# Fix — Dark mode lost when navigating between pages (wire:navigate)

**Date:** 2026-08-22
**Scope:** Theme selection (light/dark/system) reset to light on every SPA navigation; only a full browser reload restored it.

---

## Root cause

All internal links use `wire:navigate`. On navigation Livewire fetches the new page and syncs the `<html>` element's attributes with the **server-rendered** ones — which knew nothing about the chosen theme — so the `.dark` class (and `color-scheme` style) was wiped on every navigation. The choice was still in `localStorage`, which is why a full reload (where the anti-FOUC script in `<head>` runs again) restored dark mode.

## Fixes

| File | Change |
|---|---|
| `resources/js/theme.js` | 1) Re-applies the stored theme on `alpine:navigated` / `livewire:navigated` — the authoritative fix for the attribute wipe. 2) `set()` now also mirrors the choice into a `theme` cookie (1 year, samesite=lax) so the server can pre-render the correct class. |
| `resources/views/layouts/app.blade.php` | `<html>` now renders `class="dark"` when the `theme` cookie says `dark` — pages fetched by `wire:navigate` already match the selected theme (no light flash during the swap, correct first paint). |
| `resources/views/layouts/auth/simple.blade.php` | Same server-side `dark` class pre-render. |
| `resources/views/errors/404.blade.php` | Same server-side `dark` class pre-render. |

`localStorage` remains the source of truth (the inline head script still applies/corrects it on every full load); the cookie is a render-time hint that keeps SPA navigation consistent.

## Verification

Browser-tested end to end:

- Dark via header switcher → `wire:navigate` to **Students** → `html.dark` present, page dark.
- Further navigations **Students → Courses → Dashboard** → still dark.
- Switch to Light → navigate to **Users** → `html.dark` absent (light persists).
- Dark → navigate to **Roles** → dark; Light → navigate to **All reports** → light (locator check `html.dark` = 1 / 0 respectively).
- Dark + full reload → dark, now pre-rendered from the cookie.

Full suite: **90 passed (187 assertions)**; `vendor/bin/pint --dirty` clean.
