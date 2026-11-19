<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $transaction_no
 * @property int|null $invoice_id
 * @property int $user_id
 * @property string|null $student_id
 * @property int|null $fee_type_id
 * @property float $amount
 * @property string $currency
 * @property string $gateway
 * @property string $payment_method
 * @property string $status
 * @property bool $is_offline
 * @property string|null $gateway_order_id
 * @property string|null $gateway_payment_id
 * @property string|null $gateway_signature
 * @property array|null $gateway_response
 * @property string|null $offline_reference_id
 * @property Carbon|null $offline_payment_date
 * @property string|null $offline_payment_time
 * @property string|null $user_remarks
 * @property string|null $proof_document_path
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $admin_remarks
 * @property string|null $receipt_no
 * @property Carbon|null $receipt_generated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read StudentFeeInvoice|null $invoice
 * @property-read FeeType|null $feeType
 * @property-read User|null $verifier
 * @property-read LibraryDeposit|null $libraryDeposit
 */
class PaymentTransaction extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const GATEWAY_RAZORPAY = 'razorpay';

    public const GATEWAY_PHONEPE = 'phonepe';

    public const GATEWAY_CCAVENUE = 'ccavenue';

    public const GATEWAY_OFFLINE = 'offline';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REFUNDED = 'refunded';

    public const METHOD_UPI = 'upi';

    public const METHOD_STATIC_QR = 'static_qr';

    public const METHOD_CARD = 'card';

    public const METHOD_NETBANKING = 'netbanking';

    public const METHOD_NEFT = 'neft';

    public const METHOD_IMPS = 'imps';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CASH = 'cash';

    public const METHOD_CHEQUE = 'cheque';

    public const METHOD_DD = 'dd';

    protected $fillable = [
        'transaction_no',
        'invoice_id',
        'user_id',
        'student_id',
        'fee_type_id',
        'amount',
        'gateway_charged_amount',
        'gateway_fee',
        'gateway_tax',
        'currency',
        'gateway',
        'payment_method',
        'status',
        'is_offline',
        'gateway_order_id',
        'gateway_payment_id',
        'gateway_signature',
        'gateway_bank_ref_no',
        'gateway_tracking_id',
        'gateway_payment_mode',
        'gateway_card_name',
        'gateway_response',
        'amount_mismatch',
        'amount_mismatch_notes',
        'refund_amount',
        'refund_reason',
        'refund_reference_no',
        'refund_status',
        'refunded_at',
        'refunded_by',
        'billing_country',
        'billing_tel',
        'billing_email',
        'offline_reference_id',
        'offline_payment_date',
        'offline_payment_time',
        'user_remarks',
        'proof_document_path',
        'verified_by',
        'verified_at',
        'admin_remarks',
        'receipt_no',
        'receipt_generated_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_charged_amount' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'gateway_tax' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'amount_mismatch' => 'boolean',
            'is_offline' => 'boolean',
            'gateway_response' => 'array',
            'offline_payment_date' => 'date',
            'verified_at' => 'datetime',
            'receipt_generated_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public static function generateTransactionNo(): string
    {
        $year = date('Y');
        $prefix = "TXN-{$year}-";

        $latest = static::withTrashed()
            ->where('transaction_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(transaction_no) DESC, transaction_no DESC')
            ->value('transaction_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        $candidate = sprintf('%s%06d', $prefix, $nextSeq);

        while (static::withTrashed()->where('transaction_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('%s%06d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    public static function generateReceiptNo(): string
    {
        $year = date('Y');
        $prefix = "RCT-{$year}-";

        $latest = static::withTrashed()
            ->where('receipt_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(receipt_no) DESC, receipt_no DESC')
            ->value('receipt_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        $candidate = sprintf('%s%05d', $prefix, $nextSeq);

        while (static::withTrashed()->where('receipt_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('%s%05d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StudentFeeInvoice::class, 'invoice_id');
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function libraryDeposit(): HasOne
    {
        return $this->hasOne(LibraryDeposit::class, 'payment_transaction_id');
    }

    public function isOnline(): bool
    {
        return ! $this->is_offline && in_array($this->gateway, [self::GATEWAY_RAZORPAY, self::GATEWAY_PHONEPE, self::GATEWAY_CCAVENUE], true);
    }

    public function isUnderReview(): bool
    {
        return $this->status === self::STATUS_UNDER_REVIEW;
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESSFUL;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED || (float) $this->refund_amount > 0;
    }

    public function canBeRefunded(): bool
    {
        return $this->isSuccessful() && $this->getRefundableAmount() > 0.00;
    }

    public function getRefundableAmount(): float
    {
        $base = (float) ($this->amount ?? 0.00);
        $refunded = (float) ($this->refund_amount ?? 0.00);

        return max(0.00, round($base - $refunded, 2));
    }

    public function hasAmountMismatch(): bool
    {
        return (bool) $this->amount_mismatch;
    }

    public function totalPaidByCustomer(): float
    {
        return (float) ($this->gateway_charged_amount ?: $this->amount);
    }
}
