<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reservation_no
 * @property int $book_id
 * @property int|null $book_copy_id
 * @property int $library_member_id
 * @property int|null $user_id
 * @property Carbon $reserved_at
 * @property Carbon $expires_at
 * @property string $status
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class BookReservation extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready_for_pickup';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'reservation_no',
        'reservation_number',
        'book_id',
        'book_copy_id',
        'library_member_id',
        'user_id',
        'reserved_at',
        'expires_at',
        'status',
        'fulfilled_at',
        'cancelled_at',
        'cancellation_reason',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BookReservation $reservation) {
            if (empty($reservation->reservation_no) && ! empty($reservation->reservation_number)) {
                $reservation->reservation_no = $reservation->reservation_number;
            } elseif (empty($reservation->reservation_number) && ! empty($reservation->reservation_no)) {
                $reservation->reservation_number = $reservation->reservation_no;
            } elseif (empty($reservation->reservation_no) && empty($reservation->reservation_number)) {
                $resNo = 'RES-'.now()->format('Y').'-'.strtoupper(bin2hex(random_bytes(3)));
                $reservation->reservation_no = $resNo;
                $reservation->reservation_number = $resNo;
            }

            if (empty($reservation->reserved_at)) {
                $reservation->reserved_at = now();
            }
            if (empty($reservation->expires_at)) {
                $days = (int) Setting::get('library.reservation_expiry_days', 3);
                $reservation->expires_at = now()->addDays($days);
            }
            if (auth()->check() && empty($reservation->created_by)) {
                $reservation->created_by = auth()->id();
            }
        });

        static::saving(function (BookReservation $reservation) {
            if (! empty($reservation->reservation_no) && empty($reservation->reservation_number)) {
                $reservation->reservation_number = $reservation->reservation_no;
            } elseif (! empty($reservation->reservation_number) && empty($reservation->reservation_no)) {
                $reservation->reservation_no = $reservation->reservation_number;
            }
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function bookCopy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function libraryMember(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'library_member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return now()->gt($this->expires_at) && $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_READY], true);
    }

    public function getReservationNumberAttribute($value = null): string
    {
        return (string) ($value ?? $this->attributes['reservation_no'] ?? '');
    }
}
