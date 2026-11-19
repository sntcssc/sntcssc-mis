<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->regularUser = User::factory()->create();
    $this->regularUser->assignRole('Student');

    $this->apiKeyService = app(ApiKeyService::class);
});

test('administrators can access API key management, logs, and docs views', function () {
    $team = $this->admin->personalTeam();

    $this->actingAs($this->admin)
        ->get(route('admin.api-keys.index', ['current_team' => $team->slug]))
        ->assertOk();

    $this->actingAs($this->admin)
        ->get(route('admin.api-keys.logs', ['current_team' => $team->slug]))
        ->assertOk();

    $this->actingAs($this->admin)
        ->get(route('admin.api-keys.docs', ['current_team' => $team->slug]))
        ->assertOk();
});

test('unauthorized users cannot access API key management', function () {
    $team = $this->regularUser->personalTeam();

    $this->actingAs($this->regularUser)
        ->get(route('admin.api-keys.index', ['current_team' => $team->slug]))
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->get(route('admin.api-keys.logs', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('api key service generates secure key with prefix and sha256 hash', function () {
    $result = $this->apiKeyService->generateKey([
        'name' => 'Mobile Flutter App',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['ping', 'read:academics', 'read:students'],
        'description' => 'Test Flutter mobile key',
    ], $this->admin);

    expect($result)->toHaveKeys(['apiKey', 'plainTextKey'])
        ->and($result['apiKey'])->toBeInstanceOf(ApiKey::class)
        ->and($result['plainTextKey'])->toStartWith('sntc_live_')
        ->and($result['apiKey']->key_hash)->toBe(hash('sha256', $result['plainTextKey']))
        ->and($result['apiKey']->rate_limit_per_minute)->toBe(120)
        ->and($result['apiKey']->hasScope('read:students'))->toBeTrue()
        ->and($result['apiKey']->hasScope('write:students'))->toBeFalse();

    $this->assertDatabaseHas('api_keys', [
        'name' => 'Mobile Flutter App',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
    ]);
});

test('api call without api key is rejected with 401 API_KEY_MISSING', function () {
    $response = $this->getJson('/api/v1/ping');

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'API_KEY_MISSING',
            ],
        ]);
});

test('api call with invalid key is rejected with 401 API_KEY_INVALID', function () {
    $response = $this->withHeaders([
        'X-API-Key' => 'sntc_live_invalidkeythatdoesnotexist1234567890',
    ])->getJson('/api/v1/ping');

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'API_KEY_INVALID',
            ],
        ]);
});

test('api call with valid api key succeeds and returns rate limit headers', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Mobile Client',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'scopes' => ['ping', 'read:academics'],
    ], $this->admin);

    $plainKey = $generated['plainTextKey'];

    $response = $this->withHeaders([
        'X-API-Key' => $plainKey,
    ])->getJson('/api/v1/ping');

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Pong! SNTCSSC MIS REST API is live and operational.',
            'data' => [
                'client' => [
                    'name' => 'Mobile Client',
                    'type' => 'mobile_app',
                    'environment' => 'production',
                ],
            ],
        ])
        ->assertHeader('X-RateLimit-Limit', '60');

    // Telemetry log should be recorded
    $this->assertDatabaseHas('api_key_logs', [
        'api_key_id' => $generated['apiKey']->id,
        'status_code' => 200,
        'method' => 'GET',
    ]);
});

test('bearer authorization header is accepted interchangeably with X-API-Key', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Bearer Client',
        'type' => 'desktop_app',
        'environment' => 'production',
        'scopes' => ['ping'],
    ], $this->admin);

    $plainKey = $generated['plainTextKey'];

    $response = $this->withToken($plainKey)->getJson('/api/v1/ping');

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('revoked api key cannot be used and returns 401 API_KEY_REVOKED', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Revoke Test Client',
        'scopes' => ['ping'],
    ], $this->admin);

    $plainKey = $generated['plainTextKey'];
    $key = $generated['apiKey'];

    $this->apiKeyService->revokeKey($key, 'Security incident drill', $this->admin);

    expect($key->fresh()->isRevoked())->toBeTrue();

    $response = $this->withHeaders([
        'X-API-Key' => $plainKey,
    ])->getJson('/api/v1/ping');

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'API_KEY_REVOKED');
});

test('suspended api key returns 403 API_KEY_SUSPENDED', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Suspended Client',
        'scopes' => ['ping'],
    ], $this->admin);

    $plainKey = $generated['plainTextKey'];
    $key = $generated['apiKey'];

    $this->apiKeyService->toggleStatus($key, $this->admin);

    expect($key->fresh()->isSuspended())->toBeTrue();

    $response = $this->withHeaders([
        'X-API-Key' => $plainKey,
    ])->getJson('/api/v1/ping');

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'API_KEY_SUSPENDED');
});

test('expired api key returns 401 API_KEY_EXPIRED', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Expired Client',
        'expires_at' => Carbon::now()->subDay(),
        'scopes' => ['ping'],
    ], $this->admin);

    $plainKey = $generated['plainTextKey'];

    $response = $this->withHeaders([
        'X-API-Key' => $plainKey,
    ])->getJson('/api/v1/ping');

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'API_KEY_EXPIRED');
});

test('ip allowlist restricts access from unauthorized IPs', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Firewalled Client',
        'ip_allowlist' => ['192.168.1.100', '10.0.0.0/24'],
        'scopes' => ['ping'],
    ], $this->admin);

    $plainKey = $generated['plainTextKey'];

    // Call from unauthorized IP
    $response = $this->withHeaders([
        'X-API-Key' => $plainKey,
    ])->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.45',
    ])->getJson('/api/v1/ping');

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'IP_UNAUTHORIZED');

    // Call from whitelisted IP
    $allowedResponse = $this->withHeaders([
        'X-API-Key' => $plainKey,
    ])->withServerVariables([
        'REMOTE_ADDR' => '192.168.1.100',
    ])->getJson('/api/v1/ping');

    $allowedResponse->assertOk()
        ->assertJsonPath('success', true);
});

test('scope middleware blocks access if key lacks required scope', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Ping Only Client',
        'scopes' => ['ping'], // Does NOT have read:students
    ], $this->admin);

    $plainKey = $generated['plainTextKey'];

    $response = $this->withHeaders([
        'X-API-Key' => $plainKey,
    ])->getJson('/api/v1/students');

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');
});

test('scope middleware permits access if key has required scope or wildcard', function () {
    // Key with read:students
    $studentKey = $this->apiKeyService->generateKey([
        'name' => 'Student Portal Client',
        'scopes' => ['read:students'],
    ], $this->admin);

    $response = $this->withHeaders([
        'X-API-Key' => $studentKey['plainTextKey'],
    ])->getJson('/api/v1/students');

    $response->assertOk()
        ->assertJsonPath('success', true);

    // Key with wildcard *
    $adminKey = $this->apiKeyService->generateKey([
        'name' => 'Root Admin Client',
        'scopes' => ['*'],
    ], $this->admin);

    $wildcardResponse = $this->withHeaders([
        'X-API-Key' => $adminKey['plainTextKey'],
    ])->getJson('/api/v1/students');

    $wildcardResponse->assertOk()
        ->assertJsonPath('success', true);
});

test('api key secret rotation invalidates old secret and validates new secret', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Rotating Client',
        'scopes' => ['ping'],
    ], $this->admin);

    $oldPlainKey = $generated['plainTextKey'];
    $key = $generated['apiKey'];

    $rotated = $this->apiKeyService->rotateKey($key, $this->admin);
    $newPlainKey = $rotated['plainTextKey'];

    expect($newPlainKey)->not->toBe($oldPlainKey);

    // Old secret fails
    $this->withHeaders(['X-API-Key' => $oldPlainKey])
        ->getJson('/api/v1/ping')
        ->assertStatus(401);

    // New secret succeeds
    $this->withHeaders(['X-API-Key' => $newPlainKey])
        ->getJson('/api/v1/ping')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('livewire index component allows searching and creating key via modal', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.index')
        ->assertSee('API Key Management')
        ->set('createForm.name', 'Desktop Electron Admin')
        ->set('createForm.type', 'desktop_app')
        ->set('createForm.environment', 'production')
        ->set('createForm.rate_limit_per_minute', 150)
        ->set('createForm.scopes', ['ping', 'read:academics'])
        ->call('createKey')
        ->assertSet('showKeyRevealModal', true)
        ->assertSee('Desktop Electron Admin');

    $this->assertDatabaseHas('api_keys', [
        'name' => 'Desktop Electron Admin',
        'type' => 'desktop_app',
    ]);
});

test('developer console & docs page includes hostel management endpoints, presets, and guides', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('API Developer Portal & Console')
        ->assertSee('/api/v1/hostel/dashboard')
        ->assertSee('/api/v1/hostel/visual-board')
        ->assertSee('Hostel Management REST API Integration Guide')
        ->assertSee('read:hostel')
        ->set('activeScopeTab', 'hostel')
        ->assertSee('/api/v1/hostel/allocations')
        ->call('openGuide', 'hostel')
        ->assertSet('showGuideModal', true)
        ->assertSee('SNTCSSC MIS — Hostel Management REST API Integration Guide')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});

test('quick test modal in api keys index supports hostel endpoints', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Hostel Test Client',
        'scopes' => ['ping', 'read:hostel'],
    ], $this->admin);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.index')
        ->call('openTestModal', $generated['apiKey']->id)
        ->set('testEndpoint', 'hostel-dashboard')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200)
        ->assertSee('metrics');
});

test('developer console & docs page includes optional subjects and student id cards endpoints, presets, and guides', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('API Developer Portal & Console')
        ->assertSee('/api/v1/catalog/optional-subjects')
        ->assertSee('/api/v1/optional-subjects/allocations')
        ->assertSee('/api/v1/student-id-cards/settings')
        ->assertSee('/api/v1/student-id-cards/{identifier}/payload')
        ->assertSee('Optional Subjects & Change Requests API Guide')
        ->assertSee('Student Identity Cards & QR Verification API Guide')
        ->set('activeScopeTab', 'optional_subjects')
        ->assertSee('/api/v1/optional-subjects/quick-update')
        ->set('activeScopeTab', 'student_id_cards')
        ->assertSee('/api/v1/student-id-cards/{identifier}/download')
        ->call('openGuide', 'optional_subjects')
        ->assertSet('showGuideModal', true)
        ->assertSee('Optional Subjects Management')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false)
        ->call('openGuide', 'student_id_cards')
        ->assertSet('showGuideModal', true)
        ->assertSee('Student Identity Cards')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});

test('developer console & docs page includes dynamic study materials endpoints, presets, and guide modal', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('API Developer Portal & Console')
        ->assertSee('/api/v1/study-materials')
        ->assertSee('/api/v1/study-materials/types')
        ->assertSee('/api/v1/study-materials/statistics')
        ->assertSee('read:materials')
        ->set('activeScopeTab', 'materials')
        ->assertSee('/api/v1/study-materials/my-materials')
        ->assertSee('/api/v1/study-materials/{id}/download-token')
        ->call('openGuide', 'study_materials')
        ->assertSet('showGuideModal', true)
        ->assertSee('Dynamic Study Material Management System')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});

test('developer console & docs page includes application system settings endpoints, presets, and guide modal', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('API Developer Portal & Console')
        ->assertSee('/api/v1/settings/public')
        ->assertSee('/api/v1/settings/groups')
        ->assertSee('/api/v1/settings/statistics')
        ->assertSee('read:settings')
        ->set('activeScopeTab', 'settings')
        ->assertSee('/api/v1/settings/groups/{group}')
        ->assertSee('/api/v1/settings/batch')
        ->call('openGuide', 'settings')
        ->assertSet('showGuideModal', true)
        ->assertSee('Application System Settings REST API')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});

test('quick test modal in api keys index supports study materials and settings endpoints', function () {
    $generated = $this->apiKeyService->generateKey([
        'name' => 'All Modules Test Client',
        'scopes' => ['ping', 'read:materials', 'read:settings', 'read:tickets'],
    ], $this->admin);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.index')
        ->call('openTestModal', $generated['apiKey']->id)
        ->set('testEndpoint', 'study-materials-types')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200)
        ->set('testEndpoint', 'settings-public')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200)
        ->assertSee('SNT CSSC')
        ->set('testEndpoint', 'settings-groups')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200)
        ->assertSee('general')
        ->set('testEndpoint', 'tickets-dashboard')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200)
        ->set('testEndpoint', 'tickets-categories')
        ->call('runQuickTest')
        ->assertSet('testStatusCode', 200);
});

test('developer console & docs page includes support tickets endpoints, presets, and guide modal', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('API Developer Portal & Console')
        ->assertSee('/api/v1/tickets/dashboard')
        ->assertSee('/api/v1/tickets/categories')
        ->assertSee('/api/v1/tickets/my-tickets')
        ->assertSee('read:tickets')
        ->set('activeScopeTab', 'tickets')
        ->assertSee('/api/v1/tickets/{identifier}/messages')
        ->assertSee('/api/v1/tickets/{identifier}/status')
        ->call('openGuide', 'tickets')
        ->assertSet('showGuideModal', true)
        ->assertSee('Support Tickets & Helpdesk')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});
