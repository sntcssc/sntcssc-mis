<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backup:run {--type= : Backup type (database_only or full_with_media)} {--disk= : Destination storage disk} {--email= : Recipient email address for backup notification} {--no-email : Do not dispatch email notification}')]
#[Description('Execute automated or manual database and media files backup routine.')]
class RunDatabaseBackupCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService): int
    {
        $this->info('Starting SNT CSSC MIS database and files backup...');

        $type = $this->option('type') ?: null;
        $disk = $this->option('disk') ?: null;
        $recipient = $this->option('email') ?: null;
        $sendEmail = ! $this->option('no-email');

        $options = array_filter([
            'type' => $type,
            'disk' => $disk,
            'recipient_email' => $recipient,
            'send_email' => $sendEmail,
        ], fn ($val) => ! is_null($val));

        $result = $backupService->createBackup(
            options: $options,
            triggerType: Backup::TRIGGER_SCHEDULED
        );

        if ($result['success']) {
            $this->info($result['message']);
            /** @var Backup $backup */
            $backup = $result['backup'];
            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Filename', $backup->filename],
                    ['Type', $backup->typeLabel()],
                    ['Size', $backup->formattedSize()],
                    ['Tables Dumped', $backup->tables_count],
                    ['Total Records', $backup->records_count],
                    ['Media Files', $backup->files_count],
                    ['Duration', "{$backup->duration_seconds}s"],
                    ['Checksum', substr($backup->checksum, 0, 16).'...'],
                ]
            );

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
