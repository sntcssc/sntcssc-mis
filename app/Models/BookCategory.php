<?php

namespace App\Models;

use App\Concerns\Auditable;
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
 * @property string $slug
 * @property string|null $code
 * @property string|null $description
 * @property string $status
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class BookCategory extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'code',
        'ddc_classification',
        'description',
        'status',
        'is_active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BookCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
            if (auth()->check() && empty($category->created_by)) {
                $category->created_by = auth()->id();
            }
        });

        static::updating(function (BookCategory $category) {
            if (auth()->check()) {
                $category->updated_by = auth()->id();
            }
        });

        static::saving(function (BookCategory $category) {
            if (isset($category->attributes['is_active'])) {
                $category->status = $category->is_active ? 'active' : 'inactive';
            } elseif (isset($category->attributes['status'])) {
                $category->is_active = ($category->status === 'active');
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
