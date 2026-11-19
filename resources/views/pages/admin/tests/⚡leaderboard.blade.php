<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Setting;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Leaderboard & Top Rankers')] class extends Component {
    #[Url(as: 'test_id')]
    public ?int $selectedTestId = null;

    #[Url(as: 'scope')]
    public string $scope = 'test'; // 'test' or 'overall'

    public string $search = '';

    #[Url(as: 'top')]
    public int $topLimit = 10;

    public function mount(): void
    {
        // Load default limit from system settings if available
        $defaultSetting = (int) Setting::get('test_leaderboard_limit', 10);
        if (request()->missing('top') && $defaultSetting > 0) {
            $this->topLimit = $defaultSetting;
        }

        if (! $this->selectedTestId) {
            $latestTest = StudentTest::where('status', StudentTest::STATUS_COMPLETED)->latest('test_date')->first()
                ?? StudentTest::latest('test_date')->first();
            $this->selectedTestId = $latestTest?->id;
        }
    }

    public function saveDefaultLimit(): void
    {
        Setting::set('test_leaderboard_limit', $this->topLimit, auth()->id());
        Toast::dispatch($this, 'success', __('Default leaderboard limit saved to Top :count rankers.', ['count' => $this->topLimit]));
    }

    #[Computed]
    public function tests()
    {
        return StudentTest::with(['batch', 'course'])->orderByDesc('test_date')->get();
    }

    #[Computed]
    public function currentTest()
    {
        if (! $this->selectedTestId) {
            return null;
        }

        return StudentTest::with(['batch', 'course', 'section'])->find($this->selectedTestId);
    }

    #[Computed]
    public function topRankers()
    {
        $limit = max(3, $this->topLimit);

        if ($this->scope === 'test' && $this->currentTest) {
            return $this->currentTest->rankers($limit);
        }

        // Overall aggregate leaderboard across all completed tests
        $topUsers = StudentTestResult::with(['user.studentProfile', 'section', 'test'])
            ->where('attendance_status', StudentTestResult::STATUS_PRESENT)
            ->select('user_id', DB::raw('AVG(percentage) as avg_pct'), DB::raw('SUM(marks_obtained) as tot_marks'), DB::raw('COUNT(*) as tests_count'))
            ->groupBy('user_id')
            ->having('tests_count', '>=', 1)
            ->orderByDesc('avg_pct')
            ->limit($limit)
            ->get();

        return $topUsers->map(function ($row, $idx) {
            $user = User::with('studentProfile')->find($row->user_id);
            $enrollment = $user?->activeEnrollment ?? $user?->enrollments()->latest()->first();

            return (object) [
                'rank' => $idx + 1,
                'user' => $user,
                'roll_number' => $enrollment?->roll_no ?: $user?->student_id,
                'section' => $enrollment?->section,
                'marks_obtained' => round((float) $row->tot_marks, 2),
                'percentage' => round((float) $row->avg_pct, 2),
                'tests_count' => $row->tests_count,
                'is_top_ten' => true,
            ];
        });
    }

    #[Computed]
    public function fullMeritList()
    {
        if (! $this->currentTest) {
            return collect();
        }

        return $this->currentTest->results()
            ->with(['user.studentProfile', 'section'])
            ->where('attendance_status', StudentTestResult::STATUS_PRESENT)
            ->when($this->search, function (Builder $q) {
                $s = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($s) {
                    $sub->where('roll_number', 'like', $s)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $s));
                });
            })
            ->orderByRaw('CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END, `rank` ASC, marks_obtained DESC')
            ->get();
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">🏆</span>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Leaderboard & Top :count Rankers', ['count' => $this->topLimit]) }}</h1>
                <x-ui.badge variant="warning" size="sm">{{ __('Merit Standings') }}</x-ui.badge>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Celebrating top UPSC Civil Services Examination performers. Check your standing, stay motivated, and strive to feature among the elite rankers.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" href="{{ route('admin.tests.index') }}" wire:navigate>
                <x-icon name="arrow-left" class="w-4 h-4 mr-1.5" />
                {{ __('All Tests') }}
            </x-ui.button>
            <x-ui.button variant="primary" size="sm" href="{{ route('admin.tests.reports') }}" wire:navigate>
                <x-icon name="bar-chart-3" class="w-4 h-4 mr-1.5" />
                {{ __('Detailed Analytics') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Mode Selector, Test Dropdown & Dynamic Limit Controls -->
    <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 p-4 bg-card rounded-lg border border-border shadow-xs">
        <!-- Scope Switcher -->
        <div class="flex items-center gap-1.5 bg-secondary/60 p-1 rounded-md">
            <button
                type="button"
                wire:click="$set('scope', 'test')"
                class="px-3 py-1.5 rounded text-xs font-semibold transition-all {{ $scope === 'test' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
            >
                {{ __('Test-Specific Rankers') }}
            </button>
            <button
                type="button"
                wire:click="$set('scope', 'overall')"
                class="px-3 py-1.5 rounded text-xs font-semibold transition-all {{ $scope === 'overall' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
            >
                {{ __('Overall Aggregate Leaderboard') }}
            </button>
        </div>

        <!-- Dynamic Controls: Test Dropdown + Top Limit Selector -->
        <div class="flex flex-wrap items-center gap-2.5">
            @if($scope === 'test')
                <div class="w-full sm:w-72">
                    <select wire:model.live="selectedTestId" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs font-semibold shadow-xs outline-none focus-visible:ring-[2px] focus-visible:ring-ring">
                        @foreach($this->tests as $t)
                            <option value="{{ $t->id }}">
                                [{{ $t->code }}] {{ $t->title }} ({{ $t->test_date->format('d M Y') }})
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <!-- Configurable Top Rankers Limit -->
            <div class="flex items-center gap-1.5">
                <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Show Top:') }}</span>
                <select wire:model.live="topLimit" class="h-9 rounded-md border border-input bg-card px-2.5 text-xs font-bold shadow-xs outline-none text-foreground">
                    <option value="3">Top 3 (Podium)</option>
                    <option value="5">Top 5</option>
                    <option value="10">Top 10</option>
                    <option value="15">Top 15</option>
                    <option value="20">Top 20</option>
                    <option value="25">Top 25</option>
                    <option value="50">Top 50</option>
                    <option value="100">Top 100</option>
                </select>

                <button
                    type="button"
                    wire:click="saveDefaultLimit"
                    class="h-9 px-2.5 rounded-md border border-border bg-card hover:bg-secondary text-foreground text-xs font-semibold transition-colors flex items-center gap-1"
                    title="{{ __('Set current limit as default in system settings') }}"
                >
                    <x-icon name="check" class="w-3.5 h-3.5 text-primary" />
                    <span>{{ __('Save Default') }}</span>
                </button>
            </div>
        </div>
    </div>

    @php $currentTest = $this->currentTest; @endphp

    @if($currentTest && $scope === 'test')
        <div class="p-3 bg-secondary/30 rounded-lg border border-border/60 text-xs flex flex-wrap items-center justify-between gap-2">
            <div>
                <span class="font-bold text-foreground text-sm">{{ $currentTest->title }}</span>
                <span class="text-muted-foreground ml-2">({{ $currentTest->code }})</span>
            </div>
            <div class="flex items-center gap-3 text-muted-foreground">
                <span>Course: <strong class="text-foreground">{{ $currentTest->course?->name }}</strong></span>
                <span>&bull;</span>
                <span>Date: <strong class="text-foreground">{{ $currentTest->test_date->format('d M Y') }}</strong></span>
                <span>&bull;</span>
                <span>Max Marks: <strong class="text-foreground">{{ $currentTest->total_marks }}</strong></span>
            </div>
        </div>
    @endif

    <!-- Podium: Top 3 Achievers (High-Contrast Light & Dark Mode) -->
    @php
        $rank1 = $this->topRankers->firstWhere('rank', 1);
        $rank2 = $this->topRankers->firstWhere('rank', 2);
        $rank3 = $this->topRankers->firstWhere('rank', 3);
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-2">
        <!-- 2nd Rank (Silver) -->
        <div class="order-2 md:order-1 flex flex-col justify-end">
            @if($rank2)
                <div class="bg-linear-to-b from-slate-200/50 via-card to-card dark:from-slate-800/50 dark:via-card dark:to-card rounded-2xl border-2 border-slate-300 dark:border-slate-600 p-6 shadow-sm text-center space-y-4">
                    <div class="inline-flex items-center gap-1.5 bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-black text-xs px-3.5 py-1 rounded-full shadow-xs border border-slate-300 dark:border-slate-600 uppercase tracking-wider mx-auto">
                        <span class="text-sm">🥈</span>
                        <span>{{ __('RANK #2') }} &bull; {{ __('RUNNER-UP') }}</span>
                    </div>

                    <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-black text-xl flex items-center justify-center mx-auto border-2 border-slate-300 dark:border-slate-500 overflow-hidden shadow-xs">
                        @if($rank2->user?->avatarUrl())
                            <img src="{{ $rank2->user->avatarUrl() }}" alt="{{ $rank2->user->name }}" class="w-full h-full object-cover">
                        @else
                            {{ strtoupper(substr($rank2->user?->name ?? '2', 0, 1)) }}
                        @endif
                    </div>

                    <div>
                        <h3 class="font-bold text-foreground text-base">{{ $rank2->user?->name }}</h3>
                        <p class="text-xs font-mono text-muted-foreground mt-0.5">{{ $rank2->roll_number ?: 'SNTCSSC Candidate' }}</p>
                        <p class="text-[11px] text-muted-foreground">{{ $rank2->section?->name ? 'Sec ' . $rank2->section->name : 'All Sections' }}</p>
                    </div>

                    <div class="pt-3 border-t border-border/60 flex items-center justify-around text-xs">
                        <div>
                            <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Score') }}</span>
                            <span class="font-black text-foreground text-sm">{{ $rank2->marks_obtained }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Percentage') }}</span>
                            <span class="font-black text-slate-700 dark:text-slate-300 text-sm">{{ $rank2->percentage }}%</span>
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-border p-8 text-center text-muted-foreground text-xs">
                    {{ __('Rank 2 Awaiting Evaluation') }}
                </div>
            @endif
        </div>

        <!-- 1st Rank (Gold - Center & Elevated) -->
        <div class="order-1 md:order-2">
            @if($rank1)
                <div class="bg-linear-to-b from-amber-500/20 via-card to-card dark:from-amber-500/25 dark:via-card dark:to-card rounded-2xl border-2 border-amber-500 dark:border-amber-400 p-6 shadow-lg text-center space-y-4 ring-2 ring-amber-500/20 md:-translate-y-2">
                    <div class="inline-flex items-center gap-1.5 bg-linear-to-r from-amber-500 via-amber-400 to-amber-500 text-slate-950 font-black text-xs px-4 py-1.5 rounded-full shadow-md border border-amber-300/60 uppercase tracking-wider mx-auto">
                        <span class="text-sm">👑</span>
                        <span>{{ __('RANK #1') }} &bull; {{ __('TOPPER') }}</span>
                    </div>

                    <div class="w-20 h-20 rounded-full bg-amber-500/20 text-amber-600 dark:text-amber-400 font-black text-2xl flex items-center justify-center mx-auto border-4 border-amber-500 shadow-inner overflow-hidden">
                        @if($rank1->user?->avatarUrl())
                            <img src="{{ $rank1->user->avatarUrl() }}" alt="{{ $rank1->user->name }}" class="w-full h-full object-cover">
                        @else
                            {{ strtoupper(substr($rank1->user?->name ?? '1', 0, 1)) }}
                        @endif
                    </div>

                    <div>
                        <h3 class="font-black text-foreground text-lg">{{ $rank1->user?->name }}</h3>
                        <p class="text-xs font-mono text-amber-600 dark:text-amber-400 font-bold mt-0.5">{{ $rank1->roll_number ?: 'SNTCSSC Candidate' }}</p>
                        <p class="text-xs text-muted-foreground mt-0.5">{{ $rank1->section?->name ? 'Sec ' . $rank1->section->name : 'Apex Batch' }}</p>
                    </div>

                    <div class="pt-3 border-t border-amber-500/30 flex items-center justify-around text-xs">
                        <div>
                            <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Score Obtained') }}</span>
                            <span class="font-black text-amber-600 dark:text-amber-400 text-base">{{ $rank1->marks_obtained }} M</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Percentage') }}</span>
                            <span class="font-black text-foreground text-base">{{ $rank1->percentage }}%</span>
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-border p-8 text-center text-muted-foreground text-xs">
                    {{ __('Rank 1 Awaiting Evaluation') }}
                </div>
            @endif
        </div>

        <!-- 3rd Rank (Bronze) -->
        <div class="order-3 flex flex-col justify-end">
            @if($rank3)
                <div class="bg-linear-to-b from-amber-700/15 via-card to-card dark:from-amber-950/40 dark:via-card dark:to-card rounded-2xl border-2 border-amber-600/60 dark:border-amber-600/50 p-6 shadow-sm text-center space-y-4">
                    <div class="inline-flex items-center gap-1.5 bg-amber-700/20 dark:bg-amber-800/50 text-amber-900 dark:text-amber-200 font-black text-xs px-3.5 py-1 rounded-full shadow-xs border border-amber-600/40 dark:border-amber-600/60 uppercase tracking-wider mx-auto">
                        <span class="text-sm">🥉</span>
                        <span>{{ __('RANK #3') }} &bull; {{ __('2ND RUNNER-UP') }}</span>
                    </div>

                    <div class="w-16 h-16 rounded-full bg-amber-700/15 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 font-black text-xl flex items-center justify-center mx-auto border-2 border-amber-700/60 dark:border-amber-500/60 overflow-hidden shadow-xs">
                        @if($rank3->user?->avatarUrl())
                            <img src="{{ $rank3->user->avatarUrl() }}" alt="{{ $rank3->user->name }}" class="w-full h-full object-cover">
                        @else
                            {{ strtoupper(substr($rank3->user?->name ?? '3', 0, 1)) }}
                        @endif
                    </div>

                    <div>
                        <h3 class="font-bold text-foreground text-base">{{ $rank3->user?->name }}</h3>
                        <p class="text-xs font-mono text-muted-foreground mt-0.5">{{ $rank3->roll_number ?: 'SNTCSSC Candidate' }}</p>
                        <p class="text-[11px] text-muted-foreground">{{ $rank3->section?->name ? 'Sec ' . $rank3->section->name : 'All Sections' }}</p>
                    </div>

                    <div class="pt-3 border-t border-border/60 flex items-center justify-around text-xs">
                        <div>
                            <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Score') }}</span>
                            <span class="font-black text-foreground text-sm">{{ $rank3->marks_obtained }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block uppercase font-bold">{{ __('Percentage') }}</span>
                            <span class="font-black text-amber-700 dark:text-amber-400 text-sm">{{ $rank3->percentage }}%</span>
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-border p-8 text-center text-muted-foreground text-xs">
                    {{ __('Rank 3 Awaiting Evaluation') }}
                </div>
            @endif
        </div>
    </div>

    <!-- Rankers 4 to N Showcase Grid -->
    @if($this->topLimit > 3)
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    {{ __('Featured Achievers (Ranks 4 to :limit)', ['limit' => $this->topLimit]) }}
                </h2>
                <span class="text-xs text-muted-foreground font-mono">
                    {{ count($this->topRankers->where('rank', '>', 3)) }} candidates featured
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                @forelse($this->topRankers->where('rank', '>', 3) as $ranker)
                    <div class="p-3.5 bg-card rounded-lg border border-border shadow-xs flex items-center gap-3 relative overflow-hidden" wire:key="ranker-{{ $ranker->rank }}">
                        <!-- Candidate Photo with Rank Badge -->
                        <div class="relative w-11 h-11 shrink-0">
                            @if($ranker->user?->avatarUrl())
                                <img src="{{ $ranker->user->avatarUrl() }}" alt="{{ $ranker->user->name }}" class="w-11 h-11 rounded-full object-cover border border-border">
                            @else
                                <div class="w-11 h-11 rounded-full bg-primary/10 text-primary font-bold text-sm flex items-center justify-center border border-border">
                                    {{ strtoupper(substr($ranker->user?->name ?? 'C', 0, 1)) }}
                                </div>
                            @endif
                            <span class="absolute -bottom-1 -right-1 bg-secondary text-foreground text-[10px] font-black px-1.5 py-0.2 rounded-full border border-border shadow-xs">
                                #{{ $ranker->rank }}
                            </span>
                        </div>

                        <div class="flex-1 min-w-0">
                            <h4 class="font-bold text-foreground text-xs truncate">{{ $ranker->user?->name }}</h4>
                            <p class="text-[11px] font-mono text-muted-foreground truncate">{{ $ranker->roll_number ?: 'Candidate' }}</p>
                            <div class="flex items-center justify-between text-[11px] mt-1 text-muted-foreground">
                                <span>Score: <strong class="text-foreground">{{ $ranker->marks_obtained }}</strong></span>
                                <span class="font-bold text-primary">{{ $ranker->percentage }}%</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full p-4 rounded-lg border border-dashed border-border text-center text-muted-foreground text-xs">
                        {{ __('No further top positions logged yet.') }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <!-- Complete Merit Standings Table (for Test scope) -->
    @if($scope === 'test' && $currentTest)
        <div class="rounded-lg border border-border bg-card shadow-xs overflow-hidden space-y-2">
            <div class="p-4 border-b border-border bg-muted/30 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                    {{ __('Complete Examination Merit List (:count Candidates)', ['count' => count($this->fullMeritList)]) }}
                </h3>

                <div class="relative w-full sm:w-64">
                    <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search candidate in merit list...') }}"
                        class="h-8 w-full rounded border border-input bg-transparent pl-8 pr-2 text-xs outline-none focus-visible:ring-[2px] focus-visible:ring-ring"
                    />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-border bg-muted/20 text-muted-foreground uppercase text-[10px]">
                            <th class="px-4 py-2.5 text-center w-12">Rank</th>
                            <th class="px-4 py-2.5">Roll No</th>
                            <th class="px-4 py-2.5">Candidate Name</th>
                            <th class="px-4 py-2.5">Section</th>
                            <th class="px-4 py-2.5 text-right">Marks Obtained</th>
                            <th class="px-4 py-2.5 text-right">% Score</th>
                            <th class="px-4 py-2.5 text-center">Percentile</th>
                            <th class="px-4 py-2.5 text-right">Marksheet</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @forelse($this->fullMeritList as $row)
                            <tr class="hover:bg-muted/30 {{ $row->rank && $row->rank <= 3 ? 'bg-amber-500/5 font-semibold' : '' }}">
                                <td class="px-4 py-2.5 text-center font-bold">
                                    @if($row->rank === 1) 🥇 @elseif($row->rank === 2) 🥈 @elseif($row->rank === 3) 🥉 @elseif($row->rank) #{{ $row->rank }} @else — @endif
                                </td>
                                <td class="px-4 py-2.5 font-mono">{{ $row->roll_number ?: 'N/A' }}</td>
                                <td class="px-4 py-2.5 text-foreground font-medium">{{ $row->user?->name }}</td>
                                <td class="px-4 py-2.5 text-muted-foreground">{{ $row->section?->name ?? 'Default' }}</td>
                                <td class="px-4 py-2.5 text-right font-bold text-foreground">{{ $row->marks_obtained }}</td>
                                <td class="px-4 py-2.5 text-right">{{ $row->percentage }}%</td>
                                <td class="px-4 py-2.5 text-center font-mono text-muted-foreground">{{ $row->percentile !== null ? $row->percentile . '%' : '—' }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('tests.download-marksheet', $row) }}" target="_blank" class="text-primary hover:underline font-medium">
                                        View PDF
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-6 text-center text-muted-foreground">
                                    {{ __('No merit list entries found.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
