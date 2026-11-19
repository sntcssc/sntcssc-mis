# Changelog: Dynamic Classes & Lecture Schedule Routine Management System

**Date:** 2026-11-01  
**Category:** Academic Operations, Routine Scheduling, Faculty Master & Student Portal  
**Scope:** Full-stack enterprise routine management system with recurrence generation, multi-batch/course scheduling, online meeting platform integration, class recording access, comprehensive course plans, and role-based permissions.

---

## 1. Overview & Business Context

An enterprise-grade, fully dynamic **Classes & Lecture Routine Management System** was developed and integrated into the MIS platform. The system empowers academic administrators and faculty members to plan curriculum blueprints (Annual, Monthly, Weekly Course Plans), schedule routine lectures for batches, courses, and specific sections, execute recurring schedule generation across custom date windows, assign single or multiple faculty members, manage online meeting platforms (Zoom, Google Meet, Microsoft Teams, Cisco Webex, etc.) or offline classrooms, and upload or link lecture recordings for students.

Enrolled students access their schedule and routine through a responsive, real-time portal supporting multi-course and multi-batch enrollments, live lecture notifications, interactive modal dialogs, downloadable syllabi, class recording vault, and personalized PDF/Excel timetable exports.

---

## 2. Database Schema & Architecture

### Migration: `2026_11_01_012709_create_class_routine_and_faculty_management_tables.php`

1. **`faculties` (Master Teacher Directory)**
   - `id`: Auto-incrementing primary key.
   - `user_id`: Nullable foreign key linking to `users` for faculty login accounts.
   - `faculty_code`: Unique system identifier (`FAC-YYYY-###`).
   - `name`, `email`, `phone`: Teacher identity and communication coordinates.
   - `designation`: Academic title (e.g., Senior Professor, Associate Professor, Guest Lecturer).
   - `department`: Departmental allocation (e.g., General Studies, Optional History, Economics).
   - `specialization`: Domain expertise (e.g., Modern Indian History, Macroeconomics).
   - `qualification`: Degrees and certifications (e.g., Ph.D., UGC-NET).
   - `bio`: Academic background and profile dossier.
   - `status`: Active, Inactive, On Leave.
   - `subjects`: JSON array of competent teaching subjects.
   - `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.

2. **`course_plans` (Abstract Comprehensive Course Plans & Syllabi)**
   - `id`: Auto-incrementing primary key.
   - `plan_code`: Unique blueprint reference (`CP-YYYY-###`).
   - `title`: Descriptive course plan title.
   - `batch_id`, `course_id`, `section_id`: Academic cohort targeting (cascade on delete).
   - `plan_type`: `annual`, `monthly`, `weekly`, `custom`.
   - `academic_year`: Academic session identifier.
   - `start_date`, `end_date`: Target operational roadmap boundaries.
   - `total_lectures_planned`: Target planned lecture quota.
   - `topics_covered`: Syllabus units and milestone breakdown.
   - `description`: Comprehensive pedagogical strategy and reference bibliography.
   - `document_path`: Attached official syllabus document (PDF/Doc).
   - `version`: Version tracking (e.g., 1.0, 1.1).
   - `status`: `draft`, `published`, `under_revision`, `archived`.
   - `published_at`, `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.

3. **`class_schedules` (Operational Class Routine Instances)**
   - `id`: Auto-incrementing primary key.
   - `schedule_code`: Unique routine identifier (`CLS-YYYY-#####`).
   - `batch_id`, `course_id`, `section_id` (nullable: null signifies all sections of batch/course).
   - `course_plan_id`: Nullable link to master curriculum plan.
   - `class_type`: `regular`, `optional`, `extra`, `revision`, `guest_lecture`.
   - `class_date`: Date of the lecture.
   - `day_of_week`: Automatically computed and updated on date selection (`Monday`, `Tuesday`, etc.).
   - `start_time`, `end_time`: Timetable slot.
   - `lecture_number`: Optional lecture sequence identifier (e.g., `Lecture 14`).
   - `subject`, `topics`, `description`: Subject syllabus content and student instructions.
   - `delivery_mode`: `offline`, `online`, `hybrid`.
   - `classroom`: Offline physical room/hall number.
   - `platform`: `Zoom`, `Google Meet`, `Microsoft Teams`, `Cisco Webex Meetings`, `Others`.
   - `meeting_link`, `meeting_id`, `meeting_password`, `platform_other`: Online access credentials.
   - `recording_url`: Direct cloud recording URL (Zoom Cloud, YouTube Unlisted, Google Drive, Vimeo, CDN).
   - `recording_file_path`: Internal storage upload path.
   - `recording_password`, `recording_notes`: Passcode and timestamp index.
   - `recording_status`: `not_available`, `available`, `processing`.
   - `recurrence_group_id`: UUID linking batch-generated recurring instances.
   - `is_recurring`, `recurrence_rule`: Recurring series metadata.
   - `status`: `scheduled`, `in_progress`, `completed`, `rescheduled`, `cancelled`.
   - `cancellation_reason`, `rescheduled_from_id`, `rescheduled_reason`: Lifecycle audit trails.
   - `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.
   - Normalized indexes on `[batch_id, course_id, class_date]`, `[class_date, status]`, `[delivery_mode]`, and `[class_type]`.

4. **`class_schedule_faculty` (Pivot Table)**
   - Many-to-many relationship supporting team teaching, lead faculty, co-faculty, guest lecturers, and assistants.
   - Fields: `id`, `class_schedule_id`, `faculty_id`, `role`, `timestamps`.

---

## 3. Eloquent Models & Relationships

- **`App\Models\Faculty`**:
  - Implements `Auditable`, `HasFactory`, and `SoftDeletes`.
  - Automatic `faculty_code` generator (`FAC-YYYY-###`).
  - Scopes: `scopeActive`, `scopeForDepartment`.
  - Relationships: `user()` (BelongsTo), `classSchedules()` (BelongsToMany with `role` pivot), `creator()`, `updater()`.
  - Accessors: `initials`, `avatar_url`.

- **`App\Models\CoursePlan`**:
  - Automatic `plan_code` generator (`CP-YYYY-###`) and `published_at` synchronization.
  - Scopes: `scopePublished`, `scopeForBatch`, `scopeForCourse`.
  - Relationships: `batch()`, `course()`, `section()`, `classSchedules()`.

- **`App\Models\ClassSchedule`**:
  - Automatic `schedule_code` generator (`CLS-YYYY-#####`).
  - Automatic `day_of_week` calculation from `class_date` upon creation and update.
  - Scopes: `scopeToday`, `scopeUpcoming`, `scopePast`, `scopeScheduled`, `scopeCompleted`, `scopeForStudent(User $student)` (resolves multi-enrollment matching for batches, courses, and individual/global sections).
  - Helpers: `formatted_time`, `duration_minutes`, `isHappeningNow()`, `hasRecording()`, `recording_access_url`, `primary_faculty_names`.

- **Updated Models**:
  - `App\Models\Batch`: Added `classSchedules()` and `coursePlans()` relations.
  - `App\Models\Course`: Added `classSchedules()` and `coursePlans()` relations.
  - `App\Models\Section`: Added `classSchedules()` and `coursePlans()` relations.
  - `App\Models\User`: Added `faculty()` (HasOne) relation.

---

## 4. Backend Service: `App\Services\ClassScheduleService`

Encapsulates all business logic inside database transactions with comprehensive audit logging:
- `createSingle(array $data, array $facultyIds = [])`: Validates, assigns code, computes day of week, attaches teachers, logs audit event.
- `generateRecurring(array $baseData, array $facultyIds, array $config)`: Iterates across date ranges matching selected days of the week, creates atomic instances with auto-incrementing lecture numbers, tags series with UUID `recurrence_group_id`, records audit event.
- `updateSchedule(ClassSchedule $schedule, array $data, ?array $facultyIds)`: Updates schedule, recalculates day if date changes, updates faculty pivots, logs old and new states.
- `reschedule(ClassSchedule $schedule, string $newDate, string $newStartTime, string $newEndTime, ?string $reason)`: Updates schedule timing, updates status to `rescheduled`, logs trail.
- `cancel(ClassSchedule $schedule, string $reason)`: Cancels lecture with mandatory audit explanation.
- `markCompleted(ClassSchedule $schedule)`: Concludes lecture.
- `updateRecording(ClassSchedule $schedule, array $data)`: Saves recording cloud link, uploaded file path, passcode, timestamp index, sets status to `available`.
- `quickCreateFaculty(array $facultyData)`: Creates teacher record inline during class scheduling and returns instance for immediate assignment.
- `export(Collection|array $schedules, string $format)`: Generates CSV, Excel (.xlsx), and styled PDF reports.
- `import(string $filePath)`: Parses Excel/CSV, resolves batches, courses, sections, and faculties, creates schedules in transaction.

---

## 5. REST API Integration: `ClassScheduleApiController`

Registered in `routes/api.php` under `api/v1/classes` with `auth.api_key` and API scope authorization:
- `GET /api/v1/classes`: Filterable by batch, course, section, faculty, class_type, delivery_mode, date range, status, keyword search.
- `GET /api/v1/classes/{id}`: Full lecture detail with course plan, assigned teachers, and venue/meeting link.
- `POST /api/v1/classes`: Create single or recurring classes.
- `PUT /api/v1/classes/{id}`: Update class attributes.
- `DELETE /api/v1/classes/{id}`: Soft delete with audit log.
- `POST /api/v1/classes/{id}/reschedule`: Reschedule with reason.
- `POST /api/v1/classes/{id}/cancel`: Cancel with reason.
- `POST /api/v1/classes/{id}/recording`: Update recording access.
- `GET /api/v1/classes/faculties`: Directory of active teaching faculty.
- `GET /api/v1/classes/course-plans`: Published course plans and syllabi.
- `GET /api/v1/student/classes`: Personal classes for authenticated student across active enrollments.

---

## 6. Admin Livewire SFC Pages

1. **`resources/views/pages/admin/⚡classes.blade.php`** (`admin.classes.index`):
   - Real-time KPI statistics cards (Total, Today Scheduled, Completed, Online, Offline, Recordings Ready).
   - Toggle between **List View** and **Weekly Routine Calendar Matrix View**.
   - Comprehensive filter toolbar: Batch, Course, Section, Class Type (Regular vs Optional), Delivery Mode, Faculty, Date Window (Today, Tomorrow, This Week, Upcoming, Past), Status.
   - Interactive modals (no browser alerts):
     - Single Class Scheduling with auto-day calculation and dynamic course/section loading.
     - Batch Recurrence Generator with day-of-week checkboxes and lecture numbering.
     - Quick Add Teacher / Faculty modal.
     - Add / Upload Class Recording modal with passcode and index notes.
     - Reschedule Class modal.
     - Cancel Class modal with mandatory explanation.
     - Class Detail view modal.
     - Delete confirmation modal.
     - Bulk Import modal with downloadable CSV template.
     - Export to CSV, Excel, and PDF.

2. **`resources/views/pages/admin/⚡course-plans.blade.php`** (`admin.classes.plans`):
   - Comprehensive course plans management (Annual, Monthly, Weekly, Custom).
   - Targets tracking (Planned Lectures vs Conducted Classes progress bar).
   - Upload and download syllabus documents (PDF/Doc).
   - CRUD modals, publish / revise status lifecycle.

3. **`resources/views/pages/admin/⚡faculties.blade.php`** (`admin.faculties.index`):
   - Faculty Master Directory.
   - Search by name, designation, department, specialization, email, phone.
   - Filter by department and status.
   - Modals for creating/editing faculty, viewing full dossiers with recent classes history, status toggling, and soft deletion.
   - Export to CSV, Excel, and PDF.

---

## 7. Student Portal Livewire Experience

- **`resources/views/pages/student/⚡routine.blade.php`** (`student.routine.index`):
   - Multi-enrollment selector supporting students enrolled in one or multiple batches, courses, and assigned sections.
   - **Today's Classes Live Banner**: Pulsing "Live Now" indicator, 1-click "Join Class" button for online meetings (Zoom, Meet, Teams, Webex), physical classroom info for offline classes.
   - **Upcoming Routine Tab**: Filterable by course, batch, section, class type, and mode.
   - **Today's Schedule Tab**: Day-focused agenda.
   - **Class Recordings Vault Tab**: Access all concluded classes with available recordings, passwords, and timestamp notes.
   - **Course Plans & Syllabi Tab**: Abstract comprehensive course plans and downloadable syllabus files.
   - **Concluded Classes History Tab**: Complete historical attendance and lecture log.
   - **Print / Download My Routine**: Generates customized student timetable PDF or Excel.
   - Quick navigation pill integrated into Academics page (`student.academics.index`).

---

## 8. Role-Based Access Control (RBAC) & Security

- Added **`Class Routine & Faculty`** permission module to `App\Services\RbacService`:
  - `classes.view`, `classes.create`, `classes.edit`, `classes.delete`, `classes.recordings`, `classes.plans`, `classes.export`, `classes.import`.
  - `faculty.view`, `faculty.manage`, `faculty.delete`.
- Synced permissions to roles:
  - `Super Administrator` & `Administrator`: Full unrestricted access.
  - `Faculty`: Routine viewing, class scheduling, recording management, course plans, exports, and faculty directory.
  - `Staff` & `Admissions Officer`: Routine viewing, exports, and faculty directory.
  - `Student`: Access to personal enrolled class routines via `EnsureUserIsStudent` middleware.
- Input validation, database transactions, and comprehensive `AuditLogService` logging on every action.
- Soft delete support across all tables.

---

## 9. Internationalization (i18n)

Updated language catalogs:
- `lang/en.json` (English)
- `lang/bn.json` (Bengali)
- `lang/hi.json` (Hindi)

---

## 10. Verification & Quality Assurance

- **Unit & Feature Test Suite:** `tests/Feature/ClassRoutineManagementTest.php`
  - **21 passing tests, 123 assertions** covering:
    - Admin routine rendering and stats controls.
    - Faculty master CRUD, photo upload, CV upload, and custom department handling.
    - Single class creation with automatic day computation and classroom assignment.
    - Batch recurrence routine generation across dates.
    - Quick faculty inline creation.
    - Rescheduling and cancellation with audit reasons.
    - Canceled class isolation from syllabus progress and scheduled counts.
    - Recording upload and passcode management.
    - Student routine isolation (enrolled vs non-enrolled courses/batches).
    - Multi-course enrollment routine aggregation.
    - Course plans publication with soft copy document upload and student downloads.
    - REST API endpoints for schedules, faculties, and student routines.
    - Livewire student routine component rendering, soft copy syllabus downloads, and tabs.
    - Livewire admin course plans component rendering.
    - Classroom master CRUD, search, filters, and Excel/CSV/PDF exports.
    - Faculty workload and comprehensive routine reports (workload, course coverage, classroom utilization).
    - Automated and manual class reminder alerts dispatching via WhatsApp, SMS, Email, and Telegram.
    - CSV and Excel export file response generation.
- **Regression Testing:** `SectionManagementCrudTest` and `BatchCourseLivewireTest` executed (20 tests, 122 assertions, 100% pass).
- **Code Style:** Formatted using Laravel Pint (`vendor/bin/pint --format agent`).

---

## 11. Enterprise Enhancements (Phase 2 Additions)

### A. Dynamic Classroom Master Management (CRUD)
- Created migration `2026_11_01_030000_create_classrooms_and_update_schedules_tables.php` creating `classrooms` table.
- Added model `App\Models\Classroom` with `Auditable`, `SoftDeletes`, and course/section assignment helper `isAssignedToCourseOrSection()`.
- Added `App\Services\ClassroomService` providing full CRUD operations, conflict detection, bulk spreadsheet import with sample template generation, and multi-format exports (Excel, CSV, PDF).
- Built interactive Livewire management page `resources/views/pages/admin/⚡classrooms.blade.php` with responsive stats, search/filtering, modal forms, facilities checkboxes, and deletion safeguards.
- Integrated classroom selection into single schedule creation and recurring batch generation with room conflict prevention.

### B. Canceled Class Handling in Schedule Count
- Fixed count integrity by separating canceled sessions from conducted and active routine quotas.
- Added Eloquent scopes `scopeCancelled()` and `scopeNotCancelled()` to `ClassSchedule`.
- Enhanced `CoursePlan` with virtual attributes:
  - `conducted_lectures_count` (classes completed)
  - `scheduled_lectures_count` (classes scheduled)
  - `cancelled_lectures_count` (classes cancelled, tracked for audits)
  - `effective_lectures_count` (conducted + scheduled, excluding canceled)
  - `remaining_lectures_to_schedule` (`total_lectures_planned - effective_lectures_count`)
  - `progress_percentage` (`(conducted_lectures_count / total_lectures_planned) * 100`)

### C. Faculty Photo, CV Upload & Custom Department
- Enhanced `faculties` table with `photo_path` and `cv_path`.
- Enhanced `App\Models\Faculty` with `DEPARTMENTS` constant and accessors `photo_url`, `cv_url`, and `cv_file_name`.
- Added department selector in `⚡faculties.blade.php` featuring:
  - Predefined options: `General Studies`, `Geography`, `Ancient History`, `Medival History`, `Medieval History`, `Modern History`, `Polity`, `Economy`, `Economics`, `History`, `Ethics`, `Essay`, `CSAT`, and `Custom`.
  - When `Custom` is selected, an input field dynamically appears for custom department entry.
- Added photo thumbnail and downloadable CV actions in both faculty list table and detailed dossier modal.

### D. Detailed Course Plan Soft Copy
- Added file upload `documentFile` in `⚡course-plans.blade.php` allowing admins to upload the official syllabus document (PDF/DOC, up to 20MB).
- Updated student timetable routine portal `⚡routine.blade.php` (Course Plans tab) to display official syllabus file cards with "View" and "Download Soft Copy" actions.

### E. Faculty Classes Tracking & Comprehensive Reports
- Added View Switcher in `⚡classes.blade.php` for:
  - **List View**: Tabular timetable with filtering and inline actions.
  - **Calendar View**: Weekly day-by-day matrix routine.
  - **Faculty Tracker**: Per-faculty summary tracking assigned sessions, conducted classes, upcoming classes, canceled sessions, total hours taught, and completion rate.
  - **Routine Reports**: Full report generator supporting **Faculty Workload & Teaching Report**, **Course Routine & Syllabus Coverage Report**, and **Classroom Utilization & Occupancy Report** with instant Excel, CSV, and PDF downloads.

### F. Scheduled Classes Reminder Notification Alert System
- Implemented `App\Services\ClassReminderService` managing automated & manual student reminders.
- **Configurable Settings**:
  - Global toggle (`classes.reminders_enabled`).
  - Lead time in minutes before class begins (default: 60 minutes).
  - Multi-channel delivery selection: WhatsApp, SMS, Email, and Telegram.
  - Customizable template with dynamic tag placeholders (`{student_name}`, `{subject}`, `{course_name}`, `{class_date}`, `{class_time}`, `{delivery_mode}`, `{venue_or_link}`, `{faculty_name}`).
- **Automated Scheduled Execution**:
  - Created console command `php artisan classes:send-reminders {--force}` to query upcoming classes starting within the lead time window and notify active enrolled students.
- **Manual Reminder Trigger**:
  - Added interactive "Send Reminder Now" modal in admin routine view allowing administrators to send on-demand reminders (e.g. 2 days ahead of time) with custom notes.
- Logs all alerts in `AppNotification` and `CommunicationLog` for comprehensive auditability.

### G. RBAC Permissions Expansion
- Added permissions in `App\Services\RbacService`:
  - `classrooms.view`, `classrooms.manage`, `classrooms.delete`
  - `classes.remind`, `classes.reports`
- Updated sidebar navigation with `Classrooms Master` under Academics section.

---

## 8. Partner Coaching Institutes, Quick Faculty Upgrades, RenamesUploads & Multi-Platform REST APIs

### A. Partner Institutes for Coaching Master Module
- **Database Schema**: Created migration `2026_11_01_034030_create_partner_institutes_and_update_faculties_table.php` establishing table `partner_institutes` (`id`, `code`, `name`, `description`, `image_path`, `website_url`, `email`, `mobile`, `phone`, `address`, `start_date`, `end_date`, `status`, `official_name`, `official_designation`, `official_contact`, `official_email`, `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`).
- **Eloquent Model (`App\Models\PartnerInstitute`)**:
  - Implemented `Auditable`, `HasFactory`, `SoftDeletes`.
  - Added auto-code generator (`INST-YYYY-###`), status badge constants (`active`, `inactive`, `mou_expired`, `pending`), relationships (`faculties()`, `creator()`, `updater()`), scopes (`scopeActive()`, `scopeSearch()`), and accessors (`image_url`, `status_badge_color`, `status_label`).
- **Service Layer (`App\Services\PartnerInstituteService`)**:
  - Implemented `createInstitute`, `updateInstitute`, `deleteInstitute`, `exportInstitutes` (Excel, CSV, PDF via `TableExporter`), `importFromSpreadsheet`, and `generateSampleTemplate`.
- **Livewire SFC (`⚡partner-institutes.blade.php`)**:
  - Full CRUD with statistics cards (Total Institutes, Active Partnerships, MoU Expired/Due, Linked Faculties).
  - Search, status filtering, column sorting, pagination.
  - Interactive modals: `partner-institute-form-modal`, `partner-institute-view-modal`, `partner-institute-delete-modal`, `partner-institute-import-modal`.
- **RBAC & Sidebar**:
  - Registered permissions `partner_institutes.view`, `partner_institutes.manage`, `partner_institutes.delete`, `partner_institutes.export`, `partner_institutes.import`.
  - Added route `admin.partner-institutes.index` in `routes/web.php` and sidebar navigation link in `resources/views/layouts/app/sidebar.blade.php`.

### B. Faculty Reference Institute & Employment Type
- Added `partner_institute_id` (foreign key) and `faculty_type` (`regular`, `guest`, `visiting`, `contractual`, `adjunct`) to `faculties` table and model.
- Added relationship `partnerInstitute()` and accessor `faculty_type_label` to `Faculty.php`.
- Enhanced `⚡faculties.blade.php` to include:
  - Reference Partner Institute dropdown selector.
  - Faculty Type selector.
  - Instant badges for faculty type and partner institute in the main directory table.
  - Profile dossier view displaying linked coaching institute coordinates.

### C. Quick Add Teacher Upgrade in Routine Scheduler
- Upgraded "Quick Add Teacher" modal (`quick-faculty-modal`) in `⚡classes.blade.php` to match full Faculty Master standards:
  - Full Name, Designation, Department dropdown with "Custom" text input.
  - Photo upload with thumbnail preview and "Remove photo" button.
  - CV / Resume file upload with "Remove CV" button.
  - Reference Partner Institute dropdown selector.
  - Faculty Type dropdown selector (`regular`, `guest`, `visiting`, etc.).
  - Email, Phone, Specialization, Qualification, Competent Subjects, and Biography.
  - Automatically selects the newly created faculty in the active class scheduling form.

### D. File Removal Options & `RenamesUploads` Trait Standard
- Integrated `App\Concerns\RenamesUploads` trait:
  - Formats upload filenames as `{$type}_user_{$userId}_{$timestamp}.{$extension}`.
  - Applied across Partner Institutes logos, Faculty photos & CVs, and Course Plans syllabus documents.
- Added "Remove uploaded file" option in Edit Course Plan modal (`⚡course-plans.blade.php`), ensuring old files are cleaned up from storage disk.

### E. Classroom Modal & Reminder Toggle Fixes
- Replaced manual `@if` modals in `⚡classrooms.blade.php` with `<x-ui.modal>` standard components (`classroom-form-modal`, `classroom-details-modal`, `classroom-delete-modal`, `classroom-import-modal`), resolving display issues.
- Fixed Scheduled Classes Reminder toggle switch styling in `⚡classes.blade.php` using standard accessible toggle markup.

### F. Global Search Full-Text Integration
- Enhanced `App\Services\GlobalSearchService`:
  - Added `nav_classes`, `nav_course_plans`, `nav_classrooms`, `nav_faculties`, `nav_partner_institutes` into `navigationCatalog()`.
  - Added `action_schedule_class`, `action_create_course_plan`, `action_add_partner_institute` into `quickActionsCatalog()`.
  - Added live database search handlers for:
    - **Class Routine & Schedules**: searched by subject, topics, lecture code.
    - **Course Plans**: searched by title, plan code, topics covered.
    - **Classrooms Master**: searched by name, room code, building, floor.
    - **Faculty Master**: searched by name, faculty code, department, email.
    - **Partner Institutes**: searched by name, institute code, official representative, email.

### G. Developer Console & Multi-Platform REST APIs
- Created API Controllers:
  - `PartnerInstituteApiController`: `/api/v1/partner-institutes` (Index, Store, Show, Update, Destroy, Faculties).
  - `ClassroomApiController`: `/api/v1/classrooms` (Index, Store, Show, Update, Destroy).
  - Enhanced `ClassScheduleApiController`: `/api/v1/classes/faculties` (POST quick-create), `/api/v1/classes/course-plans` (POST, GET {id}, DELETE {id}).
- Created multi-platform integration guide in `docs/integrations/classes-and-partner-institutes-api-guide.md` with Flutter, React/Next.js, and cURL examples.
- Updated in-admin Developer Console (`resources/views/pages/admin/api-keys/⚡docs.blade.php`):
  - Added interactive guide viewer `openGuide('classes')`.
  - Added testable endpoints with ready-to-test JSON payloads in API Sandbox console.
  - Added integration guide card in the developer console library.

### H. Verification & Code Quality
- Added full feature tests in `tests/Feature/PartnerInstitutesAndClassesEnhancementTest.php` (6 tests, 32 assertions passed).
- Re-tested existing `ClassRoutineManagementTest.php` (21 tests, 123 assertions passed).
- Executed `vendor/bin/pint --format agent` for PSR-12 and code formatting compliance.
- Added translations in `lang/en.json`, `lang/bn.json`, and `lang/hi.json`.

---

## 9. Button Visibility Normalization, Partner Institute Livewire Toasts & Comprehensive Import Engine

### A. Classroom Button Text Visibility Normalization
- **Issue**: "Add Classroom", "Create Classroom", "Update Classroom", and "Start Import" buttons rendered with invisible white text due to legacy `bg-primary-600` classes being unrecognized by the Tailwind CSS theme variables (`--color-primary`), leaving background transparent.
- **Resolution**:
  - Refactored all primary actions in `resources/views/pages/admin/⚡classrooms.blade.php` to `<x-ui.button>` components with semantic variants (`default`, `outline`, `destructive`).
  - Standardized styling across headers, create/edit modals, import modals, and delete confirmations.

### B. Partner Coaching Institute Validation & Livewire Toast Dispatching
- **Issue**: Submitting the Partner Institute form without selecting dates caused MySQL strict mode failure (`1292 Incorrect date value: '' for column 'start_date'`). Furthermore, notifications were utilizing session flashes (`Toast::success`, `Toast::error`) rather than reactive Livewire dispatches, which left the UI silent.
- **Resolution**:
  - Implemented `PartnerInstituteService::sanitizeData()` to automatically sanitize incoming payloads, casting empty strings to `null` and prepending `https://` to website URLs lacking protocols.
  - Added `booted()` saving listener to `App\Models\PartnerInstitute` guaranteeing empty date and string attributes are nullified before persistence.
  - Replaced all session flashes with `Toast::dispatch($this, 'success'|'error'|'warning', ...)` to trigger instant `<x-ui.toasts>` popups in the browser.
  - Enhanced form validation to conditionally enforce date logic only when both boundaries are supplied.

### C. Comprehensive Bulk Import Architecture (Courses, Batches, Sections, Course Plans & Faculty Master)
- **Unified Engine**: Created `App\Services\AcademicImportService` providing dedicated spreadsheet processing for Excel (`.xlsx`, `.xls`) and CSV files via PhpSpreadsheet:
  1. **Courses Import**: Handles Course Code, Name, Category, Duration, Fee, Reserved Fee, Capacity, Has Sections, Status, and Description with `Course::updateOrCreate()`.
  2. **Batches Import**: Processes Batch Code, Name, Academic Year, Date Ranges, Max Students, Status, and automatically syncs linked courses via comma-separated identifiers.
  3. **Sections Import**: Resolves target Batches and Courses, verifies or generates `BatchCourse` pivots, and registers classroom sections with capacity quotas, hall numbers, and shift timings.
  4. **Course Plans Import**: Ingests curriculum roadmap blueprints, resolving cohorts and optional sections with automated lecture quota tracking and published status synchronization.
  5. **Faculty Master Import**: Imports faculty profiles, resolving linked Partner Institutes, departmental taxonomy, employment classifications (`regular`, `guest`, `visiting`, etc.), and parsed competent subject tags.
- **Sample Spreadsheet Template Generators**:
  - Implemented `generateCourseTemplate()`, `generateBatchTemplate()`, `generateSectionTemplate()`, `generateCoursePlanTemplate()`, and `generateFacultyTemplate()` with bold header styling, auto-sized columns, and descriptive sample data rows.
- **Livewire UI Integrations**:
  - Added dedicated `<x-ui.modal>` import dialogs and header "Import" buttons with RBAC authorization across:
    - `resources/views/pages/admin/⚡courses.blade.php` (`course-import-modal`)
    - `resources/views/pages/admin/⚡batches.blade.php` (`batch-import-modal`)
    - `resources/views/pages/admin/⚡sections.blade.php` (`section-import-modal`)
    - `resources/views/pages/admin/⚡course-plans.blade.php` (`course-plan-import-modal`)
    - `resources/views/pages/admin/⚡faculties.blade.php` (`faculty-import-modal`)
  - Integrated diagnostic summary cards providing live breakdown of successfully imported counts and row-level error reporting.

### D. Multilingual Translations & Automated Test Suite
- Added comprehensive translations for all import dialogs, guidelines, diagnostics, and notifications in `lang/en.json`, `lang/bn.json`, and `lang/hi.json`.
- Registered granular RBAC permissions (`courses.import`, `batches.import`, `sections.import`, `faculty.import`) in `App\Services\RbacService`.
- Developed automated test suite `tests/Feature/AcademicImportAndEnhancementTest.php` covering CSV parsing, relationship linking, data sanitization, and template downloads.
- All 34 academic test cases (192 assertions) passing with 0 failures.

---

## 10. Fix Import Modals Accessibility & Partner Institute Academic Assignments (Batches, Courses & Sections)

### A. Root Cause Resolution for Import Modals
- **Issue**: Newly added import dialogs across `⚡courses.blade.php`, `⚡batches.blade.php`, `⚡sections.blade.php`, `⚡course-plans.blade.php`, and `⚡faculties.blade.php` failed to render or trigger when clicking the "Import" action button. In addition, `⚡classrooms.blade.php` relied on round-trip asynchronous Livewire dispatches without client-side Alpine binding.
- **Root Cause Identified**:
  - In `courses`, `batches`, `sections`, `course-plans`, and `faculties`, the `<x-ui.modal name="...-import-modal">` definition was inadvertently placed before closing the preceding `<x-ui.modal>` (such as `course-delete-modal`, `batch-delete-modal`, `section-delete-modal`, `course-plan-delete-modal`, and `faculty-delete-modal`). Because the parent delete modal was closed by default (`x-show="$store.modals.isOpen(...) === false"`), the entire inner import modal DOM was hidden.
- **Resolution**:
  - Restored proper `</x-ui.modal>` boundaries prior to every import modal block.
  - Added instant zero-latency Alpine trigger handlers `x-data x-on:click="$store.modals.open('...-import-modal')"` to all import buttons alongside server-side Livewire state initialization.
  - Updated cancel/close buttons in all modals to use direct Alpine handlers `x-data x-on:click="$store.modals.close('...-import-modal')"` for instantaneous dismissals without server roundtrips.

### B. Partner Coaching Institute Academic Cohort Allotment (Batches, Courses & Sections)
- **Database Schema (`2026_11_01_044500_create_partner_institute_academic_assignments_tables.php`)**:
  - Created pivot tables:
    1. `partner_institute_batches` (`partner_institute_id`, `batch_id`, timestamps)
    2. `partner_institute_courses` (`partner_institute_id`, `course_id`, timestamps)
    3. `partner_institute_sections` (`partner_institute_id`, `section_id`, timestamps)
- **Eloquent Relationships**:
  - `PartnerInstitute`: Added `batches()`, `courses()`, `sections()` `BelongsToMany` relationships.
  - `Batch`: Added `partnerInstitutes()` `BelongsToMany` relationship.
  - `Course`: Added `partnerInstitutes()` `BelongsToMany` relationship.
  - `Section`: Added `partnerInstitutes()` `BelongsToMany` relationship.
- **Service Layer (`PartnerInstituteService`)**:
  - Enhanced `createInstitute()` and `updateInstitute()` to automatically validate and synchronize associated Batches, Courses, and Sections using atomic database transactions.
  - Updated spreadsheet export to include three dedicated columns: `Associated Batches`, `Associated Courses`, and `Associated Sections`.
  - Updated spreadsheet importer (`importFromSpreadsheet`) to parse comma-separated batch codes, course codes, and section codes (Columns O, P, Q).
  - Updated `generateSampleTemplate()` to include sample assignments for the new columns.
- **Interactive Livewire Component (`⚡partner-institutes.blade.php`)**:
  - **Component State**: Added `$assigned_batches`, `$assigned_courses`, and `$assigned_sections` arrays.
  - **Dynamic Filtering**: Added computed property `availableSectionsList` that reactively filters available sections based on the selected batches and courses.
  - **Create / Edit Modal UI**: Added a dedicated card titled **"Associated Academic Cohorts, Courses & Sections"** allowing multi-select checkboxes for batches, courses, and filtered sections.
  - **Table View**: Added an **"Academic Scope"** column displaying batch, course, and section badge counts per institute (or "All Batches & Courses" badge when unconstrained).
  - **View Dossier Modal**: Added comprehensive academic breakdown displaying linked batch tags, course badges, and section badges with parent cohort details.
- **REST API (`PartnerInstituteApiController`)**:
  - `index()`: Eager loads `batches:id,name,code`, `courses:id,name,code`, `sections:id,name,code` with relation counts.
  - `show()`: Delivers full hierarchy of linked batches, courses, sections, and faculties.
  - `store()` & `update()`: Validates and persists `batches`, `courses`, and `sections` array inputs.
- **Automated Verification**:
  - Added comprehensive feature tests in `tests/Feature/AcademicImportAndEnhancementTest.php` verifying service synchronization, Livewire form state persistence, REST API responses, and classroom import modal workflows.
  - Full test suite passing (38 tests, 212 assertions).
  - Cleaned and verified code standards with `vendor/bin/pint --dirty --format agent`.
