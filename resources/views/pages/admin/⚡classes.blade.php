<?php

use App\Concerns\RenamesUploads;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Models\Section;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\ClassReminderService;
use App\Services\ClassScheduleService;
use App\Services\StudentImportExportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Class Routine & Lecture Scheduling')] class extends Component {
    use RenamesUploads, WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'section')]
    public string $sectionFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'mode')]
    public string $modeFilter = '';

    #[Url(as: 'faculty')]
    public string $facultyFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'date')]
    public string $dateFilter = ''; // today, tomorrow, this_week, upcoming, past, custom

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    public string $activeView = 'list'; // list, calendar, faculty_tracker, reports

    public string $sortField = 'class_date';

    public string $sortDirection = 'asc';

    public int $perPage = 15;

    // Single Form Modal State
    public ?int $editingId = null;

    public array $form = [
        'batch_id' => '',
        'course_id' => '',
        'section_id' => '',
        'course_plan_id' => '',
        'classroom_id' => '',
        'class_type' => 'regular',
        'class_date' => '',
        'day_of_week' => '',
        'start_time' => '10:00',
        'end_time' => '12:00',
        'lecture_number' => '',
        'subject' => '',
        'topics' => '',
        'description' => '',
        'delivery_mode' => 'offline',
        'classroom' => '',
        'platform' => 'Zoom',
        'meeting_link' => '',
        'meeting_id' => '',
        'meeting_password' => '',
        'platform_other' => '',
        'selected_faculty_ids' => [],
        'status' => 'scheduled',
    ];

    // Batch Recurrence Generator State
    public array $recurrenceForm = [
        'batch_id' => '',
        'course_id' => '',
        'section_id' => '',
        'course_plan_id' => '',
        'classroom_id' => '',
        'class_type' => 'regular',
        'start_date' => '',
        'end_date' => '',
        'days_of_week' => ['Monday', 'Wednesday', 'Friday'],
        'start_time' => '10:00',
        'end_time' => '12:00',
        'start_lecture_no' => 1,
        'subject' => '',
        'topics' => '',
        'description' => '',
        'delivery_mode' => 'offline',
        'classroom' => '',
        'platform' => 'Zoom',
        'meeting_link' => '',
        'meeting_id' => '',
        'meeting_password' => '',
        'selected_faculty_ids' => [],
    ];

    // Manual Reminder State
    public ?int $remindScheduleId = null;

    public ?ClassSchedule $remindSchedule = null;

    public array $selectedReminderChannels = ['email', 'whatsapp', 'sms', 'telegram'];

    public string $customReminderMessage = '';

    public int $enrolledStudentsCount = 0;

    // Reminder Settings State
    public bool $reminderEnabled = true;

    public int $reminderLeadTime = 60;

    public array $reminderChannels = ['email', 'whatsapp', 'sms', 'telegram'];

    public string $reminderTemplate = '';

    // Faculty Tracker State
    public string $trackerFacultyId = '';

    public string $trackerBatchId = '';

    public string $trackerCourseId = '';

    public string $trackerDateFrom = '';

    public string $trackerDateTo = '';

    // Reports Generator State
    public string $selectedReportType = 'faculty_workload'; // faculty_workload, course_coverage, classroom_utilization

    public string $reportBatchId = '';

    public string $reportCourseId = '';

    public string $reportFacultyId = '';

    // Quick Faculty Modal State
    public $quickFacultyPhoto = null;

    public $quickFacultyCv = null;

    public string $quickFacultyDepartmentSelect = 'General Studies';

    public string $quickFacultyCustomDepartment = '';

    public bool $removeQuickFacultyPhoto = false;

    public bool $removeQuickFacultyCv = false;

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

    public array $quickFacultyForm = [
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
    ];

    // Recording Modal State
    public ?int $recordingScheduleId = null;

    public ?ClassSchedule $recordingSchedule = null;

    public array $recordingForm = [
        'recording_url' => '',
        'recording_password' => '',
        'recording_notes' => '',
        'recording_status' => 'available',
    ];

    public $recordingUploadFile = null;

    // Reschedule Modal State
    public ?int $rescheduleId = null;

    public ?ClassSchedule $reschedulingSchedule = null;

    public array $rescheduleForm = [
        'new_date' => '',
        'start_time' => '',
        'end_time' => '',
        'reason' => '',
    ];

    // Cancel Modal State
    public ?int $cancelId = null;

    public ?ClassSchedule $cancellingSchedule = null;

    public string $cancelReason = '';

    // View Details Modal State
    public ?int $viewingId = null;

    public ?ClassSchedule $viewingSchedule = null;

    // Delete Modal State
    public ?int $deleteId = null;

    public ?ClassSchedule $deletingSchedule = null;

    // Import State
    public $importFile = null;

    public array $importErrors = [];

    public function mount(): void
    {
        $this->form['class_date'] = now()->format('Y-m-d');
        $this->form['day_of_week'] = now()->format('l');

        $this->recurrenceForm['start_date'] = now()->format('Y-m-d');
        $this->recurrenceForm['end_date'] = now()->addWeeks(4)->format('Y-m-d');
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

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedModeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFacultyFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    // Automatically fill day_of_week whenever class_date is chosen in single scheduler form
    public function updatedFormClassDate($value): void
    {
        if (! empty($value)) {
            try {
                $this->form['day_of_week'] = Carbon::parse($value)->format('l');
            } catch (\Throwable) {
                $this->form['day_of_week'] = '';
            }
        }
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
        $this->form['section_id'] = '';
    }

    public function updatedRecurrenceFormBatchId($batchId): void
    {
        if (! empty($batchId)) {
            $batch = Batch::with('courses')->find((int) $batchId);
            if ($batch && $batch->courses->isNotEmpty()) {
                if (! $batch->courses->contains('id', (int) $this->recurrenceForm['course_id'])) {
                    $this->recurrenceForm['course_id'] = (string) $batch->courses->first()->id;
                }
            }
        }
        $this->recurrenceForm['section_id'] = '';
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
        $today = Carbon::today();

        return [
            'total' => ClassSchedule::count(),
            'today' => ClassSchedule::whereDate('class_date', $today)->whereNotIn('status', ['cancelled'])->count(),
            'completed' => ClassSchedule::where('status', 'completed')->count(),
            'scheduled' => ClassSchedule::whereIn('status', ['scheduled', 'in_progress'])->count(),
            'cancelled' => ClassSchedule::where('status', 'cancelled')->count(),
            'online' => ClassSchedule::where('delivery_mode', 'online')->whereNotIn('status', ['cancelled'])->count(),
            'offline' => ClassSchedule::where('delivery_mode', 'offline')->whereNotIn('status', ['cancelled'])->count(),
            'with_recordings' => ClassSchedule::where('recording_status', 'available')->orWhereNotNull('recording_url')->count(),
        ];
    }

    #[Computed]
    public function classroomsList()
    {
        return Classroom::active()->orderBy('building')->orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::where('status', '!=', 'archived')->orderBy('name')->get();
    }

    #[Computed]
    public function courses()
    {
        if (! empty($this->batchFilter)) {
            $batch = Batch::with('courses')->find($this->batchFilter);
            if ($batch && $batch->courses->isNotEmpty()) {
                return $batch->courses;
            }
        }

        return Course::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function filterSections()
    {
        if (! empty($this->batchFilter) && ! empty($this->courseFilter)) {
            return Section::where('batch_id', $this->batchFilter)
                ->where('course_id', $this->courseFilter)
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
        }

        return collect();
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
    public function formSections()
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
    public function recurrenceFormCourses()
    {
        if (! empty($this->recurrenceForm['batch_id'])) {
            $batch = Batch::with('courses')->find($this->recurrenceForm['batch_id']);
            if ($batch && $batch->courses->isNotEmpty()) {
                return $batch->courses;
            }
        }

        return Course::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function recurrenceFormSections()
    {
        if (! empty($this->recurrenceForm['batch_id']) && ! empty($this->recurrenceForm['course_id'])) {
            return Section::where('batch_id', $this->recurrenceForm['batch_id'])
                ->where('course_id', $this->recurrenceForm['course_id'])
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
        }

        return collect();
    }

    #[Computed]
    public function allFaculties()
    {
        return Faculty::active()->orderBy('name')->get();
    }

    #[Computed]
    public function partnerInstitutes()
    {
        return PartnerInstitute::query()->orderBy('name')->get();
    }

    #[Computed]
    public function coursePlans()
    {
        if (! empty($this->form['batch_id']) && ! empty($this->form['course_id'])) {
            return CoursePlan::published()
                ->where('batch_id', $this->form['batch_id'])
                ->where('course_id', $this->form['course_id'])
                ->orderBy('title')
                ->get();
        }

        return CoursePlan::published()->orderBy('title')->get();
    }

    #[Computed]
    public function schedules()
    {
        return ClassSchedule::query()
            ->with(['batch', 'course', 'section', 'faculties'])
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('subject', 'like', $term)
                        ->orWhere('topics', 'like', $term)
                        ->orWhere('schedule_code', 'like', $term)
                        ->orWhere('lecture_number', 'like', $term)
                        ->orWhere('classroom', 'like', $term)
                        ->orWhereHas('course', fn ($c) => $c->where('name', 'like', $term))
                        ->orWhereHas('batch', fn ($b) => $b->where('name', 'like', $term))
                        ->orWhereHas('faculties', fn ($f) => $f->where('name', 'like', $term));
                });
            })
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->when($this->sectionFilter, fn ($q) => $q->where('section_id', (int) $this->sectionFilter))
            ->when($this->typeFilter, fn ($q) => $q->where('class_type', $this->typeFilter))
            ->when($this->modeFilter, fn ($q) => $q->where('delivery_mode', $this->modeFilter))
            ->when($this->facultyFilter, fn ($q) => $q->whereHas('faculties', fn ($f) => $f->where('faculties.id', (int) $this->facultyFilter)))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFilter, function (Builder $q) {
                $today = Carbon::today();
                match ($this->dateFilter) {
                    'today' => $q->whereDate('class_date', $today),
                    'tomorrow' => $q->whereDate('class_date', $today->copy()->addDay()),
                    'this_week' => $q->whereBetween('class_date', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()]),
                    'upcoming' => $q->whereDate('class_date', '>=', $today)->whereNotIn('status', ['cancelled']),
                    'past' => $q->whereDate('class_date', '<', $today),
                    default => null,
                };
            })
            ->when($this->dateFrom, fn ($q) => $q->whereDate('class_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('class_date', '<=', $this->dateTo))
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('start_time', 'asc')
            ->paginate($this->perPage);
    }

    public function updatedFormClassroomId($roomId): void
    {
        if (! empty($roomId)) {
            $room = Classroom::find((int) $roomId);
            if ($room) {
                $this->form['classroom'] = $room->name;
            }
        }
    }

    public function updatedRecurrenceFormClassroomId($roomId): void
    {
        if (! empty($roomId)) {
            $room = Classroom::find((int) $roomId);
            if ($room) {
                $this->recurrenceForm['classroom'] = $room->name;
            }
        }
    }

    public function openReminderModal(int $id): void
    {
        $this->remindSchedule = ClassSchedule::with(['batch', 'course', 'section', 'faculties', 'classroomRecord'])->findOrFail($id);
        $this->remindScheduleId = $this->remindSchedule->id;
        $this->selectedReminderChannels = ClassReminderService::getConfiguredChannels();
        $this->customReminderMessage = ClassReminderService::getTemplate();
        $this->enrolledStudentsCount = ClassReminderService::getEnrolledStudentsForClass($this->remindSchedule)->count();

        $this->dispatch('modal-open', name: 'class-reminder-modal');
    }

    public function sendManualReminder(): void
    {
        if (! $this->remindSchedule) {
            return;
        }

        $result = ClassReminderService::sendClassReminder(
            $this->remindSchedule,
            $this->selectedReminderChannels,
            $this->customReminderMessage,
            true
        );

        if ($result['success']) {
            Toast::dispatch($this, 'success', $result['message']);
            $this->dispatch('modal-close', name: 'class-reminder-modal');
            $this->remindSchedule = null;
            $this->remindScheduleId = null;
        } else {
            Toast::dispatch($this, 'error', $result['message']);
        }
    }

    public function openReminderSettingsModal(): void
    {
        $this->reminderEnabled = ClassReminderService::isEnabled();
        $this->reminderLeadTime = ClassReminderService::getLeadTimeMinutes();
        $this->reminderChannels = ClassReminderService::getConfiguredChannels();
        $this->reminderTemplate = ClassReminderService::getTemplate();

        $this->dispatch('modal-open', name: 'class-reminder-settings-modal');
    }

    public function saveReminderSettings(): void
    {
        ClassReminderService::updateSettings(
            $this->reminderEnabled,
            $this->reminderLeadTime,
            $this->reminderChannels,
            $this->reminderTemplate
        );

        Toast::dispatch($this, 'success', __('Class reminder alert settings saved successfully.'));
        $this->dispatch('modal-close', name: 'class-reminder-settings-modal');
    }

    #[Computed]
    public function facultyWorkloadRows(): array
    {
        return ClassScheduleService::getFacultyWorkloadData([
            'faculty_id' => $this->trackerFacultyId ? (int) $this->trackerFacultyId : null,
            'batch_id' => $this->trackerBatchId ? (int) $this->trackerBatchId : null,
            'course_id' => $this->trackerCourseId ? (int) $this->trackerCourseId : null,
            'start_date' => $this->trackerDateFrom ?: null,
            'end_date' => $this->trackerDateTo ?: null,
        ]);
    }

    #[Computed]
    public function currentReportData(): array
    {
        $filters = [
            'faculty_id' => $this->reportFacultyId ? (int) $this->reportFacultyId : null,
            'batch_id' => $this->reportBatchId ? (int) $this->reportBatchId : null,
            'course_id' => $this->reportCourseId ? (int) $this->reportCourseId : null,
        ];

        return match ($this->selectedReportType) {
            'faculty_workload' => ClassScheduleService::getFacultyWorkloadData($filters),
            'course_coverage' => ClassScheduleService::getCourseCoverageData($filters),
            'classroom_utilization' => ClassScheduleService::getClassroomUtilizationData($filters),
            default => [],
        };
    }

    public function exportGeneratedReport(string $format = 'xlsx')
    {
        $filters = [
            'faculty_id' => $this->reportFacultyId ? (int) $this->reportFacultyId : null,
            'batch_id' => $this->reportBatchId ? (int) $this->reportBatchId : null,
            'course_id' => $this->reportCourseId ? (int) $this->reportCourseId : null,
        ];

        return ClassScheduleService::exportReport($this->selectedReportType, $filters, $format);
    }

    public function createSingle(): void
    {
        $this->editingId = null;
        $firstBatch = $this->batches->first();
        $firstCourse = $firstBatch && $firstBatch->courses->isNotEmpty() ? $firstBatch->courses->first() : $this->courses->first();

        $this->form = [
            'batch_id' => $firstBatch ? (string) $firstBatch->id : '',
            'course_id' => $firstCourse ? (string) $firstCourse->id : '',
            'section_id' => '',
            'course_plan_id' => '',
            'classroom_id' => '',
            'class_type' => 'regular',
            'class_date' => now()->format('Y-m-d'),
            'day_of_week' => now()->format('l'),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'lecture_number' => '',
            'subject' => '',
            'topics' => '',
            'description' => '',
            'delivery_mode' => 'offline',
            'classroom' => '',
            'platform' => 'Zoom',
            'meeting_link' => '',
            'meeting_id' => '',
            'meeting_password' => '',
            'platform_other' => '',
            'selected_faculty_ids' => [],
            'status' => 'scheduled',
        ];

        $this->resetValidation();
        $this->dispatch('modal-open', name: 'class-form-modal');
    }

    public function openRecurrenceModal(): void
    {
        $firstBatch = $this->batches->first();
        $firstCourse = $firstBatch && $firstBatch->courses->isNotEmpty() ? $firstBatch->courses->first() : $this->courses->first();

        $this->recurrenceForm = [
            'batch_id' => $firstBatch ? (string) $firstBatch->id : '',
            'course_id' => $firstCourse ? (string) $firstCourse->id : '',
            'section_id' => '',
            'course_plan_id' => '',
            'classroom_id' => '',
            'class_type' => 'regular',
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addWeeks(4)->format('Y-m-d'),
            'days_of_week' => ['Monday', 'Wednesday', 'Friday'],
            'start_time' => '10:00',
            'end_time' => '12:00',
            'start_lecture_no' => 1,
            'subject' => '',
            'topics' => '',
            'description' => '',
            'delivery_mode' => 'offline',
            'classroom' => '',
            'platform' => 'Zoom',
            'meeting_link' => '',
            'meeting_id' => '',
            'meeting_password' => '',
            'selected_faculty_ids' => [],
        ];

        $this->resetValidation();
        $this->dispatch('modal-open', name: 'recurrence-form-modal');
    }

    public function edit(int $id): void
    {
        $schedule = ClassSchedule::with('faculties')->findOrFail($id);
        $this->editingId = $schedule->id;

        $this->form = [
            'batch_id' => (string) $schedule->batch_id,
            'course_id' => (string) $schedule->course_id,
            'section_id' => $schedule->section_id ? (string) $schedule->section_id : '',
            'course_plan_id' => $schedule->course_plan_id ? (string) $schedule->course_plan_id : '',
            'classroom_id' => $schedule->classroom_id ? (string) $schedule->classroom_id : '',
            'class_type' => $schedule->class_type,
            'class_date' => $schedule->class_date->format('Y-m-d'),
            'day_of_week' => $schedule->day_of_week,
            'start_time' => Carbon::parse($schedule->start_time)->format('H:i'),
            'end_time' => Carbon::parse($schedule->end_time)->format('H:i'),
            'lecture_number' => $schedule->lecture_number ?? '',
            'subject' => $schedule->subject ?? '',
            'topics' => $schedule->topics ?? '',
            'description' => $schedule->description ?? '',
            'delivery_mode' => $schedule->delivery_mode,
            'classroom' => $schedule->classroom ?? '',
            'platform' => $schedule->platform ?? 'Zoom',
            'meeting_link' => $schedule->meeting_link ?? '',
            'meeting_id' => $schedule->meeting_id ?? '',
            'meeting_password' => $schedule->meeting_password ?? '',
            'platform_other' => $schedule->platform_other ?? '',
            'selected_faculty_ids' => $schedule->faculties->pluck('id')->map(fn ($i) => (string) $i)->all(),
            'status' => $schedule->status,
        ];

        $this->resetValidation();
        $this->dispatch('modal-open', name: 'class-form-modal');
    }

    public function saveSingle(): void
    {
        $this->validate([
            'form.batch_id' => 'required|exists:batches,id',
            'form.course_id' => 'required|exists:courses,id',
            'form.section_id' => 'nullable|exists:sections,id',
            'form.course_plan_id' => 'nullable|exists:course_plans,id',
            'form.classroom_id' => 'nullable|exists:classrooms,id',
            'form.class_type' => 'required|in:regular,optional,extra,revision,guest_lecture',
            'form.class_date' => 'required|date',
            'form.start_time' => 'required',
            'form.end_time' => 'required',
            'form.subject' => 'nullable|string|max:191',
            'form.topics' => 'nullable|string',
            'form.description' => 'nullable|string',
            'form.lecture_number' => 'nullable|string|max:50',
            'form.delivery_mode' => 'required|in:offline,online,hybrid',
            'form.classroom' => 'nullable|string|max:100',
            'form.platform' => 'nullable|string|max:50',
            'form.meeting_link' => 'nullable|url',
            'form.meeting_id' => 'nullable|string|max:100',
            'form.meeting_password' => 'nullable|string|max:100',
            'form.platform_other' => 'nullable|string|max:100',
            'form.selected_faculty_ids' => 'nullable|array',
            'form.status' => 'required|in:scheduled,in_progress,completed,rescheduled,cancelled',
        ]);

        try {
            $facultyIds = array_map('intval', $this->form['selected_faculty_ids'] ?? []);

            // Check classroom conflict if physical room selected
            if (! empty($this->form['classroom_id'])) {
                $roomConflict = ClassScheduleService::checkClassroomConflict(
                    (int) $this->form['classroom_id'],
                    $this->form['class_date'],
                    $this->form['start_time'].':00',
                    $this->form['end_time'].':00',
                    $this->editingId
                );

                if ($roomConflict) {
                    Toast::dispatch($this, 'error', __("Classroom booking conflict: Room ':room' is already scheduled for ':subj' on :date (:time).", [
                        'room' => $roomConflict->classroom_display,
                        'subj' => $roomConflict->subject,
                        'date' => $roomConflict->class_date->format('d M Y'),
                        'time' => $roomConflict->formatted_time,
                    ]));

                    return;
                }
            }

            // Check faculty conflicts
            if (! empty($facultyIds)) {
                $facultyConflicts = ClassScheduleService::checkFacultyConflicts(
                    $facultyIds,
                    $this->form['class_date'],
                    $this->form['start_time'].':00',
                    $this->form['end_time'].':00',
                    $this->editingId
                );

                if ($facultyConflicts->isNotEmpty()) {
                    $c = $facultyConflicts->first();
                    $facultyNames = $c->faculties->pluck('name')->join(', ');
                    Toast::dispatch($this, 'error', __("Faculty schedule conflict: ':faculty' is already assigned to ':subj' on :date (:time).", [
                        'faculty' => $facultyNames,
                        'subj' => $c->subject,
                        'date' => $c->class_date->format('d M Y'),
                        'time' => $c->formatted_time,
                    ]));

                    return;
                }
            }

            $data = [
                'batch_id' => (int) $this->form['batch_id'],
                'course_id' => (int) $this->form['course_id'],
                'section_id' => ! empty($this->form['section_id']) ? (int) $this->form['section_id'] : null,
                'course_plan_id' => ! empty($this->form['course_plan_id']) ? (int) $this->form['course_plan_id'] : null,
                'classroom_id' => ! empty($this->form['classroom_id']) ? (int) $this->form['classroom_id'] : null,
                'class_type' => $this->form['class_type'],
                'class_date' => $this->form['class_date'],
                'day_of_week' => Carbon::parse($this->form['class_date'])->format('l'),
                'start_time' => $this->form['start_time'],
                'end_time' => $this->form['end_time'],
                'lecture_number' => $this->form['lecture_number'] ?: null,
                'subject' => $this->form['subject'] ?: null,
                'topics' => $this->form['topics'] ?: null,
                'description' => $this->form['description'] ?: null,
                'delivery_mode' => $this->form['delivery_mode'],
                'classroom' => $this->form['classroom'] ?: null,
                'platform' => $this->form['platform'] ?: null,
                'meeting_link' => $this->form['meeting_link'] ?: null,
                'meeting_id' => $this->form['meeting_id'] ?: null,
                'meeting_password' => $this->form['meeting_password'] ?: null,
                'platform_other' => $this->form['platform_other'] ?: null,
                'status' => $this->form['status'],
            ];

            if ($this->editingId) {
                $schedule = ClassSchedule::findOrFail($this->editingId);
                ClassScheduleService::updateSchedule($schedule, $data, $facultyIds);
                Toast::dispatch($this, 'success', __('Class schedule updated successfully.'));
            } else {
                ClassScheduleService::createSingle($data, $facultyIds);
                Toast::dispatch($this, 'success', __('Class scheduled successfully.'));
            }

            $this->dispatch('modal-close', name: 'class-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to schedule class: :err', ['err' => $e->getMessage()]));
        }
    }

    public function generateRecurrence(): void
    {
        $this->validate([
            'recurrenceForm.batch_id' => 'required|exists:batches,id',
            'recurrenceForm.course_id' => 'required|exists:courses,id',
            'recurrenceForm.section_id' => 'nullable|exists:sections,id',
            'recurrenceForm.classroom_id' => 'nullable|exists:classrooms,id',
            'recurrenceForm.class_type' => 'required|in:regular,optional,extra,revision,guest_lecture',
            'recurrenceForm.start_date' => 'required|date',
            'recurrenceForm.end_date' => 'required|date|after_or_equal:recurrenceForm.start_date',
            'recurrenceForm.days_of_week' => 'required|array|min:1',
            'recurrenceForm.start_time' => 'required',
            'recurrenceForm.end_time' => 'required',
            'recurrenceForm.subject' => 'nullable|string|max:191',
            'recurrenceForm.delivery_mode' => 'required|in:offline,online,hybrid',
        ]);

        try {
            $baseData = [
                'batch_id' => (int) $this->recurrenceForm['batch_id'],
                'course_id' => (int) $this->recurrenceForm['course_id'],
                'section_id' => ! empty($this->recurrenceForm['section_id']) ? (int) $this->recurrenceForm['section_id'] : null,
                'course_plan_id' => ! empty($this->recurrenceForm['course_plan_id']) ? (int) $this->recurrenceForm['course_plan_id'] : null,
                'classroom_id' => ! empty($this->recurrenceForm['classroom_id']) ? (int) $this->recurrenceForm['classroom_id'] : null,
                'class_type' => $this->recurrenceForm['class_type'],
                'start_time' => $this->recurrenceForm['start_time'],
                'end_time' => $this->recurrenceForm['end_time'],
                'subject' => $this->recurrenceForm['subject'] ?: null,
                'topics' => $this->recurrenceForm['topics'] ?: null,
                'description' => $this->recurrenceForm['description'] ?: null,
                'delivery_mode' => $this->recurrenceForm['delivery_mode'],
                'classroom' => $this->recurrenceForm['classroom'] ?: null,
                'platform' => $this->recurrenceForm['platform'] ?: null,
                'meeting_link' => $this->recurrenceForm['meeting_link'] ?: null,
                'meeting_id' => $this->recurrenceForm['meeting_id'] ?: null,
                'meeting_password' => $this->recurrenceForm['meeting_password'] ?: null,
            ];

            $facultyIds = array_map('intval', $this->recurrenceForm['selected_faculty_ids'] ?? []);

            $config = [
                'start_date' => $this->recurrenceForm['start_date'],
                'end_date' => $this->recurrenceForm['end_date'],
                'days_of_week' => $this->recurrenceForm['days_of_week'],
                'start_lecture_no' => $this->recurrenceForm['start_lecture_no'],
            ];

            $result = ClassScheduleService::generateRecurring($baseData, $facultyIds, $config);

            Toast::dispatch($this, 'success', __('Successfully generated :count recurring class instances.', ['count' => $result['count']]));
            $this->dispatch('modal-close', name: 'recurrence-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to generate recurring routine: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openQuickFacultyModal(): void
    {
        $this->quickFacultyPhoto = null;
        $this->quickFacultyCv = null;
        $this->removeQuickFacultyPhoto = false;
        $this->removeQuickFacultyCv = false;
        $this->quickFacultyDepartmentSelect = 'General Studies';
        $this->quickFacultyCustomDepartment = '';
        $this->quickFacultyForm = [
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
            'subjects_raw' => $this->form['subject'] ?: '',
        ];
        $this->resetValidation();
        $this->dispatch('modal-open', name: 'quick-faculty-modal');
    }

    public function saveQuickFaculty(): void
    {
        $this->validate([
            'quickFacultyForm.name' => 'required|string|max:191',
            'quickFacultyForm.email' => 'nullable|email|max:191',
            'quickFacultyForm.phone' => 'nullable|string|max:50',
            'quickFacultyForm.designation' => 'nullable|string|max:100',
            'quickFacultyDepartmentSelect' => 'required|string',
            'quickFacultyCustomDepartment' => 'required_if:quickFacultyDepartmentSelect,Custom|nullable|string|max:100',
            'quickFacultyForm.partner_institute_id' => 'nullable|exists:partner_institutes,id',
            'quickFacultyForm.faculty_type' => 'required|in:regular,guest,visiting,contractual,adjunct',
            'quickFacultyPhoto' => 'nullable|image|max:5120',
            'quickFacultyCv' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'quickFacultyForm.specialization' => 'nullable|string|max:191',
            'quickFacultyForm.qualification' => 'nullable|string|max:191',
            'quickFacultyForm.bio' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $resolvedDept = $this->quickFacultyDepartmentSelect === 'Custom'
                ? (trim($this->quickFacultyCustomDepartment) ?: 'General Studies')
                : ($this->quickFacultyDepartmentSelect ?: 'General Studies');

            $subjects = array_values(array_filter(array_map('trim', explode(',', $this->quickFacultyForm['subjects_raw'] ?? ''))));

            $payload = [
                'name' => trim($this->quickFacultyForm['name']),
                'email' => ! empty($this->quickFacultyForm['email']) ? trim($this->quickFacultyForm['email']) : null,
                'phone' => ! empty($this->quickFacultyForm['phone']) ? trim($this->quickFacultyForm['phone']) : null,
                'designation' => ! empty($this->quickFacultyForm['designation']) ? trim($this->quickFacultyForm['designation']) : 'Faculty Member',
                'department' => $resolvedDept ?: 'General Studies',
                'partner_institute_id' => ! empty($this->quickFacultyForm['partner_institute_id']) ? (int) $this->quickFacultyForm['partner_institute_id'] : null,
                'faculty_type' => $this->quickFacultyForm['faculty_type'] ?? 'regular',
                'specialization' => ! empty($this->quickFacultyForm['specialization']) ? trim($this->quickFacultyForm['specialization']) : null,
                'qualification' => ! empty($this->quickFacultyForm['qualification']) ? trim($this->quickFacultyForm['qualification']) : null,
                'bio' => ! empty($this->quickFacultyForm['bio']) ? trim($this->quickFacultyForm['bio']) : null,
                'subjects' => $subjects,
                'status' => 'active',
            ];

            if ($this->quickFacultyPhoto && ! $this->removeQuickFacultyPhoto) {
                $photoName = $this->generateFileName('faculty_photo', $this->quickFacultyPhoto, auth()->id() ?? 1);
                $photoPath = $this->quickFacultyPhoto->storeAs('faculties/photos', $photoName, 'public');
                $payload['photo_path'] = $photoPath;
                $payload['avatar_path'] = $photoPath;
            }

            if ($this->quickFacultyCv && ! $this->removeQuickFacultyCv) {
                $cvName = $this->generateFileName('faculty_cv', $this->quickFacultyCv, auth()->id() ?? 1);
                $cvPath = $this->quickFacultyCv->storeAs('faculties/cvs', $cvName, 'public');
                $payload['cv_path'] = $cvPath;
            }

            $faculty = Faculty::create($payload);

            AuditLogService::log(
                event: 'faculty_created_quick',
                description: "Quick created faculty from Routine scheduler: {$faculty->name} ({$faculty->faculty_code})",
                auditable: $faculty,
                newValues: $faculty->toArray(),
                userId: auth()->id()
            );

            // Auto-select in current form
            $this->form['selected_faculty_ids'][] = (string) $faculty->id;
            $this->recurrenceForm['selected_faculty_ids'][] = (string) $faculty->id;

            DB::commit();

            Toast::dispatch($this, 'success', __('Faculty :name created and selected.', ['name' => $faculty->name]));
            $this->dispatch('modal-close', name: 'quick-faculty-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to create faculty: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openRecordingModal(int $id): void
    {
        $schedule = ClassSchedule::findOrFail($id);
        $this->recordingScheduleId = $schedule->id;
        $this->recordingSchedule = $schedule;
        $this->recordingForm = [
            'recording_url' => $schedule->recording_url ?? '',
            'recording_password' => $schedule->recording_password ?? '',
            'recording_notes' => $schedule->recording_notes ?? '',
            'recording_status' => $schedule->recording_status === 'available' ? 'available' : 'available',
        ];
        $this->recordingUploadFile = null;
        $this->dispatch('modal-open', name: 'class-recording-modal');
    }

    public function saveRecording(): void
    {
        if (! $this->recordingScheduleId) {
            return;
        }

        $this->validate([
            'recordingForm.recording_url' => 'nullable|url',
            'recordingForm.recording_password' => 'nullable|string|max:100',
            'recordingForm.recording_notes' => 'nullable|string',
            'recordingUploadFile' => 'nullable|file|mimes:mp4,webm,mp3,wav,mkv|max:512000',
        ]);

        try {
            $filePath = null;
            if ($this->recordingUploadFile) {
                $filePath = $this->recordingUploadFile->store('class-recordings', 'public');
            }

            $schedule = ClassSchedule::findOrFail($this->recordingScheduleId);

            $data = [
                'recording_url' => $this->recordingForm['recording_url'] ?: null,
                'recording_password' => $this->recordingForm['recording_password'] ?: null,
                'recording_notes' => $this->recordingForm['recording_notes'] ?: null,
                'recording_status' => ! empty($this->recordingForm['recording_url']) || $filePath ? 'available' : 'not_available',
            ];

            if ($filePath) {
                $data['recording_file_path'] = $filePath;
            }

            ClassScheduleService::updateRecording($schedule, $data);

            Toast::dispatch($this, 'success', __('Class recording updated and made available to enrolled students.'));
            $this->dispatch('modal-close', name: 'class-recording-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save recording: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openRescheduleModal(int $id): void
    {
        $schedule = ClassSchedule::findOrFail($id);
        $this->rescheduleId = $schedule->id;
        $this->reschedulingSchedule = $schedule;
        $this->rescheduleForm = [
            'new_date' => $schedule->class_date->format('Y-m-d'),
            'start_time' => Carbon::parse($schedule->start_time)->format('H:i'),
            'end_time' => Carbon::parse($schedule->end_time)->format('H:i'),
            'reason' => '',
        ];
        $this->dispatch('modal-open', name: 'class-reschedule-modal');
    }

    public function saveReschedule(): void
    {
        if (! $this->rescheduleId) {
            return;
        }

        $this->validate([
            'rescheduleForm.new_date' => 'required|date',
            'rescheduleForm.start_time' => 'required',
            'rescheduleForm.end_time' => 'required',
            'rescheduleForm.reason' => 'nullable|string|max:500',
        ]);

        try {
            $schedule = ClassSchedule::findOrFail($this->rescheduleId);
            ClassScheduleService::reschedule(
                $schedule,
                $this->rescheduleForm['new_date'],
                $this->rescheduleForm['start_time'],
                $this->rescheduleForm['end_time'],
                $this->rescheduleForm['reason'] ?: null
            );

            Toast::dispatch($this, 'info', __('Class rescheduled successfully.'));
            $this->dispatch('modal-close', name: 'class-reschedule-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to reschedule: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openCancelModal(int $id): void
    {
        $this->cancelId = $id;
        $this->cancellingSchedule = ClassSchedule::findOrFail($id);
        $this->cancelReason = '';
        $this->dispatch('modal-open', name: 'class-cancel-modal');
    }

    public function confirmCancelClass(): void
    {
        if (! $this->cancelId) {
            return;
        }

        $this->validate([
            'cancelReason' => 'required|string|max:500',
        ]);

        try {
            $schedule = ClassSchedule::findOrFail($this->cancelId);
            ClassScheduleService::cancel($schedule, $this->cancelReason);

            Toast::dispatch($this, 'warning', __('Class marked as cancelled.'));
            $this->dispatch('modal-close', name: 'class-cancel-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to cancel class: :err', ['err' => $e->getMessage()]));
        }
    }

    public function markCompleted(int $id): void
    {
        try {
            $schedule = ClassSchedule::findOrFail($id);
            ClassScheduleService::markCompleted($schedule);

            Toast::dispatch($this, 'success', __('Class marked as completed.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to complete class.'));
        }
    }

    public function showDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingSchedule = ClassSchedule::with(['batch', 'course', 'section', 'coursePlan', 'faculties'])
            ->findOrFail($id);

        $this->dispatch('modal-open', name: 'class-view-modal');
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->deletingSchedule = ClassSchedule::findOrFail($id);
        $this->dispatch('modal-open', name: 'class-delete-modal');
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        try {
            $schedule = ClassSchedule::findOrFail($this->deleteId);
            $code = $schedule->schedule_code;
            $schedule->delete();

            AuditLogService::log(
                event: 'class_schedule_deleted',
                description: "Deleted class schedule {$code}",
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Class schedule :code deleted.', ['code' => $code]));
            $this->deleteId = null;
            $this->dispatch('modal-close', name: 'class-delete-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete class: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'csv')
    {
        $schedules = ClassSchedule::query()
            ->with(['batch', 'course', 'section', 'faculties'])
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->when($this->sectionFilter, fn ($q) => $q->where('section_id', (int) $this->sectionFilter))
            ->when($this->typeFilter, fn ($q) => $q->where('class_type', $this->typeFilter))
            ->when($this->modeFilter, fn ($q) => $q->where('delivery_mode', $this->modeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy('class_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return ClassScheduleService::export($schedules, $format);
    }

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $headers = [
            'Date',
            'Batch',
            'Course',
            'Section',
            'Type',
            'Subject',
            'Topics',
            'Start Time',
            'End Time',
            'Faculty',
            'Mode',
            'Classroom',
            'Platform',
            'Meeting Link',
        ];

        $sampleRow = [
            now()->format('Y-m-d'),
            $this->batches->first()?->code ?? 'BATCH-2026',
            $this->courses->first()?->code ?? 'COURSE-001',
            '',
            'regular',
            'Indian Polity',
            'Fundamental Rights & Duties',
            '10:00 AM',
            '12:00 PM',
            'Dr. B. R. Sharma',
            'offline',
            'Hall A',
            '',
            '',
        ];

        return StudentImportExportService::exportCsv('class-routine-import-template', $headers, [$sampleRow]);
    }

    public function importSchedules(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,xlsx,xls,txt|max:10240',
        ]);

        $path = $this->importFile->getRealPath();
        $res = ClassScheduleService::import($path);

        $this->importErrors = $res['errors'];

        if ($res['imported'] > 0) {
            Toast::dispatch($this, 'success', __('Successfully imported :count classes.', ['count' => $res['imported']]));
            if (empty($this->importErrors)) {
                $this->dispatch('modal-close', name: 'class-import-modal');
            }
        } else {
            Toast::dispatch($this, 'error', __('Import completed with issues. Check errors list.'));
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'batchFilter', 'courseFilter', 'sectionFilter', 'typeFilter', 'modeFilter', 'facultyFilter', 'statusFilter', 'dateFilter', 'dateFrom', 'dateTo']);
        $this->sortField = 'class_date';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="calendar" class="w-7 h-7 text-primary" />
                {{ __('Classes & Lecture Schedule Routine') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Dynamic routine management for batches, courses, and sections. Supports online platforms, recurrence generator, and recording links.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- View Mode Switcher -->
            <div class="inline-flex rounded-lg border border-border bg-muted/40 p-0.5">
                <button
                    type="button"
                    wire:click="$set('activeView', 'list')"
                    class="rounded-md px-2.5 py-1 text-xs font-medium cursor-pointer transition-colors {{ $activeView === 'list' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="layers" class="h-3.5 w-3.5 inline mr-1" />
                    {{ __('List View') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('activeView', 'calendar')"
                    class="rounded-md px-2.5 py-1 text-xs font-medium cursor-pointer transition-colors {{ $activeView === 'calendar' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="calendar-days" class="h-3.5 w-3.5 inline mr-1" />
                    {{ __('Weekly Routine') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('activeView', 'faculty_tracker')"
                    class="rounded-md px-2.5 py-1 text-xs font-medium cursor-pointer transition-colors {{ $activeView === 'faculty_tracker' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="user-check" class="h-3.5 w-3.5 inline mr-1 text-primary" />
                    {{ __('Faculty Tracker') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('activeView', 'reports')"
                    class="rounded-md px-2.5 py-1 text-xs font-medium cursor-pointer transition-colors {{ $activeView === 'reports' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="bar-chart-3" class="h-3.5 w-3.5 inline mr-1 text-purple-600" />
                    {{ __('Routine Reports') }}
                </button>
            </div>

            <!-- Reminder Settings Trigger -->
            <x-ui.button variant="outline" size="sm" wire:click="openReminderSettingsModal" class="h-9 gap-1.5 cursor-pointer border-amber-500/30 text-amber-600 dark:text-amber-400 hover:bg-amber-500/10" title="{{ __('Configure automated & manual scheduled classes reminder alert system') }}">
                <x-icon name="bell" class="h-4 w-4"/>
                <span>{{ __('Reminder Alerts') }}</span>
            </x-ui.button>

            <!-- Import Modal Trigger -->
            @can('classes.import')
                <x-ui.button variant="outline" size="sm" wire:click="$dispatch('modal-open', { name: 'class-import-modal' })" class="h-9 gap-1.5 cursor-pointer">
                    <x-icon name="upload" class="h-4 w-4"/>
                    <span>{{ __('Import') }}</span>
                </x-ui.button>
            @endcan

            <!-- Export Dropdown -->
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

            @can('classes.create')
                <!-- Recurrence Batch Generator -->
                <x-ui.button variant="outline" wire:click="openRecurrenceModal" size="sm" class="h-9 gap-1.5 border-primary/40 text-primary hover:bg-primary/10 cursor-pointer">
                    <x-icon name="repeat" class="h-4 w-4"/>
                    <span>{{ __('Recurring Routine') }}</span>
                </x-ui.button>

                <!-- Single Class -->
                <x-ui.button wire:click="createSingle" size="sm" class="h-9 gap-1.5 cursor-pointer">
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('Schedule Class') }}</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Statistics KPIs -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <p class="text-xs font-medium text-muted-foreground">{{ __('Total Classes') }}</p>
            <p class="text-xl font-bold text-foreground mt-0.5">{{ number_format($this->stats['total']) }}</p>
            <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('All records') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <p class="text-xs font-medium text-blue-600 dark:text-blue-400">{{ __('Today Scheduled') }}</p>
            <p class="text-xl font-bold text-blue-600 dark:text-blue-400 mt-0.5">{{ number_format($this->stats['today']) }}</p>
            <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('Active today') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <p class="text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ __('Conducted Classes') }}</p>
            <p class="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-0.5">{{ number_format($this->stats['completed']) }}</p>
            <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('Completed sessions') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ __('Upcoming Scheduled') }}</p>
            <p class="text-xl font-bold text-indigo-600 dark:text-indigo-400 mt-0.5">{{ number_format($this->stats['scheduled']) }}</p>
            <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('Active in pipeline') }}</p>
        </div>

        <div class="rounded-xl border border-rose-200 dark:border-rose-900/50 bg-rose-50/30 dark:bg-rose-950/20 p-3 shadow-xs">
            <p class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ __('Cancelled Classes') }}</p>
            <p class="text-xl font-bold text-rose-600 dark:text-rose-400 mt-0.5">{{ number_format($this->stats['cancelled']) }}</p>
            <p class="text-[10px] text-rose-500/80 mt-0.5">{{ __('Excluded from syllabus') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <p class="text-xs font-medium text-violet-600 dark:text-violet-400">{{ __('Recordings Available') }}</p>
            <p class="text-xl font-bold text-violet-600 dark:text-violet-400 mt-0.5">{{ number_format($this->stats['with_recordings']) }}</p>
            <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('Student portal ready') }}</p>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
            <div class="relative sm:col-span-2">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search subject, topic, room, teacher...') }}"
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
                    wire:model.live="sectionFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Sections') }}</option>
                    @foreach ($this->filterSections as $sec)
                        <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select
                    wire:model.live="dateFilter"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('Any Time') }}</option>
                    <option value="today">{{ __('Today') }}</option>
                    <option value="tomorrow">{{ __('Tomorrow') }}</option>
                    <option value="this_week">{{ __('This Week') }}</option>
                    <option value="upcoming">{{ __('Upcoming') }}</option>
                    <option value="past">{{ __('Past Concluded') }}</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4 pt-1 border-t border-border/50 text-xs">
            <div>
                <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Class Category') }}</label>
                <select wire:model.live="typeFilter" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="">{{ __('All Types (Regular & Optional)') }}</option>
                    <option value="regular">{{ __('Regular Classes') }}</option>
                    <option value="optional">{{ __('Optional Classes') }}</option>
                    <option value="extra">{{ __('Extra Classes') }}</option>
                    <option value="revision">{{ __('Revision Classes') }}</option>
                    <option value="guest_lecture">{{ __('Guest Lectures') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Conduct Mode') }}</label>
                <select wire:model.live="modeFilter" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="">{{ __('All Modes') }}</option>
                    <option value="offline">{{ __('Offline (Classroom)') }}</option>
                    <option value="online">{{ __('Online (Zoom/Meet/Teams)') }}</option>
                    <option value="hybrid">{{ __('Hybrid Mode') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Faculty Member') }}</label>
                <select wire:model.live="facultyFilter" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="">{{ __('All Teachers') }}</option>
                    @foreach ($this->allFaculties as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Status') }}</label>
                <div class="flex items-center gap-2">
                    <select wire:model.live="statusFilter" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="">{{ __('All Statuses') }}</option>
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="in_progress">{{ __('In Progress') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="rescheduled">{{ __('Rescheduled') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </select>

                    @if ($search || $batchFilter || $courseFilter || $sectionFilter || $typeFilter || $modeFilter || $facultyFilter || $statusFilter || $dateFilter)
                        <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="h-8 px-2 text-xs shrink-0 cursor-pointer" title="{{ __('Reset Filters') }}">
                            <x-icon name="rotate-ccw" class="h-3.5 w-3.5"/>
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Active View: List View -->
    @if ($activeView === 'list')
        <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-foreground">
                    <thead class="bg-muted/50 border-b border-border text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3">{{ __('Date & Day') }}</th>
                            <th class="px-4 py-3">{{ __('Time') }}</th>
                            <th class="px-4 py-3">{{ __('Subject & Topic') }}</th>
                            <th class="px-4 py-3">{{ __('Batch & Course') }}</th>
                            <th class="px-4 py-3">{{ __('Faculty') }}</th>
                            <th class="px-4 py-3">{{ __('Mode / Venue') }}</th>
                            <th class="px-4 py-3">{{ __('Recordings') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->schedules as $schedule)
                            <tr class="hover:bg-muted/30 transition-colors {{ $schedule->isHappeningNow() ? 'bg-primary/5' : '' }}">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-foreground flex items-center gap-1.5">
                                        <span>{{ $schedule->class_date->format('d M Y') }}</span>
                                        @if ($schedule->class_date->isToday())
                                            <span class="rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-1.5 py-0.2 text-[10px] font-bold uppercase">{{ __('Today') }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-muted-foreground">{{ $schedule->day_of_week }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-medium">
                                    <div class="flex items-center gap-1 text-foreground">
                                        <x-icon name="clock" class="h-3.5 w-3.5 text-muted-foreground"/>
                                        <span>{{ $schedule->formatted_time }}</span>
                                    </div>
                                    <div class="text-[11px] text-muted-foreground">
                                        {{ $schedule->duration_minutes }} {{ __('mins') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 max-w-xs">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-semibold text-foreground">{{ $schedule->subject ?: __('Subject TBD') }}</span>
                                        @if ($schedule->lecture_number)
                                            <span class="rounded-md bg-secondary px-1.5 py-0.5 text-[10px] font-mono text-muted-foreground">
                                                {{ $schedule->lecture_number }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-muted-foreground line-clamp-1 mt-0.5">
                                        {{ $schedule->topics ?: __('No topics defined') }}
                                    </div>
                                    <div class="mt-1 flex items-center gap-1">
                                        <span class="inline-flex items-center rounded-md text-[10px] font-medium px-1.5 py-0.5 {{ $schedule->class_type === 'regular' ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400' : 'bg-purple-500/10 text-purple-600 dark:text-purple-400' }}">
                                            {{ ucfirst($schedule->class_type) }}
                                        </span>
                                        @if ($schedule->is_recurring)
                                            <span class="inline-flex items-center gap-0.5 rounded-md bg-secondary text-[10px] text-muted-foreground px-1 py-0.2" title="{{ $schedule->recurrence_rule }}">
                                                <x-icon name="repeat" class="h-2.5 w-2.5"/>
                                                <span>{{ __('Recurring') }}</span>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div class="font-medium text-foreground">{{ $schedule->course?->name }}</div>
                                    <div class="text-muted-foreground">
                                        {{ $schedule->batch?->name }}
                                        <span class="text-primary font-medium">
                                            {{ $schedule->section ? ' &bull; Section '.$schedule->section->name : ' &bull; All Sections' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($schedule->faculties->isNotEmpty())
                                        <div class="flex items-center gap-1.5">
                                            <div class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-[10px]">
                                                {{ $schedule->faculties->first()->initials }}
                                            </div>
                                            <span class="font-medium text-foreground">{{ $schedule->faculties->pluck('name')->join(', ') }}</span>
                                        </div>
                                    @else
                                        <span class="text-muted-foreground italic">{{ __('Unassigned') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($schedule->delivery_mode === 'online')
                                        <div class="flex flex-col gap-1">
                                            <span class="inline-flex items-center gap-1 rounded-md bg-violet-500/10 text-violet-600 dark:text-violet-400 px-2 py-0.5 text-xs font-semibold w-fit">
                                                <x-icon name="video" class="h-3 w-3"/>
                                                {{ $schedule->platform ?: 'Online' }}
                                            </span>
                                            @if ($schedule->meeting_link)
                                                <a href="{{ $schedule->meeting_link }}" target="_blank" class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline">
                                                    <x-icon name="external-link" class="h-3 w-3"/>
                                                    <span>{{ __('Meeting Link') }}</span>
                                                </a>
                                            @endif
                                        </div>
                                    @else
                                        <div class="flex flex-col gap-0.5">
                                            <span class="inline-flex items-center gap-1 rounded-md bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 px-2 py-0.5 text-xs font-semibold w-fit">
                                                <x-icon name="map-pin" class="h-3 w-3"/>
                                                {{ __('Offline') }}
                                            </span>
                                            <span class="text-[11px] text-muted-foreground font-medium" title="{{ $schedule->classroom_display }}">
                                                {{ $schedule->classroom_display }}
                                            </span>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($schedule->hasRecording())
                                        <button
                                            type="button"
                                            wire:click="openRecordingModal({{ $schedule->id }})"
                                            class="inline-flex items-center gap-1 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-2 py-0.5 text-xs font-semibold hover:bg-emerald-500/20 cursor-pointer"
                                        >
                                            <x-icon name="play-circle" class="h-3.5 w-3.5"/>
                                            <span>{{ __('Available') }}</span>
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="openRecordingModal({{ $schedule->id }})"
                                            class="inline-flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground cursor-pointer"
                                        >
                                            <x-icon name="plus" class="h-3 w-3"/>
                                            <span>{{ __('Add Link') }}</span>
                                        </button>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($schedule->status === 'completed')
                                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-2 py-0.5 font-medium">
                                            {{ __('Completed') }}
                                        </span>
                                    @elseif ($schedule->status === 'in_progress')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 px-2 py-0.5 font-medium animate-pulse">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                            {{ __('Live Now') }}
                                        </span>
                                    @elseif ($schedule->status === 'rescheduled')
                                        <span class="inline-flex items-center rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400 px-2 py-0.5 font-medium" title="{{ $schedule->rescheduled_reason }}">
                                            {{ __('Rescheduled') }}
                                        </span>
                                    @elseif ($schedule->status === 'cancelled')
                                        <span class="inline-flex items-center rounded-full bg-destructive/10 text-destructive px-2 py-0.5 font-medium" title="{{ $schedule->cancellation_reason }}">
                                            {{ __('Cancelled') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-400 px-2 py-0.5 font-medium">
                                            {{ __('Scheduled') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            type="button"
                                            wire:click="showDetails({{ $schedule->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-foreground rounded-md hover:bg-secondary cursor-pointer"
                                            title="{{ __('View Details') }}"
                                        >
                                            <x-icon name="eye" class="h-4 w-4"/>
                                        </button>

                                        @can('classes.remind')
                                            @if (! $schedule->isCancelled())
                                                <button
                                                    type="button"
                                                    wire:click="openReminderModal({{ $schedule->id }})"
                                                    class="p-1.5 {{ $schedule->reminder_sent_at ? 'text-amber-500 hover:text-amber-600' : 'text-muted-foreground hover:text-amber-500' }} rounded-md hover:bg-secondary cursor-pointer"
                                                    title="{{ $schedule->reminder_sent_at ? __('Reminder sent :time (:count times). Click to send alert', ['time' => $schedule->reminder_sent_at->diffForHumans(), 'count' => $schedule->reminder_count]) : __('Send Reminder Alert to Enrolled Students') }}"
                                                >
                                                    <x-icon name="bell" class="h-4 w-4"/>
                                                </button>
                                            @endif
                                        @endcan

                                        @can('classes.edit')
                                            <button
                                                type="button"
                                                wire:click="edit({{ $schedule->id }})"
                                                class="p-1.5 text-muted-foreground hover:text-primary rounded-md hover:bg-secondary cursor-pointer"
                                                title="{{ __('Edit Schedule') }}"
                                            >
                                                <x-icon name="pencil" class="h-4 w-4"/>
                                            </button>

                                            <button
                                                type="button"
                                                wire:click="openRescheduleModal({{ $schedule->id }})"
                                                class="p-1.5 text-muted-foreground hover:text-amber-500 rounded-md hover:bg-secondary cursor-pointer"
                                                title="{{ __('Reschedule Date/Time') }}"
                                            >
                                                <x-icon name="clock" class="h-4 w-4"/>
                                            </button>

                                            @if ($schedule->status !== 'completed' && $schedule->status !== 'cancelled')
                                                <button
                                                    type="button"
                                                    wire:click="markCompleted({{ $schedule->id }})"
                                                    class="p-1.5 text-muted-foreground hover:text-emerald-500 rounded-md hover:bg-secondary cursor-pointer"
                                                    title="{{ __('Mark Concluded') }}"
                                                >
                                                    <x-icon name="check" class="h-4 w-4"/>
                                                </button>
                                            @endif
                                        @endcan

                                        @can('classes.delete')
                                            @if ($schedule->status !== 'cancelled')
                                                <button
                                                    type="button"
                                                    wire:click="openCancelModal({{ $schedule->id }})"
                                                    class="p-1.5 text-muted-foreground hover:text-amber-600 rounded-md hover:bg-secondary cursor-pointer"
                                                    title="{{ __('Cancel Class') }}"
                                                >
                                                    <x-icon name="x-circle" class="h-4 w-4"/>
                                                </button>
                                            @endif

                                            <button
                                                type="button"
                                                wire:click="confirmDelete({{ $schedule->id }})"
                                                class="p-1.5 text-muted-foreground hover:text-destructive rounded-md hover:bg-secondary cursor-pointer"
                                                title="{{ __('Delete Class') }}"
                                            >
                                                <x-icon name="trash-2" class="h-4 w-4"/>
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <x-icon name="calendar" class="h-8 w-8 text-muted-foreground/50"/>
                                        <p class="font-medium">{{ __('No classes found matching the criteria.') }}</p>
                                        <p class="text-xs text-muted-foreground">{{ __('Schedule a single class or generate a recurring routine batch.') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($this->schedules->hasPages())
                <div class="border-t border-border p-4">
                    {{ $this->schedules->links() }}
                </div>
            @endif
        </div>
    @elseif ($activeView === 'calendar')
        <!-- Weekly Routine Calendar Matrix View -->
        <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-7 gap-3">
                @php
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    $currentWeekStart = Carbon::now()->startOfWeek();
                @endphp

                @foreach ($days as $dayIndex => $dayName)
                    @php
                        $dayDate = $currentWeekStart->copy()->addDays($dayIndex);
                        $dayClasses = $this->schedules->filter(fn ($s) => $s->day_of_week === $dayName);
                    @endphp
                    <div class="rounded-xl border border-border bg-card p-3 shadow-xs min-h-[280px] flex flex-col">
                        <div class="flex items-center justify-between pb-2 mb-2 border-b border-border">
                            <div>
                                <span class="font-bold text-xs uppercase tracking-wider text-foreground">{{ substr($dayName, 0, 3) }}</span>
                                <span class="text-[11px] text-muted-foreground block">{{ $dayDate->format('d M') }}</span>
                            </div>
                            <span class="rounded-full bg-secondary px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                {{ $dayClasses->count() }}
                            </span>
                        </div>

                        <div class="space-y-2 flex-1">
                            @forelse ($dayClasses as $cls)
                                <div wire:click="showDetails({{ $cls->id }})" class="p-2 rounded-lg border border-border bg-secondary/30 hover:bg-secondary cursor-pointer transition-all text-xs">
                                    <div class="font-semibold text-foreground line-clamp-1">{{ $cls->subject ?: $cls->course?->code }}</div>
                                    <div class="text-[11px] text-primary font-medium">{{ $cls->formatted_time }}</div>
                                    <div class="text-[10px] text-muted-foreground line-clamp-1 mt-0.5">
                                        {{ $cls->faculties->pluck('name')->first() ?: 'Faculty TBD' }}
                                    </div>
                                    <div class="mt-1 flex items-center justify-between text-[10px]">
                                        <span class="font-semibold {{ $cls->delivery_mode === 'online' ? 'text-violet-500' : 'text-amber-600' }}">
                                            {{ ucfirst($cls->delivery_mode) }}
                                        </span>
                                        @if ($cls->hasRecording())
                                            <x-icon name="play-circle" class="h-3 w-3 text-emerald-500"/>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="h-full flex items-center justify-center text-center p-3 text-[11px] text-muted-foreground/60">
                                    {{ __('No classes') }}
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($activeView === 'faculty_tracker')
        <!-- Faculty Classes Tracking View -->
        <div class="space-y-4">
            <!-- Tracker Filter Toolbar -->
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-sm font-bold text-foreground flex items-center gap-2">
                            <x-icon name="user-check" class="w-4 h-4 text-primary" />
                            <span>{{ __('Faculty Teaching & Classes Conducted Tracker') }}</span>
                        </h3>
                        <p class="text-xs text-muted-foreground">{{ __('Monitor teaching hours, classes assigned, completed vs upcoming routine for each faculty member.') }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button variant="outline" size="sm" wire:click="exportGeneratedReport('xlsx')" class="h-8 gap-1.5 text-xs">
                            <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600" />
                            <span>{{ __('Export Workload') }}</span>
                        </x-ui.button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Filter by Faculty') }}</label>
                        <select wire:model.live="trackerFacultyId" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none">
                            <option value="">{{ __('All Faculties') }}</option>
                            @foreach ($this->allFaculties as $f)
                                <option value="{{ $f->id }}">{{ $f->name }} ({{ $f->department }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Filter by Batch') }}</label>
                        <select wire:model.live="trackerBatchId" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none">
                            <option value="">{{ __('All Batches') }}</option>
                            @foreach ($this->batches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('From Date') }}</label>
                        <input type="date" wire:model.live="trackerDateFrom" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none" />
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('To Date') }}</label>
                        <input type="date" wire:model.live="trackerDateTo" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none" />
                    </div>
                </div>
            </div>

            <!-- Faculty Workload Table -->
            <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-foreground">
                        <thead class="bg-muted/50 border-b border-border text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">{{ __('Faculty Name') }}</th>
                                <th class="px-4 py-3">{{ __('Department & Role') }}</th>
                                <th class="px-4 py-3 text-center">{{ __('Total Assigned') }}</th>
                                <th class="px-4 py-3 text-center">{{ __('Conducted (Done)') }}</th>
                                <th class="px-4 py-3 text-center">{{ __('Upcoming') }}</th>
                                <th class="px-4 py-3 text-center">{{ __('Cancelled') }}</th>
                                <th class="px-4 py-3 text-center">{{ __('Teaching Hours') }}</th>
                                <th class="px-4 py-3 text-center">{{ __('Completion %') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($this->facultyWorkloadRows as $row)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-foreground">
                                        <div class="flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-full bg-primary/10 text-primary font-bold text-xs flex items-center justify-center">
                                                {{ mb_substr($row['name'], 0, 1) }}
                                            </div>
                                            <div>
                                                <span>{{ $row['name'] }}</span>
                                                <span class="text-[10px] text-muted-foreground font-mono block">{{ $row['faculty_code'] }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        <div class="font-medium text-foreground">{{ $row['department'] }}</div>
                                        <div class="text-[11px] text-muted-foreground">{{ $row['designation'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-center font-bold text-foreground">
                                        {{ $row['total_assigned'] }}
                                    </td>
                                    <td class="px-4 py-3 text-center font-bold text-emerald-600 dark:text-emerald-400">
                                        {{ $row['conducted'] }}
                                    </td>
                                    <td class="px-4 py-3 text-center font-bold text-blue-600 dark:text-blue-400">
                                        {{ $row['scheduled'] }}
                                    </td>
                                    <td class="px-4 py-3 text-center font-bold text-rose-500">
                                        {{ $row['cancelled'] }}
                                    </td>
                                    <td class="px-4 py-3 text-center font-semibold text-foreground">
                                        <span class="px-2 py-0.5 rounded-full bg-secondary text-xs">
                                            {{ $row['total_hours'] }} hrs
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="inline-flex items-center gap-1.5">
                                            <span class="font-bold text-xs {{ $row['completion_rate'] >= 75 ? 'text-emerald-600' : 'text-foreground' }}">
                                                {{ $row['completion_rate'] }}%
                                            </span>
                                            <div class="w-16 bg-secondary h-1.5 rounded-full overflow-hidden">
                                                <div class="bg-primary h-full rounded-full" style="width: {{ min(100, $row['completion_rate']) }}%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-10 text-center text-muted-foreground text-xs">
                                        {{ __('No faculty workload data found matching the selected filters.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @elseif ($activeView === 'reports')
        <!-- Routine Reports Generator View -->
        <div class="space-y-4">
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-bold text-foreground flex items-center gap-2">
                            <x-icon name="bar-chart-3" class="w-4 h-4 text-primary" />
                            <span>{{ __('Routine & Infrastructure Reports Generator') }}</span>
                        </h3>
                        <p class="text-xs text-muted-foreground">{{ __('Generate comprehensive administrative reports with full PDF, Excel, and CSV export.') }}</p>
                    </div>

                    <!-- Export Actions -->
                    <div class="flex items-center gap-2">
                        <x-ui.button variant="outline" size="sm" wire:click="exportGeneratedReport('xlsx')" class="h-8 gap-1.5 text-xs">
                            <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600" />
                            <span>{{ __('Export Excel') }}</span>
                        </x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="exportGeneratedReport('csv')" class="h-8 gap-1.5 text-xs">
                            <x-icon name="file-text" class="w-3.5 h-3.5 text-blue-600" />
                            <span>{{ __('Export CSV') }}</span>
                        </x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="exportGeneratedReport('pdf')" class="h-8 gap-1.5 text-xs">
                            <x-icon name="file-down" class="w-3.5 h-3.5 text-rose-600" />
                            <span>{{ __('Export PDF') }}</span>
                        </x-ui.button>
                    </div>
                </div>

                <!-- Report Type Selection Pills -->
                <div class="flex flex-wrap gap-2 pt-2 border-t border-border/50">
                    <button
                        type="button"
                        wire:click="$set('selectedReportType', 'faculty_workload')"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition cursor-pointer {{ $selectedReportType === 'faculty_workload' ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-foreground border-border hover:bg-secondary' }}"
                    >
                        {{ __('1. Faculty Workload & Delivery Report') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('selectedReportType', 'course_coverage')"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition cursor-pointer {{ $selectedReportType === 'course_coverage' ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-foreground border-border hover:bg-secondary' }}"
                    >
                        {{ __('2. Course Syllabus Coverage & Cancellation Report') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('selectedReportType', 'classroom_utilization')"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition cursor-pointer {{ $selectedReportType === 'classroom_utilization' ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-foreground border-border hover:bg-secondary' }}"
                    >
                        {{ __('3. Classroom Utilization & Occupancy Report') }}
                    </button>
                </div>

                <!-- Filter Controls for Current Report -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2">
                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Filter Batch') }}</label>
                        <select wire:model.live="reportBatchId" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none">
                            <option value="">{{ __('All Batches') }}</option>
                            @foreach ($this->batches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Filter Course') }}</label>
                        <select wire:model.live="reportCourseId" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none">
                            <option value="">{{ __('All Courses') }}</option>
                            @foreach ($this->courses as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($selectedReportType === 'faculty_workload')
                        <div>
                            <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Filter Faculty') }}</label>
                            <select wire:model.live="reportFacultyId" class="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none">
                                <option value="">{{ __('All Faculties') }}</option>
                                @foreach ($this->allFaculties as $f)
                                    <option value="{{ $f->id }}">{{ $f->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Report Live Data Table Preview -->
            <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
                <div class="p-3 bg-muted/30 border-b border-border flex items-center justify-between text-xs font-semibold">
                    <span>{{ __('Live Report Preview') }} ({{ count($this->currentReportData) }} {{ __('records') }})</span>
                    <span class="text-muted-foreground text-[11px]">{{ __('Generated on') }} {{ now()->format('d M Y, h:i A') }}</span>
                </div>

                <div class="overflow-x-auto">
                    @if ($selectedReportType === 'faculty_workload')
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground font-semibold">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Code') }}</th>
                                    <th class="px-4 py-3">{{ __('Faculty Name') }}</th>
                                    <th class="px-4 py-3">{{ __('Department') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Total Assigned') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Conducted') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Upcoming') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Cancelled') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Total Hours') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Completion %') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y border-border text-foreground">
                                @forelse ($this->currentReportData as $item)
                                    <tr class="hover:bg-muted/30">
                                        <td class="px-4 py-2.5 font-mono text-muted-foreground">{{ $item['faculty_code'] }}</td>
                                        <td class="px-4 py-2.5 font-semibold">{{ $item['name'] }}</td>
                                        <td class="px-4 py-2.5">{{ $item['department'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold">{{ $item['total_assigned'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-emerald-600">{{ $item['conducted'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-blue-600">{{ $item['scheduled'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-rose-500">{{ $item['cancelled'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-semibold">{{ $item['total_hours'] }} hrs</td>
                                        <td class="px-4 py-2.5 text-center font-bold">{{ $item['completion_rate'] }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="p-8 text-center text-muted-foreground">{{ __('No records found.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif ($selectedReportType === 'course_coverage')
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground font-semibold">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Plan Code') }}</th>
                                    <th class="px-4 py-3">{{ __('Course & Plan Title') }}</th>
                                    <th class="px-4 py-3">{{ __('Batch / Section') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Planned Quota') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Conducted') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Upcoming') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Cancelled') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Remaining') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Coverage %') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y border-border text-foreground">
                                @forelse ($this->currentReportData as $item)
                                    <tr class="hover:bg-muted/30">
                                        <td class="px-4 py-2.5 font-mono text-muted-foreground">{{ $item['plan_code'] }}</td>
                                        <td class="px-4 py-2.5">
                                            <div class="font-semibold">{{ $item['title'] }}</div>
                                            <div class="text-[11px] text-muted-foreground">{{ $item['course_name'] }} &bull; {{ $item['plan_type'] }}</div>
                                        </td>
                                        <td class="px-4 py-2.5">{{ $item['batch_name'] }} &bull; {{ $item['section_name'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold">{{ $item['planned_lectures'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-emerald-600">{{ $item['conducted'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-blue-600">{{ $item['scheduled'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-rose-500" title="{{ __('Excluded from completed count') }}">{{ $item['cancelled'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-semibold">{{ $item['remaining'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-primary">{{ $item['coverage_percent'] }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="p-8 text-center text-muted-foreground">{{ __('No records found.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @else
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground font-semibold">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Room Code') }}</th>
                                    <th class="px-4 py-3">{{ __('Classroom Name') }}</th>
                                    <th class="px-4 py-3">{{ __('Building & Floor') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Capacity') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Conducted Sessions') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Upcoming Sessions') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Cancelled Sessions') }}</th>
                                    <th class="px-4 py-3 text-center">{{ __('Total Hours Used') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y border-border text-foreground">
                                @forelse ($this->currentReportData as $item)
                                    <tr class="hover:bg-muted/30">
                                        <td class="px-4 py-2.5 font-mono text-muted-foreground">{{ $item['room_code'] }}</td>
                                        <td class="px-4 py-2.5 font-semibold">{{ $item['name'] }}</td>
                                        <td class="px-4 py-2.5">{{ $item['building'] }} &bull; {{ $item['floor'] }}</td>
                                        <td class="px-4 py-2.5 text-center">{{ $item['capacity'] }}</td>
                                        <td class="px-4 py-2.5 text-center">{{ $item['status'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-emerald-600">{{ $item['conducted_sessions'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-blue-600">{{ $item['scheduled_sessions'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-bold text-rose-500">{{ $item['cancelled_sessions'] }}</td>
                                        <td class="px-4 py-2.5 text-center font-semibold">{{ $item['total_hours_used'] }} hrs</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="p-8 text-center text-muted-foreground">{{ __('No records found.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Single Class Schedule Modal -->
    <x-ui.modal name="class-form-modal" maxWidth="max-w-3xl" :title="$editingId ? __('Edit Class Schedule') : __('Schedule New Class / Lecture')" :description="__('Configure date, timing, subjects, teacher assignments, and delivery mode.')">
        <form wire:submit.prevent="saveSingle" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Batch') }} <span class="text-destructive">*</span></label>
                    <select wire:model.live="form.batch_id" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('Select Batch') }}</option>
                        @foreach ($this->batches as $batch)
                            <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                        @endforeach
                    </select>
                    @error('form.batch_id') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Course') }} <span class="text-destructive">*</span></label>
                    <select wire:model="form.course_id" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('Select Course') }}</option>
                        @foreach ($this->formCourses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                    @error('form.course_id') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Section (Optional)') }}</label>
                    <select wire:model="form.section_id" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('All Sections / Batch-wide') }}</option>
                        @foreach ($this->formSections as $sec)
                            <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Class Category') }} <span class="text-destructive">*</span></label>
                    <select wire:model="form.class_type" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="regular">{{ __('Regular Classes') }}</option>
                        <option value="optional">{{ __('Optional Classes') }}</option>
                        <option value="extra">{{ __('Extra Classes') }}</option>
                        <option value="revision">{{ __('Revision Classes') }}</option>
                        <option value="guest_lecture">{{ __('Guest Lecture') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Class Date') }} <span class="text-destructive">*</span></label>
                    <input
                        type="date"
                        wire:model.live="form.class_date"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.class_date') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Day of Week') }} ({{ __('Auto-computed') }})</label>
                    <input
                        type="text"
                        wire:model="form.day_of_week"
                        readonly
                        class="w-full rounded-md border border-input bg-muted px-3 py-2 text-sm font-semibold text-foreground"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Time') }} <span class="text-destructive">*</span></label>
                    <input
                        type="time"
                        wire:model="form.start_time"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Time') }} <span class="text-destructive">*</span></label>
                    <input
                        type="time"
                        wire:model="form.end_time"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Lecture No. (Optional)') }}</label>
                    <input
                        type="text"
                        wire:model="form.lecture_number"
                        placeholder="{{ __('Lecture 14') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Subject (Optional)') }}</label>
                    <input
                        type="text"
                        wire:model="form.subject"
                        placeholder="{{ __('Indian Polity & Governance') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Course Plan Link') }}</label>
                    <select wire:model="form.course_plan_id" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('None / Independent') }}</option>
                        @foreach ($this->coursePlans as $cp)
                            <option value="{{ $cp->id }}">{{ $cp->title }} ({{ $cp->plan_code }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-3">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Topics / Syllabus Content') }}</label>
                    <input
                        type="text"
                        wire:model="form.topics"
                        placeholder="{{ __('Preamble, Fundamental Rights, Article 14 to 32 with landmark judgments') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div class="sm:col-span-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-semibold text-foreground">
                            {{ __('Assigned Teacher(s) / Faculty') }}
                        </label>
                        <button
                            type="button"
                            wire:click="openQuickFacultyModal"
                            class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                        >
                            <x-icon name="user-plus" class="h-3.5 w-3.5"/>
                            <span>{{ __('+ Quick Add Teacher') }}</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 p-2 rounded-md border border-input bg-background max-h-36 overflow-y-auto">
                        @foreach ($this->allFaculties as $faculty)
                            <label class="flex items-center gap-2 p-1.5 rounded hover:bg-secondary cursor-pointer text-xs">
                                <input
                                    type="checkbox"
                                    wire:model="form.selected_faculty_ids"
                                    value="{{ (string) $faculty->id }}"
                                    class="rounded border-input text-primary focus:ring-primary"
                                />
                                <div>
                                    <span class="font-medium text-foreground block">{{ $faculty->name }}</span>
                                    <span class="text-[10px] text-muted-foreground">{{ $faculty->department }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                <!-- Delivery Mode Section -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Delivery Mode') }} <span class="text-destructive">*</span></label>
                    <select wire:model.live="form.delivery_mode" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="offline">{{ __('Offline (Classroom)') }}</option>
                        <option value="online">{{ __('Online (Live Video)') }}</option>
                        <option value="hybrid">{{ __('Hybrid') }}</option>
                    </select>
                </div>

                @if ($form['delivery_mode'] === 'offline' || $form['delivery_mode'] === 'hybrid')
                    <div class="sm:col-span-2 space-y-2">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">
                                {{ __('Select Classroom (Master)') }}
                            </label>
                            <select
                                wire:model.live="form.classroom_id"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            >
                                <option value="">{{ __('-- Select from Classrooms Master --') }}</option>
                                @foreach ($this->classroomsList as $room)
                                    <option value="{{ $room->id }}">
                                        {{ $room->name }} &bull; {{ $room->building }} ({{ $room->floor }}) [{{ $room->capacity ? $room->capacity.' seats' : 'N/A' }}]
                                    </option>
                                @endforeach
                            </select>
                            @error('form.classroom_id') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Classroom / Hall Display Name') }}</label>
                            <input
                                type="text"
                                wire:model="form.classroom"
                                placeholder="{{ __('Hall A - Ground Floor, Main Academic Block') }}"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>
                    </div>
                @endif

                @if ($form['delivery_mode'] === 'online' || $form['delivery_mode'] === 'hybrid')
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Platform') }}</label>
                        <select wire:model.live="form.platform" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                            <option value="Zoom">Zoom</option>
                            <option value="Google Meet">Google Meet</option>
                            <option value="Microsoft Teams">Microsoft Teams</option>
                            <option value="Cisco Webex Meetings">Cisco Webex Meetings</option>
                            <option value="Others">{{ __('Others') }}</option>
                        </select>
                    </div>

                    @if ($form['platform'] === 'Others')
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Custom Platform Name') }}</label>
                            <input
                                type="text"
                                wire:model="form.platform_other"
                                placeholder="{{ __('e.g. YouTube Live, Custom LMS') }}"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>
                    @endif

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Online Meeting Link / URL') }}</label>
                        <input
                            type="url"
                            wire:model="form.meeting_link"
                            placeholder="https://us02web.zoom.us/j/123456789"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Meeting ID') }}</label>
                        <input
                            type="text"
                            wire:model="form.meeting_id"
                            placeholder="123 456 7890"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Meeting Passcode / Password') }}</label>
                        <input
                            type="text"
                            wire:model="form.meeting_password"
                            placeholder="pass1234"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                    </div>
                @endif

                <div class="sm:col-span-3">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Instructions / Notes for Students') }}</label>
                    <textarea
                        wire:model="form.description"
                        rows="2"
                        placeholder="{{ __('Bring Laxmikanth Polity textbook and previous year prelims question compilations.') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    ></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4"/>
                    <span>{{ $editingId ? __('Update Schedule') : __('Save Class Routine') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Recurrence Routine Generator Modal -->
    <x-ui.modal name="recurrence-form-modal" maxWidth="max-w-3xl" :title="__('Batch Recurrence Routine Generator')" :description="__('Generate multiple class routine instances at once across dates without manual repetitive entries.')">
        <form wire:submit.prevent="generateRecurrence" class="space-y-4">
            <div class="p-3 rounded-lg bg-primary/10 border border-primary/20 text-xs text-primary">
                <x-icon name="info" class="h-4 w-4 inline mr-1"/>
                {{ __('This creates independent class instances for each matching date. Each generated class can still be edited, rescheduled, or cancelled separately later.') }}
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Batch') }} <span class="text-destructive">*</span></label>
                    <select wire:model.live="recurrenceForm.batch_id" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('Select Batch') }}</option>
                        @foreach ($this->batches as $batch)
                            <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Course') }} <span class="text-destructive">*</span></label>
                    <select wire:model="recurrenceForm.course_id" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('Select Course') }}</option>
                        @foreach ($this->recurrenceFormCourses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Section (Optional)') }}</label>
                    <select wire:model="recurrenceForm.section_id" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">{{ __('All Sections') }}</option>
                        @foreach ($this->recurrenceFormSections as $sec)
                            <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('From Start Date') }} <span class="text-destructive">*</span></label>
                    <input type="date" wire:model="recurrenceForm.start_date" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('To End Date') }} <span class="text-destructive">*</span></label>
                    <input type="date" wire:model="recurrenceForm.end_date" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Starting Lecture No.') }}</label>
                    <input type="number" min="1" wire:model="recurrenceForm.start_lecture_no" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div class="sm:col-span-3">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Recurrence Days of Week') }} <span class="text-destructive">*</span></label>
                    <div class="flex flex-wrap gap-2">
                        @foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day)
                            <label class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border text-xs cursor-pointer hover:bg-secondary">
                                <input type="checkbox" wire:model="recurrenceForm.days_of_week" value="{{ $day }}" class="rounded border-input text-primary focus:ring-primary"/>
                                <span class="font-medium text-foreground">{{ $day }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Time') }} <span class="text-destructive">*</span></label>
                    <input type="time" wire:model="recurrenceForm.start_time" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Time') }} <span class="text-destructive">*</span></label>
                    <input type="time" wire:model="recurrenceForm.end_time" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Class Category') }}</label>
                    <select wire:model="recurrenceForm.class_type" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="regular">{{ __('Regular Classes') }}</option>
                        <option value="optional">{{ __('Optional Classes') }}</option>
                        <option value="extra">{{ __('Extra Classes') }}</option>
                        <option value="revision">{{ __('Revision Classes') }}</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Subject Title') }}</label>
                    <input type="text" wire:model="recurrenceForm.subject" placeholder="{{ __('General Studies: Indian Economy') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Mode') }}</label>
                    <select wire:model.live="recurrenceForm.delivery_mode" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="offline">{{ __('Offline (Classroom)') }}</option>
                        <option value="online">{{ __('Online (Video Meeting)') }}</option>
                        <option value="hybrid">{{ __('Hybrid') }}</option>
                    </select>
                </div>

                @if ($recurrenceForm['delivery_mode'] === 'offline' || $recurrenceForm['delivery_mode'] === 'hybrid')
                    <div class="sm:col-span-3 space-y-2">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">
                                {{ __('Select Classroom (Master)') }}
                            </label>
                            <select
                                wire:model.live="recurrenceForm.classroom_id"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            >
                                <option value="">{{ __('-- Select from Classrooms Master --') }}</option>
                                @foreach ($this->classroomsList as $room)
                                    <option value="{{ $room->id }}">
                                        {{ $room->name }} &bull; {{ $room->building }} ({{ $room->floor }}) [{{ $room->capacity ? $room->capacity.' seats' : 'N/A' }}]
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Classroom / Hall Display Name') }}</label>
                            <input type="text" wire:model="recurrenceForm.classroom" placeholder="{{ __('Auditorium Hall 2') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                        </div>
                    </div>
                @endif
                @if ($recurrenceForm['delivery_mode'] === 'online' || $recurrenceForm['delivery_mode'] === 'hybrid')
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Platform') }}</label>
                        <select wire:model="recurrenceForm.platform" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                            <option value="Zoom">Zoom</option>
                            <option value="Google Meet">Google Meet</option>
                            <option value="Microsoft Teams">Microsoft Teams</option>
                            <option value="Cisco Webex Meetings">Cisco Webex Meetings</option>
                            <option value="Others">{{ __('Others') }}</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Meeting Link') }}</label>
                        <input type="url" wire:model="recurrenceForm.meeting_link" placeholder="https://zoom.us/j/..." class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                    </div>
                @endif

                <div class="sm:col-span-3">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Assigned Teacher(s)') }}</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 p-2 rounded-md border border-input bg-background max-h-32 overflow-y-auto">
                        @foreach ($this->allFaculties as $faculty)
                            <label class="flex items-center gap-2 p-1 rounded hover:bg-secondary cursor-pointer text-xs">
                                <input type="checkbox" wire:model="recurrenceForm.selected_faculty_ids" value="{{ (string) $faculty->id }}" class="rounded border-input text-primary focus:ring-primary"/>
                                <span class="font-medium text-foreground">{{ $faculty->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'recurrence-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="repeat" class="h-4 w-4"/>
                    <span>{{ __('Generate Batch Classes') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Quick Add Teacher Modal -->
    <x-ui.modal name="quick-faculty-modal" maxWidth="max-w-2xl" :title="__('Quick Add Teacher / Faculty')" :description="__('Add a new faculty member inline with photo, CV, partner institute reference, and subjects without leaving the class scheduler.')">
        <form wire:submit.prevent="saveQuickFaculty" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Teacher Full Name') }} <span class="text-destructive">*</span></label>
                    <input type="text" wire:model="quickFacultyForm.name" required placeholder="{{ __('Dr. B. R. Sharma') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                    @error('quickFacultyForm.name') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Designation') }}</label>
                    <input type="text" wire:model="quickFacultyForm.designation" placeholder="{{ __('Senior Professor / Guest Lecturer') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                    @error('quickFacultyForm.designation') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Department') }} <span class="text-destructive">*</span>
                    </label>
                    <select wire:model.live="quickFacultyDepartmentSelect" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none">
                        @foreach (self::PREDEFINED_DEPARTMENTS as $deptOption)
                            <option value="{{ $deptOption }}">{{ $deptOption }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($quickFacultyDepartmentSelect === 'Custom')
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            {{ __('Custom Department Name') }} <span class="text-destructive">*</span>
                        </label>
                        <input type="text" wire:model="quickFacultyCustomDepartment" placeholder="{{ __('e.g. Political Philosophy, Anthropology') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                        @error('quickFacultyCustomDepartment') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
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
                            @if ($quickFacultyPhoto && ! $removeQuickFacultyPhoto)
                                <img src="{{ $quickFacultyPhoto->temporaryUrl() }}" class="w-12 h-12 rounded-full object-cover border border-border">
                            @else
                                <div class="w-12 h-12 rounded-full bg-secondary flex items-center justify-center text-muted-foreground text-xs font-bold">
                                    <x-icon name="user" class="w-6 h-6" />
                                </div>
                            @endif
                            <div class="flex-1">
                                <input
                                    type="file"
                                    wire:model="quickFacultyPhoto"
                                    accept="image/*"
                                    class="w-full text-xs text-muted-foreground file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer"
                                />
                                <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('PNG, JPG, WEBP up to 5MB') }}</span>
                                @if ($quickFacultyPhoto && ! $removeQuickFacultyPhoto)
                                    <div class="pt-1">
                                        <button
                                            type="button"
                                            wire:click="$set('removeQuickFacultyPhoto', true); $set('quickFacultyPhoto', null)"
                                            class="inline-flex items-center gap-1 text-[11px] text-destructive hover:underline"
                                        >
                                            <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                            <span>{{ __('Remove photo') }}</span>
                                        </button>
                                    </div>
                                @endif
                                @error('quickFacultyPhoto') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
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
                            wire:model="quickFacultyCv"
                            accept=".pdf,.doc,.docx"
                            class="w-full text-xs text-muted-foreground file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80 cursor-pointer"
                        />
                        <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('PDF, DOC, DOCX up to 10MB') }}</span>
                        @if ($quickFacultyCv && ! $removeQuickFacultyCv)
                            <div class="pt-1">
                                <button
                                    type="button"
                                    wire:click="$set('removeQuickFacultyCv', true); $set('quickFacultyCv', null)"
                                    class="inline-flex items-center gap-1 text-[11px] text-destructive hover:underline"
                                >
                                    <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                    <span>{{ __('Remove CV') }}</span>
                                </button>
                            </div>
                        @endif
                        @error('quickFacultyCv') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- Reference Partner Coaching Institute -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Reference Partner Institute (Optional)') }}
                    </label>
                    <select
                        wire:model="quickFacultyForm.partner_institute_id"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="">{{ __('-- None / In-house Faculty --') }}</option>
                        @foreach ($this->partnerInstitutes as $inst)
                            <option value="{{ $inst->id }}">{{ $inst->name }} ({{ $inst->code }})</option>
                        @endforeach
                    </select>
                    @error('quickFacultyForm.partner_institute_id') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <!-- Faculty Type (Regular, Guest, Visiting, etc.) -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Faculty Type / Status') }} <span class="text-destructive">*</span>
                    </label>
                    <select
                        wire:model="quickFacultyForm.faculty_type"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="regular">{{ __('Regular Faculty') }}</option>
                        <option value="guest">{{ __('Guest Faculty') }}</option>
                        <option value="visiting">{{ __('Visiting Expert / Mentor') }}</option>
                        <option value="contractual">{{ __('Contractual Faculty') }}</option>
                        <option value="adjunct">{{ __('Adjunct Faculty') }}</option>
                    </select>
                    @error('quickFacultyForm.faculty_type') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Official Email') }}</label>
                    <input type="email" wire:model="quickFacultyForm.email" placeholder="{{ __('faculty@institution.org') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                    @error('quickFacultyForm.email') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Contact Phone') }}</label>
                    <input type="tel" wire:model="quickFacultyForm.phone" placeholder="{{ __('+91 98765 43210') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                    @error('quickFacultyForm.phone') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Specialization') }}</label>
                    <input type="text" wire:model="quickFacultyForm.specialization" placeholder="{{ __('Modern Indian History, International Relations') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Qualification') }}</label>
                    <input type="text" wire:model="quickFacultyForm.qualification" placeholder="{{ __('Ph.D., M.A. in History, UGC-NET') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Competent Subjects (comma separated)') }}</label>
                    <input type="text" wire:model="quickFacultyForm.subjects_raw" placeholder="{{ __('History, Geography, CSAT') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                    <p class="text-[11px] text-muted-foreground mt-1">{{ __('Any subject can be taught by one or multiple faculties.') }}</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Short Biography / Background') }}</label>
                    <textarea wire:model="quickFacultyForm.bio" rows="2" placeholder="{{ __('Academic researcher with mentoring experience...') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'quick-faculty-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4"/>
                    <span>{{ __('Save & Assign Faculty') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Upload / Add Recording Modal -->
    <x-ui.modal name="class-recording-modal" maxWidth="max-w-xl" :title="__('Class Lecture Recording Access')" :description="__('Provide cloud recording link or upload lecture recording file for enrolled students.')">
        <form wire:submit.prevent="saveRecording" class="space-y-4">
            @if ($recordingSchedule)
                <div class="p-3 rounded-lg bg-secondary/50 text-xs">
                    <div class="font-bold text-foreground">{{ $recordingSchedule->subject }} &bull; {{ $recordingSchedule->lecture_number }}</div>
                    <div class="text-muted-foreground">{{ $recordingSchedule->class_date->format('d M Y') }} ({{ $recordingSchedule->formatted_time }})</div>
                </div>
            @endif

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Recording Cloud URL / Link') }}</label>
                <input
                    type="url"
                    wire:model="recordingForm.recording_url"
                    placeholder="https://us02web.zoom.us/rec/share/..."
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                />
                <p class="text-[11px] text-muted-foreground mt-1">{{ __('Supports Zoom Cloud, YouTube Unlisted, Google Drive, Vimeo, or CDN link.') }}</p>
                @error('recordingForm.recording_url') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Recording Passcode / Access Key') }}</label>
                    <input
                        type="text"
                        wire:model="recordingForm.recording_password"
                        placeholder="{{ __('Optional Password') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Recording Status') }}</label>
                    <select
                        wire:model="recordingForm.recording_status"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    >
                        <option value="available">{{ __('Available for Students') }}</option>
                        <option value="processing">{{ __('Processing') }}</option>
                        <option value="not_available">{{ __('Not Available') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Or Upload Recording File (mp4, webm, mkv)') }}</label>
                <input
                    type="file"
                    wire:model="recordingUploadFile"
                    accept=".mp4,.webm,.mp3,.mkv"
                    class="w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
                <div wire:loading wire:target="recordingUploadFile" class="text-xs text-primary mt-1">{{ __('Uploading recording...') }}</div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Timestamps / Lecture Index / Study Notes') }}</label>
                <textarea
                    wire:model="recordingForm.recording_notes"
                    rows="2"
                    placeholder="{{ __('00:00 - Introduction; 25:30 - Fundamental Rights; 55:00 - Q&A Session') }}"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-recording-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4"/>
                    <span>{{ __('Save Recording') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Reschedule Modal -->
    <x-ui.modal name="class-reschedule-modal" maxWidth="max-w-md" :title="__('Reschedule Class')" :description="__('Change the date and timing for this scheduled class.')">
        <form wire:submit.prevent="saveReschedule" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('New Class Date') }} <span class="text-destructive">*</span></label>
                <input type="date" wire:model="rescheduleForm.new_date" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Time') }} <span class="text-destructive">*</span></label>
                    <input type="time" wire:model="rescheduleForm.start_time" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Time') }} <span class="text-destructive">*</span></label>
                    <input type="time" wire:model="rescheduleForm.end_time" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"/>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Reason for Rescheduling') }}</label>
                <textarea wire:model="rescheduleForm.reason" rows="2" placeholder="{{ __('Faculty emergency / Holiday adjustment...') }}" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-reschedule-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4"/>
                    <span>{{ __('Confirm Reschedule') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Cancel Class Modal -->
    <x-ui.modal name="class-cancel-modal" maxWidth="max-w-md" :title="__('Cancel Scheduled Class')" :description="__('Record cancellation reason for audit trails and student notifications.')">
        <div class="space-y-4">
            @if ($cancellingSchedule)
                <p class="text-xs text-foreground">
                    {{ __('You are cancelling:') }} <strong>{{ $cancellingSchedule->subject }}</strong> ({{ $cancellingSchedule->class_date->format('d M Y') }}).
                </p>
            @endif

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Reason for Cancellation') }} <span class="text-destructive">*</span></label>
                <textarea
                    wire:model="cancelReason"
                    rows="3"
                    required
                    placeholder="{{ __('Unforeseen faculty absence / technical outage...') }}"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                ></textarea>
                @error('cancelReason') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-cancel-modal' })">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button type="button" variant="destructive" size="sm" wire:click="confirmCancelClass" class="gap-1.5">
                    <x-icon name="x-circle" class="h-4 w-4"/>
                    <span>{{ __('Cancel Class') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Class View Details Modal -->
    <x-ui.modal name="class-view-modal" maxWidth="max-w-2xl" :title="$viewingSchedule?->subject ?? __('Class Lecture Details')" :description="__('Complete lecture specifications, teacher assignments, and joining details.')">
        @if ($viewingSchedule)
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 rounded-lg bg-secondary/50">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-foreground">{{ $viewingSchedule->subject ?: __('Subject Not Set') }}</h3>
                            <span class="rounded-md bg-primary/10 text-primary px-2 py-0.5 text-xs font-semibold">
                                {{ ucfirst($viewingSchedule->class_type) }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ $viewingSchedule->course?->name }} &bull; {{ $viewingSchedule->batch?->name }}
                            {{ $viewingSchedule->section ? '('.$viewingSchedule->section->name.')' : '(All Sections)' }}
                        </p>
                        <p class="text-xs font-mono text-primary mt-0.5">{{ $viewingSchedule->schedule_code }} &bull; {{ $viewingSchedule->lecture_number ?: 'N/A' }}</p>
                    </div>

                    @if ($viewingSchedule->delivery_mode === 'online' && $viewingSchedule->meeting_link)
                        <a href="{{ $viewingSchedule->meeting_link }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90">
                            <x-icon name="video" class="h-4 w-4"/>
                            <span>{{ __('Join Class') }}</span>
                        </a>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Date & Day') }}</span>
                        <span class="font-bold text-foreground">{{ $viewingSchedule->class_date->format('d M Y') }}</span>
                        <span class="text-[10px] text-muted-foreground block">{{ $viewingSchedule->day_of_week }}</span>
                    </div>

                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Timings') }}</span>
                        <span class="font-bold text-foreground">{{ $viewingSchedule->formatted_time }}</span>
                    </div>

                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Mode & Venue') }}</span>
                        <span class="font-bold text-foreground capitalize">{{ $viewingSchedule->delivery_mode }}</span>
                        <span class="text-[10px] text-muted-foreground block">{{ $viewingSchedule->delivery_mode === 'online' ? $viewingSchedule->platform : ($viewingSchedule->classroom ?: 'Campus') }}</span>
                    </div>

                    <div class="rounded-md border border-border p-2.5">
                        <span class="text-muted-foreground block font-medium">{{ __('Status') }}</span>
                        <span class="font-bold text-foreground capitalize">{{ $viewingSchedule->status }}</span>
                    </div>
                </div>

                <!-- Faculty Members -->
                <div class="rounded-md border border-border p-3 text-xs">
                    <span class="text-muted-foreground block font-semibold mb-2 uppercase tracking-wider text-[11px]">{{ __('Assigned Faculty / Teachers') }}</span>
                    <div class="flex flex-wrap gap-2">
                        @forelse ($viewingSchedule->faculties as $f)
                            <div class="inline-flex items-center gap-2 rounded-md bg-secondary px-2.5 py-1 text-xs">
                                <div class="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground font-bold text-[9px]">
                                    {{ $f->initials }}
                                </div>
                                <span class="font-semibold text-foreground">{{ $f->name }}</span>
                                <span class="text-[10px] text-muted-foreground">({{ $f->designation ?: 'Faculty' }})</span>
                            </div>
                        @empty
                            <span class="text-muted-foreground italic">{{ __('No faculty assigned.') }}</span>
                        @endforelse
                    </div>
                </div>

                @if ($viewingSchedule->topics)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Topics Covered') }}</span>
                        <p class="text-foreground leading-relaxed">{{ $viewingSchedule->topics }}</p>
                    </div>
                @endif

                @if ($viewingSchedule->description)
                    <div class="rounded-md border border-border p-3 text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Instructions & Reading Material') }}</span>
                        <p class="text-foreground leading-relaxed whitespace-pre-line">{{ $viewingSchedule->description }}</p>
                    </div>
                @endif

                <!-- Online Meeting Credentials -->
                @if ($viewingSchedule->delivery_mode === 'online')
                    <div class="rounded-md border border-border bg-violet-500/5 p-3 text-xs space-y-2">
                        <div class="font-semibold text-violet-600 dark:text-violet-400 flex items-center gap-1.5">
                            <x-icon name="video" class="h-4 w-4"/>
                            <span>{{ __('Online Meeting Platform Credentials') }} ({{ $viewingSchedule->platform }})</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <span class="text-muted-foreground block">{{ __('Meeting ID') }}</span>
                                <span class="font-mono font-semibold text-foreground">{{ $viewingSchedule->meeting_id ?: __('Not Required') }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground block">{{ __('Passcode') }}</span>
                                <span class="font-mono font-semibold text-foreground">{{ $viewingSchedule->meeting_password ?: __('Not Required') }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Recording Info -->
                @if ($viewingSchedule->hasRecording())
                    <div class="rounded-md border border-border bg-emerald-500/5 p-3 text-xs space-y-2">
                        <div class="font-semibold text-emerald-600 dark:text-emerald-400 flex items-center justify-between">
                            <span class="flex items-center gap-1.5">
                                <x-icon name="play-circle" class="h-4 w-4"/>
                                {{ __('Class Recording Available') }}
                            </span>
                            @if ($viewingSchedule->recording_access_url)
                                <a href="{{ $viewingSchedule->recording_access_url }}" target="_blank" class="rounded-md bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700">
                                    {{ __('Watch Recording') }} &rarr;
                                </a>
                            @endif
                        </div>
                        @if ($viewingSchedule->recording_password)
                            <div class="text-[11px]">
                                <span class="text-muted-foreground">{{ __('Passcode:') }}</span>
                                <span class="font-mono font-semibold">{{ $viewingSchedule->recording_password }}</span>
                            </div>
                        @endif
                        @if ($viewingSchedule->recording_notes)
                            <p class="text-muted-foreground text-[11px] pt-1">{{ $viewingSchedule->recording_notes }}</p>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal name="class-delete-modal" maxWidth="max-w-md" :title="__('Delete Class Schedule')" :description="__('Are you sure you want to delete this scheduled class?')">
        @if ($deletingSchedule)
            <div class="space-y-3">
                <p class="text-sm text-foreground">
                    {{ __('You are about to delete:') }} <strong>{{ $deletingSchedule->subject }}</strong> ({{ $deletingSchedule->schedule_code }}).
                </p>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-delete-modal' })">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="destructive" size="sm" wire:click="delete" class="gap-1.5">
                        <x-icon name="trash-2" class="h-4 w-4"/>
                        <span>{{ __('Delete Schedule') }}</span>
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Bulk Import Modal -->
    <x-ui.modal name="class-import-modal" maxWidth="max-w-lg" :title="__('Bulk Import Class Schedules')" :description="__('Upload Excel (.xlsx) or CSV file with routine schedules.')">
        <form wire:submit.prevent="importSchedules" class="space-y-4">
            <div class="flex items-center justify-between p-3 rounded-lg bg-secondary/50 text-xs">
                <div>
                    <span class="font-semibold text-foreground block">{{ __('Download CSV Template') }}</span>
                    <span class="text-muted-foreground">{{ __('Pre-formatted sample with required columns.') }}</span>
                </div>
                <x-ui.button type="button" variant="outline" size="sm" wire:click="downloadTemplate" class="gap-1 cursor-pointer">
                    <x-icon name="download" class="h-3.5 w-3.5"/>
                    <span>{{ __('Template') }}</span>
                </x-ui.button>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select File (CSV or Excel)') }}</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,.xlsx,.xls,.txt"
                    required
                    class="w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
                @error('importFile') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
            </div>

            @if (!empty($importErrors))
                <div class="rounded-md bg-destructive/10 border border-destructive/20 p-3 text-xs text-destructive max-h-36 overflow-y-auto space-y-1">
                    <span class="font-bold block">{{ __('Validation / Parsing Errors:') }}</span>
                    @foreach ($importErrors as $err)
                        <div>&bull; {{ $err }}</div>
                    @endforeach
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-import-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="upload" class="h-4 w-4"/>
                    <span>{{ __('Start Import') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Manual Class Reminder Modal -->
    <x-ui.modal name="class-reminder-modal" maxWidth="max-w-2xl" :title="__('Send Class Reminder Alert')" :description="__('Send instant alert notification to enrolled students for this class via chosen channels.')">
        @if ($remindSchedule)
            <div class="space-y-4">
                <!-- Target Class Summary Card -->
                <div class="p-3.5 rounded-xl border border-primary/20 bg-primary/5 space-y-2 text-xs">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-primary">{{ $remindSchedule->class_type }} {{ __('Class') }}</span>
                            <h4 class="text-sm font-bold text-foreground">{{ $remindSchedule->subject }}</h4>
                            <p class="text-muted-foreground">{{ $remindSchedule->course?->name }} &bull; {{ $remindSchedule->batch?->name }} {{ $remindSchedule->section ? '('.$remindSchedule->section->name.')' : '' }}</p>
                        </div>
                        <span class="rounded-full bg-primary/10 text-primary font-semibold px-2 py-0.5 text-xs">
                            {{ $enrolledStudentsCount }} {{ __('Students Enrolled') }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-1 border-t border-primary/10 text-[11px]">
                        <div>
                            <span class="text-muted-foreground block">{{ __('Date & Time') }}</span>
                            <span class="font-semibold text-foreground">{{ $remindSchedule->class_date->format('d M Y') }} ({{ $remindSchedule->formatted_time }})</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">{{ __('Mode & Venue') }}</span>
                            <span class="font-semibold text-foreground">{{ ucfirst($remindSchedule->delivery_mode) }} - {{ $remindSchedule->classroom_display }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">{{ __('Assigned Faculty') }}</span>
                            <span class="font-semibold text-foreground">{{ $remindSchedule->primary_faculty_names }}</span>
                        </div>
                    </div>
                </div>

                <!-- Channels Selection -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5">
                        {{ __('Select Channels to Send Alert') }} <span class="text-destructive">*</span>
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach (\App\Services\ClassReminderService::CHANNELS as $chKey => $chLabel)
                            <label class="flex items-center gap-2 p-2 rounded-lg border border-border bg-card text-xs cursor-pointer hover:bg-secondary transition">
                                <input type="checkbox" wire:model="selectedReminderChannels" value="{{ $chKey }}" class="rounded text-primary focus:ring-primary" />
                                <span class="font-medium text-foreground">{{ $chLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <!-- Message Template Preview / Edit -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-semibold text-foreground">
                            {{ __('Notification Message Preview / Custom Override') }}
                        </label>
                        <span class="text-[10px] text-muted-foreground">{{ __('Personalized per student') }}</span>
                    </div>
                    <textarea
                        wire:model="customReminderMessage"
                        rows="5"
                        class="w-full rounded-md border border-input bg-background p-2.5 text-xs text-foreground font-mono focus:ring-2 focus:ring-ring focus:outline-none"
                    ></textarea>
                    <p class="text-[10px] text-muted-foreground mt-1">
                        {{ __('Supported placeholders: {student_name}, {course_name}, {subject}, {class_date}, {class_time}, {delivery_mode}, {venue_or_link}, {faculty_name}') }}
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-reminder-modal' })">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" size="sm" wire:click="sendManualReminder" class="gap-1.5 bg-primary">
                        <x-icon name="send" class="h-4 w-4"/>
                        <span>{{ __('Send Alert to :count Students', ['count' => $enrolledStudentsCount]) }}</span>
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Reminder Settings Modal -->
    <x-ui.modal name="class-reminder-settings-modal" maxWidth="max-w-xl" :title="__('Scheduled Classes Reminder Settings')" :description="__('Configure automated reminders dispatched prior to class commencement and channel rules.')">
        <form wire:submit.prevent="saveReminderSettings" class="space-y-4">
            <!-- Global Toggle -->
            <div class="flex items-center justify-between p-3 rounded-xl border border-border bg-card">
                <div>
                    <span class="font-semibold text-xs text-foreground block">{{ __('Enable Automated Routine Reminders') }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ __('Automatically scan and alert enrolled students before classes start.') }}</span>
                </div>
                <label class="relative inline-flex items-center cursor-pointer select-none">
                    <input type="checkbox" wire:model="reminderEnabled" class="sr-only peer" />
                    <div class="w-11 h-6 bg-zinc-300 peer-focus:outline-hidden rounded-full peer dark:bg-zinc-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-zinc-600 peer-checked:bg-primary"></div>
                </label>
            </div>

            <!-- Lead Time -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">
                    {{ __('Reminder Lead Time (Minutes Before Class Begins)') }} <span class="text-destructive">*</span>
                </label>
                <div class="flex items-center gap-2">
                    <input
                        type="number"
                        min="5"
                        max="1440"
                        wire:model="reminderLeadTime"
                        required
                        class="w-32 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    <span class="text-xs text-muted-foreground">{{ __('minutes (Standard default is 60 minutes)') }}</span>
                </div>
            </div>

            <!-- Channels -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">
                    {{ __('Default Notification Channels') }}
                </label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Services\ClassReminderService::CHANNELS as $cKey => $cName)
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border bg-card text-xs cursor-pointer hover:bg-secondary">
                            <input type="checkbox" wire:model="reminderChannels" value="{{ $cKey }}" class="rounded text-primary focus:ring-primary" />
                            <span>{{ $cName }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Message Template -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">
                    {{ __('Default Reminder Message Template') }}
                </label>
                <textarea
                    wire:model="reminderTemplate"
                    rows="4"
                    class="w-full rounded-md border border-input bg-background p-2.5 text-xs text-foreground font-mono focus:ring-2 focus:ring-ring focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" wire:click="$dispatch('modal-close', { name: 'class-reminder-settings-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4"/>
                    <span>{{ __('Save Reminder Settings') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
