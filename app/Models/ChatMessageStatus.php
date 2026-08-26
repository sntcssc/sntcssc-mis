<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'user_id',
        'is_delivered',
        'delivered_at',
        'is_read',
        'read_at',
        'is_deleted_for_me',
    ];

    protected function casts(): array
    {
        return [
            'is_delivered' => 'boolean',
            'is_read' => 'boolean',
            'is_deleted_for_me' => 'boolean',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
