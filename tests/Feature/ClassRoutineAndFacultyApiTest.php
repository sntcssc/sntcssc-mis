<?php

use App\Models\Batch;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Models\Section;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\ClassScheduleService;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    RbacService::seedDefaults();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);
    $keyData = $this->apiKeyService->generateKey([
        'name' => 'Routine and Faculty Full API Key',
        'type' => 'backend_service',
        'environment' => 'sandbox',
        'rate_limit_per_minute' => 300,
        'scopes' => ['*'],
        'description' => 'Test key with full academic permissions',
    ], $this->admin);

    $this->plainKey = $keyData['plainTextKey'];

    // Setup base academic entities
    $this->batch = Batch::create([
        'name' => 'Civil Services Morning 2026-27',
        'code' => 'CS-2026-M1',
        'academic_year' => '2026-2027',
        'status' => 'active',
        'max_students' => 100,
    ]);

    $this->course = Course::create([
        'name' => 'General Studies Paper I',
        'code' => 'GS-101',
        'category' => 'Classroom',
        'duration' => '9 Months',
        'fee' => 12000.00,
        'status' => 'active',
    ]);

    $this->section = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Section A',
        'code' => 'SEC-A',
        'capacity' => 50,
        'room_number' => 'Hall 1',
    ]);

    $this->institute = PartnerInstitute::create([
        'name' => 'Chanakya Civil Services Forum',
        'code' => 'INST-2026-001',
        'status' => 'active',
    ]);

    $this->faculty = Faculty::create([
        'name' => 'Dr. Amit Roy',
        'email' => 'amit.roy@sntcssc.org',
        'phone' => '+919830012345',
        'designation' => 'Professor',
        'department' => 'Ancient History',
        'faculty_type' => 'regular',
        'partner_institute_id' => $this->institute->id,
        'status' => 'active',
        'subjects' => ['Ancient History', 'Art & Culture'],
    ]);

    $this->classroom = Classroom::create([
        'name' => 'Smart Hall 1',
        'room_code' => 'CR-H1',
        'building' => 'Main Academic Block',
        'floor' => '1st Floor',
        'capacity' => 60,
        'room_type' => 'lecture_hall',
        'status' => 'active',
    ]);
});

test('faculty api lists faculties with department, institute, and status filtering', function () {
    Faculty::create([
        'name' => 'Prof. Sunita Sharma',
        'email' => 'sunita@sntcssc.org',
        'department' => 'Geography',
        'faculty_type' => 'guest',
        'status' => 'inactive',
    ]);

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/faculties?department=Ancient History');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.name', 'Dr. Amit Roy');

    $activeOnly = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/faculties?active_only=1');

    $activeOnly->assertOk()
        ->assertJsonPath('total', 1);
});

test('faculty api creates a new faculty member with auto code, audit log, and soft deletes', function () {
    $createResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/faculties', [
            'name' => 'Dr. Rajesh Mukherjee',
            'email' => 'rajesh.m@sntcssc.org',
            'phone' => '+919876543299',
            'designation' => 'Senior Lecturer',
            'department' => 'Polity',
            'faculty_type' => 'contractual',
            'partner_institute_id' => $this->institute->id,
            'specialization' => 'Constitutional Law',
            'qualification' => 'LL.M., Ph.D.',
            'subjects' => ['Indian Polity', 'Governance'],
            'status' => 'active',
        ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Dr. Rajesh Mukherjee');

    $newFacultyId = $createResponse->json('data.id');
    expect($newFacultyId)->not->toBeNull();

    // Verify dossier endpoint
    $showResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/faculties/{$newFacultyId}");

    $showResponse->assertOk()
        ->assertJsonPath('data.faculty.department', 'Polity')
        ->assertJsonPath('data.workload.total_assigned_classes', 0);

    // Update faculty
    $updateResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/faculties/{$newFacultyId}", [
            'designation' => 'Associate Professor',
            'specialization' => 'Comparative Public Law',
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.designation', 'Associate Professor')
        ->assertJsonPath('data.specialization', 'Comparative Public Law');

    // Soft delete
    $deleteResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/faculties/{$newFacultyId}");

    $deleteResponse->assertOk();
    expect(Faculty::find($newFacultyId))->toBeNull();
    expect(Faculty::withTrashed()->find($newFacultyId))->not->toBeNull();

    // Restore
    $restoreResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/faculties/{$newFacultyId}/restore");

    $restoreResponse->assertOk();
    expect(Faculty::find($newFacultyId))->not->toBeNull();
});

test('class schedule api marks session as completed, reschedules, and restores', function () {
    $schedule = ClassScheduleService::createSingle([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => now()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Ancient Indian History',
        'topics' => 'Indus Valley Civilization',
        'delivery_mode' => 'online',
        'platform' => 'Zoom',
        'meeting_link' => 'https://zoom.us/j/1234567890',
    ], [$this->faculty->id]);

    // Mark complete
    $completeResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/classes/{$schedule->id}/complete");

    $completeResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'completed');

    expect($schedule->fresh()->status)->toBe('completed');

    // Check faculty workload endpoint
    $workloadResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/faculties/{$this->faculty->id}/workload");

    $workloadResponse->assertOk()
        ->assertJsonPath('data.conducted_classes', 1)
        ->assertJsonPath('data.total_hours_taught', 2);

    // Soft delete class
    $deleteResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/classes/{$schedule->id}");

    $deleteResponse->assertOk();
    expect(ClassSchedule::find($schedule->id))->toBeNull();

    // Restore class
    $restoreResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/classes/{$schedule->id}/restore");

    $restoreResponse->assertOk()
        ->assertJsonPath('data.id', $schedule->id);
    expect(ClassSchedule::find($schedule->id))->not->toBeNull();
});

test('class schedule calendar and reminder alert endpoints work properly', function () {
    $schedule = ClassScheduleService::createSingle([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'class_type' => 'regular',
        'class_date' => now()->toDateString(),
        'start_time' => '14:00:00',
        'end_time' => '16:00:00',
        'subject' => 'Mauryan Administration',
        'delivery_mode' => 'offline',
        'classroom' => 'Smart Hall 1',
        'classroom_id' => $this->classroom->id,
    ], [$this->faculty->id]);

    // Calendar endpoint
    $calendarResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/classes/calendar?batch_id={$this->batch->id}");

    $calendarResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('total_classes', 1);

    // Reminder settings
    $settingsResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/classes/settings/reminders');

    $settingsResponse->assertOk()
        ->assertJsonPath('success', true);

    $updateSettings = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson('/api/v1/classes/settings/reminders', [
            'enabled' => true,
            'lead_time_minutes' => 45,
            'channels' => ['whatsapp', 'email'],
            'template' => 'Hi {student_name}, class starts at {class_time}.',
        ]);

    $updateSettings->assertOk()
        ->assertJsonPath('data.lead_time_minutes', 45);

    // Enroll a student with phone to receive the reminder
    $enrolledStudent = User::factory()->create([
        'phone' => '+919830099999',
        'status' => 'active',
    ]);
    Enrollment::create([
        'enrollment_no' => 'ENR-2026-REM01',
        'user_id' => $enrolledStudent->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'status' => 'active',
        'enrolled_at' => now()->toDateString(),
    ]);

    // Trigger on-demand manual reminder alert
    $reminderResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/classes/{$schedule->id}/send-reminder", [
            'channels' => ['whatsapp'],
            'custom_message' => 'Please bring reference material.',
        ]);

    $reminderResponse->assertOk()
        ->assertJsonPath('success', true);
});

test('student self-service api returns personal classes for enrolled cohort', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    // Enroll student in batch and course
    Enrollment::create([
        'enrollment_no' => 'ENR-2026-STU01',
        'user_id' => $student->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'status' => 'active',
        'enrolled_at' => now()->toDateString(),
    ]);

    // Create a class matching student cohort
    $class1 = ClassScheduleService::createSingle([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->section->id,
        'class_type' => 'regular',
        'class_date' => now()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
        'subject' => 'Modern History',
        'topics' => 'Revolt of 1857',
        'delivery_mode' => 'online',
        'platform' => 'Zoom',
        'meeting_link' => 'https://zoom.us/j/999888777',
        'meeting_password' => 'UPSC2026',
    ], [$this->faculty->id]);

    // Student calls /api/v1/student/classes
    $response = $this->actingAs($student, 'sanctum')
        ->getJson('/api/v1/student/classes?today=1');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.subject', 'Modern History');

    // Student lecture detail
    $detailResponse = $this->actingAs($student, 'sanctum')
        ->getJson("/api/v1/student/classes/{$class1->id}");

    $detailResponse->assertOk()
        ->assertJsonPath('data.subject', 'Modern History')
        ->assertJsonPath('data.meeting_link', 'https://zoom.us/j/999888777')
        ->assertJsonPath('data.meeting_password', 'UPSC2026');
});

test('faculty self-service api allows assigned faculty to manage schedule and recordings', function () {
    $facultyUser = User::factory()->create(['email' => 'amit.teacher@sntcssc.org']);
    $facultyUser->assignRole('Faculty');

    $this->faculty->update(['user_id' => $facultyUser->id]);

    $schedule = ClassScheduleService::createSingle([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'class_type' => 'regular',
        'class_date' => now()->toDateString(),
        'start_time' => '11:00:00',
        'end_time' => '13:00:00',
        'subject' => 'Delhi Sultanate',
        'delivery_mode' => 'online',
    ], [$this->faculty->id]);

    // Faculty queries their own schedule
    $scheduleResponse = $this->actingAs($facultyUser, 'sanctum')
        ->getJson('/api/v1/faculty/my-schedule');

    $scheduleResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.subject', 'Delhi Sultanate');

    // Faculty uploads recording
    $recordResponse = $this->actingAs($facultyUser, 'sanctum')
        ->postJson("/api/v1/faculty/classes/{$schedule->id}/recording", [
            'recording_url' => 'https://zoom.us/rec/share/sample-recording',
            'recording_password' => 'Secret2026',
            'recording_notes' => 'Complete analysis of Alauddin Khalji reforms',
        ]);

    $recordResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.recording_status', 'available');

    expect($schedule->fresh()->recording_url)->toBe('https://zoom.us/rec/share/sample-recording');

    // Faculty marks complete
    $completeResponse = $this->actingAs($facultyUser, 'sanctum')
        ->postJson("/api/v1/faculty/classes/{$schedule->id}/complete");

    $completeResponse->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

test('classroom and partner institute restore endpoints work as expected', function () {
    // Classroom soft delete and restore
    $this->classroom->delete();
    expect(Classroom::find($this->classroom->id))->toBeNull();

    $restoreRoom = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/classrooms/{$this->classroom->id}/restore");

    $restoreRoom->assertOk()
        ->assertJsonPath('success', true);
    expect(Classroom::find($this->classroom->id))->not->toBeNull();

    // Partner institute soft delete and restore
    $this->institute->delete();
    expect(PartnerInstitute::find($this->institute->id))->toBeNull();

    $restoreInst = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/partner-institutes/{$this->institute->id}/restore");

    $restoreInst->assertOk()
        ->assertJsonPath('success', true);
    expect(PartnerInstitute::find($this->institute->id))->not->toBeNull();
});
