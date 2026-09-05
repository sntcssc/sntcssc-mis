# Walkthrough: Dynamic Call Mode Switching, Mid-Call Invites & Rejection Notifications

We have implemented dynamic mode switching between audio/voice and video during active calls, added mid-call participant invitations with search modal, delivered informative rejection notifications, and resolved initial media hardware delays.

---

## 1. Key Features & Architectural Enhancements

### 1.1 In-Call Voice <-> Video Switching
- **Toolbar Switch Button**: Added a dedicated mode switch button in [`⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php).
  - When in **Audio Call**, clicking the green camera button upgrades the call to **Video Call**, activates camera streams, updates WebRTC senders with `replaceTrack`, and notifies peers via `call_mode_switched`.
  - When in **Video Call**, clicking the blue phone button downgrades to **Audio Call**, non-destructively pauses video tracks, switches UI to the audio waveform stage, and synchronizes with peers.

### 1.2 Mid-Call Participant Invites ("Add to Call")
- **Invite Modal with Search**: Added an "Invite / Add Users" button on the call toolbar that opens a responsive modal with live search.
- **Elevation to Group Call**: Inviting a participant to an ongoing 1-on-1 call automatically promotes the call to a group session, registers participants in `ChatCallParticipant`, and dispatches an incoming call alert to the invitee.
- **Toast Alerts**: Dispatches `participant_invited` notifications to all participants in real time.

### 1.3 Rejection Messages & Notifications
- **1-on-1 Direct Calls**: When a receiver declines the call, the caller receives an immediate toast alert (`"{Name} has declined the call."`) and resets smoothly to idle.
- **Group Calls**: When an invited participant declines, active members receive an informational notification (`"{Name} has declined the group call."`).

### 1.4 Instant Media & Zero-Lag Initialization
- **Explicit Track Status**: Set `track.enabled = !this.isMuted` and `track.enabled = !this.isVideoOff` at the moment of track creation and WebRTC sender assignment.
- **No Manual Toggle Needed**: Microphones and cameras work immediately upon connection without requiring the user to cycle mute or camera buttons.

---

## 2. Verification & Automated Tests

- **Pest Feature & Unit Tests**: All 22 tests in `ChatSystemTest` passed (`php artisan test --compact --filter=ChatSystemTest`).
- **Laravel Pint Code Formatter**: Formatted cleanly (`vendor/bin/pint --format agent`).
- **Vite Build**: Compiled production assets with `npm run build` (Vite v8.2.2 in 2.94s).

---

## 3. Documentation & Changelog

- Updated release changelog: [`changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md).
