<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

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
        public array $payload = [],
        public ?string $signalId = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("call.{$this->callUuid}"),
        ];

        if ($this->recipientUserId > 0) {
            $channels[] = new PrivateChannel("user.{$this->recipientUserId}");
        }

        return $channels;
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
        $id = $this->signalId ?: ($this->payload['id'] ?? ($this->payload['signal_id'] ?? ('sig_'.(string) Str::uuid())));

        return [
            'id' => $id,
            'signal_id' => $id,
            'signalId' => $id,
            'call_uuid' => $this->callUuid,
            'callUuid' => $this->callUuid,
            'recipient_user_id' => $this->recipientUserId,
            'recipientUserId' => $this->recipientUserId,
            'sender_user_id' => $this->senderUserId,
            'senderUserId' => $this->senderUserId,
            'signal_type' => $this->signalType,
            'signalType' => $this->signalType,
            'type' => $this->signalType,
            'payload' => array_merge(['id' => $id, 'signal_id' => $id], $this->payload),
            'timestamp' => now()->toISOString(),
        ];
    }
}
