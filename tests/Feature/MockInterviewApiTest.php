<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\InterviewBoardMember;
use App\Models\MockInterviewCandidate;
use App\Models\MockInterviewSession;
use App\Models\MockInterviewSessionMember;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'API Admin User',
        'email' => 'api.admin@example.com',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->student1 = User::factory()->create([
        'name' => 'Arjun Roy',
        'email' => 'arjun.roy@example.com',
        'student_id' => 'STU-API-001',
    ]);
    $this->student1->assignRole('Student');

    $this->student2 = User::factory()->create([
        'name' => 'Pooja Sen',
        'email' => 'pooja.sen@example.com',
        'student_id' => 'STU-API-002',
    ]);
    $this->student2->assignRole('Student');

    $this->apiKeyService = app(ApiKeyService::class);
    $keyData = $this->apiKeyService->generateKey([
        'name' => 'Mock Interview Full Integration Key',
        'type' => 'backend_service',
        'environment' => 'sandbox',
        'rate_limit_per_minute' => 300,
        'scopes' => ['*'],
        'description' => 'Test key with full mock interview permissions',
    ], $this->admin);

    $this->plainKey = $keyData['plainTextKey'];

    // Read-only key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Mock Interview Read-Only Key',
        'type' => 'backend_service',
        'environment' => 'sandbox',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:mock_interviews'],
        'description' => 'Read only key',
    ], $this->admin);

    $this->plainReadKey = $readKeyData['plainTextKey'];

    // Setup academic batch and course
    $this->batch = Batch::create([
        'name' => 'UPSC CSE 2026 Batch',
        'code' => 'BATCH-2026',
        'academic_year' => '2026',
        'status' => 'active',
    ]);

    $this->course = Course::create([
        'name' => 'Interview Guidance Programme',
        'code' => 'IGP-2026',
        'status' => 'active',
    ]);

    // Setup a session
    $this->session = MockInterviewSession::create([
        'title' => 'UPSC CSE Mock Interview Session 1',
        'session_number' => 1,
        'session_code' => 'MIS-2026-S01',
        'academic_year' => '2026',
        'session_date' => now()->addDays(5)->format('Y-m-d'),
        'start_time' => '10:00:00',
        'end_time' => '17:00:00',
        'venue_address' => 'SNTCSSC Administrative Complex, Salt Lake, Kolkata',
        'status' => 'scheduled',
        'instructions' => 'Bring original certificates and DAF-II copy.',
        'created_by' => $this->admin->id,
    ]);

    // Setup board member
    $this->boardMember = InterviewBoardMember::create([
        'name' => 'Ambassador K. R. Narayan (Retd. IFS)',
        'designation' => 'Former Ambassador & Diplomat',
        'email' => 'ambassador.krn@example.com',
        'phone' => '+919876543210',
        'is_active' => true,
    ]);

    $this->sessionMember = MockInterviewSessionMember::create([
        'mock_interview_session_id' => $this->session->id,
        'interview_board_member_id' => $this->boardMember->id,
        'role' => 'chairperson',
        'order_index' => 1,
    ]);

    // Setup candidate 1
    $this->candidate1 = MockInterviewCandidate::create([
        'mock_interview_session_id' => $this->session->id,
        'user_id' => $this->student1->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00:00',
        'scheduled_end_time' => '10:30:00',
        'duration_minutes' => 30,
        'attending_mode' => 'online',
        'platform' => 'Zoom',
        'meeting_link' => 'https://zoom.us/j/1234567890',
        'meeting_password' => 'Pass2026',
        'status' => 'scheduled',
        'recording_type' => 'url',
        'recording_url' => 'https://zoom.us/rec/share/candidate1-recording',
        'recording_status' => 'available',
        'interview_score' => 195.0,
        'board_feedback' => 'Good international affairs grasp.',
    ]);

    // Setup candidate 2
    $this->candidate2 = MockInterviewCandidate::create([
        'mock_interview_session_id' => $this->session->id,
        'user_id' => $this->student2->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'sequence_number' => 2,
        'scheduled_start_time' => '10:30:00',
        'scheduled_end_time' => '11:00:00',
        'duration_minutes' => 30,
        'attending_mode' => 'offline',
        'status' => 'scheduled',
        'recording_type' => 'url',
        'recording_url' => 'https://zoom.us/rec/share/candidate2-secret-recording',
        'recording_status' => 'available',
        'interview_score' => 180.0,
        'board_feedback' => 'Clear ethics answers.',
    ]);
});

test('developer console & docs page includes mock interviews endpoints, presets, and guide modal', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->assertSee('API Developer Portal & Console')
        ->assertSee('/api/v1/mock-interviews/sessions')
        ->assertSee('/api/v1/mock-interviews/candidates/{identifier}/evaluate')
        ->assertSee('/api/v1/mock-interviews/candidates/{identifier}/recording')
        ->assertSee('/api/v1/mock-interviews/reports')
        ->assertSee('/api/v1/mock-interviews/analytics')
        ->assertSee('/api/v1/student/mock-interviews')
        ->call('openGuide', 'mock_interviews')
        ->assertSet('showGuideModal', true)
        ->assertSee('UPSC Mock Interview Management System REST API')
        ->call('selectPreset', 'GET', '/api/v1/mock-interviews/sessions')
        ->assertSet('sandboxMethod', 'GET')
        ->assertSet('sandboxEndpoint', '/api/v1/mock-interviews/sessions')
        ->call('selectPreset', 'POST', '/api/v1/mock-interviews/candidates/1/evaluate')
        ->assertSet('sandboxMethod', 'POST')
        ->assertSet('sandboxEndpoint', '/api/v1/mock-interviews/candidates/1/evaluate')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});

test('api authentication and scope authorization for mock interview endpoints', function () {
    // Unauthenticated request should fail with 401
    $response = $this->getJson('/api/v1/mock-interviews/sessions');
    $response->assertUnauthorized();

    // Read request with full key succeeds
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/sessions');
    $response->assertOk()
        ->assertJsonPath('success', true);

    // Write action with read-only key fails with 403
    $response = $this->withHeader('X-API-Key', $this->plainReadKey)
        ->postJson('/api/v1/mock-interviews/sessions', [
            'title' => 'Unauthorized Session Attempt',
            'academic_year' => '2026',
            'session_number' => 99,
            'session_date' => '2026-12-01',
            'start_time' => '10:00:00',
            'end_time' => '17:00:00',
            'venue_address' => 'SNTCSSC Campus',
        ]);
    $response->assertForbidden();
});

test('sessions crud endpoints work with audit logging and soft deletes', function () {
    // List sessions
    $listResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/sessions?academic_year=2026');
    $listResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');

    // Create session
    $createResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/mock-interviews/sessions', [
            'title' => 'UPSC CSE Mock Interview Session 2',
            'session_number' => 2,
            'academic_year' => '2026',
            'session_date' => '2026-11-28',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'venue_address' => 'SNTCSSC Auditorium Board Room 2',
            'instructions' => 'Strict formals required.',
            'status' => 'draft',
        ]);
    $createResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'UPSC CSE Mock Interview Session 2');

    $newSessionId = $createResponse->json('data.id');

    // Show session
    $showResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/mock-interviews/sessions/{$newSessionId}");
    $showResponse->assertOk()
        ->assertJsonPath('data.title', 'UPSC CSE Mock Interview Session 2');

    // Update session
    $updateResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/mock-interviews/sessions/{$newSessionId}", [
            'status' => 'scheduled',
            'remarks' => 'Rescheduled after board confirmation',
        ]);
    $updateResponse->assertOk()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.remarks', 'Rescheduled after board confirmation');

    // Delete session (soft delete)
    $deleteResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/mock-interviews/sessions/{$newSessionId}");
    $deleteResponse->assertOk()
        ->assertJsonPath('success', true);

    expect(MockInterviewSession::find($newSessionId))->toBeNull()
        ->and(MockInterviewSession::withTrashed()->find($newSessionId))->not->toBeNull();
});

test('board members master catalog and session member assignments', function () {
    // List board members
    $listResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/board-members');
    $listResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');

    // Create board member
    $createResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/mock-interviews/board-members', [
            'name' => 'Prof. S. Mukherjee',
            'designation' => 'Former Vice-Chancellor, Calcutta University',
            'email' => 'smukherjee@example.com',
            'phone' => '+919831122334',
            'status' => 'active',
        ]);
    $createResponse->assertCreated()
        ->assertJsonPath('data.name', 'Prof. S. Mukherjee');

    $newMemberId = $createResponse->json('data.id');

    // Show board member
    $showResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/mock-interviews/board-members/{$newMemberId}");
    $showResponse->assertOk()
        ->assertJsonPath('data.designation', 'Former Vice-Chancellor, Calcutta University');

    // Update board member
    $updateResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/mock-interviews/board-members/{$newMemberId}", [
            'phone' => '+919830001122',
        ]);
    $updateResponse->assertOk()
        ->assertJsonPath('data.phone', '+919830001122');

    // Assign to session
    $assignResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/mock-interviews/sessions/{$this->session->id}/members", [
            'interview_board_member_id' => $newMemberId,
            'role' => 'member',
            'order_index' => 2,
        ]);
    $assignResponse->assertCreated()
        ->assertJsonPath('success', true);

    $sessionMemberId = $assignResponse->json('data.id');

    // Remove from session
    $removeResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/mock-interviews/sessions/{$this->session->id}/members/{$sessionMemberId}");
    $removeResponse->assertOk()
        ->assertJsonPath('success', true);

    // Delete board member from pool
    $deleteResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/mock-interviews/board-members/{$newMemberId}");
    $deleteResponse->assertOk()
        ->assertJsonPath('success', true);
});

test('candidate slot management, evaluation, and recording dispatches', function () {
    // Show candidate
    $showResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/mock-interviews/candidates/{$this->candidate1->id}");
    $showResponse->assertOk()
        ->assertJsonPath('data.attending_mode', 'online')
        ->assertJsonPath('data.interview_score', 195);

    // Update candidate timing & mode
    $updateResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/mock-interviews/candidates/{$this->candidate1->id}", [
            'attending_mode' => 'online',
            'platform' => 'Google Meet',
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
            'scheduled_start_time' => '10:15:00',
            'scheduled_end_time' => '10:45:00',
        ]);
    $updateResponse->assertOk()
        ->assertJsonPath('data.platform', 'Google Meet')
        ->assertJsonPath('data.scheduled_start_time', '10:15:00');

    // Evaluate candidate (out of 275)
    $evalResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/mock-interviews/candidates/{$this->candidate1->id}/evaluate", [
            'interview_score' => 205.0,
            'board_feedback' => 'Exceptional maturity in responding to socio-economic scenarios.',
            'status' => 'completed',
        ]);
    $evalResponse->assertOk()
        ->assertJsonPath('data.interview_score', 205)
        ->assertJsonPath('data.status', 'completed');

    expect($this->candidate1->fresh()->interview_score)->toBe(205.0);

    // Update recording URL
    $recordResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/mock-interviews/candidates/{$this->candidate1->id}/recording", [
            'recording_type' => 'url',
            'recording_url' => 'https://drive.google.com/file/d/sample-mock-video/view',
            'recording_password' => 'DriveSecret2026',
            'recording_status' => 'available',
        ]);
    $recordResponse->assertOk()
        ->assertJsonPath('data.recording_url', 'https://drive.google.com/file/d/sample-mock-video/view');

    // Dispatch recording to student
    $dispatchResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/mock-interviews/candidates/{$this->candidate1->id}/dispatch-recording", [
            'channels' => ['email', 'sms'],
        ]);
    $dispatchResponse->assertOk()
        ->assertJsonPath('success', true);
});

test('auto slots and personalized invitations endpoints', function () {
    // Auto slots recalculation
    $slotsResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/mock-interviews/sessions/{$this->session->id}/auto-slots", [
            'start_time' => '10:00',
            'duration_minutes' => 30,
            'buffer_minutes' => 5,
        ]);
    $slotsResponse->assertOk()
        ->assertJsonPath('success', true);

    // Get templates
    $getTemplatesResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/mock-interviews/sessions/{$this->session->id}/templates");
    $getTemplatesResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['chairman_template', 'member_template', 'student_template', 'available_placeholders']]);

    // Update templates
    $updateTemplatesResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/mock-interviews/sessions/{$this->session->id}/templates", [
            'chairman_template' => 'Customized Chairman invitation for session #{session_no}.',
            'member_template' => 'Customized Member invitation for session #{session_no}.',
            'student_template' => 'Dear {student_name}, please be ready at {start_time}.',
        ]);
    $updateTemplatesResponse->assertOk()
        ->assertJsonPath('success', true);

    // Send personalized invitations
    $inviteResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/mock-interviews/sessions/{$this->session->id}/invite", [
            'channels' => ['email', 'sms'],
            'recipient_type' => 'all',
        ]);
    $inviteResponse->assertOk()
        ->assertJsonPath('success', true);
});

test('reports and analytics endpoints return formatted datasets and metrics', function () {
    // Reports - student_wise
    $reportStudent = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/reports?perspective=student_wise&academic_year=2026');
    $reportStudent->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.perspective', 'student_wise')
        ->assertJsonCount(2, 'data.rows');

    // Reports - board_member_wise
    $reportMember = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/reports?perspective=board_member_wise&academic_year=2026');
    $reportMember->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.perspective', 'board_member_wise');

    // Reports - year_wise
    $reportYear = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/reports?perspective=year_wise');
    $reportYear->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.perspective', 'year_wise');

    // Analytics endpoint
    $analytics = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/analytics?academic_year=2026');
    $analytics->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'total_sessions',
                'total_candidates',
                'evaluated_count',
                'average_score',
                'turnout_rate',
            ],
        ]);

    // Dashboard executive telemetry endpoint
    $dashboard = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/mock-interviews/dashboard?academic_year=2026');
    $dashboard->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'summary' => [
                    'total_sessions',
                    'scheduled_sessions',
                    'completed_sessions',
                    'total_candidates',
                    'evaluated_candidates',
                    'average_marks',
                    'turnout_rate',
                ],
                'mode_breakdown' => [
                    'in_person_offline',
                    'remote_online',
                ],
                'recent_sessions',
                'upcoming_interviews',
            ],
        ]);
});

test('student portal endpoint enforces peer recording privacy isolation', function () {
    // Acting as Student 1
    $response = $this->actingAs($this->student1, 'sanctum')
        ->getJson('/api/v1/student/mock-interviews');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $data = $response->json('data');
    expect($data)->not->toBeEmpty();

    $sessionData = $data[0];
    // My interview
    expect($sessionData['my_interview']['recording_url'])->toBe('https://zoom.us/rec/share/candidate1-recording');

    // Peer roster
    $roster = collect($sessionData['session_order_roster']);
    $peerRecord = $roster->firstWhere('candidate_name', $this->student2->name);
    expect($peerRecord)->not->toBeNull();
    // Timing and order are visible for queue awareness
    expect($peerRecord['sequence_number'])->toBe(2);
    // CRITICAL: Peer recording URL MUST be nullified!
    expect($peerRecord['recording_url'])->toBeNull();
    expect($peerRecord['recording_status'])->toBeNull();
});
