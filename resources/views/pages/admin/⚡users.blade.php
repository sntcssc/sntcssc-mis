<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RbacService;
use App\Services\UserExportImportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Users Management')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'role')]
    public string $roleFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'trashed')]
    public string $trashedFilter = 'without_trashed';

    #[Url(as: 'gender')]
    public string $genderFilter = '';

    #[Url(as: 'id_type')]
    public string $idTypeFilter = '';

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';
    public int $perPage = 10;

    // Selected items for bulk operations
    public array $selectedUserIds = [];
    public bool $selectAll = false;

    // Form Modal state
    public ?int $editingUserId = null;
    public string $activeFormTab = 'basic'; // basic, identity, security

    public array $form = [
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'whatsapp_no' => '',
        'gender' => '',
        'dob' => '',
        'tenth_roll' => '',
        'id_type' => 'aadhaar',
        'id_number' => '',
        'designation' => '',
        'role' => 'Staff',
        'status' => 'active',
        'password' => '',
        'password_confirmation' => '',
    ];

    // Detail Drawer Modal
    public ?int $viewingUserId = null;
    public ?User $viewingUser = null;

    // Lock Modal state
    public ?int $lockTargetId = null;
    public int $lockDurationMinutes = 60;
    public string $lockReason = '';

    // Action confirmation IDs
    public ?int $deleteTargetId = null;
    public ?int $restoreTargetId = null;
    public ?int $forceDeleteTargetId = null;

    // Import modal state
    public $importFile = null;
    public ?array $importSummary = null;
    public bool $isImporting = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTrashedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedGenderFilter(): void
    {
        $this->resetPage();
    }

    public function updatedIdTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedUserIds = $this->usersQuery()->pluck('id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->selectedUserIds = [];
        }
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'roleFilter',
            'statusFilter',
            'trashedFilter',
            'genderFilter',
            'idTypeFilter',
            'selectedUserIds',
            'selectAll',
        ]);
        $this->sortField = 'created_at';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    protected function usersQuery(): Builder
    {
        return User::query()
            ->with(['roles', 'createdByUser', 'updatedByUser'])
            ->when($this->trashedFilter === 'with_trashed', fn ($q) => $q->withTrashed())
            ->when($this->trashedFilter === 'only_trashed', fn ($q) => $q->onlyTrashed())
            ->when($this->search, function (Builder $query, $search) {
                $term = trim($search);
                $query->where(function (Builder $q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('whatsapp_no', 'like', "%{$term}%")
                        ->orWhere('urn', 'like', "%{$term}%")
                        ->orWhere('tenth_roll', 'like', "%{$term}%")
                        ->orWhere('designation', 'like', "%{$term}%")
                        ->orWhere('id_number', 'like', "%{$term}%");
                });
            })
            ->when($this->roleFilter, function (Builder $query, $role) {
                $query->whereHas('roles', fn ($rq) => $rq->where('name', $role));
            })
            ->when($this->statusFilter, function (Builder $query, $status) {
                if ($status === 'locked') {
                    $query->where(fn ($q) => $q->where('status', 'locked')->orWhere(fn ($sub) => $sub->whereNotNull('locked_untill')->where('locked_untill', '>', now())));
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($this->genderFilter, fn ($q, $g) => $q->where('gender', $g))
            ->when($this->idTypeFilter, fn ($q, $t) => $q->where('id_type', $t))
            ->orderBy($this->sortField, $this->sortDirection);
    }

    #[Computed]
    public function users()
    {
        return $this->usersQuery()->paginate($this->perPage);
    }

    #[Computed]
    public function availableRoles()
    {
        return Role::orderBy('name')->get();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => User::count(),
            'active' => User::where('status', 'active')->where(fn ($q) => $q->whereNull('locked_untill')->orWhere('locked_untill', '<=', now()))->count(),
            'locked' => User::where('status', 'locked')->orWhere(fn ($q) => $q->whereNotNull('locked_untill')->where('locked_untill', '>', now()))->count(),
            'trashed' => User::onlyTrashed()->count(),
        ];
    }

    // Modal Operations
    public function create(): void
    {
        $this->resetValidation();
        $this->editingUserId = null;
        $this->activeFormTab = 'basic';
        $this->form = [
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'whatsapp_no' => '',
            'gender' => 'male',
            'dob' => '',
            'tenth_roll' => '',
            'id_type' => 'aadhaar',
            'id_number' => '',
            'designation' => '',
            'role' => 'Staff',
            'status' => 'active',
            'password' => '',
            'password_confirmation' => '',
        ];
        $this->dispatch('modal-open', name: 'user-form-modal');
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $user = User::with('roles')->findOrFail($id);
        $this->editingUserId = $id;
        $this->activeFormTab = 'basic';

        $this->form = [
            'first_name' => $user->first_name ?? '',
            'last_name' => $user->last_last ?? ($user->last_name ?? ''),
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'whatsapp_no' => $user->whatsapp_no ?? '',
            'gender' => $user->gender ?? 'male',
            'dob' => $user->dob ? $user->dob->format('Y-m-d') : '',
            'tenth_roll' => $user->tenth_roll ?? '',
            'id_type' => $user->id_type ?? 'aadhaar',
            'id_number' => $user->id_number ?? '',
            'designation' => $user->designation ?? '',
            'role' => $user->roles->pluck('name')->first() ?? 'Staff',
            'status' => $user->status ?? 'active',
            'password' => '',
            'password_confirmation' => '',
        ];

        $this->dispatch('modal-open', name: 'user-form-modal');
    }

    public function save(): void
    {
        $isEdit = ! is_null($this->editingUserId);

        $rules = [
            'form.first_name' => ['required', 'string', 'max:100'],
            'form.last_name' => ['nullable', 'string', 'max:100'],
            'form.email' => [
                'required',
                'email',
                'max:255',
                $isEdit
                    ? Rule::unique('users', 'email')->ignore($this->editingUserId)
                    : Rule::unique('users', 'email'),
            ],
            'form.phone' => ['nullable', 'string', 'max:25'],
            'form.whatsapp_no' => ['nullable', 'string', 'max:25'],
            'form.gender' => ['nullable', 'string', 'in:male,female,other'],
            'form.dob' => ['nullable', 'date'],
            'form.tenth_roll' => ['nullable', 'string', 'max:50'],
            'form.id_type' => ['nullable', 'string', 'max:50'],
            'form.id_number' => ['nullable', 'string', 'max:100'],
            'form.designation' => ['nullable', 'string', 'max:150'],
            'form.role' => ['required', 'string', 'exists:roles,name'],
            'form.status' => ['required', 'string', 'in:active,inactive,suspended,invited,pending'],
        ];

        if (! $isEdit || ! empty($this->form['password'])) {
            $rules['form.password'] = [$isEdit ? 'nullable' : 'required', 'string', 'min:8', 'same:form.password_confirmation'];
        }

        try {
            $this->validate($rules, [
                'form.first_name.required' => __('First name is required.'),
                'form.email.required' => __('Email address is required.'),
                'form.email.email' => __('Please enter a valid email address.'),
                'form.email.unique' => __('This email address is already registered.'),
                'form.role.required' => __('Please select an assigned role.'),
                'form.status.required' => __('Please select an account status.'),
                'form.password.required' => __('Password is required.'),
                'form.password.min' => __('Password must be at least 8 characters.'),
                'form.password.same' => __('Password confirmation does not match.'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errorBag = $e->validator->errors();
            if ($errorBag->has('form.first_name') || $errorBag->has('form.last_name') || $errorBag->has('form.email') || $errorBag->has('form.phone') || $errorBag->has('form.whatsapp_no') || $errorBag->has('form.dob') || $errorBag->has('form.gender')) {
                $this->activeFormTab = 'basic';
            } elseif ($errorBag->has('form.tenth_roll') || $errorBag->has('form.id_type') || $errorBag->has('form.id_number') || $errorBag->has('form.designation')) {
                $this->activeFormTab = 'identity';
            } elseif ($errorBag->has('form.role') || $errorBag->has('form.status') || $errorBag->has('form.password') || $errorBag->has('form.password_confirmation')) {
                $this->activeFormTab = 'security';
            }

            Toast::dispatch($this, 'error', __('Please fill in all required fields properly.'));

            throw $e;
        }

        DB::transaction(function () use ($isEdit) {
            $fullName = trim($this->form['first_name'].' '.($this->form['last_name'] ?? ''));

            $userData = [
                'name' => $fullName,
                'first_name' => $this->form['first_name'],
                'last_name' => $this->form['last_name'] ?: null,
                'email' => strtolower($this->form['email']),
                'phone' => $this->form['phone'] ?: null,
                'whatsapp_no' => $this->form['whatsapp_no'] ?: null,
                'gender' => $this->form['gender'] ?: null,
                'dob' => $this->form['dob'] ? Carbon::parse($this->form['dob'])->format('Y-m-d') : null,
                'tenth_roll' => $this->form['tenth_roll'] ?: null,
                'id_type' => $this->form['id_type'] ?: null,
                'id_number' => $this->form['id_number'] ?: null,
                'designation' => $this->form['designation'] ?: null,
                'status' => $this->form['status'],
            ];

            if (! empty($this->form['password'])) {
                $userData['password'] = Hash::make($this->form['password']);
            }

            if ($isEdit) {
                $user = User::findOrFail($this->editingUserId);
                $user->update($userData);
                RbacService::syncUserRoles($user, $this->form['role']);
                Toast::dispatch($this, 'success', __("User ':name' updated successfully.", ['name' => $user->name]));
            } else {
                $userData['password'] = Hash::make($this->form['password'] ?: 'Password@123');
                $user = User::create($userData);
                RbacService::syncUserRoles($user, $this->form['role']);
                Toast::dispatch($this, 'success', __("User ':name' created successfully (URN: :urn).", ['name' => $user->name, 'urn' => $user->urn]));
            }
        });

        $this->dispatch('modal-close', name: 'user-form-modal');
    }

    public function viewDetails(int $id): void
    {
        $this->viewingUserId = $id;
        $this->viewingUser = User::withTrashed()->with(['roles', 'createdByUser', 'updatedByUser', 'deletedByUser'])->findOrFail($id);
        $this->dispatch('modal-open', name: 'user-detail-modal');
    }

    public function openLockModal(int $id): void
    {
        $this->lockTargetId = $id;
        $this->lockDurationMinutes = 60;
        $this->lockReason = '';
        $this->dispatch('modal-open', name: 'user-lock-modal');
    }

    public function lockUser(): void
    {
        if ($this->lockTargetId) {
            $user = User::findOrFail($this->lockTargetId);
            RbacService::lockUser($user, $this->lockReason, $this->lockDurationMinutes);
            $this->lockTargetId = null;
            $this->dispatch('modal-close', name: 'user-lock-modal');
            Toast::dispatch($this, 'warning', __("User ':name' account has been locked.", ['name' => $user->name]));
        }
    }

    public function unlockUser(int $id): void
    {
        $user = User::findOrFail($id);
        RbacService::unlockUser($user);
        Toast::dispatch($this, 'success', __("User ':name' account unlocked.", ['name' => $user->name]));
    }

    public function impersonateUser(int $id)
    {
        $targetUser = User::findOrFail($id);
        $result = app(\App\Services\ImpersonationService::class)->impersonate(auth()->user(), $targetUser);

        if (! $result['success']) {
            Toast::dispatch($this, 'error', $result['message']);

            return null;
        }

        $team = $targetUser->currentTeam ?? $targetUser->personalTeam();
        $targetUrl = $team
            ? route('dashboard', ['current_team' => $team->slug])
            : route('home');

        return redirect()->to($targetUrl);
    }

    public function openDeleteModal(int $id): void
    {
        $this->deleteTargetId = $id;
        $this->dispatch('modal-open', name: 'user-delete-modal');
    }

    public function softDelete(): void
    {
        if ($this->deleteTargetId) {
            $user = User::findOrFail($this->deleteTargetId);
            RbacService::softDeleteUser($user);
            $this->deleteTargetId = null;
            $this->dispatch('modal-close', name: 'user-delete-modal');
            Toast::dispatch($this, 'success', __("User ':name' soft-deleted.", ['name' => $user->name]));
        }
    }

    public function restoreUser(int $id): void
    {
        $user = User::onlyTrashed()->findOrFail($id);
        RbacService::restoreUser($user);
        Toast::dispatch($this, 'success', __("User ':name' restored successfully.", ['name' => $user->name]));
    }

    public function openForceDeleteModal(int $id): void
    {
        $this->forceDeleteTargetId = $id;
        $this->dispatch('modal-open', name: 'user-force-delete-modal');
    }

    public function forceDelete(): void
    {
        if ($this->forceDeleteTargetId) {
            $user = User::withTrashed()->findOrFail($this->forceDeleteTargetId);
            RbacService::forceDeleteUser($user);
            $this->forceDeleteTargetId = null;
            $this->dispatch('modal-close', name: 'user-force-delete-modal');
            Toast::dispatch($this, 'info', __("User record permanently purged."));
        }
    }

    // Bulk Actions
    public function bulkActivate(): void
    {
        if (empty($this->selectedUserIds)) {
            return;
        }

        $count = User::whereIn('id', $this->selectedUserIds)->update(['status' => 'active', 'locked_untill' => null]);
        AuditLogService::log('bulk_users_activated', "Bulk activated {$count} users.");
        $this->selectedUserIds = [];
        $this->selectAll = false;
        Toast::dispatch($this, 'success', __(':count users activated.', ['count' => $count]));
    }

    public function bulkDeactivate(): void
    {
        if (empty($this->selectedUserIds)) {
            return;
        }

        $count = User::whereIn('id', $this->selectedUserIds)->update(['status' => 'inactive']);
        AuditLogService::log('bulk_users_deactivated', "Bulk deactivated {$count} users.");
        $this->selectedUserIds = [];
        $this->selectAll = false;
        Toast::dispatch($this, 'info', __(':count users deactivated.', ['count' => $count]));
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedUserIds)) {
            return;
        }

        $users = User::whereIn('id', $this->selectedUserIds)->get();
        foreach ($users as $u) {
            RbacService::softDeleteUser($u);
        }

        $count = $users->count();
        $this->selectedUserIds = [];
        $this->selectAll = false;
        Toast::dispatch($this, 'success', __(':count users soft-deleted.', ['count' => $count]));
    }

    // Export & Import
    public function exportCsv(): StreamedResponse
    {
        AuditLogService::log('users_exported_csv', 'Exported users table to CSV.');

        return UserExportImportService::exportCsv($this->usersQuery(), 'users-directory');
    }

    public function exportExcel(): BinaryFileResponse
    {
        AuditLogService::log('users_exported_excel', 'Exported users table to Excel.');

        return UserExportImportService::exportExcel($this->usersQuery(), 'users-directory');
    }

    public function exportPdf(): Response
    {
        AuditLogService::log('users_exported_pdf', 'Exported users table to PDF report.');

        return UserExportImportService::exportPdf($this->usersQuery(), 'System Users Directory');
    }

    public function downloadSampleTemplate(): StreamedResponse
    {
        return UserExportImportService::downloadImportTemplate();
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importSummary = null;
        $this->isImporting = false;
        $this->dispatch('modal-open', name: 'user-import-modal');
    }

    public function runImport(): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $this->isImporting = true;
        $result = UserExportImportService::importUsers($this->importFile);
        $this->isImporting = false;
        $this->importSummary = $result;

        if ($result['success']) {
            Toast::dispatch($this, 'success', __(':count users imported successfully.', ['count' => $result['imported_count']]));
        } else {
            Toast::dispatch($this, 'error', __('Import encountered errors. Review diagnostics below.'));
        }
    }
}; ?>

<div class="space-y-4 sm:space-y-6 max-w-7xl">
    {{-- Page Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Users Management') }}</h1>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-secondary text-secondary-foreground">
                    {{ $this->users->total() }} {{ __('users') }}
                </span>
            </div>
            <p class="text-xs text-muted-foreground mt-1">
                {{ __('Manage enterprise system users, biometric/URN identifiers, dynamic role permissions, and access status.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button size="sm" class="h-9 gap-1.5" wire:click="create">
                <x-icon name="user-plus" class="h-4 w-4"/>
                {{ __('Add User') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5" wire:click="openImportModal">
                <x-icon name="upload" class="h-3.5 w-3.5"/>
                {{ __('Import') }}
            </x-ui.button>

            {{-- Export Dropdown --}}
            <x-ui.dropdown width="w-44" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground ml-0.5"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="exportExcel">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-text" wire:click="exportCsv">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="exportPdf">{{ __('Export PDF Report') }}</x-ui.dropdown.item>
            </x-ui.dropdown>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Total Registered') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="users" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2">{{ number_format($this->stats['total']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Active Accounts') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500">
                    <x-icon name="user-check" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-emerald-600 dark:text-emerald-400">{{ number_format($this->stats['active']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Locked Accounts') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-500/10 text-rose-500">
                    <x-icon name="lock" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-rose-600 dark:text-rose-400">{{ number_format($this->stats['locked']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Soft-Deleted (Archive)') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/10 text-amber-500">
                    <x-icon name="archive" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-amber-600 dark:text-amber-400">{{ number_format($this->stats['trashed']) }}</p>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="rounded-xl border border-border bg-card p-4 space-y-3 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2.5">
            {{-- Search input --}}
            <div class="relative lg:col-span-2">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, email, URN, phone, roll, ID…') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            {{-- Role Filter --}}
            <select
                wire:model.live="roleFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All Roles') }}</option>
                @foreach ($this->availableRoles as $role)
                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                @endforeach
            </select>

            {{-- Status Filter --}}
            <select
                wire:model.live="statusFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All Statuses') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="inactive">{{ __('Inactive') }}</option>
                <option value="locked">{{ __('Locked') }}</option>
                <option value="suspended">{{ __('Suspended') }}</option>
                <option value="invited">{{ __('Invited') }}</option>
                <option value="pending">{{ __('Pending') }}</option>
            </select>

            {{-- Trashed Filter --}}
            <select
                wire:model.live="trashedFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="without_trashed">{{ __('Active Records Only') }}</option>
                <option value="with_trashed">{{ __('Include Soft-Deleted') }}</option>
                <option value="only_trashed">{{ __('Soft-Deleted Only') }}</option>
            </select>

            {{-- Per Page --}}
            <select
                wire:model.live="perPage"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="10">10 {{ __('per page') }}</option>
                <option value="25">25 {{ __('per page') }}</option>
                <option value="50">50 {{ __('per page') }}</option>
                <option value="100">100 {{ __('per page') }}</option>
            </select>
        </div>

        @if ($search || $roleFilter || $statusFilter || $trashedFilter !== 'without_trashed' || $genderFilter || $idTypeFilter)
            <div class="flex items-center justify-between pt-2 border-t border-border">
                <span class="text-xs text-muted-foreground">{{ __('Active filters applied') }}</span>
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="text-xs text-primary hover:underline font-medium cursor-pointer"
                >
                    {{ __('Clear all filters') }}
                </button>
            </div>
        @endif
    </div>

    {{-- Bulk Actions Bar --}}
    @if (! empty($selectedUserIds))
        <div class="flex items-center justify-between p-3 rounded-xl border border-primary/20 bg-primary/5 text-xs shadow-xs animate-in fade-in duration-200">
            <div class="flex items-center gap-2">
                <x-icon name="check-square" class="h-4 w-4 text-primary"/>
                <span class="font-medium text-foreground">{{ count($selectedUserIds) }} {{ __('users selected') }}</span>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button size="sm" variant="outline" class="h-7 text-xs" wire:click="bulkActivate">
                    <x-icon name="check" class="h-3 w-3 text-emerald-500 mr-1"/>
                    {{ __('Activate') }}
                </x-ui.button>
                <x-ui.button size="sm" variant="outline" class="h-7 text-xs" wire:click="bulkDeactivate">
                    <x-icon name="slash" class="h-3 w-3 text-amber-500 mr-1"/>
                    {{ __('Deactivate') }}
                </x-ui.button>
                <x-ui.button size="sm" variant="outline" class="h-7 text-xs text-destructive hover:bg-destructive/10" wire:click="bulkDelete">
                    <x-icon name="trash" class="h-3 w-3 mr-1"/>
                    {{ __('Delete') }}
                </x-ui.button>
            </div>
        </div>
    @endif

    {{-- Desktop Data Table --}}
    <div class="hidden md:block rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[950px]">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-muted-foreground">
                        <th class="w-10 px-4 py-3 text-left">
                            <input
                                type="checkbox"
                                wire:model.live="selectAll"
                                class="rounded border-input text-primary focus:ring-ring cursor-pointer"
                            />
                        </th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest cursor-pointer hover:text-foreground" wire:click="sortBy('name')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('User & Identity') }}</span>
                                @if ($sortField === 'name')
                                    <x-icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="h-3 w-3"/>
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest">{{ __('URN / 10th Roll') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest">{{ __('Role & Designation') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest cursor-pointer hover:text-foreground" wire:click="sortBy('status')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Status') }}</span>
                                @if ($sortField === 'status')
                                    <x-icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="h-3 w-3"/>
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest cursor-pointer hover:text-foreground" wire:click="sortBy('last_login_at')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Last Activity') }}</span>
                                @if ($sortField === 'last_login_at')
                                    <x-icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="h-3 w-3"/>
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->users as $u)
                        <tr class="hover:bg-secondary/30 transition-colors {{ $u->trashed() ? 'opacity-60 bg-muted/20' : '' }}" wire:key="usr-row-{{ $u->id }}">
                            <td class="px-4 py-3.5">
                                <input
                                    type="checkbox"
                                    value="{{ $u->id }}"
                                    wire:model.live="selectedUserIds"
                                    class="rounded border-input text-primary focus:ring-ring cursor-pointer"
                                />
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="relative">
                                        <x-ui.avatar :name="$u->name" :initials="$u->initials()" :src="$u->avatarUrl()" size="size-9 text-xs"/>
                                        @if ($u->isLocked())
                                            <span class="absolute -top-1 -right-1 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-rose-500 text-white" title="{{ __('Locked Account') }}">
                                                <x-icon name="lock" class="h-2 w-2"/>
                                            </span>
                                        @endif
                                    </div>
                                    <div class="min-w-0 max-w-[220px]">
                                        <button
                                            type="button"
                                            wire:click="viewDetails({{ $u->id }})"
                                            class="text-sm font-semibold text-foreground hover:text-primary transition-colors truncate block text-left cursor-pointer"
                                        >
                                            {{ $u->name }}
                                        </button>
                                        <div class="text-xs text-muted-foreground truncate">{{ $u->email }}</div>
                                        @if ($u->phone)
                                            <div class="text-[10px] text-muted-foreground/80 font-mono">{{ $u->phone }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 text-xs">
                                <div class="font-mono font-medium text-foreground">{{ $u->urn ?? '—' }}</div>
                                @if ($u->tenth_roll)
                                    <div class="text-[10px] text-muted-foreground font-mono">10th: {{ $u->tenth_roll }}</div>
                                @endif
                                @if ($u->id_type && $u->id_number)
                                    <div class="text-[10px] text-muted-foreground/70 uppercase">{{ $u->id_type }}: {{ $u->id_number }}</div>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-xs">
                                @php($roleObj = $u->roles->first())
                                <x-ui.badge :color="$roleObj?->color ?? 'secondary'" class="gap-1">
                                    <x-icon name="shield" class="h-2.5 w-2.5"/>
                                    {{ $roleObj?->name ?? 'Staff' }}
                                </x-ui.badge>
                                @if ($u->designation)
                                    <div class="text-[11px] text-muted-foreground mt-0.5 truncate max-w-[150px]">{{ $u->designation }}</div>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-xs">
                                @if ($u->trashed())
                                    <x-ui.badge color="rose" class="gap-1">{{ __('Deleted') }}</x-ui.badge>
                                @elseif ($u->isLocked())
                                    <x-ui.badge color="rose" class="gap-1">
                                        <x-icon name="lock" class="h-2.5 w-2.5"/>
                                        {{ __('Locked') }}
                                    </x-ui.badge>
                                @else
                                    <x-ui.badge :color="$u->statusBadgeColor()" class="capitalize">
                                        {{ $u->status }}
                                    </x-ui.badge>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-xs text-muted-foreground">
                                @if ($u->last_login_at)
                                    <div class="font-medium text-foreground">{{ $u->last_login_at->diffForHumans() }}</div>
                                    <div class="text-[10px] font-mono">{{ $u->last_login_ip ?: 'IP N/A' }}</div>
                                @else
                                    <span class="text-muted-foreground/60">{{ __('Never logged in') }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="viewDetails({{ $u->id }})"
                                        class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                                        title="{{ __('View Dossier') }}"
                                    >
                                        <x-icon name="eye" class="h-4 w-4"/>
                                    </button>

                                    @if ($u->trashed())
                                        <button
                                            type="button"
                                            wire:click="restoreUser({{ $u->id }})"
                                            class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-emerald-500/10 text-emerald-600 transition-colors cursor-pointer"
                                            title="{{ __('Restore Account') }}"
                                        >
                                            <x-icon name="rotate-ccw" class="h-4 w-4"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="openForceDeleteModal({{ $u->id }})"
                                            class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-destructive/10 text-destructive transition-colors cursor-pointer"
                                            title="{{ __('Permanently Purge') }}"
                                        >
                                            <x-icon name="trash" class="h-4 w-4"/>
                                        </button>
                                    @else
                                        <x-ui.dropdown width="w-48" align="end">
                                            <x-slot:trigger>
                                                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer">
                                                    <x-icon name="more-vertical" class="h-4 w-4"/>
                                                </button>
                                            </x-slot:trigger>
                                            <x-ui.dropdown.item icon="pencil" wire:click="edit({{ $u->id }})">{{ __('Edit details') }}</x-ui.dropdown.item>
                                            <x-ui.dropdown.item icon="history" href="{{ route('admin.audit-logs.index', ['user' => $u->id]) }}" wire:navigate>{{ __('Audit History') }}</x-ui.dropdown.item>
                                            @if (app(\App\Services\ImpersonationService::class)->canImpersonate(auth()->user(), $u))
                                                <x-ui.dropdown.item icon="user-check" wire:click="impersonateUser({{ $u->id }})">{{ __('Login as User') }}</x-ui.dropdown.item>
                                            @endif
                                            <x-ui.dropdown.separator/>
                                            @if ($u->isLocked())
                                                <x-ui.dropdown.item icon="unlock" wire:click="unlockUser({{ $u->id }})">{{ __('Unlock Account') }}</x-ui.dropdown.item>
                                            @else
                                                <x-ui.dropdown.item icon="lock" wire:click="openLockModal({{ $u->id }})">{{ __('Lock Account') }}</x-ui.dropdown.item>
                                            @endif
                                            <x-ui.dropdown.separator/>
                                            <x-ui.dropdown.item icon="trash-2" danger wire:click="openDeleteModal({{ $u->id }})">{{ __('Soft Delete') }}</x-ui.dropdown.item>
                                        </x-ui.dropdown>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-16 text-center text-muted-foreground">
                                <x-icon name="users" class="mx-auto h-10 w-10 mb-2 opacity-40"/>
                                <p class="text-sm font-medium">{{ __('No users matching filter criteria') }}</p>
                                <p class="text-xs text-muted-foreground mt-1">{{ __('Try searching for a different keyword or resetting your filters.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->users->hasPages())
            <div class="p-4 border-t border-border">
                {{ $this->users->links() }}
            </div>
        @endif
    </div>

    {{-- Mobile Cards --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->users as $u)
            <div class="rounded-xl border border-border bg-card p-4 space-y-3 shadow-xs {{ $u->trashed() ? 'opacity-60' : '' }}" wire:key="usr-m-{{ $u->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-3 min-w-0">
                        <x-ui.avatar :name="$u->name" :initials="$u->initials()" :src="$u->avatarUrl()" size="size-10 text-sm"/>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold truncate">{{ $u->name }}</p>
                            <p class="text-xs text-muted-foreground truncate">{{ $u->email }}</p>
                            <p class="text-[10px] font-mono text-muted-foreground/80 mt-0.5">{{ $u->urn ?? 'URN N/A' }}</p>
                        </div>
                    </div>
                    <x-ui.badge :color="$u->statusBadgeColor()" class="capitalize text-[10px]">
                        {{ $u->status }}
                    </x-ui.badge>
                </div>

                <div class="flex items-center justify-between text-xs pt-2 border-t border-border">
                    <div class="flex items-center gap-1.5">
                        <x-ui.badge :color="$u->roles->first()?->color ?? 'secondary'" class="text-[10px]">
                            {{ $u->roles->first()?->name ?? 'Staff' }}
                        </x-ui.badge>
                        @if ($u->designation)
                            <span class="text-muted-foreground truncate max-w-[120px] text-[11px]">{{ $u->designation }}</span>
                        @endif
                    </div>
                    <span class="text-[11px] text-muted-foreground font-mono">{{ $u->last_login_at ? $u->last_login_at->diffForHumans() : 'Never' }}</span>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <x-ui.button size="sm" variant="outline" class="flex-1 text-xs" wire:click="viewDetails({{ $u->id }})">
                        <x-icon name="eye" class="h-3 w-3 mr-1"/>
                        {{ __('View') }}
                    </x-ui.button>
                    @if (! $u->trashed())
                        <x-ui.button size="sm" variant="outline" class="flex-1 text-xs" wire:click="edit({{ $u->id }})">
                            <x-icon name="pencil" class="h-3 w-3 mr-1"/>
                            {{ __('Edit') }}
                        </x-ui.button>
                        <x-ui.button size="sm" variant="outline" class="text-destructive text-xs" wire:click="openDeleteModal({{ $u->id }})">
                            <x-icon name="trash" class="h-3 w-3"/>
                        </x-ui.button>
                    @else
                        <x-ui.button size="sm" variant="outline" class="flex-1 text-emerald-600 text-xs" wire:click="restoreUser({{ $u->id }})">
                            <x-icon name="rotate-ccw" class="h-3 w-3 mr-1"/>
                            {{ __('Restore') }}
                        </x-ui.button>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-border bg-card p-8 text-center text-muted-foreground text-sm">
                {{ __('No users found matching your filters.') }}
            </div>
        @endforelse

        @if ($this->users->hasPages())
            <div>
                {{ $this->users->links() }}
            </div>
        @endif
    </div>

    {{-- User Create / Edit Tabbed Modal --}}
    <x-ui.modal name="user-form-modal" max-width="max-w-2xl" :title="$editingUserId ? __('Edit User Details') : __('Create New System User')" :description="__('Fill in complete personal identity, academic records, and access permissions.')">
        <div class="space-y-4">
            {{-- Tabs --}}
            <div class="flex items-center gap-1 border-b border-border pb-2">
                <button
                    type="button"
                    wire:click="$set('activeFormTab', 'basic')"
                    class="relative px-3 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer {{ $activeFormTab === 'basic' ? 'bg-primary text-primary-foreground font-semibold' : 'text-muted-foreground hover:bg-secondary' }}"
                >
                    <span>{{ __('1. Personal & Contact') }}</span>
                    @if ($errors->has('form.first_name') || $errors->has('form.last_name') || $errors->has('form.email') || $errors->has('form.phone') || $errors->has('form.whatsapp_no') || $errors->has('form.dob') || $errors->has('form.gender'))
                        <span class="inline-block size-1.5 rounded-full bg-destructive ml-1"></span>
                    @endif
                </button>
                <button
                    type="button"
                    wire:click="$set('activeFormTab', 'identity')"
                    class="relative px-3 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer {{ $activeFormTab === 'identity' ? 'bg-primary text-primary-foreground font-semibold' : 'text-muted-foreground hover:bg-secondary' }}"
                >
                    <span>{{ __('2. Identity & Academic') }}</span>
                    @if ($errors->has('form.tenth_roll') || $errors->has('form.id_type') || $errors->has('form.id_number') || $errors->has('form.designation'))
                        <span class="inline-block size-1.5 rounded-full bg-destructive ml-1"></span>
                    @endif
                </button>
                <button
                    type="button"
                    wire:click="$set('activeFormTab', 'security')"
                    class="relative px-3 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer {{ $activeFormTab === 'security' ? 'bg-primary text-primary-foreground font-semibold' : 'text-muted-foreground hover:bg-secondary' }}"
                >
                    <span>{{ __('3. Access & Password') }}</span>
                    @if ($errors->has('form.role') || $errors->has('form.status') || $errors->has('form.password') || $errors->has('form.password_confirmation'))
                        <span class="inline-block size-1.5 rounded-full bg-destructive ml-1"></span>
                    @endif
                </button>
            </div>

            <form wire:submit="save" class="space-y-4 pt-1">
                {{-- TAB 1: Personal & Contact --}}
                <div class="{{ $activeFormTab === 'basic' ? 'space-y-4' : 'hidden' }}">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-ui.input wire:model="form.first_name" :label="__('First Name') .' *'" required :error="$errors->first('form.first_name')"/>
                        <x-ui.input wire:model="form.last_name" :label="__('Last Name')" :error="$errors->first('form.last_name')"/>
                    </div>

                    <x-ui.input wire:model="form.email" :label="__('Email Address') .' *'" type="email" required :error="$errors->first('form.email')"/>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-ui.input wire:model="form.phone" :label="__('Phone Number')" type="tel" placeholder="+91 98765 43210" :error="$errors->first('form.phone')"/>
                        <x-ui.input wire:model="form.whatsapp_no" :label="__('WhatsApp Number')" type="tel" placeholder="+91 98765 43210" :error="$errors->first('form.whatsapp_no')"/>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-ui.select
                            wire:model="form.gender"
                            :label="__('Gender')"
                            :options="[
                                'male' => __('Male'),
                                'female' => __('Female'),
                                'other' => __('Other'),
                            ]"
                            :error="$errors->first('form.gender')"
                        />
                        <x-ui.input wire:model="form.dob" :label="__('Date of Birth')" type="date" :error="$errors->first('form.dob')"/>
                    </div>
                </div>

                {{-- TAB 2: Identity & Academic --}}
                <div class="{{ $activeFormTab === 'identity' ? 'space-y-4' : 'hidden' }}">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-ui.input wire:model="form.tenth_roll" :label="__('10th Class Roll No')" placeholder="WB-10-123456" :error="$errors->first('form.tenth_roll')"/>
                        <x-ui.input wire:model="form.designation" :label="__('Designation / Role Title')" placeholder="e.g. Assistant Professor, Staff" :error="$errors->first('form.designation')"/>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-ui.select
                            wire:model="form.id_type"
                            :label="__('Government ID Type')"
                            :options="[
                                'aadhaar' => 'Aadhaar Card',
                                'pan' => 'PAN Card',
                                'voter_id' => 'Voter ID Card',
                                'passport' => 'Passport',
                                'driving_license' => 'Driving License',
                            ]"
                            :error="$errors->first('form.id_type')"
                        />
                        <x-ui.input wire:model="form.id_number" :label="__('ID Number / Identifier')" placeholder="XXXX-XXXX-XXXX" :error="$errors->first('form.id_number')"/>
                    </div>
                </div>

                {{-- TAB 3: Access & Security --}}
                <div class="{{ $activeFormTab === 'security' ? 'space-y-4' : 'hidden' }}">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Assigned Role') }} *</label>
                            <select
                                wire:model="form.role"
                                class="mt-1 h-9 w-full rounded-md border {{ $errors->has('form.role') ? 'border-destructive' : 'border-input' }} bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                            >
                                @foreach ($this->availableRoles as $role)
                                    <option value="{{ $role->name }}">{{ $role->name }} ({{ $role->description ? \Illuminate\Support\Str::limit($role->description, 35) : 'General access' }})</option>
                                @endforeach
                            </select>
                            @if ($errors->has('form.role'))
                                <p class="mt-1.5 text-xs text-destructive flex items-center gap-1 font-medium">
                                    <x-icon name="alert-circle" class="h-3.5 w-3.5 shrink-0"/>
                                    <span>{{ $errors->first('form.role') }}</span>
                                </p>
                            @endif
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Account Status') }} *</label>
                            <select
                                wire:model="form.status"
                                class="mt-1 h-9 w-full rounded-md border {{ $errors->has('form.status') ? 'border-destructive' : 'border-input' }} bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                            >
                                <option value="active">{{ __('Active (Normal Access)') }}</option>
                                <option value="inactive">{{ __('Inactive (Suspended)') }}</option>
                                <option value="invited">{{ __('Invited (Pending Confirmation)') }}</option>
                                <option value="pending">{{ __('Pending Verification') }}</option>
                            </select>
                            @if ($errors->has('form.status'))
                                <p class="mt-1.5 text-xs text-destructive flex items-center gap-1 font-medium">
                                    <x-icon name="alert-circle" class="h-3.5 w-3.5 shrink-0"/>
                                    <span>{{ $errors->first('form.status') }}</span>
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="pt-2 border-t border-border">
                        <p class="text-xs font-semibold mb-2 text-foreground">
                            {{ $editingUserId ? __('Reset Password (Leave blank to keep unchanged)') : __('Set Initial Password *') }}
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <x-ui.password wire:model="form.password" :label="__('Password')" autocomplete="new-password" :error="$errors->first('form.password')"/>
                            <x-ui.password wire:model="form.password_confirmation" :label="__('Confirm Password')" autocomplete="new-password" :error="$errors->first('form.password')"/>
                        </div>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="p-3 rounded-lg border border-destructive/30 bg-destructive/10 text-destructive text-xs flex items-center gap-2 font-medium">
                        <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                        <span>{{ __('Please complete all required fields properly in the tabs above.') }}</span>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-form-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>

                    <div class="flex items-center gap-2">
                        @if ($activeFormTab !== 'basic')
                            <x-ui.button variant="outline" type="button" wire:click="$set('activeFormTab', '{{ $activeFormTab === 'security' ? 'identity' : 'basic' }}')">
                                {{ __('Previous') }}
                            </x-ui.button>
                        @endif

                        @if ($activeFormTab !== 'security')
                            <x-ui.button type="button" wire:click="$set('activeFormTab', '{{ $activeFormTab === 'basic' ? 'identity' : 'security' }}')">
                                {{ __('Next') }}
                            </x-ui.button>
                        @else
                            <x-ui.button type="submit">
                                <x-icon name="check" class="h-4 w-4 mr-1"/>
                                {{ $editingUserId ? __('Save Changes') : __('Create User') }}
                            </x-ui.button>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </x-ui.modal>

    {{-- User Quick Dossier / Detail Modal --}}
    <x-ui.modal name="user-detail-modal" max-width="max-w-2xl" :title="__('User Dossier & Security Profile')" :description="__('Full profile, identity records, role privileges, and activity overview.')">
        @if ($viewingUser)
            <div class="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
                {{-- Header summary card --}}
                <div class="flex items-center gap-4 p-4 rounded-xl border border-border bg-secondary/15">
                    <x-ui.avatar :name="$viewingUser->name" :initials="$viewingUser->initials()" :src="$viewingUser->avatarUrl()" size="size-16 text-xl"/>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-foreground truncate">{{ $viewingUser->name }}</h2>
                            <x-ui.badge :color="$viewingUser->statusBadgeColor()" class="capitalize text-[10px]">
                                {{ $viewingUser->status }}
                            </x-ui.badge>
                        </div>
                        <p class="text-xs text-muted-foreground font-mono mt-0.5">{{ $viewingUser->email }}</p>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono bg-primary/10 text-primary font-semibold">
                                {{ $viewingUser->urn ?? 'URN UNASSIGNED' }}
                            </span>
                            @if ($viewingUser->designation)
                                <span class="text-xs text-muted-foreground">&bull; {{ $viewingUser->designation }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Detail attributes grid --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                    <div class="p-3 rounded-lg border border-border bg-card">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('UUID') }}</span>
                        <div class="font-mono text-[10px] text-foreground truncate mt-1" title="{{ $viewingUser->uuid }}">{{ $viewingUser->uuid }}</div>
                    </div>

                    <div class="p-3 rounded-lg border border-border bg-card">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Phone / WhatsApp') }}</span>
                        <div class="font-medium text-foreground mt-1">{{ $viewingUser->phone ?: ($viewingUser->whatsapp_no ?: '—') }}</div>
                    </div>

                    <div class="p-3 rounded-lg border border-border bg-card">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Gender & DOB') }}</span>
                        <div class="font-medium text-foreground mt-1">
                            {{ ucfirst((string)$viewingUser->gender) }} · {{ $viewingUser->dob ? $viewingUser->dob->format('d M Y') : '—' }}
                        </div>
                    </div>

                    <div class="p-3 rounded-lg border border-border bg-card">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('10th Roll No') }}</span>
                        <div class="font-mono font-medium text-foreground mt-1">{{ $viewingUser->tenth_roll ?: '—' }}</div>
                    </div>

                    <div class="p-3 rounded-lg border border-border bg-card">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('ID Document') }}</span>
                        <div class="font-medium text-foreground mt-1 uppercase text-[11px]">
                            {{ $viewingUser->id_type ? $viewingUser->id_type.': '.$viewingUser->id_number : '—' }}
                        </div>
                    </div>

                    <div class="p-3 rounded-lg border border-border bg-card">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Last Login IP') }}</span>
                        <div class="font-mono text-foreground mt-1">{{ $viewingUser->last_login_ip ?: '—' }}</div>
                    </div>
                </div>

                {{-- Roles & Direct Permissions --}}
                <div class="p-3.5 rounded-xl border border-border bg-card space-y-2">
                    <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Assigned Roles & Privileges') }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse ($viewingUser->roles as $r)
                            <x-ui.badge :color="$r->color" class="gap-1">
                                <x-icon name="shield" class="h-3 w-3"/>
                                {{ $r->name }}
                            </x-ui.badge>
                        @empty
                            <span class="text-xs text-muted-foreground">{{ __('No roles assigned.') }}</span>
                        @endforelse
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-border">
                    <div class="flex items-center gap-2">
                        <x-ui.button variant="outline" size="sm" href="{{ route('admin.audit-logs.index', ['user' => $viewingUser->id]) }}" wire:navigate>
                            <x-icon name="history" class="h-3.5 w-3.5 mr-1"/>
                            {{ __('View Full Audit Trail') }}
                        </x-ui.button>

                        @if (app(\App\Services\ImpersonationService::class)->canImpersonate(auth()->user(), $viewingUser))
                            <x-ui.button variant="outline" size="sm" wire:click="impersonateUser({{ $viewingUser->id }})" class="text-primary hover:bg-primary/10">
                                <x-icon name="user-check" class="h-3.5 w-3.5 mr-1"/>
                                {{ __('Login as User') }}
                            </x-ui.button>
                        @endif
                    </div>

                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-detail-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- Lock Account Modal --}}
    <x-ui.modal name="user-lock-modal" max-width="max-w-md" :title="__('Lock User Account')" :description="__('Temporarily prevent user from logging in and accessing any services.')">
        <div class="space-y-4">
            <x-ui.select
                wire:model="lockDurationMinutes"
                :label="__('Lockout Duration') . ' *'"
                :options="[
                    15 => __('15 Minutes'),
                    60 => __('1 Hour'),
                    720 => __('12 Hours'),
                    1440 => __('24 Hours'),
                    10080 => __('7 Days'),
                    525600 => __('Permanent Lockout (1 Year)'),
                ]"
            />

            <x-ui.textarea wire:model="lockReason" :label="__('Reason for Lockout')" placeholder="e.g. Suspicious login activity, pending inquiry, security violation"/>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-lock-modal')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="destructive" wire:click="lockUser">
                    <x-icon name="lock" class="h-4 w-4 mr-1"/>
                    {{ __('Lock Account') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Soft Delete Modal --}}
    <x-ui.modal name="user-delete-modal" max-width="max-w-sm" :title="__('Soft-Delete User')" :description="__('The user account will be archived. You can restore it later if needed.')">
        <div class="flex justify-end gap-2 pt-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-delete-modal')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="softDelete">{{ __('Soft-Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Force Delete Modal --}}
    <x-ui.modal name="user-force-delete-modal" max-width="max-w-sm" :title="__('Permanently Purge Record')" :description="__('WARNING: This action is irreversible. All user permissions and account references will be destroyed.')">
        <div class="flex justify-end gap-2 pt-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-force-delete-modal')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="forceDelete">{{ __('Permanently Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Import Modal --}}
    <x-ui.modal name="user-import-modal" max-width="max-w-lg" :title="__('Batch Import Users')" :description="__('Upload a CSV or Excel spreadsheet with user records.')">
        <div class="space-y-4">
            <div class="p-3.5 rounded-xl border border-primary/20 bg-primary/5 flex items-center justify-between text-xs">
                <div>
                    <span class="font-semibold text-foreground">{{ __('Need standard format?') }}</span>
                    <p class="text-muted-foreground mt-0.5">{{ __('Download pre-formatted sample template.') }}</p>
                </div>
                <x-ui.button size="sm" variant="outline" class="h-8 gap-1 text-xs" wire:click="downloadSampleTemplate">
                    <x-icon name="download" class="h-3 w-3"/>
                    {{ __('Template') }}
                </x-ui.button>
            </div>

            <div class="space-y-2">
                <label class="text-xs font-semibold text-foreground">{{ __('Select CSV or Excel File (.csv, .xlsx)') }} *</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel"
                    class="block w-full text-xs text-muted-foreground file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
                @error('importFile')
                    <p class="text-[11px] text-destructive">{{ $message }}</p>
                @enderror
            </div>

            @if ($importSummary)
                <div class="p-3 rounded-lg border {{ $importSummary['success'] ? 'border-emerald-500/20 bg-emerald-500/5' : 'border-amber-500/20 bg-amber-500/5' }} text-xs space-y-1.5">
                    <div class="flex items-center justify-between font-semibold">
                        <span>{{ __('Import Summary') }}</span>
                        <span>{{ $importSummary['imported_count'] }} {{ __('Imported') }} / {{ $importSummary['skipped_count'] }} {{ __('Skipped') }}</span>
                    </div>
                    @if (! empty($importSummary['errors']))
                        <div class="max-h-32 overflow-y-auto space-y-1 pt-1 text-[11px] text-destructive font-mono">
                            @foreach ($importSummary['errors'] as $err)
                                <div>&bull; {{ $err }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('user-import-modal')">{{ __('Close') }}</x-ui.button>
                <x-ui.button type="button" wire:click="runImport" :disabled="! $importFile || $isImporting">
                    <x-icon name="upload" class="h-4 w-4 mr-1"/>
                    {{ __('Run Import') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
