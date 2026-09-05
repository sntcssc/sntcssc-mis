# Enterprise WebRTC Live Chat Voice & Video Calling Redesign & Mode Switching Architecture

**Date**: September 01, 2026  
**Type**: Enterprise Bugfix, UI/UX Redesign & WebRTC Protocol Architecture Upgrade  
**Scope**: `resources/js/chat-and-media.js`, `resources/views/components/⚡chat-call-overlay.blade.php`, `resources/views/pages/portal/⚡chat.blade.php`, `app/Services/WebRtcCallService.php`, `app/Models/ChatCall.php`, `app/Models/ChatCallParticipant.php`, `database/migrations/2026_09_01_210500_add_soft_deletes_to_chat_call_participants_table.php`, `lang/en.json`, `lang/hi.json`, `lang/bn.json`, `tests/Feature/WebRtcCallSystemTest.php`

---

## 1. Overview & Objectives

Addressed critical communication establishment issues, media stream access problems, broken Voice <-> Video mode switching, and outdated UI/UX in the Live Chat Voice and Video calling features:
1. **Dynamic SDP Renegotiation & Mode Switching Engine**: Replaced broken state toggling with full WebRTC dynamic renegotiation (`createOffer` with `offerToReceiveVideo: true`, transceiver track replacement, and mutual approval workflow).
2. **Hardware Access, Permissions & Live Device Switching**: Added robust device enumeration (`enumerateDevices`), microphone/camera/speaker selectors, audio output routing (`setSinkId`), speaker chime test, mirror flip toggle, and track cleanup to eliminate hardware locking.
3. **Resilient ICE Candidate Buffering & Signaling Synchronization**: Implemented candidate queueing to prevent candidate loss before remote descriptions are set.
4. **Modern UI/UX Redesign**: Redesigned both Pre-Call Preview and Active Calling Overlay with smooth glassmorphism, responsive 1-on-1 & multi-party video grids, audio visualizers, and a floating minimized Picture-in-Picture mode for uninterrupted portal multitasking.
5. **Soft Deletes, Database Transactions & Audit Logging**: Wrapped all calling operations in transactions, added soft-deleting to call participants, and integrated comprehensive `AuditLogService` logging.
6. **Full Localization**: Added multi-language translations in English (`en.json`), Hindi (`hi.json`), and Bengali (`bn.json`).

---

## 2. Detailed Technical Changes

### A. Database & Schema
- **Migration**: `2026_09_01_210500_add_soft_deletes_to_chat_call_participants_table.php` added `softDeletes()` column (`deleted_at`) to `chat_call_participants`.
- **`app/Models/ChatCallParticipant.php`**: Integrated `SoftDeletes`, `Auditable`, and helper status methods (`isJoined()`, `isRinging()`, `isDeclined()`, `isLeft()`).
- **`app/Models/ChatCall.php`**: Added `activeParticipants()` and `joinedParticipants()` relationship helpers, as well as helper status methods (`isConnected()`, `isRinging()`, `isInitiated()`, `isEnded()`, `isRejected()`, `isMissed()`, `isGroupCall()`).

### B. Backend WebRTC Service (`app/Services/WebRtcCallService.php`)
- Wrapped all mutations in atomic `DB::transaction()` blocks.
- Added `requestModeSwitch(string $callUuid, User $user, string $requestedType): ChatCall` and `respondModeSwitch(string $callUuid, User $user, string $requestedType, bool $accepted): ChatCall`.
- Added comprehensive audit trail logging across all actions:
  - `webrtc_call_initiated`
  - `webrtc_group_call_initiated`
  - `webrtc_call_accepted`
  - `webrtc_call_rejected`
  - `webrtc_call_ended`
  - `webrtc_call_left`
  - `webrtc_participant_invited`
  - `webrtc_mode_switch_requested`
  - `webrtc_mode_switched`
  - `webrtc_mode_switch_declined`

### C. WebRTC & Alpine.js Core Engine (`resources/js/chat-and-media.js`)
- **`chatPreCallPreviewAlpine`**:
  - Live hardware device discovery (`enumerateDevices`) for audio input, video input, and audio output.
  - Interactive device switching before call start.
  - Mirror flip toggle and speaker test chime via Web Audio API.
  - Multi-band smooth volume visualizer.
  - Clean stream release on transition to prevent hardware locking.
- **`chatCallOverlayAlpine` & `meetingRoomAlpine` Transceiver-Aware Track Attachment & m-lines Resolution**:
  - Implemented `attachTracksToPeerConnection(pc, stream)` which inspects `pc.getTransceivers()` and `pc.getSenders()`. When an incoming offer/answer has already created untracked transceivers (where `sender.track` is initially null), it uses `replaceTrack()` on the existing transceiver instead of calling `addTrack()`, which would create duplicate extra transceivers and corrupt the SDP `m-lines` order.
  - Enforced strict audio-first media track addition (`getAudioTracks()` followed by `getVideoTracks()`) and `replaceTrack()` across both group calling and online meeting room connections. This completely eliminates `InvalidAccessError: Failed to set local offer sdp: The order of m-lines in subsequent offer doesn't match order from previous offer/answer`.
  - Added signaling state guards: prevented initiating offers when already in `have-local-offer`, implemented automatic rollback (`setLocalDescription({type: 'rollback'})`) on polite peer collisions, and safely ignored duplicate / late `answer` signals if connection is already in `stable` state (preventing `InvalidStateError: Failed to set remote answer sdp: Called in wrong state: stable`).

### D. Modern UI/UX Redesign
- **`resources/views/components/⚡chat-call-overlay.blade.php`**:
  - Radar-pulsing incoming call modal.
  - High-definition active call stage with speaker spotlight & responsive multi-peer grid.
  - Floating minimized pill widget at bottom right of viewport.
  - Interactive toolbar with mic, camera, mode switcher, screen sharing, device settings, invite modal, and call termination.
  - In-call Device Settings modal & Mode Switch confirmation dialog.
  - Fixed direct call vs group call identification so 1-on-1 calls are correctly routed without room broadcasting collisions.
- **`resources/views/pages/portal/⚡chat.blade.php`**:
  - Redesigned pre-call preview modal with device dropdown selectors, live video mirror, speaker audio test, and responsive cards.
- **`resources/views/pages/portal/⚡meeting-room.blade.php` & `meetingRoomAlpine` (Online Meeting Platform Upgrade)**:
  - Added microphone, camera, and speaker hardware device enumeration and selectors to the Pre-Join Lobby.
  - Added Speaker Test Chime button with Web Audio API synthesis.
  - Added Camera Mirror Toggle button (`-scale-x-100` transform).
  - Added in-meeting Audio & Video Device Settings Modal with live preview, device switching, and audio output routing (`setSinkId`).
  - Fixed multi-track stream accumulation on `pc.ontrack` to prevent audio dropouts during peer renegotiation.

### E. Translations (`lang/`)
- Added comprehensive translation keys to `lang/en.json`, `lang/hi.json`, and `lang/bn.json` covering device names, mode switching dialogs, error notices, and call statuses.

---

## 3. Automated Verification & Testing

- **Pest Test Suite**: Ran `php artisan test --compact --filter=WebRtcCallSystemTest` (14/14 passed) and `php artisan test --compact --filter=ChatSystemTest` (22/22 passed).
- **Code Style**: Applied `vendor/bin/pint --format agent` to maintain Laravel standards.
- **Asset Compilation**: Executed `npm.cmd run build` to compile Vite assets (completed in 1.18s).
