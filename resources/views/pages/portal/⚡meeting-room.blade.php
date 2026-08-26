<?php

use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\MeetingService;
use App\Services\WebRtcCallService;
use App\Support\Toast;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Meeting Room')] class extends Component {
    public string $uuid = '';
    public ?ChatMeeting $meeting = null;
    public ?ChatMeetingParticipant $participant = null;

    // Room State
    public bool $isMuted = false;
    public bool $isVideoOff = false;
    public bool $isScreenSharing = false;
    public bool $showChatDrawer = false;
    public bool $showInfoDrawer = false;
    public bool $showControlsDrawer = false;

    // In-Meeting Chat with Scoping & Pinning
    public string $inRoomMessage = '';
    public string $inRoomChatRecipient = 'all'; // all, hosts_only, or "user_id"
    public array $inRoomChatLogs = [];
    public ?int $pinnedInRoomIndex = null;

    // Emoji Reactions
    public array $activeFloatingReactions = [];

    // Ice Servers
    public array $iceServers = [];

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->meeting = ChatMeeting::where('uuid', $uuid)->with(['host', 'participants.user'])->firstOrFail();

        $user = auth()->user();
        if (! $user) {
            return;
        }

        if ($this->meeting->isEnded()) {
            Toast::dispatch($this, 'warning', __('This meeting has already ended.'));
            $this->redirectRoute('meetings.index', navigate: true);
            return;
        }

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);
        $this->participant = $meetingService->joinMeeting($this->meeting, $user);

        /** @var WebRtcCallService $callService */
        $callService = app(WebRtcCallService::class);
        $this->iceServers = $callService->getIceServers();
    }

    public function refreshRoom(): void
    {
        $this->meeting = ChatMeeting::where('uuid', $this->uuid)->with(['host', 'participants.user'])->first();
        if ($this->participant) {
            $this->participant->refresh();
        }
    }

    /* ----------------------------------------------------------------- *
     *  In-Meeting Chat Scoping & Pinning Handlers
     * ----------------------------------------------------------------- */

    public function sendRoomMessage(): void
    {
        $text = trim($this->inRoomMessage);
        if (empty($text)) {
            return;
        }

        if (! $this->meeting->isChatAllowed() && ! $this->meeting->isHostOrCoHost(auth()->user())) {
            Toast::dispatch($this, 'error', __('Chat is disabled for this meeting.'));
            return;
        }

        $user = auth()->user();
        $recipientLabel = __('Everyone');

        if ($this->inRoomChatRecipient === 'hosts_only') {
            $recipientLabel = __('Host & Co-Hosts Only');
        } elseif (is_numeric($this->inRoomChatRecipient)) {
            $target = User::find((int) $this->inRoomChatRecipient);
            $recipientLabel = __('Direct to :name', ['name' => $target?->name ?? __('Participant')]);
        }

        $this->inRoomChatLogs[] = [
            'user_name' => $user->name,
            'user_id' => $user->id,
            'avatar' => $user->avatarUrl(),
            'body' => $text,
            'recipient' => $this->inRoomChatRecipient,
            'recipient_label' => $recipientLabel,
            'time' => now()->format('h:i A'),
            'is_self' => true,
        ];

        $this->inRoomMessage = '';
    }

    public function pinInRoomMessage(int $index): void
    {
        if (isset($this->inRoomChatLogs[$index])) {
            $this->pinnedInRoomIndex = $index;
            Toast::dispatch($this, 'success', __('Message pinned to in-meeting chat.'));
        }
    }

    public function unpinInRoomMessage(): void
    {
        $this->pinnedInRoomIndex = null;
        Toast::dispatch($this, 'info', __('In-meeting message unpinned.'));
    }

    /* ----------------------------------------------------------------- *
     *  Emoji Reactions
     * ----------------------------------------------------------------- */

    public function sendReaction(string $emoji): void
    {
        if (! $this->meeting->isEmojiAllowed() && ! $this->meeting->isHostOrCoHost(auth()->user())) {
            Toast::dispatch($this, 'warning', __('Emoji reactions are disabled by host.'));
            return;
        }

        $user = auth()->user();
        $this->activeFloatingReactions[] = [
            'id' => uniqid('rx_'),
            'emoji' => $emoji,
            'user_name' => $user->name,
            'created_at' => microtime(true),
        ];

        // Keep last 15 reactions
        if (count($this->activeFloatingReactions) > 15) {
            array_shift($this->activeFloatingReactions);
        }

        $this->js("\$dispatch('trigger-floating-emoji', { emoji: '{$emoji}', user: '{$user->name}' })");
    }

    /* ----------------------------------------------------------------- *
     *  Host & Co-Host Management Controls
     * ----------------------------------------------------------------- */

    public function admitGuest(int $participantId): void
    {
        $user = auth()->user();
        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);

        try {
            $meetingService->admitParticipant($this->meeting, $participantId, $user);
            $this->refreshRoom();
            Toast::dispatch($this, 'success', __('Guest admitted to meeting room.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function denyGuest(int $participantId): void
    {
        $user = auth()->user();
        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);

        try {
            $meetingService->denyParticipant($this->meeting, $participantId, $user);
            $this->refreshRoom();
            Toast::dispatch($this, 'info', __('Guest admission denied.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function makeCoHost(int $participantId): void
    {
        $user = auth()->user();
        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);

        try {
            $meetingService->setParticipantRole($this->meeting, $participantId, ChatMeetingParticipant::ROLE_CO_HOST, $user);
            $this->refreshRoom();
            Toast::dispatch($this, 'success', __('Participant promoted to Co-Host.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function dismissCoHost(int $participantId): void
    {
        $user = auth()->user();
        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);

        try {
            $meetingService->setParticipantRole($this->meeting, $participantId, ChatMeetingParticipant::ROLE_PARTICIPANT, $user);
            $this->refreshRoom();
            Toast::dispatch($this, 'info', __('Co-Host status removed.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function toggleRestriction(string $featureKey): void
    {
        $user = auth()->user();
        $settings = $this->meeting->settings ?? [];
        $currentVal = (bool) ($settings[$featureKey] ?? true);
        $settings[$featureKey] = ! $currentVal;

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);

        try {
            $meetingService->updateRestrictions($this->meeting, $settings, $user);
            $this->refreshRoom();
            Toast::dispatch($this, 'info', __('Restriction updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function muteAllParticipants(): void
    {
        $this->js("\$dispatch('force-mute-all')");
        Toast::dispatch($this, 'info', __('Mute signal sent to all participants.'));
    }

    public function turnOffAllVideos(): void
    {
        $this->js("\$dispatch('force-video-off-all')");
        Toast::dispatch($this, 'info', __('Video stop signal sent to all participants.'));
    }

    /* ----------------------------------------------------------------- *
     *  Leave / End Meeting Confirmation Handlers
     * ----------------------------------------------------------------- */

    public function promptLeaveMeeting(): void
    {
        $this->js("\$store.modals.open('leave-meeting-modal')");
    }

    public function executeLeaveMeeting(): void
    {
        $this->js("\$store.modals.close('leave-meeting-modal')");

        if ($this->participant) {
            $this->participant->update([
                'status' => ChatMeetingParticipant::STATUS_LEFT,
                'left_at' => now(),
            ]);
        }

        Toast::dispatch($this, 'info', __('You left the meeting.'));
        $this->redirectRoute('meetings.index', navigate: true);
    }

    public function promptEndMeetingForAll(): void
    {
        $this->js("\$store.modals.open('end-all-modal')");
    }

    public function executeEndMeetingForAll(): void
    {
        $this->js("\$store.modals.close('end-all-modal')");

        $user = auth()->user();
        if ($this->meeting && $user) {
            /** @var MeetingService $meetingService */
            $meetingService = app(MeetingService::class);
            try {
                $meetingService->endMeeting($this->meeting, $user);
                Toast::dispatch($this, 'success', __('Meeting ended for all participants.'));
                $this->redirectRoute('meetings.index', navigate: true);
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        $isHostOrCoHost = $this->meeting ? $this->meeting->isHostOrCoHost($user) : false;

        $activeParticipants = $this->meeting
            ? $this->meeting->participants()->where('status', ChatMeetingParticipant::STATUS_JOINED)->with('user')->get()
            : collect();

        $waitingParticipants = ($this->meeting && $isHostOrCoHost)
            ? $this->meeting->participants()->where('status', ChatMeetingParticipant::STATUS_WAITING)->with('user')->get()
            : collect();

        return [
            'currentUser' => $user,
            'isHostOrCoHost' => $isHostOrCoHost,
            'activeParticipants' => $activeParticipants,
            'waitingParticipants' => $waitingParticipants,
        ];
    }
};
?>

<div
    wire:poll.4s="refreshRoom"
    class="flex flex-col h-[calc(100vh-8.5rem)] min-h-[550px] rounded-2xl border border-border bg-zinc-950 text-white overflow-hidden shadow-2xl relative"
    x-data="{
        micMuted: false,
        videoOff: false,
        screenSharing: false,
        showEmojiMenu: false,
        showEndMeetingMenu: false,
        localStream: null,
        permissionError: null,
        floatingEmojis: [],

        init() {
            this.startMedia();
            window.addEventListener('trigger-floating-emoji', (e) => {
                this.addFloatingEmoji(e.detail.emoji, e.detail.user);
            });
            window.addEventListener('force-mute-all', () => {
                if (!{{ $isHostOrCoHost ? 'true' : 'false' }}) {
                    this.muteMicCompletely();
                }
            });
            window.addEventListener('force-video-off-all', () => {
                if (!{{ $isHostOrCoHost ? 'true' : 'false' }}) {
                    this.stopVideoCompletely();
                }
            });
            window.addEventListener('beforeunload', () => {
                this.stopAllMedia();
            });
            window.addEventListener('pagehide', () => {
                this.stopAllMedia();
            });
            document.addEventListener('livewire:navigating', () => {
                this.stopAllMedia();
            });
        },

        destroy() {
            this.stopAllMedia();
        },

        stopAllMedia() {
            if (this.localStream) {
                try {
                    this.localStream.getTracks().forEach(t => {
                        t.stop();
                    });
                } catch (e) {
                    console.warn('Error stopping local media tracks:', e);
                }
                this.localStream = null;
            }
            if (this.$refs.localVideo) {
                this.$refs.localVideo.srcObject = null;
            }
            this.micMuted = true;
            this.videoOff = true;
            this.screenSharing = false;
        },

        async startMedia() {
            this.permissionError = null;

            // Release any existing tracks before requesting new ones
            if (this.localStream) {
                try {
                    this.localStream.getTracks().forEach(t => t.stop());
                } catch (e) {}
                this.localStream = null;
            }

            const constraints = {
                audio: true,
                video: {{ $meeting->isVideo() ? 'true' : 'false' }}
            };

            try {
                this.localStream = await navigator.mediaDevices.getUserMedia(constraints);
                this.micMuted = false;
                this.videoOff = false;

                if (this.$refs.localVideo) {
                    this.$refs.localVideo.srcObject = this.localStream;
                    this.$refs.localVideo.play().catch(e => console.warn('Video play error:', e));
                }
            } catch (err) {
                console.warn('Primary getUserMedia error:', err);

                // Fallback attempt: If video failed, try acquiring audio-only so user is not blocked
                if (constraints.video) {
                    try {
                        this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                        this.videoOff = true;
                        this.micMuted = false;
                        if (this.$refs.localVideo) {
                            this.$refs.localVideo.srcObject = this.localStream;
                        }
                        this.permissionError = '{{ __('Camera could not be accessed. Joined with microphone audio only. Click "Retry Permission" if you want to enable camera.') }}';
                        return;
                    } catch (audioFallbackErr) {
                        console.warn('Audio fallback error:', audioFallbackErr);
                    }
                }

                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    this.permissionError = '{{ __('Camera/Microphone access was denied. Please click the Lock icon 🔒 in the browser address bar, allow Camera & Microphone permissions, and then click Retry.') }}';
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    this.permissionError = '{{ __('No camera or microphone hardware found on this device.') }}';
                } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                    this.permissionError = '{{ __('Camera or microphone is already in use by another application. Please close other apps and click Retry.') }}';
                } else {
                    this.permissionError = '{{ __('Hardware access error: ') }}' + (err.message || err.name);
                }
            }
        },

        toggleMic() {
            if (this.micMuted) {
                this.unmuteMic();
            } else {
                this.muteMicCompletely();
            }
        },

        muteMicCompletely() {
            this.micMuted = true;
            if (this.localStream) {
                this.localStream.getAudioTracks().forEach(t => {
                    t.enabled = false;
                });
            }
        },

        async unmuteMic() {
            this.micMuted = false;
            if (this.localStream && this.localStream.getAudioTracks().length > 0) {
                this.localStream.getAudioTracks().forEach(t => t.enabled = true);
            } else {
                try {
                    const audioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    const audioTrack = audioStream.getAudioTracks()[0];
                    if (this.localStream && audioTrack) {
                        this.localStream.addTrack(audioTrack);
                    }
                } catch (e) {
                    console.warn('Audio resume error:', e);
                }
            }
        },

        toggleVideo() {
            if (this.videoOff) {
                this.startVideoCompletely();
            } else {
                this.stopVideoCompletely();
            }
        },

        stopVideoCompletely() {
            this.videoOff = true;
            if (this.localStream) {
                this.localStream.getVideoTracks().forEach(t => {
                    t.enabled = false;
                    t.stop(); // Completely shuts off camera LED hardware indicator
                });
            }
        },

        async startVideoCompletely() {
            this.videoOff = false;
            try {
                const vidStream = await navigator.mediaDevices.getUserMedia({ video: true });
                const newVidTrack = vidStream.getVideoTracks()[0];
                if (this.localStream && newVidTrack) {
                    // Remove old stopped tracks
                    this.localStream.getVideoTracks().forEach(t => this.localStream.removeTrack(t));
                    this.localStream.addTrack(newVidTrack);
                    if (this.$refs.localVideo) {
                        this.$refs.localVideo.srcObject = this.localStream;
                        this.$refs.localVideo.play().catch(e => {});
                    }
                }
            } catch (err) {
                console.warn('Cannot re-acquire video track:', err);
                this.videoOff = true;
            }
        },

        async toggleScreenShare() {
            if (!this.screenSharing) {
                if (!{{ $meeting->isScreenShareAllowed() || $isHostOrCoHost ? 'true' : 'false' }}) {
                    alert('{{ __('Screen sharing is disabled by host.') }}');
                    return;
                }
                try {
                    const screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
                    if (this.$refs.localVideo) {
                        this.$refs.localVideo.srcObject = screenStream;
                    }
                    this.screenSharing = true;
                    screenStream.getVideoTracks()[0].onended = () => {
                        this.screenSharing = false;
                        if (this.$refs.localVideo && this.localStream) {
                            this.$refs.localVideo.srcObject = this.localStream;
                        }
                    };
                } catch (e) {
                    console.warn('Screen share canceled:', e);
                }
            } else {
                this.screenSharing = false;
                if (this.$refs.localVideo && this.localStream) {
                    this.$refs.localVideo.srcObject = this.localStream;
                }
            }
        },

        addFloatingEmoji(emoji, user) {
            const id = Date.now() + Math.random();
            const left = Math.floor(Math.random() * 80) + 10;
            this.floatingEmojis.push({ id, emoji, user, left });
            setTimeout(() => {
                this.floatingEmojis = this.floatingEmojis.filter(e => e.id !== id);
            }, 3000);
        }
    }"
>
    <!-- Waiting Room View (If user is in waiting lobby) -->
    @if ($participant && $participant->isWaiting())
        <div class="flex-1 flex flex-col items-center justify-center text-center p-8 space-y-4 bg-zinc-950 text-white z-50">
            <div class="h-20 w-20 rounded-full bg-primary/20 text-primary flex items-center justify-center animate-pulse">
                <x-icon name="clock" class="h-10 w-10" />
            </div>
            <div class="space-y-2 max-w-md">
                <h2 class="text-xl font-bold text-zinc-100">{{ __('You are in the Waiting Room') }}</h2>
                <p class="text-xs text-zinc-400 leading-relaxed">
                    {{ __('Please wait, the meeting host or co-host has been notified and will admit you into ":title" shortly.', ['title' => $meeting->title]) }}
                </p>
            </div>
            <x-ui.button wire:click="promptLeaveMeeting" variant="secondary" size="sm">
                {{ __('Leave Waiting Room') }}
            </x-ui.button>
        </div>
    @else
        <!-- Meeting Room Header -->
        <div class="h-14 px-4 bg-zinc-900/90 backdrop-blur-md border-b border-zinc-800 flex items-center justify-between gap-3 shrink-0 z-20">
            <div class="flex items-center gap-3 min-w-0">
                <div class="p-1.5 rounded-lg bg-primary text-primary-foreground">
                    <x-icon :name="$meeting->isVideo() ? 'video' : 'phone'" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <h2 class="text-sm font-bold truncate text-zinc-100">{{ $meeting->title }}</h2>
                    <p class="text-[10px] text-zinc-400 flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>{{ __('Live Conference') }}</span>
                        <span>• {{ __(':count participants', ['count' => $activeParticipants->count()]) }}</span>
                        @if ($isHostOrCoHost)
                            <span class="px-1.5 py-0.2 rounded bg-amber-500/20 text-amber-300 font-semibold uppercase text-[9px]">{{ __('Host Control') }}</span>
                        @endif
                    </p>
                </div>
            </div>

            <!-- Top Header Actions -->
            <div class="flex items-center gap-1 sm:gap-2">
                <!-- Host Restrictions / Controls Drawer Toggle -->
                @if ($isHostOrCoHost)
                    <button
                        type="button"
                        wire:click="$toggle('showControlsDrawer')"
                        class="p-2 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer"
                        title="{{ __('Host Room Security & Controls') }}"
                    >
                        <x-icon name="shield" class="h-4 w-4 text-amber-400" />
                    </button>
                @endif

                <!-- Meeting Info Toggle -->
                <button
                    type="button"
                    wire:click="$toggle('showInfoDrawer')"
                    class="p-2 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer"
                    title="{{ __('Meeting Info & Participants') }}"
                >
                    <x-icon name="info" class="h-4 w-4" />
                </button>

                <!-- In-Meeting Chat Toggle -->
                <button
                    type="button"
                    wire:click="$toggle('showChatDrawer')"
                    class="p-2 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer relative"
                    title="{{ __('In-Meeting Chat') }}"
                >
                    <x-icon name="message-square" class="h-4 w-4" />
                    @if (count($inRoomChatLogs) > 0)
                        <span class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-primary ring-2 ring-zinc-900"></span>
                    @endif
                </button>
            </div>
        </div>

        <!-- Waiting Room Host Alert Bar -->
        @if ($isHostOrCoHost && $waitingParticipants->isNotEmpty())
            <div class="px-4 py-2 bg-amber-500/15 border-b border-amber-500/30 flex items-center justify-between gap-3 text-xs text-amber-200 z-20">
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-amber-400 animate-ping"></span>
                    <strong>{{ __(':count guest(s) waiting in lobby:', ['count' => $waitingParticipants->count()]) }}</strong>
                    <span class="text-[11px] text-zinc-300 truncate max-w-sm">
                        {{ $waitingParticipants->pluck('user.name')->implode(', ') }}
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    @foreach ($waitingParticipants as $wp)
                        <button
                            type="button"
                            wire:click="admitGuest({{ $wp->id }})"
                            class="px-2.5 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-bold cursor-pointer"
                        >
                            {{ __('Admit :name', ['name' => $wp->user?->name]) }}
                        </button>
                        <button
                            type="button"
                            wire:click="denyGuest({{ $wp->id }})"
                            class="px-2 py-1 rounded bg-rose-600/40 hover:bg-rose-600 text-white text-[10px] cursor-pointer"
                        >
                            {{ __('Deny') }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Permission Rejection Alert Banner -->
        <div x-show="permissionError" x-cloak class="px-4 py-2.5 bg-rose-500/20 border-b border-rose-500/40 text-xs text-rose-300 flex items-center justify-between gap-2 z-20">
            <div class="flex items-center gap-2 min-w-0">
                <x-icon name="alert-octagon" class="h-4 w-4 shrink-0 text-rose-400" />
                <span class="leading-relaxed" x-text="permissionError"></span>
            </div>
            <button type="button" x-on:click="startMedia()" class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 cursor-pointer shrink-0 shadow-xs flex items-center gap-1">
                <x-icon name="refresh-cw" class="h-3.5 w-3.5" />
                <span>{{ __('Retry Permission') }}</span>
            </button>
        </div>

        <!-- Main Meeting Stage & Grid -->
        <div class="flex-1 flex overflow-hidden relative">
            <!-- Floating Reactions Container -->
            <div class="absolute inset-0 pointer-events-none overflow-hidden z-40">
                <template x-for="rx in floatingEmojis" :key="rx.id">
                    <div
                        class="absolute bottom-16 text-3xl select-none animate-float-up flex flex-col items-center gap-0.5"
                        :style="`left: ${rx.left}%;`"
                    >
                        <span x-text="rx.emoji"></span>
                        <span class="text-[9px] bg-black/60 px-1.5 py-0.5 rounded text-white font-medium" x-text="rx.user"></span>
                    </div>
                </template>
            </div>

            <!-- Video Tiles Viewports -->
            <div class="flex-1 p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 overflow-y-auto items-center justify-center">
                <!-- Local User Viewport -->
                <div class="relative rounded-2xl overflow-hidden bg-zinc-900 border border-zinc-800 aspect-video flex items-center justify-center group shadow-md">
                    <video
                        x-ref="localVideo"
                        autoplay
                        playsinline
                        muted
                        class="h-full w-full object-cover mirror"
                        :class="videoOff ? 'hidden' : 'block'"
                    ></video>

                    <!-- Avatar Fallback when camera off -->
                    <div x-show="videoOff" class="flex flex-col items-center justify-center p-4">
                        <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-20 text-xl shadow-xl" />
                    </div>

                    <!-- Overlay Label -->
                    <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between text-xs px-2 py-1 rounded-lg bg-black/60 backdrop-blur-xs text-white">
                        <span class="font-semibold truncate">
                            {{ $currentUser->name }} ({{ __('You') }})
                            @if ($participant && $participant->isHost())
                                <span class="text-amber-400 font-bold text-[10px] ml-1">[{{ __('Host') }}]</span>
                            @elseif ($participant && $participant->isCoHost())
                                <span class="text-sky-400 font-bold text-[10px] ml-1">[{{ __('Co-Host') }}]</span>
                            @endif
                        </span>
                        <div class="flex items-center gap-1.5">
                            <span x-show="micMuted" class="text-rose-400" title="{{ __('Muted') }}">
                                <x-icon name="mic-off" class="h-3.5 w-3.5" />
                            </span>
                            <span x-show="!micMuted" class="text-emerald-400">
                                <x-icon name="mic" class="h-3.5 w-3.5" />
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Other Active Participants Tiles -->
                @foreach ($activeParticipants as $p)
                    @if ((int) $p->user_id !== (int) $currentUser->id)
                        <div wire:key="active-part-{{ $p->id }}" class="relative rounded-2xl overflow-hidden bg-zinc-900 border border-zinc-800 aspect-video flex flex-col items-center justify-center shadow-md group">
                            <x-ui.avatar :name="$p->displayName()" :src="$p->user?->avatarUrl()" size="size-20 text-xl shadow-xl mb-2" />

                            <!-- Bottom Label -->
                            <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between text-xs px-2 py-1 rounded-lg bg-black/60 backdrop-blur-xs text-white">
                                <span class="font-semibold truncate">
                                    {{ $p->displayName() }}
                                    @if ($p->isHost())
                                        <span class="text-amber-400 text-[10px] ml-1">[{{ __('Host') }}]</span>
                                    @elseif ($p->isCoHost())
                                        <span class="text-sky-400 text-[10px] ml-1">[{{ __('Co-Host') }}]</span>
                                    @endif
                                </span>
                                <span class="text-emerald-400">
                                    <x-icon name="mic" class="h-3.5 w-3.5" />
                                </span>
                            </div>

                            <!-- Host Action Overlay on Hover -->
                            @if ($isHostOrCoHost)
                                <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <x-ui.dropdown width="w-40" offset="mt-1">
                                        <x-slot:trigger>
                                            <button type="button" class="p-1 rounded-lg bg-black/60 text-zinc-300 hover:text-white cursor-pointer">
                                                <x-icon name="more-vertical" class="h-4 w-4" />
                                            </button>
                                        </x-slot:trigger>

                                        @if (! $p->isHost())
                                            @if ($p->isCoHost())
                                                <x-ui.dropdown.item icon="user-minus" wire:click="dismissCoHost({{ $p->id }})">
                                                    {{ __('Dismiss Co-Host') }}
                                                </x-ui.dropdown.item>
                                            @else
                                                <x-ui.dropdown.item icon="shield" wire:click="makeCoHost({{ $p->id }})">
                                                    {{ __('Make Co-Host') }}
                                                </x-ui.dropdown.item>
                                            @endif
                                        @endif
                                    </x-ui.dropdown>
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>

            <!-- In-Meeting Chat Drawer (with Scoping & Pinning) -->
            @if ($showChatDrawer)
                <div class="w-80 bg-zinc-900 border-l border-zinc-800 flex flex-col shrink-0 animate-in slide-in-from-right duration-200 z-30">
                    <div class="p-3.5 border-b border-zinc-800 flex items-center justify-between">
                        <h3 class="font-bold text-xs text-zinc-100 flex items-center gap-1.5">
                            <x-icon name="message-square" class="h-4 w-4 text-primary" />
                            <span>{{ __('In-Meeting Chat') }}</span>
                        </h3>
                        <button type="button" wire:click="$set('showChatDrawer', false)" class="text-zinc-400 hover:text-white cursor-pointer">
                            <x-icon name="x" class="h-4 w-4" />
                        </button>
                    </div>

                    <!-- Pinned In-Room Message Notice -->
                    @if ($pinnedInRoomIndex !== null && isset($inRoomChatLogs[$pinnedInRoomIndex]))
                        @php $pinnedItem = $inRoomChatLogs[$pinnedInRoomIndex]; @endphp
                        <div class="px-3 py-2 bg-amber-500/10 border-b border-amber-500/20 flex items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <x-icon name="pin" class="h-3.5 w-3.5 text-amber-400 shrink-0" />
                                <div class="min-w-0 text-[11px]">
                                    <p class="font-bold text-amber-300 truncate">{{ __('Pinned:') }} <span class="font-normal text-zinc-300">{{ $pinnedItem['user_name'] }}</span></p>
                                    <p class="text-zinc-300 truncate">{{ $pinnedItem['body'] }}</p>
                                </div>
                            </div>
                            @if ($isHostOrCoHost)
                                <button type="button" wire:click="unpinInRoomMessage" class="p-1 text-zinc-400 hover:text-white cursor-pointer" title="{{ __('Unpin') }}">
                                    <x-icon name="pin-off" class="h-3 w-3" />
                                </button>
                            @endif
                        </div>
                    @endif

                    <!-- Audience Scope Selector -->
                    <div class="px-3 py-2 border-b border-zinc-800 bg-zinc-950/60 text-xs">
                        <label class="block text-[10px] font-semibold text-zinc-400 mb-1">{{ __('Send To:') }}</label>
                        <select
                            wire:model="inRoomChatRecipient"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-2 py-1 text-xs text-zinc-200 outline-none"
                        >
                            <option value="all">{{ __('Everyone') }}</option>
                            <option value="hosts_only">{{ __('Host & Co-Hosts Only') }}</option>
                            <optgroup label="{{ __('Direct Participant') }}">
                                @foreach ($activeParticipants as $part)
                                    @if ((int) $part->user_id !== (int) $currentUser->id)
                                        <option value="{{ $part->user_id }}">{{ $part->displayName() }}</option>
                                    @endif
                                @endforeach
                            </optgroup>
                        </select>
                    </div>

                    <!-- Chat Message List -->
                    <div class="flex-1 overflow-y-auto p-3 space-y-3 scrollbar-thin scrollbar-thumb-zinc-700 text-xs">
                        @forelse ($inRoomChatLogs as $cIndex => $chat)
                            <div class="space-y-1 group relative {{ $chat['is_self'] ? 'text-right' : 'text-left' }}">
                                <div class="flex items-center gap-1.5 {{ $chat['is_self'] ? 'justify-end' : 'justify-start' }}">
                                    <span class="font-bold text-zinc-300 text-[11px]">{{ $chat['user_name'] }}</span>
                                    <span class="text-[9px] text-zinc-500">{{ $chat['time'] }}</span>
                                    @if ($isHostOrCoHost)
                                        <button
                                            type="button"
                                            wire:click="{{ $pinnedInRoomIndex === $cIndex ? 'unpinInRoomMessage' : 'pinInRoomMessage('.$cIndex.')' }}"
                                            class="opacity-0 group-hover:opacity-100 p-0.5 text-zinc-400 hover:text-amber-400 transition-opacity cursor-pointer"
                                            title="{{ $pinnedInRoomIndex === $cIndex ? __('Unpin') : __('Pin') }}"
                                        >
                                            <x-icon :name="$pinnedInRoomIndex === $cIndex ? 'pin-off' : 'pin'" class="h-3 w-3" />
                                        </button>
                                    @endif
                                </div>
                                <div class="inline-block px-3 py-1.5 rounded-xl max-w-[85%] break-words {{ $chat['is_self'] ? 'bg-primary text-primary-foreground' : 'bg-zinc-800 text-zinc-200' }}">
                                    <p class="text-[9px] opacity-75 font-semibold mb-0.5">{{ $chat['recipient_label'] }}</p>
                                    <p>{{ $chat['body'] }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-center py-12 text-zinc-500 italic">{{ __('No messages yet.') }}</p>
                        @endforelse
                    </div>

                    <!-- Chat Composer -->
                    @if ($meeting->isChatAllowed() || $isHostOrCoHost)
                        <div class="p-2.5 bg-zinc-950 border-t border-zinc-800 flex items-center gap-1.5">
                            <input
                                type="text"
                                wire:model="inRoomMessage"
                                wire:keydown.enter="sendRoomMessage"
                                placeholder="{{ __('Type message…') }}"
                                class="flex-1 rounded-xl border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-xs text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                            <button
                                type="button"
                                wire:click="sendRoomMessage"
                                class="p-2 rounded-xl bg-primary text-primary-foreground hover:opacity-90 cursor-pointer"
                            >
                                <x-icon name="send" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    @else
                        <div class="p-3 bg-zinc-950 border-t border-zinc-800 text-center text-xs text-zinc-500 italic">
                            {{ __('Chat is disabled by host.') }}
                        </div>
                    @endif
                </div>
            @endif

            <!-- Host Security & Controls Drawer -->
            @if ($showControlsDrawer && $isHostOrCoHost)
                <div class="w-80 bg-zinc-900 border-l border-zinc-800 flex flex-col shrink-0 animate-in slide-in-from-right duration-200 z-30 p-4 space-y-4 text-xs overflow-y-auto">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="font-bold text-zinc-100 flex items-center gap-1.5">
                            <x-icon name="shield" class="h-4 w-4 text-amber-400" />
                            <span>{{ __('Host Room Controls') }}</span>
                        </h3>
                        <button type="button" wire:click="$set('showControlsDrawer', false)" class="text-zinc-400 hover:text-white">
                            <x-icon name="x" class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="space-y-3">
                        <span class="font-semibold text-zinc-300 block uppercase tracking-wider text-[10px]">{{ __('Participant Permissions') }}</span>

                        <!-- Toggle Chat -->
                        <div class="flex items-center justify-between p-2.5 rounded-xl border border-zinc-800 bg-zinc-950">
                            <div>
                                <p class="font-semibold text-zinc-200">{{ __('Allow In-Meeting Chat') }}</p>
                                <p class="text-[10px] text-zinc-400">{{ __('Participants can send messages') }}</p>
                            </div>
                            <x-ui.switch :checked="$meeting->isChatAllowed()" wire:click="toggleRestriction('chat_enabled')" />
                        </div>

                        <!-- Toggle Screen Sharing -->
                        <div class="flex items-center justify-between p-2.5 rounded-xl border border-zinc-800 bg-zinc-950">
                            <div>
                                <p class="font-semibold text-zinc-200">{{ __('Allow Screen Sharing') }}</p>
                                <p class="text-[10px] text-zinc-400">{{ __('Participants can share screens') }}</p>
                            </div>
                            <x-ui.switch :checked="$meeting->isScreenShareAllowed()" wire:click="toggleRestriction('screen_share_enabled')" />
                        </div>

                        <!-- Toggle Emoji Reactions -->
                        <div class="flex items-center justify-between p-2.5 rounded-xl border border-zinc-800 bg-zinc-950">
                            <div>
                                <p class="font-semibold text-zinc-200">{{ __('Allow Emoji Reactions') }}</p>
                                <p class="text-[10px] text-zinc-400">{{ __('Floating emoji reactions') }}</p>
                            </div>
                            <x-ui.switch :checked="$meeting->isEmojiAllowed()" wire:click="toggleRestriction('emoji_enabled')" />
                        </div>
                    </div>

                    <!-- Global Broadcast Actions -->
                    <div class="space-y-2 pt-2 border-t border-zinc-800">
                        <span class="font-semibold text-zinc-300 block uppercase tracking-wider text-[10px]">{{ __('Quick Room Actions') }}</span>
                        <button
                            type="button"
                            wire:click="muteAllParticipants"
                            class="w-full py-2 px-3 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 font-semibold flex items-center justify-center gap-1.5 cursor-pointer"
                        >
                            <x-icon name="mic-off" class="h-4 w-4 text-rose-400" />
                            <span>{{ __('Mute All Participants') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="turnOffAllVideos"
                            class="w-full py-2 px-3 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 font-semibold flex items-center justify-center gap-1.5 cursor-pointer"
                        >
                            <x-icon name="video-off" class="h-4 w-4 text-rose-400" />
                            <span>{{ __('Turn Off Video for All') }}</span>
                        </button>
                    </div>
                </div>
            @endif

            <!-- Meeting Info & Details Drawer -->
            @if ($showInfoDrawer)
                <div class="w-80 bg-zinc-900 border-l border-zinc-800 flex flex-col shrink-0 animate-in slide-in-from-right duration-200 z-30 p-4 space-y-4 text-xs overflow-y-auto">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <h3 class="font-bold text-zinc-100">{{ __('Meeting Details') }}</h3>
                        <button type="button" wire:click="$set('showInfoDrawer', false)" class="text-zinc-400 hover:text-white">
                            <x-icon name="x" class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="space-y-2">
                        <p><strong class="text-zinc-400">{{ __('Topic:') }}</strong> <span class="text-zinc-200 font-semibold">{{ $meeting->title }}</span></p>
                        <p><strong class="text-zinc-400">{{ __('Host:') }}</strong> <span class="text-zinc-200">{{ $meeting->host?->name }}</span></p>
                        <p><strong class="text-zinc-400">{{ __('Scheduled:') }}</strong> <span class="text-zinc-200">{{ $meeting->formattedScheduledAt() }}</span></p>
                        @if ($meeting->description)
                            <p class="text-zinc-400 mt-2">{{ $meeting->description }}</p>
                        @endif
                    </div>

                    <!-- Invite Link Copy -->
                    <div class="space-y-1 pt-2 border-t border-zinc-800">
                        <label class="block font-semibold text-zinc-300">{{ __('Invite Link') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input
                                type="text"
                                readonly
                                value="{{ $meeting->join_url }}"
                                class="flex-1 rounded-lg border border-zinc-700 bg-zinc-950 px-2.5 py-1.5 text-[11px] font-mono text-zinc-300 select-all outline-none"
                            />
                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText('{{ $meeting->join_url }}')"
                                class="p-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 cursor-pointer"
                                title="{{ __('Copy Link') }}"
                            >
                                <x-icon name="copy" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Bottom Controls Bar -->
        <div class="h-16 px-4 bg-zinc-900 border-t border-zinc-800 flex items-center justify-between gap-4 shrink-0 z-20 relative">
            <!-- Left Info -->
            <div class="flex items-center gap-2">
                <span class="text-xs text-zinc-400 hidden sm:inline truncate max-w-[160px]">{{ $meeting->title }}</span>
            </div>

            <!-- Center Controls (Mute, Video, Screen, Reactions, Leave) -->
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Mute / Unmute Microphone -->
                <button
                    type="button"
                    x-on:click="toggleMic()"
                    class="p-3 rounded-full transition-transform active:scale-95 cursor-pointer shadow-md"
                    :class="micMuted ? 'bg-rose-500/20 text-rose-400 border border-rose-500/40' : 'bg-zinc-800 hover:bg-zinc-700 text-zinc-100'"
                    :title="micMuted ? '{{ __('Unmute Microphone') }}' : '{{ __('Mute Microphone') }}'"
                >
                    <x-icon name="mic" class="h-5 w-5" x-show="!micMuted" />
                    <x-icon name="mic-off" class="h-5 w-5" x-show="micMuted" />
                </button>

                <!-- Video On / Off -->
                <button
                    type="button"
                    x-on:click="toggleVideo()"
                    class="p-3 rounded-full transition-transform active:scale-95 cursor-pointer shadow-md"
                    :class="videoOff ? 'bg-rose-500/20 text-rose-400 border border-rose-500/40' : 'bg-zinc-800 hover:bg-zinc-700 text-zinc-100'"
                    :title="videoOff ? '{{ __('Start Video') }}' : '{{ __('Stop Video') }}'"
                >
                    <x-icon name="video" class="h-5 w-5" x-show="!videoOff" />
                    <x-icon name="video-off" class="h-5 w-5" x-show="videoOff" />
                </button>

                <!-- Screen Sharing -->
                @if ($meeting->isScreenShareAllowed() || $isHostOrCoHost)
                    <button
                        type="button"
                        x-on:click="toggleScreenShare()"
                        class="p-3 rounded-full transition-transform active:scale-95 cursor-pointer shadow-md"
                        :class="screenSharing ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40' : 'bg-zinc-800 hover:bg-zinc-700 text-zinc-100'"
                        title="{{ __('Share Screen') }}"
                    >
                        <x-icon name="screen-share" class="h-5 w-5" />
                    </button>
                @endif

                <!-- Emoji Reactions Popover -->
                @if ($meeting->isEmojiAllowed() || $isHostOrCoHost)
                    <div class="relative">
                        <button
                            type="button"
                            x-on:click="showEmojiMenu = !showEmojiMenu; showEndMeetingMenu = false;"
                            class="p-3 rounded-full bg-zinc-800 hover:bg-zinc-700 text-zinc-100 transition-transform active:scale-95 cursor-pointer shadow-md"
                            title="{{ __('Reactions') }}"
                        >
                            <x-icon name="smile" class="h-5 w-5" />
                        </button>

                        <!-- Custom Popover Menu -->
                        <div
                            x-show="showEmojiMenu"
                            x-on:click.outside="showEmojiMenu = false"
                            x-cloak
                            class="absolute bottom-16 left-1/2 -translate-x-1/2 z-50 w-64 rounded-2xl border border-zinc-700 bg-zinc-900/95 backdrop-blur-md p-3 shadow-2xl space-y-2 animate-in fade-in zoom-in duration-150"
                        >
                            <div class="flex items-center justify-between text-xs font-semibold text-zinc-300 border-b border-zinc-800 pb-1.5">
                                <span>{{ __('Live Reactions') }}</span>
                                <button type="button" x-on:click="showEmojiMenu = false" class="text-zinc-400 hover:text-white">
                                    <x-icon name="x" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div class="grid grid-cols-4 gap-2 text-2xl text-center">
                                @foreach (['👏', '🎉', '❤️', '🔥', '👍', '✋', '😂', '😮'] as $rEmoji)
                                    <button
                                        type="button"
                                        x-on:click="showEmojiMenu = false"
                                        wire:click="sendReaction('{{ $rEmoji }}')"
                                        class="p-2 rounded-xl hover:bg-zinc-800 flex items-center justify-center transition-transform hover:scale-130 active:scale-95 cursor-pointer"
                                    >
                                        {{ $rEmoji }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Leave / End Meeting Popover (with Modals) -->
                <div class="relative">
                    @if ($isHostOrCoHost)
                        <button
                            type="button"
                            x-on:click="showEndMeetingMenu = !showEndMeetingMenu; showEmojiMenu = false;"
                            class="px-4 py-2.5 rounded-full bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs flex items-center gap-1.5 shadow-md cursor-pointer transition-transform active:scale-95"
                        >
                            <x-icon name="phone-off" class="h-4 w-4" />
                            <span>{{ __('End / Leave') }}</span>
                            <x-icon name="chevron-up" class="h-3 w-3 ml-0.5" />
                        </button>

                        <div
                            x-show="showEndMeetingMenu"
                            x-on:click.outside="showEndMeetingMenu = false"
                            x-cloak
                            class="absolute bottom-16 right-0 z-50 w-60 rounded-2xl border border-zinc-700 bg-zinc-900/95 backdrop-blur-md p-2 shadow-2xl space-y-1 animate-in fade-in zoom-in duration-150"
                        >
                            <button
                                type="button"
                                wire:click="promptLeaveMeeting"
                                x-on:click="showEndMeetingMenu = false"
                                class="w-full text-left px-3 py-2 rounded-xl text-xs text-zinc-200 hover:bg-zinc-800 flex items-center gap-2 transition-colors cursor-pointer"
                            >
                                <x-icon name="phone-off" class="h-4 w-4 text-zinc-400" />
                                <div>
                                    <p class="font-semibold">{{ __('Leave Meeting') }}</p>
                                    <p class="text-[10px] text-zinc-500">{{ __('Keep meeting open for others') }}</p>
                                </div>
                            </button>
                            <button
                                type="button"
                                wire:click="promptEndMeetingForAll"
                                x-on:click="showEndMeetingMenu = false"
                                class="w-full text-left px-3 py-2 rounded-xl text-xs text-rose-400 hover:bg-rose-500/10 flex items-center gap-2 transition-colors cursor-pointer font-bold"
                            >
                                <x-icon name="trash" class="h-4 w-4 text-rose-500" />
                                <div>
                                    <p>{{ __('End Meeting for All') }}</p>
                                    <p class="text-[10px] text-rose-400/70">{{ __('Terminate room for all') }}</p>
                                </div>
                            </button>
                        </div>
                    @else
                        <button
                            type="button"
                            wire:click="promptLeaveMeeting"
                            class="px-4 py-2.5 rounded-full bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs flex items-center gap-1.5 shadow-md cursor-pointer transition-transform active:scale-95"
                        >
                            <x-icon name="phone-off" class="h-4 w-4" />
                            <span>{{ __('Leave') }}</span>
                        </button>
                    @endif
                </div>
            </div>

            <!-- Right Spacer for Balance -->
            <div class="flex items-center gap-2">
                <!-- Clean spacer -->
            </div>
        </div>
    @endif

    <!-- Confirmation Modal: Leave Meeting -->
    <x-ui.modal name="leave-meeting-modal" max-width="max-w-md" title="{{ __('Leave Meeting') }}">
        <div class="space-y-4 text-center">
            <div class="h-14 w-14 rounded-full bg-amber-500/10 text-amber-500 flex items-center justify-center mx-auto">
                <x-icon name="phone-off" class="h-7 w-7" />
            </div>
            <div class="space-y-1">
                <h3 class="text-base font-bold text-foreground">{{ __('Leave this meeting?') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{ __('Are you sure you want to leave? You can rejoin anytime while the conference remains active.') }}
                </p>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button x-on:click="$store.modals.close('leave-meeting-modal')" variant="secondary">
                    {{ __('Stay in Meeting') }}
                </x-ui.button>
                <x-ui.button wire:click="executeLeaveMeeting" x-on:click="stopAllMedia()" variant="default" icon="phone-off">
                    {{ __('Leave Now') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Confirmation Modal: End Meeting for All -->
    <x-ui.modal name="end-all-modal" max-width="max-w-md" title="{{ __('End Meeting for All') }}">
        <div class="space-y-4 text-center">
            <div class="h-14 w-14 rounded-full bg-rose-500/10 text-rose-500 flex items-center justify-center mx-auto">
                <x-icon name="alert-triangle" class="h-7 w-7" />
            </div>
            <div class="space-y-1">
                <h3 class="text-base font-bold text-foreground">{{ __('End meeting for everyone?') }}</h3>
                <p class="text-xs text-muted-foreground">
                    {{ __('This action will immediately disconnect all participants and terminate the meeting session.') }}
                </p>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button x-on:click="$store.modals.close('end-all-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="executeEndMeetingForAll" x-on:click="stopAllMedia()" variant="destructive" icon="trash">
                    {{ __('End Meeting for All') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
