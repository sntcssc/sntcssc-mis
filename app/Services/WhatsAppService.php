<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppService
{
    /**
     * Determine if WhatsApp channel is globally enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('notification.channel_whatsapp', true);
    }

    /**
     * Get active WhatsApp gateway driver.
     */
    public static function driver(): string
    {
        return (string) Setting::get('whatsapp.driver', 'log');
    }

    /**
     * Send direct WhatsApp text message.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: ?string}
     */
    public function sendDirect(string $phone, string $message, array $options = []): array
    {
        $normalizedPhone = $this->normalizePhoneNumber($phone);

        if (empty($normalizedPhone)) {
            return [
                'success' => false,
                'error' => __('Invalid or empty WhatsApp recipient phone number.'),
                'message_id' => null,
            ];
        }

        if (! static::isEnabled()) {
            return [
                'success' => false,
                'error' => __('WhatsApp notification channel is disabled in system settings.'),
                'message_id' => null,
            ];
        }

        $driver = static::driver();

        try {
            return match ($driver) {
                'meta' => $this->sendViaMetaCloudApi($normalizedPhone, $message, $options),
                'twilio' => $this->sendViaTwilio($normalizedPhone, $message, $options),
                'ultramsg' => $this->sendViaUltraMsg($normalizedPhone, $message, $options),
                default => $this->sendViaLogDriver($normalizedPhone, $message, $options),
            };
        } catch (Throwable $e) {
            Log::error("WhatsApp dispatch error to {$normalizedPhone}: ".$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message_id' => null,
            ];
        }
    }

    /**
     * Normalize international phone numbers for WhatsApp (e.g. removes +, dashes, spaces).
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (empty($cleaned)) {
            return '';
        }

        // If no country code and length is 10 (standard Indian mobile), prepend 91
        $defaultCountry = (string) Setting::get('sms.default_country_code', '91');
        $defaultCountry = preg_replace('/[^0-9]/', '', $defaultCountry) ?: '91';

        if (strlen($cleaned) === 10) {
            $cleaned = $defaultCountry.$cleaned;
        }

        return $cleaned;
    }

    /**
     * Meta Cloud API (Official WhatsApp Graph API).
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: ?string}
     */
    protected function sendViaMetaCloudApi(string $phone, string $message, array $options = []): array
    {
        $token = (string) Setting::get('whatsapp.api_token');
        $phoneNumberId = (string) Setting::get('whatsapp.phone_number_id');
        $baseUrl = (string) Setting::get('whatsapp.base_url', 'https://graph.facebook.com/v21.0');
        $baseUrl = rtrim($baseUrl, '/');

        if (empty($token) || empty($phoneNumberId)) {
            return [
                'success' => false,
                'error' => __('Meta WhatsApp Cloud API token or Phone Number ID is not configured.'),
                'message_id' => null,
            ];
        }

        $endpoint = "{$baseUrl}/{$phoneNumberId}/messages";

        $response = Http::withToken($token)
            ->timeout((int) Setting::get('whatsapp.http_timeout', 15))
            ->retry((int) Setting::get('whatsapp.http_retries', 2), 200)
            ->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message,
                ],
            ]);

        if ($response->successful()) {
            $json = $response->json();
            $msgId = $json['messages'][0]['id'] ?? null;

            return [
                'success' => true,
                'error' => null,
                'message_id' => $msgId,
            ];
        }

        $error = $response->json('error.message') ?? $response->body();
        Log::warning("WhatsApp Meta API error: {$error}", ['response' => $response->json()]);

        return [
            'success' => false,
            'error' => $error,
            'message_id' => null,
        ];
    }

    /**
     * Twilio WhatsApp Dispatcher.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: ?string}
     */
    protected function sendViaTwilio(string $phone, string $message, array $options = []): array
    {
        $sid = (string) Setting::get('whatsapp.twilio_sid');
        $token = (string) Setting::get('whatsapp.twilio_token');
        $from = (string) Setting::get('whatsapp.twilio_from');

        if (empty($sid) || empty($token) || empty($from)) {
            return [
                'success' => false,
                'error' => __('Twilio WhatsApp credentials are incomplete.'),
                'message_id' => null,
            ];
        }

        $endpoint = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post($endpoint, [
                'From' => str_starts_with($from, 'whatsapp:') ? $from : "whatsapp:{$from}",
                'To' => "whatsapp:+{$phone}",
                'Body' => $message,
            ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'error' => null,
                'message_id' => $response->json('sid'),
            ];
        }

        return [
            'success' => false,
            'error' => $response->json('message') ?? $response->body(),
            'message_id' => null,
        ];
    }

    /**
     * UltraMsg WhatsApp Dispatcher.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: ?string}
     */
    protected function sendViaUltraMsg(string $phone, string $message, array $options = []): array
    {
        $instanceId = (string) Setting::get('whatsapp.ultramsg_instance_id');
        $token = (string) Setting::get('whatsapp.ultramsg_token');

        if (empty($instanceId) || empty($token)) {
            return [
                'success' => false,
                'error' => __('UltraMsg WhatsApp instance ID or token is missing.'),
                'message_id' => null,
            ];
        }

        $endpoint = "https://api.ultramsg.com/{$instanceId}/messages/chat";

        $response = Http::asForm()->post($endpoint, [
            'token' => $token,
            'to' => $phone,
            'body' => $message,
        ]);

        if ($response->successful() && ($response->json('sent') === 'true' || isset($response->json()['id']))) {
            return [
                'success' => true,
                'error' => null,
                'message_id' => (string) ($response->json('id') ?? ''),
            ];
        }

        return [
            'success' => false,
            'error' => $response->json('error') ?? $response->body(),
            'message_id' => null,
        ];
    }

    /**
     * Log driver for development and testing.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: string}
     */
    protected function sendViaLogDriver(string $phone, string $message, array $options = []): array
    {
        $mockId = 'WA-LOG-'.strtoupper(bin2hex(random_bytes(6)));

        Log::info("WHATSAPP [LOG DRIVER] To: {$phone} | ID: {$mockId} | Message: {$message}");

        return [
            'success' => true,
            'error' => null,
            'message_id' => $mockId,
        ];
    }

    /**
     * Send test WhatsApp message from admin panel.
     *
     * @return array{success: bool, message: string}
     */
    public function sendTestMessage(string $phone, string $customMessage = ''): array
    {
        $body = ! empty(trim($customMessage))
            ? $customMessage
            : __('This is a test notification from :app to verify WhatsApp Gateway delivery.', ['app' => Setting::appName()]);

        $res = $this->sendDirect($phone, $body);

        return [
            'success' => $res['success'],
            'message' => $res['success']
                ? __('Test WhatsApp message dispatched successfully! Message ID: :id', ['id' => $res['message_id'] ?? 'N/A'])
                : __('WhatsApp delivery failed: :error', ['error' => $res['error'] ?? 'Unknown']),
        ];
    }
}
