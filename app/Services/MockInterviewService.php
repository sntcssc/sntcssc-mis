<?php

namespace App\Services;

use App\Models\MockInterviewCandidate;
use App\Models\MockInterviewInvitationLog;
use App\Models\MockInterviewSession;
use App\Models\MockInterviewSessionMember;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MockInterviewService
{
    public function __construct(
        protected EmailService $emailService,
        protected SmsService $smsService,
        protected WhatsAppService $whatsAppService,
        protected TelegramService $telegramService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Automatically regenerate sequential interview time slots for candidates in a session.
     */
    public function autoGenerateTimeSlots(
        MockInterviewSession $session,
        ?string $startTime = null,
        ?int $durationMinutes = null,
        ?int $bufferMinutes = null
    ): int {
        return DB::transaction(function () use ($session, $startTime, $durationMinutes, $bufferMinutes) {
            $candidates = $session->candidates()->orderBy('sequence_number', 'asc')->get();

            if ($candidates->isEmpty()) {
                return 0;
            }

            $currentSlotStart = Carbon::parse($startTime ?: $session->start_time);
            $slotDuration = $durationMinutes ?: $session->default_interview_duration ?: 30;
            $slotBuffer = $bufferMinutes ?? ($session->default_buffer_duration ?: 5);

            $sequence = 1;
            foreach ($candidates as $candidate) {
                $currentSlotEnd = (clone $currentSlotStart)->addMinutes($slotDuration);

                $candidate->update([
                    'sequence_number' => $sequence,
                    'scheduled_start_time' => $currentSlotStart->format('H:i:s'),
                    'scheduled_end_time' => $currentSlotEnd->format('H:i:s'),
                    'duration_minutes' => $slotDuration,
                ]);

                $currentSlotStart = (clone $currentSlotEnd)->addMinutes($slotBuffer);
                $sequence++;
            }

            AuditLogService::log(
                event: 'mock_interview_slots_generated',
                description: "Auto-generated time slots for {$candidates->count()} candidate(s) in session {$session->session_code}.",
                auditable: $session
            );

            return $candidates->count();
        });
    }

    /**
     * Render personalized template with placeholder replacements.
     *
     * @param  array<string, mixed>  $variables
     */
    public function renderContent(string $template, array $variables): string
    {
        $search = [];
        $replace = [];

        foreach ($variables as $key => $value) {
            $search[] = '{'.$key.'}';
            $replace[] = (string) ($value ?? '');
        }

        return str_replace($search, $replace, $template);
    }

    /**
     * Get default invitation message template for Board Chairperson.
     */
    public function getDefaultChairmanInvitationTemplate(): string
    {
        return "Respected {name},\n\nYou have been nominated to Chair the Interview Board for {session_title} (Session #{session_no}) for UPSC Personality Test Aspirants.\n\n"
            ."Date: {session_date}\n"
            ."Board Hours: {start_time} to {end_time}\n"
            ."Reporting Time: {reporting_time}\n"
            ."Venue: {venue_address} {room_number}\n"
            ."Total Aspirants: {candidates_count}\n"
            ."Board Members: {board_members_list}\n\n"
            ."Instructions: {instructions}\n\n"
            ."We look forward to your gracious presence and valuable guidance.\n- Satyendranath Tagore Civil Services Study Centre (SNTCSSC)";
    }

    /**
     * Get default invitation message template for regular Board Members.
     */
    public function getDefaultMemberInvitationTemplate(): string
    {
        return "Dear {name},\n\nYou are cordially invited to serve as an esteemed Board Member for {session_title} (Session #{session_no}) for UPSC Personality Test Aspirants.\n\n"
            ."Board Chairperson: {chairperson_name}\n"
            ."Date: {session_date}\n"
            ."Board Hours: {start_time} to {end_time}\n"
            ."Reporting Time: {reporting_time}\n"
            ."Venue: {venue_address} {room_number}\n"
            ."Total Aspirants: {candidates_count}\n\n"
            ."Instructions: {instructions}\n\n"
            ."Thank you for your valuable mentorship.\n- Satyendranath Tagore Civil Services Study Centre (SNTCSSC)";
    }

    /**
     * Get default invitation message template for Candidate Students.
     */
    public function getDefaultStudentInvitationTemplate(): string
    {
        return "Dear {name},\n\nYour UPSC Mock Interview for {session_title} (Session #{session_no}) is scheduled on {session_date}.\n\n"
            ."Mode of Attendance: {attending_mode}\n"
            ."Order of Appearance: Sequence #{sequence_number}\n"
            ."Scheduled Slot: {slot_time} (Duration: {duration_minutes} Minutes)\n"
            ."Reporting / Readiness Time: {reporting_time}\n"
            ."{location_or_platform_details}\n"
            ."Board Chairperson: {chairperson_name}\n\n"
            ."Important Instructions: {candidate_instructions}\n\n"
            ."Please ensure punctuality and formal UPSC attire.\n- Satyendranath Tagore Civil Services Study Centre (SNTCSSC)";
    }

    /**
     * Build placeholder variables for Chairman or Board Member invitation.
     *
     * @return array<string, mixed>
     */
    public function buildMemberVariables(MockInterviewSessionMember $sessionMember): array
    {
        $session = $sessionMember->session;
        $member = $sessionMember->boardMember;
        $chairperson = $session->chairperson();

        $otherMembers = $session->sessionMembers()
            ->with('boardMember')
            ->where('id', '!=', $sessionMember->id)
            ->get()
            ->map(fn ($sm) => $sm->boardMember->name.($sm->role === MockInterviewSessionMember::ROLE_CHAIRPERSON ? ' (Chair)' : ''))
            ->filter()
            ->implode(', ');

        return [
            'name' => $member->name,
            'session_no' => $session->session_number,
            'session_code' => $session->session_code,
            'session_title' => $session->title,
            'session_date' => $session->formatted_date,
            'start_time' => Carbon::parse($session->start_time)->format('h:i A'),
            'end_time' => Carbon::parse($session->end_time)->format('h:i A'),
            'reporting_time' => $session->formatted_reporting_time ?: Carbon::parse($session->start_time)->subMinutes(30)->format('h:i A'),
            'venue_address' => $session->venue_address,
            'room_number' => $session->room_number ? "({$session->room_number})" : '',
            'candidates_count' => $session->candidates()->count(),
            'board_members_list' => $otherMembers ?: 'Panel of experts',
            'chairperson_name' => $chairperson ? $chairperson->name : 'Senior Board Chair',
            'instructions' => $session->instructions ?: 'Standard UPSC Personality Test rubric and DAF evaluation criteria apply.',
            'remarks' => $session->remarks ?: '',
        ];
    }

    /**
     * Build placeholder variables for Candidate Student invitation.
     *
     * @return array<string, mixed>
     */
    public function buildCandidateVariables(MockInterviewCandidate $candidate): array
    {
        $session = $candidate->session;
        $user = $candidate->user;
        $chairperson = $session->chairperson();

        $locationDetails = '';
        if ($candidate->isOnline()) {
            $locationDetails = "Online Meeting Platform: {$candidate->platform}\n"
                ."Meeting Link: {$candidate->meeting_link}\n"
                .'Meeting ID: '.($candidate->meeting_id ?: 'N/A')."\n"
                .'Passcode / Password: '.($candidate->meeting_password ?: 'N/A');
        } else {
            $venue = $candidate->getEffectiveVenueAddress();
            $room = $candidate->room_number ?: $session->room_number;
            $locationDetails = "Interview Venue: {$venue}".($room ? " (Room: {$room})" : '');
        }

        $instructions = $candidate->candidate_instructions
            ?: ($session->instructions ?: 'Bring two copies of your Detailed Application Form (DAF) and wear formal interview attire.');

        return [
            'name' => $user->name,
            'student_id' => $user->student_id ?: 'N/A',
            'session_no' => $session->session_number,
            'session_code' => $session->session_code,
            'session_title' => $session->title,
            'session_date' => $session->formatted_date,
            'attending_mode' => $candidate->isOnline() ? 'ONLINE (Remote Video Conference)' : 'OFFLINE (In-Person at Campus)',
            'sequence_number' => $candidate->sequence_number,
            'slot_time' => $candidate->formatted_slot_time,
            'duration_minutes' => $candidate->duration_minutes,
            'start_time' => Carbon::parse($candidate->scheduled_start_time)->format('h:i A'),
            'end_time' => Carbon::parse($candidate->scheduled_end_time)->format('h:i A'),
            'reporting_time' => Carbon::parse($candidate->scheduled_start_time)->subMinutes(20)->format('h:i A'),
            'location_or_platform_details' => $locationDetails,
            'venue_address' => $candidate->getEffectiveVenueAddress(),
            'platform' => $candidate->platform ?: 'Online Video Conference',
            'meeting_link' => $candidate->meeting_link ?: '',
            'meeting_id' => $candidate->meeting_id ?: '',
            'meeting_password' => $candidate->meeting_password ?: '',
            'chairperson_name' => $chairperson ? $chairperson->name : 'Eminent Board Chair',
            'candidate_instructions' => $instructions,
        ];
    }

    /**
     * Preview invitation content for a Board Member (Chair or regular).
     */
    public function previewMemberInvitation(MockInterviewSessionMember $sessionMember, ?string $customTemplate = null): array
    {
        $session = $sessionMember->session;
        $isChair = $sessionMember->isChairperson();

        $template = $customTemplate
            ?: ($isChair
                ? ($session->chairman_invitation_template ?: $this->getDefaultChairmanInvitationTemplate())
                : ($session->member_invitation_template ?: $this->getDefaultMemberInvitationTemplate()));

        $variables = $this->buildMemberVariables($sessionMember);
        $subject = "UPSC Mock Interview Board Invitation - {$session->title} [Session #{$session->session_number}]";
        $content = $this->renderContent($template, $variables);

        return [
            'recipient_name' => $sessionMember->boardMember->name,
            'recipient_type' => $isChair ? 'chairman' : 'board_member',
            'recipient_email' => $sessionMember->boardMember->email,
            'recipient_phone' => $sessionMember->boardMember->phone,
            'subject' => $subject,
            'content' => $content,
            'variables' => $variables,
        ];
    }

    /**
     * Preview invitation content for a Candidate Student.
     */
    public function previewCandidateInvitation(MockInterviewCandidate $candidate, ?string $customTemplate = null): array
    {
        $session = $candidate->session;
        $template = $customTemplate ?: ($session->student_invitation_template ?: $this->getDefaultStudentInvitationTemplate());
        $variables = $this->buildCandidateVariables($candidate);

        $modeLabel = $candidate->isOnline() ? 'Online' : 'Offline';
        $subject = "UPSC Mock Interview Schedule ({$modeLabel}) - {$session->title} [Slot: {$candidate->formatted_slot_time}]";
        $content = $this->renderContent($template, $variables);

        return [
            'recipient_name' => $candidate->user->name,
            'recipient_type' => 'student',
            'recipient_email' => $candidate->user->email,
            'recipient_phone' => $candidate->user->phone,
            'subject' => $subject,
            'content' => $content,
            'variables' => $variables,
        ];
    }

    /**
     * Dispatch invitation to a Board Member across specified channels.
     *
     * @param  array<string>  $channels
     * @return array<string, bool>
     */
    public function sendMemberInvitation(
        MockInterviewSessionMember $sessionMember,
        array $channels = ['email'],
        ?string $customTemplate = null
    ): array {
        $preview = $this->previewMemberInvitation($sessionMember, $customTemplate);
        $member = $sessionMember->boardMember;
        $session = $sessionMember->session;
        $results = [];

        foreach ($channels as $channel) {
            $status = MockInterviewInvitationLog::STATUS_FAILED;
            $error = null;

            try {
                if ($channel === 'email' && ! empty($member->email)) {
                    $res = $this->emailService->sendDirect(
                        to: $member->email,
                        subject: $preview['subject'],
                        body: nl2br(e($preview['content']))
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'sms' && ! empty($member->phone)) {
                    $res = $this->smsService->sendDirect(
                        phone: $member->phone,
                        message: $preview['content']
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'whatsapp' && ! empty($member->phone)) {
                    $res = $this->whatsAppService->sendDirect(
                        phone: $member->phone,
                        message: $preview['content']
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'telegram') {
                    $res = $this->telegramService->sendDirect(
                        chatId: $member->phone ?? '',
                        message: $preview['content']
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'in_app' && $member->user_id) {
                    $this->notificationService->sendInternal(
                        user: $member->user_id,
                        type: 'mock_interview_invitation',
                        title: $preview['subject'],
                        body: mb_substr($preview['content'], 0, 200).'...',
                        actionUrl: route('admin.mock-interviews.index'),
                        actionLabel: 'View Mock Interview'
                    );
                    $status = MockInterviewInvitationLog::STATUS_SENT;
                }
            } catch (Throwable $e) {
                $status = MockInterviewInvitationLog::STATUS_FAILED;
                $error = $e->getMessage();
                Log::error("Mock Interview Member Invitation error ({$channel}): ".$e->getMessage());
            }

            MockInterviewInvitationLog::create([
                'mock_interview_session_id' => $session->id,
                'mock_interview_session_member_id' => $sessionMember->id,
                'recipient_type' => $sessionMember->isChairperson() ? 'chairman' : 'board_member',
                'recipient_name' => $member->name,
                'recipient_contact' => $channel === 'email' ? $member->email : $member->phone,
                'user_id' => $member->user_id,
                'channel' => $channel,
                'purpose' => MockInterviewInvitationLog::PURPOSE_INVITATION,
                'subject' => $preview['subject'],
                'message_content' => $preview['content'],
                'status' => $status,
                'error_message' => $error,
                'sent_by' => auth()->id(),
                'sent_at' => now(),
            ]);

            $results[$channel] = ($status === MockInterviewInvitationLog::STATUS_SENT);
        }

        $sessionMember->update([
            'invitation_sent_at' => now(),
            'invitation_channels' => array_values(array_unique(array_merge($sessionMember->invitation_channels ?? [], $channels))),
        ]);

        return $results;
    }

    /**
     * Dispatch invitation to a Candidate Student across specified channels.
     *
     * @param  array<string>  $channels
     * @return array<string, bool>
     */
    public function sendCandidateInvitation(
        MockInterviewCandidate $candidate,
        array $channels = ['email'],
        ?string $customTemplate = null
    ): array {
        $preview = $this->previewCandidateInvitation($candidate, $customTemplate);
        $user = $candidate->user;
        $session = $candidate->session;
        $results = [];

        foreach ($channels as $channel) {
            $status = MockInterviewInvitationLog::STATUS_FAILED;
            $error = null;

            try {
                if ($channel === 'email' && ! empty($user->email)) {
                    $res = $this->emailService->sendDirect(
                        to: $user->email,
                        subject: $preview['subject'],
                        body: nl2br(e($preview['content']))
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'sms' && ! empty($user->phone)) {
                    $res = $this->smsService->sendDirect(
                        phone: $user->phone,
                        message: $preview['content']
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'whatsapp' && ! empty($user->phone)) {
                    $res = $this->whatsAppService->sendDirect(
                        phone: $user->phone,
                        message: $preview['content']
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'telegram') {
                    $res = $this->telegramService->sendDirect(
                        chatId: $user->phone ?? '',
                        message: $preview['content']
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'in_app') {
                    $this->notificationService->sendInternal(
                        user: $user->id,
                        type: 'mock_interview_candidate_invitation',
                        title: $preview['subject'],
                        body: mb_substr($preview['content'], 0, 200).'...',
                        actionUrl: route('student.mock-interviews.index'),
                        actionLabel: 'View Interview Details'
                    );
                    $status = MockInterviewInvitationLog::STATUS_SENT;
                }
            } catch (Throwable $e) {
                $status = MockInterviewInvitationLog::STATUS_FAILED;
                $error = $e->getMessage();
                Log::error("Mock Interview Candidate Invitation error ({$channel}): ".$e->getMessage());
            }

            MockInterviewInvitationLog::create([
                'mock_interview_session_id' => $session->id,
                'mock_interview_candidate_id' => $candidate->id,
                'recipient_type' => 'student',
                'recipient_name' => $user->name,
                'recipient_contact' => $channel === 'email' ? $user->email : $user->phone,
                'user_id' => $user->id,
                'channel' => $channel,
                'purpose' => MockInterviewInvitationLog::PURPOSE_INVITATION,
                'subject' => $preview['subject'],
                'message_content' => $preview['content'],
                'status' => $status,
                'error_message' => $error,
                'sent_by' => auth()->id(),
                'sent_at' => now(),
            ]);

            $results[$channel] = ($status === MockInterviewInvitationLog::STATUS_SENT);
        }

        $candidate->update([
            'invitation_sent_at' => now(),
            'invitation_channels' => array_values(array_unique(array_merge($candidate->invitation_channels ?? [], $channels))),
        ]);

        return $results;
    }

    /**
     * Dispatch video recording link to a Candidate Student across specified channels.
     *
     * @param  array<string>  $channels
     * @return array<string, bool>
     */
    public function sendRecordingToCandidate(
        MockInterviewCandidate $candidate,
        array $channels = ['email'],
        ?string $customNotes = null
    ): array {
        $session = $candidate->session;
        $user = $candidate->user;
        $recordingUrl = $candidate->getEffectiveRecordingUrl();

        if (empty($recordingUrl)) {
            return [];
        }

        $subject = "Your UPSC Mock Interview Video Recording is Available - {$session->title}";
        $content = "Dear {$user->name},\n\n"
            ."Your recorded video for the UPSC Mock Interview session ({$session->title}) conducted on {$session->formatted_date} is now available for your review and download.\n\n"
            ."Recording Download / Access URL:\n{$recordingUrl}\n"
            .($candidate->recording_password ? "Access Passcode: {$candidate->recording_password}\n" : '')
            .($customNotes ?: ($candidate->recording_notes ? "Notes: {$candidate->recording_notes}\n" : ''))
            ."\nYou can also watch and download this recording anytime by logging into your Student Dashboard at SNTCSSC MIS.\n\n"
            ."Best wishes for your final UPSC Civil Services Personality Test!\n- Satyendranath Tagore Civil Services Study Centre";

        $results = [];

        foreach ($channels as $channel) {
            $status = MockInterviewInvitationLog::STATUS_FAILED;
            $error = null;

            try {
                if ($channel === 'email' && ! empty($user->email)) {
                    $res = $this->emailService->sendDirect(
                        to: $user->email,
                        subject: $subject,
                        body: nl2br(e($content))
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'sms' && ! empty($user->phone)) {
                    $res = $this->smsService->sendDirect(
                        phone: $user->phone,
                        message: $content
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'whatsapp' && ! empty($user->phone)) {
                    $res = $this->whatsAppService->sendDirect(
                        phone: $user->phone,
                        message: $content
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'telegram') {
                    $res = $this->telegramService->sendDirect(
                        chatId: $user->phone ?? '',
                        message: $content
                    );
                    $status = $res['success'] ? MockInterviewInvitationLog::STATUS_SENT : MockInterviewInvitationLog::STATUS_FAILED;
                    $error = $res['error'] ?? null;
                } elseif ($channel === 'in_app') {
                    $this->notificationService->sendInternal(
                        user: $user->id,
                        type: 'mock_interview_recording_ready',
                        title: $subject,
                        body: "Your Mock Interview recording for {$session->title} is now available to download.",
                        actionUrl: route('student.mock-interviews.index'),
                        actionLabel: 'Watch & Download Recording'
                    );
                    $status = MockInterviewInvitationLog::STATUS_SENT;
                }
            } catch (Throwable $e) {
                $status = MockInterviewInvitationLog::STATUS_FAILED;
                $error = $e->getMessage();
                Log::error("Mock Interview Recording notification error ({$channel}): ".$e->getMessage());
            }

            MockInterviewInvitationLog::create([
                'mock_interview_session_id' => $session->id,
                'mock_interview_candidate_id' => $candidate->id,
                'recipient_type' => 'student',
                'recipient_name' => $user->name,
                'recipient_contact' => $channel === 'email' ? $user->email : $user->phone,
                'user_id' => $user->id,
                'channel' => $channel,
                'purpose' => MockInterviewInvitationLog::PURPOSE_RECORDING,
                'subject' => $subject,
                'message_content' => $content,
                'status' => $status,
                'error_message' => $error,
                'sent_by' => auth()->id(),
                'sent_at' => now(),
            ]);

            $results[$channel] = ($status === MockInterviewInvitationLog::STATUS_SENT);
        }

        $candidate->update([
            'recording_sent_at' => now(),
            'recording_sent_channels' => array_values(array_unique(array_merge($candidate->recording_sent_channels ?? [], $channels))),
        ]);

        AuditLogService::log(
            event: 'mock_interview_recording_sent',
            description: "Dispatched interview recording for candidate {$user->name} in session {$session->session_code}.",
            auditable: $candidate
        );

        return $results;
    }
}
