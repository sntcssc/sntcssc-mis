<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $description
 * @property string $icon
 * @property string $badge_color
 * @property bool $is_public
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
 */
class StudyMaterialType extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'icon',
        'badge_color',
        'is_public',
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
            'is_public' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StudyMaterialType $type) {
            if (empty($type->code)) {
                $type->code = Str::slug($type->name, '_');
            }
            if (auth()->check() && empty($type->created_by)) {
                $type->created_by = auth()->id();
            }
        });

        static::updating(function (StudyMaterialType $type) {
            if (auth()->check()) {
                $type->updated_by = auth()->id();
            }
        });

        static::deleting(function (StudyMaterialType $type) {
            if (auth()->check() && ! $type->isForceDeleting() && empty($type->deleted_by)) {
                $type->deleted_by = auth()->id();
                $type->saveQuietly();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function studyMaterials(): HasMany
    {
        return $this->hasMany(StudyMaterial::class, 'material_type', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true);
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
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    /**
     * Get associative array of active material types [code => name].
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
     * Get associative array of public material types [code => name].
     *
     * @return array<string, string>
     */
    public static function publicOptions(): array
    {
        try {
            return static::public()->ordered()->pluck('name', 'code')->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
