<?php

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\AcademicCalendarVersion;
use App\Services\AcademicCalendarService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Academic Calendar')] class extends Component {
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'month')]
    public string $monthFilter = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    public bool $showVersionHistory = false;

    // Activity detail modal state
    public ?int $selectedActivityId = null;

    #[Computed]
    public function currentCalendar(): ?AcademicCalendar
    {
        return AcademicCalendar::where('is_current', true)
            ->with(['currentVersion', 'versions' => fn ($q) => $q->orderBy('revision_number', 'desc')])
            ->first()
            ?? AcademicCalendar::orderBy('start_date', 'desc')->with(['currentVersion', 'versions'])->first();
    }

    #[Computed]
    public function monthCounts(): array
    {
        $cal = $this->currentCalendar;
        if (! $cal) {
            return [];
        }

        return AcademicCalendarActivity::where('academic_calendar_id', $cal->id)
            ->selectRaw('month, count(*) as count')
            ->groupBy('month')
            ->pluck('count', 'month')
            ->all();
    }

    #[Computed]
    public function activities()
    {
        $cal = $this->currentCalendar;
        if (! $cal) {
            return collect();
        }

        $query = AcademicCalendarActivity::where('academic_calendar_id', $cal->id)
            ->whereIn('target_audience', ['all', 'students', 'aspirants']);

        if (! empty($this->monthFilter)) {
            $query->where('month', (int) $this->monthFilter);
        }

        if (! empty($this->categoryFilter)) {
            $query->where('category', $this->categoryFilter);
        }

        if (! empty($this->search)) {
            $query->search($this->search);
        }

        return $query->orderBy('start_date', 'asc')
            ->orderBy('display_order', 'asc')
            ->get();
    }

    #[Computed]
    public function selectedActivity(): ?AcademicCalendarActivity
    {
        if (! $this->selectedActivityId) {
            return null;
        }

        return AcademicCalendarActivity::with('version')->find($this->selectedActivityId);
    }

    public function viewActivity(int $id): void
    {
        $this->selectedActivityId = $id;
        $this->dispatch('modal-open', name: 'student-activity-modal');
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'monthFilter', 'categoryFilter']);
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ route('student.dashboard') }}" class="hover:text-foreground transition-colors">{{ __('Student Portal') }}</a>
                <span>/</span>
                <span class="text-foreground font-medium">{{ __('Academic Calendar') }}</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="calendar-days" class="w-5 h-5" />
                </span>
                {{ __('Institute Academic Calendar') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Official monthly schedule of coursework, UPSC tests, state holiday recesses, and institutional events.') }}
            </p>
        </div>

        @if($this->currentCalendar && $this->currentCalendar->currentVersion)
            <div class="flex flex-wrap items-center gap-2">
                <a
                    href="{{ route('academic-calendar.export.pdf', $this->currentCalendar->id) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-2 text-xs font-semibold text-foreground hover:bg-secondary transition-colors"
                >
                    <x-icon name="file-down" class="h-4 w-4 text-rose-500" />
                    <span>{{ __('Print / PDF Schedule') }}</span>
                </a>

                <a
                    href="{{ route('academic-calendar.download.version', $this->currentCalendar->currentVersion->id) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-bold text-primary-foreground hover:bg-primary/90 shadow-xs transition-colors"
                >
                    <x-icon name="download" class="h-4 w-4" />
                    <span>{{ __('Download Official Calendar (:ver)', ['ver' => $this->currentCalendar->currentVersion->version_code]) }}</span>
                </a>
            </div>
        @endif
    </div>

    @if(! $this->currentCalendar)
        <div class="rounded-xl border border-dashed border-border bg-card p-12 text-center text-muted-foreground">
            <x-icon name="calendar-x" class="mx-auto h-12 w-12 text-muted-foreground/60" />
            <h3 class="mt-4 text-base font-semibold text-foreground">{{ __('No Academic Calendar Published') }}</h3>
            <p class="mt-1 text-xs">{{ __('The institutional academic calendar for this session will be released soon.') }}</p>
        </div>
    @else
        <!-- Current Calendar Highlights Banner -->
        <div class="rounded-2xl border border-primary/20 bg-gradient-to-r from-primary/5 via-primary/10 to-transparent p-5 sm:p-6 shadow-sm">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="space-y-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-primary text-primary-foreground font-mono font-bold text-xs px-2.5 py-0.5">
                            {{ $this->currentCalendar->academic_year }}
                        </span>
                        <span class="rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold text-[10px] uppercase tracking-wider px-2.5 py-0.5 border border-emerald-500/20">
                            {{ __('Current Session') }} &bull; {{ $this->currentCalendar->currentVersion?->version_code ?? 'v1.0' }}
                        </span>
                        @if($this->currentCalendar->currentVersion?->approved_by)
                            <span class="text-xs text-muted-foreground">
                                {{ __('Approved by') }} <strong>{{ $this->currentCalendar->currentVersion->approved_by }}</strong>
                            </span>
                        @endif
                    </div>

                    <h2 class="text-lg sm:text-xl font-bold text-foreground">
                        {{ $this->currentCalendar->title }}
                    </h2>

                    @if($this->currentCalendar->theme_or_motto)
                        <p class="text-xs text-foreground/80 font-medium italic">
                            "{{ $this->currentCalendar->theme_or_motto }}"
                        </p>
                    @endif

                    <p class="text-xs text-muted-foreground flex flex-wrap items-center gap-3 pt-1">
                        <span><strong>{{ __('Duration:') }}</strong> {{ $this->currentCalendar->formatted_period }}</span>
                        <span>&bull;</span>
                        <span><strong>{{ __('Working Days:') }}</strong> {{ $this->currentCalendar->total_working_days }} days</span>
                        <span>&bull;</span>
                        <span><strong>{{ __('Instructional Days:') }}</strong> {{ $this->currentCalendar->total_instructional_days }} days</span>
                    </p>
                </div>

                <!-- Version Control Button -->
                <div class="shrink-0">
                    <button
                        type="button"
                        wire:click="$toggle('showVersionHistory')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3.5 py-2 text-xs font-semibold text-foreground hover:bg-secondary transition-colors cursor-pointer"
                    >
                        <x-icon name="history" class="h-4 w-4 text-indigo-500" />
                        <span>{{ $showVersionHistory ? __('Hide Version History') : __('View Version History (:count)', ['count' => $this->currentCalendar->versions->count()]) }}</span>
                    </button>
                </div>
            </div>

            <!-- Version History Accordion / Drawer -->
            @if($showVersionHistory)
                <div class="mt-4 pt-4 border-t border-border/80 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                            {{ __('Preserved Document Revision Archive') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground">
                            {{ __('Download historical certified revisions') }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                        @foreach($this->currentCalendar->versions as $v)
                            <div class="rounded-xl border border-border bg-card p-3 flex items-center justify-between gap-2 shadow-2xs">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-foreground text-xs font-mono">{{ $v->version_code }}</span>
                                        @if($v->is_current)
                                            <span class="rounded bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold px-1.5 py-0.2 text-[9px]">
                                                {{ __('Latest') }}
                                            </span>
                                        @endif
                                        <span class="text-xs font-semibold text-foreground truncate max-w-[200px]">{{ $v->version_title }}</span>
                                    </div>
                                    <div class="text-[11px] text-muted-foreground mt-0.5">
                                        {{ $v->file_name }} &bull; {{ $v->human_file_size }}
                                        @if($v->effective_from)
                                            &bull; {{ __('Effective: ') }}{{ $v->effective_from->format('d M Y') }}
                                        @endif
                                    </div>
                                </div>

                                <a
                                    href="{{ route('academic-calendar.download.version', $v->id) }}"
                                    class="p-1.5 rounded-lg text-primary hover:bg-primary/10 transition-colors"
                                    title="{{ __('Download this revision') }}"
                                >
                                    <x-icon name="download" class="h-4 w-4" />
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Month Navigation Pills -->
        <div class="rounded-xl border border-border bg-card p-3 shadow-2xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">
                    {{ __('Filter by Month') }}
                </span>
                @if(! empty($this->monthFilter) || ! empty($this->categoryFilter) || ! empty($this->search))
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="text-xs text-primary hover:underline cursor-pointer flex items-center gap-1"
                    >
                        <x-icon name="x" class="h-3 w-3" />
                        {{ __('Clear Filters') }}
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none">
                <button
                    type="button"
                    wire:click="$set('monthFilter', '')"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold whitespace-nowrap cursor-pointer transition-all {{ empty($monthFilter) ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-secondary text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('All Months') }} ({{ $this->activities->count() }})
                </button>

                @php
                    $monthSequence = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];
                @endphp

                @foreach($monthSequence as $m)
                    @php
                        $mName = \Illuminate\Support\Carbon::create(null, $m, 1)->format('M');
                        $cnt = $this->monthCounts[$m] ?? 0;
                        $isSelected = (string)$monthFilter === (string)$m;
                    @endphp
                    <button
                        type="button"
                        wire:click="$set('monthFilter', '{{ $isSelected ? '' : $m }}')"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-all flex items-center gap-1.5 {{ $isSelected ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : ($cnt > 0 ? 'bg-card text-foreground border border-border hover:bg-secondary' : 'bg-muted/40 text-muted-foreground hover:bg-secondary') }}"
                    >
                        <span>{{ $mName }}</span>
                        @if($cnt > 0)
                            <span class="rounded-full px-1.5 py-0.2 text-[10px] font-bold {{ $isSelected ? 'bg-white/20 text-white' : 'bg-primary/10 text-primary' }}">
                                {{ $cnt }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Search & Category Filters -->
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search activities, exams, holidays, workshops...') }}"
                    class="w-full rounded-lg border border-border bg-card pl-9 pr-8 py-2 text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/40"
                />
                @if($search)
                    <button
                        type="button"
                        wire:click="$set('search', '')"
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer"
                    >
                        <x-icon name="x" class="h-3.5 w-3.5" />
                    </button>
                @endif
            </div>

            <div class="w-full sm:w-64">
                <select
                    wire:model.live="categoryFilter"
                    class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-primary/40 cursor-pointer"
                >
                    <option value="">{{ __('All Event Categories') }}</option>
                    <option value="academic">{{ __('Academic Sessions') }}</option>
                    <option value="examination">{{ __('UPSC Tests & Examinations') }}</option>
                    <option value="holiday">{{ __('Holidays & Vacations') }}</option>
                    <option value="co_curricular">{{ __('Seminars & Guest Lectures') }}</option>
                    <option value="sports_cultural">{{ __('Cultural & Sports Meets') }}</option>
                </select>
            </div>
        </div>

        <!-- Month-Wise Activities Breakdown -->
        <div class="space-y-6">
            @php
                $grouped = $this->activities->groupBy(fn($item) => $item->month_name . ' ' . $item->year);
            @endphp

            @forelse($grouped as $monthTitle => $events)
                <div class="space-y-3">
                    <div class="flex items-center gap-2 pb-1 border-b border-border/80">
                        <span class="h-2 w-2 rounded-full bg-primary"></span>
                        <h3 class="text-sm font-bold uppercase tracking-wider text-foreground">
                            {{ $monthTitle }}
                        </h3>
                        <span class="rounded-full bg-secondary px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                            {{ $events->count() }} {{ Str::plural(__('Event'), $events->count()) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3.5">
                        @foreach($events as $act)
                            <div
                                wire:click="viewActivity({{ $act->id }})"
                                class="rounded-xl border border-border bg-card p-4 shadow-2xs hover:shadow-md hover:border-primary/40 transition-all cursor-pointer flex flex-col justify-between"
                            >
                                <div class="space-y-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider border {{ $act->getCategoryBadgeClasses() }}">
                                            {{ $act->getCategoryLabel() }}
                                        </span>
                                        <span class="rounded-md px-2 py-0.5 text-[10px] font-semibold border {{ $act->getStatusBadgeClasses() }}">
                                            {{ ucfirst($act->status) }}
                                        </span>
                                    </div>

                                    <h4 class="text-sm font-bold text-foreground leading-snug">
                                        {{ $act->title }}
                                    </h4>

                                    <div class="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                                        <x-icon name="calendar-days" class="h-4 w-4 text-primary shrink-0" />
                                        <span>{{ $act->formatted_date_range }}</span>
                                    </div>

                                    <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <x-icon name="map-pin" class="h-3.5 w-3.5 shrink-0" />
                                        <span class="capitalize font-medium">{{ $act->venue_mode }}</span>
                                        @if($act->venue_or_link)
                                            <span>&bull;</span>
                                            <span class="truncate">{{ $act->venue_or_link }}</span>
                                        @endif
                                    </div>

                                    @if($act->description)
                                        <p class="text-xs text-muted-foreground/90 line-clamp-2 pt-0.5">
                                            {{ $act->description }}
                                        </p>
                                    @endif
                                </div>

                                <div class="mt-3 pt-2 border-t border-border flex items-center justify-between text-[11px] text-muted-foreground">
                                    <span>{{ $act->department ?? __('Institutional') }}</span>
                                    <span class="text-primary font-semibold hover:underline">{{ __('View Details &rarr;') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-border bg-card p-8 text-center text-muted-foreground">
                    <p>{{ __('No activities match the selected filter criteria.') }}</p>
                </div>
            @endforelse
        </div>
    @endif

    <!-- Activity Detail Modal Dialog -->
    <x-ui.modal name="student-activity-modal" maxWidth="max-w-lg" :title="$this->selectedActivity?->title ?? __('Activity Details')" :description="__('Institutional calendar activity guidelines and operational specifics.')">
        @if($this->selectedActivity)
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider border {{ $this->selectedActivity->getCategoryBadgeClasses() }}">
                        {{ $this->selectedActivity->getCategoryLabel() }}
                    </span>
                    <span class="rounded-md px-2 py-0.5 text-[10px] font-semibold border {{ $this->selectedActivity->getStatusBadgeClasses() }}">
                        {{ ucfirst($this->selectedActivity->status) }}
                    </span>
                    @if($this->selectedActivity->is_tentative)
                        <span class="rounded-md px-1.5 py-0.5 text-[10px] font-bold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                            {{ __('Tentative Date') }}
                        </span>
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-secondary/40 p-3 space-y-2 text-xs">
                    <div class="flex items-center gap-2 text-foreground font-semibold">
                        <x-icon name="calendar-days" class="h-4 w-4 text-primary" />
                        <span>{{ $this->selectedActivity->formatted_date_range }}</span>
                    </div>

                    <div class="flex items-center gap-2 text-muted-foreground">
                        <x-icon name="map-pin" class="h-4 w-4 text-muted-foreground" />
                        <span class="capitalize font-medium text-foreground">{{ $this->selectedActivity->venue_mode }}</span>
                        @if($this->selectedActivity->venue_or_link)
                            <span>&bull;</span>
                            <span>{{ $this->selectedActivity->venue_or_link }}</span>
                        @endif
                    </div>

                    @if($this->selectedActivity->department)
                        <div class="flex items-center gap-2 text-muted-foreground">
                            <x-icon name="building-2" class="h-4 w-4 text-muted-foreground" />
                            <span>{{ __('Department:') }} <strong class="text-foreground">{{ $this->selectedActivity->department }}</strong></span>
                        </div>
                    @endif
                </div>

                @if($this->selectedActivity->description)
                    <div class="space-y-1">
                        <h4 class="text-xs font-bold text-foreground">{{ __('Guidelines & Information:') }}</h4>
                        <p class="text-xs text-muted-foreground whitespace-pre-line leading-relaxed">
                            {{ $this->selectedActivity->description }}
                        </p>
                    </div>
                @endif

                <div class="flex justify-end pt-3 border-t border-border">
                    <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'student-activity-modal' })">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>

