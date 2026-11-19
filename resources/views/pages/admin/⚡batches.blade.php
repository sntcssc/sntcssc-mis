<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Services\AcademicImportService;
use App\Services\StudentImportExportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Batch Management')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'year')]
    public string $yearFilter = '';

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';
    public int $perPage = 10;

    // Form Modal state
    public ?int $editingId = null;
    public array $form = [
        'name' => '',
        'code' => '',
        'academic_year' => '',
        'start_date' => '',
        'end_date' => '',
        'status' => 'upcoming',
        'max_students' => '',
        'description' => '',
        'courses' => [], // Array of course IDs offered
    ];

    // Detail Modal state
    public ?int $viewingId = null;
    public ?Batch $viewingBatch = null;

    // Sections Modal state
    public ?int $managingSectionsBatchId = null;
    public array $sectionForm = [
        'course_id' => '',
        'name' => '',
        'code' => '',
        'capacity' => 45,
        'room_number' => '',
        'timing' => '',
        'status' => 'active',
    ];
    public ?int $editingSectionId = null;

    // Delete Modal state
    public ?int $deleteId = null;

    // Import Modal state
    public $importFile = null;
    public array $importResult = [];

    public function openImportModal(): void
    {
        $this->authorize('batches.manage');
        $this->importFile = null;
        $this->importResult = [];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'batch-import-modal');
    }

    public function downloadTemplate()
    {
        $this->authorize('batches.manage');

        return AcademicImportService::generateBatchTemplate();
    }

    public function processImport(): void
    {
        $this->authorize('batches.manage');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = AcademicImportService::importBatches($this->importFile);
            $this->importResult = $result;

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __(':count batches imported successfully.', ['count' => $result['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('No batches were imported. Review errors below.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedYearFilter(): void
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
            'total' => Batch::count(),
            'active' => Batch::where('status', 'active')->count(),
            'upcoming' => Batch::where('status', 'upcoming')->count(),
            'completed' => Batch::where('status', 'completed')->count(),
            'total_enrollments' => Enrollment::where('status', 'active')->count(),
        ];
    }

    #[Computed]
    public function academicYears(): array
    {
        return Batch::select('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->filter()
            ->values()
            ->all();
    }

    #[Computed]
    public function availableCourses()
    {
        return Course::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::query()
            ->with(['courses', 'sections'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('academic_year', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->yearFilter, fn ($q) => $q->where('academic_year', $this->yearFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->editingId = null;
        $currentYear = date('Y');
        $this->form = [
            'name' => ($currentYear + 1).' Batch',
            'code' => 'BATCH-'.($currentYear + 1),
            'academic_year' => (string) ($currentYear + 1),
            'start_date' => ($currentYear + 1).'-01-01',
            'end_date' => ($currentYear + 1).'-12-31',
            'status' => 'upcoming',
            'max_students' => '200',
            'description' => '',
            'courses' => Course::where('status', 'active')->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ];
        $this->dispatch('modal-open', name: 'batch-form-modal');
    }

    public function edit(int $id): void
    {
        $batch = Batch::with('courses')->findOrFail($id);
        $this->editingId = $batch->id;
        $this->form = [
            'name' => $batch->name,
            'code' => $batch->code,
            'academic_year' => $batch->academic_year,
            'start_date' => $batch->start_date ? $batch->start_date->format('Y-m-d') : '',
            'end_date' => $batch->end_date ? $batch->end_date->format('Y-m-d') : '',
            'status' => $batch->status,
            'max_students' => (string) ($batch->max_students ?? ''),
            'description' => $batch->description ?? '',
            'courses' => $batch->courses->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ];
        $this->dispatch('modal-open', name: 'batch-form-modal');
    }

    public function viewDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingBatch = Batch::with(['courses', 'sections', 'enrollments.user', 'enrollments.course', 'enrollments.section'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
            ->findOrFail($id);
        $this->dispatch('modal-open', name: 'batch-view-modal');
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => 'required|string|max:150',
            'form.code' => 'required|string|max:50|unique:batches,code,'.($this->editingId ?? 'NULL'),
            'form.academic_year' => 'required|string|max:20',
            'form.start_date' => 'nullable|date',
            'form.end_date' => 'nullable|date|after_or_equal:form.start_date',
            'form.status' => 'required|in:upcoming,active,completed,archived',
            'form.max_students' => 'nullable|integer|min:1',
            'form.description' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $data = [
                'name' => $this->form['name'],
                'code' => strtoupper($this->form['code']),
                'academic_year' => $this->form['academic_year'],
                'start_date' => $this->form['start_date'] ?: null,
                'end_date' => $this->form['end_date'] ?: null,
                'status' => $this->form['status'],
                'max_students' => $this->form['max_students'] ? (int) $this->form['max_students'] : null,
                'description' => $this->form['description'] ?: null,
            ];

            if ($this->editingId) {
                $batch = Batch::findOrFail($this->editingId);
                $batch->update($data);

                // Sync courses preserving capacities and fee schedules
                $courseIds = array_filter(array_map('intval', $this->form['courses']));
                $syncData = [];
                foreach ($courseIds as $cid) {
                    $c = Course::find($cid);
                    $syncData[$cid] = [
                        'capacity' => $c?->capacity,
                        'fee' => $c?->fee,
                        'status' => 'active',
                    ];
                }
                $batch->courses()->sync($syncData);

                Toast::dispatch($this, 'success', __('Batch ":name" updated successfully.', ['name' => $batch->name]));
            } else {
                $batch = Batch::create($data);

                // Attach courses
                $courseIds = array_filter(array_map('intval', $this->form['courses']));
                if (! empty($courseIds)) {
                    $syncData = [];
                    foreach ($courseIds as $cid) {
                        $c = Course::find($cid);
                        $syncData[$cid] = [
                            'capacity' => $c?->capacity,
                            'fee' => $c?->fee,
                            'status' => 'active',
                        ];
                    }
                    $batch->courses()->sync($syncData);
                }

                Toast::dispatch($this, 'success', __('Batch ":name" created successfully.', ['name' => $batch->name]));
            }

            DB::commit();
            $this->dispatch('modal-close', name: 'batch-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save batch: :err', ['err' => $e->getMessage()]));
        }
    }

    public function manageSections(int $batchId): void
    {
        $this->managingSectionsBatchId = $batchId;
        $batch = Batch::with(['courses', 'sections.course'])->findOrFail($batchId);
        $this->editingSectionId = null;
        $firstCourse = $batch->courses->first();
        $this->sectionForm = [
            'course_id' => $firstCourse ? (string) $firstCourse->id : '',
            'name' => 'Section A',
            'code' => '1',
            'capacity' => 45,
            'room_number' => '',
            'timing' => '',
            'status' => 'active',
        ];
        $this->dispatch('modal-open', name: 'batch-sections-modal');
    }

    public function saveSection(): void
    {
        $this->validate([
            'sectionForm.course_id' => 'required|exists:courses,id',
            'sectionForm.name' => 'required|string|max:100',
            'sectionForm.code' => 'required|string|max:10',
            'sectionForm.capacity' => 'required|integer|min:1',
            'sectionForm.status' => 'required|in:active,full,inactive',
        ]);

        try {
            if ($this->editingSectionId) {
                $section = Section::findOrFail($this->editingSectionId);
                $section->update([
                    'course_id' => (int) $this->sectionForm['course_id'],
                    'name' => $this->sectionForm['name'],
                    'code' => $this->sectionForm['code'],
                    'capacity' => (int) $this->sectionForm['capacity'],
                    'room_number' => $this->sectionForm['room_number'] ?: null,
                    'timing' => $this->sectionForm['timing'] ?: null,
                    'status' => $this->sectionForm['status'],
                ]);
                Toast::dispatch($this, 'success', __('Section updated successfully.'));
            } else {
                Section::create([
                    'batch_id' => $this->managingSectionsBatchId,
                    'course_id' => (int) $this->sectionForm['course_id'],
                    'name' => $this->sectionForm['name'],
                    'code' => $this->sectionForm['code'],
                    'capacity' => (int) $this->sectionForm['capacity'],
                    'room_number' => $this->sectionForm['room_number'] ?: null,
                    'timing' => $this->sectionForm['timing'] ?: null,
                    'status' => $this->sectionForm['status'],
                ]);
                Toast::dispatch($this, 'success', __('Section created successfully.'));
            }

            $this->editingSectionId = null;
            $this->sectionForm['name'] = '';
            $this->sectionForm['code'] = '';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save section: :err', ['err' => $e->getMessage()]));
        }
    }

    public function editSection(int $sectionId): void
    {
        $section = Section::findOrFail($sectionId);
        $this->editingSectionId = $section->id;
        $this->sectionForm = [
            'course_id' => (string) $section->course_id,
            'name' => $section->name,
            'code' => $section->code,
            'capacity' => $section->capacity,
            'room_number' => $section->room_number ?? '',
            'timing' => $section->timing ?? '',
            'status' => $section->status,
        ];
    }

    public function deleteSection(int $sectionId): void
    {
        $section = Section::findOrFail($sectionId);
        if ($section->enrollments()->where('status', 'active')->exists()) {
            Toast::dispatch($this, 'error', __('Cannot delete section with active student enrollments.'));
            return;
        }

        $section->delete();
        Toast::dispatch($this, 'success', __('Section removed successfully.'));
    }

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->dispatch('modal-open', name: 'batch-delete-modal');
    }

    public function deleteSelected(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $batch = Batch::findOrFail($this->deleteId);

        // Check if there are active student enrollments
        $enrolledCount = $batch->enrollments()->where('status', 'active')->count();
        if ($enrolledCount > 0) {
            Toast::dispatch($this, 'error', __('Cannot delete batch with :count active student enrollments.', ['count' => $enrolledCount]));
            $this->dispatch('modal-close', name: 'batch-delete-modal');
            return;
        }

        $batch->delete();
        Toast::dispatch($this, 'success', __('Batch ":name" deleted successfully.', ['name' => $batch->name]));
        $this->deleteId = null;
        $this->dispatch('modal-close', name: 'batch-delete-modal');
    }

    public function export(string $format = 'csv')
    {
        $batches = Batch::with(['courses', 'sections'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
            ->get();

        $headers = ['Batch Code', 'Batch Name', 'Academic Year', 'Start Date', 'End Date', 'Courses Offered', 'Sections Count', 'Active Enrollments', 'Capacity', 'Status'];
        $rows = [];

        foreach ($batches as $b) {
            $rows[] = [
                $b->code,
                $b->name,
                $b->academic_year,
                $b->start_date ? $b->start_date->format('Y-m-d') : 'N/A',
                $b->end_date ? $b->end_date->format('Y-m-d') : 'N/A',
                $b->courses->pluck('code')->implode(', ') ?: 'None',
                $b->sections->count(),
                $b->enrollments_count,
                $b->max_students ?? 'Uncapped',
                ucfirst($b->status),
            ];
        }

        $title = 'UPSC Batches Master Report';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Academic Sessions & Batch Master List'),
            'xlsx' => StudentImportExportService::exportExcel('batches-master-report', $headers, $rows),
            default => StudentImportExportService::exportCsv('batches-master-report', $headers, $rows),
        };
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'yearFilter']);
        $this->sortField = 'created_at';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }
}; ?>

<div class="space-y-4 sm:space-y-6 max-w-7xl">
    {{-- Header Section --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                    <x-icon name="layers" class="h-6 w-6 text-primary"/>
                    {{ __('Batch Management') }}
                </h1>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-secondary text-secondary-foreground">
                    {{ $this->stats['total'] }} {{ __('batches') }}
                </span>
            </div>
            <p class="text-xs text-muted-foreground mt-1">
                {{ __('Manage UPSC academic batches, annual intake calendars, start & end dates, and associated course programmes.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Export Dropdown --}}
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground ml-0.5"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Report') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            @canany(['batches.manage', 'batches.import'])
            <x-ui.button type="button" variant="outline" size="sm" class="h-9 gap-1.5" wire:click="openImportModal" x-data x-on:click="$store.modals.open('batch-import-modal')">
                <x-icon name="upload" class="h-3.5 w-3.5"/>
                {{ __('Import') }}
            </x-ui.button>
            @endcanany

            @can('batches.manage')
            <x-ui.button size="sm" class="h-9 gap-1.5" wire:click="create">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('Create Batch') }}
            </x-ui.button>
            @endcan
        </div>
    </div>

    {{-- Stats Overview Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Total Batches') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="layers" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-foreground">{{ $this->stats['total'] }}</p>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('All academic years') }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Active Batches') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500">
                    <x-icon name="check-circle" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-emerald-600 dark:text-emerald-400">{{ $this->stats['active'] }}</p>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Ongoing sessions') }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-sky-600 dark:text-sky-400">{{ __('Upcoming') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-sky-500/10 text-sky-500">
                    <x-icon name="clock" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-sky-600 dark:text-sky-400">{{ $this->stats['upcoming'] }}</p>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Open for enrollment') }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Completed') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-secondary text-muted-foreground">
                    <x-icon name="bookmark" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-foreground">{{ $this->stats['completed'] }}</p>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Alumni batches') }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-primary">{{ __('Enrolled Total') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="users" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2 text-primary">{{ $this->stats['total_enrollments'] }}</p>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Active course seats') }}</p>
        </div>
    </div>

    {{-- Filters & Search Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 space-y-3 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            {{-- Search Box with Clear Button --}}
            <div class="relative sm:col-span-2">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by batch code, name, year...') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select
                wire:model.live="statusFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All Statuses') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="upcoming">{{ __('Upcoming') }}</option>
                <option value="completed">{{ __('Completed') }}</option>
                <option value="archived">{{ __('Archived') }}</option>
            </select>

            <select
                wire:model.live="yearFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All Academic Years') }}</option>
                @foreach($this->academicYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </select>
        </div>

        @if($search || $statusFilter || $yearFilter)
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

    {{-- Batches Data Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead class="bg-secondary/40 border-b border-border text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('code')">
                            <div class="flex items-center gap-1">
                                {{ __('Batch Code & Name') }}
                                @if($sortField === 'code')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3 w-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('academic_year')">
                            <div class="flex items-center gap-1">
                                {{ __('Academic Year') }}
                                @if($sortField === 'academic_year')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3 w-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('start_date')">
                            <div class="flex items-center gap-1">
                                {{ __('Duration') }}
                                @if($sortField === 'start_date')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3 w-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Programmes / Courses') }}</th>
                        <th class="px-4 py-3">{{ __('Sections') }}</th>
                        <th class="px-4 py-3">{{ __('Enrolled') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-foreground">
                    @forelse($this->batches as $batch)
                        <tr class="hover:bg-secondary/20 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-foreground flex items-center gap-2">
                                    <span>{{ $batch->name }}</span>
                                    <span class="text-[11px] px-1.5 py-0.5 rounded font-mono font-medium bg-secondary text-secondary-foreground">
                                        {{ $batch->code }}
                                    </span>
                                </div>
                                @if($batch->description)
                                    <p class="text-xs text-muted-foreground truncate max-w-xs mt-0.5">{{ $batch->description }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-foreground">{{ $batch->academic_year }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($batch->start_date && $batch->end_date)
                                    <div class="text-foreground">{{ $batch->start_date->format('d M Y') }}</div>
                                    <div class="text-muted-foreground text-[10px]">to {{ $batch->end_date->format('d M Y') }}</div>
                                @elseif($batch->start_date)
                                    <div>Starts: {{ $batch->start_date->format('d M Y') }}</div>
                                @else
                                    <span class="text-muted-foreground italic">{{ __('Dates Not Set') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1 max-w-xs">
                                    @forelse($batch->courses as $course)
                                        <x-ui.badge color="sky" class="text-[10px]">
                                            {{ $course->code }}
                                        </x-ui.badge>
                                    @empty
                                        <span class="text-xs text-muted-foreground italic">{{ __('No courses assigned') }}</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <button type="button" wire:click="manageSections({{ $batch->id }})" class="inline-flex items-center gap-1 text-xs text-primary hover:underline cursor-pointer font-medium">
                                    <x-icon name="layers" class="h-3 w-3"/>
                                    {{ $batch->sections->count() }} {{ __('Sections') }}
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1 font-medium">
                                    <span class="text-foreground">{{ $batch->enrollments_count }}</span>
                                    @if($batch->max_students)
                                        <span class="text-muted-foreground text-[11px]">/ {{ $batch->max_students }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $statusColor = match($batch->status) {
                                        'active' => 'emerald',
                                        'upcoming' => 'sky',
                                        'completed' => 'secondary',
                                        'archived' => 'amber',
                                        default => 'secondary',
                                    };
                                @endphp
                                <x-ui.badge :color="$statusColor" class="text-[10px]">
                                    {{ ucfirst($batch->status) }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" wire:click="viewDetails({{ $batch->id }})" title="{{ __('View Batch Dossier') }}" class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary/50 cursor-pointer transition-colors">
                                        <x-icon name="eye" class="h-4 w-4"/>
                                    </button>

                                    @can('batches.manage')
                                    <button type="button" wire:click="edit({{ $batch->id }})" title="{{ __('Edit Batch') }}" class="p-1 rounded-md text-sky-600 hover:text-sky-700 hover:bg-sky-500/10 cursor-pointer transition-colors">
                                        <x-icon name="edit" class="h-4 w-4"/>
                                    </button>
                                    @endcan

                                    @can('batches.delete')
                                    <button type="button" wire:click="selectForDelete({{ $batch->id }})" title="{{ __('Delete Batch') }}" class="p-1 rounded-md text-rose-600 hover:text-rose-700 hover:bg-rose-500/10 cursor-pointer transition-colors">
                                        <x-icon name="trash" class="h-4 w-4"/>
                                    </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="layers" class="h-8 w-8 text-muted-foreground/50"/>
                                    <span class="text-sm font-medium">{{ __('No batches found matching criteria') }}</span>
                                    <span class="text-xs text-muted-foreground">{{ __('Try adjusting your search or filters.') }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-3 border-t border-border">
            {{ $this->batches->links() }}
        </div>
    </div>

    {{-- Create / Edit Batch Modal --}}
    <x-ui.modal name="batch-form-modal" max-width="max-w-2xl" :title="$editingId ? __('Edit Batch: :name', ['name' => $form['name']]) : __('Create New Academic Batch')" :description="__('Define academic year, timeline, capacity, and course programmes included under this batch.')">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-ui.input
                    wire:model="form.name"
                    :label="__('Batch Name *')"
                    placeholder="e.g. 2026 Batch"
                    required
                />

                <x-ui.input
                    wire:model="form.code"
                    :label="__('Batch Code *')"
                    placeholder="e.g. BATCH-2026"
                    required
                />

                <x-ui.input
                    wire:model="form.academic_year"
                    :label="__('Academic Year *')"
                    placeholder="e.g. 2026"
                    required
                />

                <x-ui.select wire:model="form.status" :label="__('Status *')">
                    <option value="upcoming">{{ __('Upcoming') }}</option>
                    <option value="active">{{ __('Active (Running)') }}</option>
                    <option value="completed">{{ __('Completed') }}</option>
                    <option value="archived">{{ __('Archived') }}</option>
                </x-ui.select>

                <x-ui.input
                    wire:model="form.start_date"
                    type="date"
                    :label="__('Batch Start Date')"
                />

                <x-ui.input
                    wire:model="form.end_date"
                    type="date"
                    :label="__('Batch End Date')"
                />

                <x-ui.input
                    wire:model="form.max_students"
                    type="number"
                    :label="__('Max Intake / Capacity')"
                    placeholder="e.g. 200"
                />
            </div>

            <x-ui.textarea
                wire:model="form.description"
                :label="__('Description / Instructions')"
                placeholder="Brief overview of programmes, schedule, or admission criteria..."
                rows="2"
            />

            {{-- Courses Multi-Select --}}
            <div>
                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Programmes / Courses Offered Under this Batch') }}</label>
                <p class="text-xs text-muted-foreground mb-2">{{ __('Select all UPSC courses conducted under this annual batch.') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto p-2 border border-border rounded-lg bg-card">
                    @foreach($this->availableCourses as $course)
                        <label class="flex items-center gap-2 p-1.5 hover:bg-secondary/40 rounded cursor-pointer text-xs">
                            <input
                                type="checkbox"
                                value="{{ $course->id }}"
                                wire:model="form.courses"
                                class="rounded border-input text-primary focus:ring-primary/50"
                            />
                            <div>
                                <span class="font-semibold text-foreground">{{ $course->name }}</span>
                                <span class="text-muted-foreground">({{ $course->code }})</span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('batch-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-icon name="save" class="h-3.5 w-3.5 mr-1"/>
                    {{ $editingId ? __('Update Batch') : __('Create Batch') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Batch View Dossier Modal --}}
    <x-ui.modal name="batch-view-modal" max-width="max-w-3xl" :title="__('Batch Dossier & Analytics')" :description="__('Full session profile, course attachments, and enrollment statistics.')">
        @if($viewingBatch)
            <div class="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-bold text-foreground">{{ $viewingBatch->name }}</h2>
                            <x-ui.badge color="sky">{{ $viewingBatch->code }}</x-ui.badge>
                        </div>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Academic Year: :year · Status: :status', ['year' => $viewingBatch->academic_year, 'status' => ucfirst($viewingBatch->status)]) }}
                        </p>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold text-primary">{{ $viewingBatch->enrollments_count }}</div>
                        <div class="text-[10px] text-muted-foreground">{{ __('Enrolled Candidates') }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 bg-secondary/20 rounded-lg text-xs">
                    <div>
                        <span class="text-muted-foreground">{{ __('Start Date:') }}</span>
                        <div class="font-semibold mt-0.5">{{ $viewingBatch->start_date ? $viewingBatch->start_date->format('d M Y') : '—' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('End Date:') }}</span>
                        <div class="font-semibold mt-0.5">{{ $viewingBatch->end_date ? $viewingBatch->end_date->format('d M Y') : '—' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Max Capacity:') }}</span>
                        <div class="font-semibold mt-0.5">{{ $viewingBatch->max_students ?? 'Uncapped' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Total Sections:') }}</span>
                        <div class="font-semibold mt-0.5">{{ $viewingBatch->sections->count() }}</div>
                    </div>
                </div>

                @if($viewingBatch->description)
                    <div class="text-xs text-foreground bg-secondary/15 p-3 rounded-lg border border-border">
                        <span class="font-semibold text-foreground block mb-1">{{ __('Description & Notes:') }}</span>
                        {{ $viewingBatch->description }}
                    </div>
                @endif

                {{-- Courses Offered Tab --}}
                <div>
                    <h3 class="text-xs font-bold uppercase text-muted-foreground mb-2 flex items-center gap-1.5">
                        <x-icon name="book-open" class="h-3.5 w-3.5 text-primary" />
                        {{ __('Programmes Conducted (:count)', ['count' => $viewingBatch->courses->count()]) }}
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @forelse($viewingBatch->courses as $c)
                            <div class="p-2.5 border border-border rounded-lg bg-card flex justify-between items-center text-xs">
                                <div>
                                    <div class="font-semibold text-foreground">{{ $c->name }}</div>
                                    <div class="text-muted-foreground text-[11px]">
                                        {{ $c->category }} · {{ $c->duration }} ·
                                        <span class="font-medium text-foreground">
                                            {{ $c->pivot->capacity ? __('Cap: :cap', ['cap' => $c->pivot->capacity]) : ($c->capacity ? __('Cap: :cap', ['cap' => $c->capacity]) : __('Unlimited intake')) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-mono font-bold text-emerald-600">₹{{ number_format($c->fee, 2) }}</div>
                                    @if($c->has_sections)
                                        <x-ui.badge color="sky" class="text-[9px]">{{ __('Has Sections') }}</x-ui.badge>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-xs text-muted-foreground italic col-span-2">{{ __('No courses attached to this batch.') }}</div>
                        @endforelse
                    </div>
                </div>

                {{-- Recent Active Enrollments --}}
                <div>
                    <h3 class="text-xs font-bold uppercase text-muted-foreground mb-2 flex items-center gap-1.5">
                        <x-icon name="users" class="h-3.5 w-3.5 text-primary" />
                        {{ __('Active Student Enrollments (Sample :count)', ['count' => min(5, $viewingBatch->enrollments->count())]) }}
                    </h3>
                    <div class="border border-border rounded-lg overflow-hidden text-xs">
                        <table class="w-full text-left">
                            <thead class="bg-secondary/40 border-b border-border">
                                <tr>
                                    <th class="p-2">{{ __('Student ID') }}</th>
                                    <th class="p-2">{{ __('Student Name') }}</th>
                                    <th class="p-2">{{ __('Course') }}</th>
                                    <th class="p-2">{{ __('Section') }}</th>
                                    <th class="p-2">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($viewingBatch->enrollments->take(5) as $enr)
                                    <tr>
                                        <td class="p-2 font-mono font-medium">{{ $enr->student_id ?: ($enr->user?->student_id ?? 'N/A') }}</td>
                                        <td class="p-2 font-semibold">{{ $enr->user?->name }}</td>
                                        <td class="p-2">{{ $enr->course?->code }}</td>
                                        <td class="p-2">{{ $enr->section?->name ?? '—' }}</td>
                                        <td class="p-2">
                                            <x-ui.badge color="emerald" class="text-[10px]">{{ ucfirst($enr->status) }}</x-ui.badge>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="p-3 text-center text-muted-foreground">{{ __('No enrollments yet in this batch.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex justify-end pt-2 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('batch-view-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- Sections Management Modal --}}
    <x-ui.modal name="batch-sections-modal" max-width="max-w-2xl" :title="__('Manage Sections for Batch')" :description="__('Configure optional classroom sections, identifier digits (used for student IDs), and schedules.')">
        <div class="space-y-4">
            {{-- Add/Edit Section Form --}}
            <div class="p-3 bg-secondary/15 rounded-lg border border-border space-y-3">
                <div class="font-semibold text-xs text-foreground">
                    {{ $editingSectionId ? __('Edit Section') : __('Add New Section to this Batch') }}
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <x-ui.select wire:model="sectionForm.course_id" :label="__('Course *')">
                        @foreach($this->availableCourses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input
                        wire:model="sectionForm.name"
                        :label="__('Section Name *')"
                        placeholder="e.g. Section A"
                        required
                    />

                    <x-ui.input
                        wire:model="sectionForm.code"
                        :label="__('ID Code (e.g. 1 for A, 2 for B) *')"
                        placeholder="e.g. 1"
                        required
                    />

                    <x-ui.input
                        wire:model="sectionForm.capacity"
                        type="number"
                        :label="__('Capacity *')"
                        required
                    />

                    <x-ui.input
                        wire:model="sectionForm.timing"
                        :label="__('Timing / Schedule')"
                        placeholder="Mon–Fri · 08:00–11:00"
                    />

                    <x-ui.input
                        wire:model="sectionForm.room_number"
                        :label="__('Room / Classroom')"
                        placeholder="Hall 1"
                    />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    @if($editingSectionId)
                        <x-ui.button size="sm" variant="outline" wire:click="$set('editingSectionId', null)">
                            {{ __('Cancel Edit') }}
                        </x-ui.button>
                    @endif
                    <x-ui.button size="sm" wire:click="saveSection">
                        <x-icon name="plus" class="h-3.5 w-3.5 mr-1"/>
                        {{ $editingSectionId ? __('Save Section') : __('Add Section') }}
                    </x-ui.button>
                </div>
            </div>

            {{-- Existing Sections List --}}
            @php
                $managingBatch = $managingSectionsBatchId ? Batch::with('sections.course')->find($managingSectionsBatchId) : null;
            @endphp
            <div>
                <h4 class="text-xs font-bold uppercase text-muted-foreground mb-2">{{ __('Configured Sections') }}</h4>
                <div class="divide-y divide-border border border-border rounded-lg overflow-hidden">
                    @if($managingBatch && $managingBatch->sections->isNotEmpty())
                        @foreach($managingBatch->sections as $sec)
                            <div class="p-3 bg-card flex items-center justify-between text-xs">
                                <div>
                                    <div class="font-bold text-foreground flex items-center gap-2">
                                        <span>{{ $sec->name }}</span>
                                        <span class="px-1.5 py-0.5 rounded bg-primary/10 text-primary font-mono">
                                            Code: {{ $sec->code }}
                                        </span>
                                        <x-ui.badge color="secondary" class="text-[10px]">{{ $sec->course?->code }}</x-ui.badge>
                                    </div>
                                    <div class="text-muted-foreground mt-0.5">
                                        Capacity: {{ $sec->enrolled_count }}/{{ $sec->capacity }} · {{ $sec->timing ?? 'No timing set' }} · {{ $sec->room_number ?? 'No room' }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="editSection({{ $sec->id }})" class="p-1 text-sky-600 hover:text-sky-700 cursor-pointer">
                                        <x-icon name="edit" class="h-3.5 w-3.5" />
                                    </button>
                                    <button type="button" wire:click="deleteSection({{ $sec->id }})" class="p-1 text-rose-600 hover:text-rose-700 cursor-pointer">
                                        <x-icon name="trash" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    @else
                        <div class="p-4 text-center text-xs text-muted-foreground">
                            {{ __('No sections configured for this batch yet. Sections are optional.') }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex justify-end pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('batch-sections-modal')">
                    {{ __('Done') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Delete Confirmation Modal --}}
    <x-ui.modal name="batch-delete-modal" max-width="max-w-md" :title="__('Confirm Batch Deletion')" :description="__('This action will soft-delete the batch from the system.')">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-rose-500/10 text-rose-600">
                    <x-icon name="alert-triangle" class="h-6 w-6" />
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{ __('Are you sure you want to delete this batch? All historical data will be preserved in audit logs, but active enrollments must be cleared first.') }}
                    </p>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('batch-delete-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" wire:click="deleteSelected">
                    <x-icon name="trash" class="h-3.5 w-3.5 mr-1"/>
                    {{ __('Delete Batch') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Bulk Import Modal --}}
    <x-ui.modal
        name="batch-import-modal"
        max-width="max-w-lg"
        :title="__('Bulk Import Batches')"
        :description="__('Upload an Excel (.xlsx, .xls) or CSV spreadsheet with batch cohort records.')"
    >
        <div class="space-y-4">
            <div class="p-3 rounded-xl bg-primary/10 border border-primary/20 flex items-start gap-2.5 text-xs text-foreground">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0 mt-0.5" />
                <div class="space-y-1">
                    <p class="font-semibold">{{ __('Spreadsheet Guidelines:') }}</p>
                    <p class="text-muted-foreground">{{ __('Required columns: Batch Code, Batch Name, Academic Year. Optional: Start Date, End Date, Max Students, Status (active/upcoming/completed/archived), Linked Course Codes, Description.') }}</p>
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
                            {{ __(':count Batches Imported', ['count' => $importResult['imported']]) }}
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
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('batch-import-modal')">
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
