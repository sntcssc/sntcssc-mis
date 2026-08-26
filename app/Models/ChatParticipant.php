<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'is_muted',
        'muted_until',
        'last_read_at',
        'last_read_message_id',
        'is_starred',
        'is_archived',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'is_muted' => 'boolean',
            'is_starred' => 'boolean',
            'is_archived' => 'boolean',
            'muted_until' => 'datetime',
            'last_read_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function isMember(): bool
    {
        return $this->role === self::ROLE_MEMBER;
    }
}
