<?php

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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class OtpPasswordResetController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected SmsService $smsService
    ) {}

    /**
     * Send OTP for password reset via Email or SMS.
     */
    public function sendOtp(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
        ]);

        $identifier = trim($validated['identifier']);
        $isEmail = $this->otpService->isEmail($identifier);

        if ($isEmail && ! EmailService::isEnabled()) {
            throw ValidationException::withMessages([
                'identifier' => [__('Email service is currently disabled in system settings.')],
            ]);
        }

        if (! $isEmail && ! SmsService::isEnabled()) {
            throw ValidationException::withMessages([
                'identifier' => [__('SMS gateway is currently disabled in system settings.')],
            ]);
        }

        $user = $isEmail
            ? User::where('email', strtolower($identifier))->first()
            : User::where('phone', $this->smsService->normalizePhoneNumber($identifier))->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => [__('No account found with this :field.', ['field' => $isEmail ? __('email address') : __('mobile number')])],
            ]);
        }

        $type = $isEmail ? OtpCode::TYPE_PASSWORD_RESET_EMAIL : OtpCode::TYPE_PASSWORD_RESET_SMS;
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
     * Verify OTP and reset the user's password.
     */
    public function verifyAndReset(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'min:4', 'max:8'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $identifier = trim($validated['identifier']);
        $code = trim($validated['code']);
        $isEmail = $this->otpService->isEmail($identifier);
        $type = $isEmail ? OtpCode::TYPE_PASSWORD_RESET_EMAIL : OtpCode::TYPE_PASSWORD_RESET_SMS;

        $result = $this->otpService->verify($identifier, $code, $type);

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'code' => [$result['message']],
            ]);
        }

        $user = $result['user'];
        if (! $user) {
            $user = $isEmail
                ? User::where('email', strtolower($identifier))->first()
                : User::where('phone', $this->smsService->normalizePhoneNumber($identifier))->first();
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'identifier' => [__('User account not found.')],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('Your password has been reset successfully.'),
                'redirect_url' => route('login'),
            ]);
        }

        return redirect()->route('login')->with('status', __('Your password has been reset successfully. Please sign in.'));
    }
}
