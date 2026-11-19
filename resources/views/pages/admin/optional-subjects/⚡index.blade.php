<?php

use App\Concerns\InteractsWithDataTable;
use App\Imports\TableImport;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OptionalSubject;
use App\Models\OptionalSubjectChangeRequest;
use App\Models\Section;
use App\Models\StudentOtherDetail;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new #[Layout('layouts.app')] #[Title('Optional Subject Management & Reports')] class extends Component {
    use InteractsWithDataTable, WithFileUploads, WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'allocations'; // allocations, requests, catalog, reports

    // Filters for Allocations Tab
    public string $batchFilter = '';
    public string $courseFilter = '';
    public string $sectionFilter = '';
    public string $subjectFilter = '';
    public string $assignmentStatusFilter = ''; // all, assigned, unassigned

    // Quick Update Modal State
    public bool $showQuickUpdateModal = false;
    public ?int $quickUpdateUserId = null;
    public ?User $quickUpdateUser = null;
    public string $quickUpdateSubject = '';

    // Change Requests Filter & Modals
    public string $requestStatusFilter = 'pending'; // pending, approved, rejected, all
    public bool $showApproveModal = false;
    public bool $showRejectModal = false;
    public ?OptionalSubjectChangeRequest $activeRequest = null;
    public string $actionRemarks = '';

    // Master Catalog (CRUD) Modal State
    public bool $showSubjectModal = false;
    public ?int $editingSubjectId = null;
    public array $subjectForm = [
        'name' => '',
        'code' => '',
        'category' => 'Humanities & Social Sciences',
        'description' => '',
        'max_capacity' => null,
        'is_active' => true,
    ];
    public bool $showDeleteSubjectModal = false;
    public ?int $deletingSubjectId = null;

    // Import State
    public bool $showImportModal = false;
    public ?TemporaryUploadedFile $importFile = null;

    // Report Generation Filter State
    public string $reportBatchId = '';
    public string $reportCourseId = '';
    public string $reportSectionId = '';
    public string $reportSubjectName = '';

    private const SEARCHABLE = ['name', 'student_id', 'email', 'phone'];

    public function mount(): void
    {
        if (! in_array($this->activeTab, ['allocations', 'requests', 'catalog', 'reports'], true)) {
            $this->activeTab = 'allocations';
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetDataTablePage();
    }

    public function updatedSearch(): void
    {
        $this->resetDataTablePage();
    }

    public function updatedBatchFilter(): void
    {
        $this->resetDataTablePage();
    }

    public function updatedCourseFilter(): void
    {
        $this->resetDataTablePage();
    }

    public function updatedSectionFilter(): void
    {
        $this->resetDataTablePage();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetDataTablePage();
    }

    public function updatedAssignmentStatusFilter(): void
    {
        $this->resetDataTablePage();
    }

    public function updatedRequestStatusFilter(): void
    {
        $this->resetPage('requestsPage');
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->batchFilter = '';
        $this->courseFilter = '';
        $this->sectionFilter = '';
        $this->subjectFilter = '';
        $this->assignmentStatusFilter = '';
        $this->resetDataTablePage();
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return ! empty($this->search)
            || ! empty($this->batchFilter)
            || ! empty($this->courseFilter)
            || ! empty($this->sectionFilter)
            || ! empty($this->subjectFilter)
            || ! empty($this->assignmentStatusFilter);
    }

    #[Computed]
    public function batches()
    {
        return Batch::query()->orderBy('name')->get();
    }

    #[Computed]
    public function courses()
    {
        return Course::query()->orderBy('name')->get();
    }

    #[Computed]
    public function sections()
    {
        return Section::query()->orderBy('name')->get();
    }

    #[Computed]
    public function activeSubjectsList()
    {
        return OptionalSubject::active()->orderBy('name')->get();
    }

    #[Computed]
    public function allSubjectsList()
    {
        $counts = StudentOtherDetail::query()
            ->whereNotNull('optional_subject')
            ->where('optional_subject', '!=', '')
            ->select('optional_subject', DB::raw('count(*) as count'))
            ->groupBy('optional_subject')
            ->pluck('count', 'optional_subject')
            ->toArray();

        return OptionalSubject::query()
            ->orderBy('name')
            ->get()
            ->map(function ($sub) use ($counts) {
                $sub->students_count = $counts[$sub->name] ?? 0;

                return $sub;
            });
    }

    #[Computed]
    public function stats(): array
    {
        $totalStudents = User::role('Student')->count();
        $assignedCount = User::role('Student')
            ->whereHas('studentOtherDetails', fn ($q) => $q->whereNotNull('optional_subject')->where('optional_subject', '!=', ''))
            ->count();
        $unassignedCount = $totalStudents - $assignedCount;
        $pendingRequests = OptionalSubjectChangeRequest::pending()->count();
        $totalSubjects = OptionalSubject::count();

        return [
            'total_students' => $totalStudents,
            'assigned_count' => $assignedCount,
            'unassigned_count' => $unassignedCount,
            'pending_requests' => $pendingRequests,
            'total_subjects' => $totalSubjects,
        ];
    }

    /* -------------------------------------------------------------
     *  ALLOCATIONS & QUICK UPDATE
     * ------------------------------------------------------------- */

    public function allocationsQuery(): Builder
    {
        return User::query()
            ->role('Student')
            ->with([
                'studentOtherDetails',
                'activeEnrollments.batch',
                'activeEnrollments.course',
                'activeEnrollments.section',
                'enrollments.batch',
                'enrollments.course',
                'enrollments.section',
            ])
            ->when($this->batchFilter, fn ($q) => $q->whereHas('enrollments', fn ($eq) => $eq->where('batch_id', $this->batchFilter)))
            ->when($this->courseFilter, fn ($q) => $q->whereHas('enrollments', fn ($eq) => $eq->where('course_id', $this->courseFilter)))
            ->when($this->sectionFilter, fn ($q) => $q->whereHas('enrollments', fn ($eq) => $eq->where('section_id', $this->sectionFilter)))
            ->when($this->subjectFilter, fn ($q) => $q->whereHas('studentOtherDetails', fn ($oq) => $oq->where('optional_subject', $this->subjectFilter)))
            ->when($this->assignmentStatusFilter === 'assigned', fn ($q) => $q->whereHas('studentOtherDetails', fn ($oq) => $oq->whereNotNull('optional_subject')->where('optional_subject', '!=', '')))
            ->when($this->assignmentStatusFilter === 'unassigned', fn ($q) => $q->where(function ($subQ) {
                $subQ->whereDoesntHave('studentOtherDetails')
                    ->orWhereHas('studentOtherDetails', fn ($oq) => $oq->whereNull('optional_subject')->orWhere('optional_subject', ''));
            }))
            ->latest('id');
    }

    #[Computed]
    public function studentAllocations()
    {
        return $this->paginateDataTable(
            $this->applyDataTable($this->allocationsQuery(), self::SEARCHABLE)
        );
    }

    public function openQuickUpdate(int $userId): void
    {
        $this->authorize('optional_subjects.manage');
        $this->quickUpdateUser = User::with('studentOtherDetails')->find($userId);

        if ($this->quickUpdateUser) {
            $this->quickUpdateUserId = $userId;
            $this->quickUpdateSubject = (string) ($this->quickUpdateUser->studentOtherDetails?->optional_subject ?? '');
            $this->showQuickUpdateModal = true;
        }
    }

    public function saveQuickUpdate(): void
    {
        $this->authorize('optional_subjects.manage');

        if (! $this->quickUpdateUserId) {
            return;
        }

        $this->validate([
            'quickUpdateSubject' => ['nullable', 'string', 'max:150'],
        ]);

        $user = User::findOrFail($this->quickUpdateUserId);

        if (! $user->hasRole('Student')) {
            Toast::dispatch($this, 'error', __('User must be a registered student.'));

            return;
        }

        if ($this->quickUpdateSubject) {
            $subModel = OptionalSubject::where('name', $this->quickUpdateSubject)->first();
            if ($subModel && $subModel->max_capacity) {
                $currentAssigned = StudentOtherDetail::where('optional_subject', $subModel->name)
                    ->where('user_id', '!=', $user->id)
                    ->count();
                if ($currentAssigned >= $subModel->max_capacity) {
                    Toast::dispatch($this, 'warning', __('Subject :name has reached its maximum capacity of :max students.', [
                        'name' => $subModel->name,
                        'max' => $subModel->max_capacity,
                    ]));

                    return;
                }
            }
        }

        try {
            DB::beginTransaction();

            $other = StudentOtherDetail::firstOrCreate(
                ['user_id' => $user->id],
                ['attempts_already_given' => 0]
            );

            $oldSubject = $other->optional_subject;
            $other->optional_subject = $this->quickUpdateSubject ?: null;
            $other->save();

            AuditLogService::log(
                event: 'optional_subject_updated',
                description: "Quick-updated optional subject for student #{$user->student_id} ({$user->name}) from '{$oldSubject}' to '{$other->optional_subject}'.",
                auditable: $other,
                oldValues: ['optional_subject' => $oldSubject],
                newValues: ['optional_subject' => $other->optional_subject]
            );

            DB::commit();

            $this->showQuickUpdateModal = false;
            Toast::dispatch($this, 'success', __('Optional subject updated successfully for :name.', ['name' => $user->name]));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    /* -------------------------------------------------------------
     *  CHANGE REQUESTS WORKFLOW
     * ------------------------------------------------------------- */

    public function requestsQuery(): Builder
    {
        return OptionalSubjectChangeRequest::query()
            ->with(['user.studentOtherDetails', 'user.activeEnrollments.course', 'user.activeEnrollments.batch', 'reviewer'])
            ->when($this->requestStatusFilter !== 'all', fn ($q) => $q->where('status', $this->requestStatusFilter))
            ->latest('id');
    }

    #[Computed]
    public function changeRequests()
    {
        return $this->requestsQuery()->paginate(15, ['*'], 'requestsPage');
    }

    public function openApproveModal(int $requestId): void
    {
        $this->authorize('optional_subjects.requests');
        $this->activeRequest = OptionalSubjectChangeRequest::with('user')->find($requestId);
        $this->actionRemarks = '';
        $this->showApproveModal = true;
    }

    public function confirmApprove(): void
    {
        $this->authorize('optional_subjects.requests');

        if (! $this->activeRequest) {
            return;
        }

        $subModel = OptionalSubject::where('name', $this->activeRequest->requested_subject)->first();
        if ($subModel && $subModel->max_capacity) {
            $currentAssigned = StudentOtherDetail::where('optional_subject', $subModel->name)
                ->where('user_id', '!=', $this->activeRequest->user_id)
                ->count();
            if ($currentAssigned >= $subModel->max_capacity) {
                Toast::dispatch($this, 'error', __('Cannot approve: Subject :name has reached its maximum capacity of :max students.', [
                    'name' => $subModel->name,
                    'max' => $subModel->max_capacity,
                ]));

                return;
            }
        }

        try {
            $reviewer = auth()->user();
            $this->activeRequest->approve($reviewer, $this->actionRemarks);

            $this->showApproveModal = false;
            $this->activeRequest = null;
            Toast::dispatch($this, 'success', __('Optional subject change request approved successfully. Student record updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openRejectModal(int $requestId): void
    {
        $this->authorize('optional_subjects.requests');
        $this->activeRequest = OptionalSubjectChangeRequest::with('user')->find($requestId);
        $this->actionRemarks = '';
        $this->showRejectModal = true;
    }

    public function confirmReject(): void
    {
        $this->authorize('optional_subjects.requests');

        $this->validate([
            'actionRemarks' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if (! $this->activeRequest) {
            return;
        }

        try {
            $reviewer = auth()->user();
            $this->activeRequest->reject($reviewer, $this->actionRemarks);

            $this->showRejectModal = false;
            $this->activeRequest = null;
            Toast::dispatch($this, 'info', __('Optional subject change request rejected.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    /* -------------------------------------------------------------
     *  MASTER CATALOG (CRUD)
     * ------------------------------------------------------------- */

    public function openCreateSubjectModal(): void
    {
        $this->authorize('optional_subjects.manage');
        $this->editingSubjectId = null;
        $this->subjectForm = [
            'name' => '',
            'code' => '',
            'category' => 'Humanities & Social Sciences',
            'description' => '',
            'max_capacity' => null,
            'is_active' => true,
        ];
        $this->showSubjectModal = true;
    }

    public function openEditSubjectModal(int $id): void
    {
        $this->authorize('optional_subjects.manage');
        $subject = OptionalSubject::findOrFail($id);

        $this->editingSubjectId = $id;
        $this->subjectForm = [
            'name' => $subject->name,
            'code' => $subject->code ?? '',
            'category' => $subject->category,
            'description' => $subject->description ?? '',
            'max_capacity' => $subject->max_capacity,
            'is_active' => (bool) $subject->is_active,
        ];
        $this->showSubjectModal = true;
    }

    public function saveSubject(): void
    {
        $this->authorize('optional_subjects.manage');

        $this->validate([
            'subjectForm.name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('optional_subjects', 'name')->ignore($this->editingSubjectId)->whereNull('deleted_at'),
            ],
            'subjectForm.code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('optional_subjects', 'code')->ignore($this->editingSubjectId)->whereNull('deleted_at'),
            ],
            'subjectForm.category' => ['required', 'string', 'max:100', Rule::in(OptionalSubject::categories())],
            'subjectForm.description' => ['nullable', 'string', 'max:1000'],
            'subjectForm.max_capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'subjectForm.is_active' => ['boolean'],
        ]);

        try {
            DB::beginTransaction();

            $data = [
                'name' => $this->subjectForm['name'],
                'code' => $this->subjectForm['code'] ? strtoupper($this->subjectForm['code']) : null,
                'category' => $this->subjectForm['category'],
                'description' => $this->subjectForm['description'] ?: null,
                'max_capacity' => $this->subjectForm['max_capacity'] ? (int) $this->subjectForm['max_capacity'] : null,
                'is_active' => (bool) $this->subjectForm['is_active'],
            ];

            if ($this->editingSubjectId) {
                $subject = OptionalSubject::findOrFail($this->editingSubjectId);
                $old = $subject->toArray();
                $subject->update($data);

                AuditLogService::log(
                    event: 'optional_subject_catalog_updated',
                    description: "Updated optional subject '{$subject->name}'.",
                    auditable: $subject,
                    oldValues: $old,
                    newValues: $data
                );

                Toast::dispatch($this, 'success', __('Optional subject updated successfully.'));
            } else {
                $subject = OptionalSubject::create($data);

                AuditLogService::log(
                    event: 'optional_subject_catalog_created',
                    description: "Created new optional subject '{$subject->name}' with code '{$subject->code}'.",
                    auditable: $subject,
                    newValues: $data
                );

                Toast::dispatch($this, 'success', __('New optional subject created successfully.'));
            }

            DB::commit();
            $this->showSubjectModal = false;
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function toggleSubjectStatus(int $id): void
    {
        $this->authorize('optional_subjects.manage');
        $subject = OptionalSubject::findOrFail($id);
        $subject->is_active = ! $subject->is_active;
        $subject->save();

        AuditLogService::log(
            event: 'optional_subject_status_toggled',
            description: "Toggled status for subject '{$subject->name}' to " . ($subject->is_active ? 'Active' : 'Inactive'),
            auditable: $subject
        );

        Toast::dispatch($this, 'success', __('Subject :name status updated.', ['name' => $subject->name]));
    }

    public function confirmDeleteSubject(int $id): void
    {
        $this->authorize('optional_subjects.manage');
        $this->deletingSubjectId = $id;
        $this->showDeleteSubjectModal = true;
    }

    public function deleteSubject(): void
    {
        $this->authorize('optional_subjects.manage');

        if (! $this->deletingSubjectId) {
            return;
        }

        $subject = OptionalSubject::findOrFail($this->deletingSubjectId);
        $name = $subject->name;
        $subject->delete();

        AuditLogService::log(
            event: 'optional_subject_deleted',
            description: "Deleted optional subject '{$name}'.",
            auditable: $subject
        );

        $this->showDeleteSubjectModal = false;
        $this->deletingSubjectId = null;
        Toast::dispatch($this, 'success', __('Subject :name has been deleted.', ['name' => $name]));
    }

    /* -------------------------------------------------------------
     *  IMPORT & EXPORT
     * ------------------------------------------------------------- */

    public function openImportModal(): void
    {
        $this->authorize('optional_subjects.import');
        $this->importFile = null;
        $this->showImportModal = true;
    }

    public function import(): void
    {
        $this->authorize('optional_subjects.import');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ]);

        try {
            DB::beginTransaction();

            $updatedCount = 0;
            $skippedCount = 0;

            Excel::import(new TableImport(
                rules: [
                    '*.optional_subject' => ['required', 'string'],
                ],
                rowHandler: function (array $row) use (&$updatedCount, &$skippedCount) {
                    $studentId = $row['student_id'] ?? null;
                    $email = $row['email'] ?? null;
                    $subject = trim((string) ($row['optional_subject'] ?? ''));

                    if (! $subject) {
                        $skippedCount++;
                        return;
                    }

                    $user = null;
                    if ($studentId) {
                        $user = User::where('student_id', $studentId)->first();
                    }
                    if (! $user && $email) {
                        $user = User::where('email', $email)->first();
                    }

                    if ($user && $user->hasRole('Student')) {
                        $other = StudentOtherDetail::firstOrCreate(
                            ['user_id' => $user->id],
                            ['attempts_already_given' => 0]
                        );
                        $other->optional_subject = $subject;
                        $other->save();
                        $updatedCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            ), $this->importFile->getRealPath());

            AuditLogService::log(
                event: 'optional_subjects_imported',
                description: "Bulk imported optional subjects assignments: {$updatedCount} updated, {$skippedCount} skipped."
            );

            DB::commit();

            $this->showImportModal = false;
            $this->importFile = null;
            Toast::dispatch($this, 'success', __(':count student optional subjects updated successfully (:skipped rows skipped).', [
                'count' => $updatedCount,
                'skipped' => $skippedCount,
            ]));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function downloadTemplate()
    {
        $this->authorize('optional_subjects.import');

        $sampleData = [
            ['student_id' => 'STU-2026-0001', 'email' => 'student1@example.com', 'optional_subject' => 'Geography'],
            ['student_id' => 'STU-2026-0002', 'email' => 'student2@example.com', 'optional_subject' => 'Political Science and International Relations'],
            ['student_id' => 'STU-2026-0003', 'email' => 'student3@example.com', 'optional_subject' => 'History'],
        ];

        return response()->streamDownload(function () use ($sampleData) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['student_id', 'email', 'optional_subject']);
            foreach ($sampleData as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'optional-subjects-import-template.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportAllocations(string $format = 'xlsx')
    {
        $this->authorize('optional_subjects.export');

        $headings = [
            'Student ID',
            'Full Name',
            'Email',
            'Phone',
            'Course',
            'Batch',
            'Section',
            'Roll Number',
            'Optional Subject',
            'Attempts Given',
            'Attempts Left',
        ];

        return TableExporter::download(
            query: $this->allocationsQuery(),
            format: $format,
            filename: 'student-optional-subjects-roster',
            headings: $headings,
            mapper: function (User $s): array {
                $enr = $s->activeEnrollments->first() ?? $s->enrollments->first();
                $other = $s->studentOtherDetails;
                return [
                    $s->student_id ?: 'N/A',
                    $s->name,
                    $s->email,
                    $s->phone ?: '—',
                    $enr?->course?->name ?? '—',
                    $enr?->batch?->name ?? '—',
                    $enr?->section?->name ?? '—',
                    $enr?->roll_no ?: '—',
                    $other?->optional_subject ?: 'Not Selected',
                    $other?->attempts_already_given ?? 0,
                    $other?->attempts_left ?? '—',
                ];
            },
            title: 'Student Optional Subjects Allocation Roster'
        );
    }

    /* -------------------------------------------------------------
     *  DYNAMIC REPORT GENERATION
     * ------------------------------------------------------------- */

    #[Computed]
    public function subjectDistribution(): array
    {
        $query = StudentOtherDetail::query()
            ->whereNotNull('optional_subject')
            ->where('optional_subject', '!=', '');

        // Scope by batch/course/section if requested
        if ($this->reportBatchId || $this->reportCourseId || $this->reportSectionId) {
            $query->whereHas('user.enrollments', function ($eq) {
                if ($this->reportBatchId) {
                    $eq->where('batch_id', $this->reportBatchId);
                }
                if ($this->reportCourseId) {
                    $eq->where('course_id', $this->reportCourseId);
                }
                if ($this->reportSectionId) {
                    $eq->where('section_id', $this->reportSectionId);
                }
            });
        }

        $counts = $query->select('optional_subject', DB::raw('count(*) as count'))
            ->groupBy('optional_subject')
            ->orderByDesc('count')
            ->get();

        $totalAssigned = $counts->sum('count');

        return $counts->map(function ($row) use ($totalAssigned) {
            $percentage = $totalAssigned > 0 ? round(($row->count / $totalAssigned) * 100, 1) : 0;
            return [
                'subject' => $row->optional_subject,
                'count' => (int) $row->count,
                'percentage' => $percentage,
            ];
        })->all();
    }

    public function exportReport(string $format = 'xlsx')
    {
        $this->authorize('optional_subjects.export');

        $distribution = $this->subjectDistribution;
        $total = array_sum(array_column($distribution, 'count'));

        $headings = [
            'Sl No.',
            'Optional Subject',
            'Candidate Count',
            'Percentage Share (%)',
        ];

        $rows = [];
        foreach ($distribution as $i => $item) {
            $rows[] = [
                $i + 1,
                $item['subject'],
                $item['count'],
                $item['percentage'] . '%',
            ];
        }

        // Add summary row
        $rows[] = ['Total', 'All Optional Subjects', $total, '100%'];

        $filename = 'optional-subjects-distribution-report-' . now()->format('Ymd-His');

        if ($format === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.table', [
                'title' => 'Optional Subject Distribution & Analytics Report',
                'generatedAt' => now(),
                'headings' => $headings,
                'rows' => $rows,
            ]);

            return response()->streamDownload(fn () => print($pdf->output()), "{$filename}.pdf", [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}.pdf\"",
            ]);
        }

        return Excel::download(
            new \App\Exports\GenericArrayExport($headings, $rows),
            "{$filename}.{$format}"
        );
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400">
                    <x-icon name="book-open" class="w-6 h-6" />
                </span>
                {{ __('Optional Subject Management & Reports') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Quick update student optional subjects, approve change requests, manage UPSC subject catalog, and generate batch-wise distribution reports.') }}
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            @can('optional_subjects.import')
                <x-ui.button wire:click="openImportModal" variant="outline" class="gap-1.5 text-xs font-semibold">
                    <x-icon name="arrow-up-tray" class="w-4 h-4" />
                    {{ __('Import Spreadsheet') }}
                </x-ui.button>
            @endcan

            @can('optional_subjects.manage')
                <x-ui.button wire:click="openCreateSubjectModal" variant="primary" class="gap-1.5 text-xs font-semibold">
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('New Subject') }}
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Real-time Dashboard Statistics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Enrolled Students') }}</span>
                <span class="p-2 rounded-xl bg-blue-500/10 text-blue-600"><x-icon name="users" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mt-2">{{ number_format($this->stats['total_students']) }}</div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('Total student cohort') }}</span>
        </div>

        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Subject Selected') }}</span>
                <span class="p-2 rounded-xl bg-emerald-500/10 text-emerald-600"><x-icon name="check-badge" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 mt-2">{{ number_format($this->stats['assigned_count']) }}</div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('Allocated optional subjects') }}</span>
        </div>

        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Pending Selection') }}</span>
                <span class="p-2 rounded-xl bg-amber-500/10 text-amber-600"><x-icon name="clock" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-amber-600 mt-2">{{ number_format($this->stats['unassigned_count']) }}</div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('Unassigned / not chosen') }}</span>
        </div>

        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs cursor-pointer hover:border-purple-300 dark:hover:border-purple-700 transition" wire:click="setTab('requests')">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Change Requests') }}</span>
                <span class="p-2 rounded-xl bg-purple-500/10 text-purple-600"><x-icon name="arrow-path" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-purple-600 mt-2 flex items-center gap-2">
                {{ number_format($this->stats['pending_requests']) }}
                @if($this->stats['pending_requests'] > 0)
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 font-bold uppercase tracking-wider animate-pulse">Action Required</span>
                @endif
            </div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('Awaiting administrative review') }}</span>
        </div>

        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs cursor-pointer hover:border-indigo-300 dark:hover:border-indigo-700 transition" wire:click="setTab('catalog')">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Subjects Catalog') }}</span>
                <span class="p-2 rounded-xl bg-indigo-500/10 text-indigo-600"><x-icon name="book-open" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mt-2">{{ number_format($this->stats['total_subjects']) }}</div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('UPSC subjects registered') }}</span>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-zinc-200 dark:border-zinc-800">
        <nav class="flex space-x-2 sm:space-x-4" aria-label="Tabs">
            <button
                type="button"
                wire:click="setTab('allocations')"
                class="px-4 py-2.5 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-2 cursor-pointer {{ $activeTab === 'allocations' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="user-group" class="w-4 h-4" />
                <span>{{ __('Student Allocations & Quick Update') }}</span>
            </button>

            <button
                type="button"
                wire:click="setTab('requests')"
                class="px-4 py-2.5 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-2 cursor-pointer {{ $activeTab === 'requests' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="arrow-path-rounded-square" class="w-4 h-4" />
                <span>{{ __('Change Requests Approval Queue') }}</span>
                @if($this->stats['pending_requests'] > 0)
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 font-bold font-mono">{{ $this->stats['pending_requests'] }}</span>
                @endif
            </button>

            <button
                type="button"
                wire:click="setTab('reports')"
                class="px-4 py-2.5 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-2 cursor-pointer {{ $activeTab === 'reports' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="chart-pie" class="w-4 h-4" />
                <span>{{ __('Analytics & Reports Generation') }}</span>
            </button>

            <button
                type="button"
                wire:click="setTab('catalog')"
                class="px-4 py-2.5 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-2 cursor-pointer {{ $activeTab === 'catalog' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="list-bullet" class="w-4 h-4" />
                <span>{{ __('Subjects Master Catalog (CRUD)') }}</span>
            </button>
        </nav>
    </div>

    <!-- TAB 1: STUDENT ALLOCATIONS & QUICK UPDATE -->
    @if($activeTab === 'allocations')
        <div class="space-y-4">
            <!-- Toolbar & Multi-dimensional Filters -->
            <div class="p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                    <div class="lg:col-span-2">
                        <x-ui.input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search candidate by name, student ID, email...') }}"
                            icon="magnifying-glass"
                        />
                    </div>

                    <select wire:model.live="batchFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                        <option value="">{{ __('All Batches') }}</option>
                        @foreach($this->batches as $batch)
                            <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="courseFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                        <option value="">{{ __('All Courses') }}</option>
                        @foreach($this->courses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="sectionFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                        <option value="">{{ __('All Sections') }}</option>
                        @foreach($this->sections as $sec)
                            <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="assignmentStatusFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                        <option value="">{{ __('All Allocation Statuses') }}</option>
                        <option value="assigned">{{ __('Subject Assigned') }}</option>
                        <option value="unassigned">{{ __('Pending Selection') }}</option>
                    </select>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-semibold text-zinc-500">{{ __('Filter by Subject:') }}</span>
                        <select wire:model.live="subjectFilter" class="h-8 rounded-md border border-input bg-card px-2.5 text-xs shadow-xs outline-none max-w-xs">
                            <option value="">{{ __('All Optional Subjects') }}</option>
                            @foreach($this->allSubjectsList as $sub)
                                <option value="{{ $sub->name }}">{{ $sub->name }}</option>
                            @endforeach
                        </select>
                        @if($this->hasActiveFilters)
                            <button
                                type="button"
                                wire:click="resetFilters"
                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-lg border border-rose-200 dark:border-rose-800 text-rose-600 dark:text-rose-400 bg-rose-50/50 dark:bg-rose-950/20 hover:bg-rose-100 transition cursor-pointer"
                            >
                                <x-icon name="x-mark" class="w-3.5 h-3.5" />
                                <span>{{ __('Clear Filters') }}</span>
                            </button>
                        @endif
                    </div>

                    <!-- Export Buttons -->
                    <div class="flex items-center gap-1.5 self-end">
                        <span class="text-xs font-semibold text-zinc-500 mr-1">{{ __('Export Roster:') }}</span>
                        <button wire:click="exportAllocations('xlsx')" class="px-2.5 py-1 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-zinc-50 transition">{{ __('Excel') }}</button>
                        <button wire:click="exportAllocations('csv')" class="px-2.5 py-1 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-zinc-50 transition">{{ __('CSV') }}</button>
                        <button wire:click="exportAllocations('pdf')" class="px-2.5 py-1 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-zinc-50 transition">{{ __('PDF') }}</button>
                    </div>
                </div>
            </div>

            <!-- Allocations Table -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 uppercase tracking-wider font-semibold">
                            <tr>
                                <th class="px-4 py-3">{{ __('Candidate / Student ID') }}</th>
                                <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                                <th class="px-4 py-3">{{ __('Roll / Section') }}</th>
                                <th class="px-4 py-3">{{ __('Current Optional Subject') }}</th>
                                <th class="px-4 py-3">{{ __('Attempts Given / Left') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Quick Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($this->studentAllocations as $student)
                                @php
                                    $enr = $student->activeEnrollments->first() ?? $student->enrollments->first();
                                    $other = $student->studentOtherDetails;
                                    $optionalSub = $other?->optional_subject;
                                @endphp
                                <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300 font-bold flex items-center justify-center shrink-0 border border-purple-200 dark:border-purple-800/50">
                                                {{ substr($student->name, 0, 1) }}
                                            </div>
                                            <div>
                                                <div class="font-bold text-zinc-900 dark:text-zinc-100">{{ $student->name }}</div>
                                                <div class="flex items-center gap-1.5 mt-0.5">
                                                    <span class="font-mono text-[10px] text-blue-600 dark:text-blue-400 font-semibold">ID: {{ $student->student_id ?: 'N/A' }}</span>
                                                    <span class="text-zinc-400">&bull;</span>
                                                    <span class="text-[11px] text-zinc-500">{{ $student->email }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $enr?->course?->name ?? '—' }}</div>
                                        <div class="text-[11px] text-zinc-500">{{ $enr?->batch?->name ?? '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-mono font-bold text-zinc-800 dark:text-zinc-200">{{ $enr?->roll_no ?: '—' }}</div>
                                        <div class="text-[11px] text-zinc-500">{{ $enr?->section?->name ?? '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($optionalSub)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60 shadow-2xs">
                                                <x-icon name="bookmark" class="w-3.5 h-3.5 text-purple-500" />
                                                <span>{{ $optionalSub }}</span>
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-800/50">
                                                <x-icon name="exclamation-circle" class="w-3 h-3 text-amber-500" />
                                                <span>{{ __('Not Assigned') }}</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-[11px] text-zinc-600 dark:text-zinc-400">
                                        <span>Given: <strong class="text-zinc-800 dark:text-zinc-200">{{ $other?->attempts_already_given ?? 0 }}</strong></span>
                                        <span class="mx-1 text-zinc-300">|</span>
                                        <span>Left: <strong class="text-zinc-800 dark:text-zinc-200">{{ $other?->attempts_left ?? '—' }}</strong></span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @can('optional_subjects.manage')
                                            <button
                                                type="button"
                                                wire:click="openQuickUpdate({{ $student->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold rounded-lg bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60 hover:bg-purple-100 dark:hover:bg-purple-900/40 transition shadow-2xs"
                                                title="{{ __('Quick update student optional subject') }}"
                                            >
                                                <x-icon name="pencil-square" class="w-3.5 h-3.5" />
                                                <span>{{ __('Quick Update') }}</span>
                                            </button>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-zinc-400 italic">
                                        {{ __('No students found matching current filters.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                    {{ $this->studentAllocations->links() }}
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 2: CHANGE REQUESTS APPROVAL QUEUE -->
    @if($activeTab === 'requests')
        <div class="space-y-4">
            <!-- Filter Toolbar -->
            <div class="p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold text-zinc-500">{{ __('Request Status:') }}</span>
                    <div class="inline-flex rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-0.5 text-xs">
                        <button
                            type="button"
                            wire:click="$set('requestStatusFilter', 'pending')"
                            class="px-3 py-1 rounded-md font-semibold transition {{ $requestStatusFilter === 'pending' ? 'bg-purple-600 text-white' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900' }}"
                        >
                            {{ __('Pending') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('requestStatusFilter', 'approved')"
                            class="px-3 py-1 rounded-md font-semibold transition {{ $requestStatusFilter === 'approved' ? 'bg-emerald-600 text-white' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900' }}"
                        >
                            {{ __('Approved') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('requestStatusFilter', 'rejected')"
                            class="px-3 py-1 rounded-md font-semibold transition {{ $requestStatusFilter === 'rejected' ? 'bg-rose-600 text-white' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900' }}"
                        >
                            {{ __('Rejected') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('requestStatusFilter', 'all')"
                            class="px-3 py-1 rounded-md font-semibold transition {{ $requestStatusFilter === 'all' ? 'bg-zinc-700 text-white' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900' }}"
                        >
                            {{ __('All') }}
                        </button>
                    </div>
                </div>

                <span class="text-xs text-zinc-400">
                    {{ __('Student-initiated requests submitted via student dashboard') }}
                </span>
            </div>

            <!-- Requests Table -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 uppercase tracking-wider font-semibold">
                            <tr>
                                <th class="px-4 py-3">{{ __('Request # / Date') }}</th>
                                <th class="px-4 py-3">{{ __('Candidate / Student ID') }}</th>
                                <th class="px-4 py-3">{{ __('Current Subject') }}</th>
                                <th class="px-4 py-3">{{ __('Requested Subject') }}</th>
                                <th class="px-4 py-3">{{ __('Reason for Change') }}</th>
                                <th class="px-4 py-3">{{ __('Status / Reviewer') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($this->changeRequests as $req)
                                <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                                    <td class="px-4 py-3">
                                        <div class="font-mono font-bold text-zinc-900 dark:text-zinc-100">{{ $req->request_no }}</div>
                                        <div class="text-[10px] text-zinc-400">{{ $req->created_at->format('d M Y, h:i A') }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-zinc-900 dark:text-zinc-100">{{ $req->user->name }}</div>
                                        <div class="text-[10px] font-mono text-blue-600 dark:text-blue-400">ID: {{ $req->user->student_id ?: 'N/A' }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-zinc-600 dark:text-zinc-400 line-through">
                                            {{ $req->current_subject ?: __('None') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-bold text-purple-600 dark:text-purple-400 flex items-center gap-1">
                                            <x-icon name="arrow-right" class="w-3 h-3 text-zinc-400" />
                                            <span>{{ $req->requested_subject }}</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 max-w-xs">
                                        <p class="text-xs text-zinc-700 dark:text-zinc-300 line-clamp-2" title="{{ $req->reason }}">
                                            {{ $req->reason }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($req->status === 'pending')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping"></span>
                                                {{ __('Pending Review') }}
                                            </span>
                                        @elseif($req->status === 'approved')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                                <x-icon name="check" class="w-3 h-3" />
                                                {{ __('Approved') }}
                                            </span>
                                            @if($req->reviewer)
                                                <div class="text-[10px] text-zinc-400 mt-0.5">by {{ $req->reviewer->name }}</div>
                                            @endif
                                        @elseif($req->status === 'rejected')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300">
                                                <x-icon name="x-mark" class="w-3 h-3" />
                                                {{ __('Rejected') }}
                                            </span>
                                            @if($req->admin_remarks)
                                                <div class="text-[10px] text-rose-500 mt-0.5" title="{{ $req->admin_remarks }}">{{ Str::limit($req->admin_remarks, 25) }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if($req->status === 'pending')
                                            @can('optional_subjects.requests')
                                                <div class="inline-flex items-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        wire:click="openApproveModal({{ $req->id }})"
                                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs transition"
                                                    >
                                                        <x-icon name="check" class="w-3.5 h-3.5" />
                                                        <span>{{ __('Approve') }}</span>
                                                    </button>

                                                    <button
                                                        type="button"
                                                        wire:click="openRejectModal({{ $req->id }})"
                                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg border border-rose-300 dark:border-rose-800 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition"
                                                    >
                                                        <x-icon name="x-mark" class="w-3.5 h-3.5" />
                                                        <span>{{ __('Reject') }}</span>
                                                    </button>
                                                </div>
                                            @endcan
                                        @else
                                            <span class="text-[11px] text-zinc-400 italic">{{ __('Completed') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-zinc-400 italic">
                                        {{ __('No change requests found matching current filter.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                    {{ $this->changeRequests->links() }}
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 3: ANALYTICS & REPORTS GENERATION -->
    @if($activeTab === 'reports')
        <div class="space-y-6">
            <!-- Filter Bar for Custom Report -->
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="funnel" class="w-4 h-4 text-purple-600" />
                            {{ __('Dynamic Report Scope & Parameters') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Filter student demographic cohort to evaluate subject popularity breakdown and generate official rosters.') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button wire:click="exportReport('xlsx')" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs transition">
                            <x-icon name="arrow-down-tray" class="w-3.5 h-3.5" />
                            {{ __('Export Excel') }}
                        </button>
                        <button wire:click="exportReport('pdf')" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-2xs transition">
                            <x-icon name="document-text" class="w-3.5 h-3.5" />
                            {{ __('Export PDF') }}
                        </button>
                        <button wire:click="exportReport('csv')" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-zinc-50 transition">
                            {{ __('CSV') }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Filter by Batch') }}</label>
                        <select wire:model.live="reportBatchId" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                            <option value="">{{ __('All Batches (Global)') }}</option>
                            @foreach($this->batches as $batch)
                                <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Filter by Course') }}</label>
                        <select wire:model.live="reportCourseId" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                            <option value="">{{ __('All Courses (Global)') }}</option>
                            @foreach($this->courses as $course)
                                <option value="{{ $course->id }}">{{ $course->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Filter by Section') }}</label>
                        <select wire:model.live="reportSectionId" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                            <option value="">{{ __('All Classroom Sections') }}</option>
                            @foreach($this->sections as $sec)
                                <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <!-- Subject Popularity Distribution Cards & Bars -->
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="chart-bar" class="w-4 h-4 text-purple-600" />
                            {{ __('Subject Popularity & Student Preference Distribution') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Breakdown of total candidates opting for each UPSC CSE optional discipline.') }}
                        </p>
                    </div>
                    <span class="text-xs font-mono font-bold text-purple-600 bg-purple-50 dark:bg-purple-950/40 px-3 py-1 rounded-full border border-purple-200 dark:border-purple-800/60">
                        {{ array_sum(array_column($this->subjectDistribution, 'count')) }} {{ __('Total Enrolled Choices') }}
                    </span>
                </div>

                <div class="space-y-3 pt-2">
                    @forelse($this->subjectDistribution as $item)
                        <div class="p-3 rounded-xl border border-zinc-100 dark:border-zinc-800/60 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-bold text-zinc-800 dark:text-zinc-200 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-purple-600"></span>
                                    <span>{{ $item['subject'] }}</span>
                                </span>
                                <div class="flex items-center gap-3 font-mono">
                                    <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ $item['count'] }} {{ __('students') }}</span>
                                    <span class="px-2 py-0.5 rounded bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 font-bold text-[11px]">{{ $item['percentage'] }}%</span>
                                </div>
                            </div>
                            <div class="w-full h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-purple-600 to-indigo-600 rounded-full transition-all duration-500" style="width: {{ $item['percentage'] }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8 text-zinc-400 italic">
                            {{ __('No optional subject selections recorded for this cohort.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 4: MASTER SUBJECTS CATALOG (CRUD) -->
    @if($activeTab === 'catalog')
        <div class="space-y-4">
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs overflow-hidden">
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between gap-4">
                    <div>
                        <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ __('Official Optional Subjects Catalog') }}</h3>
                        <p class="text-xs text-zinc-500">{{ __('Create, edit, and toggle active status of all UPSC optional disciplines.') }}</p>
                    </div>

                    @can('optional_subjects.manage')
                        <x-ui.button wire:click="openCreateSubjectModal" variant="primary" size="sm" class="gap-1 text-xs font-semibold">
                            <x-icon name="plus" class="w-3.5 h-3.5" />
                            {{ __('Add Subject') }}
                        </x-ui.button>
                    @endcan
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 uppercase tracking-wider font-semibold">
                            <tr>
                                <th class="px-4 py-3">{{ __('Subject Code') }}</th>
                                <th class="px-4 py-3">{{ __('Subject Name') }}</th>
                                <th class="px-4 py-3">{{ __('Discipline Category') }}</th>
                                <th class="px-4 py-3">{{ __('Student Enrolled Count') }}</th>
                                <th class="px-4 py-3">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($this->allSubjectsList as $sub)
                                <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                                    <td class="px-4 py-3 font-mono font-bold text-purple-600 dark:text-purple-400">
                                        {{ $sub->code ?: '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-zinc-900 dark:text-zinc-100">{{ $sub->name }}</div>
                                        @if($sub->description)
                                            <div class="text-[11px] text-zinc-500 line-clamp-1">{{ $sub->description }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                        {{ $sub->category }}
                                    </td>
                                    <td class="px-4 py-3 font-mono font-bold text-zinc-800 dark:text-zinc-200">
                                        {{ $sub->students_count }} {{ __('students') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @can('optional_subjects.manage')
                                            <button
                                                type="button"
                                                wire:click="toggleSubjectStatus({{ $sub->id }})"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold transition {{ $sub->is_active ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-zinc-200 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400' }}"
                                            >
                                                <span class="w-1.5 h-1.5 rounded-full {{ $sub->is_active ? 'bg-emerald-500' : 'bg-zinc-400' }}"></span>
                                                <span>{{ $sub->is_active ? __('Active') : __('Inactive') }}</span>
                                            </button>
                                        @else
                                            <span class="text-xs font-semibold {{ $sub->is_active ? 'text-emerald-600' : 'text-zinc-400' }}">
                                                {{ $sub->is_active ? __('Active') : __('Inactive') }}
                                            </span>
                                        @endcan
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @can('optional_subjects.manage')
                                            <div class="inline-flex items-center gap-1.5">
                                                <button
                                                    type="button"
                                                    wire:click="openEditSubjectModal({{ $sub->id }})"
                                                    class="p-1 rounded-md text-zinc-500 hover:text-purple-600 transition"
                                                    title="{{ __('Edit Subject') }}"
                                                >
                                                    <x-icon name="pencil-square" class="w-4 h-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="confirmDeleteSubject({{ $sub->id }})"
                                                    class="p-1 rounded-md text-zinc-500 hover:text-rose-600 transition"
                                                    title="{{ __('Delete Subject') }}"
                                                >
                                                    <x-icon name="trash" class="w-4 h-4" />
                                                </button>
                                            </div>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-zinc-400 italic">
                                        {{ __('No subjects registered in catalog.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- QUICK UPDATE MODAL DIALOG -->
    @if($showQuickUpdateModal && $quickUpdateUser)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
            x-data
            @keydown.escape.window="$wire.set('showQuickUpdateModal', false)"
            wire:click.self="$set('showQuickUpdateModal', false)"
        >
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="pencil-square" class="w-5 h-5 text-purple-600" />
                            {{ __('Quick Update Optional Subject') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ $quickUpdateUser->name }} (ID: {{ $quickUpdateUser->student_id ?: 'N/A' }})
                        </p>
                    </div>

                    <button type="button" wire:click="$set('showQuickUpdateModal', false)" class="text-zinc-400 hover:text-zinc-600 cursor-pointer">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-3">
                    <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 text-xs text-zinc-600 dark:text-zinc-300">
                        <span class="text-zinc-400 block">{{ __('Current Allocation:') }}</span>
                        <strong class="text-zinc-900 dark:text-zinc-100 text-sm font-bold">
                            {{ $quickUpdateUser->studentOtherDetails?->optional_subject ?: __('None / Unassigned') }}
                        </strong>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Select New Optional Subject') }}</label>
                        <select wire:model="quickUpdateSubject" class="h-10 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                            <option value="">{{ __('-- None / Remove Subject --') }}</option>
                            @foreach($this->activeSubjectsList as $sub)
                                <option value="{{ $sub->name }}">{{ $sub->name }} ({{ $sub->category }})</option>
                            @endforeach
                        </select>
                        @error('quickUpdateSubject')
                            <span class="text-xs text-rose-500">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <x-ui.button type="button" wire:click="$set('showQuickUpdateModal', false)" variant="outline" size="sm" class="text-xs">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="saveQuickUpdate" variant="primary" size="sm" class="gap-1.5 text-xs font-semibold" wire:loading.attr="disabled">
                        <x-icon name="check" class="w-4 h-4" />
                        <span wire:loading.remove>{{ __('Save Allocation') }}</span>
                        <span wire:loading>{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- APPROVE CHANGE REQUEST MODAL DIALOG -->
    @if($showApproveModal && $activeRequest)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
            x-data
            @keydown.escape.window="$wire.set('showApproveModal', false)"
            wire:click.self="$set('showApproveModal', false)"
        >
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="check-circle" class="w-5 h-5 text-emerald-600" />
                            {{ __('Approve Optional Subject Change') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Request #') }}{{ $activeRequest->request_no }} &bull; {{ $activeRequest->user->name }}
                        </p>
                    </div>
                    <button type="button" wire:click="$set('showApproveModal', false)" class="text-zinc-400 hover:text-zinc-600 cursor-pointer">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-3.5 rounded-xl border border-emerald-200 dark:border-emerald-800/60 bg-emerald-50/50 dark:bg-emerald-950/20 text-xs space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-zinc-500">{{ __('Current Subject:') }}</span>
                        <span class="line-through font-medium text-zinc-600 dark:text-zinc-400">{{ $activeRequest->current_subject ?: __('None') }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-zinc-500">{{ __('Requested Subject:') }}</span>
                        <span class="font-bold text-emerald-700 dark:text-emerald-300 text-sm">{{ $activeRequest->requested_subject }}</span>
                    </div>
                    <div class="pt-2 border-t border-emerald-200 dark:border-emerald-800/40">
                        <span class="text-zinc-500 block mb-0.5">{{ __('Student’s Stated Reason:') }}</span>
                        <p class="text-zinc-800 dark:text-zinc-200 italic">{{ $activeRequest->reason }}</p>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Optional Administrative Remarks / Advice') }}</label>
                    <textarea
                        wire:model="actionRemarks"
                        rows="3"
                        class="w-full rounded-md border border-input bg-card p-2.5 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="e.g. Approved. Student may collect updated optional notes from library."
                    ></textarea>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <x-ui.button type="button" wire:click="$set('showApproveModal', false)" variant="outline" size="sm" class="text-xs">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="confirmApprove" variant="primary" size="sm" class="gap-1.5 text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white" wire:loading.attr="disabled">
                        <x-icon name="check" class="w-4 h-4" />
                        <span wire:loading.remove>{{ __('Confirm Approval') }}</span>
                        <span wire:loading>{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- REJECT CHANGE REQUEST MODAL DIALOG -->
    @if($showRejectModal && $activeRequest)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
            x-data
            @keydown.escape.window="$wire.set('showRejectModal', false)"
            wire:click.self="$set('showRejectModal', false)"
        >
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="x-circle" class="w-5 h-5 text-rose-600" />
                            {{ __('Reject Optional Subject Change Request') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Request #') }}{{ $activeRequest->request_no }} &bull; {{ $activeRequest->user->name }}
                        </p>
                    </div>
                    <button type="button" wire:click="$set('showRejectModal', false)" class="text-zinc-400 hover:text-zinc-600 cursor-pointer">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        {{ __('Reason for Rejection (Required — Visible to Student)') }}
                    </label>
                    <textarea
                        wire:model="actionRemarks"
                        rows="4"
                        class="w-full rounded-md border border-input bg-card p-2.5 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="Please provide explicit reason why this optional subject change cannot be accommodated..."
                    ></textarea>
                    @error('actionRemarks')
                        <span class="text-xs text-rose-500">{{ $message }}</span>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <x-ui.button type="button" wire:click="$set('showRejectModal', false)" variant="outline" size="sm" class="text-xs">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="confirmReject" variant="primary" size="sm" class="gap-1.5 text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white" wire:loading.attr="disabled">
                        <x-icon name="x-mark" class="w-4 h-4" />
                        <span wire:loading.remove>{{ __('Confirm Rejection') }}</span>
                        <span wire:loading>{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- SUBJECT CRUD MODAL DIALOG -->
    @if($showSubjectModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
            x-data
            @keydown.escape.window="$wire.set('showSubjectModal', false)"
            wire:click.self="$set('showSubjectModal', false)"
        >
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="book-open" class="w-5 h-5 text-purple-600" />
                            {{ $editingSubjectId ? __('Edit Optional Subject') : __('Create New Optional Subject') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Configure official UPSC optional subject details.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="$set('showSubjectModal', false)" class="text-zinc-400 hover:text-zinc-600 cursor-pointer">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-3">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Subject Full Name') }}</label>
                        <x-ui.input wire:model="subjectForm.name" placeholder="e.g. Political Science and International Relations" />
                        @error('subjectForm.name') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Subject Code') }}</label>
                            <x-ui.input wire:model="subjectForm.code" placeholder="e.g. PSIR" />
                            @error('subjectForm.code') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Discipline Category') }}</label>
                            <select wire:model="subjectForm.category" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                                @foreach(\App\Models\OptionalSubject::categories() as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                            @error('subjectForm.category') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Description (Optional)') }}</label>
                        <textarea
                            wire:model="subjectForm.description"
                            rows="3"
                            class="w-full rounded-md border border-input bg-card p-2.5 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            placeholder="Enter subject overview or prerequisites..."
                        ></textarea>
                        @error('subjectForm.description') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                    </div>

                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 cursor-pointer">
                        <input type="checkbox" wire:model.live="subjectForm.is_active" class="w-4 h-4 text-purple-600 rounded border-zinc-300 focus:ring-purple-500" />
                        <div>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200 text-xs block">{{ __('Active for Selection') }}</span>
                            <span class="text-[11px] text-zinc-500">{{ __('Active subjects appear in student change requests and allocation dropdowns.') }}</span>
                        </div>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <x-ui.button type="button" wire:click="$set('showSubjectModal', false)" variant="outline" size="sm" class="text-xs">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="saveSubject" variant="primary" size="sm" class="gap-1.5 text-xs font-semibold" wire:loading.attr="disabled">
                        <x-icon name="check" class="w-4 h-4" />
                        <span wire:loading.remove>{{ $editingSubjectId ? __('Update Subject') : __('Create Subject') }}</span>
                        <span wire:loading>{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- DELETE SUBJECT CONFIRMATION MODAL -->
    @if($showDeleteSubjectModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
            x-data
            @keydown.escape.window="$wire.set('showDeleteSubjectModal', false)"
            wire:click.self="$set('showDeleteSubjectModal', false)"
        >
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-xl bg-rose-500/10 text-rose-600 shrink-0">
                        <x-icon name="exclamation-triangle" class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ __('Delete Optional Subject?') }}</h3>
                        <p class="text-xs text-zinc-500 mt-0.5">{{ __('This subject will be soft-deleted. Existing student allocations are safely preserved.') }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <x-ui.button type="button" wire:click="$set('showDeleteSubjectModal', false)" variant="outline" size="sm" class="text-xs">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="deleteSubject" variant="primary" size="sm" class="gap-1.5 text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white" wire:loading.attr="disabled">
                        <x-icon name="trash" class="w-4 h-4" />
                        <span wire:loading.remove>{{ __('Confirm Delete') }}</span>
                        <span wire:loading>{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- IMPORT SPREADSHEET MODAL DIALOG -->
    @if($showImportModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
            x-data
            @keydown.escape.window="$wire.set('showImportModal', false)"
            wire:click.self="$set('showImportModal', false)"
        >
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="arrow-up-tray" class="w-5 h-5 text-purple-600" />
                            {{ __('Bulk Import Optional Subjects') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Upload Excel or CSV file to batch allocate student optional subjects.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="$set('showImportModal', false)" class="text-zinc-400 hover:text-zinc-600 cursor-pointer">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-3">
                    <div class="p-3.5 rounded-xl border border-blue-200 dark:border-blue-900/50 bg-blue-50/50 dark:bg-blue-950/20 text-xs flex items-center justify-between">
                        <div>
                            <span class="font-bold text-blue-900 dark:text-blue-200 block">{{ __('Need standard format?') }}</span>
                            <span class="text-blue-800 dark:text-blue-300 text-[11px]">{{ __('Download sample CSV template with required columns.') }}</span>
                        </div>
                        <button type="button" wire:click="downloadTemplate" class="px-2.5 py-1 rounded-md bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs shadow-2xs transition cursor-pointer">
                            {{ __('Download CSV') }}
                        </button>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Spreadsheet File (.xlsx, .csv)') }}</label>
                        <input
                            type="file"
                            wire:model="importFile"
                            accept=".xlsx,.csv,.txt"
                            class="block w-full text-xs text-zinc-500 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-950/60 dark:file:text-purple-300"
                        />
                        @error('importFile') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <x-ui.button type="button" wire:click="$set('showImportModal', false)" variant="outline" size="sm" class="text-xs">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="import" variant="primary" size="sm" class="gap-1.5 text-xs font-semibold" wire:loading.attr="disabled">
                        <x-icon name="arrow-up-tray" class="w-4 h-4" />
                        <span wire:loading.remove>{{ __('Process Import') }}</span>
                        <span wire:loading>{{ __('Processing...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>

