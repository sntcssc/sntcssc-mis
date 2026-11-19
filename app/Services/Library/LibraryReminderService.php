<?php

namespace App\Services\Library;

use App\Models\AppNotification;
use App\Models\BookIssue;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class LibraryReminderService
{
    /**
     * Send return reminder for a specific book loan.
     *
     * @param  array<string>  $channels  ['database', 'email', 'sms', 'whatsapp', 'telegram']
     * @return array{success: bool, channels_sent: array<string>}
     */
    public static function sendIssueReminder(
        int|BookIssue $issue,
        array $channels = ['database', 'email', 'sms'],
        ?int $triggeredBy = null
    ): array {
        $model = $issue instanceof BookIssue ? $issue : BookIssue::with(['book', 'bookCopy', 'libraryMember', 'user'])->findOrFail($issue);

        if ($model->status === BookIssue::STATUS_RETURNED) {
            return ['success' => false, 'channels_sent' => []];
        }

        $member = $model->libraryMember;
        $user = $model->user;
        $book = $model->book;
        $copy = $model->bookCopy;
        $sentChannels = [];

        $isOverdue = $model->isOverdue();
        $overdueDays = $model->getOverdueDays();
        $dueDateStr = $model->due_date->format('d M Y');
        $fineAmount = $model->calculateCurrentFine(
            LibraryPolicyService::getFinePerDay(),
            LibraryPolicyService::isFinesEnabled()
        );

        $recipientName = $member->name;
        $recipientEmail = $member->email ?: $user?->email;
        $recipientPhone = $member->phone ?: $user?->phone;

        if ($isOverdue) {
            $subject = "Urgent Overdue Notice: Return Book '{$book->title}'";
            $messageBody = "Dear {$recipientName}, URGENT: The book '{$book->title}' (Accession No: {$copy->accession_no}) borrowed from SNTCSSC Library was due on {$dueDateStr} and is now {$overdueDays} days overdue. An overdue fine of INR ".number_format($fineAmount, 2).' has accumulated. Please return the book to the library immediately or renew it.';
        } else {
            $subject = "Reminder: Book Due for Return - '{$book->title}'";
            $messageBody = "Dear {$recipientName}, this is a gentle reminder that the book '{$book->title}' (Accession No: {$copy->accession_no}) borrowed from SNTCSSC Library is due for return on {$dueDateStr}. Please return or renew the book on or before the due date to avoid overdue fines.";
        }

        // 1. In-App Notification (if linked to User)
        if (in_array('database', $channels, true) && $user) {
            AppNotification::create([
                'user_id' => $user->id,
                'type' => $isOverdue ? 'library_book_overdue' : 'library_book_reminder',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => $isOverdue ? __('Overdue Book Alert') : __('Book Return Reminder'),
                'message' => $isOverdue
                    ? __('The book ":title" is :days days overdue. Fine: ₹:fine. Please return promptly.', [
                        'title' => $book->title,
                        'days' => $overdueDays,
                        'fine' => number_format($fineAmount, 2),
                    ])
                    : __('The book ":title" is due for return on :due. Please return or renew.', [
                        'title' => $book->title,
                        'due' => $dueDateStr,
                    ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'issue_id' => $model->id,
                    'issue_no' => $model->issue_no,
                    'is_overdue' => $isOverdue,
                    'due_date' => $model->due_date->toDateString(),
                ],
            ]);
            $sentChannels[] = 'database';
        }

        // 2. Email Reminder
        if (in_array('email', $channels, true) && ! empty($recipientEmail)) {
            try {
                $emailService = app(EmailService::class);
                $emailService->send(
                    email: $recipientEmail,
                    subject: $subject,
                    htmlBody: nl2br(e($messageBody)),
                    user: $user
                );
                $sentChannels[] = 'email';
            } catch (\Throwable $e) {
                Log::warning("Failed to dispatch library email reminder for issue {$model->id}: ".$e->getMessage());
            }
        }

        // 3. SMS Reminder
        if (in_array('sms', $channels, true) && ! empty($recipientPhone)) {
            try {
                $smsService = app(SmsService::class);
                $smsResult = $smsService->send(
                    phone: $recipientPhone,
                    message: $messageBody,
                    user: $user
                );
                if (! empty($smsResult['success'])) {
                    $sentChannels[] = 'sms';
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to dispatch library SMS reminder for issue {$model->id}: ".$e->getMessage());
            }
        }

        // 4. WhatsApp Reminder
        if (in_array('whatsapp', $channels, true) && ! empty($recipientPhone)) {
            try {
                $whatsAppService = app(WhatsAppService::class);
                $waResult = $whatsAppService->sendDirect($recipientPhone, $messageBody);
                if (! empty($waResult['success'])) {
                    $sentChannels[] = 'whatsapp';
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to dispatch library WhatsApp reminder for issue {$model->id}: ".$e->getMessage());
            }
        }

        // 5. Telegram Reminder (if telegram bot configured)
        if (in_array('telegram', $channels, true)) {
            try {
                $telegramService = app(TelegramService::class);
                $chatId = (string) Setting::get('telegram.default_chat_id');
                if (! empty($chatId)) {
                    $tgResult = $telegramService->sendDirect($chatId, $messageBody);
                    if (! empty($tgResult['success'])) {
                        $sentChannels[] = 'telegram';
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to dispatch library Telegram reminder for issue {$model->id}: ".$e->getMessage());
            }
        }

        // Update issue model counters
        $model->updateQuietly([
            'last_reminder_sent_at' => now(),
            'reminders_count' => $model->reminders_count + 1,
        ]);

        return [
            'success' => count($sentChannels) > 0,
            'channels_sent' => $sentChannels,
        ];
    }

    /**
     * Bulk dispatch reminders for multiple loans.
     *
     * @param  array<int>  $issueIds
     * @param  array<string>  $channels
     * @return array{total: int, sent: int}
     */
    public static function sendBulkReminders(array $issueIds, array $channels): array
    {
        $sentCount = 0;
        $issues = BookIssue::whereIn('id', $issueIds)->get();

        foreach ($issues as $issue) {
            $res = self::sendIssueReminder($issue, $channels, auth()->id());
            if ($res['success']) {
                $sentCount++;
            }
        }

        return [
            'total' => count($issues),
            'sent' => $sentCount,
        ];
    }
}
