<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string|null $subtitle
 * @property string $slug
 * @property string|null $isbn
 * @property string|null $isbn13
 * @property string|null $edition
 * @property int|null $category_id
 * @property int|null $publisher_id
 * @property string|null $classification_number
 * @property string $language
 * @property int|null $pages
 * @property int|null $published_year
 * @property string|null $description
 * @property string|null $cover_image
 * @property array<string>|null $images
 * @property float $price
 * @property bool $is_reference_only
 * @property string $status
 * @property bool $is_active
 * @property int $total_copies_count
 * @property int $available_copies_count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Book extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const MATERIAL_BOOK = 'book';

    public const MATERIAL_JOURNAL = 'journal';

    public const MATERIAL_PERIODICAL = 'periodical';

    public const MATERIAL_MAGAZINE = 'magazine';

    public const MATERIAL_REFERENCE = 'reference';

    protected $fillable = [
        'title',
        'subtitle',
        'slug',
        'isbn',
        'isbn13',
        'edition',
        'category_id',
        'publisher_id',
        'classification_number',
        'classification_no',
        'material_type',
        'language',
        'pages',
        'published_year',
        'description',
        'cover_image',
        'images',
        'price',
        'is_reference_only',
        'status',
        'is_active',
        'total_copies_count',
        'available_copies_count',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'price' => 'decimal:2',
            'is_reference_only' => 'boolean',
            'is_active' => 'boolean',
            'pages' => 'integer',
            'published_year' => 'integer',
            'total_copies_count' => 'integer',
            'available_copies_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Book $book) {
            if (empty($book->slug)) {
                $book->slug = Str::slug($book->title).'-'.Str::random(6);
            }
            if (auth()->check() && empty($book->created_by)) {
                $book->created_by = auth()->id();
            }
        });

        static::saving(function (Book $book) {
            // Keep classification_number and classification_no in sync
            if (! empty($book->classification_number) && empty($book->classification_no)) {
                $book->classification_no = $book->classification_number;
            } elseif (! empty($book->classification_no) && empty($book->classification_number)) {
                $book->classification_number = $book->classification_no;
            }
        });

        static::updating(function (Book $book) {
            if (auth()->check()) {
                $book->updated_by = auth()->id();
            }
        });
    }

    public function setBookCategoryIdAttribute($value): void
    {
        $this->attributes['category_id'] = $value;
    }

    public function getBookCategoryIdAttribute(): mixed
    {
        return $this->attributes['category_id'] ?? null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BookCategory::class, 'category_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(BookPublisher::class, 'publisher_id');
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(BookAuthor::class, 'book_author_pivot', 'book_id', 'author_id')
            ->withPivot('is_primary');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class, 'book_id');
    }

    public function availableCopies(): HasMany
    {
        return $this->hasMany(BookCopy::class, 'book_id')->where('status', 'available');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'book_id');
    }

    public function activeIssues(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'book_id')->whereIn('status', ['issued', 'overdue', 'renewed']);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BookReservation::class, 'book_id');
    }

    public function activeReservations(): HasMany
    {
        return $this->hasMany(BookReservation::class, 'book_id')->whereIn('status', ['pending', 'ready_for_pickup']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get displayable author names (comma-separated).
     */
    public function getAuthorsListAttribute(): string
    {
        return $this->authors->pluck('name')->join(', ') ?: 'Unknown Author';
    }

    /**
     * Get primary author.
     */
    public function getPrimaryAuthorAttribute(): ?BookAuthor
    {
        return $this->authors->sortByDesc('pivot.is_primary')->first();
    }

    /**
     * Refresh copy counts on book model.
     */
    public function refreshCopiesCount(): void
    {
        $this->updateQuietly([
            'total_copies_count' => $this->copies()->count(),
            'available_copies_count' => $this->copies()->where('status', 'available')->count(),
        ]);
    }

    /**
     * Alias for refreshCopiesCount.
     */
    public function updateCounters(): void
    {
        $this->refreshCopiesCount();
    }

    /**
     * Get cover image URL or placeholder.
     */
    public function coverImageUrl(): string
    {
        if ($this->cover_image) {
            if (str_starts_with($this->cover_image, 'http')) {
                return $this->cover_image;
            }
            if (Storage::disk('public')->exists($this->cover_image)) {
                return Storage::disk('public')->url($this->cover_image);
            }
            if (file_exists(public_path($this->cover_image))) {
                return asset($this->cover_image);
            }
        }

        return '';
    }

    /**
     * Scope for live searching across title, ISBN, author, classification, and publisher.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('subtitle', 'like', $like)
                ->orWhere('isbn', 'like', $like)
                ->orWhere('isbn13', 'like', $like)
                ->orWhere('classification_number', 'like', $like)
                ->orWhereHas('authors', fn ($aq) => $aq->where('name', 'like', $like))
                ->orWhereHas('category', fn ($cq) => $cq->where('name', 'like', $like))
                ->orWhereHas('publisher', fn ($pq) => $pq->where('name', 'like', $like))
                ->orWhereHas('copies', fn ($cp) => $cp->where('accession_no', 'like', $like)->orWhere('barcode', 'like', $like));
        });
    }
}
