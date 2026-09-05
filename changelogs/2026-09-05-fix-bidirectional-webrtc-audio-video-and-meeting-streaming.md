# Comprehensive Fix: Bidirectional WebRTC Audio/Video Streaming, Voice Call Audibility, and Meeting Room Interaction

**Date**: September 05, 2026  
**Type**: Enterprise Bug Fix & Realtime WebRTC Transceiver Synchronization  
**Scope**: 
- `resources/js/chat-and-media.js`
- `resources/js/meeting.js`
- `resources/views/components/⚡chat-call-overlay.blade.php`
- `resources/views/pages/portal/⚡meeting-room.blade.php`
- `app/Services/WebRtcCallService.php`

---

## 1. Executive Summary & Problem Resolution

### Issues Resolved:
1. **Issue 1 (Voice Calls - 1-on-1 & Group):**
   - **Symptom**: When making voice calls, no audio was audible between participants.
   - **Root Cause**: Audio transceiver sender tracks were unattached during answer SDP creation, and hidden `<audio>` sink elements were subject to browser autoplay restrictions and missing `onunmute` event triggers when remote RTP packets arrived.
   - **Fix**: Upgraded `ensureAudioSink` with automatic `onunmute` audio element play triggers, active volume/unmuted states, and global user-gesture listeners (`window._pendingChatAudioSinks` and `window._pendingAudioSinks`). Ensured `audioTransceiver.direction = 'sendrecv'` and `await audioTransceiver.sender.replaceTrack(audioTrack)` prior to creating SDP offers and answers.

2. **Issue 2 (Video Calls - Callee Video Missing for Caller):**
   - **Symptom**: When User A called User B, User B could see User A and hear sound, but User A could not see User B's video (although User A could hear User B).
   - **Root Cause**: `attachTracksToPeerConnection(pc, stream)` invoked `videoTransceiver.sender.replaceTrack(...)` asynchronously without `await`. When User B accepted the call, `createAnswer()` executed immediately before the video track was replaced on the sender. The browser generated an SDP answer with video media-line direction `a=recvonly` (or `a=inactive`) instead of `a=sendrecv`. Consequently, User B received User A's video, but never transmitted User B's video back to User A.
   - **Fix**: Made `attachTracksToPeerConnection(pc, stream)` `async` and strictly awaited `audioTransceiver.sender.replaceTrack(...)` and `videoTransceiver.sender.replaceTrack(...)` before calling `createOffer()` or `createAnswer()` across all offer/answer handlers (`webrtc-call-accepted`, `initWebRtc`, `handleIncomingSignal`, `initiateGroupPeerOffer`, and `executeCallModeSwitch`).

3. **Issue 3 (Online Meeting Room Mutual Audio & Video Interaction):**
   - **Symptom**: When User A and User B joined the meeting room, neither could hear or see each other.
   - **Root Cause**: 
     1. Un-awaited transceiver track replacements during offer and answer creation created asymmetrical or inactive SDP media sections.
     2. In `meeting.js`, `isPeerVideoOff` returned `true` when the initial RTP audio track arrived (transceiver index 0) because `stream.getVideoTracks().length` was initially 0, hiding the video tag before the video track arrived.
   - **Fix**: Made `attachTracksToPeerConnection` `async` and awaited in `initiatePeerConnection`, `handlePeerOffer`, and `startMedia`. Enhanced `isPeerVideoOff` and `ontrack` to dynamically bind remote video elements with `playsInline = true` and `muted = true` upon `event.track.onunmute`, and ensure audio sinks playback through `ensureMeetingAudioSink`.

---

## 2. Technical Code Changes

### A. Asynchronous Transceiver Track Binding (`chat-and-media.js` & `meeting.js`)
- `attachTracksToPeerConnection(pc, stream)` is now `async` and ensures all senders complete `replaceTrack` before SDP offer/answer generation:
  - Audio sender `replaceTrack` is awaited with `direction = 'sendrecv'`.
  - Video sender `replaceTrack` is awaited with `direction = 'sendrecv'` (when active) or `'inactive'` (when audio-only).
- Awaited before `createOffer()` and `createAnswer()` in:
  - `webrtc-call-accepted`
  - `initWebRtc`
  - `handleIncomingSignal` (`call_accepted`, `peer_presence`, and `offer`)
  - `initiateGroupPeerOffer`
  - `toggleMute` / `toggleVideo` / `retryAcquireCamera`
  - `executeCallModeSwitch`
  - Meeting room: `initiatePeerConnection`, `handlePeerOffer`, and `startMedia`.

### B. Global Autoplay Gesture Unlocking & Dedicated Audio Sinks
- Connected incoming media tracks trigger `track.onunmute` which immediately invokes `audioEl.play()`.
- Added global user gesture listeners (`click`, `touchstart`, `keydown`, `pointerdown`) to automatically resume any pending audio sinks if blocked by browser policy prior to user interaction.

---

## 3. Verification & Testing

- **Pest Unit & Feature Test Results**:
  - `php artisan test --compact --filter=Meeting`: **24 passed / 24 total** (89 assertions)
  - `php artisan test --compact --filter=WebRtc`: **18 passed / 18 total** (97 assertions)
- **Frontend Asset Compilation**:
  - `npm run build` executed cleanly with 0 errors (Vite production bundle generated).
- **Code Standards**:
  - `vendor/bin/pint --dirty --format agent` verified code styling.
