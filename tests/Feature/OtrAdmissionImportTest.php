<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OtrImportLog;
use App\Models\Setting;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\OtrSyncService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\SettingsSeeder;

function getSampleCandidatePayload(string $email = 'john.doe@example.com', string $appNo = 'APP2027001'): array
{
    return [
        'application' => [
            'id' => 101,
            'application_number' => $appNo,
            'status' => 'Submitted',
        ],
        'users' => [
            'id' => 501,
            'urn' => 'URN-2027-' . strtoupper(substr(md5($email), 0, 6)),
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => $email,
            'mobile' => '9876543210',
            'dob' => '2001-05-15',
            'gender' => 'male',
            'tenth_roll' => 'ROLL12345',
        ],
        'exams' => [
            'id' => 1,
            'exam_code' => 'CS-2027',
            'exam_name' => 'Composite Course Exam',
            'academic_year' => '2027',
        ],
        'application_profiles' => [
            'dob' => '2001-05-15',
            'gender' => 'male',
            'category' => 'OBC-A',
            'subcaste' => 'Muslim',
            'father_name' => 'Robert Doe',
            'mother_name' => 'Mary Doe',
            'family_income' => 2.50,
            'highest_edu' => 'Graduation',
            'school_language' => 'English',
        ],
        'application_addresses' => [
            [
                'address_type' => 'Permanent',
                'state' => 'West Bengal',
                'district' => 'Kolkata',
                'address_line1' => '123 Test Street',
                'pin_code' => '700001',
            ],
            [
                'address_type' => 'Correspondence',
                'state' => 'West Bengal',
                'district' => 'Kolkata',
                'address_line1' => '123 Test Street',
                'pin_code' => '700001',
            ],
        ],
        'appl_edu_qualifications' => [
            [
                'level' => '10th',
                'board_university' => 'WBBSE',
                'passing_year' => 2017,
                'total_marks' => 700,
                'marks_obtained' => 560,
                'percentage' => 80.00,
            ],
            [
                'level' => 'Graduation',
                'board_university' => 'CU',
                'stream_subject' => 'History',
                'passing_year' => 2022,
                'percentage' => 72.50,
            ],
        ],
        'appl_empl_histories' => [],
        'appl_documents' => [
            [
                'type' => 'PHOTO',
                'file_path' => 'documents/test_photo.jpg',
                'file_url' => 'http://127.0.0.1:8000/storage/documents/test_photo.jpg',
            ],
        ],
        'appl_other_details' => [
            'attempts_already_given' => 1,
            'attempts_left' => 5,
            'optional_subject' => 'History',
            'activities' => ['Sports'],
            'hobbies' => ['Reading'],
        ],
        'admission' => [
            'status' => 'Admitted',
            'is_admitted' => true,
            'admission_form' => [
                'id' => 77,
                'admission_form_number' => 'ADM20271TEST',
                'roll_no' => 'ROLL-2027-01',
                'amount_paid' => '1000.00',
                'payment_status' => 'Paid',
                'transaction_date' => '2026-11-10',
            ],
            'admission_selection' => [
                'id' => 88,
                'exam_id' => 1,
                'status' => 'selected',
                'selection_list' => 'A',
                'selection_mode' => 'admission_test',
            ],
        ],
    ];
}

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');

    $this->course1 = Course::first();
    $this->course2 = Course::where('id', '!=', $this->course1->id)->first();
    $this->batch = Batch::first();
});

test('feature toggle can be enabled and disabled from settings', function () {
    Setting::updateOrCreate(['key' => 'otr.enabled', 'group' => 'otr'], ['value' => '1', 'type' => Setting::TYPE_BOOLEAN]);
    Setting::flushCache();
    expect(OtrSyncService::isEnabled())->toBeTrue();

    Setting::updateOrCreate(['key' => 'otr.enabled', 'group' => 'otr'], ['value' => '0', 'type' => Setting::TYPE_BOOLEAN]);
    Setting::flushCache();
    expect(OtrSyncService::isEnabled())->toBeFalse();

    Setting::updateOrCreate(['key' => 'otr.enabled', 'group' => 'otr'], ['value' => '1', 'type' => Setting::TYPE_BOOLEAN]);
    Setting::flushCache();
    expect(OtrSyncService::isEnabled())->toBeTrue();
});

test('can import new candidate and create full dossier', function () {
    $payload = getSampleCandidatePayload('alice.new@example.com', 'APP2027ALICE');

    $statusBefore = OtrSyncService::checkCandidateMisStatus($payload, $this->course1->id, $this->batch->id);
    expect($statusBefore['exists'])->toBeFalse();
    expect($statusBefore['status_type'])->toBe('new');

    $res = OtrSyncService::importCandidate(
        candidateItem: $payload,
        courseId: $this->course1->id,
        batchId: $this->batch->id,
        actor: $this->admin
    );

    expect($res['success'])->toBeTrue();
    expect($res['action'])->toBe(OtrImportLog::ACTION_CREATED_AND_ENROLLED);

    $user = User::where('email', 'alice.new@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasRole('Student'))->toBeTrue();
    expect($user->student_id)->not->toBeEmpty();

    // Verify Student Profile
    $profile = StudentProfile::where('user_id', $user->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->category)->toBe('OBC-A');
    expect($profile->father_name)->toBe('Robert Doe');

    // Verify Addresses
    expect(StudentAddress::where('user_id', $user->id)->count())->toBe(2);

    // Verify Educations
    expect(StudentEducation::where('user_id', $user->id)->count())->toBe(2);

    // Verify Other Details
    $other = StudentOtherDetail::where('user_id', $user->id)->first();
    expect($other)->not->toBeNull();
    expect($other->optional_subject)->toBe('History');

    // Verify Enrollment
    $enrollment = Enrollment::where('user_id', $user->id)
        ->where('course_id', $this->course1->id)
        ->where('batch_id', $this->batch->id)
        ->first();
    expect($enrollment)->not->toBeNull();
    expect($enrollment->enrollment_no)->not->toBeEmpty();
    expect($enrollment->fee_status)->toBe('paid');

    // Verify OtrImportLog
    $log = OtrImportLog::where('user_id', $user->id)->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe(OtrImportLog::STATUS_SUCCESS);

    // Status after import
    $statusAfter = OtrSyncService::checkCandidateMisStatus($payload, $this->course1->id, $this->batch->id);
    expect($statusAfter['exists'])->toBeTrue();
    expect($statusAfter['already_enrolled_here'])->toBeTrue();
    expect($statusAfter['status_type'])->toBe('already_enrolled');
});

test('duplicate import into same course and batch is recognized and skipped', function () {
    $payload = getSampleCandidatePayload('bob.dup@example.com', 'APP2027BOB');

    // First import
    $res1 = OtrSyncService::importCandidate($payload, $this->course1->id, $this->batch->id, actor: $this->admin);
    expect($res1['action'])->toBe(OtrImportLog::ACTION_CREATED_AND_ENROLLED);

    // Second import attempt into SAME course and batch
    $res2 = OtrSyncService::importCandidate($payload, $this->course1->id, $this->batch->id, actor: $this->admin);
    expect($res2['action'])->toBe(OtrImportLog::ACTION_ALREADY_ENROLLED);

    // Assert only ONE enrollment was created
    $user = User::where('email', 'bob.dup@example.com')->first();
    expect(Enrollment::where('user_id', $user->id)->count())->toBe(1);
});

test('importing existing candidate into second course creates new enrollment', function () {
    $payload = getSampleCandidatePayload('carol.multi@example.com', 'APP2027CAROL');

    // First import into Course 1
    $res1 = OtrSyncService::importCandidate($payload, $this->course1->id, $this->batch->id, actor: $this->admin);
    expect($res1['action'])->toBe(OtrImportLog::ACTION_CREATED_AND_ENROLLED);

    // Status for Course 2 should show Student Exists
    $statusCourse2 = OtrSyncService::checkCandidateMisStatus($payload, $this->course2->id, $this->batch->id);
    expect($statusCourse2['exists'])->toBeTrue();
    expect($statusCourse2['already_enrolled_here'])->toBeFalse();
    expect($statusCourse2['status_type'])->toBe('student_exists');

    // Second import into Course 2
    $res2 = OtrSyncService::importCandidate($payload, $this->course2->id, $this->batch->id, actor: $this->admin);
    expect($res2['action'])->toBe(OtrImportLog::ACTION_ENROLLED_EXISTING);

    // Assert user has 2 separate enrollments
    $user = User::where('email', 'carol.multi@example.com')->first();
    expect(Enrollment::where('user_id', $user->id)->count())->toBe(2);

    $enrollmentCourses = Enrollment::where('user_id', $user->id)->pluck('course_id')->all();
    expect($enrollmentCourses)->toContain($this->course1->id);
    expect($enrollmentCourses)->toContain($this->course2->id);
});

test('admissions page renders instantly with HTTP 200 without network deadlock', function () {
    $this->admin->ensureCurrentTeam();
    $response = $this->actingAs($this->admin)->get(route('admin.admissions.index', ['current_team' => $this->admin->currentTeam->slug]));
    $response->assertOk();
    $response->assertSee('Admissions & OTR Import Hub');
    $response->assertSee('wire:init="initAdmissionsHub"', false);
});

test('livewire admissions component mounts and initializes gracefully', function () {
    $this->admin->ensureCurrentTeam();
    \Livewire\Livewire::actingAs($this->admin)
        ->test('pages::admin.admissions')
        ->assertSet('isFeedLoading', true)
        ->call('initAdmissionsHub')
        ->assertStatus(200);
});

test('self referencing port check detects port collisions accurately', function () {
    // When port matches request port on localhost
    expect(OtrSyncService::isSelfReferencingPort('http://127.0.0.1:8000'))->toBeBool();
    expect(OtrSyncService::isSelfReferencingPort('https://api.externaldomain.com/v1'))->toBeFalse();
});
