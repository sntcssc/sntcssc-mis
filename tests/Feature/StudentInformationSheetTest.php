<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\UpscAttempt;
use App\Models\User;
use App\Services\RbacService;
use App\Services\StudentInformationSheetService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'Dr. Arindam Sen',
        'email' => 'arindam.sen@sntcssc.edu',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->course = Course::create([
        'name' => 'Civil Services Executive Coaching',
        'code' => 'UPSC-EXEC',
        'fee' => 12000,
        'status' => 'active',
    ]);

    $this->batch = Batch::create([
        'name' => '2026 Foundation Batch',
        'code' => 'BATCH-2026-F',
        'academic_year' => '2026',
        'status' => 'active',
    ]);

    // Candidate 1
    $this->student1 = User::factory()->create([
        'name' => 'Debolina Banerjee',
        'email' => 'debolina.banerjee@example.com',
        'student_id' => 'STU-2026-0001',
        'urn' => 'URN-2026-0001',
        'phone' => '+91 9831122334',
        'whatsapp_no' => '+91 9831122334',
        'tenth_roll' => 'WB10-445566',
        'gender' => 'female',
        'status' => 'active',
    ]);
    $this->student1->assignRole('Student');

    StudentProfile::create([
        'user_id' => $this->student1->id,
        'father_name' => 'Shyamal Banerjee',
        'mother_name' => 'Gita Banerjee',
        'category' => 'UR',
        'family_income' => 450000,
        'is_working_professional' => false,
        'school_language' => 'English',
        'distance' => 12.5,
    ]);

    StudentAddress::create([
        'user_id' => $this->student1->id,
        'address_type' => 'Permanent',
        'address_line1' => 'Flat 4B, Greenfield City',
        'district' => 'Kolkata',
        'state' => 'West Bengal',
        'pin_code' => '700061',
    ]);

    StudentEducation::create([
        'user_id' => $this->student1->id,
        'level' => 'Graduation',
        'board_university' => 'Calcutta University',
        'institute' => 'Presidency College',
        'stream_subject' => 'Political Science',
        'passing_year' => 2024,
        'percentage' => 74.5,
        'division' => 'First Class',
        'completion_status' => 'Completed',
        'is_highest' => true,
    ]);

    StudentOtherDetail::create([
        'user_id' => $this->student1->id,
        'optional_subject' => 'Public Administration',
        'attempts_already_given' => 1,
        'attempts_left' => 5,
        'year_of_appearing' => 2027,
    ]);

    Enrollment::create([
        'enrollment_no' => 'ENR-2026-0001',
        'user_id' => $this->student1->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'roll_no' => 'ROLL-01',
        'status' => 'active',
        'fee_status' => 'paid',
        'enrolled_at' => now(),
    ]);

    StudentDocument::create([
        'user_id' => $this->student1->id,
        'type' => 'Graduation Marksheet',
        'file_name' => 'graduation_degree_scan.pdf',
        'is_active' => true,
        'verified' => true,
        'verified_at' => now(),
        'uploaded_at' => now(),
    ]);

    // Candidate 2
    $this->student2 = User::factory()->create([
        'name' => 'Aniket Roy',
        'email' => 'aniket.roy@example.com',
        'student_id' => 'STU-2026-0002',
        'urn' => 'URN-2026-0002',
        'phone' => '+91 9832233445',
        'gender' => 'male',
        'status' => 'active',
    ]);
    $this->student2->assignRole('Student');

    StudentProfile::create([
        'user_id' => $this->student2->id,
        'father_name' => 'Subhash Roy',
        'mother_name' => 'Bani Roy',
        'category' => 'SC',
        'cert_no' => 'SC/2022/9988',
        'issued_date' => '2022-04-10',
        'issued_by' => 'SDO Barasat',
        'family_income' => 220000,
        'is_working_professional' => true,
        'occupation' => 'Software Analyst',
    ]);

    StudentEmployment::create([
        'user_id' => $this->student2->id,
        'employer_name' => 'TCS Kolkata',
        'designation' => 'Systems Engineer',
        'nature_of_employment' => 'Full-time',
        'location' => 'Salt Lake Sector V',
        'is_current' => true,
    ]);

    UpscAttempt::create([
        'user_id' => $this->student2->id,
        'exam_year' => 2025,
        'roll_number' => '0821990',
        'prelims_cleared' => true,
        'mains_cleared' => false,
        'interview_cleared' => false,
    ]);

    Enrollment::create([
        'enrollment_no' => 'ENR-2026-0002',
        'user_id' => $this->student2->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'roll_no' => 'ROLL-02',
        'status' => 'active',
        'fee_status' => 'paid',
        'enrolled_at' => now(),
    ]);
});

test('admin can access detailed information sheets generator page', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.students.information-sheet')
        ->assertOk()
        ->assertSee('Student Detailed Information Sheets')
        ->assertSee('Debolina Banerjee');

    $response = $this->get("/{$this->admin->currentTeam->slug}/students/information-sheets");
    $response->assertSuccessful();
    $response->assertSee('Student Detailed Information Sheets');
});

test('can generate information sheet for pre-selected student via query parameter', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.students.information-sheet', ['studentId' => $this->student2->id])
        ->assertOk()
        ->assertSee('Aniket Roy')
        ->assertSee('STU-2026-0002')
        ->assertSee('Systems Engineer')
        ->assertSee('TCS Kolkata');
});

test('information sheet displays all comprehensive candidate tables', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::admin.students.information-sheet', ['studentId' => $this->student1->id])
        ->assertOk();

    // Verify student details
    $component->assertSee('Debolina Banerjee')
        ->assertSee('STU-2026-0001')
        ->assertSee('WB10-445566')
        ->assertSee('Shyamal Banerjee')
        ->assertSee('Gita Banerjee')
        ->assertSee('Presidency College')
        ->assertSee('Calcutta University')
        ->assertSee('Political Science')
        ->assertSee('Public Administration')
        ->assertSee('ENR-2026-0001')
        ->assertSee('ROLL-01');
});

test('can select multiple candidates and generate sheets simultaneously', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::admin.students.information-sheet')
        ->set('selectedStudentIds', [(string) $this->student1->id, (string) $this->student2->id])
        ->assertOk()
        ->assertSee('Debolina Banerjee')
        ->assertSee('Aniket Roy');

    expect($component->get('sheets'))->toHaveCount(2);
});

test('student information sheet service compiles full structured payload', function () {
    $data1 = StudentInformationSheetService::getStudentData($this->student1, true);

    expect($data1['name'])->toBe('Debolina Banerjee');
    expect($data1['student_id'])->toBe('STU-2026-0001');
    expect($data1['profile']['father_name'])->toBe('Shyamal Banerjee');
    expect($data1['profile']['category'])->toBe('UR');
    expect($data1['educations'])->toHaveCount(1);
    expect($data1['educations'][0]['institute'])->toBe('Presidency College');
    expect($data1['enrollments'])->toHaveCount(1);
    expect($data1['enrollments'][0]['enrollment_no'])->toBe('ENR-2026-0001');
    expect($data1['documents'])->toHaveCount(1);
    expect($data1['documents'][0]['type'])->toBe('Graduation Marksheet');

    $data2 = StudentInformationSheetService::getStudentData($this->student2, false);
    expect($data2['name'])->toBe('Aniket Roy');
    expect($data2['employments'])->toHaveCount(1);
    expect($data2['employments'][0]['employer_name'])->toBe('TCS Kolkata');
    expect($data2['upsc_attempts'])->toHaveCount(1);
    expect($data2['upsc_attempts'][0]['exam_year'])->toBe(2025);
});

test('admin can export detailed information sheets to pdf stream', function () {
    $this->actingAs($this->admin);

    $response = Livewire::test('pages::admin.students.information-sheet')
        ->set('selectedStudentIds', [(string) $this->student1->id, (string) $this->student2->id])
        ->call('exportPdf');

    $response->assertFileDownloaded();

    // Direct controller endpoint
    $directResponse = $this->get(route('admin.students.information-sheet.download', [
        'ids' => "{$this->student1->id},{$this->student2->id}",
        'include_documents' => '1',
    ]));

    $directResponse->assertSuccessful();
    expect($directResponse->headers->get('content-type'))->toBe('application/pdf');
});

test('student photo resolves from student document when user avatar is empty', function () {
    $student = User::factory()->create([
        'name' => 'Rahul Sen',
        'avatar' => null,
    ]);

    StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'Photo',
        'file_url' => 'https://example.com/photos/rahul.jpg',
        'is_active' => true,
    ]);

    $data = StudentInformationSheetService::getStudentData($student);
    expect($data['photo_url'])->toBe('https://example.com/photos/rahul.jpg');
    expect($data['initials'])->toBe('RS');
});

test('unauthenticated users cannot view or download information sheets', function () {
    $response = $this->get('/default/students/information-sheets');
    $response->assertRedirect(route('login'));

    $downloadResponse = $this->get(route('admin.students.information-sheet.download', [
        'ids' => (string) $this->student1->id,
    ]));
    $downloadResponse->assertRedirect(route('login'));
});

test('unauthorized users without students view permission cannot access information sheets', function () {
    $regularUser = User::factory()->create();

    $this->actingAs($regularUser)
        ->get('/default/students/information-sheets')
        ->assertForbidden();

    $this->actingAs($regularUser)
        ->get(route('admin.students.information-sheet.download', ['ids' => (string) $this->student1->id]))
        ->assertForbidden();
});
