# Realtime Calling & Online Meetings Architecture with Laravel Reverb WebSockets

This plan establishes an enterprise-grade realtime WebSockets architecture using **Laravel Reverb** for Live Chat voice/video calling and Online Meetings. When a live WebSocket connection is active, users can seamlessly conduct high-fidelity voice and video calls with realtime state synchronization (ringing, accept, decline, end, media toggle, chat). When WebSockets are not configured or disconnected, calling features are safely disabled with clear UI feedback to prevent broken calls and confusion.

---

## User Review Required

> [!IMPORTANT]
> **Key Architecture Decisions:**
> 1. **Strict WebSocket Requirement for Calling**: Calling features (1-on-1 voice/video, group calls, and live conference AV exchange) require an active live WebSocket connection. If WebSockets are disconnected or unconfigured, call buttons are disabled/badged with a clear status indicator and tooltip explaining the requirement.
> 2. **Laravel Reverb Integration**: We will install and configure `laravel/reverb` (`^1.11`) as the primary high-performance WebSocket server, using Pusher-compatible protocol over port 8080 (or 443 with TLS/reverse proxy).
> 3. **Direct Zero-Latency Client Signaling**: WebRTC SDP Offer/Answer and ICE Candidates will be transmitted over Reverb private channels directly to the client's `RTCPeerConnection` for instantaneous audio/video negotiation without polling latency.
> 4. **Production Deployment Documentation**: We will provide complete hosting instructions for deploying Reverb on Ubuntu/Nginx with SSL, Supervisor, Systemd, Docker, and shared VPS environments.

---

## Proposed Changes

### 1. Package Installation & Broadcasting Configuration

#### [MODIFY] [composer.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/composer.json)
- Require `laravel/reverb: ^1.11` in dependencies.

#### [MODIFY] [.env](file:///c:/Users/nilan/Downloads/sntcssc-mis/.env) & [.env.example](file:///c:/Users/nilan/Downloads/sntcssc-mis/.env.example)
- Configure `BROADCAST_CONNECTION=reverb`.
- Add `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`.
- Add `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`.

#### [NEW] [config/reverb.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/config/reverb.php)
- Laravel Reverb configuration with application definitions, server options, scaling/Redis support, and pulse/health metrics.

#### [MODIFY] [config/broadcasting.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/config/broadcasting.php)
- Ensure the `reverb` connection configuration correctly maps options, host, port, scheme, and client options.

---

### 2. Backend Services, Events & Authorization Channels

#### [MODIFY] [app/Services/WebRtcCallService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php)
- Add `isRealtimeSupported(): bool` method to verify broadcasting driver and credentials.
- Add `getRealtimeStatus(): array` for diagnostics and system health reporting.
- Update `initiateCall` and `initiateGroupCall` to enforce live WebSocket/broadcasting configuration with clean localized validation exceptions if unconfigured.
- Wrap call state operations (initiate, accept, reject, end) in `DB::transaction` with comprehensive `try-catch`, soft delete support, and detailed audit logging via `AuditLogService::log`.

#### [NEW] [app/Events/MeetingRealtimeEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/MeetingRealtimeEvent.php)
- Realtime broadcast event for Online Meeting rooms (participant joins, leaves, waiting room admissions/denials, hand raises, emoji reactions, and in-room chat synchronization).

#### [MODIFY] [routes/channels.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/channels.php)
- Add authorization for `meeting.{uuid}` and `meeting.presence.{uuid}` channels.
- Verify private channels for `user.{id}` and `call.{uuid}`.

---

### 3. Frontend Echo & WebSocket Connection State Manager

#### [MODIFY] [resources/js/echo.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/echo.js)
- Enhance Echo initialization to monitor Pusher connection states (`connected`, `connecting`, `disconnected`, `unavailable`, `failed`).
- Maintain `window.WebSocketState = { isConnected: boolean, status: string, error: null }`.
- Dispatch global `websocket-status-changed` events on `window` and sync with Alpine store `Alpine.store('websocket')`.
- Provide manual `reconnect()` method.

#### [MODIFY] [resources/js/chat-and-media.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- Direct 0ms client-side WebRTC signaling subscriptions over `Echo.private('user.' + id)` and `Echo.private('call.' + uuid)`.
- Streamline `RTCPeerConnection` lifecycle: proper audio/video constraints, ICE candidate queueing, track management, hardware fallback, and reliable remote audio/video element bindings.
- Enhance `chatPreCallPreviewAlpine` and `chatCallOverlayAlpine` to react to live WebSocket connection changes.
- Enhance `meetingRoomAlpine` with real-time meeting room synchronization (participant join/leave events, host action events, and in-room chat sync).

---

### 4. UI/UX Components & Livewire Views

#### [MODIFY] [resources/views/pages/portal/⚡chat.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1chat.blade.php)
- Update Voice and Video call buttons in conversation header, info drawer, and user modals:
  - When WebSocket is **Connected**: Show enabled interactive call buttons with active indicators.
  - When WebSocket is **Disconnected / Unconfigured**: Disable call buttons with clear badge/tooltip explaining that real-time calling requires an active WebSocket connection, and display a subtle banner with a "Reconnect" action.
- Ensure light and dark mode styling with full mobile responsiveness.

#### [MODIFY] [resources/views/components/⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)
- Modern enterprise calling overlay with Picture-in-Picture, full-screen dock, audio visualizer, mute/unmute, camera toggle, screen sharing, duration timer, and fallback alerts.
- Check WebSocket connection status and display connection health indicator.

#### [MODIFY] [resources/views/pages/portal/⚡meeting-room.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1meeting-room.blade.php)
- Add live WebSocket connection badge in the room header (`Connected (Reverb)` vs `Disconnected`).
- Listen to `MeetingRealtimeEvent` on `meeting.{uuid}` channel for instant participant synchronization, waiting room admissions, and in-room chat.
- Show clear reconnect prompt if WebSocket disconnects during a meeting.

#### [MODIFY] [resources/views/pages/admin/settings/⚡chat.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1chat.blade.php)
- Add live WebSocket server status indicator / diagnostic test in Admin Chat & Calling settings.
- Add setting toggle `chat.require_websocket_for_calls` (default: true).

---

### 5. Translations & Enterprise Hardening

#### [MODIFY] [lang/en.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json)
- Add all required localized strings for WebSocket status, calling disabled notifications, reconnection alerts, and error messages.

---

### 6. Deployment Documentation & Changelogs

#### [NEW] [changelogs/2026-08-29-reverb-websocket-realtime-calling-architecture.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-29-reverb-websocket-realtime-calling-architecture.md)
- Complete technical changelog of all architecture changes.
- Comprehensive production deployment guide:
  - Running Laravel Reverb via CLI (`php artisan reverb:start`).
  - Production Supervisor daemon configuration (`/etc/supervisor/conf.d/reverb.conf`).
  - Nginx SSL reverse proxy configuration (`proxy_pass http://127.0.0.1:8080; proxy_set_header Upgrade $http_upgrade;`).
  - Firewall & Port requirements.
  - Environment variables guide for production domain / SSL.

---

## Verification Plan

### Automated Tests
- Run Pest test suite for Chat & WebRTC calling:
  `php artisan test tests/Feature/ChatSystemTest.php`
- Create and run `tests/Feature/WebRtcCallSystemTest.php` to verify:
  - Call initiation succeeds when broadcasting is enabled.
  - Call initiation is gracefully blocked when broadcasting is disabled.
  - Call acceptance, rejection, signal dispatch, and duration tracking.
  - Audit log entries created for call events.
- Run Pint code formatting:
  `vendor/bin/pint --format agent`

### Manual Verification
1. **Local WebSocket Verification**:
   - Start Reverb server (`php artisan reverb:start --debug`).
   - Open Chat in browser, verify WebSocket connects (Echo channel subscription successful, green/active call buttons).
   - Test call initiation between two users: verify instant incoming call popup, ringing audio, accept, audio/video track transmission, and clean call end.
2. **WebSocket Disconnection Handling**:
   - Stop Reverb server or set invalid port.
   - Verify client detects disconnection and call buttons are gracefully disabled with helpful message.
   - Re-enable Reverb, verify automatic reconnection and call button restoration.
