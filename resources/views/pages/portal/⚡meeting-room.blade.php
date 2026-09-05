<?php

use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\MeetingService;
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
    public bool $soundMuted = false;

    // Pre-Join AV State
    public bool $inPreJoinLobby = true;
    public bool $preJoinMicEnabled = true;
    public bool $preJoinVideoEnabled = true;

    // In-Meeting Chat with Scoping, Pinning & Moderation
    public string $inRoomMessage = '';
    public string $inRoomChatRecipient = 'all'; // all, hosts_only, or "user_id"
    public array $inRoomChatLogs = [];
    public ?int $pinnedInRoomIndex = null;
    public ?int $editingInRoomIndex = null;
    public string $editingInRoomText = '';

    // Meeting Slug Customization
    public string $editMeetingSlug = '';

    // Emoji Reactions
    public array $activeFloatingReactions = [];
    public float $lastReactionTimestamp = 0.0;

    // Ice Servers
    public array $iceServers = [];

    public ?int $spotlightUserId = null;

    public ?string $spotlightUserName = null;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->lastReactionTimestamp = microtime(true) - 1.0;
        $this->meeting = ChatMeeting::where('uuid', $uuid)->with(['host', 'participants.user'])->firstOrFail();

        $user = auth()->user();
        if (! $user) {
            return;
        }

        if ($this->meeting->isEnded() || $this->meeting->isCancelled()) {
            Toast::dispatch($this, 'warning', __('This meeting has already ended or was cancelled.'));
            $this->redirectRoute('meetings.index', navigate: true);
            return;
        }

        $existing = ChatMeetingParticipant::withTrashed()
            ->where('meeting_id', $this->meeting->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->trashed()) {
            $existing->restore();
        }

        if (! $existing) {
            $isHost = $this->meeting->isHost($user);
            $initialStatus = $isHost ? ChatMeetingParticipant::STATUS_INVITED : ($this->meeting->isOpenForEveryone() ? ChatMeetingParticipant::STATUS_INVITED : ChatMeetingParticipant::STATUS_WAITING);
            $existing = ChatMeetingParticipant::create([
                'meeting_id' => $this->meeting->id,
                'user_id' => $user->id,
                'role' => $isHost ? ChatMeetingParticipant::ROLE_HOST : ChatMeetingParticipant::ROLE_PARTICIPANT,
                'status' => $initialStatus,
            ]);
        }
        $this->participant = $existing;
        if ($existing->status === ChatMeetingParticipant::STATUS_JOINED) {
            $this->inPreJoinLobby = false;
        }

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);
        $this->iceServers = $meetingService->getIceServers();
    }

    public function joinRoomFromLobby(): void
    {
        $user = auth()->user();
        if (! $this->meeting || ! $user) {
            return;
        }

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);
        $this->participant = $meetingService->joinMeeting($this->meeting, $user);

        $this->inPreJoinLobby = false;
        $this->isMuted = ! $this->preJoinMicEnabled;
        $this->isVideoOff = ! $this->preJoinVideoEnabled;
        $this->refreshRoom();
        $this->js("\$dispatch('apply-prejoin-settings', { mic: " . ($this->preJoinMicEnabled ? 'true' : 'false') . ", video: " . ($this->preJoinVideoEnabled ? 'true' : 'false') . " })");
    }

    public function spotlightParticipant(int $userId): void
    {
        $user = auth()->user();
        if (! $this->meeting->isHostOrCoHost($user)) {
            Toast::dispatch($this, 'error', __('Only hosts and co-hosts can spotlight a participant.'));
            return;
        }

        $target = User::find($userId);
        $this->spotlightUserId = $userId;
        $this->spotlightUserName = $target?->name ?? __('Participant');

        try {
            event(new \App\Events\MeetingRealtimeEvent(
                meetingUuid: $this->meeting->uuid,
                eventType: 'spotlight_updated',
                payload: [
                    'spotlight_user_id' => $userId,
                    'spotlight_user_name' => $this->spotlightUserName,
                    'by_name' => $user->name,
                ],
                senderUserId: $user->id
            ));
            Toast::dispatch($this, 'success', __('Spotlighted :name for all participants.', ['name' => $this->spotlightUserName]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function removeSpotlight(): void
    {
        $user = auth()->user();
        if (! $this->meeting->isHostOrCoHost($user)) {
            return;
        }

        $this->spotlightUserId = null;
        $this->spotlightUserName = null;

        try {
            event(new \App\Events\MeetingRealtimeEvent(
                meetingUuid: $this->meeting->uuid,
                eventType: 'spotlight_updated',
                payload: [
                    'spotlight_user_id' => null,
                    'spotlight_user_name' => null,
                    'by_name' => $user->name,
                ],
                senderUserId: $user->id
            ));
            Toast::dispatch($this, 'info', __('Spotlight removed for all participants.'));
        } catch (\Throwable $e) {}
    }

    public function toggleMuteSound(): void
    {
        $this->soundMuted = ! $this->soundMuted;
    }

    public function refreshRoom(): void
    {
        $this->meeting = ChatMeeting::where('uuid', $this->uuid)->with(['host', 'participants.user'])->first();
        if ($this->participant) {
            $this->participant->refresh();
        }

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);
        $recentReactions = $meetingService->getRecentReactions($this->uuid, $this->lastReactionTimestamp);
        if (! empty($recentReactions)) {
            $currentUserId = auth()->id();
            foreach ($recentReactions as $rx) {
                $this->lastReactionTimestamp = max($this->lastReactionTimestamp, (float) ($rx['created_at'] ?? 0));
                if ((int) ($rx['sender_id'] ?? 0) !== (int) $currentUserId) {
                    $emoji = addslashes($rx['emoji'] ?? '👍');
                    $userName = addslashes($rx['user'] ?? $rx['user_name'] ?? 'User');
                    $this->dispatch('trigger-floating-emoji', emoji: $rx['emoji'] ?? '👍', user: $rx['user'] ?? $rx['user_name'] ?? 'User');
                    $this->js("\$dispatch('trigger-floating-emoji', { emoji: '{$emoji}', user: '{$userName}' })");
                }
            }
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

        $logEntry = [
            'user_name' => $user->name,
            'user_id' => $user->id,
            'avatar' => $user->avatarUrl(),
            'body' => $text,
            'recipient' => $this->inRoomChatRecipient,
            'recipient_label' => $recipientLabel,
            'time' => now()->format('h:i A'),
            'is_self' => true,
            'is_edited' => false,
        ];

        $this->inRoomChatLogs[] = $logEntry;

        try {
            event(new \App\Events\MeetingRealtimeEvent(
                meetingUuid: $this->meeting->uuid,
                eventType: 'in_room_chat',
                payload: ['log' => $logEntry],
                senderUserId: $user->id
            ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('Meeting chat broadcast fallback: '.$e->getMessage());
        }

        $this->inRoomMessage = '';
        $this->js("\$dispatch('play-inroom-chime')");
    }

    public function startEditInRoomMessage(int $index): void
    {
        if (! isset($this->inRoomChatLogs[$index])) {
            return;
        }
        $log = $this->inRoomChatLogs[$index];
        $user = auth()->user();
        $isHost = $this->meeting->isHostOrCoHost($user);
        $isSelf = (int) ($log['user_id'] ?? 0) === (int) $user->id;

        if (! $isHost && (! $isSelf || ! $this->meeting->canParticipantEditDeleteChat())) {
            Toast::dispatch($this, 'error', __('You do not have permission to edit this message.'));
            return;
        }

        $this->editingInRoomIndex = $index;
        $this->editingInRoomText = $log['body'] ?? '';
    }

    public function saveEditInRoomMessage(): void
    {
        if ($this->editingInRoomIndex === null || ! isset($this->inRoomChatLogs[$this->editingInRoomIndex])) {
            return;
        }
        $user = auth()->user();
        $isHost = $this->meeting->isHostOrCoHost($user);
        $log = $this->inRoomChatLogs[$this->editingInRoomIndex];
        $isSelf = (int) ($log['user_id'] ?? 0) === (int) $user->id;

        if (! $isHost && (! $isSelf || ! $this->meeting->canParticipantEditDeleteChat())) {
            return;
        }

        $text = trim($this->editingInRoomText);
        if (! empty($text)) {
            $this->inRoomChatLogs[$this->editingInRoomIndex]['body'] = $text;
            $this->inRoomChatLogs[$this->editingInRoomIndex]['is_edited'] = true;
            Toast::dispatch($this, 'success', __('Message updated.'));
        }

        $this->editingInRoomIndex = null;
        $this->editingInRoomText = '';
    }

    public function cancelEditInRoomMessage(): void
    {
        $this->editingInRoomIndex = null;
        $this->editingInRoomText = '';
    }

    public function deleteInRoomMessage(int $index): void
    {
        if (! isset($this->inRoomChatLogs[$index])) {
            return;
        }
        $user = auth()->user();
        $isHost = $this->meeting->isHostOrCoHost($user);
        $log = $this->inRoomChatLogs[$index];
        $isSelf = (int) ($log['user_id'] ?? 0) === (int) $user->id;

        if (! $isHost && (! $isSelf || ! $this->meeting->canParticipantEditDeleteChat())) {
            Toast::dispatch($this, 'error', __('You do not have permission to delete this message.'));
            return;
        }

        array_splice($this->inRoomChatLogs, $index, 1);
        if ($this->pinnedInRoomIndex === $index) {
            $this->pinnedInRoomIndex = null;
        } elseif ($this->pinnedInRoomIndex > $index) {
            $this->pinnedInRoomIndex--;
        }
        Toast::dispatch($this, 'info', __('Message removed from meeting chat.'));
    }

    public function openEditSlugModal(): void
    {
        $user = auth()->user();
        if (! $this->meeting->isHostOrCoHost($user)) {
            Toast::dispatch($this, 'error', __('Only host or co-hosts can customize the meeting slug.'));
            return;
        }
        $this->editMeetingSlug = $this->meeting->invite_code;
        $this->js("\$store.modals.open('edit-slug-modal')");
    }

    public function saveMeetingSlug(): void
    {
        $user = auth()->user();
        if (! $this->meeting->isHostOrCoHost($user)) {
            return;
        }

        $slug = \Illuminate\Support\Str::slug($this->editMeetingSlug);
        if (empty($slug)) {
            Toast::dispatch($this, 'error', __('Meeting slug cannot be empty.'));
            return;
        }

        $exists = ChatMeeting::where('invite_code', $slug)->where('id', '!=', $this->meeting->id)->exists();
        if ($exists) {
            Toast::dispatch($this, 'error', __('This meeting link slug is already taken. Please choose another.'));
            return;
        }

        $this->meeting->update(['invite_code' => $slug]);
        $this->refreshRoom();
        $this->js("\$store.modals.close('edit-slug-modal')");
        Toast::dispatch($this, 'success', __('Meeting link slug updated successfully!'));
    }

    public function pinInRoomMessage(int $index): void
    {
        if ($this->pinnedInRoomIndex === $index) {
            $this->pinnedInRoomIndex = null;
            Toast::dispatch($this, 'info', __('Message unpinned.'));
        } else {
            $this->pinnedInRoomIndex = $index;
            Toast::dispatch($this, 'success', __('Message pinned to room header!'));
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
        if (! $user) {
            return;
        }

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);
        $reaction = $meetingService->recordReaction($this->meeting->uuid, $user, $emoji);

        $this->activeFloatingReactions[] = $reaction;

        // Keep last 15 reactions
        if (count($this->activeFloatingReactions) > 15) {
            array_shift($this->activeFloatingReactions);
        }

        $this->lastReactionTimestamp = max($this->lastReactionTimestamp, (float) ($reaction['created_at'] ?? microtime(true)));
        $this->dispatch('trigger-floating-emoji', emoji: $emoji, user: $user->name);
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

    public function receiveInRoomMessage(array $log): void
    {
        $currentUser = auth()->user();
        if (! $currentUser) {
            return;
        }

        $recipient = (string) ($log['recipient'] ?? 'all');
        $isHost = $this->meeting->isHostOrCoHost($currentUser);
        $isTargetUser = is_numeric($recipient) && (int) $recipient === (int) $currentUser->id;

        if ($recipient !== 'all' && ! ($recipient === 'hosts_only' && $isHost) && ! $isTargetUser) {
            return;
        }

        $isSelf = (int) ($log['user_id'] ?? 0) === (int) $currentUser->id;
        if ($isSelf) {
            return;
        }

        $log['is_self'] = false;
        $this->inRoomChatLogs[] = $log;
        $this->js("\$dispatch('play-inroom-chime')");
    }

    public function muteAllParticipants(): void
    {
        $user = auth()->user();
        if (! $this->meeting->isHostOrCoHost($user)) {
            Toast::dispatch($this, 'error', __('Unauthorized action.'));
            return;
        }

        try {
            event(new \App\Events\MeetingRealtimeEvent(
                meetingUuid: $this->meeting->uuid,
                eventType: 'force_mute_all',
                payload: ['by_user_id' => $user->id, 'by_name' => $user->name],
                senderUserId: $user->id
            ));
            Toast::dispatch($this, 'info', __('Mute signal broadcast to all participants.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function turnOffAllVideos(): void
    {
        $user = auth()->user();
        if (! $this->meeting->isHostOrCoHost($user)) {
            Toast::dispatch($this, 'error', __('Unauthorized action.'));
            return;
        }

        try {
            event(new \App\Events\MeetingRealtimeEvent(
                meetingUuid: $this->meeting->uuid,
                eventType: 'force_video_off_all',
                payload: ['by_user_id' => $user->id, 'by_name' => $user->name],
                senderUserId: $user->id
            ));
            Toast::dispatch($this, 'info', __('Video stop signal broadcast to all participants.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function sendMeetingSignal(string $signalType, array $payload = [], ?int $targetUserId = null): void
    {
        $user = auth()->user();
        if (! $this->meeting || ! $user) {
            return;
        }

        try {
            /** @var MeetingService $meetingService */
            $meetingService = app(MeetingService::class);
            $meetingService->sendSignal(
                meetingUuid: $this->meeting->uuid,
                sender: $user,
                signalType: $signalType,
                payload: $payload,
                targetUserId: $targetUserId
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('Meeting signal broadcast fallback: '.$e->getMessage());
        }
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

        $user = auth()->user();
        if ($this->participant) {
            try {
                \Illuminate\Support\Facades\DB::transaction(function () {
                    $this->participant->update([
                        'status' => ChatMeetingParticipant::STATUS_LEFT,
                        'left_at' => now(),
                    ]);
                });

                if ($this->meeting && $user) {
                    \App\Services\AuditLogService::log(
                        event: 'meeting_participant_left',
                        description: "{$user->name} left meeting '{$this->meeting->title}' (#{$this->meeting->id})",
                        auditable: $this->meeting,
                        userId: $user->id
                    );
                }
            } catch (\Throwable $e) {}
        }

        try {
            event(new \App\Events\MeetingRealtimeEvent(
                meetingUuid: $this->meeting->uuid,
                eventType: 'participant_left',
                payload: [
                    'participant_id' => $this->participant?->id,
                    'user_id' => auth()->id(),
                    'user_name' => auth()->user()?->name,
                ],
                senderUserId: auth()->id()
            ));
        } catch (\Throwable $e) {}

        Toast::dispatch($this, 'info', __('You left the meeting.'));
        $this->redirectRoute('meetings.index', navigate: false);
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
                $this->redirectRoute('meetings.index', navigate: false);
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

        $transportDriver = (string) Setting::get('chat.transport_driver', 'hybrid');
        $pollInterval = match ($transportDriver) {
            'broadcasting' => '15s',
            'hybrid' => '8s',
            default => '4s',
        };

        return [
            'currentUser' => $user,
            'isHostOrCoHost' => $isHostOrCoHost,
            'activeParticipants' => $activeParticipants,
            'waitingParticipants' => $waitingParticipants,
            'pollInterval' => $pollInterval,
        ];
    }
};
?>

<div
    id="meeting-root-container"
    wire:poll.visible.{{ $pollInterval }}="refreshRoom"
    x-data="meetingRoomAlpine({
        isVideo: {{ $meeting->isVideo() ? 'true' : 'false' }},
        isHostOrCoHost: {{ $isHostOrCoHost ? 'true' : 'false' }},
        isScreenShareAllowed: {{ $meeting->isScreenShareAllowed() ? 'true' : 'false' }},
        inPreJoinLobby: {{ $inPreJoinLobby ? 'true' : 'false' }},
        currentUserId: {{ (int) ($currentUser->id ?? 0) }},
        currentUserName: '{{ addslashes($currentUser->name ?? '') }}',
        meetingUuid: '{{ $meeting->uuid }}',
        signalUrl: '{{ route('meetings.signal.direct', ['uuid' => $meeting->uuid]) }}',
        syncUrl: '{{ route('meetings.sync.direct', ['uuid' => $meeting->uuid]) }}',
        iceServers: @js($iceServers)
    })"
    x-bind:class="isFullscreen ? '!fixed !inset-0 !h-screen !w-screen !z-50 !rounded-none !border-0' : ''"
    class="flex flex-col h-[calc(100vh-8.5rem)] min-h-[550px] rounded-2xl border border-border bg-zinc-950 text-white overflow-hidden shadow-2xl relative"
>
    <!-- 1. Pre-Join AV Setup Lobby -->
    @if ($inPreJoinLobby && (! $participant || ! $participant->isWaiting()))
        <div class="flex-1 flex flex-col items-center justify-center p-4 sm:p-6 bg-zinc-950 text-white z-50 overflow-y-auto">
            <div class="w-full max-w-xl rounded-2xl border border-zinc-800 bg-zinc-900 shadow-2xl p-5 sm:p-6 space-y-5 text-center">
                <!-- Heading -->
                <div>
                    <h2 class="text-xl font-bold text-zinc-100">{{ __('Ready to join?') }}</h2>
                    <p class="text-xs text-zinc-400 mt-1">{{ $meeting->title }} • {{ $meeting->formattedScheduledAt() }}</p>
                </div>

                <!-- Hardware & Resource Diagnostic Alert Banner in Pre-Join Lobby -->
                <div
                    x-show="hardwareNotice.show"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="rounded-xl p-3 border text-xs flex items-center justify-between gap-3 text-left"
                    :class="{
                        'bg-amber-500/15 border-amber-500/30 text-amber-200': hardwareNotice.type === 'warning',
                        'bg-rose-500/15 border-rose-500/30 text-rose-200': hardwareNotice.type === 'danger',
                        'bg-blue-500/15 border-blue-500/30 text-blue-200': hardwareNotice.type === 'info'
                    }"
                >
                    <div class="flex items-center gap-2 truncate">
                        <x-icon name="alert-triangle" class="h-4 w-4 shrink-0 text-amber-400" x-show="hardwareNotice.type === 'warning'" />
                        <x-icon name="alert-circle" class="h-4 w-4 shrink-0 text-rose-400" x-show="hardwareNotice.type === 'danger'" />
                        <span class="font-medium" x-text="hardwareNotice.message"></span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
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

                <!-- Video / Audio Preview Card -->
                <div class="relative w-full aspect-video rounded-xl bg-zinc-950 border border-zinc-800 overflow-hidden flex items-center justify-center shadow-inner group">
                    <video
                        wire:ignore
                        x-ref="localVideo"
                        autoplay
                        playsinline
                        muted
                        class="w-full h-full object-cover transition-transform duration-200"
                        :class="[videoOff ? 'hidden' : 'block', isMirrored ? '-scale-x-100' : '']"
                    ></video>

                    <!-- Avatar fallback if camera is turned off -->
                    <div x-show="videoOff" class="flex flex-col items-center justify-center space-y-2">
                        <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-20 text-2xl shadow-lg" />
                        <span class="text-xs font-semibold text-zinc-400">{{ __('Camera is off') }}</span>
                    </div>

                    <!-- Live Mic Volume Activity Indicator Badge on Top Left -->
                    <div class="absolute top-3 left-3 flex items-center gap-2 px-2.5 py-1 rounded-full bg-zinc-900/80 border border-zinc-700/60 backdrop-blur-md text-[11px] text-zinc-200 z-30">
                        <span class="h-2 w-2 rounded-full" :class="micMuted ? 'bg-rose-500' : (localAudioLevel > 5 ? 'bg-emerald-500 animate-ping' : 'bg-emerald-500')"></span>
                        <span x-text="micMuted ? '{{ __('Muted') }}' : '{{ __('Mic Active') }}'"></span>
                        <!-- Multi-bar Live Waveform in Video Preview -->
                        <div x-show="!micMuted" class="flex items-center gap-0.5 h-3 ml-1">
                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.15)}px`"></span>
                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.3)}px`"></span>
                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.18)}px`"></span>
                        </div>
                    </div>

                    <!-- Mirror Flip Toggle Badge on Top Right -->
                    <div x-show="!videoOff" class="absolute top-3 right-3 z-30">
                        <button
                            type="button"
                            @click="toggleMirror()"
                            :class="isMirrored ? 'bg-primary text-primary-foreground' : 'bg-zinc-900/80 text-zinc-300 hover:text-white border border-zinc-700/60'"
                            class="px-2.5 py-1 rounded-full backdrop-blur-md text-[10px] font-semibold flex items-center gap-1 transition-all cursor-pointer shadow-sm"
                            title="{{ __('Flip Camera View') }}"
                        >
                            <x-icon name="flip-horizontal" class="h-3 w-3" />
                            <span>{{ __('Mirror') }}</span>
                        </button>
                    </div>

                    <!-- Bottom Floating Toggles -->
                    <div class="absolute bottom-3 inset-x-0 flex items-center justify-center gap-3 z-30">
                        <button
                            type="button"
                            @click="toggleMic()"
                            :class="micMuted ? 'bg-rose-500 text-white hover:bg-rose-600' : (localAudioLevel > 5 ? 'bg-emerald-600 text-white ring-2 ring-emerald-400 hover:bg-emerald-700' : 'bg-zinc-800/90 text-white hover:bg-zinc-700/90')"
                            class="h-11 w-11 rounded-full flex items-center justify-center transition-all hover:scale-105 shadow-md cursor-pointer backdrop-blur-xs"
                            title="{{ __('Toggle Microphone') }}"
                        >
                            <x-icon name="mic" class="h-5 w-5" x-show="!micMuted" />
                            <x-icon name="mic-off" class="h-5 w-5" x-show="micMuted" />
                        </button>

                        <button
                            type="button"
                            @click="toggleVideo()"
                            :class="videoOff ? 'bg-rose-500 text-white hover:bg-rose-600' : 'bg-zinc-800/90 text-white hover:bg-zinc-700/90'"
                            class="h-11 w-11 rounded-full flex items-center justify-center transition-all hover:scale-105 shadow-md cursor-pointer backdrop-blur-xs"
                            title="{{ __('Toggle Camera') }}"
                        >
                            <x-icon name="video" class="h-5 w-5" x-show="!videoOff" />
                            <x-icon name="video-off" class="h-5 w-5" x-show="videoOff" />
                        </button>
                    </div>
                </div>

                <!-- Hardware Device Selection Controls -->
                <div class="rounded-xl bg-zinc-950 border border-zinc-800 p-3.5 space-y-2.5 text-left text-xs">
                    <!-- Microphone Selector -->
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <label class="font-medium text-zinc-300 flex items-center gap-1.5">
                                <x-icon name="mic" class="h-3.5 w-3.5 text-zinc-400" />
                                <span>{{ __('Microphone') }}</span>
                            </label>
                            <div x-show="!micMuted" class="flex items-center gap-0.5 h-3">
                                <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.15)}px`"></span>
                                <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.3)}px`"></span>
                                <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.18)}px`"></span>
                            </div>
                        </div>
                        <select
                            x-model="selectedAudioInput"
                            @change="switchMicrophoneDevice($event.target.value)"
                            class="w-full rounded-lg bg-zinc-900 border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-primary cursor-pointer"
                        >
                            <template x-for="mic in audioInputs" :key="mic.deviceId">
                                <option :value="mic.deviceId" x-text="mic.label" :selected="mic.deviceId === selectedAudioInput"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Camera Selector -->
                    <div class="space-y-1">
                        <label class="font-medium text-zinc-300 flex items-center gap-1.5">
                            <x-icon name="video" class="h-3.5 w-3.5 text-zinc-400" />
                            <span>{{ __('Camera') }}</span>
                        </label>
                        <select
                            x-model="selectedVideoInput"
                            @change="switchCameraDevice($event.target.value)"
                            class="w-full rounded-lg bg-zinc-900 border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-primary cursor-pointer"
                        >
                            <template x-for="cam in videoInputs" :key="cam.deviceId">
                                <option :value="cam.deviceId" x-text="cam.label" :selected="cam.deviceId === selectedVideoInput"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Speaker Output & Test -->
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <label class="font-medium text-zinc-300 flex items-center gap-1.5">
                                <x-icon name="volume-2" class="h-3.5 w-3.5 text-zinc-400" />
                                <span>{{ __('Speaker Output') }}</span>
                            </label>
                            <button
                                type="button"
                                @click="testSpeakerSound()"
                                :disabled="isTestingSpeaker"
                                class="text-[11px] font-semibold text-primary hover:underline flex items-center gap-1 cursor-pointer"
                            >
                                <x-icon name="play-circle" class="h-3 w-3" x-show="!isTestingSpeaker" />
                                <x-icon name="loader-2" class="h-3 w-3 animate-spin text-primary" x-show="isTestingSpeaker" />
                                <span x-text="isTestingSpeaker ? '{{ __('Playing chime…') }}' : '{{ __('Test Speaker') }}'"></span>
                            </button>
                        </div>
                        <select
                            x-model="selectedAudioOutput"
                            @change="switchAudioOutputDevice($event.target.value)"
                            class="w-full rounded-lg bg-zinc-900 border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-primary cursor-pointer"
                        >
                            <template x-for="spk in audioOutputs" :key="spk.deviceId">
                                <option :value="spk.deviceId" x-text="spk.label" :selected="spk.deviceId === selectedAudioOutput"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <!-- Actions -->
                <div class="pt-2 flex items-center justify-center gap-3">
                    <x-ui.button
                        wire:click="promptLeaveMeeting"
                        variant="secondary"
                        size="default"
                        class="px-6 font-semibold !text-zinc-100 !bg-zinc-800 hover:!bg-zinc-700 !border !border-zinc-700 shadow-sm cursor-pointer"
                    >
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="joinRoomFromLobby" variant="default" size="default" icon="video" class="px-6 font-bold shadow-lg">
                        {{ __('Join Now') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    <!-- 2. Waiting Room View (If user is in waiting lobby) -->
    @elseif ($participant && $participant->isWaiting())
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
            <x-ui.button wire:click="promptLeaveMeeting" variant="secondary" size="sm" class="!text-zinc-100 !bg-zinc-800 hover:!bg-zinc-700 !border !border-zinc-700">
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
            <div class="flex items-center gap-1.5 sm:gap-2">
                <!-- Layout Switcher Dropdown -->
                <x-ui.dropdown width="w-48" offset="mt-1">
                    <x-slot:trigger>
                        <button
                            type="button"
                            class="px-2.5 py-1.5 rounded-lg text-zinc-300 hover:text-white bg-zinc-800/80 hover:bg-zinc-700/80 border border-zinc-700 text-xs font-semibold flex items-center gap-1.5 transition-colors cursor-pointer"
                            title="{{ __('Change Screen Layout') }}"
                        >
                            <x-icon name="layout-grid" class="h-3.5 w-3.5" x-show="layoutMode === 'grid'" />
                            <x-icon name="user" class="h-3.5 w-3.5" x-show="layoutMode === 'speaker'" />
                            <x-icon name="layout-sidebar" class="h-3.5 w-3.5" x-show="layoutMode === 'sidebar'" />
                            <span class="hidden md:inline" x-text="layoutMode === 'grid' ? '{{ __('Grid') }}' : (layoutMode === 'speaker' ? '{{ __('Speaker') }}' : '{{ __('Stage') }}')"></span>
                            <x-icon name="chevron-down" class="h-3 w-3 text-zinc-400" />
                        </button>
                    </x-slot:trigger>

                    <x-ui.dropdown.item icon="layout-grid" @click="setLayoutMode('grid')">
                        {{ __('Grid View') }}
                    </x-ui.dropdown.item>
                    <x-ui.dropdown.item icon="user" @click="setLayoutMode('speaker')">
                        {{ __('Speaker Spotlight') }}
                    </x-ui.dropdown.item>
                    <x-ui.dropdown.item icon="layout-sidebar" @click="setLayoutMode('sidebar')">
                        {{ __('Stage + Filmstrip') }}
                    </x-ui.dropdown.item>
                </x-ui.dropdown>

                <!-- Pinned Status Badge in Top Bar -->
                <div x-show="pinnedUserId" x-cloak class="flex items-center gap-1 px-2 py-1 rounded-full bg-primary/20 border border-primary/40 text-primary text-[11px] font-semibold">
                    <x-icon name="pin" class="h-3 w-3" />
                    <span class="hidden sm:inline">{{ __('Pinned') }}</span>
                    <button type="button" @click="unpinUser()" class="hover:text-white cursor-pointer ml-1" title="{{ __('Unpin Screen') }}">
                        <x-icon name="x" class="h-3 w-3" />
                    </button>
                </div>

                <!-- Spotlight for Everyone Badge in Top Bar -->
                <div x-show="spotlightUserId" x-cloak class="flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/20 border border-amber-500/40 text-amber-300 text-[11px] font-semibold">
                    <x-icon name="sparkles" class="h-3 w-3" />
                    <span class="truncate max-w-[120px]" x-text="'{{ __('Spotlight:') }} ' + (spotlightUserName || '{{ __('Participant') }}')"></span>
                    @if ($isHostOrCoHost)
                        <button type="button" wire:click="removeSpotlight" class="hover:text-white cursor-pointer ml-1" title="{{ __('Remove Spotlight for Everyone') }}">
                            <x-icon name="x" class="h-3 w-3" />
                        </button>
                    @endif
                </div>

                <!-- Live WebSocket Status Indicator -->
                <div
                    class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border transition-colors"
                    :class="websocketConnected ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20'"
                    :title="websocketConnected ? '{{ __('Real-time WebSocket Live (Laravel Reverb)') }}' : '{{ __('WebSocket Disconnected / Reconnecting') }}'"
                >
                    <span class="relative flex h-2 w-2">
                        <span x-show="websocketConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2" :class="websocketConnected ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                    </span>
                    <span x-text="websocketConnected ? '{{ __('Reverb Live') }}' : '{{ __('Offline') }}'"></span>
                </div>

                <!-- Sound Notification Toggle -->
                <button
                    type="button"
                    wire:click="toggleMuteSound"
                    class="p-2 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer"
                    title="{{ $soundMuted ? __('Unmute notification sounds') : __('Mute notification sounds') }}"
                >
                    <x-icon :name="$soundMuted ? 'volume-x' : 'volume-2'" class="h-4 w-4 {{ $soundMuted ? 'text-rose-400' : 'text-zinc-400' }}" />
                </button>

                <!-- Open in New Tab -->
                <a
                    href="{{ route('meetings.room', ['uuid' => $meeting->uuid]) }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="p-2 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer"
                    title="{{ __('Open meeting in new tab') }}"
                >
                    <x-icon name="external-link" class="h-4 w-4" />
                </a>

                <!-- Fullscreen Toggle -->
                <button
                    type="button"
                    x-on:click="toggleFullscreen()"
                    class="p-2 rounded-lg text-zinc-400 hover:text-white hover:bg-zinc-800 transition-colors cursor-pointer"
                    title="{{ __('Toggle Fullscreen') }}"
                >
                    <x-icon name="maximize-2" class="h-4 w-4" x-show="!isFullscreen" />
                    <x-icon name="minimize-2" class="h-4 w-4" x-show="isFullscreen" />
                </button>

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

        <!-- Hardware & Resource Diagnostic Alert Banner in Active Room -->
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
                <x-icon name="alert-triangle" class="h-4 w-4 shrink-0 text-amber-400" x-show="hardwareNotice.type === 'warning'" />
                <x-icon name="alert-circle" class="h-4 w-4 shrink-0 text-rose-400" x-show="hardwareNotice.type === 'danger'" />
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

            <!-- Video Tiles Viewports Container -->
            <div class="flex-1 flex overflow-hidden">
                <!-- 1. Large Stage + Filmstrip Mode (Active when any participant is Pinned, Spotlighted, or in Speaker / Stage View) -->
                <div
                    x-show="effectiveFeaturedUserId()"
                    x-cloak
                    class="flex-1 p-3 flex flex-col md:flex-row gap-3 overflow-hidden"
                >
                    <!-- Main Large Stage -->
                    <div class="flex-1 h-full min-h-[360px] rounded-2xl overflow-hidden bg-black border border-primary/50 ring-2 ring-primary/20 flex flex-col items-center justify-center relative shadow-2xl">
                        <!-- A. Local User Large Stage Feed -->
                        <template x-if="effectiveFeaturedUserId() === {{ (int) $currentUser->id }}">
                            <div class="w-full h-full relative flex items-center justify-center bg-black">
                                <video
                                    wire:ignore
                                    data-local-video="true"
                                    autoplay
                                    playsinline
                                    muted
                                    class="w-full h-full object-contain bg-black mirror"
                                    :class="videoOff ? 'hidden' : 'block'"
                                ></video>

                                <div x-show="videoOff" class="flex flex-col items-center justify-center p-8">
                                    <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-32 text-4xl shadow-2xl mb-3" />
                                    <p class="text-sm font-semibold text-zinc-300">{{ $currentUser->name }} ({{ __('You') }})</p>
                                </div>

                                <!-- Stage Top Left Badges -->
                                <div class="absolute top-4 left-4 flex items-center gap-2 z-20">
                                    <div x-show="isLocalSpeaking" class="px-3 py-1 rounded-full bg-emerald-600/90 text-white text-xs font-bold flex items-center gap-1.5 shadow-lg animate-pulse">
                                        <span class="h-2 w-2 rounded-full bg-white animate-ping"></span>
                                        <span>{{ __('Speaking') }}</span>
                                    </div>
                                    <div x-show="spotlightUserId === {{ (int) $currentUser->id }}" class="px-3 py-1 rounded-full bg-amber-500 text-black text-xs font-bold flex items-center gap-1.5 shadow-lg">
                                        <x-icon name="sparkles" class="h-3.5 w-3.5" />
                                        <span>{{ __('Your Screen is Spotlighted for Everyone') }}</span>
                                    </div>
                                    <div class="px-3 py-1 rounded-full bg-primary/90 text-white text-xs font-bold flex items-center gap-1.5 shadow-lg">
                                        <x-icon name="pin" class="h-3.5 w-3.5 fill-white" />
                                        <span>{{ __('Your Screen (Pinned)') }}</span>
                                    </div>
                                </div>

                                <!-- Stage Top Right: Unpin & Host Spotlight Buttons -->
                                <div class="absolute top-4 right-4 z-20 flex items-center gap-2">
                                    @if ($isHostOrCoHost)
                                        @if ($spotlightUserId === (int) $currentUser->id)
                                            <button
                                                type="button"
                                                wire:click="removeSpotlight"
                                                class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-black text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xl transition-transform hover:scale-105"
                                                title="{{ __('Remove spotlight for everyone') }}"
                                            >
                                                <x-icon name="sparkles" class="h-4 w-4" />
                                                <span>{{ __('Remove Spotlight') }}</span>
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="spotlightParticipant({{ (int) $currentUser->id }})"
                                                class="px-3 py-1.5 rounded-xl bg-amber-500/20 hover:bg-amber-500 border border-amber-500/50 text-amber-300 hover:text-black text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xl transition-transform hover:scale-105"
                                                title="{{ __('Spotlight your screen for all attendees') }}"
                                            >
                                                <x-icon name="sparkles" class="h-4 w-4" />
                                                <span>{{ __('Spotlight My Screen for Everyone') }}</span>
                                            </button>
                                        @endif
                                    @endif

                                    <button
                                        type="button"
                                        @click="pinUser({{ (int) $currentUser->id }})"
                                        class="px-3 py-1.5 rounded-xl bg-black/80 hover:bg-black border border-white/20 text-white text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xl transition-transform hover:scale-105"
                                    >
                                        <x-icon name="pin-off" class="h-4 w-4" />
                                        <span>{{ __('Unpin Screen') }}</span>
                                    </button>
                                </div>

                                <!-- Stage Bottom Bar -->
                                <div class="absolute bottom-4 left-4 right-4 flex items-center justify-between px-4 py-2 rounded-xl bg-black/70 backdrop-blur-md border border-white/10 text-white text-sm z-20">
                                    <span class="font-bold">
                                        {{ $currentUser->name }} ({{ __('You') }})
                                        @if ($participant && $participant->isHost())
                                            <span class="text-amber-400 text-xs ml-1.5 font-bold">[{{ __('Host') }}]</span>
                                        @elseif ($participant && $participant->isCoHost())
                                            <span class="text-sky-400 text-xs ml-1.5 font-bold">[{{ __('Co-Host') }}]</span>
                                        @endif
                                    </span>
                                    <div class="flex items-center gap-3">
                                        <div x-show="!micMuted" class="flex items-center gap-1 h-4">
                                            <span class="w-1 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(4, localAudioLevel * 0.2)}px`"></span>
                                            <span class="w-1 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(4, localAudioLevel * 0.35)}px`"></span>
                                            <span class="w-1 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(4, localAudioLevel * 0.22)}px`"></span>
                                        </div>
                                        <span x-show="micMuted" class="text-rose-400 text-xs flex items-center gap-1 font-semibold">
                                            <x-icon name="mic-off" class="h-4 w-4" />
                                            <span>{{ __('Muted') }}</span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- B. Remote Participants Large Stage Feeds -->
                        @foreach ($activeParticipants as $p)
                            @if ((int) $p->user_id !== (int) $currentUser->id)
                                <template x-if="effectiveFeaturedUserId() === {{ (int) $p->user_id }}">
                                    <div class="w-full h-full relative flex items-center justify-center bg-black">
                                        <video
                                            wire:ignore
                                            data-remote-video-user="{{ $p->user_id }}"
                                            x-init="$nextTick(() => bindRemoteVideo({{ (int) $p->user_id }}))"
                                            autoplay
                                            playsinline
                                            muted
                                            class="w-full h-full object-contain bg-black"
                                            :class="isPeerVideoOff({{ (int) $p->user_id }}) ? 'hidden' : 'block'"
                                        ></video>

                                        <div x-show="isPeerVideoOff({{ (int) $p->user_id }})" class="flex flex-col items-center justify-center p-8">
                                            <x-ui.avatar :name="$p->displayName()" :src="$p->user?->avatarUrl()" size="size-32 text-4xl shadow-2xl mb-3" />
                                            <p class="text-sm font-semibold text-zinc-300">{{ $p->displayName() }}</p>
                                        </div>

                                        <!-- Stage Top Left Badges -->
                                        <div class="absolute top-4 left-4 flex items-center gap-2 z-20">
                                            <div x-show="isPeerSpeaking({{ (int) $p->user_id }})" class="px-3 py-1 rounded-full bg-emerald-600/90 text-white text-xs font-bold flex items-center gap-1.5 shadow-lg animate-pulse">
                                                <span class="h-2 w-2 rounded-full bg-white animate-ping"></span>
                                                <span>{{ __('Speaking') }}</span>
                                            </div>
                                            <div x-show="spotlightUserId === {{ (int) $p->user_id }}" class="px-3 py-1 rounded-full bg-amber-500 text-black text-xs font-bold flex items-center gap-1.5 shadow-lg">
                                                <x-icon name="sparkles" class="h-3.5 w-3.5" />
                                                <span>{{ __('Spotlight for Everyone') }}</span>
                                            </div>
                                            <div x-show="pinnedUserId === {{ (int) $p->user_id }}" class="px-3 py-1 rounded-full bg-primary/90 text-white text-xs font-bold flex items-center gap-1.5 shadow-lg">
                                                <x-icon name="pin" class="h-3.5 w-3.5 fill-white" />
                                                <span>{{ __('Pinned Screen') }}</span>
                                            </div>
                                        </div>

                                        <!-- Stage Top Right: Unpin & Host Spotlight Buttons -->
                                        <div class="absolute top-4 right-4 z-20 flex items-center gap-2">
                                            @if ($isHostOrCoHost)
                                                @if ($spotlightUserId === (int) $p->user_id)
                                                    <button
                                                        type="button"
                                                        wire:click="removeSpotlight"
                                                        class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-black text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xl transition-transform hover:scale-105"
                                                        title="{{ __('Remove spotlight for everyone') }}"
                                                    >
                                                        <x-icon name="sparkles" class="h-4 w-4" />
                                                        <span>{{ __('Remove Spotlight') }}</span>
                                                    </button>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="spotlightParticipant({{ (int) $p->user_id }})"
                                                        class="px-3 py-1.5 rounded-xl bg-amber-500/20 hover:bg-amber-500 border border-amber-500/50 text-amber-300 hover:text-black text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xl transition-transform hover:scale-105"
                                                        title="{{ __('Spotlight this screen for all attendees') }}"
                                                    >
                                                        <x-icon name="sparkles" class="h-4 w-4" />
                                                        <span>{{ __('Spotlight for Everyone') }}</span>
                                                    </button>
                                                @endif
                                            @endif

                                            <button
                                                type="button"
                                                @click="pinUser({{ (int) $p->user_id }})"
                                                class="px-3 py-1.5 rounded-xl bg-black/80 hover:bg-black border border-white/20 text-white text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xl transition-transform hover:scale-105"
                                            >
                                                <x-icon name="pin-off" class="h-4 w-4" />
                                                <span>{{ __('Unpin Screen') }}</span>
                                            </button>
                                        </div>

                                        <!-- Stage Bottom Bar -->
                                        <div class="absolute bottom-4 left-4 right-4 flex items-center justify-between px-4 py-2 rounded-xl bg-black/70 backdrop-blur-md border border-white/10 text-white text-sm z-20">
                                            <span class="font-bold">
                                                {{ $p->displayName() }}
                                                @if ($p->isHost())
                                                    <span class="text-amber-400 text-xs ml-1.5 font-bold">[{{ __('Host') }}]</span>
                                                @elseif ($p->isCoHost())
                                                    <span class="text-sky-400 text-xs ml-1.5 font-bold">[{{ __('Co-Host') }}]</span>
                                                @endif
                                            </span>
                                            <div class="flex items-center gap-3">
                                                <div x-show="!isPeerMuted({{ (int) $p->user_id }})" class="flex items-center gap-1 h-4">
                                                    <span class="w-1 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(4, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.2)}px`"></span>
                                                    <span class="w-1 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(4, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.35)}px`"></span>
                                                    <span class="w-1 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(4, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.22)}px`"></span>
                                                </div>
                                                <span x-show="isPeerMuted({{ (int) $p->user_id }})" class="text-rose-400 text-xs flex items-center gap-1 font-semibold">
                                                    <x-icon name="mic-off" class="h-4 w-4" />
                                                    <span>{{ __('Muted') }}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            @endif
                        @endforeach
                    </div>

                    <!-- Filmstrip Side/Bottom Strip (Thumbnails of non-featured participants) -->
                    <div class="flex md:flex-col gap-2.5 overflow-x-auto md:overflow-y-auto shrink-0 md:w-56 p-1 max-h-40 md:max-h-full">
                        <!-- Local User Thumbnail (if not featured) -->
                        <div
                            x-show="effectiveFeaturedUserId() !== {{ (int) $currentUser->id }}"
                            @click="pinUser({{ (int) $currentUser->id }})"
                            class="w-44 md:w-full aspect-video rounded-xl overflow-hidden bg-zinc-900 border shrink-0 relative group shadow-md cursor-pointer hover:border-primary/60 transition-all"
                            :class="spotlightUserId === {{ (int) $currentUser->id }} ? 'border-amber-400 ring-2 ring-amber-400/30' : 'border-zinc-800'"
                            title="{{ __('Click to Pin to Stage') }}"
                        >
                            <video
                                wire:ignore
                                data-local-video="true"
                                x-init="$nextTick(() => rebindLocalVideo())"
                                autoplay
                                playsinline
                                muted
                                class="h-full w-full object-cover mirror"
                                :class="videoOff ? 'hidden' : 'block'"
                            ></video>
                            <div x-show="videoOff" class="flex flex-col items-center justify-center p-2 h-full">
                                <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-10 text-xs shadow-md" />
                            </div>
                            <div class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 transition-opacity z-20 flex items-center gap-1">
                                @if ($isHostOrCoHost)
                                    <button
                                        type="button"
                                        @if ($spotlightUserId === (int) $currentUser->id)
                                            wire:click.stop="removeSpotlight"
                                            class="p-1 rounded-md bg-amber-500 text-black shadow-xs cursor-pointer"
                                            title="{{ __('Remove Spotlight (Self)') }}"
                                        @else
                                            wire:click.stop="spotlightParticipant({{ (int) $currentUser->id }})"
                                            class="p-1 rounded-md bg-black/80 text-white hover:text-amber-400 shadow-xs cursor-pointer"
                                            title="{{ __('Spotlight My Screen for Everyone') }}"
                                        @endif
                                    >
                                        <x-icon name="sparkles" class="h-3 w-3" />
                                    </button>
                                @endif
                                <button type="button" class="p-1 rounded-md bg-black/80 text-white hover:text-primary">
                                    <x-icon name="pin" class="h-3 w-3" />
                                </button>
                            </div>
                            <div class="absolute bottom-1.5 left-1.5 right-1.5 flex items-center justify-between text-[11px] px-1.5 py-0.5 rounded-md bg-black/60 backdrop-blur-xs text-white z-20">
                                <span class="truncate font-semibold flex items-center gap-1">
                                    <span>{{ __('You') }}</span>
                                    @if ($spotlightUserId === (int) $currentUser->id)
                                        <x-icon name="sparkles" class="h-2.5 w-2.5 text-amber-400" />
                                    @endif
                                </span>
                                <div x-show="!micMuted" class="flex items-center gap-0.5 h-2.5">
                                    <span class="w-0.5 rounded-full bg-emerald-400" :style="`height: ${Math.max(2, localAudioLevel * 0.12)}px`"></span>
                                    <span class="w-0.5 rounded-full bg-emerald-400" :style="`height: ${Math.max(2, localAudioLevel * 0.2)}px`"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Remote Participants Thumbnails (if not featured) -->
                        @foreach ($activeParticipants as $p)
                            @if ((int) $p->user_id !== (int) $currentUser->id)
                                <div
                                    wire:key="filmstrip-part-{{ $p->id }}"
                                    x-show="effectiveFeaturedUserId() !== {{ (int) $p->user_id }}"
                                    @click="pinUser({{ (int) $p->user_id }})"
                                    class="w-44 md:w-full aspect-video rounded-xl overflow-hidden bg-zinc-900 border shrink-0 relative group shadow-md cursor-pointer hover:border-primary/60 transition-all"
                                    :class="spotlightUserId === {{ (int) $p->user_id }} ? 'border-amber-400 ring-2 ring-amber-400/30' : 'border-zinc-800'"
                                    title="{{ __('Click to Pin to Stage') }}"
                                >
                                    <video
                                        wire:ignore
                                        data-remote-video-user="{{ $p->user_id }}"
                                        x-init="$nextTick(() => bindRemoteVideo({{ (int) $p->user_id }}))"
                                        autoplay
                                        playsinline
                                        muted
                                        class="h-full w-full object-cover"
                                        :class="isPeerVideoOff({{ (int) $p->user_id }}) ? 'hidden' : 'block'"
                                    ></video>
                                    <div x-show="isPeerVideoOff({{ (int) $p->user_id }})" class="flex flex-col items-center justify-center p-2 h-full">
                                        <x-ui.avatar :name="$p->displayName()" :src="$p->user?->avatarUrl()" size="size-10 text-xs shadow-md" />
                                    </div>
                                    <div class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 transition-opacity z-20 flex items-center gap-1">
                                        @if ($isHostOrCoHost)
                                            <button
                                                type="button"
                                                @if ($spotlightUserId === (int) $p->user_id)
                                                    wire:click.stop="removeSpotlight"
                                                    class="p-1 rounded-md bg-amber-500 text-black shadow-xs cursor-pointer"
                                                    title="{{ __('Remove Spotlight') }}"
                                                @else
                                                    wire:click.stop="spotlightParticipant({{ (int) $p->user_id }})"
                                                    class="p-1 rounded-md bg-black/80 text-white hover:text-amber-400 shadow-xs cursor-pointer"
                                                    title="{{ __('Spotlight for Everyone') }}"
                                                @endif
                                            >
                                                <x-icon name="sparkles" class="h-3 w-3" />
                                            </button>
                                        @endif
                                        <button type="button" class="p-1 rounded-md bg-black/80 text-white hover:text-primary">
                                            <x-icon name="pin" class="h-3 w-3" />
                                        </button>
                                    </div>
                                    <div class="absolute bottom-1.5 left-1.5 right-1.5 flex items-center justify-between text-[11px] px-1.5 py-0.5 rounded-md bg-black/60 backdrop-blur-xs text-white z-20">
                                        <span class="truncate font-semibold flex items-center gap-1">
                                            <span>{{ $p->displayName() }}</span>
                                            @if ($spotlightUserId === (int) $p->user_id)
                                                <x-icon name="sparkles" class="h-2.5 w-2.5 text-amber-400" />
                                            @endif
                                        </span>
                                        <div x-show="!isPeerMuted({{ (int) $p->user_id }})" class="flex items-center gap-0.5 h-2.5">
                                            <span class="w-0.5 rounded-full bg-emerald-400" :style="`height: ${Math.max(2, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.12)}px`"></span>
                                            <span class="w-0.5 rounded-full bg-emerald-400" :style="`height: ${Math.max(2, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.2)}px`"></span>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>

                <!-- 2. Standard Grid Mode (Active when no participant is featured or pinned) -->
                <div
                    x-show="!effectiveFeaturedUserId()"
                    class="flex-1 p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 overflow-y-auto items-center justify-center"
                >
                    <!-- Local User Viewport -->
                    <div
                        class="relative rounded-2xl overflow-hidden bg-zinc-900 border aspect-video flex items-center justify-center group shadow-md transition-all"
                        :class="isLocalSpeaking ? 'border-emerald-500 ring-2 ring-emerald-500/50 shadow-emerald-500/20' : ((spotlightUserId === {{ (int) $currentUser->id }} || pinnedUserId === {{ (int) $currentUser->id }}) ? 'border-amber-400 ring-2 ring-amber-400/40' : 'border-zinc-800')"
                    >
                        <video
                            wire:ignore
                            x-ref="localVideo"
                            data-local-video="true"
                            x-init="$nextTick(() => rebindLocalVideo())"
                            autoplay
                            playsinline
                            muted
                            class="h-full w-full object-cover mirror"
                            :class="videoOff ? 'hidden' : 'block'"
                        ></video>

                        <div x-show="videoOff" class="flex flex-col items-center justify-center p-4">
                            <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-20 text-xl shadow-xl" />
                        </div>

                        <div x-show="isLocalSpeaking" class="absolute top-3 left-3 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-[10px] font-bold flex items-center gap-1 shadow-lg animate-pulse z-20">
                            <span class="h-1.5 w-1.5 rounded-full bg-white animate-ping"></span>
                            <span>{{ __('Speaking') }}</span>
                        </div>

                        <div x-show="spotlightUserId === {{ (int) $currentUser->id }}" x-cloak class="absolute top-3 left-3 px-2 py-0.5 rounded-full bg-amber-500 text-black text-[10px] font-bold flex items-center gap-1 shadow-lg z-20">
                            <x-icon name="sparkles" class="h-3 w-3" />
                            <span>{{ __('Spotlight') }}</span>
                        </div>

                        <div class="absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity z-20 flex items-center gap-1.5">
                            @if ($isHostOrCoHost)
                                <button
                                    type="button"
                                    @if ($spotlightUserId === (int) $currentUser->id)
                                        wire:click="removeSpotlight"
                                        class="p-1.5 rounded-lg bg-amber-500 text-black hover:bg-amber-600 cursor-pointer shadow-md transition-all"
                                        title="{{ __('Remove Spotlight (Self)') }}"
                                    @else
                                        wire:click="spotlightParticipant({{ (int) $currentUser->id }})"
                                        class="p-1.5 rounded-lg bg-black/70 hover:bg-amber-500 hover:text-black text-zinc-300 cursor-pointer shadow-md transition-all"
                                        title="{{ __('Spotlight My Screen for Everyone') }}"
                                    @endif
                                >
                                    <x-icon name="sparkles" class="h-3.5 w-3.5" />
                                </button>
                            @endif

                            <button
                                type="button"
                                @click="pinUser({{ (int) $currentUser->id }})"
                                class="p-1.5 rounded-lg bg-black/70 hover:bg-black text-zinc-300 hover:text-white cursor-pointer shadow-md transition-all"
                                :title="pinnedUserId === {{ (int) $currentUser->id }} ? '{{ __('Unpin your screen') }}' : '{{ __('Pin your screen') }}'"
                            >
                                <x-icon name="pin" class="h-3.5 w-3.5" x-bind:class="pinnedUserId === {{ (int) $currentUser->id }} ? 'text-primary fill-primary' : ''" />
                            </button>
                        </div>

                        <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between text-xs px-2 py-1 rounded-lg bg-black/60 backdrop-blur-xs text-white z-20">
                            <span class="font-semibold truncate">
                                {{ $currentUser->name }} ({{ __('You') }})
                                @if ($participant && $participant->isHost())
                                    <span class="text-amber-400 font-bold text-[10px] ml-1">[{{ __('Host') }}]</span>
                                @elseif ($participant && $participant->isCoHost())
                                    <span class="text-sky-400 font-bold text-[10px] ml-1">[{{ __('Co-Host') }}]</span>
                                @endif
                            </span>
                            <div class="flex items-center gap-2">
                                <div x-show="!micMuted" class="flex items-center gap-0.5 h-3">
                                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.15)}px`"></span>
                                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.28)}px`"></span>
                                    <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.18)}px`"></span>
                                </div>
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
                            <div
                                wire:key="active-part-{{ $p->id }}"
                                class="relative rounded-2xl overflow-hidden bg-zinc-900 border aspect-video flex flex-col items-center justify-center shadow-md group transition-all"
                                :class="isPeerSpeaking({{ (int) $p->user_id }}) ? 'border-emerald-500 ring-2 ring-emerald-500/50 shadow-emerald-500/20' : ((spotlightUserId === {{ (int) $p->user_id }} || pinnedUserId === {{ (int) $p->user_id }}) ? 'border-amber-400 ring-2 ring-amber-400/40' : 'border-zinc-800')"
                            >
                                <video
                                    wire:ignore
                                    id="remote-meeting-video-{{ $p->user_id }}"
                                    data-remote-video-user="{{ $p->user_id }}"
                                    x-init="$nextTick(() => bindRemoteVideo({{ (int) $p->user_id }}))"
                                    autoplay
                                    playsinline
                                    muted
                                    class="h-full w-full object-cover"
                                    :class="isPeerVideoOff({{ (int) $p->user_id }}) ? 'hidden' : 'block'"
                                ></video>

                                <div x-show="isPeerVideoOff({{ (int) $p->user_id }})" class="flex flex-col items-center justify-center p-4">
                                    <x-ui.avatar :name="$p->displayName()" :src="$p->user?->avatarUrl()" size="size-20 text-xl shadow-xl mb-2" />
                                </div>

                                <div x-show="isPeerSpeaking({{ (int) $p->user_id }})" class="absolute top-3 left-3 px-2 py-0.5 rounded-full bg-emerald-600/90 text-white text-[10px] font-bold flex items-center gap-1 shadow-lg animate-pulse z-20">
                                    <span class="h-1.5 w-1.5 rounded-full bg-white animate-ping"></span>
                                    <span>{{ __('Speaking') }}</span>
                                </div>

                                <div x-show="spotlightUserId === {{ (int) $p->user_id }}" x-cloak class="absolute top-3 left-3 px-2 py-0.5 rounded-full bg-amber-500 text-black text-[10px] font-bold flex items-center gap-1 shadow-lg z-20">
                                    <x-icon name="sparkles" class="h-3 w-3" />
                                    <span>{{ __('Spotlight') }}</span>
                                </div>

                                <div class="absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity z-30 flex items-center gap-1.5">
                                    @if ($isHostOrCoHost)
                                        <button
                                            type="button"
                                            @if ($spotlightUserId === (int) $p->user_id)
                                                wire:click="removeSpotlight"
                                                class="p-1.5 rounded-lg bg-amber-500 text-black hover:bg-amber-600 cursor-pointer shadow-md transition-all"
                                                title="{{ __('Remove Spotlight') }}"
                                            @else
                                                wire:click="spotlightParticipant({{ (int) $p->user_id }})"
                                                class="p-1.5 rounded-lg bg-black/70 hover:bg-amber-500 hover:text-black text-zinc-300 cursor-pointer shadow-md transition-all"
                                                title="{{ __('Spotlight for Everyone') }}"
                                            @endif
                                        >
                                            <x-icon name="sparkles" class="h-3.5 w-3.5" />
                                        </button>
                                    @endif

                                    <button
                                        type="button"
                                        @click="pinUser({{ (int) $p->user_id }})"
                                        class="p-1.5 rounded-lg bg-black/70 hover:bg-black text-zinc-300 hover:text-white cursor-pointer shadow-md transition-all"
                                        :title="pinnedUserId === {{ (int) $p->user_id }} ? '{{ __('Unpin screen') }}' : '{{ __('Pin screen') }}'"
                                    >
                                        <x-icon name="pin" class="h-3.5 w-3.5" x-bind:class="pinnedUserId === {{ (int) $p->user_id }} ? 'text-primary fill-primary' : ''" />
                                    </button>

                                    @if ($isHostOrCoHost)
                                        <x-ui.dropdown width="w-48" offset="mt-1">
                                            <x-slot:trigger>
                                                <button type="button" class="p-1.5 rounded-lg bg-black/70 hover:bg-black text-zinc-300 hover:text-white cursor-pointer shadow-md">
                                                    <x-icon name="more-vertical" class="h-3.5 w-3.5" />
                                                </button>
                                            </x-slot:trigger>

                                            @if ($spotlightUserId === (int) $p->user_id)
                                                <x-ui.dropdown.item icon="sparkles" wire:click="removeSpotlight">
                                                    {{ __('Remove Spotlight') }}
                                                </x-ui.dropdown.item>
                                            @else
                                                <x-ui.dropdown.item icon="sparkles" wire:click="spotlightParticipant({{ (int) $p->user_id }})">
                                                    {{ __('Spotlight for Everyone') }}
                                                </x-ui.dropdown.item>
                                            @endif

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
                                    @endif
                                </div>

                                <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between text-xs px-2 py-1 rounded-lg bg-black/60 backdrop-blur-xs text-white z-20">
                                    <span class="font-semibold truncate">
                                        {{ $p->displayName() }}
                                        @if ($p->isHost())
                                            <span class="text-amber-400 text-[10px] ml-1">[{{ __('Host') }}]</span>
                                        @elseif ($p->isCoHost())
                                            <span class="text-sky-400 text-[10px] ml-1">[{{ __('Co-Host') }}]</span>
                                        @endif
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <div x-show="!isPeerMuted({{ (int) $p->user_id }})" class="flex items-center gap-0.5 h-3">
                                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.15)}px`"></span>
                                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.28)}px`"></span>
                                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, getPeerAudioLevel({{ (int) $p->user_id }}) * 0.18)}px`"></span>
                                        </div>
                                        <span x-show="isPeerMuted({{ (int) $p->user_id }})" class="text-rose-400" title="{{ __('Muted') }}">
                                            <x-icon name="mic-off" class="h-3.5 w-3.5" />
                                        </span>
                                        <span x-show="!isPeerMuted({{ (int) $p->user_id }})" class="text-emerald-400">
                                            <x-icon name="mic" class="h-3.5 w-3.5" />
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
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
                            @php
                                $canModerate = $isHostOrCoHost || ($chat['is_self'] && $meeting->canParticipantEditDeleteChat());
                            @endphp
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

                                    @if ($canModerate && $editingInRoomIndex !== $cIndex)
                                        <button
                                            type="button"
                                            wire:click="startEditInRoomMessage({{ $cIndex }})"
                                            class="opacity-0 group-hover:opacity-100 p-0.5 text-zinc-400 hover:text-primary transition-opacity cursor-pointer"
                                            title="{{ __('Edit message') }}"
                                        >
                                            <x-icon name="pencil" class="h-3 w-3" />
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="deleteInRoomMessage({{ $cIndex }})"
                                            class="opacity-0 group-hover:opacity-100 p-0.5 text-zinc-400 hover:text-rose-400 transition-opacity cursor-pointer"
                                            title="{{ __('Delete message') }}"
                                        >
                                            <x-icon name="trash" class="h-3 w-3" />
                                        </button>
                                    @endif
                                </div>

                                @if ($editingInRoomIndex === $cIndex)
                                    <div class="p-2 rounded-xl bg-zinc-900 border border-primary space-y-1.5 text-left">
                                        <input
                                            type="text"
                                            wire:model="editingInRoomText"
                                            wire:keydown.enter="saveEditInRoomMessage"
                                            class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-2.5 py-1 text-xs text-zinc-100 focus:outline-none focus:border-primary"
                                        />
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button
                                                type="button"
                                                wire:click="cancelEditInRoomMessage"
                                                class="px-2 py-0.5 rounded text-[10px] bg-zinc-800 hover:bg-zinc-700 text-zinc-300 cursor-pointer"
                                            >
                                                {{ __('Cancel') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="saveEditInRoomMessage"
                                                class="px-2 py-0.5 rounded text-[10px] bg-primary hover:bg-primary/90 text-primary-foreground font-semibold cursor-pointer"
                                            >
                                                {{ __('Save') }}
                                            </button>
                                        </div>
                                    </div>
                                @else
                                    <div class="inline-block px-3 py-1.5 rounded-xl max-w-[85%] break-words {{ $chat['is_self'] ? 'bg-primary text-primary-foreground' : 'bg-zinc-800 text-zinc-200' }}">
                                        <p class="text-[9px] opacity-75 font-semibold mb-0.5">{{ $chat['recipient_label'] }}</p>
                                        <p>{{ $chat['body'] }}</p>
                                        @if (!empty($chat['is_edited']))
                                            <span class="text-[9px] opacity-60 italic block mt-0.5">{{ __('(edited)') }}</span>
                                        @endif
                                    </div>
                                @endif
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

                        <!-- Toggle Participant Edit/Delete Messages -->
                        <div class="flex items-center justify-between p-2.5 rounded-xl border border-zinc-800 bg-zinc-950">
                            <div>
                                <p class="font-semibold text-zinc-200">{{ __('Allow Participant Message Edit/Delete') }}</p>
                                <p class="text-[10px] text-zinc-400">{{ __('Participants can edit and delete their own messages') }}</p>
                            </div>
                            <x-ui.switch :checked="$meeting->canParticipantEditDeleteChat()" wire:click="toggleRestriction('allow_participant_edit_delete_chat')" />
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

                    <!-- Invite Link & Slug Customization -->
                    <div class="space-y-1.5 pt-2 border-t border-zinc-800">
                        <div class="flex items-center justify-between">
                            <label class="block font-semibold text-zinc-300">{{ __('Invite Link') }}</label>
                            @if ($isHostOrCoHost)
                                <button
                                    type="button"
                                    wire:click="openEditSlugModal"
                                    class="text-[11px] text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                                    title="{{ __('Change meeting link slug') }}"
                                >
                                    <x-icon name="pencil" class="h-3 w-3" />
                                    <span>{{ __('Edit Slug') }}</span>
                                </button>
                            @endif
                        </div>
                        <div class="flex items-center gap-1.5">
                            <input
                                type="text"
                                readonly
                                value="{{ $meeting->join_url }}"
                                class="flex-1 rounded-lg border border-zinc-700 bg-zinc-950 px-2.5 py-1.5 text-[11px] font-mono text-zinc-300 select-all outline-none"
                            />
                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText('{{ $meeting->join_url }}'); if (window.Alpine && Alpine.store('toasts')) { Alpine.store('toasts').add('success', '{{ __('Meeting link copied!') }}'); } else { alert('{{ __('Meeting link copied!') }}'); }"
                                class="p-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 cursor-pointer"
                                title="{{ __('Copy Link') }}"
                            >
                                <x-icon name="copy" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>

                    <!-- Active Participants List & Management -->
                    <div class="space-y-2 pt-2 border-t border-zinc-800">
                        <div class="flex items-center justify-between">
                            <label class="block font-semibold text-zinc-300">{{ __('In-Meeting Participants') }} ({{ $activeParticipants->count() }})</label>
                        </div>

                        <div class="space-y-1.5 max-h-60 overflow-y-auto pr-1">
                            <!-- Local User Entry -->
                            <div class="flex items-center justify-between p-2 rounded-xl bg-zinc-950/70 border border-zinc-800">
                                <div class="flex items-center gap-2 min-w-0">
                                    <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-7 text-[10px]" />
                                    <div class="min-w-0">
                                        <p class="font-semibold text-zinc-200 truncate text-[11px]">
                                            {{ $currentUser->name }} ({{ __('You') }})
                                        </p>
                                        <span class="text-[9px] text-zinc-400">
                                            @if ($participant && $participant->isHost())
                                                <span class="text-amber-400 font-bold">[{{ __('Host') }}]</span>
                                            @elseif ($participant && $participant->isCoHost())
                                                <span class="text-sky-400 font-bold">[{{ __('Co-Host') }}]</span>
                                            @else
                                                <span>{{ __('Participant') }}</span>
                                            @endif
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1">
                                    @if ($isHostOrCoHost)
                                        <button
                                            type="button"
                                            @if ($spotlightUserId === (int) $currentUser->id)
                                                wire:click="removeSpotlight"
                                                class="p-1 rounded-md bg-amber-500 text-black shadow-xs cursor-pointer"
                                                title="{{ __('Remove Spotlight (Self)') }}"
                                            @else
                                                wire:click="spotlightParticipant({{ (int) $currentUser->id }})"
                                                class="p-1 rounded-md bg-zinc-800 text-zinc-300 hover:text-amber-400 shadow-xs cursor-pointer"
                                                title="{{ __('Spotlight My Screen for Everyone') }}"
                                            @endif
                                        >
                                            <x-icon name="sparkles" class="h-3 w-3" />
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        @click="pinUser({{ (int) $currentUser->id }})"
                                        class="p-1 rounded-md bg-zinc-800 text-zinc-300 hover:text-primary shadow-xs cursor-pointer"
                                        title="{{ __('Pin to Stage') }}"
                                    >
                                        <x-icon name="pin" class="h-3 w-3" x-bind:class="pinnedUserId === {{ (int) $currentUser->id }} ? 'text-primary fill-primary' : ''" />
                                    </button>
                                </div>
                            </div>

                            <!-- Remote Participants Entries -->
                            @foreach ($activeParticipants as $part)
                                @if ((int) $part->user_id !== (int) $currentUser->id)
                                    <div class="flex items-center justify-between p-2 rounded-xl bg-zinc-950/70 border border-zinc-800">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <x-ui.avatar :name="$part->displayName()" :src="$part->user?->avatarUrl()" size="size-7 text-[10px]" />
                                            <div class="min-w-0">
                                                <p class="font-semibold text-zinc-200 truncate text-[11px]">
                                                    {{ $part->displayName() }}
                                                </p>
                                                <span class="text-[9px] text-zinc-400">
                                                    @if ($part->isHost())
                                                        <span class="text-amber-400 font-bold">[{{ __('Host') }}]</span>
                                                    @elseif ($part->isCoHost())
                                                        <span class="text-sky-400 font-bold">[{{ __('Co-Host') }}]</span>
                                                    @else
                                                        <span>{{ __('Participant') }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            @if ($isHostOrCoHost)
                                                <button
                                                    type="button"
                                                    @if ($spotlightUserId === (int) $part->user_id)
                                                        wire:click="removeSpotlight"
                                                        class="p-1 rounded-md bg-amber-500 text-black shadow-xs cursor-pointer"
                                                        title="{{ __('Remove Spotlight') }}"
                                                    @else
                                                        wire:click="spotlightParticipant({{ (int) $part->user_id }})"
                                                        class="p-1 rounded-md bg-zinc-800 text-zinc-300 hover:text-amber-400 shadow-xs cursor-pointer"
                                                        title="{{ __('Spotlight for Everyone') }}"
                                                    @endif
                                                >
                                                    <x-icon name="sparkles" class="h-3 w-3" />
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                @click="pinUser({{ (int) $part->user_id }})"
                                                class="p-1 rounded-md bg-zinc-800 text-zinc-300 hover:text-primary shadow-xs cursor-pointer"
                                                title="{{ __('Pin to Stage') }}"
                                            >
                                                <x-icon name="pin" class="h-3 w-3" x-bind:class="pinnedUserId === {{ (int) $part->user_id }} ? 'text-primary fill-primary' : ''" />
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
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

                <!-- Device Hardware Settings Modal Trigger -->
                <button
                    type="button"
                    x-on:click="showDeviceSettingsModal = true"
                    class="p-3 rounded-full bg-zinc-800 hover:bg-zinc-700 text-zinc-100 transition-transform active:scale-95 cursor-pointer shadow-md"
                    title="{{ __('Device Audio & Video Settings') }}"
                >
                    <x-icon name="settings" class="h-5 w-5" />
                </button>

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
                                        @click="showEmojiMenu = false; sendReaction('{{ $rEmoji }}')"
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

    <!-- Modal: Edit Meeting Link Slug (Host/Co-host) -->
    <x-ui.modal name="edit-slug-modal" max-width="max-w-md" title="{{ __('Customize Meeting Link Slug') }}">
        <div class="p-4 space-y-4">
            <div class="space-y-1">
                <p class="text-xs text-muted-foreground">
                    {{ __('Set a custom, memorable link slug or code for this meeting. All attendees using the invite link will be redirected seamlessly.') }}
                </p>
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-semibold text-foreground">{{ __('Custom Slug / Invite Code') }}</label>
                <div class="flex items-center">
                    <span class="px-3 py-2 bg-secondary text-muted-foreground text-xs rounded-l-xl border border-r-0 border-border font-mono">
                        {{ url('/meetings/join') }}/
                    </span>
                    <input
                        type="text"
                        wire:model="editMeetingSlug"
                        placeholder="my-team-sync"
                        class="flex-1 rounded-r-xl border border-border bg-card px-3 py-2 text-xs font-mono text-foreground focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" x-on:click="$store.modals.close('edit-slug-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" wire:click="saveMeetingSlug" variant="default" icon="check">
                    {{ __('Save Slug') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- In-Meeting Device Hardware Settings Modal -->
    <div
        x-show="showDeviceSettingsModal"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-xs animate-in fade-in duration-150"
    >
        <div
            @click.outside="showDeviceSettingsModal = false"
            class="w-full max-w-md rounded-2xl bg-zinc-900 border border-zinc-800 p-5 shadow-2xl space-y-4 text-left"
        >
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg bg-primary/20 text-primary">
                        <x-icon name="settings" class="h-4 w-4" />
                    </div>
                    <h3 class="font-bold text-sm text-zinc-100">{{ __('Audio & Video Settings') }}</h3>
                </div>
                <button type="button" @click="showDeviceSettingsModal = false" class="text-zinc-400 hover:text-white cursor-pointer">
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>

            <!-- Preview Card -->
            <div class="relative w-full aspect-video rounded-xl bg-zinc-950 border border-zinc-800 overflow-hidden flex items-center justify-center shadow-inner group">
                <video
                    data-local-video="true"
                    autoplay
                    playsinline
                    muted
                    class="w-full h-full object-cover transition-transform duration-200"
                    :class="[videoOff ? 'hidden' : 'block', isMirrored ? '-scale-x-100' : '']"
                ></video>
                <div x-show="videoOff" class="flex flex-col items-center justify-center space-y-1">
                    <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-12 text-sm shadow-md" />
                    <span class="text-[11px] text-zinc-400">{{ __('Camera is turned off') }}</span>
                </div>
                <!-- Mirror toggle button in modal -->
                <div x-show="!videoOff" class="absolute top-2 right-2 z-30">
                    <button
                        type="button"
                        @click="toggleMirror()"
                        :class="isMirrored ? 'bg-primary text-primary-foreground' : 'bg-zinc-900/80 text-zinc-300 hover:text-white border border-zinc-700/60'"
                        class="px-2 py-0.5 rounded-full backdrop-blur-md text-[10px] font-semibold flex items-center gap-1 transition-all cursor-pointer shadow-sm"
                    >
                        <x-icon name="flip-horizontal" class="h-3 w-3" />
                        <span>{{ __('Mirror') }}</span>
                    </button>
                </div>
            </div>

            <div class="space-y-3 text-xs">
                <!-- Microphone Selector -->
                <div class="space-y-1">
                    <div class="flex items-center justify-between">
                        <label class="font-semibold text-zinc-300 flex items-center gap-1.5">
                            <x-icon name="mic" class="h-3.5 w-3.5 text-zinc-400" />
                            <span>{{ __('Microphone Input') }}</span>
                        </label>
                        <div x-show="!micMuted" class="flex items-center gap-0.5 h-3">
                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.15)}px`"></span>
                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.3)}px`"></span>
                            <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, localAudioLevel * 0.18)}px`"></span>
                        </div>
                    </div>
                    <select
                        x-model="selectedAudioInput"
                        @change="switchMicrophoneDevice($event.target.value)"
                        class="w-full rounded-lg bg-zinc-950 border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-primary cursor-pointer"
                    >
                        <template x-for="mic in audioInputs" :key="mic.deviceId">
                            <option :value="mic.deviceId" x-text="mic.label" :selected="mic.deviceId === selectedAudioInput"></option>
                        </template>
                    </select>
                </div>

                <!-- Camera Selector -->
                <div class="space-y-1">
                    <label class="font-semibold text-zinc-300 flex items-center gap-1.5">
                        <x-icon name="video" class="h-3.5 w-3.5 text-zinc-400" />
                        <span>{{ __('Camera Input') }}</span>
                    </label>
                    <select
                        x-model="selectedVideoInput"
                        @change="switchCameraDevice($event.target.value)"
                        class="w-full rounded-lg bg-zinc-950 border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-primary cursor-pointer"
                    >
                        <template x-for="cam in videoInputs" :key="cam.deviceId">
                            <option :value="cam.deviceId" x-text="cam.label" :selected="cam.deviceId === selectedVideoInput"></option>
                        </template>
                    </select>
                </div>

                <!-- Speaker Selector -->
                <div class="space-y-1">
                    <div class="flex items-center justify-between">
                        <label class="font-semibold text-zinc-300 flex items-center gap-1.5">
                            <x-icon name="volume-2" class="h-3.5 w-3.5 text-zinc-400" />
                            <span>{{ __('Speaker Output') }}</span>
                        </label>
                        <button
                            type="button"
                            @click="testSpeakerSound()"
                            :disabled="isTestingSpeaker"
                            class="text-[11px] font-semibold text-primary hover:underline flex items-center gap-1 cursor-pointer"
                        >
                            <x-icon name="play-circle" class="h-3 w-3" x-show="!isTestingSpeaker" />
                            <x-icon name="loader-2" class="h-3 w-3 animate-spin text-primary" x-show="isTestingSpeaker" />
                            <span x-text="isTestingSpeaker ? '{{ __('Playing chime…') }}' : '{{ __('Test Speaker') }}'"></span>
                        </button>
                    </div>
                    <select
                        x-model="selectedAudioOutput"
                        @change="switchAudioOutputDevice($event.target.value)"
                        class="w-full rounded-lg bg-zinc-950 border border-zinc-700 px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-primary cursor-pointer"
                    >
                        <template x-for="spk in audioOutputs" :key="spk.deviceId">
                            <option :value="spk.deviceId" x-text="spk.label" :selected="spk.deviceId === selectedAudioOutput"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="pt-2 flex justify-end">
                <button
                    type="button"
                    @click="showDeviceSettingsModal = false"
                    class="px-4 py-1.5 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground font-semibold text-xs cursor-pointer shadow-sm"
                >
                    {{ __('Done') }}
                </button>
            </div>
        </div>
    </div>
</div>
