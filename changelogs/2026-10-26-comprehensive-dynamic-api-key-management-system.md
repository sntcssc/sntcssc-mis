# Comprehensive Dynamic API Key Management & Integration System

**Release Date:** October 26, 2026  
**Status:** Completed & Enterprise Production-Ready  
**Test Suite:** Pest Feature Tests (`tests/Feature/ApiKeyManagementTest.php` - 15/15 Passed, 51 Assertions; `tests/Feature/StudyMaterialManagementTest.php` - 14/14 Passed, 58 Assertions)

---

## 1. Executive Summary & Future-Proof Architecture

Implemented an enterprise-grade, future-proof **API Key Management & External Integration System** for the Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS platform.

This module allows secure, granular, and seamless integration with:
1. **Future Mobile Applications** (iOS & Android built with Flutter, React Native, Swift, or Kotlin).
2. **Future Desktop Applications** (Windows, macOS, or Linux built with Electron, Tauri, or .NET C#).
3. **External Partner Portals, Web Applications, and Microservices**.
4. **Attendance Hardware & Biometric Relays**.

### Key Architectural Strengths:
- **Zero-Trust Security & SHA-256 Hashing**: Secret API keys are generated cryptographically (`sntc_live_...` or `sntc_test_...`) and presented **strictly once** to the administrator. The database stores only a cryptographic SHA-256 hash, ensuring that even in the event of an unauthorized database dump, tokens cannot be decrypted or compromised.
- **Instant Mobile QR Code Pairing**: Every generated or rotated key produces a high-resolution SVG/Base64 QR code powered by `chillerlan/php-qrcode`. Mobile and desktop engineers can scan this QR code directly into mobile companion apps to configure endpoints and tokens in seconds.
- **Granular Scopes / Abilities**: Access is strictly governed via domain-level scopes (e.g., `ping`, `read:academics`, `write:academics`, `read:students`, `write:students`, `read:materials`, `read:alumni`, `write:biometric`, `read:attendance`, `read:library`, or `*`).
- **Configurable Rate Limiting**: Per-key configurable throttles (default 60 req/min, scalable up to 10,000 req/min) returning standard headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After`.
- **IP Address Whitelisting & Domain Firewall**: Enforce CIDR blocks or specific IP addresses per key (e.g., `192.168.1.100`, `10.0.0.0/24`), immediately blocking unauthorized origins with `403 Forbidden (IP_UNAUTHORIZED)`.
- **Real-Time Telemetry & Request Audit Trails**: Every incoming request tracks execution latency (in milliseconds), client IP, sanitized headers, payload, status codes, and error summaries without recording credentials.
- **Interactive Developer Console & Sandbox**: Embedded test console allows developers and staff to simulate requests against live endpoints, view latency, test payloads, and copy boilerplate in Flutter, React Native, Electron, C#, Python, and cURL.

---

## 2. Database Design & Normalization

Created migration `database/migrations/2026_10_26_030000_create_api_keys_and_logs_tables.php`:

### 1. `api_keys` Table
- `id`: Big increments.
- `uuid`: Universally unique identifier for client references.
- `user_id`: Foreign key to `users(id)` (owner / bound account).
- `name`: Human-readable identifier (e.g. *SNTCSSC Flutter Mobile App*).
- `key_prefix`: Indexed prefix (e.g. `sntc_live_a1b2...`) safe for dashboard identification.
- `key_hash`: 64-character unique SHA-256 hash.
- `type`: Client category (`mobile_app`, `desktop_app`, `external_app`, `service`, `testing`).
- `environment`: Scope environment (`production`, `sandbox`).
- `status`: Lifecycle status (`active`, `suspended`, `revoked`).
- `scopes`: JSON array of authorized permissions.
- `ip_allowlist`: JSON array of allowed IPv4/IPv6 addresses or CIDR notations.
- `allowed_domains`: JSON array of allowed origins for web applications.
- `rate_limit_per_minute`: Configurable rate limit threshold.
- `expires_at`: Expiration timestamp (nullable for lifetime keys).
- `last_used_at`, `last_used_ip`, `last_used_user_agent`: Activity telemetry.
- `total_requests`: Counter of cumulative API calls.
- `description`, `metadata`: Extended notes and metadata.
- `revoked_at`, `revoked_by`, `revocation_reason`: Audit trail for revocations.
- `created_by`, `updated_by`, `deleted_by`: RBAC audit tracking.
- `timestamps`, `softDeletes`.

### 2. `api_key_logs` Table
- `id`: Big increments.
- `api_key_id`: Foreign key to `api_keys(id)` (`nullOnDelete`).
- `api_key_name`: Historical snapshot of key name.
- `ip_address`: Client IP.
- `user_agent`: Client user agent.
- `method`: HTTP method (`GET`, `POST`, `PUT`, `DELETE`).
- `endpoint`: Request URL path.
- `status_code`: HTTP response code (`200`, `401`, `403`, `429`, `500`).
- `duration_ms`: Execution latency in milliseconds.
- `request_headers`: Sanitized headers (authorization credentials scrubbed).
- `request_payload`: Sanitized request body parameters.
- `response_summary`: Error description or status summary.
- `created_at`: Indexed timestamp.

---

## 3. Core Models & Services

### `App\Models\ApiKey`
- Methods: `isValid()`, `isExpired()`, `isRevoked()`, `isSuspended()`, `hasScope($scope)`, `allowsIp($clientIp)`, `recordUsage($ip, $ua)`, `revoke($userId, $reason)`, `generateSecret($env)`.
- Scopes: `active()`, `search($term)`.

### `App\Models\ApiKeyLog`
- Methods: `isSuccessful()`, `isClientError()`, `isServerError()`.
- Scopes: `successful()`, `failed()`, `recent($days)`.

### `App\Services\ApiKey\ApiKeyService`
- `generateKey(array $data, ?User $user)`: DB transaction, key hashing, `AuditLogService::log('api_key.created')`, returns plain text key.
- `updateKey(ApiKey $apiKey, array $data, ?User $user)`: Modifies scopes, rates, IP filters, `AuditLogService::log('api_key.updated')`.
- `rotateKey(ApiKey $apiKey, ?User $user)`: Re-rolls token, invalidates old secret, `AuditLogService::log('api_key.rotated')`.
- `revokeKey(ApiKey $apiKey, ?string $reason, ?User $user)`: Immediately cuts off access, `AuditLogService::log('api_key.revoked')`.
- `toggleStatus(ApiKey $apiKey, ?User $user)`: Suspends or unpauses key.
- `deleteKey()`, `restoreKey()`, `forceDeleteKey()`: Soft delete and permanent purge.
- `getDashboardStatistics()`: Real-time telemetry metrics.
- `logRequest()`: Sanitized request telemetry logger.

---

## 4. Authentication Middleware & Security Enforcement

### `App\Http\Middleware\AuthenticateApiKey`
- Dual token ingestion: reads `X-API-Key: sntc_...` or `Authorization: Bearer sntc_...` or `api_key` parameter.
- Evaluates SHA-256 hash against database.
- Checks revocation, suspension, expiry, and IP allowlist (supporting single IPs and CIDR blocks).
- Evaluates per-key throttling via Laravel Cache rate limiting.
- Attaches authenticated `$apiKey` and user context to `$request`.
- Emits response headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-API-Environment`.
- Supports `:optional` mode for public catalogs (e.g. study materials, alumni).

### `App\Http\Middleware\RequireApiScope`
- Enforces granular scopes (e.g. `api.scope:read:students`, `api.scope:read:academics`).
- Supports wildcard access (`*`) and namespace wildcards (`read:*`).
- Returns structured JSON `403 Forbidden` (`INSUFFICIENT_SCOPE`) with granted vs required scopes.

---

## 5. RESTful API Endpoints (`routes/api.php`)

All endpoints are standardized under `/api/v1/` with structured JSON responses:
1. **`GET /api/v1/ping`** (`api.scope:ping`): Diagnostic connectivity test, server time, and key metadata.
2. **`GET /api/v1/app/info`** (`api.scope:read:system,ping`): Application version, institution metadata, and module flags.
3. **`POST /api/v1/echo`** (`api.scope:ping`): Echo test payload for mobile/desktop payload debugging.
4. **`GET /api/v1/courses` & `GET /api/v1/academics/courses`** (`api.scope:read:academics`): Active courses directory with fees and batch counts.
5. **`GET /api/v1/batches` & `GET /api/v1/academics/batches`** (`api.scope:read:academics`): Academic batches list.
6. **`GET /api/v1/students`** (`api.scope:read:students`): Paginated student search with enrollments.
7. **`GET /api/v1/students/{identifier}`** (`api.scope:read:students`): Student profile dossier by ID, UUID, URN, or Student ID.
8. **`GET /api/v1/study-materials`** (`api.scope:read:materials`): Paginated course materials and file attachments.
9. **`GET /api/v1/alumni`** (`api.scope:read:alumni`): Verified alumni profiles and civil service achievement dossiers.
10. **`POST /api/v1/biometric/punch`** (`api.scope:write:biometric`): Biometric hardware punch ingest.

---

## 6. Modern UI/UX Livewire Admin Interfaces

Three production-grade Livewire Single File Components matching the institution's dark/light design system:

### 1. `resources/views/pages/admin/api-keys/⚡index.blade.php` (`/admin/api-keys`)
- Real-time institutional KPI cards: Total Keys, Active Keys, 24h Request Volume, Error Rate, Avg Latency.
- Filter toolbar: Search, Status (`active`, `suspended`, `revoked`, `expired`, `trashed`), Client Type (`mobile_app`, `desktop_app`, `external_app`, `service`, `testing`), and Environment (`production`, `sandbox`).
- Data table with type badges, prefix tags, granted scopes pills, rate limits, and relative last active telemetry.
- **Interactive Modals (No native browser alerts)**:
  - **Create Modal**: Name, integration type, environment, rate limits, expiry presets (30d, 60d, 90d, 1y, Never, Custom), IP allowlist, domains, and grouped scope selector with "Select All" / "Toggle Group".
  - **Zero-Trust Secret Reveal & QR Modal**: Shows plain key strictly once, 1-click copy with feedback, QR code image for mobile app scanning, and copyable cURL verification snippet.
  - **Edit Modal**: Update scopes, rate limits, allowlists, and description.
  - **Roll / Rotate Secret Modal**: Regenerate key secret safely with confirmation.
  - **Revoke Modal**: Immediate revocation with optional reason.
  - **Delete Modal**: Soft delete and permanent purge dialogs.
  - **API Test Modal**: Interactive test console directly from the table row.

### 2. `resources/views/pages/admin/api-keys/⚡logs.blade.php` (`/admin/api-keys/logs`)
- Live request stream with timestamp, HTTP method badges, endpoint paths, HTTP status codes, latency badges, and client IPs.
- Telemetry filter bar: Status (2xx, 4xx, 5xx, 401, 403, 429), Method, Key, Search query.
- **Request Inspector Modal**: Deep dive into sanitized request headers, payload parameters, and error traces.
- **Prune Logs Modal**: Prune logs older than 7, 30, 90 days, or all.

### 3. `resources/views/pages/admin/api-keys/⚡docs.blade.php` (`/admin/api-keys/docs`)
- Interactive developer guide with quick-start for authentication headers.
- **Multi-Platform SDK Snippets** with 1-click copy:
  - **Flutter (Dart)** for Mobile Application.
  - **React Native (TypeScript/JS)** for Cross-Platform Mobile.
  - **Electron (Node.js)** for Desktop Applications.
  - **C# (.NET 8+)** for Windows Desktop clients.
  - **Python (Requests)** for Backend scripts / Microservices.
  - **cURL** for Terminal testing.
- Endpoints and scopes reference matrix.
- **Interactive Sandbox Console**: Select any active key, pick endpoints, send requests, and inspect real-time JSON responses and latency.

---

## 7. RBAC, Sidebar, Translations & Icons

- Added `API & Integrations` permission module in `App\Services\RbacService`:
  - `api_keys.view`, `api_keys.create`, `api_keys.edit`, `api_keys.revoke`, `api_keys.delete`, `api_keys.rotate`, `api_keys.logs`.
  - Automatically seeded and synced to `Super Administrator` and `Administrator`.
- Added `API & INTEGRATIONS` navigation group in `resources/views/layouts/app/sidebar.blade.php`.
- Added missing Lucide icons to `App\Support\LucideIcons`:
  - `terminal`, `laptop`, `plug`, `webhook`, `braces`, `code-2`.
- Added complete English, Bengali, and Hindi localization strings in `lang/en.json`, `lang/bn.json`, and `lang/hi.json`.

---

## 8. Verification & Quality Assurance

- **Pest Feature Tests**: `tests/Feature/ApiKeyManagementTest.php`  
  - 15 tests, 51 assertions, 100% passing.
- **Regression Tests**: `tests/Feature/StudyMaterialManagementTest.php`  
  - 14 tests, 58 assertions, 100% passing.
- **Code Style**: Formatted according to project standards via `vendor/bin/pint --dirty --format agent`.
