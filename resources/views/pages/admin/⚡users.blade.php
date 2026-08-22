<?php

use App\Concerns\WithSamplePagination;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Users')] class extends \Livewire\Component {
    use WithPagination;
    use WithSamplePagination;

    public string $search = '';
    public string $roleFilter = '';
    public string $statusFilter = '';

    public array $form = [
        'name' => '',
        'email' => '',
        'role' => 'Staff',
        'status' => 'Active',
    ];

    public ?int $editingId = null;
    public ?int $deleteId = null;

    public array $items = [
        ['id' => 1, 'name' => 'Admin', 'email' => 'admin@sntcssc.test', 'role' => 'Administrator', 'status' => 'Active', 'last_login' => 'Today, 09:12'],
        ['id' => 2, 'name' => 'Rakesh Prasad', 'email' => 'rakesh@sntcssc.test', 'role' => 'Admissions', 'status' => 'Active', 'last_login' => 'Today, 10:45'],
        ['id' => 3, 'name' => 'Sunita Mishra', 'email' => 'sunita@sntcssc.test', 'role' => 'Faculty', 'status' => 'Active', 'last_login' => 'Yesterday, 17:30'],
        ['id' => 4, 'name' => 'Alok Rathore', 'email' => 'alok@sntcssc.test', 'role' => 'Accountant', 'status' => 'Active', 'last_login' => '2 days ago'],
        ['id' => 5, 'name' => 'Meena Kumari', 'email' => 'meena@sntcssc.test', 'role' => 'Staff', 'status' => 'Inactive', 'last_login' => '3 weeks ago'],
        ['id' => 6, 'name' => 'Devendra Yadav', 'email' => 'devendra@sntcssc.test', 'role' => 'Faculty', 'status' => 'Active', 'last_login' => 'Today, 08:02'],
        ['id' => 7, 'name' => 'Farhan Ali', 'email' => 'farhan@sntcssc.test', 'role' => 'Staff', 'status' => 'Invited', 'last_login' => '—'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRoleFilter(): void
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
        $this->form = ['name' => '', 'email' => '', 'role' => 'Staff', 'status' => 'Active'];
        $this->dispatch('modal-open', name: 'user-form');
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = collect(collect($this->items)->firstWhere('id', $id))->only(array_keys($this->form))->all();
        $this->dispatch('modal-open', name: 'user-form');
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => 'required|string|min:3',
            'form.email' => 'required|email',
        ]);

        if ($this->editingId) {
            $this->items = collect($this->items)
                ->map(fn ($item) => $item['id'] === $this->editingId ? array_merge($item, $this->form) : $item)
                ->values()->all();
            Toast::dispatch($this, 'success', __('User updated.'));
        } else {
            array_unshift($this->items, array_merge([
                'id' => (int) (max(collect($this->items)->max('id') ?? 0) + 1),
                'last_login' => '—',
            ], $this->form));
            Toast::dispatch($this, 'success', __('User created — invitation email queued (design preview).'));
        }

        $this->dispatch('modal-close', name: 'user-form');
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
            $this->dispatch('modal-close', name: 'user-delete');
            Toast::dispatch($this, 'success', __('User deleted.'));
        }
    }

    #[Computed]
    public function users()
    {
        $filtered = collect($this->items)
            ->when($this->search, fn ($query) => $query->filter(fn ($item) => str_contains(strtolower($item['name'].' '.$item['email']), strtolower($this->search))))
            ->when($this->roleFilter, fn ($query) => $query->where('role', $this->roleFilter))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->values()
            ->all();

        return $this->paginateSample($filtered);
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Users') }}</h1>
            <span class="text-sm text-muted-foreground">({{ $this->users->total() }})</span>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.button size="sm" class="h-9" wire:click="create">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New user') }}
            </x-ui.button>

            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search users…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select wire:model.live="roleFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All roles') }}</option>
                @foreach (collect($this->items)->pluck('role')->unique()->sort()->values() as $role)
                    <option value="{{ $role }}">{{ $role }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (['Active', 'Inactive', 'Invited'] as $status)
                    <option value="{{ $status }}">{{ $status }}</option>
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
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('User') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Role') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Last login') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->users as $userItem)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="usr-{{ $userItem['id'] }}">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$userItem['name']" size="size-8 text-xs"/>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium truncate">{{ $userItem['name'] }}</p>
                                        <p class="text-xs text-muted-foreground truncate">{{ $userItem['email'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="match($userItem['role']) { 'Administrator' => 'violet', 'Faculty' => 'info', default => 'secondary' }">{{ $userItem['role'] }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="match($userItem['status']) { 'Active' => 'success', 'Invited' => 'warning', default => 'secondary' }">{{ $userItem['status'] }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground">{{ $userItem['last_login'] }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <x-ui.dropdown width="w-44" align="end">
                                    <x-slot:trigger>
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary transition-colors cursor-pointer" aria-label="{{ __('Actions') }}">
                                            <x-icon name="more-vertical" class="h-4 w-4 text-muted-foreground"/>
                                        </button>
                                    </x-slot:trigger>
                                    <x-ui.dropdown.item icon="pencil" wire:click="edit({{ $userItem['id'] }})">{{ __('Edit') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.separator/>
                                    <x-ui.dropdown.item icon="trash-2" danger wire:click="selectForDelete({{ $userItem['id'] }})" x-data x-on:click="$store.modals.open('user-delete')">{{ __('Delete') }}</x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="search-x" class="mx-auto h-8 w-8 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No users found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->users->links('partials.pagination') }}
    </div>

    {{-- Mobile cards --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->users as $userItem)
            <div class="rounded-lg border border-border bg-card p-4" wire:key="usr-m-{{ $userItem['id'] }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-3 min-w-0">
                        <x-ui.avatar :name="$userItem['name']" size="size-10 text-sm"/>
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate">{{ $userItem['name'] }}</p>
                            <p class="text-xs text-muted-foreground truncate">{{ $userItem['email'] }}</p>
                        </div>
                    </div>
                    <x-ui.badge :color="match($userItem['status']) { 'Active' => 'success', 'Invited' => 'warning', default => 'secondary' }">{{ $userItem['status'] }}</x-ui.badge>
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <x-ui.badge :color="match($userItem['role']) { 'Administrator' => 'violet', 'Faculty' => 'info', default => 'secondary' }">{{ $userItem['role'] }}</x-ui.badge>
                    <span class="text-xs text-muted-foreground">{{ $userItem['last_login'] }}</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <x-ui.button size="sm" variant="outline" class="flex-1" wire:click="edit({{ $userItem['id'] }})">{{ __('Edit') }}</x-ui.button>
                    <x-ui.button size="sm" variant="outline" class="text-destructive" wire:click="selectForDelete({{ $userItem['id'] }})" x-data x-on:click="$store.modals.open('user-delete')">{{ __('Delete') }}</x-ui.button>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground text-sm">{{ __('No users found') }}</div>
        @endforelse

        {{ $this->users->links('partials.pagination') }}
    </div>

    {{-- Form modal --}}
    <x-ui.modal name="user-form" max-width="max-w-md" :title="$editingId ? __('Edit user') : __('New user')" :description="__('User details and access role.')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input wire:model="form.name" :label="__('Name') .' *'" required/>
            <x-ui.input wire:model="form.email" :label="__('Email') .' *'" type="email" required/>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Role') }}</label>
                    <x-ui.select wire:model="form.role" class="mt-2" :options="['Administrator' => __('Administrator'), 'Faculty' => __('Faculty'), 'Accountant' => __('Accountant'), 'Admissions' => __('Admissions'), 'Staff' => __('Staff')]"/>
                </div>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Status') }}</label>
                    <x-ui.select wire:model="form.status" class="mt-2" :options="['Active' => __('Active'), 'Inactive' => __('Inactive'), 'Invited' => __('Invited')]"/>
                </div>
            </div>

            @if ($errors->any())
                <p class="text-xs text-destructive">{{ $errors->first() }}</p>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-form')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save user') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete confirmation --}}
    <x-ui.modal name="user-delete" max-width="max-w-sm" :title="__('Delete user')" :description="__('This action cannot be undone.')">
        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-delete')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deleteSelected">{{ __('Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
