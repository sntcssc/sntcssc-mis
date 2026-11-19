<?php

use App\Models\Alumni;
use App\Models\AlumniPosting;
use App\Models\AlumniServiceCategory;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Alumni\AlumniService;
use App\Services\Alumni\AlumniServiceCategoryService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->studentUser = User::factory()->create([
        'name' => 'Dipankar Roy',
        'email' => 'dipankar.roy@example.com',
        'phone' => '+91 9830112233',
    ]);
    $this->studentUser->assignRole('Student');

    $this->course = Course::first() ?? Course::create([
        'name' => 'UPSC Civil Services Foundation',
        'code' => 'UPSC-CSF',
        'fee' => 5000,
        'status' => 'active',
    ]);

    $this->batch = Batch::first() ?? Batch::create([
        'name' => '2023 Executive Batch',
        'code' => 'BATCH-2023',
        'academic_year' => '2023',
        'status' => 'active',
    ]);

    $this->enrollment = Enrollment::create([
        'enrollment_no' => 'ENR-2023-0001',
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'status' => 'active',
        'enrolled_at' => now()->subYear(),
    ]);
});

test('admin can view alumni index page and see statistics', function () {
    $this->actingAs($this->admin);

    AlumniService::createAlumni([
        'first_name' => 'Kalyan',
        'last_name' => 'Ghosh',
        'email' => 'kalyan.ias@example.com',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IAS',
        'designation' => 'Assistant Collector',
        'posting_state' => 'West Bengal',
        'exam_year' => 2022,
        'all_india_rank' => 32,
    ]);

    Livewire::test('pages::admin.alumni.index')
        ->assertOk()
        ->assertSee('Alumni & Officer Directory')
        ->assertSee('Kalyan Ghosh')
        ->assertSee('AIR 32');
});

test('admin can create an alumnus manually with multiple courses and batches', function () {
    $this->actingAs($this->admin);

    $secondBatch = Batch::create([
        'name' => '2022 Mains Booster',
        'code' => 'BATCH-2022',
        'academic_year' => '2022',
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.alumni.index')
        ->assertOk()
        ->call('openCreateModal')
        ->set('form.first_name', 'Moumita')
        ->set('form.last_name', 'Saha')
        ->set('form.email', 'moumita.ips@example.com')
        ->set('form.service_type', 'UPSC CSE')
        ->set('form.service_name', 'IPS')
        ->set('form.designation', 'Assistant Commissioner of Police')
        ->set('form.cadre', 'West Bengal')
        ->set('form.all_india_rank', '64')
        ->set('form.exam_year', '2023')
        ->set('form.posting_city', 'Kolkata')
        ->set('form.posting_state', 'West Bengal')
        ->set('courseBatches', [
            [
                'id' => null,
                'course_id' => (string) $this->course->id,
                'batch_id' => (string) $this->batch->id,
                'course_name' => $this->course->name,
                'batch_name' => $this->batch->name,
                'enrollment_no' => 'ENR-UPSC-101',
                'completion_year' => '2022',
                'status' => 'completed',
                'remarks' => 'Foundation module',
            ],
            [
                'id' => null,
                'course_id' => '',
                'batch_id' => '',
                'course_name' => 'Personality Test & Mock Interview Panel',
                'batch_name' => 'Mock Interview 2023',
                'enrollment_no' => 'INT-2023-55',
                'completion_year' => '2023',
                'status' => 'completed',
                'remarks' => 'Interview guidance',
            ],
        ])
        ->call('saveAlumni');

    $alumnus = Alumni::where('email', 'moumita.ips@example.com')->first();
    expect($alumnus)->not->toBeNull();
    expect($alumnus->full_name)->toBe('Moumita Saha');
    expect($alumnus->all_india_rank)->toBe(64);
    expect($alumnus->courseBatches()->count())->toBe(2);

    $courses = $alumnus->courseBatches->pluck('course_name')->all();
    expect($courses)->toContain($this->course->name);
    expect($courses)->toContain('Personality Test & Mock Interview Panel');
});

test('alumnus validation prevents missing required fields', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.alumni.index')
        ->call('openCreateModal')
        ->set('form.first_name', '')
        ->set('form.last_name', '')
        ->set('form.service_type', '')
        ->call('saveAlumni')
        ->assertHasErrors(['form.first_name', 'form.last_name', 'form.service_type']);
});

test('admin can convert an enrolled student to an alumnus', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.alumni.index')
        ->call('openConvertStudentModal')
        ->set('studentSearchQuery', 'Dipankar')
        ->call('selectStudentForConversion', $this->studentUser->id)
        ->assertSet('form.first_name', 'Dipankar')
        ->assertSet('form.last_name', 'Roy')
        ->assertSet('form.email', 'dipankar.roy@example.com')
        ->set('form.service_type', 'West Bengal Civil Service (WBCS)')
        ->set('form.service_name', 'WBCS (Exe)')
        ->set('form.designation', 'Deputy Magistrate')
        ->set('form.state_rank', '7')
        ->set('form.exam_year', '2022')
        ->call('saveAlumni');

    $alumnus = Alumni::where('email', 'dipankar.roy@example.com')->first();
    expect($alumnus)->not->toBeNull();
    expect($alumnus->user_id)->toBe($this->studentUser->id);
    expect($alumnus->service_name)->toBe('WBCS (Exe)');
    expect($alumnus->courseBatches()->count())->toBeGreaterThanOrEqual(1);
});

test('admin can update alumnus details and toggle featured status', function () {
    $this->actingAs($this->admin);

    $alumnus = AlumniService::createAlumni([
        'first_name' => 'Debabrata',
        'last_name' => 'Das',
        'email' => 'debabrata.ifs@example.com',
        'service_type' => 'Indian Forest Service (IFoS)',
        'service_name' => 'IFoS',
        'designation' => 'Divisional Forest Officer',
        'posting_state' => 'West Bengal',
        'is_featured' => false,
    ]);

    Livewire::test('pages::admin.alumni.index')
        ->call('openEditModal', $alumnus->id)
        ->set('form.designation', 'Conservator of Forests')
        ->call('saveAlumni');

    expect($alumnus->fresh()->designation)->toBe('Conservator of Forests');

    // Toggle featured
    Livewire::test('pages::admin.alumni.index')
        ->call('toggleFeatured', $alumnus->id);

    expect($alumnus->fresh()->is_featured)->toBeTrue();
});

test('admin can soft-delete, view in trash, and restore an alumnus', function () {
    $this->actingAs($this->admin);

    $alumnus = AlumniService::createAlumni([
        'first_name' => 'Soumen',
        'last_name' => 'Bose',
        'email' => 'soumen.wbps@example.com',
        'service_type' => 'West Bengal Police Service (WBPS)',
        'service_name' => 'WBPS',
        'designation' => 'Deputy Superintendent of Police',
    ]);

    $id = $alumnus->id;

    Livewire::test('pages::admin.alumni.index')
        ->call('confirmDelete', $id)
        ->call('executeDelete');

    expect(Alumni::where('id', $id)->exists())->toBeFalse();
    expect(Alumni::onlyTrashed()->where('id', $id)->exists())->toBeTrue();

    // Restore
    Livewire::test('pages::admin.alumni.index')
        ->set('activeTab', 'trashed')
        ->call('restoreAlumni', $id);

    expect(Alumni::where('id', $id)->exists())->toBeTrue();
});

test('alumni filtering and search work correctly', function () {
    $this->actingAs($this->admin);

    AlumniService::createAlumni([
        'first_name' => 'Alok',
        'last_name' => 'Verma',
        'email' => 'alok.ias@example.com',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IAS',
        'designation' => 'District Magistrate',
        'posting_city' => 'Malda',
        'posting_state' => 'West Bengal',
        'status' => 'active',
    ]);

    AlumniService::createAlumni([
        'first_name' => 'Sneha',
        'last_name' => 'Kundu',
        'email' => 'sneha.rbi@example.com',
        'service_type' => 'Banking & Finance',
        'service_name' => 'RBI Manager',
        'designation' => 'Grade B Officer',
        'posting_city' => 'Mumbai',
        'posting_state' => 'Maharashtra',
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.alumni.index')
        ->set('search', 'Malda')
        ->assertSee('Alok Verma')
        ->assertDontSee('Sneha Kundu')
        ->set('search', '')
        ->set('serviceTypeFilter', 'Banking & Finance')
        ->assertSee('Sneha Kundu')
        ->assertDontSee('Alok Verma');
});

test('admin can export alumni to CSV', function () {
    $this->actingAs($this->admin);

    AlumniService::createAlumni([
        'first_name' => 'Rakesh',
        'last_name' => 'Sen',
        'email' => 'rakesh.sen@example.com',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IRS',
        'designation' => 'Assistant Commissioner of Income Tax',
    ]);

    $response = Livewire::test('pages::admin.alumni.index')
        ->call('export', 'csv');

    expect($response)->not->toBeNull();
});

test('admin can import alumni from CSV file', function () {
    $this->actingAs($this->admin);

    $csvContent = "first_name,last_name,email,service_type,service_name,designation,posting_city,posting_state,courses,batches,exam_year\n"
        ."Tanmoy,Chatterjee,tanmoy.ias@testimport.com,UPSC CSE,IAS,Sub-Divisional Officer,Asansol,West Bengal,UPSC Comprehensive,Batch 2021,2021\n";

    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('alumni_import.csv', $csvContent);

    Livewire::test('pages::admin.alumni.index')
        ->set('importFile', $file)
        ->call('executeImport');

    expect(Alumni::where('email', 'tanmoy.ias@testimport.com')->exists())->toBeTrue();
    $alumnus = Alumni::where('email', 'tanmoy.ias@testimport.com')->first();
    expect($alumnus->full_name)->toBe('Tanmoy Chatterjee');
    expect($alumnus->courseBatches()->count())->toBeGreaterThanOrEqual(1);
});

test('unauthorized user cannot access alumni directory', function () {
    $unauthorizedUser = User::factory()->create();
    // No role or permission
    $this->actingAs($unauthorizedUser);

    $this->get(route('admin.alumni.index'))
        ->assertForbidden();
});

test('public can view alumni showcase wall of fame', function () {
    AlumniService::createAlumni([
        'first_name' => 'Sharmistha',
        'last_name' => 'Das',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IAS',
        'designation' => 'District Magistrate',
        'is_featured' => true,
        'status' => 'active',
    ]);

    Livewire::test('pages::public.alumni')
        ->assertOk()
        ->assertSee('SNTCSSC Achievers & Hall of Fame')
        ->assertSee('Sharmistha Das');
});

test('admin can view alumni analytics statistics page', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.alumni.statistics')
        ->assertOk()
        ->assertSee('Alumni Analytics & Institutional Insights')
        ->assertSee('Total Registered Alumni')
        ->assertSee('Civil Services Placements');
});

test('admin can export alumni to PDF', function () {
    $this->actingAs($this->admin);

    AlumniService::createAlumni([
        'first_name' => 'Ayan',
        'last_name' => 'Choudhury',
        'email' => 'ayan.ias@example.com',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IAS',
        'designation' => 'Assistant Magistrate',
    ]);

    $response = Livewire::test('pages::admin.alumni.index')
        ->call('export', 'pdf');

    expect($response)->not->toBeNull();
});

test('can create alumnus with multiple examination selections across multiple years', function () {
    $this->actingAs($this->admin);

    $alumnus = AlumniService::createAlumni(
        data: [
            'first_name' => 'Ananya',
            'last_name' => 'Chatterjee',
            'email' => 'ananya.ias@example.com',
            'service_type' => 'UPSC CSE',
            'service_name' => 'IAS',
            'designation' => 'Assistant Collector',
            'posting_state' => 'West Bengal',
        ],
        examinations: [
            [
                'examination_name' => 'West Bengal Civil Service (Executive)',
                'exam_year' => 2021,
                'service_allotted' => 'WBCS (Exe)',
                'state_rank' => 12,
                'is_primary' => false,
                'remarks' => 'First attempt qualifying WBCS Group A',
            ],
            [
                'examination_name' => 'UPSC Civil Services Examination',
                'exam_year' => 2022,
                'service_allotted' => 'IRS (IT)',
                'all_india_rank' => 195,
                'optional_subject' => 'Sociology',
                'is_primary' => false,
                'remarks' => 'Allocated to Indian Revenue Service',
            ],
            [
                'examination_name' => 'UPSC Civil Services Examination',
                'exam_year' => 2023,
                'service_allotted' => 'IAS',
                'all_india_rank' => 24,
                'optional_subject' => 'Sociology',
                'score_or_marks' => '1042/2025',
                'is_primary' => true,
                'remarks' => 'Selected for Indian Administrative Service',
            ],
        ]
    );

    expect($alumnus->examinations()->count())->toBe(3);

    $primaryExam = $alumnus->primaryExamination;
    expect($primaryExam)->not->toBeNull();
    expect($primaryExam->examination_name)->toBe('UPSC Civil Services Examination');
    expect($primaryExam->exam_year)->toBe(2023);
    expect($primaryExam->all_india_rank)->toBe(24);

    // Parent alumnus fields should sync with the primary examination
    $refreshed = $alumnus->fresh();
    expect($refreshed->exam_year)->toBe(2023);
    expect($refreshed->all_india_rank)->toBe(24);
    expect($refreshed->service_name)->toBe('IAS');
});

test('can add, update, and track career posting histories with current posting auto-sync', function () {
    $this->actingAs($this->admin);

    $alumnus = AlumniService::createAlumni(
        data: [
            'first_name' => 'Rajesh',
            'last_name' => 'Sen',
            'email' => 'rajesh.sen@example.com',
            'service_type' => 'UPSC CSE',
            'service_name' => 'IAS',
            'designation' => 'Assistant Magistrate',
            'posting_city' => 'Midnapore',
            'posting_state' => 'West Bengal',
        ],
        postings: [
            [
                'designation' => 'Assistant Magistrate & Officer Trainee',
                'department_ministry' => 'Personnel & Administrative Reforms',
                'posting_city' => 'Midnapore',
                'posting_district' => 'Paschim Medinipur',
                'posting_state' => 'West Bengal',
                'from_date' => '2020-09-01',
                'to_date' => '2022-04-30',
                'is_current' => false,
                'order_number' => 'PAR/2020/99',
            ],
            [
                'designation' => 'Sub-Divisional Officer (SDO)',
                'department_ministry' => 'General Administration Department',
                'posting_city' => 'Durgapur',
                'posting_district' => 'Paschim Bardhaman',
                'posting_state' => 'West Bengal',
                'from_date' => '2022-05-01',
                'to_date' => null,
                'is_current' => true,
                'order_number' => 'GAD/2022/412',
            ],
        ]
    );

    expect($alumnus->postings()->count())->toBe(2);

    // Parent alumni should sync with current posting
    $refreshed = $alumnus->fresh();
    expect($refreshed->designation)->toBe('Sub-Divisional Officer (SDO)');
    expect($refreshed->posting_city)->toBe('Durgapur');
    expect($refreshed->posting_district)->toBe('Paschim Bardhaman');

    // Add a new current posting (promotion to District Magistrate)
    $newPosting = AlumniService::addPosting($refreshed, [
        'designation' => 'District Magistrate & Collector',
        'department_ministry' => 'Home & Hill Affairs',
        'posting_city' => 'Alipurduar',
        'posting_district' => 'Alipurduar',
        'posting_state' => 'West Bengal',
        'from_date' => '2024-06-01',
        'is_current' => true,
        'order_number' => 'HHA/2024/77',
    ]);

    expect($alumnus->postings()->count())->toBe(3);

    // Previous current posting should now be untoggled
    $previousCurrent = AlumniPosting::where('alumni_id', $alumnus->id)
        ->where('designation', 'Sub-Divisional Officer (SDO)')
        ->first();
    expect($previousCurrent->is_current)->toBeFalse();

    // Parent alumni should now reflect the new current posting
    $afterPromotion = $alumnus->fresh();
    expect($afterPromotion->designation)->toBe('District Magistrate & Collector');
    expect($afterPromotion->posting_city)->toBe('Alipurduar');
});

test('admin can manage posting histories directly from Livewire modal', function () {
    $this->actingAs($this->admin);

    $alumnus = AlumniService::createAlumni([
        'first_name' => 'Subhash',
        'last_name' => 'Mondal',
        'email' => 'subhash.mondal@example.com',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IAS',
        'designation' => 'SDO',
        'posting_city' => 'Siliguri',
        'posting_state' => 'West Bengal',
    ]);

    Livewire::test('pages::admin.alumni.index')
        ->call('openManagePostingsModal', $alumnus->id)
        ->assertSet('showPostingsHistoryModal', true)
        ->set('quickPosting.designation', 'Additional District Magistrate (ADM)')
        ->set('quickPosting.department_ministry', 'Land & Land Reforms')
        ->set('quickPosting.posting_city', 'Darjeeling')
        ->set('quickPosting.posting_district', 'Darjeeling')
        ->set('quickPosting.posting_state', 'West Bengal')
        ->set('quickPosting.from_date', '2023-11-01')
        ->set('quickPosting.is_current', true)
        ->call('saveQuickPosting');

    expect($alumnus->postings()->count())->toBe(1);
    expect($alumnus->fresh()->designation)->toBe('Additional District Magistrate (ADM)');
    expect($alumnus->fresh()->posting_city)->toBe('Darjeeling');

    $posting = $alumnus->postings()->first();

    // Delete posting from modal
    Livewire::test('pages::admin.alumni.index')
        ->call('openManagePostingsModal', $alumnus->id)
        ->call('deleteQuickPosting', $posting->id);

    expect($alumnus->postings()->count())->toBe(0);
});

test('api can fetch paginated alumni list with filters and search', function () {
    AlumniService::createAlumni([
        'first_name' => 'Priyanka',
        'last_name' => 'Roy',
        'email' => 'priyanka.ips@example.com',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IPS',
        'designation' => 'Superintendent of Police',
        'posting_city' => 'Bankura',
        'posting_state' => 'West Bengal',
        'exam_year' => 2021,
        'all_india_rank' => 45,
        'status' => 'active',
    ]);

    AlumniService::createAlumni([
        'first_name' => 'Amitava',
        'last_name' => 'Banerjee',
        'email' => 'amitava.wbcs@example.com',
        'service_type' => 'West Bengal Civil Service (WBCS)',
        'service_name' => 'WBCS (Exe)',
        'designation' => 'Block Development Officer',
        'posting_city' => 'Cooch Behar',
        'posting_state' => 'West Bengal',
        'exam_year' => 2022,
        'state_rank' => 8,
        'status' => 'active',
    ]);

    // Test list endpoint
    $response = $this->getJson(route('api.v1.alumni.index'));
    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'uuid',
                    'first_name',
                    'last_name',
                    'full_name',
                    'service_type',
                    'service_name',
                    'designation',
                    'current_posting' => [
                        'designation',
                        'city',
                        'state',
                    ],
                ],
            ],
            'links',
            'meta',
        ]);

    // Test search filter
    $searchResponse = $this->getJson(route('api.v1.alumni.index', ['search' => 'Priyanka']));
    $searchResponse->assertOk()
        ->assertJsonFragment(['full_name' => 'Priyanka Roy'])
        ->assertJsonMissing(['full_name' => 'Amitava Banerjee']);

    // Test service_type filter
    $serviceFilter = $this->getJson(route('api.v1.alumni.index', ['service_type' => 'West Bengal Civil Service (WBCS)']));
    $serviceFilter->assertOk()
        ->assertJsonFragment(['full_name' => 'Amitava Banerjee'])
        ->assertJsonMissing(['full_name' => 'Priyanka Roy']);
});

test('api can fetch detailed alumni dossier with multiple examinations and posting progression by id or uuid', function () {
    $alumnus = AlumniService::createAlumni(
        data: [
            'first_name' => 'Sandip',
            'last_name' => 'Mukherjee',
            'email' => 'sandip.ias@example.com',
            'service_type' => 'UPSC CSE',
            'service_name' => 'IAS',
            'designation' => 'District Magistrate',
            'posting_city' => 'Murshidabad',
            'posting_state' => 'West Bengal',
            'exam_year' => 2023,
            'all_india_rank' => 15,
            'status' => 'active',
        ],
        examinations: [
            [
                'examination_name' => 'WBCS Executive Exam',
                'exam_year' => 2020,
                'service_allotted' => 'WBCS (Exe)',
                'state_rank' => 5,
                'is_primary' => false,
            ],
            [
                'examination_name' => 'UPSC Civil Services Examination',
                'exam_year' => 2023,
                'service_allotted' => 'IAS',
                'all_india_rank' => 15,
                'is_primary' => true,
            ],
        ],
        postings: [
            [
                'designation' => 'District Magistrate & District Collector',
                'department_ministry' => 'General Administration',
                'posting_city' => 'Murshidabad',
                'posting_state' => 'West Bengal',
                'from_date' => '2023-08-01',
                'is_current' => true,
            ],
        ]
    );

    // Fetch by ID
    $idResponse = $this->getJson(route('api.v1.alumni.show', ['identifier' => $alumnus->id]));
    $idResponse->assertOk()
        ->assertJsonPath('data.full_name', 'Sandip Mukherjee')
        ->assertJsonPath('data.all_india_rank', 15)
        ->assertJsonCount(2, 'data.examination_selections')
        ->assertJsonCount(1, 'data.posting_history');

    // Fetch by UUID
    $uuidResponse = $this->getJson(route('api.v1.alumni.show', ['identifier' => $alumnus->uuid]));
    $uuidResponse->assertOk()
        ->assertJsonPath('data.uuid', $alumnus->uuid);

    // Not found returns 404
    $missingResponse = $this->getJson(route('api.v1.alumni.show', ['identifier' => 'non-existent-uuid']));
    $missingResponse->assertNotFound();
});

test('api can fetch featured spotlight alumni and statistics', function () {
    AlumniService::createAlumni([
        'first_name' => 'Rina',
        'last_name' => 'Karmakar',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IAS',
        'designation' => 'Secretary',
        'is_featured' => true,
        'status' => 'active',
    ]);

    $featuredResponse = $this->getJson(route('api.v1.alumni.featured'));
    $featuredResponse->assertOk()
        ->assertJsonFragment(['full_name' => 'Rina Karmakar']);

    $statsResponse = $this->getJson(route('api.v1.alumni.statistics'));
    $statsResponse->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'total_alumni',
                'by_service_type',
                'by_exam_year',
            ],
        ]);
});

test('admin can manage dynamic service categories via Livewire', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::admin.alumni.index')
        ->assertOk()
        ->call('openCategoriesModal')
        ->assertSet('showCategoriesModal', true)
        ->assertSee('Manage Alumni Service Categories')
        ->assertSee('UPSC CSE')
        ->assertSee('West Bengal Civil Service (WBCS)')
        // Open Create Modal
        ->call('openCreateCategoryModal')
        ->assertSet('showCategoryFormModal', true)
        ->set('categoryForm.name', 'West Bengal Audit & Accounts Service')
        ->set('categoryForm.code', 'WBAAS')
        ->set('categoryForm.short_name', 'WBAAS')
        ->set('categoryForm.description', 'State financial and accounts administration')
        ->set('categoryForm.icon', 'scale')
        ->set('categoryForm.badge_color', 'emerald')
        ->call('saveCategory');

    $category = AlumniServiceCategory::where('code', 'WBAAS')->first();
    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('West Bengal Audit & Accounts Service')
        ->and($category->icon)->toBe('scale')
        ->and($category->is_system)->toBeFalse();

    // Edit Category
    $component->call('editCategory', $category->id)
        ->assertSet('editingCategoryId', $category->id)
        ->set('categoryForm.short_name', 'WBA&AS')
        ->call('saveCategory');

    expect($category->fresh()->short_name)->toBe('WBA&AS');

    // Toggle Status
    $component->call('toggleCategoryStatus', $category->id);
    expect($category->fresh()->is_active)->toBeFalse();

    $component->call('toggleCategoryStatus', $category->id);
    expect($category->fresh()->is_active)->toBeTrue();

    // Delete custom category
    $component->call('deleteCategory', $category->id);
    expect(AlumniServiceCategory::find($category->id))->toBeNull();
});

test('system default service categories cannot be deleted', function () {
    $this->actingAs($this->admin);

    $systemCategory = AlumniServiceCategory::where('is_system', true)->first();
    expect($systemCategory)->not->toBeNull();

    $service = app(AlumniServiceCategoryService::class);

    expect(fn () => $service->deleteCategory($systemCategory, $this->admin))
        ->toThrow(ValidationException::class);
});

test('service category assigned to an alumnus cannot be deleted', function () {
    $this->actingAs($this->admin);

    $customCat = AlumniServiceCategory::create([
        'name' => 'Custom Railway Service',
        'code' => 'CUSTOM-RAILWAY',
        'is_system' => false,
        'is_active' => true,
    ]);

    AlumniService::createAlumni([
        'first_name' => 'Subir',
        'last_name' => 'Bose',
        'service_type' => 'CUSTOM-RAILWAY',
        'service_name' => 'IRTS',
        'designation' => 'Divisional Operations Manager',
    ]);

    $service = app(AlumniServiceCategoryService::class);

    expect(fn () => $service->deleteCategory($customCat, $this->admin))
        ->toThrow(ValidationException::class);
});

test('creating alumni with dynamic service category automatically links service_category_id', function () {
    $this->actingAs($this->admin);

    $customCat = AlumniServiceCategory::create([
        'name' => 'Indian Economic Service',
        'code' => 'IES',
        'is_system' => false,
        'is_active' => true,
    ]);

    $alumnus = AlumniService::createAlumni([
        'first_name' => 'Ananya',
        'last_name' => 'Chatterjee',
        'service_type' => 'IES',
        'service_name' => 'IES',
        'designation' => 'Assistant Director',
    ]);

    expect($alumnus->service_category_id)->toBe($customCat->id)
        ->and($alumnus->serviceCategory->name)->toBe('Indian Economic Service');
});

test('api can fetch active service categories with alumni counts', function () {
    $customCat = AlumniServiceCategory::create([
        'name' => 'Forest Service Track',
        'code' => 'IFS-TRACK',
        'short_name' => 'IFS',
        'is_system' => false,
        'is_active' => true,
    ]);

    AlumniService::createAlumni([
        'first_name' => 'Tapan',
        'last_name' => 'Das',
        'service_type' => 'IFS-TRACK',
        'service_name' => 'IFS',
        'designation' => 'DFO',
    ]);

    $response = $this->getJson(route('api.v1.alumni.categories'));

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'code',
                    'short_name',
                    'description',
                    'icon',
                    'badge_color',
                    'is_system',
                    'alumni_count',
                ],
            ],
            'meta' => [
                'total_categories',
            ],
        ])
        ->assertJsonFragment([
            'code' => 'IFS-TRACK',
            'name' => 'Forest Service Track',
            'alumni_count' => 1,
        ]);
});
