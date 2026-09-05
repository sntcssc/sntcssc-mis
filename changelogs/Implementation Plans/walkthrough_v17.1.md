# Walkthrough: Alpine.js & Livewire 4 SPA Navigation Lifecycle & Background Tasks Cleanup

Fixed the Alpine.js loading race condition, unhandled background timers/intervals, event listener memory leaks, and proxy errors during Livewire SPA (`wire:navigate`) sidebar route transitions.

---

## Key Changes Made

### 1. Universal Alpine.js & Livewire 4 Lifecycle Engine
- **File:** [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
  - Registered all component factories directly onto `window` (`chatPreCallPreviewAlpine`, `chatCallOverlayAlpine`, `chatAlpine`, `meetingRoomAlpine`, `alpineImageEditor`).
  - Added multi-stage registration across `alpine:init`, `alpine:initialized`, `livewire:init`, `livewire:initialized`, and `livewire:navigated` to ensure components are always ready regardless of script loading order.
  - Added support for both standalone `window.Alpine` and Livewire-bundled `window.Livewire.Alpine`.

### 2. Automatic Lifecycle Cleanup via Native Alpine `$cleanup`
- **File:** [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
  - Integrated `this.$cleanup(() => this.destroy())` into the `init()` method of all 5 Alpine components.
  - Implemented tracked timer registration (`setTimeoutTracked`) and cleared all `setInterval`, `requestAnimationFrame`, and `window.addEventListener` references on element unmount.
  - Added safe audio context handling (`createSafeAudioContext()`) and automatic media stream track release (`stopMediaTracks()`) so hardware indicators (camera/microphone) turn off immediately upon navigation.
  - Added defensive `$wire` and `$refs` null-guards to prevent `"Cannot read properties of undefined"` during Livewire DOM morphing.

### 3. Resilient Global Stores & Layout Component Binding
- **Files:**
  - [`resources/js/app.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/app.js)
  - [`resources/views/components/⚡notification-bell.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡notification-bell.blade.php)
  - [`resources/views/components/⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php)
  - Protected Alpine stores (`theme`, `modals`, `toasts`) and modal bridges from duplicate bindings.
  - Eliminated raw `@entangle` strings in `notification-bell.blade.php`, replacing them with client-local state and reactive `$watch('$wire.unreadCount', ...)`.
  - Added `$cleanup` to notification chime and window notification handlers.

---

## Verification Results

### Frontend Assets
- Built clean production distribution via Vite (`npm.cmd run build`):
  - Assets compiled in **1.11s** with **0 errors**.

### Code Style & Standards
- Formatted via Laravel Pint (`vendor/bin/pint --format agent`): **Passed**.

### Automated Tests
- Chat, calls, meetings, notifications, and auth test suites passed with 100% success rate.
