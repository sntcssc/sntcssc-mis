<?php

use App\Models\Attendance;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceNotificationLog;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Course;
use App\Models\LeaveAllocation;
use App\Models\LeaveCategory;
use App\Models\User;
use App\Models\WorkingCalendar;
use App\Services\Attendance\AttendanceImportExportService;
use App\Services\Attendance\AttendanceNotificationService;
use App\Services\Attendance\AttendanceService;
use App\Services\Leave\LeaveManagementService;
use App\Services\RbacService;
use Database\Seeders\LeaveAndAttendanceSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(LeaveAndAttendanceSeeder::class);
});

test('leave and attendance seeder populates standard categories, calendars, and holidays', function () {
    expect(LeaveCategory::count())->toBeGreaterThan(0)
        ->and(LeaveCategory::where('code', 'CL')->exists())->toBeTrue()
        ->and(WorkingCalendar::where('target_type', 'staff')->exists())->toBeTrue()
        ->and(WorkingCalendar::where('target_type', 'student')->exists())->toBeTrue()
        ->and(AttendanceHoliday::count())->toBeGreaterThan(0);
});

test('user can apply for leave and view quota balance', function () {
    /** @var User $staff */
    $staff = User::factory()->create([
        'name' => 'Amit Sen',
        'email' => 'amit.sen@example.com',
        'employee_id' => 'EMP-1001',
        'staff_department' => 'Administration',
    ]);
    $staff->assignRole('Staff');

    $clCategory = LeaveCategory::where('code', 'CL')->first();

    LeaveAllocation::create([
        'user_id' => $staff->id,
        'leave_category_id' => $clCategory->id,
        'year' => (int) Carbon::now()->year,
        'total_days' => 12.0,
        'used_days' => 0.0,
        'pending_days' => 0.0,
    ]);

    /** @var LeaveManagementService $service */
    $service = app(LeaveManagementService::class);
    $initialBalances = $service->getUserLeaveBalances($staff);
    $clBalance = collect($initialBalances)->firstWhere('category.code', 'CL');

    expect($clBalance['remaining_days'])->toEqual(12.0);

    // Apply for 2 days leave
    $fromDate = Carbon::today()->addDays(5)->format('Y-m-d');
    $toDate = Carbon::today()->addDays(6)->format('Y-m-d');

    $application = $service->applyLeave($staff, [
        'leave_category_id' => $clCategory->id,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'is_half_day' => false,
        'reason' => 'Family urgent matters in native town',
    ]);

    expect($application->status)->toBe('pending')
        ->and((float) $application->total_days)->toEqual(2.0);

    $updatedBalances = $service->getUserLeaveBalances($staff);
    $clBalanceUpdated = collect($updatedBalances)->firstWhere('category.code', 'CL');
    expect($clBalanceUpdated['pending_days'])->toEqual(2.0);
});

test('approving a leave automatically synchronizes attendance records as on_leave', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Debolina Roy',
        'email' => 'debolina.roy@example.com',
    ]);
    $student->assignRole('Student');

    /** @var User $admin */
    $admin = User::factory()->create([
        'name' => 'Academic Dean',
        'email' => 'dean@example.com',
    ]);
    $admin->assignRole('Super Administrator');

    $clCategory = LeaveCategory::where('code', 'CL')->first();

    $fromDate = Carbon::today()->addDays(10)->format('Y-m-d');
    $toDate = Carbon::today()->addDays(11)->format('Y-m-d');

    /** @var LeaveManagementService $service */
    $service = app(LeaveManagementService::class);

    $application = $service->applyLeave($student, [
        'leave_category_id' => $clCategory->id,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'is_half_day' => false,
        'reason' => 'Family event in native district',
    ]);

    // Approve leave
    $service->approveLeave($application, $admin, 'Approved on valid grounds');

    expect($application->fresh()->status)->toBe('approved');

    // Verify automatic attendance synchronization
    $syncedAttendances = Attendance::where('leave_application_id', $application->id)->get();

    expect($syncedAttendances)->toHaveCount(2);

    foreach ($syncedAttendances as $att) {
        expect($att->status)->toBe('on_leave')
            ->and($att->entry_source)->toBe('system_auto_leave')
            ->and($att->leave_application_id)->toBe($application->id);
    }
});

test('cancelling or rejecting an approved leave reverts automatic attendance records', function () {
    /** @var User $staff */
    $staff = User::factory()->create(['name' => 'Pradeep Mondal', 'email' => 'pradeep@example.com']);
    $staff->assignRole('Staff');

    /** @var User $admin */
    $admin = User::factory()->create(['name' => 'HR Director', 'email' => 'hr@example.com']);
    $admin->assignRole('Super Administrator');

    $clCategory = LeaveCategory::where('code', 'CL')->first();
    $fromDate = Carbon::today()->addDays(15)->format('Y-m-d');
    $toDate = Carbon::today()->addDays(15)->format('Y-m-d');

    /** @var LeaveManagementService $service */
    $service = app(LeaveManagementService::class);

    $application = $service->applyLeave($staff, [
        'leave_category_id' => $clCategory->id,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'is_half_day' => false,
        'reason' => 'Short trip',
    ]);

    $service->approveLeave($application, $admin);

    expect(Attendance::where('leave_application_id', $application->id)->exists())->toBeTrue();

    // Cancel leave
    $service->cancelLeave($application, $staff, 'Trip cancelled');

    expect(Attendance::where('leave_application_id', $application->id)->exists())->toBeFalse()
        ->and($application->fresh()->status)->toBe('cancelled');
});

test('working calendar correctly distinguishes staff 2nd/4th saturday off from student class schedule', function () {
    /** @var AttendanceService $attendanceService */
    $attendanceService = app(AttendanceService::class);

    // Find 2nd Saturday of November 2026
    $date = Carbon::create(2026, 11, 1);
    while ($date->dayOfWeek !== Carbon::SATURDAY) {
        $date->addDay();
    }
    // 1st saturday found, now 2nd saturday:
    $secondSaturday = $date->copy()->addWeek();

    $staffStatus = $attendanceService->checkDateStatus($secondSaturday, 'staff');
    $studentStatus = $attendanceService->checkDateStatus($secondSaturday, 'student');

    expect($staffStatus['is_working_day'])->toBeFalse()
        ->and($staffStatus['type'])->toBe('weekly_off')
        ->and($studentStatus['is_working_day'])->toBeTrue();
});

test('single and bulk attendance marking records attendance accurately', function () {
    /** @var User $student1 */
    $student1 = User::factory()->create(['name' => 'Student One', 'email' => 's1@example.com']);
    $student1->assignRole('Student');

    /** @var User $student2 */
    $student2 = User::factory()->create(['name' => 'Student Two', 'email' => 's2@example.com']);
    $student2->assignRole('Student');

    /** @var User $admin */
    $admin = User::factory()->create(['name' => 'Attendance Admin', 'email' => 'attadmin@example.com']);
    $admin->assignRole('Super Administrator');

    $today = Carbon::today()->format('Y-m-d');

    /** @var AttendanceService $attendanceService */
    $attendanceService = app(AttendanceService::class);

    // Single mark
    $singleAtt = $attendanceService->markSingle([
        'user_id' => $student1->id,
        'date' => $today,
        'status' => 'present',
        'check_in_time' => '09:05:00',
    ], $admin);

    expect($singleAtt->status)->toBe('present')
        ->and($singleAtt->check_in_time)->toBe('09:05:00');

    // Bulk mark
    $result = $attendanceService->markBulk([
        'date' => $today,
        'records' => [
            [
                'user_id' => $student1->id,
                'status' => 'late',
                'check_in_time' => '09:40:00',
                'remarks' => 'Traffic delay',
            ],
            [
                'user_id' => $student2->id,
                'status' => 'absent',
                'remarks' => 'Uninformed absence',
            ],
        ],
    ], $admin);

    expect($result['saved'])->toBe(2);

    $s1Fresh = Attendance::where('user_id', $student1->id)->whereDate('date', $today)->first();
    $s2Fresh = Attendance::where('user_id', $student2->id)->whereDate('date', $today)->first();

    expect($s1Fresh)->not->toBeNull()
        ->and($s1Fresh->status)->toBe('late')
        ->and($s2Fresh)->not->toBeNull()
        ->and($s2Fresh->status)->toBe('absent');
});

test('biometric punch ingestion and background processing syncs to attendance register', function () {
    $device = BiometricDevice::create([
        'device_name' => 'Main Gate ZKTeco',
        'device_serial_number' => 'ZKT-01-SN',
        'device_ip' => '192.168.1.100',
        'api_token' => 'bio_test_token_xyz',
        'status' => 'online',
    ]);

    /** @var User $staff */
    $staff = User::factory()->create([
        'name' => 'Rajesh Sharma',
        'email' => 'rajesh.sharma@example.com',
        'biometric_pin' => '8888',
        'rfid_card_no' => 'RFID-8888',
    ]);
    $staff->assignRole('Staff');

    $today = Carbon::today()->format('Y-m-d');
    $punchTime = Carbon::today()->setTime(9, 15, 0);

    // Record raw punch log
    $log = BiometricLog::create([
        'biometric_device_id' => $device->id,
        'biometric_pin' => '8888',
        'card_number' => 'RFID-8888',
        'punch_time' => $punchTime,
        'punch_type' => 'in',
        'is_processed' => false,
    ]);

    expect($log->is_processed)->toBeFalse();

    /** @var AttendanceService $attendanceService */
    $attendanceService = app(AttendanceService::class);

    // Process biometric logs
    $result = $attendanceService->processBiometricPunches();

    expect($result['processed'])->toBeGreaterThanOrEqual(1);

    // Verify attendances record
    $att = Attendance::where('user_id', $staff->id)->whereDate('date', $today)->first();
    expect($att)->not->toBeNull()
        ->and($att->status)->toBe('present')
        ->and($att->entry_source)->toBe(Attendance::SOURCE_BIOMETRIC_DEVICE)
        ->and($att->check_in_time)->toBe('09:15:00');
});

test('attendance percentage calculation flags defaulters below 75 percent criteria', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Tanmay Mukherjee',
        'email' => 'tanmay@example.com',
    ]);
    $student->assignRole('Student');

    // Create 10 days of attendance: 5 present, 5 absent
    for ($i = 1; $i <= 10; $i++) {
        $date = Carbon::create(2026, 10, $i)->format('Y-m-d');
        Attendance::create([
            'user_id' => $student->id,
            'user_type' => 'student',
            'date' => $date,
            'status' => $i <= 5 ? Attendance::STATUS_PRESENT : Attendance::STATUS_ABSENT,
            'entry_source' => Attendance::SOURCE_MANUAL_SINGLE,
        ]);
    }

    /** @var AttendanceService $attendanceService */
    $attendanceService = app(AttendanceService::class);
    $matrix = $attendanceService->getMonthlyUserAttendanceMatrix($student, 2026, 10);

    expect($matrix['present_count'])->toBe(5)
        ->and($matrix['attendance_percentage'])->toBeLessThan(75.0)
        ->and($matrix['is_shortage'])->toBeTrue();
});

test('attendance notification service records automated alerts and absentee reminders', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Ananya Das',
        'email' => 'ananya@example.com',
        'phone' => '9876500000',
    ]);
    $student->assignRole('Student');

    $today = Carbon::today()->format('Y-m-d');

    $attendance = Attendance::create([
        'user_id' => $student->id,
        'user_type' => 'student',
        'date' => $today,
        'status' => Attendance::STATUS_ABSENT,
        'entry_source' => Attendance::SOURCE_MANUAL_SINGLE,
    ]);

    /** @var AttendanceNotificationService $notificationService */
    $notificationService = app(AttendanceNotificationService::class);

    // Send single alert
    $notificationService->notifyAttendanceMarked($attendance);

    expect(AttendanceNotificationLog::where('attendance_id', $attendance->id)->exists())->toBeTrue();

    // Shortage reminder
    $dispatched = $notificationService->sendAttendanceReminder(
        $student,
        'shortage',
        'Your classroom attendance has fallen below 75%. Please attend classes regularly.'
    );

    expect($dispatched)->toBeTrue()
        ->and(AttendanceNotificationLog::where('user_id', $student->id)->whereNull('attendance_id')->exists())->toBeTrue();
});

test('attendance and leave routes are accessible by authorized roles', function () {
    /** @var User $admin */
    $admin = User::factory()->create(['email' => 'admin_test@example.com']);
    $admin->assignRole('Super Administrator');

    /** @var User $student */
    $student = User::factory()->create(['email' => 'student_test@example.com']);
    $student->assignRole('Student');

    /** @var User $staff */
    $staff = User::factory()->create(['email' => 'staff_test@example.com']);
    $staff->assignRole('Staff');

    $slug = $admin->currentTeam->slug;

    // Admin pages
    $this->actingAs($admin)->get("/{$slug}/attendance")->assertOk();
    $this->actingAs($admin)->get("/{$slug}/attendance/mark")->assertOk();
    $this->actingAs($admin)->get("/{$slug}/attendance/reports")->assertOk();
    $this->actingAs($admin)->get("/{$slug}/attendance/biometric")->assertOk();
    $this->actingAs($admin)->get("/{$slug}/attendance/calendar")->assertOk();
    $this->actingAs($admin)->get("/{$slug}/system/settings/attendance")->assertOk();
    $this->actingAs($admin)->get("/{$slug}/leaves")->assertOk();
    $this->actingAs($admin)->get("/{$slug}/leaves/categories")->assertOk();

    // Student pages
    $studentSlug = $student->currentTeam->slug;
    $this->actingAs($student)->get("/{$studentSlug}/student/attendance")->assertOk();
    $this->actingAs($student)->get("/{$studentSlug}/student/leaves")->assertOk();

    // Staff pages
    $staffSlug = $staff->currentTeam->slug;
    $this->actingAs($staff)->get("/{$staffSlug}/portal/my-attendance")->assertOk();
    $this->actingAs($staff)->get("/{$staffSlug}/portal/my-leaves")->assertOk();
});

test('AttendanceImportExportService cleans malformed UTF-8 strings correctly', function () {
    // Valid string
    expect(AttendanceImportExportService::cleanUtf8('Hello World'))->toBe('Hello World');

    // Windows-1252 / ISO-8859-1 encoded string with accented chars
    $rawWin1252 = mb_convert_encoding('Café Müller — Student', 'Windows-1252', 'UTF-8');
    $cleaned = AttendanceImportExportService::cleanUtf8($rawWin1252);
    expect(mb_check_encoding($cleaned, 'UTF-8'))->toBeTrue();

    // Invalid byte sequence
    $brokenUtf8 = "Invalid \xC3\x28 byte test";
    $cleanedBroken = AttendanceImportExportService::cleanUtf8($brokenUtf8);
    expect(mb_check_encoding($cleanedBroken, 'UTF-8'))->toBeTrue();
});

test('attendance imports and exports execute properly with utf8 safety', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Sourav Mukherjee',
        'email' => 'sourav@example.com',
        'student_id' => 'STU-2026-9999',
    ]);
    $student->assignRole('Student');

    // Test Sample CSV Download
    $sampleResponse = AttendanceImportExportService::downloadSampleCsv();
    expect($sampleResponse->getStatusCode())->toBe(200)
        ->and($sampleResponse->headers->get('Content-Type'))->toContain('text/csv');

    // Test CSV Import with UTF-8 data
    $csvContent = "\xEF\xBB\xBFdate,student_id,status,check_in_time,check_out_time,remarks\n";
    $csvContent .= Carbon::today()->toDateString().",STU-2026-9999,present,09:30,17:30,Classroom session with special chars Café\n";

    $tempCsv = tempnam(sys_get_temp_dir(), 'test_att_').'.csv';
    file_put_contents($tempCsv, $csvContent);

    $result = AttendanceImportExportService::importAttendance($tempCsv);
    @unlink($tempCsv);

    expect($result['imported'])->toBe(1)
        ->and(Attendance::where('user_id', $student->id)->exists())->toBeTrue();

    // Test CSV Export
    $csvExportResponse = AttendanceImportExportService::exportCsv(Attendance::query());
    expect($csvExportResponse->getStatusCode())->toBe(200);

    // Test Excel Export
    $excelResponse = AttendanceImportExportService::exportExcel(Attendance::query());
    expect($excelResponse->getStatusCode())->toBe(200)
        ->and(file_exists($excelResponse->getFile()->getPathname()))->toBeTrue();
});

test('selectedCourseId accepts string and empty string without TypeError in Livewire components', function () {
    /** @var User $admin */
    $admin = User::factory()->create(['email' => 'admin_lw_test@example.com']);
    $admin->assignRole('Super Administrator');

    $course = Course::first() ?? Course::create([
        'name' => 'General Studies Foundation 2026',
        'code' => 'GS-2026',
        'is_active' => true,
    ]);

    // Livewire Reports component
    Livewire\Livewire::actingAs($admin)
        ->test('pages::admin.attendance.reports')
        ->set('selectedCourseId', (string) $course->id)
        ->assertSet('selectedCourseId', (string) $course->id)
        ->set('selectedCourseId', '')
        ->assertSet('selectedCourseId', null);

    // Livewire Mark component
    Livewire\Livewire::actingAs($admin)
        ->test('pages::admin.attendance.mark')
        ->set('selectedCourseId', (string) $course->id)
        ->assertSet('selectedCourseId', (string) $course->id)
        ->set('selectedCourseId', '')
        ->assertSet('selectedCourseId', null);

    // Livewire Payment Schedules component
    Livewire\Livewire::actingAs($admin)
        ->test('pages::admin.payments.schedules')
        ->set('selectedCourseId', (string) $course->id)
        ->assertSet('selectedCourseId', (string) $course->id)
        ->set('selectedCourseId', '')
        ->assertSet('selectedCourseId', null);
});

test('reports component export actions download files without UTF-8 malformed exception', function () {
    /** @var User $admin */
    $admin = User::factory()->create(['email' => 'admin_export_test@example.com']);
    $admin->assignRole('Super Administrator');

    // Test PDF export
    Livewire\Livewire::actingAs($admin)
        ->test('pages::admin.attendance.reports')
        ->call('exportPdf')
        ->assertFileDownloaded();

    // Test CSV export
    Livewire\Livewire::actingAs($admin)
        ->test('pages::admin.attendance.reports')
        ->call('exportCsv')
        ->assertFileDownloaded();

    // Test Excel export
    Livewire\Livewire::actingAs($admin)
        ->test('pages::admin.attendance.reports')
        ->call('exportExcel')
        ->assertFileDownloaded();
});
