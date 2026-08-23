# Feature — Application-Wide Logo, Favicon, Dark Mode Contrast, Profile Photo Upload & Modern Landing Page

**Date:** 2026-08-23  
**Scope:** Complete branding synchronization across the application with solid high-contrast containers for transparent PNGs and JPGs in light/dark modes, profile photo upload with real-time preview on admin and user settings profile pages, database schema updates for user avatars, and a modern landing page redesign for the MIS platform.

---

## 1. High-Contrast Dark & Light Mode Logo Handling

**Files:**
- `resources/views/components/app-logo.blade.php`
- `resources/views/components/app-logo-icon.blade.php`
- `resources/views/components/auth-header.blade.php`

### Problem Solved:
Transparent PNG logos containing black or dark artwork/text became invisible when placed against dark mode backgrounds (`#0a0a0a` / `#161615`). Similarly, JPG logos with white backgrounds looked uncontained against dark backgrounds.

### Solution:
- Enclosed the logo and favicon images inside an elegant, solid white badge container (`bg-white p-1.5 shadow-xs border border-slate-200/80 dark:border-white/20 rounded-lg`).
- This ensures:
  1. **Transparent PNGs with black/dark text:** Crisp and readable on the white badge in dark mode.
  2. **JPGs with white backgrounds:** Seamlessly integrate into the container with no awkward edges.
  3. **Colored logos:** Pop with maximum vibrance and contrast.
- Refined image scaling (`h-7 max-w-[130px]` and `h-9 max-w-[160px]`) to maintain balanced proportions without oversized visuals.

---

## 2. Profile Photo Upload with Real-Time Preview

**Files:**
- `database/migrations/2026_08_23_133153_add_avatar_to_users_table.php`
- `app/Models/User.php`
- `resources/views/pages/admin/⚡profile.blade.php`
- `resources/views/pages/settings/⚡profile.blade.php`

### Capabilities Added:
1. **Database Schema:** Added nullable `avatar` column to `users` table.
2. **Model Integration:** Added `avatar` to `$fillable` and `User::avatarUrl(): ?string` resolving public storage URLs via `FileUploadService`.
3. **Livewire File Upload:**
   - Real-time temporary URL preview when a new photo is selected (`$avatarFile->temporaryUrl()`).
   - Automated storage in `storage/app/public/avatars` with sanitized naming and deletion of old avatars.
   - Upload spinner animation during file transfer.
   - One-click "Remove photo" action to delete the stored avatar and reset to initials.

---

## 3. Modern Welcome Landing Page & Theme Preset Synchronization

**File:** `resources/views/welcome.blade.php`

Replaced the legacy starter view with a modern, high-performance landing page tailored for the **SNT Civil Services Study Centre - Management Information System (MIS)**:

### Design & Functional Features:
- **System Theme Preset Sync:** Integrated `@include('partials.head')` which dynamically generates and applies active Appearance Color Combination Presets (`var(--primary)`, `bg-primary`, `text-primary`, `--radius`, `--font-family`), so whenever the admin switches theme presets (Emerald, Indigo, Sky Blue, Rose, Violet, Amber, Slate, etc.), the welcome page dynamically matches the exact color palette.
- **Theme Controls & Interactivity:** Added `@livewireScripts` to `welcome.blade.php` to initialize Alpine.js and the shared theme store, enabling instantaneous theme switching (Light, Dark, Auto/System) via `<x-ui.theme-switch/>` without full page reload.
- **Language Switcher:** Integrated `<x-locale-switcher/>` with active Alpine dropdown interactivity for multi-language toggle (English, Hindi, Bengali).
- **Lucide Icons Library Expansion (`app/Support/LucideIcons.php`):** Added complete SVG path definitions for `log-in`, `camera`, `sliders`, `sliders-horizontal`, `file-check`, `file-check-2`, `award`, `book`, `compass`, and `check-circle` so all module icons and action buttons render crisp vector graphics without missing placeholder spans.
- **Proportional Spacing:** Generous container paddings, responsive vertical rhythm (`py-16 sm:py-24 lg:py-28`), and balanced typography.
- **Multi-Language Support (English, Hindi, Bengali):** Added complete Hindi (`lang/hi.json`) and Bengali (`lang/bn.json`) translation dictionaries covering all welcome page headlines, badge pills, module descriptions, quick statistics, action buttons, and footer labels.
- **Hero Section:** Ambient background glows driven by dynamic primary theme colors, animated MIS badge pill, prominent headline, and primary call-to-action buttons.
- **Institutional Stats:** 4-card metric overview (Digital Admissions, Multi-Campus Management, Real-Time Analytics, Role Control).
- **Core Modules Grid:** 6 modern feature cards with dynamic primary-tinted icon containers detailing Student Lifecycle, Batches & Scheduling, Test Series & Evaluations, Financials & Fees, Role-Based Access, and System Configuration.
- **Footer:** Dynamic copyright notice via `Setting::copyrightText()`, application name, and navigation links.

---

## 4. User Profile & Preferences Synchronization

**Files:**
- `resources/views/pages/admin/⚡profile.blade.php`
- `resources/views/pages/settings/⚡profile.blade.php`

- Synchronized **Interface Language**, **Timezone**, **Date Format**, and **Time Format** with system localization settings.
- Saving preferences updates the `Setting` model, invalidates cache, and updates `session(['locale'])` and `app()->setLocale()` for immediate locale switching.

---

## 5. Automated Test Coverage

**File:** `tests/Feature/BrandAssetSyncTest.php`

14 automated Pest feature tests verifying:
1. `Setting::logoUrl()` returns `null` by default when no logo is uploaded.
2. `Setting::faviconUrl()` returns `/favicon.ico` by default with fallback, or `null` without fallback.
3. `Setting::logoUrl()` and `Setting::faviconUrl()` return valid public URLs when set.
4. Fallback from general to appearance settings when general keys are blank.
5. Dynamic resolution of `Setting::appName()`, `Setting::siteName()`, and `Setting::copyrightText()`.
6. `<x-app-logo>` Blade component fallback, custom logo rendering, and application name display beside logo.
7. `<x-app-logo-icon>` Blade component fallback and custom icon rendering with contrast container.
8. General settings upload and cross-sync to appearance settings.
9. Appearance settings upload and cross-sync to general settings.
10. Admin profile preferences saving and synchronization with system localization settings.
11. Settings profile preferences saving and synchronization with system localization settings.
12. User profile photo upload, real-time update, and removal on admin profile page.
13. Welcome landing page successful rendering with modern layout.
14. Welcome landing page multi-language translation rendering in Hindi and Bengali.

---

## 6. Verification Status

- **Pest Test Suite:** `189 passed (554 assertions)`
- **Pint Code Formatter:** `vendor/bin/pint --format agent` clean.
