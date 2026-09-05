# Walkthrough: User Status Query Fix, Dynamic Mode Switching & Mid-Call Invites

We have resolved the SQL column error for the participant invite query, dynamic mode switching between audio/voice and video during active calls, and mid-call participant invitations with search modal.

---

## 1. Key Problem Solutions & Fixes

### 1.1 Fixed `Unknown column 'active'` in Invite Query
- **Root Cause**: In [`⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php), `availableUsersToInvite` was querying `where('active', true)` while the `users` table uses the column `status` (with values `'active'`, `'inactive'`, etc.).
- **Fix**: Updated the query to `->where(fn($q) => $q->whereNull('status')->orWhere('status', 'active'))`.

### 1.2 In-Call Voice <-> Video Mode Switching
- **Toolbar Switch Button**: Added mode switch controls in the active call toolbar.
  - **Audio &rarr; Video**: Upgrades the call to video, acquires camera streams, updates WebRTC senders with `replaceTrack`, and notifies peers via `call_mode_switched`.
  - **Video &rarr; Audio**: Downgrades to voice-only mode, non-destructively pauses video tracks, and switches the viewport to the live audio waveform stage.

### 1.3 Mid-Call Participant Invites ("Add to Call")
- **Invite Modal with Search**: Added an "Invite / Add Users" button that opens a responsive modal with live search.
- **Elevation to Group Call**: Inviting a participant to an ongoing 1-on-1 call automatically promotes the call to a group session, registers participants in `ChatCallParticipant`, and dispatches an incoming call alert to the invitee.
- **Toast Alerts**: Dispatches `participant_invited` notifications to all participants in real time.

### 1.4 Rejection Messages & Notifications
- **1-on-1 Direct Calls**: When a receiver declines the call, the caller receives an immediate toast alert (`"{Name} has declined the call."`) and resets smoothly to idle.
- **Group Calls**: When an invited participant declines, active members receive an informational notification (`"{Name} has declined the group call."`).

---

## 2. Verification & Automated Tests

- **Pest Feature & Unit Tests**: All 22 tests in `ChatSystemTest` passed (`php artisan test --compact --filter=ChatSystemTest`).
- **Laravel Pint Code Formatter**: Formatted cleanly (`vendor/bin/pint --format agent`).
- **Vite Build**: Compiled production assets with `npm run build` (Vite v8.2.2 in 2.94s).

---

## 3. Documentation & Changelog

- Updated release changelog: [`changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md).
