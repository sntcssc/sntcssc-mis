# Changelog: Leave & Attendance Management REST API Suite, Developer Console & Multi-Platform Enterprise Integration

**Date:** 2026-10-31  
**Author:** SNTCSSC Development Team  
**Category:** Leaves Management, Attendance Management, Biometric Hardware & Turnstiles, Mobile GPS Clock-In/Out, Multi-Platform REST APIs, Developer Console & In-App Documentation, Dynamic Study Material Integration, Enterprise Audit Trails, Multi-Language Localization  
**Status:** Completed & Production Ready  

---

## 1. Executive Summary

As part of the comprehensive institutional digital modernization of the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC)**, this release delivers a complete, enterprise-grade RESTful API ecosystem, comprehensive integration documentation, and Developer Console integration for the **Leaves Management** and **Attendance Management** modules, alongside full interactive testing and documentation for the **Study Material Management Module** directly within the admin panel.

The architecture decouples biometric turnstiles, optical facial recognition scanners, staff/student biometric clock-ins, mobile GPS geo-fencing, dynamic leave category management, quota balance calculations, multi-level leave approvals with automatic attendance synchronization, and statutory defaulter warnings (< 75% threshold) from monolithic Blade views. This enables seamless, turnkey integrations across:
- **Mobile Applications** (Flutter & React Native for iOS/Android) for student and faculty self-service GPS clock-in/out with coordinate verification, leave applications with proof attachments, quota balance sheets, and real-time approval push notifications.
- **Campus Turnstiles, Flap Barriers & Lobby Kiosks** (C# .NET & Python Hardware Bridges) for sub-second offline/online gate punches using RFID cards (`rfid_card_no`), biometric PINs (`biometric_pin`), and student/employee lifetime identifiers, featuring automatic gate unlock signals and hardware batch punch ingestion.
- **Biometric Hardware Terminals** (ZKTeco, Hikvision, eSSL facial & fingerprint terminals) with automatic on-demand punch sync (`/api/v1/attendance/biometric/sync`), device heartbeat telemetry, and zero-trust token rotation (`/api/v1/attendance/devices/{id}/regenerate-token`).
- **Administrative Developer Console & Interactive Docs** (`resources/views/pages/admin/api-keys/⚡docs.blade.php` and `⚡index.blade.php`) with full-featured interactive testing, documentation, and live execution sandboxes directly within the admin dashboard.

All implementations strictly adhere to enterprise production standards:
- **Atomic Database Transactions** (`DB::beginTransaction()` / `DB::commit()` / `DB::rollBack()`) wrapping all attendance single and bulk cohort marks, leave approvals with automatic `attendances` synchronization, quota balance adjustments, leave cancellations, and device provisioning.
- **Audit Trails & Security Compliance** via `AuditLogService::log()`, recording timestamps, actor user IDs, auditable models, and delta state changes.
- **Soft Delete Protection** (`SoftDeletes`) on all attendance records, biometric devices, working calendars, holidays, leave categories, leave allocations, and leave applications.
- **Active Dependency & State Consistency Guards**:
  - `ACTIVE_LEAVE_PROTECTED`: Manual deletion of attendance records automatically synchronized from active approved leaves is blocked to prevent quota discrepancies.
  - `ACTIVE_APPLICATIONS_PREVENTED`: Deletion of leave categories with active pending or approved applications is strictly blocked.
- **Standardized API Response Envelopes** (`success`, `data`, `meta`, `error` with structured machine-readable error codes).
- **Complete Tri-lingual Localization** (`lang/en.json`, `lang/bn.json`, `lang/hi.json`) for all new UI strings, status badges, and documentation tables.
- **Automated Pest 5 Test Coverage** (12 API feature tests, 158 assertions) and 100% clean formatting with Laravel Pint.

---

## 2. Attendance Management REST API Architecture (38 Endpoints)

The entire attendance and hardware ingestion hierarchy is exposed under `/api/v1/attendance/*`:

### 2.1. Executive Telemetry & Granular Statistics
- `GET /api/v1/attendance/dashboard` (`read:attendance`): Executive telemetry returning daily KPI summary (`total_marked`, `total_punches_today`, `present_count`, `absent_count`, `late_count`, `half_day_count`, `on_leave_count`), student/staff attendance rates, hardware device online/offline telemetry, 15 recent live punches, and 7-day weekly trend.
- `GET /api/v1/attendance/statistics` (`read:attendance`): Multi-attribute analytics breakdown by date ranges, user types, courses, batches, and entry sources (`biometric_device`, `rfid_card`, `mobile_qr`, `manual_single`, `manual_bulk`, `import`), including statutory attendance percentage rates.

### 2.2. Master Attendance Register & Cohort Marking (CRUD)
- `GET /api/v1/attendance/register` & `GET /api/v1/attendance` (`read:attendance`): Search, filter, and paginate master attendance register records by date, user type (`student`, `staff`), course, batch, section, status, and search keywords.
- `POST /api/v1/attendance/mark` (`write:attendance`): Mark individual attendance record with auto-generation of unique `attendance_no`, shift late calculation, and audit trail logging. Accepts alias parameters (`clock_in` / `check_in_time`, `clock_out` / `check_out_time`, `manual` / `manual_single`).
- `POST /api/v1/attendance/bulk-mark` (`write:attendance`): Cohort-wide bulk attendance marking in a single database transaction with automatic resolution of user roles and shift times.
- `GET /api/v1/attendance/{identifier}` (`read:attendance`): Show detailed attendance record dossier with student/staff info and biometric device telemetry.
- `PUT /api/v1/attendance/{identifier}` (`write:attendance`): Update check-in/out times, working hours, status, or remarks with audit logging.
- `DELETE /api/v1/attendance/{identifier}` (`write:attendance`): Soft delete attendance record. Enforces `ACTIVE_LEAVE_PROTECTED` guard preventing manual deletion of system-synced approved leave entries.

### 2.3. Statutory Defaulter Detection & Bulk Notifications
- `GET /api/v1/attendance/defaulters` (`read:attendance`): Dynamic defaulter query calculating attendance percentage against working days in the month; flags candidates falling below the threshold (default: 75%) and calculates exact shortfall days.
- `POST /api/v1/attendance/defaulters/notify` (`write:attendance`): Bulk notification dispatch (SMS, email, in-app push) to defaulters and guardians with attendance summary and warning directives.

### 2.4. Mobile GPS Clock-In / Clock-Out & Candidate Self-Service
- `POST /api/v1/attendance/clock-in` (`write:attendance`): Geolocation-validated clock-in for mobile clients (Flutter / React Native). Validates coordinates, calculates late minutes against working calendar shifts, and updates/creates attendance with `mobile_qr` / `mobile_gps` source.
- `POST /api/v1/attendance/clock-out` (`write:attendance`): Mobile clock-out with automatic elapsed working hours computation.
- `GET /api/v1/attendance/my-attendance` (`read:attendance`): Authenticated candidate's personal monthly attendance ledger, attendance percentage, and 30-day chronological punch history.

### 2.5. Hardware Turnstiles, Gate Flaps & Offline Batch Punch Ingestion
- `POST /api/v1/attendance/punch` (`write:biometric`, `write:attendance`): Sub-second gate verification for turnstiles and RFID barriers. Accepts RFID card number, biometric PIN, or lifetime student ID; returns instant gate action (`gate_action: unlock`), candidate photo URL, and updates biometric punch logs.
- `POST /api/v1/attendance/batch-punch` & `POST /api/v1/attendance/biometric/batch-punch` (`write:biometric`, `write:attendance`): Ingestion of bulk offline punch arrays buffered during network outages by Raspberry Pi, Arduino, or C# Windows bridge services.
- `POST /api/v1/attendance/biometric/sync` (`write:attendance`, `write:biometric`): On-demand synchronization engine to process buffered biometric punch logs into master attendance records.

### 2.6. Biometric Hardware Devices Management (CRUD)
- `GET /api/v1/attendance/devices` (`read:attendance`): List all registered hardware devices (turnstiles, facial terminals, RFID readers) with unprocessed punch queues and heartbeat statuses.
- `POST /api/v1/attendance/devices` (`write:attendance`): Provision a new biometric device with automatic API token generation. Accepts aliases (`name` / `device_name`, `device_identifier` / `device_serial_number`, `ip_address` / `device_ip`).
- `GET /api/v1/attendance/devices/{id}` (`read:attendance`): Hardware device telemetry and connection configuration.
- `PUT /api/v1/attendance/devices/{id}` (`write:attendance`): Update hardware device settings, IP, port, or active status.
- `DELETE /api/v1/attendance/devices/{id}` (`write:attendance`): Soft delete biometric device.
- `POST /api/v1/attendance/devices/{id}/regenerate-token` (`write:attendance`): Zero-trust security token rotation, returning a freshly generated `bio_...` token for hardware authentication.

### 2.7. Institutional Working Calendars & Holidays (CRUD)
- `GET /api/v1/attendance/calendars` (`read:attendance`): List working calendars and shift timings for staff and student cohorts.
- `POST /api/v1/attendance/calendars` (`write:attendance`): Provision shift schedules with day-of-week active flags, 2nd/4th Saturday rules, expected start/end times, and late grace periods.
- `GET /api/v1/attendance/calendars/{id}` (`read:attendance`): Single working calendar profile.
- `PUT /api/v1/attendance/calendars/{id}` (`write:attendance`): Update shift rules and grace minutes.
- `DELETE /api/v1/attendance/calendars/{id}` (`write:attendance`): Soft delete calendar.
- `GET /api/v1/attendance/holidays` (`read:attendance`): Academic holidays and restricted institutional leaves list.
- `POST /api/v1/attendance/holidays` (`write:attendance`): Declare institutional or government holidays.
- `GET /api/v1/attendance/holidays/{id}` (`read:attendance`): Holiday details.
- `PUT /api/v1/attendance/holidays/{id}` (`write:attendance`): Modify holiday dates or descriptions.
- `DELETE /api/v1/attendance/holidays/{id}` (`write:attendance`): Soft delete holiday.

### 2.8. Data Import & Export Facilities
- `GET /api/v1/attendance/export/csv` (`read:attendance`, `download:attendance`): Streamed CSV export of attendance records with multi-attribute filtering.
- `GET /api/v1/attendance/export/pdf` (`read:attendance`, `download:attendance`): Printable tabular PDF attendance register.
- `POST /api/v1/attendance/import` (`write:attendance`): Spreadsheet ingestion of attendance records with row-by-row validation.
- `GET /api/v1/attendance/sample-csv` (`read:attendance`): Sample CSV template download for import preparation.
- `GET /api/v1/attendance/users/{userId}/matrix` (`read:attendance`): Comprehensive 1..31 day monthly attendance matrix with present/absent/late/leave breakdowns.

---

## 3. Leave Management REST API Architecture (24 Endpoints)

The complete leave lifecycle is exposed under `/api/v1/leaves/*`:

### 3.1. Executive Telemetry & Deep Analytics
- `GET /api/v1/leaves/dashboard` (`read:leaves`): Executive telemetry returning active leaves today, pending applications count, approved/rejected breakdown, top categories, and monthly leave application volume trends.
- `GET /api/v1/leaves/statistics` (`read:leaves`): Departmental and category-wise analytics on leave utilization and balance consumption.

### 3.2. Leave Categories & Policy Configuration (CRUD)
- `GET /api/v1/leaves/categories` (`read:leaves`): List all institutional leave categories (Casual, Medical, Earned, Duty, Maternity, Study) with quota rules and proof requirements.
- `POST /api/v1/leaves/categories` (`write:leaves`): Provision a new leave category with annual quota, monthly limit, proof attachment requirements, and applicable roles (`staff`, `student`, `both`/`all`).
- `GET /api/v1/leaves/categories/{id}` (`read:leaves`): Detailed leave category dossier.
- `PUT /api/v1/leaves/categories/{id}` (`write:leaves`): Update category quota, color badge, or attachment rules.
- `DELETE /api/v1/leaves/categories/{id}` (`write:leaves`): Soft delete leave category (protected: blocked if active pending/approved applications exist).

### 3.3. User Leave Quota Allocations & Balance Sheets
- `GET /api/v1/leaves/allocations` (`read:leaves`): Search and paginate annual leave allocations by user and category.
- `POST /api/v1/leaves/allocations` (`write:leaves`): Assign or update annual quota allocations for staff or students.
- `GET /api/v1/leaves/allocations/{id}` (`read:leaves`): Single quota allocation profile.
- `PUT /api/v1/leaves/allocations/{id}` (`write:leaves`): Adjust total days, carry-forward days, or allocation notes.
- `GET /api/v1/leaves/users/{userId}/balances` & `GET /api/v1/leaves/balances/{userId}` (`read:leaves`): Category-wise leave balance sheet for a student or staff member (`total_days`, `used_days`, `pending_days`, `remaining_days`).

### 3.4. Leave Applications Lifecycle (Apply, Review, Approve, Reject, Cancel)
- `GET /api/v1/leaves/applications` (`read:leaves`): Search, filter, and paginate leave applications by status, applicant, category, and date ranges.
- `POST /api/v1/leaves/applications` (`write:leaves`): Submit a leave application with balance pre-check, overlap prevention, half-day support, and optional proof document uploads.
- `GET /api/v1/leaves/applications/{identifier}` (`read:leaves`): Application dossier with applicant profile, category rules, proof download URL, approver remarks, and synced attendance records.
- `PUT /api/v1/leaves/applications/{identifier}` (`write:leaves`): Update pending application dates or reason with balance re-validation.
- `POST /api/v1/leaves/applications/{identifier}/approve` (`write:leaves`): Atomic approval workflow. Automatically deducts leave balance from pending to used, and **automatically generates `on_leave` attendance records in the master register** for each day in the date range with entry source `system_auto_leave`.
- `POST /api/v1/leaves/applications/{identifier}/reject` (`write:leaves`): Reject application with mandatory remarks and automatic refund of pending quota days back to the user's balance.
- `POST /api/v1/leaves/applications/{identifier}/cancel` (`write:leaves`): Cancellation workflow. Safely reverts used quota back to remaining balance and **automatically deletes or cleans synced `on_leave` attendance records**.
- `DELETE /api/v1/leaves/applications/{identifier}` (`write:leaves`): Soft delete leave application.

### 3.5. Candidate & Staff Self-Service Endpoints
- `GET /api/v1/leaves/my-balances` (`read:leaves`): Authenticated user's live leave quota balance sheet.
- `GET /api/v1/leaves/my-applications` (`read:leaves`): Authenticated user's personal leave applications history and approval statuses.
- `POST /api/v1/leaves/apply` (`write:leaves`): Quick self-service leave application submission.
- `POST /api/v1/leaves/my-applications/{identifier}/cancel` (`write:leaves`): Self-service cancellation of pending or approved leaves.

---

## 4. Multi-Platform Integration Guide (`docs/integrations/`)

A step-by-step developer manual was published at:
`docs/integrations/leave-and-attendance-management-api.md`

### Contents Include:
1. **High-Level System Architecture Diagram** (Mermaid flowchart showing Cloud API Gateway, Mobile Apps, Desktop Turnstiles, Hardware Bridges, and Web Consoles).
2. **Mobile GPS Clock-In Sequence Diagram** (Client geolocation capture, bearer auth, coordinate validation, late threshold check, and feedback).
3. **Turnstile Gate Verification Sequence Diagram** (Sub-second RFID/PIN scan, API verification, hardware relay trigger to unlock gate, and background log buffering).
4. **Leave Approval & Attendance Sync Sequence Diagram** (Submission, balance deduction, approval, atomic creation of `on_leave` records in `attendances` table, and push notification).
5. **Defaulter Detection & Alert Sequence Diagram** (Monthly matrix computation, < 75% filter, and automated digest dispatch).
6. **Detailed REST Reference** covering all 62 endpoints with query parameters, request schemas, response envelopes, and error codes.
7. **Production Code Recipes**:
   - **Flutter / Dart**: Complete service class with HTTP client, interceptors, GPS clock-in, and turnstile punch handling.
   - **React Native / TypeScript**: Mobile integration service using Axios and standard types.
   - **C# .NET 8+**: Windows Turnstile Service with HttpClient, hardware serial port integration, and local SQLite offline queue fallback.
   - **Python 3.11+**: Batch punch ingestion bridge for ZKTeco and Linux kiosk terminals.
   - **cURL**: Ready-to-run terminal verification commands.

---

## 5. Developer Console & In-App Documentation Integration

Inside the Admin Developer Portal (`resources/views/pages/admin/api-keys/⚡docs.blade.php` and `⚡index.blade.php`):
1. **Interactive Test Sandbox Expansion**:
   - Added preset module filters for **"Attendance"** and **"Leaves"**.
   - Added quick-test buttons for:
     - Attendance: `/dashboard`, `/statistics`, `/register`, `/defaulters`, `/devices`, `/calendars`, `/holidays`, `/mark`, `/clock-in`, `/punch`.
     - Leaves: `/dashboard`, `/statistics`, `/categories`, `/allocations`, `/applications`, `/my-balances`, `/apply`.
     - Study Materials: `/study-materials/courses/1`, `/study-materials/batches/1`.
2. **Interactive Modal Console Integration** (`⚡index.blade.php`):
   - Added `<optgroup label="Attendance & Shifts">` and `<optgroup label="Leave Management">` in the API test console modal.
   - Implemented real-time simulation handlers for `attendance.dashboard`, `attendance.statistics`, `attendance.register`, `attendance.defaulters`, `leaves.dashboard`, `leaves.statistics`, `leaves.categories`, `leaves.my-balances`.
3. **Official Architecture Cards**:
   - Added architecture guide card for **Leave & Attendance • Multi-Platform** linking directly to the Markdown guide viewer in the console.
   - Added **Study Materials • Multi-Platform** architecture card.
4. **Multi-Platform SDK Code Snippets**:
   - Added code snippets in Flutter, React Native, C# .NET, Python, and cURL for Attendance and Leave operations.
5. **Comprehensive Scopes & Endpoints Matrix**:
   - Added detailed rows for all 38 attendance endpoints and 24 leave endpoints with HTTP methods, scopes, and descriptions.

---

## 6. Security, Resilience & Enterprise Standards

1. **Granular API Scopes Enforced**:
   - `read:attendance`, `write:attendance`, `write:biometric`, `download:attendance`
   - `read:leaves`, `write:leaves`
2. **Zero-Trust Token Rotation**:
   - Biometric hardware tokens (`device_token` / `api_token`) can be rotated on demand without downtime via `/api/v1/attendance/devices/{id}/regenerate-token`.
3. **Data Integrity & Active Dependency Guards**:
   - Attendance records marked `on_leave` linked to active leave applications are protected against manual deletion to maintain ledger accuracy.
   - Categories linked to active applications cannot be deleted.
4. **Defaulter Statutory Calculation**:
   - Automated calculation of attendance percentage using official working days resolved from target calendars and institutional holidays, properly accounting for staff 2nd/4th Saturday offs vs student schedules.

---

## 7. Tri-Lingual Localization Updates

Added 47 new localized phrases across `lang/en.json`, `lang/bn.json`, and `lang/hi.json`:
- English, Bengali (বাংলা), and Hindi (हिन्दी) translations for all new UI labels, status alerts, error responses, console modals, and documentation headers.
- Verified valid JSON syntax and character encoding across all 3 translation files (EN: 5,980 keys, BN: 5,938 keys, HI: 5,897 keys).

---

## 8. Verification & Test Suite Results

- **Pest Feature Tests**:
  - `tests/Feature/LeaveAndAttendanceApiTest.php`: **12 tests passed, 158 assertions** (3.89s).
  - `tests/Feature/LeaveAndAttendanceManagementTest.php`: **14 tests passed, 74 assertions** (8.91s).
  - `tests/Feature/StudyMaterialDynamicApiTest.php`: **8 tests passed, 92 assertions** (2.64s).
- **Code Style & Formatting**:
  - Ran `vendor/bin/pint --format agent` fixing style conventions across all modified PHP controllers and test files.
- **PHP Syntax & Linter**:
  - `php -l` clean on `AttendanceApiController.php`, `LeaveApiController.php`, `LeaveAndAttendanceApiTest.php`, `⚡docs.blade.php`, and `⚡index.blade.php`.

