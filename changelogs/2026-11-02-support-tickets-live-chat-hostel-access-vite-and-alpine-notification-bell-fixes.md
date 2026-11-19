# Changelog: Support Tickets, Live Chat & Meetings Permissions, Hostel Access, WebSocket Polling Fallback, Vite Preload & Alpine Notification Bell Fixes

**Date:** 2026-11-02  
**Type:** Bug Fix / Security & Access Control / Performance Optimization / Frontend Stability Upgrade  

---

## Summary of Changes

### 1. Support Tickets & Helpdesk: Fixed Undefined Relationship `[enrollments]`
- **Root Cause:** In [`resources/views/pages/admin/tickets/⚡show.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/tickets/⚡show.blade.php), opening a support ticket triggered:
  `Call to undefined relationship [enrollments] on model [App\Models\StudentProfile]`.
  The view attempted to query `$ticket->user?->studentProfile?->enrollments`, but the Eloquent relationship `enrollments()` / `studentEnrollments()` is defined on the `App\Models\User` model, not directly on `StudentProfile`.
- **Solution:**
  - Updated the relationship reference to query `$ticket->user?->studentEnrollments` with eager loading.
  - Added null-safe fallback handling for tickets submitted by non-student users or guests.

---

### 2. Live Chat & Online Meetings: Feature Flag & Sidebar Permission Access Control
- **Root Cause:** When Live Chat or Online WebRTC Meetings features were globally disabled in System Settings (`Setting::get('chat.live_chat_enabled')` or `Setting::get('meetings.online_meetings_enabled')`), the sidebar navigation items and routes were still accessible to unauthorized roles and students.
- **Solution:**
  - Added strict feature-enablement and permission guards in [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php) and topbar navigation.
  - Sidebar menu items for Live Chat and Meetings are now conditionally rendered only if the feature is globally active AND the authenticated user possesses the corresponding RBAC permission.
  - Guarded the portal Livewire components ([`⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php) and [`⚡meetings.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meetings.blade.php)) with `abort_unless(..., 403)` when disabled.

---

### 3. Student Hostel Access & Form Download Restrictions
- **Root Cause:** Students without active hostel room allocations or accommodation history could see the Hostel menu in their portal dashboard and access the hostel download form.
- **Solution:**
  - Updated [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php) to verify whether the logged-in student has an active hostel allocation or historical record before showing the Hostel section in the student portal sidebar.
  - In [`app/Http/Controllers/Hostel/HostelFormDownloadController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/Hostel/HostelFormDownloadController.php), restricted hostel form downloads exclusively to students with active, approved hostel accommodations (`HostelStudentAllocation::where('student_id', ...)->where('status', 'active')->exists()`), returning a localized 403 error for unaccommodated students.

---

### 4. Realtime Transport Fallback: Graceful Polling when WebSocket Server is Stopped
- **Root Cause:** When the Laravel Reverb WebSocket daemon was stopped and the "Chat Realtime Driver" was set to `"polling"` in application settings, the browser continued trying to establish a WebSocket connection to `wss://localhost:8080/app/...`, resulting in console connection failure errors.
- **Solution:**
  - Updated [`resources/views/partials/head.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/partials/head.blade.php) to check the active realtime transport driver before injecting WebSocket configuration metadata.
  - In [`resources/js/echo.js`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/js/echo.js), introduced a safe `NullEcho` fallback stub. When the driver is configured as `"polling"` or credentials are absent, `window.Echo` initializes cleanly as a no-op client without opening unwanted WebSocket connections or spamming browser logs.

---

### 5. Fixed `Undefined variable $transportDriver` in Admin Panel
- **Root Cause:** When accessing `/admin/chat` or `/portal/meetings`, a PHP error was thrown:
  `Undefined variable $transportDriver` in [`⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php) and [`⚡meeting-room.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meeting-room.blade.php).
- **Solution:**
  - Added `'transportDriver' => $transportDriver` to the view data returned by the component's `with()` method, resolving the driver configuration from application settings (`Setting::get('chat.realtime_driver', 'hybrid')`).

---

### 6. Message Sending Latency Optimization
- **Root Cause:** When sending messages in Live Chat, a noticeable delay occurred even with the Reverb WebSocket server running. Investigation revealed three bottlenecks:
  1. Keystroke typing events fired synchronous Livewire HTTP calls on every input change.
  2. The message broadcast event was dispatched inside the database transaction, blocking execution until the socket handshake completed.
  3. Notifications for conversation participants were inserted sequentially one by one.
- **Solution:**
  - In [`app/Services/ChatService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/ChatService.php), decoupled the Reverb broadcast from the database transaction by committing the transaction first before firing `MessageSentEvent`.
  - Converted per-recipient notification creation to bulk `insert()` queries.
  - In [`resources/views/pages/portal/⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php), debounced typing indicators client-side and added immediate optimistic UI feedback on send.

---

### 7. Fixed Chromium Unused Vite CSS Preload Console Warning
- **Root Cause:** The browser console reported:
  `The resource http://127.0.0.1:8000/build/assets/app-CX5918y-.css was preloaded using link preload but not used within a few seconds from the window's load event. Please make sure it has an appropriate as value and it is preloaded intentionally.`
  By default, Laravel's Vite tag generator emitted both `<link rel="preload" as="style" href="...">` and `<link rel="stylesheet" href="...">`. Because modern Chromium loads stylesheets synchronously via `rel="stylesheet"`, the separate preload link went unconsumed, prompting the browser warning.
- **Solution:**
  - In [`app/Providers/AppServiceProvider.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Providers/AppServiceProvider.php#L131-L141), added `Vite::usePreloadTagAttributes()` to return `false` for CSS stylesheets matching `\.(css|less|sass|scss|styl|stylus|pcss|postcss)`.
  - Suppressed redundant `<link rel="preload" as="style">` while preserving `<link rel="stylesheet">` and `<link rel="modulepreload">` for scripts.
  - Added automated test in [`tests/Feature/ViteAssetPreloadTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/ViteAssetPreloadTest.php).

---

### 8. Fixed Notification Bell Alpine.js Errors (`Illegal invocation` & `drawerOpen is not defined`)
- **Root Cause:**
  1. In [`resources/views/components/⚡notification-bell.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡notification-bell.blade.php), `init()` attempted to watch `$wire` using Alpine's watcher: `this.$watch('$wire.unreadCount', (val) => { ... })`.
  2. Because `$wire` is an Alpine magic property (not a property of `this`), Alpine's dot-splitter evaluated `this['$wire']['unreadCount']`, which threw `TypeError: Cannot read properties of undefined (reading 'unreadCount')`.
  3. When `init()` crashed, Alpine aborted component initialization. When child directives evaluated the uninitialized variable:
     - When named `open`, it looked up `window.open` (a native browser function) and invoked it detached from `window`, throwing `Alpine Expression Error: Illegal invocation  Expression: "open"`.
     - When renamed to `drawerOpen`, it threw `Alpine Expression Error: drawerOpen is not defined`.
- **Solution:**
  - Completely cleaned up `x-data` in [`resources/views/components/⚡notification-bell.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡notification-bell.blade.php#L145-L215) to use clean, synchronous state: `{ drawerOpen: false, playChime() { ... } }`.
  - Removed crashing `this.$watch('$wire.unreadCount')`, `$cleanup` hooks, and redundant getters.
  - Moved unread count change detection into the component's server-side `rendering()` lifecycle method, dispatching `@play-notification-chime.window` cleanly when new notifications arrive.
  - Added automated assertions to [`tests/Feature/NotificationSystemTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/NotificationSystemTest.php).

---

## Files Modified & Added

| File | Status | Description |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | Modified | Suppressed redundant Vite CSS preload links via `Vite::usePreloadTagAttributes()`. |
| `resources/views/components/⚡notification-bell.blade.php` | Modified | Fixed Alpine `x-data` scope, eliminated crashing `$watch`, and renamed open variable to `drawerOpen`. |
| `resources/views/pages/admin/tickets/⚡show.blade.php` | Modified | Fixed relationship lookup from `studentProfile->enrollments` to `user->studentEnrollments`. |
| `resources/views/layouts/app/sidebar.blade.php` | Modified | Added feature and permission checks for Live Chat, Meetings, and Student Hostel access. |
| `resources/views/partials/head.blade.php` | Modified | Conditioned WebSocket meta tags on active transport driver. |
| `resources/js/echo.js` | Modified | Added safe `NullEcho` stub fallback for polling transport mode. |
| `resources/views/pages/portal/⚡chat.blade.php` | Modified | Passed `$transportDriver` and optimized typing indicators. |
| `resources/views/pages/portal/⚡meeting-room.blade.php` | Modified | Passed `$transportDriver` to view payload. |
| `app/Services/ChatService.php` | Modified | Decoupled message broadcasting from DB transaction and bulk-inserted participant notifications. |
| `tests/Feature/ViteAssetPreloadTest.php` | Added | Pest feature test ensuring Vite stylesheet tags render without redundant preload warnings. |
| `tests/Feature/NotificationSystemTest.php` | Modified | Added assertions ensuring `notification-bell` renders `x-show="drawerOpen"` without errors. |
| `tests/Feature/LiveChatMeetingsAndHostelSettingsAccessTest.php` | Added | Feature test verifying settings and permissions access control across chat, meetings, and hostel. |

---

## Verification & Testing

- **Pest Test Suite:**
  - `php artisan test --compact tests/Feature/ViteAssetPreloadTest.php` (2 passed, 6 assertions)
  - `php artisan test --compact tests/Feature/NotificationSystemTest.php` (9 passed, 81 assertions)
  - `php artisan test --compact tests/Feature/LiveChatMeetingsAndHostelSettingsAccessTest.php` (12 passed, 52 assertions)
  - Total: **23 passed, 139 assertions, 0 errors**.
- **Laravel Pint:** Executed `vendor/bin/pint --format agent` with all files conforming to project standards.

