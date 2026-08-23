# Dedicated Settings Group UI Pages & Reusable File/Image Upload Component

Create dedicated, modern production-grade UI pages for each of the 8 settings groups (General, SEO, Appearance, Email, Localization, Payment, SMS, System) with dedicated form fields, interactive toggles, image/file uploads with live previews, reusable upload components and services with timestamped renaming and folder-wise storage, DB transactions, robust try-catch exception handling, and full test coverage.

## User Review Required

> [!NOTE]
> All 8 settings group pages will share a unified navigation bar with icon tabs, allowing seamless switching between the Master Table view (`/system/settings`) and each dedicated group page (`/system/settings/general`, `/seo`, `/appearance`, `/email`, `/localization`, `/payment`, `/sms`, `/system`).

---

## Proposed Changes

### 1. File Upload Service & Reusable UI Component

#### [NEW] [FileUploadService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/FileUploadService.php)
- Handles file storage, custom folder destinations, sanitized timestamp-based renaming:
  `{prefix}_{Ymd_His}_{random}.{ext}` (e.g. `site_logo_20260823_143022_a8b9c0.png`).
- Checks file size limits, allowable MIME types/extensions, and deletes old files if replaced.
- Safe error handling with try/catch and logging.

#### [NEW] [file-upload.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/file-upload.blade.php)
- Reusable modern production-grade Blade component supporting:
  - Drag-and-drop dropzone with hover & focus states.
  - Image mode (`type="image"`) with real-time live preview for new uploads (`temporaryUrl()`) and current image thumbnail display with zoom/view link.
  - File mode (`type="file"`) with file icon, original filename, file size formatted in KB/MB, and download link for existing files.
  - Remove / Clear upload button.
  - Livewire upload progress loading indicator.
  - Configurable accepted file types, folder hints, and max size badges.

---

### 2. Unified Settings Navigation Component

#### [NEW] [settings-nav.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/settings-nav.blade.php)
- Modern tabbed header navigation with Lucide icons for all settings pages:
  - **All Settings** (Master Table)
  - **General** (`general.*`)
  - **SEO** (`seo.*`)
  - **Appearance** (`appearance.*`)
  - **Email Provider** (`email.*`)
  - **Localization & Formats** (`localization.*`)
  - **Payment Gateways** (`payment.*`)
  - **SMS Gateway** (`sms.*`)
  - **System** (`system.*`)

---

### 3. Dedicated Settings Group Pages (Livewire Components)

#### [NEW] [general.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡general.blade.php)
- **Form Fields**: Site Name, Tagline, Description, App Name, Title Suffix, Campus, Contact Email, Contact Mobile, Contact Phone, Address, Office Timing, Open Days, Copyright Text.
- **Uploads**: Site Logo & Site Favicon with live preview and storage under `settings/general/`.
- **Database Handling**: `DB::transaction`, `try ... catch (\Throwable)`, `Toast::dispatch`.

#### [NEW] [seo.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡seo.blade.php)
- **Form Fields**: Meta Title, Meta Description, Meta Keywords, Canonical URL, Twitter Handle, Google Analytics / GTM ID, Robots.txt content.
- **Uploads**: Open Graph (OG) Image with live preview under `settings/seo/`.
- **Database Handling**: `DB::transaction`, `try ... catch`, `Toast::dispatch`.

#### [NEW] [appearance.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡appearance.blade.php)
- **Form Fields**: Primary Brand Color, Dark Mode preference, Sidebar Theme, Font Family, Custom CSS editor.
- **Uploads**: Dashboard Logo & Dashboard Icon with live preview under `settings/appearance/`.
- **Database Handling**: `DB::transaction`, `try ... catch`, `Toast::dispatch`.

#### [NEW] [email.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡email.blade.php)
- **Form Fields**: Mail Driver (SMTP/Log/Mailgun/SES/Sendmail), SMTP Host, SMTP Port, SMTP Encryption (TLS/SSL/None), SMTP Username, SMTP Password (masked secret), From Address, From Name, CC Address.
- **Interactive Action**: Test Email recipient field and "Send Test Email" button with exception handling and status feedback.
- **Database Handling**: `DB::transaction`, `try ... catch`, `Toast::dispatch`.

#### [NEW] [localization.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡localization.blade.php)
- **Form Fields**: Default Language (English, Hindi, Bengali), Fallback Language, Timezone (Asia/Kolkata, UTC, etc.), Date Format (with live formatted sample), Time Format (with live formatted sample), Currency Symbol (₹, $, €, etc.), Currency Code (INR, USD, etc.), Number Format (Indian Lakhs vs International).
- **Database Handling**: `DB::transaction`, `try ... catch`, `Toast::dispatch`.

#### [NEW] [payment.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡payment.blade.php)
- **Form Fields**:
  - Global: Online Payments Enabled toggle, Active Gateway, Currency Code.
  - Razorpay: Razorpay Enabled toggle, Key ID, Key Secret (masked secret), Webhook Secret (masked secret).
  - PhonePe: PhonePe Enabled toggle, Merchant ID, Salt Key (masked secret), Salt Index, Mode (UAT/LIVE).
- **Database Handling**: `DB::transaction`, `try ... catch`, `Toast::dispatch`.

#### [NEW] [sms.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡sms.blade.php)
- **Form Fields**:
  - SMS Service: SMS Master Toggle, Provider (2factor.in / Log / MSG91 / Fast2SMS), Default Country Code.
  - 2factor.in: API Key (masked secret), Base URL, DLT Sender ID, DLT Template Name.
  - OTP Parameters: OTP Length, OTP Expiry (minutes), HTTP Timeout, Retry Attempts.
- **Interactive Action**: "Send Test OTP" simulation with validation and feedback.
- **Database Handling**: `DB::transaction`, `try ... catch`, `Toast::dispatch`.

#### [NEW] [system.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡system.blade.php)
- **Form Fields**: Maintenance Mode switch (with caution badge), Debug Mode switch, App Name, App Version, Developed By, Developer Contact, GitHub URL, Website URL, Max Upload Size (MB), Session Lifetime (min), Cache Driver.
- **Maintenance Actions**: "Clear Application Cache", "Clear View Cache", "Create Storage Link" buttons with execution and toast notifications.
- **Database Handling**: `DB::transaction`, `try ... catch`, `Toast::dispatch`.

---

### 4. Route Registrations and Navigation

#### [MODIFY] [routes/web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register the 8 dedicated settings routes under the `{current_team}` prefix:
  - `system/settings/general` -> `admin.settings.general`
  - `system/settings/seo` -> `admin.settings.seo`
  - `system/settings/appearance` -> `admin.settings.appearance`
  - `system/settings/email` -> `admin.settings.email`
  - `system/settings/localization` -> `admin.settings.localization`
  - `system/settings/payment` -> `admin.settings.payment`
  - `system/settings/sms` -> `admin.settings.sms`
  - `system/settings/system` -> `admin.settings.system`

#### [MODIFY] [SettingsSeeder.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/SettingsSeeder.php)
- Add defaults for any new settings keys (such as `seo.og_image`, `seo.canonical_url`, `seo.robots_txt`, `appearance.primary_color`, `appearance.custom_css`, `localization.timezone`, `system.debug_mode`, etc.).

#### [MODIFY] [⚡settings.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡settings.blade.php)
- Integrate `<x-settings-nav active="all" />` header tabs.

---

### 5. Automated Tests

#### [NEW] [SettingsPagesTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/SettingsPagesTest.php)
- Tests for:
  - Rendering each of the 8 settings pages (General, SEO, Appearance, Email, Localization, Payment, SMS, System).
  - Saving settings in each group with validation and `DB::transaction`.
  - Uploading images with timestamp renaming via `FileUploadService` and `<x-ui.file-upload>`.
  - Testing actions (Test email trigger, clear cache, etc.).

---

## Verification Plan

### Automated Tests
- Run `php artisan test --filter=SettingsPagesTest`
- Run full test suite: `php artisan test`
- Format code: `vendor/bin/pint --format agent`

### Manual Verification
- Navigate through all 9 settings tabs using the top settings navigation.
- Modify and save fields in each settings group; check that changes persist in the database and cache flushes.
- Test uploading site logo, favicon, OG image, and dashboard logo; verify timestamp-based renaming, storage folder structure, and live previews.
