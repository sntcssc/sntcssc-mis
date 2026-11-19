<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Test\TestImportExportService;
use App\Services\Test\TestReportService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Examinations & Performance Analytics')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'tab')]
    public string $activeTab = 'test_wise'; // 'test_wise', 'student_wise', 'participation', 'sections'

    // Tab 1: Test-wise filters & state
    public ?int $selectedTestId = null;
    public string $testWiseSearch = '';
    public ?int $testWiseSectionId = null;
    public string $testWiseAttendance = '';
    public string $testWisePassStatus = '';
    public string $testWiseSortField = 'rank';
    public string $testWiseSortDirection = 'asc';
    public $importFile = null;

    // Tab 2: Student-wise filters & state
    public string $studentSearch = '';
    public ?int $selectedStudentId = null;
    public string $studentStage = '';
    public string $studentType = '';
    public string $studentAttendance = '';
    public string $studentSortField = 'date';
    public string $studentSortDirection = 'desc';

    // Tab 3: Participation filters & state
    public ?int $partBatchId = null;
    public ?int $partCourseId = null;
    public ?int $partSectionId = null;
    public string $partSearch = '';
    public string $partCategory = 'all'; // 'all', 'champions', 'absentees', 'max', 'least'

    // Tab 4: Section comparison & state
    public ?int $compCourseId = null;
    public ?int $compBatchId = null;
    public string $sectionSearch = '';
    public string $sectionSortField = 'name';
    public string $sectionSortDirection = 'asc';

    public function mount(): void
    {
        $latestTest = StudentTest::where('status', StudentTest::STATUS_COMPLETED)->latest('test_date')->first()
            ?? StudentTest::latest('test_date')->first();

        $this->selectedTestId = $latestTest?->id;

        $firstCourse = Course::first();
        $this->compCourseId = $firstCourse?->id;

        $firstStudent = User::whereHas('studentTestResults')->first()
            ?? User::whereHas('enrollments')->first();

        $this->selectedStudentId = $firstStudent?->id;
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
    public function sections()
    {
        return Section::orderBy('name')->get();
    }

    #[Computed]
    public function tests()
    {
        return StudentTest::with(['batch', 'course'])->orderByDesc('test_date')->get();
    }

    #[Computed]
    public function selectedTest()
    {
        if (! $this->selectedTestId) {
            return null;
        }

        return StudentTest::with(['batch', 'course', 'section', 'subjects'])->find($this->selectedTestId);
    }

    #[Computed]
    public function testWiseStats(): ?array
    {
        if (! $this->selectedTest) {
            return null;
        }

        return app(TestReportService::class)->getTestPerformanceStats($this->selectedTest);
    }

    #[Computed]
    public function testWiseResults()
    {
        if (! $this->selectedTestId) {
            return collect();
        }

        return StudentTestResult::with(['user.studentProfile', 'section', 'evaluator'])
            ->where('student_test_id', $this->selectedTestId)
            ->when($this->testWiseSearch, function (Builder $q) {
                $s = '%' . trim($this->testWiseSearch) . '%';
                $q->where(function ($sub) use ($s) {
                    $sub->where('roll_number', 'like', $s)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $s)->orWhere('email', 'like', $s));
                });
            })
            ->when($this->testWiseSectionId, fn (Builder $q) => $q->where('section_id', $this->testWiseSectionId))
            ->when($this->testWiseAttendance, fn (Builder $q) => $q->where('attendance_status', $this->testWiseAttendance))
            ->when($this->testWisePassStatus === 'passed', fn (Builder $q) => $q->where('is_passed', true))
            ->when($this->testWisePassStatus === 'failed', fn (Builder $q) => $q->where('is_passed', false))
            ->when($this->testWiseSortField === 'rank', function (Builder $q) {
                $dir = $this->testWiseSortDirection === 'desc' ? 'DESC' : 'ASC';
                $q->orderByRaw("CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END, `rank` {$dir}, marks_obtained DESC");
            })
            ->when($this->testWiseSortField === 'marks', fn (Builder $q) => $q->orderBy('marks_obtained', $this->testWiseSortDirection))
            ->when($this->testWiseSortField === 'percentage', fn (Builder $q) => $q->orderBy('percentage', $this->testWiseSortDirection))
            ->when($this->testWiseSortField === 'roll_number', fn (Builder $q) => $q->orderBy('roll_number', $this->testWiseSortDirection))
            ->get();
    }

    #[Computed]
    public function studentSearchResults()
    {
        if (strlen($this->studentSearch) < 2) {
            return collect();
        }

        $s = '%' . trim($this->studentSearch) . '%';

        return User::whereHas('enrollments')
            ->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                    ->orWhere('student_id', 'like', $s)
                    ->orWhere('email', 'like', $s);
            })
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function studentReport(): ?array
    {
        if (! $this->selectedStudentId) {
            return null;
        }

        return app(TestReportService::class)->getStudentOverallReport($this->selectedStudentId);
    }

    #[Computed]
    public function filteredStudentResults()
    {
        $report = $this->studentReport;
        if (! $report || empty($report['results'])) {
            return collect();
        }

        return collect($report['results'])
            ->when($this->studentStage, fn ($c) => $c->filter(fn ($r) => strtolower($r->test?->test_stage ?? '') === strtolower($this->studentStage)))
            ->when($this->studentType, fn ($c) => $c->filter(fn ($r) => strtolower($r->test?->test_type ?? '') === strtolower($this->studentType)))
            ->when($this->studentAttendance, fn ($c) => $c->filter(fn ($r) => strtolower($r->attendance_status) === strtolower($this->studentAttendance)))
            ->sortBy(function ($r) {
                return match ($this->studentSortField) {
                    'marks' => (float) $r->marks_obtained,
                    'percentage' => (float) $r->percentage,
                    'rank' => $r->rank ? (int) $r->rank : 999999,
                    default => $r->test?->test_date?->timestamp ?? 0,
                };
            }, descending: $this->studentSortDirection === 'desc')
            ->values();
    }

    #[Computed]
    public function participationData(): array
    {
        return app(TestReportService::class)->getParticipationAnalytics(
            $this->partBatchId,
            $this->partCourseId,
            $this->partSectionId
        );
    }

    #[Computed]
    public function sectionComparison()
    {
        if (! $this->compCourseId) {
            return collect();
        }

        $collection = app(TestReportService::class)->getSectionComparisonStats($this->compCourseId, $this->compBatchId);

        if (! empty($this->sectionSearch)) {
            $s = strtolower(trim($this->sectionSearch));
            $collection = $collection->filter(function ($item) use ($s) {
                return str_contains(strtolower($item['section']->name), $s)
                    || str_contains(strtolower($item['section']->code), $s);
            });
        }

        return $collection->sortBy(function ($item) {
            return match ($this->sectionSortField) {
                'students' => (int) $item['student_count'],
                'tests' => (int) $item['tests_count'],
                'avg_marks' => (float) $item['avg_marks'],
                'avg_percentage' => (float) $item['avg_percentage'],
                'highest' => (float) $item['highest_marks'],
                default => $item['section']->name,
            };
        }, descending: $this->sectionSortDirection === 'desc')->values();
    }

    public function selectStudent(int $id): void
    {
        $this->selectedStudentId = $id;
        $this->studentSearch = '';
    }

    public function nudgeStudent(int $userId, NotificationService $notifService): void
    {
        try {
            $user = User::findOrFail($userId);
            $notifService->sendInternal(
                $user,
                'test_participation_reminder',
                'UPSC Test Participation Alert',
                'Dear Student, our academic audit records indicate you have not participated in recent scheduled mock tests. Mock examination practice is critical for Civil Services Examination success. Please connect with your mentor and attend upcoming tests.',
                route('student.tests.index'),
                'View Test Schedule'
            );
            Toast::dispatch($this, 'success', __('Academic participation nudge sent to :name.', ['name' => $user->name]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to send nudge: :err', ['err' => $e->getMessage()]));
        }
    }

    /* ----------------------------------------------------------------- *
     *  Export Actions across all 4 Tabs (PDF, Excel, CSV)
     * ----------------------------------------------------------------- */

    public function exportTestResults(string $format, TestImportExportService $exportService)
    {
        if (! $this->selectedTest) {
            return;
        }

        return $exportService->exportTestResults($this->selectedTest, $format);
    }

    public function exportStudentDossier(string $format, TestImportExportService $exportService)
    {
        $report = $this->studentReport;
        if (! $report || ! isset($report['student'])) {
            Toast::dispatch($this, 'warning', __('Please select a student first.'));
            return;
        }

        return $exportService->exportStudentDossier($report['student'], $report, $format);
    }

    public function exportParticipation(string $format, TestImportExportService $exportService)
    {
        return $exportService->exportParticipationAudit($this->participationData, $format);
    }

    public function exportSectionComparison(string $format, TestImportExportService $exportService)
    {
        return $exportService->exportSectionComparison($this->sectionComparison, $format);
    }

    /* ----------------------------------------------------------------- *
     *  Import Template & Score Ingestion Actions
     * ----------------------------------------------------------------- */

    public function downloadScoreTemplate(string $format, TestImportExportService $service)
    {
        if (! $this->selectedTest) {
            return;
        }

        return $service->generateSampleTemplate($this->selectedTest, $format);
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->dispatch('modal-open', name: 'import-scores-modal');
    }

    public function importScores(TestImportExportService $service): void
    {
        if (! $this->selectedTest) {
            Toast::dispatch($this, 'error', __('Please select an examination first.'));
            return;
        }

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $result = $service->importScores($this->selectedTest, $this->importFile);

        if ($result['success']) {
            $this->importFile = null;
            $this->dispatch('modal-close', name: 'import-scores-modal');
            Toast::dispatch($this, 'success', $result['message']);
        } else {
            Toast::dispatch($this, 'error', $result['message']);
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Examinations & Performance Analytics') }}</h1>
                <x-ui.badge variant="primary" size="sm">{{ __('Comprehensive Reports') }}</x-ui.badge>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Deep cross-test insights, overall student progress tracking, participation audits (100% champions & chronic absentees), and section comparisons.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" href="{{ route('admin.tests.leaderboard') }}" wire:navigate>
                <x-icon name="trophy" class="w-4 h-4 mr-1.5 text-amber-500" />
                {{ __('Leaderboard') }}
            </x-ui.button>
            <x-ui.button variant="primary" size="sm" href="{{ route('admin.tests.index') }}" wire:navigate>
                <x-icon name="clipboard-check" class="w-4 h-4 mr-1.5" />
                {{ __('Tests Dashboard') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 border-b border-border pb-1 overflow-x-auto">
        <button
            type="button"
            wire:click="$set('activeTab', 'test_wise')"
            class="px-3.5 py-2 text-xs font-semibold rounded-t-lg transition-colors flex items-center gap-2 whitespace-nowrap {{ $activeTab === 'test_wise' ? 'border-b-2 border-primary text-primary bg-primary/5' : 'text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="file-text" class="w-4 h-4" />
            {{ __('1. Test-Wise Students Performance') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'student_wise')"
            class="px-3.5 py-2 text-xs font-semibold rounded-t-lg transition-colors flex items-center gap-2 whitespace-nowrap {{ $activeTab === 'student_wise' ? 'border-b-2 border-primary text-primary bg-primary/5' : 'text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="user" class="w-4 h-4" />
            {{ __('2. Student-Wise Overall Performance') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'participation')"
            class="px-3.5 py-2 text-xs font-semibold rounded-t-lg transition-colors flex items-center gap-2 whitespace-nowrap {{ $activeTab === 'participation' ? 'border-b-2 border-primary text-primary bg-primary/5' : 'text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="pie-chart" class="w-4 h-4" />
            {{ __('3. Attendance & Participation Audit') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'sections')"
            class="px-3.5 py-2 text-xs font-semibold rounded-t-lg transition-colors flex items-center gap-2 whitespace-nowrap {{ $activeTab === 'sections' ? 'border-b-2 border-primary text-primary bg-primary/5' : 'text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="columns" class="w-4 h-4" />
            {{ __('4. Batch & Section Comparison') }}
        </button>
    </div>

    <!-- TAB 1: Test-Wise Performance Report -->
    @if($activeTab === 'test_wise')
        <div class="space-y-4">
            <!-- Test Selector Bar & Export Toolbar -->
            <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 p-4 bg-card rounded-lg border border-border shadow-xs">
                <div class="flex-1 max-w-xl">
                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1">{{ __('Select Examination to Analyze') }}</label>
                    <select wire:model.live="selectedTestId" class="h-10 w-full rounded-md border border-input bg-card px-3 text-sm font-semibold shadow-xs outline-none focus-visible:ring-[2px] focus-visible:ring-ring">
                        @foreach($this->tests as $t)
                            <option value="{{ $t->id }}">
                                [{{ $t->code }}] {{ $t->title }} — {{ $t->test_date->format('d M Y') }} ({{ $t->course?->name }})
                            </option>
                        @endforeach
                    </select>
                </div>

                @if($this->selectedTest)
                    <div class="flex flex-wrap items-center gap-2 self-end lg:self-auto">
                        <!-- Import Trigger -->
                        <x-ui.button variant="secondary" size="sm" wire:click="openImportModal">
                            <x-icon name="upload" class="w-4 h-4 mr-1 text-primary" />
                            {{ __('Import Scores') }}
                        </x-ui.button>

                        <!-- Exports: CSV, Excel, PDF -->
                        <x-ui.button variant="outline" size="sm" wire:click="exportTestResults('csv')">
                            <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                            {{ __('CSV') }}
                        </x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="exportTestResults('excel')">
                            <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                            {{ __('Excel') }}
                        </x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="exportTestResults('pdf')">
                            <x-icon name="file-text" class="w-4 h-4 mr-1 text-destructive" />
                            {{ __('PDF') }}
                        </x-ui.button>
                        <x-ui.button variant="primary" size="sm" href="{{ route('admin.tests.results', $this->selectedTest) }}" wire:navigate>
                            <x-icon name="award" class="w-4 h-4 mr-1" />
                            {{ __('Gradebook') }}
                        </x-ui.button>
                    </div>
                @endif
            </div>

            @php $testWiseStats = $this->testWiseStats; @endphp

            @if($testWiseStats)
                <!-- Metrics Grid -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <x-ui.stat-card
                        icon="users"
                        color="sky"
                        :label="__('Enrolled Turnout')"
                        :value="$testWiseStats['attended_count'] . ' / ' . $testWiseStats['total_enrolled']"
                    />
                    <x-ui.stat-card
                        icon="percent"
                        color="emerald"
                        :label="__('Attendance Rate')"
                        :value="$testWiseStats['attendance_rate'] . '%'"
                    />
                    <x-ui.stat-card
                        icon="bar-chart-2"
                        color="primary"
                        :label="__('Average Score')"
                        :value="$testWiseStats['avg_marks'] . ' Marks'"
                    />
                    <x-ui.stat-card
                        icon="trophy"
                        color="amber"
                        :label="__('Top Score / Scorer')"
                        :value="$testWiseStats['highest_marks'] . ' M'"
                    />
                    <x-ui.stat-card
                        icon="check-circle"
                        color="violet"
                        :label="__('Benchmark Pass %')"
                        :value="$testWiseStats['pass_rate'] . '%'"
                    />
                </div>

                <!-- Grade Distribution Histogram -->
                <div class="p-4 bg-card rounded-lg border border-border shadow-xs space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Score Distribution Breakdown') }}</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                        <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
                            <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">Grade A (≥75%)</span>
                            <div class="text-xl font-extrabold text-foreground mt-1">{{ $testWiseStats['grades']['grade_a'] }}</div>
                            <span class="text-[11px] text-muted-foreground">High Distinction</span>
                        </div>
                        <div class="p-3 rounded-lg bg-sky-500/10 border border-sky-500/20">
                            <span class="text-xs font-bold text-sky-600 dark:text-sky-400">Grade B (60% - 74%)</span>
                            <div class="text-xl font-extrabold text-foreground mt-1">{{ $testWiseStats['grades']['grade_b'] }}</div>
                            <span class="text-[11px] text-muted-foreground">Above Average</span>
                        </div>
                        <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20">
                            <span class="text-xs font-bold text-amber-600 dark:text-amber-400">Grade C (45% - 59%)</span>
                            <div class="text-xl font-extrabold text-foreground mt-1">{{ $testWiseStats['grades']['grade_c'] }}</div>
                            <span class="text-[11px] text-muted-foreground">Average / Qualifying</span>
                        </div>
                        <div class="p-3 rounded-lg bg-destructive/10 border border-destructive/20">
                            <span class="text-xs font-bold text-destructive">Needs Review (<45%)</span>
                            <div class="text-xl font-extrabold text-foreground mt-1">{{ $testWiseStats['grades']['grade_d'] }}</div>
                            <span class="text-[11px] text-muted-foreground">Remedial Focus</span>
                        </div>
                    </div>
                </div>

                <!-- Dynamic Search, Filters & Sorting Toolbar for Table -->
                <div class="p-3 bg-card rounded-lg border border-border shadow-xs flex flex-wrap items-center justify-between gap-3 text-xs">
                    <div class="flex flex-wrap items-center gap-2 flex-1 min-w-[280px]">
                        <!-- Search Candidate -->
                        <div class="relative w-full sm:w-60">
                            <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="testWiseSearch"
                                placeholder="{{ __('Search candidate, roll...') }}"
                                class="h-8 w-full rounded border border-input bg-transparent pl-8 pr-2 text-xs outline-none focus-visible:ring-[2px] focus-visible:ring-ring"
                            />
                        </div>

                        <!-- Section Filter -->
                        <select wire:model.live="testWiseSectionId" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none">
                            <option value="">{{ __('All Sections') }}</option>
                            @foreach($this->sections as $sec)
                                <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                            @endforeach
                        </select>

                        <!-- Attendance Filter -->
                        <select wire:model.live="testWiseAttendance" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none">
                            <option value="">{{ __('All Attendance') }}</option>
                            <option value="present">{{ __('Present Only') }}</option>
                            <option value="absent">{{ __('Absent Only') }}</option>
                        </select>

                        <!-- Result Status Filter -->
                        <select wire:model.live="testWisePassStatus" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none">
                            <option value="">{{ __('All Results') }}</option>
                            <option value="passed">{{ __('Passed Only') }}</option>
                            <option value="failed">{{ __('Remedial / Failed') }}</option>
                        </select>
                    </div>

                    <!-- Sort Controls -->
                    <div class="flex items-center gap-1.5 text-muted-foreground">
                        <span>{{ __('Sort:') }}</span>
                        <select wire:model.live="testWiseSortField" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none font-semibold text-foreground">
                            <option value="rank">{{ __('Merit Rank') }}</option>
                            <option value="marks">{{ __('Marks Obtained') }}</option>
                            <option value="percentage">{{ __('Percentage') }}</option>
                            <option value="roll_number">{{ __('Roll Number') }}</option>
                        </select>
                        <button
                            type="button"
                            wire:click="$set('testWiseSortDirection', '{{ $testWiseSortDirection === 'asc' ? 'desc' : 'asc' }}')"
                            class="p-1.5 rounded border border-input bg-card hover:bg-secondary text-foreground"
                            title="{{ $testWiseSortDirection === 'asc' ? 'Ascending' : 'Descending' }}"
                        >
                            <x-icon name="{{ $testWiseSortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>

                <!-- Full Attendees Merit Table -->
                <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden">
                    <div class="px-4 py-3 border-b border-border bg-muted/30 flex items-center justify-between">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                            {{ __('Evaluated Candidates (:count)', ['count' => count($this->testWiseResults)]) }}
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px] font-bold">
                                    <th class="px-3 py-2.5 text-center w-12">Rank</th>
                                    <th class="px-3 py-2.5">Roll No</th>
                                    <th class="px-4 py-2.5">Candidate Name</th>
                                    <th class="px-3 py-2.5">Section</th>
                                    <th class="px-3 py-2.5 text-center">Status</th>
                                    <th class="px-3 py-2.5 text-center">Right</th>
                                    <th class="px-3 py-2.5 text-center">Wrong</th>
                                    <th class="px-3 py-2.5 text-center">Blank</th>
                                    <th class="px-3 py-2.5 text-right">Net Marks</th>
                                    <th class="px-3 py-2.5 text-right">% Score</th>
                                    <th class="px-3 py-2.5 text-center">Percentile</th>
                                    <th class="px-3 py-2.5">Remarks & Feedback</th>
                                    <th class="px-3 py-2.5 text-right">Marksheet</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                @forelse($this->testWiseResults as $res)
                                    <tr class="hover:bg-muted/30 {{ $res->rank && $res->rank <= 3 ? 'bg-amber-500/5' : '' }}">
                                        <td class="px-3 py-2.5 text-center font-bold">
                                             @if($res->rank === 1) 🥇 @elseif($res->rank === 2) 🥈 @elseif($res->rank === 3) 🥉 @elseif($res->rank) #{{ $res->rank }} @else — @endif
                                        </td>
                                        <td class="px-3 py-2.5 font-mono font-semibold">{{ $res->roll_number ?: 'N/A' }}</td>
                                        <td class="px-4 py-2.5 font-medium text-foreground">{{ $res->user?->name }}</td>
                                        <td class="px-3 py-2.5 text-muted-foreground">{{ $res->section?->name ?? 'Default' }}</td>
                                        <td class="px-3 py-2.5 text-center">
                                            <x-ui.badge variant="{{ $res->attendance_status === 'present' ? 'success' : 'secondary' }}" size="sm">
                                                {{ ucfirst($res->attendance_status) }}
                                            </x-ui.badge>
                                        </td>
                                        <td class="px-3 py-2.5 text-center font-bold text-emerald-600 dark:text-emerald-400">{{ $res->right_answers }}</td>
                                        <td class="px-3 py-2.5 text-center font-bold text-destructive">{{ $res->wrong_answers }}</td>
                                        <td class="px-3 py-2.5 text-center text-muted-foreground">{{ $res->blank_answers }}</td>
                                        <td class="px-3 py-2.5 text-right font-bold text-foreground">{{ $res->marks_obtained }}</td>
                                        <td class="px-3 py-2.5 text-right font-semibold">{{ $res->percentage }}%</td>
                                        <td class="px-3 py-2.5 text-center font-mono text-muted-foreground">{{ $res->percentile !== null ? $res->percentile . '%' : '—' }}</td>
                                        <td class="px-3 py-2.5 text-[11px] text-muted-foreground max-w-xs truncate">
                                            @if($res->remarks || $res->feedback)
                                                <span>{{ $res->remarks ?: $res->feedback }}</span>
                                            @else
                                                <span class="text-muted-foreground/60">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            <a href="{{ route('tests.download-marksheet', $res) }}" target="_blank" class="text-primary hover:underline font-medium">
                                                PDF
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="13" class="px-4 py-8 text-center text-muted-foreground">
                                            {{ __('No candidates match the selected filters.') }}
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

    <!-- TAB 2: Student-Wise Overall Performance Report -->
    @if($activeTab === 'student_wise')
        <div class="space-y-4">
            <!-- Student Selector & Search Bar -->
            <div class="p-4 bg-card rounded-lg border border-border shadow-xs space-y-3">
                <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Search Student to Generate Cross-Test Dossier') }}</label>
                <div class="relative max-w-lg">
                    <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="studentSearch"
                        placeholder="{{ __('Type candidate name, roll number, or email...') }}"
                        class="h-10 w-full rounded-md border border-input bg-transparent pl-8 pr-3 text-sm shadow-xs outline-none focus-visible:ring-[2px] focus-visible:ring-ring"
                    />

                    <!-- Autocomplete dropdown -->
                    @if($this->studentSearchResults->isNotEmpty())
                        <div class="absolute left-0 right-0 top-full mt-1 bg-popover text-popover-foreground rounded-lg border border-border shadow-lg z-20 overflow-hidden divide-y divide-border/60">
                            @foreach($this->studentSearchResults as $u)
                                <button
                                    type="button"
                                    wire:click="selectStudent({{ $u->id }})"
                                    class="w-full text-left px-3 py-2.5 hover:bg-secondary/60 text-xs flex items-center justify-between"
                                >
                                    <div>
                                        <span class="font-bold text-foreground">{{ $u->name }}</span>
                                        <span class="text-muted-foreground ml-1.5 font-mono">({{ $u->student_id ?: 'No Roll' }})</span>
                                    </div>
                                    <span class="text-primary font-medium">{{ __('Select →') }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @php $studentReport = $this->studentReport; @endphp

            @if($studentReport)
                <!-- Student Dossier Header & Export Actions -->
                <div class="p-4 bg-card rounded-lg border border-border shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-lg overflow-hidden border border-border">
                            @if($studentReport['student']->avatarUrl())
                                <img src="{{ $studentReport['student']->avatarUrl() }}" alt="{{ $studentReport['student']->name }}" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(substr($studentReport['student']->name, 0, 1)) }}
                            @endif
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-foreground">{{ $studentReport['student']->name }}</h2>
                            <div class="text-xs text-muted-foreground flex flex-wrap items-center gap-2 mt-0.5">
                                <span class="font-mono font-semibold text-primary">{{ $studentReport['student']->student_id ?: 'SNTCSSC Candidate' }}</span>
                                <span>&bull;</span>
                                <span>{{ $studentReport['enrollment']?->course?->name ?? 'UPSC Civil Services Foundation' }}</span>
                                <span>&bull;</span>
                                <span>Batch: {{ $studentReport['enrollment']?->batch?->name ?? 'General' }}</span>
                                @if($studentReport['enrollment']?->section)
                                    <span>&bull;</span>
                                    <span>Sec {{ $studentReport['enrollment']->section->name }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Export Actions for Student Dossier -->
                    <div class="flex items-center gap-2">
                        <x-ui.button variant="outline" size="sm" wire:click="exportStudentDossier('csv')">
                            <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                            {{ __('Dossier CSV') }}
                        </x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="exportStudentDossier('excel')">
                            <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                            {{ __('Dossier Excel') }}
                        </x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="exportStudentDossier('pdf')">
                            <x-icon name="file-text" class="w-4 h-4 mr-1 text-destructive" />
                            {{ __('Dossier PDF') }}
                        </x-ui.button>
                        <x-ui.badge variant="primary" size="lg">
                            {{ $studentReport['participation_rate'] }}% {{ __('Attendance') }}
                        </x-ui.badge>
                    </div>
                </div>

                <!-- Metrics Grid -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <x-ui.stat-card
                        icon="clipboard-check"
                        color="primary"
                        :label="__('Tests Attended')"
                        :value="$studentReport['attended_count'] . ' / ' . $studentReport['total_tests_conducted']"
                    />
                    <x-ui.stat-card
                        icon="percent"
                        color="violet"
                        :label="__('Average Score')"
                        :value="$studentReport['avg_percentage'] . '%'"
                    />
                    <x-ui.stat-card
                        icon="trophy"
                        color="amber"
                        :label="__('Highest Score')"
                        :value="$studentReport['highest_marks'] . ' Marks'"
                    />
                    <x-ui.stat-card
                        icon="award"
                        color="emerald"
                        :label="__('Best Rank Achieved')"
                        :value="$studentReport['best_rank'] ? '#' . $studentReport['best_rank'] : 'N/A'"
                    />
                    <x-ui.stat-card
                        icon="star"
                        color="sky"
                        :label="__('Top Finishes')"
                        :value="$studentReport['top_ten_count'] . ' Times'"
                    />
                </div>

                <!-- Filter & Sort Toolbar for Student Tests -->
                <div class="p-3 bg-card rounded-lg border border-border shadow-xs flex flex-wrap items-center justify-between gap-2 text-xs">
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Stage Filter -->
                        <select wire:model.live="studentStage" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none">
                            <option value="">{{ __('All Stages') }}</option>
                            <option value="prelims">{{ __('Prelims') }}</option>
                            <option value="mains">{{ __('Mains') }}</option>
                        </select>

                        <!-- Type Filter -->
                        <select wire:model.live="studentType" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none">
                            <option value="">{{ __('All Types') }}</option>
                            <option value="mcq">{{ __('MCQ') }}</option>
                            <option value="descriptive">{{ __('Descriptive') }}</option>
                            <option value="essay">{{ __('Essay') }}</option>
                        </select>

                        <!-- Attendance Filter -->
                        <select wire:model.live="studentAttendance" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none">
                            <option value="">{{ __('All Attendance') }}</option>
                            <option value="present">{{ __('Present Only') }}</option>
                            <option value="absent">{{ __('Absent Only') }}</option>
                        </select>
                    </div>

                    <!-- Sort -->
                    <div class="flex items-center gap-1.5 text-muted-foreground">
                        <span>{{ __('Sort:') }}</span>
                        <select wire:model.live="studentSortField" class="h-8 rounded border border-input bg-card px-2 text-xs outline-none font-semibold text-foreground">
                            <option value="date">{{ __('Test Date') }}</option>
                            <option value="marks">{{ __('Marks Obtained') }}</option>
                            <option value="percentage">{{ __('Percentage') }}</option>
                            <option value="rank">{{ __('Merit Rank') }}</option>
                        </select>
                        <button
                            type="button"
                            wire:click="$set('studentSortDirection', '{{ $studentSortDirection === 'asc' ? 'desc' : 'asc' }}')"
                            class="p-1.5 rounded border border-input bg-card hover:bg-secondary text-foreground"
                            title="{{ $studentSortDirection === 'asc' ? 'Ascending' : 'Descending' }}"
                        >
                            <x-icon name="{{ $studentSortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>

                <!-- Test History Table -->
                <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden">
                    <div class="px-4 py-3 border-b border-border bg-muted/30">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                            {{ __('All Attended Examinations & Performance Records (:count)', ['count' => count($this->filteredStudentResults)]) }}
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px] font-bold">
                                    <th class="px-3 py-2.5">Date</th>
                                    <th class="px-3 py-2.5">Code</th>
                                    <th class="px-4 py-2.5">Examination Title</th>
                                    <th class="px-3 py-2.5">Stage & Type</th>
                                    <th class="px-3 py-2.5 text-center">Attendance</th>
                                    <th class="px-3 py-2.5 text-right">Marks Obtained</th>
                                    <th class="px-3 py-2.5 text-right">% Score</th>
                                    <th class="px-3 py-2.5 text-center">Merit Rank</th>
                                    <th class="px-3 py-2.5 text-center">Percentile</th>
                                    <th class="px-3 py-2.5">Feedback / Remarks</th>
                                    <th class="px-3 py-2.5 text-right">Marksheet</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                @forelse($this->filteredStudentResults as $res)
                                    <tr class="hover:bg-muted/30 {{ $res->is_top_ten ? 'bg-amber-500/5' : '' }}">
                                        <td class="px-3 py-2.5 text-muted-foreground whitespace-nowrap">
                                            {{ $res->test?->test_date?->format('d M Y') }}
                                        </td>
                                        <td class="px-3 py-2.5 font-mono font-semibold text-primary">
                                            {{ $res->test?->code }}
                                        </td>
                                        <td class="px-4 py-2.5 font-medium text-foreground">
                                            {{ $res->test?->title }}
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <x-ui.badge variant="secondary" size="sm">
                                                {{ strtoupper($res->test?->test_stage ?? '') }}
                                            </x-ui.badge>
                                        </td>
                                        <td class="px-3 py-2.5 text-center">
                                            <x-ui.badge variant="{{ $res->attendance_status === 'present' ? 'success' : 'secondary' }}" size="sm">
                                                {{ ucfirst($res->attendance_status) }}
                                            </x-ui.badge>
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-bold text-foreground">
                                            {{ $res->marks_obtained }} / {{ $res->total_marks }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-semibold">
                                            {{ $res->percentage }}%
                                        </td>
                                        <td class="px-3 py-2.5 text-center font-bold">
                                            @if($res->rank)
                                                #{{ $res->rank }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-center font-mono text-muted-foreground">
                                            {{ $res->percentile !== null ? $res->percentile . '%' : '—' }}
                                        </td>
                                        <td class="px-3 py-2.5 text-[11px] text-muted-foreground max-w-xs truncate">
                                            {{ $res->feedback ?: ($res->remarks ?: '—') }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            <a href="{{ route('tests.download-marksheet', $res) }}" target="_blank" class="text-primary hover:underline font-medium">
                                                PDF
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="px-4 py-8 text-center text-muted-foreground">
                                            {{ __('No examination records logged for this candidate.') }}
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

    <!-- TAB 3: Attendance & Participation Audit -->
    @if($activeTab === 'participation')
        <div class="space-y-4">
            <!-- Filter Bar with Dynamic Search & Exports -->
            <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 p-4 bg-card rounded-lg border border-border shadow-xs">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 flex-1 max-w-3xl">
                    <select wire:model.live="partBatchId" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                        <option value="">{{ __('All Batches') }}</option>
                        @foreach($this->batches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="partCourseId" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                        <option value="">{{ __('All Courses') }}</option>
                        @foreach($this->courses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="partSectionId" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                        <option value="">{{ __('All Sections') }}</option>
                        @foreach($this->sections as $sec)
                            <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="partCategory" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none font-semibold">
                        <option value="all">{{ __('All Turnout Categories') }}</option>
                        <option value="champions">{{ __('100% Champions') }}</option>
                        <option value="absentees">{{ __('Chronic Absentees (0%)') }}</option>
                        <option value="max">{{ __('Top Turnout') }}</option>
                        <option value="least">{{ __('Least Turnout') }}</option>
                    </select>
                </div>

                <!-- Export Actions -->
                <div class="flex items-center gap-2 self-end lg:self-auto">
                    <x-ui.button variant="outline" size="sm" wire:click="exportParticipation('csv')">
                        <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                        {{ __('CSV') }}
                    </x-ui.button>
                    <x-ui.button variant="outline" size="sm" wire:click="exportParticipation('excel')">
                        <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                        {{ __('Excel') }}
                    </x-ui.button>
                    <x-ui.button variant="outline" size="sm" wire:click="exportParticipation('pdf')">
                        <x-icon name="file-text" class="w-4 h-4 mr-1 text-destructive" />
                        {{ __('PDF') }}
                    </x-ui.button>
                </div>
            </div>

            <!-- Summary KPI -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-4 bg-card rounded-lg border border-border shadow-xs">
                    <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Total Tests Conducted') }}</span>
                    <div class="text-2xl font-black text-foreground mt-1">{{ $this->participationData['total_tests_conducted'] }}</div>
                    <span class="text-xs text-muted-foreground">{{ $this->participationData['total_students'] }} active students enrolled</span>
                </div>

                <div class="p-4 bg-card rounded-lg border border-emerald-500/20 bg-emerald-500/5 shadow-xs">
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">{{ __('100% Attendance Champions') }}</span>
                    <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-1">{{ $this->participationData['participated_all_count'] }}</div>
                    <span class="text-xs text-muted-foreground">{{ __('Attended every scheduled test') }}</span>
                </div>

                <div class="p-4 bg-card rounded-lg border border-destructive/20 bg-destructive/5 shadow-xs">
                    <span class="text-xs font-bold uppercase tracking-wider text-destructive">{{ __('Chronic Absentees (0% Participation)') }}</span>
                    <div class="text-2xl font-black text-destructive mt-1">{{ $this->participationData['participated_none_count'] }}</div>
                    <span class="text-xs text-muted-foreground">{{ __('Have not attended any examination') }}</span>
                </div>
            </div>

            <!-- Two Columns: Champions vs Defaulters -->
            @if($partCategory === 'all' || $partCategory === 'champions' || $partCategory === 'absentees')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- 100% Champions Table -->
                    @if($partCategory === 'all' || $partCategory === 'champions')
                        <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden flex flex-col">
                            <div class="p-3 bg-emerald-500/10 border-b border-border flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-lg">🏆</span>
                                    <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                                        {{ __('100% Participation Champions (:count)', ['count' => $this->participationData['participated_all_count']]) }}
                                    </h3>
                                </div>
                            </div>

                            <div class="overflow-x-auto flex-1">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px]">
                                            <th class="px-3 py-2">Roll No</th>
                                            <th class="px-3 py-2">Candidate Name</th>
                                            <th class="px-3 py-2">Course</th>
                                            <th class="px-3 py-2 text-center">Attended</th>
                                            <th class="px-3 py-2 text-right">Avg Score</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60">
                                        @forelse($this->participationData['participated_all'] as $item)
                                            <tr class="hover:bg-muted/30">
                                                <td class="px-3 py-2 font-mono font-semibold">{{ $item['enrollment']->roll_no ?: $item['user']->student_id }}</td>
                                                <td class="px-3 py-2 font-bold text-foreground">{{ $item['user']->name }}</td>
                                                <td class="px-3 py-2 text-muted-foreground">{{ $item['enrollment']->course?->code }}</td>
                                                <td class="px-3 py-2 text-center font-bold text-emerald-600 dark:text-emerald-400">{{ $item['attended_tests'] }}/{{ $item['available_tests'] }}</td>
                                                <td class="px-3 py-2 text-right font-bold">{{ $item['avg_marks'] }} M</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ __('No 100% attendees found.') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <!-- 0% Defaulters Table -->
                    @if($partCategory === 'all' || $partCategory === 'absentees')
                        <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden flex flex-col">
                            <div class="p-3 bg-destructive/10 border-b border-border flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-lg">⚠️</span>
                                    <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                                        {{ __('Chronic Absentees / 0% Attended (:count)', ['count' => $this->participationData['participated_none_count']]) }}
                                    </h3>
                                </div>
                            </div>

                            <div class="overflow-x-auto flex-1">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px]">
                                            <th class="px-3 py-2">Roll No</th>
                                            <th class="px-3 py-2">Candidate Name</th>
                                            <th class="px-3 py-2">Contact</th>
                                            <th class="px-3 py-2 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60">
                                        @forelse($this->participationData['participated_none'] as $item)
                                            <tr class="hover:bg-muted/30">
                                                <td class="px-3 py-2 font-mono font-semibold text-destructive">{{ $item['enrollment']->roll_no ?: $item['user']->student_id }}</td>
                                                <td class="px-3 py-2 font-bold text-foreground">{{ $item['user']->name }}</td>
                                                <td class="px-3 py-2 text-muted-foreground">
                                                    {{ $item['user']->phone ?: $item['user']->email }}
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    <button
                                                        type="button"
                                                        wire:click="nudgeStudent({{ $item['user']->id }})"
                                                        class="px-2 py-1 rounded bg-primary/10 text-primary hover:bg-primary/20 text-[11px] font-semibold transition-colors"
                                                    >
                                                        {{ __('Send Nudge') }}
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-3 py-6 text-center text-muted-foreground">{{ __('No chronic absentees! All candidates have participated.') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <!-- Top Attendees vs Least Attendees Tables -->
            @if($partCategory === 'all' || $partCategory === 'max' || $partCategory === 'least')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- Maximum Tests Participated -->
                    @if($partCategory === 'all' || $partCategory === 'max')
                        <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden">
                            <div class="p-3 bg-muted/40 border-b border-border">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                                    {{ __('Top Attendees (Maximum Tests Participated)') }}
                                </h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px]">
                                            <th class="px-3 py-2">Roll No</th>
                                            <th class="px-3 py-2">Student Name</th>
                                            <th class="px-3 py-2 text-center">Tests Attended</th>
                                            <th class="px-3 py-2 text-right">Participation Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60">
                                        @foreach($this->participationData['participated_max'] as $m)
                                            <tr class="hover:bg-muted/30">
                                                <td class="px-3 py-2 font-mono font-semibold">{{ $m['enrollment']->roll_no ?: $m['user']->student_id }}</td>
                                                <td class="px-3 py-2 font-bold text-foreground">{{ $m['user']->name }}</td>
                                                <td class="px-3 py-2 text-center font-bold text-primary">{{ $m['attended_tests'] }} tests</td>
                                                <td class="px-3 py-2 text-right font-semibold">{{ $m['participation_rate'] }}%</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <!-- Least Tests Participated -->
                    @if($partCategory === 'all' || $partCategory === 'least')
                        <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden">
                            <div class="p-3 bg-muted/40 border-b border-border">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                                    {{ __('Least Attendees (Fewest Tests Participated > 0)') }}
                                </h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px]">
                                            <th class="px-3 py-2">Roll No</th>
                                            <th class="px-3 py-2">Student Name</th>
                                            <th class="px-3 py-2 text-center">Tests Attended</th>
                                            <th class="px-3 py-2 text-right">Participation Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60">
                                        @foreach($this->participationData['participated_least'] as $l)
                                            <tr class="hover:bg-muted/30">
                                                <td class="px-3 py-2 font-mono font-semibold">{{ $l['enrollment']->roll_no ?: $l['user']->student_id }}</td>
                                                <td class="px-3 py-2 font-bold text-foreground">{{ $l['user']->name }}</td>
                                                <td class="px-3 py-2 text-center font-bold text-muted-foreground">{{ $l['attended_tests'] }} tests</td>
                                                <td class="px-3 py-2 text-right font-semibold text-destructive">{{ $l['participation_rate'] }}%</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <!-- TAB 4: Batch & Section Comparison -->
    @if($activeTab === 'sections')
        <div class="space-y-4">
            <!-- Filter & Search Toolbar -->
            <div class="p-4 bg-card rounded-lg border border-border shadow-xs flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2 flex-1 max-w-2xl">
                    <div class="w-full sm:w-64">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1">{{ __('Course Breakdown') }}</label>
                        <select wire:model.live="compCourseId" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                            @foreach($this->courses as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="w-full sm:w-48">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1">{{ __('Filter Batch') }}</label>
                        <select wire:model.live="compBatchId" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                            <option value="">{{ __('All Batches') }}</option>
                            @foreach($this->batches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="w-full sm:w-48">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1">{{ __('Search Section') }}</label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="sectionSearch"
                            placeholder="{{ __('Filter name/code...') }}"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-[2px] focus-visible:ring-ring"
                        />
                    </div>
                </div>

                <!-- Export Actions -->
                <div class="flex items-center gap-2 self-end lg:self-end">
                    <x-ui.button variant="outline" size="sm" wire:click="exportSectionComparison('csv')">
                        <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                        {{ __('CSV') }}
                    </x-ui.button>
                    <x-ui.button variant="outline" size="sm" wire:click="exportSectionComparison('excel')">
                        <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-600 dark:text-emerald-400" />
                        {{ __('Excel') }}
                    </x-ui.button>
                    <x-ui.button variant="outline" size="sm" wire:click="exportSectionComparison('pdf')">
                        <x-icon name="file-text" class="w-4 h-4 mr-1 text-destructive" />
                        {{ __('PDF') }}
                    </x-ui.button>
                </div>
            </div>

            <!-- Sections Table -->
            <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px] font-bold">
                            <th class="px-4 py-3">{{ __('Section Name') }}</th>
                            <th class="px-4 py-3">{{ __('Classroom Code') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('Enrolled Students') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('Tests Held') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Average Score') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Average %') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Highest Recorded') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @forelse($this->sectionComparison as $secStat)
                            <tr class="hover:bg-muted/30">
                                <td class="px-4 py-3 font-bold text-foreground text-sm">{{ $secStat['section']->name }}</td>
                                <td class="px-4 py-3 font-mono text-muted-foreground">{{ $secStat['section']->code }}</td>
                                <td class="px-4 py-3 text-center font-bold text-foreground">{{ $secStat['student_count'] }}</td>
                                <td class="px-4 py-3 text-center font-bold text-primary">{{ $secStat['tests_count'] }}</td>
                                <td class="px-4 py-3 text-right font-bold text-foreground">{{ $secStat['avg_marks'] }} M</td>
                                <td class="px-4 py-3 text-right font-semibold text-emerald-600 dark:text-emerald-400">{{ $secStat['avg_percentage'] }}%</td>
                                <td class="px-4 py-3 text-right font-bold text-amber-600 dark:text-amber-400">{{ $secStat['highest_marks'] }} M</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                    {{ __('No section data found for this course.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Interactive Modal: Import Candidate Scores -->
    <x-ui.modal
        name="import-scores-modal"
        max-width="max-w-3xl"
        :title="__('Import Candidate Examination Scores & Evaluator Logs')"
        :description="$this->selectedTest ? '[' . $this->selectedTest->code . '] ' . $this->selectedTest->title : __('Bulk import scores for selected test.')"
    >
        <form wire:submit="importScores" class="space-y-5">
            <!-- Step 1: Pre-filled Roster Download -->
            <div class="p-4 bg-muted/40 rounded-xl border border-border/80 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-primary text-[11px] font-black">1</span>
                            {{ __('Download Pre-filled Roster Template') }}
                        </h4>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            {{ __('Includes enrolled candidate roll numbers, names, and current section mappings.') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            wire:click="downloadScoreTemplate('csv')"
                            class="px-3 py-1.5 rounded-lg bg-card hover:bg-secondary border border-border text-foreground font-semibold text-xs flex items-center gap-1.5 shadow-xs transition-colors"
                        >
                            <x-icon name="download" class="w-3.5 h-3.5 text-emerald-500" />
                            <span>{{ __('CSV Template') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="downloadScoreTemplate('excel')"
                            class="px-3 py-1.5 rounded-lg bg-card hover:bg-secondary border border-border text-foreground font-semibold text-xs flex items-center gap-1.5 shadow-xs transition-colors"
                        >
                            <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600" />
                            <span>{{ __('Excel (.xlsx) Template') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 2: Roster Column Reference & Explanation -->
            <div class="space-y-2.5">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-primary text-[11px] font-black">2</span>
                        {{ __('File Columns & Data Format Reference') }}
                    </h4>
                    <span class="text-[11px] text-muted-foreground font-mono">12 Columns Supported</span>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-muted/50 border-b border-border text-[10px] uppercase font-bold text-muted-foreground">
                                <th class="px-3 py-2">Column Header</th>
                                <th class="px-3 py-2">Requirement</th>
                                <th class="px-3 py-2">Accepted Values & Instructions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60 text-[11px]">
                            <tr>
                                <td class="px-3 py-1.5 font-mono font-bold text-foreground">roll_number</td>
                                <td class="px-3 py-1.5"><span class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-600 font-bold text-[10px]">REQUIRED</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Candidate's roll number or student registration ID. Primary identity key.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">student_name</td>
                                <td class="px-3 py-1.5"><span class="text-muted-foreground text-[10px]">Reference</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Full student name for human verification. Left as-is during processing.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">email</td>
                                <td class="px-3 py-1.5"><span class="text-muted-foreground text-[10px]">Optional</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Used as fallback matcher if roll_number is not matched in current batch.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono font-bold text-foreground">attendance_status</td>
                                <td class="px-3 py-1.5"><span class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-600 font-bold text-[10px]">REQUIRED</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground"><code class="font-mono text-primary font-bold">present</code>, <code class="font-mono text-muted-foreground">absent</code>, <code class="font-mono text-muted-foreground">exempted</code>, or <code class="font-mono text-destructive">disqualified</code> (case-insensitive).</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">right_answers</td>
                                <td class="px-3 py-1.5"><span class="text-primary text-[10px] font-bold">MCQ</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Number of correct responses (integer &ge; 0). Multiplied by question weightage.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">wrong_answers</td>
                                <td class="px-3 py-1.5"><span class="text-primary text-[10px] font-bold">MCQ</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Number of incorrect responses. Penalized using configured negative marking ratio.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">blank_answers</td>
                                <td class="px-3 py-1.5"><span class="text-muted-foreground text-[10px]">Optional</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Unattempted count. Auto-calculated as (total questions &minus; right &minus; wrong) if left blank.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">marks_obtained</td>
                                <td class="px-3 py-1.5"><span class="text-indigo-600 dark:text-indigo-400 text-[10px] font-bold">Descriptive</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Direct score for Essay, Mains or Answer-Writing tests. For MCQ, optional manual override.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">remarks</td>
                                <td class="px-3 py-1.5"><span class="text-muted-foreground text-[10px]">Optional</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Internal faculty observation (e.g. &ldquo;Strong in modern history, revise ethics theory&rdquo;).</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">feedback</td>
                                <td class="px-3 py-1.5"><span class="text-muted-foreground text-[10px]">Optional</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Actionable guidance printed on student's official PDF marksheet and visible on portal.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">evaluated_by</td>
                                <td class="px-3 py-1.5"><span class="text-muted-foreground text-[10px]">Optional</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Evaluating faculty email, user ID, or full name. Defaults to current logged-in user.</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-mono text-foreground">evaluated_at</td>
                                <td class="px-3 py-1.5"><span class="text-muted-foreground text-[10px]">Optional</span></td>
                                <td class="px-3 py-1.5 text-muted-foreground">Evaluation timestamp (e.g. <code class="font-mono">YYYY-MM-DD</code> or <code class="font-mono">YYYY-MM-DD HH:MM</code>). Defaults to now.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Step 3: UPSC CSE Prelims Scoring Mechanics Notice -->
            <div class="p-3 bg-secondary/30 rounded-xl border border-border/80 text-xs space-y-2">
                <div class="flex items-center gap-2 text-foreground font-bold text-xs">
                    <x-icon name="info" class="w-4 h-4 text-primary shrink-0" />
                    <span>{{ __('UPSC Civil Services Examination Scoring Mechanics & Auto-Calculation') }}</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-[11px] text-muted-foreground">
                    <div class="p-2.5 rounded-lg bg-card border border-border/60">
                        <span class="font-bold text-foreground block mb-0.5">General Studies Paper I (GS I)</span>
                        <span>100 Questions &bull; 200 Marks &bull; <strong class="text-emerald-500">+2.00</strong> per correct &bull; <strong class="text-destructive">-0.66</strong> (1/3rd penalty) per wrong. Counts towards Merit Cut-off.</span>
                    </div>
                    <div class="p-2.5 rounded-lg bg-card border border-border/60">
                        <span class="font-bold text-foreground block mb-0.5">CSAT / GS Paper II (GS II)</span>
                        <span>80 Questions &bull; 200 Marks &bull; <strong class="text-emerald-500">+2.50</strong> per correct &bull; <strong class="text-destructive">-0.83</strong> (1/3rd penalty) per wrong. Qualifying (requires &ge; 33% / 66.67 Marks).</span>
                    </div>
                </div>
                <p class="text-[10px] text-muted-foreground italic">
                    * {{ __('The engine recalculates batch merit ranks, percentiles, and pass/fail statuses immediately upon completion of the import.') }}
                </p>
            </div>

            <!-- Step 4: File Upload & Action -->
            <div class="space-y-2">
                <label class="block text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-primary text-[11px] font-black">3</span>
                    {{ __('Select Completed File (.csv, .xlsx, .xls)') }} <span class="text-destructive">*</span>
                </label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv, .xlsx, .xls"
                    class="block w-full text-xs text-foreground file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer border border-input rounded-md bg-card p-1 shadow-xs"
                />
                @error('importFile')
                    <span class="text-destructive text-xs block mt-1">{{ $message }}</span>
                @enderror
                <p class="text-[11px] text-muted-foreground">
                    {{ __('Supported formats: .csv, .xlsx, .xls. Maximum upload size: 10 MB.') }}
                </p>
            </div>

            <div class="pt-3 border-t border-border flex items-center justify-end gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    wire:click="$dispatch('modal-close', { name: 'import-scores-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>

                <x-ui.button
                    type="submit"
                    variant="primary"
                    size="sm"
                    wire:loading.attr="disabled"
                    wire:target="importScores, importFile"
                >
                    <span wire:loading.remove wire:target="importScores">{{ __('Process & Ingest Scores') }}</span>
                    <span wire:loading wire:target="importScores" class="flex items-center gap-1">
                        <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                        <span>{{ __('Importing & Recalculating Ranks...') }}</span>
                    </span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
