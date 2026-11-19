<?php

use App\Models\AuditLog;
use App\Models\Section;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use App\Services\Test\TestImportExportService;
use App\Services\Test\TestReportService;
use App\Services\Test\TestScoreCalculatorService;
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

new #[Layout('layouts.app')] #[Title('Test Results & Merit Ranking')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    public StudentTest $test;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'attendance')]
    public string $attendanceFilter = '';

    #[Url(as: 'standing')]
    public string $standingFilter = '';

    public int $perPage = 25;

    // Single result editing modal state
    public ?int $editingResultId = null;
    public ?StudentTestResult $editingResult = null;
    public array $editForm = [
        'roll_number' => '',
        'section_id' => '',
        'attendance_status' => 'present',
        'right_answers' => 0,
        'wrong_answers' => 0,
        'blank_answers' => 0,
        'marks_obtained' => 0.00,
        'manual_override_marks' => '',
        'is_passed' => true,
        'evaluated_by' => '',
        'evaluated_at' => '',
        'remarks' => '',
        'feedback' => '',
    ];

    // Bulk import modal state
    public $importFile = null;
    public array $importReport = [];

    public function mount(StudentTest $test): void
    {
        $this->test = $test->load(['batch', 'course', 'section', 'subjects']);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAttendanceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStandingFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function statistics(): array
    {
        return app(TestReportService::class)->getTestPerformanceStats($this->test);
    }

    #[Computed]
    public function availableSections()
    {
        return Section::where('course_id', $this->test->course_id)
            ->when($this->test->batch_id, fn ($q) => $q->where(fn ($sub) => $sub->where('batch_id', $this->test->batch_id)->orWhereNull('batch_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    #[Computed]
    public function evaluatorsList()
    {
        return User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['Super Administrator', 'Administrator', 'Staff', 'Faculty', 'Teacher', 'Instructor']);
        })->orderBy('name')->get(['id', 'name', 'email']);
    }

    #[Computed]
    public function results()
    {
        return $this->test->results()
            ->with(['user.studentProfile', 'section', 'evaluator'])
            ->when($this->search, function (Builder $q) {
                $s = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($s) {
                    $sub->where('roll_number', 'like', $s)
                        ->orWhereHas('user', function ($uq) use ($s) {
                            $uq->where('name', 'like', $s)
                                ->orWhere('email', 'like', $s);
                        });
                });
            })
            ->when($this->attendanceFilter, fn ($q, $v) => $q->where('attendance_status', $v))
            ->when($this->standingFilter === 'top_ten', fn ($q) => $q->where('is_top_ten', true))
            ->when($this->standingFilter === 'passed', fn ($q) => $q->where('is_passed', true)->where('attendance_status', 'present'))
            ->when($this->standingFilter === 'failed', fn ($q) => $q->where('is_passed', false)->where('attendance_status', 'present'))
            ->orderByRaw('CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END, `rank` ASC, marks_obtained DESC')
            ->paginate($this->perPage);
    }

    public function openEditResultModal(int $resultId): void
    {
        $result = StudentTestResult::with(['user', 'section', 'evaluator'])->findOrFail($resultId);
        $this->editingResultId = $result->id;
        $this->editingResult = $result;

        $calcRight = (int) $result->right_answers;
        $calcWrong = (int) $result->wrong_answers;
        $formulaScore = round(($calcRight * (float) ($this->test->marks_per_question ?: 2.0)) - ($calcWrong * (float) ($this->test->penaltyPerWrongAnswer())), 2);
        $isOverridden = $this->test->isMcq() && abs((float) $result->marks_obtained - $formulaScore) > 0.001 && $result->attendance_status === 'present';

        $this->editForm = [
            'roll_number' => $result->roll_number ?? '',
            'section_id' => $result->section_id ? (string) $result->section_id : '',
            'attendance_status' => $result->attendance_status,
            'total_questions' => (int) ($result->total_questions ?: $this->test->total_questions),
            'total_marks' => (float) ($result->total_marks ?: $this->test->total_marks),
            'right_answers' => (int) $result->right_answers,
            'wrong_answers' => (int) $result->wrong_answers,
            'blank_answers' => (int) $result->blank_answers,
            'marks_obtained' => (float) $result->marks_obtained,
            'manual_override_marks' => $isOverridden ? (float) $result->marks_obtained : '',
            'is_passed' => (bool) $result->is_passed,
            'evaluated_by' => $result->evaluated_by ? (string) $result->evaluated_by : (string) (auth()->id() ?? ''),
            'evaluated_at' => $result->evaluated_at ? $result->evaluated_at->format('Y-m-d\TH:i') : now()->format('Y-m-d\TH:i'),
            'remarks' => $result->remarks ?? '',
            'feedback' => $result->feedback ?? '',
        ];

        $this->dispatch('modal-open', name: 'result-edit-modal');
    }

    public function setAttendanceStatus(string $status): void
    {
        if (in_array($status, ['present', 'absent', 'exempted', 'disqualified'], true)) {
            $this->editForm['attendance_status'] = $status;
        }
    }

    public function saveResult(TestScoreCalculatorService $calculator): void
    {
        if (! $this->editingResultId) {
            return;
        }

        $this->validate([
            'editForm.roll_number' => 'nullable|string|max:50',
            'editForm.section_id' => 'nullable|exists:sections,id',
            'editForm.attendance_status' => 'required|in:present,absent,exempted,disqualified',
            'editForm.total_questions' => 'nullable|integer|min:1',
            'editForm.total_marks' => 'nullable|numeric|min:0.01',
            'editForm.right_answers' => 'nullable|integer|min:0',
            'editForm.wrong_answers' => 'nullable|integer|min:0',
            'editForm.blank_answers' => 'nullable|integer|min:0',
            'editForm.marks_obtained' => 'nullable|numeric',
            'editForm.manual_override_marks' => 'nullable|numeric',
            'editForm.is_passed' => 'nullable|boolean',
            'editForm.evaluated_by' => 'nullable|exists:users,id',
            'editForm.evaluated_at' => 'nullable|string',
            'editForm.remarks' => 'nullable|string|max:1000',
            'editForm.feedback' => 'nullable|string|max:1000',
        ]);

        try {
            $result = StudentTestResult::findOrFail($this->editingResultId);
            $calculator->recordStudentScore($this->test, $result->user_id, $this->editForm);

            Toast::dispatch($this, 'success', __('Score updated and merit ranks recalculated.'));
            $this->dispatch('modal-close', name: 'result-edit-modal');
            $this->editingResultId = null;
            $this->editingResult = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update score: :err', ['err' => $e->getMessage()]));
        }
    }

    public function recalculateRanks(TestScoreCalculatorService $calculator): void
    {
        try {
            $calculator->recalculateAndRank($this->test);
            Toast::dispatch($this, 'success', __('Merit ranks, percentiles, and standings recalculated successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to recalculate: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openImportModal(): void
    {
        $this->reset(['importFile', 'importReport']);
        $this->dispatch('modal-open', name: 'results-import-modal');
    }

    public function downloadTemplate(string $format = 'csv', ?TestImportExportService $exportService = null)
    {
        $exportService = $exportService ?? app(TestImportExportService::class);
        return $exportService->generateSampleTemplate($this->test, $format);
    }

    public function processImport(TestImportExportService $exportService): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx|max:10240',
        ]);

        try {
            $report = $exportService->importScores($this->test, $this->importFile);
            $this->importReport = $report;

            if ($report['success']) {
                Toast::dispatch($this, 'success', $report['message']);
                $this->resetPage();
            } else {
                Toast::dispatch($this, 'error', $report['message']);
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import error: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format, TestImportExportService $exportService)
    {
        return $exportService->exportTestResults($this->test, $format);
    }
}; ?>

<div class="space-y-6">
    <!-- Breadcrumb & Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ route('admin.tests.index') }}" wire:navigate class="hover:text-foreground transition-colors flex items-center gap-1">
                    <x-icon name="arrow-left" class="w-3.5 h-3.5" />
                    {{ __('Back to Examinations') }}
                </a>
                <span>/</span>
                <span class="font-mono text-primary font-bold">{{ $test->code }}</span>
            </div>

            <div class="flex items-center gap-2.5">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ $test->title }}</h1>
                <x-ui.badge variant="{{ $test->test_stage === 'prelims' ? 'primary' : 'warning' }}" size="sm">
                    {{ strtoupper($test->test_stage) }}
                </x-ui.badge>
                <x-ui.badge variant="secondary" size="sm">
                    {{ strtoupper(str_replace('_', ' ', $test->test_type)) }}
                </x-ui.badge>
            </div>

            <p class="text-xs text-muted-foreground mt-1 flex flex-wrap items-center gap-2">
                <span>{{ $test->course?->name }}</span>
                <span>&bull;</span>
                <span>{{ $test->batch?->name }}</span>
                <span>&bull;</span>
                <span class="font-medium text-foreground">
                    {{ $test->section ? 'Section ' . $test->section->name : 'All Sections' }}
                </span>
                <span>&bull;</span>
                <span>{{ $test->test_date->format('d M Y') }} ({{ $test->duration_minutes }} Mins)</span>
                <span>&bull;</span>
                <span class="font-bold text-foreground">Max Marks: {{ $test->total_marks }}</span>
            </p>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="outline" size="sm" wire:click="recalculateRanks" title="{{ __('Recalculate ranks, negative deductions & percentiles') }}">
                <x-icon name="refresh-cw" class="w-4 h-4 mr-1.5 text-primary" />
                {{ __('Recalculate Ranks') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" wire:click="openImportModal">
                <x-icon name="upload" class="w-4 h-4 mr-1.5" />
                {{ __('Import Scores') }}
            </x-ui.button>

            <!-- Export Dropdown / Buttons -->
            <div class="flex items-center gap-1">
                <x-ui.button variant="outline" size="sm" wire:click="export('csv')">
                    <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1 text-emerald-500" />
                    {{ __('CSV') }}
                </x-ui.button>
                <x-ui.button variant="outline" size="sm" wire:click="export('pdf')">
                    <x-icon name="file-text" class="w-4 h-4 mr-1 text-destructive" />
                    {{ __('PDF') }}
                </x-ui.button>
            </div>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
        <div class="p-3 bg-card rounded-lg border border-border shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider">{{ __('Turnout') }}</span>
            <div class="text-lg font-bold text-foreground mt-0.5">
                {{ $this->statistics['attended_count'] }} <span class="text-xs text-muted-foreground font-normal">/ {{ $this->statistics['total_enrolled'] }}</span>
            </div>
            <span class="text-[11px] text-emerald-500 font-medium">{{ $this->statistics['attendance_rate'] }}% attended</span>
        </div>

        <div class="p-3 bg-card rounded-lg border border-border shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider">{{ __('Average Score') }}</span>
            <div class="text-lg font-bold text-foreground mt-0.5">
                {{ $this->statistics['avg_marks'] }}
            </div>
            <span class="text-[11px] text-muted-foreground">out of {{ $test->total_marks }}</span>
        </div>

        <div class="p-3 bg-card rounded-lg border border-border shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider">{{ __('Top Scorer') }}</span>
            <div class="text-sm font-bold text-foreground truncate mt-0.5" title="{{ $this->statistics['top_scorer']?->name ?? 'N/A' }}">
                {{ $this->statistics['top_scorer']?->name ?? 'N/A' }}
            </div>
            <span class="text-[11px] font-bold text-amber-500">{{ $this->statistics['top_score'] ?? 0 }} Marks</span>
        </div>

        <div class="p-3 bg-card rounded-lg border border-border shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider">{{ __('Lowest Marks') }}</span>
            <div class="text-lg font-bold text-foreground mt-0.5">
                {{ $this->statistics['lowest_marks'] }}
            </div>
            <span class="text-[11px] text-muted-foreground">min registered</span>
        </div>

        <div class="p-3 bg-card rounded-lg border border-border shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider">{{ __('Pass Rate') }}</span>
            <div class="text-lg font-bold text-foreground mt-0.5">
                {{ $this->statistics['pass_rate'] }}%
            </div>
            <span class="text-[11px] text-emerald-500">{{ $this->statistics['passed_count'] }} qualified</span>
        </div>

        <div class="p-3 bg-card rounded-lg border border-border shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider">{{ __('Top 10 Rankers') }}</span>
            <div class="text-lg font-bold text-amber-500 mt-0.5">
                10 <span class="text-xs text-muted-foreground font-normal">Featured</span>
            </div>
            <a href="{{ route('admin.tests.leaderboard') }}?test_id={{ $test->id }}" wire:navigate class="text-[11px] text-primary hover:underline font-medium">
                {{ __('View Podium →') }}
            </a>
        </div>
    </div>

    <!-- Search & Filters -->
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 p-3 bg-card rounded-lg border border-border">
        <div class="relative flex-1 max-w-md">
            <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by student name, roll number, email...') }}"
                class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            />
        </div>

        <div class="flex items-center gap-2">
            <!-- Attendance Filter -->
            <select wire:model.live="attendanceFilter" class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover">
                <option value="">{{ __('All Attendance') }}</option>
                <option value="present">{{ __('Present Only') }}</option>
                <option value="absent">{{ __('Absent Only') }}</option>
                <option value="disqualified">{{ __('Disqualified') }}</option>
            </select>

            <!-- Standing Filter -->
            <select wire:model.live="standingFilter" class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover">
                <option value="">{{ __('All Standings') }}</option>
                <option value="top_ten">{{ __('Top 10 Rankers') }}</option>
                <option value="passed">{{ __('Passed / Benchmark Met') }}</option>
                <option value="failed">{{ __('Needs Attention') }}</option>
            </select>
        </div>
    </div>

    <!-- Results Table -->
    <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                        <th class="px-3.5 py-3 text-center w-12">{{ __('Rank') }}</th>
                        <th class="px-4 py-3">{{ __('Student / Roll Number') }}</th>
                        <th class="px-3 py-3">{{ __('Section') }}</th>
                        <th class="px-3 py-3 text-center">{{ __('Attendance') }}</th>
                        @if($test->isMcq())
                            <th class="px-3 py-3 text-center">{{ __('Right') }}</th>
                            <th class="px-3 py-3 text-center">{{ __('Wrong') }}</th>
                            <th class="px-3 py-3 text-center">{{ __('Blank') }}</th>
                        @endif
                        <th class="px-4 py-3 text-right">{{ __('Net Marks') }}</th>
                        <th class="px-3 py-3 text-right">{{ __('Percentage') }}</th>
                        <th class="px-3 py-3 text-center">{{ __('Percentile') }}</th>
                        <th class="px-3 py-3 text-center">{{ __('Result') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($this->results as $result)
                        <tr class="hover:bg-muted/30 transition-colors {{ $result->rank && $result->rank <= 3 ? 'bg-amber-500/5' : '' }}" wire:key="res-{{ $result->id }}">
                            <!-- Rank -->
                            <td class="px-3.5 py-3 text-center">
                                @if($result->rank === 1)
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-500 text-white font-bold text-xs shadow-xs" title="1st Rank - Gold">
                                        🥇
                                    </span>
                                @elseif($result->rank === 2)
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-300 dark:bg-slate-700 text-foreground font-bold text-xs shadow-xs" title="2nd Rank - Silver">
                                        🥈
                                    </span>
                                @elseif($result->rank === 3)
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-700/80 text-white font-bold text-xs shadow-xs" title="3rd Rank - Bronze">
                                        🥉
                                    </span>
                                @elseif($result->rank && $result->rank <= 10)
                                    <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-500 font-bold text-xs font-mono">
                                        #{{ $result->rank }}
                                    </span>
                                @elseif($result->rank)
                                    <span class="text-xs font-mono text-muted-foreground">#{{ $result->rank }}</span>
                                @else
                                    <span class="text-xs text-muted-foreground">—</span>
                                @endif
                            </td>

                            <!-- Candidate -->
                            <td class="px-4 py-3">
                                <div class="font-bold text-foreground text-sm flex items-center gap-2">
                                    {{ $result->user?->name ?? 'Candidate' }}
                                    @if($result->is_top_ten)
                                        <span class="text-[10px] bg-amber-500/10 text-amber-500 font-bold px-1.5 py-0.2 rounded">TOP 10</span>
                                    @endif
                                </div>
                                <div class="text-[11px] text-muted-foreground font-mono mt-0.5">
                                    {{ $result->roll_number ?: ($result->user?->student_id ?: 'No Roll') }}
                                </div>
                            </td>

                            <!-- Section -->
                            <td class="px-3 py-3 text-xs">
                                {{ $result->section?->name ?? 'Default' }}
                            </td>

                            <!-- Attendance Status -->
                            <td class="px-3 py-3 text-center">
                                @if($result->attendance_status === 'present')
                                    <x-ui.badge variant="success" size="sm">{{ __('Present') }}</x-ui.badge>
                                @elseif($result->attendance_status === 'absent')
                                    <x-ui.badge variant="secondary" size="sm">{{ __('Absent') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="destructive" size="sm">{{ ucfirst($result->attendance_status) }}</x-ui.badge>
                                @endif
                            </td>

                            <!-- MCQ Breakdown -->
                            @if($test->isMcq())
                                <td class="px-3 py-3 text-center font-bold text-emerald-500">
                                    {{ $result->right_answers }}
                                </td>
                                <td class="px-3 py-3 text-center font-bold text-destructive">
                                    {{ $result->wrong_answers }}
                                </td>
                                <td class="px-3 py-3 text-center text-muted-foreground">
                                    {{ $result->blank_answers }}
                                </td>
                            @endif

                            <!-- Net Marks -->
                            <td class="px-4 py-3 text-right font-bold text-sm text-foreground">
                                {{ $result->marks_obtained }}
                            </td>

                            <!-- Percentage -->
                            <td class="px-3 py-3 text-right text-xs font-semibold text-foreground">
                                {{ $result->percentage }}%
                            </td>

                            <!-- Percentile -->
                            <td class="px-3 py-3 text-center text-xs text-muted-foreground font-mono">
                                {{ $result->percentile !== null ? $result->percentile . '%' : '—' }}
                            </td>

                            <!-- Result Classification -->
                            <td class="px-3 py-3 text-center">
                                @if($result->attendance_status === 'present')
                                    @if($result->is_passed)
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-500">
                                            <x-icon name="check" class="w-3 h-3" />
                                            PASS
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-destructive">
                                            <x-icon name="x" class="w-3 h-3" />
                                            FAIL
                                        </span>
                                    @endif
                                @else
                                    <span class="text-[11px] text-muted-foreground">—</span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <!-- Edit Score -->
                                    <button
                                        type="button"
                                        wire:click="openEditResultModal({{ $result->id }})"
                                        class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors"
                                        title="{{ __('Edit Student Marks') }}"
                                    >
                                        <x-icon name="pencil" class="w-3.5 h-3.5" />
                                    </button>

                                    <!-- Marksheet PDF -->
                                    <a
                                        href="{{ route('tests.download-marksheet', $result) }}"
                                        target="_blank"
                                        class="p-1.5 rounded-md hover:bg-secondary text-primary transition-colors"
                                        title="{{ __('Download Official Marksheet PDF') }}"
                                    >
                                        <x-icon name="file-text" class="w-3.5 h-3.5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $test->isMcq() ? 12 : 9 }}" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="user-x" class="mx-auto h-8 w-8 text-muted-foreground/60 mb-2" />
                                <p class="text-sm font-semibold">{{ __('No student records found') }}</p>
                                <p class="text-xs text-muted-foreground mt-1">{{ __('Try clearing filters or import candidate scores.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->results->hasPages())
            <div class="px-4 py-3 border-t border-border">
                {{ $this->results->links() }}
            </div>
        @endif
    </div>

    <!-- Comprehensive Update Candidate Score Modal -->
    <x-ui.modal
        name="result-edit-modal"
        max-width="max-w-2xl"
        :title="__('Update Candidate Score & Examination Record')"
        :description="__('Edit attendance status, score breakdown, section mapping, evaluator notes, and manual overrides.')"
    >
        @if($editingResult)
            <form wire:submit.prevent="saveResult" class="space-y-4">
                <!-- Candidate Profile Summary Banner -->
                <div class="p-3 bg-secondary/30 rounded-xl border border-border/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-primary/10 text-primary font-bold text-sm flex items-center justify-center shrink-0 border border-primary/20 overflow-hidden">
                            @if($editingResult->user?->avatarUrl())
                                <img src="{{ $editingResult->user->avatarUrl() }}" alt="{{ $editingResult->user->name }}" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(substr($editingResult->user?->name ?? 'C', 0, 1)) }}
                            @endif
                        </div>
                        <div>
                            <h4 class="font-bold text-foreground text-sm flex items-center gap-2">
                                <span>{{ $editingResult->user?->name }}</span>
                                @if($editingResult->rank)
                                    <span class="px-2 py-0.2 rounded-full font-black text-[10px] {{ $editingResult->rank === 1 ? 'bg-amber-500 text-slate-950' : ($editingResult->rank === 2 ? 'bg-slate-300 dark:bg-slate-700 text-slate-900 dark:text-slate-100' : ($editingResult->rank === 3 ? 'bg-amber-700 text-white' : 'bg-secondary text-foreground')) }}">
                                        RANK #{{ $editingResult->rank }}
                                    </span>
                                @endif
                            </h4>
                            <p class="text-[11px] text-muted-foreground font-mono mt-0.5">
                                {{ $editingResult->user?->email }} &bull; ID: {{ $editingResult->user?->student_id ?: 'N/A' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <x-ui.badge variant="{{ $editingResult->attendance_status === 'present' ? 'success' : 'secondary' }}" size="sm">
                            {{ ucfirst($editingResult->attendance_status) }}
                        </x-ui.badge>
                        <span class="text-[11px] font-bold text-foreground font-mono bg-card px-2 py-1 rounded border border-border">
                            {{ $editingResult->marks_obtained }} / {{ $test->total_marks }} M
                        </span>
                    </div>
                </div>

                <!-- Roster Identity Corrections: Roll Number & Section -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            {{ __('Candidate Roll Number') }} <span class="text-destructive">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model="editForm.roll_number"
                            placeholder="e.g. ROLL-001 or SNTC-2026-0042"
                            class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs font-mono font-semibold shadow-xs focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                        @error('editForm.roll_number') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('Printed on official marksheet.') }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            {{ __('Assigned Classroom Section') }}
                        </label>
                        <select
                            wire:model="editForm.section_id"
                            class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs font-semibold shadow-xs focus:outline-none focus:ring-2 focus:ring-primary/20"
                        >
                            <option value="">{{ __('Default / All Sections') }}</option>
                            @foreach($this->availableSections as $sec)
                                <option value="{{ $sec->id }}">{{ $sec->name }} ({{ $sec->code }})</option>
                            @endforeach
                        </select>
                        @error('editForm.section_id') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        <p class="text-[10px] text-muted-foreground mt-0.5">{{ __('Used for section-wise comparative analytics.') }}</p>
                    </div>
                </div>

                <!-- Attendance Status: 4 Interactive Pill Buttons -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5 flex items-center justify-between">
                        <span>{{ __('Attendance Status') }} <span class="text-destructive">*</span></span>
                        <span class="text-[10px] text-muted-foreground font-mono uppercase">{{ $editForm['attendance_status'] }}</span>
                    </label>

                    <input type="hidden" wire:model="editForm.attendance_status" />

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <button
                            type="button"
                            wire:click="setAttendanceStatus('present')"
                            class="flex items-center justify-center gap-1.5 p-2 rounded-lg border text-xs font-bold transition-all {{ $editForm['attendance_status'] === 'present' ? 'bg-emerald-500/15 border-emerald-500 text-emerald-600 dark:text-emerald-400 ring-2 ring-emerald-500/20 shadow-xs' : 'border-border bg-card hover:bg-secondary text-muted-foreground' }}"
                        >
                            <x-icon name="check-circle" class="w-3.5 h-3.5 {{ $editForm['attendance_status'] === 'present' ? 'text-emerald-500' : '' }}" />
                            <span>{{ __('Present') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="setAttendanceStatus('absent')"
                            class="flex items-center justify-center gap-1.5 p-2 rounded-lg border text-xs font-bold transition-all {{ $editForm['attendance_status'] === 'absent' ? 'bg-amber-500/15 border-amber-500 text-amber-600 dark:text-amber-400 ring-2 ring-amber-500/20 shadow-xs' : 'border-border bg-card hover:bg-secondary text-muted-foreground' }}"
                        >
                            <x-icon name="user-x" class="w-3.5 h-3.5 {{ $editForm['attendance_status'] === 'absent' ? 'text-amber-500' : '' }}" />
                            <span>{{ __('Absent') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="setAttendanceStatus('exempted')"
                            class="flex items-center justify-center gap-1.5 p-2 rounded-lg border text-xs font-bold transition-all {{ $editForm['attendance_status'] === 'exempted' ? 'bg-sky-500/15 border-sky-500 text-sky-600 dark:text-sky-400 ring-2 ring-sky-500/20 shadow-xs' : 'border-border bg-card hover:bg-secondary text-muted-foreground' }}"
                        >
                            <x-icon name="shield" class="w-3.5 h-3.5 {{ $editForm['attendance_status'] === 'exempted' ? 'text-sky-500' : '' }}" />
                            <span>{{ __('Exempted') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="setAttendanceStatus('disqualified')"
                            class="flex items-center justify-center gap-1.5 p-2 rounded-lg border text-xs font-bold transition-all {{ $editForm['attendance_status'] === 'disqualified' ? 'bg-destructive/15 border-destructive text-destructive ring-2 ring-destructive/20 shadow-xs' : 'border-border bg-card hover:bg-secondary text-muted-foreground' }}"
                        >
                            <x-icon name="slash" class="w-3.5 h-3.5 {{ $editForm['attendance_status'] === 'disqualified' ? 'text-destructive' : '' }}" />
                            <span>{{ __('Disqualified') }}</span>
                        </button>
                    </div>

                    @if($editForm['attendance_status'] !== 'present')
                        <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1 flex items-center gap-1">
                            <x-icon name="info" class="w-3.5 h-3.5 shrink-0" />
                            <span>{{ __('When not marked present, candidate score is set to 0.00 and excluded from merit rank calculation.') }}</span>
                        </p>
                    @endif
                </div>

                @if($editForm['attendance_status'] === 'present')
                    @if($test->isMcq())
                        <!-- MCQ Question Counters -->
                        <div class="p-3.5 bg-secondary/30 rounded-xl border border-border/70 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-foreground">{{ __('Objective Question Breakdown') }}</span>
                                <span class="text-[11px] text-muted-foreground font-mono">Total Qs: {{ $test->total_questions }}</span>
                            </div>

                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-500 mb-1">
                                        {{ __('Correct (✓)') }} (+{{ $test->marks_per_question }})
                                    </label>
                                    <input
                                        type="number"
                                        wire:model.live="editForm.right_answers"
                                        min="0"
                                        max="{{ $test->total_questions }}"
                                        class="h-9 w-full rounded-md border border-input bg-card px-2 text-center text-sm font-bold shadow-xs focus:ring-2 focus:ring-emerald-500/20"
                                    />
                                    @error('editForm.right_answers') <span class="text-destructive text-[10px] block mt-0.5">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-destructive mb-1">
                                        {{ __('Incorrect (✗)') }} (-{{ round($test->marks_per_question * $test->negative_marking_ratio, 2) }})
                                    </label>
                                    <input
                                        type="number"
                                        wire:model.live="editForm.wrong_answers"
                                        min="0"
                                        max="{{ $test->total_questions }}"
                                        class="h-9 w-full rounded-md border border-input bg-card px-2 text-center text-sm font-bold shadow-xs focus:ring-2 focus:ring-destructive/20"
                                    />
                                    @error('editForm.wrong_answers') <span class="text-destructive text-[10px] block mt-0.5">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-muted-foreground mb-1">
                                        {{ __('Blank / Left') }} (0.0)
                                    </label>
                                    <input
                                        type="number"
                                        wire:model="editForm.blank_answers"
                                        min="0"
                                        max="{{ $test->total_questions }}"
                                        class="h-9 w-full rounded-md border border-input bg-card px-2 text-center text-sm font-semibold shadow-xs"
                                    />
                                    @error('editForm.blank_answers') <span class="text-destructive text-[10px] block mt-0.5">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            @php
                                $calcRight = (int) ($editForm['right_answers'] ?? 0);
                                $calcWrong = (int) ($editForm['wrong_answers'] ?? 0);
                                $calcScore = round(($calcRight * (float) $test->marks_per_question) - ($calcWrong * (float) ($test->marks_per_question * $test->negative_marking_ratio)), 2);
                            @endphp

                            <div class="flex items-center justify-between text-xs pt-1 border-t border-border/60">
                                <span class="text-muted-foreground text-[11px]">
                                    Formula: <strong class="text-foreground">{{ $calcRight }} &times; {{ $test->marks_per_question }} &minus; {{ $calcWrong }} &times; {{ round($test->marks_per_question * $test->negative_marking_ratio, 2) }}</strong>
                                </span>
                                <span class="font-bold text-foreground">
                                    Computed: <strong class="text-primary">{{ $calcScore }} Marks</strong>
                                </span>
                            </div>

                            <!-- Manual Score Override for MCQ -->
                            <div class="pt-2 border-t border-border/60">
                                <label class="block text-xs font-semibold text-foreground mb-1 flex items-center justify-between">
                                    <span>{{ __('Manual Score Override (Optional)') }}</span>
                                    @if(!empty($editForm['manual_override_marks']))
                                        <button
                                            type="button"
                                            wire:click="$set('editForm.manual_override_marks', '')"
                                            class="text-[10px] text-destructive hover:underline font-semibold flex items-center gap-1"
                                        >
                                            <x-icon name="rotate-ccw" class="w-3 h-3" />
                                            <span>{{ __('Clear Override & Restore Formula') }}</span>
                                        </button>
                                    @else
                                        <span class="text-[10px] text-muted-foreground">{{ __('Overrides formula computation') }}</span>
                                    @endif
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    wire:model="editForm.manual_override_marks"
                                    placeholder="{{ __('Leave empty to keep formula auto-calculation (:score M)', ['score' => $calcScore]) }}"
                                    class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs font-mono font-semibold shadow-xs focus:ring-2 focus:ring-primary/20"
                                />
                                <p class="text-[10px] text-muted-foreground mt-0.5">
                                    {{ __('Useful if grace marks were awarded or a disputed question was cancelled by the committee.') }}
                                </p>
                            </div>
                        </div>
                    @else
                        <!-- Descriptive Marks Input -->
                        <div class="p-3.5 bg-secondary/30 rounded-xl border border-border/70 space-y-2">
                            <label class="block text-xs font-semibold text-foreground mb-1 flex items-center justify-between">
                                <span>{{ __('Descriptive / Essay Marks Obtained') }} <span class="text-destructive">*</span></span>
                                <span class="text-[11px] text-muted-foreground font-mono">Max Marks: {{ $test->total_marks }}</span>
                            </label>
                            <input
                                type="number"
                                step="0.25"
                                wire:model="editForm.marks_obtained"
                                min="0"
                                max="{{ $test->total_marks }}"
                                class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm font-bold shadow-xs focus:ring-2 focus:ring-primary/20"
                            />
                            @error('editForm.marks_obtained') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    <!-- Quick Correction of Total Questions & Maximum Marks (If Wrongly Uploaded) -->
                    <div x-data="{ showAdvanced: false }" class="p-3 bg-secondary/20 rounded-xl border border-border/60 space-y-2">
                        <button
                            type="button"
                            x-on:click="showAdvanced = !showAdvanced"
                            class="text-xs font-semibold text-primary hover:underline flex items-center justify-between w-full"
                        >
                            <span class="flex items-center gap-1.5">
                                <x-icon name="sliders" class="w-3.5 h-3.5 text-primary" />
                                <span>{{ __('Paper Bounds & Questions Correction') }}</span>
                            </span>
                            <span class="text-[10px] text-muted-foreground font-mono" x-text="showAdvanced ? '{{ __('Hide') }}' : '{{ __('Correct if wrongly imported') }}'"></span>
                        </button>
                        <div x-show="showAdvanced" x-collapse class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                            <div>
                                <label class="block text-[11px] font-semibold text-foreground mb-1">
                                    {{ __('Candidate Total Questions') }}
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    wire:model="editForm.total_questions"
                                    class="h-8 w-full rounded-md border border-input bg-card px-3 text-xs font-mono shadow-xs focus:ring-2 focus:ring-primary/20"
                                />
                                @error('editForm.total_questions') <span class="text-destructive text-[10px] block mt-0.5">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-foreground mb-1">
                                    {{ __('Candidate Total Maximum Marks') }}
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    wire:model="editForm.total_marks"
                                    class="h-8 w-full rounded-md border border-input bg-card px-3 text-xs font-mono shadow-xs focus:ring-2 focus:ring-primary/20"
                                />
                                @error('editForm.total_marks') <span class="text-destructive text-[10px] block mt-0.5">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Pass / Fail Result Classification Override -->
                    <div class="flex items-center justify-between p-3 rounded-lg border border-border bg-card">
                        <div>
                            <span class="text-xs font-semibold text-foreground block">{{ __('Result Standing Classification') }}</span>
                            <span class="text-[11px] text-muted-foreground">
                                @if($test->pass_marks)
                                    {{ __('Qualifying Pass Marks: :marks Marks', ['marks' => $test->pass_marks]) }}
                                @else
                                    {{ __('Based on general performance standards.') }}
                                @endif
                            </span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                wire:click="$set('editForm.is_passed', true)"
                                class="px-3 py-1.5 rounded-md text-xs font-bold transition-colors {{ !empty($editForm['is_passed']) ? 'bg-emerald-500/15 border border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'bg-secondary text-muted-foreground' }}"
                            >
                                <x-icon name="check" class="w-3 h-3 inline mr-1" />
                                {{ __('Passed') }}
                            </button>
                            <button
                                type="button"
                                wire:click="$set('editForm.is_passed', false)"
                                class="px-3 py-1.5 rounded-md text-xs font-bold transition-colors {{ empty($editForm['is_passed']) ? 'bg-destructive/15 border border-destructive text-destructive' : 'bg-secondary text-muted-foreground' }}"
                            >
                                <x-icon name="x" class="w-3 h-3 inline mr-1" />
                                {{ __('Failed / Remedial') }}
                            </button>
                        </div>
                    </div>
                @endif

                <!-- Evaluation Metadata: Evaluated By & Date -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Evaluated By (Faculty / Staff)') }}</label>
                        <select
                            wire:model="editForm.evaluated_by"
                            class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs focus:ring-2 focus:ring-primary/20"
                        >
                            <option value="">{{ __('Select Faculty Evaluator...') }}</option>
                            @foreach($this->evaluatorsList as $ev)
                                <option value="{{ $ev->id }}">{{ $ev->name }} ({{ $ev->email }})</option>
                            @endforeach
                        </select>
                        @error('editForm.evaluated_by') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Evaluation Date & Time') }}</label>
                        <input
                            type="datetime-local"
                            wire:model="editForm.evaluated_at"
                            class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs font-mono shadow-xs focus:ring-2 focus:ring-primary/20"
                        />
                        @error('editForm.evaluated_at') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- Observations & Student Feedback -->
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Faculty Remarks / Observations (Internal)') }}</label>
                        <textarea
                            wire:model="editForm.remarks"
                            rows="2"
                            placeholder="e.g. Strong conceptual clarity in Polity; revise Macroeconomics fiscal indicators."
                            class="w-full rounded-md border border-input bg-card p-2 text-xs shadow-xs focus:ring-2 focus:ring-primary/20"
                        ></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1 flex items-center justify-between">
                            <span>{{ __('Improvement Guidance for Student') }}</span>
                            <span class="text-[10px] text-muted-foreground">{{ __('Printed on candidate marksheet PDF') }}</span>
                        </label>
                        <textarea
                            wire:model="editForm.feedback"
                            rows="2"
                            placeholder="Actionable recommendations visible to candidate on portal & marksheet..."
                            class="w-full rounded-md border border-input bg-card p-2 text-xs shadow-xs focus:ring-2 focus:ring-primary/20"
                        ></textarea>
                    </div>
                </div>

                <!-- Modal Action Buttons -->
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('result-edit-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button variant="primary" type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveResult">{{ __('Save Score & Recalculate Standings') }}</span>
                        <span wire:loading wire:target="saveResult" class="flex items-center gap-1">
                            <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                            <span>{{ __('Updating & Ranking...') }}</span>
                        </span>
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>

    <!-- Comprehensive Candidate Scores Import Modal -->
    <x-ui.modal
        name="results-import-modal"
        max-width="max-w-3xl"
        :title="__('Import Candidate Examination Scores & Evaluator Logs')"
        :description="__('Upload completed scores file. The engine matches candidates by roll number, calculates UPSC net marks and negative penalties, and updates ranks.')"
    >
        <div class="space-y-5">
            <!-- Step 1: Pre-filled Roster Download -->
            <div class="p-4 bg-muted/40 rounded-xl border border-border/80 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-primary text-[11px] font-black">1</span>
                            {{ __('Download Pre-filled Roster Template') }}
                        </h4>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            {{ __('Generated automatically with enrolled candidates, roll numbers, names, and current section mappings.') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            wire:click="downloadTemplate('csv')"
                            class="px-3 py-1.5 rounded-lg bg-card hover:bg-secondary border border-border text-foreground font-semibold text-xs flex items-center gap-1.5 shadow-xs transition-colors"
                        >
                            <x-icon name="download" class="w-3.5 h-3.5 text-emerald-500" />
                            <span>{{ __('CSV Template') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="downloadTemplate('excel')"
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
                    accept=".csv,.xlsx,.xls,.txt"
                    class="block w-full text-xs text-foreground file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer border border-input rounded-md bg-card p-1 shadow-xs"
                />
                @error('importFile') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                <div wire:loading wire:target="importFile" class="text-xs text-primary mt-1 flex items-center gap-1">
                    <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                    <span>{{ __('Validating file format...') }}</span>
                </div>
            </div>

            @if(!empty($importReport))
                <div class="p-3.5 rounded-xl border {{ $importReport['success'] ? 'bg-emerald-500/10 border-emerald-500/30' : 'bg-destructive/10 border-destructive/30' }} text-xs space-y-1.5">
                    <p class="font-bold {{ $importReport['success'] ? 'text-emerald-500' : 'text-destructive' }} flex items-center gap-1.5">
                        <x-icon name="{{ $importReport['success'] ? 'check-circle' : 'alert-circle' }}" class="w-4 h-4" />
                        <span>{{ $importReport['message'] }}</span>
                    </p>
                    @if(!empty($importReport['errors']))
                        <ul class="list-disc list-inside text-destructive text-[11px] max-h-32 overflow-y-auto pt-1 space-y-0.5">
                            @foreach($importReport['errors'] as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('results-import-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="primary" size="sm" wire:click="processImport" wire:loading.attr="disabled" :disabled="!$importFile">
                    <span wire:loading.remove wire:target="processImport">{{ __('Process & Recalculate Standings') }}</span>
                    <span wire:loading wire:target="processImport" class="flex items-center gap-1">
                        <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                        <span>{{ __('Ingesting & Ranking...') }}</span>
                    </span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
