# Implementation Plan: Hardware Resource Diagnostics, Busy Device Detection & Graceful Fallback for Calls & Meetings

Ensure camera and microphone hardware availability, OS/app busy states (e.g., `NotReadableError` / `TrackStartError`), and permission pre-flight diagnostics are thoroughly checked and handled before and during live chat calls (direct & group) and online meetings.

---

## 1. Problem Overview & Scope

Users initiating or receiving audio/video calls in live chat or entering online meeting rooms currently lack pre-flight hardware availability checks. When a camera or microphone is locked or in use by another application (e.g., Zoom, MS Teams, Skype, OBS, or another browser tab), WebRTC calls or video streams can fail or freeze without clear diagnosis or graceful fallback.

### Key Goals:
1. **Pre-Flight Hardware Availability & Busy Checks**:
   - Universal media pre-flight checking before starting or accepting calls and joining meetings.
   - Comprehensive detection of:
     - **Device Busy / In Use by Another App** (`NotReadableError`, `TrackStartError`, `AbortError`).
     - **Permission Denied** (`NotAllowedError`, `PermissionDeniedError`).
     - **No Hardware Found** (`NotFoundError`, `DevicesNotFoundError`).
     - **Constraint/Resolution Failures** (`OverconstrainedError`).
2. **Multi-Tier Graceful Fallbacks**:
   - If camera is busy in another app during a video call or meeting: automatically fall back to voice mode with microphone, show a clear non-blocking alert banner (*"Camera in use by another app — joined with microphone. [Retry Camera]"*), and seamlessly re-attach video once released.
   - If microphone is busy or blocked: provide real-time diagnostic alerts with one-click retry.
3. **Dynamic Hardware & Device Change Reactivity**:
   - Listen to `navigator.mediaDevices.ondevicechange` to dynamically update input/output device lists and reconnect audio/video streams when USB headsets/webcams are plugged in or removed.
4. **Immediate Resource Release on Call/Meeting Termination**:
   - Ensure all media tracks are strictly stopped (`track.stop()`) on call termination, modal close, and page navigation (`livewire:navigating`, `beforeunload`, `pagehide`), turning off camera/mic hardware LEDs immediately.
5. **Localization & UI/UX Consistency**:
   - Add multilingual translation keys in `lang/en.json`, `lang/hi.json`, and `lang/bn.json` for all device status alerts, retry actions, and troubleshooting notices.

---

## 2. User Review Required

> [!IMPORTANT]
> - Automatic fallback ensures calls and meetings never fail outright: if a webcam is occupied by another app, the call proceeds with voice and an interactive prompt to retry camera acquisition at any time.
> - When user terminates or minimizes a call/meeting, hardware tracks are strictly terminated to free OS media devices for other apps.

---

## 3. Proposed Changes

### JavaScript Engine & WebRTC Layer
#### [MODIFY] [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- Implement `inspectMediaHardwareAvailability({ audio, video, selectedAudioInput, selectedVideoInput })` helper returning structured availability status (`{ available, audioAvailable, videoAvailable, errorType, errorMessage, isBusy }`).
- Implement `acquireMediaStreamWithFallback({ audio, video, audioConstraints, videoConstraints, onFallback })` with multi-tier fallback (Ideal constraints $\rightarrow$ Relaxed constraints $\rightarrow$ Audio-only fallback).
- Update `chatPreCallPreviewAlpine` to perform full device pre-flight diagnostics and show actionable error recovery.
- Update `chatCallOverlayAlpine` (direct and group calls) with device busy detection, audio fallback banner, and dynamic `devicechange` listener.
- Update `meetingRoomAlpine` with pre-flight device inspection, busy device detection, automatic audio fallback, and one-click camera recovery.
- Ensure strict `stopMediaTracks(stream)` execution on all cleanup / teardown paths.

---

### Blade & UI Components
#### [MODIFY] [`resources/views/components/⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)
- Add interactive Device Status Alert Banner in the call overlay header (e.g., displaying when camera is busy in another app or permissions are missing, with a "Retry Device" button).
- Ensure device selectors and speaker tests properly bind and update on `devicechange`.

#### [MODIFY] [`resources/views/pages/portal/⚡meeting-room.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1meeting-room.blade.php)
- Add floating hardware notice banner when camera/mic is busy or failing in meeting lobby/stage.
- Add "Retry Camera" action button when camera is falling back to audio.

---

### Localization
#### [MODIFY] [`lang/en.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json), [`lang/hi.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json), [`lang/bn.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json)
- Add localization strings for:
  - *"Camera is currently in use by another application. Connected with microphone only."*
  - *"Microphone is in use by another application. Please close other apps and retry."*
  - *"Check & Retry Device"*
  - *"Camera Reconnected"*
  - *"Hardware device plugged in / removed"*

---

## 4. Verification Plan

### Automated Tests
```bash
php artisan test --compact tests/Feature/WebRtcCallSystemTest.php
php artisan test --compact tests/Feature/ChatSystemTest.php
```

### Code Formatting & Build Verification
```bash
vendor/bin/pint --format agent
npm.cmd run build
```

### Manual / Browser Verification
- Verify pre-flight hardware inspection in pre-call preview modal and meeting lobby.
- Verify that when camera fails/busy, the call gracefully falls back to audio mode with a clear user notice.
- Verify that clicking "Retry Camera" successfully re-engages camera track once released by other apps.
- Verify that ending a call/meeting releases hardware devices immediately (webcam indicator turns off).
