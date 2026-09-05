# Walkthrough - Laravel Reverb WebSocket & Real-Time WebRTC Calling Architecture

We have implemented the real-time WebSocket architecture using **Laravel Reverb**, ensuring voice and video calling features, along with online meetings, operate with real-time peer-to-peer signaling. Calling features are now dynamically gated by live WebSocket connectivity status.

---

## 1. What Was Built & Modified

### A. Laravel Reverb WebSocket Server Backend
- **Installed & Configured Reverb**: Installed `laravel/reverb: ^1.11.1` and configured `config/reverb.php` with `.env` / `.env.example` credentials.
- **WebSocket Gating in Backend Services**: In [`WebRtcCallService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php), added `isRealtimeSupported()` and `getRealtimeStatus()`. Calls throw clear `ValidationException` if WebSockets are required and unavailable.
- **Meeting Real-Time Broadcasts**: Created [`MeetingRealtimeEvent.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/MeetingRealtimeEvent.php) broadcasting on `meeting.{uuid}` channel.
- **Channel Authorizations**: Updated [`routes/channels.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/channels.php) to authorize `meeting.{uuid}`, `call.{uuid}`, and `user.{id}` channels.
- **Enterprise Standards**: Wrapped state changes in database transactions (`DB::transaction`), audit logging via `AuditLogService::log`, soft deletes, and localized user feedback.

---

### B. Frontend WebSocket Connection Management & Signaling
- **Dynamic Connection State Tracking**: In [`resources/js/echo.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/echo.js), hooked into Pusher/Reverb connection events (`connected`, `connecting`, `disconnected`, `unavailable`, `failed`) and dispatched `websocket-status-changed` events.
- **Global Reactive Alpine Store**: Added `$store.websocket` in [`resources/js/app.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/app.js) with `.connected`, `.status`, and `.reconnect()`.
- **Direct WebRTC Signaling**: Updated [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js) so `chatCallOverlayAlpine` subscribes directly to `private-call.{callUuid}` and `private-user.{id}`, handles ICE candidate queueing, and guarantees audio/video playback.
- **Online Meeting Room Real-Time Sync**: Configured `meetingRoomAlpine` to listen for real-time events (`participant_joined`, `waiting_admitted`, `in_room_chat`, `floating_emoji`, `meeting_ended`).

---

### C. UI/UX & Responsive Controls
- **Chat Conversation Header**:
  - Added live status pill (`Live` vs `Offline`).
  - Voice and Video Call buttons are bound to `$store.websocket.connected`. When disconnected, buttons show as disabled with explanatory tooltips.
  - Added a non-intrusive offline banner with a **Reconnect** button.
- **Admin Settings**:
  - Added `chat.require_websocket_for_calls` switch in [`⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡chat.blade.php).
  - Added a live WebSocket server diagnostic card showing active driver, host, port, and scheme.

---

## 2. Verification & Automated Test Results

### Automated Feature Tests
Executed Pest test suites across WebRTC Calling, Chat, and Online Meetings:
```bash
php artisan test tests/Feature/WebRtcCallSystemTest.php tests/Feature/ChatSystemTest.php tests/Feature/OnlineMeetingTest.php
```

```
   PASS  Tests\Feature\WebRtcCallSystemTest
  ✓ WebRtcCallService correctly detects live realtime WebSocket support
  ✓ Initiating a call requires active WebSocket connection when setting is enabled
  ✓ Caller can successfully initiate 1-on-1 WebRTC call and dispatch signals
  ✓ Caller can initiate group WebRTC call to all group members
  ✓ Call receiver can accept incoming WebRTC call
  ✓ Call receiver can reject incoming call
  ✓ Active call can be ended and duration is computed in seconds
  ✓ WebRtcCallService returns configured STUN and TURN ICE servers
  ✓ Chat livewire component verifies WebSocket state when prompting and launching calls
  ✓ Admin chat settings component allows configuring require_websocket_for_calls

   PASS  Tests\Feature\ChatSystemTest (27 tests)
   PASS  Tests\Feature\OnlineMeetingTest (5 tests)

  Tests:    42 passed (215 assertions)
  Duration: 100.98s
```

### Frontend Assets Compilation
```bash
npm.cmd run build
✓ built in 2.18s
```

### Code Style
```bash
vendor/bin/pint --format agent
✓ All PHP files formatted to project standard.
```

---

## 3. How to Run Locally & In Production

### Running Locally
1. Start the Laravel Reverb WebSocket server:
   ```bash
   php artisan reverb:start --debug
   ```
2. Start the Laravel application:
   ```bash
   php artisan serve
   ```
3. In browser, navigate to the Chat or Meeting room. The **Live** badge will turn green, enabling real-time calling and instant conference synchronization.

### Production Deployment
Refer to the detailed step-by-step production deployment guide in [Changelog](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-29-laravel-reverb-websocket-realtime-calling-architecture.md) covering **Supervisor**, **Systemd**, and **Nginx SSL WebSocket Reverse Proxy (`/app/`)**.
