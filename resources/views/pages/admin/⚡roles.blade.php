<?php

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogService;
use App\Services\RbacService;
use App\Services\RolePermissionExportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Roles & Permissions Management')] class extends Component {
    #[Url(as: 'q')]
    public string $search = '';

    public ?int $activeRoleId = null;

    // Role Form (Create / Edit)
    public ?int $editingRoleId = null;
    public array $roleForm = [
        'name' => '',
        'description' => '',
        'color' => 'emerald',
    ];

    // Clone Role Modal
    public ?int $cloneRoleId = null;
    public string $cloneRoleName = '';
    public string $cloneRoleDescription = '';

    // Delete Role Confirmation
    public ?int $deleteRoleId = null;

    public function mount(): void
    {
        // Default select the first role
        $first = Role::orderBy('id')->first();
        if ($first) {
            $this->activeRoleId = $first->id;
        }
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->with(['permissions'])
            ->withCount('users')
            ->when($this->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function activeRole(): ?Role
    {
        if (! $this->activeRoleId) {
            return $this->roles->first();
        }

        return Role::with(['permissions', 'users'])->find($this->activeRoleId);
    }

    #[Computed]
    public function permissionModules()
    {
        return Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');
    }

    public function selectRole(int $roleId): void
    {
        $this->activeRoleId = $roleId;
    }

    public function togglePermission(string $permissionName): void
    {
        $role = $this->activeRole;
        if (! $role) {
            return;
        }

        if ($role->name === 'Super Administrator' && $permissionName === 'audit.view') {
            Toast::dispatch($this, 'info', __('Super Administrator must retain audit permissions.'));
        }

        if ($role->hasPermissionTo($permissionName, 'web')) {
            $role->revokePermissionTo($permissionName);
            AuditLogService::log('role_permission_revoked', "Revoked permission '{$permissionName}' from role '{$role->name}'.", $role);
            Toast::dispatch($this, 'info', __("Revoked ':perm' from :role.", ['perm' => $permissionName, 'role' => $role->name]));
        } else {
            $role->givePermissionTo($permissionName);
            AuditLogService::log('role_permission_granted', "Granted permission '{$permissionName}' to role '{$role->name}'.", $role);
            Toast::dispatch($this, 'success', __("Granted ':perm' to :role.", ['perm' => $permissionName, 'role' => $role->name]));
        }

        $this->dispatch('$refresh');
    }

    public function toggleAllForModule(string $module, bool $enable): void
    {
        $role = $this->activeRole;
        if (! $role) {
            return;
        }

        $perms = Permission::where('module', $module)->pluck('name')->all();

        if ($enable) {
            $role->givePermissionTo($perms);
            AuditLogService::log('role_module_permissions_granted', "Granted all '{$module}' permissions to role '{$role->name}'.", $role);
            Toast::dispatch($this, 'success', __("All ':module' permissions granted to :role.", ['module' => $module, 'role' => $role->name]));
        } else {
            $role->revokePermissionTo($perms);
            AuditLogService::log('role_module_permissions_revoked', "Revoked all '{$module}' permissions from role '{$role->name}'.", $role);
            Toast::dispatch($this, 'info', __("All ':module' permissions revoked from :role.", ['module' => $module, 'role' => $role->name]));
        }

        $this->dispatch('$refresh');
    }

    public function grantAllPermissions(): void
    {
        $role = $this->activeRole;
        if (! $role) {
            return;
        }

        $all = Permission::all();
        $role->syncPermissions($all);
        AuditLogService::log('role_all_permissions_granted', "Granted all system permissions to role '{$role->name}'.", $role);
        Toast::dispatch($this, 'success', __("All system permissions granted to :role.", ['role' => $role->name]));
    }

    public function revokeAllPermissions(): void
    {
        $role = $this->activeRole;
        if (! $role) {
            return;
        }

        if ($role->is_system && $role->name === 'Super Administrator') {
            Toast::dispatch($this, 'error', __('Cannot revoke all permissions from Super Administrator.'));

            return;
        }

        $role->syncPermissions([]);
        AuditLogService::log('role_all_permissions_revoked', "Revoked all permissions from role '{$role->name}'.", $role);
        Toast::dispatch($this, 'warning', __("All permissions revoked from :role.", ['role' => $role->name]));
    }

    // Role CRUD Operations
    public function createRoleModal(): void
    {
        $this->resetValidation();
        $this->editingRoleId = null;
        $this->roleForm = [
            'name' => '',
            'description' => '',
            'color' => 'emerald',
        ];
        $this->dispatch('modal-open', name: 'role-form-modal');
    }

    public function editRoleModal(int $id): void
    {
        $this->resetValidation();
        $role = Role::findOrFail($id);
        $this->editingRoleId = $id;
        $this->roleForm = [
            'name' => $role->name,
            'description' => $role->description ?? '',
            'color' => $role->color ?? 'emerald',
        ];
        $this->dispatch('modal-open', name: 'role-form-modal');
    }

    public function saveRole(): void
    {
        $isEdit = ! is_null($this->editingRoleId);
        $role = $isEdit ? Role::findOrFail($this->editingRoleId) : null;

        $rules = [
            'roleForm.description' => ['nullable', 'string', 'max:500'],
            'roleForm.color' => ['required', 'string', 'max:30'],
        ];

        try {
            $this->validate($rules, [
                'roleForm.name.required' => __('Role name is required.'),
                'roleForm.name.unique' => __('A role with this name already exists.'),
                'roleForm.color.required' => __('Please select a badge color.'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Toast::dispatch($this, 'error', __('Please fill in all required fields properly.'));

            throw $e;
        }

        if ($isEdit) {
            RbacService::updateRole($role, [
                'name' => (! $role->is_system) ? $this->roleForm['name'] : $role->name,
                'description' => $this->roleForm['description'],
                'color' => $this->roleForm['color'],
            ]);
            Toast::dispatch($this, 'success', __("Role ':name' updated successfully.", ['name' => $role->name]));
        } else {
            $created = RbacService::createRole([
                'name' => $this->roleForm['name'],
                'description' => $this->roleForm['description'],
                'color' => $this->roleForm['color'],
            ]);
            $this->activeRoleId = $created->id;
            Toast::dispatch($this, 'success', __("Role ':name' created successfully.", ['name' => $created->name]));
        }

        $this->dispatch('modal-close', name: 'role-form-modal');
    }

    public function openCloneModal(int $id): void
    {
        $source = Role::findOrFail($id);
        $this->cloneRoleId = $id;
        $this->cloneRoleName = $source->name.' (Copy)';
        $this->cloneRoleDescription = 'Copy of '.$source->name.' role permissions';
        $this->dispatch('modal-open', name: 'clone-role-modal');
    }

    public function cloneRole(): void
    {
        try {
            $this->validate([
                'cloneRoleName' => ['required', 'string', 'max:100', 'unique:roles,name'],
                'cloneRoleDescription' => ['nullable', 'string', 'max:500'],
            ], [
                'cloneRoleName.required' => __('Cloned role name is required.'),
                'cloneRoleName.unique' => __('A role with this name already exists.'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Toast::dispatch($this, 'error', __('Please fill in all required fields properly.'));

            throw $e;
        }

        $source = Role::findOrFail($this->cloneRoleId);
        $cloned = RbacService::cloneRole($source, $this->cloneRoleName, $this->cloneRoleDescription);
        $this->activeRoleId = $cloned->id;
        $this->cloneRoleId = null;
        $this->dispatch('modal-close', name: 'clone-role-modal');
        Toast::dispatch($this, 'success', __("Role ':name' created from :source.", ['name' => $cloned->name, 'source' => $source->name]));
    }

    public function openDeleteModal(int $id): void
    {
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->is_system) {
            Toast::dispatch($this, 'error', __('System protected roles cannot be deleted.'));

            return;
        }

        if ($role->users_count > 0) {
            Toast::dispatch($this, 'error', __("Cannot delete role ':name' because :count users are assigned to it.", ['name' => $role->name, 'count' => $role->users_count]));

            return;
        }

        $this->deleteRoleId = $id;
        $this->dispatch('modal-open', name: 'delete-role-modal');
    }

    public function deleteRole(): void
    {
        if ($this->deleteRoleId) {
            $role = Role::findOrFail($this->deleteRoleId);
            RbacService::deleteRole($role);
            $this->deleteRoleId = null;
            $this->activeRoleId = Role::orderBy('id')->value('id');
            $this->dispatch('modal-close', name: 'delete-role-modal');
            Toast::dispatch($this, 'success', __('Role deleted successfully.'));
        }
    }

    // Exports
    public function exportExcel(): BinaryFileResponse
    {
        return RolePermissionExportService::exportRolesExcel();
    }

    public function exportCsv(): StreamedResponse
    {
        return RolePermissionExportService::exportRolesCsv();
    }

    public function exportPdf(): Response
    {
        return RolePermissionExportService::exportRolesPdf();
    }
}; ?>

<div class="space-y-4 sm:space-y-6 max-w-7xl">
    {{-- Page Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Roles & Permissions Matrix') }}</h1>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    {{ $this->roles->count() }} {{ __('roles') }}
                </span>
            </div>
            <p class="text-xs text-muted-foreground mt-1">
                {{ __('Dynamic granular Role-Based Access Control (RBAC) matrix for module security and permission assignments.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button size="sm" class="h-9 gap-1.5" wire:click="createRoleModal">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New Custom Role') }}
            </x-ui.button>

            {{-- Export Dropdown --}}
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export Matrix') }}
                        <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground ml-0.5"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="exportExcel">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-text" wire:click="exportCsv">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="exportPdf">{{ __('Export Matrix PDF') }}</x-ui.dropdown.item>
            </x-ui.dropdown>
        </div>
    </div>

    {{-- Grid Layout: Split Roles List + Permission Matrix --}}
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-4">
        {{-- Left Pane: Roles Selector (4 Cols) --}}
        <div class="xl:col-span-4 space-y-3">
            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search roles…') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <div class="space-y-2">
                @forelse ($this->roles as $role)
                    <div
                        wire:key="role-card-{{ $role->id }}"
                        class="rounded-xl border transition-all cursor-pointer p-3.5 {{ $activeRoleId === $role->id ? 'border-primary bg-primary/5 shadow-xs ring-1 ring-primary/30' : 'border-border bg-card hover:bg-secondary/30' }}"
                        wire:click="selectRole({{ $role->id }})"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-{{ $role->color }}-500/10 text-{{ $role->color }}-600 dark:text-{{ $role->color }}-400">
                                    <x-icon name="shield" class="h-4 w-4"/>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <p class="text-sm font-semibold truncate text-foreground">{{ $role->name }}</p>
                                        @if ($role->is_system)
                                            <span class="inline-flex items-center px-1.5 py-0.2 text-[9px] font-bold rounded bg-secondary text-secondary-foreground uppercase">
                                                {{ __('System') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[11px] text-muted-foreground mt-0.5">
                                        {{ $role->users_count }} {{ __('assigned users') }} &bull; {{ $role->permissions->count() }} {{ __('perms') }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    wire:click.stop="editRoleModal({{ $role->id }})"
                                    class="p-1 rounded text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
                                    title="{{ __('Edit Role Metadata') }}"
                                >
                                    <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                </button>
                                <button
                                    type="button"
                                    wire:click.stop="openCloneModal({{ $role->id }})"
                                    class="p-1 rounded text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
                                    title="{{ __('Clone Role') }}"
                                >
                                    <x-icon name="copy" class="h-3.5 w-3.5"/>
                                </button>
                                @if (! $role->is_system)
                                    <button
                                        type="button"
                                        wire:click.stop="openDeleteModal({{ $role->id }})"
                                        class="p-1 rounded text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
                                        title="{{ __('Delete Role') }}"
                                    >
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($role->description)
                            <p class="mt-2 text-xs text-muted-foreground line-clamp-2 leading-relaxed">
                                {{ $role->description }}
                            </p>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-border bg-card p-6 text-center text-xs text-muted-foreground">
                        {{ __('No roles matching search.') }}
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Right Pane: Dynamic Permission Matrix (8 Cols) --}}
        <div class="xl:col-span-8 space-y-4">
            @if ($this->activeRole)
                {{-- Active Role Header Card --}}
                <div class="p-4 rounded-xl border border-border bg-card shadow-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-{{ $this->activeRole->color }}-500/10 text-{{ $this->activeRole->color }}-600">
                            <x-icon name="shield-check" class="h-5 w-5"/>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-base font-bold text-foreground">{{ $this->activeRole->name }}</h2>
                                <x-ui.badge :color="$this->activeRole->color" class="text-[10px]">
                                    {{ $this->activeRole->permissions->count() }} {{ __('Active Privileges') }}
                                </x-ui.badge>
                            </div>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ $this->activeRole->description ?: __('Configure granted system capabilities and module controls.') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button size="sm" variant="outline" class="h-8 text-xs gap-1" wire:click="grantAllPermissions">
                            <x-icon name="check-check" class="h-3.5 w-3.5 text-emerald-500"/>
                            {{ __('Grant All') }}
                        </x-ui.button>
                        <x-ui.button size="sm" variant="outline" class="h-8 text-xs gap-1 text-destructive hover:bg-destructive/10" wire:click="revokeAllPermissions">
                            <x-icon name="x" class="h-3.5 w-3.5"/>
                            {{ __('Revoke All') }}
                        </x-ui.button>
                    </div>
                </div>

                {{-- Module Permission Groups --}}
                <div class="space-y-3.5">
                    @php($grantedPermNames = $this->activeRole->permissions->pluck('name')->all())

                    @foreach ($this->permissionModules as $module => $permissions)
                        @php($modulePermNames = $permissions->pluck('name')->all())
                        @php($allModuleGranted = count(array_intersect($modulePermNames, $grantedPermNames)) === count($modulePermNames))

                        <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden" wire:key="perm-mod-{{ $module }}">
                            {{-- Module Header --}}
                            <div class="flex items-center justify-between px-4 py-3 bg-muted/30 border-b border-border">
                                <div class="flex items-center gap-2">
                                    <x-icon name="folder-lock" class="h-4 w-4 text-primary"/>
                                    <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">{{ $module }}</h3>
                                    <span class="text-[11px] text-muted-foreground">({{ count(array_intersect($modulePermNames, $grantedPermNames)) }}/{{ count($modulePermNames) }})</span>
                                </div>

                                <div class="flex items-center gap-2">
                                    @if ($allModuleGranted)
                                        <button
                                            type="button"
                                            wire:click="toggleAllForModule('{{ $module }}', false)"
                                            class="text-[11px] text-muted-foreground hover:text-destructive transition-colors font-medium cursor-pointer"
                                        >
                                            {{ __('Deselect All') }}
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="toggleAllForModule('{{ $module }}', true)"
                                            class="text-[11px] text-primary hover:underline transition-colors font-medium cursor-pointer"
                                        >
                                            {{ __('Select All') }}
                                        </button>
                                    @endif
                                </div>
                            </div>

                            {{-- Permission switches grid --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4">
                                @foreach ($permissions as $perm)
                                    @php($isGranted = in_array($perm->name, $grantedPermNames, true))
                                    <div
                                        wire:key="perm-item-{{ $perm->id }}"
                                        wire:click="togglePermission('{{ $perm->name }}')"
                                        class="flex items-start justify-between gap-3 p-2.5 rounded-lg border transition-all cursor-pointer {{ $isGranted ? 'border-primary/30 bg-primary/5' : 'border-border/60 bg-background/50 hover:bg-secondary/40' }}"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-mono text-xs font-semibold {{ $isGranted ? 'text-primary' : 'text-foreground' }}">{{ $perm->name }}</span>
                                            </div>
                                            <p class="text-[11px] text-muted-foreground leading-relaxed mt-0.5">{{ $perm->description }}</p>
                                        </div>

                                        <div class="pt-0.5">
                                            <div class="relative inline-flex h-4 w-8 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out {{ $isGranted ? 'bg-primary' : 'bg-muted' }}">
                                                <span class="inline-block h-3 w-3 transform rounded-full bg-white transition duration-200 ease-in-out {{ $isGranted ? 'translate-x-4' : 'translate-x-0' }}"></span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Role Create / Edit Modal --}}
    <x-ui.modal name="role-form-modal" max-width="max-w-md" :title="$editingRoleId ? __('Edit Role Definition') : __('Create New Custom Role')" :description="__('Configure role identifier, display styling, and operational scope.')">
        <form wire:submit="saveRole" class="space-y-4">
            <x-ui.input wire:model="roleForm.name" :label="__('Role Name') .' *'" placeholder="e.g. Department Head" required :error="$errors->first('roleForm.name')"/>

            <x-ui.textarea wire:model="roleForm.description" :label="__('Role Description')" placeholder="Briefly describe what this role enables users to do"/>

            <div>
                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Badge & Theme Accent Color') }} *</label>
                <select
                    wire:model="roleForm.color"
                    class="mt-1 h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                >
                    <option value="emerald">{{ __('Emerald (Success Green)') }}</option>
                    <option value="violet">{{ __('Violet (Royal Purple)') }}</option>
                    <option value="sky">{{ __('Sky Blue') }}</option>
                    <option value="blue">{{ __('Indigo Blue') }}</option>
                    <option value="amber">{{ __('Amber (Gold)') }}</option>
                    <option value="rose">{{ __('Rose (Crimson)') }}</option>
                    <option value="zinc">{{ __('Zinc (Neutral Slate)') }}</option>
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('role-form-modal')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ $editingRoleId ? __('Save Changes') : __('Create Role') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Clone Role Modal --}}
    <x-ui.modal name="clone-role-modal" max-width="max-w-md" :title="__('Clone Existing Role')" :description="__('Duplicate all permissions from selected role into a new custom role.')">
        <form wire:submit="cloneRole" class="space-y-4">
            <x-ui.input wire:model="cloneRoleName" :label="__('New Cloned Role Name') .' *'" required :error="$errors->first('cloneRoleName')"/>
            <x-ui.textarea wire:model="cloneRoleDescription" :label="__('Description')"/>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('clone-role-modal')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Duplicate Role') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete Role Modal --}}
    <x-ui.modal name="delete-role-modal" max-width="max-w-sm" :title="__('Delete Role')" :description="__('Are you sure you want to remove this custom role? This action cannot be undone.')">
        <div class="flex justify-end gap-2 pt-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('delete-role-modal')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deleteRole">{{ __('Delete Role') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
