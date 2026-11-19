<?php

use App\Models\Attendance;
use App\Models\Enrollment;
use App\Services\Attendance\AttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('My Attendance Record')] class extends Component {
    #[Url(as: 'year')]
    public int $selectedYear = 2026;

    #[Url(as: 'month')]
    public int $selectedMonth = 10;

    public function mount(): void
    {
        if (! $this->selectedYear) {
            $this->selectedYear = (int) Carbon::now()->year;
        }
        if (! $this->selectedMonth) {
            $this->selectedMonth = (int) Carbon::now()->month;
        }
    }

    #[Computed]
    public function activeEnrollment()
    {
        return auth()->user()->activeEnrollments()->with(['course', 'batch', 'section'])->first();
    }

    #[Computed]
    public function monthlyMatrix(): array
    {
        $attendanceService = app(AttendanceService::class);
        return $attendanceService->getMonthlyUserAttendanceMatrix(auth()->user(), $this->selectedYear, $this->selectedMonth);
    }

    #[Computed]
    public function dailyLogs()
    {
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        return Attendance::where('user_id', auth()->id())
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['leaveApplication.category'])
            ->orderBy('date', 'desc')
            ->get();
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('My Classroom Attendance Dossier') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                    {{ __('Student Portal') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Official classroom attendance register, UPSC test eligibility criteria, and daily punch timestamps.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <!-- Year & Month Selectors -->
            <select wire:model.live="selectedYear" class="px-3 py-1.5 text-xs bg-background border border-border rounded-lg font-mono">
                @for($y = Carbon::now()->year; $y >= Carbon::now()->year - 2; $y--)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endfor
            </select>

            <select wire:model.live="selectedMonth" class="px-3 py-1.5 text-xs bg-background border border-border rounded-lg font-mono">
                @for($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}">{{ Carbon::create(2026, $m, 1)->format('F') }}</option>
                @endfor
            </select>

            <a href="{{ route('student.leaves.index') }}" wire:navigate>
                <x-ui.button variant="default" size="sm" class="cursor-pointer bg-primary text-primary-foreground font-semibold">
                    <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                    {{ __('Apply Leave') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    <!-- Active Course & Attendance Status Banner -->
    <div class="p-4 rounded-xl border border-border bg-card shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                <x-icon name="graduation-cap" class="w-5 h-5" />
            </div>
            <div>
                <h2 class="text-sm font-bold text-foreground">{{ $this->activeEnrollment?->course?->name ?? __('Civil Services Coaching Programme') }}</h2>
                <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground mt-0.5">
                    <span>Batch: <strong class="text-foreground">{{ $this->activeEnrollment?->batch?->name ?? 'General' }}</strong></span>
                    <span>Roll No: <strong class="text-foreground font-mono">{{ $this->activeEnrollment?->roll_no ?? auth()->user()->student_id }}</strong></span>
                </div>
            </div>
        </div>

        <!-- 75% Attendance UPSC Criteria Badge -->
        <div>
            @if($this->monthlyMatrix['attendance_percentage'] >= 75)
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20 text-xs font-semibold">
                    <x-icon name="check-circle" class="w-4 h-4 text-emerald-600 shrink-0" />
                    <span>{{ __('Eligible for Test Series & Mentorship (>= 75%)') }}</span>
                </div>
            @else
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-rose-500/10 text-rose-700 dark:text-rose-300 border border-rose-500/20 text-xs font-semibold">
                    <x-icon name="alert-triangle" class="w-4 h-4 text-rose-600 shrink-0" />
                    <span>{{ __('Attendance Shortage Warning (< 75% Criteria)') }}</span>
                </div>
            @endif
        </div>
    </div>

    <!-- Monthly Summary Statistics Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <!-- Rate % -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-semibold block">{{ __('Attendance Rate') }}</span>
            <span class="text-2xl font-bold font-mono text-foreground mt-1 block">
                {{ $this->monthlyMatrix['attendance_percentage'] }}%
            </span>
            <div class="mt-2 w-full bg-muted rounded-full h-1.5 overflow-hidden">
                <div class="h-1.5 rounded-full {{ $this->monthlyMatrix['attendance_percentage'] >= 75 ? 'bg-emerald-500' : 'bg-rose-500' }}" style="width: {{ $this->monthlyMatrix['attendance_percentage'] }}%"></div>
            </div>
        </div>

        <!-- Working Days -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-semibold block">{{ __('Total Working Days') }}</span>
            <span class="text-2xl font-bold font-mono text-foreground mt-1 block">
                {{ $this->monthlyMatrix['total_working_days'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Class Days') }}</span>
        </div>

        <!-- Present -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-emerald-600 dark:text-emerald-400 uppercase font-semibold block">{{ __('Classes Attended') }}</span>
            <span class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400 mt-1 block">
                {{ $this->monthlyMatrix['present_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Full Days') }}</span>
        </div>

        <!-- Absent -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-rose-600 dark:text-rose-400 uppercase font-semibold block">{{ __('Absent') }}</span>
            <span class="text-2xl font-bold font-mono text-rose-600 dark:text-rose-400 mt-1 block">
                {{ $this->monthlyMatrix['absent_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Unexcused') }}</span>
        </div>

        <!-- On Leave -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-sky-600 dark:text-sky-400 uppercase font-semibold block">{{ __('Approved Leave') }}</span>
            <span class="text-2xl font-bold font-mono text-sky-600 dark:text-sky-400 mt-1 block">
                {{ $this->monthlyMatrix['leave_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Excused') }}</span>
        </div>

        <!-- Late Punches -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-amber-600 dark:text-amber-400 uppercase font-semibold block">{{ __('Late Arrivals') }}</span>
            <span class="text-2xl font-bold font-mono text-amber-600 dark:text-amber-400 mt-1 block">
                {{ $this->monthlyMatrix['late_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Beyond Grace') }}</span>
        </div>
    </div>

    <!-- Monthly Calendar Visual Matrix Grid -->
    <div class="p-4 rounded-xl border border-border bg-card shadow-xs space-y-3">
        <div class="flex items-center justify-between pb-2 border-b border-border">
            <h2 class="text-sm font-semibold text-foreground">
                {{ Carbon::create($selectedYear, $selectedMonth, 1)->format('F Y') }} {{ __('Classroom Attendance Calendar') }}
            </h2>

            <div class="flex flex-wrap items-center gap-2 text-[10px] font-medium">
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-emerald-500"></span> Present</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-rose-500"></span> Absent</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-amber-500"></span> Late</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-sky-500"></span> On Leave</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-zinc-400"></span> Off / Holiday</span>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-2">
            @foreach($this->monthlyMatrix['days'] as $d)
                <div class="p-2.5 rounded-lg border text-center transition-all {{ match($d['status']) {
                    'present' => 'bg-emerald-50/70 dark:bg-emerald-950/20 border-emerald-300 dark:border-emerald-800',
                    'absent' => 'bg-rose-50/70 dark:bg-rose-950/20 border-rose-300 dark:border-rose-800',
                    'late' => 'bg-amber-50/70 dark:bg-amber-950/20 border-amber-300 dark:border-amber-800',
                    'half_day' => 'bg-purple-50/70 dark:bg-purple-950/20 border-purple-300 dark:border-purple-800',
                    'on_leave' => 'bg-sky-50/70 dark:bg-sky-950/20 border-sky-300 dark:border-sky-800',
                    'future' => 'bg-transparent border-dashed border-border/40 text-muted-foreground/40',
                    default => 'bg-muted/30 border-border/60 text-muted-foreground',
                } }}">
                    <div class="flex items-center justify-between text-[11px] font-mono">
                        <span class="font-bold text-foreground">{{ $d['day_number'] }}</span>
                        <span class="text-muted-foreground font-sans uppercase text-[9px]">{{ $d['day_name'] }}</span>
                    </div>

                    <div class="mt-2">
                        @if($d['status'] === 'present')
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-600 text-white block">P</span>
                        @elseif($d['status'] === 'absent')
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-600 text-white block">A</span>
                        @elseif($d['status'] === 'late')
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-600 text-white block">L (+{{ $d['late_minutes'] }}m)</span>
                        @elseif($d['status'] === 'half_day')
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-600 text-white block">HD</span>
                        @elseif($d['status'] === 'on_leave')
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-sky-600 text-white block">LEAVE</span>
                        @elseif($d['status'] === 'weekly_off')
                            <span class="text-[10px] text-muted-foreground block font-sans">Off</span>
                        @elseif($d['status'] === 'holiday')
                            <span class="text-[10px] text-muted-foreground block font-sans truncate" title="{{ $d['label'] }}">Holiday</span>
                        @elseif($d['status'] === 'future')
                            <span class="text-[10px] text-muted-foreground/30 block">-</span>
                        @else
                            <span class="text-[10px] text-muted-foreground block font-sans">{{ $d['label'] }}</span>
                        @endif
                    </div>

                    @if($d['check_in'])
                        <div class="mt-1 text-[9px] font-mono text-muted-foreground truncate">
                            {{ $d['check_in'] }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <!-- Daily Punch Records Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
            <h2 class="text-sm font-semibold text-foreground">
                {{ __('Daily Attendance Punch Logs') }}
            </h2>
            <span class="text-xs text-muted-foreground">
                {{ count($this->dailyLogs) }} {{ __('recorded entries') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs divide-y divide-border">
                <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Check-In Time') }}</th>
                        <th class="px-4 py-3">{{ __('Check-Out Time') }}</th>
                        <th class="px-4 py-3">{{ __('Hours Attended') }}</th>
                        <th class="px-4 py-3">{{ __('Late Minutes') }}</th>
                        <th class="px-4 py-3">{{ __('Entry Source') }}</th>
                        <th class="px-4 py-3">{{ __('Notes') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60 font-mono text-[11px]">
                    @forelse($this->dailyLogs as $record)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 font-sans">
                                <strong class="text-foreground block">{{ $record->date ? $record->date->format('d M, Y (D)') : '' }}</strong>
                                <span class="text-[10px] text-muted-foreground font-mono">{{ $record->attendance_no }}</span>
                            </td>

                            <td class="px-4 py-3 font-sans">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-semibold border {{ $record->statusBadgeClass() }}">
                                    {{ $record->statusLabel() }}
                                </span>
                                @if($record->leaveApplication)
                                    <span class="text-[10px] text-sky-600 dark:text-sky-400 block mt-0.5">
                                        {{ $record->leaveApplication->category?->name }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-emerald-600 dark:text-emerald-400">
                                {{ $record->check_in_time ?: '--:--' }}
                            </td>

                            <td class="px-4 py-3 text-indigo-600 dark:text-indigo-400">
                                {{ $record->check_out_time ?: '--:--' }}
                            </td>

                            <td class="px-4 py-3 text-foreground font-semibold">
                                {{ $record->working_hours > 0 ? $record->working_hours . ' hrs' : '-' }}
                            </td>

                            <td class="px-4 py-3">
                                @if($record->late_minutes > 0)
                                    <span class="text-amber-600 dark:text-amber-400 font-bold">+{{ $record->late_minutes }}m</span>
                                @else
                                    <span class="text-muted-foreground">-</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 font-sans capitalize text-muted-foreground">
                                {{ str_replace('_', ' ', $record->entry_source) }}
                            </td>

                            <td class="px-4 py-3 font-sans text-muted-foreground max-w-xs truncate">
                                {{ $record->remarks ?: '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-muted-foreground font-sans">
                                {{ __('No attendance records logged for this month.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
