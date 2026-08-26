<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ChatMessageSentEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public ChatMessage $message,
        public array $recipientUserIds = []
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("conversation.{$this->message->conversation_id}"),
        ];

        foreach ($this->recipientUserIds as $userId) {
            $channels[] = new PrivateChannel("user.{$userId}");
        }

        return $channels;
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $sender = $this->message->user;
        $replyTo = $this->message->replyTo;

        return [
            'id' => $this->message->id,
            'uuid' => $this->message->uuid,
            'conversation_id' => $this->message->conversation_id,
            'user_id' => $this->message->user_id,
            'sender_name' => $sender?->name ?? 'User',
            'sender_avatar' => $sender?->avatarUrl(),
            'body' => $this->message->body,
            'type' => $this->message->type,
            'reply_to' => $replyTo ? [
                'id' => $replyTo->id,
                'sender_name' => $replyTo->user?->name ?? 'User',
                'body' => Str::limit($replyTo->body ?? '', 80),
                'type' => $replyTo->type,
            ] : null,
            'attachments' => $this->message->attachments->map(fn ($att) => [
                'id' => $att->id,
                'uuid' => $att->uuid,
                'name' => $att->file_name,
                'url' => $att->url(),
                'type' => $att->file_type,
                'size' => $att->formattedSize(),
                'is_image' => $att->isImage(),
                'is_audio' => $att->isAudio(),
                'is_video' => $att->isVideo(),
                'is_pdf' => $att->isPdf(),
            ])->toArray(),
            'is_edited' => $this->message->is_edited,
            'created_at' => $this->message->created_at?->toISOString(),
            'formatted_time' => $this->message->formattedTime(),
            'tick_status' => $this->message->tickStatus(),
        ];
    }
}
