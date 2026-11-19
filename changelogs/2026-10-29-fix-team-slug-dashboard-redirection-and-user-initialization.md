# Fix Team Slug Dashboard Redirection, OTP Login Routing, User Team Assignment, and RBAC User Deletion

**Date:** 29 October 2026  
**Status:** Completed & Verified (45/45 Feature Tests Passing, 222 Assertions)

---

## 1. Executive Summary

This update resolves multiple critical authentication, user management, and routing issues in the SNT CSSC MIS portal:
1. **Missing Route Parameter (`current_team`) on Dashboard Redirect**: When an admin added a new user or student from the admin panel, the user had no team initialized. During OTP verification, generating the dashboard route failed with `Missing required parameter for [Route: dashboard] [URI: {current_team}/dashboard] [Missing parameter: current_team]`.
2. **Undefined Method Exception (`Call to undefined method App\Models\User::allTeams()`)**: In fallback redirect handlers and authentication responses, `$user->allTeams()` was invoked to locate a fallback team when `currentTeam` was unassigned. This method was missing on the `User` model and `HasTeams` trait.
3. **Admin 403 Forbidden After OTP Login (`{URL}/{userid}/dashboard`)**: When logging in as `admin@sntcssc.in` with OTP, the controller passed the user's/team's integer ID (`1`) instead of the team's URL slug (`super-admins-team`). The resulting URL (`/1/dashboard`) could not be resolved by slug in `EnsureTeamMembership`, resulting in an unintended `403 Forbidden` error.
4. **Undefined Method Exception (`Call to undefined method App\Services\RbacService::softDeleteUser()`)**: When attempting to soft-delete, restore, or force-delete a user in the Admin Panel (`⚡users.blade.php`), the controller called `RbacService::softDeleteUser()`, `RbacService::restoreUser()`, and `RbacService::forceDeleteUser()`, which were not defined in `App\Services\RbacService`.
5. **Admin-Created Users Given New Personal Teams Instead of Existing Team**: When an administrator added a new user or student from the admin panel, a brand new personal team (e.g., "Student Name's Team") was being created for that user, rather than assigning them as a member into the administrator's existing organization team (`super-admins-team`).

All these issues have been completely resolved with automatic team assignment to existing organization teams, slug-based routing, numeric ID auto-canonical redirection, RBAC deletion services with full audit logs, and extensive unit/feature test coverage.

---

## 2. Root Cause Analysis

### A. Missing `current_team` Route Parameter & New Personal Team Creation
- Newly created users from the Admin Panel (`⚡users.blade.php`), Students Management (`⚡students.blade.php`), and CSV import (`UserExportImportService.php`) were created via `User::create(...)` without team initialization.
- In `App\Http\Controllers\Auth\OtpLoginController`:
  ```php
  $redirectUrl = route('dashboard', ['current_team' => $user->current_team_id ?: $user->personalTeam()?->id]);
  ```
  Since both `current_team_id` and `personalTeam()` returned `null`, `['current_team' => null]` was passed to Laravel's route generator, triggering `Missing required parameter for [Route: dashboard]`.
- Furthermore, when team initialization was first introduced, it automatically generated an isolated personal team for every user. In an institutional MIS environment, users and students should instead be attached as members of the existing organization team (the admin's active team).

### B. Undefined Method `User::allTeams()`
- When accessing the top-level `/dashboard` redirect route or when authenticating through `RedirectsToCurrentTeam`, the fallback lookup checked:
  ```php
  $team = $user->currentTeam ?? $user->personalTeam() ?? $user->allTeams()->first();
  ```
  The trait `App\Concerns\HasTeams` defined `teams()`, `ownedTeams()`, and `currentTeam()`, but lacked `allTeams()`. When a user had no `currentTeam`, evaluating `$user->allTeams()` threw `BadMethodCallException: Call to undefined method App\Models\User::allTeams()`.
- Additionally, `RedirectsToCurrentTeam` attempted `$user->ownedTeams()->create(...)`, which failed because `HasManyThrough` relationships in Laravel do not support `create()`, and the `teams` schema uses `is_personal`, not `personal_team`.

### C. Admin `{URL}/{userid}/dashboard` 403 Forbidden Error
- For `admin@sntcssc.in`, `current_team_id` was `1`. Passing `$user->current_team_id` into `route('dashboard', ['current_team' => ...])` generated `/{team_id}/dashboard` (`/1/dashboard`).
- `App\Http\Middleware\EnsureTeamMembership` queried teams strictly by slug (`Team::where('slug', $team)->first()`). Querying slug `"1"` yielded `null` because the slug is `'super-admins-team'`, prompting `abort_if(! $user || ! $team || ! $user->belongsToTeam($team), 403)`.

### D. Missing `RbacService::softDeleteUser()` and Related Deletion Methods
- In `resources/views/pages/admin/⚡users.blade.php`:
  - `softDelete()` invoked `RbacService::softDeleteUser($user)`.
  - `restoreUser()` invoked `RbacService::restoreUser($user)`.
  - `forceDelete()` invoked `RbacService::forceDeleteUser($user)`.
  - `bulkDelete()` invoked `RbacService::softDeleteUser($u)`.
- None of these methods existed on `App\Services\RbacService`, resulting in a fatal `BadMethodCallException`.

---

## 3. Detailed Changes & Architecture

### 1. Existing Team Assignment & HasTeams Enhancements (`app/Concerns/HasTeams.php`)
- **Added `assignToTeam(Team $team, TeamRole $role = TeamRole::Member): bool`**:
  Attaches the user to an existing team with the specified role (`TeamRole::Member` by default) via `syncWithoutDetaching` and switches the user's active team to that team.
- **Added `allTeams(): Collection`**:
  Returns `$this->teams()->get()`, satisfying calls across `ChatJoinController`, `MeetingJoinController`, redirect responses, and tests.
- **Added `createPersonalTeam(?string $name = null): Team`**:
  Invokes `App\Actions\Teams\CreateTeam` to create a personal team record, register the user as `TeamRole::Owner`, and switch the user's active team.
- **Enhanced `personalTeam(): ?Team`**:
  Updated to query only teams where the user is an owner:
  ```php
  return $this->teams()
      ->wherePivot('role', TeamRole::Owner->value)
      ->where('is_personal', true)
      ->first();
  ```
  Prevents members of the organization team from mistakenly having the shared team identified as their personal team.
- **Enhanced `ensureCurrentTeam(): Team`**:
  Guarantees that every user has a valid active team:
  1. If `currentTeam` is loaded and valid, sets `URL::defaults` and returns it.
  2. If `current_team_id` is present, queries and associates the team.
  3. If unassigned, checks `personalTeam()` or any existing team in `teams()`.
  4. If the user possesses no team memberships, checks for an existing organization team (`Team::where('slug', 'super-admins-team')->orWhere('is_personal', false)->oldest('id')->first() ?? Team::oldest('id')->first()`) and assigns the user to it as a `TeamRole::Member`.
  5. Only if no teams exist in the entire database does it create a new personal team.
  6. Updates `URL::defaults(['current_team' => $team->slug, 'team' => $team->slug])`.
- **Updated `switchTeam()`**:
  Populates both `'current_team'` and `'team'` keys in `URL::defaults`.

### 2. Admin Creation Flow: Assign to Existing Team
- **`resources/views/pages/admin/⚡users.blade.php`**:
  When an admin adds a new user, automatically assigns the user to the admin's existing team:
  ```php
  $targetTeam = auth()->user()?->currentTeam
      ?? auth()->user()?->personalTeam()
      ?? Team::where('slug', 'super-admins-team')->first()
      ?? Team::first();

  if ($targetTeam) {
      $user->assignToTeam($targetTeam, TeamRole::Member);
  } else {
      $user->ensureCurrentTeam();
  }
  ```
- **`resources/views/pages/admin/⚡students.blade.php`**:
  Applies the same existing team assignment logic when an admin creates a student.
- **`app/Services/UserExportImportService.php`**:
  Applies the existing team assignment logic to all imported users.

### 3. RBAC User Deletion & Restoration Methods (`app/Services/RbacService.php`)
- **`softDeleteUser(User $user, ?User $actor = null): bool`**:
  Executes in a database transaction, records `deleted_by` as the acting admin's ID, executes soft deletion, and logs an `AuditLog` event (`user_soft_deleted`).
- **`restoreUser(User $user, ?User $actor = null): bool`**:
  Clears `deleted_by`, executes restoration, and logs an `AuditLog` event (`user_restored`).
- **`forceDeleteUser(User $user, ?User $actor = null): bool`**:
  Permanently purges the user record and creates an audit log entry (`user_force_deleted`).
- **Self-Deletion Guard**:
  Added safety checks in `⚡users.blade.php` preventing admins from soft-deleting or purging their own active accounts.

### 4. OTP Login & Verification Redirection
- **`app/Http/Controllers/Auth/OtpLoginController.php`**:
  Replaced numeric ID assignment with:
  ```php
  $team = $user->ensureCurrentTeam();
  $redirectUrl = route('dashboard', ['current_team' => $team->slug]);
  ```
  Guarantees that `redirect_url` always uses the team's URL slug and never throws missing parameter exceptions.
- **`app/Http/Controllers/Auth/OtpVerificationController.php`**:
  Ensures `$team = $targetUser?->ensureCurrentTeam()` is called before generating the dashboard route with `$team->slug`.

### 5. Middleware URL Defaults & Security Enforcement
- **`app/Http/Middleware/SetTeamUrlDefaults.php`**:
  Calls `$user->ensureCurrentTeam()` on all authenticated web requests, guaranteeing that route helpers like `route('dashboard')` anywhere in Blade templates or Livewire components automatically receive the default parameter.
- **`app/Http/Middleware/EnsureTeamMembership.php`**:
  - Enhanced `team()` helper to inspect both `slug` and numeric `id` when resolving teams (`Team::where('slug', $team)->when(is_numeric($team), fn ($q) => $q->orWhere('id', (int) $team))->first()`).
  - Added auto-canonical redirection: If a user accesses a URL using a numeric team ID (e.g., `/1/dashboard`), the middleware automatically redirects them to the canonical slug URL (e.g., `/super-admins-team/dashboard`).
  - Exempted `Super Administrator` from team membership aborts to prevent unintentional lockouts across administrative workflows.

### 6. Institutional Team Rebranding: `SNT CSSC` (`sntcssc`)
- **Renamed Organization Team**:
  Rebranded the primary institutional team from `"Super Admin's Team"` (slug: `super-admins-team`, `is_personal: true`) to `"SNT CSSC"` (slug: `sntcssc`, `is_personal: false`).
- **Standardized URL Structure**:
  All dashboard and portal URLs now render as `/{sntcssc}/dashboard`, providing an institutional, clean aesthetic suitable for the institute.
- **Backwards Compatibility & Legacy URL Redirects**:
  In `EnsureTeamMembership` middleware, any requests to legacy URLs (such as `/super-admins-team/dashboard` or numeric team IDs) are automatically resolved and canonically redirected to `/sntcssc/dashboard`.
- **Seeder Update (`SuperAdminSeeder.php`)**:
  Updated to initialize and maintain the organization team as `"SNT CSSC"` with slug `'sntcssc'`.

### 7. Web Routes & Login Response
- **`routes/web.php`**:
  Updated top-level `/dashboard` fallback redirect to resolve `$user->ensureCurrentTeam()` and redirect directly to `route('dashboard', ['current_team' => $team->slug])`.
- **`app/Http/Responses/Concerns/RedirectsToCurrentTeam.php`**:
  Replaced broken `ownedTeams()->create()` logic with `$user->ensureCurrentTeam()`.

---

## 4. Verification & Automated Test Suite

- **Automated Tests Added / Updated**:
  - `tests/Feature/UserManagementTest.php`:
    - `admin can soft delete a user and restore via livewire`
    - `admin can force delete a user via livewire`
    - `admin cannot soft delete own account`
    - `user created by admin is assigned into admin existing team instead of creating new personal team`
    - Verified `User::where('email', ...)->first()->current_team_id === $admin->currentTeam->id`.
  - `tests/Feature/Auth/OtpAuthenticationTest.php`:
    - `user created without a team can log in via OTP and is redirected to their team dashboard with team slug`
    - `admin user login via OTP redirects to team slug URL and can access dashboard without 403`
    - `numeric ID access redirects canonically to team slug URL`
    - `student user without team hitting dashboard redirect does not throw undefined method allTeams`
- **Test Results**:
  ```powershell
  php artisan test --compact tests/Feature/UserManagementTest.php tests/Feature/Auth/OtpAuthenticationTest.php tests/Feature/DashboardTest.php tests/Feature/Auth/AuthenticationTest.php
  # {"tool":"pest","result":"passed","tests":45,"passed":45,"assertions":222,"duration_ms":14175}
  ```
- **Code Style**:
  Formatted all modified PHP files using Laravel Pint (`php vendor/bin/pint --dirty --format agent`).
