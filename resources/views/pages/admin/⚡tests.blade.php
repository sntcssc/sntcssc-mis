<?php

use App\Concerns\WithSamplePagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Tests & Selections')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';
    public string $selectionFilter = '';

    public array $items = [
        ['id' => 1, 'test' => 'Admission Test #14', 'held_on' => '2026-08-02', 'applicants' => 96, 'avg_score' => 63, 'highest' => 91, 'selected_a' => 28, 'selected_b' => 19],
        ['id' => 2, 'test' => 'Admission Test #15', 'held_on' => '2026-08-09', 'applicants' => 112, 'avg_score' => 67, 'highest' => 94, 'selected_a' => 34, 'selected_b' => 22],
        ['id' => 3, 'test' => 'Admission Test #16', 'held_on' => '2026-08-16', 'applicants' => 87, 'avg_score' => 61, 'highest' => 88, 'selected_a' => 24, 'selected_b' => 16],
        ['id' => 4, 'test' => 'Scholarship Test', 'held_on' => '2026-07-20', 'applicants' => 143, 'avg_score' => 58, 'highest' => 97, 'selected_a' => 40, 'selected_b' => 12],
        ['id' => 5, 'test' => 'Mock Prelims #7', 'held_on' => '2026-07-06', 'applicants' => 204, 'avg_score' => 52, 'highest' => 86, 'selected_a' => 0, 'selected_b' => 0],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSelectionFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function tests()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(fn ($item) => str_contains(strtolower($item['test']), strtolower($this->search))))
            ->when($this->selectionFilter === 'with', fn ($query) => $query->filter(fn ($item) => $item['selected_a'] + $item['selected_b'] > 0))
            ->when($this->selectionFilter === 'without', fn ($query) => $query->filter(fn ($item) => $item['selected_a'] + $item['selected_b'] === 0))
            ->values()
            ->all();

        return $this->paginateSample($filtered);
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Tests & Selections') }}</h1>
            <p class="text-sm text-muted-foreground mt-1">{{ __('Admission test results and selection lists (A / B).') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search tests…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <select wire:model.live="selectionFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All tests') }}</option>
                <option value="with">{{ __('With selections') }}</option>
                <option value="without">{{ __('Without selections') }}</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-ui.stat-card icon="clipboard-check" color="emerald" :label="__('Tests held')" :value="count($items)"/>
        <x-ui.stat-card icon="users" color="cyan" :label="__('Total applicants')" :value="number_format(collect($items)->sum('applicants'))"/>
        <x-ui.stat-card icon="check-circle-2" color="violet" :label="__('Selected (List A)')" :value="number_format(collect($items)->sum('selected_a'))"/>
        <x-ui.stat-card icon="clock" color="amber" :label="__('Waiting (List B)')" :value="number_format(collect($items)->sum('selected_b'))"/>
    </div>

    {{-- Desktop table --}}
    <div class="hidden md:block rounded-lg border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Test') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Held on') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Applicants') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Avg score') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Highest') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('List A') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('List B') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->tests as $test)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="test-{{ $test['id'] }}">
                            <td class="px-4 py-3.5 text-sm font-medium">{{ $test['test'] }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ \Carbon\Carbon::parse($test['held_on'])->format('d M Y') }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $test['applicants'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $test['avg_score'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm font-semibold text-emerald-500 tabular-nums">{{ $test['highest'] }}</td>
                            <td class="px-4 py-3.5 text-right">
                                @if ($test['selected_a'] > 0)
                                    <x-ui.badge color="success">{{ $test['selected_a'] }} {{ __('selected') }}</x-ui.badge>
                                @else
                                    <span class="text-xs text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                @if ($test['selected_b'] > 0)
                                    <x-ui.badge color="warning">{{ $test['selected_b'] }} {{ __('waiting') }}</x-ui.badge>
                                @else
                                    <span class="text-xs text-muted-foreground">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No tests found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->tests->links('partials.pagination') }}
    </div>

    {{-- Mobile cards --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->tests as $test)
            <div class="rounded-lg border border-border bg-card p-4" wire:key="test-m-{{ $test['id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium">{{ $test['test'] }}</p>
                        <p class="text-xs text-muted-foreground">{{ \Carbon\Carbon::parse($test['held_on'])->format('d M Y') }}</p>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Applicants') }}</p>
                        <p class="text-xs font-semibold mt-0.5">{{ $test['applicants'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Avg') }}</p>
                        <p class="text-xs font-semibold mt-0.5">{{ $test['avg_score'] }}</p>
                    </div>
                    <div class="rounded-md bg-emerald-500/10 py-2">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Highest') }}</p>
                        <p class="text-xs font-semibold mt-0.5 text-emerald-500">{{ $test['highest'] }}</p>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground text-sm">{{ __('No tests found') }}</div>
        @endforelse

        {{ $this->tests->links('partials.pagination') }}
    </div>
</div>
