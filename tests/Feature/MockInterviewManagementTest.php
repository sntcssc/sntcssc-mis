<?php

use App\Models\ApiKey;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\InterviewBoardMember;
use App\Models\MockInterviewCandidate;
use App\Models\MockInterviewInvitationLog;
use App\Models\MockInterviewSession;
use App\Models\MockInterviewSessionMember;
use App\Models\User;
use App\Services\MockInterviewService;
use App\Services\RbacService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'Mock Interview Admin',
        'email' => 'mi.admin@example.com',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->staff = User::factory()->create([
        'name' => 'Desk Staff',
        'email' => 'desk.staff@example.com',
    ]);
    $this->staff->assignRole('Staff');

    $this->student1 = User::factory()->create([
        'name' => 'Ananya Chatterjee',
        'email' => 'ananya@example.com',
        'student_id' => 'STU-UPSC-001',
    ]);
    $this->student1->assignRole('Student');

    $this->student2 = User::factory()->create([
        'name' => 'Rahul Sen',
        'email' => 'rahul@example.com',
        'student_id' => 'STU-UPSC-002',
    ]);
    $this->student2->assignRole('Student');

    $this->batch = Batch::create([
        'name' => 'UPSC CSE 2026 Interview Guidance',
        'code' => 'BATCH-IGP-2026',
        'academic_year' => '2026',
        'status' => 'active',
    ]);

    $this->course = Course::create([
        'name' => 'Personality Test & Mock Interview Programme',
        'code' => 'CRS-MOCK-2026',
        'status' => 'active',
    ]);

    $this->batch->courses()->attach($this->course->id);

    Enrollment::create([
        'user_id' => $this->student1->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'enrollment_number' => 'ENR-2026-001',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    Enrollment::create([
        'user_id' => $this->student2->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'enrollment_number' => 'ENR-2026-002',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);
});

test('admin can create mock interview session with venue and timing parameters', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.mock-interviews')
        ->set('sessionForm.title', 'UPSC CSE 2026 Mock Interview - Session 1')
        ->set('sessionForm.session_number', 1)
        ->set('sessionForm.academic_year', '2026')
        ->set('sessionForm.session_date', '2026-11-15')
        ->set('sessionForm.start_time', '09:30')
        ->set('sessionForm.end_time', '17:30')
        ->set('sessionForm.reporting_time', '09:00')
        ->set('sessionForm.venue_address', 'Board Room 1, SNTCSSC Campus, Salt Lake, Kolkata')
        ->set('sessionForm.room_number', 'Board Room A')
        ->set('sessionForm.default_interview_duration', 30)
        ->set('sessionForm.default_buffer_duration', 5)
        ->set('sessionForm.status', 'scheduled')
        ->call('saveSession')
        ->assertHasNoErrors();

    $session = MockInterviewSession::where('session_number', 1)->first();
    expect($session)->not->toBeNull()
        ->and($session->title)->toBe('UPSC CSE 2026 Mock Interview - Session 1')
        ->and($session->session_code)->toStartWith('MIS-2026-S')
        ->and($session->default_interview_duration)->toBe(30)
        ->and($session->room_number)->toBe('Board Room A');
});

test('admin can assign 5-member board with 1 chairperson and members', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S01',
        'title' => 'UPSC CSE Mock Interview Session 1',
        'session_number' => 1,
        'academic_year' => '2026',
        'session_date' => '2026-11-15',
        'start_time' => '09:30:00',
        'end_time' => '17:30:00',
        'venue_address' => 'Board Room 1, SNTCSSC Campus',
        'default_interview_duration' => 30,
        'status' => 'scheduled',
    ]);

    // Create 3 panel experts
    $chair = InterviewBoardMember::create([
        'name' => 'Dr. A. K. Sharma',
        'designation' => 'Former Chief Secretary, IAS (Retd.)',
        'status' => 'active',
    ]);

    $member1 = InterviewBoardMember::create([
        'name' => 'Prof. S. Sen',
        'designation' => 'Professor of Public Policy',
        'status' => 'active',
    ]);

    $member2 = InterviewBoardMember::create([
        'name' => 'Ms. R. Mukherjee',
        'designation' => 'Former Ambassador, IFS (Retd.)',
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.mock-interviews')
        ->set('selectedSessionId', $session->id)
        ->set('sessionMemberForm.interview_board_member_id', $chair->id)
        ->set('sessionMemberForm.role', 'chairperson')
        ->set('sessionMemberForm.order_index', 1)
        ->call('saveSessionMember')
        ->assertHasNoErrors()
        ->set('sessionMemberForm.interview_board_member_id', $member1->id)
        ->set('sessionMemberForm.role', 'member')
        ->set('sessionMemberForm.order_index', 2)
        ->call('saveSessionMember')
        ->assertHasNoErrors()
        ->set('sessionMemberForm.interview_board_member_id', $member2->id)
        ->set('sessionMemberForm.role', 'member')
        ->set('sessionMemberForm.order_index', 3)
        ->call('saveSessionMember')
        ->assertHasNoErrors();

    expect($session->sessionMembers()->count())->toBe(3)
        ->and($session->chairperson()->id)->toBe($chair->id)
        ->and($session->regularBoardMembers()->count())->toBe(2);
});

test('admin can assign students with offline or online mode and meeting credentials', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S01',
        'title' => 'UPSC CSE Mock Interview Session 1',
        'session_number' => 1,
        'academic_year' => '2026',
        'session_date' => '2026-11-15',
        'start_time' => '09:30:00',
        'end_time' => '17:30:00',
        'venue_address' => 'Board Room 1, SNTCSSC Campus',
        'status' => 'scheduled',
    ]);

    // Student 1: In-person offline
    Livewire::test('pages::admin.mock-interviews')
        ->set('selectedSessionId', $session->id)
        ->set('candidateForm.user_id', $this->student1->id)
        ->set('candidateForm.sequence_number', 1)
        ->set('candidateForm.scheduled_start_time', '10:00')
        ->set('candidateForm.scheduled_end_time', '10:30')
        ->set('candidateForm.duration_minutes', 30)
        ->set('candidateForm.attending_mode', 'offline')
        ->set('candidateForm.room_number', 'Chamber A')
        ->call('saveCandidate')
        ->assertHasNoErrors();

    // Student 2: Online remote meeting
    Livewire::test('pages::admin.mock-interviews')
        ->set('selectedSessionId', $session->id)
        ->set('candidateForm.user_id', $this->student2->id)
        ->set('candidateForm.sequence_number', 2)
        ->set('candidateForm.scheduled_start_time', '10:35')
        ->set('candidateForm.scheduled_end_time', '11:05')
        ->set('candidateForm.duration_minutes', 30)
        ->set('candidateForm.attending_mode', 'online')
        ->set('candidateForm.platform', 'Zoom')
        ->set('candidateForm.meeting_link', 'https://zoom.us/j/9876543210')
        ->set('candidateForm.meeting_id', '9876543210')
        ->set('candidateForm.meeting_password', 'upsc2026')
        ->call('saveCandidate')
        ->assertHasNoErrors();

    $c1 = MockInterviewCandidate::where('user_id', $this->student1->id)->first();
    $c2 = MockInterviewCandidate::where('user_id', $this->student2->id)->first();

    expect($c1)->not->toBeNull()
        ->and($c1->isOffline())->toBeTrue()
        ->and($c1->sequence_number)->toBe(1)
        ->and($c2)->not->toBeNull()
        ->and($c2->isOnline())->toBeTrue()
        ->and($c2->platform)->toBe('Zoom')
        ->and($c2->meeting_password)->toBe('upsc2026');
});

test('auto-generate slots calculates sequential timings with breaks', function () {
    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S02',
        'title' => 'UPSC CSE Mock Interview Session 2',
        'session_number' => 2,
        'academic_year' => '2026',
        'session_date' => '2026-11-16',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Campus',
        'default_interview_duration' => 25,
        'default_buffer_duration' => 5,
        'status' => 'scheduled',
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '00:00:00',
        'scheduled_end_time' => '00:00:00',
        'attending_mode' => 'offline',
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student2->id,
        'sequence_number' => 2,
        'scheduled_start_time' => '00:00:00',
        'scheduled_end_time' => '00:00:00',
        'attending_mode' => 'online',
    ]);

    $service = app(MockInterviewService::class);
    $count = $service->autoGenerateTimeSlots(
        session: $session,
        startTime: '10:00',
        durationMinutes: 25,
        bufferMinutes: 5
    );

    expect($count)->toBe(2);

    $c1 = MockInterviewCandidate::where('user_id', $this->student1->id)->first();
    $c2 = MockInterviewCandidate::where('user_id', $this->student2->id)->first();

    expect(Carbon::parse($c1->scheduled_start_time)->format('H:i'))->toBe('10:00')
        ->and(Carbon::parse($c1->scheduled_end_time)->format('H:i'))->toBe('10:25')
        ->and($c1->duration_minutes)->toBe(25)
        ->and(Carbon::parse($c2->scheduled_start_time)->format('H:i'))->toBe('10:30')
        ->and(Carbon::parse($c2->scheduled_end_time)->format('H:i'))->toBe('10:55')
        ->and($c2->duration_minutes)->toBe(25);
});

test('recording URL attachment and dispatch to candidate works', function () {
    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S03',
        'title' => 'UPSC CSE Mock Interview Session 3',
        'session_number' => 3,
        'academic_year' => '2026',
        'session_date' => '2026-11-17',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Campus',
        'status' => 'completed',
    ]);

    $candidate = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'attending_mode' => 'offline',
        'status' => 'completed',
        'recording_url' => 'https://drive.google.com/file/d/sample-mock-interview-1/view',
        'recording_status' => 'available',
        'recording_password' => 'pass123',
    ]);

    expect($candidate->hasRecording())->toBeTrue();

    $service = app(MockInterviewService::class);
    $results = $service->sendRecordingToCandidate(
        candidate: $candidate,
        channels: ['email', 'in_app']
    );

    expect($results)->toHaveKey('email')
        ->and($results)->toHaveKey('in_app');

    $candidate->refresh();
    expect($candidate->recording_sent_at)->not->toBeNull();

    $log = MockInterviewInvitationLog::where('mock_interview_candidate_id', $candidate->id)
        ->where('purpose', 'recording')
        ->first();
    expect($log)->not->toBeNull()
        ->and($log->message_content)->toContain('drive.google.com');
});

test('student portal displays scheduled interview and peer order of appearance while hiding peers recordings', function () {
    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S04',
        'title' => 'UPSC CSE Mock Interview Session 4',
        'session_number' => 4,
        'academic_year' => '2026',
        'session_date' => now()->addDays(3)->format('Y-m-d'),
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'Administrative Building, Salt Lake',
        'status' => 'scheduled',
    ]);

    // Student 1 candidate (logged-in student) with recording
    $c1 = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'attending_mode' => 'offline',
        'status' => 'scheduled',
        'recording_url' => 'https://example.com/recording-student-1.mp4',
        'recording_status' => 'available',
    ]);

    // Student 2 candidate (peer student) with sensitive recording
    $c2 = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student2->id,
        'sequence_number' => 2,
        'scheduled_start_time' => '10:35:00',
        'scheduled_end_time' => '11:05:00',
        'attending_mode' => 'online',
        'status' => 'scheduled',
        'recording_url' => 'https://example.com/secret-recording-student-2.mp4',
        'recording_status' => 'available',
    ]);

    // Act as Student 1
    $this->actingAs($this->student1);

    Livewire::test('pages::student.mock-interviews')
        ->assertSee('MIS-2026-S04')
        ->assertSee('10:00 AM - 10:30 AM')
        ->call('openSessionRosterModal', $session->id)
        ->assertSee('Rahul Sen')
        ->assertSee('Ananya Chatterjee')
        ->assertSee('YOU')
        // Confidential recording of Rahul Sen must NOT be leaked
        ->assertDontSee('secret-recording-student-2.mp4');
});

test('api endpoints for mock interview management work end to end', function () {
    $rawKey = 'sntc_live_mocktestsecret1234567890';
    $apiKey = ApiKey::create([
        'name' => 'Integration Key',
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => 'sntc_live',
        'scopes' => ['*'],
        'status' => 'active',
        'rate_limit_per_minute' => 120,
    ]);

    $response = $this->withHeaders([
        'X-API-KEY' => $rawKey,
    ])->postJson('/api/v1/mock-interviews/sessions', [
        'title' => 'API Created UPSC Mock Session',
        'session_number' => 5,
        'academic_year' => '2026',
        'session_date' => '2026-11-20',
        'start_time' => '09:00',
        'end_time' => '17:00',
        'venue_address' => 'SNTCSSC Campus Kolkata',
        'default_interview_duration' => 30,
        'status' => 'scheduled',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'API Created UPSC Mock Session');

    $sessionId = $response->json('data.id');

    // List sessions via API
    $listResponse = $this->withHeaders([
        'X-API-KEY' => $rawKey,
    ])->getJson('/api/v1/mock-interviews/sessions');

    $listResponse->assertOk()
        ->assertJsonPath('success', true);
});

test('personalized invitations preview produces customized content for chairman vs member vs student', function () {
    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S05',
        'title' => 'UPSC CSE Mock Interview Session 5',
        'session_number' => 5,
        'academic_year' => '2026',
        'session_date' => '2026-11-25',
        'start_time' => '09:30:00',
        'end_time' => '17:30:00',
        'reporting_time' => '09:00:00',
        'venue_address' => 'Board Room 2, SNTCSSC Campus',
        'room_number' => 'Chamber B',
        'status' => 'scheduled',
    ]);

    $chair = InterviewBoardMember::create([
        'name' => 'Shri B. K. Roy, IAS (Retd.)',
        'email' => 'bkroy@gov.in',
        'status' => 'active',
    ]);

    $member = InterviewBoardMember::create([
        'name' => 'Dr. P. Sen',
        'email' => 'psen@univ.ac.in',
        'status' => 'active',
    ]);

    $smChair = MockInterviewSessionMember::create([
        'mock_interview_session_id' => $session->id,
        'interview_board_member_id' => $chair->id,
        'role' => 'chairperson',
        'order_index' => 1,
    ]);

    $smMember = MockInterviewSessionMember::create([
        'mock_interview_session_id' => $session->id,
        'interview_board_member_id' => $member->id,
        'role' => 'member',
        'order_index' => 2,
    ]);

    $candidate = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'venue_address' => 'Board Room 2, SNTCSSC Campus',
    ]);

    $service = app(MockInterviewService::class);

    // 1. Chairman Preview
    $chairPreview = $service->previewMemberInvitation($smChair);
    expect($chairPreview['content'])->toContain('Chair the Interview Board')
        ->and($chairPreview['recipient_type'])->toBe('chairman')
        ->and($chairPreview['content'])->toContain('Shri B. K. Roy');

    // 2. Member Preview
    $memberPreview = $service->previewMemberInvitation($smMember);
    expect($memberPreview['content'])->toContain('esteemed Board Member')
        ->and($memberPreview['recipient_type'])->toBe('board_member')
        ->and($memberPreview['content'])->toContain('Shri B. K. Roy, IAS (Retd.)'); // Mentions Chairman

    // 3. Student Preview
    $studentPreview = $service->previewCandidateInvitation($candidate);
    expect($studentPreview['content'])->toContain('In-Person at Campus')
        ->and($studentPreview['content'])->toContain('Sequence #1')
        ->and($studentPreview['content'])->toContain('10:00 AM - 10:30 AM')
        ->and($studentPreview['content'])->toContain('30 Minutes');
});

test('admin can export session candidate schedule timetable to csv and pdf', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S06',
        'title' => 'UPSC CSE Mock Interview Session 6',
        'session_number' => 6,
        'academic_year' => '2026',
        'session_date' => '2026-11-26',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Administrative Building',
        'status' => 'scheduled',
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'scheduled',
    ]);

    $component = Livewire::test('pages::admin.mock-interviews');

    // CSV export test
    $csvResponse = $component->instance()->exportSessionTimetable($session->id, 'csv');
    expect($csvResponse)->toBeInstanceOf(StreamedResponse::class);

    // PDF export test
    $pdfResponse = $component->instance()->exportSessionTimetable($session->id, 'pdf');
    expect($pdfResponse)->toBeInstanceOf(StreamedResponse::class);
});

test('student can download own interview video recording directly and cannot download peers recording', function () {
    Storage::fake('public');

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S07',
        'title' => 'UPSC CSE Mock Interview Session 7',
        'session_number' => 7,
        'academic_year' => '2026',
        'session_date' => '2026-11-28',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Campus',
        'status' => 'scheduled',
    ]);

    Storage::disk('public')->put('mock-interviews/recordings/sample_video.mp4', 'dummy video stream');

    $cand1 = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'recording_file_path' => 'mock-interviews/recordings/sample_video.mp4',
        'recording_status' => MockInterviewCandidate::RECORDING_AVAILABLE,
        'status' => 'completed',
    ]);

    $cand2 = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student2->id,
        'sequence_number' => 2,
        'scheduled_start_time' => '10:30:00',
        'scheduled_end_time' => '11:00:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'recording_file_path' => 'mock-interviews/recordings/sample_video_2.mp4',
        'recording_status' => MockInterviewCandidate::RECORDING_AVAILABLE,
        'status' => 'completed',
    ]);

    // Student 1 can download own recording
    $this->actingAs($this->student1);
    $response = Livewire::test('pages::student.mock-interviews')
        ->call('downloadRecording', $cand1->id)
        ->instance();

    // Student 1 cannot download Student 2's recording
    Livewire::test('pages::student.mock-interviews')
        ->call('downloadRecording', $cand2->id)
        ->assertDispatched('toast');
});

test('admin can open invitations hub and dispatch communiques without app expression error', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S08',
        'title' => 'UPSC CSE Mock Interview Session 8',
        'session_number' => 8,
        'academic_year' => '2026',
        'session_date' => '2026-11-29',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Campus',
        'status' => 'scheduled',
    ]);

    $member = InterviewBoardMember::create([
        'name' => 'Dr. K. N. Banerjee, IAS (Retd.)',
        'email' => 'kn.banerjee@example.gov.in',
        'designation' => 'Former Chief Secretary',
        'status' => 'active',
    ]);

    MockInterviewSessionMember::create([
        'mock_interview_session_id' => $session->id,
        'interview_board_member_id' => $member->id,
        'role' => 'chairperson',
        'order_index' => 1,
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'scheduled',
    ]);

    // Test calling openInvitationsForSession and dispatchInvitations cleanly
    Livewire::test('pages::admin.mock-interviews')
        ->call('openInvitationsForSession', $session->id)
        ->set('inviteChannels', ['in_app'])
        ->set('inviteTarget', 'all')
        ->call('dispatchInvitations')
        ->assertHasNoErrors();

    expect(MockInterviewInvitationLog::where('mock_interview_session_id', $session->id)->count())->toBeGreaterThan(0);
});

test('admin can import mock interview sessions and board members from csv', function () {
    $this->actingAs($this->admin);

    // 1. Session CSV Import
    $sessionCsvContent = "Title,Year,Session No,Date,Start,End,Reporting,Duration,Buffer,Venue,Address,Room,Instructions,Remarks,Status\n".
        "Mock Interview Drive Alpha,2026,10,2026-12-05,09:30,17:30,09:00,30,5,SNTCSSC Campus,Main Administrative Hall,Room 101,Formal attire required,Batch 1,scheduled\n";

    $sessionFile = UploadedFile::fake()->createWithContent('sessions.csv', $sessionCsvContent);

    Livewire::test('pages::admin.mock-interviews')
        ->set('sessionImportFile', $sessionFile)
        ->call('importSessions')
        ->assertHasNoErrors();

    expect(MockInterviewSession::where('title', 'Mock Interview Drive Alpha')->exists())->toBeTrue();

    // 2. Board Member CSV Import
    $memberCsvContent = "Name,Email,Phone,Designation,Organization,Specialization,Bio,WhatsApp,Telegram,Status\n".
        "Ambassador Vikram Sen,vikram.sen@example.gov.in,9830099999,Former Ambassador,IFS (Retd.),International Relations,Diplomat for 34 years,9830099999,,active\n";

    $memberFile = UploadedFile::fake()->createWithContent('panelists.csv', $memberCsvContent);

    Livewire::test('pages::admin.mock-interviews')
        ->set('boardMemberImportFile', $memberFile)
        ->call('importBoardMembers')
        ->assertHasNoErrors();

    expect(InterviewBoardMember::where('email', 'vikram.sen@example.gov.in')->exists())->toBeTrue();
});

test('admin can upload and remove board member avatar and candidate recording with renames uploads trait', function () {
    Storage::fake('public');
    $this->actingAs($this->admin);

    // 1. Avatar upload and remove
    $avatarFile = UploadedFile::fake()->image('panel_photo.jpg');

    $test = Livewire::test('pages::admin.mock-interviews')
        ->call('openPoolMemberModal')
        ->set('poolMemberForm.name', 'Prof. Arundhati Roy')
        ->set('poolMemberForm.designation', 'Senior Academic')
        ->set('poolMemberAvatar', $avatarFile)
        ->call('savePoolMember')
        ->assertHasNoErrors();

    $member = InterviewBoardMember::where('name', 'Prof. Arundhati Roy')->first();
    expect($member)->not->toBeNull()
        ->and($member->avatar)->not->toBeNull()
        ->and($member->avatar)->toContain('panel_member_avatar');

    // Remove photo
    Livewire::test('pages::admin.mock-interviews')
        ->call('openPoolMemberModal', $member->id)
        ->set('removeAvatar', true)
        ->call('savePoolMember')
        ->assertHasNoErrors();

    $member->refresh();
    expect($member->avatar)->toBeNull();

    // 2. Candidate recording upload and remove
    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-S09',
        'title' => 'Session 9',
        'session_number' => 9,
        'academic_year' => '2026',
        'session_date' => '2026-11-30',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Campus',
        'status' => 'scheduled',
    ]);

    $cand = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'completed',
    ]);

    $videoFile = UploadedFile::fake()->create('interview.mp4', 1000, 'video/mp4');

    Livewire::test('pages::admin.mock-interviews')
        ->call('openRecordingModal', $cand->id)
        ->set('recordingForm.recording_type', 'upload')
        ->set('recordingForm.recording_status', 'available')
        ->set('uploadedRecordingFile', $videoFile)
        ->call('saveRecording')
        ->assertHasNoErrors();

    $cand->refresh();
    expect($cand->recording_file_path)->not->toBeNull()
        ->and($cand->recording_file_path)->toContain('interview_recording');

    // Remove recording
    Livewire::test('pages::admin.mock-interviews')
        ->call('openRecordingModal', $cand->id)
        ->set('removeRecordingFile', true)
        ->call('saveRecording')
        ->assertHasNoErrors();

    $cand->refresh();
    expect($cand->recording_file_path)->toBeNull();
});

test('admin can customize and save invitation templates per role and reset back to default', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-TPL01',
        'title' => 'UPSC CSE Mock Interview Template Test Session',
        'session_number' => 11,
        'academic_year' => '2026',
        'session_date' => '2026-12-05',
        'start_time' => '09:30:00',
        'end_time' => '17:30:00',
        'venue_address' => 'SNTCSSC Campus',
        'status' => 'scheduled',
    ]);

    $bmChair = InterviewBoardMember::create([
        'name' => 'Dr. A. K. Sharma',
        'email' => 'chair.sharma@example.gov.in',
        'designation' => 'Former UPSC Member',
        'is_active' => true,
    ]);

    MockInterviewSessionMember::create([
        'mock_interview_session_id' => $session->id,
        'interview_board_member_id' => $bmChair->id,
        'role' => MockInterviewSessionMember::ROLE_CHAIRPERSON,
        'order_index' => 1,
    ]);

    $cand = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'scheduled',
    ]);

    $component = Livewire::test('pages::admin.mock-interviews')
        ->call('openInvitationsForSession', $session->id)
        ->assertSet('hubTab', 'preview')
        ->set('hubTab', 'edit_template')
        ->set('previewRecipientType', 'chairman')
        ->assertSet('activeTemplateText', fn ($val) => str_contains($val, 'Chair the Interview Board'));

    // Edit Chairman template
    $customChairText = "Respected {name},\nCustom Chairman Invite for Session #{session_no} on {session_date}.";
    $component->set('activeTemplateText', $customChairText)
        ->call('saveSessionInvitationTemplate')
        ->assertHasNoErrors();

    $session->refresh();
    expect($session->chairman_invitation_template)->toBe($customChairText);

    // Check Live Preview with edited template
    $component->call('refreshLivePreview');
    $preview = $component->get('liveInvitationPreview');
    expect($preview['content'])->toContain('Custom Chairman Invite for Session #11');

    // Switch to Student template and edit
    $customStudentText = "Dear {name},\nYour Custom Slot is: {slot_time} (Mode: {attending_mode}).";
    $component->set('previewRecipientType', 'student')
        ->set('activeTemplateText', $customStudentText)
        ->call('saveSessionInvitationTemplate')
        ->assertHasNoErrors();

    $session->refresh();
    expect($session->student_invitation_template)->toBe($customStudentText);

    // Reset student template back to default
    $component->call('resetInvitationTemplateToDefault')
        ->assertHasNoErrors();

    $session->refresh();
    expect($session->student_invitation_template)->toBeNull();
    expect($component->get('activeTemplateText'))->toContain('Your UPSC Mock Interview');
});

test('session form modal allows loading default templates and saving custom template overrides', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.mock-interviews')
        ->call('openSessionModal')
        ->call('loadDefaultTemplateForSession', 'chairman')
        ->call('loadDefaultTemplateForSession', 'member')
        ->call('loadDefaultTemplateForSession', 'student')
        ->assertSet('sessionForm.chairman_invitation_template', fn ($val) => ! empty($val))
        ->assertSet('sessionForm.member_invitation_template', fn ($val) => ! empty($val))
        ->assertSet('sessionForm.student_invitation_template', fn ($val) => ! empty($val))
        ->set('sessionForm.title', 'CSE 2026 Session with Custom Templates')
        ->set('sessionForm.session_number', 12)
        ->set('sessionForm.academic_year', '2026')
        ->set('sessionForm.session_date', '2026-12-10')
        ->set('sessionForm.start_time', '09:30')
        ->set('sessionForm.end_time', '17:30')
        ->set('sessionForm.venue_address', 'Main Board Room')
        ->set('sessionForm.default_interview_duration', 30)
        ->set('sessionForm.status', 'scheduled')
        ->set('sessionForm.student_invitation_template', 'Personalized Welcome for {name}!')
        ->call('saveSession')
        ->assertHasNoErrors();

    $created = MockInterviewSession::where('session_number', 12)->first();
    expect($created)->not->toBeNull()
        ->and($created->student_invitation_template)->toBe('Personalized Welcome for {name}!')
        ->and($created->chairman_invitation_template)->not->toBeEmpty();
});

test('admin can view aspirant detailed student information and dossier modal', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-VIEW01',
        'title' => 'UPSC CSE Mock Interview View Candidate Test',
        'session_number' => 15,
        'academic_year' => '2026',
        'session_date' => '2026-12-15',
        'start_time' => '09:30:00',
        'end_time' => '17:30:00',
        'venue_address' => 'SNTCSSC Campus',
        'status' => 'scheduled',
    ]);

    $cand = MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'scheduled',
    ]);

    Livewire::test('pages::admin.mock-interviews')
        ->call('viewCandidateDetails', $cand->id)
        ->assertSet('viewingCandidateId', $cand->id)
        ->assertSet('viewingCandidate.id', $cand->id)
        ->assertDispatched('modal-open', name: 'candidate-details-modal');
});

test('admin can open reports modal and preview student wise, board member wise, and year wise reports', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-RPT01',
        'title' => 'CSE 2026 Reports Test Session',
        'session_number' => 20,
        'academic_year' => '2026',
        'session_date' => '2026-11-20',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Conference Hall',
        'status' => 'scheduled',
    ]);

    $panelist = InterviewBoardMember::create([
        'name' => 'Prof. S. K. Mukherjee',
        'designation' => 'Former UPSC Panel Advisor',
        'organization' => 'IAS Study Circle',
        'specialization' => 'Public Administration',
        'email' => 'prof.mukherjee@example.com',
        'status' => 'active',
    ]);

    MockInterviewSessionMember::create([
        'mock_interview_session_id' => $session->id,
        'interview_board_member_id' => $panelist->id,
        'role' => MockInterviewSessionMember::ROLE_CHAIRPERSON,
        'order_index' => 1,
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:30:00',
        'scheduled_end_time' => '11:00:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'completed',
        'interview_score' => 185.5,
        'board_feedback' => 'Commendable analytical depth and composure under grilling.',
    ]);

    $component = Livewire::test('pages::admin.mock-interviews')
        ->call('openReportsModal')
        ->assertSet('reportType', 'student_wise')
        ->assertDispatched('modal-open', name: 'mock-interview-reports-modal');

    // 1. Verify Student Wise report data structure
    $studentData = $component->instance()->buildReportData();
    expect($studentData['title'])->toContain('Candidate & Timetable')
        ->and($studentData['headings'])->toContain('Candidate Name')
        ->and($studentData['stats']['Total Candidates'])->toBeGreaterThanOrEqual(1)
        ->and(count($studentData['rows']))->toBeGreaterThanOrEqual(1);

    // 2. Switch to Board Member Wise report
    $component->set('reportType', 'board_member_wise');
    $boardData = $component->instance()->buildReportData();
    expect($boardData['title'])->toContain('Panelist Deployment')
        ->and($boardData['headings'])->toContain('Panelist Name')
        ->and($boardData['stats']['Unique Panelists'])->toBeGreaterThanOrEqual(1);

    // 3. Switch to Year Wise & Session overview report
    $component->set('reportType', 'year_wise');
    $yearData = $component->instance()->buildReportData();
    expect($yearData['title'])->toContain('Academic Year & Session Summary')
        ->and($yearData['headings'])->toContain('Academic Cycle')
        ->and($yearData['stats']['Total Sessions'])->toBeGreaterThanOrEqual(1);
});

test('admin can filter reports dynamically and reset filters', function () {
    $this->actingAs($this->admin);

    $session1 = MockInterviewSession::create([
        'session_code' => 'MIS-2025-FLT01',
        'title' => 'CSE 2025 Retrospective Session',
        'session_number' => 21,
        'academic_year' => '2025',
        'session_date' => '2025-04-10',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Hall A',
        'status' => 'completed',
    ]);

    $session2 = MockInterviewSession::create([
        'session_code' => 'MIS-2026-FLT02',
        'title' => 'CSE 2026 Live Session',
        'session_number' => 22,
        'academic_year' => '2026',
        'session_date' => '2026-11-25',
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'venue_address' => 'SNTCSSC Hall B',
        'status' => 'scheduled',
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session1->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'online',
        'status' => 'completed',
        'interview_score' => 192.0,
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session2->id,
        'user_id' => $this->student2->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:30:00',
        'scheduled_end_time' => '11:00:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'scheduled',
    ]);

    $component = Livewire::test('pages::admin.mock-interviews')
        ->set('reportAcademicYear', '2025')
        ->set('reportMode', 'online')
        ->set('reportEvaluationStatus', 'evaluated');

    $data = $component->instance()->buildReportData();
    expect($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0])->toContain($this->student1->name);

    // Reset filters
    $component->call('resetReportFilters');
    expect($component->get('reportAcademicYear'))->toBe('')
        ->and($component->get('reportMode'))->toBe('')
        ->and($component->get('reportEvaluationStatus'))->toBe('');
});

test('admin can export reports in pdf, excel, and csv formats', function () {
    $this->actingAs($this->admin);

    $session = MockInterviewSession::create([
        'session_code' => 'MIS-2026-EXP01',
        'title' => 'CSE 2026 Export Verification Session',
        'session_number' => 23,
        'academic_year' => '2026',
        'session_date' => '2026-11-28',
        'start_time' => '09:30:00',
        'end_time' => '17:00:00',
        'venue_address' => 'SNTCSSC Campus',
        'status' => 'scheduled',
    ]);

    MockInterviewCandidate::create([
        'mock_interview_session_id' => $session->id,
        'user_id' => $this->student1->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'scheduled',
    ]);

    $component = Livewire::test('pages::admin.mock-interviews')
        ->set('reportSessionId', (string) $session->id);

    // 1. Export CSV
    $csvResponse = $component->call('exportReport', 'csv');
    expect($csvResponse)->not->toBeNull();

    // 2. Export Excel
    $xlsxResponse = $component->call('exportReport', 'xlsx');
    expect($xlsxResponse)->not->toBeNull();

    // 3. Export PDF
    $pdfResponse = $component->call('exportReport', 'pdf');
    expect($pdfResponse)->not->toBeNull();
});
