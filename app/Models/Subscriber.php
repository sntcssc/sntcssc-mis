<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property string|null $email
 * @property string $country_code
 * @property string|null $phone
 * @property string|null $name
 * @property string $status
 * @property string $source
 * @property array<string>|null $tags
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $subscribed_at
 * @property Carbon|null $unsubscribed_at
 * @property string|null $unsubscribe_token
 * @property string|null $unsubscribe_reason
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $creator
 * @property-read User|null $editor
 * @property-read User|null $deleter
 */
class Subscriber extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_EMAIL = 'email';

    public const TYPE_WHATSAPP = 'whatsapp';

    public const TYPE_BOTH = 'both';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_PENDING = 'pending_verification';

    public const SOURCE_WELCOME = 'welcome_page';

    public const SOURCE_FOOTER = 'footer';

    public const SOURCE_ADMIN = 'admin_manual';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_COURSE = 'course_inquiry';

    public const SOURCE_CHECKOUT = 'checkout';

    protected $fillable = [
        'uuid',
        'type',
        'email',
        'country_code',
        'phone',
        'name',
        'status',
        'source',
        'tags',
        'ip_address',
        'user_agent',
        'subscribed_at',
        'unsubscribed_at',
        'unsubscribe_token',
        'unsubscribe_reason',
        'metadata',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeEmail(Builder $query): Builder
    {
        return $query->whereIn('type', [self::TYPE_EMAIL, self::TYPE_BOTH])->whereNotNull('email');
    }

    public function scopeWhatsapp(Builder $query): Builder
    {
        return $query->whereIn('type', [self::TYPE_WHATSAPP, self::TYPE_BOTH])->whereNotNull('phone');
    }

    public function scopeUnsubscribed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNSUBSCRIBED);
    }

    public function scopeTagged(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    public function isEmail(): bool
    {
        return in_array($this->type, [self::TYPE_EMAIL, self::TYPE_BOTH], true) && ! empty($this->email);
    }

    public function isWhatsapp(): bool
    {
        return in_array($this->type, [self::TYPE_WHATSAPP, self::TYPE_BOTH], true) && ! empty($this->phone);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isUnsubscribed(): bool
    {
        return $this->status === self::STATUS_UNSUBSCRIBED;
    }

    public function formattedPhone(): ?string
    {
        if (empty($this->phone)) {
            return null;
        }

        $code = $this->country_code ?: '+91';

        return str_starts_with($this->phone, '+') ? $this->phone : "{$code} {$this->phone}";
    }

    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'emerald',
            self::STATUS_UNSUBSCRIBED => 'zinc',
            self::STATUS_BOUNCED => 'destructive',
            self::STATUS_PENDING => 'amber',
            default => 'secondary',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => __('Active'),
            self::STATUS_UNSUBSCRIBED => __('Unsubscribed'),
            self::STATUS_BOUNCED => __('Bounced / Invalid'),
            self::STATUS_PENDING => __('Pending Verification'),
            default => ucfirst($this->status),
        };
    }

    public function typeBadgeColor(): string
    {
        return match ($this->type) {
            self::TYPE_EMAIL => 'violet',
            self::TYPE_WHATSAPP => 'emerald',
            self::TYPE_BOTH => 'blue',
            default => 'secondary',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_EMAIL => __('Email Newsletter'),
            self::TYPE_WHATSAPP => __('WhatsApp Alerts'),
            self::TYPE_BOTH => __('Email & WhatsApp'),
            default => ucfirst($this->type),
        };
    }

    public function unsubscribeUrl(): string
    {
        return url('/unsubscribe/'.$this->unsubscribe_token);
    }
}
