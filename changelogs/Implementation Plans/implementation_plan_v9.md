# Implementation Plan: Database Backup & Restore System

This plan outlines the architecture and implementation for an enterprise-grade **Database Backup and Restore Management System** with automated schedules (Daily/Weekly/Monthly), media packaging, configurable retention windows, email delivery with attachment size thresholds, on-demand backup triggers, restore safeguards, and a responsive administrative interface.

---

## User Review Required

> [!IMPORTANT]
> - **Cross-Platform Database Engine**: The dump engine uses a pure PHP PDO table-by-table schema and data generator with batch chunking. This guarantees reliable execution across SQLite, MySQL, and PostgreSQL environments without requiring external binary dependencies (`mysqldump`, `pg_dump`). For SQLite, binary database snapshot packaging is also included.
> - **Email Attachment Threshold**: Backups equal to or below the configurable limit (default **15 MB**) are directly attached to the notification email as a `.zip`. Backups larger than the limit send a rich status report with metadata summary (tables dumped, records count, file size) and a secure reminder to download from the Admin panel.
> - **Pre-Restore Safety Snapshot**: When initiating a database restore, the system can automatically create a pre-restore safety snapshot before applying changes inside a transactional rollback boundary.

---

## Proposed Changes

### 1. Database Schema & Migration

#### [NEW] [Migration: `database/migrations/2026_08_25_000001_create_backups_table.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_25_000001_create_backups_table.php)
- Creates the `backups` table tracking:
  - `uuid`, `filename`, `disk`, `path`, `type` (`database_only`, `full_with_media`)
  - `db_driver`, `size_bytes`, `tables_count`, `records_count`, `files_count`, `checksum`
  - `trigger_type` (`manual`, `scheduled`, `api`, `pre_restore`), `status` (`completed`, `failed`, `running`, `restored`), `error_message`, `duration_seconds`, `metadata` (JSON)
  - `email_sent`, `email_recipient`, `created_by`, `deleted_by`, `deleted_at` (`SoftDeletes`), `timestamps`

#### [NEW] [`app/Models/Backup.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Backup.php)
- Eloquent Model with `Auditable`, `HasFactory`, `SoftDeletes`.
- Helper methods: `formattedSize()`, `statusBadgeColor()`, `typeLabel()`, `isZip()`, `existsOnDisk()`, `absolutePath()`.

---

### 2. Core Service & Artisan Commands

#### [NEW] [`app/Services/BackupService.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/BackupService.php)
- **`createBackup(array $options = [], ?User $creator = null, string $triggerType = 'manual'): array`**:
  - Dumps database schema (`CREATE TABLE`, indexes) and table rows (`INSERT INTO`).
  - If `$scope === 'full_with_media'`, recursively packages `storage/app/public/` (logos, avatars, student photos, documents).
  - Compresses into `.zip` with `meta.json` and checksum verification.
  - Automatically executes `pruneOldBackups()` against retention limits.
  - Emails copy/report based on attachment threshold.
  - Logs `backup_created` in `AuditLogService`.
- **`restoreBackup(Backup|string $backup, ?User $actor = null, bool $createSafetyBackup = true): array`**:
  - Validates archive integrity.
  - Creates pre-restore safety snapshot.
  - Executes SQL statements within a database transaction with foreign keys temporarily deferred.
  - Restores media files if present in the archive.
  - Flushes application caches and records `backup_restored` audit log.
- **`pruneOldBackups(): int`**:
  - Cleans up backups exceeding retention count (e.g. keep last N) or age (e.g. keep for N days).
- **`sendBackupEmail(Backup $backup, ?string $recipient = null, bool $isAutomated = false): bool`**:
  - Checks file size vs `backup.email_attachment_max_mb`. Attaches file if below threshold; otherwise sends summary with download link.
- **`sendScheduledReport(?string $recipient = null): bool`**:
  - Compiles backup health report (latest run, total archive volume, storage health).

#### [NEW] [Artisan Commands](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Console/Commands/)
- [`RunDatabaseBackupCommand.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Console/Commands/RunDatabaseBackupCommand.php): `php artisan app:backup:run` (run backup via CLI/scheduler).
- [`SendBackupReportCommand.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Console/Commands/SendBackupReportCommand.php): `php artisan app:backup:report` (send scheduled report).
- [`CleanOldBackupsCommand.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Console/Commands/CleanOldBackupsCommand.php): `php artisan app:backup:clean` (prune old archives).

#### [MODIFY] [`routes/console.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/console.php) & [`database/seeders/CronJobSeeder.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/CronJobSeeder.php)
- Register automated backup jobs in the Cron Jobs table (`app:backup:run`, `app:backup:report`).

---

### 3. Admin Livewire UI & Navigation

#### [NEW] [`resources/views/pages/admin/settings/⚡backup.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/settings/%E2%9A%A1backup.blade.php)
- **Top Stats Summary**: Total Backups, Total Disk Storage, Latest Backup Relative Time, Automated Schedule Status.
- **Header Actions**:
  - **"Backup Now"**: Interactive modal with options (Database only vs Full with Media, Email Notification toggle).
  - **"Upload Backup File"**: Upload an external `.sql` or `.zip` backup to archive or restore.
  - **"Backup Settings"**: Configure schedule frequency (Daily/Weekly/Monthly), retention window (Count/Days), storage disk, notification email, attachment threshold (MB).
  - **"Send Health Report"**: Send immediate status report email.
- **Backups Table (Sorted Newest First)**:
  - Search, scope filter (`All`, `Database Only`, `Full with Media`), status filter.
  - Checkbox selection for bulk deletion.
  - Action buttons per row: **Download**, **Restore** (with confirmation modal), **Email Copy**, **Inspect Details**, **Delete**.
  - Mobile card views and full dark/light mode support.

#### [MODIFY] [`resources/views/components/settings-nav.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/settings-nav.blade.php)
- Add **"Database & Backups"** tab with `database` icon.

#### [MODIFY] [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php) & [`routes/web.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register route `Route::livewire('system/settings/backup', 'pages::admin.settings.backup')->name('admin.settings.backup');`.
- Add Backups link in system settings navigation.

---

### 4. Email Templates & Seeders

#### [MODIFY] [`database/seeders/MessageTemplateSeeder.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/MessageTemplateSeeder.php)
- Seed email templates:
  - `backup_completed`: HTML notification with backup size, tables count, and download link.
  - `backup_failed`: Urgent alert for backup execution failure.
  - `backup_scheduled_report`: Periodic health and storage summary report.

---

### 5. Multi-Language Translations

#### [MODIFY] [`lang/en.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/en.json), [`lang/hi.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/hi.json), [`lang/bn.json`](file:///c:/Users/nilan/Downloads/sntcssc-mis/lang/bn.json)
- Add all localization keys for backup management, restore confirmations, retention settings, and status messages.

---

## Verification Plan

### Automated Tests
- Create [`tests/Feature/Admin/BackupManagementTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/Admin/BackupManagementTest.php):
  - Test on-demand database backup creation (generates valid file, records `Backup` entry and `AuditLog`).
  - Test full backup including media files (`storage/app/public`).
  - Test backup restoration (schema & data restored, caches flushed, audit log recorded).
  - Test retention pruning removes archives exceeding maximum count or age.
  - Test backup file download with path traversal security.
  - Test backup email notification with attachment size threshold logic.
  - Test Livewire component state, modal actions, and settings updates.
- Run full test suite: `php artisan test --compact`

### Manual Verification
- Trigger an on-demand backup from the UI and verify zip download.
- Test restore confirmation flow and verify data integrity.
- Update backup settings (retention, schedule, email) and verify changes persist.
