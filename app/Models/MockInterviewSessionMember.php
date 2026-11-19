<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $mock_interview_session_id
 * @property int $interview_board_member_id
 * @property string $role
 * @property int $order_index
 * @property string $attendance_status
 * @property Carbon|null $invitation_sent_at
 * @property array|null $invitation_channels
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read MockInterviewSession $session
 * @property-read InterviewBoardMember $boardMember
 */
class MockInterviewSessionMember extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'mock_interview_session_id' => 'integer',
        'interview_board_member_id' => 'integer',
        'order_index' => 'integer',
        'invitation_sent_at' => 'datetime',
        'invitation_channels' => 'array',
    ];

    public const ROLE_CHAIRPERSON = 'chairperson';

    public const ROLE_MEMBER = 'member';

    public const STATUS_INVITED = 'invited';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_ABSENT = 'absent';

    // Relationships

    public function session(): BelongsTo
    {
        return $this->belongsTo(MockInterviewSession::class, 'mock_interview_session_id');
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(InterviewBoardMember::class, 'interview_board_member_id');
    }

    public function invitationLogs(): HasMany
    {
        return $this->hasMany(MockInterviewInvitationLog::class, 'mock_interview_session_member_id');
    }

    // Scopes

    public function scopeChairperson(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_CHAIRPERSON);
    }

    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_MEMBER);
    }

    public function isChairperson(): bool
    {
        return $this->role === self::ROLE_CHAIRPERSON;
    }
}
