<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    public function __construct(
        protected EmailService $emailService,
        protected SmsService $smsService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Generate unique, human-readable ticket code (e.g. TICK-202608-0001).
     */
    public function generateTicketNumber(): string
    {
        $prefix = 'TICK-'.now()->format('Ym').'-';
        $latest = Ticket::withTrashed()
            ->where('ticket_number', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($latest) {
            $lastSeq = (int) substr($latest->ticket_number, -4);
            $nextSeq = str_pad((string) ($lastSeq + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $nextSeq = '0001';
        }

        return $prefix.$nextSeq;
    }

    /**
     * Create a new support ticket with attachments, opening message, SLA calculation, and audit trail.
     *
     * @param  array{subject: string, description: string, category_id?: int|null, priority?: string, user_id?: int|null, guest_name?: string|null, guest_email?: string|null, guest_phone?: string|null, source?: string, metadata?: array|null}  $data
     * @param  array<UploadedFile>  $uploadedFiles
     */
    public function createTicket(array $data, ?User $creator = null, array $uploadedFiles = []): Ticket
    {
        return DB::transaction(function () use ($data, $creator, $uploadedFiles) {
            $source = $data['source'] ?? Ticket::SOURCE_PORTAL;
            if (isset($data['user_id'])) {
                $user = User::find($data['user_id']);
            } elseif ($source === Ticket::SOURCE_PORTAL) {
                $user = $creator;
            } else {
                $user = null;
            }

            $category = isset($data['category_id']) ? TicketCategory::find($data['category_id']) : null;

            $priority = $data['priority'] ?? ($category?->default_priority ?? Ticket::PRIORITY_MEDIUM);
            if (! in_array($priority, [Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT], true)) {
                $priority = Ticket::PRIORITY_MEDIUM;
            }

            $source = $data['source'] ?? Ticket::SOURCE_PORTAL;
            $uuid = (string) Str::uuid();
            $ticketNumber = $this->generateTicketNumber();

            // Calculate SLA deadlines
            $slaResponseHours = $category?->sla_response_hours ?? 24;
            $slaResolutionHours = $category?->sla_resolution_hours ?? 72;

            // Apply priority SLA weight multipliers
            $multiplier = match ($priority) {
                Ticket::PRIORITY_URGENT => 0.25, // 4x faster
                Ticket::PRIORITY_HIGH => 0.5,    // 2x faster
                Ticket::PRIORITY_LOW => 1.5,     // 1.5x relaxed
                default => 1.0,
            };

            $firstResponseDueAt = now()->addMinutes((int) round($slaResponseHours * 60 * $multiplier));
            $resolutionDueAt = now()->addMinutes((int) round($slaResolutionHours * 60 * $multiplier));

            $assignedUserId = $category?->default_assigned_user_id;

            $ticket = Ticket::create([
                'ticket_number' => $ticketNumber,
                'uuid' => $uuid,
                'user_id' => $user?->id,
                'guest_name' => $data['guest_name'] ?? ($user ? $user->name : null),
                'guest_email' => $data['guest_email'] ?? ($user ? $user->email : null),
                'guest_phone' => $data['guest_phone'] ?? ($user ? $user->phone : null),
                'category_id' => $category?->id,
                'assigned_to_user_id' => $assignedUserId,
                'priority' => $priority,
                'status' => Ticket::STATUS_OPEN,
                'subject' => $data['subject'],
                'description' => $data['description'],
                'source' => $source,
                'last_reply_at' => now(),
                'last_reply_by_user_id' => $user?->id,
                'first_response_due_at' => $firstResponseDueAt,
                'resolution_due_at' => $resolutionDueAt,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Create initial public message in conversation thread
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user?->id,
                'sender_name' => $ticket->submitterName(),
                'sender_email' => $ticket->submitterEmail(),
                'type' => TicketMessage::TYPE_PUBLIC_REPLY,
                'message' => $data['description'],
            ]);

            // Process uploaded attachments
            $this->storeAttachments($uploadedFiles, $ticket, $message, $user);

            AuditLogService::log(
                event: 'ticket_created',
                description: "Created support ticket #{$ticketNumber} - '{$ticket->subject}'.",
                newValues: [
                    'ticket_number' => $ticketNumber,
                    'subject' => $ticket->subject,
                    'category' => $category?->name,
                    'priority' => $priority,
                    'user' => $user?->name,
                ],
                userId: $user?->id
            );

            // Dispatch notifications asynchronously
            $this->notifyTicketCreated($ticket);

            return $ticket;
        });
    }

    /**
     * Post a reply (public response or internal staff note) to a ticket.
     *
     * @param  array{message: string, type?: string, new_status?: string|null}  $data
     * @param  array<UploadedFile>  $uploadedFiles
     */
    public function replyTicket(Ticket $ticket, array $data, ?User $sender = null, array $uploadedFiles = []): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $data, $sender, $uploadedFiles) {
            $type = $data['type'] ?? TicketMessage::TYPE_PUBLIC_REPLY;
            if (! in_array($type, [TicketMessage::TYPE_PUBLIC_REPLY, TicketMessage::TYPE_INTERNAL_NOTE], true)) {
                $type = TicketMessage::TYPE_PUBLIC_REPLY;
            }

            $senderName = $sender ? $sender->name : $ticket->submitterName();
            $senderEmail = $sender ? $sender->email : $ticket->submitterEmail();

            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $sender?->id,
                'sender_name' => $senderName,
                'sender_email' => $senderEmail,
                'type' => $type,
                'message' => $data['message'],
            ]);

            // Save attachments
            $this->storeAttachments($uploadedFiles, $ticket, $message, $sender);

            $updates = [
                'last_reply_at' => now(),
                'last_reply_by_user_id' => $sender?->id,
            ];

            $isStaff = $sender && ($sender->hasRole('Super Administrator') || $sender->hasRole('Administrator') || $sender->can('tickets.reply'));
            $isSubmitter = $sender && $ticket->user_id === $sender->id;

            // If staff is posting first public reply, record first_responded_at
            if ($type === TicketMessage::TYPE_PUBLIC_REPLY && $isStaff && is_null($ticket->first_responded_at)) {
                $updates['first_responded_at'] = now();
                if ($ticket->first_response_due_at && now()->isAfter($ticket->first_response_due_at)) {
                    $updates['is_sla_response_breached'] = true;
                }
            }

            // Automatic status transitions
            if (! empty($data['new_status'])) {
                $updates['status'] = $data['new_status'];
                if ($data['new_status'] === Ticket::STATUS_RESOLVED) {
                    $updates['resolved_at'] = now();
                }
            } elseif ($type === TicketMessage::TYPE_PUBLIC_REPLY) {
                if ($isSubmitter && in_array($ticket->status, [Ticket::STATUS_PENDING_USER, Ticket::STATUS_RESOLVED], true)) {
                    $updates['status'] = Ticket::STATUS_OPEN;
                } elseif ($isStaff && $ticket->status === Ticket::STATUS_OPEN) {
                    $updates['status'] = Ticket::STATUS_IN_PROGRESS;
                }
            }

            $ticket->update($updates);

            AuditLogService::log(
                event: $type === TicketMessage::TYPE_INTERNAL_NOTE ? 'ticket_internal_note' : 'ticket_replied',
                description: ($type === TicketMessage::TYPE_INTERNAL_NOTE ? 'Added internal staff note' : 'Posted reply')." to ticket #{$ticket->ticket_number}.",
                newValues: [
                    'ticket_number' => $ticket->ticket_number,
                    'message_id' => $message->id,
                    'type' => $type,
                ],
                userId: $sender?->id
            );

            // Send notifications only for public replies
            if ($type === TicketMessage::TYPE_PUBLIC_REPLY) {
                $this->notifyTicketReplied($ticket, $message, $sender);
            }

            return $message;
        });
    }

    /**
     * Transition ticket status with milestone timestamp updates and audit logs.
     */
    public function updateStatus(Ticket $ticket, string $newStatus, ?User $actor = null, ?string $reason = null): bool
    {
        if (! in_array($newStatus, [
            Ticket::STATUS_OPEN,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_PENDING_USER,
            Ticket::STATUS_ON_HOLD,
            Ticket::STATUS_RESOLVED,
            Ticket::STATUS_CLOSED,
        ], true)) {
            return false;
        }

        if ($ticket->status === $newStatus) {
            return true;
        }

        return DB::transaction(function () use ($ticket, $newStatus, $actor, $reason) {
            $oldStatus = $ticket->status;
            $updates = ['status' => $newStatus];

            if ($newStatus === Ticket::STATUS_RESOLVED && is_null($ticket->resolved_at)) {
                $updates['resolved_at'] = now();
                if ($ticket->resolution_due_at && now()->isAfter($ticket->resolution_due_at)) {
                    $updates['is_sla_resolution_breached'] = true;
                }
            } elseif ($newStatus === Ticket::STATUS_CLOSED && is_null($ticket->closed_at)) {
                $updates['closed_at'] = now();
            }

            $ticket->update($updates);

            // Add system timeline event
            $actorName = $actor?->name ?? __('System');
            $reasonText = $reason ? " Reason: {$reason}" : '';
            TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor?->id,
                'sender_name' => $actorName,
                'type' => TicketMessage::TYPE_SYSTEM_EVENT,
                'message' => __("Ticket status changed from ':old' to ':new' by :actor.:reason", [
                    'old' => ucfirst(str_replace('_', ' ', $oldStatus)),
                    'new' => ucfirst(str_replace('_', ' ', $newStatus)),
                    'actor' => $actorName,
                    'reason' => $reasonText,
                ]),
                'metadata' => ['event' => 'status_change', 'from' => $oldStatus, 'to' => $newStatus],
            ]);

            AuditLogService::log(
                event: 'ticket_status_updated',
                description: "Updated ticket #{$ticket->ticket_number} status from '{$oldStatus}' to '{$newStatus}'.",
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus, 'reason' => $reason],
                userId: $actor?->id
            );

            $this->notifyStatusChanged($ticket, $oldStatus, $newStatus);

            return true;
        });
    }

    /**
     * Assign or reassign ticket to an agent.
     */
    public function assignTicket(Ticket $ticket, ?User $assignee, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($ticket, $assignee, $actor) {
            $oldAssignee = $ticket->assignedTo?->name ?? __('Unassigned');
            $newAssignee = $assignee?->name ?? __('Unassigned');

            $ticket->update(['assigned_to_user_id' => $assignee?->id]);

            $actorName = $actor?->name ?? __('System');
            TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor?->id,
                'sender_name' => $actorName,
                'type' => TicketMessage::TYPE_SYSTEM_EVENT,
                'message' => __('Ticket reassigned from :old to :new by :actor.', [
                    'old' => $oldAssignee,
                    'new' => $newAssignee,
                    'actor' => $actorName,
                ]),
                'metadata' => ['event' => 'assignment_change', 'from' => $oldAssignee, 'to' => $newAssignee],
            ]);

            AuditLogService::log(
                event: 'ticket_assigned',
                description: "Reassigned ticket #{$ticket->ticket_number} to {$newAssignee}.",
                oldValues: ['assigned_to' => $oldAssignee],
                newValues: ['assigned_to' => $newAssignee],
                userId: $actor?->id
            );

            if ($assignee && $assignee->email && EmailService::isEnabled()) {
                $this->emailService->sendTemplate(
                    email: $assignee->email,
                    templateCode: 'ticket_created_staff',
                    variables: [
                        'staff_name' => $assignee->name,
                        'ticket_number' => $ticket->ticket_number,
                        'ticket_subject' => $ticket->subject,
                        'user_name' => $ticket->submitterName(),
                        'priority' => $ticket->priorityLabel(),
                        'ticket_url' => $this->resolveTicketUrl($ticket, true),
                        'app_name' => Setting::appName(),
                    ]
                );
            }

            return true;
        });
    }

    /**
     * Change priority of a ticket.
     */
    public function updatePriority(Ticket $ticket, string $priority, ?User $actor = null): bool
    {
        if (! in_array($priority, [Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT], true)) {
            return false;
        }

        if ($ticket->priority === $priority) {
            return true;
        }

        $old = $ticket->priority;
        $ticket->update(['priority' => $priority]);

        $actorName = $actor?->name ?? __('System');
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor?->id,
            'sender_name' => $actorName,
            'type' => TicketMessage::TYPE_SYSTEM_EVENT,
            'message' => __('Ticket priority changed from :old to :new by :actor.', [
                'old' => ucfirst($old),
                'new' => ucfirst($priority),
                'actor' => $actorName,
            ]),
        ]);

        AuditLogService::log(
            event: 'ticket_priority_updated',
            description: "Updated ticket #{$ticket->ticket_number} priority from {$old} to {$priority}.",
            oldValues: ['priority' => $old],
            newValues: ['priority' => $priority],
            userId: $actor?->id
        );

        return true;
    }

    /**
     * Record customer satisfaction rating and optional feedback review.
     */
    public function rateTicket(Ticket $ticket, int $rating, ?string $feedback = null, ?User $user = null): bool
    {
        $rating = max(1, min(5, $rating));

        $ticket->update([
            'satisfaction_rating' => $rating,
            'satisfaction_feedback' => $feedback,
        ]);

        AuditLogService::log(
            event: 'ticket_rated',
            description: "Submitted satisfaction rating ({$rating}/5 stars) for ticket #{$ticket->ticket_number}.",
            newValues: ['rating' => $rating, 'feedback' => $feedback],
            userId: $user?->id ?? $ticket->user_id
        );

        return true;
    }

    /**
     * Check active tickets against SLA response and resolution milestones.
     */
    public function checkSlaBreaches(): int
    {
        $breachesCount = 0;
        $now = now();

        // 1. First Response Breaches
        $responseBreaches = Ticket::open()
            ->whereNull('first_responded_at')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<', $now)
            ->where('is_sla_response_breached', false)
            ->get();

        foreach ($responseBreaches as $ticket) {
            $ticket->update(['is_sla_response_breached' => true]);
            $breachesCount++;

            AuditLogService::log(
                event: 'ticket_sla_breach',
                description: "Ticket #{$ticket->ticket_number} breached first-response SLA."
            );

            $this->notifySlaWarning($ticket, 'response');
        }

        // 2. Resolution Breaches
        $resolutionBreaches = Ticket::open()
            ->whereNull('resolved_at')
            ->whereNotNull('resolution_due_at')
            ->where('resolution_due_at', '<', $now)
            ->where('is_sla_resolution_breached', false)
            ->get();

        foreach ($resolutionBreaches as $ticket) {
            $ticket->update(['is_sla_resolution_breached' => true]);
            $breachesCount++;

            AuditLogService::log(
                event: 'ticket_sla_breach',
                description: "Ticket #{$ticket->ticket_number} breached resolution SLA."
            );

            $this->notifySlaWarning($ticket, 'resolution');
        }

        return $breachesCount;
    }

    /**
     * Soft delete ticket and child records.
     */
    public function deleteTicket(Ticket $ticket, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($ticket, $actor) {
            $ticket->update(['deleted_by' => $actor?->id]);
            $ticket->delete();

            AuditLogService::log(
                event: 'ticket_deleted',
                description: "Deleted support ticket #{$ticket->ticket_number}.",
                userId: $actor?->id
            );

            return true;
        });
    }

    /**
     * Execute mass bulk action across multiple tickets.
     */
    public function bulkAction(string $action, array $ticketIds, mixed $value = null, ?User $actor = null): int
    {
        $tickets = Ticket::whereIn('id', $ticketIds)->get();
        $count = 0;

        foreach ($tickets as $ticket) {
            $success = match ($action) {
                'status' => $this->updateStatus($ticket, (string) $value, $actor),
                'priority' => $this->updatePriority($ticket, (string) $value, $actor),
                'assign' => $this->assignTicket($ticket, is_numeric($value) ? User::find($value) : null, $actor),
                'delete' => $this->deleteTicket($ticket, $actor),
                default => false,
            };

            if ($success) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Store and sanitize uploaded attachment files.
     *
     * @param  array<UploadedFile>  $uploadedFiles
     */
    protected function storeAttachments(array $uploadedFiles, Ticket $ticket, ?TicketMessage $message = null, ?User $uploader = null): void
    {
        if (empty($uploadedFiles)) {
            return;
        }

        foreach ($uploadedFiles as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $size = $file->getSize();
            $ext = $file->getClientOriginalExtension();

            // Block dangerous extensions
            if (in_array(strtolower($ext), ['php', 'exe', 'bat', 'sh', 'js', 'phtml', 'cmd'], true)) {
                continue;
            }

            $hashName = (string) Str::uuid().'.'.$ext;
            $relativeDir = "tickets/{$ticket->ticket_number}";
            $path = $file->storeAs($relativeDir, $hashName, 'local');

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'ticket_message_id' => $message?->id,
                'uploaded_by_user_id' => $uploader?->id,
                'filename' => $hashName,
                'original_filename' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'disk' => 'local',
                'path' => $path,
            ]);
        }
    }

    /**
     * Dispatch notification when ticket is created.
     */
    protected function notifyTicketCreated(Ticket $ticket): void
    {
        $vars = [
            'user_name' => $ticket->submitterName(),
            'ticket_number' => $ticket->ticket_number,
            'ticket_subject' => $ticket->subject,
            'category' => $ticket->category?->name ?? 'General',
            'priority' => $ticket->priorityLabel(),
            'ticket_url' => $this->resolveTicketUrl($ticket, false),
            'app_name' => Setting::appName(),
        ];

        // 1. Realtime In-App Notification to User
        if ($ticket->user) {
            $this->notificationService->notifyTicket(
                ticket: $ticket,
                eventType: 'created',
                title: __('Ticket #:number Created', ['number' => $ticket->ticket_number]),
                message: __('Your support ticket ":subject" has been registered.', ['subject' => $ticket->subject]),
                targetUser: $ticket->user
            );
        }

        // 2. Realtime In-App Notification to Assigned Staff
        if ($ticket->assignedTo) {
            $this->notificationService->notifyTicket(
                ticket: $ticket,
                eventType: 'assigned',
                title: __('New Ticket Assigned: #:number', ['number' => $ticket->ticket_number]),
                message: __('You have been assigned to ticket ":subject" (:category, :priority).', [
                    'subject' => $ticket->subject,
                    'category' => $ticket->category?->name ?? 'General',
                    'priority' => $ticket->priorityLabel(),
                ]),
                targetUser: $ticket->assignedTo
            );
        }

        // 3. Email to user
        if ($ticket->submitterEmail() && EmailService::isEnabled()) {
            $this->emailService->sendTemplate(
                email: $ticket->submitterEmail(),
                templateCode: 'ticket_created_user',
                variables: $vars
            );
        }

        // 4. SMS to user
        if ($ticket->guest_phone && SmsService::isEnabled()) {
            $this->smsService->sendTemplate(
                phone: $ticket->guest_phone,
                templateCode: 'ticket_created_sms',
                variables: $vars
            );
        }

        // 5. Email to staff / assignee
        $staffEmail = $ticket->assignedTo?->email ?: (string) Setting::get('backup.notification_email', config('mail.from.address'));
        if ($staffEmail && EmailService::isEnabled()) {
            $staffVars = array_merge($vars, [
                'staff_name' => $ticket->assignedTo?->name ?? 'Support Team',
                'ticket_url' => $this->resolveTicketUrl($ticket, true),
            ]);

            $this->emailService->sendTemplate(
                email: $staffEmail,
                templateCode: 'ticket_created_staff',
                variables: $staffVars
            );
        }
    }

    /**
     * Dispatch notification when a reply is posted.
     */
    protected function notifyTicketReplied(Ticket $ticket, TicketMessage $message, ?User $sender): void
    {
        $isStaff = $sender && ($sender->hasRole('Super Administrator') || $sender->hasRole('Administrator') || $sender->can('tickets.reply'));

        if ($isStaff) {
            // Staff replied -> notify user in real-time
            if ($ticket->user) {
                $this->notificationService->notifyTicket(
                    ticket: $ticket,
                    eventType: 'replied',
                    title: __('Support Staff Replied on Ticket #:number', ['number' => $ticket->ticket_number]),
                    message: Str::limit(strip_tags($message->message), 140),
                    targetUser: $ticket->user
                );
            }

            if ($ticket->submitterEmail() && EmailService::isEnabled()) {
                $this->emailService->sendTemplate(
                    email: $ticket->submitterEmail(),
                    templateCode: 'ticket_replied_user',
                    variables: [
                        'user_name' => $ticket->submitterName(),
                        'staff_name' => $sender->name,
                        'ticket_number' => $ticket->ticket_number,
                        'ticket_subject' => $ticket->subject,
                        'reply_excerpt' => Str::limit(strip_tags($message->message), 150),
                        'ticket_url' => $this->resolveTicketUrl($ticket, false),
                        'app_name' => Setting::appName(),
                    ]
                );
            }
        } else {
            // User replied -> notify staff / assignee in real-time
            if ($ticket->assignedTo) {
                $this->notificationService->notifyTicket(
                    ticket: $ticket,
                    eventType: 'replied_by_user',
                    title: __('User Replied on Ticket #:number', ['number' => $ticket->ticket_number]),
                    message: Str::limit(strip_tags($message->message), 140),
                    targetUser: $ticket->assignedTo
                );
            }

            $staffEmail = $ticket->assignedTo?->email ?: (string) Setting::get('backup.notification_email', config('mail.from.address'));
            if ($staffEmail && EmailService::isEnabled()) {
                $this->emailService->sendTemplate(
                    email: $staffEmail,
                    templateCode: 'ticket_replied_staff',
                    variables: [
                        'staff_name' => $ticket->assignedTo?->name ?? 'Support Team',
                        'user_name' => $ticket->submitterName(),
                        'ticket_number' => $ticket->ticket_number,
                        'ticket_subject' => $ticket->subject,
                        'reply_excerpt' => Str::limit(strip_tags($message->message), 150),
                        'ticket_url' => $this->resolveTicketUrl($ticket, true),
                        'app_name' => Setting::appName(),
                    ]
                );
            }
        }
    }

    /**
     * Dispatch notification when ticket status changes.
     */
    protected function notifyStatusChanged(Ticket $ticket, string $oldStatus, string $newStatus): void
    {
        // Realtime In-App Notification
        if ($ticket->user) {
            $this->notificationService->notifyTicket(
                ticket: $ticket,
                eventType: 'status_changed',
                title: __('Ticket #:number Status: :status', [
                    'number' => $ticket->ticket_number,
                    'status' => ucfirst(str_replace('_', ' ', $newStatus)),
                ]),
                message: __('Your ticket status was changed from :old to :new.', [
                    'old' => ucfirst(str_replace('_', ' ', $oldStatus)),
                    'new' => ucfirst(str_replace('_', ' ', $newStatus)),
                ]),
                targetUser: $ticket->user
            );
        }

        if ($ticket->submitterEmail() && EmailService::isEnabled()) {
            $this->emailService->sendTemplate(
                email: $ticket->submitterEmail(),
                templateCode: 'ticket_status_changed',
                variables: [
                    'user_name' => $ticket->submitterName(),
                    'ticket_number' => $ticket->ticket_number,
                    'ticket_subject' => $ticket->subject,
                    'old_status' => ucfirst(str_replace('_', ' ', $oldStatus)),
                    'new_status' => ucfirst(str_replace('_', ' ', $newStatus)),
                    'ticket_url' => $this->resolveTicketUrl($ticket, false),
                    'app_name' => Setting::appName(),
                ]
            );
        }
    }

    /**
     * Dispatch SLA warning alert to staff.
     */
    protected function notifySlaWarning(Ticket $ticket, string $type): void
    {
        // Realtime alert to assigned staff
        if ($ticket->assignedTo) {
            $this->notificationService->notifyTicket(
                ticket: $ticket,
                eventType: 'sla_warning',
                title: __('SLA Warning: Ticket #:number', ['number' => $ticket->ticket_number]),
                message: __('Ticket #:number has reached its :type deadline.', [
                    'number' => $ticket->ticket_number,
                    'type' => $type === 'response' ? 'first response' : 'resolution',
                ]),
                targetUser: $ticket->assignedTo,
                extra: [
                    'color' => 'text-rose-500 bg-rose-500/10 border-rose-500/20',
                    'icon' => 'alert-triangle',
                ]
            );
        }

        $staffEmail = $ticket->assignedTo?->email ?: (string) Setting::get('backup.notification_email', config('mail.from.address'));
        if ($staffEmail && EmailService::isEnabled()) {
            $this->emailService->sendTemplate(
                email: $staffEmail,
                templateCode: 'ticket_sla_warning',
                variables: [
                    'staff_name' => $ticket->assignedTo?->name ?? 'Support Team',
                    'ticket_number' => $ticket->ticket_number,
                    'ticket_subject' => $ticket->subject,
                    'breach_type' => $type === 'response' ? 'First Response SLA' : 'Resolution SLA',
                    'priority' => $ticket->priorityLabel(),
                    'ticket_url' => $this->resolveTicketUrl($ticket, true),
                    'app_name' => Setting::appName(),
                ]
            );
        }
    }

    /**
     * Resolve user portal or admin URL for a ticket.
     */
    public function resolveTicketUrl(Ticket $ticket, bool $isAdmin = false): string
    {
        try {
            $user = auth()->user();
            $team = $user?->currentTeam ?? $user?->personalTeam();

            if ($isAdmin) {
                return $team
                    ? route('admin.tickets.show', ['current_team' => $team->slug, 'ticket' => $ticket->ticket_number])
                    : url("/support/tickets/{$ticket->ticket_number}");
            }

            return $team
                ? route('tickets.show', ['current_team' => $team->slug, 'ticket' => $ticket->ticket_number])
                : url("/tickets/{$ticket->ticket_number}");
        } catch (\Throwable) {
            return $isAdmin ? url("/support/tickets/{$ticket->ticket_number}") : url("/tickets/{$ticket->ticket_number}");
        }
    }
}
