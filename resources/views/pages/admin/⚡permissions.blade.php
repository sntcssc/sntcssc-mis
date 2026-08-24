<?php

use App\Models\Permission;
use App\Services\AuditLogService;
use App\Services\RbacService;
use App\Services\RolePermissionExportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Permissions Management')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'module')]
    public string $moduleFilter = '';

    public int $perPage = 15;

    // Form Modal state
    public ?int $editingPermissionId = null;
    public array $form = [
        'name' => '',
        'module' => 'General',
        'description' => '',
    ];

    // Delete confirmation
    public ?int $deletePermissionId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedModuleFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'moduleFilter']);
        $this->resetPage();
    }

    #[Computed]
    public function modules()
    {
        return Permission::query()->distinct()->pluck('module')->sort()->values();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Permission::count(),
            'modules' => Permission::distinct('module')->count('module'),
        ];
    }

    #[Computed]
    public function permissions()
    {
        return Permission::query()
            ->withCount('roles')
            ->when($this->search, function (Builder $query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%");
            })
            ->when($this->moduleFilter, fn ($q, $m) => $q->where('module', $m))
            ->orderBy('module')
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editingPermissionId = null;
        $this->form = [
            'name' => '',
            'module' => 'General',
            'description' => '',
        ];
        $this->dispatch('modal-open', name: 'permission-form-modal');
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $perm = Permission::findOrFail($id);
        $this->editingPermissionId = $id;
        $this->form = [
            'name' => $perm->name,
            'module' => $perm->module ?? 'General',
            'description' => $perm->description ?? '',
        ];
        $this->dispatch('modal-open', name: 'permission-form-modal');
    }

    public function save(): void
    {
        $isEdit = ! is_null($this->editingPermissionId);

        try {
            $this->validate([
                'form.name' => [
                    'required',
                    'string',
                    'max:100',
                    $isEdit
                        ? Rule::unique('permissions', 'name')->ignore($this->editingPermissionId)
                        : Rule::unique('permissions', 'name'),
                ],
                'form.module' => ['required', 'string', 'max:50'],
                'form.description' => ['nullable', 'string', 'max:500'],
            ], [
                'form.name.required' => __('Permission key name is required.'),
                'form.name.unique' => __('A permission with this key name already exists.'),
                'form.module.required' => __('Module classification is required.'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Toast::dispatch($this, 'error', __('Please fill in all required fields properly.'));

            throw $e;
        }

        if ($isEdit) {
            $perm = Permission::findOrFail($this->editingPermissionId);
            RbacService::updatePermission($perm, $this->form['name'], $this->form['module'], $this->form['description']);
            Toast::dispatch($this, 'success', __("Permission ':name' updated.", ['name' => $perm->name]));
        } else {
            $perm = RbacService::createPermission($this->form['name'], $this->form['module'], $this->form['description']);
            Toast::dispatch($this, 'success', __("Permission ':name' created.", ['name' => $perm->name]));
        }

        $this->dispatch('modal-close', name: 'permission-form-modal');
    }

    public function openDeleteModal(int $id): void
    {
        $this->deletePermissionId = $id;
        $this->dispatch('modal-open', name: 'permission-delete-modal');
    }

    public function deletePermission(): void
    {
        if ($this->deletePermissionId) {
            $perm = Permission::findOrFail($this->deletePermissionId);
            RbacService::deletePermission($perm);
            $this->deletePermissionId = null;
            $this->dispatch('modal-close', name: 'permission-delete-modal');
            Toast::dispatch($this, 'success', __('Permission deleted successfully.'));
        }
    }

    public function exportCsv(): StreamedResponse
    {
        AuditLogService::log('permissions_exported_csv', 'Exported permissions catalog to CSV.');

        return RolePermissionExportService::exportPermissionsCsv();
    }
}; ?>

<div class="space-y-4 sm:space-y-6 max-w-7xl">
    {{-- Page Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Permissions Catalog') }}</h1>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary">
                    {{ $this->stats['total'] }} {{ __('permissions') }}
                </span>
            </div>
            <p class="text-xs text-muted-foreground mt-1">
                {{ __('Granular capability tokens assigned across system modules and custom roles.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button size="sm" class="h-9 gap-1.5" wire:click="create">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New Permission Key') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5" wire:click="exportCsv">
                <x-icon name="download" class="h-3.5 w-3.5"/>
                {{ __('Export CSV') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="rounded-xl border border-border bg-card p-4 space-y-3 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="relative lg:col-span-2">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search permission key, module, description…') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <select
                wire:model.live="moduleFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring"
            >
                <option value="">{{ __('All Modules') }} ({{ count($this->modules) }})</option>
                @foreach ($this->modules as $mod)
                    <option value="{{ $mod }}">{{ $mod }}</option>
                @endforeach
            </select>

            <select
                wire:model.live="perPage"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring"
            >
                <option value="15">15 {{ __('per page') }}</option>
                <option value="30">30 {{ __('per page') }}</option>
                <option value="50">50 {{ __('per page') }}</option>
                <option value="100">100 {{ __('per page') }}</option>
            </select>
        </div>

        @if ($search || $moduleFilter)
            <div class="flex items-center justify-between pt-2 border-t border-border">
                <span class="text-xs text-muted-foreground">{{ __('Filtered view active') }}</span>
                <button type="button" wire:click="resetFilters" class="text-xs text-primary hover:underline font-medium cursor-pointer">
                    {{ __('Clear filters') }}
                </button>
            </div>
        @endif
    </div>

    {{-- Permissions Table --}}
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[750px]">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-muted-foreground">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest">{{ __('Module') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest">{{ __('Permission Key') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest">{{ __('Description') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest">{{ __('Assigned Roles') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->permissions as $perm)
                        <tr class="hover:bg-secondary/30 transition-colors" wire:key="perm-row-{{ $perm->id }}">
                            <td class="px-4 py-3.5 text-xs font-semibold text-foreground whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-secondary text-secondary-foreground">
                                    {{ $perm->module }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 text-xs font-mono font-bold text-primary whitespace-nowrap">
                                {{ $perm->name }}
                            </td>

                            <td class="px-4 py-3.5 text-xs text-muted-foreground max-w-md">
                                {{ $perm->description ?: '—' }}
                            </td>

                            <td class="px-4 py-3.5 text-xs whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600">
                                    {{ $perm->roles_count }} {{ __('roles') }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $perm->id }})"
                                        class="flex h-7 w-7 items-center justify-center rounded text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
                                        title="{{ __('Edit') }}"
                                    >
                                        <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="openDeleteModal({{ $perm->id }})"
                                        class="flex h-7 w-7 items-center justify-center rounded text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
                                        title="{{ __('Delete') }}"
                                    >
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-muted-foreground text-xs">
                                {{ __('No permissions match the search criteria.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->permissions->hasPages())
            <div class="p-4 border-t border-border">
                {{ $this->permissions->links() }}
            </div>
        @endif
    </div>

    {{-- Create / Edit Modal --}}
    <x-ui.modal name="permission-form-modal" max-width="max-w-md" :title="$editingPermissionId ? __('Edit Permission') : __('Create Dynamic Permission')" :description="__('Unique authorization capability key.')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input wire:model="form.name" :label="__('Permission Key (e.g. reports.export_financial)') .' *'" required :error="$errors->first('form.name')"/>

            <div>
                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Module / Area') }} *</label>
                <input
                    type="text"
                    wire:model="form.module"
                    list="modules-datalist"
                    placeholder="e.g. Users, Academics, Reports & BI"
                    class="mt-1 h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                    required
                />
                <datalist id="modules-datalist">
                    @foreach ($this->modules as $mod)
                        <option value="{{ $mod }}"></option>
                    @endforeach
                </datalist>
            </div>

            <x-ui.textarea wire:model="form.description" :label="__('Capability Description')" placeholder="Explain what user actions this permission allows"/>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('permission-form-modal')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ $editingPermissionId ? __('Save Changes') : __('Create Permission') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete Modal --}}
    <x-ui.modal name="permission-delete-modal" max-width="max-w-sm" :title="__('Delete Permission Key')" :description="__('Are you sure? Removing this permission key will unassign it from all roles.')">
        <div class="flex justify-end gap-2 pt-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('permission-delete-modal')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deletePermission">{{ __('Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
