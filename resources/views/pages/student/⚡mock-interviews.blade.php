<?php

use App\Models\AuditLog;
use App\Models\MockInterviewCandidate;
use App\Models\MockInterviewSession;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Toast;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('My UPSC Mock Interviews')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'upcoming'; // upcoming, past, recordings

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 10;

    // View Session Peer Order Modal
    public ?int $viewingSessionId = null;

    public ?MockInterviewSession $viewingSession = null;

    // Watch Recording Modal
    public ?int $watchingCandidateId = null;

    public ?MockInterviewCandidate $watchingCandidate = null;

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function currentStudent(): ?User
    {
        return auth()->user();
    }

    #[Computed]
    public function myCandidateRecords()
    {
        $user = $this->currentStudent;
        if (! $user) {
            return collect();
        }

        $now = now()->toDateString();

        return MockInterviewCandidate::query()
            ->with(['session.sessionMembers.boardMember', 'batch', 'course'])
            ->where('user_id', $user->id)
            ->whereHas('session')
            ->when($this->search, function (Builder $q) {
                $q->whereHas('session', fn (Builder $sq) => $sq->search($this->search));
            })
            ->when($this->activeTab === 'upcoming', function (Builder $q) use ($now) {
                $q->whereHas('session', fn (Builder $sq) => $sq->whereDate('session_date', '>=', $now))
                    ->where('status', '!=', MockInterviewCandidate::STATUS_COMPLETED);
            })
            ->when($this->activeTab === 'past', function (Builder $q) use ($now) {
                $q->where(function (Builder $sub) use ($now) {
                    $sub->whereHas('session', fn (Builder $sq) => $sq->whereDate('session_date', '<', $now))
                        ->orWhere('status', MockInterviewCandidate::STATUS_COMPLETED);
                });
            })
            ->when($this->activeTab === 'recordings', function (Builder $q) {
                $q->where('recording_status', MockInterviewCandidate::RECORDING_AVAILABLE);
            })
            ->latest('id')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function stats(): array
    {
        $user = $this->currentStudent;
        if (! $user) {
            return ['total' => 0, 'upcoming' => 0, 'completed' => 0, 'recordings' => 0];
        }

        $now = now()->toDateString();
        $base = MockInterviewCandidate::where('user_id', $user->id);

        return [
            'total' => (clone $base)->count(),
            'upcoming' => (clone $base)->whereHas('session', fn ($q) => $q->whereDate('session_date', '>=', $now))->where('status', '!=', 'completed')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'recordings' => (clone $base)->where('recording_status', 'available')->count(),
        ];
    }

    /**
     * Open session peer appearance order modal (Strict Privacy: hides peers' recordings).
     */
    public function openSessionRosterModal(int $sessionId): void
    {
        $this->viewingSessionId = $sessionId;
        $this->viewingSession = MockInterviewSession::with([
            'sessionMembers.boardMember',
            'candidates' => fn ($q) => $q->with('user')->orderBy('sequence_number', 'asc'),
        ])->findOrFail($sessionId);

        $this->dispatch('modal-open', name: 'session-roster-modal');
    }

    /**
     * Open recording modal for authenticated student's own interview.
     */
    public function openWatchRecordingModal(int $candidateId): void
    {
        $candidate = MockInterviewCandidate::with(['session'])->findOrFail($candidateId);

        // Security check: strictly only allow current student to view their own recording
        if ($candidate->user_id !== auth()->id() && ! auth()->user()->hasAnyRole(['Super Administrator', 'Administrator'])) {
            Toast::dispatch($this, 'error', __('Unauthorized: You cannot access another candidate\'s recording.'));

            return;
        }

        $this->watchingCandidateId = $candidateId;
        $this->watchingCandidate = $candidate;

        AuditLogService::log(
            event: 'student_watched_mock_interview_recording',
            description: "Student {$candidate->user->name} accessed interview recording for session {$candidate->session->session_code}",
            auditable: $candidate
        );

        $this->dispatch('modal-open', name: 'watch-recording-modal');
    }

    /**
     * Download recording directly for authenticated student's interview.
     */
    public function downloadRecording(int $candidateId)
    {
        $candidate = MockInterviewCandidate::with(['session', 'user'])->findOrFail($candidateId);

        // Security check: strictly only allow current student to download their own recording
        if ($candidate->user_id !== auth()->id() && ! auth()->user()->hasAnyRole(['Super Administrator', 'Administrator'])) {
            Toast::dispatch($this, 'error', __('Unauthorized: You cannot download another candidate\'s recording.'));

            return;
        }

        if (! $candidate->hasRecording()) {
            Toast::dispatch($this, 'error', __('No interview recording is available yet.'));

            return;
        }

        AuditLogService::log(
            event: 'student_downloaded_mock_interview_recording',
            description: "Student {$candidate->user->name} downloaded interview recording for session {$candidate->session->session_code}",
            auditable: $candidate
        );

        if ($candidate->recording_file_path && Storage::disk('public')->exists($candidate->recording_file_path)) {
            $ext = pathinfo($candidate->recording_file_path, PATHINFO_EXTENSION) ?: 'mp4';
            $safeName = 'Mock_Interview_'.Str::slug($candidate->session->session_code ?? 'UPSC').'_'.($candidate->user->student_id ?? 'candidate').'.'.$ext;

            return Storage::disk('public')->download($candidate->recording_file_path, $safeName);
        }

        if ($candidate->recording_url) {
            return redirect()->away($candidate->recording_url);
        }

        Toast::dispatch($this, 'warning', __('Recording download link could not be resolved.'));
    }
};
?>

<div class="space-y-6">
    <!-- Top Header Banner -->
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="award" class="h-6 w-6"/>
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('My UPSC Mock Interviews') }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ __('Personality Test simulations, order of appearance timetable, offline venue / online links, and video recording vault.') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="relative w-full md:w-64">
            <x-icon name="search" class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"/>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search session or date...') }}"
                class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-xs placeholder:text-muted-foreground focus:border-primary focus:outline-none"
            />
        </div>
    </div>

    <!-- Student Stats Bar -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Total Allocated') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold">{{ $this->stats['total'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('Sessions') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Upcoming Slots') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $this->stats['upcoming'] }}</span>
                <span class="text-xs text-blue-500 font-semibold">{{ __('Scheduled') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Interviews Completed') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-emerald-600">{{ $this->stats['completed'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('Appeared') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Recordings Available') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-sky-600">{{ $this->stats['recordings'] }}</span>
                <span class="text-xs text-sky-500 font-semibold">{{ __('Watch & Download') }}</span>
            </div>
        </div>
    </div>

    <!-- Navigation Tab Buttons -->
    <div class="flex items-center gap-2 border-b border-border pb-2">
        <button
            type="button"
            wire:click="$set('activeTab', 'upcoming')"
            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors cursor-pointer {{ $activeTab === 'upcoming' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            {{ __('Upcoming Mock Interviews') }} ({{ $this->stats['upcoming'] }})
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'past')"
            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors cursor-pointer {{ $activeTab === 'past' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            {{ __('Past Sessions & Feedback') }} ({{ $this->stats['completed'] }})
        </button>
        <button
            type="button"
            wire:click="$set('activeTab', 'recordings')"
            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors cursor-pointer {{ $activeTab === 'recordings' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            {{ __('My Video Recordings Vault') }} ({{ $this->stats['recordings'] }})
        </button>
    </div>

    <!-- Candidate Records Cards -->
    <div class="space-y-4">
        @forelse ($this->myCandidateRecords as $record)
            @php
                $session = $record->session;
                $chair = $session->chairperson();
            @endphp
            <div class="rounded-xl border border-border bg-card p-5 shadow-xs space-y-4 transition-all hover:border-primary/40">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs font-bold text-primary">{{ $session->session_code }}</span>
                            <span class="rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-muted-foreground">Session #{{ $session->session_number }}</span>
                            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ $session->academic_year }}</span>

                            @if ($record->isOnline())
                                <span class="inline-flex items-center gap-1 rounded-full bg-purple-500/10 px-2 py-0.5 text-xs font-semibold text-purple-600 dark:text-purple-400">
                                    <x-icon name="video" class="h-3 w-3"/>
                                    {{ __('Online Remote Interview') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-2 py-0.5 text-xs font-semibold text-blue-600 dark:text-blue-400">
                                    <x-icon name="map-pin" class="h-3 w-3"/>
                                    {{ __('In-Person Offline') }}
                                </span>
                            @endif

                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold text-{{ $record->status_badge_color }}-700 bg-{{ $record->status_badge_color }}-500/10">
                                {{ ucfirst($record->status) }}
                            </span>
                        </div>

                        <h3 class="mt-1 text-lg font-bold text-foreground">{{ $session->title }}</h3>

                        @if ($chair)
                            <div class="mt-1 flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400 font-medium">
                                <span>♔</span>
                                <span>{{ __('Board Chairperson:') }} <strong>{{ $chair->name }}</strong> ({{ $chair->designation ?: 'Eminent Panelist' }})</span>
                            </div>
                        @endif
                    </div>

                    <!-- Highlighted Time Slot Badge -->
                    <div class="flex flex-col items-end gap-1">
                        <div class="rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-right">
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-primary">{{ __('Your Interview Slot') }}</span>
                            <div class="text-base font-bold text-foreground">{{ $record->formatted_slot_time }}</div>
                            <span class="text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                {{ __('Order: Sequence #') }}{{ $record->sequence_number }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Timing & Location Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 rounded-lg border border-border/60 bg-muted/20 p-3 text-xs">
                    <!-- Date & Reporting -->
                    <div class="space-y-1">
                        <div class="flex items-center gap-1 text-muted-foreground font-medium">
                            <x-icon name="calendar" class="h-3.5 w-3.5"/>
                            <span>{{ __('Interview Date & Timetable') }}</span>
                        </div>
                        <p class="font-bold text-foreground">{{ $session->formatted_date }}</p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ __('Your Slot:') }} {{ Carbon::parse($record->scheduled_start_time)->format('h:i A') }} - {{ Carbon::parse($record->scheduled_end_time)->format('h:i A') }} ({{ $record->duration_minutes }}m)
                        </p>
                        <p class="text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                            {{ __('Reporting / Readiness Time:') }} {{ Carbon::parse($record->scheduled_start_time)->subMinutes(20)->format('h:i A') }}
                        </p>
                    </div>

                    <!-- Location / Platform Credentials -->
                    <div class="space-y-1">
                        @if ($record->isOnline())
                            <div class="flex items-center gap-1 text-purple-600 dark:text-purple-400 font-medium">
                                <x-icon name="video" class="h-3.5 w-3.5"/>
                                <span>{{ __('Online Meeting Credentials') }}</span>
                            </div>
                            <p class="font-bold text-foreground">{{ $record->platform ?: 'Online Video' }}</p>
                            @if ($record->meeting_link)
                                <a href="{{ $record->meeting_link }}" target="_blank" class="text-primary hover:underline font-semibold flex items-center gap-1">
                                    <span>{{ __('Open / Join Meeting') }}</span>
                                    <x-icon name="external-link" class="h-3 w-3"/>
                                </a>
                            @endif
                            @if ($record->meeting_id)
                                <p class="text-[11px] font-mono text-muted-foreground">{{ __('Meeting ID:') }} {{ $record->meeting_id }}</p>
                            @endif
                            @if ($record->meeting_password)
                                <p class="text-[11px] font-mono text-muted-foreground">{{ __('Passcode:') }} {{ $record->meeting_password }}</p>
                            @endif
                        @else
                            <div class="flex items-center gap-1 text-blue-600 dark:text-blue-400 font-medium">
                                <x-icon name="map-pin" class="h-3.5 w-3.5"/>
                                <span>{{ __('Offline In-Person Venue') }}</span>
                            </div>
                            <p class="font-semibold text-foreground">{{ $record->getEffectiveVenueAddress() }}</p>
                            @if ($record->room_number ?: $session->room_number)
                                <p class="text-[11px] text-muted-foreground">{{ __('Chamber / Room:') }} {{ $record->room_number ?: $session->room_number }}</p>
                            @endif
                        @endif
                    </div>

                    <!-- Instructions / Candidate notes -->
                    <div class="space-y-1">
                        <div class="flex items-center gap-1 text-muted-foreground font-medium">
                            <x-icon name="info" class="h-3.5 w-3.5"/>
                            <span>{{ __('Important Instructions') }}</span>
                        </div>
                        <p class="text-[11px] text-foreground/90 leading-relaxed">
                            {{ $record->candidate_instructions ?: ($session->instructions ?: __('Dress in formal UPSC Personality test attire and carry 2 copies of your Detailed Application Form (DAF).')) }}
                        </p>
                    </div>
                </div>

                <!-- Marks & Feedback (If evaluated) -->
                @if ($record->interview_score !== null || $record->board_feedback)
                    <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3 space-y-1.5 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-emerald-700 dark:text-emerald-300 flex items-center gap-1">
                                <x-icon name="clipboard-check" class="h-4 w-4"/>
                                {{ __('Board Evaluation & Marks (UPSC 275 Rubric)') }}
                            </span>
                            @if ($record->interview_score !== null)
                                <span class="font-bold text-emerald-700 dark:text-emerald-300 text-sm">
                                    {{ number_format($record->interview_score, 1) }} / 275
                                </span>
                            @endif
                        </div>
                        @if ($record->board_feedback)
                            <p class="text-[11px] text-foreground/90 italic">
                                "{!! nl2br(e($record->board_feedback)) !!}"
                            </p>
                        @endif
                    </div>
                @endif

                <!-- Bottom Action Row -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
                    <!-- Session Order of Appearance Roster Button -->
                    <button
                        type="button"
                        wire:click="openSessionRosterModal({{ $session->id }})"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline cursor-pointer"
                    >
                        <x-icon name="users" class="h-4 w-4"/>
                        <span>{{ __('View Session Order of Appearance & Schedule') }}</span>
                    </button>

                    <!-- Recording Watch / Download Button -->
                    <div class="flex items-center gap-2">
                        @if ($record->hasRecording())
                            <x-ui.button
                                size="sm"
                                wire:click="openWatchRecordingModal({{ $record->id }})"
                                class="cursor-pointer gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white h-8 text-xs font-semibold shadow-xs"
                            >
                                <x-icon name="play" class="h-3.5 w-3.5"/>
                                <span>{{ __('Watch & Download Recording') }}</span>
                            </x-ui.button>
                        @else
                            <span class="text-xs text-muted-foreground italic flex items-center gap-1">
                                <x-icon name="video" class="h-3.5 w-3.5"/>
                                {{ __('Recording will be available after the interview is concluded.') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-border p-12 text-center text-muted-foreground">
                <div class="flex flex-col items-center justify-center gap-2">
                    <x-icon name="award" class="h-10 w-10 text-muted-foreground/40"/>
                    <h3 class="text-base font-semibold text-foreground">{{ __('No Mock Interviews Scheduled') }}</h3>
                    <p class="text-xs max-w-md">
                        {{ __('You currently do not have any mock interviews scheduled under this tab. Once assigned by the administrative board, your slot timings and venue details will appear here.') }}
                    </p>
                </div>
            </div>
        @endforelse

        @if ($this->myCandidateRecords->hasPages())
            <div class="border-t border-border p-3">
                {{ $this->myCandidateRecords->links() }}
            </div>
        @endif
    </div>

    <!-- ======================================================== -->
    <!-- INTERACTIVE MODAL: SESSION ORDER OF APPEARANCE ROSTER -->
    <!-- (Strict Privacy Guarantee: Other students' recordings are never exposed!) -->
    <!-- ======================================================== -->
    <x-ui.modal name="session-roster-modal" maxWidth="max-w-2xl" title="{{ __('Mock Interview Schedule & Order of Appearance') }}" description="{{ __('Check the chronological order of candidates for this session so you are fully prepared when your turn arrives.') }}">
        @if ($viewingSession)
            <div class="space-y-4">
                <!-- Session Context Mini Banner -->
                <div class="rounded-lg border border-border bg-muted/30 p-3 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-mono font-bold text-primary">{{ $viewingSession->session_code }}</span>
                            <span class="font-semibold text-foreground ml-2">{{ $viewingSession->title }}</span>
                        </div>
                        <span class="font-medium text-muted-foreground">{{ $viewingSession->formatted_date }} • {{ $viewingSession->formatted_time_range }}</span>
                    </div>
                    <div class="mt-1 text-[11px] text-muted-foreground">
                        <strong>{{ __('Venue:') }}</strong> {{ $viewingSession->venue_address }} {{ $viewingSession->room_number ? "({$viewingSession->room_number})" : '' }}
                    </div>
                </div>

                <!-- Appearance Order Table -->
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-border bg-muted/40 text-muted-foreground">
                            <tr>
                                <th class="p-2.5 font-semibold text-center w-12">{{ __('Seq') }}</th>
                                <th class="p-2.5 font-semibold">{{ __('Candidate Name') }}</th>
                                <th class="p-2.5 font-semibold">{{ __('Attending Mode') }}</th>
                                <th class="p-2.5 font-semibold">{{ __('Scheduled Slot') }}</th>
                                <th class="p-2.5 font-semibold">{{ __('Status') }}</th>
                                <th class="p-2.5 font-semibold text-right">{{ __('Recording') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($viewingSession->candidates->sortBy('sequence_number') as $c)
                                @php
                                    $isMe = ($c->user_id === auth()->id());
                                @endphp
                                <tr class="{{ $isMe ? 'bg-primary/5 font-semibold border-l-2 border-primary' : 'hover:bg-muted/20' }}">
                                    <td class="p-2.5 text-center font-mono font-bold">
                                        #{{ $c->sequence_number }}
                                    </td>
                                    <td class="p-2.5">
                                        <div class="flex items-center gap-1.5">
                                            <span>{{ $c->user->name }}</span>
                                            @if ($isMe)
                                                <span class="rounded bg-primary text-primary-foreground text-[9px] font-bold px-1.5 py-0.2">
                                                    {{ __('YOU') }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="p-2.5">
                                        @if ($c->isOnline())
                                            <span class="inline-flex items-center gap-1 text-[10px] text-purple-600 dark:text-purple-400 font-medium">
                                                <x-icon name="video" class="h-3 w-3"/>
                                                {{ __('Online') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 text-[10px] text-blue-600 dark:text-blue-400 font-medium">
                                                <x-icon name="map-pin" class="h-3 w-3"/>
                                                {{ __('Offline') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="p-2.5">
                                        <span class="font-mono text-[11px]">{{ $c->formatted_slot_time }}</span>
                                    </td>
                                    <td class="p-2.5">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-semibold text-{{ $c->status_badge_color }}-700 bg-{{ $c->status_badge_color }}-500/10">
                                            {{ ucfirst($c->status) }}
                                        </span>
                                    </td>
                                    <td class="p-2.5 text-right">
                                        <!-- CRITICAL PRIVACY ENFORCEMENT: ONLY logged in student can see their own recording -->
                                        @if ($isMe && $c->hasRecording())
                                            <button
                                                type="button"
                                                wire:click="openWatchRecordingModal({{ $c->id }})"
                                                class="inline-flex items-center gap-1 rounded bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-600 hover:bg-emerald-500/20 cursor-pointer"
                                            >
                                                <x-icon name="play" class="h-3 w-3"/>
                                                <span>{{ __('Watch') }}</span>
                                            </button>
                                        @elseif (! $isMe)
                                            <span class="text-[10px] text-muted-foreground/60 italic" title="Recordings of other candidates are strictly private">
                                                {{ __('Private') }}
                                            </span>
                                        @else
                                            <span class="text-[10px] text-muted-foreground/60 italic">
                                                {{ __('Pending') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end border-t border-border pt-4">
                    <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('session-roster-modal')">
                        {{ __('Close Schedule') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- ======================================================== -->
    <!-- INTERACTIVE MODAL: WATCH / DOWNLOAD RECORDING -->
    <!-- ======================================================== -->
    <x-ui.modal name="watch-recording-modal" maxWidth="max-w-2xl" title="{{ __('My Interview Video Recording') }}" description="{{ __('Watch or download your UPSC mock interview recording for review, body language feedback, and self-assessment.') }}">
        @if ($watchingCandidate && $watchingCandidate->hasRecording())
            <div class="space-y-4">
                <div class="rounded-lg border border-border bg-muted/20 p-3 text-xs space-y-1">
                    <div class="font-bold text-foreground text-sm">{{ $watchingCandidate->session->title }}</div>
                    <div class="text-muted-foreground">
                        {{ $watchingCandidate->session->formatted_date }} • {{ $watchingCandidate->formatted_slot_time }}
                    </div>
                    @if ($watchingCandidate->recording_password)
                        <div class="text-xs font-mono font-semibold text-primary">
                            {{ __('Access Passcode / Key:') }} {{ $watchingCandidate->recording_password }}
                        </div>
                    @endif
                </div>

                <!-- Video Access Player or Direct Download Banner -->
                @php $recUrl = $watchingCandidate->getEffectiveRecordingUrl(); @endphp
                @if (str_ends_with(strtolower($recUrl), '.mp4') || str_ends_with(strtolower($recUrl), '.webm'))
                    <div class="space-y-2">
                        <div class="rounded-lg overflow-hidden border border-border bg-black aspect-video flex items-center justify-center">
                            <video controls class="w-full h-full max-h-80" preload="metadata">
                                <source src="{{ $recUrl }}" type="video/mp4">
                                {{ __('Your browser does not support the video tag.') }}
                            </video>
                        </div>
                    </div>
                @else
                    <div class="rounded-lg border border-primary/30 bg-primary/5 p-6 text-center space-y-3">
                        <x-icon name="play" class="h-10 w-10 text-primary mx-auto"/>
                        <div class="space-y-1">
                            <h4 class="font-bold text-sm text-foreground">{{ __('Cloud Video Recording Ready') }}</h4>
                            <p class="text-xs text-muted-foreground max-w-md mx-auto">
                                {{ __('Your interview video is hosted securely. Click below to stream or download to your device.') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center justify-center gap-2">
                            <button
                                type="button"
                                wire:click="downloadRecording({{ $watchingCandidate->id }})"
                                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-xs font-bold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer"
                            >
                                <x-icon name="download" class="h-3.5 w-3.5"/>
                                <span>{{ __('Download Video Recording') }}</span>
                            </button>
                            <a
                                href="{{ $recUrl }}"
                                target="_blank"
                                class="inline-flex items-center gap-2 rounded-md border border-input bg-background px-4 py-2 text-xs font-bold text-foreground shadow-xs hover:bg-secondary transition-colors"
                            >
                                <span>{{ __('Open in New Tab') }}</span>
                                <x-icon name="external-link" class="h-3.5 w-3.5"/>
                            </a>
                        </div>
                    </div>
                @endif

                @if ($watchingCandidate->recording_notes)
                    <div class="rounded-lg border border-border bg-background p-3 text-xs space-y-1">
                        <span class="font-semibold text-foreground">{{ __('Panel Notes & Timestamps:') }}</span>
                        <p class="text-muted-foreground whitespace-pre-wrap">{!! nl2br(e($watchingCandidate->recording_notes)) !!}</p>
                    </div>
                @endif

                <div class="flex items-center justify-between border-t border-border pt-4">
                    <button
                        type="button"
                        wire:click="downloadRecording({{ $watchingCandidate->id }})"
                        class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer"
                    >
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        <span>{{ __('Download Recording') }}</span>
                    </button>
                    <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('watch-recording-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
