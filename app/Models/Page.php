<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;

class Page extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'slug',
        'status',
        'sort_order',
        'is_system',
        'view_count',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_system' => 'boolean',
            'view_count' => 'integer',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function translations(): HasMany
    {
        return $this->hasMany(PageTranslation::class, 'page_id');
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

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /* ----------------------------------------------------------------- *
     *  Translation Helpers
     * ----------------------------------------------------------------- */

    /**
     * Get the translation record for a specific locale (with fallback to default).
     */
    public function translation(?string $locale = null): ?PageTranslation
    {
        $targetLocale = $locale ?: App::getLocale();
        $defaultLocale = config('app.fallback_locale', 'en');

        // Check loaded relation first if available
        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $targetLocale)
                ?? $this->translations->firstWhere('locale', $defaultLocale)
                ?? $this->translations->first();
        }

        return $this->translations()->where('locale', $targetLocale)->first()
            ?? $this->translations()->where('locale', $defaultLocale)->first()
            ?? $this->translations()->first();
    }

    /**
     * Check if a translation exists for a specific locale.
     */
    public function hasTranslation(string $locale): bool
    {
        if ($this->relationLoaded('translations')) {
            return $this->translations->contains('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->exists();
    }

    /**
     * Get a translated attribute with automatic locale fallback.
     */
    public function getTranslation(string $attribute, ?string $locale = null, mixed $default = null): mixed
    {
        $translation = $this->translation($locale);

        return $translation ? ($translation->{$attribute} ?? $default) : $default;
    }

    public function getTitleAttribute(): string
    {
        return (string) $this->getTranslation('title', null, $this->slug);
    }

    public function getContentAttribute(): string
    {
        return (string) $this->getTranslation('content', null, '');
    }

    public function getMetaTitleAttribute(): ?string
    {
        return $this->getTranslation('meta_title', null, $this->title);
    }

    public function getMetaDescriptionAttribute(): ?string
    {
        return $this->getTranslation('meta_description', null, null);
    }

    public function getMetaKeywordsAttribute(): ?string
    {
        return $this->getTranslation('meta_keywords', null, null);
    }

    public function getReadingTimeAttribute(): int
    {
        $words = str_word_count(strip_tags($this->content));

        return max(1, (int) ceil($words / 200));
    }
}
