# Implementation Plan: OTP Cooldown Rounding, Queued Communications & Admin Cron Jobs Management

This plan details:
1. **Fixing OTP Cooldown Decimal Formatting**: Ensuring all cooldown wait periods and rate limit warnings display clean rounded integers (`(int) ceil(...)`) without fractional digits.
2. **Queued Jobs / Event Architecture for Communications**:
   - Introducing asynchronous `SendQueuedEmailJob`, `SendQueuedSmsJob`, and `DispatchCommunicationCampaignJob` for regular emails, notices, broadcasts, and bulk campaigns to improve web request throughput and application performance.
   - Maintaining **synchronous, instant delivery** for all OTP verification codes and login OTPs.
3. **Enterprise Cron Jobs & Scheduled Tasks Management System**:
   - Database schema and Eloquent model `CronJob` with `SoftDeletes`, audit tracking, and dynamic integration into Laravel's console scheduler.
   - Comprehensive Admin Panel Dashboard:
     * Real-time metrics (Total Jobs, Active Jobs, Failed Tasks, Next Run schedule).
     * Interactive table with cron expression helpers, status toggles, last run duration, and status indicators.
     * Modal to create and edit tasks with cron expression generator presets (Every 5 mins, Hourly, Daily, Weekly, Custom).
     * **"Run Now"** feature allowing admins to manually trigger any scheduled task on demand with live output logs.
     * Soft delete, trash view, and restore capabilities.
4. **Automated Testing & Documentation**:
   - Pest test suite covering OTP cooldown integers, queued jobs, cron job execution, and admin Livewire components.
   - Detailed changelog entry under `changelogs/`.

---

## User Review Required

> [!IMPORTANT]
> **New Database Table**: A migration `create_cron_jobs_table.php` will be created to store scheduled commands, execution logs, cron expressions, and execution metadata.

> [!NOTE]
> **Instant vs Queued Messages**:
> - OTPs will continue executing immediately (synchronously) for zero-delay authentication and security verification.
> - Background campaigns, notices, and bulk dispatches can now be queued asynchronously using Laravel's queue worker (`queue:work` or database/redis queue driver).

---

## Proposed Changes

### 1. OTP Cooldown Rounding

#### [MODIFY] [OtpService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/OtpService.php)
- Cast and calculate `wait_seconds` using `(int) ceil(...)` in both `checkCooldown()` and RateLimiter returns.

---

### 2. Queued Jobs & Asynchronous Message Dispatches

#### [NEW] [SendQueuedEmailJob.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Jobs/SendQueuedEmailJob.php)
- Queued job implementing `ShouldQueue` to dispatch outbound transactional and broadcast emails asynchronously.

#### [NEW] [SendQueuedSmsJob.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Jobs/SendQueuedSmsJob.php)
- Queued job implementing `ShouldQueue` to dispatch outbound SMS messages asynchronously.

#### [NEW] [DispatchCommunicationCampaignJob.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Jobs/DispatchCommunicationCampaignJob.php)
- Queued job to process large recipient lists in the background without tying up HTTP requests.

#### [MODIFY] [CommunicationService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/CommunicationService.php)
- Add methods `queueEmail()`, `queueSms()`, and `queueCampaign()` while keeping `logAndSendEmail()` / `logAndSendSms()` synchronous for instant requirements like OTPs.

---

### 3. Cron Jobs & Scheduled Tasks Management

#### [NEW] [create_cron_jobs_table.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_24_000003_create_cron_jobs_table.php)
- Columns: `id`, `name`, `command`, `arguments` (json nullable), `expression` (string), `description` (text nullable), `is_active` (boolean default true), `run_in_background` (boolean default true), `without_overlapping` (boolean default true), `last_run_at`, `last_run_status`, `last_run_duration`, `last_run_output` (longText nullable), `next_run_at`, `created_by`, `updated_by`, `deleted_at`, `timestamps`.

#### [NEW] [CronJob.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/CronJob.php)
- Model with `SoftDeletes`, `Auditable`, cron expression parser, next run calculator, execution helper (`run()`), and query scopes (`active()`, `due()`).

#### [NEW] [CronJobSeeder.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/CronJobSeeder.php)
- Seed essential scheduled system maintenance tasks (e.g. prune expired team invitations, clear expired password reset tokens, prune failed jobs, database health check).

#### [MODIFY] [console.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/console.php)
- Dynamically load active `CronJob` entries from the database into Laravel's `Schedule` facade!

#### [NEW] [⚡cron-jobs.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/system/%E2%9A%A1cron-jobs.blade.php)
- Livewire 3 component for Cron Job Management:
  - Metric summary cards (Total Jobs, Active, Failed, Next Run).
  - Search, filter by status, and trash view.
  - Interactive table displaying task name, command, schedule expression badge, status toggle, last run duration & output, next run schedule, and action buttons.
  - **"Run Now"** button with real-time execution feedback and output inspector modal.
  - Create / Edit modal with human-friendly frequency presets (Every 5 mins, Hourly, Daily, Weekly, Monthly, Custom) and expression helpers.
  - Soft delete and restore.

#### [MODIFY] [sidebar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php) & [web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register route `system/cron-jobs` and add "Cron Jobs" under the `SYSTEM` section in the sidebar.

---

### 4. Verification & Testing

#### [NEW] [CronJobsTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/CronJobsTest.php)
- Test suite verifying:
  1. OTP cooldown returns integer seconds without decimals.
  2. Queued jobs dispatch asynchronously while OTP remains synchronous.
  3. Cron jobs creation, updating, execution (`run()`), next run calculation, output capturing, and soft deletion.
  4. Admin Livewire UI interactions for cron job management.

---

## Verification Plan

### Automated Tests
```bash
php artisan test --compact tests/Feature/CronJobsTest.php tests/Feature/CommunicationsTest.php
php artisan test --compact
```

### Code Quality Check
```bash
vendor/bin/pint --format agent
```
