<?php

namespace App\Services;

use App\Jobs\DispatchCommunicationCampaignJob;
use App\Jobs\SendQueuedEmailJob;
use App\Jobs\SendQueuedSmsJob;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommunicationService
{
    public function __construct(
        protected SmsService $smsService,
        protected EmailService $emailService
    ) {}

    /**
     * Dispatch an email and record its delivery log.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $metadata
     */
    public function logAndSendEmail(
        string $email,
        string $subject,
        string $htmlBody,
        array $options = [],
        ?User $user = null,
        ?string $templateCode = null,
        array $variables = [],
        string $type = CommunicationLog::TYPE_CUSTOM_INDIVIDUAL,
        ?int $sentBy = null,
        ?int $campaignId = null,
        array $metadata = []
    ): CommunicationLog {
        $email = trim(strtolower($email));
        $recipientName = $user?->name ?? ($variables['name'] ?? null);

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
            if ($user && ! $recipientName) {
                $recipientName = $user->name;
            }
        }

        // Apply personalizations if variables passed
        $renderedSubject = $this->personalizeString($subject, array_merge(['name' => $recipientName ?? 'User', 'email' => $email], $variables));
        $renderedBody = $this->personalizeString($htmlBody, array_merge(['name' => $recipientName ?? 'User', 'email' => $email], $variables));

        $status = CommunicationLog::STATUS_FAILED;
        $errorMessage = null;
        $deliveredAt = null;
        $failedAt = null;

        if (! EmailService::isEnabled()) {
            $errorMessage = __('Email service is currently disabled in system settings.');
            $failedAt = now();
        } else {
            try {
                $dispatched = $this->emailService->sendDirect($email, $renderedSubject, $renderedBody, $options);
                if ($dispatched['success']) {
                    $status = CommunicationLog::STATUS_SENT;
                    $deliveredAt = now();
                } else {
                    $status = CommunicationLog::STATUS_FAILED;
                    $errorMessage = $dispatched['error'] ?? __('Failed to send email.');
                    $failedAt = now();
                }
            } catch (\Throwable $e) {
                $status = CommunicationLog::STATUS_FAILED;
                $errorMessage = $e->getMessage();
                $failedAt = now();
                Log::error("CommunicationService email error to {$email}: ".$e->getMessage(), ['exception' => $e]);
            }
        }

        return CommunicationLog::create([
            'channel' => CommunicationLog::CHANNEL_EMAIL,
            'type' => $type,
            'recipient' => $email,
            'recipient_name' => $recipientName,
            'user_id' => $user?->id,
            'template_code' => $templateCode,
            'subject' => $renderedSubject,
            'content' => $renderedBody,
            'variables' => $variables,
            'metadata' => array_merge([
                'options' => $options,
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ], $metadata),
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_by' => $sentBy ?? auth()->id(),
            'campaign_id' => $campaignId,
            'sent_at' => now(),
            'delivered_at' => $deliveredAt,
            'failed_at' => $failedAt,
        ]);
    }

    /**
     * Dispatch an SMS and record its delivery log.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $metadata
     */
    public function logAndSendSms(
        string $phone,
        string $message,
        ?string $dltTemplateId = null,
        ?string $senderId = null,
        ?User $user = null,
        ?string $templateCode = null,
        array $variables = [],
        string $type = CommunicationLog::TYPE_CUSTOM_INDIVIDUAL,
        ?int $sentBy = null,
        ?int $campaignId = null,
        array $metadata = []
    ): CommunicationLog {
        $normalizedPhone = $this->smsService->normalizePhoneNumber($phone);
        $recipientName = $user?->name ?? ($variables['name'] ?? null);

        if (! $user && $normalizedPhone) {
            $user = User::where('phone', $normalizedPhone)->first();
            if ($user && ! $recipientName) {
                $recipientName = $user->name;
            }
        }

        // Apply personalizations if variables passed
        $renderedMessage = $this->personalizeString($message, array_merge(['name' => $recipientName ?? 'User', 'phone' => $normalizedPhone], $variables));

        $status = CommunicationLog::STATUS_FAILED;
        $errorMessage = null;
        $deliveredAt = null;
        $failedAt = null;

        if (! SmsService::isEnabled()) {
            $errorMessage = __('SMS gateway is currently disabled in system settings.');
            $failedAt = now();
        } else {
            try {
                $result = $this->smsService->sendDirect($normalizedPhone, $renderedMessage, $dltTemplateId, $senderId);
                if ($result['success']) {
                    $status = CommunicationLog::STATUS_SENT;
                    $deliveredAt = now();
                } else {
                    $status = CommunicationLog::STATUS_FAILED;
                    $errorMessage = $result['error'] ?? __('SMS gateway failed to deliver message.');
                    $failedAt = now();
                }
            } catch (\Throwable $e) {
                $status = CommunicationLog::STATUS_FAILED;
                $errorMessage = $e->getMessage();
                $failedAt = now();
                Log::error("CommunicationService SMS error to {$normalizedPhone}: ".$e->getMessage(), ['exception' => $e]);
            }
        }

        return CommunicationLog::create([
            'channel' => CommunicationLog::CHANNEL_SMS,
            'type' => $type,
            'recipient' => $normalizedPhone,
            'recipient_name' => $recipientName,
            'user_id' => $user?->id,
            'template_code' => $templateCode,
            'subject' => null,
            'content' => $renderedMessage,
            'variables' => $variables,
            'metadata' => array_merge([
                'dlt_template_id' => $dltTemplateId,
                'sender_id' => $senderId,
                'driver' => SmsService::driver(),
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ], $metadata),
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_by' => $sentBy ?? auth()->id(),
            'campaign_id' => $campaignId,
            'sent_at' => now(),
            'delivered_at' => $deliveredAt,
            'failed_at' => $failedAt,
        ]);
    }

    /**
     * Resend an existing communication log item.
     *
     * @return array{success: bool, message: string, log: CommunicationLog}
     */
    public function resend(CommunicationLog $log, ?int $adminId = null): array
    {
        $adminId = $adminId ?? auth()->id();

        try {
            return DB::transaction(function () use ($log, $adminId) {
                $success = false;
                $errorMessage = null;

                if ($log->isEmail()) {
                    if (! EmailService::isEnabled()) {
                        $errorMessage = __('Email service is currently disabled in system settings.');
                    } else {
                        $options = $log->metadata['options'] ?? [];
                        $result = $this->emailService->sendDirect($log->recipient, $log->subject ?? Setting::appName(), $log->content, $options);
                        $success = $result['success'];
                        $errorMessage = $result['error'] ?? null;
                    }
                } else {
                    if (! SmsService::isEnabled()) {
                        $errorMessage = __('SMS gateway is currently disabled in system settings.');
                    } else {
                        $dltTemplateId = $log->metadata['dlt_template_id'] ?? null;
                        $senderId = $log->metadata['sender_id'] ?? null;
                        $result = $this->smsService->sendDirect($log->recipient, $log->content, $dltTemplateId, $senderId);
                        $success = $result['success'];
                        $errorMessage = $result['error'] ?? null;
                    }
                }

                $log->resend_count += 1;
                $log->last_resent_at = now();

                if ($success) {
                    $log->status = CommunicationLog::STATUS_SENT;
                    $log->delivered_at = now();
                    $log->error_message = null;
                } else {
                    $log->status = CommunicationLog::STATUS_FAILED;
                    $log->failed_at = now();
                    $log->error_message = $errorMessage ?: __('Resend attempt failed.');
                }

                $log->save();

                AuditLogService::log(
                    event: 'communication_resent',
                    description: "Resent {$log->channel} to {$log->recipient} (Attempt #{$log->resend_count}) - Status: {$log->status}",
                    newValues: [
                        'channel' => $log->channel,
                        'recipient' => $log->recipient,
                        'status' => $log->status,
                        'resend_count' => $log->resend_count,
                        'error_message' => $log->error_message,
                    ],
                    userId: $adminId
                );

                return [
                    'success' => $success,
                    'message' => $success
                        ? __(':channel resent successfully to :recipient.', ['channel' => strtoupper($log->channel), 'recipient' => $log->recipient])
                        : __('Resend failed: :error', ['error' => $log->error_message]),
                    'log' => $log->fresh(),
                ];
            });
        } catch (\Throwable $e) {
            Log::error("CommunicationService resend failed for log #{$log->id}: ".$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => __('Resend failed: :error', ['error' => $e->getMessage()]),
                'log' => $log,
            ];
        }
    }

    /**
     * Resend multiple communication logs in batch.
     *
     * @param  array<int>  $logIds
     * @return array{success: bool, resend_count: int, failed_count: int, message: string}
     */
    public function bulkResend(array $logIds, ?int $adminId = null): array
    {
        $logs = CommunicationLog::whereIn('id', $logIds)->get();
        $resent = 0;
        $failed = 0;

        foreach ($logs as $log) {
            $result = $this->resend($log, $adminId);
            if ($result['success']) {
                $resent++;
            } else {
                $failed++;
            }
        }

        return [
            'success' => $resent > 0,
            'resend_count' => $resent,
            'failed_count' => $failed,
            'message' => __(':resent message(s) resent successfully. :failed failed.', ['resent' => $resent, 'failed' => $failed]),
        ];
    }

    /**
     * Dispatch an individual or bulk campaign.
     *
     * @return array{success: bool, sent: int, failed: int, message: string}
     */
    public function dispatchCampaign(CommunicationCampaign $campaign, ?int $adminId = null): array
    {
        $adminId = $adminId ?? auth()->id();
        $recipients = $this->resolveRecipientsForCampaign($campaign);

        $campaign->status = CommunicationCampaign::STATUS_PROCESSING;
        $campaign->total_recipients = count($recipients);
        $campaign->sent_at = now();
        $campaign->updated_by = $adminId;
        $campaign->save();

        $sentCount = 0;
        $failedCount = 0;

        foreach ($recipients as $recipientData) {
            $user = $recipientData['user'] ?? null;
            $email = $recipientData['email'] ?? null;
            $phone = $recipientData['phone'] ?? null;
            $name = $recipientData['name'] ?? ($user?->name ?? 'User');

            $variables = [
                'name' => $name,
                'email' => $email ?? '',
                'phone' => $phone ?? '',
                'app_name' => Setting::appName(),
                'date' => now()->format('d M Y'),
                'time' => now()->format('h:i A'),
            ];

            // Send Email if applicable
            if (in_array($campaign->channel, [CommunicationCampaign::CHANNEL_EMAIL, CommunicationCampaign::CHANNEL_BOTH]) && $email) {
                $log = $this->logAndSendEmail(
                    email: $email,
                    subject: $campaign->subject ?: Setting::appName().' Notice',
                    htmlBody: $campaign->content,
                    user: $user,
                    templateCode: $campaign->template_code,
                    variables: $variables,
                    type: $campaign->recipient_type === CommunicationCampaign::RECIPIENT_INDIVIDUAL ? CommunicationLog::TYPE_CUSTOM_INDIVIDUAL : CommunicationLog::TYPE_CUSTOM_BULK,
                    sentBy: $adminId,
                    campaignId: $campaign->id
                );

                if ($log->isDelivered()) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }

            // Send SMS if applicable
            if (in_array($campaign->channel, [CommunicationCampaign::CHANNEL_SMS, CommunicationCampaign::CHANNEL_BOTH]) && $phone) {
                $log = $this->logAndSendSms(
                    phone: $phone,
                    message: $campaign->content,
                    user: $user,
                    templateCode: $campaign->template_code,
                    variables: $variables,
                    type: $campaign->recipient_type === CommunicationCampaign::RECIPIENT_INDIVIDUAL ? CommunicationLog::TYPE_CUSTOM_INDIVIDUAL : CommunicationLog::TYPE_CUSTOM_BULK,
                    sentBy: $adminId,
                    campaignId: $campaign->id
                );

                if ($log->isDelivered()) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }
        }

        $campaign->sent_count = $sentCount;
        $campaign->failed_count = $failedCount;
        $campaign->status = $sentCount > 0 ? CommunicationCampaign::STATUS_COMPLETED : CommunicationCampaign::STATUS_FAILED;
        $campaign->save();

        AuditLogService::log(
            event: 'communication_campaign_dispatched',
            description: "Dispatched communication campaign '{$campaign->title}' (Sent: {$sentCount}, Failed: {$failedCount})",
            newValues: [
                'campaign_id' => $campaign->id,
                'channel' => $campaign->channel,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
            ],
            userId: $adminId
        );

        return [
            'success' => $sentCount > 0,
            'sent' => $sentCount,
            'failed' => $failedCount,
            'message' => __('Campaign dispatched: :sent sent, :failed failed.', ['sent' => $sentCount, 'failed' => $failedCount]),
        ];
    }

    /**
     * Resolve target recipients list based on campaign configuration.
     *
     * @return array<int, array{user: ?User, name: string, email: ?string, phone: ?string}>
     */
    public function resolveRecipientsForCampaign(CommunicationCampaign $campaign): array
    {
        $recipients = [];

        switch ($campaign->recipient_type) {
            case CommunicationCampaign::RECIPIENT_INDIVIDUAL:
                $userIds = (array) ($campaign->recipient_ids ?? []);
                $users = User::whereIn('id', $userIds)->get();
                foreach ($users as $user) {
                    $recipients[] = [
                        'user' => $user,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                    ];
                }
                break;

            case CommunicationCampaign::RECIPIENT_ROLE:
                $role = $campaign->recipient_role;
                if ($role) {
                    $users = User::whereHas('teams', function ($query) use ($role) {
                        $query->where('role', $role);
                    })->orWhereHas('ownedTeams')->get();

                    foreach ($users as $user) {
                        $recipients[] = [
                            'user' => $user,
                            'name' => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone,
                        ];
                    }
                }
                break;

            case CommunicationCampaign::RECIPIENT_ALL:
                $users = User::all();
                foreach ($users as $user) {
                    $recipients[] = [
                        'user' => $user,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                    ];
                }
                break;

            case CommunicationCampaign::RECIPIENT_CUSTOM:
                $customList = (array) ($campaign->recipient_ids ?? []);
                foreach ($customList as $item) {
                    $item = trim((string) $item);
                    if (empty($item)) {
                        continue;
                    }

                    if (filter_var($item, FILTER_VALIDATE_EMAIL)) {
                        $user = User::where('email', $item)->first();
                        $recipients[] = [
                            'user' => $user,
                            'name' => $user?->name ?? 'User',
                            'email' => $item,
                            'phone' => $user?->phone,
                        ];
                    } else {
                        $normalized = $this->smsService->normalizePhoneNumber($item);
                        $user = User::where('phone', $normalized)->first();
                        $recipients[] = [
                            'user' => $user,
                            'name' => $user?->name ?? 'User',
                            'email' => $user?->email,
                            'phone' => $normalized,
                        ];
                    }
                }
                break;
        }

        return $recipients;
    }

    /**
     * Replace template placeholders like {name}, {email}, {phone}, {app_name} with values.
     *
     * @param  array<string, mixed>  $variables
     */
    public function personalizeString(string $template, array $variables = []): string
    {
        $variables['app_name'] ??= Setting::appName();
        $variables['date'] ??= now()->format('d M Y');
        $variables['time'] ??= now()->format('h:i A');
        $variables['year'] ??= date('Y');

        foreach ($variables as $key => $value) {
            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $template = str_replace('{'.$key.'}', (string) $value, $template);
            }
        }

        return $template;
    }

    /**
     * Dispatch an email asynchronously via the queue.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $metadata
     */
    public function queueEmail(
        string $email,
        string $subject,
        string $htmlBody,
        array $options = [],
        ?User $user = null,
        ?string $templateCode = null,
        array $variables = [],
        string $type = CommunicationLog::TYPE_CUSTOM_INDIVIDUAL,
        ?int $sentBy = null,
        ?int $campaignId = null,
        array $metadata = []
    ): void {
        SendQueuedEmailJob::dispatch(
            $email,
            $subject,
            $htmlBody,
            $options,
            $user,
            $templateCode,
            $variables,
            $type,
            $sentBy,
            $campaignId,
            $metadata
        );
    }

    /**
     * Dispatch an SMS message asynchronously via the queue.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $metadata
     */
    public function queueSms(
        string $phone,
        string $message,
        array $options = [],
        ?User $user = null,
        ?string $templateCode = null,
        array $variables = [],
        string $type = CommunicationLog::TYPE_CUSTOM_INDIVIDUAL,
        ?int $sentBy = null,
        ?int $campaignId = null,
        array $metadata = []
    ): void {
        SendQueuedSmsJob::dispatch(
            $phone,
            $message,
            $options,
            $user,
            $templateCode,
            $variables,
            $type,
            $sentBy,
            $campaignId,
            $metadata
        );
    }

    /**
     * Queue a broadcast campaign for background processing.
     */
    public function queueCampaign(CommunicationCampaign $campaign, ?int $adminId = null): void
    {
        DispatchCommunicationCampaignJob::dispatch($campaign, $adminId);
    }
}
