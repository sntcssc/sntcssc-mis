<?php

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\AcademicCalendarVersion;
use App\Services\AcademicCalendarService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Academic Calendar')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'year')]
    public string $yearFilter = '';

    #[Url(as: 'month')]
    public string $monthFilter = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'audience')]
    public string $audienceFilter = '';

    public string $activeView = 'planner'; // planner, table, versions

    public string $sortField = 'start_date';

    public string $sortDirection = 'asc';

    public int $perPage = 15;

    // Academic Calendar Modal State (Create / Edit)
    public ?int $editingCalendarId = null;

    public array $calendarForm = [
        'academic_year' => '',
        'title' => '',
        'theme_or_motto' => '',
        'start_date' => '',
        'end_date' => '',
        'total_working_days' => 240,
        'total_instructional_days' => 190,
        'description' => '',
        'status' => 'published',
        'is_current' => false,
        'initial_version_code' => 'v1.0',
        'initial_version_title' => 'Initial Approved Calendar',
        'approved_by' => 'Academic Council',
        'approval_date' => '',
        'effective_from' => '',
    ];

    public $initialFile = null;

    // Version Revision Upload Modal State
    public ?int $revisionCalendarId = null;

    public array $revisionForm = [
        'version_code' => '',
        'version_title' => '',
        'change_summary' => '',
        'approved_by' => '',
        'approval_date' => '',
        'effective_from' => '',
    ];

    public $revisionFile = null;

    // Activity Modal State (Create / Edit)
    public ?int $editingActivityId = null;

    public array $activityForm = [
        'academic_calendar_id' => null,
        'title' => '',
        'category' => 'academic',
        'start_date' => '',
        'end_date' => '',
        'target_audience' => 'all',
        'department' => '',
        'venue_mode' => 'offline',
        'venue_or_link' => '',
        'is_tentative' => false,
        'status' => 'planned',
        'description' => '',
        'remarks' => '',
        'display_order' => 0,
    ];

    // Bulk Import Modal State
    public $importFile = null;

    public array $importErrors = [];

    // History Modal State
    public ?int $historyCalendarId = null;

    // Delete Confirmation State
    public string $deleteType = ''; // calendar, version, activity

    public ?int $deleteId = null;

    public string $deleteTitle = '';

    public function mount(): void
    {
        if (empty($this->yearFilter)) {
            $current = AcademicCalendar::where('is_current', true)->first()
                ?? AcademicCalendar::orderBy('start_date', 'desc')->first();
            $this->yearFilter = $current ? $current->academic_year : '';
        }
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMonthFilter(): void
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

    public function updatedSearch(): void
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
    public function academicYears(): array
    {
        return AcademicCalendar::orderBy('academic_year', 'desc')
            ->pluck('academic_year')
            ->all();
    }

    #[Computed]
    public function selectedCalendar(): ?AcademicCalendar
    {
        if (empty($this->yearFilter)) {
            return AcademicCalendar::where('is_current', true)->with(['currentVersion', 'versions'])->first();
        }

        return AcademicCalendar::where('academic_year', $this->yearFilter)
            ->with(['currentVersion', 'versions'])
            ->first();
    }

    #[Computed]
    public function statistics(): array
    {
        return AcademicCalendarService::getCalendarStatistics($this->selectedCalendar);
    }

    #[Computed]
    public function monthCounts(): array
    {
        $calendar = $this->selectedCalendar;
        if (! $calendar) {
            return [];
        }

        return AcademicCalendarActivity::where('academic_calendar_id', $calendar->id)
            ->selectRaw('month, count(*) as count')
            ->groupBy('month')
            ->pluck('count', 'month')
            ->all();
    }

    #[Computed]
    public function activities()
    {
        $calendar = $this->selectedCalendar;
        if (! $calendar) {
            return collect();
        }

        $query = AcademicCalendarActivity::query()
            ->where('academic_calendar_id', $calendar->id)
            ->with('version');

        if (! empty($this->monthFilter)) {
            $query->where('month', (int) $this->monthFilter);
        }

        if (! empty($this->categoryFilter)) {
            $query->where('category', $this->categoryFilter);
        }

        if (! empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        if (! empty($this->audienceFilter)) {
            $query->where('target_audience', $this->audienceFilter);
        }

        if (! empty($this->search)) {
            $query->search($this->search);
        }

        $query->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('display_order', 'asc');

        if ($this->activeView === 'table') {
            return $query->paginate($this->perPage);
        }

        return $query->get();
    }

    // -------------------------------------------------------------
    // Academic Calendar Modal Logic
    // -------------------------------------------------------------
    public function openCreateCalendarModal(): void
    {
        $this->editingCalendarId = null;
        $this->initialFile = null;
        $currentYear = date('Y');
        $nextYear = (int) $currentYear + 1;

        $this->calendarForm = [
            'academic_year' => "{$currentYear}-{$nextYear}",
            'title' => "Annual Institutional Academic Calendar ({$currentYear}-{$nextYear})",
            'theme_or_motto' => '',
            'start_date' => "{$currentYear}-04-01",
            'end_date' => "{$nextYear}-03-31",
            'total_working_days' => 245,
            'total_instructional_days' => 192,
            'description' => '',
            'status' => 'published',
            'is_current' => false,
            'initial_version_code' => 'v1.0',
            'initial_version_title' => 'Initial Approved Calendar',
            'approved_by' => 'Academic Council',
            'approval_date' => now()->toDateString(),
            'effective_from' => "{$currentYear}-04-01",
        ];

        $this->dispatch('modal-open', name: 'calendar-form-modal');
    }

    public function openEditCalendarModal(int $id): void
    {
        $calendar = AcademicCalendar::findOrFail($id);
        $this->editingCalendarId = $calendar->id;
        $this->initialFile = null;

        $this->calendarForm = [
            'academic_year' => $calendar->academic_year,
            'title' => $calendar->title,
            'theme_or_motto' => $calendar->theme_or_motto ?? '',
            'start_date' => $calendar->start_date->toDateString(),
            'end_date' => $calendar->end_date->toDateString(),
            'total_working_days' => $calendar->total_working_days,
            'total_instructional_days' => $calendar->total_instructional_days,
            'description' => $calendar->description ?? '',
            'status' => $calendar->status,
            'is_current' => $calendar->is_current,
            'initial_version_code' => '',
            'initial_version_title' => '',
            'approved_by' => '',
            'approval_date' => '',
            'effective_from' => '',
        ];

        $this->dispatch('modal-open', name: 'calendar-form-modal');
    }

    public function saveCalendar(): void
    {
        $rules = [
            'calendarForm.academic_year' => ['required', 'string', 'max:30'],
            'calendarForm.title' => ['required', 'string', 'max:255'],
            'calendarForm.theme_or_motto' => ['nullable', 'string', 'max:255'],
            'calendarForm.start_date' => ['required', 'date'],
            'calendarForm.end_date' => ['required', 'date', 'after_or_equal:calendarForm.start_date'],
            'calendarForm.total_working_days' => ['nullable', 'integer', 'min:0'],
            'calendarForm.total_instructional_days' => ['nullable', 'integer', 'min:0'],
            'calendarForm.description' => ['nullable', 'string'],
            'calendarForm.status' => ['required', 'in:draft,published,archived'],
            'calendarForm.is_current' => ['boolean'],
        ];

        if (! $this->editingCalendarId && $this->initialFile) {
            $rules['initialFile'] = ['file', 'mimes:pdf,docx,xlsx,png,jpg', 'max:25600'];
        }

        $this->validate($rules);

        try {
            if ($this->editingCalendarId) {
                $calendar = AcademicCalendar::findOrFail($this->editingCalendarId);
                AcademicCalendarService::updateCalendar($calendar, $this->calendarForm);
                Toast::dispatch($this, 'success', __('Academic calendar updated successfully.'));
            } else {
                $calendar = AcademicCalendarService::createCalendar($this->calendarForm, $this->initialFile);
                $this->yearFilter = $calendar->academic_year;
                Toast::dispatch($this, 'success', __('Academic calendar created successfully.'));
            }

            $this->dispatch('modal-close', name: 'calendar-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error: :message', ['message' => $e->getMessage()]));
        }
    }

    public function makeCurrent(int $calendarId): void
    {
        $calendar = AcademicCalendar::findOrFail($calendarId);
        AcademicCalendarService::setCurrentCalendar($calendar);
        $this->yearFilter = $calendar->academic_year;
        Toast::dispatch($this, 'success', __('Session :year is now the active academic calendar.', ['year' => $calendar->academic_year]));
    }

    // -------------------------------------------------------------
    // Revision Upload Modal Logic (Version Control)
    // -------------------------------------------------------------
    public function openRevisionModal(?int $calendarId = null): void
    {
        $calendar = $calendarId ? AcademicCalendar::findOrFail($calendarId) : $this->selectedCalendar;
        if (! $calendar) {
            Toast::dispatch($this, 'error', __('Please select an academic calendar session first.'));

            return;
        }

        $this->revisionCalendarId = $calendar->id;
        $this->revisionFile = null;

        $maxRev = (int) $calendar->versions()->max('revision_number') ?? 0;
        $nextRev = $maxRev + 1;
        $suggestedCode = 'v1.'.$nextRev;

        $this->revisionForm = [
            'version_code' => $suggestedCode,
            'version_title' => "Revision {$nextRev} - ".now()->format('M Y').' Schedule',
            'change_summary' => '',
            'approved_by' => 'Academic Advisory Council',
            'approval_date' => now()->toDateString(),
            'effective_from' => now()->toDateString(),
        ];

        $this->dispatch('modal-open', name: 'revision-upload-modal');
    }

    public function saveRevision(): void
    {
        $this->validate([
            'revisionFile' => ['required', 'file', 'mimes:pdf,docx,xlsx,png,jpg', 'max:25600'],
            'revisionForm.version_code' => ['required', 'string', 'max:20'],
            'revisionForm.version_title' => ['required', 'string', 'max:255'],
            'revisionForm.change_summary' => ['required', 'string', 'min:5'],
            'revisionForm.approved_by' => ['nullable', 'string', 'max:150'],
            'revisionForm.approval_date' => ['nullable', 'date'],
            'revisionForm.effective_from' => ['nullable', 'date'],
        ]);

        try {
            $calendar = AcademicCalendar::findOrFail($this->revisionCalendarId);
            $version = AcademicCalendarService::uploadRevision(
                $calendar,
                $this->revisionFile,
                $this->revisionForm
            );

            Toast::dispatch($this, 'success', __('Uploaded revision :ver. Previous versions remain preserved in history.', ['ver' => $version->version_code]));
            $this->dispatch('modal-close', name: 'revision-upload-modal');
            $this->revisionFile = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to upload revision: :message', ['message' => $e->getMessage()]));
        }
    }

    // -------------------------------------------------------------
    // Activity Modal Logic (Create / Edit)
    // -------------------------------------------------------------
    public function openActivityModal(?int $id = null, ?int $month = null): void
    {
        $calendar = $this->selectedCalendar;
        if (! $calendar) {
            Toast::dispatch($this, 'error', __('Please select an academic calendar first.'));

            return;
        }

        $this->editingActivityId = $id;

        if ($id) {
            $activity = AcademicCalendarActivity::findOrFail($id);
            $this->activityForm = [
                'academic_calendar_id' => $activity->academic_calendar_id,
                'title' => $activity->title,
                'category' => $activity->category,
                'start_date' => $activity->start_date->toDateString(),
                'end_date' => $activity->end_date ? $activity->end_date->toDateString() : '',
                'target_audience' => $activity->target_audience,
                'department' => $activity->department ?? '',
                'venue_mode' => $activity->venue_mode,
                'venue_or_link' => $activity->venue_or_link ?? '',
                'is_tentative' => (bool) $activity->is_tentative,
                'status' => $activity->status,
                'description' => $activity->description ?? '',
                'remarks' => $activity->remarks ?? '',
                'display_order' => $activity->display_order,
            ];
        } else {
            $defaultDate = $calendar->start_date->toDateString();
            if ($month) {
                $year = (int) $calendar->start_date->format('Y');
                if ($month < (int) $calendar->start_date->format('n')) {
                    $year = (int) $calendar->end_date->format('Y');
                }
                $defaultDate = Carbon::create($year, $month, 15)->toDateString();
            }

            $this->activityForm = [
                'academic_calendar_id' => $calendar->id,
                'title' => '',
                'category' => 'academic',
                'start_date' => $defaultDate,
                'end_date' => '',
                'target_audience' => 'all',
                'department' => 'Academics',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Main Auditorium',
                'is_tentative' => false,
                'status' => 'planned',
                'description' => '',
                'remarks' => '',
                'display_order' => $calendar->activities()->count() + 1,
            ];
        }

        $this->dispatch('modal-open', name: 'activity-form-modal');
    }

    public function saveActivity(): void
    {
        $this->validate([
            'activityForm.title' => ['required', 'string', 'max:255'],
            'activityForm.category' => ['required', 'in:academic,examination,admission,holiday,co_curricular,administrative,sports_cultural,other'],
            'activityForm.start_date' => ['required', 'date'],
            'activityForm.end_date' => ['nullable', 'date', 'after_or_equal:activityForm.start_date'],
            'activityForm.target_audience' => ['required', 'string', 'max:100'],
            'activityForm.department' => ['nullable', 'string', 'max:150'],
            'activityForm.venue_mode' => ['required', 'in:offline,online,hybrid'],
            'activityForm.venue_or_link' => ['nullable', 'string', 'max:255'],
            'activityForm.is_tentative' => ['boolean'],
            'activityForm.status' => ['required', 'in:planned,ongoing,completed,postponed,cancelled'],
            'activityForm.description' => ['nullable', 'string'],
            'activityForm.remarks' => ['nullable', 'string'],
            'activityForm.display_order' => ['nullable', 'integer'],
        ]);

        try {
            $calendar = $this->selectedCalendar;
            if ($this->editingActivityId) {
                $activity = AcademicCalendarActivity::findOrFail($this->editingActivityId);
                AcademicCalendarService::updateActivity($activity, $this->activityForm);
                Toast::dispatch($this, 'success', __('Activity updated successfully.'));
            } else {
                AcademicCalendarService::createActivity($calendar, $this->activityForm);
                Toast::dispatch($this, 'success', __('New activity added to the calendar.'));
            }

            $this->dispatch('modal-close', name: 'activity-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error: :message', ['message' => $e->getMessage()]));
        }
    }

    // -------------------------------------------------------------
    // Bulk Import Activities Logic
    // -------------------------------------------------------------
    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importErrors = [];
        $this->dispatch('modal-open', name: 'activity-import-modal');
    }

    public function importActivities(): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,xlsx,xls,txt', 'max:10240'],
        ]);

        $calendar = $this->selectedCalendar;
        if (! $calendar) {
            Toast::dispatch($this, 'error', __('No calendar selected.'));

            return;
        }

        $res = AcademicCalendarService::importActivities($calendar, $this->importFile->getRealPath());
        $this->importErrors = $res['errors'];

        if ($res['imported'] > 0) {
            Toast::dispatch($this, 'success', __('Successfully imported :count activities.', ['count' => $res['imported']]));
            if (empty($this->importErrors)) {
                $this->dispatch('modal-close', name: 'activity-import-modal');
                $this->importFile = null;
            }
        } else {
            Toast::dispatch($this, 'error', __('Import completed with issues. Check errors list.'));
        }
    }

    // -------------------------------------------------------------
    // History Modal Logic
    // -------------------------------------------------------------
    public function openHistoryModal(int $calendarId): void
    {
        $this->historyCalendarId = $calendarId;
        $this->dispatch('modal-open', name: 'version-history-modal');
    }

    #[Computed]
    public function historyCalendar(): ?AcademicCalendar
    {
        if (! $this->historyCalendarId) {
            return $this->selectedCalendar;
        }

        return AcademicCalendar::with(['versions' => fn ($q) => $q->orderBy('revision_number', 'desc')->with('uploader')])
            ->find($this->historyCalendarId);
    }

    // -------------------------------------------------------------
    // Delete Confirmation Logic
    // -------------------------------------------------------------
    public function openDeleteModal(string $type, int $id, string $title): void
    {
        $this->deleteType = $type;
        $this->deleteId = $id;
        $this->deleteTitle = $title;
        $this->dispatch('modal-open', name: 'calendar-delete-modal');
    }

    public function confirmDelete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        try {
            if ($this->deleteType === 'calendar') {
                $cal = AcademicCalendar::findOrFail($this->deleteId);
                AcademicCalendarService::deleteCalendar($cal);
                $this->yearFilter = AcademicCalendar::first()?->academic_year ?? '';
                Toast::dispatch($this, 'success', __('Academic calendar deleted successfully.'));
            } elseif ($this->deleteType === 'version') {
                $ver = AcademicCalendarVersion::findOrFail($this->deleteId);
                AcademicCalendarService::deleteVersion($ver);
                Toast::dispatch($this, 'success', __('Calendar version deleted successfully.'));
            } elseif ($this->deleteType === 'activity') {
                $act = AcademicCalendarActivity::findOrFail($this->deleteId);
                AcademicCalendarService::deleteActivity($act);
                Toast::dispatch($this, 'success', __('Activity deleted successfully.'));
            }

            $this->dispatch('modal-close', name: 'calendar-delete-modal');
            $this->deleteId = null;
            $this->deleteType = '';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Delete failed: :message', ['message' => $e->getMessage()]));
        }
    }

    // -------------------------------------------------------------
    // Export Activities
    // -------------------------------------------------------------
    public function export(string $format): mixed
    {
        $calendar = $this->selectedCalendar;
        if (! $calendar) {
            Toast::dispatch($this, 'error', __('No calendar selected for export.'));

            return null;
        }

        $filters = [
            'month' => $this->monthFilter,
            'category' => $this->categoryFilter,
            'status' => $this->statusFilter,
            'search' => $this->search,
        ];

        return AcademicCalendarService::exportActivities($calendar, $filters, $format);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'monthFilter', 'categoryFilter', 'statusFilter', 'audienceFilter']);
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Top Header & Breadcrumb -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-foreground transition-colors">{{ __('Dashboard') }}</a>
                <span>/</span>
                <span>{{ __('Academics') }}</span>
                <span>/</span>
                <span class="text-foreground font-medium">{{ __('Academic Calendar') }}</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="calendar-days" class="w-5 h-5" />
                </span>
                {{ __('Academic Calendar & Institutional Planning') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Month-wise institutional operations, lecture commencement, test series, holidays, and multi-version document archive.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- View Mode Switcher -->
            <div class="inline-flex rounded-lg border border-border bg-muted/40 p-0.5">
                <button
                    type="button"
                    wire:click="$set('activeView', 'planner')"
                    class="rounded-md px-2.5 py-1.5 text-xs font-medium cursor-pointer transition-colors {{ $activeView === 'planner' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="calendar" class="h-3.5 w-3.5 inline mr-1 text-primary" />
                    {{ __('Month Planner') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('activeView', 'table')"
                    class="rounded-md px-2.5 py-1.5 text-xs font-medium cursor-pointer transition-colors {{ $activeView === 'table' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="layers" class="h-3.5 w-3.5 inline mr-1" />
                    {{ __('List View') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('activeView', 'versions')"
                    class="rounded-md px-2.5 py-1.5 text-xs font-medium cursor-pointer transition-colors {{ $activeView === 'versions' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="history" class="h-3.5 w-3.5 inline mr-1 text-indigo-500" />
                    {{ __('Version Vault') }}
                </button>
            </div>

            <!-- Import Modal Trigger -->
            @can('academic_calendar.import')
                <x-ui.button variant="outline" size="sm" wire:click="openImportModal" class="h-9 gap-1.5 cursor-pointer">
                    <x-icon name="upload" class="h-4 w-4"/>
                    <span class="hidden sm:inline">{{ __('Import') }}</span>
                </x-ui.button>
            @endcan

            <!-- Export Dropdown -->
            @can('academic_calendar.export')
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
                            <span>{{ __('Export Activities (CSV)') }}</span>
                        </button>
                        <button type="button" wire:click="export('xlsx')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                            <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 text-emerald-500"/>
                            <span>{{ __('Export Activities (Excel)') }}</span>
                        </button>
                        <button type="button" wire:click="export('pdf')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                            <x-icon name="file-down" class="h-3.5 w-3.5 text-rose-500"/>
                            <span>{{ __('Download Booklet (PDF)') }}</span>
                        </button>
                    </x-slot:menu>
                </x-ui.dropdown>
            @endcan

            <!-- Upload Revision Trigger (Version Control) -->
            @can('academic_calendar.revise')
                <x-ui.button variant="outline" wire:click="openRevisionModal()" size="sm" class="h-9 gap-1.5 border-indigo-500/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/10 cursor-pointer">
                    <x-icon name="upload-cloud" class="h-4 w-4"/>
                    <span>{{ __('Upload Revision') }}</span>
                </x-ui.button>
            @endcan

            <!-- Add Activity Trigger -->
            @can('academic_calendar.create')
                <x-ui.button wire:click="openActivityModal()" size="sm" class="h-9 gap-1.5 cursor-pointer bg-primary">
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('Add Activity') }}</span>
                </x-ui.button>
            @endcan

            <!-- New Session Trigger -->
            @can('academic_calendar.create')
                <x-ui.button variant="outline" wire:click="openCreateCalendarModal" size="sm" class="h-9 gap-1.5 cursor-pointer">
                    <x-icon name="calendar-plus" class="h-4 w-4 text-emerald-600"/>
                    <span class="hidden md:inline">{{ __('New Session') }}</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Real-time Dashboard KPI Statistics -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <!-- Card 1: Active Session -->
        <div class="rounded-xl border border-border bg-card p-3.5 shadow-2xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Active Session') }}</span>
                <span class="p-1 rounded-md bg-primary/10 text-primary">
                    <x-icon name="calendar" class="h-4 w-4" />
                </span>
            </div>
            <div class="mt-2 text-lg font-bold text-foreground truncate">
                {{ $this->selectedCalendar?->academic_year ?? 'N/A' }}
            </div>
            <div class="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                @if($this->selectedCalendar?->is_current)
                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-semibold">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Current Active') }}
                    </span>
                @else
                    <span class="text-amber-600 font-medium capitalize">{{ $this->selectedCalendar?->status ?? 'Inactive' }}</span>
                @endif
            </div>
        </div>

        <!-- Card 2: Total Planned Activities -->
        <div class="rounded-xl border border-border bg-card p-3.5 shadow-2xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Total Activities') }}</span>
                <span class="p-1 rounded-md bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="layers" class="h-4 w-4" />
                </span>
            </div>
            <div class="mt-2 text-xl font-bold text-foreground">
                {{ $this->statistics['total_activities'] }}
            </div>
            <div class="mt-1 text-[11px] text-muted-foreground">
                {{ __('Scheduled events') }}
            </div>
        </div>

        <!-- Card 3: Latest Approved Version -->
        <div class="rounded-xl border border-border bg-card p-3.5 shadow-2xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Approved Version') }}</span>
                <span class="p-1 rounded-md bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="file-check" class="h-4 w-4" />
                </span>
            </div>
            <div class="mt-2 flex items-center gap-2">
                <span class="text-xl font-bold text-foreground">
                    {{ $this->selectedCalendar?->currentVersion?->version_code ?? 'v1.0' }}
                </span>
                @if($this->selectedCalendar?->currentVersion)
                    <a
                        href="{{ route('academic-calendar.download.version', $this->selectedCalendar->currentVersion->id) }}"
                        class="p-1 rounded text-primary hover:bg-primary/10 transition-colors"
                        title="{{ __('Download active official document') }}"
                    >
                        <x-icon name="download" class="h-3.5 w-3.5" />
                    </a>
                @endif
            </div>
            <div class="mt-1 text-[11px] text-muted-foreground truncate">
                {{ $this->selectedCalendar?->currentVersion?->approved_by ?? __('Council Approved') }}
            </div>
        </div>

        <!-- Card 4: Upcoming This Month -->
        <div class="rounded-xl border border-border bg-card p-3.5 shadow-2xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('This Month') }}</span>
                <span class="p-1 rounded-md bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="clock" class="h-4 w-4" />
                </span>
            </div>
            <div class="mt-2 text-xl font-bold text-amber-600 dark:text-amber-400">
                {{ $this->statistics['upcoming_this_month'] }}
            </div>
            <div class="mt-1 text-[11px] text-muted-foreground">
                {{ now()->format('F Y') }}
            </div>
        </div>

        <!-- Card 5: Completed Milestones -->
        <div class="rounded-xl border border-border bg-card p-3.5 shadow-2xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Completed') }}</span>
                <span class="p-1 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle" class="h-4 w-4" />
                </span>
            </div>
            <div class="mt-2 text-xl font-bold text-emerald-600 dark:text-emerald-400">
                {{ $this->statistics['completed_activities'] }}
            </div>
            <div class="mt-1 text-[11px] text-muted-foreground">
                {{ __('Concluded events') }}
            </div>
        </div>

        <!-- Card 6: Preserved Document Versions -->
        <div class="rounded-xl border border-border bg-card p-3.5 shadow-2xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Preserved Files') }}</span>
                <span class="p-1 rounded-md bg-purple-500/10 text-purple-600 dark:text-purple-400">
                    <x-icon name="history" class="h-4 w-4" />
                </span>
            </div>
            <div class="mt-2 text-xl font-bold text-foreground">
                {{ $this->selectedCalendar?->versions->count() ?? 0 }}
            </div>
            <div class="mt-1 text-[11px] text-muted-foreground">
                {{ $this->statistics['total_downloads'] }} {{ __('downloads') }}
            </div>
        </div>
    </div>

    <!-- Academic Year Selection Banner & Details -->
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 shadow-2xs">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <!-- Left: Calendar Session Info -->
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-mono font-bold px-2.5 py-0.5 rounded-full bg-primary/10 text-primary border border-primary/20">
                        {{ $this->selectedCalendar?->calendar_code ?? 'AC' }}
                    </span>
                    <h2 class="text-base sm:text-lg font-bold text-foreground">
                        {{ $this->selectedCalendar?->title ?? __('Select Academic Year') }}
                    </h2>
                    @if($this->selectedCalendar?->is_current)
                        <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                            {{ __('Current Active Session') }}
                        </span>
                    @endif
                </div>
                <p class="text-xs text-muted-foreground flex flex-wrap items-center gap-3">
                    <span>
                        <strong>{{ __('Session Period:') }}</strong>
                        {{ $this->selectedCalendar?->formatted_period ?? '-' }}
                    </span>
                    <span>&bull;</span>
                    <span>
                        <strong>{{ __('Working Days:') }}</strong>
                        {{ $this->selectedCalendar?->total_working_days ?? 0 }} {{ __('days') }}
                    </span>
                    <span>&bull;</span>
                    <span>
                        <strong>{{ __('Instructional Days:') }}</strong>
                        {{ $this->selectedCalendar?->total_instructional_days ?? 0 }} {{ __('days') }}
                    </span>
                    @if($this->selectedCalendar?->theme_or_motto)
                        <span>&bull;</span>
                        <span class="italic text-foreground/80">"{{ $this->selectedCalendar->theme_or_motto }}"</span>
                    @endif
                </p>
            </div>

            <!-- Right: Academic Year Switcher Dropdown & Actions -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1.5">
                    <span class="text-xs font-semibold text-muted-foreground whitespace-nowrap">{{ __('Academic Session:') }}</span>
                    <select
                        wire:model.live="yearFilter"
                        class="rounded-lg border border-border bg-secondary px-3 py-1.5 text-xs font-semibold text-foreground focus:outline-none focus:ring-2 focus:ring-primary/40 cursor-pointer"
                    >
                        @foreach($this->academicYears as $year)
                            <option value="{{ $year }}">{{ $year }} @if($year === AcademicCalendar::where('is_current', true)->value('academic_year')) ({{ __('Active') }}) @endif</option>
                        @endforeach
                    </select>
                </div>

                @if($this->selectedCalendar)
                    @if(! $this->selectedCalendar->is_current)
                        @can('academic_calendar.edit')
                            <x-ui.button
                                variant="outline"
                                size="sm"
                                wire:click="makeCurrent({{ $this->selectedCalendar->id }})"
                                class="h-8 text-xs cursor-pointer text-emerald-600 border-emerald-500/30 hover:bg-emerald-500/10"
                                title="{{ __('Set this academic year as active') }}"
                            >
                                <x-icon name="check" class="h-3.5 w-3.5 mr-1" />
                                {{ __('Set as Active') }}
                            </x-ui.button>
                        @endcan
                    @endif

                    @can('academic_calendar.edit')
                        <x-ui.button
                            variant="outline"
                            size="sm"
                            wire:click="openEditCalendarModal({{ $this->selectedCalendar->id }})"
                            class="h-8 text-xs cursor-pointer"
                        >
                            <x-icon name="edit-3" class="h-3.5 w-3.5 mr-1" />
                            {{ __('Edit Details') }}
                        </x-ui.button>
                    @endcan

                    <!-- Download Latest Approved Document -->
                    @if($this->selectedCalendar->currentVersion)
                        <a
                            href="{{ route('academic-calendar.download.version', $this->selectedCalendar->currentVersion->id) }}"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 hover:bg-primary/20 text-primary border border-primary/20 px-3 py-1.5 text-xs font-semibold transition-colors"
                        >
                            <x-icon name="file-text" class="h-3.5 w-3.5" />
                            <span>{{ __('Download :ver Document', ['ver' => $this->selectedCalendar->currentVersion->version_code]) }}</span>
                        </a>
                    @endif

                    @can('academic_calendar.delete')
                        <button
                            type="button"
                            wire:click="openDeleteModal('calendar', {{ $this->selectedCalendar->id }}, '{{ $this->selectedCalendar->title }}')"
                            class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-500/10 transition-colors cursor-pointer"
                            title="{{ __('Delete this academic calendar session') }}"
                        >
                            <x-icon name="trash-2" class="h-4 w-4" />
                        </button>
                    @endcan
                @endif
            </div>
        </div>

        <!-- Month Navigation Pills (April to March Academic Cycle) -->
        <div class="mt-4 pt-3.5 border-t border-border/80">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">
                    {{ __('Month-Wise Activity Roadmap') }}
                </span>
                @if(! empty($this->monthFilter) || ! empty($this->categoryFilter) || ! empty($this->statusFilter) || ! empty($this->search))
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="text-xs text-primary hover:underline cursor-pointer flex items-center gap-1"
                    >
                        <x-icon name="x" class="h-3 w-3" />
                        {{ __('Reset Filters') }}
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none">
                <button
                    type="button"
                    wire:click="$set('monthFilter', '')"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold whitespace-nowrap cursor-pointer transition-all {{ empty($monthFilter) ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-secondary text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('All Months') }} ({{ $this->statistics['total_activities'] }})
                </button>

                @php
                    // Standard Indian Academic Cycle: April (4) to March (3)
                    $monthSequence = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];
                @endphp

                @foreach($monthSequence as $m)
                    @php
                        $mName = \Illuminate\Support\Carbon::create(null, $m, 1)->format('M');
                        $cnt = $this->monthCounts[$m] ?? 0;
                        $isSelected = (string)$monthFilter === (string)$m;
                    @endphp
                    <button
                        type="button"
                        wire:click="$set('monthFilter', '{{ $isSelected ? '' : $m }}')"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-all flex items-center gap-1.5 {{ $isSelected ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : ($cnt > 0 ? 'bg-card text-foreground border border-border hover:bg-secondary' : 'bg-muted/40 text-muted-foreground hover:bg-secondary') }}"
                    >
                        <span>{{ $mName }}</span>
                        @if($cnt > 0)
                            <span class="rounded-full px-1.5 py-0.2 text-[10px] font-bold {{ $isSelected ? 'bg-white/20 text-white' : 'bg-primary/10 text-primary' }}">
                                {{ $cnt }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="rounded-xl border border-border bg-card p-3.5 shadow-2xs">
        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-5">
            <!-- Search Input -->
            <div class="relative lg:col-span-2">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by title, code, venue, department, description...') }}"
                    class="w-full rounded-lg border border-border bg-background pl-9 pr-8 py-2 text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/40"
                />
                @if($search)
                    <button
                        type="button"
                        wire:click="$set('search', '')"
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer"
                    >
                        <x-icon name="x" class="h-3.5 w-3.5" />
                    </button>
                @endif
            </div>

            <!-- Category Filter -->
            <div>
                <select
                    wire:model.live="categoryFilter"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-primary/40 cursor-pointer"
                >
                    <option value="">{{ __('All Activity Categories') }}</option>
                    <option value="academic">{{ __('Academic Session') }}</option>
                    <option value="examination">{{ __('Examination & Tests') }}</option>
                    <option value="admission">{{ __('Admission & Enrolment') }}</option>
                    <option value="holiday">{{ __('Holiday & Vacation') }}</option>
                    <option value="co_curricular">{{ __('Co-Curricular & Seminars') }}</option>
                    <option value="administrative">{{ __('Administrative & Meeting') }}</option>
                    <option value="sports_cultural">{{ __('Sports & Cultural') }}</option>
                    <option value="other">{{ __('Other Institutional') }}</option>
                </select>
            </div>

            <!-- Status Filter -->
            <div>
                <select
                    wire:model.live="statusFilter"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-primary/40 cursor-pointer"
                >
                    <option value="">{{ __('All Activity Statuses') }}</option>
                    <option value="planned">{{ __('Planned') }}</option>
                    <option value="ongoing">{{ __('Ongoing') }}</option>
                    <option value="completed">{{ __('Completed') }}</option>
                    <option value="postponed">{{ __('Postponed') }}</option>
                    <option value="cancelled">{{ __('Cancelled') }}</option>
                </select>
            </div>

            <!-- Target Audience Filter -->
            <div>
                <select
                    wire:model.live="audienceFilter"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-primary/40 cursor-pointer"
                >
                    <option value="">{{ __('All Target Audiences') }}</option>
                    <option value="all">{{ __('General / All') }}</option>
                    <option value="students">{{ __('Enrolled Students') }}</option>
                    <option value="aspirants">{{ __('UPSC Aspirants') }}</option>
                    <option value="faculty">{{ __('Faculty Members') }}</option>
                    <option value="staff">{{ __('Administrative Staff') }}</option>
                </select>
            </div>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- VIEW 1: MONTH-WISE INTERACTIVE PLANNER (DEFAULT)               -->
    <!-- ============================================================== -->
    @if($activeView === 'planner')
        <div class="space-y-6">
            @php
                $groupedActivities = $this->activities->groupBy(fn($item) => $item->month_name . ' ' . $item->year);
            @endphp

            @if($groupedActivities->isEmpty())
                <div class="rounded-xl border border-dashed border-border bg-card p-12 text-center">
                    <x-icon name="calendar-x" class="mx-auto h-12 w-12 text-muted-foreground/60" />
                    <h3 class="mt-4 text-base font-semibold text-foreground">{{ __('No Activities Found') }}</h3>
                    <p class="mt-1 text-xs text-muted-foreground max-w-sm mx-auto">
                        {{ __('No institutional activities match the current filters or no activities added for this academic year.') }}
                    </p>
                    <div class="mt-4 flex items-center justify-center gap-2">
                        @can('academic_calendar.create')
                            <x-ui.button wire:click="openActivityModal" size="sm" class="gap-1.5 cursor-pointer">
                                <x-icon name="plus" class="h-4 w-4"/>
                                <span>{{ __('Add Activity') }}</span>
                            </x-ui.button>
                        @endcan
                        @can('academic_calendar.import')
                            <x-ui.button variant="outline" size="sm" wire:click="openImportModal" class="gap-1.5 cursor-pointer">
                                <x-icon name="upload" class="h-4 w-4"/>
                                <span>{{ __('Import from Excel') }}</span>
                            </x-ui.button>
                        @endcan
                    </div>
                </div>
            @else
                @foreach($groupedActivities as $monthName => $monthEvents)
                    <div class="space-y-3">
                        <!-- Month Section Header -->
                        <div class="flex items-center justify-between pb-1 border-b border-border/80">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-primary"></span>
                                <h3 class="text-sm font-bold uppercase tracking-wider text-foreground">
                                    {{ $monthName }}
                                </h3>
                                <span class="rounded-full bg-secondary px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                                    {{ $monthEvents->count() }} {{ Str::plural(__('Activity'), $monthEvents->count()) }}
                                </span>
                            </div>

                            @can('academic_calendar.create')
                                @php
                                    $firstItem = $monthEvents->first();
                                    $mNum = $firstItem ? $firstItem->month : 1;
                                @endphp
                                <button
                                    type="button"
                                    wire:click="openActivityModal(null, {{ $mNum }})"
                                    class="text-xs font-medium text-primary hover:text-primary/80 transition-colors cursor-pointer flex items-center gap-1"
                                >
                                    <x-icon name="plus" class="h-3.5 w-3.5" />
                                    <span>{{ __('Add to :month', ['month' => explode(' ', $monthName)[0]]) }}</span>
                                </button>
                            @endcan
                        </div>

                        <!-- Activities Grid for this month -->
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3.5">
                            @foreach($monthEvents as $act)
                                <div class="rounded-xl border border-border bg-card p-4 shadow-2xs hover:shadow-sm transition-all flex flex-col justify-between group">
                                    <div class="space-y-2.5">
                                        <!-- Header Badges -->
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider border {{ $act->getCategoryBadgeClasses() }}">
                                                    {{ $act->getCategoryLabel() }}
                                                </span>
                                                <span class="rounded-md px-2 py-0.5 text-[10px] font-semibold border {{ $act->getStatusBadgeClasses() }}">
                                                    {{ ucfirst($act->status) }}
                                                </span>
                                                @if($act->is_tentative)
                                                    <span class="rounded-md px-1.5 py-0.5 text-[10px] font-bold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                                        {{ __('Tentative') }}
                                                    </span>
                                                @endif
                                            </div>

                                            <span class="font-mono text-[10px] text-muted-foreground">
                                                {{ $act->activity_code }}
                                            </span>
                                        </div>

                                        <!-- Title -->
                                        <h4 class="text-sm font-bold text-foreground leading-snug group-hover:text-primary transition-colors">
                                            {{ $act->title }}
                                        </h4>

                                        <!-- Date & Timing -->
                                        <div class="flex items-center gap-2 text-xs font-semibold text-foreground/90">
                                            <x-icon name="calendar-days" class="h-4 w-4 text-primary shrink-0" />
                                            <span>{{ $act->formatted_date_range }}</span>
                                        </div>

                                        <!-- Venue & Delivery Mode -->
                                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                            <x-icon name="map-pin" class="h-3.5 w-3.5 shrink-0 text-muted-foreground/80" />
                                            <span class="capitalize font-medium">{{ $act->venue_mode }}</span>
                                            @if($act->venue_or_link)
                                                <span>&bull;</span>
                                                <span class="truncate">{{ $act->venue_or_link }}</span>
                                            @endif
                                        </div>

                                        <!-- Department & Audience -->
                                        <div class="flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground pt-1 border-t border-border/60">
                                            @if($act->department)
                                                <span class="rounded bg-secondary px-1.5 py-0.5 font-medium">
                                                    {{ $act->department }}
                                                </span>
                                            @endif
                                            <span class="rounded bg-secondary/80 px-1.5 py-0.5 capitalize">
                                                {{ __('Audience:') }} {{ $act->target_audience }}
                                            </span>
                                        </div>

                                        @if($act->description)
                                            <p class="text-xs text-muted-foreground/90 line-clamp-2 pt-0.5">
                                                {{ $act->description }}
                                            </p>
                                        @endif
                                    </div>

                                    <!-- Bottom Actions -->
                                    <div class="mt-3.5 pt-2.5 border-t border-border flex items-center justify-between text-xs">
                                        <div class="text-[11px] text-muted-foreground">
                                            @if($act->version)
                                                <span title="{{ __('Introduced in version') }} {{ $act->version->version_code }}">
                                                    {{ __('Ver:') }} <strong class="text-foreground">{{ $act->version->version_code }}</strong>
                                                </span>
                                            @endif
                                        </div>

                                        <div class="flex items-center gap-1">
                                            @can('academic_calendar.edit')
                                                <button
                                                    type="button"
                                                    wire:click="openActivityModal({{ $act->id }})"
                                                    class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                                    title="{{ __('Edit activity') }}"
                                                >
                                                    <x-icon name="edit" class="h-3.5 w-3.5" />
                                                </button>
                                            @endcan
                                            @can('academic_calendar.delete')
                                                <button
                                                    type="button"
                                                    wire:click="openDeleteModal('activity', {{ $act->id }}, '{{ $act->title }}')"
                                                    class="p-1 rounded-md text-rose-500 hover:bg-rose-500/10 transition-colors cursor-pointer"
                                                    title="{{ __('Delete activity') }}"
                                                >
                                                    <x-icon name="trash-2" class="h-3.5 w-3.5" />
                                                </button>
                                            @endcan
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

    <!-- ============================================================== -->
    <!-- VIEW 2: COMPREHENSIVE LIST / TABLE VIEW                        -->
    <!-- ============================================================== -->
    @elseif($activeView === 'table')
        <div class="rounded-xl border border-border bg-card shadow-2xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/50 border-b border-border text-[11px] uppercase font-bold text-muted-foreground tracking-wider">
                        <tr>
                            <th scope="col" class="px-4 py-3 cursor-pointer" wire:click="sortBy('activity_code')">
                                <div class="flex items-center gap-1">
                                    <span>{{ __('Code') }}</span>
                                    @if($sortField === 'activity_code')
                                        <x-icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="h-3 w-3" />
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-4 py-3 cursor-pointer" wire:click="sortBy('title')">
                                <div class="flex items-center gap-1">
                                    <span>{{ __('Activity Title') }}</span>
                                    @if($sortField === 'title')
                                        <x-icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="h-3 w-3" />
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-4 py-3">{{ __('Category') }}</th>
                            <th scope="col" class="px-4 py-3 cursor-pointer" wire:click="sortBy('start_date')">
                                <div class="flex items-center gap-1">
                                    <span>{{ __('Date Range') }}</span>
                                    @if($sortField === 'start_date')
                                        <x-icon :name="$sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="h-3 w-3" />
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-4 py-3">{{ __('Target') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('Department / Mode') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('Status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($this->activities as $act)
                            <tr class="hover:bg-secondary/40 transition-colors">
                                <td class="px-4 py-3 font-mono font-medium text-foreground whitespace-nowrap">
                                    {{ $act->activity_code }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-foreground">{{ $act->title }}</div>
                                    @if($act->description)
                                        <div class="text-[11px] text-muted-foreground line-clamp-1 mt-0.5">{{ $act->description }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="rounded-md px-2 py-0.5 text-[10px] font-bold border {{ $act->getCategoryBadgeClasses() }}">
                                        {{ $act->getCategoryLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap font-medium text-foreground">
                                    {{ $act->formatted_date_range }}
                                    @if($act->is_tentative)
                                        <span class="ml-1 text-[10px] text-amber-500 font-bold">({{ __('Tentative') }})</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap capitalize text-muted-foreground">
                                    {{ $act->target_audience }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                    <div class="font-medium text-foreground">{{ $act->department ?? '-' }}</div>
                                    <div class="text-[11px] capitalize">{{ $act->venue_mode }} &bull; {{ $act->venue_or_link ?? '-' }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="rounded-md px-2 py-0.5 text-[10px] font-semibold border {{ $act->getStatusBadgeClasses() }}">
                                        {{ ucfirst($act->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        @can('academic_calendar.edit')
                                            <button
                                                type="button"
                                                wire:click="openActivityModal({{ $act->id }})"
                                                class="p-1 rounded text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                                title="{{ __('Edit activity') }}"
                                            >
                                                <x-icon name="edit" class="h-3.5 w-3.5" />
                                            </button>
                                        @endcan
                                        @can('academic_calendar.delete')
                                            <button
                                                type="button"
                                                wire:click="openDeleteModal('activity', {{ $act->id }}, '{{ $act->title }}')"
                                                class="p-1 rounded text-rose-500 hover:bg-rose-500/10 transition-colors cursor-pointer"
                                                title="{{ __('Delete activity') }}"
                                            >
                                                <x-icon name="trash-2" class="h-3.5 w-3.5" />
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-muted-foreground">
                                    {{ __('No activities found.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($this->activities instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $this->activities->hasPages())
                <div class="p-3 border-t border-border">
                    {{ $this->activities->links() }}
                </div>
            @endif
        </div>

    <!-- ============================================================== -->
    <!-- VIEW 3: VERSION CONTROL VAULT & PRESERVED ARCHIVE              -->
    <!-- ============================================================== -->
    @elseif($activeView === 'versions')
        <div class="space-y-6">
            @php
                $calendar = $this->selectedCalendar;
            @endphp

            @if(! $calendar)
                <div class="rounded-xl border border-border bg-card p-8 text-center text-muted-foreground">
                    {{ __('No academic calendar session selected.') }}
                </div>
            @else
                <!-- Active Version Callout Card -->
                @if($calendar->currentVersion)
                    <div class="rounded-xl border border-primary/30 bg-primary/5 p-5 shadow-sm">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="space-y-1.5">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-primary text-primary-foreground font-bold px-2.5 py-0.5 text-xs">
                                        {{ $calendar->currentVersion->version_code }}
                                    </span>
                                    <span class="rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold text-[10px] px-2 py-0.5 uppercase tracking-wider border border-emerald-500/20">
                                        {{ __('Currently Active Official Revision') }}
                                    </span>
                                </div>
                                <h3 class="text-base font-bold text-foreground">
                                    {{ $calendar->currentVersion->version_title }}
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    <strong>{{ __('File:') }}</strong> {{ $calendar->currentVersion->file_name }} &bull;
                                    <strong>{{ __('Size:') }}</strong> {{ $calendar->currentVersion->human_file_size }} &bull;
                                    <strong>{{ __('Approved By:') }}</strong> {{ $calendar->currentVersion->approved_by ?? 'Academic Council' }} &bull;
                                    <strong>{{ __('Effective:') }}</strong> {{ $calendar->currentVersion->effective_from?->format('d M Y') ?? '-' }}
                                </p>
                                @if($calendar->currentVersion->change_summary)
                                    <div class="text-xs text-foreground/80 bg-background/60 p-2.5 rounded-lg border border-border/60 mt-2">
                                        <strong>{{ __('Changelog / Revision Notes:') }}</strong> {{ $calendar->currentVersion->change_summary }}
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <a
                                    href="{{ route('academic-calendar.download.version', $calendar->currentVersion->id) }}"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground px-4 py-2 text-xs font-bold shadow-xs transition-colors"
                                >
                                    <x-icon name="download" class="h-4 w-4" />
                                    <span>{{ __('Download Current (:ver)', ['ver' => $calendar->currentVersion->version_code]) }}</span>
                                </a>
                                @can('academic_calendar.revise')
                                    <x-ui.button
                                        variant="outline"
                                        size="sm"
                                        wire:click="openRevisionModal({{ $calendar->id }})"
                                        class="h-9 gap-1.5 cursor-pointer"
                                    >
                                        <x-icon name="upload-cloud" class="h-4 w-4" />
                                        <span>{{ __('Upload Next Revision') }}</span>
                                    </x-ui.button>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Preserved Version History Timeline / Table -->
                <div class="rounded-xl border border-border bg-card shadow-2xs overflow-hidden">
                    <div class="p-4 border-b border-border flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-foreground">
                                {{ __('Preserved Document Version History') }} ({{ $calendar->academic_year }})
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ __('Every revision uploaded is preserved year-wise. Old versions can be inspected, compared, and downloaded anytime.') }}
                            </p>
                        </div>
                        <span class="rounded-full bg-secondary px-2.5 py-1 text-xs font-semibold text-muted-foreground">
                            {{ $calendar->versions->count() }} {{ Str::plural(__('Preserved Version'), $calendar->versions->count()) }}
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-[11px] uppercase font-bold text-muted-foreground tracking-wider">
                                <tr>
                                    <th scope="col" class="px-4 py-3">{{ __('Version') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Title & Revision Notes') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('File Details') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Authority & Approval') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Uploaded') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Downloads') }}</th>
                                    <th scope="col" class="px-4 py-3 text-right">{{ __('Download') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($calendar->versions as $ver)
                                    <tr class="hover:bg-secondary/40 transition-colors {{ $ver->is_current ? 'bg-primary/5' : '' }}">
                                        <td class="px-4 py-3.5 whitespace-nowrap">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-bold text-foreground text-sm font-mono">{{ $ver->version_code }}</span>
                                                @if($ver->is_current)
                                                    <span class="rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold px-1.5 py-0.2 text-[9px] uppercase border border-emerald-500/20">
                                                        {{ __('Active') }}
                                                    </span>
                                                @else
                                                    <span class="rounded-full bg-muted text-muted-foreground font-medium px-1.5 py-0.2 text-[9px]">
                                                        {{ __('Archived') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="text-[10px] text-muted-foreground mt-0.5">
                                                {{ __('Revision #') }}{{ $ver->revision_number }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3.5">
                                            <div class="font-bold text-foreground">{{ $ver->version_title }}</div>
                                            @if($ver->change_summary)
                                                <div class="text-[11px] text-muted-foreground mt-1 bg-secondary/50 p-1.5 rounded border border-border/50">
                                                    {{ $ver->change_summary }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3.5 whitespace-nowrap">
                                            <div class="flex items-center gap-1.5 font-medium text-foreground">
                                                <x-icon name="file-text" class="h-3.5 w-3.5 text-primary" />
                                                <span>{{ $ver->file_name }}</span>
                                            </div>
                                            <div class="text-[11px] text-muted-foreground mt-0.5 font-mono">
                                                {{ $ver->human_file_size }} &bull; {{ strtoupper($ver->file_extension) }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3.5 whitespace-nowrap text-muted-foreground">
                                            <div class="font-medium text-foreground">{{ $ver->approved_by ?? '-' }}</div>
                                            <div class="text-[10px] mt-0.5">
                                                {{ $ver->approval_date ? __('Approved: ') . $ver->approval_date->format('d M Y') : '' }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3.5 whitespace-nowrap text-muted-foreground">
                                            <div>{{ $ver->created_at?->format('d M Y, h:i A') }}</div>
                                            <div class="text-[10px] mt-0.5">{{ $ver->uploader?->name ?? __('System') }}</div>
                                        </td>
                                        <td class="px-4 py-3.5 whitespace-nowrap font-semibold text-foreground">
                                            {{ $ver->download_count }}
                                        </td>
                                        <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1">
                                                <a
                                                    href="{{ route('academic-calendar.download.version', $ver->id) }}"
                                                    class="inline-flex items-center gap-1 rounded-md bg-secondary hover:bg-primary hover:text-primary-foreground px-2.5 py-1 text-xs font-semibold transition-colors"
                                                    title="{{ __('Download this version') }}"
                                                >
                                                    <x-icon name="download" class="h-3.5 w-3.5" />
                                                    <span>{{ __('Download') }}</span>
                                                </a>

                                                @can('academic_calendar.delete')
                                                    <button
                                                        type="button"
                                                        wire:click="openDeleteModal('version', {{ $ver->id }}, '{{ $ver->version_code }}')"
                                                        class="p-1 rounded text-rose-500 hover:bg-rose-500/10 transition-colors cursor-pointer"
                                                        title="{{ __('Delete version') }}"
                                                    >
                                                        <x-icon name="trash-2" class="h-3.5 w-3.5" />
                                                    </button>
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                            {{ __('No versions uploaded yet.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- ============================================================== -->
    <!-- INTERACTIVE MODAL DIALOGS (NO BROWSER ALERTS)                  -->
    <!-- ============================================================== -->

    <!-- Modal 1: Create / Edit Academic Calendar Session -->
    <x-ui.modal name="calendar-form-modal" maxWidth="max-w-2xl" :title="$editingCalendarId ? __('Edit Academic Calendar Session') : __('Create Academic Year Calendar')" :description="__('Configure institutional academic year boundaries, operational parameters, and initial document.')">
        <form wire:submit.prevent="saveCalendar" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Academic Year') }} *</label>
                    <input
                        type="text"
                        wire:model="calendarForm.academic_year"
                        placeholder="e.g. 2026-2027"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                    @error('calendarForm.academic_year') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Calendar Title') }} *</label>
                    <input
                        type="text"
                        wire:model="calendarForm.title"
                        placeholder="e.g. Annual Academic Calendar 2026-2027"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                    @error('calendarForm.title') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Date') }} *</label>
                    <input
                        type="date"
                        wire:model="calendarForm.start_date"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                    @error('calendarForm.start_date') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Date') }} *</label>
                    <input
                        type="date"
                        wire:model="calendarForm.end_date"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                    @error('calendarForm.end_date') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Total Working Days') }}</label>
                    <input
                        type="number"
                        wire:model="calendarForm.total_working_days"
                        min="0"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Instructional Days') }}</label>
                    <input
                        type="number"
                        wire:model="calendarForm.total_instructional_days"
                        min="0"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Annual Motto or Institutional Theme') }}</label>
                    <input
                        type="text"
                        wire:model="calendarForm.theme_or_motto"
                        placeholder="e.g. Excellence in Civil Services Guidance & Administrative Leadership"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Overview / Description') }}</label>
                    <textarea
                        wire:model="calendarForm.description"
                        rows="2"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    ></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Publication Status') }}</label>
                    <select
                        wire:model="calendarForm.status"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    >
                        <option value="published">{{ __('Published') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="archived">{{ __('Archived') }}</option>
                    </select>
                </div>

                <div class="flex items-center pt-5">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-semibold text-foreground">
                        <input type="checkbox" wire:model="calendarForm.is_current" class="rounded border-border text-primary focus:ring-primary" />
                        <span>{{ __('Set as currently active academic calendar') }}</span>
                    </label>
                </div>
            </div>

            <!-- Initial Document Attachment (For new calendars) -->
            @if(! $editingCalendarId)
                <div class="pt-3 border-t border-border space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-primary">
                        {{ __('Initial Document Attachment (Version 1.0)') }}
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Upload Document (PDF / DOCX)') }}</label>
                            <input
                                type="file"
                                wire:model="initialFile"
                                class="w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                            />
                            @error('initialFile') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Approval Authority') }}</label>
                            <input
                                type="text"
                                wire:model="calendarForm.approved_by"
                                placeholder="e.g. Academic Council"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                            />
                        </div>
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'calendar-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5 bg-primary">
                    <x-icon name="check" class="h-4 w-4" />
                    <span>{{ $editingCalendarId ? __('Update Calendar') : __('Create Academic Calendar') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal 2: Upload New Version / Revision (Version Control) -->
    <x-ui.modal name="revision-upload-modal" maxWidth="max-w-xl" :title="__('Upload Revised Academic Calendar')" :description="__('Upload revised calendar file with version control. All previous versions are preserved year-wise in the document archive.')">
        <form wire:submit.prevent="saveRevision" class="space-y-4">
            <div class="p-3 rounded-lg bg-indigo-500/10 border border-indigo-500/20 text-xs text-indigo-700 dark:text-indigo-300">
                <div class="flex items-start gap-2">
                    <x-icon name="history" class="h-4 w-4 mt-0.5 shrink-0" />
                    <div>
                        <strong>{{ __('Version Control Preservation:') }}</strong>
                        {{ __('Uploading a revision makes it the new official version. Past versions will remain accessible for auditing, comparison, and historical download.') }}
                    </div>
                </div>
            </div>

            <div class="space-y-3.5">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select Revised File (PDF / DOCX / XLSX)') }} *</label>
                    <input
                        type="file"
                        wire:model="revisionFile"
                        class="w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                    />
                    @error('revisionFile') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Version Code') }} *</label>
                        <input
                            type="text"
                            wire:model="revisionForm.version_code"
                            placeholder="e.g. v1.2"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs font-mono font-bold text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                        />
                        @error('revisionForm.version_code') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Revision Title') }} *</label>
                        <input
                            type="text"
                            wire:model="revisionForm.version_title"
                            placeholder="e.g. Mid-Term Vacation & Test Reschedule"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                        />
                        @error('revisionForm.version_title') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Approved By') }}</label>
                        <input
                            type="text"
                            wire:model="revisionForm.approved_by"
                            placeholder="e.g. Academic Council / Director"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Effective Date') }}</label>
                        <input
                            type="date"
                            wire:model="revisionForm.effective_from"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Changelog / Revision Summary (What was modified?)') }} *</label>
                    <textarea
                        wire:model="revisionForm.change_summary"
                        rows="3"
                        placeholder="{{ __('Detail what changes or adjustments were made in this calendar revision...') }}"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    ></textarea>
                    @error('revisionForm.change_summary') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'revision-upload-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5 bg-primary">
                    <x-icon name="upload-cloud" class="h-4 w-4" />
                    <span>{{ __('Upload & Publish Revision') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal 3: Create / Edit Activity Modal -->
    <x-ui.modal name="activity-form-modal" maxWidth="max-w-2xl" :title="$editingActivityId ? __('Edit Activity') : __('Add Calendar Activity')" :description="__('Configure institutional event, test date, lecture commencement, or holiday recess.')">
        <form wire:submit.prevent="saveActivity" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Activity / Event Title') }} *</label>
                    <input
                        type="text"
                        wire:model="activityForm.title"
                        placeholder="e.g. UPSC Prelims Mock Assessment Drive - Series 1"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                    @error('activityForm.title') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Category') }} *</label>
                    <select
                        wire:model="activityForm.category"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    >
                        <option value="academic">{{ __('Academic Session') }}</option>
                        <option value="examination">{{ __('Examination & Tests') }}</option>
                        <option value="admission">{{ __('Admission & Enrolment') }}</option>
                        <option value="holiday">{{ __('Holiday & Vacation') }}</option>
                        <option value="co_curricular">{{ __('Co-Curricular & Seminars') }}</option>
                        <option value="administrative">{{ __('Administrative & Meeting') }}</option>
                        <option value="sports_cultural">{{ __('Sports & Cultural') }}</option>
                        <option value="other">{{ __('Other Institutional') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Status') }} *</label>
                    <select
                        wire:model="activityForm.status"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    >
                        <option value="planned">{{ __('Planned') }}</option>
                        <option value="ongoing">{{ __('Ongoing') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="postponed">{{ __('Postponed') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Date') }} *</label>
                    <input
                        type="date"
                        wire:model="activityForm.start_date"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                    @error('activityForm.start_date') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Date (Optional)') }}</label>
                    <input
                        type="date"
                        wire:model="activityForm.end_date"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                    @error('activityForm.end_date') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Target Audience') }}</label>
                    <select
                        wire:model="activityForm.target_audience"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    >
                        <option value="all">{{ __('All / General') }}</option>
                        <option value="students">{{ __('Enrolled Students') }}</option>
                        <option value="aspirants">{{ __('UPSC Aspirants') }}</option>
                        <option value="faculty">{{ __('Faculty Members') }}</option>
                        <option value="staff">{{ __('Administrative Staff') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('In-Charge Department') }}</label>
                    <input
                        type="text"
                        wire:model="activityForm.department"
                        placeholder="e.g. Examination Cell / Academics"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Delivery Mode') }}</label>
                    <select
                        wire:model="activityForm.venue_mode"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    >
                        <option value="offline">{{ __('Offline (Classroom / Campus)') }}</option>
                        <option value="online">{{ __('Online (Meeting / Webinar)') }}</option>
                        <option value="hybrid">{{ __('Hybrid (Auditorium & Live Stream)') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Venue Details or Online Link') }}</label>
                    <input
                        type="text"
                        wire:model="activityForm.venue_or_link"
                        placeholder="e.g. Exam Halls A, B & C or Zoom Link"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description & Student Instructions') }}</label>
                    <textarea
                        wire:model="activityForm.description"
                        rows="2"
                        placeholder="{{ __('Details, milestones, or instructions for this activity...') }}"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-primary/40 focus:outline-none"
                    ></textarea>
                </div>

                <div class="flex items-center pt-2">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-semibold text-foreground">
                        <input type="checkbox" wire:model="activityForm.is_tentative" class="rounded border-border text-primary focus:ring-primary" />
                        <span>{{ __('Dates are tentative (Subject to revision)') }}</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'activity-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5 bg-primary">
                    <x-icon name="check" class="h-4 w-4" />
                    <span>{{ $editingActivityId ? __('Update Activity') : __('Add Activity') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal 4: Bulk Import Activities (Excel / CSV) -->
    <x-ui.modal name="activity-import-modal" maxWidth="max-w-xl" :title="__('Bulk Import Calendar Activities')" :description="__('Import multiple monthly activities from an Excel (.xlsx) or CSV file.')">
        <form wire:submit.prevent="importActivities" class="space-y-4">
            <div class="rounded-lg border border-border bg-secondary/50 p-3.5 space-y-2 text-xs">
                <div class="flex items-center justify-between">
                    <span class="font-bold text-foreground">{{ __('Template File:') }}</span>
                    <a
                        href="{{ route('academic-calendar.template.import') }}"
                        class="inline-flex items-center gap-1 text-primary hover:underline font-semibold"
                    >
                        <x-icon name="download" class="h-3.5 w-3.5" />
                        <span>{{ __('Download CSV Sample Template') }}</span>
                    </a>
                </div>
                <p class="text-muted-foreground text-[11px]">
                    {{ __('Columns: Title, Category, Start Date (YYYY-MM-DD), End Date, Audience, Department, Mode, Venue Details, Status, Description, Is Tentative.') }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select Spreadsheet (.xlsx, .csv)') }} *</label>
                <input
                    type="file"
                    wire:model="importFile"
                    class="w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
                @error('importFile') <span class="text-[11px] text-rose-500">{{ $message }}</span> @enderror
            </div>

            @if(! empty($importErrors))
                <div class="rounded-lg border border-rose-500/20 bg-rose-500/10 p-3 text-xs text-rose-600 dark:text-rose-400 space-y-1 max-h-36 overflow-y-auto">
                    <div class="font-bold">{{ __('Import Warnings / Errors:') }}</div>
                    @foreach($importErrors as $err)
                        <div>&bull; {{ $err }}</div>
                    @endforeach
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'activity-import-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5 bg-primary">
                    <x-icon name="upload" class="h-4 w-4" />
                    <span>{{ __('Upload & Import') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal 5: Confirm Deletion Modal -->
    <x-ui.modal name="calendar-delete-modal" maxWidth="max-w-md" :title="__('Confirm Deletion')" :description="__('This action will soft-delete the record and preserve an audit log.')">
        <div class="space-y-4">
            <div class="p-3.5 rounded-lg bg-rose-500/10 border border-rose-500/20 text-xs text-rose-700 dark:text-rose-400 flex items-start gap-2">
                <x-icon name="alert-triangle" class="h-4 w-4 mt-0.5 shrink-0" />
                <div>
                    {{ __('Are you sure you want to delete:') }}
                    <strong class="block text-foreground mt-0.5 font-bold">{{ $deleteTitle }}</strong>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'calendar-delete-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" wire:click="confirmDelete" class="gap-1.5 bg-rose-600 hover:bg-rose-700 text-white">
                    <x-icon name="trash-2" class="h-4 w-4" />
                    <span>{{ __('Confirm Delete') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

