# Decouple Online Meetings from Realtime Chat, WebRTC Calling & Dual Reverb/Pusher Engine

**Date**: September 04, 2026  
**Type**: Architectural Decoupling, Enterprise Security, Dual Broadcasting Architecture & UI/UX Upgrade  
**Scope**: `resources/js/echo.js`, `resources/js/meeting.js`, `resources/js/chat-and-media.js`, `resources/js/app.js`, `resources/views/partials/head.blade.php`, `resources/views/pages/portal/⚡meeting-room.blade.php`, `resources/views/pages/admin/settings/⚡meetings.blade.php`, `resources/views/pages/admin/settings/⚡chat.blade.php`, `resources/views/components/settings-nav.blade.php`, `resources/views/layouts/app/sidebar.blade.php`, `app/Http/Controllers/MeetingJoinController.php`, `app/Http/Controllers/ChatJoinController.php`, `app/Services/MeetingService.php`, `app/Services/WebRtcCallService.php`, `app/Models/ChatMeeting.php`, `app/Models/ChatMeetingParticipant.php`, `database/migrations/2026_09_04_232000_add_soft_deletes_to_chat_meeting_participants_table.php`, `routes/web.php`, `.env.example`, `lang/en.json`, `lang/bn.json`, `lang/hi.json`, `tests/Feature/MeetingDecouplingAndBroadcastingTest.php`

---

## 1. Overview & Objectives

This release cleanly decouples the standalone **Online Meeting Platform** from the **Live Chat and WebRTC Calling system**, while adding dynamic dual-broadcaster support for both **Laravel Reverb** and **Pusher Channels**.

### Key Deliverables
1. **Architectural Separation of Online Meetings & Realtime Chat**:
   - Extracted meeting-specific client logic into a separate `resources/js/meeting.js` bundle, removing 1,400+ lines of duplicate meeting code from `resources/js/chat-and-media.js`.
   - Separated the join controller: created `MeetingJoinController` dedicated to `/meetings/join/{code}` and scoped `ChatJoinController` strictly to chat invite links (`/live-chat/join/{code}`).
   - Isolated media audio sinks and device streams: meeting audio now routes to `#webrtc-meeting-audio-sink` while chat calls use `#webrtc-audio-sink`, preventing device lockouts and volume collisions.
   - Added active meeting suppression in the global chat call overlay: incoming call ringtones and popups are suppressed if the user is currently inside an active meeting room (`/meetings/room/`).

2. **Dynamic Dual Reverb & Pusher Broadcaster Support**:
   - Upgraded `resources/js/echo.js` to automatically detect runtime broadcast configuration (`meta[name="broadcast-driver"]` or `VITE_BROADCASTER`).
   - If `BROADCAST_CONNECTION=pusher` (or `pusher-key` exists), it connects via Pusher with cluster, TLS, and correct auth endpoints.
   - If `BROADCAST_CONNECTION=reverb` (or `reverb-key` exists), it connects via Reverb with configurable host, port, scheme, and TLS flags.
   - Injected runtime configuration meta tags via `resources/views/partials/head.blade.php` to ensure zero Vite rebuild is needed when switching between Reverb and Pusher in production `.env`.
   - Updated `.env.example` with clear instructions and complete configuration keys for both broadcasters.

3. **Enterprise Hardening & Security**:
   - **Access Control Fix**: Fixed a security vulnerability in `⚡meeting-room.blade.php` where non-invited users could enter `invited_only` rooms directly as `STATUS_INVITED`; uninvited users are now correctly placed in `STATUS_WAITING` for host admittance.
   - **Team Membership Verification**: `MeetingJoinController` validates `$meeting->team && $user->belongsToTeam($meeting->team)` for team-scoped meetings before admission.
   - **Soft Deletes**: Added `deleted_at` column to `chat_meeting_participants` and integrated `SoftDeletes` and `Auditable` traits into `ChatMeetingParticipant`.
   - **Database Transactions & Try-Catch**: Wrapped meeting operations in `DB::transaction()` with comprehensive audit logging via `AuditLogService`.

4. **Dedicated Online Meetings Admin Settings Interface**:
   - Created `pages/admin/settings/⚡meetings.blade.php` Livewire component and registered route `/admin/settings/meetings` with `admin.settings.meetings` permission.
   - Allows administrators to independently configure meeting availability, max durations, default access modes, waiting room defaults, screen sharing, STUN/TURN ICE servers, and recording permissions.
   - Added navigation link in Admin Sidebar and Settings Sub-navigation with active state highlighting.
   - Added a direct link between Chat Settings and Meeting Settings.

5. **Internationalization & Multi-Language Support**:
   - Added 38+ translations to `lang/en.json`, `lang/bn.json`, and `lang/hi.json` for all meeting settings, access modes, and system notifications.

---

## 2. Technical Details & File Changes

### A. Routing & Controllers
- **`app/Http/Controllers/MeetingJoinController.php` (New)**:
  - Validates meeting status (blocks ended or cancelled meetings with informative alerts).
  - Enforces team permissions for team-scoped meetings.
  - Automatically joins or queues the user into the meeting participant list.
  - Emits `AuditLogService::log()` with `meeting_join_link_used`.
  - Redirects securely to `meetings.room`.
- **`app/Http/Controllers/ChatJoinController.php` (Modified)**:
  - Removed `joinMeeting()` method and unused `ChatMeeting` model reference.
  - Cleaned up to solely handle chat room invite tokens.
- **`routes/web.php`**:
  - Pointed `Route::get('/meetings/join/{code}', [MeetingJoinController::class, 'join'])->name('meetings.join')`.
  - Registered `Route::get('/settings/meetings', ...)->name('admin.settings.meetings')`.

### B. Broadcasting & Dual Engine (`resources/js/echo.js`)
- Reads runtime meta tags:
  ```html
  <meta name="broadcast-driver" content="{{ config('broadcasting.default') }}">
  <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
  <meta name="pusher-key" content="{{ config('broadcasting.connections.pusher.key') }}">
  <meta name="pusher-cluster" content="{{ config('broadcasting.connections.pusher.options.cluster') }}">
  ```
- Evaluates active driver:
  - Pusher configuration uses `cluster`, `forceTLS: true`, and standard Pusher client settings.
  - Reverb configuration uses `wsHost`, `wsPort`, `wssPort`, `forceTLS`, and `enabledTransports: ['ws', 'wss']`.
  - Both setups automatically set up authorization headers for `csrf-token` and handle subscription failures smoothly.

### C. Client-Side WebRTC Isolation (`resources/js/meeting.js`)
- Created `resources/js/meeting.js` containing `meetingRoomAlpine(config)`.
- Features include:
  - Robust multi-tier hardware media stream acquisition with `acquireMediaStreamWithFallback()` and `diagnoseHardwareMediaAvailability()`. Automatically handles camera contention (e.g. Zoom/Teams), overconstrained resolutions, and audio-only graceful degradation.
  - Local microphone/camera/speaker enumeration and dynamic selection.
  - Independent audio routing via Web Audio API and HTML5 audio sink (`setSinkId`).
  - Strict audio-first transceiver track attachments (`replaceTrack` when untracked transceivers exist to prevent `m-lines` SDP renegotiation order mismatch).
  - Isolated presence channels: `presence-meeting.{uuid}`.
  - In-meeting Audio & Video Device Settings Modal, Screen Sharing, Waiting Room management, Chat within meeting, and Participant moderation.
- Registered in `resources/js/app.js` and built with Vite.

### D. Models & Migrations
- **`database/migrations/2026_09_04_232000_add_soft_deletes_to_chat_meeting_participants_table.php`**:
  - Adds `deleted_at` nullable timestamp column.
- **`app/Models/ChatMeetingParticipant.php`**:
  - Added `SoftDeletes` and `Auditable` traits.
- **`app/Models/ChatMeeting.php`**:
  - Added `isCancelled(): bool` helper.
- **`app/Services/MeetingService.php`**:
  - Added `isRealtimeSupported()` and `getIceServers()` checking dedicated `meeting.webrtc_*` settings with fallback to `chat.webrtc_*`.

### E. Admin Settings
- **`resources/views/pages/admin/settings/⚡meetings.blade.php`**:
  - Reactive Livewire settings form with tabbed sections for General, Access & Security, WebRTC & STUN/TURN, and Advanced controls.
  - Flushes setting cache via `Setting::flushCache()` on save.
  - Audits configuration updates via `AuditLogService::log()`.

---

## 3. Verification & Testing

1. **Automated Pest Feature Tests**:
   - `tests/Feature/MeetingDecouplingAndBroadcastingTest.php` (6/6 tests passing)
   - `tests/Feature/OnlineMeetingTest.php` (13/13 tests passing)
   - `tests/Feature/WebRtcCallSystemTest.php` (14/14 tests passing)
   - `tests/Feature/ChatSystemTest.php` (22/22 tests passing)
   - Total: **55 tests passed, 280 assertions**.
2. **Code Style & Formatting**:
   - Verified with `vendor/bin/pint --format agent`.
3. **Asset Compilation**:
   - Built with `npm.cmd run build` without any warnings or errors.
