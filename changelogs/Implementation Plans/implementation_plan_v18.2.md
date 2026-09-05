# Implementation Plan - WebRTC Calling Fixes (Mute/Camera Toggle, Hardware Release, Signaling State, Voice/Video Call Routing)

Fix critical WebRTC audio/video calling issues in the chat system:
1. Mute and camera on/off toggles during calls failing to halt audio/video transmission over `RTCPeerConnection`.
2. Camera and microphone hardware access remaining active after ending, rejecting, or hanging up a call instead of releasing hardware instantly.
3. Console `InvalidStateError: Failed to execute 'setRemoteDescription' on 'RTCPeerConnection': Failed to set remote answer sdp: Called in wrong state: stable`.
4. Voice call initiated by User A displaying as an incoming video call request on User B's screen.

## Proposed Changes

### 1. WebRTC Client Engine (`resources/js/chat-and-media.js`)

#### [MODIFY] [chat-and-media.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- **Track & Sender Mute/Video Synchronization**:
  - In `toggleMute()`: Update `sender.track.enabled = !this.isMuted` across all audio senders on `this.peerConnection` in addition to `localStream.getAudioTracks()`.
  - In `toggleVideo()`: When `isVideoOff` is enabled, stop local video tracks and execute `sender.replaceTrack(null)` on RTCRtpSenders so no video frames are transmitted over the wire. When turned back on, obtain new camera track and execute `sender.replaceTrack(newVidTrack)`.
  - In `initWebRtc()`: Strictly initialize audio sender tracks as disabled if `startMuted` is true and execute `replaceTrack(null)` if `startVideoOff` is true.
- **Immediate Hardware Resource Release**:
  - Enhance `stopMediaTracks()` to explicitly disable (`enabled = false`) and stop (`stop()`) all tracks.
  - In `cleanupWebRtc()`: Explicitly stop all sender tracks on `peerConnection`, close connection, stop all tracks on `localStream`, `screenStream`, and `remoteStream`, and clear all media element sources (`srcObject = null`).
- **Signaling State Guarding & `InvalidStateError` Suppression**:
  - In `handleIncomingSignal` for `'answer'`: Check `this.peerConnection.signalingState === 'have-local-offer'` before calling `setRemoteDescription`, and catch/suppress benign `InvalidStateError` from duplicate or concurrent answer dispatches.
  - In `handleIncomingSignal` for `'offer'`: Guard against invalid signaling state transitions and gracefully handle duplicate SDP offers.
- **Reactive State & Call Type Accuracy**:
  - Update `chatCallOverlayAlpine` to prioritize local reactive properties (`localCallType`, `localPeerName`, `localPeerAvatar`) over stale `$wire` initial defaults.
  - Correctly extract call type (`audio` vs `video`) across all signal handlers (`incoming_call`, `webrtc-call-started`, `webrtc-call-accepted`).

---

### 2. Chat Call Overlay Blade Component (`resources/views/components/⚡chat-call-overlay.blade.php`)

#### [MODIFY] [⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)
- **Dynamic Alpine UI Binding for Incoming Call Dialog**:
  - Replace static Blade PHP strings (`{{ ucfirst($callType) }}`) and static Blade icons (`:name="$callType === 'video' ? 'video' : 'phone-call'"`) with Alpine reactive directives (`x-show`, `x-text`).
  - Render dynamic icon and title based on Alpine's reactive `callType === 'audio' ? 'Incoming Voice Call…' : 'Incoming Video Call…'`.
  - Render dynamic Accept button icon (`x-show="callType === 'audio'"` for phone vs video).
- **Instant Client-Side Hardware Teardown**:
  - On the Decline and Hang Up buttons, add `@click="cleanupWebRtc(); $wire.hangUpCall()"` / `@click="cleanupWebRtc(); $wire.declineCall()"` so media hardware is freed instantly in 0ms without waiting for network/Livewire roundtrips.
- **Component State Consistency**:
  - Ensure `acceptCall()` in the Livewire component updates `$this->callType = $call->type`.

---

### 3. Portal Chat Page (`resources/views/pages/portal/⚡chat.blade.php`)

#### [MODIFY] [⚡chat.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1chat.blade.php)
- **Eliminate Double-Dispatch on Call Launch**:
  - Remove redundant `wire:click="promptCall(...)"` from Voice Call and Video Call header buttons to prevent race conditions and duplicate event dispatches when Alpine's `@click` triggers the pre-call preview with verified `callType`.

---

## Verification Plan

### Automated Tests
- Run existing and updated WebRTC test suites:
  ```powershell
  php artisan test --compact tests/Feature/WebRtcCallSystemTest.php
  ```

### Manual Verification Scenarios
1. **Voice Call vs Video Call Request**:
   - User A initiates voice call -> User B receives prompt titled "Incoming Voice Call…" with phone icon, and accept button shows phone icon.
   - User B accepts -> overlay displays audio-only stage with pulsing avatar and timer, without camera activation.
2. **Microphone & Camera Mute Toggles**:
   - In active call, clicking mute sets audio track `enabled = false` and disables RTCRtpSender. Remote peer receives no audio.
   - In video call, clicking camera toggle turns off video, replaces sender track with `null`, and remote peer sees "Camera is off" placeholder. Turning camera back on reacquires stream and restores video.
3. **Instant Hardware Release on Call Termination**:
   - Clicking End Call / Decline stops all tracks immediately; browser camera/mic recording indicators in the browser tab and OS taskbar disappear instantly.
4. **Console Cleanliness**:
   - Verify no `InvalidStateError: Failed to execute 'setRemoteDescription'` in browser console during answer/offer exchange.
