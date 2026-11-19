<?php

namespace Database\Seeders;

use App\Models\AttendanceHoliday;
use App\Models\LeaveCategory;
use App\Models\Setting;
use App\Models\WorkingCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class LeaveAndAttendanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed Leave Categories
        $categories = [
            [
                'name' => 'Casual Leave',
                'code' => 'CL',
                'applicable_to' => 'both',
                'color' => 'blue',
                'annual_quota' => 12.0,
                'monthly_limit' => 2.0,
                'requires_attachment' => false,
                'requires_approval' => true,
                'is_paid' => true,
                'description' => 'Standard short-duration personal leave for urgent private affairs.',
            ],
            [
                'name' => 'Medical / Sick Leave',
                'code' => 'ML',
                'applicable_to' => 'both',
                'color' => 'rose',
                'annual_quota' => 10.0,
                'monthly_limit' => null,
                'requires_attachment' => true,
                'requires_approval' => true,
                'is_paid' => true,
                'description' => 'Leave on grounds of sickness or medical treatment. Doctor certificate required for > 2 days.',
            ],
            [
                'name' => 'Academic / Study Leave',
                'code' => 'AL',
                'applicable_to' => 'student',
                'color' => 'purple',
                'annual_quota' => 15.0,
                'monthly_limit' => 5.0,
                'requires_attachment' => false,
                'requires_approval' => true,
                'is_paid' => false,
                'description' => 'Leave for external UPSC coaching, seminars, or field survey academic study.',
            ],
            [
                'name' => 'Duty / Official Leave',
                'code' => 'DL',
                'applicable_to' => 'staff',
                'color' => 'emerald',
                'annual_quota' => 10.0,
                'monthly_limit' => null,
                'requires_attachment' => false,
                'requires_approval' => true,
                'is_paid' => true,
                'description' => 'Official outstation deputation, exam invigilation, or institutional meetings.',
            ],
            [
                'name' => 'Maternity / Paternity Leave',
                'code' => 'MAT',
                'applicable_to' => 'staff',
                'color' => 'amber',
                'annual_quota' => 90.0,
                'monthly_limit' => null,
                'requires_attachment' => true,
                'requires_approval' => true,
                'is_paid' => true,
                'description' => 'Statutory parental and childcare leave for office staff as per state government rules.',
            ],
            [
                'name' => 'Exam Leave',
                'code' => 'EXAM',
                'applicable_to' => 'student',
                'color' => 'teal',
                'annual_quota' => 7.0,
                'monthly_limit' => null,
                'requires_attachment' => true,
                'requires_approval' => true,
                'is_paid' => false,
                'description' => 'Leave for appearing in UPSC Prelims/Mains, WBCS, or university degree semester exams.',
            ],
            [
                'name' => 'Compensatory Off',
                'code' => 'COMP',
                'applicable_to' => 'staff',
                'color' => 'sky',
                'annual_quota' => 5.0,
                'monthly_limit' => null,
                'requires_attachment' => false,
                'requires_approval' => true,
                'is_paid' => true,
                'description' => 'Leave granted in lieu of attending official duties on holidays or Sundays.',
            ],
        ];

        foreach ($categories as $cat) {
            LeaveCategory::updateOrCreate(['code' => $cat['code']], $cat);
        }

        // 2. Seed Default Working Calendars
        WorkingCalendar::updateOrCreate(
            ['name' => 'Office Staff General Schedule', 'target_type' => 'staff'],
            [
                'monday_active' => true,
                'tuesday_active' => true,
                'wednesday_active' => true,
                'thursday_active' => true,
                'friday_active' => true,
                'saturday_active' => true,
                'sunday_active' => false,
                'saturday_rules' => '2nd_4th_off',
                'expected_start_time' => '09:30:00',
                'expected_end_time' => '17:30:00',
                'late_grace_minutes' => 15,
                'half_day_hours' => 4.0,
                'is_default' => true,
            ]
        );

        WorkingCalendar::updateOrCreate(
            ['name' => 'Regular Student Academic Week', 'target_type' => 'student'],
            [
                'monday_active' => true,
                'tuesday_active' => true,
                'wednesday_active' => true,
                'thursday_active' => true,
                'friday_active' => true,
                'saturday_active' => true,
                'sunday_active' => false,
                'saturday_rules' => 'all_working',
                'expected_start_time' => '10:00:00',
                'expected_end_time' => '16:30:00',
                'late_grace_minutes' => 15,
                'half_day_hours' => 3.5,
                'is_default' => true,
            ]
        );

        // 3. Seed Sample Institutional & Govt Holidays
        $year = Carbon::now()->year;
        $holidays = [
            [
                'title' => 'Republic Day',
                'date' => "{$year}-01-26",
                'type' => AttendanceHoliday::TYPE_GOVT_HOLIDAY,
                'target_type' => 'all',
                'description' => 'National Holiday - Republic Day of India.',
            ],
            [
                'title' => 'Independence Day',
                'date' => "{$year}-08-15",
                'type' => AttendanceHoliday::TYPE_GOVT_HOLIDAY,
                'target_type' => 'all',
                'description' => 'National Holiday - Independence Day.',
            ],
            [
                'title' => 'Mahatma Gandhi Jayanti',
                'date' => "{$year}-10-02",
                'type' => AttendanceHoliday::TYPE_GOVT_HOLIDAY,
                'target_type' => 'all',
                'description' => 'National Holiday - Gandhi Jayanti.',
            ],
            [
                'title' => 'Weekly UPSC Prelims Assessment Test',
                'date' => Carbon::now()->startOfWeek()->addDays(5)->format('Y-m-d'), // Saturday
                'type' => AttendanceHoliday::TYPE_WEEKLY_TEST,
                'target_type' => 'student',
                'description' => 'Scheduled Full-Length GS Paper 1 Mock Test for UPSC aspirants.',
            ],
        ];

        foreach ($holidays as $h) {
            AttendanceHoliday::updateOrCreate(
                ['title' => $h['title'], 'date' => $h['date']],
                $h
            );
        }

        // 4. Seed Attendance and Leave Settings
        $settings = [
            'attendance.notify_student_present' => ['value' => '1', 'type' => Setting::TYPE_BOOLEAN, 'group' => 'attendance', 'label' => 'Notify Student On Marked Present'],
            'attendance.notify_student_absent' => ['value' => '1', 'type' => Setting::TYPE_BOOLEAN, 'group' => 'attendance', 'label' => 'Notify Student On Marked Absent'],
            'attendance.notify_student_late' => ['value' => '1', 'type' => Setting::TYPE_BOOLEAN, 'group' => 'attendance', 'label' => 'Notify Student On Marked Late'],
            'attendance.notification_channels' => ['value' => json_encode(['database', 'email', 'sms', 'whatsapp']), 'type' => Setting::TYPE_JSON, 'group' => 'attendance', 'label' => 'Attendance Notification Channels'],
            'attendance.mandatory_percentage' => ['value' => '75', 'type' => Setting::TYPE_NUMBER, 'group' => 'attendance', 'label' => 'Minimum Required Attendance %'],
            'attendance.daily_auto_notify_enabled' => ['value' => '0', 'type' => Setting::TYPE_BOOLEAN, 'group' => 'attendance', 'label' => 'Automatic Daily Absentee Notification Job'],
            'attendance.daily_auto_notify_time' => ['value' => '18:00', 'type' => Setting::TYPE_STRING, 'group' => 'attendance', 'label' => 'Daily Attendance Notification Dispatch Time'],
            'attendance.allow_staff_web_checkin' => ['value' => '1', 'type' => Setting::TYPE_BOOLEAN, 'group' => 'attendance', 'label' => 'Allow Staff Portal Web Check-In / Check-Out'],
            'attendance.biometric_sync_interval' => ['value' => '5', 'type' => Setting::TYPE_NUMBER, 'group' => 'attendance', 'label' => 'Biometric Auto-Process Interval (Minutes)'],
            'leave.auto_sync_to_attendance' => ['value' => '1', 'type' => Setting::TYPE_BOOLEAN, 'group' => 'leave', 'label' => 'Automatically Sync Approved Leaves to Attendance Register'],
            'leave.allow_half_day' => ['value' => '1', 'type' => Setting::TYPE_BOOLEAN, 'group' => 'leave', 'label' => 'Allow Half-Day Leave Applications'],
            'leave.default_annual_staff_quota' => ['value' => '15', 'type' => Setting::TYPE_NUMBER, 'group' => 'leave', 'label' => 'Default Annual Leave Quota for Office Staff'],
            'leave.default_annual_student_quota' => ['value' => '10', 'type' => Setting::TYPE_NUMBER, 'group' => 'leave', 'label' => 'Default Annual Leave Quota for Students'],
        ];

        foreach ($settings as $key => $meta) {
            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $meta['value'],
                    'type' => $meta['type'],
                    'group' => $meta['group'],
                    'label' => $meta['label'],
                    'status' => true,
                ]
            );
        }
    }
}
