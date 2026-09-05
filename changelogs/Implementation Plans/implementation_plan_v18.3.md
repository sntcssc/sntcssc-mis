# Implementation Plan - WebRTC Call Flow & Online Meeting Mesh Video/Audio Engine

Implement the required fixes and features across WebRTC 1-on-1 calling and online meeting rooms:
1. **Fix Call Overlay & Prevent Auto-Answer**:
   - Ensure User A displays the outgoing calling overlay upon initiating a call (`callStatus = 'outgoing'`).
   - Prevent User B from auto-answering incoming calls; queue the SDP offer and only negotiate/answer when User B explicitly clicks "Accept".
2. **Live Microphone Waveform / Volume Meters**:
   - Add real-time audio volume meters (`AudioContext` + `AnalyserNode`) on 1-on-1 call screen (local & remote audio) and online meeting tiles.
3. **Full-Mesh WebRTC Video & Audio in Online Meetings**:
   - Build multi-peer WebRTC mesh engine in `meetingRoomAlpine` with automatic SDP offer/answer exchange, ICE candidate routing, and remote video/audio track bindings.
4. **Participant Join / Leave Notifications with Full Name**:
   - Broadcast and render in-room notifications and toasts when any participant joins or leaves the meeting room.
5. **Active Speaker Identification**:
   - Compute per-participant audio levels in real time to visually highlight the speaking user with an active glowing ring, animated waveform, and "Speaking..." badge.

## Proposed Changes

### 1. WebRTC Calling Engine & Overlay (`resources/js/chat-and-media.js`)

#### [MODIFY] [chat-and-media.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- **1-on-1 Call Overlay Flow (`chatCallOverlayAlpine`)**:
  - In `webrtc-call-started`: Explicitly set `this.callStatus = 'outgoing'`.
  - In `handleIncomingSignal`: When receiving an `offer` while in `callStatus === 'incoming'`, store `this.pendingOffer = payload.sdp` without auto-answering.
  - In `webrtc-call-accepted` / `acceptCall`: Initialize WebRTC, process `this.pendingOffer`, create and send SDP answer, and transition to `callStatus = 'connected'`.
  - Add `setupLocalAudioMeter(stream)` and `setupRemoteAudioMeter(stream)` to track `localAudioLevel` and `remoteAudioLevel` (0-100).
- **Meeting Room WebRTC Mesh Engine (`meetingRoomAlpine`)**:
  - Implement `peers = {}` managing peer connections (`RTCPeerConnection`), remote streams, audio analysers, and speaker state for all active meeting participants.
  - Handle `meeting_peer_join`, `meeting_signal` (offer, answer, ice_candidate, peer_state), and `participant_left`.
  - Add active speaker detection loop monitoring volume levels across all participant streams.
  - Add toast/banner announcements on `participant_joined` and `participant_left` containing full user names.

---

### 2. Chat Call Overlay Blade Component (`resources/views/components/⚡chat-call-overlay.blade.php`)

#### [MODIFY] [⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)
- Add live animated microphone waveform bars to the calling screen for both local user and remote peer.
- Highlight active speaker pulse during audio and video calls.

---

### 3. Online Meeting Room Blade Component (`resources/views/pages/portal/⚡meeting-room.blade.php`)

#### [MODIFY] [⚡meeting-room.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1meeting-room.blade.php)
- Replace static avatar placeholder tiles with dynamic WebRTC video elements (`<video>`) and avatar fallbacks for remote participants.
- Add live microphone volume meter / waveform bars to each participant's tile.
- Add active speaker glowing border (`ring-2 ring-emerald-500`) and "Speaking..." badge.
- Broadcast `participant_left` event in `executeLeaveMeeting()`.
- Render join/leave event toasts and notification banner.

---

## Verification Plan

### Automated Tests
- Run WebRTC call and meeting feature test suites:
  ```powershell
  php artisan test --compact tests/Feature/WebRtcCallSystemTest.php tests/Feature/OnlineMeetingTest.php
  ```

### Manual Verification
1. **1-on-1 Call Flow**:
   - User A calls User B -> User A sees outgoing call overlay with ringing animation.
   - User B sees incoming dialog and ringtone; call does NOT connect until User B clicks "Accept".
   - Upon clicking "Accept", connection establishes, and audio waveforms show voice activity on both screens.
2. **Online Meeting Multi-User Video/Audio**:
   - Multiple users in the same meeting room transmit and view each other's live camera and audio feeds.
   - When a user speaks, their tile displays active speaker indicator and live waveform.
   - When a user joins or leaves, notification displays with full user name.
