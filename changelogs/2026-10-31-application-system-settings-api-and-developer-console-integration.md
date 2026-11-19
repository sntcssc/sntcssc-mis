# Changelog: Application System Settings Module API, Multi-Platform Integrations & Developer Console Integration

**Date:** October 31, 2026  
**Module:** Application System Settings • Dynamic Study Materials Integration • API Developer Portal & Console • Cross-Platform Client SDKs  
**Status:** Enterprise Production Ready  

---

## 1. Executive Summary

This release introduces the complete **Application System Settings RESTful API** suite along with multi-platform client SDK specifications and enterprise architecture documentation. Furthermore, it updates the **Developer Console & Docs** directly within the admin panel (`resources/views/pages/admin/api-keys/⚡docs.blade.php` and `⚡index.blade.php`) to fully integrate both the **Study Material Management Module** and the new **Application System Settings Module**, ensuring all APIs are interactive, testable in real-time, and documented directly inside the admin console.

### Key Pillars & Enterprise Standards:
- **Future-Ready Multi-Platform Architecture:** Standardized bootstrap parameters, branding assets, feature toggles, and localization presets for Mobile (Flutter, React Native), Modern Web (Next.js, Livewire 4, React SPA), Desktop (.NET 8 WPF/MAUI, Electron), and Background Automations / Microservices (Python, Node.js, cURL).
- **Security & Data Integrity:**
  - Masking of sensitive credentials (`••••••••`) for secure API responses unless accessed with `manage:settings` authorization.
  - Granular API key permission scoping (`read:settings`, `write:settings`, `manage:settings`).
  - Database transactions (`DB::beginTransaction()`, `DB::commit()`, `DB::rollBack()`) on all mutations, batch updates, resets, and imports.
  - Soft deletion lifecycle with `deleted_by` user attribution, restoration, and permanent force-purge capabilities.
  - Complete operational audit logging via `AuditLogService::log()` on every action.
  - In-memory settings cache invalidation (`snt.settings`) upon every modification.
- **Developer Experience:**
  - 11 interactive one-click sandbox presets for System Settings in the Developer Console.
  - Quick Test modal integration in the API Keys Manager for immediate testing.
  - Comprehensive Markdown integration guide (`docs/integrations/application-system-settings-api.md`).
  - Multilingual translations across English, Bengali, and Hindi.

---

## 2. API Scope Expansion (`ApiKeyService.php`)

Added 3 granular API scopes under `app/Services/ApiKey/ApiKeyService.php`:

| Scope Identifier | Label | Description |
| :--- | :--- | :--- |
| `read:settings` | Read Application Settings | View system configuration, bootstrap parameters, features, and categorized setting groups. |
| `write:settings` | Manage Application Settings | Update setting values, create dynamic settings, soft-delete configuration keys, and upload branding assets. |
| `manage:settings` | Advanced Settings Administration | View unmasked secrets, toggle maintenance mode, flush system cache, reset to defaults, and import/export configs. |

---

## 3. Comprehensive REST API Controller (`SystemSettingsApiController.php`)

Created `app/Http/Controllers/Api/SystemSettingsApiController.php` providing **22 production-grade endpoints**:

### Complete Route Endpoints (22 Routes)

1. **Public Bootstrap & Configuration:**
   - `GET /api/v1/settings/public` (`auth.api_key:optional`, `read:settings`) — Public bootstrap payload containing app metadata, branding URLs, localization presets, contact details, operational constraints, and active feature toggles.
   - `GET /api/v1/settings/bootstrap` (`auth.api_key:optional`, `read:settings`) — Alias for mobile and web splash screen initialization.

2. **Group Catalog & Telemetry:**
   - `GET /api/v1/settings/groups` (`read:settings`) — Catalog of 12 distinct setting groups (`general`, `branding`, `theme`, `localization`, `contact`, `features`, `operational`, `security`, `email`, `sms`, `backup`, `integrations`) with key counts and descriptions.
   - `GET /api/v1/settings/statistics` (`read:settings`) — System configuration metrics, total/active/soft-deleted counts, cache status, PHP/Laravel runtime versions, and server environment.

3. **Collection & Group Lookup:**
   - `GET /api/v1/settings` (`read:settings`) — Paginated and searchable settings collection with group filters and secret masking.
   - `GET /api/v1/settings/groups/{group}` (`read:settings`) — Group-specific dictionary (flat key-value map) and item collection.
   - `GET /api/v1/settings/{key}` (`read:settings`) — Retrieve individual setting by key (supports dot notation).

4. **Mutations & Batch Processing:**
   - `POST /api/v1/settings` (`write:settings`) — Create a new dynamic setting inside a DB transaction with audit logging.
   - `PUT /api/v1/settings/{key}` (`write:settings`) — Update setting value, label, options, or active status with automatic cache invalidation.
   - `POST /api/v1/settings/batch` (`write:settings`) — Atomic multi-setting transaction update via POST.
   - `PUT /api/v1/settings/batch` (`write:settings`) — Atomic multi-setting transaction update via PUT.

5. **Soft Delete, Restore & Purge Lifecycle:**
   - `DELETE /api/v1/settings/{key}` (`write:settings`) — Soft delete a setting key, recording `deleted_by` user ID and audit trail.
   - `POST /api/v1/settings/{key}/restore` (`write:settings`) — Restore a soft-deleted setting key and rebuild the cache.
   - `DELETE /api/v1/settings/{key}/force` (`manage:settings`) — Permanently purge a setting key from the database.

6. **Cache & Reset Administration:**
   - `POST /api/v1/settings/cache/flush` (`manage:settings`) — Invalidate `snt.settings` in-memory and Redis/database cache.
   - `POST /api/v1/settings/reset` (`manage:settings`) — Reset a single key, an entire group, or all settings to factory seed defaults in a transaction.

7. **Export & Import Engine:**
   - `GET /api/v1/settings/export` (`manage:settings`) — Export structured JSON configuration bundle with redacted secrets.
   - `POST /api/v1/settings/import` (`manage:settings`) — Transactional import/merge of settings JSON payload with audit logging.

8. **Asset Management & Diagnostics:**
   - `POST /api/v1/settings/assets` (`write:settings`) — Secure multipart upload for branding assets (`site_logo`, `site_favicon`, `admin_logo`, etc.) via `FileUploadService`.
   - `POST /api/v1/settings/diagnostics/email` (`manage:settings`) — Dispatch diagnostic test email via `EmailService`.
   - `POST /api/v1/settings/diagnostics/sms` (`manage:settings`) — Dispatch diagnostic test SMS via `SmsService`.
   - `POST /api/v1/settings/maintenance` (`manage:settings`) — Real-time maintenance mode toggle with custom message and IP allowlists.

---

## 4. Developer Console & Documentation Integration

### Interactive Sandbox Updates (`⚡docs.blade.php`)
- **Endpoint Catalog:** Registered all 22 System Settings endpoints alongside the 15 Dynamic Study Material endpoints.
- **Tab Filtering:** Added filter pills for `Study Materials` and `Application Settings (22)`.
- **Pre-Configured One-Click Presets:** Added 11 interactive presets:
  1. `Public Application Bootstrap` (`GET /api/v1/settings/public`)
  2. `Settings Groups Catalog` (`GET /api/v1/settings/groups`)
  3. `Settings Telemetry & Stats` (`GET /api/v1/settings/statistics`)
  4. `List All Settings` (`GET /api/v1/settings?per_page=15`)
  5. `General Settings Group` (`GET /api/v1/settings/groups/general`)
  6. `Branding & Theme Settings` (`GET /api/v1/settings/groups/branding`)
  7. `Localization Settings` (`GET /api/v1/settings/groups/localization`)
  8. `Batch Update Settings` (`POST /api/v1/settings/batch`)
  9. `Flush Settings Cache` (`POST /api/v1/settings/cache/flush`)
  10. `Export Settings Bundle` (`GET /api/v1/settings/export`)
  11. `Toggle Maintenance Mode` (`POST /api/v1/settings/maintenance`)
- **Smart Path Replacement:** Enhanced `selectPreset()` to dynamically replace path parameters (`{id}`, `{key}`, `{group}`, `{fileId}`, `{course}`, `{batch}`, `{token}`, `{uuid}`) with valid defaults.
- **Embedded Integration Guides:** Added interactive modal guides for both **Dynamic Study Material Management** and **Application System Settings**, complete with architecture diagrams, scopes, and sample cURL / HTTP payloads.

### Quick Test Modal Updates (`⚡index.blade.php`)
- Integrated `<optgroup label="Application System Settings">` and enriched `<optgroup label="Dynamic Study Materials">` inside the Quick Test dialog.
- Allows administrators to test public bootstrap, group dictionary, telemetry stats, and study material types in a single click directly from the API Key list.

---

## 5. Multi-Platform Integration Guide (`application-system-settings-api.md`)

Authored `docs/integrations/application-system-settings-api.md`, providing a comprehensive production guide featuring:
- **System Architecture & Data Flow:** Mermaid flowcharts of the multi-tier caching layer and client bootstrap lifecycle.
- **Security & Authorization Model:** Scope matrix and secret masking rules.
- **Production Client Implementations:** Complete, copy-paste ready implementations for:
  - **Flutter (Dart):** `SystemSettingsService` with offline local cache fallback and theme mode sync.
  - **React Native / Expo (TypeScript):** Axios client with state hooks and dynamic feature gating.
  - **Desktop (C# .NET 8 WPF/MAUI):** Strongly-typed `SystemSettingsClient` with `HttpClient`.
  - **Desktop (Electron / Node.js):** IPC bridge and settings cache store.
  - **Modern Web (Next.js 14+ / React):** App Router bootstrap provider with SSR hydration.
  - **Microservices & Scripts (Python):** `SystemSettingsClient` with automated error handling and secret management.
  - **CLI / cURL:** Shell scripts for automated deployment pipelines.

---

## 6. Localization & UI Polish

- Added 17 multilingual keys across `lang/en.json`, `lang/bn.json`, and `lang/hi.json`:
  - `Application System Settings REST API`
  - `Public Bootstrap & Configuration`
  - `Branding & Theme Settings`
  - `Localization & Regional Settings`
  - `Maintenance Mode & Operational Flags`
  - `Cache Invalidation & Performance`
  - `Diagnostic Test Email & SMS`
  - `Export / Import Configuration Bundles`
- Added icon alias mappings in `app/Support/LucideIcons.php` for `'tune' => 'sliders'` and `'preferences' => 'settings'`.

---

## 7. Verification & Automated Test Coverage

- **System Settings API Test Suite:**
  - Created `tests/Feature/SystemSettingsApiTest.php` with 14 comprehensive test cases covering:
    - Public bootstrap access (without API key and with API key).
    - Group catalog retrieval.
    - System configuration telemetry.
    - Secret masking for read-only vs unmasked for `manage:settings` scope.
    - Single key retrieval.
    - Setting creation with validation and database transaction.
    - Setting update with cache invalidation and audit logging.
    - Atomic batch updating.
    - Soft deletion, restoration, and force-purge lifecycle.
    - In-memory cache flush.
    - Factory defaults reset.
    - Export and import bundles.
    - Multipart branding asset uploads via `FileUploadService`.
    - Maintenance mode toggling.
  - **Result:** 14/14 passed (365 assertions).
- **API Key Management Test Suite:**
  - Added 3 feature tests in `tests/Feature/ApiKeyManagementTest.php` verifying:
    - Developer Console & Docs page includes dynamic study materials endpoints, presets, and guide modal.
    - Developer Console & Docs page includes system settings endpoints, presets, and guide modal.
    - Quick Test modal in API keys index supports both study materials and settings endpoints.
  - **Result:** 21/21 passed (102 assertions).
- **Study Material Test Suite:**
  - Executed all 55 Study Material tests across public, dynamic, role-based access, and thumbnail reorder modules.
  - **Result:** 55/55 passed (323 assertions).
- **Code Style & Formatting:**
  - Formatted all modified PHP files with `vendor/bin/pint --dirty --format agent`.

