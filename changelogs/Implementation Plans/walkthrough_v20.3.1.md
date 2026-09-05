# Enterprise WebRTC Calling & Online Meetings — Hardware Diagnostics & Optimization

We have implemented an end-to-end hardware resource pre-flight diagnostic system with busy device detection (`NotReadableError` / `TrackStartError`), multi-tier graceful fallbacks, dynamic device change reactivity, and instant resource cleanup across Live Chat Calling (1-on-1 & Group) and Online Meetings.

---

## 1. Problem Diagnosis & Architecture Requirements

1. **Hardware Resource Conflict & Busy Devices (`NotReadableError` / `TrackStartError`)**:
   - When a webcam or microphone was already in use by another app (e.g. Zoom, MS Teams, Skype, OBS, or another browser window), WebRTC media capture previously threw unhandled errors or stalled the calling interface without diagnosis.
2. **Missing Pre-Flight Device & Permission Verification**:
   - Calls and meetings initiated without verifying whether audio/video hardware physically existed or had permission granted.
3. **Absence of Graceful Fallback**:
   - If a camera was busy in another app during a video call or meeting, the call would fail or disconnect rather than smoothly falling back to voice/microphone mode while offering an option to re-engage the camera once released.
4. **Hardware Device Plugging/Unplugging Reactivity**:
   - Connecting or disconnecting USB webcams or headsets during an active call or meeting did not dynamically update device lists.

---

## 2. Solutions Implemented

### A. Universal Pre-Flight Hardware Diagnostics & Smart Fallback
- Added [`diagnoseHardwareMediaAvailability()`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js) to inspect `navigator.mediaDevices.enumerateDevices()` and permission status (`navigator.permissions.query`).
- Added [`acquireMediaStreamWithFallback()`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js) with multi-tier fallback:
  1. **Primary**: Requests ideal audio and video constraints.
  2. **Overconstrained Recovery**: Falls back to relaxed video constraints if specific resolutions fail.
  3. **Audio-Only Graceful Fallback**: If the webcam is locked by another app (`NotReadableError`, `TrackStartError`) or unavailable, the system automatically falls back to microphone audio, connects the call/meeting in voice mode, and flags `fallbackReason = 'camera_busy'`.
  4. **Detailed Error Categorization**: Distinguishes between `busy`, `permission_denied`, `not_found`, and `overconstrained`.

### B. Interactive Hardware Diagnostic Alert Banners
- **Live Chat Call Overlay** ([`⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)):
  - Added real-time floating status banner displaying when camera is busy in another app.
  - Included a **"Retry Camera"** action button that seamlessly re-acquires and re-attaches the camera track once released by other apps without dropping the call.
- **Online Meeting Room & Lobby** ([`⚡meeting-room.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1meeting-room.blade.php)):
  - Added hardware notice banners in both the pre-join lobby and the live meeting room.
  - Integrated `retryAcquireCamera()` to dynamically re-bind the video stream and notify all peer mesh connections in real time.

### C. Dynamic Device Change Reactivity (`devicechange`)
- Added `devicechange` event listeners on `navigator.mediaDevices` across `chatPreCallPreviewAlpine`, `chatCallOverlayAlpine`, and `meetingRoomAlpine` to automatically refresh available cameras, microphones, and speakers when devices are connected or disconnected.

### D. Strict Hardware Resource Teardown
- Guaranteed explicit `stopMediaTracks(stream)` execution on call termination, modal close, and page navigation (`livewire:navigating`, `beforeunload`, `pagehide`), immediately turning off the webcam LED and freeing the hardware for other applications.

---

## 3. Verification & Results

### Automated Feature Tests
- **WebRTC Call Feature Tests**:
  ```bash
  php artisan test --compact tests/Feature/WebRtcCallSystemTest.php
  # Result: 14 passed (79 assertions)
  ```
- **Live Chat Feature Tests**:
  ```bash
  php artisan test --compact tests/Feature/ChatSystemTest.php
  # Result: 22 passed (133 assertions)
  ```

### Code Style & Asset Compilation
- **Laravel Pint**: `vendor/bin/pint --format agent` — **Passed (0 issues)**.
- **Vite Bundle**: `npm.cmd run build` — **Compiled successfully in 1.06s**.
