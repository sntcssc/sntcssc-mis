# Implementation Plan - Fix Online Meeting Realtime Reactions, Voice/Video Interaction & Device Controls

## Overview & Background

The Online Meeting platform currently has three critical real-time defects reported:
1. **Reaction Visibility**: When a participant sends a floating emoji reaction, it is not consistently visible to other joined participants.
2. **Real-time Voice & Video Interaction**: Joined users cannot reliably interact via voice or video in real-time due to signaling bottlenecks, missing peer synchronization on initial join, channel authorization bugs, and premature track muting.
3. **Hardware & Device Controls (Camera, Mic, Screen Share)**:
   - When a user turns off their camera, other users still see the last frozen frame because `peer_state` reactivity is lost and the HTML5 `<video>` element is not properly hidden/cleared.
   - Screen sharing only updates the local preview element and never replaces the WebRTC video transceiver track sent to remote peers.
   - Microphone mute/unmute does not reliably update transceiver tracks or remote audio sink states.

---

## Root Cause Analysis

1. **Reactions**:
   - In `routes/channels.php` line 79, `meeting.{uuid}` calls `$meeting->isOpenAccess()`, which threw `BadMethodCallException: Call to undefined method App\Models\ChatMeeting::isOpenAccess()` (the actual method is `isOpenForEveryone()`), causing `/broadcasting/auth` to crash with HTTP 500 for non-pre-existing participants.
   - Livewire's `sendReaction()` pushed to `$this->activeFloatingReactions` in component memory, but never rendered it in the Blade template nor shared it via Cache, meaning polling users never received reactions.
   - Reactions were only transmitted via Livewire round-trip without immediate local optimistic rendering or peer signal fallback.

2. **Real-time Interaction (WebRTC Mesh Signaling & Audio/Video)**:
   - When a user enters a meeting already joined (`STATUS_JOINED` e.g., Host or page reload), `apply-prejoin-settings` never fires, so `init()` never called `syncActivePeers()` or `sendMeetingSignal('peer_join')`.
   - Every single WebRTC signal (`offer`, `answer`, and 15–30 individual `ice_candidate`s per peer) was sent via Livewire `$wire.sendMeetingSignal(...)` network requests, choking the Livewire component and causing massive candidate delivery delays, dropped connections, or checksum conflicts.
   - `pc.ontrack` had `event.track.onmute = () => { peer.isVideoOff = true; bindRemoteVideo(id); }`. In standard WebRTC, `onmute` triggers on temporary RTP packet silence / jitter during initial negotiation, prematurely killing the video stream before frames render.

3. **Device Controls (Camera Off, Mic Mute, Screen Share)**:
   - **Camera Off**: `stopVideoCompletely()` called `replaceTrack(null)`, cutting RTP packets, but the receiver's Alpine template did not reactively toggle `:class="isPeerVideoOff(id) ? 'hidden' : 'block'"` due to shallow object mutation. In HTML5 `<video>`, setting `srcObject = null` or pausing leaves the last rendered frame painted on screen.
   - **Screen Share**: `toggleScreenShare()` captured `getDisplayMedia()`, but never iterated over `this.peers` to call `replaceTrack(screenTrack)`! Remote peers never received screen share frames. When stopping screen share, it never restored the camera track.
   - **Microphone**: When resuming audio, newly created tracks were never attached via `replaceTrack` to existing peer senders.

---

## Proposed Changes

### Component 1: Broadcasting & Signaling Architecture

#### [MODIFY] [routes/channels.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/channels.php)
- Fix `$meeting->isOpenAccess()` to `$meeting->isOpenForEveryone()`.
- Add channel authorization for `meeting.{uuid}` supporting Host, joined participants, and open-access meetings.

#### [MODIFY] [app/Models/ChatMeeting.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatMeeting.php)
- Add alias method `isOpenAccess(): bool` returning `$this->isOpenForEveryone()` to prevent any future naming regressions.

#### [MODIFY] [app/Services/MeetingService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/MeetingService.php)
- Add `sendSignal(string $meetingUuid, User $sender, string $signalType, array $payload = [], ?int $targetUserId = null): bool` to handle signaling, candidate batching, and broadcasting through `MeetingRealtimeEvent`.
- Add reaction storage in Cache with short TTL (e.g. 15 seconds) so both WebSocket broadcast and polling fallback can retrieve recent reactions.

#### [MODIFY] [routes/web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register high-speed, lightweight endpoint `POST /meetings/{uuid}/signal` handled by a dedicated controller/method (`MeetingSignalController` or `MeetingService`) to bypass Livewire overhead for ICE candidates and SDP negotiation.

#### [NEW] [app/Http/Controllers/MeetingSignalController.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/MeetingSignalController.php)
- Validates user belongs to the meeting.
- Dispatches signal via `MeetingService` in sub-10ms without booting Livewire component state.

---

### Component 2: Client WebRTC & Realtime Engine (`resources/js/meeting.js`)

#### [MODIFY] [resources/js/meeting.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/meeting.js)
1. **Initial Peer Discovery**:
   - Ensure `init()` immediately initiates peer synchronization `syncActivePeers()` if the user is already in the meeting room (`!inPreJoinLobby`), rather than waiting for `apply-prejoin-settings`.
   - Add periodic peer health check (every 3 seconds) that verifies peer connections and auto-renegotiates if disconnected.
2. **Fast Signaling & Candidate Batching**:
   - Update `sendMeetingSignal()` to use `fetch('/meetings/' + this.meetingUuid + '/signal')` with CSRF header, falling back to `$wire.sendMeetingSignal()`.
   - Batch ICE candidates: queue candidates gathered within 40ms into an array `{ candidates: [...] }` to drastically reduce HTTP traffic from ~30 calls down to 1–2 calls.
3. **Screen Sharing Fix**:
   - In `toggleScreenShare()`:
     - On start: Iterate through all active peer connections (`this.peers`) and replace the video sender track with `screenTrack`.
     - On stop (or when `screenTrack.onended` fires): Re-acquire or re-attach the local camera video track to all peer senders via `replaceTrack()`.
     - Broadcast `peer_state` indicating screen share status.
4. **Camera Off / Video Toggle Fix**:
   - When turning camera off: Set `track.enabled = false` on local stream and peer senders (sending black frames rather than crashing transceivers with `replaceTrack(null)`).
   - Maintain top-level reactive dictionary `peerStates: {}` (e.g., `this.peerStates[userId] = { isVideoOff: true, isMuted: false }`) and update it on `peer_state` signals.
   - In `bindRemoteVideo(userId)`: When `isVideoOff` is true, immediately pause the element, set `el.srcObject = null`, load a blank state, and ensure the video element is visually hidden so no frozen frame is displayed.
5. **Reactions**:
   - Optimistically display floating reaction immediately on the sender's client.
   - Broadcast `floating_emoji` via high-speed signaling so all connected peers receive it immediately.
   - Listen for `floating_emoji` in `handleMeetingRealtime()` and invoke `addFloatingEmoji()`.

---

### Component 3: Meeting Room Livewire Component & Blade UI

#### [MODIFY] [resources/views/pages/portal/⚡meeting-room.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1meeting-room.blade.php)
1. **Reactions Synchronization**:
   - Update `sendReaction()` to store the reaction in Cache (via `MeetingService`) and broadcast `MeetingRealtimeEvent`.
   - In `refreshRoom()`: Fetch recent reactions from Cache that occurred within the last poll window and dispatch `trigger-floating-emoji` to Alpine so polling users also see reactions.
   - On the emoji button: Use `@click="addFloatingEmoji('{{ $rEmoji }}', '{{ $currentUser->name }}')"` for instant local feedback alongside sending to server.
2. **Video & Avatar Display**:
   - Bind video element visibility and avatar display directly to `isPeerVideoOff(userId)` with explicit Alpine state reactivity.
   - Ensure the `<video>` element has `:class="isPeerVideoOff(...) ? 'hidden' : 'block'"` and the avatar container has `x-show="isPeerVideoOff(...)"`.
   - Include visual badges for screen sharing and microphone mute states.

---

## Verification Plan

### Automated Tests
- Run existing feature test suite:
  ```bash
  php artisan test --compact --filter=MeetingDecouplingAndBroadcastingTest
  php artisan test --compact --filter=OnlineMeetingTest
  ```
- Write new feature test `tests/Feature/OnlineMeetingRealtimeAndControlsTest.php`:
  - Test `routes/channels.php` channel authorization for hosts, invited participants, and open-access users (verifying `isOpenForEveryone`).
  - Test `POST /meetings/{uuid}/signal` route authorization, signal broadcasting, and candidate batching.
  - Test reaction sending and cache synchronization for polling clients.

### Asset Compilation & Formatting
- Format code with Laravel Pint:
  ```bash
  vendor/bin/pint --format agent
  ```
- Compile frontend assets:
  ```bash
  npm run build
  ```

### Manual Verification
- Test two simulated users joining the same meeting:
  1. User A sends reaction (e.g., 👏, ❤️) -> verify reaction floats up with sender name on both User A and User B's screens.
  2. Verify User A and User B connect audio/video tracks in real time.
  3. User A turns off camera -> verify User B immediately sees User A's avatar placeholder (no frozen video frame).
  4. User A mutes mic -> verify User B immediately sees the "Muted" badge and audio cuts.
  5. User A shares screen -> verify User B immediately sees User A's shared screen in real-time.
