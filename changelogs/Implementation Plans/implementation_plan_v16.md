# Enterprise Realtime Live Chat, WebRTC Calling & Multi-Channel Broadcasting System

## Goal Description
Implement a modern, enterprise-grade, production-ready real-time live chat and communication suite within Laravel using Livewire 4, Alpine.js, Tailwind CSS, and WebRTC. The system will support hybrid real-time transport (configurable between Livewire `wire:poll.3s` polling and Reverb WebSockets broadcasting via Admin Settings), 1-on-1 direct messaging, collaborative group chats, WhatsApp/Telegram-style one-way announcement channels, in-browser WebRTC audio & video calling without external paid services, multi-channel notification dispatching, quote-style message replies with highlight scroll animations, delivery ticks (single, double, and double blue), typing indicators, online presence, and a dedicated admin bulk broadcast messaging suite with personalization tags.

---

## User Review Required
> [!IMPORTANT]
> - **Transport & Signaling Mode:** The system will feature a **Hybrid Realtime Engine** where the admin can choose between **Livewire Polling Only (`wire:poll.3s`)**, **Broadcasting Only (Reverb WebSockets)**, or **Hybrid (WebSockets with automatic Polling fallback)**.
> - **WebRTC Audio & Video Calls:** Built entirely in-house using native WebRTC (`RTCPeerConnection`), using either Laravel Reverb or internal polling signaling. No third-party paid subscriptions (e.g., Twilio, Pusher, Agora) are required.
> - **Admin Control Suite:** All features (Live Chat, Direct Chat, Group Chat, Channels, Voice Calls, Video Calls, Transport Driver, External Notification Channels) can be independently enabled/disabled from a dedicated Admin Chat Settings panel (`/{current_team}/system/settings/chat`).

---

## Proposed Changes

### 1. Database Architecture & Migrations
Create comprehensive, indexed tables with SoftDeletes and Audit tracking:
#### [NEW] [create_chat_tables.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_26_193000_create_chat_tables.php)
- `chat_conversations`: `id`, `uuid`, `type` (`direct`, `group`, `channel`), `title`, `description`, `avatar`, `is_broadcast_only`, `created_by`, `updated_by`, `deleted_by`, `last_message_at`, `last_message_id`, `team_id`, `settings` (JSON), `timestamps`, `softDeletes`.
- `chat_participants`: `id`, `conversation_id`, `user_id`, `role` (`owner`, `admin`, `member`), `is_muted`, `muted_until`, `last_read_at`, `last_read_message_id`, `is_starred`, `is_archived`, `joined_at`, `left_at`, `timestamps`.
- `chat_messages`: `id`, `uuid`, `conversation_id`, `user_id`, `reply_to_id`, `body`, `type` (`text`, `image`, `file`, `audio`, `video`, `system`), `is_edited`, `edited_at`, `is_deleted_for_everyone`, `metadata` (JSON), `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.
- `chat_message_statuses`: `id`, `message_id`, `user_id`, `is_delivered`, `delivered_at`, `is_read`, `read_at`, `is_deleted_for_me`, `timestamps`.
- `chat_message_attachments`: `id`, `uuid`, `message_id`, `file_name`, `file_path`, `file_type`, `file_size`, `file_extension`, `metadata` (JSON), `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.
- `chat_calls`: `id`, `uuid`, `conversation_id` (nullable), `caller_id`, `receiver_id` (nullable), `type` (`audio`, `video`), `status` (`initiated`, `ringing`, `connected`, `rejected`, `missed`, `busy`, `ended`, `failed`), `started_at`, `ended_at`, `duration_seconds`, `signal_data` (JSON), `metadata` (JSON), `timestamps`, `softDeletes`.
- `chat_call_participants`: `id`, `call_id`, `user_id`, `status` (`invited`, `ringing`, `joined`, `declined`, `left`), `joined_at`, `left_at`, `timestamps`.

---

### 2. Eloquent Models
#### [NEW] [ChatConversation.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatConversation.php)
- Relationships to `participants`, `messages`, `lastMessage`, `attachments`, `calls`.
- Query scopes: `direct()`, `group()`, `channel()`, `forUser($userId)`.
- Helper methods: `displayNameFor($user)`, `displayAvatarFor($user)`, `unreadCountFor($user)`, `canPost($user)`.
#### [NEW] [ChatParticipant.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatParticipant.php)
- Participant status, role management (`isOwner()`, `isAdmin()`, `isMember()`).
#### [NEW] [ChatMessage.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatMessage.php)
- Message body, reply hierarchy, soft deletion for everyone vs for me, delivery status helpers (`isReadByAll()`, `isDeliveredToAll()`, `statusForUser($user)`).
#### [NEW] [ChatMessageStatus.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatMessageStatus.php)
- Per-recipient read and delivery timestamps for single/double/blue ticks.
#### [NEW] [ChatMessageAttachment.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatMessageAttachment.php)
- File storage, preview URL generation, MIME inspection.
#### [NEW] [ChatCall.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatCall.php)
- WebRTC call history, status, duration calculation, and signaling data.
#### [NEW] [ChatCallParticipant.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatCallParticipant.php)

---

### 3. Realtime Events & Broadcasting Channels
#### [NEW] [ChatMessageSentEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/ChatMessageSentEvent.php)
- Broadcasts new message to `conversation.{conversation_id}` and `user.{recipient_id}`.
#### [NEW] [ChatMessageUpdatedEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/ChatMessageUpdatedEvent.php)
- Broadcasts edits and delete-for-everyone events.
#### [NEW] [ChatMessageReadEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/ChatMessageReadEvent.php)
- Broadcasts blue tick read receipts.
#### [NEW] [ChatUserTypingEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/ChatUserTypingEvent.php)
- Broadcasts typing indicator.
#### [NEW] [WebRtcCallSignalEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/WebRtcCallSignalEvent.php)
- Realtime signaling for call initiation, offer, answer, candidate, and hangup.
#### [MODIFY] [channels.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/channels.php)
- Add authorization for `conversation.{id}`, `call.{uuid}`, and `user.{id}`.

---

### 4. Enterprise Services
#### [NEW] [ChatService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/ChatService.php)
- Complete chat lifecycle: conversation creation, member management, message sending, file validation & uploads, editing, message deletion (for me / everyone), quote replies, delivery/read tick synchronization.
- Automatic multi-channel notification dispatching on message receipt (In-app database always + external Email, SMS, WhatsApp, Telegram if enabled in Chat Settings).
- Bulk personalized broadcast dispatcher (`{name}`, `{email}`, `{role}`, etc.).
#### [NEW] [WebRtcCallService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php)
- WebRTC call signaling engine (hybrid Reverb/WebSockets & Polling signal exchange), call duration logging, and caller notifications.
#### [MODIFY] [RbacService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/RbacService.php)
- Add granular chat permissions: `chat.access`, `chat.create_group`, `chat.create_channel`, `chat.broadcast`, `chat.manage_settings`, `chat.voice_call`, `chat.video_call`.

---

### 5. Admin Settings & UI Components
#### [NEW] [⚡chat.blade.php (Admin Settings)](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1chat.blade.php)
- Master switches for Live Chat, Direct Chat, Group Chat, Channels, Voice Calls, Video Calls.
- Transport Driver (`hybrid`, `polling`, `broadcasting`), Polling Interval (`3s`, `5s`, `10s`, `30s`), WebRTC Signaling Mode.
- Max file upload size, allowed extensions, edit message time limit.
- External notification channels toggle matrix (Email, SMS, WhatsApp, Telegram).
#### [MODIFY] [settings-nav.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/settings-nav.blade.php)
- Add "Live Chat & Calls" tab to the settings navigation bar.
#### [NEW] [⚡chat.blade.php (Main Live Chat Portal)](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1chat.blade.php)
- Production-ready WhatsApp/Telegram style responsive UI with dark/light mode.
- Conversation list with live search, online green dot, unread badges, filter tabs.
- Active thread with date separators, message bubbles, delivery ticks (single/double/blue), reply quote card with smooth scroll + highlight animation, (edited) badge, attachment cards, media previews.
- Emoji picker popover, typing indicator, auto-expanding composer.
- Channel / Group management drawer (add/remove members, make admin/member, change subject/avatar).
#### [NEW] [⚡broadcast-chat.blade.php (Admin Dedicated Broadcast Section)](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/communications/%E2%9A%A1broadcast-chat.blade.php)
- User search, filter by roles/teams, bulk select.
- Dynamic placeholders composer (`{name}`, `{email}`, `{phone}`).
- Instant bulk dispatch with DB transactions & audit log.
#### [NEW] [⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)
- Global WebRTC incoming call ringing popup & active audio/video call modal with microphone mute, camera toggle, screen sharing, duration timer, and hang up.
#### [MODIFY] [app.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app.blade.php)
- Embed `<livewire:chat-call-overlay />` and audio chime resources.
#### [MODIFY] [topbar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/topbar.blade.php)
- Add quick Live Chat icon with unread badge counter.
#### [MODIFY] [sidebar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)
- Add Live Chat and Broadcast Chat menu items.
#### [MODIFY] [web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register routes for Live Chat, Broadcast Chat, and Admin Chat Settings.

---

## Verification Plan

### Automated Tests
- Run Pest test suite: `php artisan test --compact --filter=ChatSystemTest`
  - Tests 1-on-1 direct conversation and messaging.
  - Tests group conversation with owner/admin/member privileges and member add/remove.
  - Tests broadcast-only channels (ensuring non-admins cannot post).
  - Tests message editing, soft delete for me vs delete for everyone.
  - Tests delivery and read status ticks (single, double, blue).
  - Tests quote reply association and metadata.
  - Tests admin personalized bulk broadcast dispatch.
  - Tests WebRTC call signaling and duration calculation.
  - Tests admin settings toggle guards.

### Manual Verification
- Verify responsive layout across mobile and desktop.
- Verify light and dark mode styling.
- Test message reply scroll and highlight animation.
- Test emoji picker and file attachment uploads.
- Run Pint formatter to ensure style compliance: `vendor/bin/pint --format agent`.
- Write detailed changelog under `changelogs/`.
