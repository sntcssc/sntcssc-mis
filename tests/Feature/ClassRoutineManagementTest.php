<?php

use App\Models\Batch;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\Section;
use App\Models\User;
use App\Services\ClassReminderService;
use App\Services\ClassroomService;
use App\Services\ClassScheduleService;
use App\Services\RbacService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'Academic Admin',
        'email' => 'academic.admin@example.com',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->facultyUser = User::factory()->create([
        'name' => 'Prof. S. K. Mukherjee',
        'email' => 'sk.mukherjee@example.com',
    ]);
    $this->facultyUser->assignRole('Faculty');

    $this->studentUser = User::factory()->create([
        'name' => 'Priya Das',
        'email' => 'priya.das@example.com',
        'student_id' => 'STU-CLS-001',
    ]);
    $this->studentUser->assignRole('Student');

    $this->batch = Batch::create([
        'name' => '2026 GS Prelims Foundation',
        'code' => 'BATCH-2026-GS',
        'academic_year' => '2026',
        'status' => 'active',
    ]);

    $this->course = Course::create([
        'name' => 'General Studies Integrated Prelims-cum-Mains',
        'code' => 'GS-INT-2026',
        'fee' => 15000,
        'status' => 'active',
    ]);

    $this->batch->courses()->attach($this->course->id);

    $this->section = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Section A',
        'code' => 'SEC-A',
        'capacity' => 50,
        'status' => 'active',
    ]);

    $this->enrollment = Enrollment::create([
        'enrollment_no' => 'ENR-2026-9001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
        'status' => 'active',
        'fee_amount' => 15000,
        'fee_status' => 'paid',
        'enrolled_at' => now(),
    ]);

    $this->faculty = Faculty::create([
        'name' => 'Dr. B. R. Sharma',
        'email' => 'br.sharma@example.com',
        'designation' => 'Senior Professor',
        'department' => 'Polity & Governance',
        'specialization' => 'Indian Constitution',
        'subjects' => ['Indian Polity', 'Governance'],
        'status' => 'active',
    ]);
});

test('admin can view classes routine management page', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.classes')
        ->assertSee('Classes & Lecture Schedule Routine')
        ->assertSee('Total Classes');
});

test('faculty master can be created, updated and listed', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.faculties')
        ->set('form.name', 'Prof. Sunita Roy')
        ->set('form.designation', 'Associate Professor')
        ->set('form.department', 'Economics')
        ->set('form.specialization', 'Macroeconomics')
        ->set('form.subjects_raw', 'Indian Economy, Budget, Banking')
        ->set('form.status', 'active')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('faculties', [
        'name' => 'Prof. Sunita Roy',
        'department' => 'Economics',
    ]);
});

test('single class schedule can be created with automatic day computation and faculty assignment', function () {
    $this->actingAs($this->admin);

    $testDate = '2026-11-04'; // Wednesday
    $expectedDay = 'Wednesday';

    Livewire::test('pages::admin.classes')
        ->set('form.batch_id', (string) $this->batch->id)
        ->set('form.course_id', (string) $this->course->id)
        ->set('form.section_id', (string) $this->section->id)
        ->set('form.class_type', 'regular')
        ->set('form.class_date', $testDate)
        ->set('form.start_time', '10:00')
        ->set('form.end_time', '12:00')
        ->set('form.subject', 'Indian Polity')
        ->set('form.topics', 'Preamble and Basic Structure Doctrine')
        ->set('form.delivery_mode', 'offline')
        ->set('form.classroom', 'Hall 101')
        ->set('form.selected_faculty_ids', [(string) $this->faculty->id])
        ->set('form.status', 'scheduled')
        ->call('saveSingle')
        ->assertHasNoErrors();

    $schedule = ClassSchedule::where('subject', 'Indian Polity')->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->batch_id)->toBe($this->batch->id);
    expect($schedule->course_id)->toBe($this->course->id);
    expect($schedule->section_id)->toBe($this->section->id);
    expect($schedule->class_date->format('Y-m-d'))->toBe($testDate);
    expect($schedule->day_of_week)->toBe($expectedDay);
    expect($schedule->classroom)->toBe('Hall 101');
    expect($schedule->faculties->contains('id', $this->faculty->id))->toBeTrue();
});

test('batch recurrence generator creates multiple class instances across dates concurrently', function () {
    $this->actingAs($this->admin);

    $startDate = '2026-11-02'; // Monday
    $endDate = '2026-11-15';   // 2 weeks later

    $res = ClassScheduleService::generateRecurring([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Modern History',
        'topics' => '1857 Revolt to National Congress Formation',
        'delivery_mode' => 'online',
        'platform' => 'Zoom',
        'meeting_link' => 'https://zoom.us/j/987654321',
        'meeting_id' => '987 654 321',
        'meeting_password' => 'hist2026',
    ], [$this->faculty->id], [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'days_of_week' => ['Monday', 'Wednesday', 'Friday'],
        'start_lecture_no' => 1,
    ]);

    // In 2 weeks (Nov 2 to Nov 15):
    // Week 1: Nov 2 (Mon), Nov 4 (Wed), Nov 6 (Fri) = 3
    // Week 2: Nov 9 (Mon), Nov 11 (Wed), Nov 13 (Fri) = 3
    // Total = 6 instances
    expect($res['count'])->toBe(6);

    $schedules = ClassSchedule::where('subject', 'Modern History')->get();
    expect($schedules->count())->toBe(6);

    foreach ($schedules as $schedule) {
        expect($schedule->is_recurring)->toBeTrue();
        expect($schedule->recurrence_group_id)->not->toBeEmpty();
        expect(in_array($schedule->day_of_week, ['Monday', 'Wednesday', 'Friday'], true))->toBeTrue();
    }
});

test('quick faculty create saves teacher inline and returns assigned record', function () {
    $faculty = ClassScheduleService::quickCreateFaculty([
        'name' => 'Prof. Arvind Kejriwal',
        'email' => 'arvind@example.com',
        'department' => 'Public Administration',
        'designation' => 'Guest Lecturer',
    ]);

    expect($faculty->exists)->toBeTrue();
    expect($faculty->faculty_code)->toStartWith('FAC-');
    expect($faculty->name)->toBe('Prof. Arvind Kejriwal');
});

test('class can be rescheduled and cancelled with audit reasons', function () {
    $schedule = ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'class_type' => 'regular',
        'class_date' => '2026-11-10',
        'day_of_week' => 'Tuesday',
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Geography',
        'status' => 'scheduled',
    ]);

    $rescheduled = ClassScheduleService::reschedule($schedule, '2026-11-12', '14:00:00', '16:00:00', 'Faculty unavailable on Tuesday');
    expect($rescheduled->status)->toBe('rescheduled');
    expect($rescheduled->class_date->format('Y-m-d'))->toBe('2026-11-12');
    expect($rescheduled->day_of_week)->toBe('Thursday');

    $cancelled = ClassScheduleService::cancel($schedule, 'National holiday announced');
    expect($cancelled->status)->toBe('cancelled');
    expect($cancelled->cancellation_reason)->toBe('National holiday announced');
});

test('class recording can be added and accessed by students', function () {
    $schedule = ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => '2026-11-01',
        'day_of_week' => 'Sunday',
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Science & Tech',
        'status' => 'completed',
    ]);

    ClassScheduleService::updateRecording($schedule, [
        'recording_url' => 'https://us02web.zoom.us/rec/play/xyz123',
        'recording_password' => 'recPass123',
        'recording_notes' => 'Covers Space Tech and ISRO Missions',
    ]);

    $schedule->refresh();
    expect($schedule->recording_status)->toBe('available');
    expect($schedule->recording_url)->toBe('https://us02web.zoom.us/rec/play/xyz123');
    expect($schedule->recording_password)->toBe('recPass123');
    expect($schedule->hasRecording())->toBeTrue();
});

test('enrolled student sees only classes for their batches courses and sections', function () {
    // Class for student's batch, course and section
    $matchingClass = ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => now()->format('Y-m-d'),
        'day_of_week' => now()->format('l'),
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Student Enrolled Subject',
        'status' => 'scheduled',
    ]);

    // Another batch/course class where student is NOT enrolled
    $otherBatch = Batch::create([
        'name' => 'Other Batch 2027',
        'code' => 'BATCH-2027',
        'academic_year' => '2027',
        'status' => 'active',
    ]);

    $otherClass = ClassSchedule::create([
        'batch_id' => $otherBatch->id,
        'course_id' => $this->course->id,
        'class_type' => 'regular',
        'class_date' => now()->format('Y-m-d'),
        'day_of_week' => now()->format('l'),
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Other Batch Subject',
        'status' => 'scheduled',
    ]);

    $studentClasses = ClassSchedule::forStudent($this->studentUser)->get();

    expect($studentClasses->contains('id', $matchingClass->id))->toBeTrue();
    expect($studentClasses->contains('id', $otherClass->id))->toBeFalse();
});

test('student enrolled in multiple courses sees classes across all their active enrollments', function () {
    // Create second course and enroll student
    $course2 = Course::create([
        'name' => 'Ethics, Integrity & Aptitude GS IV',
        'code' => 'GS-IV-ETHICS',
        'fee' => 8000,
        'status' => 'active',
    ]);

    $enrollment2 = Enrollment::create([
        'enrollment_no' => 'ENR-2026-9002',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->id,
        'course_id' => $course2->id,
        'batch_id' => $this->batch->id,
        'status' => 'active',
        'fee_amount' => 8000,
        'fee_status' => 'paid',
        'enrolled_at' => now(),
    ]);

    $classCourse1 = ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => now()->format('Y-m-d'),
        'day_of_week' => now()->format('l'),
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'subject' => 'GS Course 1 Lecture',
        'status' => 'scheduled',
    ]);

    $classCourse2 = ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $course2->id,
        'class_type' => 'optional',
        'class_date' => now()->format('Y-m-d'),
        'day_of_week' => now()->format('l'),
        'start_time' => '14:00:00',
        'end_time' => '16:00:00',
        'subject' => 'Ethics Case Studies',
        'status' => 'scheduled',
    ]);

    $studentClasses = ClassSchedule::forStudent($this->studentUser)->get();

    expect($studentClasses->contains('id', $classCourse1->id))->toBeTrue();
    expect($studentClasses->contains('id', $classCourse2->id))->toBeTrue();
});

test('course plans can be created and published', function () {
    $plan = CoursePlan::create([
        'title' => 'Annual GS Roadmap 2026',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'plan_type' => 'annual',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_lectures_planned' => 120,
        'status' => 'published',
    ]);

    expect($plan->exists)->toBeTrue();
    expect($plan->plan_code)->toStartWith('CP-');
    expect($plan->published_at)->not->toBeNull();
});

test('api endpoints return schedules and allow scheduling', function () {
    $this->actingAs($this->admin);

    $response = $this->getJson('/api/v1/classes');
    $response->assertSuccessful();

    $postResponse = $this->postJson('/api/v1/classes', [
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'class_type' => 'regular',
        'class_date' => '2026-11-20',
        'start_time' => '11:00',
        'end_time' => '13:00',
        'subject' => 'Economics via API',
        'delivery_mode' => 'online',
        'platform' => 'Google Meet',
        'meeting_link' => 'https://meet.google.com/abc-defg-hij',
    ]);

    $postResponse->assertStatus(201);
    $this->assertDatabaseHas('class_schedules', [
        'subject' => 'Economics via API',
        'platform' => 'Google Meet',
    ]);
});

test('student routine livewire component renders and shows enrolled classes and tabs', function () {
    $this->actingAs($this->studentUser);

    ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => now()->format('Y-m-d'),
        'day_of_week' => now()->format('l'),
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Student Routine Polity Class',
        'status' => 'scheduled',
    ]);

    Livewire::test('pages::student.routine')
        ->assertSee('Classes & Timetable Routine')
        ->assertSee('Student Routine Polity Class')
        ->set('activeTab', 'recordings')
        ->assertSee('Class Recordings Vault')
        ->set('activeTab', 'plans')
        ->assertSee('Course Plans & Syllabi');
});

test('admin course plans livewire component renders and creates plan', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.course-plans')
        ->assertSee('Course Plans & Syllabus Schedules')
        ->set('form.title', 'Livewire Created GS Course Plan')
        ->set('form.batch_id', (string) $this->batch->id)
        ->set('form.course_id', (string) $this->course->id)
        ->set('form.plan_type', 'annual')
        ->set('form.start_date', '2026-01-01')
        ->set('form.end_date', '2026-12-31')
        ->set('form.total_lectures_planned', 90)
        ->set('form.version', '1.0')
        ->set('form.status', 'published')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('course_plans', [
        'title' => 'Livewire Created GS Course Plan',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'status' => 'published',
    ]);
});

test('class routine can be exported to csv and excel', function () {
    ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'class_type' => 'regular',
        'class_date' => now()->format('Y-m-d'),
        'day_of_week' => now()->format('l'),
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Exportable Class',
        'status' => 'scheduled',
    ]);

    $schedules = ClassSchedule::all();
    $csvResponse = ClassScheduleService::export($schedules, 'csv');
    expect($csvResponse)->toBeInstanceOf(StreamedResponse::class);

    $xlsxResponse = ClassScheduleService::export($schedules, 'xlsx');
    expect($xlsxResponse)->toBeInstanceOf(BinaryFileResponse::class);
});

test('classroom can be created, updated, filtered and assigned to schedule', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.classrooms')
        ->set('name', 'Aryabhatta Seminar Hall')
        ->set('room_code', 'AB-201')
        ->set('building', 'Chanakya Academic Block')
        ->set('floor', '2nd Floor')
        ->set('capacity', 75)
        ->set('room_type', 'lecture_hall')
        ->set('status', 'active')
        ->set('facilities', ['Smart Board', 'Audio System', 'Live Streaming Setup'])
        ->set('assigned_courses', [(string) $this->course->id])
        ->set('assigned_sections', [(string) $this->section->id])
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('classrooms', [
        'name' => 'Aryabhatta Seminar Hall',
        'room_code' => 'AB-201',
        'building' => 'Chanakya Academic Block',
        'capacity' => 75,
    ]);

    $classroom = Classroom::where('room_code', 'AB-201')->first();
    expect($classroom)->not->toBeNull();
    expect($classroom->display_name)->toContain('Aryabhatta Seminar Hall');
    expect($classroom->isAssignedToCourseOrSection($this->course->id, $this->section->id))->toBeTrue();

    // Assign to a schedule
    Livewire::test('pages::admin.classes')
        ->set('form.batch_id', (string) $this->batch->id)
        ->set('form.course_id', (string) $this->course->id)
        ->set('form.section_id', (string) $this->section->id)
        ->set('form.class_type', 'regular')
        ->set('form.class_date', '2026-11-05')
        ->set('form.start_time', '14:00')
        ->set('form.end_time', '16:00')
        ->set('form.subject', 'Governance Case Studies')
        ->set('form.delivery_mode', 'offline')
        ->set('form.classroom_id', (string) $classroom->id)
        ->call('saveSingle')
        ->assertHasNoErrors();

    $schedule = ClassSchedule::where('subject', 'Governance Case Studies')->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->classroom_id)->toBe($classroom->id);
    expect($schedule->classroom)->toBe('Aryabhatta Seminar Hall');
    expect($schedule->classroom_display)->toContain('Aryabhatta Seminar Hall');
});

test('classroom master supports excel csv and pdf exports', function () {
    $classroom = Classroom::create([
        'name' => 'Lecture Theater 1',
        'room_code' => 'LT-101',
        'building' => 'Main Wing',
        'floor' => 'Ground Floor',
        'capacity' => 120,
        'room_type' => 'lecture_theater',
        'status' => 'active',
        'facilities' => ['Projector', 'Air Conditioned'],
    ]);

    $classrooms = Classroom::where('id', $classroom->id)->get();

    $csv = ClassroomService::exportClassrooms($classrooms, 'csv');
    expect($csv)->toBeInstanceOf(BinaryFileResponse::class);

    $xlsx = ClassroomService::exportClassrooms($classrooms, 'xlsx');
    expect($xlsx)->toBeInstanceOf(BinaryFileResponse::class);

    $pdf = ClassroomService::exportClassrooms($classrooms, 'pdf');
    expect($pdf)->toBeInstanceOf(StreamedResponse::class);
});

test('faculty supports photo upload cv upload and custom department', function () {
    $this->actingAs($this->admin);
    Storage::fake('public');

    $photo = UploadedFile::fake()->image('prof_chatterjee.jpg', 300, 300);
    $cv = UploadedFile::fake()->create('prof_chatterjee_cv.pdf', 250, 'application/pdf');

    Livewire::test('pages::admin.faculties')
        ->set('form.name', 'Prof. Ananya Chatterjee')
        ->set('form.email', 'ananya.chatterjee@example.com')
        ->set('form.designation', 'Distinguished Visiting Fellow')
        ->set('department_select', 'Custom')
        ->set('custom_department', 'Public Governance & Constitutional Law')
        ->set('photo', $photo)
        ->set('cv', $cv)
        ->set('form.subjects_raw', 'Constitutional Law, Comparative Governance')
        ->set('form.status', 'active')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('faculties', [
        'name' => 'Prof. Ananya Chatterjee',
        'department' => 'Public Governance & Constitutional Law',
    ]);

    $faculty = Faculty::where('email', 'ananya.chatterjee@example.com')->first();
    expect($faculty)->not->toBeNull();
    expect($faculty->photo_path)->not->toBeNull();
    expect($faculty->cv_path)->not->toBeNull();
    expect($faculty->photo_url)->not->toBeNull();
    expect($faculty->cv_url)->not->toBeNull();
    expect($faculty->cv_file_name)->toEndWith('.pdf');
    Storage::disk('public')->assertExists($faculty->photo_path);
    Storage::disk('public')->assertExists($faculty->cv_path);
});

test('cancelled classes are isolated and excluded from completed schedule count and course plan progress', function () {
    $plan = CoursePlan::create([
        'title' => 'Ancient & Medieval History Comprehensive Plan',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'plan_type' => 'annual',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_lectures_planned' => 10,
        'status' => 'published',
    ]);

    // 2 Completed classes linked to this plan
    for ($i = 1; $i <= 2; $i++) {
        ClassSchedule::create([
            'batch_id' => $this->batch->id,
            'course_id' => $this->course->id,
            'course_plan_id' => $plan->id,
            'class_type' => 'regular',
            'class_date' => now()->subDays(5 - $i)->format('Y-m-d'),
            'day_of_week' => 'Monday',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'subject' => "Completed Lecture {$i}",
            'status' => 'completed',
        ]);
    }

    // 3 Upcoming Scheduled classes
    for ($i = 1; $i <= 3; $i++) {
        ClassSchedule::create([
            'batch_id' => $this->batch->id,
            'course_id' => $this->course->id,
            'course_plan_id' => $plan->id,
            'class_type' => 'regular',
            'class_date' => now()->addDays($i)->format('Y-m-d'),
            'day_of_week' => 'Tuesday',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'subject' => "Scheduled Lecture {$i}",
            'status' => 'scheduled',
        ]);
    }

    // 2 Cancelled classes (should NOT inflate completed or planned counts)
    for ($i = 1; $i <= 2; $i++) {
        ClassSchedule::create([
            'batch_id' => $this->batch->id,
            'course_id' => $this->course->id,
            'course_plan_id' => $plan->id,
            'class_type' => 'regular',
            'class_date' => now()->subDays($i)->format('Y-m-d'),
            'day_of_week' => 'Wednesday',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'subject' => "Cancelled Lecture {$i}",
            'status' => 'cancelled',
            'cancellation_reason' => 'Emergency administrative closure',
        ]);
    }

    $plan->refresh();

    // Check counts
    expect($plan->conducted_lectures_count)->toBe(2);
    expect($plan->scheduled_lectures_count)->toBe(3);
    expect($plan->cancelled_lectures_count)->toBe(2);
    expect($plan->effective_lectures_count)->toBe(5); // 2 conducted + 3 scheduled (cancelled excluded)
    expect($plan->remaining_lectures_to_schedule)->toBe(5); // 10 planned - 5 effective = 5
    expect($plan->progress_percentage)->toBe(20.0); // (2 conducted / 10 planned) * 100

    // Scopes
    expect(ClassSchedule::where('course_plan_id', $plan->id)->cancelled()->count())->toBe(2);
    expect(ClassSchedule::where('course_plan_id', $plan->id)->notCancelled()->count())->toBe(5);
});

test('course plan soft copy document can be uploaded and viewed by students', function () {
    $this->actingAs($this->admin);
    Storage::fake('public');

    $syllabusPdf = UploadedFile::fake()->create('gs_annual_curriculum_2026.pdf', 350, 'application/pdf');

    Livewire::test('pages::admin.course-plans')
        ->set('form.title', 'Integrated GS Prelims & Mains Syllabus Master Plan')
        ->set('form.batch_id', (string) $this->batch->id)
        ->set('form.course_id', (string) $this->course->id)
        ->set('form.plan_type', 'annual')
        ->set('form.start_date', '2026-01-01')
        ->set('form.end_date', '2026-12-31')
        ->set('form.total_lectures_planned', 100)
        ->set('documentFile', $syllabusPdf)
        ->set('form.status', 'published')
        ->call('save')
        ->assertHasNoErrors();

    $plan = CoursePlan::where('title', 'Integrated GS Prelims & Mains Syllabus Master Plan')->first();
    expect($plan)->not->toBeNull();
    expect($plan->document_path)->not->toBeNull();
    expect($plan->document_file_name)->toEndWith('.pdf');
    Storage::disk('public')->assertExists($plan->document_path);

    // Verify student can view in student routine portal
    $this->actingAs($this->studentUser);
    Livewire::test('pages::student.routine')
        ->set('activeTab', 'plans')
        ->assertSee('Integrated GS Prelims & Mains Syllabus Master Plan')
        ->assertSee('Download Soft Copy')
        ->assertSee($plan->document_file_name);
});

test('faculty workload and comprehensive routine reports can be generated and exported', function () {
    $this->actingAs($this->admin);

    $facultyWorkload = ClassScheduleService::getFacultyWorkloadData();
    expect($facultyWorkload)->toBeArray();

    $courseCoverage = ClassScheduleService::getCourseCoverageData();
    expect($courseCoverage)->toBeArray();

    $classroomUtilization = ClassScheduleService::getClassroomUtilizationData();
    expect($classroomUtilization)->toBeArray();

    // Test Report Exports in all formats
    $xlsxExport = ClassScheduleService::exportReport('faculty_workload', 'xlsx');
    expect($xlsxExport)->toBeInstanceOf(BinaryFileResponse::class);

    $csvExport = ClassScheduleService::exportReport('course_coverage', 'csv');
    expect($csvExport)->toBeInstanceOf(StreamedResponse::class);

    $pdfExport = ClassScheduleService::exportReport('classroom_utilization', 'pdf');
    expect($pdfExport)->toBeInstanceOf(StreamedResponse::class);
});

test('scheduled classes reminder notification alert can be configured dispatched automatically and manually', function () {
    $this->actingAs($this->admin);

    // Configure reminder settings
    ClassReminderService::updateSettings([
        'enabled' => true,
        'lead_time_minutes' => 60,
        'channels' => ['whatsapp', 'email', 'telegram', 'sms'],
    ]);

    $settings = ClassReminderService::getSettings();
    expect($settings['enabled'])->toBeTrue();
    expect($settings['lead_time_minutes'])->toBe(60);
    expect($settings['channels'])->toContain('whatsapp');

    // Create a class starting in 45 minutes (within the 60m window)
    $startTime = now()->addMinutes(45)->format('H:i:s');
    $endTime = now()->addMinutes(105)->format('H:i:s');

    $upcomingClass = ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => now()->format('Y-m-d'),
        'day_of_week' => now()->format('l'),
        'start_time' => $startTime,
        'end_time' => $endTime,
        'subject' => 'Imminent Ethics Lecture',
        'status' => 'scheduled',
    ]);

    // Dispatch automated reminders via Artisan Command
    $exitCode = Artisan::call('classes:send-reminders');
    expect($exitCode)->toBe(0);

    $upcomingClass->refresh();
    expect($upcomingClass->reminder_sent_at)->not->toBeNull();
    expect($upcomingClass->reminder_count)->toBeGreaterThanOrEqual(1);

    // Manual reminder trigger for a future class (e.g. 2 days ahead)
    $futureClass = ClassSchedule::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => now()->addDays(2)->format('Y-m-d'),
        'day_of_week' => now()->addDays(2)->format('l'),
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Advance Notice Polity Lecture',
        'status' => 'scheduled',
    ]);

    $manualResult = ClassReminderService::sendManualReminder(
        $futureClass,
        ['whatsapp', 'email'],
        'Reminder: Special session scheduled 2 days later. Please prepare your materials.'
    );

    expect($manualResult['success'])->toBeTrue();
    expect($manualResult['recipients_count'])->toBeGreaterThanOrEqual(1);

    $futureClass->refresh();
    expect($futureClass->reminder_sent_at)->not->toBeNull();
    expect($futureClass->reminder_count)->toBe(1);
    expect($futureClass->last_reminder_channel)->toContain('whatsapp');
    expect($futureClass->last_reminder_channel)->toContain('email');
});
