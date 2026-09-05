<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMeetingParticipant extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const ROLE_HOST = 'host';

    public const ROLE_CO_HOST = 'co_host';

    public const ROLE_PARTICIPANT = 'participant';

    public const STATUS_INVITED = 'invited';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_JOINED = 'joined';

    public const STATUS_LEFT = 'left';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_DENIED = 'denied';

    protected $fillable = [
        'meeting_id',
        'user_id',
        'guest_name',
        'role',
        'status',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ChatMeeting::class, 'meeting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function displayName(): string
    {
        return $this->user?->name ?? $this->guest_name ?? __('Guest Participant');
    }

    public function isHost(): bool
    {
        return $this->role === self::ROLE_HOST;
    }

    public function isCoHost(): bool
    {
        return $this->role === self::ROLE_CO_HOST;
    }

    public function isHostOrCoHost(): bool
    {
        return in_array($this->role, [self::ROLE_HOST, self::ROLE_CO_HOST], true);
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    public function isJoined(): bool
    {
        return $this->status === self::STATUS_JOINED;
    }
}
