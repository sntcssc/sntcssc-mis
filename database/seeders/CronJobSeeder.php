<?php

namespace Database\Seeders;

use App\Models\CronJob;
use Illuminate\Database\Seeder;

class CronJobSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultJobs = [
            [
                'name' => 'Prune Expired Team Invitations',
                'command' => 'teams:prune-invitations',
                'arguments' => null,
                'expression' => '0 0 * * *',
                'description' => 'Deletes expired team member invitations past their expiry date to keep records clean.',
                'is_active' => true,
                'run_in_background' => true,
                'without_overlapping' => true,
            ],
            [
                'name' => 'Clear Expired Password Reset Tokens',
                'command' => 'auth:clear-resets',
                'arguments' => null,
                'expression' => '0 */6 * * *',
                'description' => 'Flushes expired password reset tokens from the password_reset_tokens table.',
                'is_active' => true,
                'run_in_background' => true,
                'without_overlapping' => true,
            ],
            [
                'name' => 'Prune Failed Queue Jobs',
                'command' => 'queue:prune-failed',
                'arguments' => ['--hours' => 168],
                'expression' => '0 2 * * 0',
                'description' => 'Prunes failed job records older than 7 days from the failed_jobs database table.',
                'is_active' => true,
                'run_in_background' => true,
                'without_overlapping' => true,
            ],
            [
                'name' => 'Database Model Pruning',
                'command' => 'model:prune',
                'arguments' => null,
                'expression' => '0 3 * * *',
                'description' => 'Executes soft-deleted and prunable model pruning routines across application models.',
                'is_active' => true,
                'run_in_background' => true,
                'without_overlapping' => true,
            ],
        ];

        foreach ($defaultJobs as $job) {
            $cron = CronJob::updateOrCreate(
                ['command' => $job['command']],
                $job
            );

            if (! $cron->next_run_at) {
                $cron->update(['next_run_at' => $cron->calculateNextRunAt()]);
            }
        }
    }
}
