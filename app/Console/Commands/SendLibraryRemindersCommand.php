<?php

namespace App\Console\Commands;

use App\Models\BookIssue;
use App\Models\Setting;
use App\Services\Library\LibraryPolicyService;
use App\Services\Library\LibraryReminderService;
use Illuminate\Console\Command;

class SendLibraryRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'library:send-reminders {--force : Force send even if automated reminders are disabled in settings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch automated due-soon and overdue return reminders to library borrowers via In-App, Email, SMS, WhatsApp, and Telegram';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $enabled = (bool) Setting::get('library.auto_reminders_enabled', true);
        if (! $enabled && ! $this->option('force')) {
            $this->info('Library automated reminders are currently disabled in settings. Skipping.');

            return self::SUCCESS;
        }

        $reminderDaysBefore = LibraryPolicyService::getReminderDaysBefore();
        $channels = LibraryPolicyService::getReminderChannels();

        $this->info('Scanning library loans for return reminders (Channels: '.implode(', ', $channels).')...');

        // 1. Mark overdue issues
        $overdueLoans = BookIssue::whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_RENEWED])
            ->whereDate('due_date', '<', now()->toDateString())
            ->get();

        foreach ($overdueLoans as $loan) {
            $loan->update(['status' => BookIssue::STATUS_OVERDUE]);
        }

        // 2. Due soon loans (due within next X days and not reminded today)
        $targetDate = now()->addDays($reminderDaysBefore)->toDateString();
        $dueSoonLoans = BookIssue::whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_RENEWED])
            ->whereDate('due_date', '<=', $targetDate)
            ->whereDate('due_date', '>=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('last_reminder_sent_at')
                    ->orWhereDate('last_reminder_sent_at', '<', now()->toDateString());
            })
            ->get();

        $dueSoonCount = 0;
        foreach ($dueSoonLoans as $loan) {
            $result = LibraryReminderService::sendIssueReminder($loan, $channels);
            if ($result['success']) {
                $dueSoonCount++;
            }
        }

        // 3. Overdue loans (due date passed and not reminded today)
        $overdueReminderLoans = BookIssue::where('status', BookIssue::STATUS_OVERDUE)
            ->where(function ($q) {
                $q->whereNull('last_reminder_sent_at')
                    ->orWhereDate('last_reminder_sent_at', '<', now()->toDateString());
            })
            ->get();

        $overdueCount = 0;
        foreach ($overdueReminderLoans as $loan) {
            $result = LibraryReminderService::sendIssueReminder($loan, $channels);
            if ($result['success']) {
                $overdueCount++;
            }
        }

        $this->info("Completed: Sent {$dueSoonCount} due-soon reminders and {$overdueCount} overdue reminders.");

        return self::SUCCESS;
    }
}
