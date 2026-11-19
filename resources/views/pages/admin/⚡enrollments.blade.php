<?php

use App\Helpers\CourseData;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Services\AdmissionRestrictionService;
use App\Services\StudentIdGeneratorService;
use App\Services\StudentImportExportService;
use App\Services\UserStudentSyncService;
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

new #[Layout('layouts.app')] #[Title('Enrollments Management')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'section')]
    public string $sectionFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'fee')]
    public string $feeFilter = '';

    public string $sortField = 'enrolled_at';
    public string $sortDirection = 'desc';
    public int $perPage = 10;

    // Form Modal state
    public ?int $editingId = null;
    public array $form = [
        'user_id' => '',
        'batch_id' => '',
        'course_id' => '',
        'section_id' => '',
        'roll_no' => '',
        'application_no' => '',
        'enrolled_at' => '',
        'completed_at' => '',
        'fee_amount' => '0.00',
        'fee_status' => 'paid',
        'status' => 'active',
        'remarks' => '',
        'override_restrictions' => false,
    ];

    // Detail Modal state
    public ?int $viewingId = null;
    public ?Enrollment $viewingEnrollment = null;

    // Direct Cancel / Discontinuation Modal state
    public ?int $directCancelId = null;
    public ?Enrollment $directCancelEnrollment = null;
    public array $directCancelForm = [
        'status' => 'cancelled',
        'reason' => 'disciplinary',
        'effective_date' => '',
        'notes' => '',
    ];

    // Revert / Reinstate Modal state
    public ?int $reinstateId = null;
    public ?Enrollment $reinstateEnrollment = null;
    public array $reinstateForm = [
        'order_number' => '',
        'reason' => '',
        'effective_date' => '',
        'activate_user' => true,
    ];

    // Delete Modal state
    public ?int $deleteId = null;

    // Import Modal state
    public $importFile = null;
    public ?array $importSummary = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBatchFilter(): void
    {
        $this->sectionFilter = '';
        $this->resetPage();
    }

    public function updatedCourseFilter(): void
    {
        $this->sectionFilter = '';
        $this->resetPage();
    }

    public function updatedSectionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFeeFilter(): void
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
            'total' => Enrollment::count(),
            'active' => Enrollment::where('status', 'active')->count(),
            'completed' => Enrollment::where('status', 'completed')->count(),
            'total_fees' => Enrollment::sum('fee_amount'),
            'pending_fees' => Enrollment::where('fee_status', 'pending')->sum('fee_amount'),
        ];
    }

    #[Computed]
    public function batches()
    {
        return Batch::where('status', '!=', 'archived')->orderBy('name')->get();
    }

    #[Computed]
    public function courses()
    {
        return Course::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function sections()
    {
        return Section::query()
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function formSections()
    {
        if (! $this->form['batch_id'] || ! $this->form['course_id']) {
            return collect();
        }

        $batchId = (int) $this->form['batch_id'];
        $courseId = (int) $this->form['course_id'];

        $sections = Section::where('batch_id', $batchId)
            ->where(function ($q) use ($courseId) {
                $q->whereNull('course_id')
                    ->orWhere('course_id', $courseId);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        // If no sections exist for this batch yet, but the course requires/has sections (e.g. Composite Course),
        // auto-provision the standard sections (Section A & Section B) for this batch so section assignment is always available.
        if ($sections->isEmpty()) {
            $course = Course::find($courseId);
            if ($course && $course->has_sections) {
                $batch = Batch::find($batchId);
                if ($batch) {
                    $secA = Section::create([
                        'batch_id' => $batchId,
                        'course_id' => $courseId,
                        'name' => 'Section A',
                        'code' => '1',
                        'capacity' => 100,
                        'room_number' => 'Hall 1',
                        'timing' => 'Mon–Fri · 08:00–11:00',
                        'status' => 'active',
                    ]);
                    $secB = Section::create([
                        'batch_id' => $batchId,
                        'course_id' => $courseId,
                        'name' => 'Section B',
                        'code' => '2',
                        'capacity' => 100,
                        'room_number' => 'Hall 2',
                        'timing' => 'Mon–Fri · 16:00–19:00',
                        'status' => 'active',
                    ]);

                    return collect([$secA, $secB]);
                }
            }
        }

        return $sections;
    }

    #[Computed]
    public function eligibleStudents()
    {
        return User::where('status', 'active')
            ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'Student'))->orWhereNotNull('student_id')->orWhereHas('enrollments'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function enrollments()
    {
        return Enrollment::query()
            ->with(['user.studentProfile', 'batch', 'course', 'section'])
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('enrollment_no', 'like', $term)
                        ->orWhere('application_no', 'like', $term)
                        ->orWhere('roll_no', 'like', $term)
                        ->orWhereHas('user', function ($uq) use ($term) {
                            $uq->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('phone', 'like', $term)
                                ->orWhere('student_id', 'like', $term);
                        });
                });
            })
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->when($this->sectionFilter, fn ($q) => $q->where('section_id', (int) $this->sectionFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->feeFilter, fn ($q) => $q->where('fee_status', $this->feeFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->editingId = null;
        $defaultBatch = Batch::where('status', 'active')->first() ?? Batch::first();
        $defaultCourse = Course::where('status', 'active')->first();

        $this->form = [
            'user_id' => '',
            'batch_id' => $defaultBatch ? (string) $defaultBatch->id : '',
            'course_id' => $defaultCourse ? (string) $defaultCourse->id : '',
            'section_id' => '',
            'roll_no' => '',
        'application_no' => '',
            'enrolled_at' => date('Y-m-d'),
            'completed_at' => '',
            'fee_amount' => $defaultCourse ? (string) $defaultCourse->fee : '0.00',
            'fee_status' => 'paid',
            'status' => 'active',
            'remarks' => '',
            'override_restrictions' => false,
        ];

        $this->dispatch('modal-open', name: 'enrollment-form-modal');
    }

    public function recalculateFee(): void
    {
        if (empty($this->form['course_id'])) {
            return;
        }

        $course = Course::find((int) $this->form['course_id']);
        if (! $course) {
            return;
        }

        $category = 'UR';
        if (! empty($this->form['user_id'])) {
            $student = User::with('studentProfile')->find((int) $this->form['user_id']);
            $category = $student?->studentProfile?->category ?? 'UR';
        }

        $fee = CourseData::calculateFee($course, $category);
        $this->form['fee_amount'] = (string) $fee;

        if ($course->isFree() || $fee == 0.0) {
            $this->form['fee_status'] = 'exempted';
        }
    }

    public function updatedFormBatchId($batchId): void
    {
        $this->form['section_id'] = '';
        $this->recalculateFee();
    }

    public function updatedFormCourseId($courseId): void
    {
        $this->form['section_id'] = '';
        $this->recalculateFee();
    }

    public function updatedFormUserId($userId): void
    {
        $this->recalculateFee();
    }

    public function edit(int $id): void
    {
        $enr = Enrollment::findOrFail($id);
        $this->editingId = $enr->id;

        $this->form = [
            'user_id' => (string) $enr->user_id,
            'batch_id' => (string) $enr->batch_id,
            'course_id' => (string) $enr->course_id,
            'section_id' => $enr->section_id ? (string) $enr->section_id : '',
            'roll_no' => $enr->roll_no ?? '',
            'application_no' => $enr->application_no ?? '',
            'enrolled_at' => $enr->enrolled_at ? $enr->enrolled_at->format('Y-m-d') : date('Y-m-d'),
            'completed_at' => $enr->completed_at ? $enr->completed_at->format('Y-m-d') : '',
            'fee_amount' => (string) $enr->fee_amount,
            'fee_status' => $enr->fee_status,
            'status' => $enr->status,
            'remarks' => $enr->remarks ?? '',
            'override_restrictions' => false,
        ];

        $this->dispatch('modal-open', name: 'enrollment-form-modal');
    }

    public function viewDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingEnrollment = Enrollment::with(['user.studentProfile', 'batch', 'course', 'section'])->findOrFail($id);
        $this->dispatch('modal-open', name: 'enrollment-view-modal');
    }

    public function save(): void
    {
        $this->validate([
            'form.user_id' => 'required|exists:users,id',
            'form.batch_id' => 'required|exists:batches,id',
            'form.course_id' => 'required|exists:courses,id',
            'form.application_no' => 'nullable|string|max:50',
            'form.enrolled_at' => 'required|date',
            'form.fee_amount' => 'required|numeric|min:0',
            'form.fee_status' => 'required|in:pending,partial,paid,exempted',
            'form.status' => 'required|in:active,completed,dropped,suspended,alumni',
        ]);

        $student = User::findOrFail((int) $this->form['user_id']);
        $batch = Batch::findOrFail((int) $this->form['batch_id']);
        $course = Course::findOrFail((int) $this->form['course_id']);
        $section = ! empty($this->form['section_id']) ? Section::find((int) $this->form['section_id']) : null;

        // Restriction check on creation
        if (! $this->editingId) {
            $check = AdmissionRestrictionService::checkEligibility(
                $student,
                $course,
                $batch,
                $section,
                (bool) $this->form['override_restrictions']
            );

            if (! $check['allowed']) {
                Toast::dispatch($this, 'error', $check['reason']);
                return;
            }
        }

        DB::beginTransaction();
        try {
            // Generate 9-digit Student ID for this enrollment (e.g. 202630001 for PCC, 202710001 for CC Sec A)
            $studentId = StudentIdGeneratorService::generateForEnrollment(
                $student,
                $course,
                $section,
                $batch->academic_year,
                true
            );

            $data = [
                'user_id' => $student->id,
                'student_id' => $studentId,
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => $section?->id,
                'roll_no' => $this->form['roll_no'] ?: null,
                'application_no' => ! empty($this->form['application_no']) ? trim($this->form['application_no']) : null,
                'enrolled_at' => $this->form['enrolled_at'],
                'completed_at' => $this->form['completed_at'] ?: null,
                'fee_amount' => (float) $this->form['fee_amount'],
                'fee_status' => $this->form['fee_status'],
                'status' => $this->form['status'],
                'remarks' => $this->form['remarks'] ?: null,
            ];

            if (! $student->hasRole('Student')) {
                $student->assignRole('Student');
            }
            UserStudentSyncService::syncFromUser($student);

            if ($this->editingId) {
                $enr = Enrollment::findOrFail($this->editingId);
                $enr->update($data);
                Toast::dispatch($this, 'success', __('Enrollment :no updated successfully.', ['no' => $enr->enrollment_no]));
            } else {
                $enr = Enrollment::create($data);
                Toast::dispatch($this, 'success', __('Candidate :name enrolled into :course! (ID: :id)', [
                    'name' => $student->name,
                    'course' => $course->code,
                    'id' => $studentId,
                ]));
            }

            DB::commit();
            $this->dispatch('modal-close', name: 'enrollment-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Enrollment failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openDirectCancelModal(int $id): void
    {
        $this->directCancelId = $id;
        $this->directCancelEnrollment = Enrollment::with(['user', 'course', 'batch'])->findOrFail($id);
        $this->directCancelForm = [
            'status' => 'cancelled',
            'reason' => 'disciplinary',
            'effective_date' => date('Y-m-d'),
            'notes' => '',
        ];
        $this->dispatch('modal-open', name: 'enrollment-direct-cancel-modal');
    }

    public function executeDirectCancel(): void
    {
        $this->validate([
            'directCancelForm.status' => 'required|in:cancelled,discontinued,withdrawn,dropped',
            'directCancelForm.reason' => 'required|string|max:100',
            'directCancelForm.effective_date' => 'required|date',
            'directCancelForm.notes' => 'required|string|min:5|max:1000',
        ]);

        if (! $this->directCancelId) {
            return;
        }

        DB::beginTransaction();
        try {
            $enr = Enrollment::with(['user', 'course'])->findOrFail($this->directCancelId);
            $oldStatus = $enr->status;
            $newStatus = $this->directCancelForm['status'];

            $formattedReason = ucfirst($this->directCancelForm['reason']).': '.trim($this->directCancelForm['notes']);

            $enr->update([
                'status' => $newStatus,
                'cancellation_reason' => $formattedReason,
                'discontinued_at' => $this->directCancelForm['effective_date'] ?: now(),
                'discontinued_by' => auth()->id(),
            ]);

            AuditLog::record(
                action: 'enrollment.cancelled',
                details: [
                    'enrollment_id' => $enr->id,
                    'enrollment_no' => $enr->enrollment_no,
                    'user_id' => $enr->user_id,
                    'student_name' => $enr->user?->name,
                    'course' => $enr->course?->code,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'reason' => $this->directCancelForm['reason'],
                    'notes' => $this->directCancelForm['notes'],
                    'effective_date' => $this->directCancelForm['effective_date'],
                ],
                userId: auth()->id(),
                type: 'enrollment',
                status: 'success'
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Enrollment :no marked as :status successfully.', [
                'no' => $enr->enrollment_no,
                'status' => ucfirst($newStatus),
            ]));

            $this->directCancelId = null;
            $this->directCancelEnrollment = null;
            $this->dispatch('modal-close', name: 'enrollment-direct-cancel-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to cancel enrollment: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openReinstateModal(int $id): void
    {
        $this->reinstateId = $id;
        $this->reinstateEnrollment = Enrollment::with(['user.studentProfile', 'course', 'batch', 'section'])->findOrFail($id);
        $this->reinstateForm = [
            'order_number' => 'REIN-'.date('Y').'-'.rand(1000, 9999),
            'reason' => '',
            'effective_date' => now()->format('Y-m-d'),
            'activate_user' => true,
        ];
        $this->dispatch('modal-open', name: 'enrollment-reinstate-modal');
    }

    public function closeReinstateModal(): void
    {
        $this->reinstateId = null;
        $this->reinstateEnrollment = null;
        $this->reinstateForm = [
            'order_number' => '',
            'reason' => '',
            'effective_date' => '',
            'activate_user' => true,
        ];
        $this->dispatch('modal-close', name: 'enrollment-reinstate-modal');
    }

    public function executeReinstate(): void
    {
        if (! auth()->user()?->can('enrollments.reinstate') && ! auth()->user()?->can('students.edit') && ! auth()->user()?->hasRole('Super Administrator')) {
            Toast::dispatch($this, 'error', __('Unauthorized: You lack permission to reinstate student enrollments.'));
            return;
        }

        $this->validate([
            'reinstateForm.reason' => 'required|string|min:5|max:1000',
            'reinstateForm.order_number' => 'nullable|string|max:100',
        ]);

        if (! $this->reinstateId) {
            return;
        }

        DB::beginTransaction();
        try {
            $enr = Enrollment::with('user')->findOrFail($this->reinstateId);

            $enr->reinstate(
                reason: $this->reinstateForm['reason'],
                adminId: auth()->id(),
                activateUser: (bool) ($this->reinstateForm['activate_user'] ?? true),
                orderNumber: $this->reinstateForm['order_number'] ?: null
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Enrollment :no systematically reinstated to active.', [
                'no' => $enr->enrollment_no,
            ]));

            // Refresh viewing enrollment if view modal is currently open
            if ($this->viewingId) {
                $this->viewingEnrollment = Enrollment::with(['user.studentProfile', 'batch', 'course', 'section', 'creator', 'discontinuedBy', 'reinstatedBy'])->find($this->viewingId);
            }

            $this->closeReinstateModal();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('Failed to reinstate enrollment: :err', ['err' => $e->getMessage()]));
        }
    }

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->dispatch('modal-open', name: 'enrollment-delete-modal');
    }

    public function deleteSelected(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $enr = Enrollment::findOrFail($this->deleteId);
        $enr->delete();

        Toast::dispatch($this, 'success', __('Enrollment record :no deleted.', ['no' => $enr->enrollment_no]));
        $this->deleteId = null;
        $this->dispatch('modal-close', name: 'enrollment-delete-modal');
    }

    public function processImport(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        $path = $this->importFile->getRealPath();
        $res = StudentImportExportService::importEnrollments($path);
        $this->importSummary = $res;

        if ($res['success'] > 0) {
            Toast::dispatch($this, 'success', __('Successfully imported :count enrollments.', ['count' => $res['success']]));
        } else {
            Toast::dispatch($this, 'error', __('Import completed with :count errors.', ['count' => $res['failed']]));
        }
    }

    public function downloadTemplate(string $format = 'csv')
    {
        return StudentImportExportService::downloadEnrollmentTemplate($format);
    }

    public function export(string $format = 'csv')
    {
        $enrollments = Enrollment::with(['user', 'batch', 'course', 'section'])->get();

        $headers = ['Enrollment #', 'Application #', 'Lifetime Student ID', 'Student Name', 'Email', 'Batch', 'Course Programme', 'Section', 'Roll #', 'Enrolled Date', 'Fee Amount (INR)', 'Fee Status', 'Status'];
        $rows = [];

        foreach ($enrollments as $e) {
            $rows[] = [
                $e->enrollment_no,
                $e->application_no ?? '—',
                $e->user?->student_id ?? 'N/A',
                $e->user?->name ?? 'N/A',
                $e->user?->email ?? 'N/A',
                $e->batch?->name ?? 'N/A',
                $e->course?->code.' - '.($e->course?->name ?? ''),
                $e->section?->name ?? 'No Section',
                $e->roll_no ?? '—',
                $e->enrolled_at ? $e->enrolled_at->format('Y-m-d') : 'N/A',
                number_format($e->fee_amount, 2),
                ucfirst($e->fee_status),
                ucfirst($e->status),
            ];
        }

        $title = 'UPSC Course Enrollments Master Registry';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Candidate Course Enrollments and Fee Status'),
            'xlsx' => StudentImportExportService::exportExcel('enrollments-master-report', $headers, $rows),
            default => StudentImportExportService::exportCsv('enrollments-master-report', $headers, $rows),
        };
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->batchFilter = '';
        $this->courseFilter = '';
        $this->sectionFilter = '';
        $this->statusFilter = '';
        $this->feeFilter = '';
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="book-open" class="w-7 h-7 text-primary" />
                {{ __('Course Enrollments Management') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage candidate admissions into UPSC courses, annual batches, optional classroom section allocations, and fee collection records.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Import Button -->
            <x-ui.button variant="outline" size="sm" wire:click="$dispatch('modal-open', { name: 'enrollment-import-modal' })" class="h-9 gap-1.5">
                <x-icon name="upload" class="h-3.5 w-3.5"/>
                {{ __('Import') }}
            </x-ui.button>

            <!-- Export Dropdown -->
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground ml-0.5"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Report') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            @can('students.create')
            <x-ui.button variant="default" size="sm" wire:click="create" class="h-9 gap-1.5">
                <x-icon name="plus" class="h-3.5 w-3.5"/>
                {{ __('New Enrollment') }}
            </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Enrollments') }}</span>
                <span class="p-2 rounded-lg bg-sky-50 dark:bg-sky-950/50 text-sky-600 dark:text-sky-400">
                    <x-icon name="badge-check" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ $this->stats['total'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Across all batches') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Active Seats') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ $this->stats['active'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Ongoing course learners') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Completed') }}</span>
                <span class="p-2 rounded-lg bg-muted text-muted-foreground">
                    <x-icon name="graduation-cap" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ $this->stats['completed'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Graduated programmes') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Total Fees Value') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="banknote" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400 mt-2">
                ₹{{ number_format($this->stats['total_fees'], 2) }}
            </div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Booked programme fees') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Pending Fees') }}</span>
                <span class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="clock" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold font-mono text-amber-600 dark:text-amber-400 mt-2">
                ₹{{ number_format($this->stats['pending_fees'], 2) }}
            </div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Awaiting payment clearance') }}</div>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-4 bg-card border border-border rounded-xl shadow-xs space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <div class="md:col-span-2 lg:col-span-2 relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search student, ID, roll #, enrollment #...') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select wire:model.live="batchFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Batches') }}</option>
                @foreach($this->batches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="courseFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Courses') }}</option>
                @foreach($this->courses as $c)
                    <option value="{{ $c->id }}">{{ $c->code }} - {{ $c->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="sectionFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Sections') }}</option>
                @foreach($this->sections as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}@if($s->code) ({{ $s->code }})@endif</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Statuses') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="completed">{{ __('Completed') }}</option>
                <option value="withdrawn">{{ __('Withdrawn') }}</option>
                <option value="discontinued">{{ __('Discontinued') }}</option>
                <option value="cancelled">{{ __('Cancelled') }}</option>
                <option value="suspended">{{ __('Suspended') }}</option>
                <option value="dropped">{{ __('Dropped') }}</option>
                <option value="alumni">{{ __('Alumni') }}</option>
            </select>

            <select wire:model.live="feeFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Fee Statuses') }}</option>
                <option value="paid">{{ __('Paid') }}</option>
                <option value="pending">{{ __('Pending') }}</option>
                <option value="partial">{{ __('Partial') }}</option>
                <option value="exempted">{{ __('Exempted') }}</option>
            </select>

            <div class="flex items-center justify-end sm:col-span-2 md:col-span-4 lg:col-span-7">
                @if($search || $batchFilter || $courseFilter || $sectionFilter || $statusFilter || $feeFilter)
                    <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="h-9 gap-1.5 text-xs">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                        {{ __('Reset All Filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <!-- Enrollments Data Table -->
    <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-muted/50 border-b border-border text-xs text-muted-foreground uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('enrollment_no')">
                            <div class="flex items-center gap-1">
                                {{ __('Enrollment #') }}
                                @if($sortField === 'enrollment_no')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3 h-3 text-foreground" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Candidate & Student ID') }}</th>
                        <th class="px-4 py-3">{{ __('Batch Session') }}</th>
                        <th class="px-4 py-3">{{ __('Course Programme') }}</th>
                        <th class="px-4 py-3">{{ __('Section') }}</th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('roll_no')">
                            <div class="flex items-center gap-1">
                                {{ __('Roll Number') }}
                                @if($sortField === 'roll_no')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3 h-3 text-foreground" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Fee Status') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-foreground">
                    @forelse($this->enrollments as $enr)
                        <tr class="hover:bg-muted/40 transition-colors">
                            <td class="px-4 py-3">
                                <span class="font-mono font-bold text-xs text-primary">
                                    {{ $enr->enrollment_no }}
                                </span>
                                @if($enr->application_no)
                                    <div class="text-[10px] font-mono text-muted-foreground flex items-center gap-1">
                                        <span>{{ __('App:') }}</span>
                                        <span class="font-semibold text-foreground/85">{{ $enr->application_no }}</span>
                                    </div>
                                @endif
                                <div class="text-[10px] text-muted-foreground">
                                    {{ $enr->enrolled_at ? $enr->enrolled_at->format('d M Y') : '—' }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-bold text-foreground flex items-center gap-1.5">
                                    <span>{{ $enr->user?->name }}</span>
                                </div>
                                <div class="flex items-center gap-1 mt-0.5">
                                    <span class="font-mono text-xs px-1.5 py-0.2 rounded bg-primary/10 text-primary font-semibold">
                                        ID: {{ $enr->student_id ?: ($enr->user?->student_id ?? 'N/A') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="font-semibold text-foreground">{{ $enr->batch?->name }}</span>
                                <div class="text-[10px] text-muted-foreground">{{ $enr->batch?->academic_year }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-foreground text-xs">
                                    {{ $enr->course?->name }}
                                </div>
                                <x-ui.badge variant="secondary" size="sm">{{ $enr->course?->code }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($enr->section)
                                    <span class="font-semibold text-foreground">{{ $enr->section->name }}</span>
                                    <div class="text-[10px] text-muted-foreground">Code: {{ $enr->section->code }}</div>
                                @else
                                    <span class="text-muted-foreground italic">{{ __('Optional / None') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs font-semibold">
                                {{ $enr->roll_no ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-mono font-bold text-xs">₹{{ number_format($enr->fee_amount, 2) }}</div>
                                @php
                                    $feeVariant = match($enr->fee_status) {
                                        'paid' => 'success',
                                        'partial' => 'warning',
                                        'exempted' => 'secondary',
                                        default => 'destructive',
                                    };
                                @endphp
                                <x-ui.badge variant="{{ $feeVariant }}" size="sm">
                                    {{ ucfirst($enr->fee_status) }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $enrStatusVariant = match($enr->status) {
                                        'active' => 'success',
                                        'completed' => 'primary',
                                        'dropped' => 'secondary',
                                        'withdrawn' => 'warning',
                                        'discontinued', 'cancelled', 'suspended' => 'destructive',
                                        default => 'secondary',
                                    };
                                @endphp
                                <x-ui.badge variant="{{ $enrStatusVariant }}" size="sm">
                                    {{ ucfirst($enr->status) }}
                                </x-ui.badge>
                                @if($enr->reinstated_at)
                                    <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">
                                        {{ __('Reinstated') }} {{ $enr->reinstated_at->format('d M Y') }}
                                    </div>
                                @elseif($enr->cancellation_reason)
                                    <div class="text-[10px] text-muted-foreground truncate max-w-[130px]" title="{{ $enr->cancellation_reason }}">
                                        {{ Str::limit($enr->cancellation_reason, 20) }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="viewDetails({{ $enr->id }})"
                                        class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted cursor-pointer transition-colors"
                                        title="{{ __('View Details') }}"
                                    >
                                        <x-icon name="eye" class="w-4 h-4" />
                                    </button>

                                    @if(in_array($enr->status, ['active', 'suspended']))
                                        @if(auth()->user()?->can('enrollments.discontinue') || auth()->user()?->can('students.edit'))
                                        <button
                                            type="button"
                                            wire:click="openDirectCancelModal({{ $enr->id }})"
                                            class="p-1 rounded-md text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:hover:bg-amber-950/40 cursor-pointer transition-colors"
                                            title="{{ __('Cancel / Discontinue Enrollment') }}"
                                        >
                                            <x-icon name="user-x" class="w-4 h-4" />
                                        </button>
                                        @endif
                                    @endif

                                    @if($enr->canBeReinstated())
                                        @if(auth()->user()?->can('enrollments.reinstate') || auth()->user()?->can('students.edit'))
                                        <button
                                            type="button"
                                            wire:click="openReinstateModal({{ $enr->id }})"
                                            class="p-1 rounded-md text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 cursor-pointer transition-colors"
                                            title="{{ __('Revert Discontinuation / Reinstate to Active') }}"
                                        >
                                            <x-icon name="rotate-ccw" class="w-4 h-4" />
                                        </button>
                                        @endif
                                    @endif

                                    @can('students.edit')
                                    <button
                                        type="button"
                                        wire:click="edit({{ $enr->id }})"
                                        class="p-1 rounded-md text-sky-600 hover:text-sky-700 hover:bg-sky-50 dark:hover:bg-sky-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Edit Enrollment') }}"
                                    >
                                        <x-icon name="edit" class="w-4 h-4" />
                                    </button>
                                    @endcan

                                    @can('students.delete')
                                    <button
                                        type="button"
                                        wire:click="selectForDelete({{ $enr->id }})"
                                        class="p-1 rounded-md text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Delete Enrollment') }}"
                                    >
                                        <x-icon name="trash-2" class="w-4 h-4" />
                                    </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="book-open" class="w-8 h-8 text-muted-foreground/60" />
                                    <span class="text-base font-medium">{{ __('No enrollments found matching criteria') }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $this->enrollments->links() }}
        </div>
    </div>

    <!-- Create / Edit Enrollment Modal -->
    <x-ui.modal
        name="enrollment-form-modal"
        max-width="max-w-xl"
        :title="$editingId ? __('Edit Enrollment: :no', ['no' => $form['roll_no'] ?: 'Record']) : __('Enroll Student in Course')"
        :description="__('Assign candidate into batch and course with optional section assignment and fee records.')"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Candidate Student *') }}</label>
                    <select wire:model.live="form.user_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        <option value="">{{ __('Select Student') }}</option>
                        @foreach($this->eligibleStudents as $st)
                            <option value="{{ $st->id }}">
                                {{ $st->name }} (ID: {{ $st->student_id ?? 'Pending' }}) - {{ $st->email }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Batch Session *') }}</label>
                    <select wire:model.live="form.batch_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        <option value="">{{ __('Select Batch') }}</option>
                        @foreach($this->batches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Course Programme *') }}</label>
                    <select wire:model.live="form.course_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        <option value="">{{ __('Select Course') }}</option>
                        @foreach($this->courses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                </div>

                <!-- Section Selection -->
                @php
                    $curCourse = ! empty($form['course_id']) ? $this->courses->firstWhere('id', (int) $form['course_id']) : null;
                    $needsSections = $curCourse?->has_sections;
                @endphp
                <div class="sm:col-span-2 space-y-1">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground">
                            {{ __('Section Assignment') }}
                            @if($needsSections)
                                <span class="text-primary font-bold">({{ __('Cohort Selection for :code', ['code' => $curCourse->code]) }})</span>
                            @else
                                <span class="text-muted-foreground font-normal">({{ __('Optional') }})</span>
                            @endif
                        </label>
                        @if($needsSections)
                            <span class="text-[11px] text-muted-foreground font-mono">{{ __('Sets ID digit: Sec A = 1, Sec B = 2') }}</span>
                        @endif
                    </div>
                    <select wire:model="form.section_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="">{{ $needsSections ? __('-- Select Section (e.g. Section A or B) --') : __('No Section / General Cohort') }}</option>
                        @foreach($this->formSections as $sec)
                            <option value="{{ $sec->id }}">
                                {{ $sec->name }} (ID Code: {{ $sec->code }}, Cap: {{ $sec->enrolled_count }}/{{ $sec->capacity }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <x-ui.input
                    wire:model="form.roll_no"
                    label="{{ __('Roll Number (Optional)') }}"
                    placeholder="{{ __('e.g. CC-2026-A-01') }}"
                />

                <x-ui.input
                    wire:model="form.application_no"
                    label="{{ __('Application No. (Optional)') }}"
                    placeholder="{{ __('e.g. APP-2026-0042') }}"
                />

                <x-ui.input
                    wire:model="form.enrolled_at"
                    type="date"
                    label="{{ __('Enrolled Date *') }}"
                    required
                />

                <x-ui.input
                    wire:model="form.fee_amount"
                    type="number"
                    step="0.01"
                    label="{{ __('Fee Amount (INR) *') }}"
                    required
                />

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Fee Status *') }}</label>
                    <select wire:model="form.fee_status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        <option value="paid">{{ __('Paid') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="partial">{{ __('Partial') }}</option>
                        <option value="exempted">{{ __('Exempted') }}</option>
                    </select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Enrollment Status *') }}</label>
                    <select wire:model="form.status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="dropped">{{ __('Dropped') }}</option>
                        <option value="suspended">{{ __('Suspended') }}</option>
                        <option value="alumni">{{ __('Alumni') }}</option>
                    </select>
                </div>

                <x-ui.input
                    wire:model="form.completed_at"
                    type="date"
                    label="{{ __('Completion Date') }}"
                />
            </div>

            <x-ui.textarea
                wire:model="form.remarks"
                label="{{ __('Remarks & Notes') }}"
                placeholder="{{ __('Special comments, concessions, or administrative notes...') }}"
                rows="2"
            />

            @if(! $editingId)
                @can('students.edit')
                <label class="flex items-start gap-2 pt-1 cursor-pointer text-xs">
                    <input
                        type="checkbox"
                        wire:model="form.override_restrictions"
                        class="mt-0.5 rounded border-input text-amber-600 focus:ring-amber-500"
                    />
                    <span class="text-muted-foreground">
                        {{ __('Administrator Override: Bypass admission restriction rules if candidate previously enrolled in this course.') }}
                    </span>
                </label>
                @endcan
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('enrollment-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default">
                    {{ $editingId ? __('Update Enrollment') : __('Enroll Student') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Enrollment View Modal -->
    <x-ui.modal
        name="enrollment-view-modal"
        max-width="max-w-lg"
        :title="$viewingEnrollment ? $viewingEnrollment->user?->name : __('Enrollment Details')"
        :description="$viewingEnrollment ? __('Enrollment #: :no', ['no' => $viewingEnrollment->enrollment_no]) : ''"
    >
        @if($viewingEnrollment)
            <div class="space-y-4">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <h3 class="text-lg font-bold text-foreground">
                            {{ $viewingEnrollment->user?->name }}
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            {{ __('Enrollment #: :no', ['no' => $viewingEnrollment->enrollment_no]) }}
                        </p>
                    </div>
                    <x-ui.badge variant="{{ $viewingEnrollment->status === 'active' ? 'success' : 'secondary' }}" size="sm">
                        {{ ucfirst($viewingEnrollment->status) }}
                    </x-ui.badge>
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs p-3 bg-muted/40 rounded-lg">
                    <div>
                        <span class="text-muted-foreground">{{ __('Academic Student ID:') }}</span>
                        <div class="font-mono font-bold text-primary mt-0.5">{{ $viewingEnrollment->student_id ?: ($viewingEnrollment->user?->student_id ?? 'N/A') }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Roll Number:') }}</span>
                        <div class="font-mono font-bold mt-0.5 text-foreground">{{ $viewingEnrollment->roll_no ?? '—' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Application Number:') }}</span>
                        <div class="font-mono font-bold mt-0.5 text-foreground">{{ $viewingEnrollment->application_no ?? '—' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Batch:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingEnrollment->batch?->name }} ({{ $viewingEnrollment->batch?->academic_year }})</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Course Programme:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingEnrollment->course?->name }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Section Allocation:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingEnrollment->section?->name ?? 'None' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Enrolled Date:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingEnrollment->enrolled_at ? $viewingEnrollment->enrolled_at->format('d M Y') : '—' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Fee Amount:') }}</span>
                        <div class="font-mono font-bold text-emerald-600 mt-0.5">₹{{ number_format($viewingEnrollment->fee_amount, 2) }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Fee Status:') }}</span>
                        <div class="font-semibold capitalize mt-0.5 text-foreground">{{ $viewingEnrollment->fee_status }}</div>
                    </div>
                </div>

                @if($viewingEnrollment->cancellation_reason || $viewingEnrollment->discontinued_at)
                    <div class="p-3 text-xs bg-rose-50/70 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/50 rounded-lg space-y-1">
                        <div class="flex items-center gap-1.5 font-bold text-rose-700 dark:text-rose-400">
                            <x-icon name="alert-circle" class="w-4 h-4" />
                            <span>{{ __('Course Discontinuation / Cancellation Record') }}</span>
                        </div>
                        <div class="text-[11px] text-muted-foreground">
                            <strong>{{ __('Status:') }}</strong> <span class="capitalize font-semibold text-rose-600 dark:text-rose-400">{{ $viewingEnrollment->status }}</span>
                            @if($viewingEnrollment->discontinued_at)
                                &bull; <strong>{{ __('Effective Date:') }}</strong> {{ $viewingEnrollment->discontinued_at->format('d M Y') }}
                            @endif
                            @if($viewingEnrollment->discontinuedBy)
                                &bull; <strong>{{ __('By:') }}</strong> {{ $viewingEnrollment->discontinuedBy->name }}
                            @endif
                        </div>
                        <div class="mt-1 text-foreground font-medium bg-background/80 p-2 rounded border border-rose-100 dark:border-rose-900/30">
                            {{ $viewingEnrollment->cancellation_reason }}
                        </div>
                        @if($viewingEnrollment->canBeReinstated() && (auth()->user()?->can('enrollments.reinstate') || auth()->user()?->can('students.edit')))
                            <div class="pt-2 flex justify-end">
                                <x-ui.button
                                    type="button"
                                    size="xs"
                                    variant="default"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white gap-1.5 cursor-pointer"
                                    wire:click="openReinstateModal({{ $viewingEnrollment->id }})"
                                >
                                    <x-icon name="rotate-ccw" class="w-3.5 h-3.5" />
                                    {{ __('Reinstate This Enrollment to Active') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @endif

                @if($viewingEnrollment->reinstated_at)
                    <div class="p-3 text-xs bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg space-y-1">
                        <div class="flex items-center gap-1.5 font-bold text-emerald-700 dark:text-emerald-400">
                            <x-icon name="check-circle" class="w-4 h-4" />
                            <span>{{ __('Course Reinstatement Record') }}</span>
                        </div>
                        <div class="text-[11px] text-muted-foreground">
                            <strong>{{ __('Status:') }}</strong> <span class="capitalize font-semibold text-emerald-600 dark:text-emerald-400">{{ $viewingEnrollment->status }}</span>
                            &bull; <strong>{{ __('Reinstated Date:') }}</strong> {{ $viewingEnrollment->reinstated_at->format('d M Y H:i') }}
                            @if($viewingEnrollment->reinstatedBy)
                                &bull; <strong>{{ __('By:') }}</strong> {{ $viewingEnrollment->reinstatedBy->name }}
                            @endif
                        </div>
                        @if($viewingEnrollment->reinstatement_reason)
                            <div class="mt-1 text-foreground font-medium bg-background/80 p-2 rounded border border-emerald-100 dark:border-emerald-900/30">
                                {{ $viewingEnrollment->reinstatement_reason }}
                            </div>
                        @endif
                    </div>
                @endif

                @if($viewingEnrollment->remarks)
                    <div class="p-3 text-xs bg-card border border-border rounded-lg">
                        <span class="font-semibold block mb-1 text-foreground">{{ __('Remarks:') }}</span>
                        <p class="text-muted-foreground">{{ $viewingEnrollment->remarks }}</p>
                    </div>
                @endif

                <div class="flex justify-end pt-2 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('enrollment-view-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Enrollment Import Modal -->
    <x-ui.modal
        name="enrollment-import-modal"
        max-width="max-w-lg"
        :title="__('Bulk Import Enrollments')"
        :description="__('Batch assign students to courses via Excel (.xlsx) or CSV file.')"
    >
        <div class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-lg text-xs space-y-2 border border-border">
                <span class="font-bold text-foreground block">{{ __('Download Sample Template:') }}</span>
                <div class="flex gap-2">
                    <x-ui.button size="xs" variant="outline" wire:click="downloadTemplate('csv')" class="gap-1">
                        <x-icon name="file-text" class="w-3.5 h-3.5"/>
                        {{ __('CSV Template') }}
                    </x-ui.button>
                    <x-ui.button size="xs" variant="outline" wire:click="downloadTemplate('xlsx')" class="gap-1">
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5"/>
                        {{ __('Excel (.xlsx) Template') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="space-y-1">
                <label class="text-xs font-semibold text-foreground">{{ __('Choose File *') }}</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,.xlsx,.xls,.txt"
                    class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs file:mr-2 file:border-0 file:bg-transparent file:text-xs file:font-medium"
                />
            </div>

            @if($importSummary)
                <div class="p-3 rounded-lg border text-xs {{ $importSummary['failed'] > 0 ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300' : 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300' }}">
                    <div class="font-bold">
                        {{ __('Import Results: :success created, :failed failed.', ['success' => $importSummary['success'], 'failed' => $importSummary['failed']]) }}
                    </div>
                    @if(!empty($importSummary['errors']))
                        <ul class="mt-2 list-disc list-inside space-y-1 text-rose-600 dark:text-rose-400 max-h-32 overflow-y-auto">
                            @foreach(array_slice($importSummary['errors'], 0, 5) as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('enrollment-import-modal')">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button variant="default" wire:click="processImport">
                    {{ __('Run Import') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal
        name="enrollment-delete-modal"
        max-width="max-w-md"
        :title="__('Confirm Enrollment Deletion')"
        :description="__('This action will remove the candidate course enrollment.')"
    >
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-destructive/10 text-destructive">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ __('Are you sure you want to delete this enrollment? Historical audit records will be maintained.') }}
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('enrollment-delete-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" wire:click="deleteSelected">
                    {{ __('Delete Enrollment') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Direct Cancel / Discontinue Enrollment Modal -->
    <x-ui.modal
        name="enrollment-direct-cancel-modal"
        max-width="max-w-lg"
        :title="__('Cancel or Discontinue Enrollment')"
        :description="__('Direct administrative action to discontinue or cancel a student enrollment record.')"
    >
        @if($directCancelEnrollment)
            <form wire:submit="executeDirectCancel" class="space-y-4">
                <div class="p-3 bg-muted/40 rounded-lg text-xs space-y-1 border border-border">
                    <div class="flex items-center justify-between font-semibold">
                        <span class="text-foreground">{{ $directCancelEnrollment->user?->name }}</span>
                        <span class="font-mono text-primary">{{ $directCancelEnrollment->enrollment_no }}</span>
                    </div>
                    <div class="text-muted-foreground">
                        {{ $directCancelEnrollment->course?->code }} - {{ $directCancelEnrollment->course?->name }} ({{ $directCancelEnrollment->batch?->name }})
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">{{ __('Administrative Action / Target Status *') }}</label>
                        <select wire:model="directCancelForm.status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                            <option value="cancelled">{{ __('Cancelled (Official Admission Revocation / Nullified)') }}</option>
                            <option value="discontinued">{{ __('Discontinued (Administrative / Student Discontinuation)') }}</option>
                            <option value="withdrawn">{{ __('Withdrawn (Voluntary / Mutual Withdrawal)') }}</option>
                            <option value="dropped">{{ __('Dropped (Prolonged Absence / Dropout)') }}</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">{{ __('Cancellation Reason Category *') }}</label>
                        <select wire:model="directCancelForm.reason" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                            <option value="disciplinary">{{ __('Disciplinary Sanction / Misconduct') }}</option>
                            <option value="non_attendance">{{ __('Prolonged Non-Attendance / Absenteeism') }}</option>
                            <option value="failed_qualification">{{ __('Ineligible / Failed Minimum Criteria') }}</option>
                            <option value="fee_default">{{ __('Non-Payment of Course Fees') }}</option>
                            <option value="student_request">{{ __('Direct Student / Guardian Request') }}</option>
                            <option value="upsc_selection">{{ __('Selected in UPSC / State Civil Services') }}</option>
                            <option value="other_job">{{ __('Selected in Other Government / Corporate Job') }}</option>
                            <option value="administrative">{{ __('Administrative Decision / Course Rescheduling') }}</option>
                            <option value="other">{{ __('Other Specific Justification') }}</option>
                        </select>
                    </div>

                    <x-ui.input
                        wire:model="directCancelForm.effective_date"
                        type="date"
                        label="{{ __('Effective Discontinuation Date *') }}"
                        required
                    />

                    <x-ui.textarea
                        wire:model="directCancelForm.notes"
                        label="{{ __('Administrative Order / Reason Notes *') }}"
                        placeholder="{{ __('Specify the official justification, order reference, competent authority decision, or findings...') }}"
                        rows="3"
                        required
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('enrollment-direct-cancel-modal')">
                        {{ __('Abort') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="destructive">
                        {{ __('Confirm Discontinuation') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>

    <!-- Direct Revert / Reinstate Enrollment Modal -->
    <x-ui.modal
        name="enrollment-reinstate-modal"
        max-width="max-w-lg"
        :title="__('Reinstate Student Enrollment to Active')"
        :description="__('Systematically revert course withdrawal or discontinuation and restore student enrollment status to active.')"
    >
        @if($reinstateEnrollment)
            <form wire:submit="executeReinstate" class="space-y-4">
                <div class="p-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs space-y-1.5">
                    <div class="flex items-center justify-between font-bold text-emerald-900 dark:text-emerald-200">
                        <span>{{ $reinstateEnrollment->user?->name }}</span>
                        <span class="font-mono text-emerald-700 dark:text-emerald-400">{{ $reinstateEnrollment->enrollment_no }}</span>
                    </div>
                    <div class="text-emerald-800 dark:text-emerald-300">
                        {{ $reinstateEnrollment->course?->code }} - {{ $reinstateEnrollment->course?->name }} ({{ $reinstateEnrollment->batch?->name }})
                        @if($reinstateEnrollment->section)
                            &bull; {{ $reinstateEnrollment->section->name }}
                        @endif
                    </div>
                    <div class="flex items-center gap-2 pt-1">
                        <span class="text-muted-foreground">{{ __('Current State:') }}</span>
                        <x-ui.badge variant="destructive" size="sm">
                            {{ ucfirst($reinstateEnrollment->status) }}
                        </x-ui.badge>
                        @if($reinstateEnrollment->discontinued_at)
                            <span class="text-[11px] text-muted-foreground">({{ __('Since') }} {{ $reinstateEnrollment->discontinued_at->format('d M Y') }})</span>
                        @endif
                    </div>
                    @if($reinstateEnrollment->cancellation_reason)
                        <div class="p-2 bg-background/80 rounded border border-emerald-100 dark:border-emerald-900 text-[11px] text-muted-foreground mt-1">
                            <strong>{{ __('Previous Cancellation / Withdrawal Grounds:') }}</strong>
                            <p class="italic text-foreground mt-0.5">{{ $reinstateEnrollment->cancellation_reason }}</p>
                        </div>
                    @endif
                </div>

                <div class="space-y-3">
                    <x-ui.input
                        wire:model="reinstateForm.order_number"
                        label="{{ __('Reinstatement Order / Decision Reference # (Optional)') }}"
                        placeholder="{{ __('e.g. ORD-REINSTATE-2026-004') }}"
                    />

                    <x-ui.textarea
                        wire:model="reinstateForm.reason"
                        label="{{ __('Administrative Reinstatement Grounds / Notes *') }}"
                        placeholder="{{ __('Specify official reason for reinstatement (e.g. medical clearance, Director approval, appeal accepted, clerical error reversion)...') }}"
                        rows="3"
                        required
                    />

                    <div class="p-2.5 bg-muted/40 rounded-lg border border-border">
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input
                                type="checkbox"
                                wire:model="reinstateForm.activate_user"
                                class="rounded border-input text-primary focus:ring-ring"
                            />
                            <span class="font-medium text-foreground">
                                {{ __('Also restore student account status to "Active" (if currently inactive or discontinued)') }}
                            </span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('enrollment-reinstate-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="default" class="bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer">
                        <x-icon name="rotate-ccw" class="w-4 h-4 mr-1.5" />
                        {{ __('Confirm Reinstatement to Active') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>
</div>
