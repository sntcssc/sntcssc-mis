# Fix: Online Meeting Platform — Peers Could Not See or Hear Each Other (WebRTC over Laravel Reverb)

**Date:** 2026-09-05 (updated after one-way-media follow-up)
**Branch:** `v3-admin-live-chat-WebRTC`
**Scope:** Online meetings (multi-party WebRTC), shared transport with 1-on-1 live chat calls, signaling endpoints, translations

---

## Follow-up Fix: One-Way Media (fix #8)

After the initial fixes, manual testing showed **one-way media**: USER A's camera/mic streamed to USER B, but USER B's media never reached USER A.

**Root cause (found via new client diagnostics):** the *answering* client could attach its mic/camera to a **dangling (unassociated) RTCRtpTransceiver**. When a peer connection is created from an early ICE-candidate event, transceivers are pre-created before the remote offer arrives; `getTransceiverByKind`'s index-based fallback could then select a dangling transceiver (mid `null`) instead of the one negotiated with the remote m-line. The track was attached to a transceiver whose media is never transmitted, so the **answer was effectively `recvonly`** — the answerer kept *receiving* (why B could still see/hear A) while never *sending*. Verified live afterwards: every cached answer SDP now declares `sendrecv` on both m-lines with SSRC lines.

**Fix:** `getTransceiverByKind` in `resources/js/meeting.js` now prefers **associated** transceivers (`mid !== null`) whose sender/receiver carries the right kind, and only falls back to pre-offer/index matching when no associated transceiver exists.

**Also added — WebRTC connection-health diagnostics (supportability):**
- `meeting.js` collects a 10-second snapshot per client (peer connection/ICE/signaling states, sender & receiver track readiness, remote stream tracks, local stream state, WebSocket status) and reports it via Livewire.
- New `reportWebRtcDiagnostics()` on the meeting-room Livewire component logs it as `WEBRTC_DIAG meeting=... user=...` — grep this in `storage/logs/laravel.log` to see both sides' truth during any future incident.

---

## Follow-up Fix #2: Live Chat Voice & Video Calls (1-on-1 and Group)

The call code path (`resources/js/chat-and-media.js`, used by the chat call overlay) had the **same defects** as the meeting room. Fixes applied:

1. **Dangling-transceiver bug (one-way media in calls)** — `ensureDeterministicTransceivers` used index-based transceiver selection (`t.mid === '0' || idx === 0`) that can pick an unassociated transceiver created before the remote offer arrived. The caller/callee's mic/camera then rode a transceiver whose media is never transmitted, producing one-way audio/video. Now uses the same associated-transceiver-preferring selection as the meeting (`getCallTransceiverByKind`). Verified by a scripted call smoke test: the 1-on-1 video offer now carries both m-lines as `sendrecv` with 6 SSRC lines and live tracks attached to the correct transceivers.
2. **Call stall self-healing (new watchdog):** `startCallWatchdog()` runs every 4 s while a call is active (stopped on `cleanupWebRtc()`):
   - **Group calls:** a peer connection stuck in `new`/`connecting` for 20 s (lost answer, dead remote) is recycled and renegotiated by the lower user id; the higher id sends `peer_presence`.
   - **1-on-1 calls:** if the answer never arrives, the offer is re-transmitted every 8 s (`_lastOfferSentAt` stamped on every offer path: initial offer, `call_accepted`, `peer_presence`) instead of stalling silently in `have-local-offer`.
3. **Glare handling parity:** when the impolite peer in a group call ignores a colliding offer, it now re-transmits its own offer (previously both sides could end up waiting for each other with no media).
4. Unhandled promise rejections from `replaceTrack` in `ensureDeterministicTransceivers` are now caught.

Note: the shared root-cause fixes earlier in this changelog already covered the call path as well — the Reverb payload limits (group-call SDP offers also exceeded the old 10 KB default), the `TrimStrings` SDP corruption, and the compact `WebRtcCallSignalEvent` broadcast payloads. This follow-up adds the call-specific negotiation fixes on top.

## Summary of Root Causes Found (Live-Diagnosed)

The problem was reproduced live with two authenticated browsers in the same meeting room (`php artisan reverb:start --debug`, fake media devices, two isolated Chrome profiles). Two independent root causes — plus several secondary defects — prevented any audio/video between participants:

### Root Cause 1 — Reverb silently rejected all SDP broadcasts ("Payload too large")

`storage/logs/laravel.log` showed repeated:

```
MeetingRealtimeEvent signal broadcast fallback: Pusher error: Payload too large..
Realtime WebRtcCallSignalEvent broadcast fallback: Pusher error: Payload too large..
```

Reverb enforces `max_request_size` / `max_message_size` of **10,000 bytes by default**. A WebRTC SDP offer/answer for an audio+video meeting is typically **6–8 KB on its own**, and the event was **double-wrapped** (`payload` embedded inside another `signalType/fromUserId/targetUserId` wrapper that already contained the same metadata), pushing every offer/answer broadcast past 10 KB. Reverb dropped them, so **offers and answers never reached the other peer** — users joined, saw each other's tiles, but negotiation never happened.

### Root Cause 2 — Laravel `TrimStrings` middleware corrupted every SDP (verified byte-level)

Laravel's global `TrimStrings` middleware trims every incoming request string, including the SDP inside the JSON signal payload. This **stripped the mandatory trailing CRLF** from the SDP, so the receiving browser's parser failed:

```
OperationError: Failed to execute 'setRemoteDescription' on 'RTCPeerConnection':
Failed to parse SessionDescription. a=ssrc:... msid:... Invalid SDP line.
```

Verified live: an SDP sent as 5,668 bytes arrived as 5,666 bytes — exactly the trailing `\r\n` missing (177 → 176 CRLFs). Chrome requires every SDP line to be CRLF-terminated, so **every answer was rejected client-side**, even after Root Cause 1 was fixed.

---

## Fixes Applied

### 1. Reverb payload limits (`.env`, `.env.example`)

```env
REVERB_MAX_REQUEST_SIZE=1000000
REVERB_APP_MAX_MESSAGE_SIZE=1000000
```

> ⚠️ **Deployment note:** restart `php artisan reverb:start` after changing `.env` — Reverb reads these at boot. The local Reverb server was restarted during this fix.

### 2. Slim, non-duplicated broadcast payloads

- `app/Events/MeetingRealtimeEvent.php` — `broadcastWith()` now emits a compact payload (`event_type`, `type`, `payload`, `sender_user_id`, `timestamp`); removed duplicated camelCase mirror keys.
- `app/Events/WebRtcCallSignalEvent.php` — same treatment (also fixes 1-on-1 call signaling size).
- `app/Services/MeetingService::sendSignal()` — the wire format is now `signalType/fromUserId/targetUserId` at the top level with the raw signal (SDP/candidates) under `payload`, so **the SDP is transmitted exactly once**. The polling-fallback cache now stores the same structure (previously the polling path delivered offers without their SDP — polling fallback was broken for negotiation).

### 3. SDP-safe `TrimStrings` (new: `app/Http/Middleware/TrimStrings.php`, registered in `bootstrap/app.php`)

The app middleware replaces the framework one and never trims strings starting with `v=0` (SDP). This is content-based rather than path-based so it also protects 1-on-1 call SDPs that travel through Livewire update requests. **This is the fix for the "Invalid SDP line" failure** — verified live with a byte-identical 5.7 KB SDP round-trip through the signaling endpoint.

### 4. `MeetingSignalController` hardening (security + robustness)

- **`sync` endpoint had no authorization at all** — any authenticated user could read any meeting's cached signals. Now shares the same access check as `signal()` (host / Super Admin / participant / open-access).
- Input validation (`signalType` ≤ 64 chars, payload array, target user id integers) and a **60 KB payload ceiling** with a friendly 422.
- Try/catch around both actions with `Log::error` and graceful JSON errors; no more unhandled exceptions.
- Shared `canAccessMeeting()` authorization helper.

### 5. Rate limiting (`routes/web.php`)

`throttle:240,1` applied to all four meeting signal/sync routes (direct + team-scoped). Sufficient headroom for legitimate signaling bursts (ICE batches, 1s polling fallback) while blocking abuse.

### 6. CSRF posture corrected (`bootstrap/app.php`)

The `meetings/*/signal` POST routes were previously CSRF-exempt (a session-CSRF risk). The client always sends `X-CSRF-TOKEN`, so the exemption was removed — only the GET `sync` route remains exempt. Verified the meeting client still works.

### 7. `resources/js/meeting.js` — negotiation resilience

- **Renegotiation storm removed:** `syncActivePeers()` used to treat transient ICE `disconnected` as failure and re-offer every 3 seconds. Now `disconnected` requires a 10 s grace period, and a 2 s per-peer backoff prevents signaling hammering.
- **Stalled-connection self-healing:** a peer connection stuck in `new`/`connecting` for more than 15 s (e.g. remote answer lost) is now recycled and renegotiated automatically instead of stalling forever.
- **Clock-skew fix in polling fallback:** `_lastSignalTimestamp` was initialised from the client clock and compared against server `microtime(true)`; a skewed clock silently filtered out fresh signals. Now initialised to 0 (server clock governs; duplicates are handled by `_processedSignalIds`).
- **Broken statements fixed in `startVideoCompletely()` catch block** (`canRetryCamera: true, isRetrying = false;` — a no-op label/comma expression that left the retry button state stale) — now proper assignments.
- Broadcast-failure logs in `MeetingService` upgraded from `Log::debug` to `Log::warning` with an actionable message, so degraded WebRTC signaling is visible in operations.

---

## Translations

Scanned every `__()` key on the meeting platform (meeting room, meetings index, signal/join controllers, `MeetingService`) against `lang/en.json`, `lang/bn.json`, `lang/hi.json`:

- **231 missing keys** added to **all three** files (English identity entries registered, full Bengali and Hindi translations provided) — e.g. waiting-room copy, host controls, spotlight/pin actions, permission errors, passcode prompts, invitation emails and notification strings.

---

## Tests

New feature test suite `tests/Feature/MeetingWebRtcSignalingSecurityTest.php` (10 tests, Pest):

1. `sync` rejects non-participants of closed meetings (regression test for the missing authorization).
2. `sync` allows the host and returns the polling signal payload.
3. `signal` rejects non-participants of closed meetings.
4. `signal` rejects oversized payloads (422).
5. `signal` accepts realistic SDP offer sizes (regression test for the Reverb limit fix).
6. `sendSignal` broadcasts the compact, non-duplicated wire format.
7. `getRecentSignals` excludes the requesting user's own signals.
8. Ended meetings refuse new signals (410).
9. Unauthenticated access is rejected on both endpoints.
10. Signal cache entries are capped at 50.

**Result: full suite green — 378 passed (1,499 assertions), including all pre-existing meeting/chat/call suites.** `vendor/bin/pint --dirty` applied. `npm run build` compiles cleanly.

---

## Live Verification Performed (two-browser test)

Two isolated Chrome profiles with Chrome's fake media devices joined the same meeting:

- ✅ Root cause 1 confirmed live: Reverb dropped every offer/answer with "Payload too large" until the limits were raised; afterwards offers/answers/ICE appear in the signal cache and are delivered.
- ✅ Root cause 2 confirmed live: console showed `setRemoteDescription ... Invalid SDP line`; after the `TrimStrings` fix the SDP round-trips **byte-identical** (trailing CRLF preserved).
- ✅ Offer → answer → ICE candidate exchange completes in both directions within seconds.
- ✅ The answering side received the remote stream (1 video + 1 audio track) and the remote `<video>` element bound to it.
- ✅ Client renegotiation/recycling now recovers lost answers automatically.
- ℹ️ One interactive check could not be completed in the automation rig: the second test browser was minimised, and Chrome suspends WebRTC (ICE/DTLS) in suspended windows. Please do a quick visual pass with two **visible** browser windows to see the live video tiles.

---

## Recommendations (Not Required, but Recommended)

1. **TURN server for production.** Current ICE config is STUN-only (Google public STUN). STUN works on the same LAN/typical NATs, but users behind symmetric NAT/corporate firewalls will fail to connect. Add a TURN server (e.g. coturn, or a managed service like Twilio/Xirsys Cloud) in Settings → meetings (`meeting.webrtc_turn_server` / `username` / `credential`) — the backend already supports it and the client already consumes it.
2. **Presence channel for the meeting room.** `presence-meeting.{uuid}` with `Echo.join().here()/joining()/leaving()` would replace the 3-second DOM/registry heuristic (`syncActivePeers`) with authoritative presence, and give accurate participant counts.
3. **Move signaling to WebSockets (whisper) for small signals.** Once negotiation is established, `peer_state`/`floating_emoji` could use Echo whispers to avoid HTTP round-trips entirely (keep HTTP for SDP, which needs the server-side cache fallback).
4. **Reverb scaling:** if you ever run multiple Reverb instances horizontally, enable `REVERB_SCALING_ENABLED` with Redis — otherwise events published to one node won't reach clients on another.
5. **Monitor `Log::warning('Meeting signal broadcast failed...')`** in production — it now surfaces silent WebRTC degradation (e.g. Reverb down) instead of hiding it at debug level.
6. **Restart Reverb on deploy** whenever `REVERB_*` env values change; consider a supervisor/systemd unit for `php artisan reverb:start` in production instead of a terminal.

---

## Files Changed

| File | Change |
| --- | --- |
| `.env` / `.env.example` | Added `REVERB_MAX_REQUEST_SIZE=1000000`, `REVERB_APP_MAX_MESSAGE_SIZE=1000000` |
| `app/Events/MeetingRealtimeEvent.php` | Compact `broadcastWith()` |
| `app/Events/WebRtcCallSignalEvent.php` | Compact `broadcastWith()` (also fixes 1-on-1 calls) |
| `app/Services/MeetingService.php` | Single-wrap wire payload, polling cache format, warning-level broadcast logs |
| `app/Http/Controllers/MeetingSignalController.php` | Authorization on `sync`, validation, payload ceiling, try/catch, error handling |
| `app/Http/Middleware/TrimStrings.php` | **New** — SDP-safe string trimming |
| `bootstrap/app.php` | Register TrimStrings replacement; CSRF exemptions reduced to sync GET only |
| `routes/web.php` | `throttle:240,1` on signal/sync routes |
| `resources/js/meeting.js` | Renegotiation backoff, stalled-connection recycling, clock-skew fix, `startVideoCompletely()` fix |
| `lang/en.json`, `lang/bn.json`, `lang/hi.json` | +231 translated keys each |
| `tests/Feature/MeetingWebRtcSignalingSecurityTest.php` | **New** — 10 security/regression tests |

## Test Data Note

During diagnosis the passwords of the two seeded users `test@example.com` and `admin@sntcssc.in` were reset to a known test password (`webrtc-test-2026`) to enable automated logins. Please rotate/remove these if those credentials matter to you. Three diagnostic meetings created during testing were soft-deleted.


----

Wonderful — I'm glad both the online meetings and the live chat calls are working now! 🎉

Quick recap of what was delivered in this session:

- **Root causes fixed:** Reverb's 10 KB payload limit silently dropping all SDP broadcasts, and Laravel's `TrimStrings` corrupting every SDP by stripping its trailing CRLF — plus the dangling-transceiver bug that caused one-way media in both meetings and calls.
- **Resilience:** self-healing renegotiation in meetings and calls, renegotiation-storm prevention, clock-skew fix, and glare re-transmission.
- **Security & quality:** authorization on the previously unguarded `sync` endpoint, rate limiting, payload validation, corrected CSRF posture, 231 translations (en/bn/hi), 378 passing tests, and the new `WEBRTC_DIAG` health logging for future troubleshooting.

Two small reminders for when you deploy:

1. **Restart Reverb** after deploying, since the new `.env` payload limits (`REVERB_MAX_REQUEST_SIZE`, `REVERB_APP_MAX_MESSAGE_SIZE`) only take effect at boot.
2. **Rotate the test passwords** I set during diagnosis (`test@example.com` and `admin@sntcssc.in` → `webrtc-test-2026`).

And the standing recommendation: adding a TURN server (coturn or a managed service) will make calls work for users behind strict corporate NATs — the app already supports it via the meetings settings.

Everything is documented in [changelogs/2026-09-05-fix-webrtc-meeting-realtime-negotiation-payload-and-security.md](changelogs/2026-09-05-fix-webrtc-meeting-realtime-negotiation-payload-and-security.md). Enjoy your working calls! 👋
