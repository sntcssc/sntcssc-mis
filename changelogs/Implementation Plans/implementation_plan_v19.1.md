# Realtime Typing Indicator & Concurrent Group Voice/Video Calling

## Overview
This implementation plan adds:
1. **Real-time Typing Indicators**:
   - Works across **One-to-One (Direct)**, **Group**, and **Broadcast/Announcement Channels** (for users with posting permissions).
   - Real-time event broadcasting via Laravel Reverb / Pusher WebSockets and polling fallback.
   - Smart aggregation (e.g. *"Alice is typing..."*, *"Alice and Bob are typing..."*, *"Several people are typing..."*) with smooth 3-dot pulsing wave animations.
   - Displayed in the chat header subtext, above the message input bar, and inside the conversation sidebar preview.
   - Auto-expiring timer (2.5s) to reset typing state when idle, and instantaneous removal on message send or input clear.

2. **Concurrent Multi-Party Group Voice (Audio) & Video Calling**:
   - Multi-peer WebRTC mesh signaling allowing multiple users to connect simultaneously in a group voice or video call.
   - Live in-chat banner for ongoing group calls showing active participant count and a 1-click **"Join Call"** button.
   - Interactive multi-grid layout (responsive 1-tile, 2-tile, 4-tile, 6+ tile grid with speaker spotlight and sidebar modes).
   - Active speaker detection with glowing visual rings and real-time audio volume visualizer bars.
   - Full in-call controls: Mute/Unmute microphone, Camera Toggle (Video On/Off), Screen Sharing, Speaker/Grid layout switch, Participants list drawer, and Leave/End Call.
   - Ringing & notification handling for all group members with audio chimes.

3. **Enterprise Standards**:
   - Database transactions (`DB::transaction`) on all state modifications.
   - Soft deletes (`SoftDeletes`) and audit logging (`AuditLogService::log`) for call and chat actions.
   - Mobile responsive UI with full Light and Dark mode styling.
   - Comprehensive error handling and multi-language translations (`en`, `bn`).
   - Automated Pest tests verifying all typing and multi-party calling features.

---

## User Review Required

> [!NOTE]
> WebRTC group calling uses multi-peer mesh signaling over WebSockets (`WebRtcCallSignalEvent` / Laravel Reverb / Pusher), requiring no external paid SFU server. It supports concurrent group audio and video calling directly in browser.

---

## Proposed Changes

### Backend & Broadcasting Layer

#### [MODIFY] [ChatUserTypingEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/ChatUserTypingEvent.php)
- Enhance `ChatUserTypingEvent` to broadcast on `PrivateChannel("conversation.{$this->conversationId}")` and `PrivateChannel("user.{$userId}")`.
- Include sender details: `conversation_id`, `user_id`, `user_name`, `user_avatar`, and `is_typing`.

#### [MODIFY] [WebRtcCallSignalEvent.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Events/WebRtcCallSignalEvent.php)
- Ensure room-level broadcasting when `recipientUserId` is 0 or null (so group call signals like `participant_joined`, `participant_left`, `call_ended`, `active_call_ping` reach all peers in the `call.{callUuid}` channel).

#### [MODIFY] [ChatService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/ChatService.php)
- Add `broadcastTypingIndicator(ChatConversation $conversation, User $user, bool $isTyping = true): void` method with permission validation (checking if user is a member and can post in channel).

#### [MODIFY] [WebRtcCallService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php)
- Upgrade `initiateGroupCall`, `acceptCall`, `rejectCall`, `leaveCall`, and `endCall` to handle concurrent group participants.
- Add `joinGroupCall(string $callUuid, User $user)` for joining an ongoing group call.
- Add `getActiveGroupCall(int $conversationId)` to retrieve the active call and joined participants for banner display.

---

### Frontend & Livewire UI Layer

#### [MODIFY] [chat-and-media.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- Upgrade `chatCallOverlayAlpine`:
  - Support multi-peer WebRTC mesh `peers: { [userId]: { pc, stream, isMuted, isVideoOff, audioLevel, isSpeaking } }`.
  - Handle multi-party SDP offer/answer exchange, ICE candidate routing, and stream attachment.
  - Implement active speaker audio analysis and responsive layout modes (Grid, Speaker Spotlight, Sidebar).
  - Handle screen sharing in group calls.
- Add typing helper logic to dispatch debounced typing events and maintain real-time typing indicators in Alpine.

#### [MODIFY] [⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php)
- Add multi-user video tile grid and audio tile cards.
- Add active speaker indicators, participant count badge, mute/camera badges, and responsive light/dark design.
- Support `join-group-call`, `leave-group-call`, and group call event listeners.

#### [MODIFY] [⚡chat.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php)
- Add typing indicator listeners on Echo channels (`ChatUserTyping`).
- Render typing indicators in:
  1. Chat header status subtext (animated 3 bouncing dots + user names).
  2. Bottom floating bubble above composer.
  3. Conversation list sidebar item preview.
- Add ongoing group call banner in header with active participant avatars, count, and **"Join Call"** button.
- Debounced `@input` / `@keydown` dispatching on message textarea.

---

### Localization & Quality Assurance

#### [MODIFY] [en.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json) & [bn.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json)
- Add translation strings for typing indicators, group calling states, and multi-user actions.

#### [MODIFY] [ChatSystemTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/ChatSystemTest.php)
- Add tests for typing indicator dispatching and reception in 1-on-1, group, and channel chats.
- Add tests for initiating, joining, multi-peer signaling, and leaving group voice & video calls.

#### [NEW] [2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-30-realtime-typing-indicator-and-group-webrtc-calling.md)
- Complete changelog documenting all additions.

---

## Verification Plan

### Automated Tests
- Run `php artisan test --compact --filter=ChatSystemTest` to verify all chat, typing, and group calling tests pass.
- Run `vendor/bin/pint --format agent` to ensure strict PSR-12 / Laravel coding standard compliance.

### Manual / Browser Verification
- Verify typing indicator pulses when typing in 1-on-1 and group chats.
- Verify ongoing group call banner appears and opens multi-grid call overlay.
- Verify light and dark mode styles and mobile responsiveness.
