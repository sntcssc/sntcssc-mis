<?php

use App\Concerns\WithSamplePagination;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Students')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $courseFilter = '';

    public array $form = [
        'name' => '',
        'email' => '',
        'roll_no' => '',
        'course' => '',
        'batch' => '',
        'mobile' => '',
        'status' => 'Active',
    ];

    public ?string $editingId = null;

    public array $items = [
        ['id' => 1, 'name' => 'Priya Sharma', 'email' => 'priya.sharma@example.com', 'roll_no' => 'SNT-2026-001', 'course' => 'Composite Course', 'batch' => 'B-2026-A', 'mobile' => '+91 98300 11223', 'status' => 'Active', 'joined' => '2026-01-12'],
        ['id' => 2, 'name' => 'Rahul Verma', 'email' => 'rahul.verma@example.com', 'roll_no' => 'SNT-2026-002', 'course' => 'Prelims Crash Course', 'batch' => 'PCC-Jun', 'mobile' => '+91 98300 44556', 'status' => 'Active', 'joined' => '2026-02-03'],
        ['id' => 3, 'name' => 'Ankit Singh', 'email' => 'ankit.singh@example.com', 'roll_no' => 'SNT-2026-003', 'course' => 'Mains Guidance Programme', 'batch' => 'MGP-01', 'mobile' => '+91 98300 77889', 'status' => 'Active', 'joined' => '2026-02-18'],
        ['id' => 4, 'name' => 'Sneha Patel', 'email' => 'sneha.patel@example.com', 'roll_no' => 'SNT-2026-004', 'course' => 'Interview Training Programme', 'batch' => 'ITP-01', 'mobile' => '+91 98300 99001', 'status' => 'Pending', 'joined' => '2026-03-05'],
        ['id' => 5, 'name' => 'Arjun Mehta', 'email' => 'arjun.mehta@example.com', 'roll_no' => 'SNT-2026-005', 'course' => 'Composite Course', 'batch' => 'B-2026-A', 'mobile' => '+91 98300 22334', 'status' => 'Active', 'joined' => '2026-03-11'],
        ['id' => 6, 'name' => 'Kavya Reddy', 'email' => 'kavya.reddy@example.com', 'roll_no' => 'SNT-2026-006', 'course' => 'Mock Interview Programme', 'batch' => 'MIP-02', 'mobile' => '+91 98300 55667', 'status' => 'Inactive', 'joined' => '2026-03-19'],
        ['id' => 7, 'name' => 'Deepak Kumar', 'email' => 'deepak.kumar@example.com', 'roll_no' => 'SNT-2026-007', 'course' => 'Prelims Crash Course', 'batch' => 'PCC-Jun', 'mobile' => '+91 98300 88990', 'status' => 'Active', 'joined' => '2026-04-02'],
        ['id' => 8, 'name' => 'Ishita Banerjee', 'email' => 'ishita.b@example.com', 'roll_no' => 'SNT-2026-008', 'course' => 'Composite Course', 'batch' => 'B-2026-B', 'mobile' => '+91 98300 33445', 'status' => 'Alumni', 'joined' => '2025-06-15'],
        ['id' => 9, 'name' => 'Vikram Yadav', 'email' => 'vikram.yadav@example.com', 'roll_no' => 'SNT-2026-009', 'course' => 'Mains Guidance Programme', 'batch' => 'MGP-01', 'mobile' => '+91 98300 66778', 'status' => 'Active', 'joined' => '2026-04-21'],
        ['id' => 10, 'name' => 'Neha Gupta', 'email' => 'neha.gupta@example.com', 'roll_no' => 'SNT-2026-010', 'course' => 'Weekend Test Series', 'batch' => 'WTS-01', 'mobile' => '+91 98300 11229', 'status' => 'Pending', 'joined' => '2026-05-08'],
        ['id' => 11, 'name' => 'Rohit Das', 'email' => 'rohit.das@example.com', 'roll_no' => 'SNT-2026-011', 'course' => 'Composite Course', 'batch' => 'B-2026-B', 'mobile' => '+91 98300 44557', 'status' => 'Active', 'joined' => '2026-05-14'],
        ['id' => 12, 'name' => 'Pooja Nair', 'email' => 'pooja.nair@example.com', 'roll_no' => 'SNT-2026-012', 'course' => 'Interview Training Programme', 'batch' => 'ITP-01', 'mobile' => '+91 98300 77880', 'status' => 'Active', 'joined' => '2026-06-01'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCourseFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '', 'email' => '', 'roll_no' => '', 'course' => '',
            'batch' => '', 'mobile' => '', 'status' => 'Active',
        ];

        $this->dispatch('modal-open', name: 'student-form');
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = collect($this->items)->firstWhere('id', $id) ?? $this->form;
        unset($this->form['id'], $this->form['joined']);

        $this->dispatch('modal-open', name: 'student-form');
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => 'required|string|min:3',
            'form.email' => 'required|email',
            'form.roll_no' => 'required|string',
        ]);

        if ($this->editingId) {
            $this->items = collect($this->items)
                ->map(fn ($item) => $item['id'] === $this->editingId
                    ? array_merge($item, $this->form)
                    : $item)
                ->values()
                ->all();

            Toast::dispatch($this, 'success', __('Student updated.'));
        } else {
            array_unshift($this->items, array_merge([
                'id' => (int) (max(collect($this->items)->max('id') ?? 0) + 1),
                'joined' => now()->toDateString(),
            ], $this->form));

            Toast::dispatch($this, 'success', __('Student created.'));
        }

        $this->dispatch('modal-close', name: 'student-form');
    }

    public function delete(int $id): void
    {
        $this->items = collect($this->items)->reject(fn ($item) => $item['id'] === $id)->values()->all();
        $this->dispatch('modal-close', name: 'student-delete');
        Toast::dispatch($this, 'success', __('Student deleted.'));
    }

    public ?int $deleteId = null;

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
    }

    public function deleteSelected(): void
    {
        if ($this->deleteId) {
            $this->delete($this->deleteId);
            $this->deleteId = null;
        }
    }

    #[Computed]
    public function students()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(function ($item) {
                return str_contains(strtolower($item['name'].' '.$item['email'].' '.$item['roll_no']), strtolower($this->search));
            }))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->courseFilter, fn ($query) => $query->where('course', $this->courseFilter))
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
    {{-- Page header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Students') }}</h1>
            <span class="text-sm text-muted-foreground">({{ $this->students->total() }})</span>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.button size="sm" class="h-9" x-data x-on:click="$store.modals.open('student-form'); $wire.create()">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New student') }}
            </x-ui.button>

            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search students…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select
                wire:model.live="statusFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All statuses') }}</option>
                @foreach (['Active', 'Pending', 'Inactive', 'Alumni'] as $status)
                    <option value="{{ $status }}">{{ $status }}</option>
                @endforeach
            </select>

            <select
                wire:model.live="courseFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All courses') }}</option>
                @foreach ($this->courses as $course)
                    <option value="{{ $course }}">{{ $course }}</option>
                @endforeach
            </select>

            <button type="button" wire:loading.attr="disabled" class="flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-secondary transition-colors cursor-pointer" title="{{ __('Refresh') }}">
                <x-icon name="refresh-cw" class="h-4 w-4 text-muted-foreground" wire:loading.delay.class="animate-spin"/>
            </button>
        </div>
    </div>

    {{-- Table (desktop) --}}
    <div class="hidden md:block rounded-lg border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Student') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Roll no') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Course') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Batch') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Mobile') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->students as $student)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="student-{{ $student['id'] }}">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$student['name']" size="size-8 text-xs"/>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium truncate">{{ $student['name'] }}</p>
                                        <p class="text-xs text-muted-foreground truncate">{{ $student['email'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-sm font-mono text-muted-foreground">{{ $student['roll_no'] }}</td>
                            <td class="px-4 py-3.5 text-sm">{{ $student['course'] }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $student['batch'] }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $student['mobile'] }}</td>
                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="match($student['status']) { 'Active' => 'success', 'Pending' => 'warning', 'Alumni' => 'violet', default => 'secondary' }">
                                    {{ $student['status'] }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <x-ui.dropdown width="w-40" align="end">
                                    <x-slot:trigger>
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary transition-colors cursor-pointer" aria-label="{{ __('Actions') }}">
                                            <x-icon name="more-vertical" class="h-4 w-4 text-muted-foreground"/>
                                        </button>
                                    </x-slot:trigger>
                                    <x-ui.dropdown.item icon="eye" wire:click="edit({{ $student['id'] }})">{{ __('View / Edit') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.separator/>
                                    <x-ui.dropdown.item icon="trash-2" danger x-data x-on:click="$store.modals.open('student-delete'); $wire.selectForDelete({{ $student['id'] }})">
                                        {{ __('Delete') }}
                                    </x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center">
                                <div class="flex flex-col items-center gap-2 text-muted-foreground">
                                    <x-icon name="search-x" class="h-8 w-8"/>
                                    <p class="text-sm font-medium">{{ __('No students found') }}</p>
                                    <p class="text-xs">{{ __('Try adjusting your search or filters.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->students->links('partials.pagination') }}
    </div>

    {{-- Cards (mobile) --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->students as $student)
            <div class="rounded-lg border border-border bg-card p-4" wire:key="student-m-{{ $student['id'] }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <x-ui.avatar :name="$student['name']" size="size-10 text-sm"/>
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate">{{ $student['name'] }}</p>
                            <p class="text-xs text-muted-foreground truncate">{{ $student['course'] }}</p>
                        </div>
                    </div>
                    <x-ui.badge :color="match($student['status']) { 'Active' => 'success', 'Pending' => 'warning', 'Alumni' => 'violet', default => 'secondary' }">
                        {{ $student['status'] }}
                    </x-ui.badge>
                </div>

                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground uppercase tracking-wide">{{ __('Batch') }}</p>
                        <p class="text-xs font-semibold mt-0.5">{{ $student['batch'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground uppercase tracking-wide">{{ __('Roll no') }}</p>
                        <p class="text-xs font-semibold mt-0.5 font-mono">{{ $student['roll_no'] }}</p>
                    </div>
                    <div class="rounded-md bg-secondary/50 py-2">
                        <p class="text-[10px] text-muted-foreground uppercase tracking-wide">{{ __('Joined') }}</p>
                        <p class="text-xs font-semibold mt-0.5">{{ \Carbon\Carbon::parse($student['joined'])->format('d M Y') }}</p>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-2">
                    <x-ui.button size="sm" variant="outline" class="flex-1" wire:click="edit({{ $student['id'] }})">{{ __('Edit') }}</x-ui.button>
                    <x-ui.button size="sm" variant="outline" class="text-destructive" x-data x-on:click="$store.modals.open('student-delete'); $wire.selectForDelete({{ $student['id'] }})">
                        {{ __('Delete') }}
                    </x-ui.button>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground">
                <p class="text-sm font-medium">{{ __('No students found') }}</p>
            </div>
        @endforelse

        {{ $this->students->links('partials.pagination') }}
    </div>

    {{-- Create / Edit modal --}}
    <x-ui.modal name="student-form" :maxWidth="'max-w-lg'" :title="$editingId ? __('Edit student') : __('New student')" :description="__('Student details and enrollment info.')">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input wire:model="form.name" :label="__('Full name') .' *'" required/>
                <x-ui.input wire:model="form.roll_no" :label="__('Roll no') .' *'" required/>
                <x-ui.input wire:model="form.email" :label="__('Email') .' *'" type="email" required/>
                <x-ui.input wire:model="form.mobile" :label="__('Mobile')"/>
                <x-ui.input wire:model="form.course" :label="__('Course')"/>
                <x-ui.input wire:model="form.batch" :label="__('Batch')"/>
                <div class="sm:col-span-2">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Status') }}</label>
                    <x-ui.select wire:model="form.status" class="mt-2" :options="['Active' => __('Active'), 'Pending' => __('Pending'), 'Inactive' => __('Inactive'), 'Alumni' => __('Alumni')]" size="default"/>
                </div>
            </div>

            @if ($errors->any())
                <p class="text-xs text-destructive">{{ $errors->first() }}</p>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save student') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete confirmation --}}
    <x-ui.modal name="student-delete" max-width="max-w-sm" :title="__('Delete student')" :description="__('This action cannot be undone.')">
        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-delete')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deleteSelected">{{ __('Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
