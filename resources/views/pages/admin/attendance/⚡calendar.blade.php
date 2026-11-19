<?php

use App\Models\AttendanceHoliday;
use App\Models\Batch;
use App\Models\Course;
use App\Models\WorkingCalendar;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Working Days & Holiday Calendar')] class extends Component {
    public string $activeTab = 'calendars'; // 'calendars' or 'holidays'

    public int $selectedYear = 2026;

    // Working Calendar Modal State
    public bool $isEditingCalendar = false;
    public ?int $editingCalendarId = null;
    public string $calName = '';
    public string $calTargetType = 'staff';
    public int|string|null $calCourseId = null;
    public int|string|null $calBatchId = null;
    public string $calDepartment = '';
    public bool $calMon = true;
    public bool $calTue = true;
    public bool $calWed = true;
    public bool $calThu = true;
    public bool $calFri = true;
    public bool $calSat = false;
    public bool $calSun = false;
    public string $calSatRules = '2nd_4th_off';
    public string $calStartTime = '09:30:00';
    public string $calEndTime = '17:30:00';
    public int $calLateGrace = 15;
    public float $calHalfDayHours = 4.0;
    public bool $calIsDefault = false;

    // Holiday Modal State
    public bool $isEditingHoliday = false;
    public ?int $editingHolidayId = null;
    public string $holTitle = '';
    public string $holDate = '';
    public ?string $holToDate = null;
    public string $holType = 'govt_holiday';
    public string $holTargetType = 'all';
    public int|string|null $holCourseId = null;
    public int|string|null $holBatchId = null;
    public string $holDescription = '';
    public bool $holIsRestricted = false;

    // Delete Confirmation Modals
    public ?int $deleteCalendarId = null;
    public ?int $deleteHolidayId = null;

    public function mount(): void
    {
        $this->selectedYear = (int) Carbon::now()->year;
        $this->holDate = Carbon::today()->toDateString();
    }

    #[Computed]
    public function workingCalendars()
    {
        return WorkingCalendar::with(['course', 'batch'])->orderBy('target_type')->orderBy('id')->get();
    }

    #[Computed]
    public function holidays()
    {
        return AttendanceHoliday::whereYear('date', $this->selectedYear)
            ->with(['course', 'batch'])
            ->orderBy('date')
            ->get();
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::when($this->calCourseId, function ($q) {
            $q->whereHas('courses', fn ($cq) => $cq->where('courses.id', $this->calCourseId));
        })
            ->orderBy('name')
            ->get();
    }

    // Working Calendar Methods
    public function openCreateCalendarModal(): void
    {
        $this->isEditingCalendar = false;
        $this->editingCalendarId = null;
        $this->calName = '';
        $this->calTargetType = 'staff';
        $this->calCourseId = null;
        $this->calBatchId = null;
        $this->calDepartment = '';
        $this->calMon = true;
        $this->calTue = true;
        $this->calWed = true;
        $this->calThu = true;
        $this->calFri = true;
        $this->calSat = false;
        $this->calSun = false;
        $this->calSatRules = '2nd_4th_off';
        $this->calStartTime = '09:30:00';
        $this->calEndTime = '17:30:00';
        $this->calLateGrace = 15;
        $this->calHalfDayHours = 4.0;
        $this->calIsDefault = false;
        $this->dispatch('modal-open', name: 'calendar-form-modal');
    }

    public function openEditCalendarModal(int $id): void
    {
        $cal = WorkingCalendar::findOrFail($id);
        $this->isEditingCalendar = true;
        $this->editingCalendarId = $cal->id;
        $this->calName = $cal->name;
        $this->calTargetType = $cal->target_type;
        $this->calCourseId = $cal->course_id;
        $this->calBatchId = $cal->batch_id;
        $this->calDepartment = $cal->department ?? '';
        $this->calMon = (bool) $cal->monday_active;
        $this->calTue = (bool) $cal->tuesday_active;
        $this->calWed = (bool) $cal->wednesday_active;
        $this->calThu = (bool) $cal->thursday_active;
        $this->calFri = (bool) $cal->friday_active;
        $this->calSat = (bool) $cal->saturday_active;
        $this->calSun = (bool) $cal->sunday_active;
        $this->calSatRules = $cal->saturday_rules ?? '2nd_4th_off';
        $this->calStartTime = $cal->expected_start_time;
        $this->calEndTime = $cal->expected_end_time;
        $this->calLateGrace = $cal->late_grace_minutes;
        $this->calHalfDayHours = (float) $cal->half_day_hours;
        $this->calIsDefault = (bool) $cal->is_default;
        $this->dispatch('modal-open', name: 'calendar-form-modal');
    }

    public function saveCalendar(): void
    {
        $this->validate([
            'calName' => 'required|string|max:100',
            'calTargetType' => 'required|in:staff,student',
            'calStartTime' => 'required',
            'calEndTime' => 'required',
        ]);

        try {
            $data = [
                'name' => $this->calName,
                'target_type' => $this->calTargetType,
                'course_id' => $this->calCourseId,
                'batch_id' => $this->calBatchId,
                'department' => $this->calDepartment ?: null,
                'monday_active' => $this->calMon,
                'tuesday_active' => $this->calTue,
                'wednesday_active' => $this->calWed,
                'thursday_active' => $this->calThu,
                'friday_active' => $this->calFri,
                'saturday_active' => $this->calSat,
                'sunday_active' => $this->calSun,
                'saturday_rules' => $this->calSatRules,
                'expected_start_time' => $this->calStartTime,
                'expected_end_time' => $this->calEndTime,
                'late_grace_minutes' => $this->calLateGrace,
                'half_day_hours' => $this->calHalfDayHours,
                'is_default' => $this->calIsDefault,
            ];

            if ($this->calIsDefault) {
                // Remove default from other calendars with same target type
                WorkingCalendar::where('target_type', $this->calTargetType)
                    ->when($this->editingCalendarId, fn ($q) => $q->where('id', '!=', $this->editingCalendarId))
                    ->update(['is_default' => false]);
            }

            if ($this->isEditingCalendar) {
                $cal = WorkingCalendar::findOrFail($this->editingCalendarId);
                $cal->update($data);
                Toast::dispatch($this, 'success', __('Working calendar updated successfully.'));
            } else {
                WorkingCalendar::create($data);
                Toast::dispatch($this, 'success', __('Working calendar created successfully.'));
            }

            $this->dispatch('modal-close', name: 'calendar-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save schedule: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmDeleteCalendar(int $id): void
    {
        $this->deleteCalendarId = $id;
        $this->dispatch('modal-open', name: 'delete-calendar-modal');
    }

    public function deleteCalendar(): void
    {
        if (! $this->deleteCalendarId) {
            return;
        }

        try {
            $cal = WorkingCalendar::findOrFail($this->deleteCalendarId);
            $cal->delete();
            Toast::dispatch($this, 'success', __('Schedule removed successfully.'));
            $this->dispatch('modal-close', name: 'delete-calendar-modal');
            $this->deleteCalendarId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete schedule: :err', ['err' => $e->getMessage()]));
        }
    }

    // Holiday Methods
    public function openCreateHolidayModal(): void
    {
        $this->isEditingHoliday = false;
        $this->editingHolidayId = null;
        $this->holTitle = '';
        $this->holDate = Carbon::today()->toDateString();
        $this->holToDate = null;
        $this->holType = 'govt_holiday';
        $this->holTargetType = 'all';
        $this->holCourseId = null;
        $this->holBatchId = null;
        $this->holDescription = '';
        $this->holIsRestricted = false;
        $this->dispatch('modal-open', name: 'holiday-form-modal');
    }

    public function openEditHolidayModal(int $id): void
    {
        $hol = AttendanceHoliday::findOrFail($id);
        $this->isEditingHoliday = true;
        $this->editingHolidayId = $hol->id;
        $this->holTitle = $hol->title;
        $this->holDate = $hol->date ? $hol->date->toDateString() : '';
        $this->holToDate = $hol->to_date ? $hol->to_date->toDateString() : null;
        $this->holType = $hol->type;
        $this->holTargetType = $hol->target_type;
        $this->holCourseId = $hol->course_id;
        $this->holBatchId = $hol->batch_id;
        $this->holDescription = $hol->description ?? '';
        $this->holIsRestricted = (bool) $hol->is_restricted;
        $this->dispatch('modal-open', name: 'holiday-form-modal');
    }

    public function saveHoliday(): void
    {
        $this->validate([
            'holTitle' => 'required|string|max:150',
            'holDate' => 'required|date',
            'holType' => 'required|string',
            'holTargetType' => 'required|in:all,staff,student',
        ]);

        try {
            $data = [
                'title' => $this->holTitle,
                'date' => $this->holDate,
                'to_date' => $this->holToDate ?: null,
                'type' => $this->holType,
                'target_type' => $this->holTargetType,
                'course_id' => $this->holCourseId,
                'batch_id' => $this->holBatchId,
                'description' => $this->holDescription ?: null,
                'is_restricted' => $this->holIsRestricted,
            ];

            if ($this->isEditingHoliday) {
                $hol = AttendanceHoliday::findOrFail($this->editingHolidayId);
                $hol->update($data);
                Toast::dispatch($this, 'success', __('Holiday event updated successfully.'));
            } else {
                AttendanceHoliday::create($data);
                Toast::dispatch($this, 'success', __('Holiday event added successfully.'));
            }

            $this->dispatch('modal-close', name: 'holiday-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save holiday: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmDeleteHoliday(int $id): void
    {
        $this->deleteHolidayId = $id;
        $this->dispatch('modal-open', name: 'delete-holiday-modal');
    }

    public function deleteHoliday(): void
    {
        if (! $this->deleteHolidayId) {
            return;
        }

        try {
            $hol = AttendanceHoliday::findOrFail($this->deleteHolidayId);
            $hol->delete();
            Toast::dispatch($this, 'success', __('Holiday removed successfully.'));
            $this->dispatch('modal-close', name: 'delete-holiday-modal');
            $this->deleteHolidayId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete: :err', ['err' => $e->getMessage()]));
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
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Working Days, Shifts & Holiday Calendar') }}</h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Configure separate working day quotas and shift hours for office staff (e.g. 21 days) vs students (e.g. 26 class days) and manage dynamic holiday types.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if($activeTab === 'calendars')
                <x-ui.button variant="default" size="sm" wire:click="openCreateCalendarModal" class="bg-primary text-primary-foreground font-semibold">
                    <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                    {{ __('New Shift Schedule') }}
                </x-ui.button>
            @else
                <x-ui.button variant="default" size="sm" wire:click="openCreateHolidayModal" class="bg-primary text-primary-foreground font-semibold">
                    <x-icon name="calendar-plus" class="w-4 h-4 mr-1.5" />
                    {{ __('Add Holiday / Test Day') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 pb-1 border-b border-border">
        <button
            type="button"
            wire:click="$set('activeTab', 'calendars')"
            class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'calendars' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
        >
            <x-icon name="clock" class="w-4 h-4" />
            {{ __('Working Days & Shifts (Staff vs Students)') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'holidays')"
            class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'holidays' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
        >
            <x-icon name="calendar-days" class="w-4 h-4" />
            {{ __('Holidays, Weekly Offs & Test Days') }}
        </button>
    </div>

    <!-- TAB 1: WORKING CALENDARS & SHIFTS -->
    @if($activeTab === 'calendars')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($this->workingCalendars as $cal)
                <div class="p-5 rounded-xl border border-border bg-card shadow-xs flex flex-col justify-between">
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider {{ $cal->target_type === 'staff' ? 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' }}">
                                        {{ ucfirst($cal->target_type) }} {{ __('Schedule') }}
                                    </span>
                                    @if($cal->is_default)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                                            {{ __('Default') }}
                                        </span>
                                    @endif
                                </div>
                                <h3 class="font-bold text-foreground text-base">{{ $cal->name }}</h3>
                                @if($cal->course)
                                    <span class="text-xs text-muted-foreground block">Course: {{ $cal->course->name }}</span>
                                @endif
                                @if($cal->batch)
                                    <span class="text-xs text-muted-foreground block">Batch: {{ $cal->batch->name }}</span>
                                @endif
                            </div>

                            <span class="p-2.5 rounded-xl bg-muted text-foreground">
                                <x-icon name="{{ $cal->target_type === 'staff' ? 'briefcase' : 'graduation-cap' }}" class="w-5 h-5" />
                            </span>
                        </div>

                        <!-- Days of Week Chips -->
                        <div class="mt-4 pt-3 border-t border-border/60">
                            <span class="text-[11px] font-medium text-muted-foreground block mb-2">{{ __('Active Working Days') }}</span>
                            <div class="flex flex-wrap gap-1.5 font-mono text-xs">
                                @foreach(['Mon' => $cal->monday_active, 'Tue' => $cal->tuesday_active, 'Wed' => $cal->wednesday_active, 'Thu' => $cal->thursday_active, 'Fri' => $cal->friday_active, 'Sat' => $cal->saturday_active, 'Sun' => $cal->sunday_active] as $dayName => $isActive)
                                    <span class="px-2 py-1 rounded-md text-[11px] font-bold {{ $isActive ? 'bg-primary/10 text-primary border border-primary/20' : 'bg-muted/40 text-muted-foreground/40 line-through' }}">
                                        {{ $dayName }}
                                    </span>
                                @endforeach
                            </div>

                            @if($cal->saturday_active)
                                <div class="mt-2 text-[11px] text-muted-foreground">
                                    Saturday Policy: <strong class="text-foreground font-sans">{{ match($cal->saturday_rules) { '2nd_4th_off' => __('2nd & 4th Saturday Off'), 'all_off' => __('All Saturdays Off'), default => __('Full Working Day') } }}</strong>
                                </div>
                            @endif
                        </div>

                        <!-- Shift Timings -->
                        <div class="mt-4 grid grid-cols-3 gap-2 p-2.5 rounded-lg bg-muted/40 border border-border/40 text-xs font-mono">
                            <div>
                                <span class="text-[10px] text-muted-foreground block font-sans">{{ __('Working Hours') }}</span>
                                <strong class="text-foreground">{{ substr($cal->expected_start_time, 0, 5) }} - {{ substr($cal->expected_end_time, 0, 5) }}</strong>
                            </div>
                            <div>
                                <span class="text-[10px] text-muted-foreground block font-sans">{{ __('Grace Window') }}</span>
                                <strong class="text-foreground">{{ $cal->late_grace_minutes }} mins</strong>
                            </div>
                            <div>
                                <span class="text-[10px] text-muted-foreground block font-sans">{{ __('Half-Day Req.') }}</span>
                                <strong class="text-foreground">{{ $cal->half_day_hours }} hrs</strong>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-border flex items-center justify-end gap-1">
                        <x-ui.button variant="ghost" size="xs" wire:click="openEditCalendarModal({{ $cal->id }})" class="cursor-pointer text-xs">
                            <x-icon name="edit" class="w-3.5 h-3.5 mr-1" />
                            {{ __('Edit Schedule') }}
                        </x-ui.button>
                        <x-ui.button
                            variant="ghost"
                            size="xs"
                            wire:click="confirmDeleteCalendar({{ $cal->id }})"
                            class="cursor-pointer text-xs text-rose-600 hover:bg-rose-500/10"
                        >
                            <x-icon name="trash-2" class="w-3.5 h-3.5 mr-1" />
                            {{ __('Delete') }}
                        </x-ui.button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- TAB 2: DYNAMIC HOLIDAYS, WEEKLY OFFS & TEST DAYS -->
    @if($activeTab === 'holidays')
        <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="px-4 py-3 border-b border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <h2 class="text-sm font-semibold text-foreground">
                        {{ __('Calendar Holidays & Special Academic Days (:year)', ['year' => $selectedYear]) }}
                    </h2>
                    <span class="text-xs text-muted-foreground font-mono">({{ count($this->holidays) }} events)</span>
                </div>

                <div class="flex items-center gap-2">
                    <select wire:model.live="selectedYear" class="px-3 py-1 text-xs bg-background border border-border rounded-lg font-mono">
                        @for($y = Carbon::now()->year + 1; $y >= Carbon::now()->year - 2; $y--)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs divide-y divide-border">
                    <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                        <tr>
                            <th class="px-4 py-3">{{ __('Date / Duration') }}</th>
                            <th class="px-4 py-3">{{ __('Event Title & Scope') }}</th>
                            <th class="px-4 py-3">{{ __('Holiday Type') }}</th>
                            <th class="px-4 py-3">{{ __('Applicable To') }}</th>
                            <th class="px-4 py-3">{{ __('Description / Notes') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @forelse($this->holidays as $hol)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3 font-mono">
                                    <strong class="text-foreground block">{{ $hol->date ? $hol->date->format('d M, Y') : 'N/A' }}</strong>
                                    @if($hol->to_date)
                                        <span class="text-[10px] text-muted-foreground block">
                                            to {{ $hol->to_date->format('d M, Y') }}
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    <span class="font-bold text-foreground block text-sm">{{ $hol->title }}</span>
                                    @if($hol->course)
                                        <span class="text-[10px] text-muted-foreground">Course: {{ $hol->course->name }}</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $hol->typeBadgeColor() }}">
                                        {{ $hol->typeLabel() }}
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider {{ match($hol->target_type) { 'staff' => 'bg-indigo-500/10 text-indigo-600', 'student' => 'bg-emerald-500/10 text-emerald-600', default => 'bg-blue-500/10 text-blue-600' } }}">
                                        {{ ucfirst($hol->target_type) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-muted-foreground max-w-xs truncate">
                                    {{ $hol->description ?: '-' }}
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            type="button"
                                            wire:click="openEditHolidayModal({{ $hol->id }})"
                                            class="p-1 text-muted-foreground hover:text-primary transition-colors cursor-pointer rounded hover:bg-muted"
                                        >
                                            <x-icon name="edit" class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="confirmDeleteHoliday({{ $hol->id }})"
                                            class="p-1 text-muted-foreground hover:text-rose-600 transition-colors cursor-pointer rounded hover:bg-muted"
                                        >
                                            <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                                    {{ __('No holidays or special calendar events recorded for :year.', ['year' => $selectedYear]) }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Calendar Schedule Modal -->
    <x-ui.modal name="calendar-form-modal" :title="$isEditingCalendar ? __('Edit Working Calendar & Shift') : __('New Working Calendar & Shift')">
        <form wire:submit="saveCalendar" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Schedule Title') }} *</label>
                <input type="text" wire:model="calName" placeholder="{{ __('e.g. Office Staff General Schedule') }}" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg" />
                @error('calName') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Target User Role') }} *</label>
                    <select wire:model.live="calTargetType" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                        <option value="staff">{{ __('Office Staff & Faculty') }}</option>
                        <option value="student">{{ __('Students / Aspirants') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Saturday Rule') }}</label>
                    <select wire:model="calSatRules" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                        <option value="2nd_4th_off">{{ __('2nd & 4th Saturday Off (Office standard)') }}</option>
                        <option value="all_off">{{ __('All Saturdays Off') }}</option>
                        <option value="all_working">{{ __('All Saturdays Working (Full Day)') }}</option>
                    </select>
                </div>
            </div>

            <!-- Active Days Toggles -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-2">{{ __('Active Days in Week') }}</label>
                <div class="grid grid-cols-4 sm:grid-cols-7 gap-2 text-xs">
                    <label class="flex items-center gap-1.5 cursor-pointer p-2 rounded-lg border border-border bg-muted/20">
                        <input type="checkbox" wire:model="calMon" class="rounded text-primary focus:ring-primary" />
                        <span>Mon</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer p-2 rounded-lg border border-border bg-muted/20">
                        <input type="checkbox" wire:model="calTue" class="rounded text-primary focus:ring-primary" />
                        <span>Tue</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer p-2 rounded-lg border border-border bg-muted/20">
                        <input type="checkbox" wire:model="calWed" class="rounded text-primary focus:ring-primary" />
                        <span>Wed</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer p-2 rounded-lg border border-border bg-muted/20">
                        <input type="checkbox" wire:model="calThu" class="rounded text-primary focus:ring-primary" />
                        <span>Thu</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer p-2 rounded-lg border border-border bg-muted/20">
                        <input type="checkbox" wire:model="calFri" class="rounded text-primary focus:ring-primary" />
                        <span>Fri</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer p-2 rounded-lg border border-border bg-muted/20">
                        <input type="checkbox" wire:model="calSat" class="rounded text-primary focus:ring-primary" />
                        <span>Sat</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer p-2 rounded-lg border border-border bg-muted/20">
                        <input type="checkbox" wire:model="calSun" class="rounded text-primary focus:ring-primary" />
                        <span>Sun</span>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Shift Start Time') }} *</label>
                    <input type="time" wire:model="calStartTime" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Shift End Time') }} *</label>
                    <input type="time" wire:model="calEndTime" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Late Grace Window (Minutes)') }}</label>
                    <input type="number" wire:model="calLateGrace" min="0" max="120" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Half-Day Min Hours') }}</label>
                    <input type="number" step="0.5" wire:model="calHalfDayHours" min="1" max="10" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-foreground">
                    <input type="checkbox" wire:model="calIsDefault" class="rounded border-border text-primary focus:ring-primary" />
                    <span>{{ __('Set as primary default calendar for this target role') }}</span>
                </label>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'calendar-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground font-semibold">
                    {{ __('Save Schedule') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Holiday Form Modal -->
    <x-ui.modal name="holiday-form-modal" :title="$isEditingHoliday ? __('Edit Holiday / Test Day') : __('Add Holiday / Special Day')">
        <form wire:submit="saveHoliday" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Event Title') }} *</label>
                <input type="text" wire:model="holTitle" placeholder="{{ __('e.g. Durga Puja Holiday or Weekly UPSC Test Day') }}" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg" />
                @error('holTitle') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Start Date') }} *</label>
                    <input type="date" wire:model="holDate" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                    @error('holDate') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('End Date (Optional)') }}</label>
                    <input type="date" wire:model="holToDate" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Holiday Category Type') }} *</label>
                    <select wire:model="holType" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                        <option value="govt_holiday">{{ __('Govt. Holiday (National / Gazetted)') }}</option>
                        <option value="institution_holiday">{{ __('Institutional Holiday') }}</option>
                        <option value="weekly_off">{{ __('Weekly Off') }}</option>
                        <option value="weekly_test">{{ __('Weekly Test Day (Students)') }}</option>
                        <option value="exam_day">{{ __('Examination Day') }}</option>
                        <option value="restricted_holiday">{{ __('Restricted Holiday') }}</option>
                        <option value="emergency_off">{{ __('Emergency / Disaster Off') }}</option>
                        <option value="vacation">{{ __('Vacation / Semester Break') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Applicability') }} *</label>
                    <select wire:model="holTargetType" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                        <option value="all">{{ __('All Users (Staff + Students)') }}</option>
                        <option value="student">{{ __('Students Only') }}</option>
                        <option value="staff">{{ __('Office Staff & Faculty Only') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description / Official Order Reference') }}</label>
                <textarea wire:model="holDescription" rows="2" placeholder="{{ __('e.g. As per WB Govt. Finance Department Holiday List 2026') }}" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg"></textarea>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'holiday-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground font-semibold">
                    {{ __('Save Calendar Event') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Delete Calendar Modal -->
    <x-ui.modal name="delete-calendar-modal" maxWidth="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center shrink-0">
                    <x-icon name="trash-2" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Delete Schedule Definition?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Are you sure you want to delete this working calendar? Users will fall back to default working days.') }}
                    </p>
                </div>
            </div>

            <div class="pt-3 border-t border-border flex justify-end gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$dispatch('modal-close', { name: 'delete-calendar-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="destructive"
                    size="sm"
                    wire:click="deleteCalendar"
                >
                    {{ __('Delete Schedule') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Delete Holiday Modal -->
    <x-ui.modal name="delete-holiday-modal" maxWidth="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center shrink-0">
                    <x-icon name="trash-2" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Remove Calendar Event?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Are you sure you want to remove this holiday or special test day from the calendar?') }}
                    </p>
                </div>
            </div>

            <div class="pt-3 border-t border-border flex justify-end gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$dispatch('modal-close', { name: 'delete-holiday-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="destructive"
                    size="sm"
                    wire:click="deleteHoliday"
                >
                    {{ __('Remove Event') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
