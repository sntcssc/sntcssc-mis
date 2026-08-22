# UI Fixes — Modal centering + page section spacing

**Date:** 2026-08-22
**Scope:** Follow-up fixes to the UI conversion (`2026-08-22-nextjs-template-ui-conversion.md`): modals opening partially off-screen on every page, and cramped spacing between page header / toolbar / content sections.

---

## 1. Modals rendered off-center with parts cut off (all pages)

**Root cause:** the modal entrance animation (`ui-content-in` keyframes in `app.css`) animated the CSS `transform` property to `translate(-50%, -50%) scale(1)`, while the dialog is centered with Tailwind's `-translate-x-1/2 -translate-y-1/2` utilities (which use the individual `translate` CSS property). With the animation's `both` fill-mode persisting its final keyframe, **both transforms compounded** — the dialog ended up shifted up and to the left by its own half-width/height, so its top-left region went off-screen on every page.

**Fixes:**

| File | Change |
|---|---|
| `resources/css/app.css` | Keyframes `ui-content-in` / `ui-popover-in` / `ui-toast-in` now animate only `opacity` and the individual `scale` / `translate` properties — never `transform` — so they can never compound with Tailwind's centering utilities. Comment added documenting the constraint. |
| `resources/views/components/ui/modal.blade.php` | Restructured: the dialog is now a `flex-col` with a **pinned close button** (absolute, `z-10`, always visible — previously it scrolled away with long content) and an inner `overflow-y-auto` scroll area; height capped at `max-h-[calc(100vh-4rem)]` and width `w-[calc(100%-2rem)]` so the modal never touches screen edges on mobile. |

Verified in browser: New-student modal perfectly centered on desktop (1280px) and mobile (390px), internal scrolling reaches the Save button, close button stays visible, overlay stays fixed behind.

## 2. Page sections cramped (header / toolbar / stat cards / table touching)

**Root cause:** the template gets its vertical rhythm from `main`'s `space-y-4 sm:space-y-6`, which only applies between *direct children* of `main`. The dashboard renders its sections directly into the layout slot (so it was fine), but every Livewire page must wrap all content in a single root `<div>` — so `main`'s spacing never reached the page's sections and they stacked with **zero gap**.

**Fixes:**

| File | Change |
|---|---|
| `resources/views/pages/admin/⚡*.blade.php` (all 11 pages: students, admissions, enrollments, courses, batches, tests, users, roles, reports, reports-saved, profile) | Root div now has `class="space-y-4 sm:space-y-6"` — restoring the template's section rhythm (16px mobile / 24px ≥sm) between page header, stat cards, tables/cards, etc. |
| Same files | Toolbar rows (`New …` button, search, filter selects, refresh) gap increased `gap-2` → `gap-2.5` for comfortable breathing room, especially when wrapping on mobile. |

Verified in browser: students page (desktop + mobile) and admissions (stat cards + table) all show proper spacing; no horizontal overflow on 390px.

## 3. Verification

- `vendor/bin/pest` — **87 passed (179 assertions)**.
- `vendor/bin/pint --dirty` — clean.
- Browser checks (desktop 1280px + mobile 390px): modal centering/scroll/close, section spacing, toolbar wrapping.
