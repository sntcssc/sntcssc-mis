# Implementation Plan: Enterprise-Grade OTP (SMS & Email), Dual Login & Centralized Template Management

Implement a robust, production-grade OTP verification system for SMS and Email, multi-method authentication (Email/Mobile with Password and Email/Mobile with OTP), verification redirection enforcement, admin-controllable gateway failover, and a centralized template management system for SMS and Email.

## User Review Required

> [!IMPORTANT]
> - **Phone field in `users` table**: A migration will add `phone` (string, unique/nullable) and `phone_verified_at` (timestamp, nullable) to `users`.
> - **Gateway Resilience**: When SMS gateway is disabled in admin settings (`sms.enabled = false`), SMS OTP options and prompts will gracefully hide/disable without affecting system operation. Similarly, when Email is disabled (`email.is_enabled = false`), Email OTP options adapt gracefully.
> - **Centralized Templates**: Dedicated admin pages will be created at `system/templates/sms` and `system/templates/email` for creating, editing, categorizing (OTP, Notification, Notice, Communication, Promotional), previewing, and testing templates.

---

## Proposed Changes

### 1. Database Migrations & Models

#### [NEW] [database/migrations/2026_08_23_140000_add_phone_to_users_table.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_23_140000_add_phone_to_users_table.php)
- Adds `phone` and `phone_verified_at` columns with indexing to the `users` table.

#### [NEW] [database/migrations/2026_08_23_140001_create_otp_codes_table.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_23_140001_create_otp_codes_table.php)
- Creates `otp_codes` table with `id`, `user_id`, `identifier` (phone/email), `type` (registration, login, reset, verify), `code_hash` (secure bcrypt/hash), `expires_at`, `verified_at`, `attempts`, `max_attempts`, `ip_address`, `user_agent`, `metadata`, `created_at`, `updated_at`, `deleted_at` (soft deletes).

#### [NEW] [database/migrations/2026_08_23_140002_create_message_templates_tables.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_23_140002_create_message_templates_tables.php)
- Creates `sms_templates` and `email_templates` tables with `code`, `name`, `category` (otp, notification, notice, communication, promotional), `subject` (for email), `sender_id`, `dlt_template_id`, `body`, `variables` (json), `status` (boolean), `created_by`, `updated_by`, `deleted_by`, timestamps, `deleted_at` (soft deletes).

#### [MODIFY] [app/Models/User.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/User.php)
- Add `phone` and `phone_verified_at` to fillable attributes and datetime casting.
- Add helper methods `hasVerifiedPhone(): bool` and `markPhoneAsVerified(): bool`.

#### [NEW] [app/Models/OtpCode.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/OtpCode.php)
- Eloquent model for OTP codes with `SoftDeletes`, `Auditable`, relationship to `User`, and validation/expiry methods.

#### [NEW] [app/Models/SmsTemplate.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/SmsTemplate.php) & [app/Models/EmailTemplate.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/EmailTemplate.php)
- Eloquent models with `SoftDeletes`, `Auditable`, user relationships, and placeholder rendering logic (`{name}`, `{otp}`, `{app_name}`, `{expiry}`, etc.).

---

### 2. Core Communication & OTP Services

#### [NEW] [app/Services/SmsService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/SmsService.php)
- Handles SMS dispatch across multiple drivers: `2factor` (DLT OTP/Transactional), `msg91`, `fast2sms`, and `log`.
- Respects `Setting::get('sms.enabled')`. If disabled or gateway errors occur, logs gracefully without failing user requests.
- Integrates timeout and retry logic based on `Setting` values.

#### [NEW] [app/Services/EmailService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/EmailService.php)
- Handles dynamic templated emails using database `EmailTemplate` and SMTP/Mail transports.
- Respects `Setting::get('email.is_enabled')`.

#### [NEW] [app/Services/OtpService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/OtpService.php)
- Enterprise-grade OTP engine:
  - Generation: Cryptographically secure numeric OTPs with configurable digit length.
  - Hashing: Stored as secure hashes.
  - Rate limiting & cooldown: Enforces resend cooldowns and maximum attempt thresholds.
  - Delivery routing: Sends OTP via `SmsService` or `EmailService` using centralized template records.
  - Verification: Atomic validation with try/catch, DB transactions, attempt counters, and soft-delete/marked as verified.

---

### 3. Authentication & Verification Flows

#### [MODIFY] [app/Providers/FortifyServiceProvider.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Providers/FortifyServiceProvider.php)
- Register `Fortify::authenticateUsing()` to support login with **Email OR Phone + Password**.
- Configure rate limiters for OTP requests and verification.

#### [MODIFY] [app/Actions/Fortify/CreateNewUser.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Actions/Fortify/CreateNewUser.php)
- Validate and store `phone` alongside `email`, `name`, and `password`.
- Trigger verification OTPs (Email and SMS) upon registration.

#### [NEW] [app/Http/Middleware/EnsurePhoneAndEmailVerified.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/EnsurePhoneAndEmailVerified.php)
- Checks authenticated user's verification state:
  - If email unverified -> redirects to verify email page.
  - If phone unverified (and SMS enabled) -> redirects to `verify-otp` page.

#### [MODIFY] [resources/views/pages/auth/login.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/login.blade.php)
- Dual-method Login UI:
  - **Method 1 (Password)**: Email or Mobile number + Password.
  - **Method 2 (OTP)**: Email or Mobile number -> Dispatches OTP -> Enters OTP -> Logs in.
- Dynamically checks `Setting::get('sms.enabled')` and `Setting::get('email.is_enabled')` to gracefully show/hide or adapt methods.

#### [MODIFY] [resources/views/pages/auth/register.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/register.blade.php)
- Include mobile number field with country code support.
- Livewire/Form handling to submit and redirect to verification flow.

#### [MODIFY] [resources/views/pages/auth/verify-otp.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/verify-otp.blade.php)
- Livewire OTP verification component:
  - Supports mobile OTP and email OTP verification.
  - Live countdown timer for resend OTP.
  - Rate limiting alerts, error messaging, and automated redirection to dashboard upon successful verification.

#### [MODIFY] [resources/views/pages/auth/forgot-password.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/forgot-password.blade.php) & [resources/views/pages/auth/reset-password.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/reset-password.blade.php)
- Allows requesting password reset via Email OR Mobile OTP.
- Verifies OTP before allowing new password submission.

---

### 4. Centralized Template Management Admin Pages

#### [NEW] [resources/views/pages/admin/templates/⚡sms-templates.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/templates/⚡sms-templates.blade.php)
- Livewire admin page for managing SMS templates:
  - Category tabs/filters: OTP, Notification, Notice, Communication, Promotional.
  - Search, pagination, status toggles.
  - Create / Edit modal with placeholder tags inserter (`{name}`, `{otp}`, `{app_name}`, etc.), DLT template ID, sender ID.
  - Test SMS dispatch preview with sample variables.
  - Soft delete and restore.

#### [NEW] [resources/views/pages/admin/templates/⚡email-templates.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/templates/⚡email-templates.blade.php)
- Livewire admin page for managing Email templates:
  - Category tabs/filters: OTP, Notification, Notice, Communication, Promotional.
  - Subject and HTML/rich body editor with dynamic placeholder tags.
  - Preview rendering mode.
  - Test email sending modal.
  - Soft delete and restore.

#### [MODIFY] [routes/web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php) & [resources/views/layouts/app/sidebar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)
- Register template routes:
  - `system/templates/sms` (`admin.sms-templates.index`)
  - `system/templates/email` (`admin.email-templates.index`)
- Add "SMS Templates" and "Email Templates" under Sidebar -> System / Communications.

---

### 5. Seeders & Translations

#### [NEW] [database/seeders/MessageTemplateSeeder.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/MessageTemplateSeeder.php)
- Seeds initial standard templates for OTP Registration, OTP Login, Password Reset, Student Admission Notice, Exam Notification, Promotional Announcement.

#### [MODIFY] [lang/en.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json), [lang/hi.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json), [lang/bn.json](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json)
- Add complete translation strings for all new UI messages, labels, validation alerts, and template categories.

---

## Verification Plan

### Automated Tests
- `php artisan test --compact` to execute full test suite.
- Specific new test file `tests/Feature/Auth/OtpAuthenticationTest.php` testing:
  - OTP generation and verification for SMS & Email.
  - Rate limiting & cooldown on OTP resend.
  - Password login with Email and with Phone.
  - OTP login with Email and with Phone.
  - Graceful fallback when SMS or Email gateway is disabled in settings.
  - Redirection to verification page when unverified user logs in.
  - Forgot password via OTP.
  - SMS & Email templates CRUD, placeholder rendering, and test dispatching.

### Code Style & Formatting
- Run `vendor/bin/pint --format agent` to ensure standard Laravel style compliance.
