<?php

use App\Concerns\WithSamplePagination;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Courses')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';
    public string $categoryFilter = '';
    public string $view = 'grid';

    public array $form = [
        'name' => '',
        'category' => 'Classroom',
        'code' => '',
        'duration' => '',
        'fee' => '',
        'status' => 'Active',
    ];

    public ?int $editingId = null;
    public ?int $deleteId = null;

    public array $items = [
        ['id' => 1, 'name' => 'Composite Course', 'code' => 'CC-FY', 'category' => 'Classroom', 'duration' => '12 months', 'fee' => 15000, 'enrolled' => 184, 'status' => 'Active'],
        ['id' => 2, 'name' => 'Prelims Crash Course', 'code' => 'PCC', 'category' => 'Classroom', 'duration' => '10 weeks', 'fee' => 7500, 'enrolled' => 126, 'status' => 'Active'],
        ['id' => 3, 'name' => 'Mains Guidance Programme', 'code' => 'MGP', 'category' => 'Classroom', 'duration' => '16 weeks', 'fee' => 12000, 'enrolled' => 98, 'status' => 'Active'],
        ['id' => 4, 'name' => 'Interview Training Programme', 'code' => 'ITP', 'category' => 'Interview', 'duration' => '4 weeks', 'fee' => 10000, 'enrolled' => 64, 'status' => 'Active'],
        ['id' => 5, 'name' => 'Mock Interview Programme', 'code' => 'MIP', 'category' => 'Interview', 'duration' => '2 weeks', 'fee' => 8000, 'enrolled' => 41, 'status' => 'Active'],
        ['id' => 6, 'name' => 'Weekend Test Series', 'code' => 'WTS', 'category' => 'Test Series', 'duration' => '24 weeks', 'fee' => 3000, 'enrolled' => 28, 'status' => 'Active'],
        ['id' => 7, 'name' => 'CSAT Foundation', 'code' => 'CSAT-F', 'category' => 'Classroom', 'duration' => '8 weeks', 'fee' => 5000, 'enrolled' => 0, 'status' => 'Inactive'],
        ['id' => 8, 'name' => 'Optional Subject Guidance', 'code' => 'OPT-G', 'category' => 'Classroom', 'duration' => '20 weeks', 'fee' => 14000, 'enrolled' => 52, 'status' => 'Active'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'category' => 'Classroom', 'code' => '', 'duration' => '', 'fee' => '', 'status' => 'Active'];
        $this->dispatch('modal-open', name: 'course-form');
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = collect(collect($this->items)->firstWhere('id', $id))->only(array_keys($this->form))->all();
        $this->dispatch('modal-open', name: 'course-form');
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => 'required|string|min:3',
            'form.code' => 'required|string',
        ]);

        if ($this->editingId) {
            $this->items = collect($this->items)
                ->map(fn ($item) => $item['id'] === $this->editingId ? array_merge($item, $this->form) : $item)
                ->values()->all();
            Toast::dispatch($this, 'success', __('Course updated.'));
        } else {
            array_unshift($this->items, array_merge([
                'id' => (int) (max(collect($this->items)->max('id') ?? 0) + 1),
                'enrolled' => 0,
                'fee' => (int) $this->form['fee'],
            ], $this->form));
            Toast::dispatch($this, 'success', __('Course created.'));
        }

        $this->dispatch('modal-close', name: 'course-form');
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
            $this->dispatch('modal-close', name: 'course-delete');
            Toast::dispatch($this, 'success', __('Course deleted.'));
        }
    }

    #[Computed]
    public function courses()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(fn ($item) => str_contains(strtolower($item['name'].' '.$item['code']), strtolower($this->search))))
            ->when($this->categoryFilter, fn ($query) => $query->where('category', $this->categoryFilter))
            ->values()
            ->all();

        return $this->paginateSample($filtered);
    }

    #[Computed]
    public function categories()
    {
        return collect($this->items)->pluck('category')->unique()->sort()->values()->all();
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Courses') }}</h1>
            <span class="text-sm text-muted-foreground">({{ $this->courses->total() }})</span>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.button size="sm" class="h-9" wire:click="create">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New course') }}
            </x-ui.button>

            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search courses…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <select wire:model.live="categoryFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category }}">{{ $category }}</option>
                @endforeach
            </select>

            {{-- View toggle --}}
            <div class="flex rounded-md border border-border overflow-hidden">
                <button type="button" wire:click="$set('view', 'grid')" class="flex h-9 w-9 items-center justify-center transition-colors cursor-pointer {{ $view === 'grid' ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground' }}" title="{{ __('Grid view') }}">
                    <x-icon name="layout-grid" class="h-4 w-4"/>
                </button>
                <button type="button" wire:click="$set('view', 'table')" class="flex h-9 w-9 items-center justify-center transition-colors cursor-pointer border-l border-border {{ $view === 'table' ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground' }}" title="{{ __('Table view') }}">
                    <x-icon name="list" class="h-4 w-4"/>
                </button>
            </div>
        </div>
    </div>

    {{-- Grid view --}}
    @if ($view === 'grid')
        <div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                @forelse ($this->courses as $course)
                    <div class="group rounded-xl border border-border bg-card overflow-hidden transition-all hover:-translate-y-0.5 hover:shadow-md" wire:key="course-g-{{ $course['id'] }}">
                        <div class="relative aspect-[4/3] bg-muted flex items-center justify-center">
                            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/15">
                                <x-icon name="graduation-cap" class="h-6 w-6 text-emerald-500"/>
                            </span>
                            <div class="absolute top-2 left-2">
                                <x-ui.badge :color="$course['status'] === 'Active' ? 'success' : 'secondary'">{{ $course['status'] }}</x-ui.badge>
                            </div>
                            <div class="absolute top-2 right-2">
                                <x-ui.dropdown width="w-36" align="end">
                                    <x-slot:trigger>
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-md bg-background/80 backdrop-blur hover:bg-background cursor-pointer" aria-label="{{ __('Actions') }}">
                                            <x-icon name="more-vertical" class="h-3.5 w-3.5"/>
                                        </button>
                                    </x-slot:trigger>
                                    <x-ui.dropdown.item icon="pencil" wire:click="edit({{ $course['id'] }})">{{ __('Edit') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.item icon="trash-2" danger wire:click="selectForDelete({{ $course['id'] }})" x-data x-on:click="$store.modals.open('course-delete')">{{ __('Delete') }}</x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </div>
                        </div>
                        <div class="p-3">
                            <p class="text-sm font-medium truncate group-hover:text-emerald-500 transition-colors">{{ $course['name'] }}</p>
                            <p class="text-xs text-muted-foreground mt-0.5">{{ $course['code'] }} · {{ $course['duration'] }}</p>
                            <div class="mt-2 flex items-center justify-between">
                                <span class="text-sm font-bold">₹{{ number_format($course['fee']) }}</span>
                                <span class="text-[10px] text-muted-foreground">{{ $course['enrolled'] }} {{ __('enrolled') }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full rounded-lg border border-border bg-card p-12 text-center text-muted-foreground">
                        <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
                        <p class="text-sm font-medium">{{ __('No courses found') }}</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-4">
                {{ $this->courses->links('partials.pagination') }}
            </div>
        </div>

    {{-- Table view --}}
    @else
        <div class="rounded-lg border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px]">
                    <thead>
                        <tr class="border-b border-border">
                            <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Course') }}</th>
                            <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Category') }}</th>
                            <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Duration') }}</th>
                            <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Fee') }}</th>
                            <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Enrolled') }}</th>
                            <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->courses as $course)
                            <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="course-t-{{ $course['id'] }}">
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/10 shrink-0">
                                            <x-icon name="graduation-cap" class="h-4 w-4 text-emerald-500"/>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium truncate">{{ $course['name'] }}</p>
                                            <p class="text-xs text-muted-foreground font-mono">{{ $course['code'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5">
                                    <x-ui.badge color="secondary">{{ $course['category'] }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $course['duration'] }}</td>
                                <td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums">₹{{ number_format($course['fee']) }}</td>
                                <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $course['enrolled'] }}</td>
                                <td class="px-4 py-3.5">
                                    <x-ui.badge :color="$course['status'] === 'Active' ? 'success' : 'secondary'">{{ $course['status'] }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3.5 text-right">
                                    <x-ui.dropdown width="w-40" align="end">
                                        <x-slot:trigger>
                                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary transition-colors cursor-pointer" aria-label="{{ __('Actions') }}">
                                                <x-icon name="more-vertical" class="h-4 w-4 text-muted-foreground"/>
                                            </button>
                                        </x-slot:trigger>
                                        <x-ui.dropdown.item icon="pencil" wire:click="edit({{ $course['id'] }})">{{ __('Edit') }}</x-ui.dropdown.item>
                                        <x-ui.dropdown.separator/>
                                        <x-ui.dropdown.item icon="trash-2" danger wire:click="selectForDelete({{ $course['id'] }})" x-data x-on:click="$store.modals.open('course-delete')">{{ __('Delete') }}</x-ui.dropdown.item>
                                    </x-ui.dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                    <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
                                    <p class="text-sm font-medium">{{ __('No courses found') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $this->courses->links('partials.pagination') }}
        </div>
    @endif

    {{-- Form modal --}}
    <x-ui.modal name="course-form" max-width="max-w-lg" :title="$editingId ? __('Edit course') : __('New course')" :description="__('Course details, duration and fee.')">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input wire:model="form.name" :label="__('Course name') .' *'" required/>
                <x-ui.input wire:model="form.code" :label="__('Course code') .' *'" required/>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Category') }}</label>
                    <x-ui.select wire:model="form.category" class="mt-2" :options="['Classroom' => __('Classroom'), 'Interview' => __('Interview'), 'Test Series' => __('Test Series'), 'Online' => __('Online')]"/>
                </div>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Status') }}</label>
                    <x-ui.select wire:model="form.status" class="mt-2" :options="['Active' => __('Active'), 'Inactive' => __('Inactive')]"/>
                </div>
                <x-ui.input wire:model="form.duration" :label="__('Duration')"/>
                <x-ui.input wire:model="form.fee" :label="__('Fee (₹)')" type="number" min="0"/>
            </div>

            @if ($errors->any())
                <p class="text-xs text-destructive">{{ $errors->first() }}</p>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('course-form')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save course') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete confirmation --}}
    <x-ui.modal name="course-delete" max-width="max-w-sm" :title="__('Delete course')" :description="__('This action cannot be undone.')">
        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('course-delete')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deleteSelected">{{ __('Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
