<?php

namespace App\Services\Captcha\Drivers;

use App\Models\Setting;
use App\Services\Captcha\CaptchaResult;
use App\Services\Captcha\Contracts\CaptchaDriverInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleRecaptchaDriver implements CaptchaDriverInterface
{
    public function getName(): string
    {
        return 'google';
    }

    public function getVersion(): string
    {
        return (string) Setting::get('captcha.google_version', 'v2_checkbox');
    }

    public function getSiteKey(): string
    {
        return (string) (Setting::get('captcha.google_site_key') ?? config('services.recaptcha.site_key', ''));
    }

    public function getSecretKey(): string
    {
        return (string) (Setting::get('captcha.google_secret_key') ?? config('services.recaptcha.secret_key', ''));
    }

    public function getDomain(): string
    {
        $domain = (string) Setting::get('captcha.google_domain', 'www.google.com');

        return in_array($domain, ['www.google.com', 'www.recaptcha.net'], true) ? $domain : 'www.google.com';
    }

    public function getScoreThreshold(): float
    {
        return (float) Setting::get('captcha.google_score_threshold', 0.5);
    }

    /**
     * Render the Google reCAPTCHA widget / script HTML.
     */
    public function render(string $action = 'general', array $options = []): string
    {
        $siteKey = htmlspecialchars($this->getSiteKey(), ENT_QUOTES, 'UTF-8');
        $version = $this->getVersion();
        $domain = $this->getDomain();
        $action = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

        if (empty($siteKey)) {
            return '<div class="p-3 text-xs text-amber-700 dark:text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-lg">'
                .__('Google reCAPTCHA site key is not configured. Please update settings.')
                .'</div>';
        }

        $widgetId = 'recaptcha_'.bin2hex(random_bytes(4));

        if ($version === 'v3') {
            return <<<HTML
<div id="{$widgetId}_container" class="recaptcha-v3-container">
    <input type="hidden" name="g-recaptcha-response" id="{$widgetId}">
    <script src="https://{$domain}/recaptcha/api.js?render={$siteKey}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof grecaptcha !== 'undefined') {
                grecaptcha.ready(function() {
                    grecaptcha.execute('{$siteKey}', { action: '{$action}' }).then(function(token) {
                        var el = document.getElementById('{$widgetId}');
                        if (el) el.value = token;
                    });
                });
            }
        });
        if (window.Livewire) {
            document.addEventListener('livewire:navigated', function() {
                if (typeof grecaptcha !== 'undefined') {
                    grecaptcha.ready(function() {
                        grecaptcha.execute('{$siteKey}', { action: '{$action}' }).then(function(token) {
                            var el = document.getElementById('{$widgetId}');
                            if (el) el.value = token;
                        });
                    });
                }
            });
        }
    </script>
</div>
HTML;
        }

        if ($version === 'v2_invisible') {
            return <<<HTML
<div id="{$widgetId}_container" class="recaptcha-v2-invisible-container">
    <div class="g-recaptcha" data-sitekey="{$siteKey}" data-size="invisible" data-callback="onInvisibleRecaptchaSuccess"></div>
    <script src="https://{$domain}/recaptcha/api.js" async defer></script>
</div>
HTML;
        }

        // Default: v2 Checkbox
        return <<<HTML
<div id="{$widgetId}_container" class="my-3 flex flex-col items-center justify-center">
    <div class="g-recaptcha" data-sitekey="{$siteKey}" data-theme="auto"></div>
    <script src="https://{$domain}/recaptcha/api.js" async defer></script>
    <script>
        if (typeof window.initRecaptchaOnDemand === 'undefined') {
            window.initRecaptchaOnDemand = function() {
                if (typeof grecaptcha !== 'undefined' && typeof grecaptcha.render === 'function') {
                    document.querySelectorAll('.g-recaptcha').forEach(function(el) {
                        if (!el.hasChildNodes() || el.children.length === 0) {
                            try {
                                grecaptcha.render(el);
                            } catch(e) {}
                        }
                    });
                }
            };
        }
        if (window.Livewire) {
            document.addEventListener('livewire:navigated', function() {
                window.initRecaptchaOnDemand?.();
            });
        }
    </script>
</div>
HTML;
    }

    /**
     * Verify incoming reCAPTCHA response token against Google servers.
     */
    public function verify(Request $request, string $action = 'general', array $options = []): CaptchaResult
    {
        $token = $options['token']
            ?? $options['g-recaptcha-response']
            ?? ($request->input('g-recaptcha-response')
                ?: $request->header('X-Recaptcha-Token')
                ?: $request->input('recaptcha_token')
                ?: $request->input('captcha_token')
                ?: $request->input('otp_captcha_token'));

        if (empty($token)) {
            return CaptchaResult::failure(
                message: __('Please complete the Google reCAPTCHA verification.'),
                errorCode: 'missing-input-response'
            );
        }

        $secretKey = $this->getSecretKey();
        if (empty($secretKey)) {
            Log::error('Google reCAPTCHA verification failed: secret key is missing in settings.');

            return CaptchaResult::failure(
                message: __('Google reCAPTCHA configuration error. Please contact administrator.'),
                errorCode: 'missing-secret-key'
            );
        }

        $domain = $this->getDomain();
        $verifyUrl = "https://{$domain}/recaptcha/api/siteverify";

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->retry(2, 200, throw: false)
                ->post($verifyUrl, [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);

            if (! $response->successful()) {
                Log::warning('Google reCAPTCHA endpoint returned HTTP '.$response->status(), [
                    'body' => $response->body(),
                ]);

                return CaptchaResult::failure(
                    message: __('Could not verify reCAPTCHA with Google servers. Please try again.'),
                    errorCode: 'http-error-'.$response->status()
                );
            }

            $data = $response->json() ?? [];
            $isSuccess = (bool) ($data['success'] ?? false);

            if (! $isSuccess) {
                $errorCodes = $data['error-codes'] ?? ['invalid-input-response'];
                $errorCodeStr = is_array($errorCodes) ? implode(', ', $errorCodes) : (string) $errorCodes;

                return CaptchaResult::failure(
                    message: __('Google reCAPTCHA verification failed. Please try again.'),
                    errorCode: $errorCodeStr,
                    raw: $data
                );
            }

            // For v3, evaluate score threshold
            if ($this->getVersion() === 'v3') {
                $score = (float) ($data['score'] ?? 0.0);
                $threshold = $this->getScoreThreshold();

                if ($score < $threshold) {
                    return CaptchaResult::failure(
                        message: __('Verification rejected due to suspicious bot activity score.'),
                        errorCode: 'score-below-threshold',
                        score: $score,
                        raw: $data
                    );
                }

                return CaptchaResult::success(
                    message: __('Google reCAPTCHA v3 verified.'),
                    score: $score,
                    raw: $data
                );
            }

            return CaptchaResult::success(
                message: __('Google reCAPTCHA verified.'),
                score: 1.0,
                raw: $data
            );
        } catch (Throwable $e) {
            Log::error('Exception during Google reCAPTCHA verification: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return CaptchaResult::failure(
                message: __('Security verification failed. Please refresh and try again.'),
                errorCode: 'exception-'.$e->getCode(),
                raw: ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Generate challenge metadata.
     */
    public function generateChallenge(array $options = []): array
    {
        return [
            'driver' => 'google',
            'version' => $this->getVersion(),
            'site_key' => $this->getSiteKey(),
            'domain' => $this->getDomain(),
        ];
    }

    /**
     * Diagnostic handshake test for admin settings panel.
     */
    public function testConnection(string $siteKey, string $secretKey, string $domain = 'www.google.com'): array
    {
        $verifyUrl = "https://{$domain}/recaptcha/api/siteverify";

        try {
            $start = microtime(true);
            // Sending an empty dummy response will verify whether Google accepts the secret key
            // (If the secret key is invalid, Google returns error code 'invalid-input-secret')
            // (If the secret key is valid, Google returns error code 'missing-input-response')
            $response = Http::asForm()
                ->timeout(6)
                ->post($verifyUrl, [
                    'secret' => $secretKey,
                    'response' => 'test-probe-token',
                ]);

            $elapsed = round((microtime(true) - $start) * 1000);
            $data = $response->json() ?? [];
            $errorCodes = (array) ($data['error-codes'] ?? []);

            if (in_array('invalid-input-secret', $errorCodes, true)) {
                return [
                    'status' => 'error',
                    'message' => __('Google rejected the Secret Key. Please double-check your credentials.'),
                    'response_time_ms' => $elapsed,
                    'details' => $data,
                ];
            }

            return [
                'status' => 'success',
                'message' => __('Connected to Google reCAPTCHA successfully! Secret key accepted.'),
                'response_time_ms' => $elapsed,
                'details' => $data,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => __('Connection failed: ').$e->getMessage(),
                'response_time_ms' => 0,
                'details' => ['exception' => $e->getMessage()],
            ];
        }
    }
}
