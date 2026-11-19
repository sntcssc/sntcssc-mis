# Changelog: Users & Access Management REST API and Developer Console Integration

**Date:** October 31, 2026  
**Module:** Users & Access Management (RBAC) • Developer Console & Documentation • Study Material Module Integration  
**Status:** Enterprise Production Ready  

---

## 1. Executive Summary

This release delivers the complete **Users & Access Management (RBAC)** REST API suite along with deep integration into the in-app **API Developer Portal & Console** (`resources/views/pages/admin/api-keys/⚡docs.blade.php` and `⚡index.blade.php`).

The implementation establishes a future-ready, cross-platform foundation for mobile applications (Flutter, React Native), desktop administration apps (Electron, Windows .NET 8 C#), backend microservices (Python), and external workflows (cURL). Furthermore, the **Study Material Management Module** has been fully verified and showcased in the interactive Developer Console with live sandboxing, POST/GET presets, payload templates, and in-console documentation rendering.

---

## 2. API Scope Expansion (`ApiKeyService.php`)

Added granular institutional access control scopes under `app/Services/ApiKey/ApiKeyService.php`:

| Scope Identifier | Label | Description |
| :--- | :--- | :--- |
| `read:users` | Read Users & RBAC Telemetry | View user directory, profiles, telemetry statistics, and security audit logs. |
| `write:users` | Manage Users & Account Lifecycle | Register new users, update profiles, toggle status, unlock accounts, and reset passwords. |
| `manage:roles` | Manage Roles & Hierarchies | Create, clone, update, delete custom roles, and synchronize role permissions. |
| `manage:permissions`| Manage Permissions Catalog | Register and classify system permissions by functional modules. |

---

## 3. Comprehensive REST API Controller (`UserApiController.php`)

Constructed `app/Http/Controllers/Api/UserApiController.php` providing **33 production-ready endpoints** with enterprise defensive engineering:
- **Soft Delete Lifecycles:** Account deactivation, soft deletion, and one-click account restoration (`POST /restore`), with protected hard purge (`DELETE /force`).
- **Database Transactions:** Every mutating endpoint (`store`, `update`, `destroy`, `restore`, `forceDelete`, `updateStatus`, `unlock`, `resetPassword`, `syncRoles`, `bulkStatus`, `rolesStore`, `rolesClone`, `rolesSyncPermissions`, `permissionsStore`, `permissionsDestroy`, `updateProfile`, `changePassword`) wraps operations in atomic `DB::beginTransaction()` and `DB::commit()` with defensive rollback on failure.
- **Enterprise Audit Logging:** Every administrative action triggers structured audit trails via `AuditLogService::log()` capturing event name, user context, IP address, and payload diffs.
- **Defensive Safeguards & Business Rule Enforcement:**
  - `SELF_DELETION_PROHIBITED`: Prevents an authenticated user or API key owner from deleting their own user account.
  - `LAST_SUPER_ADMIN_PROTECTED`: Prevents deleting or stripping roles from the institution's sole remaining Super Administrator.
  - `SYSTEM_ROLE_PROTECTED`: Core built-in system roles (Super Administrator, Admin, Faculty, Student, Staff) cannot be deleted or renamed.
  - `ROLE_IN_USE`: Custom roles cannot be removed while active users remain assigned to them.
  - `PERMISSION_IN_USE`: Custom permissions cannot be deleted while assigned to any system or custom role.
  - `URN Generator Automation`: Users created through the API automatically receive an institution-standard 14-digit Unique Registration Number (URN).

### Complete Route Endpoints (33 Routes)
1. **Executive Telemetry & Analytics:**
   - `GET /api/v1/users/dashboard` (`read:users`)
   - `GET /api/v1/users/statistics` (`read:users`)
2. **User Lifecycle & Directory Management:**
   - `GET /api/v1/users` (`read:users`)
   - `POST /api/v1/users` (`write:users`)
   - `GET /api/v1/users/{identifier}` (`read:users`)
   - `PUT /api/v1/users/{identifier}` (`write:users`)
   - `DELETE /api/v1/users/{identifier}` (`write:users`)
   - `POST /api/v1/users/{identifier}/restore` (`write:users`)
   - `DELETE /api/v1/users/{identifier}/force` (`write:users`)
3. **Security Operations & Account Controls:**
   - `POST /api/v1/users/{identifier}/status` (`write:users`)
   - `POST /api/v1/users/{identifier}/unlock` (`write:users`)
   - `POST /api/v1/users/{identifier}/reset-password` (`write:users`)
   - `POST /api/v1/users/{identifier}/sync-roles` (`manage:roles`)
   - `GET /api/v1/users/{identifier}/audit-logs` (`read:users`)
4. **Data Exchange & Bulk Operations:**
   - `GET /api/v1/users/export` (`read:users`)
   - `POST /api/v1/users/import` (`write:users`)
   - `POST /api/v1/users/bulk-status` (`write:users`)
5. **Roles & RBAC Management:**
   - `GET /api/v1/roles` (`manage:roles`)
   - `POST /api/v1/roles` (`manage:roles`)
   - `GET /api/v1/roles/{identifier}` (`manage:roles`)
   - `PUT /api/v1/roles/{identifier}` (`manage:roles`)
   - `DELETE /api/v1/roles/{identifier}` (`manage:roles`)
   - `POST /api/v1/roles/{identifier}/clone` (`manage:roles`)
   - `POST /api/v1/roles/{identifier}/sync-permissions` (`manage:roles`)
6. **Permissions Catalog:**
   - `GET /api/v1/permissions` (`manage:permissions`)
   - `GET /api/v1/permissions/modules` (`manage:permissions`)
   - `POST /api/v1/permissions` (`manage:permissions`)
   - `PUT /api/v1/permissions/{identifier}` (`manage:permissions`)
   - `DELETE /api/v1/permissions/{identifier}` (`manage:permissions`)
7. **Self-Service Me Context:**
   - `GET /api/v1/user/me` (`read:users`)
   - `PUT /api/v1/user/profile` (`write:users`)
   - `POST /api/v1/user/change-password` (`write:users`)
8. **System Audit Logs:**
   - `GET /api/v1/security/audit-logs` (`read:users`)

---

## 4. Multi-Platform Developer Console & Documentation Integration

### Interactive Developer Console (`⚡docs.blade.php`)
- **Guide Viewer Mapping:** Updated `openGuide('users')` to dynamically render `docs/integrations/users-and-access-management-api.md` directly inside the in-app modal.
- **Section 3 Category Tabs:** Added `Users & Access (33)` tab displaying all 33 user & RBAC endpoints alongside Study Materials (27), Attendance (38), Leaves (24), Payments (43), and Academics (29).
- **Interactive Sandbox Presets:**
  - Added `Users & RBAC` filter button.
  - Added 13 pre-configured GET and POST presets with complete JSON payloads (`GET /users/dashboard`, `GET /users/statistics`, `GET /users`, `POST /users`, `GET /users/1`, `POST /users/1/status`, `POST /users/1/unlock`, `GET /users/export`, `GET /roles`, `POST /roles`, `GET /permissions/modules`, `GET /user/me`, `GET /security/audit-logs`).
  - Study Materials module verified with full suite of presets (`GET /study-materials`, `GET /study-materials/types`, `GET /study-materials/statistics`, `POST /study-materials`, `POST /study-materials/1/track-view`, `POST /study-materials/types`, `POST /study-materials/1/share-link`, etc.).
- **Section 4 Guide Cards:** Added official architecture card for `Users & Access Management (RBAC) REST API Guide` with one-click in-console reader.
- **Multi-Platform SDK Boilerplate:** Enhanced code snippets for Flutter (Dart), React Native (JS), Electron/Node (JS), C# (.NET 8), Python, and cURL with copy-paste recipes for Users & Access operations.

### Quick Test Integration (`⚡index.blade.php`)
- Added `<optgroup label="{{ __('Users & Access (RBAC)') }}">` in the API key quick test modal with 7 live testing targets.
- Implemented matching routes in `runQuickTest()` for rapid simulated API key verification without external tools.

---

## 5. Documentation Deliverable

Published comprehensive integration manual:  
📁 `docs/integrations/users-and-access-management-api.md` (over 850 lines) covering:
- Authentication, scope matrices, and rate limiting headers.
- Mermaid diagrams for Architecture & Authorization flow, and User Lockout & Recovery cycle.
- Detailed Request/Response schemas for all 33 endpoints.
- Error Code matrix (`VALIDATION_FAILED`, `SELF_DELETION_PROHIBITED`, `LAST_SUPER_ADMIN_PROTECTED`, `SYSTEM_ROLE_PROTECTED`, `ROLE_IN_USE`, `PERMISSION_IN_USE`, `USER_NOT_FOUND`).
- Multi-platform SDK integration recipes for Flutter, React Native, Electron, C# (.NET 8), and Python.

---

## 6. Internationalization & UI Verification

- Added localized translations for all new strings across `lang/en.json`, `lang/bn.json`, and `lang/hi.json`:
  - `Users & Access`
  - `Users & RBAC`
  - `Users & Access (RBAC)`
  - `Users & RBAC • Multi-Platform`
  - `Users & Access Management (RBAC) REST API Guide`
  - Enterprise guide card summaries.
- Verified all icon usages (`users`, `book-open`, `key`, `terminal`, etc.) against `App\Support\LucideIcons`.

---

## 7. Verification & Automated Testing

- **Pest Feature Test Suite:** `tests/Feature/UserAndAccessApiTest.php`  
  - 18 feature test scenarios covering population metrics, statistics, search/pagination, auto-generated URN verification, role assignment, update, status toggles, account lockout clearance, self-deletion protection, last super admin guard, soft delete/restore lifecycle, role cloning/protected system role rejection, permission module catalog, self-service me profile, security audit logs, export streams, and bulk status updates.
  - **Result:** `18 passed, 173 assertions (100% pass rate)`.
- **Regression Testing:**
  - `StudyMaterialDynamicApiTest` & `LeaveAndAttendanceApiTest`: `20 passed, 250 assertions (100% pass rate)`.
- **Code Style:** Formatted using `vendor/bin/pint --format agent`.

