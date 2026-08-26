<?php

namespace App\Services;

use App\Events\WebRtcCallSignalEvent;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\ChatConversation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebRtcCallService
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Initiate a new WebRTC voice or video call.
     */
    public function initiateCall(
        User $caller,
        User $receiver,
        string $type = ChatCall::TYPE_VIDEO,
        ?int $conversationId = null
    ): ChatCall {
        $callEnabled = (bool) Setting::get('chat.enabled', true);
        $typeEnabled = $type === ChatCall::TYPE_AUDIO
            ? (bool) Setting::get('chat.voice_call_enabled', true)
            : (bool) Setting::get('chat.video_call_enabled', true);

        if (! $callEnabled || ! $typeEnabled) {
            throw ValidationException::withMessages([
                'call' => __('WebRTC calling is currently disabled in system settings.'),
            ]);
        }

        if ($caller->id === $receiver->id) {
            throw ValidationException::withMessages([
                'call' => __('You cannot call yourself.'),
            ]);
        }

        return DB::transaction(function () use ($caller, $receiver, $type, $conversationId) {
            $call = ChatCall::create([
                'conversation_id' => $conversationId,
                'caller_id' => $caller->id,
                'receiver_id' => $receiver->id,
                'type' => $type,
                'status' => ChatCall::STATUS_RINGING,
                'signal_data' => [
                    'signals' => [],
                ],
            ]);

            // Add participants
            ChatCallParticipant::create([
                'call_id' => $call->id,
                'user_id' => $caller->id,
                'status' => ChatCallParticipant::STATUS_JOINED,
                'joined_at' => now(),
            ]);

            ChatCallParticipant::create([
                'call_id' => $call->id,
                'user_id' => $receiver->id,
                'status' => ChatCallParticipant::STATUS_RINGING,
            ]);

            // Realtime Signal Dispatch
            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $caller,
                signalType: 'incoming_call',
                payload: [
                    'caller_id' => $caller->id,
                    'caller_name' => $caller->name,
                    'caller_avatar' => $caller->avatarUrl(),
                    'call_type' => $type,
                    'conversation_id' => $conversationId,
                ],
                recipientUserId: $receiver->id
            );

            // In-app alert notification
            $this->notificationService->notifyWebRtcCall(
                recipient: $receiver,
                caller: $caller,
                callUuid: $call->uuid,
                callType: $type,
                status: 'ringing'
            );

            AuditLogService::log(
                event: 'webrtc_call_initiated',
                description: "Initiated {$type} call to {$receiver->name} ({$receiver->email})",
                auditable: $call,
                newValues: ['caller_id' => $caller->id, 'receiver_id' => $receiver->id, 'type' => $type],
                userId: $caller->id
            );

            return $call;
        });
    }

    /**
     * Initiate a multi-party WebRTC group audio or video call.
     */
    public function initiateGroupCall(
        User $caller,
        ChatConversation $conversation,
        string $type = ChatCall::TYPE_VIDEO
    ): ChatCall {
        $callEnabled = (bool) Setting::get('chat.enabled', true);
        $typeEnabled = $type === ChatCall::TYPE_AUDIO
            ? (bool) Setting::get('chat.voice_call_enabled', true)
            : (bool) Setting::get('chat.video_call_enabled', true);

        if (! $callEnabled || ! $typeEnabled) {
            throw ValidationException::withMessages([
                'call' => __('WebRTC calling is currently disabled in system settings.'),
            ]);
        }

        $participants = $conversation->participants()
            ->where('user_id', '!=', $caller->id)
            ->whereNull('left_at')
            ->with('user')
            ->get();

        if ($participants->isEmpty()) {
            throw ValidationException::withMessages([
                'call' => __('There are no other active participants in this group to call.'),
            ]);
        }

        return DB::transaction(function () use ($caller, $conversation, $participants, $type) {
            $call = ChatCall::create([
                'conversation_id' => $conversation->id,
                'caller_id' => $caller->id,
                'receiver_id' => $participants->first()->user_id,
                'type' => $type,
                'status' => ChatCall::STATUS_RINGING,
                'signal_data' => [
                    'is_group' => true,
                    'group_name' => $conversation->title,
                    'signals' => [],
                ],
            ]);

            // Add caller
            ChatCallParticipant::create([
                'call_id' => $call->id,
                'user_id' => $caller->id,
                'status' => ChatCallParticipant::STATUS_JOINED,
                'joined_at' => now(),
            ]);

            // Add all group members
            foreach ($participants as $part) {
                if (! $part->user) {
                    continue;
                }

                ChatCallParticipant::create([
                    'call_id' => $call->id,
                    'user_id' => $part->user_id,
                    'status' => ChatCallParticipant::STATUS_RINGING,
                ]);

                // Signal each group member
                $this->sendSignal(
                    callUuid: $call->uuid,
                    sender: $caller,
                    signalType: 'incoming_call',
                    payload: [
                        'caller_id' => $caller->id,
                        'caller_name' => $caller->name,
                        'caller_avatar' => $caller->avatarUrl(),
                        'call_type' => $type,
                        'is_group' => true,
                        'group_title' => $conversation->title,
                        'conversation_id' => $conversation->id,
                    ],
                    recipientUserId: $part->user_id
                );

                $this->notificationService->notifyWebRtcCall(
                    recipient: $part->user,
                    caller: $caller,
                    callUuid: $call->uuid,
                    callType: $type,
                    status: 'ringing'
                );
            }

            AuditLogService::log(
                event: 'webrtc_group_call_initiated',
                description: "Initiated {$type} group call in conversation '{$conversation->title}' (#{$conversation->id})",
                auditable: $call,
                newValues: ['caller_id' => $caller->id, 'conversation_id' => $conversation->id, 'type' => $type],
                userId: $caller->id
            );

            return $call;
        });
    }

    /**
     * Transmit WebRTC signaling packet (SDP Offer, Answer, ICE Candidates, Mute state).
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendSignal(
        string $callUuid,
        User $sender,
        string $signalType,
        array $payload = [],
        ?int $recipientUserId = null
    ): bool {
        $call = ChatCall::where('uuid', $callUuid)->first();
        if (! $call) {
            return false;
        }

        $targetUserId = $recipientUserId ?? (
            (int) $call->caller_id === (int) $sender->id ? (int) $call->receiver_id : (int) $call->caller_id
        );

        if (! $targetUserId) {
            return false;
        }

        // Store signal in DB for internal polling fallback mode
        $signalEntry = [
            'id' => uniqid('sig_'),
            'type' => $signalType,
            'from_user_id' => $sender->id,
            'to_user_id' => $targetUserId,
            'payload' => $payload,
            'created_at' => now()->toISOString(),
        ];

        $signalData = $call->signal_data ?? ['signals' => []];
        $signals = $signalData['signals'] ?? [];
        $signals[] = $signalEntry;

        // Keep last 40 signals in history to prevent overflow
        if (count($signals) > 40) {
            $signals = array_slice($signals, -40);
        }

        $call->update(['signal_data' => ['signals' => $signals]]);

        // Broadcast realtime WebRTC signal event
        try {
            event(new WebRtcCallSignalEvent(
                callUuid: $call->uuid,
                recipientUserId: $targetUserId,
                senderUserId: $sender->id,
                signalType: $signalType,
                payload: $payload
            ));
        } catch (Throwable $e) {
            Log::debug('Realtime WebRtcCallSignalEvent graceful fallback: '.$e->getMessage());
        }

        return true;
    }

    /**
     * Accept incoming WebRTC call.
     */
    public function acceptCall(string $callUuid, User $user): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();

        return DB::transaction(function () use ($call, $user) {
            $call->update([
                'status' => ChatCall::STATUS_CONNECTED,
                'started_at' => now(),
            ]);

            $call->participants()
                ->where('user_id', $user->id)
                ->update([
                    'status' => ChatCallParticipant::STATUS_JOINED,
                    'joined_at' => now(),
                ]);

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'call_accepted',
                payload: ['accepted_by' => $user->id]
            );

            AuditLogService::log(
                event: 'webrtc_call_accepted',
                description: "Accepted call #{$call->uuid}",
                auditable: $call,
                userId: $user->id
            );

            return $call;
        });
    }

    /**
     * Reject incoming call.
     */
    public function rejectCall(string $callUuid, User $user, ?string $reason = 'declined'): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();

        return DB::transaction(function () use ($call, $user, $reason) {
            $call->update([
                'status' => ChatCall::STATUS_REJECTED,
                'ended_at' => now(),
            ]);

            $call->participants()
                ->where('user_id', $user->id)
                ->update([
                    'status' => ChatCallParticipant::STATUS_DECLINED,
                    'left_at' => now(),
                ]);

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'call_rejected',
                payload: ['reason' => $reason]
            );

            AuditLogService::log(
                event: 'webrtc_call_rejected',
                description: "Rejected call #{$call->uuid}",
                auditable: $call,
                userId: $user->id
            );

            return $call;
        });
    }

    /**
     * End active call and calculate duration.
     */
    public function endCall(string $callUuid, User $user): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();

        return DB::transaction(function () use ($call, $user) {
            $now = now();
            $durationSeconds = $call->started_at ? $call->started_at->diffInSeconds($now) : 0;

            $call->update([
                'status' => ChatCall::STATUS_ENDED,
                'ended_at' => $now,
                'duration_seconds' => (int) $durationSeconds,
            ]);

            $call->participants()
                ->whereNull('left_at')
                ->update(['left_at' => $now]);

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'call_ended',
                payload: ['duration' => $durationSeconds]
            );

            AuditLogService::log(
                event: 'webrtc_call_ended',
                description: "Ended call #{$call->uuid} (Duration: {$call->formattedDuration()})",
                auditable: $call,
                newValues: ['duration_seconds' => $durationSeconds],
                userId: $user->id
            );

            return $call;
        });
    }

    /**
     * Retrieve ICE Server configuration (STUN / TURN) for browser WebRTC client.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIceServers(): array
    {
        $stunServer = (string) Setting::get('chat.webrtc_stun_server', 'stun:stun.l.google.com:19302');
        $turnServer = Setting::get('chat.webrtc_turn_server');
        $turnUsername = Setting::get('chat.webrtc_turn_username');
        $turnCredential = Setting::get('chat.webrtc_turn_credential');

        $servers = [
            ['urls' => $stunServer ?: 'stun:stun.l.google.com:19302'],
        ];

        if (! empty($turnServer)) {
            $turnConfig = ['urls' => $turnServer];
            if (! empty($turnUsername)) {
                $turnConfig['username'] = $turnUsername;
            }
            if (! empty($turnCredential)) {
                $turnConfig['credential'] = $turnCredential;
            }
            $servers[] = $turnConfig;
        }

        return $servers;
    }
}
