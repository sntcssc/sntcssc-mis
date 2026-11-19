<?php

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\User;
use App\Services\AcademicCalendarService;
use App\Services\RbacService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('public');
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'Academic Administrator',
        'email' => 'academic.admin@sntcssc.gov.in',
    ]);
    $this->admin->assignRole('Super Administrator');
    $this->admin->ensureCurrentTeam();

    $this->student = User::factory()->create([
        'name' => 'Sourav Mukherjee',
        'email' => 'sourav.student@sntcssc.gov.in',
    ]);
    $this->student->assignRole('Student');
    $this->student->ensureCurrentTeam();
});

test('admin can access academic calendar management portal', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.academic-calendar.index', ['current_team' => $this->admin->currentTeam->slug]))
        ->assertOk()
        ->assertSee('Academic Calendar & Institutional Planning')
        ->assertSee('Month Planner')
        ->assertSee('Version Vault');
});

test('student can access academic calendar page in student portal', function () {
    $this->actingAs($this->student)
        ->get(route('student.academic-calendar.index', ['current_team' => $this->student->currentTeam->slug]))
        ->assertOk()
        ->assertSee('Institute Academic Calendar');
});

test('service creates academic calendar with initial document version and audit log', function () {
    $dummyPdf = UploadedFile::fake()->create('academic_calendar_2027_28.pdf', 500, 'application/pdf');

    $calendar = AcademicCalendarService::createCalendar([
        'academic_year' => '2027-2028',
        'title' => 'Annual Academic Calendar (2027-2028)',
        'theme_or_motto' => 'Integrity, Compassion, and Public Service',
        'start_date' => '2027-04-01',
        'end_date' => '2028-03-31',
        'total_working_days' => 246,
        'total_instructional_days' => 194,
        'status' => 'published',
        'is_current' => true,
        'initial_version_code' => 'v1.0',
        'initial_version_title' => 'Initial Council Approved Calendar',
    ], $dummyPdf, $this->admin);

    expect($calendar)->toBeInstanceOf(AcademicCalendar::class);
    expect($calendar->academic_year)->toBe('2027-2028');
    expect($calendar->is_current)->toBeTrue();
    expect($calendar->current_version_id)->not->toBeNull();

    $this->assertDatabaseHas('academic_calendars', [
        'id' => $calendar->id,
        'academic_year' => '2027-2028',
        'is_current' => 1,
    ]);

    $this->assertDatabaseHas('academic_calendar_versions', [
        'academic_calendar_id' => $calendar->id,
        'version_code' => 'v1.0',
        'is_current' => 1,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'academic_calendar.created',
        'auditable_id' => $calendar->id,
    ]);
});

test('uploading revision preserves previous version year-wise and updates latest pointer', function () {
    $calendar = AcademicCalendar::create([
        'calendar_code' => 'AC-TEST-2026',
        'academic_year' => '2026-2027',
        'title' => 'Testing Academic Calendar',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => 'published',
        'is_current' => true,
    ]);

    // Initial version 1.0
    $pdf1 = UploadedFile::fake()->create('calendar_v1.pdf', 300, 'application/pdf');
    $v1 = AcademicCalendarService::uploadRevision($calendar, $pdf1, [
        'version_code' => 'v1.0',
        'version_title' => 'Initial Publication',
        'change_summary' => 'Inaugural release.',
    ], $this->admin);

    expect($v1->is_current)->toBeTrue();
    expect($v1->revision_number)->toBe(1);
    expect($calendar->fresh()->current_version_id)->toBe($v1->id);

    // Upload revised version 1.1
    $pdf2 = UploadedFile::fake()->create('calendar_v1.1_revised.pdf', 350, 'application/pdf');
    $v2 = AcademicCalendarService::uploadRevision($calendar, $pdf2, [
        'version_code' => 'v1.1',
        'version_title' => 'Puja Vacation Shift & Exam Adjustment',
        'change_summary' => 'Rescheduled Durga Puja recess by one week.',
    ], $this->admin);

    // Verify version 2 is now current
    expect($v2->is_current)->toBeTrue();
    expect($v2->revision_number)->toBe(2);
    expect($calendar->fresh()->current_version_id)->toBe($v2->id);

    // Verify previous version 1 is STILL preserved in the database
    $v1Refreshed = $v1->fresh();
    expect($v1Refreshed)->not->toBeNull();
    expect($v1Refreshed->is_current)->toBeFalse();
    expect($v1Refreshed->version_code)->toBe('v1.0');

    // Total preserved versions is 2
    expect($calendar->versions()->count())->toBe(2);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'academic_calendar.version_uploaded',
        'auditable_id' => $v2->id,
    ]);
});

test('downloading version increments download counter and logs audit trail', function () {
    $calendar = AcademicCalendar::create([
        'calendar_code' => 'AC-DL-TEST',
        'academic_year' => '2026-2027',
        'title' => 'Download Test Calendar',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => 'published',
        'is_current' => true,
    ]);

    $file = UploadedFile::fake()->create('official_calendar.pdf', 200, 'application/pdf');
    $version = AcademicCalendarService::uploadRevision($calendar, $file, [
        'version_code' => 'v1.0',
        'version_title' => 'Official Released Calendar',
    ], $this->admin);

    expect($version->download_count)->toBe(0);

    // Download via controller
    $response = $this->actingAs($this->student)
        ->get(route('academic-calendar.download.version', $version->id));

    $response->assertOk();
    expect($version->fresh()->download_count)->toBe(1);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'academic_calendar.version_downloaded',
        'auditable_id' => $version->id,
    ]);
});

test('month-wise activity CRUD operations work end-to-end', function () {
    $calendar = AcademicCalendar::create([
        'calendar_code' => 'AC-CRUD-TEST',
        'academic_year' => '2026-2027',
        'title' => 'Activity CRUD Test Calendar',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => 'published',
        'is_current' => true,
    ]);

    // Create Activity
    $activity = AcademicCalendarService::createActivity($calendar, [
        'title' => 'UPSC Prelims Mock Series 1',
        'category' => 'examination',
        'start_date' => '2026-05-15',
        'end_date' => '2026-05-15',
        'target_audience' => 'students',
        'department' => 'Examination Cell',
        'venue_mode' => 'offline',
        'venue_or_link' => 'Halls 1 & 2',
        'status' => 'planned',
        'description' => 'Comprehensive GS Paper I test.',
    ], $this->admin);

    expect($activity)->toBeInstanceOf(AcademicCalendarActivity::class);
    expect($activity->month)->toBe(5);
    expect($activity->year)->toBe(2026);
    expect($activity->activity_code)->toStartWith('ACT-2026-');

    $this->assertDatabaseHas('academic_calendar_activities', [
        'id' => $activity->id,
        'title' => 'UPSC Prelims Mock Series 1',
        'category' => 'examination',
        'month' => 5,
    ]);

    // Update Activity
    AcademicCalendarService::updateActivity($activity, [
        'title' => 'UPSC Prelims Mock Series 1 (Revised Date)',
        'start_date' => '2026-05-20',
        'status' => 'ongoing',
    ], $this->admin);

    expect($activity->fresh()->title)->toBe('UPSC Prelims Mock Series 1 (Revised Date)');
    expect($activity->fresh()->status)->toBe('ongoing');

    // Delete Activity (Soft Delete)
    AcademicCalendarService::deleteActivity($activity, $this->admin);
    expect($activity->fresh()->trashed())->toBeTrue();
});

test('bulk importing activities from spreadsheet creates activities in correct calendar', function () {
    $calendar = AcademicCalendar::create([
        'calendar_code' => 'AC-IMP-TEST',
        'academic_year' => '2026-2027',
        'title' => 'Import Test Calendar',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => 'published',
        'is_current' => true,
    ]);

    // Prepare temporary CSV file
    $csvContent = "Title,Category,Start Date,End Date,Target Audience,Department,Venue Mode,Venue Details,Status,Description,Is Tentative\n";
    $csvContent .= "Foundation Course Commencement,academic,2026-04-05,2026-04-07,students,Academics,offline,Main Auditorium,planned,Induction ceremony,no\n";
    $csvContent .= "Summer Vacation Recess,holiday,2026-06-01,2026-06-15,all,Administration,offline,Campus Closed,planned,Mid-summer recess,no\n";

    $tempPath = tempnam(sys_get_temp_dir(), 'cal_import_').'.csv';
    file_put_contents($tempPath, $csvContent);

    $result = AcademicCalendarService::importActivities($calendar, $tempPath, $this->admin);

    unlink($tempPath);

    expect($result['imported'])->toBe(2);
    expect($calendar->activities()->count())->toBe(2);

    $this->assertDatabaseHas('academic_calendar_activities', [
        'academic_calendar_id' => $calendar->id,
        'title' => 'Foundation Course Commencement',
        'category' => 'academic',
        'month' => 4,
    ]);

    $this->assertDatabaseHas('academic_calendar_activities', [
        'academic_calendar_id' => $calendar->id,
        'title' => 'Summer Vacation Recess',
        'category' => 'holiday',
        'month' => 6,
    ]);
});

test('exporting activities to CSV, Excel, and PDF executes cleanly', function () {
    $calendar = AcademicCalendar::create([
        'calendar_code' => 'AC-EXP-TEST',
        'academic_year' => '2026-2027',
        'title' => 'Export Test Calendar',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => 'published',
        'is_current' => true,
    ]);

    AcademicCalendarService::createActivity($calendar, [
        'title' => 'Sample Activity 1',
        'category' => 'academic',
        'start_date' => '2026-07-10',
        'target_audience' => 'students',
        'department' => 'Academics',
        'venue_mode' => 'offline',
        'status' => 'planned',
    ], $this->admin);

    // CSV
    $csvResponse = AcademicCalendarService::exportActivities($calendar, [], 'csv');
    expect($csvResponse)->not->toBeNull();

    // Excel
    $xlsxResponse = AcademicCalendarService::exportActivities($calendar, [], 'xlsx');
    expect($xlsxResponse)->not->toBeNull();

    // PDF Booklet
    $pdfResponse = AcademicCalendarService::exportActivities($calendar, [], 'pdf');
    expect($pdfResponse)->not->toBeNull();
});

test('soft-deleting academic calendar cascades soft-delete to activities and versions', function () {
    $calendar = AcademicCalendar::create([
        'calendar_code' => 'AC-CASCADE-TEST',
        'academic_year' => '2026-2027',
        'title' => 'Cascade Test Calendar',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => 'published',
        'is_current' => false,
    ]);

    $file = UploadedFile::fake()->create('test.pdf', 100);
    $version = AcademicCalendarService::uploadRevision($calendar, $file, [
        'version_code' => 'v1.0',
        'version_title' => 'Version 1',
    ], $this->admin);

    $activity = AcademicCalendarService::createActivity($calendar, [
        'title' => 'Sample Activity',
        'category' => 'academic',
        'start_date' => '2026-08-01',
        'target_audience' => 'students',
        'venue_mode' => 'offline',
        'status' => 'planned',
    ], $this->admin);

    AcademicCalendarService::deleteCalendar($calendar, $this->admin);

    expect($calendar->fresh()->trashed())->toBeTrue();
    expect($version->fresh()->trashed())->toBeTrue();
    expect($activity->fresh()->trashed())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'academic_calendar.deleted',
        'auditable_id' => $calendar->id,
    ]);
});

test('livewire admin academic calendar component can create and switch sessions', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.academic-calendar')
        ->assertStatus(200)
        ->set('calendarForm.academic_year', '2028-2029')
        ->set('calendarForm.title', 'Academic Calendar 2028-2029')
        ->set('calendarForm.start_date', '2028-04-01')
        ->set('calendarForm.end_date', '2029-03-31')
        ->set('calendarForm.status', 'published')
        ->call('saveCalendar')
        ->assertDispatched('modal-close', name: 'calendar-form-modal');

    $this->assertDatabaseHas('academic_calendars', [
        'academic_year' => '2028-2029',
        'title' => 'Academic Calendar 2028-2029',
    ]);
});

test('sidebar displays academic calendar for student with access and hides for student without access', function () {
    // 1. Student with default permission has Academic Calendar in sidebar
    $responseWithAccess = $this->actingAs($this->student)
        ->get(route('dashboard', ['current_team' => $this->student->currentTeam->slug]));

    $responseWithAccess->assertOk()
        ->assertSee(route('student.academic-calendar.index'))
        ->assertDontSee(route('admin.academic-calendar.index'));

    // 2. Revoke all academic calendar permissions from student role
    $studentRole = Role::findByName('Student');
    $studentRole->revokePermissionTo('academic_calendar.view');
    $studentRole->revokePermissionTo('academic_calendar.download');

    $responseWithoutAccess = $this->actingAs($this->student->fresh())
        ->get(route('dashboard', ['current_team' => $this->student->currentTeam->slug]));

    $responseWithoutAccess->assertOk()
        ->assertDontSee(route('student.academic-calendar.index'))
        ->assertDontSee(route('admin.academic-calendar.index'));
});

test('sidebar displays academic calendar for staff with access and hides for staff without access', function () {
    $staff = User::factory()->create([
        'name' => 'General Staff Member',
        'email' => 'general.staff@sntcssc.gov.in',
    ]);
    $staff->ensureCurrentTeam();

    // 1. Staff initially has no academic calendar permissions -> hidden
    $responseWithoutAccess = $this->actingAs($staff)
        ->get(route('dashboard', ['current_team' => $staff->currentTeam->slug]));

    $responseWithoutAccess->assertOk()
        ->assertDontSee(route('admin.academic-calendar.index'))
        ->assertDontSee(route('student.academic-calendar.index'));

    // 2. Grant academic_calendar.view permission -> visible
    $staff->givePermissionTo('academic_calendar.view');

    $responseWithAccess = $this->actingAs($staff->fresh())
        ->get(route('dashboard', ['current_team' => $staff->currentTeam->slug]));

    $responseWithAccess->assertOk()
        ->assertSee(route('admin.academic-calendar.index'))
        ->assertDontSee(route('student.academic-calendar.index'));

    // 3. Grant a non-view permission (e.g. academic_calendar.edit) without direct view -> still visible via Gate logic
    $staff->revokePermissionTo('academic_calendar.view');
    $staff->givePermissionTo('academic_calendar.edit');

    $responseWithEditAccess = $this->actingAs($staff->fresh())
        ->get(route('dashboard', ['current_team' => $staff->currentTeam->slug]));

    $responseWithEditAccess->assertOk()
        ->assertSee(route('admin.academic-calendar.index'));

    // 4. Revoke all academic calendar permissions -> hidden again
    $staff->revokePermissionTo('academic_calendar.edit');

    $responseRevoked = $this->actingAs($staff->fresh())
        ->get(route('dashboard', ['current_team' => $staff->currentTeam->slug]));

    $responseRevoked->assertOk()
        ->assertDontSee(route('admin.academic-calendar.index'));
});
