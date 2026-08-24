# Production-Grade RBAC, Extended User Profile Schema, Import/Export & Audit Trail System

**Date:** 24 August 2026  
**Status:** Completed & Verified (250/250 Automated Tests Passing)

---

## 1. Executive Summary

This release delivers an enterprise-grade Role-Based Access Control (RBAC), User Management, and Security Audit system designed for high availability, compliance, and multi-format data interchange (Excel, CSV, PDF). It extends the primary `users` database schema with comprehensive personal, identity, and academic attributes, introduces automated lockout mechanisms, provides soft-delete with restoration/purge lifecycles, and offers self-service user activity logging alongside administrative system-wide audit trail inspection.

---

## 2. Database Schema Additions & Migrations

### Migration: `database/migrations/2026_08_24_100000_add_rbac_and_profile_fields_to_users_table.php`

The `users`, `roles`, and `permissions` tables were enhanced with the following columns:

| Table | Column | Type | Index / Constraints | Purpose |
|---|---|---|---|---|
| `users` | `uuid` | `UUID` | Unique, Index | Globally unique identifier for APIs and integrations |
| `users` | `urn` | `VARCHAR(50)` | Unique, Index | Institutional Unique Registration Number (e.g. `SNTCSSC-2026-00001`) |
| `users` | `first_name` | `VARCHAR(255)` | Nullable | User first name |
| `users` | `last_name` | `VARCHAR(255)` | Nullable | User last name / surname |
| `users` | `whatsapp_no` | `VARCHAR(25)` | Nullable | WhatsApp communication number |
| `users` | `dob` | `DATE` | Nullable | Date of birth |
| `users` | `gender` | `VARCHAR(20)` | Nullable | Gender (`male`, `female`, `other`) |
| `users` | `tenth_roll` | `VARCHAR(50)` | Nullable | 10th standard board examination roll number |
| `users` | `id_type` | `VARCHAR(50)` | Nullable | Document type (`aadhaar`, `pan`, `voter_id`, `passport`, `driving_license`) |
| `users` | `id_number` | `VARCHAR(100)` | Nullable | Government identity card / document number |
| `users` | `designation` | `VARCHAR(150)` | Nullable | Institutional designation / academic title |
| `users` | `status` | `VARCHAR(30)` | Default `'active'`, Index | Account lifecycle state (`active`, `inactive`, `locked`, `suspended`, `invited`, `pending`) |
| `users` | `last_login_at` | `TIMESTAMP` | Nullable | Timestamp of most recent successful session authentication |
| `users` | `last_login_ip` | `VARCHAR(45)` | Nullable | IP address of last successful authentication |
| `users` | `failed_login_attempts`| `UNSIGNED INT` | Default `0` | Consecutive failed password attempts counter |
| `users` | `locked_untill` | `TIMESTAMP` | Nullable | Security lockout expiry timestamp |
| `users` | `deleted_by` | `FOREIGN KEY` | Nullable &rarr; `users.id` | User who initiated soft deletion |
| `users` | `updated_by` | `FOREIGN KEY` | Nullable &rarr; `users.id` | User who last updated the record |
| `users` | `created_by` | `FOREIGN KEY` | Nullable &rarr; `users.id` | User who created the record |
| `users` | `deleted_at` | `TIMESTAMP` | Nullable (`SoftDeletes`) | Soft-delete timestamp |
| `roles` | `description` | `VARCHAR(500)` | Nullable | Human-readable explanation of role responsibilities |
| `roles` | `color` | `VARCHAR(30)` | Default `'emerald'` | UI badge accent color token |
| `roles` | `is_system` | `BOOLEAN` | Default `false` | Prevents accidental deletion of protected root roles |
| `permissions` | `module` | `VARCHAR(50)` | Default `'General'`, Index | Functional grouping category for permission grouping |
| `permissions` | `description` | `VARCHAR(500)` | Nullable | Detailed capability description |

---

## 3. Core Models & Architectural Services

### Models:
1. **`App\Models\User`**:
   - Added `SoftDeletes`, `HasRoles` (`Spatie\Permission\Traits\HasRoles`), and `Auditable` traits.
   - Implemented automated sequential URN generation (`User::generateNextUrn()`) and UUID initialization.
   - Synchronized `first_name` and `last_name` with `name` attribute.
   - Added security methods: `isLocked()`, `lockAccount()`, `unlockAccount()`, `recordLogin()`, `recordFailedLogin()`, `statusBadgeColor()`.
   - Added relationship methods: `createdByUser()`, `updatedByUser()`, `deletedByUser()`, `auditLogs()`.
2. **`App\Models\Role`**:
   - Extends Spatie's Role model with `Auditable` trait.
   - Added scopes: `scopeCustom()`, `scopeSystem()`.
   - Added safety helper: `isDeletable()` checking system lock status and active assigned user count.
3. **`App\Models\Permission`**:
   - Extends Spatie's Permission model with `Auditable` trait and `scopeForModule()`.

### Services:
1. **`App\Services\RbacService`**:
   - Manages role creation, cloning, updating, and safe deletion.
   - Manages dynamic permission key creation, editing, and deletion.
   - Provides user role synchronization (`syncUserRoles()`), direct permission synchronization (`syncUserPermissions()`), account lock/unlock, and soft delete/restore/force delete.
   - Seeds standard default roles (`Super Administrator`, `Administrator`, `Admissions Officer`, `Faculty`, `Accountant`, `Staff`, `Student`) and 30+ categorized permissions across 10 modules.
2. **`App\Services\UserExportImportService`**:
   - **CSV Export**: High-performance streaming export.
   - **Excel Export**: Styled `.xlsx` spreadsheet download via `Maatwebsite\Excel`.
   - **PDF Export**: Landscape printable directory report via `Barryvdh\DomPDF` with institution branding.
   - **Batch Import**: Validates CSV / Excel files with row-by-row diagnostics, duplicate email detection, automatic role assignment, URN generation, and DB transaction rollback on critical errors.
   - **Download Template**: Pre-formatted CSV sample template with mock fields.
3. **`App\Services\RolePermissionExportService`**:
   - Export matrix of roles and granted permissions to Excel, CSV, and PDF.

---

## 4. Modern Livewire UI / UX Components

1. **`resources/views/pages/admin/⚡users.blade.php`**:
   - **Interactive DataTable**: Real-time debounce search, multi-field query matching (`name`, `email`, `phone`, `whatsapp_no`, `urn`, `tenth_roll`, `id_number`, `designation`).
   - **Multi-dimensional Filters**: Role, Status (`active`, `inactive`, `locked`, `suspended`, `invited`, `pending`), Soft-Deleted filter (`Active Records Only`, `Include Soft-Deleted`, `Soft-Deleted Only`), Per-page selector.
   - **User Form Modal**: Clean 3-tab layout (1. Personal & Contact, 2. Identity & Academic, 3. Access & Password) supporting create and edit modes.
   - **User Dossier Drawer Modal**: Detailed identity card, biometric identifiers, URN, assigned privileges, and quick link to audit trail.
   - **Lock / Unlock Modal**: Configurable lockout duration (15m, 1h, 12h, 24h, 7d, 1y) with reason tracking.
   - **Bulk Actions**: Bulk activate, deactivate, soft-delete with instant counter.
   - **Import / Export**: Modal with sample template download and diagnostic results.
   - Fully mobile-responsive cards view and light/dark theme compatibility.

2. **`resources/views/pages/admin/⚡roles.blade.php`**:
   - **Split Pane Interface**: Left pane role selector showing system status, user counts, and color badges.
   - **Permission Matrix**: Module-grouped interactive toggle switches with "Select All" / "Deselect All" per module and "Grant All" / "Revoke All" for the active role.
   - **Role Management**: Create custom role with color picker, clone role with all permissions duplicated, and safe delete with validation guard.
   - **Matrix Export**: Export full authorization matrix to Excel, CSV, or PDF.

3. **`resources/views/pages/admin/⚡permissions.blade.php`**:
   - Dynamic permissions catalog with module filtering, search, pagination, and CRUD modal for custom permission tokens.

4. **`resources/views/pages/admin/⚡user-activity.blade.php`**:
   - User self-service activity and security trail view with timeline presentation, authentication event metrics, date filtering, and visual diff inspector.

---

## 5. Security & Authentication Enhancements

1. **`App\Listeners\LogAuthenticationEvents`**:
   - Upgraded to automatically trigger `$user->recordLogin()` on successful login.
   - Records failed login counter and triggers auto-lockout when threshold is exceeded.
   - Records audit logs for all login, logout, failed login, and password reset events.
2. **Account Deletion Safeguards**:
   - Back-office administrators use soft deletion (`deleted_at` with audit attribution).
   - User self-service deletion permanently purges account as confirmed in deletion dialogue.

---

## 6. Routes & Navigation

- Added `admin.permissions.index` (`/permissions`) to web routes and sidebar navigation.
- Added `admin.user-activity.index` (`/my-activity`) to web routes and sidebar navigation under `USER & ACCESS MANAGEMENT`.

---

## 8. Layout Optimization, Dedicated UrnGenerator & Localization

1. **Dedicated `UrnGenerator` Action (`App\Actions\Support\UrnGenerator`)**:
   - Extracted URN generation into a dedicated action class generating timestamp and microsecond-based 14-digit identifiers (`Ymd` + 6-digit microsecond suffix) with database collision verification.
   - Updated `App\Models\User` to call `UrnGenerator::generate()`.

2. **Audit Logs & My Activity Layout Overflow Fixes**:
   - Converted filter areas in `⚡audit-logs.blade.php` and `⚡user-activity.blade.php` to responsive 12-column CSS grids (`grid-cols-1 md:grid-cols-12`) with `min-w-0` on input wrappers.
   - Fixed date-range picker inputs and dropdown truncation to prevent horizontal overflowing across desktop and mobile devices.

3. **Lucide Icons Expansions (`App\Support\LucideIcons`)**:
   - Added SVG path definitions for missing icons: `check-check`, `folder-lock`, `user-check`, `user-x`, `user-minus`, `check-square`, `square`, `slash`, `unlock`, `x-circle`, `alert-octagon`.

4. **Comprehensive Multi-Language Localization**:
   - Synchronized all 337+ RBAC, audit log, user management, and security action keys across `lang/en.json` (English), `lang/hi.json` (Hindi), and `lang/bn.json` (Bengali), bringing total localized phrases to 1,282 keys.

---

## 10. Extended Profile & Preferences Integration

1. **Comprehensive Profile Field Inputs**:
   - Integrated full set of user attributes into [`⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1profile.blade.php) and [`pages/settings/⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/settings/%E2%9A%A1profile.blade.php):
     - **Personal Details**: `first_name`, `last_name`, `name` (synchronized), `email`, `phone`, `whatsapp_no`, `dob` (date of birth), `gender` (male, female, other).
     - **Identity & Academic Details**: `designation`, `tenth_roll` (10th Board Roll No.), `id_type` (Aadhaar, PAN, Voter ID, Passport, Driving License), `id_number`.
   - Updated `App\Concerns\ProfileValidationRules` to validate all extended user attributes.

2. **Dossier & Security Metadata Card**:
   - Embedded real-time institutional identity summary on profile pages: `URN`, `UUID`, assigned `Roles` badges, direct `Permissions` count, `Last Login` timestamp, `Last Login IP`, and `Member Since`.

---

## 11. Super Administrator Seeder & Enhanced Input Validation UX

1. **Dedicated `SuperAdminSeeder` (`database/seeders/SuperAdminSeeder.php`)**:
   - Creates/ensures default Super Administrator account:
     - **Email**: `admin@sntcssc.in`
     - **Password**: `Password@1234`
     - **Role**: `Super Administrator`
     - **Status**: `active`
     - **Team**: Automatically assigns/creates personal team with Owner privileges.
   - Connected directly to `database/seeders/DatabaseSeeder.php`.

2. **Required Field Validation Indicators & Form Flash Notifications**:
   - **UI Input Components** (`components/ui/input.blade.php`, `components/ui/select.blade.php`, `components/ui/password.blade.php`):
     - Added red iconized error feedback (`alert-circle` icon + red text) and highlight ring on validation failure.
     - Added support for `error` and `hint` props across select dropdowns.
   - **Livewire Components** (`⚡users.blade.php`, `⚡roles.blade.php`, `⚡permissions.blade.php`, `⚡profile.blade.php`):
     - Automatically catches validation exceptions, dispatches a user-friendly error flash notification (`Please fill in all required fields properly.`), and switches to the tab containing the empty required field.
     - Added active red dot badges on tab headers indicating which tab contains unfulfilled required fields.

---

## 12. Dynamic RBAC Navigation Filtering & Route Authorization

1. **Super Administrator Root Access Gate (`App\Providers\AppServiceProvider`)**:
   - Registered global `Gate::before` hook granting instantaneous authorization bypass for accounts assigned the `'Super Administrator'` role across all Laravel gates, policies, and `can:...` middleware.

2. **Dynamic Sidebar RBAC Filtering (`resources/views/layouts/app/sidebar.blade.php`)**:
   - Attached specific permission keys to every navigation module (e.g. `students.view`, `admissions.view`, `courses.manage`, `batches.manage`, `users.view`, `roles.view`, `permissions.manage`, `audit.view`, `settings.general`, `settings.backup`, `settings.cron`, etc.).
   - Dynamically filters `$navSections` to only render items and categories the authenticated user has explicit permission or role to access.
   - Hides empty section headers when a user lacks access to all items in that category.
   - Synchronized across desktop expanded mode, desktop collapsed icon mode, and mobile drawer mode.

3. **Backend Route Authorization Middleware (`routes/web.php`)**:
   - Protected all administrative routes with `middleware('can:<permission>')` to enforce server-side HTTP 403 Forbidden protection on direct URL access attempts by unauthorized users.

---

## 13. Submenu Permission Filtering, Teams Route Protection & Topbar Header Avatar

1. **Submenu Children RBAC Filtering (`resources/views/layouts/app/sidebar.blade.php`)**:
   - Fixed array collection mapping so submenu children (e.g. `System settings` and `Teams` under `Settings`) are properly filtered by permissions.
   - Non-super-admin / non-privileged users will only see `Profile settings` and `Security` in the Settings dropdown, with `System settings` and `Teams` hidden.

2. **Teams Route Protection (`routes/settings.php`)**:
   - Protected `settings/teams` and `settings/teams/{team}` with `middleware('can:settings.general')` to prevent unauthorized direct URL access.

3. **Header Topbar Real-Time Avatar Integration (`resources/views/layouts/app/topbar.blade.php`)**:
   - Passed `:src="$user->avatarUrl()"` to `<x-ui.avatar>` in the topbar header and dropdown menu.
   - User uploaded avatars are displayed in the header, with fallback to initial letter badges when no image is uploaded.
   - Dynamic user role name displayed next to username instead of static text.

---

## 14. Prominent Livewire File Upload Progress & Centered Spinner UX

1. **Global Floating Upload Progress Card (`resources/views/layouts/app.blade.php`)**:
   - Added a top-center floating status card that listens to global Livewire upload events (`livewire-upload-start`, `livewire-upload-progress`, `livewire-upload-finish`, `livewire-upload-error`).
   - Displays an animated spinner, live numerical percentage (`75%`), and a progress bar.
   - Smoothly slides down and fades in when any file begins uploading anywhere in the application, and transitions away once complete.

2. **Avatar Upload Overlay (`resources/views/pages/admin/⚡profile.blade.php`, `resources/views/pages/settings/⚡profile.blade.php`)**:
   - Enhanced the avatar circular container with dedicated Alpine upload event listeners and a centered backdrop blur overlay with spinner and live percentage counter.

3. **Logo & Favicon File Upload Component (`resources/views/components/ui/file-upload.blade.php`)**:
   - Enhanced `<x-ui.file-upload>` with centered upload progress overlay, spinner, and real-time animated percentage progress bar during file transfers.

---

## 15. Verification & Automated Test Suite

- Created **`tests/Feature/UserManagementTest.php`** (9 tests covering schema, locking, soft deletes, Livewire filtering, CSV/Excel export, batch import, profile updates, super admin seeder, and form validation).
- Created **`tests/Feature/RbacTest.php`** (11 tests covering role seeding, custom role lifecycle, clone, permission CRUD, toggle matrix, user activity, export services, super admin unrestricted access, 403 forbidden access on unauthorized roles, sidebar submenu permission filtering, teams route protection, and topbar avatar rendering).
- Formatted all code cleanly using Laravel Pint (`vendor/bin/pint --format agent`).
- Full Pest test suite verified: **299 passed / 299 tests** (1,090 assertions).
