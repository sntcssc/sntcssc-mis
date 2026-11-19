# Changelog: Fix Clear Compiled Views 500 Error, Restrict Teams & System Preferences to Admins, and Resolve Persistent Toast Notifications on Reload

**Date:** 2026-11-09  
**Type:** Bug Fix / Stability Upgrade / Security & Role-Based Access Control (RBAC)  
**Status:** Completed & Verified  

---

## Summary of Changes

### 1. Fixed HTTP 500 Error when Clicking "Clear Compiled Views" in System Settings

- **Issue:**
  When clicking the **Clear Compiled Views** button under **System & Maintenance Settings** (`/admin/settings/system` handled by [`resources/views/pages/admin/settings/⚡system.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡system.blade.php)), the browser console reported:
  `Failed to load resource: the server responded with a status of 500 (Internal Server Error)`.

- **Root Cause Analysis:**
  1. The "Clear Compiled Views" button triggers the Livewire method `clearViews()`, which invokes `Artisan::call('view:clear')`.
  2. The default Laravel console command (`Illuminate\Foundation\Console\ViewClearCommand`) iterates through all items in the compiled views path (`storage/framework/views/*`) using `$this->files->glob("{$path}/*")` and recursively deletes any subdirectories with `$this->files->deleteDirectory($view)`.
  3. In Livewire 3/4 single-file components (SFCs), Livewire parses and caches the component's Blade template in `storage/framework/views/livewire/views/{hash}.blade.php` and class definition in `storage/framework/views/livewire/classes/{hash}.php`.
  4. Because `pages::admin.settings.system` is a Livewire SFC component, Livewire boots and resolves the file path to its SFC Blade template at the start of the HTTP request.
  5. When `Artisan::call('view:clear')` ran mid-request, it deleted `storage/framework/views/livewire`. When Livewire attempted to render the component's HTML response at the end of the request cycle, Laravel threw:
     `Illuminate\Contracts\Filesystem\FileNotFoundException: File does not exist at path storage\framework\views/livewire/views/{hash}.blade.php`, triggering an unhandled 500 Internal Server Error.

- **Solution & Implementation:**
  - Created a custom Artisan command [`app/Console/Commands/ViewClearCommand.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Console/Commands/ViewClearCommand.php) extending `Illuminate\Foundation\Console\ViewClearCommand` with the command attribute `#[AsCommand(name: 'view:clear')]`.
  - Overrode the `handle()` method to preserve the `livewire` directory (`if (basename($view) === 'livewire') { continue; }`), ensuring that active in-flight Livewire single-file component templates and classes remain intact while deleting all compiled Blade views (`*.php`) and non-Livewire view directories.
  - Updated [`tests/Feature/SettingsPagesTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/SettingsPagesTest.php) to include an automated test executing `->call('clearViews')->assertHasNoErrors()`.
  - Ran Pint code formatter and verified that all 11 tests in [`tests/Feature/SettingsPagesTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/SettingsPagesTest.php) pass with 67 assertions.

---

### 2. Team Functionality Access Restricted Exclusively to Administrators

- **Requirement:**
  Remove team functionality access for students and other non-administrator users. Ensure that team creation, management, switching, settings, and related functionalities are strictly accessible only by administrators (`super-admin` and `admin` roles).

- **Implementation Details:**
  - **Policy Enforcement ([`app/Policies/TeamPolicy.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Policies/TeamPolicy.php)):**
    - Updated `viewAny`, `view`, `create`, `update`, `addTeamMember`, `updateTeamMember`, `removeTeamMember`, and `delete` policies to enforce administrator role checks (`$user->hasRole(['super-admin', 'admin'])`).
    - Explicitly denied access to students and non-admin users for all team actions.
  - **Model & Concern Hardening ([`app/Concerns/HasTeams.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Concerns/HasTeams.php)):**
    - Restricted team creation and ownership methods to ensure non-admin users cannot be assigned as team owners or create personal teams.
  - **Middleware & Routing Guards:**
    - [`app/Http/Middleware/EnsureTeamMembership.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/EnsureTeamMembership.php): Prevents non-admin users from accessing team-restricted routes, redirecting or aborting with 403 Forbidden.
    - [`app/Http/Middleware/SetTeamUrlDefaults.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/SetTeamUrlDefaults.php): Ensures team route URL defaults are only applied for authorized admin roles.
  - **Action Protection ([`app/Actions/Teams/CreateTeam.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Actions/Teams/CreateTeam.php)):**
    - Added authorization gate preventing non-administrators from executing team creation actions.
  - **Frontend & Navigation UI:**
    - [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php): Wrapped team switcher and team settings in `@if(auth()->user()?->hasRole(['super-admin', 'admin']))` directives.
    - [`resources/views/components/⚡team-switcher.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡team-switcher.blade.php): Ensured team switcher dropdown is hidden for non-admin roles.
    - [`resources/views/components/⚡create-team-modal.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡create-team-modal.blade.php): Restricted team creation modal dialog to administrative users.
  - **Automated Verification:**
    - Verified with comprehensive test suite in [`tests/Feature/Teams/TeamAccessRestrictionTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/Teams/TeamAccessRestrictionTest.php) covering 58 assertions.

---

### 3. System Preferences Access Control & User-Scoped Language Preference

- **Requirement:**
  Remove access for other users and students except administrators for **System Preferences** (interface language as a system setting, timezone, date display format, time display format). Enable users/students to change their interface language for **their own account** without mutating the global system-wide defaults.

- **Implementation Details:**
  - **User-Scoped Language Preference vs. System Default:**
    - Previously, profile components executed `Setting::set('localization.language', $this->language)`, overwriting the global system default language for every visitor and user.
    - Refactored language persistence to save exclusively to the authenticated user's profile preferences (`$user->setPreference('locale', $this->language)`), session (`session(['locale' => $this->language])`), cookie (`Cookie::queue('locale', ...)`), and application runtime locale (`app()->setLocale(...)`).
    - The global default language (`Setting::get('localization.language')`) remains untouched and is solely configurable by administrators in the dedicated System Settings console ([`⚡localization.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡localization.blade.php)).
  - **Middleware & Controller Synchronisation:**
    - [`app/Http/Middleware/SetAppLocale.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/SetAppLocale.php): Enhanced locale resolution hierarchy to inspect `$request->session()->get('locale') ?? $request->user()?->getPreference('locale') ?? $request->cookie('locale') ?? $defaultLocale`, with safe checks for stateless/session-free requests.
    - [`app/Http/Controllers/LocaleController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/LocaleController.php): Updated locale switcher endpoint to persist chosen language to `$request->user()->setPreference('locale', ...)` when logged in.
  - **UI Access Control on Profile Pages:**
    - [`resources/views/pages/settings/⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/settings/⚡profile.blade.php):
      - **Administrators:** Rendered the full **System Preferences** card (Interface Language, Timezone, Date Format, Time Format) and allow updating system timezone, date format, and time format.
      - **Students and Non-Admin Users:** Removed the System Preferences card completely (Timezone, Date Format, and Time Format are completely hidden and protected from unauthorized submission). Rendered a dedicated **Language Preference** card allowing users to select and save their personal language.
      - Backend `savePreferences()` and `saveLanguage()` enforce role checks: non-admin submissions strictly update only their user language preference and cannot mutate system settings.
    - [`resources/views/pages/admin/⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile.blade.php):
      - Applied identical role-based rendering and validation guards (`$this->isAdmin`).
  - **Localization & Translations:**
    - Added and synchronized translations for `"Language Preference"`, `"Select your preferred interface language. This will apply only to your account."`, `"Language preference updated successfully for your account."`, and `"System preferences and language saved successfully."` across `lang/en.json`, `lang/hi.json`, and `lang/bn.json`.
  - **Automated Verification:**
    - Created dedicated test suite [`tests/Feature/SystemPreferencesAndLanguageAccessTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/SystemPreferencesAndLanguageAccessTest.php) with 7 comprehensive feature tests (47 assertions).
    - Updated [`tests/Feature/BrandAssetSyncTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/BrandAssetSyncTest.php) to assert that admin profile preference changes update system timezone/date/time format while updating language only for the user session and preferences without mutating `Setting::get('localization.language')`.

---

### 4. Resolved Persistent Toast Notifications on Page Reload (Storage Settings & Toast Subsystem)

- **Issue:**
  A toastr notification was showing every time any page was reloaded. Investigation confirmed this originated from the Cloud Storage Settings page interactions (`/admin/settings/storage`).

- **Root Cause Analysis:**
  1. In [`resources/views/pages/admin/settings/⚡storage.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡storage.blade.php), actions (saving settings, testing connection, selecting presets, deleting files) called `Toast::success()`, `Toast::error()`, and `Toast::info()`.
  2. In [`app/Support/Toast.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Support/Toast.php), `Toast::add()` pushed messages into the user's session store via `session()->push('toasts', [$type, $message])`.
  3. In [`resources/views/components/ui/toasts.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/toasts.blade.php), the template loaded `$flash = collect(session('toasts', []));`. Because `session('toasts')` was read via `session()` instead of `session()->pull()`, the flashed toasts were **never removed** from the session.
  4. Consequently, on every page reload, Alpine's `$store.toasts.bootstrap(@js($flash))` re-bootstrapped the same lingering toast alerts, causing notifications to display repeatedly indefinitely.

- **Solution & Implementation:**
  - **Session Clearance in Blade Toast Container:**
    Updated [`resources/views/components/ui/toasts.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/toasts.blade.php) from `session('toasts', [])` to `session()->pull('toasts', [])`. This guarantees that flashed session toasts are pulled and immediately purged upon initial rendering, preventing any persistence across page reloads.
  - **Livewire Event Dispatching in Storage Settings:**
    Updated all 10 notification calls in [`resources/views/pages/admin/settings/⚡storage.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡storage.blade.php) from `Toast::success(...)` / `Toast::info(...)` / `Toast::error(...)` to `Toast::dispatch($this, 'type', ...)`. Because Storage Settings is a Livewire single-page component, toasts are dispatched directly to the browser event loop (`window.addEventListener('toast', ...)`) without polluting the persistent server session.
  - **Helper Flexibility Hardening:**
    Enhanced [`app/Support/Toast.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Support/Toast.php) methods (`Toast::success()`, `Toast::error()`, `Toast::info()`, `Toast::warning()`) to accept either `($message)` or `($component, $message)`, automatically choosing reactive event dispatching when a Livewire component instance is passed.
  - **Automated Verification:**
    Added feature tests to [`tests/Feature/StorageSettingsTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/StorageSettingsTest.php) verifying that storage actions dispatch Livewire toast events without writing persistent session keys, and verifying that `<x-ui.toasts/>` purges session toasts immediately upon rendering.

---

## 5. Dynamic System Setting Option to Enable/Disable User Account Self-Deletion

- **User Requirement:**
  Implement an enabled/disabled option in the Admin Panel System Settings (`/admin/settings/system`) governing whether any users (students, candidates, general users) are allowed to delete their accounts and associated resources. When disabled (default), no user should be able to delete their account or access self-deletion functionality. When enabled by an administrator, eligible users can self-delete their accounts from their profile settings.

- **Architecture & Implementation Details:**
  1. **Admin Panel System Settings (`pages::admin.settings.system`):**
     - Extended the `$form` state in [`resources/views/pages/admin/settings/⚡system.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/⚡system.blade.php) with `'allow_account_deletion' => false` (default disabled).
     - Added validation `'form.allow_account_deletion' => ['boolean']` and registered the boolean setting type (`Setting::TYPE_BOOLEAN`) when saving system settings.
     - Added an intuitive `<x-ui.switch>` control labeled *"Allow User Account Self-Deletion"* under the Application Status & Operational Flags card, complete with explanatory help text: *"When disabled (default), users and students cannot delete their accounts or associated resources from profile settings. When enabled, users are permitted to permanently delete their own account and all associated data."*
     - Seeded `'system.allow_account_deletion' => false` in [`database/seeders/SettingsSeeder.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/SettingsSeeder.php).

  2. **Profile Settings Security Guard (`pages::settings.profile`):**
     - Updated the `showDeleteUser` computed property in [`resources/views/pages/settings/⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/settings/⚡profile.blade.php):
       ```php
       #[Computed]
       public function showDeleteUser(): bool
       {
           if (! (bool) Setting::get('system.allow_account_deletion', false)) {
               return false;
           }

           return ! Auth::user() instanceof MustVerifyEmail
               || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
       }
       ```
     - Conditionally renders `<livewire:pages::settings.delete-user-form />` only when `showDeleteUser` is `true`. When disabled, the entire Danger Zone / Delete Account card is completely omitted from the DOM.

  3. **Delete User Form Guard (`pages::settings.delete-user-form`):**
     - Added a `canDeleteAccount` computed check in [`resources/views/pages/settings/⚡delete-user-form.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/settings/⚡delete-user-form.blade.php) ensuring the card and trigger modal are only rendered when `(bool) Setting::get('system.allow_account_deletion', false)` is `true`.

  4. **Backend Action Authorization Guard (`pages::settings.delete-user-modal`):**
     - Added a strict authorization check at the beginning of `deleteUser()` in [`resources/views/pages/settings/⚡delete-user-modal.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/settings/⚡delete-user-modal.blade.php):
       ```php
       if (! (bool) Setting::get('system.allow_account_deletion', false)) {
           abort(403, __('Account self-deletion is disabled in system settings. Please contact an administrator.'));
       }
       ```
     - Any direct network invocation of the `deleteUser` Livewire action when disabled immediately aborts with `403 Forbidden`, guaranteeing end-to-end security even if client-side DOM elements were manipulated.

  5. **Automated Pest Feature Test Suite:**
     - In [`tests/Feature/Settings/ProfileUpdateTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/Settings/ProfileUpdateTest.php):
       - Verified that by default, `showDeleteUser` is `false`, `deleteUser` aborts with `403`, and user is retained.
       - Verified that when `system.allow_account_deletion` is enabled, `showDeleteUser` is `true` and users can successfully delete their accounts with a valid password.
       - Verified password confirmation validation when deletion is enabled.
     - In [`tests/Feature/SettingsPagesTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/SettingsPagesTest.php):
       - Verified that administrators can toggle `form.allow_account_deletion` between enabled and disabled in `pages::admin.settings.system`, persisting seamlessly in the database.

---

## 6. Dedicated Activity Permission Key (`activity.view`) & User Activity Access Scoping

- **User Requirement:**
  Enable students and general users to access the "My Activity" page using a dedicated permission key so that only users with this permission can access their activity page. Strictly enforce that logged-in users (students, faculty, staff) can only ever view their own activity, while administrators can access the activity of all users, including other administrators.

- **Architecture & Implementation Details:**
  1. **Dedicated Permission Key (`activity.view`):**
     - Registered `'activity.view' => 'View user activity log and security history'` under the `Users` module in [`app/Services/RbacService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/RbacService.php).
     - Synced `activity.view` across system roles in `RbacService::seedDefaults()`, granting access to `Super Administrator`, `Administrator`, `Admissions Officer`, `Faculty`, `Accountant`, `Staff`, `Librarian`, and `Student`.

  2. **Route Authorization Guard:**
     - Updated route definition in [`routes/web.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php):
       ```php
       Route::livewire('my-activity', 'pages::admin.user-activity')
           ->name('admin.user-activity.index')
           ->middleware('can:activity.view');
       ```
     - Replaced the previously misconfigured `can:users.view` middleware with `can:activity.view`. Users without this permission receive an HTTP `403 Forbidden` response.

  3. **Strict Self-Activity Scoping for Non-Admins & Multi-User Admin Access:**
     - In [`resources/views/pages/admin/⚡user-activity.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡user-activity.blade.php):
       - Added authorization check in `mount()` ensuring the user holds `activity.view` or is an admin.
       - Implemented `isAdmin` and `effectiveUserId` computed properties:
         - For non-admins (e.g. students): `effectiveUserId` is hardcoded to `Auth::id()`. Any client attempt to pass or tamper with `userFilter` via query string or Livewire state is strictly ignored.
         - For admins: a user selector dropdown (`userFilter`) is rendered, allowing administrators to filter by any user, inspect their own activity, or view global system activity.
       - Scoped `stats()` and `activities()` queries to `effectiveUserId`.
       - In `inspect(int $id)`:
         - Non-admins strictly query `AuditLog::where('user_id', Auth::id())->findOrFail($id)`, preventing unauthorized inspection of another user's activity diff (throws 404).
         - Admins can inspect any user's audit diff with user attribution.

  4. **Sidebar & Profile Navigation Updates:**
     - In [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php):
       - Added "My Activity" to `$mainNavItems` for students and non-admin portal users with `'permission' => 'activity.view'`.
       - Updated the administrative item under `USER & ACCESS MANAGEMENT` to `'permission' => 'activity.view'`.
       - Removed `admin.user-activity.index` from the student URL blacklist.
     - In [`resources/views/pages/admin/⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile.blade.php):
       - Wrapped the "My Activity" header button in `@can('activity.view')`.

  5. **Automated Feature Test Coverage:**
     - Created [`tests/Feature/UserActivityAccessTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/UserActivityAccessTest.php) with 7 test cases:
       - Users without `activity.view` are forbidden from accessing the page (HTTP 403).
       - Students with `activity.view` can access `my-activity` and only see their own records.
       - Students cannot bypass scoping to view other users' records by tampering with `userFilter`.
       - Students cannot inspect another user's audit log record (throws `ModelNotFoundException`).
       - Admins can view any user's activity and filter by specific users.
       - Admins can inspect the diff for other users' logs.
       - Sidebar and profile navigation buttons render conditionally based on `activity.view` permission.

---

7. **Live Chat and Online Meetings Access Gating & UI Consistency:**
   - **User Request:** Enforce permission checks across Live Chat and Online Meetings so only users holding valid permissions can access chat and meetings related links, routes, actions, and see them on their dashboard, header (topbar), or sidebar.
   - **Root Cause & Scope Analysis:**
     - In [`routes/web.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php), routes `chat.index`, `admin.chat.index`, `chat.join`, `meetings.index`, `meetings.room`, `meetings.join`, `meetings.signal`, and `meetings.sync` lacked permission middleware (`can:chat.access`, `can:meetings.view`).
     - In [`app/Http/Controllers/ChatJoinController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/ChatJoinController.php) and [`app/Http/Controllers/MeetingJoinController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/MeetingJoinController.php), join link requests lacked explicit permission checks for authenticated users.
     - In [`resources/views/layouts/app/topbar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/topbar.blade.php), Live Chat was gated by `chat.access`, but the Online Meetings quick access button was missing.
     - In [`resources/views/dashboard.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/dashboard.blade.php), `$unreadChatCount` ran regardless of whether the user held `chat.access`.
     - In [`resources/views/pages/portal/⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php), voice/video call buttons and creation actions (`createGroup`, `createChannel`) did not enforce granular permission checks.
     - In [`resources/views/pages/portal/⚡meetings.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meetings.blade.php), "Start Instant Meeting" and "Schedule Meeting" action buttons and server-side creation handlers (`createInstantMeeting`, `scheduleMeeting`) were not guarded by `meetings.create`.
     - In [`resources/views/pages/portal/⚡meeting-room.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meeting-room.blade.php), `mount()` lacked verification of `meetings.view` (while correctly recognizing meeting host ownership).

   - **Key Changes Implemented:**
     1. **Route Middleware Authorization:**
        - Gated `chat.index` and `admin.chat.index` with `middleware('can:chat.access')`.
        - Gated `chat.join` with `middleware(['auth', 'can:chat.access'])`.
        - Gated `meetings.index` and `meetings.room` with `middleware('can:meetings.view')`.
        - Gated `meetings.join` with `middleware(['auth', 'can:meetings.view'])`.
        - Gated signaling and sync endpoints (`meetings.signal`, `meetings.sync`, `meetings.signal.direct`, `meetings.sync.direct`) with `'can:meetings.view'`.
     2. **Join Controllers Authorization:**
        - In [`app/Http/Controllers/ChatJoinController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/ChatJoinController.php), added:
          `abort_unless($user->can('chat.access') || $user->hasRole('Super Administrator'), 403, __('You do not have permission to access live chat.'));`
        - In [`app/Http/Controllers/MeetingJoinController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/MeetingJoinController.php), added:
          `abort_unless($user->can('meetings.view') || $user->hasRole('Super Administrator'), 403, __('You do not have permission to access online meetings.'));`
     3. **UI Elements & Navigation Gating:**
        - In [`resources/views/layouts/app/topbar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/topbar.blade.php), added the Online Meetings quick access button next to the Live Chat button, gated by `meetings.view` and `meeting.enabled`.
        - In [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php), guarded `$unreadChatCount` calculation so unread queries only run for users with `chat.access`.
        - In [`resources/views/dashboard.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/dashboard.blade.php), guarded `$unreadChatCount` and ensured Live Chat and Online Meetings cards are displayed only when valid permissions (`chat.access`, `meetings.view`) are held.
     4. **Component-Level Action & Modal Authorization:**
        - In [`resources/views/pages/portal/⚡chat.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php):
          - Enforced `chat.voice_call` and `chat.video_call` permissions on `voiceCallEnabled`, `videoCallEnabled`, and server-side call dispatchers (`promptCall`, `launchConfirmedCall`, `launchConfirmedGroupCall`).
          - Enforced `chat.create_group` and `chat.create_channel` permissions in `createGroup()` and `createChannel()`.
          - Wrapped the action dropdown and modals in `@can` directives (`chat.create_group`, `chat.create_channel`, `meetings.view`, `chat.broadcast`).
        - In [`resources/views/pages/portal/⚡meetings.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meetings.blade.php):
          - Enforced `meetings.create` permission on `openInstantModal()`, `createInstantMeeting()`, and `scheduleMeeting()`.
          - Wrapped the "Start Instant Meeting" and "Schedule Meeting" header buttons and creation modals in `@can('meetings.create')`.
        - In [`resources/views/pages/portal/⚡meeting-room.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meeting-room.blade.php):
          - In `mount()`, verified `$this->meeting->isHost($user) || $user->can('meetings.view') || $user->hasRole('Super Administrator')`, aborting with 403 otherwise.
     5. **Automated Feature Test Coverage:**
        - Created [`tests/Feature/ChatAndMeetingsAccessTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/ChatAndMeetingsAccessTest.php) with 8 test cases:
          - Users without `chat.access` cannot access chat routes, join links, or Livewire components (HTTP 403).
          - Users with `chat.access` can access live chat and join links.
          - Users without `meetings.view` cannot access meetings routes, room routes, join links, or signaling endpoints (HTTP 403).
          - Users with `meetings.view` can access meetings hub, room, and join links.
          - Topbar, sidebar, and dashboard navigation links and cards render conditionally based on permissions.
          - Users without `meetings.create` cannot see creation buttons/modals or trigger instant/scheduled meeting creation (HTTP 403).
          - Users with `meetings.create` can view creation buttons and create meetings.
          - Chat group and channel creation actions are gated by `chat.create_group` and `chat.create_channel`.

---

## Verification & Testing Summary

1. **Chat & Meetings Access Test Suite:**
   ```bash
   php artisan test tests/Feature/ChatAndMeetingsAccessTest.php --compact
   ```
   **Result:** PASSED (8 tests, 44 assertions).
2. **Online Meeting Feature Test Suite:**
   ```bash
   php artisan test tests/Feature/OnlineMeetingTest.php --compact
   ```
   **Result:** PASSED (13 tests, 49 assertions).
3. **Live Chat, Meetings & Hostel Settings Access Test Suite:**
   ```bash
   php artisan test tests/Feature/LiveChatMeetingsAndHostelSettingsAccessTest.php --compact
   ```
   **Result:** PASSED (12 tests, 52 assertions).
4. **User Activity Access & Scoping Test Suite:**
   ```bash
   php artisan test tests/Feature/UserActivityAccessTest.php --compact
   ```
   **Result:** PASSED (7 tests, 26 assertions).
5. **RBAC Test Suite:**
   ```bash
   php artisan test tests/Feature/RbacTest.php --compact
   ```
   **Result:** PASSED (11 tests, 43 assertions).
6. **Team Access Restriction Test Suite:**
   ```bash
   php artisan test tests/Feature/Teams/TeamAccessRestrictionTest.php --compact
   ```
   **Result:** PASSED (21 tests, 32 assertions).
7. **Pint Code Formatting:**
   ```bash
   vendor/bin/pint routes/web.php app/Http/Controllers/ChatJoinController.php app/Http/Controllers/MeetingJoinController.php tests/Feature/OnlineMeetingTest.php tests/Feature/ChatAndMeetingsAccessTest.php --format agent
   ```
   **Result:** PASSED (All style standards met).

---

## 8. Resolved SQLSTATE[22007] Invalid Datetime Format Error on Student Profile Change Request Approval (`dob = ''`)

- **Issue:**
  When an administrator approved a student's profile change request in `/admin/profile-requests` ([`⚡profile-requests.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile-requests.blade.php)), MySQL failed with:
  `Approval failed: SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect date value: '' for column 'dob' at row 1 (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: laravelx, SQL: update users set phone = 9876543211, whatsapp_no = , dob = , gender = , tenth_roll = , id_type = , id_number = , updated_by = 2, users.updated_at = 2026-11-09 23:38:10 where id = 3)`.

- **Root Cause:**
  1. In [`⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile.blade.php), empty input fields submitted by students were set to `""`. When `$user->fill($validated)` ran, `$user->getDirty()` compared database `null` to `""`, marking unchanged blank fields as dirty and storing them in the serialized JSON request payload.
  2. In [`⚡profile-requests.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile-requests.blade.php), the approval method passed `$payload` directly into `$user->update($userUpdates)`. Under MySQL strict mode (`STRICT_TRANS_TABLES`), an empty string `''` on a `DATE` column triggers error 1292.

- **Solution & Implementation:**
  - **Model Mutators:** Added robust mutators in [`app/Models/User.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/User.php), [`app/Models/StudentProfile.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentProfile.php), [`app/Models/StudentEmployment.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentEmployment.php), and [`app/Models/StudentEducation.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentEducation.php) ensuring that date columns (`dob`, `issued_date`, `pwbd_issued_date`, `from_date`, `to_date`), numeric columns (`family_income`, `distance`, `marks_obtained`, `total_marks`, `percentage`, `cgpa`), and nullable string fields (`whatsapp_no`, `tenth_roll`, `id_type`, `id_number`, `gender`, `designation`) convert empty strings `""` to `null`.
  - **Payload Sanitization:** Added `sanitizePayload()` in [`⚡profile-requests.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile-requests.blade.php) sanitizing all sections (`basic`, `account`, `profile`, `address`, `education`, `employment`, `upsc`, `documents`), and synchronized student profile records using `UserStudentSyncService::syncFromUser($user)`.
  - **Form Cleaning:** Cleaned `$validated` in [`⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile.blade.php) prior to `$user->fill()`, preventing unchanged blank fields from falsely registering as dirty.
  - **API Controller:** Sanitized `$userFields` and `$profileFields` in [`app/Http/Controllers/Api/StudentApiController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/Api/StudentApiController.php).
  - **Automated Verification:** Added tests to [`tests/Feature/StudentDashboardProfileRequestTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/StudentDashboardProfileRequestTest.php) verifying that requests with empty string dates and numbers approve cleanly without database errors (16 passed, 130 assertions).

---

## 9. Resolved Meeting Share Modal Alpine Expression Error (`@js` Unparsed in Component Attribute)

- **Issue:**
  Opening the meeting invitation modal (`openShareModal(id)`) in `/meetings` produced browser console errors:
  `Alpine Expression Error: Invalid or unexpected token`
  `Expression: "copyText(@js($joinUrl), true)"`
  `Expression: "copyText(@js($shareText), false)"`
  `Uncaught SyntaxError: Invalid or unexpected token`

- **Root Cause:**
  `<x-ui.button>` is a Blade component. Laravel's component compiler does not parse Blade directives like `@js(...)` within component attributes (`@click="copyText(@js(...))"`). The literal string `@js(...)` was outputted into the HTML, causing Alpine's JavaScript evaluation to fail on the invalid `@` token.

- **Solution & Implementation:**
  - In [`resources/views/pages/portal/⚡meetings.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meetings.blade.php):
    - Declared `joinUrl: {{ Js::from($joinUrl) }}` and `shareText: {{ Js::from($shareText) }}` inside the modal root element's `x-data`.
    - Added clean Alpine helper methods `copyLink()` and `copyInvite()`.
    - Simplified button directives to `@click="copyLink()"` and `@click="copyInvite()"`.
  - Added automated test in [`tests/Feature/OnlineMeetingTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/OnlineMeetingTest.php) confirming that the modal renders without any unparsed `@js(` tokens.


