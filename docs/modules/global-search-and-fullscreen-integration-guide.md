# Implementation & Integration Guide: Global Search & Fullscreen Mode Module

**Module:** Enterprise Global Search & Navigation Engine • Header Fullscreen Viewport Manager • RESTful API Suite  
**Date:** November 1, 2026  
**Status:** Production Ready  

---

## 1. Executive Summary

This document provides a comprehensive, step-by-step implementation and integration guide for the **Global Search and Fullscreen Mode Module** in the SNTCSSC MIS Administrative Portal.

The module provides:
1. **Interactive Global Command Palette / Search Dialog:** Located in the top navigation header (`topbar.blade.php`), accessible via desktop search input, mobile dedicated search trigger, or global keyboard shortcut (`Ctrl+K` / `Cmd+K`).
2. **Access-Controlled Omnisearch:** Seamlessly indexes all administrative modules, settings pages, submenus, and interactive quick actions, alongside real-time live database records (Students, Courses, Batches, Library Books, Staff Users, Support Tickets, Study Materials) strictly filtered by the authenticated user's Spatie permissions (`global_search.access`, `students.view`, `courses.manage`, etc.).
3. **HTML5 Fullscreen Toggle & Persistent Configuration:** Interactive toggle button in the header with reactive `maximize-2` and `minimize-2` icons, HTML5 Fullscreen API state synchronization (`fullscreenchange`), and dual-level persistence:
   - System-wide default setting in `settings` table (`appearance.default_fullscreen`)
   - Individual user preference in `users.preferences` JSON column
4. **Production RESTful API Suite (4 Endpoints):** Dual-authenticated (`auth.api_key` & `auth:sanctum`), scoped with `read:search` and `manage:search`, backed by atomic database transactions, audit trails, and defensiveness.
5. **Interactive Developer Console & Docs Integration:** Testable directly within the in-admin API documentation portal (`⚡docs.blade.php`) with cross-platform code generators.

---

## 2. Architecture & File Inventory

```
app/
├── Http/Controllers/Api/
│   └── SearchApiController.php            # RESTful API Controller (4 production endpoints)
├── Models/
│   └── User.php                           # Updated: preferences JSON cast, getPreference(), setPreference()
├── Services/
│   ├── ApiKey/
│   │   └── ApiKeyService.php              # Added read:search and manage:search scopes
│   ├── GlobalSearchService.php            # Master catalog, RBAC filter, live entity search & preferences
│   └── RbacService.php                    # Added global_search.access permission
└── Support/
    └── LucideIcons.php                    # Added maximize, minimize, fullscreen, and corner-down-left icons

database/migrations/
└── 2026_11_01_010000_create_global_search_and_fullscreen_settings.php  # Schema migration & seeds

docs/
├── integrations/
│   └── global-search-and-fullscreen-api.md # Interactive markdown guide read by Developer Console
└── modules/
    └── global-search-and-fullscreen-integration-guide.md # This guide

resources/views/
├── components/
│   └── ⚡global-search-modal.blade.php     # Livewire 4 SFC: Command palette modal & keyboard navigation
├── layouts/
│   ├── app.blade.php                      # Injected <livewire:global-search-modal/>
│   └── app/
│       └── topbar.blade.php               # Search triggers (desktop & mobile) + Fullscreen toggle
└── pages/
    ├── admin/
    │   ├── api-keys/
    │   │   └── ⚡docs.blade.php           # Updated: API Console sandbox presets, reference table & card
    │   └── settings/
    │       └── ⚡appearance.blade.php     # System default viewport setting (normal vs fullscreen)
    └── settings/
        └── ⚡appearance.blade.php         # User profile default viewport preference
```

---

## 3. Step-by-Step Implementation Walkthrough

### Step 1: Database Migration & Role Assignment
Execute the schema migration:
```bash
php artisan migrate
```
This adds:
- `preferences` JSON column to `users` table
- `global_search.access` permission to Spatie permissions table
- Assigns `global_search.access` to administrative roles (`Super Administrator`, `Administrator`, `Admissions Officer`, `Faculty`, `Accountant`, `Staff`, `Librarian`)
- Inserts `appearance.default_fullscreen` setting (`normal` by default).

### Step 2: Global Search Service (`GlobalSearchService.php`)
The service indexes 16 top-level functional categories covering all 58 administrative routes:
1. Main Dashboard & Communications
2. Students & Admissions Directory
3. Alumni Directory & Placement
4. Academics, Courses, Batches & Exams
5. Attendance & Biometric Turnstiles
6. Leave Management
7. Fees, Invoices & Financial Ledgers
8. Library Catalog, Accessioning & Circulation
9. Hostel Management & Bed Allocations
10. Users & Access Management (RBAC)
11. Communications & Marketing
12. Content & CMS Pages
13. Support & Helpdesk Tickets
14. Reports & Telemetry
15. API & Integrations
16. System Settings & Maintenance

#### Access Control Rule:
```php
if ($user->hasRole('Super Administrator')) {
    return true; // Root bypass
}
if ($user->isStudent() && !empty($item['admin_only'])) {
    return false; // Student restriction
}
if (!empty($item['permission'])) {
    return $user->can($item['permission']);
}
return true;
```

### Step 3: Frontend Command Palette (`⚡global-search-modal.blade.php`)
- **Keybindings:**
  - `Ctrl + K` or `Cmd + K`: Toggle dialog
  - `↑` / `↓`: Navigate highlighted result
  - `Enter`: Select highlighted result (or execute action)
  - `Esc`: Close dialog
- **Dynamic Category Filtering:** Real-time pills to filter by module or result type.
- **Recent Searches:** Saved in client `localStorage` with clear option.
- **Special Actions:** Instant fullscreen toggle and light/dark theme switching.

### Step 4: Header Integration (`topbar.blade.php`)
1. **Desktop Search Bar:**
   ```html
   <button type="button" x-on:click="$dispatch('open-global-search')">
       <x-icon name="search" />
       <span>Search…</span>
       <kbd>Ctrl K</kbd>
   </button>
   ```
2. **Mobile Search Icon:**
   Dedicated search trigger icon for mobile viewports (`sm:hidden`).
3. **Fullscreen Toggle:**
   HTML5 Fullscreen API with reactive state, listening to `fullscreenchange` and optional auto-fullscreen triggering.

---

## 4. RESTful API Endpoints Summary

| Method | Endpoint | Scope | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/search/global` | `read:search` | Query modules, actions, and permissible data records. |
| `GET` | `/api/v1/search/modules` | `read:search` | Retrieve accessible module taxonomy and quick actions. |
| `GET` | `/api/v1/search/preferences` | `read:search` | Inspect current user and system viewport preferences. |
| `POST` | `/api/v1/search/preferences` | `manage:search` | Update viewport mode (normal vs fullscreen) and options. |

---

## 5. Security & Verification Checklist

- [x] Strict Role-Based Access Control: Unauthorized users cannot view restricted modules or entity records in search results.
- [x] Audit Logging: Search preference modifications and significant queries are logged via `AuditLogService::log()`.
- [x] Atomic DB Transactions: Preference mutations use `DB::beginTransaction()`, `DB::commit()`, `DB::rollBack()`.
- [x] Dual-Mode Authentication: Supports `auth.api_key` and `auth:sanctum` headers.
- [x] Input Validation: Payload sizes, options, and query limits strictly validated.
- [x] Responsive Design & Dark Mode: Production-grade styling supporting both light and dark themes across all device viewports.
- [x] Graceful Degradation: Handles browser autoplay policy for fullscreen without user activation by attaching smooth interaction fallback.
