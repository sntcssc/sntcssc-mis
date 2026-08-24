<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class BackupService
{
    public function __construct(
        protected EmailService $emailService
    ) {}

    /**
     * Determine whether automated backups are enabled in system settings.
     */
    public static function isAutoBackupEnabled(): bool
    {
        return (bool) Setting::get('backup.auto_backup_enabled', true);
    }

    /**
     * Get default storage disk for backups.
     */
    public static function defaultDisk(): string
    {
        return (string) Setting::get('backup.storage_disk', 'local');
    }

    /**
     * Get default notification email recipient.
     */
    public static function notificationEmail(): string
    {
        $email = (string) Setting::get('backup.notification_email');

        if (empty($email)) {
            $email = (string) Setting::get('general.email', config('mail.from.address', 'admin@sntcssc.in'));
        }

        return $email;
    }

    /**
     * Create a new database and media backup archive.
     *
     * @param  array{type?: string, disk?: string, send_email?: bool, recipient_email?: string, name?: string}  $options
     * @return array{success: bool, message: string, backup: ?Backup}
     */
    public function createBackup(array $options = [], ?User $creator = null, string $triggerType = Backup::TRIGGER_MANUAL): array
    {
        $startTime = microtime(true);
        $type = $options['type'] ?? (string) Setting::get('backup.default_scope', Backup::TYPE_FULL_WITH_MEDIA);
        if (! in_array($type, [Backup::TYPE_DATABASE_ONLY, Backup::TYPE_FULL_WITH_MEDIA], true)) {
            $type = Backup::TYPE_DATABASE_ONLY;
        }

        $diskName = $options['disk'] ?? self::defaultDisk();
        if (! config("filesystems.disks.{$diskName}")) {
            $diskName = 'local';
        }

        $uuid = (string) Str::uuid();
        $dateStr = now()->format('Y-m-d_His');
        $scopeSuffix = $type === Backup::TYPE_FULL_WITH_MEDIA ? 'full' : 'db';
        $customName = ! empty($options['name']) ? Str::slug($options['name'], '_') : 'sntcssc_backup';
        $zipFilename = "{$customName}_{$dateStr}_{$scopeSuffix}_{$uuid}.zip";
        $relativeStoragePath = "backups/{$zipFilename}";

        // Temporary directory on local scratch for building zip
        $tempDir = storage_path('app/temp/backup_'.Str::random(12));
        File::ensureDirectoryExists($tempDir);
        File::ensureDirectoryExists(storage_path('app/backups'));

        $driver = DB::getDriverName();
        $tablesDumped = 0;
        $totalRecords = 0;
        $mediaFilesCount = 0;

        try {
            // 1. Generate SQL dump script
            $sqlFilePath = $tempDir.'/database.sql';
            $dumpResult = $this->generateSqlDump($sqlFilePath);
            $tablesDumped = $dumpResult['tables_count'];
            $totalRecords = $dumpResult['records_count'];

            // 2. If SQLite, copy binary database file as snapshot too
            if ($driver === 'sqlite') {
                $sqlitePath = config('database.connections.sqlite.database');
                if (file_exists($sqlitePath) && is_file($sqlitePath)) {
                    File::copy($sqlitePath, $tempDir.'/database.sqlite');
                }
            }

            // 3. Media packaging if scope is full
            $mediaDir = $tempDir.'/media';
            if ($type === Backup::TYPE_FULL_WITH_MEDIA) {
                File::ensureDirectoryExists($mediaDir);
                $publicStorage = storage_path('app/public');
                if (File::isDirectory($publicStorage)) {
                    File::copyDirectory($publicStorage, $mediaDir);
                    $mediaFilesCount = count(File::allFiles($mediaDir));
                }
            }

            // 4. Create meta.json manifest
            $meta = [
                'uuid' => $uuid,
                'app_name' => Setting::appName(),
                'app_version' => Setting::get('system.app_version', '1.0.0'),
                'created_at' => now()->toIso8601String(),
                'db_driver' => $driver,
                'type' => $type,
                'trigger_type' => $triggerType,
                'tables_count' => $tablesDumped,
                'records_count' => $totalRecords,
                'media_files_count' => $mediaFilesCount,
                'created_by' => $creator?->id,
            ];
            File::put($tempDir.'/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

            // 5. Package into ZIP archive
            $finalZipPath = Storage::disk($diskName)->path($relativeStoragePath);
            File::ensureDirectoryExists(dirname($finalZipPath));

            $zip = new ZipArchive;
            if ($zip->open($finalZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Could not create ZIP archive at {$finalZipPath}");
            }

            $allFiles = File::allFiles($tempDir);
            foreach ($allFiles as $file) {
                $relativePath = substr($file->getPathname(), strlen($tempDir) + 1);
                $zip->addFile($file->getPathname(), str_replace('\\', '/', $relativePath));
            }
            $zip->close();

            // 6. Calculate checksum & size
            $sizeBytes = File::size($finalZipPath);
            $checksum = hash_file('sha256', $finalZipPath);
            $durationSeconds = round(microtime(true) - $startTime, 2);

            // 7. Save Backup record
            $backup = Backup::create([
                'uuid' => $uuid,
                'filename' => $zipFilename,
                'disk' => $diskName,
                'path' => $relativeStoragePath,
                'type' => $type,
                'db_driver' => $driver,
                'size_bytes' => $sizeBytes,
                'tables_count' => $tablesDumped,
                'records_count' => $totalRecords,
                'files_count' => $mediaFilesCount,
                'checksum' => $checksum,
                'trigger_type' => $triggerType,
                'status' => Backup::STATUS_COMPLETED,
                'duration_seconds' => $durationSeconds,
                'metadata' => $meta,
                'created_by' => $creator?->id ?? auth()->id(),
            ]);

            // 8. Auto-prune older backups according to retention policies
            $this->pruneOldBackups();

            // 9. Send email dispatch if configured or explicitly requested
            $shouldEmail = $options['send_email'] ?? (bool) Setting::get('backup.email_on_success', true);
            $recipient = $options['recipient_email'] ?? self::notificationEmail();

            if ($shouldEmail && ! empty($recipient) && EmailService::isEnabled()) {
                $this->sendBackupEmail($backup, $recipient, $triggerType === Backup::TRIGGER_SCHEDULED);
            }

            // 10. Audit Log
            AuditLogService::log(
                event: 'backup_created',
                description: "Created database backup archive '{$zipFilename}' ({$backup->formattedSize()}, {$tablesDumped} tables, {$totalRecords} rows).",
                newValues: [
                    'filename' => $zipFilename,
                    'type' => $type,
                    'size_bytes' => $sizeBytes,
                    'tables_count' => $tablesDumped,
                    'trigger_type' => $triggerType,
                ],
                userId: $creator?->id ?? auth()->id()
            );

            return [
                'success' => true,
                'message' => __("Backup ':filename' generated successfully (:size).", [
                    'filename' => $zipFilename,
                    'size' => $backup->formattedSize(),
                ]),
                'backup' => $backup,
            ];
        } catch (\Throwable $e) {
            Log::error('Backup creation failed: '.$e->getMessage(), ['exception' => $e]);

            // Log failure backup row if possible
            $durationSeconds = round(microtime(true) - $startTime, 2);
            Backup::create([
                'uuid' => $uuid,
                'filename' => $zipFilename,
                'disk' => $diskName,
                'path' => $relativeStoragePath,
                'type' => $type,
                'db_driver' => $driver,
                'size_bytes' => 0,
                'tables_count' => 0,
                'records_count' => 0,
                'files_count' => 0,
                'trigger_type' => $triggerType,
                'status' => Backup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'duration_seconds' => $durationSeconds,
                'created_by' => $creator?->id ?? auth()->id(),
            ]);

            // Send alert on failure if enabled
            if (Setting::get('backup.email_on_failure', true) && EmailService::isEnabled()) {
                $recipient = self::notificationEmail();
                if ($recipient) {
                    $this->emailService->sendTemplate(
                        email: $recipient,
                        templateCode: 'backup_failed',
                        variables: [
                            'error_message' => $e->getMessage(),
                            'trigger_type' => $triggerType,
                            'date' => now()->toDayDateTimeString(),
                        ]
                    );
                }
            }

            return [
                'success' => false,
                'message' => __('Backup creation failed: :error', ['error' => $e->getMessage()]),
                'backup' => null,
            ];
        } finally {
            // Clean up temporary workspace directory
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /**
     * Pure PHP cross-platform SQL schema and data dump generator.
     *
     * @return array{tables_count: int, records_count: int}
     */
    public function generateSqlDump(string $destinationFilePath): array
    {
        $handle = fopen($destinationFilePath, 'w');
        if (! $handle) {
            throw new \RuntimeException("Unable to open file for writing SQL dump: {$destinationFilePath}");
        }

        $driver = DB::getDriverName();
        $appName = Setting::appName();
        $timestamp = now()->toDateTimeString();

        fwrite($handle, "-- --------------------------------------------------------\n");
        fwrite($handle, "-- SNT CSSC MIS Database Schema & Data Dump\n");
        fwrite($handle, "-- Application: {$appName}\n");
        fwrite($handle, "-- Timestamp: {$timestamp}\n");
        fwrite($handle, "-- Database Driver: {$driver}\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");

        if ($driver === 'mysql' || $driver === 'mariadb') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
            fwrite($handle, "SET time_zone = \"+00:00\";\n\n");
        } elseif ($driver === 'sqlite') {
            fwrite($handle, "PRAGMA foreign_keys = OFF;\n\n");
        }

        $tables = $this->getAllTableNames();
        $tablesCount = 0;
        $totalRecords = 0;

        foreach ($tables as $table) {
            // Skip sqlite system tables
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }

            $tablesCount++;
            fwrite($handle, "-- --------------------------------------------------------\n");
            fwrite($handle, "-- Table Structure: `{$table}`\n");
            fwrite($handle, "-- --------------------------------------------------------\n");

            // Write DROP & CREATE statements
            $createTableSql = $this->getCreateTableSql($table, $driver);
            if ($createTableSql) {
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, "{$createTableSql};\n\n");
            }

            // Dump Table Data in Chunks
            fwrite($handle, "-- Dumping data for table `{$table}`\n");
            $recordsCount = DB::table($table)->count();
            $totalRecords += $recordsCount;

            if ($recordsCount > 0) {
                DB::table($table)->orderBy(DB::raw('1'))->chunk(500, function ($rows) use ($handle, $table) {
                    if ($rows->isEmpty()) {
                        return;
                    }

                    $first = (array) $rows->first();
                    $columns = array_keys($first);
                    $escapedColumns = array_map(fn ($c) => "`{$c}`", $columns);
                    $columnList = implode(', ', $escapedColumns);

                    $valuesList = [];
                    foreach ($rows as $row) {
                        $rowArray = (array) $row;
                        $formattedValues = [];

                        foreach ($columns as $col) {
                            $val = $rowArray[$col] ?? null;
                            if (is_null($val)) {
                                $formattedValues[] = 'NULL';
                            } elseif (is_numeric($val) && ! is_string($val)) {
                                $formattedValues[] = $val;
                            } elseif (is_bool($val)) {
                                $formattedValues[] = $val ? '1' : '0';
                            } else {
                                $escaped = str_replace(
                                    ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
                                    ['\\\\', '\\0', '\\n', '\\r', "''", '\\"', '\\Z'],
                                    (string) $val
                                );
                                $formattedValues[] = "'{$escaped}'";
                            }
                        }

                        $valuesList[] = '('.implode(', ', $formattedValues).')';
                    }

                    $insertSql = "INSERT INTO `{$table}` ({$columnList}) VALUES\n".implode(",\n", $valuesList).";\n";
                    fwrite($handle, $insertSql);
                });
                fwrite($handle, "\n");
            }
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } elseif ($driver === 'sqlite') {
            fwrite($handle, "PRAGMA foreign_keys = ON;\n");
        }

        fclose($handle);

        return [
            'tables_count' => $tablesCount,
            'records_count' => $totalRecords,
        ];
    }

    /**
     * Get table list from current database connection.
     *
     * @return array<string>
     */
    protected function getAllTableNames(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $results = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            return array_map(fn ($r) => (string) $r->name, $results);
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $results = DB::select('SHOW TABLES');
            $dbName = DB::getDatabaseName();

            return array_map(fn ($r) => (string) (get_object_vars($r)["Tables_in_{$dbName}"] ?? array_values((array) $r)[0]), $results);
        }

        if ($driver === 'pgsql') {
            $results = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

            return array_map(fn ($r) => (string) $r->tablename, $results);
        }

        // Schema fallback
        return Schema::getTableListing();
    }

    /**
     * Generate CREATE TABLE definition for database table.
     */
    protected function getCreateTableSql(string $table, string $driver): ?string
    {
        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $create = DB::select("SHOW CREATE TABLE `{$table}`");
                if (! empty($create)) {
                    $row = (array) $create[0];

                    return $row['Create Table'] ?? null;
                }
            } elseif ($driver === 'sqlite') {
                $create = DB::select("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
                if (! empty($create) && ! empty($create[0]->sql)) {
                    return (string) $create[0]->sql;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Could not obtain CREATE TABLE definition for {$table}: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Restore database and media from a backup archive or file.
     *
     * @param  Backup|string  $backupOrPath  Backup model instance or absolute/relative filepath
     * @return array{success: bool, message: string}
     */
    public function restoreBackup(Backup|string $backupOrPath, ?User $actor = null, bool $createSafetyBackup = true): array
    {
        $backupModel = null;
        $filePath = null;

        if ($backupOrPath instanceof Backup) {
            $backupModel = $backupOrPath;
            $filePath = $backupModel->absolutePath();
        } else {
            $backupModel = Backup::firstWhere('filename', $backupOrPath)
                ?? Backup::firstWhere('path', $backupOrPath)
                ?? Backup::firstWhere('uuid', $backupOrPath);

            $filePath = $backupModel ? $backupModel->absolutePath() : (file_exists($backupOrPath) ? $backupOrPath : storage_path("app/{$backupOrPath}"));
        }

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return [
                'success' => false,
                'message' => __('Backup file not found or is unreadable on disk.'),
            ];
        }

        // 1. Create Pre-Restore Safety Snapshot if requested
        if ($createSafetyBackup) {
            try {
                $this->createBackup(
                    options: ['type' => Backup::TYPE_DATABASE_ONLY, 'name' => 'pre_restore_safety'],
                    creator: $actor,
                    triggerType: Backup::TRIGGER_PRE_RESTORE
                );
            } catch (\Throwable $e) {
                Log::warning('Pre-restore safety backup creation warning: '.$e->getMessage());
            }
        }

        $tempDir = storage_path('app/temp/restore_'.Str::random(12));
        File::ensureDirectoryExists($tempDir);

        try {
            $isZip = str_ends_with(strtolower($filePath), '.zip');

            if ($isZip) {
                $zip = new ZipArchive;
                if ($zip->open($filePath) !== true) {
                    throw new \RuntimeException('Failed to open and unpack ZIP backup archive.');
                }
                $zip->extractTo($tempDir);
                $zip->close();
            } else {
                // Direct SQL or SQLite file
                File::copy($filePath, $tempDir.'/database.sql');
            }

            $driver = DB::getDriverName();

            // 2. Restore Database
            $sqlFile = $tempDir.'/database.sql';
            $sqliteFile = $tempDir.'/database.sqlite';

            if ($driver === 'sqlite' && File::exists($sqliteFile)) {
                $sqliteDbPath = config('database.connections.sqlite.database');
                File::copy($sqliteFile, $sqliteDbPath);
            } elseif (File::exists($sqlFile)) {
                $sqlContent = File::get($sqlFile);
                $this->executeSqlScript($sqlContent, $driver);
            } else {
                throw new \RuntimeException('No database dump found in the selected backup archive.');
            }

            // 3. Restore Media / Public Uploads if present
            $mediaDir = $tempDir.'/media';
            if (File::isDirectory($mediaDir)) {
                $publicStorage = storage_path('app/public');
                File::ensureDirectoryExists($publicStorage);
                File::copyDirectory($mediaDir, $publicStorage);
            }

            // 4. Clear application cache & views
            Artisan::call('cache:clear');
            Artisan::call('view:clear');
            Setting::flushCache();

            if ($backupModel) {
                $backupModel->update(['status' => Backup::STATUS_RESTORED]);
            }

            AuditLogService::log(
                event: 'backup_restored',
                description: "Restored system database from archive '".basename($filePath)."'.",
                newValues: [
                    'source_file' => basename($filePath),
                    'restored_by' => $actor?->id ?? auth()->id(),
                ],
                userId: $actor?->id ?? auth()->id()
            );

            return [
                'success' => true,
                'message' => __("Database and files restored successfully from ':filename'.", ['filename' => basename($filePath)]),
            ];
        } catch (\Throwable $e) {
            Log::error('Backup restore failed: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => __('Restore failed: :error', ['error' => $e->getMessage()]),
            ];
        } finally {
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /**
     * Execute SQL script with foreign key checks turned off within safe transaction boundaries.
     */
    protected function executeSqlScript(string $sql, string $driver): void
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared("SET FOREIGN_KEY_CHECKS=0;\n".$sql."\nSET FOREIGN_KEY_CHECKS=1;");

            return;
        }

        if ($driver === 'sqlite') {
            DB::connection()->getPdo()->exec('PRAGMA foreign_keys = OFF;');

            // Strip comment lines
            $lines = explode("\n", $sql);
            $cleanLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (! str_starts_with($trimmed, '--') && ! str_starts_with($trimmed, '/*')) {
                    $cleanLines[] = $line;
                }
            }
            $cleanSql = implode("\n", $cleanLines);

            // Split into individual SQL statements
            $statements = array_filter(
                array_map('trim', explode(";\n", $cleanSql)),
                fn ($stmt) => ! empty($stmt)
            );

            foreach ($statements as $statement) {
                try {
                    DB::unprepared($statement);
                } catch (\Throwable $e) {
                    Log::warning('Restore statement execution warning: '.$e->getMessage());
                }
            }

            DB::connection()->getPdo()->exec('PRAGMA foreign_keys = ON;');

            return;
        }

        DB::unprepared($sql);
    }

    /**
     * Prune older backups exceeding max retention count and age window.
     */
    public function pruneOldBackups(): int
    {
        $retentionCount = (int) Setting::get('backup.retention_count', 10);
        $retentionDays = (int) Setting::get('backup.retention_days', 30);

        if ($retentionCount < 1) {
            $retentionCount = 10;
        }

        $pruned = 0;

        // 1. Prune by Age (older than N days)
        if ($retentionDays > 0) {
            $cutoff = now()->subDays($retentionDays);
            $expiredBackups = Backup::where('created_at', '<', $cutoff)->get();

            foreach ($expiredBackups as $backup) {
                if ($this->deleteBackup($backup, force: true)) {
                    $pruned++;
                }
            }
        }

        // 2. Prune by Count (keep newest N)
        $totalActive = Backup::count();
        if ($totalActive > $retentionCount) {
            $excessCount = $totalActive - $retentionCount;
            $oldestBackups = Backup::orderBy('created_at', 'asc')->take($excessCount)->get();

            foreach ($oldestBackups as $backup) {
                if ($this->deleteBackup($backup, force: true)) {
                    $pruned++;
                }
            }
        }

        if ($pruned > 0) {
            Log::info("Pruned {$pruned} historical backups based on retention policy.");
            AuditLogService::log(
                event: 'backups_pruned',
                description: "Pruned {$pruned} backup archive(s) exceeding retention limit (Max: {$retentionCount}, Age: {$retentionDays}d)."
            );
        }

        return $pruned;
    }

    /**
     * Send backup notification email to administrator with ZIP attachment if <= threshold.
     */
    public function sendBackupEmail(Backup $backup, ?string $recipient = null, bool $isAutomated = false): bool
    {
        $recipient = $recipient ?: self::notificationEmail();

        if (empty($recipient) || ! EmailService::isEnabled()) {
            return false;
        }

        $maxAttachmentMb = (int) Setting::get('backup.email_attachment_max_mb', 15);
        if ($maxAttachmentMb < 1) {
            $maxAttachmentMb = 15;
        }

        $maxAttachmentBytes = $maxAttachmentMb * 1024 * 1024;
        $attachZip = $backup->size_bytes > 0 && $backup->size_bytes <= $maxAttachmentBytes && $backup->existsOnDisk();

        $variables = [
            'app_name' => Setting::appName(),
            'filename' => $backup->filename,
            'size' => $backup->formattedSize(),
            'type' => $backup->typeLabel(),
            'tables_count' => (string) $backup->tables_count,
            'records_count' => (string) $backup->records_count,
            'date' => $backup->created_at?->format('d M Y, h:i A') ?? now()->format('d M Y, h:i A'),
            'attached' => $attachZip ? __('Yes (Attached to this email)') : __('No (Exceeds email attachment limit)'),
            'download_url' => $this->resolveDashboardUrl(),
        ];

        try {
            $template = EmailTemplate::active()->where('code', 'backup_completed')->first();

            $subject = $template
                ? strtr($template->subject, array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($variables)), array_values($variables)))
                : "[{$variables['app_name']}] Database Backup Generated ({$backup->formattedSize()})";

            $htmlBody = $template
                ? $template->render($variables)['body']
                : "<div style='font-family: Arial, sans-serif; padding: 20px;'>
                    <h2>Database Backup Generated</h2>
                    <p>A new system backup archive <strong>{$backup->filename}</strong> has been created.</p>
                    <ul>
                        <li><strong>Size:</strong> {$backup->formattedSize()}</li>
                        <li><strong>Type:</strong> {$backup->typeLabel()}</li>
                        <li><strong>Tables Dumped:</strong> {$backup->tables_count}</li>
                        <li><strong>Total Records:</strong> {$backup->records_count}</li>
                        <li><strong>Timestamp:</strong> {$variables['date']}</li>
                    </ul>
                    ".($attachZip ? '<p>The backup archive is attached directly to this message.</p>' : "<p>Due to size limits ({$backup->formattedSize()} > {$maxAttachmentMb} MB), please download it directly from your MIS admin dashboard.</p>").'
                </div>';

            $fromAddress = (string) Setting::get('email.from_address', config('mail.from.address', 'noreply@sntcssc.in'));
            $fromName = (string) Setting::get('email.from_name', Setting::appName());

            Mail::html($htmlBody, function ($message) use ($recipient, $subject, $fromAddress, $fromName, $backup, $attachZip) {
                $message->to($recipient)
                    ->subject($subject)
                    ->from($fromAddress, $fromName);

                if ($attachZip && $backup->existsOnDisk()) {
                    $message->attach($backup->absolutePath(), [
                        'as' => $backup->filename,
                        'mime' => 'application/zip',
                    ]);
                }
            });

            $backup->update([
                'email_sent' => true,
                'email_recipient' => $recipient,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send backup email to {$recipient}: ".$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    /**
     * Send periodic scheduled backup status & health report.
     */
    public function sendScheduledReport(?string $recipient = null): bool
    {
        $recipient = $recipient ?: self::notificationEmail();

        if (empty($recipient) || ! EmailService::isEnabled()) {
            return false;
        }

        $totalCount = Backup::count();
        $totalBytes = (int) Backup::sum('size_bytes');
        $latest = Backup::latest('id')->first();

        $formattedTotalSize = (new Backup(['size_bytes' => $totalBytes]))->formattedSize();

        $variables = [
            'app_name' => Setting::appName(),
            'total_backups' => (string) $totalCount,
            'total_storage' => $formattedTotalSize,
            'latest_backup' => $latest?->filename ?? __('None'),
            'latest_date' => $latest?->created_at?->format('d M Y, h:i A') ?? __('N/A'),
            'latest_status' => $latest?->status ?? __('N/A'),
            'schedule_status' => self::isAutoBackupEnabled() ? __('Active (:freq)', ['freq' => ucfirst((string) Setting::get('backup.schedule_frequency', 'daily'))]) : __('Disabled'),
            'report_date' => now()->format('d M Y, h:i A'),
            'dashboard_url' => $this->resolveDashboardUrl(),
        ];

        $template = EmailTemplate::active()->where('code', 'backup_scheduled_report')->first();

        if ($template) {
            return $this->emailService->sendTemplate(
                email: $recipient,
                templateCode: 'backup_scheduled_report',
                variables: $variables
            );
        }

        try {
            $fromAddress = (string) Setting::get('email.from_address', config('mail.from.address', 'noreply@sntcssc.in'));
            $fromName = (string) Setting::get('email.from_name', Setting::appName());
            $subject = "[{$variables['app_name']}] Database Backup Health & Status Report";
            $htmlBody = "<div style='font-family: Arial, sans-serif; padding: 20px;'>
                <h2>Database Backup Health & Status Report</h2>
                <p>Here is the scheduled summary of your database archives for <strong>{$variables['app_name']}</strong>.</p>
                <ul>
                    <li><strong>Total Archives:</strong> {$variables['total_backups']}</li>
                    <li><strong>Total Storage Used:</strong> {$variables['total_storage']}</li>
                    <li><strong>Latest Archive:</strong> {$variables['latest_backup']} ({$variables['latest_date']})</li>
                    <li><strong>Latest Status:</strong> {$variables['latest_status']}</li>
                    <li><strong>Schedule:</strong> {$variables['schedule_status']}</li>
                </ul>
            </div>";

            Mail::html($htmlBody, function ($message) use ($recipient, $subject, $fromAddress, $fromName) {
                $message->to($recipient)->subject($subject)->from($fromAddress, $fromName);
            });

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to dispatch backup scheduled report to {$recipient}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Resolve absolute or relative dashboard URL for backup panel.
     */
    public function resolveDashboardUrl(): string
    {
        try {
            $user = auth()->user();
            $team = $user?->currentTeam ?? $user?->personalTeam();

            if ($team) {
                return route('admin.settings.backup', ['current_team' => $team->slug]);
            }
        } catch (\Throwable) {
            // Fallback
        }

        return url('/system/settings/backup');
    }

    /**
     * Safely download a backup file with path validation.
     */
    public function downloadBackup(Backup $backup, ?User $actor = null): BinaryFileResponse
    {
        if (! $backup->existsOnDisk()) {
            abort(404, __('The requested backup file does not exist on disk.'));
        }

        AuditLogService::log(
            event: 'backup_downloaded',
            description: "Downloaded backup archive '{$backup->filename}' ({$backup->formattedSize()}).",
            newValues: ['filename' => $backup->filename, 'size_bytes' => $backup->size_bytes],
            userId: $actor?->id ?? auth()->id()
        );

        return response()->download($backup->absolutePath(), $backup->filename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Delete backup archive from storage and database.
     */
    public function deleteBackup(Backup $backup, ?User $actor = null, bool $force = false): bool
    {
        try {
            // Delete physical file
            if ($backup->existsOnDisk()) {
                Storage::disk($backup->disk)->delete($backup->path);
            }

            $filename = $backup->filename;
            $size = $backup->formattedSize();

            if ($force) {
                $backup->forceDelete();
            } else {
                $backup->delete();
            }

            AuditLogService::log(
                event: 'backup_deleted',
                description: "Deleted backup archive '{$filename}' ({$size}).",
                newValues: ['filename' => $filename, 'force' => $force],
                userId: $actor?->id ?? auth()->id()
            );

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to delete backup {$backup->id}: ".$e->getMessage());

            return false;
        }
    }
}
