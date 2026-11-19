# SNTCSSC MIS — Users & Access Management (RBAC) REST API Integration Guide
### Multi-Platform Implementation & Integration Manual (Mobile Applications, Web Portals, Desktop Admin Consoles, Directory Synchronizers & Single Sign-On Microservices)

---

## 1. Executive Summary & Architecture Overview

The **Users & Access Management (RBAC) System** of the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS** delivers a unified, enterprise-grade, and future-ready RESTful API ecosystem. Designed to decouple authentication, granular role-based permissions, profile administration, user directory synchronization, and security telemetry from monolithic views, this API enables turnkey integrations across:

- **Mobile Applications** (Flutter & React Native for iOS / Android) for student, faculty, and administrative self-service profile dossiers, password changes, security status monitoring, and multi-factor authentication (MFA).
- **Administrative Desktop Consoles & IT Helpdesk Tools** (Electron, C# .NET 8, Tauri) for real-time user management, role assignments, account lockout/unlock workflows, bulk status changes, and directory search.
- **Web Applications & Microservices** (React, Vue, Angular, Next.js, Laravel) for integrating custom portals, LMS, ERP, and single sign-on (SSO) gateways.
- **Enterprise Security Telemetry & SIEM Systems** (Splunk, Elastic, Datadog) for streaming real-time security audit trails, failed login spikes, privilege escalation events, and role alteration logs.

### Key Architectural Capabilities:
1. **Granular Role-Based Access Control (RBAC):** Built on high-performance Spatie Permission architecture with dynamic multi-module permissions, customizable colors, and protected system roles (`Super Administrator`, `Administrator`, `Admissions Officer`, `Faculty`, `Accountant`, `Staff`, `Librarian`, `Student`).
2. **Multi-Attribute User Identity Resolution:** High-speed lookup by ID, UUID, 14-digit URN (`urn`), 9-digit Lifetime Student ID (`student_id`), Employee ID (`employee_id`), Email, or Phone.
3. **Enterprise Production Standards:**
   - **Atomic Database Transactions (`DB::beginTransaction()` / `DB::commit()`):** Every user creation, profile update, role synchronization, status transition, and soft-delete runs within strict database transactions to guarantee zero partial writes.
   - **Soft Delete Safeguards (`SoftDeletes`):** Preserves full historical auditability across `users`. Soft-deleted users can be searched, inspected, and restored via dedicated endpoints.
   - **Protected System Guards:**
     - `SELF_DELETION_PROHIBITED`: Prevents administrators from deleting, deactivating, or locking their own accounts.
     - `LAST_SUPER_ADMIN_PROTECTED`: Guarantees that the sole active Super Administrator cannot be deleted, deactivated, locked, or stripped of their role.
     - `SYSTEM_ROLE_PROTECTED`: Prevents renaming or deleting foundational system roles.
     - `ROLE_IN_USE`: Prevents deleting custom roles that have active assigned users.
   - **Comprehensive Security Audit Trails:** Automatic logging of actor user IDs, IP addresses, user agents, request URLs, and before/after state diffs via `AuditLogService::log()`.
   - **Granular API Scopes:** Strict RBAC enforcement via `read:users`, `write:users`, `manage:roles`, and `manage:permissions`.

---

## 2. Decoupled Multi-Platform Architecture & Sequence Flows

```mermaid
graph TD
    subgraph Multi-Platform Clients
        Mobile[Mobile App\nFlutter / React Native]
        Desktop[Desktop Console\nElectron / C# .NET]
        WebPortal[Enterprise Web Portal\nReact / Vue / Next.js]
        SIEM[Security Telemetry\nPython / SIEM Collector]
    end

    subgraph Security & API Gateway
        GW[Laravel REST API Gateway]
        AuthGuard{API Key / Bearer Auth}
        ScopeGate{Scope Verification\nread:users / write:users\nmanage:roles / manage:permissions}
        RateLimit[Rate Limiter\n60-300 req/min]
    end

    subgraph Core Domain Services
        UserCtrl[UserApiController]
        RbacSvc[RbacService]
        ExportSvc[UserExportImportService]
        AuditSvc[AuditLogService]
        UrnGen[UrnGenerator]
    end

    subgraph Storage & Audit Engine
        DB[(MySQL Database Engine\nUsers, Roles, Permissions)]
        AuditLog[(Audit Trail System)]
    end

    Mobile -->|Bearer Token / X-API-Key| GW
    Desktop -->|X-API-Key / Admin Key| GW
    WebPortal -->|Sanctum / X-API-Key| GW
    SIEM -->|X-API-Key| GW

    GW --> AuthGuard
    AuthGuard --> ScopeGate
    ScopeGate --> RateLimit
    RateLimit --> UserCtrl

    UserCtrl --> RbacSvc
    UserCtrl --> ExportSvc
    UserCtrl --> AuditSvc
    UserCtrl --> UrnGen

    RbacSvc --> DB
    ExportSvc --> DB
    AuditSvc --> AuditLog
```

---

## 3. Authentication, API Keys & Scope Matrix

Every incoming API request must authenticate via an active API Key passed in HTTP request headers:

```http
X-API-Key: sntc_live_a1b2c3d4e5f6g7h8...
```
*or alternatively:*
```http
Authorization: Bearer sntc_live_a1b2c3d4e5f6g7h8...
```

### Granular Permission Scopes

| Scope Identifier | Scope Title | Target Capabilities |
| :--- | :--- | :--- |
| `read:users` | Read Users & Directory | Query user registry, profiles, telemetry, roles list, permissions list, and audit logs. |
| `write:users` | Write Users & Credentials | Create users, update profiles, toggle status, unlock accounts, reset passwords, soft-delete, restore, import. |
| `manage:roles` | Manage Roles & Assignments | Create, update, clone, delete custom roles, and synchronize user/role assignments. |
| `manage:permissions` | Manage System Permissions | Inspect, create, update, or remove modular permissions. |
| `*` | Full Administrative Access | Unrestricted access across all existing & future endpoints. |

---

## 4. REST API Endpoint Reference (33 Endpoints)

All Users & Access Management endpoints reside under the `/api/v1` namespace.

### 4.1. Executive Telemetry & Statistics

#### `GET /api/v1/users/dashboard`
Returns high-level user metrics, account status counts, email/phone verification rates, MFA adoption rate, role distribution, 10 recent logins, and 10 recent security audit events.

- **Required Scope:** `read:users`
- **Sample Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "counts": {
      "total": 1250,
      "active": 1180,
      "inactive": 42,
      "suspended": 18,
      "locked": 10,
      "trashed": 15,
      "email_verified": 1210,
      "phone_verified": 1195,
      "mfa_enabled": 340
    },
    "rates": {
      "email_verification_pct": 96.8,
      "phone_verification_pct": 95.6,
      "mfa_adoption_pct": 27.2
    },
    "roles_distribution": [
      { "id": 1, "name": "Super Administrator", "color": "violet", "is_system": true, "users_count": 3 },
      { "id": 2, "name": "Administrator", "color": "emerald", "is_system": true, "users_count": 12 },
      { "id": 3, "name": "Faculty", "color": "blue", "is_system": false, "users_count": 45 },
      { "id": 4, "name": "Student", "color": "indigo", "is_system": false, "users_count": 1150 }
    ],
    "recent_logins": [
      { "id": 1, "name": "Admin User", "email": "admin@sntcssc.in", "last_login_at": "2026-10-31T16:00:00Z", "last_login_ip": "127.0.0.1" }
    ],
    "recent_security_events": [ ... ]
  }
}
```

#### `GET /api/v1/users/statistics`
Granular user analytics: gender distribution, status breakdown, department distribution for staff, and 6-month monthly user registration growth trends.

- **Required Scope:** `read:users`

---

### 4.2. User Directory & Management (CRUD + Lifecycle)

#### `GET /api/v1/users`
Search, filter, and paginate the master user directory.

- **Required Scope:** `read:users`
- **Query Parameters:**
  - `search` (string): Keyword matching name, email, phone, URN, student ID, employee ID.
  - `role` (string|int): Filter by role name or ID.
  - `status` (string): `active`, `inactive`, `suspended`, `locked`.
  - `trashed` (string): `without_trashed` (default), `with_trashed`, `only_trashed`.
  - `gender` (string): `Male`, `Female`, `Other`.
  - `verified` (string): `email`, `phone`, `both`, `unverified`.
  - `sort_by` (string): `id`, `name`, `email`, `created_at`, `last_login_at` (default: `created_at`).
  - `sort_dir` (string): `asc` or `desc` (default: `desc`).
  - `per_page` (int): Records per page (default: 15, max: 100).

#### `POST /api/v1/users`
Create a new user with auto-generated 14-digit URN, UUID, role assignment, and audit log.

- **Required Scope:** `write:users`
- **Request Body (JSON):**
```json
{
  "name": "Prof. Ananya Sen",
  "first_name": "Ananya",
  "last_name": "Sen",
  "email": "ananya.sen@sntcssc.in",
  "phone": "9830012345",
  "whatsapp_no": "9830012345",
  "gender": "Female",
  "dob": "1985-06-15",
  "designation": "Senior Faculty of History",
  "staff_department": "Academic Affairs",
  "employee_id": "EMP-8024",
  "biometric_pin": "8024",
  "rfid_card_no": "RFID-8024",
  "status": "active",
  "roles": ["Faculty"],
  "password": "InitialSecurePassword@2026"
}
```
*Note: If `password` is omitted, the system generates a secure 12-character random password and returns it in `generated_password`.*

#### `GET /api/v1/users/{identifier}`
Retrieve full 360-degree user dossier by ID, UUID, URN, email, phone, student ID, or employee ID.

- **Required Scope:** `read:users`

#### `PUT /api/v1/users/{identifier}`
Update user profile attributes with audit trail of old vs new values.

- **Required Scope:** `write:users`

#### `DELETE /api/v1/users/{identifier}`
Safely soft-delete user account. Sets `deleted_by` to the authenticated actor. Enforces protection guards preventing self-deletion and deletion of the last remaining Super Administrator.

- **Required Scope:** `write:users`

#### `POST /api/v1/users/{identifier}/restore`
Restore a previously soft-deleted user back to active state.

- **Required Scope:** `write:users`

#### `DELETE /api/v1/users/{identifier}/force-delete`
Permanently purge a soft-deleted user from the database. Protected by Super Administrator guard.

- **Required Scope:** `write:users`

---

### 4.3. Security Operations, Status & Credentials

#### `POST /api/v1/users/{identifier}/status`
Transition user status between `active`, `inactive`, `suspended`, or `locked`.

- **Required Scope:** `write:users`
- **Request Body (JSON):**
```json
{
  "status": "locked",
  "lock_hours": 48,
  "reason": "Repeated suspicious login attempts from unknown IP"
}
```

#### `POST /api/v1/users/{identifier}/unlock`
Immediately clear account lockout, reset failed login attempts counter to 0, and transition status to `active`.

- **Required Scope:** `write:users`

#### `POST /api/v1/users/{identifier}/reset-password`
Administrative password reset. Sets specified password or auto-generates temporary credential.

- **Required Scope:** `write:users`
- **Request Body (JSON):**
```json
{
  "password": "NewSecurePassword#2026"
}
```

#### `POST /api/v1/users/{identifier}/sync-roles`
Synchronize user roles array with guard preventing removal of sole Super Administrator.

- **Required Scope:** `manage:roles` or `write:users`
- **Request Body (JSON):**
```json
{
  "roles": ["Faculty", "Admissions Officer"]
}
```

#### `GET /api/v1/users/{identifier}/audit-logs`
Paginated audit trail of all security and modification events where user is the actor or the auditable target.

- **Required Scope:** `read:users`

---

### 4.4. Bulk Operations, Data Exchange & Import/Export

#### `GET /api/v1/users/export`
Export filtered user dataset as structured JSON.

- **Required Scope:** `read:users`

#### `POST /api/v1/users/import`
Bulk import users via JSON array or multipart spreadsheet upload (`.csv`, `.xlsx`).

- **Required Scope:** `write:users`

#### `POST /api/v1/users/bulk-status`
Bulk change status for multiple user IDs in an atomic transaction.

- **Required Scope:** `write:users`
- **Request Body (JSON):**
```json
{
  "user_ids": [12, 15, 23, 44],
  "status": "active"
}
```

---

### 4.5. Roles Management System (RBAC)

#### `GET /api/v1/roles`
List all roles with active users count, permissions count, color branding, and system role indicators.

- **Required Scope:** `read:users` or `manage:roles`

#### `POST /api/v1/roles`
Create a new custom role with color, description, and optional initial permission assignments.

- **Required Scope:** `manage:roles`
- **Request Body (JSON):**
```json
{
  "name": "Hostel Warden",
  "description": "Hostel allocation and disciplinary supervisor",
  "color": "sky",
  "permissions": ["hostel.view", "hostel.allocations.view", "hostel.allocations.manage"]
}
```

#### `GET /api/v1/roles/{id}`
Show role profile, users assigned to this role, and permissions grouped by module.

- **Required Scope:** `read:users` or `manage:roles`

#### `PUT /api/v1/roles/{id}`
Update role description, color, or permissions. Protects system roles against name modification.

- **Required Scope:** `manage:roles`

#### `DELETE /api/v1/roles/{id}`
Delete custom role. Enforces guards: system roles cannot be deleted, and roles with active assigned users cannot be deleted.

- **Required Scope:** `manage:roles`

#### `POST /api/v1/roles/{id}/clone`
Duplicate an existing role with all of its assigned permissions under a new name.

- **Required Scope:** `manage:roles`
- **Request Body (JSON):**
```json
{
  "name": "Junior Faculty",
  "description": "Assistant faculty role cloned from Faculty template"
}
```

#### `POST /api/v1/roles/{id}/sync-permissions`
Atomic synchronization of permissions to a role.

- **Required Scope:** `manage:roles`
- **Request Body (JSON):**
```json
{
  "permissions": ["academics.view", "attendance.view", "attendance.mark"]
}
```

---

### 4.6. Permissions Management System

#### `GET /api/v1/permissions`
List all registered system permissions. Supports `?module=Hostel` filter and `?grouped=true` for module-grouped associative output.

- **Required Scope:** `read:users` or `manage:permissions`

#### `GET /api/v1/permissions/modules`
Catalog of distinct functional modules and permission counts.

- **Required Scope:** `read:users` or `manage:permissions`

#### `POST /api/v1/permissions`
Create a custom system permission.

- **Required Scope:** `manage:permissions`

#### `PUT /api/v1/permissions/{id}`
Update custom permission module or description.

- **Required Scope:** `manage:permissions`

#### `DELETE /api/v1/permissions/{id}`
Delete custom permission (guarded: cannot delete if assigned to active roles).

- **Required Scope:** `manage:permissions`

---

### 4.7. Current User Self-Service (Me)

#### `GET /api/v1/user/me` & `GET /api/v1/user/profile`
Authenticated user's personal profile dossier, assigned roles, direct permissions, all permissions, teams, and security status.

#### `PUT /api/v1/user/profile`
Self-service profile update for personal information (name, first/last name, phone, whatsapp, gender, dob).

#### `POST /api/v1/user/change-password`
Self-service password update with verification of current password.

- **Request Body (JSON):**
```json
{
  "current_password": "OldPassword#2026",
  "new_password": "NewUpdatedPassword#2026",
  "new_password_confirmation": "NewUpdatedPassword#2026"
}
```

---

### 4.8. Security Audit Logs Feed

#### `GET /api/v1/security/audit-logs`
System-wide security and action audit log feed with search, actor filter, event filter, date range filter, and pagination.

- **Required Scope:** `read:users`

---

## 5. Multi-Platform SDK Integration Recipes

### 5.1. Flutter (Dart) — Mobile App Integration

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class SntcsscUserClient {
  final String baseUrl;
  final String apiKey;

  SntcsscUserClient({required this.baseUrl, required this.apiKey});

  Map<String, String> get _headers => {
    'X-API-Key': apiKey,
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  // 1. Fetch Executive Telemetry
  Future<Map<String, dynamic>> getUsersDashboard() async {
    final response = await http.get(Uri.parse('$baseUrl/users/dashboard'), headers: _headers);
    return jsonDecode(response.body);
  }

  // 2. Search Users Directory
  Future<List<dynamic>> searchUsers({String? query, String? role, String? status}) async {
    final params = <String, String>{};
    if (query != null) params['search'] = query;
    if (role != null) params['role'] = role;
    if (status != null) params['status'] = status;

    final uri = Uri.parse('$baseUrl/users').replace(queryParameters: params);
    final response = await http.get(uri, headers: _headers);
    final json = jsonDecode(response.body);
    return json['data'] ?? [];
  }

  // 3. Instant Account Unlock
  Future<bool> unlockUser(int userId) async {
    final response = await http.post(Uri.parse('$baseUrl/users/$userId/unlock'), headers: _headers);
    final json = jsonDecode(response.body);
    return json['success'] == true;
  }

  // 4. Update User Roles
  Future<Map<String, dynamic>> syncUserRoles(int userId, List<String> roles) async {
    final response = await http.post(
      Uri.parse('$baseUrl/users/$userId/sync-roles'),
      headers: _headers,
      body: jsonEncode({'roles': roles}),
    );
    return jsonDecode(response.body);
  }
}
```

### 5.2. React Native (TypeScript / Axios)

```typescript
import axios, { AxiosInstance } from 'axios';

export class SntcsscUserApi {
  private api: AxiosInstance;

  constructor(baseURL: string, apiKey: string) {
    this.api = axios.create({
      baseURL,
      headers: {
        'X-API-Key': apiKey,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      timeout: 10000,
    });
  }

  // 1. Get User Profile Dossier
  async getUser(identifier: string | number) {
    const res = await this.api.get(`/users/${identifier}`);
    return res.data.data;
  }

  // 2. Create User Record
  async createUser(payload: {
    name: string;
    email: string;
    phone?: string;
    designation?: string;
    roles?: string[];
  }) {
    const res = await this.api.post('/users', payload);
    return res.data;
  }

  // 3. Status Transition (Activate / Suspend / Lock)
  async setStatus(identifier: string | number, status: 'active' | 'suspended' | 'locked', reason?: string) {
    const res = await this.api.post(`/users/${identifier}/status`, { status, reason });
    return res.data;
  }

  // 4. Self Password Change
  async changeMyPassword(currentPassword: string, newPassword: string) {
    const res = await this.api.post('/user/change-password', {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: newPassword,
    });
    return res.data;
  }
}
```

### 5.3. C# .NET 8 (Desktop / Windows Client)

```csharp
using System;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

public class SntcsscUserClient
{
    private readonly HttpClient _client;
    private readonly string _baseUrl;

    public SntcsscUserClient(string baseUrl, string apiKey)
    {
        _baseUrl = baseUrl.TrimEnd('/');
        _client = new HttpClient();
        _client.DefaultRequestHeaders.Add("X-API-Key", apiKey);
        _client.DefaultRequestHeaders.Add("Accept", "application/json");
    }

    public async Task<string> GetDashboardAsync()
    {
        var response = await _client.GetAsync($"{_baseUrl}/users/dashboard");
        response.EnsureSuccessStatusCode();
        return await response.Content.ReadAsStringAsync();
    }

    public async Task<bool> BulkStatusUpdateAsync(int[] userIds, string status)
    {
        var payload = JsonSerializer.Serialize(new { user_ids = userIds, status });
        var content = new StringContent(payload, Encoding.UTF8, "application/json");
        var response = await _client.PostAsync($"{_baseUrl}/users/bulk-status", content);
        return response.IsSuccessStatusCode;
    }
}
```

### 5.4. Python Client (Automation & Synchronization)

```python
import requests

BASE_URL = "https://mis.sntcssc.in/api/v1"
API_KEY = "sntc_live_YOUR_GENERATED_KEY"

session = requests.Session()
session.headers.update({
    "X-API-Key": API_KEY,
    "Accept": "application/json",
})

# 1. Fetch Users Telemetry
dash = session.get(f"{BASE_URL}/users/dashboard").json()
print("Total Users:", dash["data"]["counts"]["total"])

# 2. Reset Password
reset_resp = session.post(
    f"{BASE_URL}/users/102/reset-password",
    json={"password": "TemporaryPassword123#"}
).json()
print("Password Reset:", reset_resp["success"])

# 3. Clone Role
clone_resp = session.post(
    f"{BASE_URL}/roles/4/clone",
    json={"name": "Visiting Lecturer", "description": "Visiting faculty member"}
).json()
print("New Cloned Role ID:", clone_resp["data"]["id"])
```

---

## 6. Error Codes & Troubleshooting Reference

| Error Code | HTTP Status | Description & Recommended Resolution |
| :--- | :--- | :--- |
| `API_KEY_MISSING` | 401 | No API key supplied in `X-API-Key` or `Authorization: Bearer` header. |
| `INSUFFICIENT_SCOPE` | 403 | The API key lacks the required scope (e.g. `read:users`, `write:users`). |
| `USER_NOT_FOUND` | 404 | No user matches the provided ID, UUID, URN, student ID, or email. |
| `SELF_DELETION_PROHIBITED` | 422 | Attempted to delete or suspend the authenticated actor's own account. |
| `LAST_SUPER_ADMIN_PROTECTED` | 422 | Attempted to delete, deactivate, lock, or demote the sole remaining Super Administrator. |
| `SYSTEM_ROLE_RENAME_BLOCKED` | 422 | Core system roles cannot be renamed to preserve RBAC integrity. |
| `SYSTEM_ROLE_DELETE_BLOCKED` | 422 | Core system roles cannot be deleted. |
| `ROLE_IN_USE` | 422 | Role cannot be deleted while assigned to one or more active users. |
| `PERMISSION_IN_USE` | 422 | Permission cannot be deleted while assigned to one or more roles. |
| `INVALID_CURRENT_PASSWORD` | 422 | The current password supplied during self-service password update is incorrect. |
| `VALIDATION_FAILED` | 422 | One or more input fields failed validation constraints. Check `error.details`. |

---

## 7. Production Deployment Verification Checklist

- [x] All 33 routes registered and mapped in `routes/api.php`
- [x] Scopes defined in `ApiKeyService.php` (`read:users`, `write:users`, `manage:roles`, `manage:permissions`)
- [x] Atomic transactions wrapping every state change
- [x] Soft delete enabled on user models and guarded against accidental purge
- [x] Sole Super Admin protection enforced across deletion, status change, and role sync
- [x] Comprehensive audit logging via `AuditLogService::log()`
- [x] Multi-platform SDK code recipes provided
- [x] Complete error code handling with standardized JSON response envelopes

