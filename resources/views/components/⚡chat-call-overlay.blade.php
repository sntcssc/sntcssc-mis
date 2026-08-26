<?php

use App\Models\ChatCall;
use App\Models\Setting;
use App\Models\User;
use App\Services\WebRtcCallService;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?int $activeCallId = null;
    public ?string $activeCallUuid = null;
    public string $callType = 'video';
    public string $callStatus = 'idle'; // idle, incoming, outgoing, connected, ended
    public ?int $peerUserId = null;
    public string $peerName = '';
    public ?string $peerAvatar = null;
    public array $iceServers = [];

    // Polling mode signals tracker
    public string $signalingDriver = 'reverb';

    public function mount(): void
    {
        $this->signalingDriver = (string) Setting::get('chat.webrtc_signaling_driver', 'reverb');
        /** @var WebRtcCallService $callService */
        $callService = app(WebRtcCallService::class);
        $this->iceServers = $callService->getIceServers();

        $this->checkForIncomingCalls();
    }

    public function checkForIncomingCalls(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        // If currently idle, check for any incoming ringing calls
        if ($this->callStatus === 'idle') {
            $incoming = ChatCall::where('receiver_id', $user->id)
                ->where('status', ChatCall::STATUS_RINGING)
                ->where('created_at', '>=', now()->subSeconds(45))
                ->latest()
                ->first();

            if ($incoming) {
                $caller = $incoming->caller;
                $this->activeCallId = $incoming->id;
                $this->activeCallUuid = $incoming->uuid;
                $this->callType = $incoming->type;
                $this->callStatus = 'incoming';
                $this->peerUserId = $caller?->id;
                $this->peerName = $caller?->name ?? __('Unknown Caller');
                $this->peerAvatar = $caller?->avatarUrl();

                $this->dispatch('incoming-call-received', [
                    'callUuid' => $incoming->uuid,
                    'callType' => $incoming->type,
                    'callerName' => $this->peerName,
                ]);
            }
        } elseif ($this->activeCallUuid && in_array($this->callStatus, ['incoming', 'outgoing', 'connected'])) {
            // Check if call was ended or rejected by remote peer
            $call = ChatCall::where('uuid', $this->activeCallUuid)->first();
            if ($call && in_array($call->status, [ChatCall::STATUS_REJECTED, ChatCall::STATUS_MISSED, ChatCall::STATUS_ENDED, ChatCall::STATUS_FAILED])) {
                $this->resetCallState();
                $this->dispatch('call-terminated-remotely');
            }
        }
    }

    #[On('start-call')]
    public function startCall(int $receiverId, string $type = 'video', ?int $conversationId = null): void
    {
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
            $this->callStatus = 'outgoing';
            $this->peerUserId = $receiver->id;
            $this->peerName = $receiver->name;
            $this->peerAvatar = $receiver->avatarUrl();

            $this->dispatch('webrtc-call-started', [
                'callUuid' => $call->uuid,
                'callType' => $type,
                'peerUserId' => $receiver->id,
                'iceServers' => $this->iceServers,
                'isInitiator' => true,
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    #[On('start-group-call')]
    public function startGroupCall(int $conversationId, string $type = 'video'): void
    {
        $currentUser = auth()->user();
        $conversation = \App\Models\ChatConversation::find($conversationId);

        if (! $conversation || ! $currentUser) {
            return;
        }

        try {
            /** @var WebRtcCallService $callService */
            $callService = app(WebRtcCallService::class);
            $call = $callService->initiateGroupCall(
                caller: $currentUser,
                conversation: $conversation,
                type: $type
            );

            $this->activeCallId = $call->id;
            $this->activeCallUuid = $call->uuid;
            $this->callType = $type;
            $this->callStatus = 'outgoing';
            $this->peerName = $conversation->title;
            $this->peerAvatar = $conversation->displayAvatarFor($currentUser);

            $this->dispatch('webrtc-call-started', [
                'callUuid' => $call->uuid,
                'callType' => $type,
                'isGroup' => true,
                'iceServers' => $this->iceServers,
                'isInitiator' => true,
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
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

            $this->callStatus = 'connected';

            $this->dispatch('webrtc-call-accepted', [
                'callUuid' => $call->uuid,
                'callType' => $this->callType,
                'peerUserId' => $this->peerUserId,
                'iceServers' => $this->iceServers,
                'isInitiator' => false,
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
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
            $callService->endCall($this->activeCallUuid, $currentUser);
        } catch (\Throwable $e) {
            // Ignore
        }

        $this->resetCallState();
        $this->dispatch('webrtc-call-ended');
    }

    public function sendSignalPayload(string $signalType, array $payload): void
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
            recipientUserId: $this->peerUserId
        );
    }

    protected function resetCallState(): void
    {
        $this->activeCallId = null;
        $this->activeCallUuid = null;
        $this->callStatus = 'idle';
        $this->peerUserId = null;
        $this->peerName = '';
        $this->peerAvatar = null;
    }
};
?>

<div
    wire:poll.3s="checkForIncomingCalls"
    x-data="{
        callStatus: @entangle('callStatus'),
        callType: @entangle('callType'),
        peerName: @entangle('peerName'),
        peerAvatar: @entangle('peerAvatar'),
        localStream: null,
        remoteStream: null,
        peerConnection: null,
        isMuted: false,
        isVideoOff: false,
        isScreenSharing: false,
        durationSeconds: 0,
        timerInterval: null,
        ringtoneAudio: null,

        init() {
            window.addEventListener('incoming-call-received', (e) => {
                this.playRingtone();
            });

            window.addEventListener('webrtc-call-started', (e) => {
                this.initWebRtc(e.detail.iceServers, true, e.detail.callType);
            });

            window.addEventListener('webrtc-call-accepted', (e) => {
                this.stopRingtone();
                this.initWebRtc(e.detail.iceServers, false, e.detail.callType);
            });

            window.addEventListener('webrtc-call-ended', () => {
                this.cleanupWebRtc();
            });

            window.addEventListener('call-terminated-remotely', () => {
                this.cleanupWebRtc();
            });

            window.addEventListener('beforeunload', () => {
                this.cleanupWebRtc();
            });

            window.addEventListener('pagehide', () => {
                this.cleanupWebRtc();
            });

            document.addEventListener('livewire:navigating', () => {
                this.cleanupWebRtc();
            });
        },

        destroy() {
            this.cleanupWebRtc();
        },

        async initWebRtc(iceServers, isInitiator, callType) {
            this.startTimer();
            try {
                const constraints = {
                    audio: true,
                    video: callType === 'video' ? { width: { ideal: 1280 }, height: { ideal: 720 } } : false
                };

                this.localStream = await navigator.mediaDevices.getUserMedia(constraints);
                if (this.$refs.localVideo && callType === 'video') {
                    this.$refs.localVideo.srcObject = this.localStream;
                }

                this.peerConnection = new RTCPeerConnection({ iceServers: iceServers || [{ urls: 'stun:stun.l.google.com:19302' }] });

                this.localStream.getTracks().forEach(track => {
                    this.peerConnection.addTrack(track, this.localStream);
                });

                this.peerConnection.ontrack = (event) => {
                    if (this.$refs.remoteVideo) {
                        this.$refs.remoteVideo.srcObject = event.streams[0];
                    }
                    if (this.$refs.remoteAudio) {
                        this.$refs.remoteAudio.srcObject = event.streams[0];
                    }
                };

                this.peerConnection.onicecandidate = (event) => {
                    if (event.candidate) {
                        $wire.sendSignalPayload('ice_candidate', { candidate: event.candidate });
                    }
                };

                if (isInitiator) {
                    const offer = await this.peerConnection.createOffer();
                    await this.peerConnection.setLocalDescription(offer);
                    $wire.sendSignalPayload('offer', { sdp: offer });
                }
            } catch (err) {
                console.error('WebRTC Media Device Access Error:', err);
            }
        },

        toggleMute() {
            if (this.localStream) {
                this.isMuted = !this.isMuted;
                this.localStream.getAudioTracks().forEach(track => {
                    track.enabled = !this.isMuted;
                });
                $wire.sendSignalPayload('toggle_audio', { isMuted: this.isMuted });
            }
        },

        toggleVideo() {
            if (this.localStream) {
                this.isVideoOff = !this.isVideoOff;
                this.localStream.getVideoTracks().forEach(track => {
                    track.enabled = !this.isVideoOff;
                });
                $wire.sendSignalPayload('toggle_video', { isVideoOff: this.isVideoOff });
            }
        },

        startTimer() {
            this.durationSeconds = 0;
            clearInterval(this.timerInterval);
            this.timerInterval = setInterval(() => {
                this.durationSeconds++;
            }, 1000);
        },

        formatDuration(sec) {
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        },

        playRingtone() {
            // Visual pulse with optional audio
        },

        stopRingtone() {
            // Stop sound
        },

        cleanupWebRtc() {
            this.stopRingtone();
            clearInterval(this.timerInterval);
            this.durationSeconds = 0;
            if (this.localStream) {
                this.localStream.getTracks().forEach(track => track.stop());
                this.localStream = null;
            }
            if (this.peerConnection) {
                this.peerConnection.close();
                this.peerConnection = null;
            }
        }
    }"
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
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-md"
    >
        <div class="w-full max-w-sm rounded-2xl border border-primary/30 bg-card p-6 shadow-2xl text-center space-y-5 animate-pulse">
            <!-- Pulsing Avatar -->
            <div class="relative inline-block mx-auto">
                <div class="absolute -inset-2 rounded-full bg-primary/20 animate-ping"></div>
                <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-20 text-xl border-4 border-primary" />
            </div>

            <div>
                <h3 class="text-lg font-bold text-foreground">{{ $peerName }}</h3>
                <p class="text-xs text-primary font-medium mt-1 flex items-center justify-center gap-1.5">
                    <x-icon :name="$callType === 'video' ? 'video' : 'phone-call'" class="h-4 w-4 animate-bounce" />
                    <span>{{ __('Incoming :type Call…', ['type' => ucfirst($callType)]) }}</span>
                </p>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-center gap-6 pt-2">
                <!-- Decline -->
                <button
                    type="button"
                    wire:click="declineCall"
                    class="flex flex-col items-center gap-1 cursor-pointer group"
                >
                    <div class="h-14 w-14 rounded-full bg-rose-500 hover:bg-rose-600 text-white flex items-center justify-center shadow-lg transition-transform group-hover:scale-110">
                        <x-icon name="phone-off" class="h-6 w-6" />
                    </div>
                    <span class="text-[11px] font-medium text-muted-foreground">{{ __('Decline') }}</span>
                </button>

                <!-- Accept -->
                <button
                    type="button"
                    wire:click="acceptCall"
                    class="flex flex-col items-center gap-1 cursor-pointer group"
                >
                    <div class="h-14 w-14 rounded-full bg-emerald-500 hover:bg-emerald-600 text-white flex items-center justify-center shadow-lg transition-transform group-hover:scale-110">
                        <x-icon :name="$callType === 'video' ? 'video' : 'phone'" class="h-6 w-6" />
                    </div>
                    <span class="text-[11px] font-medium text-emerald-600 dark:text-emerald-400 font-semibold">{{ __('Accept') }}</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 2. Active WebRTC Call Stage / Overlay -->
    <div
        x-show="callStatus === 'connected' || callStatus === 'outgoing'"
        x-transition.opacity
        x-cloak
        class="fixed inset-0 z-50 flex flex-col bg-zinc-950 text-white select-none"
    >
        <!-- Top Toolbar -->
        <div class="flex items-center justify-between p-4 bg-gradient-to-b from-black/80 to-transparent z-10">
            <div class="flex items-center gap-3">
                <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-10 text-sm" />
                <div>
                    <h3 class="text-sm font-bold leading-tight">{{ $peerName }}</h3>
                    <div class="flex items-center gap-2 text-xs text-zinc-400">
                        <span class="h-2 w-2 rounded-full" :class="callStatus === 'connected' ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'"></span>
                        <span x-text="callStatus === 'connected' ? formatDuration(durationSeconds) : '{{ __('Connecting / Ringing…') }}'"></span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-white/10 border border-white/20 uppercase tracking-wider">
                    {{ __('Encrypted WebRTC') }}
                </span>
            </div>
        </div>

        <!-- Video / Audio Stream Viewport -->
        <div class="relative flex-1 flex items-center justify-center overflow-hidden bg-zinc-900">
            <!-- Remote Audio Element (Hidden for audio tracks) -->
            <audio x-ref="remoteAudio" autoplay></audio>

            <!-- Video Call Remote Stream -->
            <template x-if="callType === 'video'">
                <div class="relative w-full h-full flex items-center justify-center">
                    <video x-ref="remoteVideo" autoplay playsinline class="w-full h-full object-cover"></video>

                    <!-- PiP Local Camera Preview -->
                    <div class="absolute top-4 right-4 w-36 sm:w-48 aspect-video rounded-xl overflow-hidden border-2 border-white/20 shadow-2xl bg-black z-20">
                        <video x-ref="localVideo" autoplay playsinline muted class="w-full h-full object-cover mirror"></video>
                        <div class="absolute bottom-1 left-2 text-[10px] text-white/80 font-mono">{{ __('You') }}</div>
                    </div>
                </div>
            </template>

            <!-- Audio-Only Call Visual Stage -->
            <template x-if="callType === 'audio'">
                <div class="flex flex-col items-center gap-4">
                    <div class="relative">
                        <div class="absolute -inset-4 rounded-full bg-emerald-500/20 animate-ping"></div>
                        <x-ui.avatar :name="$peerName" :src="$peerAvatar" size="size-32 text-4xl border-4 border-emerald-500 shadow-2xl" />
                    </div>
                    <div class="flex items-center gap-1 mt-3">
                        <span class="h-6 w-1 bg-emerald-500 rounded-full animate-bounce"></span>
                        <span class="h-10 w-1 bg-emerald-500 rounded-full animate-bounce [animation-delay:0.2s]"></span>
                        <span class="h-14 w-1 bg-emerald-500 rounded-full animate-bounce [animation-delay:0.4s]"></span>
                        <span class="h-8 w-1 bg-emerald-500 rounded-full animate-bounce [animation-delay:0.1s]"></span>
                        <span class="h-12 w-1 bg-emerald-500 rounded-full animate-bounce [animation-delay:0.3s]"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Bottom Controls Bar -->
        <div class="p-6 bg-gradient-to-t from-black/90 to-transparent flex items-center justify-center gap-4 z-10">
            <!-- Mute Audio -->
            <button
                type="button"
                x-on:click="toggleMute()"
                class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg"
                :class="isMuted ? 'bg-rose-500 text-white' : 'bg-white/15 hover:bg-white/25 text-white'"
                :title="isMuted ? '{{ __('Unmute Microphone') }}' : '{{ __('Mute Microphone') }}'"
            >
                <x-icon name="mic" class="h-5 w-5" x-show="!isMuted" />
                <x-icon name="mic-off" class="h-5 w-5" x-show="isMuted" />
            </button>

            <!-- Toggle Camera (Video Calls Only) -->
            <template x-if="callType === 'video'">
                <button
                    type="button"
                    x-on:click="toggleVideo()"
                    class="h-12 w-12 rounded-full flex items-center justify-center transition-all cursor-pointer shadow-lg"
                    :class="isVideoOff ? 'bg-rose-500 text-white' : 'bg-white/15 hover:bg-white/25 text-white'"
                    :title="isVideoOff ? '{{ __('Turn Camera On') }}' : '{{ __('Turn Camera Off') }}'"
                >
                    <x-icon name="video" class="h-5 w-5" x-show="!isVideoOff" />
                    <x-icon name="video-off" class="h-5 w-5" x-show="isVideoOff" />
                </button>
            </template>

            <!-- End / Hang Up Call -->
            <button
                type="button"
                wire:click="hangUpCall"
                class="h-14 w-14 rounded-full bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center shadow-xl transition-transform hover:scale-105 cursor-pointer"
                title="{{ __('End Call') }}"
            >
                <x-icon name="phone-off" class="h-6 w-6" />
            </button>
        </div>
    </div>
</div>
