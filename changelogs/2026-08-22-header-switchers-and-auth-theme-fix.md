# Feature — Header language & theme switchers + auth theme toggle fix

**Date:** 2026-08-22
**Scope:** Language switcher and light/dark/system theme control added to the admin dashboard header; fixed the theme toggle not working on the login/registration (guest) pages.

---

## 1. Fixed: theme toggle did nothing on login / register pages

**Root cause:** the guest pages are plain Blade pages with no Livewire components, and Livewire only auto-injects its JavaScript (which bundles Alpine) on pages that render Livewire components. After the Flux removal, nothing loaded Alpine on the auth pages — so the theme store registration and the toggle's `x-on:click` never ran. (The passkey button on login had the same latent problem.)

**Fixes:**

| File | Change |
|---|---|
| `resources/views/layouts/auth/simple.blade.php` | Added `@livewireScripts` — Alpine (theme toggle, toasts, OTP input, passkey buttons) and `wire:navigate` now work on all guest pages. |
| `resources/views/components/ui/theme-toggle.blade.php` | The floating sun/moon toggle now uses a plain `onclick="window.SntcsscTheme.toggle()"` handler instead of Alpine — it works on any page even without Livewire/Alpine. Icon states remain pure CSS (`dark:hidden` / `dark:block`). |

Verified in browser: clicking the top-left toggle on `/login` switches the page to dark mode and back.

## 2. Header theme switcher (light / dark / system)

| File | Change |
|---|---|
| `resources/views/components/ui/theme-switch.blade.php` | **New** compact 3-icon segmented control (sun / moon / monitor) in a bordered pill, matching the template's aesthetic. Active mode is highlighted; all three controls (header switcher, user-menu appearance control, settings → Appearance page) stay in sync through the shared Alpine `theme` store. |

Placed in the topbar (`layouts/app/topbar.blade.php`) between the team switcher and the notifications bell.

## 3. Header language switcher (English / हिन्दी / বांলা)

A fully functional locale switcher — not just UI:

| File | Change |
|---|---|
| `resources/views/components/locale-switcher.blade.php` | **New** globe dropdown (`w-56`) styled like the template's language menu: native name + "English · en"-style subtitle, emerald check on the active language. Shows the current locale code on `xs+` screens, icon-only on the smallest. |
| `app/Http/Middleware/SetAppLocale.php` | **New** — applies the chosen locale (session, falling back to a 1-year cookie so logged-out visitors keep their choice) to every request. `SUPPORTED = ['en', 'hi', 'bn']`. |
| `app/Http/Controllers/LocaleController.php` | **New** — `POST /locale`: validates the locale against the supported list, stores it in session + queues the cookie, redirects back. |
| `routes/web.php` | Added `Route::post('locale', LocaleController::class)->name('locale.switch')`. |
| `bootstrap/app.php` | `SetAppLocale` appended to the `web` middleware group. |
| `lang/hi.json`, `lang/bn.json` | **New** starter translations (~45 common strings each: sidebar groups/items, auth labels, common buttons). Keys are the English `__()` strings, so anything untranslated falls back to English automatically — extend these files as you translate more strings. |

Verified in browser: switching to हिन्दी translates the sidebar (डैशबोर्ड, छात्र, प्रवेश…), persists across full page reloads, and the choice carries across admin + guest pages in the same session.

## 4. Tests

- `tests/Feature/LocaleTest.php` — **new**: locale switch persists and translates the dashboard, unsupported locales are rejected, and the long-lived cookie is queued.
- Full suite: **90 passed (187 assertions)**; `vendor/bin/pint --dirty` clean.
