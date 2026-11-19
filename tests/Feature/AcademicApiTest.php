<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Academics Test Admin']);
    $this->admin->assignRole('Super Administrator');

    $this->course = Course::first() ?? Course::create([
        'name' => 'UPSC CSE Civil Services Exam',
        'code' => 'UPSC-CSE-TEST',
        'fee' => 15000,
        'status' => 'active',
    ]);

    $this->batch = Batch::first() ?? Batch::create([
        'name' => 'UPSC 2026 Foundation Batch',
        'code' => 'UPSC-2026-T1',
        'course_id' => $this->course->id,
        'status' => 'active',
        'academic_year' => '2026',
    ]);

    $this->section = Section::first() ?? Section::create([
        'name' => 'Section Alpha',
        'code' => 'SEC-ALPHA',
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'room_number' => 'Hall 101',
        'capacity' => 60,
    ]);

    $this->apiKeyService = app(ApiKeyService::class);

    // Full academic key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Academics Full Test Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['ping', 'read:academics', 'write:academics'],
        'description' => 'Test key for full academic operations',
    ], $this->admin);

    $this->plainKey = $fullKeyData['plainTextKey'];

    // Readonly academic key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Academics Readonly Test Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['ping', 'read:academics'],
        'description' => 'Test key for readonly academic operations',
    ], $this->admin);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];

    // Key without academic scopes
    $noScopeKeyData = $this->apiKeyService->generateKey([
        'name' => 'Materials Only Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'scopes' => ['ping', 'read:materials'],
        'description' => 'Test key lacking academic scopes',
    ], $this->admin);

    $this->noScopePlainKey = $noScopeKeyData['plainTextKey'];
});

test('academic endpoints enforce authentication and scopes', function () {
    // 1. Missing API key returns 401
    $this->getJson('/api/v1/academics/dashboard')
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'API_KEY_MISSING');

    // 2. Key lacking read:academics returns 403
    $this->withHeader('X-API-Key', $this->noScopePlainKey)
        ->getJson('/api/v1/academics/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');

    // 3. Key lacking write:academics returns 403 on write operation
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/academics/courses', [
            'name' => 'WBCS Foundation 2027',
            'code' => 'WBCS-2027-NEW',
        ])
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');
});

test('public discovery catalog is accessible without api key', function () {
    // Public catalog listing
    $response = $this->getJson('/api/v1/academics/catalog')
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    expect($response->json('data'))->toBeArray();

    // Public course details by slug/code/id
    $this->getJson('/api/v1/academics/catalog/'.$this->course->id)
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $this->course->id);

    // Catalog alias route
    $this->getJson('/api/v1/catalog/courses')
        ->assertSuccessful()
        ->assertJsonPath('success', true);
});

test('academic dashboard and statistics endpoints return valid telemetry', function () {
    // Dashboard metrics
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/academics/dashboard')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'overview' => [
                    'total_courses',
                    'active_courses',
                    'inactive_courses',
                    'archived_courses',
                    'total_batches',
                    'active_batches',
                    'upcoming_batches',
                    'completed_batches',
                    'total_sections',
                    'active_sections',
                    'total_active_students',
                    'total_capacity',
                    'occupancy_percentage',
                ],
                'categories',
                'academic_years',
                'recent_batches',
            ],
        ]);

    // Capacity statistics
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/academics/statistics')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'courses_breakdown',
                'batches_breakdown',
                'admissions_by_year',
                'sections_breakdown',
            ],
        ]);
});

test('course crud operations with audit logs and validation', function () {
    $code = 'TEST-CR-101';

    // 1. Create course
    $createResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/academics/courses', [
            'name' => 'WBCS Advanced Prelims & Mains',
            'code' => $code,
            'duration_weeks' => 40,
            'fee' => 12500,
            'status' => 'active',
            'description' => 'Comprehensive preparation for WBCS Executive',
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', $code);

    $courseId = $createResponse->json('data.id');

    // Verify audit log created
    expect(AuditLog::where('event', 'academic.course.created')->exists())->toBeTrue();

    // 2. Fetch course details
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/academics/courses/{$courseId}")
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $courseId);

    // 3. Update course
    $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/academics/courses/{$courseId}", [
            'name' => 'WBCS Advanced Prelims & Mains (Updated)',
            'fee' => 14000,
        ])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'WBCS Advanced Prelims & Mains (Updated)');

    // 4. Delete course without active enrollments
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/courses/{$courseId}")
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    // Verify course is soft deleted
    expect(Course::find($courseId))->toBeNull();
    expect(Course::withTrashed()->find($courseId))->not->toBeNull();
});

test('batch crud operations and course attachment', function () {
    $batchCode = 'BATCH-TEST-2027';

    // 1. Create batch
    $createResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/academics/batches', [
            'name' => '2027 Morning Regular Batch',
            'code' => $batchCode,
            'course_id' => $this->course->id,
            'academic_year' => '2027',
            'status' => 'upcoming',
            'max_students' => 100,
            'start_date' => '2027-01-15',
            'end_date' => '2027-12-15',
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', $batchCode);

    $batchId = $createResponse->json('data.id');

    // 2. Fetch batch dossier
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/academics/batches/{$batchId}")
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $batchId);

    // 3. Update batch
    $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/academics/batches/{$batchId}", [
            'name' => '2027 Morning Regular Batch (Renamed)',
            'status' => 'active',
        ])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', '2027 Morning Regular Batch (Renamed)');

    // 4. Attach another course to batch
    $anotherCourse = Course::create([
        'name' => 'General Studies Foundation',
        'code' => 'GS-FOUNDATION',
        'status' => 'active',
    ]);

    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/academics/batches/{$batchId}/courses", [
            'course_id' => $anotherCourse->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    // 5. List courses linked to batch
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/academics/batches/{$batchId}/courses")
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    // 6. Detach course
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/batches/{$batchId}/courses/{$anotherCourse->id}")
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    // 7. Delete batch
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/batches/{$batchId}")
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    expect(Batch::find($batchId))->toBeNull();
    expect(Batch::withTrashed()->find($batchId))->not->toBeNull();
});

test('section crud operations and vacancy management', function () {
    // 1. Create section
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/academics/sections', [
            'name' => 'Section Beta',
            'code' => 'SEC-BETA',
            'course_id' => $this->course->id,
            'batch_id' => $this->batch->id,
            'room_number' => 'Hall 204',
            'capacity' => 45,
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true);

    $sectionId = $response->json('data.id');

    // 2. Fetch section details
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/academics/sections/{$sectionId}")
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $sectionId);

    // 3. Update section
    $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/academics/sections/{$sectionId}", [
            'room_number' => 'Hall 205',
            'capacity' => 50,
        ])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.room_number', 'Hall 205');

    // 4. Delete section
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/sections/{$sectionId}")
        ->assertSuccessful()
        ->assertJsonPath('success', true);
});

test('active student enrollment dependency guard prevents deletion', function () {
    // Create an enrolled student in course, batch, section
    $studentUser = User::factory()->create([
        'name' => 'Subhasish Sen',
        'email' => 'subhasish.sen@example.com',
        'status' => 'active',
    ]);
    $studentUser->assignRole('Student');

    StudentProfile::create([
        'user_id' => $studentUser->id,
        'category' => 'UR',
    ]);

    Enrollment::create([
        'user_id' => $studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    // 1. Trying to delete course with active enrollment must return 422
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/courses/{$this->course->id}")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'ACTIVE_DEPENDENCY_PREVENTED');

    // 2. Trying to delete batch with active enrollment must return 422
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/batches/{$this->batch->id}")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'ACTIVE_DEPENDENCY_PREVENTED');

    // 3. Trying to delete section with active enrollment must return 422
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/sections/{$this->section->id}")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'ACTIVE_DEPENDENCY_PREVENTED');

    // 4. Detaching course from batch with active enrollment must return 422
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academics/batches/{$this->batch->id}/courses/{$this->course->id}")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'ACTIVE_DEPENDENCY_PREVENTED');
});

test('academic years and calendar schedules return valid structures', function () {
    // Academic years
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/academics/academic-years')
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    // Academic calendar
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/academics/calendar?academic_year=2026')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'holidays_and_events',
                'working_calendars',
                'batch_milestones',
            ],
        ]);
});

test('bulk import courses and batches endpoints validate and process data', function () {
    // 1. Bulk import courses
    $coursesData = [
        [
            'name' => 'UPSC Geography Optional Special',
            'code' => 'OPT-GEO-2027',
            'fee' => 8000,
            'duration_weeks' => 20,
            'status' => 'active',
        ],
        [
            'name' => 'UPSC History Optional Special',
            'code' => 'OPT-HIST-2027',
            'fee' => 8000,
            'duration_weeks' => 20,
            'status' => 'active',
        ],
    ];

    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/academics/courses/import', [
            'courses' => $coursesData,
        ])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.created', 2);

    expect(Course::where('code', 'OPT-GEO-2027')->exists())->toBeTrue();
    expect(Course::where('code', 'OPT-HIST-2027')->exists())->toBeTrue();

    // 2. Bulk import batches
    $batchesData = [
        [
            'name' => '2027 Weekend Specialized Batch A',
            'code' => 'BAT-WKND-27A',
            'course_id' => $this->course->id,
            'academic_year' => '2027',
            'status' => 'upcoming',
        ],
    ];

    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/academics/batches/import', [
            'batches' => $batchesData,
        ])
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.created', 1);

    expect(Batch::where('code', 'BAT-WKND-27A')->exists())->toBeTrue();
});
