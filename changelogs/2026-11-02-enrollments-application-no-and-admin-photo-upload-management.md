# Changelog: Enrollment Application Number & Admin Photo Upload / Avatar Management

**Date:** 2026-11-02  
**Type:** Feature / Database Enhancement / File Storage & Upload Architecture / Admin UX Upgrade  

---

## Summary of Changes

### 1. Database Migration: Optional `application_no` in `enrollments` Table
- **Migration:** Created [`database/migrations/2026_11_02_230000_add_application_no_to_enrollments_table.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_11_02_230000_add_application_no_to_enrollments_table.php).
- Added nullable string column `application_no` (max 50 chars, indexed) to the `enrollments` table right after `enrollment_no`.
- Successfully executed migration via `php artisan migrate`.

---

### 2. Model & Service Enhancements for `application_no`
- **Model:** Updated [`app/Models/Enrollment.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Enrollment.php):
  - Added `@property string|null $application_no` to PHPDoc.
  - Added `'application_no'` to `$fillable`.
- **Import/Export Service:** Updated [`app/Services/StudentImportExportService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/StudentImportExportService.php):
  - Added `'Application Number (Optional)'` column header to the downloadable enrollment CSV template and sample rows.
  - Added automatic mapping of `application_number_optional` / `application_no` during bulk enrollment spreadsheet import.
- **API Controller:** Updated [`app/Http/Controllers/Api/EnrollmentApiController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/Api/EnrollmentApiController.php):
  - Added `application_no` search filtering across query terms.
  - Added `application_no` validation (`nullable|string|max:50`) and mass-assignment in `store()` and `update()`.
  - Added `application_no` to resource response transforms and CSV export reports.
- **Student Portal:** Updated [`resources/views/pages/student/⚡academics.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/student/⚡academics.blade.php):
  - Renders `App No: {{ $enrollment->application_no }}` on student enrolled course cards when present.

---

### 3. Admin Enrollments Management View
- **View:** [`resources/views/pages/admin/⚡enrollments.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡enrollments.blade.php):
  - Added `application_no` to `$form` array, initialized in `create()` and loaded in `edit()`.
  - Added validation rule `'form.application_no' => 'nullable|string|max:50'` and persistence in `save()`.
  - Integrated `application_no` into global search query terms.
  - Displayed `App: {{ $enr->application_no }}` below the enrollment number in the table.
  - Added form input for Application Number in `enrollment-form-modal`.
  - Displayed Application Number in `enrollment-view-modal` and added to CSV/Excel exports.

---

### 4. Admin Students Management: Photo Upload / Replace / Remove with `RenamesUploads`
- **View:** [`resources/views/pages/admin/⚡students.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡students.blade.php):
  - Integrated `RenamesUploads` trait (`App\Concerns\RenamesUploads`) and `WithFileUploads`.
  - Added public `$avatarFile` state and `removeStudentAvatar()` method.
  - **Create Mode (Tab 1: Basic & Identity):** Added photo upload card with real-time preview (`temporaryUrl`), file input supporting JPG/PNG/WEBP (up to 5MB), and removal/cancel actions.
  - In `saveStudent()`: validated `$avatarFile`, generated standardized filename via `$this->generateFileName('avatar', $this->avatarFile, $user->id)` (`avatar_user_{userId}_{timestamp}.{ext}`), stored file in `avatars/` on `public` disk, and assigned to `$user->avatar`.
  - **Edit Mode (Section 1: Account & Identity):** Added photo management block with current avatar preview, replace upload file input, and "Remove Photo" button with confirmation.
  - In `updateBasicInfo()`: replaced old avatar file (deleting previous disk asset) and saved newly renamed avatar file.
  - In `removeStudentAvatar()`: physically deleted existing avatar file from storage disk and nullified `$student->avatar`.
  - **Application Number in Student Workflows:**
    - Added `enroll_application_no` input to Tab 3 (Initial Course Enrollment) of the student registration form and saved to the initial enrollment record.
    - Added `application_no` input to `quick-enroll-modal` and mapped to created enrollment.
    - Added `Application #` details in the candidate complete dossier enrollments table.
    - Added avatar rendering in students table rows and dossier modal headers.

---

### 5. Admin Users Management: Photo Upload / Replace / Remove with `RenamesUploads`
- **View:** [`resources/views/pages/admin/⚡users.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡users.blade.php):
  - Integrated `use RenamesUploads;` and added public `$avatarFile = null;` state.
  - Added `removeAvatar()` method to delete existing disk file and clear `$user->avatar`.
  - Added avatar upload and replace/remove component in `user-form-modal` (Tab 1: Personal & Role) with live preview and loading state.
  - In `save()`:
    - Added image validation for `$avatarFile` (`image|mimes:png,jpg,jpeg,webp|max:5120`).
    - Handled avatar persistence in creation using `$this->generateFileName('avatar', $this->avatarFile, $user->id)`.
    - Handled avatar replacement in edit mode, safely unlinking the old file and saving the new file.
  - Cleaned up Blade `@php` directive parsing to ensure seamless single-file Livewire component compilation.

---

### 6. Modal Confirmation for Photo Removal & Avatar-Only Update Fix
- Replaced native browser `wire:confirm` alerts with structured application UI confirmation modals:
  - **Students:** Added `student-avatar-remove-modal` (`<x-ui.modal name="student-avatar-remove-modal">`) in [`⚡students.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡students.blade.php), opened with `$store.modals.open('student-avatar-remove-modal')` and automatically closed upon removal.
  - **Users:** Added `user-avatar-remove-modal` (`<x-ui.modal name="user-avatar-remove-modal">`) in [`⚡users.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡users.blade.php), opened with `$store.modals.open('user-avatar-remove-modal')` and automatically closed upon removal.
- **Fixed Avatar-Only Update Issue:**
  - In [`⚡students.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡students.blade.php) `updateBasicInfo()`, updated the dirty fields check from `if (empty($dirty))` to `if (empty($dirty) && ! $this->avatarFile)`. Previously, if only the student's photo was uploaded without changing other account fields, it returned early showing "No changes detected in Account details." Now, the photo uploads, renames via `RenamesUploads`, and saves properly with dedicated toast feedback.
  - Added an inline **Save Photo** button in the avatar management block for both students and users so administrators can instantly persist an uploaded photo without scrolling to the bottom of the form.

---

### 7. Automated Testing & Verification
- **Test File:** [`tests/Feature/EnrollmentApplicationNoAndAvatarUploadTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/EnrollmentApplicationNoAndAvatarUploadTest.php):
  - `enrollment can store and search by optional application number`: verified database persistence and search filtering in admin enrollments.
  - `admin can create enrollment with optional application number through enrollments page`: verified Livewire form creation with `application_no`.
  - `admin can upload photo when registering a new student using RenamesUploads convention`: verified filename pattern `avatar_user_{id}_{timestamp}.jpg`, storage on public disk, and initial enrollment `application_no`.
  - `admin can replace and remove student profile photo in edit mode`: verified replacing deletes old file and saves new file, and removal unlinks file and nullifies avatar.
  - `admin can update only photo of student without changing any other account details`: verified that avatar-only changes persist and dispatch success toasts without early exit.
  - `admin can quick-enroll student with optional application number`: verified `quick-enroll-modal` persistence.
  - `admin can upload photo when creating user and replace/remove in edit mode`: verified complete user avatar lifecycle.
- **Test Suite Results:**
  - `php artisan test --compact tests/Feature/EnrollmentApplicationNoAndAvatarUploadTest.php`: **7 passed** (43 assertions).
  - `php artisan test --compact tests/Feature/AdminPagesTest.php`: **19 passed** (22 assertions).
  - `php artisan test --compact tests/Feature/BatchCourseEnrollmentStudentTest.php`: **12 passed** (59 assertions).
- **Code Style:** Formatted all modified PHP files with `vendor/bin/pint --format agent` (Pint passed with zero style violations).

