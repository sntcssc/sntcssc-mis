# Changelog: Dynamic Study Material & Examination Management Multi-Platform REST APIs

**Date:** 2026-10-28  
**Author:** SNTCSSC Development Team  
**Category:** Enterprise Architecture, Multi-Platform Integration, REST APIs, Examination Management, Study Materials, Developer Console  
**Status:** Completed & Production Ready  

---

## 1. Executive Summary

In this release, the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS** has been upgraded with two enterprise-grade, future-ready modules designed for multi-platform integration across **Mobile Applications** (Flutter, React Native, Swift, Kotlin), **Web Applications & Student Portals** (React, Vue, Alpine, Blade), and **Desktop Clients** (Electron, C# .NET):

1. **Dynamic Study Material Management System API Expansion:**
   - Administrative authoring, updating, and soft deletion with comprehensive audit trails.
   - Chunked binary file attachment uploading with strict MIME type whitelisting and path traversal security.
   - Dynamic study material type categories (Lecture Notes, PIB Sandhan, Current Affairs, Handouts, E-Books) with live counts and visibility controls.
   - Client view telemetry (`X-Client-Platform` tracking for mobile, desktop, and web).
   - Time-limited signed token binary streaming (`/download/{token}`) with 30-minute TTL without revealing internal storage paths.
   - Automated ZIP archive packaging (`/download-zip`) for batch prefetching and offline mobile synchronization.
   - Personalized student feed (`/my-materials`) automatically filtered by enrolled courses and batches.
   - Secure share link generation with expiration and password encryption.

2. **Examination Management System (Full Lifecycle Implementation):**
   - High-throughput examination scheduling, notifications, application windows, duration, fees, and instructions.
   - Examination venues, centers, and room/hall hierarchy with real-time seating capacity calculations.
   - Live interactive visual seating plan matrix across venues, halls, and classrooms.
   - Bulk candidate seat allocation and standardized roll number generation (`EXAM-CENTER-0001`).
   - Invigilator mobile/tablet attendance verification with encrypted QR code and barcode scanning (`verify-admit-card`).
   - Merit ranking, scoring, qualification declaration, and OMR result ingestion.
   - Digital student admit card generation with verification payload and scheduled release date protection.

3. **Developer Console & Interactive Documentation Integration:**
   - Integrated both modules into the **API Developer Console** (`⚡docs.blade.php`).
   - In-modal full markdown integration guides rendered dynamically via `Str::markdown()`.
   - Multi-platform SDK boilerplate recipes (Flutter Dart, React Native JS, Electron Node, C# .NET, Python, cURL).
   - Real-time interactive REST sandbox with one-click presets for Examinations and Study Materials.

4. **Enterprise Quality & Security Compliance:**
   - **Soft Deletes** on all examination entities (`exams`, `examination_centers`, `examination_center_rooms`, `exam_candidates`) and study materials (`study_materials`, `study_material_files`).
   - **Database Transactions (`DB::transaction`)** wrapping all state modifications to prevent data corruption.
   - **Structured `try/catch`** exception handling with consistent error formats (`{ "success": false, "message": "...", "errors": {...} }`).
   - **Audit Logging via `AuditLogService::log(...)`** for every administrative action.
   - **Path Traversal Protection (`realpath`)** for file streaming and download endpoints.
   - **Strict MIME Whitelist** on file uploads (`pdf, doc, docx, ppt, pptx, xls, xlsx, txt, zip, rar, mp4, mp3, webm, jpg, jpeg, png`).
   - **Trilingual Localization:** 41 translation keys synchronized across `lang/en.json`, `lang/bn.json`, and `lang/hi.json`.
   - **Complete Pest Test Coverage:** 20 test cases (240 assertions) passing with 100% green status.
   - **Laravel Pint:** Code style formatted according to project conventions.

---

## 2. Database Migrations & Model Architecture

### 2.1. Migration: `database/migrations/2026_10_28_000000_create_examination_management_tables.php`
- **`examination_centers` Table:**
  - `code` (string, unique), `name`, `address`, `city`, `district`, `state`, `pincode`.
  - `contact_person`, `contact_phone`, `contact_email`.
  - `total_capacity` (integer), `is_active` (boolean).
  - `softDeletes()`, `timestamps()`.
- **`examination_center_rooms` Table:**
  - `examination_center_id` (foreign key, cascade on delete).
  - `room_number`, `floor`, `building_name`, `capacity` (integer), `is_active` (boolean).
  - `softDeletes()`, `timestamps()`.
- **`exams` Table:**
  - `uuid` (char 36, unique), `exam_code` (string, unique), `exam_name`.
  - `academic_year`, `conducting_body`, `notification_date`, `apply_start`, `apply_end`.
  - `exam_date`, `exam_time`, `reporting_time`, `duration_minutes`.
  - `fee_general`, `fee_reserved`, `total_vacancies`.
  - `status` (`draft`, `published`, `registration_closed`, `completed`, `cancelled`).
  - `instructions`, `admit_card_instructions`, `admit_card_release_date`.
  - `created_by` (foreign key), `softDeletes()`, `timestamps()`.
- **`exam_center_mappings` Table:**
  - `exam_id`, `examination_center_id`, `is_active`, `timestamps()`.
- **`exam_candidates` Table:**
  - `uuid` (char 36, unique), `exam_id` (foreign key), `student_id` (nullable), `user_id` (nullable).
  - `candidate_name`, `candidate_email`, `candidate_phone`.
  - `roll_number` (string, indexed), `examination_center_id`, `examination_center_room_id`, `seat_number`.
  - `admit_card_token` (string 64, unique indexed), `admit_card_downloaded_at`.
  - `attendance_status` (`pending`, `present`, `absent`, `disqualified`).
  - `attendance_marked_at`, `marks_obtained`, `rank`, `is_qualified`, `remarks`.
  - `softDeletes()`, `timestamps()`.

### 2.2. Eloquent Models Created
- [`app/Models/Exam.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Exam.php) — Implements `Auditable` and `SoftDeletes`. Scopes: `upcoming()`, `published()`.
- [`app/Models/ExaminationCenter.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ExaminationCenter.php) — Implements `Auditable` and `SoftDeletes`. Scopes: `active()`.
- [`app/Models/ExaminationCenterRoom.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ExaminationCenterRoom.php) — Implements `Auditable` and `SoftDeletes`. Scopes: `active()`.
- [`app/Models/ExamCandidate.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ExamCandidate.php) — Implements `Auditable` and `SoftDeletes`. Constants for attendance.

### 2.3. API Resources Created
- [`app/Http/Resources/ExamResource.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Resources/ExamResource.php) — Paginated exam summaries.
- [`app/Http/Resources/ExamDetailResource.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Resources/ExamDetailResource.php) — Comprehensive dossier with mapped venues.
- [`app/Http/Resources/ExaminationCenterResource.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Resources/ExaminationCenterResource.php) — Centers and child room rosters.
- [`app/Http/Resources/ExamCandidateResource.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Resources/ExamCandidateResource.php) — Candidates with seating and attendance status.

### 2.4. Database Seeder
- [`database/seeders/ExaminationSeeder.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/ExaminationSeeder.php) — Seeds standard centers (Salt Lake Main Campus, Siliguri Regional Centre) and flagship exam (Civil Services Coaching Entrance Exam 2027) with candidates.

---

## 3. Business Logic & Enterprise Services

### 3.1. Examination Service: `app/Services/Exam/ExamService.php`
- `getDashboardMetrics()`: Aggregate real-time statistics (total exams, upcoming tests, centers, total capacity, candidate attendance rate).
- `createExam()`: Transactional creation with center synchronization and audit logging.
- `updateExam()`: Transactional updates capturing before/after values for audit logs.
- `deleteExam()`: Soft deletes exam and cascades to candidates with guard against deleting completed exams with declared merit results.
- `createCenter()`, `updateCenter()`, `deleteCenter()`: Center management with validation against deleting venues with active assigned candidates.
- `createRoom()`, `updateRoom()`, `deleteRoom()`: Hall and room capacity management with center total capacity re-computation and assignment guard.
- `getSeatingPlan()`: Visual seating plan matrix calculating assigned vs vacant seats across all rooms.
- `allocateSeats()`: Bulk candidate seating arrangement generating sequential roll numbers and randomized admit card tokens.
- `markAttendance()`: Invigilator attendance recording (`present`, `absent`, `disqualified`) with timestamps and remarks.
- `recordResults()`: Bulk scoring, ranks, and qualification flags.
- `verifyAdmitCard()`: Fast QR token and roll number validation for barcode scanners.
- `generateAdmitCardData()`: Structured printable/wallet payload with institute authority, venue, candidate biodata, and QR payload.

### 3.2. Study Material Service Expansion: `app/Services/StudyMaterial/StudyMaterialService.php`
- `attachFiles()`: Transactional file upload with `FileUploadService`, auto-incrementing display orders, and audit logging.
- `detachFile()`: Transactional file removal from server storage and soft deletion with audit logging.

### 3.3. API Scopes Registered in `ApiKeyService.php`
- `read:examinations` — View examination schedules, venues, seating matrices, candidates, and verify QR tokens.
- `write:examinations` — Create exams, manage centers, allocate seats, record attendance, and declare results.
- `download:admit_cards` — Generate digital candidate admit cards.

---

## 4. API Endpoints Directory

### 4.1. Dynamic Study Material Management (21 Routes)
| Method | Endpoint | Required Scope | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/study-materials` | `read:materials` | Paginated catalog with search, course, batch, type filters |
| `POST` | `/api/v1/study-materials` | `write:materials` | Create material with course, batch, access level, and files |
| `GET` | `/api/v1/study-materials/{id}` | `read:materials` | Full material dossier with file downloads and access rules |
| `PUT` | `/api/v1/study-materials/{id}` | `write:materials` | Update material attributes, replace files, change access |
| `DELETE` | `/api/v1/study-materials/{id}` | `write:materials` | Soft delete study material and linked records |
| `POST` | `/api/v1/study-materials/{id}/files` | `write:materials` | Upload and attach binary files (PDF, MP4, DOCX, ZIP) |
| `DELETE` | `/api/v1/study-materials/{id}/files/{fileId}` | `write:materials` | Delete attached file from server storage |
| `GET` | `/api/v1/study-materials/types` | `read:materials` | Dynamic material types with live published counts |
| `POST` | `/api/v1/study-materials/types` | `write:materials` | Create custom material type category |
| `PUT` | `/api/v1/study-materials/types/{id}` | `write:materials` | Update custom material type name, badge, visibility |
| `DELETE` | `/api/v1/study-materials/types/{id}` | `write:materials` | Delete custom material type category |
| `POST` | `/api/v1/study-materials/{id}/track-view` | `read:materials` | Client telemetry recording (`X-Client-Platform` header) |
| `GET` | `/api/v1/study-materials/{id}/download-token` | `download:materials` | Generate signed 30-min download token |
| `GET` | `/api/v1/study-materials/download/{token}` | Public Stream | Stream binary file via signed token |
| `GET` | `/api/v1/study-materials/{id}/download-zip` | `download:materials` | Package all attachments into a single ZIP archive |
| `GET` | `/api/v1/study-materials/my-materials` | `read:materials` | Student personalized feed of enrolled materials |
| `POST` | `/api/v1/study-materials/{id}/share-link` | `write:materials` | Generate or refresh secure share link with password |
| `GET` | `/api/v1/study-materials/statistics` | `read:materials` | Repository storage and download KPI metrics |
| `GET` | `/api/v1/external/study-materials/types` | `read:materials` | Public material types for external CMS websites |
| `GET` | `/api/v1/external/study-materials` | `read:materials` | Public study materials feed for external websites |
| `POST` | `/api/v1/external/study-materials/{id}/download-request` | `download:materials` | Lead capture with smart OTP verification |

### 4.2. Examination Management (21 Routes)
| Method | Endpoint | Required Scope | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/examinations/dashboard` | `read:examinations` | Executive KPI metrics and upcoming exam calendar |
| `POST` | `/api/v1/examinations/verify-admit-card` | `read:examinations` | Fast barcode/QR token verification for invigilators |
| `GET` | `/api/v1/examinations` | `read:examinations` | List and search examinations by year, status, date |
| `POST` | `/api/v1/examinations` | `write:examinations` | Schedule new examination with fees and instructions |
| `GET` | `/api/v1/examinations/{id}` | `read:examinations` | Full examination dossier with mapped centers |
| `PUT` | `/api/v1/examinations/{id}` | `write:examinations` | Update schedule, instructions, status |
| `DELETE` | `/api/v1/examinations/{id}` | `write:examinations` | Soft delete/archive examination |
| `GET` | `/api/v1/examinations/centers` | `read:examinations` | List examination centers and venues across districts |
| `POST` | `/api/v1/examinations/centers` | `write:examinations` | Register new examination center venue |
| `GET` | `/api/v1/examinations/centers/{id}` | `read:examinations` | Detailed center profile with classroom halls |
| `PUT` | `/api/v1/examinations/centers/{id}` | `write:examinations` | Update center address, capacity, contact person |
| `DELETE` | `/api/v1/examinations/centers/{id}` | `write:examinations` | Deactivate/delete center (guarded by seat allocations) |
| `POST` | `/api/v1/examinations/centers/{id}/rooms` | `write:examinations` | Add classroom/hall with seating capacity |
| `DELETE` | `/api/v1/examinations/centers/{centerId}/rooms/{roomId}` | `write:examinations` | Delete examination hall from center |
| `GET` | `/api/v1/examinations/{id}/seating-plan` | `read:examinations` | Visual seating matrix (centers -> rooms -> seats) |
| `POST` | `/api/v1/examinations/{id}/allocate-seats` | `write:examinations` | Bulk allocate candidate seats & roll numbers |
| `GET` | `/api/v1/examinations/{id}/candidates` | `read:examinations` | Query scheduled candidates, seats, attendance |
| `POST` | `/api/v1/examinations/{id}/attendance` | `write:examinations` | Mark candidate attendance (present/absent/disqualified) |
| `POST` | `/api/v1/examinations/{id}/results` | `write:examinations` | Ingest candidate scores, ranks, and merit results |
| `GET` | `/api/v1/examinations/my-exams` | Student / `read:examinations` | Student self-service: list registered exams & timings |
| `GET` | `/api/v1/examinations/{id}/admit-card` | Student / `download:admit_cards` | Download official admit card payload with QR token |

---

## 5. Developer Console & Multi-Platform Documentation

### 5.1. Integration Guides Created
- [`docs/integrations/study-material-management-api.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/docs/integrations/study-material-management-api.md) — Multi-Platform Integration Guide covering Flutter (Dart), React Native (JS), Electron / Node.js, C# (.NET), Python, and cURL, along with offline SQLite caching strategies and chunked binary streaming.
- [`docs/integrations/examination-management-api.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/docs/integrations/examination-management-api.md) — Comprehensive guide covering mobile QR invigilator scanner implementation, seating matrix visualization, admit card generation, and OMR result ingestion.

### 5.2. Developer Console UI: `resources/views/pages/admin/api-keys/⚡docs.blade.php`
- Interactive modal viewer displaying full integration guides via `openGuide('examination')`, `openGuide('study_materials')`, and `openGuide('hostel')`.
- Filter tabs for **Examinations (21)**, **Study Materials (21)**, and **Hostel Management (29)**.
- Live sandbox with auto-fill presets for examination and study material test endpoints.
- Ready-to-use SDK code snippets covering real-time QR attendance, chunked token downloads, and multi-platform authentication.

---

## 6. Verification & Quality Assurance

- **Unit & Feature Test Suites:**
  - `tests/Feature/StudyMaterialDynamicApiTest.php`: **8 passed** (92 assertions)
  - `tests/Feature/ExaminationApiTest.php`: **12 passed** (148 assertions)
  - `tests/Feature/ApiKeyManagementTest.php`: **17 passed** (62 assertions)
  - `tests/Feature/HostelApiTest.php`: **9 passed** (459 assertions)
  - **Total Tests Verified:** 46 passed (761 assertions).
- **Code Formatting:** Formatted with `vendor/bin/pint --format agent`.
- **Localization:** 41 new keys added and verified across `lang/en.json`, `lang/bn.json`, `lang/hi.json`.

---

## 7. Migration & Deployment Instructions

1. **Run Database Migrations:**
   ```bash
   php artisan migrate --force
   ```
2. **Seed Initial Examination Centers & Samples (Optional for Staging/Dev):**
   ```bash
   php artisan db:seed --class=ExaminationSeeder
   ```
3. **Build Frontend Assets (if modifying blade/views):**
   ```bash
   npm run build
   ```
4. **Clear Caches:**
   ```bash
   php artisan route:clear
   php artisan config:clear
   php artisan view:clear
   ```

