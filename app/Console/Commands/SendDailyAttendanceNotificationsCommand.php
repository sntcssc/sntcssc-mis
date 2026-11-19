<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Attendance\AttendanceNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendDailyAttendanceNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:daily-notify {--date= : Target attendance date YYYY-MM-DD} {--force : Bypass enabled setting toggle}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch daily attendance presence and absence notices to students and users via SMS, WhatsApp, Email, and in-app';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceNotificationService $notificationService): int
    {
        $enabled = (bool) Setting::get('attendance.daily_auto_notify_enabled', false);

        if (! $enabled && ! $this->option('force')) {
            $this->info('Daily automated attendance notification is currently disabled in Attendance Settings.');

            return self::SUCCESS;
        }

        $date = $this->option('date') ?: Carbon::today()->toDateString();
        $this->info("Dispatching daily attendance notices for date: {$date}...");

        $result = $notificationService->dispatchDailyAbsenteeDigest($date);

        $this->info("Completed. Total notifications sent: {$result['total_sent']}, Failed: {$result['failed']}.");

        return self::SUCCESS;
    }
}
