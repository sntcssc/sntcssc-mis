# UI Redesign — "Hyper POS" NextJS template → Laravel 13 + Livewire 4

**Date:** 2026-08-22
**Scope:** Complete replacement of the Flux UI design with the `nextjs-admin-dashboard` (Hyper POS) template design, implemented with pure Tailwind CSS v4 (no Flux components), fully mobile responsive, light + dark mode.

---

## 1. Summary

The entire UI layer of `sntcssc-mis` was redesigned to match the NextJS admin template pixel-for-pixel: emerald/slate light theme, zinc-based dark theme, Geist font, collapsible icon sidebar, sticky glass topbar, centered-card auth pages, and the template's canonical list/table/grid page recipes. All Flux component usage was removed from the views (the `livewire/flux` composer package is left installed but unused — it can be removed later with `composer remove livewire/flux` once you're confident nothing else references it).

Functional behavior (Fortify auth, teams, passkeys, 2FA, email verification) is unchanged; only the presentation layer was replaced. The Pest suite passes (87 tests / 179 assertions) after one test was updated to assert the new toast event instead of Flux's internal `toast-show` event.

---

## 2. Design system foundation

| File | Change |
|---|---|
| `resources/css/app.css` | **Rewritten.** Removed the Flux CSS import and Flux-specific base rules. Ported the template's exact design tokens: `:root` light + `.dark` CSS variables (`--background/-foreground/card/popover/primary/secondary/muted/accent/destructive/border/input/ring/sidebar*/chart-1..5`), `@theme inline` mappings to Tailwind utilities, radius scale (`--radius: 0.625rem` → `sm/md/lg/xl`), extra `xs` breakpoint (475px), thin 6px theme-aware scrollbar, `[x-cloak]` base rule, and CSS keyframe replacements for `tw-animate-css` (overlay/content/popover/toast entrance animations). |
| `vite.config.js` | Fonts switched from `Instrument Sans` to **Geist** (400/500/600/700) + **Geist Mono** (400/500) via Bunny Fonts. |
| `resources/js/theme.js` | **New.** `window.SntcsscTheme` manager: light/dark/system persisted in `localStorage('theme')`, toggles the `.dark` class + `color-scheme` on `<html>`, reacts to OS preference changes, dispatches `theme-changed` events. Replaces Flux's `$flux.appearance` / `@fluxAppearance`. |
| `resources/js/app.js` | **Rewritten** (was empty). Imports `theme.js`; registers Alpine stores: `theme` (3-way appearance), `modals` (open/close/scroll-lock by name), `toasts` (auto-dismiss stack + Livewire `toast` event listener); bridges Livewire dispatches `modal-open` / `modal-close` to the Alpine modal store. |
| `resources/views/partials/head.blade.php` | Removed `@fluxAppearance`; added an inline anti-FOUC theme script that applies the stored/system theme before first paint; `passkeys.js` no longer loaded globally (loaded on demand by passkey components via `@assets`). |

### Token mapping (NextJS → this app)

| Token | Light | Dark |
|---|---|---|
| `primary` | `#059669` (emerald-600) | `#10b981` (emerald-500) |
| `background` | `#ffffff` | `#09090b` |
| `card` / `popover` | `#ffffff` | `#18181b` |
| `secondary` / `muted` | `#f1f5f9` (slate-100) | `#1f2937` / `#27272a` |
| `border` / `input` | `#e2e8f0` (slate-200) | `rgba(255,255,255,.08/.1)` |
| `sidebar` | `#f8fafc` | `#0f0f12` |
| `muted-foreground` | `#64748b` | `#a1a1aa` |

---

## 3. New reusable components

### PHP
| File | Purpose |
|---|---|
| `app/Support/LucideIcons.php` | **New.** 124 Lucide icon path definitions (extracted from `lucide-react@0.525` in the template project) used by `<x-icon>`. Zero npm dependency. |
| `app/Support/Toast.php` | **New.** Toast helper: `Toast::success/error/warning/info()` flashes to session (rendered by `<x-ui.toasts>`); `Toast::dispatch($component, type, message)` fires the Livewire `toast` event for instant feedback. Replaces `Flux::toast()`. |
| `app/Concerns/WithSamplePagination.php` | **New.** Paginates in-memory sample datasets as `LengthAwarePaginator` and points Livewire's pagination at the custom `partials/pagination` view. Swap the data source for Eloquent when modules get real tables. |

### Blade — icons & UI kit (`resources/views/components/`)
| Component | Notes |
|---|---|
| `icon.blade.php` | `<x-icon name="zap" class="h-4 w-4"/>` — inline Lucide SVG, overridable `stroke-width`. |
| `ui/button` | shadcn button ports: variants `default/destructive/outline/secondary/ghost/link`, sizes `default/sm/lg/icon/icon-sm`; renders `<a>` when `href` present. |
| `ui/input`, `ui/password`, `ui/select`, `ui/textarea`, `ui/checkbox`, `ui/switch` | Form controls with uppercase micro-labels, error/hint slots, eye-toggle password, peer-checked checkbox/switch — all Flux-free. |
| `ui/badge` | Status pill: `success/warning/danger/info/violet/secondary/outline` colors using the template's `bg-{color}-500/15 text-{color}-500` recipe. |
| `ui/avatar` | Emerald initials circle (or image). |
| `ui/dropdown` (+ `dropdown/item`, `dropdown/label`, `dropdown/separator`) | Alpine dropdown (click-outside, escape), popover entrance animation, `align`/`width`/`offset` props. |
| `ui/modal` | Alpine `$store.modals`-driven dialog: overlay + centered content, zoom/fade animation, optional title/description, close button, ESC handling. |
| `ui/otp` | The template's 6-box OTP input: auto-advance, backspace, arrow keys, paste support, filled-state emerald borders, hidden input (supports `wire:model` via `model` prop). |
| `ui/stat-card` | KPI card with icon tile / label / value. |
| `ui/theme-toggle` | Sun/moon floating toggle (used on guest pages). |
| `ui/toasts` | Bottom-right toast stack; boots from session flash; listens to Livewire `toast` events; per-type icons. |
| `chart/bar` | Grouped bar chart (current vs previous) as CSS columns with dashed gridlines, Y-axis labels and hover tooltips — replicates the recharts Performance chart. |
| `chart/donut` | SVG donut with padding gaps, center total and 2-col legend — replicates the recharts Category mix chart. |
| `chart/heatmap` | 7×24 CSS-grid heatmap with emerald opacity scale and Low→High legend. |

### Blade — other
| File | Purpose |
|---|---|
| `resources/views/partials/pagination.blade.php` | Custom Livewire pagination footer matching the template: "Rows per page" select, `n–m of total`, chevron buttons, `page / last` chip. |

---

## 4. Admin shell (replaces Flux chrome)

| File | Change |
|---|---|
| `resources/views/layouts/app.blade.php` | **Rewritten** as the full document shell (was a Flux wrapper): Alpine state for sidebar collapse (persisted in `localStorage`) + mobile drawer; `lg:ml-60`/`lg:ml-16` main column; `max-w-[1440px]` content; bordered footer (© SNT CSSC MIS · Documentation · Support · version dot); `<x-ui.toasts>` + `<livewire:create-team-modal/>`. |
| `resources/views/layouts/app/sidebar.blade.php` | **Rewritten** (was Flux sidebar): fixed `w-60`↔`w-16` collapsible desktop sidebar + mobile drawer with blurred backdrop; emerald logo tile + "SNT CSSC MIS"; grouped nav with 10px uppercase headers and submenu (Settings); "All systems online / v1.0.0" footer. MIS menu: **MAIN** Dashboard · **STUDENTS** Students, Admissions, Enrollments · **ACADEMICS** Courses, Batches, Tests & Selections · **USER & ACCESS MANAGEMENT** Users, Roles · **REPORTS** All reports, Saved reports · **SYSTEM** Profile, Settings (submenu: Profile settings / Security / Appearance / Teams). |
| `resources/views/layouts/app/topbar.blade.php` | **New.** Sticky `h-14` glass header: mobile hamburger, breadcrumbs (route-based), search button with kbd chip, **team switcher** (template's "store selector" analog), notifications dropdown (w-80, emerald count badge), user dropdown (profile header, Profile & preferences, Settings, 3-way Light/Dark/Auto appearance control, destructive Sign-out with `data-test="logout-button"` preserved). |
| `resources/views/layouts/app/header.blade.php` | **Deleted** (unused alternate top-nav layout). |

---

## 5. Auth pages (guest)

Layout `resources/views/layouts/auth/simple.blade.php` **rewritten**: `min-h-screen` centered `max-w-[440px]` card (`rounded-2xl p-8 sm:p-10 shadow-sm`), floating theme toggle top-left, © footer. `auth.blade.php` wrapper kept; unused `card`/`split` variants deleted. Hardcoded `class="dark"` removed (theme defaults to light/system like the template).

| Page | Change |
|---|---|
| `pages/auth/login` | Template login: logo tile + "Sign in", **Email/Mobile segmented switcher** (Alpine), uppercase labels, `h-11` inputs, mono password with eye toggle, remember-me + emerald forgot link, `h-[46px]` submit (`data-test="login-button"` kept), demo-credentials block (local env only), restyled passkey sign-in, mobile-OTP tab is visual-only (shows "SMS gateway not configured" notice). Fortify POST + `data-test`/field names unchanged. |
| `pages/auth/register` | Template card with full name / email / password + confirm; `data-test="register-user-button"` kept. |
| `pages/auth/forgot-password` | "Send reset link" + emerald success panel with "Send again". |
| `pages/auth/reset-password` | Template card; token flow unchanged. |
| `pages/auth/verify-email`, `confirm-password` | Same card recipe; logout/passkey behaviors kept. |
| `pages/auth/two-factor-challenge` | Template verify-OTP design: ShieldCheck tile, `<x-ui.otp>` 6-box input, recovery-code toggle preserved. |
| `pages/auth/verify-otp` | **New** visual-only page at `/verify-otp`: 6-box OTP, 30s resend countdown, verified success state. |
| `components/auth-header`, `auth-session-status`, `team-invitation-alert`, `passkey-verify`, `passkey-registration` | Restyled Flux-free (Alpine logic preserved). |

---

## 6. Dashboard & admin pages

`resources/views/dashboard.blade.php` — **rewritten** as the template dashboard with MIS data (sample): greeting + Live pill, "Today's fee collections" hero (₹1,83,871 + quick-stat chips), Operations card with amber/rose/cyan alert tiles, This-week mini card, Performance grouped bar chart, Course-mix donut, Enrollment insights (top courses / low-fill batches), Activity feed, admissions heatmap, staff sessions. Keeps `<livewire:pages::teams.pending-invitations-modal/>`.

New Livewire (Blaze `⚡`) pages under `resources/views/pages/admin/` — all follow the template's canonical list recipe (page header + count, `+ New` button, debounced search with clear button, filter selects, desktop table `min-w-[800px]` + mobile card list, template pagination footer, modals via `x-ui.modal`, toasts). In-memory CRUD works live; swap to Eloquent when tables exist:

| Page | Route | Highlights |
|---|---|---|
| `⚡students` | `/{team}/students` | Avatar rows, roll no, course/batch, status pills, create/edit/delete modals. |
| `⚡admissions` | `/{team}/admissions` | Application no, marks A–D + total, List A/B badges, view modal with Select/List-B actions, 4 KPI stat cards. |
| `⚡enrollments` | `/{team}/enrollments` | Student + course/batch, paid amount, fee-status pills. |
| `⚡courses` | `/{team}/courses` | **Dual table/grid view** like the products page (grid cards with hover lift, view toggle, category filter). |
| `⚡batches` | `/{team}/batches` | Seat-fill progress bars, timing, status, stat cards. |
| `⚡tests` | `/{team}/tests` | Test results with avg/highest, List A/B counts, stat cards. |
| `⚡users` | `/{team}/users` | Role/status badges, last login, create/edit/delete (sample data — wire to real users + spatie in the RBAC phase). |
| `⚡roles` | `/{team}/roles` | Role list + permission groups with switches (design preview for spatie RBAC). |
| `⚡reports` | `/{team}/reports` | Categorized report catalog with search + "Popular" flags. |
| `⚡reports-saved` | `/{team}/reports/saved` | Saved/scheduled reports table. |
| `⚡profile` | `/{team}/profile` | Template profile design; profile-info + password forms wired to **real logic**; sessions/preferences cards visual. |

`resources/views/errors/404.blade.php` — **new**, template not-found design (amber file-question tile, ghost "404", dashboard/back buttons).

---

## 7. Settings & teams restyle (Flux → x-ui, logic preserved)

| File | Change |
|---|---|
| `partials/settings-heading` | Template page header (title + subtitle + hairline). |
| `pages/settings/layout` | Side navlist → **horizontal segmented tab bar** (Profile / Security / Teams / Appearance) + section heading. |
| `pages/settings/⚡profile` | Card form with x-ui inputs; real update + verification-resend logic; `data-test="update-profile-button"` kept. |
| `pages/settings/⚡security` | Password card, 2FA section (Enable/Disable + setup modal + recovery codes), passkeys list — all logic and test-asserted texts kept (`Update password`, `Two-factor authentication`, `Passkeys`, `No passkeys yet`, `Enable 2FA`). |
| `pages/settings/⚡appearance` | Flux radio bound to `$flux.appearance` → our Alpine `$store.theme` segmented control. |
| `pages/settings/⚡two-factor-setup-modal` | x-ui modal; QR + manual key + `<x-ui.otp>` verification step; copy-to-clipboard kept. |
| `pages/settings/two-factor/⚡recovery-codes` | Card with show/hide + regenerate. |
| `pages/settings/⚡delete-user-form` + `delete-user-modal` | Rose danger zone card + x-ui confirm modal. |
| `pages/teams/⚡index` / `⚡edit` | Template cards, role dropdown (x-ui.dropdown), invite/remove/delete modals restyled; all `data-test` attributes and authorization logic preserved. |
| `pages/teams/⚡invite-member-modal`, `remove-member-modal`, `cancel-invitation-modal`, `delete-team-modal`, `pending-invitations-modal` | Flux modals → `x-ui.modal`; toasts → `Toast::dispatch`. |
| `components/⚡team-switcher` | Restyled as the topbar "TEAM · name" outline dropdown; switch-team logic unchanged. |
| `components/⚡create-team-modal` | x-ui modal + our toast. |

### Deleted Flux-era files
- `resources/views/flux/` (published Flux icon overrides + navlist group)
- `components/app-logo.blade.php`, `components/app-logo-icon.blade.php`, `components/desktop-user-menu.blade.php`, `components/placeholder-pattern.blade.php`
- `layouts/auth/card.blade.php`, `layouts/auth/split.blade.php`

---

## 8. Routes & config

- `routes/web.php`: settings routes now load **before** the `{current_team}` group (otherwise `/{current_team}/profile` captures `/settings/profile`); added `/verify-otp` (guest view) and 11 admin Livewire routes (`admin.students.index`, `admin.admissions.index`, `admin.enrollments.index`, `admin.courses.index`, `admin.batches.index`, `admin.tests.index`, `admin.users.index`, `admin.roles.index`, `admin.reports.index`, `admin.reports.saved`, `admin.profile.show`) inside the existing `auth + verified + EnsureTeamMembership` group.
- `.env`: `APP_NAME="SNT CSSC MIS"` (was `Laravel`).

---

## 9. Tests

- `tests/Feature/Teams/TeamInvitationTest.php`: updated one assertion from Flux's internal `toast-show` event to our public `toast` event (`type: 'success'`).
- `tests/Feature/AdminPagesTest.php`: **new** — guest redirect + authenticated 200 for every new admin page, and guest rendering of `/verify-otp`.
- Result: **87 passed (179 assertions)**; `vendor/bin/pint --dirty` clean; `npm run build` succeeds.

---

## 10. Notes & follow-ups

1. **Flux package**: no longer referenced by any view. Run `composer remove livewire/flux` when convenient (kept installed to avoid a dependency change without approval).
2. **Sample data pages**: students/admissions/enrollments/courses/batches/tests/users/roles/reports use in-memory arrays with working search/filter/pagination/CRUD. When you build each module's database tables (users-table fields, RBAC, etc. from your todo list), replace the `items` arrays with Eloquent queries and keep the same components/pagination.
3. **Mobile OTP sign-in**: UI is present but disabled pending an SMS gateway; the login mobile tab shows a friendly notice.
4. **Charts** are server-rendered SVG/CSS (no JS chart library needed) — data arrays live in each page/component.
5. **Dark mode** persists per browser (`localStorage 'theme'`), respects OS preference when set to Auto, and no longer hardcodes dark.
