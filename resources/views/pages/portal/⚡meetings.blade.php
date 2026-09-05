<?php

use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\Toast;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Online Meetings')] class extends Component {
    public string $filterTab = 'upcoming'; // upcoming, past, my_meetings

    // Form for Instant Meeting
    public string $instantTopic = '';
    public string $instantMode = 'video'; // video, audio
    public string $instantAccessMode = 'open'; // open, invited_only

    // Form for Scheduled Meeting
    public string $meetingTitle = '';
    public string $meetingDescription = '';
    public string $scheduledAt = '';
    public string $endsAt = '';
    public string $meetingMode = 'video';
    public string $accessMode = 'open'; // open, invited_only
    public int $durationMinutes = 60;
    public string $repeatType = 'none';
    public string $repeatUntil = '';
    public int $reminderOffsetMinutes = 15;
    public array $reminderChannels = ['database', 'email'];
    public string $passcode = '';
    public array $selectedInvitees = [];
    public array $selectedCoHosts = [];
    public string $customEmailsInput = '';
    public array $dispatchChannels = ['database', 'email'];

    // Edit Meeting Form State
    public ?int $editingMeetingId = null;
    public string $editTitle = '';
    public string $editDescription = '';
    public string $editScheduledAt = '';
    public string $editEndsAt = '';
    public string $editMode = 'video';
    public string $editAccessMode = 'open';
    public int $editDurationMinutes = 60;
    public string $editRepeatType = 'none';
    public string $editRepeatUntil = '';
    public int $editReminderOffsetMinutes = 15;
    public array $editReminderChannels = ['database', 'email'];
    public string $editPasscode = '';
    public array $editSelectedInvitees = [];
    public array $editSelectedCoHosts = [];
    public string $editCustomEmailsInput = '';
    public array $editDispatchChannels = ['database', 'email'];

    // Selected Meeting for Share / Bulk Email Modal
    public ?int $selectedMeetingId = null;
    public string $shareModalBulkEmails = '';

    // Join, Cancel & End Confirmation State
    public ?int $confirmJoinMeetingId = null;
    public ?int $confirmEndMeetingId = null;
    public ?int $confirmCancelMeetingId = null;
    public string $cancelReason = '';
    public array $cancelNotifyChannels = ['database', 'email'];
    public string $cancelCustomEmailsInput = '';

    public function mount(): void
    {
        $enabled = (bool) Setting::get('chat.meetings_enabled', true);
        if (! $enabled && ! auth()->user()?->hasRole('Super Administrator')) {
            abort(403, __('Online meetings are currently disabled by administrator.'));
        }

        $now = now()->addHour();
        $this->scheduledAt = $now->format('Y-m-d\TH:i');
        $this->endsAt = $now->copy()->addMinutes(60)->format('Y-m-d\TH:i');
        $this->instantTopic = __('Quick Mentorship & Doubt Clearing');
    }

    public function openInstantModal(): void
    {
        $user = auth()->user();
        $this->instantTopic = __('Instant Meeting (:name)', ['name' => $user?->name ?? 'User']);
        $this->instantMode = 'video';
        $this->instantAccessMode = 'open';
        $this->js("\$store.modals.open('instant-meeting-modal')");
    }

    public function createInstantMeeting(): void
    {
        $this->validate([
            'instantTopic' => ['required', 'string', 'min:2', 'max:150'],
            'instantMode' => ['required', 'in:video,audio'],
            'instantAccessMode' => ['required', 'in:open,invited_only'],
        ]);

        $user = auth()->user();
        if (! $user) {
            return;
        }

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);

        try {
            $meeting = $meetingService->createInstantMeeting(
                host: $user,
                title: $this->instantTopic,
                mode: $this->instantMode,
                accessMode: $this->instantAccessMode
            );

            $this->js("\$store.modals.close('instant-meeting-modal')");
            Toast::dispatch($this, 'success', __('Instant meeting created!'));
            $this->redirectRoute('meetings.room', ['uuid' => $meeting->uuid], navigate: true);
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function scheduleMeeting(): void
    {
        if (empty($this->endsAt) || Carbon::parse($this->endsAt)->lte(Carbon::parse($this->scheduledAt))) {
            $this->endsAt = Carbon::parse($this->scheduledAt)->addMinutes($this->durationMinutes)->format('Y-m-d\TH:i');
        }

        $this->validate([
            'meetingTitle' => ['required', 'string', 'min:3', 'max:150'],
            'scheduledAt' => ['required', 'date', 'after:now'],
            'endsAt' => ['nullable', 'date'],
            'meetingMode' => ['required', 'in:video,audio'],
            'accessMode' => ['required', 'in:open,invited_only'],
            'durationMinutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'repeatType' => ['required', 'string'],
            'repeatUntil' => ['nullable', 'date'],
            'reminderOffsetMinutes' => ['required', 'integer'],
            'meetingDescription' => ['nullable', 'string', 'max:1000'],
            'passcode' => ['nullable', 'string', 'max:32'],
        ]);

        $user = auth()->user();
        if (! $user) {
            return;
        }

        // Parse custom emails
        $customEmails = array_filter(
            array_map('trim', preg_split('/[\r\n,;]+/', $this->customEmailsInput)),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        );

        /** @var MeetingService $meetingService */
        $meetingService = app(MeetingService::class);

        try {
            $meetingService->scheduleMeeting(
                host: $user,
                title: $this->meetingTitle,
                scheduledAt: $this->scheduledAt,
                endsAt: $this->endsAt ?: null,
                mode: $this->meetingMode,
                accessMode: $this->accessMode,
                durationMinutes: $this->durationMinutes,
                repeatType: $this->repeatType,
                repeatUntil: $this->repeatUntil ?: null,
                reminderOffsetMinutes: $this->reminderOffsetMinutes,
                reminderChannels: $this->reminderChannels,
                description: $this->meetingDescription ?: null,
                passcode: $this->passcode ?: null,
                inviteeUserIds: $this->selectedInvitees,
                coHostUserIds: $this->selectedCoHosts,
                customEmails: $customEmails,
                dispatchChannels: $this->dispatchChannels
            );

            $this->meetingTitle = '';
            $this->meetingDescription = '';
            $this->passcode = '';
            $this->selectedInvitees = [];
            $this->selectedCoHosts = [];
            $this->customEmailsInput = '';
            $this->js("\$store.modals.close('schedule-meeting-modal')");

            Toast::dispatch($this, 'success', __('Meeting scheduled successfully and invitations dispatched!'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openEditModal(int $meetingId): void
    {
        $meeting = ChatMeeting::with(['participants'])->find($meetingId);
        if (! $meeting) {
            return;
        }

        $user = auth()->user();
        if (! $user || ! $meeting->canManageMeeting($user)) {
            Toast::dispatch($this, 'error', __('You do not have permission to edit this meeting.'));
            return;
        }

        $this->editingMeetingId = $meeting->id;
        $this->editTitle = $meeting->title;
        $this->editDescription = $meeting->description ?? '';
        $this->editScheduledAt = $meeting->scheduled_at ? $meeting->scheduled_at->format('Y-m-d\TH:i') : '';
        $this->editEndsAt = $meeting->ends_at ? $meeting->ends_at->format('Y-m-d\TH:i') : '';
        $this->editMode = $meeting->mode;
        $this->editAccessMode = $meeting->access_mode;
        $this->editDurationMinutes = $meeting->duration_minutes;
        $this->editRepeatType = $meeting->repeat_type ?? 'none';
        $this->editRepeatUntil = $meeting->repeat_until ? $meeting->repeat_until->format('Y-m-d') : '';
        $this->editReminderOffsetMinutes = $meeting->reminder_offset_minutes ?? 15;
        $this->editReminderChannels = $meeting->reminder_channels ?? ['database', 'email'];
        $this->editPasscode = $meeting->passcode ?? '';

        $this->editSelectedCoHosts = $meeting->participants()
            ->where('role', ChatMeetingParticipant::ROLE_CO_HOST)
            ->pluck('user_id')
            ->toArray();

        $this->editSelectedInvitees = $meeting->participants()
            ->where('role', ChatMeetingParticipant::ROLE_PARTICIPANT)
            ->pluck('user_id')
            ->toArray();

        $this->editCustomEmailsInput = '';

        $this->js("\$store.modals.open('edit-meeting-modal')");
    }

    public function updateScheduledMeeting(): void
    {
        if (! $this->editingMeetingId) {
            return;
        }

        if (empty($this->editEndsAt) || Carbon::parse($this->editEndsAt)->lte(Carbon::parse($this->editScheduledAt))) {
            $this->editEndsAt = Carbon::parse($this->editScheduledAt)->addMinutes($this->editDurationMinutes)->format('Y-m-d\TH:i');
        }

        $this->validate([
            'editTitle' => ['required', 'string', 'min:3', 'max:150'],
            'editScheduledAt' => ['required', 'date'],
            'editEndsAt' => ['nullable', 'date'],
            'editMode' => ['required', 'in:video,audio'],
            'editAccessMode' => ['required', 'in:open,invited_only'],
            'editDurationMinutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'editRepeatType' => ['required', 'string'],
            'editRepeatUntil' => ['nullable', 'date'],
            'editReminderOffsetMinutes' => ['required', 'integer'],
            'editDescription' => ['nullable', 'string', 'max:1000'],
            'editPasscode' => ['nullable', 'string', 'max:32'],
        ]);

        $meeting = ChatMeeting::find($this->editingMeetingId);
        $user = auth()->user();

        if ($meeting && $user) {
            $customEmails = array_filter(
                array_map('trim', preg_split('/[\r\n,;]+/', $this->editCustomEmailsInput)),
                fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
            );

            /** @var MeetingService $meetingService */
            $meetingService = app(MeetingService::class);

            try {
                $meetingService->updateMeeting(
                    meeting: $meeting,
                    actor: $user,
                    data: [
                        'title' => $this->editTitle,
                        'description' => $this->editDescription,
                        'scheduled_at' => $this->editScheduledAt,
                        'ends_at' => $this->editEndsAt ?: null,
                        'mode' => $this->editMode,
                        'access_mode' => $this->editAccessMode,
                        'duration_minutes' => $this->editDurationMinutes,
                        'repeat_type' => $this->editRepeatType,
                        'repeat_until' => $this->editRepeatUntil ?: null,
                        'reminder_offset_minutes' => $this->editReminderOffsetMinutes,
                        'reminder_channels' => $this->editReminderChannels,
                        'passcode' => $this->editPasscode ?: null,
                    ],
                    inviteeUserIds: $this->editSelectedInvitees,
                    coHostUserIds: $this->editSelectedCoHosts,
                    customEmails: $customEmails,
                    dispatchChannels: $this->editDispatchChannels
                );

                $this->js("\$store.modals.close('edit-meeting-modal')");
                $this->editingMeetingId = null;
                Toast::dispatch($this, 'success', __('Meeting details updated successfully!'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
    }

    public function promptCancelMeeting(int $meetingId): void
    {
        $this->confirmCancelMeetingId = $meetingId;
        $this->cancelReason = '';
        $this->cancelNotifyChannels = ['database', 'email'];
        $this->cancelCustomEmailsInput = '';
        $this->js("\$store.modals.open('cancel-meeting-modal')");
    }

    public function executeCancelMeeting(): void
    {
        if (! $this->confirmCancelMeetingId) {
            return;
        }

        $meeting = ChatMeeting::find($this->confirmCancelMeetingId);
        $user = auth()->user();

        $this->js("\$store.modals.close('cancel-meeting-modal')");

        if ($meeting && $user) {
            $customEmails = array_filter(
                array_map('trim', preg_split('/[\r\n,;]+/', $this->cancelCustomEmailsInput)),
                fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
            );

            /** @var MeetingService $meetingService */
            $meetingService = app(MeetingService::class);

            try {
                $meetingService->cancelMeeting(
                    meeting: $meeting,
                    actor: $user,
                    reason: $this->cancelReason,
                    notifyChannels: $this->cancelNotifyChannels,
                    customEmails: $customEmails
                );
                Toast::dispatch($this, 'info', __('Meeting cancelled and cancellation alerts dispatched.'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
        $this->confirmCancelMeetingId = null;
    }

    public function promptJoinMeeting(int $meetingId): void
    {
        $this->confirmJoinMeetingId = $meetingId;
        $this->js("\$store.modals.open('join-confirm-modal')");
    }

    public function executeJoinMeeting(): void
    {
        if (! $this->confirmJoinMeetingId) {
            return;
        }

        $meeting = ChatMeeting::find($this->confirmJoinMeetingId);
        $this->js("\$store.modals.close('join-confirm-modal')");

        if ($meeting) {
            $this->redirectRoute('meetings.room', ['uuid' => $meeting->uuid], navigate: true);
        }
    }

    public function promptEndMeeting(int $meetingId): void
    {
        $this->confirmEndMeetingId = $meetingId;
        $this->js("\$store.modals.open('end-meeting-confirm-modal')");
    }

    public function executeEndMeeting(): void
    {
        if (! $this->confirmEndMeetingId) {
            return;
        }

        $meeting = ChatMeeting::find($this->confirmEndMeetingId);
        $user = auth()->user();

        $this->js("\$store.modals.close('end-meeting-confirm-modal')");

        if ($meeting && $user) {
            /** @var MeetingService $meetingService */
            $meetingService = app(MeetingService::class);
            try {
                $meetingService->endMeeting($meeting, $user);
                Toast::dispatch($this, 'success', __('Meeting ended for all participants.'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
        $this->confirmEndMeetingId = null;
    }

    public function openShareModal(int $meetingId): void
    {
        $this->selectedMeetingId = $meetingId;
        $this->shareModalBulkEmails = '';
        $this->js("\$store.modals.open('share-meeting-modal')");
    }

    public function sendAdditionalEmailInvites(): void
    {
        if (! $this->selectedMeetingId || empty(trim($this->shareModalBulkEmails))) {
            return;
        }

        $meeting = ChatMeeting::find($this->selectedMeetingId);
        $user = auth()->user();

        if ($meeting && $user) {
            $emails = array_filter(
                array_map('trim', preg_split('/[\r\n,;]+/', $this->shareModalBulkEmails)),
                fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
            );

            if (empty($emails)) {
                Toast::dispatch($this, 'warning', __('Please enter valid email addresses.'));
                return;
            }

            /** @var MeetingService $meetingService */
            $meetingService = app(MeetingService::class);
            $count = $meetingService->sendBulkEmailInvitations($meeting, $emails, $user);

            $this->shareModalBulkEmails = '';
            Toast::dispatch($this, 'success', __('Sent :count personalized email invitations!', ['count' => $count]));
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        $userId = $user->id;

        $query = ChatMeeting::query()->with(['host', 'participants.user']);

        if ($this->filterTab === 'upcoming') {
            $query->forUser($userId)->upcoming();
        } elseif ($this->filterTab === 'past') {
            $query->forUser($userId)->past();
        } elseif ($this->filterTab === 'my_meetings') {
            $query->where('host_id', $userId)->orderBy('created_at', 'desc');
        }

        $meetings = $query->paginate(12);

        $selectedMeeting = $this->selectedMeetingId
            ? ChatMeeting::with('host')->find($this->selectedMeetingId)
            : null;

        $confirmJoinMeeting = $this->confirmJoinMeetingId
            ? ChatMeeting::with('host')->find($this->confirmJoinMeetingId)
            : null;

        $confirmEndMeeting = $this->confirmEndMeetingId
            ? ChatMeeting::with('host')->find($this->confirmEndMeetingId)
            : null;

        $confirmCancelMeeting = $this->confirmCancelMeetingId
            ? ChatMeeting::with('host')->find($this->confirmCancelMeetingId)
            : null;

        $availableUsers = User::where('id', '!=', $userId)
            ->whereNull('deleted_at')
            ->limit(50)
            ->get();

        return [
            'meetings' => $meetings,
            'selectedMeeting' => $selectedMeeting,
            'confirmJoinMeeting' => $confirmJoinMeeting,
            'confirmEndMeeting' => $confirmEndMeeting,
            'confirmCancelMeeting' => $confirmCancelMeeting,
            'availableUsers' => $availableUsers,
            'currentUser' => $user,
        ];
    }
};
?>

<div class="space-y-6" x-data="{ copiedInvite: false }">
    <!-- Header Banner -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-6 rounded-2xl bg-gradient-to-r from-primary/10 via-card to-card border border-border shadow-xs">
        <div class="space-y-1">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-xl bg-primary text-primary-foreground">
                    <x-icon name="video" class="h-6 w-6" />
                </div>
                <h1 class="text-xl font-bold text-foreground">{{ __('Online Meetings & Video Conferences') }}</h1>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ __('Host instant peer conferences or schedule recurring webinars with start & end times, multi-channel reminders, pinned chats & QR access.') }}
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <!-- Start Instant Video Meeting (Opens Modal with Topic & Access options) -->
            <x-ui.button wire:click="openInstantModal" variant="default" icon="video" size="sm">
                {{ __('Start Instant Meeting') }}
            </x-ui.button>

            <!-- Schedule Meeting -->
            <x-ui.button x-on:click="$store.modals.open('schedule-meeting-modal')" variant="outline" icon="calendar-plus" size="sm">
                {{ __('Schedule Meeting') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="flex items-center gap-2 border-b border-border pb-2">
        @foreach (['upcoming' => __('Upcoming & Live'), 'my_meetings' => __('Hosted by Me'), 'past' => __('Past & History')] as $tKey => $tLabel)
            <button
                type="button"
                wire:click="$set('filterTab', '{{ $tKey }}')"
                class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer {{ $filterTab === $tKey ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground hover:bg-secondary' }}"
            >
                {{ $tLabel }}
            </button>
        @endforeach
    </div>

    <!-- Meetings Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($meetings as $m)
            @php
                $isHostOrCoHost = $m->isHostOrCoHost($currentUser);
                $isLive = $m->isLive();
            @endphp
            <div
                wire:key="meeting-{{ $m->id }}"
                class="rounded-2xl border border-border bg-card p-5 shadow-xs flex flex-col justify-between space-y-4 hover:border-primary/50 transition-colors relative overflow-hidden"
            >
                @if ($isLive)
                    <div class="absolute top-0 right-0 px-3 py-1 bg-emerald-500 text-white text-[10px] font-bold rounded-bl-xl uppercase tracking-wider flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-white animate-ping"></span>
                        {{ __('Live Now') }}
                    </div>
                @elseif ($m->repeat_type && $m->repeat_type !== 'none')
                    <div class="absolute top-0 right-0 px-2.5 py-0.5 bg-blue-500/10 text-blue-600 dark:text-blue-400 text-[10px] font-semibold rounded-bl-xl border-l border-b border-blue-500/20 flex items-center gap-1">
                        <x-icon name="repeat" class="h-3 w-3" />
                        <span>{{ $m->repeatLabel() }}</span>
                    </div>
                @endif

                <div class="space-y-2.5">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-xl {{ $m->isVideo() ? 'bg-blue-500/10 text-blue-500' : 'bg-emerald-500/10 text-emerald-500' }} shrink-0">
                            <x-icon :name="$m->isVideo() ? 'video' : 'phone'" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-bold text-foreground truncate">{{ $m->title }}</h3>
                            <p class="text-[11px] text-muted-foreground flex items-center gap-1 mt-0.5">
                                <x-icon name="clock" class="h-3 w-3 inline shrink-0" />
                                <span class="font-medium text-foreground">{{ $m->formattedScheduledAt() }}</span>
                            </p>
                            @if ($m->ends_at)
                                <p class="text-[10px] text-muted-foreground flex items-center gap-1">
                                    <span>{{ __('Ends:') }} {{ $m->formattedEndsAt() }}</span>
                                    <span>• {{ $m->formattedDuration() }}</span>
                                </p>
                            @else
                                <p class="text-[10px] text-muted-foreground">
                                    <span>{{ __('Duration:') }} {{ $m->formattedDuration() }}</span>
                                </p>
                            @endif
                        </div>
                    </div>

                    @if ($m->description)
                        <p class="text-xs text-muted-foreground line-clamp-2">{{ $m->description }}</p>
                    @endif

                    <!-- Details & Badges Row -->
                    <div class="flex items-center justify-between gap-1 pt-2 border-t border-border/50 text-[11px] text-muted-foreground flex-wrap">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <x-ui.avatar :name="$m->host?->name" :initials="$m->host?->initials()" :src="$m->host?->avatarUrl()" size="size-5 text-[9px]" />
                            <span class="truncate">{{ __('Host:') }} <strong class="text-foreground">{{ $m->host?->name }}</strong></span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            @if ($m->reminder_offset_minutes)
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-secondary text-muted-foreground flex items-center gap-1" title="{{ __('Reminder:') }} {{ $m->reminderLabel() }}">
                                    <x-icon name="bell" class="h-3 w-3 text-amber-500" />
                                    <span>{{ $m->reminderLabel() }}</span>
                                </span>
                            @endif

                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $m->access_mode === 'invited_only' ? 'bg-amber-500/10 text-amber-600' : 'bg-secondary text-muted-foreground' }}">
                                {{ $m->access_mode === 'invited_only' ? __('Invited Only') : __('Open Access') }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="flex items-center justify-between gap-2 pt-3 border-t border-border">
                    <div class="flex items-center gap-1">
                        <!-- Share & QR -->
                        <button
                            type="button"
                            wire:click="openShareModal({{ $m->id }})"
                            class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                            title="{{ __('Share Invitation & Bulk Email') }}"
                        >
                            <x-icon name="share-2" class="h-4 w-4" />
                        </button>

                        <!-- Edit / Update Meeting Button (for Host/Co-Host) -->
                        @if ($isHostOrCoHost && ! $m->isEnded())
                            <button
                                type="button"
                                wire:click="openEditModal({{ $m->id }})"
                                class="p-2 rounded-lg text-muted-foreground hover:text-primary hover:bg-primary/10 transition-colors cursor-pointer"
                                title="{{ __('Edit & Modify Meeting Schedule') }}"
                            >
                                <x-icon name="edit" class="h-4 w-4" />
                            </button>
                        @endif

                        <!-- Cancel Meeting Button with Confirmation & Notifications -->
                        @if ($isHostOrCoHost && ! $m->isEnded() && $m->isScheduled())
                            <button
                                type="button"
                                wire:click="promptCancelMeeting({{ $m->id }})"
                                class="p-2 rounded-lg text-rose-500 hover:bg-rose-500/10 transition-colors cursor-pointer"
                                title="{{ __('Cancel Meeting with Notice') }}"
                            >
                                <x-icon name="x-circle" class="h-4 w-4" />
                            </button>
                        @endif

                        <!-- End Meeting Button (Live) -->
                        @if ($isHostOrCoHost && $isLive)
                            <button
                                type="button"
                                wire:click="promptEndMeeting({{ $m->id }})"
                                class="p-2 rounded-lg text-rose-500 hover:bg-rose-500/10 transition-colors cursor-pointer"
                                title="{{ __('End Meeting for All') }}"
                            >
                                <x-icon name="phone-off" class="h-4 w-4" />
                            </button>
                        @endif
                    </div>

                    @if (! $m->isEnded())
                        <button
                            type="button"
                            wire:click="promptJoinMeeting({{ $m->id }})"
                            class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all shadow-xs cursor-pointer {{ $isLive ? 'bg-emerald-600 hover:bg-emerald-700 text-white animate-pulse' : 'bg-primary text-primary-foreground hover:opacity-90' }}"
                        >
                            {{ $isLive ? __('Join Live Room') : __('Enter Room') }}
                        </button>
                    @elseif ($m->status === 'cancelled')
                        <span class="text-xs text-rose-500 font-semibold italic">{{ __('Cancelled') }}</span>
                    @else
                        <span class="text-xs text-muted-foreground italic">{{ __('Ended') }}</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-span-full py-16 text-center text-muted-foreground">
                <x-icon name="video-off" class="h-10 w-10 mx-auto mb-2 opacity-30" />
                <h4 class="text-sm font-bold text-foreground">{{ __('No meetings found') }}</h4>
                <p class="text-xs text-muted-foreground mt-1">{{ __('Start an instant meeting or schedule a conference.') }}</p>
            </div>
        @endforelse
    </div>

    <!-- 1. Modal: Instant Meeting Setup (Topic & Access Type) -->
    <x-ui.modal name="instant-meeting-modal" max-width="max-w-md" title="{{ __('Start Instant Meeting') }}">
        <div class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Meeting Topic / Title') }}</label>
                <x-ui.input wire:model="instantTopic" placeholder="{{ __('e.g. Quick Doubts & Mentorship Session') }}" />
                @error('instantTopic') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Conference Mode') }}</label>
                <select wire:model="instantMode" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                    <option value="video">{{ __('Audio & Video Conference') }}</option>
                    <option value="audio">{{ __('Audio-Only Conference') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Access Mode') }}</label>
                <select wire:model="instantAccessMode" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                    <option value="open">{{ __('Open for Everyone (Direct Join)') }}</option>
                    <option value="invited_only">{{ __('Invited Users Only (Waiting Room for Guests)') }}</option>
                </select>
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('In "Open Access" mode, anyone with the link can join directly. In "Invited Only" mode, uninvited attendees wait in the lobby until host admits them.') }}
                </p>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button x-on:click="$store.modals.close('instant-meeting-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="createInstantMeeting" variant="default" icon="video">
                    {{ __('Start Meeting Now') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- 2. Modal: Schedule Meeting (Start/End Time, Recurrence, Reminders) -->
    <x-ui.modal name="schedule-meeting-modal" max-width="max-w-xl" title="{{ __('Schedule Online Meeting') }}">
        <div class="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Meeting Title / Topic') }}</label>
                <x-ui.input wire:model="meetingTitle" placeholder="{{ __('e.g. UPSC Mains Mentorship & Doubts Clearing') }}" />
                @error('meetingTitle') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <!-- Start Date/Time and End Date/Time -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Date & Time') }}</label>
                    <x-ui.input type="datetime-local" wire:model="scheduledAt" />
                    @error('scheduledAt') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Date & Time') }}</label>
                    <x-ui.input type="datetime-local" wire:model="endsAt" />
                    @error('endsAt') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Duration (Minutes)') }}</label>
                    <x-ui.input type="number" wire:model="durationMinutes" min="15" max="1440" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Conference Mode') }}</label>
                    <select wire:model="meetingMode" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                        <option value="video">{{ __('Audio & Video Conference') }}</option>
                        <option value="audio">{{ __('Audio-Only Conference') }}</option>
                    </select>
                </div>
            </div>

            <!-- Recurrence / Repeat Options -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 rounded-xl border border-border bg-secondary/20">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1 flex items-center gap-1">
                        <x-icon name="repeat" class="h-3.5 w-3.5 text-primary" />
                        <span>{{ __('Recurrence / Repeat Type') }}</span>
                    </label>
                    <select wire:model.live="repeatType" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                        <option value="none">{{ __('Does not repeat') }}</option>
                        <option value="all_day">{{ __('All Day') }}</option>
                        <option value="daily">{{ __('Daily') }}</option>
                        <option value="weekly">{{ __('Weekly') }}</option>
                        <option value="specific_day">{{ __('Specific Day of Week') }}</option>
                        <option value="monthly">{{ __('Monthly') }}</option>
                        <option value="annually">{{ __('Annually') }}</option>
                        <option value="every_weekday">{{ __('Every Weekday (Mon–Fri)') }}</option>
                        <option value="every_weekend">{{ __('Every Weekend (Sat–Sun)') }}</option>
                        <option value="custom">{{ __('Custom Recurrence') }}</option>
                    </select>
                </div>

                @if ($repeatType !== 'none')
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Repeat Until (Optional)') }}</label>
                        <x-ui.input type="date" wire:model="repeatUntil" />
                    </div>
                @else
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Access Mode') }}</label>
                        <select wire:model="accessMode" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                            <option value="open">{{ __('Open for Everyone') }}</option>
                            <option value="invited_only">{{ __('Invited Users Only (Waiting Room)') }}</option>
                        </select>
                    </div>
                @endif
            </div>

            @if ($repeatType !== 'none')
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Access Mode') }}</label>
                    <select wire:model="accessMode" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                        <option value="open">{{ __('Open for Everyone') }}</option>
                        <option value="invited_only">{{ __('Invited Users Only (Waiting Room)') }}</option>
                    </select>
                </div>
            @endif

            <!-- Reminder Notification Settings -->
            <div class="p-3 rounded-xl border border-border bg-secondary/20 space-y-2">
                <label class="block text-xs font-semibold text-foreground flex items-center gap-1">
                    <x-icon name="bell-ring" class="h-3.5 w-3.5 text-amber-500" />
                    <span>{{ __('Reminder Notification Alert') }}</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Send Reminder Before:') }}</label>
                        <select wire:model="reminderOffsetMinutes" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                            <option value="10">{{ __('10 minutes before') }}</option>
                            <option value="15">{{ __('15 minutes before') }}</option>
                            <option value="30">{{ __('30 minutes before') }}</option>
                            <option value="60">{{ __('1 hour before') }}</option>
                            <option value="120">{{ __('2 hours before') }}</option>
                            <option value="1440">{{ __('1 day before') }}</option>
                            <option value="2880">{{ __('2 days before') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Reminder Channels:') }}</label>
                        <div class="flex items-center gap-3 flex-wrap text-xs pt-1">
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="reminderChannels" value="database" class="rounded text-primary" />
                                <span>{{ __('In-App') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="reminderChannels" value="email" class="rounded text-primary" />
                                <span>{{ __('Email') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="reminderChannels" value="whatsapp" class="rounded text-primary" />
                                <span>{{ __('WhatsApp') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="reminderChannels" value="telegram" class="rounded text-primary" />
                                <span>{{ __('Telegram') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="reminderChannels" value="sms" class="rounded text-primary" />
                                <span>{{ __('SMS') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Passcode (Optional)') }}</label>
                <x-ui.input wire:model="passcode" placeholder="{{ __('e.g. 123456') }}" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Agenda / Description') }}</label>
                <x-ui.textarea wire:model="meetingDescription" rows="2" placeholder="{{ __('Meeting agenda, syllabus, or instructions…') }}" />
            </div>

            <!-- Co-Hosts Selection -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select Co-Hosts (Can operate & manage meeting)') }}</label>
                <div class="max-h-24 overflow-y-auto space-y-1 rounded-lg border border-border p-2">
                    @foreach ($availableUsers as $u)
                        <label class="flex items-center gap-2 text-xs p-1 rounded hover:bg-secondary cursor-pointer select-none">
                            <input type="checkbox" wire:model="selectedCoHosts" value="{{ $u->id }}" class="rounded border-border text-primary" />
                            <span>{{ $u->name }} ({{ $u->email }})</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Invitees Selection -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select Registered Invitees') }}</label>
                <div class="max-h-28 overflow-y-auto space-y-1 rounded-lg border border-border p-2">
                    @foreach ($availableUsers as $u)
                        <label class="flex items-center gap-2 text-xs p-1 rounded hover:bg-secondary cursor-pointer select-none">
                            <input type="checkbox" wire:model="selectedInvitees" value="{{ $u->id }}" class="rounded border-border text-primary" />
                            <span>{{ $u->name }} ({{ $u->email }})</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Bulk Custom Email Input -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Additional Invitee Emails (Comma / Newline separated)') }}</label>
                <x-ui.textarea wire:model="customEmailsInput" rows="2" placeholder="student1@gmail.com, guest@edu.org" />
            </div>

            <!-- Multi-channel invitations dispatch -->
            <div class="p-3 rounded-lg border border-border bg-secondary/30 space-y-2">
                <span class="text-xs font-semibold text-foreground block">{{ __('Dispatch Initial Invitations Via:') }}</span>
                <div class="flex items-center gap-4 flex-wrap text-xs">
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchChannels" value="database" class="rounded text-primary" />
                        <span>{{ __('In-App Notice') }}</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchChannels" value="email" class="rounded text-primary" />
                        <span>{{ __('Email') }}</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchChannels" value="whatsapp" class="rounded text-primary" />
                        <span>{{ __('WhatsApp') }}</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchChannels" value="telegram" class="rounded text-primary" />
                        <span>{{ __('Telegram') }}</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchChannels" value="sms" class="rounded text-primary" />
                        <span>{{ __('SMS') }}</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button x-on:click="$store.modals.close('schedule-meeting-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="scheduleMeeting" variant="default" icon="check">
                    {{ __('Schedule & Send Invites') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- 3. Modal: Edit / Modify Scheduled Meeting -->
    <x-ui.modal name="edit-meeting-modal" max-width="max-w-xl" title="{{ __('Edit Scheduled Meeting') }}">
        <div class="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Meeting Title / Topic') }}</label>
                <x-ui.input wire:model="editTitle" />
                @error('editTitle') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Date & Time') }}</label>
                    <x-ui.input type="datetime-local" wire:model="editScheduledAt" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Date & Time') }}</label>
                    <x-ui.input type="datetime-local" wire:model="editEndsAt" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Duration (Minutes)') }}</label>
                    <x-ui.input type="number" wire:model="editDurationMinutes" min="15" max="1440" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Conference Mode') }}</label>
                    <select wire:model="editMode" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                        <option value="video">{{ __('Audio & Video Conference') }}</option>
                        <option value="audio">{{ __('Audio-Only Conference') }}</option>
                    </select>
                </div>
            </div>

            <!-- Recurrence -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 rounded-xl border border-border bg-secondary/20">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Recurrence') }}</label>
                    <select wire:model="editRepeatType" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                        <option value="none">{{ __('Does not repeat') }}</option>
                        <option value="all_day">{{ __('All Day') }}</option>
                        <option value="daily">{{ __('Daily') }}</option>
                        <option value="weekly">{{ __('Weekly') }}</option>
                        <option value="specific_day">{{ __('Specific Day of Week') }}</option>
                        <option value="monthly">{{ __('Monthly') }}</option>
                        <option value="annually">{{ __('Annually') }}</option>
                        <option value="every_weekday">{{ __('Every Weekday (Mon–Fri)') }}</option>
                        <option value="every_weekend">{{ __('Every Weekend (Sat–Sun)') }}</option>
                        <option value="custom">{{ __('Custom Recurrence') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Access Mode') }}</label>
                    <select wire:model="editAccessMode" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                        <option value="open">{{ __('Open for Everyone') }}</option>
                        <option value="invited_only">{{ __('Invited Users Only (Waiting Room)') }}</option>
                    </select>
                </div>
            </div>

            <!-- Reminders -->
            <div class="p-3 rounded-xl border border-border bg-secondary/20 space-y-2">
                <label class="block text-xs font-semibold text-foreground">{{ __('Reminder Notification Alert') }}</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Send Reminder Before:') }}</label>
                        <select wire:model="editReminderOffsetMinutes" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                            <option value="10">{{ __('10 minutes before') }}</option>
                            <option value="15">{{ __('15 minutes before') }}</option>
                            <option value="30">{{ __('30 minutes before') }}</option>
                            <option value="60">{{ __('1 hour before') }}</option>
                            <option value="120">{{ __('2 hours before') }}</option>
                            <option value="1440">{{ __('1 day before') }}</option>
                            <option value="2880">{{ __('2 days before') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Reminder Channels:') }}</label>
                        <div class="flex items-center gap-3 flex-wrap text-xs pt-1">
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="editReminderChannels" value="database" class="rounded text-primary" />
                                <span>{{ __('In-App') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="editReminderChannels" value="email" class="rounded text-primary" />
                                <span>{{ __('Email') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="editReminderChannels" value="whatsapp" class="rounded text-primary" />
                                <span>{{ __('WhatsApp') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="editReminderChannels" value="telegram" class="rounded text-primary" />
                                <span>{{ __('Telegram') }}</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" wire:model="editReminderChannels" value="sms" class="rounded text-primary" />
                                <span>{{ __('SMS') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Passcode (Optional)') }}</label>
                <x-ui.input wire:model="editPasscode" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Agenda / Description') }}</label>
                <x-ui.textarea wire:model="editDescription" rows="2" />
            </div>

            <!-- Co-Hosts Selection -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Modify Co-Hosts') }}</label>
                <div class="max-h-24 overflow-y-auto space-y-1 rounded-lg border border-border p-2">
                    @foreach ($availableUsers as $u)
                        <label class="flex items-center gap-2 text-xs p-1 rounded hover:bg-secondary cursor-pointer select-none">
                            <input type="checkbox" wire:model="editSelectedCoHosts" value="{{ $u->id }}" class="rounded border-border text-primary" />
                            <span>{{ $u->name }} ({{ $u->email }})</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Invitees Selection -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Modify Invitees') }}</label>
                <div class="max-h-28 overflow-y-auto space-y-1 rounded-lg border border-border p-2">
                    @foreach ($availableUsers as $u)
                        <label class="flex items-center gap-2 text-xs p-1 rounded hover:bg-secondary cursor-pointer select-none">
                            <input type="checkbox" wire:model="editSelectedInvitees" value="{{ $u->id }}" class="rounded border-border text-primary" />
                            <span>{{ $u->name }} ({{ $u->email }})</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Additional Emails -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Send Update Notice to Additional Emails') }}</label>
                <x-ui.textarea wire:model="editCustomEmailsInput" rows="2" placeholder="guest@edu.org" />
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button x-on:click="$store.modals.close('edit-meeting-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="updateScheduledMeeting" variant="default" icon="check">
                    {{ __('Save & Update Meeting') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- 4. Modal: Cancel Meeting Confirmation with Multi-Channel Notice -->
    @if ($confirmCancelMeeting)
        <x-ui.modal name="cancel-meeting-modal" max-width="max-w-md" title="{{ __('Cancel Online Meeting') }}">
            <div class="space-y-4 text-left">
                <div class="text-center space-y-1">
                    <div class="h-12 w-12 rounded-full bg-rose-500/10 text-rose-500 flex items-center justify-center mx-auto mb-2">
                        <x-icon name="alert-triangle" class="h-6 w-6" />
                    </div>
                    <h3 class="text-base font-bold text-foreground">{{ __('Cancel ":title"?', ['title' => $confirmCancelMeeting->title]) }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Are you sure you want to cancel this meeting? A cancellation alert will be dispatched to all invited attendees.') }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Cancellation Reason (Optional)') }}</label>
                    <x-ui.input wire:model="cancelReason" placeholder="{{ __('e.g. Rescheduled due to faculty absence') }}" />
                </div>

                <div class="p-3 rounded-lg border border-border bg-secondary/30 space-y-2">
                    <span class="text-xs font-semibold text-foreground block">{{ __('Dispatch Cancellation Alerts Via:') }}</span>
                    <div class="flex items-center gap-3 flex-wrap text-xs">
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" wire:model="cancelNotifyChannels" value="database" class="rounded text-primary" />
                            <span>{{ __('In-App') }}</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" wire:model="cancelNotifyChannels" value="email" class="rounded text-primary" />
                            <span>{{ __('Email') }}</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" wire:model="cancelNotifyChannels" value="whatsapp" class="rounded text-primary" />
                            <span>{{ __('WhatsApp') }}</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" wire:model="cancelNotifyChannels" value="telegram" class="rounded text-primary" />
                            <span>{{ __('Telegram') }}</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" wire:model="cancelNotifyChannels" value="sms" class="rounded text-primary" />
                            <span>{{ __('SMS') }}</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Also Notify External Emails (Optional)') }}</label>
                    <x-ui.input wire:model="cancelCustomEmailsInput" placeholder="external@guest.org, student@domain.com" />
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <x-ui.button x-on:click="$store.modals.close('cancel-meeting-modal')" variant="secondary">
                        {{ __('Keep Meeting') }}
                    </x-ui.button>
                    <x-ui.button wire:click="executeCancelMeeting" variant="destructive" icon="x-circle">
                        {{ __('Confirm Cancellation') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    <!-- 5. Modal: Join Meeting Confirmation -->
    @if ($confirmJoinMeeting)
        <x-ui.modal name="join-confirm-modal" max-width="max-w-md" title="{{ __('Join Meeting Confirmation') }}">
            <div class="space-y-4 text-center">
                <div class="h-14 w-14 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto">
                    <x-icon :name="$confirmJoinMeeting->isVideo() ? 'video' : 'phone'" class="h-7 w-7" />
                </div>
                <div class="space-y-1">
                    <h3 class="text-base font-bold text-foreground">{{ $confirmJoinMeeting->title }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Hosted by :host', ['host' => $confirmJoinMeeting->host?->name]) }}
                    </p>
                </div>

                <div class="p-3 rounded-xl border border-border bg-secondary/30 text-left text-xs space-y-1">
                    <p><strong class="text-foreground">{{ __('Access:') }}</strong> {{ $confirmJoinMeeting->access_mode === 'invited_only' ? __('Invited Only (Waiting Room)') : __('Open for Everyone') }}</p>
                    <p><strong class="text-foreground">{{ __('Mode:') }}</strong> {{ $confirmJoinMeeting->isVideo() ? __('Audio & Video') : __('Audio Only') }}</p>
                    <p><strong class="text-foreground">{{ __('Duration:') }}</strong> {{ $confirmJoinMeeting->formattedDuration() }}</p>
                    <p class="text-[11px] text-muted-foreground pt-1">
                        {{ __('Please ensure your camera and microphone permissions are enabled in your browser.') }}
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <x-ui.button x-on:click="$store.modals.close('join-confirm-modal')" variant="secondary">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="executeJoinMeeting" variant="default" icon="video">
                        {{ __('Join Meeting Now') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    <!-- 6. Modal: End Meeting for All Confirmation -->
    @if ($confirmEndMeeting)
        <x-ui.modal name="end-meeting-confirm-modal" max-width="max-w-md" title="{{ __('End Meeting for All') }}">
            <div class="space-y-4 text-center">
                <div class="h-14 w-14 rounded-full bg-rose-500/10 text-rose-500 flex items-center justify-center mx-auto">
                    <x-icon name="alert-triangle" class="h-7 w-7" />
                </div>
                <div class="space-y-1">
                    <h3 class="text-base font-bold text-foreground">{{ __('End ":title"?', ['title' => $confirmEndMeeting->title]) }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Are you sure you want to end this meeting for all participants? This will disconnect all attendees and close the room.') }}
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <x-ui.button x-on:click="$store.modals.close('end-meeting-confirm-modal')" variant="secondary">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="executeEndMeeting" variant="destructive" icon="phone-off">
                        {{ __('End Meeting for All') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    <!-- 7. Modal: Share Meeting, QR Code & Bulk Email Inviter -->
    @if ($selectedMeeting)
        @php
            $joinUrl = $selectedMeeting->join_url;
            $shareText = __("You are invited to join an Online :mode Meeting:\n📌 Topic: :title\n⏰ Time: :time\n🔗 Link: :url", [
                'mode' => ucfirst($selectedMeeting->mode),
                'title' => $selectedMeeting->title,
                'time' => $selectedMeeting->formattedScheduledAt(),
                'url' => $joinUrl,
            ]);
            if ($selectedMeeting->passcode) {
                $shareText .= "\n" . __('Passcode: :passcode', ['passcode' => $selectedMeeting->passcode]);
            }
        @endphp
        <x-ui.modal name="share-meeting-modal" max-width="max-w-md" title="{{ __('Meeting Invitation & QR Access') }}">
            <div
                x-data="{
                    copiedInvite: false,
                    copiedLink: false,
                    copyText(text, isLink = false) {
                        try {
                            if (navigator.clipboard && window.isSecureContext) {
                                navigator.clipboard.writeText(text).then(() => {
                                    if (isLink) {
                                        this.copiedLink = true;
                                        setTimeout(() => this.copiedLink = false, 2000);
                                    } else {
                                        this.copiedInvite = true;
                                        setTimeout(() => this.copiedInvite = false, 2000);
                                    }
                                }).catch(() => this.fallbackCopy(text, isLink));
                            } else {
                                this.fallbackCopy(text, isLink);
                            }
                        } catch (e) {
                            this.fallbackCopy(text, isLink);
                        }
                    },
                    fallbackCopy(text, isLink) {
                        const ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.top = '-9999px';
                        ta.style.left = '-9999px';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        try {
                            document.execCommand('copy');
                            if (isLink) {
                                this.copiedLink = true;
                                setTimeout(() => this.copiedLink = false, 2000);
                            } else {
                                this.copiedInvite = true;
                                setTimeout(() => this.copiedInvite = false, 2000);
                            }
                        } catch (err) {}
                        document.body.removeChild(ta);
                    }
                }"
                class="space-y-5 text-center"
            >
                <!-- QR Code Box -->
                <div class="p-4 bg-white rounded-2xl border border-border shadow-xs inline-block mx-auto">
                    <div class="h-44 w-44 flex items-center justify-center mx-auto bg-slate-50 border border-slate-200 rounded-xl overflow-hidden p-2">
                        <img
                            src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={{ urlencode($joinUrl) }}"
                            alt="QR Code"
                            class="h-full w-full object-contain"
                        />
                    </div>
                    <p class="text-[11px] text-zinc-600 font-medium mt-2">{{ __('Scan with smartphone camera to join meeting') }}</p>
                </div>

                <!-- Invite Details Card -->
                <div class="p-3 rounded-xl border border-border bg-secondary/30 text-left space-y-1.5 text-xs">
                    <p><strong class="text-foreground">{{ __('Topic:') }}</strong> {{ $selectedMeeting->title }}</p>
                    <p><strong class="text-foreground">{{ __('Start Time:') }}</strong> {{ $selectedMeeting->formattedScheduledAt() }}</p>
                    @if ($selectedMeeting->ends_at)
                        <p><strong class="text-foreground">{{ __('End Time:') }}</strong> {{ $selectedMeeting->formattedEndsAt() }} ({{ $selectedMeeting->formattedDuration() }})</p>
                    @else
                        <p><strong class="text-foreground">{{ __('Duration:') }}</strong> {{ $selectedMeeting->formattedDuration() }}</p>
                    @endif
                    @if ($selectedMeeting->repeat_type && $selectedMeeting->repeat_type !== 'none')
                        <p><strong class="text-foreground">{{ __('Recurrence:') }}</strong> {{ $selectedMeeting->repeatLabel() }}</p>
                    @endif
                    <p><strong class="text-foreground">{{ __('Host:') }}</strong> {{ $selectedMeeting->host?->name }}</p>
                    <p><strong class="text-foreground">{{ __('Access:') }}</strong> {{ $selectedMeeting->access_mode === 'invited_only' ? __('Invited Users (Waiting Room)') : __('Open Access') }}</p>
                    @if ($selectedMeeting->passcode)
                        <p><strong class="text-foreground">{{ __('Passcode:') }}</strong> <span class="font-mono font-bold">{{ $selectedMeeting->passcode }}</span></p>
                    @endif
                </div>

                <!-- Copy Link & Full Invitation Bar -->
                <div class="space-y-2 text-left">
                    <label class="block text-xs font-semibold text-foreground">{{ __('Meeting Join Link') }}</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            readonly
                            value="{{ $joinUrl }}"
                            class="flex-1 rounded-lg border border-border bg-secondary/50 px-3 py-2 text-xs font-mono select-all outline-none"
                        />
                        <x-ui.button
                            type="button"
                            @click="copyText(@js($joinUrl), true)"
                            variant="secondary"
                            size="sm"
                            icon="link"
                        >
                            <span x-text="copiedLink ? '{{ __('Copied!') }}' : '{{ __('Copy Link') }}'"></span>
                        </x-ui.button>
                        <x-ui.button
                            type="button"
                            @click="copyText(@js($shareText), false)"
                            variant="default"
                            size="sm"
                            icon="copy"
                        >
                            <span x-text="copiedInvite ? '{{ __('Copied!') }}' : '{{ __('Copy Invite') }}'"></span>
                        </x-ui.button>
                    </div>
                </div>

                <!-- Send Additional Bulk Emails Anytime -->
                <div class="space-y-2 text-left pt-2 border-t border-border">
                    <label class="block text-xs font-semibold text-foreground">{{ __('Send Invite via Email (Enter Email Addresses)') }}</label>
                    <div class="flex gap-2">
                        <x-ui.input wire:model="shareModalBulkEmails" placeholder="colleague@domain.com, student@domain.com" class="text-xs flex-1" />
                        <x-ui.button wire:click="sendAdditionalEmailInvites" variant="default" size="sm" icon="send">
                            {{ __('Send') }}
                        </x-ui.button>
                    </div>
                </div>

                <!-- Share Apps -->
                <div class="pt-2 border-t border-border flex items-center justify-center gap-3">
                    <a
                        href="https://wa.me/?text={{ urlencode($shareText) }}"
                        target="_blank"
                        class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 transition-colors flex items-center gap-1.5 text-xs font-semibold"
                    >
                        <x-icon name="smartphone" class="h-4 w-4" />
                        <span>{{ __('WhatsApp') }}</span>
                    </a>

                    <a
                        href="https://t.me/share/url?url={{ urlencode($joinUrl) }}&text={{ urlencode($shareText) }}"
                        target="_blank"
                        class="p-2.5 rounded-xl bg-sky-500/10 text-sky-600 hover:bg-sky-500/20 transition-colors flex items-center gap-1.5 text-xs font-semibold"
                    >
                        <x-icon name="send" class="h-4 w-4" />
                        <span>{{ __('Telegram') }}</span>
                    </a>

                    <a
                        href="mailto:?subject={{ urlencode(__('Meeting: :title', ['title' => $selectedMeeting->title])) }}&body={{ urlencode($shareText) }}"
                        class="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 hover:bg-blue-500/20 transition-colors flex items-center gap-1.5 text-xs font-semibold"
                    >
                        <x-icon name="mail" class="h-4 w-4" />
                        <span>{{ __('Email') }}</span>
                    </a>
                </div>
            </div>
        </x-ui.modal>
    @endif
</div>
