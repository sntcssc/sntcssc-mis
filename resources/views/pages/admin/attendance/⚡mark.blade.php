<?php

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LeaveApplication;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Daily Attendance Register')] class extends Component {
    #[Url(as: 'mode')]
    public string $mode = 'student'; // 'student' or 'staff'

    #[Url(as: 'date')]
    public string $selectedDate = '';

    public int|string|null $selectedCourseId = null;
    public int|string|null $selectedBatchId = null;
    public int|string|null $selectedSectionId = null;
    public string $selectedDepartment = '';

    public bool $sendNotificationOnSave = true;

    // Roster grid data: user_id => [status, check_in_time, check_out_time, remarks, is_locked_leave]
    public array $roster = [];

    // Single modal state
    public bool $showSingleModal = false;
    public ?int $singleUserId = null;
    public string $singleStatus = 'present';
    public ?string $singleCheckIn = null;
    public ?string $singleCheckOut = null;
    public ?string $singleRemarks = null;

    public function mount(): void
    {
        if (empty($this->selectedDate)) {
            $this->selectedDate = Carbon::today()->toDateString();
        }

        $this->sendNotificationOnSave = (bool) Setting::get('attendance.notify_student_present', true);

        // Preselect first course and batch if student mode
        if ($this->mode === 'student' && ! $this->selectedCourseId) {
            $firstCourse = Course::first();
            if ($firstCourse) {
                $this->selectedCourseId = $firstCourse->id;
                $firstBatch = $firstCourse->batches()->first();
                if ($firstBatch) {
                    $this->selectedBatchId = $firstBatch->id;
                }
            }
        }

        $this->loadRoster();
    }

    public function updatedMode(): void
    {
        $this->loadRoster();
    }

    public function updatedSelectedDate(): void
    {
        $this->loadRoster();
    }

    public function updatedSelectedCourseId(): void
    {
        if ($this->selectedCourseId === '') {
            $this->selectedCourseId = null;
        }
        $this->selectedBatchId = null;
        $this->selectedSectionId = null;
        $course = $this->selectedCourseId ? Course::find((int) $this->selectedCourseId) : null;
        $firstBatch = $course?->batches()->first();
        if ($firstBatch) {
            $this->selectedBatchId = $firstBatch->id;
        }
        $this->loadRoster();
    }

    public function updatedSelectedBatchId(): void
    {
        if ($this->selectedBatchId === '') {
            $this->selectedBatchId = null;
        }
        $this->selectedSectionId = null;
        $this->loadRoster();
    }

    public function updatedSelectedSectionId(): void
    {
        if ($this->selectedSectionId === '') {
            $this->selectedSectionId = null;
        }
        $this->loadRoster();
    }

    public function updatedSelectedDepartment(): void
    {
        $this->loadRoster();
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
    public function sections()
    {
        return Section::when($this->selectedBatchId, fn ($q) => $q->where('batch_id', $this->selectedBatchId))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function departments(): array
    {
        return [
            'Administration',
            'Academic Faculty',
            'Library Administration',
            'Accounts & Finance',
            'IT & Systems',
            'Student Affairs',
        ];
    }

    public function loadRoster(): void
    {
        $this->roster = [];
        $date = $this->selectedDate ?: Carbon::today()->toDateString();

        if ($this->mode === 'student') {
            if (! $this->selectedBatchId && ! $this->selectedCourseId) {
                return;
            }

            $enrollmentsQuery = Enrollment::where('status', 'active')
                ->when($this->selectedBatchId, fn ($q) => $q->where('batch_id', (int) $this->selectedBatchId))
                ->when($this->selectedCourseId && ! $this->selectedBatchId, fn ($q) => $q->where('course_id', (int) $this->selectedCourseId))
                ->when($this->selectedSectionId, fn ($q) => $q->where('section_id', (int) $this->selectedSectionId))
                ->with(['user']);

            $users = $enrollmentsQuery->get()->pluck('user')->filter()->unique('id');
        } else {
            // Staff members
            $users = User::whereHas('roles', function ($q) {
                $q->whereIn('name', ['Administrator', 'Staff', 'Faculty', 'Accountant', 'Librarian']);
            })
            ->when($this->selectedDepartment, fn ($q) => $q->where('staff_department', $this->selectedDepartment))
            ->orderBy('name')
            ->get();
        }

        // Fetch existing attendance records for this date
        $existing = Attendance::where('date', $date)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // Fetch approved leaves on this date
        $approvedLeaves = LeaveApplication::where('status', LeaveApplication::STATUS_APPROVED)
            ->where('from_date', '<=', $date)
            ->where('to_date', '>=', $date)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        foreach ($users as $user) {
            $att = $existing->get($user->id);
            $leave = $approvedLeaves->get($user->id);

            $status = 'present';
            $checkIn = null;
            $checkOut = null;
            $remarks = null;
            $isLockedLeave = false;

            if ($leave) {
                $status = 'on_leave';
                $remarks = "Approved Leave: {$leave->category?->name}";
                $isLockedLeave = true;
            } elseif ($att) {
                $status = $att->status;
                $checkIn = $att->check_in_time;
                $checkOut = $att->check_out_time;
                $remarks = $att->remarks;
            }

            $this->roster[$user->id] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'identifier' => $user->student_id ?? $user->employee_id ?? $user->email,
                'status' => $status,
                'check_in_time' => $checkIn,
                'check_out_time' => $checkOut,
                'remarks' => $remarks,
                'is_locked_leave' => $isLockedLeave,
            ];
        }
    }

    public function markAllPresent(): void
    {
        foreach ($this->roster as $userId => $item) {
            if (! $item['is_locked_leave']) {
                $this->roster[$userId]['status'] = 'present';
            }
        }
    }

    public function markAllAbsent(): void
    {
        foreach ($this->roster as $userId => $item) {
            if (! $item['is_locked_leave']) {
                $this->roster[$userId]['status'] = 'absent';
            }
        }
    }

    public function setStatus(int $userId, string $status): void
    {
        if (isset($this->roster[$userId]) && ! $this->roster[$userId]['is_locked_leave']) {
            $this->roster[$userId]['status'] = $status;
        }
    }

    public function saveRegister(AttendanceService $attendanceService): void
    {
        if (empty($this->roster)) {
            Toast::dispatch($this, 'warning', __('No users in the roster to save.'));
            return;
        }

        try {
            $records = [];
            foreach ($this->roster as $userId => $item) {
                $records[] = [
                    'user_id' => $userId,
                    'status' => $item['status'],
                    'check_in_time' => $item['check_in_time'],
                    'check_out_time' => $item['check_out_time'],
                    'remarks' => $item['remarks'],
                ];
            }

            $result = $attendanceService->markBulk([
                'date' => $this->selectedDate,
                'records' => $records,
                'send_notification' => $this->sendNotificationOnSave,
            ], auth()->user());

            Toast::dispatch($this, 'success', __('Attendance register saved successfully (:count marked, :skipped skipped).', [
                'count' => $result['saved'],
                'skipped' => $result['skipped'],
            ]));

            $this->loadRoster();
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save attendance register: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openSingleModal(int $userId): void
    {
        $this->singleUserId = $userId;
        if (isset($this->roster[$userId])) {
            $this->singleStatus = $this->roster[$userId]['status'];
            $this->singleCheckIn = $this->roster[$userId]['check_in_time'];
            $this->singleCheckOut = $this->roster[$userId]['check_out_time'];
            $this->singleRemarks = $this->roster[$userId]['remarks'];
        }
        $this->showSingleModal = true;
        $this->dispatch('modal-open', name: 'single-mark-modal');
    }

    public function saveSingleModal(AttendanceService $attendanceService): void
    {
        if (! $this->singleUserId) {
            return;
        }

        try {
            $attendanceService->markSingle([
                'user_id' => $this->singleUserId,
                'date' => $this->selectedDate,
                'status' => $this->singleStatus,
                'check_in_time' => $this->singleCheckIn,
                'check_out_time' => $this->singleCheckOut,
                'remarks' => $this->singleRemarks,
                'send_notification' => $this->sendNotificationOnSave,
            ], auth()->user());

            Toast::dispatch($this, 'success', __('Attendance updated successfully.'));
            $this->dispatch('modal-close', name: 'single-mark-modal');
            $this->loadRoster();
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update: :err', ['err' => $e->getMessage()]));
        }
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
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Daily Attendance Register') }}</h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Take attendance for batch classrooms or staff departments with 1-click bulk marking.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button
                variant="outline"
                size="sm"
                wire:click="markAllPresent"
                class="cursor-pointer text-emerald-600 dark:text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/10"
            >
                <x-icon name="check" class="w-4 h-4 mr-1.5" />
                {{ __('Mark All Present') }}
            </x-ui.button>

            <x-ui.button
                variant="outline"
                size="sm"
                wire:click="markAllAbsent"
                class="cursor-pointer text-rose-600 dark:text-rose-400 border-rose-500/30 hover:bg-rose-500/10"
            >
                <x-icon name="x" class="w-4 h-4 mr-1.5" />
                {{ __('Mark All Absent') }}
            </x-ui.button>

            <x-ui.button
                variant="default"
                size="sm"
                wire:click="saveRegister"
                wire:loading.attr="disabled"
                class="cursor-pointer bg-primary text-primary-foreground font-semibold shadow-xs"
            >
                <x-icon name="save" class="w-4 h-4 mr-1.5" wire:loading.class="hidden" wire:target="saveRegister" />
                <x-icon name="refresh-cw" class="w-4 h-4 mr-1.5 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="saveRegister" />
                {{ __('Save Attendance') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Mode Selector & Scope Selector Toolbar -->
    <div class="p-4 rounded-xl border border-border bg-card shadow-xs space-y-4">
        <!-- Mode Tabs -->
        <div class="flex items-center gap-2 pb-3 border-b border-border">
            <button
                type="button"
                wire:click="$set('mode', 'student')"
                class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $mode === 'student' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
            >
                <x-icon name="graduation-cap" class="w-4 h-4" />
                {{ __('Student Batch Attendance') }}
            </button>

            <button
                type="button"
                wire:click="$set('mode', 'staff')"
                class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $mode === 'staff' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
            >
                <x-icon name="briefcase" class="w-4 h-4" />
                {{ __('Office Staff & Faculty Attendance') }}
            </button>
        </div>

        <!-- Scope Controls -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Date') }} *</label>
                <input
                    type="date"
                    wire:model.live="selectedDate"
                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                />
            </div>

            @if($mode === 'student')
                <div>
                    <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Course') }} *</label>
                    <select
                        wire:model.live="selectedCourseId"
                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                    >
                        <option value="">{{ __('Select Course') }}</option>
                        @foreach($this->courses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Batch') }} *</label>
                    <select
                        wire:model.live="selectedBatchId"
                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                    >
                        <option value="">{{ __('Select Batch') }}</option>
                        @foreach($this->batches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Section') }} ({{ __('Optional') }})</label>
                    <select
                        wire:model.live="selectedSectionId"
                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                    >
                        <option value="">{{ __('All Sections') }}</option>
                        @foreach($this->sections as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <div>
                    <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Department') }}</label>
                    <select
                        wire:model.live="selectedDepartment"
                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                    >
                        <option value="">{{ __('All Staff Departments') }}</option>
                        @foreach($this->departments as $dept)
                            <option value="{{ $dept }}">{{ $dept }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="flex items-center pt-5">
                <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-foreground">
                    <input
                        type="checkbox"
                        wire:model="sendNotificationOnSave"
                        class="rounded border-border text-primary focus:ring-primary"
                    />
                    <span>{{ __('Notify students via SMS / App') }}</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Attendance Roster Grid -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
            <h2 class="text-sm font-semibold text-foreground flex items-center gap-2">
                <span>{{ __('Attendance Register Roster') }}</span>
                <span class="px-2 py-0.5 rounded-full text-xs font-mono bg-muted text-muted-foreground">
                    {{ count($roster) }} {{ __('Candidates') }}
                </span>
            </h2>

            <div class="flex items-center gap-4 text-xs font-medium">
                <span class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    {{ __('Present') }}: {{ count(array_filter($roster, fn ($i) => $i['status'] === 'present')) }}
                </span>
                <span class="flex items-center gap-1.5 text-rose-600 dark:text-rose-400">
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                    {{ __('Absent') }}: {{ count(array_filter($roster, fn ($i) => $i['status'] === 'absent')) }}
                </span>
                <span class="flex items-center gap-1.5 text-sky-600 dark:text-sky-400">
                    <span class="w-2 h-2 rounded-full bg-sky-500"></span>
                    {{ __('On Leave') }}: {{ count(array_filter($roster, fn ($i) => $i['status'] === 'on_leave')) }}
                </span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs divide-y divide-border">
                <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3 w-10">#</th>
                        <th class="px-4 py-3">{{ __('Name & ID') }}</th>
                        <th class="px-4 py-3 w-72">{{ __('Status') }}</th>
                        <th class="px-4 py-3 w-36">{{ __('Check-In Time') }}</th>
                        <th class="px-4 py-3 w-36">{{ __('Check-Out Time') }}</th>
                        <th class="px-4 py-3">{{ __('Remarks') }}</th>
                        <th class="px-4 py-3 text-right w-16">{{ __('Edit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($roster as $userId => $item)
                        <tr class="hover:bg-muted/30 transition-colors {{ $item['is_locked_leave'] ? 'bg-sky-50/40 dark:bg-sky-950/20' : '' }}">
                            <td class="px-4 py-3 text-muted-foreground font-mono">
                                {{ $loop->iteration }}
                            </td>

                            <td class="px-4 py-3">
                                <span class="font-medium text-foreground block">{{ $item['name'] }}</span>
                                <span class="text-[10px] text-muted-foreground font-mono">{{ $item['identifier'] }}</span>
                            </td>

                            <td class="px-4 py-3">
                                @if($item['is_locked_leave'])
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20">
                                        <x-icon name="calendar-check" class="w-3.5 h-3.5" />
                                        {{ __('On Approved Leave') }}
                                    </span>
                                @else
                                    <div class="flex items-center gap-1">
                                        <!-- Present Button -->
                                        <button
                                            type="button"
                                            wire:click="setStatus({{ $userId }}, 'present')"
                                            class="px-2.5 py-1 rounded font-semibold text-xs transition-colors cursor-pointer {{ $item['status'] === 'present' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-muted/70 text-muted-foreground hover:bg-emerald-500/10 hover:text-emerald-600' }}"
                                        >
                                            P
                                        </button>

                                        <!-- Absent Button -->
                                        <button
                                            type="button"
                                            wire:click="setStatus({{ $userId }}, 'absent')"
                                            class="px-2.5 py-1 rounded font-semibold text-xs transition-colors cursor-pointer {{ $item['status'] === 'absent' ? 'bg-rose-600 text-white shadow-xs' : 'bg-muted/70 text-muted-foreground hover:bg-rose-500/10 hover:text-rose-600' }}"
                                        >
                                            A
                                        </button>

                                        <!-- Late Button -->
                                        <button
                                            type="button"
                                            wire:click="setStatus({{ $userId }}, 'late')"
                                            class="px-2.5 py-1 rounded font-semibold text-xs transition-colors cursor-pointer {{ $item['status'] === 'late' ? 'bg-amber-600 text-white shadow-xs' : 'bg-muted/70 text-muted-foreground hover:bg-amber-500/10 hover:text-amber-600' }}"
                                        >
                                            L
                                        </button>

                                        <!-- Half Day Button -->
                                        <button
                                            type="button"
                                            wire:click="setStatus({{ $userId }}, 'half_day')"
                                            class="px-2.5 py-1 rounded font-semibold text-xs transition-colors cursor-pointer {{ $item['status'] === 'half_day' ? 'bg-purple-600 text-white shadow-xs' : 'bg-muted/70 text-muted-foreground hover:bg-purple-500/10 hover:text-purple-600' }}"
                                        >
                                            HD
                                        </button>
                                    </div>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <input
                                    type="time"
                                    wire:model="roster.{{ $userId }}.check_in_time"
                                    class="w-full px-2 py-1 text-xs font-mono bg-background border border-border rounded"
                                    {{ $item['is_locked_leave'] ? 'disabled' : '' }}
                                />
                            </td>

                            <td class="px-4 py-3">
                                <input
                                    type="time"
                                    wire:model="roster.{{ $userId }}.check_out_time"
                                    class="w-full px-2 py-1 text-xs font-mono bg-background border border-border rounded"
                                    {{ $item['is_locked_leave'] ? 'disabled' : '' }}
                                />
                            </td>

                            <td class="px-4 py-3">
                                <input
                                    type="text"
                                    wire:model="roster.{{ $userId }}.remarks"
                                    placeholder="{{ __('Notes...') }}"
                                    class="w-full px-2.5 py-1 text-xs bg-background border border-border rounded"
                                    {{ $item['is_locked_leave'] ? 'disabled' : '' }}
                                />
                            </td>

                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    wire:click="openSingleModal({{ $userId }})"
                                    class="p-1 text-muted-foreground hover:text-primary transition-colors cursor-pointer rounded hover:bg-muted"
                                >
                                    <x-icon name="sliders" class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="users" class="w-10 h-10 mx-auto text-muted-foreground/40 mb-2" />
                                <p class="text-sm font-medium">{{ __('No candidates found in this scope.') }}</p>
                                <p class="text-xs text-muted-foreground/80 mt-1">{{ __('Please select a course and batch or adjust filters.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(!empty($roster))
            <div class="px-4 py-3 border-t border-border flex justify-end">
                <x-ui.button
                    variant="default"
                    size="sm"
                    wire:click="saveRegister"
                    wire:loading.attr="disabled"
                    class="cursor-pointer bg-primary text-primary-foreground font-semibold"
                >
                    <x-icon name="save" class="w-4 h-4 mr-1.5" />
                    {{ __('Save Attendance Register') }}
                </x-ui.button>
            </div>
        @endif
    </div>

    <!-- Single Edit Modal -->
    <x-ui.modal name="single-mark-modal" :title="__('Detailed Attendance Entry')">
        <form wire:submit="saveSingleModal" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Status') }} *</label>
                <select wire:model="singleStatus" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                    <option value="present">{{ __('Present') }}</option>
                    <option value="absent">{{ __('Absent') }}</option>
                    <option value="late">{{ __('Late') }}</option>
                    <option value="half_day">{{ __('Half Day') }}</option>
                    <option value="on_leave">{{ __('On Leave') }}</option>
                    <option value="holiday">{{ __('Holiday') }}</option>
                    <option value="weekly_off">{{ __('Weekly Off') }}</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Punch In Time') }}</label>
                    <input type="time" wire:model="singleCheckIn" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Punch Out Time') }}</label>
                    <input type="time" wire:model="singleCheckOut" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Remarks / Reason') }}</label>
                <textarea wire:model="singleRemarks" rows="2" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg"></textarea>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'single-mark-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground font-semibold">
                    {{ __('Save Changes') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
