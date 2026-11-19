<?php

use App\Models\Course;
use App\Models\LibraryDeposit;
use App\Models\LibraryMember;
use App\Models\User;
use App\Services\Library\LibraryCardService;
use App\Services\Library\LibraryImportExportService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Library Member Directory')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'id';
    public string $sortDirection = 'desc';
    public int $perPage = 15;

    // Member Modal state (Create/Edit)
    public bool $showMemberModal = false;
    public ?int $editingMemberId = null;
    public string $memberType = 'student';
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $studentId = '';
    public ?int $selectedUserId = null;
    public string $userSearchQuery = '';
    public int $maxBooksAllowed = 3;
    public float $securityDepositOverride = 0.00;
    public ?string $expiryDate = null;
    public string $notes = '';
    public string $status = 'active';

    // Library Card Modal state
    public bool $showCardModal = false;
    public ?LibraryMember $cardMember = null;
    public bool $cardWithDeposit = true;
    public bool $cardWithIssuedBooks = true;
    public ?array $cardPayload = null;

    // Import Modal state
    public bool $showImportModal = false;
    public $importFile = null;
    public ?array $importSummary = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
        }
    }

    #[Computed]
    public function stats(): array
    {
        $totalDeposits = LibraryMember::with('user.activeLibraryDeposits')->get()->sum(fn ($m) => $m->getActiveSecurityDeposit());

        return [
            'total' => LibraryMember::count(),
            'students' => LibraryMember::where('member_type', LibraryMember::TYPE_STUDENT)->count(),
            'external' => LibraryMember::where('member_type', LibraryMember::TYPE_EXTERNAL)->count(),
            'active_members' => LibraryMember::where('status', LibraryMember::STATUS_ACTIVE)->count(),
            'total_deposits' => $totalDeposits,
        ];
    }

    #[Computed]
    public function userSearchResults(): array
    {
        if (strlen(trim($this->userSearchQuery)) < 2) {
            return [];
        }

        $term = '%'.trim($this->userSearchQuery).'%';

        return User::query()
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('student_id', 'like', $term);
            })
            ->limit(8)
            ->get(['id', 'name', 'email', 'phone', 'student_id'])
            ->all();
    }

    public function selectUser(int $userId): void
    {
        $user = User::find($userId);
        if ($user) {
            $this->selectedUserId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email ?? '';
            $this->phone = $user->phone ?? '';
            $this->studentId = $user->student_id ?? '';
            $this->userSearchQuery = '';
        }
    }

    public function openCreateModal(): void
    {
        $this->resetMemberForm();
        $this->showMemberModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->resetMemberForm();
        $member = LibraryMember::findOrFail($id);

        $this->editingMemberId = $member->id;
        $this->memberType = $member->member_type;
        $this->name = $member->name;
        $this->email = $member->email ?? '';
        $this->phone = $member->phone ?? '';
        $this->studentId = $member->student_id ?? '';
        $this->selectedUserId = $member->user_id;
        $this->maxBooksAllowed = $member->max_books_allowed;
        $this->securityDepositOverride = (float) $member->security_deposit_override;
        $this->expiryDate = $member->expiry_date?->format('Y-m-d');
        $this->notes = $member->notes ?? '';
        $this->status = $member->status;

        $this->showMemberModal = true;
    }

    public function saveMember(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'memberType' => 'required|in:student,external,staff',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'studentId' => 'nullable|string|max:50',
            'maxBooksAllowed' => 'required|integer|min:1|max:20',
            'securityDepositOverride' => 'nullable|numeric|min:0',
            'expiryDate' => 'nullable|date',
            'status' => 'required|in:active,suspended,expired',
        ]);

        try {
            DB::beginTransaction();

            if ($this->editingMemberId) {
                $member = LibraryMember::findOrFail($this->editingMemberId);
                $member->update([
                    'name' => $this->name,
                    'member_type' => $this->memberType,
                    'email' => $this->email ?: null,
                    'phone' => $this->phone ?: null,
                    'student_id' => $this->studentId ?: null,
                    'user_id' => $this->selectedUserId,
                    'max_books_allowed' => $this->maxBooksAllowed,
                    'security_deposit_override' => $this->securityDepositOverride,
                    'expiry_date' => $this->expiryDate ?: null,
                    'notes' => $this->notes ?: null,
                    'status' => $this->status,
                ]);

                Toast::dispatch($this, 'success', __('Member ":name" updated successfully.', ['name' => $member->name]));
            } else {
                $member = LibraryMember::create([
                    'member_number' => LibraryCardService::generateMemberNumber(),
                    'user_id' => $this->selectedUserId,
                    'student_id' => $this->studentId ?: null,
                    'member_type' => $this->memberType,
                    'name' => $this->name,
                    'email' => $this->email ?: null,
                    'phone' => $this->phone ?: null,
                    'joining_date' => now()->toDateString(),
                    'expiry_date' => $this->expiryDate ?: now()->addYear()->toDateString(),
                    'max_books_allowed' => $this->maxBooksAllowed,
                    'security_deposit_override' => $this->securityDepositOverride,
                    'status' => $this->status,
                    'notes' => $this->notes ?: null,
                ]);

                Toast::dispatch($this, 'success', __('Member ":name" registered successfully with Card #:no.', [
                    'name' => $member->name,
                    'no' => $member->member_number,
                ]));
            }

            DB::commit();
            $this->showMemberModal = false;
            $this->resetMemberForm();
        } catch (\Exception $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    private function resetMemberForm(): void
    {
        $this->editingMemberId = null;
        $this->memberType = 'student';
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->studentId = '';
        $this->selectedUserId = null;
        $this->userSearchQuery = '';
        $this->maxBooksAllowed = 3;
        $this->securityDepositOverride = 0.00;
        $this->expiryDate = now()->addYear()->format('Y-m-d');
        $this->notes = '';
        $this->status = 'active';
        $this->resetErrorBag();
    }

    public function openCardModal(int $id): void
    {
        $this->cardMember = LibraryMember::with(['user.activeLibraryDeposits', 'activeIssues.book', 'activeIssues.bookCopy'])->findOrFail($id);
        $this->cardWithDeposit = true;
        $this->cardWithIssuedBooks = true;
        $this->refreshCardPayload();
        $this->showCardModal = true;
    }

    public function updatedCardWithDeposit(): void
    {
        $this->refreshCardPayload();
    }

    public function updatedCardWithIssuedBooks(): void
    {
        $this->refreshCardPayload();
    }

    private function refreshCardPayload(): void
    {
        if ($this->cardMember) {
            $this->cardPayload = LibraryCardService::getCardPayload(
                $this->cardMember,
                $this->cardWithDeposit,
                $this->cardWithIssuedBooks
            );
        }
    }

    public function toggleMemberStatus(int $id): void
    {
        $member = LibraryMember::findOrFail($id);
        $newStatus = $member->status === LibraryMember::STATUS_ACTIVE ? LibraryMember::STATUS_SUSPENDED : LibraryMember::STATUS_ACTIVE;
        $member->update(['status' => $newStatus]);

        Toast::dispatch($this, 'info', __('Member status changed to :status.', ['status' => ucfirst($newStatus)]));
    }

    public function syncRegisteredStudents(): void
    {
        $usersWithoutCard = User::whereNotNull('student_id')
            ->whereDoesntHave('libraryMember')
            ->get();

        $count = 0;
        foreach ($usersWithoutCard as $user) {
            LibraryCardService::getOrCreateMemberForUser($user);
            $count++;
        }

        Toast::dispatch($this, 'success', __(':count registered student(s) automatically enrolled into Library directory.', ['count' => $count]));
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $members = $this->queryMembers()->get();

        $headers = [
            'Card No', 'Name', 'Type', 'Student ID', 'Email', 'Phone',
            'Active Deposit (INR)', 'Borrowed Books Count', 'Max Quota', 'Status', 'Expiry Date',
        ];

        $rows = $members->map(fn ($m) => [
            $m->member_number,
            $m->name,
            ucfirst($m->member_type),
            $m->student_id ?? 'N/A',
            $m->email ?? 'N/A',
            $m->phone ?? 'N/A',
            number_format($m->getActiveSecurityDeposit(), 2),
            $m->active_issues_count ?? 0,
            $m->max_books_allowed,
            ucfirst($m->status),
            $m->expiry_date?->format('Y-m-d') ?? 'N/A',
        ])->toArray();

        return TableExporter::csv($headers, $rows, 'library_members_'.now()->format('Ymd_His').'.csv');
    }

    public function export(string $format = 'csv')
    {
        if ($format === 'csv') {
            return $this->exportCsv();
        }

        $query = $this->queryMembers();

        return TableExporter::download(
            $query,
            $format,
            'library-members',
            ['Card No', 'Name', 'Type', 'Student ID', 'Email', 'Phone', 'Active Deposit (INR)', 'Borrowed Books Count', 'Max Quota', 'Status', 'Expiry Date'],
            fn ($m) => [
                $m->member_number,
                $m->name,
                ucfirst($m->member_type),
                $m->student_id ?? 'N/A',
                $m->email ?? 'N/A',
                $m->phone ?? 'N/A',
                number_format($m->getActiveSecurityDeposit(), 2),
                $m->active_issues_count ?? 0,
                $m->max_books_allowed,
                ucfirst($m->status),
                $m->expiry_date?->format('Y-m-d') ?? 'N/A',
            ],
            'SNTCSSC Library Members Directory'
        );
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importSummary = null;
        $this->resetErrorBag();
        $this->showImportModal = true;
    }

    public function downloadTemplate()
    {
        return LibraryImportExportService::sampleMembersCsv();
    }

    public function submitImport(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $result = LibraryImportExportService::importMembers($path);
            $this->importSummary = $result;

            Toast::dispatch($this, 'success', __('Import completed: :count members imported.', ['count' => $result['imported']]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :err', ['err' => $e->getMessage()]));
        }
    }

    private function queryMembers(): Builder
    {
        return LibraryMember::query()
            ->with(['user.activeLibraryDeposits'])
            ->withCount(['activeIssues'])
            ->when($this->typeFilter, fn ($q) => $q->where('member_type', $this->typeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('member_number', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderBy($this->sortField, $this->sortDirection);
    }

    #[Computed]
    public function members()
    {
        return $this->queryMembers()->paginate($this->perPage);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="id-card" class="w-6 h-6" />
                </span>
                {{ __('Library Member & Card Directory') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Manage library card holders, student course rosters, external borrowers, security deposits, and print official cards.') }}
            </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <button
                type="button"
                wire:click="syncRegisteredStudents"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition"
                title="{{ __('Auto-create library cards for enrolled course students') }}"
            >
                <x-icon name="users" class="w-4 h-4 text-zinc-400" />
                {{ __('Sync Students') }}
            </button>

            <!-- Export Dropdown -->
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition"
                    >
                        <x-icon name="download" class="w-4 h-4 text-zinc-400" />
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="w-3.5 h-3.5 text-zinc-400 ml-0.5" />
                    </button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Roster') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            <!-- Import Members Button -->
            <button
                type="button"
                wire:click="openImportModal"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-blue-200 dark:border-blue-800/60 bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition"
            >
                <x-icon name="upload" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                {{ __('Import Members') }}
            </button>

            <button
                type="button"
                wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl bg-blue-600 hover:bg-blue-700 text-white shadow-sm transition"
            >
                <x-icon name="user-plus" class="w-4 h-4" />
                {{ __('Add Library Member') }}
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Total Members') }}</span>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($this->stats['total']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-950/20 shadow-xs">
            <span class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Enrolled Students') }}</span>
            <div class="mt-1 text-2xl font-bold text-blue-900 dark:text-blue-200">{{ number_format($this->stats['students']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-purple-200 dark:border-purple-900/40 bg-purple-50/50 dark:bg-purple-950/20 shadow-xs">
            <span class="text-xs font-medium text-purple-700 dark:text-purple-400">{{ __('External Borrowers') }}</span>
            <div class="mt-1 text-2xl font-bold text-purple-900 dark:text-purple-200">{{ number_format($this->stats['external']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50/50 dark:bg-emerald-950/20 shadow-xs">
            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ __('Active Memberships') }}</span>
            <div class="mt-1 text-2xl font-bold text-emerald-900 dark:text-emerald-200">{{ number_format($this->stats['active_members']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-950/20 shadow-xs">
            <span class="text-xs font-medium text-amber-700 dark:text-amber-400">{{ __('Total Deposits Held') }}</span>
            <div class="mt-1 text-2xl font-bold text-amber-900 dark:text-amber-200">₹{{ number_format($this->stats['total_deposits'], 2) }}</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col sm:flex-row items-center gap-3">
        <div class="relative flex-1 w-full">
            <x-icon name="magnifying-glass" class="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by member name, card #, student ID, email, or phone...') }}"
                class="w-full pl-9 pr-4 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:ring-2 focus:ring-blue-500"
            />
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <select
                wire:model.live="typeFilter"
                class="w-full sm:w-40 px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
            >
                <option value="">{{ __('All Member Types') }}</option>
                <option value="student">{{ __('Student') }}</option>
                <option value="external">{{ __('External') }}</option>
                <option value="staff">{{ __('Staff') }}</option>
            </select>

            <select
                wire:model.live="statusFilter"
                class="w-full sm:w-36 px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
            >
                <option value="">{{ __('All Statuses') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="suspended">{{ __('Suspended') }}</option>
                <option value="expired">{{ __('Expired') }}</option>
            </select>
        </div>
    </div>

    <!-- Members Table -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3.5">{{ __('Member') }}</th>
                        <th class="px-4 py-3.5">{{ __('Card #') }}</th>
                        <th class="px-4 py-3.5">{{ __('Type') }}</th>
                        <th class="px-4 py-3.5">{{ __('Active Deposit') }}</th>
                        <th class="px-4 py-3.5">{{ __('Books Issued') }}</th>
                        <th class="px-4 py-3.5">{{ __('Status') }}</th>
                        <th class="px-4 py-3.5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-zinc-700 dark:text-zinc-300">
                    @forelse($this->members as $member)
                        @php
                            $deposit = $member->getActiveSecurityDeposit();
                        @endphp
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 font-bold text-xs flex items-center justify-center shrink-0">
                                        {{ strtoupper(substr($member->name, 0, 2)) }}
                                    </div>
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $member->name }}</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            @if($member->email) {{ $member->email }} &bull; @endif
                                            {{ $member->phone ?: 'No phone' }}
                                        </div>
                                        @if($member->student_id)
                                            <div class="text-xs text-blue-600 dark:text-blue-400">
                                                Roll / Student ID: <span class="font-mono">{{ $member->student_id }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $member->member_number }}
                            </td>
                            <td class="px-4 py-3.5">
                                @if($member->member_type === 'student')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">
                                        {{ __('Student') }}
                                    </span>
                                @elseif($member->member_type === 'staff')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300">
                                        {{ __('Staff') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                        {{ __('External') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="font-semibold {{ $deposit > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400' }}">
                                    ₹{{ number_format($deposit, 2) }}
                                </span>
                                @if($member->security_deposit_override > 0)
                                    <span class="block text-2xs text-amber-600 dark:text-amber-400">{{ __('(Manual override)') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $member->active_issues_count }}</span>
                                <span class="text-zinc-400 text-xs">/ {{ $member->max_books_allowed }} max</span>
                            </td>
                            <td class="px-4 py-3.5">
                                @if($member->status === 'active')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                                        {{ __('Active') }}
                                    </span>
                                @elseif($member->status === 'suspended')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">
                                        {{ __('Suspended') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400">
                                        {{ __('Expired') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        wire:click="openCardModal({{ $member->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-blue-50 dark:bg-blue-950/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 border border-blue-200 dark:border-blue-900/50 transition"
                                        title="{{ __('View and Print Library Card') }}"
                                    >
                                        <x-icon name="id-card" class="w-3.5 h-3.5" />
                                        {{ __('Card') }}
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="openEditModal({{ $member->id }})"
                                        class="inline-flex items-center p-1 text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 rounded"
                                        title="{{ __('Edit Member Details') }}"
                                    >
                                        <x-icon name="pencil" class="w-4 h-4" />
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="toggleMemberStatus({{ $member->id }})"
                                        class="inline-flex items-center p-1 {{ $member->status === 'active' ? 'text-amber-500 hover:text-amber-700' : 'text-emerald-500 hover:text-emerald-700' }} rounded"
                                        title="{{ $member->status === 'active' ? __('Suspend Card') : __('Activate Card') }}"
                                    >
                                        <x-icon name="{{ $member->status === 'active' ? 'pause' : 'play' }}" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                                <x-icon name="user-group" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                                <div class="font-medium text-sm">{{ __('No library members found.') }}</div>
                                <div class="text-xs text-zinc-400 mt-1">{{ __('Try searching with another keyword or click "Sync Students" to enroll course students.') }}</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->members->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $this->members->links() }}
            </div>
        @endif
    </div>

    <!-- Member Create/Edit Modal -->
    @if($showMemberModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="user-circle" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                        {{ $editingMemberId ? __('Edit Library Member Details') : __('Register New Library Member') }}
                    </h3>
                    <button type="button" wire:click="$set('showMemberModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <!-- Link User (Optional / Search) -->
                    @if(!$editingMemberId)
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Quick Link System User (Student or Staff)') }}
                            </label>
                            <div class="relative">
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="userSearchQuery"
                                    placeholder="{{ __('Type user name or roll number to auto-fill...') }}"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                                />
                                @if(!empty($this->userSearchResults))
                                    <div class="absolute left-0 right-0 top-full mt-1 z-30 max-h-48 overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 shadow-xl divide-y divide-zinc-100 dark:divide-zinc-700">
                                        @foreach($this->userSearchResults as $u)
                                            <button
                                                type="button"
                                                wire:click="selectUser({{ $u->id }})"
                                                class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 dark:hover:bg-blue-950/30 transition flex items-center justify-between"
                                            >
                                                <div>
                                                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $u->name }}</span>
                                                    <span class="text-zinc-500">({{ $u->email }})</span>
                                                </div>
                                                @if($u->student_id)
                                                    <span class="text-xs font-mono text-blue-600">{{ $u->student_id }}</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Membership Type *') }}
                            </label>
                            <select
                                wire:model="memberType"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="student">{{ __('Student') }}</option>
                                <option value="external">{{ __('External Member') }}</option>
                                <option value="staff">{{ __('Faculty / Staff') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Student ID / Roll No') }}
                            </label>
                            <input
                                type="text"
                                wire:model="studentId"
                                placeholder="{{ __('e.g., STU-2026-0042') }}"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Full Name *') }}
                        </label>
                        <input
                            type="text"
                            wire:model="name"
                            placeholder="{{ __('Enter member full name') }}"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                        />
                        @error('name') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Email Address') }}
                            </label>
                            <input
                                type="email"
                                wire:model="email"
                                placeholder="{{ __('email@domain.com') }}"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Phone Number') }}
                            </label>
                            <input
                                type="text"
                                wire:model="phone"
                                placeholder="{{ __('+91 9876543210') }}"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Max Borrow Quota *') }}
                            </label>
                            <input
                                type="number"
                                wire:model="maxBooksAllowed"
                                min="1"
                                max="20"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Deposit Override (₹)') }}
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                wire:model="securityDepositOverride"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Card Status *') }}
                            </label>
                            <select
                                wire:model="status"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="active">{{ __('Active') }}</option>
                                <option value="suspended">{{ __('Suspended') }}</option>
                                <option value="expired">{{ __('Expired') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Membership Expiry Date') }}
                        </label>
                        <input
                            type="date"
                            wire:model="expiryDate"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Administrative Notes') }}
                        </label>
                        <textarea
                            wire:model="notes"
                            rows="2"
                            placeholder="{{ __('Special permissions, address verification notes...') }}"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                        ></textarea>
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showMemberModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="saveMember"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-sm"
                    >
                        {{ $editingMemberId ? __('Save Changes') : __('Register Member') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Library Card Preview & Print Modal -->
    @if($showCardModal && $cardMember && $cardPayload)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-2xl rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-lg text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="id-card" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                            {{ __('Official Library Membership Card') }}
                        </h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Cardholder: :name (:no)', ['name' => $cardMember->name, 'no' => $cardMember->member_number]) }}
                        </p>
                    </div>
                    <button type="button" wire:click="$set('showCardModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-6 max-h-[75vh] overflow-y-auto">
                    <!-- Configurable Print Options Bar -->
                    <div class="p-4 rounded-xl border border-blue-200 dark:border-blue-900/50 bg-blue-50/50 dark:bg-blue-950/20">
                        <span class="block text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-300 mb-2">
                            {{ __('Card Print & Download Settings') }}
                        </span>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    wire:model.live="cardWithDeposit"
                                    class="w-4 h-4 text-blue-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-blue-500"
                                />
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ __('Print Security Deposit Amount Details') }}
                                </span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    wire:model.live="cardWithIssuedBooks"
                                    class="w-4 h-4 text-blue-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-blue-500"
                                />
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ __('Print Active Issued Books Log') }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Digital Card Mockup (Front Side) -->
                    <div class="rounded-2xl border-2 border-blue-500/30 bg-gradient-to-br from-blue-900 via-indigo-900 to-slate-900 p-6 text-white shadow-xl relative overflow-hidden">
                        <div class="absolute -right-10 -bottom-10 w-44 h-44 rounded-full bg-blue-500/10 blur-2xl"></div>

                        <div class="flex items-start justify-between border-b border-white/15 pb-4">
                            <div>
                                <h4 class="font-extrabold text-base tracking-wide">{{ $cardPayload['institution_name'] }}</h4>
                                <p class="text-xs text-blue-200">{{ $cardPayload['institution_sub'] }} &bull; Library Division</p>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-2xs font-bold uppercase tracking-wider bg-white/20 text-white border border-white/30">
                                {{ $cardPayload['member_type'] }}
                            </span>
                        </div>

                        <div class="mt-5 flex gap-5 items-center">
                            <!-- Avatar / Photo -->
                            <div class="w-20 h-24 rounded-xl bg-white/10 border-2 border-white/30 flex items-center justify-center shrink-0 overflow-hidden shadow-inner">
                                @if($cardMember->photoUrl())
                                    <img src="{{ $cardMember->photoUrl() }}" alt="{{ $cardMember->name }}" class="w-full h-full object-cover" />
                                @else
                                    <x-icon name="user" class="w-10 h-10 text-white/50" />
                                @endif
                            </div>

                            <!-- Details -->
                            <div class="flex-1 space-y-1">
                                <h3 class="text-lg font-bold text-white">{{ $cardPayload['name'] }}</h3>
                                <div class="font-mono text-xs text-blue-200 tracking-wider">
                                    Card No: <span class="font-bold text-white">{{ $cardPayload['member_number'] }}</span>
                                </div>
                                @if($cardPayload['student_id'] !== 'N/A')
                                    <div class="text-xs text-blue-200">
                                        Roll No: <span class="font-mono text-white">{{ $cardPayload['student_id'] }}</span>
                                    </div>
                                @endif
                                <div class="text-xs text-blue-200">
                                    Valid Till: <span class="text-white">{{ $cardPayload['expiry_date'] }}</span>
                                </div>

                                @if($cardWithDeposit)
                                    <div class="mt-2 inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg bg-emerald-500/20 border border-emerald-400/30 text-emerald-300 text-xs font-semibold">
                                        <span>Security Deposit:</span>
                                        <strong>₹{{ number_format($cardPayload['security_deposit_amount'], 2) }}</strong>
                                    </div>
                                @endif
                            </div>

                            <!-- QR Code Preview -->
                            <div class="p-1.5 bg-white rounded-xl shrink-0 shadow-md">
                                <img src="{{ $cardPayload['qr_uri'] }}" alt="QR" class="w-20 h-20" />
                            </div>
                        </div>

                        <!-- Barcode strip -->
                        <div class="mt-4 pt-3 border-t border-white/15 flex items-center justify-between">
                            <img src="{{ $cardPayload['barcode_uri'] }}" alt="Barcode" class="h-10 bg-white p-1 rounded" />
                            <span class="text-2xs font-mono text-blue-300">{{ $cardPayload['member_number'] }}</span>
                        </div>
                    </div>

                    <!-- Card Back Mockup (When active issued books selected) -->
                    @if($cardWithIssuedBooks)
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 p-4 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300 uppercase tracking-wider">
                                    {{ __('Active Borrowed Books (Back Entry)') }}
                                </span>
                                <span class="text-xs font-semibold text-zinc-500">
                                    {{ count($cardPayload['issued_books']) }} {{ __('Book(s)') }}
                                </span>
                            </div>

                            @if(empty($cardPayload['issued_books']))
                                <p class="text-xs text-zinc-400 italic">
                                    {{ __('No books currently issued to this member. Blank borrower register printed on card reverse.') }}
                                </p>
                            @else
                                <div class="divide-y divide-zinc-200 dark:divide-zinc-700 text-xs">
                                    @foreach($cardPayload['issued_books'] as $book)
                                        <div class="py-1.5 flex items-center justify-between">
                                            <div>
                                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $book['title'] }}</div>
                                                <div class="text-2xs text-zinc-400">Acc #: {{ $book['accession_no'] }} &bull; Issued: {{ $book['issue_date'] }}</div>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-2xs font-semibold text-amber-600 dark:text-amber-400">Due: {{ $book['due_date'] }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showCardModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Close') }}
                    </button>

                    <a
                        href="{{ route('library.card.download', ['member' => $cardMember->id, 'include_deposit' => $cardWithDeposit ? 1 : 0, 'include_books' => $cardWithIssuedBooks ? 1 : 0]) }}"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-sm transition"
                    >
                        <x-icon name="arrow-down-tray" class="w-4 h-4" />
                        {{ __('Download Official PDF Card') }}
                    </a>
                </div>
            </div>
        </div>
    @endif

    <!-- Import Members Modal -->
    @if($showImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="upload" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                        {{ __('Bulk Import Library Members (CSV / Excel)') }}
                    </h3>
                    <button type="button" wire:click="$set('showImportModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/60 rounded-xl border border-zinc-200 dark:border-zinc-700/60 text-xs text-zinc-600 dark:text-zinc-300 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Need a pre-filled starting template?') }}</span>
                        <button
                            type="button"
                            wire:click="downloadTemplate"
                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900/60 border border-blue-200 dark:border-blue-800 transition"
                        >
                            <x-icon name="download" class="w-3.5 h-3.5" />
                            {{ __('Download Sample CSV') }}
                        </button>
                    </div>
                    <p class="font-mono text-[11px] text-zinc-700 dark:text-zinc-300">
                        {{ __('Headers: Member_Number, Name, Email, Phone, Member_Type, Student_ID, Gender, Joining_Date, Expiry_Date, Status') }}
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        {{ __('If Member_Number is blank, card numbers will be automatically generated.') }}
                    </p>
                </div>

                <form wire:submit="submitImport" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-900 dark:text-zinc-100 mb-1">{{ __('Select CSV or Excel (.xlsx/.xls) File *') }}</label>
                        <input
                            type="file"
                            wire:model="importFile"
                            accept=".csv,.xlsx,.xls"
                            class="w-full text-xs file:mr-2 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-950 dark:file:text-blue-300 hover:file:bg-blue-100 cursor-pointer"
                            required
                        />
                        <div wire:loading wire:target="importFile" class="text-xs text-blue-600 dark:text-blue-400 mt-1 flex items-center gap-1.5">
                            <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                            {{ __('Uploading file...') }}
                        </div>
                        @error('importFile') <span class="text-xs text-rose-600 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    @if($importSummary)
                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/80 rounded-xl border border-zinc-200 dark:border-zinc-700 text-xs space-y-1">
                            <div class="text-emerald-600 dark:text-emerald-400 font-bold flex items-center gap-1.5">
                                <x-icon name="check-circle" class="w-4 h-4" />
                                {{ __('Successfully imported: :count members', ['count' => $importSummary['imported']]) }}
                            </div>
                            <div class="text-amber-600 dark:text-amber-400 font-bold flex items-center gap-1.5">
                                <x-icon name="alert-circle" class="w-4 h-4" />
                                {{ __('Skipped / duplicates: :count', ['count' => $importSummary['skipped']]) }}
                            </div>
                            @if(! empty($importSummary['errors']))
                                <div class="text-rose-600 dark:text-rose-400 font-semibold pt-1 border-t border-zinc-200 dark:border-zinc-700">
                                    {{ __('Errors Encountered:') }}
                                    <ul class="list-disc pl-4 text-[11px] font-normal max-h-24 overflow-y-auto mt-1 space-y-0.5">
                                        @foreach($importSummary['errors'] as $err)
                                            <li>{{ $err }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <button
                            type="button"
                            wire:click="$set('showImportModal', false)"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition"
                        >
                            {{ __('Close') }}
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-xs transition"
                        >
                            <span wire:loading.remove wire:target="submitImport">{{ __('Process Import') }}</span>
                            <span wire:loading wire:target="submitImport" class="flex items-center gap-1">
                                <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                                {{ __('Processing...') }}
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
