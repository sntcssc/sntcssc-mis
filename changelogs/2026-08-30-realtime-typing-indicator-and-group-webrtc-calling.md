# Real-Time Typing Indicator, WebRTC 1-to-1 & Multi-Party Voice/Video Calling, Interactive Mode Switch Approvals & Mid-Call Invites

**Date:** August 30, 2026  
**Release / Feature:** User Status Schema Fix for Invite Modal, Interactive Voice <-> Video Switch Approval Workflow, Mid-Call Participant Invites, Rejection Notifications, Instant Media Hardware Initialization, Global Audio Sinks

---

## 1. Executive Summary & Core Architectural Fixes

### 1.1 User Status Query Schema Fix & Cache Clearing
- **Issue:** The participant invite modal query previously attempted to filter on a nonexistent column `active`.
- **Fix:** Corrected the query in `availableUsersToInvite` in `⚡chat-call-overlay.blade.php` to query the actual `status` column: `->where(fn($q) => $q->whereNull('status')->orWhere('status', 'active'))`. Cleared compiled view caches (`php artisan view:clear` and `php artisan cache:clear`).

### 1.2 Interactive Mode Switch Approval Workflow (Voice <-> Video)
- **Two-Way Consent Requirement:** Mode switching (Audio -> Video or Video -> Audio) now requires mutual agreement between participants:
  - **Requester:** When clicking "Switch Call Mode", a `switch_mode_request` signal is broadcasted, and the requester is informed that the request has been sent to participants.
  - **Recipient:** A confirmation modal displays: `"{Name} has requested to switch this call to a {Video/Voice} call."` with **Accept** and **Decline** options.
  - **On Acceptance:** Dispatches `switch_mode_response` (`accepted: true`), upgrades/downgrades media tracks dynamically, and updates viewports on both ends.
  - **On Rejection:** Dispatches `switch_mode_response` (`accepted: false`), notifies the requester (`"{Name} declined the request to switch call mode."`), and keeps the call in its current state without switching.

### 1.3 Mid-Call Participant Invitations ("Add to Call")
- **Live Participant Picker Modal:** Added an "Invite / Add Users" button to the active call toolbar. Users can search organization members and invite them directly into the live call.
- **Dynamic 1-on-1 to Group Elevation:** Inviting a user to a 1-on-1 direct call seamlessly elevates the session into a multi-party group call (`isGroup = true`), creates a `ChatCallParticipant` record, and dispatches a live `incoming_call` WebRtcCallSignal to the invitee's user channel (`user.{id}`).
- **Broadcast Notifications:** All existing participants receive `participant_invited` toast notifications.

### 1.4 Call Rejection Messages & Notifications
- **1-on-1 Direct Calls:** When User B declines or rejects a call, User A receives a clear toast message: `"{Name} has declined the call."` and resets cleanly to idle.
- **Group Calls:** When an invited user declines, group participants receive an informational toast: `"{Name} has declined the group call."` without disrupting the ongoing session.

### 1.5 Instant Media & Zero-Lag Initialization
- **Explicit Track Enablement:** In `initWebRtc`, local microphone and camera tracks are explicitly set to `track.enabled = !this.isMuted` and `track.enabled = !this.isVideoOff` upon acquisition.
- **Eliminated Toggle Workaround:** Remote audio and video elements trigger `.play()` and are bound to persistent audio sinks immediately, eliminating the previous need to manually toggle mute/camera after connecting.

---

## 2. Verification & Validation Results

- **Pest Automated Test Suite:** `php artisan test --compact --filter=ChatSystemTest` ran with **22 passed tests (133 assertions)**.
- **Pint Code Formatter:** Verified and formatted cleanly with `vendor/bin/pint --format agent`.
- **Frontend Asset Compilation:** Compiled with `npm run build` (Vite v8.2.2 in 1.25s).
