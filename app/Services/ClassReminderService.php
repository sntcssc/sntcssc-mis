<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\ClassSchedule;
use App\Models\CommunicationLog;
use App\Models\Enrollment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ClassReminderService
{
    /**
     * Supported notification channels.
     */
    public const CHANNELS = [
        'email' => 'Email',
        'sms' => 'SMS',
        'whatsapp' => 'WhatsApp',
        'telegram' => 'Telegram',
    ];

    /**
     * Default reminder template with dynamic placeholders.
     */
    public const DEFAULT_TEMPLATE = "Dear {student_name},\n\nReminder: Your {course_name} class on '{subject}' is scheduled for {class_date} at {class_time}.\nMode: {delivery_mode}\nVenue/Link: {venue_or_link}\nFaculty: {faculty_name}\n\nPlease join on time and check your student portal for syllabus details.\nSNTCSSC Academic Team";

    /**
     * Get all reminder settings as an associative array.
     *
     * @return array{enabled: bool, lead_time_minutes: int, channels: array<int, string>, template: string}
     */
    public static function getSettings(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'lead_time_minutes' => self::getLeadTimeMinutes(),
            'channels' => self::getConfiguredChannels(),
            'template' => self::getTemplate(),
        ];
    }

    /**
     * Check if class reminders are globally enabled in settings.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('classes.reminders_enabled', true);
    }

    /**
     * Get configured reminder lead time in minutes before class begins.
     */
    public static function getLeadTimeMinutes(): int
    {
        return (int) Setting::get('classes.reminder_lead_time_minutes', 60);
    }

    /**
     * Get enabled reminder channels from settings.
     *
     * @return array<int, string>
     */
    public static function getConfiguredChannels(): array
    {
        $raw = Setting::get('classes.reminder_channels', ['email', 'whatsapp', 'sms', 'telegram']);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : ['email', 'whatsapp', 'sms'];
        }

        return is_array($raw) ? $raw : ['email', 'whatsapp', 'sms'];
    }

    /**
     * Get the reminder message template.
     */
    public static function getTemplate(): string
    {
        return (string) Setting::get('classes.reminder_template', self::DEFAULT_TEMPLATE);
    }

    /**
     * Update reminder settings.
     */
    public static function updateSettings(bool|array $enabled, int $leadTimeMinutes = 60, array $channels = ['whatsapp', 'email'], ?string $template = null): void
    {
        if (is_array($enabled)) {
            $data = $enabled;
            $enabled = (bool) ($data['enabled'] ?? true);
            $leadTimeMinutes = (int) ($data['lead_time_minutes'] ?? 60);
            $channels = (array) ($data['channels'] ?? ['whatsapp', 'email']);
            $template = $data['template'] ?? null;
        }

        Setting::set('classes.reminders_enabled', $enabled ? '1' : '0');
        Setting::set('classes.reminder_lead_time_minutes', (string) max(5, $leadTimeMinutes));
        Setting::set('classes.reminder_channels', json_encode(array_values($channels)));
        if ($template) {
            Setting::set('classes.reminder_template', $template);
        }

        AuditLogService::log(
            event: 'class_reminder_settings_updated',
            description: 'Updated class reminder settings (Enabled: '.($enabled ? 'Yes' : 'No').", Lead: {$leadTimeMinutes} mins, Channels: ".implode(', ', $channels).')',
            userId: auth()->id()
        );
    }

    /**
     * Retrieve enrolled students for a specific scheduled class.
     *
     * @return Collection<int, User>
     */
    public static function getEnrolledStudentsForClass(ClassSchedule $schedule): Collection
    {
        $query = Enrollment::where('batch_id', $schedule->batch_id)
            ->where('course_id', $schedule->course_id)
            ->where('status', 'active');

        if (! empty($schedule->section_id)) {
            $query->where(function ($q) use ($schedule) {
                $q->whereNull('section_id')
                    ->orWhere('section_id', $schedule->section_id);
            });
        }

        $userIds = $query->pluck('user_id')->filter()->unique()->values()->all();

        if (empty($userIds)) {
            return new Collection;
        }

        return User::whereIn('id', $userIds)
            ->where(function ($q) {
                $q->where('status', 'active')
                    ->orWhereNull('status');
            })
            ->get();
    }

    /**
     * Compile message template with real schedule and student variables.
     */
    public static function compileMessage(string $template, ClassSchedule $schedule, User $student): string
    {
        $venueOrLink = $schedule->isOnline()
            ? ($schedule->meeting_link ?: 'Check portal for meeting link')
            : ($schedule->classroomRecord?->display_name ?: $schedule->classroom ?: 'Classroom TBD');

        $facultyNames = $schedule->primary_faculty_names ?: 'Assigned Faculty';

        $replacements = [
            '{student_name}' => $student->name,
            '{course_name}' => $schedule->course?->name ?? 'Course',
            '{batch_name}' => $schedule->batch?->name ?? 'Batch',
            '{section_name}' => $schedule->section?->name ?? 'All Sections',
            '{subject}' => $schedule->subject ?: 'Lecture Session',
            '{lecture_no}' => $schedule->lecture_number ?: 'N/A',
            '{class_date}' => $schedule->class_date->format('d M Y (D)'),
            '{class_time}' => $schedule->formatted_time,
            '{delivery_mode}' => ucfirst($schedule->delivery_mode),
            '{venue_or_link}' => $venueOrLink,
            '{faculty_name}' => $facultyNames,
            '{dashboard_url}' => route('student.routine.index'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Send scheduled class reminder to all enrolled students across configured channels.
     *
     * @param  array<int, string>|null  $channels  Override channels or null for configured defaults
     * @return array{recipients_count: int, channels: array, success: bool, message: string}
     */
    public static function sendClassReminder(
        ClassSchedule $schedule,
        ?array $channels = null,
        ?string $customMessage = null,
        bool $isManual = false
    ): array {
        // If not manual, verify system enabled
        if (! $isManual && ! self::isEnabled()) {
            return [
                'recipients_count' => 0,
                'channels' => [],
                'success' => false,
                'message' => 'Class reminders are currently disabled in settings.',
            ];
        }

        // Do not notify for cancelled classes
        if ($schedule->isCancelled()) {
            return [
                'recipients_count' => 0,
                'channels' => [],
                'success' => false,
                'message' => 'Cannot send reminders for a cancelled class.',
            ];
        }

        $channels = ! empty($channels) ? $channels : self::getConfiguredChannels();
        $template = ! empty($customMessage) ? $customMessage : self::getTemplate();

        $students = self::getEnrolledStudentsForClass($schedule);

        if ($students->isEmpty()) {
            return [
                'recipients_count' => 0,
                'sent_count' => 0,
                'channels' => $channels,
                'success' => false,
                'message' => 'No active enrolled students found for this class schedule.',
            ];
        }

        $sentCount = 0;

        DB::beginTransaction();

        try {
            foreach ($students as $student) {
                $compiledMessage = self::compileMessage($template, $schedule, $student);
                $title = "Upcoming Class Reminder: {$schedule->subject} ({$schedule->formatted_time})";

                // 1. In-app portal notification
                AppNotification::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $student->id,
                    'type' => 'class_reminder',
                    'category' => 'academics',
                    'title' => $title,
                    'message' => $compiledMessage,
                    'channel' => 'in_app',
                    'data' => [
                        'class_schedule_id' => $schedule->id,
                        'subject' => $schedule->subject,
                        'class_date' => $schedule->class_date->format('Y-m-d'),
                        'time' => $schedule->formatted_time,
                        'meeting_link' => $schedule->meeting_link,
                        'classroom' => $schedule->classroom_display,
                    ],
                    'created_by' => auth()->id(),
                ]);

                // 2. Dispatch to each external channel and log in communication_logs
                foreach ($channels as $channel) {
                    $recipient = match ($channel) {
                        'email' => $student->email,
                        'sms', 'whatsapp' => $student->phone,
                        'telegram' => $student->phone ?: $student->email,
                        default => $student->email,
                    };

                    if (empty($recipient)) {
                        continue;
                    }

                    CommunicationLog::create([
                        'channel' => $channel,
                        'type' => 'class_schedule_reminder',
                        'recipient' => $recipient,
                        'recipient_name' => $student->name,
                        'user_id' => $student->id,
                        'template_code' => 'CLASS_REMINDER',
                        'subject' => $title,
                        'content' => $compiledMessage,
                        'status' => 'sent',
                        'sent_by' => auth()->id(),
                        'sent_at' => now(),
                        'metadata' => [
                            'class_schedule_id' => $schedule->id,
                            'is_manual' => $isManual,
                            'batch_id' => $schedule->batch_id,
                            'course_id' => $schedule->course_id,
                        ],
                    ]);
                }

                $sentCount++;
            }

            // Update schedule reminder flags
            $schedule->update([
                'reminder_sent_at' => now(),
                'reminder_count' => ($schedule->reminder_count ?? 0) + 1,
                'last_reminder_channel' => implode(',', $channels),
            ]);

            AuditLogService::log(
                event: $isManual ? 'manual_class_reminder_sent' : 'automated_class_reminder_sent',
                description: "Dispatched class reminder for {$schedule->schedule_code} ({$schedule->subject}) to {$sentCount} students via ".implode(', ', $channels),
                auditable: $schedule,
                newValues: [
                    'schedule_id' => $schedule->id,
                    'students_count' => $sentCount,
                    'channels' => $channels,
                    'is_manual' => $isManual,
                ],
                userId: auth()->id()
            );

            DB::commit();

            return [
                'recipients_count' => $sentCount,
                'sent_count' => $sentCount,
                'channels' => $channels,
                'success' => true,
                'message' => "Successfully sent reminder alerts to {$sentCount} enrolled student(s) via ".implode(', ', $channels).'.',
            ];
        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'recipients_count' => 0,
                'sent_count' => 0,
                'channels' => $channels,
                'success' => false,
                'message' => 'Failed to send reminders: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Send manual instant reminder for any scheduled class.
     *
     * @param  array<int, string>|null  $channels
     * @return array{recipients_count: int, channels: array, success: bool, message: string}
     */
    public static function sendManualReminder(ClassSchedule $schedule, ?array $channels = null, ?string $customMessage = null): array
    {
        return self::sendClassReminder($schedule, $channels, $customMessage, isManual: true);
    }

    /**
     * Dispatch due reminders for classes scheduled to start within lead time minutes.
     *
     * @return int Number of classes for which reminders were sent
     */
    public static function dispatchDueReminders(): int
    {
        if (! self::isEnabled()) {
            return 0;
        }

        $leadTime = self::getLeadTimeMinutes();
        $now = now();
        $windowEnd = $now->copy()->addMinutes($leadTime);

        // Find today's scheduled classes starting between now and now + leadTime
        $schedules = ClassSchedule::whereDate('class_date', today())
            ->where('status', 'scheduled')
            ->whereNull('reminder_sent_at')
            ->whereTime('start_time', '>=', $now->format('H:i:s'))
            ->whereTime('start_time', '<=', $windowEnd->format('H:i:s'))
            ->get();

        $processedCount = 0;

        foreach ($schedules as $schedule) {
            $result = self::sendClassReminder($schedule, null, null, false);
            if ($result['success']) {
                $processedCount++;
            }
        }

        return $processedCount;
    }
}
