<?php

use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\WebRtcCallService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?int $activeCallId = null;
    public string $activeCallUuid = '';
    public string $callType = 'video';
    public string $callStatus = 'idle'; // idle, incoming, outgoing, connected, ended
    public bool $isGroup = false;
    public ?int $peerUserId = null;
    public string $peerName = '';
    public ?string $peerAvatar = null;
    public array $iceServers = [];
    public int $currentUserId = 0;
    public array $participants = [];

    public bool $showAddParticipantModal = false;
    public string $inviteSearchQuery = '';

    public bool $showSwitchModeConfirmModal = false;
    public string $requestedSwitchType = '';
    public string $switchRequesterName = '';

    // Polling mode signals tracker
    public string $signalingDriver = 'reverb';
    public array $processedSignalIds = [];

    public function mount(): void
    {
        $this->currentUserId = (int) (auth()->id() ?? 0);
        $this->signalingDriver = (string) Setting::get('chat.webrtc_signaling_driver', 'reverb');
        /** @var WebRtcCallService $callService */
        $callService = app(WebRtcCallService::class);
        $this->iceServers = $callService->getIceServers();

        $this->checkForIncomingCalls();
    }

    #[Computed]
    public function availableUsersToInvite()
    {
        $currentUser = auth()->user();
        if (! $currentUser) {
            return collect();
        }

        $activeUserIds = [];
        if ($this->activeCallUuid) {
            $call = ChatCall::where('uuid', $this->activeCallUuid)->with('participants')->first();
            if ($call) {
                $activeUserIds = $call->participants()
                    ->whereIn('status', [ChatCallParticipant::STATUS_JOINED, ChatCallParticipant::STATUS_RINGING])
                    ->pluck('user_id')
                    ->all();
                if ($call->caller_id) {
                    $activeUserIds[] = (int) $call->caller_id;
                }
                if ($call->receiver_id) {
                    $activeUserIds[] = (int) $call->receiver_id;
                }
            }
        }
        $activeUserIds[] = (int) $currentUser->id;

        $query = User::whereNotIn('id', array_unique($activeUserIds))
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            });

        if (! empty($this->inviteSearchQuery)) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->inviteSearchQuery . '%')
                    ->orWhere('email', 'like', '%' . $this->inviteSearchQuery . '%');
            });
        }

        return $query->limit(8)->get();
    }

    public function openAddParticipantModal(): void
    {
        $this->showAddParticipantModal = true;
        $this->inviteSearchQuery = '';
    }

    public function closeAddParticipantModal(): void
    {
        $this->showAddParticipantModal = false;
        $this->inviteSearchQuery = '';
    }

    public function inviteUser(int $userId): void
    {
        $currentUser = auth()->user();
        $targetUser = User::find($userId);
        if (! $currentUser || ! $targetUser || ! $this->activeCallUuid) {
            return;
        }

        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $callService->inviteUserToOngoingCall($this->activeCallUuid, $currentUser, $targetUser);

            $this->isGroup = true;
            \App\Support\Toast::dispatch($this, 'success', __(':name has been invited to join this call.', ['name' => $targetUser->name]));
            $this->closeAddParticipantModal();
        } catch (\Throwable $e) {
            \App\Support\Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function requestSwitchCallMode(string $newType): void
    {
        $type = in_array(strtolower($newType), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;
        $currentUser = auth()->user();
        if (! $this->activeCallUuid || ! $currentUser) {
            return;
        }

        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $callService->requestModeSwitch($this->activeCallUuid, $currentUser, $type);

            \App\Support\Toast::dispatch($this, 'info', __('Request sent to switch to :type call.', ['type' => $type === ChatCall::TYPE_AUDIO ? __('Voice') : __('Video')]));
        } catch (\Throwable $e) {
            \App\Support\Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function acceptSwitchMode(): void
    {
        $newType = $this->requestedSwitchType ?: ChatCall::TYPE_VIDEO;
        $this->showSwitchModeConfirmModal = false;
        $this->callType = $newType;
        $currentUser = auth()->user();

        if ($this->activeCallUuid && $currentUser) {
            try {
                /** @var WebRtcCallService $callService */
                $callService = app(WebRtcCallService::class);
                $callService->respondModeSwitch($this->activeCallUuid, $currentUser, $newType, true);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        $this->dispatch('webrtc-call-type-switched', ['callType' => $newType]);
        \App\Support\Toast::dispatch($this, 'success', __('Switched to :type call.', ['type' => $newType === ChatCall::TYPE_AUDIO ? __('Voice') : __('Video')]));
    }

    public function declineSwitchMode(): void
    {
        $this->showSwitchModeConfirmModal = false;
        $currentUser = auth()->user();

        if ($this->activeCallUuid && $currentUser) {
            try {
                /** @var WebRtcCallService $callService */
                $callService = app(WebRtcCallService::class);
                $callService->respondModeSwitch($this->activeCallUuid, $currentUser, $this->requestedSwitchType ?: ChatCall::TYPE_VIDEO, false);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        \App\Support\Toast::dispatch($this, 'info', __('You declined the switch request.'));
    }

    public function checkForIncomingCalls(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $this->currentUserId = $user->id;

        // If currently idle, check for any incoming ringing calls (direct or group)
        if ($this->callStatus === 'idle') {
            // Check direct incoming
            $incoming = ChatCall::where('receiver_id', $user->id)
                ->where('status', ChatCall::STATUS_RINGING)
                ->where('created_at', '>=', now()->subSeconds(45))
                ->latest()
                ->first();

            // Check group incoming
            if (! $incoming) {
                $incomingPart = ChatCallParticipant::where('user_id', $user->id)
                    ->where('status', ChatCallParticipant::STATUS_RINGING)
                    ->whereHas('call', fn ($q) => $q->whereIn('status', [ChatCall::STATUS_RINGING, ChatCall::STATUS_CONNECTED])->where('created_at', '>=', now()->subSeconds(45)))
                    ->with('call.caller', 'call.conversation')
                    ->latest('id')
                    ->first();

                if ($incomingPart && $incomingPart->call) {
                    $incoming = $incomingPart->call;
                }
            }

            if ($incoming) {
                $caller = $incoming->caller;
                $this->activeCallId = $incoming->id;
                $this->activeCallUuid = $incoming->uuid;
                $this->callType = $incoming->type;
                $this->isGroup = $incoming->isGroupCall();
                $this->callStatus = 'incoming';
                $this->peerUserId = $caller?->id;
                $this->peerName = $this->isGroup ? ($incoming->conversation?->title ?? __('Group Call')) : ($caller?->name ?? __('Unknown Caller'));
                $this->peerAvatar = $this->isGroup ? ($incoming->conversation?->displayAvatarFor($user) ?? null) : $caller?->avatarUrl();
                $this->processedSignalIds = [];

                $this->dispatch('incoming-call-received', [
                    'callUuid' => $incoming->uuid,
                    'callType' => $incoming->type,
                    'isGroup' => $this->isGroup,
                    'callerName' => $this->peerName,
                    'callerAvatar' => $this->peerAvatar,
                    'peerUserId' => $this->peerUserId,
                ]);
            }
        } elseif ($this->activeCallUuid && in_array($this->callStatus, ['incoming', 'outgoing', 'connected'])) {
            // Check if call was ended or rejected by remote peer
            $call = ChatCall::where('uuid', $this->activeCallUuid)->first();
            if (! $call || in_array($call->status, [ChatCall::STATUS_REJECTED, ChatCall::STATUS_MISSED, ChatCall::STATUS_ENDED, ChatCall::STATUS_FAILED])) {
                $this->resetCallState();
                $this->dispatch('call-terminated-remotely');
                return;
            }

            // Sync WebRTC signals for the active call
            $this->syncSignalsForCall($call, $user);
        }
    }

    protected function syncSignalsForCall(ChatCall $call, User $user): void
    {
        $signalData = $call->signal_data ?? [];
        $signals = $signalData['signals'] ?? [];

        foreach ($signals as $sig) {
            $sigId = $sig['id'] ?? null;
            if (! $sigId || in_array($sigId, $this->processedSignalIds, true)) {
                continue;
            }

            $targetUserId = (int) ($sig['to_user_id'] ?? 0);
            $fromUserId = (int) ($sig['from_user_id'] ?? 0);

            if ($fromUserId === (int) $user->id) {
                continue;
            }

            if ($targetUserId !== 0 && $targetUserId !== (int) $user->id) {
                continue;
            }

            $this->processedSignalIds[] = $sigId;
            $signalType = $sig['type'] ?? '';
            $payload = $sig['payload'] ?? [];

            if ($signalType === 'call_accepted' && $this->callStatus === 'outgoing') {
                $this->callStatus = 'connected';
                $this->dispatch('webrtc-call-connected', [
                    'callUuid' => $call->uuid,
                ]);
            } elseif ($signalType === 'call_rejected') {
                $declinedUserName = $payload['user_name'] ?? ($payload['userName'] ?? __('Participant'));
                if (! $this->isGroup) {
                    $this->resetCallState();
                    $this->dispatch('call-terminated-remotely', [
                        'message' => __(':name has declined the call.', ['name' => $declinedUserName]),
                    ]);
                    \App\Support\Toast::dispatch($this, 'info', __(':name has declined the call.', ['name' => $declinedUserName]));
                    return;
                } else {
                    \App\Support\Toast::dispatch($this, 'info', __(':name has declined the group call.', ['name' => $declinedUserName]));
                }
            } elseif ($signalType === 'call_ended') {
                $this->resetCallState();
                $this->dispatch('call-terminated-remotely');
                return;
            } elseif ($signalType === 'switch_mode_request') {
                $this->requestedSwitchType = $payload['requested_type'] ?? 'video';
                $this->switchRequesterName = $payload['requester_name'] ?? __('Participant');
                $this->showSwitchModeConfirmModal = true;
            } elseif ($signalType === 'switch_mode_response') {
                if (! empty($payload['accepted'])) {
                    $newType = $payload['requested_type'] ?? 'video';
                    $this->callType = $newType;
                    $this->dispatch('webrtc-call-type-switched', ['callType' => $newType]);
                    \App\Support\Toast::dispatch($this, 'success', __(':name accepted the request. Switched to :type call.', [
                        'name' => $payload['responder_name'] ?? __('Participant'),
                        'type' => $newType === 'audio' ? __('Voice') : __('Video'),
                    ]));
                } else {
                    \App\Support\Toast::dispatch($this, 'warning', __(':name declined the request to switch call mode.', [
                        'name' => $payload['responder_name'] ?? __('Participant'),
                    ]));
                }
            } elseif ($signalType === 'participant_invited') {
                $invitedName = $payload['invited_user_name'] ?? __('A user');
                \App\Support\Toast::dispatch($this, 'info', __(':name was invited to this call.', ['name' => $invitedName]));
            }

            $this->dispatch('webrtc-signal-received', [
                'callUuid' => $call->uuid,
                'signalType' => $signalType,
                'payload' => $payload,
                'fromUserId' => $sig['from_user_id'] ?? null,
            ]);
        }
    }

    #[On('echo-private:user.{currentUserId},.WebRtcCallSignal')]
    #[On('echo-private:user.{currentUserId},WebRtcCallSignal')]
    public function onUserWebRtcSignal(array $event = []): void
    {
        $this->handleBroadcastedSignal($event);
    }

    public function handleIncomingBroadcastedCall(string $callUuid, string $callType, ?int $callerId = null, string $callerName = '', ?string $callerAvatar = null, bool $isGroup = false): void
    {
        $this->activeCallUuid = $callUuid;
        $this->callType = $callType;
        $this->isGroup = $isGroup;
        $this->callStatus = 'incoming';
        $this->peerUserId = $callerId;
        $this->peerName = $callerName ?: ($isGroup ? __('Group Call') : __('Unknown Caller'));
        $this->peerAvatar = $callerAvatar;
    }

    public function handleBroadcastedSignal(array $event): void
    {
        $signalType = $event['signalType'] ?? $event['signal_type'] ?? $event['type'] ?? '';
        $payload = $event['payload'] ?? [];
        $callUuid = $event['callUuid'] ?? $event['call_uuid'] ?? ($payload['call_uuid'] ?? ($payload['callUuid'] ?? null));
        $fromUserId = $event['senderUserId'] ?? $event['sender_user_id'] ?? ($event['fromUserId'] ?? ($event['from_user_id'] ?? null));

        if ($fromUserId && (int) $fromUserId === (int) auth()->id()) {
            return;
        }

        if ($signalType === 'incoming_call' && ($this->callStatus === 'idle' || empty($this->callStatus))) {
            $this->activeCallUuid = (string) $callUuid;
            $this->callType = $payload['call_type'] ?? ($payload['callType'] ?? 'video');
            $this->isGroup = ! empty($payload['is_group']) || ! empty($payload['isGroup']);
            $this->callStatus = 'incoming';
            $this->peerUserId = $fromUserId ? (int) $fromUserId : null;
            $this->peerName = $payload['caller_name'] ?? ($payload['callerName'] ?? ($this->isGroup ? ($payload['group_title'] ?? __('Group Call')) : __('Unknown Caller')));
            $this->peerAvatar = $payload['caller_avatar'] ?? ($payload['callerAvatar'] ?? null);

            $this->dispatch('incoming-call-received', [
                'callUuid' => $callUuid,
                'callType' => $this->callType,
                'isGroup' => $this->isGroup,
                'callerName' => $this->peerName,
                'callerAvatar' => $this->peerAvatar,
                'peerUserId' => $this->peerUserId,
            ]);

            return;
        }

        if ($this->activeCallUuid && $callUuid === $this->activeCallUuid) {
            if ($signalType === 'call_accepted' && $this->callStatus === 'outgoing') {
                $this->callStatus = 'connected';
                $this->dispatch('webrtc-call-connected', [
                    'callUuid' => $callUuid,
                ]);
            } elseif ($signalType === 'call_rejected') {
                $declinedUserName = $payload['user_name'] ?? ($payload['userName'] ?? __('Participant'));
                if (! $this->isGroup) {
                    $this->resetCallState();
                    $this->dispatch('call-terminated-remotely', [
                        'message' => __(':name has declined the call.', ['name' => $declinedUserName]),
                    ]);
                    \App\Support\Toast::dispatch($this, 'info', __(':name has declined the call.', ['name' => $declinedUserName]));

                    return;
                } else {
                    \App\Support\Toast::dispatch($this, 'info', __(':name has declined the group call.', ['name' => $declinedUserName]));
                }
            } elseif ($signalType === 'call_ended') {
                $this->resetCallState();
                $this->dispatch('call-terminated-remotely');

                return;
            } elseif ($signalType === 'switch_mode_request') {
                $this->requestedSwitchType = $payload['requested_type'] ?? 'video';
                $this->switchRequesterName = $payload['requester_name'] ?? __('Participant');
                $this->showSwitchModeConfirmModal = true;
            } elseif ($signalType === 'switch_mode_response') {
                if (! empty($payload['accepted'])) {
                    $newType = $payload['requested_type'] ?? 'video';
                    $this->callType = $newType;
                    $this->dispatch('webrtc-call-type-switched', ['callType' => $newType]);
                    \App\Support\Toast::dispatch($this, 'success', __(':name accepted the request. Switched to :type call.', [
                        'name' => $payload['responder_name'] ?? __('Participant'),
                        'type' => $newType === 'audio' ? __('Voice') : __('Video'),
                    ]));
                } else {
                    \App\Support\Toast::dispatch($this, 'warning', __(':name declined the request to switch call mode.', [
                        'name' => $payload['responder_name'] ?? __('Participant'),
                    ]));
                }
            } elseif ($signalType === 'participant_invited') {
                $invitedName = $payload['invited_user_name'] ?? __('A user');
                \App\Support\Toast::dispatch($this, 'info', __(':name was invited to this call.', ['name' => $invitedName]));
            }

            $this->dispatch('webrtc-signal-received', [
                'callUuid' => $callUuid,
                'signalType' => $signalType,
                'payload' => $payload,
                'fromUserId' => $fromUserId,
            ]);
        }
    }

    #[On('start-call')]
    public function startCall(int $receiverId, string $type = 'video', ?int $conversationId = null, bool $startMuted = false, bool $startVideoOff = false): void
    {
        $type = in_array(strtolower($type), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;
        $currentUser = auth()->user();
        $receiver = User::find($receiverId);

        if (! $receiver || ! $currentUser) {
            return;
        }

        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $call = $callService->initiateCall(
                caller: $currentUser,
                receiver: $receiver,
                type: $type,
                conversationId: $conversationId
            );

            $this->activeCallId = $call->id;
            $this->activeCallUuid = $call->uuid;
            $this->callType = $type;
            $this->isGroup = false;
            $this->callStatus = 'outgoing';
            $this->peerUserId = $receiver->id;
            $this->peerName = $receiver->name;
            $this->peerAvatar = $receiver->avatarUrl();
            $this->processedSignalIds = [];

            $this->dispatch('webrtc-call-started', [
                'callUuid' => $call->uuid,
                'callType' => $type,
                'isGroup' => false,
                'peerUserId' => $receiver->id,
                'peerName' => $receiver->name,
                'peerAvatar' => $receiver->avatarUrl(),
                'iceServers' => $this->iceServers,
                'startMuted' => $startMuted,
                'startVideoOff' => $type === 'audio' ? true : $startVideoOff,
            ]);
        } catch (\Throwable $e) {
            \App\Support\Toast::dispatch($this, 'error', $e->getMessage());
            $this->resetCallState();
        }
    }

    #[On('start-group-call')]
    public function startGroupCall(int $conversationId, string $type = 'video', bool $startMuted = false, bool $startVideoOff = false): void
    {
        $type = in_array(strtolower($type), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;
        $currentUser = auth()->user();
        $conv = \App\Models\ChatConversation::find($conversationId);
        if (! $currentUser || ! $conv) {
            return;
        }

        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $call = $callService->initiateGroupCall(
                caller: $currentUser,
                conversation: $conv,
                type: $type
            );

            $this->activeCallId = $call->id;
            $this->activeCallUuid = $call->uuid;
            $this->callType = $type;
            $this->isGroup = true;
            $this->callStatus = 'outgoing';
            $this->peerName = $conv->title ?: __('Group Call');
            $this->peerAvatar = $conv->displayAvatarFor($currentUser);
            $this->processedSignalIds = [];

            $this->dispatch('webrtc-call-started', [
                'callUuid' => $call->uuid,
                'callType' => $type,
                'isGroup' => true,
                'peerName' => $this->peerName,
                'peerAvatar' => $this->peerAvatar,
                'iceServers' => $this->iceServers,
                'startMuted' => $startMuted,
                'startVideoOff' => $type === 'audio' ? true : $startVideoOff,
            ]);
        } catch (\Throwable $e) {
            \App\Support\Toast::dispatch($this, 'error', $e->getMessage());
            $this->resetCallState();
        }
    }

    #[On('join-group-call')]
    public function joinGroupCall(string $callUuid, string $type = 'video', bool $startMuted = false, bool $startVideoOff = false): void
    {
        $currentUser = auth()->user();
        if (! $currentUser) {
            return;
        }

        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $call = $callService->joinGroupCall($callUuid, $currentUser);

            $this->activeCallId = $call->id;
            $this->activeCallUuid = $call->uuid;
            $this->callType = $call->type;
            $this->isGroup = true;
            $this->callStatus = 'connected';
            $this->peerName = $call->conversation?->title ?: __('Group Call');
            $this->peerAvatar = $call->conversation?->displayAvatarFor($currentUser);
            $this->processedSignalIds = [];

            $this->dispatch('webrtc-call-accepted', [
                'callUuid' => $call->uuid,
                'callType' => $call->type,
                'isGroup' => true,
                'peerName' => $this->peerName,
                'peerAvatar' => $this->peerAvatar,
                'iceServers' => $this->iceServers,
                'startMuted' => $startMuted,
                'startVideoOff' => $call->type === 'audio' ? true : $startVideoOff,
            ]);
        } catch (\Throwable $e) {
            \App\Support\Toast::dispatch($this, 'error', $e->getMessage());
            $this->resetCallState();
        }
    }

    public function acceptCall(): void
    {
        if (! $this->activeCallUuid) {
            return;
        }

        $currentUser = auth()->user();
        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $call = $callService->acceptCall($this->activeCallUuid, $currentUser);

            $this->callType = $call->type;
            $this->isGroup = $call->isGroupCall();
            $this->callStatus = 'connected';
            $this->peerUserId = (int) $call->caller_id === (int) $currentUser->id ? (int) $call->receiver_id : (int) $call->caller_id;
            $this->dispatch('webrtc-call-accepted', [
                'callUuid' => $call->uuid,
                'callType' => $call->type,
                'isGroup' => $this->isGroup,
                'peerUserId' => $this->peerUserId,
                'peerName' => $this->peerName,
                'peerAvatar' => $this->peerAvatar,
                'iceServers' => $this->iceServers,
            ]);
        } catch (\Throwable $e) {
            $this->resetCallState();
        }
    }

    public function declineCall(): void
    {
        if (! $this->activeCallUuid) {
            return;
        }

        $currentUser = auth()->user();
        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $callService->rejectCall($this->activeCallUuid, $currentUser);
        } catch (\Throwable $e) {
            // Ignore
        }

        $this->resetCallState();
        $this->dispatch('webrtc-call-ended');
    }

    public function hangUpCall(): void
    {
        if (! $this->activeCallUuid) {
            return;
        }

        $currentUser = auth()->user();
        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            if ($this->isGroup) {
                $callService->leaveCall($this->activeCallUuid, $currentUser);
            } else {
                $callService->endCall($this->activeCallUuid, $currentUser);
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        $this->resetCallState();
        $this->dispatch('webrtc-call-ended');
    }

    public function endCallForEveryone(): void
    {
        if (! $this->activeCallUuid) {
            return;
        }

        $currentUser = auth()->user();
        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $callService->endCall($this->activeCallUuid, $currentUser);
        } catch (\Throwable $e) {
            // Ignore
        }

        $this->resetCallState();
        $this->dispatch('webrtc-call-ended');
    }

    public function sendSignalPayload(string $signalType, array $payload, ?int $targetUserId = null): void
    {
        if (! $this->activeCallUuid) {
            return;
        }

        $currentUser = auth()->user();
        /** @var WebRtcCallService $callService */
        $callService = app(WebRtcCallService::class);
        $callService->sendSignal(
            callUuid: $this->activeCallUuid,
            sender: $currentUser,
            signalType: $signalType,
            payload: $payload,
            recipientUserId: $targetUserId ?? $this->peerUserId
        );
    }

    #[Computed]
    public function pollInterval(): ?string
    {
        $driver = (string) Setting::get('chat.webrtc_signaling_driver', 'reverb');

        if ($driver === 'reverb' || $driver === 'broadcasting') {
            return $this->callStatus === 'idle' ? '45s' : '8s';
        }

        if ($driver === 'hybrid') {
            return $this->callStatus === 'idle' ? '15s' : '3s';
        }

        return $this->callStatus === 'idle' ? '10s' : '2s';
    }

    public function resetCallState(): void
    {
        $this->activeCallId = null;
        $this->activeCallUuid = '';
        $this->callStatus = 'idle';
        $this->isGroup = false;
        $this->peerUserId = null;
        $this->peerName = '';
        $this->peerAvatar = null;
        $this->processedSignalIds = [];
        $this->dispatch('webrtc-call-ended');
    }
};
?>

<div
    @if ($this->pollInterval) wire:poll.visible.{{ $this->pollInterval }}="checkForIncomingCalls" @endif
    x-data="chatCallOverlayAlpine({ currentUserId: {{ (int) (auth()->id() ?? 0) }} })"
>
    <!-- 1. Incoming Call Dialog Modal -->
    <div
        x-show="callStatus === 'incoming'"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 dark:bg-black/80 backdrop-blur-md"
    >
        <div class="w-full max-w-sm rounded-3xl border border-border bg-card p-6 shadow-2xl text-center space-y-5 animate-in fade-in zoom-in-95 duration-300">
            <!-- Pulsing Avatar Radar -->
            <div class="relative inline-block mx-auto">
                <div class="absolute -inset-3 rounded-full bg-primary/25 animate-ping"></div>
                <div class="absolute -inset-6 rounded-full bg-primary/10 animate-pulse"></div>
                <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-22 text-2xl border-4 border-primary shadow-xl" />
            </div>

            <div>
                <h3 class="text-lg font-bold text-foreground truncate" x-text="peerName || '{{ __('Unknown Caller') }}'">{{ $peerName }}</h3>
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-semibold mt-1.5 border border-primary/20">
                    <x-icon name="video" class="h-3.5 w-3.5 animate-bounce" x-show="callType === 'video'" />
                    <x-icon name="phone-call" class="h-3.5 w-3.5 animate-bounce" x-show="callType === 'audio'" />
                    <span x-text="isGroup ? (callType === 'audio' ? '{{ __('Incoming Group Voice Call') }}' : '{{ __('Incoming Group Video Call') }}') : (callType === 'audio' ? '{{ __('Incoming Voice Call') }}' : '{{ __('Incoming Video Call') }}')"></span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-center gap-8 pt-2">
                <!-- Decline -->
                <button
                    type="button"
                    @click="cleanupWebRtc(); $wire.declineCall()"
                    class="flex flex-col items-center gap-1.5 cursor-pointer group"
                >
                    <div class="h-14 w-14 rounded-full bg-rose-500 hover:bg-rose-600 active:scale-95 text-white flex items-center justify-center shadow-lg transition-transform group-hover:scale-110">
                        <x-icon name="phone-off" class="h-6 w-6" />
                    </div>
                    <span class="text-xs font-semibold text-muted-foreground group-hover:text-foreground transition-colors">{{ __('Decline') }}</span>
                </button>

                <!-- Accept -->
                <button
                    type="button"
                    @click="stopRingtone()"
                    wire:click="acceptCall"
                    class="flex flex-col items-center gap-1.5 cursor-pointer group"
                >
                    <div class="h-14 w-14 rounded-full bg-emerald-500 hover:bg-emerald-600 active:scale-95 text-white flex items-center justify-center shadow-lg transition-transform group-hover:scale-110">
                        <x-icon name="video" class="h-6 w-6" x-show="callType === 'video'" />
                        <x-icon name="phone" class="h-6 w-6" x-show="callType === 'audio'" />
                    </div>
                    <span class="text-xs font-semibold text-muted-foreground group-hover:text-foreground transition-colors">{{ __('Accept') }}</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 2. Active WebRTC Call View Overlay (1-on-1 & Multi-Party Group Grid) -->
    <!-- A. Fullscreen / Expanded Overlay -->
    <div
        x-show="(callStatus === 'connected' || callStatus === 'outgoing') && !isMinimized"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="fixed inset-2 sm:inset-5 md:inset-8 z-50 rounded-3xl border border-zinc-800/80 bg-zinc-950/95 backdrop-blur-2xl shadow-2xl flex flex-col overflow-hidden text-white"
    >
        <!-- Top bar: Peer/Group Name, Call Timer, Security & Layout Controls -->
        <div class="px-4 sm:px-6 py-3.5 border-b border-white/10 flex items-center justify-between bg-white/5 shrink-0 gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-10 text-sm border-2 border-primary shrink-0" />
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-bold text-white truncate max-w-[140px] sm:max-w-xs" x-text="peerName || '{{ __('Live Call') }}'">{{ $peerName }}</h3>
                        <span x-show="isGroup" class="text-[10px] px-2 py-0.5 rounded-full bg-primary/20 text-primary border border-primary/30 font-semibold uppercase shrink-0">
                            {{ __('Group Call') }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-white/70">
                        <span class="h-2 w-2 rounded-full" :class="callStatus === 'connected' ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'"></span>
                        <span x-text="callStatus === 'connected' ? formatDuration(durationSeconds) : '{{ __('Connecting / Ringing…') }}'"></span>
                        <span x-show="isGroup && callStatus === 'connected'" class="text-white/40">•</span>
                        <span x-show="isGroup && callStatus === 'connected'" class="text-emerald-400 font-medium" x-text="`${allParticipantsCount} {{ __('in call') }}`"></span>
                    </div>
                </div>
            </div>

            <!-- Top Right Action Controls -->
            <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                <!-- Autoplay Unblock Banner -->
                <button
                    type="button"
                    x-show="audioAutoplayBlocked"
                    @click="unlockAutoplayAudio()"
                    class="px-2.5 py-1 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold flex items-center gap-1.5 animate-bounce shadow-lg cursor-pointer"
                >
                    <x-icon name="volume-2" class="h-3.5 w-3.5" />
                    <span>{{ __('Enable Sound') }}</span>
                </button>

                <!-- Layout Mode Selector for Group Calls -->
                <div x-show="isGroup && connectedPeersCount > 0" class="hidden sm:flex items-center p-0.5 rounded-xl bg-white/10 border border-white/10 text-xs">
                    <button
                        type="button"
                        @click="setLayoutMode('grid')"
                        class="px-2.5 py-1 rounded-lg transition-colors cursor-pointer"
                        :class="layoutMode === 'grid' ? 'bg-primary text-white font-semibold' : 'text-white/70 hover:text-white'"
                        title="{{ __('Grid View') }}"
                    >
                        <x-icon name="layout-grid" class="h-3.5 w-3.5 inline" />
                    </button>
                    <button
                        type="button"
                        @click="setLayoutMode('speaker')"
                        class="px-2.5 py-1 rounded-lg transition-colors cursor-pointer"
                        :class="layoutMode === 'speaker' ? 'bg-primary text-white font-semibold' : 'text-white/70 hover:text-white'"
                        title="{{ __('Speaker Spotlight') }}"
                    >
                        <x-icon name="user" class="h-3.5 w-3.5 inline" />
                    </button>
                </div>

                <!-- Call Type Badge -->
                <span class="text-xs px-2.5 py-1 rounded-full bg-white/10 text-white font-medium uppercase tracking-wider hidden sm:flex items-center gap-1.5">
                    <x-icon name="phone" class="h-3 w-3" x-show="callType === 'audio'" />
                    <x-icon name="video" class="h-3 w-3" x-show="callType === 'video'" />
                    <span x-text="callType === 'audio' ? '{{ __('Voice') }}' : '{{ __('Video') }}'"></span>
                </span>

                <!-- Minimize Window Button -->
                <button
                    type="button"
                    @click="toggleMinimize()"
                    class="h-8 w-8 rounded-xl bg-white/10 hover:bg-white/20 text-white/80 hover:text-white flex items-center justify-center transition-colors cursor-pointer"
                    title="{{ __('Minimize to Floating Pill') }}"
                >
                    <x-icon name="minimize-2" class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- Hardware & Resource Diagnostic Alert Banner -->
        <div
            x-show="hardwareNotice.show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="px-4 py-2 border-b flex items-center justify-between text-xs z-20 backdrop-blur-md shrink-0"
            :class="{
                'bg-amber-500/20 border-amber-500/30 text-amber-200': hardwareNotice.type === 'warning',
                'bg-rose-500/20 border-rose-500/30 text-rose-200': hardwareNotice.type === 'danger',
                'bg-blue-500/20 border-blue-500/30 text-blue-200': hardwareNotice.type === 'info'
            }"
        >
            <div class="flex items-center gap-2 truncate">
                <x-icon name="alert-triangle" class="h-4 w-4 shrink-0" x-show="hardwareNotice.type === 'warning'" />
                <x-icon name="alert-circle" class="h-4 w-4 shrink-0" x-show="hardwareNotice.type === 'danger'" />
                <span class="truncate font-medium" x-text="hardwareNotice.message"></span>
            </div>
            <div class="flex items-center gap-2 shrink-0 ml-3">
                <button
                    type="button"
                    x-show="hardwareNotice.canRetryCamera"
                    @click="retryAcquireCamera()"
                    :disabled="hardwareNotice.isRetrying"
                    class="px-2.5 py-1 rounded-lg bg-white/20 hover:bg-white/30 text-white font-semibold transition-colors flex items-center gap-1 cursor-pointer"
                >
                    <x-icon name="refresh-cw" class="h-3 w-3" x-show="!hardwareNotice.isRetrying" />
                    <x-icon name="loader-2" class="h-3 w-3 animate-spin" x-show="hardwareNotice.isRetrying" />
                    <span>{{ __('Retry Camera') }}</span>
                </button>
                <button
                    type="button"
                    @click="hardwareNotice.show = false"
                    class="p-1 rounded-md text-white/60 hover:text-white transition-colors cursor-pointer"
                >
                    <x-icon name="x" class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>

        <!-- Center: Video Streams or Audio Tile Cards Grid Stage -->
        <div class="flex-1 relative bg-black/95 p-3 sm:p-5 overflow-y-auto flex items-center justify-center">
            <!-- 1. GROUP CALL MULTI-PEER GRID -->
            <template x-if="isGroup">
                <div class="w-full h-full flex flex-col items-center justify-center">
                    <div
                        class="w-full h-full grid gap-3.5 max-w-6xl mx-auto"
                        :class="{
                            'grid-cols-1 md:grid-cols-2': allParticipantsCount <= 2,
                            'grid-cols-2 md:grid-cols-2 lg:grid-cols-2': allParticipantsCount === 3 || allParticipantsCount === 4,
                            'grid-cols-2 md:grid-cols-3': allParticipantsCount >= 5
                        }"
                    >
                        <!-- Local Participant Tile -->
                        <div
                            class="relative rounded-2xl overflow-hidden bg-zinc-900 border-2 transition-all flex flex-col items-center justify-center min-h-[160px] sm:min-h-[220px]"
                            :class="isLocalSpeaking ? 'border-emerald-400 shadow-lg shadow-emerald-500/20' : 'border-white/15'"
                        >
                            <!-- Local Video (if video on) -->
                            <video
                                x-ref="localVideo"
                                data-local-call-video="true"
                                x-init="$nextTick(() => rebindLocalVideo())"
                                autoplay
                                playsinline
                                muted
                                class="w-full h-full object-cover transform -scale-x-100"
                                :class="(callType === 'audio' || isVideoOff) ? 'hidden' : 'block'"
                            ></video>

                            <!-- Local Audio / Video Off Avatar Card -->
                            <div x-show="callType === 'audio' || isVideoOff" class="flex flex-col items-center justify-center p-4 space-y-3">
                                <div class="relative">
                                    <div class="absolute -inset-2 rounded-full bg-emerald-500/20" :class="isLocalSpeaking ? 'animate-ping' : ''"></div>
                                    <x-ui.avatar :name="auth()->user()?->name ?? 'You'" :src="auth()->user()?->avatarUrl()" size="size-16 sm:size-20 text-lg border-2 border-primary" />
                                </div>
                            </div>

                            <!-- Bottom Tile Info Banner -->
                            <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between px-2.5 py-1 rounded-xl bg-black/70 backdrop-blur-md text-xs">
                                <span class="font-semibold text-white truncate">{{ __('You') }}</span>
                                <div class="flex items-center gap-1.5">
                                    <span x-show="isMuted" class="p-1 rounded-md bg-rose-500/80 text-white" title="{{ __('Microphone Muted') }}">
                                        <x-icon name="mic-off" class="h-3 w-3" />
                                    </span>
                                    <span x-show="!isMuted && isLocalSpeaking" class="flex items-center gap-0.5 text-emerald-400">
                                        <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.12)}px`"></span>
                                        <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.22)}px`"></span>
                                    </span>
                                    <span x-show="callType !== 'audio' && isVideoOff" class="p-1 rounded-md bg-zinc-800 text-white/70" title="{{ __('Camera Off') }}">
                                        <x-icon name="video-off" class="h-3 w-3" />
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Remote Group Peers Tiles -->
                        <template x-for="(peer, peerId) in peers" :key="peerId">
                            <div
                                class="relative rounded-2xl overflow-hidden bg-zinc-900 border-2 transition-all flex flex-col items-center justify-center min-h-[160px] sm:min-h-[220px]"
                                :class="peer.isSpeaking ? 'border-emerald-400 shadow-lg shadow-emerald-500/20' : 'border-white/15'"
                            >
                                <video
                                    :id="'remote-call-video-' + peer.userId"
                                    :data-remote-call-video="peer.userId"
                                    x-init="$nextTick(() => bindRemoteVideo(peer.userId))"
                                    autoplay
                                    playsinline
                                    class="w-full h-full object-cover"
                                    :class="(callType === 'audio' || isPeerVideoOff(peer.userId)) ? 'hidden' : 'block'"
                                ></video>

                                <div x-show="callType === 'audio' || isPeerVideoOff(peer.userId)" class="flex flex-col items-center justify-center p-4 space-y-3">
                                    <div class="relative">
                                        <div class="absolute -inset-2 rounded-full bg-emerald-500/20" :class="peer.isSpeaking ? 'animate-ping' : ''"></div>
                                        <div class="h-16 w-16 sm:h-20 sm:w-20 rounded-full bg-primary/20 border-2 border-primary flex items-center justify-center font-bold text-lg text-primary overflow-hidden">
                                            <template x-if="peer.avatar">
                                                <img :src="peer.avatar" class="w-full h-full object-cover" />
                                            </template>
                                            <template x-if="!peer.avatar">
                                                <span x-text="peer.name ? peer.name.charAt(0).toUpperCase() : '?'"></span>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between px-2.5 py-1 rounded-xl bg-black/70 backdrop-blur-md text-xs">
                                    <span class="font-semibold text-white truncate" x-text="peer.name || ('Participant #' + peer.userId)"></span>
                                    <div class="flex items-center gap-1.5">
                                        <span x-show="isPeerMuted(peer.userId)" class="p-1 rounded-md bg-rose-500/80 text-white" title="{{ __('Muted') }}">
                                            <x-icon name="mic-off" class="h-3 w-3" />
                                        </span>
                                        <span x-show="!isPeerMuted(peer.userId) && peer.isSpeaking" class="flex items-center gap-0.5 text-emerald-400">
                                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, peer.audioLevel * 0.12)}px`"></span>
                                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, peer.audioLevel * 0.22)}px`"></span>
                                        </span>
                                        <span x-show="callType !== 'audio' && isPeerVideoOff(peer.userId)" class="p-1 rounded-md bg-zinc-800 text-white/70" title="{{ __('Camera Off') }}">
                                            <x-icon name="video-off" class="h-3 w-3" />
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Group Ringing Placeholder when alone in call -->
                        <div
                            x-show="connectedPeersCount === 0"
                            class="relative rounded-2xl overflow-hidden bg-zinc-900/60 border-2 border-dashed border-white/20 flex flex-col items-center justify-center p-6 text-center space-y-3 min-h-[160px] sm:min-h-[220px]"
                        >
                            <div class="h-12 w-12 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center animate-pulse">
                                <x-icon name="users" class="h-6 w-6" />
                            </div>
                            <div>
                                <h4 class="text-sm font-semibold text-white">{{ __('Waiting for other participants…') }}</h4>
                                <p class="text-xs text-white/60 mt-0.5">{{ __('Ringing all participants in this chat') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- 2. 1-ON-1 DIRECT CALL VIEW -->
            <template x-if="!isGroup">
                <div class="w-full h-full relative flex items-center justify-center">
                    <!-- Video Mode -->
                    <template x-if="callType === 'video'">
                        <div class="w-full h-full relative flex items-center justify-center">
                            <!-- Remote Video (Large Stage) -->
                            <video
                                x-ref="remoteVideo"
                                data-remote-call-video="direct"
                                x-init="$nextTick(() => rebindAllRemoteVideos())"
                                autoplay
                                playsinline
                                class="w-full h-full object-cover rounded-2xl"
                                :class="remoteVideoOff ? 'hidden' : 'block'"
                            ></video>

                            <!-- Remote placeholder if video stream is not yet established or remote video is off -->
                            <div x-show="callStatus === 'outgoing' || (callStatus === 'connected' && remoteVideoOff)" class="absolute inset-0 flex flex-col items-center justify-center space-y-4 bg-zinc-900 rounded-2xl">
                                <div class="relative">
                                    <div class="absolute -inset-4 rounded-full bg-primary/20 animate-ping" x-show="callStatus === 'outgoing'"></div>
                                    <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-24 text-2xl border-4 border-primary shadow-2xl" />
                                </div>
                                <p class="text-sm text-zinc-400 font-medium" x-text="callStatus === 'outgoing' ? '{{ __('Ringing...') }}' : '{{ __('Camera is turned off') }}'"></p>
                            </div>

                            <!-- Local Video (Picture-in-Picture in corner) -->
                            <div
                                class="absolute bottom-4 right-4 w-36 sm:w-52 aspect-video rounded-2xl overflow-hidden border-2 shadow-2xl bg-zinc-900 z-10 transition-all cursor-move"
                                :class="localAudioLevel > 6 ? 'border-emerald-400 shadow-emerald-500/20' : 'border-white/20'"
                            >
                                <video
                                    x-ref="localVideo"
                                    data-local-call-video="true"
                                    x-init="$nextTick(() => rebindLocalVideo())"
                                    autoplay
                                    playsinline
                                    muted
                                    class="w-full h-full object-cover transform -scale-x-100"
                                    :class="isVideoOff ? 'hidden' : 'block'"
                                ></video>

                                <div x-show="isVideoOff" class="w-full h-full flex items-center justify-center bg-zinc-900 text-white/50 text-xs font-medium">
                                    {{ __('Camera Off') }}
                                </div>

                                <!-- Local PIP Voice Meter Overlay -->
                                <div x-show="!isMuted" class="absolute bottom-1.5 right-1.5 flex items-center gap-0.5 px-1.5 py-0.5 rounded-md bg-black/60 backdrop-blur-xs">
                                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.12)}px`"></span>
                                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.22)}px`"></span>
                                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.15)}px`"></span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Audio-only Mode Stage -->
                    <template x-if="callType === 'audio'">
                        <div class="flex flex-col items-center justify-center space-y-6">
                            <div class="relative">
                                <div
                                    class="absolute -inset-6 rounded-full transition-all duration-150"
                                    :class="remoteAudioLevel > 6 ? 'bg-emerald-500/30 scale-110 animate-pulse' : 'bg-emerald-500/10'"
                                ></div>
                                <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-32 text-4xl border-4 border-emerald-500 shadow-2xl" />
                            </div>

                            <div class="text-center">
                                <h4 class="text-xl font-bold" x-text="peerName || '{{ __('Participant') }}'">{{ $peerName }}</h4>
                                <p class="text-xs text-white/60 mt-1" x-text="callStatus === 'connected' ? formatDuration(durationSeconds) : '{{ __('Voice Call Ringing…') }}'"></p>
                            </div>

                            <!-- Live Multi-Bar Waveform Display in Audio Mode -->
                            <div x-show="callStatus === 'connected'" class="flex items-center justify-center gap-1.5 h-10 px-6 py-2 rounded-2xl bg-white/5 border border-white/10">
                                <template x-for="i in 16" :key="i">
                                    <span
                                        class="w-1 rounded-full bg-emerald-400 transition-all duration-75"
                                        :style="`height: ${Math.max(4, Math.min(32, remoteAudioLevel * 0.35 * (0.4 + 0.6 * Math.sin(i * 0.5))))}px`"
                                    ></span>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <!-- Bottom Controls Toolbar -->
        <div class="px-4 sm:px-6 py-4 border-t border-white/10 bg-white/5 flex items-center justify-center gap-3 sm:gap-4 shrink-0 flex-wrap">
            <!-- Mute / Unmute Mic with Live Level Indicator -->
            <div class="relative flex items-center">
                <button
                    type="button"
                    x-on:click="toggleMute()"
                    class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg active:scale-95"
                    :class="isMuted ? 'bg-rose-500 text-white' : (isLocalSpeaking ? 'bg-emerald-600 text-white ring-2 ring-emerald-400' : 'bg-white/15 hover:bg-white/25 text-white')"
                    :title="isMuted ? '{{ __('Unmute Microphone') }}' : '{{ __('Mute Microphone') }}'"
                >
                    <x-icon name="mic" class="h-5 w-5" x-show="!isMuted" />
                    <x-icon name="mic-off" class="h-5 w-5" x-show="isMuted" />
                </button>

                <!-- Mini Waveform Bar next to mic button -->
                <div x-show="!isMuted" class="absolute -top-2 -right-1 flex items-center gap-0.5 px-1 py-0.5 rounded-full bg-black/80 border border-emerald-500/50">
                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(2, localAudioLevel * 0.12)}px`"></span>
                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(2, localAudioLevel * 0.2)}px`"></span>
                </div>
            </div>

            <!-- Toggle Camera (Video Calls Only) -->
            <template x-if="callType === 'video'">
                <button
                    type="button"
                    x-on:click="toggleVideo()"
                    class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg active:scale-95"
                    :class="isVideoOff ? 'bg-rose-500 text-white' : 'bg-white/15 hover:bg-white/25 text-white'"
                    :title="isVideoOff ? '{{ __('Turn Camera On') }}' : '{{ __('Turn Camera Off') }}'"
                >
                    <x-icon name="video" class="h-5 w-5" x-show="!isVideoOff" />
                    <x-icon name="video-off" class="h-5 w-5" x-show="isVideoOff" />
                </button>
            </template>

            <!-- Switch Call Mode (Voice <-> Video) with Participant Approval -->
            <button
                type="button"
                x-show="callStatus === 'connected'"
                @click="requestSwitchCallMode(callType === 'audio' ? 'video' : 'audio')"
                class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg bg-white/15 hover:bg-white/25 text-white active:scale-95"
                :title="callType === 'audio' ? '{{ __('Switch to Video Call') }}' : '{{ __('Switch to Voice Call') }}'"
            >
                <x-icon name="video" class="h-5 w-5 text-emerald-400" x-show="callType === 'audio'" />
                <x-icon name="phone" class="h-5 w-5 text-sky-400" x-show="callType === 'video'" />
            </button>

            <!-- Add / Invite Users to Ongoing Call -->
            <button
                type="button"
                x-show="callStatus === 'connected'"
                wire:click="openAddParticipantModal"
                class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg bg-white/15 hover:bg-white/25 text-white active:scale-95"
                title="{{ __('Add / Invite Users to Call') }}"
            >
                <x-icon name="user-plus" class="h-5 w-5 text-amber-400" />
            </button>

            <!-- Device Settings Button -->
            <button
                type="button"
                @click="showDeviceSettingsModal = !showDeviceSettingsModal"
                class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg bg-white/15 hover:bg-white/25 text-white active:scale-95"
                title="{{ __('Audio & Video Device Settings') }}"
            >
                <x-icon name="settings" class="h-5 w-5" />
            </button>

            <!-- Screen Share (Video Calls Only) -->
            <template x-if="callType === 'video'">
                <button
                    type="button"
                    x-on:click="toggleScreenShare()"
                    class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg active:scale-95"
                    :class="isScreenSharing ? 'bg-emerald-600 text-white ring-2 ring-emerald-400' : 'bg-white/15 hover:bg-white/25 text-white'"
                    :title="isScreenSharing ? '{{ __('Stop Screen Sharing') }}' : '{{ __('Share Screen') }}'"
                >
                    <x-icon name="screen-share" class="h-5 w-5" />
                </button>
            </template>

            <!-- Leave / End Call Button -->
            <button
                type="button"
                @click="cleanupWebRtc(); $wire.hangUpCall()"
                class="h-13 w-13 rounded-full bg-rose-600 hover:bg-rose-700 active:scale-95 text-white flex items-center justify-center shadow-xl transition-transform hover:scale-105 cursor-pointer"
                :title="isGroup ? '{{ __('Leave Group Call') }}' : '{{ __('End Call') }}'"
            >
                <x-icon name="phone-off" class="h-6 w-6" />
            </button>
        </div>
    </div>

    <!-- B. Floating Minimized Call Widget (For Multitasking in Live Chat / Portal) -->
    <div
        x-show="(callStatus === 'connected' || callStatus === 'outgoing') && isMinimized"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 scale-95"
        x-cloak
        class="fixed bottom-5 right-5 z-50 rounded-2xl border border-border bg-card/95 backdrop-blur-xl shadow-2xl p-3 flex items-center gap-3 animate-in fade-in"
    >
        <div class="relative">
            <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-10 text-sm border-2 border-primary" />
            <span class="absolute -top-1 -right-1 h-3 w-3 rounded-full" :class="callStatus === 'connected' ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'"></span>
        </div>

        <div class="min-w-0 pr-2">
            <h4 class="text-xs font-bold text-foreground truncate max-w-[120px]" x-text="peerName || '{{ __('Active Call') }}'"></h4>
            <p class="text-[11px] text-muted-foreground" x-text="callStatus === 'connected' ? formatDuration(durationSeconds) : '{{ __('Calling…') }}'"></p>
        </div>

        <!-- Mute Toggle in Pill -->
        <button
            type="button"
            x-on:click="toggleMute()"
            class="h-8 w-8 rounded-xl flex items-center justify-center transition-colors cursor-pointer"
            :class="isMuted ? 'bg-rose-500 text-white' : 'bg-secondary text-foreground hover:bg-secondary/80'"
        >
            <x-icon name="mic" class="h-4 w-4" x-show="!isMuted" />
            <x-icon name="mic-off" class="h-4 w-4" x-show="isMuted" />
        </button>

        <!-- Maximize / Restore -->
        <button
            type="button"
            @click="toggleMinimize()"
            class="h-8 w-8 rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 flex items-center justify-center transition-colors cursor-pointer"
            title="{{ __('Expand Call') }}"
        >
            <x-icon name="maximize-2" class="h-4 w-4" />
        </button>

        <!-- Hangup in Pill -->
        <button
            type="button"
            @click="cleanupWebRtc(); $wire.hangUpCall()"
            class="h-8 w-8 rounded-xl bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center transition-colors cursor-pointer"
            title="{{ __('End Call') }}"
        >
            <x-icon name="phone-off" class="h-4 w-4" />
        </button>
    </div>

    <!-- 3. In-Call Device Settings Modal -->
    <div
        x-cloak
        x-show="showDeviceSettingsModal"
        class="fixed inset-0 z-100000 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        x-transition
    >
        <div
            @click.outside="showDeviceSettingsModal = false"
            class="w-full max-w-sm bg-card border border-border rounded-2xl shadow-2xl p-5 space-y-4 animate-in fade-in zoom-in-95 duration-200 text-foreground"
        >
            <div class="flex items-center justify-between border-b border-border pb-3">
                <div class="flex items-center gap-2">
                    <x-icon name="settings" class="h-4 w-4 text-primary" />
                    <h3 class="text-sm font-bold">{{ __('Device Settings') }}</h3>
                </div>
                <button type="button" @click="showDeviceSettingsModal = false" class="text-muted-foreground hover:text-foreground">
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>

            <!-- Microphone Selection -->
            <div class="space-y-1.5 text-xs">
                <label class="font-semibold text-muted-foreground">{{ __('Microphone Input') }}</label>
                <select
                    x-model="selectedMicId"
                    @change="switchMicrophoneDevice(selectedMicId)"
                    class="w-full px-3 py-2 rounded-xl bg-muted/60 border border-input text-xs focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <template x-for="mic in availableMics" :key="mic.deviceId">
                        <option :value="mic.deviceId" x-text="mic.label"></option>
                    </template>
                </select>
            </div>

            <!-- Camera Selection (Video calls) -->
            <div class="space-y-1.5 text-xs" x-show="callType === 'video'">
                <label class="font-semibold text-muted-foreground">{{ __('Camera Input') }}</label>
                <select
                    x-model="selectedCameraId"
                    @change="switchCameraDevice(selectedCameraId)"
                    class="w-full px-3 py-2 rounded-xl bg-muted/60 border border-input text-xs focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <template x-for="cam in availableCameras" :key="cam.deviceId">
                        <option :value="cam.deviceId" x-text="cam.label"></option>
                    </template>
                </select>
            </div>

            <!-- Speaker Selection -->
            <div class="space-y-1.5 text-xs" x-show="availableSpeakers.length > 0">
                <label class="font-semibold text-muted-foreground">{{ __('Speaker / Audio Output') }}</label>
                <select
                    x-model="selectedSpeakerId"
                    @change="switchAudioOutputDevice(selectedSpeakerId)"
                    class="w-full px-3 py-2 rounded-xl bg-muted/60 border border-input text-xs focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <template x-for="spk in availableSpeakers" :key="spk.deviceId">
                        <option :value="spk.deviceId" x-text="spk.label"></option>
                    </template>
                </select>
            </div>

            <div class="pt-2 flex justify-end">
                <x-ui.button type="button" @click="showDeviceSettingsModal = false" variant="primary" size="sm">
                    {{ __('Done') }}
                </x-ui.button>
            </div>
        </div>
    </div>

    <!-- 4. Invite User to Ongoing Call Modal -->
    <div
        x-cloak
        x-show="$wire.showAddParticipantModal"
        class="fixed inset-0 z-100000 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div
            @click.outside="$wire.closeAddParticipantModal()"
            class="w-full max-w-md bg-card border border-border rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh] animate-in fade-in zoom-in-95 duration-200"
        >
            <!-- Header -->
            <div class="px-5 py-4 border-b border-border flex items-center justify-between bg-muted/30">
                <div class="flex items-center gap-2.5">
                    <div class="h-9 w-9 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                        <x-icon name="user-plus" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">{{ __('Invite to Call') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Add participants to this live call') }}</p>
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="closeAddParticipantModal"
                    class="h-8 w-8 rounded-lg hover:bg-accent text-muted-foreground hover:text-foreground flex items-center justify-center transition-colors cursor-pointer"
                >
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>

            <!-- Search Input -->
            <div class="p-4 border-b border-border bg-background">
                <div class="relative">
                    <x-icon name="search" class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="text"
                        wire:model.live.debounce.250ms="inviteSearchQuery"
                        placeholder="{{ __('Search users by name or email…') }}"
                        class="w-full pl-9 pr-3 py-2 text-xs rounded-xl bg-muted/50 border border-input focus:bg-background focus:outline-none focus:ring-2 focus:ring-primary"
                    />
                </div>
            </div>

            <!-- Users List -->
            <div class="p-2 overflow-y-auto max-h-72 space-y-1 divide-y divide-border/30">
                @forelse($this->availableUsersToInvite as $u)
                    <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-accent/50 transition-colors">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-ui.avatar :name="$u->name" :src="$u->avatarUrl()" size="size-9" />
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-foreground truncate">{{ $u->name }}</p>
                                <p class="text-[11px] text-muted-foreground truncate">{{ $u->email }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            wire:click="inviteUser({{ $u->id }})"
                            class="px-3 py-1.5 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-semibold flex items-center gap-1.5 shadow-xs transition-transform active:scale-95 cursor-pointer"
                        >
                            <x-icon name="phone-call" class="h-3.5 w-3.5" />
                            <span>{{ __('Invite') }}</span>
                        </button>
                    </div>
                @empty
                    <div class="py-8 text-center text-xs text-muted-foreground">
                        {{ __('No available users found to invite.') }}
                    </div>
                @endforelse
            </div>

            <!-- Footer -->
            <div class="px-5 py-3 border-t border-border bg-muted/20 flex justify-end">
                <button
                    type="button"
                    wire:click="closeAddParticipantModal"
                    class="px-4 py-2 rounded-xl text-xs font-medium text-muted-foreground hover:bg-accent transition-colors cursor-pointer"
                >
                    {{ __('Cancel') }}
                </button>
            </div>
        </div>
    </div>

    <!-- 5. Switch Mode Confirmation Modal -->
    <div
        x-cloak
        x-show="$wire.showSwitchModeConfirmModal || showSwitchModeModal"
        class="fixed inset-0 z-100000 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div
            class="w-full max-w-sm bg-card border border-border rounded-2xl shadow-2xl p-6 text-center space-y-4 animate-in fade-in zoom-in-95 duration-200"
        >
            <div
                class="mx-auto h-14 w-14 rounded-full flex items-center justify-center shadow-lg"
                :class="(requestedSwitchType === 'video' || $wire.requestedSwitchType === 'video') ? 'bg-emerald-500/20 text-emerald-500 ring-2 ring-emerald-500/30' : 'bg-sky-500/20 text-sky-500 ring-2 ring-sky-500/30'"
            >
                <x-icon name="video" class="h-7 w-7" x-show="requestedSwitchType === 'video' || $wire.requestedSwitchType === 'video'" />
                <x-icon name="phone" class="h-7 w-7" x-show="requestedSwitchType === 'audio' || $wire.requestedSwitchType === 'audio'" />
            </div>

            <div>
                <h3 class="text-base font-bold text-foreground">{{ __('Switch Call Mode?') }}</h3>
                <p class="text-xs text-muted-foreground mt-1">
                    {{ __(':name has requested to switch this call to a :type call.', [
                        'name' => $switchRequesterName ?: __('A participant'),
                        'type' => $requestedSwitchType === 'audio' ? __('Voice/Audio') : __('Video')
                    ]) }}
                </p>
            </div>

            <div class="flex items-center justify-center gap-3 pt-2">
                <button
                    type="button"
                    @click="declineSwitchMode()"
                    class="flex-1 px-4 py-2.5 rounded-xl border border-border text-xs font-semibold text-muted-foreground hover:bg-accent hover:text-foreground transition-colors cursor-pointer"
                >
                    {{ __('Decline') }}
                </button>
                <button
                    type="button"
                    @click="acceptSwitchMode()"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-semibold shadow-xs transition-transform active:scale-95 cursor-pointer"
                >
                    {{ __('Accept Switch') }}
                </button>
            </div>
        </div>
    </div>
</div>
