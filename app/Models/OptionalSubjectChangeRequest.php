<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $request_no
 * @property int $user_id
 * @property string|null $current_subject
 * @property string $requested_subject
 * @property int|null $optional_subject_id
 * @property string $reason
 * @property string $status
 * @property string|null $admin_remarks
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read OptionalSubject|null $optionalSubject
 * @property-read User|null $reviewer
 */
class OptionalSubjectChangeRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'optional_subject_change_requests';

    protected $fillable = [
        'request_no',
        'user_id',
        'current_subject',
        'requested_subject',
        'optional_subject_id',
        'reason',
        'status',
        'admin_remarks',
        'reviewed_by',
        'reviewed_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function optionalSubject(): BelongsTo
    {
        return $this->belongsTo(OptionalSubject::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Scope pending requests.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope approved requests.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope rejected requests.
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope search.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('request_no', 'like', "%{$term}%")
                ->orWhere('current_subject', 'like', "%{$term}%")
                ->orWhere('requested_subject', 'like', "%{$term}%")
                ->orWhere('reason', 'like', "%{$term}%")
                ->orWhereHas('user', function (Builder $userQ) use ($term) {
                    $userQ->where('name', 'like', "%{$term}%")
                        ->orWhere('student_id', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
        });
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->request_no)) {
                $model->request_no = static::generateRequestNumber();
            }
        });
    }

    /**
     * Alias for generateRequestNumber.
     */
    public static function generateRequestNo(): string
    {
        return static::generateRequestNumber();
    }

    /**
     * Check if request is currently pending review.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if request is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if request is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Generate unique sequential Request Number: OSCR-YYYY-XXXX.
     */
    public static function generateRequestNumber(): string
    {
        $year = now()->format('Y');
        $last = static::withTrashed()
            ->where('request_no', 'like', "OSCR-{$year}-%")
            ->orderByDesc('id')
            ->first();

        $seq = 1;
        if ($last && preg_match("/OSCR-{$year}-(\d+)/", $last->request_no, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('OSCR-%s-%04d', $year, $seq);
    }

    /**
     * Approve this change request and update student_other_details.
     */
    public function approve(User $reviewer, ?string $remarks = null): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \RuntimeException(__('Only pending requests can be approved.'));
        }

        return DB::transaction(function () use ($reviewer, $remarks) {
            $oldSubject = $this->current_subject;
            $newSubject = $this->requested_subject;

            // 1. Update request status
            $this->update([
                'status' => self::STATUS_APPROVED,
                'admin_remarks' => $remarks,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'updated_by' => $reviewer->id,
            ]);

            // 2. Update student_other_details
            $other = StudentOtherDetail::firstOrCreate(
                ['user_id' => $this->user_id],
                ['attempts_already_given' => 0]
            );

            $oldOtherSubject = $other->optional_subject;
            $other->optional_subject = $newSubject;
            $other->save();

            // 3. Audit trail
            AuditLogService::log(
                event: 'optional_subject_change_approved',
                description: "Approved optional subject change for student #{$this->user->student_id} from '{$oldOtherSubject}' to '{$newSubject}'.",
                auditable: $this,
                oldValues: ['optional_subject' => $oldOtherSubject, 'status' => self::STATUS_PENDING],
                newValues: ['optional_subject' => $newSubject, 'status' => self::STATUS_APPROVED, 'admin_remarks' => $remarks],
                userId: $reviewer->id
            );

            return true;
        });
    }

    /**
     * Reject this change request with reason/remarks.
     */
    public function reject(User $reviewer, string $remarks): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \RuntimeException(__('Only pending requests can be rejected.'));
        }

        return DB::transaction(function () use ($reviewer, $remarks) {
            $this->update([
                'status' => self::STATUS_REJECTED,
                'admin_remarks' => $remarks,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'updated_by' => $reviewer->id,
            ]);

            AuditLogService::log(
                event: 'optional_subject_change_rejected',
                description: "Rejected optional subject change for student #{$this->user->student_id}. Reason: {$remarks}",
                auditable: $this,
                oldValues: ['status' => self::STATUS_PENDING],
                newValues: ['status' => self::STATUS_REJECTED, 'admin_remarks' => $remarks],
                userId: $reviewer->id
            );

            return true;
        });
    }
}
