<?php

namespace App\Http\Middleware;

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

        // 1. Check Email verification if required
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : redirect()->route($redirectToRoute ?: 'verification.notice');
        }

        // Also check if email is unverified
        if (is_null($user->email_verified_at)) {
            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
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
