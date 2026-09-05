<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatCallParticipant extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_INVITED = 'invited';

    public const STATUS_RINGING = 'ringing';

    public const STATUS_JOINED = 'joined';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_LEFT = 'left';

    protected $fillable = [
        'call_id',
        'user_id',
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

    public function call(): BelongsTo
    {
        return $this->belongsTo(ChatCall::class, 'call_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isJoined(): bool
    {
        return $this->status === self::STATUS_JOINED;
    }

    public function isRinging(): bool
    {
        return $this->status === self::STATUS_RINGING;
    }

    public function isDeclined(): bool
    {
        return $this->status === self::STATUS_DECLINED;
    }

    public function isLeft(): bool
    {
        return $this->status === self::STATUS_LEFT;
    }
}
