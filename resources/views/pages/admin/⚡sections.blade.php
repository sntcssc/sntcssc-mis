<?php

use App\Helpers\CourseData;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchCourse;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Services\AcademicImportService;
use App\Services\AuditLogService;
use App\Services\StudentImportExportService;
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

new #[Layout('layouts.app')] #[Title('Sections Management')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

    public int $perPage = 10;

    // Form Modal state
    public ?int $editingId = null;

    public array $form = [
        'batch_id' => '',
        'course_id' => '',
        'name' => '',
        'code' => '',
        'capacity' => 45,
        'room_number' => '',
        'timing' => '',
        'status' => 'active',
    ];

    // Detail Modal state
    public ?int $viewingId = null;

    public ?Section $viewingSection = null;

    // Delete Modal state
    public ?int $deleteId = null;

    public int $deleteEnrolledCount = 0;

    // Import Modal state
    public $importFile = null;
    public array $importResult = [];

    public function openImportModal(): void
    {
        $this->authorize('sections.manage');
        $this->importFile = null;
        $this->importResult = [];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'section-import-modal');
    }

    public function downloadTemplate()
    {
        $this->authorize('sections.manage');

        return AcademicImportService::generateSectionTemplate();
    }

    public function processImport(): void
    {
        $this->authorize('sections.manage');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = AcademicImportService::importSections($this->importFile);
            $this->importResult = $result;

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __(':count sections imported successfully.', ['count' => $result['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('No sections were imported. Review errors below.'));
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
        $totalCapacity = (int) Section::sum('capacity');
        $totalEnrolled = Enrollment::whereNotNull('section_id')->where('status', 'active')->count();

        return [
            'total' => Section::count(),
            'active' => Section::where('status', 'active')->count(),
            'total_capacity' => $totalCapacity,
            'total_enrolled' => $totalEnrolled,
            'available_seats' => max(0, $totalCapacity - $totalEnrolled),
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
    public function formCourses()
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
        return Section::query()
            ->with(['batch', 'course'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('room_number', 'like', $term)
                        ->orWhere('timing', 'like', $term)
                        ->orWhereHas('course', fn ($c) => $c->where('name', 'like', $term)->orWhere('code', 'like', $term))
                        ->orWhereHas('batch', fn ($b) => $b->where('name', 'like', $term));
                });
            })
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->editingId = null;
        $firstBatch = $this->batches->first();
        $firstCourse = $this->courses->first();

        $this->form = [
            'batch_id' => $firstBatch ? (string) $firstBatch->id : '',
            'course_id' => $firstCourse ? (string) $firstCourse->id : '',
            'name' => 'Section A',
            'code' => '1',
            'capacity' => 45,
            'room_number' => '',
            'timing' => '',
            'status' => 'active',
        ];

        $this->dispatch('modal-open', name: 'section-form-modal');
    }

    public function edit(int $id): void
    {
        $section = Section::findOrFail($id);
        $this->editingId = $section->id;

        $this->form = [
            'batch_id' => (string) $section->batch_id,
            'course_id' => (string) $section->course_id,
            'name' => $section->name,
            'code' => $section->code ?? '',
            'capacity' => $section->capacity,
            'room_number' => $section->room_number ?? '',
            'timing' => $section->timing ?? '',
            'status' => $section->status ?? 'active',
        ];

        $this->dispatch('modal-open', name: 'section-form-modal');
    }

    public function save(): void
    {
        $this->validate([
            'form.batch_id' => 'required|exists:batches,id',
            'form.course_id' => 'required|exists:courses,id',
            'form.name' => 'required|string|max:100',
            'form.code' => 'required|string|max:20',
            'form.capacity' => 'required|integer|min:1|max:500',
            'form.room_number' => 'nullable|string|max:50',
            'form.timing' => 'nullable|string|max:100',
            'form.status' => 'required|in:active,inactive',
        ]);

        try {
            DB::transaction(function () {
                $batchId = (int) $this->form['batch_id'];
                $courseId = ! empty($this->form['course_id']) ? (int) $this->form['course_id'] : null;

                $batch = Batch::find($batchId);
                if ($batch && $courseId && ! $batch->courses()->where('courses.id', $courseId)->exists()) {
                    $c = Course::find($courseId);
                    $batch->courses()->attach($courseId, [
                        'capacity' => $c?->capacity,
                        'fee' => $c?->fee,
                        'status' => 'active',
                    ]);
                }

                $batchCourse = $courseId ? BatchCourse::where('batch_id', $batchId)
                    ->where('course_id', $courseId)
                    ->first() : null;

                $data = [
                    'batch_id' => $batchId,
                    'course_id' => $courseId,
                    'batch_course_id' => $batchCourse?->id,
                    'name' => trim($this->form['name']),
                    'code' => trim($this->form['code']),
                    'capacity' => (int) $this->form['capacity'],
                    'room_number' => trim($this->form['room_number'] ?? '') ?: null,
                    'timing' => trim($this->form['timing'] ?? '') ?: null,
                    'status' => $this->form['status'],
                ];

                if ($courseId) {
                    $course = Course::find($courseId);
                    if ($course && ! $course->has_sections) {
                        $course->update(['has_sections' => true]);
                    }
                }

                if ($this->editingId) {
                    $section = Section::findOrFail($this->editingId);
                    $oldValues = $section->toArray();
                    $section->update($data);

                    AuditLogService::log(
                        event: 'section_updated',
                        description: 'Updated Section: '.$section->name,
                        oldValues: $oldValues,
                        newValues: $section->fresh()->toArray(),
                        userId: auth()->id()
                    );

                    Toast::dispatch($this, 'success', __('Section ":name" updated successfully.', ['name' => $section->name]));
                } else {
                    $section = Section::create($data);

                    AuditLogService::log(
                        event: 'section_created',
                        description: 'Created Section: '.$section->name,
                        newValues: $section->toArray(),
                        userId: auth()->id()
                    );

                    Toast::dispatch($this, 'success', __('Section ":name" created successfully.', ['name' => $section->name]));
                }
            });

            $this->dispatch('modal-close', name: 'section-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save section: :err', ['err' => $e->getMessage()]));
        }
    }

    public function toggleStatus(int $id): void
    {
        try {
            $section = Section::findOrFail($id);
            $newStatus = $section->status === 'active' ? 'inactive' : 'active';
            $section->update(['status' => $newStatus]);

            AuditLogService::log(
                event: 'section_status_toggled',
                description: 'Section '.$section->name.' status set to '.$newStatus,
                oldValues: ['status' => $section->getOriginal('status')],
                newValues: ['status' => $newStatus],
                userId: auth()->id()
            );

            Toast::dispatch($this, 'info', __('Section status updated to :status.', ['status' => ucfirst($newStatus)]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update status.'));
        }
    }

    public function showDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingSection = Section::with([
            'batch',
            'course',
            'enrollments' => fn ($q) => $q->where('status', 'active')->with('student')->latest()->take(20),
        ])->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])->findOrFail($id);

        $this->dispatch('modal-open', name: 'section-view-modal');
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->deleteEnrolledCount = Enrollment::where('section_id', $id)->where('status', 'active')->count();
        $this->dispatch('modal-open', name: 'section-delete-modal');
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        try {
            $section = Section::findOrFail($this->deleteId);
            $name = $section->name;
            $oldData = $section->toArray();

            $section->delete();

            AuditLogService::log(
                event: 'section_deleted',
                description: 'Deleted Section: '.$name,
                oldValues: $oldData,
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Section ":name" deleted successfully.', ['name' => $name]));
            $this->deleteId = null;
            $this->dispatch('modal-close', name: 'section-delete-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete section: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'csv')
    {
        $sections = Section::query()
            ->with(['batch', 'course'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy('name')
            ->get();

        $headers = ['Section Name', 'Identifier Code', 'Course Code', 'Course Name', 'Batch', 'Academic Year', 'Room Number', 'Timing', 'Capacity', 'Enrolled', 'Available Seats', 'Status'];
        $rows = [];

        foreach ($sections as $s) {
            $available = max(0, $s->capacity - $s->enrollments_count);
            $rows[] = [
                $s->name,
                $s->code ?? 'None',
                $s->course?->code ?? 'N/A',
                $s->course?->name ?? 'N/A',
                $s->batch?->name ?? 'N/A',
                $s->batch?->academic_year ?? 'N/A',
                $s->room_number ?? 'TBD',
                $s->timing ?? 'TBD',
                $s->capacity,
                $s->enrollments_count,
                $available,
                ucfirst($s->status),
            ];
        }

        $title = 'Academic Course Sections and Classroom Allocation Report';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Course Sections & Capacity Rosters'),
            'xlsx' => StudentImportExportService::exportExcel('sections-master-report', $headers, $rows),
            default => StudentImportExportService::exportCsv('sections-master-report', $headers, $rows),
        };
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'batchFilter', 'courseFilter', 'statusFilter']);
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
                <x-icon name="columns" class="w-7 h-7 text-primary" />
                {{ __('Sections Management') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Configure course sections, classroom rooms, schedules, and capacity quotas per batch.') }}
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

            @canany(['sections.manage', 'sections.import'])
            <x-ui.button type="button" variant="outline" size="sm" class="h-9 gap-1.5 cursor-pointer" wire:click="openImportModal" x-data x-on:click="$store.modals.open('section-import-modal')">
                <x-icon name="upload" class="h-4 w-4"/>
                <span>{{ __('Import') }}</span>
            </x-ui.button>
            @endcanany

            <x-ui.button wire:click="create" size="sm" class="h-9 gap-1.5 cursor-pointer">
                <x-icon name="plus" class="h-4 w-4"/>
                <span>{{ __('Add Section') }}</span>
            </x-ui.button>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    @php $stats = $this->stats; @endphp
    <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-5 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Sections') }}</span>
                <span class="p-1.5 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="columns" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ number_format($stats['total']) }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Across all batches') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Active Sections') }}</span>
                <span class="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-500">
                    <x-icon name="check-circle" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ number_format($stats['active']) }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Available for enrollment') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Capacity') }}</span>
                <span class="p-1.5 rounded-lg bg-blue-500/10 text-blue-500">
                    <x-icon name="users" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ number_format($stats['total_capacity']) }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Total allocated seats') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Enrolled Students') }}</span>
                <span class="p-1.5 rounded-lg bg-purple-500/10 text-purple-500">
                    <x-icon name="graduation-cap" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-2">{{ number_format($stats['total_enrolled']) }}</div>
            <div class="text-xs text-muted-foreground mt-1">
                @if($stats['total_capacity'] > 0)
                    {{ round(($stats['total_enrolled'] / $stats['total_capacity']) * 100) }}% {{ __('utilization') }}
                @else
                    0% {{ __('utilization') }}
                @endif
            </div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs col-span-2 sm:col-span-2 md:col-span-1">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Available Seats') }}</span>
                <span class="p-1.5 rounded-lg bg-amber-500/10 text-amber-500">
                    <x-icon name="sparkles" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ number_format($stats['available_seats']) }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Remaining capacity') }}</div>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-4 bg-card border border-border rounded-xl shadow-xs space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-3">
            <div class="md:col-span-2 relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search section, code, room, timing...') }}"
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

            <select wire:model.live="statusFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Statuses') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="inactive">{{ __('Inactive') }}</option>
            </select>

            <div class="flex items-center justify-end">
                @if($search || $batchFilter || $courseFilter || $statusFilter)
                    <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="h-9 gap-1.5 text-xs">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                        {{ __('Reset') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <!-- Sections Data Table -->
    <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-muted/50 border-b border-border text-xs text-muted-foreground uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3 cursor-pointer select-none hover:text-foreground transition-colors" wire:click="sortBy('name')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('Section Name & Code') }}</span>
                                @if($sortField === 'name')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3.5 w-3.5 text-primary"/>
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Course') }}</th>
                        <th class="px-4 py-3">{{ __('Batch') }}</th>
                        <th class="px-4 py-3">{{ __('Room & Schedule') }}</th>
                        <th class="px-4 py-3">{{ __('Capacity & Occupancy') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($this->sections as $section)
                        @php
                            $enrolled = $section->enrollments_count ?? 0;
                            $capacity = max(1, $section->capacity);
                            $pct = min(100, round(($enrolled / $capacity) * 100));
                        @endphp
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-foreground text-sm">{{ $section->name }}</span>
                                    @if($section->code)
                                        <x-ui.badge color="primary" class="font-mono text-[10px] font-bold">
                                            {{ __('ID Code: ') }}{{ $section->code }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <div class="text-[10px] text-muted-foreground mt-0.5">
                                    {{ __('Student ID Digit: ') }}<span class="font-mono font-semibold text-foreground">{{ $section->code }}</span>
                                </div>
                            </td>

                            <td class="px-4 py-3.5">
                                @if($section->course)
                                    <div class="flex flex-col gap-0.5">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-bold text-foreground text-xs">{{ $section->course->code }}</span>
                                            <x-ui.badge variant="secondary" size="xs" class="text-[10px]">{{ $section->course->category }}</x-ui.badge>
                                        </div>
                                        <span class="text-[11px] text-muted-foreground truncate max-w-[200px]">{{ $section->course->name }}</span>
                                        <div class="text-[10px] text-muted-foreground">
                                            {{ $section->course->isFree() ? __('Free of Cost') : CourseData::getFeeDescription($section->course) }}
                                            @if($section->course->capacity)
                                                · <span class="font-semibold text-foreground">{{ __('Intake: :cap', ['cap' => $section->course->capacity]) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <span class="text-xs text-muted-foreground italic">{{ __('Unassigned') }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                @if($section->batch)
                                    <div class="flex flex-col">
                                        <span class="font-medium text-foreground text-xs">{{ $section->batch->name }}</span>
                                        <span class="text-[11px] text-muted-foreground">{{ $section->batch->academic_year ?? '—' }}</span>
                                    </div>
                                @else
                                    <span class="text-xs text-muted-foreground italic">{{ __('Unassigned') }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex flex-col text-xs text-muted-foreground">
                                    <div class="flex items-center gap-1 text-foreground">
                                        <x-icon name="map-pin" class="h-3 w-3 text-muted-foreground"/>
                                        <span>{{ $section->room_number ?: __('TBD Room') }}</span>
                                    </div>
                                    @if($section->timing)
                                        <div class="flex items-center gap-1 text-[11px] mt-0.5">
                                            <x-icon name="clock" class="h-3 w-3 text-muted-foreground"/>
                                            <span>{{ $section->timing }}</span>
                                        </div>
                                    @endif
                                </div>
                            </td>

                            <td class="px-4 py-3.5 min-w-[160px]">
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-mono font-medium text-foreground">{{ $enrolled }} / {{ $section->capacity }}</span>
                                        <span class="text-[11px] text-muted-foreground">{{ $pct }}%</span>
                                    </div>
                                    <div class="h-1.5 w-full bg-secondary rounded-full overflow-hidden">
                                        <div
                                            class="h-full rounded-full transition-all {{ $pct >= 100 ? 'bg-rose-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-primary') }}"
                                            style="width: {{ $pct }}%"
                                        ></div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3.5 text-center">
                                <button
                                    type="button"
                                    wire:click="toggleStatus({{ $section->id }})"
                                    class="cursor-pointer transition-opacity hover:opacity-80"
                                    title="{{ __('Click to toggle active status') }}"
                                >
                                    @if($section->status === 'active')
                                        <x-ui.badge color="success">
                                            <x-icon name="check" class="h-3 w-3 mr-0.5"/>
                                            {{ __('Active') }}
                                        </x-ui.badge>
                                    @else
                                        <x-ui.badge color="secondary">
                                            {{ __('Inactive') }}
                                        </x-ui.badge>
                                    @endif
                                </button>
                            </td>

                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <x-ui.button
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        wire:click="showDetails({{ $section->id }})"
                                        title="{{ __('View section details & enrolled students') }}"
                                        class="h-7 w-7 p-0"
                                    >
                                        <x-icon name="eye" class="h-3.5 w-3.5 text-muted-foreground hover:text-foreground"/>
                                    </x-ui.button>

                                    <x-ui.button
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        wire:click="edit({{ $section->id }})"
                                        title="{{ __('Edit Section') }}"
                                        class="h-7 w-7 p-0"
                                    >
                                        <x-icon name="edit" class="h-3.5 w-3.5 text-muted-foreground hover:text-primary"/>
                                    </x-ui.button>

                                    <x-ui.button
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        wire:click="confirmDelete({{ $section->id }})"
                                        title="{{ __('Delete Section') }}"
                                        class="h-7 w-7 p-0 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                                    >
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center space-y-2">
                                    <x-icon name="columns" class="h-8 w-8 text-muted-foreground/40"/>
                                    <p class="text-sm font-medium">{{ __('No sections found.') }}</p>
                                    <p class="text-xs text-muted-foreground">{{ __('Create a new section to organize students into classrooms and study timings.') }}</p>
                                    <x-ui.button wire:click="create" size="sm" class="mt-2 h-8 text-xs cursor-pointer">
                                        <x-icon name="plus" class="h-3.5 w-3.5 mr-1"/>
                                        {{ __('Create Section') }}
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->sections->hasPages())
            <div class="p-4 border-t border-border">
                {{ $this->sections->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Section Modal -->
    <x-ui.modal name="section-form-modal" maxWidth="max-w-md">
        <form wire:submit="save" class="space-y-4">
            <div class="border-b border-border pb-3">
                <h3 class="text-base font-semibold text-foreground">
                    {{ $editingId ? __('Edit Section') : __('Add New Section') }}
                </h3>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ __('Define classroom group, batch association, schedule timing, and seat capacity.') }}
                </p>
            </div>

            <div class="space-y-3.5">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Academic Batch') }} <span class="text-destructive">*</span>
                    </label>
                    <select
                        wire:model.live="form.batch_id"
                        class="mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                    >
                        <option value="">-- {{ __('Select Batch') }} --</option>
                        @foreach($this->batches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year ?? 'General' }})</option>
                        @endforeach
                    </select>
                    @error('form.batch_id') <p class="text-destructive text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Academic Course') }} <span class="text-destructive">*</span>
                    </label>
                    <select
                        wire:model="form.course_id"
                        class="mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                    >
                        <option value="">-- {{ __('Select Course') }} --</option>
                        @foreach($this->formCourses as $c)
                            <option value="{{ $c->id }}">{{ $c->code }} - {{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('form.course_id') <p class="text-destructive text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <x-ui.input
                            wire:model="form.name"
                            :label="__('Section Name') . ' *'"
                            placeholder="e.g. Section A"
                            required
                        />
                    </div>
                    <div>
                        <x-ui.input
                            wire:model="form.code"
                            :label="__('ID Code') . ' *'"
                            placeholder="e.g. 1"
                            maxlength="20"
                            class="font-mono text-center"
                            hint="{{ __('Used in Student ID') }}"
                            required
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input
                        wire:model="form.capacity"
                        :label="__('Seat Capacity') . ' *'"
                        type="number"
                        min="1"
                        max="500"
                        placeholder="45"
                        required
                    />

                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ __('Status') }} <span class="text-destructive">*</span>
                        </label>
                        <select
                            wire:model="form.status"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            <option value="active">{{ __('Active') }}</option>
                            <option value="inactive">{{ __('Inactive') }}</option>
                        </select>
                        @error('form.status') <p class="text-destructive text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <x-ui.input
                    wire:model="form.room_number"
                    :label="__('Room Number / Classroom Hall')"
                    placeholder="e.g. Room 204, Seminar Hall 2"
                    icon="map-pin"
                />

                <x-ui.input
                    wire:model="form.timing"
                    :label="__('Schedule Timing')"
                    placeholder="e.g. Mon-Fri, 10:00 AM - 01:00 PM"
                    icon="clock"
                />
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    wire:click="$dispatch('modal-close', { name: 'section-form-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm">
                    <x-icon name="check" class="h-3.5 w-3.5 mr-1"/>
                    {{ $editingId ? __('Update Section') : __('Create Section') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Section Details Modal -->
    <x-ui.modal name="section-view-modal" maxWidth="max-w-2xl">
        @if($viewingSection)
            <div class="space-y-5">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-foreground">{{ $viewingSection->name }}</h3>
                            @if($viewingSection->code)
                                <x-ui.badge color="primary" class="font-mono text-xs font-bold">{{ __('ID Code: ') }}{{ $viewingSection->code }}</x-ui.badge>
                            @endif
                            <x-ui.badge color="{{ $viewingSection->status === 'active' ? 'success' : 'secondary' }}">
                                {{ ucfirst($viewingSection->status) }}
                            </x-ui.badge>
                        </div>
                        <div class="flex items-center gap-2 mt-1 text-xs">
                            <span class="font-semibold text-foreground">{{ $viewingSection->course?->code }} - {{ $viewingSection->course?->name }}</span>
                            @if($viewingSection->course?->category)
                                <x-ui.badge variant="secondary" size="xs">{{ $viewingSection->course->category }}</x-ui.badge>
                            @endif
                            <span class="text-muted-foreground">| {{ $viewingSection->batch?->name }} ({{ $viewingSection->batch?->academic_year }})</span>
                        </div>
                        <div class="text-[11px] text-muted-foreground mt-0.5">
                            {{ $viewingSection->course ? ($viewingSection->course->isFree() ? __('Free Course') : CourseData::getFeeDescription($viewingSection->course)) : '' }}
                            @if($viewingSection->course?->capacity)
                                · {{ __('Course Capacity: :cap students', ['cap' => $viewingSection->course->capacity]) }}
                            @endif
                            · <span class="font-mono text-primary font-semibold">{{ __('ID Formula: [:year][:code][0001]', ['year' => $viewingSection->batch?->academic_year ?? date('Y'), 'code' => $viewingSection->code ?: '0']) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Section Meta Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3.5 rounded-lg bg-secondary/20 border border-border">
                    <div>
                        <span class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Classroom Room') }}</span>
                        <div class="font-medium text-xs text-foreground mt-0.5">{{ $viewingSection->room_number ?: __('Not Assigned') }}</div>
                    </div>
                    <div>
                        <span class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Schedule') }}</span>
                        <div class="font-medium text-xs text-foreground mt-0.5">{{ $viewingSection->timing ?: __('TBD') }}</div>
                    </div>
                    <div>
                        <span class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Capacity') }}</span>
                        <div class="font-semibold text-xs text-foreground mt-0.5">{{ $viewingSection->capacity }} {{ __('Seats') }}</div>
                    </div>
                    <div>
                        <span class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Enrolled / Vacant') }}</span>
                        <div class="font-semibold text-xs text-primary mt-0.5">
                            {{ $viewingSection->enrollments_count }} / {{ max(0, $viewingSection->capacity - $viewingSection->enrollments_count) }}
                        </div>
                    </div>
                </div>

                <!-- Enrolled Students Roster -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ __('Enrolled Students in this Section') }} ({{ $viewingSection->enrollments_count }})
                        </h4>
                        @if($viewingSection->enrollments_count > 0)
                            <a
                                href="{{ route('admin.enrollments.index', ['section' => $viewingSection->id]) }}"
                                class="text-xs text-primary hover:underline font-medium"
                            >
                                {{ __('View in Enrollments') }} →
                            </a>
                        @endif
                    </div>

                    <div class="max-h-60 overflow-y-auto rounded-lg border border-border divide-y divide-border/60">
                        @forelse($viewingSection->enrollments as $enrollment)
                            <div class="p-2.5 flex items-center justify-between gap-3 text-xs hover:bg-muted/20">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="h-7 w-7 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[11px] shrink-0">
                                        {{ substr($enrollment->student?->name ?? 'S', 0, 1) }}
                                    </div>
                                    <div class="min-w-0 truncate">
                                        <div class="font-medium text-foreground truncate">{{ $enrollment->student?->name }}</div>
                                        <div class="font-mono text-[10px] text-muted-foreground truncate">
                                            ID: {{ $enrollment->student?->student_id ?: 'Pending' }}
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <x-ui.badge color="{{ $enrollment->fee_status === 'paid' ? 'success' : 'warning' }}" class="text-[10px]">
                                        {{ ucfirst($enrollment->fee_status) }}
                                    </x-ui.badge>
                                    <div class="text-[10px] text-muted-foreground mt-0.5">
                                        {{ $enrollment->created_at?->format('d M Y') }}
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="p-4 text-center text-xs text-muted-foreground italic">
                                {{ __('No students enrolled in this section yet.') }}
                            </p>
                        @endforelse
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        wire:click="$dispatch('modal-close', { name: 'section-view-modal' })"
                    >
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal name="section-delete-modal" maxWidth="max-w-sm">
        <div class="space-y-4 text-center sm:text-left">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-500/10 text-rose-500">
                    <x-icon name="alert-triangle" class="h-5 w-5"/>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Delete Course Section?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">{{ __('This will remove the section from classroom assignment lists.') }}</p>
                </div>
            </div>

            @if($deleteEnrolledCount > 0)
                <div class="p-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-xs text-amber-800 dark:text-amber-200">
                    <p class="font-semibold">{{ __('Warning: Active Enrollments!') }}</p>
                    <p class="mt-0.5">
                        {{ __('This section currently has :count active enrolled student(s). Deleting will unset the section on those enrollments.', ['count' => $deleteEnrolledCount]) }}
                    </p>
                </div>
            @endif

            <p class="text-xs text-muted-foreground">
                {{ __('Are you sure you want to proceed? This action can be audited in the system logs.') }}
            </p>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-data
                    x-on:click="$store.modals.close('section-delete-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="destructive"
                    size="sm"
                    wire:click="delete"
                >
                    <x-icon name="trash" class="h-3.5 w-3.5 mr-1"/>
                    {{ __('Confirm Delete') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Bulk Import Modal -->
    <x-ui.modal
        name="section-import-modal"
        max-width="max-w-lg"
        :title="__('Bulk Import Sections')"
        :description="__('Upload an Excel (.xlsx, .xls) or CSV spreadsheet with classroom section allocations.')"
    >
        <div class="space-y-4">
            <div class="p-3 rounded-xl bg-primary/10 border border-primary/20 flex items-start gap-2.5 text-xs text-foreground">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0 mt-0.5" />
                <div class="space-y-1">
                    <p class="font-semibold">{{ __('Spreadsheet Guidelines:') }}</p>
                    <p class="text-muted-foreground">{{ __('Required columns: Batch Code, Course Code, Section Name. Optional: Section Code, Capacity, Room Number, Shift/Timing, Status (active/inactive).') }}</p>
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
                            {{ __(':count Sections Imported', ['count' => $importResult['imported']]) }}
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
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('section-import-modal')">
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
