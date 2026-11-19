<?php

use App\Helpers\CategoryData;
use App\Helpers\LocationData;
use App\Helpers\UpscData;
use App\Models\AuditLog;
use App\Models\ProfileUpdateRequest;
use App\Models\Setting;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\RbacService;
use App\Services\UserStudentSyncService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('getProfileCompleteness correctly evaluates incomplete vs complete student profiles', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Sourav Mukherjee',
        'first_name' => 'Sourav',
        'last_name' => 'Mukherjee',
        'email' => 'sourav@example.com',
        'phone' => '9876543210',
        'dob' => '2000-05-15',
        'gender' => 'male',
        'id_type' => 'Aadhaar',
        'id_number' => '123456789012',
    ]);
    $student->assignRole('Student');

    // Initially, profile and other related tables are not filled
    $initialCheck = $student->getProfileCompleteness();
    expect($initialCheck['is_complete'])->toBeFalse();
    expect($initialCheck['completed_count'])->toBeLessThan(7);
    expect($initialCheck['sections']['account']['is_complete'])->toBeTrue();
    expect($initialCheck['sections']['profile']['is_complete'])->toBeFalse();

    // Fill Section 2: Profile & Family
    StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Debabrata Mukherjee',
        'mother_name' => 'Sulata Mukherjee',
        'category' => 'UR',
        'distance' => 10.5,
        'highest_edu' => 'Graduation',
        'school_language' => 'English',
        'dob' => '2000-05-15',
        'gender' => 'male',
    ]);

    // Fill Section 3: Addresses
    StudentAddress::create([
        'user_id' => $student->id,
        'address_type' => 'Permanent',
        'state' => 'West Bengal',
        'district' => 'Kolkata',
        'address_line1' => 'Salt Lake Sector 5',
        'pin_code' => '700091',
    ]);

    // Fill Section 4: Qualifications
    StudentEducation::create([
        'user_id' => $student->id,
        'level' => 'Graduation',
        'board_university' => 'Calcutta University',
        'institute' => 'Presidency College',
        'passing_year' => 2022,
        'percentage' => 74.5,
    ]);

    // Fill Section 5: Employment
    StudentEmployment::create([
        'user_id' => $student->id,
        'employer_name' => 'Self-employed / Civil Prep',
        'designation' => 'Aspirant',
        'nature_of_employment' => 'Full-time Preparation',
        'from_date' => '2022-06-01',
        'is_current' => true,
    ]);

    // Fill Section 6: UPSC Details
    StudentOtherDetail::create([
        'user_id' => $student->id,
        'optional_subject' => 'History',
        'attempts_already_given' => 1,
        'year_of_appearing' => 2024,
    ]);

    // Fill Section 7: Uploaded Documents
    StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'Photo',
        'file_name' => 'photo.jpg',
        'file_path' => 'documents/photo.jpg',
        'is_active' => true,
    ]);
    StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'Signature',
        'file_name' => 'signature.jpg',
        'file_path' => 'documents/signature.jpg',
        'is_active' => true,
    ]);

    // Refresh model relations
    $student->refresh();
    $student->unsetRelation('studentProfile')
        ->unsetRelation('studentAddresses')
        ->unsetRelation('studentEducations')
        ->unsetRelation('studentEmployments')
        ->unsetRelation('studentOtherDetails')
        ->unsetRelation('studentDocuments');

    $completeCheck = $student->getProfileCompleteness();
    expect($completeCheck['is_complete'])->toBeTrue();
    expect($completeCheck['percentage'])->toBe(100);
    expect($completeCheck['completed_count'])->toBe(7);
});

test('incomplete student is redirected to dashboard when allow_incomplete_student_dashboard setting is false', function () {
    Setting::set('system.allow_incomplete_student_dashboard', false);

    /** @var User $student */
    $student = User::factory()->create([
        'phone' => null, // Incomplete account section
    ]);
    $student->assignRole('Student');
    $team = $student->currentTeam;

    // Student visiting tickets page should be redirected to dashboard
    $response = $this->actingAs($student)
        ->get(route('tickets.index', ['current_team' => $team->slug]));

    $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
    $response->assertSessionHas('profile_incomplete_warning');

    // Visiting dashboard itself must NOT trigger a redirect loop
    $dashboardResponse = $this->actingAs($student)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $dashboardResponse->assertOk();
});

test('incomplete student can access portal routes when allow_incomplete_student_dashboard is true', function () {
    Setting::set('system.allow_incomplete_student_dashboard', true);

    /** @var User $student */
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = $student->currentTeam;

    $response = $this->actingAs($student)
        ->get(route('tickets.index', ['current_team' => $team->slug]));

    $response->assertOk();
});

test('administrators are never blocked even when allow_incomplete is false and profile is incomplete', function () {
    Setting::set('system.allow_incomplete_student_dashboard', false);

    /** @var User $admin */
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $team = $admin->currentTeam;

    $response = $this->actingAs($admin)
        ->get(route('tickets.index', ['current_team' => $team->slug]));

    $response->assertOk();
});

test('student dashboard loads helper classes and allows submitting correction requests', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'first_name' => 'Ananya',
        'last_name' => 'Roy',
        'phone' => '9830012345',
        'dob' => '2001-08-20',
        'gender' => 'female',
    ]);
    $student->assignRole('Student');

    // Verify helper classes provide expected data
    expect(CategoryData::getCategoryOptions())->toBeArray();
    expect(LocationData::getStateNames())->toContain('West Bengal');
    expect(UpscData::getOptionalSubjects())->toBeArray();

    // Test livewire student dashboard component
    Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        ->set('requestModalSection', 'profile')
        ->set('profileRequest.category', 'OBC')
        ->set('profileRequest.creamy_layer', 'NCL')
        ->set('profileRequest.subcaste', 'Karmakar')
        ->set('profileRequest.father_name', 'Bimal Roy')
        ->set('profileRequest.mother_name', 'Mina Roy')
        ->set('profileRequest.father_occupation', 'Business')
        ->set('profileRequest.mother_occupation', 'Homemaker')
        ->set('requestReason', 'Update family details and correct OBC category')
        ->call('submitCorrectionRequest')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $request = ProfileUpdateRequest::where('user_id', $student->id)
        ->where('section', 'profile')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull();
    expect($request->status)->toBe('pending');
    expect($request->payload['category'])->toBe('OBC');
    expect($request->payload['subcaste'])->toBe('Karmakar');
    expect($request->payload['father_occupation'])->toBe('Business');
});

test('student dashboard allows submitting address and upsc correction requests with helper data', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'first_name' => 'Soumen',
        'last_name' => 'Dey',
        'phone' => '9831122334',
        'dob' => '2000-01-10',
        'gender' => 'male',
    ]);
    $student->assignRole('Student');

    $optionalSubjects = UpscData::getOptionalSubjects();
    expect($optionalSubjects)->toContain('Public Administration');

    // Submit Address Correction Request
    Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        ->set('requestModalSection', 'address')
        ->set('addressRequest.permanent_state', 'West Bengal')
        ->set('addressRequest.permanent_district', 'North 24 Parganas')
        ->set('addressRequest.permanent_subdistrict', 'Bidhannagar')
        ->set('addressRequest.permanent_post_office', 'Salt Lake Sec 1 P.O.')
        ->set('addressRequest.permanent_police_station', 'Bidhannagar North P.S.')
        ->set('addressRequest.permanent_address_line1', 'Block BD, Salt Lake')
        ->set('addressRequest.permanent_address_line2', 'Flat 4B')
        ->set('addressRequest.permanent_pin_code', '700064')
        ->set('requestReason', 'Permanent address relocated')
        ->call('submitCorrectionRequest')
        ->assertHasNoErrors();

    $addrReq = ProfileUpdateRequest::where('user_id', $student->id)
        ->where('section', 'address')
        ->latest('id')
        ->first();

    expect($addrReq)->not->toBeNull();
    expect($addrReq->payload['permanent_subdistrict'])->toBe('Bidhannagar');
    expect($addrReq->payload['permanent_police_station'])->toBe('Bidhannagar North P.S.');
    expect($addrReq->payload['permanent_address_line2'])->toBe('Flat 4B');

    // Submit UPSC Details Correction Request
    Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        ->set('requestModalSection', 'upsc')
        ->set('upscRequest.optional_subject', 'Public Administration')
        ->set('upscRequest.attempts_already_given', 2)
        ->set('upscRequest.year_of_appearing', 2025)
        ->set('requestReason', 'Finalized optional subject selection')
        ->call('submitCorrectionRequest')
        ->assertHasNoErrors();

    $upscReq = ProfileUpdateRequest::where('user_id', $student->id)
        ->where('section', 'upsc')
        ->latest('id')
        ->first();

    expect($upscReq)->not->toBeNull();
    expect($upscReq->payload['optional_subject'])->toBe('Public Administration');
    expect($upscReq->payload['year_of_appearing'])->toBe(2025);
});

test('UserStudentSyncService synchronizes all missing fields across profile and address', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Koyel Ghosh',
        'first_name' => 'Koyel',
        'last_name' => 'Ghosh',
        'phone' => '9800112233',
        'dob' => '2001-03-25',
        'gender' => 'female',
    ]);

    UserStudentSyncService::syncStudent(
        $student,
        ['email' => 'koyel@example.com'],
        [
            'category' => 'OBC',
            'subcaste' => 'Goala',
            'creamy_layer' => 'NCL',
            'occupation' => 'Aspirant',
            'father_occupation' => 'Farmer',
            'mother_occupation' => 'Homemaker',
            'upsc_community' => 'OBC',
            'family_income' => 180000,
            'highest_edu' => 'Graduation',
            'school_language' => 'Bengali',
        ],
        [
            'state' => 'West Bengal',
            'district' => 'Nadia',
            'subdistrict' => 'Ranaghat',
            'post_office' => 'Ranaghat P.O.',
            'police_station' => 'Ranaghat P.S.',
            'address_line1' => 'Village Ghoshpara',
            'address_line2' => 'Near Ghosh Mandir',
            'pin_code' => '741201',
        ]
    );

    $profile = StudentProfile::where('user_id', $student->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->subcaste)->toBe('Goala');
    expect($profile->occupation)->toBe('Aspirant');
    expect($profile->father_occupation)->toBe('Farmer');
    expect($profile->mother_occupation)->toBe('Homemaker');
    expect($profile->upsc_community)->toBe('OBC');

    $address = StudentAddress::where('user_id', $student->id)->where('address_type', 'Permanent')->first();
    expect($address)->not->toBeNull();
    expect($address->subdistrict)->toBe('Ranaghat');
    expect($address->post_office)->toBe('Ranaghat P.O.');
    expect($address->police_station)->toBe('Ranaghat P.S.');
    expect($address->address_line2)->toBe('Near Ghosh Mandir');
});

test('system setting for student dashboard completeness can be configured', function () {
    Setting::set('system.allow_incomplete_student_dashboard', true);
    expect(Setting::get('system.allow_incomplete_student_dashboard'))->toBeTrue();

    Setting::set('system.allow_incomplete_student_dashboard', false);
    expect(Setting::get('system.allow_incomplete_student_dashboard'))->toBeFalse();
});

test('student with no employment records has 100% completeness and passes profile completeness check', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Ananya Sen',
        'first_name' => 'Ananya',
        'last_name' => 'Sen',
        'email' => 'ananya@example.com',
        'phone' => '9830112233',
        'dob' => '2001-08-10',
        'gender' => 'female',
        'id_type' => 'Aadhaar',
        'id_number' => '998877665544',
    ]);
    $student->assignRole('Student');

    // Fill Section 2: Profile & Family
    StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Subhash Sen',
        'mother_name' => 'Mita Sen',
        'category' => 'UR',
        'distance' => 5.0,
        'highest_edu' => 'Graduation',
        'school_language' => 'English',
        'dob' => '2001-08-10',
        'gender' => 'female',
    ]);

    // Fill Section 3: Addresses
    StudentAddress::create([
        'user_id' => $student->id,
        'address_type' => 'Permanent',
        'state' => 'West Bengal',
        'district' => 'Kolkata',
        'address_line1' => 'Ballygunge Circular Road',
        'pin_code' => '700019',
    ]);

    // Fill Section 4: Qualifications
    StudentEducation::create([
        'user_id' => $student->id,
        'level' => 'Graduation',
        'board_university' => 'Jadavpur University',
        'institute' => 'Jadavpur University',
        'passing_year' => 2023,
        'percentage' => 81.2,
    ]);

    // Section 5: NO EMPLOYMENT RECORDS (student has no work experience)
    expect($student->studentEmployments()->count())->toBe(0);

    // Fill Section 6: UPSC Details
    StudentOtherDetail::create([
        'user_id' => $student->id,
        'optional_subject' => 'Sociology',
        'attempts_already_given' => 0,
        'year_of_appearing' => 2025,
    ]);

    // Fill Section 7: Uploaded Documents
    StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'Photo',
        'file_name' => 'photo.jpg',
        'file_path' => 'documents/photo.jpg',
        'is_active' => true,
    ]);
    StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'Signature',
        'file_name' => 'signature.jpg',
        'file_path' => 'documents/signature.jpg',
        'is_active' => true,
    ]);

    $student->refresh();
    $completeness = $student->getProfileCompleteness();

    expect($completeness['is_complete'])->toBeTrue();
    expect($completeness['percentage'])->toBe(100);
    expect($completeness['completed_count'])->toBe(7);
    expect($completeness['sections']['employment']['is_complete'])->toBeTrue();
    expect($completeness['sections']['employment']['is_optional'])->toBeTrue();
    expect($completeness['sections']['employment']['missing'])->toBeEmpty();
});

test('student can initiate withdraw confirmation modal and execute request withdrawal', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'first_name' => 'Rahul',
        'last_name' => 'Das',
    ]);
    $student->assignRole('Student');

    $request = ProfileUpdateRequest::create([
        'user_id' => $student->id,
        'section' => 'account',
        'payload' => ['first_name' => 'Rahul New'],
        'current_data' => ['first_name' => 'Rahul'],
        'changes' => ['first_name' => ['from' => 'Rahul', 'to' => 'Rahul New']],
        'reason' => 'Spelling typo in certificate',
        'status' => 'pending',
    ]);

    $component = Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        // 1. Open confirmation modal
        ->call('openWithdrawConfirmation', $request->id)
        ->assertSet('withdrawingRequestId', $request->id)
        ->assertDispatched('modal-open', name: 'confirm-withdraw-modal');

    // 2. Can cancel confirmation modal
    $component->call('cancelWithdraw')
        ->assertSet('withdrawingRequestId', null)
        ->assertSet('withdrawingRequest', null)
        ->assertDispatched('modal-close', name: 'confirm-withdraw-modal');

    expect(ProfileUpdateRequest::find($request->id))->not->toBeNull();

    // 3. Open again and confirm withdrawal
    $component->call('openWithdrawConfirmation', $request->id)
        ->call('executeWithdrawRequest')
        ->assertSet('withdrawingRequestId', null)
        ->assertDispatched('modal-close', name: 'confirm-withdraw-modal');

    // The request should be soft deleted or removed
    expect(ProfileUpdateRequest::find($request->id))->toBeNull();
    expect(ProfileUpdateRequest::withTrashed()->find($request->id)->trashed())->toBeTrue();

    // Audit log should be recorded
    $log = AuditLog::where('auditable_type', ProfileUpdateRequest::class)
        ->where('auditable_id', $request->id)
        ->where('event', 'profile_update_withdrawn')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($student->id);
});

test('admin can reject correction request within database transaction', function () {
    /** @var User $admin */
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Priya Roy',
        'student_id' => 'STU-2026-0099',
    ]);
    $student->assignRole('Student');

    $request = ProfileUpdateRequest::create([
        'user_id' => $student->id,
        'section' => 'profile',
        'payload' => ['father_name' => 'New Father Name'],
        'current_data' => ['father_name' => 'Old Father Name'],
        'changes' => ['father_name' => ['from' => 'Old Father Name', 'to' => 'New Father Name']],
        'reason' => 'Name change',
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.profile-requests')
        ->call('openReview', $request->id)
        ->set('adminNotes', 'Document proof is illegible, please upload a clearer scan.')
        ->call('rejectRequest')
        ->assertDispatched('modal-close', name: 'review-modal');

    $request->refresh();
    expect($request->status)->toBe('rejected');
    expect($request->admin_notes)->toBe('Document proof is illegible, please upload a clearer scan.');
    expect($request->reviewed_by)->toBe($admin->id);

    $log = AuditLog::where('auditable_type', ProfileUpdateRequest::class)
        ->where('auditable_id', $request->id)
        ->where('event', 'profile_update_rejected')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($admin->id);
});

test('student can toggle working professional status dynamically and profile completeness reflects changes', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Rohan Sen',
        'first_name' => 'Rohan',
        'last_name' => 'Sen',
        'email' => 'rohan@example.com',
        'phone' => '9876543211',
        'dob' => '2001-08-20',
        'gender' => 'male',
        'id_type' => 'Aadhaar',
        'id_number' => '987654321098',
    ]);
    $student->assignRole('Student');

    // Profile (Section 2)
    StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Anil Sen',
        'mother_name' => 'Kavita Sen',
        'category' => 'UR',
        'distance' => 15.0,
        'highest_edu' => 'Graduation',
        'school_language' => 'English',
        'is_working_professional' => false,
    ]);

    // Addresses (Section 3)
    StudentAddress::create([
        'user_id' => $student->id,
        'address_type' => 'Permanent',
        'state' => 'West Bengal',
        'district' => 'Kolkata',
        'address_line1' => 'College Street',
        'pin_code' => '700073',
    ]);

    // Qualifications (Section 4)
    StudentEducation::create([
        'user_id' => $student->id,
        'level' => 'Graduation',
        'institute' => 'Presidency University',
        'passing_year' => 2023,
    ]);

    // UPSC Details (Section 6)
    StudentOtherDetail::create([
        'user_id' => $student->id,
        'optional_subject' => 'Geography',
        'attempts_already_given' => 0,
        'year_of_appearing' => 2024,
    ]);

    // Documents (Section 7)
    StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'Photo',
        'file_name' => 'photo.jpg',
        'file_path' => 'documents/photo.jpg',
        'is_active' => true,
    ]);
    StudentDocument::create([
        'user_id' => $student->id,
        'type' => 'Signature',
        'file_name' => 'sig.jpg',
        'file_path' => 'documents/sig.jpg',
        'is_active' => true,
    ]);

    // 0 employments present. Since is_working_professional is false, profile is 100% complete
    $student->unsetRelation('studentProfile')
        ->unsetRelation('studentAddresses')
        ->unsetRelation('studentEducations')
        ->unsetRelation('studentEmployments')
        ->unsetRelation('studentOtherDetails')
        ->unsetRelation('studentDocuments');

    $completeness = $student->getProfileCompleteness();
    expect($completeness['is_complete'])->toBeTrue();
    expect($completeness['percentage'])->toBe(100);
    expect($completeness['sections']['employment']['is_complete'])->toBeTrue();
    expect($completeness['sections']['employment']['is_optional'])->toBeTrue();

    // Student toggles status to Working Professional
    Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        ->assertSet('isWorkingProfessional', false)
        ->call('updateWorkingProfessionalStatus', true)
        ->assertSet('isWorkingProfessional', true);

    $student->unsetRelation('studentProfile');
    expect($student->studentProfile->is_working_professional)->toBeTrue();

    // Now completeness requires employment, so it becomes incomplete
    $incompleteCheck = $student->getProfileCompleteness();
    expect($incompleteCheck['is_complete'])->toBeFalse();
    expect($incompleteCheck['percentage'])->toBeLessThan(100);
    expect($incompleteCheck['sections']['employment']['is_complete'])->toBeFalse();
    expect($incompleteCheck['sections']['employment']['is_optional'])->toBeFalse();
    expect($incompleteCheck['sections']['employment']['missing'])->toContain(__('At least one employment record is required for working professionals'));

    // Toggle back to Non-working
    Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        ->assertSet('isWorkingProfessional', true)
        ->call('updateWorkingProfessionalStatus', false)
        ->assertSet('isWorkingProfessional', false);

    $student->unsetRelation('studentProfile');
    expect($student->studentProfile->is_working_professional)->toBeFalse();

    $restoredCheck = $student->getProfileCompleteness();
    expect($restoredCheck['is_complete'])->toBeTrue();
    expect($restoredCheck['percentage'])->toBe(100);
});

test('working professional requires designation, nature, and from_date when requesting employment correction', function () {
    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Tanmoy Das',
        'student_id' => 'STU-2026-0777',
    ]);
    $student->assignRole('Student');

    StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Subhash Das',
        'is_working_professional' => true,
    ]);

    // Working professional: designation, nature, from_date are required
    Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        ->call('openRequestModal', 'employment')
        ->set('employmentRequest.employer_name', 'Tech Mahindra')
        ->set('employmentRequest.designation', '')
        ->set('employmentRequest.nature_of_employment', '')
        ->set('employmentRequest.from_date', '')
        ->set('requestReason', 'Adding current employer')
        ->call('submitCorrectionRequest')
        ->assertHasErrors([
            'employmentRequest.designation',
            'employmentRequest.nature_of_employment',
            'employmentRequest.from_date',
        ]);

    // Student switches to non-working: fields become optional
    $student->studentProfile->update(['is_working_professional' => false]);
    $student->unsetRelation('studentProfile');

    Livewire::actingAs($student)
        ->test('pages::student.dashboard')
        ->call('openRequestModal', 'employment')
        ->set('employmentRequest.employer_name', 'Tech Mahindra')
        ->set('employmentRequest.designation', '')
        ->set('employmentRequest.nature_of_employment', '')
        ->set('employmentRequest.from_date', '')
        ->set('requestReason', 'Adding internship / optional employer')
        ->call('submitCorrectionRequest')
        ->assertHasNoErrors([
            'employmentRequest.designation',
            'employmentRequest.nature_of_employment',
            'employmentRequest.from_date',
        ])
        ->assertDispatched('modal-close', name: 'student-request-modal');

    expect(ProfileUpdateRequest::where('user_id', $student->id)->where('section', 'employment')->exists())->toBeTrue();
});

test('admin can manage student working professional status and conditional employment validation', function () {
    /** @var User $admin */
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Moumita Ghosh',
        'student_id' => 'STU-2026-0888',
    ]);
    $student->assignRole('Student');

    StudentProfile::create([
        'user_id' => $student->id,
        'father_name' => 'Prabir Ghosh',
        'is_working_professional' => false,
    ]);

    // Admin opens student edit and toggles working professional status to true
    Livewire::actingAs($admin)
        ->test('pages::admin.students')
        ->call('edit', $student->id, 'employment')
        ->call('setStudentWorkingProfessionalStatus', true);

    $student->unsetRelation('studentProfile');
    expect($student->studentProfile->is_working_professional)->toBeTrue();

    // Trying to save employment for working professional without mandatory fields should fail
    Livewire::actingAs($admin)
        ->test('pages::admin.students')
        ->call('edit', $student->id, 'employment')
        ->set('employmentForm.employer_name', 'Wipro Ltd')
        ->set('employmentForm.designation', '')
        ->set('employmentForm.nature_of_employment', '')
        ->set('employmentForm.from_date', '')
        ->call('saveEmployment')
        ->assertHasErrors([
            'employmentForm.designation',
            'employmentForm.nature_of_employment',
            'employmentForm.from_date',
        ]);

    // Filling mandatory fields for working professional succeeds
    Livewire::actingAs($admin)
        ->test('pages::admin.students')
        ->call('edit', $student->id, 'employment')
        ->set('employmentForm.employer_name', 'Wipro Ltd')
        ->set('employmentForm.designation', 'Project Engineer')
        ->set('employmentForm.nature_of_employment', 'Full-time')
        ->set('employmentForm.from_date', '2023-01-15')
        ->call('saveEmployment')
        ->assertHasNoErrors();

    expect(StudentEmployment::where('user_id', $student->id)->where('employer_name', 'Wipro Ltd')->exists())->toBeTrue();

    // Admin toggles back to non-working
    Livewire::actingAs($admin)
        ->test('pages::admin.students')
        ->call('edit', $student->id, 'employment')
        ->call('setStudentWorkingProfessionalStatus', false);

    $student->unsetRelation('studentProfile');
    expect($student->studentProfile->is_working_professional)->toBeFalse();
});

test('admin can approve profile correction request with empty date and string values without SQL 1292 error', function () {
    /** @var User $admin */
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var User $student */
    $student = User::factory()->create([
        'name' => 'Subhankar Basak',
        'email' => 'subhankar.test@example.com',
        'phone' => null,
        'whatsapp_no' => null,
        'dob' => null,
        'gender' => null,
        'tenth_roll' => null,
        'id_type' => null,
        'id_number' => null,
    ]);
    $student->assignRole('Student');

    // Create a request with empty string values matching the user report
    $request = ProfileUpdateRequest::create([
        'user_id' => $student->id,
        'section' => 'basic',
        'payload' => [
            'dob' => '',
            'phone' => '9876543211',
            'gender' => '',
            'id_type' => '',
            'id_number' => '',
            'tenth_roll' => '',
            'designation' => '',
            'whatsapp_no' => '',
        ],
        'current_data' => [
            'dob' => null,
            'phone' => null,
            'gender' => null,
            'id_type' => null,
            'id_number' => null,
            'tenth_roll' => null,
            'designation' => null,
            'whatsapp_no' => null,
        ],
        'reason' => 'Student requested profile detail correction.',
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.profile-requests')
        ->call('openReview', $request->id)
        ->assertDispatched('modal-open', name: 'review-modal')
        ->call('approveRequest')
        ->assertDispatched('modal-close', name: 'review-modal')
        ->assertDispatched('toast');

    $request->refresh();
    expect($request->status)->toBe('approved');
    expect($request->reviewed_by)->toBe($admin->id);

    $student->refresh();
    expect($student->phone)->toBe('9876543211');
    expect($student->dob)->toBeNull();
    expect($student->whatsapp_no)->toBeNull();
    expect($student->gender)->toBeNull();

    $log = AuditLog::where('auditable_type', ProfileUpdateRequest::class)
        ->where('auditable_id', $request->id)
        ->where('event', 'profile_update_approved')
        ->first();

    expect($log)->not->toBeNull();
});

test('admin can approve profile section request with empty dates and decimals without SQL error', function () {
    /** @var User $admin */
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var User $student */
    $student = User::factory()->create();
    $student->assignRole('Student');

    $request = ProfileUpdateRequest::create([
        'user_id' => $student->id,
        'section' => 'profile',
        'payload' => [
            'father_name' => 'Dilip Basak',
            'mother_name' => 'Rita Basak',
            'category' => 'OBC',
            'subcaste' => 'Tanti',
            'issued_date' => '',
            'pwbd_issued_date' => '',
            'distance' => '',
            'family_income' => '',
        ],
        'current_data' => [],
        'reason' => 'Update family and category details',
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.profile-requests')
        ->call('openReview', $request->id)
        ->call('approveRequest')
        ->assertDispatched('modal-close', name: 'review-modal');

    $request->refresh();
    expect($request->status)->toBe('approved');

    $profile = StudentProfile::where('user_id', $student->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->father_name)->toBe('Dilip Basak');
    expect($profile->category)->toBe('OBC');
    expect($profile->issued_date)->toBeNull();
    expect($profile->pwbd_issued_date)->toBeNull();
    expect($profile->distance)->toBeNull();
    expect($profile->family_income)->toBeNull();
});
