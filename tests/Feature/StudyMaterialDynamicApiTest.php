<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialFile;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Study Material Admin']);
    $this->admin->assignRole('Super Administrator');

    $this->course = Course::first() ?? Course::factory()->create(['name' => 'UPSC CSE Civil Services']);
    $this->batch = Batch::first() ?? Batch::factory()->create(['course_id' => $this->course->id]);

    $this->apiKeyService = app(ApiKeyService::class);

    // Full key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Study Material Full Integration Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:materials', 'write:materials', 'download:materials', 'ping'],
        'description' => 'Test key with full study material permissions',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Readonly key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Study Material Readonly Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['read:materials'],
        'description' => 'Read only study material key',
    ], $this->admin);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];
});

test('administrative study material creation requires write scope', function () {
    $payload = [
        'title' => 'Indian Economy: Monetary Policy Framework 2026',
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'subject' => 'General Studies Paper III',
        'topic' => 'Monetary Policy & Inflation Targeting',
        'module_code' => 'ECON-MOD-01',
        'material_type' => 'lecture_notes',
        'access_level' => 'enrolled_only',
        'status' => 'published',
    ];

    // Read-only key fails with 403
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/study-materials', $payload)
        ->assertStatus(403);

    // Full key succeeds with 201
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/study-materials', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Indian Economy: Monetary Policy Framework 2026')
        ->assertJsonPath('data.module_code', 'ECON-MOD-01');

    $this->assertDatabaseHas('study_materials', [
        'title' => 'Indian Economy: Monetary Policy Framework 2026',
    ]);
});

test('can update and delete study material', function () {
    $material = StudyMaterial::create([
        'title' => 'Geography: Indian Drainage System',
        'course_id' => $this->course->id,
        'access_level' => 'enrolled_only',
        'status' => 'published',
    ]);

    // Update
    $updateRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/study-materials/{$material->id}", [
            'title' => 'Geography: Indian Drainage System (Updated Edition)',
            'subject' => 'General Studies Paper I',
            'is_pinned' => true,
        ]);

    $updateRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Geography: Indian Drainage System (Updated Edition)')
        ->assertJsonPath('data.is_pinned', true);

    // Delete
    $deleteRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/study-materials/{$material->id}");

    $deleteRes->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('study_materials', ['id' => $material->id]);
});

test('can upload and delete file attachments for study material', function () {
    $material = StudyMaterial::create([
        'title' => 'Environment & Ecology Compendium',
        'course_id' => $this->course->id,
        'status' => 'published',
    ]);

    $fakeFile = UploadedFile::fake()->create('ecology_handout.pdf', 1024, 'application/pdf');

    $uploadRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/study-materials/{$material->id}/files", [
            'files' => [$fakeFile],
        ]);

    $uploadRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');

    $fileId = $uploadRes->json('data.0.id');

    // Delete file
    $delFileRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/study-materials/{$material->id}/files/{$fileId}");

    $delFileRes->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('study_material_files', ['id' => $fileId]);
});

test('dynamic study material types catalog and management', function () {
    // 1. List types
    $listRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/study-materials/types');

    $listRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'code', 'icon']]]);

    // 2. Create custom type
    $typeRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/study-materials/types', [
            'name' => 'Topper Answer Copies',
            'code' => 'topper_answer_copies',
            'description' => 'Evaluated answer copies of selected UPSC candidates.',
            'icon' => 'award',
            'badge_color' => 'indigo',
            'is_public' => false,
            'is_active' => true,
        ]);

    $typeRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', 'topper_answer_copies');

    $typeId = $typeRes->json('data.id');

    // 3. Update custom type
    $updateTypeRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/study-materials/types/{$typeId}", [
            'name' => 'UPSC Topper Answer Copies',
        ]);

    $updateTypeRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'UPSC Topper Answer Copies');
});

test('client telemetry records view event and increments count', function () {
    $material = StudyMaterial::create([
        'title' => 'Ethics: Case Studies on Civil Service Integrity',
        'course_id' => $this->course->id,
        'status' => 'published',
        'view_count' => 5,
    ]);

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->withHeader('X-Client-Platform', 'flutter_mobile')
        ->postJson("/api/v1/study-materials/{$material->id}/track-view");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('view_count', 6);

    $this->assertDatabaseHas('study_material_access_logs', [
        'study_material_id' => $material->id,
        'action' => 'view',
    ]);
});

test('secure signed token generation and binary file download', function () {
    $material = StudyMaterial::create([
        'title' => 'Current Affairs Monthly Compendium',
        'course_id' => $this->course->id,
        'status' => 'published',
    ]);

    $storedPath = 'study-materials/test_file.pdf';
    Storage::disk('public')->put($storedPath, 'Sample PDF content');

    $file = StudyMaterialFile::create([
        'study_material_id' => $material->id,
        'file_name' => 'CA_October_2026.pdf',
        'file_path' => $storedPath,
        'file_size' => 18,
        'file_type' => 'pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'download_count' => 0,
    ]);

    // 1. Generate token
    $tokenRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/study-materials/{$material->id}/download-token");

    $tokenRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['token', 'download_url', 'expires_in_seconds']);

    $token = $tokenRes->json('token');

    // 2. Stream binary download using token without API key
    $downloadRes = $this->get("/api/v1/study-materials/download/{$token}");

    $downloadRes->assertStatus(200)
        ->assertHeader('Content-Disposition', 'attachment; filename=CA_October_2026.pdf');
});

test('student personalized feed returns enrolled batch materials', function () {
    $studentUser = User::factory()->create();

    Enrollment::create([
        'user_id' => $studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'status' => 'enrolled',
        'enrolled_at' => now(),
    ]);

    $material = StudyMaterial::create([
        'title' => 'Batch Specific Handout',
        'course_id' => $this->course->id,
        'access_level' => 'enrolled_only',
        'status' => 'published',
    ]);
    $material->batches()->sync([$this->batch->id]);

    // Query personalized feed
    $response = $this->actingAs($studentUser)
        ->getJson('/api/v1/study-materials/my-materials');

    $response->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

test('can download all material files bundled into a single zip archive', function () {
    $material = StudyMaterial::create([
        'title' => 'Complete Polity Handouts',
        'course_id' => $this->course->id,
        'access_level' => 'public_link',
        'status' => 'published',
    ]);

    $path1 = 'study-materials/polity_part1.pdf';
    $path2 = 'study-materials/polity_part2.pdf';
    Storage::disk('public')->put($path1, 'Part 1 Notes');
    Storage::disk('public')->put($path2, 'Part 2 Notes');

    StudyMaterialFile::create([
        'study_material_id' => $material->id,
        'file_name' => 'polity_part1.pdf',
        'file_path' => $path1,
        'file_size' => 12,
        'file_type' => 'pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'download_count' => 0,
    ]);

    StudyMaterialFile::create([
        'study_material_id' => $material->id,
        'file_name' => 'polity_part2.pdf',
        'file_path' => $path2,
        'file_size' => 12,
        'file_type' => 'pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'download_count' => 0,
    ]);

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->get("/api/v1/study-materials/{$material->id}/download-zip");

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/zip');
});
