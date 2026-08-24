<?php

namespace App\Http\Middleware;

use App\Services\EmailService;
use App\Services\SmsService;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhoneAndEmailVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // 1. Check Email verification if unverified
        if (($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) || is_null($user->email_verified_at)) {
            if ($request->expectsJson()) {
                abort(403, 'Your email address is not verified.');
            }

            return EmailService::isOtpVerification()
                ? redirect()->route('verify-otp', ['type' => 'email'])
                : redirect()->route($redirectToRoute ?: 'verification.notice');
        }

        // 2. Check Mobile verification if phone is present and SMS gateway is enabled
        if ($user->phone && is_null($user->phone_verified_at) && SmsService::isEnabled()) {
            return $request->expectsJson()
                ? abort(403, 'Your mobile number is not verified.')
                : redirect()->route('verify-otp', ['type' => 'phone']);
        }

        return $next($request);
    }
}
