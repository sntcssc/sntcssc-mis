<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatMeeting extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const TYPE_INSTANT = 'instant';

    public const TYPE_SCHEDULED = 'scheduled';

    public const MODE_VIDEO = 'video';

    public const MODE_AUDIO = 'audio';

    public const ACCESS_OPEN = 'open';

    public const ACCESS_INVITED_ONLY = 'invited_only';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_LIVE = 'live';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CANCELLED = 'cancelled';

    // Recurrence Constants
    public const REPEAT_NONE = 'none';

    public const REPEAT_ALL_DAY = 'all_day';

    public const REPEAT_DAILY = 'daily';

    public const REPEAT_WEEKLY = 'weekly';

    public const REPEAT_SPECIFIC_DAY = 'specific_day';

    public const REPEAT_MONTHLY = 'monthly';

    public const REPEAT_ANNUALLY = 'annually';

    public const REPEAT_EVERY_WEEKDAY = 'every_weekday';

    public const REPEAT_EVERY_WEEKEND = 'every_weekend';

    public const REPEAT_CUSTOM = 'custom';

    protected $fillable = [
        'uuid',
        'invite_code',
        'team_id',
        'host_id',
        'title',
        'description',
        'type',
        'mode',
        'access_mode',
        'scheduled_at',
        'ends_at',
        'duration_minutes',
        'repeat_type',
        'repeat_until',
        'reminder_offset_minutes',
        'reminder_channels',
        'reminder_sent_at',
        'passcode',
        'status',
        'started_at',
        'ended_at',
        'settings',
        'signal_data',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $meeting) {
            if (empty($meeting->uuid)) {
                $meeting->uuid = (string) Str::uuid();
            }
            if (empty($meeting->invite_code)) {
                $meeting->invite_code = Str::lower(Str::random(12));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ends_at' => 'datetime',
            'repeat_until' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'duration_minutes' => 'integer',
            'reminder_offset_minutes' => 'integer',
            'reminder_channels' => 'array',
            'settings' => 'array',
            'signal_data' => 'array',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatMeetingParticipant::class, 'meeting_id');
    }

    /* ----------------------------------------------------------------- *
     *  Scopes & Helpers
     * ----------------------------------------------------------------- */

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_LIVE])
            ->orderBy('scheduled_at', 'asc');
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ENDED, self::STATUS_CANCELLED])
            ->orderBy('ended_at', 'desc');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('host_id', $userId)
                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $userId));
        });
    }

    public function isHost(?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        return $user && (int) $this->host_id === (int) $user->id;
    }

    public function isHostOrCoHost(?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        if ($this->isHost($user) || $user->hasRole('Super Administrator')) {
            return true;
        }

        return $this->participants()
            ->where('user_id', $user->id)
            ->where('role', ChatMeetingParticipant::ROLE_CO_HOST)
            ->exists();
    }

    public function canManageMeeting(?User $user = null): bool
    {
        return $this->isHostOrCoHost($user);
    }

    public function isOpenForEveryone(): bool
    {
        return $this->access_mode !== self::ACCESS_INVITED_ONLY;
    }

    public function isChatAllowed(): bool
    {
        return (bool) ($this->settings['chat_enabled'] ?? true);
    }

    public function isScreenShareAllowed(): bool
    {
        return (bool) ($this->settings['screen_share_enabled'] ?? true);
    }

    public function isEmojiAllowed(): bool
    {
        return (bool) ($this->settings['emoji_enabled'] ?? true);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isEnded(): bool
    {
        return in_array($this->status, [self::STATUS_ENDED, self::STATUS_CANCELLED], true);
    }

    public function isVideo(): bool
    {
        return $this->mode === self::MODE_VIDEO;
    }

    public function getJoinUrlAttribute(): string
    {
        return url('/meetings/join/'.$this->invite_code);
    }

    public function formattedScheduledAt(): string
    {
        if (! $this->scheduled_at) {
            return __('Instant Meeting');
        }

        return $this->scheduled_at->format('D, d M Y, h:i A');
    }

    public function formattedEndsAt(): ?string
    {
        if (! $this->ends_at) {
            if ($this->scheduled_at && $this->duration_minutes) {
                return $this->scheduled_at->clone()->addMinutes($this->duration_minutes)->format('D, d M Y, h:i A');
            }

            return null;
        }

        return $this->ends_at->format('D, d M Y, h:i A');
    }

    public function formattedDuration(): string
    {
        $minutes = $this->duration_minutes;
        if ($this->scheduled_at && $this->ends_at) {
            $minutes = max(1, $this->scheduled_at->diffInMinutes($this->ends_at));
        }

        $hours = intdiv($minutes, 60);
        $remMinutes = $minutes % 60;

        if ($hours > 0 && $remMinutes > 0) {
            return __(':hours hr :mins mins', ['hours' => $hours, 'mins' => $remMinutes]);
        }

        if ($hours > 0) {
            return __(':hours hour(s)', ['hours' => $hours]);
        }

        return __(':mins mins', ['mins' => $minutes]);
    }

    public function repeatLabel(): string
    {
        return match ($this->repeat_type) {
            self::REPEAT_ALL_DAY => __('All Day'),
            self::REPEAT_DAILY => __('Daily'),
            self::REPEAT_WEEKLY => __('Weekly'),
            self::REPEAT_SPECIFIC_DAY => __('Specific Day of Week'),
            self::REPEAT_MONTHLY => __('Monthly'),
            self::REPEAT_ANNUALLY => __('Annually'),
            self::REPEAT_EVERY_WEEKDAY => __('Every Weekday (Mon–Fri)'),
            self::REPEAT_EVERY_WEEKEND => __('Every Weekend (Sat–Sun)'),
            self::REPEAT_CUSTOM => __('Custom Recurrence'),
            default => __('Does not repeat'),
        };
    }

    public function reminderLabel(): string
    {
        if (! $this->reminder_offset_minutes) {
            return __('No reminder');
        }

        return match ($this->reminder_offset_minutes) {
            10 => __('10 minutes before'),
            15 => __('15 minutes before'),
            30 => __('30 minutes before'),
            60 => __('1 hour before'),
            120 => __('2 hours before'),
            1440 => __('1 day before'),
            2880 => __('2 days before'),
            default => __(':mins mins before', ['mins' => $this->reminder_offset_minutes]),
        };
    }
}
