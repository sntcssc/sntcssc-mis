<?php

namespace App\Http\Middleware;

use App\Services\Captcha\CaptchaService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ValidateCaptchaMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $action = 'general'): Response
    {
        if (CaptchaService::isEnabled($action)) {
            $result = CaptchaService::verifyRequest($request, $action);

            if ($result->isFailure()) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $result->message ?? __('Security verification failed. Please try again.'),
                        'errors' => [
                            'captcha' => [$result->message ?? __('Security verification failed. Please try again.')],
                        ],
                    ], 422);
                }

                throw ValidationException::withMessages([
                    'captcha' => [$result->message ?? __('Security verification failed. Please try again.')],
                ]);
            }
        }

        return $next($request);
    }
}
