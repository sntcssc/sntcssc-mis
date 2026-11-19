<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $book_id
 * @property string $accession_no
 * @property string $barcode
 * @property string|null $rfid_tag
 * @property int $copy_number
 * @property Carbon $acquisition_date
 * @property float $purchase_cost
 * @property string|null $supplier_details
 * @property string|null $invoice_number
 * @property string|null $funding_source
 * @property string|null $classification_number
 * @property string|null $shelf_location
 * @property string $book_condition
 * @property string $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class BookCopy extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_IN_HOUSE_READING = 'in_house_reading';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_MAINTENANCE = 'under_maintenance';

    public const STATUS_LOST = 'lost';

    public const STATUS_WRITTEN_OFF = 'written_off';

    public const CONDITION_NEW = 'new';

    public const CONDITION_GOOD = 'good';

    public const CONDITION_FAIR = 'fair';

    public const CONDITION_DAMAGED = 'damaged';

    public const CONDITION_WORN = 'worn';

    public const CONDITION_LOST = 'lost';

    protected $fillable = [
        'book_id',
        'accession_no',
        'barcode',
        'rfid_tag',
        'copy_number',
        'acquisition_date',
        'purchase_cost',
        'price',
        'supplier_details',
        'invoice_number',
        'funding_source',
        'classification_number',
        'classification_no',
        'shelf_location',
        'book_condition',
        'condition',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'price' => 'decimal:2',
            'copy_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BookCopy $copy) {
            if (empty($copy->barcode)) {
                $copy->barcode = $copy->accession_no;
            }
            if (auth()->check() && empty($copy->created_by)) {
                $copy->created_by = auth()->id();
            }
        });

        static::saving(function (BookCopy $copy) {
            if (! empty($copy->classification_number) && empty($copy->classification_no)) {
                $copy->classification_no = $copy->classification_number;
            } elseif (! empty($copy->classification_no) && empty($copy->classification_number)) {
                $copy->classification_number = $copy->classification_no;
            }

            // Sync condition and book_condition
            if (! empty($copy->condition) && empty($copy->book_condition)) {
                $copy->book_condition = $copy->condition;
            } elseif (! empty($copy->book_condition) && empty($copy->condition)) {
                $copy->condition = $copy->book_condition;
            } elseif (! empty($copy->condition) && ! empty($copy->book_condition) && $copy->isDirty('condition')) {
                $copy->book_condition = $copy->condition;
            } elseif (! empty($copy->condition) && ! empty($copy->book_condition) && $copy->isDirty('book_condition')) {
                $copy->condition = $copy->book_condition;
            }

            // Sync price and purchase_cost
            if ($copy->price !== null && $copy->purchase_cost === null) {
                $copy->purchase_cost = $copy->price;
            } elseif ($copy->purchase_cost !== null && $copy->price === null) {
                $copy->price = $copy->purchase_cost;
            }
        });

        static::created(function (BookCopy $copy) {
            $copy->book?->refreshCopiesCount();
            BookAccessionHistory::create([
                'book_copy_id' => $copy->id,
                'action' => 'acquired',
                'new_condition' => $copy->book_condition,
                'new_status' => $copy->status,
                'new_shelf_location' => $copy->shelf_location,
                'notes' => 'Initial accessioning into collection.',
                'created_by' => auth()->id(),
            ]);
        });

        static::updated(function (BookCopy $copy) {
            $copy->book?->refreshCopiesCount();
        });

        static::deleted(function (BookCopy $copy) {
            $copy->book?->refreshCopiesCount();
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'book_copy_id');
    }

    public function currentIssue(): HasOne
    {
        return $this->hasOne(BookIssue::class, 'book_copy_id')
            ->whereIn('status', ['issued', 'overdue', 'renewed'])
            ->latestOfMany();
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BookAccessionHistory::class, 'book_copy_id')->latest();
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function markCondition(string $condition, ?string $notes = null): void
    {
        $old = $this->book_condition;
        $this->update([
            'book_condition' => $condition,
            'condition' => $condition,
        ]);

        BookAccessionHistory::create([
            'book_copy_id' => $this->id,
            'action' => 'condition_changed',
            'old_condition' => $old,
            'new_condition' => $condition,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }

    public function getConditionAttribute(): string
    {
        return $this->attributes['condition'] ?? $this->attributes['book_condition'] ?? self::CONDITION_GOOD;
    }

    public function setConditionAttribute(?string $value): void
    {
        $this->attributes['condition'] = $value;
        $this->attributes['book_condition'] = $value;
    }

    public function markRelocated(string $newLocation, ?string $notes = null): void
    {
        $old = $this->shelf_location;
        $this->update(['shelf_location' => $newLocation]);

        BookAccessionHistory::create([
            'book_copy_id' => $this->id,
            'action' => 'relocated',
            'old_shelf_location' => $old,
            'new_shelf_location' => $newLocation,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }

    public function markStatus(string $status, ?string $notes = null): void
    {
        $old = $this->status;
        $this->update(['status' => $status]);

        BookAccessionHistory::create([
            'book_copy_id' => $this->id,
            'action' => 'status_changed',
            'old_status' => $old,
            'new_status' => $status,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }
}
