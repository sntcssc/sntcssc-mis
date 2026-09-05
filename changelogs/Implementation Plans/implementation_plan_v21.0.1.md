# Architecture Implementation Plan: Decoupling Online Meetings from Realtime Chat & Calling with Dual Reverb/Pusher Support

## Overview
This plan outlines the complete separation and architectural decoupling of the **Online Meeting Platform** from the **Realtime Live Chat and 1-on-1 / Group WebRTC Calling** system. It also implements dual **Laravel Reverb and Pusher** WebSocket broadcaster support, adds missing soft-delete capabilities, guarantees atomic DB transactions with proper audit logging and exception handling, enhances mobile responsive UI/UX for both light and dark modes, resolves access control and security vulnerabilities, updates language translations, and documents all changes in the project changelog.

---

## User Review Required

> [!IMPORTANT]
> - **Dual Broadcaster Compatibility**: Frontend Laravel Echo will dynamically detect and connect via either **Pusher** (using Pusher app key, cluster, TLS) or **Laravel Reverb** (using Reverb key, host, port, scheme) based on environment configuration (`BROADCAST_CONNECTION` and `VITE_BROADCASTER` or provided keys).
> - **Zero Disruption to Existing Database Data**: Existing database tables (`chat_meetings`, `chat_meeting_participants`, `chat_conversations`, `chat_calls`, etc.) will remain intact, ensuring full backward compatibility. A non-destructive migration will add `deleted_at` to `chat_meeting_participants`.
> - **Dedicated Online Meetings Admin Settings**: An independent settings interface (`/system/settings/meetings`) will be created for Online Meetings so meeting configurations (duration limits, default access modes, STUN/TURN servers) are managed independently from live chat settings.

---

## Proposed Changes

### 1. Controllers & Route Decoupling
Separate meeting join logic from chat join logic so neither domain relies on the other:

#### [NEW] [MeetingJoinController.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/MeetingJoinController.php)
- Dedicated controller for joining online meetings via invite code (`/meetings/join/{code}`).
- Handles team-aware redirect resolution, valid meeting code validation, audit logging, and redirects to `meetings.room`.

#### [MODIFY] [ChatJoinController.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/ChatJoinController.php)
- Remove `joinMeeting()` method to eliminate cross-domain coupling. Focus exclusively on chat conversation joins (`/live-chat/join/{code}`).

#### [MODIFY] [web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Update route `meetings.join` to use `MeetingJoinController::class`.
- Register new admin route `admin.settings.meetings` for dedicated online meetings settings.

---

### 2. Dual Reverb & Pusher Broadcaster Support
Make real-time broadcasting work seamlessly whether using Laravel Reverb or Pusher:

#### [MODIFY] [echo.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/echo.js)
- Enhance Echo initialization to detect whether Reverb or Pusher is active:
  - If Pusher: connect using `broadcaster: 'pusher'`, `cluster`, `forceTLS: true`.
  - If Reverb: connect using `broadcaster: 'reverb'`, `wsHost`, `wsPort`, `wssPort`, `forceTLS`.
  - Fallback to safe `NullEcho` when credentials are not configured, preventing runtime JavaScript exceptions.
  - Expose reconnect helper and state to window and Alpine store.

#### [MODIFY] [head.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/partials/head.blade.php)
- Inject runtime broadcasting configuration meta tags (`broadcaster`, `pusher-cluster`, `reverb-host`, etc.) so frontend Echo initializes correctly even if environment configuration changes without a rebuild.

#### [MODIFY] [.env.example](file:///c:/Users/nilan/Downloads/sntcssc-mis/.env.example)
- Document both `REVERB_*` and `PUSHER_*` configuration keys clearly.

---

### 3. Service Layer Decoupling & Hardening
Ensure services operate independently, handle exceptions cleanly, wrap all writes in DB transactions, and log audit entries:

#### [MODIFY] [MeetingService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/MeetingService.php)
- Add standalone `getIceServers()` method reading meeting-specific STUN/TURN settings (with fallback to global defaults), removing any dependency on `WebRtcCallService`.
- Enhance `isRealtimeSupported()` to support both Pusher and Reverb.
- Ensure all mutations (`createInstantMeeting`, `scheduleMeeting`, `updateMeeting`, `cancelMeeting`, `joinMeeting`, `endMeeting`, `admitWaitingParticipant`, `denyWaitingParticipant`) run inside `DB::transaction` with structured `try/catch` and record detailed audit logs via `AuditLogService`.

#### [MODIFY] [WebRtcCallService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php)
- Maintain focus on 1-on-1 and group voice/video calls inside chat.
- Support both Pusher and Reverb in status diagnostics.
- Ensure all call events and state transitions log audit entries.

---

### 4. JavaScript Modularization (Decoupling Meeting Room from Chat & Calls)

#### [NEW] [meeting.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/meeting.js)
- Extract the 1,400+ lines of `meetingRoomAlpine` from `chat-and-media.js` into an isolated, self-contained module.
- Contains meeting multi-peer WebRTC mesh logic, separate audio sinks (`webrtc-meeting-audio-sink`), grid layouts, screen sharing, hand-raise, reactions, and in-room chat.
- Register `meetingRoomAlpine` to `window` and Alpine data registry.

#### [MODIFY] [chat-and-media.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- Remove `meetingRoomAlpine` so this file handles only Chat, Live Call Previews, and Call Overlays.
- Prevent device conflict: If a user is on an active meeting room page (`/meetings/room/`), suppress call overlay camera/mic hardware acquisition to prevent `NotReadableError` or WebRTC audio collisions.

#### [MODIFY] [app.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/app.js)
- Import both `./chat-and-media.js` and `./meeting.js` and register both component suites cleanly.

---

### 5. Database Soft Deletes & Security Fixes

#### [NEW] Migration: `add_soft_deletes_to_chat_meeting_participants_table.php`
- Adds `deleted_at` timestamp column and index to `chat_meeting_participants`.

#### [MODIFY] [ChatMeetingParticipant.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatMeetingParticipant.php)
- Add `use SoftDeletes;` and `use Auditable;`.

#### [MODIFY] [⚡meeting-room.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meeting-room.blade.php)
- **Security Fix**: In `mount()`, remove vulnerability where uninvited users accessing an `invited_only` meeting were automatically created with `STATUS_INVITED`. Instead, uninvited users are properly placed in `STATUS_WAITING` if waiting room is active or denied entry.
- Remove import of `WebRtcCallService` and call `MeetingService::getIceServers()` instead.
- Improve mobile responsive layout: Ensure video tiles, controls dock, and chat drawer adapt gracefully on small screens, respecting dark and light theme styles.

---

### 6. Admin Settings & UI Separation

#### [NEW] [⚡meetings.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡meetings.blade.php)
- Dedicated Livewire settings component for Online Meetings:
  - Enable / disable online meetings.
  - Max meeting duration limit.
  - Default access mode (Open vs Invited Only).
  - Waiting room configuration.
  - Dedicated WebRTC STUN & TURN servers for meetings.
  - Fully mobile responsive with dark/light mode support.

#### [MODIFY] [⚡chat.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡chat.blade.php)
- Remove meeting settings fields and add a direct link / card pointing to the dedicated Online Meetings settings page.

#### [MODIFY] [sidebar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)
- Ensure Online Meetings has its dedicated presence in navigation and settings.

---

### 7. Translations & Changelog

#### [MODIFY] [en.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json), [bn.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json), [hi.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json)
- Add complete translations for all new and updated meeting settings, security notices, broadcaster statuses, and action confirmations.

#### [NEW] [2026-09-04-decouple-online-meetings-realtime-chat-and-reverb-pusher.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-09-04-decouple-online-meetings-realtime-chat-and-reverb-pusher.md)
- Document the entire architecture separation, dual broadcaster implementation, security fixes, soft deletes, and usage instructions.

---

## Verification Plan

### Automated Tests
Run existing and new Pest feature tests:
```bash
# 1. Run Online Meeting tests
php artisan test --compact --filter=OnlineMeetingTest

# 2. Run Chat System tests
php artisan test --compact --filter=ChatSystemTest

# 3. Run WebRTC Calling tests
php artisan test --compact --filter=WebRtcCallSystemTest

# 4. Run new test verifying decoupling & dual broadcaster support
php artisan test --compact --filter=MeetingDecouplingAndBroadcastingTest
```

### Code Quality & Formatting
Run Laravel Pint to ensure all PHP code complies with project conventions:
```bash
vendor/bin/pint --format agent
```

### Manual / Browser Verification
1. Verify Live Chat operates independently without loading meeting scripts or depending on meeting state.
2. Verify Online Meetings operates independently without depending on `WebRtcCallService` or chat components.
3. Verify switching broadcaster to Pusher or Reverb in environment connects cleanly.
4. Verify responsive mobile layout and dark mode styling in Meeting Room and Meetings Hub.
