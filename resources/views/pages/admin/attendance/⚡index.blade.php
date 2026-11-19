<?php

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\BiometricDevice;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LeaveApplication;
use App\Models\User;
use App\Services\Attendance\AttendanceNotificationService;
use App\Services\Attendance\AttendanceService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Attendance Command Center')] class extends Component {
    use WithPagination;

    #[Url(as: 'date')]
    public string $selectedDate = '';

    #[Url(as: 'type')]
    public string $userTypeFilter = 'all'; // 'all', 'student', 'staff'

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 15;

    public function mount(): void
    {
        if (empty($this->selectedDate)) {
            $this->selectedDate = Carbon::today()->toDateString();
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingUserTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::when($this->courseFilter, function ($q) {
            $q->whereHas('courses', fn ($cq) => $cq->where('courses.id', $this->courseFilter));
        })
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $date = $this->selectedDate ?: Carbon::today()->toDateString();

        // 1. Staff Statistics
        $totalStaff = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['Administrator', 'Staff', 'Faculty', 'Accountant', 'Librarian']);
        })->count();

        $staffPresent = Attendance::where('date', $date)
            ->where('user_type', 'staff')
            ->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE, Attendance::STATUS_HALF_DAY])
            ->count();

        $staffRate = $totalStaff > 0 ? round(($staffPresent / $totalStaff) * 100, 1) : 0;

        // 2. Student Statistics
        $totalActiveStudents = Enrollment::where('status', 'active')->distinct('user_id')->count('user_id');

        $studentsPresent = Attendance::where('date', $date)
            ->where('user_type', 'student')
            ->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE, Attendance::STATUS_HALF_DAY])
            ->count();

        $studentRate = $totalActiveStudents > 0 ? round(($studentsPresent / $totalActiveStudents) * 100, 1) : 0;

        // 3. Totals
        $todayAbsents = Attendance::where('date', $date)->where('status', Attendance::STATUS_ABSENT)->count();
        $todayLeaves = Attendance::where('date', $date)->where('status', Attendance::STATUS_ON_LEAVE)->count();
        $onlineDevices = BiometricDevice::online()->count();
        $totalDevices = BiometricDevice::count();

        return [
            'staff_rate' => $staffRate,
            'staff_present' => $staffPresent,
            'total_staff' => $totalStaff,
            'student_rate' => $studentRate,
            'students_present' => $studentsPresent,
            'total_students' => $totalActiveStudents,
            'absent_count' => $todayAbsents,
            'leave_count' => $todayLeaves,
            'online_devices' => $onlineDevices,
            'total_devices' => $totalDevices,
        ];
    }

    #[Computed]
    public function attendances()
    {
        $date = $this->selectedDate ?: Carbon::today()->toDateString();

        return Attendance::query()
            ->where('date', $date)
            ->when($this->userTypeFilter !== 'all', fn (Builder $q) => $q->where('user_type', $this->userTypeFilter))
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->courseFilter, fn (Builder $q) => $q->where('course_id', $this->courseFilter))
            ->when($this->batchFilter, fn (Builder $q) => $q->where('batch_id', $this->batchFilter))
            ->when($this->search, function (Builder $q) {
                $q->whereHas('user', function ($uq) {
                    $uq->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhere('student_id', 'like', "%{$this->search}%")
                        ->orWhere('employee_id', 'like', "%{$this->search}%");
                })->orWhere('attendance_no', 'like', "%{$this->search}%");
            })
            ->with(['user', 'course', 'batch', 'leaveApplication.category', 'marker'])
            ->orderBy('user_type')
            ->orderBy('id', 'desc')
            ->paginate($this->perPage);
    }

    public function processBiometricSync(AttendanceService $attendanceService): void
    {
        try {
            $result = $attendanceService->processBiometricPunches(300);
            Toast::dispatch($this, 'success', __('Processed :count biometric punches (:errors errors).', [
                'count' => $result['processed'],
                'errors' => $result['errors'],
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Biometric sync failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function sendDailyAbsentAlerts(AttendanceNotificationService $notificationService): void
    {
        try {
            $date = $this->selectedDate ?: Carbon::today()->toDateString();
            $result = $notificationService->dispatchDailyAbsenteeDigest($date);
            Toast::dispatch($this, 'success', __('Dispatched :sent absent notifications (:failed failed).', [
                'sent' => $result['total_sent'],
                'failed' => $result['failed'],
            ]));
            $this->dispatch('modal-close', name: 'send-absent-alerts-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Notification dispatch failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmSendDailyAbsentAlerts(): void
    {
        $this->dispatch('modal-open', name: 'send-absent-alerts-modal');
    }

    public function resendNotification(int $attendanceId, AttendanceNotificationService $notificationService): void
    {
        try {
            $attendance = Attendance::findOrFail($attendanceId);
            $notificationService->notifyAttendanceMarked($attendance);
            Toast::dispatch($this, 'success', __('Attendance notification dispatched successfully to :name.', ['name' => $attendance->user?->name]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to send notification: :err', ['err' => $e->getMessage()]));
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Attendance Command Center') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                    {{ __('Live & Biometric') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Unified attendance monitoring, biometric sync, and live status for staff & students.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button
                variant="outline"
                size="sm"
                wire:click="processBiometricSync"
                wire:loading.attr="disabled"
                class="cursor-pointer"
            >
                <x-icon name="refresh-cw" class="w-4 h-4 mr-1.5" wire:loading.class="animate-spin" wire:target="processBiometricSync" />
                {{ __('Sync Biometric') }}
            </x-ui.button>

            <x-ui.button
                variant="outline"
                size="sm"
                wire:click="confirmSendDailyAbsentAlerts"
                class="cursor-pointer text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-500/10"
            >
                <x-icon name="bell" class="w-4 h-4 mr-1.5" />
                {{ __('Send Absent Alerts') }}
            </x-ui.button>

            @can('attendance.manage')
                <a href="{{ route('admin.attendance.mark') }}" wire:navigate>
                    <x-ui.button variant="default" size="sm" class="cursor-pointer bg-primary text-primary-foreground">
                        <x-icon name="clipboard-check" class="w-4 h-4 mr-1.5" />
                        {{ __('Daily Register') }}
                    </x-ui.button>
                </a>
            @endcan
        </div>
    </div>

    <!-- Quick Navigation Bar -->
    <div class="flex items-center gap-2 overflow-x-auto pb-1 border-b border-border/60">
        <a href="{{ route('admin.attendance.index') }}" wire:navigate class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary/10 text-primary border border-primary/20">
            {{ __('Overview & Live Feed') }}
        </a>
        <a href="{{ route('admin.attendance.mark') }}" wire:navigate class="px-3 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">
            {{ __('Single & Bulk Mark') }}
        </a>
        <a href="{{ route('admin.attendance.reports') }}" wire:navigate class="px-3 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">
            {{ __('Monthly Matrix & Reports') }}
        </a>
        <a href="{{ route('admin.attendance.biometric') }}" wire:navigate class="px-3 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">
            {{ __('Biometric Machines') }}
        </a>
        <a href="{{ route('admin.attendance.calendar') }}" wire:navigate class="px-3 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">
            {{ __('Working Days & Holidays') }}
        </a>
        <a href="{{ route('admin.leaves.index') }}" wire:navigate class="px-3 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">
            {{ __('Leaves Desk') }}
        </a>
        <a href="{{ route('admin.settings.attendance') }}" wire:navigate class="px-3 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">
            {{ __('Settings & Alerts') }}
        </a>
    </div>

    <!-- KPI Cards Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Staff Attendance -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Staff Attendance') }}</span>
                <span class="p-2 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="user-check" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-bold font-mono text-foreground">{{ $this->stats['staff_rate'] }}%</span>
                <span class="text-xs text-muted-foreground">({{ $this->stats['staff_present'] }} / {{ $this->stats['total_staff'] }})</span>
            </div>
            <div class="mt-2 w-full bg-muted rounded-full h-1.5 overflow-hidden">
                <div class="bg-emerald-500 h-1.5 rounded-full" style="width: {{ $this->stats['staff_rate'] }}%"></div>
            </div>
        </div>

        <!-- Student Attendance -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Student Attendance') }}</span>
                <span class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="graduation-cap" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-bold font-mono text-foreground">{{ $this->stats['student_rate'] }}%</span>
                <span class="text-xs text-muted-foreground">({{ $this->stats['students_present'] }} / {{ $this->stats['total_students'] }})</span>
            </div>
            <div class="mt-2 w-full bg-muted rounded-full h-1.5 overflow-hidden">
                <div class="bg-indigo-500 h-1.5 rounded-full" style="width: {{ $this->stats['student_rate'] }}%"></div>
            </div>
        </div>

        <!-- Today Absentees & Leaves -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Absents & Leaves') }}</span>
                <span class="p-2 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400">
                    <x-icon name="user-x" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-3">
                <div>
                    <span class="text-xl font-bold font-mono text-rose-600 dark:text-rose-400">{{ $this->stats['absent_count'] }}</span>
                    <span class="text-[11px] text-muted-foreground block">{{ __('Absent') }}</span>
                </div>
                <div class="border-l border-border pl-3">
                    <span class="text-xl font-bold font-mono text-sky-600 dark:text-sky-400">{{ $this->stats['leave_count'] }}</span>
                    <span class="text-[11px] text-muted-foreground block">{{ __('On Leave') }}</span>
                </div>
            </div>
            <p class="text-[11px] text-muted-foreground mt-2">
                {{ __('Auto-synced from approved leave applications') }}
            </p>
        </div>

        <!-- Biometric Devices -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Biometric Devices') }}</span>
                <span class="p-2 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400">
                    <x-icon name="fingerprint" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-bold font-mono text-foreground">{{ $this->stats['online_devices'] }} / {{ $this->stats['total_devices'] }}</span>
                <span class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold">{{ __('Online') }}</span>
            </div>
            <p class="text-[11px] text-muted-foreground mt-2">
                {{ __('Supports ZKTeco, eSSL, RFID, and face recognition machines') }}
            </p>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-4 rounded-xl border border-border bg-card shadow-xs space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <!-- Date Filter -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Date') }}</label>
                <input
                    type="date"
                    wire:model.live="selectedDate"
                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                />
            </div>

            <!-- Role / Type Filter -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Target Role') }}</label>
                <select
                    wire:model.live="userTypeFilter"
                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                >
                    <option value="all">{{ __('All Users (Staff + Students)') }}</option>
                    <option value="student">{{ __('Students Only') }}</option>
                    <option value="staff">{{ __('Office Staff & Faculty Only') }}</option>
                </select>
            </div>

            <!-- Status Filter -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Status') }}</label>
                <select
                    wire:model.live="statusFilter"
                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                >
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="present">{{ __('Present') }}</option>
                    <option value="absent">{{ __('Absent') }}</option>
                    <option value="late">{{ __('Late') }}</option>
                    <option value="half_day">{{ __('Half Day') }}</option>
                    <option value="on_leave">{{ __('On Leave') }}</option>
                </select>
            </div>

            <!-- Course Filter -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Course') }}</label>
                <select
                    wire:model.live="courseFilter"
                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                >
                    <option value="">{{ __('All Courses') }}</option>
                    @foreach($this->courses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Batch Filter -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Batch') }}</label>
                <select
                    wire:model.live="batchFilter"
                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                >
                    <option value="">{{ __('All Batches') }}</option>
                    @foreach($this->batches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Search -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Search User') }}</label>
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Name, ID, Email...') }}"
                        class="w-full pl-8 pr-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                    />
                    <x-icon name="search" class="w-3.5 h-3.5 text-muted-foreground absolute left-2.5 top-2.5" />
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Live Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
            <h2 class="text-sm font-semibold text-foreground flex items-center gap-2">
                <span>{{ __('Daily Attendance Feed') }}</span>
                <span class="text-xs font-normal text-muted-foreground font-mono">
                    ({{ Carbon::parse($selectedDate)->format('d M, Y') }})
                </span>
            </h2>

            <span class="text-xs text-muted-foreground">
                {{ __('Showing :count records', ['count' => $this->attendances->total()]) }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs divide-y divide-border">
                <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Attendance No / Source') }}</th>
                        <th class="px-4 py-3">{{ __('User & Role') }}</th>
                        <th class="px-4 py-3">{{ __('Course / Dept') }}</th>
                        <th class="px-4 py-3">{{ __('Punch In / Out') }}</th>
                        <th class="px-4 py-3">{{ __('Hours / Late') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Notification') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($this->attendances as $record)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3">
                                <span class="font-mono font-medium text-foreground block">{{ $record->attendance_no }}</span>
                                <span class="text-[10px] text-muted-foreground flex items-center gap-1 mt-0.5">
                                    <x-icon name="{{ match($record->entry_source) { 'biometric_device' => 'fingerprint', 'system_auto_leave' => 'calendar', default => 'edit' } }}" class="w-3 h-3" />
                                    {{ ucfirst(str_replace('_', ' ', $record->entry_source)) }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs uppercase shrink-0">
                                        {{ substr($record->user?->name ?? 'U', 0, 2) }}
                                    </div>
                                    <div>
                                        <span class="font-medium text-foreground block">{{ $record->user?->name ?? __('Unknown') }}</span>
                                        <span class="text-[10px] text-muted-foreground font-mono">
                                            {{ $record->user?->student_id ?? $record->user?->employee_id ?? $record->user?->email }}
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span class="font-medium text-foreground block">
                                    {{ $record->course?->name ?? $record->user?->staff_department ?? __('General Staff') }}
                                </span>
                                @if($record->batch)
                                    <span class="text-[10px] text-muted-foreground block">Batch: {{ $record->batch->name }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 font-mono">
                                <div class="flex items-center gap-1.5 text-xs">
                                    <span class="text-emerald-600 dark:text-emerald-400">{{ $record->check_in_time ?: '--:--' }}</span>
                                    <span class="text-muted-foreground">→</span>
                                    <span class="text-indigo-600 dark:text-indigo-400">{{ $record->check_out_time ?: '--:--' }}</span>
                                </div>
                            </td>

                            <td class="px-4 py-3 font-mono">
                                <span class="block text-foreground font-medium">{{ $record->working_hours > 0 ? $record->working_hours . ' hrs' : '-' }}</span>
                                @if($record->late_minutes > 0)
                                    <span class="text-[10px] text-amber-600 dark:text-amber-400 font-semibold block">
                                        +{{ $record->late_minutes }}m late
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold border {{ $record->statusBadgeClass() }}">
                                    {{ $record->statusLabel() }}
                                </span>
                                @if($record->leaveApplication)
                                    <span class="text-[10px] text-sky-600 dark:text-sky-400 block mt-0.5 truncate max-w-[140px]" title="{{ $record->leaveApplication->application_no }}">
                                        {{ $record->leaveApplication->category?->name }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if($record->notification_sent)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">
                                        <x-icon name="check-check" class="w-3.5 h-3.5" />
                                        {{ __('Sent') }}
                                    </span>
                                @else
                                    <span class="text-[11px] text-muted-foreground">{{ __('Pending') }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="resendNotification({{ $record->id }})"
                                        title="{{ __('Resend Alert') }}"
                                        class="p-1 text-muted-foreground hover:text-primary transition-colors cursor-pointer rounded hover:bg-muted"
                                    >
                                        <x-icon name="bell" class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="calendar-x" class="w-10 h-10 mx-auto text-muted-foreground/40 mb-2" />
                                <p class="text-sm font-medium">{{ __('No attendance records for this date / filter criteria.') }}</p>
                                <p class="text-xs text-muted-foreground/80 mt-1">{{ __('Use "Daily Register" to mark single or bulk attendance.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->attendances->hasPages())
            <div class="px-4 py-3 border-t border-border">
                {{ $this->attendances->links() }}
            </div>
        @endif
    </div>

    <!-- Send Absent Alerts Confirmation Modal -->
    <x-ui.modal name="send-absent-alerts-modal" maxWidth="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-500/10 text-amber-600 flex items-center justify-center shrink-0">
                    <x-icon name="bell" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Send Absentee Notifications?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('This will notify all candidates marked absent for today (:date).', ['date' => \Illuminate\Support\Carbon::parse($selectedDate)->format('d M, Y')]) }}
                    </p>
                </div>
            </div>

            <div class="p-3 rounded-lg border border-border bg-muted/40 text-xs text-muted-foreground">
                <p class="text-foreground font-medium">{{ __('Multi-channel Delivery') }}</p>
                <p class="mt-0.5 text-[11px]">
                    {{ __('Absentee follow-up notices will be sent via SMS, WhatsApp, Email, and In-App notification as enabled in system settings.') }}
                </p>
            </div>

            <div class="pt-3 border-t border-border flex justify-end gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$dispatch('modal-close', { name: 'send-absent-alerts-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="default"
                    size="sm"
                    wire:click="sendDailyAbsentAlerts"
                    wire:loading.attr="disabled"
                    class="bg-amber-600 hover:bg-amber-700 text-white"
                >
                    <x-icon name="send" class="w-4 h-4 mr-1.5" />
                    {{ __('Confirm & Dispatch') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
