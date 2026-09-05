# Enterprise WebRTC Calling Architecture & Reliability Fix (M-Lines Order & Perfect Negotiation)

**Date**: 2026-09-01  
**Category**: Realtime WebRTC Communications / Live Chat Audio & Video Calling  
**Status**: Completed & Verified  

---

## 1. Problem Overview & Root Cause Analysis

### Identified WebRTC Calling Issues:
1. **M-Lines Order Mismatch Error (`InvalidAccessError`)**:
   - **Error Message**: `Error creating group offer for peer 3: InvalidAccessError: Failed to execute 'setLocalDescription' on 'RTCPeerConnection': Failed to set local offer sdp: The order of m-lines in subsequent offer doesn't match order from previous offer/answer.`
   - **Root Cause**: WebRTC RFC 8829 specifies that once an `RTCPeerConnection` establishes media sections, their sequence and indices cannot change. Starting audio-only calls or toggling video dynamically attached media tracks using `addTrack()` or legacy `{ offerToReceiveVideo: true }` options without fixed transceiver slots. Subsequent renegotiation offers generated mismatched media description sections (`m=audio`, `m=video`), causing browser WebRTC engines to reject `setLocalDescription`.
2. **Invalid State Answer Error (`InvalidStateError`)**:
   - **Error Message**: `Error handling 1-on-1 renegotiation offer: InvalidStateError Failed to execute 'setLocalDescription' on 'RTCPeerConnection': Failed to set local answer sdp: Called in wrong state: stable`
   - **Root Cause**: Concurrent signal delivery through Reverb WebSockets and polling fallback caused duplicate offer signals to be processed back-to-back without unique signal IDs. Once the first offer moved the connection to `stable`, the duplicate offer attempted to set an answer on a connection already in `stable`.
3. **Stuck Video / Muted Realtime Audio**:
   - **Root Cause**: Dynamic Alpine DOM mounting (`<template x-if>`) rendered `<video>` and `<audio>` tags asynchronously after `ontrack` had already executed. Media streams were not systematically rebound to the newly mounted DOM elements upon layout changes, mode switches, or participant arrivals.

---

## 2. Implemented Architecture & Solutions

### A. Deterministic Transceiver Architecture (Audio = Index 0, Video = Index 1)
- Implemented `ensureDeterministicTransceivers(pc)` across all WebRTC peer connections (1-on-1 direct calling, multi-peer group calling, and meeting rooms).
- Locked Transceiver 0 to `'audio'` and Transceiver 1 to `'video'` upon `RTCPeerConnection` instantiation.
- Updated track management to use `transceiver.sender.replaceTrack(track || null)` and modify `transceiver.direction` (`sendrecv` vs `recvonly`) instead of dynamically calling `addTrack()`.
- Stripped legacy `offerToReceiveAudio` / `offerToReceiveVideo` constraints from `createOffer()` and `createAnswer()`.

### B. W3C Perfect Negotiation Protocol & Signal Deduplication
- Added unique UUIDs to every WebRTC signal event in `WebRtcCallService` and `WebRtcCallSignalEvent`.
- Maintained a bounded LRU `Set` of processed signal IDs in the Alpine client to prevent duplicate signal execution from concurrent WebSocket and polling fallbacks.
- Implemented polite vs. impolite peer arbitration (`isPolite = Number(myUserId) > Number(remoteUserId)`).
- Added glare collision protection with polite rollback (`pc.setLocalDescription({ type: 'rollback' })`) and guarded `setLocalDescription(answer)` to execute only when `pc.signalingState === 'have-remote-offer'`.

### C. Reliable Media Track Binding & Autoplay Resilience
- Added reactive `x-init="$nextTick(() => ...)"` lifecycle hooks on all `<video>` and `<audio>` elements.
- Implemented multi-frame rebinding in `bindRemoteVideo()`, `rebindAllRemoteVideos()`, and `rebindLocalVideo()`.
- Maintained dedicated background audio sink elements in `#webrtc-global-audio-sink` ensuring persistent audio playback even during modal minimizations or tab switching.

---

## 3. Files Modified

| File | Changes Made |
| :--- | :--- |
| [`app/Events/WebRtcCallSignalEvent.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/WebRtcCallSignalEvent.php) | Added signal ID tracking and broadcast payload enrichment. |
| [`app/Services/WebRtcCallService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php) | Generated unique UUID-based signal IDs for all dispatched call signals. |
| [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js) | Implemented deterministic transceivers, perfect negotiation, signal deduplication, and reliable media binding for `chatCallOverlayAlpine` and `meetingRoomAlpine`. |
| [`resources/views/components/⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php) | Added `x-init` mounting hooks and data attributes to local and remote `<video>` elements. |
| [`resources/views/pages/portal/⚡meeting-room.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/%E2%9A%A1meeting-room.blade.php) | Fixed Alpine `x-show` speaker test loader icon to resolve `Undefined constant "isTestingSpeaker"` error. |
| [`lang/en.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json) | Added missing call localization strings. |
| [`lang/hi.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json) | Added Hindi translations for call statuses and tooltips. |
| [`lang/bn.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json) | Added Bengali translations for call statuses and tooltips. |

---

## 4. Verification & Testing

- **Pest Feature Tests**:
  - `php artisan test --compact tests/Feature/WebRtcCallSystemTest.php` -> 14 passed (79 assertions).
  - `php artisan test --compact tests/Feature/ChatSystemTest.php` -> 22 passed (133 assertions).
- **Code Style**:
  - Formatted with `vendor/bin/pint --format agent`.
- **Frontend Asset Compilation**:
  - Built with `npm.cmd run build` without any warnings or errors.
