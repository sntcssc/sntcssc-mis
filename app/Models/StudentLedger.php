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
 * @property string $ledger_no
 * @property int $user_id
 * @property string|null $student_id
 * @property string $entry_type
 * @property float $amount
 * @property float $balance_after
 * @property string $reference_type
 * @property int|null $reference_id
 * @property string $description
 * @property Carbon $transaction_date
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
class StudentLedger extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const ENTRY_DEBIT = 'debit';   // Increases student's payable liability (e.g., fee invoice issued)

    public const ENTRY_CREDIT = 'credit'; // Decreases student's payable liability (e.g., payment received, waiver)

    public const REF_INVOICE = 'invoice';

    public const REF_PAYMENT = 'payment';

    public const REF_REFUND = 'refund';

    public const REF_DEPOSIT = 'deposit';

    public const REF_WAIVER = 'waiver';

    public const REF_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'ledger_no',
        'user_id',
        'student_id',
        'entry_type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'transaction_date',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public static function generateLedgerNo(): string
    {
        $year = date('Y');
        $prefix = "LDG-{$year}-";

        $latest = static::withTrashed()
            ->where('ledger_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(ledger_no) DESC, ledger_no DESC')
            ->value('ledger_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        $candidate = sprintf('%s%06d', $prefix, $nextSeq);

        while (static::withTrashed()->where('ledger_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('%s%06d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    /**
     * Record a debit entry (adds liability/obligation to student).
     */
    public static function recordDebit(
        int $userId,
        float $amount,
        string $referenceType,
        ?int $referenceId,
        string $description,
        ?string $studentId = null,
        ?int $createdBy = null
    ): self {
        $lastBalance = static::where('user_id', $userId)->latest('id')->value('balance_after') ?? 0.00;
        $newBalance = (float) $lastBalance + $amount;

        return static::create([
            'ledger_no' => static::generateLedgerNo(),
            'user_id' => $userId,
            'student_id' => $studentId,
            'entry_type' => self::ENTRY_DEBIT,
            'amount' => $amount,
            'balance_after' => $newBalance,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'transaction_date' => now()->toDateString(),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Record a credit entry (reduces liability/payment credited).
     */
    public static function recordCredit(
        int $userId,
        float $amount,
        string $referenceType,
        ?int $referenceId,
        string $description,
        ?string $studentId = null,
        ?int $createdBy = null
    ): self {
        $lastBalance = static::where('user_id', $userId)->latest('id')->value('balance_after') ?? 0.00;
        $newBalance = (float) $lastBalance - $amount;

        return static::create([
            'ledger_no' => static::generateLedgerNo(),
            'user_id' => $userId,
            'student_id' => $studentId,
            'entry_type' => self::ENTRY_CREDIT,
            'amount' => $amount,
            'balance_after' => $newBalance,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'transaction_date' => now()->toDateString(),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Get current student balance liability (positive = dues owed, 0 = settled).
     */
    public static function getCurrentBalance(int $userId): float
    {
        return (float) (static::where('user_id', $userId)->latest('id')->value('balance_after') ?? 0.00);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StudentFeeInvoice::class, 'reference_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'reference_id');
    }

    public function resolveFeeType(): ?FeeType
    {
        if ($this->reference_type === self::REF_INVOICE && $this->reference_id) {
            return $this->invoice?->feeType;
        }

        if ($this->reference_type === self::REF_PAYMENT && $this->reference_id) {
            return $this->paymentTransaction?->feeType ?: $this->paymentTransaction?->invoice?->feeType;
        }

        if (in_array($this->reference_type, [self::REF_DEPOSIT, self::REF_REFUND])) {
            return FeeType::where('code', 'library_security')->first();
        }

        return null;
    }
}
