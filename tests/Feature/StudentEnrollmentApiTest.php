<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create(['name' => 'System Administrator']);
    $this->admin->assignRole('Super Administrator');

    $this->studentUser = User::factory()->create([
        'name' => 'Arunava Mukherjee',
        'first_name' => 'Arunava',
        'last_name' => 'Mukherjee',
        'email' => 'arunava.mukherjee@example.com',
        'phone' => '9876543210',
        'gender' => 'Male',
        'student_id' => 'STU-2026-0001',
        'status' => 'active',
    ]);
    $this->studentUser->assignRole('Student');

    StudentProfile::create([
        'user_id' => $this->studentUser->id,
        'category' => 'UR',
        'father_name' => 'Dipankar Mukherjee',
        'mother_name' => 'Mousumi Mukherjee',
        'is_pwbd' => false,
    ]);

    $this->course = Course::first() ?? Course::factory()->create([
        'name' => 'UPSC CSE Civil Services Exam',
        'code' => 'UPSC-CSE',
        'fee' => 15000,
    ]);

    $this->batch = Batch::first() ?? Batch::factory()->create([
        'name' => 'UPSC 2026 Foundation Batch',
        'code' => 'UPSC-2026-F1',
        'course_id' => $this->course->id,
    ]);

    $this->section = Section::first() ?? Section::create([
        'name' => 'Section Alpha',
        'code' => 'SEC-A',
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'room_number' => 'Hall 101',
        'capacity' => 60,
    ]);

    $this->apiKeyService = app(ApiKeyService::class);

    // Full administrative key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Student Management Full Integration Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['ping', 'read:students', 'write:students', 'read:academics', 'write:academics', 'read:materials', 'write:materials'],
        'description' => 'Test key for full student and enrollment operations',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Read-only key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Student Read-Only Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 100,
        'scopes' => ['ping', 'read:students'],
        'description' => 'Test key for read-only student queries',
    ], $this->admin);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];

    // Student self-service key (owned by studentUser)
    $studentKeyData = $this->apiKeyService->generateKey([
        'name' => 'Student Self Service App Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['ping', 'read:students', 'write:students'],
        'description' => 'Test key for student self-service app',
    ], $this->studentUser);

    $this->studentPlainKey = $studentKeyData['plainTextKey'];

    // Key without student scopes
    $noScopeKeyData = $this->apiKeyService->generateKey([
        'name' => 'Academics Only Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'scopes' => ['ping', 'read:academics'],
        'description' => 'Test key lacking student scopes',
    ], $this->admin);

    $this->noScopePlainKey = $noScopeKeyData['plainTextKey'];
});

test('student api endpoints enforce authentication and scopes', function () {
    // 1. Missing API key returns 401
    $this->getJson('/api/v1/students')
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'API_KEY_MISSING');

    // 2. Key lacking read:students scope returns 403
    $this->withHeader('X-API-Key', $this->noScopePlainKey)
        ->getJson('/api/v1/students')
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');

    // 3. Readonly key attempting write returns 403
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/students', [
            'name' => 'Test Candidate',
            'email' => 'candidate.unauthorized@example.com',
            'phone' => '9988776655',
            'gender' => 'female',
        ])
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');

    // 4. Read key with read:students succeeds
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/students')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('executive student dashboard and statistics telemetry', function () {
    // Create an enrollment for testing stats
    Enrollment::create([
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
        'enrollment_no' => 'ENR-2026-TEST-01',
        'roll_no' => 'ROLL-01',
        'status' => 'active',
        'fee_status' => 'paid',
        'enrolled_at' => now(),
    ]);

    // Dashboard
    $dashRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/students/dashboard');

    $dashRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'overview' => [
                    'total_students',
                    'active_students',
                    'total_enrollments',
                    'active_enrollments',
                ],
                'category_distribution',
                'gender_distribution',
                'course_distribution',
                'recent_admissions',
            ],
        ]);

    // Statistics endpoint
    $statsRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/students/statistics');

    $statsRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'batches_occupancy',
                'admissions_by_year',
                'fee_breakdown',
            ],
        ]);
});

test('sub-second student identity verification kiosk lookup', function () {
    Enrollment::create([
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
        'enrollment_no' => 'ENR-2026-KIOSK-01',
        'status' => 'active',
        'fee_status' => 'paid',
        'enrolled_at' => now(),
    ]);

    // 1. Look up by student_id
    $lookupById = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/students/verify-identity', [
            'identifier' => 'STU-2026-0001',
        ]);

    $lookupById->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.student.email', 'arunava.mukherjee@example.com')
        ->assertJsonPath('data.flags.is_active', true)
        ->assertJsonPath('data.flags.has_active_enrollment', true)
        ->assertJsonPath('data.flags.fee_clear', true);

    // 2. Look up by email
    $lookupByEmail = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/students/verify-identity', [
            'identifier' => 'arunava.mukherjee@example.com',
        ]);

    $lookupByEmail->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.verified', true);

    // 3. Unknown identifier returns 404
    $lookupUnknown = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/students/verify-identity', [
            'identifier' => 'NON-EXISTENT-ID',
        ]);

    $lookupUnknown->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'STUDENT_NOT_FOUND');
});

test('administrative student registration with auto lifetime id', function () {
    $payload = [
        'name' => 'Debolina Chatterjee',
        'email' => 'debolina.chatterjee@example.com',
        'phone' => '9123456789',
        'gender' => 'female',
        'dob' => '2001-08-15',
        'category' => 'SC',
        'father_name' => 'Subhash Chatterjee',
        'mother_name' => 'Kalyani Chatterjee',
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
    ];

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/students', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Debolina Chatterjee')
        ->assertJsonPath('data.email', 'debolina.chatterjee@example.com');

    $studentId = $response->json('data.id');
    $this->assertDatabaseHas('users', [
        'id' => $studentId,
        'email' => 'debolina.chatterjee@example.com',
    ]);

    $this->assertDatabaseHas('student_profiles', [
        'user_id' => $studentId,
        'category' => 'SC',
        'father_name' => 'Subhash Chatterjee',
    ]);

    $this->assertDatabaseHas('enrollments', [
        'user_id' => $studentId,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
    ]);
});

test('retrieving student dossier with profile completeness, updating details, and soft deleting', function () {
    // 1. Fetch dossier
    $showRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/students/{$this->studentUser->id}");

    $showRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.student.id', $this->studentUser->id)
        ->assertJsonPath('data.student.email', 'arunava.mukherjee@example.com')
        ->assertJsonStructure([
            'success',
            'data' => [
                'student',
                'profile_dossier',
                'addresses',
                'educations',
                'employments',
                'upsc_details',
                'documents',
                'enrollments',
                'profile_completeness',
            ],
        ]);

    // 2. Update student details
    $updateRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/students/{$this->studentUser->id}", [
            'name' => 'Arunava Mukherjee (Updated)',
            'father_name' => 'Dipankar Mukherjee Ph.D.',
            'category' => 'OBCA',
        ]);

    $updateRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Arunava Mukherjee (Updated)');

    $this->assertDatabaseHas('users', [
        'id' => $this->studentUser->id,
        'name' => 'Arunava Mukherjee (Updated)',
    ]);

    $this->assertDatabaseHas('student_profiles', [
        'user_id' => $this->studentUser->id,
        'category' => 'OBCA',
    ]);

    // 3. Soft delete student
    $delRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/students/{$this->studentUser->id}");

    $delRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.deleted', true);

    $this->assertSoftDeleted('users', ['id' => $this->studentUser->id]);
});

test('managing student addresses, educational qualifications, and documents', function () {
    $identifier = $this->studentUser->id;

    // 1. Address Store & List
    $addrRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/students/{$identifier}/addresses", [
            'address_type' => 'present',
            'address_line1' => 'Flat 4B, Heritage Enclave',
            'district' => 'Kolkata',
            'state' => 'West Bengal',
            'pin_code' => '700029',
        ]);

    $addrRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.district', 'Kolkata');

    $addressId = $addrRes->json('data.id');

    $listAddrRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/students/{$identifier}/addresses");

    $listAddrRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');

    // 2. Education Store & List
    $eduRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/students/{$identifier}/educations", [
            'level' => 'Graduation',
            'board_university' => 'Jadavpur University',
            'stream_subject' => 'B.E. Computer Science & Engineering',
            'passing_year' => 2024,
            'percentage' => 84.50,
            'is_highest' => true,
        ]);

    $eduRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.board_university', 'Jadavpur University');

    $eduId = $eduRes->json('data.id');

    // 3. Document Upload, List & Verification
    $fakeDoc = UploadedFile::fake()->create('aadhaar_card.pdf', 500, 'application/pdf');

    $docRes = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->post("/api/v1/students/{$identifier}/documents", [
        'type' => 'aadhaar_card',
        'document_type' => 'aadhaar_card',
        'file' => $fakeDoc,
    ]);

    $docRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.type', 'aadhaar_card');

    $docId = $docRes->json('data.id');

    // Verify document
    $verifyDocRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/students/{$identifier}/documents/{$docId}/verify", [
            'action' => 'verify',
            'reason' => 'Aadhaar Card UID verified against original.',
        ]);

    $verifyDocRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.verified', true);

    $this->assertDatabaseHas('student_documents', [
        'id' => $docId,
        'verified' => true,
    ]);
});

test('academic structures: querying and provisioning batches, courses, and sections', function () {
    // 1. Batches
    $batchesRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/students/batches');

    $batchesRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'code']]]);

    // 2. Courses
    $coursesRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/students/courses');

    $coursesRes->assertOk()
        ->assertJsonPath('success', true);

    // 3. Sections
    $sectionsRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/students/sections');

    $sectionsRes->assertOk()
        ->assertJsonPath('success', true);

    // 4. Create Section
    $createSecRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/students/sections', [
            'course_id' => $this->course->id,
            'batch_id' => $this->batch->id,
            'name' => 'Section Beta',
            'code' => 'SEC-B',
            'room_number' => 'Hall 102',
            'capacity' => 50,
        ]);

    $createSecRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', 'SEC-B');

    $this->assertDatabaseHas('sections', [
        'code' => 'SEC-B',
        'name' => 'Section Beta',
    ]);
});

test('profile correction requests workflow: submission and atomic approval merge', function () {
    // 1. Submit request
    $submitRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/students/profile-requests', [
            'student_id' => (string) $this->studentUser->id,
            'section' => 'basic',
            'payload' => [
                'name' => 'Arunava Mukherjee (Corrected Spelled)',
                'father_name' => 'Dr. Dipankar Mukherjee',
            ],
            'reason' => 'Father title corrected in gazette.',
        ]);

    $submitRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending');

    $reqId = $submitRes->json('data.id');

    // 2. List requests
    $listRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/students/profile-requests?status=pending');

    $listRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');

    // 3. Approve request
    $reviewRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/students/profile-requests/{$reqId}/review", [
            'action' => 'approve',
            'admin_notes' => 'Verified with gazette notification copy.',
        ]);

    $reviewRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'approved');

    // Check atomic merge in database
    $this->assertDatabaseHas('users', [
        'id' => $this->studentUser->id,
        'name' => 'Arunava Mukherjee (Corrected Spelled)',
    ]);

    $this->assertDatabaseHas('student_profiles', [
        'user_id' => $this->studentUser->id,
        'father_name' => 'Dr. Dipankar Mukherjee',
    ]);
});

test('student self-service endpoints resolve authenticated student context', function () {
    // Create an enrollment
    Enrollment::create([
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrollment_no' => 'ENR-SELF-2026-01',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    // 1. My Profile using student's API key
    $myProfileRes = $this->withHeader('X-API-Key', $this->studentPlainKey)
        ->getJson('/api/v1/student/my-profile');

    $myProfileRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.student.id', $this->studentUser->id)
        ->assertJsonPath('data.student.email', 'arunava.mukherjee@example.com');

    // 2. My Enrollments
    $myEnrRes = $this->withHeader('X-API-Key', $this->studentPlainKey)
        ->getJson('/api/v1/student/my-enrollments');

    $myEnrRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');

    // 3. Submit correction request via self-service
    $selfReqRes = $this->withHeader('X-API-Key', $this->studentPlainKey)
        ->postJson('/api/v1/student/profile-correction-request', [
            'section' => 'basic',
            'payload' => [
                'father_name' => 'Dipankar Mukherjee Senior',
            ],
            'reason' => 'Updated father name record.',
        ]);

    $selfReqRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_id', $this->studentUser->id);
});

test('course enrollment lifecycle: admission restriction, transfer, completion, discontinuation, reinstatement', function () {
    // 1. Create enrollment
    $enrRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/enrollments', [
            'user_id' => $this->studentUser->id,
            'course_id' => $this->course->id,
            'batch_id' => $this->batch->id,
            'section_id' => $this->section->id,
            'fee_status' => 'paid',
        ]);

    $enrRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'active');

    $enrollmentId = $enrRes->json('data.id');
    $enrollmentNo = $enrRes->json('data.enrollment_no');

    expect($enrollmentNo)->not->toBeEmpty();

    // 2. Duplicate admission check: attempting to admit into exact same course & batch fails
    $dupRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/enrollments', [
            'user_id' => $this->studentUser->id,
            'course_id' => $this->course->id,
            'batch_id' => $this->batch->id,
        ]);

    $dupRes->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'ADMISSION_RESTRICTION');

    // 3. Create second section for transfer
    $sec2 = Section::create([
        'name' => 'Section Gamma',
        'code' => 'SEC-G',
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'room_number' => 'Hall 201',
        'capacity' => 40,
    ]);

    // Section Transfer
    $transferRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/enrollments/{$enrollmentId}/transfer-section", [
            'new_section_id' => $sec2->id,
            'reason' => 'Accommodating evening lecture timings.',
        ]);

    $transferRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transferred', true)
        ->assertJsonPath('data.new_section', 'Section Gamma');

    // 4. Complete enrollment
    $completeRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/enrollments/{$enrollmentId}/complete", [
            'remarks' => 'Course completed successfully with honors.',
        ]);

    $completeRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'completed');

    // 5. Discontinue enrollment
    $discRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/enrollments/{$enrollmentId}/discontinue", [
            'reason' => 'Candidate personal leave.',
        ]);

    $discRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'discontinued');

    // 6. Reinstate enrollment
    $reinstateRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/enrollments/{$enrollmentId}/reinstate", [
            'reinstatement_reason' => 'Approved resumption by director.',
        ]);

    $reinstateRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'active');

    // 7. Soft delete enrollment
    $delRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/enrollments/{$enrollmentId}");

    $delRes->assertOk()
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('enrollments', ['id' => $enrollmentId]);
});

test('developer console & docs page includes student management and enrollment tabs, presets, and guides', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('API Developer Portal & Console')
        ->assertSee('/api/v1/students/dashboard')
        ->assertSee('/api/v1/students/verify-identity')
        ->assertSee('/api/v1/enrollments')
        ->assertSee('Student Management & Enrollment System REST API Guide')
        ->assertSee('Dynamic Study Material System Integration Guide')
        ->set('activeScopeTab', 'students')
        ->assertSee('/api/v1/students/profile-requests')
        ->set('activeScopeTab', 'enrollments')
        ->assertSee('/api/v1/enrollments/{identifier}/transfer-section')
        ->call('openGuide', 'students')
        ->assertSet('showGuideModal', true)
        ->assertSee('Student Management & Enrollment System REST API — Multi-Platform Integration Guide')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false)
        ->call('openGuide', 'study_materials')
        ->assertSet('showGuideModal', true)
        ->assertSee('Dynamic Study Material Management System — Multi-Platform Integration Guide')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});

test('quick test modal in api keys index supports student management, enrollments, and study material endpoints', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.index')
        ->call('openTestModal', $this->fullApiKey->id)
        ->set('testEndpoint', 'students-dashboard')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200)
        ->set('testEndpoint', 'enrollments')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200)
        ->set('testEndpoint', 'study-materials-stats')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200);
});

test('students and enrollments index api endpoints execute successfully without avatar_url sql errors', function () {
    $resStudents = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/students');

    $resStudents->assertOk()
        ->assertJsonPath('success', true);

    $resEnrollments = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/enrollments');

    $resEnrollments->assertOk()
        ->assertJsonPath('success', true);
});
