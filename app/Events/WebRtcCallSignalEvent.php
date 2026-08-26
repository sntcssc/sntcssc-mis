<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebRtcCallSignalEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $signalType  'incoming_call', 'offer', 'answer', 'ice_candidate', 'call_accepted', 'call_rejected', 'call_ended', 'toggle_video', 'toggle_audio'
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $callUuid,
        public int $recipientUserId,
        public int $senderUserId,
        public string $signalType,
        public array $payload = []
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->recipientUserId}"),
            new PrivateChannel("call.{$this->callUuid}"),
        ];
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'WebRtcCallSignal';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'call_uuid' => $this->callUuid,
            'recipient_user_id' => $this->recipientUserId,
            'sender_user_id' => $this->senderUserId,
            'signal_type' => $this->signalType,
            'payload' => $this->payload,
            'timestamp' => now()->toISOString(),
        ];
    }
}
