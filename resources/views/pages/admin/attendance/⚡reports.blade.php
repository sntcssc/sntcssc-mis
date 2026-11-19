<?php

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Attendance\AttendanceImportExportService;
use App\Services\Attendance\AttendanceNotificationService;
use App\Services\Attendance\AttendanceService;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Attendance Matrix & Reports')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'tab')]
    public string $activeTab = 'matrix'; // 'matrix', 'defaulters', 'import'

    #[Url(as: 'year')]
    public int $selectedYear = 2026;

    #[Url(as: 'month')]
    public int $selectedMonth = 10;

    #[Url(as: 'mode')]
    public string $targetMode = 'student'; // 'student' or 'staff'

    #[Url(as: 'course')]
    public int|string|null $selectedCourseId = null;

    #[Url(as: 'batch')]
    public int|string|null $selectedBatchId = null;

    #[Url(as: 'q')]
    public string $search = '';

    // Import modal
    public $importFile = null;
    public array $importResults = [];

    // Reminder modal
    public bool $showReminderModal = false;
    public ?int $reminderUserId = null;
    public string $reminderMessage = '';

    public function mount(): void
    {
        if (! $this->selectedYear) {
            $this->selectedYear = (int) Carbon::now()->year;
        }
        if (! $this->selectedMonth) {
            $this->selectedMonth = (int) Carbon::now()->month;
        }

        if ($this->targetMode === 'student' && ! $this->selectedCourseId) {
            $firstCourse = Course::first();
            if ($firstCourse) {
                $this->selectedCourseId = $firstCourse->id;
                $firstBatch = $firstCourse->batches()->first();
                if ($firstBatch) {
                    $this->selectedBatchId = $firstBatch->id;
                }
            }
        }
    }

    public function updatedSelectedCourseId(): void
    {
        if ($this->selectedCourseId === '') {
            $this->selectedCourseId = null;
        }
        $this->selectedBatchId = null;
    }

    public function updatedSelectedBatchId(): void
    {
        if ($this->selectedBatchId === '') {
            $this->selectedBatchId = null;
        }
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::when($this->selectedCourseId, function ($q) {
            $q->whereHas('courses', fn ($cq) => $cq->where('courses.id', $this->selectedCourseId));
        })
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function daysInMonth(): int
    {
        return Carbon::create($this->selectedYear, $this->selectedMonth, 1)->daysInMonth;
    }

    #[Computed]
    public function candidateMatrix(): array
    {
        $attendanceService = app(AttendanceService::class);
        $matrix = [];

        if ($this->targetMode === 'student') {
            $query = Enrollment::where('status', 'active')
                ->when($this->selectedBatchId, fn ($q) => $q->where('batch_id', $this->selectedBatchId))
                ->when($this->selectedCourseId && ! $this->selectedBatchId, fn ($q) => $q->where('course_id', $this->selectedCourseId))
                ->with(['user', 'course', 'batch']);

            if ($this->search) {
                $query->whereHas('user', function ($uq) {
                    $uq->where('name', 'like', "%{$this->search}%")
                        ->orWhere('student_id', 'like', "%{$this->search}%");
                });
            }

            $enrollments = $query->get();

            foreach ($enrollments as $enr) {
                $user = $enr->user;
                if (! $user) {
                    continue;
                }

                $summary = $attendanceService->getMonthlyUserAttendanceMatrix($user, $this->selectedYear, $this->selectedMonth);

                $matrix[] = [
                    'user' => $user,
                    'identifier' => $user->student_id ?? $enr->roll_no ?? $user->email,
                    'course_name' => $enr->course?->name ?? 'N/A',
                    'batch_name' => $enr->batch?->name ?? 'N/A',
                    'summary' => $summary,
                ];
            }
        } else {
            // Staff members
            $query = User::whereHas('roles', function ($q) {
                $q->whereIn('name', ['Administrator', 'Staff', 'Faculty', 'Accountant', 'Librarian']);
            });

            if ($this->search) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('employee_id', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            }

            $users = $query->orderBy('name')->get();

            foreach ($users as $user) {
                $summary = $attendanceService->getMonthlyUserAttendanceMatrix($user, $this->selectedYear, $this->selectedMonth);

                $matrix[] = [
                    'user' => $user,
                    'identifier' => $user->employee_id ?? $user->email,
                    'course_name' => $user->staff_department ?? 'Staff',
                    'batch_name' => $user->designation ?? 'N/A',
                    'summary' => $summary,
                ];
            }
        }

        return $matrix;
    }

    #[Computed]
    public function defaulters(): array
    {
        return collect($this->candidateMatrix)->filter(fn ($item) => $item['summary']['is_shortage'])->values()->all();
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        $query = Attendance::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when($this->targetMode !== 'all', fn ($q) => $q->where('user_type', $this->targetMode))
            ->when($this->selectedCourseId, fn ($q) => $q->where('course_id', (int) $this->selectedCourseId))
            ->when($this->selectedBatchId, fn ($q) => $q->where('batch_id', (int) $this->selectedBatchId));

        return AttendanceImportExportService::exportCsv($query, "attendance_register_{$this->selectedYear}_{$this->selectedMonth}.csv");
    }

    public function exportExcel(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        $query = Attendance::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when($this->targetMode !== 'all', fn ($q) => $q->where('user_type', $this->targetMode))
            ->when($this->selectedCourseId, fn ($q) => $q->where('course_id', (int) $this->selectedCourseId))
            ->when($this->selectedBatchId, fn ($q) => $q->where('batch_id', (int) $this->selectedBatchId));

        return AttendanceImportExportService::exportExcel($query, "attendance_register_{$this->selectedYear}_{$this->selectedMonth}.xlsx");
    }

    public function downloadSampleCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return AttendanceImportExportService::downloadSampleCsv();
    }

    public function exportPdf(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $startDate = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        $query = Attendance::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when($this->targetMode !== 'all', fn ($q) => $q->where('user_type', $this->targetMode))
            ->when($this->selectedCourseId, fn ($q) => $q->where('course_id', (int) $this->selectedCourseId))
            ->when($this->selectedBatchId, fn ($q) => $q->where('batch_id', (int) $this->selectedBatchId));

        return AttendanceImportExportService::exportPdf($query, [
            'title' => __('Attendance Register - :month :year', [
                'month' => $startDate->format('F'),
                'year' => $this->selectedYear,
            ]),
            'date_range' => $startDate->format('d M, Y') . ' to ' . $endDate->format('d M, Y'),
        ]);
    }

    public function processImport(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $result = AttendanceImportExportService::importAttendance($path, auth()->user());
            $this->importResults = $result;

            Toast::dispatch($this, 'success', __('Imported :count attendance records successfully (:skipped skipped).', [
                'count' => $result['imported'],
                'skipped' => $result['skipped'],
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openReminderModal(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->reminderUserId = $user->id;
        $this->reminderMessage = __('Dear :name, your attendance for this month is currently below the mandatory 75% criteria. Please ensure regular classroom attendance to remain eligible for UPSC test series.', [
            'name' => $user->name,
        ]);
        $this->dispatch('modal-open', name: 'attendance-reminder-modal');
    }

    public function sendReminder(AttendanceNotificationService $notificationService): void
    {
        if (! $this->reminderUserId) {
            return;
        }

        try {
            $user = User::findOrFail($this->reminderUserId);
            $notificationService->sendAttendanceReminder($user, 'shortage', $this->reminderMessage);

            Toast::dispatch($this, 'success', __('Attendance reminder dispatched to :name via configured channels.', ['name' => $user->name]));
            $this->dispatch('modal-close', name: 'attendance-reminder-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to dispatch reminder: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmNotifyAllDefaulters(): void
    {
        $this->dispatch('modal-open', name: 'notify-defaulters-modal');
    }

    public function notifyAllDefaulters(AttendanceNotificationService $notificationService): void
    {
        $defaultersList = $this->defaulters;
        $sentCount = 0;

        foreach ($defaultersList as $item) {
            try {
                $user = $item['user'];
                $msg = __('Dear :name, your classroom attendance (:pct%) is below mandatory 75%. Please attend upcoming classes regularly.', [
                    'name' => $user->name,
                    'pct' => $item['summary']['attendance_percentage'],
                ]);
                $notificationService->sendAttendanceReminder($user, 'shortage', $msg);
                $sentCount++;
            } catch (\Throwable) {
                // Continue with next
            }
        }

        Toast::dispatch($this, 'success', __('Sent attendance shortage notices to :count defaulters.', ['count' => $sentCount]));
        $this->dispatch('modal-close', name: 'notify-defaulters-modal');
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.attendance.index') }}" wire:navigate class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted">
                    <x-icon name="arrow-left" class="w-5 h-5" />
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Attendance Matrix & Analytics Register') }}</h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Day-by-day 31-day attendance matrix, shortage defaulter tracking (< 75%), import and export.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="outline" size="sm" wire:click="exportCsv" class="cursor-pointer">
                <x-icon name="download" class="w-4 h-4 mr-1.5" />
                {{ __('Export CSV') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" wire:click="exportExcel" class="cursor-pointer text-emerald-600 dark:text-emerald-400 border-emerald-500/30">
                <x-icon name="file-spreadsheet" class="w-4 h-4 mr-1.5" />
                {{ __('Export Excel') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" wire:click="exportPdf" class="cursor-pointer text-rose-600 dark:text-rose-400 border-rose-500/30">
                <x-icon name="file-text" class="w-4 h-4 mr-1.5" />
                {{ __('Export PDF') }}
            </x-ui.button>

            @can('attendance.import')
                <x-ui.button
                    variant="outline"
                    size="sm"
                    x-on:click="$dispatch('modal-open', { name: 'import-attendance-modal' })"
                    class="cursor-pointer"
                >
                    <x-icon name="upload" class="w-4 h-4 mr-1.5" />
                    {{ __('Import Spreadsheet') }}
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 pb-1 border-b border-border">
        <button
            type="button"
            wire:click="$set('activeTab', 'matrix')"
            class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'matrix' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
        >
            <x-icon name="calendar" class="w-4 h-4" />
            {{ __('Monthly Calendar Matrix (1-31)') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'defaulters')"
            class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'defaulters' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
        >
            <x-icon name="alert-triangle" class="w-4 h-4" />
            {{ __('Attendance Defaulters (< 75%)') }}
            @if(count($this->defaulters) > 0)
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-500 text-white">
                    {{ count($this->defaulters) }}
                </span>
            @endif
        </button>
    </div>

    <!-- Scope & Month Filter Controls -->
    <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <!-- Year -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Year') }}</label>
                <select wire:model.live="selectedYear" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg">
                    @for($y = Carbon::now()->year; $y >= Carbon::now()->year - 3; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
            </div>

            <!-- Month -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Month') }}</label>
                <select wire:model.live="selectedMonth" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg">
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}">{{ Carbon::create(2026, $m, 1)->format('F') }}</option>
                    @endfor
                </select>
            </div>

            <!-- Role / Mode -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Role Scope') }}</label>
                <select wire:model.live="targetMode" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg">
                    <option value="student">{{ __('Students') }}</option>
                    <option value="staff">{{ __('Office Staff & Faculty') }}</option>
                </select>
            </div>

            @if($targetMode === 'student')
                <!-- Course -->
                <div>
                    <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Course') }}</label>
                    <select wire:model.live="selectedCourseId" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg">
                        <option value="">{{ __('All Courses') }}</option>
                        @foreach($this->courses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Batch -->
                <div>
                    <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Batch') }}</label>
                    <select wire:model.live="selectedBatchId" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg">
                        <option value="">{{ __('All Batches') }}</option>
                        @foreach($this->batches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <!-- Search -->
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Search') }}</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Name or ID...') }}"
                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg"
                />
            </div>
        </div>
    </div>

    <!-- TAB 1: MONTHLY 31-DAY MATRIX -->
    @if($activeTab === 'matrix')
        <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="px-4 py-3 border-b border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div class="flex items-center gap-3">
                    <h2 class="text-sm font-semibold text-foreground">
                        {{ Carbon::create($selectedYear, $selectedMonth, 1)->format('F Y') }} {{ __('Attendance Matrix') }}
                    </h2>
                    <span class="text-xs text-muted-foreground font-mono">
                        ({{ count($this->candidateMatrix) }} {{ __('Candidates') }})
                    </span>
                </div>

                <!-- Color Legend -->
                <div class="flex flex-wrap items-center gap-2 text-[10px] font-medium">
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-emerald-500"></span> P = Present</span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-rose-500"></span> A = Absent</span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-amber-500"></span> L = Late</span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-purple-500"></span> HD = Half Day</span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-sky-500"></span> LEV = Leave</span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-zinc-400"></span> W = Off / Holiday</span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs divide-y divide-border border-collapse">
                    <thead class="bg-muted/60 text-muted-foreground text-[10px] font-semibold uppercase">
                        <tr>
                            <th class="px-3 py-2 sticky left-0 bg-muted z-10 min-w-[180px]">{{ __('Candidate') }}</th>
                            @for($d = 1; $d <= $this->daysInMonth; $d++)
                                <th class="px-1 py-2 text-center font-mono min-w-[28px]">
                                    {{ $d }}<br>
                                    <span class="text-[9px] font-normal text-muted-foreground/70">
                                        {{ substr(Carbon::create($selectedYear, $selectedMonth, $d)->format('D'), 0, 1) }}
                                    </span>
                                </th>
                            @endfor
                            <th class="px-2 py-2 text-center bg-muted/90">{{ __('Work Days') }}</th>
                            <th class="px-2 py-2 text-center bg-muted/90">{{ __('Present') }}</th>
                            <th class="px-2 py-2 text-center bg-muted/90">{{ __('Absent') }}</th>
                            <th class="px-2 py-2 text-center bg-muted/90">{{ __('Leave') }}</th>
                            <th class="px-3 py-2 text-center bg-muted/90 min-w-[90px]">{{ __('Rate %') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60 font-mono text-[11px]">
                        @forelse($this->candidateMatrix as $row)
                            <tr class="hover:bg-muted/30 transition-colors {{ $row['summary']['is_shortage'] ? 'bg-rose-50/20 dark:bg-rose-950/10' : '' }}">
                                <!-- Sticky Candidate Column -->
                                <td class="px-3 py-2 sticky left-0 bg-card z-10 font-sans border-r border-border">
                                    <strong class="text-foreground block truncate max-w-[170px]">{{ $row['user']->name }}</strong>
                                    <span class="text-[10px] text-muted-foreground block font-mono">{{ $row['identifier'] }}</span>
                                </td>

                                <!-- 1..31 Days -->
                                @for($d = 1; $d <= $this->daysInMonth; $d++)
                                    @php
                                        $dayData = $row['summary']['days'][$d] ?? null;
                                        $status = $dayData ? $dayData['status'] : 'not_marked';
                                    @endphp
                                    <td class="p-0.5 text-center border-r border-border/40">
                                        @if($status === 'present')
                                            <span class="inline-block w-6 h-5 leading-5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">P</span>
                                        @elseif($status === 'late')
                                            <span class="inline-block w-6 h-5 leading-5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300" title="{{ $dayData['late_minutes'] ?? 0 }}m late">L</span>
                                        @elseif($status === 'half_day')
                                            <span class="inline-block w-6 h-5 leading-5 rounded text-[10px] font-bold bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300">HD</span>
                                        @elseif($status === 'absent')
                                            <span class="inline-block w-6 h-5 leading-5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300">A</span>
                                        @elseif($status === 'on_leave')
                                            <span class="inline-block w-6 h-5 leading-5 rounded text-[9px] font-bold bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300" title="{{ $dayData['label'] ?? 'Leave' }}">LV</span>
                                        @elseif(in_array($status, ['weekly_off', 'holiday', 'govt_holiday', 'institution_holiday', 'weekly_test', 'exam_day']))
                                            <span class="inline-block w-6 h-5 leading-5 rounded text-[9px] font-medium bg-muted text-muted-foreground">
                                                {{ $status === 'weekly_test' ? 'T' : 'W' }}
                                            </span>
                                        @else
                                            <span class="inline-block w-6 h-5 leading-5 text-muted-foreground/30">-</span>
                                        @endif
                                    </td>
                                @endfor

                                <!-- Summary Totals -->
                                <td class="px-2 py-2 text-center text-foreground font-semibold bg-muted/20">
                                    {{ $row['summary']['total_working_days'] }}
                                </td>
                                <td class="px-2 py-2 text-center text-emerald-600 dark:text-emerald-400 font-bold bg-muted/20">
                                    {{ $row['summary']['present_count'] }}
                                </td>
                                <td class="px-2 py-2 text-center text-rose-600 dark:text-rose-400 font-bold bg-muted/20">
                                    {{ $row['summary']['absent_count'] }}
                                </td>
                                <td class="px-2 py-2 text-center text-sky-600 dark:text-sky-400 font-bold bg-muted/20">
                                    {{ $row['summary']['leave_count'] }}
                                </td>
                                <td class="px-3 py-2 text-center bg-muted/20">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold font-mono {{ $row['summary']['is_shortage'] ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' }}">
                                        {{ $row['summary']['attendance_percentage'] }}%
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $this->daysInMonth + 6 }}" class="px-4 py-12 text-center text-muted-foreground font-sans">
                                    <x-icon name="calendar-x" class="w-10 h-10 mx-auto text-muted-foreground/40 mb-2" />
                                    <p class="text-sm font-medium">{{ __('No candidate attendance records found for this month.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- TAB 2: DEFAULTERS REGISTER (< 75% ATTENDANCE) -->
    @if($activeTab === 'defaulters')
        <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="px-4 py-3 border-b border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-rose-600 dark:text-rose-400 flex items-center gap-1.5">
                        <x-icon name="alert-triangle" class="w-4 h-4" />
                        {{ __('Classroom Attendance Shortage Register (< 75% Criteria)') }}
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Aspirants falling below the statutory 75% attendance quota are flagged for disciplinary warnings.') }}
                    </p>
                </div>

                @if(count($this->defaulters) > 0)
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="confirmNotifyAllDefaulters"
                        class="cursor-pointer text-rose-600 dark:text-rose-400 border-rose-500/30 hover:bg-rose-500/10"
                    >
                        <x-icon name="bell" class="w-4 h-4 mr-1.5" />
                        {{ __('Notify All Defaulters (:count)', ['count' => count($this->defaulters)]) }}
                    </x-ui.button>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs divide-y divide-border">
                    <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                        <tr>
                            <th class="px-4 py-3">{{ __('Student Details') }}</th>
                            <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                            <th class="px-4 py-3">{{ __('Working Days') }}</th>
                            <th class="px-4 py-3">{{ __('Attended') }}</th>
                            <th class="px-4 py-3">{{ __('Absent') }}</th>
                            <th class="px-4 py-3">{{ __('Current %') }}</th>
                            <th class="px-4 py-3">{{ __('Shortage Severity') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @forelse($this->defaulters as $item)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3">
                                    <strong class="font-medium text-foreground block">{{ $item['user']->name }}</strong>
                                    <span class="text-[10px] text-muted-foreground font-mono">{{ $item['identifier'] }} | {{ $item['user']->email }}</span>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="font-medium text-foreground block">{{ $item['course_name'] }}</span>
                                    <span class="text-[10px] text-muted-foreground">{{ $item['batch_name'] }}</span>
                                </td>

                                <td class="px-4 py-3 font-mono font-medium">
                                    {{ $item['summary']['total_working_days'] }}
                                </td>

                                <td class="px-4 py-3 font-mono text-emerald-600 dark:text-emerald-400 font-bold">
                                    {{ $item['summary']['present_count'] }}
                                </td>

                                <td class="px-4 py-3 font-mono text-rose-600 dark:text-rose-400 font-bold">
                                    {{ $item['summary']['absent_count'] }}
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold font-mono bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                                        {{ $item['summary']['attendance_percentage'] }}%
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    @if($item['summary']['attendance_percentage'] < 50)
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-600 text-white uppercase tracking-wider">
                                            {{ __('Critical Defaulter') }}
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-500/20 text-amber-700 dark:text-amber-300">
                                            {{ __('Warning Shortage') }}
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <x-ui.button
                                        variant="outline"
                                        size="xs"
                                        wire:click="openReminderModal({{ $item['user']->id }})"
                                        class="cursor-pointer text-xs"
                                    >
                                        <x-icon name="send" class="w-3.5 h-3.5 mr-1" />
                                        {{ __('Send Warning') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                    <x-icon name="check-circle-2" class="w-10 h-10 mx-auto text-emerald-500/60 mb-2" />
                                    <p class="text-sm font-medium text-emerald-600 dark:text-emerald-400">{{ __('Outstanding! No attendance defaulters detected for this month.') }}</p>
                                    <p class="text-xs text-muted-foreground mt-0.5">{{ __('All enrolled candidates have maintained over 75% classroom presence.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Import Spreadsheet Modal -->
    <x-ui.modal name="import-attendance-modal" :title="__('Bulk Import Attendance Spreadsheet')">
        <form wire:submit="processImport" class="space-y-4">
            <div>
                <p class="text-xs text-muted-foreground mb-3">
                    {{ __('Upload a CSV or Excel file containing attendance logs. Mandatory headers: "date", "student_id" or "employee_id" or "email", and "status" (present/absent/late/half_day). Optional: "check_in_time", "check_out_time", "remarks".') }}
                </p>

                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,.xlsx,.xls"
                    class="w-full text-xs file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
                <div class="flex items-center justify-between gap-2 mt-2">
                    <button
                        type="button"
                        wire:click="downloadSampleCsv"
                        class="text-xs text-primary hover:underline inline-flex items-center gap-1 font-medium cursor-pointer"
                    >
                        <x-icon name="download" class="w-3.5 h-3.5" />
                        {{ __('Download Sample Template (.CSV)') }}
                    </button>
                    @error('importFile')
                        <span class="text-xs text-rose-600 dark:text-rose-400 block">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            @if(!empty($importResults))
                <div class="p-3 rounded-lg bg-muted text-xs space-y-1">
                    <p class="text-emerald-600 dark:text-emerald-400 font-semibold">
                        {{ __('Successfully imported :count records', ['count' => $importResults['imported'] ?? 0]) }}
                    </p>
                    @if(!empty($importResults['errors']))
                        <div class="text-rose-600 dark:text-rose-400 max-h-32 overflow-y-auto mt-2">
                            @foreach($importResults['errors'] as $err)
                                <div>• {{ $err }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'import-attendance-modal' })">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" wire:loading.attr="disabled" class="bg-primary text-primary-foreground">
                    <x-icon name="upload" class="w-4 h-4 mr-1.5" />
                    {{ __('Upload & Import') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Shortage Warning Reminder Modal -->
    <x-ui.modal name="attendance-reminder-modal" :title="__('Dispatch Attendance Shortage Notice')">
        <form wire:submit="sendReminder" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Custom Message') }} *</label>
                <textarea
                    wire:model="reminderMessage"
                    rows="4"
                    class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                ></textarea>
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('This notice will be dispatched via active channels (SMS, WhatsApp, Email, In-app) configured under Attendance Settings.') }}
                </p>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'attendance-reminder-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground">
                    <x-icon name="send" class="w-4 h-4 mr-1.5" />
                    {{ __('Dispatch Notice') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Notify All Defaulters Confirmation Modal -->
    <x-ui.modal name="notify-defaulters-modal" maxWidth="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center shrink-0">
                    <x-icon name="bell" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Notify All Defaulters?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('This will send automated attendance shortage warning notifications to all :count flagged students.', ['count' => count($this->defaulters)]) }}
                    </p>
                </div>
            </div>

            <div class="p-3 rounded-lg border border-amber-500/20 bg-amber-500/10 text-xs text-amber-800 dark:text-amber-200">
                <p class="font-medium">{{ __('Channels Used') }}</p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    {{ __('Alerts will be delivered via active student communication channels (SMS, WhatsApp, and In-App Notifications).') }}
                </p>
            </div>

            <div class="pt-3 border-t border-border flex justify-end gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$dispatch('modal-close', { name: 'notify-defaulters-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="destructive"
                    size="sm"
                    wire:click="notifyAllDefaulters"
                    wire:loading.attr="disabled"
                >
                    <x-icon name="send" class="w-4 h-4 mr-1.5" />
                    {{ __('Confirm & Send Notices') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
