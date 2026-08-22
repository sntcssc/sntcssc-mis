<?php

use App\Concerns\WithSamplePagination;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Admissions')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $listFilter = '';

    public ?int $viewingId = null;

    public array $items = [
        ['id' => 1, 'application_no' => 'APP-2026-1248', 'name' => 'Rahul Verma', 'email' => 'rahul.verma@example.com', 'applied_on' => '2026-08-01', 'marks_a' => 18, 'marks_b' => 22, 'marks_c' => 19, 'marks_d' => 15, 'total' => 74, 'selection' => 'List B', 'status' => 'Reviewed', 'selected_on' => null],
        ['id' => 2, 'application_no' => 'APP-2026-1249', 'name' => 'Sneha Patel', 'email' => 'sneha.patel@example.com', 'applied_on' => '2026-08-02', 'marks_a' => 21, 'marks_b' => 24, 'marks_c' => 20, 'marks_d' => 17, 'total' => 82, 'selection' => 'List A', 'status' => 'Selected', 'selected_on' => '2026-08-10'],
        ['id' => 3, 'application_no' => 'APP-2026-1250', 'name' => 'Vikram Yadav', 'email' => 'vikram.yadav@example.com', 'applied_on' => '2026-08-03', 'marks_a' => 15, 'marks_b' => 18, 'marks_c' => 14, 'marks_d' => 12, 'total' => 59, 'selection' => null, 'status' => 'Pending', 'selected_on' => null],
        ['id' => 4, 'application_no' => 'APP-2026-1251', 'name' => 'Neha Gupta', 'email' => 'neha.gupta@example.com', 'applied_on' => '2026-08-05', 'marks_a' => 20, 'marks_b' => 21, 'marks_c' => 22, 'marks_d' => 16, 'total' => 79, 'selection' => 'List A', 'status' => 'Selected', 'selected_on' => '2026-08-12'],
        ['id' => 5, 'application_no' => 'APP-2026-1252', 'name' => 'Rohit Das', 'email' => 'rohit.das@example.com', 'applied_on' => '2026-08-07', 'marks_a' => 12, 'marks_b' => 14, 'marks_c' => 11, 'marks_d' => 10, 'total' => 47, 'selection' => null, 'status' => 'Rejected', 'selected_on' => null],
        ['id' => 6, 'application_no' => 'APP-2026-1253', 'name' => 'Pooja Nair', 'email' => 'pooja.nair@example.com', 'applied_on' => '2026-08-09', 'marks_a' => 19, 'marks_b' => 20, 'marks_c' => 18, 'marks_d' => 14, 'total' => 71, 'selection' => 'List B', 'status' => 'Reviewed', 'selected_on' => null],
        ['id' => 7, 'application_no' => 'APP-2026-1254', 'name' => 'Amit Joshi', 'email' => 'amit.joshi@example.com', 'applied_on' => '2026-08-11', 'marks_a' => 22, 'marks_b' => 23, 'marks_c' => 21, 'marks_d' => 18, 'total' => 84, 'selection' => 'List A', 'status' => 'Selected', 'selected_on' => '2026-08-15'],
        ['id' => 8, 'application_no' => 'APP-2026-1255', 'name' => 'Ritu Singh', 'email' => 'ritu.singh@example.com', 'applied_on' => '2026-08-13', 'marks_a' => 16, 'marks_b' => 19, 'marks_c' => 17, 'marks_d' => 13, 'total' => 65, 'selection' => null, 'status' => 'Pending', 'selected_on' => null],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingListFilter(): void
    {
        $this->resetPage();
    }

    public function viewApplication(int $id): void
    {
        $this->viewingId = $id;
        $this->dispatch('modal-open', name: 'admission-view');
    }

    public function moveTo(string $list, int $id): void
    {
        $this->items = collect($this->items)
            ->map(fn ($item) => $item['id'] === $id
                ? array_merge($item, ['selection' => $list, 'status' => 'Selected', 'selected_on' => now()->toDateString()])
                : $item)
            ->values()
            ->all();

        $this->dispatch('modal-close', name: 'admission-view');
        Toast::dispatch($this, 'success', __('Application moved to :list.', ['list' => $list]));
    }

    #[Computed]
    public function admissions()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(fn ($item) => str_contains(strtolower($item['name'].' '.$item['application_no']), strtolower($this->search))))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->listFilter, fn ($query) => $query->where('selection', $this->listFilter))
            ->values()
            ->all();

        return $this->paginateSample($filtered);
    }

    #[Computed]
    public function viewing()
    {
        return collect($this->items)->firstWhere('id', $this->viewingId);
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Admissions') }}</h1>
            <span class="text-sm text-muted-foreground">({{ $this->admissions->total() }})</span>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search applications…') }}"
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
                @foreach (['Pending', 'Reviewed', 'Selected', 'Rejected'] as $status)
                    <option value="{{ $status }}">{{ $status }}</option>
                @endforeach
            </select>

            <select wire:model.live="listFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All lists') }}</option>
                <option value="List A">{{ __('List A (Selection)') }}</option>
                <option value="List B">{{ __('List B (Waiting)') }}</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-ui.stat-card icon="file-text" color="secondary" :label="__('Applications')" :value="$this->admissions->total()"/>
        <x-ui.stat-card icon="check-circle-2" color="emerald" :label="__('Selected (List A)')" :value="collect($items)->where('selection', 'List A')->count()"/>
        <x-ui.stat-card icon="clock" color="amber" :label="__('Pending review')" :value="collect($items)->where('status', 'Pending')->count()"/>
        <x-ui.stat-card icon="x" color="rose" :label="__('Rejected')" :value="collect($items)->where('status', 'Rejected')->count()"/>
    </div>

    {{-- Desktop table --}}
    <div class="hidden md:block rounded-lg border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Application') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Applicant') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Applied') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('A') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('B') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('C') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('D') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Total') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('List') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->admissions as $admission)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="adm-{{ $admission['id'] }}">
                            <td class="px-4 py-3.5 text-sm font-mono font-medium">{{ $admission['application_no'] }}</td>
                            <td class="px-4 py-3.5">
                                <p class="text-sm font-medium">{{ $admission['name'] }}</p>
                                <p class="text-xs text-muted-foreground">{{ $admission['email'] }}</p>
                            </td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ \Carbon\Carbon::parse($admission['applied_on'])->format('d M Y') }}</td>
                            <td class="px-4 py-3.5 text-right text-sm text-muted-foreground tabular-nums">{{ $admission['marks_a'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm text-muted-foreground tabular-nums">{{ $admission['marks_b'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm text-muted-foreground tabular-nums">{{ $admission['marks_c'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm text-muted-foreground tabular-nums">{{ $admission['marks_d'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums">{{ $admission['total'] }}</td>
                            <td class="px-4 py-3.5">
                                @if ($admission['selection'])
                                    <x-ui.badge :color="$admission['selection'] === 'List A' ? 'success' : 'warning'">{{ $admission['selection'] }}</x-ui.badge>
                                @else
                                    <span class="text-xs text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="match($admission['status']) { 'Selected' => 'success', 'Pending' => 'warning', 'Rejected' => 'danger', default => 'info' }">
                                    {{ $admission['status'] }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <x-ui.button size="sm" variant="outline" wire:click="viewApplication({{ $admission['id'] }})">{{ __('View') }}</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No applications found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->admissions->links('partials.pagination') }}
    </div>

    {{-- Mobile cards --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->admissions as $admission)
            <div class="rounded-lg border border-border bg-card p-4" wire:key="adm-m-{{ $admission['id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium">{{ $admission['name'] }}</p>
                        <p class="text-xs font-mono text-muted-foreground">{{ $admission['application_no'] }}</p>
                    </div>
                    <x-ui.badge :color="match($admission['status']) { 'Selected' => 'success', 'Pending' => 'warning', 'Rejected' => 'danger', default => 'info' }">{{ $admission['status'] }}</x-ui.badge>
                </div>
                <div class="mt-3 grid grid-cols-5 gap-1.5 text-center">
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground">A</p>
                        <p class="text-xs font-semibold">{{ $admission['marks_a'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground">B</p>
                        <p class="text-xs font-semibold">{{ $admission['marks_b'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground">C</p>
                        <p class="text-xs font-semibold">{{ $admission['marks_c'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground">D</p>
                        <p class="text-xs font-semibold">{{ $admission['marks_d'] }}</p>
                    </div>
                    <div class="rounded-md bg-emerald-500/10 py-2">
                        <p class="text-[10px] text-muted-foreground">{{ __('Total') }}</p>
                        <p class="text-xs font-semibold text-emerald-500">{{ $admission['total'] }}</p>
                    </div>
                </div>
                <x-ui.button size="sm" variant="outline" class="mt-3 w-full" wire:click="viewApplication({{ $admission['id'] }})">{{ __('View application') }}</x-ui.button>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground text-sm">{{ __('No applications found') }}</div>
        @endforelse

        {{ $this->admissions->links('partials.pagination') }}
    </div>

    {{-- View modal --}}
    <x-ui.modal name="admission-view" max-width="max-w-lg" :title="__('Application details')" :description="$this->viewing['application_no'] ?? ''">
        @if ($this->viewing)
            <div class="space-y-4">
                <div class="flex items-center gap-3 rounded-lg border border-border p-3">
                    <x-ui.avatar :name="$this->viewing['name']" size="size-10 text-sm"/>
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $this->viewing['name'] }}</p>
                        <p class="text-xs text-muted-foreground truncate">{{ $this->viewing['email'] }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-5 gap-2 text-center">
                    <div class="rounded-md bg-secondary/50 py-2.5">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Paper A') }}</p>
                        <p class="text-sm font-bold mt-0.5">{{ $this->viewing['marks_a'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2.5">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Paper B') }}</p>
                        <p class="text-sm font-bold mt-0.5">{{ $this->viewing['marks_b'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2.5">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Paper C') }}</p>
                        <p class="text-sm font-bold mt-0.5">{{ $this->viewing['marks_c'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2.5">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Paper D') }}</p>
                        <p class="text-sm font-bold mt-0.5">{{ $this->viewing['marks_d'] }}</p>
                    </div>
                    <div class="rounded-md bg-emerald-500/10 py-2.5">
                        <p class="text-[10px] text-muted-foreground uppercase">{{ __('Total') }}</p>
                        <p class="text-sm font-bold mt-0.5 text-emerald-500">{{ $this->viewing['total'] }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-between rounded-lg border border-border px-3 py-2.5 text-sm">
                    <span class="text-muted-foreground">{{ __('Current selection') }}</span>
                    <x-ui.badge :color="$this->viewing['selection'] === 'List A' ? 'success' : ($this->viewing['selection'] === 'List B' ? 'warning' : 'secondary')">{{ $this->viewing['selection'] ?? __('Not selected') }}</x-ui.badge>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('admission-view')">{{ __('Close') }}</x-ui.button>
                    <x-ui.button wire:click="moveTo('List B', {{ $this->viewing['id'] }})">{{ __('Move to List B') }}</x-ui.button>
                    <x-ui.button wire:click="moveTo('List A', {{ $this->viewing['id'] }})">{{ __('Select (List A)') }}</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
