# Changelog: Course & Academics Management REST API Suite, Developer Console & Multi-Platform Enterprise Integration

**Date:** 2026-10-31  
**Author:** SNTCSSC Development Team  
**Category:** Course & Academics Management, Multi-Platform REST APIs, Developer Console & In-App Documentation, Dynamic Study Material Integration, Kiosk Scanners & Turnstiles, Enterprise Architecture, Audit Trails  
**Status:** Completed & Production Ready  

---

## 1. Executive Summary

As part of the institutional digital modernization of the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC)**, this release delivers a complete, enterprise-grade RESTful API ecosystem, documentation, and Developer Console integration for the **Course and Academics Management System**, alongside full in-app developer portal integration for the **Dynamic Study Material Management Module**.

The new architecture decouples academic structures (courses, cohorts/batches, classroom sections, calendar timetables, and intake capacities) from monolithic views, providing turnkey integrations across:
- **Student Mobile Applications** (Flutter & React Native) for public course discovery, syllabus browsing, batch schedule inspection, and real-time class timetables.
- **Administrative & Circulation Desktop Applications** (Electron & C# .NET) for admissions counter staff, academic coordinators, and registrar kiosks.
- **Campus Turnstiles & Verification Kiosks** (C# .NET & Python Microservices) for section capacity checks, room allocations, and student shift validation.
- **Web Portals & Developer Consoles** (Admin Panel Blade/Livewire) with full-featured interactive testing, documentation, and real-time execution sandboxes directly within the admin dashboard.

All implementations strictly adhere to enterprise production standards:
- **Atomic Database Transactions** (`DB::transaction`) wrapping all mutative operations to prevent orphaned records or state inconsistency.
- **Audit Trails & Security Compliance** via `AuditLogService::log()`, recording timestamps, actor user IDs, auditable models, and old/new delta state.
- **Soft Delete Protection** (`SoftDeletes`) on all courses, batches, and classroom sections.
- **Active Enrollment Dependency Guards** (`ACTIVE_DEPENDENCY_PREVENTED`) preventing deletion of courses, batches, sections, or batch-course linkages whenever active student enrollments exist.
- **Standardized API Response Envelopes** (`success` / `error`, data payload, contextual meta, error codes).
- **Complete Tri-lingual Localization** (`lang/en.json`, `lang/bn.json`, `lang/hi.json`) for all new UI strings, status badges, and documentation tables.
- **Automated Pest 5 Test Coverage** (9 feature tests, 119 assertions) and 100% clean formatting with Laravel Pint.

---

## 2. Course & Academics Management REST API Architecture (29 Endpoints)

The entire academic hierarchy is exposed under `/api/v1/academics/*` and aliased under top-level endpoints `/api/v1/courses`, `/api/v1/batches`, `/api/v1/sections`, `/api/v1/catalog/courses`:

### 2.1. Executive Telemetry & Capacity Analytics
- `GET /api/v1/academics/dashboard` (`read:academics`): Executive telemetry returning summary KPIs (`total_courses`, `active_courses`, `total_batches`, `active_batches`, `total_sections`, `total_capacity`, `total_active_students`, `occupancy_percentage`), category distributions, multi-year batch counts, and recent cohorts.
- `GET /api/v1/academics/statistics` (`read:academics`): Granular capacity and occupancy breakdown by course, batch, and classroom section, along with multi-year admissions intake trends.

### 2.2. Course Offerings Management (CRUD)
- `GET /api/v1/academics/courses` (`read:academics`): Search, filter, and paginate academic courses by keyword, category, status, or fee type.
- `POST /api/v1/academics/courses` (`write:academics`): Provision a new course offering with fee structure, intake limit, and code.
- `GET /api/v1/academics/courses/{identifier}` (`read:academics`): Course dossier with linked batches, active sections, capacity utilization rate, and enrolled student count.
- `PUT /api/v1/academics/courses/{identifier}` (`write:academics`): Update course metadata, description, duration, or active status.
- `DELETE /api/v1/academics/courses/{identifier}` (`write:academics`): Soft-delete a course offering. Protected: blocked if active student enrollments exist.
- Top-level aliases: `GET|POST|PUT|DELETE /api/v1/courses/*`.

### 2.3. Academic Batches & Cohorts Management (CRUD)
- `GET /api/v1/academics/batches` (`read:academics`): Query batches with dates, academic year, target exam year, and student capacity.
- `POST /api/v1/academics/batches` (`write:academics`): Provision a new academic batch linked to an academic year, session, and intake limit.
- `GET /api/v1/academics/batches/{identifier}` (`read:academics`): Batch dossier with attached courses, active sections, timetable overview, and students.
- `PUT /api/v1/academics/batches/{identifier}` (`write:academics`): Update batch metadata, academic year, dates, or active status.
- `DELETE /api/v1/academics/batches/{identifier}` (`write:academics`): Soft-delete an academic batch. Protected: blocked if active student enrollments exist.
- Top-level aliases: `GET|POST|PUT|DELETE /api/v1/batches/*`.

### 2.4. Batch-Course Offerings Association
- `GET /api/v1/academics/batches/{id}/courses` (`read:academics`): List all courses linked to a specific academic batch with fees and capacity overrides.
- `POST /api/v1/academics/batches/{id}/courses` (`write:academics`): Attach an existing course offering to an academic batch.
- `DELETE /api/v1/academics/batches/{id}/courses/{courseId}` (`write:academics`): Detach a course offering from an academic batch. Blocked if students are actively enrolled in this pair.

### 2.5. Classroom Sections & Room Allocations (CRUD)
- `GET /api/v1/academics/sections` (`read:academics`): List classroom sections with batch, room assignment, shift timing, and student allocation headcounts.
- `POST /api/v1/academics/sections` (`write:academics`): Create a new classroom section for a batch with assigned room and maximum seating capacity.
- `GET /api/v1/academics/sections/{id}` (`read:academics`): Classroom section dossier with enrolled student roster, seat occupancy, and vacancy.
- `PUT /api/v1/academics/sections/{id}` (`write:academics`): Update section room, shift, capacity, or section label.
- `DELETE /api/v1/academics/sections/{id}` (`write:academics`): Soft-delete a classroom section. Blocked if students are currently allocated to it.
- Top-level aliases: `GET|POST|PUT|DELETE /api/v1/sections/*`.

### 2.6. Institutional Metadata & Academic Calendar
- `GET /api/v1/academics/academic-years` (`read:academics`): Distinct academic years and admission sessions available in the institution.
- `GET /api/v1/academics/calendar` (`read:academics`): Institutional academic calendar schedule: attendance holidays, academic events, working calendars, and batch milestones.

### 2.7. Bulk Data Import & Export
- `GET /api/v1/academics/courses/export` (`read:academics`): Export course catalog as JSON dataset.
- `POST /api/v1/academics/courses/import` (`write:academics`): Bulk import academic courses from standard structured payloads.
- `GET /api/v1/academics/batches/export` (`read:academics`): Export batches dataset as JSON.
- `POST /api/v1/academics/batches/import` (`write:academics`): Bulk import academic batches from standard structured payloads.

### 2.8. Public Course Discovery Catalog (Zero Auth Required)
- `GET /api/v1/academics/catalog` (`auth.api_key:optional`): Public course discovery catalog for prospective applicants and mobile onboarding.
- `GET /api/v1/academics/catalog/{identifier}` (`auth.api_key:optional`): Detailed public course syllabus and open batch schedules for candidate admission applications.
- Top-level aliases: `GET /api/v1/catalog/courses` and `GET /api/v1/catalog/courses/{identifier}`.

---

## 3. Developer Console & In-App Documentation (`⚡docs.blade.php`)

The interactive Developer Console was significantly updated to integrate the **Course & Academics Management System** and ensure the **Study Material Management Module** is testable and documented:
1. **Official Architecture & Integration Guide Card:** Added the Course & Academics guide card linked to `docs/integrations/course-and-academics-management-api.md` with in-app modal reading via `wire:click="openGuide('academics')"`.
2. **Interactive Testing Presets:** Added 14 pre-configured interactive testing sandbox presets for Course & Academics:
   - Executive Telemetry (`GET /api/v1/academics/dashboard`)
   - Capacity & Occupancy Statistics (`GET /api/v1/academics/statistics`)
   - Course Offerings List (`GET /api/v1/academics/courses`)
   - Create New Course (`POST /api/v1/academics/courses`)
   - Course Dossier (`GET /api/v1/academics/courses/1`)
   - Cohorts & Batches Catalog (`GET /api/v1/academics/batches`)
   - Provision Academic Batch (`POST /api/v1/academics/batches`)
   - Batch Offering Linkage (`POST /api/v1/academics/batches/1/courses`)
   - Classroom Sections Roster (`GET /api/v1/academics/sections`)
   - Provision Classroom Section (`POST /api/v1/academics/sections`)
   - Institutional Academic Years (`GET /api/v1/academics/academic-years`)
   - Academic Calendar & Holidays (`GET /api/v1/academics/calendar`)
   - Public Course Discovery Catalog (`GET /api/v1/academics/catalog`)
   - Bulk Import Courses (`POST /api/v1/academics/courses/import`)
3. **Dynamic Study Material Enhancements:** Added missing `GET /api/v1/study-materials/1/download-zip` to Study Materials presets, and verified authenticated endpoints under `auth.api_key`.
4. **Scope Tab and Reference Table:** Added the `Course & Academics (29)` filter tab with all 29 routes, methods, and descriptions.
5. **Multi-Platform Code Recipes:** Updated all multi-platform code snippet tabs (Flutter Dart, React Native JavaScript, Electron Node.js, C# .NET, Python, and cURL) with practical Course & Academics examples.

---

## 4. Integration Guide Document

Created a comprehensive integration guide:
`docs/integrations/course-and-academics-management-api.md`
- Multi-Platform Architecture & Target Platforms
- Security, Authentication & Scope Permissions Matrix
- Complete Endpoints Specification with Request/Response JSON Schemas
- Mermaid Sequence Diagrams for Course Lifecycle, Public Catalog Discovery, and Section Capacity Assignment
- Native Code Implementation Recipes:
  - Flutter (Dart) Client Service
  - React Native (TypeScript) Client Service
  - C# .NET Windows Desktop Client
  - cURL Terminal Commands
- Mobile Offline Caching & Sync Strategy
- Production Hardening & Security Safeguards

---

## 5. Model & Relationship Enhancements

- **`App\Models\Section`**: Added `creator(): BelongsTo` and `updater(): BelongsTo` relationships linking to `User::class` on `created_by` and `updated_by` foreign keys, matching `Course` and `Batch` models.

---

## 6. Tri-Lingual Localization Updates

Updated `lang/en.json`, `lang/bn.json`, and `lang/hi.json` with 59 new keys each covering:
- Course and Academics navigation labels
- Academic dashboard KPIs and statistics terms
- Section and batch status badges
- Error messages and dependency guard notifications
- Developer Console cards, presets, and guides

---

## 7. Verification & Automated Testing

- **Pest 5 Test Suite (`tests/Feature/AcademicApiTest.php`):**
  - `academic endpoints enforce authentication and scopes` (401 without key, 403 on missing scopes)
  - `public discovery catalog is accessible without api key`
  - `academic dashboard and statistics endpoints return valid telemetry`
  - `course crud operations with audit logs and validation`
  - `batch crud operations and course attachment`
  - `section crud operations and vacancy management`
  - `active student enrollment dependency guard prevents deletion` (asserting 422 `ACTIVE_DEPENDENCY_PREVENTED`)
  - `academic years and calendar schedules return valid structures`
  - `bulk import courses and batches endpoints validate and process data`
  - **Result: 9 passed, 119 assertions (3.08s)**
- **Regression Testing:**
  - `tests/Feature/StudyMaterialDynamicApiTest.php`: 8 passed, 92 assertions (2.76s)
  - `tests/Feature/StudentEnrollmentApiTest.php`: 12 passed, 173 assertions (6.20s)
- **Code Style:** Formatted modified PHP files with Laravel Pint (`vendor/bin/pint --format agent`).

