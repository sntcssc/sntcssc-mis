<?php

use App\Models\CaptchaLog;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\GlobalSearchService;
use App\Services\RbacService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();

    $this->superAdmin = User::factory()->create(['name' => 'Super Administrator']);
    $this->superAdmin->assignRole('Super Administrator');

    $this->faculty = User::factory()->create(['name' => 'Faculty Officer', 'email' => 'faculty@example.com']);
    $this->faculty->assignRole('Faculty');

    $this->studentUser = User::factory()->create([
        'name' => 'Student Candidate',
        'email' => 'student.candidate@example.com',
        'phone' => '+919876543299',
        'student_id' => 'STU-999',
    ]);
    $this->studentUser->assignRole('Student');

    $this->apiKeyService = app(ApiKeyService::class);

    $keyData = $this->apiKeyService->generateKey([
        'name' => 'Global Search Test Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:search', 'manage:search', 'ping'],
        'description' => 'Key for global search testing',
    ], $this->superAdmin);

    $this->apiKey = $keyData['apiKey'];
    $this->plainKey = $keyData['plainTextKey'];
});

test('global search catalog contains all admin modules and quick actions', function () {
    $catalog = GlobalSearchService::navigationCatalog();
    $actions = GlobalSearchService::quickActionsCatalog();

    expect($catalog)->not->toBeEmpty()
        ->and(count($catalog))->toBeGreaterThanOrEqual(40)
        ->and($actions)->not->toBeEmpty();

    $titles = collect($catalog)->pluck('title')->all();
    expect($titles)->toContain('Dashboard')
        ->and($titles)->toContain('Students Directory')
        ->and($titles)->toContain('Courses Management')
        ->and($titles)->toContain('Attendance Dashboard')
        ->and($titles)->toContain('Books Catalog & Inventory')
        ->and($titles)->toContain('Hostel Dashboard & Telemetry')
        ->and($titles)->toContain('Appearance & Theme Settings');
});

test('super administrator can access all navigation modules', function () {
    $accessible = GlobalSearchService::getAccessibleNavigation($this->superAdmin);
    $all = GlobalSearchService::navigationCatalog();

    expect($accessible->count())->toBe(count($all));
});

test('role-based access control restricts modules for non-privileged roles', function () {
    $facultyAccessible = GlobalSearchService::getAccessibleNavigation($this->faculty);
    $facultyTitles = $facultyAccessible->pluck('title')->all();

    // Faculty has courses/batches/tests permissions, but NOT hostel or backup or system settings
    expect($facultyTitles)->toContain('Courses Management')
        ->and($facultyTitles)->not->toContain('Database & Media Backups')
        ->and($facultyTitles)->not->toContain('Buildings & Floors');
});

test('student portal account cannot access admin-only navigation items', function () {
    $studentAccessible = GlobalSearchService::getAccessibleNavigation($this->studentUser);
    $studentTitles = $studentAccessible->pluck('title')->all();

    expect($studentTitles)->not->toContain('Students Directory')
        ->and($studentTitles)->not->toContain('General System Settings')
        ->and($studentTitles)->not->toContain('Database & Media Backups');
});

test('global search searches courses and students respecting permissions', function () {
    $course = Course::create([
        'name' => 'Comprehensive UPSC Civil Services Mains 2026',
        'code' => 'UPSC-CS-2026',
        'fee_amount' => 25000,
        'is_active' => true,
    ]);

    // Super Admin searches for 'UPSC'
    $results = GlobalSearchService::search('UPSC', $this->superAdmin);

    expect($results['total'])->toBeGreaterThanOrEqual(1);

    $matchedCourse = collect($results['items'])->firstWhere('id', "course_{$course->id}");
    expect($matchedCourse)->not->toBeNull()
        ->and($matchedCourse['title'])->toBe('Comprehensive UPSC Civil Services Mains 2026');

    // Super admin searches for student
    $studentResults = GlobalSearchService::search('STU-999', $this->superAdmin);
    $matchedStudent = collect($studentResults['items'])->firstWhere('id', "student_{$this->studentUser->id}");
    expect($matchedStudent)->not->toBeNull()
        ->and($matchedStudent['title'])->toBe('Student Candidate');
});

test('viewport preferences fall back to system setting and allow user override', function () {
    Setting::set('appearance.default_fullscreen', 'normal');

    $prefs = GlobalSearchService::getViewportPreferences($this->superAdmin);
    expect($prefs['effective_fullscreen'])->toBe('normal')
        ->and($prefs['system_default_fullscreen'])->toBe('normal')
        ->and($prefs['user_default_fullscreen'])->toBeNull();

    // Update user preference
    GlobalSearchService::updateViewportPreferences($this->superAdmin, [
        'default_fullscreen' => 'fullscreen',
    ]);

    $updatedPrefs = GlobalSearchService::getViewportPreferences($this->superAdmin);
    expect($updatedPrefs['effective_fullscreen'])->toBe('fullscreen')
        ->and($updatedPrefs['user_default_fullscreen'])->toBe('fullscreen');
});

test('livewire global search modal component renders and filters by category', function () {
    Livewire::actingAs($this->superAdmin)
        ->test('global-search-modal')
        ->assertOk()
        ->set('search', 'attendance')
        ->assertSee('Attendance')
        ->set('category', 'Attendance & Shifts')
        ->assertSet('category', 'Attendance & Shifts')
        ->call('clear')
        ->assertSet('search', '')
        ->assertSet('category', 'all');
});

test('api endpoint GET /api/v1/search/global returns search results with api key', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/search/global?q=course&limit=5');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'query',
                'total',
                'categories',
                'items',
            ],
            'meta' => [
                'query',
                'total_results',
                'user_id',
            ],
        ]);
});

test('api endpoint GET /api/v1/search/modules returns structured hierarchy', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/search/modules');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'modules',
                'quick_actions',
                'total_modules',
                'total_navigation_items',
            ],
        ]);
});

test('api endpoint GET /api/v1/search/preferences returns preferences', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/search/preferences');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'effective_fullscreen',
                'user_default_fullscreen',
                'system_default_fullscreen',
            ],
        ]);
});

test('api endpoint POST /api/v1/search/preferences updates preferences', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/search/preferences', [
        'default_fullscreen' => 'fullscreen',
        'search_history_enabled' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.effective_fullscreen', 'fullscreen')
        ->assertJsonPath('data.user_default_fullscreen', 'fullscreen');
});

test('sanctum personal access token authenticates search api endpoints', function () {
    $response = $this->actingAs($this->superAdmin, 'sanctum')
        ->withHeaders(['Accept' => 'application/json'])
        ->getJson('/api/v1/search/global?q=settings');

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('unauthenticated search api request returns 401', function () {
    $response = $this->withHeaders([
        'Accept' => 'application/json',
    ])->getJson('/api/v1/search/global?q=test');

    $response->assertStatus(401);
});

test('global search catalog includes recaptcha and bot defense modules', function () {
    $catalog = GlobalSearchService::navigationCatalog();
    $actions = GlobalSearchService::quickActionsCatalog();

    $navIds = collect($catalog)->pluck('id')->all();
    expect($navIds)->toContain('nav_settings_captcha')
        ->and($navIds)->toContain('nav_captcha_logs')
        ->and($navIds)->toContain('nav_captcha_api_docs');

    $actionIds = collect($actions)->pluck('id')->all();
    expect($actionIds)->toContain('action_test_captcha')
        ->and($actionIds)->toContain('action_configure_bot_defense');
});

test('global search finds captcha settings, quick actions, and verification logs', function () {
    CaptchaLog::create([
        'session_id' => 'bot_suspect_sess_999',
        'ip_address' => '192.168.100.88',
        'driver' => 'custom',
        'action' => 'login',
        'status' => 'failed',
        'error_code' => 'mismatched-answer',
    ]);

    // 1. Search for keyword "captcha"
    $results = GlobalSearchService::search('captcha', $this->superAdmin);
    expect($results['total'])->toBeGreaterThanOrEqual(1);

    $matchedNav = collect($results['items'])->firstWhere('id', 'nav_settings_captcha');
    expect($matchedNav)->not->toBeNull()
        ->and($matchedNav['title'])->toBe('reCAPTCHA & Bot Defense Settings');

    // 2. Search for IP address in CaptchaLog
    $ipResults = GlobalSearchService::search('192.168.100.88', $this->superAdmin);
    expect($ipResults['total'])->toBeGreaterThanOrEqual(1);

    $matchedLog = collect($ipResults['items'])->firstWhere('type', 'captcha_log');
    expect($matchedLog)->not->toBeNull()
        ->and($matchedLog['subtitle'])->toContain('192.168.100.88');
});
