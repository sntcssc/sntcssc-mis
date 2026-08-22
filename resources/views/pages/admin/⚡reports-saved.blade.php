<?php

use App\Concerns\WithSamplePagination;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Saved reports')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';

    public array $items = [
        ['id' => 1, 'name' => 'Weekly admissions digest', 'type' => 'Applications summary', 'schedule' => 'Every Monday · 08:00', 'created_by' => 'Admin', 'created_at' => '2026-07-14', 'runs' => 6],
        ['id' => 2, 'name' => 'Month-end fee collection', 'type' => 'Collections summary', 'schedule' => 'Monthly · 1st', 'created_by' => 'Alok Rathore', 'created_at' => '2026-06-01', 'runs' => 3],
        ['id' => 3, 'name' => 'List A candidates — Test #15', 'type' => 'Selection lists (A/B)', 'schedule' => 'On demand', 'created_by' => 'Admin', 'created_at' => '2026-08-10', 'runs' => 1],
        ['id' => 4, 'name' => 'Low occupancy batches', 'type' => 'Batch occupancy', 'schedule' => 'Every Friday · 17:00', 'created_by' => 'Rakesh Prasad', 'created_at' => '2026-07-25', 'runs' => 4],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function run(int $id): void
    {
        Toast::dispatch($this, 'info', __('Report queued — you will be notified when it is ready (design preview).'));
    }

    public function delete(int $id): void
    {
        $this->items = collect($this->items)->reject(fn ($item) => $item['id'] === $id)->values()->all();
        Toast::dispatch($this, 'success', __('Saved report deleted.'));
    }

    #[Computed]
    public function reports()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(fn ($item) => str_contains(strtolower($item['name']), strtolower($this->search))))
            ->values()
            ->all();

        return $this->paginateSample($filtered);
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.reports.index') }}" wire:navigate class="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-card hover:bg-secondary transition-colors cursor-pointer">
                <x-icon name="arrow-left" class="h-4 w-4 text-muted-foreground"/>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Saved reports') }}</h1>
                <p class="text-sm text-muted-foreground mt-0.5">{{ __('Your saved and scheduled reports.') }}</p>
            </div>
        </div>

        <div class="relative">
            <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search saved reports…') }}"
                class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            />
        </div>
    </div>

    <div class="rounded-lg border border-border bg-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[700px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Report') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Schedule') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Created by') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Runs') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->reports as $report)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="saved-{{ $report['id'] }}">
                            <td class="px-4 py-3.5">
                                <p class="text-sm font-medium">{{ $report['name'] }}</p>
                                <p class="text-xs text-muted-foreground">{{ $report['type'] }}</p>
                            </td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $report['schedule'] }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $report['created_by'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $report['runs'] }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <x-ui.button size="sm" variant="outline" wire:click="run({{ $report['id'] }})">{{ __('Run') }}</x-ui.button>
                                    <button type="button" wire:click="delete({{ $report['id'] }})" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-destructive/10 transition-colors cursor-pointer" title="{{ __('Delete') }}">
                                        <x-icon name="trash-2" class="h-4 w-4 text-destructive"/>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="bookmark" class="mx-auto h-8 w-8 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No saved reports yet') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->reports->links('partials.pagination') }}
    </div>
</div>
