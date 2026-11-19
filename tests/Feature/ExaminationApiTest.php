<?php

use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExaminationCenter;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\ExaminationSeeder;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(ExaminationSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Exam System Admin']);
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);

    // Full examination permissions key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Examination Authority Integration Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:examinations', 'write:examinations', 'download:admit_cards', 'ping'],
        'description' => 'Master key with full examination scopes',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Read-only key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Examination Readonly Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['read:examinations'],
        'description' => 'Read-only exam key',
    ], $this->admin);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];
});

test('examination dashboard returns metrics and upcoming examinations', function () {
    // 1. Without API Key returns 401
    $this->getJson('/api/v1/examinations/dashboard')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'API_KEY_MISSING');

    // 2. With valid key returns 200 and metrics
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/examinations/dashboard');

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure([
            'success',
            'data' => [
                'metrics' => [
                    'total_exams',
                    'upcoming_exams',
                    'total_centers',
                    'total_center_capacity',
                    'total_candidates',
                    'present_candidates',
                    'absent_candidates',
                    'attendance_rate',
                ],
                'recent_exams',
            ],
        ]);
});

test('can list examinations with pagination and search filter', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/examinations?search=CSCEE');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'uuid',
                    'exam_code',
                    'exam_name',
                    'academic_year',
                    'conducting_body',
                    'status',
                    'exam_date',
                ],
            ],
            'meta',
        ]);
});

test('can create a new examination with write scope', function () {
    $payload = [
        'exam_code' => 'TEST-EXAM-2027',
        'exam_name' => 'State Civil Services Prelims Test Series 2027',
        'academic_year' => '2026-2027',
        'conducting_body' => 'Academic Evaluation Board',
        'notification_date' => '2026-11-01',
        'apply_start' => '2026-11-05',
        'apply_end' => '2026-11-25',
        'exam_date' => '2026-12-20',
        'exam_time' => '10:00 AM - 12:00 PM',
        'reporting_time' => '09:00 AM',
        'duration_minutes' => 120,
        'fee_general' => 100.00,
        'fee_reserved' => 50.00,
        'total_vacancies' => 100,
        'status' => 'published',
        'instructions' => 'Bring original photo identification and admit card.',
        'admit_card_instructions' => 'Report 60 minutes before start.',
    ];

    // Read-only key is rejected with 403
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/examinations', $payload)
        ->assertStatus(403);

    // Full key creates exam successfully
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/examinations', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exam_code', 'TEST-EXAM-2027')
        ->assertJsonPath('data.exam_name', 'State Civil Services Prelims Test Series 2027');

    $this->assertDatabaseHas('exams', [
        'exam_code' => 'TEST-EXAM-2027',
    ]);
});

test('can list and create examination centers and rooms', function () {
    // 1. List centers
    $listRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/examinations/centers');

    $listRes->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'code',
                    'name',
                    'address',
                    'city',
                    'total_capacity',
                ],
            ],
        ]);

    // 2. Create center
    $centerPayload = [
        'code' => 'HOW-CT-04',
        'name' => 'Howrah District Examination Centre',
        'address' => 'Station Road, Howrah',
        'city' => 'Howrah',
        'district' => 'Howrah',
        'total_capacity' => 80,
    ];

    $centerRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/examinations/centers', $centerPayload);

    $centerRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', 'HOW-CT-04');

    $centerId = $centerRes->json('data.id');

    // 3. Add room to center
    $roomRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/examinations/centers/{$centerId}/rooms", [
            'room_number' => 'Hall-101',
            'floor' => '1st Floor',
            'capacity' => 40,
        ]);

    $roomRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.room_number', 'Hall-101');
});

test('seating plan returns real-time center and room allocation matrix', function () {
    $exam = Exam::first();

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/examinations/{$exam->id}/seating-plan");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'exam' => ['id', 'exam_code', 'exam_name'],
                'summary' => [
                    'total_centers',
                    'total_capacity',
                    'total_assigned_seats',
                    'total_vacant_seats',
                ],
                'centers' => [
                    '*' => [
                        'id',
                        'name',
                        'code',
                        'total_capacity',
                        'assigned_candidates',
                        'vacant_seats',
                        'rooms',
                    ],
                ],
            ],
        ]);
});

test('can bulk allocate candidate seats and query candidate directory', function () {
    $exam = Exam::first();
    $center = ExaminationCenter::first();
    $room = $center->rooms()->first();

    $allocationPayload = [
        'center_id' => $center->id,
        'room_id' => $room->id,
        'candidates' => [
            [
                'candidate_name' => 'Debasmita Roy',
                'candidate_email' => 'debasmita.roy@example.com',
                'candidate_phone' => '+91 98300 55443',
                'student_id' => 205,
            ],
            [
                'candidate_name' => 'Tanmoy Paul',
                'candidate_email' => 'tanmoy.paul@example.com',
                'candidate_phone' => '+91 98300 99881',
                'student_id' => 206,
            ],
        ],
    ];

    $allocRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/examinations/{$exam->id}/allocate-seats", $allocationPayload);

    $allocRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.allocated_count', 2);

    // Query candidates
    $candRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/examinations/{$exam->id}/candidates?search=Debasmita");

    $candRes->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.candidate_name', 'Debasmita Roy');
});

test('invigilator can mark candidate attendance in real-time', function () {
    $candidate = ExamCandidate::first();
    $exam = $candidate->exam;

    $attendancePayload = [
        'roll_number' => $candidate->roll_number,
        'attendance_status' => 'present',
        'remarks' => 'Physical identity verified via Biometric & Voter ID.',
    ];

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/examinations/{$exam->id}/attendance", $attendancePayload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.attendance_status', 'present')
        ->assertJsonPath('data.roll_number', $candidate->roll_number);

    $this->assertDatabaseHas('exam_candidates', [
        'id' => $candidate->id,
        'attendance_status' => 'present',
    ]);
});

test('can record examination marks and merit rank evaluation', function () {
    $candidate = ExamCandidate::first();
    $exam = $candidate->exam;

    $resultsPayload = [
        'results' => [
            [
                'roll_number' => $candidate->roll_number,
                'marks_obtained' => 155.75,
                'rank' => 1,
                'is_qualified' => true,
                'remarks' => 'Rank 1 - Outstanding score in GS and CSAT',
            ],
        ],
    ];

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/examinations/{$exam->id}/results", $resultsPayload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.updated_count', 1);

    $this->assertDatabaseHas('exam_candidates', [
        'id' => $candidate->id,
        'marks_obtained' => 155.75,
        'rank' => 1,
        'is_qualified' => true,
    ]);
});

test('can retrieve digital admit card dataset with verification QR payload', function () {
    $candidate = ExamCandidate::first();
    $exam = $candidate->exam;

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/examinations/{$exam->id}/admit-card?roll_number={$candidate->roll_number}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.candidate.roll_number', $candidate->roll_number)
        ->assertJsonStructure([
            'success',
            'data' => [
                'institute' => ['name', 'authority', 'address'],
                'candidate' => [
                    'name',
                    'roll_number',
                    'seat_number',
                    'admit_token',
                    'verification_qr_payload',
                ],
                'examination' => [
                    'exam_code',
                    'exam_name',
                    'exam_date',
                    'exam_time',
                    'reporting_time',
                ],
                'venue' => [
                    'center_name',
                    'center_code',
                    'address',
                    'room_number',
                ],
                'instructions',
            ],
        ]);
});

test('can verify admit card QR token or roll number for invigilator devices', function () {
    $candidate = ExamCandidate::first();

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/examinations/verify-admit-card', [
            'token' => $candidate->admit_card_token,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_valid', true)
        ->assertJsonPath('data.candidate.roll_number', $candidate->roll_number);
});

test('can delete examination room from center', function () {
    $center = ExaminationCenter::first();
    $room = $center->rooms()->create([
        'room_number' => 'Empty-Room-Z',
        'capacity' => 20,
    ]);

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/examinations/centers/{$center->id}/rooms/{$room->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('examination_center_rooms', ['id' => $room->id]);
});

test('unauthorized public cannot download admit card before release date', function () {
    $exam = Exam::create([
        'exam_code' => 'FUTURE-EXAM-2027',
        'exam_name' => 'Future Exam with Pending Admit Cards',
        'admit_card_release_date' => now()->addDays(7),
        'status' => 'published',
    ]);

    $candidate = ExamCandidate::create([
        'exam_id' => $exam->id,
        'roll_number' => 'FUT-001',
        'candidate_name' => 'John Aspirant',
        'admit_card_token' => 'future_token_123',
    ]);

    // Public / student request without read:examinations scope gets 403
    $response = $this->getJson("/api/v1/examinations/{$exam->id}/admit-card?roll_number={$candidate->roll_number}");

    $response->assertStatus(403)
        ->assertJsonPath('success', false);
});
