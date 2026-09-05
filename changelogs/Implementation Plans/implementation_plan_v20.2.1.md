# WebRTC Voice & Video Calling Enterprise Stability & UI/UX Enhancement Plan

This plan addresses the WebRTC SDP offer/answer m-line order mismatch (`InvalidAccessError`), signaling state conflict (`InvalidStateError: Called in wrong state: stable`), real-time audio/video transmission stalling, and delivers an enterprise-grade, mobile-responsive call UI with dark/light mode, audit logging, transactions, soft delete support, and comprehensive translations.

## Problem Analysis

1. **SDP m-lines Order Mismatch (`InvalidAccessError: Failed to set local offer sdp: The order of m-lines in subsequent offer doesn't match order from previous offer/answer`)**:
   - WebRTC RFC 8829 (JSEP) mandates that once an `RTCPeerConnection` negotiates media lines (`m=audio`, `m=video`), the sequence and indices of media sections cannot be changed in subsequent offers/answers.
   - In voice calls or when switching camera on/off, tracks/transceivers were dynamically added using `addTrack()` or legacy `createOffer({ offerToReceiveVideo: true })` after initial negotiation, causing Chrome/Chromium to generate subsequent offers with altered m-line ordering.
2. **Renegotiation Wrong State (`InvalidStateError: Failed to set local answer sdp: Called in wrong state: stable`)**:
   - Concurrently arriving signals from WebSocket broadcasting and polling fallback, combined with lack of signal ID deduplication in `WebRtcCallSignalEvent`, resulted in duplicate offer processing.
   - When a duplicate offer was handled after the connection had already returned to `stable`, calling `createAnswer()` and `setLocalDescription(answer)` resulted in `InvalidStateError`.
3. **Stuck Video / Stalled Realtime Audio**:
   - Video and audio DOM elements inside Alpine `<template x-if="...">` blocks were not mounted when `ontrack` or stream initialization executed, resulting in detached `srcObject` references.
   - Autoplay policies and audio sink element IDs had discrepancies between blade templates and JavaScript helpers.

---

## Proposed Changes

### 1. Signaling & Backend Engine (`app/` & `routes/`)

#### [MODIFY] [WebRtcCallService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php)
- Generate a unique `$signalId = 'sig_'.(string) Str::uuid()` on every outbound signal.
- Pass `$signalId` to `WebRtcCallSignalEvent` to guarantee broadcast deduplication.
- Ensure all call operations (initiate, accept, reject, invite, mode switch, leave, end) use strict DB transactions, soft deletes, and comprehensive `AuditLogService` logging.

#### [MODIFY] [WebRtcCallSignalEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/WebRtcCallSignalEvent.php)
- Accept `public ?string $signalId = null` in constructor.
- Broadcast `'id' => $this->signalId`, `'signal_id' => $this->signalId`, `'signalId' => $this->signalId` in `broadcastWith()`.

---

### 2. WebRTC Client Engine (`resources/js/chat-and-media.js`)

#### [MODIFY] [chat-and-media.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- **Deterministic Transceiver Architecture**:
  - Upon creating ANY `RTCPeerConnection` (1-on-1 or multi-party group calls):
    - `audioTransceiver = pc.addTransceiver('audio', { direction: 'sendrecv' })` (Media Section 0)
    - `videoTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' })` (Media Section 1)
  - Lock media section 0 to Audio and media section 1 to Video permanently.
  - Dynamically replace tracks on existing transceivers (`audioTransceiver.sender.replaceTrack(track)`, `videoTransceiver.sender.replaceTrack(track)`) without calling `addTrack()`.
  - Eliminate legacy `offerToReceiveAudio`/`offerToReceiveVideo` from `createOffer()` and `createAnswer()`.
- **W3C Perfect Negotiation & Signaling Guards**:
  - Implement `makingOffer`, `isSettingRemoteAnswerPending`, `ignoreOffer`, and polite/impolite peer arbitration (`isPolite = myUserId > remoteUserId`).
  - Guard every `setRemoteDescription`, `setLocalDescription`, and `createAnswer` with explicit `signalingState === 'have-remote-offer'` checks.
- **Robust Signal Deduplication**:
  - Enforce strict signal tracking across WebSocket and Polling via `Set` of signal IDs.
- **Seamless Stream & DOM Video/Audio Rebinding**:
  - Ensure `srcObject` is bound and re-bound upon DOM rendering with multi-tick retries, global background audio sinks, and user-gesture autoplay recovery.

---

### 3. Component & Responsive UI/UX (`resources/views/components/⚡chat-call-overlay.blade.php`)

#### [MODIFY] [⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php)
- Enterprise-grade modern UI/UX with full support for Light & Dark mode.
- Mobile-first responsive layout (smart stacking on mobile screens, PIP draggable widget, flexible grid for group calls).
- Add `x-init` hooks on video elements to ensure instant binding upon Alpine rendering.
- Live audio waveform meters, mute badges, connection quality indicators, device settings drawer, and participant invitation modals.

---

### 4. Localization & Changelogs

#### [MODIFY] [lang/en.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json)
#### [MODIFY] [lang/hi.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json)
#### [MODIFY] [lang/bn.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json)
- Add all strings for WebRTC voice/video calls, mode switches, audio autoplay unlock, and device controls.

#### [NEW] [2026-09-01-enterprise-webrtc-voice-video-calling-mline-and-renegotiation-fix.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-mline-and-renegotiation-fix.md)
- Complete technical documentation of changes.

---

## Verification Plan

### Automated Tests
- Run `php artisan test --compact tests/Feature/WebRtcCallSystemTest.php`
- Run `php artisan test --compact tests/Feature/ChatSystemTest.php`
- Add new test cases verifying signal ID delivery, group signaling deduplication, and mode switches.

### Linting & Formatting
- Run `vendor/bin/pint --dirty --format agent`
- Verify frontend bundling with `npm run build`.
