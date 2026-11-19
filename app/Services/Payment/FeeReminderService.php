<?php

namespace App\Services\Payment;

use App\Models\AppNotification;
use App\Models\CommunicationLog;
use App\Models\FeeSchedule;
use App\Models\StudentFeeInvoice;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class FeeReminderService
{
    /**
     * Send payment reminder for a specific student fee invoice.
     *
     * @param  array<string>  $channels  ['database', 'email', 'sms', 'whatsapp']
     * @return array{success: bool, channels_sent: array<string>}
     */
    public static function sendInvoiceReminder(
        int|StudentFeeInvoice $invoice,
        array $channels = ['database', 'email', 'sms'],
        ?int $triggeredBy = null
    ): array {
        $model = $invoice instanceof StudentFeeInvoice ? $invoice : StudentFeeInvoice::with(['user', 'feeType'])->findOrFail($invoice);

        if ($model->isPaid() || $model->status === StudentFeeInvoice::STATUS_WAIVED) {
            return ['success' => false, 'channels_sent' => []];
        }

        $user = $model->user;
        $sentChannels = [];

        $dueDateStr = $model->due_date->format('d M Y');
        $amountStr = number_format($model->balance_amount, 2);

        $subject = "Reminder: Payment Due for {$model->title}";
        $messageBody = "Dear {$user->name}, this is a reminder regarding your fee invoice {$model->invoice_no} ({$model->title}) for an outstanding balance of INR {$amountStr}. Due date is {$dueDateStr}. Please complete your payment through your Student Dashboard.";

        // 1. In-App Notification
        if (in_array('database', $channels, true)) {
            AppNotification::create([
                'user_id' => $user->id,
                'type' => 'fee_reminder',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Fee Payment Reminder'),
                'message' => __('Your payment of ₹:amount for :title is due on :date. Please clear your dues.', [
                    'amount' => $amountStr,
                    'title' => $model->title,
                    'date' => $dueDateStr,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'invoice_id' => $model->id,
                    'invoice_no' => $model->invoice_no,
                    'balance_amount' => $model->balance_amount,
                ],
            ]);
            $sentChannels[] = 'database';
        }

        // 2. Email Reminder
        if (in_array('email', $channels, true) && ! empty($user->email)) {
            try {
                $emailService = app(EmailService::class);
                $emailService->send(
                    email: $user->email,
                    subject: $subject,
                    htmlBody: nl2br(e($messageBody)),
                    user: $user
                );
                $sentChannels[] = 'email';
            } catch (\Throwable $e) {
                Log::warning("Failed to send fee reminder email to user {$user->id}: ".$e->getMessage());
            }
        }

        // 3. SMS Reminder
        if (in_array('sms', $channels, true) && ! empty($user->phone)) {
            try {
                $smsMessage = "SNTCSSC Alert: Dear {$user->name}, fee payment of Rs.{$amountStr} for {$model->invoice_no} is due by {$dueDateStr}. Pay now via portal.";
                $smsService = app(SmsService::class);
                $smsService->send(
                    phone: $user->phone,
                    message: $smsMessage,
                    user: $user
                );
                $sentChannels[] = 'sms';
            } catch (\Throwable $e) {
                Log::warning("Failed to send fee reminder SMS to user {$user->id}: ".$e->getMessage());
            }
        }

        // 4. WhatsApp Reminder (Log delivery / dispatch through communication system)
        if (in_array('whatsapp', $channels, true) && (! empty($user->whatsapp_no) || ! empty($user->phone))) {
            try {
                CommunicationLog::create([
                    'channel' => 'whatsapp',
                    'type' => CommunicationLog::TYPE_NOTIFICATION,
                    'user_id' => $user->id,
                    'recipient' => $user->whatsapp_no ?: $user->phone,
                    'recipient_name' => $user->name,
                    'subject' => $subject,
                    'content' => $messageBody,
                    'status' => CommunicationLog::STATUS_SENT,
                    'sent_at' => now(),
                    'sent_by' => $triggeredBy,
                ]);
                $sentChannels[] = 'whatsapp';
            } catch (\Throwable $e) {
                Log::warning("Failed to log fee reminder WhatsApp message to user {$user->id}: ".$e->getMessage());
            }
        }

        // Update invoice reminder stats
        $model->increment('reminder_count');
        $model->update(['reminder_sent_at' => now()]);

        AuditLogService::log(
            event: 'fee_reminder_sent',
            description: "Sent payment reminder for invoice {$model->invoice_no} to {$user->name} via ".implode(', ', $sentChannels),
            auditable: $model,
            userId: $triggeredBy
        );

        return [
            'success' => ! empty($sentChannels),
            'channels_sent' => $sentChannels,
        ];
    }

    /**
     * Send bulk payment reminders for all overdue or pending invoices under a schedule.
     *
     * @param  array<string>  $channels
     * @return array{total_targeted: int, total_sent: int}
     */
    public static function sendScheduleBulkReminders(
        int|FeeSchedule $schedule,
        array $channels = ['database', 'email', 'sms'],
        ?int $triggeredBy = null
    ): array {
        $model = $schedule instanceof FeeSchedule ? $schedule : FeeSchedule::findOrFail($schedule);

        $pendingInvoices = StudentFeeInvoice::query()
            ->where('fee_schedule_id', $model->id)
            ->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL, StudentFeeInvoice::STATUS_OVERDUE])
            ->with(['user', 'feeType'])
            ->get();

        $sentCount = 0;
        foreach ($pendingInvoices as $inv) {
            $result = static::sendInvoiceReminder($inv, $channels, $triggeredBy);
            if ($result['success']) {
                $sentCount++;
            }
        }

        return [
            'total_targeted' => $pendingInvoices->count(),
            'total_sent' => $sentCount,
        ];
    }
}
