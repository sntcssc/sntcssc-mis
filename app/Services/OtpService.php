<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    public function __construct(
        protected SmsService $smsService,
        protected EmailService $emailService
    ) {}

    /**
     * Determine if a channel is available for the given identifier.
     */
    public function isChannelAvailable(string $identifier): bool
    {
        if ($this->isEmail($identifier)) {
            return EmailService::isEnabled();
        }

        return SmsService::isEnabled();
    }

    /**
     * Determine if the identifier is an email address.
     */
    public function isEmail(string $identifier): bool
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Normalize identifier (email or phone number).
     */
    public function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($this->isEmail($identifier)) {
            return strtolower($identifier);
        }

        return $this->smsService->normalizePhoneNumber($identifier);
    }

    /**
     * Check if a new OTP can be requested (cooldown check).
     *
     * @return array{can_resend: bool, wait_seconds: int}
     */
    public function checkCooldown(string $identifier, string $type, int $cooldownSeconds = 60): array
    {
        $normalized = $this->normalizeIdentifier($identifier);

        $latestOtp = OtpCode::query()
            ->where('identifier', $normalized)
            ->where('type', $type)
            ->latest('id')
            ->first();

        if (! $latestOtp) {
            return ['can_resend' => true, 'wait_seconds' => 0];
        }

        $elapsedSeconds = (int) $latestOtp->created_at->diffInSeconds(now());

        if ($elapsedSeconds < $cooldownSeconds) {
            $remaining = (int) ceil($cooldownSeconds - $elapsedSeconds);

            return [
                'can_resend' => false,
                'wait_seconds' => max(1, $remaining),
            ];
        }

        return ['can_resend' => true, 'wait_seconds' => 0];
    }

    /**
     * Generate, store, and dispatch an OTP to SMS or Email.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $extraVariables
     * @return array{success: bool, message: string, otp_id: ?int, cooldown_seconds: int, plain_code?: string}
     */
    public function generateAndSend(
        string $identifier,
        string $type,
        ?User $user = null,
        ?string $templateCode = null,
        array $metadata = [],
        array $extraVariables = []
    ): array {
        $normalized = $this->normalizeIdentifier($identifier);
        $isEmail = $this->isEmail($normalized);

        // 1. Check channel availability
        if ($isEmail && ! EmailService::isEnabled()) {
            return [
                'success' => false,
                'message' => __('Email service is currently disabled in system settings.'),
                'otp_id' => null,
                'cooldown_seconds' => 0,
            ];
        }

        if (! $isEmail && ! SmsService::isEnabled()) {
            return [
                'success' => false,
                'message' => __('SMS gateway is currently disabled in system settings.'),
                'otp_id' => null,
                'cooldown_seconds' => 0,
            ];
        }

        // 2. Cooldown check
        $cooldown = $this->checkCooldown($normalized, $type);
        if (! $cooldown['can_resend']) {
            $waitSeconds = (int) ceil($cooldown['wait_seconds']);

            return [
                'success' => false,
                'message' => __('Please wait :seconds seconds before requesting another code.', ['seconds' => $waitSeconds]),
                'otp_id' => null,
                'cooldown_seconds' => $waitSeconds,
            ];
        }

        // 3. Rate limiting by IP and identifier
        $throttleKey = 'otp-send:'.request()->ip().'|'.$normalized;
        if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
            $seconds = (int) ceil(RateLimiter::availableIn($throttleKey));

            return [
                'success' => false,
                'message' => __('Too many OTP requests. Please try again in :seconds seconds.', ['seconds' => $seconds]),
                'otp_id' => null,
                'cooldown_seconds' => $seconds,
            ];
        }
        RateLimiter::hit($throttleKey, 600);

        try {
            return DB::transaction(function () use ($normalized, $type, $user, $isEmail, $templateCode, $metadata, $extraVariables) {
                // Soft delete previous unverified OTPs of the same type for this identifier
                OtpCode::query()
                    ->where('identifier', $normalized)
                    ->where('type', $type)
                    ->whereNull('verified_at')
                    ->delete();

                $length = (int) Setting::get('sms.otp_length', 6);
                if (! in_array($length, [4, 6, 8])) {
                    $length = 6;
                }

                $expiryMinutes = (int) Setting::get('sms.otp_expiry_minutes', 5);
                if ($expiryMinutes < 1) {
                    $expiryMinutes = 5;
                }

                $min = 10 ** ($length - 1);
                $max = (10 ** $length) - 1;
                $plainCode = (string) random_int($min, $max);

                if (! $user) {
                    $user = $isEmail
                        ? User::where('email', $normalized)->first()
                        : User::where('phone', $normalized)->first();
                }

                $otp = OtpCode::create([
                    'user_id' => $user?->id,
                    'identifier' => $normalized,
                    'type' => $type,
                    'code_hash' => Hash::make($plainCode),
                    'attempts' => 0,
                    'max_attempts' => 5,
                    'expires_at' => now()->addMinutes($expiryMinutes),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'metadata' => $metadata,
                ]);

                // Map default template code if none provided
                $defaultTemplate = match ($type) {
                    OtpCode::TYPE_REGISTRATION_SMS, OtpCode::TYPE_REGISTRATION_EMAIL, OtpCode::TYPE_VERIFY_PHONE, OtpCode::TYPE_VERIFY_EMAIL => 'otp_registration',
                    OtpCode::TYPE_LOGIN_SMS, OtpCode::TYPE_LOGIN_EMAIL => 'otp_login',
                    OtpCode::TYPE_PASSWORD_RESET_SMS, OtpCode::TYPE_PASSWORD_RESET_EMAIL => 'otp_password_reset',
                    default => 'otp_registration',
                };

                $finalTemplateCode = $templateCode ?: $defaultTemplate;

                $variables = array_merge([
                    'name' => $user?->name ?? __('User'),
                    'otp' => $plainCode,
                    'expiry' => (string) $expiryMinutes,
                    'app_name' => Setting::appName(),
                ], $extraVariables);

                $dispatched = $isEmail
                    ? $this->emailService->sendTemplate($normalized, $finalTemplateCode, $variables, [], $user)
                    : $this->smsService->sendTemplate($normalized, $finalTemplateCode, $variables, $user);

                if (! $dispatched) {
                    Log::warning("OTP generation succeeded but message delivery failed for identifier: {$normalized}");
                }

                return [
                    'success' => true,
                    'message' => $isEmail
                        ? __('A verification code has been sent to your email.')
                        : __('A verification code has been sent to your mobile number.'),
                    'otp_id' => $otp->id,
                    'cooldown_seconds' => 60,
                    'plain_code' => app()->environment('local', 'testing') ? $plainCode : null,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('OTP generation failed: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => __('Failed to generate verification code. Please try again.'),
                'otp_id' => null,
                'cooldown_seconds' => 0,
            ];
        }
    }

    /**
     * Verify an OTP provided by the user.
     *
     * @return array{success: bool, message: string, user: ?User, otp: ?OtpCode}
     */
    public function verify(string $identifier, string $code, string $type): array
    {
        $normalized = $this->normalizeIdentifier($identifier);

        $throttleKey = 'otp-verify:'.request()->ip().'|'.$normalized;
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            return [
                'success' => false,
                'message' => __('Too many incorrect attempts. Please request a new code.'),
                'user' => null,
                'otp' => null,
            ];
        }

        try {
            $otp = OtpCode::query()
                ->where('identifier', $normalized)
                ->where('type', $type)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            if (! $otp) {
                RateLimiter::hit($throttleKey, 300);

                return [
                    'success' => false,
                    'message' => __('Invalid or expired verification code. Please request a new one.'),
                    'user' => null,
                    'otp' => null,
                ];
            }

            if ($otp->hasExceededAttempts()) {
                $otp->delete(); // Invalidate

                return [
                    'success' => false,
                    'message' => __('Maximum verification attempts exceeded. Please request a new code.'),
                    'user' => null,
                    'otp' => null,
                ];
            }

            if (! $otp->verifyCode($code)) {
                RateLimiter::hit($throttleKey, 300);
                $remaining = max(0, $otp->max_attempts - $otp->attempts);

                return [
                    'success' => false,
                    'message' => __('Incorrect verification code. :remaining attempts remaining.', ['remaining' => $remaining]),
                    'user' => null,
                    'otp' => null,
                ];
            }

            RateLimiter::clear($throttleKey);

            $user = $otp->user;
            if (! $user) {
                $user = $this->isEmail($normalized)
                    ? User::where('email', $normalized)->first()
                    : User::where('phone', $normalized)->first();
            }

            return [
                'success' => true,
                'message' => __('Verification successful.'),
                'user' => $user,
                'otp' => $otp,
            ];
        } catch (\Throwable $e) {
            Log::error('OTP verification error: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => __('An error occurred during verification. Please try again.'),
                'user' => null,
                'otp' => null,
            ];
        }
    }
}
