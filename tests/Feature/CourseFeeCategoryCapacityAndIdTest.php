<?php

use App\Helpers\CourseData;
use App\Models\Batch;
use App\Models\BatchCourse;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Services\AdmissionRestrictionService;
use App\Services\RbacService;
use App\Services\StudentIdGeneratorService;
use Database\Seeders\AcademicSeeder;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
});

test('course categories and fee structures are cleanly provided by CourseData helper class', function () {
    $categories = CourseData::getCategories();

    expect($categories)->toBeArray()
        ->and($categories)->toHaveKeys(['Classroom', 'Crash Course', 'Guidance', 'Interview', 'Test Series'])
        ->and(CourseData::getCategoryKeys())->toContain('Classroom', 'Crash Course');

    $feeTypes = CourseData::getFeeTypes();
    expect($feeTypes)->toHaveKeys(['monthly', 'one_time']);

    $units = CourseData::getDurationUnits();
    expect($units)->toHaveKeys(['days', 'weeks', 'months', 'years', 'custom']);
});

test('course fee calculates depending on student category: CC is monthly 1000 for UR and 500 for reserved, PCC is one-time 1500 for UR and 750 for reserved', function () {
    $cc = Course::where('code', 'CC')->first();
    $pcc = Course::where('code', 'PCC')->first();

    // Verify CC fee structure
    expect($cc->fee_type)->toBe('monthly')
        ->and($cc->duration)->toBe('10 Months')
        ->and($cc->capacity)->toBe(200);

    // CC fees
    expect(CourseData::calculateFee($cc, 'UR'))->toBe(1000.0)
        ->and(CourseData::calculateFee($cc, 'General'))->toBe(1000.0)
        ->and(CourseData::calculateFee($cc, 'SC'))->toBe(500.0)
        ->and(CourseData::calculateFee($cc, 'ST'))->toBe(500.0)
        ->and(CourseData::calculateFee($cc, 'OBC'))->toBe(500.0)
        ->and(CourseData::calculateFee($cc, 'EWS'))->toBe(500.0);

    // Verify PCC fee structure
    expect($pcc->fee_type)->toBe('one_time')
        ->and($pcc->duration)->toBe('3 Months')
        ->and($pcc->capacity)->toBeNull(); // Unlimited

    // PCC fees
    expect(CourseData::calculateFee($pcc, 'UR'))->toBe(1500.0)
        ->and(CourseData::calculateFee($pcc, 'General'))->toBe(1500.0)
        ->and(CourseData::calculateFee($pcc, 'SC'))->toBe(750.0)
        ->and(CourseData::calculateFee($pcc, 'ST'))->toBe(750.0)
        ->and(CourseData::calculateFee($pcc, 'OBC'))->toBe(750.0)
        ->and(CourseData::calculateFee($pcc, 'EWS'))->toBe(750.0);
});

test('capacity can be defined for both batch and courses: CC is capped at 200 while PCC is unlimited under the same batch', function () {
    $batch = Batch::where('code', 'BATCH-2027')->first();
    $cc = Course::where('code', 'CC')->first();
    $pcc = Course::where('code', 'PCC')->first();

    // Both courses belong to the same batch
    expect($batch->courses->pluck('code'))->toContain('CC', 'PCC');

    // Composite Course has max intake capacity of 200
    expect($cc->capacity)->toBe(200);
    // PCC has unlimited capacity
    expect($pcc->capacity)->toBeNull();

    // Test AdmissionRestrictionService respects course capacity
    $student = User::factory()->create();

    // When CC capacity is artificially lowered to 2 for testing
    $ccPivot = BatchCourse::where('batch_id', $batch->id)->where('course_id', $cc->id)->first();
    $ccPivot->update(['capacity' => 2]);

    // Enroll 2 students in CC under Batch 2027
    $s1 = User::factory()->create();
    $s2 = User::factory()->create();
    Enrollment::create([
        'user_id' => $s1->id,
        'batch_id' => $batch->id,
        'course_id' => $cc->id,
        'enrolled_at' => now(),
        'status' => 'active',
    ]);
    Enrollment::create([
        'user_id' => $s2->id,
        'batch_id' => $batch->id,
        'course_id' => $cc->id,
        'enrolled_at' => now(),
        'status' => 'active',
    ]);

    // 3rd student attempting to join CC should be blocked due to capacity
    $checkCC = AdmissionRestrictionService::checkEligibility($student, $cc, $batch);
    expect($checkCC['allowed'])->toBeFalse()
        ->and($checkCC['reason'])->toContain('maximum intake capacity of 2 students');

    // But attempting to join PCC under the exact same batch is allowed (PCC is unlimited)
    $checkPCC = AdmissionRestrictionService::checkEligibility($student, $pcc, $batch);
    expect($checkPCC['allowed'])->toBeTrue();
});

test('sections can belong to multiple courses or be expanded to any future course', function () {
    $batch = Batch::where('code', 'BATCH-2027')->first();
    $pcc = Course::where('code', 'PCC')->first();

    // Create a section under PCC (expanding sections to PCC)
    $pccSection = Section::create([
        'batch_id' => $batch->id,
        'course_id' => $pcc->id,
        'name' => 'PCC Fast-Track Section 1',
        'code' => '3',
        'capacity' => 60,
        'status' => 'active',
    ]);

    expect($pccSection->id)->not->toBeNull()
        ->and($pccSection->course_id)->toBe($pcc->id)
        ->and($pccSection->batch_id)->toBe($batch->id);

    // Also support course-agnostic / shared sections across the batch
    $sharedSection = Section::create([
        'batch_id' => $batch->id,
        'course_id' => null, // Shared across courses
        'name' => 'General Reading Room Section',
        'code' => '9',
        'capacity' => 100,
        'status' => 'active',
    ]);

    expect($sharedSection->id)->not->toBeNull()
        ->and($sharedSection->course_id)->toBeNull();
});

test('student id default configuration generates 9 digits with batch year, section/course identifier, and sequence', function () {
    $batch2027 = Batch::where('code', 'BATCH-2027')->first();
    $cc = Course::where('code', 'CC')->first();
    $pcc = Course::where('code', 'PCC')->first();

    // 1. CC Section A identifier is 1
    $secA = Section::firstOrCreate(
        ['batch_id' => $batch2027->id, 'course_id' => $cc->id, 'code' => '1'],
        ['name' => 'Section A', 'capacity' => 45, 'status' => 'active']
    );
    $studentA = User::factory()->create(['student_id' => null]);
    $idA = StudentIdGeneratorService::generateForEnrollment($studentA, $cc, $secA, $batch2027->academic_year);

    expect($idA)->toBe('202710001')
        ->and(strlen($idA))->toBe(9);

    // 2. CC Section B identifier is 2
    $secB = Section::firstOrCreate(
        ['batch_id' => $batch2027->id, 'course_id' => $cc->id, 'code' => '2'],
        ['name' => 'Section B', 'capacity' => 45, 'status' => 'active']
    );
    $studentB = User::factory()->create(['student_id' => null]);
    $idB = StudentIdGeneratorService::generateForEnrollment($studentB, $cc, $secB, $batch2027->academic_year);

    expect($idB)->toBe('202720001')
        ->and(strlen($idB))->toBe(9);

    // 3. PCC identifier is 3 (without sections)
    $studentPcc = User::factory()->create(['student_id' => null]);
    $idPcc = StudentIdGeneratorService::generateForEnrollment($studentPcc, $pcc, null, $batch2027->academic_year);

    expect($idPcc)->toBe('202730001')
        ->and(strlen($idPcc))->toBe(9);
});

test('multi-course re-enrollment solution preserves enrollment-specific student id and updates active student id', function () {
    $batch2026 = Batch::where('code', 'BATCH-2026')->first();
    $batch2027 = Batch::where('code', 'BATCH-2027')->first();

    $cc = Course::where('code', 'CC')->first();
    $pcc = Course::where('code', 'PCC')->first();
    $secA = Section::firstOrCreate(
        ['batch_id' => $batch2027->id, 'course_id' => $cc->id, 'code' => '1'],
        ['name' => 'Section A', 'capacity' => 45, 'status' => 'active']
    );

    // Candidate John Doe enrolls in PCC in 2026 Batch
    $student = User::factory()->create(['name' => 'John Doe', 'student_id' => null]);

    $pccId = StudentIdGeneratorService::generateForEnrollment($student, $pcc, null, $batch2026->academic_year);
    expect($pccId)->toBe('202630001');

    $enr2026 = Enrollment::create([
        'user_id' => $student->id,
        'student_id' => $pccId,
        'batch_id' => $batch2026->id,
        'course_id' => $pcc->id,
        'enrolled_at' => '2026-02-01',
        'status' => 'completed',
        'fee_amount' => 1500.00,
    ]);

    expect($student->fresh()->student_id)->toBe('202630001')
        ->and($enr2026->student_id)->toBe('202630001');

    // Later in 2027, the same student gets admitted into Composite Course Section A
    $ccId = StudentIdGeneratorService::generateForEnrollment($student, $cc, $secA, $batch2027->academic_year);
    expect($ccId)->toBe('202710001');

    $enr2027 = Enrollment::create([
        'user_id' => $student->id,
        'student_id' => $ccId,
        'batch_id' => $batch2027->id,
        'course_id' => $cc->id,
        'section_id' => $secA->id,
        'enrolled_at' => '2027-01-10',
        'status' => 'active',
        'fee_amount' => 1000.00,
    ]);

    // 1. Current active student_id on User is updated to the active 2027 cohort ID
    expect($student->fresh()->student_id)->toBe('202710001');

    // 2. Both enrollments permanently retain their exact respective cohort student IDs!
    expect($enr2026->fresh()->student_id)->toBe('202630001')
        ->and($enr2027->fresh()->student_id)->toBe('202710001');

    // 3. In 2027 CC Section A roll list, student is correctly identified as 202710001
    $roster2027 = Enrollment::where('batch_id', $batch2027->id)->where('course_id', $cc->id)->first();
    expect($roster2027->student_id)->toBe('202710001');

    // 4. In 2026 PCC historical archive, student is correctly identified as 202630001
    $archive2026 = Enrollment::where('batch_id', $batch2026->id)->where('course_id', $pcc->id)->first();
    expect($archive2026->student_id)->toBe('202630001');
});

test('it supports free of cost / no fee courses and exempts fee', function () {
    // 1. CourseData fee types contains free option
    expect(CourseData::getFeeTypes())->toHaveKey('free')
        ->and(CourseData::getFeeTypes()['free'])->toContain('Free');

    // 2. Create a Free Course
    $freeCourse = Course::create([
        'name' => 'Free Civil Services Orientation Workshop',
        'code' => 'FCOW',
        'category' => 'Guidance',
        'fee' => 0.00,
        'fee_type' => 'free',
        'fee_reserved' => 0.00,
        'capacity' => null,
        'has_sections' => false,
        'identifier_code' => '7',
        'status' => 'active',
    ]);

    expect($freeCourse->isFree())->toBeTrue()
        ->and(CourseData::calculateFee($freeCourse, 'UR'))->toBe(0.0)
        ->and(CourseData::calculateFee($freeCourse, 'SC'))->toBe(0.0)
        ->and(CourseData::getFeeDescription($freeCourse))->toContain('Free of Cost / No Fee');

    // 3. Admin user creates enrollment for student in free course
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    $batch = Batch::where('code', 'BATCH-2026')->first();
    $student = User::factory()->create(['name' => 'Free Course Aspirant']);
    $student->assignRole('Student');

    $test = Livewire::test('pages::admin.enrollments')
        ->call('create')
        ->set('form.user_id', (string) $student->id)
        ->set('form.batch_id', (string) $batch->id)
        ->set('form.course_id', (string) $freeCourse->id);

    // Fee amount should be auto-recalculated to 0.00 and fee_status set to exempted
    expect((float) $test->get('form.fee_amount'))->toBe(0.0)
        ->and($test->get('form.fee_status'))->toBe('exempted');

    // Save enrollment
    $test->call('save')
        ->assertHasNoErrors();

    $enr = Enrollment::where('user_id', $student->id)->where('course_id', $freeCourse->id)->first();
    expect($enr)->not->toBeNull()
        ->and((float) $enr->fee_amount)->toBe(0.0)
        ->and($enr->fee_status)->toBe('exempted')
        ->and($enr->status)->toBe('active');
});

test('section assignment is available for composite course across batches in enrollments', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    $cc = Course::where('code', 'CC')->first();
    $batch2026 = Batch::where('code', 'BATCH-2026')->first();
    $batch2027 = Batch::where('code', 'BATCH-2027')->first();

    // 1. For Batch 2026, CC sections should be Section A and Section B
    $component = Livewire::test('pages::admin.enrollments')
        ->call('create')
        ->set('form.batch_id', (string) $batch2026->id)
        ->set('form.course_id', (string) $cc->id);

    $sec2026 = $component->get('formSections');
    expect($sec2026)->toHaveCount(2)
        ->and($sec2026->pluck('name')->all())->toContain('Section A', 'Section B')
        ->and($sec2026->pluck('code')->all())->toContain('1', '2');

    // 2. For Batch 2027, CC sections should also be available (Section A and Section B)
    $component->set('form.batch_id', (string) $batch2027->id);

    $sec2027 = $component->get('formSections');
    expect($sec2027)->toHaveCount(2)
        ->and($sec2027->pluck('name')->all())->toContain('Section A', 'Section B')
        ->and($sec2027->pluck('code')->all())->toContain('1', '2');

    // 3. For a course without sections (PCC), formSections is empty
    $pcc = Course::where('code', 'PCC')->first();
    $component->set('form.course_id', (string) $pcc->id);
    expect($component->get('formSections'))->toHaveCount(0);
});

test('sections management CRUD correctly links course, batch and displays identifier code', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    $batch = Batch::where('code', 'BATCH-2026')->first();
    $cc = Course::where('code', 'CC')->first();

    // Render sections page
    Livewire::test('pages::admin.sections')
        ->assertSee('Sections Management')
        ->assertSee('Section A')
        ->assertSee('Section B')
        ->assertSee('ID Code: 1')
        ->assertSee('ID Code: 2')
        ->assertSee('Classroom')
        ->assertSee('2026 Batch');

    // Create a new section
    Livewire::test('pages::admin.sections')
        ->call('create')
        ->set('form.batch_id', (string) $batch->id)
        ->set('form.course_id', (string) $cc->id)
        ->set('form.name', 'Section C (Weekend)')
        ->set('form.code', '3')
        ->set('form.capacity', 50)
        ->set('form.status', 'active')
        ->call('save')
        ->assertHasNoErrors();

    $secC = Section::where('name', 'Section C (Weekend)')->first();
    expect($secC)->not->toBeNull()
        ->and($secC->code)->toBe('3')
        ->and($secC->batch_id)->toBe($batch->id)
        ->and($secC->course_id)->toBe($cc->id);
});

test('system settings student id generation preview reflects batch academic year, course, and section 9-digit format', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    // 1. Preview CC Section A => Year(2027) + Identifier(1) + 0001 = 202710001 (9 digits)
    $component = Livewire::test('pages::admin.settings.system')
        ->set('previewYear', '2027')
        ->set('studentIdConfig.sequence_length', 4)
        ->set('studentIdConfig.course_identifiers.CC', '1')
        ->set('studentIdConfig.section_identifiers.Section A', '1')
        ->set('previewSelectedCourse', 'CC')
        ->set('previewSelectedSection', 'Section A');

    $preview = $component->get('previewGeneratedId');
    expect($preview['year'])->toBe('2027')
        ->and($preview['identifier'])->toBe('1')
        ->and($preview['sample_id'])->toBe('202710001')
        ->and($preview['length'])->toBe(9)
        ->and($preview['is_valid'])->toBeTrue();

    // 2. Preview CC Section B => Year(2027) + Identifier(2) + 0001 = 202720001 (9 digits)
    $component->set('studentIdConfig.section_identifiers.Section B', '2')
        ->set('previewSelectedSection', 'Section B');

    $previewB = $component->get('previewGeneratedId');
    expect($previewB['sample_id'])->toBe('202720001')
        ->and($previewB['length'])->toBe(9);

    // 3. Preview PCC (No Section) => Year(2026) + Identifier(3) + 0001 = 202630001 (9 digits)
    $component->set('previewYear', '2026')
        ->set('studentIdConfig.course_identifiers.PCC', '3')
        ->set('previewSelectedCourse', 'PCC')
        ->set('previewSelectedSection', '');

    $previewPcc = $component->get('previewGeneratedId');
    expect($previewPcc['sample_id'])->toBe('202630001')
        ->and($previewPcc['length'])->toBe(9);
});
