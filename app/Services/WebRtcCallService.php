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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebRtcCallService
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Check if a live real-time WebSocket connection/driver is configured and supported.
     */
    public function isRealtimeSupported(): bool
    {
        $driver = config('broadcasting.default');

        if (in_array($driver, ['null', 'log'], true) || empty($driver)) {
            return false;
        }

        if ($driver === 'reverb') {
            return ! empty(config('broadcasting.connections.reverb.key'))
                && ! empty(config('broadcasting.connections.reverb.secret'))
                && ! empty(config('broadcasting.connections.reverb.app_id'));
        }

        if ($driver === 'pusher') {
            return ! empty(config('broadcasting.connections.pusher.key'))
                && ! empty(config('broadcasting.connections.pusher.secret'))
                && ! empty(config('broadcasting.connections.pusher.app_id'));
        }

        return true;
    }

    /**
     * Retrieve realtime system status and diagnostics for WebSockets.
     *
     * @return array<string, mixed>
     */
    public function getRealtimeStatus(): array
    {
        $driver = (string) config('broadcasting.default');
        $isSupported = $this->isRealtimeSupported();
        $requireWebsocket = (bool) Setting::get('chat.require_websocket_for_calls', true);

        $host = match ($driver) {
            'reverb' => (string) config('broadcasting.connections.reverb.options.host', '127.0.0.1'),
            'pusher' => (string) config('broadcasting.connections.pusher.options.host', 'pusher.com'),
            default => 'localhost',
        };

        $port = match ($driver) {
            'reverb' => (int) config('broadcasting.connections.reverb.options.port', 8080),
            'pusher' => (int) config('broadcasting.connections.pusher.options.port', 443),
            default => 80,
        };

        $scheme = match ($driver) {
            'reverb' => (string) config('broadcasting.connections.reverb.options.scheme', 'http'),
            'pusher' => (string) config('broadcasting.connections.pusher.options.scheme', 'https'),
            default => 'http',
        };

        $cluster = match ($driver) {
            'pusher' => (string) config('broadcasting.connections.pusher.options.cluster', 'mt1'),
            default => null,
        };

        return [
            'supported' => $isSupported,
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'cluster' => $cluster,
            'require_websocket' => $requireWebsocket,
            'reason' => ! $isSupported ? __('Broadcasting is currently set to :driver or credentials are missing.', ['driver' => $driver ?: 'none']) : null,
        ];
    }

    /**
     * Initiate a new WebRTC voice or video call.
     */
    public function initiateCall(
        User $caller,
        User $receiver,
        string $type = ChatCall::TYPE_VIDEO,
        ?int $conversationId = null
    ): ChatCall {
        $type = in_array(strtolower($type), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;

        $callEnabled = (bool) Setting::get('chat.enabled', true);
        $typeEnabled = $type === ChatCall::TYPE_AUDIO
            ? (bool) Setting::get('chat.voice_call_enabled', true)
            : (bool) Setting::get('chat.video_call_enabled', true);

        if (! $callEnabled || ! $typeEnabled) {
            throw ValidationException::withMessages([
                'call' => __('WebRTC calling is currently disabled in system settings.'),
            ]);
        }

        $requireWebsocket = (bool) Setting::get('chat.require_websocket_for_calls', true);
        if ($requireWebsocket && ! $this->isRealtimeSupported()) {
            throw ValidationException::withMessages([
                'call' => __('Real-time voice and video calling requires an active WebSocket connection. Please configure Laravel Reverb or Pusher in your system environment.'),
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
                    'call_uuid' => $call->uuid,
                    'caller_id' => $caller->id,
                    'caller_name' => $caller->name,
                    'caller_avatar' => $caller->avatarUrl(),
                    'call_type' => $type,
                    'type' => $type,
                    'conversation_id' => $conversationId,
                    'is_group' => false,
                ],
                recipientUserId: $receiver->id
            );

            // In-app alert notification
            try {
                $this->notificationService->notifyWebRtcCall(
                    recipient: $receiver,
                    caller: $caller,
                    callUuid: $call->uuid,
                    callType: $type,
                    status: 'ringing'
                );
            } catch (Throwable $e) {
                Log::debug('Notification dispatch suppressed during call initiation: '.$e->getMessage());
            }

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
        $type = in_array(strtolower($type), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;

        $callEnabled = (bool) Setting::get('chat.enabled', true);
        $typeEnabled = $type === ChatCall::TYPE_AUDIO
            ? (bool) Setting::get('chat.voice_call_enabled', true)
            : (bool) Setting::get('chat.video_call_enabled', true);

        if (! $callEnabled || ! $typeEnabled) {
            throw ValidationException::withMessages([
                'call' => __('WebRTC calling is currently disabled in system settings.'),
            ]);
        }

        $requireWebsocket = (bool) Setting::get('chat.require_websocket_for_calls', true);
        if ($requireWebsocket && ! $this->isRealtimeSupported()) {
            throw ValidationException::withMessages([
                'call' => __('Real-time voice and video calling requires an active WebSocket connection. Please configure Laravel Reverb or Pusher in your system environment.'),
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
                        'call_uuid' => $call->uuid,
                        'caller_id' => $caller->id,
                        'caller_name' => $caller->name,
                        'caller_avatar' => $caller->avatarUrl(),
                        'call_type' => $type,
                        'type' => $type,
                        'is_group' => true,
                        'group_title' => $conversation->title,
                        'conversation_id' => $conversation->id,
                    ],
                    recipientUserId: $part->user_id
                );

                try {
                    $this->notificationService->notifyWebRtcCall(
                        recipient: $part->user,
                        caller: $caller,
                        callUuid: $call->uuid,
                        callType: $type,
                        status: 'ringing'
                    );
                } catch (Throwable $e) {
                    Log::debug('Group call notification suppressed: '.$e->getMessage());
                }
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
     * Transmit WebRTC signaling packet (SDP Offer, Answer, ICE Candidates, Mute state, Peer Presence, Mode Switch).
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

        $isGroup = $call->isGroupCall();

        if ($recipientUserId !== null && $recipientUserId > 0) {
            $targetUserId = $recipientUserId;
        } elseif (! $isGroup) {
            $targetUserId = (int) $call->caller_id === (int) $sender->id ? (int) $call->receiver_id : (int) $call->caller_id;
        } else {
            $targetUserId = 0; // Broadcast to entire room
        }

        // Generate unique signal ID for client-side and polling deduplication
        $signalId = $payload['id'] ?? ($payload['signal_id'] ?? ('sig_'.(string) Str::uuid()));
        $payloadWithId = array_merge($payload, ['id' => $signalId, 'signal_id' => $signalId]);

        // Store signal in DB for internal polling fallback mode
        $signalEntry = [
            'id' => $signalId,
            'type' => $signalType,
            'from_user_id' => $sender->id,
            'to_user_id' => $targetUserId,
            'payload' => $payloadWithId,
            'created_at' => now()->toISOString(),
        ];

        $signalData = $call->signal_data ?? ['signals' => []];
        $signals = $signalData['signals'] ?? [];
        $signals[] = $signalEntry;

        // Keep last 80 signals in history to prevent overflow
        if (count($signals) > 80) {
            $signals = array_slice($signals, -80);
        }

        $signalData['signals'] = $signals;
        $call->update(['signal_data' => $signalData]);

        // Broadcast realtime WebRTC signal event
        try {
            event(new WebRtcCallSignalEvent(
                callUuid: $call->uuid,
                recipientUserId: $targetUserId,
                senderUserId: $sender->id,
                signalType: $signalType,
                payload: $payloadWithId,
                signalId: $signalId
            ));
        } catch (Throwable $e) {
            Log::debug('Realtime WebRtcCallSignalEvent broadcast fallback: '.$e->getMessage());
        }

        return true;
    }

    /**
     * Accept incoming WebRTC call (Direct or Group).
     */
    public function acceptCall(string $callUuid, User $user): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();

        return DB::transaction(function () use ($call, $user) {
            $call->update([
                'status' => ChatCall::STATUS_CONNECTED,
                'started_at' => $call->started_at ?? now(),
            ]);

            $participant = $call->participants()->where('user_id', $user->id)->first();
            if ($participant) {
                $participant->update([
                    'status' => ChatCallParticipant::STATUS_JOINED,
                    'joined_at' => now(),
                    'left_at' => null,
                ]);
            } else {
                ChatCallParticipant::create([
                    'call_id' => $call->id,
                    'user_id' => $user->id,
                    'status' => ChatCallParticipant::STATUS_JOINED,
                    'joined_at' => now(),
                ]);
            }

            // Broadcast signal to peers
            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'participant_joined',
                payload: [
                    'user_id' => $user->id,
                    'userId' => $user->id,
                    'user_name' => $user->name,
                    'userName' => $user->name,
                    'user_avatar' => $user->avatarUrl(),
                    'userAvatar' => $user->avatarUrl(),
                    'accepted_by' => $user->id,
                    'call_uuid' => $call->uuid,
                ]
            );

            // Legacy 1-on-1 compatibility
            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'call_accepted',
                payload: [
                    'accepted_by' => $user->id,
                    'call_uuid' => $call->uuid,
                ]
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
     * Join an ongoing group voice or video call.
     */
    public function joinGroupCall(string $callUuid, User $user): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)
            ->whereIn('status', [ChatCall::STATUS_RINGING, ChatCall::STATUS_CONNECTED])
            ->firstOrFail();

        // Verify user is a member of the conversation
        if ($call->conversation_id) {
            $conversation = ChatConversation::find($call->conversation_id);
            if ($conversation && ! $conversation->participants()->where('user_id', $user->id)->whereNull('left_at')->exists() && ! $user->hasRole('Super Administrator')) {
                throw ValidationException::withMessages([
                    'call' => __('You are not a participant of this conversation.'),
                ]);
            }
        }

        return DB::transaction(function () use ($call, $user) {
            $call->update([
                'status' => ChatCall::STATUS_CONNECTED,
                'started_at' => $call->started_at ?? now(),
            ]);

            $participant = $call->participants()->where('user_id', $user->id)->first();
            if ($participant) {
                $participant->update([
                    'status' => ChatCallParticipant::STATUS_JOINED,
                    'joined_at' => now(),
                    'left_at' => null,
                ]);
            } else {
                ChatCallParticipant::create([
                    'call_id' => $call->id,
                    'user_id' => $user->id,
                    'status' => ChatCallParticipant::STATUS_JOINED,
                    'joined_at' => now(),
                ]);
            }

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'participant_joined',
                payload: [
                    'user_id' => $user->id,
                    'userId' => $user->id,
                    'user_name' => $user->name,
                    'userName' => $user->name,
                    'user_avatar' => $user->avatarUrl(),
                    'userAvatar' => $user->avatarUrl(),
                    'call_uuid' => $call->uuid,
                ]
            );

            AuditLogService::log(
                event: 'webrtc_group_call_joined',
                description: "Joined group call #{$call->uuid}",
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
            $isGroup = $call->isGroupCall();

            $call->participants()
                ->where('user_id', $user->id)
                ->update([
                    'status' => ChatCallParticipant::STATUS_DECLINED,
                    'left_at' => now(),
                ]);

            if (! $isGroup) {
                $call->update([
                    'status' => ChatCall::STATUS_REJECTED,
                    'ended_at' => now(),
                ]);
            } else {
                // If all invited participants declined, terminate the call
                $activeCount = $call->participants()
                    ->whereIn('status', [ChatCallParticipant::STATUS_JOINED, ChatCallParticipant::STATUS_RINGING])
                    ->count();

                if ($activeCount <= 1) {
                    $call->update([
                        'status' => ChatCall::STATUS_ENDED,
                        'ended_at' => now(),
                    ]);
                }
            }

            $targetUserId = (int) $call->caller_id === (int) $user->id ? (int) $call->receiver_id : (int) $call->caller_id;
            $payloadData = [
                'reason' => $reason,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'call_uuid' => $call->uuid,
                'is_group' => $isGroup,
            ];

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'call_rejected',
                payload: $payloadData,
                recipientUserId: $isGroup ? 0 : $targetUserId
            );

            // Also broadcast directly to caller and active participants' user channels
            if ($targetUserId > 0) {
                $this->sendSignal(
                    callUuid: $call->uuid,
                    sender: $user,
                    signalType: 'call_rejected',
                    payload: $payloadData,
                    recipientUserId: $targetUserId
                );
            }

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
     * Request a call mode switch (Voice <-> Video).
     */
    public function requestModeSwitch(string $callUuid, User $user, string $requestedType): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();
        $type = in_array(strtolower($requestedType), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;

        $this->sendSignal(
            callUuid: $call->uuid,
            sender: $user,
            signalType: 'switch_mode_request',
            payload: [
                'requested_type' => $type,
                'requester_id' => $user->id,
                'requester_name' => $user->name,
                'call_uuid' => $call->uuid,
            ]
        );

        AuditLogService::log(
            event: 'webrtc_mode_switch_requested',
            description: "Requested mode switch to {$type} in call #{$call->uuid}",
            auditable: $call,
            newValues: ['requested_type' => $type],
            userId: $user->id
        );

        return $call;
    }

    /**
     * Respond to a call mode switch request.
     */
    public function respondModeSwitch(string $callUuid, User $user, string $requestedType, bool $accepted): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();
        $type = in_array(strtolower($requestedType), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;

        return DB::transaction(function () use ($call, $user, $type, $accepted) {
            if ($accepted) {
                $call->update(['type' => $type]);
            }

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'switch_mode_response',
                payload: [
                    'accepted' => $accepted,
                    'requested_type' => $type,
                    'responder_id' => $user->id,
                    'responder_name' => $user->name,
                    'call_uuid' => $call->uuid,
                ]
            );

            if ($accepted) {
                $this->sendSignal(
                    callUuid: $call->uuid,
                    sender: $user,
                    signalType: 'call_mode_switched',
                    payload: [
                        'call_type' => $type,
                        'callType' => $type,
                        'call_uuid' => $call->uuid,
                    ]
                );
            }

            AuditLogService::log(
                event: $accepted ? 'webrtc_mode_switched' : 'webrtc_mode_switch_declined',
                description: $accepted ? "Switched mode to {$type} in call #{$call->uuid}" : "Declined mode switch to {$type} in call #{$call->uuid}",
                auditable: $call,
                newValues: ['type' => $type, 'accepted' => $accepted],
                userId: $user->id
            );

            return $call;
        });
    }

    /**
     * Invite a new user to an ongoing call in real-time.
     */
    public function inviteUserToOngoingCall(string $callUuid, User $inviter, User $invitee): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();

        return DB::transaction(function () use ($call, $inviter, $invitee) {
            $signalData = $call->signal_data ?? [];
            $signalData['is_group'] = true;
            $call->signal_data = $signalData;
            $call->save();

            // Ensure caller and receiver records exist in ChatCallParticipant
            if ($call->caller_id) {
                ChatCallParticipant::firstOrCreate([
                    'call_id' => $call->id,
                    'user_id' => $call->caller_id,
                ], [
                    'status' => ChatCallParticipant::STATUS_JOINED,
                    'joined_at' => $call->started_at ?? now(),
                ]);
            }
            if ($call->receiver_id) {
                ChatCallParticipant::firstOrCreate([
                    'call_id' => $call->id,
                    'user_id' => $call->receiver_id,
                ], [
                    'status' => ChatCallParticipant::STATUS_JOINED,
                    'joined_at' => $call->started_at ?? now(),
                ]);
            }

            // Create or update participant record for the invited user
            ChatCallParticipant::updateOrCreate([
                'call_id' => $call->id,
                'user_id' => $invitee->id,
            ], [
                'status' => ChatCallParticipant::STATUS_RINGING,
                'joined_at' => null,
                'left_at' => null,
            ]);

            // Dispatch incoming call signal directly to the invited user
            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $inviter,
                signalType: 'incoming_call',
                payload: [
                    'call_uuid' => $call->uuid,
                    'call_type' => $call->type,
                    'type' => $call->type,
                    'is_group' => true,
                    'caller_id' => $inviter->id,
                    'caller_name' => $inviter->name,
                    'caller_avatar' => $inviter->avatarUrl(),
                    'group_title' => $call->conversation?->title ?? __('Group Call'),
                    'conversation_id' => $call->conversation_id,
                ],
                recipientUserId: $invitee->id
            );

            // Notify existing call participants that a user was invited
            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $inviter,
                signalType: 'participant_invited',
                payload: [
                    'call_uuid' => $call->uuid,
                    'invited_user_id' => $invitee->id,
                    'invited_user_name' => $invitee->name,
                    'invited_user_avatar' => $invitee->avatarUrl(),
                    'inviter_name' => $inviter->name,
                ],
                recipientUserId: 0
            );

            AuditLogService::log(
                event: 'webrtc_participant_invited',
                description: "Invited {$invitee->name} to ongoing call #{$call->uuid}",
                auditable: $call,
                userId: $inviter->id
            );

            return $call;
        });
    }

    /**
     * Leave an active group call.
     */
    public function leaveCall(string $callUuid, User $user): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();

        return DB::transaction(function () use ($call, $user) {
            $now = now();

            $call->participants()
                ->where('user_id', $user->id)
                ->update([
                    'status' => ChatCallParticipant::STATUS_LEFT,
                    'left_at' => $now,
                ]);

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'participant_left',
                payload: [
                    'user_id' => $user->id,
                    'userId' => $user->id,
                    'user_name' => $user->name,
                    'call_uuid' => $call->uuid,
                ]
            );

            // Check if any other participants remain joined
            $remainingJoined = $call->participants()
                ->where('status', ChatCallParticipant::STATUS_JOINED)
                ->whereNull('left_at')
                ->count();

            if ($remainingJoined <= 1) {
                $durationSeconds = $call->started_at ? $call->started_at->diffInSeconds($now) : 0;
                $call->update([
                    'status' => ChatCall::STATUS_ENDED,
                    'ended_at' => $now,
                    'duration_seconds' => (int) $durationSeconds,
                ]);

                $this->sendSignal(
                    callUuid: $call->uuid,
                    sender: $user,
                    signalType: 'call_ended',
                    payload: ['duration' => $durationSeconds, 'call_uuid' => $call->uuid]
                );
            }

            AuditLogService::log(
                event: 'webrtc_call_left',
                description: "Left call #{$call->uuid}",
                auditable: $call,
                userId: $user->id
            );

            return $call;
        });
    }

    /**
     * End active call for all participants and calculate duration.
     */
    public function endCall(string $callUuid, User $user): ChatCall
    {
        $call = ChatCall::where('uuid', $callUuid)->firstOrFail();

        return DB::transaction(function () use ($call, $user) {
            $now = now();
            $durationSeconds = $call->started_at ? $call->started_at->diffInSeconds($now) : 0;
            $isGroup = $call->isGroupCall();

            $call->update([
                'status' => ChatCall::STATUS_ENDED,
                'ended_at' => $now,
                'duration_seconds' => (int) $durationSeconds,
            ]);

            $call->participants()
                ->whereNull('left_at')
                ->update([
                    'status' => ChatCallParticipant::STATUS_LEFT,
                    'left_at' => $now,
                ]);

            $targetUserId = (int) $call->caller_id === (int) $user->id ? (int) $call->receiver_id : (int) $call->caller_id;

            $this->sendSignal(
                callUuid: $call->uuid,
                sender: $user,
                signalType: 'call_ended',
                payload: ['duration' => $durationSeconds, 'ended_by' => $user->id, 'call_uuid' => $call->uuid],
                recipientUserId: $isGroup ? 0 : $targetUserId
            );

            if ($isGroup) {
                $otherParticipantIds = $call->participants()->where('user_id', '!=', $user->id)->pluck('user_id')->all();
                foreach ($otherParticipantIds as $otherId) {
                    $this->sendSignal(
                        callUuid: $call->uuid,
                        sender: $user,
                        signalType: 'call_ended',
                        payload: ['duration' => $durationSeconds, 'ended_by' => $user->id, 'call_uuid' => $call->uuid],
                        recipientUserId: $otherId
                    );
                }
            }

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
     * Retrieve the active group call for a conversation if one is currently in progress.
     */
    public function getActiveGroupCall(int $conversationId): ?ChatCall
    {
        return ChatCall::where('conversation_id', $conversationId)
            ->whereIn('status', [ChatCall::STATUS_RINGING, ChatCall::STATUS_CONNECTED])
            ->where('created_at', '>=', now()->subHours(4))
            ->with(['participants.user', 'caller'])
            ->latest('id')
            ->first();
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
