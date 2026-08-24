<?php

use App\Exports\TableExport;
use App\Imports\TableImport;
use App\Models\ContactDepartment;
use App\Models\ContactSubject;
use App\Models\ContactSubmission;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new #[Layout('layouts.app')] #[Title('Contact Submissions & Inquiries')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    // Active Main Tab: 'submissions', 'departments', 'subjects'
    #[Url(as: 'tab')]
    public string $activeTab = 'submissions';

    // Submissions filter state
    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'priority')]
    public string $priorityFilter = 'all';

    #[Url(as: 'dept')]
    public string $departmentFilter = 'all';

    public bool $showTrashed = false;

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';

    // View & Respond modal state
    public bool $detailsModalOpen = false;
    public ?int $selectedSubmissionId = null;
    public ?ContactSubmission $selectedSubmission = null;
    public string $adminResponseNotes = '';
    public string $updateStatus = '';
    public string $updatePriority = '';

    // Create / Edit Submission modal state
    public bool $submissionFormModalOpen = false;
    public ?int $editingSubmissionId = null;
    public array $submissionForm = [
        'name' => '',
        'email' => '',
        'mobile' => '',
        'whatsapp' => '',
        'department_id' => '',
        'subject_id' => '',
        'custom_subject' => '',
        'message' => '',
        'status' => ContactSubmission::STATUS_NEW,
        'priority' => ContactSubmission::PRIORITY_MEDIUM,
        'admin_notes' => '',
    ];

    // Department modal state
    public bool $departmentModalOpen = false;
    public ?int $editingDepartmentId = null;
    public array $departmentForm = [
        'name' => '',
        'code' => '',
        'email' => '',
        'description' => '',
        'sort_order' => 0,
        'is_active' => true,
    ];

    // Subject modal state
    public bool $subjectModalOpen = false;
    public ?int $editingSubjectId = null;
    public array $subjectForm = [
        'department_id' => '',
        'name' => '',
        'code' => '',
        'sort_order' => 0,
        'is_active' => true,
    ];

    // Import state
    public bool $importModalOpen = false;
    public $importFile = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPriorityFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDepartmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedShowTrashed(): void
    {
        $this->resetPage();
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

    /* ----------------------------------------------------------------- *
     *  Computed Queries
     * ----------------------------------------------------------------- */

    #[Computed]
    public function departments()
    {
        return ContactDepartment::ordered()->get();
    }

    #[Computed]
    public function subjects()
    {
        return ContactSubject::with('department')->ordered()->get();
    }

    #[Computed]
    public function submissions()
    {
        $query = ContactSubmission::query()
            ->with(['department', 'subject', 'responder'])
            ->when($this->showTrashed, fn (Builder $q) => $q->onlyTrashed(), fn (Builder $q) => $q->withoutTrashed())
            ->when($this->statusFilter !== 'all', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->priorityFilter !== 'all', fn (Builder $q) => $q->where('priority', $this->priorityFilter))
            ->when($this->departmentFilter !== 'all', fn (Builder $q) => $q->where('department_id', $this->departmentFilter))
            ->when(trim($this->search) !== '', function (Builder $q) {
                $term = trim($this->search);
                $q->where(function (Builder $sub) use ($term) {
                    $sub->where('reference_no', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "%{$term}%")
                        ->orWhere('custom_subject', 'like', "%{$term}%")
                        ->orWhere('message', 'like', "%{$term}%");
                });
            });

        if (in_array($this->sortField, ['reference_no', 'name', 'status', 'priority', 'created_at', 'replied_at'], true)) {
            $query->orderBy($this->sortField, $this->sortDirection);
        } else {
            $query->latest();
        }

        return $query->paginate(10);
    }

    #[Computed]
    public function stats()
    {
        return [
            'total' => ContactSubmission::count(),
            'new' => ContactSubmission::where('status', ContactSubmission::STATUS_NEW)->count(),
            'in_progress' => ContactSubmission::where('status', ContactSubmission::STATUS_IN_PROGRESS)->count(),
            'resolved' => ContactSubmission::where('status', ContactSubmission::STATUS_RESOLVED)->count(),
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Submission Details & Response Handling
     * ----------------------------------------------------------------- */

    public function viewDetails(int $id): void
    {
        $this->selectedSubmissionId = $id;
        $this->selectedSubmission = ContactSubmission::withTrashed()->with(['department', 'subject', 'responder'])->findOrFail($id);
        $this->adminResponseNotes = $this->selectedSubmission->admin_notes ?? '';
        $this->updateStatus = $this->selectedSubmission->status;
        $this->updatePriority = $this->selectedSubmission->priority;
        $this->detailsModalOpen = true;
    }

    public function updateSubmissionStatus(): void
    {
        if (! $this->selectedSubmission) {
            return;
        }

        try {
            DB::transaction(function () {
                $sub = $this->selectedSubmission;
                $oldStatus = $sub->status;

                $sub->status = $this->updateStatus;
                $sub->priority = $this->updatePriority;
                $sub->admin_notes = $this->adminResponseNotes;

                if ($this->updateStatus === ContactSubmission::STATUS_REPLIED || $this->updateStatus === ContactSubmission::STATUS_RESOLVED) {
                    $sub->replied_at ??= now();
                    $sub->replied_by ??= Auth::id();
                }

                $sub->save();

                AuditLogService::log(
                    event: 'contact.updated',
                    description: "Updated contact inquiry [{$sub->reference_no}] status from {$oldStatus} to {$sub->status}",
                    auditable: $sub
                );
            });

            $this->detailsModalOpen = false;
            Toast::dispatch($this, 'success', __('Inquiry record updated successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update inquiry.'));
        }
    }

    /* ----------------------------------------------------------------- *
     *  Create / Edit Submission
     * ----------------------------------------------------------------- */

    public function openCreateSubmissionModal(): void
    {
        $this->resetValidation();
        $this->editingSubmissionId = null;
        $this->submissionForm = [
            'name' => '',
            'email' => '',
            'mobile' => '',
            'whatsapp' => '',
            'department_id' => '',
            'subject_id' => '',
            'custom_subject' => '',
            'message' => '',
            'status' => ContactSubmission::STATUS_NEW,
            'priority' => ContactSubmission::PRIORITY_MEDIUM,
            'admin_notes' => '',
        ];
        $this->submissionFormModalOpen = true;
    }

    public function editSubmission(int $id): void
    {
        $this->resetValidation();
        $sub = ContactSubmission::withTrashed()->findOrFail($id);
        $this->editingSubmissionId = $sub->id;

        $this->submissionForm = [
            'name' => $sub->name,
            'email' => $sub->email,
            'mobile' => $sub->mobile,
            'whatsapp' => $sub->whatsapp ?? '',
            'department_id' => (string) ($sub->department_id ?? ''),
            'subject_id' => (string) ($sub->subject_id ?? ''),
            'custom_subject' => $sub->custom_subject ?? '',
            'message' => $sub->message,
            'status' => $sub->status,
            'priority' => $sub->priority,
            'admin_notes' => $sub->admin_notes ?? '',
        ];
        $this->submissionFormModalOpen = true;
    }

    public function saveSubmission(): void
    {
        $this->validate([
            'submissionForm.name' => ['required', 'string', 'max:100'],
            'submissionForm.email' => ['required', 'email', 'max:150'],
            'submissionForm.mobile' => ['required', 'string', 'max:25'],
            'submissionForm.message' => ['required', 'string', 'min:5'],
        ]);

        try {
            DB::transaction(function () {
                $isNew = ! $this->editingSubmissionId;
                $sub = $this->editingSubmissionId
                    ? ContactSubmission::withTrashed()->findOrFail($this->editingSubmissionId)
                    : new ContactSubmission();

                $sub->name = trim($this->submissionForm['name']);
                $sub->email = strtolower(trim($this->submissionForm['email']));
                $sub->mobile = trim($this->submissionForm['mobile']);
                $sub->whatsapp = filled($this->submissionForm['whatsapp']) ? trim($this->submissionForm['whatsapp']) : null;
                $sub->department_id = filled($this->submissionForm['department_id']) ? (int) $this->submissionForm['department_id'] : null;
                $sub->subject_id = filled($this->submissionForm['subject_id']) ? (int) $this->submissionForm['subject_id'] : null;
                $sub->custom_subject = filled($this->submissionForm['custom_subject']) ? trim($this->submissionForm['custom_subject']) : null;
                $sub->message = trim($this->submissionForm['message']);
                $sub->status = $this->submissionForm['status'];
                $sub->priority = $this->submissionForm['priority'];
                $sub->admin_notes = filled($this->submissionForm['admin_notes']) ? trim($this->submissionForm['admin_notes']) : null;

                if ($isNew) {
                    $sub->reference_no = ContactSubmission::generateReferenceNumber();
                }

                $sub->save();

                AuditLogService::log(
                    event: $isNew ? 'contact.created' : 'contact.updated',
                    description: ($isNew ? 'Manually recorded contact inquiry: ' : 'Updated contact inquiry: ').$sub->reference_no,
                    auditable: $sub
                );
            });

            $this->submissionFormModalOpen = false;
            Toast::dispatch($this, 'success', $this->editingSubmissionId ? __('Submission updated.') : __('Submission logged successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save submission.'));
        }
    }

    public function deleteSubmission(int $id): void
    {
        try {
            $sub = ContactSubmission::findOrFail($id);
            $sub->deleted_by = Auth::id();
            $sub->save();
            $sub->delete();

            AuditLogService::log(
                event: 'contact.deleted',
                description: "Soft deleted contact submission [{$sub->reference_no}]",
                auditable: $sub
            );

            Toast::dispatch($this, 'success', __('Inquiry moved to trash.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete inquiry.'));
        }
    }

    public function restoreSubmission(int $id): void
    {
        try {
            $sub = ContactSubmission::onlyTrashed()->findOrFail($id);
            $sub->restore();

            AuditLogService::log(
                event: 'contact.restored',
                description: "Restored contact submission [{$sub->reference_no}]",
                auditable: $sub
            );

            Toast::dispatch($this, 'success', __('Inquiry restored.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to restore inquiry.'));
        }
    }

    public function forceDeleteSubmission(int $id): void
    {
        try {
            $sub = ContactSubmission::onlyTrashed()->findOrFail($id);
            $ref = $sub->reference_no;
            $sub->forceDelete();

            AuditLogService::log(
                event: 'contact.permanently_deleted',
                description: "Permanently deleted contact submission [{$ref}]"
            );

            Toast::dispatch($this, 'success', __('Inquiry permanently deleted.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete record.'));
        }
    }

    /* ----------------------------------------------------------------- *
     *  Export & Import
     * ----------------------------------------------------------------- */

    public function exportSubmissions(string $format = 'csv')
    {
        $filename = 'contact_submissions_'.date('Y_m_d_His').'.'.$format;

        $query = ContactSubmission::query()
            ->with(['department', 'subject'])
            ->when($this->statusFilter !== 'all', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->priorityFilter !== 'all', fn (Builder $q) => $q->where('priority', $this->priorityFilter))
            ->latest();

        $headings = [
            'Reference No',
            'Name',
            'Email',
            'Mobile',
            'WhatsApp',
            'Department',
            'Subject',
            'Status',
            'Priority',
            'Message',
            'Submitted Date',
        ];

        $mapper = function (ContactSubmission $sub) {
            return [
                $sub->reference_no,
                $sub->name,
                $sub->email,
                $sub->mobile,
                $sub->whatsapp ?? '',
                $sub->department_name,
                $sub->subject_title,
                ucfirst($sub->status),
                ucfirst($sub->priority),
                $sub->message,
                $sub->created_at->format('Y-m-d H:i:s'),
            ];
        };

        return Excel::download(new TableExport($query, $headings, $mapper), $filename);
    }

    public function importSubmissions(): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        try {
            Excel::import(new TableImport(
                rules: [
                    'name' => 'required',
                    'email' => 'required|email',
                    'mobile' => 'required',
                    'message' => 'required',
                ],
                rowHandler: function (array $row) {
                    return ContactSubmission::create([
                        'reference_no' => ContactSubmission::generateReferenceNumber(),
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'mobile' => $row['mobile'],
                        'whatsapp' => $row['whatsapp'] ?? null,
                        'custom_subject' => $row['subject'] ?? ($row['custom_subject'] ?? null),
                        'message' => $row['message'],
                        'status' => ContactSubmission::STATUS_NEW,
                        'priority' => ContactSubmission::PRIORITY_MEDIUM,
                    ]);
                }
            ), $this->importFile);

            $this->importModalOpen = false;
            $this->reset('importFile');
            Toast::dispatch($this, 'success', __('Contact submissions imported successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :msg', ['msg' => $e->getMessage()]));
        }
    }

    /* ----------------------------------------------------------------- *
     *  Department Management
     * ----------------------------------------------------------------- */

    public function openCreateDepartmentModal(): void
    {
        $this->resetValidation();
        $this->editingDepartmentId = null;
        $maxSort = (int) (ContactDepartment::max('sort_order') ?? 0);

        $this->departmentForm = [
            'name' => '',
            'code' => '',
            'email' => '',
            'description' => '',
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ];
        $this->departmentModalOpen = true;
    }

    public function editDepartment(int $id): void
    {
        $this->resetValidation();
        $dept = ContactDepartment::findOrFail($id);
        $this->editingDepartmentId = $dept->id;
        $this->departmentForm = [
            'name' => $dept->name,
            'code' => $dept->code,
            'email' => $dept->email ?? '',
            'description' => $dept->description ?? '',
            'sort_order' => $dept->sort_order,
            'is_active' => (bool) $dept->is_active,
        ];
        $this->departmentModalOpen = true;
    }

    public function saveDepartment(): void
    {
        $this->validate([
            'departmentForm.name' => ['required', 'string', 'max:100'],
            'departmentForm.code' => ['required', 'string', 'max:50', 'unique:contact_departments,code,'.($this->editingDepartmentId ?? 'NULL').',id'],
            'departmentForm.email' => ['nullable', 'email'],
            'departmentForm.sort_order' => ['required', 'integer'],
        ]);

        try {
            $dept = $this->editingDepartmentId
                ? ContactDepartment::findOrFail($this->editingDepartmentId)
                : new ContactDepartment();

            $dept->fill($this->departmentForm);
            $dept->save();

            $this->departmentModalOpen = false;
            Toast::dispatch($this, 'success', $this->editingDepartmentId ? __('Department updated.') : __('Department created.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save department.'));
        }
    }

    public function deleteDepartment(int $id): void
    {
        try {
            $dept = ContactDepartment::findOrFail($id);
            $dept->delete();
            Toast::dispatch($this, 'success', __('Department deleted.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete department.'));
        }
    }

    /* ----------------------------------------------------------------- *
     *  Subject Management
     * ----------------------------------------------------------------- */

    public function openCreateSubjectModal(): void
    {
        $this->resetValidation();
        $this->editingSubjectId = null;
        $maxSort = (int) (ContactSubject::max('sort_order') ?? 0);

        $this->subjectForm = [
            'department_id' => $this->departments->first()?->id ?? '',
            'name' => '',
            'code' => '',
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ];
        $this->subjectModalOpen = true;
    }

    public function editSubject(int $id): void
    {
        $this->resetValidation();
        $subj = ContactSubject::findOrFail($id);
        $this->editingSubjectId = $subj->id;
        $this->subjectForm = [
            'department_id' => (string) ($subj->department_id ?? ''),
            'name' => $subj->name,
            'code' => $subj->code ?? '',
            'sort_order' => $subj->sort_order,
            'is_active' => (bool) $subj->is_active,
        ];
        $this->subjectModalOpen = true;
    }

    public function saveSubject(): void
    {
        $this->validate([
            'subjectForm.name' => ['required', 'string', 'max:150'],
            'subjectForm.department_id' => ['nullable', 'exists:contact_departments,id'],
            'subjectForm.sort_order' => ['required', 'integer'],
        ]);

        try {
            $subj = $this->editingSubjectId
                ? ContactSubject::findOrFail($this->editingSubjectId)
                : new ContactSubject();

            $subj->fill($this->subjectForm);
            $subj->save();

            $this->subjectModalOpen = false;
            Toast::dispatch($this, 'success', $this->editingSubjectId ? __('Subject updated.') : __('Subject created.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save subject.'));
        }
    }

    public function deleteSubject(int $id): void
    {
        try {
            $subj = ContactSubject::findOrFail($id);
            $subj->delete();
            Toast::dispatch($this, 'success', __('Subject deleted.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete subject.'));
        }
    }
}; ?>

<div class="space-y-6">
    {{-- Header with Stats and Actions --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-2xs">
                    <x-icon name="mail" class="h-5 w-5"/>
                </span>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground">{{ __('Contact Inquiries & Helpdesk') }}</h1>
                    <p class="text-xs sm:text-sm text-muted-foreground">{{ __('Manage student inquiries, responses, department routing, and subject categories.') }}</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($activeTab === 'submissions')
                <button
                    type="button"
                    wire:click="exportSubmissions('csv')"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3.5 py-2 text-xs font-semibold text-foreground hover:bg-secondary transition-colors cursor-pointer shadow-2xs"
                >
                    <x-icon name="download" class="h-3.5 w-3.5"/>
                    <span>{{ __('Export CSV') }}</span>
                </button>

                <button
                    type="button"
                    wire:click="$set('importModalOpen', true)"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3.5 py-2 text-xs font-semibold text-foreground hover:bg-secondary transition-colors cursor-pointer shadow-2xs"
                >
                    <x-icon name="upload" class="h-3.5 w-3.5"/>
                    <span>{{ __('Import') }}</span>
                </button>

                <button
                    type="button"
                    wire:click="openCreateSubmissionModal"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                >
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('Log Inquiry') }}</span>
                </button>
            @elseif ($activeTab === 'departments')
                <button
                    type="button"
                    wire:click="openCreateDepartmentModal"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                >
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('Add Department') }}</span>
                </button>
            @elseif ($activeTab === 'subjects')
                <button
                    type="button"
                    wire:click="openCreateSubjectModal"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                >
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('Add Subject') }}</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Quick KPI Stat Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="rounded-2xl border border-border bg-card p-4 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Submissions') }}</span>
                <x-icon name="inbox" class="h-4 w-4 text-muted-foreground"/>
            </div>
            <p class="text-2xl font-extrabold text-foreground mt-2 font-mono">{{ number_format($this->stats['total']) }}</p>
        </div>

        <div class="rounded-2xl border border-border bg-card p-4 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wider">{{ __('New / Unread') }}</span>
                <span class="flex h-2 w-2 rounded-full bg-blue-500 animate-pulse"></span>
            </div>
            <p class="text-2xl font-extrabold text-blue-600 dark:text-blue-400 mt-2 font-mono">{{ number_format($this->stats['new']) }}</p>
        </div>

        <div class="rounded-2xl border border-border bg-card p-4 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('In Progress') }}</span>
                <x-icon name="clock" class="h-4 w-4 text-amber-500"/>
            </div>
            <p class="text-2xl font-extrabold text-amber-600 dark:text-amber-400 mt-2 font-mono">{{ number_format($this->stats['in_progress']) }}</p>
        </div>

        <div class="rounded-2xl border border-border bg-card p-4 shadow-2xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Resolved') }}</span>
                <x-icon name="shield-check" class="h-4 w-4 text-emerald-500"/>
            </div>
            <p class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-2 font-mono">{{ number_format($this->stats['resolved']) }}</p>
        </div>
    </div>

    {{-- Main Module Tabs Bar --}}
    <div class="border-b border-border">
        <nav class="flex space-x-6">
            <button
                type="button"
                wire:click="$set('activeTab', 'submissions')"
                class="py-3 px-1 border-b-2 text-xs sm:text-sm font-semibold transition-all cursor-pointer {{ $activeTab === 'submissions' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <span class="flex items-center gap-2">
                    <x-icon name="inbox" class="h-4 w-4"/>
                    <span>{{ __('Inquiries & Submissions') }}</span>
                </span>
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'departments')"
                class="py-3 px-1 border-b-2 text-xs sm:text-sm font-semibold transition-all cursor-pointer {{ $activeTab === 'departments' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <span class="flex items-center gap-2">
                    <x-icon name="building-2" class="h-4 w-4"/>
                    <span>{{ __('Departments') }}</span>
                </span>
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'subjects')"
                class="py-3 px-1 border-b-2 text-xs sm:text-sm font-semibold transition-all cursor-pointer {{ $activeTab === 'subjects' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <span class="flex items-center gap-2">
                    <x-icon name="tag" class="h-4 w-4"/>
                    <span>{{ __('Inquiry Subjects') }}</span>
                </span>
            </button>
        </nav>
    </div>

    {{-- TAB 1: SUBMISSIONS --}}
    @if ($activeTab === 'submissions')
        <div class="space-y-4">
            {{-- Filter and Search Controls Bar --}}
            <div class="rounded-2xl border border-border bg-card p-4 shadow-2xs space-y-3">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="relative w-full sm:w-80">
                        <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search ref#, name, email, query…') }}"
                            class="h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 text-xs sm:text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto justify-end">
                        <select
                            wire:model.live="departmentFilter"
                            class="h-9 rounded-md border border-input bg-card px-3 text-xs sm:text-sm shadow-xs outline-none focus-visible:border-ring"
                        >
                            <option value="all">{{ __('All Departments') }}</option>
                            @foreach ($this->departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>

                        <select
                            wire:model.live="statusFilter"
                            class="h-9 rounded-md border border-input bg-card px-3 text-xs sm:text-sm shadow-xs outline-none focus-visible:border-ring"
                        >
                            <option value="all">{{ __('All Statuses') }}</option>
                            <option value="new">{{ __('New') }}</option>
                            <option value="in_progress">{{ __('In Progress') }}</option>
                            <option value="replied">{{ __('Replied') }}</option>
                            <option value="resolved">{{ __('Resolved') }}</option>
                            <option value="closed">{{ __('Closed') }}</option>
                        </select>

                        <select
                            wire:model.live="priorityFilter"
                            class="h-9 rounded-md border border-input bg-card px-3 text-xs sm:text-sm shadow-xs outline-none focus-visible:border-ring"
                        >
                            <option value="all">{{ __('All Priorities') }}</option>
                            <option value="low">{{ __('Low') }}</option>
                            <option value="medium">{{ __('Medium') }}</option>
                            <option value="high">{{ __('High') }}</option>
                            <option value="urgent">{{ __('Urgent') }}</option>
                        </select>

                        <button
                            type="button"
                            wire:click="$toggle('showTrashed')"
                            class="inline-flex items-center gap-1.5 h-9 rounded-md border px-3 text-xs font-medium transition-colors cursor-pointer {{ $showTrashed ? 'border-destructive bg-destructive/10 text-destructive' : 'border-border bg-card text-muted-foreground hover:bg-secondary' }}"
                        >
                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                            <span>{{ $showTrashed ? __('Showing Trash') : __('Trash') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Submissions Table --}}
            <div class="rounded-2xl border border-border bg-card shadow-2xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead class="bg-muted/60 border-b border-border text-muted-foreground uppercase text-[10px] tracking-wider font-semibold">
                            <tr>
                                <th class="px-4 py-3">{{ __('Ref # & Date') }}</th>
                                <th class="px-4 py-3">{{ __('Contact Person') }}</th>
                                <th class="px-4 py-3">{{ __('Department & Subject') }}</th>
                                <th class="px-4 py-3">{{ __('Status') }}</th>
                                <th class="px-4 py-3">{{ __('Priority') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($this->submissions as $sub)
                                <tr class="hover:bg-secondary/30 transition-colors {{ $sub->status === 'new' ? 'bg-blue-500/5' : '' }}" wire:key="sub-row-{{ $sub->id }}">
                                    {{-- Reference No & Date --}}
                                    <td class="px-4 py-3.5">
                                        <div class="font-mono text-xs font-bold text-foreground flex items-center gap-1.5">
                                            @if ($sub->status === 'new')
                                                <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                                            @endif
                                            <span>{{ $sub->reference_no }}</span>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground mt-0.5">{{ $sub->created_at->format('d M Y, h:i A') }}</p>
                                    </td>

                                    {{-- Contact Person --}}
                                    <td class="px-4 py-3.5">
                                        <p class="font-semibold text-foreground">{{ $sub->name }}</p>
                                        <p class="text-xs text-muted-foreground">{{ $sub->email }}</p>
                                        <p class="text-xs font-mono text-muted-foreground">{{ $sub->mobile }}</p>
                                    </td>

                                    {{-- Department & Subject --}}
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center rounded-md bg-secondary px-2 py-0.5 text-[10px] font-semibold text-secondary-foreground mb-1">
                                            {{ $sub->department_name }}
                                        </span>
                                        <p class="font-medium text-foreground truncate max-w-xs">{{ $sub->subject_title }}</p>
                                        <p class="text-xs text-muted-foreground truncate max-w-xs">{{ Str::limit($sub->message, 60) }}</p>
                                    </td>

                                    {{-- Status Badge --}}
                                    <td class="px-4 py-3.5">
                                        @php
                                            $statusClasses = match($sub->status) {
                                                'new' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
                                                'in_progress' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                                                'replied' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400',
                                                'resolved' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                                                'closed' => 'bg-muted text-muted-foreground',
                                                default => 'bg-muted text-muted-foreground',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                            <span>{{ ucfirst(str_replace('_', ' ', $sub->status)) }}</span>
                                        </span>
                                    </td>

                                    {{-- Priority --}}
                                    <td class="px-4 py-3.5">
                                        @php
                                            $prioClasses = match($sub->priority) {
                                                'urgent' => 'bg-destructive/10 text-destructive font-bold',
                                                'high' => 'bg-orange-500/10 text-orange-600 dark:text-orange-400 font-semibold',
                                                'medium' => 'bg-secondary text-secondary-foreground',
                                                'low' => 'bg-muted text-muted-foreground',
                                                default => 'bg-muted text-muted-foreground',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] uppercase tracking-wider {{ $prioClasses }}">
                                            {{ $sub->priority }}
                                        </span>
                                    </td>

                                    {{-- Actions --}}
                                    <td class="px-4 py-3.5 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            @if ($showTrashed)
                                                <button
                                                    type="button"
                                                    wire:click="restoreSubmission({{ $sub->id }})"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                                >
                                                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5 text-emerald-500"/>
                                                    <span>{{ __('Restore') }}</span>
                                                </button>

                                                <button
                                                    type="button"
                                                    wire:click="forceDeleteSubmission({{ $sub->id }})"
                                                    wire:confirm="{{ __('Are you sure you want to permanently delete this inquiry record?') }}"
                                                    class="inline-flex items-center justify-center h-7 w-7 rounded-lg border border-destructive/30 bg-destructive/10 text-destructive hover:bg-destructive/20 cursor-pointer"
                                                >
                                                    <x-icon name="trash" class="h-3.5 w-3.5"/>
                                                </button>
                                            @else
                                                <button
                                                    type="button"
                                                    wire:click="viewDetails({{ $sub->id }})"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold text-primary hover:bg-secondary transition-colors cursor-pointer shadow-2xs"
                                                    title="{{ __('View details and respond') }}"
                                                >
                                                    <x-icon name="eye" class="h-3.5 w-3.5"/>
                                                    <span>{{ __('Details') }}</span>
                                                </button>

                                                <button
                                                    type="button"
                                                    wire:click="editSubmission({{ $sub->id }})"
                                                    class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer"
                                                    title="{{ __('Edit Record') }}"
                                                >
                                                    <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                                </button>

                                                <button
                                                    type="button"
                                                    wire:click="deleteSubmission({{ $sub->id }})"
                                                    wire:confirm="{{ __('Move this inquiry to trash?') }}"
                                                    class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-destructive hover:bg-destructive/10 cursor-pointer"
                                                    title="{{ __('Delete inquiry') }}"
                                                >
                                                    <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                        <div class="max-w-xs mx-auto space-y-2">
                                            <x-icon name="mail" class="h-8 w-8 mx-auto text-muted-foreground/60"/>
                                            <p class="text-sm font-medium">{{ __('No inquiries found.') }}</p>
                                            <p class="text-xs text-muted-foreground/80">{{ __('Submissions from the public Contact Us page will appear here.') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($this->submissions->hasPages())
                    <div class="p-4 border-t border-border">
                        {{ $this->submissions->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- TAB 2: DEPARTMENTS --}}
    @if ($activeTab === 'departments')
        <div class="rounded-2xl border border-border bg-card shadow-2xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead class="bg-muted/60 border-b border-border text-muted-foreground uppercase text-[10px] tracking-wider font-semibold">
                        <tr>
                            <th class="px-4 py-3">{{ __('Order') }}</th>
                            <th class="px-4 py-3">{{ __('Department Name') }}</th>
                            <th class="px-4 py-3">{{ __('Code') }}</th>
                            <th class="px-4 py-3">{{ __('Routing Email') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->departments as $dept)
                            <tr class="hover:bg-secondary/30 transition-colors" wire:key="dept-{{ $dept->id }}">
                                <td class="px-4 py-3.5 font-mono text-xs text-muted-foreground">#{{ $dept->sort_order }}</td>
                                <td class="px-4 py-3.5">
                                    <p class="font-bold text-foreground">{{ $dept->name }}</p>
                                    @if ($dept->description)
                                        <p class="text-xs text-muted-foreground line-clamp-1">{{ $dept->description }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 font-mono text-xs">{{ $dept->code }}</td>
                                <td class="px-4 py-3.5 text-xs text-muted-foreground">{{ $dept->email ?: '—' }}</td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $dept->is_active ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-muted text-muted-foreground' }}">
                                        {{ $dept->is_active ? __('Active') : __('Disabled') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            type="button"
                                            wire:click="editDepartment({{ $dept->id }})"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer"
                                        >
                                            <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="deleteDepartment({{ $dept->id }})"
                                            wire:confirm="{{ __('Delete this department?') }}"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-destructive hover:bg-destructive/10 cursor-pointer"
                                        >
                                            <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No departments found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- TAB 3: SUBJECTS --}}
    @if ($activeTab === 'subjects')
        <div class="rounded-2xl border border-border bg-card shadow-2xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead class="bg-muted/60 border-b border-border text-muted-foreground uppercase text-[10px] tracking-wider font-semibold">
                        <tr>
                            <th class="px-4 py-3">{{ __('Order') }}</th>
                            <th class="px-4 py-3">{{ __('Subject / Topic Name') }}</th>
                            <th class="px-4 py-3">{{ __('Linked Department') }}</th>
                            <th class="px-4 py-3">{{ __('Code') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->subjects as $subj)
                            <tr class="hover:bg-secondary/30 transition-colors" wire:key="subj-{{ $subj->id }}">
                                <td class="px-4 py-3.5 font-mono text-xs text-muted-foreground">#{{ $subj->sort_order }}</td>
                                <td class="px-4 py-3.5 font-bold text-foreground">{{ $subj->name }}</td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center rounded-md bg-secondary px-2 py-0.5 text-xs font-medium">
                                        {{ $subj->department?->name ?? __('Unassigned') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 font-mono text-xs text-muted-foreground">{{ $subj->code ?: '—' }}</td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $subj->is_active ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-muted text-muted-foreground' }}">
                                        {{ $subj->is_active ? __('Active') : __('Disabled') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            type="button"
                                            wire:click="editSubject({{ $subj->id }})"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer"
                                        >
                                            <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="deleteSubject({{ $subj->id }})"
                                            wire:confirm="{{ __('Delete this subject?') }}"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-destructive hover:bg-destructive/10 cursor-pointer"
                                        >
                                            <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No inquiry subjects found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Submission View & Response Modal --}}
    @if ($detailsModalOpen && $selectedSubmission)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
            <div class="bg-card border border-border rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden ui-content-in">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-muted/40 shrink-0">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <x-icon name="mail" class="h-4 w-4"/>
                        </span>
                        <div>
                            <h3 class="text-sm font-bold text-foreground">
                                {{ __('Inquiry Details: :ref', ['ref' => $selectedSubmission->reference_no]) }}
                            </h3>
                            <p class="text-[11px] text-muted-foreground">{{ $selectedSubmission->created_at->format('d M Y, h:i A') }}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        wire:click="$set('detailsModalOpen', false)"
                        class="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-secondary text-muted-foreground hover:text-foreground cursor-pointer"
                    >
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    {{-- User Profile Card --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-muted/40 p-4 rounded-xl border border-border text-xs">
                        <div>
                            <p class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Full Name') }}</p>
                            <p class="text-sm font-bold text-foreground mt-0.5">{{ $selectedSubmission->name }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Email Address') }}</p>
                            <p class="text-sm font-semibold text-foreground mt-0.5">
                                <a href="mailto:{{ $selectedSubmission->email }}" class="text-primary hover:underline">{{ $selectedSubmission->email }}</a>
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Mobile Phone') }}</p>
                            <p class="text-sm font-mono font-semibold text-foreground mt-0.5">
                                <a href="tel:{{ $selectedSubmission->mobile }}" class="hover:underline">{{ $selectedSubmission->mobile }}</a>
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('WhatsApp') }}</p>
                            <p class="text-sm font-mono font-semibold text-foreground mt-0.5">
                                @if ($selectedSubmission->whatsapp)
                                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $selectedSubmission->whatsapp) }}" target="_blank" class="text-emerald-600 dark:text-emerald-400 hover:underline">
                                        {{ $selectedSubmission->whatsapp }}
                                    </a>
                                @else
                                    <span class="text-muted-foreground/60">—</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    {{-- Topic & Message --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-bold text-foreground flex items-center gap-1.5">
                                <x-icon name="tag" class="h-3.5 w-3.5 text-primary"/>
                                <span>{{ $selectedSubmission->department_name }} &bull; {{ $selectedSubmission->subject_title }}</span>
                            </span>
                        </div>
                        <div class="bg-background border border-border rounded-xl p-4 text-xs sm:text-sm text-foreground whitespace-pre-wrap leading-relaxed">
                            {{ $selectedSubmission->message }}
                        </div>
                    </div>

                    {{-- Status & Priority Changer --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Update Status') }}
                            </label>
                            <select
                                wire:model="updateStatus"
                                class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                            >
                                <option value="new">{{ __('New') }}</option>
                                <option value="in_progress">{{ __('In Progress') }}</option>
                                <option value="replied">{{ __('Replied') }}</option>
                                <option value="resolved">{{ __('Resolved') }}</option>
                                <option value="closed">{{ __('Closed') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Update Priority') }}
                            </label>
                            <select
                                wire:model="updatePriority"
                                class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                            >
                                <option value="low">{{ __('Low') }}</option>
                                <option value="medium">{{ __('Medium') }}</option>
                                <option value="high">{{ __('High') }}</option>
                                <option value="urgent">{{ __('Urgent') }}</option>
                            </select>
                        </div>
                    </div>

                    {{-- Admin Notes / Response Tracking --}}
                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                            {{ __('Internal Admin Notes / Response History') }}
                        </label>
                        <textarea
                            wire:model="adminResponseNotes"
                            rows="3"
                            placeholder="{{ __('Add notes about call/email responses, resolution details, or staff assignments…') }}"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs outline-none focus-visible:border-ring"
                        ></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-between px-6 py-4 border-t border-border bg-muted/40 shrink-0">
                    <div class="flex items-center gap-2">
                        <a
                            href="mailto:{{ $selectedSubmission->email }}?subject=Re: {{ urlencode($selectedSubmission->reference_no . ' — ' . $selectedSubmission->subject_title) }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground hover:bg-secondary cursor-pointer"
                        >
                            <x-icon name="mail" class="h-3.5 w-3.5 text-primary"/>
                            <span>{{ __('Reply Email') }}</span>
                        </a>

                        <a
                            href="tel:{{ $selectedSubmission->mobile }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground hover:bg-secondary cursor-pointer"
                        >
                            <x-icon name="phone" class="h-3.5 w-3.5 text-primary"/>
                            <span>{{ __('Call') }}</span>
                        </a>
                    </div>

                    <button
                        type="button"
                        wire:click="updateSubmissionStatus"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2 text-xs font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 cursor-pointer"
                    >
                        <x-icon name="save" class="h-3.5 w-3.5"/>
                        <span>{{ __('Save Changes') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Create / Edit Submission Form Modal --}}
    @if ($submissionFormModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
            <div class="bg-card border border-border rounded-2xl w-full max-w-xl flex flex-col shadow-2xl overflow-hidden ui-content-in">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-muted/40">
                    <h3 class="text-sm font-bold text-foreground">
                        {{ $editingSubmissionId ? __('Edit Inquiry Record') : __('Log New Contact Inquiry') }}
                    </h3>
                    <button type="button" wire:click="$set('submissionFormModalOpen', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Name') }} <span class="text-destructive">*</span>
                            </label>
                            <input type="text" wire:model="submissionForm.name" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs" required/>
                            @error('submissionForm.name') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Email') }} <span class="text-destructive">*</span>
                            </label>
                            <input type="email" wire:model="submissionForm.email" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs" required/>
                            @error('submissionForm.email') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Mobile') }} <span class="text-destructive">*</span>
                            </label>
                            <input type="text" wire:model="submissionForm.mobile" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs" required/>
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('WhatsApp') }}
                            </label>
                            <input type="text" wire:model="submissionForm.whatsapp" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs"/>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Department') }}
                            </label>
                            <select wire:model="submissionForm.department_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs">
                                <option value="">{{ __('Select Department') }}</option>
                                @foreach ($this->departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Subject Category') }}
                            </label>
                            <select wire:model="submissionForm.subject_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs">
                                <option value="">{{ __('Select Subject') }}</option>
                                @foreach ($this->subjects as $subj)
                                    <option value="{{ $subj->id }}">{{ $subj->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                            {{ __('Custom Subject') }}
                        </label>
                        <input type="text" wire:model="submissionForm.custom_subject" placeholder="{{ __('Optional subject description') }}" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs"/>
                    </div>

                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                            {{ __('Message') }} <span class="text-destructive">*</span>
                        </label>
                        <textarea wire:model="submissionForm.message" rows="3" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs" required></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Status') }}
                            </label>
                            <select wire:model="submissionForm.status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs">
                                <option value="new">{{ __('New') }}</option>
                                <option value="in_progress">{{ __('In Progress') }}</option>
                                <option value="replied">{{ __('Replied') }}</option>
                                <option value="resolved">{{ __('Resolved') }}</option>
                                <option value="closed">{{ __('Closed') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Priority') }}
                            </label>
                            <select wire:model="submissionForm.priority" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs">
                                <option value="low">{{ __('Low') }}</option>
                                <option value="medium">{{ __('Medium') }}</option>
                                <option value="high">{{ __('High') }}</option>
                                <option value="urgent">{{ __('Urgent') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-border bg-muted/40">
                    <button type="button" wire:click="$set('submissionFormModalOpen', false)" class="rounded-xl border border-border bg-card px-4 py-2 text-xs font-semibold">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveSubmission" class="rounded-xl bg-primary px-5 py-2 text-xs font-semibold text-primary-foreground">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Department Create/Edit Modal --}}
    @if ($departmentModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
            <div class="bg-card border border-border rounded-2xl w-full max-w-md flex flex-col shadow-2xl overflow-hidden ui-content-in">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-muted/40">
                    <h3 class="text-sm font-bold text-foreground">
                        {{ $editingDepartmentId ? __('Edit Department') : __('Create Department') }}
                    </h3>
                    <button type="button" wire:click="$set('departmentModalOpen', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="p-6 space-y-4 text-xs">
                    <div>
                        <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Department Name') }} *</label>
                        <input type="text" wire:model="departmentForm.name" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs" required/>
                        @error('departmentForm.name') <p class="mt-1 text-destructive">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Code') }} *</label>
                            <input type="text" wire:model="departmentForm.code" class="h-9 w-full rounded-md border border-input bg-transparent px-3 font-mono text-xs shadow-xs" required/>
                        </div>
                        <div>
                            <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Sort Order') }}</label>
                            <input type="number" wire:model="departmentForm.sort_order" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs"/>
                        </div>
                    </div>

                    <div>
                        <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Routing Email') }}</label>
                        <input type="email" wire:model="departmentForm.email" placeholder="{{ __('dept@sntcssc.in') }}" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs"/>
                    </div>

                    <div>
                        <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Description') }}</label>
                        <textarea wire:model="departmentForm.description" rows="2" class="w-full rounded-md border border-input bg-transparent px-3 py-1.5 text-xs shadow-xs"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="checkbox" id="dept_active" wire:model="departmentForm.is_active" class="rounded border-input text-primary focus:ring-primary"/>
                        <label for="dept_active" class="font-medium text-foreground cursor-pointer">{{ __('Active Department') }}</label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-border bg-muted/40">
                    <button type="button" wire:click="$set('departmentModalOpen', false)" class="rounded-xl border border-border bg-card px-4 py-2 text-xs font-semibold">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveDepartment" class="rounded-xl bg-primary px-5 py-2 text-xs font-semibold text-primary-foreground">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Subject Create/Edit Modal --}}
    @if ($subjectModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
            <div class="bg-card border border-border rounded-2xl w-full max-w-md flex flex-col shadow-2xl overflow-hidden ui-content-in">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-muted/40">
                    <h3 class="text-sm font-bold text-foreground">
                        {{ $editingSubjectId ? __('Edit Subject') : __('Create Subject') }}
                    </h3>
                    <button type="button" wire:click="$set('subjectModalOpen', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="p-6 space-y-4 text-xs">
                    <div>
                        <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Subject / Topic Name') }} *</label>
                        <input type="text" wire:model="subjectForm.name" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs" required/>
                        @error('subjectForm.name') <p class="mt-1 text-destructive">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Parent Department') }}</label>
                        <select wire:model="subjectForm.department_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs">
                            <option value="">{{ __('Unassigned / General') }}</option>
                            @foreach ($this->departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Code') }}</label>
                            <input type="text" wire:model="subjectForm.code" class="h-9 w-full rounded-md border border-input bg-transparent px-3 font-mono text-xs shadow-xs"/>
                        </div>
                        <div>
                            <label class="font-semibold uppercase tracking-wider text-muted-foreground block mb-1">{{ __('Sort Order') }}</label>
                            <input type="number" wire:model="subjectForm.sort_order" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs"/>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="checkbox" id="subj_active" wire:model="subjectForm.is_active" class="rounded border-input text-primary focus:ring-primary"/>
                        <label for="subj_active" class="font-medium text-foreground cursor-pointer">{{ __('Active Subject') }}</label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-border bg-muted/40">
                    <button type="button" wire:click="$set('subjectModalOpen', false)" class="rounded-xl border border-border bg-card px-4 py-2 text-xs font-semibold">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveSubject" class="rounded-xl bg-primary px-5 py-2 text-xs font-semibold text-primary-foreground">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Import Modal --}}
    @if ($importModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
            <div class="bg-card border border-border rounded-2xl w-full max-w-md flex flex-col shadow-2xl overflow-hidden ui-content-in">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-muted/40">
                    <h3 class="text-sm font-bold text-foreground">{{ __('Import Contact Submissions') }}</h3>
                    <button type="button" wire:click="$set('importModalOpen', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="p-6 space-y-4 text-xs">
                    <p class="text-muted-foreground leading-relaxed">
                        {{ __('Upload a CSV or Excel spreadsheet containing columns: name, email, mobile, subject, message.') }}
                    </p>

                    <div class="rounded-xl border-2 border-dashed border-border p-6 text-center">
                        <input type="file" wire:model="importFile" class="text-xs" accept=".csv,.xlsx,.xls"/>
                    </div>
                    @error('importFile') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-border bg-muted/40">
                    <button type="button" wire:click="$set('importModalOpen', false)" class="rounded-xl border border-border bg-card px-4 py-2 text-xs font-semibold">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="importSubmissions" class="rounded-xl bg-primary px-5 py-2 text-xs font-semibold text-primary-foreground">{{ __('Import File') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
