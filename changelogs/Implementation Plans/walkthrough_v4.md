# Walkthrough — Enterprise OTP Authentication, Verification & Centralized Message Templates

We have implemented an enterprise-grade production-level OTP authentication and verification system for SMS and Email, along with centralized template management and failover controls.

---

## 1. Key Features Built

### Dual-Method Authentication & Verification
- **Dual Login Methods**:
  - **Password Login**: Users can log in using either **Email Address + Password** or **Mobile Number + Password**.
  - **OTP Sign In**: Passwordless sign-in using dynamic one-time passwords delivered via SMS (if mobile number is entered) or Email (if email address is entered).
- **Registration Verification**:
  - Registration captures Full Name, Email, Mobile Phone Number, and Password.
  - Automatically dispatches initial verification OTP upon signup.
- **Unverified Account Protection Middleware**:
  - `EnsurePhoneAndEmailVerified` checks verification status on protected routes.
  - Redirects unverified users directly to the verification page (`/verify-otp` for phone, `/verify-email` for email) instead of granting dashboard access.
- **OTP Password Reset**:
  - Users can reset forgotten passwords instantly via SMS/Email OTP verification or through the traditional email reset link.

---

### Gateway Resilience & Admin Controls
- **Toggleable Providers**:
  - SMS gateway is fully controlled via `Setting::get('sms.enabled')`.
  - Email service is fully controlled via `Setting::get('email.is_enabled')`.
- **Automatic UI & Logic Failover**:
  - If the SMS gateway is disabled in settings, SMS OTP options and prompts adapt automatically without application errors or crashes.
  - If Email service is disabled, Email OTP options adapt similarly.
  - Multi-driver SMS architecture supporting `2factor`, `msg91`, `fast2sms`, and local `log`.

---

### Security, Rate Limiting & Hashing
- **Bcrypt Hashing**: Plaintext OTP codes are never stored in the database. `OtpCode` stores `code_hash` with `Hash::make()` and verifies using constant-time `Hash::check()`.
- **60-Second Cooldown & Rate Limiting**: Enforces a 60-second wait between OTP requests and throttles requests per IP/identifier (max 6 requests per 10 minutes).
- **Attempt Limiting**: OTP records track verification attempts and invalidate themselves upon reaching the maximum allowed attempts (default 5).

---

### Centralized Template Management (SMS & Email)
- Dedicated admin management pages accessible from the sidebar under **COMMUNICATIONS**:
  - **SMS Templates** (`/system/templates/sms`): Categorized templates (`otp`, `notification`, `notice`, `communication`, `promotional`), DLT Header IDs, DLT Content IDs, placeholder variable substitution chips, instant test SMS dispatch modal, and soft-delete/restore capabilities.
  - **Email Templates** (`/system/templates/email`): Categorized templates, customizable email subject lines, HTML body markup, responsive live preview modal, instant test email dispatch modal, and soft-delete/restore capabilities.

---

## 2. Verification & Automated Test Results

### Pest Automated Feature & Unit Tests
We added automated tests covering all OTP flows, services, resilience switches, and template management:
- `tests/Feature/Auth/OtpAuthenticationTest.php` (13 tests)
- `tests/Feature/MessageTemplatesTest.php` (4 tests)

```bash
$ php artisan test --compact
PASS  Tests\Unit\ExampleTest
PASS  Tests\Feature\AdminPagesTest
PASS  Tests\Feature\AuditLogTest
PASS  Tests\Feature\Auth\AuthenticationTest
PASS  Tests\Feature\Auth\EmailVerificationTest
PASS  Tests\Feature\Auth\OtpAuthenticationTest
PASS  Tests\Feature\Auth\PasswordConfirmationTest
PASS  Tests\Feature\Auth\PasswordResetTest
PASS  Tests\Feature\Auth\RegistrationTest
PASS  Tests\Feature\Auth\TwoFactorChallengeTest
PASS  Tests\Feature\BrandAssetSyncTest
PASS  Tests\Feature\DashboardTest
PASS  Tests\Feature\ExampleTest
PASS  Tests\Feature\LanguageManagementTest
PASS  Tests\Feature\LocaleTest
PASS  Tests\Feature\MaintenanceModeTest
PASS  Tests\Feature\MessageTemplatesTest
PASS  Tests\Feature\SettingTest
PASS  Tests\Feature\Settings\ProfileUpdateTest
PASS  Tests\Feature\Settings\SecurityTest
PASS  Tests\Feature\SettingsCrudTest
PASS  Tests\Feature\SettingsPagesTest
PASS  Tests\Feature\Teams\PruneExpiredTeamInvitationsTest
PASS  Tests\Feature\Teams\TeamInvitationTest
PASS  Tests\Feature\Teams\TeamMemberTest
PASS  Tests\Feature\Teams\TeamTest
PASS  Tests\Feature\ThemeSettingsTest

Tests:    207 passed (625 assertions)
Duration: 17.58s
```

### Code Formatting
- Laravel Pint formatting was executed and verified: `vendor/bin/pint --format agent`.

---

## 3. Changelog
The changelog has been saved to:
[`changelogs/2026-08-23-enterprise-otp-sms-email-verification-and-templates.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-23-enterprise-otp-sms-email-verification-and-templates.md)
