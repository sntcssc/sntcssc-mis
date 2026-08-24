# Configurable Registration Email Verification (OTP vs Link) & Anonymous Admin User Impersonation

**Date:** 25 August 2026  
**Status:** Completed & Verified (264/264 Automated Tests Passing)

---

## 1. Executive Summary

This release introduces two major enterprise-grade security and authentication capabilities:
1. **Configurable Registration Email Verification Mode**: Allows administrators to choose whether new user registrations require verification via a **6-digit Email OTP** (mirroring mobile phone OTP verification) or a **Magic Verification Link URL**. The preference is fully manageable in the Admin Panel's **Email Provider Settings**.
2. **Anonymous Admin User Impersonation ("Login as User")**: Enables administrators to securely sign in directly to any user's dashboard anonymously without requiring user passwords. It includes an enable/disable feature toggle in **System Settings**, administrative authorization rules, a persistent top notification banner, one-click return to admin account, and comprehensive audit trails.

---

## 2. Feature Details & Architecture

### A. Email Verification Mode (OTP vs Link Strategy)

#### 1. Configuration & Settings Management
- Added `email.verification_mode` setting in the `Setting` model under group `email`.
- Setting options:
  - `otp` (Default / Recommended): Dispatches a 6-digit one-time password (OTP) directly to the user's email address during sign-up for quick, on-screen verification.
  - `link`: Dispatches a standard signed magic verification URL link via transactional email.
- Updated `App\Services\EmailService`:
  - Added `EmailService::getVerificationMode(): string`
  - Added `EmailService::isOtpVerification(): bool`

#### 2. Sign-up & Registration Lifecycle
- Updated `App\Actions\Fortify\CreateNewUser`:
  - When creating a new user, checks `EmailService::isOtpVerification()` and `EmailService::isEnabled()`.
  - In `otp` mode: Generates and dispatches `OtpCode::TYPE_REGISTRATION_EMAIL` using `OtpService::generateAndSend()`.
  - In `link` mode: Dispatches the standard Laravel verification notification (`$user->sendEmailVerificationNotification()`).
  - Dispatches `OtpCode::TYPE_REGISTRATION_SMS` if a mobile phone is provided and SMS gateway is enabled.

#### 3. Middleware & UI Navigation
- Updated `App\Http\Middleware\EnsurePhoneAndEmailVerified`:
  - Dynamically redirects unverified email users:
    - To `route('verify-otp', ['type' => 'email'])` when `email.verification_mode === 'otp'`.
    - To `route('verification.notice')` (`verify-email.blade.php`) when `email.verification_mode === 'link'`.
- Updated `App\Services\OtpService`:
  - `verify()` method queries and matches both `TYPE_REGISTRATION_EMAIL` and `TYPE_VERIFY_EMAIL` codes interchangeably.
- Updated `resources/views/pages/auth/verify-otp.blade.php`:
  - Full support for both `type=email` and `type=phone` verification.
  - Interactive 60-second cooldown timer and live "Resend code" button.
  - Development plain code badge for testing environments.
- Updated `resources/views/pages/auth/verify-email.blade.php`:
  - Added quick switch button to "Enter 6-Digit OTP Code" if OTP mode is active.
- Updated `resources/views/pages/admin/settings/⚡email.blade.php`:
  - Added "Registration Email Verification Strategy" section with visual radio cards and status badges.

---

### B. Admin User Impersonation ("Login as User Anonymously")

#### 1. System Setting Toggle
- Added `system.allow_user_impersonation` setting in the `Setting` model under group `system` (default `true`).
- Managed via switch toggle in **System Settings & Maintenance** (`pages::admin.settings.system` / `⚡system.blade.php`).

#### 2. Impersonation Service & Authorization Engine
- Created `App\Services\ImpersonationService`:
  - `isAllowed(): bool`: Checks `system.allow_user_impersonation` setting.
  - `isImpersonating(): bool`: Checks if session contains `impersonator_id`.
  - `getImpersonatorId(): ?int`: Retrieves root admin ID from session.
  - `getImpersonator(): ?User`: Retrieves root administrator `User` model.
  - `canImpersonate(?User $actor, ?User $target): bool`:
    - Ensures feature is enabled in system settings.
    - Ensures actor cannot impersonate themselves.
    - Prevents impersonation of soft-deleted accounts.
    - Enforces administrative roles (`Super Administrator`, `Administrator`) or `users.impersonate` permission.
    - Prevents non-Super Administrators from impersonating Super Administrators.
  - `impersonate(User $actor, User $target): array`:
    - Stores `impersonator_id` and `impersonated_at` in session.
    - Records `user_impersonation_started` in `AuditLog`.
    - Authenticates target user via `Auth::login($target)`.
  - `leave(): array`:
    - Records `user_impersonation_ended` in `AuditLog`.
    - Clears impersonation session keys.
    - Re-authenticates root administrator and redirects back to Users Management.

#### 3. Controller & Routes
- Created `App\Http\Controllers\Admin\ImpersonationController`:
  - `impersonate(Request $request, User $user)`: Initiates impersonation session.
  - `leave(Request $request)`: Ends impersonation session.
- Registered routes in `routes/web.php`:
  - `POST /admin/impersonate/{user}` (`admin.impersonate`)
  - `POST /admin/impersonate/leave` (`admin.impersonate.leave`)

#### 4. UI & Layout Integration
- **Users Management Directory** (`resources/views/pages/admin/⚡users.blade.php`):
  - Added `impersonateUser(int $id)` Livewire action.
  - Added "Login as User" item in table actions dropdown for each eligible user row.
  - Added "Login as User" button inside the User Dossier detail drawer modal.
- **Top Notification Banner** (`resources/views/layouts/app.blade.php`):
  - Sticky warning alert rendered whenever `session()->has('impersonator_id')`.
  - Displays: `"Impersonation Mode: Browsing as [Name] ([Email]) — Signed in via Administrator [AdminName]."`
  - Features an instant "Switch Back to Admin" button.

---

## 3. Files Created & Modified

| Action | Path | Description |
|---|---|---|
| **Created** | `app/Services/ImpersonationService.php` | Impersonation business logic, permission rules, session handling, and audit logging |
| **Created** | `app/Http/Controllers/Admin/ImpersonationController.php` | HTTP Controller handling start and termination of impersonation sessions |
| **Created** | `tests/Feature/Auth/EmailOtpVerificationTest.php` | Automated tests for email OTP registration, verification mode switching, and OTP validation |
| **Created** | `tests/Feature/Admin/ImpersonationTest.php` | Automated tests for admin impersonation, access control, leave session, and setting toggle |
| **Modified** | `app/Services/EmailService.php` | Added `getVerificationMode()` and `isOtpVerification()` helper methods |
| **Modified** | `app/Services/OtpService.php` | Updated `verify()` to match equivalent registration and verification OTP types |
| **Modified** | `app/Actions/Fortify/CreateNewUser.php` | Dispatches email OTP or verification link on registration based on system setting |
| **Modified** | `app/Http/Middleware/EnsurePhoneAndEmailVerified.php` | Redirects unverified email users to OTP or Link verification screen based on setting |
| **Modified** | `app/Http/Controllers/Auth/OtpVerificationController.php` | Added team-aware route redirect upon OTP verification |
| **Modified** | `routes/web.php` | Registered `admin.impersonate` and `admin.impersonate.leave` routes |
| **Modified** | `resources/views/layouts/app.blade.php` | Added sticky impersonation alert banner with "Switch Back to Admin" action |
| **Modified** | `resources/views/pages/admin/settings/⚡email.blade.php` | Added "Registration Email Verification Strategy" setting and UI controls |
| **Modified** | `resources/views/pages/admin/settings/⚡system.blade.php` | Added `allow_user_impersonation` toggle setting and UI controls |
| **Modified** | `resources/views/pages/admin/⚡users.blade.php` | Added `impersonateUser` method and "Login as User" action in table and dossier modal |
| **Modified** | `resources/views/pages/auth/verify-email.blade.php` | Added direct button to switch to OTP code input when in OTP mode |

---

## 4. Test Verification Summary

- **Feature Tests**:
  - `EmailOtpVerificationTest`: 7/7 passed.
  - `ImpersonationTest`: 6/6 passed.
- **Full Application Suite**:
  - `264 passed, 0 failed (960 assertions)`.
- **Code Style**:
  - Formatted with Laravel Pint (`vendor/bin/pint --format agent`).
