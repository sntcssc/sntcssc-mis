<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $invoice_no
 * @property int $user_id
 * @property string|null $student_id
 * @property int|null $enrollment_id
 * @property int|null $fee_schedule_id
 * @property int $fee_type_id
 * @property string $title
 * @property string|null $description
 * @property int|null $period_month
 * @property int|null $period_year
 * @property float $amount
 * @property float $discount_amount
 * @property float $late_fee_amount
 * @property float $total_amount
 * @property float $paid_amount
 * @property float $balance_amount
 * @property Carbon $due_date
 * @property string $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $reminder_sent_at
 * @property int $reminder_count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Enrollment|null $enrollment
 * @property-read FeeSchedule|null $feeSchedule
 * @property-read FeeType $feeType
 * @property-read Collection<int, PaymentTransaction> $transactions
 */
class StudentFeeInvoice extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_WAIVED = 'waived';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'invoice_no',
        'user_id',
        'student_id',
        'enrollment_id',
        'fee_schedule_id',
        'fee_type_id',
        'title',
        'description',
        'period_month',
        'period_year',
        'amount',
        'discount_amount',
        'late_fee_amount',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'due_date',
        'status',
        'paid_at',
        'reminder_sent_at',
        'reminder_count',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'integer',
            'period_year' => 'integer',
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'late_fee_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    public static function generateInvoiceNo(): string
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";

        $latest = static::withTrashed()
            ->where('invoice_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(invoice_no) DESC, invoice_no DESC')
            ->value('invoice_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        $candidate = sprintf('%s%05d', $prefix, $nextSeq);

        while (static::withTrashed()->where('invoice_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('%s%05d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function feeSchedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'invoice_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PARTIAL, self::STATUS_OVERDUE], true);
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_date->isPast();
    }

    public function getEffectiveStatusAttribute(): string
    {
        if ($this->isPaid() || $this->status === self::STATUS_WAIVED || $this->status === self::STATUS_CANCELLED) {
            return $this->status;
        }

        if ($this->due_date->isPast()) {
            return self::STATUS_OVERDUE;
        }

        return $this->status;
    }

    public function getMonthNameAttribute(): ?string
    {
        if (! $this->period_month) {
            return null;
        }

        return Carbon::create(null, (int) $this->period_month, 1)->format('F');
    }

    public function canBeCancelled(): bool
    {
        return ! in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true);
    }

    public function canBeDeleted(): bool
    {
        return (float) $this->paid_amount <= 0.00
            && ! $this->transactions()->where('status', PaymentTransaction::STATUS_SUCCESSFUL)->exists();
    }
}
