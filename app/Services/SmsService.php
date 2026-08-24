<?php

namespace App\Services;

use App\Models\CommunicationLog;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Determine whether SMS dispatching is enabled in system settings.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('sms.enabled', false);
    }

    /**
     * Get the active SMS driver.
     */
    public static function driver(): string
    {
        return (string) Setting::get('sms.driver', '2factor');
    }

    /**
     * Directly send SMS and return detailed execution status.
     *
     * @return array{success: bool, error: ?string}
     */
    public function sendDirect(string $phone, string $message, ?string $dltTemplateId = null, ?string $senderId = null): array
    {
        if (! self::isEnabled()) {
            Log::info("SMS Service is disabled in settings. Suppressed SMS to {$phone}: {$message}");

            return [
                'success' => false,
                'error' => __('SMS gateway is currently disabled in system settings.'),
            ];
        }

        $phone = $this->normalizePhoneNumber($phone);
        $driver = self::driver();

        try {
            return match ($driver) {
                '2factor' => $this->sendVia2FactorDetailed($phone, $message, $dltTemplateId, $senderId),
                'msg91' => $this->sendViaMsg91Detailed($phone, $message, $dltTemplateId, $senderId),
                'fast2sms' => $this->sendViaFast2SmsDetailed($phone, $message),
                'log' => $this->logSmsDetailed($phone, $message),
                default => $this->logSmsDetailed($phone, $message),
            };
        } catch (\Throwable $e) {
            Log::error("SMS delivery failed to {$phone} via {$driver}: ".$e->getMessage(), [
                'exception' => $e,
                'phone' => $phone,
                'driver' => $driver,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a raw SMS message to the specified phone number and record in logs.
     */
    public function send(string $phone, string $message, ?string $dltTemplateId = null, ?string $senderId = null, ?User $user = null, ?string $templateCode = null, array $variables = []): bool
    {
        $result = $this->sendDirect($phone, $message, $dltTemplateId, $senderId);

        // Record communication log if database is accessible
        try {
            $normalized = $this->normalizePhoneNumber($phone);
            $recipientName = $user?->name ?? ($variables['name'] ?? null);
            if (! $user && $normalized) {
                $user = User::where('phone', $normalized)->first();
                $recipientName ??= $user?->name;
            }

            CommunicationLog::create([
                'channel' => CommunicationLog::CHANNEL_SMS,
                'type' => $templateCode ? CommunicationLog::TYPE_NOTIFICATION : CommunicationLog::TYPE_CUSTOM_INDIVIDUAL,
                'recipient' => $normalized,
                'recipient_name' => $recipientName,
                'user_id' => $user?->id,
                'template_code' => $templateCode,
                'subject' => null,
                'content' => $message,
                'variables' => $variables,
                'metadata' => [
                    'dlt_template_id' => $dltTemplateId,
                    'sender_id' => $senderId,
                    'driver' => self::driver(),
                ],
                'status' => $result['success'] ? CommunicationLog::STATUS_SENT : CommunicationLog::STATUS_FAILED,
                'error_message' => $result['error'] ?? null,
                'sent_by' => auth()->id(),
                'sent_at' => now(),
                'delivered_at' => $result['success'] ? now() : null,
                'failed_at' => $result['success'] ? null : now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write CommunicationLog for SMS: '.$e->getMessage());
        }

        return $result['success'];
    }

    /**
     * Send SMS by rendering a registered database template.
     *
     * @param  array<string, mixed>  $variables
     */
    public function sendTemplate(string $phone, string $templateCode, array $variables = [], ?User $user = null): bool
    {
        $template = SmsTemplate::active()->where('code', $templateCode)->first();

        if (! $template) {
            Log::warning("SmsTemplate with code '{$templateCode}' not found or inactive.");

            return false;
        }

        // Add default system variables
        $variables['app_name'] ??= Setting::appName();
        $variables['name'] ??= $user?->name ?? 'User';
        $variables['date'] ??= now()->format('d M Y');
        $variables['time'] ??= now()->format('h:i A');

        $message = $template->render($variables);
        $dltTemplateId = $template->dlt_template_id ?: (string) Setting::get('sms.two_factor_template_name');
        $senderId = $template->sender_id ?: (string) Setting::get('sms.two_factor_sender_id');

        return $this->send($phone, $message, $dltTemplateId, $senderId, $user, $templateCode, $variables);
    }

    /**
     * Send SMS via 2factor.in gateway with detailed response.
     *
     * @return array{success: bool, error: ?string}
     */
    protected function sendVia2FactorDetailed(string $phone, string $message, ?string $dltTemplateId = null, ?string $senderId = null): array
    {
        $apiKey = (string) Setting::get('sms.two_factor_api_key');
        $baseUrl = rtrim((string) Setting::get('sms.two_factor_base_url', 'https://2factor.in'), '/');
        $senderId = $senderId ?: (string) Setting::get('sms.two_factor_sender_id', 'SNTCSS');
        $timeout = (int) Setting::get('sms.http_timeout', 10);
        $retries = (int) Setting::get('sms.http_retry_attempts', 2);

        if (empty($apiKey)) {
            Log::warning('2factor.in API Key is missing. Falling back to log.');

            return $this->logSmsDetailed($phone, $message);
        }

        $cleanPhone = preg_replace('/^\+?91/', '', $phone);
        $url = "{$baseUrl}/API/V1/{$apiKey}/ADDON_SERVICES/SEND/TSMS";

        $response = Http::timeout($timeout)
            ->retry($retries, 200)
            ->asForm()
            ->post($url, [
                'From' => $senderId,
                'To' => $cleanPhone,
                'Msg' => $message,
                'TemplateName' => $dltTemplateId,
            ]);

        if ($response->successful()) {
            Log::info("SMS dispatched successfully via 2factor to {$phone}. Response: ".$response->body());

            return ['success' => true, 'error' => null];
        }

        Log::error("2factor.in SMS dispatch failed for {$phone}: ".$response->body());

        return ['success' => false, 'error' => '2factor API error: '.$response->body()];
    }

    /**
     * Send SMS via MSG91 gateway placeholder.
     *
     * @return array{success: bool, error: ?string}
     */
    protected function sendViaMsg91Detailed(string $phone, string $message, ?string $dltTemplateId = null, ?string $senderId = null): array
    {
        Log::info("MSG91 SMS to {$phone} (DLT: {$dltTemplateId}): {$message}");

        return ['success' => true, 'error' => null];
    }

    /**
     * Send SMS via Fast2SMS gateway placeholder.
     *
     * @return array{success: bool, error: ?string}
     */
    protected function sendViaFast2SmsDetailed(string $phone, string $message): array
    {
        Log::info("Fast2SMS to {$phone}: {$message}");

        return ['success' => true, 'error' => null];
    }

    /**
     * Log SMS dispatch for local development or testing.
     *
     * @return array{success: bool, error: ?string}
     */
    protected function logSmsDetailed(string $phone, string $message): array
    {
        Log::info("================ [SMS DISPATCH] ================\nTO: {$phone}\nBODY: {$message}\n================================================");

        return ['success' => true, 'error' => null];
    }

    /**
     * Normalize phone number to standard format with country code.
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', trim($phone));
        $defaultCountry = (string) Setting::get('sms.default_country_code', '+91');

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        // Strip leading 0 if present (e.g. 09876543210 -> 9876543210)
        $trimmed = ltrim($phone, '0');

        if (strlen($trimmed) === 10) {
            return $defaultCountry.$trimmed;
        }

        if (str_starts_with($trimmed, ltrim($defaultCountry, '+'))) {
            return '+'.$trimmed;
        }

        return '+'.$trimmed;
    }
}
