# Changelog: Enterprise Admission OTR API Integration, Candidate Dossiers & Automated Enrollment Hub

**Date:** November 11, 2026  
**Module:** Admissions & Admission OTR Synchronization Gateway  
**Author:** Antigravity  
**Status:** Production Ready & Verified (7 Pest Feature Tests Passing, 43 Assertions, Zero Regressions)  

---

## 1. Overview & Architectural Goals

The **SNTCSSC MIS (Management Information System)** now includes an enterprise-grade, configurable **Admission OTR Integration & Import Hub**. This feature connects the separate **SNTCSSC Admission OTR** portal to **SNTCSSC MIS** via secure REST API Bearer authentication, allowing administrators to inspect and import admitted/selected candidates along with their complete multi-table application dossiers directly into MIS.

### Core Architecture Highlights:
1. **Decoupled & Optional Feature:**
   - The integration is an optional, additional module that can be enabled or disabled at any time from System Settings (`otr.enabled`).
   - When disabled, both systems operate completely standalone without errors, background calls, or dependencies.
2. **Multi-Table Dossier Mirroring:**
   - Candidate details from Admission OTR (`application_profiles`, `application_addresses`, `appl_edu_qualifications`, `appl_empl_histories`, `appl_documents`, `appl_other_details`) map directly to the normalized MIS schema:
     - `users` (Student user account with lifetime 9-digit `student_id`)
     - `student_profiles` (Father/mother details, category, PwBD, income, occupations)
     - `student_addresses` (Permanent and correspondence addresses)
     - `student_educations` (10th, 12th, Graduation, percentages, CGPA, subjects)
     - `student_employments` (Prior employment histories)
     - `student_documents` (Photos, signatures, caste certificates, IDs, and marksheet file paths)
     - `student_other_details` (UPSC attempts left/given, optional subject, hobbies)
     - `enrollments` (Official course and batch enrollment records)
3. **Smart Duplicate & Multi-Course Recognition:**
   - Detects whether a candidate already exists in MIS (matching by URN, email, phone, or application number).
   - If candidate is already enrolled in the target Course and Batch: skips duplicate creation and reports existing enrollment.
   - If candidate exists in MIS but is being enrolled into a new course (e.g., enrolled in PCC earlier, now admitted into CC): adds a new `Enrollment` record without duplicating user records, while updating the profile dossier if configured.
4. **Database Transactions & Telemetry:**
   - Every import runs in an isolated database transaction (`DB::transaction`) with automatic rollback on error.
   - Detailed audit logs in `otr_import_logs` and `audit_logs` record the exact payload snapshot, timestamps, actor, and status.
5. **Asynchronous Deferred Loading & Anti-Deadlock Safeguards:**
   - Initial page loads render instantaneously (< 30ms) without blocking on external HTTP cURL requests.
   - Data feeds load asynchronously via Livewire `wire:init="initAdmissionsHub"` with animated skeleton shimmer states.
   - Loopback port collision detector (`OtrSyncService::isSelfReferencingPort`) prevents PHP built-in web server deadlocks when running both applications locally.
   - Strict `connectTimeout(3)` and request `timeout(8)` ensure immediate failover without ever hitting PHP's 30-second `max_execution_time`.

---

## 2. Root Cause Analysis & Resolution for 500 Timeout Error

### 2.1 Issue Diagnosis
When accessing `/admissions`, users previously encountered:
```
local.ERROR: Maximum execution time of 30 seconds exceeded
Symfony\Component\ErrorHandler\Error\FatalError: Maximum execution time of 30 seconds exceeded
at .../vendor/guzzlehttp/guzzle/src/Handler/CurlHandler.php:85
```

### 2.2 Root Cause
1. **Single-Threaded Server Deadlock:** During initial page rendering of `/admissions`, computed properties `feedData` and `availableExams` fired synchronous HTTP requests via Guzzle to `http://127.0.0.1:8000`. Because PHP's built-in web server (`php artisan serve`) is single-threaded, port 8000 could not serve the incoming cURL request while it was still executing the initial request, creating a 30-second loopback deadlock.
2. **Missing Fallback Route:** Top-level `/admissions` did not have an automatic redirect to active team slugs `/{current_team}/admissions`.

### 2.3 Comprehensive Fix Applied
1. **Asynchronous Feed Loading:** In `⚡admissions.blade.php`, converted synchronous computed properties into deferred component state (`$isFeedLoading = true`, `$feedItems`, `$feedMeta`, `$availableExams`) initialized via `wire:init="initAdmissionsHub"`. Initial HTML renders in ~25ms with elegant animated skeleton loaders.
2. **Loopback Collision Safeguard:** Added `OtrSyncService::isSelfReferencingPort()` which checks if target host and port match the running MIS instance. If so, it fails fast gracefully with an informative user warning instead of locking the thread.
3. **Connection & Request Timeouts:** Added `connectTimeout(3)` and clamped request `timeout(min($timeout, 8))` to all HTTP calls, wrapped in specific `ConnectionException` and `Throwable` catch blocks.
4. **Default Setting Update:** Updated `otr.base_url` default to `http://127.0.0.1:8080` (where Admission OTR runs) in both database and `SettingsSeeder.php`.
5. **Top-Level Route Redirect:** Added `Route::get('admissions', ...)` in `routes/web.php` to seamlessly redirect authenticated users to `/{current_team}/admissions`.

---

## 3. Database Schema & Models

### 3.1 Migration: `create_otr_import_logs_table.php`
- **`otr_import_logs` Table:**
  - `id`: Primary key
  - `user_id`: Foreign key referencing `users(id)` (cascade on delete)
  - `enrollment_id`: Nullable foreign key referencing `enrollments(id)`
  - `course_id`: Foreign key referencing `courses(id)`
  - `batch_id`: Foreign key referencing `batches(id)`
  - `section_id`: Nullable foreign key referencing `sections(id)`
  - `imported_by`: Nullable foreign key referencing `users(id)`
  - `admission_selection_id`, `admission_form_id`, `admission_form_number`: OTR identifiers
  - `application_id`, `application_number`, `exam_id`, `exam_code`, `urn`: Application references
  - `candidate_name`, `candidate_email`, `candidate_phone`: Contact snapshot
  - `status`: String enum (`success`, `failed`, `already_enrolled`, `skipped`)
  - `action_taken`: String enum (`created_student_and_enrolled`, `enrolled_existing_student`, `already_enrolled`, `failed`)
  - `downloaded_documents_count`: Integer
  - `payload_snapshot`: JSON field storing full 14-table OTR response snapshot
  - `error_message`: Nullable text
  - `timestamps` & `softDeletes`

### 3.2 Model: `App\Models\OtrImportLog`
- Eloquent model with `Auditable`, `HasFactory`, `SoftDeletes`.
- Relationships: `user`, `enrollment`, `course`, `batch`, `section`, `importedBy`.
- Scopes: `successful()`, `failed()`, `search()`.

---

## 4. Service Layer: `App\Services\OtrSyncService`

The centralized domain service handles all HTTP communication, mapping, and import operations:
- `isEnabled(): bool`: Checks `Setting::get('otr.enabled')`.
- `getBaseUrl(): string`: Retrieves configured OTR Base URL.
- `getApiKey(): string`: Retrieves decrypted API key secret.
- `isSelfReferencingPort(?string $url): bool`: Validates against local loopback deadlocks.
- `ping(?string $baseUrl, ?string $apiKey): array`: Health check with latency measurement.
- `fetchExams(): array`: Fetches available examinations/courses from OTR.
- `fetchAdmissions(array $filters): array`: Queries `/api/v1/admissions/all-data` with filters (`status`, `exam_id`, `selection_list`, `search`, `page`, `per_page`).
- `fetchAdmissionDetail(id): array`: Queries single candidate all-data endpoint.
- `checkCandidateMisStatus(candidateItem, targetCourseId, targetBatchId): array`: Analyzes candidate existence in MIS and enrollment state.
- `importCandidate(candidateItem, courseId, batchId, sectionId, options, actor): array`: Atomic candidate import.
- `importBulk(candidateItems, courseId, batchId, sectionId, options, actor): array`: Granular batch import with per-candidate isolation.
- `getDashboardStatistics(): array`: Real-time telemetry (total records, new students created, multi-course enrolled, failed count, last sync time).

---

## 5. Role-Based Access Control (RBAC) & Settings

### 5.1 Granular Permissions
Added in `App\Services\RbacService.php`:
- `admissions.otr_sync`: "Connect to Admission OTR portal, fetch admitted candidates, and run imports into MIS"
- `admissions.otr_configure`: "Configure Admission OTR API keys, endpoints, and automatic synchronization settings"
- Assigned to `Super Administrator`, `Administrator`, and `Admissions Officer`.

### 5.2 System Settings Seeded
- `otr.enabled`: (Boolean) Enable/disable feature flag
- `otr.base_url`: (String) Base URL (`http://127.0.0.1:8080`)
- `otr.api_key`: (Secret) AES-256 encrypted bearer key
- `otr.timeout`: (Number) Request timeout (10 seconds)
- `otr.default_course_id`: (Number) Default Course ID for imports
- `otr.default_batch_id`: (Number) Default Batch ID for imports
- `otr.download_documents`: (Boolean) Download binary files to MIS storage vs keep external reference
- `otr.update_existing_profiles`: (Boolean) Update student profile dossier if candidate already exists in MIS

---

## 6. User Interface & Experience (Livewire Volt + Tailwind v4)

### 6.1 Admissions & OTR Import Hub (`pages::admin.admissions`)
- **Header & Telemetry Cards:**
  - Real-time online/offline/disabled status indicator with live ping response time.
  - 5 Key Metric Cards: Total OTR Candidates, New Students Created, Multi-Course Enrolled, Total Synchronized, Last Sync Timestamp.
  - Quick actions: "Test Connection", "OTR Settings", "Refresh Feed", "Import Selected (N)".
- **Tabs:**
  1. *OTR Admitted Candidates Feed*:
     - Target Course & Batch enrollment selector bar.
     - Live filters: Examination/Programme select, MIS Status filter (`All`, `Pending Import Only`, `Already In MIS`), Selection List filter (`A`, `B`), full-text search with debounce.
     - Candidate data table with avatar, URN, application number, form number, roll number, fee payment status, MIS status pill (`Enrolled in MIS`, `Student Exists`, `New Candidate`).
     - Actions: "Dossier" preview button, "Import & Enroll" button.
  2. *Historical OTR Imports & Enrollment Logs*:
     - Chronological audit table of all imports with timestamps, actor, enrollment number, and status badges.
  3. *Quick Connection Config*:
     - In-page credential and base URL updates without leaving the Admissions Hub.
- **Interactive Modal Dialogs (No browser alerts/confirms):**
  1. *Candidate Dossier Modal*: Multi-tab full application view (Personal & Family, Addresses, Educations, Employment, Documents with view links, UPSC Details).
  2. *Single Import Modal*: Confirmation dialog to choose target Course, Batch, Section, Fee Amount, Fee Status, and document policies.
  3. *Bulk Import Modal*: Multi-candidate import confirmation with breakdown of new vs existing candidates.
  4. *Import Results Modal*: Comprehensive summary of success, new students, multi-course enrollments, and expandable error details.
  5. *Test Connection Modal*: Diagnostic ping display with latency, API key preview, scopes, and server time.

### 6.2 Dedicated Settings Page (`pages::admin.settings.otr`)
- Route: `/system/settings/otr` (`route('admin.settings.otr')`).
- Accessible from main sidebar under "Settings > Admission OTR Settings" and from the Admissions Hub.
- Dual-mode (Light/Dark) support adhering to high-contrast enterprise design.

---

## 7. Verification & Automated Test Results

- **Pest Feature Suite (`tests/Feature/OtrAdmissionImportTest.php`):**
  - `feature toggle can be enabled and disabled from settings`: **Passed**
  - `can import new candidate and create full dossier`: **Passed**
  - `duplicate import into same course and batch is recognized and skipped`: **Passed**
  - `importing existing candidate into second course creates new enrollment`: **Passed**
  - `admissions page renders instantly with HTTP 200 without network deadlock`: **Passed**
  - `livewire admissions component mounts and initializes gracefully`: **Passed**
  - `self referencing port check detects port collisions accurately`: **Passed**
  - **Summary:** 7 tests passed, 43 assertions passed (0 failures).
- **Live End-to-End Verification (`test_e2e_live.php`):**
  - Ping to live Admission OTR server on port 8080: HTTP 200, 564ms.
  - Fetched 193 candidate dossiers and 2 examination programmes.
  - Successfully imported real candidate "Ayantika Mondal" (ID: 202610001, Enrollment: `EN-2026-CC-0016`).
  - Successfully verified duplicate skip (`already_enrolled`).
  - Successfully verified multi-course enrollment (`enrolled_existing_student`, Enrollment: `EN-2026-PCC-0001`).
  - Verified feature toggle disable/enable protections.
- **Icons & Visuals Audit:** Verified 21/21 Lucide SVG icons registered and rendering properly.
- **Localization Audit:** 226/226 keys translated with 0 missing across `en.json`, `bn.json`, and `hi.json`.
- **View Compilation:** `php artisan view:clear` and re-compiled without errors.


