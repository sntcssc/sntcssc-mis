# Changelog: Comprehensive Dynamic Academic Calendar & Version Control Management System

**Date:** 2026-11-01  
**Category:** Academic Operations, Institutional Planning, Document Archival & Version Control  
**Scope:** Full-stack enterprise academic calendar system featuring annual month-wise activity roadmaps, version-controlled calendar file repository with complete year-wise historical preservation, direct multi-version download streaming, spreadsheet import/export, role-based access control, interactive modals, audit trails, and multi-platform REST API integration.

---

## 1. Executive Summary & Business Architecture

Every academic year, the institute prepares an official **Academic Calendar** detailing month-wise operational planning, course commencement, foundational lectures, state holidays and recesses, examination test series, seminars, workshops, and UPSC personality interview panels. Over the course of the session, gazette changes or administrative rescheduling require periodic revisions of the calendar.

Prior to this implementation, revised schedules risked overwriting historical documents or causing discrepancies across student cohorts. The newly deployed **Academic Calendar & Version Control System** introduces:
1. **Year-Wise Session Preservation:** Every academic year maintains independent session boundaries, working days, instructional quotas, and themes.
2. **Strict Multi-Version File Archival:** When a revised calendar document (PDF, Word, Excel, or high-resolution graphic) is approved and uploaded, the system automatically assigns the next revision number, updates the active pointer, and preserves all previous document versions intact with changelog notes, approval authority, effective dates, and download analytics.
3. **Month-Wise Interactive Activities Roadmap:** Structured institutional activities classified into Academic, Examination, Admission, Holiday, Co-Curricular, Administrative, Sports/Cultural, and General categories, searchable and filterable across the 12-month calendar cycle.
4. **Interactive Modal Dialogs & Modern UI/UX:** Complete elimination of disruptive browser alerts/confirms in favor of interactive `<x-ui.modal>` overlays supporting light and dark modes, fully mobile responsive.
5. **Two-Way Data Interoperability:** Excel (.xlsx) and CSV bulk import with error reporting, alongside export to CSV, Excel, and professionally styled landscape PDF booklets.
6. **Student Self-Service Portal:** Enrolled students access the active institutional calendar, review monthly milestones, see upcoming events, and download approved documents.
7. **Production-Grade Security & Audit Logging:** Model-level audit tracking via `AuditLog::record()`, database transactions, soft-deletes with cascade protection, and Spatie RBAC permission policies.

---

## 2. Database Schema & Migration Architecture

### Migration: `2026_11_01_070000_create_academic_calendar_management_tables.php`

1. **`academic_calendars` (Master Academic Year Entity)**
   - `id`: Auto-incrementing primary key.
   - `calendar_code`: Unique reference string (`AC-YYYY-YY` or `AC-YYYY-YY-##`).
   - `academic_year`: Indexed session string (e.g., `2026-2027`, `2025-2026`).
   - `title`: Descriptive academic title.
   - `theme_or_motto`: Institutional objective or motto for the year.
   - `start_date`, `end_date`: Operational dates of the academic year.
   - `total_working_days`, `total_instructional_days`: Quantitative session targets.
   - `description`: Narrative overview of institutional objectives.
   - `status`: Enum (`draft`, `published`, `archived`).
   - `is_current`: Boolean flag indicating whether the session is currently active.
   - `current_version_id`: Foreign key pointing to the active `AcademicCalendarVersion`.
   - `created_by`, `updated_by`, `deleted_by`: Foreign keys to `users`.
   - `timestamps`, `softDeletes`.
   - Compound and individual indexes for query optimization.

2. **`academic_calendar_versions` (Document Version Control & Revision Archive)**
   - `id`: Auto-incrementing primary key.
   - `academic_calendar_id`: Foreign key linked to `academic_calendars` (cascade on delete, short fk `ac_ver_cal_fk`).
   - `version_code`: Human-readable version code (`v1.0`, `v1.1`, `v1.2`, `v2.0`).
   - `revision_number`: Integer revision counter (1, 2, 3...).
   - `version_title`: Revision summary headline.
   - `file_path`: Storage path in `storage/app/public/academic_calendars/{calendar_code}/`.
   - `file_name`: Original uploaded filename preserved.
   - `file_size`: Integer in bytes.
   - `file_mime`: MIME type detected on upload.
   - `change_summary`: Text changelog notes describing specific modifications made in this revision.
   - `approved_by`: Academic Council / Board approval authority.
   - `approval_date`, `effective_from`: Governance timeline dates.
   - `is_current`: Boolean flag distinguishing active version from historical revisions.
   - `download_count`: Metric tracking cumulative document downloads.
   - `uploaded_by`, `created_by`, `updated_by`, `deleted_by`: User audit links.
   - `timestamps`, `softDeletes`.
   - Indexes: `['academic_calendar_id', 'is_current']` (`ac_ver_cal_cur_idx`) and `['academic_calendar_id', 'revision_number']` (`ac_ver_cal_rev_idx`).

3. **`academic_calendar_activities` (Month-Wise Institutional Operations & Events)**
   - `id`: Auto-incrementing primary key.
   - `academic_calendar_id`: Foreign key to `academic_calendars`.
   - `academic_calendar_version_id`: Nullable foreign key linking to the specific revision document in which the event was scheduled or updated (`ac_act_ver_fk`).
   - `activity_code`: Unique reference identifier (`ACT-YYYY-####`).
   - `title`: Name of activity/event.
   - `category`: Enum (`academic`, `examination`, `admission`, `holiday`, `co_curricular`, `administrative`, `sports_cultural`, `other`).
   - `start_date`, `end_date`: Date range of the activity.
   - `month` (1-12), `year`: Automatically computed from `start_date` on creation/update.
   - `target_audience`: `all`, `students`, `faculty`, `staff`, `aspirants`.
   - `department`: Department in charge (e.g., `Academics`, `Examination Cell`, `Student Affairs`).
   - `venue_mode`: `offline`, `online`, `hybrid`.
   - `venue_or_link`: Venue hall, classroom, or meeting hyperlink.
   - `is_tentative`: Boolean flag indicating if dates are provisional.
   - `is_notified`: Notification broadcast flag.
   - `status`: `planned`, `ongoing`, `completed`, `postponed`, `cancelled`.
   - `description`, `remarks`: Guidance notes and syllabus references.
   - `display_order`: Integer sorting index within the month.
   - `created_by`, `updated_by`, `deleted_by`: User foreign keys.
   - `timestamps`, `softDeletes`.
   - Indexes: `['academic_calendar_id', 'month', 'year']` (`ac_act_cal_mo_yr_idx`) and `['start_date', 'category']` (`ac_act_date_cat_idx`).

---

## 3. Eloquent Models & Business Methods

### `App\Models\AcademicCalendar`
- Uses `Auditable`, `HasFactory`, and `SoftDeletes`.
- `generateCode(string $academicYear)`: Deterministic unique code generation (`AC-2026-27`).
- `versions()`: HasMany relationship ordered by descending revision number.
- `currentVersion()`: BelongsTo relationship pointing to active version.
- `activities()`: HasMany relationship ordered by start date and display order.
- `makeCurrent()`: Atomically deactivates other calendar sessions and activates this one.
- `formatted_period`: Formatted human date period (e.g. `01 Apr 2026 - 31 Mar 2027`).

### `App\Models\AcademicCalendarVersion`
- Uses `Auditable`, `HasFactory`, and `SoftDeletes`.
- `academicCalendar()`: BelongsTo parent calendar.
- `uploader()`: BelongsTo user who uploaded the document.
- `human_file_size`: Formatted file size accessor (e.g. `2.14 MB`).
- `incrementDownloads()`: Safe atomic incrementation without triggering timestamp mutations.

### `App\Models\AcademicCalendarActivity`
- Uses `Auditable`, `HasFactory`, and `SoftDeletes`.
- Auto-computes `month` and `year` from `start_date` via Eloquent model lifecycle hooks (`creating`, `updating`).
- `generateCode(int $year)`: Auto-generates sequential identifier (`ACT-YYYY-####`).
- Scopes: `scopeForMonth`, `scopeCategory`, `scopeUpcoming`, `scopeSearch`.
- Accessors: `formatted_date_range`, `month_name`, `getCategoryBadgeClasses()`, `getStatusBadgeClasses()`.

---

## 4. Backend Service Layer: `App\Services\AcademicCalendarService`

Encapsulates all domain transactions, file management, and audit logging:
- `createCalendar(array $data, ?UploadedFile $file, ?User $user)`: Creates calendar, manages active session flag, uploads initial v1.0 version document, and logs audit event.
- `updateCalendar(AcademicCalendar $calendar, array $data, ?User $user)`: Updates metadata in transaction with audit logging.
- `setCurrentCalendar(AcademicCalendar $calendar, ?User $user)`: Sets active session.
- `uploadRevision(AcademicCalendar $calendar, UploadedFile $file, array $data, ?User $user)`: Computes next revision counter, deactivates previous version, stores sanitized document in storage, updates master pointer, and logs version upload audit event.
- `createActivity(AcademicCalendar $calendar, array $data, ?User $user)`: Creates scheduled activity.
- `updateActivity(AcademicCalendarActivity $activity, array $data, ?User $user)`: Updates scheduled activity.
- `deleteActivity(AcademicCalendarActivity $activity, ?User $user)`: Soft deletes activity.
- `deleteCalendar(AcademicCalendar $calendar, ?User $user)`: Cascades soft-deletes to all child versions and activities.
- `deleteVersion(AcademicCalendarVersion $version, ?User $user)`: Soft deletes version with fallback promotion of previous revision if deleting current version.
- `importActivities(AcademicCalendar $calendar, string $filePath, ?User $user)`: Reads spreadsheet data via PhpSpreadsheet with error tracking and column resolution.
- `exportActivities(AcademicCalendar $calendar, array $filters, string $format)`: Produces CSV, Excel, or styled landscape PDF report.
- `downloadVersion(AcademicCalendarVersion $version, ?User $user)`: Securely streams document download, increments download count, and logs audit record.
- `getCalendarStatistics(?AcademicCalendar $calendar)`: Computes real-time KPI metrics.

---

## 5. Role-Based Access Control (RBAC) & Permissions

Updated `App\Services\RbacService`:
- Registered new permission group **`Academic Calendar`**:
  - `academic_calendar.view`: View institutional academic calendars, monthly planners, and version history.
  - `academic_calendar.create`: Create new academic year calendars and add monthly activities.
  - `academic_calendar.edit`: Modify academic calendar details and activities.
  - `academic_calendar.revise`: Upload revised calendar documents and manage version control.
  - `academic_calendar.delete`: Delete or archive academic calendars, versions, and activities.
  - `academic_calendar.export`: Export academic calendar reports and schedules to PDF, Excel, and CSV.
  - `academic_calendar.import`: Bulk import monthly activities from spreadsheets.
  - `academic_calendar.download`: Download version-controlled academic calendar documents.
- Synced permissions to roles:
  - **Super Administrator & Administrator**: Full access across all 8 permissions.
  - **Faculty, Staff, Admissions Officer**: `view`, `download`, `export`.
  - **Accountant & Librarian**: `view`, `download`.
  - **Student**: `view`, `download`.

---

## 6. User Interface & Livewire 4 SFC Implementation

### Admin Management Portal: `resources/views/pages/admin/⚡academic-calendar.blade.php`
- Route: `{current_team}/academic-calendar` (`admin.academic-calendar.index`).
- Real-time KPI Statistics Cards: Active Session, Total Activities, Approved Version, Activities This Month, Completed Milestones, Preserved Files.
- Academic Year Switcher: Switch between current and previous academic year sessions with one click.
- Three Dynamic View Modes:
  1. **Month Planner (Default):** Month selector pills (April - March) with activity count badges, categorized cards with colored category badges, date ranges, venue/mode tags, tentative indicators, and inline edit/delete actions.
  2. **List View:** Sortable, searchable, filterable table with bulk export options.
  3. **Version Vault:** Comprehensive document revision archive displaying current active version callout and full history table with download links, revision notes, approval authority, file size, and upload timestamps.
- Interactive Modals (no browser alerts):
  - `calendar-form-modal`: Create / edit academic calendar sessions.
  - `revision-upload-modal`: Upload revised document with revision notes.
  - `activity-form-modal`: Add / edit scheduled activities.
  - `activity-import-modal`: Bulk import activities with CSV template link.
  - `calendar-delete-modal`: Soft-delete confirmation with audit warnings.

### Student Portal: `resources/views/pages/student/⚡academic-calendar.blade.php`
- Route: `{current_team}/student/academic-calendar` (`student.academic-calendar.index`).
- Displays active academic calendar banner with session theme and working days.
- Prominent one-click official calendar download button (`Download Official Calendar (:ver)`).
- Version history drawer allowing students to download older revisions year-wise.
- Month-wise event breakdown with keyword search and category filters.
- Activity detail modal dialog for viewing guidelines and instructions.
- Print / PDF Schedule download button.

---

## 7. Document Downloads & Printable PDF Template

### Download Controller: `App\Http\Controllers\AcademicCalendarDownloadController`
- `downloadVersion(AcademicCalendarVersion $version)`: Secure stream download with permission check, counter increment, and audit log.
- `downloadLatest(AcademicCalendar $calendar)`: Quick shortcut to active version document.
- `exportPdf(AcademicCalendar $calendar)`: Generates complete booklet.
- `sampleImportTemplate()`: Streams standardized CSV template.

### Printable PDF View: `resources/views/pdf/academic-calendar.blade.php`
- Professional A4 Landscape layout rendered with DomPDF.
- SNTCSSC header and Government of West Bengal insignia text.
- Metadata box with security hash, active version badge, and session dates.
- Grouped month-wise activities table with colored category indicators.
- Preserved document version history table.

---

## 8. Multi-Platform REST API Integration

Controller: `App\Http\Controllers\Api\AcademicCalendarApiController` registered in `routes/api.php` under `api/v1/academic-calendars` protected by API key authentication and scopes:
- `GET /api/v1/academic-calendars`: Filterable list of academic calendars (`read:academics`).
- `GET /api/v1/academic-calendars/current`: Current active calendar with statistics (`read:academics`).
- `GET /api/v1/academic-calendars/{id}`: Detailed calendar record (`read:academics`).
- `POST /api/v1/academic-calendars`: Create calendar (`write:academics`).
- `PUT /api/v1/academic-calendars/{id}`: Update calendar (`write:academics`).
- `DELETE /api/v1/academic-calendars/{id}`: Soft delete calendar (`write:academics`).
- `POST /api/v1/academic-calendars/{id}/set-current`: Activate session (`write:academics`).
- `GET /api/v1/academic-calendars/{id}/versions`: Version history (`read:academics`).
- `POST /api/v1/academic-calendars/{id}/versions`: Upload revision document (`write:academics`).
- `GET /api/v1/academic-calendars/{id}/versions/{versionId}/download`: Download version file (`read:academics`).
- `GET /api/v1/academic-calendars/{id}/activities`: Filtered activities (`read:academics`).
- `POST /api/v1/academic-calendars/{id}/activities`: Create activity (`write:academics`).
- `PUT /api/v1/academic-calendars/{id}/activities/{activityId}`: Update activity (`write:academics`).
- `DELETE /api/v1/academic-calendars/{id}/activities/{activityId}`: Delete activity (`write:academics`).
- `GET /api/v1/student/academic-calendar`: Student-facing calendar endpoint.

---

## 9. Global Search, Navigation & Localization

1. **Navigation Sidebar (`resources/views/layouts/app/sidebar.blade.php`):**
   - **Student Navigation (`$mainNavItems`):** Guarded with `'permission' => 'academic_calendar.view'`. If a student account does not have access related to academic calendars, the item is completely hidden from the sidebar.
   - **Administrative Navigation (`ACADEMICS` section):** Added `'permission' => 'academic_calendar.view'` and `'admin_only' => true`, with an explicit route check preventing student users from ever seeing the administrative management link.
   - **Granular Permission & Role Inheritance:** Enhanced permission filtration to grant visibility if the user has `academic_calendar.view` OR any academic calendar permission (`create`, `edit`, `revise`, `delete`, `export`, `import`, `download`) or `Super Administrator` role. Users without any academic calendar access will not see Academic Calendar anywhere in the sidebar.
   - **Gate::before Fallback (`AppServiceProvider.php`):** Configured Gate rule allowing any user with non-view academic calendar capabilities to satisfy `academic_calendar.view`.
2. **Command Palette (`App\Services\GlobalSearchService`):**
   - Distinct navigation entries: `nav_academic_calendar` (admin portal with `admin_only = true`) and `nav_student_academic_calendar` (student portal with `student_only = true`).
   - Dynamic search indexing for `AcademicCalendar` and `AcademicCalendarActivity`, automatically resolving target URLs based on the user's account type (student vs admin).
3. **Localization (`lang/en.json`, `lang/hi.json`, `lang/bn.json`):**
   - Added translations for all calendar headings and activity categories in English, Hindi, and Bengali (185 keys, 0 missing).

---

## 10. Automated Test Coverage

- **`tests/Feature/AcademicCalendarTest.php`** (12 tests, 66 assertions, all passed):
  - Admin access & viewing management portal.
  - Student access in student portal.
  - Calendar creation with initial v1.0 document and audit log.
  - Revision upload preserving previous versions year-wise and updating active pointer.
  - Download count tracking and audit logging.
  - Month-wise activity CRUD operations.
  - Bulk spreadsheet importing.
  - Exporting to CSV, Excel, and PDF booklet.
  - Cascade soft-deleting.
  - Livewire admin component session switching and modal interactions.
  - **Sidebar visibility test for student accounts (visible when access granted, hidden when access revoked, and isolated from administrative links).**
  - **Sidebar visibility test for staff/admin accounts (visible with view or edit capabilities, hidden when all access is revoked).**
- **`tests/Feature/AcademicCalendarApiTest.php`** (13 tests, 57 assertions, all passed):
  - API listing with pagination.
  - API current active calendar retrieval.
  - API calendar creation and update.
  - API set-current session activation.
  - API version upload and history retrieval.
  - API activity CRUD endpoints.
  - API student endpoint.
  - API key scope enforcement.
  - API system metadata advertisement (`supported_modules.academic_calendar = true`).
  - API Key scope catalog validation (`read:academics` & `write:academics`).
  - Multi-platform integration markdown guide verification.
  - Developer console Livewire interactive guide modal verification.
  - Developer console endpoints and reference card display.
- **Total Test Suite:** 23 tests, 107 assertions, 100% pass rate.

---

## 11. Developer Console & In-App Interactive API Documentation

Integrated the Academic Calendar module directly into the administrative **Developer Documentation & Console** (`resources/views/pages/admin/api-keys/⚡docs.blade.php`):
1. **Interactive Sandbox Integration:**
   - Added automatic path parameter normalization for `{versionId}` and `{activityId}` alongside `{id}`.
   - Pre-configured realistic testing payloads for calendar session creation, version upload, and activity scheduling in `selectPreset()`.
   - Sandbox execution directly testable with live sub-requests and microsecond duration metrics.
2. **Endpoints Catalog:**
   - Cataloged all 15 REST endpoints under `academics` and `students` categories with exact scope mappings and descriptions.
3. **Interactive Guide Viewer:**
   - Wired `openGuide('academic_calendar')` to render `docs/integrations/academic-calendar-and-version-control-api.md` directly inside the in-app modal using CommonMark markdown parser.
   - Added dedicated guide card under *Official Architecture & Integration Guides* with direct 1-click modal access.
4. **API Discovery & Scopes:**
   - Updated `ApiKeyIntegrationApiController::appInfo()` to declare `'academic_calendar' => true` in `supported_modules`.
   - Updated `ApiKeyService::getAvailableScopes()` to include academic calendars in `read:academics` and `write:academics` scope descriptions.

---

## 12. Enterprise Multi-Platform Integration Blueprint

Created `docs/integrations/academic-calendar-and-version-control-api.md`:
- Comprehensive architectural documentation for external clients.
- Multi-platform client recipes:
  - **Flutter (Dart):** Cross-platform mobile service with offline caching and binary file downloader.
  - **React Native / TypeScript:** Axios-based typed client with interface models and React hooks.
  - **C# (.NET MAUI & WPF):** Windows desktop client and campus noticeboard display service.
  - **Electron / Node.js:** Digital signage background synchronization daemon.
- Enterprise recommendations:
  - iCalendar (`.ics`) and WebCal feed subscription standard.
  - Broadcast push notification webhooks on calendar revision publication.
  - Conditional HTTP requests using `ETag` and `If-Modified-Since` for bandwidth efficiency.
  - Permanent audit compliance and regulatory retention.

---

## 13. Localization, Typography & Code Quality

1. **Localization:**
   - Extracted all UI strings from admin and student Livewire components.
   - Added 185 distinct localization keys across `lang/en.json`, `lang/hi.json`, and `lang/bn.json` with zero missing keys.
2. **Icon & Component Verification:**
   - Audited all `<x-icon>` tags against `App\Support\LucideIcons`. All 27 unique icons validated and verified.
   - Converted dynamic sort header icons to standard `:name` syntax.
3. **Pint Code Formatting:**
   - Executed `vendor/bin/pint --format agent` to format all modified PHP classes, controllers, seeders, and tests to adhere to PSR-12 and Laravel Boost conventions.


