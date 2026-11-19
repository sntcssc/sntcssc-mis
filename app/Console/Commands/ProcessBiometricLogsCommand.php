<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceService;
use Illuminate\Console\Command;

class ProcessBiometricLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:process-biometric {--limit=500 : Batch size of unprocessed punches to parse}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process raw biometric punch logs from machines into daily student and staff attendance records';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceService $attendanceService): int
    {
        $limit = (int) $this->option('limit');
        $this->info("Processing up to {$limit} raw biometric punches...");

        $result = $attendanceService->processBiometricPunches($limit);

        $this->info("Biometric punch processing completed. Punches processed: {$result['processed']}, Errors: {$result['errors']}.");

        return self::SUCCESS;
    }
}
