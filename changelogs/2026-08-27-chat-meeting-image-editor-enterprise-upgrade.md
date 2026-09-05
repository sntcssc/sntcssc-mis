# Changelog — Enterprise Upgrade: Chat, Meetings, Image Editor & System Robustness

**Date:** August 27, 2026  
**Status:** Completed & Verified (All 340 Automated Tests Passed)

---

## Overview

This update delivers extensive enterprise upgrades across authentication redirection, database uniqueness constraint handling, real-time live messaging, WebRTC online meetings, audio-visual controls, and rich media tools including a modern client-side Image Editor component.

---

## 1. Authentication & Redirection Robustness

- **Top-Level Fallback Route (`/dashboard`):** Added a root `/dashboard` redirect route in [routes/web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php) that identifies the user's current team or personal team and redirects smoothly to `/{team}/dashboard`, preventing any 404 page when authenticating or navigating.
- **Resilient Team Resolution:** Updated [RedirectsToCurrentTeam.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Responses/Concerns/RedirectsToCurrentTeam.php) to automatically fall back to the first available team or provision a personal team if an account lacks an active team mapping.

---

## 2. Enterprise Duplicate Value & Unique Constraint Handling

- **Phone Number Normalization Before Validation:** Updated [CreateNewUser.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Actions/Fortify/CreateNewUser.php) to normalize user-submitted mobile numbers (`SmsService::normalizePhoneNumber()`) before running `Rule::unique()`, aligning with database storage format and preventing validation bypass.
- **Graceful Duplicate Handling:** Wrapped registration in try/catch blocks converting raw database SQL collision exceptions into friendly validation errors (`"This phone number is already registered. Please log in or use another number."`).
- **Global Unique Constraint Exception Handler:** Added a global renderable exception handler in [bootstrap/app.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/bootstrap/app.php) for `UniqueConstraintViolationException` across the entire application, presenting clean, user-friendly feedback instead of raw 500 error screens.

---

## 3. Reusable Modern Canvas Image Editor Component

- **New Component [image-editor.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/image-editor.blade.php):**
  - **Cropping & Aspect Ratios:** Freeform, Square 1:1, Avatar Circle, 4:3 Standard, and 16:9 Landscape.
  - **Transformations:** 90° Clockwise Rotation, Horizontal Flip, and Vertical Flip.
  - **Drawing & Doodling:** Interactive canvas brush with color picker and stroke width slider.
  - **Text Overlays:** Custom text input, font size, and color customization.
  - **Stickers & Emojis:** Quick emoji sticker stamping with selectable sizes.
  - **History & Export:** Full Undo history and Base64 DataURL / Blob export events.
  - **Universal Event Dispatcher:** Listens for `open-image-editor` and dispatches `image-editor-saved` for easy plug-and-play across Chat, Profile Photo Uploads, and Settings logos/favicons.

---

## 4. Live Chat Enhancements

- **Emoji Reactions:**
  - Added database migration and Eloquent model [ChatMessageReaction.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ChatMessageReaction.php) with soft deletes and auditable author tracking.
  - Implemented `toggleReaction()` in [ChatService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/ChatService.php) with audit logging.
  - Interactive reaction bar popover on hover (`👍`, `❤️`, `😂`, `😮`, `😢`, `👏`, `🔥`, `🎉`).
  - Grouped reaction badge pills rendered below messages with counts, tooltips of reactors, and one-click toggle.
- **Sender Details & Direct Messaging:**
  - Clickable sender name and avatar in group/channel chats and Conversation Info Drawer.
  - Modal profile viewer showing user avatar, name, designation, phone, email, WhatsApp, and online status.
  - One-click "Direct Message" action initiating or opening a 1-on-1 direct chat.
- **Sound Notifications & Header Options:**
  - Synthesized audio chime using Web Audio API on new incoming messages with persistent mute toggle button.
  - **Open in New Tab** and **Fullscreen View** buttons added to the chat top bar.

---

## 5. WebRTC Online Meeting Room Enhancements

- **Pre-Join AV Check Lobby:**
  - Pre-join preview screen allowing users to preview their camera stream, test microphone, and configure mic/camera on/off states prior to entering the live room.
- **In-Meeting Chat Moderation:**
  - Host, Co-Host, and Super Admins can edit or delete any message inside the in-meeting chat.
  - Added room permission setting `allow_participant_edit_delete_chat` (disabled by default) allowing participants to edit and delete their own sent messages when enabled by host.
- **Customizable Meeting Link Slug:**
  - Hosts and Co-Hosts can customize and edit the meeting link slug / invite code (`/meetings/join/{custom-slug}`) in real-time with instant validation and clipboard copy.
- **Fullscreen, New Tab & Audio Chimes:**
  - Header controls for Fullscreen mode, Open in New Tab, and in-room chat audio chime notifications.

---

## 6. UI & Icon Fixes

- **Button Component Prop Support:** Updated [button.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/button.blade.php) to support `icon` and `iconPosition` properties, fixing missing icons across Notification Center, Inbox, and Action bars.
- **Lucide Icons Library:** Added missing SVG definitions to [LucideIcons.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Support/LucideIcons.php) including `crop`, `brush`, `rotate-cw`, `flip-horizontal`, `flip-vertical`, `sticker`, `type`, `crown`, `megaphone`, and `minimize-2`.

---

---

## 8. Browser Console Errors & SPA Blank Page Fixes

- **Alpine.js Directives & Scoping Isolation:**
  - Fixed `ReferenceError` warnings (`isFullscreen`, `mobileSidebarOpen`, `highlightedMessageId`, `activeTab`, `callStatus`) caused by `:class` directives evaluated on `x-data` host elements before property initialization.
  - Refactored `chatAlpine()`, `meetingRoomAlpine()`, `chatCallOverlayAlpine()`, and `alpineImageEditor()` with clean modular `Alpine.data()` script definitions and defensive initial fallbacks.
- **Resolved SPA Blank Page Navigation:**
  - Replaced dynamic `request()->fullUrl()` in "Open In New Tab" header links with canonical named route generators (`route('admin.chat.index', ['conversation' => ...])` and `route('meetings.room', ['uuid' => ...])`), preventing Livewire SPA internal update URLs (`/livewire/update`) from opening blank pages.
  - Enhanced `mount()` in `⚡chat.blade.php` to accept both query strings and direct route parameters (`$conversation`, `$c`).
- **Resolved Livewire Entangle Errors on Notification Bell:**
  - Fixed `Livewire Entangle Error: Livewire property ['unreadCount'] / ['soundEnabled'] cannot be found on component: ['notification-bell']` by converting dynamically computed `#[Computed]` methods to declared public Livewire properties (`public int $unreadCount = 0`, `public bool $soundEnabled = true`) synchronized seamlessly during `mount()` and `rendering()`.
- **Script Pre-Declaration & Lifecycle Initialization:**
  - Relocated Alpine component `<script>` registration blocks (`chatCallOverlayAlpine`, `chatAlpine`, `meetingRoomAlpine`, `alpineImageEditor`) directly above their respective DOM containers. This guarantees functions and Alpine definitions exist before Alpine executes the initial `x-data` parsing cycle, preventing uninitialized expressions or `ReferenceError` warnings during morphing or initial render.
