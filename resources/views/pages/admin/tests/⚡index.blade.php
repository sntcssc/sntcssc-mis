<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Section;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Services\Test\StudentTestService;
use App\Services\Test\TestImportExportService;
use App\Support\Toast;
use Carbon\Carbon;
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

new #[Layout('layouts.app')] #[Title('Mock Tests & Examinations Management')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'section')]
    public string $sectionFilter = '';

    #[Url(as: 'stage')]
    public string $stageFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'test_date';
    public string $sortDirection = 'desc';
    public int $perPage = 10;

    // Form Modal state
    public ?int $editingId = null;
    public array $form = [
        'title' => '',
        'code' => '',
        'batch_id' => '',
        'course_id' => '',
        'section_id' => '',
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'test_number' => 1,
        'total_marks' => 200.00,
        'pass_marks' => 66.00,
        'total_questions' => 100,
        'mode' => 'offline',
        'test_date' => '',
        'start_time' => '10:00 AM',
        'end_time' => '12:00 PM',
        'duration_minutes' => 120,
        'venue_room' => '',
        'instructions' => '',
        'negative_marking_ratio' => 0.33,
        'marks_per_question' => 2.00,
        'notify_students' => false,
        'notification_channels' => ['in_app', 'email', 'whatsapp'],
    ];

    public $questionPaperFile = null;
    public $modelAnswerFile = null;

    // Subjects builder
    public array $subjects = [];

    // Delete Modal state
    public ?int $deleteId = null;

    // Notify confirmation modal state
    public ?int $notifyTestId = null;

    public function mount(): void
    {
        $this->form['test_date'] = now()->addDays(2)->format('Y-m-d');
        $this->initDefaultSubjects();
    }

    public function initDefaultSubjects(): void
    {
        $this->subjects = [
            [
                'subject_name' => 'General Studies Paper I',
                'subject_code' => 'GS-1',
                'questions_count' => 100,
                'marks_per_question' => 2.00,
                'negative_marking' => 0.66,
                'max_marks' => 200.00,
            ],
        ];
    }

    public function addSubjectRow(): void
    {
        $this->subjects[] = [
            'subject_name' => '',
            'subject_code' => '',
            'questions_count' => 50,
            'marks_per_question' => (float) ($this->form['marks_per_question'] ?? 2.00),
            'negative_marking' => round((float) ($this->form['marks_per_question'] ?? 2.00) * (float) ($this->form['negative_marking_ratio'] ?? 0.33), 2),
            'max_marks' => 100.00,
        ];
        $this->recalculateSubjectTotals();
    }

    public function removeSubjectRow(int $index): void
    {
        unset($this->subjects[$index]);
        $this->subjects = array_values($this->subjects);
        $this->recalculateSubjectTotals();
    }

    public function updatedSubjects(): void
    {
        $this->recalculateSubjectTotals();
    }

    public function recalculateSubjectTotals(): void
    {
        if (empty($this->subjects)) {
            return;
        }

        $totQ = 0;
        $totM = 0;
        foreach ($this->subjects as &$subj) {
            $q = (int) ($subj['questions_count'] ?? 0);
            $m = (float) ($subj['marks_per_question'] ?? 2.00);
            $max = round($q * $m, 2);
            $subj['max_marks'] = $max;
            $totQ += $q;
            $totM += $max;
        }

        if ($totQ > 0) {
            $this->form['total_questions'] = $totQ;
        }
        if ($totM > 0) {
            $this->form['total_marks'] = $totM;
        }
    }

    public function updatedFormCourseId($courseId): void
    {
        $this->form['section_id'] = '';
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
        $this->sectionFilter = '';
    }

    public function updatedSectionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStageFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
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
    public function batches()
    {
        return Batch::orderBy('name')->get();
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function availableSections()
    {
        if (empty($this->form['course_id'])) {
            return collect();
        }

        return Section::where('course_id', $this->form['course_id'])
            ->when($this->form['batch_id'], fn ($q, $b) => $q->where('batch_id', $b))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function filterSections()
    {
        if (empty($this->courseFilter)) {
            return collect();
        }

        return Section::where('course_id', $this->courseFilter)
            ->when($this->batchFilter, fn ($q, $b) => $q->where('batch_id', $b))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function statistics(): array
    {
        $totalTests = StudentTest::count();
        $completedTests = StudentTest::where('status', StudentTest::STATUS_COMPLETED)->count();
        $totalEvaluations = StudentTestResult::where('attendance_status', StudentTestResult::STATUS_PRESENT)->count();
        $avgScore = StudentTestResult::where('attendance_status', StudentTestResult::STATUS_PRESENT)->avg('percentage') ?? 0;

        return [
            'total_tests' => $totalTests,
            'completed_tests' => $completedTests,
            'total_evaluations' => $totalEvaluations,
            'avg_score' => round((float) $avgScore, 1),
        ];
    }

    #[Computed]
    public function tests()
    {
        return StudentTest::with(['batch', 'course', 'section', 'subjects'])
            ->withCount([
                'results as enrolled_count',
                'results as attended_count' => fn ($q) => $q->where('attendance_status', StudentTestResult::STATUS_PRESENT),
            ])
            ->when($this->search, function (Builder $q) {
                $s = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($s) {
                    $sub->where('title', 'like', $s)
                        ->orWhere('code', 'like', $s)
                        ->orWhere('venue_room', 'like', $s);
                });
            })
            ->when($this->batchFilter, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($this->courseFilter, fn ($q, $v) => $q->where('course_id', $v))
            ->when($this->sectionFilter, fn ($q, $v) => $q->where('section_id', $v))
            ->when($this->stageFilter, fn ($q, $v) => $q->where('test_stage', $v))
            ->when($this->typeFilter, fn ($q, $v) => $q->where('test_type', $v))
            ->when($this->statusFilter, fn ($q, $v) => $q->where('status', $v))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function openCreateModal(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->questionPaperFile = null;
        $this->modelAnswerFile = null;

        $firstBatch = Batch::first();
        $firstCourse = Course::first();

        $this->form = [
            'title' => 'UPSC Prelims Mock Test #' . (StudentTest::count() + 1),
            'code' => sprintf('TEST-%s-%04d', date('Y'), StudentTest::count() + 1),
            'batch_id' => $firstBatch?->id ?? '',
            'course_id' => $firstCourse?->id ?? '',
            'section_id' => '',
            'test_stage' => 'prelims',
            'test_type' => 'mcq',
            'test_for' => 'mock_test',
            'test_number' => StudentTest::count() + 1,
            'total_marks' => 200.00,
            'pass_marks' => 66.00,
            'total_questions' => 100,
            'mode' => 'offline',
            'test_date' => now()->addDays(3)->format('Y-m-d'),
            'start_time' => '10:00 AM',
            'end_time' => '12:00 PM',
            'duration_minutes' => 120,
            'venue_room' => 'Main Auditorium & Examination Hall',
            'instructions' => "1. Use Black/Blue Ball Point pen only.\n2. Negative marking of 1/3rd for wrong answers.\n3. Mobile phones and electronic gadgets are strictly prohibited.",
            'negative_marking_ratio' => 0.33,
            'marks_per_question' => 2.00,
            'notify_students' => true,
            'notification_channels' => ['in_app', 'email', 'whatsapp'],
        ];

        $this->initDefaultSubjects();
        $this->dispatch('modal-open', name: 'test-form-modal');
    }

    public function applyPreset(string $preset): void
    {
        $count = StudentTest::count() + 1;

        if ($preset === 'upsc_gs1') {
            $this->form['title'] = "UPSC Prelims GS Paper I Mock #{$count}";
            $this->form['code'] = sprintf('TEST-GS1-%s-%04d', date('Y'), $count);
            $this->form['test_stage'] = 'prelims';
            $this->form['test_type'] = 'mcq';
            $this->form['test_for'] = 'mock_test';
            $this->form['total_marks'] = 200.00;
            $this->form['pass_marks'] = 66.00;
            $this->form['total_questions'] = 100;
            $this->form['marks_per_question'] = 2.00;
            $this->form['negative_marking_ratio'] = 0.3333;
            $this->form['duration_minutes'] = 120;
            $this->form['instructions'] = "1. General Studies Paper I (Counts towards Prelims Merit Cut-off).\n2. Total Questions: 100 | Maximum Marks: 200.\n3. Each correct answer: +2.00 Marks.\n4. Penalty for wrong answer: 1/3rd (-0.66 Marks).\n5. Unattempted questions carry zero penalty.";

            $this->subjects = [
                [
                    'subject_name' => 'General Studies Paper I',
                    'subject_code' => 'GS-1',
                    'questions_count' => 100,
                    'marks_per_question' => 2.00,
                    'negative_marking' => 0.66,
                    'max_marks' => 200.00,
                ],
            ];
            Toast::dispatch($this, 'info', __('Loaded UPSC Prelims GS Paper I Preset (100 Qs, 200 Marks, +2.00 / -0.66).'));
        } elseif ($preset === 'upsc_csat') {
            $this->form['title'] = "UPSC CSAT / GS Paper II Mock #{$count}";
            $this->form['code'] = sprintf('TEST-CSAT-%s-%04d', date('Y'), $count);
            $this->form['test_stage'] = 'prelims';
            $this->form['test_type'] = 'mcq';
            $this->form['test_for'] = 'mock_test';
            $this->form['total_marks'] = 200.00;
            $this->form['pass_marks'] = 66.67;
            $this->form['total_questions'] = 80;
            $this->form['marks_per_question'] = 2.50;
            $this->form['negative_marking_ratio'] = 0.3333;
            $this->form['duration_minutes'] = 120;
            $this->form['instructions'] = "1. CSAT / General Studies Paper II (Qualifying Nature: Requires ≥ 33% / 66.67 Marks).\n2. Total Questions: 80 | Maximum Marks: 200.\n3. Each correct answer: +2.50 Marks.\n4. Penalty for wrong answer: 1/3rd (-0.83 Marks).\n5. Unattempted questions carry zero penalty.";

            $this->subjects = [
                [
                    'subject_name' => 'CSAT / General Studies Paper II (Qualifying)',
                    'subject_code' => 'CSAT-2',
                    'questions_count' => 80,
                    'marks_per_question' => 2.50,
                    'negative_marking' => 0.83,
                    'max_marks' => 200.00,
                ],
            ];
            Toast::dispatch($this, 'info', __('Loaded UPSC CSAT Paper II Preset (80 Qs, 200 Marks, +2.50 / -0.83, Qualifying ≥33%).'));
        } elseif ($preset === 'upsc_composite') {
            $this->form['title'] = "UPSC Prelims Composite Simulation (GS + CSAT) #{$count}";
            $this->form['code'] = sprintf('TEST-COMP-%s-%04d', date('Y'), $count);
            $this->form['test_stage'] = 'prelims';
            $this->form['test_type'] = 'mcq';
            $this->form['test_for'] = 'full_length_test';
            $this->form['total_marks'] = 200.00;
            $this->form['pass_marks'] = 66.00;
            $this->form['total_questions'] = 90;
            $this->form['marks_per_question'] = 2.00;
            $this->form['negative_marking_ratio'] = 0.3333;
            $this->form['duration_minutes'] = 120;
            $this->form['instructions'] = "1. Composite Simulation: Section A (GS I, 50 Qs @ 2.00 M, -0.66) + Section B (CSAT, 40 Qs @ 2.50 M, -0.83).\n2. Penalty of 1/3rd applies to each section respectively.";

            $this->subjects = [
                [
                    'subject_name' => 'General Studies Paper I',
                    'subject_code' => 'GS-1',
                    'questions_count' => 50,
                    'marks_per_question' => 2.00,
                    'negative_marking' => 0.66,
                    'max_marks' => 100.00,
                ],
                [
                    'subject_name' => 'CSAT Paper II (Aptitude & Reasoning)',
                    'subject_code' => 'CSAT-2',
                    'questions_count' => 40,
                    'marks_per_question' => 2.50,
                    'negative_marking' => 0.83,
                    'max_marks' => 100.00,
                ],
            ];
            Toast::dispatch($this, 'info', __('Loaded UPSC Composite Preset (GS: 50 Qs + CSAT: 40 Qs = 200 Marks).'));
        } elseif ($preset === 'upsc_mains') {
            $this->form['title'] = "UPSC Mains GS Answer Writing Mock #{$count}";
            $this->form['code'] = sprintf('TEST-MAINS-%s-%04d', date('Y'), $count);
            $this->form['test_stage'] = 'mains';
            $this->form['test_type'] = 'answer_writing';
            $this->form['test_for'] = 'sectional_test';
            $this->form['total_marks'] = 250.00;
            $this->form['pass_marks'] = 100.00;
            $this->form['total_questions'] = 20;
            $this->form['marks_per_question'] = 12.50;
            $this->form['negative_marking_ratio'] = 0.00;
            $this->form['duration_minutes'] = 180;
            $this->form['instructions'] = "1. Answer all questions within UPSC prescribed word limits (150 words for 10M, 250 words for 15M).\n2. Write clearly in the allotted space.\n3. Detailed evaluator feedback will be logged on your official marksheet.";

            $this->subjects = [
                [
                    'subject_name' => 'General Studies Mains Paper',
                    'subject_code' => 'GS-MAINS',
                    'questions_count' => 20,
                    'marks_per_question' => 12.50,
                    'negative_marking' => 0.00,
                    'max_marks' => 250.00,
                ],
            ];
            Toast::dispatch($this, 'info', __('Loaded UPSC Mains GS Preset (20 Qs, 250 Marks, 180 Mins).'));
        }
    }

    public function edit(int $id): void
    {
        $this->resetErrorBag();
        $test = StudentTest::with('subjects')->findOrFail($id);
        $this->editingId = $test->id;
        $this->questionPaperFile = null;
        $this->modelAnswerFile = null;

        $this->form = [
            'title' => $test->title,
            'code' => $test->code,
            'batch_id' => $test->batch_id,
            'course_id' => $test->course_id,
            'section_id' => $test->section_id ?? '',
            'test_stage' => $test->test_stage,
            'test_type' => $test->test_type,
            'test_for' => $test->test_for,
            'test_number' => $test->test_number,
            'total_marks' => $test->total_marks,
            'pass_marks' => $test->pass_marks,
            'total_questions' => $test->total_questions,
            'mode' => $test->mode,
            'test_date' => $test->test_date->format('Y-m-d'),
            'start_time' => $test->start_time ?? '10:00 AM',
            'end_time' => $test->end_time ?? '12:00 PM',
            'duration_minutes' => $test->duration_minutes,
            'venue_room' => $test->venue_room ?? '',
            'instructions' => $test->instructions ?? '',
            'negative_marking_ratio' => $test->negative_marking_ratio,
            'marks_per_question' => $test->marks_per_question,
            'notify_students' => false,
            'notification_channels' => $test->notification_channels ?: ['in_app', 'email', 'whatsapp'],
        ];

        $this->subjects = $test->subjects->map(function ($s) {
            return [
                'subject_name' => $s->subject_name,
                'subject_code' => $s->subject_code,
                'questions_count' => $s->questions_count,
                'marks_per_question' => $s->marks_per_question,
                'negative_marking' => $s->negative_marking,
                'max_marks' => $s->max_marks,
            ];
        })->all();

        if (empty($this->subjects)) {
            $this->initDefaultSubjects();
        }

        $this->dispatch('modal-open', name: 'test-form-modal');
    }

    public function save(StudentTestService $testService): void
    {
        $this->validate([
            'form.title' => 'required|string|max:200',
            'form.code' => 'required|string|max:50|unique:student_tests,code,' . ($this->editingId ?? 'NULL'),
            'form.batch_id' => 'required|exists:batches,id',
            'form.course_id' => 'required|exists:courses,id',
            'form.section_id' => 'nullable|exists:sections,id',
            'form.test_stage' => 'required|in:prelims,mains,interview_prep',
            'form.test_type' => 'required|in:mcq,descriptive,essay,answer_writing',
            'form.test_for' => 'required|in:class_test,mock_test,live_test,full_length_test,sectional_test',
            'form.test_number' => 'required|integer|min:1',
            'form.total_marks' => 'required|numeric|min:1',
            'form.pass_marks' => 'nullable|numeric|min:0',
            'form.total_questions' => 'required|integer|min:0',
            'form.mode' => 'required|in:offline,online,hybrid',
            'form.test_date' => 'required|date',
            'form.start_time' => 'nullable|string',
            'form.end_time' => 'nullable|string',
            'form.duration_minutes' => 'required|integer|min:10',
            'form.venue_room' => 'nullable|string|max:100',
            'questionPaperFile' => 'nullable|file|mimes:pdf,doc,docx|max:20480',
            'modelAnswerFile' => 'nullable|file|mimes:pdf,doc,docx|max:20480',
        ]);

        $payload = $this->form;
        if (empty($payload['section_id'])) {
            $payload['section_id'] = null;
        }

        try {
            if ($this->editingId) {
                $test = StudentTest::findOrFail($this->editingId);
                $testService->updateTest($test, $payload, $this->subjects, $this->questionPaperFile, $this->modelAnswerFile);
                Toast::dispatch($this, 'success', __('Examination ":title" updated successfully.', ['title' => $test->title]));
            } else {
                $test = $testService->createTest($payload, $this->subjects, $this->questionPaperFile, $this->modelAnswerFile);
                Toast::dispatch($this, 'success', __('Examination ":title" scheduled and enrolled students registered.', ['title' => $test->title]));
            }

            $this->dispatch('modal-close', name: 'test-form-modal');
            $this->reset(['editingId', 'questionPaperFile', 'modelAnswerFile']);
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save examination: :err', ['err' => $e->getMessage()]));
        }
    }

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->dispatch('modal-open', name: 'test-delete-modal');
    }

    public function deleteSelected(StudentTestService $testService): void
    {
        if (! $this->deleteId) {
            return;
        }

        try {
            $test = StudentTest::findOrFail($this->deleteId);
            $testService->deleteTest($test);
            Toast::dispatch($this, 'success', __('Examination deleted successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete examination: :err', ['err' => $e->getMessage()]));
        } finally {
            $this->deleteId = null;
            $this->dispatch('modal-close', name: 'test-delete-modal');
        }
    }

    public function promptNotifyStudents(int $testId): void
    {
        $this->notifyTestId = $testId;
        $this->dispatch('modal-open', name: 'test-notify-modal');
    }

    public function dispatchNotification(StudentTestService $testService): void
    {
        if (! $this->notifyTestId) {
            return;
        }

        try {
            $test = StudentTest::findOrFail($this->notifyTestId);
            $count = $testService->notifyEnrolledStudents($test);
            Toast::dispatch($this, 'success', __('Notifications dispatched to :count enrolled students.', ['count' => $count]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to dispatch notification: :err', ['err' => $e->getMessage()]));
        } finally {
            $this->notifyTestId = null;
            $this->dispatch('modal-close', name: 'test-notify-modal');
        }
    }

    public function exportResults(int $testId, string $format, TestImportExportService $exportService)
    {
        $test = StudentTest::findOrFail($testId);
        return $exportService->exportTestResults($test, $format);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Mock Tests & Examinations') }}</h1>
                <x-ui.badge variant="primary" size="sm">{{ __('UPSC CSE MIS') }}</x-ui.badge>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Schedule mock tests, sectional exams, assign subjects, upload papers & model answers, and track candidate results.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.button variant="outline" size="sm" href="{{ route('admin.tests.reports') }}" wire:navigate>
                <x-icon name="bar-chart-3" class="w-4 h-4 mr-1.5 text-primary" />
                {{ __('Performance Reports') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" href="{{ route('admin.tests.leaderboard') }}" wire:navigate>
                <x-icon name="trophy" class="w-4 h-4 mr-1.5 text-amber-500" />
                {{ __('Leaderboard') }}
            </x-ui.button>

            <x-ui.button variant="primary" size="sm" wire:click="openCreateModal">
                <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                {{ __('Schedule New Test') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <x-ui.stat-card
            icon="clipboard-list"
            color="primary"
            :label="__('Tests Conducted')"
            :value="$this->statistics['total_tests']"
        />
        <x-ui.stat-card
            icon="check-circle-2"
            color="emerald"
            :label="__('Evaluated / Completed')"
            :value="$this->statistics['completed_tests']"
        />
        <x-ui.stat-card
            icon="users"
            color="sky"
            :label="__('Candidates Evaluated')"
            :value="number_format($this->statistics['total_evaluations'])"
        />
        <x-ui.stat-card
            icon="percent"
            color="violet"
            :label="__('Overall Avg Score')"
            :value="$this->statistics['avg_score'] . '%'"
        />
    </div>

    <!-- Search & Filter Controls -->
    <div class="rounded-lg border border-border bg-card p-4 space-y-3 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2.5">
            <!-- Search -->
            <div class="lg:col-span-2 relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search test title, code, venue...') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-3 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <!-- Batch Filter -->
            <div>
                <select wire:model.live="batchFilter" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                    <option value="">{{ __('All Batches') }}</option>
                    @foreach($this->batches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Course Filter -->
            <div>
                <select wire:model.live="courseFilter" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                    <option value="">{{ __('All Courses') }}</option>
                    @foreach($this->courses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                    @endforeach
                </select>
            </div>

            <!-- Stage Filter -->
            <div>
                <select wire:model.live="stageFilter" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                    <option value="">{{ __('All Stages') }}</option>
                    <option value="prelims">{{ __('Prelims') }}</option>
                    <option value="mains">{{ __('Mains') }}</option>
                    <option value="interview_prep">{{ __('Interview / Personality') }}</option>
                </select>
            </div>

            <!-- Type Filter -->
            <div>
                <select wire:model.live="typeFilter" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground">
                    <option value="">{{ __('All Types') }}</option>
                    <option value="mcq">{{ __('MCQ (Objective)') }}</option>
                    <option value="descriptive">{{ __('Descriptive') }}</option>
                    <option value="essay">{{ __('Essay Paper') }}</option>
                    <option value="answer_writing">{{ __('Answer Writing') }}</option>
                </select>
            </div>
        </div>

        @if($courseFilter && $this->filterSections->isNotEmpty())
            <div class="flex items-center gap-2 pt-2 border-t border-border/60 text-xs">
                <span class="text-muted-foreground font-medium">{{ __('Section Filter:') }}</span>
                <button
                    type="button"
                    wire:click="$set('sectionFilter', '')"
                    class="px-2.5 py-1 rounded-md text-xs font-medium transition-colors {{ $sectionFilter === '' ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80' }}"
                >
                    {{ __('All Sections') }}
                </button>
                @foreach($this->filterSections as $sec)
                    <button
                        type="button"
                        wire:click="$set('sectionFilter', '{{ $sec->id }}')"
                        class="px-2.5 py-1 rounded-md text-xs font-medium transition-colors {{ (string) $sectionFilter === (string) $sec->id ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80' }}"
                    >
                        {{ $sec->name }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Desktop Table -->
    <div class="hidden md:block rounded-lg border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('code')">
                            <div class="flex items-center gap-1.5">
                                {{ __('Test Code / Info') }}
                                <x-icon name="chevrons-up-down" class="w-3.5 h-3.5 opacity-60" />
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Course & Batch / Section') }}</th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('test_date')">
                            <div class="flex items-center gap-1.5">
                                {{ __('Schedule & Mode') }}
                                <x-icon name="chevrons-up-down" class="w-3.5 h-3.5 opacity-60" />
                            </div>
                        </th>
                        <th class="px-4 py-3 text-center">{{ __('Questions & Marks') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('Candidate Turnout') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('Files') }}</th>
                        <th class="px-4 py-3 text-center cursor-pointer select-none" wire:click="sortBy('status')">
                            <div class="flex items-center justify-center gap-1.5">
                                {{ __('Status') }}
                                <x-icon name="chevrons-up-down" class="w-3.5 h-3.5 opacity-60" />
                            </div>
                        </th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse ($this->tests as $test)
                        <tr class="hover:bg-muted/30 transition-colors" wire:key="test-row-{{ $test->id }}">
                            <!-- Test Code / Info -->
                            <td class="px-4 py-3.5 align-top">
                                <div class="font-bold text-foreground text-sm flex items-center gap-1.5">
                                    {{ $test->title }}
                                </div>
                                <div class="flex items-center gap-1.5 mt-1">
                                    <span class="font-mono text-xs text-primary font-semibold bg-primary/10 px-1.5 py-0.5 rounded">
                                        {{ $test->code }}
                                    </span>
                                    <x-ui.badge variant="{{ $test->test_stage === 'prelims' ? 'primary' : 'warning' }}" size="sm">
                                        {{ strtoupper($test->test_stage) }}
                                    </x-ui.badge>
                                    <x-ui.badge variant="secondary" size="sm">
                                        {{ strtoupper(str_replace('_', ' ', $test->test_type)) }}
                                    </x-ui.badge>
                                </div>
                                @if($test->subjects->isNotEmpty())
                                    <div class="mt-1 text-[11px] text-muted-foreground">
                                        {{ $test->subjects->pluck('subject_name')->implode(' + ') }}
                                    </div>
                                @endif
                            </td>

                            <!-- Course & Section -->
                            <td class="px-4 py-3.5 align-top">
                                <div class="font-medium text-foreground text-xs">{{ $test->course?->name }}</div>
                                <div class="text-[11px] text-muted-foreground mt-0.5 flex items-center gap-1">
                                    <span>{{ $test->batch?->name }}</span>
                                    <span>&bull;</span>
                                    @if($test->section)
                                        <span class="font-semibold text-primary">Sec {{ $test->section->name }}</span>
                                    @else
                                        <span class="text-muted-foreground italic">{{ __('All Sections') }}</span>
                                    @endif
                                </div>
                            </td>

                            <!-- Schedule & Mode -->
                            <td class="px-4 py-3.5 align-top">
                                <div class="text-xs font-semibold text-foreground flex items-center gap-1">
                                    <x-icon name="calendar" class="w-3.5 h-3.5 text-muted-foreground" />
                                    {{ $test->test_date->format('d M Y') }} ({{ $test->test_day ?: $test->test_date->format('D') }})
                                </div>
                                <div class="text-[11px] text-muted-foreground mt-0.5 flex items-center gap-1">
                                    <x-icon name="clock" class="w-3 h-3" />
                                    <span>{{ $test->start_time ?: '10:00 AM' }} ({{ $test->duration_minutes }}m)</span>
                                </div>
                                <div class="text-[10px] text-muted-foreground mt-0.5 uppercase tracking-wide">
                                    <span class="font-medium text-foreground">{{ strtoupper($test->mode) }}</span>
                                    @if($test->venue_room) &bull; {{ $test->venue_room }} @endif
                                </div>
                            </td>

                            <!-- Questions & Marks -->
                            <td class="px-4 py-3.5 align-top text-center">
                                <div class="text-xs font-bold text-foreground">
                                    {{ $test->total_marks }} <span class="text-[10px] font-normal text-muted-foreground">{{ __('Marks') }}</span>
                                </div>
                                <div class="text-[11px] text-muted-foreground mt-0.5">
                                    {{ $test->total_questions }} {{ __('Questions') }}
                                </div>
                                @if($test->isMcq())
                                    <div class="text-[10px] text-destructive/80 mt-0.5">
                                        -{{ round($test->marks_per_question * $test->negative_marking_ratio, 2) }} {{ __('neg/wrong') }}
                                    </div>
                                @endif
                            </td>

                            <!-- Candidate Turnout -->
                            <td class="px-4 py-3.5 align-top text-center">
                                <div class="text-xs font-bold text-foreground">
                                    {{ $test->attended_count }} / {{ $test->enrolled_count }}
                                </div>
                                <div class="w-20 bg-secondary/80 rounded-full h-1.5 mx-auto mt-1 overflow-hidden">
                                    <div
                                        class="bg-emerald-500 h-1.5 rounded-full"
                                        style="width: {{ $test->enrolled_count > 0 ? min(100, round(($test->attended_count / $test->enrolled_count) * 100)) : 0 }}%"
                                    ></div>
                                </div>
                                <div class="text-[10px] text-muted-foreground mt-0.5">
                                    {{ $test->enrolled_count > 0 ? round(($test->attended_count / $test->enrolled_count) * 100) : 0 }}% {{ __('Turnout') }}
                                </div>
                            </td>

                            <!-- Question Paper & Solutions -->
                            <td class="px-4 py-3.5 align-top text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    @if($test->question_paper_path)
                                        <a
                                            href="{{ route('tests.download-paper', $test) }}"
                                            target="_blank"
                                            class="p-1 rounded bg-secondary hover:bg-secondary/80 text-foreground transition-colors"
                                            title="{{ __('Download Question Paper') }}"
                                        >
                                            <x-icon name="file-text" class="w-3.5 h-3.5 text-primary" />
                                        </a>
                                    @else
                                        <span class="text-[10px] text-muted-foreground italic">—</span>
                                    @endif

                                    @if($test->model_answer_path)
                                        <a
                                            href="{{ route('tests.download-solution', $test) }}"
                                            target="_blank"
                                            class="p-1 rounded bg-secondary hover:bg-secondary/80 text-foreground transition-colors"
                                            title="{{ __('Download Model Answers') }}"
                                        >
                                            <x-icon name="check-square" class="w-3.5 h-3.5 text-emerald-500" />
                                        </a>
                                    @endif
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3.5 align-top text-center">
                                @if($test->status === 'completed')
                                    <x-ui.badge variant="success" size="sm">{{ __('Completed') }}</x-ui.badge>
                                @elseif($test->status === 'ongoing')
                                    <x-ui.badge variant="warning" size="sm">{{ __('Ongoing') }}</x-ui.badge>
                                @elseif($test->status === 'cancelled')
                                    <x-ui.badge variant="destructive" size="sm">{{ __('Cancelled') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="primary" size="sm">{{ __('Scheduled') }}</x-ui.badge>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3.5 align-top text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <!-- Results / Marksheets Button -->
                                    <x-ui.button
                                        variant="primary"
                                        size="xs"
                                        href="{{ route('admin.tests.results', $test) }}"
                                        wire:navigate
                                        title="{{ __('Manage Marks & Results') }}"
                                    >
                                        <x-icon name="award" class="w-3 h-3 mr-1" />
                                        {{ __('Results') }}
                                    </x-ui.button>

                                    <!-- Quick Notify -->
                                    <button
                                        type="button"
                                        wire:click="promptNotifyStudents({{ $test->id }})"
                                        class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors"
                                        title="{{ __('Notify Enrolled Students') }}"
                                    >
                                        <x-icon name="send" class="w-3.5 h-3.5" />
                                    </button>

                                    <!-- Export CSV -->
                                    <button
                                        type="button"
                                        wire:click="exportResults({{ $test->id }}, 'csv')"
                                        class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors"
                                        title="{{ __('Export Results CSV') }}"
                                    >
                                        <x-icon name="download" class="w-3.5 h-3.5" />
                                    </button>

                                    <!-- Edit -->
                                    <button
                                        type="button"
                                        wire:click="edit({{ $test->id }})"
                                        class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors"
                                        title="{{ __('Edit Test Details') }}"
                                    >
                                        <x-icon name="pencil" class="w-3.5 h-3.5" />
                                    </button>

                                    <!-- Delete -->
                                    <button
                                        type="button"
                                        wire:click="selectForDelete({{ $test->id }})"
                                        class="p-1.5 rounded-md hover:bg-destructive/10 text-muted-foreground hover:text-destructive transition-colors"
                                        title="{{ __('Delete Test') }}"
                                    >
                                        <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="clipboard-x" class="mx-auto h-10 w-10 text-muted-foreground/60 mb-2" />
                                <p class="text-sm font-semibold text-foreground">{{ __('No examination schedules found') }}</p>
                                <p class="text-xs text-muted-foreground mt-1">{{ __('Try adjusting your search criteria or schedule a new examination.') }}</p>
                                <div class="mt-4">
                                    <x-ui.button variant="primary" size="sm" wire:click="openCreateModal">
                                        <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                                        {{ __('Schedule First Examination') }}
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->tests->hasPages())
            <div class="px-4 py-3 border-t border-border">
                {{ $this->tests->links() }}
            </div>
        @endif
    </div>

    <!-- Mobile Card View -->
    <div class="md:hidden space-y-3">
        @forelse ($this->tests as $test)
            <div class="rounded-lg border border-border bg-card p-4 shadow-xs space-y-3" wire:key="test-mobile-{{ $test->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="font-mono text-xs text-primary font-semibold bg-primary/10 px-1.5 py-0.5 rounded">
                                {{ $test->code }}
                            </span>
                            <x-ui.badge variant="{{ $test->test_stage === 'prelims' ? 'primary' : 'warning' }}" size="sm">
                                {{ strtoupper($test->test_stage) }}
                            </x-ui.badge>
                        </div>
                        <h3 class="font-bold text-foreground text-sm mt-1">{{ $test->title }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ $test->course?->name }} &bull; {{ $test->batch?->name }}
                            @if($test->section) (Sec {{ $test->section->name }}) @endif
                        </p>
                    </div>

                    <x-ui.badge variant="{{ $test->status === 'completed' ? 'success' : 'primary' }}" size="sm">
                        {{ ucfirst($test->status) }}
                    </x-ui.badge>
                </div>

                <div class="grid grid-cols-2 gap-2 text-xs border-y border-border/60 py-2.5">
                    <div>
                        <span class="text-muted-foreground block text-[10px] uppercase font-bold">{{ __('Date & Time') }}</span>
                        <span class="font-semibold text-foreground">{{ $test->test_date->format('d M Y') }}</span>
                        <span class="text-muted-foreground block text-[11px]">{{ $test->start_time }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block text-[10px] uppercase font-bold">{{ __('Structure') }}</span>
                        <span class="font-semibold text-foreground">{{ $test->total_marks }} Marks</span>
                        <span class="text-muted-foreground block text-[11px]">{{ $test->total_questions }} Questions</span>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <div class="text-xs text-muted-foreground">
                        <span class="font-bold text-foreground">{{ $test->attended_count }}</span> / {{ $test->enrolled_count }} attended
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button
                            variant="primary"
                            size="xs"
                            href="{{ route('admin.tests.results', $test) }}"
                            wire:navigate
                        >
                            <x-icon name="award" class="w-3 h-3 mr-1" />
                            {{ __('Results') }}
                        </x-ui.button>
                        <button
                            type="button"
                            wire:click="edit({{ $test->id }})"
                            class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground"
                        >
                            <x-icon name="pencil" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground text-sm">
                {{ __('No examinations scheduled.') }}
            </div>
        @endforelse

        @if($this->tests->hasPages())
            <div>
                {{ $this->tests->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Test Modal -->
    <x-ui.modal
        name="test-form-modal"
        max-width="max-w-4xl"
        :title="$editingId ? __('Edit Examination Schedule') : __('Schedule New Mock Test / Examination')"
        :description="__('Configure UPSC test parameters, assign composite subjects, upload questions & solutions, and alert students.')"
    >
        <form wire:submit.prevent="save" class="space-y-4">
            <!-- Official UPSC Presets Toolbar -->
            <div class="p-3 bg-secondary/40 rounded-lg border border-border/70 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-foreground flex items-center gap-1.5">
                        <x-icon name="target" class="w-3.5 h-3.5 text-primary" />
                        {{ __('Official UPSC Exam Presets') }}
                    </span>
                    <span class="text-[10px] text-muted-foreground">{{ __('Click to load official UPSC marking schemes & structures') }}</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    <button
                        type="button"
                        wire:click="applyPreset('upsc_gs1')"
                        class="p-2 rounded-md border border-border bg-card hover:border-primary hover:bg-primary/5 text-left transition-all group"
                    >
                        <div class="text-xs font-bold text-foreground group-hover:text-primary flex items-center justify-between">
                            <span>🏛️ GS Paper I</span>
                            <span class="text-[9px] font-mono bg-primary/10 text-primary px-1 rounded">+2.00</span>
                        </div>
                        <div class="text-[10px] text-muted-foreground mt-0.5">100 Qs &bull; 200M &bull; -0.66 Neg</div>
                    </button>
                    <button
                        type="button"
                        wire:click="applyPreset('upsc_csat')"
                        class="p-2 rounded-md border border-border bg-card hover:border-amber-500 hover:bg-amber-500/5 text-left transition-all group"
                    >
                        <div class="text-xs font-bold text-foreground group-hover:text-amber-500 flex items-center justify-between">
                            <span>📐 CSAT Paper II</span>
                            <span class="text-[9px] font-mono bg-amber-500/10 text-amber-600 font-bold px-1 rounded">≥33%</span>
                        </div>
                        <div class="text-[10px] text-muted-foreground mt-0.5">80 Qs &bull; 200M &bull; +2.50 / -0.83</div>
                    </button>
                    <button
                        type="button"
                        wire:click="applyPreset('upsc_composite')"
                        class="p-2 rounded-md border border-border bg-card hover:border-primary hover:bg-primary/5 text-left transition-all group"
                    >
                        <div class="text-xs font-bold text-foreground group-hover:text-primary">⚖️ Composite GS+CSAT</div>
                        <div class="text-[10px] text-muted-foreground mt-0.5">50 GS + 40 CSAT = 200M</div>
                    </button>
                    <button
                        type="button"
                        wire:click="applyPreset('upsc_mains')"
                        class="p-2 rounded-md border border-border bg-card hover:border-primary hover:bg-primary/5 text-left transition-all group"
                    >
                        <div class="text-xs font-bold text-foreground group-hover:text-primary">✍️ Mains Answer Writing</div>
                        <div class="text-[10px] text-muted-foreground mt-0.5">20 Qs &bull; 250M &bull; 180 Mins</div>
                    </button>
                </div>
            </div>

            <!-- Basic Details -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Test Title') }} <span class="text-destructive">*</span></label>
                    <input
                        type="text"
                        wire:model="form.title"
                        placeholder="e.g. UPSC Prelims Mock Test #4 - GS & CSAT"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    @error('form.title') <span class="text-xs text-destructive mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Test Code') }} <span class="text-destructive">*</span></label>
                    <input
                        type="text"
                        wire:model="form.code"
                        placeholder="e.g. TEST-2026-0004"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm font-mono shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    @error('form.code') <span class="text-xs text-destructive mt-1">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Stage, Type, Test For, Test Number -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-secondary/30 p-3 rounded-lg border border-border/60">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Stage') }} <span class="text-destructive">*</span></label>
                    <select wire:model="form.test_stage" class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-xs shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="prelims">{{ __('Prelims') }}</option>
                        <option value="mains">{{ __('Mains') }}</option>
                        <option value="interview_prep">{{ __('Interview Prep') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Type') }} <span class="text-destructive">*</span></label>
                    <select wire:model="form.test_type" class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-xs shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="mcq">{{ __('MCQ (Objective)') }}</option>
                        <option value="descriptive">{{ __('Descriptive') }}</option>
                        <option value="essay">{{ __('Essay') }}</option>
                        <option value="answer_writing">{{ __('Answer Writing') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Test Purpose') }} <span class="text-destructive">*</span></label>
                    <select wire:model="form.test_for" class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-xs shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="mock_test">{{ __('Mock Test') }}</option>
                        <option value="class_test">{{ __('Class Test') }}</option>
                        <option value="full_length_test">{{ __('Full Length Test') }}</option>
                        <option value="sectional_test">{{ __('Sectional Test') }}</option>
                        <option value="live_test">{{ __('Live Test') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Test Number') }} <span class="text-destructive">*</span></label>
                    <input
                        type="number"
                        wire:model="form.test_number"
                        min="1"
                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>
            </div>

            <!-- Batch, Course, Section Target -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Academic Batch') }} <span class="text-destructive">*</span></label>
                    <select wire:model="form.batch_id" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="">{{ __('Select Batch') }}</option>
                        @foreach($this->batches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                        @endforeach
                    </select>
                    @error('form.batch_id') <span class="text-xs text-destructive mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Course') }} <span class="text-destructive">*</span></label>
                    <select wire:model.live="form.course_id" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="">{{ __('Select Course') }}</option>
                        @foreach($this->courses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                    @error('form.course_id') <span class="text-xs text-destructive mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Section') }}</label>
                    <select wire:model="form.section_id" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="">{{ __('All Sections of this Course') }}</option>
                        @foreach($this->availableSections as $sec)
                            <option value="{{ $sec->id }}">{{ $sec->name }} (Sec {{ $sec->code }})</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('Leave empty if test applies to all classroom sections.') }}</p>
                </div>
            </div>

            <!-- Subject-Wise Question Breakdown (Dynamic Subjects Assignment) -->
            <div class="border border-border rounded-lg p-3 space-y-2 bg-muted/20">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-foreground">{{ __('Subject Breakdown & Question Sets') }}</h4>
                        <p class="text-[11px] text-muted-foreground">{{ __('Define single or composite subjects (e.g. GS: 50 Qs + CSAT: 50 Qs = 100 Qs total).') }}</p>
                    </div>
                    <x-ui.button variant="outline" size="xs" type="button" wire:click="addSubjectRow">
                        <x-icon name="plus" class="w-3 h-3 mr-1" />
                        {{ __('Add Subject') }}
                    </x-ui.button>
                </div>

                <div class="space-y-2 pt-1">
                    @foreach($subjects as $index => $subj)
                        <div class="grid grid-cols-12 gap-2 items-center bg-card p-2 rounded-md border border-border/60" wire:key="subj-row-{{ $index }}">
                            <div class="col-span-12 sm:col-span-4">
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="subjects.{{ $index }}.subject_name"
                                    placeholder="{{ __('Subject Name (e.g. General Studies)') }}"
                                    class="h-8 w-full rounded border border-input bg-transparent px-2 text-xs shadow-xs"
                                />
                            </div>
                            <div class="col-span-4 sm:col-span-2">
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="subjects.{{ $index }}.subject_code"
                                    placeholder="{{ __('Code (GS-1)') }}"
                                    class="h-8 w-full rounded border border-input bg-transparent px-2 text-xs font-mono shadow-xs"
                                />
                            </div>
                            <div class="col-span-4 sm:col-span-2">
                                <input
                                    type="number"
                                    wire:model.live.debounce.300ms="subjects.{{ $index }}.questions_count"
                                    placeholder="{{ __('Questions') }}"
                                    class="h-8 w-full rounded border border-input bg-transparent px-2 text-xs shadow-xs text-center"
                                />
                            </div>
                            <div class="col-span-4 sm:col-span-3">
                                <div class="text-[11px] text-muted-foreground flex items-center gap-1">
                                    <span>Max:</span>
                                    <span class="font-bold text-foreground">{{ $subj['max_marks'] ?? 0 }} M</span>
                                </div>
                            </div>
                            <div class="col-span-12 sm:col-span-1 text-right">
                                @if(count($subjects) > 1)
                                    <button
                                        type="button"
                                        wire:click="removeSubjectRow({{ $index }})"
                                        class="p-1 rounded text-destructive hover:bg-destructive/10"
                                        title="{{ __('Remove Subject') }}"
                                    >
                                        <x-icon name="x" class="w-3.5 h-3.5" />
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Marks & Schedule -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Maximum Marks') }} <span class="text-destructive">*</span></label>
                    <input
                        type="number"
                        step="0.5"
                        wire:model="form.total_marks"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 font-bold"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Qualifying / Pass Marks') }}</label>
                    <input
                        type="number"
                        step="0.5"
                        wire:model="form.pass_marks"
                        placeholder="Optional"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Total Questions') }}</label>
                    <input
                        type="number"
                        wire:model="form.total_questions"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Mode of Test') }}</label>
                    <select wire:model="form.mode" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="offline">{{ __('Offline (Classroom OMR)') }}</option>
                        <option value="online">{{ __('Online CBT') }}</option>
                        <option value="hybrid">{{ __('Hybrid') }}</option>
                    </select>
                </div>
            </div>

            <!-- Schedule Timing & Venue -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 bg-secondary/20 p-3 rounded-lg border border-border/60">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Test Date') }} <span class="text-destructive">*</span></label>
                    <input
                        type="date"
                        wire:model="form.test_date"
                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    @error('form.test_date') <span class="text-xs text-destructive mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Time') }}</label>
                    <input
                        type="text"
                        wire:model="form.start_time"
                        placeholder="10:00 AM"
                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Time') }}</label>
                    <input
                        type="text"
                        wire:model="form.end_time"
                        placeholder="12:00 PM"
                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Duration (Mins)') }}</label>
                    <input
                        type="number"
                        wire:model="form.duration_minutes"
                        min="10"
                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>
            </div>

            <!-- Venue / Room -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Venue / Classroom / Online Link') }}</label>
                <input
                    type="text"
                    wire:model="form.venue_room"
                    placeholder="e.g. SNTCSSC Salt Lake Campus - Hall 3 / Online Portal"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <!-- File Uploads: Question Paper & Model Answer -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border border-border rounded-lg p-3 bg-muted/10">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Question Paper (PDF / DOC)') }}
                    </label>
                    <input
                        type="file"
                        wire:model="questionPaperFile"
                        accept=".pdf,.doc,.docx"
                        class="text-xs text-muted-foreground file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                    />
                    <p class="text-[10px] text-muted-foreground mt-1">
                        {{ __('Students can download this after the test concludes.') }}
                    </p>
                    <div wire:loading wire:target="questionPaperFile" class="text-xs text-primary mt-1">
                        {{ __('Uploading question paper...') }}
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Model Answer & Explanations (PDF)') }}
                    </label>
                    <input
                        type="file"
                        wire:model="modelAnswerFile"
                        accept=".pdf,.doc,.docx"
                        class="text-xs text-muted-foreground file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-emerald-600 file:text-white hover:file:bg-emerald-700 cursor-pointer"
                    />
                    <p class="text-[10px] text-muted-foreground mt-1">
                        {{ __('Available for self-evaluation to all enrolled students once test is over.') }}
                    </p>
                    <div wire:loading wire:target="modelAnswerFile" class="text-xs text-emerald-600 mt-1">
                        {{ __('Uploading model answer key...') }}
                    </div>
                </div>
            </div>

            <!-- Notification to Students Toggle -->
            <div class="rounded-lg border border-border p-3 bg-card space-y-2">
                <div class="flex items-center justify-between">
                    <div>
                        <label class="text-xs font-bold text-foreground cursor-pointer flex items-center gap-2">
                            <input
                                type="checkbox"
                                wire:model="form.notify_students"
                                class="rounded border-input text-primary focus:ring-primary h-4 w-4"
                            />
                            {{ __('Notify Enrolled Students on Schedule / Publish') }}
                        </label>
                        <p class="text-[11px] text-muted-foreground ml-6">
                            {{ __('Broadcast date, timing, and syllabus instructions to all active candidates of this batch/section.') }}
                        </p>
                    </div>
                </div>

                @if($form['notify_students'])
                    <div class="flex flex-wrap items-center gap-4 ml-6 pt-2 border-t border-border/60 text-xs">
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" wire:model="form.notification_channels" value="in_app" class="rounded text-primary h-3.5 w-3.5" />
                            <span>{{ __('In-App Portal') }}</span>
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" wire:model="form.notification_channels" value="email" class="rounded text-primary h-3.5 w-3.5" />
                            <span>{{ __('Email Notice') }}</span>
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" wire:model="form.notification_channels" value="whatsapp" class="rounded text-primary h-3.5 w-3.5" />
                            <span class="text-emerald-500 font-medium">{{ __('WhatsApp Message') }}</span>
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" wire:model="form.notification_channels" value="sms" class="rounded text-primary h-3.5 w-3.5" />
                            <span>{{ __('SMS') }}</span>
                        </label>
                    </div>
                @endif
            </div>

            <!-- Instructions -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Examination Instructions for Candidates') }}</label>
                <textarea
                    wire:model="form.instructions"
                    rows="3"
                    placeholder="Enter guidelines, pen requirements, negative marking rules..."
                    class="w-full rounded-md border border-input bg-transparent p-2.5 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                ></textarea>
            </div>

            <!-- Modal Action Buttons -->
            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('test-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="primary" type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ $editingId ? __('Update Examination') : __('Schedule Examination') }}</span>
                    <span wire:loading>{{ __('Processing...') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal
        name="test-delete-modal"
        max-width="max-w-md"
        :title="__('Confirm Examination Deletion')"
        :description="__('This action will soft-delete the examination record and candidate score history.')"
    >
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-destructive/10 text-destructive">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ __('Are you sure you want to delete this test? Associated score records and ranking entries will be archived.') }}
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('test-delete-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" wire:click="deleteSelected">
                    {{ __('Delete Test') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Notify Confirmation Modal -->
    <x-ui.modal
        name="test-notify-modal"
        max-width="max-w-md"
        :title="__('Broadcast Examination Alert')"
        :description="__('Send instant reminders to all enrolled students for this examination.')"
    >
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-primary/10 text-primary">
                    <x-icon name="send" class="w-6 h-6" />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ __('Are you sure you want to send multi-channel notifications (In-app, WhatsApp, Email) to candidates registered for this exam?') }}
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('test-notify-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="dispatchNotification">
                    {{ __('Send Broadcast Now') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

