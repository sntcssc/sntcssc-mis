<?php

use App\Models\CronJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

try {
    if (Schema::hasTable('cron_jobs')) {
        $jobs = CronJob::active()->get();

        foreach ($jobs as $job) {
            $event = Schedule::call(function () use ($job) {
                $job->run();
            })
                ->cron($job->expression)
                ->name($job->name);

            if ($job->description) {
                $event->description($job->description);
            }

            if ($job->run_in_background) {
                $event->runInBackground();
            }

            if ($job->without_overlapping) {
                $event->withoutOverlapping();
            }
        }
    }
} catch (Throwable $e) {
    Log::warning('Could not register database cron jobs in console schedule: '.$e->getMessage());
}
