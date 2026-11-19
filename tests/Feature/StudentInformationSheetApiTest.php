<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentAddress;
use App\Models\StudentEducation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create([
        'name' => 'System Administrator',
        'email' => 'admin.sheet@example.com',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->course = Course::first() ?? Course::factory()->create([
        'name' => 'UPSC CSE Civil Services Exam',
        'code' => 'UPSC-CSE',
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

    // Candidate 1
    $this->student1 = User::factory()->create([
        'name' => 'Ananya Chatterjee',
        'email' => 'ananya.c@example.com',
        'phone' => '9830112233',
        'student_id' => 'STU-2026-0001',
        'urn' => 'URN-2026-0001',
        'gender' => 'Female',
        'status' => 'active',
    ]);
    $this->student1->assignRole('Student');

    StudentProfile::create([
        'user_id' => $this->student1->id,
        'father_name' => 'Subir Chatterjee',
        'mother_name' => 'Kakali Chatterjee',
        'category' => 'UR',
        'dob' => '2000-05-15',
        'blood_group' => 'O+',
        'annual_family_income' => '750000',
    ]);

    StudentAddress::create([
        'user_id' => $this->student1->id,
        'address_type' => 'Permanent',
        'address_line1' => 'Flat 3A, Salt Lake City',
        'district' => 'Kolkata',
        'state' => 'West Bengal',
        'pin_code' => '700064',
    ]);

    StudentEducation::create([
        'user_id' => $this->student1->id,
        'level' => 'Graduation',
        'stream_subject' => 'Political Science',
        'board_university' => 'Jadavpur University',
        'passing_year' => 2022,
        'percentage' => '84.50',
    ]);

    Enrollment::create([
        'user_id' => $this->student1->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
        'roll_no' => 'ROLL-001',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    // Candidate 2
    $this->student2 = User::factory()->create([
        'name' => 'Bikash Mondal',
        'email' => 'bikash.m@example.com',
        'phone' => '9830223344',
        'student_id' => 'STU-2026-0002',
        'urn' => 'URN-2026-0002',
        'gender' => 'Male',
        'status' => 'active',
    ]);
    $this->student2->assignRole('Student');

    StudentProfile::create([
        'user_id' => $this->student2->id,
        'father_name' => 'Ramesh Mondal',
        'mother_name' => 'Aparna Mondal',
        'category' => 'SC',
        'dob' => '1998-11-20',
        'blood_group' => 'B+',
    ]);

    Enrollment::create([
        'user_id' => $this->student2->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
        'roll_no' => 'ROLL-002',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $this->apiKeyService = app(ApiKeyService::class);

    // Full administrative API key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Information Sheet Full Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['ping', 'read:students', 'download:students', 'write:students'],
    ], $this->admin);
    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Read-only key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Information Sheet Read Only Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 100,
        'scopes' => ['ping', 'read:students'],
    ], $this->admin);
    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];

    // Key lacking student scopes
    $noScopeKeyData = $this->apiKeyService->generateKey([
        'name' => 'Academics Only Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'scopes' => ['ping', 'read:academics'],
    ], $this->admin);
    $this->noScopePlainKey = $noScopeKeyData['plainTextKey'];
});

test('information sheet api enforces authentication and scopes', function () {
    // 1. Missing API Key returns 401
    $this->getJson('/api/v1/students/information-sheets')
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'API_KEY_MISSING');

    // 2. Insufficient scope returns 403
    $this->withHeaders(['X-API-Key' => $this->noScopePlainKey])
        ->getJson('/api/v1/students/information-sheets')
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');

    // 3. Valid key with read:students scope succeeds
    $this->withHeaders(['X-API-Key' => $this->readOnlyPlainKey])
        ->getJson('/api/v1/students/information-sheets')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('information sheet index returns paginated candidate summaries with links', function () {
    $response = $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->getJson('/api/v1/students/information-sheets?per_page=10')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'uuid',
                    'student_id',
                    'urn',
                    'name',
                    'email',
                    'phone',
                    'gender',
                    'category',
                    'status',
                    'batch_name',
                    'course_name',
                    'documents_count',
                    'endpoints' => ['sheet', 'download_pdf'],
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
                'metadata' => ['institution_name', 'generated_at'],
            ],
        ]);

    expect($response->json('data'))->toHaveCount(2);

    // Search filter
    $searchResponse = $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->getJson('/api/v1/students/information-sheets?search=Ananya')
        ->assertStatus(200);

    expect($searchResponse->json('data'))->toHaveCount(1);
    expect($searchResponse->json('data.0.name'))->toBe('Ananya Chatterjee');
});

test('information sheet show returns comprehensive dossier by multiple identifiers and logs audit', function () {
    // 1. Query by numeric ID
    $responseById = $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->getJson("/api/v1/students/{$this->student1->id}/information-sheet")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Ananya Chatterjee')
        ->assertJsonPath('data.student_id', 'STU-2026-0001')
        ->assertJsonPath('data.profile.father_name', 'Subir Chatterjee')
        ->assertJsonPath('data.permanent_address.district', 'Kolkata')
        ->assertJsonPath('data.educations.0.stream_subject', 'Political Science')
        ->assertJsonPath('data.primary_roll', 'ROLL-001');

    // 2. Query by Student ID (e.g. STU-2026-0001)
    $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->getJson('/api/v1/students/STU-2026-0001/information-sheet')
        ->assertStatus(200)
        ->assertJsonPath('data.id', $this->student1->id);

    // 3. Query by URN (e.g. URN-2026-0001)
    $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->getJson('/api/v1/students/URN-2026-0001/information-sheet')
        ->assertStatus(200)
        ->assertJsonPath('data.id', $this->student1->id);

    // 4. Non-existent returns 404
    $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->getJson('/api/v1/students/NON_EXISTENT_ID/information-sheet')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'STUDENT_NOT_FOUND');

    // 5. Audit log is recorded
    $log = AuditLog::where('event', 'student_information_sheet_viewed')
        ->where('auditable_id', $this->student1->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->description)->toContain('Ananya Chatterjee');
});

test('information sheet bulk endpoint compiles multiple candidates and validates input', function () {
    // 1. Missing ids returns 422
    $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->postJson('/api/v1/students/information-sheets/bulk', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // 2. Valid array of IDs compiles multiple sheets
    $response = $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->postJson('/api/v1/students/information-sheets/bulk', [
            'ids' => [$this->student1->id, $this->student2->id],
            'include_documents' => false,
        ])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.count', 2);

    expect($response->json('data'))->toHaveCount(2);

    // 3. Audit log is recorded
    $log = AuditLog::where('event', 'student_information_sheets_bulk_viewed')->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('2 student');
});

test('information sheet pdf download endpoint streams binary pdf or returns base64 format', function () {
    // 1. Download single student PDF with format=base64
    $base64Response = $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->getJson("/api/v1/students/{$this->student1->id}/information-sheet/download?format=base64")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'filename',
                'student_count',
                'file_size_bytes',
                'mime_type',
                'base64_pdf',
            ],
        ]);

    expect($base64Response->json('data.mime_type'))->toBe('application/pdf');
    expect($base64Response->json('data.base64_pdf'))->not->toBeEmpty();

    // 2. Direct binary download stream
    $binaryResponse = $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->get("/api/v1/students/{$this->student1->id}/information-sheet/download?format=binary")
        ->assertStatus(200);

    expect($binaryResponse->headers->get('content-type'))->toBe('application/pdf');

    // 3. Bulk PDF download via POST
    $bulkResponse = $this->withHeaders(['X-API-Key' => $this->plainKey])
        ->post('/api/v1/students/information-sheets/download', [
            'ids' => [$this->student1->id, $this->student2->id],
            'format' => 'binary',
        ])
        ->assertStatus(200);

    expect($bulkResponse->headers->get('content-type'))->toBe('application/pdf');

    // 4. Audit log recorded
    $downloadLog = AuditLog::where('event', 'student_information_sheet_downloaded')->latest('id')->first();
    expect($downloadLog)->not->toBeNull();
});

test('student self-service endpoint allows candidate to inspect and download own sheet via sanctum', function () {
    // 1. Unauthenticated request to student self-service returns 401
    $this->getJson('/api/v1/student/information-sheet')
        ->assertStatus(401);

    // 2. Sanctum authenticated candidate accesses own sheet
    Sanctum::actingAs($this->student1);

    $response = $this->getJson('/api/v1/student/information-sheet')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Ananya Chatterjee')
        ->assertJsonPath('data.student_id', 'STU-2026-0001');

    // 3. Sanctum candidate downloads own PDF in base64 format
    $this->getJson('/api/v1/student/information-sheet/download?format=base64')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.student_count', 1);

    // 4. Candidate download log recorded
    $selfLog = AuditLog::where('event', 'student_self_information_sheet_viewed')
        ->where('user_id', $this->student1->id)
        ->latest('id')
        ->first();

    expect($selfLog)->not->toBeNull();
});
