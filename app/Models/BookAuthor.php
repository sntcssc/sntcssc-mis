<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $biography
 * @property string|null $country
 * @property string|null $email
 * @property string|null $website
 * @property string $status
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class BookAuthor extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'biography',
        'country',
        'nationality',
        'email',
        'website',
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
        static::creating(function (BookAuthor $author) {
            if (empty($author->slug)) {
                $author->slug = Str::slug($author->name);
            }
            if (auth()->check() && empty($author->created_by)) {
                $author->created_by = auth()->id();
            }
        });

        static::updating(function (BookAuthor $author) {
            if (auth()->check()) {
                $author->updated_by = auth()->id();
            }
        });

        static::saving(function (BookAuthor $author) {
            // Sync nationality and country
            if (! empty($author->nationality) && empty($author->country)) {
                $author->country = $author->nationality;
            } elseif (! empty($author->country) && empty($author->nationality)) {
                $author->nationality = $author->country;
            } elseif (! empty($author->nationality) && ! empty($author->country) && $author->isDirty('nationality')) {
                $author->country = $author->nationality;
            } elseif (! empty($author->nationality) && ! empty($author->country) && $author->isDirty('country')) {
                $author->nationality = $author->country;
            }

            if (isset($author->attributes['is_active'])) {
                $author->status = $author->is_active ? 'active' : 'inactive';
            } elseif (isset($author->attributes['status'])) {
                $author->is_active = ($author->status === 'active');
            }
        });
    }

    public function getNationalityAttribute(): ?string
    {
        return $this->attributes['nationality'] ?? $this->attributes['country'] ?? null;
    }

    public function setNationalityAttribute(?string $value): void
    {
        $this->attributes['nationality'] = $value;
        if (empty($this->attributes['country'])) {
            $this->attributes['country'] = $value;
        }
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_author_pivot', 'author_id', 'book_id')
            ->withPivot('is_primary');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
