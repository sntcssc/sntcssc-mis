<?php

namespace App\Services;

use App\Events\ChatMessageReadEvent;
use App\Events\ChatMessageSentEvent;
use App\Events\ChatMessageUpdatedEvent;
use App\Models\AppNotification;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatMessageStatus;
use App\Models\ChatParticipant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected WhatsAppService $whatsAppService,
        protected TelegramService $telegramService,
        protected EmailService $emailService,
        protected SmsService $smsService
    ) {}

    /* ----------------------------------------------------------------- *
     *  Conversation Lifecycle Management
     * ----------------------------------------------------------------- */

    /**
     * Find existing 1-on-1 direct conversation or create a new one.
     */
    public function findOrCreateDirectConversation(User $user1, User $user2, ?int $teamId = null): ChatConversation
    {
        if ($user1->id === $user2->id) {
            throw ValidationException::withMessages([
                'user' => __('You cannot start a direct conversation with yourself.'),
            ]);
        }

        // Look for existing direct conversation with both participants
        $existing = ChatConversation::direct()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user1->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user2->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user1, $user2, $teamId) {
            $conversation = ChatConversation::create([
                'type' => ChatConversation::TYPE_DIRECT,
                'team_id' => $teamId ?? $user1->current_team_id,
                'created_by' => $user1->id,
            ]);

            ChatParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user1->id,
                'role' => ChatParticipant::ROLE_MEMBER,
            ]);

            ChatParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user2->id,
                'role' => ChatParticipant::ROLE_MEMBER,
            ]);

            AuditLogService::log(
                event: 'chat_direct_conversation_created',
                description: "Created direct chat between {$user1->name} and {$user2->name}",
                auditable: $conversation,
                newValues: ['user1_id' => $user1->id, 'user2_id' => $user2->id],
                userId: $user1->id
            );

            return $conversation;
        });
    }

    /**
     * Create a group conversation.
     *
     * @param  array<int>  $participantUserIds
     */
    public function createGroupConversation(
        User $creator,
        string $title,
        array $participantUserIds = [],
        ?string $description = null,
        ?string $avatarPath = null,
        ?int $teamId = null
    ): ChatConversation {
        return DB::transaction(function () use ($creator, $title, $participantUserIds, $description, $avatarPath, $teamId) {
            $conversation = ChatConversation::create([
                'type' => ChatConversation::TYPE_GROUP,
                'title' => $title,
                'description' => $description,
                'avatar' => $avatarPath,
                'team_id' => $teamId ?? $creator->current_team_id,
                'created_by' => $creator->id,
            ]);

            // Add creator as owner
            ChatParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $creator->id,
                'role' => ChatParticipant::ROLE_OWNER,
            ]);

            // Add other members
            $uniqueIds = array_unique(array_filter($participantUserIds, fn ($id) => (int) $id !== (int) $creator->id));
            foreach ($uniqueIds as $userId) {
                ChatParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                    'role' => ChatParticipant::ROLE_MEMBER,
                ]);
            }

            // Post system message
            $this->sendSystemMessage($conversation, __('Group ":title" was created by :name.', [
                'title' => $title,
                'name' => $creator->name,
            ]));

            AuditLogService::log(
                event: 'chat_group_created',
                description: "Created group conversation '{$title}' with ".(count($uniqueIds) + 1).' members',
                auditable: $conversation,
                newValues: ['title' => $title, 'members_count' => count($uniqueIds) + 1],
                userId: $creator->id
            );

            return $conversation;
        });
    }

    /**
     * Create a broadcast channel (WhatsApp / Telegram style).
     *
     * @param  array<int>  $subscriberUserIds
     */
    public function createChannel(
        User $creator,
        string $title,
        array $subscriberUserIds = [],
        ?string $description = null,
        ?string $avatarPath = null,
        bool $isBroadcastOnly = true,
        ?int $teamId = null
    ): ChatConversation {
        return DB::transaction(function () use ($creator, $title, $subscriberUserIds, $description, $avatarPath, $isBroadcastOnly, $teamId) {
            $conversation = ChatConversation::create([
                'type' => ChatConversation::TYPE_CHANNEL,
                'title' => $title,
                'description' => $description,
                'avatar' => $avatarPath,
                'is_broadcast_only' => $isBroadcastOnly,
                'team_id' => $teamId ?? $creator->current_team_id,
                'created_by' => $creator->id,
            ]);

            // Creator as Owner
            ChatParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $creator->id,
                'role' => ChatParticipant::ROLE_OWNER,
            ]);

            // Add subscribers
            $uniqueIds = array_unique(array_filter($subscriberUserIds, fn ($id) => (int) $id !== (int) $creator->id));
            foreach ($uniqueIds as $userId) {
                ChatParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                    'role' => ChatParticipant::ROLE_MEMBER,
                ]);
            }

            $this->sendSystemMessage($conversation, __('Channel ":title" was created.', ['title' => $title]));

            AuditLogService::log(
                event: 'chat_channel_created',
                description: "Created broadcast channel '{$title}'",
                auditable: $conversation,
                newValues: ['title' => $title, 'is_broadcast_only' => $isBroadcastOnly],
                userId: $creator->id
            );

            return $conversation;
        });
    }

    /* ----------------------------------------------------------------- *
     *  Message Handling
     * ----------------------------------------------------------------- */

    /**
     * Send a message with optional attachments and quote reply.
     *
     * @param  array<UploadedFile|array>  $attachments
     */
    public function sendMessage(
        ChatConversation $conversation,
        User $sender,
        ?string $body = null,
        ?int $replyToId = null,
        array $attachments = [],
        string $type = ChatMessage::TYPE_TEXT
    ): ChatMessage {
        if (! $conversation->canPost($sender)) {
            throw ValidationException::withMessages([
                'message' => __('You do not have permission to post messages in this conversation.'),
            ]);
        }

        if (empty(trim((string) $body)) && empty($attachments)) {
            throw ValidationException::withMessages([
                'body' => __('Message cannot be empty.'),
            ]);
        }

        return DB::transaction(function () use ($conversation, $sender, $body, $replyToId, $attachments, $type) {
            $messageType = ! empty($attachments) ? (count($attachments) === 1 ? $this->resolveTypeFromAttachment($attachments[0]) : ChatMessage::TYPE_FILE) : $type;

            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $sender->id,
                'reply_to_id' => $replyToId,
                'body' => $body ? trim($body) : null,
                'type' => $messageType,
                'created_by' => $sender->id,
            ]);

            // Process attachments
            foreach ($attachments as $attachment) {
                $this->storeAttachment($message, $attachment, $sender->id);
            }

            // Create delivery/read status records for all other participants
            $recipientIds = $conversation->participants()
                ->where('user_id', '!=', $sender->id)
                ->whereNull('left_at')
                ->pluck('user_id')
                ->all();

            foreach ($recipientIds as $recipientId) {
                ChatMessageStatus::create([
                    'message_id' => $message->id,
                    'user_id' => $recipientId,
                    'is_delivered' => true,
                    'delivered_at' => now(),
                    'is_read' => false,
                ]);
            }

            // Update conversation last message timestamp & pointer
            $conversation->update([
                'last_message_at' => $message->created_at,
                'last_message_id' => $message->id,
            ]);

            // Update sender's last read pointer
            $conversation->participants()
                ->where('user_id', $sender->id)
                ->update([
                    'last_read_at' => now(),
                    'last_read_message_id' => $message->id,
                ]);

            // Trigger Realtime Broadcast Event
            $this->broadcastSentMessage($message, $recipientIds);

            // Dispatch Multi-Channel Notifications
            $this->dispatchChatNotifications($conversation, $message, $sender, $recipientIds);

            return $message;
        });
    }

    /**
     * Send a system action message.
     */
    public function sendSystemMessage(ChatConversation $conversation, string $text): ChatMessage
    {
        $systemUser = User::first() ?? auth()->user();

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $systemUser?->id ?? 1,
            'body' => $text,
            'type' => ChatMessage::TYPE_SYSTEM,
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_id' => $message->id,
        ]);

        return $message;
    }

    /**
     * Edit an existing message.
     */
    public function editMessage(ChatMessage $message, User $user, string $newBody): ChatMessage
    {
        if ((int) $message->user_id !== (int) $user->id && ! $user->hasRole('Super Administrator')) {
            throw ValidationException::withMessages([
                'message' => __('You can only edit your own messages.'),
            ]);
        }

        $editLimitMinutes = (int) Setting::get('chat.edit_time_limit_minutes', 15);
        if ($editLimitMinutes > 0 && $message->created_at->diffInMinutes(now()) > $editLimitMinutes && ! $user->hasRole('Super Administrator')) {
            throw ValidationException::withMessages([
                'message' => __('This message can no longer be edited as the edit time window has passed.'),
            ]);
        }

        if (empty(trim($newBody))) {
            throw ValidationException::withMessages([
                'body' => __('Message body cannot be empty.'),
            ]);
        }

        return DB::transaction(function () use ($message, $user, $newBody) {
            $oldBody = $message->body;

            $message->update([
                'body' => trim($newBody),
                'is_edited' => true,
                'edited_at' => now(),
                'updated_by' => $user->id,
            ]);

            // Broadcast message updated event
            try {
                event(new ChatMessageUpdatedEvent($message, 'edited'));
            } catch (Throwable $e) {
                Log::debug('Realtime ChatMessageUpdatedEvent graceful fallback: '.$e->getMessage());
            }

            AuditLogService::log(
                event: 'chat_message_edited',
                description: "Edited message #{$message->id}",
                auditable: $message,
                oldValues: ['body' => $oldBody],
                newValues: ['body' => $message->body],
                userId: $user->id
            );

            return $message;
        });
    }

    /**
     * Delete a message for current user only.
     */
    public function deleteMessageForMe(ChatMessage $message, User $user): bool
    {
        return DB::transaction(function () use ($message, $user) {
            $status = ChatMessageStatus::firstOrCreate(
                ['message_id' => $message->id, 'user_id' => $user->id],
                ['is_delivered' => true, 'is_read' => true]
            );

            $status->update(['is_deleted_for_me' => true]);

            return true;
        });
    }

    /**
     * Delete a message for everyone in the conversation.
     */
    public function deleteMessageForEveryone(ChatMessage $message, User $user): bool
    {
        $conversation = $message->conversation;
        $isAuthor = (int) $message->user_id === (int) $user->id;
        $isAdmin = $conversation->isUserAdmin($user->id) || $user->hasRole('Super Administrator');

        if (! $isAuthor && ! $isAdmin) {
            throw ValidationException::withMessages([
                'message' => __('You do not have permission to delete this message for everyone.'),
            ]);
        }

        return DB::transaction(function () use ($message, $user) {
            $metadata = $message->metadata ?? [];
            $metadata['original_body'] = $message->body;
            $metadata['deleted_by_user_id'] = $user->id;
            $metadata['deleted_by_name'] = $user->name;
            $metadata['deleted_at'] = now()->toIso8601String();

            $message->update([
                'is_deleted_for_everyone' => true,
                'body' => __('This message was deleted'),
                'metadata' => $metadata,
                'deleted_by' => $user->id,
            ]);

            // Broadcast message deleted event
            try {
                event(new ChatMessageUpdatedEvent($message, 'deleted_for_everyone'));
            } catch (Throwable $e) {
                Log::debug('Realtime ChatMessageUpdatedEvent graceful fallback: '.$e->getMessage());
            }

            AuditLogService::log(
                event: 'chat_message_deleted_everyone',
                description: "Deleted message #{$message->id} for everyone in conversation #{$message->conversation_id}",
                auditable: $message,
                userId: $user->id
            );

            return true;
        });
    }

    /**
     * Pin a message in a conversation.
     */
    public function pinMessage(ChatMessage $message, User $user): bool
    {
        $conversation = $message->conversation;
        if ($conversation->isGroupOrChannel() && ! $conversation->isUserAdmin($user->id) && ! $user->hasRole('Super Administrator')) {
            throw ValidationException::withMessages([
                'message' => __('Only admins can pin messages in this group/channel.'),
            ]);
        }

        return DB::transaction(function () use ($message, $user) {
            $message->update([
                'is_pinned' => true,
                'pinned_at' => now(),
                'pinned_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            AuditLogService::log(
                event: 'chat_message_pinned',
                description: "Pinned message #{$message->id} in conversation #{$message->conversation_id}",
                auditable: $message,
                userId: $user->id
            );

            return true;
        });
    }

    /**
     * Unpin a message in a conversation.
     */
    public function unpinMessage(ChatMessage $message, User $user): bool
    {
        return DB::transaction(function () use ($message, $user) {
            $message->update([
                'is_pinned' => false,
                'pinned_at' => null,
                'pinned_by' => null,
                'updated_by' => $user->id,
            ]);

            AuditLogService::log(
                event: 'chat_message_unpinned',
                description: "Unpinned message #{$message->id} in conversation #{$message->conversation_id}",
                auditable: $message,
                userId: $user->id
            );

            return true;
        });
    }

    /* ----------------------------------------------------------------- *
     *  Delivery & Seen Status Synchronizations (Single/Double/Blue Ticks)
     * ----------------------------------------------------------------- */

    /**
     * Mark entire conversation as read by given user.
     */
    public function markConversationAsRead(ChatConversation $conversation, User $user): int
    {
        return DB::transaction(function () use ($conversation, $user) {
            $unreadStatuses = ChatMessageStatus::where('user_id', $user->id)
                ->where('is_read', false)
                ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))
                ->get();

            $affectedCount = $unreadStatuses->count();
            if ($affectedCount === 0) {
                return 0;
            }

            $messageIds = $unreadStatuses->pluck('message_id')->all();

            ChatMessageStatus::whereIn('id', $unreadStatuses->pluck('id'))
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                    'is_delivered' => true,
                    'delivered_at' => now(),
                ]);

            $latestMessageId = $conversation->messages()->latest('id')->value('id');

            $conversation->participants()
                ->where('user_id', $user->id)
                ->update([
                    'last_read_at' => now(),
                    'last_read_message_id' => $latestMessageId,
                ]);

            // Broadcast Read Event for Blue Ticks
            try {
                event(new ChatMessageReadEvent($conversation->id, $user->id, $messageIds));
            } catch (Throwable $e) {
                Log::debug('Realtime ChatMessageReadEvent graceful fallback: '.$e->getMessage());
            }

            return $affectedCount;
        });
    }

    /* ----------------------------------------------------------------- *
     *  Participant & Member Management
     * ----------------------------------------------------------------- */

    /**
     * Add member to group / channel.
     */
    public function addParticipant(
        ChatConversation $conversation,
        User $userToAdd,
        User $actor,
        string $role = ChatParticipant::ROLE_MEMBER
    ): ChatParticipant {
        if (! $conversation->isUserAdmin($actor->id) && ! $actor->hasRole('Super Administrator')) {
            throw ValidationException::withMessages([
                'user' => __('Only admins can add members to this conversation.'),
            ]);
        }

        return DB::transaction(function () use ($conversation, $userToAdd, $actor, $role) {
            $participant = ChatParticipant::firstOrNew([
                'conversation_id' => $conversation->id,
                'user_id' => $userToAdd->id,
            ]);

            $participant->role = $role;
            $participant->left_at = null;
            $participant->joined_at = now();
            $participant->save();

            $this->sendSystemMessage($conversation, __(':name was added by :actor.', [
                'name' => $userToAdd->name,
                'actor' => $actor->name,
            ]));

            AuditLogService::log(
                event: 'chat_participant_added',
                description: "Added user {$userToAdd->name} to conversation '{$conversation->displayNameFor()}'",
                auditable: $conversation,
                newValues: ['user_id' => $userToAdd->id, 'role' => $role],
                userId: $actor->id
            );

            return $participant;
        });
    }

    /**
     * Remove member from group / channel.
     */
    public function removeParticipant(ChatConversation $conversation, User $userToRemove, User $actor): bool
    {
        $isSelf = (int) $userToRemove->id === (int) $actor->id;
        $isAdmin = $conversation->isUserAdmin($actor->id) || $actor->hasRole('Super Administrator');

        if (! $isSelf && ! $isAdmin) {
            throw ValidationException::withMessages([
                'user' => __('You do not have permission to remove this member.'),
            ]);
        }

        return DB::transaction(function () use ($conversation, $userToRemove, $actor, $isSelf) {
            $participant = $conversation->participants()->where('user_id', $userToRemove->id)->first();

            if (! $participant) {
                return false;
            }

            $participant->update(['left_at' => now()]);
            $participant->delete();

            $systemText = $isSelf
                ? __(':name left the conversation.', ['name' => $userToRemove->name])
                : __(':name was removed by :actor.', ['name' => $userToRemove->name, 'actor' => $actor->name]);

            $this->sendSystemMessage($conversation, $systemText);

            AuditLogService::log(
                event: 'chat_participant_removed',
                description: $systemText,
                auditable: $conversation,
                userId: $actor->id
            );

            return true;
        });
    }

    /**
     * Promote / Demote participant role.
     */
    public function updateParticipantRole(
        ChatConversation $conversation,
        User $targetUser,
        string $newRole,
        User $actor
    ): bool {
        if (! $conversation->isUserAdmin($actor->id) && ! $actor->hasRole('Super Administrator')) {
            throw ValidationException::withMessages([
                'role' => __('Only admins can change member roles.'),
            ]);
        }

        return DB::transaction(function () use ($conversation, $targetUser, $newRole, $actor) {
            $participant = $conversation->participants()->where('user_id', $targetUser->id)->first();

            if (! $participant) {
                return false;
            }

            $oldRole = $participant->role;
            $participant->update(['role' => $newRole]);

            $roleLabel = ucfirst($newRole);
            $this->sendSystemMessage($conversation, __(':name is now a :role.', [
                'name' => $targetUser->name,
                'role' => $roleLabel,
            ]));

            AuditLogService::log(
                event: 'chat_participant_role_updated',
                description: "Changed role of {$targetUser->name} to {$newRole} in conversation #{$conversation->id}",
                auditable: $conversation,
                oldValues: ['role' => $oldRole],
                newValues: ['role' => $newRole],
                userId: $actor->id
            );

            return true;
        });
    }

    /* ----------------------------------------------------------------- *
     *  Broadcast Messaging System
     * ----------------------------------------------------------------- */

    /**
     * Send personalized broadcast messages to a list of users.
     *
     * @param  iterable<User>  $targetUsers
     * @return array{sent_count: int, failed_count: int}
     */
    public function sendBroadcastMessage(
        User $admin,
        iterable $targetUsers,
        string $bodyTemplate,
        ?int $teamId = null
    ): array {
        $sentCount = 0;
        $failedCount = 0;

        foreach ($targetUsers as $user) {
            try {
                // Personalize template placeholders
                $personalizedBody = str_replace(
                    ['{name}', '{first_name}', '{email}', '{phone}', '{role}'],
                    [
                        $user->name,
                        explode(' ', $user->name)[0] ?? $user->name,
                        $user->email,
                        $user->phone ?? '',
                        $user->roles->first()?->name ?? 'Member',
                    ],
                    $bodyTemplate
                );

                $conversation = $this->findOrCreateDirectConversation($admin, $user, $teamId);
                $this->sendMessage($conversation, $admin, $personalizedBody);
                $sentCount++;
            } catch (Throwable $e) {
                Log::error("Failed to send broadcast message to user #{$user->id}: ".$e->getMessage());
                $failedCount++;
            }
        }

        AuditLogService::log(
            event: 'chat_bulk_broadcast_dispatched',
            description: "Admin {$admin->name} dispatched bulk broadcast to {$sentCount} users",
            newValues: [
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'template_preview' => mb_substr($bodyTemplate, 0, 100),
            ],
            userId: $admin->id
        );

        return [
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Internal Helper Routines
     * ----------------------------------------------------------------- */

    protected function broadcastSentMessage(ChatMessage $message, array $recipientIds): void
    {
        $realtimeDriver = (string) Setting::get('chat.transport_driver', 'hybrid');

        if (in_array($realtimeDriver, ['broadcasting', 'hybrid'], true)) {
            try {
                event(new ChatMessageSentEvent($message, $recipientIds));
            } catch (Throwable $e) {
                Log::debug('Realtime ChatMessageSentEvent graceful fallback: '.$e->getMessage());
            }
        }
    }

    /**
     * Dispatch multi-channel notifications for chat messages.
     */
    protected function dispatchChatNotifications(
        ChatConversation $conversation,
        ChatMessage $message,
        User $sender,
        array $recipientIds
    ): void {
        $chatEnabled = (bool) Setting::get('chat.enabled', true);
        if (! $chatEnabled) {
            return;
        }

        $notifyEmail = (bool) Setting::get('chat.notify_email', true);
        $notifySms = (bool) Setting::get('chat.notify_sms', false);
        $notifyWhatsApp = (bool) Setting::get('chat.notify_whatsapp', false);
        $notifyTelegram = (bool) Setting::get('chat.notify_telegram', false);

        $previewText = $message->body ?: __('Sent an attachment');
        $chatUrl = route('admin.chat.index', ['team' => $conversation->team?->slug ?? 'default', 'c' => $conversation->uuid]);

        foreach ($recipientIds as $recipientId) {
            $recipient = User::find($recipientId);
            if (! $recipient) {
                continue;
            }

            // Always create In-App Database notification
            $this->notificationService->send(
                user: $recipient,
                title: $conversation->isDirect()
                    ? __('New message from :name', ['name' => $sender->name])
                    : __(':name in :group', ['name' => $sender->name, 'group' => $conversation->displayNameFor($recipient)]),
                message: $previewText,
                category: AppNotification::CATEGORY_CHAT,
                options: [
                    'type' => 'chat_message',
                    'action_url' => $chatUrl,
                    'action_label' => __('Open Live Chat'),
                    'icon' => 'message-square',
                    'color' => 'text-blue-500 bg-blue-500/10 border-blue-500/20',
                    'created_by' => $sender->id,
                    'channels' => [AppNotification::CHANNEL_DATABASE],
                    'metadata' => [
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                        'sender_id' => $sender->id,
                    ],
                ]
            );

            // Optional External Channels based on Admin Settings
            if ($notifyEmail && ! empty($recipient->email) && EmailService::isEnabled()) {
                try {
                    $emailSubject = __('[Chat] :name: :message', ['name' => $sender->name, 'message' => mb_substr($previewText, 0, 40)]);
                    $emailBody = view('emails.generic_notification', [
                        'user' => $recipient,
                        'title' => __('New Chat Message from :sender', ['sender' => $sender->name]),
                        'body' => $previewText,
                        'actionUrl' => $chatUrl,
                        'actionLabel' => __('Reply in Chat'),
                    ])->render();

                    $this->emailService->sendDirect($recipient->email, $emailSubject, $emailBody);
                } catch (Throwable $e) {
                    Log::debug('Chat email alert error: '.$e->getMessage());
                }
            }

            if ($notifyWhatsApp && ! empty($recipient->phone) && WhatsAppService::isEnabled()) {
                try {
                    $waText = '*[Chat]* '.$sender->name.': '.$previewText."\n\n".__('Reply').': '.$chatUrl;
                    $this->whatsAppService->sendDirect($recipient->whatsapp_no ?: $recipient->phone, $waText);
                } catch (Throwable $e) {
                    Log::debug('Chat WhatsApp alert error: '.$e->getMessage());
                }
            }

            if ($notifySms && ! empty($recipient->phone) && SmsService::isEnabled()) {
                try {
                    $smsText = $sender->name.': '.mb_substr($previewText, 0, 100);
                    $this->smsService->sendDirect($recipient->phone, $smsText);
                } catch (Throwable $e) {
                    Log::debug('Chat SMS alert error: '.$e->getMessage());
                }
            }

            if ($notifyTelegram && TelegramService::isEnabled()) {
                try {
                    $this->telegramService->sendFormatted(
                        title: __('New Chat Message from :sender', ['sender' => $sender->name]),
                        body: $previewText,
                        actionUrl: $chatUrl,
                        actionLabel: __('Open Chat')
                    );
                } catch (Throwable $e) {
                    Log::debug('Chat Telegram alert error: '.$e->getMessage());
                }
            }
        }
    }

    /**
     * Store and associate attachment with message.
     */
    protected function storeAttachment(ChatMessage $message, UploadedFile|array $file, int $userId): ChatMessageAttachment
    {
        if ($file instanceof UploadedFile) {
            $fileName = $file->getClientOriginalName();
            $fileExtension = $file->getClientOriginalExtension();
            $fileType = $file->getMimeType() ?? 'application/octet-stream';
            $fileSize = $file->getSize();

            $path = FileUploadService::upload($file, 'chat/attachments');

            return ChatMessageAttachment::create([
                'message_id' => $message->id,
                'file_name' => $fileName,
                'file_path' => $path,
                'file_type' => $fileType,
                'file_size' => $fileSize,
                'file_extension' => $fileExtension,
                'created_by' => $userId,
            ]);
        }

        // Array descriptor fallback
        return ChatMessageAttachment::create([
            'message_id' => $message->id,
            'file_name' => $file['name'] ?? 'attachment',
            'file_path' => $file['path'] ?? '',
            'file_type' => $file['type'] ?? 'application/octet-stream',
            'file_size' => $file['size'] ?? 0,
            'file_extension' => $file['extension'] ?? 'bin',
            'created_by' => $userId,
        ]);
    }

    protected function resolveTypeFromAttachment(mixed $attachment): string
    {
        if ($attachment instanceof UploadedFile) {
            $mime = $attachment->getMimeType();
            if (str_starts_with($mime, 'image/')) {
                return ChatMessage::TYPE_IMAGE;
            }
            if (str_starts_with($mime, 'audio/')) {
                return ChatMessage::TYPE_AUDIO;
            }
            if (str_starts_with($mime, 'video/')) {
                return ChatMessage::TYPE_VIDEO;
            }
        }

        return ChatMessage::TYPE_FILE;
    }
}
