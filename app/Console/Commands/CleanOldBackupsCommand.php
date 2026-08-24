<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backup:clean')]
#[Description('Prune historical database backup archives exceeding retention count and age constraints.')]
class CleanOldBackupsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService): int
    {
        $this->info('Evaluating backup retention policy and pruning expired archives...');

        $pruned = $backupService->pruneOldBackups();

        $this->info("Pruned {$pruned} historical backup archive(s).");

        return self::SUCCESS;
    }
}
