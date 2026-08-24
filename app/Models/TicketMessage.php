<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketMessage extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_PUBLIC_REPLY = 'public_reply';

    public const TYPE_INTERNAL_NOTE = 'internal_note';

    public const TYPE_SYSTEM_EVENT = 'system_event';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'sender_name',
        'sender_email',
        'type',
        'message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_message_id');
    }

    public function isInternalNote(): bool
    {
        return $this->type === self::TYPE_INTERNAL_NOTE;
    }

    public function isPublicReply(): bool
    {
        return $this->type === self::TYPE_PUBLIC_REPLY;
    }

    public function isSystemEvent(): bool
    {
        return $this->type === self::TYPE_SYSTEM_EVENT;
    }

    public function senderDisplayName(): string
    {
        if ($this->user) {
            return $this->user->name;
        }

        return $this->sender_name ?: __('System');
    }
}
