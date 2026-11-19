<?php

use App\Helpers\CourseData;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Services\AcademicImportService;
use App\Services\StudentImportExportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Course Management')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'sections')]
    public string $sectionsFilter = '';

    #[Url(as: 'admit_once')]
    public string $admitOnceFilter = '';

    public string $sortField = 'name';
    public string $sortDirection = 'asc';
    public int $perPage = 10;

    // Form Modal state
    public ?int $editingId = null;
    public array $form = [
        'name' => '',
        'code' => '',
        'category' => 'Classroom',
        'duration' => '',
        'fee_type' => 'monthly',
        'fee' => '1000.00',
        'fee_reserved' => '500.00',
        'capacity' => '200',
        'has_sections' => false,
        'identifier_code' => '',
        'admit_once_only' => false,
        'max_attempts' => '',
        'status' => 'active',
        'description' => '',
    ];

    // Detail Modal state
    public ?int $viewingId = null;
    public ?Course $viewingCourse = null;

    // Delete Modal state
    public ?int $deleteId = null;

    // Import Modal state
    public $importFile = null;
    public array $importResult = [];

    public function openImportModal(): void
    {
        $this->authorize('courses.manage');
        $this->importFile = null;
        $this->importResult = [];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'course-import-modal');
    }

    public function downloadTemplate()
    {
        $this->authorize('courses.manage');

        return AcademicImportService::generateCourseTemplate();
    }

    public function processImport(): void
    {
        $this->authorize('courses.manage');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = AcademicImportService::importCourses($this->importFile);
            $this->importResult = $result;

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __(':count courses imported successfully.', ['count' => $result['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('No courses were imported. Review errors below.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSectionsFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAdmitOnceFilter(): void
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
            'total' => Course::count(),
            'classroom' => Course::where('category', 'Classroom')->count(),
            'crash_course' => Course::where('category', 'Crash Course')->count(),
            'interview' => Course::where('category', 'Interview')->count(),
            'total_enrollments' => Enrollment::where('status', 'active')->count(),
        ];
    }

    #[Computed]
    public function categories(): array
    {
        return CourseData::getCategories();
    }

    #[Computed]
    public function feeTypes(): array
    {
        return CourseData::getFeeTypes();
    }

    #[Computed]
    public function courses()
    {
        return Course::query()
            ->with(['batches', 'sections'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('category', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->sectionsFilter !== '', fn ($q) => $q->where('has_sections', $this->sectionsFilter === 'yes'))
            ->when($this->admitOnceFilter !== '', fn ($q) => $q->where('admit_once_only', $this->admitOnceFilter === 'yes'))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'code' => '',
            'category' => 'Classroom',
            'duration' => '10 Months',
            'fee_type' => 'monthly',
            'fee' => '1000.00',
            'fee_reserved' => '500.00',
            'capacity' => '200',
            'has_sections' => false,
            'identifier_code' => '',
            'admit_once_only' => false,
            'max_attempts' => '',
            'status' => 'active',
            'description' => '',
        ];
        $this->dispatch('modal-open', name: 'course-form-modal');
    }

    public function edit(int $id): void
    {
        $course = Course::findOrFail($id);
        $this->editingId = $course->id;
        $this->form = [
            'name' => $course->name,
            'code' => $course->code,
            'category' => $course->category,
            'duration' => $course->duration ?? '',
            'fee_type' => $course->fee_type ?? 'monthly',
            'fee' => (string) $course->fee,
            'fee_reserved' => $course->fee_reserved !== null ? (string) $course->fee_reserved : '',
            'capacity' => $course->capacity !== null ? (string) $course->capacity : '',
            'has_sections' => (bool) $course->has_sections,
            'identifier_code' => $course->identifier_code ?? '',
            'admit_once_only' => (bool) $course->admit_once_only,
            'max_attempts' => (string) ($course->max_attempts ?? ''),
            'status' => $course->status,
            'description' => $course->description ?? '',
        ];
        $this->dispatch('modal-open', name: 'course-form-modal');
    }

    public function viewDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingCourse = Course::with(['batches', 'sections.batch', 'enrollments.user', 'enrollments.batch'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
            ->findOrFail($id);
        $this->dispatch('modal-open', name: 'course-view-modal');
    }

    public function updatedFormFeeType($value): void
    {
        if ($value === 'free') {
            $this->form['fee'] = '0.00';
            $this->form['fee_reserved'] = '0.00';
        }
    }

    public function save(): void
    {
        if ($this->form['fee_type'] === 'free') {
            $this->form['fee'] = '0.00';
            $this->form['fee_reserved'] = '0.00';
        }

        $this->validate([
            'form.name' => 'required|string|max:150',
            'form.code' => 'required|string|max:50|unique:courses,code,'.($this->editingId ?? 'NULL'),
            'form.category' => 'required|string|max:50',
            'form.duration' => 'nullable|string|max:100',
            'form.fee_type' => 'required|in:monthly,one_time,free',
            'form.fee' => 'required|numeric|min:0',
            'form.fee_reserved' => 'nullable|numeric|min:0',
            'form.capacity' => 'nullable|integer|min:1',
            'form.has_sections' => 'boolean',
            'form.identifier_code' => 'nullable|string|max:10',
            'form.admit_once_only' => 'boolean',
            'form.max_attempts' => 'nullable|integer|min:1',
            'form.status' => 'required|in:active,inactive,archived',
            'form.description' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $data = [
                'name' => $this->form['name'],
                'code' => strtoupper($this->form['code']),
                'category' => $this->form['category'],
                'duration' => $this->form['duration'] ?: null,
                'fee_type' => $this->form['fee_type'],
                'fee' => (float) $this->form['fee'],
                'fee_reserved' => ($this->form['fee_type'] !== 'free' && $this->form['fee_reserved'] !== '') ? (float) $this->form['fee_reserved'] : 0.00,
                'capacity' => $this->form['capacity'] !== '' ? (int) $this->form['capacity'] : null,
                'has_sections' => (bool) $this->form['has_sections'],
                'identifier_code' => $this->form['identifier_code'] ?: null,
                'admit_once_only' => (bool) $this->form['admit_once_only'],
                'max_attempts' => $this->form['max_attempts'] ? (int) $this->form['max_attempts'] : null,
                'status' => $this->form['status'],
                'description' => $this->form['description'] ?: null,
            ];

            if ($this->editingId) {
                $course = Course::findOrFail($this->editingId);
                $course->update($data);
                Toast::dispatch($this, 'success', __('Course ":name" updated successfully.', ['name' => $course->name]));
            } else {
                $course = Course::create($data);
                Toast::dispatch($this, 'success', __('Course ":name" created successfully.', ['name' => $course->name]));
            }

            DB::commit();
            $this->dispatch('modal-close', name: 'course-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save course: :err', ['err' => $e->getMessage()]));
        }
    }

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->dispatch('modal-open', name: 'course-delete-modal');
    }

    public function deleteSelected(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $course = Course::findOrFail($this->deleteId);

        // Check if active student enrollments exist
        $enrolledCount = $course->enrollments()->where('status', 'active')->count();
        if ($enrolledCount > 0) {
            Toast::dispatch($this, 'error', __('Cannot delete course with :count active student enrollments.', ['count' => $enrolledCount]));
            $this->dispatch('modal-close', name: 'course-delete-modal');
            return;
        }

        $course->delete();
        Toast::dispatch($this, 'success', __('Course ":name" deleted successfully.', ['name' => $course->name]));
        $this->deleteId = null;
        $this->dispatch('modal-close', name: 'course-delete-modal');
    }

    public function export(string $format = 'csv')
    {
        $courses = Course::withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])->get();

        $headers = ['Code', 'Course Name', 'Category', 'Duration', 'Fee (INR)', 'Has Sections', 'ID Identifier', 'Admit Once Only', 'Max Attempts', 'Active Enrolled', 'Status'];
        $rows = [];

        foreach ($courses as $c) {
            $rows[] = [
                $c->code,
                $c->name,
                $c->category,
                $c->duration ?? 'N/A',
                number_format($c->fee, 2),
                $c->has_sections ? 'Yes' : 'No',
                $c->identifier_code ?? 'None',
                $c->admit_once_only ? 'Yes' : 'No',
                $c->max_attempts ?? 'Uncapped',
                $c->enrollments_count,
                ucfirst($c->status),
            ];
        }

        $title = 'UPSC Courses and Programmes Master Report';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Academic Programmes and Fee Schedules'),
            'xlsx' => StudentImportExportService::exportExcel('courses-master-report', $headers, $rows),
            default => StudentImportExportService::exportCsv('courses-master-report', $headers, $rows),
        };
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'categoryFilter', 'statusFilter', 'sectionsFilter', 'admitOnceFilter']);
        $this->sortField = 'created_at';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="graduation-cap" class="w-7 h-7 text-primary" />
                {{ __('Course Management') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage UPSC coaching courses, fee schedules, optional section assignment flags, student ID generation identifiers, and admission restriction rules.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
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

            @canany(['courses.manage', 'courses.import'])
            <x-ui.button type="button" variant="outline" size="sm" class="h-9 gap-1.5" wire:click="openImportModal" x-data x-on:click="$store.modals.open('course-import-modal')">
                <x-icon name="upload" class="h-3.5 w-3.5"/>
                {{ __('Import') }}
            </x-ui.button>
            @endcanany

            @can('courses.manage')
            <x-ui.button variant="default" size="sm" wire:click="create" class="h-9 gap-1.5">
                <x-icon name="plus" class="h-3.5 w-3.5"/>
                {{ __('Create Course') }}
            </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Courses') }}</span>
                <span class="p-2 rounded-lg bg-sky-50 dark:bg-sky-950/50 text-sky-600 dark:text-sky-400">
                    <x-icon name="book-open" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ $this->stats['total'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Active programmes') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Classroom (CC)') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="building-2" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ $this->stats['classroom'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Flagship foundation') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Crash Courses (PCC)') }}</span>
                <span class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="zap" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ $this->stats['crash_course'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('High-yield revision') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-violet-600 dark:text-violet-400 uppercase tracking-wider">{{ __('Interview (ITP)') }}</span>
                <span class="p-2 rounded-lg bg-violet-50 dark:bg-violet-950/50 text-violet-600 dark:text-violet-400">
                    <x-icon name="users" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-violet-600 dark:text-violet-400 mt-2">{{ $this->stats['interview'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Mock panels & DAF') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider">{{ __('Enrolled Total') }}</span>
                <span class="p-2 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="badge-check" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400 mt-2">{{ $this->stats['total_enrollments'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Active course seats') }}</div>
        </div>
    </div>

    <!-- Filters Toolbar -->
    <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search courses by name, code...') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select wire:model.live="categoryFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Categories') }}</option>
                @foreach($this->categories as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>

            <select wire:model.live="sectionsFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Formats') }}</option>
                <option value="yes">{{ __('With Sections (e.g. CC)') }}</option>
                <option value="no">{{ __('Without Sections') }}</option>
            </select>

            <select wire:model.live="admitOnceFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Policies') }}</option>
                <option value="yes">{{ __('Admit Once Only') }}</option>
                <option value="no">{{ __('Re-enrollment Permitted') }}</option>
            </select>

            <div class="flex items-center justify-end">
                @if($search || $categoryFilter || $statusFilter || $sectionsFilter !== '' || $admitOnceFilter !== '')
                    <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="h-9 gap-1.5 text-xs">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                        {{ __('Reset Filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <!-- Courses Table -->
    <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-muted/50 border-b border-border text-xs text-muted-foreground uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('name')">
                            <div class="flex items-center gap-1">
                                {{ __('Course Programme') }}
                                @if($sortField === 'name')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3 h-3 text-foreground" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Category') }}</th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('fee')">
                            <div class="flex items-center gap-1">
                                {{ __('Fee & Duration') }}
                                @if($sortField === 'fee')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3 h-3 text-foreground" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Section Setup') }}</th>
                        <th class="px-4 py-3">{{ __('ID Code') }}</th>
                        <th class="px-4 py-3">{{ __('Admission Rule') }}</th>
                        <th class="px-4 py-3">{{ __('Enrolled') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-foreground">
                    @forelse($this->courses as $course)
                        <tr class="hover:bg-muted/40 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-foreground flex items-center gap-2">
                                    <span>{{ $course->name }}</span>
                                    <span class="text-xs px-2 py-0.5 rounded font-mono font-bold bg-primary/10 text-primary">
                                        {{ $course->code }}
                                    </span>
                                </div>
                                @if($course->description)
                                    <p class="text-xs text-muted-foreground truncate max-w-xs mt-0.5">{{ $course->description }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-xs">{{ $course->category }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if($course->isFree())
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-xs text-emerald-600 dark:text-emerald-400">₹0.00</span>
                                        <x-ui.badge variant="success" size="sm">{{ __('Free / No Fee') }}</x-ui.badge>
                                    </div>
                                @else
                                    <div class="font-mono font-bold text-foreground flex items-baseline gap-1">
                                        <span>₹{{ number_format($course->fee, 2) }}</span>
                                        <span class="text-[11px] font-normal text-muted-foreground">
                                            {{ $course->fee_type === 'monthly' ? '/ mo (UR)' : '(UR)' }}
                                        </span>
                                    </div>
                                    @if($course->fee_reserved !== null && (float) $course->fee_reserved > 0)
                                        <div class="text-[11px] font-mono text-emerald-600 dark:text-emerald-400">
                                            ₹{{ number_format($course->fee_reserved, 2) }}
                                            <span class="text-[10px] text-muted-foreground">{{ $course->fee_type === 'monthly' ? '/ mo (Reserved)' : '(Reserved)' }}</span>
                                        </div>
                                    @endif
                                @endif
                                <div class="text-xs text-muted-foreground mt-0.5">
                                    {{ $course->duration ?? 'Flexible' }}
                                    <span class="mx-1">·</span>
                                    <span class="{{ $course->capacity ? 'font-medium text-foreground' : 'italic' }}">
                                        {{ $course->capacity ? __('Cap: :cap', ['cap' => $course->capacity]) : __('Unlimited intake') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if($course->has_sections)
                                    <div class="flex items-center gap-1 text-xs text-primary font-medium">
                                        <x-icon name="layers" class="w-3.5 h-3.5" />
                                        <span>{{ __('Has Sections') }}</span>
                                    </div>
                                    <div class="text-xs text-muted-foreground">{{ $course->sections->count() }} {{ __('defined') }}</div>
                                @else
                                    <span class="text-xs text-muted-foreground">{{ __('Single Cohort') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($course->identifier_code)
                                    <span class="font-mono text-xs px-2 py-0.5 rounded bg-muted text-foreground font-bold">
                                        {{ $course->identifier_code }}
                                    </span>
                                @else
                                    <span class="text-xs text-muted-foreground italic">{{ __('Default') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($course->admit_once_only)
                                    <x-ui.badge variant="warning" size="sm">
                                        {{ __('Admit Once Only') }}
                                    </x-ui.badge>
                                @else
                                    <span class="text-xs text-muted-foreground">{{ __('Repeat allowed') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-semibold text-foreground">{{ $course->enrollments_count }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge variant="{{ $course->status === 'active' ? 'success' : 'secondary' }}" size="sm">
                                    {{ ucfirst($course->status) }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="viewDetails({{ $course->id }})"
                                        class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted cursor-pointer transition-colors"
                                        title="{{ __('View Course Dossier') }}"
                                    >
                                        <x-icon name="eye" class="w-4 h-4" />
                                    </button>

                                    @can('courses.manage')
                                    <button
                                        type="button"
                                        wire:click="edit({{ $course->id }})"
                                        class="p-1 rounded-md text-sky-600 hover:text-sky-700 hover:bg-sky-50 dark:hover:bg-sky-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Edit Course') }}"
                                    >
                                        <x-icon name="edit" class="w-4 h-4" />
                                    </button>
                                    @endcan

                                    @can('courses.delete')
                                    <button
                                        type="button"
                                        wire:click="selectForDelete({{ $course->id }})"
                                        class="p-1 rounded-md text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Delete Course') }}"
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
                                    <span class="text-base font-medium">{{ __('No courses found matching criteria') }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $this->courses->links() }}
        </div>
    </div>

    <!-- Create / Edit Course Modal -->
    <x-ui.modal
        name="course-form-modal"
        max-width="max-w-2xl"
        :title="$editingId ? __('Edit Course: :name', ['name' => $form['name']]) : __('Create New Course Programme')"
        :description="__('Define curriculum details, fee structure, section requirements, unique ID identifier, and admission restrictions.')"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input
                    wire:model="form.name"
                    label="{{ __('Course Name *') }}"
                    placeholder="{{ __('e.g. Composite Course (CC)') }}"
                    required
                />

                <x-ui.input
                    wire:model="form.code"
                    label="{{ __('Course Short Code *') }}"
                    placeholder="{{ __('e.g. CC, PCC, MGP, ITP') }}"
                    required
                />

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Category *') }}</label>
                    <select wire:model="form.category" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        @foreach($this->categories as $k => $lbl)
                            <option value="{{ $k }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Fee Schedule *') }}</label>
                    <select wire:model.live="form.fee_type" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        @foreach($this->feeTypes as $ftKey => $ftLabel)
                            <option value="{{ $ftKey }}">{{ $ftLabel }}</option>
                        @endforeach
                    </select>
                </div>

                @if($form['fee_type'] === 'free')
                    <div class="sm:col-span-2 p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-700 dark:text-emerald-400 flex items-center gap-2">
                        <x-icon name="check-circle" class="w-4 h-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span>{{ __('This course is Free of Cost / No Fee. Enrolled candidates will have their fee amount set to ₹0.00 and fee status marked as exempted.') }}</span>
                    </div>
                @else
                    <x-ui.input
                        wire:model="form.fee"
                        type="number"
                        step="0.01"
                        label="{{ __('UR / General Fee (INR) *') }}"
                        placeholder="{{ __('e.g. 1000.00') }}"
                        required
                    />

                    <x-ui.input
                        wire:model="form.fee_reserved"
                        type="number"
                        step="0.01"
                        label="{{ __('Reserved Category Fee (SC/ST/OBC/EWS) (INR)') }}"
                        placeholder="{{ __('e.g. 500.00 (Leave blank if same as UR)') }}"
                    />
                @endif

                <x-ui.input
                    wire:model="form.duration"
                    label="{{ __('Duration (Days, Weeks, Months, Years, or Custom)') }}"
                    placeholder="{{ __('e.g. 10 Months, 12 Weeks, 1 Year 3 Months') }}"
                />

                <x-ui.input
                    wire:model="form.capacity"
                    type="number"
                    label="{{ __('Max Intake / Capacity') }}"
                    placeholder="{{ __('e.g. 200 (Leave blank for Unlimited)') }}"
                />

                <x-ui.input
                    wire:model="form.identifier_code"
                    label="{{ __('Unique ID Identifier Code (Digit)') }}"
                    placeholder="{{ __('e.g. 1 for CC, 3 for PCC, 4 for MGP') }}"
                />
            </div>

            <x-ui.textarea
                wire:model="form.description"
                label="{{ __('Course Description & Syllabus Summary') }}"
                placeholder="{{ __('Details about syllabus, modules, faculty guidance...') }}"
                rows="2"
            />

            <!-- Configuration & Restrictions Flags -->
            <div class="p-3 bg-muted/30 rounded-lg border border-border space-y-3">
                <div class="text-xs font-bold text-foreground uppercase tracking-wider">
                    {{ __('Admission & Section Configuration') }}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-start gap-2.5 p-2 bg-card border border-border rounded-lg cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model="form.has_sections"
                            class="mt-0.5 rounded border-input text-primary focus:ring-ring"
                        />
                        <div>
                            <div class="text-xs font-semibold text-foreground">{{ __('Has Sections') }}</div>
                            <div class="text-xs text-muted-foreground">{{ __('Divide into Section A, B, etc. with section-based ID generation.') }}</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-2.5 p-2 bg-card border border-border rounded-lg cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model="form.admit_once_only"
                            class="mt-0.5 rounded border-input text-amber-600 focus:ring-amber-500"
                        />
                        <div>
                            <div class="text-xs font-semibold text-foreground">{{ __('Admit Once Only') }}</div>
                            <div class="text-xs text-muted-foreground">{{ __('Block duplicate admission if candidate was ever admitted in this course.') }}</div>
                        </div>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-ui.input
                        wire:model="form.max_attempts"
                        type="number"
                        label="{{ __('Max Lifetime Attempts (Optional)') }}"
                        placeholder="{{ __('Leave blank for uncapped') }}"
                    />

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">{{ __('Status *') }}</label>
                        <select wire:model="form.status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                            <option value="active">{{ __('Active') }}</option>
                            <option value="inactive">{{ __('Inactive') }}</option>
                            <option value="archived">{{ __('Archived') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('course-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default">
                    {{ $editingId ? __('Update Course') : __('Create Course') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Course View Dossier Modal -->
    <x-ui.modal
        name="course-view-modal"
        max-width="max-w-2xl"
        :title="$viewingCourse ? $viewingCourse->name : __('Course Dossier')"
        :description="__('Detailed curriculum parameters, admission constraints, and offered batches.')"
    >
        @if($viewingCourse)
            <div class="space-y-6">
                <div class="flex items-start justify-between border-b border-border pb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-foreground">{{ $viewingCourse->name }}</h3>
                            <x-ui.badge variant="secondary" size="sm">{{ $viewingCourse->code }}</x-ui.badge>
                        </div>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __(':category · Duration: :duration', ['category' => $viewingCourse->category, 'duration' => $viewingCourse->duration ?? 'N/A']) }}
                        </p>
                    </div>
                    <div class="text-right">
                        @if($viewingCourse->isFree())
                            <div class="text-xl font-bold text-emerald-600 dark:text-emerald-400">
                                {{ __('Free of Cost / No Fee') }}
                            </div>
                            <div class="text-xs text-muted-foreground">{{ __('₹0.00 · No student fee required') }}</div>
                        @else
                            <div class="text-2xl font-bold font-mono text-emerald-600">
                                ₹{{ number_format($viewingCourse->fee, 2) }}
                                <span class="text-xs font-normal text-muted-foreground">{{ $viewingCourse->fee_type === 'monthly' ? '/ month (UR)' : '(UR)' }}</span>
                            </div>
                            @if($viewingCourse->fee_reserved !== null && (float) $viewingCourse->fee_reserved > 0)
                                <div class="text-xs font-mono font-medium text-emerald-700 dark:text-emerald-400">
                                    ₹{{ number_format($viewingCourse->fee_reserved, 2) }} {{ $viewingCourse->fee_type === 'monthly' ? '/ mo' : '' }} (Reserved: SC/ST/OBC/EWS)
                                </div>
                            @endif
                            <div class="text-xs text-muted-foreground">{{ $viewingCourse->fee_type === 'monthly' ? __('Monthly Subscription') : __('One-time Fee') }}</div>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 p-3 bg-muted/40 rounded-lg text-xs">
                    <div>
                        <span class="text-muted-foreground">{{ __('Section Setup:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingCourse->has_sections ? __('Has Sections') : __('Single Cohort') }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Intake Capacity:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingCourse->capacity ? __(':cap Seats', ['cap' => $viewingCourse->capacity]) : __('Unlimited') }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('ID Identifier:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingCourse->identifier_code ?? 'Default' }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Admit Once Rule:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingCourse->admit_once_only ? __('Restricted (Once Only)') : __('Unrestricted') }}</div>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Active Enrollments:') }}</span>
                        <div class="font-semibold mt-0.5 text-foreground">{{ $viewingCourse->enrollments_count }}</div>
                    </div>
                </div>

                @if($viewingCourse->description)
                    <div class="text-xs text-muted-foreground p-3 bg-card rounded-lg border border-border">
                        <span class="font-semibold text-foreground block mb-1">{{ __('Overview & Syllabus:') }}</span>
                        {{ $viewingCourse->description }}
                    </div>
                @endif

                <!-- Linked Batches -->
                <div>
                    <h4 class="text-xs font-bold uppercase text-muted-foreground mb-2">{{ __('Offered In Batches') }}</h4>
                    <div class="flex flex-wrap gap-2">
                        @forelse($viewingCourse->batches as $b)
                            <div class="px-2.5 py-1.5 rounded-lg border border-border text-xs bg-card flex items-center gap-2">
                                <span class="font-bold text-foreground">{{ $b->name }}</span>
                                <x-ui.badge variant="secondary" size="sm">{{ $b->academic_year }}</x-ui.badge>
                            </div>
                        @empty
                            <span class="text-xs text-muted-foreground italic">{{ __('Not assigned to any batch yet.') }}</span>
                        @endforelse
                    </div>
                </div>

                <div class="flex justify-end pt-2 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('course-view-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal
        name="course-delete-modal"
        max-width="max-w-md"
        :title="__('Confirm Course Deletion')"
        :description="__('This action will soft-delete the course programme.')"
    >
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-destructive/10 text-destructive">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ __('Are you sure you want to delete this course? Active student enrollments must be cleared first.') }}
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('course-delete-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" wire:click="deleteSelected">
                    {{ __('Delete Course') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Bulk Import Modal -->
    <x-ui.modal
        name="course-import-modal"
        max-width="max-w-lg"
        :title="__('Bulk Import Courses')"
        :description="__('Upload an Excel (.xlsx, .xls) or CSV spreadsheet with course master records.')"
    >
        <div class="space-y-4">
            <div class="p-3 rounded-xl bg-primary/10 border border-primary/20 flex items-start gap-2.5 text-xs text-foreground">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0 mt-0.5" />
                <div class="space-y-1">
                    <p class="font-semibold">{{ __('Spreadsheet Guidelines:') }}</p>
                    <p class="text-muted-foreground">{{ __('Required columns: Course Code, Course Name. Optional: Category, Duration, Fee, Fee Reserved, Capacity, Has Sections (yes/no), Status (active/inactive), Description.') }}</p>
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
                            {{ __(':count Courses Imported', ['count' => $importResult['imported']]) }}
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
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('course-import-modal')">
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
