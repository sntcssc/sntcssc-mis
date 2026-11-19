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
 * @property string|null $contact_person
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
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
class BookPublisher extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'contact_person',
        'email',
        'phone',
        'address',
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
        static::creating(function (BookPublisher $publisher) {
            if (empty($publisher->slug)) {
                $publisher->slug = Str::slug($publisher->name);
            }
            if (auth()->check() && empty($publisher->created_by)) {
                $publisher->created_by = auth()->id();
            }
        });

        static::updating(function (BookPublisher $publisher) {
            if (auth()->check()) {
                $publisher->updated_by = auth()->id();
            }
        });

        static::saving(function (BookPublisher $publisher) {
            if (isset($publisher->attributes['is_active'])) {
                $publisher->status = $publisher->is_active ? 'active' : 'inactive';
            } elseif (isset($publisher->attributes['status'])) {
                $publisher->is_active = ($publisher->status === 'active');
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'publisher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
