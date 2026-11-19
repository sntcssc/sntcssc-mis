# Enterprise Batch, Course, Section, Student Dossier & Lifetime Unique ID Management System

**Date:** 02 October 2026  
**Status:** Completed & Verified (15/15 Automated Pest Tests Passing)

---

## 1. Executive Summary

This release implements a production-grade, enterprise-ready **Batch Management**, **Course Management**, **Optional Section Allocation**, **Comprehensive Student Registry Dossier**, **Enrollment History**, and **Lifetime Unique Student ID Strategy** for the Satyendranath Tagore Civil Services Study Centre (SNTCSSC) Management Information System (MIS).

The system addresses the institute's specialized UPSC civil services examination coaching requirements:
- Multi-course annual batches (e.g. "2026 Batch" offering Composite Course [CC], Prelims Crash Course [PCC], Mains Guidance Programme [MGP], Interview Training Programme [ITP], Weekend Test Series [WTS]).
- 10-digit lifetime student unique IDs formatted dynamically by year, programme code, and optional section code (e.g. `202610001` for Section A, `202720001` for Section B, and configurable fallbacks when no sections are involved).
- Lifetime student ID preservation across multi-year and multi-course enrollments.
- Dynamic admission restriction engine preventing duplicate enrollments in restricted courses (e.g. one-time admission for Composite Course) with authorized administrator overrides.
- Multi-tabbed student profile dossier aligning with the One Time Registration (OTR) schema (Basic Details, Addresses, Educational Qualifications, Employment History, UPSC Exam Details, Uploaded Documents, and Complete Enrollment History).
- Secure Student Profile Correction Request workflow ensuring that candidate details cannot be modified without administrative inspection, side-by-side diff review, and approval.
- High-performance, full-text dynamic search, faceted filtering, column sorting, Excel (.xlsx) / CSV imports with validation, and multi-format exports (PDF, Excel, CSV).

---

## 2. Database Schema Additions & Migrations

Four database migrations were created and executed successfully:

### 1. `database/migrations/2026_10_02_100000_add_student_id_to_users_table.php`
- Adds `student_id VARCHAR(10) NULL UNIQUE AFTER urn` to `users` table.
- Indexed for instant lookups across online test portals, library, LMS, and the MIS.

### 2. `database/migrations/2026_10_02_110000_create_batches_courses_sections_enrollments_tables.php`
- **`batches`**:
  - `id`, `name` (e.g. "2026 Batch"), `code` (e.g. `BATCH-2026`), `academic_year` (e.g. `2026`), `start_date`, `end_date`, `status` (`upcoming`, `active`, `completed`, `archived`), `description`, `max_students`, `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.
- **`courses`**:
  - `id`, `name`, `code` (unique, e.g. `CC`, `PCC`, `MGP`, `ITP`), `category` (`Classroom`, `Crash Course`, `Guidance`, `Interview`, `Test Series`), `description`, `duration`, `fee` (decimal), `has_sections` (boolean), `identifier_code` (e.g. `1`, `3`, `4`), `admit_once_only` (boolean admission policy), `max_attempts` (nullable integer), `status`, `rules` (json), `timestamps`, `softDeletes`.
- **`batch_courses`**:
  - Many-to-many pivot between batches and courses with `capacity`, `fee`, `start_date`, `end_date`, `status`, `remarks`, `timestamps`, `softDeletes`.
- **`sections`**:
  - `id`, `batch_id`, `course_id`, `batch_course_id`, `name` (e.g. "Section A"), `code` (e.g. `1`), `capacity` (default 45), `room_number`, `timing`, `status`, `timestamps`, `softDeletes`.
- **`enrollments`**:
  - `id`, `enrollment_no` (unique, e.g. `ENR-2026-00001`), `user_id`, `batch_id`, `course_id`, `section_id` (nullable), `roll_no`, `enrolled_at`, `completed_at`, `status` (`active`, `completed`, `dropped`, `suspended`, `alumni`), `fee_amount`, `fee_status` (`pending`, `partial`, `paid`, `exempted`), `remarks`, `timestamps`, `softDeletes`.

### 3. `database/migrations/2026_10_02_120000_create_student_profile_dossier_tables.php`
Implements the full dossier structure matching the institutional OTR standard:
- **`student_profiles`**: Personal details, father/mother name, category (`UR`, `SC`, `ST`, `OBCA`, `OBCB`, `EWS`), verification status, subcategory, subcaste, certificate number, PwBD benchmark disability, family income, highest qualification, school language, hobbies, activities.
- **`student_addresses`**: Permanent and correspondence address records with state, district, subdistrict, address lines, post office, police station, and PIN code.
- **`student_educations`**: Academic qualification history (10th, 12th, Graduation, Post-Graduation) with board/university, institute, stream, passing year, total marks, percentage, CGPA, and division.
- **`student_employments`**: Employment history with employer, designation, nature of employment, location, dates, and current status.
- **`student_other_details`**: UPSC exam-specific metadata (attempts already given, attempts left, optional subject, year of appearing).
- **`student_documents`**: Uploaded verification documents (caste certificate, 10th certificate, photo, signature, ID proof) with verification timestamps and audit tracking.

### 4. `database/migrations/2026_10_02_130000_create_profile_update_requests_table.php`
- **`profile_update_requests`**:
  - `id`, `request_no` (unique, e.g. `REQ-2026-00001`), `user_id`, `section` (`basic`, `address`, `education`), `payload` (JSON of requested changes), `current_data` (JSON snapshot of original values), `changes` (JSON diff), `reason` (student justification), `status` (`pending`, `approved`, `rejected`), `admin_notes`, `reviewed_by`, `reviewed_at`, `timestamps`, `softDeletes`.

---

## 3. Core Models & Architectural Services

### 1. Eloquent Models
- [`App\Models\Batch`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Batch.php): Course relationship via pivot, sections relationship, active enrollment count scopes, auditable trail, and soft deletes.
- [`App\Models\Course`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Course.php): Batches relationship, sections relationship, admit-once-only and section configuration attributes.
- [`App\Models\BatchCourse`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/BatchCourse.php): Pivot model with overrides for fees, dates, and capacities.
- [`App\Models\Section`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Section.php): Section code identifier, classroom capacity tracking, and occupancy metrics.
- [`App\Models\Enrollment`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Enrollment.php): Automatic sequential enrollment number formatting (`ENR-YYYY-00001`), fee management, and user/course/batch linking.
- [`App\Models\StudentProfile`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentProfile.php), [`StudentAddress`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentAddress.php), [`StudentEducation`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentEducation.php), [`StudentEmployment`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentEmployment.php), [`StudentOtherDetail`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentOtherDetail.php), [`StudentDocument`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentDocument.php): Complete student profile dossier entities.
- [`App\Models\ProfileUpdateRequest`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ProfileUpdateRequest.php): Pending/approved/rejected workflow with diff payloads.
- [`App\Models\User`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/User.php): Added `student_id` to fillable attributes and relationships to enrollments, student profile, addresses, educations, documents, and profile update requests.

### 2. Services
- [`App\Services\StudentIdGeneratorService`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/StudentIdGeneratorService.php):
  - Generates 9-to-10 digit unique IDs formatted as `[Year][Identifier][Sequence]`.
  - For Composite Course Section A: `202610001`.
  - For Composite Course Section B: `202720001`.
  - Handles courses without sections via configured identifier (e.g. PCC: 3, MGP: 4, ITP: 5, WTS: 6) or fallback `0`.
  - Guarantees **Permanent Lifetime Retention**: If a user already possesses a `student_id`, it is never overwritten or mutated on subsequent enrollments.
- [`App\Services\AdmissionRestrictionService`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/AdmissionRestrictionService.php):
  - Validates `admit_once_only` policy: blocks duplicate candidate registration in restricted programmes.
  - Prevents duplicate active enrollment in the same course under the same batch.
  - Validates classroom section capacity.
  - Supports authorized administrative override with justification and audit trail.
- [`App\Services\StudentImportExportService`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/StudentImportExportService.php):
  - Generates styled institute PDF reports using `barryvdh/laravel-dompdf`.
  - Generates auto-sized, styled Excel (.xlsx) workbooks using `phpoffice/phpspreadsheet`.
  - Generates RFC 4180 CSV with UTF-8 BOM.
  - Implements bulk import parsers for Students and Enrollments with downloadable CSV and Excel sample templates.
- [`App\Services\RbacService`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/RbacService.php):
  - Added permissions: `batches.view`, `batches.manage`, `batches.delete`, `batches.export`, `courses.view`, `courses.manage`, `courses.delete`, `courses.export`, `sections.manage`, `enrollments.view`, `enrollments.manage`, `enrollments.delete`, `enrollments.export`, `enrollments.import`, `profile_requests.view`, `profile_requests.review`.

---

## 4. User Interface & Livewire Components

All components are built using Flux UI components, Tailwind CSS, and support light & dark modes with full responsiveness:

1. **Batches Management** ([`resources/views/pages/admin/⚡batches.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1batches.blade.php)):
   - Complete CRUD operations with database persistence.
   - Batch intake calendar, start and end dates, multi-course attachments, and optional section management.
   - Real-time search, status filtering (`active`, `upcoming`, `completed`, `archived`), academic year filtering, and column sorting.
   - Full export (PDF, Excel, CSV).

2. **Courses Management** ([`resources/views/pages/admin/⚡courses.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1courses.blade.php)):
   - Complete CRUD operations with category grouping, duration, and fee schedules.
   - Section assignment toggles and Student ID identifier code mapping.
   - Admission restriction policies (`admit_once_only` toggle, max attempts).
   - Real-time search, category filtering, section flag filtering, and multi-format exports.

3. **Students Management** ([`resources/views/pages/admin/⚡students.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1students.blade.php)):
   - Complete student registry with 10-digit lifetime student IDs, URNs, and contact information.
   - Detailed tabbed Dossier Modal: Basic Details, Addresses, Educational Qualifications, Employment History, UPSC Details, Uploaded Documents with verification toggles, and Complete Chronological Enrollment History.
   - Quick Course Enrollment modal shortcut.
   - Bulk Excel/CSV student import with downloadable templates.
   - Multi-format exports (PDF, Excel, CSV).

4. **Course Enrollments Management** ([`resources/views/pages/admin/⚡enrollments.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1enrollments.blade.php)):
   - Complete admission workflow with student selection, batch selection, course selection, and optional section assignment.
   - Real-time enforcement of admission restrictions (admit-once-only and duplicate batch checks).
   - Automated Student Unique ID generation upon first enrollment.
   - Fee tracking (`paid`, `partial`, `pending`, `exempted`) and completion date logging.
   - Bulk Excel/CSV enrollment import with downloadable templates.
   - Multi-format exports (PDF, Excel, CSV).

5. **Profile Correction Requests** ([`resources/views/pages/admin/⚡profile-requests.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1profile-requests.blade.php)):
   - Administrative review queue for student-submitted correction requests.
   - Side-by-side diff inspector highlighting original values vs requested changes.
   - Approval action: Merges approved changes into candidate records inside a DB transaction with audit logging.
   - Rejection action: Records administrator reason and declines modification.
   - Self-service student profile interception in [`⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1profile.blade.php) preventing direct updates without review.

6. **Navigation & Routes**:
   - Registered `admin.profile-requests.index` route in `routes/web.php`.
   - Updated sidebar navigation with real-time pending request count badges.

---

## 5. Verification & Testing

- **15 Automated Pest Tests Passing (100% Pass Rate)**:
  - `BatchCourseEnrollmentStudentTest.php`: 10 tests, 44 assertions.
  - `BatchCourseLivewireTest.php`: 5 tests, 22 assertions.
  - `AdminPagesTest.php`: 19 tests, 22 assertions.
- Code formatted with **Laravel Pint** according to PSR-12 and project style guides.
