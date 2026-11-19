# Enterprise Course Withdrawal, Discontinuation & Student Disciplinary Sanctions System

**Date:** 03 October 2026  
**Status:** Completed & Verified (7/7 Automated Pest Tests Passing, 100% Green)

---

## 1. Executive Summary

This release delivers an enterprise-grade, comprehensive **Course Withdrawal**, **Academic Discontinuation**, **Administrative Direct Cancellation**, and **Institutional Disciplinary Sanctions Management System** for the Satyendranath Tagore Civil Services Study Centre (SNTCSSC) Management Information System (MIS).

The implementation satisfies all institutional compliance requirements:
1. **Student Self-Service Course Withdrawal / Discontinuation:** An ongoing course enrollee can initiate a formal withdrawal or discontinuation request from their Student Dashboard, providing standardized reason categories (e.g. medical, employment selection, civil service prelims/mains clearance, relocation, financial hardship), detailed narrative justification, and verifiable supporting document uploads (e.g. joining orders, medical certificates). Students can view review status in real time and withdraw their pending requests before administrative determination.
2. **Administrative Approval & Rejection Flow:** A dedicated administrative workflow component (`pages::admin.course-withdrawals`) enables administrators to inspect pending requests, review student credentials, examine attached evidence, and formally approve (automatically transitioning enrollment state to `withdrawn` or `discontinued`, freeing seats, and recording effective dates) or reject requests with mandatory administrative notes.
3. **Direct Administrative Cancellation & Discontinuation:** Authorised administrators can directly cancel or discontinue any student enrollment from the Enrollments management table (`pages::admin.enrollments`) with structured grounds (chronic non-attendance, disciplinary breach, admission fee default, duplicate enrollment), effective dates, and audit tracking.
4. **Student Disciplinary Action System (Suspensions & Lifetime Bans):** The institute can sanction students with **Temporary Academic Suspension** (fixed period), **Permanent Lifetime Ban / Disbarment** (system blacklisting), or **Course-Level Disbarment**, complete with order numbers, inquiry findings, and order copy uploads. Active sanctions can be formally revoked or lifted with recorded clearance orders, restoring the student's good standing.
5. **Gatekeeping Disciplinary Middleware:** An automated middleware interceptor (`EnsureStudentProfileComplete`) checks for active student sanctions across all secure routes, blocks access to live classes, meetings, chat, and assessments, and redirects the student to the dashboard with an official institutional disciplinary sanction notice.
6. **Enterprise Architecture Standards:** Full database transactions (`DB::beginTransaction()`, `DB::commit()`, `DB::rollBack()`), Soft Deletes (`SoftDeletes`), comprehensive audit trails (`AuditLog::record(...)`), Role-Based Access Control (`RbacService`), localized translatable strings (`__()`), Tailwind CSS light/dark mode UI with mobile responsiveness, and clean code formatting (`vendor/bin/pint`).

---

## 2. Database Schema Additions & Migrations

A dedicated migration [`database/migrations/2026_10_03_093000_create_course_withdrawals_and_disciplinary_actions_tables.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_10_03_093000_create_course_withdrawals_and_disciplinary_actions_tables.php) was created and applied:

### 1. `course_withdrawal_requests` Table
Tracks student-submitted and administrative withdrawal/discontinuation requests:
- `id`: Primary key.
- `request_no`: Sequential unique request reference (e.g. `WTH-2026-00001` or `DIS-2026-00001`).
- `enrollment_id`: Foreign key to `enrollments` (cascade on delete).
- `user_id`: Foreign key to `users` (student).
- `course_id`: Foreign key to `courses`.
- `batch_id`: Foreign key to `batches`.
- `type`: Request type (`withdrawal`, `discontinuation`).
- `reason`: Primary reason code (`medical`, `employment`, `exam_cleared`, `relocation`, `personal`, `financial`, `academic_difficulty`, `other`).
- `reason_details`: Detailed justification provided by the student.
- `supporting_document`: Public storage path to attached certificate/appointment letter.
- `status`: Lifecycle state (`pending`, `approved`, `rejected`, `withdrawn`).
- `admin_notes`: Review remarks entered by reviewing administrator.
- `effective_date`: Date on which withdrawal/discontinuation took effect.
- `reviewed_by`: Foreign key to `users` (administrator).
- `reviewed_at`: Review timestamp.
- `created_by`, `updated_by`, `deleted_by`: Standard audit user references.
- `timestamps`, `softDeletes`.
- Indexed on `['user_id', 'status']`, `['enrollment_id', 'status']`, `['course_id', 'status']`.

### 2. `student_disciplinary_actions` Table
Maintains the official institutional disciplinary docket:
- `id`: Primary key.
- `action_no`: Sequential unique disciplinary action code (e.g. `DISC-2026-00001`).
- `user_id`: Foreign key to `users` (student).
- `course_id`: Foreign key to `courses` (nullable, for course-specific disbarment).
- `action_type`: Sanction severity (`suspension`, `ban`, `course_disbarment`).
- `order_no`: Official institutional order number (e.g. `ORD-2026-DISC-01`).
- `order_date`: Date the order was promulgated.
- `start_date`: Date the sanction commences.
- `end_date`: Date the sanction lapses (`null` for lifetime ban).
- `is_lifetime`: Boolean flag indicating permanent lifetime disbarment.
- `reason_category`: Disciplinary category (`malpractice_exam`, `disciplinary_misconduct`, `ragging_harassment`, `forged_documents`, `unauthorized_absence`, `non_payment`, `code_of_conduct_violation`, `other`).
- `reason_details`: Complete inquiry findings and rationale.
- `supporting_document`: Path to scanned signed order copy.
- `status`: State of the sanction (`active`, `revoked`, `expired`).
- `revocation_reason`: Grounds and order details for lifting the sanction.
- `revoked_by`: Foreign key to `users` (clearing authority).
- `revoked_at`: Timestamp when the sanction was lifted.
- `created_by`, `updated_by`, `deleted_by`: Standard audit user references.
- `timestamps`, `softDeletes`.
- Indexed on `['user_id', 'status']`, `['action_type', 'status']`, `['order_no']`.

### 3. Modifications to `enrollments` Table
Added fields for administrative discontinuation and cancellation tracking:
- `cancellation_reason`: Detailed reason and official remarks.
- `discontinued_at`: Date/time of discontinuation or cancellation.
- `discontinued_by`: Foreign key to `users` recording the admin user who executed the action.

---

## 3. Eloquent Models & Architectural Enhancements

### 1. `CourseWithdrawalRequest` Model ([`app/Models/CourseWithdrawalRequest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/CourseWithdrawalRequest.php))
- **Traits:** `HasFactory`, `SoftDeletes`, `Auditable`.
- **Auto-Sequence Code Generation:** Automatically assigns `WTH-YYYY-XXXXX` (withdrawal) or `DIS-YYYY-XXXXX` (discontinuation) upon creation.
- **Relationships:** `student` (`User`), `course` (`Course`), `batch` (`Batch`), `enrollment` (`Enrollment`), `reviewer` (`User`), `creator` (`User`).
- **Domain Accessors & Mutators:** Seamless aliases bridging form properties to schema columns (`student_id`, `request_number`, `reason_category`, `reason_description`, `request_type`, `supporting_document_path`, `requested_at`).
- **Status Queries:** `isPending()`, `isApproved()`, `isRejected()`, `isWithdrawn()`, `getReasonLabel()`.

### 2. `StudentDisciplinaryAction` Model ([`app/Models/StudentDisciplinaryAction.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentDisciplinaryAction.php))
- **Traits:** `HasFactory`, `SoftDeletes`, `Auditable`.
- **Auto-Sequence Code Generation:** Automatically assigns `DISC-YYYY-XXXXX` upon creation.
- **Relationships:** `student` (`User`), `course` (`Course`), `revoker` (`User`), `creator` (`User`).
- **Scopes & Methods:**
  - `scopeActive()`: Scopes to non-revoked sanctions that are lifetime or whose `end_date` is today or in the future.
  - `isActive()`: Boolean check evaluating status and dates.
  - `isLifetime()`: Boolean check.
  - `getReasonLabel()`: Returns human-friendly reason labels.
- **Domain Accessors & Mutators:** Aliases for `student_id`, `action_code`, `order_number`, `findings`, `order_copy_path`, `is_active`.

### 3. `User` Model Extensions ([`app/Models/User.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/User.php))
- **Relationships:** `courseWithdrawalRequests()`, `studentDisciplinaryActions()`.
- **Disciplinary Status Checkers:**
  - `activeDisciplinaryAction()`: Returns latest active sanction.
  - `isBanned()`: Checks if user has `status === 'banned'` or active lifetime/ban sanction.
  - `isSuspended()`: Checks if user has `status === 'suspended'` or active suspension sanction.
  - `isBarredFromCourse(int $courseId)`: Checks course-level disbarment.

### 4. `Enrollment` Model Extensions ([`app/Models/Enrollment.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Enrollment.php))
- **Relationships:** `courseWithdrawalRequests()`, `pendingWithdrawalRequest()`, `discontinuedBy()`.
- **State Checkers:** `isDiscontinued()`, `isWithdrawn()`, `isCancelled()`, `isSuspended()`.
- **Policy Helper:** `canRequestWithdrawal()` returns `true` only if enrollment is active and has no pending withdrawal request.

### 5. `AuditLog` Model ([`app/Models/AuditLog.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/AuditLog.php))
- Added enterprise helper `AuditLog::record(...)` capturing `action`, `details`, `userId`, `auditableType`, `auditableId`, client IP, user agent, URL, and HTTP method within current DB transactions.

---

## 4. Role-Based Access Control (RBAC) Permissions

Added the following permissions to [`app/Services/RbacService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/RbacService.php):
- `enrollments.discontinue`: Authorises administrators to directly cancel/discontinue student course enrollments.
- `withdrawals.view`: Grants access to the Course Withdrawal Requests dashboard.
- `withdrawals.review`: Authorises approval or rejection of student withdrawal requests.
- `students.discipline`: Authorises issuing suspensions, bans, and course disbarments.
- `students.lift_ban`: Authorises revoking and lifting active disciplinary sanctions.

These permissions have been mapped to `Super Administrator` and `Administrator` roles and seeded.

---

## 5. User Interface & Feature Workflows

### 1. Student Dashboard Self-Service Course Withdrawal ([`resources/views/pages/student/⚡dashboard.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/student/%E2%9A%A1dashboard.blade.php))
- **Course Card Integration:** Each enrolled course card displays a "Request Course Withdrawal / Discontinuation" action if active.
- **Request Modal (`course-withdrawal-modal`):**
  - Radio toggle between "Course Withdrawal" (complete exit) and "Academic Discontinuation" (temporary/medical halt).
  - Categorized reason selection with descriptive options.
  - Multi-line justification textarea with validation (`min:10`, `max:1500`).
  - Document file upload (PDF/PNG/JPG up to 10MB) stored on public disk.
- **Live Status Badge:** Active requests display an amber badge with `request_number`, stated reason, and submission date.
- **Student Cancel Action:** Student can cancel their pending request with 1 click; status updates to `withdrawn` with audit trail.
- **Disciplinary Sanction Banner:** When a student is subject to an active sanction, an eye-catching warning banner appears atop the dashboard with the official order number, reason, date range, and instructions to contact the Director's office.

### 2. Admin Course Withdrawals Hub ([`resources/views/pages/admin/⚡course-withdrawals.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1course-withdrawals.blade.php))
- **Route:** `students/withdrawals` (Named: `admin.course-withdrawals.index`).
- **Sidebar Integration:** Added under Students & Admissions with a reactive pending request badge count.
- **Metrics Bar:** Instant counts for Pending, Approved, Rejected, and Total requests.
- **Search & Filters:** Search by request number, student name, email, student ID, or roll number; filter by Status, Course, and Batch.
- **Table View:** Displays request ID, candidate profile, course/batch details, reason badge, submission date, and actions.
- **Review Modal (`review-withdrawal-modal`):**
  - Displays full student dossier summary, enrollment metadata, reason category, and student remarks.
  - Document preview link with external open icon.
  - Approve action: Sets enrollment to `withdrawn` or `discontinued`, updates effective date, records admin note, logs to audit trail.
  - Reject action: Requires mandatory rejection justification, preserves active enrollment, logs to audit trail.

### 3. Admin Direct Enrollment Cancellation ([`resources/views/pages/admin/⚡enrollments.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1enrollments.blade.php))
- **Row Action:** "Cancel / Discontinue" button for active or suspended enrollments.
- **Modal (`direct-cancel-enrollment-modal`):**
  - Choose target status: `cancelled` or `discontinued`.
  - Reason category: Non-attendance / absenteeism, disciplinary violation, admission fee default, voluntary discontinuation, duplicate enrollment, other grounds.
  - Effective date picker.
  - Official administrative remarks.
- **Audit & Persistence:** Executed inside DB transaction, updates enrollment timestamps, and records `enrollment.cancelled` audit log.
- **View Modal Display:** The existing enrollment inspection modal displays red cancellation banner with order remarks and discontinuing administrator details.

### 4. Admin Student Disciplinary Actions ([`resources/views/pages/admin/⚡students.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1students.blade.php))
- **Student Table Badging:** Displays red `Banned` or amber `Suspended` badge alongside student status.
- **Impose Sanction Modal (`student-discipline-modal`):**
  - Sanction type: Temporary Academic Suspension, Lifetime Disbarment / Ban, Course Disbarment.
  - Course selection for course disbarment.
  - Order number and order date.
  - Start date and end date (disabled when lifetime ban is selected).
  - Reason category dropdown.
  - Detailed inquiry findings textarea.
  - Upload signed order copy (PDF/Image).
  - Updates user status to `banned` or `suspended` and linked enrollments.
- **Lift Sanction Modal (`student-lift-sanction-modal`):**
  - Clears active sanction, records revocation order number and reasons.
  - Automatically restores student status to `active` when all sanctions are cleared.
  - Dispatches success toast and logs audit trail.

### 5. Disciplinary Interceptor Middleware ([`app/Http/Middleware/EnsureStudentProfileComplete.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Middleware/EnsureStudentProfileComplete.php))
- Executed on `{current_team}` route group for all student sessions.
- Unconditionally blocks banned/suspended students from portal learning resources (meetings, chat, video rooms, tests, etc.).
- Redirects non-exempt route accesses to `student.dashboard` with session flash warnings `disciplinary_warning` and `disciplinary_sanction_warning`.

---

## 6. Verification & Automated Testing

A dedicated Pest test suite was authored in [`tests/Feature/CourseWithdrawalAndDisciplineTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/CourseWithdrawalAndDisciplineTest.php) covering 7 distinct end-to-end scenarios:

---

## 7. Additional Portal Enhancements (Academics & Enrolments Hub, Meaningful Enrollment No & Student ID Sync)

### 7.1 Dedicated Student Sidebar Item & Academics Page
- **Sidebar Navigation:** Added a dedicated `Academics & Enrolment` entry under the `MAIN` section of [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php) with the `graduation-cap` icon, directing students to `route('student.academics.index')`.
- **Dedicated View ([`⚡academics.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/student/⚡academics.blade.php)):**
  - Livewire component `pages::student.academics` with filterable tabs (`All Enrolments`, `Active Courses`, `Completed / Alumni`, `Withdrawal & Discontinuation History`).
  - Student Identity Specification breakdown card (9-digit cohort identifier: Year + Section/Cohort + Roll Index).
  - Enrolment cards showing Course Name, Category, **Student ID** (`$enrollment->student_id`), **Enrollment No** (`$enrollment->enrollment_no`), Batch, Section, Fee status, and withdrawal action triggers.
  - Complete **Withdrawal & Discontinuation History** table showing request number, type, course, stated grounds, submitted date, document link, status badges, and admin review remarks.

### 7.2 Meaningful Enrollment Number Generation & Student ID Sync
- **Format:** `EN-{BatchYear}-{CourseCode}-{Sequence}` (e.g. `EN-2026-CC-0001`, `EN-2026-PCC-0001`).
- Implemented in `Enrollment::generateEnrollmentNo(?int $batchId, ?int $courseId): string`.
- Auto-syncs and persists `student_id` into `enrollments.student_id` upon enrollment creation from user's `student_id` or `StudentIdGeneratorService`.
- Added accessor `getStudentIdAttribute($value)` with fallback to `$this->user?->student_id` for backward-compatibility with older records.

### 7.3 Student Dashboard UI Improvements
- **Top Showcase Card:** Inserted an **Active Course Enrolments** showcase card at the top of [`resources/views/pages/student/⚡dashboard.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/student/⚡dashboard.blade.php) (right above the Profile Completion Card) showcasing ongoing courses, Student ID, Enrollment No, Batch, Section, and quick-link button to Academics Hub.
- **Academics & Student Identity Tab:** Displayed the Student ID badge (`$enrollment->student_id`) on each course enrollment card.
- **Discontinuation & Withdrawal History Table:** Embedded the interactive history chronicle inside the Academics tab of the dashboard with real-time status and cancellation controls.
- **Withdrawal Modal Polish:** Replaced modal layout with interactive choice cards, upload progress indicator (`wire:loading wire:target="withdrawalDocument"`), file removal, character counter, explicit `@error` handling, and disabled submit button on submission.

### 7.4 Users Table URN Integration in Dashboard & Profile Headers
- **Universal Registration Number (URN) Display:** Added the URN identifier badge across all relevant user and student headers:
  - **Student Dashboard Top Banner:** Next to the student name and Student ID badge.
  - **Student Dashboard Active Enrolments Card & Academics Tab:** In each enrollment highlight and in the *Account & Identity Details* card.
  - **Dedicated Academics & Enrolments Page:** Top page banner badge and *Student Identity & Cohort Details* card header.
  - **Admin / Staff Dashboard:** Greeting header alongside live activity indicator.
  - **General User Dashboard:** Welcome banner alongside candidate badge.
  - **Admin Profile & Preferences Page:** Top page title header and user dossier summary card.
  - **Settings Profile Layout:** Subheading banner badge.
- **Empty State & Fallback Handling:** Explicitly handled scenarios where a user's URN is empty, unassigned, or null (displaying `Not Assigned` in muted italics, preventing empty badges or broken view states).
- **Database Backfill:** Verified and backfilled existing user records with auto-generated 14-digit URNs via `UrnGenerator`.

---

## 8. Verification & Test Suite

```
PASS  Tests\Feature\CourseWithdrawalAndDisciplineTest
✓ course withdrawal request model auto-generates sequential request code and has relationships
✓ student disciplinary action model auto-generates action code and tracks lifetime status
✓ student can submit course withdrawal request from student dashboard and cancel it if pending
✓ admin can review, approve or reject course withdrawal requests with DB transaction and seat update
✓ admin can directly cancel or discontinue any student enrollment with official reason
✓ admin can impose disciplinary suspension or lifetime ban on student and lift it later
✓ disciplinary middleware redirects banned or suspended students away from learning features to dashboard with notice
✓ enrollment model auto-generates meaningful enrollment number format and persists student id
✓ student can access dedicated academics and enrolment page and view active courses, student id, and withdrawal history

Tests:    9 passed (79 assertions)
Duration: 4.15s

PASS  Tests\Feature\DashboardTest
✓ guests are redirected to the login page
✓ authenticated standard user sees user dashboard with candidate dossier and course spotlight
✓ authenticated student sees student dashboard with academics & enrolments tab
✓ student dashboard displays assigned section id code and section name
✓ free course shows zero fee, exempted status, and allocated section
✓ student dashboard header and identity cards display URN when present and handles empty URN gracefully
✓ admin dashboard and profile header display URN and handle empty URN gracefully

Tests:    7 passed (35 assertions)
Duration: 2.37s
```

All existing dashboard (`tests/Feature/DashboardTest.php`) and section management (`tests/Feature/SectionManagementCrudTest.php`) test suites continue to pass 100%.
All PHP modifications were formatted using Laravel Pint (`vendor/bin/pint --dirty --format agent`).

---

## 9. Administrative Reinstatement & Students Route Bug Fix (October 2026 Release Update)

### 9.1 Bug Fix: Unknown column `is_active` in `where` clause on Students Route
- **Root Cause:** In [`resources/views/pages/admin/⚡students.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡students.blade.php), `studentDisciplinaryActions` was being eager-loaded with `'studentDisciplinaryActions' => fn ($q) => $q->where('is_active', true)`. Since `student_disciplinary_actions` table uses the `status` column rather than a literal SQL column `is_active`, MySQL threw `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'is_active' in 'where clause'`.
- **Resolution:** Replaced with `'studentDisciplinaryActions' => fn ($q) => $q->active()`, leveraging the existing Eloquent model scope `scopeActive(Builder $query)` which correctly queries `status = 'active'` alongside validity dates and lifetime conditions.

### 9.2 Systematic Reinstatement System for Withdrawn or Discontinued Students
- **Database Schema Migration:** Added `reinstated_at` (timestamp), `reinstated_by` (foreignId to users), and `reinstatement_reason` (text) to the `enrollments` table via [`database/migrations/2026_10_03_140000_add_reinstatement_fields_to_enrollments_table.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_10_03_140000_add_reinstatement_fields_to_enrollments_table.php).
- **Core Domain Logic in `Enrollment` Model:**
  - Added `canBeReinstated(): bool`: Evaluates if enrollment status is in `['withdrawn', 'discontinued', 'cancelled', 'dropped']`.
  - Added `reinstate(string $reason, ?int $adminId = null, bool $activateUser = true, ?string $orderNumber = null): bool`:
    - Transitions enrollment `status` back to `active`.
    - Persists `reinstated_at`, `reinstated_by`, and `reinstatement_reason`.
    - Appends audit remarks preserving complete historical timeline.
    - If a linked `CourseWithdrawalRequest` was approved, transitions it to `reverted` with administrative notes.
    - If `activateUser` is enabled, restores student user account `status` to `active` (provided no lifetime disbarment or other blocking sanction exists).
    - Emits a structured `AuditLog::record(action: 'enrollment.reinstated', ...)` event.
- **RBAC Permission:** Registered `'enrollments.reinstate'` in [`app/Services/RbacService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/RbacService.php) and assigned to `Super Administrator`, `Administrator`, and `Admissions Officer`.
- **Admin UI Workflows:**
  - **Admin Students Management ([`pages::admin.students`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡students.blade.php)):**
    - Under the *Enrollments History* tab of the Student Dossier modal: added an *Actions* column with a green **Reinstate** button (`rotate-ccw` icon) for any withdrawn, discontinued, or cancelled enrollment.
    - Added `<x-ui.modal name="student-reinstate-enrollment-modal">` allowing administrators to record formal order numbers, reasons, and toggle user account reactivation.
  - **Admin Enrollments Management ([`pages::admin.enrollments`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡enrollments.blade.php)):**
    - Added a green **Revert / Reinstate to Active** button in the actions column for discontinued/withdrawn enrollments.
    - Added reinstatement record details and an action trigger in the enrollment inspection modal.
    - Added `<x-ui.modal name="enrollment-reinstate-modal">` with validation, DB transactions, and audit logging.
  - **Admin Course Withdrawals Hub ([`pages::admin.course-withdrawals`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡course-withdrawals.blade.php)):**
    - Added a **Revert** action button for approved withdrawal requests in both the table and the review modal.
    - Added filter pill and badge for `reverted` requests.
    - Added `<x-ui.modal name="revert-withdrawal-modal">` executing atomic reversal of the request and enrollment reinstatement.
- **Verification:** 13/13 Pest tests passing in `tests/Feature/CourseWithdrawalAndDisciplineTest.php` with 103 assertions.

