<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceHoliday;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BiometricLog;
use App\Models\Course;
use App\Models\LeaveApplication;
use App\Models\User;
use App\Models\WorkingCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        protected AttendanceNotificationService $notificationService
    ) {}

    /**
     * Mark single attendance entry.
     *
     * @param  array{
     *     user_id: int,
     *     date: string,
     *     status: string,
     *     check_in_time?: string|null,
     *     check_out_time?: string|null,
     *     remarks?: string|null,
     *     send_notification?: bool
     * }  $data
     */
    public function markSingle(array $data, ?User $marker = null): Attendance
    {
        $user = User::findOrFail($data['user_id']);
        $date = Carbon::parse($data['date'])->toDateString();
        $userType = $user->isStudent() ? 'student' : 'staff';

        // Check if there's an approved leave for this date
        $approvedLeave = LeaveApplication::where('user_id', $user->id)
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->where('from_date', '<=', $date)
            ->where('to_date', '>=', $date)
            ->first();

        $status = $data['status'];
        $leaveId = null;

        if ($approvedLeave) {
            $status = Attendance::STATUS_ON_LEAVE;
            $leaveId = $approvedLeave->id;
        }

        // Student batch and course resolution
        $courseId = null;
        $batchId = null;
        $sectionId = null;

        if ($userType === 'student') {
            $enrollment = $user->activeEnrollments()->first();
            if ($enrollment) {
                $courseId = $enrollment->course_id;
                $batchId = $enrollment->batch_id;
                $sectionId = $enrollment->section_id;
            }
        }

        // Calculate hours and late minutes
        $checkIn = ! empty($data['check_in_time']) ? $data['check_in_time'] : null;
        $checkOut = ! empty($data['check_out_time']) ? $data['check_out_time'] : null;
        $workingHours = 0.00;
        $lateMinutes = 0;

        if ($checkIn && $checkOut) {
            $in = Carbon::parse("{$date} {$checkIn}");
            $out = Carbon::parse("{$date} {$checkOut}");
            if ($out->gte($in)) {
                $workingHours = round($in->diffInMinutes($out) / 60, 2);
            }
        }

        // Calculate late minutes against working calendar schedule
        $calendar = $this->resolveCalendarForUser($user, $userType, $courseId, $batchId);
        if ($calendar && $checkIn) {
            $expected = Carbon::parse("{$date} {$calendar->expected_start_time}")->addMinutes($calendar->late_grace_minutes);
            $actual = Carbon::parse("{$date} {$checkIn}");
            if ($actual->gt($expected)) {
                $lateMinutes = $expected->diffInMinutes($actual);
                if ($status === Attendance::STATUS_PRESENT) {
                    $status = Attendance::STATUS_LATE;
                }
            }
        }

        return DB::transaction(function () use ($user, $date, $userType, $courseId, $batchId, $sectionId, $status, $checkIn, $checkOut, $workingHours, $lateMinutes, $leaveId, $data, $marker) {
            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', $date)
                ->first() ?? new Attendance([
                    'user_id' => $user->id,
                    'date' => $date,
                ]);

            $isNew = ! $attendance->exists;
            if ($isNew) {
                $attendance->attendance_no = Attendance::generateAttendanceNumber();
                $attendance->created_by = $marker?->id;
            }

            $attendance->user_type = $userType;
            $attendance->course_id = $courseId;
            $attendance->batch_id = $batchId;
            $attendance->section_id = $sectionId;
            $attendance->status = $status;
            $attendance->check_in_time = $checkIn;
            $attendance->check_out_time = $checkOut;
            $attendance->working_hours = $workingHours;
            $attendance->late_minutes = $lateMinutes;
            $attendance->entry_source = Attendance::SOURCE_MANUAL_SINGLE;
            $attendance->leave_application_id = $leaveId;
            $attendance->marked_by = $marker?->id;
            $attendance->remarks = $data['remarks'] ?? null;
            $attendance->updated_by = $marker?->id;
            $attendance->save();

            AuditLog::record(
                action: 'attendance_marked',
                details: [
                    'attendance_no' => $attendance->attendance_no,
                    'user' => $user->name,
                    'date' => $date,
                    'status' => $status,
                ],
                userId: $marker?->id,
                auditableType: Attendance::class,
                auditableId: $attendance->id,
                description: "Marked {$status} attendance for {$user->name} on {$date}"
            );

            if (! empty($data['send_notification'])) {
                $this->notificationService->notifyAttendanceMarked($attendance);
            }

            return $attendance;
        });
    }

    /**
     * Mark bulk attendance for students (by Batch/Course) or staff (by department/role).
     *
     * @param  array{
     *     date: string,
     *     records: array<int, array{user_id: int, status: string, check_in_time?: string|null, check_out_time?: string|null, remarks?: string|null}>,
     *     send_notification?: bool
     * }  $data
     * @return array{saved: int, skipped: int}
     */
    public function markBulk(array $data, ?User $marker = null): array
    {
        $date = Carbon::parse($data['date'])->toDateString();
        $saved = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $date, $marker, &$saved, &$skipped) {
            foreach ($data['records'] as $entry) {
                if (empty($entry['user_id'])) {
                    $skipped++;

                    continue;
                }

                $user = User::find($entry['user_id']);
                if (! $user) {
                    $skipped++;

                    continue;
                }

                $userType = $user->isStudent() ? 'student' : 'staff';

                // Check approved leave
                $approvedLeave = LeaveApplication::where('user_id', $user->id)
                    ->where('status', LeaveApplication::STATUS_APPROVED)
                    ->where('from_date', '<=', $date)
                    ->where('to_date', '>=', $date)
                    ->first();

                $status = $entry['status'] ?? Attendance::STATUS_PRESENT;
                $leaveId = null;

                if ($approvedLeave) {
                    $status = Attendance::STATUS_ON_LEAVE;
                    $leaveId = $approvedLeave->id;
                }

                $courseId = null;
                $batchId = null;
                $sectionId = null;

                if ($userType === 'student') {
                    $enrollment = $user->activeEnrollments()->first();
                    if ($enrollment) {
                        $courseId = $enrollment->course_id;
                        $batchId = $enrollment->batch_id;
                        $sectionId = $enrollment->section_id;
                    }
                }

                $checkIn = ! empty($entry['check_in_time']) ? $entry['check_in_time'] : null;
                $checkOut = ! empty($entry['check_out_time']) ? $entry['check_out_time'] : null;
                $workingHours = 0.00;
                $lateMinutes = 0;

                if ($checkIn && $checkOut) {
                    $in = Carbon::parse("{$date} {$checkIn}");
                    $out = Carbon::parse("{$date} {$checkOut}");
                    if ($out->gte($in)) {
                        $workingHours = round($in->diffInMinutes($out) / 60, 2);
                    }
                }

                $attendance = Attendance::where('user_id', $user->id)
                    ->whereDate('date', $date)
                    ->first() ?? new Attendance([
                        'user_id' => $user->id,
                        'date' => $date,
                    ]);

                if (! $attendance->exists) {
                    $attendance->attendance_no = Attendance::generateAttendanceNumber();
                    $attendance->created_by = $marker?->id;
                }

                $attendance->user_type = $userType;
                $attendance->course_id = $courseId;
                $attendance->batch_id = $batchId;
                $attendance->section_id = $sectionId;
                $attendance->status = $status;
                $attendance->check_in_time = $checkIn;
                $attendance->check_out_time = $checkOut;
                $attendance->working_hours = $workingHours;
                $attendance->late_minutes = $lateMinutes;
                $attendance->entry_source = Attendance::SOURCE_MANUAL_BULK;
                $attendance->leave_application_id = $leaveId;
                $attendance->marked_by = $marker?->id;
                $attendance->remarks = $entry['remarks'] ?? null;
                $attendance->updated_by = $marker?->id;
                $attendance->save();

                $saved++;

                if (! empty($data['send_notification'])) {
                    $this->notificationService->notifyAttendanceMarked($attendance);
                }
            }

            AuditLog::record(
                action: 'attendance_bulk_marked',
                details: [
                    'date' => $date,
                    'total_saved' => $saved,
                ],
                userId: $marker?->id,
                description: "Bulk marked {$saved} attendance records for date {$date}"
            );
        });

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * Process raw biometric punch logs into the attendances table.
     *
     * @return array{processed: int, errors: int}
     */
    public function processBiometricPunches(?int $limit = 500): array
    {
        $unprocessedLogs = BiometricLog::unprocessed()
            ->with(['device'])
            ->orderBy('punch_time')
            ->limit($limit)
            ->get();

        $processed = 0;
        $errors = 0;

        foreach ($unprocessedLogs as $log) {
            try {
                DB::transaction(function () use ($log, &$processed) {
                    // Match user by biometric_pin, rfid_card_no, or employee_id
                    $user = null;
                    if ($log->user_id) {
                        $user = User::find($log->user_id);
                    } elseif ($log->biometric_pin) {
                        $user = User::where('biometric_pin', $log->biometric_pin)
                            ->orWhere('employee_id', $log->biometric_pin)
                            ->first();
                    } elseif ($log->card_number) {
                        $user = User::where('rfid_card_no', $log->card_number)->first();
                    }

                    if (! $user) {
                        // Mark processed with warning
                        $log->update([
                            'is_processed' => true,
                            'processed_at' => Carbon::now(),
                        ]);

                        return;
                    }

                    $punchDate = Carbon::parse($log->punch_time)->toDateString();
                    $punchTime = Carbon::parse($log->punch_time)->toTimeString();
                    $userType = $user->isStudent() ? 'student' : 'staff';

                    $courseId = null;
                    $batchId = null;
                    $sectionId = null;

                    if ($userType === 'student') {
                        $enrollment = $user->activeEnrollments()->first();
                        if ($enrollment) {
                            $courseId = $enrollment->course_id;
                            $batchId = $enrollment->batch_id;
                            $sectionId = $enrollment->section_id;
                        }
                    }

                    $attendance = Attendance::where('user_id', $user->id)
                        ->whereDate('date', $punchDate)
                        ->first() ?? new Attendance([
                            'user_id' => $user->id,
                            'date' => $punchDate,
                        ]);

                    if (! $attendance->exists) {
                        $attendance->attendance_no = Attendance::generateAttendanceNumber();
                        $attendance->user_type = $userType;
                        $attendance->course_id = $courseId;
                        $attendance->batch_id = $batchId;
                        $attendance->section_id = $sectionId;
                        $attendance->check_in_time = $punchTime;
                        $attendance->status = Attendance::STATUS_PRESENT;
                        $attendance->entry_source = Attendance::SOURCE_BIOMETRIC_DEVICE;
                        $attendance->biometric_device_id = $log->biometric_device_id;
                    } else {
                        // Existing punch today: treat second/later punch as checkout or update checkout
                        if (empty($attendance->check_in_time)) {
                            $attendance->check_in_time = $punchTime;
                        } else {
                            $attendance->check_out_time = $punchTime;
                        }
                    }

                    // Recalculate working hours & late minutes
                    if ($attendance->check_in_time && $attendance->check_out_time) {
                        $in = Carbon::parse("{$punchDate} {$attendance->check_in_time}");
                        $out = Carbon::parse("{$punchDate} {$attendance->check_out_time}");
                        if ($out->gte($in)) {
                            $attendance->working_hours = round($in->diffInMinutes($out) / 60, 2);
                        }
                    }

                    $calendar = $this->resolveCalendarForUser($user, $userType, $courseId, $batchId);
                    if ($calendar && $attendance->check_in_time) {
                        $expected = Carbon::parse("{$punchDate} {$calendar->expected_start_time}")->addMinutes($calendar->late_grace_minutes);
                        $actual = Carbon::parse("{$punchDate} {$attendance->check_in_time}");
                        if ($actual->gt($expected)) {
                            $attendance->late_minutes = $expected->diffInMinutes($actual);
                            $attendance->status = Attendance::STATUS_LATE;
                        }
                    }

                    $attendance->save();

                    $log->update([
                        'user_id' => $user->id,
                        'is_processed' => true,
                        'processed_at' => Carbon::now(),
                    ]);

                    $processed++;
                });
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Resolve effective working calendar for a user.
     */
    public function resolveCalendarForUser(User $user, string $userType, ?int $courseId = null, ?int $batchId = null): ?WorkingCalendar
    {
        if ($userType === 'student' && $batchId) {
            $batchCalendar = WorkingCalendar::where('target_type', 'student')
                ->where('batch_id', $batchId)
                ->first();
            if ($batchCalendar) {
                return $batchCalendar;
            }
        }

        if ($userType === 'student' && $courseId) {
            $courseCalendar = WorkingCalendar::where('target_type', 'student')
                ->where('course_id', $courseId)
                ->first();
            if ($courseCalendar) {
                return $courseCalendar;
            }
        }

        return WorkingCalendar::where('target_type', $userType)
            ->where('is_default', true)
            ->first() ?? WorkingCalendar::where('target_type', $userType)->first();
    }

    /**
     * Determine day status (working_day, holiday, weekly_off, test_day) based on calendars and holidays.
     *
     * @return array{is_working_day: bool, type: string, label: string, holiday: ?AttendanceHoliday}
     */
    public function checkDateStatus(Carbon $date, string $userType, ?int $courseId = null, ?int $batchId = null): array
    {
        $dateStr = $date->toDateString();

        // 1. Check holidays
        $holiday = AttendanceHoliday::where('date', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('to_date')->where('date', $dateStr)
                    ->orWhere(function ($q2) use ($dateStr) {
                        $q2->whereNotNull('to_date')->where('to_date', '>=', $dateStr);
                    });
            })
            ->where(function ($q) use ($userType) {
                $q->where('target_type', 'all')->orWhere('target_type', $userType);
            })
            ->first();

        if ($holiday) {
            $isWorking = in_array($holiday->type, [AttendanceHoliday::TYPE_WEEKLY_TEST, AttendanceHoliday::TYPE_EXAM_DAY]);

            return [
                'is_working_day' => $isWorking,
                'type' => $holiday->type,
                'label' => $holiday->title,
                'holiday' => $holiday,
            ];
        }

        // 2. Check working calendar
        $calendar = WorkingCalendar::where('target_type', $userType)
            ->where('is_default', true)
            ->first();

        if ($calendar && ! $calendar->isWorkingDay($date)) {
            return [
                'is_working_day' => false,
                'type' => AttendanceHoliday::TYPE_WEEKLY_OFF,
                'label' => __('Weekly Off'),
                'holiday' => null,
            ];
        }

        return [
            'is_working_day' => true,
            'type' => 'working_day',
            'label' => __('Working Day'),
            'holiday' => null,
        ];
    }

    /**
     * Compute monthly attendance summary and day-by-day matrix for a specific user.
     *
     * @return array{
     *     total_days_in_month: int,
     *     total_working_days: int,
     *     present_count: int,
     *     absent_count: int,
     *     late_count: int,
     *     half_day_count: int,
     *     leave_count: int,
     *     holiday_count: int,
     *     weekly_off_count: int,
     *     attendance_percentage: float,
     *     is_shortage: bool,
     *     days: array<int, array{
     *         date: string,
     *         day_number: int,
     *         day_name: string,
     *         status: string,
     *         check_in: ?string,
     *         check_out: ?string,
     *         working_hours: float,
     *         late_minutes: int,
     *         label: string,
     *         badge_class: string
     *     }>
     * }
     */
    public function getMonthlyUserAttendanceMatrix(User $user, int $year, int $month): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        $totalDays = $startDate->daysInMonth;
        $userType = $user->isStudent() ? 'student' : 'staff';

        // Load existing attendance records
        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->keyBy(fn ($att) => Carbon::parse($att->date)->toDateString());

        $workingDays = 0;
        $presentCount = 0;
        $absentCount = 0;
        $lateCount = 0;
        $halfDayCount = 0;
        $leaveCount = 0;
        $holidayCount = 0;
        $weeklyOffCount = 0;

        $days = [];

        for ($day = 1; $day <= $totalDays; $day++) {
            $currentDate = Carbon::create($year, $month, $day);
            $dateStr = $currentDate->toDateString();
            $dayInfo = $this->checkDateStatus($currentDate, $userType);

            if ($dayInfo['is_working_day']) {
                $workingDays++;
            }

            /** @var Attendance|null $record */
            $record = $attendances->get($dateStr);

            if ($record) {
                $status = $record->status;
                $checkIn = $record->check_in_time;
                $checkOut = $record->check_out_time;
                $hours = (float) $record->working_hours;
                $late = $record->late_minutes;
                $label = $record->statusLabel();
                $badge = $record->statusBadgeClass();

                switch ($status) {
                    case Attendance::STATUS_PRESENT:
                        $presentCount++;
                        break;
                    case Attendance::STATUS_LATE:
                        $presentCount++;
                        $lateCount++;
                        break;
                    case Attendance::STATUS_HALF_DAY:
                        $presentCount += 0.5;
                        $halfDayCount++;
                        break;
                    case Attendance::STATUS_ABSENT:
                        $absentCount++;
                        break;
                    case Attendance::STATUS_ON_LEAVE:
                        $leaveCount++;
                        break;
                    case Attendance::STATUS_HOLIDAY:
                        $holidayCount++;
                        break;
                    case Attendance::STATUS_WEEKLY_OFF:
                        $weeklyOffCount++;
                        break;
                }
            } else {
                // If past date and working day, default to not recorded or absent
                if ($currentDate->isPast() && $dayInfo['is_working_day']) {
                    $status = 'not_marked';
                    $label = __('Unmarked');
                    $badge = 'bg-zinc-500/10 text-zinc-500 border-zinc-500/20';
                } elseif (! $dayInfo['is_working_day']) {
                    $status = $dayInfo['type'];
                    $label = $dayInfo['label'];
                    $badge = 'bg-zinc-500/10 text-zinc-500 border-zinc-500/20';
                    if ($dayInfo['type'] === AttendanceHoliday::TYPE_WEEKLY_OFF) {
                        $weeklyOffCount++;
                    } else {
                        $holidayCount++;
                    }
                } else {
                    $status = 'future';
                    $label = __('Upcoming');
                    $badge = 'bg-transparent text-muted-foreground border-border/40';
                }

                $checkIn = null;
                $checkOut = null;
                $hours = 0.00;
                $late = 0;
            }

            $days[$day] = [
                'date' => $dateStr,
                'day_number' => $day,
                'day_name' => $currentDate->format('D'),
                'status' => $status,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'working_hours' => $hours,
                'late_minutes' => $late,
                'label' => $label,
                'badge_class' => $badge,
            ];
        }

        $effectiveTotal = max(1, $workingDays);
        $percentage = round(($presentCount / $effectiveTotal) * 100, 1);
        $isShortage = $percentage < 75.0 && $workingDays > 5;

        return [
            'total_days_in_month' => $totalDays,
            'total_working_days' => $workingDays,
            'present_count' => (int) floor($presentCount),
            'absent_count' => $absentCount,
            'late_count' => $lateCount,
            'half_day_count' => $halfDayCount,
            'leave_count' => $leaveCount,
            'holiday_count' => $holidayCount,
            'weekly_off_count' => $weeklyOffCount,
            'attendance_percentage' => min(100.0, $percentage),
            'is_shortage' => $isShortage,
            'days' => $days,
        ];
    }
}
