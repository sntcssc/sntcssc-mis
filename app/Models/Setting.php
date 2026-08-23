<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    /** Value types supported by the (future) settings admin UI. */
    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_NUMBER = 'number';

    public const TYPE_SELECT = 'select';

    public const TYPE_IMAGE = 'image';

    public const TYPE_FILE = 'file';

    public const TYPE_SECRET = 'secret';

    public const TYPE_JSON = 'json';

    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'label',
        'options',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
        static::restored(fn () => static::flushCache());
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'status' => 'boolean',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /* ----------------------------------------------------------------- *
     *  Attribute handling
     * ----------------------------------------------------------------- */

    protected function setValueAttribute($value): void
    {
        $this->attributes['value'] = $this->shouldEncrypt((string) ($value ?? ''))
            ? Crypt::encryptString($value)
            : $value;
    }

    /**
     * Raw stored value, decrypting secrets. Secrets whose ciphertext cannot
     * be decrypted (e.g. APP_KEY changed) return null instead of leaking the
     * ciphertext into the UI.
     */
    public function rawValue(): ?string
    {
        $value = $this->attributes['value'] ?? null;

        if ($value !== null && $this->type === self::TYPE_SECRET) {
            try {
                return Crypt::decryptString($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $value;
    }

    /**
     * The setting value cast according to its type.
     */
    public function typed(): mixed
    {
        $value = $this->rawValue();

        if ($value === null) {
            return match ($this->type) {
                self::TYPE_BOOLEAN => false,
                self::TYPE_NUMBER => 0,
                self::TYPE_JSON => null,
                default => null,
            };
        }

        return match ($this->type) {
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_NUMBER => str_contains($value, '.') ? (float) $value : (int) $value,
            self::TYPE_JSON => json_decode($value, true),
            default => (string) $value,
        };
    }

    protected function shouldEncrypt(string $value): bool
    {
        if ($this->type !== self::TYPE_SECRET || $value === '') {
            return false;
        }

        // Never double-encrypt an already-encrypted value.
        try {
            Crypt::decryptString($value);

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /* ----------------------------------------------------------------- *
     *  Static API
     * ----------------------------------------------------------------- */

    /**
     * Look up a setting by key and return its type-cast value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $setting = static::allCached()->firstWhere('key', $key);

            if (! $setting || ! $setting->status) {
                return $default;
            }

            return $setting->typed() ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Persist a setting value (creates the row when the key is new), caching
     * nothing — the saved event flushes the cache automatically.
     */
    public static function set(string $key, mixed $value, ?int $userId = null): self
    {
        $setting = static::withTrashed()->firstWhere('key', $key) ?? new static(['key' => $key]);

        if ($setting->trashed()) {
            $setting->restore();
        }

        if ($userId && ! $setting->exists) {
            $setting->created_by = $userId;
        }

        $setting->type ??= self::TYPE_STRING;
        $setting->status ??= true;

        $setting->value = is_array($value) ? json_encode($value) : (string) $value;
        $setting->updated_by = $userId;
        $setting->save();

        return $setting->refresh();
    }

    /**
     * All active settings, cached until a write flushes it.
     *
     * @return Collection<int, static>
     */
    public static function allCached(): Collection
    {
        try {
            $cached = Cache::get('snt.settings');

            if ($cached instanceof Collection) {
                return $cached;
            }
        } catch (\Throwable) {
            // Cache corrupted or unserialize failure
        }

        Cache::forget('snt.settings');

        try {
            $settings = static::query()->where('status', true)->get();
            Cache::put('snt.settings', $settings, now()->addDay());

            return $settings;
        } catch (\Throwable) {
            return collect();
        }
    }

    public static function flushCache(): void
    {
        Cache::forget('snt.settings');
    }

    /**
     * Resolve the public URL of the active site/dashboard logo.
     * Looks up general.site_logo first, then appearance.logo.
     * Returns null if no custom logo is uploaded.
     */
    public static function logoUrl(): ?string
    {
        $logo = static::get('general.site_logo') ?: static::get('appearance.logo');

        if (! $logo) {
            return null;
        }

        return FileUploadService::url((string) $logo);
    }

    /**
     * Resolve the public URL of the active site favicon / dashboard icon.
     * Looks up general.site_favicon first, then appearance.icon.
     * Falls back to '/favicon.ico' if $fallback is true, or null otherwise.
     */
    public static function faviconUrl(bool $fallback = true): ?string
    {
        $favicon = static::get('general.site_favicon') ?: static::get('appearance.icon');

        if ($favicon) {
            return FileUploadService::url((string) $favicon);
        }

        return $fallback ? '/favicon.ico' : null;
    }

    /**
     * Resolve the application name.
     */
    public static function appName(): string
    {
        return (string) static::get('general.app_name', static::get('general.site_name', config('app.name', 'SNT CSSC MIS')));
    }

    /**
     * Resolve the site name.
     */
    public static function siteName(): string
    {
        return (string) static::get('general.site_name', config('app.name', 'SNT CSSC MIS'));
    }

    /**
     * Resolve the formatted copyright string with dynamic year.
     */
    public static function copyrightText(): string
    {
        $template = (string) static::get('general.copyright_text', '© :year SNT CSSC. All rights reserved.');

        return str_replace(':year', (string) date('Y'), $template);
    }
}
