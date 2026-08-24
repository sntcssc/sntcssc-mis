<?php

namespace App\Services;

use App\Models\CommunicationLog;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /**
     * Determine whether Email dispatching is enabled in system settings.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('email.is_enabled', true);
    }

    /**
     * Send email directly and return detailed status.
     *
     * @param  array{cc?: string|array<string>, bcc?: string|array<string>, from_address?: string, from_name?: string}  $options
     * @return array{success: bool, error: ?string}
     */
    public function sendDirect(string $email, string $subject, string $htmlBody, array $options = []): array
    {
        if (! self::isEnabled()) {
            Log::info("Email Service is disabled in settings. Suppressed email to {$email}: {$subject}");

            return [
                'success' => false,
                'error' => __('Email service is currently disabled in system settings.'),
            ];
        }

        try {
            $fromAddress = $options['from_address'] ?? (string) Setting::get('email.from_address', config('mail.from.address', 'noreply@sntcssc.in'));
            $fromName = $options['from_name'] ?? (string) Setting::get('email.from_name', Setting::appName());
            $defaultCc = (string) Setting::get('email.cc_to');

            Mail::html($htmlBody, function ($message) use ($email, $subject, $fromAddress, $fromName, $options, $defaultCc) {
                $message->to($email)
                    ->subject($subject)
                    ->from($fromAddress, $fromName);

                if (! empty($options['cc'])) {
                    $message->cc($options['cc']);
                } elseif (! empty($defaultCc)) {
                    $message->cc(array_filter(array_map('trim', explode(',', $defaultCc))));
                }

                if (! empty($options['bcc'])) {
                    $message->bcc($options['bcc']);
                }
            });

            Log::info("Email dispatched successfully to {$email} with subject '{$subject}'.");

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::error("Email delivery failed to {$email}: ".$e->getMessage(), [
                'exception' => $e,
                'email' => $email,
                'subject' => $subject,
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send an email with raw HTML content and record in communication logs.
     *
     * @param  array{cc?: string|array<string>, bcc?: string|array<string>, from_address?: string, from_name?: string}  $options
     */
    public function send(string $email, string $subject, string $htmlBody, array $options = [], ?User $user = null, ?string $templateCode = null, array $variables = []): bool
    {
        $result = $this->sendDirect($email, $subject, $htmlBody, $options);

        // Record communication log
        try {
            $email = trim(strtolower($email));
            $recipientName = $user?->name ?? ($variables['name'] ?? null);
            if (! $user && $email) {
                $user = User::where('email', $email)->first();
                $recipientName ??= $user?->name;
            }

            CommunicationLog::create([
                'channel' => CommunicationLog::CHANNEL_EMAIL,
                'type' => $templateCode ? CommunicationLog::TYPE_NOTIFICATION : CommunicationLog::TYPE_CUSTOM_INDIVIDUAL,
                'recipient' => $email,
                'recipient_name' => $recipientName,
                'user_id' => $user?->id,
                'template_code' => $templateCode,
                'subject' => $subject,
                'content' => $htmlBody,
                'variables' => $variables,
                'metadata' => [
                    'options' => $options,
                ],
                'status' => $result['success'] ? CommunicationLog::STATUS_SENT : CommunicationLog::STATUS_FAILED,
                'error_message' => $result['error'] ?? null,
                'sent_by' => auth()->id(),
                'sent_at' => now(),
                'delivered_at' => $result['success'] ? now() : null,
                'failed_at' => $result['success'] ? null : now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write CommunicationLog for Email: '.$e->getMessage());
        }

        return $result['success'];
    }

    /**
     * Send email by rendering a registered database template.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    public function sendTemplate(string $email, string $templateCode, array $variables = [], array $options = [], ?User $user = null): bool
    {
        $template = EmailTemplate::active()->where('code', $templateCode)->first();

        if (! $template) {
            Log::warning("EmailTemplate with code '{$templateCode}' not found or inactive.");

            return false;
        }

        // Add default system variables
        $variables['app_name'] ??= Setting::appName();
        $variables['email'] ??= $email;
        $variables['name'] ??= $user?->name ?? 'User';
        $variables['date'] ??= now()->format('d M Y');
        $variables['year'] ??= date('Y');

        $rendered = $template->render($variables);

        return $this->send($email, $rendered['subject'], $rendered['body'], $options, $user, $templateCode, $variables);
    }
}
