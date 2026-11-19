<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Models\Section;
use App\Models\User;
use App\Services\AcademicImportService;
use App\Services\ApiKey\ApiKeyService;
use App\Services\PartnerInstituteService;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RbacService::seedDefaults();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $apiKeyService = app(ApiKeyService::class);
    $keyData = $apiKeyService->generateKey([
        'name' => 'Academics Test Key',
        'type' => 'backend_service',
        'environment' => 'sandbox',
        'rate_limit_per_minute' => 300,
        'scopes' => ['ping', 'read:academics', 'write:academics'],
        'description' => 'Test key for full academic operations',
    ], $this->admin);
    $this->plainKey = $keyData['plainTextKey'];
});

test('partner institute creates successfully with empty string dates without mysql error', function () {
    $this->actingAs($this->admin);

    $institute = PartnerInstituteService::createInstitute([
        'name' => 'Elite IAS Forum',
        'status' => 'active',
        'start_date' => '',
        'end_date' => '',
        'website_url' => 'www.eliteias.org',
        'email' => '',
        'phone' => '',
    ]);

    expect($institute)->toBeInstanceOf(PartnerInstitute::class);
    expect($institute->start_date)->toBeNull();
    expect($institute->end_date)->toBeNull();
    expect($institute->email)->toBeNull();
    expect($institute->website_url)->toBe('https://www.eliteias.org');
});

test('academic import service imports courses from csv', function () {
    $this->actingAs($this->admin);

    $csvContent = implode("\n", [
        'Course Code,Course Name,Category,Duration,Fee,Fee Reserved,Capacity,Has Sections,Status,Description',
        'UPSC-PRELIMS-2026,UPSC Prelims Master Programme,UPSC,1 Year,18000,9000,150,yes,active,Comprehensive Prelims Batch',
        'WBCS-MAINS-2026,WBCS Intensive Programme,WBCS,6 Months,12000,6000,80,no,active,Targeted Mains Guidance',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'test_courses_').'.csv';
    file_put_contents($tempCsv, $csvContent);

    $result = AcademicImportService::importCourses($tempCsv);

    expect($result['imported'])->toBe(2);
    expect($result['errors'])->toBeEmpty();

    $c1 = Course::where('code', 'UPSC-PRELIMS-2026')->first();
    expect($c1)->not->toBeNull();
    expect($c1->name)->toBe('UPSC Prelims Master Programme');
    expect((float) $c1->fee)->toBe(18000.0);
    expect($c1->has_sections)->toBeTrue();

    @unlink($tempCsv);
});

test('academic import service imports batches and links courses', function () {
    $this->actingAs($this->admin);

    $course = Course::create([
        'code' => 'UPSC-GS-TEST',
        'name' => 'General Studies Foundation',
        'category' => 'UPSC',
        'fee' => 10000,
        'fee_reserved' => 5000,
        'status' => 'active',
    ]);

    $csvContent = implode("\n", [
        'Batch Code,Batch Name,Academic Year,Start Date,End Date,Max Students,Status,Linked Course Codes,Description',
        'BATCH-2026-TEST,Test Batch 2026,2026-2027,2026-07-01,2027-05-31,120,active,UPSC-GS-TEST,Sample test batch description',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'test_batches_').'.csv';
    file_put_contents($tempCsv, $csvContent);

    $result = AcademicImportService::importBatches($tempCsv);

    expect($result['imported'])->toBe(1);
    expect($result['errors'])->toBeEmpty();

    $batch = Batch::where('code', 'BATCH-2026-TEST')->first();
    expect($batch)->not->toBeNull();
    expect($batch->courses->pluck('id')->all())->toContain($course->id);

    @unlink($tempCsv);
});

test('academic import service imports sections and links batch and course', function () {
    $this->actingAs($this->admin);

    $batch = Batch::create([
        'code' => 'BATCH-SEC-01',
        'name' => 'Batch Sections Test',
        'academic_year' => '2026-2027',
        'status' => 'active',
    ]);

    $course = Course::create([
        'code' => 'COURSE-SEC-01',
        'name' => 'Course Sections Test',
        'category' => 'UPSC',
        'status' => 'active',
    ]);

    $csvContent = implode("\n", [
        'Batch Code,Course Code,Section Name,Section Code,Capacity,Room Number,Shift/Timing,Status',
        'BATCH-SEC-01,COURSE-SEC-01,Section Alpha,SEC-ALPHA,50,Hall 1,09:00 AM - 12:00 PM,active',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'test_sections_').'.csv';
    file_put_contents($tempCsv, $csvContent);

    $result = AcademicImportService::importSections($tempCsv);

    expect($result['imported'])->toBe(1);
    expect($result['errors'])->toBeEmpty();

    $section = Section::where('batch_id', $batch->id)->where('course_id', $course->id)->first();
    expect($section)->not->toBeNull();
    expect($section->name)->toBe('Section Alpha');
    expect($section->capacity)->toBe(50);

    @unlink($tempCsv);
});

test('academic import service imports course plans', function () {
    $this->actingAs($this->admin);

    $batch = Batch::create([
        'code' => 'BATCH-CP-01',
        'name' => 'Batch CP Test',
        'academic_year' => '2026-2027',
        'status' => 'active',
    ]);

    $course = Course::create([
        'code' => 'COURSE-CP-01',
        'name' => 'Course CP Test',
        'category' => 'UPSC',
        'status' => 'active',
    ]);

    $csvContent = implode("\n", [
        'Plan Code,Plan Title,Batch Code,Course Code,Section Code,Plan Type,Academic Year,Start Date,End Date,Total Planned Lectures,Topics Covered,Status,Description',
        'CP-TEST-001,Polity Annual Roadmap,BATCH-CP-01,COURSE-CP-01,,annual,2026-2027,2026-07-01,2027-04-30,40,Constitution; Governance,published,Syllabus Blueprint',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'test_plans_').'.csv';
    file_put_contents($tempCsv, $csvContent);

    $result = AcademicImportService::importCoursePlans($tempCsv);

    expect($result['imported'])->toBe(1);
    expect($result['errors'])->toBeEmpty();

    $plan = CoursePlan::where('plan_code', 'CP-TEST-001')->first();
    expect($plan)->not->toBeNull();
    expect($plan->title)->toBe('Polity Annual Roadmap');
    expect($plan->total_lectures_planned)->toBe(40);

    @unlink($tempCsv);
});

test('academic import service imports faculty master with partner institute linkage', function () {
    $this->actingAs($this->admin);

    $partner = PartnerInstitute::create([
        'code' => 'INST-PARTNER-01',
        'name' => 'Kolkata IAS Mentors',
        'status' => 'active',
    ]);

    $csvContent = implode("\n", [
        'Faculty Code,Full Name,Email,Phone,Designation,Department,Faculty Type,Partner Institute Code or Name,Specialization,Qualification,Status,Competent Subjects,Biography',
        'FAC-TEST-001,Dr. Amit Roy,amit.roy@example.com,9876543210,Senior Faculty,History,guest,INST-PARTNER-01,Modern India,Ph.D.,active,"History, Polity",Experienced educator',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'test_faculty_').'.csv';
    file_put_contents($tempCsv, $csvContent);

    $result = AcademicImportService::importFaculties($tempCsv);

    expect($result['imported'])->toBe(1);
    expect($result['errors'])->toBeEmpty();

    $faculty = Faculty::where('email', 'amit.roy@example.com')->first();
    expect($faculty)->not->toBeNull();
    expect($faculty->name)->toBe('Dr. Amit Roy');
    expect($faculty->partner_institute_id)->toBe($partner->id);
    expect($faculty->faculty_type)->toBe('guest');
    expect($faculty->subjects)->toBe(['History', 'Polity']);

    @unlink($tempCsv);
});

test('template generators return valid downloadable responses', function () {
    $this->actingAs($this->admin);

    expect(AcademicImportService::generateCourseTemplate())->not->toBeNull();
    expect(AcademicImportService::generateBatchTemplate())->not->toBeNull();
    expect(AcademicImportService::generateSectionTemplate())->not->toBeNull();
    expect(AcademicImportService::generateCoursePlanTemplate())->not->toBeNull();
    expect(AcademicImportService::generateFacultyTemplate())->not->toBeNull();
});

test('partner institute service can assign batches, courses and sections', function () {
    $this->actingAs($this->admin);

    $batch1 = Batch::create([
        'code' => 'BATCH-PI-01',
        'name' => 'Batch PI One',
        'academic_year' => '2026-2027',
        'status' => 'active',
    ]);

    $batch2 = Batch::create([
        'code' => 'BATCH-PI-02',
        'name' => 'Batch PI Two',
        'academic_year' => '2026-2027',
        'status' => 'active',
    ]);

    $course1 = Course::create([
        'code' => 'COURSE-PI-01',
        'name' => 'Course PI One',
        'category' => 'UPSC',
        'status' => 'active',
    ]);

    $section1 = Section::create([
        'batch_id' => $batch1->id,
        'course_id' => $course1->id,
        'name' => 'Section PI A',
        'code' => 'SEC-PI-A',
        'status' => 'active',
    ]);

    $institute = PartnerInstituteService::createInstitute([
        'name' => 'Premier Civil Services Academy',
        'status' => 'active',
        'batches' => [$batch1->id, $batch2->id],
        'courses' => [$course1->id],
        'sections' => [$section1->id],
    ]);

    expect($institute->batches)->toHaveCount(2);
    expect($institute->courses)->toHaveCount(1);
    expect($institute->sections)->toHaveCount(1);
    expect($institute->batches->pluck('code')->all())->toContain('BATCH-PI-01', 'BATCH-PI-02');

    // Test update sync
    $updated = PartnerInstituteService::updateInstitute($institute, [
        'name' => 'Premier Civil Services Academy Updated',
        'batches' => [$batch1->id],
        'courses' => [],
        'sections' => [$section1->id],
    ]);

    $updated->load(['batches', 'courses', 'sections']);
    expect($updated->batches)->toHaveCount(1);
    expect($updated->courses)->toHaveCount(0);
    expect($updated->sections)->toHaveCount(1);
});

test('partner institute livewire component assigns batches courses and sections', function () {
    $this->actingAs($this->admin);

    $batch = Batch::create([
        'code' => 'BATCH-LW-01',
        'name' => 'Livewire Batch 01',
        'academic_year' => '2026-2027',
        'status' => 'active',
    ]);

    $course = Course::create([
        'code' => 'COURSE-LW-01',
        'name' => 'Livewire Course 01',
        'category' => 'UPSC',
        'status' => 'active',
    ]);

    $section = Section::create([
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'name' => 'Livewire Section 01',
        'code' => 'SEC-LW-01',
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.partner-institutes')
        ->set('form.name', 'Livewire Partner Academy')
        ->set('form.status', 'active')
        ->set('assigned_batches', [$batch->id])
        ->set('assigned_courses', [$course->id])
        ->set('assigned_sections', [$section->id])
        ->call('save')
        ->assertHasNoErrors();

    $inst = PartnerInstitute::where('name', 'Livewire Partner Academy')->first();
    expect($inst)->not->toBeNull();
    expect($inst->batches->pluck('id')->all())->toContain($batch->id);
    expect($inst->courses->pluck('id')->all())->toContain($course->id);
    expect($inst->sections->pluck('id')->all())->toContain($section->id);
});

test('partner institute api endpoints include batches courses and sections', function () {
    $this->actingAs($this->admin);

    $batch = Batch::create([
        'code' => 'BATCH-API-01',
        'name' => 'API Batch 01',
        'academic_year' => '2026-2027',
        'status' => 'active',
    ]);

    $course = Course::create([
        'code' => 'COURSE-API-01',
        'name' => 'API Course 01',
        'category' => 'UPSC',
        'status' => 'active',
    ]);

    $section = Section::create([
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'name' => 'API Section 01',
        'code' => 'SEC-API-01',
        'status' => 'active',
    ]);

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/partner-institutes', [
        'name' => 'API Partner Institute',
        'status' => 'active',
        'batches' => [$batch->id],
        'courses' => [$course->id],
        'sections' => [$section->id],
    ]);

    $response->assertStatus(201);
    $instituteId = $response->json('data.id');

    $showResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
        'Accept' => 'application/json',
    ])->getJson("/api/v1/partner-institutes/{$instituteId}");

    $showResponse->assertStatus(200);
    $showResponse->assertJsonPath('data.batches.0.id', $batch->id);
    $showResponse->assertJsonPath('data.courses.0.id', $course->id);
    $showResponse->assertJsonPath('data.sections.0.id', $section->id);
});

test('classroom livewire component renders properly and download template works', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.classrooms')
        ->assertStatus(200)
        ->call('openImportModal')
        ->assertDispatched('modal-open', name: 'classroom-import-modal');
});
