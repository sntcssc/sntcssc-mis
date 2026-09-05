# Implementation Plan - Enterprise Live Chat Voice & Video Calling Overhaul, WebRTC Mode Switching & Modern UI/UX Redesign

## 1. Overview & Objectives
This plan resolves all reported issues with Live Chat Voice and Video calling in `sntcssc-mis`:
1. **WebRTC Connection Establishment & Audio/Video Transmission**: Resolve ICE candidate dropping, race conditions during connection setup, media track synchronization, and audio autoplay policy blocks.
2. **Microphone & Camera Hardware Access & Permissions**: Handle device acquisition, device enumeration (switching cameras/mics/speakers), `devicechange` events, hardware locking/busy state handling, and clear permission troubleshooting.
3. **Voice <-> Video Call Mode Switching**: Fix the broken switch mechanism with proper two-way consent signaling, Dynamic SDP Renegotiation, transceiver management, and synchronized viewport updates.
4. **Modern UI/UX Redesign**:
   - **Pre-Call Preview Screen**: Modern glassmorphic design, device selector dropdowns (camera/mic/speaker), speaker testing, mirror toggle, live waveform volume meter, light/dark mode, fully responsive.
   - **Calling Overlay Screen**: Ringing modal, active 1-on-1 & multi-party responsive grid, speaker spotlight, picture-in-picture draggable preview, minimized floating widget for multitasking, live audio equalizer, in-call device switching, end/leave call actions.
5. **Enterprise Standards & Security**: SoftDeletes for call participants, DB transactions across all operations, complete audit logging (`AuditLogService`), authorization checks, localization (`lang/`), Pint styling, and Pest test suite.

---

## 2. Proposed Changes & Components

### Component 1: Database & Models (SoftDeletes, Auditing & State)
#### [NEW] [migration `database/migrations/2026_09_01_210500_add_soft_deletes_to_chat_call_participants_table.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_09_01_210500_add_soft_deletes_to_chat_call_participants_table.php)
- Adds `softDeletes()` column to `chat_call_participants` table with index.

#### [MODIFY] [`app/Models/ChatCallParticipant.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatCallParticipant.php)
- Add `use SoftDeletes;` and `use Auditable;`.
- Add helper methods `isJoined()`, `isRinging()`, `isDeclined()`, `isLeft()`.

#### [MODIFY] [`app/Models/ChatCall.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatCall.php)
- Add helper methods `isInitiated()`, `isConnected()`, `isRinging()`, `isEnded()`, `isMissed()`, `isRejected()`.

---

### Component 2: Backend Service & Event Layer
#### [MODIFY] [`app/Services/WebRtcCallService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php)
- Wrap all service operations in `DB::transaction()` with comprehensive `try/catch` and logging.
- Add `requestModeSwitch(string $callUuid, User $user, string $requestedType)`: dispatches `switch_mode_request` signal, logs audit event `webrtc_mode_switch_requested`.
- Add `respondModeSwitch(string $callUuid, User $user, string $requestedType, bool $accepted)`: on accept, updates `ChatCall->type`, broadcasts `switch_mode_response` & `call_mode_switched`, logs `webrtc_mode_switched`. On decline, broadcasts decline response.
- Comprehensive audit logging for all lifecycle events (`webrtc_call_initiated`, `webrtc_group_call_initiated`, `webrtc_call_accepted`, `webrtc_call_rejected`, `webrtc_call_ended`, `webrtc_call_left`, `webrtc_participant_invited`, `webrtc_mode_switched`).

#### [MODIFY] [`routes/channels.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/channels.php)
- Verify channel authorization for `call.{uuid}` and `user.{id}` to ensure strictly authorized access.

---

### Component 3: Livewire Call Overlay & Calling UI
#### [MODIFY] [`resources/views/components/⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php)
- Enhance Livewire component methods:
  - `startCall`, `startGroupCall`, `joinGroupCall`, `acceptCall`, `declineCall`, `hangUpCall`, `endCallForEveryone`, `inviteUser`, `requestSwitchCallMode`, `acceptSwitchMode`, `declineSwitchMode`, `sendSignalPayload`.
- Redesign complete Blade UI with production-grade Tailwind CSS (light & dark mode compatible):
  1. **Incoming Call Dialog**: Pulsing avatar with ripple animation, caller name, call type badge, responsive action buttons.
  2. **Active Call View**:
     - Modern glassmorphic container with dark/light adaptive styling.
     - Top Navigation: Caller/Group title, duration counter, participant count badge, Layout toggles (Grid / Speaker / PiP), Minimized toggle.
     - Video/Voice Stage:
       - 1-on-1: Remote HD video with draggable/dockable local PiP with voice border; in Audio mode: pulsing avatar with animated frequency bars.
       - Group Grid: Responsive multi-peer grid (1-6+ peers) with active speaker detection border, muted badges, camera off placeholders, and speaker spotlight mode.
     - Floating Minimized Mode: Compact draggable pill at bottom-right showing active timer, participant avatar, mute/camera status, and expand/hangup controls.
     - Bottom Control Toolbar:
       - Microphone toggle (with live volume meter bar)
       - Camera toggle (with smooth placeholder transition)
       - **Switch Call Mode (Voice <-> Video)** with interactive confirmation modal
       - Screen Sharing toggle
       - Invite Participants modal trigger
       - Device Settings (switch mic, camera, speaker live in call)
       - End / Leave call button with distinct actions for 1-on-1 vs group host vs participant.
  3. **Interactive Sub-Modals**:
     - Mode Switch Confirmation Modal (with 15s auto-dismiss countdown, accept & decline buttons).
     - Add / Invite Participant Modal (searchable user list with active call filters).
     - In-Call Device Settings Modal (live device selectors).

---

### Component 4: Pre-Call Device & Permissions Preview Screen
#### [MODIFY] [`resources/views/pages/portal/⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php)
- Redesign the Pre-Call Preview modal:
  - Video Mode: 16:9 mirrored preview, camera mirror toggle, device selectors (Microphone, Camera, Speaker Output with Test Chime), live voice waveform volume meter.
  - Audio Mode: Pure voice call layout with pulsing avatar, real-time waveform bars, mic/speaker selectors, and audio test button.
  - Clear permission status card with retry button and browser instructions.
  - Fully mobile responsive layout supporting light and dark mode.

---

### Component 5: WebRTC & Audio/Video Alpine.js Core Engine
#### [MODIFY] [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
1. **Device Enumeration & Hardware Management**:
   - Implement `enumerateDevices()` to populate `audioinput`, `audiooutput`, and `videoinput` devices.
   - Implement live device switching: `switchCamera(deviceId)`, `switchMicrophone(deviceId)`, `switchAudioOutput(deviceId, el)`.
   - Listen to `devicechange` events on `navigator.mediaDevices` to dynamically update connected devices.
   - Graceful hardware resource handoff between pre-call preview and active call without locking cameras/mics.
   - Multi-tier fallback constraints: 1080p -> 720p -> 480p -> 360p -> audio only.
2. **Signaling & WebRTC Connection Establishment**:
   - Resilient candidate queueing: Buffer incoming ICE candidates if `peerConnection` is not yet ready or `remoteDescription` is null, and drain immediately upon setting remote description.
   - Handle signaling glare and simultaneous offers in group calls.
   - Handle audio autoplay policies with graceful user interaction fallback if browser blocks playback.
3. **WebRTC Mode Switching (Voice <-> Video & Video <-> Voice)**:
   - Handle `switch_mode_request`, `switch_mode_response`, and `call_mode_switched` in JavaScript `handleIncomingSignal`.
   - Implement **SDP Dynamic Renegotiation**:
     - When switching to Video: Acquire video track, add to stream, add/replace track on peer connection senders, generate renegotiation offer (`createOffer`), exchange SDP answer with peer, and trigger remote video display.
     - When switching to Voice: Stop/disable video tracks, set video sender track to null, notify peer, and seamlessly transition viewport to audio equalizer.
4. **Audio Analyser & Real-time Waveforms**:
   - Safe Web Audio API context with multi-band frequency analyzer for smooth speaking level visualization and waveform animations.

---

### Component 6: Translations, Tests & Changelog
#### [MODIFY] [`lang/en.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json), [`lang/hi.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json), [`lang/bn.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json)
- Add all newly added translation strings.

#### [MODIFY] [`tests/Feature/WebRtcCallSystemTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/WebRtcCallSystemTest.php)
- Add comprehensive test cases covering:
  - Mode switch requests (Audio -> Video, Video -> Audio) and accept/reject flows.
  - Device switching and signal dispatching.
  - Soft deletes on `ChatCallParticipant`.
  - Audit log entries for WebRTC calling events.
  - Group calling invitation and mid-call participant joins.

#### [NEW] [`changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-redesign-and-mode-switch-fix.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-redesign-and-mode-switch-fix.md)
- Complete technical changelog of all fixes, architectural upgrades, and UI/UX redesign.

---

## 3. Verification Plan

### Automated Tests
1. Run migration: `php artisan migrate`
2. Run Pest tests:
   ```bash
   php artisan test --compact --filter=WebRtcCallSystemTest
   php artisan test --compact --filter=ChatSystemTest
   php artisan test --compact
   ```
3. Run Laravel Pint code formatter:
   ```bash
   vendor/bin/pint --format agent
   ```
4. Build frontend assets:
   ```bash
   npm run build
   ```

### Manual Verification Checklist
- [x] Test 1-on-1 voice and video calling establishment.
- [x] Test group calling establishment and mid-call invitations.
- [x] Test camera/microphone permissions, device selection, and error recovery.
- [x] Test Voice -> Video and Video -> Voice mode switching with mutual approval and SDP renegotiation.
- [x] Verify UI/UX in light and dark mode, desktop, and mobile viewports.
- [x] Verify minimized floating call pill multitasking.
- [x] Verify audit log records and soft delete handling.
