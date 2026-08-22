<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Reports')] class extends Component {
    public string $search = '';

    public array $groups = [
        [
            'title' => 'Admissions',
            'classes' => 'bg-emerald-500/15 text-emerald-500',
            'icon' => 'file-text',
            'reports' => [
                ['name' => 'Applications summary', 'description' => 'Daily, weekly and monthly application counts', 'popular' => true],
                ['name' => 'Selection lists (A/B)', 'description' => 'Selected vs waiting candidates by test', 'popular' => true],
                ['name' => 'Admission test results', 'description' => 'Marks distribution and pass rates'],
            ],
        ],
        [
            'title' => 'Students',
            'classes' => 'bg-cyan-500/15 text-cyan-500',
            'icon' => 'users',
            'reports' => [
                ['name' => 'Enrollment summary', 'description' => 'Enrollments by course, batch and period', 'popular' => true],
                ['name' => 'Student directory', 'description' => 'Full student list with contact details'],
                ['name' => 'Attendance report', 'description' => 'Session-wise attendance by batch'],
            ],
        ],
        [
            'title' => 'Fees & Collections',
            'classes' => 'bg-amber-500/15 text-amber-500',
            'icon' => 'banknote',
            'reports' => [
                ['name' => 'Collections summary', 'description' => 'Fee collected by course, mode and period', 'popular' => true],
                ['name' => 'Outstanding installments', 'description' => 'Overdue and upcoming payments'],
                ['name' => 'Refunds issued', 'description' => 'Refund history with reasons'],
            ],
        ],
        [
            'title' => 'Batches & Courses',
            'classes' => 'bg-violet-500/15 text-violet-500',
            'icon' => 'layers',
            'reports' => [
                ['name' => 'Batch occupancy', 'description' => 'Seats filled vs capacity across batches'],
                ['name' => 'Course performance', 'description' => 'Completion and result rates by course'],
                ['name' => 'Faculty allocation', 'description' => 'Sessions assigned per faculty member'],
            ],
        ],
        [
            'title' => 'System',
            'classes' => 'bg-rose-500/15 text-rose-500',
            'icon' => 'activity',
            'reports' => [
                ['name' => 'User activity log', 'description' => 'Audit trail of user actions'],
                ['name' => 'Login history', 'description' => 'Successful and failed sign-in attempts'],
            ],
        ],
    ];

    #[Computed]
    public function reportGroups()
    {
        if ($this->search === '') {
            return collect($this->groups);
        }

        $needle = strtolower($this->search);

        return collect($this->groups)
            ->map(fn ($group) => array_merge($group, [
                'reports' => collect($group['reports'])->filter(fn ($report) => str_contains(strtolower($report['name'].' '.$report['description']), $needle))->values()->all(),
            ]))
            ->filter(fn ($group) => count($group['reports']) > 0)
            ->values();
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('All reports') }}</h1>
            <p class="text-sm text-muted-foreground mt-1">{{ __('Browse and run reports across the MIS.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" class="h-9" href="{{ route('admin.reports.saved') }}" wire:navigate>
                <x-icon name="bookmark" class="h-4 w-4"/>
                {{ __('Saved reports') }}
            </x-ui.button>

            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search reports…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>
        </div>
    </div>

    @forelse ($this->reportGroups as $group)
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center gap-2.5 mb-4">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $group['classes'] }}">
                    <x-icon :name="$group['icon']" class="h-4 w-4"/>
                </span>
                <h2 class="text-sm font-semibold">{{ $group['title'] }}</h2>
                <span class="text-xs text-muted-foreground">({{ count($group['reports']) }})</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($group['reports'] as $report)
                    <button type="button" class="group rounded-lg border border-border p-3 sm:p-4 text-left hover:bg-secondary/30 transition-colors cursor-pointer">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-medium group-hover:text-emerald-500 transition-colors">{{ $report['name'] }}</span>
                            @if ($report['popular'] ?? false)
                                <x-ui.badge color="warning">{{ __('Popular') }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ $report['description'] }}</p>
                        <div class="mt-3 flex items-center gap-1 text-xs text-muted-foreground group-hover:text-emerald-500 transition-colors">
                            {{ __('Run report') }}
                            <x-icon name="arrow-right" class="h-3 w-3"/>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-border bg-card p-12 text-center text-muted-foreground">
            <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
            <p class="text-sm font-medium">{{ __('No reports found') }}</p>
        </div>
    @endforelse
</div>
