<?php

use App\Concerns\RenamesUploads;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\InterviewBoardMember;
use App\Models\MockInterviewCandidate;
use App\Models\MockInterviewInvitationLog;
use App\Models\MockInterviewSession;
use App\Models\MockInterviewSessionMember;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MockInterviewService;
use App\Services\StudentImportExportService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('UPSC Mock Interview Management')] class extends Component {
    use RenamesUploads, WithFileUploads, WithPagination;

    #[Url(as: 'view')]
    public string $activeView = 'sessions'; // sessions, session_detail, board_members, recordings_vault

    #[Url(as: 'session')]
    public ?int $selectedSessionId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'year')]
    public string $yearFilter = '';

    #[Url(as: 'mode')]
    public string $modeFilter = '';

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    public string $sortField = 'session_date';

    public string $sortDirection = 'asc';

    public int $perPage = 15;

    // Session Modal State
    public ?int $editingSessionId = null;

    public array $sessionForm = [
        'title' => '',
        'session_number' => 1,
        'academic_year' => '2026',
        'session_date' => '',
        'start_time' => '09:30',
        'end_time' => '17:30',
        'reporting_time' => '09:00',
        'venue_address' => 'Board Room 1, SNTCSSC Administrative Building, Salt Lake, Kolkata',
        'room_number' => 'Board Room A',
        'default_interview_duration' => 30,
        'default_buffer_duration' => 5,
        'instructions' => 'Candidates must report in formal attire with 2 copies of their Detailed Application Form (DAF).',
        'remarks' => '',
        'status' => 'scheduled',
        'chairman_invitation_template' => '',
        'member_invitation_template' => '',
        'student_invitation_template' => '',
    ];

    // Session Member Modal State
    public ?int $editingSessionMemberId = null;

    public array $sessionMemberForm = [
        'interview_board_member_id' => '',
        'role' => 'member', // chairperson, member
        'order_index' => 1,
        'attendance_status' => 'confirmed',
        'remarks' => '',
    ];

    // Quick Board Member Creation inside modal
    public bool $isQuickCreatingBoardMember = false;

    public array $quickBoardMemberForm = [
        'name' => '',
        'email' => '',
        'phone' => '',
        'designation' => '',
        'organization' => '',
        'specialization' => '',
        'status' => 'active',
    ];

    // Candidate Modal State
    public ?int $editingCandidateId = null;

    public array $candidateForm = [
        'user_id' => '',
        'batch_id' => '',
        'course_id' => '',
        'sequence_number' => 1,
        'scheduled_start_time' => '10:00',
        'scheduled_end_time' => '10:30',
        'duration_minutes' => 30,
        'attending_mode' => 'offline', // offline, online
        'venue_address' => '',
        'room_number' => '',
        'platform' => 'Zoom',
        'platform_other' => '',
        'meeting_link' => '',
        'meeting_id' => '',
        'meeting_password' => '',
        'candidate_instructions' => '',
        'status' => 'scheduled',
    ];

    // Auto-Generate Slots Modal State
    public array $autoSlotsForm = [
        'start_time' => '10:00',
        'duration_minutes' => 30,
        'buffer_minutes' => 5,
    ];

    // Recording Modal State
    public ?int $recordingCandidateId = null;

    public array $recordingForm = [
        'recording_type' => 'url', // url, upload
        'recording_url' => '',
        'recording_password' => '',
        'recording_notes' => '',
        'recording_status' => 'available',
    ];

    public $uploadedRecordingFile = null;

    public ?string $existingRecordingFilePath = null;

    public bool $removeRecordingFile = false;

    // Send Recording Modal State
    public ?int $dispatchRecordingCandidateId = null;

    public array $dispatchRecordingForm = [
        'channels' => ['email', 'whatsapp', 'in_app'],
        'notes' => '',
    ];

    // Score / Evaluation Modal State
    public ?int $evaluationCandidateId = null;

    public array $evaluationForm = [
        'interview_score' => '',
        'board_feedback' => '',
        'status' => 'completed',
    ];

    // Invitations Hub Modal State
    public string $inviteTarget = 'all'; // all, board_members, candidates, specific_candidate, specific_member

    public ?int $inviteCandidateId = null;

    public ?int $inviteMemberId = null;

    public array $inviteChannels = ['email', 'in_app'];

    public string $previewRecipientType = 'student'; // chairman, board_member, student

    public string $hubTab = 'preview'; // preview, edit_template

    public string $activeTemplateText = '';

    public string $customChairmanTemplate = '';

    public string $customMemberTemplate = '';

    public string $customStudentTemplate = '';

    public string $customInvitationTemplate = '';

    public ?array $liveInvitationPreview = null;

    // Candidate Details Modal State
    public ?int $viewingCandidateId = null;

    public ?MockInterviewCandidate $viewingCandidate = null;

    // Reports Generator Modal State
    public string $reportType = 'student_wise'; // 'student_wise', 'board_member_wise', 'year_wise'

    public string $reportAcademicYear = '';

    public string $reportSessionId = '';

    public string $reportMode = ''; // '', 'offline', 'online'

    public string $reportStatus = ''; // '', 'scheduled', 'completed', 'in_progress', 'absent', 'cancelled'

    public string $reportBoardMemberId = '';

    public string $reportEvaluationStatus = ''; // '', 'evaluated', 'pending'

    public ?string $reportStartDate = null;

    public ?string $reportEndDate = null;

    // Import Candidates Modal State
    public $candidateImportFile = null;

    public ?int $importBatchId = null;

    public ?int $importCourseId = null;

    // Import Sessions Modal State
    public $sessionImportFile = null;

    // Import Board Members Modal State
    public $boardMemberImportFile = null;

    // Board Members Master Pool Form
    public ?int $editingPoolMemberId = null;

    public array $poolMemberForm = [
        'name' => '',
        'user_id' => '',
        'email' => '',
        'phone' => '',
        'designation' => '',
        'organization' => '',
        'specialization' => '',
        'bio' => '',
        'status' => 'active',
    ];

    public $poolMemberAvatar = null;

    public ?string $existingAvatarUrl = null;

    public ?string $existingAvatarPath = null;

    public bool $removeAvatar = false;

    // Generic Delete Confirmation State
    public ?string $deleteActionType = null; // session, session_member, candidate, pool_member

    public ?int $deleteTargetId = null;

    public string $deleteTargetTitle = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedModeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function dashboardStats(): array
    {
        $now = now()->toDateString();

        return [
            'total_sessions' => MockInterviewSession::count(),
            'upcoming_sessions' => MockInterviewSession::whereDate('session_date', '>=', $now)->count(),
            'completed_sessions' => MockInterviewSession::where('status', MockInterviewSession::STATUS_COMPLETED)->count(),
            'total_candidates' => MockInterviewCandidate::count(),
            'offline_candidates' => MockInterviewCandidate::where('attending_mode', MockInterviewCandidate::MODE_OFFLINE)->count(),
            'online_candidates' => MockInterviewCandidate::where('attending_mode', MockInterviewCandidate::MODE_ONLINE)->count(),
            'board_members_count' => InterviewBoardMember::where('status', InterviewBoardMember::STATUS_ACTIVE)->count(),
            'available_recordings' => MockInterviewCandidate::where('recording_status', MockInterviewCandidate::RECORDING_AVAILABLE)->count(),
        ];
    }

    #[Computed]
    public function currentSession(): ?MockInterviewSession
    {
        if (! $this->selectedSessionId) {
            return null;
        }

        return MockInterviewSession::with([
            'sessionMembers.boardMember',
            'candidates.user',
            'candidates.batch',
            'candidates.course',
        ])->find($this->selectedSessionId);
    }

    #[Computed]
    public function sessionsList()
    {
        return MockInterviewSession::query()
            ->with(['sessionMembers.boardMember', 'candidates'])
            ->when($this->search, fn (Builder $q) => $q->search($this->search))
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->yearFilter, fn (Builder $q) => $q->where('academic_year', $this->yearFilter))
            ->when($this->dateFrom, fn (Builder $q) => $q->whereDate('session_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn (Builder $q) => $q->whereDate('session_date', '<=', $this->dateTo))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    #[Computed]
    public function boardMembersPool()
    {
        return InterviewBoardMember::query()
            ->when($this->search, fn (Builder $q) => $q->search($this->search))
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function candidateRecordingsVault()
    {
        return MockInterviewCandidate::query()
            ->with(['session', 'user', 'batch'])
            ->when($this->search, fn (Builder $q) => $q->search($this->search))
            ->when($this->statusFilter, fn (Builder $q) => $q->where('recording_status', $this->statusFilter))
            ->latest('id')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function batchesList()
    {
        return Batch::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function coursesList()
    {
        return Course::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function activeBoardMembers()
    {
        return InterviewBoardMember::active()->orderBy('name')->get();
    }

    #[Computed]
    public function availableStudents()
    {
        $batchId = $this->candidateForm['batch_id'] ? (int) $this->candidateForm['batch_id'] : null;
        $courseId = $this->candidateForm['course_id'] ? (int) $this->candidateForm['course_id'] : null;

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'Student'))
            ->when($batchId, function (Builder $q) use ($batchId) {
                $q->whereHas('enrollments', fn ($eq) => $eq->where('batch_id', $batchId)->where('status', 'active'));
            })
            ->when($courseId, function (Builder $q) use ($courseId) {
                $q->whereHas('enrollments', fn ($eq) => $eq->where('course_id', $courseId)->where('status', 'active'));
            })
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    // View Navigation

    public function setView(string $view, ?int $sessionId = null): void
    {
        $this->activeView = $view;
        if ($sessionId !== null) {
            $this->selectedSessionId = $sessionId;
        }
        $this->resetPage();
    }

    // ==========================================
    // Session CRUD Operations
    // ==========================================

    public function openSessionModal(?int $id = null): void
    {
        $this->editingSessionId = $id;

        if ($id) {
            $session = MockInterviewSession::findOrFail($id);
            $this->sessionForm = [
                'title' => $session->title,
                'session_number' => $session->session_number,
                'academic_year' => $session->academic_year,
                'session_date' => $session->session_date->format('Y-m-d'),
                'start_time' => Carbon::parse($session->start_time)->format('H:i'),
                'end_time' => Carbon::parse($session->end_time)->format('H:i'),
                'reporting_time' => $session->reporting_time ? Carbon::parse($session->reporting_time)->format('H:i') : '',
                'venue_address' => $session->venue_address,
                'room_number' => $session->room_number ?? '',
                'default_interview_duration' => $session->default_interview_duration,
                'default_buffer_duration' => $session->default_buffer_duration,
                'instructions' => $session->instructions ?? '',
                'remarks' => $session->remarks ?? '',
                'status' => $session->status,
                'chairman_invitation_template' => $session->chairman_invitation_template ?? '',
                'member_invitation_template' => $session->member_invitation_template ?? '',
                'student_invitation_template' => $session->student_invitation_template ?? '',
            ];
        } else {
            $nextNum = (MockInterviewSession::max('session_number') ?: 0) + 1;
            $this->sessionForm = [
                'title' => "UPSC CSE 2026 Mock Interview - Session {$nextNum}",
                'session_number' => $nextNum,
                'academic_year' => date('Y'),
                'session_date' => now()->addDays(7)->format('Y-m-d'),
                'start_time' => '09:30',
                'end_time' => '17:30',
                'reporting_time' => '09:00',
                'venue_address' => 'Board Room 1, SNTCSSC Administrative Complex, Salt Lake, Sector V, Kolkata - 700091',
                'room_number' => 'Board Room A',
                'default_interview_duration' => 30,
                'default_buffer_duration' => 5,
                'instructions' => 'Candidates must report in formal attire with 2 copies of their Detailed Application Form (DAF).',
                'remarks' => '',
                'status' => 'scheduled',
                'chairman_invitation_template' => '',
                'member_invitation_template' => '',
                'student_invitation_template' => '',
            ];
        }

        $this->dispatch('modal-open', name: 'session-form-modal');
    }

    public function loadDefaultTemplateForSession(string $type): void
    {
        $service = app(MockInterviewService::class);
        if ($type === 'chairman') {
            $this->sessionForm['chairman_invitation_template'] = $service->getDefaultChairmanInvitationTemplate();
        } elseif ($type === 'member') {
            $this->sessionForm['member_invitation_template'] = $service->getDefaultMemberInvitationTemplate();
        } elseif ($type === 'student') {
            $this->sessionForm['student_invitation_template'] = $service->getDefaultStudentInvitationTemplate();
        }
    }

    public function saveSession(): void
    {
        $this->validate([
            'sessionForm.title' => 'required|string|max:255',
            'sessionForm.session_number' => 'required|integer|min:1',
            'sessionForm.academic_year' => 'required|string|max:16',
            'sessionForm.session_date' => 'required|date',
            'sessionForm.start_time' => 'required',
            'sessionForm.end_time' => 'required',
            'sessionForm.venue_address' => 'required|string',
            'sessionForm.default_interview_duration' => 'required|integer|min:10|max:120',
            'sessionForm.status' => 'required|string',
        ]);

        try {
            DB::transaction(function () {
                if ($this->editingSessionId) {
                    $session = MockInterviewSession::findOrFail($this->editingSessionId);
                    $session->update($this->sessionForm);
                    Toast::dispatch($this, 'success', __('Mock Interview Session updated successfully.'));
                } else {
                    $code = MockInterviewSession::generateNextSessionCode($this->sessionForm['academic_year']);
                    $session = MockInterviewSession::create(array_merge($this->sessionForm, [
                        'session_code' => $code,
                    ]));
                    Toast::dispatch($this, 'success', __('Mock Interview Session created successfully.'));
                    $this->selectedSessionId = $session->id;
                }
            });

            $this->dispatch('modal-close', name: 'session-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error saving session: ').$e->getMessage());
        }
    }

    // ==========================================
    // Board Member Management in Session
    // ==========================================

    public function openSessionMemberModal(?int $id = null): void
    {
        $this->editingSessionMemberId = $id;
        $this->isQuickCreatingBoardMember = false;

        if ($id) {
            $member = MockInterviewSessionMember::findOrFail($id);
            $this->sessionMemberForm = [
                'interview_board_member_id' => $member->interview_board_member_id,
                'role' => $member->role,
                'order_index' => $member->order_index,
                'attendance_status' => $member->attendance_status,
                'remarks' => $member->remarks ?? '',
            ];
        } else {
            $count = $this->currentSession ? $this->currentSession->sessionMembers->count() : 0;
            $this->sessionMemberForm = [
                'interview_board_member_id' => '',
                'role' => $count === 0 ? 'chairperson' : 'member',
                'order_index' => $count + 1,
                'attendance_status' => 'confirmed',
                'remarks' => '',
            ];
        }

        $this->dispatch('modal-open', name: 'session-member-modal');
    }

    public function quickCreateBoardMember(): void
    {
        $this->validate([
            'quickBoardMemberForm.name' => 'required|string|max:255',
            'quickBoardMemberForm.designation' => 'nullable|string|max:255',
            'quickBoardMemberForm.email' => 'nullable|email|max:255',
            'quickBoardMemberForm.phone' => 'nullable|string|max:32',
        ]);

        try {
            $member = InterviewBoardMember::create($this->quickBoardMemberForm);
            $this->sessionMemberForm['interview_board_member_id'] = $member->id;
            $this->isQuickCreatingBoardMember = false;
            $this->reset('quickBoardMemberForm');
            Toast::dispatch($this, 'success', __('New Panelist created and selected!'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to create panelist: ').$e->getMessage());
        }
    }

    public function saveSessionMember(): void
    {
        $this->validate([
            'sessionMemberForm.interview_board_member_id' => 'required|exists:interview_board_members,id',
            'sessionMemberForm.role' => 'required|in:chairperson,member',
            'sessionMemberForm.order_index' => 'required|integer|min:1',
        ]);

        if (! $this->selectedSessionId) {
            return;
        }

        try {
            DB::transaction(function () {
                $session = MockInterviewSession::findOrFail($this->selectedSessionId);

                // If assigning chairperson, demote any other chairperson to regular member
                if ($this->sessionMemberForm['role'] === MockInterviewSessionMember::ROLE_CHAIRPERSON) {
                    $session->sessionMembers()
                        ->where('role', MockInterviewSessionMember::ROLE_CHAIRPERSON)
                        ->where('interview_board_member_id', '!=', $this->sessionMemberForm['interview_board_member_id'])
                        ->update(['role' => MockInterviewSessionMember::ROLE_MEMBER]);
                }

                MockInterviewSessionMember::updateOrCreate(
                    [
                        'mock_interview_session_id' => $session->id,
                        'interview_board_member_id' => $this->sessionMemberForm['interview_board_member_id'],
                    ],
                    [
                        'role' => $this->sessionMemberForm['role'],
                        'order_index' => $this->sessionMemberForm['order_index'],
                        'attendance_status' => $this->sessionMemberForm['attendance_status'],
                        'remarks' => $this->sessionMemberForm['remarks'],
                    ]
                );

                AuditLogService::log(
                    event: 'mock_interview_member_assigned',
                    description: "Assigned board member to session: {$session->session_code}",
                    auditable: $session
                );
            });

            Toast::dispatch($this, 'success', __('Board member assigned successfully!'));
            $this->dispatch('modal-close', name: 'session-member-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error assigning member: ').$e->getMessage());
        }
    }

    public function setAsChairperson(int $sessionMemberId): void
    {
        try {
            DB::transaction(function () use ($sessionMemberId) {
                $member = MockInterviewSessionMember::findOrFail($sessionMemberId);
                $session = $member->session;

                // Demote existing chairperson
                $session->sessionMembers()
                    ->where('role', MockInterviewSessionMember::ROLE_CHAIRPERSON)
                    ->update(['role' => MockInterviewSessionMember::ROLE_MEMBER]);

                $member->update([
                    'role' => MockInterviewSessionMember::ROLE_CHAIRPERSON,
                    'order_index' => 1,
                ]);

                AuditLogService::log(
                    event: 'mock_interview_chairperson_set',
                    description: "Set {$member->boardMember->name} as Chairperson for session {$session->session_code}",
                    auditable: $session
                );
            });

            Toast::dispatch($this, 'success', __('Board Chairperson designated successfully!'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error setting chairperson: ').$e->getMessage());
        }
    }

    // ==========================================
    // Candidate Student Management in Session
    // ==========================================

    public function viewCandidateDetails(int $candidateId): void
    {
        $this->viewingCandidateId = $candidateId;
        $this->viewingCandidate = MockInterviewCandidate::with([
            'session',
            'batch',
            'course',
            'user.studentProfile',
            'user.studentAddresses',
            'user.studentEducations',
            'user.studentOtherDetails',
            'user.upscAttempts',
            'user.enrollments.course',
            'user.enrollments.batch',
        ])->findOrFail($candidateId);

        $this->dispatch('modal-open', name: 'candidate-details-modal');
    }

    public function openCandidateModal(?int $id = null): void
    {
        $this->editingCandidateId = $id;

        if ($id) {
            $candidate = MockInterviewCandidate::findOrFail($id);
            $this->candidateForm = [
                'user_id' => $candidate->user_id,
                'batch_id' => $candidate->batch_id ?? '',
                'course_id' => $candidate->course_id ?? '',
                'sequence_number' => $candidate->sequence_number,
                'scheduled_start_time' => Carbon::parse($candidate->scheduled_start_time)->format('H:i'),
                'scheduled_end_time' => Carbon::parse($candidate->scheduled_end_time)->format('H:i'),
                'duration_minutes' => $candidate->duration_minutes,
                'attending_mode' => $candidate->attending_mode,
                'venue_address' => $candidate->venue_address ?? '',
                'room_number' => $candidate->room_number ?? '',
                'platform' => $candidate->platform ?? 'Zoom',
                'platform_other' => $candidate->platform_other ?? '',
                'meeting_link' => $candidate->meeting_link ?? '',
                'meeting_id' => $candidate->meeting_id ?? '',
                'meeting_password' => $candidate->meeting_password ?? '',
                'candidate_instructions' => $candidate->candidate_instructions ?? '',
                'status' => $candidate->status,
            ];
        } else {
            $session = $this->currentSession;
            $maxSeq = $session ? ($session->candidates->max('sequence_number') ?: 0) : 0;
            $duration = $session ? $session->default_interview_duration : 30;

            // Compute next slot start time
            $slotStart = '10:00';
            if ($session && $session->candidates->isNotEmpty()) {
                $lastCandidate = $session->candidates->sortByDesc('sequence_number')->first();
                $slotStart = Carbon::parse($lastCandidate->scheduled_end_time)
                    ->addMinutes($session->default_buffer_duration ?: 5)
                    ->format('H:i');
            } elseif ($session) {
                $slotStart = Carbon::parse($session->start_time)->format('H:i');
            }
            $slotEnd = Carbon::parse($slotStart)->addMinutes($duration)->format('H:i');

            $this->candidateForm = [
                'user_id' => '',
                'batch_id' => '',
                'course_id' => '',
                'sequence_number' => $maxSeq + 1,
                'scheduled_start_time' => $slotStart,
                'scheduled_end_time' => $slotEnd,
                'duration_minutes' => $duration,
                'attending_mode' => 'offline',
                'venue_address' => $session ? $session->venue_address : '',
                'room_number' => $session ? ($session->room_number ?? '') : '',
                'platform' => 'Zoom',
                'platform_other' => '',
                'meeting_link' => '',
                'meeting_id' => '',
                'meeting_password' => '',
                'candidate_instructions' => '',
                'status' => 'scheduled',
            ];
        }

        $this->dispatch('modal-open', name: 'candidate-form-modal');
    }

    public function updatedCandidateFormScheduledStartTime(): void
    {
        $duration = (int) ($this->candidateForm['duration_minutes'] ?: 30);
        if ($this->candidateForm['scheduled_start_time']) {
            $this->candidateForm['scheduled_end_time'] = Carbon::parse($this->candidateForm['scheduled_start_time'])
                ->addMinutes($duration)
                ->format('H:i');
        }
    }

    public function updatedCandidateFormDurationMinutes(): void
    {
        $this->updatedCandidateFormScheduledStartTime();
    }

    public function saveCandidate(): void
    {
        $this->validate([
            'candidateForm.user_id' => 'required|exists:users,id',
            'candidateForm.sequence_number' => 'required|integer|min:1',
            'candidateForm.scheduled_start_time' => 'required',
            'candidateForm.scheduled_end_time' => 'required',
            'candidateForm.duration_minutes' => 'required|integer|min:5|max:120',
            'candidateForm.attending_mode' => 'required|in:offline,online',
            'candidateForm.meeting_link' => 'nullable|url',
        ]);

        if (! $this->selectedSessionId) {
            return;
        }

        try {
            DB::transaction(function () {
                $session = MockInterviewSession::findOrFail($this->selectedSessionId);

                $payload = array_merge($this->candidateForm, [
                    'mock_interview_session_id' => $session->id,
                    'batch_id' => $this->candidateForm['batch_id'] ? (int) $this->candidateForm['batch_id'] : null,
                    'course_id' => $this->candidateForm['course_id'] ? (int) $this->candidateForm['course_id'] : null,
                    'venue_address' => $this->candidateForm['venue_address'] ?: $session->venue_address,
                ]);

                if ($this->editingCandidateId) {
                    $candidate = MockInterviewCandidate::findOrFail($this->editingCandidateId);
                    $candidate->update($payload);
                    Toast::dispatch($this, 'success', __('Candidate details updated successfully!'));
                } else {
                    MockInterviewCandidate::updateOrCreate(
                        [
                            'mock_interview_session_id' => $session->id,
                            'user_id' => $this->candidateForm['user_id'],
                        ],
                        $payload
                    );
                    Toast::dispatch($this, 'success', __('Candidate added to interview session!'));
                }

                AuditLogService::log(
                    event: 'mock_interview_candidate_saved',
                    description: "Saved candidate for session: {$session->session_code}",
                    auditable: $session
                );
            });

            $this->dispatch('modal-close', name: 'candidate-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error saving candidate: ').$e->getMessage());
        }
    }

    public function moveCandidateSequence(int $candidateId, string $direction): void
    {
        try {
            DB::transaction(function () use ($candidateId, $direction) {
                $candidate = MockInterviewCandidate::findOrFail($candidateId);
                $session = $candidate->session;
                $candidates = $session->candidates()->orderBy('sequence_number', 'asc')->get();

                $currentIndex = $candidates->search(fn ($c) => $c->id === $candidate->id);
                if ($currentIndex === false) {
                    return;
                }

                $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
                if ($targetIndex < 0 || $targetIndex >= $candidates->count()) {
                    return;
                }

                $otherCandidate = $candidates->get($targetIndex);

                // Swap sequence numbers
                $tempSeq = $candidate->sequence_number;
                $candidate->update(['sequence_number' => $otherCandidate->sequence_number]);
                $otherCandidate->update(['sequence_number' => $tempSeq]);
            });

            Toast::dispatch($this, 'success', __('Order of appearance updated!'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error reordering: ').$e->getMessage());
        }
    }

    // ==========================================
    // Auto-Generate Slots Modal & Execution
    // ==========================================

    public function openAutoSlotsModal(): void
    {
        $session = $this->currentSession;
        $this->autoSlotsForm = [
            'start_time' => $session ? Carbon::parse($session->start_time)->format('H:i') : '10:00',
            'duration_minutes' => $session ? $session->default_interview_duration : 30,
            'buffer_minutes' => $session ? $session->default_buffer_duration : 5,
        ];

        $this->dispatch('modal-open', name: 'auto-slots-modal');
    }

    public function executeAutoSlots(): void
    {
        $service = app(MockInterviewService::class);

        $this->validate([
            'autoSlotsForm.start_time' => 'required',
            'autoSlotsForm.duration_minutes' => 'required|integer|min:10|max:120',
            'autoSlotsForm.buffer_minutes' => 'required|integer|min:0|max:60',
        ]);

        if (! $this->currentSession) {
            return;
        }

        try {
            $count = $service->autoGenerateTimeSlots(
                session: $this->currentSession,
                startTime: $this->autoSlotsForm['start_time'],
                durationMinutes: (int) $this->autoSlotsForm['duration_minutes'],
                bufferMinutes: (int) $this->autoSlotsForm['buffer_minutes']
            );

            Toast::dispatch($this, 'success', __("Time slots calculated and allocated for {$count} candidates!"));
            $this->dispatch('modal-close', name: 'auto-slots-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error generating slots: ').$e->getMessage());
        }
    }

    // ==========================================
    // Recording Upload & Link Management
    // ==========================================

    public function openRecordingModal(int $candidateId): void
    {
        $this->recordingCandidateId = $candidateId;
        $candidate = MockInterviewCandidate::findOrFail($candidateId);

        $this->recordingForm = [
            'recording_type' => $candidate->recording_type ?? 'url',
            'recording_url' => $candidate->recording_url ?? '',
            'recording_password' => $candidate->recording_password ?? '',
            'recording_notes' => $candidate->recording_notes ?? '',
            'recording_status' => $candidate->recording_status === 'not_available' ? 'available' : $candidate->recording_status,
        ];
        $this->uploadedRecordingFile = null;
        $this->existingRecordingFilePath = $candidate->recording_file_path;
        $this->removeRecordingFile = false;

        $this->dispatch('modal-open', name: 'recording-modal');
    }

    public function saveRecording(): void
    {
        $candidate = MockInterviewCandidate::findOrFail($this->recordingCandidateId);

        $rules = [
            'recordingForm.recording_type' => 'required|in:url,upload',
            'recordingForm.recording_status' => 'required|in:not_available,processing,available',
            'recordingForm.recording_password' => 'nullable|string|max:64',
            'recordingForm.recording_notes' => 'nullable|string',
        ];

        if ($this->recordingForm['recording_type'] === 'url') {
            $rules['recordingForm.recording_url'] = 'required|url';
        } else {
            $rules['uploadedRecordingFile'] = 'nullable|file|mimes:mp4,mov,avi,webm,mkv|max:204800'; // up to 200MB
        }

        $this->validate($rules);

        try {
            DB::transaction(function () use ($candidate) {
                $filePath = $candidate->recording_file_path;

                if ($this->removeRecordingFile && $this->existingRecordingFilePath) {
                    if (Storage::disk('public')->exists($this->existingRecordingFilePath)) {
                        Storage::disk('public')->delete($this->existingRecordingFilePath);
                    }
                    $filePath = null;
                    if ($this->recordingForm['recording_type'] === 'upload') {
                        $this->recordingForm['recording_status'] = MockInterviewCandidate::RECORDING_NOT_AVAILABLE;
                    }
                } elseif ($this->recordingForm['recording_type'] === 'upload' && $this->uploadedRecordingFile) {
                    // Delete old file if present
                    if ($this->existingRecordingFilePath && Storage::disk('public')->exists($this->existingRecordingFilePath)) {
                        Storage::disk('public')->delete($this->existingRecordingFilePath);
                    }
                    $filename = $this->generateFileName('interview_recording', $this->uploadedRecordingFile, auth()->id() ?? 1);
                    $filePath = $this->uploadedRecordingFile->storeAs('mock-interviews/recordings', $filename, 'public');
                    $this->recordingForm['recording_status'] = MockInterviewCandidate::RECORDING_AVAILABLE;
                }

                $candidate->update([
                    'recording_type' => $this->recordingForm['recording_type'],
                    'recording_url' => $this->recordingForm['recording_type'] === 'url' ? $this->recordingForm['recording_url'] : null,
                    'recording_file_path' => $filePath,
                    'recording_password' => $this->recordingForm['recording_password'],
                    'recording_notes' => $this->recordingForm['recording_notes'],
                    'recording_status' => $this->recordingForm['recording_status'],
                    'recording_uploaded_at' => now(),
                ]);

                AuditLogService::log(
                    event: 'mock_interview_recording_attached',
                    description: "Attached interview recording for {$candidate->user->name}",
                    auditable: $candidate
                );
            });

            Toast::dispatch($this, 'success', __('Interview recording attached successfully!'));
            $this->dispatch('modal-close', name: 'recording-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error attaching recording: ').$e->getMessage());
        }
    }

    public function openDispatchRecordingModal(int $candidateId): void
    {
        $this->dispatchRecordingCandidateId = $candidateId;
        $candidate = MockInterviewCandidate::findOrFail($candidateId);

        $this->dispatchRecordingForm = [
            'channels' => ['email', 'whatsapp', 'in_app'],
            'notes' => $candidate->recording_notes ?? '',
        ];

        $this->dispatch('modal-open', name: 'dispatch-recording-modal');
    }

    public function dispatchRecordingToStudent(): void
    {
        $service = app(MockInterviewService::class);

        $this->validate([
            'dispatchRecordingForm.channels' => 'required|array|min:1',
        ]);

        $candidate = MockInterviewCandidate::findOrFail($this->dispatchRecordingCandidateId);

        try {
            $results = $service->sendRecordingToCandidate(
                candidate: $candidate,
                channels: $this->dispatchRecordingForm['channels'],
                customNotes: $this->dispatchRecordingForm['notes'] ?? null
            );

            Toast::dispatch($this, 'success', __('Interview recording link sent to student successfully!'));
            $this->dispatch('modal-close', name: 'dispatch-recording-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error sending recording link: ').$e->getMessage());
        }
    }

    // ==========================================
    // Candidate Evaluation & Score
    // ==========================================

    public function openEvaluationModal(int $candidateId): void
    {
        $this->evaluationCandidateId = $candidateId;
        $candidate = MockInterviewCandidate::findOrFail($candidateId);

        $this->evaluationForm = [
            'interview_score' => $candidate->interview_score !== null ? (string) $candidate->interview_score : '',
            'board_feedback' => $candidate->board_feedback ?? '',
            'status' => $candidate->status === 'scheduled' ? 'completed' : $candidate->status,
        ];

        $this->dispatch('modal-open', name: 'evaluation-modal');
    }

    public function saveEvaluation(): void
    {
        $this->validate([
            'evaluationForm.interview_score' => 'nullable|numeric|min:0|max:275',
            'evaluationForm.board_feedback' => 'nullable|string',
            'evaluationForm.status' => 'required|in:scheduled,reported,interviewing,completed,absent,rescheduled',
        ]);

        $candidate = MockInterviewCandidate::findOrFail($this->evaluationCandidateId);

        try {
            $candidate->update([
                'interview_score' => $this->evaluationForm['interview_score'] !== '' ? (float) $this->evaluationForm['interview_score'] : null,
                'board_feedback' => $this->evaluationForm['board_feedback'],
                'status' => $this->evaluationForm['status'],
            ]);

            AuditLogService::log(
                event: 'mock_interview_evaluated',
                description: "Recorded score & feedback for candidate: {$candidate->user->name}",
                auditable: $candidate
            );

            Toast::dispatch($this, 'success', __('Score & panel feedback recorded successfully!'));
            $this->dispatch('modal-close', name: 'evaluation-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error saving evaluation: ').$e->getMessage());
        }
    }

    // ==========================================
    // Invitations Hub & Live Preview Modal
    // ==========================================

    public function openInvitationsModal(?string $target = null, ?int $targetId = null): void
    {
        if (! $this->currentSession) {
            return;
        }

        $session = $this->currentSession;
        $service = app(MockInterviewService::class);

        $this->inviteTarget = $target ?: 'all';
        $this->inviteCandidateId = ($target === 'specific_candidate') ? $targetId : null;
        $this->inviteMemberId = ($target === 'specific_member') ? $targetId : null;
        $this->inviteChannels = ['email', 'in_app'];
        $this->hubTab = 'preview';

        if ($this->inviteTarget === 'specific_member' && $this->inviteMemberId) {
            $member = MockInterviewSessionMember::find($this->inviteMemberId);
            $this->previewRecipientType = ($member && $member->isChairperson()) ? 'chairman' : 'board_member';
        } elseif ($this->inviteTarget === 'board_members') {
            $this->previewRecipientType = 'chairman';
        } else {
            $this->previewRecipientType = 'student';
        }

        $this->customChairmanTemplate = $session->chairman_invitation_template ?: $service->getDefaultChairmanInvitationTemplate();
        $this->customMemberTemplate = $session->member_invitation_template ?: $service->getDefaultMemberInvitationTemplate();
        $this->customStudentTemplate = $session->student_invitation_template ?: $service->getDefaultStudentInvitationTemplate();

        $this->syncActiveTemplateText();
        $this->refreshLivePreview();
        $this->dispatch('modal-open', name: 'invitation-hub-modal');
    }

    public function openInvitationsForSession(int $sessionId): void
    {
        $this->selectedSessionId = $sessionId;
        $this->activeView = 'session_detail';
        $this->openInvitationsModal('all');
    }

    public function updatedPreviewRecipientType(): void
    {
        $this->syncActiveTemplateText();
        $this->refreshLivePreview();
    }

    public function updatedActiveTemplateText(): void
    {
        match ($this->previewRecipientType) {
            'chairman' => $this->customChairmanTemplate = $this->activeTemplateText,
            'board_member' => $this->customMemberTemplate = $this->activeTemplateText,
            default => $this->customStudentTemplate = $this->activeTemplateText,
        };

        $this->refreshLivePreview();
    }

    protected function syncActiveTemplateText(): void
    {
        $this->activeTemplateText = match ($this->previewRecipientType) {
            'chairman' => $this->customChairmanTemplate,
            'board_member' => $this->customMemberTemplate,
            default => $this->customStudentTemplate,
        };
    }

    public function saveSessionInvitationTemplate(): void
    {
        if (! $this->currentSession) {
            return;
        }

        $session = $this->currentSession;
        match ($this->previewRecipientType) {
            'chairman' => $session->chairman_invitation_template = $this->activeTemplateText,
            'board_member' => $session->member_invitation_template = $this->activeTemplateText,
            default => $session->student_invitation_template = $this->activeTemplateText,
        };
        $session->save();

        AuditLogService::log(
            event: 'mock_interview_template_saved',
            description: "Saved custom {$this->previewRecipientType} invitation template for session {$session->session_code}.",
            auditable: $session
        );

        $label = match ($this->previewRecipientType) {
            'chairman' => 'Chairman of the Board',
            'board_member' => 'Board Panelist Member',
            default => 'Attending Aspirant Student',
        };

        Toast::dispatch($this, 'success', __("Saved {$label} invitation template for this session!"));
        $this->refreshLivePreview();
    }

    public function resetInvitationTemplateToDefault(): void
    {
        $service = app(MockInterviewService::class);
        $defaultText = match ($this->previewRecipientType) {
            'chairman' => $service->getDefaultChairmanInvitationTemplate(),
            'board_member' => $service->getDefaultMemberInvitationTemplate(),
            default => $service->getDefaultStudentInvitationTemplate(),
        };

        $this->activeTemplateText = $defaultText;
        match ($this->previewRecipientType) {
            'chairman' => $this->customChairmanTemplate = $defaultText,
            'board_member' => $this->customMemberTemplate = $defaultText,
            default => $this->customStudentTemplate = $defaultText,
        };

        if ($this->currentSession) {
            $session = $this->currentSession;
            match ($this->previewRecipientType) {
                'chairman' => $session->chairman_invitation_template = null,
                'board_member' => $session->member_invitation_template = null,
                default => $session->student_invitation_template = null,
            };
            $session->save();
        }

        $label = match ($this->previewRecipientType) {
            'chairman' => 'Chairman of the Board',
            'board_member' => 'Board Panelist Member',
            default => 'Attending Aspirant Student',
        };

        Toast::dispatch($this, 'info', __("Reset {$label} invitation template to system default!"));
        $this->refreshLivePreview();
    }

    public function hasCustomSessionTemplate(): bool
    {
        if (! $this->currentSession) {
            return false;
        }

        $session = $this->currentSession;

        return match ($this->previewRecipientType) {
            'chairman' => ! empty($session->chairman_invitation_template),
            'board_member' => ! empty($session->member_invitation_template),
            default => ! empty($session->student_invitation_template),
        };
    }

    public function getAvailableTemplatePlaceholdersProperty(): array
    {
        if ($this->previewRecipientType === 'chairman') {
            return [
                '{name}' => __('Chairperson full name'),
                '{session_no}' => __('Session serial number (e.g. 1)'),
                '{session_title}' => __('Session title'),
                '{session_date}' => __('Formatted session date (e.g. 15 Nov 2026)'),
                '{start_time}' => __('Expected starting time (e.g. 09:30 AM)'),
                '{end_time}' => __('Expected ending time (e.g. 05:30 PM)'),
                '{reporting_time}' => __('Panel reporting time (e.g. 09:00 AM)'),
                '{venue_address}' => __('Session physical venue location'),
                '{room_number}' => __('Board room identifier'),
                '{candidates_count}' => __('Total scheduled candidates'),
                '{board_members_list}' => __('Names and designations of panel members'),
                '{instructions}' => __('Board guidelines and evaluation rubrics'),
            ];
        }

        if ($this->previewRecipientType === 'board_member') {
            return [
                '{name}' => __('Board panelist name'),
                '{chairperson_name}' => __('Board chairperson name'),
                '{session_no}' => __('Session serial number'),
                '{session_title}' => __('Session title'),
                '{session_date}' => __('Formatted session date'),
                '{start_time}' => __('Expected starting time'),
                '{end_time}' => __('Expected ending time'),
                '{reporting_time}' => __('Reporting time'),
                '{venue_address}' => __('Venue address'),
                '{room_number}' => __('Board room identifier'),
                '{candidates_count}' => __('Scheduled candidates count'),
                '{instructions}' => __('Instructions & guidelines'),
            ];
        }

        return [
            '{name}' => __('Candidate student name'),
            '{session_no}' => __('Session number'),
            '{session_title}' => __('Session title'),
            '{session_date}' => __('Interview session date'),
            '{attending_mode}' => __('Attending Mode: ONLINE or OFFLINE (In-Person)'),
            '{sequence_number}' => __('Order / sequence of appearance (e.g. 1, 2, 3...)'),
            '{slot_time}' => __('Starting to end time slot (e.g. 10:00 AM - 10:30 AM)'),
            '{duration_minutes}' => __('Interview duration in minutes (e.g. 30)'),
            '{reporting_time}' => __('Reporting time before interview'),
            '{location_or_platform_details}' => __('Venue/Room for Offline, or Meeting link & credentials for Online'),
            '{chairperson_name}' => __('Chairperson leading the board'),
            '{candidate_instructions}' => __('Specific instructions for candidate'),
        ];
    }

    public function refreshLivePreview(): void
    {
        $service = app(MockInterviewService::class);

        if (! $this->currentSession) {
            return;
        }

        $session = $this->currentSession;

        if ($this->previewRecipientType === 'chairman') {
            $chair = $session->sessionMembers()->where('role', MockInterviewSessionMember::ROLE_CHAIRPERSON)->first()
                ?: $session->sessionMembers->first();
            $tpl = $this->customChairmanTemplate ?: null;
            if ($chair) {
                $this->liveInvitationPreview = $service->previewMemberInvitation($chair, $tpl);
            } else {
                $variables = [
                    'name' => 'Dr. A. K. Sharma',
                    'session_no' => $session->session_number,
                    'session_title' => $session->title,
                    'session_date' => $session->formatted_date,
                    'start_time' => Carbon::parse($session->start_time)->format('h:i A'),
                    'end_time' => Carbon::parse($session->end_time)->format('h:i A'),
                    'reporting_time' => $session->formatted_reporting_time ?: '09:00 AM',
                    'venue_address' => $session->venue_address,
                    'room_number' => $session->room_number ? "({$session->room_number})" : '',
                    'candidates_count' => $session->candidates->count(),
                    'board_members_list' => '5-Member Panel',
                    'instructions' => $session->instructions ?: 'Standard UPSC Personality Test rubric.',
                ];
                $this->liveInvitationPreview = [
                    'recipient_name' => 'Dr. A. K. Sharma (Example Chair)',
                    'recipient_type' => 'chairman',
                    'recipient_email' => 'chair@example.gov.in',
                    'subject' => "UPSC Mock Interview Board Invitation - {$session->title} [Session #{$session->session_number}]",
                    'content' => $service->renderContent($tpl ?: $service->getDefaultChairmanInvitationTemplate(), $variables),
                    'variables' => $variables,
                ];
            }
        } elseif ($this->previewRecipientType === 'board_member') {
            $regularMember = $session->sessionMembers()->where('role', MockInterviewSessionMember::ROLE_MEMBER)->first()
                ?: $session->sessionMembers->first();
            $tpl = $this->customMemberTemplate ?: null;
            if ($regularMember) {
                $this->liveInvitationPreview = $service->previewMemberInvitation($regularMember, $tpl);
            } else {
                $variables = [
                    'name' => 'Prof. S. Sen',
                    'session_no' => $session->session_number,
                    'session_title' => $session->title,
                    'session_date' => $session->formatted_date,
                    'start_time' => Carbon::parse($session->start_time)->format('h:i A'),
                    'end_time' => Carbon::parse($session->end_time)->format('h:i A'),
                    'reporting_time' => '09:00 AM',
                    'venue_address' => $session->venue_address,
                    'room_number' => $session->room_number ? "({$session->room_number})" : '',
                    'chairperson_name' => 'Shri P. Mukherjee, IAS (Retd.)',
                    'candidates_count' => $session->candidates->count(),
                    'instructions' => $session->instructions ?: 'Standard evaluation.',
                ];
                $this->liveInvitationPreview = [
                    'recipient_name' => 'Prof. S. Sen (Example Member)',
                    'recipient_type' => 'board_member',
                    'recipient_email' => 'panelist@example.gov.in',
                    'subject' => "UPSC Mock Interview Board Member Invitation - {$session->title}",
                    'content' => $service->renderContent($tpl ?: $service->getDefaultMemberInvitationTemplate(), $variables),
                    'variables' => $variables,
                ];
            }
        } else {
            // Student Preview
            $candidate = $session->candidates->first();
            $tpl = $this->customStudentTemplate ?: null;
            if ($candidate) {
                $this->liveInvitationPreview = $service->previewCandidateInvitation($candidate, $tpl);
            } else {
                $variables = [
                    'name' => 'Ananya Chatterjee',
                    'session_no' => $session->session_number,
                    'session_title' => $session->title,
                    'session_date' => $session->formatted_date,
                    'attending_mode' => 'OFFLINE (In-Person at Campus)',
                    'sequence_number' => 1,
                    'slot_time' => '10:00 AM - 10:30 AM (30 mins)',
                    'duration_minutes' => 30,
                    'reporting_time' => '09:30 AM',
                    'location_or_platform_details' => "Interview Venue: {$session->venue_address} (Room: {$session->room_number})",
                    'chairperson_name' => 'Dr. A. K. Sharma',
                    'candidate_instructions' => 'Bring two copies of your DAF.',
                ];
                $this->liveInvitationPreview = [
                    'recipient_name' => 'Ananya Chatterjee (Sample Aspirant)',
                    'recipient_type' => 'student',
                    'recipient_email' => 'student@example.com',
                    'subject' => "UPSC Mock Interview Schedule - {$session->title} [Slot: 10:00 AM - 10:30 AM]",
                    'content' => $service->renderContent($tpl ?: $service->getDefaultStudentInvitationTemplate(), $variables),
                    'variables' => $variables,
                ];
            }
        }
    }

    public function dispatchInvitations(): void
    {
        $service = app(MockInterviewService::class);

        $this->validate([
            'inviteChannels' => 'required|array|min:1',
        ]);

        if (! $this->currentSession) {
            return;
        }

        $session = $this->currentSession;
        $sentCount = 0;
        $channels = $this->inviteChannels;

        try {
            if (in_array($this->inviteTarget, ['all', 'board_members'])) {
                foreach ($session->sessionMembers as $sm) {
                    $tpl = $sm->isChairperson()
                        ? ($this->customChairmanTemplate ?: null)
                        : ($this->customMemberTemplate ?: null);
                    $service->sendMemberInvitation($sm, $channels, $tpl);
                    $sentCount++;
                }
            }

            if (in_array($this->inviteTarget, ['all', 'candidates'])) {
                $tpl = $this->customStudentTemplate ?: null;
                foreach ($session->candidates as $cand) {
                    $service->sendCandidateInvitation($cand, $channels, $tpl);
                    $sentCount++;
                }
            }

            if ($this->inviteTarget === 'specific_candidate' && $this->inviteCandidateId) {
                $candidate = MockInterviewCandidate::findOrFail($this->inviteCandidateId);
                $tpl = $this->customStudentTemplate ?: null;
                $service->sendCandidateInvitation($candidate, $channels, $tpl);
                $sentCount++;
            }

            if ($this->inviteTarget === 'specific_member' && $this->inviteMemberId) {
                $member = MockInterviewSessionMember::findOrFail($this->inviteMemberId);
                $tpl = $member->isChairperson()
                    ? ($this->customChairmanTemplate ?: null)
                    : ($this->customMemberTemplate ?: null);
                $service->sendMemberInvitation($member, $channels, $tpl);
                $sentCount++;
            }

            Toast::dispatch($this, 'success', __("Personalized invitations dispatched to {$sentCount} recipient(s)!"));
            $this->dispatch('modal-close', name: 'invitation-hub-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error dispatching invitations: ').$e->getMessage());
        }
    }

    // ==========================================
    // Bulk Candidate Import
    // ==========================================

    public function openImportModal(): void
    {
        $this->candidateImportFile = null;
        $this->importBatchId = null;
        $this->importCourseId = null;
        $this->dispatch('modal-open', name: 'import-candidates-modal');
    }

    public function downloadCandidateImportTemplate()
    {
        $headers = [
            'Student ID or Email',
            'Attending Mode (offline/online)',
            'Platform (Zoom/Google Meet/Microsoft Teams/Cisco Webex/Others)',
            'Meeting Link',
            'Meeting Password',
            'Room Number',
            'Interview Duration (Minutes)',
            'Candidate Instructions',
        ];

        $sampleRow = [
            'STU-2026-001',
            'offline',
            '',
            '',
            '',
            'Board Room A',
            '30',
            'Bring two copies of DAF',
        ];

        return StudentImportExportService::exportCsv('mock-interview-candidates-import-template', $headers, [$sampleRow]);
    }

    public function importCandidates(): void
    {
        $this->validate([
            'candidateImportFile' => 'required|file|mimes:csv,txt,xlsx|max:10240',
        ]);

        if (! $this->currentSession) {
            return;
        }

        $session = $this->currentSession;

        try {
            $path = $this->candidateImportFile->getRealPath();
            $data = array_map('str_getcsv', file($path));

            if (empty($data) || count($data) < 2) {
                throw new \Exception(__('The uploaded CSV file is empty or missing data rows.'));
            }

            // Remove header row
            array_shift($data);

            $imported = 0;
            $currentSeq = ($session->candidates->max('sequence_number') ?: 0) + 1;
            $startTime = Carbon::parse($session->start_time);
            $duration = $session->default_interview_duration ?: 30;
            $buffer = $session->default_buffer_duration ?: 5;

            DB::transaction(function () use ($data, $session, &$imported, &$currentSeq, &$startTime, $duration, $buffer) {
                foreach ($data as $row) {
                    if (empty($row) || ! isset($row[0]) || blank($row[0])) {
                        continue;
                    }

                    $identifier = trim($row[0]);
                    $user = User::where('student_id', $identifier)
                        ->orWhere('email', $identifier)
                        ->first();

                    if (! $user) {
                        continue;
                    }

                    $mode = strtolower(trim($row[1] ?? 'offline')) === 'online' ? 'online' : 'offline';
                    $platform = trim($row[2] ?? ($mode === 'online' ? 'Zoom' : ''));
                    $meetingLink = trim($row[3] ?? '');
                    $meetingPassword = trim($row[4] ?? '');
                    $room = trim($row[5] ?? ($session->room_number ?? ''));
                    $candDuration = ! empty($row[6]) ? (int) $row[6] : $duration;
                    $instructions = trim($row[7] ?? '');

                    $slotEnd = (clone $startTime)->addMinutes($candDuration);

                    MockInterviewCandidate::updateOrCreate(
                        [
                            'mock_interview_session_id' => $session->id,
                            'user_id' => $user->id,
                        ],
                        [
                            'batch_id' => $this->importBatchId,
                            'course_id' => $this->importCourseId,
                            'sequence_number' => $currentSeq,
                            'scheduled_start_time' => $startTime->format('H:i:s'),
                            'scheduled_end_time' => $slotEnd->format('H:i:s'),
                            'duration_minutes' => $candDuration,
                            'attending_mode' => $mode,
                            'venue_address' => $session->venue_address,
                            'room_number' => $room,
                            'platform' => $mode === 'online' ? ($platform ?: 'Zoom') : null,
                            'meeting_link' => $mode === 'online' ? $meetingLink : null,
                            'meeting_password' => $mode === 'online' ? $meetingPassword : null,
                            'candidate_instructions' => $instructions ?: null,
                            'status' => 'scheduled',
                        ]
                    );

                    $currentSeq++;
                    $startTime = (clone $slotEnd)->addMinutes($buffer);
                    $imported++;
                }

                AuditLogService::log(
                    event: 'mock_interview_candidates_imported',
                    description: "Imported {$imported} candidates into session: {$session->session_code}",
                    auditable: $session
                );
            });

            Toast::dispatch($this, 'success', __("Successfully imported {$imported} candidates into the session!"));
            $this->dispatch('modal-close', name: 'import-candidates-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: ').$e->getMessage());
        }
    }

    // ==========================================
    // Import Sessions Functionality
    // ==========================================

    public function openImportSessionsModal(): void
    {
        $this->sessionImportFile = null;
        $this->dispatch('modal-open', name: 'import-sessions-modal');
    }

    public function downloadSessionImportTemplate()
    {
        $headers = [
            'Session Title',
            'Academic Year',
            'Session Number',
            'Session Date (YYYY-MM-DD)',
            'Start Time (HH:MM)',
            'End Time (HH:MM)',
            'Reporting Time (HH:MM)',
            'Default Duration (Minutes)',
            'Buffer (Minutes)',
            'Venue Name',
            'Venue Address',
            'Room Number',
            'Instructions',
            'Remarks',
            'Status (scheduled/draft/in_progress/completed)',
        ];

        $sampleRow = [
            'UPSC Mock Interview 2026 - Day 1 Session A',
            '2026',
            '1',
            date('Y-m-d', strtotime('+3 days')),
            '09:30',
            '17:30',
            '09:00',
            '30',
            '5',
            'SNTCSSC Campus',
            'Board Room 1, SNTCSSC Administrative Building, Salt Lake, Kolkata',
            'Room 101',
            'Report in formal attire with 2 copies of DAF.',
            'Initial Mock Round',
            'scheduled',
        ];

        return StudentImportExportService::exportCsv('mock-interview-sessions-import-template', $headers, [$sampleRow]);
    }

    public function importSessions(): void
    {
        $this->validate([
            'sessionImportFile' => 'required|file|mimes:csv,txt,xlsx|max:10240',
        ]);

        try {
            $path = $this->sessionImportFile->getRealPath();
            $data = array_map('str_getcsv', file($path));

            if (empty($data) || count($data) < 2) {
                throw new \Exception(__('The uploaded CSV file is empty or missing data rows.'));
            }

            // Remove header row
            array_shift($data);

            $imported = 0;

            DB::transaction(function () use ($data, &$imported) {
                foreach ($data as $row) {
                    if (empty($row) || ! isset($row[0]) || blank($row[0])) {
                        continue;
                    }

                    $title = trim($row[0]);
                    $year = ! empty($row[1]) ? trim($row[1]) : (string) date('Y');
                    $sessionNum = ! empty($row[2]) ? (int) $row[2] : ($imported + 1);
                    $sessionDate = ! empty($row[3]) ? trim($row[3]) : date('Y-m-d');
                    $startTime = ! empty($row[4]) ? trim($row[4]) : '09:30:00';
                    $endTime = ! empty($row[5]) ? trim($row[5]) : '17:30:00';
                    $reportingTime = ! empty($row[6]) ? trim($row[6]) : '09:00:00';
                    $duration = ! empty($row[7]) ? (int) $row[7] : 30;
                    $buffer = ! empty($row[8]) ? (int) $row[8] : 5;
                    $venueName = trim($row[9] ?? 'SNTCSSC Campus');
                    $venueAddress = trim($row[10] ?? 'SNTCSSC Administrative Building, Salt Lake, Kolkata');
                    $room = trim($row[11] ?? '');
                    $instructions = trim($row[12] ?? '');
                    $remarks = trim($row[13] ?? '');
                    $status = ! empty($row[14]) ? strtolower(trim($row[14])) : 'scheduled';

                    $session = MockInterviewSession::create([
                        'title' => $title,
                        'academic_year' => $year,
                        'session_number' => $sessionNum,
                        'session_date' => $sessionDate,
                        'start_time' => strlen($startTime) === 5 ? "{$startTime}:00" : $startTime,
                        'end_time' => strlen($endTime) === 5 ? "{$endTime}:00" : $endTime,
                        'reporting_time' => strlen($reportingTime) === 5 ? "{$reportingTime}:00" : $reportingTime,
                        'default_interview_duration' => $duration,
                        'default_buffer_duration' => $buffer,
                        'venue_name' => $venueName,
                        'venue_address' => $venueAddress,
                        'room_number' => $room,
                        'instructions' => $instructions,
                        'remarks' => $remarks,
                        'status' => in_array($status, ['scheduled', 'in_progress', 'completed', 'draft', 'cancelled']) ? $status : 'scheduled',
                    ]);

                    AuditLogService::log(
                        event: 'mock_interview_session_imported',
                        description: "Imported session: {$session->title} ({$session->session_code})",
                        auditable: $session
                    );

                    $imported++;
                }
            });

            $this->sessionImportFile = null;
            Toast::dispatch($this, 'success', __("Successfully imported {$imported} interview session(s)!"));
            $this->dispatch('modal-close', name: 'import-sessions-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error importing sessions: ').$e->getMessage());
        }
    }

    // ==========================================
    // Import Board Members Functionality
    // ==========================================

    public function openImportBoardMembersModal(): void
    {
        $this->boardMemberImportFile = null;
        $this->dispatch('modal-open', name: 'import-board-members-modal');
    }

    public function downloadBoardMemberImportTemplate()
    {
        $headers = [
            'Full Name',
            'Email Address',
            'Phone Number',
            'Designation / Former Cadre',
            'Organization / Service',
            'Specialization / Domain',
            'Bio / Background',
            'WhatsApp Number',
            'Telegram Chat ID',
            'Status (active/inactive)',
        ];

        $sampleRow = [
            'Dr. Amitabh Roy, IAS (Retd.)',
            'amitabh.roy@example.gov.in',
            '9876543210',
            'Former Additional Chief Secretary',
            'Govt. of West Bengal / Cadre IAS',
            'Public Administration & Governance, DAF Analysis',
            'Over 35 years in administrative services and public policy design.',
            '9876543210',
            '',
            'active',
        ];

        return StudentImportExportService::exportCsv('mock-interview-panelists-import-template', $headers, [$sampleRow]);
    }

    public function importBoardMembers(): void
    {
        $this->validate([
            'boardMemberImportFile' => 'required|file|mimes:csv,txt,xlsx|max:10240',
        ]);

        try {
            $path = $this->boardMemberImportFile->getRealPath();
            $data = array_map('str_getcsv', file($path));

            if (empty($data) || count($data) < 2) {
                throw new \Exception(__('The uploaded CSV file is empty or missing data rows.'));
            }

            // Remove header row
            array_shift($data);

            $imported = 0;

            DB::transaction(function () use ($data, &$imported) {
                foreach ($data as $row) {
                    if (empty($row) || ! isset($row[0]) || blank($row[0])) {
                        continue;
                    }

                    $name = trim($row[0]);
                    $email = ! empty($row[1]) ? trim($row[1]) : null;
                    $phone = ! empty($row[2]) ? trim($row[2]) : null;
                    $designation = trim($row[3] ?? 'Panel Expert');
                    $organization = trim($row[4] ?? 'Civil Services / Govt');
                    $specialization = trim($row[5] ?? 'Governance & Current Affairs');
                    $bio = trim($row[6] ?? '');
                    $whatsapp = ! empty($row[7]) ? trim($row[7]) : $phone;
                    $telegram = ! empty($row[8]) ? trim($row[8]) : null;
                    $status = ! empty($row[9]) && strtolower(trim($row[9])) === 'inactive' ? 'inactive' : 'active';

                    // Find existing by email or phone
                    $member = null;
                    if ($email) {
                        $member = InterviewBoardMember::where('email', $email)->first();
                    }
                    if (! $member && $phone) {
                        $member = InterviewBoardMember::where('phone', $phone)->first();
                    }

                    if ($member) {
                        $member->update([
                            'name' => $name,
                            'designation' => $designation,
                            'organization' => $organization,
                            'specialization' => $specialization,
                            'bio' => $bio ?: $member->bio,
                            'whatsapp_number' => $whatsapp ?: $member->whatsapp_number,
                            'telegram_chat_id' => $telegram ?: $member->telegram_chat_id,
                            'status' => $status,
                        ]);
                    } else {
                        $member = InterviewBoardMember::create([
                            'name' => $name,
                            'email' => $email,
                            'phone' => $phone,
                            'designation' => $designation,
                            'organization' => $organization,
                            'specialization' => $specialization,
                            'bio' => $bio,
                            'whatsapp_number' => $whatsapp,
                            'telegram_chat_id' => $telegram,
                            'status' => $status,
                        ]);
                    }

                    AuditLogService::log(
                        event: 'interview_board_member_imported',
                        description: "Imported panelist: {$member->name} ({$member->member_code})",
                        auditable: $member
                    );

                    $imported++;
                }
            });

            $this->boardMemberImportFile = null;
            Toast::dispatch($this, 'success', __("Successfully imported {$imported} panelist(s)!"));
            $this->dispatch('modal-close', name: 'import-board-members-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error importing panel members: ').$e->getMessage());
        }
    }

    public function exportBoardMembers(string $format = 'csv')
    {
        $headers = [
            'Panel Code',
            'Full Name',
            'Email',
            'Phone',
            'Designation',
            'Organization',
            'Specialization',
            'Status',
            'Sessions Count',
        ];

        $members = InterviewBoardMember::withCount('sessionMembers')->get();
        $rows = [];

        foreach ($members as $m) {
            $rows[] = [
                $m->member_code,
                $m->name,
                $m->email ?: 'N/A',
                $m->phone ?: 'N/A',
                $m->designation ?: 'N/A',
                $m->organization ?: 'N/A',
                $m->specialization ?: 'N/A',
                ucfirst($m->status),
                $m->session_members_count,
            ];
        }

        $filename = 'upsc-mock-interview-panelists-pool-'.date('Ymd');

        if ($format === 'pdf') {
            return StudentImportExportService::exportPdf('UPSC Mock Interview Panelists Directory', $headers, $rows);
        } elseif ($format === 'xlsx') {
            return StudentImportExportService::exportExcel($filename, $headers, $rows);
        }

        return StudentImportExportService::exportCsv($filename, $headers, $rows);
    }

    // ==========================================
    // Export Operations (PDF, Excel, CSV)
    // ==========================================

    public function exportSessions(string $format = 'csv')
    {
        $headers = [
            'Session Code',
            'Session Title',
            'Session #',
            'Year',
            'Date',
            'Hours',
            'Reporting Time',
            'Venue Address',
            'Room',
            'Board Chairperson',
            'Total Candidates',
            'Status',
        ];

        $sessions = MockInterviewSession::with(['sessionMembers.boardMember', 'candidates'])->get();

        $rows = $sessions->map(function ($s) {
            $chair = $s->chairperson();

            return [
                $s->session_code,
                $s->title,
                $s->session_number,
                $s->academic_year,
                $s->formatted_date,
                $s->formatted_time_range,
                $s->formatted_reporting_time ?: 'N/A',
                $s->venue_address,
                $s->room_number ?? 'N/A',
                $chair ? $chair->name : 'Unassigned',
                $s->candidates->count(),
                ucfirst($s->status),
            ];
        })->toArray();

        $filename = 'upsc-mock-interview-sessions';

        if ($format === 'pdf') {
            return StudentImportExportService::exportPdf('UPSC Mock Interview Sessions Roster', $headers, $rows);
        } elseif ($format === 'xlsx') {
            return StudentImportExportService::exportExcel($filename, $headers, $rows);
        }

        return StudentImportExportService::exportCsv($filename, $headers, $rows);
    }

    public function exportSessionTimetable(int $sessionId, string $format = 'pdf')
    {
        $session = MockInterviewSession::with([
            'sessionMembers.boardMember',
            'candidates.user',
            'candidates.batch',
        ])->findOrFail($sessionId);

        if ($format === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.mock-interview-session-pdf', [
                'session' => $session,
            ])->setPaper('a4', 'portrait');

            $safeName = \Illuminate\Support\Str::slug($session->session_code).'-interview-schedule.pdf';

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                $safeName,
                ['Content-Type' => 'application/pdf']
            );
        }

        $headers = [
            'Seq #',
            'Student ID',
            'Candidate Name',
            'Attending Mode',
            'Scheduled Slot',
            'Duration (Mins)',
            'Venue / Meeting Platform',
            'Meeting Link / Room',
            'Status',
            'Interview Score (275)',
            'Board Feedback',
            'Recording Available',
        ];

        $rows = $session->candidates->map(function ($c) use ($session) {
            return [
                $c->sequence_number,
                $c->user->student_id ?: 'N/A',
                $c->user->name,
                strtoupper($c->attending_mode),
                $c->formatted_slot_time,
                $c->duration_minutes,
                $c->isOnline() ? ($c->platform ?: 'Online Video') : ($c->venue_address ?: $session->venue_address),
                $c->isOnline() ? ($c->meeting_link ?: 'N/A') : ($c->room_number ?: 'Board Room'),
                ucfirst($c->status),
                $c->interview_score !== null ? number_format($c->interview_score, 1) : 'Pending',
                $c->board_feedback ?? '',
                $c->hasRecording() ? 'Yes' : 'No',
            ];
        })->toArray();

        $filename = "{$session->session_code}-interview-roster";

        if ($format === 'xlsx') {
            return StudentImportExportService::exportExcel($filename, $headers, $rows);
        }

        return StudentImportExportService::exportCsv($filename, $headers, $rows);
    }

    // ==========================================
    // UPSC Mock Interview Reports Generator
    // ==========================================

    public function openReportsModal(?string $type = null, ?int $sessionId = null): void
    {
        $this->reportType = $type ?: 'student_wise';
        $this->reportSessionId = $sessionId ? (string) $sessionId : '';
        $this->reportAcademicYear = $this->reportAcademicYear ?: (string) date('Y');
        $this->dispatch('modal-open', name: 'mock-interview-reports-modal');
    }

    public function resetReportFilters(): void
    {
        $this->reportAcademicYear = '';
        $this->reportSessionId = '';
        $this->reportMode = '';
        $this->reportStatus = '';
        $this->reportBoardMemberId = '';
        $this->reportEvaluationStatus = '';
        $this->reportStartDate = null;
        $this->reportEndDate = null;
    }

    public function getAvailableAcademicYearsProperty(): array
    {
        $years = MockInterviewSession::distinct()->pluck('academic_year')->filter()->sortDesc()->values()->all();

        return ! empty($years) ? $years : [(string) date('Y'), (string) (date('Y') - 1)];
    }

    public function getReportFilterSessionsProperty()
    {
        return MockInterviewSession::query()
            ->when($this->reportAcademicYear, fn ($q) => $q->where('academic_year', $this->reportAcademicYear))
            ->orderBy('session_date', 'desc')
            ->get(['id', 'session_code', 'title', 'session_date']);
    }

    public function getReportFilterBoardMembersProperty()
    {
        return InterviewBoardMember::orderBy('name')->get(['id', 'name', 'designation']);
    }

    public function buildReportData(): array
    {
        $title = '';
        $subtitle = '';
        $headings = [];
        $rows = [];
        $stats = [];
        $filtersSummary = [];

        if ($this->reportAcademicYear) {
            $filtersSummary[] = 'Academic Cycle: '.$this->reportAcademicYear;
        }
        if ($this->reportSessionId) {
            $session = MockInterviewSession::find($this->reportSessionId);
            if ($session) {
                $filtersSummary[] = "Session: {$session->session_code} - {$session->title}";
            }
        }
        if ($this->reportMode) {
            $filtersSummary[] = 'Mode: '.ucfirst($this->reportMode);
        }
        if ($this->reportStatus) {
            $filtersSummary[] = 'Status: '.ucfirst($this->reportStatus);
        }
        if ($this->reportEvaluationStatus) {
            $filtersSummary[] = 'Evaluation: '.ucfirst($this->reportEvaluationStatus);
        }
        if ($this->reportStartDate || $this->reportEndDate) {
            $filtersSummary[] = 'Date Range: '.($this->reportStartDate ?: 'Beginning').' to '.($this->reportEndDate ?: 'Present');
        }

        $filtersSummaryText = ! empty($filtersSummary) ? implode(' • ', $filtersSummary) : 'All Records (Unfiltered)';

        if ($this->reportType === 'board_member_wise') {
            $title = 'UPSC Mock Interview Panelist Deployment & Performance Report';
            $subtitle = 'Comprehensive analysis of board panel assignments, sessions conducted, and scoring metrics';

            $query = MockInterviewSessionMember::query()
                ->with([
                    'boardMember',
                    'session.candidates',
                ])
                ->when($this->reportAcademicYear, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->where('academic_year', $this->reportAcademicYear)))
                ->when($this->reportSessionId, fn ($q) => $q->where('mock_interview_session_id', $this->reportSessionId))
                ->when($this->reportBoardMemberId, fn ($q) => $q->where('interview_board_member_id', $this->reportBoardMemberId))
                ->when($this->reportStartDate, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '>=', $this->reportStartDate)))
                ->when($this->reportEndDate, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '<=', $this->reportEndDate)))
                ->orderBy('mock_interview_session_id', 'desc')
                ->orderBy('order_index', 'asc');

            $members = $query->get();

            $headings = [
                '#',
                'Panelist Name',
                'Designation & Org',
                'Specialization',
                'Role in Session',
                'Session Code',
                'Session Date',
                'Venue / Room',
                'Total Candidates',
                'Evaluated',
                'Avg Score (/275)',
                'Highest Score',
                'Lowest Score',
            ];

            $rows = $members->map(function ($sm, $idx) {
                $cands = $sm->session->candidates;
                $evalCands = $cands->whereNotNull('interview_score');
                $evalCount = $evalCands->count();
                $avgScore = $evalCount > 0 ? number_format($evalCands->avg('interview_score'), 1) : '—';
                $maxScore = $evalCount > 0 ? number_format($evalCands->max('interview_score'), 1) : '—';
                $minScore = $evalCount > 0 ? number_format($evalCands->min('interview_score'), 1) : '—';

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
                    $avgScore,
                    $maxScore,
                    $minScore,
                ];
            })->all();

            $stats = [
                'Total Deployments' => count($rows),
                'Unique Panelists' => $members->pluck('interview_board_member_id')->unique()->count(),
                'Sessions Chaired' => $members->where('role', MockInterviewSessionMember::ROLE_CHAIRPERSON)->count(),
                'Total Evaluated' => $members->sum(fn ($sm) => $sm->session->candidates->whereNotNull('interview_score')->count()),
            ];
        } elseif ($this->reportType === 'year_wise') {
            $title = 'UPSC Mock Interview Academic Year & Session Summary Report';
            $subtitle = 'Macro-level overview of interview sessions, candidate turnouts, evaluation rubrics, and recordings';

            $query = MockInterviewSession::query()
                ->with([
                    'sessionMembers.boardMember',
                    'candidates',
                ])
                ->when($this->reportAcademicYear, fn ($q) => $q->where('academic_year', $this->reportAcademicYear))
                ->when($this->reportStatus, fn ($q) => $q->where('status', $this->reportStatus))
                ->when($this->reportStartDate, fn ($q) => $q->whereDate('session_date', '>=', $this->reportStartDate))
                ->when($this->reportEndDate, fn ($q) => $q->whereDate('session_date', '<=', $this->reportEndDate))
                ->orderBy('academic_year', 'desc')
                ->orderBy('session_number', 'asc');

            $sessions = $query->get();

            $headings = [
                '#',
                'Academic Cycle',
                'Session Code',
                'Session Title',
                'Date & Hours',
                'Board Chairperson',
                'Panel Size',
                'Enrolled Candidates',
                'In-Person (Offline)',
                'Remote (Online)',
                'Completed Interviews',
                'Evaluated',
                'Avg Score (/275)',
                'Recordings Available',
                'Session Status',
            ];

            $rows = $sessions->map(function ($s, $idx) {
                $evalCands = $s->candidates->whereNotNull('interview_score');
                $evalCount = $evalCands->count();
                $avgScore = $evalCount > 0 ? number_format($evalCands->avg('interview_score'), 1) : '—';

                return [
                    $idx + 1,
                    $s->academic_year,
                    $s->session_code,
                    $s->title,
                    "{$s->formatted_date} (" . Carbon::parse($s->start_time)->format('h:i A') . " - " . Carbon::parse($s->end_time)->format('h:i A') . ")",
                    $s->chairperson_name ?: 'Pending Nomination',
                    $s->sessionMembers->count() . ' Members',
                    $s->candidates->count(),
                    $s->candidates->where('attending_mode', 'offline')->count(),
                    $s->candidates->where('attending_mode', 'online')->count(),
                    $s->candidates->where('status', 'completed')->count(),
                    $evalCount,
                    $avgScore,
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
                'Overall Avg Score' => $evalAll->count() > 0 ? number_format($evalAll->avg('interview_score'), 1) . ' / 275' : '—',
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
                ->when($this->reportAcademicYear, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->where('academic_year', $this->reportAcademicYear)))
                ->when($this->reportSessionId, fn ($q) => $q->where('mock_interview_session_id', $this->reportSessionId))
                ->when($this->reportMode, fn ($q) => $q->where('attending_mode', $this->reportMode))
                ->when($this->reportStatus, fn ($q) => $q->where('status', $this->reportStatus))
                ->when($this->reportEvaluationStatus === 'evaluated', fn ($q) => $q->whereNotNull('interview_score'))
                ->when($this->reportEvaluationStatus === 'pending', fn ($q) => $q->whereNull('interview_score'))
                ->when($this->reportStartDate, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '>=', $this->reportStartDate)))
                ->when($this->reportEndDate, fn ($q) => $q->whereHas('session', fn ($sq) => $sq->whereDate('session_date', '<=', $this->reportEndDate)))
                ->orderBy('mock_interview_session_id', 'desc')
                ->orderBy('sequence_number', 'asc');

            $candidates = $query->get();

            $headings = [
                '#',
                'Student ID',
                'Candidate Name',
                'UPSC Optional',
                'Category',
                'Session Code',
                'Session Date',
                'Slot & Order',
                'Mode',
                'Status',
                'Score (/275)',
                'Panel Feedback',
                'Recording Status',
            ];

            $rows = $candidates->map(function ($c, $idx) {
                return [
                    $idx + 1,
                    $c->user->student_id ?: $c->user->email,
                    $c->user->name,
                    $c->user->studentOtherDetails?->optional_subject ?: '—',
                    $c->user->studentProfile?->category ?: 'General',
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
                'Average Marks' => $evalCands->count() > 0 ? number_format($evalCands->avg('interview_score'), 1) . ' / 275' : '—',
            ];
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'reportType' => $this->reportType,
            'filtersSummary' => $filtersSummaryText,
            'headings' => $headings,
            'rows' => $rows,
            'stats' => $stats,
            'generatedAt' => now()->format('d M Y, h:i A'),
            'generatedBy' => auth()->user()?->name ?? 'Administrator',
        ];
    }

    public function exportReport(string $format = 'pdf')
    {
        $format = strtolower($format);
        if (! in_array($format, ['pdf', 'xlsx', 'csv'])) {
            return null;
        }

        $data = $this->buildReportData();
        $safeName = \Illuminate\Support\Str::slug("mock-interview-{$this->reportType}-report").'-'.now()->format('Ymd-His');

        AuditLogService::log(
            event: 'mock_interview_report_exported',
            description: "Generated and exported {$this->reportType} report in {$format} format.",
            auditable: null
        );

        if ($format === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.mock-interview-report-pdf', [
                'title' => $data['title'],
                'subtitle' => $data['subtitle'],
                'reportType' => $this->reportType,
                'academicYear' => $this->reportAcademicYear ?: 'All Cycles',
                'filtersSummary' => $data['filtersSummary'],
                'stats' => $data['stats'],
                'headings' => $data['headings'],
                'rows' => $data['rows'],
                'generatedAt' => now(),
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                "{$safeName}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        }

        return TableExporter::downloadArray(
            rows: $data['rows'],
            format: $format,
            filename: "mock-interview-{$this->reportType}-report",
            headings: $data['headings'],
            title: $data['title']
        );
    }

    // ==========================================
    // Board Member Master Pool CRUD
    // ==========================================

    public function openPoolMemberModal(?int $id = null): void
    {
        $this->editingPoolMemberId = $id;
        $this->poolMemberAvatar = null;
        $this->removeAvatar = false;

        if ($id) {
            $member = InterviewBoardMember::findOrFail($id);
            $this->existingAvatarUrl = $member->avatar_url;
            $this->existingAvatarPath = $member->avatar;
            $this->poolMemberForm = [
                'name' => $member->name,
                'user_id' => $member->user_id ?? '',
                'email' => $member->email ?? '',
                'phone' => $member->phone ?? '',
                'designation' => $member->designation ?? '',
                'organization' => $member->organization ?? '',
                'specialization' => $member->specialization ?? '',
                'bio' => $member->bio ?? '',
                'status' => $member->status,
            ];
        } else {
            $this->existingAvatarUrl = null;
            $this->existingAvatarPath = null;
            $this->poolMemberForm = [
                'name' => '',
                'user_id' => '',
                'email' => '',
                'phone' => '',
                'designation' => 'Former Civil Servant / Domain Expert',
                'organization' => 'Govt of West Bengal / UPSC',
                'specialization' => 'Governance, DAF, Ethics & Current Affairs',
                'bio' => '',
                'status' => 'active',
            ];
        }

        $this->dispatch('modal-open', name: 'board-member-form-modal');
    }

    public function savePoolMember(): void
    {
        $this->validate([
            'poolMemberForm.name' => 'required|string|max:255',
            'poolMemberForm.email' => 'nullable|email|max:255',
            'poolMemberForm.phone' => 'nullable|string|max:32',
            'poolMemberForm.designation' => 'nullable|string|max:255',
            'poolMemberForm.organization' => 'nullable|string|max:255',
            'poolMemberAvatar' => 'nullable|image|max:2048',
        ]);

        try {
            DB::transaction(function () {
                $payload = array_merge($this->poolMemberForm, [
                    'user_id' => $this->poolMemberForm['user_id'] ? (int) $this->poolMemberForm['user_id'] : null,
                ]);

                if ($this->removeAvatar && $this->existingAvatarPath) {
                    if (Storage::disk('public')->exists($this->existingAvatarPath)) {
                        Storage::disk('public')->delete($this->existingAvatarPath);
                    }
                    $payload['avatar'] = null;
                } elseif ($this->poolMemberAvatar) {
                    if ($this->existingAvatarPath && Storage::disk('public')->exists($this->existingAvatarPath)) {
                        Storage::disk('public')->delete($this->existingAvatarPath);
                    }
                    $filename = $this->generateFileName('panel_member_avatar', $this->poolMemberAvatar, auth()->id() ?? 1);
                    $avatarPath = $this->poolMemberAvatar->storeAs('mock-interviews/avatars', $filename, 'public');
                    $payload['avatar'] = $avatarPath;
                }

                if ($this->editingPoolMemberId) {
                    $member = InterviewBoardMember::findOrFail($this->editingPoolMemberId);
                    $member->update($payload);
                    Toast::dispatch($this, 'success', __('Panelist profile updated successfully!'));
                } else {
                    $member = InterviewBoardMember::create($payload);
                    Toast::dispatch($this, 'success', __('New Panelist added to Board Member directory!'));
                }

                AuditLogService::log(
                    event: 'mock_interview_panelist_saved',
                    description: "Saved board member: {$member->name}",
                    auditable: $member
                );
            });

            $this->dispatch('modal-close', name: 'board-member-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error saving panelist: ').$e->getMessage());
        }
    }

    // ==========================================
    // Generic Delete Confirmation Flow
    // ==========================================

    public function confirmDelete(string $type, int $id, string $title): void
    {
        $this->deleteActionType = $type;
        $this->deleteTargetId = $id;
        $this->deleteTargetTitle = $title;
        $this->dispatch('modal-open', name: 'delete-confirm-modal');
    }

    public function executeDelete(): void
    {
        if (! $this->deleteActionType || ! $this->deleteTargetId) {
            return;
        }

        try {
            DB::transaction(function () {
                if ($this->deleteActionType === 'session') {
                    $session = MockInterviewSession::findOrFail($this->deleteTargetId);
                    $session->delete();
                    Toast::dispatch($this, 'success', __('Mock Interview Session soft-deleted.'));
                    if ($this->selectedSessionId === $session->id) {
                        $this->selectedSessionId = null;
                        $this->activeView = 'sessions';
                    }
                } elseif ($this->deleteActionType === 'session_member') {
                    $sm = MockInterviewSessionMember::findOrFail($this->deleteTargetId);
                    $sm->delete();
                    Toast::dispatch($this, 'success', __('Board member removed from session.'));
                } elseif ($this->deleteActionType === 'candidate') {
                    $candidate = MockInterviewCandidate::findOrFail($this->deleteTargetId);
                    $candidate->delete();
                    Toast::dispatch($this, 'success', __('Candidate student removed from session.'));
                } elseif ($this->deleteActionType === 'pool_member') {
                    $member = InterviewBoardMember::findOrFail($this->deleteTargetId);
                    $member->delete();
                    Toast::dispatch($this, 'success', __('Panelist removed from pool directory.'));
                }
            });

            $this->dispatch('modal-close', name: 'delete-confirm-modal');
            $this->deleteActionType = null;
            $this->deleteTargetId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Error deleting record: ').$e->getMessage());
        }
    }
};
?>

<div class="space-y-6">
    <!-- Top Header Banner -->
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="award" class="h-6 w-6"/>
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('UPSC Mock Interview Management') }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ __('Manage annual 5-member interview boards, offline/online sessions, personalized multi-channel invitations, and candidate recording vault.') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Top Right Actions -->
        <div class="flex flex-wrap items-center gap-2">
            @if ($activeView === 'session_detail')
                <x-ui.button variant="outline" size="sm" wire:click="setView('sessions')" class="cursor-pointer gap-1.5">
                    <x-icon name="arrow-left" class="h-4 w-4"/>
                    <span>{{ __('Back to Sessions') }}</span>
                </x-ui.button>
            @endif

            <!-- Navigation Tabs Pills -->
            <div class="inline-flex rounded-lg border border-border bg-card p-1">
                <button
                    type="button"
                    wire:click="setView('sessions')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors cursor-pointer {{ $activeView === 'sessions' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('Sessions') }}
                </button>
                <button
                    type="button"
                    wire:click="setView('board_members')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors cursor-pointer {{ $activeView === 'board_members' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('Board Members Pool') }}
                </button>
                <button
                    type="button"
                    wire:click="setView('recordings_vault')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors cursor-pointer {{ $activeView === 'recordings_vault' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('Recordings Vault') }}
                </button>
            </div>

            <!-- Import Sessions Button -->
            <x-ui.button variant="outline" size="sm" wire:click="openImportSessionsModal" class="h-9 gap-1.5 cursor-pointer">
                <x-icon name="upload" class="h-4 w-4 text-emerald-500"/>
                <span>{{ __('Import Sessions') }}</span>
            </x-ui.button>

            <!-- Reports & Analytics Button -->
            <x-ui.button variant="outline" size="sm" wire:click="openReportsModal()" class="h-9 gap-1.5 cursor-pointer border-indigo-500/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/10">
                <x-icon name="chart-bar" class="h-4 w-4 text-indigo-500"/>
                <span>{{ __('Reports & Analytics') }}</span>
            </x-ui.button>

            <!-- Export Sessions Dropdown -->
            <x-ui.dropdown align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5 cursor-pointer">
                        <x-icon name="download" class="h-4 w-4"/>
                        <span>{{ __('Export') }}</span>
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 opacity-60"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-slot:menu>
                    <button type="button" wire:click="exportSessions('csv')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-text" class="h-3.5 w-3.5 text-blue-500"/>
                        <span>{{ __('Export Sessions as CSV') }}</span>
                    </button>
                    <button type="button" wire:click="exportSessions('xlsx')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 text-emerald-500"/>
                        <span>{{ __('Export Sessions as Excel') }}</span>
                    </button>
                    <button type="button" wire:click="exportSessions('pdf')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                        <x-icon name="file-down" class="h-3.5 w-3.5 text-rose-500"/>
                        <span>{{ __('Export Sessions as PDF') }}</span>
                    </button>
                </x-slot:menu>
            </x-ui.dropdown>

            @can('mock_interviews.create')
                <x-ui.button size="sm" wire:click="openSessionModal()" class="gap-1.5 cursor-pointer bg-primary text-primary-foreground">
                    <x-icon name="plus" class="h-4 w-4"/>
                    <span>{{ __('New Interview Session') }}</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Stat Cards Overview -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Total Sessions') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold">{{ $this->dashboardStats['total_sessions'] }}</span>
                <span class="text-xs text-blue-500 font-semibold">{{ $this->dashboardStats['upcoming_sessions'] }} {{ __('Upcoming') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Total Aspirants') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold">{{ $this->dashboardStats['total_candidates'] }}</span>
                <span class="text-xs text-emerald-500 font-semibold">{{ $this->dashboardStats['offline_candidates'] }} {{ __('Offline') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Online Aspirants') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ $this->dashboardStats['online_candidates'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('Remote Slots') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Board Members') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold">{{ $this->dashboardStats['board_members_count'] }}</span>
                <span class="text-xs text-amber-500 font-semibold">{{ __('Panel Pool') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Completed') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-emerald-600">{{ $this->dashboardStats['completed_sessions'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('Evaluated') }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-3 shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Video Vault') }}</span>
            <div class="mt-1 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-sky-600">{{ $this->dashboardStats['available_recordings'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('Recordings') }}</span>
            </div>
        </div>
    </div>

    <!-- MAIN VIEW 1: SESSIONS LIST -->
    @if ($activeView === 'sessions')
        <div class="space-y-4">
            <!-- Filter & Search Bar -->
            <div class="flex flex-col gap-3 rounded-xl border border-border bg-card p-3 shadow-xs md:flex-row md:items-center md:justify-between">
                <div class="flex flex-1 flex-wrap items-center gap-2">
                    <div class="relative w-full md:w-64">
                        <x-icon name="search" class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"/>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search session, venue, panel...') }}"
                            class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-xs placeholder:text-muted-foreground focus:border-primary focus:outline-none"
                        />
                    </div>

                    <select wire:model.live="statusFilter" class="h-9 rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('All Statuses') }}</option>
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="in_progress">{{ __('In Progress') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </select>

                    <select wire:model.live="yearFilter" class="h-9 rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('All Years') }}</option>
                        <option value="2026">2026</option>
                        <option value="2025">2025</option>
                        <option value="2024">2024</option>
                    </select>

                    <input
                        type="date"
                        wire:model.live="dateFrom"
                        placeholder="From Date"
                        class="h-9 rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none"
                    />
                    <input
                        type="date"
                        wire:model.live="dateTo"
                        placeholder="To Date"
                        class="h-9 rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground">{{ __('Per page:') }}</span>
                    <select wire:model.live="perPage" class="h-9 rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
            </div>

            <!-- Sessions Table -->
            <div class="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-border bg-muted/40 text-muted-foreground">
                            <tr>
                                <th class="p-3 font-semibold">{{ __('Session Info') }}</th>
                                <th class="p-3 font-semibold cursor-pointer" wire:click="sortBy('session_date')">
                                    <div class="flex items-center gap-1">
                                        <span>{{ __('Date & Hours') }}</span>
                                        @if ($sortField === 'session_date')
                                            <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3.5 w-3.5"/>
                                        @endif
                                    </div>
                                </th>
                                <th class="p-3 font-semibold">{{ __('Board Composition (5 Members)') }}</th>
                                <th class="p-3 font-semibold">{{ __('Aspirants') }}</th>
                                <th class="p-3 font-semibold">{{ __('Venue / Room') }}</th>
                                <th class="p-3 font-semibold">{{ __('Status') }}</th>
                                <th class="p-3 font-semibold text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($this->sessionsList as $session)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="p-3">
                                        <div class="flex flex-col gap-0.5">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-xs font-semibold text-primary">{{ $session->session_code }}</span>
                                                <span class="rounded-full bg-secondary px-1.5 py-0.2 text-[10px] text-muted-foreground">Session #{{ $session->session_number }}</span>
                                            </div>
                                            <span class="font-medium text-foreground">{{ $session->title }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ __('Cycle:') }} {{ $session->academic_year }}</span>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="font-medium text-foreground">{{ $session->formatted_date }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ $session->formatted_time_range }}</span>
                                            @if ($session->formatted_reporting_time)
                                                <span class="text-[10px] text-amber-600 dark:text-amber-400 font-medium">{{ __('Reporting:') }} {{ $session->formatted_reporting_time }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        @php
                                            $chair = $session->chairperson();
                                            $memberCount = $session->sessionMembers->count();
                                        @endphp
                                        <div class="flex flex-col gap-1">
                                            @if ($chair)
                                                <div class="flex items-center gap-1.5">
                                                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 text-[10px] font-bold" title="Board Chairperson">
                                                        ♔
                                                    </span>
                                                    <span class="font-medium text-foreground">{{ $chair->name }}</span>
                                                    <span class="rounded bg-amber-500/10 px-1 py-0.2 text-[9px] font-semibold text-amber-600">Chair</span>
                                                </div>
                                            @else
                                                <span class="text-[11px] text-muted-foreground italic">{{ __('No Chairperson assigned') }}</span>
                                            @endif
                                            <span class="text-[11px] text-muted-foreground">
                                                {{ $memberCount }} / 5 {{ __('Members configured') }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        @php
                                            $totalCands = $session->candidates->count();
                                            $offlineCands = $session->candidates->where('attending_mode', 'offline')->count();
                                            $onlineCands = $session->candidates->where('attending_mode', 'online')->count();
                                        @endphp
                                        <div class="flex flex-col gap-0.5">
                                            <span class="font-semibold text-foreground">{{ $totalCands }} {{ __('Aspirants') }}</span>
                                            <div class="flex items-center gap-1">
                                                <span class="rounded-full bg-blue-500/10 px-1.5 py-0.2 text-[10px] font-medium text-blue-600">{{ $offlineCands }} Offline</span>
                                                <span class="rounded-full bg-purple-500/10 px-1.5 py-0.2 text-[10px] font-medium text-purple-600">{{ $onlineCands }} Online</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-3 max-w-[200px]">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="truncate text-foreground font-medium" title="{{ $session->venue_address }}">{{ $session->venue_address }}</span>
                                            @if ($session->room_number)
                                                <span class="text-[11px] text-muted-foreground">{{ __('Room:') }} {{ $session->room_number }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold text-{{ $session->status_badge_color }}-700 bg-{{ $session->status_badge_color }}-500/10 dark:text-{{ $session->status_badge_color }}-300">
                                            {{ ucfirst($session->status) }}
                                        </span>
                                    </td>
                                    <td class="p-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <!-- Manage Session Workspace Button -->
                                            <x-ui.button
                                                size="sm"
                                                variant="outline"
                                                wire:click="setView('session_detail', {{ $session->id }})"
                                                class="h-7 px-2 text-xs gap-1 cursor-pointer"
                                                title="Open Board & Candidate Management"
                                            >
                                                <x-icon name="users" class="h-3.5 w-3.5 text-primary"/>
                                                <span>{{ __('Manage') }}</span>
                                            </x-ui.button>

                                            <!-- PDF Timetable Export -->
                                            <button
                                                type="button"
                                                wire:click="exportSessionTimetable({{ $session->id }}, 'pdf')"
                                                class="flex h-7 w-7 items-center justify-center rounded-md border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                                title="Download Printable PDF Timetable"
                                            >
                                                <x-icon name="file-down" class="h-3.5 w-3.5 text-rose-500"/>
                                            </button>

                                            <!-- Invitations Hub Modal -->
                                            <button
                                                type="button"
                                                wire:click="openInvitationsForSession({{ $session->id }})"
                                                class="flex h-7 w-7 items-center justify-center rounded-md border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                                title="Send Personalized Invitations"
                                            >
                                                <x-icon name="send" class="h-3.5 w-3.5 text-sky-500"/>
                                            </button>

                                            <!-- Session Report & Analytics -->
                                            <button
                                                type="button"
                                                wire:click="openReportsModal('student_wise', {{ $session->id }})"
                                                class="flex h-7 w-7 items-center justify-center rounded-md border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                                title="View Session Report & Analytics"
                                            >
                                                <x-icon name="file-text" class="h-3.5 w-3.5 text-indigo-500"/>
                                            </button>

                                            <!-- Edit Session -->
                                            @can('mock_interviews.edit')
                                                <button
                                                    type="button"
                                                    wire:click="openSessionModal({{ $session->id }})"
                                                    class="flex h-7 w-7 items-center justify-center rounded-md border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                                    title="Edit Session Details"
                                                >
                                                    <x-icon name="edit" class="h-3.5 w-3.5 text-amber-500"/>
                                                </button>
                                            @endcan

                                            <!-- Delete Session -->
                                            @can('mock_interviews.delete')
                                                <button
                                                    type="button"
                                                    wire:click="confirmDelete('session', {{ $session->id }}, '{{ $session->title }} ({{ $session->session_code }})')"
                                                    class="flex h-7 w-7 items-center justify-center rounded-md border border-input text-muted-foreground hover:bg-destructive/10 hover:text-destructive cursor-pointer"
                                                    title="Delete Session"
                                                >
                                                    <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                                                </button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-muted-foreground">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <x-icon name="award" class="h-8 w-8 text-muted-foreground/50"/>
                                            <p class="font-medium">{{ __('No Mock Interview sessions found matching the criteria.') }}</p>
                                            <x-ui.button size="sm" wire:click="openSessionModal()" class="cursor-pointer mt-1">
                                                <x-icon name="plus" class="h-4 w-4 mr-1"/>
                                                <span>{{ __('Create First Session') }}</span>
                                            </x-ui.button>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($this->sessionsList->hasPages())
                    <div class="border-t border-border p-3">
                        {{ $this->sessionsList->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- MAIN VIEW 2: SESSION DETAIL & WORKSPACE -->
    @if ($activeView === 'session_detail' && $this->currentSession)
        @php $session = $this->currentSession; @endphp
        <div class="space-y-6">
            <!-- Session Banner Header -->
            <div class="rounded-xl border border-border bg-card p-5 shadow-xs">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-sm font-bold text-primary">{{ $session->session_code }}</span>
                            <span class="rounded-full bg-secondary px-2 py-0.5 text-xs text-muted-foreground">Session #{{ $session->session_number }}</span>
                            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ $session->academic_year }}</span>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold text-{{ $session->status_badge_color }}-700 bg-{{ $session->status_badge_color }}-500/10">
                                {{ ucfirst($session->status) }}
                            </span>
                        </div>
                        <h2 class="mt-1 text-xl font-bold tracking-tight text-foreground">{{ $session->title }}</h2>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span class="flex items-center gap-1">
                                <x-icon name="calendar" class="h-3.5 w-3.5"/>
                                <strong>{{ $session->formatted_date }}</strong>
                            </span>
                            <span class="flex items-center gap-1">
                                <x-icon name="clock" class="h-3.5 w-3.5"/>
                                {{ $session->formatted_time_range }} ({{ $session->default_interview_duration }}m slot / {{ $session->default_buffer_duration }}m break)
                            </span>
                            <span class="flex items-center gap-1">
                                <x-icon name="map-pin" class="h-3.5 w-3.5"/>
                                {{ $session->venue_address }} {{ $session->room_number ? "[Room: {$session->room_number}]" : '' }}
                            </span>
                        </div>
                        @if ($session->instructions)
                            <div class="mt-2 text-xs bg-muted/40 p-2 rounded-md border border-border/50 text-foreground/90">
                                <strong>{{ __('Instructions:') }}</strong> {{ $session->instructions }}
                            </div>
                        @endif
                    </div>

                    <!-- Session Action Toolbar -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Auto-Generate Slots Button -->
                        <x-ui.button size="sm" variant="outline" wire:click="openAutoSlotsModal()" class="cursor-pointer gap-1.5 h-8 text-xs border-primary/30 text-primary hover:bg-primary/5">
                            <x-icon name="sparkles" class="h-3.5 w-3.5"/>
                            <span>{{ __('Auto-Generate Slots') }}</span>
                        </x-ui.button>

                        <!-- Invitations Hub Button -->
                        <x-ui.button size="sm" variant="outline" wire:click="openInvitationsModal('all')" class="cursor-pointer gap-1.5 h-8 text-xs border-sky-500/30 text-sky-600 hover:bg-sky-500/5">
                            <x-icon name="send" class="h-3.5 w-3.5"/>
                            <span>{{ __('Invitations Hub & Preview') }}</span>
                        </x-ui.button>

                        <!-- Import Candidates Button -->
                        <x-ui.button size="sm" variant="outline" wire:click="openImportModal()" class="cursor-pointer gap-1.5 h-8 text-xs">
                            <x-icon name="upload" class="h-3.5 w-3.5"/>
                            <span>{{ __('Import CSV/Excel') }}</span>
                        </x-ui.button>

                        <!-- PDF Timetable Export -->
                        <x-ui.dropdown align="end">
                            <x-slot:trigger>
                                <x-ui.button variant="outline" size="sm" class="h-8 text-xs gap-1 cursor-pointer">
                                    <x-icon name="download" class="h-3.5 w-3.5 text-rose-500"/>
                                    <span>{{ __('Timetable') }}</span>
                                    <x-icon name="chevron-down" class="h-3 w-3 opacity-60"/>
                                </x-ui.button>
                            </x-slot:trigger>
                            <x-slot:menu>
                                <button type="button" wire:click="exportSessionTimetable({{ $session->id }}, 'pdf')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                                    <x-icon name="file-down" class="h-3.5 w-3.5 text-rose-500"/>
                                    <span>{{ __('Export Printable PDF Timetable') }}</span>
                                </button>
                                <button type="button" wire:click="exportSessionTimetable({{ $session->id }}, 'xlsx')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                                    <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 text-emerald-500"/>
                                    <span>{{ __('Export Schedule as Excel') }}</span>
                                </button>
                                <button type="button" wire:click="exportSessionTimetable({{ $session->id }}, 'csv')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                                    <x-icon name="file-text" class="h-3.5 w-3.5 text-blue-500"/>
                                    <span>{{ __('Export Schedule as CSV') }}</span>
                                </button>
                            </x-slot:menu>
                        </x-ui.dropdown>

                        <!-- Session Reports Button -->
                        <x-ui.button variant="outline" size="sm" wire:click="openReportsModal('student_wise', {{ $session->id }})" class="h-8 gap-1.5 cursor-pointer text-xs border-indigo-500/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/10">
                            <x-icon name="file-text" class="h-3.5 w-3.5"/>
                            <span>{{ __('Reports') }}</span>
                        </x-ui.button>

                        <!-- Edit Session Button -->
                        <button
                            type="button"
                            wire:click="openSessionModal({{ $session->id }})"
                            class="flex h-8 w-8 items-center justify-center rounded-md border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                            title="Edit Session Settings"
                        >
                            <x-icon name="settings" class="h-4 w-4"/>
                        </button>
                    </div>
                </div>
            </div>

            <!-- SECTION A: BOARD COMPOSITION (5-Member Board Setup) -->
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 font-bold">
                            ♔
                        </span>
                        <div>
                            <h3 class="font-bold text-base text-foreground">{{ __('Interview Board Members (5-Member Board)') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Configure 1 Board Chairperson and other esteemed panel members for conducting the interviews.') }}</p>
                        </div>
                    </div>

                    <x-ui.button size="sm" wire:click="openSessionMemberModal()" class="cursor-pointer gap-1.5 h-8 text-xs bg-amber-600 hover:bg-amber-700 text-white">
                        <x-icon name="plus" class="h-3.5 w-3.5"/>
                        <span>{{ __('Add Board Member') }}</span>
                    </x-ui.button>
                </div>

                <!-- Board Members Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @forelse ($session->sessionMembers as $member)
                        @php
                            $isChair = $member->isChairperson();
                            $panelist = $member->boardMember;
                        @endphp
                        <div class="relative rounded-lg border {{ $isChair ? 'border-amber-400 dark:border-amber-600/70 bg-amber-500/5' : 'border-border bg-card' }} p-3.5 shadow-2xs">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-start gap-2.5">
                                    <div class="relative">
                                        @if ($panelist->avatar_url)
                                            <img src="{{ $panelist->avatar_url }}" alt="{{ $panelist->name }}" class="h-10 w-10 rounded-full object-cover border border-border"/>
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-full font-bold text-xs {{ $isChair ? 'bg-amber-500 text-white' : 'bg-primary/10 text-primary' }}">
                                                {{ $panelist->initials }}
                                            </div>
                                        @endif
                                        @if ($isChair)
                                            <span class="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-white text-[9px] shadow-xs" title="Chairperson">
                                                ♔
                                            </span>
                                        @endif
                                    </div>
                                    <div class="space-y-0.5">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-semibold text-xs text-foreground">{{ $panelist->name }}</span>
                                            @if ($isChair)
                                                <span class="rounded bg-amber-500/20 text-amber-700 dark:text-amber-300 text-[9px] font-bold px-1.5 py-0.2">CHAIRPERSON</span>
                                            @else
                                                <span class="rounded bg-secondary text-muted-foreground text-[9px] font-medium px-1.5 py-0.2">Member #{{ $member->order_index }}</span>
                                            @endif
                                        </div>
                                        <p class="text-[11px] text-muted-foreground line-clamp-1">{{ $panelist->designation ?: 'Board Panelist' }}</p>
                                        @if ($panelist->organization)
                                            <p class="text-[10px] text-muted-foreground/80 font-medium">{{ $panelist->organization }}</p>
                                        @endif
                                        <div class="flex items-center gap-2 pt-1 text-[10px]">
                                            @if ($member->invitation_sent_at)
                                                <span class="text-emerald-600 font-medium flex items-center gap-0.5">
                                                    <x-icon name="check" class="h-3 w-3"/>
                                                    {{ __('Invited') }} ({{ implode(', ', $member->invitation_channels ?? []) }})
                                                </span>
                                            @else
                                                <span class="text-muted-foreground italic">{{ __('Invitation pending') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex items-center gap-1">
                                    @if (! $isChair)
                                        <button
                                            type="button"
                                            wire:click="setAsChairperson({{ $member->id }})"
                                            class="flex h-6 px-1.5 items-center justify-center rounded border border-input text-[10px] text-muted-foreground hover:bg-amber-500/10 hover:text-amber-600 cursor-pointer"
                                            title="Designate as Chairperson"
                                        >
                                            <span class="mr-0.5">♔</span> {{ __('Make Chair') }}
                                        </button>
                                    @endif

                                    <!-- Send Invitation to this Member -->
                                    <button
                                        type="button"
                                        wire:click="openInvitationsModal('specific_member', {{ $member->id }})"
                                        class="flex h-6 w-6 items-center justify-center rounded border border-input text-muted-foreground hover:bg-sky-500/10 hover:text-sky-600 cursor-pointer"
                                        title="Send Invitation"
                                    >
                                        <x-icon name="send" class="h-3 w-3"/>
                                    </button>

                                    <!-- Remove Member -->
                                    <button
                                        type="button"
                                        wire:click="confirmDelete('session_member', {{ $member->id }}, '{{ $panelist->name }}')"
                                        class="flex h-6 w-6 items-center justify-center rounded border border-input text-muted-foreground hover:bg-destructive/10 hover:text-destructive cursor-pointer"
                                        title="Remove Member"
                                    >
                                        <x-icon name="trash-2" class="h-3 w-3"/>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full rounded-lg border border-dashed border-border p-6 text-center text-muted-foreground">
                            <p class="text-xs">{{ __('No board members assigned to this session yet.') }}</p>
                            <x-ui.button size="sm" variant="outline" wire:click="openSessionMemberModal()" class="cursor-pointer mt-2 text-xs">
                                <x-icon name="plus" class="h-3.5 w-3.5 mr-1"/>
                                <span>{{ __('Assign Chairperson & Members') }}</span>
                            </x-ui.button>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- SECTION B: CANDIDATE INTERVIEW SCHEDULE & APPEARANCE ORDER TABLE -->
            <div class="rounded-xl border border-border bg-card shadow-xs space-y-3">
                <div class="flex flex-col gap-2 p-4 border-b border-border sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-bold text-base text-foreground">{{ __('Attending Aspirants & Interview Timetable') }}</h3>
                            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                                {{ $session->candidates->count() }} {{ __('Enrolled Candidates') }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ __('Ordered sequence of candidate appearances. Duration: 25-30m each. Supports in-person offline and online remote platforms.') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button size="sm" wire:click="openCandidateModal()" class="cursor-pointer gap-1.5 h-8 text-xs bg-primary text-primary-foreground">
                            <x-icon name="plus" class="h-3.5 w-3.5"/>
                            <span>{{ __('Add Aspirant') }}</span>
                        </x-ui.button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-border bg-muted/40 text-muted-foreground">
                            <tr>
                                <th class="p-3 font-semibold text-center w-16">{{ __('Order') }}</th>
                                <th class="p-3 font-semibold">{{ __('Candidate Name & ID') }}</th>
                                <th class="p-3 font-semibold">{{ __('Attending Mode') }}</th>
                                <th class="p-3 font-semibold">{{ __('Scheduled Slot') }}</th>
                                <th class="p-3 font-semibold">{{ __('Platform / Venue') }}</th>
                                <th class="p-3 font-semibold">{{ __('Status') }}</th>
                                <th class="p-3 font-semibold text-center">{{ __('Evaluation (275)') }}</th>
                                <th class="p-3 font-semibold">{{ __('Interview Recording') }}</th>
                                <th class="p-3 font-semibold text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($session->candidates->sortBy('sequence_number') as $candidate)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <!-- Sequence & Reordering -->
                                    <td class="p-3 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <span class="font-mono font-bold text-foreground text-xs">#{{ $candidate->sequence_number }}</span>
                                            <div class="flex flex-col">
                                                <button
                                                    type="button"
                                                    wire:click="moveCandidateSequence({{ $candidate->id }}, 'up')"
                                                    class="text-muted-foreground hover:text-foreground cursor-pointer {{ $loop->first ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                    {{ $loop->first ? 'disabled' : '' }}
                                                    title="Move Up"
                                                >
                                                    <x-icon name="chevron-up" class="h-3 w-3"/>
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="moveCandidateSequence({{ $candidate->id }}, 'down')"
                                                    class="text-muted-foreground hover:text-foreground cursor-pointer {{ $loop->last ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                    {{ $loop->last ? 'disabled' : '' }}
                                                    title="Move Down"
                                                >
                                                    <x-icon name="chevron-down" class="h-3 w-3"/>
                                                </button>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Candidate Info -->
                                    <td class="p-3">
                                        <div class="flex items-start gap-2.5">
                                            <div class="h-8 w-8 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0 select-none border border-primary/20">
                                                {{ strtoupper(substr($candidate->user->name, 0, 2)) }}
                                            </div>
                                            <div class="flex flex-col gap-0.5 min-w-0">
                                                <div class="flex items-center gap-1.5 flex-wrap">
                                                    <!-- Clickable Candidate Name to open detailed modal -->
                                                    <button
                                                        type="button"
                                                        wire:click="viewCandidateDetails({{ $candidate->id }})"
                                                        class="font-semibold text-foreground text-xs hover:text-primary hover:underline cursor-pointer text-left truncate max-w-[150px]"
                                                        title="{{ __('View Aspirant Profile & Detailed Information (Modal)') }}"
                                                    >
                                                        {{ $candidate->user->name }}
                                                    </button>

                                                    <!-- Check Details Icon Button (Modal) -->
                                                    <button
                                                        type="button"
                                                        wire:click="viewCandidateDetails({{ $candidate->id }})"
                                                        class="inline-flex items-center justify-center h-5 w-5 rounded bg-primary/10 text-primary hover:bg-primary/20 transition-colors cursor-pointer"
                                                        title="{{ __('Check Student Detailed Information Modal') }}"
                                                    >
                                                        <x-icon name="user" class="h-3 w-3"/>
                                                    </button>

                                                    <!-- Open in New Tab Link -->
                                                    <a
                                                        href="{{ route('admin.students.index', ['q' => $candidate->user->student_id ?: $candidate->user->email, 'current_team' => auth()->user()->currentTeam?->slug ?? 'default']) }}"
                                                        target="_blank"
                                                        class="inline-flex items-center justify-center h-5 w-5 rounded text-muted-foreground hover:text-primary hover:bg-muted transition-colors"
                                                        title="{{ __('Open Student Profile in New Tab') }}"
                                                    >
                                                        <x-icon name="external-link" class="h-3 w-3"/>
                                                    </a>
                                                </div>

                                                <div class="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                                    <span class="font-mono">{{ $candidate->user->student_id ?: $candidate->user->email }}</span>
                                                    @if ($candidate->user->studentOtherDetails?->optional_subject)
                                                        <span class="inline-flex items-center rounded bg-amber-500/10 px-1.5 py-0.2 text-[10px] font-medium text-amber-700 dark:text-amber-400">
                                                            {{ $candidate->user->studentOtherDetails->optional_subject }}
                                                        </span>
                                                    @endif
                                                </div>

                                                @if ($candidate->batch)
                                                    <span class="text-[10px] text-primary/80 font-medium">{{ $candidate->batch->name }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Mode -->
                                    <td class="p-3">
                                        @if ($candidate->isOnline())
                                            <span class="inline-flex items-center gap-1 rounded-full bg-purple-500/10 px-2 py-0.5 text-[10px] font-semibold text-purple-600 dark:text-purple-400">
                                                <x-icon name="video" class="h-3 w-3"/>
                                                {{ __('Online Remote') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-2 py-0.5 text-[10px] font-semibold text-blue-600 dark:text-blue-400">
                                                <x-icon name="map-pin" class="h-3 w-3"/>
                                                {{ __('In-Person Offline') }}
                                            </span>
                                        @endif
                                    </td>

                                    <!-- Slot Timings -->
                                    <td class="p-3">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="font-bold text-foreground text-xs">{{ $candidate->formatted_slot_time }}</span>
                                            <span class="text-[10px] text-muted-foreground">
                                                {{ __('Reporting:') }} {{ Carbon::parse($candidate->scheduled_start_time)->subMinutes(20)->format('h:i A') }}
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Platform / Venue -->
                                    <td class="p-3 max-w-[200px]">
                                        @if ($candidate->isOnline())
                                            <div class="flex flex-col gap-0.5">
                                                <span class="font-semibold text-foreground">{{ $candidate->platform ?: 'Online' }}</span>
                                                @if ($candidate->meeting_link)
                                                    <a href="{{ $candidate->meeting_link }}" target="_blank" class="truncate text-[11px] text-primary hover:underline flex items-center gap-1">
                                                        <span>{{ __('Join Link') }}</span>
                                                        <x-icon name="external-link" class="h-3 w-3"/>
                                                    </a>
                                                @endif
                                                @if ($candidate->meeting_password)
                                                    <span class="text-[10px] text-muted-foreground font-mono">{{ __('Pwd:') }} {{ $candidate->meeting_password }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <div class="flex flex-col gap-0.5">
                                                <span class="truncate text-foreground text-xs" title="{{ $candidate->getEffectiveVenueAddress() }}">{{ $candidate->getEffectiveVenueAddress() }}</span>
                                                @if ($candidate->room_number)
                                                    <span class="text-[11px] text-muted-foreground">{{ __('Room:') }} {{ $candidate->room_number }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>

                                    <!-- Status -->
                                    <td class="p-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold text-{{ $candidate->status_badge_color }}-700 bg-{{ $candidate->status_badge_color }}-500/10">
                                            {{ ucfirst($candidate->status) }}
                                        </span>
                                    </td>

                                    <!-- Evaluation & Score -->
                                    <td class="p-3 text-center">
                                        @if ($candidate->interview_score !== null)
                                            <div class="flex flex-col items-center">
                                                <span class="font-bold text-foreground text-xs">{{ number_format($candidate->interview_score, 1) }}</span>
                                                <span class="text-[9px] text-muted-foreground">/ 275 Marks</span>
                                            </div>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="openEvaluationModal({{ $candidate->id }})"
                                                class="rounded border border-dashed border-input px-2 py-0.5 text-[10px] text-muted-foreground hover:bg-secondary cursor-pointer"
                                            >
                                                {{ __('Enter Marks') }}
                                            </button>
                                        @endif
                                    </td>

                                    <!-- Video Recording -->
                                    <td class="p-3">
                                        @if ($candidate->hasRecording())
                                            <div class="flex flex-col gap-1">
                                                <div class="flex items-center gap-1.5">
                                                    <a
                                                        href="{{ $candidate->getEffectiveRecordingUrl() }}"
                                                        target="_blank"
                                                        class="inline-flex items-center gap-1 rounded bg-sky-500/10 px-2 py-0.5 text-[10px] font-semibold text-sky-600 hover:bg-sky-500/20"
                                                    >
                                                        <x-icon name="play" class="h-3 w-3"/>
                                                        <span>{{ __('Watch / Link') }}</span>
                                                    </a>
                                                    <button
                                                        type="button"
                                                        wire:click="openRecordingModal({{ $candidate->id }})"
                                                        class="text-muted-foreground hover:text-foreground cursor-pointer"
                                                        title="Edit Recording"
                                                    >
                                                        <x-icon name="edit" class="h-3 w-3"/>
                                                    </button>
                                                </div>

                                                <!-- Send Recording Button -->
                                                <button
                                                    type="button"
                                                    wire:click="openDispatchRecordingModal({{ $candidate->id }})"
                                                    class="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-600 hover:underline cursor-pointer"
                                                >
                                                    <x-icon name="send" class="h-2.5 w-2.5"/>
                                                    @if ($candidate->recording_sent_at)
                                                        <span>{{ __('Sent') }} ({{ Carbon::parse($candidate->recording_sent_at)->format('d M') }})</span>
                                                    @else
                                                        <span>{{ __('Send to Student') }}</span>
                                                    @endif
                                                </button>
                                            </div>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="openRecordingModal({{ $candidate->id }})"
                                                class="inline-flex items-center gap-1 rounded border border-dashed border-input px-2 py-0.5 text-[10px] text-muted-foreground hover:bg-secondary cursor-pointer"
                                            >
                                                <x-icon name="upload" class="h-3 w-3"/>
                                                <span>{{ __('Add Recording') }}</span>
                                            </button>
                                        @endif
                                    </td>

                                    <!-- Actions -->
                                    <td class="p-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <!-- Check Student Details Modal -->
                                            <button
                                                type="button"
                                                wire:click="viewCandidateDetails({{ $candidate->id }})"
                                                class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-secondary hover:text-primary cursor-pointer transition-colors"
                                                title="{{ __('Check Student Detailed Information Modal') }}"
                                            >
                                                <x-icon name="user" class="h-3.5 w-3.5 text-primary"/>
                                            </button>

                                            <!-- Open Full Profile in New Tab -->
                                            <a
                                                href="{{ route('admin.students.index', ['search' => $candidate->user->student_id ?: $candidate->user->email, 'current_team' => auth()->user()->currentTeam?->slug ?? 'default']) }}"
                                                target="_blank"
                                                class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-secondary hover:text-sky-500 transition-colors"
                                                title="{{ __('Open Student Profile in New Tab') }}"
                                            >
                                                <x-icon name="external-link" class="h-3.5 w-3.5 text-sky-500"/>
                                            </a>

                                            <!-- Send Invitation to this Candidate -->
                                            <button
                                                type="button"
                                                wire:click="openInvitationsModal('specific_candidate', {{ $candidate->id }})"
                                                class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                                title="Send Personalized Invitation"
                                            >
                                                <x-icon name="send" class="h-3.5 w-3.5 text-sky-500"/>
                                            </button>

                                            <!-- Evaluate Score Modal -->
                                            <button
                                                type="button"
                                                wire:click="openEvaluationModal({{ $candidate->id }})"
                                                class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                                title="Evaluate Marks & Panel Remarks"
                                            >
                                                <x-icon name="clipboard-check" class="h-3.5 w-3.5 text-emerald-500"/>
                                            </button>

                                            <!-- Edit Candidate -->
                                            <button
                                                type="button"
                                                wire:click="openCandidateModal({{ $candidate->id }})"
                                                class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                                title="Edit Candidate Details"
                                            >
                                                <x-icon name="edit" class="h-3.5 w-3.5 text-amber-500"/>
                                            </button>

                                            <!-- Remove Candidate -->
                                            <button
                                                type="button"
                                                wire:click="confirmDelete('candidate', {{ $candidate->id }}, '{{ $candidate->user->name }}')"
                                                class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-destructive/10 hover:text-destructive cursor-pointer"
                                                title="Remove Candidate"
                                            >
                                                <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="p-8 text-center text-muted-foreground">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <x-icon name="users" class="h-8 w-8 text-muted-foreground/50"/>
                                            <p class="font-medium">{{ __('No candidate aspirants assigned to this interview session yet.') }}</p>
                                            <div class="flex items-center gap-2 mt-1">
                                                <x-ui.button size="sm" wire:click="openCandidateModal()" class="cursor-pointer">
                                                    <x-icon name="plus" class="h-3.5 w-3.5 mr-1"/>
                                                    <span>{{ __('Add Aspirant') }}</span>
                                                </x-ui.button>
                                                <x-ui.button size="sm" variant="outline" wire:click="openImportModal()" class="cursor-pointer">
                                                    <x-icon name="upload" class="h-3.5 w-3.5 mr-1"/>
                                                    <span>{{ __('Import CSV') }}</span>
                                                </x-ui.button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- MAIN VIEW 3: BOARD MEMBERS MASTER POOL -->
    @if ($activeView === 'board_members')
        <div class="space-y-4">
            <div class="flex flex-col gap-3 rounded-xl border border-border bg-card p-3 shadow-xs md:flex-row md:items-center md:justify-between">
                <div class="flex flex-1 items-center gap-2">
                    <div class="relative w-full md:w-72">
                        <x-icon name="search" class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"/>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search by name, organization, designation...') }}"
                            class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-xs placeholder:text-muted-foreground focus:border-primary focus:outline-none"
                        />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button variant="outline" size="sm" wire:click="openImportBoardMembersModal" class="cursor-pointer gap-1.5 h-8 text-xs">
                        <x-icon name="upload" class="h-3.5 w-3.5 text-emerald-500"/>
                        <span>{{ __('Import Panelists') }}</span>
                    </x-ui.button>
                    <x-ui.dropdown align="end">
                        <x-slot:trigger>
                            <x-ui.button variant="outline" size="sm" class="h-8 text-xs gap-1 cursor-pointer">
                                <x-icon name="download" class="h-3.5 w-3.5 text-blue-500"/>
                                <span>{{ __('Export') }}</span>
                                <x-icon name="chevron-down" class="h-3 w-3 opacity-60"/>
                            </x-ui.button>
                        </x-slot:trigger>
                        <x-slot:menu>
                            <button type="button" wire:click="exportBoardMembers('csv')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                                <x-icon name="file-text" class="h-3.5 w-3.5 text-blue-500"/>
                                <span>{{ __('Export as CSV') }}</span>
                            </button>
                            <button type="button" wire:click="exportBoardMembers('xlsx')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                                <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 text-emerald-500"/>
                                <span>{{ __('Export as Excel') }}</span>
                            </button>
                            <button type="button" wire:click="exportBoardMembers('pdf')" class="flex w-full items-center gap-2 px-3 py-2 text-xs hover:bg-secondary transition-colors cursor-pointer">
                                <x-icon name="file-down" class="h-3.5 w-3.5 text-rose-500"/>
                                <span>{{ __('Export as PDF') }}</span>
                            </button>
                        </x-slot:menu>
                    </x-ui.dropdown>
                    <x-ui.button size="sm" wire:click="openPoolMemberModal()" class="cursor-pointer gap-1.5 bg-primary text-primary-foreground">
                        <x-icon name="plus" class="h-4 w-4"/>
                        <span>{{ __('New Panelist') }}</span>
                    </x-ui.button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($this->boardMembersPool as $member)
                    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                @if ($member->avatar_url)
                                    <img src="{{ $member->avatar_url }}" alt="{{ $member->name }}" class="h-12 w-12 rounded-full object-cover border border-border"/>
                                @else
                                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-sm">
                                        {{ $member->initials }}
                                    </div>
                                @endif
                                <div class="space-y-0.5">
                                    <span class="font-mono text-[10px] text-muted-foreground">{{ $member->member_code }}</span>
                                    <h4 class="font-bold text-sm text-foreground">{{ $member->name }}</h4>
                                    <p class="text-xs text-muted-foreground">{{ $member->designation ?: 'Panel Expert' }}</p>
                                    @if ($member->organization)
                                        <p class="text-[11px] font-medium text-foreground/80">{{ $member->organization }}</p>
                                    @endif
                                </div>
                            </div>

                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $member->status === 'active' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-secondary text-muted-foreground' }}">
                                {{ ucfirst($member->status) }}
                            </span>
                        </div>

                        @if ($member->specialization)
                            <div class="text-[11px] text-muted-foreground bg-muted/30 p-2 rounded-md">
                                <strong>{{ __('Domain:') }}</strong> {{ $member->specialization }}
                            </div>
                        @endif

                        <div class="flex items-center justify-between border-t border-border pt-2.5 text-xs text-muted-foreground">
                            <span>{{ $member->sessions->count() }} {{ __('Sessions Chaired/Attended') }}</span>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    wire:click="openPoolMemberModal({{ $member->id }})"
                                    class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer"
                                    title="Edit Profile"
                                >
                                    <x-icon name="edit" class="h-3.5 w-3.5"/>
                                </button>
                                <button
                                    type="button"
                                    wire:click="confirmDelete('pool_member', {{ $member->id }}, '{{ $member->name }}')"
                                    class="flex h-7 w-7 items-center justify-center rounded border border-input text-muted-foreground hover:bg-destructive/10 hover:text-destructive cursor-pointer"
                                    title="Delete Panelist"
                                >
                                    <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full rounded-xl border border-dashed border-border p-8 text-center text-muted-foreground">
                        <p>{{ __('No board panel members found.') }}</p>
                    </div>
                @endforelse
            </div>

            @if ($this->boardMembersPool->hasPages())
                <div class="border-t border-border p-3">
                    {{ $this->boardMembersPool->links() }}
                </div>
            @endif
        </div>
    @endif

    <!-- MAIN VIEW 4: RECORDINGS VAULT -->
    @if ($activeView === 'recordings_vault')
        <div class="space-y-4">
            <div class="flex flex-col gap-3 rounded-xl border border-border bg-card p-3 shadow-xs md:flex-row md:items-center md:justify-between">
                <div class="flex flex-1 items-center gap-2">
                    <div class="relative w-full md:w-72">
                        <x-icon name="search" class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"/>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search candidate, roll, session...') }}"
                            class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-xs placeholder:text-muted-foreground focus:border-primary focus:outline-none"
                        />
                    </div>

                    <select wire:model.live="statusFilter" class="h-9 rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('All Recording Statuses') }}</option>
                        <option value="available">{{ __('Available') }}</option>
                        <option value="processing">{{ __('Processing') }}</option>
                        <option value="not_available">{{ __('Not Available') }}</option>
                    </select>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-border bg-muted/40 text-muted-foreground">
                            <tr>
                                <th class="p-3 font-semibold">{{ __('Candidate Name & Roll') }}</th>
                                <th class="p-3 font-semibold">{{ __('Mock Interview Session') }}</th>
                                <th class="p-3 font-semibold">{{ __('Mode & Date') }}</th>
                                <th class="p-3 font-semibold">{{ __('Recording Status') }}</th>
                                <th class="p-3 font-semibold">{{ __('Recording Details') }}</th>
                                <th class="p-3 font-semibold">{{ __('Student Notification') }}</th>
                                <th class="p-3 font-semibold text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($this->candidateRecordingsVault as $cand)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="p-3">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="font-bold text-foreground">{{ $cand->user->name }}</span>
                                            <span class="font-mono text-muted-foreground">{{ $cand->user->student_id ?: $cand->user->email }}</span>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="font-semibold text-foreground">{{ $cand->session->title }}</span>
                                            <span class="font-mono text-muted-foreground">{{ $cand->session->session_code }}</span>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="font-medium text-foreground">{{ $cand->session->formatted_date }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ strtoupper($cand->attending_mode) }} • {{ $cand->formatted_slot_time }}</span>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $cand->hasRecording() ? 'bg-emerald-500/10 text-emerald-600' : 'bg-secondary text-muted-foreground' }}">
                                            {{ ucfirst(str_replace('_', ' ', $cand->recording_status)) }}
                                        </span>
                                    </td>
                                    <td class="p-3 max-w-[200px]">
                                        @if ($cand->hasRecording())
                                            <div class="flex flex-col gap-0.5">
                                                <a href="{{ $cand->getEffectiveRecordingUrl() }}" target="_blank" class="truncate text-primary hover:underline font-medium">
                                                    {{ $cand->getEffectiveRecordingUrl() }}
                                                </a>
                                                @if ($cand->recording_password)
                                                    <span class="font-mono text-[10px] text-muted-foreground">{{ __('Passcode:') }} {{ $cand->recording_password }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-muted-foreground italic text-[11px]">{{ __('No recording attached') }}</span>
                                        @endif
                                    </td>
                                    <td class="p-3">
                                        @if ($cand->recording_sent_at)
                                            <span class="text-emerald-600 text-xs font-medium flex items-center gap-1">
                                                <x-icon name="check" class="h-3.5 w-3.5"/>
                                                {{ __('Sent via') }} {{ implode(', ', $cand->recording_sent_channels ?? []) }}
                                            </span>
                                        @else
                                            <span class="text-muted-foreground italic text-[11px]">{{ __('Not sent yet') }}</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <x-ui.button size="sm" variant="outline" wire:click="openRecordingModal({{ $cand->id }})" class="h-7 text-xs cursor-pointer">
                                                <x-icon name="upload" class="h-3 w-3 mr-1"/>
                                                <span>{{ $cand->hasRecording() ? __('Edit') : __('Attach') }}</span>
                                            </x-ui.button>
                                            @if ($cand->hasRecording())
                                                <x-ui.button size="sm" wire:click="openDispatchRecordingModal({{ $cand->id }})" class="h-7 text-xs bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer">
                                                    <x-icon name="send" class="h-3 w-3 mr-1"/>
                                                    <span>{{ __('Send') }}</span>
                                                </x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-muted-foreground">
                                        {{ __('No candidate interview records found.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($this->candidateRecordingsVault->hasPages())
                    <div class="border-t border-border p-3">
                        {{ $this->candidateRecordingsVault->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- ======================================================== -->
    <!-- INTERACTIVE MODALS -->
    <!-- ======================================================== -->

    <!-- 1. SESSION FORM MODAL -->
    <x-ui.modal name="session-form-modal" maxWidth="max-w-2xl" title="{{ $editingSessionId ? __('Edit Mock Interview Session') : __('Create Mock Interview Session') }}" description="{{ __('Define session parameters, date, offline venue address, and default interview duration.') }}">
        <form wire:submit="saveSession" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Session Title') }} *</label>
                    <input type="text" wire:model="sessionForm.title" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                    @error('sessionForm.title') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Session Number') }} *</label>
                    <input type="number" wire:model="sessionForm.session_number" min="1" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Academic Cycle / Year') }} *</label>
                    <input type="text" wire:model="sessionForm.academic_year" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Session Date') }} *</label>
                    <input type="date" wire:model="sessionForm.session_date" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Reporting Time') }}</label>
                    <input type="time" wire:model="sessionForm.reporting_time" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Board Start Time') }} *</label>
                    <input type="time" wire:model="sessionForm.start_time" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Board End Time') }} *</label>
                    <input type="time" wire:model="sessionForm.end_time" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Offline Venue Address (Where Board Sits)') }} *</label>
                    <textarea wire:model="sessionForm.venue_address" rows="2" required class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none"></textarea>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Room Number / Chamber') }}</label>
                    <input type="text" wire:model="sessionForm.room_number" placeholder="e.g. Board Room A" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Status') }} *</label>
                    <select wire:model="sessionForm.status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="in_progress">{{ __('In Progress') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Default Slot Duration (Minutes)') }} *</label>
                    <input type="number" wire:model="sessionForm.default_interview_duration" min="10" max="120" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                    <span class="text-[10px] text-muted-foreground">{{ __('Standard UPSC mock slot is 25 to 30 mins.') }}</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Buffer / Transition Break (Minutes)') }}</label>
                    <input type="number" wire:model="sessionForm.default_buffer_duration" min="0" max="60" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Session Instructions') }}</label>
                    <textarea wire:model="sessionForm.instructions" rows="2" class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none" placeholder="{{ __('Guidelines for candidates and board panelists...') }}"></textarea>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Internal Administrative Remarks') }}</label>
                    <textarea wire:model="sessionForm.remarks" rows="1" class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none"></textarea>
                </div>

                <div class="col-span-full border-t border-border pt-3">
                    <details class="group rounded-lg border border-border bg-muted/20 p-3">
                        <summary class="flex items-center justify-between cursor-pointer text-xs font-semibold text-foreground select-none">
                            <div class="flex items-center gap-1.5">
                                <x-icon name="mail" class="h-4 w-4 text-primary"/>
                                <span>{{ __('Personalized Invitation Templates (Optional Overrides)') }}</span>
                            </div>
                            <span class="text-[11px] text-muted-foreground group-open:rotate-180 transition-transform">▼</span>
                        </summary>

                        <div class="mt-3 space-y-3 border-t border-border/50 pt-3" x-data="{ templateTab: 'chair' }">
                            <div class="flex items-center gap-1 p-0.5 rounded-lg bg-muted text-xs">
                                <button
                                    type="button"
                                    x-on:click="templateTab = 'chair'"
                                    :class="templateTab === 'chair' ? 'bg-background font-semibold text-foreground shadow-xs' : 'text-muted-foreground'"
                                    class="px-2.5 py-1 rounded-md transition-colors cursor-pointer text-xs"
                                >
                                    {{ __('♔ Chairperson') }}
                                </button>
                                <button
                                    type="button"
                                    x-on:click="templateTab = 'member'"
                                    :class="templateTab === 'member' ? 'bg-background font-semibold text-foreground shadow-xs' : 'text-muted-foreground'"
                                    class="px-2.5 py-1 rounded-md transition-colors cursor-pointer text-xs"
                                >
                                    {{ __('👥 Panelist Member') }}
                                </button>
                                <button
                                    type="button"
                                    x-on:click="templateTab = 'student'"
                                    :class="templateTab === 'student' ? 'bg-background font-semibold text-foreground shadow-xs' : 'text-muted-foreground'"
                                    class="px-2.5 py-1 rounded-md transition-colors cursor-pointer text-xs"
                                >
                                    {{ __('🎓 Attending Student') }}
                                </button>
                            </div>

                            <!-- Chairperson Template -->
                            <div x-show="templateTab === 'chair'" class="space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Chairperson Invitation Template') }}</label>
                                    <button type="button" wire:click="loadDefaultTemplateForSession('chairman')" class="text-[11px] text-primary hover:underline cursor-pointer">
                                        {{ __('Load Default') }}
                                    </button>
                                </div>
                                <textarea wire:model="sessionForm.chairman_invitation_template" rows="5" class="w-full font-mono text-xs rounded-md border border-input bg-background p-2.5 focus:border-primary focus:outline-none" placeholder="{{ __('Leave blank to use system default...') }}"></textarea>
                                <span class="text-[10px] text-muted-foreground">{{ __('Placeholders: {name}, {session_no}, {session_title}, {session_date}, {start_time}, {end_time}, {reporting_time}, {venue_address}, {room_number}, {candidates_count}, {board_members_list}, {instructions}') }}</span>
                            </div>

                            <!-- Member Template -->
                            <div x-show="templateTab === 'member'" class="space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Board Panelist Invitation Template') }}</label>
                                    <button type="button" wire:click="loadDefaultTemplateForSession('member')" class="text-[11px] text-primary hover:underline cursor-pointer">
                                        {{ __('Load Default') }}
                                    </button>
                                </div>
                                <textarea wire:model="sessionForm.member_invitation_template" rows="5" class="w-full font-mono text-xs rounded-md border border-input bg-background p-2.5 focus:border-primary focus:outline-none" placeholder="{{ __('Leave blank to use system default...') }}"></textarea>
                                <span class="text-[10px] text-muted-foreground">{{ __('Placeholders: {name}, {chairperson_name}, {session_no}, {session_title}, {session_date}, {start_time}, {end_time}, {reporting_time}, {venue_address}, {room_number}, {candidates_count}, {instructions}') }}</span>
                            </div>

                            <!-- Student Template -->
                            <div x-show="templateTab === 'student'" class="space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Attending Aspirant Student Invitation Template') }}</label>
                                    <button type="button" wire:click="loadDefaultTemplateForSession('student')" class="text-[11px] text-primary hover:underline cursor-pointer">
                                        {{ __('Load Default') }}
                                    </button>
                                </div>
                                <textarea wire:model="sessionForm.student_invitation_template" rows="5" class="w-full font-mono text-xs rounded-md border border-input bg-background p-2.5 focus:border-primary focus:outline-none" placeholder="{{ __('Leave blank to use system default...') }}"></textarea>
                                <span class="text-[10px] text-muted-foreground">{{ __('Placeholders: {name}, {session_no}, {session_title}, {session_date}, {attending_mode}, {sequence_number}, {slot_time}, {duration_minutes}, {reporting_time}, {location_or_platform_details}, {chairperson_name}, {candidate_instructions}') }}</span>
                            </div>
                        </div>
                    </details>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('session-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    {{ $editingSessionId ? __('Update Session') : __('Create Session') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 2. SESSION MEMBER MODAL (Assign 5-member board) -->
    <x-ui.modal name="session-member-modal" maxWidth="max-w-lg" title="{{ __('Assign Board Member to Session') }}" description="{{ __('Select a panel expert from the directory or quickly create one.') }}">
        <form wire:submit="saveSessionMember" class="space-y-4">
            @if (! $isQuickCreatingBoardMember)
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold text-foreground">{{ __('Select Board Panelist') }} *</label>
                        <button type="button" wire:click="$set('isQuickCreatingBoardMember', true)" class="text-[11px] text-primary hover:underline font-semibold cursor-pointer">
                            + {{ __('Create New Panelist') }}
                        </button>
                    </div>
                    <select wire:model="sessionMemberForm.interview_board_member_id" required class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('-- Select Panelist from Pool --') }}</option>
                        @foreach ($this->activeBoardMembers as $bm)
                            <option value="{{ $bm->id }}">{{ $bm->name }} ({{ $bm->designation ?: 'Expert' }})</option>
                        @endforeach
                    </select>
                </div>
            @else
                <div class="rounded-lg border border-primary/30 bg-primary/5 p-3 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-xs text-primary">{{ __('Quick Create Panelist') }}</span>
                        <button type="button" wire:click="$set('isQuickCreatingBoardMember', false)" class="text-[10px] text-muted-foreground hover:underline cursor-pointer">
                            {{ __('Choose Existing') }}
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="col-span-full">
                            <input type="text" wire:model="quickBoardMemberForm.name" placeholder="Full Name *" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                        </div>
                        <div>
                            <input type="text" wire:model="quickBoardMemberForm.designation" placeholder="Designation (e.g. Ex-IAS)" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                        </div>
                        <div>
                            <input type="text" wire:model="quickBoardMemberForm.organization" placeholder="Organization" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                        </div>
                        <div>
                            <input type="email" wire:model="quickBoardMemberForm.email" placeholder="Email" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                        </div>
                        <div>
                            <input type="text" wire:model="quickBoardMemberForm.phone" placeholder="Phone" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                        </div>
                    </div>
                    <x-ui.button type="button" size="sm" wire:click="quickCreateBoardMember" class="w-full text-xs h-7">
                        {{ __('Save & Select Panelist') }}
                    </x-ui.button>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Board Role') }} *</label>
                    <select wire:model="sessionMemberForm.role" required class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="chairperson">{{ __('♔ Board Chairperson') }}</option>
                        <option value="member">{{ __('Board Member') }}</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Order Index') }} *</label>
                    <input type="number" wire:model="sessionMemberForm.order_index" min="1" max="10" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Attendance Status') }}</label>
                    <select wire:model="sessionMemberForm.attendance_status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="confirmed">{{ __('Confirmed') }}</option>
                        <option value="invited">{{ __('Invited') }}</option>
                        <option value="attended">{{ __('Attended') }}</option>
                        <option value="absent">{{ __('Absent') }}</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Remarks') }}</label>
                    <input type="text" wire:model="sessionMemberForm.remarks" placeholder="Optional notes" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('session-member-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    {{ __('Assign to Board') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 3. CANDIDATE STUDENT MODAL -->
    <x-ui.modal name="candidate-form-modal" maxWidth="max-w-2xl" title="{{ $editingCandidateId ? __('Edit Candidate Interview Slot') : __('Assign Aspirant to Mock Interview') }}" description="{{ __('Assign student, configure attending mode (offline/online), platform credentials, sequence, and interview slot timings.') }}">
        <form wire:submit="saveCandidate" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <!-- Academic Cohort Filters for quick student selection -->
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Filter Batch (Optional)') }}</label>
                    <select wire:model.live="candidateForm.batch_id" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('All Batches') }}</option>
                        @foreach ($this->batchesList as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Filter Course (Optional)') }}</label>
                    <select wire:model.live="candidateForm.course_id" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('All Courses') }}</option>
                        @foreach ($this->coursesList as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Select Enrolled Student') }} *</label>
                    <select wire:model="candidateForm.user_id" required class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('-- Select Student Candidate --') }}</option>
                        @foreach ($this->availableStudents as $s)
                            <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->student_id ?: $s->email }})</option>
                        @endforeach
                    </select>
                </div>

                <!-- Sequence & Mode -->
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Sequence / Order of Appearance') }} *</label>
                    <input type="number" wire:model="candidateForm.sequence_number" min="1" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Attending Mode') }} *</label>
                    <select wire:model.live="candidateForm.attending_mode" required class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="offline">{{ __('Offline / In-Person (at Location)') }}</option>
                        <option value="online">{{ __('Online (Remote Video Conference)') }}</option>
                    </select>
                </div>

                <!-- Timings -->
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Scheduled Start Time') }} *</label>
                    <input type="time" wire:model.live="candidateForm.scheduled_start_time" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Duration (Minutes)') }} *</label>
                    <input type="number" wire:model.live="candidateForm.duration_minutes" min="10" max="120" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Scheduled End Time') }} *</label>
                    <input type="time" wire:model="candidateForm.scheduled_end_time" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Status') }}</label>
                    <select wire:model="candidateForm.status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="reported">{{ __('Reported') }}</option>
                        <option value="interviewing">{{ __('Interviewing') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="absent">{{ __('Absent') }}</option>
                        <option value="rescheduled">{{ __('Rescheduled') }}</option>
                    </select>
                </div>

                <!-- Online Mode Platforms & Credentials -->
                @if ($candidateForm['attending_mode'] === 'online')
                    <div class="col-span-full rounded-lg border border-purple-500/30 bg-purple-500/5 p-3 space-y-3">
                        <div class="flex items-center gap-1.5">
                            <x-icon name="video" class="h-4 w-4 text-purple-600"/>
                            <span class="font-bold text-xs text-purple-700 dark:text-purple-300">{{ __('Online Video Conference Credentials') }}</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-foreground">{{ __('Platform') }} *</label>
                                <select wire:model.live="candidateForm.platform" class="h-8 w-full rounded border border-input bg-background px-2 text-xs">
                                    <option value="Zoom">Zoom Meetings</option>
                                    <option value="Google Meet">Google Meet</option>
                                    <option value="Microsoft Teams">Microsoft Teams</option>
                                    <option value="Cisco Webex Meetings">Cisco Webex Meetings</option>
                                    <option value="Others">Other Platform</option>
                                </select>
                            </div>
                            @if ($candidateForm['platform'] === 'Others')
                                <div>
                                    <label class="text-xs font-semibold text-foreground">{{ __('Specify Platform Name') }}</label>
                                    <input type="text" wire:model="candidateForm.platform_other" placeholder="e.g. Jitsi, WebRTC" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                                </div>
                            @endif
                            <div class="col-span-full">
                                <label class="text-xs font-semibold text-foreground">{{ __('Meeting URL Link') }} *</label>
                                <input type="url" wire:model="candidateForm.meeting_link" placeholder="https://zoom.us/j/... or https://meet.google.com/..." class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground">{{ __('Meeting ID') }}</label>
                                <input type="text" wire:model="candidateForm.meeting_id" placeholder="Optional" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground">{{ __('Passcode / Password') }}</label>
                                <input type="text" wire:model="candidateForm.meeting_password" placeholder="Optional" class="h-8 w-full rounded border border-input bg-background px-2 text-xs"/>
                            </div>
                        </div>
                    </div>
                @else
                    <div>
                        <label class="text-xs font-semibold text-foreground">{{ __('Offline Room / Chamber') }}</label>
                        <input type="text" wire:model="candidateForm.room_number" placeholder="Defaults to session room" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                    </div>
                @endif

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Special Instructions for this Aspirant') }}</label>
                    <textarea wire:model="candidateForm.candidate_instructions" rows="2" class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none" placeholder="{{ __('e.g. Prepare Optional Subject Geography case studies, bring updated DAF...') }}"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('candidate-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    {{ $editingCandidateId ? __('Save Slot Changes') : __('Add Aspirant') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 4. AUTO-GENERATE SLOTS MODAL -->
    <x-ui.modal name="auto-slots-modal" maxWidth="max-w-md" title="{{ __('Auto-Generate Sequential Time Slots') }}" description="{{ __('Automatically calculate and assign consecutive interview time slots for all aspirants in this session.') }}">
        <form wire:submit="executeAutoSlots" class="space-y-4">
            <div class="space-y-3">
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('First Interview Start Time') }} *</label>
                    <input type="time" wire:model="autoSlotsForm.start_time" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Slot Duration per Candidate (Minutes)') }} *</label>
                    <input type="number" wire:model="autoSlotsForm.duration_minutes" min="10" max="120" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                    <span class="text-[10px] text-muted-foreground">{{ __('Each candidate receives 25 to 30 minutes interview.') }}</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Buffer / Transition Between Slots (Minutes)') }} *</label>
                    <input type="number" wire:model="autoSlotsForm.buffer_minutes" min="0" max="60" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>
            </div>

            <div class="rounded-md bg-amber-500/10 p-2.5 text-xs text-amber-700 dark:text-amber-300">
                <p>{{ __('This will re-calculate start and end times for all ordered aspirants according to their sequence number.') }}</p>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('auto-slots-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    <x-icon name="sparkles" class="h-3.5 w-3.5 mr-1"/>
                    <span>{{ __('Calculate & Apply') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 5. INVITATIONS HUB & LIVE PREVIEW MODAL -->
    <x-ui.modal name="invitation-hub-modal" maxWidth="max-w-3xl" title="{{ __('Personalized Invitations Hub & Template Editor') }}" description="{{ __('Draft, live-preview, customize role templates, and send differentiated invitations for Chairman, Board Members, and Students across multi-channel gateways.') }}">
        <form wire:submit="dispatchInvitations" class="space-y-4">
            <!-- Top Selectors: Target Group & Template/Preview Role -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Recipient Target Group') }} *</label>
                    <select wire:model.live="inviteTarget" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="all">{{ __('All (Board Members & Candidates)') }}</option>
                        <option value="board_members">{{ __('All Board Members (Chair + Members)') }}</option>
                        <option value="candidates">{{ __('All Attending Aspirants') }}</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Template & Preview Role') }} *</label>
                    <select wire:model.live="previewRecipientType" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="chairman">{{ __('♔ Chairman of the Board (Custom Role)') }}</option>
                        <option value="board_member">{{ __('👥 Board Panelist Member') }}</option>
                        <option value="student">{{ __('🎓 Attending Aspirant Student') }}</option>
                    </select>
                </div>
            </div>

            <!-- Tab Switcher: [👁 Live Preview] vs [✏️ Edit Template] -->
            <div class="flex items-center justify-between border-b border-border pb-2.5">
                <div class="flex items-center gap-1.5 p-0.5 rounded-lg bg-muted/60 text-xs">
                    <button
                        type="button"
                        wire:click="$set('hubTab', 'preview')"
                        @class([
                            'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md font-medium transition-all cursor-pointer text-xs',
                            'bg-background text-foreground shadow-xs font-semibold' => $hubTab === 'preview',
                            'text-muted-foreground hover:text-foreground' => $hubTab !== 'preview',
                        ])
                    >
                        <x-icon name="eye" class="h-3.5 w-3.5"/>
                        <span>{{ __('Live Preview') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="$set('hubTab', 'edit_template')"
                        @class([
                            'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md font-medium transition-all cursor-pointer text-xs',
                            'bg-background text-foreground shadow-xs font-semibold' => $hubTab === 'edit_template',
                            'text-muted-foreground hover:text-foreground' => $hubTab !== 'edit_template',
                        ])
                    >
                        <x-icon name="edit-3" class="h-3.5 w-3.5"/>
                        <span>{{ __('Edit Template') }}</span>
                        @if ($this->hasCustomSessionTemplate())
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500" title="{{ __('Session override active') }}"></span>
                        @endif
                    </button>
                </div>

                <!-- Template Status Pill -->
                <div class="text-[11px] text-muted-foreground flex items-center gap-2">
                    <span class="hidden sm:inline">{{ __('Active Role:') }}</span>
                    <span class="font-semibold text-foreground">
                        {{ match ($previewRecipientType) {
                            'chairman' => __('Chairman Template'),
                            'board_member' => __('Board Member Template'),
                            default => __('Student Template'),
                        } }}
                    </span>
                    @if ($this->hasCustomSessionTemplate())
                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                            {{ __('Custom Session Saved') }}
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground border border-border">
                            {{ __('Using System Default') }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- Content Area: Preview Tab or Edit Template Tab -->
            @if ($hubTab === 'preview')
                <!-- Live Rendered Preview Box -->
                @if ($liveInvitationPreview)
                    <div class="rounded-lg border border-border bg-muted/30 p-3.5 space-y-2.5">
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-1.5 font-bold text-foreground">
                                <x-icon name="eye" class="h-3.5 w-3.5 text-primary"/>
                                <span>{{ __('Rendered Content Preview') }} ({{ ucfirst(str_replace('_', ' ', $liveInvitationPreview['recipient_type'])) }})</span>
                            </div>
                            <span class="text-[11px] text-muted-foreground">{{ __('Sample Recipient:') }} <strong class="text-foreground">{{ $liveInvitationPreview['recipient_name'] }}</strong></span>
                        </div>

                        <div class="text-xs font-semibold text-foreground bg-background p-2.5 rounded-md border border-border">
                            <span class="text-muted-foreground font-normal">{{ __('Subject:') }}</span> {{ $liveInvitationPreview['subject'] }}
                        </div>

                        <div class="rounded-md border border-border bg-background p-3 text-xs font-mono whitespace-pre-wrap max-h-56 overflow-y-auto leading-relaxed text-foreground/90 select-text">
{{ $liveInvitationPreview['content'] }}
                        </div>
                    </div>
                @endif
            @else
                <!-- Edit Template Tab -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                            <x-icon name="file-text" class="h-3.5 w-3.5 text-primary"/>
                            <span>{{ __('Invitation Template Content') }} ({{ match ($previewRecipientType) { 'chairman' => __('Chairman'), 'board_member' => __('Board Member'), default => __('Student') } }})</span>
                        </label>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="resetInvitationTemplateToDefault"
                                class="inline-flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground hover:underline cursor-pointer"
                                title="{{ __('Reset to system default template') }}"
                            >
                                <x-icon name="rotate-ccw" class="h-3 w-3"/>
                                <span>{{ __('Reset to Default') }}</span>
                            </button>
                            <button
                                type="button"
                                wire:click="saveSessionInvitationTemplate"
                                class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline cursor-pointer bg-primary/10 hover:bg-primary/20 px-2.5 py-1 rounded-md border border-primary/20 transition-colors"
                                title="{{ __('Save this template specifically for this session') }}"
                            >
                                <x-icon name="save" class="h-3.5 w-3.5"/>
                                <span>{{ __('Save for this Session') }}</span>
                            </button>
                        </div>
                    </div>

                    <textarea
                        wire:model.live.debounce.300ms="activeTemplateText"
                        rows="9"
                        class="w-full font-mono text-xs rounded-md border border-input bg-background p-3 text-foreground focus:border-primary focus:outline-none leading-relaxed"
                        placeholder="{{ __('Write template with placeholders like {name}, {session_date}, {slot_time}...') }}"
                    ></textarea>

                    <!-- Available Placeholders Chips -->
                    <div class="rounded-lg border border-border bg-muted/20 p-2.5">
                        <div class="text-[11px] font-semibold text-muted-foreground mb-1.5 flex items-center gap-1">
                            <x-icon name="code" class="h-3 w-3 text-primary"/>
                            <span>{{ __('Available Dynamic Variables (Click to append):') }}</span>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->availableTemplatePlaceholders as $token => $desc)
                                <button
                                    type="button"
                                    x-on:click="
                                        const textarea = $el.closest('form').querySelector('textarea');
                                        if (textarea) {
                                            const start = textarea.selectionStart;
                                            const end = textarea.selectionEnd;
                                            const text = textarea.value;
                                            textarea.value = text.substring(0, start) + '{{ $token }}' + text.substring(end);
                                            textarea.dispatchEvent(new Event('input'));
                                        }
                                    "
                                    class="inline-flex items-center gap-1 rounded bg-secondary/80 hover:bg-secondary px-2 py-0.5 text-[10px] font-mono font-medium text-secondary-foreground transition-colors cursor-pointer border border-border/50"
                                    title="{{ $desc }}"
                                >
                                    <span>{{ $token }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <!-- Delivery Channels -->
            <div>
                <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Dispatch Delivery Channels') }} *</label>
                <div class="flex flex-wrap gap-4 text-xs">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="inviteChannels" value="email" class="rounded border-input text-primary"/>
                        <span>{{ __('Email Notification') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="inviteChannels" value="whatsapp" class="rounded border-input text-primary"/>
                        <span>{{ __('WhatsApp Message') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="inviteChannels" value="sms" class="rounded border-input text-primary"/>
                        <span>{{ __('SMS Gateway') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="inviteChannels" value="telegram" class="rounded border-input text-primary"/>
                        <span>{{ __('Telegram Bot') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="inviteChannels" value="in_app" class="rounded border-input text-primary"/>
                        <span>{{ __('In-App Notification') }}</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('invitation-hub-modal')">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground gap-1.5">
                    <x-icon name="send" class="h-3.5 w-3.5"/>
                    <span>{{ __('Dispatch Invitations Now') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 6. ATTACH / UPLOAD RECORDING MODAL -->
    <x-ui.modal name="recording-modal" maxWidth="max-w-lg" title="{{ __('Interview Video Recording') }}" description="{{ __('Attach cloud recording URL (Zoom Cloud, Google Drive, YouTube unlisted) or upload video file.') }}">
        <form wire:submit="saveRecording" class="space-y-4">
            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Recording Storage Mode') }} *</label>
                <select wire:model.live="recordingForm.recording_type" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                    <option value="url">{{ __('External Cloud Video URL (Drive, YouTube, Vimeo, Zoom)') }}</option>
                    <option value="upload">{{ __('Direct File Upload (.mp4, .webm, .mkv)') }}</option>
                </select>
            </div>

            @if ($recordingForm['recording_type'] === 'url')
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Video Recording URL') }} *</label>
                    <input type="url" wire:model="recordingForm.recording_url" placeholder="https://drive.google.com/file/... or https://youtu.be/..." required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>
            @else
                <div>
                    <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Upload Video File') }}</label>
                    @if ($existingRecordingFilePath && ! $removeRecordingFile)
                        <div class="mb-2 p-2.5 rounded-md border border-border bg-muted/30 flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2 truncate">
                                <x-icon name="video" class="h-4 w-4 text-primary shrink-0"/>
                                <span class="font-mono text-[11px] truncate">{{ basename($existingRecordingFilePath) }}</span>
                            </div>
                            <button
                                type="button"
                                wire:click="$set('removeRecordingFile', true); $set('uploadedRecordingFile', null)"
                                class="inline-flex items-center gap-1 text-[11px] font-medium text-destructive hover:underline cursor-pointer ml-2 shrink-0"
                            >
                                <x-icon name="trash-2" class="h-3.5 w-3.5" />
                                <span>{{ __('Remove uploaded file') }}</span>
                            </button>
                        </div>
                    @endif
                    @if ($removeRecordingFile)
                        <div class="mb-2 text-[11px] text-amber-500 font-medium">
                            {{ __('Uploaded video file will be removed upon saving.') }}
                        </div>
                    @endif
                    <input type="file" wire:model="uploadedRecordingFile" accept="video/*" class="h-9 w-full text-xs file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:bg-primary file:text-white file:text-xs cursor-pointer"/>
                    <div wire:loading wire:target="uploadedRecordingFile" class="text-[11px] text-primary mt-1 font-medium">
                        {{ __('Uploading video file...') }}
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Access Passcode / Key') }}</label>
                    <input type="text" wire:model="recordingForm.recording_password" placeholder="Optional" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Status') }} *</label>
                    <select wire:model="recordingForm.recording_status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="available">{{ __('Available (Ready for student)') }}</option>
                        <option value="processing">{{ __('Processing') }}</option>
                        <option value="not_available">{{ __('Not Available') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Recording Notes / Timestamps Index') }}</label>
                <textarea wire:model="recordingForm.recording_notes" rows="2" class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none" placeholder="{{ __('e.g. 05:20 DAF questions, 15:40 International Relations questions...') }}"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('recording-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    {{ __('Save Recording') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 7. DISPATCH RECORDING TO STUDENT MODAL -->
    <x-ui.modal name="dispatch-recording-modal" maxWidth="max-w-md" title="{{ __('Send Recording Download Link') }}" description="{{ __('Notify the student with direct video download/access link via selected communication channels.') }}">
        <form wire:submit="dispatchRecordingToStudent" class="space-y-4">
            <div>
                <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Dispatch Via Channels') }} *</label>
                <div class="flex flex-wrap gap-3 text-xs">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchRecordingForm.channels" value="email" class="rounded border-input text-primary"/>
                        <span>{{ __('Email') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchRecordingForm.channels" value="whatsapp" class="rounded border-input text-primary"/>
                        <span>{{ __('WhatsApp') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchRecordingForm.channels" value="sms" class="rounded border-input text-primary"/>
                        <span>{{ __('SMS') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchRecordingForm.channels" value="telegram" class="rounded border-input text-primary"/>
                        <span>{{ __('Telegram') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" wire:model="dispatchRecordingForm.channels" value="in_app" class="rounded border-input text-primary"/>
                        <span>{{ __('In-App') }}</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Custom Note / Message to Student') }}</label>
                <textarea wire:model="dispatchRecordingForm.notes" rows="2" class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none" placeholder="{{ __('Optional encouragement or feedback notes...') }}"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('dispatch-recording-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-emerald-600 hover:bg-emerald-700 text-white gap-1.5">
                    <x-icon name="send" class="h-3.5 w-3.5"/>
                    <span>{{ __('Send Recording Link') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 8. EVALUATION & MARKS MODAL -->
    <x-ui.modal name="evaluation-modal" maxWidth="max-w-md" title="{{ __('UPSC Personality Test Evaluation') }}" description="{{ __('Record interview marks out of 275 and board feedback recommendations.') }}">
        <form wire:submit="saveEvaluation" class="space-y-4">
            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Interview Score (Out of 275 Marks)') }}</label>
                <input type="number" step="0.5" wire:model="evaluationForm.interview_score" min="0" max="275" placeholder="e.g. 210.5" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Candidate Status') }} *</label>
                <select wire:model="evaluationForm.status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                    <option value="completed">{{ __('Completed') }}</option>
                    <option value="interviewing">{{ __('Interviewing') }}</option>
                    <option value="reported">{{ __('Reported') }}</option>
                    <option value="absent">{{ __('Absent') }}</option>
                    <option value="scheduled">{{ __('Scheduled') }}</option>
                </select>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Board Feedback & Remarks') }}</label>
                <textarea wire:model="evaluationForm.board_feedback" rows="3" class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none" placeholder="{{ __('Strengths, body language, DAF clarity, areas of improvement...') }}"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('evaluation-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    {{ __('Save Marks & Remarks') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 9. IMPORT CANDIDATES MODAL -->
    <x-ui.modal name="import-candidates-modal" maxWidth="max-w-lg" title="{{ __('Import Candidates into Session') }}" description="{{ __('Bulk import candidate students from CSV or Excel file.') }}">
        <form wire:submit="importCandidates" class="space-y-4">
            <div class="rounded-lg border border-border bg-muted/40 p-3 space-y-2 text-xs">
                <div class="flex items-center justify-between">
                    <span class="font-bold text-foreground">{{ __('CSV Template Guide') }}</span>
                    <button type="button" wire:click="downloadCandidateImportTemplate" class="text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        <span>{{ __('Download Template CSV') }}</span>
                    </button>
                </div>
                <p class="text-muted-foreground text-[11px]">
                    {{ __('Ensure column 1 contains Student ID or Registered Email. Offline or online mode and duration can also be specified.') }}
                </p>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Upload CSV / Excel File') }} *</label>
                <input type="file" wire:model="candidateImportFile" accept=".csv,.xlsx,.txt" required class="h-9 w-full text-xs file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:bg-primary file:text-white file:text-xs"/>
                @error('candidateImportFile') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Assign Batch (Optional)') }}</label>
                    <select wire:model="importBatchId" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->batchesList as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Assign Course (Optional)') }}</label>
                    <select wire:model="importCourseId" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->coursesList as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('import-candidates-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground gap-1.5">
                    <x-icon name="upload" class="h-3.5 w-3.5"/>
                    <span>{{ __('Import Candidates') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 10. BOARD MEMBER POOL FORM MODAL -->
    <x-ui.modal name="board-member-form-modal" maxWidth="max-w-lg" title="{{ $editingPoolMemberId ? __('Edit Panelist Profile') : __('Add Panelist to Board Member Pool') }}" description="{{ __('Directory of distinguished panel experts, former civil servants, and guest interviewers.') }}">
        <form wire:submit="savePoolMember" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Full Name') }} *</label>
                    <input type="text" wire:model="poolMemberForm.name" required class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Designation / Former Cadre') }}</label>
                    <input type="text" wire:model="poolMemberForm.designation" placeholder="e.g. Ex-IAS, Former Secretary" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Organization') }}</label>
                    <input type="text" wire:model="poolMemberForm.organization" placeholder="e.g. Govt of West Bengal" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Email Address') }}</label>
                    <input type="email" wire:model="poolMemberForm.email" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div>
                    <label class="text-xs font-semibold text-foreground">{{ __('Phone / WhatsApp') }}</label>
                    <input type="text" wire:model="poolMemberForm.phone" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Specialization & Domain') }}</label>
                    <input type="text" wire:model="poolMemberForm.specialization" placeholder="e.g. International Relations, Internal Security, Ethics" class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs focus:border-primary focus:outline-none"/>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Brief Bio / Experience Dossier') }}</label>
                    <textarea wire:model="poolMemberForm.bio" rows="2" class="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:border-primary focus:outline-none"></textarea>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Profile Photo') }}</label>
                    <div class="flex items-center gap-3">
                        @if ($poolMemberAvatar)
                            <img src="{{ $poolMemberAvatar->temporaryUrl() }}" class="w-12 h-12 rounded-full object-cover border border-border">
                        @elseif ($existingAvatarUrl && ! $removeAvatar)
                            <img src="{{ $existingAvatarUrl }}" class="w-12 h-12 rounded-full object-cover border border-border">
                        @else
                            <div class="w-12 h-12 rounded-full bg-secondary flex items-center justify-center text-muted-foreground text-xs font-bold">
                                <x-icon name="user" class="w-6 h-6" />
                            </div>
                        @endif
                        <div class="flex-1 space-y-1">
                            <input type="file" wire:model="poolMemberAvatar" accept="image/*" class="h-9 w-full text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-primary file:text-white file:text-xs cursor-pointer"/>
                            @if ($poolMemberAvatar || ($existingAvatarUrl && ! $removeAvatar))
                                <div>
                                    <button type="button" wire:click="$set('removeAvatar', true); $set('poolMemberAvatar', null)" class="inline-flex items-center gap-1 text-[11px] text-destructive hover:underline cursor-pointer">
                                        <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                        <span>{{ __('Remove photo') }}</span>
                                    </button>
                                </div>
                            @endif
                            @if ($removeAvatar)
                                <span class="text-[11px] text-amber-500 font-medium block">{{ __('Photo will be removed upon saving.') }}</span>
                            @endif
                            @error('poolMemberAvatar') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>

                <div class="col-span-full">
                    <label class="text-xs font-semibold text-foreground">{{ __('Status') }}</label>
                    <select wire:model="poolMemberForm.status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:border-primary focus:outline-none">
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('board-member-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    {{ $editingPoolMemberId ? __('Update Profile') : __('Save Panelist') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 11. IMPORT SESSIONS MODAL -->
    <x-ui.modal name="import-sessions-modal" maxWidth="max-w-lg" title="{{ __('Import Mock Interview Sessions') }}" description="{{ __('Bulk import mock interview sessions from a CSV or Excel file.') }}">
        <form wire:submit="importSessions" class="space-y-4">
            <div class="rounded-lg border border-border bg-muted/40 p-3 space-y-2 text-xs">
                <div class="flex items-center justify-between">
                    <span class="font-bold text-foreground">{{ __('CSV Template Guide') }}</span>
                    <button type="button" wire:click="downloadSessionImportTemplate" class="text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        <span>{{ __('Download Template CSV') }}</span>
                    </button>
                </div>
                <p class="text-muted-foreground text-[11px]">
                    {{ __('Format: Title, Year, Session No, Date (YYYY-MM-DD), Start Time, End Time, Reporting Time, Duration, Buffer, Venue Name, Address, Room, Instructions, Remarks, Status.') }}
                </p>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Upload CSV / Excel File') }} *</label>
                <input type="file" wire:model="sessionImportFile" accept=".csv,.xlsx,.txt" required class="h-9 w-full text-xs file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:bg-primary file:text-white file:text-xs cursor-pointer"/>
                @error('sessionImportFile') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('import-sessions-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    <x-icon name="upload" class="h-3.5 w-3.5 mr-1"/>
                    <span>{{ __('Import Sessions') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 12. IMPORT BOARD MEMBERS MODAL -->
    <x-ui.modal name="import-board-members-modal" maxWidth="max-w-lg" title="{{ __('Import Panelists into Directory') }}" description="{{ __('Bulk import interview board panel members from a CSV or Excel file.') }}">
        <form wire:submit="importBoardMembers" class="space-y-4">
            <div class="rounded-lg border border-border bg-muted/40 p-3 space-y-2 text-xs">
                <div class="flex items-center justify-between">
                    <span class="font-bold text-foreground">{{ __('CSV Template Guide') }}</span>
                    <button type="button" wire:click="downloadBoardMemberImportTemplate" class="text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        <span>{{ __('Download Template CSV') }}</span>
                    </button>
                </div>
                <p class="text-muted-foreground text-[11px]">
                    {{ __('Format: Name, Email, Phone, Designation, Organization, Specialization, Bio, WhatsApp, Telegram, Status.') }}
                </p>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground">{{ __('Upload CSV / Excel File') }} *</label>
                <input type="file" wire:model="boardMemberImportFile" accept=".csv,.xlsx,.txt" required class="h-9 w-full text-xs file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:bg-primary file:text-white file:text-xs cursor-pointer"/>
                @error('boardMemberImportFile') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('import-board-members-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="bg-primary text-primary-foreground">
                    <x-icon name="upload" class="h-3.5 w-3.5 mr-1"/>
                    <span>{{ __('Import Panelists') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- 13. ASPIRANT STUDENT DETAILS & DOSSIER MODAL -->
    <x-ui.modal name="candidate-details-modal" maxWidth="max-w-4xl" title="{{ __('Aspirant Detailed Profile & Mock Interview Dossier') }}" description="{{ __('Comprehensive candidate profile, UPSC examination credentials, attending timetable, and panel evaluation.') }}">
        @if ($viewingCandidate)
            @php
                $u = $viewingCandidate->user;
                $p = $u->studentProfile;
                $o = $u->studentOtherDetails;
                $teamSlug = auth()->user()->currentTeam?->slug ?? 'default';
                $studentTabUrl = route('admin.students.index', ['search' => $u->student_id ?: $u->email, 'current_team' => $teamSlug]);
            @endphp

            <div class="space-y-4" x-data="{ candidateTab: 'interview' }">
                <!-- Top Header Profile Banner -->
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3.5 rounded-xl border border-border bg-muted/30">
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold text-base shrink-0 border border-primary/20 select-none">
                            {{ strtoupper(substr($u->name, 0, 2)) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h4 class="font-bold text-foreground text-sm">{{ $u->name }}</h4>
                                <span class="font-mono text-xs font-semibold px-2 py-0.5 rounded-md bg-secondary text-secondary-foreground border border-border">
                                    {{ $u->student_id ?: __('ID: N/A') }}
                                </span>
                                @if ($viewingCandidate->isOnline())
                                    <span class="inline-flex items-center gap-1 rounded-full bg-purple-500/10 px-2 py-0.5 text-[10px] font-semibold text-purple-600 dark:text-purple-400 border border-purple-500/20">
                                        <x-icon name="video" class="h-3 w-3"/>
                                        {{ __('Online Remote') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-2 py-0.5 text-[10px] font-semibold text-blue-600 dark:text-blue-400 border border-blue-500/20">
                                        <x-icon name="map-pin" class="h-3 w-3"/>
                                        {{ __('In-Person Offline') }}
                                    </span>
                                @endif
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold text-{{ $viewingCandidate->status_badge_color }}-700 bg-{{ $viewingCandidate->status_badge_color }}-500/10 border border-{{ $viewingCandidate->status_badge_color }}-500/20">
                                    {{ ucfirst($viewingCandidate->status) }}
                                </span>
                            </div>

                            <div class="flex items-center gap-3 text-xs text-muted-foreground mt-1 flex-wrap">
                                <span class="flex items-center gap-1">
                                    <x-icon name="mail" class="h-3.5 w-3.5 text-primary"/>
                                    {{ $u->email }}
                                </span>
                                @if ($u->phone)
                                    <span class="flex items-center gap-1">
                                        <x-icon name="phone" class="h-3.5 w-3.5 text-emerald-500"/>
                                        {{ $u->phone }}
                                    </span>
                                @endif
                                @if ($p?->category)
                                    <span class="font-medium text-foreground">
                                        {{ __('Category:') }} {{ $p->category }}
                                    </span>
                                @endif
                                @if ($o?->optional_subject)
                                    <span class="inline-flex items-center rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400 border border-amber-500/20">
                                        {{ __('Optional:') }} {{ $o->optional_subject }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Direct Open in New Tab Action -->
                    <a
                        href="{{ $studentTabUrl }}"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-secondary transition-colors shrink-0 shadow-xs cursor-pointer"
                        title="{{ __('Open complete student profile in a new browser tab') }}"
                    >
                        <x-icon name="external-link" class="h-3.5 w-3.5 text-primary"/>
                        <span>{{ __('Open in New Tab') }}</span>
                    </a>
                </div>

                <!-- Navigation Tabs -->
                <div class="flex items-center gap-1 p-1 rounded-lg bg-muted/60 text-xs border border-border/50 overflow-x-auto">
                    <button
                        type="button"
                        x-on:click="candidateTab = 'interview'"
                        :class="candidateTab === 'interview' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all cursor-pointer whitespace-nowrap text-xs"
                    >
                        <x-icon name="calendar" class="h-3.5 w-3.5"/>
                        <span>{{ __('Mock Interview & Timetable') }}</span>
                    </button>

                    <button
                        type="button"
                        x-on:click="candidateTab = 'upsc'"
                        :class="candidateTab === 'upsc' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all cursor-pointer whitespace-nowrap text-xs"
                    >
                        <x-icon name="award" class="h-3.5 w-3.5"/>
                        <span>{{ __('UPSC & DAF Profile') }}</span>
                    </button>

                    <button
                        type="button"
                        x-on:click="candidateTab = 'personal'"
                        :class="candidateTab === 'personal' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all cursor-pointer whitespace-nowrap text-xs"
                    >
                        <x-icon name="user" class="h-3.5 w-3.5"/>
                        <span>{{ __('Personal & Addresses') }}</span>
                    </button>

                    <button
                        type="button"
                        x-on:click="candidateTab = 'academics'"
                        :class="candidateTab === 'academics' ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-all cursor-pointer whitespace-nowrap text-xs"
                    >
                        <x-icon name="book-open" class="h-3.5 w-3.5"/>
                        <span>{{ __('Academics & Enrollments') }}</span>
                    </button>
                </div>

                <!-- Tab 1: Mock Interview & Timetable -->
                <div x-show="candidateTab === 'interview'" class="space-y-3.5">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="rounded-lg border border-border bg-card p-3 space-y-1">
                            <span class="text-[11px] font-medium text-muted-foreground">{{ __('Appearance Order') }}</span>
                            <div class="text-base font-bold font-mono text-primary">#{{ $viewingCandidate->sequence_number }}</div>
                            <span class="text-[10px] text-muted-foreground">{{ __('Slot in session appearance order') }}</span>
                        </div>

                        <div class="rounded-lg border border-border bg-card p-3 space-y-1">
                            <span class="text-[11px] font-medium text-muted-foreground">{{ __('Scheduled Slot Time') }}</span>
                            <div class="text-sm font-bold text-foreground">{{ $viewingCandidate->formatted_slot_time }}</div>
                            <span class="text-[10px] text-muted-foreground">{{ __('Duration:') }} {{ $viewingCandidate->duration_minutes }} {{ __('Mins') }}</span>
                        </div>

                        <div class="rounded-lg border border-border bg-card p-3 space-y-1">
                            <span class="text-[11px] font-medium text-muted-foreground">{{ __('Reporting Time') }}</span>
                            <div class="text-sm font-bold text-foreground">
                                {{ Carbon::parse($viewingCandidate->scheduled_start_time)->subMinutes(20)->format('h:i A') }}
                            </div>
                            <span class="text-[10px] text-muted-foreground">{{ __('20 mins prior to interview slot') }}</span>
                        </div>
                    </div>

                    <!-- Platform & Venue Specifications -->
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-2">
                        <h5 class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            <x-icon name="navigation" class="h-3.5 w-3.5 text-primary"/>
                            <span>{{ __('Location & Attendance Specifications') }}</span>
                        </h5>

                        @if ($viewingCandidate->isOnline())
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span class="text-muted-foreground">{{ __('Online Video Platform:') }}</span>
                                    <div class="font-semibold text-foreground mt-0.5">{{ $viewingCandidate->platform ?: 'Online' }}</div>
                                </div>
                                <div>
                                    <span class="text-muted-foreground">{{ __('Direct Join Link:') }}</span>
                                    <div class="mt-0.5">
                                        @if ($viewingCandidate->meeting_link)
                                            <a href="{{ $viewingCandidate->meeting_link }}" target="_blank" class="text-primary hover:underline font-mono break-all inline-flex items-center gap-1">
                                                <span>{{ $viewingCandidate->meeting_link }}</span>
                                                <x-icon name="external-link" class="h-3 w-3 shrink-0"/>
                                            </a>
                                        @else
                                            <span class="text-muted-foreground italic">{{ __('Not provided') }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <span class="text-muted-foreground">{{ __('Meeting ID:') }}</span>
                                    <div class="font-mono text-foreground font-semibold mt-0.5">{{ $viewingCandidate->meeting_id ?: '—' }}</div>
                                </div>
                                <div>
                                    <span class="text-muted-foreground">{{ __('Meeting Passcode:') }}</span>
                                    <div class="font-mono text-foreground font-semibold mt-0.5">{{ $viewingCandidate->meeting_password ?: '—' }}</div>
                                </div>
                            </div>
                        @else
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div class="sm:col-span-2">
                                    <span class="text-muted-foreground">{{ __('Offline Physical Venue Address:') }}</span>
                                    <div class="font-semibold text-foreground mt-0.5">{{ $viewingCandidate->getEffectiveVenueAddress() }}</div>
                                </div>
                                <div>
                                    <span class="text-muted-foreground">{{ __('Interview Board Room:') }}</span>
                                    <div class="font-semibold text-foreground mt-0.5">{{ $viewingCandidate->room_number ?: ($viewingCandidate->session->room_number ?? '—') }}</div>
                                </div>
                                <div>
                                    <span class="text-muted-foreground">{{ __('Session Code & Title:') }}</span>
                                    <div class="font-semibold text-foreground mt-0.5">{{ $viewingCandidate->session->session_code }} ({{ $viewingCandidate->session->title }})</div>
                                </div>
                            </div>
                        @endif

                        @if ($viewingCandidate->candidate_instructions)
                            <div class="border-t border-border pt-2 text-xs">
                                <span class="text-muted-foreground font-semibold">{{ __('Candidate Specific Instructions:') }}</span>
                                <p class="text-foreground mt-0.5 whitespace-pre-wrap">{!! nl2br(e($viewingCandidate->candidate_instructions)) !!}</p>
                            </div>
                        @endif
                    </div>

                    <!-- Evaluation & Panel Feedback -->
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-2">
                        <div class="flex items-center justify-between">
                            <h5 class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <x-icon name="clipboard-check" class="h-3.5 w-3.5 text-emerald-500"/>
                                <span>{{ __('Panel Evaluation & Score') }}</span>
                            </h5>
                            @if ($viewingCandidate->interview_score !== null)
                                <span class="font-mono font-bold text-sm text-foreground bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-2.5 py-0.5 rounded-md border border-emerald-500/20">
                                    {{ number_format($viewingCandidate->interview_score, 1) }} / 275 Marks
                                </span>
                            @else
                                <span class="text-[11px] text-muted-foreground italic">{{ __('Pending Panel Evaluation') }}</span>
                            @endif
                        </div>

                        @if ($viewingCandidate->board_feedback)
                            <div class="rounded bg-muted/40 p-2.5 text-xs text-foreground leading-relaxed whitespace-pre-wrap">
                                {!! nl2br(e($viewingCandidate->board_feedback)) !!}
                            </div>
                        @else
                            <p class="text-xs text-muted-foreground italic">{{ __('No panel remarks recorded yet.') }}</p>
                        @endif
                    </div>

                    <!-- Video Recording Status -->
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-2">
                        <div class="flex items-center justify-between">
                            <h5 class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <x-icon name="film" class="h-3.5 w-3.5 text-sky-500"/>
                                <span>{{ __('Interview Video Recording') }}</span>
                            </h5>
                            @if ($viewingCandidate->hasRecording())
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">
                                    <x-icon name="check-circle" class="h-3 w-3"/>
                                    {{ __('Recording Available') }}
                                </span>
                            @else
                                <span class="text-[10px] text-muted-foreground italic">{{ __('Not uploaded / attached') }}</span>
                            @endif
                        </div>

                        @if ($viewingCandidate->hasRecording())
                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                <a
                                    href="{{ $viewingCandidate->getEffectiveRecordingUrl() }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-sky-500/10 text-sky-600 dark:text-sky-400 px-3 py-1.5 text-xs font-semibold hover:bg-sky-500/20 transition-colors"
                                >
                                    <x-icon name="play" class="h-3.5 w-3.5"/>
                                    <span>{{ __('Open Recording Link') }}</span>
                                    <x-icon name="external-link" class="h-3 w-3"/>
                                </a>

                                @if ($viewingCandidate->recording_password)
                                    <span class="text-xs font-mono text-muted-foreground">
                                        {{ __('Passcode:') }} <strong class="text-foreground">{{ $viewingCandidate->recording_password }}</strong>
                                    </span>
                                @endif

                                @if ($viewingCandidate->recording_sent_at)
                                    <span class="text-[11px] text-muted-foreground ml-auto">
                                        {{ __('Sent to Candidate:') }} {{ Carbon::parse($viewingCandidate->recording_sent_at)->format('d M Y, h:i A') }}
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Tab 2: UPSC & DAF Profile -->
                <div x-show="candidateTab === 'upsc'" class="space-y-3.5">
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-3">
                        <h5 class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            <x-icon name="award" class="h-3.5 w-3.5 text-primary"/>
                            <span>{{ __('UPSC Examination & DAF Information') }}</span>
                        </h5>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                            <div>
                                <span class="text-muted-foreground">{{ __('UPSC Mains Optional Subject:') }}</span>
                                <div class="font-bold text-foreground text-sm mt-0.5">
                                    {{ $o?->optional_subject ?: ($p?->upsc_community ?? '—') }}
                                </div>
                            </div>

                            <div>
                                <span class="text-muted-foreground">{{ __('UPSC Roll Number / Reg No:') }}</span>
                                <div class="font-bold font-mono text-foreground mt-0.5">
                                    {{ $o?->dynamic_fields['upsc_roll_no'] ?? ($u->upscAttempts->first()?->roll_number ?? '—') }}
                                </div>
                            </div>

                            <div>
                                <span class="text-muted-foreground">{{ __('Medium / Language:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">
                                    {{ $p?->school_language ?: 'English' }}
                                </div>
                            </div>

                            <div>
                                <span class="text-muted-foreground">{{ __('Attempts Already Given:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">
                                    {{ $o?->attempts_already_given ?? '0' }}
                                </div>
                            </div>

                            <div>
                                <span class="text-muted-foreground">{{ __('Attempts Remaining:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">
                                    {{ $o?->attempts_left ?? '—' }}
                                </div>
                            </div>

                            <div>
                                <span class="text-muted-foreground">{{ __('Year of Appearing:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">
                                    {{ $o?->year_of_appearing ?? date('Y') }}
                                </div>
                            </div>
                        </div>

                        <!-- DAF Extracurricular & Hobbies -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-border pt-3 text-xs">
                            <div class="space-y-1">
                                <span class="font-semibold text-foreground">{{ __('DAF Hobbies & Interests:') }}</span>
                                <p class="text-muted-foreground bg-muted/30 p-2.5 rounded-md leading-relaxed whitespace-pre-wrap">
                                    {{ $o?->hobbies ?: ($p?->hobbies ?: __('No specific hobbies recorded.')) }}
                                </p>
                            </div>

                            <div class="space-y-1">
                                <span class="font-semibold text-foreground">{{ __('DAF Extracurricular Activities & Leadership:') }}</span>
                                <p class="text-muted-foreground bg-muted/30 p-2.5 rounded-md leading-relaxed whitespace-pre-wrap">
                                    {{ $o?->activities ?: ($p?->activities ?: __('No extracurricular activities recorded.')) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- UPSC Past Attempts History -->
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-2">
                        <h5 class="text-xs font-bold text-foreground">{{ __('UPSC Civil Services Examination Attempts Record') }}</h5>
                        <div class="border border-border rounded-md overflow-hidden text-xs">
                            <table class="w-full text-left">
                                <thead class="bg-muted/50 border-b border-border text-[11px] text-muted-foreground">
                                    <tr>
                                        <th class="p-2">{{ __('Exam Year') }}</th>
                                        <th class="p-2">{{ __('Roll No') }}</th>
                                        <th class="p-2 text-center">{{ __('Prelims') }}</th>
                                        <th class="p-2 text-center">{{ __('Mains') }}</th>
                                        <th class="p-2 text-center">{{ __('Interview Stage') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($u->upscAttempts as $att)
                                        <tr>
                                            <td class="p-2 font-bold font-mono">{{ $att->exam_year }}</td>
                                            <td class="p-2 font-mono text-muted-foreground">{{ $att->roll_number ?: '—' }}</td>
                                            <td class="p-2 text-center">
                                                <span @class([
                                                    'inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold',
                                                    'bg-emerald-500/10 text-emerald-600' => $att->prelims_cleared,
                                                    'bg-muted text-muted-foreground' => ! $att->prelims_cleared,
                                                ])>
                                                    {{ $att->prelims_cleared ? __('Cleared') : __('No') }}
                                                </span>
                                            </td>
                                            <td class="p-2 text-center">
                                                <span @class([
                                                    'inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold',
                                                    'bg-emerald-500/10 text-emerald-600' => $att->mains_cleared,
                                                    'bg-muted text-muted-foreground' => ! $att->mains_cleared,
                                                ])>
                                                    {{ $att->mains_cleared ? __('Cleared') : __('No') }}
                                                </span>
                                            </td>
                                            <td class="p-2 text-center">
                                                <span @class([
                                                    'inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold',
                                                    'bg-emerald-500/10 text-emerald-600' => $att->interview_cleared,
                                                    'bg-muted text-muted-foreground' => ! $att->interview_cleared,
                                                ])>
                                                    {{ $att->interview_cleared ? __('Cleared') : __('No') }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="p-3 text-center text-muted-foreground italic text-xs">
                                                {{ __('No prior examination attempts logged on file.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Personal & Addresses -->
                <div x-show="candidateTab === 'personal'" class="space-y-3.5">
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-3">
                        <h5 class="text-xs font-bold text-foreground">{{ __('Personal & Guardian Details') }}</h5>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                            <div>
                                <span class="text-muted-foreground">{{ __('Father\'s Name:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">{{ $p?->father_name ?: '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Mother\'s Name:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">{{ $p?->mother_name ?: '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Date of Birth & Gender:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">
                                    {{ $p?->dob ? Carbon::parse($p->dob)->format('d M Y') : '—' }}
                                    @if ($p?->gender)
                                        ({{ ucfirst($p->gender) }})
                                    @endif
                                </div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Category:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">{{ $p?->category ?: 'General' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Subcaste:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">{{ $p?->subcaste ?: '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('PwBD Status:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">
                                    {{ $p?->is_pwbd ? __('Yes') . ' (' . ($p->pwbd_type ?? 'N/A') . ')' : __('No') }}
                                </div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Occupation:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">{{ $p?->occupation ?: ($p?->is_working_professional ? __('Working Professional') : __('Student / Aspirant')) }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Family Annual Income:') }}</span>
                                <div class="font-semibold text-foreground mt-0.5">₹{{ number_format($p?->family_income ?? 0, 2) }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information -->
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-2">
                        <h5 class="text-xs font-bold text-foreground">{{ __('Residential Addresses') }}</h5>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            @php
                                $perm = $u->studentAddresses->where('address_type', 'permanent')->first();
                                $corr = $u->studentAddresses->where('address_type', 'correspondence')->first() ?: $perm;
                            @endphp
                            <div class="p-3 rounded-lg border border-border bg-muted/20 space-y-1">
                                <span class="font-bold text-primary uppercase text-[10px] block">{{ __('Permanent Address') }}</span>
                                @if ($perm)
                                    <p class="text-foreground leading-relaxed">
                                        {{ $perm->address_line1 }}{{ $perm->address_line2 ? ', ' . $perm->address_line2 : '' }}<br>
                                        {{ $perm->district ? $perm->district . ', ' : '' }}{{ $perm->state ? $perm->state . ' - ' : '' }}{{ $perm->pin_code }}
                                    </p>
                                @else
                                    <p class="text-muted-foreground italic">{{ __('No permanent address on file.') }}</p>
                                @endif
                            </div>

                            <div class="p-3 rounded-lg border border-border bg-muted/20 space-y-1">
                                <span class="font-bold text-indigo-500 uppercase text-[10px] block">{{ __('Correspondence Address') }}</span>
                                @if ($corr)
                                    <p class="text-foreground leading-relaxed">
                                        {{ $corr->address_line1 }}{{ $corr->address_line2 ? ', ' . $corr->address_line2 : '' }}<br>
                                        {{ $corr->district ? $corr->district . ', ' : '' }}{{ $corr->state ? $corr->state . ' - ' : '' }}{{ $corr->pin_code }}
                                    </p>
                                @else
                                    <p class="text-muted-foreground italic">{{ __('Same as permanent address.') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 4: Academics & Enrollments -->
                <div x-show="candidateTab === 'academics'" class="space-y-3.5">
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-2">
                        <div class="flex items-center justify-between">
                            <h5 class="text-xs font-bold text-foreground">{{ __('Educational Qualifications') }}</h5>
                            <span class="text-[11px] text-muted-foreground">{{ __('Highest:') }} <strong class="text-foreground">{{ $p?->highest_edu ?: '—' }}</strong></span>
                        </div>
                        <div class="border border-border rounded-md overflow-hidden text-xs">
                            <table class="w-full text-left">
                                <thead class="bg-muted/50 border-b border-border text-[11px] text-muted-foreground">
                                    <tr>
                                        <th class="p-2">{{ __('Level / Degree') }}</th>
                                        <th class="p-2">{{ __('Board / University') }}</th>
                                        <th class="p-2">{{ __('Stream / Subject') }}</th>
                                        <th class="p-2">{{ __('Year') }}</th>
                                        <th class="p-2 text-right">{{ __('Percentage / CGPA') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($u->studentEducations as $edu)
                                        <tr>
                                            <td class="p-2 font-semibold text-foreground">{{ $edu->level }}</td>
                                            <td class="p-2 text-muted-foreground">{{ $edu->board_university ?: $edu->institute }}</td>
                                            <td class="p-2">{{ $edu->stream_subject ?: '—' }}</td>
                                            <td class="p-2 font-mono">{{ $edu->passing_year ?: '—' }}</td>
                                            <td class="p-2 text-right font-mono font-bold">{{ $edu->percentage ? $edu->percentage . '%' : ($edu->cgpa ? $edu->cgpa . ' CGPA' : '—') }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="p-3 text-center text-muted-foreground italic text-xs">
                                                {{ __('No qualifications listed.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Enrolled Courses at SNTCSSC -->
                    <div class="rounded-lg border border-border bg-card p-3.5 space-y-2">
                        <h5 class="text-xs font-bold text-foreground">{{ __('Course & Batch Enrollments at SNTCSSC') }}</h5>
                        <div class="border border-border rounded-md overflow-hidden text-xs">
                            <table class="w-full text-left">
                                <thead class="bg-muted/50 border-b border-border text-[11px] text-muted-foreground">
                                    <tr>
                                        <th class="p-2">{{ __('Course') }}</th>
                                        <th class="p-2">{{ __('Batch') }}</th>
                                        <th class="p-2">{{ __('Enrolled Date') }}</th>
                                        <th class="p-2 text-right">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($u->enrollments as $enr)
                                        <tr>
                                            <td class="p-2 font-semibold text-foreground">{{ $enr->course->name ?? '—' }}</td>
                                            <td class="p-2">{{ $enr->batch->name ?? '—' }}</td>
                                            <td class="p-2 font-mono text-muted-foreground">{{ $enr->created_at ? $enr->created_at->format('d M Y') : '—' }}</td>
                                            <td class="p-2 text-right">
                                                <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-600">
                                                    {{ ucfirst($enr->status ?? 'Active') }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="p-3 text-center text-muted-foreground italic text-xs">
                                                {{ __('No active course enrollments.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="flex items-center justify-between border-t border-border pt-4">
                    <a
                        href="{{ $studentTabUrl }}"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 text-xs text-primary hover:underline font-semibold cursor-pointer"
                        title="{{ __('Open complete student dossier in a new browser tab') }}"
                    >
                        <x-icon name="external-link" class="h-3.5 w-3.5"/>
                        <span>{{ __('Open Complete Student Dossier in New Tab') }}</span>
                    </a>

                    <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('candidate-details-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- 14. UPSC MOCK INTERVIEW REPORTS & ANALYTICS MODAL -->
    <x-ui.modal name="mock-interview-reports-modal" maxWidth="max-w-6xl" title="{{ __('UPSC Mock Interview Reports & Performance Analytics') }}" description="{{ __('Multi-dimensional reporting across Aspirants, Board Members, and Academic Cycles with instant export to PDF, Excel, and CSV.') }}">
        @php
            $reportData = $this->buildReportData();
        @endphp

        <div class="space-y-4">
            <!-- Perspective Selector Tabs & Export Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-border pb-3">
                <div class="flex flex-wrap items-center gap-1.5 p-1 rounded-lg bg-muted/60 text-xs">
                    <button
                        type="button"
                        wire:click="$set('reportType', 'student_wise')"
                        @class([
                            'inline-flex items-center gap-2 px-3 py-1.5 rounded-md font-medium transition-all cursor-pointer text-xs',
                            'bg-background text-foreground shadow-xs font-semibold' => $reportType === 'student_wise',
                            'text-muted-foreground hover:text-foreground' => $reportType !== 'student_wise',
                        ])
                    >
                        <x-icon name="user" class="h-3.5 w-3.5 text-primary"/>
                        <span>{{ __('🎓 Student Wise Report') }}</span>
                    </button>

                    <button
                        type="button"
                        wire:click="$set('reportType', 'board_member_wise')"
                        @class([
                            'inline-flex items-center gap-2 px-3 py-1.5 rounded-md font-medium transition-all cursor-pointer text-xs',
                            'bg-background text-foreground shadow-xs font-semibold' => $reportType === 'board_member_wise',
                            'text-muted-foreground hover:text-foreground' => $reportType !== 'board_member_wise',
                        ])
                    >
                        <x-icon name="users" class="h-3.5 w-3.5 text-amber-500"/>
                        <span>{{ __('👥 Board Member Wise Report') }}</span>
                    </button>

                    <button
                        type="button"
                        wire:click="$set('reportType', 'year_wise')"
                        @class([
                            'inline-flex items-center gap-2 px-3 py-1.5 rounded-md font-medium transition-all cursor-pointer text-xs',
                            'bg-background text-foreground shadow-xs font-semibold' => $reportType === 'year_wise',
                            'text-muted-foreground hover:text-foreground' => $reportType !== 'year_wise',
                        ])
                    >
                        <x-icon name="calendar" class="h-3.5 w-3.5 text-emerald-500"/>
                        <span>{{ __('📅 Year Wise & Session Overview') }}</span>
                    </button>
                </div>

                <!-- Export Action Buttons -->
                <div class="flex items-center gap-2 shrink-0">
                    <x-ui.button
                        type="button"
                        size="sm"
                        wire:click="exportReport('pdf')"
                        class="gap-1.5 h-8 text-xs cursor-pointer bg-rose-600 hover:bg-rose-700 text-white"
                        title="{{ __('Download formatted landscape PDF report') }}"
                    >
                        <x-icon name="file-down" class="h-3.5 w-3.5"/>
                        <span>{{ __('Export PDF') }}</span>
                    </x-ui.button>

                    <x-ui.button
                        type="button"
                        size="sm"
                        wire:click="exportReport('xlsx')"
                        class="gap-1.5 h-8 text-xs cursor-pointer bg-emerald-600 hover:bg-emerald-700 text-white"
                        title="{{ __('Download formatted Microsoft Excel (.xlsx) spreadsheet') }}"
                    >
                        <x-icon name="file-spreadsheet" class="h-3.5 w-3.5"/>
                        <span>{{ __('Export Excel') }}</span>
                    </x-ui.button>

                    <x-ui.button
                        type="button"
                        size="sm"
                        wire:click="exportReport('csv')"
                        class="gap-1.5 h-8 text-xs cursor-pointer bg-blue-600 hover:bg-blue-700 text-white"
                        title="{{ __('Download comma-separated values (.csv) data') }}"
                    >
                        <x-icon name="file-text" class="h-3.5 w-3.5"/>
                        <span>{{ __('Export CSV') }}</span>
                    </x-ui.button>
                </div>
            </div>

            <!-- Dynamic Filter Controls Card -->
            <div class="rounded-xl border border-border bg-muted/20 p-3 sm:p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                        <x-icon name="filter" class="h-3.5 w-3.5 text-primary"/>
                        {{ __('Filter Report Data') }}
                    </span>
                    <button
                        type="button"
                        wire:click="resetReportFilters"
                        class="text-[11px] text-muted-foreground hover:text-foreground flex items-center gap-1 cursor-pointer transition-colors"
                        title="{{ __('Reset all report filters to default') }}"
                    >
                        <x-icon name="refresh-cw" class="h-3 w-3"/>
                        <span>{{ __('Reset Filters') }}</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2.5 text-xs">
                    <!-- Academic Cycle -->
                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Academic Cycle') }}</label>
                        <select wire:model.live="reportAcademicYear" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                            <option value="">{{ __('All Cycles') }}</option>
                            @foreach ($this->availableAcademicYears as $year)
                                <option value="{{ $year }}">{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Mock Interview Session -->
                    @if ($reportType !== 'year_wise')
                        <div class="sm:col-span-2">
                            <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Interview Session') }}</label>
                            <select wire:model.live="reportSessionId" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                                <option value="">{{ __('All Sessions') }}</option>
                                @foreach ($this->reportFilterSessions as $s)
                                    <option value="{{ $s->id }}">{{ $s->session_code }} — {{ $s->title }} ({{ $s->formatted_date }})</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <!-- Student Wise Specific Filters -->
                    @if ($reportType === 'student_wise')
                        <!-- Mode Filter -->
                        <div>
                            <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Attending Mode') }}</label>
                            <select wire:model.live="reportMode" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                                <option value="">{{ __('All Modes') }}</option>
                                <option value="offline">{{ __('In-Person (Offline)') }}</option>
                                <option value="online">{{ __('Remote (Online)') }}</option>
                            </select>
                        </div>

                        <!-- Evaluation Status -->
                        <div>
                            <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Evaluation Status') }}</label>
                            <select wire:model.live="reportEvaluationStatus" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                                <option value="">{{ __('All Aspirants') }}</option>
                                <option value="evaluated">{{ __('Evaluated (Marks Given)') }}</option>
                                <option value="pending">{{ __('Pending Marks') }}</option>
                            </select>
                        </div>

                        <!-- Candidate Attendance Status -->
                        <div>
                            <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Attendance Status') }}</label>
                            <select wire:model.live="reportStatus" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                                <option value="">{{ __('All Statuses') }}</option>
                                <option value="scheduled">{{ __('Scheduled') }}</option>
                                <option value="completed">{{ __('Completed') }}</option>
                                <option value="in_progress">{{ __('In Progress') }}</option>
                                <option value="absent">{{ __('Absent') }}</option>
                                <option value="cancelled">{{ __('Cancelled') }}</option>
                            </select>
                        </div>
                    @endif

                    <!-- Board Member Wise Specific Filters -->
                    @if ($reportType === 'board_member_wise')
                        <div class="sm:col-span-2">
                            <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Board Panelist') }}</label>
                            <select wire:model.live="reportBoardMemberId" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                                <option value="">{{ __('All Panelists') }}</option>
                                @foreach ($this->reportFilterBoardMembers as $bm)
                                    <option value="{{ $bm->id }}">{{ $bm->name }} ({{ $bm->designation ?: 'Expert' }})</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <!-- Year Wise Specific Filters -->
                    @if ($reportType === 'year_wise')
                        <div>
                            <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('Session Status') }}</label>
                            <select wire:model.live="reportStatus" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none">
                                <option value="">{{ __('All Statuses') }}</option>
                                <option value="scheduled">{{ __('Scheduled') }}</option>
                                <option value="in_progress">{{ __('In Progress') }}</option>
                                <option value="completed">{{ __('Completed') }}</option>
                                <option value="draft">{{ __('Draft') }}</option>
                                <option value="cancelled">{{ __('Cancelled') }}</option>
                            </select>
                        </div>
                    @endif

                    <!-- Date Range: Start Date -->
                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('From Date') }}</label>
                        <input type="date" wire:model.live="reportStartDate" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none"/>
                    </div>

                    <!-- Date Range: End Date -->
                    <div>
                        <label class="block text-[11px] font-medium text-muted-foreground mb-1">{{ __('To Date') }}</label>
                        <input type="date" wire:model.live="reportEndDate" class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs focus:border-primary focus:outline-none"/>
                    </div>
                </div>

                <!-- Active Filters Subtitle Indicator -->
                <div class="text-[11px] text-muted-foreground bg-background/50 rounded-md px-2.5 py-1.5 border border-border/50 flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                    <div>
                        <span class="font-semibold text-foreground">{{ __('Active Scope:') }}</span>
                        <span>{{ $reportData['filtersSummary'] }}</span>
                    </div>
                    <span class="font-medium text-primary">{{ count($reportData['rows']) }} {{ __('record(s) found') }}</span>
                </div>
            </div>

            <!-- KPI Metric Summary Stat Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2.5">
                @foreach ($reportData['stats'] as $label => $val)
                    <div class="rounded-lg border border-border bg-card p-3 shadow-2xs">
                        <div class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">{{ __($label) }}</div>
                        <div class="mt-1 text-base sm:text-lg font-bold text-foreground">{{ $val }}</div>
                    </div>
                @endforeach
            </div>

            <!-- Live Tabular Preview of Generated Report -->
            <div class="space-y-2">
                <div class="flex items-center justify-between text-xs font-semibold text-foreground">
                    <span class="flex items-center gap-1.5">
                        <x-icon name="list" class="h-3.5 w-3.5 text-muted-foreground"/>
                        {{ __('Report Data Preview') }}
                    </span>
                    <span class="text-[11px] text-muted-foreground font-normal">
                        {{ __('Live sample preview (showing up to 8 records)') }}
                    </span>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border bg-card">
                    <table class="w-full border-collapse text-left text-xs">
                        <thead class="bg-muted/70 text-muted-foreground border-b border-border">
                            <tr>
                                @foreach ($reportData['headings'] as $head)
                                    <th class="px-3 py-2 font-semibold whitespace-nowrap">{{ __($head) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse (array_slice($reportData['rows'], 0, 8) as $row)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    @foreach ($row as $cell)
                                        <td class="px-3 py-2 whitespace-nowrap text-foreground text-[11px]">{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($reportData['headings']) }}" class="py-8 text-center text-muted-foreground text-xs">
                                        <div class="flex flex-col items-center justify-center gap-1">
                                            <x-icon name="file-text" class="h-6 w-6 text-muted-foreground/40"/>
                                            <p>{{ __('No records found matching the active filter criteria.') }}</p>
                                            <p class="text-[11px]">{{ __('Try adjusting the academic year, session, or date range filters above.') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (count($reportData['rows']) > 8)
                    <p class="text-right text-[11px] text-muted-foreground italic">
                        {{ __('Showing preview of first 8 of :count records. Export PDF, Excel, or CSV to view full dataset.', ['count' => count($reportData['rows'])]) }}
                    </p>
                @endif
            </div>

            <!-- Modal Footer -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-border pt-4">
                <div class="text-[11px] text-muted-foreground">
                    <span>{{ __('Generated:') }} {{ $reportData['generatedAt'] }} • {{ __('By:') }} {{ $reportData['generatedBy'] }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        x-on:click="$store.modals.close('mock-interview-reports-modal')"
                    >
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    </x-ui.modal>

    <!-- 15. CONFIRM DELETE MODAL -->
    <x-ui.modal name="delete-confirm-modal" maxWidth="max-w-md" title="{{ __('Confirm Action') }}" description="{{ __('Are you sure you want to delete this record? This action uses soft deletes and can be audited.') }}">
        <div class="space-y-4">
            <div class="rounded-lg bg-destructive/10 p-3 text-xs text-destructive">
                <strong>{{ __('Target:') }}</strong> {{ $deleteTargetTitle }}
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$store.modals.close('delete-confirm-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" wire:click="executeDelete" class="bg-destructive hover:bg-destructive/90 text-destructive-foreground">
                    {{ __('Confirm Delete') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
