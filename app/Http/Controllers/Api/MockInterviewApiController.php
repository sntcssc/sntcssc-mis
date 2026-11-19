<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InterviewBoardMember;
use App\Models\MockInterviewCandidate;
use App\Models\MockInterviewSession;
use App\Models\MockInterviewSessionMember;
use App\Services\AuditLogService;
use App\Services\MockInterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MockInterviewApiController extends Controller
{
    public function __construct(
        protected MockInterviewService $mockInterviewService
    ) {}

    /**
     * Real-time executive dashboard telemetry and operational overview.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $year = $request->input('academic_year');

        $sessionQuery = MockInterviewSession::query()
            ->when($year, fn ($q) => $q->where('academic_year', $year));

        $candidateQuery = MockInterviewCandidate::query()
            ->when($year, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->where('academic_year', $year)));

        $totalSessions = (clone $sessionQuery)->count();
        $scheduledSessions = (clone $sessionQuery)->whereIn('status', ['scheduled', 'in_progress'])->count();
        $completedSessions = (clone $sessionQuery)->where('status', 'completed')->count();

        $totalCandidates = (clone $candidateQuery)->count();
        $completedInterviews = (clone $candidateQuery)->where('status', 'completed')->count();
        $scheduledInterviews = (clone $candidateQuery)->where('status', 'scheduled')->count();

        $evalCands = (clone $candidateQuery)->whereNotNull('interview_score');
        $evalCount = $evalCands->count();
        $avgScore = $evalCount > 0 ? round((float) $evalCands->avg('interview_score'), 1) : null;
        $maxScore = $evalCount > 0 ? (float) $evalCands->max('interview_score') : null;
        $minScore = $evalCount > 0 ? (float) $evalCands->min('interview_score') : null;

        $offlineCount = (clone $candidateQuery)->where('attending_mode', 'offline')->count();
        $onlineCount = (clone $candidateQuery)->where('attending_mode', 'online')->count();

        $recordingsCount = (clone $candidateQuery)->where(function ($q) {
            $q->whereNotNull('recording_url')->orWhereNotNull('recording_file_path');
        })->count();

        $totalPanelists = InterviewBoardMember::count();
        $activePanelists = InterviewBoardMember::where('status', 'active')->count();

        $recentSessions = (clone $sessionQuery)
            ->with(['sessionMembers.boardMember'])
            ->withCount('candidates')
            ->orderBy('session_date', 'desc')
            ->take(5)
            ->get()
            ->map(function ($s) {
                $chair = $s->sessionMembers->firstWhere('role', 'chairperson')?->boardMember?->name;

                return [
                    'id' => $s->id,
                    'session_code' => $s->session_code,
                    'title' => $s->title,
                    'session_date' => $s->formatted_date,
                    'timing' => "{$s->start_time} - {$s->end_time}",
                    'venue' => $s->venue_address,
                    'status' => $s->status,
                    'candidates_count' => $s->candidates_count,
                    'chairperson' => $chair ?: 'Not Assigned',
                ];
            });

        $upcomingInterviews = (clone $candidateQuery)
            ->with(['user', 'session'])
            ->whereIn('status', ['scheduled', 'interviewing'])
            ->orderBy('scheduled_start_time', 'asc')
            ->take(5)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'candidate_name' => $c->user?->name,
                'student_id' => $c->user?->student_id,
                'session_code' => $c->session?->session_code,
                'scheduled_time' => $c->formatted_slot_time,
                'attending_mode' => $c->attending_mode,
                'platform' => $c->platform,
                'status' => $c->status,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_sessions' => $totalSessions,
                    'scheduled_sessions' => $scheduledSessions,
                    'completed_sessions' => $completedSessions,
                    'total_candidates' => $totalCandidates,
                    'completed_interviews' => $completedInterviews,
                    'scheduled_interviews' => $scheduledInterviews,
                    'evaluated_candidates' => $evalCount,
                    'average_marks' => $avgScore,
                    'highest_marks' => $maxScore,
                    'lowest_marks' => $minScore,
                    'recordings_available' => $recordingsCount,
                    'active_panel_experts' => $activePanelists,
                    'total_panelists' => $totalPanelists,
                    'turnout_rate' => $totalCandidates > 0 ? round(($completedInterviews / $totalCandidates) * 100, 1) : 0.0,
                ],
                'mode_breakdown' => [
                    'in_person_offline' => $offlineCount,
                    'remote_online' => $onlineCount,
                ],
                'recent_sessions' => $recentSessions,
                'upcoming_interviews' => $upcomingInterviews,
            ],
        ]);
    }

    /**
     * Get paginated list of mock interview sessions with filters.
     */
    public function sessions(Request $request): JsonResponse
    {
        $query = MockInterviewSession::query()
            ->with(['sessionMembers.boardMember', 'candidates.user']);

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('year')) {
            $query->where('academic_year', $request->input('year'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('session_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('session_date', '<=', $request->input('date_to'));
        }

        $sessions = $query->orderBy('session_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $sessions->items(),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    /**
     * Create a new mock interview session.
     */
    public function storeSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'session_number' => 'nullable|integer|min:1',
            'academic_year' => 'nullable|string|max:16',
            'session_date' => 'required|date',
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'reporting_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'venue_address' => 'required|string',
            'room_number' => 'nullable|string|max:64',
            'default_interview_duration' => 'nullable|integer|min:10|max:120',
            'default_buffer_duration' => 'nullable|integer|min:0|max:60',
            'instructions' => 'nullable|string',
            'remarks' => 'nullable|string',
            'status' => 'nullable|in:draft,scheduled,in_progress,completed,cancelled',
        ]);

        try {
            $session = DB::transaction(function () use ($validated) {
                return MockInterviewSession::create(array_merge($validated, [
                    'session_code' => MockInterviewSession::generateNextSessionCode($validated['academic_year'] ?? date('Y')),
                ]));
            });

            AuditLogService::log(
                event: 'mock_interview_session_created',
                description: "Created Mock Interview Session: {$session->title} ({$session->session_code})",
                auditable: $session
            );

            return response()->json([
                'success' => true,
                'message' => 'Mock interview session created successfully.',
                'data' => $session->load(['sessionMembers.boardMember', 'candidates.user']),
            ], Response::HTTP_CREATED);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create mock interview session: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get details of a single mock interview session.
     */
    public function showSession(int $id): JsonResponse
    {
        $session = MockInterviewSession::with([
            'sessionMembers.boardMember',
            'candidates.user',
            'candidates.batch',
            'candidates.course',
            'invitationLogs' => fn ($q) => $q->latest()->limit(50),
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }

    /**
     * Update an existing mock interview session.
     */
    public function updateSession(Request $request, int $id): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'session_number' => 'nullable|integer|min:1',
            'academic_year' => 'nullable|string|max:16',
            'session_date' => 'sometimes|required|date',
            'start_time' => 'sometimes|required',
            'end_time' => 'sometimes|required',
            'reporting_time' => 'nullable',
            'venue_address' => 'sometimes|required|string',
            'room_number' => 'nullable|string|max:64',
            'default_interview_duration' => 'nullable|integer|min:10|max:120',
            'default_buffer_duration' => 'nullable|integer|min:0|max:60',
            'instructions' => 'nullable|string',
            'remarks' => 'nullable|string',
            'status' => 'nullable|in:draft,scheduled,in_progress,completed,cancelled',
            'chairman_invitation_template' => 'nullable|string',
            'member_invitation_template' => 'nullable|string',
            'student_invitation_template' => 'nullable|string',
        ]);

        $session->update($validated);

        AuditLogService::log(
            event: 'mock_interview_session_updated',
            description: "Updated Mock Interview Session: {$session->title} ({$session->session_code})",
            auditable: $session
        );

        return response()->json([
            'success' => true,
            'message' => 'Mock interview session updated successfully.',
            'data' => $session->fresh(['sessionMembers.boardMember', 'candidates.user']),
        ]);
    }

    /**
     * Delete (soft-delete) a mock interview session.
     */
    public function destroySession(int $id): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($id);
        $title = $session->title;
        $session->delete();

        AuditLogService::log(
            event: 'mock_interview_session_deleted',
            description: "Soft deleted Mock Interview Session: {$title}",
            auditable: $session
        );

        return response()->json([
            'success' => true,
            'message' => 'Mock interview session deleted successfully.',
        ]);
    }

    /**
     * Assign or update a board member in a session (supports 1 chairperson and members).
     */
    public function assignMember(Request $request, int $sessionId): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($sessionId);

        $validated = $request->validate([
            'interview_board_member_id' => 'required|exists:interview_board_members,id',
            'role' => 'required|in:chairperson,member',
            'order_index' => 'nullable|integer|min:1|max:10',
            'attendance_status' => 'nullable|in:invited,confirmed,attended,absent',
            'remarks' => 'nullable|string',
        ]);

        // If assigning as chairperson, ensure existing chairperson is downgraded or replaced
        if ($validated['role'] === MockInterviewSessionMember::ROLE_CHAIRPERSON) {
            $session->sessionMembers()
                ->where('role', MockInterviewSessionMember::ROLE_CHAIRPERSON)
                ->where('interview_board_member_id', '!=', $validated['interview_board_member_id'])
                ->update(['role' => MockInterviewSessionMember::ROLE_MEMBER]);
        }

        $sessionMember = MockInterviewSessionMember::updateOrCreate(
            [
                'mock_interview_session_id' => $session->id,
                'interview_board_member_id' => $validated['interview_board_member_id'],
            ],
            [
                'role' => $validated['role'],
                'order_index' => $validated['order_index'] ?? ($validated['role'] === 'chairperson' ? 1 : 2),
                'attendance_status' => $validated['attendance_status'] ?? MockInterviewSessionMember::STATUS_CONFIRMED,
                'remarks' => $validated['remarks'] ?? null,
            ]
        );

        $status = $sessionMember->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK;

        return response()->json([
            'success' => true,
            'message' => 'Board member assigned to session successfully.',
            'data' => $sessionMember->load('boardMember'),
        ], $status);
    }

    /**
     * Remove a board member from a session.
     */
    public function removeMember(int $sessionId, int $memberId): JsonResponse
    {
        $sessionMember = MockInterviewSessionMember::where('mock_interview_session_id', $sessionId)
            ->where('interview_board_member_id', $memberId)
            ->firstOrFail();

        $sessionMember->delete();

        return response()->json([
            'success' => true,
            'message' => 'Board member removed from session successfully.',
        ]);
    }

    /**
     * Assign a student into a mock interview session.
     */
    public function assignCandidate(Request $request, int $sessionId): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($sessionId);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'batch_id' => 'nullable|exists:batches,id',
            'course_id' => 'nullable|exists:courses,id',
            'sequence_number' => 'nullable|integer|min:1',
            'scheduled_start_time' => 'required|date_format:H:i',
            'scheduled_end_time' => 'required|date_format:H:i|after:scheduled_start_time',
            'duration_minutes' => 'nullable|integer|min:5|max:120',
            'attending_mode' => 'required|in:offline,online',
            'venue_address' => 'nullable|string',
            'room_number' => 'nullable|string|max:64',
            'platform' => 'nullable|in:Zoom,Google Meet,Microsoft Teams,Cisco Webex Meetings,Others',
            'platform_other' => 'nullable|string|max:64',
            'meeting_link' => 'nullable|url',
            'meeting_id' => 'nullable|string|max:64',
            'meeting_password' => 'nullable|string|max:64',
            'candidate_instructions' => 'nullable|string',
            'status' => 'nullable|in:scheduled,reported,interviewing,completed,absent,rescheduled',
        ]);

        $nextSeq = $validated['sequence_number']
            ?? (($session->candidates()->max('sequence_number') ?: 0) + 1);

        $candidate = MockInterviewCandidate::updateOrCreate(
            [
                'mock_interview_session_id' => $session->id,
                'user_id' => $validated['user_id'],
            ],
            array_merge($validated, [
                'sequence_number' => $nextSeq,
                'duration_minutes' => $validated['duration_minutes'] ?? $session->default_interview_duration ?? 30,
                'venue_address' => $validated['venue_address'] ?? $session->venue_address,
            ])
        );

        return response()->json([
            'success' => true,
            'message' => 'Candidate student added into session successfully.',
            'data' => $candidate->load(['user', 'batch', 'course']),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update candidate details or status.
     */
    public function updateCandidate(Request $request, int $candidateId): JsonResponse
    {
        $candidate = MockInterviewCandidate::findOrFail($candidateId);

        $validated = $request->validate([
            'sequence_number' => 'nullable|integer|min:1',
            'scheduled_start_time' => 'sometimes|required',
            'scheduled_end_time' => 'sometimes|required',
            'duration_minutes' => 'nullable|integer|min:5|max:120',
            'attending_mode' => 'sometimes|required|in:offline,online',
            'venue_address' => 'nullable|string',
            'room_number' => 'nullable|string|max:64',
            'platform' => 'nullable|in:Zoom,Google Meet,Microsoft Teams,Cisco Webex Meetings,Others',
            'platform_other' => 'nullable|string|max:64',
            'meeting_link' => 'nullable|url',
            'meeting_id' => 'nullable|string|max:64',
            'meeting_password' => 'nullable|string|max:64',
            'candidate_instructions' => 'nullable|string',
            'status' => 'nullable|in:scheduled,reported,interviewing,completed,absent,rescheduled',
            'interview_score' => 'nullable|numeric|min:0|max:275',
            'board_feedback' => 'nullable|string',
        ]);

        $candidate->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Candidate details updated successfully.',
            'data' => $candidate->fresh(['user', 'batch', 'course']),
        ]);
    }

    /**
     * Remove candidate from session.
     */
    public function removeCandidate(int $candidateId): JsonResponse
    {
        $candidate = MockInterviewCandidate::findOrFail($candidateId);
        $candidate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Candidate removed from session successfully.',
        ]);
    }

    /**
     * Attach / update video recording for a candidate.
     */
    public function updateRecording(Request $request, int $candidateId): JsonResponse
    {
        $candidate = MockInterviewCandidate::findOrFail($candidateId);

        $validated = $request->validate([
            'recording_type' => 'required|in:url,upload',
            'recording_url' => 'nullable|url',
            'recording_password' => 'nullable|string|max:64',
            'recording_notes' => 'nullable|string',
            'recording_status' => 'required|in:not_available,processing,available',
        ]);

        $candidate->update(array_merge($validated, [
            'recording_uploaded_at' => now(),
        ]));

        AuditLogService::log(
            event: 'mock_interview_recording_updated',
            description: "Updated interview recording for student: {$candidate->user->name}",
            auditable: $candidate
        );

        return response()->json([
            'success' => true,
            'message' => 'Recording attached successfully.',
            'data' => $candidate->fresh(['user']),
        ]);
    }

    /**
     * Auto generate sequential time slots for all candidates in session.
     */
    public function autoSlots(Request $request, int $sessionId): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($sessionId);

        $validated = $request->validate([
            'start_time' => 'nullable|date_format:H:i',
            'duration_minutes' => 'nullable|integer|min:10|max:120',
            'buffer_minutes' => 'nullable|integer|min:0|max:60',
        ]);

        $count = $this->mockInterviewService->autoGenerateTimeSlots(
            session: $session,
            startTime: $validated['start_time'] ?? null,
            durationMinutes: $validated['duration_minutes'] ?? null,
            bufferMinutes: $validated['buffer_minutes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => "Successfully auto-generated time slots for {$count} candidate(s).",
            'data' => $session->fresh(['candidates.user']),
        ]);
    }

    /**
     * Dispatch personalized invitations to board members and/or candidates.
     */
    public function dispatchInvitations(Request $request, int $sessionId): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($sessionId);

        $targetInput = $request->input('target') ?: $request->input('recipient_type', 'all');
        $request->merge(['target' => $targetInput]);

        $validated = $request->validate([
            'target' => 'required|in:all,board_members,candidates,specific_candidate,specific_member',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:email,sms,whatsapp,telegram,in_app',
            'candidate_id' => 'nullable|exists:mock_interview_candidates,id',
            'session_member_id' => 'nullable|exists:mock_interview_session_members,id',
            'custom_template' => 'nullable|string',
        ]);

        $dispatchedCount = 0;
        $channels = $validated['channels'];
        $target = $validated['target'];

        if (in_array($target, ['all', 'board_members'])) {
            foreach ($session->sessionMembers as $member) {
                $this->mockInterviewService->sendMemberInvitation($member, $channels, $validated['custom_template'] ?? null);
                $dispatchedCount++;
            }
        }

        if (in_array($target, ['all', 'candidates'])) {
            foreach ($session->candidates as $candidate) {
                $this->mockInterviewService->sendCandidateInvitation($candidate, $channels, $validated['custom_template'] ?? null);
                $dispatchedCount++;
            }
        }

        if ($target === 'specific_candidate' && ! empty($validated['candidate_id'])) {
            $candidate = MockInterviewCandidate::findOrFail($validated['candidate_id']);
            $this->mockInterviewService->sendCandidateInvitation($candidate, $channels, $validated['custom_template'] ?? null);
            $dispatchedCount++;
        }

        if ($target === 'specific_member' && ! empty($validated['session_member_id'])) {
            $member = MockInterviewSessionMember::findOrFail($validated['session_member_id']);
            $this->mockInterviewService->sendMemberInvitation($member, $channels, $validated['custom_template'] ?? null);
            $dispatchedCount++;
        }

        return response()->json([
            'success' => true,
            'message' => "Successfully dispatched invitations to {$dispatchedCount} recipient(s).",
        ]);
    }

    /**
     * Dispatch video recording download link to candidate.
     */
    public function dispatchRecording(Request $request, int $candidateId): JsonResponse
    {
        $candidate = MockInterviewCandidate::with(['session', 'user'])->findOrFail($candidateId);

        $validated = $request->validate([
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:email,sms,whatsapp,telegram,in_app',
            'notes' => 'nullable|string',
        ]);

        $results = $this->mockInterviewService->sendRecordingToCandidate(
            candidate: $candidate,
            channels: $validated['channels'],
            customNotes: $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Recording download link dispatched successfully.',
            'results' => $results,
        ]);
    }

    /**
     * List board members pool.
     */
    public function boardMembers(Request $request): JsonResponse
    {
        $members = InterviewBoardMember::query()
            ->when($request->filled('q'), fn ($q) => $q->search($request->input('q')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $members->items(),
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'total' => $members->total(),
            ],
        ]);
    }

    /**
     * Create a board member in the master pool.
     */
    public function storeBoardMember(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'nullable|exists:users,id',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:32',
            'designation' => 'nullable|string|max:255',
            'organization' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $member = InterviewBoardMember::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Board member created successfully.',
            'data' => $member,
        ], Response::HTTP_CREATED);
    }

    /**
     * Show single board member with session assignment history.
     */
    public function showBoardMember(int $id): JsonResponse
    {
        $member = InterviewBoardMember::with([
            'sessionMembers.session',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $member,
        ]);
    }

    /**
     * Update board member profile.
     */
    public function updateBoardMember(Request $request, int $id): JsonResponse
    {
        $member = InterviewBoardMember::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'user_id' => 'nullable|exists:users,id',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:32',
            'designation' => 'nullable|string|max:255',
            'organization' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $member->update($validated);

        AuditLogService::log(
            event: 'mock_interview_board_member_updated',
            description: "Updated Board Panelist Profile: {$member->name}",
            auditable: $member
        );

        return response()->json([
            'success' => true,
            'message' => 'Board member updated successfully.',
            'data' => $member,
        ]);
    }

    /**
     * Soft delete board member.
     */
    public function destroyBoardMember(int $id): JsonResponse
    {
        $member = InterviewBoardMember::findOrFail($id);
        $name = $member->name;
        $member->delete();

        AuditLogService::log(
            event: 'mock_interview_board_member_deleted',
            description: "Soft deleted Board Panelist Profile: {$name}",
            auditable: $member
        );

        return response()->json([
            'success' => true,
            'message' => 'Board member deleted successfully.',
        ]);
    }

    /**
     * Show comprehensive candidate dossier and timetable details.
     */
    public function showCandidate(int $candidateId): JsonResponse
    {
        $candidate = MockInterviewCandidate::with([
            'session.sessionMembers.boardMember',
            'user.studentProfile',
            'user.studentOtherDetails',
            'batch',
            'course',
        ])->findOrFail($candidateId);

        $u = $candidate->user;
        $p = $u?->studentProfile;
        $o = $u?->studentOtherDetails;

        return response()->json([
            'success' => true,
            'data' => array_merge($candidate->toArray(), [
                'candidate' => $candidate,
                'student_profile' => [
                    'student_id' => $u?->student_id ?: $u?->email,
                    'name' => $u?->name,
                    'email' => $u?->email,
                    'phone' => $u?->phone,
                    'category' => $p?->category ?: 'General',
                    'upsc_roll_number' => $o?->upsc_roll_number ?: 'Pending',
                    'optional_subject' => $o?->optional_subject ?: 'General Studies',
                    'medium_of_exam' => $o?->medium_of_exam ?: 'English',
                    'attempts_count' => $o?->upsc_attempts_count ?? 1,
                    'daf_hobbies' => $o?->daf_hobbies ?: null,
                ],
            ]),
        ]);
    }

    /**
     * Record interview evaluation score (/275) and panel recommendations.
     */
    public function evaluateCandidate(Request $request, int $candidateId): JsonResponse
    {
        $candidate = MockInterviewCandidate::with('session', 'user')->findOrFail($candidateId);

        $validated = $request->validate([
            'interview_score' => 'required|numeric|min:0|max:275',
            'board_feedback' => 'nullable|string',
            'status' => 'nullable|in:completed,interviewing,reported,absent',
        ]);

        $candidate->update([
            'interview_score' => $validated['interview_score'],
            'board_feedback' => $validated['board_feedback'] ?? $candidate->board_feedback,
            'status' => $validated['status'] ?? 'completed',
        ]);

        AuditLogService::log(
            event: 'mock_interview_candidate_evaluated',
            description: "Recorded interview score ({$validated['interview_score']}/275) for candidate: {$candidate->user?->name}",
            auditable: $candidate
        );

        return response()->json([
            'success' => true,
            'message' => 'Interview evaluation score and remarks saved successfully.',
            'data' => $candidate->fresh(['session', 'user']),
        ]);
    }

    /**
     * Get multi-dimensional report JSON data (student_wise, board_member_wise, year_wise).
     */
    public function reports(Request $request): JsonResponse
    {
        $type = $request->input('perspective') ?: $request->input('type', 'student_wise');
        $academicYear = $request->input('academic_year');
        $sessionId = $request->input('session_id');
        $mode = $request->input('mode');
        $status = $request->input('status');
        $boardMemberId = $request->input('board_member_id');
        $evaluationStatus = $request->input('evaluation_status');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if ($type === 'board_member_wise') {
            $title = 'UPSC Mock Interview Panelist Deployment & Performance Report';
            $subtitle = 'Comprehensive analysis of board panel assignments, sessions conducted, and scoring metrics';

            $query = MockInterviewSessionMember::query()
                ->with(['boardMember', 'session.candidates'])
                ->when($academicYear, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->where('academic_year', $academicYear)))
                ->when($sessionId, fn ($q) => $q->where('mock_interview_session_id', $sessionId))
                ->when($boardMemberId, fn ($q) => $q->where('interview_board_member_id', $boardMemberId))
                ->when($dateFrom, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '>=', $dateFrom)))
                ->when($dateTo, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '<=', $dateTo)))
                ->orderBy('mock_interview_session_id', 'desc')
                ->orderBy('order_index', 'asc');

            $members = $query->get();

            $headings = [
                '#', 'Panelist Name', 'Designation & Org', 'Specialization',
                'Role in Session', 'Session Code', 'Session Date', 'Venue / Room',
                'Total Candidates', 'Evaluated', 'Avg Score (/275)', 'Highest Score', 'Lowest Score',
            ];

            $rows = $members->map(function ($sm, $idx) {
                $cands = $sm->session->candidates;
                $evalCands = $cands->whereNotNull('interview_score');
                $evalCount = $evalCands->count();

                return [
                    $idx + 1,
                    $sm->boardMember->name,
                    ($sm->boardMember->designation ?: 'Expert').($sm->boardMember->organization ? " ({$sm->boardMember->organization})" : ''),
                    $sm->boardMember->specialization ?: 'General Administration',
                    $sm->isChairperson() ? 'Chairperson' : 'Board Panelist',
                    $sm->session->session_code,
                    $sm->session->formatted_date,
                    $sm->session->room_number ? "{$sm->session->venue_address} ({$sm->session->room_number})" : $sm->session->venue_address,
                    $cands->count(),
                    $evalCount,
                    $evalCount > 0 ? number_format($evalCands->avg('interview_score'), 1) : '—',
                    $evalCount > 0 ? number_format($evalCands->max('interview_score'), 1) : '—',
                    $evalCount > 0 ? number_format($evalCands->min('interview_score'), 1) : '—',
                ];
            })->all();

            $stats = [
                'Total Deployments' => count($rows),
                'Unique Panelists' => $members->pluck('interview_board_member_id')->unique()->count(),
                'Sessions Chaired' => $members->where('role', MockInterviewSessionMember::ROLE_CHAIRPERSON)->count(),
                'Total Evaluated' => $members->sum(fn ($sm) => $sm->session->candidates->whereNotNull('interview_score')->count()),
            ];
        } elseif ($type === 'year_wise') {
            $title = 'UPSC Mock Interview Academic Year & Session Summary Report';
            $subtitle = 'Macro-level overview of interview sessions, candidate turnouts, evaluation rubrics, and recordings';

            $query = MockInterviewSession::query()
                ->with(['sessionMembers.boardMember', 'candidates'])
                ->when($academicYear, fn ($q) => $q->where('academic_year', $academicYear))
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($dateFrom, fn ($q) => $q->whereDate('session_date', '>=', $dateFrom))
                ->when($dateTo, fn ($q) => $q->whereDate('session_date', '<=', $dateTo))
                ->orderBy('academic_year', 'desc')
                ->orderBy('session_number', 'asc');

            $sessions = $query->get();

            $headings = [
                '#', 'Academic Cycle', 'Session Code', 'Session Title', 'Date & Hours',
                'Board Chairperson', 'Panel Size', 'Enrolled Candidates', 'In-Person (Offline)',
                'Remote (Online)', 'Completed Interviews', 'Evaluated', 'Avg Score (/275)',
                'Recordings Available', 'Session Status',
            ];

            $rows = $sessions->map(function ($s, $idx) {
                $evalCands = $s->candidates->whereNotNull('interview_score');
                $evalCount = $evalCands->count();

                return [
                    $idx + 1,
                    $s->academic_year,
                    $s->session_code,
                    $s->title,
                    "{$s->formatted_date} (".Carbon::parse($s->start_time)->format('h:i A').' - '.Carbon::parse($s->end_time)->format('h:i A').')',
                    $s->chairperson_name ?: 'Pending Nomination',
                    $s->sessionMembers->count().' Members',
                    $s->candidates->count(),
                    $s->candidates->where('attending_mode', 'offline')->count(),
                    $s->candidates->where('attending_mode', 'online')->count(),
                    $s->candidates->where('status', 'completed')->count(),
                    $evalCount,
                    $evalCount > 0 ? number_format($evalCands->avg('interview_score'), 1) : '—',
                    $s->candidates->filter(fn ($c) => $c->hasRecording())->count(),
                    ucfirst($s->status),
                ];
            })->all();

            $allCands = $sessions->flatMap->candidates;
            $evalAll = $allCands->whereNotNull('interview_score');
            $stats = [
                'Total Sessions' => count($rows),
                'Total Candidates' => $allCands->count(),
                'Completed' => $allCands->where('status', 'completed')->count(),
                'Overall Avg Score' => $evalAll->count() > 0 ? number_format($evalAll->avg('interview_score'), 1).' / 275' : '—',
                'Video Recordings' => $allCands->filter(fn ($c) => $c->hasRecording())->count(),
            ];
        } else {
            // student_wise
            $title = 'UPSC Mock Interview Candidate & Timetable Detailed Report';
            $subtitle = 'Granular performance register with interview slots, evaluation marks, feedback, and video recording status';

            $query = MockInterviewCandidate::query()
                ->with([
                    'session.sessionMembers.boardMember',
                    'user.studentProfile',
                    'user.studentOtherDetails',
                    'batch',
                    'course',
                ])
                ->when($academicYear, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->where('academic_year', $academicYear)))
                ->when($sessionId, fn ($q) => $q->where('mock_interview_session_id', $sessionId))
                ->when($mode, fn ($q) => $q->where('attending_mode', $mode))
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($evaluationStatus === 'evaluated', fn ($q) => $q->whereNotNull('interview_score'))
                ->when($evaluationStatus === 'pending', fn ($q) => $q->whereNull('interview_score'))
                ->when($dateFrom, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '>=', $dateFrom)))
                ->when($dateTo, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '<=', $dateTo)))
                ->orderBy('mock_interview_session_id', 'desc')
                ->orderBy('sequence_number', 'asc');

            $candidates = $query->get();

            $headings = [
                '#', 'Student ID', 'Candidate Name', 'UPSC Optional', 'Category',
                'Session Code', 'Session Date', 'Slot & Order', 'Mode', 'Status',
                'Score (/275)', 'Panel Feedback', 'Recording Status',
            ];

            $rows = $candidates->map(function ($c, $idx) {
                return [
                    $idx + 1,
                    $c->user?->student_id ?: ($c->user?->email ?? '—'),
                    $c->user?->name ?? 'Candidate',
                    $c->user?->studentOtherDetails?->optional_subject ?: '—',
                    $c->user?->studentProfile?->category ?: 'General',
                    $c->session->session_code,
                    $c->session->formatted_date,
                    "Slot: {$c->formatted_slot_time} (#{$c->sequence_number})",
                    $c->isOnline() ? 'Online Remote' : 'In-Person Offline',
                    ucfirst($c->status),
                    $c->interview_score !== null ? number_format($c->interview_score, 1) : 'Pending',
                    $c->board_feedback ? mb_substr($c->board_feedback, 0, 75).'...' : '—',
                    $c->hasRecording() ? 'Available' : 'Pending',
                ];
            })->all();

            $evalCands = $candidates->whereNotNull('interview_score');
            $stats = [
                'Total Candidates' => count($rows),
                'In-Person (Offline)' => $candidates->where('attending_mode', 'offline')->count(),
                'Remote (Online)' => $candidates->where('attending_mode', 'online')->count(),
                'Evaluated' => $evalCands->count(),
                'Average Marks' => $evalCands->count() > 0 ? number_format($evalCands->avg('interview_score'), 1).' / 275' : '—',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'title' => $title,
                'subtitle' => $subtitle,
                'report_type' => $type,
                'perspective' => $type,
                'headings' => $headings,
                'rows' => $rows,
                'stats' => $stats,
                'total_records' => count($rows),
            ],
        ]);
    }

    /**
     * Get high-level mock interview executive telemetry and analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        $year = $request->input('academic_year');

        $sessionQuery = MockInterviewSession::query()
            ->when($year, fn ($q) => $q->where('academic_year', $year));

        $candidateQuery = MockInterviewCandidate::query()
            ->when($year, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->where('academic_year', $year)));

        $totalSessions = (clone $sessionQuery)->count();
        $totalCandidates = (clone $candidateQuery)->count();
        $completedInterviews = (clone $candidateQuery)->where('status', 'completed')->count();
        $evalCands = (clone $candidateQuery)->whereNotNull('interview_score');
        $evalCount = $evalCands->count();
        $avgScore = $evalCount > 0 ? round((float) $evalCands->avg('interview_score'), 1) : null;
        $offlineCount = (clone $candidateQuery)->where('attending_mode', 'offline')->count();
        $onlineCount = (clone $candidateQuery)->where('attending_mode', 'online')->count();
        $recordingsCount = (clone $candidateQuery)->where(function ($q) {
            $q->whereNotNull('recording_url')->orWhereNotNull('recording_file_path');
        })->count();

        $activePanelists = InterviewBoardMember::where('status', 'active')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_sessions' => $totalSessions,
                'total_candidates' => $totalCandidates,
                'completed_interviews' => $completedInterviews,
                'evaluated_candidates' => $evalCount,
                'evaluated_count' => $evalCount,
                'average_score' => $avgScore,
                'average_score_out_of_275' => $avgScore,
                'total_recordings_available' => $recordingsCount,
                'active_panel_experts' => $activePanelists,
                'turnout_rate' => $totalCandidates > 0 ? round(($completedInterviews / $totalCandidates) * 100, 1) : 0.0,
                'mode_distribution' => [
                    'in_person_offline' => $offlineCount,
                    'remote_online' => $onlineCount,
                ],
            ],
        ]);
    }

    /**
     * Get personalized invitation templates for a session.
     */
    public function getTemplates(int $sessionId): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($sessionId);

        return response()->json([
            'success' => true,
            'data' => [
                'session_code' => $session->session_code,
                'session_title' => $session->title,
                'chairman_template' => $session->getChairmanInvitationTemplate(),
                'member_template' => $session->getMemberInvitationTemplate(),
                'student_template' => $session->getStudentInvitationTemplate(),
                'has_custom_chairman' => ! empty($session->chairman_invitation_template),
                'has_custom_member' => ! empty($session->member_invitation_template),
                'has_custom_student' => ! empty($session->student_invitation_template),
                'available_placeholders' => [
                    '{session_no}', '{session_code}', '{session_date}', '{start_time}', '{end_time}',
                    '{venue_address}', '{chairman_name}', '{member_name}', '{student_name}',
                    '{interview_mode}', '{duration_minutes}', '{instructions}', '{platform}',
                    '{meeting_link}', '{meeting_password}',
                ],
            ],
        ]);
    }

    /**
     * Update invitation templates for a session.
     */
    public function updateTemplates(Request $request, int $sessionId): JsonResponse
    {
        $session = MockInterviewSession::findOrFail($sessionId);

        $validated = $request->validate([
            'chairman_template' => 'nullable|string',
            'member_template' => 'nullable|string',
            'student_template' => 'nullable|string',
        ]);

        $session->update([
            'chairman_invitation_template' => $validated['chairman_template'] ?? null,
            'member_invitation_template' => $validated['member_template'] ?? null,
            'student_invitation_template' => $validated['student_template'] ?? null,
        ]);

        AuditLogService::log(
            event: 'mock_interview_template_updated',
            description: "Updated invitation templates for session: {$session->title} ({$session->session_code})",
            auditable: $session
        );

        return response()->json([
            'success' => true,
            'message' => 'Invitation templates updated successfully for session.',
            'data' => [
                'chairman_template' => $session->getChairmanInvitationTemplate(),
                'member_template' => $session->getMemberInvitationTemplate(),
                'student_template' => $session->getStudentInvitationTemplate(),
            ],
        ]);
    }

    /**
     * Endpoint for authenticated student to fetch their mock interview sessions and peer order of appearance.
     */
    public function studentSessions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $myCandidates = MockInterviewCandidate::with([
            'session.sessionMembers.boardMember',
            'batch',
            'course',
        ])->where('user_id', $user->id)
            ->latest('id')
            ->get();

        $data = $myCandidates->map(function ($myCand) use ($user) {
            $session = $myCand->session;

            // Session peer order of appearance roster — strictly hide other students' recordings!
            $roster = $session->candidates()
                ->with('user')
                ->orderBy('sequence_number', 'asc')
                ->get()
                ->map(function ($c) use ($user) {
                    $isSelf = ($c->user_id === $user->id);

                    return [
                        'sequence_number' => $c->sequence_number,
                        'candidate_name' => $isSelf ? "{$c->user->name} (You)" : $c->user->name,
                        'student_id' => $c->user->student_id,
                        'attending_mode' => $c->attending_mode,
                        'slot_time' => $c->formatted_slot_time,
                        'duration_minutes' => $c->duration_minutes,
                        'status' => $c->status,
                        'is_current_user' => $isSelf,
                        // Recording ONLY exposed for self, never for peers!
                        'recording_url' => $isSelf ? $c->getEffectiveRecordingUrl() : null,
                        'recording_status' => $isSelf ? $c->recording_status : null,
                    ];
                });

            return [
                'my_interview' => [
                    'id' => $myCand->id,
                    'sequence_number' => $myCand->sequence_number,
                    'session_title' => $session->title,
                    'session_code' => $session->session_code,
                    'session_date' => $session->formatted_date,
                    'slot_time' => $myCand->formatted_slot_time,
                    'attending_mode' => $myCand->attending_mode,
                    'venue_address' => $myCand->getEffectiveVenueAddress(),
                    'room_number' => $myCand->room_number ?: $session->room_number,
                    'platform' => $myCand->platform,
                    'meeting_link' => $myCand->meeting_link,
                    'meeting_id' => $myCand->meeting_id,
                    'meeting_password' => $myCand->meeting_password,
                    'instructions' => $myCand->candidate_instructions ?: $session->instructions,
                    'status' => $myCand->status,
                    'score' => $myCand->interview_score,
                    'feedback' => $myCand->board_feedback,
                    'recording_available' => $myCand->hasRecording(),
                    'recording_url' => $myCand->getEffectiveRecordingUrl(),
                    'recording_password' => $myCand->recording_password,
                    'recording_notes' => $myCand->recording_notes,
                ],
                'session_order_roster' => $roster,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
