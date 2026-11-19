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
 * @property string $action_no
 * @property int $user_id
 * @property int|null $course_id
 * @property string $action_type
 * @property string $order_no
 * @property Carbon $order_date
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property bool $is_lifetime
 * @property string $reason_category
 * @property string|null $reason_details
 * @property string|null $supporting_document
 * @property string $status
 * @property string|null $revocation_reason
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User $student
 * @property-read Course|null $course
 * @property-read User|null $revoker
 * @property-read User|null $creator
 */
class StudentDisciplinaryAction extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'action_no',
        'user_id',
        'student_id',
        'course_id',
        'action_type',
        'order_no',
        'order_number',
        'order_date',
        'start_date',
        'end_date',
        'is_lifetime',
        'reason_category',
        'reason_details',
        'findings',
        'supporting_document',
        'order_copy_path',
        'status',
        'is_active',
        'action_code',
        'revocation_reason',
        'revoked_by',
        'revoked_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_lifetime' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StudentDisciplinaryAction $action) {
            if (empty($action->action_no)) {
                $year = date('Y');
                $count = static::whereYear('created_at', date('Y'))->count() + 1;
                $action->action_no = 'DISC-'.$year.'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
            }

            if (auth()->check() && empty($action->created_by)) {
                $action->created_by = auth()->id();
            }
        });

        static::updating(function (StudentDisciplinaryAction $action) {
            if (auth()->check()) {
                $action->updated_by = auth()->id();
            }
        });

        static::deleting(function (StudentDisciplinaryAction $action) {
            if (auth()->check() && ! $action->isForceDeleting() && empty($action->deleted_by)) {
                $action->deleted_by = auth()->id();
                $action->saveQuietly();
            }
        });
    }

    public static function getActionTypes(): array
    {
        return [
            'suspension' => 'Temporary Academic Suspension',
            'ban' => 'Permanent Lifetime Disbarment / Ban',
            'course_disbarment' => 'Course-Level Disbarment',
        ];
    }

    public static function getReasonCategories(): array
    {
        return [
            'malpractice_exam' => 'Examination Malpractice / Cheating / Unfair Means',
            'disciplinary_misconduct' => 'Gross Insubordination / Behavioral Misconduct',
            'ragging_harassment' => 'Violation of Anti-Ragging & Harassment Norms',
            'forged_documents' => 'Submission of Fraudulent / Forged Certificates',
            'unauthorized_absence' => 'Chronic Truancy / Unreported Extended Absence',
            'non_payment' => 'Willful Default / Refusal of Prescribed Fee',
            'code_of_conduct_violation' => 'Breach of SNTCSSC Institutional Code of Conduct',
            'other' => 'Other Serious Institutional Grounds',
        ];
    }

    public static function getReasonLabel(?string $key): string
    {
        if (empty($key)) {
            return '—';
        }

        return static::getReasonCategories()[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->where('is_lifetime', true)
                    ->orWhereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            });
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->is_lifetime || $this->end_date === null) {
            return true;
        }

        return $this->end_date->isFuture() || $this->end_date->isToday();
    }

    public function isLifetime(): bool
    {
        return (bool) $this->is_lifetime;
    }

    public function getStudentIdAttribute(): ?int
    {
        return $this->user_id;
    }

    public function setStudentIdAttribute($value): void
    {
        $this->attributes['user_id'] = $value;
    }

    public function getActionCodeAttribute(): ?string
    {
        return $this->action_no;
    }

    public function setActionCodeAttribute($value): void
    {
        $this->attributes['action_no'] = $value;
    }

    public function getOrderNumberAttribute(): ?string
    {
        return $this->order_no;
    }

    public function setOrderNumberAttribute($value): void
    {
        $this->attributes['order_no'] = $value;
    }

    public function getFindingsAttribute(): ?string
    {
        return $this->reason_details;
    }

    public function setFindingsAttribute($value): void
    {
        $this->attributes['reason_details'] = $value;
    }

    public function getOrderCopyPathAttribute(): ?string
    {
        return $this->supporting_document;
    }

    public function setOrderCopyPathAttribute($value): void
    {
        $this->attributes['supporting_document'] = $value;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->isActive();
    }

    public function setIsActiveAttribute($value): void
    {
        $this->attributes['status'] = $value ? 'active' : 'revoked';
    }
}
