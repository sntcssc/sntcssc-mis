<?php

use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Roles')] class extends Component {
    public string $search = '';

    public ?string $activeRole = 'Administrator';

    public array $roles = [
        ['name' => 'Administrator', 'description' => 'Full access to all modules and system settings', 'users' => 1, 'color' => 'violet'],
        ['name' => 'Admissions', 'description' => 'Manage applications, admission tests and selection lists', 'users' => 1, 'color' => 'info'],
        ['name' => 'Faculty', 'description' => 'View batches, take attendance and record test marks', 'users' => 2, 'color' => 'emerald'],
        ['name' => 'Accountant', 'description' => 'Manage fee collections, installments and refunds', 'users' => 1, 'color' => 'amber'],
        ['name' => 'Staff', 'description' => 'Basic access to student records and front-desk operations', 'users' => 2, 'color' => 'secondary'],
    ];

    public array $permissions = [
        'Dashboard' => ['View dashboard', 'View reports'],
        'Students' => ['View students', 'Create students', 'Edit students', 'Delete students'],
        'Admissions' => ['View applications', 'Review applications', 'Manage selection lists'],
        'Academics' => ['View courses', 'Manage courses', 'View batches', 'Manage batches', 'Record test marks'],
        'Fees' => ['View collections', 'Collect payments', 'Issue refunds'],
        'System' => ['Manage users', 'Manage roles & permissions', 'View audit logs', 'Configure settings'],
    ];

    /** role => [allowed permission keys flattened] */
    public array $rolePermissions = [
        'Administrator' => [],
        'Admissions' => ['View dashboard', 'View students', 'View applications', 'Review applications', 'Manage selection lists', 'View courses', 'View batches', 'Record test marks'],
        'Faculty' => ['View dashboard', 'View students', 'View courses', 'View batches', 'Record test marks'],
        'Accountant' => ['View dashboard', 'View students', 'View collections', 'Collect payments', 'Issue refunds'],
        'Staff' => ['View dashboard', 'View students', 'View courses', 'View batches'],
    ];

    public function mount(): void
    {
        // Administrator implicitly has every permission.
        $this->rolePermissions['Administrator'] = collect($this->permissions)->flatten(1)->values()->all();
    }

    public function selectRole(string $role): void
    {
        $this->activeRole = $role;
    }

    public function togglePermission(string $permission): void
    {
        $role = $this->activeRole;
        $granted = $this->rolePermissions[$role] ?? [];

        if (in_array($permission, $granted, true)) {
            $this->rolePermissions[$role] = array_values(array_diff($granted, [$permission]));
        } else {
            $this->rolePermissions[$role][] = $permission;
        }

        Toast::dispatch($this, 'success', __('Permissions updated for :role.', ['role' => $role]));
    }

    #[Computed]
    public function filteredRoles()
    {
        return collect($this->roles)
            ->when($this->search, fn ($query) => $query->filter(fn ($role) => str_contains(strtolower($role['name'].' '.$role['description']), strtolower($this->search))))
            ->values()
            ->all();
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Roles & Permissions') }}</h1>
            <p class="text-sm text-muted-foreground mt-1">{{ __('Design preview — will be wired to spatie/laravel-permission in the RBAC phase.') }}</p>
        </div>

        <div class="relative">
            <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search roles…') }}"
                class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            />
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 sm:gap-4">
        {{-- Roles list --}}
        <div class="xl:col-span-1 space-y-2">
            @forelse ($this->filteredRoles as $role)
                <button
                    type="button"
                    wire:click="selectRole('{{ $role['name'] }}')"
                    class="w-full text-left rounded-xl border p-4 transition-colors cursor-pointer {{ $activeRole === $role['name'] ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-border bg-card hover:bg-secondary/30' }}"
                    wire:key="role-{{ $role['name'] }}"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10">
                                <x-icon name="shield" class="h-4 w-4 text-emerald-500"/>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium truncate">{{ $role['name'] }}</p>
                                <p class="text-xs text-muted-foreground truncate">{{ $role['users'] }} {{ __('users') }}</p>
                            </div>
                        </div>
                        @if ($activeRole === $role['name'])
                            <x-icon name="check" class="h-4 w-4 text-emerald-500 shrink-0"/>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground line-clamp-2">{{ $role['description'] }}</p>
                </button>
            @empty
                <div class="rounded-xl border border-border bg-card p-8 text-center text-muted-foreground text-sm">{{ __('No roles found') }}</div>
            @endforelse
        </div>

        {{-- Permission groups --}}
        <div class="xl:col-span-2 space-y-3 sm:space-y-4">
            @php($granted = $rolePermissions[$activeRole] ?? [])
            @foreach ($permissions as $group => $groupPermissions)
                <div class="rounded-xl border border-border bg-card p-4 sm:p-5" wire:key="perm-{{ $group }}">
                    <h3 class="text-sm font-semibold mb-3">{{ $group }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($groupPermissions as $permission)
                            <x-ui.switch
                                :label="$permission"
                                :checked="in_array($permission, $granted)"
                                wire:click="togglePermission('{{ $permission }}')"
                            />
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
