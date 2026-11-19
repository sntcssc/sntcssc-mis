# Changelog: Fix Student Profile Change Request Approval SQLSTATE[22007] Invalid Datetime Format Error

**Date:** 2026-11-09  
**Type:** Bug Fix / Database Resilience / Data Sanitization  
**Status:** Completed & Verified  

---

## Issue Description

When an administrator attempted to approve a student profile change request in the Admin Panel (`/admin/profile-requests` handled by [`resources/views/pages/admin/⚡profile-requests.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile-requests.blade.php)), the approval action failed with the following database exception:

```
Approval failed: SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect date value: '' for column 'dob' at row 1 
(Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: laravelx, 
SQL: update `users` set `phone` = 9876543211, `whatsapp_no` = , `dob` = , `gender` = , `tenth_roll` = , `id_type` = , `id_number` = , `updated_by` = 2, `users`.`updated_at` = 2026-11-09 23:38:10 where `id` = 3)
```

---

## Root Cause Analysis

1. **Form Dirty-State Detection with Blank Inputs:**
   In [`resources/views/pages/admin/⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile.blade.php), when a student or user submitted a profile update request from their profile settings, empty HTML form inputs were submitted as empty strings `""`.
   When `$user->fill($validated)` was called, Eloquent compared the model's existing database values (`null`) with the submitted values (`""`). Because `null !== ""`, Eloquent marked fields like `dob`, `whatsapp_no`, `gender`, `tenth_roll`, `id_type`, and `id_number` as dirty even though they had not been populated with meaningful data.
   Consequently, the serialized `payload` stored in `profile_update_requests` contained:
   ```json
   {
     "dob": "",
     "phone": "9876543211",
     "gender": "",
     "id_type": "",
     "id_number": "",
     "tenth_roll": "",
     "designation": "",
     "whatsapp_no": ""
   }
   ```

2. **MySQL Strict Mode (`STRICT_TRANS_TABLES`):**
   When the administrator reviewed the request in [`resources/views/pages/admin/⚡profile-requests.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile-requests.blade.php) and clicked **Approve**, the un-sanitized payload was passed directly to Eloquent:
   ```php
   $userUpdates = array_intersect_key($payload, array_flip(['name', 'first_name', 'last_name', 'phone', 'whatsapp_no', 'dob', 'gender', 'tenth_roll', 'id_type', 'id_number']));
   $user->update($userUpdates);
   ```
   Under MySQL strict mode (`STRICT_TRANS_TABLES`), an empty string `''` is not automatically coerced into `NULL` for `DATE`, `DATETIME`, `INT`, or `DECIMAL` columns, triggering `SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect date value: '' for column 'dob'`.

3. **Bypass of HTTP Trim/Null Middleware:**
   Because the payload was retrieved from a database JSON column during the admin's approval request, Laravel's standard HTTP middleware (`TrimStrings` and `ConvertEmptyStringsToNull`) was not executed against these values, requiring robust model mutators and payload sanitization handlers.

---

## Solutions & Implementation

### 1. Model Mutator Defense in Depth

To prevent empty strings from ever reaching the database layer across all user and student operations:

- **[`app/Models/User.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/User.php):**
  - Added a mutator for `dob` ensuring empty strings `""` or whitespace are automatically converted to `null`, while valid dates are normalized to `Y-m-d`.
  - Added mutators for nullable string attributes: `whatsapp_no`, `tenth_roll`, `id_type`, `id_number`, `gender`, and `designation`, converting `""` to `null`.
- **[`app/Models/StudentProfile.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentProfile.php):**
  - Added mutators for date columns: `dob`, `issued_date`, and `pwbd_issued_date`, normalizing `""` to `null`.
  - Added mutators for numeric columns: `family_income` and `distance`, ensuring empty strings convert to `null`.
- **[`app/Models/StudentEmployment.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentEmployment.php):**
  - Added mutators for `from_date` and `to_date`, converting `""` to `null`.
- **[`app/Models/StudentEducation.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/StudentEducation.php):**
  - Added mutators for `passing_year`, `total_marks`, `marks_obtained`, `percentage`, and `cgpa`, converting `""` to `null`.

### 2. Payload Sanitization in Profile Requests Handler

- **[`resources/views/pages/admin/⚡profile-requests.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile-requests.blade.php):**
  - Implemented `sanitizePayload(array $data, array $dateFields = [], array $numericFields = [])` which:
    - Recursively converts any empty string or whitespace-only value to `null`.
    - Validates date fields and converts invalid/empty date values to `null`.
    - Converts empty numeric/decimal fields to `null`.
  - Applied sanitization across all request sections:
    - `basic` / `account`: Sanitized `dob` and identification fields.
    - `profile`: Sanitized `dob`, `issued_date`, `pwbd_issued_date`, `family_income`, and `distance`.
    - `address`: Sanitized address strings.
    - `education`: Sanitized `passing_year`, `total_marks`, `marks_obtained`, `percentage`, and `cgpa`.
    - `employment`: Sanitized `from_date` and `to_date`.
    - `upsc`: Sanitized UPSC attempt history arrays.
    - `documents`: Sanitized uploaded document file paths.
  - Enforced bidirectional synchronization after user updates using `UserStudentSyncService::syncFromUser($user)`.

### 3. Clean Profile Submission in Profile Settings

- **[`resources/views/pages/admin/⚡profile.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/⚡profile.blade.php):**
  - Cleaned the validated array before dirty-state checking:
    ```php
    $cleanedValidated = array_map(function ($value) {
        return (is_string($value) && trim($value) === '') ? null : $value;
    }, $validated);
    ```
  - This ensures that empty input fields matching existing `null` database values are not falsely treated as dirty changes.

### 4. API Endpoint Sanitization

- **[`app/Http/Controllers/Api/StudentApiController.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Http/Controllers/Api/StudentApiController.php):**
  - Added sanitization logic in `reviewProfileRequest()` for both `$userFields` and `$profileFields`, converting empty string values to `null` before performing updates.

---

## Verification & Testing

1. **Automated Feature Tests:**
   Updated [`tests/Feature/StudentDashboardProfileRequestTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/StudentDashboardProfileRequestTest.php) with dedicated test cases:
   - `admin can approve profile request with empty dob string without sql datetime error`
   - `admin can approve complex profile section requests containing empty dates and numbers`

2. **Test Execution Results:**
   ```bash
   php artisan test tests/Feature/StudentDashboardProfileRequestTest.php --compact
   ```
   **Result:** PASSED (16 tests, 130 assertions).

   ```bash
   php artisan test tests/Feature/BatchCourseLivewireTest.php tests/Feature/Settings/ProfileUpdateTest.php --compact
   ```
   **Result:** PASSED (16 tests, 94 assertions).

3. **Code Style & Formatting:**
   ```bash
   vendor/bin/pint --dirty --format agent
   ```
   **Result:** PASSED (All files conform to Laravel Pint guidelines).

