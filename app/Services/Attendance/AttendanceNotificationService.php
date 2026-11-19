<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceNotificationLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AttendanceNotificationService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected WhatsAppService $whatsAppService,
        protected TelegramService $telegramService,
        protected EmailService $emailService,
        protected SmsService $smsService
    ) {}

    /**
     * Dispatch attendance status notification to a student or user.
     */
    public function notifyAttendanceMarked(Attendance $attendance, ?string $customMessage = null): void
    {
        $attendance->loadMissing(['user', 'course', 'batch']);
        $user = $attendance->user;

        if (! $user) {
            return;
        }

        $status = $attendance->status;

        // Check system settings for whether to send notification for this status
        $shouldNotify = match ($status) {
            Attendance::STATUS_PRESENT => (bool) Setting::get('attendance.notify_student_present', true),
            Attendance::STATUS_ABSENT => (bool) Setting::get('attendance.notify_student_absent', true),
            Attendance::STATUS_LATE => (bool) Setting::get('attendance.notify_student_late', true),
            default => false,
        };

        if (! $shouldNotify) {
            return;
        }

        $dateFormatted = Carbon::parse($attendance->date)->format('d M, Y');
        $statusLabel = $attendance->statusLabel();

        $title = __('Attendance Update: Marked :status on :date', [
            'status' => $statusLabel,
            'date' => $dateFormatted,
        ]);

        $body = $customMessage ?: match ($status) {
            Attendance::STATUS_PRESENT => __('Dear :name, you were marked PRESENT today (:date). Have an inspiring study session at SNTCSSC!', ['name' => $user->name, 'date' => $dateFormatted]),
            Attendance::STATUS_ABSENT => __('Dear :name, you have been marked ABSENT on :date. Please ensure you maintain the mandatory 75% classroom attendance criteria.', ['name' => $user->name, 'date' => $dateFormatted]),
            Attendance::STATUS_LATE => __('Dear :name, you were marked LATE (:minutes mins) on :date. Check-in time: :time.', ['name' => $user->name, 'minutes' => $attendance->late_minutes, 'date' => $dateFormatted, 'time' => $attendance->check_in_time ?? 'N/A']),
            default => __('Attendance status recorded as :status on :date.', ['status' => $statusLabel, 'date' => $dateFormatted]),
        };

        $configuredChannels = Setting::get('attendance.notification_channels', ['database', 'email', 'sms', 'whatsapp']);
        if (is_string($configuredChannels)) {
            $configuredChannels = json_decode($configuredChannels, true) ?: ['database'];
        }

        // Send via core NotificationService
        $this->notificationService->send(
            user: $user,
            title: $title,
            message: $body,
            category: 'attendance',
            options: [
                'type' => 'attendance_'.$status,
                'channels' => $configuredChannels,
                'color' => match ($status) {
                    Attendance::STATUS_PRESENT => 'emerald',
                    Attendance::STATUS_LATE => 'amber',
                    default => 'rose',
                },
                'action_url' => $attendance->user_type === 'student' ? route('student.attendance.index') : route('portal.my-attendance.index'),
                'action_label' => __('View Attendance Dossier'),
            ]
        );

        // Record in attendance_notifications_log for tracking
        foreach ($configuredChannels as $channel) {
            AttendanceNotificationLog::create([
                'attendance_id' => $attendance->id,
                'user_id' => $user->id,
                'channel' => $channel,
                'notification_type' => 'marked_'.$status,
                'recipient' => match ($channel) {
                    'email' => $user->email,
                    'sms', 'whatsapp' => $user->phone ?? $user->whatsapp_no,
                    default => (string) $user->id,
                },
                'status' => 'sent',
                'message_preview' => mb_strimwidth($body, 0, 250, '...'),
            ]);
        }

        $attendance->updateQuietly([
            'notification_sent' => true,
            'notification_sent_at' => Carbon::now(),
        ]);
    }

    /**
     * Send reminder to student regarding attendance shortage or absenteeism.
     *
     * @param  array<string>|null  $channels
     */
    public function sendAttendanceReminder(User $user, string $reminderType, string $message, ?array $channels = null): bool
    {
        $channels = $channels ?: Setting::get('attendance.notification_channels', ['database', 'email', 'sms', 'whatsapp']);
        if (is_string($channels)) {
            $channels = json_decode($channels, true) ?: ['database'];
        }

        $title = match ($reminderType) {
            'shortage' => __('Urgently Required: Classroom Attendance Shortage Warning'),
            'absentee' => __('Notice of Absence: Classroom Attendance Follow-up'),
            default => __('SNTCSSC Attendance Reminder Notification'),
        };

        $this->notificationService->send(
            user: $user,
            title: $title,
            message: $message,
            category: 'attendance',
            options: [
                'type' => 'attendance_reminder',
                'channels' => $channels,
                'color' => 'amber',
                'action_url' => $user->isStudent() ? route('student.attendance.index') : route('portal.my-attendance.index'),
                'action_label' => __('View Attendance'),
            ]
        );

        foreach ($channels as $channel) {
            AttendanceNotificationLog::create([
                'attendance_id' => null,
                'user_id' => $user->id,
                'channel' => $channel,
                'notification_type' => 'reminder',
                'recipient' => match ($channel) {
                    'email' => $user->email,
                    'sms', 'whatsapp' => $user->phone ?? $user->whatsapp_no,
                    default => (string) $user->id,
                },
                'status' => 'sent',
                'message_preview' => mb_strimwidth($message, 0, 250, '...'),
            ]);
        }

        return true;
    }

    /**
     * Send daily absent notices for a specific date (used by cron command or admin manual trigger).
     *
     * @return array{total_sent: int, failed: int}
     */
    public function dispatchDailyAbsenteeDigest(string $date, ?string $userType = null): array
    {
        $query = Attendance::where('date', $date)
            ->where('status', Attendance::STATUS_ABSENT)
            ->with(['user']);

        if ($userType) {
            $query->where('user_type', $userType);
        }

        $absentees = $query->get();
        $sentCount = 0;
        $failedCount = 0;

        foreach ($absentees as $attendance) {
            try {
                $this->notifyAttendanceMarked($attendance);
                $sentCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to dispatch daily absentee notification for user {$attendance->user_id}: ".$e->getMessage());
                $failedCount++;
            }
        }

        return [
            'total_sent' => $sentCount,
            'failed' => $failedCount,
        ];
    }
}
