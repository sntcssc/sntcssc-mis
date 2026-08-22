<?php

use App\Concerns\WithSamplePagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Enrollments')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';
    public string $courseFilter = '';
    public string $feeFilter = '';

    public array $items = [
        ['id' => 1, 'student' => 'Priya Sharma', 'roll_no' => 'SNT-2026-001', 'course' => 'Composite Course', 'batch' => 'B-2026-A', 'enrolled_on' => '2026-01-12', 'fee_status' => 'Paid', 'amount' => 15000],
        ['id' => 2, 'student' => 'Rahul Verma', 'roll_no' => 'SNT-2026-002', 'course' => 'Prelims Crash Course', 'batch' => 'PCC-Jun', 'enrolled_on' => '2026-02-03', 'fee_status' => 'Partial', 'amount' => 7500],
        ['id' => 3, 'student' => 'Ankit Singh', 'roll_no' => 'SNT-2026-003', 'course' => 'Mains Guidance Programme', 'batch' => 'MGP-01', 'enrolled_on' => '2026-02-18', 'fee_status' => 'Paid', 'amount' => 12000],
        ['id' => 4, 'student' => 'Sneha Patel', 'roll_no' => 'SNT-2026-004', 'course' => 'Interview Training Programme', 'batch' => 'ITP-01', 'enrolled_on' => '2026-03-05', 'fee_status' => 'Overdue', 'amount' => 0],
        ['id' => 5, 'student' => 'Arjun Mehta', 'roll_no' => 'SNT-2026-005', 'course' => 'Composite Course', 'batch' => 'B-2026-A', 'enrolled_on' => '2026-03-11', 'fee_status' => 'Paid', 'amount' => 15000],
        ['id' => 6, 'student' => 'Kavya Reddy', 'roll_no' => 'SNT-2026-006', 'course' => 'Mock Interview Programme', 'batch' => 'MIP-02', 'enrolled_on' => '2026-03-19', 'fee_status' => 'Partial', 'amount' => 4000],
        ['id' => 7, 'student' => 'Deepak Kumar', 'roll_no' => 'SNT-2026-007', 'course' => 'Prelims Crash Course', 'batch' => 'PCC-Jun', 'enrolled_on' => '2026-04-02', 'fee_status' => 'Paid', 'amount' => 7500],
        ['id' => 8, 'student' => 'Vikram Yadav', 'roll_no' => 'SNT-2026-009', 'course' => 'Mains Guidance Programme', 'batch' => 'MGP-01', 'enrolled_on' => '2026-04-21', 'fee_status' => 'Overdue', 'amount' => 0],
        ['id' => 9, 'student' => 'Neha Gupta', 'roll_no' => 'SNT-2026-010', 'course' => 'Weekend Test Series', 'batch' => 'WTS-01', 'enrolled_on' => '2026-05-08', 'fee_status' => 'Paid', 'amount' => 3000],
        ['id' => 10, 'student' => 'Rohit Das', 'roll_no' => 'SNT-2026-011', 'course' => 'Composite Course', 'batch' => 'B-2026-B', 'enrolled_on' => '2026-05-14', 'fee_status' => 'Partial', 'amount' => 10000],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCourseFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFeeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function enrollments()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(fn ($item) => str_contains(strtolower($item['student'].' '.$item['roll_no']), strtolower($this->search))))
            ->when($this->courseFilter, fn ($query) => $query->where('course', $this->courseFilter))
            ->when($this->feeFilter, fn ($query) => $query->where('fee_status', $this->feeFilter))
            ->values()
            ->all();

        return $this->paginateSample($filtered);
    }

    #[Computed]
    public function courses()
    {
        return collect($this->items)->pluck('course')->unique()->sort()->values()->all();
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Enrollments') }}</h1>
            <span class="text-sm text-muted-foreground">({{ $this->enrollments->total() }})</span>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search enrollments…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select wire:model.live="courseFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All courses') }}</option>
                @foreach ($this->courses as $course)
                    <option value="{{ $course }}">{{ $course }}</option>
                @endforeach
            </select>

            <select wire:model.live="feeFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All fee statuses') }}</option>
                @foreach (['Paid', 'Partial', 'Overdue'] as $feeStatus)
                    <option value="{{ $feeStatus }}">{{ $feeStatus }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Desktop table --}}
    <div class="hidden md:block rounded-lg border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Student') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Course') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Batch') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Enrolled') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Paid amount') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Fee status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->enrollments as $enrollment)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="enr-{{ $enrollment['id'] }}">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$enrollment['student']" size="size-8 text-xs"/>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium truncate">{{ $enrollment['student'] }}</p>
                                        <p class="text-xs text-muted-foreground font-mono">{{ $enrollment['roll_no'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-sm">{{ $enrollment['course'] }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $enrollment['batch'] }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ \Carbon\Carbon::parse($enrollment['enrolled_on'])->format('d M Y') }}</td>
                            <td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums">₹{{ number_format($enrollment['amount']) }}</td>
                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="match($enrollment['fee_status']) { 'Paid' => 'success', 'Overdue' => 'danger', default => 'warning' }">
                                    {{ $enrollment['fee_status'] }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No enrollments found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->enrollments->links('partials.pagination') }}
    </div>

    {{-- Mobile cards --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->enrollments as $enrollment)
            <div class="rounded-lg border border-border bg-card p-4" wire:key="enr-m-{{ $enrollment['id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">{{ $enrollment['student'] }}</p>
                        <p class="text-xs text-muted-foreground truncate">{{ $enrollment['course'] }} · {{ $enrollment['batch'] }}</p>
                    </div>
                    <x-ui.badge :color="match($enrollment['fee_status']) { 'Paid' => 'success', 'Overdue' => 'danger', default => 'warning' }">{{ $enrollment['fee_status'] }}</x-ui.badge>
                </div>
                <div class="mt-3 flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ __('Paid') }}: <strong class="text-foreground">₹{{ number_format($enrollment['amount']) }}</strong></span>
                    <span class="text-muted-foreground">{{ \Carbon\Carbon::parse($enrollment['enrolled_on'])->format('d M Y') }}</span>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground text-sm">{{ __('No enrollments found') }}</div>
        @endforelse

        {{ $this->enrollments->links('partials.pagination') }}
    </div>
</div>
