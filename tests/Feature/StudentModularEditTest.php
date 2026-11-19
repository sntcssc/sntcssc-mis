<?php

use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\UpscAttempt;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');
});

test('admin can open student edit modal and load modular state arrays', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Ananya Chatterjee',
        'first_name' => 'Ananya',
        'last_name' => 'Chatterjee',
        'email' => 'ananya.c@example.com',
        'phone' => '9830011223',
        'gender' => 'female',
        'status' => 'active',
    ]);

    StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Bimal Chatterjee',
        'category' => 'UR',
        'family_income' => 450000,
    ]);

    Livewire::test('pages::admin.students')
        ->assertOk()
        ->call('edit', $student->id, 'profile')
        ->assertSet('editingStudentId', $student->id)
        ->assertSet('activeFormTab', 'profile')
        ->assertSet('userForm.name', 'Ananya Chatterjee')
        ->assertSet('userForm.email', 'ananya.c@example.com')
        ->assertSet('profileForm.father_name', 'Bimal Chatterjee')
        ->assertSet('profileForm.category', 'UR')
        ->assertDispatched('modal-open', name: 'student-form-modal');
});

test('admin can update only dirty fields of student basic account info', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Rahul Sen',
        'first_name' => 'Rahul',
        'last_name' => 'Sen',
        'email' => 'rahul.sen@example.com',
        'phone' => '9830099887',
        'gender' => 'male',
        'dob' => '2000-05-15',
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'basic')
        ->set('userForm.phone', '9830055555') // Modify only phone
        ->call('updateBasicInfo');

    $student->refresh();
    expect($student->phone)->toBe('9830055555')
        ->and($student->email)->toBe('rahul.sen@example.com')
        ->and($student->name)->toBe('Rahul Sen');
});

test('admin can update student personal and family profile details modularly', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Sourav Ganguly',
        'email' => 'sourav.g@example.com',
    ]);

    StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Chandidas Ganguly',
        'category' => 'UR',
        'family_income' => 500000,
    ]);

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'profile')
        ->set('profileForm.mother_name', 'Nirupa Ganguly')
        ->set('profileForm.category', 'OBCA')
        ->call('updateProfileDetails');

    $profile = StudentProfile::where('user_id', $student->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->father_name)->toBe('Chandidas Ganguly')
        ->and($profile->mother_name)->toBe('Nirupa Ganguly')
        ->and($profile->category)->toBe('OBCA');
});

test('admin can update permanent and correspondence addresses independently', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Debolina Ghosh',
        'email' => 'debolina.g@example.com',
    ]);

    // Add Permanent Address
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'address')
        ->assertSet('addressForm.selected_type', 'Permanent')
        ->set('addressForm.address_line1', '12 Salt Lake Sector 1')
        ->set('addressForm.district', 'North 24 Parganas')
        ->set('addressForm.state', 'West Bengal')
        ->set('addressForm.pin_code', '700064')
        ->call('updateAddressDetails');

    $perm = StudentAddress::where('user_id', $student->id)->where('address_type', 'Permanent')->first();
    expect($perm)->not->toBeNull()
        ->and($perm->district)->toBe('North 24 Parganas')
        ->and($perm->pin_code)->toBe('700064');

    // Switch to Correspondence and update with different location
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'address')
        ->call('switchAddressType', 'Correspondence')
        ->assertSet('addressForm.selected_type', 'Correspondence')
        ->set('addressForm.address_line1', 'Flat 4B, South City')
        ->set('addressForm.district', 'Kolkata')
        ->set('addressForm.state', 'West Bengal')
        ->set('addressForm.pin_code', '700068')
        ->call('updateAddressDetails');

    $corr = StudentAddress::where('user_id', $student->id)->where('address_type', 'Correspondence')->first();
    expect($corr)->not->toBeNull()
        ->and($corr->pin_code)->toBe('700068')
        ->and($perm->refresh()->pin_code)->toBe('700064');
});

test('admin can perform qualification CRUD operations without affecting other data', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Pritam Saha',
        'email' => 'pritam.saha@example.com',
    ]);

    // Add a qualification
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'education')
        ->set('educationForm.level', 'Graduation')
        ->set('educationForm.board_university', 'University of Calcutta')
        ->set('educationForm.stream_subject', 'Economics Honours')
        ->set('educationForm.passing_year', 2023)
        ->set('educationForm.percentage', 76.40)
        ->set('educationForm.is_highest', true)
        ->call('saveEducation');

    $edu = StudentEducation::where('user_id', $student->id)->first();
    expect($edu)->not->toBeNull()
        ->and($edu->level)->toBe('Graduation')
        ->and((float) $edu->percentage)->toBe(76.40)
        ->and($edu->is_highest)->toBeTrue();

    // Edit the existing qualification
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'education')
        ->call('editEducation', $edu->id)
        ->assertSet('educationForm.editing_id', $edu->id)
        ->set('educationForm.percentage', 78.50)
        ->call('saveEducation');

    expect((float) $edu->refresh()->percentage)->toBe(78.50);

    // Delete the qualification
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'education')
        ->call('deleteEducation', $edu->id);

    expect(StudentEducation::find($edu->id))->toBeNull();
});

test('admin can perform employment CRUD operations without affecting other data', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Megha Roy',
        'email' => 'megha.roy@example.com',
    ]);

    // Add employment
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'employment')
        ->set('employmentForm.employer_name', 'Cognizant Technology Solutions')
        ->set('employmentForm.designation', 'Programmer Analyst')
        ->set('employmentForm.nature_of_employment', 'Full-time')
        ->set('employmentForm.location', 'Kolkata')
        ->set('employmentForm.is_current', true)
        ->call('saveEmployment');

    $emp = StudentEmployment::where('user_id', $student->id)->first();
    expect($emp)->not->toBeNull()
        ->and($emp->employer_name)->toBe('Cognizant Technology Solutions')
        ->and($emp->is_current)->toBeTrue();

    // Edit employment
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'employment')
        ->call('editEmployment', $emp->id)
        ->assertSet('employmentForm.editing_id', $emp->id)
        ->set('employmentForm.designation', 'Senior Software Engineer')
        ->call('saveEmployment');

    expect($emp->refresh()->designation)->toBe('Senior Software Engineer');

    // Delete employment
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'employment')
        ->call('deleteEmployment', $emp->id);

    expect(StudentEmployment::find($emp->id))->toBeNull();
});

test('admin can update civil services UPSC details modularly', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Tanmoy Paul',
        'email' => 'tanmoy.paul@example.com',
    ]);

    StudentOtherDetail::create([
        'user_id' => $student->id,
        'attempts_already_given' => 1,
        'attempts_left' => 5,
        'optional_subject' => 'History',
        'year_of_appearing' => 2025,
    ]);

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'other')
        ->set('otherForm.attempts_already_given', 2)
        ->set('otherForm.optional_subject', 'Geography')
        ->call('updateOtherDetails');

    $other = StudentOtherDetail::where('user_id', $student->id)->first();
    expect($other)->not->toBeNull()
        ->and($other->attempts_already_given)->toBe(2)
        ->and($other->optional_subject)->toBe('Geography')
        ->and($other->year_of_appearing)->toBe(2025);
});

test('validation prevents invalid email update in basic account info', function () {
    $this->actingAs($this->admin);

    $existing = User::factory()->create(['email' => 'taken@example.com']);
    $student = User::factory()->create(['email' => 'original@example.com']);

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'basic')
        ->set('userForm.email', 'taken@example.com')
        ->call('updateBasicInfo')
        ->assertHasErrors(['userForm.email']);

    expect($student->refresh()->email)->toBe('original@example.com');
});

test('realtime full name autofill works from first name and last name in userForm and form', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'first_name' => 'Subhas',
        'last_name' => 'Bose',
        'name' => 'Subhas Bose',
    ]);

    // Test userForm in modal
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'basic')
        ->set('userForm.first_name', 'Netaji Subhas')
        ->set('userForm.last_name', 'Chandra Bose')
        ->assertSet('userForm.name', 'Netaji Subhas Chandra Bose');

    // Test form in create student
    Livewire::test('pages::admin.students')
        ->set('form.first_name', 'Rabindranath')
        ->set('form.last_name', 'Tagore')
        ->assertSet('form.name', 'Rabindranath Tagore');
});

test('admin can save extended pwbd details and creamy layer for obc category in profile', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Amitava Roy',
        'email' => 'amitava.roy@example.com',
    ]);

    StudentProfile::create([
        'user_id' => $student->id,
        'category' => 'UR',
    ]);

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'profile')
        ->set('profileForm.category', 'OBC')
        ->set('profileForm.creamy_layer', 'NCL')
        ->set('profileForm.subcaste', 'Ghosh')
        ->set('profileForm.distance', 18.75)
        ->set('profileForm.school_language', 'Bengali')
        ->set('profileForm.highest_qualification', 'Graduation')
        ->set('profileForm.is_pwbd', true)
        ->set('profileForm.pwbd_type', 'VI')
        ->set('profileForm.pwd_percentage', 45)
        ->set('profileForm.pwbd_cert_no', 'CERT-PWD-2024-9988')
        ->set('profileForm.pwbd_issued_date', '2024-01-15')
        ->set('profileForm.pwbd_issued_by', 'District Hospital Medical Board')
        ->call('updateProfileDetails');

    $profile = StudentProfile::where('user_id', $student->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->category)->toBe('OBC')
        ->and($profile->creamy_layer)->toBe('NCL')
        ->and($profile->subcaste)->toBe('Ghosh')
        ->and((float) $profile->distance)->toBe(18.75)
        ->and($profile->school_language)->toBe('Bengali')
        ->and($profile->highest_qualification)->toBe('Graduation')
        ->and((bool) $profile->is_pwd)->toBeTrue()
        ->and($profile->pwd_type)->toBe('VI')
        ->and((float) $profile->pwd_percentage)->toBe(45.0)
        ->and($profile->pwbd_cert_no)->toBe('CERT-PWD-2024-9988')
        ->and($profile->pwbd_issued_date?->format('Y-m-d'))->toBe('2024-01-15')
        ->and($profile->pwbd_issued_by)->toBe('District Hospital Medical Board');
});

test('education auto-calculates percentage in realtime and recalculates upon save to prevent tampering', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Kavita Sharma',
        'email' => 'kavita.s@example.com',
    ]);

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'education')
        ->set('educationForm.level', '10th')
        ->set('educationForm.board_university', 'WBBSE')
        ->set('educationForm.stream_subject', 'General')
        ->set('educationForm.passing_year', 2018)
        ->set('educationForm.total_marks', 800)
        ->set('educationForm.marks_obtained', 680)
        // Real-time calculation check: 680 / 800 * 100 = 85.00
        ->assertSet('educationForm.percentage', 85.00)
        ->set('educationForm.division', '1st Division')
        // Tamper with percentage before saving:
        ->set('educationForm.percentage', 99.99)
        ->call('saveEducation');

    $edu = StudentEducation::where('user_id', $student->id)->where('level', '10th')->first();
    expect($edu)->not->toBeNull()
        ->and($edu->total_marks)->toBe(800)
        ->and($edu->marks_obtained)->toBe(680)
        ->and((float) $edu->percentage)->toBe(85.00) // Recalculated correctly on save
        ->and($edu->division)->toBe('1st Division');
});

test('admin can manage upsc attempts crud operations', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Vikramaditya Roy',
        'email' => 'vikram.roy@example.com',
    ]);

    // Create attempt
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'other')
        ->set('attemptForm.exam_year', 2023)
        ->set('attemptForm.roll_number', '0812345')
        ->set('attemptForm.prelims_cleared', true)
        ->set('attemptForm.mains_cleared', true)
        ->set('attemptForm.interview_cleared', false)
        ->call('saveAttempt');

    $attempt = UpscAttempt::where('user_id', $student->id)->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->exam_year)->toBe(2023)
        ->and($attempt->roll_number)->toBe('0812345')
        ->and((bool) $attempt->prelims_cleared)->toBeTrue()
        ->and((bool) $attempt->mains_cleared)->toBeTrue()
        ->and((bool) $attempt->interview_cleared)->toBeFalse();

    // Edit attempt
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'other')
        ->call('editAttempt', $attempt->id)
        ->assertSet('attemptForm.editing_id', $attempt->id)
        ->set('attemptForm.interview_cleared', true)
        ->call('saveAttempt');

    expect((bool) $attempt->refresh()->interview_cleared)->toBeTrue();

    // Delete attempt
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'other')
        ->call('deleteAttempt', $attempt->id);

    expect(UpscAttempt::find($attempt->id))->toBeNull();
});

test('admin can attach external otr document url or upload document with custom name and renamed file', function () {
    Storage::fake('public');
    $this->actingAs($this->admin);

    $student = User::factory()->create([
        'name' => 'Barnali Mukherjee',
        'email' => 'barnali.m@example.com',
    ]);

    // Attach external OTR Document URL
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'documents')
        ->set('documentForm.document_type', 'OTR Profile Document')
        ->set('documentForm.file_url', 'https://otr.sntcssc.gov.in/documents/12345/admit_card.pdf')
        ->call('saveDocument');

    $doc = StudentDocument::where('user_id', $student->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->document_type)->toBe('OTR Profile Document')
        ->and($doc->file_url)->toBe('https://otr.sntcssc.gov.in/documents/12345/admit_card.pdf')
        ->and($doc->verification_status)->toBe('verified');

    // Upload custom document using RenamesUploads trait
    $fakeFile = UploadedFile::fake()->create('custom_doc.pdf', 300, 'application/pdf');

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'documents')
        ->set('documentForm.document_type', 'Custom')
        ->set('documentForm.custom_document_type', 'Migration Certificate')
        ->set('documentForm.file', $fakeFile)
        ->call('saveDocument');

    $customDoc = StudentDocument::where('user_id', $student->id)->where('type', 'Migration Certificate')->first();
    expect($customDoc)->not->toBeNull()
        ->and($customDoc->type)->toBe('Migration Certificate')
        ->and($customDoc->file_name)->toMatch('/^migration_certificate_user_'.$student->id.'_\d{8}_\d{6}\.pdf$/')
        ->and(Storage::disk('public')->exists($customDoc->file_path))->toBeTrue();

    // Delete document
    Livewire::test('pages::admin.students')
        ->call('edit', $student->id, 'documents')
        ->call('deleteDocument', $doc->id);

    expect(StudentDocument::find($doc->id))->toBeNull();
});
