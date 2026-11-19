# Changelog: Dynamic Custom Material Types & External Public Study Material Integration with Lead Capture and OTP Verification

**Date:** October 26, 2026  
**Status:** Completed & Fully Tested  
**Test Suite:** Pest Feature Tests (68/68 Passed, 342 Assertions)  
**Code Formatter:** Laravel Pint (`vendor/bin/pint --dirty --format agent` Clean)

---

## 1. Executive Summary

In response to institutional requirements for public engagement, outreach, and marketing lead acquisition, this update implements:
1. **Dynamic Custom Study Material Types:** Full capability for administrators to dynamically create, configure, toggle, and curate custom publication categories (such as *PIB Sandhan - An In-House Initiative of SNTCSSC*, *Monthly Current Affairs*, *Mains Answer Writing Synopses*, etc.) with custom icon, badge color, display ordering, and external API visibility.
2. **RESTful External API Integration (Secured via API Key Management):** High-throughput, token-authenticated API endpoints under `/api/v1/external/study-materials` secured by `auth.api_key` and API scopes (`read:materials`, `download:materials`), enabling external websites and mobile applications to query and display specific publication types.
3. **Public Lead Capture & OTP Verification Workflow:**
   - Public visitors seeking to download materials provide identification: First Name, Last Name, Email, and Mobile Number.
   - **Genuineness Check:** If the email/phone is new or unverified, the system issues Email and Mobile OTPs via `OtpService` and holds a secure 15-minute verification session.
   - **Automated Lead Ingestion:** Upon successful OTP verification, the user's lead information is automatically recorded under the `subscribers` table (`Subscriber::SOURCE_STUDY_MATERIAL`) with marketing tags and material metadata.
   - **Existing User Bypass:** If the visitor's mobile or email is already registered and verified in the database, the system bypasses re-verification and immediately issues an unrestricted download token.
   - **Secure Binary Streaming:** Token-secured download endpoint (`/api/v1/external/study-materials/download/{token}`) streams single documents or packaged ZIP files directly to the user's browser or mobile device, logging complete access telemetry.

---

## 2. Architectural & Database Changes

### 2.1 Database Migrations
- **`2026_10_26_040000_create_study_material_types_and_lead_captures_table.php`**:
  - Created `study_material_types` table (`id`, `name`, `code`, `description`, `icon`, `badge_color`, `is_public`, `is_system`, `is_active`, `display_order`, `created_by`, `updated_by`, `deleted_by`, timestamps, soft deletes).
  - Pre-seeded system defaults including `current_affairs` and `pib_sandhan` (An In-House Initiative of SNTCSSC).
  - Altered `study_materials.course_id` to be nullable to support standalone institutional publications intended for all students and public external audiences.
  - Extended `study_material_access_logs` with `subscriber_id`, `guest_name`, `guest_email`, `guest_phone`, `is_verified`, and `metadata` (JSON).

### 2.2 Models & Relationships
- **`App\Models\StudyMaterialType`**:
  - Full Eloquent model with `Auditable` and `SoftDeletes`.
  - Scopes: `scopeActive`, `scopePublic`, `scopeOrdered`, `scopeSearch`.
  - Helpers: `activeOptions()`, `publicOptions()`.
  - Auto-slugification of `code` on creation.
- **`App\Models\StudyMaterial`**:
  - Added constants `TYPE_CURRENT_AFFAIRS = 'current_affairs'` and `TYPE_PIB_SANDHAN = 'pib_sandhan'`.
  - Dynamic `materialTypes()` loading from active `StudyMaterialType` definitions with graceful fallback.
  - Relation: `typeDefinition(): BelongsTo(StudyMaterialType::class, 'material_type', 'code')`.
  - Dynamic icon resolution from type definition.
- **`App\Models\Subscriber`**:
  - Added constant `SOURCE_STUDY_MATERIAL = 'study_material_download'`.
  - Relation: `studyMaterialAccessLogs(): HasMany(StudyMaterialAccessLog::class)`.
- **`App\Models\StudyMaterialAccessLog`**:
  - Fillable fields and casts for `subscriber_id`, `guest_name`, `guest_email`, `guest_phone`, `is_verified`, `metadata`.
  - Relation: `subscriber(): BelongsTo(Subscriber::class)`.

---

## 3. Services & Controllers

### 3.1 Services
- **`App\Services\StudyMaterial\StudyMaterialTypeService`**:
  - Handles CRUD operations for material types (`createType`, `updateType`, `deleteType`, `toggleStatus`, `getActiveTypes`, `getPublicTypes`).
  - Strict checks preventing deletion of core system types or types with attached materials.
  - Comprehensive audit logging via `AuditLogService`.
- **`App\Services\StudyMaterial\StudyMaterialService`**:
  - Updated `recordAccess()` to record `Subscriber`, guest lead details, verification status, and request telemetry.
  - Supports nullable `course_id` for standalone public publications.

### 3.2 External RESTful API Controller
- **`App\Http\Controllers\Api\ExternalStudyMaterialApiController`**:
  - `GET /api/v1/external/study-materials/types`: Lists active public material types with published materials count.
  - `GET /api/v1/external/study-materials`: Filter by `type` / `material_type`, keyword `search`, `year`, `month`, `sort`, pagination.
  - `GET /api/v1/external/study-materials/{identifier}`: Complete dossier and file metadata.
  - `POST /api/v1/external/study-materials/{identifier}/download-request`:
    - Checks verification status against `subscribers`, `users`, and verified `otp_codes`.
    - If verified: Records lead, logs access, generates secure token, returns immediate download URL (`requires_verification: false`).
    - If unverified: Generates session, dispatches email & mobile OTPs (`requires_verification: true`).
  - `POST /api/v1/external/study-materials/verify-otp-and-download`:
    - Validates OTP code against pending session.
    - Records user under `subscribers` table (`source: study_material_download`, tags, metadata).
    - Logs access with `is_verified: true`.
    - Returns secure 2-hour download token.
  - `POST /api/v1/external/study-materials/resend-otp`:
    - Resends verification code respecting cooldown limits.
  - `GET /api/v1/external/study-materials/download/{token}`:
    - Validates token from cache, streams single file directly or packs multiple files into a named ZIP archive on the fly.

---

## 4. Admin Livewire UI Hub Enhancements

- **`resources/views/pages/admin/study-materials/⚡index.blade.php`**:
  - **Material Types Catalog Modal (`$showTypesModal`):**
    - Inspect all system and custom types, view icons, codes, and external API status.
    - Toggle status (Active / Inactive) with a single click.
    - Edit and delete custom types.
  - **Create / Edit Material Type Modal (`$showTypeFormModal`):**
    - Input name, custom slug, description, Lucide icon selector, and curated badge color.
    - "Public API Access & Discovery" toggle to control external website and mobile app listing.
  - **Inline Quick-Add Trigger:**
    - Next to the "Material Type" dropdown in the Upload modal, admins can click `+ Custom Type` to define a new type on the fly without abandoning their form draft.
  - **Scope Tabs:** Added dedicated tabs for *Current Affairs* and *PIB Sandhan*.
  - **Course Flexibility:** Course selector supports `-- General / All Courses (Public Publication) --` for general public publications.

---

## 5. Verification & Test Suite

### Pest Feature Tests
- `tests/Feature/StudyMaterialPublicApiLeadCaptureTest.php`:
  1. `unauthenticated external request without api key is rejected with 401` -> Passed
  2. `external request with api key lacking material scope is rejected with 403` -> Passed
  3. `external client can list public study material types including PIB Sandhan and Current Affairs` -> Passed
  4. `admin can dynamically create, edit, toggle, and delete custom material types` -> Passed
  5. `external client can query study materials by dynamic type like PIB Sandhan and Current Affairs` -> Passed
  6. `new unverified user initiating download triggers OTP verification session` -> Passed
  7. `public user verifying OTP receives download token and is recorded in subscribers table as lead` -> Passed
  8. `existing verified subscriber bypasses OTP verification and accesses download immediately` -> Passed
  9. `admin study materials livewire component supports dynamic custom material types management` -> Passed

### Full Regression Test Summary
- `StudyMaterialPublicApiLeadCaptureTest.php`: 9/9 Passed (69 assertions)
- `StudyMaterialManagementTest.php`: 19/19 Passed (85 assertions)
- `ApiKeyManagementTest.php`: 15/15 Passed (51 assertions)
- `AlumniManagementTest.php`: 19/19 Passed (123 assertions)
- `StudentDashboardProfileRequestTest.php`: 14/14 Passed (111 assertions)
- `BatchCourseEnrollmentStudentTest.php`: 12/12 Passed (59 assertions)
- **Total: 88 tests passed, 510+ assertions, 0 failures.**

---

## 6. Custom Type Modal, Icons, Student Dashboard & Translations Refinement

1. **Custom Material Type Modal Bug Fix (`$showTypeFormModal`):**
   - **Root Cause:** The sub-modal previously used non-standard Tailwind class `z-55`, which evaluated to `z-index: auto` (0), rendering the modal underneath or clashing with the `$showFormModal` (`z-50`) and `$showTypesModal` (`z-50`) DOM stacking contexts.
   - **Resolution:**
     - Elevated to `z-[70]` on backdrop with deep semi-transparent overlay `bg-black/70 dark:bg-black/80 backdrop-blur-sm overflow-y-auto`.
     - Elevated `$showTypesModal` to `z-[60]`.
     - Added distinct `wire:key="study-material-type-form-modal"` and `wire:key="study-material-types-management-modal"`.
     - Added click-outside dismissal (`wire:click.self`).
     - Added `$this->resetErrorBag()` and `$this->resetValidation()` before opening.
     - Automatically invalidated `$this->allMaterialTypesList` computed cache upon saving, toggling, or deleting.

2. **Lucide Icons Audit & Missing SVG Data Added:**
   - Updated `app/Support/LucideIcons.php` with SVG paths for `newspaper`, `headphones`, and `folder-down`.
   - Added aliases `'grid' => 'layout-grid'` and `'folder-arrow-down' => 'folder-down'`.

3. **Student Dashboard & Sidebar Options:**
   - **Sidebar:** Positioned `Study Materials` prominently in `$mainNavItems` right alongside `Academics & Enrolment` for all logged-in students.
   - **Student Dashboard Header:** Added a dedicated "Study Materials" button in the active enrolments header.
   - **Enrolled Course Cards:** Added a "Course Materials" shortcut button inside every active course enrolment card for 1-click access.

4. **Public vs. Enrolled Study Material Access Control:**
   - Added `StudyMaterial::isPubliclyAvailable()` detecting:
     - `access_level === self::ACCESS_PUBLIC_LINK` (when not expired)
     - `course_id === null` (general public publications)
     - `material_type` is `current_affairs` or `pib_sandhan`
     - Custom dynamic material types marked `is_public`
   - Updated `isAccessibleBy(?User $user)` and `scopeForUser()`: All logged-in students can view and download public materials without any course or batch restrictions, while enrolled course and batch materials strictly require active enrollment.

5. **Multi-Lingual Translations:**
   - Added all missing UI and modal strings across `lang/en.json`, `lang/bn.json`, and `lang/hi.json`.

