<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ProfileUpdateRequest;
use App\Models\Section;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AdmissionRestrictionService;
use App\Services\RbacService;
use App\Services\StudentIdGeneratorService;
use App\Services\StudentImportExportService;
use App\Services\UserStudentSyncService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
});

test('batch can be created with academic year and courses associated', function () {
    $batch = Batch::create([
        'name' => '2028 UPSC Batch',
        'code' => 'BATCH-2028',
        'academic_year' => '2028',
        'start_date' => '2028-01-01',
        'end_date' => '2028-12-31',
        'status' => 'upcoming',
        'max_students' => 150,
        'description' => 'Comprehensive batch for CSE 2028.',
    ]);

    expect($batch->id)->not->toBeNull()
        ->and($batch->code)->toBe('BATCH-2028')
        ->and($batch->academic_year)->toBe('2028')
        ->and($batch->status)->toBe('upcoming');

    $cc = Course::where('code', 'CC')->first();
    $pcc = Course::where('code', 'PCC')->first();

    $batch->courses()->attach([
        $cc->id => ['capacity' => 50, 'fee' => 15000, 'status' => 'active'],
        $pcc->id => ['capacity' => 60, 'fee' => 7500, 'status' => 'active'],
    ]);

    expect($batch->courses)->toHaveCount(2)
        ->and($batch->courses->pluck('code'))->toContain('CC', 'PCC');
});

test('course can configure optional sections and admit once only restrictions', function () {
    $course = Course::create([
        'name' => 'Advanced GS Mains Mentorship',
        'code' => 'AGSM',
        'category' => 'Guidance',
        'duration' => '14 Weeks',
        'fee' => 14000.00,
        'has_sections' => true,
        'identifier_code' => '7',
        'admit_once_only' => true,
        'max_attempts' => 1,
        'status' => 'active',
    ]);

    expect($course->has_sections)->toBeTrue()
        ->and($course->admit_once_only)->toBeTrue()
        ->and($course->identifier_code)->toBe('7');
});

test('sections can be assigned to batch and course with capacity tracking', function () {
    $batch = Batch::where('code', 'BATCH-2026')->first();
    $cc = Course::where('code', 'CC')->first();

    $section = Section::create([
        'batch_id' => $batch->id,
        'course_id' => $cc->id,
        'name' => 'Section C (Weekend)',
        'code' => '3',
        'capacity' => 30,
        'room_number' => 'Hall 3',
        'timing' => 'Sat–Sun · 10:00–14:00',
        'status' => 'active',
    ]);

    expect($section->id)->not->toBeNull()
        ->and($section->batch->code)->toBe('BATCH-2026')
        ->and($section->course->code)->toBe('CC')
        ->and($section->is_full)->toBeFalse()
        ->and($section->enrolled_count)->toBe(0);
});

test('student id generator generates 9 to 10 digit unique lifetime id for section A and section B', function () {
    $batch = Batch::where('code', 'BATCH-2026')->first();
    $cc = Course::where('code', 'CC')->first();
    $secA = Section::where('batch_id', $batch->id)->where('code', '1')->first();
    $secB = Section::where('batch_id', $batch->id)->where('code', '2')->first();

    $studentA = User::factory()->create(['student_id' => null]);
    $idA = StudentIdGeneratorService::generateForStudent($studentA, $cc, $secA, $batch->academic_year);

    expect($idA)->toBe('202610001')
        ->and(strlen($idA))->toBeLessThanOrEqual(10)
        ->and($studentA->fresh()->student_id)->toBe('202610001');

    $studentB = User::factory()->create(['student_id' => null]);
    $idB = StudentIdGeneratorService::generateForStudent($studentB, $cc, $secB, $batch->academic_year);

    expect($idB)->toBe('202620001')
        ->and(strlen($idB))->toBeLessThanOrEqual(10)
        ->and($studentB->fresh()->student_id)->toBe('202620001');
});

test('lifetime student id is permanently preserved when student enrolls in subsequent courses or batches', function () {
    $batch2026 = Batch::where('code', 'BATCH-2026')->first();
    $cc = Course::where('code', 'CC')->first();
    $secA = Section::where('batch_id', $batch2026->id)->where('code', '1')->first();

    $student = User::factory()->create(['student_id' => null]);
    $firstId = StudentIdGeneratorService::generateForStudent($student, $cc, $secA, '2026');

    expect($firstId)->toBe('202610001');

    // 2 years later student returns to enroll in Mains Guidance Programme in 2028 batch
    $mgp = Course::where('code', 'MGP')->first();
    $secondId = StudentIdGeneratorService::generateForStudent($student, $mgp, null, '2028');

    // Student MUST retain their original lifetime ID!
    expect($secondId)->toBe('202610001')
        ->and($student->fresh()->student_id)->toBe('202610001');
});

test('admission restriction service blocks duplicate admission for admit once only courses', function () {
    $batch = Batch::where('code', 'BATCH-2026')->first();
    $cc = Course::where('code', 'CC')->first(); // admit_once_only = true
    $secA = Section::where('batch_id', $batch->id)->where('code', '1')->first();

    $student = User::factory()->create();

    // First enrollment succeeds
    Enrollment::create([
        'user_id' => $student->id,
        'batch_id' => $batch->id,
        'course_id' => $cc->id,
        'section_id' => $secA->id,
        'enrolled_at' => now(),
        'fee_amount' => 15000,
        'status' => 'active',
    ]);

    // Attempting to enroll again into Composite Course
    $batch2027 = Batch::where('code', 'BATCH-2027')->first();
    $check = AdmissionRestrictionService::checkEligibility($student, $cc, $batch2027, null, false);

    expect($check['allowed'])->toBeFalse()
        ->and($check['reason'])->toContain('Institute policy permits only one-time admission for this programme.');

    // With administrator override, allowed is true with warning
    $checkOverride = AdmissionRestrictionService::checkEligibility($student, $cc, $batch2027, null, true);
    expect($checkOverride['allowed'])->toBeTrue()
        ->and($checkOverride['warnings'])->not->toBeEmpty();
});

test('admission restriction service blocks duplicate enrollment in same course under same batch', function () {
    $batch = Batch::where('code', 'BATCH-2026')->first();
    $pcc = Course::where('code', 'PCC')->first(); // admit_once_only = false

    $student = User::factory()->create();

    Enrollment::create([
        'user_id' => $student->id,
        'batch_id' => $batch->id,
        'course_id' => $pcc->id,
        'enrolled_at' => now(),
        'fee_amount' => 7500,
        'status' => 'active',
    ]);

    // Cannot enroll twice in PCC under the same 2026 batch
    $check = AdmissionRestrictionService::checkEligibility($student, $pcc, $batch, null, false);
    expect($check['allowed'])->toBeFalse()
        ->and($check['reason'])->toContain('Candidate is already enrolled in');
});

test('student dossier supports basic profile, addresses, qualifications, employment, and documents', function () {
    $student = User::factory()->create();

    // Profile
    $profile = StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Debabrata Sen',
        'mother_name' => 'Mita Sen',
        'dob' => '2001-05-14',
        'gender' => 'male',
        'category' => 'SC',
        'subcaste' => 'Rajbanshi',
        'distance' => 12.50,
        'family_income' => 240000.00,
        'highest_edu' => 'Graduation',
    ]);

    // Address
    $address = StudentAddress::create([
        'user_id' => $student->id,
        'address_type' => 'Permanent',
        'state' => 'West Bengal',
        'district' => 'Kolkata',
        'address_line1' => 'Sector 1, Salt Lake',
        'pin_code' => '700064',
    ]);

    // Education
    $edu = StudentEducation::create([
        'user_id' => $student->id,
        'level' => 'Graduation',
        'board_university' => 'Calcutta University',
        'stream_subject' => 'History Honours',
        'passing_year' => 2023,
        'percentage' => 72.50,
        'is_highest' => true,
    ]);

    // Employment
    $emp = StudentEmployment::create([
        'user_id' => $student->id,
        'employer_name' => 'WB State Corp',
        'designation' => 'Executive Officer',
        'is_current' => true,
    ]);

    // Document
    $doc = StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'caste_certificate',
        'file_path' => 'documents/caste_cert_01.pdf',
        'file_name' => 'caste_cert.pdf',
        'verified' => false,
    ]);

    $student->refresh();

    expect($student->studentProfile->father_name)->toBe('Debabrata Sen')
        ->and($student->permanent_address->state)->toBe('West Bengal')
        ->and($student->studentEducations)->toHaveCount(1)
        ->and($student->studentEmployments)->toHaveCount(1)
        ->and($student->studentDocuments)->toHaveCount(1);
});

test('profile correction request workflow requires administrator approval before applying changes', function () {
    $student = User::factory()->create([
        'name' => 'Ananya Roy',
        'phone' => '9830011111',
    ]);
    $student->assignRole('Student');

    // Student requests to update phone number and father name
    $req = ProfileUpdateRequest::create([
        'user_id' => $student->id,
        'section' => 'basic',
        'payload' => [
            'phone' => '9830099999',
            'father_name' => 'Tapan Kumar Roy',
        ],
        'current_data' => [
            'phone' => '9830011111',
            'father_name' => 'Tapan Roy',
        ],
        'reason' => 'Corrected father full name and updated mobile number.',
        'status' => 'pending',
    ]);

    expect($req->status)->toBe('pending');
    // Ensure student phone is not changed yet!
    expect($student->fresh()->phone)->toBe('9830011111');

    // Admin reviews and approves
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    DB::transaction(function () use ($req, $admin, $student) {
        $student->update(['phone' => $req->payload['phone']]);
        StudentProfile::updateOrCreate(
            ['user_id' => $student->id],
            ['father_name' => $req->payload['father_name']]
        );

        $req->update([
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => 'Verified with supporting documentation.',
        ]);
    });

    expect($req->fresh()->status)->toBe('approved')
        ->and($student->fresh()->phone)->toBe('9830099999')
        ->and($student->fresh()->studentProfile?->father_name)->toBe('Tapan Kumar Roy');
});

test('sample import templates can be downloaded for students and enrollments', function () {
    $csvTemplate = StudentImportExportService::downloadStudentTemplate('csv');
    expect($csvTemplate)->not->toBeNull();

    $enrTemplate = StudentImportExportService::downloadEnrollmentTemplate('csv');
    expect($enrTemplate)->not->toBeNull();
});

test('student registration in admin panel enforces student role and unifies profile and address', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.students')
        ->call('createStudent')
        ->set('form.name', 'Pooja Ghosh')
        ->set('form.first_name', 'Pooja')
        ->set('form.last_name', 'Ghosh')
        ->set('form.email', 'pooja.ghosh@example.com')
        ->set('form.phone', '9876501234')
        ->set('form.dob', '2001-09-18')
        ->set('form.gender', 'female')
        ->set('form.category', 'OBCB')
        ->set('form.father_name', 'Bimal Ghosh')
        ->set('form.state', 'West Bengal')
        ->set('form.district', 'Howrah')
        ->set('form.address_line1', '12 GT Road')
        ->set('form.pin_code', '711101')
        ->call('saveStudent')
        ->assertHasNoErrors();

    $student = User::where('email', 'pooja.ghosh@example.com')->first();
    expect($student)->not->toBeNull()
        ->and($student->hasRole('Student'))->toBeTrue()
        ->and($student->student_id)->not->toBeEmpty()
        ->and($student->dob->format('Y-m-d'))->toBe('2001-09-18')
        ->and($student->gender)->toBe('female')
        ->and($student->studentProfile)->not->toBeNull()
        ->and($student->studentProfile->dob->format('Y-m-d'))->toBe('2001-09-18')
        ->and($student->studentProfile->gender)->toBe('female')
        ->and($student->studentProfile->father_name)->toBe('Bimal Ghosh')
        ->and($student->permanent_address)->not->toBeNull()
        ->and($student->permanent_address->district)->toBe('Howrah');
});

test('updating user dob or gender automatically synchronizes with student profile', function () {
    $student = User::factory()->create([
        'name' => 'Dipak Nandi',
        'dob' => '2000-01-01',
        'gender' => 'male',
    ]);
    $student->assignRole('Student');
    UserStudentSyncService::syncFromUser($student);

    // Profile was created with initial data
    expect($student->fresh()->studentProfile)->not->toBeNull()
        ->and($student->fresh()->studentProfile->dob->format('Y-m-d'))->toBe('2000-01-01');

    // Update user dob and gender directly
    $student->update([
        'dob' => '2000-05-15',
        'gender' => 'male',
    ]);

    expect($student->fresh()->studentProfile->dob->format('Y-m-d'))->toBe('2000-05-15');
});
