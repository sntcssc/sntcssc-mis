# Changelog: SPA Navigation Lifecycle, Event Listener Cleanup & Blank Tab Elimination

**Date:** 2026-08-27  
**Type:** Bug Fix / Enterprise Feature Upgrade / UX Enhancement  

---

## Summary of Changes

### 1. Fixed SPA Navigation Console Errors & Component Lifecycle
- **Root Cause:** When navigating via `wire:navigate` without page reload, Alpine component `init()` methods previously registered persistent `window.addEventListener()` handlers without unbinding them on unmount. Successive navigations accumulated multiple instances of window listeners that attempted to access unmounted DOM references and proxy bindings, generating Livewire and Alpine console errors.
- **Solution:**
  - Implemented tracked listener binding (`bindListener` / `clearAllListeners`) across `chatAlpine`, `chatPreCallPreviewAlpine`, `chatCallOverlayAlpine`, `meetingRoomAlpine`, and `alpineImageEditor` in [`resources/js/chat-and-media.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/chat-and-media.js).
  - Automatically unbinds and cleans up all media streams, timers, audio contexts, animation frames, and window listeners on `destroy()` and `livewire:navigating`.
  - Added null-safe guards across all DOM `$refs` to ensure transition and unmount operations execute safely.

---

### 2. Fixed Blank New Tab Auto-Opening on Sidebar Navigation
- **Root Causes:**
  1. In [`resources/views/pages/portal/⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php), the top action bar rendered an "Open In New Tab" link `<a href="..." target="_blank">` even when no conversation was active, which could be triggered by focus during SPA navigation.
  2. In [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php), collapsed sidebar items with submenus defaulted to `href="#"` with `wire:navigate`.
  3. In [`resources/views/components/⚡create-team-modal.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡create-team-modal.blade.php), an `autofocus` attribute inside the global layout modal triggered browser focus jumps on page transitions.
- **Solution:**
  - Added `@if ($activeConversation)` and `tabindex="-1"` to the external chat link.
  - Updated collapsed sidebar navigation links to fallback to the first child route rather than `#`.
  - Removed `autofocus` from hidden modal inputs.

---

### 3. Cleaned Global Livewire Call Overlay Entangles
- In [`resources/views/components/⚡chat-call-overlay.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php), changed `x-data="chatCallOverlayAlpine(@entangle(...))"` to `x-data="chatCallOverlayAlpine()"` with reactive `$wire` getters/setters, preventing entangle proxy collisions during SPA route changes.

---

### 4. Verification & Testing
- 100% test pass rate: **340 passed** (1,333 assertions, 0 failures) via Pest.
- Laravel Pint formatting verified.
- Vite production build compiled successfully.
