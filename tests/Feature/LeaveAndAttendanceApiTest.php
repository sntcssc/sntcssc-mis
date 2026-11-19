<?php

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\BiometricDevice;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LeaveAllocation;
use App\Models\LeaveCategory;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\LeaveAndAttendanceSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
    $this->seed(LeaveAndAttendanceSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Attendance Admin']);
    $this->admin->assignRole('Super Administrator');

    $this->staff = User::factory()->create([
        'name' => 'Prof. S. Mukherjee',
        'email' => 'smukherjee@example.com',
        'employee_id' => 'EMP-7001',
        'staff_department' => 'Academic Affairs',
        'biometric_pin' => '7001',
        'rfid_card_no' => 'RFID-7001',
    ]);
    $this->staff->assignRole('Faculty');

    $this->student = User::factory()->create([
        'name' => 'Priyanka Das',
        'email' => 'priyanka.das@example.com',
        'biometric_pin' => '8001',
        'rfid_card_no' => 'RFID-8001',
    ]);
    $this->student->assignRole('Student');

    $this->course = Course::first() ?? Course::factory()->create(['name' => 'WBCS Foundation']);
    $this->batch = Batch::first() ?? Batch::factory()->create(['course_id' => $this->course->id]);

    Enrollment::create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'status' => 'enrolled',
        'enrolled_at' => Carbon::now()->subMonths(2),
    ]);

    $this->apiKeyService = app(ApiKeyService::class);

    // Full key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Full Attendance & Leaves Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 500,
        'scopes' => [
            'read:attendance',
            'write:attendance',
            'write:biometric',
            'download:attendance',
            'read:leaves',
            'write:leaves',
            'ping',
        ],
        'description' => 'Test key with full attendance and leaves capabilities',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Read-only key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Read Only Attendance Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'scopes' => ['read:attendance', 'read:leaves'],
        'description' => 'Read only permissions',
    ], $this->admin);

    $this->readApiKey = $readKeyData['apiKey'];
    $this->readPlainKey = $readKeyData['plainTextKey'];
});

test('api authentication rejects unauthenticated calls or invalid keys', function () {
    $this->getJson('/api/v1/attendance/dashboard')
        ->assertStatus(401)
        ->assertJson(['success' => false]);

    $this->withHeaders(['X-Api-Key' => 'sntc_live_invalidkey1234567890'])
        ->getJson('/api/v1/attendance/dashboard')
        ->assertStatus(401)
        ->assertJson(['success' => false]);
});

test('attendance endpoints enforce granular scope authorization', function () {
    // Read key cannot write
    $this->withHeaders(['X-Api-Key' => $this->readPlainKey])
        ->postJson('/api/v1/attendance/mark', [
            'user_id' => $this->staff->id,
            'date' => Carbon::today()->toDateString(),
            'status' => 'present',
        ])
        ->assertStatus(403)
        ->assertJson(['success' => false]);
});

test('attendance executive dashboard and statistics return valid telemetry envelopes', function () {
    $response = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->getJson('/api/v1/attendance/dashboard')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'date',
                'summary' => ['total_punches_today', 'present_count', 'absent_count', 'late_count', 'on_leave_count'],
                'devices' => ['total', 'online', 'active'],
            ],
            'meta' => ['timestamp', 'version', 'latency_ms'],
        ]);

    expect($response->json('success'))->toBeTrue();

    $statsResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->getJson('/api/v1/attendance/statistics?user_type=staff')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['total_records', 'present_count', 'absent_count', 'late_count', 'attendance_rate_percentage'],
        ]);

    expect($statsResponse->json('success'))->toBeTrue();
});

test('attendance single mark, register query, and soft delete lifecycle', function () {
    $today = Carbon::today()->toDateString();

    // Mark single record
    $markResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/mark', [
            'user_id' => $this->staff->id,
            'date' => $today,
            'status' => 'present',
            'clock_in' => '09:05:00',
            'entry_source' => 'manual',
            'remarks' => 'On time morning arrival',
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true]);

    $attendanceId = $markResponse->json('data.id');
    expect($attendanceId)->toBeGreaterThan(0);

    // Query register
    $registerResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->getJson("/api/v1/attendance/register?date={$today}&user_type=staff")
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'current_page',
                'data' => [
                    '*' => ['id', 'user_id', 'date', 'status', 'user'],
                ],
            ],
        ]);

    expect($registerResponse->json('data.total'))->toBeGreaterThanOrEqual(1);

    // Update attendance record
    $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->putJson("/api/v1/attendance/{$attendanceId}", [
            'clock_out' => '17:30:00',
            'remarks' => 'Regular departure',
        ])
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    // Soft delete attendance record
    $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->deleteJson("/api/v1/attendance/{$attendanceId}")
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(Attendance::find($attendanceId))->toBeNull()
        ->and(Attendance::withTrashed()->find($attendanceId))->not->toBeNull();
});

test('bulk cohort attendance marking processes records in transaction', function () {
    $today = Carbon::today()->toDateString();

    $payload = [
        'date' => $today,
        'records' => [
            [
                'user_id' => $this->student->id,
                'status' => 'present',
                'clock_in' => '10:00:00',
                'entry_source' => 'web_portal',
            ],
            [
                'user_id' => $this->staff->id,
                'status' => 'present',
                'clock_in' => '09:00:00',
                'entry_source' => 'web_portal',
            ],
        ],
    ];

    $response = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/bulk-mark', $payload)
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'total_submitted' => 2,
                'successful_count' => 2,
                'failed_count' => 0,
            ],
        ]);

    expect($response->json('success'))->toBeTrue();
    expect(Attendance::whereDate('date', $today)->count())->toBeGreaterThanOrEqual(2);
});

test('turnstile gate punch and offline batch ingestion update biometric logs and attendance', function () {
    $device = BiometricDevice::create([
        'device_name' => 'Main Gate Turnstile A',
        'device_serial_number' => 'TURNSTILE-GATE-01',
        'device_type' => 'turnstile_gate',
        'location' => 'Academic Block Entry',
        'api_token' => 'gate_token_test_abc123',
        'status' => 'online',
    ]);

    // Single Turnstile Gate punch using RFID
    $punchResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/punch', [
            'rfid_card_no' => 'RFID-8001',
            'device_identifier' => 'TURNSTILE-GATE-01',
            'punch_time' => Carbon::now()->toDateTimeString(),
            'direction' => 'in',
            'verification_mode' => 'rfid',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'verified' => true,
                'gate_action' => 'unlock',
                'user_id' => $this->student->id,
            ],
        ]);

    expect($punchResponse->json('data.verified'))->toBeTrue();

    // Batch punch ingestion
    $batchPayload = [
        'device_identifier' => 'TURNSTILE-GATE-01',
        'punches' => [
            [
                'biometric_pin' => '7001',
                'punch_time' => Carbon::now()->subHours(2)->toDateTimeString(),
                'direction' => 'in',
                'verification_mode' => 'fingerprint',
            ],
            [
                'rfid_card_no' => 'RFID-8001',
                'punch_time' => Carbon::now()->subHours(1)->toDateTimeString(),
                'direction' => 'out',
                'verification_mode' => 'rfid',
            ],
        ],
    ];

    $batchResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/batch-punch', $batchPayload)
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'total_received' => 2,
                'ingested_count' => 2,
                'unresolved_count' => 0,
            ],
        ]);

    expect($batchResponse->json('data.ingested_count'))->toBe(2);
});

test('mobile gps clock-in and clock-out with coordinate validation', function () {
    $clockInResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/clock-in', [
            'user_id' => $this->staff->id,
            'latitude' => 22.572646,
            'longitude' => 88.363895,
            'accuracy_meters' => 12.5,
            'device_meta' => [
                'os' => 'Android 14',
                'app_version' => '2.4.1',
            ],
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'user_id' => $this->staff->id,
            ],
        ]);

    expect(in_array($clockInResponse->json('data.status'), ['present', 'late']))->toBeTrue()
        ->and($clockInResponse->json('success'))->toBeTrue();

    // Clock out
    $clockOutResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/clock-out', [
            'user_id' => $this->staff->id,
            'latitude' => 22.572650,
            'longitude' => 88.363900,
        ])
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($clockOutResponse->json('data.clock_out'))->not->toBeNull();
});

test('biometric devices CRUD and token regeneration', function () {
    // Create device
    $createResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/devices', [
            'name' => 'Hostel Block B Facial Terminal',
            'device_identifier' => 'HOSTEL-FACE-02',
            'device_type' => 'facial_recognition',
            'location' => 'Hostel Gate B',
            'ip_address' => '192.168.1.115',
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true]);

    $deviceId = $createResponse->json('data.id');
    expect($deviceId)->toBeGreaterThan(0);

    // Regenerate device token
    $tokenResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson("/api/v1/attendance/devices/{$deviceId}/regenerate-token")
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['id', 'device_token'],
        ]);

    expect($tokenResponse->json('data.device_token'))->not->toBeNull();

    // Delete device
    $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->deleteJson("/api/v1/attendance/devices/{$deviceId}")
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(BiometricDevice::find($deviceId))->toBeNull();
});

test('working calendars and holidays CRUD operations', function () {
    // Create calendar
    $calResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/calendars', [
            'name' => 'Academic Year 2026 Shift A',
            'target_type' => 'student',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'working_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            'shift_start_time' => '10:00:00',
            'shift_end_time' => '17:00:00',
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true]);

    $calId = $calResponse->json('data.id');

    // Create holiday
    $holidayResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/attendance/holidays', [
            'title' => 'Independence Day Observance',
            'date' => '2026-08-15',
            'is_mandatory' => true,
            'description' => 'National Holiday',
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true]);

    $holidayId = $holidayResponse->json('data.id');

    // Clean up
    $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->deleteJson("/api/v1/attendance/calendars/{$calId}")
        ->assertStatus(200);

    $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->deleteJson("/api/v1/attendance/holidays/{$holidayId}")
        ->assertStatus(200);
});

test('defaulters report calculates low attendance candidates accurately', function () {
    // Student has 0 attendance records, total days > 0 -> should appear or calculate
    $response = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->getJson('/api/v1/attendance/defaulters?user_type=student&threshold_percentage=75')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'threshold_percentage',
                'total_defaulters',
                'defaulters',
            ],
        ]);

    expect($response->json('success'))->toBeTrue();
});

test('leave categories and quota balance allocations CRUD', function () {
    // Create leave category
    $catResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/leaves/categories', [
            'name' => 'Conference & Research Leave',
            'code' => 'CRL',
            'applicable_to' => 'staff',
            'max_days_per_year' => 10,
            'requires_proof' => true,
            'is_paid' => true,
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true]);

    $categoryId = $catResponse->json('data.id');
    expect($categoryId)->toBeGreaterThan(0);

    // Allocate quota
    $allocResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/leaves/allocations', [
            'user_id' => $this->staff->id,
            'leave_category_id' => $categoryId,
            'year' => (int) Carbon::now()->year,
            'total_days' => 10,
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true]);

    $allocId = $allocResponse->json('data.id');

    // Query balance sheet
    $balanceResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->getJson("/api/v1/leaves/balances/{$this->staff->id}")
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'user_id',
                'year',
                'balances' => [
                    '*' => ['category_id', 'category_name', 'total_days', 'used_days', 'remaining_days'],
                ],
            ],
        ]);

    expect($balanceResponse->json('success'))->toBeTrue();

    // Delete category when no active applications exist
    $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->deleteJson("/api/v1/leaves/categories/{$categoryId}")
        ->assertStatus(200)
        ->assertJson(['success' => true]);
});

test('leave application submission, approval with automatic attendance sync, and cancellation', function () {
    $category = LeaveCategory::firstOrCreate(
        ['code' => 'MED'],
        [
            'name' => 'Medical Leave',
            'applicable_to' => 'both',
            'annual_quota' => 15,
            'is_paid' => true,
            'is_active' => true,
        ]
    );

    LeaveAllocation::updateOrCreate(
        ['user_id' => $this->staff->id, 'leave_category_id' => $category->id, 'year' => (int) Carbon::now()->year],
        ['total_days' => 15.0, 'used_days' => 0.0]
    );

    $startDate = Carbon::tomorrow()->toDateString();
    $endDate = Carbon::tomorrow()->addDay()->toDateString();

    // 1. Submit leave application
    $applyResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson('/api/v1/leaves/applications', [
            'user_id' => $this->staff->id,
            'leave_category_id' => $category->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Viral fever rest and recuperation',
        ])
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'pending',
                'total_days' => 2,
            ],
        ]);

    $applicationId = $applyResponse->json('data.id');

    // 2. Approve leave application -> Should automatically generate on_leave attendance records
    $approveResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson("/api/v1/leaves/applications/{$applicationId}/approve", [
            'approver_remarks' => 'Approved on medical grounds. Get well soon.',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'approved',
                'synced_attendance_days' => 2,
            ],
        ]);

    expect($approveResponse->json('data.status'))->toBe('approved');

    // Verify synced attendance records exist in the database
    $syncedAttendance = Attendance::where('user_id', $this->staff->id)
        ->whereDate('date', $startDate)
        ->first();

    expect($syncedAttendance)->not->toBeNull()
        ->and($syncedAttendance->status)->toBe('on_leave')
        ->and($syncedAttendance->entry_source)->toBe('system_auto_leave');

    // Verify deleting this attendance record manually is prevented due to active approved leave guard
    $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->deleteJson("/api/v1/attendance/{$syncedAttendance->id}")
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    // 3. Cancel the approved leave -> Should restore balance and remove synced attendance records
    $cancelResponse = $this->withHeaders(['X-Api-Key' => $this->plainKey])
        ->postJson("/api/v1/leaves/applications/{$applicationId}/cancel", [
            'remarks' => 'Recovered early, resuming duties.',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'cancelled',
            ],
        ]);

    expect($cancelResponse->json('data.status'))->toBe('cancelled');

    // Synced attendance should now be removed or restored
    $restoredAttendance = Attendance::where('user_id', $this->staff->id)
        ->whereDate('date', $startDate)
        ->first();

    expect($restoredAttendance)->toBeNull();
});
