<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Language extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'flag',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /* ----------------------------------------------------------------- *
     *  Static Cache Helpers
     * ----------------------------------------------------------------- */

    /**
     * @return Collection<int, static>
     */
    public static function allCached(): Collection
    {
        try {
            $cached = Cache::get('snt.languages.all');

            if ($cached instanceof Collection) {
                return $cached;
            }
        } catch (\Throwable) {
            // Cache corrupted or unserialize failure
        }

        Cache::forget('snt.languages.all');

        try {
            $languages = static::query()->ordered()->get();
            Cache::put('snt.languages.all', $languages, now()->addDay());

            return $languages;
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int, static>
     */
    public static function activeCached(): Collection
    {
        try {
            $cached = Cache::get('snt.languages.active');

            if ($cached instanceof Collection) {
                return $cached;
            }
        } catch (\Throwable) {
            // Cache corrupted or unserialize failure
        }

        Cache::forget('snt.languages.active');

        try {
            $languages = static::query()->active()->ordered()->get();
            Cache::put('snt.languages.active', $languages, now()->addDay());

            return $languages;
        } catch (\Throwable) {
            return collect();
        }
    }

    public static function defaultLanguage(): ?self
    {
        return static::activeCached()->firstWhere('is_default', true)
            ?? static::activeCached()->first();
    }

    public static function flushCache(): void
    {
        Cache::forget('snt.languages.all');
        Cache::forget('snt.languages.active');
    }
}
