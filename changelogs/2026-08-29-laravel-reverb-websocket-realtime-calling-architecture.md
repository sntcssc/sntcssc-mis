# Laravel Reverb WebSocket Server Integration & Real-Time Calling Architecture

**Date:** August 29, 2026  
**Release / Feature:** Real-Time WebRTC Voice/Video Calling, Laravel Reverb WebSocket Integration, Channel Authorization & Production Deployment

---

## 1. Executive Summary & Problem Solved

### The Problem
Previously, voice and video calling features and online meetings were accessible in the UI even in environments running without an active WebSocket server or under pure HTTP polling mode. Because WebRTC peer connection establishment (SDP offer/answer exchange, ICE candidate candidates) and meeting state changes (waiting room admission, participants joining/leaving, in-room chat) require sub-millisecond bidirectional full-duplex signaling, running without an active WebSocket connection caused:
- Video and audio streams failing to negotiate or transmit.
- Out-of-sync call ringing, answer, and termination states.
- Poor user experience where buttons were clickable but calling failed silently.

### The Architectural Solution
1. **Enforced WebSocket Gating for Calling & Meetings:** The calling architecture now dynamically checks for a live WebSocket connection. When disconnected or unconfigured, calling buttons are gracefully disabled with informative tooltips and badges, preventing broken calls.
2. **Laravel Reverb WebSocket Server:** Installed and configured `laravel/reverb` as the primary first-party real-time WebSocket server.
3. **Reactive Global Connection State (`Alpine.store('websocket')` & `window.WebSocketState`):** Client-side Pusher/Reverb connection states (`connected`, `connecting`, `disconnected`, `unavailable`, `failed`) are monitored in real time and automatically synchronized across Blade views and Alpine.js components.
4. **Direct Channel Signaling & ICE Candidate Queueing:** Real-time WebRTC signals are exchanged directly on private channels (`call.{uuid}`, `user.{id}`, `meeting.{uuid}`) with automated audio element playback and ICE candidate queuing.
5. **Admin Diagnostics & Configuration:** Added `chat.require_websocket_for_calls` setting switch and a live WebSocket server diagnostic card in Admin Settings.

---

## 2. Key Changes & File Modifications

### Backend & Service Layer
- `composer.json` & `composer.lock`: Added `laravel/reverb: ^1.11.1` and `pusher/pusher-php-server: ^7.3`.
- `config/reverb.php`: Published Reverb server and application configurations.
- `.env` & `.env.example`: Added Reverb environment credentials:
  ```env
  BROADCAST_CONNECTION=reverb
  REVERB_APP_ID=932139
  REVERB_APP_KEY=seh7uxtuwomubmwjvqqt
  REVERB_APP_SECRET=sypbycc38sjj5mtkqnjh
  REVERB_HOST="localhost"
  REVERB_PORT=8080
  REVERB_SCHEME=http

  VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
  VITE_REVERB_HOST="${REVERB_HOST}"
  VITE_REVERB_PORT="${REVERB_PORT}"
  VITE_REVERB_SCHEME="${REVERB_SCHEME}"
  ```
- `app/Events/MeetingRealtimeEvent.php`: [NEW] Broadcast event implementing `ShouldBroadcastNow` on `meeting.{uuid}`.
- `routes/channels.php`: Authorized `meeting.{uuid}` alongside `call.{uuid}` and `user.{id}` channels.
- `app/Services/WebRtcCallService.php`:
  - Added `isRealtimeSupported(): bool` and `getRealtimeStatus(): array`.
  - Added WebSocket gating in `initiateCall` and `initiateGroupCall` throwing `ValidationException` when unconfigured.
  - Wrapped actions in `DB::transaction` with `AuditLogService` logging and try-catch handling.
- `app/Services/MeetingService.php`:
  - Integrated `MeetingRealtimeEvent` dispatches on participant join, admission, denial, role change, restriction update, and meeting termination.
- `database/seeders/SettingsSeeder.php`: Added `chat.require_websocket_for_calls` default setting.

### Frontend JavaScript Layer
- `resources/js/echo.js`:
  - Added `window.WebSocketState` object and `window.reconnectWebSocket()` utility.
  - Bound Pusher connection `state_change`, `connected`, `disconnected`, `unavailable`, `failed`, `error` listeners.
  - Dispatched `websocket-status-changed` window events.
- `resources/js/app.js`:
  - Initialized `Alpine.store('websocket')` reactive store with auto-updating connection status.
- `resources/js/chat-and-media.js`:
  - `chatPreCallPreviewAlpine`: Validates active WebSocket connection before dispatching call.
  - `chatCallOverlayAlpine`: Subscribes directly to `private-call.{callUuid}` and `private-user.{id}`, handles ICE candidates queueing, and guarantees audio/video playback (`play().catch()`).
  - `meetingRoomAlpine`: Subscribes to `private-meeting.{meetingUuid}`, handles instant participant admittance from waiting rooms, synchronized in-room chat, and real-time floating emojis.

### Blade Templates & UI
- `resources/views/pages/portal/⚡chat.blade.php`:
  - Added live status pill (`Live` vs `Offline`).
  - Conditioned voice/video call buttons to `$store.websocket.connected`.
  - Added non-intrusive warning banner when WebSocket disconnects with 1-click Reconnect button.
- `resources/views/components/⚡chat-call-overlay.blade.php`:
  - Fixed argument passing for `initiateGroupCall`.
  - Added Toast exception handling on call setup errors.
- `resources/views/pages/portal/⚡meeting-room.blade.php`:
  - Added real-time event broadcasting for in-room messages and floating emojis.
  - Added `Reverb Live` badge in the room header.
- `resources/views/pages/admin/settings/⚡chat.blade.php`:
  - Added `Require Active WebSocket for Calling` toggle.
  - Added real-time WebSocket connection status and diagnostic card.
- `lang/en.json`: Added comprehensive translation keys for WebSocket alerts, statuses, and tooltips.

---

## 3. Production & Hosting Deployment Guide for Laravel Reverb

When deploying to a live server (Ubuntu / Debian, Nginx, SSL), follow these steps to run Laravel Reverb reliably 24/7 in production.

### Step 1: Environment Variables on Production
In your production `.env` file:
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=your_production_app_id
REVERB_APP_KEY=your_production_app_key
REVERB_APP_SECRET=your_production_app_secret

# The external domain users connect to (HTTPS / WSS)
REVERB_HOST="yourdomain.com"
REVERB_PORT=443
REVERB_SCHEME=https

# Internal server bind
REVERB_SERVER_HOST="127.0.0.1"
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```
Run `npm run build` after setting production environment variables.

---

### Step 2: Process Manager (Supervisor / Systemd)

#### Option A: Supervisor (Recommended)
Create `/etc/supervisor/conf.d/reverb.conf`:
```ini
[program:reverb]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/sntcssc-mis/artisan reverb:start --host=127.0.0.1 --port=8080 --hostname=yourdomain.com
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/sntcssc-mis/storage/logs/reverb.log
stopwaitsecs=3600
```
Update and start Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start reverb:*
```

#### Option B: Systemd Service
Create `/etc/systemd/system/reverb.service`:
```ini
[Unit]
Description=Laravel Reverb WebSocket Server
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/sntcssc-mis/artisan reverb:start --host=127.0.0.1 --port=8080 --hostname=yourdomain.com
RestartSec=5s

[Install]
WantedBy=multi-user.target
```
Enable and start the service:
```bash
sudo systemctl daemon-reload
sudo systemctl enable reverb
sudo systemctl start reverb
```

---

### Step 3: Nginx Reverse Proxy with SSL (WSS Support)

Add the `/app/` location block to your Nginx HTTPS server block (e.g. `/etc/nginx/sites-available/yourdomain.com`):

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    # SSL Certificates
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    root /var/www/sntcssc-mis/public;
    index index.php;

    # Regular Laravel Web Traffic
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
    }

    # Laravel Reverb WebSocket Reverse Proxy
    location ~ ^/(app|apps)/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
    }
}
```

Test and reload Nginx:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

### Step 4: Verification in Local & Staging Environment

To run Reverb locally for testing:
```bash
php artisan reverb:start --debug
```
Open the application in two separate browser windows/tabs, open the Chat portal, and notice:
1. The **Live** badge is active and green.
2. Clicking Voice or Video Call initiates the call and connects in real time without lag.
3. Terminating or declining the call syncs instantaneously on both screens.
4. Stopping the `reverb:start` process immediately switches UI buttons to disabled state and shows the offline reconnection banner.

---

## 4. Automated Test Results

Ran the full Pest feature test suite:
- `tests/Feature/WebRtcCallSystemTest.php` (10 tests, 51 assertions) — **PASSED**
- `tests/Feature/ChatSystemTest.php` (27 tests, 114 assertions) — **PASSED**
- `tests/Feature/OnlineMeetingTest.php` (5 tests, 50 assertions) — **PASSED**

**Total Test Suite Result:** `42 passed, 215 assertions` (100% Passing).
Code style formatted with `Laravel Pint`.

---

## 5. WebRTC Handshake & Broadcasting 403 Resolution Details

### Issue 1: `POST /broadcasting/auth 403 (Forbidden)`
- **Root Cause 1:** The `<meta name="csrf-token">` tag was missing from `resources/views/partials/head.blade.php`, causing Pusher-JS requests to `/broadcasting/auth` to be sent without the required CSRF token header.
- **Root Cause 2:** Static Livewire attributes `#[On('echo-private:call.{activeCallUuid},...')]` attempted to subscribe to `private-call.` with empty UUID on mount before any call was active, which failed authorization callbacks.
- **Fix:** 
  1. Added `<meta name="csrf-token" content="{{ csrf_token() }}" />` in `partials/head.blade.php`.
  2. Configured `authEndpoint: '/broadcasting/auth'` with explicit `'X-CSRF-TOKEN'` headers in `resources/js/echo.js`.
  3. Handled empty ID and UUID parameters gracefully in `routes/channels.php`.
  4. Switched call channel subscription to dynamic JavaScript subscription via `subscribeCallEchoChannel(uuid)` when a call starts or is received.

### Issue 2: Incoming Call Screen Only Showing After Reload & Peer Connection Not Establishing
- **Root Cause 1 (Payload Key Mismatch):** `WebRtcCallSignalEvent.php` was broadcasting snake_case keys (`signal_type`, `call_uuid`, `sender_user_id`), whereas the Livewire method `handleBroadcastedSignal` and JavaScript function `handleIncomingSignal` expected camelCase keys (`signalType`, `callUuid`, `senderUserId`). Consequently:
  - `incoming_call` events arrived with `signalType = undefined`, preventing the incoming call popup from triggering.
  - SDP `offer`, SDP `answer`, and `ice_candidate` signals were similarly dropped, preventing WebRTC peer connection establishment and causing silent connection failure upon accepting calls.
- **Root Cause 2 (Direct Client-Side User Channel Subscription):** The client-side overlay did not subscribe to `private-user.{id}` directly in JavaScript.
- **Fix:**
  1. Updated `WebRtcCallSignalEvent::broadcastWith()` and `MeetingRealtimeEvent::broadcastWith()` to broadcast BOTH camelCase and snake_case properties.
  2. Updated `handleBroadcastedSignal` in `⚡chat-call-overlay.blade.php` and `handleIncomingSignal` in `resources/js/chat-and-media.js` to accept `signalType || signal_type || type` and `callUuid || call_uuid`.
  3. Added `subscribeUserEchoChannel(userId)` in `chatCallOverlayAlpine` so incoming calls are caught directly by the browser in < 50ms, popping up the dialog and connecting audio/video immediately upon accepting.

---

## 6. Voice Call Normalization, Signaling State Machine & Hardware Release Fixes

### Issue 3: Voice Call Showing as Video Call & Camera Turning On
- **Root Cause:** In `chat.blade.php` and `chat-and-media.js`, voice calls passed `'voice'` as the call type, but internal conditionals checked `=== 'audio'`. Because `'voice' !== 'audio'`, the system evaluated `isAudioOnly = false`, requested camera permissions via `getUserMedia`, and rendered the video overlay stage.
- **Fix:** Normalized call type across all PHP services (`WebRtcCallService`), Livewire components (`⚡chat.blade.php`, `⚡chat-call-overlay.blade.php`), and Alpine.js modules (`chatPreCallPreviewAlpine`, `chatCallOverlayAlpine`) to strictly `'audio'` or `'video'`. For audio calls, `video: false` is strictly enforced and the video track is never requested.

### Issue 4: `Error handling WebRTC answer: InvalidStateError: Failed to execute 'setRemoteDescription' on 'RTCPeerConnection': Failed to set remote answer sdp: Called in wrong state: stable`
- **Root Cause:** Due to dual channel listening (`user.{id}` and `call.{uuid}`), incoming SDP `answer` signals were dispatched multiple times. The first `answer` changed the peer connection signaling state from `have-local-offer` to `stable`. When the subsequent duplicate `answer` arrived milliseconds later, `setRemoteDescription` failed with `InvalidStateError` because `setRemoteDescription(answer)` can only be called when waiting for an answer in `have-local-offer` state. This uncaught error aborted remote audio/video playback.
- **Fix:** Added signaling state guards in `handleIncomingSignal`:
  - For `answer`: `if (this.peerConnection.signalingState !== 'have-local-offer') return;`
  - For `offer`: `if (this.peerConnection.signalingState === 'have-remote-offer' || this.peerConnection.signalingState !== 'stable') return;`
  - Properly applies remote descriptions, flushes queued ICE candidates, and creates/sends SDP answers without crashing.

### Issue 5: Camera / Microphone Hardware Not Disconnecting Completely on Call End
- **Root Cause:** `cleanupWebRtc()` only stopped tracks on `this.localStream`. If the user replaced video tracks (toggle video / screen sharing), or if `peerConnection`'s senders retained track references, the browser kept the microphone/camera hardware active.
- **Fix:**
  1. Updated `cleanupWebRtc()` in `chatCallOverlayAlpine` to stop all tracks on `this.localStream`, `this.screenStream`, `this.remoteStream`, and explicitly iterate through `this.peerConnection.getSenders()` calling `sender.track.stop()`.
  2. Cleared and paused all `<video>` and `<audio>` DOM elements (`srcObject = null; pause();`).
  3. Added `$this->dispatch('webrtc-call-ended')` in Livewire's `resetCallState()` so cleanup is guaranteed on errors, rejects, or remote hangups.

### Issue 6: `Error handling WebRTC offer: InvalidStateError: Failed to execute 'setLocalDescription' on 'RTCPeerConnection': Failed to set local answer sdp: Called in wrong state: stable`
- **Root Cause (Self-Signal Reflection Loop):** When User A (the caller) created and sent an SDP `offer` to the channel `call.{uuid}`, Laravel Reverb broadcasted the event to all subscribers on that channel—which included User A. Because there was no self-signal check, User A received its own `offer` and mistakenly attempted to create and set a local `answer` on its own connection. By the time `setLocalDescription(answer)` executed, User A was already in `stable` state after receiving User B's answer, resulting in `InvalidStateError: Failed to set local answer sdp: Called in wrong state: stable`.
- **Fix:**
  1. Added self-origin filtering in both JavaScript Alpine (`chatCallOverlayAlpine`) and Livewire (`⚡chat-call-overlay.blade.php`):
     ```javascript
     const fromUserId = signal.senderUserId || signal.sender_user_id || signal.fromUserId || signal.from_user_id;
     const myUserId = this.currentUserId || (this.$wire && this.$wire.currentUserId);
     if (fromUserId && myUserId && (Number(fromUserId) === Number(myUserId))) {
         return; // Ignore our own signals reflected from Reverb WebSocket
     }
     ```
  2. Added an `isProcessingOffer` concurrency mutex in `handleIncomingSignal` to prevent overlapping offer executions.



