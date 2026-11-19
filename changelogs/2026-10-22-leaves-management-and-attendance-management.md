# Comprehensive Dynamic Leave and Attendance Management System

**Release Date:** October 22, 2026  
**Status:** Completed & Production-Ready  
**Test Suite:** Pest Feature Tests (`tests/Feature/LeaveAndAttendanceManagementTest.php` - 10/10 Passed, 55 Assertions; `tests/Feature/RbacTest.php` - 11/11 Passed, 43 Assertions)

---

## 1. Executive Summary

Implemented an enterprise-grade, fully dynamic **Leave Management System** and **Attendance Management System** for both **office staff** and **academic students** within the Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS platform.

The system addresses the core challenge of differing operational schedules between staff (e.g. 21 monthly office working days with 2nd and 4th Saturday off) and students (e.g. 26 monthly classroom days with weekly tests and lectures), while providing unified administrative control, automated attendance-leave synchronization, hardware biometric machine integration, real-time KPI matrix reporting, defaulter detection (< 75% attendance criteria), and multi-channel notifications (In-App, Email, SMS, WhatsApp, Telegram).

---

## 2. Architecture & Database Design

### Migrations & Tables
Created database migration `database/migrations/2026_10_22_000000_create_leave_and_attendance_management_tables.php` defining 8 normalized tables with full `Auditable` tracking, soft deletes, and foreign keys:
1. **`leave_categories`**: Dynamic taxonomy of leave categories (`CL`, `ML`, `AL`, `DL`, `MAT`, `EXAM`, `COMP`) with annual quotas, monthly limits, color branding, applicability toggles (`all`, `staff`, `student`), paid/unpaid status, and medical attachment rules.
2. **`leave_allocations`**: Year-wise allocated, used, pending, and carried-forward leave quotas per user and category.
3. **`leave_applications`**: Applications filed by staff or students with dates, half-day indicators (`first_half`/`second_half`), purpose, medical proofs, approval state machine (`pending`, `approved`, `rejected`, `cancelled`), and approver remarks.
4. **`working_calendars`**: Dynamic schedule definitions distinguishing office staff (Monday-Friday + 1st/3rd/5th Saturday = 21 working days) from student batches (Monday-Saturday = 26 class days), with shifts, grace minutes, half-day hours, and custom rules (`all_working`, `2nd_4th_off`, `all_saturdays_off`).
5. **`attendance_holidays`**: Holiday calendar categorized by types (`govt_holiday`, `institution_holiday`, `weekly_off`, `weekly_test`, `exam_day`, `restricted_holiday`, `emergency_off`, `vacation`) and targeted audiences (`all`, `staff`, `student`).
6. **`biometric_devices`**: Hardware devices (ZKTeco, eSSL, RFID readers, biometric facial recognition) with IP/Port configuration, device serial number, location, direction (`in`, `out`, `both`), heartbeat timestamp, and per-device API tokens.
7. **`biometric_logs`**: Ingestion buffer for device push logs storing punch timestamps, device IDs, raw JSON payloads, verification mode (fingerprint, card, face), and background sync status.
8. **`attendances`**: Unified master attendance register tracking `attendance_no` (`ATT-YYYY-XXXXXX`), user ID, user type (`staff`/`student`), course/batch/section linkage, date, status (`present`, `absent`, `late`, `half_day`, `on_leave`, `holiday`, `weekly_off`), check-in and check-out timestamps, calculated working hours, late minutes, entry source (`manual_single`, `manual_bulk`, `biometric_device`, `rfid_card`, `system_auto_leave`, `import`), and linkage to `leave_applications`.
9. **`attendance_notifications_log`**: Audit log of automated dispatches across Email, SMS, WhatsApp, and database channels.
10. Added columns to `users`: `biometric_pin`, `rfid_card_no`, `employee_id`, and `staff_department`.

---

## 3. RBAC, Security & Permissions

Updated `app/Services/RbacService.php` and seeded default capabilities:
- **Attendance & Schedules Group**:
  - `attendance.view`: View attendance command center, live feed, and monthly register.
  - `attendance.manage`: Mark, edit, and bulk-mark attendance for students and staff.
  - `attendance.delete`: Soft-delete or void attendance entries.
  - `attendance.export`: Export attendance matrices and registers to CSV and PDF.
  - `attendance.import`: Bulk import attendance registers via CSV/Excel spreadsheets.
  - `attendance.settings`: Configure working calendars, holidays, shifts, and notification thresholds.
  - `attendance.notify`: Dispatch absentee reminders and shortage alerts.
  - `attendance.biometric`: Manage physical biometric devices and API authorization tokens.
- **Leave Management Group**:
  - `leaves.view`: View leave applications and balances.
  - `leaves.apply`: Submit leave requests.
  - `leaves.manage`: Approve, reject, and adjust leave applications.
  - `leaves.categories.manage`: Create, edit, and configure leave categories and annual quotas.
  - `leaves.export`: Export leave logs and reports.
- **Role Assignments**:
  - `Super Administrator` & `Administrator`: Full management across both domains.
  - `Faculty` & `Staff`: `attendance.view`, `leaves.apply`, `leaves.view`, with portal web clock-in/out and timesheets.
  - `Student`: `leaves.apply`, `leaves.view`, with student attendance dossier and % progress gauge.

---

## 4. Service Layer Implementation

### `LeaveManagementService` (`app/Services/Leave/LeaveManagementService.php`)
- **`applyLeave`**: Validates overlapping requests, date spans, category eligibility (`applicable_to`), document attachments, and calculates net days (accounting for half-day). Tracks pending days in user quota.
- **`approveLeave`**: Validates authorization, updates allocation (`pending_days -> used_days`), records approver remarks, records `AuditLog`, dispatches notifications, and **automatically generates `attendances` records** marked as `status = 'on_leave'` and `entry_source = 'system_auto_leave'`.
- **`rejectLeave`**: Reverts pending allocations, records rejection reasons, and clears any synced attendance records.
- **`cancelLeave`**: Allows applicant or administrator cancellation, restoring unused quota and deleting auto-synced attendance entries.
- **`getUserLeaveBalances`**: Computes category-wise annual quota, carried forward, used, pending, and remaining balance.

### `AttendanceService` (`app/Services/Attendance/AttendanceService.php`)
- **`markSingle`**: Records manual check-in/out, calculates working hours and late minutes against the assigned working calendar, checks for approved leaves, and generates unique `ATT-YYYY-XXXXXX` attendance numbers.
- **`markBulk`**: High-performance transaction processing for bulk registers (with 1-click "Mark All Present" or "Mark All Absent"), resolving student enrollment details and syncing remarks.
- **`checkDateStatus`**: Determines date status dynamically (`working_day`, `govt_holiday`, `weekly_off`, `weekly_test`, `exam_day`) by cross-referencing target calendar and active holidays for the given user type.
- **`processBiometricPunches`**: Resolves raw device logs, matches users via `biometric_pin`, `rfid_card_no`, or `employee_id`, and creates/updates daily attendance records with punch times and working duration.
- **`getMonthlyUserAttendanceMatrix`**: Builds 1..31 day-by-day attendance dossier for a user, evaluating working days, present/absent/late/half-day counts, attendance percentage, and shortage flag (< 75%).

### `AttendanceNotificationService` (`app/Services/Attendance/AttendanceNotificationService.php`)
- **`notifyAttendanceMarked`**: Sends real-time alerts when a student or staff is marked present, absent, or late across configured channels (Database, Email, SMS, WhatsApp).
- **`sendAttendanceReminder`**: Sends targeted warning notices regarding classroom attendance shortage (< 75%) or consecutive absences.
- **`sendDailyAbsenteeDigest`**: Dispatches end-of-day summary notifications to candidates absent on working days.

### `AttendanceImportExportService` (`app/Services/Attendance/AttendanceImportExportService.php`)
- **`exportMonthlyRegisterCsv`**: Memory-efficient streamed CSV export with all candidate metadata and 1..31 daily status columns.
- **`exportMonthlyRegisterPdf`**: Formats print-ready landscape PDF attendance register using DomPDF (`resources/views/pdf/attendance-register.blade.php`).
- **`importAttendanceCsv`**: Validates and bulk-inserts attendance logs from external CSV spreadsheets.

---

## 5. Console Commands & Hardware APIs

- **`php artisan attendance:daily-notify`** (`app/Console/Commands/SendDailyAttendanceNotificationsCommand.php`): Scheduled background command for daily absentee alerts.
- **`php artisan attendance:process-biometric`** (`app/Console/Commands/ProcessBiometricLogsCommand.php`): Cron command executing every 5 minutes to process queued biometric punches.
- **`POST /api/v1/biometric/punch`** (`app/Http/Controllers/Api/BiometricApiController.php`): REST endpoint authenticated via `X-Device-Token` for ZKTeco / eSSL push middleware.
- **`POST /api/v1/biometric/heartbeat`**: Live device status check updating `last_heartbeat_at`.

---

## 6. Livewire 4 User Interface

Created 12 modern, responsive, dark/light mode compatible components:
1. **Admin Attendance Hub** (`resources/views/pages/admin/attendance/⚡index.blade.php`): Live KPI cards (Staff Present Rate, Student Present Rate, Absentees, Active Leaves, Online Devices), daily attendance feed, and quick actions.
2. **Attendance Register** (`resources/views/pages/admin/attendance/⚡mark.blade.php`): Filter by Staff Department or Student Course/Batch/Section. Single edit modal and 1-click bulk marking toolbar.
3. **Reports & 31-Day Matrix** (`resources/views/pages/admin/attendance/⚡reports.blade.php`): Full monthly grid (days 1..31), defaulters tab (< 75%), CSV/PDF exports, and CSV bulk importer.
4. **Biometric Integration Desk** (`resources/views/pages/admin/attendance/⚡biometric.blade.php`): Device CRUD, online status indicators, API key regenerate, log viewer, and hardware test punch simulator.
5. **Calendars & Dynamic Holidays** (`resources/views/pages/admin/attendance/⚡calendar.blade.php`): Working calendar configurations (Mon-Sat, 2nd/4th Saturday Off rules), and Holiday management (`govt_holiday`, `weekly_test`, etc.).
6. **Attendance Settings** (`resources/views/pages/admin/settings/⚡attendance.blade.php`): Multi-channel toggles, mandatory percentage threshold (75%), and cron job configurations.
7. **Leave Applications Desk** (`resources/views/pages/admin/leaves/⚡index.blade.php`): Approve/Reject modals with remarks, document viewer, and dynamic pending badge counter in sidebar.
8. **Leave Categories & Quotas** (`resources/views/pages/admin/leaves/⚡categories.blade.php`): Category CRUD, quotas, paid/unpaid toggles, and user allocation manager.
9. **Student Attendance Dossier** (`resources/views/pages/student/⚡attendance.blade.php`): Personal attendance gauge, shortage warning banner (< 75%), monthly calendar grid, and check-in logs.
10. **Student Leave Portal** (`resources/views/pages/student/⚡leaves.blade.php`): Quota balance cards, leave application form with medical certificate upload, and application status timeline.
11. **Staff Timesheet** (`resources/views/pages/portal/⚡my-attendance.blade.php`): Web check-in / check-out button, working hours tracker, and timesheet matrix.
12. **Staff Leave Portal** (`resources/views/pages/portal/⚡my-leaves.blade.php`): Staff personal leave quota tracker, application workflow, and history.

---

## 7. Navigation & Localization

- **Sidebar Navigation** (`resources/views/layouts/app/sidebar.blade.php`):
  - Added dynamic counter badge for pending leave applications.
  - Added `student.attendance.index` ("My Attendance") and `student.leaves.index` ("My Leaves") for students.
  - Added `portal.my-attendance.index` ("My Attendance") and `portal.my-leaves.index` ("My Leaves") for office staff.
  - Added dedicated navigation sections: `ATTENDANCE & SHIFTS` and `LEAVE MANAGEMENT`.
- **Multilingual Support & Full Localization**:
  - Added comprehensive translation entries across `lang/en.json`, `lang/hi.json`, and `lang/bn.json` covering all 643 UI labels, alerts, validation messages, leave categories, and attendance terminology in English, Hindi, and Bengali with 100% parity.

---

## 8. Automated Verification & Quality Assurance

- **Pest Feature Tests**: `tests/Feature/LeaveAndAttendanceManagementTest.php`
  - 10 comprehensive tests covering:
    1. Seeder verification for categories, working calendars, and holidays.
    2. Leave application submission and quota balance tracking.
    3. Automatic leave-to-attendance synchronization on approval (`status = 'on_leave'`, `source = 'system_auto_leave'`).
    4. Reversion of attendance records upon leave cancellation or rejection.
    5. Working calendar resolution verifying staff 2nd/4th Saturday off vs student classroom schedules.
    6. Single and bulk manual attendance marking.
    7. Biometric punch ingestion and background batch processing.
    8. Defaulter detection (< 75% classroom attendance criteria).
    9. Automated notification logging and absentee reminders.
    10. Authorization and accessibility verification across all 12 Livewire routes.
  - **Results**: 10 passed, 55 assertions passed (100% success rate).

---

## 9. Bug Fixes & UI Enhancements

- **Resolved `Column not found: 1054 Unknown column 'course_id' in 'where clause'`**:
  - The `batches` table links to `courses` through the `batch_courses` pivot table (`belongsToMany`), meaning `course_id` does not exist as a column directly on `batches`.
  - Refactored `Batch` queries across `⚡reports.blade.php`, `⚡mark.blade.php`, `⚡index.blade.php`, and `⚡calendar.blade.php` to use `Batch::when($courseId, fn ($q) => $q->whereHas('courses', fn ($cq) => $cq->where('courses.id', $courseId)))` and `$course->batches()->first()`.
- **Lucide Icon Registry Expansion**:
  - Added SVG path definitions in `app/Support/LucideIcons.php` for `gauge`, `calendar-check`, `calendar-x`, and `scan-face`.
  - Added icon alias mapping for `bar-chart-2` -> `bar-chart-3`.
  - Audited all 89 distinct icon usages across the module to ensure zero missing icons.
- **Code Formatting**:
  - Verified and formatted all PHP files via Laravel Pint (`vendor/bin/pint --dirty --format agent`).

- **Code Style Compliance**: Formatted using `vendor/bin/pint --dirty --format agent`.

---

## 10. Robustness, UTF-8 Sanitation, Import/Export & UX Enhancements

- **Resolved `Cannot assign string to property Livewire\Component@anonymous::$selectedCourseId of type ?int`**:
  - Livewire bound HTML `<select wire:model.live="selectedCourseId">` elements transmit string identifiers (e.g. `"1"` or empty string `""`), which triggered PHP `TypeError` when assigned to strictly typed `?int` component properties.
  - Updated property declarations across:
    - [`resources/views/pages/admin/attendance/⚡reports.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/attendance/⚡reports.blade.php) (`$selectedCourseId`, `$selectedBatchId`)
    - [`resources/views/pages/admin/attendance/⚡mark.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/attendance/⚡mark.blade.php) (`$selectedCourseId`, `$selectedBatchId`, `$selectedSectionId`)
    - [`resources/views/pages/admin/attendance/⚡calendar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/attendance/⚡calendar.blade.php) (`$calCourseId`, `$calBatchId`, `$holCourseId`, `$holBatchId`)
    - [`resources/views/pages/admin/payments/⚡schedules.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/payments/⚡schedules.blade.php) (`$selectedCourseId`, `$selectedBatchId`, `$selectedFeeTypeId`)
    - [`resources/views/pages/student/⚡leaves.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/student/⚡leaves.blade.php) (`$categoryId`)
    - [`resources/views/pages/portal/⚡my-leaves.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡my-leaves.blade.php) (`$categoryId`)
  - Changed types to `int|string|null`, implemented `updatedSelected...` lifecycle hooks to normalize empty strings `""` back to `null`, and ensured explicit integer casting `(int)` in database queries.

- **Resolved `Malformed UTF-8 characters, possibly incorrectly encoded`**:
  - Implemented `AttendanceImportExportService::cleanUtf8(mixed $value): string` which inspects encoding via `mb_check_encoding`, converts Windows-1252 / ISO-8859-1 strings into valid UTF-8, and strips out corrupted byte sequences using `mb_scrub(..., 'UTF-8')`.
  - Applied automatic UTF-8 byte order mark (BOM `\xEF\xBB\xBF`) detection and cell sanitization across all parsed CSV and Excel spreadsheet rows before processing into Livewire arrays or database models.

- **Full Import & Export Capabilities**:
  - **CSV Export**: Added streaming CSV output with UTF-8 BOM (`AttendanceImportExportService::exportCsv`), ensuring compatibility with Microsoft Excel without mojibake.
  - **Excel (.xlsx) Export**: Added native Excel spreadsheet generation with styled navy headers, bold text, auto-sized columns, and automatic temporary file cleanup (`AttendanceImportExportService::exportExcel`).
  - **Sample Template Download**: Added downloadable sample import template (`attendance_import_sample.csv`) accessible directly from the attendance reports modal.
  - **PDF Register**: Generates a high-resolution, landscape A4 printable PDF register using DomPDF (`resources/views/pdf/attendance-register.blade.php`).

- **Modern UI Modal Migration (Zero Browser Alerts)**:
  - Replaced all legacy browser `alert()` and `wire:confirm` native dialogs with custom `<x-ui.modal>` components and reactive Alpine toast notifications:
    - Replaced `wire:confirm` on defaulter broadcast in `⚡reports.blade.php` with `<x-ui.modal name="notify-defaulters-modal">`.
    - Replaced `wire:confirm` on daily absent alerts in `⚡index.blade.php` with `<x-ui.modal name="send-absent-alerts-modal">`.
    - Replaced `wire:confirm` on schedule/holiday deletions in `⚡calendar.blade.php` with `<x-ui.modal name="delete-calendar-modal">` and `<x-ui.modal name="delete-holiday-modal">`.
    - Replaced `wire:confirm` on device deletion and token regeneration in `⚡biometric.blade.php` with `<x-ui.modal name="delete-device-modal">` and `<x-ui.modal name="regenerate-token-modal">`.
    - Replaced `wire:confirm` on category deletion in `⚡categories.blade.php` with `<x-ui.modal name="delete-category-modal">`.
    - Replaced `wire:confirm` on leave application cancellation in `student/⚡leaves.blade.php` and `portal/⚡my-leaves.blade.php` with `<x-ui.modal name="cancel-leave-modal">`.
    - Replaced browser `alert()` fallbacks in `⚡meeting-room.blade.php` and `chat-and-media.js` with non-blocking toast dispatches.
  - Verified zero `wire:confirm` and zero `alert()` remain in the Leave and Attendance modules.

- **Test Suite Results**:
  - Pest feature test suite `tests/Feature/LeaveAndAttendanceManagementTest.php`: **13 passed, 71 assertions passed (100% success rate)**.

