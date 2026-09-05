# Enterprise WebRTC Live Chat Voice & Video Calling Upgrade & Online Meeting Hardware Suite

We have resolved the bugs in Live Chat Voice & Video calling, fixed direct call vs. group call detection, eliminated one-way audio/video transmission, implemented dynamic Voice <-> Video mode switching with SDP renegotiation, and brought the full hardware selection and preview suite to the Online Meeting platform.

---

## Key Achievements & Resolved Issues

### 1. Direct Calls vs. Group Calls Identification Fix
- **Problem**: Direct 1-on-1 calls initiated inside a conversation were incorrectly marked as group calls because `!empty($call->conversation_id)` was evaluated as `true`. This caused direct calls to be treated as group calls, breaking direct 1-on-1 signaling and showing "Group Call" in the UI.
- **Solution**:
  - Refactored [`ChatCall::isGroupCall()`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatCall.php#L145-L155) to accurately check whether `$this->signal_data['is_group']` is true or `$this->conversation->isGroup()` is true, and verify whether `receiver_id` is null.
  - Updated all signaling and overlay dispatchers in [`WebRtcCallService`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php) and [`⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php).

### 2. Bidirectional Audio/Video Media Track Transmission Fix
- **Problem**: When multiple tracks (audio & video) arrived incrementally in WebRTC `ontrack`, creating a new `MediaStream([event.track])` was overwriting earlier tracks, leading to one user not hearing or seeing the other.
- **Solution**:
  - Implemented safe track accumulation across 1-on-1 and group calls in `resources/js/chat-and-media.js`: if a remote stream already exists, incoming tracks are appended via `addTrack()` without replacing the stream or breaking the audio sinks.
  - Added targeted recipient routing for direct answer signals (`sendDirectSignal('answer', ..., payload.peerUserId)`).

### 3. Voice <-> Video Mode Switching with Dynamic SDP Renegotiation
- Implemented mutual consent workflow with `switch_mode_request` and `switch_mode_response` signals.
- Implemented dynamic WebRTC SDP renegotiation: when upgrading to Video, a new video track is acquired, attached to `localStream`, replaced on WebRTC senders via `replaceTrack()`, and a renegotiation `createOffer({ offerToReceiveVideo: true, offerToReceiveAudio: true })` / `createAnswer()` exchange is performed with peers.
- Added a 20-second auto-dismiss timeout for mode switch requests.

### 4. Online Meeting Platform Hardware Selection & Control Suite
- **Pre-Join Lobby**:
  - Added live Microphone, Camera, and Speaker device dropdown selectors.
  - Added **Speaker Test Chime** with Web Audio API synthesis.
  - Added **Camera Mirror Toggle** (`-scale-x-100` transform).
- **In-Meeting Controls**:
  - Added in-meeting **Audio & Video Device Settings Modal** in [`⚡meeting-room.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1meeting-room.blade.php) and `meetingRoomAlpine`.
  - Added live device switching (`switchCameraDevice`, `switchMicrophoneDevice`, `switchAudioOutputDevice`) that replaces sender tracks in real-time across all connected mesh peers.

### 5. Enterprise Soft Deletes, DB Transactions & Audit Logging
- Added soft deletes to `chat_call_participants` (`2026_09_01_210500_add_soft_deletes_to_chat_call_participants_table.php`).
- Wrapped all calling operations in `DB::transaction()`.
- Logged all call lifecycle events via `AuditLogService` (`webrtc_call_initiated`, `webrtc_call_accepted`, `webrtc_mode_switched`, `webrtc_participant_invited`, etc.).

### 6. Full Multi-Language Localization
- Added translations in English (`lang/en.json`), Hindi (`lang/hi.json`), and Bengali (`lang/bn.json`).

---

## Verification & Validation Results

### Automated Tests
- Ran Pest test suite:
  ```bash
  php artisan test --compact --filter=WebRtcCallSystemTest
  ```
  **Result**: `14 passed (79 assertions)`

  ```bash
  php artisan test --compact --filter=ChatSystemTest
  ```
  **Result**: `22 passed (133 assertions)`

### Code Style & Asset Compilation
- Formatted PHP code with Laravel Pint:
  ```bash
  vendor/bin/pint --format agent
  ```
  **Result**: Clean
- Compiled frontend assets with Vite:
  ```bash
  npm.cmd run build
  ```
  **Result**: Built in 1.62s with all assets transformed.

---

## Changelog
A technical changelog has been saved to:
[`changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-redesign-and-mode-switch-fix.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-redesign-and-mode-switch-fix.md)
