<?php

use App\Models\Attendance;
use App\Models\Setting;
use App\Services\Attendance\AttendanceService;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('My Attendance & Timesheet')] class extends Component {
    #[Url(as: 'year')]
    public int $selectedYear = 2026;

    #[Url(as: 'month')]
    public int $selectedMonth = 10;

    public bool $webCheckinAllowed = true;

    public function mount(): void
    {
        if (! $this->selectedYear) {
            $this->selectedYear = (int) Carbon::now()->year;
        }
        if (! $this->selectedMonth) {
            $this->selectedMonth = (int) Carbon::now()->month;
        }

        $this->webCheckinAllowed = (bool) Setting::get('attendance.allow_staff_web_checkin', true);
    }

    #[Computed]
    public function todayAttendance()
    {
        return Attendance::where('user_id', auth()->id())
            ->where('date', Carbon::today()->toDateString())
            ->first();
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

    public function clockIn(AttendanceService $attendanceService): void
    {
        if (! $this->webCheckinAllowed) {
            Toast::dispatch($this, 'error', __('Web check-in is currently disabled by system administration.'));
            return;
        }

        try {
            $timeNow = Carbon::now()->format('H:i:s');
            $attendanceService->markSingle([
                'user_id' => auth()->id(),
                'date' => Carbon::today()->toDateString(),
                'status' => 'present',
                'check_in_time' => $timeNow,
                'remarks' => __('Web check-in from staff dashboard'),
            ], auth()->user());

            Toast::dispatch($this, 'success', __('Clocked in successfully at :time.', ['time' => $timeNow]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Clock-in failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function clockOut(AttendanceService $attendanceService): void
    {
        if (! $this->webCheckinAllowed) {
            Toast::dispatch($this, 'error', __('Web check-in is currently disabled.'));
            return;
        }

        try {
            $att = $this->todayAttendance;
            if (! $att) {
                Toast::dispatch($this, 'error', __('Please clock in first before clocking out.'));
                return;
            }

            $timeNow = Carbon::now()->format('H:i:s');
            $attendanceService->markSingle([
                'user_id' => auth()->id(),
                'date' => Carbon::today()->toDateString(),
                'status' => $att->status,
                'check_in_time' => $att->check_in_time,
                'check_out_time' => $timeNow,
                'remarks' => $att->remarks . ' | ' . __('Web check-out at :time', ['time' => $timeNow]),
            ], auth()->user());

            Toast::dispatch($this, 'success', __('Clocked out successfully at :time.', ['time' => $timeNow]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Clock-out failed: :err', ['err' => $e->getMessage()]));
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Staff Attendance & Timesheet') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ __('Staff Portal') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Official timesheet, punch in/out timestamps, hours worked, and monthly office calendar.') }}
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

            <a href="{{ route('portal.my-leaves.index') }}" wire:navigate>
                <x-ui.button variant="default" size="sm" class="cursor-pointer bg-primary text-primary-foreground font-semibold">
                    <x-icon name="calendar" class="w-4 h-4 mr-1.5" />
                    {{ __('Leave Desk') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    <!-- Live Web Clock In / Out Widget -->
    @if($webCheckinAllowed)
        <div class="p-5 rounded-xl border border-border bg-card shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-mono font-bold text-lg">
                    <x-icon name="clock" class="w-6 h-6" />
                </div>
                <div>
                    <h2 class="text-sm font-bold text-foreground">
                        {{ Carbon::today()->format('l, d F Y') }}
                    </h2>
                    <div class="flex items-center gap-3 text-xs text-muted-foreground mt-0.5 font-mono">
                        <span>Check-In: <strong class="text-emerald-600 dark:text-emerald-400">{{ $this->todayAttendance?->check_in_time ?: '--:--' }}</strong></span>
                        <span>Check-Out: <strong class="text-indigo-600 dark:text-indigo-400">{{ $this->todayAttendance?->check_out_time ?: '--:--' }}</strong></span>
                        <span>Total: <strong class="text-foreground">{{ $this->todayAttendance?->working_hours > 0 ? $this->todayAttendance->working_hours . ' hrs' : '-' }}</strong></span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                @if(! $this->todayAttendance || empty($this->todayAttendance->check_in_time))
                    <x-ui.button
                        variant="default"
                        size="sm"
                        wire:click="clockIn"
                        wire:loading.attr="disabled"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold cursor-pointer shadow-xs"
                    >
                        <x-icon name="log-in" class="w-4 h-4 mr-1.5" />
                        {{ __('Clock In Now') }}
                    </x-ui.button>
                @else
                    <x-ui.button
                        variant="default"
                        size="sm"
                        wire:click="clockOut"
                        wire:loading.attr="disabled"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold cursor-pointer shadow-xs"
                    >
                        <x-icon name="log-out" class="w-4 h-4 mr-1.5" />
                        {{ __('Clock Out') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    @endif

    <!-- Monthly Summary Statistics Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <!-- Rate % -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-semibold block">{{ __('Attendance Rate') }}</span>
            <span class="text-2xl font-bold font-mono text-foreground mt-1 block">
                {{ $this->monthlyMatrix['attendance_percentage'] }}%
            </span>
            <div class="mt-2 w-full bg-muted rounded-full h-1.5 overflow-hidden">
                <div class="bg-primary h-1.5 rounded-full" style="width: {{ $this->monthlyMatrix['attendance_percentage'] }}%"></div>
            </div>
        </div>

        <!-- Office Working Days -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-muted-foreground uppercase font-semibold block">{{ __('Office Working Days') }}</span>
            <span class="text-2xl font-bold font-mono text-foreground mt-1 block">
                {{ $this->monthlyMatrix['total_working_days'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Standard Days') }}</span>
        </div>

        <!-- Days Attended -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-emerald-600 dark:text-emerald-400 uppercase font-semibold block">{{ __('Days Attended') }}</span>
            <span class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400 mt-1 block">
                {{ $this->monthlyMatrix['present_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Days') }}</span>
        </div>

        <!-- Absents -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-rose-600 dark:text-rose-400 uppercase font-semibold block">{{ __('Absents') }}</span>
            <span class="text-2xl font-bold font-mono text-rose-600 dark:text-rose-400 mt-1 block">
                {{ $this->monthlyMatrix['absent_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Unplanned') }}</span>
        </div>

        <!-- On Leave -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-sky-600 dark:text-sky-400 uppercase font-semibold block">{{ __('Approved Leaves') }}</span>
            <span class="text-2xl font-bold font-mono text-sky-600 dark:text-sky-400 mt-1 block">
                {{ $this->monthlyMatrix['leave_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Excused') }}</span>
        </div>

        <!-- Late Arrivals -->
        <div class="p-3.5 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-[10px] text-amber-600 dark:text-amber-400 uppercase font-semibold block">{{ __('Late Arrivals') }}</span>
            <span class="text-2xl font-bold font-mono text-amber-600 dark:text-amber-400 mt-1 block">
                {{ $this->monthlyMatrix['late_count'] }}
            </span>
            <span class="text-[10px] text-muted-foreground mt-1 block">{{ __('Beyond Grace') }}</span>
        </div>
    </div>

    <!-- Monthly Calendar Matrix -->
    <div class="p-4 rounded-xl border border-border bg-card shadow-xs space-y-3">
        <div class="flex items-center justify-between pb-2 border-b border-border">
            <h2 class="text-sm font-semibold text-foreground">
                {{ Carbon::create($selectedYear, $selectedMonth, 1)->format('F Y') }} {{ __('Staff Timesheet Calendar') }}
            </h2>

            <div class="flex flex-wrap items-center gap-2 text-[10px] font-medium">
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-emerald-500"></span> Present</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-rose-500"></span> Absent</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-amber-500"></span> Late</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-sky-500"></span> Leave</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-zinc-400"></span> Off / Sat</span>
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

    <!-- Timesheet Records Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
            <h2 class="text-sm font-semibold text-foreground">
                {{ __('Timesheet Logs & Recorded Hours') }}
            </h2>
            <span class="text-xs text-muted-foreground">
                {{ count($this->dailyLogs) }} {{ __('logs') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs divide-y divide-border">
                <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Punch In Time') }}</th>
                        <th class="px-4 py-3">{{ __('Punch Out Time') }}</th>
                        <th class="px-4 py-3">{{ __('Effective Hours') }}</th>
                        <th class="px-4 py-3">{{ __('Late Arrival') }}</th>
                        <th class="px-4 py-3">{{ __('Capture Source') }}</th>
                        <th class="px-4 py-3">{{ __('Remarks') }}</th>
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
                                {{ __('No timesheet records logged for this month.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
