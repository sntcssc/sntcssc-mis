<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseWithdrawalRequest;
use App\Models\Enrollment;
use App\Models\StudentDisciplinaryAction;
use App\Models\StudentProfile;
use App\Models\Team;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
    Storage::fake('public');
});

test('course withdrawal request model auto-generates sequential request code and has relationships', function () {
    $user = User::factory()->create();
    $course = Course::first();
    $batch = Batch::first();
    $enrollment = Enrollment::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'fee_amount' => 500,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    $req = CourseWithdrawalRequest::create([
        'student_id' => $user->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrollment_id' => $enrollment->id,
        'request_type' => 'withdrawal',
        'reason_category' => 'employment_selection',
        'reason_description' => 'Selected as Assistant Commissioner of Revenue.',
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    expect($req->request_number)->toStartWith('WTH-'.date('Y').'-')
        ->and($req->student->id)->toBe($user->id)
        ->and($req->course->id)->toBe($course->id)
        ->and($req->enrollment->id)->toBe($enrollment->id)
        ->and($req->isPending())->toBeTrue()
        ->and($req->getReasonLabel())->toContain('Employment');
});

test('student disciplinary action model auto-generates action code and tracks lifetime status', function () {
    $student = User::factory()->create();
    $admin = User::factory()->create();

    $action = StudentDisciplinaryAction::create([
        'student_id' => $student->id,
        'action_type' => 'lifetime_ban',
        'reason_category' => 'malpractice',
        'order_number' => 'ORD-2026-999',
        'order_date' => now()->toDateString(),
        'start_date' => now()->toDateString(),
        'is_lifetime' => true,
        'findings' => 'Candidate caught impersonating in mock test series and forged identity documentation.',
        'is_active' => true,
        'created_by' => $admin->id,
    ]);

    expect($action->action_code)->toStartWith('DISC-'.date('Y').'-')
        ->and($action->isActive())->toBeTrue()
        ->and($action->isLifetime())->toBeTrue()
        ->and($action->student->id)->toBe($student->id)
        ->and($action->creator->id)->toBe($admin->id);
});

test('student can submit course withdrawal request from student dashboard and cancel it if pending', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = $student->currentTeam;

    // Create student profile
    StudentProfile::create([
        'user_id' => $student->id,
        'category' => 'UR',
    ]);

    $course = Course::first();
    $batch = Batch::first();
    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'fee_amount' => 1000,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    $this->actingAs($student);

    // Mount Student Dashboard and submit withdrawal
    $proof = UploadedFile::fake()->create('medical_cert.pdf', 200, 'application/pdf');

    Livewire::test('pages::student.dashboard')
        ->call('openWithdrawalModal', $enrollment->id)
        ->assertSet('withdrawalEnrollmentId', $enrollment->id)
        ->set('withdrawalForm.request_type', 'discontinuation')
        ->set('withdrawalForm.reason_category', 'health_medical')
        ->set('withdrawalForm.reason_description', 'Suffered severe spinal fracture requiring 4 months hospitalization and complete rest.')
        ->set('withdrawalDocument', $proof)
        ->call('submitWithdrawalRequest')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $request = CourseWithdrawalRequest::where('enrollment_id', $enrollment->id)->first();
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('pending')
        ->and($request->reason_category)->toBe('health_medical')
        ->and($request->supporting_document_path)->not->toBeNull();

    // Verify Audit Log
    $log = AuditLog::where('event', 'course_withdrawal.submitted')->latest()->first();
    expect($log)->not->toBeNull();

    // Student cancels pending withdrawal request
    Livewire::test('pages::student.dashboard')
        ->call('cancelWithdrawalRequest', $request->id)
        ->assertDispatched('toast');

    $request->refresh();
    expect($request->status)->toBe('withdrawn')
        ->and($request->admin_notes)->toContain('Cancelled by student');
});

test('admin can review, approve or reject course withdrawal requests with DB transaction and seat update', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');
    $course = Course::first();
    $batch = Batch::first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'fee_amount' => 1000,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    $withdrawalReq = CourseWithdrawalRequest::create([
        'student_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrollment_id' => $enrollment->id,
        'request_type' => 'withdrawal',
        'reason_category' => 'employment_selection',
        'reason_description' => 'Selected in State Public Service Commission as Deputy Collector.',
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    $this->actingAs($admin);

    // Test rejection workflow
    Livewire::test('pages::admin.course-withdrawals')
        ->call('openReviewModal', $withdrawalReq->id)
        ->set('reviewForm.status', 'rejected')
        ->set('reviewForm.admin_notes', 'Rejected: Please submit the joining letter for official file record.')
        ->call('processReview')
        ->assertDispatched('toast');

    $withdrawalReq->refresh();
    $enrollment->refresh();
    expect($withdrawalReq->status)->toBe('rejected')
        ->and($withdrawalReq->reviewed_by)->toBe($admin->id)
        ->and($enrollment->status)->toBe('active'); // Still active

    // Now test approval workflow (re-opened or new)
    $withdrawalReq->update(['status' => 'pending']);

    Livewire::test('pages::admin.course-withdrawals')
        ->call('openReviewModal', $withdrawalReq->id)
        ->set('reviewForm.status', 'approved')
        ->set('reviewForm.target_enrollment_status', 'withdrawn')
        ->set('reviewForm.effective_date', now()->toDateString())
        ->set('reviewForm.admin_notes', 'Approved by Competent Authority upon verification of appointment letter.')
        ->call('processReview')
        ->assertDispatched('toast');

    $withdrawalReq->refresh();
    $enrollment->refresh();

    expect($withdrawalReq->status)->toBe('approved')
        ->and($withdrawalReq->reviewed_by)->toBe($admin->id)
        ->and($enrollment->status)->toBe('withdrawn')
        ->and($enrollment->discontinued_at)->not->toBeNull()
        ->and($enrollment->discontinued_by)->toBe($admin->id)
        ->and($enrollment->cancellation_reason)->toContain('Approved Course Withdrawal');

    // Verify AuditLog recorded
    $log = AuditLog::where('event', 'course_withdrawal.reviewed')->latest()->first();
    expect($log)->not->toBeNull();
});

test('admin can directly cancel or discontinue any student enrollment with official reason', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $student = User::factory()->create();
    $course = Course::first();
    $batch = Batch::first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'fee_amount' => 500,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::admin.enrollments')
        ->call('openDirectCancelModal', $enrollment->id)
        ->assertSet('directCancelId', $enrollment->id)
        ->set('directCancelForm.status', 'discontinued')
        ->set('directCancelForm.reason', 'non_attendance')
        ->set('directCancelForm.effective_date', now()->toDateString())
        ->set('directCancelForm.notes', 'Candidate absent for more than 45 continuous days without prior intimation.')
        ->call('executeDirectCancel')
        ->assertDispatched('toast');

    $enrollment->refresh();
    expect($enrollment->status)->toBe('discontinued')
        ->and($enrollment->cancellation_reason)->toContain('Non_attendance')
        ->and($enrollment->discontinued_by)->toBe($admin->id)
        ->and($enrollment->discontinued_at)->not->toBeNull();

    $log = AuditLog::where('event', 'enrollment.cancelled')->latest()->first();
    expect($log)->not->toBeNull();
});

test('admin can impose disciplinary suspension or lifetime ban on student and lift it later', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $student = User::factory()->create(['status' => 'active']);
    $student->assignRole('Student');

    $this->actingAs($admin);

    // Impose lifetime ban
    Livewire::test('pages::admin.students')
        ->call('openDisciplineModal', $student->id)
        ->set('disciplineForm.action_type', 'lifetime_ban')
        ->set('disciplineForm.reason_category', 'misconduct')
        ->set('disciplineForm.order_number', 'ORD-2026-BAN-01')
        ->set('disciplineForm.order_date', now()->toDateString())
        ->set('disciplineForm.start_date', now()->toDateString())
        ->set('disciplineForm.findings', 'Found guilty of severe misconduct, physical assault on campus, and gross indiscipline.')
        ->call('saveDisciplinaryAction')
        ->assertDispatched('toast');

    $student->refresh();
    expect($student->status)->toBe('banned')
        ->and($student->isBanned())->toBeTrue();

    $action = StudentDisciplinaryAction::where('user_id', $student->id)->where('status', 'active')->first();
    expect($action)->not->toBeNull()
        ->and($action->action_type)->toBe('lifetime_ban')
        ->and($action->is_lifetime)->toBeTrue();

    // Now lift the disciplinary sanction
    Livewire::test('pages::admin.students')
        ->call('openLiftSanctionModal', $student->id)
        ->set('liftSanctionForm.revocation_order_no', 'REV-2026-CLEARED-01')
        ->set('liftSanctionForm.revocation_reason', 'Governing Body reviewed mercy appeal, student tendered unconditional apology and served 6 months community service.')
        ->call('liftSanction')
        ->assertDispatched('toast');

    $student->refresh();
    $action->refresh();

    expect($action->is_active)->toBeFalse()
        ->and($action->revoked_at)->not->toBeNull()
        ->and($action->revoked_by)->toBe($admin->id)
        ->and($student->status)->toBe('active')
        ->and($student->isBanned())->toBeFalse();
});

test('disciplinary middleware redirects banned or suspended students away from learning features to dashboard with notice', function () {
    $student = User::factory()->create(['status' => 'active']);
    $student->assignRole('Student');
    $team = $student->currentTeam;

    StudentProfile::create([
        'user_id' => $student->id,
        'category' => 'UR',
    ]);

    // Impose temporary suspension
    StudentDisciplinaryAction::create([
        'student_id' => $student->id,
        'action_type' => 'temporary_suspension',
        'reason_category' => 'indiscipline',
        'order_number' => 'ORD-SUSP-11',
        'order_date' => now()->toDateString(),
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
        'is_lifetime' => false,
        'findings' => 'Temporary suspension pending inquiry.',
        'is_active' => true,
    ]);

    $this->actingAs($student);

    // Attempt to access meetings or chat (should be redirected to student.dashboard with disciplinary_warning)
    $response = $this->get(route('meetings.index', ['current_team' => $team->slug]));
    $response->assertRedirect(route('student.dashboard', ['current_team' => $team->slug]))
        ->assertSessionHas('disciplinary_warning');

    // Access to student.dashboard itself should be allowed (status 200) so they can view the notice
    $dashResponse = $this->get(route('student.dashboard', ['current_team' => $team->slug]));
    $dashResponse->assertOk()
        ->assertSee('Official Institutional Disciplinary Sanction Active');
});

test('enrollment model auto-generates meaningful enrollment number format and persists student id', function () {
    $student = User::factory()->create([
        'student_id' => '202610088',
    ]);
    $course = Course::first();
    $batch = Batch::first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'fee_amount' => 1500,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    expect($enrollment->enrollment_no)->toMatch('/^EN-\d{4}-.+-\d{4}$/')
        ->and($enrollment->student_id)->toBe('202610088');

    // Verify database record has student_id populated
    $dbRecord = DB::table('enrollments')->where('id', $enrollment->id)->first();
    expect($dbRecord->student_id)->toBe('202610088')
        ->and($dbRecord->enrollment_no)->toStartWith('EN-');
});

test('student can access dedicated academics and enrolment page and view active courses, student id, and withdrawal history', function () {
    $student = User::factory()->create([
        'student_id' => '202610099',
    ]);
    $student->assignRole('Student');
    $team = $student->currentTeam;

    StudentProfile::create([
        'user_id' => $student->id,
        'category' => 'OBC-A',
    ]);

    $course = Course::first();
    $batch = Batch::first();
    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'fee_amount' => 500,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    $this->actingAs($student);

    // Direct HTTP get to route
    $response = $this->get(route('student.academics.index', ['current_team' => $team->slug]));
    $response->assertOk()
        ->assertSee('Academics & Enrolments')
        ->assertSee('202610099')
        ->assertSee($enrollment->enrollment_no);

    // Test pages::student.academics Livewire component
    Livewire::test('pages::student.academics')
        ->assertSee('Academics & Enrolments')
        ->assertSee($course->name)
        ->assertSee('202610099')
        ->assertSee($enrollment->enrollment_no)
        ->call('setTab', 'active')
        ->assertSee($course->name)
        ->call('setTab', 'history')
        ->assertSee('No Withdrawal or Discontinuation Requests Filed')
        ->call('openWithdrawalModal', $enrollment->id)
        ->set('withdrawalForm.request_type', 'withdrawal')
        ->set('withdrawalForm.reason_category', 'relocation')
        ->set('withdrawalForm.reason_description', 'Relocating to another state due to family circumstances.')
        ->call('submitWithdrawalRequest')
        ->assertDispatched('toast');

    expect(CourseWithdrawalRequest::where('enrollment_id', $enrollment->id)->exists())->toBeTrue();

    // Verify student dashboard showcases active enrolments and withdrawal history
    Livewire::test('pages::student.dashboard')
        ->assertSee('Active Course Enrolments')
        ->assertSee($course->name)
        ->assertSee('202610099')
        ->assertSee($enrollment->enrollment_no)
        ->assertSee('Course Withdrawal & Discontinuation History');
});

test('admin can systematically revert a discontinued enrollment back to active from admin students dossier', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create(['status' => 'inactive']);
    $student->assignRole('Student');
    $course = Course::first();
    $batch = Batch::first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now()->subMonths(2),
        'fee_amount' => 1000,
        'fee_status' => 'paid',
        'status' => 'discontinued',
        'discontinued_at' => now()->subMonth(),
        'cancellation_reason' => 'Disciplinary: Excessive non-attendance',
    ]);

    expect($enrollment->canBeReinstated())->toBeTrue();

    $this->actingAs($admin);

    Livewire::test('pages::admin.students')
        ->set('viewingStudentId', $student->id)
        ->call('openReinstateModal', $enrollment->id)
        ->assertSet('reinstateEnrollmentId', $enrollment->id)
        ->set('reinstateForm.order_number', 'ORD-REIN-2026-001')
        ->set('reinstateForm.reason', 'Director reviewed apology letter and medical certificates; granted reinstatement.')
        ->set('reinstateForm.activate_user', true)
        ->call('executeReinstate')
        ->assertDispatched('toast');

    $enrollment->refresh();
    $student->refresh();

    expect($enrollment->status)->toBe('active')
        ->and($enrollment->reinstated_at)->not->toBeNull()
        ->and($enrollment->reinstated_by)->toBe($admin->id)
        ->and($enrollment->reinstatement_reason)->toContain('ORD-REIN-2026-001')
        ->and($student->status)->toBe('active');

    expect(AuditLog::where('event', 'enrollment.reinstated')->where('auditable_id', $enrollment->id)->exists())->toBeTrue();
});

test('admin can systematically revert a cancelled enrollment back to active from admin enrollments page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create(['status' => 'active']);
    $student->assignRole('Student');
    $course = Course::first();
    $batch = Batch::first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now()->subMonths(3),
        'fee_amount' => 1500,
        'fee_status' => 'paid',
        'status' => 'cancelled',
        'cancellation_reason' => 'Failed qualification verification',
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::admin.enrollments')
        ->call('openReinstateModal', $enrollment->id)
        ->assertSet('reinstateId', $enrollment->id)
        ->set('reinstateForm.reason', 'Candidate produced genuine degree certificate duly verified by university registrar.')
        ->call('executeReinstate')
        ->assertDispatched('toast');

    $enrollment->refresh();
    expect($enrollment->status)->toBe('active')
        ->and($enrollment->reinstated_at)->not->toBeNull();
});

test('admin can revert an approved course withdrawal request and reinstate student from course withdrawals page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create(['status' => 'active']);
    $student->assignRole('Student');
    $course = Course::first();
    $batch = Batch::first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now()->subMonths(1),
        'fee_amount' => 500,
        'fee_status' => 'paid',
        'status' => 'withdrawn',
        'discontinued_at' => now()->subDays(5),
    ]);

    $req = CourseWithdrawalRequest::create([
        'student_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrollment_id' => $enrollment->id,
        'request_type' => 'withdrawal',
        'reason_category' => 'employment',
        'reason_description' => 'Selected in central government job.',
        'status' => 'approved',
        'effective_date' => now()->subDays(5),
        'reviewed_by' => $admin->id,
        'reviewed_at' => now()->subDays(5),
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::admin.course-withdrawals')
        ->call('openRevertModal', $req->id)
        ->assertSet('revertingRequestId', $req->id)
        ->set('revertForm.revert_reason', 'Joining postponed by employer; student requested to rejoin current batch with Director approval.')
        ->call('executeRevert')
        ->assertDispatched('toast');

    $req->refresh();
    $enrollment->refresh();

    expect($req->status)->toBe('reverted')
        ->and($req->isReverted())->toBeTrue()
        ->and($enrollment->status)->toBe('active')
        ->and($enrollment->reinstated_at)->not->toBeNull();

    expect(AuditLog::where('event', 'course_withdrawal.reverted')->where('auditable_id', $req->id)->exists())->toBeTrue();
});

test('admin can visit students route in admin panel without error and see active disciplinary actions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $team = Team::first() ?? Team::factory()->create();
    $admin->update(['current_team_id' => $team->id]);

    $student = User::factory()->create();
    $student->assignRole('Student');

    // Create a disciplinary action on the student to ensure relation and active() scope are exercised
    StudentDisciplinaryAction::create([
        'user_id' => $student->id,
        'action_type' => 'temporary_suspension',
        'order_no' => 'ORD-TEST-01',
        'order_date' => now()->toDateString(),
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(15)->toDateString(),
        'reason_category' => 'disciplinary_misconduct',
        'reason_details' => 'Misconduct in examination hall.',
        'status' => 'active',
    ]);

    $this->actingAs($admin);

    $response = $this->get(route('admin.students.index', ['current_team' => $team->slug]));
    $response->assertOk();

    Livewire::test('pages::admin.students')
        ->assertOk()
        ->assertSee($student->name)
        ->assertSee('Suspended');
});
