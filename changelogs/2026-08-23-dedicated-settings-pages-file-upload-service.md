# Feature — Dedicated Settings Group Pages, Reusable File Upload Component & Service

**Date:** 2026-08-23  
**Scope:** New dedicated UI pages for all 8 settings groups (General, SEO, Appearance, Email, Localization, Payment, SMS, System), production-grade reusable file/image upload Blade component and service with original-filename preservation, folder-wise timestamped storage, DB transactions, exception handling, and comprehensive test coverage.

---

## 1. New: `FileUploadService` — Centralised Upload Handler

**File:** `app/Services/FileUploadService.php`

Production-grade, static-method service for all settings and future upload use-cases.

**Filename format:** `{field_name}_{original-filename-slug}_{Ymd_His}.{ext}`  
**Example:** `settings/general/site_logo_company-brand-white_20260823_143022.png`

| Method | Purpose |
|---|---|
| `store(file, folder, prefix, disk, oldPath)` | Saves file with original-name preservation, slugified, prefixed with field name, timestamped. Deletes old file on replace. |
| `delete(path, disk)` | Safely removes a file from storage, ignoring http/https URLs. |
| `url(path, disk)` | Resolves full public URL for any stored path. |
| `humanSize(bytes)` | Formats bytes to human-readable `KB` / `MB` etc. |

All methods wrapped in `try ... catch (\Throwable)` with `Log::error/warning` calls.

---

## 2. New: `<x-ui.file-upload>` — Reusable Upload Component

**File:** `resources/views/components/ui/file-upload.blade.php`

Modern, production-grade Blade component using Livewire file uploads with full configuration support.

**Props:**

| Prop | Default | Purpose |
|---|---|---|
| `label` | `null` | Field label |
| `type` | `'image'` | `'image'` or `'file'` |
| `accept` | Auto by type | MIME filter string |
| `maxSize` | `'5MB'` | Max file size badge text |
| `value` | `null` | Currently stored path (existing file) |
| `file` | `null` | Livewire temp upload object |
| `folder` | `null` | Hint label shown under dropzone |
| `allowRemove` | `true` | Show remove button |

**Features:**
- Drag-and-drop dropzone with animated hover/active state
- Live image preview via `$file->temporaryUrl()` when a new file is chosen
- Existing image thumbnail or existing file download link for edit mode
- "Ready to save" badge in emerald green for new uploads
- Livewire `wire:loading` upload spinner indicator
- Error message slot and hint text support

---

## 3. New: `<x-settings-nav>` — Unified Settings Tab Navigation

**File:** `resources/views/components/settings-nav.blade.php`

Tab bar rendered on all settings pages with active state highlighting. Links use `wire:navigate`.

Tabs: **All Settings** · **General** · **SEO** · **Appearance** · **Email Provider** · **Localization & Formats** · **Payment Gateways** · **SMS Gateway** · **System**

---

## 4. New: 8 Dedicated Settings Group Pages

All pages follow consistent patterns:
- `DB::transaction()` wrapping all database writes
- `try ... catch (\Throwable $e)` with `Log::error()` and user-facing `Toast::dispatch()`
- Individual `validate()` call per field with descriptive rules
- Secrets (passwords, API keys, salts) — only updated when a non-blank value is submitted
- `Setting::flushCache()` called after every save
- `FileUploadService::store()` for all image/file fields

| Route name | File | URL |
|---|---|---|
| `admin.settings.general` | `pages/admin/settings/⚡general.blade.php` | `/{team}/system/settings/general` |
| `admin.settings.seo` | `pages/admin/settings/⚡seo.blade.php` | `/{team}/system/settings/seo` |
| `admin.settings.appearance` | `pages/admin/settings/⚡appearance.blade.php` | `/{team}/system/settings/appearance` |
| `admin.settings.email` | `pages/admin/settings/⚡email.blade.php` | `/{team}/system/settings/email` |
| `admin.settings.localization` | `pages/admin/settings/⚡localization.blade.php` | `/{team}/system/settings/localization` |
| `admin.settings.payment` | `pages/admin/settings/⚡payment.blade.php` | `/{team}/system/settings/payment` |
| `admin.settings.sms` | `pages/admin/settings/⚡sms.blade.php` | `/{team}/system/settings/sms` |
| `admin.settings.system` | `pages/admin/settings/⚡system.blade.php` | `/{team}/system/settings/system` |

### General Settings
- Site Name, Tagline, Description, App Name, Title Suffix, Campus, Contact Email, Mobile, Phone, Address, Timing, Open Days, Copyright
- Uploads: **Site Logo** + **Site Favicon** → `settings/general/`

### SEO & Meta Settings
- Meta Title, Meta Description, Meta Keywords, Canonical URL, Twitter/X Handle, Google Analytics / GTM ID, Robots.txt
- Live Google Search Result Snippet mock preview
- Upload: **OG Share Banner Image** → `settings/seo/`

### Appearance & Theme Settings
- Primary Accent Color (colour picker + hex input), Default Dark Mode (system/light/dark), Sidebar Theme, Font Family, Custom CSS editor
- Uploads: **Dashboard Logo** + **Dashboard Icon** → `settings/appearance/`

### Email Provider Settings
- Mail Driver (SMTP / Log / Sendmail / Mailgun / SES), SMTP Host, Port, Encryption (TLS/SSL/None), SMTP Username, SMTP Password (masked), From Address, From Name, CC Address
- Interactive: **Send Test Email** button — dispatches `Mail::raw()`, shows success/error toast

### Localization & Format Settings
- App Language (English/Hindi/Bengali), Fallback Language, Timezone, Date Format, Time Format, Currency Symbol, Currency Code (ISO), Number Notation (Indian Lakhs / International)
- Live timestamp preview formatted with selected date + time format from current server time

### Payment Gateway Settings
- Master Online Payments toggle, Active Gateway, Currency Code
- Razorpay: toggle, Key ID, Key Secret (masked), Webhook Secret (masked)
- PhonePe: toggle, Merchant ID, Salt Key (masked), Salt Index, Mode (UAT/LIVE)

### SMS Gateway & OTP Settings
- SMS master toggle, Provider (2factor.in / Log / MSG91 / Fast2SMS), Country Code
- 2factor.in: API Key (masked), Base URL, DLT Sender ID, DLT Template Name
- OTP: Length (4/6/8), Expiry Minutes, HTTP Timeout, Max Retry Attempts
- Interactive: **Send Test SMS** button with status feedback

### System & Server Settings
- Maintenance Mode switch (with warning indicator), Debug Mode switch
- App Name, App Version, Developed By, Developer Contact, GitHub URL, Website URL
- Max Upload Size (MB), Session Inactivity Lifetime (Min), Cache Driver
- Maintenance actions: **Clear App Cache**, **Clear Compiled Views**, **Verify Storage Symlink**

---

## 5. Updated: Routes

**File:** `routes/web.php`

Added 8 Livewire routes under `/{current_team}` middleware group for all dedicated settings pages.

---

## 6. Updated: `SettingsSeeder`

**File:** `database/seeders/SettingsSeeder.php`

Added seed definitions for all new settings keys introduced by the dedicated pages:

| Group | New Keys |
|---|---|
| `appearance` | `primary_color`, `dark_mode`, `sidebar_theme`, `font_family`, `custom_css` |
| `seo` | `canonical_url`, `og_image`, `twitter_handle`, `robots_txt`, `google_analytics_id` |
| `localization` | `fallback_language`, `timezone`, `currency_code`, `number_format` |
| `system` | `debug_mode`, `max_upload_size`, `session_lifetime`, `cache_driver` |

---

## 7. Updated: Master Settings Table

**File:** `resources/views/pages/admin/⚡settings.blade.php`

- Added `<x-settings-nav active="all"/>` unified navigation at the top.
- Import added for `App\Services\FileUploadService`.
- File storage in the generic modal now uses `FileUploadService::store()` with folder derived from setting group and prefix derived from the setting key — giving properly named, folder-organised stored files.

---

## 8. New: Tests

**File:** `tests/Feature/SettingsPagesTest.php`

11 new tests covering:

| Test | Covers |
|---|---|
| `FileUploadService stores files with sanitized timestamp renaming` | Format: `{prefix}_{original}_{timestamp}.{ext}` |
| `FileUploadService deletes old file on replacement` | Old path removed from disk |
| `FileUploadService humanSize formats bytes correctly` | B, KB, MB formatting |
| `general settings page renders and saves fields and logo upload` | Full form save + image upload |
| `seo settings page renders and saves meta tags and og image` | Full form save + OG image upload |
| `appearance settings page renders and saves colors, font and assets` | Color, dark mode, logo upload |
| `email settings page renders, saves configuration, and sends test email` | SMTP save + test email dispatch |
| `localization settings page renders and saves date, time and locale standards` | Language, timezone, formats |
| `payment gateway settings page renders and saves razorpay and phonepe configurations` | Razorpay + PhonePe credentials |
| `sms gateway settings page renders and saves 2factor parameters and dispatches test sms` | 2factor config + test SMS |
| `system settings page renders, saves flags and runs maintenance utilities` | Flags + cache/link utilities |

**Result:** 132/132 tests passed (392 assertions). Code formatted with `vendor/bin/pint --format agent`.

---

## 9. Updated: `FileUploadService` — Original Filename Preserved

**Change:** Filename format updated from `{prefix}_{Ymd_His}_{random6}.{ext}` to `{prefix}_{original-name-slug}_{Ymd_His}.{ext}`.

The original uploaded file's client name (without extension) is slugified and preserved in the stored filename for human readability and traceability, while the field/prefix label and timestamp still guarantee uniqueness and organisation.

**Example:**
- Uploaded: `Company Brand White Background.png`
- Stored as: `site_logo_company-brand-white-background_20260823_143022.png`
