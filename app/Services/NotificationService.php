<?php

namespace App\Services;

use App\Events\RealtimeNotificationEvent;
use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    public function __construct(
        protected WhatsAppService $whatsAppService,
        protected TelegramService $telegramService,
        protected EmailService $emailService,
        protected SmsService $smsService
    ) {}

    /**
     * Send an enterprise multi-channel notification to a single user.
     *
     * @param  array{
     *     type?: string,
     *     action_url?: string|null,
     *     action_label?: string|null,
     *     icon?: string|null,
     *     color?: string|null,
     *     channels?: array<string>|null,
     *     metadata?: array<string, mixed>|null,
     *     created_by?: int|null,
     *     broadcast?: bool
     * }  $options
     */
    public function send(
        User|int $user,
        string $title,
        string $message,
        string $category = AppNotification::CATEGORY_SYSTEM,
        array $options = []
    ): ?AppNotification {
        $targetUser = is_numeric($user) ? User::find($user) : $user;

        if (! $targetUser) {
            Log::warning("NotificationService::send failed - User not found: {$user}");

            return null;
        }

        try {
            return DB::transaction(function () use ($targetUser, $title, $message, $category, $options) {
                $type = $options['type'] ?? 'general';
                $dataPayload = [
                    'action_url' => $options['action_url'] ?? null,
                    'action_label' => $options['action_label'] ?? __('View Details'),
                    'icon' => $options['icon'] ?? null,
                    'color' => $options['color'] ?? null,
                    'metadata' => $options['metadata'] ?? [],
                ];

                $inAppEnabled = (bool) Setting::get('notification.channel_database', true);
                $notification = null;

                if ($inAppEnabled) {
                    $notification = AppNotification::create([
                        'user_id' => $targetUser->id,
                        'type' => $type,
                        'category' => $category,
                        'title' => $title,
                        'message' => $message,
                        'data' => $dataPayload,
                        'channel' => AppNotification::CHANNEL_DATABASE,
                        'created_by' => $options['created_by'] ?? auth()->id(),
                    ]);

                    // Broadcast real-time event if broadcasting is enabled
                    $shouldBroadcast = $options['broadcast'] ?? true;
                    if ($shouldBroadcast) {
                        $this->broadcastRealtime($notification, $targetUser);
                    }
                }

                // Dispatch to active external channels
                $requestedChannels = $options['channels'] ?? $this->resolveActiveChannelsForCategory($category);
                $this->dispatchExternalChannels($targetUser, $title, $message, $requestedChannels, $dataPayload);

                AuditLogService::log(
                    event: 'notification_sent',
                    description: "Dispatched notification '{$title}' to user {$targetUser->name} ({$targetUser->email})",
                    newValues: [
                        'user_id' => $targetUser->id,
                        'category' => $category,
                        'type' => $type,
                        'title' => $title,
                    ],
                    userId: $options['created_by'] ?? auth()->id()
                );

                return $notification;
            });
        } catch (Throwable $e) {
            Log::error("NotificationService::send error to user #{$targetUser->id}: ".$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * Send notification to a collection or list of users in bulk.
     *
     * @param  iterable<User|int>  $users
     * @param  array<string, mixed>  $options
     * @return array<AppNotification>
     */
    public function notifyUsers(
        iterable $users,
        string $title,
        string $message,
        string $category = AppNotification::CATEGORY_SYSTEM,
        array $options = []
    ): array {
        $created = [];
        foreach ($users as $user) {
            $notification = $this->send($user, $title, $message, $category, $options);
            if ($notification) {
                $created[] = $notification;
            }
        }

        return $created;
    }

    /**
     * Broadcast realtime event via Reverb / Laravel Echo.
     */
    public function broadcastRealtime(AppNotification $notification, ?User $user = null): void
    {
        $realtimeDriver = (string) Setting::get('notification.realtime_driver', 'hybrid');

        if (in_array($realtimeDriver, ['broadcasting', 'hybrid'], true)) {
            try {
                $user = $user ?? $notification->user;
                $unreadCount = $user ? $user->unreadAppNotificationsCount() : AppNotification::forUser($notification->user_id)->unread()->count();

                event(new RealtimeNotificationEvent($notification, $unreadCount));
            } catch (Throwable $e) {
                // Silently log and gracefully fallback - Livewire polling handles reception
                Log::debug('Realtime broadcast graceful fallback: '.$e->getMessage());
            }
        }
    }

    /**
     * Dispatch to active external communication channels (Email, SMS, WhatsApp, Telegram).
     *
     * @param  array<string>  $channels
     * @param  array<string, mixed>  $dataPayload
     */
    protected function dispatchExternalChannels(
        User $user,
        string $title,
        string $message,
        array $channels,
        array $dataPayload = []
    ): void {
        $actionUrl = $dataPayload['action_url'] ?? null;
        $actionLabel = $dataPayload['action_label'] ?? null;

        // 1. Email Channel
        if (in_array(AppNotification::CHANNEL_EMAIL, $channels, true) && EmailService::isEnabled() && ! empty($user->email)) {
            try {
                $bodyHtml = view('emails.generic_notification', [
                    'user' => $user,
                    'title' => $title,
                    'body' => $message,
                    'actionUrl' => $actionUrl,
                    'actionLabel' => $actionLabel,
                ])->render();

                $this->emailService->sendDirect($user->email, $title, $bodyHtml);
            } catch (Throwable $e) {
                // Fallback to plain html
                $fallbackHtml = "<p>Hello <strong>{$user->name}</strong>,</p><p>{$message}</p>";
                if ($actionUrl) {
                    $fallbackHtml .= "<p><a href='{$actionUrl}' style='display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;'>{$actionLabel}</a></p>";
                }
                $this->emailService->sendDirect($user->email, $title, $fallbackHtml);
            }
        }

        // 2. SMS Channel
        if (in_array(AppNotification::CHANNEL_SMS, $channels, true) && SmsService::isEnabled()) {
            $phone = $user->phone;
            if (! empty($phone)) {
                $smsText = "{$title}: {$message}";
                $this->smsService->sendDirect($phone, $smsText);
            }
        }

        // 3. WhatsApp Channel
        if (in_array(AppNotification::CHANNEL_WHATSAPP, $channels, true) && WhatsAppService::isEnabled()) {
            $phone = $user->whatsapp_no ?: $user->phone;
            if (! empty($phone)) {
                $waText = "*{$title}*\n\n{$message}";
                if ($actionUrl) {
                    $waText .= "\n\n🔗 {$actionLabel}: {$actionUrl}";
                }
                $this->whatsAppService->sendDirect($phone, $waText);
            }
        }

        // 4. Telegram Channel
        if (in_array(AppNotification::CHANNEL_TELEGRAM, $channels, true) && TelegramService::isEnabled()) {
            $this->telegramService->sendFormatted(
                title: $title,
                body: $message,
                actionUrl: $actionUrl,
                actionLabel: $actionLabel
            );
        }
    }

    /**
     * Resolve globally active channels for given category.
     *
     * @return array<string>
     */
    public function resolveActiveChannelsForCategory(string $category): array
    {
        $channels = [];

        if (Setting::get('notification.channel_database', true)) {
            $channels[] = AppNotification::CHANNEL_DATABASE;
        }
        if (Setting::get('notification.channel_email', true)) {
            $channels[] = AppNotification::CHANNEL_EMAIL;
        }
        if (Setting::get('notification.channel_sms', false)) {
            $channels[] = AppNotification::CHANNEL_SMS;
        }
        if (Setting::get('notification.channel_whatsapp', false)) {
            $channels[] = AppNotification::CHANNEL_WHATSAPP;
        }
        if (Setting::get('notification.channel_telegram', false)) {
            $channels[] = AppNotification::CHANNEL_TELEGRAM;
        }

        return $channels;
    }

    /* ----------------------------------------------------------------- *
     *  Domain-Specific Helper Dispatches
     * ----------------------------------------------------------------- */

    /**
     * Dispatch Support Ticket Lifecycle Notification.
     *
     * @param  array<string, mixed>  $extra
     */
    public function notifyTicket(
        Ticket $ticket,
        string $eventType,
        string $title,
        string $message,
        ?User $targetUser = null,
        array $extra = []
    ): ?AppNotification {
        $user = $targetUser ?? $ticket->user;

        if (! $user) {
            return null;
        }

        $ticketService = app(TicketService::class);
        $isStaff = $user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.reply');
        $actionUrl = $ticketService->resolveTicketUrl($ticket, $isStaff);

        return $this->send(
            user: $user,
            title: $title,
            message: $message,
            category: AppNotification::CATEGORY_TICKET,
            options: array_merge([
                'type' => "ticket_{$eventType}",
                'action_url' => $actionUrl,
                'action_label' => __('View Ticket #:number', ['number' => $ticket->ticket_number]),
                'icon' => 'ticket',
                'color' => 'text-amber-500 bg-amber-500/10 border-amber-500/20',
                'metadata' => [
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'ticket_status' => $ticket->status,
                    'priority' => $ticket->priority,
                ],
            ], $extra)
        );
    }

    /**
     * Extensible Realtime Live Chat Dispatcher.
     *
     * @param  array<string, mixed>  $extra
     */
    public function notifyLiveChat(
        User $recipient,
        User $sender,
        string $message,
        string $roomId,
        array $extra = []
    ): ?AppNotification {
        return $this->send(
            user: $recipient,
            title: __('New Message from :sender', ['sender' => $sender->name]),
            message: $message,
            category: AppNotification::CATEGORY_CHAT,
            options: array_merge([
                'type' => 'chat_message',
                'action_url' => $extra['action_url'] ?? url("/chat/{$roomId}"),
                'action_label' => __('Open Chat Room'),
                'icon' => 'message-square',
                'color' => 'text-blue-500 bg-blue-500/10 border-blue-500/20',
                'created_by' => $sender->id,
                'metadata' => [
                    'room_id' => $roomId,
                    'sender_id' => $sender->id,
                    'sender_name' => $sender->name,
                    'sender_avatar' => $sender->avatarUrl(),
                ],
            ], $extra)
        );
    }

    /**
     * Extensible Realtime WebRTC Audio / Video Call Signaler.
     *
     * @param  array<string, mixed>  $extra
     */
    public function notifyWebRtcCall(
        User $recipient,
        User $caller,
        string $callUuid,
        string $callType = 'video',
        string $status = 'ringing',
        array $extra = []
    ): ?AppNotification {
        $icon = $callType === 'video' ? 'video' : 'phone-call';

        return $this->send(
            user: $recipient,
            title: __('Incoming :type Call from :caller', [
                'type' => ucfirst($callType),
                'caller' => $caller->name,
            ]),
            message: __('Click to join the real-time audio/video conference room.'),
            category: AppNotification::CATEGORY_CALL,
            options: array_merge([
                'type' => 'call_incoming',
                'action_url' => $extra['action_url'] ?? url("/call/{$callUuid}"),
                'action_label' => __('Accept Call'),
                'icon' => $icon,
                'color' => 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20',
                'created_by' => $caller->id,
                'metadata' => [
                    'call_uuid' => $callUuid,
                    'call_type' => $callType,
                    'call_status' => $status,
                    'caller_id' => $caller->id,
                    'caller_name' => $caller->name,
                    'caller_avatar' => $caller->avatarUrl(),
                    'sdp' => $extra['sdp'] ?? null,
                ],
            ], $extra)
        );
    }

    /* ----------------------------------------------------------------- *
     *  Notification Lifecycle Management (Mark read, Delete, Fetch)
     * ----------------------------------------------------------------- */

    /**
     * Mark specific notification(s) as read.
     *
     * @param  int|array<int>  $notificationIds
     */
    public function markAsRead(int|array $notificationIds, int $userId): int
    {
        $ids = is_array($notificationIds) ? $notificationIds : [$notificationIds];

        $affected = AppNotification::forUser($userId)
            ->whereIn('id', $ids)
            ->unread()
            ->update(['read_at' => now()]);

        if ($affected > 0) {
            AuditLogService::log(
                event: 'notification_marked_read',
                description: "Marked {$affected} notification(s) as read",
                newValues: ['notification_ids' => $ids],
                userId: $userId
            );
        }

        return $affected;
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(int $userId): int
    {
        $affected = AppNotification::forUser($userId)
            ->unread()
            ->update(['read_at' => now()]);

        if ($affected > 0) {
            AuditLogService::log(
                event: 'notification_mark_all_read',
                description: "Marked all ({$affected}) notifications as read",
                userId: $userId
            );
        }

        return $affected;
    }

    /**
     * Soft-delete a notification.
     */
    public function deleteNotification(int $notificationId, int $userId): bool
    {
        $notification = AppNotification::forUser($userId)->find($notificationId);

        if (! $notification) {
            return false;
        }

        $notification->deleted_by = $userId;
        $notification->save();
        $deleted = (bool) $notification->delete();

        if ($deleted) {
            AuditLogService::log(
                event: 'notification_deleted',
                description: "Deleted notification #{$notificationId}",
                oldValues: ['title' => $notification->title, 'type' => $notification->type],
                userId: $userId
            );
        }

        return $deleted;
    }

    /**
     * Clear / Delete all notifications for a user.
     */
    public function clearAll(int $userId): int
    {
        $notifications = AppNotification::forUser($userId)->get();
        $count = $notifications->count();

        foreach ($notifications as $notification) {
            $notification->deleted_by = $userId;
            $notification->save();
            $notification->delete();
        }

        if ($count > 0) {
            AuditLogService::log(
                event: 'notification_clear_all',
                description: "Cleared all ({$count}) notifications",
                userId: $userId
            );
        }

        return $count;
    }

    /**
     * Get unread count for user.
     */
    public function getUnreadCount(int $userId): int
    {
        return AppNotification::forUser($userId)->unread()->count();
    }

    /**
     * Get recent notifications collection.
     *
     * @return Collection<int, AppNotification>
     */
    public function getRecentNotifications(int $userId, int $limit = 10, ?string $category = null): Collection
    {
        $query = AppNotification::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($category && $category !== 'all') {
            $query->category($category);
        }

        return $query->get();
    }
}
