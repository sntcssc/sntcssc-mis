<?php

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    RbacService::seedDefaults();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);
    $keyData = $this->apiKeyService->generateKey([
        'name' => 'Academic Calendar Test Key',
        'type' => 'backend_service',
        'environment' => 'sandbox',
        'rate_limit_per_minute' => 300,
        'scopes' => ['*'],
        'description' => 'Test key with full permissions',
    ], $this->admin);

    $this->plainKey = $keyData['plainTextKey'];

    // Create seed calendar
    $this->calendar = AcademicCalendar::create([
        'calendar_code' => 'AC-API-2026',
        'academic_year' => '2026-2027',
        'title' => 'Annual Academic Calendar 2026-2027',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'total_working_days' => 248,
        'total_instructional_days' => 195,
        'status' => 'published',
        'is_current' => true,
    ]);
});

test('api lists academic calendars with pagination', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/academic-calendars');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.calendar_code', 'AC-API-2026');
});

test('api returns current active academic calendar', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/academic-calendars/current');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('calendar.academic_year', '2026-2027')
        ->assertJsonPath('calendar.is_current', true);
});

test('api creates a new academic calendar', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/academic-calendars', [
            'academic_year' => '2027-2028',
            'title' => 'New Session Calendar 2027-2028',
            'start_date' => '2027-04-01',
            'end_date' => '2028-03-31',
            'total_working_days' => 240,
            'total_instructional_days' => 190,
            'status' => 'published',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('calendar.academic_year', '2027-2028');

    $this->assertDatabaseHas('academic_calendars', [
        'academic_year' => '2027-2028',
    ]);
});

test('api updates an academic calendar', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/academic-calendars/{$this->calendar->id}", [
            'title' => 'Updated Academic Calendar Title',
            'total_working_days' => 250,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('calendar.title', 'Updated Academic Calendar Title')
        ->assertJsonPath('calendar.total_working_days', 250);
});

test('api sets calendar as currently active session', function () {
    $newCalendar = AcademicCalendar::create([
        'calendar_code' => 'AC-API-NEXT',
        'academic_year' => '2028-2029',
        'title' => 'Future Calendar',
        'start_date' => '2028-04-01',
        'end_date' => '2029-03-31',
        'status' => 'published',
        'is_current' => false,
    ]);

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/academic-calendars/{$newCalendar->id}/set-current");

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($newCalendar->fresh()->is_current)->toBeTrue();
    expect($this->calendar->fresh()->is_current)->toBeFalse();
});

test('api uploads new revision document and maintains version history', function () {
    $dummyFile = UploadedFile::fake()->create('calendar_revision.pdf', 300, 'application/pdf');

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/academic-calendars/{$this->calendar->id}/versions", [
            'file' => $dummyFile,
            'version_code' => 'v1.1',
            'version_title' => 'Mid-Term Revision',
            'change_summary' => 'Rescheduled vacation dates.',
            'approved_by' => 'Academic Board',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('version.version_code', 'v1.1')
        ->assertJsonPath('version.is_current', true);

    $this->assertDatabaseHas('academic_calendar_versions', [
        'academic_calendar_id' => $this->calendar->id,
        'version_code' => 'v1.1',
        'is_current' => 1,
    ]);

    // List versions endpoint
    $listResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/academic-calendars/{$this->calendar->id}/versions");

    $listResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'versions');
});

test('api performs activity CRUD operations', function () {
    // 1. Create activity
    $createResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/academic-calendars/{$this->calendar->id}/activities", [
            'title' => 'UPSC Mains Mock Test Drive',
            'category' => 'examination',
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-12',
            'target_audience' => 'aspirants',
            'department' => 'Examination Cell',
            'venue_mode' => 'offline',
            'venue_or_link' => 'Halls 1, 2 & 3',
            'status' => 'planned',
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('activity.title', 'UPSC Mains Mock Test Drive');

    $activityId = $createResponse->json('activity.id');

    // 2. List activities
    $listResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/academic-calendars/{$this->calendar->id}/activities");

    $listResponse->assertOk()
        ->assertJsonPath('total', 1);

    // 3. Update activity
    $updateResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/academic-calendars/{$this->calendar->id}/activities/{$activityId}", [
            'title' => 'UPSC Mains Mock Test Drive (Rescheduled)',
            'status' => 'ongoing',
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('activity.title', 'UPSC Mains Mock Test Drive (Rescheduled)');

    // 4. Delete activity
    $deleteResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/academic-calendars/{$this->calendar->id}/activities/{$activityId}");

    $deleteResponse->assertOk()
        ->assertJsonPath('success', true);

    expect(AcademicCalendarActivity::find($activityId))->toBeNull();
});

test('api student academic-calendar endpoint returns active calendar and events', function () {
    AcademicCalendarActivity::create([
        'academic_calendar_id' => $this->calendar->id,
        'activity_code' => 'ACT-2026-0001',
        'title' => 'Independence Day Holiday',
        'category' => 'holiday',
        'start_date' => '2026-08-15',
        'target_audience' => 'all',
        'venue_mode' => 'offline',
        'status' => 'planned',
    ]);

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/student/academic-calendar');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('calendar.code', 'AC-API-2026')
        ->assertJsonCount(1, 'activities');
});

test('api app-info endpoint advertises academic calendar as supported module', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/app/info');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.supported_modules.academic_calendar', true);
});

test('api key service scopes include academic calendar support', function () {
    $scopes = ApiKeyService::getAvailableScopes();

    expect($scopes)->toHaveKey('Academics')
        ->and($scopes['Academics']['read:academics'])->toContain('academic calendar')
        ->and($scopes['Academics']['write:academics'])->toContain('academic calendar');
});

test('academic calendar integration documentation guide exists and is non-empty', function () {
    $path = base_path('docs/integrations/academic-calendar-and-version-control-api.md');

    expect(file_exists($path))->toBeTrue();
    $content = file_get_contents($path);
    expect($content)->toContain('Academic Calendar & Version Control System REST API')
        ->and($content)->toContain('/api/v1/academic-calendars')
        ->and($content)->toContain('/api/v1/academic-calendars/current');
});

test('developer console component loads academic calendar integration guide', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->call('openGuide', 'academic_calendar')
        ->assertSet('showGuideModal', true)
        ->assertSee('Academic Calendar &amp; Version Control System REST API', false)
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});

test('developer console component contains academic calendar endpoints and card', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('/api/v1/academic-calendars')
        ->assertSee('Academic Calendar &amp; Version Control System API Guide', false);
});
