<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OtpVerificationController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected SmsService $smsService
    ) {}

    /**
     * Send or resend an OTP for phone or email verification.
     */
    public function sendOtp(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $targetType = $request->input('type', 'phone');

        $identifier = $targetType === 'email'
            ? ($user?->email ?? $request->input('identifier'))
            : ($user?->phone ?? $request->input('identifier'));

        if (! $identifier) {
            throw ValidationException::withMessages([
                'identifier' => [__('A valid mobile number or email is required.')],
            ]);
        }

        $isEmail = $this->otpService->isEmail($identifier);
        $type = $isEmail ? OtpCode::TYPE_VERIFY_EMAIL : OtpCode::TYPE_VERIFY_PHONE;

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
     * Verify the entered code and mark phone or email as verified.
     */
    public function verifyOtp(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:8'],
            'identifier' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:phone,email'],
        ]);

        /** @var User|null $user */
        $user = $request->user();
        $targetType = $validated['type'] ?? 'phone';

        $identifier = $validated['identifier']
            ?? ($targetType === 'email' ? $user?->email : $user?->phone);

        if (! $identifier) {
            throw ValidationException::withMessages([
                'code' => [__('Could not determine verification recipient.')],
            ]);
        }

        $isEmail = $this->otpService->isEmail($identifier);
        $type = $isEmail ? OtpCode::TYPE_VERIFY_EMAIL : OtpCode::TYPE_VERIFY_PHONE;

        $result = $this->otpService->verify($identifier, $validated['code'], $type);

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'code' => [$result['message']],
            ]);
        }

        // Mark as verified on User model
        $targetUser = $user ?? $result['user'];
        if ($targetUser) {
            if ($isEmail) {
                $targetUser->markEmailAsVerified();
            } else {
                $targetUser->markPhoneAsVerified();
            }
        }

        $redirectUrl = $targetUser
            ? route('dashboard', ['current_team' => $targetUser->current_team_id ?: $targetUser->personalTeam()?->id])
            : route('login');

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('Verification successful.'),
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', __('Verification successful!'));
    }
}
