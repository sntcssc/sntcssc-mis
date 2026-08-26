<?php

namespace App\Events;

use App\Models\AppNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RealtimeNotificationEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public AppNotification $notification,
        public int $unreadCount = 0
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("App.Models.User.{$this->notification->user_id}"),
            new PrivateChannel("user.{$this->notification->user_id}"),
        ];
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'RealtimeNotificationEvent';
    }

    /**
     * Payload sent across WebSockets/Reverb.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'uuid' => $this->notification->uuid,
            'user_id' => $this->notification->user_id,
            'type' => $this->notification->type,
            'category' => $this->notification->category,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'data' => $this->notification->data,
            'channel' => $this->notification->channel,
            'read_at' => $this->notification->read_at?->toISOString(),
            'action_url' => $this->notification->actionUrl(),
            'action_label' => $this->notification->actionLabel(),
            'icon' => $this->notification->iconName(),
            'color' => $this->notification->colorClass(),
            'category_label' => $this->notification->categoryLabel(),
            'created_at' => $this->notification->created_at?->toISOString(),
            'time_ago' => $this->notification->created_at?->diffForHumans() ?? 'Just now',
            'unread_count' => $this->unreadCount,
        ];
    }
}
