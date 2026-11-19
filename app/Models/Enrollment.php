<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Services\StudentIdGeneratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $enrollment_no
 * @property string|null $application_no
 * @property int $user_id
 * @property int $batch_id
 * @property int $course_id
 * @property int|null $section_id
 * @property string|null $roll_no
 * @property Carbon $enrolled_at
 * @property Carbon|null $completed_at
 * @property string $status
 * @property float $fee_amount
 * @property string $fee_status
 * @property string|null $remarks
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User $student
 * @property-read Batch $batch
 * @property-read Course $course
 * @property-read Section|null $section
 */
class Enrollment extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DISCONTINUED = 'discontinued';

    protected $fillable = [
        'enrollment_no',
        'application_no',
        'user_id',
        'student_id',
        'batch_id',
        'course_id',
        'section_id',
        'roll_no',
        'enrolled_at',
        'completed_at',
        'status',
        'fee_amount',
        'fee_status',
        'remarks',
        'cancellation_reason',
        'discontinued_at',
        'discontinued_by',
        'reinstated_at',
        'reinstated_by',
        'reinstatement_reason',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
            'completed_at' => 'date',
            'discontinued_at' => 'date',
            'reinstated_at' => 'datetime',
            'fee_amount' => 'decimal:2',
        ];
    }

    public static function generateEnrollmentNo(?int $batchId, ?int $courseId): string
    {
        $batch = $batchId ? Batch::find($batchId) : null;
        $course = $courseId ? Course::find($courseId) : null;

        $year = $batch?->academic_year ?: date('Y');
        $rawCode = $course?->code ?: 'GEN';
        $courseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawCode)) ?: 'GEN';

        $prefix = "EN-{$year}-{$courseCode}-";

        // Query the highest existing sequence with this prefix
        $latest = static::withTrashed()
            ->where('enrollment_no', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(enrollment_no) DESC, enrollment_no DESC')
            ->value('enrollment_no');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        } else {
            $count = static::withTrashed()
                ->where('batch_id', $batchId)
                ->where('course_id', $courseId)
                ->count();
            $nextSeq = $count + 1;
        }

        $candidate = $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);

        while (static::withTrashed()->where('enrollment_no', $candidate)->exists()) {
            $nextSeq++;
            $candidate = $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    protected static function booted(): void
    {
        static::creating(function (Enrollment $enrollment) {
            if (empty($enrollment->enrollment_no)) {
                $enrollment->enrollment_no = static::generateEnrollmentNo($enrollment->batch_id, $enrollment->course_id);
            }

            if (empty($enrollment->attributes['student_id']) && $enrollment->user_id) {
                $studentUser = $enrollment->user ?? User::find($enrollment->user_id);
                if ($studentUser) {
                    $enrollment->student_id = ! empty($studentUser->student_id)
                        ? $studentUser->student_id
                        : StudentIdGeneratorService::generateForEnrollment(
                            $studentUser,
                            $enrollment->course ?? Course::find($enrollment->course_id),
                            $enrollment->section ?? Section::find($enrollment->section_id),
                            $enrollment->batch?->academic_year ?? Batch::find($enrollment->batch_id)?->academic_year,
                            true
                        );
                }
            }

            if (auth()->check() && empty($enrollment->created_by)) {
                $enrollment->created_by = auth()->id();
            }
        });

        static::updating(function (Enrollment $enrollment) {
            if (auth()->check()) {
                $enrollment->updated_by = auth()->id();
            }
        });

        static::deleting(function (Enrollment $enrollment) {
            if (auth()->check() && ! $enrollment->isForceDeleting() && empty($enrollment->deleted_by)) {
                $enrollment->deleted_by = auth()->id();
                $enrollment->saveQuietly();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function feeInvoices(): HasMany
    {
        return $this->hasMany(StudentFeeInvoice::class);
    }

    public function studentTestResults(): HasMany
    {
        return $this->hasMany(StudentTestResult::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStudentIdAttribute($value): ?string
    {
        return $value ?: $this->user?->student_id;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function discontinuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discontinued_by');
    }

    public function courseWithdrawalRequests(): HasMany
    {
        return $this->hasMany(CourseWithdrawalRequest::class)->latest();
    }

    public function pendingWithdrawalRequest(): HasOne
    {
        return $this->hasOne(CourseWithdrawalRequest::class)->where('status', 'pending');
    }

    public function reinstatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reinstated_by');
    }

    public function isDiscontinued(): bool
    {
        return in_array($this->status, ['discontinued', 'withdrawn', 'cancelled'], true);
    }

    public function isWithdrawn(): bool
    {
        return $this->status === 'withdrawn';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function canRequestWithdrawal(): bool
    {
        return $this->status === 'active' && ! $this->pendingWithdrawalRequest()->exists();
    }

    public function canBeReinstated(): bool
    {
        return in_array($this->status, ['withdrawn', 'discontinued', 'cancelled', 'dropped'], true);
    }

    /**
     * Systematically reinstate a withdrawn, discontinued, or cancelled enrollment back to active.
     */
    public function reinstate(string $reason, ?int $adminId = null, bool $activateUser = true, ?string $orderNumber = null): bool
    {
        $adminId = $adminId ?? auth()->id();
        $oldStatus = $this->status;
        $orderPrefix = ! empty($orderNumber) ? "Order #{$orderNumber}: " : '';
        $fullReason = $orderPrefix.trim($reason);

        $this->update([
            'status' => 'active',
            'reinstated_at' => now(),
            'reinstated_by' => $adminId,
            'reinstatement_reason' => $fullReason,
            'remarks' => trim(($this->remarks ? $this->remarks."\n" : '').'['.now()->format('Y-m-d H:i')." Reinstated by Admin: {$fullReason} (Previous status: {$oldStatus})]"),
        ]);

        // If there was an approved withdrawal request for this enrollment, revert it
        $withdrawalRequest = $this->courseWithdrawalRequests()
            ->where('status', 'approved')
            ->latest()
            ->first();

        if ($withdrawalRequest) {
            $withdrawalRequest->update([
                'status' => 'reverted',
                'admin_notes' => trim(($withdrawalRequest->admin_notes ? $withdrawalRequest->admin_notes."\n" : '').'['.now()->format('Y-m-d H:i')." Administratively Reverted: {$fullReason}]"),
            ]);
        }

        // If requested and student user account is not active, restore user to active if no blocking ban exists
        if ($activateUser && $this->user) {
            $studentUser = $this->user;
            if (in_array($studentUser->status, ['inactive', 'suspended', 'discontinued', 'withdrawn'], true)) {
                if (! $studentUser->isBanned()) {
                    $studentUser->update(['status' => 'active']);
                }
            }
        }

        AuditLog::record(
            action: 'enrollment.reinstated',
            details: [
                'enrollment_id' => $this->id,
                'enrollment_no' => $this->enrollment_no,
                'user_id' => $this->user_id,
                'student_name' => $this->user?->name,
                'course' => $this->course?->code,
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'reason' => $fullReason,
                'reinstated_by' => $adminId,
            ],
            userId: $adminId,
            auditableType: static::class,
            auditableId: $this->id,
            description: "Reinstated student {$this->user?->name} ({$this->enrollment_no}) in course {$this->course?->name} back to active."
        );

        return true;
    }
}
