<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use App\Services\StudentInformationSheetService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Student Detailed Information Sheets')] class extends Component {
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

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'student_id')]
    public ?int $studentId = null;

    #[Url(as: 'ids')]
    public string $initialIds = '';

    public array $selectedStudentIds = [];
    public bool $selectAll = false;
    public bool $includeDocuments = false;

    public function mount(): void
    {
        $user = auth()->user();
        if ($user && ! $user->can('students.view') && ! $user->hasRole('Super Administrator') && ! $user->hasRole('Administrator') && ! $user->hasRole('Admissions Officer') && ! $user->hasRole('Staff')) {
            abort(403, __('Unauthorized: You lack permission to view student detailed information sheets.'));
        }

        if ($this->studentId) {
            $this->selectedStudentIds = [(string) $this->studentId];
        } elseif (! empty($this->initialIds)) {
            $ids = array_filter(array_map('trim', explode(',', $this->initialIds)));
            $this->selectedStudentIds = array_values(array_map('strval', $ids));
        } else {
            // By default, select the first student to provide an immediate live preview
            $firstStudent = $this->studentsQuery()->first();
            if ($firstStudent) {
                $this->selectedStudentIds = [(string) $firstStudent->id];
            }
        }
    }

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

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $matchingIds = $this->studentsQuery()->take(100)->pluck('users.id')->map(fn ($id) => (string) $id)->all();
            $this->selectedStudentIds = array_values(array_unique(array_merge($this->selectedStudentIds, $matchingIds)));
        } else {
            $this->selectedStudentIds = [];
        }
    }

    public function toggleStudent(int $id): void
    {
        $strId = (string) $id;
        if (in_array($strId, $this->selectedStudentIds, true)) {
            $this->selectedStudentIds = array_values(array_diff($this->selectedStudentIds, [$strId]));
            $this->selectAll = false;
        } else {
            $this->selectedStudentIds[] = $strId;
        }
    }

    public function selectAllMatching(): void
    {
        $this->selectedStudentIds = $this->studentsQuery()->take(100)->pluck('users.id')->map(fn ($id) => (string) $id)->all();
        $this->selectAll = true;
        Toast::dispatch($this, 'info', __('Selected :count matching students for detailed information sheet generation.', ['count' => count($this->selectedStudentIds)]));
    }

    public function clearSelection(): void
    {
        $this->selectedStudentIds = [];
        $this->selectAll = false;
    }

    public function exportPdf()
    {
        if (empty($this->selectedStudentIds)) {
            Toast::dispatch($this, 'warning', __('Please select at least one student to export information sheets.'));
            return null;
        }

        if (count($this->selectedStudentIds) > 100) {
            Toast::dispatch($this, 'warning', __('A maximum of 100 student information sheets can be exported in a single PDF request.'));
            return null;
        }

        return StudentInformationSheetService::downloadPdf($this->selectedStudentIds, $this->includeDocuments);
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

    public function studentsQuery(): Builder
    {
        return User::query()
            ->with([
                'studentProfile',
                'activeEnrollments.course',
                'activeEnrollments.batch',
            ])
            ->where(function ($query) {
                $query->whereHas('roles', fn ($q) => $q->where('name', 'Student'))
                    ->orWhereNotNull('student_id')
                    ->orWhereHas('enrollments');
            })
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhere('urn', 'like', $term)
                        ->orWhere('tenth_roll', 'like', $term)
                        ->orWhere('id_number', 'like', $term);
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->categoryFilter, function ($query) {
                $query->whereHas('studentProfile', fn ($q) => $q->where('category', $this->categoryFilter));
            })
            ->when($this->batchFilter, function ($query) {
                $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', (int) $this->batchFilter));
            })
            ->when($this->courseFilter, function ($query) {
                $query->whereHas('enrollments', fn ($q) => $q->where('course_id', (int) $this->courseFilter));
            })
            ->when($this->sectionFilter, function ($query) {
                $query->whereHas('enrollments', fn ($q) => $q->where('section_id', (int) $this->sectionFilter));
            })
            ->orderBy('name', 'asc');
    }

    #[Computed]
    public function candidateList()
    {
        return $this->studentsQuery()->paginate(15);
    }

    #[Computed]
    public function sheets(): Collection
    {
        if (empty($this->selectedStudentIds)) {
            return collect();
        }

        $students = User::whereIn('id', $this->selectedStudentIds)
            ->with([
                'studentProfile',
                'studentAddresses',
                'studentEducations',
                'studentEmployments',
                'studentOtherDetails',
                'upscAttempts',
                'studentDocuments',
                'enrollments.batch',
                'enrollments.course',
                'enrollments.section',
                'activeEnrollments.batch',
                'activeEnrollments.course',
                'activeEnrollments.section',
            ])
            ->get();

        // Sort in order of selection or name
        $sorted = $students->sortBy(function ($s) {
            $index = array_search((string) $s->id, $this->selectedStudentIds, true);
            return $index !== false ? $index : 9999;
        });

        return StudentInformationSheetService::getMultipleStudentsData($sorted, $this->includeDocuments);
    }

    #[Computed]
    public function metadata(): array
    {
        return StudentInformationSheetService::getMetadata();
    }
}; ?>

<div class="space-y-6">
    <style>
        @media print {
            aside, header, nav, footer, .no-print, [class*="topbar"], [class*="sidebar"] {
                display: none !important;
            }
            body, html {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            div[class*="lg:ml-"], div[class*="min-h-screen"], main {
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                display: block !important;
            }
            .print-sheet-item {
                page-break-after: always !important;
                break-after: page !important;
                margin: 0 0 20px 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                background-color: #ffffff !important;
                color: #0f172a !important;
            }
            .print-sheet-item:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .doc-annexure-item {
                page-break-before: always !important;
                break-before: page !important;
            }
        }
    </style>

    <!-- Top Action Bar (Screen Only) -->
    <div class="no-print flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border pb-4">
        <div>
            <div class="flex items-center gap-2">
                <a
                    href="{{ route('admin.students.index') }}"
                    class="p-1.5 rounded-lg border border-border bg-card text-muted-foreground hover:text-foreground hover:bg-muted transition-colors shadow-xs"
                    title="{{ __('Back to Students') }}"
                >
                    <x-icon name="arrow-left" class="w-4 h-4" />
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                    <x-icon name="file-text" class="w-6 h-6 text-primary" />
                    {{ __('Student Detailed Information Sheets') }}
                </h1>
            </div>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Generate, review, and print complete candidate information sheets with table dossiers, qualification history, and supporting document annexures.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Include Supporting Documents Toggle -->
            <label class="inline-flex items-center gap-2 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card hover:bg-muted cursor-pointer transition select-none shadow-xs">
                <input
                    type="checkbox"
                    wire:model.live="includeDocuments"
                    class="rounded border-input text-primary focus:ring-ring h-4 w-4"
                />
                <span class="flex items-center gap-1.5">
                    <x-icon name="paperclip" class="w-3.5 h-3.5 text-muted-foreground" />
                    {{ __('Include Supporting / Uploaded Documents') }}
                </span>
            </label>

            <!-- Print Button -->
            <x-ui.button
                variant="outline"
                size="sm"
                onclick="window.print()"
                class="h-9 gap-1.5 font-semibold cursor-pointer"
                :disabled="empty($selectedStudentIds)"
            >
                <x-icon name="printer" class="h-3.5 w-3.5" />
                {{ __('Print Sheets') }}
            </x-ui.button>

            <!-- Export to PDF Button -->
            <x-ui.button
                variant="default"
                size="sm"
                wire:click="exportPdf"
                wire:loading.attr="disabled"
                class="h-9 gap-1.5 font-semibold cursor-pointer shadow-xs"
                :disabled="empty($selectedStudentIds)"
            >
                <x-icon name="download" class="h-3.5 w-3.5" />
                <span wire:loading.remove wire:target="exportPdf">
                    {{ __('Export to PDF') }}
                    @if(count($selectedStudentIds) > 0)
                        ({{ count($selectedStudentIds) }})
                    @endif
                </span>
                <span wire:loading wire:target="exportPdf" class="flex items-center gap-1">
                    <span class="h-3.5 w-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                    {{ __('Generating PDF...') }}
                </span>
            </x-ui.button>
        </div>
    </div>

    <!-- Main Workspace Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- Left Panel: Candidate Selector & Filter Controls (Screen Only) -->
        <div class="no-print lg:col-span-4 xl:col-span-3 space-y-4">
            <div class="bg-card border border-border rounded-xl p-4 shadow-xs space-y-4 sticky top-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h2 class="text-sm font-bold text-foreground flex items-center gap-1.5">
                        <x-icon name="users" class="w-4 h-4 text-primary" />
                        {{ __('Select Candidates') }}
                    </h2>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary">
                        {{ count($selectedStudentIds) }} {{ __('selected') }}
                    </span>
                </div>

                <!-- Search Input -->
                <div class="relative">
                    <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search candidate name, ID, URN...') }}"
                        class="h-8 w-full rounded-md border border-input bg-background pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                    />
                    @if($search)
                        <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                            <x-icon name="x" class="h-3 w-3" />
                        </button>
                    @endif
                </div>

                <!-- Filters -->
                <div class="space-y-2">
                    <div>
                        <label class="text-[11px] font-medium text-muted-foreground mb-1 block">{{ __('Filter by Batch') }}</label>
                        <select wire:model.live="batchFilter" class="h-8 w-full rounded-md border border-input bg-card px-2 text-xs shadow-xs outline-none">
                            <option value="">{{ __('All Batches') }}</option>
                            @foreach($this->batches as $batch)
                                <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-[11px] font-medium text-muted-foreground mb-1 block">{{ __('Filter by Course') }}</label>
                        <select wire:model.live="courseFilter" class="h-8 w-full rounded-md border border-input bg-card px-2 text-xs shadow-xs outline-none">
                            <option value="">{{ __('All Courses') }}</option>
                            @foreach($this->courses as $course)
                                <option value="{{ $course->id }}">{{ $course->code }} - {{ $course->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-[11px] font-medium text-muted-foreground mb-1 block">{{ __('Category') }}</label>
                            <select wire:model.live="categoryFilter" class="h-8 w-full rounded-md border border-input bg-card px-2 text-xs shadow-xs outline-none">
                                <option value="">{{ __('All') }}</option>
                                <option value="UR">UR</option>
                                <option value="SC">SC</option>
                                <option value="ST">ST</option>
                                <option value="OBCA">OBC-A</option>
                                <option value="OBCB">OBC-B</option>
                                <option value="EWS">EWS</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[11px] font-medium text-muted-foreground mb-1 block">{{ __('Status') }}</label>
                            <select wire:model.live="statusFilter" class="h-8 w-full rounded-md border border-input bg-card px-2 text-xs shadow-xs outline-none">
                                <option value="">{{ __('All') }}</option>
                                <option value="active">{{ __('Active') }}</option>
                                <option value="inactive">{{ __('Inactive') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Quick Selection Controls -->
                <div class="flex items-center justify-between text-xs pt-1 border-t border-border">
                    <button
                        type="button"
                        wire:click="selectAllMatching"
                        class="text-primary hover:underline font-medium text-[11px] cursor-pointer"
                    >
                        {{ __('Select Matching') }}
                    </button>
                    @if(count($selectedStudentIds) > 0)
                        <button
                            type="button"
                            wire:click="clearSelection"
                            class="text-rose-600 hover:underline font-medium text-[11px] cursor-pointer"
                        >
                            {{ __('Clear All') }}
                        </button>
                    @endif
                </div>

                <!-- Candidates List -->
                <div class="space-y-1.5 max-h-[380px] overflow-y-auto pr-1 divide-y divide-border/60">
                    @forelse($this->candidateList as $candidate)
                        @php
                            $isSelected = in_array((string) $candidate->id, $selectedStudentIds, true);
                        @endphp
                        <div
                            wire:click="toggleStudent({{ $candidate->id }})"
                            class="flex items-center gap-2.5 p-2 rounded-lg cursor-pointer transition-colors text-xs {{ $isSelected ? 'bg-primary/10 border border-primary/30' : 'hover:bg-muted/70' }}"
                        >
                            <input
                                type="checkbox"
                                value="{{ $candidate->id }}"
                                {{ $isSelected ? 'checked' : '' }}
                                class="rounded border-input text-primary focus:ring-ring h-3.5 w-3.5 pointer-events-none"
                            />
                            <x-ui.avatar
                                :name="$candidate->name"
                                :initials="$candidate->initials()"
                                :src="$candidate->avatarUrl()"
                                size="size-7 text-[10px] shrink-0"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="font-medium text-foreground truncate flex items-center justify-between">
                                    <span>{{ $candidate->name }}</span>
                                    @if($candidate->studentProfile?->category)
                                        <span class="text-[9px] text-muted-foreground">{{ $candidate->studentProfile->category }}</span>
                                    @endif
                                </div>
                                <div class="text-[10px] text-muted-foreground truncate">
                                    {{ $candidate->student_id ?: ($candidate->urn ?: 'No ID') }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-4 text-center text-xs text-muted-foreground">
                            {{ __('No candidates match the selected filters.') }}
                        </div>
                    @endforelse
                </div>

                @if($this->candidateList->hasPages())
                    <div class="pt-2 text-xs border-t border-border">
                        {{ $this->candidateList->links(data: ['scrollTo' => false]) }}
                    </div>
                @endif
            </div>
        </div>

        <!-- Right Panel: Printable Information Sheets Preview & Generator -->
        <div class="lg:col-span-8 xl:col-span-9 space-y-6">
            @if($this->sheets->isEmpty())
                <div class="no-print bg-card border border-dashed border-border rounded-2xl p-12 text-center space-y-3">
                    <div class="mx-auto w-12 h-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                        <x-icon name="file-text" class="w-6 h-6" />
                    </div>
                    <h3 class="text-base font-bold text-foreground">{{ __('No Students Selected') }}</h3>
                    <p class="text-xs text-muted-foreground max-w-md mx-auto">
                        {{ __('Please select one or more candidates from the left panel to preview and generate their Detailed Information Sheet for printing or PDF export.') }}
                    </p>
                    <div class="pt-2">
                        <x-ui.button size="sm" variant="outline" wire:click="selectAllMatching" class="gap-1.5 text-xs">
                            <x-icon name="check-circle" class="w-3.5 h-3.5" />
                            {{ __('Select First Matching Candidates') }}
                        </x-ui.button>
                    </div>
                </div>
            @else
                <!-- Printable Sheets Container -->
                <div class="space-y-8">
                    @foreach($this->sheets as $index => $sheet)
                        <div class="print-sheet-item bg-white dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 border border-zinc-200 dark:border-zinc-800 rounded-xl p-6 sm:p-8 shadow-sm">
                            <!-- Official Header -->
                            <div class="text-center border-b-2 border-blue-900 dark:border-blue-700 pb-3 mb-4">
                                <h2 class="text-base sm:text-lg font-bold text-blue-900 dark:text-blue-400 uppercase tracking-wide">
                                    {{ $this->metadata['institution_name'] }}
                                </h2>
                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">
                                    {{ $this->metadata['institution_sub'] }} &bull; {{ $this->metadata['institution_address'] }}
                                </p>
                                <div class="mt-2 inline-block px-3 py-1 bg-blue-900 text-white dark:bg-blue-800 text-xs font-bold uppercase tracking-wider rounded">
                                    {{ __('Student Detailed Information Sheet') }}
                                </div>
                            </div>

                            <!-- Meta Info Bar -->
                            <div class="flex flex-wrap items-center justify-between text-xs text-zinc-600 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800 pb-2 mb-4 gap-2">
                                <div>
                                    <span class="font-bold text-zinc-900 dark:text-zinc-200">{{ __('Student ID') }}:</span>
                                    <span class="font-mono font-bold text-blue-700 dark:text-blue-400 ml-1">{{ $sheet['student_id'] }}</span>
                                    @if($sheet['urn'])
                                        <span class="mx-1 text-zinc-300 dark:text-zinc-700">|</span>
                                        <span class="font-bold text-zinc-900 dark:text-zinc-200">{{ __('URN') }}:</span>
                                        <span class="font-mono ml-1">{{ $sheet['urn'] }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <span><strong>{{ __('Generated') }}:</strong> {{ $this->metadata['generated_at'] }}</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $sheet['status'] === 'Active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300' }}">
                                        {{ $sheet['status'] }}
                                    </span>
                                </div>
                            </div>

                            <!-- Section 1: Basic Identity & Passport Photo -->
                            <div class="mb-5">
                                <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                    1. {{ __('Primary Identity & Personal Particulars') }}
                                </div>

                                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded">
                                    <table class="w-full text-xs border-collapse table-fixed">
                                        <colgroup>
                                            <col class="w-[18%]">
                                            <col class="w-[28%]">
                                            <col class="w-[16%]">
                                            <col class="w-[24%]">
                                            <col class="w-[14%] min-w-[115px]">
                                        </colgroup>
                                        <tbody>
                                            <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Full Name') }}</td>
                                                <td class="p-2 font-bold text-sm text-blue-900 dark:text-blue-300 border-r border-zinc-200 dark:border-zinc-800" colspan="3">{{ $sheet['name'] }}</td>
                                                <td rowspan="5" class="p-2 text-center align-middle bg-zinc-50 dark:bg-zinc-900/40 border-l border-zinc-200 dark:border-zinc-800">
                                                    <div class="flex flex-col items-center justify-center gap-1.5 py-1">
                                                        <div class="w-24 h-32 border-2 border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex items-center justify-center overflow-hidden rounded shadow-xs">
                                                            @if(!empty($sheet['photo_url']))
                                                                <img src="{{ $sheet['photo_url'] }}" alt="{{ $sheet['name'] }}" class="w-full h-full object-cover" />
                                                            @else
                                                                <div class="flex flex-col items-center justify-center p-2 text-center text-zinc-400 dark:text-zinc-500">
                                                                    <div class="w-9 h-9 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-400 dark:text-zinc-500 font-bold text-xs mb-1">
                                                                        {{ $sheet['initials'] ?? 'STU' }}
                                                                    </div>
                                                                    <span class="text-[9px] font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Photo') }}</span>
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <span class="text-[10px] font-mono font-bold text-zinc-600 dark:text-zinc-400">{{ $sheet['student_id'] }}</span>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Date of Birth') }}</td>
                                                <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $sheet['dob'] }} ({{ $sheet['age'] }})</td>
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Gender') }}</td>
                                                <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $sheet['gender'] }}</td>
                                            </tr>
                                            <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Primary Contact') }}</td>
                                                <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono">{{ $sheet['phone'] ?: '—' }}</td>
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('WhatsApp') }}</td>
                                                <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono">{{ $sheet['whatsapp_no'] ?: '—' }}</td>
                                            </tr>
                                            <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Email Address') }}</td>
                                                <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono text-[11px]" colspan="3">{{ $sheet['email'] }}</td>
                                            </tr>
                                            <tr>
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('10th Roll No.') }}</td>
                                                <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono">{{ $sheet['tenth_roll'] }}</td>
                                                <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ $sheet['id_type'] }}</td>
                                                <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono">{{ $sheet['id_number'] }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Section 2: Social Reservation & Family Background -->
                            <div class="mb-5">
                                <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                    2. {{ __('Social Reservation & Family Background') }}
                                </div>
                                <table class="w-full text-xs border-collapse border border-zinc-200 dark:border-zinc-800">
                                    <tbody>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                            <td class="w-1/6 p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Category') }}</td>
                                            <td class="w-2/6 p-2 border-r border-zinc-200 dark:border-zinc-800">
                                                <strong>{{ $sheet['profile']['category'] }}</strong>
                                                @if($sheet['profile']['subcaste'] && $sheet['profile']['subcaste'] !== '—')
                                                    ({{ $sheet['profile']['subcaste'] }})
                                                @endif
                                            </td>
                                            <td class="w-1/6 p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Creamy Layer') }}</td>
                                            <td class="w-2/6 p-2">{{ $sheet['profile']['creamy_layer'] }}</td>
                                        </tr>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Caste Certificate') }}</td>
                                            <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $sheet['profile']['cert_no'] }}</td>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Issued Date / By') }}</td>
                                            <td class="p-2">
                                                {{ $sheet['profile']['issued_date'] }}
                                                @if($sheet['profile']['issued_by'] && $sheet['profile']['issued_by'] !== '—')
                                                    / {{ $sheet['profile']['issued_by'] }}
                                                @endif
                                            </td>
                                        </tr>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('PwBD Status') }}</td>
                                            <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">
                                                @if($sheet['profile']['is_pwbd'])
                                                    <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                                        {{ __('Yes') }} ({{ $sheet['profile']['pwbd_type'] }} - {{ $sheet['profile']['pwd_percentage'] }})
                                                    </span>
                                                @else
                                                    {{ __('No') }}
                                                @endif
                                            </td>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('PwBD Certificate') }}</td>
                                            <td class="p-2">{{ $sheet['profile']['pwbd_cert_no'] }}</td>
                                        </tr>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Father\'s Name') }}</td>
                                            <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $sheet['profile']['father_name'] }}</td>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Father\'s Occupation') }}</td>
                                            <td class="p-2">{{ $sheet['profile']['father_occupation'] }}</td>
                                        </tr>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Mother\'s Name') }}</td>
                                            <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $sheet['profile']['mother_name'] }}</td>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Mother\'s Occupation') }}</td>
                                            <td class="p-2">{{ $sheet['profile']['mother_occupation'] }}</td>
                                        </tr>
                                        <tr>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Family Income') }}</td>
                                            <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-bold text-zinc-900 dark:text-zinc-100">{{ $sheet['profile']['family_income'] }}</td>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Working Candidate') }}</td>
                                            <td class="p-2">
                                                {{ $sheet['profile']['is_working_professional'] ? __('Yes') : __('No') }}
                                                @if($sheet['profile']['occupation'] && $sheet['profile']['occupation'] !== '—')
                                                    ({{ $sheet['profile']['occupation'] }})
                                                @endif
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Section 3: Addresses -->
                            <div class="mb-5">
                                <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                    3. {{ __('Address Details') }}
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                    <div class="border border-zinc-200 dark:border-zinc-800 rounded p-3 bg-zinc-50/50 dark:bg-zinc-900/30">
                                        <div class="font-bold text-zinc-900 dark:text-zinc-100 uppercase tracking-wider text-[11px] mb-1.5 flex items-center gap-1.5">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                                            {{ __('Permanent Address') }}
                                        </div>
                                        <p class="text-zinc-700 dark:text-zinc-300 leading-relaxed">
                                            {{ $sheet['permanent_address']['formatted'] }}
                                        </p>
                                    </div>
                                    <div class="border border-zinc-200 dark:border-zinc-800 rounded p-3 bg-zinc-50/50 dark:bg-zinc-900/30">
                                        <div class="font-bold text-zinc-900 dark:text-zinc-100 uppercase tracking-wider text-[11px] mb-1.5 flex items-center gap-1.5">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
                                            {{ __('Correspondence / Present Address') }}
                                        </div>
                                        <p class="text-zinc-700 dark:text-zinc-300 leading-relaxed">
                                            {{ $sheet['correspondence_address']['formatted'] }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 4: Educational Qualifications -->
                            <div class="mb-5">
                                <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                    4. {{ __('Educational Qualifications') }}
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs border-collapse border border-zinc-200 dark:border-zinc-800">
                                        <thead>
                                            <tr class="bg-zinc-100 dark:bg-zinc-900 text-zinc-800 dark:text-zinc-200 font-bold uppercase text-[10px]">
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Level') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Board / University') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Institute') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Stream / Subject') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Year') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Marks / %') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Division') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($sheet['educations'] as $edu)
                                                <tr class="border-b border-zinc-200 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-bold text-zinc-900 dark:text-zinc-100">
                                                        {{ $edu['level'] }}
                                                        @if($edu['is_highest'])
                                                            <span class="text-blue-600 text-[10px] ml-1" title="{{ __('Highest Qualification') }}">*</span>
                                                        @endif
                                                    </td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $edu['board_university'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $edu['institute'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $edu['stream_subject'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 text-center font-mono">{{ $edu['passing_year'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 text-center font-bold">{{ $edu['percentage'] }}</td>
                                                    <td class="p-2 text-center">{{ $edu['division'] }}</td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="7" class="p-3 text-center text-zinc-500 italic">{{ __('No educational qualifications entered.') }}</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Section 5: Course Enrollments -->
                            <div class="mb-5">
                                <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                    5. {{ __('Course Enrollments & Academic Admissions') }}
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs border-collapse border border-zinc-200 dark:border-zinc-800">
                                        <thead>
                                            <tr class="bg-zinc-100 dark:bg-zinc-900 text-zinc-800 dark:text-zinc-200 font-bold uppercase text-[10px]">
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Enrollment No.') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Course') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Batch') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Section') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Roll No.') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Enrolled Date') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Status') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($sheet['enrollments'] as $enr)
                                                <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono text-[11px]">{{ $enr['enrollment_no'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-bold text-zinc-900 dark:text-zinc-100">{{ $enr['course'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $enr['batch'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $enr['section'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 text-center font-bold font-mono">{{ $enr['roll_no'] }}</td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 text-center">{{ $enr['enrolled_at'] }}</td>
                                                    <td class="p-2 text-center">
                                                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-bold uppercase {{ $enr['status'] === 'Active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300' }}">
                                                            {{ $enr['status'] }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="7" class="p-3 text-center text-zinc-500 italic">{{ __('No course enrollment records.') }}</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Section 6: UPSC Civil Services Details -->
                            <div class="mb-5">
                                <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                    6. {{ __('UPSC Civil Services Information & History') }}
                                </div>
                                <table class="w-full text-xs border-collapse border border-zinc-200 dark:border-zinc-800">
                                    <tbody>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                            <td class="w-1/4 p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Optional Subject') }}</td>
                                            <td class="w-1/4 p-2 border-r border-zinc-200 dark:border-zinc-800 font-bold text-blue-800 dark:text-blue-300">{{ $sheet['other_details']['optional_subject'] }}</td>
                                            <td class="w-1/4 p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Attempts Given / Left') }}</td>
                                            <td class="w-1/4 p-2">{{ $sheet['other_details']['attempts_already_given'] }} given / {{ $sheet['other_details']['attempts_left'] }} left</td>
                                        </tr>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Year of Appearing') }}</td>
                                            <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono">{{ $sheet['other_details']['year_of_appearing'] }}</td>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('School Medium') }}</td>
                                            <td class="p-2">{{ $sheet['profile']['school_language'] }}</td>
                                        </tr>
                                        <tr>
                                            <td class="p-2 font-bold bg-zinc-50 dark:bg-zinc-900/50 text-zinc-600 dark:text-zinc-400 border-r border-zinc-200 dark:border-zinc-800">{{ __('Activities & Hobbies') }}</td>
                                            <td class="p-2" colspan="3">
                                                {{ $sheet['other_details']['hobbies'] }}
                                                @if($sheet['other_details']['activities'] && $sheet['other_details']['activities'] !== '—')
                                                    &bull; {{ $sheet['other_details']['activities'] }}
                                                @endif
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Section 7: Employment History (If available) -->
                            @if(count($sheet['employments']) > 0)
                                <div class="mb-5">
                                    <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                        7. {{ __('Employment & Professional Experience') }}
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs border-collapse border border-zinc-200 dark:border-zinc-800">
                                            <thead>
                                                <tr class="bg-zinc-100 dark:bg-zinc-900 text-zinc-800 dark:text-zinc-200 font-bold uppercase text-[10px]">
                                                    <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Employer / Organization') }}</th>
                                                    <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Designation') }}</th>
                                                    <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Nature') }}</th>
                                                    <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Location') }}</th>
                                                    <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Period') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($sheet['employments'] as $emp)
                                                    <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                                        <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-bold">{{ $emp['employer_name'] }}</td>
                                                        <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $emp['designation'] }}</td>
                                                        <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $emp['nature_of_employment'] }}</td>
                                                        <td class="p-2 border-r border-zinc-200 dark:border-zinc-800">{{ $emp['location'] }}</td>
                                                        <td class="p-2 text-center">{{ $emp['from_date'] }} - {{ $emp['to_date'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif

                            <!-- Section 8: Uploaded Supporting Documents Registry -->
                            <div class="mb-5">
                                <div class="bg-zinc-100 dark:bg-zinc-900 border-l-4 border-blue-900 dark:border-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-400 mb-2">
                                    {{ count($sheet['employments']) > 0 ? '8' : '7' }}. {{ __('Uploaded & Supporting Documents Registry') }}
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs border-collapse border border-zinc-200 dark:border-zinc-800">
                                        <thead>
                                            <tr class="bg-zinc-100 dark:bg-zinc-900 text-zinc-800 dark:text-zinc-200 font-bold uppercase text-[10px]">
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('Document Type') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-left">{{ __('File Name / Identifier') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Uploaded Date') }}</th>
                                                <th class="p-2 border border-zinc-200 dark:border-zinc-800 text-center">{{ __('Verification Status') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($sheet['documents'] as $doc)
                                                <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-bold capitalize">
                                                        {{ str_replace('_', ' ', $doc['type']) }}
                                                    </td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 font-mono text-[11px]">
                                                        @if(!empty($doc['web_url']))
                                                            <a href="{{ $doc['web_url'] }}" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1">
                                                                <span>{{ $doc['file_name'] }}</span>
                                                                <x-icon name="external-link" class="w-3 h-3 no-print" />
                                                            </a>
                                                        @else
                                                            {{ $doc['file_name'] }}
                                                        @endif
                                                    </td>
                                                    <td class="p-2 border-r border-zinc-200 dark:border-zinc-800 text-center">{{ $doc['uploaded_at'] }}</td>
                                                    <td class="p-2 text-center">
                                                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-bold uppercase {{ $doc['verified'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' }}">
                                                            {{ $doc['verified'] ? __('Verified') : __('Pending Verification') }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="4" class="p-3 text-center text-zinc-500 italic">{{ __('No uploaded supporting documents on record.') }}</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Verification Signatures & Seal -->
                            <div class="mt-8 pt-4 border-t-2 border-zinc-200 dark:border-zinc-800">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 text-xs">
                                    <div class="border border-zinc-200 dark:border-zinc-800 rounded p-4 flex flex-col justify-between h-32">
                                        <p class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                            <strong>{{ __('Candidate Declaration') }}:</strong>
                                            {{ __('I hereby certify that all information submitted in this application/dossier is true, correct, and complete to the best of my knowledge.') }}
                                        </p>
                                        <div class="border-t border-dashed border-zinc-400 pt-2 font-bold text-blue-900 dark:text-blue-400">
                                            {{ __('Signature of Candidate') }} &bull; {{ $sheet['name'] }}
                                        </div>
                                    </div>

                                    <div class="border border-zinc-200 dark:border-zinc-800 rounded p-4 flex flex-col justify-between h-32">
                                        <p class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                            <strong>{{ __('Institutional Verification') }}:</strong>
                                            {{ __('Verified with original credentials and official institute records.') }}
                                        </p>
                                        <div class="border-t border-dashed border-zinc-400 pt-2 font-bold text-blue-900 dark:text-blue-400">
                                            {{ __('Authorized Signatory') }} &bull; {{ $this->metadata['institution_name'] }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Document Annexures (When Include Supporting Documents is enabled) -->
                            @if($includeDocuments && count($sheet['documents']) > 0)
                                <div class="doc-annexure-item mt-10 pt-6 border-t-4 border-blue-900 dark:border-blue-600">
                                    <div class="text-center pb-3 mb-6 border-b border-zinc-200 dark:border-zinc-800">
                                        <h3 class="text-sm sm:text-base font-bold text-blue-900 dark:text-blue-400 uppercase tracking-wider">
                                            {{ __('ANNEXURE: SUPPORTING & UPLOADED CREDENTIALS') }}
                                        </h3>
                                        <p class="text-xs text-zinc-500 mt-1">
                                            {{ __('Candidate:') }} <strong>{{ $sheet['name'] }}</strong> ({{ __('ID:') }} {{ $sheet['student_id'] }})
                                        </p>
                                    </div>

                                    <div class="space-y-8">
                                        @foreach($sheet['documents'] as $doc)
                                            <div class="p-4 border border-zinc-200 dark:border-zinc-800 rounded-xl bg-zinc-50 dark:bg-zinc-900/40 text-center">
                                                <div class="flex items-center justify-between text-xs font-bold text-zinc-800 dark:text-zinc-200 border-b border-zinc-200 dark:border-zinc-800 pb-2 mb-4">
                                                    <span class="capitalize">{{ str_replace('_', ' ', $doc['type']) }} — {{ $doc['file_name'] }}</span>
                                                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $doc['verified'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                        {{ $doc['verified'] ? __('Verified Official Copy') : __('Unverified Copy') }}
                                                    </span>
                                                </div>

                                                @if($doc['is_image'] && (!empty($doc['web_url']) || !empty($doc['data_uri'])))
                                                    <div class="inline-block border border-zinc-300 dark:border-zinc-700 rounded-lg p-2 bg-white dark:bg-zinc-950 shadow-xs max-w-full">
                                                        <img
                                                            src="{{ $doc['web_url'] ?? $doc['data_uri'] }}"
                                                            alt="{{ $doc['type'] }}"
                                                            class="max-h-[550px] max-w-full mx-auto object-contain rounded"
                                                        />
                                                    </div>
                                                @else
                                                    <div class="p-8 border border-dashed border-zinc-300 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-950 text-center">
                                                        <x-icon name="file-text" class="w-10 h-10 text-primary mx-auto mb-2" />
                                                        <div class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $doc['file_name'] }}</div>
                                                        <div class="text-xs text-zinc-500 mt-1">{{ __('Document format: PDF / Non-image asset') }}</div>
                                                        @if(!empty($doc['web_url']))
                                                            <div class="mt-3">
                                                                <a href="{{ $doc['web_url'] }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-semibold bg-blue-600 text-white hover:bg-blue-700">
                                                                    <x-icon name="external-link" class="w-3.5 h-3.5" />
                                                                    {{ __('View Original Document') }}
                                                                </a>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif

                                                <div class="text-[10px] text-zinc-500 mt-3 text-left flex items-center justify-between">
                                                    <span>{{ __('Uploaded Date:') }} {{ $doc['uploaded_at'] }}</span>
                                                    <span>{{ __('Student ID:') }} {{ $sheet['student_id'] }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

