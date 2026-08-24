# Implementation Plan: Email Verification with OTP and Admin User Impersonation

This plan details the implementation of two key enterprise features:
1. **Configurable Email Verification (OTP vs Link)**: Allows admin to configure whether email verification on user sign-up/registration uses a **6-digit OTP code** (similar to mobile OTP) or a **magic verification link**, with full settings management in the Admin Panel's Email Provider Settings.
2. **Admin Impersonation ("Login as User Anonymously")**: Allows administrators to securely log into any user's dashboard anonymously without passwords for diagnostics and support, complete with an enable/disable toggle in System Settings, permission checks, audit logging, and a global "Return to Admin" banner.

---

## User Review Required

> [!IMPORTANT]
> - **Default Verification Mode**: The email verification mode default will be set to `otp` so newly registered users receive a 6-digit email OTP by default, but admins can switch between `otp` and `link` anytime in **System Settings → Email Provider Settings**.
> - **Impersonation Security**: Impersonation is restricted to authorized administrative roles (`Super Admin` and `Admin`). An active impersonation session displays an alert banner at the top of every screen with a one-click "Switch Back to Admin" button. All impersonation actions are recorded in the audit logs.

---

## Proposed Changes

### 1. Email Verification Configuration & OTP Registration Flow

#### [MODIFY] [Email Provider Settings](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1email.blade.php)
- Add `$form['verification_mode']` (`'otp'` or `'link'`) with a default of `'otp'`.
- Add UI controls in Email Provider Settings to choose between:
  - **OTP Based Verification** (6-Digit Code via Email for instant verification)
  - **Link Based Verification** (Magic verification link URL via Email)
- Save `email.verification_mode` in the `Setting` table under group `email`.

#### [MODIFY] [CreateNewUser.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Actions/Fortify/CreateNewUser.php)
- When a user registers:
  - Check `Setting::get('email.verification_mode', 'otp')`.
  - If `'otp'` and `EmailService::isEnabled()`: Dispatch `OtpCode::TYPE_REGISTRATION_EMAIL` to the user's email via `OtpService`.
  - If `'link'` and `EmailService::isEnabled()`: Dispatch Fortify's standard signed email link verification notification.
  - If mobile phone is present and SMS gateway is enabled: Dispatch mobile OTP as before.

#### [MODIFY] [EnsurePhoneAndEmailVerified.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/EnsurePhoneAndEmailVerified.php)
- If user's email is not verified:
  - Check `Setting::get('email.verification_mode', 'otp')`.
  - If `'otp'`: Redirect to `route('verify-otp', ['type' => 'email'])`.
  - If `'link'`: Redirect to `route('verification.notice')`.
- If phone is unverified and SMS is enabled: Redirect to `route('verify-otp', ['type' => 'phone'])`.

#### [MODIFY] [OtpService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/OtpService.php) & [OtpVerificationController.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/Auth/OtpVerificationController.php)
- Ensure OTP verification checks both `TYPE_REGISTRATION_EMAIL` and `TYPE_VERIFY_EMAIL` seamlessly when verifying email codes.
- Ensure `sendOtp` and `verifyOtp` handle email and phone verification cleanly with proper error handling and redirection.

#### [MODIFY] [verify-email.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/verify-email.blade.php) & [verify-otp.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/auth/verify-otp.blade.php)
- Update `verify-email.blade.php` to handle OTP mode gracefully (providing a link or direct redirect to OTP entry if OTP mode is active).
- Ensure `verify-otp.blade.php` supports both email and phone seamlessly with clear target identifiers, countdown, resend OTP button, and visual feedback.

---

### 2. Admin Impersonation ("Login as User Anonymously")

#### [NEW] [ImpersonationService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/ImpersonationService.php)
- Centralized service for user impersonation:
  - `isAllowed(): bool` - checks setting `system.allow_user_impersonation` (default `true`).
  - `isImpersonating(): bool` - checks `session()->has('impersonator_id')`.
  - `getImpersonator(): ?User` - retrieves the original administrator instance.
  - `canImpersonate(User $actor, User $target): bool` - authorization checks (admin role, not self, not trashed, setting enabled).
  - `impersonate(User $actor, User $target): bool` - stores session state, logs audit entry, logs in target user.
  - `leave(): bool` - restores original admin session, logs audit entry, clears session flags.

#### [NEW] [ImpersonationController.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/Admin/ImpersonationController.php)
- `impersonate(Request $request, User $user)`: validates permission and initiates impersonation.
- `leave(Request $request)`: terminates impersonation and returns back to the admin users management screen.

#### [MODIFY] [routes/web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register routes:
  - `POST /admin/impersonate/{user}` -> `ImpersonationController@impersonate` (`name('admin.impersonate')`)
  - `POST /admin/impersonate/leave` -> `ImpersonationController@leave` (`name('admin.impersonate.leave')`)

#### [MODIFY] [System Settings Page](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1system.blade.php)
- Add `allow_user_impersonation` toggle under administrative settings.
- Persist setting in `system.allow_user_impersonation`.

#### [MODIFY] [Users Management Page](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1users.blade.php)
- Add "Login as User" / "Impersonate" action button in:
  - Row action dropdown for each user.
  - User detail dossier drawer modal.
- Include Livewire action `impersonateUser(int $id)` to quickly launch the session.

#### [NEW] / [MODIFY] [Impersonation Banner in App Layout](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app.blade.php)
- Add a persistent top notification banner whenever `session()->has('impersonator_id')`:
  - Displays: "Impersonation Mode Active: You are browsing as **[User Name]** ([email])."
  - Includes a prominent "Switch Back to Admin" button.

---

## Verification Plan

### Automated Tests
- Create `tests/Feature/Auth/EmailOtpVerificationTest.php` testing:
  - User registration in OTP mode triggers registration email OTP.
  - User registration in Link mode sends email verification notification link.
  - Verifying email with valid OTP marks `email_verified_at` as verified.
  - Rate limiting and cooldown handling for email OTP.
  - Setting update in Email Provider Settings for verification mode.
- Create `tests/Feature/Admin/ImpersonationTest.php` testing:
  - Admin can impersonate a regular user when setting is enabled.
  - Regular user cannot impersonate another user (403 forbidden).
  - Admin cannot impersonate when `system.allow_user_impersonation` is disabled in settings.
  - Leaving impersonation restores the original administrator session and redirects to users directory.
  - Audit logs are properly recorded when starting and stopping impersonation.
- Run all test suites: `php artisan test --compact`

### Manual Verification
- Test registration with `email.verification_mode = 'otp'` and verify OTP screen behavior.
- Toggle setting to `link` and test link verification.
- Test Admin "Login as User" button from the Users Management table, verify the top impersonation banner, and click "Switch Back to Admin".
