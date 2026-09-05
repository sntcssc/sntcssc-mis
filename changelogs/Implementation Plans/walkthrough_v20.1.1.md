# Enterprise WebRTC Live Chat Voice & Video Calling Upgrade & Fixes

We have resolved the bugs in Live Chat Voice & Video calling, implemented dynamic Voice <-> Video mode switching with SDP renegotiation, added hardware device selection, and redesigned the calling experience with modern production-grade UI/UX.

---

## Key Achievements & Resolved Issues

### 1. Voice <-> Video Mode Switching with Dynamic SDP Renegotiation
- **Problem**: Switching between Voice (audio) and Video calls failed to transmit video tracks and had no mutual confirmation workflow.
- **Solution**:
  - Implemented mutual consent workflow with `switch_mode_request` and `switch_mode_response` signals.
  - Implemented dynamic WebRTC SDP renegotiation: when upgrading to Video, a new video track is acquired, attached to `localStream`, replaced on WebRTC senders via `replaceTrack()`, and a renegotiation `createOffer({ offerToReceiveVideo: true, offerToReceiveAudio: true })` / `createAnswer()` exchange is performed with peers.
  - Added a 20-second auto-dismiss timeout for mode switch requests.

### 2. Microphone, Camera & Speaker Hardware Selection & Error Handling
- **Problem**: Users could not select or switch input/output devices; preview track stopping caused `TrackStartError` / `NotReadableError` locks when transitioning to active calls.
- **Solution**:
  - Implemented live hardware device enumeration (`enumerateDevices`) for microphones, cameras, and audio output speakers.
  - Added device dropdown selectors in both the **Pre-Call Preview Modal** and the **In-Call Device Settings Modal**.
  - Added interactive **Speaker Test Chime** using Web Audio API synthesis.
  - Added **Mirror Camera Toggle** (`-scale-x-100` toggle).
  - Provided graceful fallback from video to audio when camera permissions are restricted.

### 3. Signaling Synchronization & ICE Candidate Buffering
- **Problem**: ICE candidates arriving before `setRemoteDescription` were discarded, resulting in connection drops or one-way media streams.
- **Solution**:
  - Added queueing in `pendingIceCandidates` and per-peer candidate buffers.
  - Candidates are automatically drained and registered as soon as `setRemoteDescription` completes.
  - Added persistent audio sinks (`ensureAudioSink`) and audio autoplay unblock detection.

### 4. Modern UI/UX Redesign (Light & Dark Mode)
- **Pre-Call Device Preview**:
  - Glassmorphic card design with 16:9 camera feed, multi-band live audio visualizer, mirror toggle, device dropdown selectors, and speaker test button.
- **Active Calling Overlay**:
  - Radar-pulsing incoming call modal with accept and decline actions.
  - Responsive 1-on-1 and multi-party group grid with speaker spotlighting, voice meters, and active speaking borders.
  - **Floating Minimized Pill Widget**: Allows users to minimize ongoing calls to the bottom-right corner and continue chatting or navigating without interruption.
- **In-Call Modals**:
  - Dedicated Device Settings Modal, Invite Users Modal, and Mode Switch Confirmation Dialog.

### 5. Enterprise Soft Deletes, DB Transactions & Audit Logging
- Added soft deletes to `chat_call_participants` (`2026_09_01_210500_add_soft_deletes_to_chat_call_participants_table.php`).
- Wrapped all calling operations in `DB::transaction()`.
- Logged all call lifecycle events via `AuditLogService` (`webrtc_call_initiated`, `webrtc_call_accepted`, `webrtc_mode_switched`, `webrtc_participant_invited`, etc.).

### 6. Full Multi-Language Localization
- Added translations to `lang/en.json`, `lang/hi.json`, and `lang/bn.json`.

---

## Verification & Validation Results

### Automated Tests
- Ran Pest test suite:
  ```bash
  php artisan test --compact --filter=WebRtcCallSystemTest
  ```
  **Result**: `14 passed (79 assertions)`

  ```bash
  php artisan test --compact --filter=ChatSystemTest
  ```
  **Result**: `22 passed (133 assertions)`

### Code Style & Asset Compilation
- Formatted PHP code with Laravel Pint:
  ```bash
  vendor/bin/pint --format agent
  ```
  **Result**: Clean
- Compiled frontend assets with Vite:
  ```bash
  npm.cmd run build
  ```
  **Result**: Built in 2.21s with all assets transformed.

---

## Changelog
A technical changelog has been saved to:
`changelogs/2026-09-01-enterprise-webrtc-voice-video-calling-redesign-and-mode-switch-fix.md`
