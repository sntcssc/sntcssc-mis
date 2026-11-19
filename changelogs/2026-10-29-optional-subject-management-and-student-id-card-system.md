# Changelog: Optional Subject Management, Change Request Workflow, and Official Student ID Card System

**Date:** 2026-10-29  
**Author:** SNTCSSC Development Team  
**Category:** Academic Management, UPSC Optional Subjects, Student Self-Service Portal, Identity Credentials & Card Generation, RBAC & Audit Trails  
**Status:** Completed & Production Ready  

---

## 1. Executive Summary

In premier Civil Services preparatory academies such as the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC)**, managing candidates' chosen **UPSC CSE Optional Subjects** is critical for scheduling specialized lecture series, arranging answer writing evaluations, assigning academic mentors, and generating cohort demographics. Candidates frequently adjust their optional subject choices during the foundational stages of preparation following introductory orientation sessions and mentoring consultations.

Simultaneously, institution-wide physical security, classroom access, test-hall verification, and library admittance require a robust, standardized **Student Identity Card System**.

This release introduces an end-to-end, enterprise-grade suite encompassing:
1. **Optional Subject Management & Reports:**
   - Real-time student allocations dashboard with cohort filtering (Batch, Course, Section, Status).
   - In-place **Quick Update** modal dialog allowing administrators and authorized academic officers to update any student's optional subject with reason tracking and full audit trails.
   - Master **UPSC Optional Subject Catalog** CRUD pre-seeded with all 48 official UPSC subjects (Humanities, Sciences, Engineering, Medical, and Indian Literatures).
   - **Student Change Request Workflow:** Enrolled students can inspect their assigned optional subject in their portal dashboard and academics view, submit a formal change request with justifications, and track request status.
   - **Administrative Approval / Rejection Queue:** Staff review requests, review justifications, approve (which automatically synchronizes `student_other_details.optional_subject` and issues audit events) or reject with mandatory remarks.
   - **Batch / Course / Section Analytics & Reports:** Dynamic cohort-wise subject popularity distribution, progress charts, and one-click data export (PDF, Excel, CSV) & spreadsheet import with downloadable template.
2. **Student Identity Card System:**
   - High-fidelity, double-sided official printable ID Card PDF with Code 128 barcode, dynamic QR verification code, institutional branding, cut marks, student photo, emergency contact, blood group, validity session, and terms & conditions.
   - Centralized **Admin Student ID Card Settings** page with live card preview, custom validity period, institutional headings, theme colors, field visibility toggles, and an instant administrative kill-switch / toggle to **enable or disable student download access**.
   - Student portal self-service digital ID card viewer and conditional PDF download.
   - Administrative ID card registry with search, cohort filtering, single card instant PDF download, and bulk consolidated PDF download.
3. **RESTful API Services:**
   - Dedicated API endpoints for mobile applications, third-party LMS integrations, and student verification scanners under `/api/v1`.
4. **Enterprise Standards:**
   - Strict RBAC permissions, Spatie permissions integration, soft deletes, database transactions, full audit trail logging via `AuditLogService`, multi-language localization (`en`, `bn`, `hi`), dark/light mode responsive design, and automated Pest 5 test coverage.

---

## 2. Architecture & Database Design

### 2.1 Database Schema Additions

#### 1. `optional_subjects` (Master Curriculum Catalog)
```sql
CREATE TABLE optional_subjects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    category VARCHAR(100) NOT NULL,
    description TEXT NULL,
    max_capacity INT UNSIGNED NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    deleted_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX (category),
    INDEX (is_active)
);
```

#### 2. `optional_subject_change_requests` (Student Change Requests Lifecycle)
```sql
CREATE TABLE optional_subject_change_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(50) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    current_subject VARCHAR(255) NULL,
    requested_subject VARCHAR(255) NOT NULL,
    optional_subject_id BIGINT UNSIGNED NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    admin_remarks TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    deleted_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX (status),
    INDEX (user_id)
);
```

#### 3. `student_other_details` Table Integration
- Utilizes the existing `student_other_details.optional_subject` column as the authoritative source of truth for the student's active optional subject across the entire MIS (admissions, academics, examinations, and identity cards).

#### 4. System Settings Keys (`student_id_card`)
- `student_id_card.enable_student_download` (Boolean, default: `true`): Controls whether students are permitted to download their PDF ID card directly from the student dashboard.
- `student_id_card.card_title` (String, default: `STUDENT IDENTITY CARD`): Header title printed on the card.
- `student_id_card.institution_name` (String): Institutional title.
- `student_id_card.institution_sub` (String): Sub-heading (e.g. `Government of West Bengal`).
- `student_id_card.validity_period` (String): Formatted academic session range (e.g. `Academic Session 2026-2027`).
- `student_id_card.authorized_signatory_title` (String): Designation text above signature line.
- `student_id_card.show_blood_group` (Boolean, default: `true`)
- `student_id_card.show_emergency_contact` (Boolean, default: `true`)
- `student_id_card.show_barcode` (Boolean, default: `true`)
- `student_id_card.show_qr_code` (Boolean, default: `true`)
- `student_id_card.show_optional_subject` (Boolean, default: `true`)
- `student_id_card.terms_and_conditions` (Text): Institutional terms and rules printed on card back.
- `student_id_card.theme_color` (String, default: `#1e3a8a`): Institutional header color.

---

## 3. Optional Subject Management Features

### 3.1 Tab 1: Allocations & Quick Assignment
- **Real-Time Student Cohort Filtering:**
  - Search by Name, Student ID, URN, Email, Phone.
  - Scoping by Course, Batch, Section, and Assignment Status (All, Assigned, Unassigned).
- **Inline / Quick Update Modal Dialog:**
  - One-click "Quick Update" button on any student row.
  - Interactive modal dialog featuring current subject, searchable dropdown of active optional subjects, and an optional administrative note/reason.
  - Instant background submission without page reload.
  - Atomic database transaction updating `student_other_details.optional_subject` and creating an audit trail log via `AuditLogService`.
- **Bulk Spreadsheet Import & Export:**
  - Modal with downloadable CSV/Excel template with column headers: `student_id`, `email`, `optional_subject`.
  - Validates student existence and ensures subject conforms to curriculum catalog.
  - 1-click export of current filtered allocation list to CSV, Excel (`.xlsx`), or PDF.

### 3.2 Tab 2: Change Requests Approval Queue
- **Sequential Identifier:** Generates unique tracking numbers (e.g., `OSCR-2026-0001`).
- **Status Filter:** Pending (with active badge count), Approved, Rejected, All.
- **Interactive Review Modals:**
  - **Approve Modal:** Displays student info, current subject, requested subject, justification, and allows optional approval remarks. On confirmation, marks request `approved`, assigns `reviewed_by` and `reviewed_at`, synchronizes `student_other_details.optional_subject`, and writes an audit event.
  - **Reject Modal:** Requires mandatory administrative reason explaining why the change cannot be permitted. Keeps the student's original subject intact.

### 3.3 Tab 3: Master Subject Catalog (CRUD)
- Pre-seeded with 48 official UPSC subjects categorized into:
  - *Humanities & Social Sciences* (History, Geography, Political Science, Public Administration, Sociology, Economics, Philosophy, Psychology, Anthropology).
  - *Sciences & Mathematics* (Physics, Chemistry, Mathematics, Statistics, Geology).
  - *Biological & Agricultural Sciences* (Botany, Zoology, Agriculture, Animal Husbandry & Veterinary Science).
  - *Engineering & Medical* (Civil Engineering, Electrical Engineering, Mechanical Engineering, Medical Science).
  - *Literature of Indian Languages* (Bengali, Hindi, Sanskrit, English, Urdu, Santhali, Maithili, Tamil, Telugu, etc.).
- Complete CRUD operations: Add new subject, edit subject, toggle active status, and soft delete with interactive confirmation modal.

### 3.4 Tab 4: Cohort Analytics & Reports Generation
- **Dynamic Scoping:** Scoped by Batch, Course, Section, and Subject.
- **Popularity Distribution:** Real-time percentage breakdowns, candidate headcounts, and visual progress bars showing subject demand across batches.
- **Exportable Reports:** Export comprehensive cohort-wise optional subject distribution reports to PDF, Excel, and CSV.

---

## 4. Student Portal Integration

### 4.1 Student Dashboard (`pages::student.dashboard`)
- **Overview Stat Card:** Displays the candidate's active optional subject directly on their dashboard overview with a badge indicating active status or pending change request.
- **UPSC CSE Details Tab (Tab 6):**
  - Displays the active optional subject card with an action button: **"Change Optional Subject"**.
  - If a change request is currently pending review, displays a prominent amber notification banner with the requested subject, submission date, and pending status.
  - Includes a full **Optional Subject Change History Table** tracking past requests, request numbers, submitted reasons, review remarks, and approval/rejection outcomes.
- **Interactive Request Modal Dialog:**
  - Allows candidate to select an active optional subject from the approved catalog.
  - Enforces minimum 10-character reason explaining the academic basis for the change.
  - Client-side and server-side validation preventing selection of the current subject and rejecting concurrent duplicate requests.

### 4.2 Student Academics View (`pages::student.academics`)
- Academic summary highlights the assigned optional subject alongside course, batch, section, and enrollment number, with direct link to manage choices.

---

## 5. Student Identity Card System

### 5.1 Official Printable ID Card PDF (`pdf.student-id-card`)
- **Standard CR80 Identity Card Dimensions (85.6mm $\times$ 54mm):**
  - Rendered at 300 DPI layout specifications in portrait and landscape printable sheets.
  - Front Side: Institutional header, State Government seal, student photo with rounded frame, Candidate Name, Student ID, URN, Course, Batch, Section, Optional Subject, Blood Group, Validity Session, Authorized Signatory signature line.
  - Back Side: Emergency contact phone, candidate residential address, terms & conditions, dynamic Code 128 barcode, and high-density 2D QR Code.
- **Dynamic Barcode & QR Code Integration:**
  - Utilizes `BarcodeService` to generate inline SVG/Base64 data URIs.
  - QR Code contains structured JSON payload for scanner verification (`STUDENT_ID_CARD`, `id`, `student_id`, `name`, `course`, `batch`, `validity`, `issued_by`).

### 5.2 Admin Settings & Student Download Availability Toggle
- Dedicated settings interface at `pages::admin.settings.student-id-card` (accessible via Settings navigation bar).
- **Student Portal Download Availability Toggle:**
  - Switch: **"Enable Student Downloads"**.
  - When enabled: Students see a primary "Download ID Card (PDF)" button in their student portal.
  - When disabled: Students see an explanatory administrative restriction banner (`"Student ID Card downloads are currently disabled by the administration. You can view your digital card credentials above or contact the administrative office."`) and direct download requests receive a `403 Forbidden` response.
  - Administrative and staff users bypass this restriction at all times.
- **Live Card Preview:** Real-time visual mockup on the settings page displaying simulated front and back card layouts as administrative configurations are altered.

### 5.3 Admin Student ID Card Registry (`pages::admin.student-id-cards.index`)
- Comprehensive candidate roster with real-time stats (Total Candidates, Active Enrollments, With ID Photo, Download Status).
- Single-student card preview modal dialog.
- Single-student instant PDF download action.
- Bulk multi-select candidate export: Generates a single consolidated PDF containing identity cards for all selected students, formatted with print cut marks.
- Quick action button embedded in the main Admin Students table (`pages::admin.students`).

---

## 6. RESTful API Services

The following endpoints are registered in `routes/api.php`:

| Method | Endpoint | Description | Scope / Guard |
|---|---|---|---|
| `GET` | `/api/v1/catalog/optional-subjects` | List active optional subjects catalog | Public / Student |
| `GET` | `/api/v1/optional-subjects` | Paginated catalog with search and category filters | `auth.api_key` |
| `GET` | `/api/v1/optional-subjects/categories` | Unique subject categories with count statistics | `auth.api_key` |
| `GET` | `/api/v1/optional-subjects/allocations` | Cohort allocations with batch/course/section filters | `auth.api_key` |
| `POST` | `/api/v1/optional-subjects/quick-update` | Quick update student's optional subject | `auth.api_key` |
| `GET` | `/api/v1/optional-subjects/requests` | List change requests with status filters | `auth.api_key` |
| `POST` | `/api/v1/optional-subjects/requests/{id}/review`| Approve or reject student change request | `auth.api_key` |
| `GET` | `/api/v1/student/optional-subject` | Authenticated student's optional subject & history | Student Session / Sanctum |
| `POST` | `/api/v1/student/optional-subject/request` | Submit new optional subject change request | Student Session / Sanctum |
| `GET` | `/api/v1/student/id-card/settings` | Digital ID card configuration & download availability | Public / Student |
| `GET` | `/api/v1/student/id-card/payload` | Full digital ID card payload for mobile app rendering | Student Session / Sanctum |
| `GET` | `/api/v1/student/id-card/download` | Download student's official ID Card PDF | Student Session / Sanctum |
| `GET` | `/api/v1/student-id-cards/{identifier}/payload` | Inspect any candidate's digital ID payload | Admin / Staff / API Key |
| `GET` | `/api/v1/student-id-cards/{identifier}/download`| Administrative download of candidate ID Card PDF | Admin / Staff / API Key |

---

## 7. Role-Based Access Control (RBAC)

The following granular permissions were added to `app/Services/RbacService.php` and mapped to default roles:

| Permission | Description | Assigned Roles |
|---|---|---|
| `optional_subjects.view` | View optional subjects catalog, student allocations, and reports | Super Admin, Admin, Admissions Officer, Faculty, Staff |
| `optional_subjects.manage` | Create, edit, delete subjects and quick update student allocations | Super Admin, Admin, Admissions Officer |
| `optional_subjects.requests` | Review, approve, and reject optional subject change requests | Super Admin, Admin, Admissions Officer, Staff |
| `optional_subjects.export` | Export allocation lists and cohort reports to PDF/Excel/CSV | Super Admin, Admin, Admissions Officer, Faculty |
| `optional_subjects.import` | Bulk import student optional subjects via spreadsheet | Super Admin, Admin |
| `student_id_cards.view` | View candidate ID card registry and digital cards | Super Admin, Admin, Admissions Officer, Staff |
| `student_id_cards.download` | Download single or bulk student ID card PDFs | Super Admin, Admin, Admissions Officer, Staff |
| `student_id_cards.settings` | Configure ID card layouts, validity period, and student download access | Super Admin, Admin |

---

## 8. Verification & Automated Test Coverage

Automated feature tests were created using **Pest 5** and executed against an isolated test environment:

### Test Suites Executed:
1. `tests/Feature/OptionalSubjectManagementTest.php` (13 tests, 38 assertions):
   - Admin access verification and 403 unauthorized rejection.
   - UPSC subject seeding verification ($\ge 48$ official subjects).
   - Admin quick update via Livewire component and database validation.
   - Master catalog CRUD (create custom subject, toggle active status, soft delete).
   - Student change request submission with reason tracking.
   - Validation preventing selection of same current subject.
   - Validation preventing duplicate concurrent pending requests.
   - Admin approval workflow verifying automatic update of `student_other_details.optional_subject`.
   - Admin rejection workflow verifying mandatory rejection remarks and subject retention.
   - Allocation export to CSV.
   - API endpoints verification.
2. `tests/Feature/StudentIdCardTest.php` (11 tests, 38 assertions):
   - Admin access to ID cards registry and live statistics.
   - Admin configuration settings viewing and Livewire updates.
   - Student digital ID card portal preview.
   - Student ID card download when enabled by administration.
   - Student ID card download blocked (`403 Forbidden`) when disabled in settings.
   - Admin override allowing download even when student self-service is disabled.
   - Bulk download consolidating multiple students into a single printable PDF.
   - API settings and digital payload verification.
   - Security rule preventing student from accessing another candidate's ID payload.
3. `tests/Feature/ApiKeyManagementTest.php` (18 tests, 77 assertions):
   - Developer Console & Docs rendering and endpoint navigation.
   - Interactive modal guide viewer for Optional Subjects and Student ID Cards.
   - Category tab scoping (`optional_subjects`, `student_id_cards`).
   - Sandbox preset execution and response inspection.

### Summary:
- **Total Tests:** 42
- **Passed:** 42
- **Failures:** 0
- **Total Assertions:** 153
- **Laravel Pint Code Formatter:** Cleanly formatted according to PSR-12 / Laravel standards.

---

## 9. Multi-Platform Documentation & Developer Console

A dedicated step-by-step multi-platform integration guide has been created at:
- [`docs/integrations/optional-subjects-and-id-cards-api.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/docs/integrations/optional-subjects-and-id-cards-api.md)

It contains comprehensive implementation recipes for:
- **Flutter / Dart:** Student portal mobile app (viewing assignments, submitting change requests, displaying digital cards with flip view).
- **React Native / TypeScript:** Candidate digital credential viewer and conditional PDF download.
- **Python / OpenCV:** Campus turnstile barcode and QR token validation service.
- **C# / .NET:** Gatekeeper desktop scanner and turnstile access control application.
- **cURL & Node.js:** Direct HTTP integrations.

Additionally, the **Developer Console & Docs** inside the Admin Panel (`/admin/api-keys/docs`) now features:
- Interactive **Quick Presets** for one-click testing of Optional Subjects and ID Card endpoints.
- Dedicated category filter tabs for `Optional Subjects (9)` and `Student ID Cards (6)`.
- Interactive in-app guide viewers allowing administrators and integration partners to read the full architectural documentation directly within the browser.

