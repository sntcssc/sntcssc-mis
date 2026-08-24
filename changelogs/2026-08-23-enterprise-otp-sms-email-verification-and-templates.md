# Enterprise OTP Authentication, Verification & Centralized Message Templates

**Date:** 2026-08-23  
**Scope:** Production-grade OTP authentication and verification for SMS & Email, dual login pathways (Password & OTP), rate limiting, gateway failure fallback and resilience controls in admin settings, unverified user protection middleware, centralized SMS & Email templates management with variable substitution and live test dispatches, multilingual translations, and comprehensive Pest automated test suite.

---

## Summary of Completed Changes

### 1. Database Migrations & Models

- **`database/migrations/2026_08_23_140000_add_phone_to_users_table.php`**:
  - Added unique `phone` and nullable `phone_verified_at` timestamp columns to `users` table.
- **`database/migrations/2026_08_23_140001_create_otp_codes_table.php`**:
  - Created `otp_codes` table storing `user_id`, `identifier`, `type`, `code_hash` (bcrypt), `attempts`, `max_attempts`, `expires_at`, `verified_at`, `ip_address`, `user_agent`, `metadata`, audit fields, and `softDeletes`.
- **`database/migrations/2026_08_23_140002_create_message_templates_tables.php`**:
  - Created `sms_templates` and `email_templates` tables supporting categories (`otp`, `notification`, `notice`, `communication`, `promotional`), DLT IDs, sender IDs, dynamic JSON variables, status toggles, audit trails, and soft deletes.
- **`app/Models/User.php`**:
  - Added `phone` and `phone_verified_at` to `$fillable` and `$casts`.
  - Added `hasVerifiedPhone()` and `markPhoneAsVerified()` helper methods.
- **`app/Models/OtpCode.php`**:
  - Built model with secure verification (`verifyCode`), attempt limiting, cooldown tracking, and audit relationships.
- **`app/Models/SmsTemplate.php` & `app/Models/EmailTemplate.php`**:
  - Implemented dynamic `{placeholder}` variable substitution for SMS and Email messages, category constants, and active query scopes.

---

### 2. Core Services & Resilient Gateways

- **`app/Services/SmsService.php`**:
  - Multi-driver gateway dispatcher (`2factor`, `msg91`, `fast2sms`, `log`) with HTTP client retries and timeouts.
  - Dynamically respects `Setting::get('sms.enabled')`.
  - Automatic phone number normalization with country code handling (e.g. converting `9876543210` or `09876543210` to `+919876543210`).
  - Safe error handling and logging to prevent application crashes on SMS gateway failure.
- **`app/Services/EmailService.php`**:
  - Dynamically respects `Setting::get('email.is_enabled')`.
  - Dispatches customized HTML template emails with subject and body placeholder interpolation.
- **`app/Services/OtpService.php`**:
  - Generates cryptographically secure numeric OTPs with configurable length (`sms.otp_length`) and validity window (`sms.otp_expiry_minutes`).
  - Hashes OTP codes using `Hash::make()` (bcrypt) before database insertion.
  - Enforces a 60-second cooldown period between requests and IP/identifier rate limiting (6 requests per 10 minutes).
  - Handles atomic database verification transactions and invalidates previous codes.

---

### 3. Authentication & Verification Controllers & Middleware

- **`app/Providers/FortifyServiceProvider.php`**:
  - Configured `Fortify::authenticateUsing()` to allow seamless password logins with either **Email** or **Mobile Phone Number**.
- **`app/Actions/Fortify/CreateNewUser.php`**:
  - Validates and stores phone numbers during registration.
  - Automatically dispatches initial registration OTP when SMS service is active.
- **`app/Http/Middleware/EnsurePhoneAndEmailVerified.php`**:
  - Protects authenticated routes by verifying email and phone verification status.
  - Redirects unverified email accounts to `verification.notice` and unverified phone accounts to `verify-otp?type=phone`.
- **`bootstrap/app.php`**:
  - Registered `EnsurePhoneAndEmailVerified` as the application's `verified` middleware alias.
- **`app/Http/Controllers/Auth/OtpLoginController.php`**:
  - Handles passwordless OTP sign-in endpoints: `POST /login/otp/send` and `POST /login/otp/verify`.
- **`app/Http/Controllers/Auth/OtpVerificationController.php`**:
  - Handles account verification endpoints: `POST /verify-otp/send` and `POST /verify-otp/verify`.
- **`app/Http/Controllers/Auth/OtpPasswordResetController.php`**:
  - Handles OTP-based password reset endpoints: `POST /forgot-password/otp/send` and `POST /forgot-password/otp/reset`.

---

### 4. User Interface & Centralized Admin Management

- **Centralized SMS & Email Templates Management**:
  - `resources/views/pages/admin/templates/⚡sms-templates.blade.php`: Livewire component with search, category filtering, create/edit modal with variable chips, test SMS modal, soft delete & restore.
  - `resources/views/pages/admin/templates/⚡email-templates.blade.php`: Livewire component with search, category filtering, rich HTML preview modal, test email modal, soft delete & restore.
  - Registered routes under `/system/templates/sms` and `/system/templates/email` with navigation items under the `COMMUNICATIONS` sidebar section.
- **Enhanced Auth Views**:
  - `resources/views/pages/auth/login.blade.php`: Dual login UI with **Password Login** and **OTP Sign In** tabs, live countdown timers, cooldown tracking, and automatic UI fallbacks when gateways are offline.
  - `resources/views/pages/auth/register.blade.php`: Added phone number field with country code prefix and helper description.
  - `resources/views/pages/auth/verify-otp.blade.php`: Dynamic reactive OTP verification with automatic resend timer, error handling, and dashboard redirection.
  - `resources/views/pages/auth/forgot-password.blade.php`: Dual password recovery interface supporting instant OTP reset or traditional email reset link.
- **Profile Settings**:
  - `resources/views/pages/settings/⚡profile.blade.php`: Added mobile number management to user profile settings with dirty detection and verification resets.

---

### 5. Multilingual Localization

- Updated `lang/en.json`, `lang/hi.json`, and `lang/bn.json` with over 60+ new translation phrases across English, Hindi, and Bengali covering all OTP flows, message templates, categories, and validation messages.

---

### 6. Automated Testing & Code Standards

- **`tests/Feature/Auth/OtpAuthenticationTest.php`** (13 tests):
  - Phone normalization and country code validation.
  - SMS & Email service gateway toggles and disable resilience.
  - OTP generation, bcrypt hashing, rate limiting, and 60-second cooldown enforcement.
  - User registration with phone and initial verification OTP dispatch.
  - Dual login using Email + Password and Phone + Password.
  - Passwordless login via Email OTP and SMS OTP.
  - Gateway offline failover behavior.
  - Unverified account redirection middleware.
  - OTP-based password resets.
- **`tests/Feature/MessageTemplatesTest.php`** (4 tests):
  - Admin access to SMS and Email template management pages.
  - Livewire template CRUD operations, category scoping, placeholder rendering, soft deletes, and restores.
- **Test Suite Results**: 207 tests passed (625 assertions, 0 failures).
- **Code Style**: Formatted and verified with Laravel Pint (`vendor/bin/pint --format agent`).
