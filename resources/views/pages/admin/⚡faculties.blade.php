<?php

use App\Concerns\RenamesUploads;
use App\Models\AuditLog;
use App\Models\ClassSchedule;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Models\User;
use App\Services\AcademicImportService;
use App\Services\AuditLogService;
use App\Services\ClassScheduleService;
use App\Services\StudentImportExportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Faculty Master Directory')] class extends Component {
    use RenamesUploads, WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'dept')]
    public string $departmentFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

    public int $perPage = 15;

    // Form Modal State
    public ?int $editingId = null;

    public $photo = null;

    public $cv = null;

    public string $department_select = 'General Studies';

    public string $custom_department = '';

    public ?string $existing_photo_url = null;

    public ?string $existing_photo_path = null;

    public ?string $existing_cv_url = null;

    public ?string $existing_cv_name = null;

    public ?string $existing_cv_path = null;

    public bool $remove_photo = false;

    public bool $remove_cv = false;

    public const PREDEFINED_DEPARTMENTS = [
        'General Studies',
        'Geography',
        'Ancient History',
        'Medival History',
        'Medieval History',
        'Modern History',
        'Polity',
        'Economy',
        'Economics',
        'History',
        'Ethics',
        'Essay',
        'CSAT',
        'Custom',
    ];

    public array $form = [
        'name' => '',
        'email' => '',
        'phone' => '',
        'designation' => 'Faculty Member',
        'department' => 'General Studies',
        'partner_institute_id' => '',
        'faculty_type' => 'regular',
        'specialization' => '',
        'qualification' => '',
        'bio' => '',
        'subjects_raw' => '',
        'status' => 'active',
        'user_id' => '',
    ];

    // Detail Modal State
    public ?int $viewingId = null;

    public ?Faculty $viewingFaculty = null;

    // Delete Modal State
    public ?int $deleteId = null;

    public ?Faculty $deletingFaculty = null;

    // Import State
    public $importFile = null;

    public array $importErrors = [];

    public array $importResult = [];

    public function openImportModal(): void
    {
        $this->authorize('faculty.manage');
        $this->importFile = null;
        $this->importResult = [];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'faculty-import-modal');
    }

    public function downloadTemplate()
    {
        $this->authorize('faculty.manage');

        return AcademicImportService::generateFacultyTemplate();
    }

    public function processImport(): void
    {
        $this->authorize('faculty.manage');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = AcademicImportService::importFaculties($this->importFile);
            $this->importResult = $result;

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __(':count faculty members imported successfully.', ['count' => $result['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('No faculty records were imported. Review errors below.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDepartmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFormDepartment(string $value): void
    {
        $standardDepts = array_diff(self::PREDEFINED_DEPARTMENTS, ['Custom']);
        if (in_array($value, $standardDepts, true)) {
            $this->department_select = $value;
            $this->custom_department = '';
        } else {
            $this->department_select = 'Custom';
            $this->custom_department = $value;
        }
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
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Faculty::count(),
            'active' => Faculty::where('status', 'active')->count(),
            'departments' => Faculty::distinct('department')->whereNotNull('department')->count('department'),
            'total_classes' => DB::table('class_schedule_faculty')->count(),
        ];
    }

    #[Computed]
    public function departments(): array
    {
        return Faculty::whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->filter()
            ->values()
            ->all();
    }

    #[Computed]
    public function partnerInstitutes()
    {
        return PartnerInstitute::query()->orderBy('name')->get();
    }

    #[Computed]
    public function faculties()
    {
        return Faculty::query()
            ->with(['user', 'classSchedules', 'partnerInstitute'])
            ->withCount('classSchedules')
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('faculty_code', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('designation', 'like', $term)
                        ->orWhere('department', 'like', $term)
                        ->orWhere('specialization', 'like', $term);
                });
            })
            ->when($this->departmentFilter, fn ($q) => $q->where('department', $this->departmentFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->photo = null;
        $this->cv = null;
        $this->department_select = 'General Studies';
        $this->custom_department = '';
        $this->existing_photo_url = null;
        $this->existing_photo_path = null;
        $this->existing_cv_url = null;
        $this->existing_cv_name = null;
        $this->existing_cv_path = null;
        $this->remove_photo = false;
        $this->remove_cv = false;
        $this->form = [
            'name' => '',
            'email' => '',
            'phone' => '',
            'designation' => 'Faculty Member',
            'department' => 'General Studies',
            'partner_institute_id' => '',
            'faculty_type' => 'regular',
            'specialization' => '',
            'qualification' => '',
            'bio' => '',
            'subjects_raw' => '',
            'status' => 'active',
            'user_id' => '',
        ];
        $this->resetValidation();
        $this->dispatch('modal-open', name: 'faculty-form-modal');
    }

    public function edit(int $id): void
    {
        $faculty = Faculty::findOrFail($id);
        $this->editingId = $faculty->id;
        $this->photo = null;
        $this->cv = null;
        $this->existing_photo_url = $faculty->photo_url ?? $faculty->avatar_url;
        $this->existing_photo_path = $faculty->photo_path;
        $this->existing_cv_url = $faculty->cv_url;
        $this->existing_cv_name = $faculty->cv_file_name;
        $this->existing_cv_path = $faculty->cv_path;
        $this->remove_photo = false;
        $this->remove_cv = false;

        $standardDepts = array_diff(self::PREDEFINED_DEPARTMENTS, ['Custom']);
        if (in_array($faculty->department, $standardDepts, true)) {
            $this->department_select = $faculty->department;
            $this->custom_department = '';
        } else {
            $this->department_select = 'Custom';
            $this->custom_department = $faculty->department ?? '';
        }

        $this->form = [
            'name' => $faculty->name,
            'email' => $faculty->email ?? '',
            'phone' => $faculty->phone ?? '',
            'designation' => $faculty->designation ?? 'Faculty Member',
            'department' => $faculty->department ?? 'General Studies',
            'partner_institute_id' => $faculty->partner_institute_id ? (string) $faculty->partner_institute_id : '',
            'faculty_type' => $faculty->faculty_type ?? 'regular',
            'specialization' => $faculty->specialization ?? '',
            'qualification' => $faculty->qualification ?? '',
            'bio' => $faculty->bio ?? '',
            'subjects_raw' => is_array($faculty->subjects) ? implode(', ', $faculty->subjects) : '',
            'status' => $faculty->status,
            'user_id' => $faculty->user_id ? (string) $faculty->user_id : '',
        ];
        $this->resetValidation();
        $this->dispatch('modal-open', name: 'faculty-form-modal');
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => 'required|string|max:191',
            'form.email' => 'nullable|email|max:191',
            'form.phone' => 'nullable|string|max:50',
            'form.designation' => 'nullable|string|max:100',
            'department_select' => 'required|string',
            'custom_department' => 'required_if:department_select,Custom|nullable|string|max:100',
            'form.partner_institute_id' => 'nullable|exists:partner_institutes,id',
            'form.faculty_type' => 'required|in:regular,guest,visiting,contractual,adjunct',
            'photo' => 'nullable|image|max:5120',
            'cv' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'form.specialization' => 'nullable|string|max:191',
            'form.qualification' => 'nullable|string|max:191',
            'form.bio' => 'nullable|string',
            'form.status' => 'required|in:active,inactive,on_leave',
            'form.user_id' => 'nullable|exists:users,id',
        ]);

        try {
            DB::beginTransaction();

            $resolvedDept = $this->department_select === 'Custom'
                ? (trim($this->custom_department) ?: ($this->form['department'] ?? 'General Studies'))
                : ($this->department_select ?: ($this->form['department'] ?? 'General Studies'));

            $subjects = array_values(array_filter(array_map('trim', explode(',', $this->form['subjects_raw']))));

            $payload = [
                'name' => trim($this->form['name']),
                'email' => ! empty($this->form['email']) ? trim($this->form['email']) : null,
                'phone' => ! empty($this->form['phone']) ? trim($this->form['phone']) : null,
                'designation' => ! empty($this->form['designation']) ? trim($this->form['designation']) : 'Faculty Member',
                'department' => $resolvedDept ?: 'General Studies',
                'partner_institute_id' => ! empty($this->form['partner_institute_id']) ? (int) $this->form['partner_institute_id'] : null,
                'faculty_type' => $this->form['faculty_type'] ?? 'regular',
                'specialization' => ! empty($this->form['specialization']) ? trim($this->form['specialization']) : null,
                'qualification' => ! empty($this->form['qualification']) ? trim($this->form['qualification']) : null,
                'bio' => ! empty($this->form['bio']) ? trim($this->form['bio']) : null,
                'subjects' => $subjects,
                'status' => $this->form['status'],
                'user_id' => ! empty($this->form['user_id']) ? (int) $this->form['user_id'] : null,
            ];

            // Handle Photo upload with RenamesUploads & remove option
            if ($this->photo) {
                if ($this->existing_photo_path && Storage::disk('public')->exists($this->existing_photo_path)) {
                    Storage::disk('public')->delete($this->existing_photo_path);
                }
                $photoName = $this->generateFileName('faculty_photo', $this->photo, auth()->id() ?? 1);
                $photoPath = $this->photo->storeAs('faculties/photos', $photoName, 'public');
                $payload['photo_path'] = $photoPath;
                $payload['avatar_path'] = $photoPath;
            } elseif ($this->remove_photo && $this->existing_photo_path) {
                if (Storage::disk('public')->exists($this->existing_photo_path)) {
                    Storage::disk('public')->delete($this->existing_photo_path);
                }
                $payload['photo_path'] = null;
                $payload['avatar_path'] = null;
            }

            // Handle CV upload with RenamesUploads & remove option
            if ($this->cv) {
                if ($this->existing_cv_path && Storage::disk('public')->exists($this->existing_cv_path)) {
                    Storage::disk('public')->delete($this->existing_cv_path);
                }
                $cvName = $this->generateFileName('faculty_cv', $this->cv, auth()->id() ?? 1);
                $cvPath = $this->cv->storeAs('faculties/cvs', $cvName, 'public');
                $payload['cv_path'] = $cvPath;
            } elseif ($this->remove_cv && $this->existing_cv_path) {
                if (Storage::disk('public')->exists($this->existing_cv_path)) {
                    Storage::disk('public')->delete($this->existing_cv_path);
                }
                $payload['cv_path'] = null;
            }

            if ($this->editingId) {
                $faculty = Faculty::findOrFail($this->editingId);
                $old = $faculty->toArray();
                $faculty->update($payload);

                AuditLogService::log(
                    event: 'faculty_updated',
                    description: "Updated faculty profile: {$faculty->name}",
                    auditable: $faculty,
                    oldValues: $old,
                    newValues: $faculty->fresh()->toArray(),
                    userId: auth()->id()
                );

                Toast::dispatch($this, 'success', __('Faculty :name updated successfully.', ['name' => $faculty->name]));
            } else {
                $faculty = Faculty::create($payload);

                AuditLogService::log(
                    event: 'faculty_created',
                    description: "Created new faculty: {$faculty->name} ({$faculty->faculty_code})",
                    auditable: $faculty,
                    newValues: $faculty->toArray(),
                    userId: auth()->id()
                );

                Toast::dispatch($this, 'success', __('Faculty :name created successfully.', ['name' => $faculty->name]));
            }

            DB::commit();
            $this->dispatch('modal-close', name: 'faculty-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save faculty: :err', ['err' => $e->getMessage()]));
        }
    }

    public function showDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingFaculty = Faculty::with(['user', 'partnerInstitute', 'classSchedules' => fn ($q) => $q->latest()->take(10)->with(['batch', 'course'])])
            ->findOrFail($id);

        $this->dispatch('modal-open', name: 'faculty-view-modal');
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->deletingFaculty = Faculty::withCount('classSchedules')->findOrFail($id);
        $this->dispatch('modal-open', name: 'faculty-delete-modal');
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        try {
            $faculty = Faculty::findOrFail($this->deleteId);
            $name = $faculty->name;
            $oldData = $faculty->toArray();

            $faculty->delete();

            AuditLogService::log(
                event: 'faculty_deleted',
                description: "Deleted faculty: {$name}",
                oldValues: $oldData,
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Faculty :name deleted successfully.', ['name' => $name]));
            $this->deleteId = null;
            $this->dispatch('modal-close', name: 'faculty-delete-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete faculty: :err', ['err' => $e->getMessage()]));
        }
    }

    public function toggleStatus(int $id): void
    {
        try {
            $faculty = Faculty::findOrFail($id);
            $newStatus = $faculty->status === 'active' ? 'inactive' : 'active';
            $faculty->update(['status' => $newStatus]);

            Toast::dispatch($this, 'info', __('Faculty status updated to :status.', ['status' => ucfirst($newStatus)]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update status.'));
        }
    }

    public function export(string $format = 'csv')
    {
        $faculties = Faculty::query()
            ->when($this->departmentFilter, fn ($q) => $q->where('department', $this->departmentFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy('name')
            ->get();

        $headers = ['Faculty Code', 'Name', 'Designation', 'Department', 'Specialization', 'Qualification', 'Email', 'Phone', 'Subjects', 'Status'];
        $rows = [];

        foreach ($faculties as $f) {
            $rows[] = [
                $f->faculty_code,
                $f->name,
                $f->designation ?? 'N/A',
                $f->department ?? 'N/A',
                $f->specialization ?? 'N/A',
                $f->qualification ?? 'N/A',
                $f->email ?? 'N/A',
                $f->phone ?? 'N/A',
                is_array($f->subjects) ? implode(', ', $f->subjects) : 'N/A',
                ucfirst($f->status),
            ];
        }

        $title = 'Faculty Master Directory';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Faculty Profiles & Departmental Allocation'),
            'xlsx' => StudentImportExportService::exportExcel('faculty-master-report', $headers, $rows),
            default => StudentImportExportService::exportCsv('faculty-master-report', $headers, $rows),
        };
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'departmentFilter', 'statusFilter']);
        $this->sortField = 'name';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="users" class="w-7 h-7 text-primary" />
                {{ __('Faculty Master Directory') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage teaching faculty, subject specialists, and academic instructors across all courses.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.dropdown align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5 cursor-pointer">
                        <x-icon name="download" class="h-4 w-4"/>
                        <span>{{ __('Export') }}</span>
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 opacity-60"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-slot:menu>
                    <button type="button" wire:click="export('csv')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-text" class="h-3.5 w-3.5 text-muted-foreground"/>
                        <span>{{ __('Export as CSV') }}</span>
                    </button>
                    <button type="button" wire:click="export('xlsx')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 text-emerald-500"/>
                        <span>{{ __('Export as Excel') }}</span>
                    </button>
                    <button type="button" wire:click="export('pdf')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-down" class="h-3.5 w-3.5 text-rose-500"/>
                        <span>{{ __('Export as PDF') }}</span>
                    </button>
                </x-slot:menu>
            </x-ui.dropdown>

            @canany(['faculty.manage', 'faculty.import'])
                <x-ui.button type="button" variant="outline" size="sm" class="h-9 gap-1.5 cursor-pointer" wire:click="openImportModal" x-data x-on:click="$store.modals.open('faculty-import-modal')">
                    <x-icon name="upload" class="h-4 w-4"/>
                    <span>{{ __('Import') }}</span>
                </x-ui.button>
            @endcanany

            @can('faculty.manage')
                <x-ui.button wire:click="create" size="sm" class="h-9 gap-1.5 cursor-pointer">
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('Add Faculty') }}</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-blue-500/10 p-2.5 text-blue-600 dark:text-blue-400">
                    <x-icon name="users" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Total Faculty') }}</p>
                    <p class="text-2xl font-bold text-foreground">{{ number_format($this->stats['total']) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-emerald-500/10 p-2.5 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle-2" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Active Teachers') }}</p>
                    <p class="text-2xl font-bold text-foreground">{{ number_format($this->stats['active']) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-violet-500/10 p-2.5 text-violet-600 dark:text-violet-400">
                    <x-icon name="layers" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Departments') }}</p>
                    <p class="text-2xl font-bold text-foreground">{{ number_format($this->stats['departments']) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-amber-500/10 p-2.5 text-amber-600 dark:text-amber-400">
                    <x-icon name="calendar" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Classes Conducted') }}</p>
                    <p class="text-2xl font-bold text-foreground">{{ number_format($this->stats['total_classes']) }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, code, dept, subject...') }}"
                    class="w-full rounded-md border border-input bg-background pl-9 pr-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
            </div>

            <div>
                <select
                    wire:model.live="departmentFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Departments') }}</option>
                    @foreach ($this->departments as $dept)
                        <option value="{{ $dept }}">{{ $dept }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select
                    wire:model.live="statusFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="on_leave">{{ __('On Leave') }}</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                @if ($search || $departmentFilter || $statusFilter)
                    <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="h-9 gap-1 text-xs">
                        <x-icon name="rotate-ccw" class="h-3.5 w-3.5"/>
                        <span>{{ __('Reset Filters') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <!-- Faculty Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted/50 border-b border-border text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Faculty') }}</th>
                        <th class="px-4 py-3">{{ __('Code') }}</th>
                        <th class="px-4 py-3">{{ __('Department') }}</th>
                        <th class="px-4 py-3">{{ __('Specialization') }}</th>
                        <th class="px-4 py-3">{{ __('Subjects Taught') }}</th>
                        <th class="px-4 py-3">{{ __('Classes') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->faculties as $faculty)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 font-medium">
                                <div class="flex items-center gap-3">
                                    @if ($faculty->avatar_url)
                                        <img src="{{ $faculty->avatar_url }}" alt="{{ $faculty->name }}" class="h-9 w-9 shrink-0 rounded-full object-cover border border-border">
                                    @else
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary font-semibold text-xs">
                                            {{ $faculty->initials }}
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-semibold text-foreground flex items-center gap-1.5">
                                            <span>{{ $faculty->name }}</span>
                                            @if ($faculty->cv_url)
                                                <a href="{{ $faculty->cv_url }}" target="_blank" download title="{{ __('Download CV/Resume') }}" class="text-muted-foreground hover:text-primary transition-colors">
                                                    <x-icon name="file-text" class="h-3.5 w-3.5 text-primary" />
                                                </a>
                                            @endif
                                        </div>
                                        <div class="text-xs text-muted-foreground flex items-center gap-1.5 flex-wrap">
                                            <span>{{ $faculty->designation ?: 'Faculty' }}</span>
                                            <span class="text-muted-foreground/60">&bull;</span>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-muted text-foreground capitalize">
                                                {{ $faculty->faculty_type_label }}
                                            </span>
                                            @if ($faculty->partnerInstitute)
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-500/10 text-blue-700 dark:text-blue-300" title="{{ $faculty->partnerInstitute->name }}">
                                                    <x-icon name="landmark" class="w-3 h-3 shrink-0" />
                                                    <span class="truncate max-w-[120px]">{{ $faculty->partnerInstitute->name }}</span>
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                                {{ $faculty->faculty_code }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ $faculty->department ?: 'N/A' }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ $faculty->specialization ?: 'General' }}
                            </td>
                            <td class="px-4 py-3 text-xs max-w-xs">
                                @if (!empty($faculty->subjects))
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (array_slice($faculty->subjects, 0, 3) as $subj)
                                            <span class="inline-flex items-center rounded-md bg-secondary px-1.5 py-0.5 text-[10px] font-medium text-secondary-foreground">
                                                {{ $subj }}
                                            </span>
                                        @endforeach
                                        @if (count($faculty->subjects) > 3)
                                            <span class="text-[10px] text-muted-foreground">+{{ count($faculty->subjects) - 3 }}</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted-foreground italic">{{ __('None set') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs font-semibold">
                                <span class="inline-flex items-center gap-1 text-primary">
                                    <x-icon name="calendar" class="h-3 w-3" />
                                    {{ $faculty->class_schedules_count }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($faculty->status === 'active')
                                    <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                        {{ __('Active') }}
                                    </span>
                                @elseif ($faculty->status === 'on_leave')
                                    <span class="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                                        {{ __('On Leave') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-zinc-500/10 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        {{ __('Inactive') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="showDetails({{ $faculty->id }})"
                                        class="p-1.5 text-muted-foreground hover:text-foreground rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                        title="{{ __('View Profile') }}"
                                    >
                                        <x-icon name="eye" class="h-4 w-4"/>
                                    </button>

                                    @can('faculty.manage')
                                        <button
                                            type="button"
                                            wire:click="edit({{ $faculty->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-primary rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Edit Profile') }}"
                                        >
                                            <x-icon name="pencil" class="h-4 w-4"/>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="toggleStatus({{ $faculty->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-amber-500 rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Toggle Active/Inactive') }}"
                                        >
                                            <x-icon name="power" class="h-4 w-4"/>
                                        </button>
                                    @endcan

                                    @can('faculty.delete')
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $faculty->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-destructive rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Delete Faculty') }}"
                                        >
                                            <x-icon name="trash-2" class="h-4 w-4"/>
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="users" class="h-8 w-8 text-muted-foreground/50"/>
                                    <p class="font-medium">{{ __('No faculty members found.') }}</p>
                                    <p class="text-xs text-muted-foreground">{{ __('Try clearing filters or add a new faculty member.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->faculties->hasPages())
            <div class="border-t border-border p-4">
                {{ $this->faculties->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Faculty Modal -->
    <x-ui.modal name="faculty-form-modal" maxWidth="max-w-2xl" :title="$editingId ? __('Edit Faculty Profile') : __('Add New Faculty Member')" :description="__('Configure faculty member details, teaching departments, and subject competencies.')">
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Full Name') }} <span class="text-destructive">*</span></label>
                    <input
                        type="text"
                        wire:model="form.name"
                        required
                        placeholder="{{ __('Dr. B. R. Sharma') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.name') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Designation') }}</label>
                    <input
                        type="text"
                        wire:model="form.designation"
                        placeholder="{{ __('Senior Professor / Guest Lecturer') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.designation') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Department') }} <span class="text-destructive">*</span>
                    </label>
                    <select
                        wire:model.live="department_select"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        @foreach (self::PREDEFINED_DEPARTMENTS as $deptOption)
                            <option value="{{ $deptOption }}">{{ $deptOption }}</option>
                        @endforeach
                    </select>
                    @error('department_select') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                @if ($department_select === 'Custom')
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            {{ __('Custom Department Name') }} <span class="text-destructive">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model="custom_department"
                            placeholder="{{ __('e.g. Political Philosophy, Anthropology') }}"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                        @error('custom_department') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                    </div>
                @endif

                <!-- Photo & CV Uploads -->
                <div class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4 p-3 rounded-lg border border-border bg-muted/20">
                    <!-- Photo Upload -->
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            {{ __('Faculty Photo (Optional)') }}
                        </label>
                        <div class="flex items-center gap-3">
                            @if ($photo)
                                <img src="{{ $photo->temporaryUrl() }}" class="w-12 h-12 rounded-full object-cover border border-border">
                            @elseif ($existing_photo_url && ! $remove_photo)
                                <img src="{{ $existing_photo_url }}" class="w-12 h-12 rounded-full object-cover border border-border">
                            @else
                                <div class="w-12 h-12 rounded-full bg-secondary flex items-center justify-center text-muted-foreground text-xs font-bold">
                                    <x-icon name="user" class="w-6 h-6" />
                                </div>
                            @endif
                            <div class="flex-1">
                                <input
                                    type="file"
                                    wire:model="photo"
                                    accept="image/*"
                                    class="w-full text-xs text-muted-foreground file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer"
                                />
                                <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('PNG, JPG, WEBP up to 5MB') }}</span>
                                @if ($photo || ($existing_photo_url && ! $remove_photo))
                                    <div class="pt-1">
                                        <button
                                            type="button"
                                            wire:click="$set('remove_photo', true); $set('photo', null)"
                                            class="inline-flex items-center gap-1 text-[11px] text-destructive hover:underline"
                                        >
                                            <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                            <span>{{ __('Remove photo') }}</span>
                                        </button>
                                    </div>
                                @endif
                                @error('photo') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>

                    <!-- CV / Resume Upload -->
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            {{ __('CV / Resume (Optional)') }}
                        </label>
                        <input
                            type="file"
                            wire:model="cv"
                            accept=".pdf,.doc,.docx"
                            class="w-full text-xs text-muted-foreground file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80 cursor-pointer"
                        />
                        @if ($cv)
                            <p class="text-[10px] text-emerald-600 mt-0.5">{{ __('New file selected ready to save.') }}</p>
                        @elseif ($existing_cv_url && ! $remove_cv)
                            <div class="flex items-center gap-1.5 mt-1 text-xs text-primary font-medium">
                                <x-icon name="file-text" class="w-3.5 h-3.5" />
                                <a href="{{ $existing_cv_url }}" target="_blank" download class="hover:underline truncate max-w-xs">
                                    {{ $existing_cv_name ?: __('Current CV / Resume') }}
                                </a>
                            </div>
                        @else
                            <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('PDF, DOC, DOCX up to 10MB') }}</span>
                        @endif

                        @if ($cv || ($existing_cv_url && ! $remove_cv))
                            <div class="pt-1">
                                <button
                                    type="button"
                                    wire:click="$set('remove_cv', true); $set('cv', null)"
                                    class="inline-flex items-center gap-1 text-[11px] text-destructive hover:underline"
                                >
                                    <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                    <span>{{ __('Remove CV') }}</span>
                                </button>
                            </div>
                        @endif
                        @error('cv') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- Reference Partner Coaching Institute -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Reference Partner Institute (Optional)') }}
                    </label>
                    <select
                        wire:model="form.partner_institute_id"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="">{{ __('-- None / In-house Faculty --') }}</option>
                        @foreach ($this->partnerInstitutes as $inst)
                            <option value="{{ $inst->id }}">{{ $inst->name }} ({{ $inst->code }})</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-muted-foreground mt-1">{{ __('Select if faculty belongs to or is deputed from an affiliated coaching institute.') }}</p>
                    @error('form.partner_institute_id') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <!-- Faculty Type (Regular, Guest, Visiting, etc.) -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Faculty Type / Status') }} <span class="text-destructive">*</span>
                    </label>
                    <select
                        wire:model="form.faculty_type"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="regular">{{ __('Regular Faculty') }}</option>
                        <option value="guest">{{ __('Guest Faculty') }}</option>
                        <option value="visiting">{{ __('Visiting Expert / Mentor') }}</option>
                        <option value="contractual">{{ __('Contractual Faculty') }}</option>
                        <option value="adjunct">{{ __('Adjunct Faculty') }}</option>
                    </select>
                    @error('form.faculty_type') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Official Email') }}</label>
                    <input
                        type="email"
                        wire:model="form.email"
                        placeholder="{{ __('faculty@institution.org') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.email') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Contact Phone') }}</label>
                    <input
                        type="tel"
                        wire:model="form.phone"
                        placeholder="{{ __('+91 98765 43210') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.phone') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Specialization') }}</label>
                    <input
                        type="text"
                        wire:model="form.specialization"
                        placeholder="{{ __('Modern Indian History, International Relations') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Qualification') }}</label>
                    <input
                        type="text"
                        wire:model="form.qualification"
                        placeholder="{{ __('Ph.D., M.A. in History, UGC-NET') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Competent Subjects (comma separated)') }}
                    </label>
                    <input
                        type="text"
                        wire:model="form.subjects_raw"
                        placeholder="{{ __('Indian History, Art & Culture, Modern India, World History') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    <p class="text-[11px] text-muted-foreground mt-1">{{ __('Any subject can be taught by one or multiple faculties.') }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Status') }}</label>
                    <select
                        wire:model="form.status"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                        <option value="on_leave">{{ __('On Leave') }}</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Short Biography / Background') }}</label>
                    <textarea
                        wire:model="form.bio"
                        rows="3"
                        placeholder="{{ __('Former civil servant / academic researcher with 12+ years of mentoring experience...') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    ></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'faculty-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4"/>
                    <span>{{ $editingId ? __('Update Faculty') : __('Create Faculty') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Faculty View Details Modal -->
    <x-ui.modal name="faculty-view-modal" maxWidth="max-w-2xl" :title="$viewingFaculty?->name ?? __('Faculty Dossier')" :description="__('Full profile overview, subjects handled, and teaching assignments.')">
        @if ($viewingFaculty)
            <div class="space-y-4">
                <div class="flex items-center gap-4 p-4 rounded-lg bg-secondary/50">
                    @if ($viewingFaculty->avatar_url)
                        <img src="{{ $viewingFaculty->avatar_url }}" alt="{{ $viewingFaculty->name }}" class="h-14 w-14 shrink-0 rounded-full object-cover border-2 border-primary/20 shadow-xs">
                    @else
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground font-bold text-lg">
                            {{ $viewingFaculty->initials }}
                        </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <h3 class="text-lg font-bold text-foreground">{{ $viewingFaculty->name }}</h3>
                        <p class="text-xs text-muted-foreground">{{ $viewingFaculty->designation }} &bull; {{ $viewingFaculty->department }}</p>
                        <p class="text-xs font-mono text-primary mt-0.5">{{ $viewingFaculty->faculty_code }}</p>
                    </div>

                    @if ($viewingFaculty->cv_url)
                        <a
                            href="{{ $viewingFaculty->cv_url }}"
                            target="_blank"
                            download
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-xs transition"
                        >
                            <x-icon name="download" class="h-3.5 w-3.5" />
                            <span>{{ __('Download CV') }}</span>
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="rounded-md border border-border p-3">
                        <span class="text-muted-foreground block font-medium">{{ __('Faculty Type') }}</span>
                        <span class="font-semibold text-foreground capitalize">{{ $viewingFaculty->faculty_type_label }}</span>
                    </div>
                    <div class="rounded-md border border-border p-3">
                        <span class="text-muted-foreground block font-medium">{{ __('Reference Partner Institute') }}</span>
                        @if ($viewingFaculty->partnerInstitute)
                            <span class="font-semibold text-primary flex items-center gap-1">
                                <x-icon name="landmark" class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ $viewingFaculty->partnerInstitute->name }} ({{ $viewingFaculty->partnerInstitute->code }})</span>
                            </span>
                        @else
                            <span class="font-semibold text-foreground">{{ __('In-house Faculty (No Partner Institute)') }}</span>
                        @endif
                    </div>
                    <div class="rounded-md border border-border p-3">
                        <span class="text-muted-foreground block font-medium">{{ __('Email') }}</span>
                        <span class="font-semibold text-foreground">{{ $viewingFaculty->email ?: __('Not Provided') }}</span>
                    </div>
                    <div class="rounded-md border border-border p-3">
                        <span class="text-muted-foreground block font-medium">{{ __('Phone') }}</span>
                        <span class="font-semibold text-foreground">{{ $viewingFaculty->phone ?: __('Not Provided') }}</span>
                    </div>
                    <div class="rounded-md border border-border p-3">
                        <span class="text-muted-foreground block font-medium">{{ __('Specialization') }}</span>
                        <span class="font-semibold text-foreground">{{ $viewingFaculty->specialization ?: __('General Studies') }}</span>
                    </div>
                    <div class="rounded-md border border-border p-3">
                        <span class="text-muted-foreground block font-medium">{{ __('Qualification') }}</span>
                        <span class="font-semibold text-foreground">{{ $viewingFaculty->qualification ?: __('N/A') }}</span>
                    </div>
                </div>

                @if ($viewingFaculty->bio)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-medium mb-1">{{ __('Biography') }}</span>
                        <p class="text-foreground leading-relaxed">{{ $viewingFaculty->bio }}</p>
                    </div>
                @endif

                @if (!empty($viewingFaculty->subjects))
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">{{ __('Competent Teaching Subjects') }}</h4>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($viewingFaculty->subjects as $subject)
                                <span class="rounded-md bg-secondary px-2.5 py-1 text-xs font-medium text-secondary-foreground">
                                    {{ $subject }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">{{ __('Recent Classes Scheduled') }}</h4>
                    <div class="rounded-md border border-border divide-y divide-border overflow-hidden text-xs">
                        @forelse ($viewingFaculty->classSchedules as $class)
                            <div class="p-2.5 flex items-center justify-between hover:bg-muted/30">
                                <div>
                                    <div class="font-semibold text-foreground">{{ $class->subject }} &bull; {{ $class->topics }}</div>
                                    <div class="text-[11px] text-muted-foreground">
                                        {{ $class->batch?->name }} &bull; {{ $class->class_date->format('d M Y') }} ({{ $class->formatted_time }})
                                    </div>
                                </div>
                                <span class="rounded-full bg-secondary px-2 py-0.5 text-[10px] font-medium">
                                    {{ ucfirst($class->delivery_mode) }}
                                </span>
                            </div>
                        @empty
                            <div class="p-3 text-center text-muted-foreground">{{ __('No classes scheduled yet.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal name="faculty-delete-modal" maxWidth="max-w-md" :title="__('Confirm Delete Faculty')" :description="__('Are you sure you want to delete this faculty member? This action soft-deletes the profile.')">
        @if ($deletingFaculty)
            <div class="space-y-3">
                <p class="text-sm text-foreground">
                    {{ __('You are about to delete faculty:') }} <strong>{{ $deletingFaculty->name }}</strong> ({{ $deletingFaculty->faculty_code }}).
                </p>
                @if ($deletingFaculty->class_schedules_count > 0)
                    <div class="rounded-md bg-amber-500/10 border border-amber-500/20 p-3 text-xs text-amber-600 dark:text-amber-400">
                        <x-icon name="alert-triangle" class="h-4 w-4 inline mr-1" />
                        {{ __('Note: This teacher is associated with :count classes. Historical logs and routines will remain preserved.', ['count' => $deletingFaculty->class_schedules_count]) }}
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('faculty-delete-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="destructive" size="sm" wire:click="delete" class="gap-1.5">
                        <x-icon name="trash-2" class="h-4 w-4"/>
                        <span>{{ __('Delete Faculty') }}</span>
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Bulk Import Modal -->
    <x-ui.modal
        name="faculty-import-modal"
        max-width="max-w-lg"
        :title="__('Bulk Import Faculty Directory')"
        :description="__('Upload an Excel (.xlsx, .xls) or CSV spreadsheet with faculty master profiles.')"
    >
        <div class="space-y-4">
            <div class="p-3 rounded-xl bg-primary/10 border border-primary/20 flex items-start gap-2.5 text-xs text-foreground">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0 mt-0.5" />
                <div class="space-y-1">
                    <p class="font-semibold">{{ __('Spreadsheet Guidelines:') }}</p>
                    <p class="text-muted-foreground">{{ __('Required columns: Full Name, Email. Optional: Faculty Code, Phone, Designation, Department, Faculty Type (regular/guest/visiting/contractual/adjunct), Partner Institute Code/Name, Specialization, Qualification, Status, Competent Subjects (comma-separated), Biography.') }}</p>
                    <button type="button" wire:click="downloadTemplate" class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline pt-1">
                        <x-icon name="download" class="w-3.5 h-3.5" />
                        <span>{{ __('Download sample Excel template (.xlsx)') }}</span>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">
                    {{ __('Select Spreadsheet File') }} <span class="text-destructive">*</span>
                </label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".xlsx,.xls,.csv"
                    class="w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer border border-input rounded-md bg-background"
                />
                @error('importFile') <span class="text-destructive text-[11px] block mt-1">{{ $message }}</span> @enderror
            </div>

            @if (! empty($importResult))
                <div class="p-3 rounded-xl border {{ $importResult['imported'] > 0 ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-amber-500/30 bg-amber-500/10' }} text-xs space-y-1.5">
                    <div class="flex items-center justify-between font-semibold">
                        <span>{{ __('Import Diagnostics Summary') }}</span>
                        <span class="{{ $importResult['imported'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                            {{ __(':count Faculty Members Imported', ['count' => $importResult['imported']]) }}
                        </span>
                    </div>

                    @if (! empty($importResult['errors']))
                        <ul class="list-disc list-inside space-y-1 max-h-36 overflow-y-auto text-destructive pt-1 border-t border-border/50">
                            @foreach ($importResult['errors'] as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('faculty-import-modal')">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" wire:click="processImport" wire:loading.attr="disabled" class="gap-1.5 disabled:opacity-50">
                    <x-icon name="upload" class="h-4 w-4" />
                    <span wire:loading.remove wire:target="processImport">{{ __('Start Import') }}</span>
                    <span wire:loading wire:target="processImport">{{ __('Processing...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
