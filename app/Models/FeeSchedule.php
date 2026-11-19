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
 * @property string $schedule_no
 * @property int $fee_type_id
 * @property int $batch_id
 * @property int $course_id
 * @property string $title
 * @property string $academic_year
 * @property int|null $period_month
 * @property int|null $period_year
 * @property string $billing_cycle
 * @property Carbon $due_date
 * @property int $grace_period_days
 * @property float $late_fee_amount
 * @property string|null $description
 * @property string $status
 * @property int $total_targeted_students
 * @property float $total_invoiced_amount
 * @property float $total_collected_amount
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read FeeType $feeType
 * @property-read Batch $batch
 * @property-read Course $course
 * @property-read Collection<int, StudentFeeInvoice> $invoices
 * @property-read User|null $creator
 */
class FeeSchedule extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'schedule_no',
        'fee_type_id',
        'batch_id',
        'course_id',
        'title',
        'academic_year',
        'period_month',
        'period_year',
        'billing_cycle',
        'due_date',
        'grace_period_days',
        'late_fee_amount',
        'description',
        'status',
        'total_targeted_students',
        'total_invoiced_amount',
        'total_collected_amount',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'period_month' => 'integer',
            'period_year' => 'integer',
            'grace_period_days' => 'integer',
            'late_fee_amount' => 'decimal:2',
            'total_targeted_students' => 'integer',
            'total_invoiced_amount' => 'decimal:2',
            'total_collected_amount' => 'decimal:2',
        ];
    }

    public static function generateScheduleNo(): string
    {
        $year = date('Y');
        $prefix = "SCH-{$year}-";

        $latest = static::withTrashed()
            ->where('schedule_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(schedule_no) DESC, schedule_no DESC')
            ->value('schedule_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        $candidate = sprintf('%s%04d', $prefix, $nextSeq);

        while (static::withTrashed()->where('schedule_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = sprintf('%s%04d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(StudentFeeInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getMonthNameAttribute(): ?string
    {
        if (! $this->period_month) {
            return null;
        }

        return Carbon::create(null, $this->period_month, 1)->format('F');
    }

    public function isWithinPaymentWindow(): bool
    {
        return now()->toDateString() <= $this->due_date->toDateString();
    }

    public function isReverted(): bool
    {
        return in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REVERTED], true);
    }

    public function canBeReverted(): bool
    {
        return ! $this->isReverted();
    }

    public function canBeDeleted(): bool
    {
        return (float) $this->total_collected_amount <= 0.00
            && ! $this->invoices()->where('paid_amount', '>', 0)->exists();
    }

    public function updateTotalCollected(): void
    {
        $this->update([
            'total_collected_amount' => $this->invoices()->sum('paid_amount'),
        ]);
    }
}
