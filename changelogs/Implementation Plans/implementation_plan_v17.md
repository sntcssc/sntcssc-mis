# Implementation Plan - Alpine.js & Livewire 4 SPA Navigation Lifecycle & Background Tasks Cleanup

Resolve Alpine.js loading race conditions, continuous background timers/intervals, unhandled event listener leaks, and proxy undefined errors during Livewire SPA (`wire:navigate`) sidebar transitions. Ensure enterprise-grade standards including DB transactions, soft deletes, audit logging, error handling, mobile responsiveness, dark mode, and multi-language translations.

## User Review Required

> [!IMPORTANT]
> - Livewire 4 bundles Alpine.js internally and loads asynchronously relative to the `<head>` bundle. We are implementing a robust multi-stage initialization hook (`window` global registration, `alpine:init`, `livewire:init`, `livewire:initialized`, and `livewire:navigated`) alongside native Alpine `$cleanup` lifecycle management.
> - Background timers (ringtone, call timer, audio volume meters, notification chimes) and media streams (camera/mic hardware) will be automatically destroyed and released whenever an Alpine component is unmounted or when navigating away, preventing lingering processes and hardware lockups.

## Proposed Changes

Grouped by component layer:

---

### JavaScript & Alpine.js Lifecycle Engine

#### [MODIFY] [chat-and-media.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js)
- **Universal Alpine Registration**: Guarantee `registerChatAndMediaComponents()` registers components on `window` and `Alpine.data()` across all lifecycle stages (`alpine:init`, `livewire:init`, `livewire:initialized`, `livewire:navigated`, and direct invocation).
- **Native `$cleanup` Hooks**: Wire up `this.$cleanup(() => this.destroy())` across all 5 Alpine components:
  1. `chatAlpine`: cleanup event listeners, scroll observer, and timeouts.
  2. `chatCallOverlayAlpine`: cleanup WebRTC peer connection, local/remote streams, ringtone audio context, ringtone interval, call duration timer, and event listeners.
  3. `chatPreCallPreviewAlpine`: cleanup audio meter analyser, `requestAnimationFrame`, audio context, media streams, and event listeners.
  4. `meetingRoomAlpine`: cleanup media tracks, screen share stream, floating emoji timers, and event listeners.
  5. `alpineImageEditor`: cleanup canvas references, text/emoji overlays, and event listeners.
- **Defensive `$wire` & `$refs` Guards**: Ensure getters, setters, and method invocations check for `$wire` existence, preventing `"Cannot read properties of undefined"` during Livewire DOM morphing.
- **AudioContext Safe Wrappers**: Guard `AudioContext` against browser autoplay restrictions and prevent unhandled promise rejections on unmount.

#### [MODIFY] [app.js](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/app.js)
- Ensure all stores (`theme`, `modals`, `toasts`) and component registrations initialize cleanly across `alpine:init`, `livewire:init`, and `livewire:navigated`.

---

### Layout & Livewire Components

#### [MODIFY] [⚡notification-bell.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡notification-bell.blade.php)
- Replace `@entangle` strings inside `x-data` with safe Alpine reactivity / `$wire` properties to prevent entangle proxy crashes during SPA transitions.
- Add `$cleanup` hook for sound chimes and browser notification watchers.

#### [MODIFY] [⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php)
- Ensure background signal sync and incoming call checks run safely without polling detached elements.
- Add try-catch and null-safety for all signal dispatching.

---

### Backend Services, Security & Audit Logging

#### [MODIFY] [ChatService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/ChatService.php) & [WebRtcCallService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/WebRtcCallService.php)
- Confirm all actions are encapsulated in `DB::transaction()`.
- Ensure comprehensive try-catch exception handling with user-friendly translations.
- Verify audit log emission via `AuditLogService::log()`.

---

### Documentation & Changelog

#### [NEW] [2026-08-28-alpine-livewire-spa-lifecycle-and-background-cleanup.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-28-alpine-livewire-spa-lifecycle-and-background-cleanup.md)
- Complete summary of all architectural fixes, lifecycle enhancements, and enterprise standards.

---

## Verification Plan

### Automated Tests
- Run all chat, meeting, notification, and auth Pest test suites:
  `php artisan test --compact`
- Format code using Pint:
  `vendor/bin/pint --format agent`
- Build frontend assets:
  `npm run build`

### Manual Verification
- Test sidebar SPA navigation between pages (`wire:navigate`) to verify zero console errors (`not defined`, null reference, or unhandled promise rejections).
- Verify call pre-preview and active call overlay clean up camera/mic hardware indicators immediately when navigating away.
- Verify notification bell and chat polling work smoothly without lingering timers.
