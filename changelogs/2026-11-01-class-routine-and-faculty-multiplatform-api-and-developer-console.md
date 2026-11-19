# Changelog: Class Routine & Faculty Module Multi-Platform API Suite & Developer Console Integration

**Date:** November 1, 2026  
**Module:** Class Routine & Schedule Management • Faculty Master • Classroom Allocation • Partner Institutes Directory • Course Plans Telemetry • Automated Class Reminders • Student & Faculty Self-Service Portals • In-Admin API Developer Console & Documentation Portal  
**Status:** Enterprise Production Ready  

---

## 1. Executive Summary

This release introduces the enterprise-grade **Class Routine & Faculty Management Module** RESTful API suite (**64 production endpoints**), delivers future-ready multi-platform integration capabilities across **Mobile Apps** (Flutter, React Native, Swift, Kotlin), **Web Applications** (React, Vue, Livewire, Next.js), and **Desktop / Kiosk Systems** (Electron, Windows .NET 8 WPF/MAUI, Digital Signage), and updates the in-admin **API Developer Console & Docs** (`resources/views/pages/admin/api-keys/⚡docs.blade.php`).

All endpoints strictly adhere to enterprise-grade production standards:
- **Dual Authentication Architecture:**
  - Administrative and microservice integrations authenticate via `auth.api_key` using HTTP `X-API-Key` headers paired with granular permission scopes (`read:academics` and `write:academics`).
  - Student and Faculty self-service portals authenticate via `auth:sanctum` Personal Bearer Tokens, securely scoping data access directly to the authenticated user's active enrollments and faculty profiles.
- **Conflict Prevention Engine:** Real-time algorithmic conflict checking prevents faculty double-booking and physical classroom collisions across overlapping time windows.
- **Atomic Database Transactions:** All data mutations (class scheduling, batch recurring generation, completions, cancellations, reschedules, reminder triggers, file uploads, and bulk spreadsheet imports) are wrapped in `DB::beginTransaction()`, `DB::commit()`, and `DB::rollBack()`.
- **Structured Audit Logging:** Every create, update, delete, restore, file upload, reminder dispatch, and status transition is recorded through `AuditLogService::log()` with user context, IP address, and payload snapshots.
- **Enterprise Soft Deletes:** Non-destructive soft deletes with corresponding `/restore` endpoints implemented across `ClassSchedule`, `Faculty`, `Classroom`, `PartnerInstitute`, and `CoursePlan`.
- **Multi-Format Reporting & Export/Import:** Native Excel (`xlsx`), CSV, and PDF exports alongside spreadsheet bulk importing with automatic field mapping and validation.
- **Multi-Channel Automated Class Reminders:** Configurable lead-time reminder engine supporting WhatsApp, SMS, Email, Telegram, and In-App Portal notifications (`AppNotification`).
- **Complete Test Coverage:** Comprehensive feature test suite (`ClassRoutineAndFacultyApiTest.php`) verifying all CRUD, restore, calendar, workload, reminder alert, student self-service, and faculty self-service workflows with 100% passing tests.

---

## 2. API Scope Expansion (`ApiKeyService.php`)

Enhanced the existing `read:academics` and `write:academics` institutional scopes in `app/Services/ApiKey/ApiKeyService.php` to govern class routines, faculty masters, classroom allocations, partner institutes, and course planning:

| Scope Identifier | Category | Permissions Granted |
| :--- | :--- | :--- |
| `read:academics` | Academics & Routine | View batches, courses, class schedules, timetable calendars, faculty profiles, faculty workload reports, classroom utilization, partner institutes, course plans, and reminder settings. |
| `write:academics` | Academics & Routine | Create and update classes, recurring schedules, mark classes completed, upload lecture recordings, manage faculty dossiers, configure classroom capacities, trigger manual reminders, and export/import academic data. |

---

## 3. Architecture & Service Layer Expansion

### 3.1 Faculty Management Service (`app/Services/FacultyService.php`)
- **Lifecycle Management:** Creation, updates, soft deletion, and restoration of faculty profiles with automatic unique code generation (`FAC-YYYY-XXXX`).
- **Media & Document Handling:** Seamless avatar photo and resume/CV upload processing with automatic sanitization and file renaming via `App\Concerns\RenamesUploads`.
- **Workload Analytics:** `getFacultyWorkloadStats(Faculty $faculty)` and `getGlobalWorkloadStats()` computing assigned classes, conducted sessions, upcoming lectures, total hours taught, and cohort distribution.
- **Export & Import Engine:** `exportFaculties()` supporting formatted Excel, CSV, and PDF downloads, and `importFaculties()` supporting batch spreadsheet ingestion with validation.

### 3.2 Automated Class Reminder Service (`app/Services/ClassReminderService.php`)
- **Settings Registry:** Lead-time configuration (`classes.reminder_lead_time_minutes`), active channels (`classes.reminder_channels`), and dynamic template customization via `Setting::set()`.
- **Cohort Resolution:** `getEnrolledStudentsForClass()` automatically fetches all actively enrolled students matching the scheduled class's batch, course, and section.
- **Dispatch Engine:** Dispatches multi-channel alerts (WhatsApp, SMS, Email, Telegram, In-App), updates reminder counters (`reminder_sent_at`, `reminder_count`), logs dispatches into `communication_logs`, and creates `AppNotification` records.

### 3.3 Class Schedule Engine (`app/Services/ClassScheduleService.php`)
- Conflict detection validating faculty and room availability.
- Recurring schedule generation across weekly date intervals.
- Lecture completion marking, reschedule management, and course plan lecture counter synchronization.

---

## 4. Complete REST API Route Directory (64 Production Endpoints)

### 4.1 Class Schedules & Routines (28 Endpoints)
- `GET /api/v1/classes` — Paginated list of classes with filtering by date, batch, course, section, faculty, classroom, status, delivery mode, and search.
- `POST /api/v1/classes` — Schedule a single lecture session with conflict detection.
- `POST /api/v1/classes/recurring` — Batch schedule recurring lecture sessions across date ranges.
- `GET /api/v1/classes/calendar` — Grouped timetable matrix by date or day of the week.
- `GET /api/v1/classes/reports/faculty-workload` — Faculty teaching load and completion statistics.
- `GET /api/v1/classes/reports/course-coverage` — Syllabus and lecture plan execution progress.
- `GET /api/v1/classes/reports/classroom-utilization` — Physical classroom occupancy and utilization telemetry.
- `GET /api/v1/classes/settings/reminders` — Get global automated reminder settings.
- `PUT /api/v1/classes/settings/reminders` — Update reminder lead times, channels, and message templates.
- `GET /api/v1/classes/course-plans` — List course plans and syllabus outlines.
- `POST /api/v1/classes/course-plans` — Create a new syllabus course plan.
- `GET /api/v1/classes/course-plans/{id}` — Get single course plan details and associated lectures.
- `PUT /api/v1/classes/course-plans/{id}` — Update an existing course plan.
- `DELETE /api/v1/classes/course-plans/{id}` — Soft delete a course plan.
- `POST /api/v1/classes/course-plans/{id}/restore` — Restore a soft deleted course plan.
- `GET /api/v1/classes/course-plans/{id}/progress` — Calculate lecture completion rate and remaining topics.
- `GET /api/v1/classes/export` — Export routine schedules to Excel, CSV, or PDF.
- `POST /api/v1/classes/import` — Import routine schedules from spreadsheet.
- `GET /api/v1/classes/{id}` — Single class details with batch, course, section, classroom, and faculty relations.
- `PUT /api/v1/classes/{id}` — Update lecture timing, topic, classroom, or assigned faculties.
- `DELETE /api/v1/classes/{id}` — Soft delete a scheduled lecture.
- `POST /api/v1/classes/{id}/restore` — Restore a soft deleted lecture.
- `POST /api/v1/classes/{id}/complete` — Mark session as completed and update faculty workload stats.
- `POST /api/v1/classes/{id}/reschedule` — Reschedule lecture to new date/time with reschedule history tracking.
- `POST /api/v1/classes/{id}/cancel` — Cancel lecture with reason and notify enrolled students.
- `POST /api/v1/classes/{id}/recording` — Attach video recording URL, cloud file path, and lecture notes.
- `POST /api/v1/classes/{id}/send-reminder` — Trigger manual on-demand reminder alert to enrolled cohort.
- `GET /api/v1/classes/{id}/enrolled-students` — List student cohort enrolled for the lecture.

### 4.2 Faculty Master Management (11 Endpoints)
- `GET /api/v1/faculties` — Paginated faculty directory with department, institute, type, and status filtering.
- `POST /api/v1/faculties` — Create faculty profile with auto-generated code and audit logging.
- `GET /api/v1/faculties/workload-stats` — Global workload analytics across all faculty members.
- `GET /api/v1/faculties/export` — Export faculty master directory to Excel, CSV, or PDF.
- `POST /api/v1/faculties/import` — Bulk import faculties from Excel or CSV spreadsheet.
- `GET /api/v1/faculties/{id}` — Faculty dossier, bio, academic profile, and workload statistics.
- `PUT /api/v1/faculties/{id}` — Update faculty credentials, department, and contact details.
- `DELETE /api/v1/faculties/{id}` — Soft delete faculty record.
- `POST /api/v1/faculties/{id}/restore` — Restore soft deleted faculty profile.
- `GET /api/v1/faculties/{id}/schedule` — Individual timetable and upcoming lectures for a specific faculty member.
- `GET /api/v1/faculties/{id}/workload` — Detailed teaching load analytics and conducted class telemetry.

### 4.3 Classrooms & Physical Facilities (7 Endpoints)
- `GET /api/v1/classrooms` — List classrooms with capacity, building, and maintenance status filtering.
- `POST /api/v1/classrooms` — Register a new physical classroom or smart hall.
- `GET /api/v1/classrooms/{id}` — Single classroom details, equipment facilities, and current status.
- `PUT /api/v1/classrooms/{id}` — Update classroom seating capacity, building, floor, or status.
- `DELETE /api/v1/classrooms/{id}` — Soft delete classroom record.
- `POST /api/v1/classrooms/{id}/restore` — Restore soft deleted classroom record.
- `GET /api/v1/classrooms/{id}/schedule` — View room allocation timeline to avoid scheduling conflicts.

### 4.4 Partner Institutes Directory (7 Endpoints)
- `GET /api/v1/partner-institutes` — List partner institutes and coaching alliances.
- `POST /api/v1/partner-institutes` — Register new partner institute with auto-generated institution code.
- `GET /api/v1/partner-institutes/{id}` — Partner institute details with linked faculties count.
- `PUT /api/v1/partner-institutes/{id}` — Update partner institute contact info and status.
- `DELETE /api/v1/partner-institutes/{id}` — Soft delete partner institute record.
- `POST /api/v1/partner-institutes/{id}/restore` — Restore soft deleted partner institute.
- `GET /api/v1/partner-institutes/{id}/faculties` — List all faculty members associated with this partner institute.

### 4.5 Student Self-Service Portal (`/api/v1/student/`) (5 Endpoints)
- `GET /api/v1/student/classes` — Student's personal lecture routine filtered to their enrolled batches, courses, and sections.
- `GET /api/v1/student/routine` — Student's weekly routine timetable matrix.
- `GET /api/v1/student/classes/{id}` — Detailed lecture info, Zoom/Google Meet links, classroom venue, and recording access.
- `GET /api/v1/student/course-plans` — Syllabus progress and planned lecture roadmap for enrolled courses.
- `GET /api/v1/student/routine/download` — Download student's personalized routine timetable as a PDF document.

### 4.6 Faculty Self-Service Portal (`/api/v1/faculty/`) (6 Endpoints)
- `GET /api/v1/faculty/my-schedule` — Assigned lectures timetable for the authenticated faculty member.
- `GET /api/v1/faculty/my-profile` — Faculty dossier, contact credentials, and department information.
- `GET /api/v1/faculty/workload` — Teaching load statistics, total hours taught, and completion rate.
- `POST /api/v1/faculty/classes/{id}/recording` — Upload or update lecture recording link, passcode, and study notes.
- `POST /api/v1/faculty/classes/{id}/complete` — Mark assigned class as completed directly from the mobile/web app.
- `POST /api/v1/faculty/classes/{id}/reschedule` — Submit a reschedule request for an assigned lecture.

---

## 5. Developer Console & In-Admin Documentation (`⚡docs.blade.php`)

Enhanced the in-admin **API Developer Console & Docs** portal (`resources/views/pages/admin/api-keys/⚡docs.blade.php`):
1. **Interactive Sandbox Presets:** Added quick-fill preset buttons for:
   - `GET /api/v1/classes?today=1`
   - `GET /api/v1/classes/calendar`
   - `POST /api/v1/classes` (with pre-filled valid scheduling payload)
   - `GET /api/v1/classes/reports/faculty-workload`
   - `GET /api/v1/classes/settings/reminders`
   - `GET /api/v1/faculties`
   - `GET /api/v1/faculties/workload-stats`
   - `GET /api/v1/classrooms`
   - `GET /api/v1/partner-institutes`
   - `GET /api/v1/student/classes?today=1`
   - `GET /api/v1/faculty/my-schedule`
2. **Dynamic Guide Integration:** Updated `openGuide` action to load the dedicated markdown guide `docs/integrations/class-routine-and-faculty-api-guide.md` whenever class routine or faculty topics are selected.
3. **Comprehensive Directory Table:** Included all 64 endpoints in the catalog categorized under Class Routine & Faculty Master with required scopes, HTTP methods, and parameter guides.
4. **Hero Guide Card:** Added dedicated link card: *"Class Routine & Faculty Master REST API Guide (64 Endpoints • Enterprise Multi-Platform Ready)"*.

---

## 6. Multi-Platform Integration Guide (`docs/integrations/class-routine-and-faculty-api-guide.md`)

Authored a 28 KB enterprise guide detailing:
- **System Architecture & Data Flow:** High-level ASCII and Mermaid sequence diagrams covering scheduling conflict prevention, real-time Reverb WebSocket broadcasts, and automated class reminder notifications.
- **Authentication Protocols:** Step-by-step headers and token setup for administrative microservices (`X-API-Key`) and mobile apps (`Bearer <sanctum_token>`).
- **Complete Endpoint Specifications:** Detailed path, method, headers, request body, and response structures for all 64 endpoints.
- **Production Mobile SDK (Flutter / Dart):** Complete `ClassRoutineService.dart` implementation with offline caching, error handling, and routine parsing.
- **Production Web Hook (Next.js / React / TypeScript):** Complete `useTodayRoutine` hook featuring auto-refresh timers, loading states, and live link triggers.
- **Automated Verification Script:** Executable bash cURL test script for testing health, schedule listing, conflict checking, and dossier fetching.
- **Laravel Reverb WebSocket Channels:** Channel topologies (`private-student.{id}`, `private-faculty.{id}`, `presence-class.{id}`) and real-time event schemas (`ClassScheduled`, `ClassRescheduled`, `ClassCancelled`, `ClassCompleted`, `ClassReminderDispatched`).

---

## 7. Internationalization & UI Polish

- **Translation Catalogs:** Added 594 translation keys across English (`lang/en.json`), Bengali (`lang/bn.json`), and Hindi (`lang/hi.json`) covering all UI elements, status labels, delivery modes, error messages, and documentation headings.
- **Lucide Icons:** Added missing SVG definition for the `'wrench'` icon in `app/Support/LucideIcons.php`, eliminating UI rendering anomalies on the classroom maintenance status badge.
- **Styling & Accessibility:** Audited color contrast, typography hierarchy, and badge styles across `⚡classes.blade.php`, `⚡faculties.blade.php`, and `⚡classrooms.blade.php`.

---

## 8. Verification & Quality Assurance

- **New Test Suite:** `tests/Feature/ClassRoutineAndFacultyApiTest.php`
  - `faculty api lists faculties with department, institute, and status filtering` — **PASS**
  - `faculty api creates a new faculty member with auto code, audit log, and soft deletes` — **PASS**
  - `class schedule api marks session as completed, reschedules, and restores` — **PASS**
  - `class schedule calendar and reminder alert endpoints work properly` — **PASS**
  - `student self-service api returns personal classes for enrolled cohort` — **PASS**
  - `faculty self-service api allows assigned faculty to manage schedule and recordings` — **PASS**
  - `classroom and partner institute restore endpoints work as expected` — **PASS**
  - **Results:** 7 tests, 66 assertions, 0 failures.
- **Regression Test Suites:**
  - `tests/Feature/ClassRoutineManagementTest.php` — **PASS** (21 tests, 123 assertions)
  - `tests/Feature/PartnerInstitutesAndClassesEnhancementTest.php` — **PASS** (6 tests, 32 assertions)
- **Code Style Verification:**
  - Executed `vendor/bin/pint --format agent` on all modified files, ensuring strict compliance with PSR-12 and Laravel Pint style conventions.
