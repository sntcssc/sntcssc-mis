<?php

use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use App\Services\Test\TestReportService;
use App\Support\Toast;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('My Mock Tests & Examinations')] class extends Component {
    #[Url(as: 'tab')]
    public string $activeTab = 'my_tests'; // 'my_tests' or 'leaderboard'

    #[Url(as: 'test_id')]
    public ?int $selectedLeaderboardTestId = null;

    // Detailed Marksheet Viewer Modal
    public ?int $viewingResultId = null;
    public ?StudentTestResult $viewingResult = null;

    public function mount(): void
    {
        if (request()->routeIs('student.tests.leaderboard')) {
            $this->activeTab = 'leaderboard';
        }

        $latestTest = StudentTest::where('status', StudentTest::STATUS_COMPLETED)->latest('test_date')->first();
        $this->selectedLeaderboardTestId = $latestTest?->id;
    }

    #[Computed]
    public function student(): User
    {
        return auth()->user()->load(['studentProfile', 'activeEnrollment.course', 'activeEnrollment.batch', 'activeEnrollment.section']);
    }

    #[Computed]
    public function report(): array
    {
        return app(TestReportService::class)->getStudentOverallReport($this->student->id);
    }

    #[Computed]
    public function upcomingTests()
    {
        $enrollment = $this->student->activeEnrollment ?? $this->student->enrollments()->latest()->first();
        if (! $enrollment) {
            return collect();
        }

        return StudentTest::where('batch_id', $enrollment->batch_id)
            ->where('course_id', $enrollment->course_id)
            ->where(function ($q) use ($enrollment) {
                $q->whereNull('section_id')
                    ->orWhere('section_id', $enrollment->section_id);
            })
            ->where('test_date', '>=', now()->toDateString())
            ->orderBy('test_date')
            ->get();
    }

    #[Computed]
    public function testHistory()
    {
        return StudentTestResult::with(['test.course', 'test.batch', 'test.subjects', 'section'])
            ->where('user_id', $this->student->id)
            ->whereHas('test')
            ->get()
            ->sortByDesc(fn ($r) => $r->test?->test_date);
    }

    #[Computed]
    public function allConductededTests()
    {
        return StudentTest::with(['batch', 'course'])->orderByDesc('test_date')->get();
    }

    #[Computed]
    public function leaderboardTest()
    {
        if (! $this->selectedLeaderboardTestId) {
            return null;
        }

        return StudentTest::with(['batch', 'course', 'section'])->find($this->selectedLeaderboardTestId);
    }

    #[Computed]
    public function leaderboardLimit(): int
    {
        return (int) \App\Models\Setting::get('test_leaderboard_limit', 10);
    }

    #[Computed]
    public function leaderboardRankers()
    {
        if (! $this->leaderboardTest) {
            return collect();
        }

        return $this->leaderboardTest->rankers($this->leaderboardLimit);
    }

    public function openMarksheetModal(int $resultId): void
    {
        $this->viewingResult = StudentTestResult::with(['test.subjects', 'section'])->findOrFail($resultId);
        $this->viewingResultId = $resultId;
        $this->dispatch('modal-open', name: 'student-marksheet-modal');
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">📝</span>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('My Examinations & Performance') }}</h1>
                <x-ui.badge variant="primary" size="sm">{{ __('Student Portal') }}</x-ui.badge>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Track your UPSC mock tests, download question papers & model answers once tests conclude, view merit ranks, and inspect digital marksheets.') }}
            </p>
        </div>

        <!-- Tab Pills -->
        <div class="flex items-center gap-1.5 p-1 bg-secondary/60 rounded-lg border border-border/60">
            <button
                type="button"
                wire:click="$set('activeTab', 'my_tests')"
                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all flex items-center gap-1.5 {{ $activeTab === 'my_tests' ? 'bg-card text-foreground shadow-xs font-bold' : 'text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="clipboard-check" class="w-3.5 h-3.5" />
                {{ __('My Tests & Marksheets') }}
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'leaderboard')"
                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all flex items-center gap-1.5 {{ $activeTab === 'leaderboard' ? 'bg-card text-foreground shadow-xs font-bold' : 'text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="trophy" class="w-3.5 h-3.5 text-amber-500" />
                {{ __('Rankers Podium') }}
            </button>
        </div>
    </div>

    <!-- Student KPI Metric Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
        <x-ui.stat-card
            icon="clipboard-list"
            color="primary"
            :label="__('Tests Attended')"
            :value="$this->report['attended_count'] . ' / ' . $this->report['total_tests_conducted']"
        />
        <x-ui.stat-card
            icon="percent"
            color="emerald"
            :label="__('Turnout Rate')"
            :value="$this->report['participation_rate'] . '%'"
        />
        <x-ui.stat-card
            icon="bar-chart-2"
            color="violet"
            :label="__('Average Score')"
            :value="$this->report['avg_percentage'] . '%'"
        />
        <x-ui.stat-card
            icon="award"
            color="amber"
            :label="__('Best Rank Achieved')"
            :value="$this->report['best_rank'] ? '#' . $this->report['best_rank'] : 'N/A'"
        />
        <x-ui.stat-card
            icon="star"
            color="sky"
            :label="__('Top 10 Finishes')"
            :value="$this->report['top_ten_count'] . ' Times'"
        />
    </div>

    @if($activeTab === 'my_tests')
        <div class="space-y-6">
            <!-- Upcoming Tests Notice -->
            @if($this->upcomingTests->isNotEmpty())
                <div class="p-4 rounded-xl border border-primary/20 bg-primary/5 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="flex h-2 w-2 rounded-full bg-primary animate-ping"></span>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-primary">{{ __('Upcoming Scheduled Mock Tests') }}</h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($this->upcomingTests as $up)
                            <div class="p-3 bg-card rounded-lg border border-border shadow-xs flex flex-col justify-between space-y-2" wire:key="up-{{ $up->id }}">
                                <div>
                                    <div class="flex items-center justify-between">
                                        <span class="font-mono text-xs font-bold text-primary">{{ $up->code }}</span>
                                        <x-ui.badge variant="{{ $up->test_stage === 'prelims' ? 'primary' : 'warning' }}" size="sm">
                                            {{ strtoupper($up->test_stage) }}
                                        </x-ui.badge>
                                    </div>
                                    <h4 class="font-bold text-foreground text-sm mt-1">{{ $up->title }}</h4>
                                    <p class="text-xs text-muted-foreground mt-0.5">
                                        {{ $up->course?->name }} &bull; Max Marks: {{ $up->total_marks }}
                                    </p>
                                </div>

                                <div class="pt-2 border-t border-border/60 flex items-center justify-between text-xs text-muted-foreground">
                                    <div class="flex items-center gap-1">
                                        <x-icon name="calendar" class="w-3.5 h-3.5" />
                                        <span class="font-semibold text-foreground">{{ $up->test_date->format('d M Y') }}</span>
                                        <span>({{ $up->start_time ?: '10:00 AM' }})</span>
                                    </div>
                                    <span class="uppercase text-[10px] font-bold tracking-wide">{{ $up->mode }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Attended Tests & Grade History -->
            <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden space-y-2">
                <div class="p-4 border-b border-border bg-muted/30 flex items-center justify-between">
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                            {{ __('My Examination Performance Records (:count)', ['count' => count($this->testHistory)]) }}
                        </h3>
                        <p class="text-[11px] text-muted-foreground">
                            {{ __('Inspect scores, question breakdowns, and download solutions.') }}
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px] font-bold">
                                <th class="px-3.5 py-3">Date</th>
                                <th class="px-3.5 py-3">Test Code</th>
                                <th class="px-4 py-3">Examination Title</th>
                                <th class="px-3 py-3">Stage & Type</th>
                                <th class="px-3 py-3 text-center">Attendance</th>
                                <th class="px-4 py-3 text-right">Marks Obtained</th>
                                <th class="px-3 py-3 text-right">% Score</th>
                                <th class="px-3 py-3 text-center">Rank</th>
                                <th class="px-3 py-3 text-center">Downloads</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            @forelse($this->testHistory as $res)
                                @php $test = $res->test; @endphp
                                <tr class="hover:bg-muted/30 transition-colors {{ $res->is_top_ten ? 'bg-amber-500/5' : '' }}" wire:key="student-res-{{ $res->id }}">
                                    <td class="px-3.5 py-3 whitespace-nowrap text-muted-foreground">
                                        {{ $test?->test_date?->format('d M Y') }}
                                    </td>
                                    <td class="px-3.5 py-3 font-mono font-bold text-primary">
                                        {{ $test?->code }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-foreground text-sm flex items-center gap-1.5">
                                            {{ $test?->title }}
                                            @if($res->is_top_ten)
                                                <span class="text-[9px] bg-amber-500 text-white font-bold px-1 rounded">TOP 10</span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-muted-foreground mt-0.5">
                                            {{ $test?->course?->name }} &bull; Max Marks: {{ $res->total_marks }}
                                        </div>
                                    </td>
                                    <td class="px-3.5 py-3">
                                        <x-ui.badge variant="{{ $test?->test_stage === 'prelims' ? 'primary' : 'warning' }}" size="sm">
                                            {{ strtoupper($test?->test_stage ?? '') }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <x-ui.badge variant="{{ $res->attendance_status === 'present' ? 'success' : 'secondary' }}" size="sm">
                                            {{ ucfirst($res->attendance_status) }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-foreground text-sm">
                                        @if($res->attendance_status === 'present')
                                            {{ $res->marks_obtained }} <span class="text-xs font-normal text-muted-foreground">/ {{ $res->total_marks }}</span>
                                        @else
                                            <span class="text-muted-foreground">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right font-semibold text-foreground">
                                        @if($res->attendance_status === 'present')
                                            {{ $res->percentage }}%
                                        @else
                                            <span class="text-muted-foreground">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        @if($res->rank === 1)
                                            <span class="font-bold text-amber-500">🥇 #1</span>
                                        @elseif($res->rank === 2)
                                            <span class="font-bold text-slate-400">🥈 #2</span>
                                        @elseif($res->rank === 3)
                                            <span class="font-bold text-amber-700">🥉 #3</span>
                                        @elseif($res->rank)
                                            <span class="font-mono font-bold">#{{ $res->rank }}</span>
                                        @else
                                            <span class="text-muted-foreground">—</span>
                                        @endif
                                    </td>

                                    <!-- Downloads: Question Paper & Model Answer -->
                                    <td class="px-3 py-3 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            @if($test?->canDownloadSolutions())
                                                @if($test?->question_paper_path)
                                                    <a
                                                        href="{{ route('tests.download-paper', $test) }}"
                                                        target="_blank"
                                                        class="p-1.5 rounded bg-primary/10 hover:bg-primary/20 text-primary transition-colors"
                                                        title="{{ __('Download Question Paper') }}"
                                                    >
                                                        <x-icon name="file-text" class="w-3.5 h-3.5" />
                                                    </a>
                                                @endif
                                                @if($test?->model_answer_path)
                                                    <a
                                                        href="{{ route('tests.download-solution', $test) }}"
                                                        target="_blank"
                                                        class="p-1.5 rounded bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 transition-colors"
                                                        title="{{ __('Download Model Answers & Explanations') }}"
                                                    >
                                                        <x-icon name="check-square" class="w-3.5 h-3.5" />
                                                    </a>
                                                @endif
                                            @else
                                                <span class="text-[10px] text-muted-foreground flex items-center gap-1" title="{{ __('Available after test concludes') }}">
                                                    <x-icon name="lock" class="w-3 h-3" />
                                                    {{ __('Locked') }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <!-- View Details Modal -->
                                            <button
                                                type="button"
                                                wire:click="openMarksheetModal({{ $res->id }})"
                                                class="px-2.5 py-1 rounded-md bg-secondary hover:bg-secondary/80 text-foreground font-semibold text-xs transition-colors"
                                            >
                                                {{ __('Details') }}
                                            </button>

                                            <!-- Marksheet PDF -->
                                            <a
                                                href="{{ route('tests.download-marksheet', $res) }}"
                                                target="_blank"
                                                class="px-2.5 py-1 rounded-md bg-primary text-primary-foreground font-semibold text-xs hover:bg-primary/90 transition-colors flex items-center gap-1"
                                            >
                                                <x-icon name="download" class="w-3 h-3" />
                                                {{ __('Marksheet') }}
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="px-4 py-12 text-center text-muted-foreground">
                                        <x-icon name="clipboard-x" class="mx-auto h-8 w-8 text-muted-foreground/60 mb-2" />
                                        <p class="text-sm font-semibold">{{ __('No test records logged yet') }}</p>
                                        <p class="text-xs text-muted-foreground mt-1">{{ __('Your mock test results and merit ranks will appear here as tests are evaluated.') }}</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- TAB 2: Leaderboard & Rankers View -->
    @if($activeTab === 'leaderboard')
        <div class="space-y-6">
            <!-- Test Selector -->
            <div class="p-4 bg-card rounded-lg border border-border shadow-xs flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <div class="flex-1 max-w-md">
                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1">
                        {{ __('Select Mock Examination to View Top :count Rankers', ['count' => $this->leaderboardLimit]) }}
                    </label>
                    <select wire:model.live="selectedLeaderboardTestId" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs font-semibold shadow-xs outline-none">
                        @foreach($this->allConductededTests as $t)
                            <option value="{{ $t->id }}">
                                [{{ $t->code }}] {{ $t->title }} — {{ $t->test_date->format('d M Y') }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if($this->leaderboardTest)
                <!-- Podium: Top 3 Rankers -->
                @php
                    $r1 = $this->leaderboardRankers->firstWhere('rank', 1);
                    $r2 = $this->leaderboardRankers->firstWhere('rank', 2);
                    $r3 = $this->leaderboardRankers->firstWhere('rank', 3);
                @endphp

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-2">
                    <!-- 2nd Rank -->
                    <div class="order-2 md:order-1 flex flex-col justify-end">
                        @if($r2)
                            <div class="bg-linear-to-b from-slate-200/50 via-card to-card dark:from-slate-800/50 dark:via-card dark:to-card rounded-2xl border-2 border-slate-300 dark:border-slate-600 p-6 shadow-sm text-center space-y-4">
                                <div class="inline-flex items-center gap-1.5 bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-black text-xs px-3.5 py-1 rounded-full shadow-xs border border-slate-300 dark:border-slate-600 uppercase tracking-wider mx-auto">
                                    <span class="text-sm">🥈</span>
                                    <span>{{ __('RANK #2') }} &bull; {{ __('RUNNER-UP') }}</span>
                                </div>
                                <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-black text-xl flex items-center justify-center mx-auto border-2 border-slate-300 dark:border-slate-500 overflow-hidden shadow-xs">
                                    @if($r2->user?->avatarUrl())
                                        <img src="{{ $r2->user->avatarUrl() }}" alt="{{ $r2->user->name }}" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($r2->user?->name ?? '2', 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <h3 class="font-bold text-foreground text-sm">{{ $r2->user?->name }}</h3>
                                    <p class="text-[11px] font-mono text-muted-foreground mt-0.5">{{ $r2->roll_number ?: 'Candidate' }}</p>
                                </div>
                                <div class="pt-3 border-t border-border/60 text-xs flex items-center justify-around">
                                    <div>
                                        <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Score') }}</span>
                                        <span class="font-black text-foreground">{{ $r2->marks_obtained }} M</span>
                                    </div>
                                    <div>
                                        <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Percentage') }}</span>
                                        <span class="font-bold text-slate-700 dark:text-slate-300">{{ $r2->percentage }}%</span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- 1st Rank (Topper) -->
                    <div class="order-1 md:order-2">
                        @if($r1)
                            <div class="bg-linear-to-b from-amber-500/20 via-card to-card dark:from-amber-500/25 dark:via-card dark:to-card rounded-2xl border-2 border-amber-500 dark:border-amber-400 p-6 shadow-lg text-center space-y-4 ring-2 ring-amber-500/20 md:-translate-y-2">
                                <div class="inline-flex items-center gap-1.5 bg-linear-to-r from-amber-500 via-amber-400 to-amber-500 text-slate-950 font-black text-xs px-4 py-1.5 rounded-full shadow-md border border-amber-300/60 uppercase tracking-wider mx-auto">
                                    <span class="text-sm">👑</span>
                                    <span>{{ __('RANK #1') }} &bull; {{ __('TOPPER') }}</span>
                                </div>
                                <div class="w-20 h-20 rounded-full bg-amber-500/20 text-amber-600 dark:text-amber-400 font-black text-2xl flex items-center justify-center mx-auto border-4 border-amber-500 shadow-inner overflow-hidden">
                                    @if($r1->user?->avatarUrl())
                                        <img src="{{ $r1->user->avatarUrl() }}" alt="{{ $r1->user->name }}" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($r1->user?->name ?? '1', 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <h3 class="font-black text-foreground text-base">{{ $r1->user?->name }}</h3>
                                    <p class="text-xs font-mono text-amber-600 dark:text-amber-400 font-bold mt-0.5">{{ $r1->roll_number ?: 'Candidate' }}</p>
                                </div>
                                <div class="pt-3 border-t border-amber-500/30 text-xs flex items-center justify-around">
                                    <div>
                                        <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Score') }}</span>
                                        <span class="font-black text-amber-600 dark:text-amber-400 text-base">{{ $r1->marks_obtained }} M</span>
                                    </div>
                                    <div>
                                        <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Percentage') }}</span>
                                        <span class="font-bold text-foreground text-base">{{ $r1->percentage }}%</span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- 3rd Rank -->
                    <div class="order-3 flex flex-col justify-end">
                        @if($r3)
                            <div class="bg-linear-to-b from-amber-700/15 via-card to-card dark:from-amber-950/40 dark:via-card dark:to-card rounded-2xl border-2 border-amber-600/60 dark:border-amber-600/50 p-6 shadow-sm text-center space-y-4">
                                <div class="inline-flex items-center gap-1.5 bg-amber-700/20 dark:bg-amber-800/50 text-amber-900 dark:text-amber-200 font-black text-xs px-3.5 py-1 rounded-full shadow-xs border border-amber-600/40 dark:border-amber-600/60 uppercase tracking-wider mx-auto">
                                    <span class="text-sm">🥉</span>
                                    <span>{{ __('RANK #3') }} &bull; {{ __('2ND RUNNER-UP') }}</span>
                                </div>
                                <div class="w-16 h-16 rounded-full bg-amber-700/15 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 font-black text-xl flex items-center justify-center mx-auto border-2 border-amber-700/60 dark:border-amber-500/60 overflow-hidden shadow-xs">
                                    @if($r3->user?->avatarUrl())
                                        <img src="{{ $r3->user->avatarUrl() }}" alt="{{ $r3->user->name }}" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($r3->user?->name ?? '3', 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <h3 class="font-bold text-foreground text-sm">{{ $r3->user?->name }}</h3>
                                    <p class="text-[11px] font-mono text-muted-foreground mt-0.5">{{ $r3->roll_number ?: 'Candidate' }}</p>
                                </div>
                                <div class="pt-3 border-t border-border/60 text-xs flex items-center justify-around">
                                    <div>
                                        <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Score') }}</span>
                                        <span class="font-black text-foreground">{{ $r3->marks_obtained }} M</span>
                                    </div>
                                    <div>
                                        <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Percentage') }}</span>
                                        <span class="font-bold text-amber-700 dark:text-amber-400">{{ $r3->percentage }}%</span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- 4 to N Showcase -->
                @if($this->leaderboardLimit > 3)
                    <div class="space-y-3 pt-2">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                            {{ __('Featured Achievers (Ranks 4 to :limit)', ['limit' => $this->leaderboardLimit]) }}
                        </h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                            @foreach($this->leaderboardRankers->where('rank', '>', 3) as $r)
                                <div class="p-3 bg-card rounded-lg border border-border shadow-xs flex items-center gap-3" wire:key="student-ranker-{{ $r->rank }}">
                                    <div class="relative w-9 h-9 shrink-0">
                                        @if($r->user?->avatarUrl())
                                            <img src="{{ $r->user->avatarUrl() }}" alt="{{ $r->user->name }}" class="w-9 h-9 rounded-full object-cover border border-border">
                                        @else
                                            <div class="w-9 h-9 rounded-full bg-primary/10 text-primary font-bold text-xs flex items-center justify-center border border-border">
                                                {{ strtoupper(substr($r->user?->name ?? 'C', 0, 1)) }}
                                            </div>
                                        @endif
                                        <span class="absolute -bottom-1 -right-1 bg-secondary text-foreground text-[9px] font-black px-1 py-0.2 rounded-full border border-border">
                                            #{{ $r->rank }}
                                        </span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="font-bold text-foreground text-xs truncate">{{ $r->user?->name }}</h4>
                                        <p class="text-[10px] font-mono text-muted-foreground">{{ $r->roll_number }}</p>
                                        <div class="text-[11px] text-muted-foreground mt-0.5">
                                            <span class="font-bold text-foreground">{{ $r->marks_obtained }} M</span> ({{ $r->percentage }}%)
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif

    <!-- Student Marksheet Details Modal -->
    <x-ui.modal
        name="student-marksheet-modal"
        max-width="max-w-2xl"
        :title="__('Performance Breakdown & Evaluation Summary')"
        :description="$viewingResult ? $viewingResult->test?->title . ' (' . $viewingResult->test?->code . ')' : ''"
    >
        @if($viewingResult)
            <div class="space-y-4">
                <!-- Overview Pills -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 p-3 rounded-lg bg-secondary/30 border border-border/60 text-center">
                    <div>
                        <span class="text-[10px] text-muted-foreground uppercase font-bold">{{ __('Net Marks') }}</span>
                        <div class="text-lg font-black text-foreground mt-0.5">{{ $viewingResult->marks_obtained }} / {{ $viewingResult->total_marks }}</div>
                    </div>
                    <div>
                        <span class="text-[10px] text-muted-foreground uppercase font-bold">{{ __('Percentage') }}</span>
                        <div class="text-lg font-black text-primary mt-0.5">{{ $viewingResult->percentage }}%</div>
                    </div>
                    <div>
                        <span class="text-[10px] text-muted-foreground uppercase font-bold">{{ __('Merit Rank') }}</span>
                        <div class="text-lg font-black text-amber-500 mt-0.5">
                            {{ $viewingResult->rank ? '#' . $viewingResult->rank : '—' }}
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] text-muted-foreground uppercase font-bold">{{ __('Percentile') }}</span>
                        <div class="text-lg font-black text-foreground mt-0.5">
                            {{ $viewingResult->percentile !== null ? $viewingResult->percentile . '%' : 'N/A' }}
                        </div>
                    </div>
                </div>

                <!-- MCQ Breakdown if applicable -->
                @if($viewingResult->test?->isMcq())
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">{{ __('Answer Accuracy & Penalty Metrics') }}</h4>
                        <div class="grid grid-cols-3 gap-2.5 text-center">
                            <div class="p-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
                                <span class="text-[11px] font-bold text-emerald-500">Correct Answers (✓)</span>
                                <div class="text-xl font-black text-foreground mt-0.5">{{ $viewingResult->right_answers }}</div>
                                <span class="text-[10px] text-emerald-600">+{{ $viewingResult->positive_marks }} Marks</span>
                            </div>
                            <div class="p-2.5 rounded-lg bg-destructive/10 border border-destructive/20">
                                <span class="text-[11px] font-bold text-destructive">Incorrect (✗)</span>
                                <div class="text-xl font-black text-foreground mt-0.5">{{ $viewingResult->wrong_answers }}</div>
                                <span class="text-[10px] text-destructive">-{{ $viewingResult->negative_marks }} Penalty</span>
                            </div>
                            <div class="p-2.5 rounded-lg bg-secondary border border-border">
                                <span class="text-[11px] font-bold text-muted-foreground">Unattempted</span>
                                <div class="text-xl font-black text-foreground mt-0.5">{{ $viewingResult->blank_answers }}</div>
                                <span class="text-[10px] text-muted-foreground">0 Marks</span>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Feedback & Faculty Guidance -->
                @if($viewingResult->feedback || $viewingResult->remarks)
                    <div class="p-3 bg-card rounded-lg border border-border text-xs space-y-1.5">
                        <span class="font-bold text-foreground block">{{ __('Academic Guidance & Faculty Notes:') }}</span>
                        @if($viewingResult->feedback)
                            <p class="text-muted-foreground">{{ $viewingResult->feedback }}</p>
                        @endif
                        @if($viewingResult->remarks)
                            <p class="text-muted-foreground italic text-[11px]">{{ $viewingResult->remarks }}</p>
                        @endif
                    </div>
                @endif

                <!-- Modal Actions -->
                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-marksheet-modal')">
                        {{ __('Close') }}
                    </x-ui.button>

                    <a
                        href="{{ route('tests.download-marksheet', $viewingResult) }}"
                        target="_blank"
                        class="px-3 py-1.5 rounded-md bg-primary text-primary-foreground font-semibold text-xs flex items-center gap-1.5 hover:bg-primary/90 transition-colors"
                    >
                        <x-icon name="download" class="w-3.5 h-3.5" />
                        {{ __('Download Official Marksheet PDF') }}
                    </a>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
