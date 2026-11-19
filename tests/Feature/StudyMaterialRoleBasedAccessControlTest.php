<?php

use App\Models\ApiKey;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialType;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use App\Services\StudyMaterial\StudyMaterialService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Administrator');

    $this->faculty = User::factory()->create([
        'name' => 'Prof. S. K. Mukherjee',
        'email' => 'faculty@sntcssc.in',
    ]);
    $this->faculty->assignRole('Faculty');

    $this->accountant = User::factory()->create([
        'name' => 'Accounts Officer',
        'email' => 'accountant@sntcssc.in',
    ]);
    $this->accountant->assignRole('Accountant');

    $this->viewOnlyStaff = User::factory()->create([
        'name' => 'View Only Staff',
        'email' => 'viewonly@sntcssc.in',
    ]);
    $this->viewOnlyStaff->givePermissionTo('study_materials.view');

    $this->studentUser = User::factory()->create([
        'name' => 'Subhas Bose',
        'email' => 'subhas@example.com',
    ]);
    $this->studentUser->assignRole('Student');

    $this->otherStudent = User::factory()->create([
        'name' => 'Satyajit Ray',
        'email' => 'satyajit@example.com',
    ]);
    $this->otherStudent->assignRole('Student');

    $this->course = Course::first() ?? Course::create([
        'name' => 'UPSC Civil Services Foundation',
        'code' => 'UPSC-CSF',
        'fee' => 5000,
        'status' => 'active',
    ]);

    $this->batch = Batch::first() ?? Batch::create([
        'name' => '2026 Batch Alpha',
        'code' => 'BATCH-ALPHA',
        'academic_year' => '2026',
        'status' => 'active',
        'course_id' => $this->course->id,
    ]);

    // Enroll studentUser in the course and batch
    Enrollment::create([
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrollment_number' => 'ENR-2026-SUBHAS',
        'status' => Enrollment::STATUS_ACTIVE,
        'enrolled_at' => now(),
    ]);
});

test('user without study_materials.view permission cannot access admin study materials index or statistics', function () {
    // Accountant has no study_materials.view permission
    $this->actingAs($this->accountant);
    $team = $this->accountant->personalTeam();

    $indexResponse = $this->get(route('admin.study-materials.index', ['current_team' => $team->slug]));
    $indexResponse->assertForbidden();

    $statsResponse = $this->get(route('admin.study-materials.statistics', ['current_team' => $team->slug]));
    $statsResponse->assertForbidden();
});

test('students cannot access admin study materials management page', function () {
    $this->actingAs($this->studentUser);
    $team = $this->studentUser->personalTeam();

    $response = $this->get(route('admin.study-materials.index', ['current_team' => $team->slug]));
    $response->assertForbidden();
});

test('view-only user can view admin hub but is forbidden from mutation actions', function () {
    $this->actingAs($this->viewOnlyStaff);
    $team = $this->viewOnlyStaff->personalTeam();

    // Can access the index page
    $response = $this->get(route('admin.study-materials.index', ['current_team' => $team->slug]));
    $response->assertOk();

    // 1. Forbidden from opening create modal
    Livewire::test('pages::admin.study-materials.index')
        ->call('openCreateModal')
        ->assertStatus(403);

    // 2. Forbidden from saving new material
    Livewire::test('pages::admin.study-materials.index')
        ->set('form.title', 'Unauthorized Material')
        ->set('form.course_id', $this->course->id)
        ->set('form.material_type', StudyMaterial::TYPE_LECTURE_NOTES)
        ->call('saveMaterial')
        ->assertStatus(403);

    // Create an existing material to test edit and delete attempts
    $service = app(StudyMaterialService::class);
    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Sample Material for RBAC Test',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    // 3. Forbidden from opening edit modal
    Livewire::test('pages::admin.study-materials.index')
        ->call('openEditModal', $material->id)
        ->assertStatus(403);

    // 4. Forbidden from updating existing material
    Livewire::test('pages::admin.study-materials.index')
        ->set('editingId', $material->id)
        ->set('form.title', 'Hacked Title')
        ->call('saveMaterial')
        ->assertStatus(403);

    // 5. Forbidden from deleting material
    Livewire::test('pages::admin.study-materials.index')
        ->call('confirmDelete', $material->id)
        ->assertStatus(403);

    Livewire::test('pages::admin.study-materials.index')
        ->set('deletingId', $material->id)
        ->call('executeDelete')
        ->assertStatus(403);

    // 6. Forbidden from restoring material
    Livewire::test('pages::admin.study-materials.index')
        ->call('restoreMaterial', $material->id)
        ->assertStatus(403);

    // 7. Forbidden from exporting
    Livewire::test('pages::admin.study-materials.index')
        ->call('export')
        ->assertStatus(403);

    // 8. Forbidden from importing
    Livewire::test('pages::admin.study-materials.index')
        ->call('openImportModal')
        ->assertStatus(403);

    // 9. Forbidden from sharing
    Livewire::test('pages::admin.study-materials.index')
        ->call('openShareModal', $material->id)
        ->assertStatus(403);

    // 10. Forbidden from managing types
    Livewire::test('pages::admin.study-materials.index')
        ->call('openCreateTypeModal')
        ->assertStatus(403);

    Livewire::test('pages::admin.study-materials.index')
        ->set('typeForm.name', 'Hacked Type')
        ->set('typeForm.code', 'hacked_type')
        ->call('saveType')
        ->assertStatus(403);
});

test('faculty with standard study material permissions can manage materials', function () {
    $this->actingAs($this->faculty);
    $team = $this->faculty->personalTeam();

    $response = $this->get(route('admin.study-materials.index', ['current_team' => $team->slug]));
    $response->assertOk();

    // Faculty can open create modal and save material
    Livewire::test('pages::admin.study-materials.index')
        ->call('openCreateModal')
        ->assertSet('showFormModal', true)
        ->set('form.title', 'Faculty Economics Lecture')
        ->set('form.course_id', $this->course->id)
        ->set('form.material_type', StudyMaterial::TYPE_LECTURE_NOTES)
        ->set('form.status', StudyMaterial::STATUS_PUBLISHED)
        ->set('form.access_level', StudyMaterial::ACCESS_ENROLLED_ONLY)
        ->call('saveMaterial')
        ->assertSet('showFormModal', false);

    $created = StudyMaterial::where('title', 'Faculty Economics Lecture')->first();
    expect($created)->not->toBeNull();

    // Faculty can edit material
    Livewire::test('pages::admin.study-materials.index')
        ->call('openEditModal', $created->id)
        ->assertSet('showFormModal', true)
        ->set('form.title', 'Faculty Economics Lecture - Refined')
        ->call('saveMaterial')
        ->assertSet('showFormModal', false);

    expect($created->fresh()->title)->toBe('Faculty Economics Lecture - Refined');

    // Faculty can move to trash
    Livewire::test('pages::admin.study-materials.index')
        ->call('confirmDelete', $created->id, false)
        ->call('executeDelete');

    expect($created->fresh()->trashed())->toBeTrue();
});

test('student cannot read confidential notes of a course they are not enrolled in', function () {
    $this->actingAs($this->otherStudent);

    $service = app(StudyMaterialService::class);
    $secretMaterial = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Classified Faculty Question Bank',
        'material_type' => StudyMaterial::TYPE_QUESTION_BANK,
        'content' => 'Top Secret Examination Questions for 2026',
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    // otherStudent is not enrolled in this course: calling openNotesModal must abort 403
    Livewire::test('pages::student.study-materials')
        ->call('openNotesModal', $secretMaterial->id)
        ->assertStatus(403);
});

test('user without study_materials.download permission cannot download private material', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('private_syllabus.pdf', 100, 'application/pdf');

    $service = app(StudyMaterialService::class);
    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Private Course Syllabus',
        'material_type' => StudyMaterial::TYPE_SYLLABUS,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ], [$file]);

    // User without download permission
    $userWithoutDownload = User::factory()->create();
    $userWithoutDownload->givePermissionTo('study_materials.view');

    $this->actingAs($userWithoutDownload);

    $response = $this->get(route('study-materials.download', ['studyMaterial' => $material->id]));
    $response->assertForbidden();
});

test('external api rejects download requests for private enrolled materials', function () {
    $apiKeyService = app(ApiKeyService::class);
    $keyData = $apiKeyService->generateKey([
        'name' => 'External Test App',
        'type' => ApiKey::TYPE_EXTERNAL_APP,
        'scopes' => ['read:materials', 'download:materials'],
    ], $this->superAdmin);

    $service = app(StudyMaterialService::class);
    $privateMaterial = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Internal Batch Confidential Module',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    // Attempting to request download of private material via public external lead capture API
    $response = $this->withHeaders([
        'X-API-Key' => $keyData['plainTextKey'],
    ])->postJson(route('api.v1.external.study-materials.download-request', [
        'identifier' => $privateMaterial->id,
    ]), [
        'first_name' => 'Ayan',
        'email' => 'ayan@example.com',
        'phone' => '+91 9988776655',
    ]);

    $response->assertForbidden();
    $response->assertJsonPath('error.code', 'MATERIAL_RESTRICTED');
});

test('unpublished draft materials cannot be accessed via shared token', function () {
    $service = app(StudyMaterialService::class);
    $draftMaterial = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Unpublished Work in Progress',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_DRAFT,
        'access_level' => StudyMaterial::ACCESS_PUBLIC_LINK,
    ]);

    $response = $this->get(route('study-materials.public-share', ['token' => $draftMaterial->share_token]));
    $response->assertNotFound();

    $downloadResponse = $this->get(route('study-materials.public-download', ['token' => $draftMaterial->share_token]));
    $downloadResponse->assertNotFound();
});

test('student logged in dashboard sidebar does not show admin study materials or materials analytics', function () {
    $this->actingAs($this->studentUser);
    $team = $this->studentUser->personalTeam();

    $response = $this->get(route('dashboard', ['current_team' => $team->slug]));
    $response->assertOk();

    // Must not include administrative Study Materials index or analytics routes
    $response->assertDontSee(route('admin.study-materials.index'));
    $response->assertDontSee(route('admin.study-materials.statistics'));
    $response->assertDontSee(__('Materials Analytics'));

    // Must include the legitimate student study materials portal link
    $response->assertSee(route('student.study-materials.index'));
});

test('faculty logged in dashboard sidebar shows admin study materials and materials analytics', function () {
    $this->actingAs($this->faculty);
    $team = $this->faculty->personalTeam();

    $response = $this->get(route('dashboard', ['current_team' => $team->slug]));
    $response->assertOk();

    $response->assertSee(route('admin.study-materials.index'));
    $response->assertSee(route('admin.study-materials.statistics'));
});

test('admin can open responsive material types management and add custom type modals', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test('pages::admin.study-materials.index')
        ->call('openTypesModal')
        ->assertSet('showTypesModal', true)
        ->assertSee(__('Study Material Types & Categories'))
        ->assertSee(__('External API Access'))
        ->call('openCreateTypeModal')
        ->assertSet('showTypeFormModal', true)
        ->assertSee(__('Add Custom Material Type'))
        ->assertSee(__('Public API Access & Discovery'));
});

test('admin can toggle material type visibility between public and internal enrolled', function () {
    $this->actingAs($this->superAdmin);
    $type = StudyMaterialType::where('code', 'lecture_notes')->firstOrFail();
    $initialVisibility = $type->is_public;

    Livewire::test('pages::admin.study-materials.index')
        ->call('toggleTypeVisibility', $type->id)
        ->assertDispatched('toast');

    expect($type->fresh()->is_public)->toBe(! $initialVisibility);

    // Toggle back
    Livewire::test('pages::admin.study-materials.index')
        ->call('toggleTypeVisibility', $type->id)
        ->assertDispatched('toast');

    expect($type->fresh()->is_public)->toBe($initialVisibility);
});
