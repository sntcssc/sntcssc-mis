# Settings Enhancements & Dedicated Group Pages Walkthrough

All requested features, dedicated settings group pages, reusable file/image upload architecture, database transactions, exception handling, and automated tests have been implemented and verified.

---

## 1. Reusable File & Image Upload Architecture

### `FileUploadService` ([app/Services/FileUploadService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/FileUploadService.php))
- **Timestamped & Unique Renaming**: Saves files in the format `{folder}/{prefix}_{Ymd_His}_{random6}.{ext}` (e.g. `settings/general/site_logo_20260823_143022_a8b9c0.png`), preventing namespace collisions and cache-busting stale assets.
- **Folder-Wise Storage**: Supports custom folder destinations (e.g. `settings/general`, `settings/seo`, `settings/appearance`).
- **Old Asset Deletion**: Automatically removes old replaced files on disk when a new file is uploaded.
- **Helper Utilities**: `FileUploadService::url()`, `FileUploadService::delete()`, and `FileUploadService::humanSize()`.
- **Exception & Error Handling**: Wrapped in `try ... catch (\Throwable)` blocks with logging.

### Reusable `<x-ui.file-upload>` Blade Component ([resources/views/components/ui/file-upload.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/file-upload.blade.php))
- **Modern Dropzone**: Drag-and-drop file upload with animated hover/drag states, file type filters, and maximum size indicators.
- **Live Previews**:
  - **Images**: Displays real-time preview of newly chosen files (`temporaryUrl()`) and thumbnail cards with zoom/view links for existing images.
  - **Files**: Displays file icon, filename, formatted size (KB/MB), and download/view links.
- **Loading & Remove Actions**: Integrated Livewire loading indicators (`wire:loading wire:target="..."`) and clear/remove action buttons.

---

## 2. Dedicated Settings Group Pages

All dedicated settings pages use `DB::transaction()`, `try ... catch (\Throwable $e)`, input validations, `Toast::dispatch`, and link through a unified navigation bar:

### 1. General Settings ([pages/admin/settings/⚡general.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡general.blade.php))
- **Fields**: Site Name, Tagline, Description, App Name, Page Title Suffix, Campus, Contact Email, Mobile, Phone, Address, Timing, Open Days, Copyright Notice.
- **Uploads**: Site Logo & Site Favicon with live preview and storage in `settings/general/`.

### 2. SEO & Meta Settings ([pages/admin/settings/⚡seo.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡seo.blade.php))
- **Fields**: Meta Title, Meta Description, Meta Keywords, Canonical Base URL, Twitter/X Handle, Google Analytics / GTM ID, Robots.txt directives.
- **Live Mockup**: Real-time Google Search Result Snippet preview.
- **Uploads**: Open Graph (OG) Share Banner with live preview in `settings/seo/`.

### 3. Appearance & Theme Settings ([pages/admin/settings/⚡appearance.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡appearance.blade.php))
- **Fields**: Primary Accent Color (interactive color picker + hex input), Default Dark Mode (system / light / dark), Sidebar Theme, Font Family (Inter, Plus Jakarta Sans, Roboto, System UI), Custom CSS editor.
- **Uploads**: Dashboard Logo & Dashboard Icon with live preview in `settings/appearance/`.

### 4. Email Provider & SMTP Settings ([pages/admin/settings/⚡email.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡email.blade.php))
- **Fields**: Mail Driver (SMTP / Log / Sendmail / Mailgun / SES), SMTP Host, Port, Encryption (TLS/SSL/None), SMTP Username, SMTP Password (masked secret), From Email, From Name, CC Address.
- **Interactive Tool**: "Send Test Email" deliverability trigger with status toasts.

### 5. Localization & Format Settings ([pages/admin/settings/⚡localization.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡localization.blade.php))
- **Fields**: App Language (English, Hindi, Bengali), Fallback Language, Default Timezone (Asia/Kolkata, UTC, etc.), Date Format, Time Format, Currency Symbol, Currency Code (ISO), Number Notation (Indian Lakhs vs International).
- **Live Preview**: Real-time calculated timestamp preview in selected date/time standard.

### 6. Payment Gateway Settings ([pages/admin/settings/⚡payment.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡payment.blade.php))
- **Fields**:
  - Global: Online Payments master switch, Default Gateway, Currency Code.
  - Razorpay: Razorpay switch, Key ID, Key Secret (masked secret), Webhook Secret (masked secret).
  - PhonePe: PhonePe switch, Merchant ID, Salt Key (masked secret), Salt Index, Gateway Mode (UAT/LIVE).

### 7. SMS Gateway & OTP Settings ([pages/admin/settings/⚡sms.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡sms.blade.php))
- **Fields**: SMS master switch, SMS Provider (2factor.in, Log, MSG91, Fast2SMS), Default Country Code, 2factor API Key (masked secret), DLT Sender ID, DLT Template Name, Base URL, OTP Digit Length, Validity Minutes, HTTP Timeout, Max Retries.
- **Interactive Tool**: "Send Test SMS" test trigger with status feedback.

### 8. System & Server Settings ([pages/admin/settings/⚡system.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡system.blade.php))
- **Fields**: Maintenance Mode switch (with caution badge), Debug Mode switch, App Name, App Version, Developed By, Developer Contact, GitHub URL, Website URL, Max Upload Size (MB), Session Inactivity Lifetime (Min), Cache Driver.
- **Maintenance Actions**: "Clear App Cache", "Clear Compiled Views", "Verify Storage Symlink".

---

## 3. Navigation & Master Table Integration

- Created `<x-settings-nav>` ([resources/views/components/settings-nav.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/settings-nav.blade.php)) with active highlight badges and Lucide icons across all 9 settings pages.
- Registered all 8 subpage routes in [`routes/web.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php).
- Updated the Master Settings Table in [`⚡settings.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡settings.blade.php) with the navigation header and `FileUploadService`.

---

## 4. Verification & Testing

### Automated Test Results:
Ran `php artisan test`:
```
   PASS  Tests\Feature\SettingsPagesTest
  ✓ FileUploadService stores files with sanitized timestamp renaming
  ✓ FileUploadService deletes old file on replacement
  ✓ FileUploadService humanSize formats bytes correctly
  ✓ general settings page renders and saves fields and logo upload
  ✓ seo settings page renders and saves meta tags and og image
  ✓ appearance settings page renders and saves colors, font and assets
  ✓ email settings page renders, saves configuration, and sends test email
  ✓ localization settings page renders and saves date, time and locale standards
  ✓ payment gateway settings page renders and saves razorpay and phonepe configurations
  ✓ sms gateway settings page renders and saves 2factor parameters and dispatches test sms
  ✓ system settings page renders, saves flags and runs maintenance utilities

   PASS  Tests\Feature\SettingsCrudTest
  ... 22 passed

Tests:    132 passed (392 assertions)
Duration: 15.98s
```
- **Total Tests**: 132 tests (100% passing).
- **Pint Formatting**: Ran `vendor/bin/pint --format agent` to maintain code standards.
