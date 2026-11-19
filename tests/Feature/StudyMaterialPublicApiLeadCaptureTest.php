<?php

use App\Models\ApiKey;
use App\Models\Batch;
use App\Models\Course;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialType;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use App\Services\StudyMaterial\StudyMaterialService;
use App\Services\StudyMaterial\StudyMaterialTypeService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->course = Course::first() ?? Course::factory()->create(['name' => 'UPSC CSE Civil Services']);
    $this->batch = Batch::first() ?? Batch::factory()->create(['course_id' => $this->course->id]);

    $this->apiKeyService = app(ApiKeyService::class);
    $this->studyMaterialService = app(StudyMaterialService::class);
    $this->typeService = app(StudyMaterialTypeService::class);

    // Generate test API Key with material scopes
    $keyData = $this->apiKeyService->generateKey([
        'name' => 'External News & PIB Portal',
        'type' => ApiKey::TYPE_EXTERNAL_APP,
        'environment' => ApiKey::ENV_PRODUCTION,
        'rate_limit_per_minute' => 100,
        'scopes' => ['read:materials', 'download:materials'],
        'description' => 'Test key for external webapp integration',
    ], $this->admin);

    $this->plainKey = $keyData['plainTextKey'];
    $this->apiKey = $keyData['apiKey'];
});

test('unauthenticated external request without api key is rejected with 401', function () {
    $response = $this->getJson(route('api.v1.external.study-materials.index'));

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'API_KEY_MISSING');
});

test('external request with api key lacking material scope is rejected with 403', function () {
    $limitedKey = $this->apiKeyService->generateKey([
        'name' => 'Ping Only Key',
        'scopes' => ['ping'],
    ], $this->admin);

    $response = $this->withHeaders([
        'X-API-Key' => $limitedKey['plainTextKey'],
    ])->getJson(route('api.v1.external.study-materials.index'));

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');
});

test('external client can list public study material types including PIB Sandhan and Current Affairs', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->getJson(route('api.v1.external.study-materials.types'));

    $response->assertOk()
        ->assertJsonPath('success', true);

    $data = $response->json('data');
    $codes = collect($data)->pluck('code')->all();

    expect($codes)->toContain('pib_sandhan');
    expect($codes)->toContain('current_affairs');
});

test('admin can dynamically create, edit, toggle, and delete custom material types', function () {
    $this->actingAs($this->admin);

    // 1. Create custom type
    $type = $this->typeService->createType([
        'name' => 'Mains Model Answers 2026',
        'code' => 'mains_answers_2026',
        'description' => 'Detailed model answers and blueprint for UPSC Mains GS Papers',
        'icon' => 'clipboard-check',
        'badge_color' => 'indigo',
        'is_public' => true,
        'is_active' => true,
    ], $this->admin);

    expect($type->code)->toBe('mains_answers_2026');
    expect($type->name)->toBe('Mains Model Answers 2026');
    expect($type->is_public)->toBeTrue();

    // Verify it is dynamically listed in activeOptions
    $options = StudyMaterialType::activeOptions();
    expect($options)->toHaveKey('mains_answers_2026');

    // 2. Update type
    $updated = $this->typeService->updateType($type, [
        'name' => 'Mains Model Answers & Synopsis 2026',
    ], $this->admin);

    expect($updated->name)->toBe('Mains Model Answers & Synopsis 2026');

    // 3. Toggle status
    $toggled = $this->typeService->toggleStatus($type, $this->admin);
    expect($toggled->is_active)->toBeFalse();

    // Re-activate
    $this->typeService->toggleStatus($type, $this->admin);

    // 4. Delete type
    $deleted = $this->typeService->deleteType($type, $this->admin);
    expect($deleted)->toBeTrue();
    expect(StudyMaterialType::where('code', 'mains_answers_2026')->exists())->toBeFalse();
});

test('external client can query study materials by dynamic type like PIB Sandhan and Current Affairs', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('pib_sandhan_oct_2026.pdf', 800, 'application/pdf');

    // Create PIB Sandhan material
    $material = $this->studyMaterialService->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'PIB Sandhan - October 2026 Edition',
        'subject' => 'Current Affairs & Policy',
        'topic' => 'Cabinet Decisions & Economic Reforms',
        'material_type' => StudyMaterial::TYPE_PIB_SANDHAN,
        'status' => StudyMaterial::STATUS_PUBLISHED,
        'access_level' => StudyMaterial::ACCESS_PUBLIC_LINK,
        'description' => 'Comprehensive digest of PIB releases for the month of October 2026.',
    ], [$file]);

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->getJson(route('api.v1.external.study-materials.index', ['type' => 'pib_sandhan']));

    $response->assertOk()
        ->assertJsonPath('success', true);

    $items = $response->json('data');
    expect($items)->not->toBeEmpty();
    expect($items[0]['title'])->toBe('PIB Sandhan - October 2026 Edition');
    expect($items[0]['material_type']['code'])->toBe('pib_sandhan');
    expect($items[0]['files'])->toHaveCount(1);
    expect($items[0]['files'][0]['file_name'])->toBe('pib_sandhan_oct_2026.pdf');
});

test('new unverified user initiating download triggers OTP verification session', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('ca_october.pdf', 500, 'application/pdf');

    $material = $this->studyMaterialService->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Monthly Current Affairs - October 2026',
        'material_type' => StudyMaterial::TYPE_CURRENT_AFFAIRS,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ], [$file]);

    $payload = [
        'first_name' => 'Arijit',
        'last_name' => 'Banerjee',
        'email' => 'arijit.banerjee@testexample.org',
        'phone' => '+919876543210',
    ];

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->postJson(route('api.v1.external.study-materials.download-request', ['identifier' => $material->id]), $payload);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('requires_verification', true)
        ->assertJsonStructure([
            'session_token',
            'channels' => ['email', 'phone'],
        ]);

    $sessionToken = $response->json('session_token');
    expect(Cache::has('study_mat_sess_'.$sessionToken))->toBeTrue();
});

test('public user verifying OTP receives download token and is recorded in subscribers table as lead', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('ca_october_edition.pdf', 600, 'application/pdf');

    $material = $this->studyMaterialService->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Monthly Current Affairs - October 2026',
        'material_type' => StudyMaterial::TYPE_CURRENT_AFFAIRS,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ], [$file]);

    $fileId = $material->files->first()->id;

    $initPayload = [
        'first_name' => 'Debolina',
        'last_name' => 'Roy',
        'email' => 'debolina.roy@sampleupsc.org',
        'phone' => '+919830112233',
        'file_id' => $fileId,
    ];

    $initResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->postJson(route('api.v1.external.study-materials.download-request', ['identifier' => $material->id]), $initPayload);

    $initResponse->assertOk()
        ->assertJsonPath('requires_verification', true);

    $sessionToken = $initResponse->json('session_token');
    $plainCode = $initResponse->json('debug_otp');
    expect($plainCode)->not->toBeNull();

    $verifyResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->postJson(route('api.v1.external.study-materials.verify-otp'), [
        'session_token' => $sessionToken,
        'otp_code' => $plainCode,
    ]);

    $verifyResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('verified', true)
        ->assertJsonStructure([
            'download_token',
            'download_url',
            'subscriber' => ['id', 'name', 'email', 'phone'],
        ]);

    // Verify user was recorded in subscribers table
    $subscriber = Subscriber::where('email', 'debolina.roy@sampleupsc.org')->first();
    expect($subscriber)->not->toBeNull();
    expect($subscriber->name)->toBe('Debolina Roy');
    expect($subscriber->phone)->toBe('+919830112233');
    expect($subscriber->source)->toBe(Subscriber::SOURCE_STUDY_MATERIAL);
    expect($subscriber->status)->toBe(Subscriber::STATUS_ACTIVE);
    expect($subscriber->tags)->toContain('study_materials_lead');

    // Verify access log was recorded
    $log = StudyMaterialAccessLog::where('study_material_id', $material->id)
        ->where('subscriber_id', $subscriber->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->action)->toBe(StudyMaterialAccessLog::ACTION_DOWNLOAD);
    expect($log->is_verified)->toBeTrue();
    expect($log->guest_email)->toBe('debolina.roy@sampleupsc.org');

    // Verify download token works
    $downloadToken = $verifyResponse->json('download_token');
    $downloadResponse = $this->get(route('api.v1.external.study-materials.download', ['token' => $downloadToken]));
    $downloadResponse->assertOk();
});

test('existing verified subscriber bypasses OTP verification and accesses download immediately', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('pib_sandhan_nov.pdf', 700, 'application/pdf');

    $material = $this->studyMaterialService->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'PIB Sandhan - November 2026',
        'material_type' => StudyMaterial::TYPE_PIB_SANDHAN,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ], [$file]);

    // Pre-create existing active subscriber
    $existingSubscriber = Subscriber::create([
        'uuid' => (string) Str::uuid(),
        'type' => Subscriber::TYPE_BOTH,
        'name' => 'Sourav Ganguly',
        'email' => 'sourav.ganguly@bengalcricket.in',
        'phone' => '+919831000001',
        'status' => Subscriber::STATUS_ACTIVE,
        'source' => 'welcome_page',
        'unsubscribe_token' => Str::random(40),
    ]);

    $payload = [
        'first_name' => 'Sourav',
        'last_name' => 'Ganguly',
        'email' => 'sourav.ganguly@bengalcricket.in',
        'phone' => '+919831000001',
    ];

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->postJson(route('api.v1.external.study-materials.download-request', ['identifier' => $material->id]), $payload);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('requires_verification', false)
        ->assertJsonStructure([
            'download_token',
            'download_url',
            'subscriber',
        ]);

    // Download using token
    $token = $response->json('download_token');
    $downloadResponse = $this->get(route('api.v1.external.study-materials.download', ['token' => $token]));
    $downloadResponse->assertOk();

    // Material download counter incremented
    expect($material->fresh()->download_count)->toBeGreaterThan(0);
});

test('admin study materials livewire component supports dynamic custom material types management', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.study-materials.index')
        ->call('openTypesModal')
        ->assertSet('showTypesModal', true)
        ->call('openCreateTypeModal')
        ->assertSet('showTypeFormModal', true)
        ->set('typeForm.name', 'Daily Editorial Synopsis')
        ->set('typeForm.code', 'daily_editorial_synopsis')
        ->set('typeForm.description', 'Daily editorial synthesis from The Hindu & Indian Express')
        ->set('typeForm.icon', 'newspaper')
        ->set('typeForm.badge_color', 'emerald')
        ->set('typeForm.is_public', true)
        ->call('saveType')
        ->assertHasNoErrors()
        ->assertSet('showTypeFormModal', false);

    expect(StudyMaterialType::where('code', 'daily_editorial_synopsis')->exists())->toBeTrue();

    // Verify it is available in options
    $types = StudyMaterialType::activeOptions();
    expect($types)->toHaveKey('daily_editorial_synopsis');
});
