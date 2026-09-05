<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatCall extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const TYPE_AUDIO = 'audio';

    public const TYPE_VIDEO = 'video';

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_RINGING = 'ringing';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_MISSED = 'missed';

    public const STATUS_BUSY = 'busy';

    public const STATUS_ENDED = 'ended';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'conversation_id',
        'caller_id',
        'receiver_id',
        'type',
        'status',
        'started_at',
        'ended_at',
        'duration_seconds',
        'signal_data',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $call) {
            if (empty($call->uuid)) {
                $call->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'signal_data' => 'array',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatCallParticipant::class, 'call_id');
    }

    public function activeParticipants(): HasMany
    {
        return $this->hasMany(ChatCallParticipant::class, 'call_id')
            ->whereIn('status', [ChatCallParticipant::STATUS_JOINED, ChatCallParticipant::STATUS_RINGING]);
    }

    public function joinedParticipants(): HasMany
    {
        return $this->hasMany(ChatCallParticipant::class, 'call_id')
            ->where('status', ChatCallParticipant::STATUS_JOINED);
    }

    public function isAudio(): bool
    {
        return $this->type === self::TYPE_AUDIO;
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function isRinging(): bool
    {
        return $this->status === self::STATUS_RINGING;
    }

    public function isInitiated(): bool
    {
        return $this->status === self::STATUS_INITIATED;
    }

    public function isEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isMissed(): bool
    {
        return $this->status === self::STATUS_MISSED;
    }

    public function isGroupCall(): bool
    {
        if (! empty($this->signal_data['is_group'])) {
            return true;
        }

        if ($this->relationLoaded('conversation') && $this->conversation) {
            return $this->conversation->isGroup();
        }

        return empty($this->receiver_id) && ! empty($this->conversation_id);
    }

    public function formattedDuration(): string
    {
        $seconds = $this->duration_seconds;
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d', $minutes, $remainingSeconds);
    }
}
