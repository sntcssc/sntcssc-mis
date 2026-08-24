<?php

namespace App\Models;

use App\Concerns\Auditable;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

class CronJob extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RUNNING = 'running';

    protected $fillable = [
        'name',
        'command',
        'arguments',
        'expression',
        'description',
        'is_active',
        'run_in_background',
        'without_overlapping',
        'last_run_at',
        'last_run_status',
        'last_run_duration',
        'last_run_output',
        'next_run_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'is_active' => 'boolean',
            'run_in_background' => 'boolean',
            'without_overlapping' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_duration' => 'float',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope to active scheduled jobs.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to jobs whose last execution failed.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('last_run_status', self::STATUS_FAILED);
    }

    /**
     * Scope to jobs whose last execution succeeded.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('last_run_status', self::STATUS_SUCCESS);
    }

    /**
     * Calculate next run datetime from cron expression.
     */
    public function calculateNextRunAt(): ?\DateTimeInterface
    {
        try {
            $cron = new CronExpression($this->expression);

            return $cron->getNextRunDate();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return human-friendly description of the schedule expression.
     */
    public function getHumanFrequency(): string
    {
        return match (trim($this->expression)) {
            '* * * * *' => 'Every Minute',
            '*/2 * * * *' => 'Every 2 Minutes',
            '*/5 * * * *' => 'Every 5 Minutes',
            '*/10 * * * *' => 'Every 10 Minutes',
            '*/15 * * * *' => 'Every 15 Minutes',
            '*/30 * * * *' => 'Every 30 Minutes',
            '0 * * * *' => 'Hourly (at minute 0)',
            '0 */2 * * *' => 'Every 2 Hours',
            '0 */6 * * *' => 'Every 6 Hours',
            '0 */12 * * *' => 'Every 12 Hours',
            '0 0 * * *' => 'Daily at Midnight (00:00)',
            '0 2 * * *' => 'Daily at 2:00 AM',
            '0 3 * * *' => 'Daily at 3:00 AM',
            '0 0 * * 0' => 'Weekly on Sunday (00:00)',
            '0 0 1 * *' => 'Monthly on 1st (00:00)',
            '0 0 1 1 *' => 'Yearly on Jan 1st',
            default => 'Custom ('.$this->expression.')',
        };
    }

    /**
     * Execute the cron job command and record metrics.
     *
     * @return array{success: bool, output: string, duration: float}
     */
    public function run(): array
    {
        $startTime = microtime(true);
        $this->update(['last_run_status' => self::STATUS_RUNNING]);

        $outputBuffer = new BufferedOutput;
        $exitCode = 0;

        try {
            // Check if command is an internal closure identifier or artisan command
            if ($this->command === 'teams:prune-invitations') {
                $deletedCount = TeamInvitation::query()
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->delete();
                $outputBuffer->writeln("Pruned {$deletedCount} expired team invitation(s).");
            } else {
                $args = (array) ($this->arguments ?? []);
                $exitCode = Artisan::call($this->command, $args, $outputBuffer);
            }

            $duration = round(microtime(true) - $startTime, 3);
            $outputText = trim($outputBuffer->fetch());
            $success = $exitCode === 0;

            $this->update([
                'last_run_at' => now(),
                'last_run_status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILED,
                'last_run_duration' => $duration,
                'last_run_output' => $outputText ?: ($success ? 'Command executed successfully with code 0.' : 'Command failed with exit code '.$exitCode),
                'next_run_at' => $this->calculateNextRunAt(),
            ]);

            return [
                'success' => $success,
                'output' => $this->last_run_output,
                'duration' => $duration,
            ];
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 3);
            $errorMessage = "Execution Exception: {$e->getMessage()}\n{$e->getTraceAsString()}";
            Log::error("Cron job '{$this->name}' failed: ".$e->getMessage(), ['exception' => $e]);

            $this->update([
                'last_run_at' => now(),
                'last_run_status' => self::STATUS_FAILED,
                'last_run_duration' => $duration,
                'last_run_output' => $errorMessage,
                'next_run_at' => $this->calculateNextRunAt(),
            ]);

            return [
                'success' => false,
                'output' => $errorMessage,
                'duration' => $duration,
            ];
        }
    }
}
