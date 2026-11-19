<?php

use App\Concerns\RenamesUploads;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\Section;
use App\Services\AcademicImportService;
use App\Services\AuditLogService;
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

new #[Layout('layouts.app')] #[Title('Comprehensive Course Plans & Syllabus Maps')] class extends Component {
    use RenamesUploads, WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'type')]
    public string $planTypeFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'start_date';

    public string $sortDirection = 'desc';

    public int $perPage = 10;

    // Form Modal State
    public ?int $editingId = null;

    public array $form = [
        'title' => '',
        'batch_id' => '',
        'course_id' => '',
        'section_id' => '',
        'plan_type' => 'annual',
        'academic_year' => '',
        'start_date' => '',
        'end_date' => '',
        'total_lectures_planned' => 60,
        'topics_covered' => '',
        'description' => '',
        'version' => '1.0',
        'status' => 'published',
    ];

    public $documentFile = null;

    public ?string $existingDocumentPath = null;

    public ?string $existingDocumentUrl = null;

    public bool $removeDocument = false;

    // Detail Modal State
    public ?int $viewingId = null;

    public ?CoursePlan $viewingPlan = null;

    // Delete Modal State
    public ?int $deleteId = null;

    public ?CoursePlan $deletingPlan = null;

    // Import Modal state
    public $importFile = null;
    public array $importResult = [];

    public function openImportModal(): void
    {
        $this->authorize('classes.plans');
        $this->importFile = null;
        $this->importResult = [];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'course-plan-import-modal');
    }

    public function downloadTemplate()
    {
        $this->authorize('classes.plans');

        return AcademicImportService::generateCoursePlanTemplate();
    }

    public function processImport(): void
    {
        $this->authorize('classes.plans');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = AcademicImportService::importCoursePlans($this->importFile);
            $this->importResult = $result;

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __(':count course plans imported successfully.', ['count' => $result['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('No course plans were imported. Review errors below.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBatchFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCourseFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPlanTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFormBatchId($batchId): void
    {
        if (! empty($batchId)) {
            $batch = Batch::with('courses')->find((int) $batchId);
            if ($batch && $batch->courses->isNotEmpty()) {
                if (! $batch->courses->contains('id', (int) $this->form['course_id'])) {
                    $this->form['course_id'] = (string) $batch->courses->first()->id;
                }
            }
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

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => CoursePlan::count(),
            'published' => CoursePlan::where('status', 'published')->count(),
            'annual' => CoursePlan::where('plan_type', 'annual')->count(),
            'monthly_weekly' => CoursePlan::whereIn('plan_type', ['monthly', 'weekly'])->count(),
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
        if (! empty($this->form['batch_id'])) {
            $batch = Batch::with('courses')->find($this->form['batch_id']);
            if ($batch && $batch->courses->isNotEmpty()) {
                return $batch->courses;
            }
        }

        return Course::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function sections()
    {
        if (! empty($this->form['batch_id']) && ! empty($this->form['course_id'])) {
            return Section::where('batch_id', $this->form['batch_id'])
                ->where('course_id', $this->form['course_id'])
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
        }

        return collect();
    }

    #[Computed]
    public function coursePlans()
    {
        return CoursePlan::query()
            ->with(['batch', 'course', 'section'])
            ->withCount('classSchedules')
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('title', 'like', $term)
                        ->orWhere('plan_code', 'like', $term)
                        ->orWhere('topics_covered', 'like', $term)
                        ->orWhereHas('course', fn ($c) => $c->where('name', 'like', $term))
                        ->orWhereHas('batch', fn ($b) => $b->where('name', 'like', $term));
                });
            })
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->when($this->planTypeFilter, fn ($q) => $q->where('plan_type', $this->planTypeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->editingId = null;
        $firstBatch = $this->batches->first();
        $firstCourse = $firstBatch && $firstBatch->courses->isNotEmpty() ? $firstBatch->courses->first() : $this->courses->first();

        $this->form = [
            'title' => '',
            'batch_id' => $firstBatch ? (string) $firstBatch->id : '',
            'course_id' => $firstCourse ? (string) $firstCourse->id : '',
            'section_id' => '',
            'plan_type' => 'annual',
            'academic_year' => $firstBatch?->academic_year ?? date('Y').'-'.(date('Y') + 1),
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addMonths(6)->format('Y-m-d'),
            'total_lectures_planned' => 60,
            'topics_covered' => '',
            'description' => '',
            'version' => '1.0',
            'status' => 'published',
        ];
        $this->documentFile = null;
        $this->existingDocumentPath = null;
        $this->existingDocumentUrl = null;
        $this->removeDocument = false;
        $this->resetValidation();
        $this->dispatch('modal-open', name: 'course-plan-form-modal');
    }

    public function edit(int $id): void
    {
        $plan = CoursePlan::findOrFail($id);
        $this->editingId = $plan->id;
        $this->existingDocumentPath = $plan->document_path;
        $this->existingDocumentUrl = $plan->document_url;
        $this->removeDocument = false;
        $this->form = [
            'title' => $plan->title,
            'batch_id' => (string) $plan->batch_id,
            'course_id' => (string) $plan->course_id,
            'section_id' => $plan->section_id ? (string) $plan->section_id : '',
            'plan_type' => $plan->plan_type,
            'academic_year' => $plan->academic_year ?? '',
            'start_date' => $plan->start_date->format('Y-m-d'),
            'end_date' => $plan->end_date->format('Y-m-d'),
            'total_lectures_planned' => $plan->total_lectures_planned,
            'topics_covered' => $plan->topics_covered ?? '',
            'description' => $plan->description ?? '',
            'version' => $plan->version,
            'status' => $plan->status,
        ];
        $this->documentFile = null;
        $this->resetValidation();
        $this->dispatch('modal-open', name: 'course-plan-form-modal');
    }

    public function save(): void
    {
        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.batch_id' => 'required|exists:batches,id',
            'form.course_id' => 'required|exists:courses,id',
            'form.section_id' => 'nullable|exists:sections,id',
            'form.plan_type' => 'required|in:annual,monthly,weekly,custom',
            'form.start_date' => 'required|date',
            'form.end_date' => 'required|date|after_or_equal:form.start_date',
            'form.total_lectures_planned' => 'required|integer|min:1',
            'form.topics_covered' => 'nullable|string',
            'form.description' => 'nullable|string',
            'form.version' => 'required|string|max:20',
            'form.status' => 'required|in:draft,published,under_revision,archived',
            'documentFile' => 'nullable|file|mimes:pdf,docx,doc|max:20480',
        ]);

        try {
            DB::beginTransaction();

            $payload = [
                'title' => trim($this->form['title']),
                'batch_id' => (int) $this->form['batch_id'],
                'course_id' => (int) $this->form['course_id'],
                'section_id' => ! empty($this->form['section_id']) ? (int) $this->form['section_id'] : null,
                'plan_type' => $this->form['plan_type'],
                'academic_year' => $this->form['academic_year'] ?: null,
                'start_date' => $this->form['start_date'],
                'end_date' => $this->form['end_date'],
                'total_lectures_planned' => (int) $this->form['total_lectures_planned'],
                'topics_covered' => $this->form['topics_covered'] ?: null,
                'description' => $this->form['description'] ?: null,
                'version' => $this->form['version'],
                'status' => $this->form['status'],
            ];

            // Use RenamesUploads and handle file removal
            if ($this->documentFile) {
                if ($this->existingDocumentPath && Storage::disk('public')->exists($this->existingDocumentPath)) {
                    Storage::disk('public')->delete($this->existingDocumentPath);
                }
                $filename = $this->generateFileName('course_plan', $this->documentFile, auth()->id() ?? 1);
                $docPath = $this->documentFile->storeAs('course-plans', $filename, 'public');
                $payload['document_path'] = $docPath;
            } elseif ($this->removeDocument && $this->existingDocumentPath) {
                if (Storage::disk('public')->exists($this->existingDocumentPath)) {
                    Storage::disk('public')->delete($this->existingDocumentPath);
                }
                $payload['document_path'] = null;
            }

            if ($this->editingId) {
                $plan = CoursePlan::findOrFail($this->editingId);
                $old = $plan->toArray();
                $plan->update($payload);

                AuditLogService::log(
                    event: 'course_plan_updated',
                    description: "Updated course plan {$plan->plan_code}: {$plan->title}",
                    auditable: $plan,
                    oldValues: $old,
                    newValues: $plan->fresh()->toArray(),
                    userId: auth()->id()
                );

                Toast::dispatch($this, 'success', __('Course Plan updated successfully.'));
            } else {
                $plan = CoursePlan::create($payload);

                AuditLogService::log(
                    event: 'course_plan_created',
                    description: "Created course plan {$plan->plan_code}: {$plan->title}",
                    auditable: $plan,
                    newValues: $plan->toArray(),
                    userId: auth()->id()
                );

                Toast::dispatch($this, 'success', __('Course Plan created successfully.'));
            }

            DB::commit();
            $this->dispatch('modal-close', name: 'course-plan-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save course plan: :err', ['err' => $e->getMessage()]));
        }
    }

    public function showDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingPlan = CoursePlan::with(['batch', 'course', 'section', 'classSchedules' => fn ($q) => $q->latest()->take(10)])
            ->withCount('classSchedules')
            ->findOrFail($id);

        $this->dispatch('modal-open', name: 'course-plan-view-modal');
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->deletingPlan = CoursePlan::withCount('classSchedules')->findOrFail($id);
        $this->dispatch('modal-open', name: 'course-plan-delete-modal');
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        try {
            $plan = CoursePlan::findOrFail($this->deleteId);
            $title = $plan->title;
            $code = $plan->plan_code;

            $plan->delete();

            AuditLogService::log(
                event: 'course_plan_deleted',
                description: "Deleted course plan {$code}: {$title}",
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Course Plan :code deleted.', ['code' => $code]));
            $this->deleteId = null;
            $this->dispatch('modal-close', name: 'course-plan-delete-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete course plan: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'csv')
    {
        $plans = CoursePlan::query()
            ->with(['batch', 'course', 'section'])
            ->withCount('classSchedules')
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->when($this->planTypeFilter, fn ($q) => $q->where('plan_type', $this->planTypeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy('start_date', 'desc')
            ->get();

        $headers = ['Plan Code', 'Title', 'Batch', 'Course', 'Section', 'Type', 'Planned Lectures', 'Conducted Classes', 'Start Date', 'End Date', 'Version', 'Status'];
        $rows = [];

        foreach ($plans as $p) {
            $rows[] = [
                $p->plan_code,
                $p->title,
                $p->batch?->name ?? 'N/A',
                $p->course?->name ?? 'N/A',
                $p->section?->name ?? 'All Sections',
                ucfirst($p->plan_type),
                $p->total_lectures_planned,
                $p->class_schedules_count,
                $p->start_date->format('Y-m-d'),
                $p->end_date->format('Y-m-d'),
                $p->version,
                ucfirst($p->status),
            ];
        }

        $title = 'Academic Course Plans and Syllabus Schedules Report';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Curriculum Milestones & Lecture Targets'),
            'xlsx' => StudentImportExportService::exportExcel('course-plans-report', $headers, $rows),
            default => StudentImportExportService::exportCsv('course-plans-report', $headers, $rows),
        };
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'batchFilter', 'courseFilter', 'planTypeFilter', 'statusFilter']);
        $this->sortField = 'start_date';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="book-open" class="w-7 h-7 text-primary" />
                {{ __('Course Plans & Syllabus Schedules') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Publish annual, monthly, or weekly abstract curriculum course plans and syllabus lecture roadmaps for students.') }}
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

            @can('classes.plans')
                <x-ui.button type="button" variant="outline" size="sm" class="h-9 gap-1.5 cursor-pointer" wire:click="openImportModal" x-data x-on:click="$store.modals.open('course-plan-import-modal')">
                    <x-icon name="upload" class="h-4 w-4"/>
                    <span>{{ __('Import') }}</span>
                </x-ui.button>

                <x-ui.button wire:click="create" size="sm" class="h-9 gap-1.5 cursor-pointer">
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('New Course Plan') }}</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-blue-500/10 p-2.5 text-blue-600 dark:text-blue-400">
                    <x-icon name="book-open" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Total Course Plans') }}</p>
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
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Published Plans') }}</p>
                    <p class="text-2xl font-bold text-foreground">{{ number_format($this->stats['published']) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-violet-500/10 p-2.5 text-violet-600 dark:text-violet-400">
                    <x-icon name="calendar" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Annual Comprehensive') }}</p>
                    <p class="text-2xl font-bold text-foreground">{{ number_format($this->stats['annual']) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-amber-500/10 p-2.5 text-amber-600 dark:text-amber-400">
                    <x-icon name="calendar-days" class="h-5 w-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Monthly & Weekly') }}</p>
                    <p class="text-2xl font-bold text-foreground">{{ number_format($this->stats['monthly_weekly']) }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="relative lg:col-span-1">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search plan or title...') }}"
                    class="w-full rounded-md border border-input bg-background pl-9 pr-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
            </div>

            <div>
                <select
                    wire:model.live="batchFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Batches') }}</option>
                    @foreach ($this->batches as $batch)
                        <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select
                    wire:model.live="courseFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Courses') }}</option>
                    @foreach ($this->courses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select
                    wire:model.live="planTypeFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Plan Types') }}</option>
                    <option value="annual">{{ __('Annual') }}</option>
                    <option value="monthly">{{ __('Monthly') }}</option>
                    <option value="weekly">{{ __('Weekly') }}</option>
                    <option value="custom">{{ __('Custom') }}</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <select
                    wire:model.live="statusFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="published">{{ __('Published') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="under_revision">{{ __('Under Revision') }}</option>
                    <option value="archived">{{ __('Archived') }}</option>
                </select>

                @if ($search || $batchFilter || $courseFilter || $planTypeFilter || $statusFilter)
                    <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="h-9 gap-1 text-xs shrink-0">
                        <x-icon name="rotate-ccw" class="h-3.5 w-3.5"/>
                    </x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <!-- Course Plans Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted/50 border-b border-border text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Course Plan') }}</th>
                        <th class="px-4 py-3">{{ __('Batch & Course') }}</th>
                        <th class="px-4 py-3">{{ __('Type & Target') }}</th>
                        <th class="px-4 py-3">{{ __('Timeline') }}</th>
                        <th class="px-4 py-3">{{ __('Actual Classes') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->coursePlans as $plan)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-foreground">{{ $plan->title }}</div>
                                <div class="font-mono text-xs text-muted-foreground flex items-center gap-2 mt-0.5">
                                    <span>{{ $plan->plan_code }}</span>
                                    <span>&bull;</span>
                                    <span>v{{ $plan->version }}</span>
                                    @if ($plan->document_path)
                                        <a href="{{ $plan->document_url }}" target="_blank" class="inline-flex items-center gap-0.5 text-primary hover:underline">
                                            <x-icon name="paperclip" class="h-3 w-3"/>
                                            <span>{{ __('Syllabus') }}</span>
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-medium text-foreground">{{ $plan->course?->name }}</div>
                                <div class="text-muted-foreground">{{ $plan->batch?->name }} {{ $plan->section ? '('.$plan->section->name.')' : '(All Sections)' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="inline-flex items-center rounded-md bg-secondary px-2 py-0.5 text-xs font-medium">
                                    {{ ucfirst($plan->plan_type) }}
                                </span>
                                <div class="text-muted-foreground mt-0.5">{{ $plan->total_lectures_planned }} {{ __('Lectures') }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                <div>{{ $plan->start_date->format('d M Y') }}</div>
                                <div>{{ __('to') }} {{ $plan->end_date->format('d M Y') }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="flex items-center gap-1 font-semibold text-foreground">
                                    <x-icon name="check-circle" class="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
                                    <span>{{ $plan->conducted_lectures_count }} / {{ $plan->total_lectures_planned }} {{ __('Conducted') }}</span>
                                </div>
                                <div class="text-[11px] text-muted-foreground flex items-center gap-1.5 mt-0.5">
                                    <span>{{ $plan->scheduled_lectures_count }} {{ __('upcoming') }}</span>
                                    @if ($plan->cancelled_lectures_count > 0)
                                        <span class="text-rose-500 font-medium" title="{{ __('Canceled classes do not count towards syllabus completion') }}">
                                            &bull; {{ $plan->cancelled_lectures_count }} {{ __('cancelled') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="w-28 bg-secondary rounded-full h-1.5 mt-1 overflow-hidden" title="{{ $plan->progress_percentage }}% completed">
                                    <div class="bg-primary h-1.5 rounded-full" style="width: {{ min(100, $plan->progress_percentage) }}%"></div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($plan->status === 'published')
                                    <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                        {{ __('Published') }}
                                    </span>
                                @elseif ($plan->status === 'under_revision')
                                    <span class="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                                        {{ __('Under Revision') }}
                                    </span>
                                @elseif ($plan->status === 'draft')
                                    <span class="inline-flex items-center rounded-full bg-zinc-500/10 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        {{ __('Draft') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                        {{ ucfirst($plan->status) }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="showDetails({{ $plan->id }})"
                                        class="p-1.5 text-muted-foreground hover:text-foreground rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                        title="{{ __('View Plan Details') }}"
                                    >
                                        <x-icon name="eye" class="h-4 w-4"/>
                                    </button>

                                    @can('classes.plans')
                                        <button
                                            type="button"
                                            wire:click="edit({{ $plan->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-primary rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Edit Course Plan') }}"
                                        >
                                            <x-icon name="pencil" class="h-4 w-4"/>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $plan->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-destructive rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Delete Course Plan') }}"
                                        >
                                            <x-icon name="trash-2" class="h-4 w-4"/>
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="book-open" class="h-8 w-8 text-muted-foreground/50"/>
                                    <p class="font-medium">{{ __('No course plans found.') }}</p>
                                    <p class="text-xs text-muted-foreground">{{ __('Create an annual or monthly course plan to share comprehensive roadmaps with students.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->coursePlans->hasPages())
            <div class="border-t border-border p-4">
                {{ $this->coursePlans->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Modal -->
    <x-ui.modal name="course-plan-form-modal" maxWidth="max-w-2xl" :title="$editingId ? __('Edit Course Plan') : __('Create Comprehensive Course Plan')" :description="__('Configure the course plan blueprint, lecture targets, and syllabus curriculum roadmap.')">
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Plan Title') }} <span class="text-destructive">*</span></label>
                    <input
                        type="text"
                        wire:model="form.title"
                        required
                        placeholder="{{ __('2026 Prelims-cum-Mains GS Foundation Annual Course Plan') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.title') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Batch') }} <span class="text-destructive">*</span></label>
                    <select
                        wire:model.live="form.batch_id"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="">{{ __('Select Batch') }}</option>
                        @foreach ($this->batches as $batch)
                            <option value="{{ $batch->id }}">{{ $batch->name }} ({{ $batch->academic_year }})</option>
                        @endforeach
                    </select>
                    @error('form.batch_id') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Course') }} <span class="text-destructive">*</span></label>
                    <select
                        wire:model="form.course_id"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="">{{ __('Select Course') }}</option>
                        @foreach ($this->courses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                    @error('form.course_id') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Section (Optional)') }}</label>
                    <select
                        wire:model="form.section_id"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="">{{ __('All Sections / Batch-wide') }}</option>
                        @foreach ($this->sections as $sec)
                            <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Plan Type') }} <span class="text-destructive">*</span></label>
                    <select
                        wire:model="form.plan_type"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="annual">{{ __('Annual (Recommended)') }}</option>
                        <option value="monthly">{{ __('Monthly Plan') }}</option>
                        <option value="weekly">{{ __('Weekly Plan') }}</option>
                        <option value="custom">{{ __('Custom Range') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Date') }} <span class="text-destructive">*</span></label>
                    <input
                        type="date"
                        wire:model="form.start_date"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Date') }} <span class="text-destructive">*</span></label>
                    <input
                        type="date"
                        wire:model="form.end_date"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Total Lectures Planned') }} <span class="text-destructive">*</span></label>
                    <input
                        type="number"
                        wire:model="form.total_lectures_planned"
                        min="1"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Status & Version') }}</label>
                    <div class="flex gap-2">
                        <select
                            wire:model="form.status"
                            class="w-2/3 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        >
                            <option value="published">{{ __('Published to Students') }}</option>
                            <option value="draft">{{ __('Draft') }}</option>
                            <option value="under_revision">{{ __('Under Revision') }}</option>
                            <option value="archived">{{ __('Archived') }}</option>
                        </select>
                        <input
                            type="text"
                            wire:model="form.version"
                            placeholder="1.0"
                            class="w-1/3 rounded-md border border-input bg-background px-2 py-2 text-sm text-center font-mono text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Topics & Units Covered') }}</label>
                    <textarea
                        wire:model="form.topics_covered"
                        rows="2"
                        placeholder="{{ __('Unit 1: Ancient & Medieval India; Unit 2: Modern Indian History & National Movement; Unit 3: Post-Independence...') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    ></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Detailed Syllabus & Pedagogical Notes') }}</label>
                    <textarea
                        wire:model="form.description"
                        rows="3"
                        placeholder="{{ __('Comprehensive month-wise distribution, suggested readings, reference materials, answer writing schedule...') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    ></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Attach Official Syllabus Document (PDF/Doc, max 20MB)') }}</label>
                    <input
                        type="file"
                        wire:model="documentFile"
                        accept=".pdf,.docx,.doc"
                        class="w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                    />
                    <div wire:loading wire:target="documentFile" class="text-xs text-primary mt-1">{{ __('Uploading file...') }}</div>

                    @if ($existingDocumentUrl && ! $removeDocument && ! $documentFile)
                        <div class="flex items-center justify-between p-2.5 mt-2 rounded-lg bg-muted/40 border border-border text-xs">
                            <div class="flex items-center gap-2 text-foreground">
                                <x-icon name="file-text" class="w-4 h-4 text-primary shrink-0" />
                                <span>{{ __('Currently Attached Softcopy Document') }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <a href="{{ $existingDocumentUrl }}" target="_blank" class="text-xs text-primary font-semibold hover:underline">
                                    {{ __('View File') }}
                                </a>
                                <button
                                    type="button"
                                    wire:click="$set('removeDocument', true); $set('documentFile', null)"
                                    class="inline-flex items-center gap-1 text-xs text-destructive hover:underline cursor-pointer"
                                >
                                    <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                    <span>{{ __('Remove uploaded file') }}</span>
                                </button>
                            </div>
                        </div>
                    @elseif ($removeDocument)
                        <div class="p-2 mt-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 text-xs flex items-center justify-between">
                            <span>{{ __('File will be removed upon updating the plan.') }}</span>
                            <button
                                type="button"
                                wire:click="$set('removeDocument', false)"
                                class="text-xs underline hover:text-foreground"
                            >
                                {{ __('Undo') }}
                            </button>
                        </div>
                    @endif
                    @error('documentFile') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'course-plan-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4"/>
                    <span>{{ $editingId ? __('Update Plan') : __('Publish Plan') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Detail View Modal -->
    <x-ui.modal name="course-plan-view-modal" maxWidth="max-w-2xl" :title="$viewingPlan?->title ?? __('Course Plan Overview')" :description="__('Comprehensive course plan roadmap and operational schedule link.')">
        @if ($viewingPlan)
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 rounded-lg bg-secondary/50">
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ $viewingPlan->title }}</h3>
                        <p class="text-xs text-muted-foreground">{{ $viewingPlan->course?->name }} &bull; {{ $viewingPlan->batch?->name }}</p>
                        <p class="text-xs font-mono text-primary mt-0.5">{{ $viewingPlan->plan_code }} (v{{ $viewingPlan->version }})</p>
                    </div>
                    @if ($viewingPlan->document_path)
                        <a href="{{ $viewingPlan->document_url }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90">
                            <x-icon name="download" class="h-3.5 w-3.5"/>
                            <span>{{ __('Download Syllabus') }}</span>
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Plan Type') }}</span>
                        <span class="font-bold text-foreground uppercase">{{ $viewingPlan->plan_type }}</span>
                    </div>
                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Planned Lectures') }}</span>
                        <span class="font-bold text-foreground">{{ $viewingPlan->total_lectures_planned }}</span>
                    </div>
                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Classes Conducted') }}</span>
                        <span class="font-bold text-primary">{{ $viewingPlan->class_schedules_count }}</span>
                    </div>
                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Status') }}</span>
                        <span class="font-bold text-foreground capitalize">{{ $viewingPlan->status }}</span>
                    </div>
                </div>

                @if ($viewingPlan->topics_covered)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Syllabus Modules Covered') }}</span>
                        <p class="text-foreground leading-relaxed">{{ $viewingPlan->topics_covered }}</p>
                    </div>
                @endif

                @if ($viewingPlan->description)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Pedagogical Plan & Strategy') }}</span>
                        <p class="text-foreground leading-relaxed whitespace-pre-line">{{ $viewingPlan->description }}</p>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal name="course-plan-delete-modal" maxWidth="max-w-md" :title="__('Delete Course Plan')" :description="__('Are you sure you want to delete this course plan?')">
        @if ($deletingPlan)
            <div class="space-y-3">
                <p class="text-sm text-foreground">
                    {{ __('You are about to delete:') }} <strong>{{ $deletingPlan->title }}</strong> ({{ $deletingPlan->plan_code }}).
                </p>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('course-plan-delete-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="destructive" size="sm" wire:click="delete" class="gap-1.5">
                        <x-icon name="trash-2" class="h-4 w-4"/>
                        <span>{{ __('Delete Plan') }}</span>
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Bulk Import Modal -->
    <x-ui.modal
        name="course-plan-import-modal"
        max-width="max-w-lg"
        :title="__('Bulk Import Course Plans')"
        :description="__('Upload an Excel (.xlsx, .xls) or CSV spreadsheet with curriculum course plans and syllabus milestones.')"
    >
        <div class="space-y-4">
            <div class="p-3 rounded-xl bg-primary/10 border border-primary/20 flex items-start gap-2.5 text-xs text-foreground">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0 mt-0.5" />
                <div class="space-y-1">
                    <p class="font-semibold">{{ __('Spreadsheet Guidelines:') }}</p>
                    <p class="text-muted-foreground">{{ __('Required columns: Plan Title, Batch Code, Course Code, Start Date, End Date. Optional: Plan Code, Section Code, Plan Type (annual/monthly/weekly/custom), Academic Year, Total Lectures Planned, Topics Covered, Status (published/draft), Description.') }}</p>
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
                            {{ __(':count Course Plans Imported', ['count' => $importResult['imported']]) }}
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
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('course-plan-import-modal')">
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
