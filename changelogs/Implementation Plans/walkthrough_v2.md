# Brand Assets Synchronization (Logo & Favicon) Walkthrough

We have unified and synchronized the application logo and favicon configuration from system settings across the entire application with automatic fallback to defaults.

## Key Changes Made

### 1. Centralized Model Helpers
In [`app/Models/Setting.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Setting.php):
- **`Setting::logoUrl()`**: Resolves the public URL for the active logo (`general.site_logo` or `appearance.logo`). Returns `null` if no custom logo is uploaded.
- **`Setting::faviconUrl(bool $fallback = true)`**: Resolves the active favicon URL (`general.site_favicon` or `appearance.icon`). Falls back to `/favicon.ico` when `$fallback` is true, or `null` otherwise.
- **`Setting::appName()`**: Resolves configured application name (`general.app_name` or `general.site_name` or `config('app.name')`).
- **`Setting::siteName()`**: Resolves configured site name (`general.site_name` or `config('app.name')`).
- **`Setting::copyrightText()`**: Formats the copyright notice replacing `:year` dynamically.

---

### 2. Dedicated Brand Blade Components
- **`<x-app-logo/>`** in [`resources/views/components/app-logo.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/app-logo.blade.php):
  - Renders custom logo image (`<img>`) if uploaded.
  - Falls back to the default zap badge + text name if not uploaded.
  - Supports `:href`, `:iconOnly`, custom image/text classes.
- **`<x-app-logo-icon/>`** in [`resources/views/components/app-logo-icon.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/app-logo-icon.blade.php):
  - Renders square custom icon/favicon image if uploaded.
  - Falls back to the zap badge icon if not uploaded.
- **`<x-auth-header/>`** in [`resources/views/components/auth-header.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/auth-header.blade.php):
  - Updated to display uploaded custom logo/icon with fallback.

---

### 3. Integrated Across Layouts & Pages
- **HTML `<head>`** ([`resources/views/partials/head.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/partials/head.blade.php)):
  - `<link rel="icon">` dynamically loads `Setting::faviconUrl()`.
  - When no custom favicon is set, falls back to `/favicon.ico`, `/favicon.svg`, and `/apple-touch-icon.png`.
  - OpenGraph image `og:image` automatically defaults to `Setting::logoUrl()` if no custom SEO card image is set.
- **Sidebar & Navigation** ([`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)):
  - Desktop expanded sidebar header uses `<x-app-logo/>`.
  - Mobile drawer sidebar header uses `<x-app-logo/>`.
- **Footers** ([`resources/views/layouts/app.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app.blade.php), [`resources/views/layouts/auth/simple.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/auth/simple.blade.php)):
  - Dynamic copyright notice with current year.
- **Welcome / Landing Page** ([`resources/views/welcome.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/welcome.blade.php)):
  - Dynamic favicon in `<head>`.
  - Header brand uses `<x-app-logo/>`.
- **Auth Screens** (`login.blade.php`, `register.blade.php`, `forgot-password.blade.php`, `reset-password.blade.php`):
  - Brand header dynamically renders uploaded brand asset or fallback badge.

---

### 4. Cross-Page Settings Synchronization
- **General Settings** ([`resources/views/pages/admin/settings/⚡general.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1general.blade.php)):
  - Automatically loads and synchronizes `site_logo` / `site_favicon` with `appearance.logo` / `appearance.icon`.
- **Appearance Settings** ([`resources/views/pages/admin/settings/⚡appearance.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1appearance.blade.php)):
  - Automatically loads and synchronizes `logo` / `icon` with `general.site_logo` / `general.site_favicon`.

---

## Verification Results

### Automated Tests
Ran full test suite (`185` tests passed, `520` assertions):
- `BrandAssetSyncTest.php`: 9 tests verifying default fallbacks, custom uploads, settings cross-synchronization, and Blade component rendering.
- `SettingsPagesTest.php` and full application test suite: all passed.

```
Tests:    185 passed (520 assertions)
Duration: 15.14s
```
