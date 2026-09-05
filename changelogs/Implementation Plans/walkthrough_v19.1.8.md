# Walkthrough: User Status Query Fix & Interactive Switch Mode Approval Workflow

We have fixed the SQL column query error for user invitations, cleared view caches, and implemented an interactive request-and-approval workflow for switching between voice and video calls.

---

## 1. Key Problem Solutions & Fixes

### 1.1 User Status Query Schema Fix & Cache Invalidation
- **Fixed Column Error**: In [`⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php), changed the query from `where('active', true)` to `where(fn($q) => $q->whereNull('status')->orWhere('status', 'active'))`.
- **Cleared View & Application Caches**: Ran `php artisan view:clear` and `php artisan cache:clear` to purge cached compiled views.

### 1.2 Two-Way Consent Workflow for Voice <-> Video Switching
- **Request Signal (`switch_mode_request`)**: When a participant clicks the switch button, a request is sent to the other participants instead of switching immediately.
- **Confirmation Modal**: The recipient receives an in-call modal:
  > **Switch Call Mode?**  
  > *"{Name} has requested to switch this call to a {Video / Voice} call."*  
  > Buttons: **Accept Switch** | **Decline**
- **Decision Handling (`switch_mode_response`)**:
  - **Accept**: Both sides switch mode, update media tracks, and transition layouts simultaneously.
  - **Decline**: The requester receives a notification (`"{Name} declined the request to switch call mode."`) and the call continues in its current state without switching.

### 1.3 Mid-Call Participant Invites ("Add to Call")
- **Live Search Modal**: Added an "Invite / Add Users" button that opens a responsive modal with live search.
- **Elevation to Group Call**: Inviting a participant to an ongoing 1-on-1 call automatically promotes the call to a group session, registers participants in `ChatCallParticipant`, and dispatches an incoming call alert to the invitee.

---

## 2. Verification & Automated Tests

- **Pest Feature & Unit Tests**: All 22 tests in `ChatSystemTest` passed (`php artisan test --compact --filter=ChatSystemTest`).
- **Laravel Pint Code Formatter**: Formatted cleanly (`vendor/bin/pint --format agent`).
- **Vite Build**: Compiled production assets with `npm run build` (Vite v8.2.2 in 1.25s).

---

## 3. Documentation & Changelog

- Updated release changelog: [`changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md).
