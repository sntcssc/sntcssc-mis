<?php

namespace App\Services;

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class OtpLoginController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected SmsService $smsService
    ) {}

    /**
     * Request a one-time password for login via Email or SMS.
     */
    public function sendOtp(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
        ]);

        $identifier = trim($validated['identifier']);
        $isEmail = $this->otpService->isEmail($identifier);

        // Check if gateway is enabled
        if ($isEmail && ! EmailService::isEnabled()) {
            throw ValidationException::withMessages([
                'identifier' => [__('Email authentication is currently disabled in system settings.')],
            ]);
        }

        if (! $isEmail && ! SmsService::isEnabled()) {
            throw ValidationException::withMessages([
                'identifier' => [__('Mobile SMS authentication is currently disabled in system settings.')],
            ]);
        }

        // Find registered user
        $user = $isEmail
            ? User::where('email', strtolower($identifier))->first()
            : User::where('phone', $this->smsService->normalizePhoneNumber($identifier))->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => [__('No registered account found with this :field.', ['field' => $isEmail ? __('email address') : __('mobile number')])],
            ]);
        }

        $type = $isEmail ? OtpCode::TYPE_LOGIN_EMAIL : OtpCode::TYPE_LOGIN_SMS;
        $result = $this->otpService->generateAndSend(
            identifier: $identifier,
            type: $type,
            user: $user
        );

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'identifier' => [$result['message']],
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return back()->with('status', $result['message']);
    }

    /**
     * Verify the OTP code and authenticate the user.
     */
    public function verifyOtp(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'min:4', 'max:8'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $identifier = trim($validated['identifier']);
        $code = trim($validated['code']);
        $isEmail = $this->otpService->isEmail($identifier);
        $type = $isEmail ? OtpCode::TYPE_LOGIN_EMAIL : OtpCode::TYPE_LOGIN_SMS;

        $result = $this->otpService->verify($identifier, $code, $type);

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'code' => [$result['message']],
            ]);
        }

        /** @var User|null $user */
        $user = $result['user'];

        if (! $user) {
            $user = $isEmail
                ? User::where('email', strtolower($identifier))->first()
                : User::where('phone', $this->smsService->normalizePhoneNumber($identifier))->first();
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => [__('User account could not be found.')],
            ]);
        }

        // Log in user
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Determine redirect destination
        $redirectUrl = route('dashboard', ['current_team' => $user->current_team_id ?: $user->personalTeam()?->id]);

        if (is_null($user->email_verified_at)) {
            $redirectUrl = route('verification.notice');
        } elseif ($user->phone && is_null($user->phone_verified_at) && SmsService::isEnabled()) {
            $redirectUrl = route('verify-otp', ['type' => 'phone']);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('Sign in successful.'),
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect()->intended($redirectUrl);
    }
}
