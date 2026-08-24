# Enterprise-Grade Database Backup & Restore Management System with Automated Scheduling, Retention & Off-site Email Delivery

**Date:** 25 August 2026  
**Status:** Completed & Verified (273/273 Automated Tests Passing, 990 Assertions)

---

## 1. Executive Summary

This release introduces a production-ready **Database Backup & Disaster Recovery Management System** for SNT CSSC MIS. The module provides full automation, cross-database snapshot generation, public media bundling, configurable retention pruning, email delivery with dynamic attachment thresholds, scheduled health reports, and one-click disaster recovery with automatic pre-restore safety snapshots.

---

## 2. Key Architecture & Features

### A. Database Schema & Models
- **`backups` Table Migration (`2026_08_24_185710_create_backups_table.php`)**:
  - `uuid`, `filename`, `disk`, `path`
  - `type` (`database_only`, `full_with_media`)
  - `db_driver` (`sqlite`, `mysql`, `pgsql`, `mariadb`)
  - `size_bytes`, `tables_count`, `records_count`, `files_count`, `checksum` (SHA-256)
  - `trigger_type` (`manual`, `scheduled`, `pre_restore`, `api`)
  - `status` (`completed`, `failed`, `restored`)
  - `duration_seconds`, `metadata` (JSON)
  - `email_sent`, `email_recipient`, `created_by`, `deleted_by`, `deleted_at`, timestamps
- **`App\Models\Backup`**:
  - Integrates `Auditable`, `SoftDeletes`, `HasFactory`.
  - Helpers: `formattedSize()`, `statusBadgeColor()`, `typeLabel()`, `triggerLabel()`, `absolutePath()`, `existsOnDisk()`, `isZip()`.

### B. Core Backup Engine (`App\Services\BackupService`)
- **Cross-Platform SQL & Binary Dumps**:
  - SQLite binary database snapshotting and pure PHP table schema/data chunk extraction with DDL and DML generation.
  - Media inclusion from `storage/app/public` when `full_with_media` scope is selected.
  - Manifest generation (`meta.json`) with UUID, timestamp, database version, table counts, row counts, and file counts.
  - Zip compression, SHA-256 hash checksum calculation, and execution duration profiling.
- **Transactional Disaster Recovery & Safe Restore**:
  - Unpacks `.zip` or direct `.sql`/`.sqlite` files.
  - Automatic **Pre-Restore Safety Snapshot** generation (`backup.create_safety_snapshot`) before applying changes.
  - Foreign key bypass and safe statement execution across SQLite, MySQL, and PostgreSQL.
  - Media synchronization back into `storage/app/public`.
  - Application cache flushes upon successful restore and status updating.
- **Intelligent Retention & Pruning**:
  - `retention_count` (e.g. keep max 10 latest backups).
  - `retention_days` (e.g. purge archives older than 30 days).
  - Soft delete database records and disk file unlinking.
- **Automated Email Dispatch & Size Attachment Thresholding**:
  - If archive size $\le$ threshold (`backup.email_attachment_max_mb`, default 15MB), the `.zip` archive is attached directly to transactional email.
  - If archive size $> 15\text{MB}$, an email completion summary with a direct dashboard download link is dispatched.
  - Automated failure notification alerts when backup errors occur.
  - Scheduled health and status reports (`backup_scheduled_report`).

### C. Artisan CLI & Internal Cron Scheduler
- **Commands**:
  - `php artisan app:backup:run [--type=] [--disk=] [--email=] [--no-email]`: Generates backup archive.
  - `php artisan app:backup:clean`: Prunes backups older or in excess of configured retention policy.
  - `php artisan app:backup:report [--email=]`: Compiles and dispatches backup health summary report.
- **Cron Jobs (`CronJobSeeder`)**:
  - Automated daily backup: `app:backup:run` (expression: `0 2 * * *`).
  - Automated daily cleanup: `app:backup:clean` (expression: `30 2 * * *`).
  - Automated weekly status report: `app:backup:report` (expression: `0 8 * * 1`).

### D. Modern Livewire UI / UX (`pages/admin/settings/⚡backup.blade.php`)
- **Real-Time Analytics Grid**:
  - Total Archives count with retention badge.
  - Storage volume utilized with active disk badge.
  - Latest backup timestamp and filename.
  - Internal Cron scheduler status and next run timer.
- **Data Table (Newest First)**:
  - Archive name, UUID, Scope badge, Size, Tables/Rows count, Trigger label, Creation timestamp, and Status.
  - Search by filename or UUID, Filter by Scope (`database_only` vs `full_with_media`), Filter by Status (`completed`, `failed`, `restored`).
  - Single and bulk selection checkbox actions with bulk delete modal.
- **Interactive Modals**:
  1. **Backup Now Modal**: Select scope, optional label, destination disk, and custom recipient email.
  2. **Backup Settings & Retention Modal**: Manage cron schedule frequency (daily/weekly/monthly), time, retention count, retention days, default scope, storage disk, email notification switches, and attachment max MB threshold.
  3. **Restore Confirmation Modal**: Visual warning with pre-restore safety snapshot toggle.
  4. **Upload Archive Modal**: Drag-and-drop or select external `.zip`/`.sql` file with optional immediate restore toggle.
  5. **Email Copy Modal**: Send instant email notification and attachment to custom address.
  6. **Inspect Manifest Modal**: View UUID, SHA-256 checksum, storage path, execution duration, and database engine.
  7. **Delete & Bulk Delete Modals**: Confirm destructive operations safely.

### E. Navigation & Multi-Language Translations
- Added "Database & Backups" tab in Settings Top Navbar (`resources/views/components/settings-nav.blade.php`).
- Added "Database & Backups" link in App Sidebar under `SYSTEM` (`resources/views/layouts/app/sidebar.blade.php`).
- Registered routes `admin.settings.backup` (`/system/settings/backup`) and `admin.backups.index` (`/system/backups`).
- Added 87 translation keys to `lang/en.json`, `lang/hi.json`, and `lang/bn.json`.

---

## 3. Verification & Automated Testing

- **Feature Test Suite (`tests/Feature/Admin/BackupManagementTest.php`)**:
  - `administrator can create a database-only backup archive` (Passed)
  - `administrator can create a full backup archive with media files` (Passed)
  - `administrator can restore database from backup archive` (Passed)
  - `backup retention policy prunes archives exceeding count limit` (Passed)
  - `email notification is dispatched when backup is created` (Passed)
  - `scheduled health report is dispatched to configured administrator email` (Passed)
  - `livewire database backups page allows searching, filtering, and settings updates` (Passed)
  - `livewire component allows single and bulk deletion of backup archives` (Passed)
  - `artisan backup commands execute cleanly` (Passed)
- **Full Test Suite Status**: **273/273 tests passed** with **990 assertions**.
- **Code Standards**: Formatted according to Laravel Pint (`vendor/bin/pint --format agent`).