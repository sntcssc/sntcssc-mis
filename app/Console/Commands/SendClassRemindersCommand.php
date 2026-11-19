<?php

namespace App\Console\Commands;

use App\Services\ClassReminderService;
use Illuminate\Console\Command;

class SendClassRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'classes:send-reminders {--force : Force send even if automated reminders are disabled in settings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch automated start-of-class reminders to enrolled students via configured channels (Email, SMS, WhatsApp, Telegram)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $enabled = ClassReminderService::isEnabled();
        if (! $enabled && ! $this->option('force')) {
            $this->info('Class reminders are currently disabled in settings. Skipping.');

            return self::SUCCESS;
        }

        $leadTime = ClassReminderService::getLeadTimeMinutes();
        $channels = ClassReminderService::getConfiguredChannels();

        $this->info("Scanning scheduled classes starting within the next {$leadTime} minutes (Channels: ".implode(', ', $channels).')...');

        $count = ClassReminderService::dispatchDueReminders();

        $this->info("Successfully sent reminder alerts for {$count} scheduled class session(s).");

        return self::SUCCESS;
    }
}
