# Enterprise Realtime Multi-Channel Notification System Implementation Plan

## Goal
Build a modern, production-ready, enterprise-grade real-time Notification System with multi-channel support (In-App Database, Email, SMS, WhatsApp, Telegram with admin enable/disable toggles), dual-engine real-time transport (Livewire `wire:poll.3s` polling and Laravel Reverb / WebSockets broadcasting with configurable fallback and hybrid modes), support ticket integration, and extensible architecture for real-time live chat and WebRTC audio/video calls.

---

## Architecture Overview

```
                                  +-------------------------------+
                                  |   Application Event Trigger   |
                                  | (Ticket, Chat, WebRTC, Alert) |
                                  +---------------+---------------+
                                                  |
                                                  v
                                  +-------------------------------+
                                  |      NotificationService      |
                                  +---------------+---------------+
                                                  |
                     +----------------------------+----------------------------+
                     |                            |                            |
                     v                            v                            v
        +-------------------------+  +-------------------------+  +-------------------------+
        | In-App Database Channel |  |  External Messaging     |  | Realtime Transport Bus  |
        | (Soft-deletable DB)     |  |  - Email (EmailService) |  |  - Reverb Broadcast     |
        +-------------------------+  |  - SMS (SmsService)     |  |  - Livewire Polling 3s  |
                                     |  - WhatsAppService      |  |  - Web Audio Chime Sound|
                                     |  - TelegramService      |  |  - Browser Push API     |
                                     +-------------------------+  +-------------------------+
                                                  |
                                                  v
                                     +-------------------------+
                                     | Admin Settings & Audit  |
                                     | (Channel master toggles,|
                                     |  gateway creds, logs)   |
                                     +-------------------------+
```

---

## Proposed Changes

### 1. Database & Migrations
- **[NEW]** `database/migrations/2026_08_26_000001_create_app_notifications_table.php`:
  - Rich enterprise notifications table with soft deletes (`id`, `uuid`, `user_id`, `type`, `category` [ticket, chat, call, system, marketing], `title`, `message`, `data` [JSON payload with action links, webrtc metadata, chat payload], `read_at`, `sound_played_at`, `channel`, `deleted_at`, `timestamps`, indexes).
  - Also integrates standard Laravel `notifications` capability.
- Seed default notification settings in database / migration for channels:
  - `notification.realtime_driver` (hybrid/polling/broadcasting)
  - `notification.poll_interval` (3s)
  - `notification.sound_enabled` (true)
  - `notification.browser_push_enabled` (true)
  - `notification.channel_database` (true)
  - `notification.channel_email` (true)
  - `notification.channel_sms` (true)
  - `notification.channel_whatsapp` (true)
  - `notification.channel_telegram` (true)
  - WhatsApp gateway settings (`whatsapp.driver`, `whatsapp.api_token`, `whatsapp.phone_number_id`, `whatsapp.business_account_id`, `whatsapp.base_url`)
  - Telegram gateway settings (`telegram.driver`, `telegram.bot_token`, `telegram.default_chat_id`)

### 2. Multi-Channel Messaging Services
- **[NEW]** `app/Services/WhatsAppService.php`:
  - Enterprise WhatsApp gateway service supporting Meta Cloud API, Twilio, UltraMsg, and Log driver.
  - Template & direct message dispatch, phone number normalization, error handling, test message delivery.
- **[NEW]** `app/Services/TelegramService.php`:
  - Enterprise Telegram Bot service supporting Telegram Bot API and Log driver.
  - Formatted HTML/Markdown message sending, target chat ID routing, test message delivery.
- **[NEW]** `app/Services/NotificationService.php`:
  - Central notification orchestrator.
  - Methods:
    - `notify(User $user, string $title, string $message, string $category, array $options = []): AppNotification`
    - `notifyTicketEvent(Ticket $ticket, string $event, array $extra = []): void`
    - `notifyChatEvent(User $recipient, User $sender, string $message, string $roomId, array $extra = []): void`
    - `notifyCallEvent(User $recipient, User $caller, string $callUuid, string $callType, array $extra = []): void` (WebRTC signaling ready)
    - `broadcastRealtime(AppNotification $notification): void`
    - `markAsRead(int|array $notificationIds, int $userId): void`
    - `markAllAsRead(int $userId): void`
    - `deleteNotification(int $notificationId, int $userId): void`
    - `clearAll(int $userId): void`
  - Encapsulated in DB transactions with `AuditLogService` logging.
- **[NEW]** `app/Models/AppNotification.php`:
  - Eloquent model with soft deletes, helper methods (`isRead()`, `actionUrl()`, `iconName()`, `badgeColor()`, `categoryLabel()`, `formattedTime()`).
- **[NEW]** `app/Events/RealtimeNotificationEvent.php`:
  - Real-time broadcast event implementing `ShouldBroadcastNow` on `private-App.Models.User.{id}` channel with normalized payload.

### 3. Settings & Admin UI
- **[NEW]** `resources/views/pages/admin/settings/⚡notification.blade.php`:
  - Comprehensive admin control panel for:
    - Realtime Engine (Mode: Polling `3s`, Broadcasting `Reverb`, or `Hybrid Fallback`)
    - Sound Chime Alerts & Browser Push Notification toggles
    - Channel Master Toggles (In-App, Email, SMS, WhatsApp, Telegram)
    - WhatsApp Gateway Settings (Meta Cloud API, Twilio, UltraMsg, Log, credentials, Test Message sender)
    - Telegram Gateway Settings (Telegram Bot API, Token, Chat ID, Test Message sender)
    - Testing suite: Send test notification across any channel immediately with live feedback
- **[MODIFY]** `resources/views/components/settings-nav.blade.php`:
  - Add "Notifications & Channels" tab with `bell` icon to settings navigation.
- **[MODIFY]** `routes/web.php`:
  - Register route `admin.settings.notification` and user notification inbox `notifications.index`.

### 4. Interactive Livewire Components & Topbar Integration
- **[NEW]** `resources/views/components/⚡notification-bell.blade.php`:
  - Livewire single-file component replacing mock bell in topbar.
  - Dynamically switches transport: `wire:poll.3s` (when configured in settings) or event listener `echo-private:App.Models.User.{id},RealtimeNotificationEvent`.
  - Animated unread counter badge.
  - Interactive dropdown drawer with category tabs ("All", "Unread", "Tickets", "System", "Calls & Chat").
  - Actions: "Mark all as read", "Clear / Dismiss", "Mute sound", "View All in Inbox".
  - Audio Chime synthesized with HTML5 Web Audio API (smooth crystal chime, 0 external assets required).
  - Browser Push Notification integration (`Notification.requestPermission()`).
- **[MODIFY]** `resources/views/layouts/app/topbar.blade.php`:
  - Replace static placeholder mock bell with `<livewire:notification-bell />`.
- **[NEW]** `resources/views/pages/portal/⚡notifications.blade.php`:
  - Full-featured Notification Inbox & Center:
    - Responsive table/grid of all user notifications.
    - Filters: Category (Tickets, System, Chat, Calls), Status (All, Read, Unread), Date Range, Search.
    - Bulk selection with batch actions: "Mark as Read", "Mark as Unread", "Delete Selected".
    - Pagination, quick jump to related ticket or module.

### 5. Ticket System & Modules Realtime Integration
- **[MODIFY]** `app/Services/TicketService.php`:
  - Connect all ticket lifecycle hooks (`notifyTicketCreated`, `notifyTicketReplied`, `notifyStatusChanged`, `notifySlaWarning`) to `NotificationService`.
  - Dispatches to database in-app real-time notification (so staff and users instantly see topbar bell update with sound), and dispatches to Email, SMS, WhatsApp, and Telegram according to admin channel settings.

### 6. Translations & Localization
- **[MODIFY]** `lang/en.json`, `lang/hi.json`, `lang/bn.json`:
  - Add complete translation strings for all notification settings, notification titles, channels, buttons, categories, and toast messages.

### 7. Documentation & Changelog
- **[NEW]** `changelogs/2026-08-26-enterprise-realtime-multi-channel-notification-system.md`:
  - Comprehensive changelog documenting architecture, channels, real-time modes, settings, ticket integration, WebRTC/chat extensibility, and security practices.

---

## Verification Plan

### Automated Tests
- Run Pest test suite covering:
  - `NotificationServiceTest`: verify in-app notification creation, marking read, soft deleting, channel dispatching.
  - `WhatsAppServiceTest` & `TelegramServiceTest`: verify payload generation and driver handling.
  - `NotificationSettingsTest`: verify saving settings, channel enable/disable, testing tools.
  - `RealtimeNotificationBellTest`: verify Livewire component rendering, unread count computation, mark-all-read action.
  - `TicketNotificationIntegrationTest`: verify ticket creation and reply trigger multi-channel notifications.
- Execute: `php artisan test --compact`

### Code Quality & Formatting
- Run `vendor/bin/pint --dirty --format agent` to format PHP code.
- Check database constraints, foreign keys, transaction handling, and audit logs.
