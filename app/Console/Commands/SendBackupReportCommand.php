<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backup:report {--email= : Recipient email address for scheduled report}')]
#[Description('Send periodic database backup health and storage utilization report to admin.')]
class SendBackupReportCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService): int
    {
        $this->info('Sending database backup health report...');

        $recipient = $this->option('email') ?: null;
        $sent = $backupService->sendScheduledReport($recipient);

        if ($sent) {
            $this->info('Backup health report sent successfully.');

            return self::SUCCESS;
        }

        $this->warn('Backup health report could not be dispatched (email service disabled or no recipient configured).');

        return self::FAILURE;
    }
}
