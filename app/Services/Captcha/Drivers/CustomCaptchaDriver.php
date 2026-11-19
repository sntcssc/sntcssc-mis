<?php

namespace App\Services\Captcha\Drivers;

use App\Models\Setting;
use App\Services\Captcha\CaptchaResult;
use App\Services\Captcha\Contracts\CaptchaDriverInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class CustomCaptchaDriver implements CaptchaDriverInterface
{
    public const TYPE_NUMBERS = 'numbers';

    public const TYPE_ALPHABETS = 'alphabets';

    public const TYPE_ALPHANUMERIC = 'alphanumeric';

    public const TYPE_SPECIAL = 'special_characters';

    public const TYPE_MATH = 'math';

    public function getName(): string
    {
        return 'custom';
    }

    public function getCharType(): string
    {
        return (string) Setting::get('captcha.custom_char_type', self::TYPE_ALPHANUMERIC);
    }

    public function getLength(): int
    {
        $len = (int) Setting::get('captcha.custom_length', 5);

        return max(3, min(8, $len));
    }

    public function isCaseSensitive(): bool
    {
        return (bool) Setting::get('captcha.custom_case_sensitive', false);
    }

    public function getNoiseLines(): int
    {
        $lines = (int) Setting::get('captcha.custom_noise_lines', 3);

        return max(0, min(8, $lines));
    }

    public function getNoiseDots(): int
    {
        $dots = (int) Setting::get('captcha.custom_noise_dots', 35);

        return max(0, min(100, $dots));
    }

    public function hasDistortion(): bool
    {
        return (bool) Setting::get('captcha.custom_distortion', true);
    }

    public function getExpireMinutes(): int
    {
        $mins = (int) Setting::get('captcha.custom_expire_minutes', 5);

        return max(1, min(30, $mins));
    }

    public function getMaxAttempts(): int
    {
        $attempts = (int) Setting::get('captcha.custom_max_attempts', 3);

        return max(1, min(10, $attempts));
    }

    /**
     * Generate dynamic challenge data (token, answer, display text, SVG image).
     */
    public function generateChallenge(array $options = []): array
    {
        $token = (string) Str::uuid();
        $charType = $options['char_type'] ?? $this->getCharType();
        $length = $options['length'] ?? $this->getLength();

        $displayCode = '';
        $expectedAnswer = '';
        $speechText = '';

        if ($charType === self::TYPE_MATH) {
            $num1 = random_int(3, 19);
            $num2 = random_int(1, 9);
            $operator = random_int(0, 1) === 0 ? '+' : '-';

            if ($operator === '-') {
                // Ensure non-negative answer
                if ($num2 > $num1) {
                    [$num1, $num2] = [$num2, $num1];
                }
                $answer = $num1 - $num2;
            } else {
                $answer = $num1 + $num2;
            }

            $displayCode = "{$num1} {$operator} {$num2} = ?";
            $expectedAnswer = (string) $answer;
            $speechText = "What is {$num1} ".($operator === '+' ? 'plus' : 'minus')." {$num2}?";
        } elseif ($charType === self::TYPE_NUMBERS) {
            $digits = '23456789';
            for ($i = 0; $i < $length; $i++) {
                $ch = $digits[random_int(0, strlen($digits) - 1)];
                $displayCode .= $ch;
            }
            $expectedAnswer = $displayCode;
            $speechText = implode(' ', str_split($displayCode));
        } elseif ($charType === self::TYPE_ALPHABETS) {
            $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
            for ($i = 0; $i < $length; $i++) {
                $ch = $pool[random_int(0, strlen($pool) - 1)];
                $displayCode .= $ch;
            }
            $expectedAnswer = $this->isCaseSensitive() ? $displayCode : strtolower($displayCode);
            $speechText = implode(' ', str_split($displayCode));
        } elseif ($charType === self::TYPE_SPECIAL) {
            $lettersNumbers = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
            $symbols = '@#$%&*!?';
            // Ensure at least 1 symbol and length-1 letters/numbers
            $chars = [];
            $chars[] = $symbols[random_int(0, strlen($symbols) - 1)];
            for ($i = 1; $i < $length; $i++) {
                $chars[] = $lettersNumbers[random_int(0, strlen($lettersNumbers) - 1)];
            }
            shuffle($chars);
            $displayCode = implode('', $chars);
            $expectedAnswer = $this->isCaseSensitive() ? $displayCode : strtolower($displayCode);
            $speechText = implode(' ', str_split($displayCode));
        } else {
            // Default: Alphanumeric
            $pool = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
            for ($i = 0; $i < $length; $i++) {
                $ch = $pool[random_int(0, strlen($pool) - 1)];
                $displayCode .= $ch;
            }
            $expectedAnswer = $this->isCaseSensitive() ? $displayCode : strtolower($displayCode);
            $speechText = implode(' ', str_split($displayCode));
        }

        // Cache the challenge encrypted
        $cacheKey = "captcha_challenge:{$token}";
        $expireAt = now()->addMinutes($this->getExpireMinutes());

        Cache::put($cacheKey, [
            'answer' => Crypt::encryptString($expectedAnswer),
            'display' => $displayCode,
            'speech' => $speechText,
            'attempts' => 0,
            'max_attempts' => $this->getMaxAttempts(),
            'case_sensitive' => $this->isCaseSensitive(),
            'char_type' => $charType,
        ], $expireAt);

        // Generate SVG
        $svg = $this->generateSvg($displayCode, $options);
        $svgDataUri = 'data:image/svg+xml;base64,'.base64_encode($svg);

        return [
            'token' => $token,
            'image' => $svgDataUri,
            'raw_svg' => $svg,
            'speech' => $speechText,
            'audio_url' => route('api.v1.captcha.audio', ['token' => $token]),
            'type' => $charType,
            'expires_at' => $expireAt->toIso8601String(),
            'expected_answer' => $expectedAnswer,
        ];
    }

    /**
     * Render the Custom CAPTCHA Widget HTML.
     */
    public function render(string $action = 'general', array $options = []): string
    {
        $challenge = $this->generateChallenge($options);
        $token = htmlspecialchars($challenge['token'], ENT_QUOTES, 'UTF-8');
        $imageUri = $challenge['image'];
        $speech = addslashes($challenge['speech']);
        $refreshUrl = route('api.v1.captcha.generate');
        $inputName = htmlspecialchars($options['input_name'] ?? 'captcha_answer', ENT_QUOTES, 'UTF-8');
        $tokenName = htmlspecialchars($options['token_name'] ?? 'captcha_token', ENT_QUOTES, 'UTF-8');
        $action = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $placeholder = $challenge['type'] === self::TYPE_MATH
            ? __('Answer the question')
            : ($this->isCaseSensitive() ? __('Enter exact characters') : __('Enter security code'));
        $placeholder = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');

        $isProduction = app()->isProduction() || in_array(config('app.env'), ['production', 'live'], true);
        $autofillAnswer = ! $isProduction ? addslashes($challenge['expected_answer'] ?? '') : '';
        $devBadge = ! $isProduction
            ? '<span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium bg-emerald-500/10 border border-emerald-500/20 px-1.5 py-0.5 rounded ml-1 select-none">'.__('Dev Autofill').'</span>'
            : '';

        return <<<HTML
<div
    class="custom-captcha-wrapper p-3 rounded-xl border border-border bg-card/80 shadow-xs space-y-2.5 my-3"
    x-data="{
        captchaToken: '{$token}',
        captchaImage: '{$imageUri}',
        captchaSpeech: '{$speech}',
        captchaAnswer: '{$autofillAnswer}',
        loading: false,
        audioPlaying: false,
        async refreshCaptcha() {
            this.loading = true;
            try {
                const res = await fetch('{$refreshUrl}?action={$action}');
                const data = await res.json();
                if (data.token && data.image) {
                    this.captchaToken = data.token;
                    this.captchaImage = data.image;
                    this.captchaSpeech = data.speech || '';
                    this.captchaAnswer = (data.autofill_answer !== undefined) ? data.autofill_answer : '';
                    const inputEl = this.\$refs.captchaInput;
                    if (inputEl) {
                        inputEl.value = this.captchaAnswer;
                        inputEl.focus();
                    }
                }
            } catch (e) {
                console.error('Captcha refresh failed', e);
            } finally {
                this.loading = false;
            }
        },
        speakCaptcha() {
            if ('speechSynthesis' in window && this.captchaSpeech) {
                window.speechSynthesis.cancel();
                const textToSpeak = this.captchaSpeech.includes('What is')
                    ? this.captchaSpeech
                    : this.captchaSpeech.split('').join(' ');
                const utter = new SpeechSynthesisUtterance(textToSpeak);
                utter.rate = 0.82;
                utter.pitch = 1.0;
                utter.lang = 'en-US';
                window.speechSynthesis.speak(utter);
                return;
            }
            if (this.audioPlaying) return;
            this.audioPlaying = true;
            try {
                const audio = new Audio('/api/v1/captcha/audio/' + this.captchaToken);
                audio.onended = () => { this.audioPlaying = false; };
                audio.onerror = () => { this.audioPlaying = false; };
                audio.play().catch(() => { this.audioPlaying = false; });
            } catch (e) {
                this.audioPlaying = false;
            }
        }
    }"
    x-init="if (captchaAnswer && \$refs.captchaInput) { \$refs.captchaInput.value = captchaAnswer; }"
>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold text-foreground flex items-center gap-1.5">
            <svg class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>
                <path d="m9 12 2 2 4-4"/>
            </svg>
            Security Verification
            {$devBadge}
        </span>
        <div class="flex items-center gap-1">
            <button
                type="button"
                x-on:click="speakCaptcha"
                class="p-1 rounded text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                title="Listen audio"
                aria-label="Listen audio readout"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path>
                </svg>
            </button>
            <button
                type="button"
                x-on:click="refreshCaptcha"
                class="captcha-refresh-btn p-1 rounded text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                :class="{ 'animate-spin': loading }"
                title="Get a new challenge"
                aria-label="Refresh security code"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                    <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                    <path d="M16 16h5v5"/>
                </svg>
            </button>
        </div>
    </div>

    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
        <div class="relative shrink-0 rounded-lg overflow-hidden border border-border bg-muted/40 flex items-center justify-center select-none">
            <img :src="captchaImage" alt="Security Captcha" class="h-11 w-44 sm:w-48 object-contain pointer-events-none" />
            <div x-show="loading" class="absolute inset-0 bg-background/60 flex items-center justify-center">
                <span class="text-xs text-muted-foreground font-medium animate-pulse">Refreshing...</span>
            </div>
        </div>

        <div class="flex-1 min-w-0">
            <input type="hidden" name="{$tokenName}" :value="captchaToken" class="captcha-token-input">
            <input
                x-ref="captchaInput"
                type="text"
                name="{$inputName}"
                value="{$autofillAnswer}"
                required
                autocomplete="off"
                autocorrect="off"
                autocapitalize="off"
                spellcheck="false"
                placeholder="{$placeholder}"
                class="captcha-answer-input w-full h-11 px-3 rounded-lg border border-input bg-background text-sm font-medium tracking-wider text-foreground placeholder:text-muted-foreground shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[2px] focus-visible:ring-ring/40 transition-colors"
            />
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Verify user response against stored challenge.
     */
    public function verify(Request $request, string $action = 'general', array $options = []): CaptchaResult
    {
        $token = $options['token']
            ?? $options['captcha_token']
            ?? ($request->input('captcha_token')
                ?: $request->input('otp_captcha_token')
                ?: $request->header('X-Captcha-Token')
                ?: $request->input('token'));

        $userAnswer = $options['answer']
            ?? $options['captcha_answer']
            ?? ($request->input('captcha_answer')
                ?: $request->input('otp_captcha_answer')
                ?: $request->input('captcha_code')
                ?: $request->header('X-Captcha-Answer'));

        if (empty($token) || empty($userAnswer)) {
            return CaptchaResult::failure(
                message: __('Please enter the security verification code.'),
                errorCode: 'missing-answer'
            );
        }

        $tokenStr = (string) $token;
        if (strlen($tokenStr) > 64 || ! preg_match('/^[a-zA-Z0-9\-_]+$/', $tokenStr)) {
            return CaptchaResult::failure(
                message: __('Invalid security verification token format.'),
                errorCode: 'invalid-token-format'
            );
        }

        $cacheKey = "captcha_challenge:{$tokenStr}";
        $challenge = Cache::get($cacheKey);

        if (! $challenge) {
            return CaptchaResult::failure(
                message: __('Security verification code has expired. Please refresh and try again.'),
                errorCode: 'expired-token'
            );
        }

        $attempts = ($challenge['attempts'] ?? 0) + 1;
        $maxAttempts = $challenge['max_attempts'] ?? $this->getMaxAttempts();

        try {
            $expected = Crypt::decryptString($challenge['answer']);
        } catch (\Throwable) {
            Cache::forget($cacheKey);

            return CaptchaResult::failure(
                message: __('Security verification payload corrupted. Please refresh.'),
                errorCode: 'decryption-failed'
            );
        }

        $caseSensitive = (bool) ($challenge['case_sensitive'] ?? false);
        $userNormalized = trim((string) $userAnswer);
        $expectedNormalized = trim((string) $expected);

        if (! $caseSensitive) {
            $userNormalized = strtolower($userNormalized);
            $expectedNormalized = strtolower($expectedNormalized);
        }

        // Constant-time string comparison to prevent timing attacks
        if (hash_equals($expectedNormalized, $userNormalized)) {
            // Successfully verified! Invalidate immediately to prevent reuse
            Cache::forget($cacheKey);

            return CaptchaResult::success(
                message: __('Security code verified successfully.'),
                score: 1.0,
                raw: ['attempts' => $attempts]
            );
        }

        // Incorrect answer
        if ($attempts >= $maxAttempts) {
            Cache::forget($cacheKey);

            return CaptchaResult::failure(
                message: __('Maximum verification attempts exceeded. Please refresh the security code.'),
                errorCode: 'max-attempts-exceeded',
                score: 0.0,
                raw: ['attempts' => $attempts]
            );
        }

        // Update attempts count in cache
        $challenge['attempts'] = $attempts;
        Cache::put($cacheKey, $challenge, now()->addMinutes($this->getExpireMinutes()));

        return CaptchaResult::failure(
            message: __('Invalid security verification code. Please try again.'),
            errorCode: 'mismatched-answer',
            score: 0.0,
            raw: ['attempts' => $attempts, 'remaining' => $maxAttempts - $attempts]
        );
    }

    /**
     * Generate an SVG image for the text with noise, rotation, and distortion.
     */
    protected function generateSvg(string $text, array $options = []): string
    {
        $width = 220;
        $height = 55;
        $characters = mb_str_split($text);
        $count = count($characters);

        $noiseLines = $options['noise_lines'] ?? $this->getNoiseLines();
        $noiseDots = $options['noise_dots'] ?? $this->getNoiseDots();
        $distortion = $options['distortion'] ?? $this->hasDistortion();

        // Background color & gradient
        $svgBg = '<rect width="100%" height="100%" rx="8" fill="url(#captchaGrad)" />';

        // Noise lines (wavy Bézier curves)
        $linesSvg = '';
        for ($i = 0; $i < $noiseLines; $i++) {
            $startX = random_int(0, 30);
            $startY = random_int(10, $height - 10);
            $ctrl1X = random_int(40, 90);
            $ctrl1Y = random_int(5, $height - 5);
            $ctrl2X = random_int(120, 180);
            $ctrl2Y = random_int(5, $height - 5);
            $endX = random_int($width - 30, $width);
            $endY = random_int(10, $height - 10);
            $strokeColor = random_int(0, 1) === 0 ? '#10b981' : '#6366f1';
            $strokeWidth = random_int(12, 22) / 10;
            $opacity = random_int(30, 60) / 100;

            $linesSvg .= "<path d=\"M{$startX},{$startY} C{$ctrl1X},{$ctrl1Y} {$ctrl2X},{$ctrl2Y} {$endX},{$endY}\" fill=\"none\" stroke=\"{$strokeColor}\" stroke-width=\"{$strokeWidth}\" opacity=\"{$opacity}\" />";
        }

        // Noise dots
        $dotsSvg = '';
        for ($i = 0; $i < $noiseDots; $i++) {
            $cx = random_int(5, $width - 5);
            $cy = random_int(5, $height - 5);
            $r = random_int(8, 22) / 10;
            $fill = random_int(0, 2) === 0 ? '#10b981' : (random_int(0, 1) === 0 ? '#0ea5e9' : '#8b5cf6');
            $opacity = random_int(25, 60) / 100;
            $dotsSvg .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"{$r}\" fill=\"{$fill}\" opacity=\"{$opacity}\" />";
        }

        // Character layout
        $charSvg = '';
        $step = ($width - 40) / max(1, $count);
        $fontFamilies = ['Arial, sans-serif', 'Verdana, sans-serif', 'Trebuchet MS, sans-serif', 'Impact, sans-serif'];

        foreach ($characters as $idx => $char) {
            $x = 22 + ($idx * $step) + random_int(-3, 3);
            $y = 36 + random_int(-4, 4);
            $rot = $distortion ? random_int(-22, 22) : 0;
            $fontFamily = $fontFamilies[random_int(0, count($fontFamilies) - 1)];
            $fontSize = random_int(22, 27);
            $textColor = random_int(0, 2) === 0 ? '#0f172a' : (random_int(0, 1) === 0 ? '#047857' : '#1e3a8a');

            $escapedChar = htmlspecialchars($char, ENT_XML1, 'UTF-8');
            $charSvg .= "<text x=\"{$x}\" y=\"{$y}\" font-family=\"{$fontFamily}\" font-size=\"{$fontSize}\" font-weight=\"bold\" fill=\"{$textColor}\" transform=\"rotate({$rot}, {$x}, {$y})\" text-anchor=\"middle\">{$escapedChar}</text>";
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
    <defs>
        <linearGradient id="captchaGrad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#f8fafc" />
            <stop offset="50%" stop-color="#f1f5f9" />
            <stop offset="100%" stop-color="#e2e8f0" />
        </linearGradient>
    </defs>
    {$svgBg}
    {$linesSvg}
    {$dotsSvg}
    {$charSvg}
</svg>
SVG;
    }
}
