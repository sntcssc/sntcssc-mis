<?php

use App\Concerns\WithSamplePagination;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Batches')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';
    public string $statusFilter = '';

    public array $form = [
        'code' => '',
        'course' => '',
        'timing' => '',
        'capacity' => '30',
        'starts_on' => '',
        'status' => 'Upcoming',
    ];

    public ?int $editingId = null;
    public ?int $deleteId = null;

    public array $items = [
        ['id' => 1, 'code' => 'B-2026-A', 'course' => 'Composite Course', 'timing' => 'Mon–Fri · 8:00–11:00', 'seats' => 42, 'capacity' => 45, 'starts_on' => '2026-01-15', 'status' => 'Running'],
        ['id' => 2, 'code' => 'B-2026-B', 'course' => 'Composite Course', 'timing' => 'Mon–Fri · 16:00–19:00', 'seats' => 31, 'capacity' => 45, 'starts_on' => '2026-02-01', 'status' => 'Running'],
        ['id' => 3, 'code' => 'PCC-Jun', 'course' => 'Prelims Crash Course', 'timing' => 'Daily · 7:00–13:00', 'seats' => 54, 'capacity' => 60, 'starts_on' => '2026-06-01', 'status' => 'Completed'],
        ['id' => 4, 'code' => 'PCC-Sep', 'course' => 'Prelims Crash Course', 'timing' => 'Daily · 7:00–13:00', 'seats' => 0, 'capacity' => 60, 'starts_on' => '2026-09-01', 'status' => 'Upcoming'],
        ['id' => 5, 'code' => 'MGP-01', 'course' => 'Mains Guidance Programme', 'timing' => 'Sat–Sun · 10:00–14:00', 'seats' => 28, 'capacity' => 30, 'starts_on' => '2026-03-10', 'status' => 'Running'],
        ['id' => 6, 'code' => 'MGP-02', 'course' => 'Mains Guidance Programme', 'timing' => 'Sat–Sun · 15:00–19:00', 'seats' => 6, 'capacity' => 30, 'starts_on' => '2026-08-25', 'status' => 'Upcoming'],
        ['id' => 7, 'code' => 'ITP-01', 'course' => 'Interview Training Programme', 'timing' => 'Tue–Thu · 17:00–19:00', 'seats' => 18, 'capacity' => 20, 'starts_on' => '2026-02-20', 'status' => 'Running'],
        ['id' => 8, 'code' => 'MIP-02', 'course' => 'Mock Interview Programme', 'timing' => 'Weekends · 10:00–16:00', 'seats' => 0, 'capacity' => 24, 'starts_on' => '2026-09-12', 'status' => 'Upcoming'],
        ['id' => 9, 'code' => 'WTS-01', 'course' => 'Weekend Test Series', 'timing' => 'Sunday · 9:00–12:00', 'seats' => 36, 'capacity' => 40, 'starts_on' => '2026-04-05', 'status' => 'Running'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['code' => '', 'course' => '', 'timing' => '', 'capacity' => '30', 'starts_on' => '', 'status' => 'Upcoming'];
        $this->dispatch('modal-open', name: 'batch-form');
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $item = collect($this->items)->firstWhere('id', $id);
        $this->form = collect($item)->only(array_keys($this->form))->all();
        $this->dispatch('modal-open', name: 'batch-form');
    }

    public function save(): void
    {
        $this->validate([
            'form.code' => 'required|string',
            'form.course' => 'required|string',
        ]);

        if ($this->editingId) {
            $this->items = collect($this->items)
                ->map(fn ($item) => $item['id'] === $this->editingId ? array_merge($item, $this->form) : $item)
                ->values()->all();
            Toast::dispatch($this, 'success', __('Batch updated.'));
        } else {
            array_unshift($this->items, array_merge([
                'id' => (int) (max(collect($this->items)->max('id') ?? 0) + 1),
                'seats' => 0,
            ], $this->form, ['capacity' => (int) $this->form['capacity']]));
            Toast::dispatch($this, 'success', __('Batch created.'));
        }

        $this->dispatch('modal-close', name: 'batch-form');
    }

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
    }

    public function deleteSelected(): void
    {
        if ($this->deleteId) {
            $this->items = collect($this->items)->reject(fn ($item) => $item['id'] === $this->deleteId)->values()->all();
            $this->deleteId = null;
            $this->dispatch('modal-close', name: 'batch-delete');
            Toast::dispatch($this, 'success', __('Batch deleted.'));
        }
    }

    #[Computed]
    public function batches()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(fn ($item) => str_contains(strtolower($item['code'].' '.$item['course']), strtolower($this->search))))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->values()
            ->all();

        return $this->paginateSample($filtered);
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Batches') }}</h1>
            <span class="text-sm text-muted-foreground">({{ $this->batches->total() }})</span>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.button size="sm" class="h-9" wire:click="create">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New batch') }}
            </x-ui.button>

            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search batches…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select wire:model.live="statusFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (['Upcoming', 'Running', 'Completed'] as $status)
                    <option value="{{ $status }}">{{ $status }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-ui.stat-card icon="layers" color="emerald" :label="__('Running')" :value="collect($items)->where('status', 'Running')->count()"/>
        <x-ui.stat-card icon="calendar" color="cyan" :label="__('Upcoming')" :value="collect($items)->where('status', 'Upcoming')->count()"/>
        <x-ui.stat-card icon="check-circle-2" color="secondary" :label="__('Completed')" :value="collect($items)->where('status', 'Completed')->count()"/>
        <x-ui.stat-card icon="users" color="amber" :label="__('Total seats filled')" :value="number_format(collect($items)->sum('seats'))"/>
    </div>

    {{-- Desktop table --}}
    <div class="hidden md:block rounded-lg border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Batch') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Course') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Timing') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Seats') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Starts') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->batches as $batch)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="bat-{{ $batch['id'] }}">
                            <td class="px-4 py-3.5 text-sm font-mono font-medium">{{ $batch['code'] }}</td>
                            <td class="px-4 py-3.5 text-sm">{{ $batch['course'] }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $batch['timing'] }}</td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-24 rounded-full bg-secondary overflow-hidden">
                                        <div class="h-full rounded-full {{ $batch['seats'] / max($batch['capacity'], 1) < 0.3 ? 'bg-amber-500' : 'bg-emerald-500' }}" style="width: {{ min(100, round($batch['seats'] / max($batch['capacity'], 1) * 100)) }}%"></div>
                                    </div>
                                    <span class="text-xs text-muted-foreground tabular-nums">{{ $batch['seats'] }}/{{ $batch['capacity'] }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ \Carbon\Carbon::parse($batch['starts_on'])->format('d M Y') }}</td>
                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="match($batch['status']) { 'Running' => 'success', 'Upcoming' => 'info', default => 'secondary' }">{{ $batch['status'] }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <x-ui.dropdown width="w-40" align="end">
                                    <x-slot:trigger>
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary transition-colors cursor-pointer" aria-label="{{ __('Actions') }}">
                                            <x-icon name="more-vertical" class="h-4 w-4 text-muted-foreground"/>
                                        </button>
                                    </x-slot:trigger>
                                    <x-ui.dropdown.item icon="pencil" wire:click="edit({{ $batch['id'] }})">{{ __('Edit') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.separator/>
                                    <x-ui.dropdown.item icon="trash-2" danger wire:click="selectForDelete({{ $batch['id'] }})" x-data x-on:click="$store.modals.open('batch-delete')">{{ __('Delete') }}</x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No batches found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->batches->links('partials.pagination') }}
    </div>

    {{-- Mobile cards --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->batches as $batch)
            <div class="rounded-lg border border-border bg-card p-4" wire:key="bat-m-{{ $batch['id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-mono font-medium">{{ $batch['code'] }}</p>
                        <p class="text-xs text-muted-foreground">{{ $batch['course'] }}</p>
                    </div>
                    <x-ui.badge :color="match($batch['status']) { 'Running' => 'success', 'Upcoming' => 'info', default => 'secondary' }">{{ $batch['status'] }}</x-ui.badge>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <div class="h-1.5 flex-1 rounded-full bg-secondary overflow-hidden">
                        <div class="h-full rounded-full {{ $batch['seats'] / max($batch['capacity'], 1) < 0.3 ? 'bg-amber-500' : 'bg-emerald-500' }}" style="width: {{ min(100, round($batch['seats'] / max($batch['capacity'], 1) * 100)) }}%"></div>
                    </div>
                    <span class="text-xs text-muted-foreground tabular-nums">{{ $batch['seats'] }}/{{ $batch['capacity'] }}</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <x-ui.button size="sm" variant="outline" class="flex-1" wire:click="edit({{ $batch['id'] }})">{{ __('Edit') }}</x-ui.button>
                    <x-ui.button size="sm" variant="outline" class="text-destructive" wire:click="selectForDelete({{ $batch['id'] }})" x-data x-on:click="$store.modals.open('batch-delete')">{{ __('Delete') }}</x-ui.button>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground text-sm">{{ __('No batches found') }}</div>
        @endforelse

        {{ $this->batches->links('partials.pagination') }}
    </div>

    {{-- Form modal --}}
    <x-ui.modal name="batch-form" max-width="max-w-lg" :title="$editingId ? __('Edit batch') : __('New batch')" :description="__('Batch schedule and capacity.')">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input wire:model="form.code" :label="__('Batch code') .' *'" required/>
                <x-ui.input wire:model="form.course" :label="__('Course') .' *'" required/>
                <x-ui.input wire:model="form.timing" :label="__('Timing')"/>
                <x-ui.input wire:model="form.capacity" :label="__('Capacity')" type="number" min="1"/>
                <x-ui.input wire:model="form.starts_on" :label="__('Starts on')" type="date"/>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Status') }}</label>
                    <x-ui.select wire:model="form.status" class="mt-2" :options="['Upcoming' => __('Upcoming'), 'Running' => __('Running'), 'Completed' => __('Completed')]"/>
                </div>
            </div>

            @if ($errors->any())
                <p class="text-xs text-destructive">{{ $errors->first() }}</p>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('batch-form')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save batch') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete confirmation --}}
    <x-ui.modal name="batch-delete" max-width="max-w-sm" :title="__('Delete batch')" :description="__('This action cannot be undone.')">
        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('batch-delete')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deleteSelected">{{ __('Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
