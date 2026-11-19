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
 * @property string $deposit_no
 * @property int $user_id
 * @property string|null $student_id
 * @property float $amount
 * @property int|null $payment_transaction_id
 * @property Carbon $deposit_date
 * @property string $status
 * @property Carbon|null $refund_requested_at
 * @property int|null $refund_requested_by
 * @property string|null $refund_reason
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $ifsc_code
 * @property string|null $account_holder_name
 * @property string|null $upi_id
 * @property int|null $refund_reviewed_by
 * @property Carbon|null $refund_reviewed_at
 * @property float|null $refund_approved_amount
 * @property int|null $refund_processed_by
 * @property Carbon|null $refund_processed_at
 * @property string|null $refund_transaction_ref
 * @property string|null $refund_receipt_path
 * @property string|null $admin_notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read PaymentTransaction|null $paymentTransaction
 * @property-read User|null $requester
 * @property-read User|null $reviewer
 * @property-read User|null $processor
 */
class LibraryDeposit extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REFUND_REQUESTED = 'refund_requested';

    public const STATUS_REFUND_APPROVED = 'refund_approved';

    public const STATUS_REFUND_PROCESSED = 'refund_processed';

    public const STATUS_REFUND_COMPLETED = self::STATUS_REFUND_PROCESSED;

    public const STATUS_REFUND_REJECTED = 'refund_rejected';

    public const STATUS_FORFEITED = 'forfeited';

    public const CLEARANCE_NOT_REQUIRED = 'not_required';

    public const CLEARANCE_PENDING = 'pending';

    public const CLEARANCE_CLEARED = 'cleared';

    public const CLEARANCE_REJECTED = 'rejected';

    public const STANDARD_TIERS = [500, 1000, 1500, 2000, 2500, 3000];

    protected $fillable = [
        'deposit_no',
        'user_id',
        'student_id',
        'amount',
        'payment_transaction_id',
        'deposit_date',
        'status',
        'clearance_status',
        'clearance_certificate_no',
        'cleared_at',
        'cleared_by_id',
        'clearance_remarks',
        'refund_requested_at',
        'refund_requested_by',
        'refund_reason',
        'bank_name',
        'account_number',
        'ifsc_code',
        'account_holder_name',
        'upi_id',
        'refund_reviewed_by',
        'refund_reviewed_at',
        'refund_approved_amount',
        'refund_processed_by',
        'refund_processed_at',
        'refund_transaction_ref',
        'refund_receipt_path',
        'admin_notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposit_date' => 'date',
            'cleared_at' => 'datetime',
            'refund_requested_at' => 'datetime',
            'refund_reviewed_at' => 'datetime',
            'refund_approved_amount' => 'decimal:2',
            'refund_processed_at' => 'datetime',
        ];
    }

    public static function generateDepositNo(): string
    {
        $year = date('Y');
        $prefix = "LIB-DEP-{$year}-";

        $latest = static::withTrashed()
            ->where('deposit_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(deposit_no) DESC, deposit_no DESC')
            ->value('deposit_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        $candidate = sprintf('%s%05d', $prefix, $nextSeq);

        while (static::withTrashed()->where('deposit_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('%s%05d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    public static function generateClearanceCertificateNo(): string
    {
        $year = date('Y');
        $prefix = "LIB-CLR-{$year}-";

        $latest = static::withTrashed()
            ->where('clearance_certificate_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(clearance_certificate_no) DESC, clearance_certificate_no DESC')
            ->value('clearance_certificate_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        $candidate = sprintf('%s%05d', $prefix, $nextSeq);

        while (static::withTrashed()->where('clearance_certificate_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('%s%05d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_reviewed_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_processed_by');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_id');
    }

    public function canRequestRefund(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRefundInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_REFUND_REQUESTED, self::STATUS_REFUND_APPROVED], true);
    }

    public function isRefundCompleted(): bool
    {
        return $this->status === self::STATUS_REFUND_PROCESSED;
    }

    public function isClearancePending(): bool
    {
        return $this->clearance_status === self::CLEARANCE_PENDING;
    }

    public function isClearanceApproved(): bool
    {
        return $this->clearance_status === self::CLEARANCE_CLEARED;
    }
}
