# Enterprise WebRTC Voice & Video Calling Architecture & Reliability Fix

We have resolved the WebRTC signaling errors, m-line ordering mismatches, and video/audio stream rendering issues in direct (1-on-1) and multi-party group calls.

---

## 1. What Was Fixed

### Key Issues Resolved:
1. **`InvalidAccessError: Failed to set local offer sdp: The order of m-lines in subsequent offer doesn't match order from previous offer/answer.`**
   - **Fix**: Implemented a **Deterministic Transceiver Architecture** (`ensureDeterministicTransceivers(pc)`). On every `RTCPeerConnection` instantiation, media section 0 is locked to `'audio'` and media section 1 is locked to `'video'`. Track updates (turning camera on/off, unmuting/muting, mode upgrades/downgrades) now use `sender.replaceTrack()` and transceiver direction switching (`sendrecv` vs `recvonly`) without calling `addTrack()`.
2. **`InvalidStateError: Failed to set local answer sdp: Called in wrong state: stable`**
   - **Fix**: Generated unique UUID-based `signalId`s on the backend (`WebRtcCallService` & `WebRtcCallSignalEvent`) and added an LRU `Set` deduplication cache on the frontend to prevent duplicate signals from concurrent WebSocket and polling channels. Implemented the **W3C Perfect Negotiation Protocol** with polite peer arbitration and glare rollback (`pc.setLocalDescription({ type: 'rollback' })`), guarding `setLocalDescription(answer)` to only run in `have-remote-offer`.
3. **Stuck Video / Muted Audio in Realtime**:
   - **Fix**: Added reactive `x-init="$nextTick(() => ...)"` lifecycle hooks to all `<video>` and `<audio>` elements across direct and multi-party group call layouts, ensuring immediate attachment of incoming streams even when elements are conditionally rendered via Alpine `<template x-if>`. Background audio sinks in `#webrtc-global-audio-sink` maintain uninterrupted audio playback.

---

## 2. Modified Files & Components

- [**`app/Events/WebRtcCallSignalEvent.php`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/WebRtcCallSignalEvent.php): Added unique `$signalId` parameter and payload enrichment.
- [**`app/Services/WebRtcCallService.php`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php): Generated UUID-based signal IDs (`sig_...`) for all broadcasted signals.
- [**`resources/js/chat-and-media.js`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js): Added `ensureDeterministicTransceivers`, signal deduplication, W3C perfect negotiation protocol, safe audio playback, and resilient stream binding for `chatCallOverlayAlpine` and `meetingRoomAlpine`.
- [**`resources/views/components/⚡chat-call-overlay.blade.php`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/%E2%9A%A1chat-call-overlay.blade.php): Added reactive `x-init` hooks and data attributes to local and remote `<video>` tags.
- [**`lang/en.json`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json), [**`lang/hi.json`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json), [**`lang/bn.json`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json): Added translations for call statuses, placeholders, and tooltips.
- [**`changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-mline-order-and-perfect-negotiation-fix.md`**](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-mline-order-and-perfect-negotiation-fix.md): Documented all architectural decisions and fixes.

---

## 3. Verification Results

### Automated Feature Tests
```bash
php artisan test --compact tests/Feature/WebRtcCallSystemTest.php
# PASS tests: 14, passed: 14, assertions: 79

php artisan test --compact tests/Feature/ChatSystemTest.php
# PASS tests: 22, passed: 22, assertions: 133
```

### Code Formatting & Asset Compilation
- **Pint**: `vendor/bin/pint --format agent` completed cleanly.
- **Vite Build**: `npm.cmd run build` built 27 modules in 1.62s with zero errors or warnings.
