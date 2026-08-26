<?php

namespace App\Services;

use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MeetingService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected WhatsAppService $whatsAppService,
        protected TelegramService $telegramService,
        protected SmsService $smsService
    ) {}

    /**
     * Create an instant WebRTC online meeting.
     */
    public function createInstantMeeting(
        User $host,
        string $title = 'Instant Meeting',
        string $mode = ChatMeeting::MODE_VIDEO,
        string $accessMode = ChatMeeting::ACCESS_OPEN,
        ?string $description = null,
        array $coHostUserIds = []
    ): ChatMeeting {
        $enabled = (bool) Setting::get('chat.meetings_enabled', true);
        if (! $enabled && ! $host->hasRole('Super Administrator')) {
            throw ValidationException::withMessages([
                'meeting' => __('Online meetings are currently disabled by administrator.'),
            ]);
        }

        return DB::transaction(function () use ($host, $title, $mode, $accessMode, $description, $coHostUserIds) {
            $meeting = ChatMeeting::create([
                'host_id' => $host->id,
                'team_id' => $host->current_team_id,
                'title' => trim($title),
                'description' => $description ? trim($description) : null,
                'type' => ChatMeeting::TYPE_INSTANT,
                'mode' => $mode,
                'access_mode' => $accessMode,
                'status' => ChatMeeting::STATUS_LIVE,
                'started_at' => now(),
                'duration_minutes' => (int) Setting::get('chat.meeting_max_duration_minutes', 120),
                'settings' => [
                    'chat_enabled' => true,
                    'screen_share_enabled' => true,
                    'emoji_enabled' => true,
                ],
                'created_by' => $host->id,
            ]);

            // Add host as participant
            ChatMeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'user_id' => $host->id,
                'role' => ChatMeetingParticipant::ROLE_HOST,
                'status' => ChatMeetingParticipant::STATUS_JOINED,
                'joined_at' => now(),
            ]);

            // Add co-hosts
            foreach ($coHostUserIds as $coHostId) {
                if ((int) $coHostId !== (int) $host->id) {
                    ChatMeetingParticipant::create([
                        'meeting_id' => $meeting->id,
                        'user_id' => $coHostId,
                        'role' => ChatMeetingParticipant::ROLE_CO_HOST,
                        'status' => ChatMeetingParticipant::STATUS_INVITED,
                    ]);
                }
            }

            AuditLogService::log(
                event: 'meeting_created_instant',
                description: "Created instant meeting '{$meeting->title}' (#{$meeting->id})",
                auditable: $meeting,
                userId: $host->id
            );

            return $meeting;
        });
    }

    /**
     * Schedule a future online meeting with start/end time, recurrence, reminders, and invitations.
     *
     * @param  array<int>  $inviteeUserIds
     * @param  array<int>  $coHostUserIds
     * @param  array<string>  $customEmails
     * @param  array<string>  $dispatchChannels
     * @param  array<string>  $reminderChannels
     */
    public function scheduleMeeting(
        User $host,
        string $title,
        \DateTimeInterface|string $scheduledAt,
        \DateTimeInterface|string|null $endsAt = null,
        string $mode = ChatMeeting::MODE_VIDEO,
        string $accessMode = ChatMeeting::ACCESS_OPEN,
        int $durationMinutes = 60,
        string $repeatType = ChatMeeting::REPEAT_NONE,
        \DateTimeInterface|string|null $repeatUntil = null,
        ?int $reminderOffsetMinutes = 15,
        array $reminderChannels = ['database', 'email'],
        ?string $description = null,
        ?string $passcode = null,
        array $inviteeUserIds = [],
        array $coHostUserIds = [],
        array $customEmails = [],
        array $dispatchChannels = ['database', 'email']
    ): ChatMeeting {
        $enabled = (bool) Setting::get('chat.meetings_enabled', true);
        if (! $enabled && ! $host->hasRole('Super Administrator')) {
            throw ValidationException::withMessages([
                'meeting' => __('Online meetings are currently disabled by administrator.'),
            ]);
        }

        $startCarbon = $scheduledAt instanceof Carbon ? $scheduledAt : Carbon::parse($scheduledAt);
        $endCarbon = $endsAt ? ($endsAt instanceof Carbon ? $endsAt : Carbon::parse($endsAt)) : $startCarbon->copy()->addMinutes($durationMinutes);

        if ($endCarbon->lte($startCarbon)) {
            $endCarbon = $startCarbon->copy()->addMinutes($durationMinutes);
        }

        $actualDuration = max(1, $startCarbon->diffInMinutes($endCarbon));

        return DB::transaction(function () use (
            $host, $title, $startCarbon, $endCarbon, $actualDuration, $mode, $accessMode,
            $repeatType, $repeatUntil, $reminderOffsetMinutes, $reminderChannels,
            $description, $passcode, $inviteeUserIds, $coHostUserIds, $customEmails, $dispatchChannels
        ) {
            $meeting = ChatMeeting::create([
                'host_id' => $host->id,
                'team_id' => $host->current_team_id,
                'title' => trim($title),
                'description' => $description ? trim($description) : null,
                'type' => ChatMeeting::TYPE_SCHEDULED,
                'mode' => $mode,
                'access_mode' => $accessMode,
                'scheduled_at' => $startCarbon,
                'ends_at' => $endCarbon,
                'duration_minutes' => $actualDuration,
                'repeat_type' => $repeatType,
                'repeat_until' => $repeatUntil,
                'reminder_offset_minutes' => $reminderOffsetMinutes,
                'reminder_channels' => $reminderChannels,
                'passcode' => $passcode ? trim($passcode) : null,
                'status' => ChatMeeting::STATUS_SCHEDULED,
                'settings' => [
                    'chat_enabled' => true,
                    'screen_share_enabled' => true,
                    'emoji_enabled' => true,
                ],
                'created_by' => $host->id,
            ]);

            // Add host
            ChatMeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'user_id' => $host->id,
                'role' => ChatMeetingParticipant::ROLE_HOST,
                'status' => ChatMeetingParticipant::STATUS_INVITED,
            ]);

            // Add co-hosts
            foreach ($coHostUserIds as $coHostId) {
                if ((int) $coHostId !== (int) $host->id) {
                    ChatMeetingParticipant::updateOrCreate(
                        ['meeting_id' => $meeting->id, 'user_id' => $coHostId],
                        [
                            'role' => ChatMeetingParticipant::ROLE_CO_HOST,
                            'status' => ChatMeetingParticipant::STATUS_INVITED,
                        ]
                    );
                }
            }

            // Add invitees & dispatch notifications
            if (! empty($inviteeUserIds)) {
                $invitees = User::whereIn('id', $inviteeUserIds)->get();
                foreach ($invitees as $invitee) {
                    if ((int) $invitee->id !== (int) $host->id && ! in_array($invitee->id, $coHostUserIds)) {
                        ChatMeetingParticipant::create([
                            'meeting_id' => $meeting->id,
                            'user_id' => $invitee->id,
                            'role' => ChatMeetingParticipant::ROLE_PARTICIPANT,
                            'status' => ChatMeetingParticipant::STATUS_INVITED,
                        ]);
                    }

                    $this->dispatchMeetingInvitation($meeting, $host, $invitee, $dispatchChannels);
                }
            }

            // Send custom emails if provided
            if (! empty($customEmails)) {
                $this->sendBulkEmailInvitations($meeting, $customEmails, $host);
            }

            AuditLogService::log(
                event: 'meeting_scheduled',
                description: "Scheduled {$mode} meeting '{$meeting->title}' for {$meeting->formattedScheduledAt()}",
                auditable: $meeting,
                userId: $host->id
            );

            return $meeting;
        });
    }

    /**
     * Update an existing scheduled meeting.
     */
    public function updateMeeting(
        ChatMeeting $meeting,
        User $actor,
        array $data,
        array $inviteeUserIds = [],
        array $coHostUserIds = [],
        array $customEmails = [],
        array $dispatchChannels = ['database', 'email']
    ): ChatMeeting {
        if (! $meeting->canManageMeeting($actor)) {
            throw ValidationException::withMessages([
                'meeting' => __('Unauthorized to update this meeting.'),
            ]);
        }

        return DB::transaction(function () use (
            $meeting, $actor, $data, $inviteeUserIds, $coHostUserIds, $customEmails, $dispatchChannels
        ) {
            $startCarbon = isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : $meeting->scheduled_at;
            $endCarbon = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : ($data['duration_minutes'] ? $startCarbon->copy()->addMinutes((int) $data['duration_minutes']) : $meeting->ends_at);

            if ($endCarbon && $startCarbon && $endCarbon->lte($startCarbon)) {
                $endCarbon = $startCarbon->copy()->addMinutes((int) ($data['duration_minutes'] ?? $meeting->duration_minutes ?? 60));
            }

            $actualDuration = ($startCarbon && $endCarbon) ? max(1, $startCarbon->diffInMinutes($endCarbon)) : ($data['duration_minutes'] ?? $meeting->duration_minutes);

            $meeting->update([
                'title' => trim($data['title'] ?? $meeting->title),
                'description' => isset($data['description']) ? (trim($data['description']) ?: null) : $meeting->description,
                'mode' => $data['mode'] ?? $meeting->mode,
                'access_mode' => $data['access_mode'] ?? $meeting->access_mode,
                'scheduled_at' => $startCarbon,
                'ends_at' => $endCarbon,
                'duration_minutes' => $actualDuration,
                'repeat_type' => $data['repeat_type'] ?? $meeting->repeat_type,
                'repeat_until' => isset($data['repeat_until']) ? ($data['repeat_until'] ? Carbon::parse($data['repeat_until']) : null) : $meeting->repeat_until,
                'reminder_offset_minutes' => $data['reminder_offset_minutes'] ?? $meeting->reminder_offset_minutes,
                'reminder_channels' => $data['reminder_channels'] ?? $meeting->reminder_channels,
                'passcode' => isset($data['passcode']) ? (trim($data['passcode']) ?: null) : $meeting->passcode,
                'updated_by' => $actor->id,
            ]);

            // Sync Co-Hosts
            ChatMeetingParticipant::where('meeting_id', $meeting->id)
                ->where('role', ChatMeetingParticipant::ROLE_CO_HOST)
                ->whereNotIn('user_id', $coHostUserIds)
                ->update(['role' => ChatMeetingParticipant::ROLE_PARTICIPANT]);

            foreach ($coHostUserIds as $coHostId) {
                if ((int) $coHostId !== (int) $meeting->host_id) {
                    ChatMeetingParticipant::updateOrCreate(
                        ['meeting_id' => $meeting->id, 'user_id' => $coHostId],
                        ['role' => ChatMeetingParticipant::ROLE_CO_HOST]
                    );
                }
            }

            // Sync Invitees
            $currentParticipantUserIds = $meeting->participants()->where('role', '!=', ChatMeetingParticipant::ROLE_HOST)->pluck('user_id')->all();
            $newInvitees = array_diff($inviteeUserIds, $currentParticipantUserIds);

            foreach ($newInvitees as $newInviteeId) {
                if ((int) $newInviteeId !== (int) $meeting->host_id && ! in_array($newInviteeId, $coHostUserIds)) {
                    ChatMeetingParticipant::create([
                        'meeting_id' => $meeting->id,
                        'user_id' => $newInviteeId,
                        'role' => ChatMeetingParticipant::ROLE_PARTICIPANT,
                        'status' => ChatMeetingParticipant::STATUS_INVITED,
                    ]);

                    $userObj = User::find($newInviteeId);
                    if ($userObj) {
                        $this->dispatchMeetingInvitation($meeting, $actor, $userObj, $dispatchChannels);
                    }
                }
            }

            if (! empty($customEmails)) {
                $this->sendBulkEmailInvitations($meeting, $customEmails, $actor);
            }

            AuditLogService::log(
                event: 'meeting_updated',
                description: "Updated scheduled meeting '{$meeting->title}' (#{$meeting->id})",
                auditable: $meeting,
                userId: $actor->id
            );

            return $meeting->fresh();
        });
    }

    /**
     * Cancel a scheduled or live meeting with multi-channel cancellation notices.
     *
     * @param  array<string>  $notifyChannels
     * @param  array<string>  $customEmails
     */
    public function cancelMeeting(
        ChatMeeting $meeting,
        User $actor,
        string $reason = '',
        array $notifyChannels = ['database', 'email'],
        array $customEmails = []
    ): bool {
        if (! $meeting->canManageMeeting($actor)) {
            throw ValidationException::withMessages([
                'meeting' => __('Unauthorized to cancel this meeting.'),
            ]);
        }

        return DB::transaction(function () use ($meeting, $actor, $reason, $notifyChannels, $customEmails) {
            $meeting->update([
                'status' => ChatMeeting::STATUS_CANCELLED,
                'ended_at' => now(),
                'updated_by' => $actor->id,
            ]);

            // Notify all registered participants
            $participants = $meeting->participants()->with('user')->get();
            foreach ($participants as $part) {
                if ($part->user && (int) $part->user->id !== (int) $actor->id) {
                    $this->dispatchMeetingCancellation($meeting, $actor, $part->user, $reason, $notifyChannels);
                }
            }

            // Dispatch cancellation emails to custom external emails
            if (! empty($customEmails)) {
                foreach ($customEmails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        try {
                            $this->sendCancellationEmail($meeting, $email, $actor, $reason);
                        } catch (\Throwable $e) {
                            Log::warning("Failed to send cancellation email to {$email}: ".$e->getMessage());
                        }
                    }
                }
            }

            AuditLogService::log(
                event: 'meeting_cancelled',
                description: "Cancelled meeting '{$meeting->title}' (#{$meeting->id})",
                auditable: $meeting,
                userId: $actor->id
            );

            return true;
        });
    }

    /**
     * Dispatch cancellation notification to user.
     */
    public function dispatchMeetingCancellation(
        ChatMeeting $meeting,
        User $actor,
        User $recipient,
        string $reason,
        array $channels
    ): void {
        $cancelNotice = __('The scheduled meeting ":title" has been cancelled by host :host.', [
            'title' => $meeting->title,
            'host' => $actor->name,
        ]);
        if (! empty($reason)) {
            $cancelNotice .= "\n".__('Reason: :reason', ['reason' => $reason]);
        }

        if (in_array('database', $channels, true)) {
            $this->notificationService->sendInternal(
                user: $recipient,
                type: 'meeting_cancelled',
                title: __('Meeting Cancelled: :title', ['title' => $meeting->title]),
                body: $cancelNotice,
                actionUrl: route('meetings.index'),
                actionLabel: __('View Meetings')
            );
        }

        if (in_array('email', $channels, true) && ! empty($recipient->email)) {
            $this->sendCancellationEmail($meeting, $recipient->email, $actor, $reason);
        }

        if (in_array('whatsapp', $channels, true) && ! empty($recipient->phone) && WhatsAppService::isEnabled()) {
            try {
                $this->whatsAppService->sendDirect($recipient->whatsapp_no ?: $recipient->phone, $cancelNotice);
            } catch (\Throwable $e) {
                Log::debug('WhatsApp meeting cancel alert error: '.$e->getMessage());
            }
        }

        if (in_array('telegram', $channels, true) && TelegramService::isEnabled()) {
            try {
                $this->telegramService->sendFormatted(
                    title: __('Meeting Cancelled: :title', ['title' => $meeting->title]),
                    body: $cancelNotice
                );
            } catch (\Throwable $e) {
                Log::debug('Telegram meeting cancel alert error: '.$e->getMessage());
            }
        }

        if (in_array('sms', $channels, true) && ! empty($recipient->phone) && SmsService::isEnabled()) {
            try {
                $this->smsService->sendDirect($recipient->phone, Str::limit($cancelNotice, 140));
            } catch (\Throwable $e) {
                Log::debug('SMS meeting cancel alert error: '.$e->getMessage());
            }
        }
    }

    protected function sendCancellationEmail(ChatMeeting $meeting, string $email, User $actor, string $reason): void
    {
        Mail::send([], [], function ($message) use ($meeting, $email, $actor, $reason) {
            $subject = __('[Cancelled] Online Meeting: :title', ['title' => $meeting->title]);
            $html = view('emails.meeting_cancellation', [
                'meeting' => $meeting,
                'actor' => $actor,
                'reason' => $reason,
            ])->render();

            $message->to($email)
                ->subject($subject)
                ->html($html);
        });
    }

    /**
     * Dispatch invitation to user across multiple channels.
     */
    public function dispatchMeetingInvitation(
        ChatMeeting $meeting,
        User $host,
        User $recipient,
        array $channels
    ): void {
        $joinUrl = $meeting->join_url;
        $title = $meeting->title;
        $time = $meeting->formattedScheduledAt();
        $mode = ucfirst($meeting->mode);

        $body = __("You are invited to join an Online :mode Meeting:\n📌 Topic: :title\n⏰ Time: :time\n🔗 Join: :url", [
            'mode' => $mode,
            'title' => $title,
            'time' => $time,
            'url' => $joinUrl,
        ]);

        if ($meeting->passcode) {
            $body .= "\n".__('Passcode: :passcode', ['passcode' => $meeting->passcode]);
        }

        if (in_array('database', $channels, true)) {
            $this->notificationService->sendInternal(
                user: $recipient,
                type: 'meeting_invitation',
                title: __('Online Meeting Invitation: :title', ['title' => $title]),
                body: __(':host invited you to an online :mode meeting scheduled for :time.', [
                    'host' => $host->name,
                    'mode' => $mode,
                    'time' => $time,
                ]),
                actionUrl: $joinUrl,
                actionLabel: __('Join Meeting')
            );
        }

        if (in_array('email', $channels, true) && ! empty($recipient->email)) {
            try {
                $this->sendEmailInvitation($meeting, $recipient->email, $host);
            } catch (\Throwable $e) {
                Log::warning("Failed to send meeting invite email to {$recipient->email}: ".$e->getMessage());
            }
        }

        if (in_array('whatsapp', $channels, true) && ! empty($recipient->phone) && WhatsAppService::isEnabled()) {
            try {
                $this->whatsAppService->sendDirect($recipient->whatsapp_no ?: $recipient->phone, $body);
            } catch (\Throwable $e) {
                Log::debug('WhatsApp meeting invite error: '.$e->getMessage());
            }
        }

        if (in_array('telegram', $channels, true) && TelegramService::isEnabled()) {
            try {
                $this->telegramService->sendFormatted(
                    title: __('Online Meeting Invitation: :title', ['title' => $title]),
                    body: $body,
                    actionUrl: $joinUrl,
                    actionLabel: __('Join Meeting')
                );
            } catch (\Throwable $e) {
                Log::debug('Telegram meeting invite error: '.$e->getMessage());
            }
        }

        if (in_array('sms', $channels, true) && ! empty($recipient->phone) && SmsService::isEnabled()) {
            try {
                $smsText = __('Meeting ":title" on :time. Join: :url', [
                    'title' => Str::limit($title, 30),
                    'time' => $time,
                    'url' => $joinUrl,
                ]);
                $this->smsService->sendDirect($recipient->phone, $smsText);
            } catch (\Throwable $e) {
                Log::debug('SMS meeting invite error: '.$e->getMessage());
            }
        }
    }

    /**
     * Send personalized HTML email invitation.
     */
    public function sendEmailInvitation(ChatMeeting $meeting, string $email, User $host): void
    {
        Mail::send([], [], function ($message) use ($meeting, $email, $host) {
            $subject = __('Invitation: :title - Online Meeting', ['title' => $meeting->title]);
            $html = view('emails.meeting_invitation', [
                'meeting' => $meeting,
                'host' => $host,
                'joinUrl' => $meeting->join_url,
            ])->render();

            $message->to($email)
                ->subject($subject)
                ->html($html);
        });
    }

    /**
     * Bulk send personalized email invitations to custom email list.
     */
    public function sendBulkEmailInvitations(ChatMeeting $meeting, array $emails, User $host): int
    {
        $sent = 0;
        foreach ($emails as $email) {
            $cleanEmail = trim($email);
            if (filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $this->sendEmailInvitation($meeting, $cleanEmail, $host);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning("Failed to send meeting invitation email to {$cleanEmail}: ".$e->getMessage());
                }
            }
        }

        return $sent;
    }

    /**
     * Join a meeting room.
     */
    public function joinMeeting(ChatMeeting $meeting, User $user, ?string $passcode = null): ChatMeetingParticipant
    {
        $isHostOrCoHost = $meeting->isHostOrCoHost($user);

        if ($meeting->passcode && ! $isHostOrCoHost && $passcode !== $meeting->passcode) {
            throw ValidationException::withMessages([
                'passcode' => __('Incorrect meeting passcode.'),
            ]);
        }

        return DB::transaction(function () use ($meeting, $user, $isHostOrCoHost) {
            if ($meeting->isScheduled() && $isHostOrCoHost) {
                $meeting->update([
                    'status' => ChatMeeting::STATUS_LIVE,
                    'started_at' => now(),
                ]);
            }

            $existing = ChatMeetingParticipant::where('meeting_id', $meeting->id)
                ->where('user_id', $user->id)
                ->first();

            // Determine if user requires waiting room admission
            $isInvited = $existing && in_array($existing->status, [ChatMeetingParticipant::STATUS_INVITED, ChatMeetingParticipant::STATUS_JOINED]);
            $requiresWaiting = ! $isHostOrCoHost && ! $meeting->isOpenForEveryone() && ! $isInvited;

            $initialStatus = $requiresWaiting ? ChatMeetingParticipant::STATUS_WAITING : ChatMeetingParticipant::STATUS_JOINED;

            $participant = ChatMeetingParticipant::firstOrCreate(
                ['meeting_id' => $meeting->id, 'user_id' => $user->id],
                [
                    'role' => $meeting->isHost($user) ? ChatMeetingParticipant::ROLE_HOST : ChatMeetingParticipant::ROLE_PARTICIPANT,
                    'status' => $initialStatus,
                    'joined_at' => $requiresWaiting ? null : now(),
                ]
            );

            if (! $requiresWaiting && $participant->status !== ChatMeetingParticipant::STATUS_JOINED) {
                $participant->update([
                    'status' => ChatMeetingParticipant::STATUS_JOINED,
                    'joined_at' => now(),
                    'left_at' => null,
                ]);
            }

            return $participant;
        });
    }

    /**
     * Admit a participant from waiting room (Host/Co-Host action).
     */
    public function admitParticipant(ChatMeeting $meeting, int $participantId, User $actor): bool
    {
        if (! $meeting->canManageMeeting($actor)) {
            throw ValidationException::withMessages([
                'meeting' => __('Unauthorized to admit participants.'),
            ]);
        }

        $participant = $meeting->participants()->find($participantId);
        if ($participant) {
            $participant->update([
                'status' => ChatMeetingParticipant::STATUS_JOINED,
                'joined_at' => now(),
                'left_at' => null,
            ]);

            AuditLogService::log(
                event: 'meeting_participant_admitted',
                description: "Admitted {$participant->displayName()} to meeting '{$meeting->title}'",
                auditable: $meeting,
                userId: $actor->id
            );

            return true;
        }

        return false;
    }

    /**
     * Deny a participant from waiting room.
     */
    public function denyParticipant(ChatMeeting $meeting, int $participantId, User $actor): bool
    {
        if (! $meeting->canManageMeeting($actor)) {
            throw ValidationException::withMessages([
                'meeting' => __('Unauthorized to deny participants.'),
            ]);
        }

        $participant = $meeting->participants()->find($participantId);
        if ($participant) {
            $participant->update([
                'status' => ChatMeetingParticipant::STATUS_DENIED,
                'left_at' => now(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Promote / Demote Co-Host role.
     */
    public function setParticipantRole(ChatMeeting $meeting, int $participantId, string $role, User $actor): bool
    {
        if (! $meeting->canManageMeeting($actor)) {
            throw ValidationException::withMessages([
                'meeting' => __('Unauthorized to change participant roles.'),
            ]);
        }

        $participant = $meeting->participants()->find($participantId);
        if ($participant && ! $participant->isHost()) {
            $participant->update(['role' => $role]);

            AuditLogService::log(
                event: 'meeting_participant_role_updated',
                description: "Changed {$participant->displayName()} role to {$role} in meeting '{$meeting->title}'",
                auditable: $meeting,
                userId: $actor->id
            );

            return true;
        }

        return false;
    }

    /**
     * Update in-room restrictions (Chat on/off, Emoji on/off, Screen Share on/off).
     */
    public function updateRestrictions(ChatMeeting $meeting, array $settings, User $actor): bool
    {
        if (! $meeting->canManageMeeting($actor)) {
            throw ValidationException::withMessages([
                'meeting' => __('Unauthorized to modify meeting restrictions.'),
            ]);
        }

        $merged = array_merge($meeting->settings ?? [], $settings);
        $meeting->update([
            'settings' => $merged,
            'updated_by' => $actor->id,
        ]);

        return true;
    }

    /**
     * End a meeting (Host or Co-Host action).
     */
    public function endMeeting(ChatMeeting $meeting, User $actor): bool
    {
        if (! $meeting->canManageMeeting($actor)) {
            throw ValidationException::withMessages([
                'meeting' => __('Only the meeting host or co-host can end the meeting for all participants.'),
            ]);
        }

        return DB::transaction(function () use ($meeting, $actor) {
            $meeting->update([
                'status' => ChatMeeting::STATUS_ENDED,
                'ended_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $meeting->participants()->whereNull('left_at')->update([
                'status' => ChatMeetingParticipant::STATUS_LEFT,
                'left_at' => now(),
            ]);

            AuditLogService::log(
                event: 'meeting_ended',
                description: "Ended meeting '{$meeting->title}' (#{$meeting->id})",
                auditable: $meeting,
                userId: $actor->id
            );

            return true;
        });
    }
}
