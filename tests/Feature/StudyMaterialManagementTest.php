<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialShare;
use App\Models\StudyMaterialType;
use App\Models\User;
use App\Services\RbacService;
use App\Services\StudyMaterial\StudyMaterialImportExportService;
use App\Services\StudyMaterial\StudyMaterialService;
use App\Support\LucideIcons;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->studentUser = User::factory()->create([
        'name' => 'Ananya Sen',
        'email' => 'ananya.sen@example.com',
        'phone' => '+91 9830112233',
        'whatsapp_no' => '+91 9830112233',
    ]);
    $this->studentUser->assignRole('Student');

    $this->otherStudent = User::factory()->create([
        'name' => 'Rahul Bose',
        'email' => 'rahul.bose@example.com',
        'phone' => '+91 9830998877',
    ]);
    $this->otherStudent->assignRole('Student');

    $this->course = Course::first() ?? Course::create([
        'name' => 'UPSC Civil Services Foundation',
        'code' => 'UPSC-CSF',
        'fee' => 5000,
        'status' => 'active',
    ]);

    $this->otherCourse = Course::create([
        'name' => 'WBCS Executive Foundation',
        'code' => 'WBCS-EXE',
        'fee' => 4000,
        'status' => 'active',
    ]);

    $this->batch = Batch::first() ?? Batch::create([
        'name' => '2026 Morning Batch',
        'code' => 'BATCH-2026-M',
        'academic_year' => '2026',
        'status' => 'active',
    ]);

    // Enroll Ananya in this->course and this->batch
    $this->enrollment = Enrollment::create([
        'enrollment_no' => 'ENR-2026-0001',
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'status' => Enrollment::STATUS_ACTIVE,
        'enrolled_at' => now(),
    ]);

    // Attach batch to course
    if (! $this->course->batches()->where('batches.id', $this->batch->id)->exists()) {
        $this->course->batches()->attach($this->batch->id);
    }
});

test('admin can view study materials index page and see statistics', function () {
    $this->actingAs($this->admin);

    $service = app(StudyMaterialService::class);
    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Indian Polity - Preamble & Basic Structure',
        'subject' => 'Indian Polity',
        'topic' => 'Preamble',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    $team = $this->admin->personalTeam();

    $response = $this->get(route('admin.study-materials.index', ['current_team' => $team->slug]));
    $response->assertOk();
    $response->assertSee('Indian Polity - Preamble & Basic Structure');
});

test('admin can create study material with batch associations and files', function () {
    $this->actingAs($this->admin);
    Storage::fake('public');

    $file1 = UploadedFile::fake()->create('preamble_notes.pdf', 500, 'application/pdf');
    $file2 = UploadedFile::fake()->create('supreme_court_cases.docx', 250, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    Livewire::test('pages::admin.study-materials.index')
        ->call('openCreateModal')
        ->set('form.course_id', $this->course->id)
        ->set('form.batch_ids', [$this->batch->id])
        ->set('form.title', 'Modern Indian History - Revolt of 1857')
        ->set('form.subject', 'Modern History')
        ->set('form.topic', 'Revolt of 1857')
        ->set('form.material_type', StudyMaterial::TYPE_LECTURE_NOTES)
        ->set('form.description', 'Complete overview of civil rebellions and leaders.')
        ->set('form.status', StudyMaterial::STATUS_PUBLISHED)
        ->set('newFiles', [$file1, $file2])
        ->call('saveMaterial')
        ->assertHasNoErrors();

    $material = StudyMaterial::where('title', 'Modern Indian History - Revolt of 1857')->first();
    expect($material)->not->toBeNull();
    expect($material->course_id)->toBe($this->course->id);
    expect($material->batches->pluck('id')->all())->toContain($this->batch->id);
    expect($material->files)->toHaveCount(2);
});

test('study material validation prevents missing required fields', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.study-materials.index')
        ->call('openCreateModal')
        ->set('form.title', '')
        ->set('form.course_id', '')
        ->call('saveMaterial')
        ->assertHasErrors(['form.title', 'form.course_id']);
});

test('admin can update study material and add/remove files', function () {
    $this->actingAs($this->admin);
    Storage::fake('public');

    $service = app(StudyMaterialService::class);
    $initialFile = UploadedFile::fake()->create('sample1.pdf', 100, 'application/pdf');

    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Initial Title Note',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ], [$initialFile]);

    $existingFileId = $material->files->first()->id;
    $newFile = UploadedFile::fake()->create('updated_slides.pptx', 300);

    Livewire::test('pages::admin.study-materials.index')
        ->call('openEditModal', $material->id)
        ->set('form.title', 'Updated Title Note V2')
        ->set('deleteFileIds', [$existingFileId])
        ->set('newFiles', [$newFile])
        ->call('saveMaterial')
        ->assertHasNoErrors();

    $material->refresh();
    expect($material->title)->toBe('Updated Title Note V2');
    expect($material->files)->toHaveCount(1);
    expect($material->files->first()->file_name)->toBe('updated_slides.pptx');
});

test('admin can soft-delete, view in trash, and restore study material', function () {
    $this->actingAs($this->admin);

    $service = app(StudyMaterialService::class);
    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Geography of India Notes',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    Livewire::test('pages::admin.study-materials.index')
        ->call('confirmDelete', $material->id, false)
        ->call('executeDelete');

    expect(StudyMaterial::where('id', $material->id)->exists())->toBeFalse();
    expect(StudyMaterial::withTrashed()->where('id', $material->id)->exists())->toBeTrue();

    // Restore
    Livewire::test('pages::admin.study-materials.index')
        ->call('restoreMaterial', $material->id);

    expect(StudyMaterial::where('id', $material->id)->exists())->toBeTrue();
});

test('admin can share study material with students across channels', function () {
    $this->actingAs($this->admin);

    $service = app(StudyMaterialService::class);
    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Ethics, Integrity & Aptitude Case Studies',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    Livewire::test('pages::admin.study-materials.index')
        ->call('openShareModal', $material->id)
        ->set('shareChannels', ['whatsapp', 'email', 'sms', 'telegram', 'in_app'])
        ->set('shareTarget', 'batch')
        ->set('shareCustomMessage', 'Important: Must solve case study 1 & 2 before class.')
        ->call('dispatchShare');

    // Verify share logs were created
    $shares = StudyMaterialShare::where('study_material_id', $material->id)->get();
    expect($shares->count())->toBeGreaterThan(0);
    expect($shares->pluck('recipient_name')->all())->toContain('Ananya Sen');
});

test('enrolled student can view study materials belonging to their enrolled course and batch', function () {
    $this->actingAs($this->studentUser);

    $service = app(StudyMaterialService::class);

    // Material 1: For student's course and batch
    $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Enrolled Student Study Guide',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    // Material 2: For another course
    $service->createMaterial([
        'course_id' => $this->otherCourse->id,
        'title' => 'Other Course Secret Notes',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    $team = $this->studentUser->personalTeam();

    $response = $this->get(route('student.study-materials.index', ['current_team' => $team->slug]));
    $response->assertOk();
    $response->assertSee('Enrolled Student Study Guide');
    $response->assertDontSee('Other Course Secret Notes');
});

test('unenrolled student cannot access study materials of other courses', function () {
    $this->actingAs($this->otherStudent);

    $service = app(StudyMaterialService::class);
    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Restricted UPSC Materials',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    // otherStudent is NOT enrolled in UPSC course
    expect($material->isAccessibleBy($this->otherStudent))->toBeFalse();

    // Trying to download directly should result in 403 Forbidden
    $response = $this->get(route('study-materials.download', ['studyMaterial' => $material->id]));
    $response->assertForbidden();
});

test('file download endpoint authorizes access, increments download counters, and writes access log', function () {
    $this->actingAs($this->studentUser);
    Storage::fake('public');

    $file = UploadedFile::fake()->create('economy_notes.pdf', 300, 'application/pdf');
    $service = app(StudyMaterialService::class);

    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Indian Economy & Macroeconomics',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ], [$file]);

    $fileRecord = $material->files->first();

    $response = $this->get(route('study-materials.download', [
        'studyMaterial' => $material->id,
        'fileIndex' => $fileRecord->id,
    ]));

    $response->assertOk();

    $material->refresh();
    $fileRecord->refresh();

    expect($material->download_count)->toBe(1);
    expect($fileRecord->download_count)->toBe(1);

    $log = StudyMaterialAccessLog::where('study_material_id', $material->id)->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe(StudyMaterialAccessLog::ACTION_DOWNLOAD);
    expect($log->user_id)->toBe($this->studentUser->id);
});

test('public share link allows viewing and downloading via secure token', function () {
    $service = app(StudyMaterialService::class);
    Storage::fake('public');

    $file = UploadedFile::fake()->create('public_syllabus.pdf', 200, 'application/pdf');

    $material = $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Public Civil Services Syllabus 2026',
        'material_type' => StudyMaterial::TYPE_SYLLABUS,
        'access_level' => StudyMaterial::ACCESS_PUBLIC_LINK,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ], [$file]);

    // Guest user can view public share page
    $response = $this->get(route('study-materials.public-share', ['token' => $material->share_token]));
    $response->assertOk();
    $response->assertSee('Public Civil Services Syllabus 2026');

    // Guest user can download public file
    $downloadResponse = $this->get(route('study-materials.public-download', ['token' => $material->share_token]));
    $downloadResponse->assertOk();
});

test('export study materials to CSV works as expected', function () {
    $this->actingAs($this->admin);

    $service = app(StudyMaterialService::class);
    $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'Science & Technology Compendium',
        'material_type' => StudyMaterial::TYPE_HANDOUT_REFERENCE,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    $component = Livewire::test('pages::admin.study-materials.index');
    $exportResponse = $component->call('export', 'csv');

    expect($exportResponse)->not->toBeNull();
});

test('import study materials from CSV file creates records and batch mappings', function () {
    $this->actingAs($this->admin);

    $csvContent = "Title,Course_Code,Batches,Subject,Topic,Module_Code,Material_Type,Description,External_URL,Access_Level,Is_Pinned,Status\n";
    $csvContent .= "Ancient Indian History Notes,{$this->course->code},{$this->batch->code},History,Indus Valley,MOD-01,lecture_notes,Detailed notes on Harappan culture,,enrolled_only,yes,published\n";

    $tempCsv = tempnam(sys_get_temp_dir(), 'sm_import_').'.csv';
    file_put_contents($tempCsv, $csvContent);

    $result = StudyMaterialImportExportService::importMaterials($tempCsv);
    @unlink($tempCsv);

    expect($result['imported'])->toBe(1);

    $material = StudyMaterial::where('title', 'Ancient Indian History Notes')->first();
    expect($material)->not->toBeNull();
    expect($material->course_id)->toBe($this->course->id);
    expect($material->batches->pluck('id')->all())->toContain($this->batch->id);
});

test('unauthorized user cannot access study materials management', function () {
    $userWithoutPermission = User::factory()->create();
    // No role or permission assigned

    $this->actingAs($userWithoutPermission);
    $team = $userWithoutPermission->personalTeam();

    $response = $this->get(route('admin.study-materials.index', ['current_team' => $team->slug]));
    $response->assertForbidden();
});

test('api can fetch paginated study materials list and statistics', function () {
    $service = app(StudyMaterialService::class);
    $service->createMaterial([
        'course_id' => $this->course->id,
        'title' => 'API Test Material Resource',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    $response = $this->getJson(route('api.v1.study-materials.index'));
    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'uuid',
                'title',
                'course',
                'batches',
                'material_type',
                'status',
            ],
        ],
    ]);

    $statsResponse = $this->getJson(route('api.v1.study-materials.statistics'));
    $statsResponse->assertOk();
    $statsResponse->assertJsonPath('success', true);
    $statsResponse->assertJsonStructure([
        'data' => [
            'total_materials',
            'published_materials',
            'total_files',
            'total_downloads',
        ],
    ]);
});

test('student logged-in dashboard and sidebar contains study materials option', function () {
    $this->actingAs($this->studentUser);
    $team = $this->studentUser->personalTeam();

    $response = $this->get(route('dashboard', ['current_team' => $team->slug]));
    $response->assertOk();
    $response->assertSee(route('student.study-materials.index', ['current_team' => $team->slug]));
    $response->assertSee(__('Study Materials'));
});

test('students can access and download publicly available study materials without any enrollment restrictions', function () {
    // otherStudent is NOT enrolled in any course
    $this->actingAs($this->otherStudent);
    Storage::fake('public');

    $file = UploadedFile::fake()->create('pib_sandhan_october.pdf', 150, 'application/pdf');
    $service = app(StudyMaterialService::class);

    // 1. PIB Sandhan general publication with no course_id
    $pibMaterial = $service->createMaterial([
        'course_id' => null,
        'title' => 'PIB Sandhan - October 2026 Edition',
        'material_type' => StudyMaterial::TYPE_PIB_SANDHAN,
        'status' => StudyMaterial::STATUS_PUBLISHED,
        'access_level' => StudyMaterial::ACCESS_PUBLIC_LINK,
    ], [$file]);

    // 2. Current Affairs publication
    $caMaterial = $service->createMaterial([
        'course_id' => null,
        'title' => 'Monthly Current Affairs Compendium',
        'material_type' => StudyMaterial::TYPE_CURRENT_AFFAIRS,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    // Should be accessible to otherStudent even with 0 enrollments
    expect($pibMaterial->isAccessibleBy($this->otherStudent))->toBeTrue();
    expect($caMaterial->isAccessibleBy($this->otherStudent))->toBeTrue();

    // Student can access student study materials portal and see the public materials
    $team = $this->otherStudent->personalTeam();
    $portalResponse = $this->get(route('student.study-materials.index', ['current_team' => $team->slug]));
    $portalResponse->assertOk();
    $portalResponse->assertSee('PIB Sandhan - October 2026 Edition');
    $portalResponse->assertSee('Monthly Current Affairs Compendium');

    // Student can download the file without restriction
    $downloadResponse = $this->get(route('study-materials.download', [
        'studyMaterial' => $pibMaterial->id,
        'fileIndex' => $pibMaterial->files->first()->id,
    ]));
    $downloadResponse->assertOk();
});

test('enrolled course and batch specific study materials remain restricted by enrollments', function () {
    $this->actingAs($this->otherStudent);
    $service = app(StudyMaterialService::class);

    $restrictedMaterial = $service->createMaterial([
        'course_id' => $this->course->id,
        'batch_ids' => [$this->batch->id],
        'title' => 'Restricted UPSC Batch Only Blueprint',
        'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
        'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
        'status' => StudyMaterial::STATUS_PUBLISHED,
    ]);

    // otherStudent is NOT enrolled in UPSC course or batch
    expect($restrictedMaterial->isAccessibleBy($this->otherStudent))->toBeFalse();

    // Enrolled studentUser CAN access and download
    expect($restrictedMaterial->isAccessibleBy($this->studentUser))->toBeTrue();

    // otherStudent download attempt gets 403 Forbidden
    $response = $this->get(route('study-materials.download', ['studyMaterial' => $restrictedMaterial->id]));
    $response->assertForbidden();
});

test('admin can open custom study material type modal, validate, and save dynamic type', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::admin.study-materials.index')
        ->call('openCreateTypeModal')
        ->assertSet('showTypeFormModal', true)
        ->assertSet('editingTypeId', null)
        ->set('typeForm.name', 'Prelims Booster 2026')
        ->set('typeForm.code', 'prelims_booster')
        ->set('typeForm.description', 'Rapid revision modules for preliminary examinations')
        ->set('typeForm.icon', 'zap')
        ->set('typeForm.badge_color', 'amber')
        ->set('typeForm.is_public', true)
        ->set('typeForm.is_active', true)
        ->call('saveType')
        ->assertSet('showTypeFormModal', false);

    $createdType = StudyMaterialType::where('code', 'prelims_booster')->first();
    expect($createdType)->not->toBeNull();
    expect($createdType->name)->toBe('Prelims Booster 2026');
    expect($createdType->is_public)->toBeTrue();
    expect($createdType->icon)->toBe('zap');

    // Test toggle status
    $component->call('toggleTypeStatus', $createdType->id);
    expect($createdType->fresh()->is_active)->toBeFalse();

    // Test delete
    $component->call('deleteType', $createdType->id);
    expect(StudyMaterialType::find($createdType->id))->toBeNull();
});

test('lucide icons map newspaper, headphones, folder-down and grid aliases correctly', function () {
    expect(LucideIcons::get('newspaper'))->not->toBeNull();
    expect(LucideIcons::get('headphones'))->not->toBeNull();
    expect(LucideIcons::get('folder-down'))->not->toBeNull();
    expect(LucideIcons::get('grid'))->not->toBeNull();
    expect(LucideIcons::get('folder-open'))->not->toBeNull();
    expect(LucideIcons::get('book-marked'))->not->toBeNull();
});
