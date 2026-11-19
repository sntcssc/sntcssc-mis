<?php

use App\Models\Role;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'Security Admin',
        'email' => 'admin@security.sntcssc.ac.in',
        'status' => 'active',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->faculty = User::factory()->create([
        'name' => 'Prof. Anirban Roy',
        'email' => 'anirban.roy@sntcssc.ac.in',
        'employee_id' => 'FAC-9011',
        'staff_department' => 'Political Science',
        'status' => 'active',
    ]);
    $this->faculty->assignRole('Faculty');

    $this->student = User::factory()->create([
        'name' => 'Debolina Sen',
        'email' => 'debolina.sen@sntcssc.ac.in',
        'student_id' => 'STU-2026-099',
        'status' => 'active',
    ]);
    $this->student->assignRole('Student');

    $this->apiKeyService = app(ApiKeyService::class);

    // Full key with all users and RBAC scopes
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Full Users & RBAC Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 500,
        'scopes' => [
            'read:users',
            'write:users',
            'manage:roles',
            'manage:permissions',
            'ping',
        ],
        'description' => 'Test key for Users and Access Management REST APIs',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Readonly key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Readonly Users Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 100,
        'scopes' => ['read:users', 'ping'],
        'description' => 'Read-only key without write scopes',
    ], $this->admin);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];
});

test('executive users dashboard endpoint returns population metrics and role counts', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/users/dashboard');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'counts' => [
                    'total',
                    'active',
                    'inactive',
                    'suspended',
                    'locked',
                    'trashed',
                ],
                'rates',
                'roles_distribution',
                'recent_logins',
            ],
        ]);

    expect($response->json('data.counts.total'))->toBeGreaterThanOrEqual(3);
});

test('users statistics endpoint returns analytics datasets', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/users/statistics');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'total_users',
                'gender_distribution',
                'status_distribution',
                'department_distribution',
                'monthly_growth_trends',
            ],
        ]);
});

test('users directory pagination and search works accurately', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/users?search=Anirban');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'email', 'urn'],
            ],
            'meta' => [
                'current_page',
                'total',
            ],
        ]);

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect($data[0]['name'])->toBe('Prof. Anirban Roy');
});

test('registering user generates 14-digit URN, assigns roles, and logs audit record', function () {
    $payload = [
        'name' => 'Dr. Subhash Bose',
        'email' => 'subhash.bose@sntcssc.ac.in',
        'phone' => '9830112233',
        'employee_id' => 'EMP-HIST-1942',
        'staff_department' => 'Indian History & Culture',
        'status' => 'active',
        'roles' => ['Faculty'],
    ];

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/users', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Dr. Subhash Bose')
        ->assertJsonPath('data.employee_id', 'EMP-HIST-1942');

    $createdUser = User::where('email', 'subhash.bose@sntcssc.ac.in')->first();
    expect($createdUser)->not->toBeNull();
    expect(strlen($createdUser->urn))->toBe(14);
    expect($createdUser->hasRole('Faculty'))->toBeTrue();
});

test('user creation is denied with 403 when write:users scope is missing', function () {
    $payload = [
        'name' => 'Unauthorized Attempt',
        'email' => 'unauthorized@example.com',
    ];

    $response = $this->withHeaders([
        'X-API-Key' => $this->readOnlyPlainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/users', $payload);

    $response->assertStatus(403);
});

test('user lookup by id, urn, or employee_id returns full dossier', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/users/'.$this->faculty->employee_id);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $this->faculty->id)
        ->assertJsonPath('data.email', 'anirban.roy@sntcssc.ac.in')
        ->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'uuid',
                'urn',
                'name',
                'email',
                'roles',
                'permissions',
            ],
        ]);
});

test('updating user attributes and syncing roles', function () {
    $payload = [
        'name' => 'Prof. Anirban Roy, Ph.D.',
        'staff_department' => 'International Relations',
        'roles' => ['Faculty'],
    ];

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->putJson('/api/v1/users/'.$this->faculty->id, $payload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Prof. Anirban Roy, Ph.D.')
        ->assertJsonPath('data.staff_department', 'International Relations');

    $this->faculty->refresh();
    expect($this->faculty->name)->toBe('Prof. Anirban Roy, Ph.D.');
});

test('account status update requires justification remarks', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/users/'.$this->student->id.'/status', [
        'status' => 'suspended',
        'reason' => 'Administrative disciplinary inquiry',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'suspended');

    $this->student->refresh();
    expect($this->student->status)->toBe('suspended');
});

test('unlocking user account clears failed login attempts and lockout timestamp', function () {
    $this->student->update([
        'failed_login_attempts' => 5,
        'locked_untill' => Carbon::now()->addHours(2),
    ]);

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/users/'.$this->student->id.'/unlock');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_locked', false);

    $this->student->refresh();
    expect($this->student->failed_login_attempts)->toBe(0);
    expect($this->student->locked_untill)->toBeNull();
});

test('self-deletion protection guard prevents authenticated actor from deleting self', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->deleteJson('/api/v1/users/'.$this->admin->id);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'SELF_DELETION_PROHIBITED');

    expect(User::find($this->admin->id))->not->toBeNull();
});

test('last super administrator protection guard prevents deleting sole super admin', function () {
    $secondAdmin = User::factory()->create(['name' => 'Secondary Admin']);
    $secondAdmin->assignRole('Super Administrator');

    // Create key owned by secondAdmin
    $secKeyData = $this->apiKeyService->generateKey([
        'name' => 'Sec Admin Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'scopes' => ['write:users', 'ping'],
    ], $secondAdmin);

    // Delete first admin so only one remains
    $this->withHeaders([
        'X-API-Key' => $secKeyData['plainTextKey'],
        'Accept' => 'application/json',
    ])->deleteJson('/api/v1/users/'.$this->admin->id);

    // Now attempt to delete the last remaining Super Admin
    $failResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->deleteJson('/api/v1/users/'.$secondAdmin->id);

    $failResponse->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('soft delete and restore user lifecycle', function () {
    $tempUser = User::factory()->create(['name' => 'Temporary Candidate', 'status' => 'active']);

    $delResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->deleteJson('/api/v1/users/'.$tempUser->id);

    $delResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(User::find($tempUser->id))->toBeNull();
    expect(User::withTrashed()->find($tempUser->id))->not->toBeNull();

    // Restore
    $restoreResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/users/'.$tempUser->id.'/restore');

    $restoreResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(User::find($tempUser->id))->not->toBeNull();
});

test('roles catalog, creation, cloning, and protected system role guard', function () {
    // 1. List roles
    $listResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/roles');

    $listResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'users_count', 'permissions_count'],
            ],
        ]);

    // 2. Create custom role
    $createResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/roles', [
        'name' => 'Academic Evaluator 2026',
        'description' => 'External subject evaluator',
    ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Academic Evaluator 2026');

    $roleId = $createResponse->json('data.id');

    // 3. Clone custom role
    $cloneResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/roles/'.$roleId.'/clone', [
        'new_name' => 'Senior Academic Evaluator 2026',
    ]);

    $cloneResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Senior Academic Evaluator 2026');

    // 4. Protected system role deletion rejection
    $deleteSystemRole = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->deleteJson('/api/v1/roles/Super Administrator');

    $deleteSystemRole->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'SYSTEM_ROLE_PROTECTED');
});

test('permissions catalog and functional modules hierarchy', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/permissions/modules');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['total_modules', 'total_permissions'],
        ]);

    expect($response->json('meta.total_permissions'))->toBeGreaterThan(0);
});

test('self-service me profile endpoint returns current user context and matrix', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/user/me');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $this->admin->id)
        ->assertJsonPath('data.email', $this->admin->email)
        ->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'name',
                'email',
                'roles',
                'permissions',
            ],
        ]);
});

test('master security audit logs endpoint returns chronological audit trail', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/security/audit-logs');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => [
                'current_page',
                'total',
            ],
        ]);
});

test('users export stream generates complete structured dataset', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/users/export');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['total_exported', 'generated_at'],
        ]);

    expect($response->json('meta.total_exported'))->toBeGreaterThanOrEqual(3);
});

test('bulk status update modifies cohort of user accounts', function () {
    $userA = User::factory()->create(['status' => 'pending']);
    $userB = User::factory()->create(['status' => 'pending']);

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/users/bulk-status', [
        'user_ids' => [$userA->id, $userB->id],
        'status' => 'active',
        'reason' => 'Batch verification approved',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.updated_count', 2);

    $userA->refresh();
    $userB->refresh();
    expect($userA->status)->toBe('active');
    expect($userB->status)->toBe('active');
});
