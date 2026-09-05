# Walkthrough - WebRTC Calling Fixes

Resolved the four WebRTC calling issues:
1. Microphone mute and camera on/off toggles properly halt audio/video streaming over the peer connection.
2. Device microphone and camera hardware resources are immediately released upon ending, declining, or cutting a call.
3. Fixed the console error: `chat:294 Error handling WebRTC answer: InvalidStateError: Failed to execute 'setRemoteDescription' on 'RTCPeerConnection': Failed to set remote answer sdp: Called in wrong state: stable`.
4. Voice call requests initiated by User A now accurately display as voice calls on User B's screen without defaulting to video call.

---

## Changes Made

### 1. WebRTC Client Engine & Track Lifecycle
**File:** [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- **Mute & Camera Toggle Audio/Video Streaming Control**:
  - `toggleMute()`: Disables all local stream audio tracks (`track.enabled = !this.isMuted`) and disables RTCRtpSender audio tracks on `peerConnection.getSenders()`.
  - `toggleVideo()`: When turned off, stops and disables local video tracks, executes `sender.replaceTrack(null)` on video senders, and clears `localVideo.srcObject`. When turned on, requests new camera stream and executes `sender.replaceTrack(newVidTrack)`.
  - `initWebRtc()`: Syncs initial `startMuted` / `startVideoOff` directly onto RTCRtpSenders.
- **Immediate Hardware Resource Release**:
  - `stopMediaTracks()`: Disables (`track.enabled = false`) and stops (`track.stop()`) all media tracks across streams.
  - `cleanupWebRtc()`: Stops and clears tracks on `localStream`, `screenStream`, and `remoteStream`; stops all sender tracks on `peerConnection`; closes peer connection; pauses and resets `srcObject` for `localVideo`, `remoteVideo`, and `remoteAudio`.
- **Signaling State Guarding & `InvalidStateError` Resolution**:
  - `handleIncomingSignal`:
    - Guards `'answer'` signal processing by verifying `peerConnection.signalingState === 'have-local-offer'` before calling `setRemoteDescription`, ignoring duplicate answer signals.
    - Catches and gracefully ignores `InvalidStateError` during concurrent SDP exchanges.
    - Guards `'offer'` signal processing against invalid signaling states.
- **Reactive State Accuracy**:
  - Prioritizes `localCallType`, `localPeerName`, and `localPeerAvatar` in Alpine getters/setters so incoming voice calls correctly set and retain `'audio'`.

---

### 2. Chat Call Overlay Component
**File:** [`resources/views/components/⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)
- **Dynamic Alpine Directives for Incoming Call Modal**:
  - Replaced static Blade PHP strings with reactive Alpine expressions (`x-show="callType === 'audio'"`, `x-text="callType === 'audio' ? 'Incoming Voice Call…' : 'Incoming Video Call…'"`).
  - Configured Accept button to display phone icon for voice calls and video icon for video calls.
- **Zero-Latency Hardware Teardown**:
  - Added `@click="cleanupWebRtc(); $wire.declineCall()"` to the Decline button and `@click="cleanupWebRtc(); $wire.hangUpCall()"` to the Hang Up button so camera/mic hardware indicators turn off instantly in 0ms on click.
- **Component State**:
  - Updated `acceptCall()` in Livewire to sync `$this->callType = $call->type`.

---

### 3. Portal Chat Page
**File:** [`resources/views/pages/portal/⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1chat.blade.php)
- Removed redundant `wire:click="promptCall(...)"` from Voice and Video call buttons to prevent double preview dispatches and race conditions with Alpine's `@click`.

---

## Verification Results

### Automated Tests
```powershell
php artisan test --compact tests/Feature/WebRtcCallSystemTest.php
```
- **Result**: 11 passed (61 assertions) in 2.2s.

### Frontend Compilation
```powershell
npm.cmd run build
```
- **Result**: Vite built production bundle cleanly in 0.99s.

### Code Style
```powershell
vendor/bin/pint --dirty --format agent
```
- **Result**: Checked and formatted.
