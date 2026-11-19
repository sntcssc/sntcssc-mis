<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $mock_interview_session_id
 * @property int|null $mock_interview_candidate_id
 * @property int|null $mock_interview_session_member_id
 * @property string $recipient_type
 * @property string $recipient_name
 * @property string|null $recipient_contact
 * @property int|null $user_id
 * @property string $channel
 * @property string $purpose
 * @property string|null $subject
 * @property string $message_content
 * @property string $status
 * @property string|null $error_message
 * @property int|null $sent_by
 * @property Carbon $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MockInterviewSession $session
 * @property-read MockInterviewCandidate|null $candidate
 * @property-read MockInterviewSessionMember|null $sessionMember
 * @property-read User|null $user
 * @property-read User|null $sender
 */
class MockInterviewInvitationLog extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'mock_interview_session_id' => 'integer',
        'mock_interview_candidate_id' => 'integer',
        'mock_interview_session_member_id' => 'integer',
        'user_id' => 'integer',
        'sent_by' => 'integer',
        'sent_at' => 'datetime',
    ];

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_TELEGRAM = 'telegram';

    public const CHANNEL_IN_APP = 'in_app';

    public const PURPOSE_INVITATION = 'invitation';

    public const PURPOSE_RECORDING = 'recording';

    public const PURPOSE_REMINDER = 'reminder';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    // Relationships

    public function session(): BelongsTo
    {
        return $this->belongsTo(MockInterviewSession::class, 'mock_interview_session_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(MockInterviewCandidate::class, 'mock_interview_candidate_id');
    }

    public function sessionMember(): BelongsTo
    {
        return $this->belongsTo(MockInterviewSessionMember::class, 'mock_interview_session_member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    // Scopes

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
