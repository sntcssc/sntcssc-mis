# Centralized Theme Settings, System Configuration, and Enterprise Audit Logging

Implement a comprehensive, enterprise-grade customization and administration suite:
1. **Centralized Theme Settings with One-Click Color Presets & Custom Palette Theming**: Application-wide CSS variable injection, 8+ curated presets (Emerald, Indigo, Ocean, Teal, Rose, Amber, Violet, Slate) + custom hex color picker, font family selection, border radius, and custom CSS overrides.
2. **Centralized System Configuration**:
   - **Language & Translation Management**: Dynamic database-driven languages (add/edit/delete/toggle), translation string editor for `lang/{locale}.json`, active locale switcher, and middleware.
   - **Application Branding & Identity**: Logo, favicon, app name, tagline, campus, contact info, working hours, dynamic copyright notice.
   - **SEO Information**: Meta title, description, keywords, Open Graph, Twitter card, Google Analytics ID, robots.txt.
   - **Service Providers**: Email (SMTP/Log/SES/Mailgun with deliverability test), SMS (2factor.in DLT, MSG91, Fast2SMS, Log with OTP config & test SMS), Payment gateways (Razorpay, PhonePe, Stripe with mode & currency).
   - **Application Status & Maintenance Mode with Secret Bypass Token**: Configurable maintenance toggle, secret token bypass (`?secret=TOKEN` or cookie), whitelist paths/IPs, custom 503 view.
3. **Enterprise Audit Logging System**:
   - Database table & model (`audit_logs`), `Auditable` model trait, `AuditLogService`.
   - Event listeners for authentication (Login, Logout, Failed Login, Password Reset) and system operations (Settings changes, Theme changes, Maintenance toggles, Translations updates).
   - Dedicated Admin Audit Logs Page (`admin.audit-logs.index`) with stat cards, search/filters, visual side-by-side JSON Diff modal, export to Excel/CSV/PDF, and log pruning policy.
4. **Testing, Linting, & Changelog**:
   - Pest feature tests for Theme, Languages, Maintenance Bypass, and Audit Logs.
   - Laravel Pint code style formatting.
   - Markdown changelog entry in `changelogs/`.

---

## Proposed Changes

Grouped by component:

### 1. Theme Configuration & Dynamic Styling

#### [NEW] [ThemePresets.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Support/ThemePresets.php)
- Defines preset palettes with light & dark mode variables (`--primary`, `--ring`, `--sidebar-primary`, `--chart-1`..`5`).
- Presets: *Emerald Classic*, *Royal Indigo*, *Ocean Blue*, *Teal Cyan*, *Crimson Rose*, *Sunset Amber*, *Violet Velvet*, *Slate Monochrome*.
- Helper methods to resolve active CSS variables based on preset or custom hex overrides.

#### [NEW] [theme-styles.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/partials/theme-styles.blade.php)
- Generates dynamic `:root` and `.dark` CSS styles, typography font family, border radius, and custom CSS overrides from database `Setting` values.

#### [MODIFY] [partials/head.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/partials/head.blade.php)
- Include `@include('partials.theme-styles')`.
- Render dynamic `<title>`, `<meta>` description, keywords, OpenGraph tags, dynamic favicon link from `Setting`.

#### [MODIFY] [admin/settings/⚡appearance.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1appearance.blade.php)
- Visual preset selector with one-click palette cards and live badge swatches.
- Custom color pickers (Primary Light, Primary Dark, Ring).
- Radius, Sidebar style, Font family selector, and Custom CSS textarea.
- Logo and Favicon uploaders with instant preview.
- Audit logging on appearance changes.

---

### 2. Languages, Translations, and Localization

#### [NEW] [create_languages_table.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_23_170000_create_languages_table.php)
- Migration for `languages` table (`id`, `code`, `name`, `native_name`, `direction`, `flag`, `is_default`, `is_active`, `sort_order`, timestamps).

#### [NEW] [Language.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Language.php)
- Eloquent Model with caching, `active()` scope, `default()` helper, and `Auditable` trait.

#### [NEW] [TranslationService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/TranslationService.php)
- Service to read, search, update, and add translation keys in `lang/{locale}.json` and sync with language files.

#### [MODIFY] [admin/settings/⚡localization.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1localization.blade.php)
- Manage Languages section: Add language, edit language, delete, toggle active, set default.
- Translation String Editor modal/tab: Select language, search translation keys, edit translations, add new translation strings, save with instant reload.
- Timezone, Date format, Time format, Currency symbol/code, Number notation settings.

#### [MODIFY] [app/Http/Middleware/SetAppLocale.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/SetAppLocale.php)
- Dynamically validate locales against active database languages instead of hardcoded array.

#### [MODIFY] [components/locale-switcher.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/locale-switcher.blade.php)
- Dynamically render all enabled languages from `Language::active()`.

---

### 3. Maintenance Mode with Secret Token Bypass

#### [NEW] [CheckMaintenanceMode.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/CheckMaintenanceMode.php)
- Intercepts requests when `system.maintenance_mode` is enabled.
- Allows bypass if:
  1. URL contains `?secret=TOKEN` (sets 24h bypass cookie and redirects clean).
  2. Request has valid `maintenance_bypass` cookie.
  3. User is authenticated administrator.
  4. URL matches exempt routes (`/login`, `/system/settings/*`, webhooks).
- Returns 503 response rendering `errors/503.blade.php` when not bypassed.

#### [NEW] [503.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/errors/503.blade.php)
- Production-grade maintenance page displaying custom title, message, estimated completion time, contact details, and a secret bypass token login modal.

#### [MODIFY] [admin/settings/⚡system.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1system.blade.php)
- Add Maintenance Mode configuration: Secret bypass token generator/input, custom message, bypass URL generator (`/dashboard?secret=YOUR_TOKEN`), retry-after seconds.

---

### 4. Enterprise Audit Logging System

#### [NEW] [create_audit_logs_table.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_23_171000_create_audit_logs_table.php)
- Migration for `audit_logs` table (`id`, `user_id`, `team_id`, `event`, `auditable_type`, `auditable_id`, `ip_address`, `user_agent`, `url`, `method`, `old_values`, `new_values`, `description`, timestamps).

#### [NEW] [AuditLog.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/AuditLog.php)
- Eloquent model with relationships (`user`, `team`, `auditable`), JSON casts, scopes for filtering by event/user/date.

#### [NEW] [AuditLogService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/AuditLogService.php)
- Central service to log events, capture IP/User-Agent/Route, and prune logs.

#### [NEW] [Auditable.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Concerns/Auditable.php)
- Model trait to automatically audit Eloquent lifecycle events (`created`, `updated`, `deleted`, `restored`) with property diffs, excluding hidden/sensitive columns (passwords, secrets).

#### [NEW] [LogAuthenticationEvents.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Listeners/LogAuthenticationEvents.php)
- Listens to `Login`, `Logout`, `Failed`, `PasswordReset` auth events and logs them to `audit_logs`.

#### [NEW] [admin/⚡audit-logs.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1audit-logs.blade.php)
- Full-page SFC at `/{current_team}/system/audit-logs`.
- Summary metric cards (Total logs, Today's events, Security/Auth events, Settings mutations).
- Search, Filter by Event Type, Filter by User, Date Range Picker.
- Visual Diff Modal: Side-by-side or colored comparison of Old vs New values with highlighted property changes, request metadata.
- Export to Excel (.xlsx), CSV (.csv), and PDF (.pdf).
- Log Pruning modal (purge logs older than 30/60/90 days).

#### [MODIFY] [routes/web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register `admin.audit-logs.index` route.

#### [MODIFY] [layouts/app/sidebar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php) & [components/settings-nav.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/settings-nav.blade.php)
- Add Audit Logs navigation links under System & Compliance.

---

### 5. Centralized Service Providers & Helpers

#### [MODIFY] [admin/settings/⚡email.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1email.blade.php)
- Master email enable/disable toggle, provider configuration, audit logging on save.

#### [MODIFY] [admin/settings/⚡sms.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1sms.blade.php)
- Master SMS enable/disable toggle, credentials, test dispatch, audit logging on save.

#### [MODIFY] [admin/settings/⚡payment.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1payment.blade.php)
- Master payment enable/disable toggle, individual gateway toggles, audit logging on save.

#### [MODIFY] [app/Providers/SettingsServiceProvider.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Providers/SettingsServiceProvider.php)
- Dynamically apply timezone, mail driver, and app defaults at boot.

---

### 6. Verification, Tests & Documentation

#### [NEW] [Feature/ThemeSettingsTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/ThemeSettingsTest.php)
- Tests for theme presets, custom colors, CSS variable generation.

#### [NEW] [Feature/LanguageManagementTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/LanguageManagementTest.php)
- Tests for adding languages, translation updates, and dynamic locale switching.

#### [NEW] [Feature/MaintenanceModeTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/MaintenanceModeTest.php)
- Tests for maintenance mode locking and bypass via secret token / cookie.

#### [NEW] [Feature/AuditLogTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/AuditLogTest.php)
- Tests for event logging, diff recording, filtering, and export.

#### [NEW] [changelogs/2026-08-23-theme-settings-system-configuration-and-audit-logs.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-23-theme-settings-system-configuration-and-audit-logs.md)
- Complete release notes and architecture documentation.

---

## Verification Plan

### Automated Tests
Run Pest test suite:
```bash
php artisan test --compact
```
Filter specific feature tests:
```bash
php artisan test --compact --filter=ThemeSettingsTest
php artisan test --compact --filter=LanguageManagementTest
php artisan test --compact --filter=MaintenanceModeTest
php artisan test --compact --filter=AuditLogTest
```

### Code Formatting
Run Laravel Pint:
```bash
vendor/bin/pint --format agent
```

### Manual & Visual Verification
1. Appearance Settings: Select a preset (e.g. Royal Indigo or Crimson Rose), save, and verify primary colors change across topbar, buttons, active sidebar items, focus rings in both light and dark modes.
2. Language Management: Add a new language, edit translation strings, verify locale switcher and translated UI.
3. Maintenance Mode: Turn on maintenance mode, test that visiting public/regular pages renders the modern 503 maintenance page, test entering bypass secret token `?secret=...` to verify bypass access.
4. Audit Logs: Perform various actions (login, change settings, switch theme, update translations), visit the Audit Logs page, inspect the visual Diff modal, test filters and export.
