<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $short_name
 * @property string|null $description
 * @property string $icon
 * @property string $badge_color
 * @property bool $is_system
 * @property bool $is_active
 * @property int $display_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read Collection<int, Alumni> $alumni
 */
class AlumniServiceCategory extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'alumni_service_categories';

    protected $fillable = [
        'name',
        'code',
        'short_name',
        'description',
        'icon',
        'badge_color',
        'is_system',
        'is_active',
        'display_order',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AlumniServiceCategory $category) {
            if (empty($category->code)) {
                $category->code = Str::slug($category->name, '_');
            }
            if (auth()->check() && empty($category->created_by)) {
                $category->created_by = auth()->id();
            }
        });

        static::updating(function (AlumniServiceCategory $category) {
            if (auth()->check()) {
                $category->updated_by = auth()->id();
            }
        });

        static::deleting(function (AlumniServiceCategory $category) {
            if (auth()->check() && ! $category->isForceDeleting() && empty($category->deleted_by)) {
                $category->deleted_by = auth()->id();
                $category->saveQuietly();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function alumni(): HasMany
    {
        return $this->hasMany(Alumni::class, 'service_category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('short_name', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    /**
     * Get associative array of active categories [code => name].
     *
     * @return array<string, string>
     */
    public static function activeOptions(): array
    {
        try {
            return static::active()->ordered()->pluck('name', 'code')->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get collection of all active categories.
     *
     * @return Collection<int, AlumniServiceCategory>
     */
    public static function activeList(): Collection
    {
        try {
            return static::active()->ordered()->get();
        } catch (\Throwable) {
            return new Collection;
        }
    }
}
