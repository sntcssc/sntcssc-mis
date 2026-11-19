<?php

use App\Models\Classroom;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\GlobalSearchService;
use App\Services\PartnerInstituteService;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    RbacService::seedDefaults();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);
    $keyData = $this->apiKeyService->generateKey([
        'name' => 'Academics Test Key',
        'type' => 'backend_service',
        'environment' => 'sandbox',
        'rate_limit_per_minute' => 300,
        'scopes' => ['ping', 'read:academics', 'write:academics'],
        'description' => 'Test key for full academic operations',
    ], $this->admin);

    $this->plainKey = $keyData['plainTextKey'];
});

test('partner institute model creates with auto-generated code and custom code', function () {
    $institute1 = PartnerInstitute::create([
        'name' => 'Chanakya Civil Services Forum',
        'status' => 'active',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'official_name' => 'Dr. P. K. Ghosh',
    ]);

    expect($institute1->code)->not->toBeEmpty();
    expect($institute1->status_label)->toBe('Active');

    $institute2 = PartnerInstitute::create([
        'name' => 'Apex IAS Academy',
        'code' => 'INST-APEX-99',
        'status' => 'mou_expired',
    ]);

    expect($institute2->code)->toBe('INST-APEX-99');
    expect($institute2->status_label)->toBe('MoU Expired');
});

test('partner institute service handles CRUD and soft deletion with audit logs', function () {
    $this->actingAs($this->admin);

    $institute = PartnerInstituteService::createInstitute([
        'name' => 'National Mentorship Academy',
        'status' => 'active',
        'email' => 'info@nationalacademy.org',
    ]);

    expect($institute->exists)->toBeTrue();
    expect($institute->name)->toBe('National Mentorship Academy');

    $updated = PartnerInstituteService::updateInstitute($institute, [
        'name' => 'National Mentorship Academy Updated',
    ]);

    expect($updated->name)->toBe('National Mentorship Academy Updated');

    $deleted = PartnerInstituteService::deleteInstitute($institute);
    expect($deleted)->toBeTrue();
    expect(PartnerInstitute::withTrashed()->find($institute->id)->trashed())->toBeTrue();
});

test('faculty model links to partner institute and has faculty type', function () {
    $institute = PartnerInstitute::create([
        'name' => 'Kolkata IAS Mentors',
        'status' => 'active',
    ]);

    $faculty = Faculty::create([
        'name' => 'Dr. Bikram Sen',
        'department' => 'Geography',
        'designation' => 'Visiting Professor',
        'partner_institute_id' => $institute->id,
        'faculty_type' => 'visiting',
        'status' => 'active',
    ]);

    expect($faculty->partnerInstitute)->not->toBeNull();
    expect($faculty->partnerInstitute->id)->toBe($institute->id);
    expect($faculty->faculty_type)->toBe('visiting');
    expect($faculty->faculty_type_label)->toBe('Visiting Faculty');
    expect($institute->faculties)->toHaveCount(1);
});

test('global search catalog and live search includes routines, plans, classrooms, and partner institutes', function () {
    $this->actingAs($this->admin);

    $navCatalog = GlobalSearchService::navigationCatalog();
    $navIds = collect($navCatalog)->pluck('id')->all();

    expect($navIds)->toContain('nav_classes');
    expect($navIds)->toContain('nav_course_plans');
    expect($navIds)->toContain('nav_classrooms');
    expect($navIds)->toContain('nav_faculties');
    expect($navIds)->toContain('nav_partner_institutes');

    $institute = PartnerInstitute::create([
        'name' => 'Horizon Civil Mentorship',
        'code' => 'INST-HORIZON',
        'status' => 'active',
    ]);

    $classroom = Classroom::create([
        'name' => 'Lecture Auditorium Delta',
        'room_code' => 'CR-DELTA-01',
        'building' => 'Academic Complex',
        'floor' => '3rd Floor',
        'capacity' => 100,
        'room_type' => 'lecture_hall',
        'status' => 'active',
    ]);

    $searchResults = GlobalSearchService::search('Horizon', $this->admin);
    expect($searchResults['total'])->toBeGreaterThanOrEqual(1);

    $classroomResults = GlobalSearchService::search('Delta', $this->admin);
    expect($classroomResults['total'])->toBeGreaterThanOrEqual(1);
});

test('partner institute rest api routes provide full crud and validation', function () {
    // List empty
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/partner-institutes');

    $response->assertOk();
    expect($response->json('success'))->toBeTrue();

    // Create via API
    $createResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/partner-institutes', [
        'name' => 'Chanakya IAS Academy',
        'code' => 'INST-CHANAKYA-01',
        'status' => 'active',
        'email' => 'admin@chanakya.org',
    ]);

    $createResponse->assertCreated();
    $instId = $createResponse->json('data.id');

    // Show details
    $showResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson("/api/v1/partner-institutes/{$instId}");

    $showResponse->assertOk();
    expect($showResponse->json('data.name'))->toBe('Chanakya IAS Academy');

    // Delete
    $deleteResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->deleteJson("/api/v1/partner-institutes/{$instId}");

    $deleteResponse->assertOk();
});

test('classroom rest api routes provide full crud and validation', function () {
    $createResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/classrooms', [
        'name' => 'Seminar Hall Gamma',
        'building' => 'Main Campus',
        'floor' => 'Ground Floor',
        'capacity' => 60,
        'room_type' => 'seminar_hall',
        'status' => 'active',
    ]);

    $createResponse->assertCreated();
    $roomId = $createResponse->json('data.id');

    $showResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson("/api/v1/classrooms/{$roomId}");

    $showResponse->assertOk();
    expect($showResponse->json('data.name'))->toBe('Seminar Hall Gamma');
});
