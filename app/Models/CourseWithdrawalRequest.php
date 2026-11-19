<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_no
 * @property int $enrollment_id
 * @property int $user_id
 * @property int $course_id
 * @property int $batch_id
 * @property string $type
 * @property string $reason
 * @property string|null $reason_details
 * @property string|null $supporting_document
 * @property string $status
 * @property string|null $admin_notes
 * @property Carbon|null $effective_date
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User $student
 * @property-read Enrollment $enrollment
 * @property-read Course $course
 * @property-read Batch $batch
 * @property-read User|null $reviewer
 */
class CourseWithdrawalRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'request_no',
        'request_number',
        'enrollment_id',
        'user_id',
        'student_id',
        'course_id',
        'batch_id',
        'type',
        'request_type',
        'reason',
        'reason_category',
        'reason_details',
        'reason_description',
        'supporting_document',
        'supporting_document_path',
        'status',
        'admin_notes',
        'effective_date',
        'reviewed_by',
        'reviewed_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CourseWithdrawalRequest $req) {
            if (empty($req->request_no)) {
                $prefix = $req->type === 'discontinuation' ? 'DIS' : 'WTH';
                $year = date('Y');
                $count = static::whereYear('created_at', date('Y'))->count() + 1;
                $req->request_no = $prefix.'-'.$year.'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            }

            if (auth()->check() && empty($req->created_by)) {
                $req->created_by = auth()->id();
            }
        });

        static::updating(function (CourseWithdrawalRequest $req) {
            if (auth()->check()) {
                $req->updated_by = auth()->id();
            }
        });

        static::deleting(function (CourseWithdrawalRequest $req) {
            if (auth()->check() && ! $req->isForceDeleting() && empty($req->deleted_by)) {
                $req->deleted_by = auth()->id();
                $req->saveQuietly();
            }
        });
    }

    public static function getReasons(): array
    {
        return [
            'medical' => 'Medical / Severe Health Conditions',
            'employment' => 'Secured Employment / Job Placement',
            'exam_cleared' => 'Selected in Another Competitive Exam',
            'relocation' => 'Permanent Relocation / Moved Out of Region',
            'personal' => 'Personal / Family Responsibilities',
            'financial' => 'Financial Difficulties / Economic Hardship',
            'academic_difficulty' => 'Academic Pace / Schedule Conflict',
            'other' => 'Other Grounds (Explained Below)',
        ];
    }

    public function getReasonLabel(?string $key = null): string
    {
        $k = $key ?? $this->reason_category ?? $this->reason;

        if (empty($k)) {
            return '—';
        }

        return static::getReasons()[$k] ?? ucfirst(str_replace('_', ' ', (string) $k));
    }

    public static function reasonLabel(?string $key): string
    {
        if (empty($key)) {
            return '—';
        }

        return static::getReasons()[$key] ?? ucfirst(str_replace('_', ' ', (string) $key));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isReverted(): bool
    {
        return $this->status === 'reverted';
    }

    public function scopeReverted(Builder $query): Builder
    {
        return $query->where('status', 'reverted');
    }

    public function getStudentIdAttribute(): ?int
    {
        return $this->user_id;
    }

    public function setStudentIdAttribute($value): void
    {
        $this->attributes['user_id'] = $value;
    }

    public function getRequestNumberAttribute(): ?string
    {
        return $this->request_no;
    }

    public function setRequestNumberAttribute($value): void
    {
        $this->attributes['request_no'] = $value;
    }

    public function getReasonCategoryAttribute(): ?string
    {
        return $this->reason;
    }

    public function setReasonCategoryAttribute($value): void
    {
        $this->attributes['reason'] = $value;
    }

    public function getReasonDescriptionAttribute(): ?string
    {
        return $this->reason_details;
    }

    public function setReasonDescriptionAttribute($value): void
    {
        $this->attributes['reason_details'] = $value;
    }

    public function getRequestTypeAttribute(): ?string
    {
        return $this->type;
    }

    public function setRequestTypeAttribute($value): void
    {
        $this->attributes['type'] = $value;
    }

    public function getSupportingDocumentPathAttribute(): ?string
    {
        return $this->supporting_document;
    }

    public function setSupportingDocumentPathAttribute($value): void
    {
        $this->attributes['supporting_document'] = $value;
    }

    public function getRequestedAtAttribute(): ?\Carbon\Carbon
    {
        return $this->created_at ? \Carbon\Carbon::parse($this->created_at) : null;
    }
}
