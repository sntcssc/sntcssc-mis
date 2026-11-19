<?php

namespace App\Models;

use App\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $mock_interview_session_id
 * @property int $user_id
 * @property int|null $batch_id
 * @property int|null $course_id
 * @property int $sequence_number
 * @property string $scheduled_start_time
 * @property string $scheduled_end_time
 * @property int $duration_minutes
 * @property string $attending_mode
 * @property string|null $venue_address
 * @property string|null $room_number
 * @property string|null $platform
 * @property string|null $platform_other
 * @property string|null $meeting_link
 * @property string|null $meeting_id
 * @property string|null $meeting_password
 * @property string|null $candidate_instructions
 * @property string $status
 * @property float|null $interview_score
 * @property string|null $board_feedback
 * @property string $recording_type
 * @property string|null $recording_url
 * @property string|null $recording_file_path
 * @property string|null $recording_password
 * @property string $recording_status
 * @property Carbon|null $recording_uploaded_at
 * @property string|null $recording_notes
 * @property Carbon|null $invitation_sent_at
 * @property array|null $invitation_channels
 * @property Carbon|null $recording_sent_at
 * @property array|null $recording_sent_channels
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read MockInterviewSession $session
 * @property-read User $user
 * @property-read Batch|null $batch
 * @property-read Course|null $course
 * @property-read Collection<int, MockInterviewInvitationLog> $invitationLogs
 */
class MockInterviewCandidate extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'mock_interview_session_id' => 'integer',
        'user_id' => 'integer',
        'batch_id' => 'integer',
        'course_id' => 'integer',
        'sequence_number' => 'integer',
        'duration_minutes' => 'integer',
        'interview_score' => 'float',
        'recording_uploaded_at' => 'datetime',
        'invitation_sent_at' => 'datetime',
        'recording_sent_at' => 'datetime',
        'invitation_channels' => 'array',
        'recording_sent_channels' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
    ];

    public const MODE_OFFLINE = 'offline';

    public const MODE_ONLINE = 'online';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_INTERVIEWING = 'interviewing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const RECORDING_NOT_AVAILABLE = 'not_available';

    public const RECORDING_PROCESSING = 'processing';

    public const RECORDING_AVAILABLE = 'available';

    public const PLATFORMS = [
        'Zoom' => 'Zoom Meetings',
        'Google Meet' => 'Google Meet',
        'Microsoft Teams' => 'Microsoft Teams',
        'Cisco Webex Meetings' => 'Cisco Webex Meetings',
        'Others' => 'Other Video Conference',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
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

    // Relationships

    public function session(): BelongsTo
    {
        return $this->belongsTo(MockInterviewSession::class, 'mock_interview_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function invitationLogs(): HasMany
    {
        return $this->hasMany(MockInterviewInvitationLog::class, 'mock_interview_candidate_id');
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

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('attending_mode', self::MODE_ONLINE);
    }

    public function scopeOffline(Builder $query): Builder
    {
        return $query->where('attending_mode', self::MODE_OFFLINE);
    }

    public function scopeRecorded(Builder $query): Builder
    {
        return $query->where('recording_status', self::RECORDING_AVAILABLE);
    }

    public function scopeForUser(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->whereHas('user', function (Builder $u) use ($term) {
                $u->where('name', 'like', "%{$term}%")
                    ->orWhere('student_id', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            })->orWhere('venue_address', 'like', "%{$term}%")
                ->orWhere('platform', 'like', "%{$term}%")
                ->orWhere('status', 'like', "%{$term}%");
        });
    }

    // Accessors & Helpers

    public function isOnline(): bool
    {
        return $this->attending_mode === self::MODE_ONLINE;
    }

    public function isOffline(): bool
    {
        return $this->attending_mode === self::MODE_OFFLINE;
    }

    public function hasRecording(): bool
    {
        return $this->recording_status === self::RECORDING_AVAILABLE
            && (! empty($this->recording_url) || ! empty($this->recording_file_path));
    }

    public function getEffectiveRecordingUrl(): ?string
    {
        if (! empty($this->recording_url)) {
            return $this->recording_url;
        }

        if (! empty($this->recording_file_path)) {
            return Storage::disk('public')->url($this->recording_file_path);
        }

        return null;
    }

    public function getFormattedSlotTimeAttribute(): string
    {
        $start = $this->scheduled_start_time ? Carbon::parse($this->scheduled_start_time)->format('h:i A') : '--';
        $end = $this->scheduled_end_time ? Carbon::parse($this->scheduled_end_time)->format('h:i A') : '--';

        return "{$start} - {$end} ({$this->duration_minutes}m)";
    }

    public function getEffectiveVenueAddress(): string
    {
        return $this->venue_address ?: ($this->session?->venue_address ?? 'SNTCSSC Campus');
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_SCHEDULED => 'blue',
            self::STATUS_REPORTED => 'amber',
            self::STATUS_INTERVIEWING => 'purple',
            self::STATUS_COMPLETED => 'emerald',
            self::STATUS_ABSENT => 'rose',
            self::STATUS_RESCHEDULED => 'zinc',
            default => 'zinc',
        };
    }
}
