# Walkthrough: Email Verification with OTP & Admin User Impersonation

This document summarizes the implementation of:
1. **Configurable Email Verification (OTP vs Link)** managed in Admin Email Provider Settings.
2. **Admin Impersonation ("Login as User Anonymously")** with enable/disable toggle in Admin System Settings.

---

## 1. Email Verification with OTP & Admin Provider Settings

### Changes & Capabilities
- **Admin Email Provider Setting**:
  - Located in [System Settings → Email Provider](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1email.blade.php).
  - Admins can select the **Registration Email Verification Strategy**:
    - **OTP Based Verification (Recommended)**: Dispatches a 6-digit OTP code to the user's email upon sign-up for quick on-screen verification (identical to mobile OTP).
    - **Link Based Verification**: Dispatches a signed magic verification link URL to the user's email.
  - Setting persisted as `email.verification_mode` in the `Setting` table (`email` group).
- **Registration Flow**:
  - In [`CreateNewUser.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Actions/Fortify/CreateNewUser.php), upon user creation:
    - If `email.verification_mode === 'otp'` and email service is enabled, `OtpService::generateAndSend` is dispatched with `OtpCode::TYPE_REGISTRATION_EMAIL`.
    - If `email.verification_mode === 'link'`, the standard verification notification is dispatched.
- **Middleware & Routing**:
  - In [`EnsurePhoneAndEmailVerified.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/EnsurePhoneAndEmailVerified.php): Unverified email users are dynamically redirected to `route('verify-otp', ['type' => 'email'])` when OTP mode is active or `route('verification.notice')` when Link mode is active.
  - In [`OtpService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/OtpService.php): OTP verification matches both registration and direct verification OTP codes seamlessly.
  - In [`verify-otp.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/verify-otp.blade.php): Full UI support for email OTP input, 60s cooldown timer, resend button, and instant dashboard redirect.

---

## 2. Admin Impersonation ("Login as User Anonymously")

### Changes & Capabilities
- **System Setting Toggle**:
  - Located in [System Settings → System & Maintenance](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1system.blade.php).
  - Admins can toggle **Allow Admin Impersonation (Anonymous Login as User)** on/off (`system.allow_user_impersonation`).
- **Core Impersonation Service & Controller**:
  - [`ImpersonationService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/ImpersonationService.php): Handles security validations (admin role required, cannot impersonate self or deleted users), session persistence (`impersonator_id`), and audit logging.
  - [`ImpersonationController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/Admin/ImpersonationController.php) & routes in [`routes/web.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php):
    - `POST /admin/impersonate/{user}` (`admin.impersonate`)
    - `POST /admin/impersonate/leave` (`admin.impersonate.leave`)
- **Admin Users Directory Integration**:
  - In [Users Management](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1users.blade.php), every eligible user row action menu and the user dossier drawer modal display a **"Login as User"** action.
- **Top Impersonation Notification Banner**:
  - In [`layouts/app.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app.blade.php), when impersonation is active, a sticky banner appears alerting the administrator:
    - `"Impersonation Mode: Browsing as [Name] ([Email]) — Signed in via Administrator [AdminName]."`
    - Includes a **"Switch Back to Admin"** button to restore the original administrator session in one click.

---

## 3. Verification & Test Results

### Automated Feature Tests
1. [`tests/Feature/Auth/EmailOtpVerificationTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/Auth/EmailOtpVerificationTest.php):
   - `✓ new user registration dispatches email OTP when OTP mode is active`
   - `✓ new user registration dispatches link notification when Link mode is active`
   - `✓ unverified user is redirected to verify-otp when OTP mode is enabled`
   - `✓ unverified user is redirected to verification.notice when Link mode is enabled`
   - `✓ email can be verified with valid OTP code`
   - `✓ email verification fails with invalid OTP code`
   - `✓ admin can update email verification mode in email settings`

2. [`tests/Feature/Admin/ImpersonationTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/Admin/ImpersonationTest.php):
   - `✓ administrator can impersonate a regular user when setting is enabled`
   - `✓ regular user cannot impersonate another user`
   - `✓ administrator cannot impersonate when feature is disabled in settings`
   - `✓ administrator cannot impersonate self or deleted user`
   - `✓ administrator can leave impersonation and return to admin session`
   - `✓ admin can toggle impersonation setting from system settings page`

3. **Full Test Suite Execution**:
   - `264 passed, 0 failed (960 assertions)`
