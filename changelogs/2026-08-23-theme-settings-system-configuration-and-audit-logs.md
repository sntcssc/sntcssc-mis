# Release Notes — Centralized Theme Settings, System Configuration, and Enterprise Audit Logging

**Date:** 2026-08-23  
**Scope:** Complete enterprise-grade suite for application branding, theme presets & custom palette injection, dynamic database-driven languages & translation string editor, maintenance mode with secret token bypass, and enterprise audit logging with diff inspection modal and multi-format exports.

---

## 1. Centralized Theme Settings & Dynamic Palette Theming

- **Preset Architecture (`App\Support\ThemePresets`):**
  - 8 curated enterprise color presets: *Emerald Classic*, *Royal Indigo*, *Ocean Blue*, *Teal Cyan*, *Crimson Rose*, *Sunset Amber*, *Violet Velvet*, and *Slate Monochrome*.
  - Full support for light and dark CSS variable generation (`--primary`, `--ring`, `--sidebar-primary`, `--chart-1..5`, `--radius`, `--font-sans`).
- **Dynamic Head Injection (`resources/views/partials/theme-styles.blade.php`):**
  - Seamless `:root` and `.dark` CSS injection with database caching.
  - Custom hex color overrides, typography font families (Geist, Inter, Plus Jakarta Sans, Roboto, System UI), and custom CSS rule blocks.
- **Appearance Administration (`⚡appearance.blade.php`):**
  - Visual card-based preset selector with live swatch badges.
  - Dedicated brand asset uploaders for main header logo and favicon with instant previews and old file cleanup.

---

## 2. Dynamic Language Management & Translation Editor

- **Database-Driven Languages (`languages` table & `Language` model):**
  - Complete schema and model for managing active, default, LTR/RTL, and custom languages with automatic cache invalidation.
- **JSON Translation String Editor (`TranslationService` & `⚡localization.blade.php`):**
  - Live search, add, edit, and delete translations in `lang/{locale}.json` directly from the admin dashboard.
  - Dynamic `SetAppLocale` middleware and `<x-locale-switcher>` integration with DB-backed active languages.

---

## 3. Maintenance Mode with Secret Token Bypass

- **Middleware Protection (`CheckMaintenanceMode`):**
  - Intercepts requests when `system.maintenance_mode` is enabled.
  - Supports query parameter bypass token (`?secret=TOKEN`) which sets a secure 7-day cookie and cleanly redirects.
  - Automatically exempts administrator routes and authentication endpoints (`/login`, `/system/settings/*`).
- **Modern 503 Maintenance Page (`errors/503.blade.php`):**
  - Accessible, responsive maintenance screen with custom downtime messaging, contact assistance info, and modal token login.

---

## 4. Enterprise Audit Logging System

- **Database Model & Trait (`AuditLog`, `Auditable`, `AuditLogService`):**
  - Automatic Eloquent lifecycle auditing (`created`, `updated`, `deleted`, `restored`) recording old vs new attributes with exclusion of sensitive fields.
  - Authentication event listeners logging login, logout, failed authentication attempts, and password resets.
- **Interactive Audit Logs Dashboard (`⚡audit-logs.blade.php`):**
  - Full-page interface at `/{current_team}/system/audit-logs`.
  - Summary metric cards, multi-parameter search, filter by event type, user, and date range.
  - Side-by-side Visual Diff Modal highlighting changed properties.
  - Export capabilities for Excel (`.xlsx`), CSV (`.csv`), and PDF (`.pdf`).
  - Safe log pruning action with configurable age thresholds.

---

## 5. Verification & Tests

- `tests/Feature/ThemeSettingsTest.php`
- `tests/Feature/LanguageManagementTest.php`
- `tests/Feature/MaintenanceModeTest.php`
- `tests/Feature/AuditLogTest.php`
- `tests/Feature/LocaleTest.php`

## 6. Complete Localization & Theme Integration

- **Full Application Translations:** 
  - Generated comprehensive lang/hi.json (Hindi) and lang/bn.json (Bengali) translation files covering all 937 application strings.
  - Ensured identity mappings in lang/en.json to properly integrate with Laravel's __('Key') translation helper.
- **Dynamic CSS Variable Theming Across Components:**
  - Removed remaining hardcoded \emerald\ Tailwind classes and replaced them with dynamic \primary\ tokens in:
    - Heatmap Chart Component (\esources/views/components/chart/heatmap.blade.php\)
    - File Upload Component (\esources/views/components/ui/file-upload.blade.php\)
    - OTP Component (\esources/views/components/ui/otp.blade.php\)
    - App Layout (\esources/views/layouts/app.blade.php\)
    - Toasts Component (\esources/views/components/ui/toasts.blade.php\)
- **Dark/Light Theme Persistence:**
  - Refined theme initialization logic in \esources/js/theme.js\ and \pp.blade.php\ to properly read the system appearance settings and enforce the configured default (light/dark/system).
- **Application-wide Formatting & Directives:**
  - Added \App\Support\Format\ helper and custom Blade directives (\@formatDate\, \@formatCurrency\, etc.) that seamlessly adapt to the database-driven timezone and locale settings application-wide.
