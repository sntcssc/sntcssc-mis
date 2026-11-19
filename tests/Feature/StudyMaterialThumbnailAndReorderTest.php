<?php

use App\Models\ApiKey;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use App\Services\StudyMaterial\StudyMaterialService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->student = User::factory()->create();
    $this->student->assignRole('Student');

    $this->course = Course::first() ?? Course::create([
        'name' => 'UPSC Civil Services Foundation',
        'code' => 'UPSC-CSF',
        'fee' => 5000,
        'status' => 'active',
    ]);

    $this->batch = Batch::first() ?? Batch::create([
        'name' => '2026 Morning Batch',
        'code' => 'BATCH-2026-M',
        'academic_year' => '2026',
        'status' => 'active',
        'course_id' => $this->course->id,
    ]);

    Enrollment::create([
        'enrollment_no' => 'ENR-TEST-0001',
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'status' => Enrollment::STATUS_ACTIVE,
        'enrolled_at' => now(),
    ]);

    Storage::fake('public');
});

test('admin can create study material with class date and custom thumbnail url', function () {
    $this->actingAs($this->admin);
    $service = app(StudyMaterialService::class);

    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Polity Class Notes - Day 14',
        'subject' => 'Indian Polity',
        'topic' => 'Directive Principles of State Policy',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'class_date' => '2026-10-24',
        'thumbnail_url' => 'https://example.com/thumbnails/dpsp-lecture.jpg',
        'display_order' => 5,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    expect($material->class_date?->format('Y-m-d'))->toBe('2026-10-24')
        ->and($material->thumbnail_url)->toBe('https://example.com/thumbnails/dpsp-lecture.jpg')
        ->and($material->thumbnail_image_url)->toBe('https://example.com/thumbnails/dpsp-lecture.jpg')
        ->and($material->display_order)->toBe(5);
});

test('admin can upload a thumbnail image file for study material', function () {
    $this->actingAs($this->admin);
    $service = app(StudyMaterialService::class);

    $thumbnail = UploadedFile::fake()->image('dpsp-cover.jpg', 640, 480);

    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Economy Class - Fiscal Deficit',
        'material_type' => StudyMaterial::TYPE_SLIDE_DECK,
        'class_date' => '2026-10-25',
        'thumbnail_file' => $thumbnail,
    ]);

    expect($material->thumbnail_path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($material->thumbnail_path))->toBeTrue()
        ->and($material->thumbnail_image_url)->toContain('storage/'.$material->thumbnail_path);
});

test('updating study material can replace or remove thumbnail and update class date', function () {
    $this->actingAs($this->admin);
    $service = app(StudyMaterialService::class);

    $initialThumbnail = UploadedFile::fake()->image('old-cover.png');
    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Ancient History',
        'class_date' => '2026-10-20',
        'thumbnail_file' => $initialThumbnail,
    ]);

    $oldPath = $material->thumbnail_path;
    expect(Storage::disk('public')->exists($oldPath))->toBeTrue();

    // 1. Replace with new file
    $newThumbnail = UploadedFile::fake()->image('new-cover.jpg');
    $updated = $service->updateMaterial($material, [
        'title' => 'Ancient History - Indus Valley',
        'class_date' => '2026-10-21',
        'thumbnail_file' => $newThumbnail,
        'display_order' => 12,
    ]);

    expect(Storage::disk('public')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('public')->exists($updated->thumbnail_path))->toBeTrue()
        ->and($updated->class_date?->format('Y-m-d'))->toBe('2026-10-21')
        ->and($updated->display_order)->toBe(12);

    // 2. Remove thumbnail
    $cleared = $service->updateMaterial($updated, [
        'title' => 'Ancient History - Indus Valley',
        'remove_thumbnail' => true,
        'thumbnail_url' => 'https://cdn.example.com/fallback.webp',
    ]);

    expect($cleared->thumbnail_path)->toBeNull()
        ->and($cleared->thumbnail_url)->toBe('https://cdn.example.com/fallback.webp')
        ->and($cleared->thumbnail_image_url)->toBe('https://cdn.example.com/fallback.webp');
});

test('reordering study materials via moveDisplayOrder and updateDisplayOrder works correctly', function () {
    $this->actingAs($this->admin);
    $service = app(StudyMaterialService::class);

    $matA = $service->createMaterial(['title' => 'Material A', 'display_order' => 10, 'material_type' => StudyMaterial::TYPE_CURRENT_AFFAIRS]);
    $matB = $service->createMaterial(['title' => 'Material B', 'display_order' => 20, 'material_type' => StudyMaterial::TYPE_CURRENT_AFFAIRS]);
    $matC = $service->createMaterial(['title' => 'Material C', 'display_order' => 30, 'material_type' => StudyMaterial::TYPE_CURRENT_AFFAIRS]);

    // Move MatB up -> swaps order with MatA
    $service->moveDisplayOrder($matB, 'up');
    $matA->refresh();
    $matB->refresh();

    expect($matB->display_order)->toBe(10)
        ->and($matA->display_order)->toBe(20);

    // Update MatC explicit order to 1
    $service->updateDisplayOrder($matC, 1);
    expect($matC->refresh()->display_order)->toBe(1);

    // Ordered query check: C (order 1), B (order 10), A (order 20)
    $orderedIds = StudyMaterial::ordered()->whereIn('id', [$matA->id, $matB->id, $matC->id])->pluck('id')->all();
    expect($orderedIds)->toBe([$matC->id, $matB->id, $matA->id]);
});

test('livewire admin component supports reorder actions and displays class date', function () {
    $this->actingAs($this->admin);

    $mat1 = StudyMaterial::create([
        'title' => 'Geography Notes - Module 1',
        'course_id' => $this->course->id,
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'class_date' => '2026-10-26',
        'display_order' => 5,
        'status' => StudyMaterial::STATUS_PUBLISHED,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
    ]);

    $mat2 = StudyMaterial::create([
        'title' => 'Geography Notes - Module 2',
        'course_id' => $this->course->id,
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'class_date' => '2026-10-27',
        'display_order' => 15,
        'status' => StudyMaterial::STATUS_PUBLISHED,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
    ]);

    Livewire::test('pages::admin.study-materials.index')
        ->assertSee('Geography Notes - Module 1')
        ->assertSee('26 Oct 2026')
        ->call('moveOrder', $mat2->id, 'up');

    expect($mat2->refresh()->display_order)->toBe(5)
        ->and($mat1->refresh()->display_order)->toBe(15);
});

test('student portal respects admin display order and shows class date badge', function () {
    $this->actingAs($this->student);

    $matSecond = StudyMaterial::create([
        'title' => 'Science & Tech - Nanotechnology',
        'course_id' => $this->course->id,
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'class_date' => '2026-10-22',
        'display_order' => 2,
        'thumbnail_url' => 'https://example.com/nano.jpg',
        'status' => StudyMaterial::STATUS_PUBLISHED,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
    ]);
    $matSecond->batches()->sync([$this->batch->id]);

    $matFirst = StudyMaterial::create([
        'title' => 'Science & Tech - Biotechnology',
        'course_id' => $this->course->id,
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'class_date' => '2026-10-20',
        'display_order' => 1,
        'status' => StudyMaterial::STATUS_PUBLISHED,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
    ]);
    $matFirst->batches()->sync([$this->batch->id]);

    Livewire::test('pages::student.study-materials')
        ->assertSee('Science & Tech - Biotechnology')
        ->assertSee('Science & Tech - Nanotechnology')
        ->assertSee('Class: 20 Oct 2026')
        ->assertSee('Class: 22 Oct 2026');
});

test('external study materials api includes thumbnail_url, class_date, and display_order', function () {
    $apiKeyService = app(ApiKeyService::class);
    $keyData = $apiKeyService->generateKey([
        'name' => 'Integration Tester',
        'scopes' => ['read:materials', 'download:materials'],
        'environment' => ApiKey::ENV_PRODUCTION,
        'rate_limit_per_minute' => 120,
    ], $this->admin);

    $plainKey = $keyData['plainTextKey'];

    $material = StudyMaterial::create([
        'title' => 'October Current Affairs Comprehensive',
        'material_type' => StudyMaterial::TYPE_CURRENT_AFFAIRS,
        'class_date' => '2026-10-26',
        'display_order' => 1,
        'thumbnail_url' => 'https://mis.sntcssc.in/covers/ca-oct-2026.png',
        'status' => StudyMaterial::STATUS_PUBLISHED,
        'access_level' => StudyMaterial::ACCESS_PUBLIC_LINK,
    ]);

    $response = $this->withHeaders([
        'X-Api-Key' => $plainKey,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/external/study-materials');

    $response->assertOk()
        ->assertJsonPath('data.0.title', 'October Current Affairs Comprehensive')
        ->assertJsonPath('data.0.class_date', '2026-10-26')
        ->assertJsonPath('data.0.thumbnail_url', 'https://mis.sntcssc.in/covers/ca-oct-2026.png')
        ->assertJsonPath('data.0.display_order', 1);

    // Single item show endpoint
    $showResponse = $this->withHeaders([
        'X-Api-Key' => $plainKey,
        'Accept' => 'application/json',
    ])->getJson("/api/v1/external/study-materials/{$material->id}");

    $showResponse->assertOk()
        ->assertJsonPath('data.thumbnail_url', 'https://mis.sntcssc.in/covers/ca-oct-2026.png')
        ->assertJsonPath('data.class_date', '2026-10-26')
        ->assertJsonPath('data.display_order', 1);
});
