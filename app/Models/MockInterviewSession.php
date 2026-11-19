<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Services\MockInterviewService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $session_code
 * @property string $title
 * @property int $session_number
 * @property string $academic_year
 * @property Carbon $session_date
 * @property string $start_time
 * @property string $end_time
 * @property string|null $reporting_time
 * @property string $venue_address
 * @property string|null $room_number
 * @property int $default_interview_duration
 * @property int $default_buffer_duration
 * @property string|null $instructions
 * @property string|null $remarks
 * @property string $status
 * @property string|null $chairman_invitation_template
 * @property string|null $member_invitation_template
 * @property string|null $student_invitation_template
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, MockInterviewSessionMember> $sessionMembers
 * @property-read Collection<int, InterviewBoardMember> $boardMembers
 * @property-read Collection<int, MockInterviewCandidate> $candidates
 * @property-read Collection<int, MockInterviewInvitationLog> $invitationLogs
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class MockInterviewSession extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'session_date' => 'date',
        'session_number' => 'integer',
        'default_interview_duration' => 'integer',
        'default_buffer_duration' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->session_code)) {
                $model->session_code = static::generateNextSessionCode($model->academic_year ?? date('Y'));
            }
            if (auth()->check()) {
                $model->created_by = auth()->id();
                $model->updated_by = auth()->id();
            }
        });

        static::updating(function (self $model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });

        static::deleting(function (self $model) {
            if (auth()->check()) {
                $model->deleted_by = auth()->id();
                $model->saveQuietly();
            }
        });
    }

    /**
     * Generate unique session code format: MIS-YYYY-S##
     */
    public static function generateNextSessionCode(?string $year = null): string
    {
        $year = $year ?: date('Y');
        $prefix = "MIS-{$year}-S";

        $latest = static::withTrashed()
            ->where('session_code', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('session_code');

        if ($latest && preg_match('/MIS-\d+-S(\d+)/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        } else {
            $next = static::withTrashed()->where('academic_year', $year)->count() + 1;
        }

        return sprintf('%s%02d', $prefix, $next);
    }

    // Relationships

    public function sessionMembers(): HasMany
    {
        return $this->hasMany(MockInterviewSessionMember::class, 'mock_interview_session_id')
            ->orderBy('order_index', 'asc');
    }

    public function boardMembers(): BelongsToMany
    {
        return $this->belongsToMany(
            InterviewBoardMember::class,
            'mock_interview_session_members',
            'mock_interview_session_id',
            'interview_board_member_id'
        )->withPivot(['id', 'role', 'order_index', 'attendance_status', 'invitation_sent_at', 'invitation_channels', 'remarks'])
            ->withTimestamps()
            ->orderByPivot('order_index', 'asc');
    }

    public function chairpersonMember(): HasOne
    {
        return $this->hasOne(MockInterviewSessionMember::class, 'mock_interview_session_id')
            ->where('role', MockInterviewSessionMember::ROLE_CHAIRPERSON);
    }

    public function chairperson(): ?InterviewBoardMember
    {
        $chairMember = $this->sessionMembers->firstWhere('role', MockInterviewSessionMember::ROLE_CHAIRPERSON);

        return $chairMember?->boardMember;
    }

    public function regularBoardMembers(): Collection
    {
        return $this->sessionMembers->where('role', MockInterviewSessionMember::ROLE_MEMBER);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(MockInterviewCandidate::class, 'mock_interview_session_id')
            ->orderBy('sequence_number', 'asc');
    }

    public function invitationLogs(): HasMany
    {
        return $this->hasMany(MockInterviewInvitationLog::class, 'mock_interview_session_id')
            ->latest('sent_at');
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

    // Scopes

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('session_code', 'like', "%{$term}%")
                ->orWhere('venue_address', 'like', "%{$term}%")
                ->orWhere('academic_year', 'like', "%{$term}%")
                ->orWhereHas('boardMembers', function (Builder $bm) use ($term) {
                    $bm->where('name', 'like', "%{$term}%")
                        ->orWhere('designation', 'like', "%{$term}%");
                })
                ->orWhereHas('candidates.user', function (Builder $u) use ($term) {
                    $u->where('name', 'like', "%{$term}%")
                        ->orWhere('student_id', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
        });
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('session_date', '>=', now()->toDateString());
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->whereDate('session_date', '<', now()->toDateString());
    }

    public function scopeYear(Builder $query, ?string $year): Builder
    {
        if (blank($year)) {
            return $query;
        }

        return $query->where('academic_year', $year);
    }

    // Accessors & Helper Methods

    public function getFormattedTimeRangeAttribute(): string
    {
        $start = $this->start_time ? Carbon::parse($this->start_time)->format('h:i A') : '--';
        $end = $this->end_time ? Carbon::parse($this->end_time)->format('h:i A') : '--';

        return "{$start} - {$end}";
    }

    public function getFormattedReportingTimeAttribute(): ?string
    {
        return $this->reporting_time ? Carbon::parse($this->reporting_time)->format('h:i A') : null;
    }

    public function getFormattedDateAttribute(): string
    {
        return $this->session_date ? $this->session_date->format('d M Y (D)') : '--';
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'zinc',
            self::STATUS_SCHEDULED => 'blue',
            self::STATUS_IN_PROGRESS => 'amber',
            self::STATUS_COMPLETED => 'emerald',
            self::STATUS_CANCELLED => 'rose',
            default => 'zinc',
        };
    }

    public function getChairmanInvitationTemplate(): string
    {
        return $this->chairman_invitation_template
            ?: app(MockInterviewService::class)->getDefaultChairmanInvitationTemplate();
    }

    public function getMemberInvitationTemplate(): string
    {
        return $this->member_invitation_template
            ?: app(MockInterviewService::class)->getDefaultMemberInvitationTemplate();
    }

    public function getStudentInvitationTemplate(): string
    {
        return $this->student_invitation_template
            ?: app(MockInterviewService::class)->getDefaultStudentInvitationTemplate();
    }
}
