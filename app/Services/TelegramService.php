<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramService
{
    /**
     * Determine if Telegram channel is globally enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('notification.channel_telegram', true);
    }

    /**
     * Get active Telegram driver.
     */
    public static function driver(): string
    {
        return (string) Setting::get('telegram.driver', 'log');
    }

    /**
     * Send direct message to a Telegram chat ID or channel.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: ?string}
     */
    public function sendDirect(string $chatId, string $message, array $options = []): array
    {
        $chatId = trim($chatId);
        if (empty($chatId)) {
            $chatId = (string) Setting::get('telegram.default_chat_id');
        }

        if (empty($chatId)) {
            return [
                'success' => false,
                'error' => __('Invalid or empty Telegram Chat ID / Channel Username.'),
                'message_id' => null,
            ];
        }

        if (! static::isEnabled()) {
            return [
                'success' => false,
                'error' => __('Telegram notification channel is disabled in system settings.'),
                'message_id' => null,
            ];
        }

        $driver = static::driver();

        try {
            return match ($driver) {
                'telegram' => $this->sendViaBotApi($chatId, $message, $options),
                default => $this->sendViaLogDriver($chatId, $message, $options),
            };
        } catch (Throwable $e) {
            Log::error("Telegram dispatch error to {$chatId}: ".$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message_id' => null,
            ];
        }
    }

    /**
     * Send structured, rich-formatted message with optional inline action button.
     *
     * @return array{success: bool, error: ?string, message_id: ?string}
     */
    public function sendFormatted(
        string $title,
        string $body,
        ?string $chatId = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null
    ): array {
        $chatId = $chatId ?: (string) Setting::get('telegram.default_chat_id');
        $appName = Setting::appName();

        $text = "🔔 <b>{$appName} - {$title}</b>\n\n".strip_tags($body);

        $options = [
            'parse_mode' => 'HTML',
        ];

        if (! empty($actionUrl)) {
            $options['reply_markup'] = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => $actionLabel ?: __('Open in Portal'),
                            'url' => $actionUrl,
                        ],
                    ],
                ],
            ];
        }

        return $this->sendDirect($chatId, $text, $options);
    }

    /**
     * Telegram Bot API Dispatcher.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: ?string}
     */
    protected function sendViaBotApi(string $chatId, string $message, array $options = []): array
    {
        $botToken = (string) Setting::get('telegram.bot_token');

        if (empty($botToken)) {
            return [
                'success' => false,
                'error' => __('Telegram Bot Token is not configured in settings.'),
                'message_id' => null,
            ];
        }

        $endpoint = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => Setting::get('telegram.parse_mode', 'HTML'),
            'disable_web_page_preview' => false,
        ], $options);

        $response = Http::timeout((int) Setting::get('telegram.http_timeout', 15))
            ->retry((int) Setting::get('telegram.http_retries', 2), 200)
            ->post($endpoint, $payload);

        if ($response->successful() && $response->json('ok') === true) {
            $msgId = (string) ($response->json('result.message_id') ?? '');

            return [
                'success' => true,
                'error' => null,
                'message_id' => $msgId,
            ];
        }

        $error = $response->json('description') ?? $response->body();
        Log::warning("Telegram API error: {$error}", ['response' => $response->json()]);

        return [
            'success' => false,
            'error' => $error,
            'message_id' => null,
        ];
    }

    /**
     * Log driver for development and testing.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, error: ?string, message_id: string}
     */
    protected function sendViaLogDriver(string $chatId, string $message, array $options = []): array
    {
        $mockId = 'TG-LOG-'.strtoupper(bin2hex(random_bytes(6)));

        Log::info("TELEGRAM [LOG DRIVER] Chat: {$chatId} | ID: {$mockId} | Message: {$message}");

        return [
            'success' => true,
            'error' => null,
            'message_id' => $mockId,
        ];
    }

    /**
     * Query Telegram bot info to verify credentials.
     *
     * @return array{success: bool, bot: ?array, error: ?string}
     */
    public function getBotInfo(): array
    {
        $botToken = (string) Setting::get('telegram.bot_token');

        if (empty($botToken)) {
            return [
                'success' => false,
                'bot' => null,
                'error' => __('Bot token not provided.'),
            ];
        }

        try {
            $res = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/getMe");

            if ($res->successful() && $res->json('ok') === true) {
                return [
                    'success' => true,
                    'bot' => $res->json('result'),
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'bot' => null,
                'error' => $res->json('description') ?? __('Failed to connect to Telegram Bot API.'),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'bot' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send test message from admin settings panel.
     *
     * @return array{success: bool, message: string}
     */
    public function sendTestMessage(string $chatId, string $customMessage = ''): array
    {
        $appName = Setting::appName();
        $body = ! empty(trim($customMessage))
            ? $customMessage
            : "🔔 <b>{$appName} Test Notification</b>\n\nThis is a verification test message dispatched from the system settings panel.";

        $res = $this->sendDirect($chatId, $body, ['parse_mode' => 'HTML']);

        return [
            'success' => $res['success'],
            'message' => $res['success']
                ? __('Test Telegram message dispatched successfully! Message ID: :id', ['id' => $res['message_id'] ?? 'N/A'])
                : __('Telegram delivery failed: :error', ['error' => $res['error'] ?? 'Unknown']),
        ];
    }
}
