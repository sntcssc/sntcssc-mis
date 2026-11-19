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
 * @property string $fine_no
 * @property int $book_issue_id
 * @property int $library_member_id
 * @property int|null $user_id
 * @property int $overdue_days
 * @property float $fine_rate_per_day
 * @property float $calculated_fine
 * @property float $waived_amount
 * @property float $payable_amount
 * @property float $paid_amount
 * @property string $status
 * @property int|null $payment_transaction_id
 * @property Carbon|null $paid_at
 * @property string|null $payment_method
 * @property int|null $waived_by
 * @property Carbon|null $waived_at
 * @property string|null $waiver_reason
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class BookFine extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'fine_no',
        'fine_number',
        'fine_type',
        'book_issue_id',
        'library_member_id',
        'user_id',
        'overdue_days',
        'fine_rate_per_day',
        'calculated_fine',
        'amount',
        'waived_amount',
        'payable_amount',
        'paid_amount',
        'status',
        'payment_transaction_id',
        'paid_at',
        'payment_method',
        'payment_reference',
        'collected_by_id',
        'waived_by',
        'waived_by_id',
        'waived_at',
        'waiver_reason',
        'notes',
        'created_by',
        'created_by_id',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'overdue_days' => 'integer',
            'fine_rate_per_day' => 'decimal:2',
            'calculated_fine' => 'decimal:2',
            'amount' => 'decimal:2',
            'waived_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BookFine $fine) {
            if (empty($fine->fine_no) && ! empty($fine->fine_number)) {
                $fine->fine_no = $fine->fine_number;
            } elseif (empty($fine->fine_number) && ! empty($fine->fine_no)) {
                $fine->fine_number = $fine->fine_no;
            } elseif (empty($fine->fine_no) && empty($fine->fine_number)) {
                $fineNumber = 'FN-'.now()->format('Y').'-'.strtoupper(bin2hex(random_bytes(3)));
                $fine->fine_no = $fineNumber;
                $fine->fine_number = $fineNumber;
            }

            if ($fine->amount !== null && $fine->calculated_fine === null) {
                $fine->calculated_fine = $fine->amount;
            } elseif ($fine->calculated_fine !== null && $fine->amount === null) {
                $fine->amount = $fine->calculated_fine;
            }

            if ($fine->payable_amount <= 0) {
                $fine->payable_amount = max(0.00, ($fine->amount ?? $fine->calculated_fine ?? 0.00) - $fine->waived_amount);
            }

            if (auth()->check()) {
                if (empty($fine->created_by)) {
                    $fine->created_by = auth()->id();
                }
                if (empty($fine->created_by_id)) {
                    $fine->created_by_id = auth()->id();
                }
            }
        });

        static::saving(function (BookFine $fine) {
            if (! empty($fine->fine_no) && empty($fine->fine_number)) {
                $fine->fine_number = $fine->fine_no;
            } elseif (! empty($fine->fine_number) && empty($fine->fine_no)) {
                $fine->fine_no = $fine->fine_number;
            }

            if ($fine->amount !== null && $fine->calculated_fine === null) {
                $fine->calculated_fine = $fine->amount;
            } elseif ($fine->calculated_fine !== null && $fine->amount === null) {
                $fine->amount = $fine->calculated_fine;
            }

            if (! empty($fine->waived_by) && empty($fine->waived_by_id)) {
                $fine->waived_by_id = $fine->waived_by;
            } elseif (! empty($fine->waived_by_id) && empty($fine->waived_by)) {
                $fine->waived_by = $fine->waived_by_id;
            }
        });
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(BookIssue::class, 'book_issue_id');
    }

    public function bookIssue(): BelongsTo
    {
        return $this->belongsTo(BookIssue::class, 'book_issue_id');
    }

    public function libraryMember(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'library_member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isWaived(): bool
    {
        return $this->status === self::STATUS_WAIVED;
    }

    public function getAmountAttribute($value): float
    {
        return (float) ($value ?? $this->attributes['calculated_fine'] ?? 0.00);
    }

    public function getFineNumberAttribute($value): string
    {
        return (string) ($value ?? $this->attributes['fine_no'] ?? '');
    }
}
