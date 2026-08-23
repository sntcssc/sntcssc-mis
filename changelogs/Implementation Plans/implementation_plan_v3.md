# Implementation Plan - Sync Logo & Favicon From System Settings Across Application

Synchronize the system logo and favicon configured in System Settings (General & Appearance) so that uploaded brand assets are displayed consistently across every part of the application (layouts, headers, sidebars, auth screens, error pages, and welcome screen), with graceful fallback to default icons and SVGs when not set.

## User Review Required

> [!NOTE]
> When a custom logo or favicon is uploaded in either **General Settings** (`general.site_logo` / `general.site_favicon`) or **Appearance Settings** (`appearance.logo` / `appearance.icon`), the helper methods and UI components will automatically resolve and sync the asset everywhere. If no asset is uploaded, the UI cleanly falls back to the default icon and SVG badges (`/favicon.ico`, `/favicon.svg`, zap icon mark).

## Proposed Changes

### Core Models & Helpers

#### [MODIFY] [Setting.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Setting.php)
- Add static helper methods:
  - `Setting::logoUrl(): ?string` — Returns the resolved public URL of the uploaded logo (`general.site_logo` or `appearance.logo`), or `null` if none uploaded.
  - `Setting::faviconUrl(bool $fallback = true): ?string` — Returns the resolved public URL of the uploaded favicon (`general.site_favicon` or `appearance.icon`), or `/favicon.ico` when `$fallback` is true.
  - `Setting::appName(): string` — Returns the configured application name.
  - `Setting::siteName(): string` — Returns the configured site name.
  - `Setting::copyrightText(): string` — Returns formatted copyright notice with dynamic `:year`.

---

### Blade Components

#### [NEW] [app-logo.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/app-logo.blade.php)
- Create a brand logo component `<x-app-logo/>` that:
  - Displays the uploaded logo image via `Setting::logoUrl()` when present.
  - Falls back to the default zap badge + text name when no logo image is set.
  - Supports configurable image height, link wrapping (`href`), and custom classes.

#### [NEW] [app-logo-icon.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/app-logo-icon.blade.php)
- Create a square brand icon component `<x-app-logo-icon/>` that:
  - Displays the uploaded favicon/icon via `Setting::faviconUrl(fallback: false)` when present.
  - Falls back to the default rounded zap icon badge when no custom icon is uploaded.
  - Supports configurable size classes.

#### [MODIFY] [auth-header.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/auth-header.blade.php)
- Update `<x-auth-header/>` to use `<x-app-logo-icon/>` or `<x-app-logo/>` (or uploaded brand logo) with fallback to default zap icon.

---

### Layouts & Views

#### [MODIFY] [head.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/partials/head.blade.php)
- Use `Setting::faviconUrl()`, `Setting::siteName()`, `Setting::appName()` for `<title>`, `<meta>`, and `<link rel="icon">`.
- Default OpenGraph `og:image` to `Setting::logoUrl()` if `seo.og_image` is not set.
- Gracefully render custom favicon or default `/favicon.ico` + `/favicon.svg` + `/apple-touch-icon.png`.

#### [MODIFY] [sidebar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)
- Replace hardcoded logo/icon markup in desktop expanded sidebar, desktop collapsed sidebar, and mobile drawer with `<x-app-logo>` and `<x-app-logo-icon>`.

#### [MODIFY] [app.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app.blade.php)
- Use dynamic copyright text `\App\Models\Setting::copyrightText()`.

#### [MODIFY] [simple.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/auth/simple.blade.php)
- Use dynamic copyright text `\App\Models\Setting::copyrightText()`.

#### [MODIFY] [welcome.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/welcome.blade.php)
- Use dynamic `<link rel="icon">` and app name from `Setting`, and display `<x-app-logo/>` in the public navigation header.

#### [MODIFY] Auth Pages
- [login.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/login.blade.php)
- [register.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/register.blade.php)
- [forgot-password.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/forgot-password.blade.php)
- [reset-password.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/reset-password.blade.php)
  - Update top brand badge to use `<x-app-logo-icon/>` (displaying custom uploaded icon/logo with fallback to emerald zap badge).

---

### Settings Synchronization

#### [MODIFY] [⚡general.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1general.blade.php)
- In `mount()`, load `site_logo` and `site_favicon` falling back to `appearance.logo` and `appearance.icon` if already set.
- In `save()`, synchronize uploaded/updated `general.site_logo` to `appearance.logo` and `general.site_favicon` to `appearance.icon`.

#### [MODIFY] [⚡appearance.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1appearance.blade.php)
- In `mount()`, load `logo` and `icon` falling back to `general.site_logo` and `general.site_favicon` if already set.
- In `save()`, synchronize uploaded/updated `appearance.logo` to `general.site_logo` and `appearance.icon` to `general.site_favicon`.

---

### Automated Tests

#### [NEW] [BrandAssetSyncTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/BrandAssetSyncTest.php)
- Test `Setting::logoUrl()` and `Setting::faviconUrl()` fallback to null / default when not set.
- Test `Setting::logoUrl()` and `Setting::faviconUrl()` return correct storage URLs when uploaded.
- Test cross-synchronization between General settings and Appearance settings.
- Test rendering of `<x-app-logo>` and `<x-app-logo-icon>` in Blade.

## Verification Plan

### Automated Tests
- Run `php artisan test --compact --filter=BrandAssetSyncTest`
- Run full suite: `php artisan test --compact`
- Run Pint style formatter: `vendor/bin/pint --format agent`

### Manual Verification
- Verify that when no logo/favicon is set, layouts show default zap icon and `/favicon.ico`.
- Upload a logo and favicon in General settings or Appearance settings.
- Verify that the new logo and favicon appear on:
  - Browser tab icon (favicon)
  - Sidebar header (expanded & collapsed)
  - Auth screens (login, register, forgot-password)
  - Welcome page
