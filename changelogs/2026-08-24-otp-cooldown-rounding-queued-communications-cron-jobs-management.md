# Release: OTP Cooldown Rounding, Asynchronous Queued Communications & Admin Cron Jobs Management

**Date:** 2026-08-24  
**Scope:** Strict integer rounding for OTP cooldown messages, asynchronous queued job pipeline for non-OTP messages/campaigns while preserving instant synchronous delivery for OTPs, dynamic enterprise-grade Cron Jobs & Scheduled Tasks management system in the Admin Panel with manual on-demand execution, live terminal logs, frequency presets, soft deletes, audit logs, and automated Pest tests.

---

## Summary of Changes

### 1. OTP Cooldown Integer Formatting
- **`app/Services/OtpService.php`**:
  - Cast and rounded remaining cooldown seconds and rate limit wait times to clean integers using `(int) ceil(...)`.
  - Eliminated raw float numbers (e.g. `50.89193`) from user-facing error and cooldown warning messages.

---

### 2. Asynchronous Queued Communications Pipeline
- **`app/Jobs/SendQueuedEmailJob.php`**:
  - Implements `ShouldQueue` to process outbound transactional and bulk emails asynchronously via queue workers.
- **`app/Jobs/SendQueuedSmsJob.php`**:
  - Implements `ShouldQueue` to process outbound SMS messages asynchronously via queue workers.
- **`app/Jobs/DispatchCommunicationCampaignJob.php`**:
  - Implements `ShouldQueue` to process large audience broadcasts in the background without blocking HTTP requests.
- **`app/Services/CommunicationService.php`**:
  - Added helper methods `queueEmail()`, `queueSms()`, and `queueCampaign()`.
  - Preserved **direct synchronous delivery** (`logAndSendEmail` & `logAndSendSms`) for OTP verification codes and instant security alerts.

---

### 3. Enterprise Cron Jobs & Scheduled Tasks Management
- **`database/migrations/2026_08_24_000003_create_cron_jobs_table.php`**:
  - Created `cron_jobs` table storing `name`, `command`, `arguments` (json), `expression` (cron format e.g. `0 0 * * *`), `description`, `is_active`, `run_in_background`, `without_overlapping`, `last_run_at`, `last_run_status` (`success`, `failed`, `running`), `last_run_duration`, `last_run_output` (longText), `next_run_at`, `created_by`, `updated_by`, `deleted_at` (`SoftDeletes`), and `timestamps`.
- **`app/Models/CronJob.php`**:
  - Eloquent model with `Auditable`, `SoftDeletes`, `HasFactory`.
  - Integrated `Cron\CronExpression` for parsing, next run computation (`calculateNextRunAt()`), human-friendly frequency strings (`getHumanFrequency()`), and self-contained execution runner (`run()`) capturing exit codes, runtime duration, and terminal outputs.
- **`database/seeders/CronJobSeeder.php`**:
  - Seeded default maintenance schedules (Prune Expired Invitations, Clear Expired Password Resets, Prune Failed Jobs, Database Model Pruning).
- **`routes/console.php`**:
  - Dynamically registers all active database `CronJob` entries with Laravel's `Schedule` facade at runtime.
- **`resources/views/pages/admin/system/⚡cron-jobs.blade.php`**:
  - Enterprise Livewire 3 dashboard:
    - Summary stat cards: Total Tasks, Active Schedules, Successful Last Run, Failed Tasks.
    - Filter toolbar with status pills (All, Active, Inactive, Failed), debounced search, and Trash toggle.
    - Interactive table with human-readable frequencies, cron expression badges, status toggles, last execution duration, and next due date.
    - **"Run Now"** trigger with live execution spinner, duration tracking, and instant terminal log output inspector.
    - Create / Edit modal with frequency generator presets (Every 5 mins, Hourly, Daily, Weekly, Monthly, Custom).
    - Soft delete, restore, and permanent deletion with confirmation modals and audit logging.
- **`routes/web.php` & `resources/views/layouts/app/sidebar.blade.php`**:
  - Registered route `admin.cron-jobs.index` under `/system/cron-jobs`.
  - Added "Cron Jobs" with clock icon under the `SYSTEM` section in the admin sidebar.

---

### 4. Verification & Testing
- **`tests/Feature/CronJobsTest.php`**:
  - 4 comprehensive Pest feature tests covering:
    - OTP cooldown integer rounding without fractions.
    - Queued job dispatching for non-OTP messages/campaigns.
    - CronJob model execution, duration measurement, and next run calculation.
    - Livewire dashboard CRUD, status toggle, manual execution (`runNow`), and soft delete/restore.
- All 16 feature tests across `CronJobsTest` and `CommunicationsTest` pass.
- Formatted with Laravel Pint.
