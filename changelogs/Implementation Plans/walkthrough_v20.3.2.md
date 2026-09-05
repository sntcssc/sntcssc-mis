# WebRTC Voice & Video Calling — Real-Time Sound & Ringtone Sync Fix

We have investigated and resolved the issues preventing real-time audio/video transmission in direct voice calls, group calling, and the persistent ringtone on the call receiver's end after accepting a call.

---

## 1. Problem Diagnosis & Root Causes

1. **Pending ICE Candidate Loss on Call Acceptance**:
   - In `initWebRtc()`, `this.pendingIceCandidates` was being reinitialized to `[]` whenever `initWebRtc` ran.
   - When the receiver accepted a call, any ICE candidates already received from the caller during the ringing phase were discarded. Without these candidates, WebRTC peer connection ICE negotiation stalled in `checking` or `failed` state, blocking real-time media packets (audio & video) from transferring between peers.

2. **Track-Transceiver Rebinding & Answer Generation Race**:
   - In 1-on-1 calls and group calling, when the receiver processed the incoming SDP offer (`setRemoteDescription`), the local media stream tracks were not being explicitly re-attached to the transceiver senders before calling `createAnswer()`.
   - Transceiver direction for audio was falling back to `recvonly` instead of full duplex `sendrecv`, preventing microphone audio from transmitting.

3. **Persistent Ringtone After Call Acceptance**:
   - Web Audio API oscillators scheduled with `osc.stop(now + 1.5)` continued emitting tone bursts if `stopRingtone()` encountered active audio transitions or if subsequent Livewire poll/broadcast events triggered `playRingtone()` after the call state transitioned to `connected`.
   - Lack of an explicit `isRinging` state flag allowed delayed tone bursts to sound even after the call connected.

4. **Duplicate Audio Sink Playback Elements**:
   - Both in-template `<audio>` tags and centralized `ensureAudioSink()` elements attempted to bind and play the same `MediaStream` instance simultaneously, leading to browser audio track locking and autoplay policy blocks.

---

## 2. Solutions Implemented

### A. Preserved ICE Candidates & Robust Negotiation
- Updated [`initWebRtc()`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js) to preserve `pendingIceCandidates` instead of clearing them on initialization.
- In both [`webrtc-call-accepted`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js) and [`handleIncomingSignal()`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js), `attachTracksToPeerConnection()` is called immediately after `setRemoteDescription()` and before `createAnswer()`.
- Ensured deterministic transceivers (`ensureDeterministicTransceivers`) update `audioTransceiver.sender.replaceTrack()` and guarantee `audioTransceiver.direction = 'sendrecv'`.

### B. Immediate and Guarded Ringtone Teardown
- Added `isRinging: false` state variable to `chatCallOverlayAlpine`.
- Hardened `playRingtone()` to immediately return if `callStatus === 'connected' || callStatus === 'ended' || callStatus === 'idle'`.
- Enhanced `stopRingtone()` to synchronously flip `isRinging = false`, cancel scheduled gain ramps (`gain.cancelScheduledValues(0)`), close the Web Audio `AudioContext`, and clear all intervals.
- Guarded `incoming-call-received` and `handleUserBroadcastedSignal` to ignore incoming signals if the local user is already `connected`.

### C. Unified Single-Source Audio Sinks
- Cleaned up redundant in-template `<audio>` elements in [`⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php).
- Standardized all group and direct call audio output on dedicated, persistent audio sinks (`ensureAudioSink()`), guaranteeing conflict-free, unmuted playback with automatic user-gesture unblock listeners.

---

## 3. Verification & Results

### Automated Feature Tests
- **WebRTC Call System Tests**:
  ```bash
  php artisan test --compact tests/Feature/WebRtcCallSystemTest.php
  # Result: 14 passed (79 assertions)
  ```
- **Live Chat System Tests**:
  ```bash
  php artisan test --compact tests/Feature/ChatSystemTest.php
  # Result: 22 passed (133 assertions)
  ```

### Code Formatting & Asset Build
- **Laravel Pint**: `vendor/bin/pint --format agent` — **Passed (0 issues)**.
- **Vite Build**: `npm.cmd run build` — **Compiled successfully in 1.04s**.
