# Walkthrough: Separation of 1-to-1 & Group Calling, Audio Sync & Global Audio Sinks

We have implemented a complete architectural separation between 1-to-1 direct calls and group mesh calls, added persistent global audio sinks across live chat calls and online meetings, and resolved all premature acceptance and background ringing issues.

---

## 1. Key Problem Solutions & Fixes

### 1.1 Separation of 1-to-1 Calling and Group Calling
- **Fixed Premature Answer Dispatch**: In [`chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js), 1-to-1 direct offers and group offers are now strictly partitioned.
  - In 1-to-1 calls, User B stores `pendingOffer = sdp` and **does not send an answer** until User B explicitly clicks **"Accept"**.
  - User A stays in `callStatus === 'outgoing'` with dial tone playing and timer at `00:00`.
  - When User B clicks **"Decline"**, User A receives `call_rejected`, immediately stops the dial tone, closes the modal, and resets to idle.
  - In group calls, mesh offers/answers are exchanged strictly between connected participants (`callStatus === 'connected'`).

### 1.2 Audio Transmission & Synchronization Fixes (Calls & Online Meetings)
- **Persistent Global Audio Sink Engine (`ensureAudioSink`)**: Added a dedicated audio manager in `document.body` that keeps remote audio streams playing continuously, preventing browsers from pausing audio when video tiles are hidden with `display: none` (`x-show` / `hidden`).
- **Applied to All Contexts**:
  1. **1-to-1 Direct Calls**: `direct-call-remote-audio` sink handles audio continuously for both audio and video modes.
  2. **Group Calls**: `group-peer-audio-{userId}` sinks handle multi-party audio mesh without video dependency.
  3. **Online Meetings (`meetingRoomAlpine`)**: `meeting-peer-audio-{userId}` sinks ensure meeting participants are heard even when cameras are turned off or spotlight changes.
- **Audio Optimization**: Configured `echoCancellation: true, noiseSuppression: true, autoGainControl: true` on `getUserMedia`.

### 1.3 Livewire Component Method Visibility Fix
- Set `public function resetCallState(): void` on [`⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php).

---

## 2. Verification & Automated Tests

- **Pest Automated Test Suite**: All 22 tests in `ChatSystemTest` passed (`php artisan test --compact --filter=ChatSystemTest`).
- **Laravel Pint Code Formatter**: Passed (`vendor/bin/pint --format agent`).
- **Vite Build**: Compiled production assets with `npm run build` (Vite v8.2.2 in 5.11s).

---

## 3. Documentation & Changelog

- Updated release changelog: [`changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md).
