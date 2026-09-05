<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $eventType  'participant_joined', 'participant_left', 'waiting_admitted', 'waiting_denied', 'in_room_chat', 'floating_emoji', 'hand_raise', 'meeting_ended', 'force_mute_all', 'force_video_off_all'
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $meetingUuid,
        public string $eventType,
        public array $payload = [],
        public ?int $senderUserId = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("meeting.{$this->meetingUuid}"),
        ];
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'MeetingRealtime';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Keep this payload compact: WebRTC signaling (SDP offers/answers) rides inside
        // $this->payload and must stay under the Reverb max message size (see .env).
        return [
            'event_type' => $this->eventType,
            'type' => $this->eventType,
            'payload' => $this->payload,
            'sender_user_id' => $this->senderUserId,
            'timestamp' => now()->toISOString(),
        ];
    }
}
