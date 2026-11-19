<?php

use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\HostelSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(HostelSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Hostel API Admin']);
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Hostel Master Integration Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:hostel', 'write:hostel', 'download:hostel_forms', 'ping'],
        'description' => 'Test key with full hostel scopes',
    ], $this->admin);

    $this->apiKey = $generated['apiKey'];
    $this->plainKey = $generated['plainTextKey'];

    // Read-only key
    $readGen = $this->apiKeyService->generateKey([
        'name' => 'Hostel Readonly Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['read:hostel'],
        'description' => 'Read only hostel key',
    ], $this->admin);
    $this->readOnlyPlainKey = $readGen['plainTextKey'];
});

test('public hostel policies endpoint is accessible without authentication', function () {
    $response = $this->getJson('/api/v1/hostel/policies');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'success',
            'data' => [
                'rules_notice',
                'default_duration_months',
                'expiry_notice_days',
                'allow_resident_extension_requests',
                'soft_copy_mandatory',
                'helpdesk_contact' => ['email', 'hours', 'emergency_line'],
            ],
        ]);
});

test('hostel dashboard requires valid api key and scope', function () {
    // 1. Missing API key returns 401
    $this->getJson('/api/v1/hostel/dashboard')
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'error' => ['code' => 'API_KEY_MISSING'],
        ]);

    // 2. With valid API key returns 200 and metrics
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/hostel/dashboard')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'metrics' => [
                    'total_capacity',
                    'occupied_beds',
                    'available_beds',
                    'occupancy_rate',
                ],
                'buildings',
                'expiring_soon',
            ],
        ]);
});

test('visual board returns floor and bed status matrix', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/hostel/visual-board');

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'building_id',
                    'building_name',
                    'building_code',
                    'floors' => [
                        '*' => [
                            'floor_id',
                            'rooms' => [
                                '*' => [
                                    'room_id',
                                    'room_number',
                                    'beds' => [
                                        '*' => ['bed_id', 'bed_number', 'status', 'is_occupied'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
});

test('buildings CRUD via API', function () {
    // 1. List buildings
    $list = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/hostel/buildings')
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(count($list->json('data')))->toBeGreaterThanOrEqual(3);

    // 2. Create building with caretakers
    $create = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/buildings', [
            'name' => 'Kanchenjunga Hostel',
            'code' => 'KJH',
            'gender_type' => 'boys',
            'total_floors' => 3,
            'warden_name' => 'Prof. Amit Sharma',
            'warden_contact' => '+91 98765 43210',
            'caretakers' => [
                [
                    'name' => 'Bikash Mondal',
                    'phone' => '+91 98765 11111',
                    'shift' => 'day',
                    'is_primary' => true,
                ],
            ],
        ]);

    $create->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Kanchenjunga Hostel',
                'code' => 'KJH',
                'gender_type' => 'boys',
            ],
        ]);

    $buildingId = $create->json('data.id');
    expect(HostelBuilding::find($buildingId)->floors()->count())->toBe(3);

    // 3. Update building
    $update = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/hostel/buildings/{$buildingId}", [
            'name' => 'Kanchenjunga Residence Hall',
            'warden_name' => 'Prof. A. K. Sharma',
        ]);

    $update->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Kanchenjunga Residence Hall',
            ],
        ]);

    // 4. Delete building
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/hostel/buildings/{$buildingId}")
        ->assertStatus(200);

    expect(HostelBuilding::find($buildingId))->toBeNull();
});

test('rooms and allocations lifecycle via API', function () {
    $building = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $floor = $building->floors()->first();

    // 1. Create a double sharing room
    $roomResp = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/rooms', [
            'hostel_building_id' => $building->id,
            'hostel_floor_id' => $floor->id,
            'room_number' => 'GH-API-101',
            'room_type' => 'double',
            'capacity' => 2,
            'gender_allowed' => 'male_only',
            'amenities' => ['Wi-Fi', 'Study Table', 'Air Cooler'],
            'monthly_fee' => 3500,
        ]);

    $roomResp->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'room_number' => 'GH-API-101',
                'capacity' => 2,
            ],
        ]);

    $roomId = $roomResp->json('data.id');
    $room = HostelRoom::find($roomId);
    expect($room->beds()->count())->toBe(2);

    $bed = $room->beds()->first();

    // 2. Allot resident to bed
    $student = User::factory()->create([
        'name' => 'API Resident Student',
        'email' => 'api_resident@example.com',
        'gender' => 'Male',
    ]);
    $student->assignRole('Student');

    $allocResp = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/allocations', [
            'user_id' => $student->id,
            'hostel_bed_id' => $bed->id,
            'resident_type' => 'student',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'remarks' => 'Allotted via REST API',
        ]);

    $allocResp->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'user_id' => $student->id,
                'status' => 'active',
            ],
        ]);

    $allocId = $allocResp->json('data.id');
    expect($bed->fresh()->status)->toBe('occupied');

    // 3. Inspect single allocation
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/hostel/allocations/{$allocId}")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $allocId,
                'resident_type' => 'student',
            ],
        ]);

    // 4. Verify form submission via API
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/hostel/allocations/{$allocId}/verify-form", [
            'form_submitted' => true,
            'soft_copy_verified' => true,
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'form_submitted' => true,
                'soft_copy_verified' => true,
            ],
        ]);

    // 5. Administratively extend stay
    $newDate = now()->addMonths(9)->toDateString();
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/hostel/allocations/{$allocId}/extend", [
            'new_end_date' => $newDate,
            'notes' => 'Extended for exam preparation',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'extended',
            ],
        ]);

    // 6. Check out / vacate resident
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/hostel/allocations/{$allocId}/vacate", [
            'vacated_date' => now()->toDateString(),
            'remarks' => 'Course completed successfully',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'checked_out',
            ],
        ]);

    expect($bed->fresh()->status)->toBe('available');
});

test('resident personal portal endpoints work for authenticated resident user', function () {
    $resident = User::factory()->create([
        'name' => 'Mobile App Student',
        'email' => 'mobile_student@example.com',
        'gender' => 'Male',
    ]);
    StudentProfile::create([
        'user_id' => $resident->id,
        'urn' => 'URN-MOB-01',
        'gender' => 'Male',
        'student_status' => 'enrolled',
    ]);
    $resident->syncRoles(['Student']);

    $room = HostelRoom::first();
    $bed = $room->beds()->first();
    $allocation = HostelAllocation::create([
        'user_id' => $resident->id,
        'resident_type' => 'student',
        'hostel_room_id' => $room->id,
        'hostel_bed_id' => $bed->id,
        'allocation_no' => 'HST-MOB-TEST-01',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
    ]);
    $bed->update(['status' => 'occupied']);

    // 1. Resident queries their active accommodation
    $this->actingAs($resident)
        ->getJson('/api/v1/hostel/my-accommodation')
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'resident' => [
                    'id' => $resident->id,
                    'name' => 'Mobile App Student',
                ],
                'current_allocation' => [
                    'id' => $allocation->id,
                    'allocation_no' => 'HST-MOB-TEST-01',
                ],
            ],
        ]);

    // 2. Resident submits an extension request
    $reqResp = $this->actingAs($resident)
        ->postJson('/api/v1/hostel/my-accommodation/extension-request', [
            'extension_type' => 'months',
            'extension_value' => 3,
            'reason' => 'Need additional stay for UPSC mains revision classes.',
        ]);

    $reqResp->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'pending',
                'extension_duration_type' => 'months',
                'extension_duration_value' => 3,
            ],
        ]);

    $requestId = $reqResp->json('data.id');

    // 3. Resident checks their submitted extension requests
    $this->actingAs($resident)
        ->getJson('/api/v1/hostel/my-accommodation/extension-requests')
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    // 4. Staff/Admin reviews and approves extension request via API
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/hostel/extension-requests/{$requestId}/review", [
            'action' => 'approve',
            'admin_notes' => 'Approved by warden for Mains revision.',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'approved',
                'admin_notes' => 'Approved by warden for Mains revision.',
            ],
        ]);

    expect($allocation->fresh()->status)->toBe('extended');
});

test('resident soft-copy admission form upload via API', function () {
    Storage::fake('public');

    $resident = User::factory()->create(['name' => 'SoftCopy Student']);
    $resident->assignRole('Student');

    $room = HostelRoom::first();
    $bed = $room->beds()->first();
    HostelAllocation::create([
        'user_id' => $resident->id,
        'resident_type' => 'student',
        'hostel_room_id' => $room->id,
        'hostel_bed_id' => $bed->id,
        'allocation_no' => 'HST-SC-TEST-01',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
    ]);

    $file = UploadedFile::fake()->create('signed_hostel_form.pdf', 500, 'application/pdf');

    $response = $this->actingAs($resident)
        ->postJson('/api/v1/hostel/my-accommodation/upload-form', [
            'file' => $file,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);
});

test('hostel analytical reports endpoints return structured data', function () {
    // 1. Occupancy report
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/hostel/reports/occupancy')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'building_id',
                    'name',
                    'code',
                    'total_floors',
                    'total_rooms',
                    'total_beds',
                    'occupied_beds',
                    'available_beds',
                    'occupancy_percentage',
                ],
            ],
        ]);

    // 2. Compliance report
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/hostel/reports/compliance')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'total_active_residents',
                'physical_form_submitted',
                'physical_compliance_rate',
                'soft_copy_uploaded',
            ],
        ]);

    // 3. Expiring report
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/hostel/reports/expiring?days=30')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['threshold_days', 'total_expiring'],
        ]);
});

test('read-only key cannot perform write operations', function () {
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/hostel/buildings', [
            'name' => 'Unauthorized Building',
            'code' => 'UAB',
            'gender_type' => 'boys',
            'total_floors' => 2,
        ])
        ->assertStatus(403)
        ->assertJson([
            'success' => false,
            'error' => ['code' => 'INSUFFICIENT_SCOPE'],
        ]);
});

test('bed status update and resident transfer endpoints function correctly', function () {
    $building = HostelBuilding::first();
    $room1 = $building->rooms()->first();
    $room2 = $building->rooms()->skip(1)->first();

    $bed1 = $room1->beds()->first();
    $bed2 = $room2->beds()->first();

    // 1. Update bed status to maintenance
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/hostel/beds/{$bed2->id}/status", [
            'status' => 'maintenance',
            'notes' => 'Electrical plug socket repair',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $bed2->id,
                'status' => 'maintenance',
            ],
        ]);

    expect($bed2->fresh()->status)->toBe('maintenance');

    // Restore bed2 to available for transfer test
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/hostel/beds/{$bed2->id}/status", [
            'status' => 'available',
        ])
        ->assertStatus(200);

    // 2. Allocate student to bed1
    $student = User::factory()->create(['gender' => 'Male', 'name' => 'Transfer Resident']);
    StudentProfile::create(['user_id' => $student->id, 'gender' => 'Male', 'student_status' => 'enrolled']);
    $student->syncRoles(['Student']);

    $allocResp = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/allocations', [
            'user_id' => $student->id,
            'hostel_bed_id' => $bed1->id,
            'resident_type' => 'student',
            'start_date' => now()->toDateString(),
            'duration_months' => 6,
        ])
        ->assertStatus(201);

    $allocationId = $allocResp->json('data.id');
    expect($bed1->fresh()->status)->toBe('occupied');

    // 3. Transfer student from bed1 to bed2
    $transferResp = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/hostel/allocations/{$allocationId}/transfer", [
            'new_hostel_bed_id' => $bed2->id,
            'reason' => 'Transferred to quiet corner room for UPSC preparation.',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $allocationId,
                'hostel_bed_id' => $bed2->id,
                'hostel_room_id' => $room2->id,
            ],
        ]);

    expect($bed1->fresh()->status)->toBe('available');
    expect($bed2->fresh()->status)->toBe('occupied');
});

test('gatekeeper resident verification endpoint validates active allocation and detects unallocated users', function () {
    $resident = User::factory()->create([
        'name' => 'Gate Resident',
        'email' => 'gate_resident@example.com',
        'urn' => 'URN-GATE-99',
    ]);
    $profile = StudentProfile::create(['user_id' => $resident->id, 'gender' => 'Male', 'student_status' => 'enrolled']);
    $resident->syncRoles(['Student']);

    $bed = HostelBed::with('room')->where('status', 'available')->first();
    $room = $bed->room;

    $allocation = HostelAllocation::create([
        'user_id' => $resident->id,
        'resident_type' => 'student',
        'hostel_room_id' => $room->id,
        'hostel_bed_id' => $bed->id,
        'allocation_no' => 'HST-GATE-0099',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
    ]);
    $bed->update(['status' => 'occupied']);

    // 1. Verify by Allocation Number
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/verify-resident', [
            'identifier' => 'HST-GATE-0099',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_valid' => true,
                'verification_status' => 'VERIFIED_ACTIVE',
                'resident' => [
                    'id' => $resident->id,
                    'name' => 'Gate Resident',
                ],
                'accommodation' => [
                    'allocation_no' => 'HST-GATE-0099',
                ],
            ],
        ]);

    // 2. Verify by URN
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/verify-resident', [
            'identifier' => 'URN-GATE-99',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_valid' => true,
                'resident' => [
                    'id' => $resident->id,
                ],
            ],
        ]);

    // 3. Verify non-resident returns NOT_A_RESIDENT 404
    $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/verify-resident', [
            'identifier' => 'NON-EXISTENT-URN',
        ])
        ->assertStatus(404)
        ->assertJson([
            'success' => false,
            'error' => ['code' => 'NOT_A_RESIDENT'],
        ]);
});

test('caretakers CRUD and available rooms directory work cleanly', function () {
    $building = HostelBuilding::first();

    // 1. Create caretaker
    $createResp = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/hostel/caretakers', [
            'hostel_building_id' => $building->id,
            'name' => 'Ramesh Caretaker',
            'phone' => '+91 9876543210',
            'email' => 'ramesh.caretaker@example.com',
            'shift' => 'night',
            'is_primary' => true,
        ])
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Ramesh Caretaker',
                'shift' => 'night',
                'is_primary' => true,
            ],
        ]);

    $caretakerId = $createResp->json('data.id');

    // 2. List caretakers
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/hostel/caretakers?building_id={$building->id}")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    // 3. Update caretaker
    $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/hostel/caretakers/{$caretakerId}", [
            'phone' => '+91 9876549999',
            'notes' => 'Promoted to senior night supervisor',
        ])
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'phone' => '+91 9876549999',
            ],
        ]);

    // 4. Soft delete caretaker
    $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/hostel/caretakers/{$caretakerId}")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    // 5. Query available rooms directory
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/hostel/rooms/available')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'room_id',
                    'room_number',
                    'room_type',
                    'available_beds_count',
                    'available_beds',
                ],
            ],
            'meta' => [
                'total_available_rooms',
                'total_available_beds',
            ],
        ]);

    // 6. Public / resident catalog endpoint
    $this->getJson('/api/v1/hostel/catalog')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'buildings',
                'room_types',
                'standard_amenities',
                'policies',
            ],
        ]);
});
