# Changelog: Global Search & Command Palette, Fullscreen Viewport Engine & API Suite Integration

**Date:** November 1, 2026  
**Module:** Header Topbar Architecture • RBAC-Enforced Global Search & Command Palette (`Ctrl+K`) • Dynamic Entity Discovery • HTML5 Fullscreen Engine • Viewport Mode Settings & User Preferences • REST API Suite (`/api/v1/search/*`) • Developer Console & Interactive API Sandbox  
**Status:** Enterprise Production Ready  

---

## 1. Executive Summary

This release introduces an enterprise-grade **Global Search and Command Palette** and a reactive **Fullscreen Mode Viewport Engine** in the admin panel header (`topbar.blade.php`), accessible across all administrative pages. The module empowers authorized staff, faculty, and administrators to rapidly navigate all application modules, execute system actions, and search live institutional records directly with keyboard shortcuts (`Ctrl+K` / `Cmd+K` or `/`), complete with role-based access control (RBAC) filtering so that users can only search, discover, and inspect records they have permission to access.

Key highlights of this implementation include:
- **RBAC-Gated Global Search & Navigation:** Cataloging 58 administrative modules across 16 categories, each verified against Spatie permissions and role guardrails before inclusion in search results.
- **Dynamic Entity Discovery:** Live multi-table search querying Students, Courses, Batches, Library Books, Admin/Staff Users, Support Tickets, and Study Materials with permission checks.
- **Quick Command Shortcuts:** In-palette triggers for toggling fullscreen mode, cycling dark/light themes, and quick-jumping to frequently used actions.
- **HTML5 Fullscreen Toggle & Viewport Mode Preferences:** Header button and configurable system/user viewport defaults (`appearance.default_fullscreen`: normal or fullscreen) stored in system settings and user JSON preferences, backed by automatic viewport initialization.
- **RESTful API Suite (`/api/v1/search/*`):** 4 production API endpoints with dual authentication (`auth.api_key` and `auth:sanctum`), API scopes (`read:search`, `manage:search`), atomic DB transactions, audit logging (`AuditLogService`), and uniform response envelopes.
- **Interactive Developer Console & Docs:** Live sandbox presets, interactive parameter forms, and complete integration documentation integrated directly into `⚡docs.blade.php`.
- **Localization & Accessibility:** Full translation coverage across English (`lang/en.json`), Bengali (`lang/bn.json`), and Hindi (`lang/hi.json`), accompanied by keyboard navigation (`↑`/`↓`/`Enter`/`Esc`), screen reader ARIA dialog attributes, and responsive mobile triggers.

---

## 2. Database & RBAC Enhancements

### Migration
- **`2026_11_01_010000_create_global_search_and_fullscreen_settings.php`**:
  - Added nullable JSON column `preferences` to `users` table for user-specific UI settings (e.g., `default_fullscreen`, search preferences).
  - Seeded permission `global_search.access` under module `Global Search` (guard: `web`).
  - Assigned `global_search.access` to administrative roles (`super_admin`, `admin`, `staff`).
  - Registered institutional setting `appearance.default_fullscreen` (default: `'normal'`) under group `'appearance'`.

### User Model & RbacService
- **`app/Models/User.php`**:
  - Added `preferences` to `$fillable` array.
  - Added `preferences` cast as `'array'`.
  - Added `getPreference(string $key, mixed $default = null): mixed` helper.
  - Added `setPreference(string $key, mixed $value): bool` helper with automatic attribute saving.
  - Added Sanctum's `HasApiTokens` trait for unified API token authentication.
- **`app/Services/RbacService.php`**:
  - Registered `Global Search` module in `defaultPermissions()` catalog with permission `global_search.access`.

---

## 3. Core Search & Viewport Service (`GlobalSearchService.php`)

Constructed `app/Services/GlobalSearchService.php` providing a centralized, resilient engine:
- **Module Registry:** 58 registered admin routes spanning:
  - Academics, Batches & Courses
  - Student Admissions & Lifetime ID Profiles
  - Finance, Fees & Payment Collections
  - Library Management & Accession Registers
  - Attendance & Leave Management
  - Hostel & Room Allocation System
  - Examinations & Candidate Seating
  - Tests & Performance Evaluation
  - Study Materials & Digital Library
  - Support Tickets & Helpdesk Desk
  - Alumni Directory & Career Network
  - Communications, Campaigns & SMS/Email Templates
  - Roles, Permissions & User Access Management
  - System Configurations & Localization Settings
  - Developer Tools, API Keys & Integration Docs
- **Safe Route Resolution (`safeRoute()`):** Defensively checks `Route::has()` with graceful fallbacks to prevent `RouteNotFoundException` during menu rendering.
- **Live Entity Queries:**
  - `queryStudents()`: Queries student code, roll number, admission number, and full name (requires `students.view` or `student.manage`).
  - `queryCourses()`: Queries course title and code (requires `courses.view` or `courses.manage`).
  - `queryBatches()`: Queries batch name and code (requires `batches.view` or `batches.manage`).
  - `queryBooks()`: Queries title, ISBN, and accession number (requires `library.view` or `library.manage`).
  - `queryUsers()`: Queries admin/faculty name and email (requires `users.view` or `users.manage`).
  - `queryTickets()`: Queries ticket tracking number and subject (requires `tickets.view` or `tickets.manage`).
  - `queryStudyMaterials()`: Queries title and subject (requires `study_materials.view` or `study_materials.manage`).
- **Quick Action Commands:** Pre-configured command palette actions including Fullscreen toggle, Theme mode switcher, and instant navigation.
- **Viewport Preference Engine:**
  - `getViewportPreferences(User $user)`: Resolves effective viewport mode prioritizing user preferences over system default.
  - `updateViewportPreferences(User $user, array $data)`: Updates system or personal viewport settings wrapped in DB transactions with `AuditLogService::log()` audit logging.

---

## 4. UI/UX Header Integration & Component Architecture

### Global Search & Command Palette (`⚡global-search-modal.blade.php`)
- **Single File Livewire Component:**
  - Reactive `wire:model.live.debounce.250ms="search"`.
  - Dynamic category pill filtering (`All`, `Academics`, `Students`, `Finance`, `Library`, `Hostel`, `System`, `Actions`, etc.).
  - LocalStorage-backed recent search history with quick removal.
  - Arrow key navigation (`↑`/`↓`), selection via `Enter`, dismiss with `Esc` or backdrop click.
  - Visual badges for Action shortcuts vs. Module pages.
  - Access-controlled indicator badge reminding users of RBAC protection.

### Header Topbar (`topbar.blade.php`) & Main Layout (`app.blade.php`)
- Injected `<livewire:global-search-modal />` in root administrative layout.
- Added responsive Desktop search bar button (`Ctrl+K` badge) and Mobile icon trigger in `topbar.blade.php`.
- Added **Fullscreen Mode Toggle Button** with Alpine.js:
  - HTML5 `requestFullscreen()` and `exitFullscreen()` integration with vendor-prefix fallbacks (`webkitRequestFullscreen`, `msRequestFullscreen`).
  - Reactive icon switching (`maximize` vs `minimize`).
  - Listens for `fullscreenchange` event to sync state when toggled via keyboard (e.g. F11).
  - Automatically loads in fullscreen mode on DOM ready if user or system setting dictates `appearance.default_fullscreen === 'fullscreen'`.
  - Dispatches and listens to `toggle-admin-fullscreen` custom window events.

### Administrative & Personal Appearance Settings
- **`pages/admin/settings/⚡appearance.blade.php`**: Added system-wide default viewport mode dropdown (`normal` vs `fullscreen`) in institutional settings.
- **`pages/settings/⚡appearance.blade.php`**: Added user personal Viewport Mode preference card allowing users to choose `system_default`, `normal`, or `fullscreen`.

---

## 5. RESTful API Suite (`SearchApiController.php`)

Constructed `app/Http/Controllers/Api/SearchApiController.php` mounted under `routes/api.php`:

| HTTP Method | Route Endpoint | Required Scope | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/search/global` | `read:search` | Query modules, quick actions, and authorized database entities with keyword and category filters. |
| `GET` | `/api/v1/search/modules` | `read:search` | Fetch flat catalog of all accessible administrative modules grouped by category for the authenticated user. |
| `GET` | `/api/v1/search/preferences` | `read:search` | Retrieve current user personal viewport preference and system default fullscreen settings. |
| `POST` | `/api/v1/search/preferences` | `manage:search` | Update personal (`scope: "user"`) or system-wide (`scope: "system"`) viewport mode preferences with audit logging. |

### API Security & Resilience
- **Dual Authentication:** Powered by `AuthenticateApiKey` supporting both `X-API-Key` headers and Sanctum Bearer tokens.
- **Scope Verification:** Enforces `read:search` and `manage:search` granular API scopes via `RequireApiScope` middleware.
- **Defensive Error Handling:** Try-catch error handling wrapping all database operations, with automatic rollback and detailed developer error messages.

---

## 6. In-Admin Developer Console & Documentation Portal

- **`pages/admin/api-keys/⚡docs.blade.php`**:
  - Added new `search` category filter and badge in Developer Console.
  - Added 4 interactive sandbox presets:
    - *Global Search Query* (`GET /api/v1/search/global?q=student`)
    - *Accessible Modules Catalog* (`GET /api/v1/search/modules`)
    - *Read Viewport Preferences* (`GET /api/v1/search/preferences`)
    - *Update Viewport Mode* (`POST /api/v1/search/preferences`)
  - Embedded in-console guide card pointing directly to the integration guides.
- **Integration Markdown Documentation:**
  - `docs/integrations/global-search-and-fullscreen-api.md`: Comprehensive REST API documentation with cURL, Flutter, React Native, Electron, and Next.js integration code samples.
  - `docs/modules/global-search-and-fullscreen-integration-guide.md`: Step-by-step implementation guide covering database schema, RBAC permissions, Livewire SFC, Alpine HTML5 Fullscreen API, and developer console usage.

---

## 7. Localization & Icon Additions

- **Translations:** Added 100% synchronized translation strings across `lang/en.json`, `lang/bn.json`, and `lang/hi.json`:
  - English, Bengali, and Hindi translations for all global search headers, placeholders, empty states, shortcuts, viewport preference labels, and API descriptions.
- **Icons (`LucideIcons.php`):**
  - Added Lucide icon aliases: `maximize`, `minimize`, `fullscreen`, `return`.
  - Added missing `corner-down-left` SVG icon path for keyboard return symbol display.

---

## 8. Verification & Test Suite

Constructed comprehensive Pest test suite in `tests/Feature/GlobalSearchAndFullscreenTest.php`:
- `✓ GlobalSearchService returns accessible modules based on user permissions`
- `✓ GlobalSearchService filters out restricted modules from unauthorized users`
- `✓ GlobalSearchService blocks student accounts from accessing admin search`
- `✓ GlobalSearchService searches live database records for students and courses`
- `✓ GlobalSearchService manages system and user viewport preferences with audit log`
- `✓ API Key GET /api/v1/search/global returns search results`
- `✓ API Key GET /api/v1/search/modules returns authorized modules catalog`
- `✓ API Key GET and POST /api/v1/search/preferences reads and updates viewport settings`
- `✓ API Key search endpoints enforce required scopes`
- `✓ Sanctum authenticated users can access search API endpoints`
- `✓ Topbar renders global search trigger button and fullscreen toggle button`
- `✓ Livewire global search modal component renders and filters by category`
- `✓ User appearance settings page renders viewport mode preferences`

**Test Results:** **13 passed, 68 assertions, 100% green**.  
**Code Quality:** Formatted strictly adhering to PSR-12 and Laravel standards using `vendor/bin/pint --format agent`.
