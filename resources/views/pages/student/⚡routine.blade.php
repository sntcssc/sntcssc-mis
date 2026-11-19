<?php

use App\Models\AuditLog;
use App\Models\ClassSchedule;
use App\Models\CoursePlan;
use App\Models\Enrollment;
use App\Services\StudentImportExportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('My Classes & Routine Schedule')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'upcoming'; // upcoming, today, weekly, recordings, history, plans

    #[Url(as: 'enrollment')]
    public string $enrollmentFilter = ''; // specific enrollment id or all

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'mode')]
    public string $modeFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 15;

    // View Details Modal State
    public ?int $viewingId = null;

    public ?ClassSchedule $viewingSchedule = null;

    // Recording Watch Modal State
    public ?int $watchingRecordingId = null;

    public ?ClassSchedule $watchingSchedule = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEnrollmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedModeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function studentEnrollments()
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        return $user->activeEnrollments()
            ->with(['batch', 'course', 'section'])
            ->get();
    }

    #[Computed]
    public function todayClasses()
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        return ClassSchedule::query()
            ->with(['batch', 'course', 'section', 'faculties'])
            ->forStudent($user)
            ->today()
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('start_time', 'asc')
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $user = auth()->user();
        if (! $user) {
            return ['today' => 0, 'upcoming' => 0, 'recordings' => 0, 'history' => 0];
        }

        $base = ClassSchedule::query()->forStudent($user);

        return [
            'today' => (clone $base)->today()->whereNotIn('status', ['cancelled'])->count(),
            'upcoming' => (clone $base)->upcoming()->count(),
            'recordings' => (clone $base)->where(function ($q) {
                $q->where('recording_status', 'available')
                    ->orWhereNotNull('recording_url');
            })->count(),
            'history' => (clone $base)->past()->count(),
        ];
    }

    #[Computed]
    public function schedules()
    {
        $user = auth()->user();
        if (! $user) {
            return ClassSchedule::whereRaw('1 = 0')->paginate($this->perPage);
        }

        $query = ClassSchedule::query()
            ->with(['batch', 'course', 'section', 'faculties']);

        // Check if filtered by a specific enrollment
        if (! empty($this->enrollmentFilter)) {
            $enrollment = Enrollment::find((int) $this->enrollmentFilter);
            if ($enrollment && $enrollment->user_id === $user->id) {
                $query->where('batch_id', $enrollment->batch_id)
                    ->where('course_id', $enrollment->course_id)
                    ->where(function (Builder $sec) use ($enrollment) {
                        $sec->whereNull('section_id');
                        if (! empty($enrollment->section_id)) {
                            $sec->orWhere('section_id', $enrollment->section_id);
                        }
                    });
            } else {
                $query->forStudent($user);
            }
        } else {
            $query->forStudent($user);
        }

        // Apply Tab Filter
        if ($this->activeTab === 'today') {
            $query->today();
        } elseif ($this->activeTab === 'upcoming') {
            $query->upcoming();
        } elseif ($this->activeTab === 'history') {
            $query->past();
        } elseif ($this->activeTab === 'recordings') {
            $query->where(function ($q) {
                $q->where('recording_status', 'available')
                    ->orWhereNotNull('recording_url');
            });
        }

        if ($this->typeFilter) {
            $query->where('class_type', $this->typeFilter);
        }

        if ($this->modeFilter) {
            $query->where('delivery_mode', $this->modeFilter);
        }

        if ($this->search) {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)
                    ->orWhere('topics', 'like', $term)
                    ->orWhere('classroom', 'like', $term)
                    ->orWhere('lecture_number', 'like', $term)
                    ->orWhereHas('faculties', fn ($f) => $f->where('name', 'like', $term));
            });
        }

        $sortDir = in_array($this->activeTab, ['history', 'recordings']) ? 'desc' : 'asc';

        return $query->orderBy('class_date', $sortDir)
            ->orderBy('start_time', 'asc')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function publishedCoursePlans()
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $enrollments = $this->studentEnrollments;
        if ($enrollments->isEmpty()) {
            return collect();
        }

        $batchIds = $enrollments->pluck('batch_id')->unique()->all();
        $courseIds = $enrollments->pluck('course_id')->unique()->all();

        return CoursePlan::published()
            ->with(['batch', 'course', 'section'])
            ->withCount('classSchedules')
            ->whereIn('batch_id', $batchIds)
            ->whereIn('course_id', $courseIds)
            ->orderBy('start_date', 'asc')
            ->get();
    }

    public function showDetails(int $id): void
    {
        $user = auth()->user();
        $this->viewingId = $id;
        $this->viewingSchedule = ClassSchedule::with(['batch', 'course', 'section', 'coursePlan', 'faculties'])
            ->forStudent($user)
            ->findOrFail($id);

        $this->dispatch('modal-open', name: 'student-class-view-modal');
    }

    public function watchRecording(int $id): void
    {
        $user = auth()->user();
        $schedule = ClassSchedule::forStudent($user)->findOrFail($id);

        $this->watchingRecordingId = $schedule->id;
        $this->watchingSchedule = $schedule;

        $this->dispatch('modal-open', name: 'student-watch-recording-modal');
    }

    public function exportRoutine(string $format = 'pdf')
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $schedules = ClassSchedule::query()
            ->with(['batch', 'course', 'section', 'faculties'])
            ->forStudent($user)
            ->upcoming()
            ->orderBy('class_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        $headers = ['Date', 'Day', 'Time', 'Course', 'Batch / Section', 'Type', 'Subject & Topics', 'Faculty', 'Mode & Venue', 'Status'];
        $rows = [];

        foreach ($schedules as $s) {
            $venue = $s->delivery_mode === 'online' ? ($s->platform ?: 'Online Meeting') : ($s->classroom ?: 'Classroom');
            $rows[] = [
                $s->class_date->format('d M Y'),
                $s->day_of_week,
                $s->formatted_time,
                $s->course?->name ?? 'N/A',
                ($s->batch?->name ?? '').($s->section ? ' (Sec '.$s->section->name.')' : ''),
                ucfirst($s->class_type),
                ($s->subject ? $s->subject.' - ' : '').($s->topics ?? ''),
                $s->primary_faculty_names,
                $venue,
                ucfirst($s->status),
            ];
        }

        $title = "My Academic Class Schedule & Routine - {$user->name}";

        return match (strtolower($format)) {
            'xlsx' => StudentImportExportService::exportExcel('my-class-routine', $headers, $rows),
            'csv' => StudentImportExportService::exportCsv('my-class-routine', $headers, $rows),
            default => StudentImportExportService::exportPdf($title, $headers, $rows, 'Personalized Timetable for Enrolled Courses'),
        };
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="calendar" class="w-7 h-7 text-primary" />
                {{ __('Classes & Timetable Routine') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('View schedule for all your enrolled courses and batches, join online lectures, and access class recordings.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.dropdown align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5 cursor-pointer">
                        <x-icon name="download" class="h-4 w-4"/>
                        <span>{{ __('Download Routine') }}</span>
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 opacity-60"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-slot:menu>
                    <button type="button" wire:click="exportRoutine('pdf')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-down" class="h-3.5 w-3.5 text-rose-500"/>
                        <span>{{ __('Print / PDF Routine') }}</span>
                    </button>
                    <button type="button" wire:click="exportRoutine('xlsx')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 text-emerald-500"/>
                        <span>{{ __('Export Excel') }}</span>
                    </button>
                </x-slot:menu>
            </x-ui.dropdown>
        </div>
    </div>

    <!-- Multi-Enrollment Badge Filter Bar -->
    @if ($this->studentEnrollments->count() > 1)
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold text-muted-foreground mr-1 flex items-center gap-1">
                <x-icon name="layers" class="h-3.5 w-3.5 text-primary" />
                {{ __('Enrolled Courses & Batches:') }}
            </span>
            <button
                type="button"
                wire:click="$set('enrollmentFilter', '')"
                class="rounded-lg px-2.5 py-1 text-xs font-medium cursor-pointer transition-colors {{ empty($enrollmentFilter) ? 'bg-primary text-primary-foreground font-semibold' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80' }}"
            >
                {{ __('All Enrolled Courses (:count)', ['count' => $this->studentEnrollments->count()]) }}
            </button>
            @foreach ($this->studentEnrollments as $enrollment)
                <button
                    type="button"
                    wire:click="$set('enrollmentFilter', '{{ $enrollment->id }}')"
                    class="rounded-lg px-2.5 py-1 text-xs font-medium cursor-pointer transition-colors {{ $enrollmentFilter == (string) $enrollment->id ? 'bg-primary text-primary-foreground font-semibold' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80' }}"
                >
                    {{ $enrollment->course?->name }} ({{ $enrollment->batch?->name }}{{ $enrollment->section ? ' - Sec '.$enrollment->section->name : '' }})
                </button>
            @endforeach
        </div>
    @endif

    <!-- Today's Live Class Banner (If any classes scheduled today) -->
    @if ($this->todayClasses->isNotEmpty())
        <div class="rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-xs space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="flex h-3 w-3 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                    </span>
                    <h2 class="text-sm font-bold text-foreground uppercase tracking-wider">
                        {{ __('Today\'s Scheduled Lectures (:date)', ['date' => now()->format('l, d F Y')]) }}
                    </h2>
                </div>
                <span class="text-xs font-semibold text-primary">
                    {{ $this->todayClasses->count() }} {{ __('Classes') }}
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($this->todayClasses as $todayClass)
                    <div class="rounded-lg border border-border bg-card p-3 shadow-xs space-y-2">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <span class="rounded bg-primary/10 text-primary px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                    {{ $todayClass->formatted_time }}
                                </span>
                                <h3 class="font-bold text-sm text-foreground mt-1 line-clamp-1">{{ $todayClass->subject ?: $todayClass->course?->name }}</h3>
                                <p class="text-xs text-muted-foreground">{{ $todayClass->course?->name }}</p>
                            </div>

                            @if ($todayClass->isHappeningNow())
                                <span class="rounded-full bg-rose-500 text-white px-2 py-0.5 text-[10px] font-bold uppercase animate-pulse">
                                    {{ __('Live Now') }}
                                </span>
                            @endif
                        </div>

                        <div class="text-xs text-muted-foreground line-clamp-1">
                            {{ $todayClass->topics ?: __('Topics to be announced') }}
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-border/50 text-xs">
                            <span class="text-muted-foreground font-medium">
                                {{ $todayClass->primary_faculty_names }}
                            </span>

                            @if ($todayClass->delivery_mode === 'online' && $todayClass->meeting_link)
                                <a
                                    href="{{ $todayClass->meeting_link }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-1 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                                >
                                    <x-icon name="video" class="h-3.5 w-3.5"/>
                                    <span>{{ __('Join Now') }}</span>
                                </a>
                            @else
                                <span class="rounded bg-secondary px-2 py-0.5 text-[11px] font-medium text-foreground">
                                    <x-icon name="map-pin" class="h-3 w-3 inline mr-0.5"/>
                                    {{ $todayClass->classroom ?: __('Campus Hall') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Navigation Tabs -->
    <div class="border-b border-border flex flex-wrap items-center gap-2">
        <button
            type="button"
            wire:click="$set('activeTab', 'upcoming')"
            class="px-4 py-2.5 text-xs font-bold border-b-2 cursor-pointer transition-colors {{ $activeTab === 'upcoming' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="calendar" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Upcoming Routine') }}
            <span class="ml-1 rounded-full bg-secondary px-1.5 py-0.2 text-[10px]">{{ $this->stats['upcoming'] }}</span>
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'today')"
            class="px-4 py-2.5 text-xs font-bold border-b-2 cursor-pointer transition-colors {{ $activeTab === 'today' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="clock" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Today\'s Schedule') }}
            <span class="ml-1 rounded-full bg-secondary px-1.5 py-0.2 text-[10px]">{{ $this->stats['today'] }}</span>
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'recordings')"
            class="px-4 py-2.5 text-xs font-bold border-b-2 cursor-pointer transition-colors {{ $activeTab === 'recordings' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="play-circle" class="h-3.5 w-3.5 inline mr-1.5 text-emerald-500"/>
            {{ __('Class Recordings Vault') }}
            <span class="ml-1 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-1.5 py-0.2 text-[10px]">{{ $this->stats['recordings'] }}</span>
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'plans')"
            class="px-4 py-2.5 text-xs font-bold border-b-2 cursor-pointer transition-colors {{ $activeTab === 'plans' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="book-open" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Course Plans & Syllabi') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'history')"
            class="px-4 py-2.5 text-xs font-bold border-b-2 cursor-pointer transition-colors {{ $activeTab === 'history' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="history" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Concluded Classes History') }}
        </button>
    </div>

    <!-- Active Tab: Course Plans & Syllabi -->
    @if ($activeTab === 'plans')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @forelse ($this->publishedCoursePlans as $plan)
                <div class="rounded-xl border border-border bg-card p-5 shadow-xs space-y-3 flex flex-col justify-between">
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <span class="rounded-md bg-primary/10 text-primary px-2 py-0.5 text-xs font-bold uppercase">
                                    {{ ucfirst($plan->plan_type) }} {{ __('Course Plan') }}
                                </span>
                                <h3 class="text-base font-bold text-foreground mt-1">{{ $plan->title }}</h3>
                                <p class="text-xs text-muted-foreground">{{ $plan->course?->name }} &bull; {{ $plan->batch?->name }}</p>
                            </div>
                            <span class="font-mono text-xs text-muted-foreground">v{{ $plan->version }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-xs py-2 my-2 border-y border-border/50">
                            <div>
                                <span class="text-muted-foreground block">{{ __('Timeline') }}</span>
                                <span class="font-semibold text-foreground">{{ $plan->start_date->format('d M Y') }} - {{ $plan->end_date->format('d M Y') }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground block">{{ __('Planned Target') }}</span>
                                <span class="font-semibold text-foreground">{{ $plan->total_lectures_planned }} {{ __('Lectures') }}</span>
                            </div>
                        </div>

                        <!-- Progress Bar & Cancellation Handling -->
                        <div class="space-y-1.5 py-1">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-muted-foreground font-medium">{{ __('Syllabus Progress') }}</span>
                                <span class="font-bold text-primary">{{ $plan->progress_percentage }}%</span>
                            </div>
                            <div class="w-full bg-secondary h-2 rounded-full overflow-hidden">
                                <div class="bg-primary h-full rounded-full transition-all duration-300" style="width: {{ min(100, $plan->progress_percentage) }}%"></div>
                            </div>
                            <div class="flex flex-wrap items-center justify-between text-[11px] text-muted-foreground pt-0.5">
                                <span>{{ __('Conducted:') }} <strong class="text-emerald-600 dark:text-emerald-400">{{ $plan->conducted_lectures_count }}</strong></span>
                                <span>{{ __('Upcoming:') }} <strong class="text-blue-600 dark:text-blue-400">{{ $plan->scheduled_lectures_count }}</strong></span>
                                @if ($plan->cancelled_lectures_count > 0)
                                    <span title="{{ __('Cancelled classes are excluded from syllabus progress') }}">
                                        {{ __('Cancelled:') }} <strong class="text-rose-500">{{ $plan->cancelled_lectures_count }}</strong>
                                    </span>
                                @endif
                                <span>{{ __('Remaining:') }} <strong class="text-foreground">{{ $plan->remaining_lectures_to_schedule }}</strong></span>
                            </div>
                        </div>

                        @if ($plan->topics_covered)
                            <div class="text-xs text-muted-foreground pt-1">
                                <span class="font-semibold text-foreground block mb-0.5">{{ __('Key Topics & Units:') }}</span>
                                <p class="line-clamp-2 leading-relaxed">{{ $plan->topics_covered }}</p>
                            </div>
                        @endif
                    </div>

                    <!-- Soft Copy Download & View Section -->
                    <div class="pt-3 border-t border-border flex flex-wrap items-center justify-between gap-2">
                        @if ($plan->document_path)
                            <div class="flex items-center gap-2">
                                <div class="p-2 rounded-lg bg-primary/10 text-primary">
                                    <x-icon name="file-text" class="h-4 w-4" />
                                </div>
                                <div class="text-xs">
                                    <span class="font-semibold text-foreground block truncate max-w-[140px]" title="{{ $plan->document_file_name }}">
                                        {{ $plan->document_file_name ?: __('Course Syllabus') }}
                                    </span>
                                    <span class="text-[10px] text-muted-foreground">
                                        {{ $plan->document_size_formatted ?: __('PDF Document') }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <a
                                    href="{{ $plan->document_url }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-secondary transition"
                                >
                                    <x-icon name="eye" class="h-3.5 w-3.5" />
                                    <span>{{ __('View') }}</span>
                                </a>
                                <a
                                    href="{{ $plan->document_url }}"
                                    target="_blank"
                                    download
                                    class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90 shadow-xs transition"
                                >
                                    <x-icon name="download" class="h-3.5 w-3.5"/>
                                    <span>{{ __('Download Soft Copy') }}</span>
                                </a>
                            </div>
                        @else
                            <span class="text-xs text-muted-foreground italic flex items-center gap-1">
                                <x-icon name="info" class="h-3.5 w-3.5" />
                                <span>{{ __('Digital plan active (soft copy will be attached soon)') }}</span>
                            </span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-2 rounded-xl border border-border bg-card p-12 text-center text-muted-foreground">
                    <x-icon name="book-open" class="h-8 w-8 mx-auto text-muted-foreground/50 mb-2"/>
                    <p class="font-medium">{{ __('No comprehensive course plans published yet for your courses.') }}</p>
                    <p class="text-xs text-muted-foreground">{{ __('Course plans shared by your academic department will appear here.') }}</p>
                </div>
            @endforelse
        </div>
    @else
        <!-- Routine List / Agenda View -->
        <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
            <!-- Filter search bar within table -->
            <div class="p-3 border-b border-border bg-muted/20 flex flex-wrap items-center justify-between gap-2">
                <div class="relative w-full sm:w-72">
                    <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search subject, topic, or faculty...') }}"
                        class="w-full rounded-md border border-input bg-background pl-8 pr-3 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div class="flex items-center gap-2 text-xs">
                    <select wire:model.live="typeFilter" class="rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('All Types (Regular & Optional)') }}</option>
                        <option value="regular">{{ __('Regular Classes') }}</option>
                        <option value="optional">{{ __('Optional Classes') }}</option>
                    </select>

                    <select wire:model.live="modeFilter" class="rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('All Modes') }}</option>
                        <option value="offline">{{ __('Offline (Classroom)') }}</option>
                        <option value="online">{{ __('Online (Video)') }}</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-foreground">
                    <thead class="bg-muted/50 border-b border-border text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3">{{ __('Date & Day') }}</th>
                            <th class="px-4 py-3">{{ __('Time') }}</th>
                            <th class="px-4 py-3">{{ __('Subject & Lecture') }}</th>
                            <th class="px-4 py-3">{{ __('Faculty / Teacher') }}</th>
                            <th class="px-4 py-3">{{ __('Delivery Mode') }}</th>
                            <th class="px-4 py-3">{{ __('Status / Recordings') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->schedules as $schedule)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-foreground">{{ $schedule->class_date->format('d M Y') }}</div>
                                    <div class="text-xs text-muted-foreground">{{ $schedule->day_of_week }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-medium">
                                    <div class="flex items-center gap-1 text-foreground">
                                        <x-icon name="clock" class="h-3.5 w-3.5 text-muted-foreground"/>
                                        <span>{{ $schedule->formatted_time }}</span>
                                    </div>
                                    <div class="text-[11px] text-muted-foreground">{{ $schedule->course?->name }}</div>
                                </td>
                                <td class="px-4 py-3 max-w-sm">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-semibold text-foreground">{{ $schedule->subject ?: __('Subject') }}</span>
                                        @if ($schedule->lecture_number)
                                            <span class="rounded bg-secondary px-1.5 py-0.5 text-[10px] font-mono text-muted-foreground">
                                                {{ $schedule->lecture_number }}
                                            </span>
                                        @endif
                                        <span class="rounded-md text-[10px] font-medium px-1.5 py-0.2 {{ $schedule->class_type === 'regular' ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400' : 'bg-purple-500/10 text-purple-600 dark:text-purple-400' }}">
                                            {{ ucfirst($schedule->class_type) }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-muted-foreground line-clamp-1 mt-0.5">
                                        {{ $schedule->topics ?: __('Topic details to follow') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($schedule->faculties->isNotEmpty())
                                        <div class="flex items-center gap-1.5">
                                            <div class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-[10px]">
                                                {{ $schedule->faculties->first()->initials }}
                                            </div>
                                            <span class="font-medium text-foreground">{{ $schedule->faculties->pluck('name')->join(', ') }}</span>
                                        </div>
                                    @else
                                        <span class="text-muted-foreground italic">{{ __('Unassigned') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($schedule->delivery_mode === 'online')
                                        <span class="inline-flex items-center gap-1 rounded-md bg-violet-500/10 text-violet-600 dark:text-violet-400 px-2 py-0.5 text-xs font-semibold">
                                            <x-icon name="video" class="h-3 w-3"/>
                                            {{ $schedule->platform ?: 'Online' }}
                                        </span>
                                    @else
                                        <div class="text-xs">
                                            <span class="font-medium text-foreground">{{ __('Offline') }}</span>
                                            <span class="text-muted-foreground block text-[11px]">{{ $schedule->classroom ?: __('Campus') }}</span>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($schedule->hasRecording())
                                        <button
                                            type="button"
                                            wire:click="watchRecording({{ $schedule->id }})"
                                            class="inline-flex items-center gap-1 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-2.5 py-1 text-xs font-semibold hover:bg-emerald-500/20 cursor-pointer"
                                        >
                                            <x-icon name="play-circle" class="h-3.5 w-3.5"/>
                                            <span>{{ __('Watch Video') }}</span>
                                        </button>
                                    @elseif ($schedule->status === 'completed')
                                        <span class="rounded-full bg-emerald-500/10 text-emerald-600 px-2 py-0.5 text-xs font-medium">{{ __('Concluded') }}</span>
                                    @elseif ($schedule->isHappeningNow())
                                        <span class="rounded-full bg-rose-500 text-white px-2 py-0.5 text-xs font-bold uppercase animate-pulse">{{ __('Live Now') }}</span>
                                    @elseif ($schedule->status === 'rescheduled')
                                        <span class="rounded-full bg-amber-500/10 text-amber-600 px-2 py-0.5 text-xs font-medium">{{ __('Rescheduled') }}</span>
                                    @elseif ($schedule->status === 'cancelled')
                                        <span class="rounded-full bg-destructive/10 text-destructive px-2 py-0.5 text-xs font-medium">{{ __('Cancelled') }}</span>
                                    @else
                                        <span class="rounded-full bg-blue-500/10 text-blue-600 px-2 py-0.5 text-xs font-medium">{{ __('Scheduled') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        @if ($schedule->delivery_mode === 'online' && $schedule->meeting_link && ($schedule->isHappeningNow() || $schedule->class_date->isToday()))
                                            <a
                                                href="{{ $schedule->meeting_link }}"
                                                target="_blank"
                                                class="rounded-md bg-primary px-2.5 py-1 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                                            >
                                                {{ __('Join Class') }}
                                            </a>
                                        @endif

                                        <button
                                            type="button"
                                            wire:click="showDetails({{ $schedule->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-foreground rounded-md hover:bg-secondary cursor-pointer"
                                            title="{{ __('View Details') }}"
                                        >
                                            <x-icon name="eye" class="h-4 w-4"/>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <x-icon name="calendar" class="h-8 w-8 text-muted-foreground/50"/>
                                        <p class="font-medium">{{ __('No classes scheduled for this view.') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($this->schedules->hasPages())
                <div class="border-t border-border p-4">
                    {{ $this->schedules->links() }}
                </div>
            @endif
        </div>
    @endif

    <!-- Student Class View Details Modal -->
    <x-ui.modal name="student-class-view-modal" maxWidth="max-w-2xl" :title="$viewingSchedule?->subject ?? __('Class Lecture Details')" :description="__('Full routine information, venue, faculty instructions, and join details.')">
        @if ($viewingSchedule)
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 rounded-lg bg-secondary/50">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-foreground">{{ $viewingSchedule->subject ?: __('Subject TBD') }}</h3>
                            <span class="rounded-md bg-primary/10 text-primary px-2 py-0.5 text-xs font-semibold">
                                {{ ucfirst($viewingSchedule->class_type) }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ $viewingSchedule->course?->name }} &bull; {{ $viewingSchedule->batch?->name }}
                        </p>
                    </div>

                    @if ($viewingSchedule->delivery_mode === 'online' && $viewingSchedule->meeting_link)
                        <a href="{{ $viewingSchedule->meeting_link }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90">
                            <x-icon name="video" class="h-4 w-4"/>
                            <span>{{ __('Join Lecture') }}</span>
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Date') }}</span>
                        <span class="font-bold text-foreground">{{ $viewingSchedule->class_date->format('d M Y') }}</span>
                        <span class="text-[10px] text-muted-foreground block">{{ $viewingSchedule->day_of_week }}</span>
                    </div>

                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Timing') }}</span>
                        <span class="font-bold text-foreground">{{ $viewingSchedule->formatted_time }}</span>
                    </div>

                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Venue / Mode') }}</span>
                        <span class="font-bold text-foreground capitalize">{{ $viewingSchedule->delivery_mode }}</span>
                        <span class="text-[10px] text-muted-foreground block">{{ $viewingSchedule->delivery_mode === 'online' ? $viewingSchedule->platform : ($viewingSchedule->classroom ?: 'Campus') }}</span>
                    </div>

                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Faculty') }}</span>
                        <span class="font-bold text-foreground">{{ $viewingSchedule->primary_faculty_names }}</span>
                    </div>
                </div>

                @if ($viewingSchedule->topics)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Lecture Topics') }}</span>
                        <p class="text-foreground leading-relaxed">{{ $viewingSchedule->topics }}</p>
                    </div>
                @endif

                @if ($viewingSchedule->description)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Faculty Notes & Instructions') }}</span>
                        <p class="text-foreground leading-relaxed whitespace-pre-line">{{ $viewingSchedule->description }}</p>
                    </div>
                @endif

                @if ($viewingSchedule->delivery_mode === 'online')
                    <div class="rounded-md border border-border bg-violet-500/5 p-3 text-xs space-y-2">
                        <div class="font-semibold text-violet-600 dark:text-violet-400 flex items-center gap-1.5">
                            <x-icon name="video" class="h-4 w-4"/>
                            <span>{{ __('Meeting Platform Details') }} ({{ $viewingSchedule->platform }})</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <span class="text-muted-foreground block">{{ __('Meeting ID') }}</span>
                                <span class="font-mono font-semibold text-foreground">{{ $viewingSchedule->meeting_id ?: __('Not Required') }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground block">{{ __('Passcode') }}</span>
                                <span class="font-mono font-semibold text-foreground">{{ $viewingSchedule->meeting_password ?: __('Not Required') }}</span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.modal>

    <!-- Watch Class Recording Modal -->
    <x-ui.modal name="student-watch-recording-modal" maxWidth="max-w-2xl" :title="__('Class Lecture Recording')" :description="__('Access recorded session video and timestamp index notes.')">
        @if ($watchingSchedule)
            <div class="space-y-4">
                <div class="p-3 rounded-lg bg-secondary/50 text-xs">
                    <h3 class="font-bold text-foreground text-sm">{{ $watchingSchedule->subject }} &bull; {{ $watchingSchedule->lecture_number }}</h3>
                    <p class="text-muted-foreground">{{ $watchingSchedule->course?->name }} &bull; {{ $watchingSchedule->class_date->format('d M Y') }}</p>
                </div>

                @if ($watchingSchedule->recording_access_url)
                    <div class="rounded-lg border border-border bg-emerald-500/10 p-4 text-center space-y-2">
                        <x-icon name="play-circle" class="h-10 w-10 text-emerald-600 dark:text-emerald-400 mx-auto"/>
                        <p class="text-sm font-semibold text-foreground">{{ __('Lecture recording is ready to watch.') }}</p>
                        <div>
                            <a
                                href="{{ $watchingSchedule->recording_access_url }}"
                                target="_blank"
                                class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700 shadow-xs"
                            >
                                <x-icon name="external-link" class="h-4 w-4"/>
                                <span>{{ __('Open Recording in New Tab') }}</span>
                            </a>
                        </div>
                    </div>
                @endif

                @if ($watchingSchedule->recording_password)
                    <div class="rounded-md border border-border p-3 text-xs flex items-center justify-between">
                        <div>
                            <span class="text-muted-foreground block">{{ __('Recording Passcode') }}</span>
                            <span class="font-mono font-bold text-foreground text-sm">{{ $watchingSchedule->recording_password }}</span>
                        </div>
                        <span class="text-muted-foreground text-[11px]">{{ __('Required by cloud recording provider') }}</span>
                    </div>
                @endif

                @if ($watchingSchedule->recording_notes)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Timestamps & Index Notes') }}</span>
                        <p class="text-foreground whitespace-pre-line leading-relaxed">{{ $watchingSchedule->recording_notes }}</p>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.modal>
</div>
