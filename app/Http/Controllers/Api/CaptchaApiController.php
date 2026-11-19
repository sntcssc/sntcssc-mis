<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptchaLog;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\Captcha\CaptchaAudioGenerator;
use App\Services\Captcha\CaptchaService;
use App\Services\Captcha\Drivers\CustomCaptchaDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class CaptchaApiController extends Controller
{
    /**
     * Client configuration specifications for multi-platform bootstrap.
     */
    public function specs(Request $request): JsonResponse
    {
        $isProduction = app()->isProduction() || in_array(config('app.env'), ['production', 'live'], true);
        $activeDriver = CaptchaService::activeDriverName();

        $googleSiteKey = (string) Setting::get('captcha.google_site_key', '');

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => CaptchaService::isEnabled(),
                'active_driver' => $activeDriver,
                'supported_drivers' => ['custom', 'google'],
                'protected_actions' => [
                    'login' => CaptchaService::isEnabled('login'),
                    'register' => CaptchaService::isEnabled('register'),
                    'forgot_password' => CaptchaService::isEnabled('forgot_password'),
                    'contact_us' => CaptchaService::isEnabled('contact_us'),
                    'contact' => CaptchaService::isEnabled('contact'),
                    'api' => CaptchaService::isEnabled('api'),
                ],
                'google' => [
                    'version' => Setting::get('captcha.google_version', 'v2_checkbox'),
                    'site_key' => $googleSiteKey,
                    'domain' => Setting::get('captcha.google_domain', 'www.google.com'),
                    'score_threshold' => (float) Setting::get('captcha.google_score_threshold', 0.5),
                ],
                'custom' => [
                    'char_type' => Setting::get('captcha.custom_char_type', CustomCaptchaDriver::TYPE_ALPHANUMERIC),
                    'length' => (int) Setting::get('captcha.custom_length', 5),
                    'case_sensitive' => (bool) Setting::get('captcha.custom_case_sensitive', false),
                    'expire_minutes' => (int) Setting::get('captcha.custom_expire_minutes', 5),
                    'max_attempts' => (int) Setting::get('captcha.custom_max_attempts', 3),
                    'audio_supported' => true,
                ],
                'client_endpoints' => [
                    'specs' => route('api.v1.captcha.specs'),
                    'generate' => route('api.v1.captcha.generate'),
                    'verify' => route('api.v1.captcha.verify'),
                    'refresh' => route('api.v1.captcha.refresh'),
                    'audio' => url('/api/v1/captcha/audio/{token}'),
                    'status' => route('api.v1.captcha.status'),
                    'diagnostics' => route('api.v1.captcha.diagnostics'),
                ],
                'environment' => config('app.env'),
                'dev_autofill_enabled' => ! $isProduction,
            ],
        ]);
    }

    /**
     * Generate dynamic CAPTCHA challenge data.
     */
    public function generate(Request $request): JsonResponse
    {
        $action = $request->query('action', 'general');

        if (! CaptchaService::isEnabled($action)) {
            return response()->json([
                'enabled' => false,
                'message' => 'Captcha protection is disabled.',
            ]);
        }

        $driver = CaptchaService::driver();
        $challenge = $driver->generateChallenge([
            'action' => $action,
        ]);

        $isProduction = app()->isProduction() || in_array(config('app.env'), ['production', 'live'], true);
        $expectedAnswer = $challenge['expected_answer'] ?? null;
        unset($challenge['expected_answer']);

        if (! $isProduction && $expectedAnswer !== null) {
            $challenge['autofill_answer'] = $expectedAnswer;
        }

        if (! isset($challenge['audio_url']) && isset($challenge['token'])) {
            $challenge['audio_url'] = route('api.v1.captcha.audio', ['token' => $challenge['token']]);
        }

        return response()->json([
            'enabled' => true,
            'driver' => $driver->getName(),
            ...$challenge,
        ]);
    }

    /**
     * Invalidate previous token and issue a fresh challenge in a single round-trip.
     */
    public function refresh(Request $request): JsonResponse
    {
        $action = $request->input('action', 'general');
        $oldToken = $request->input('old_token', $request->input('token'));

        // Invalidate old token from cache
        if (! empty($oldToken)) {
            Cache::forget("captcha_challenge:{$oldToken}");
        }

        if (! CaptchaService::isEnabled($action)) {
            return response()->json([
                'enabled' => false,
                'message' => 'Captcha protection is disabled.',
            ]);
        }

        $driver = CaptchaService::driver();
        $challenge = $driver->generateChallenge([
            'action' => $action,
        ]);

        $isProduction = app()->isProduction() || in_array(config('app.env'), ['production', 'live'], true);
        $expectedAnswer = $challenge['expected_answer'] ?? null;
        unset($challenge['expected_answer']);

        if (! $isProduction && $expectedAnswer !== null) {
            $challenge['autofill_answer'] = $expectedAnswer;
        }

        if (! isset($challenge['audio_url']) && isset($challenge['token'])) {
            $challenge['audio_url'] = route('api.v1.captcha.audio', ['token' => $challenge['token']]);
        }

        return response()->json([
            'success' => true,
            'enabled' => true,
            'driver' => $driver->getName(),
            ...$challenge,
        ]);
    }

    /**
     * Verify incoming CAPTCHA challenge via JSON API.
     */
    public function verify(Request $request): JsonResponse
    {
        $action = $request->input('action', 'api');

        // Extract options from payload or headers for flexible headless client integration
        $options = [
            'token' => $request->input('token') ?? $request->input('captcha_token') ?? $request->header('X-Captcha-Token'),
            'answer' => $request->input('answer') ?? $request->input('captcha_answer') ?? $request->header('X-Captcha-Answer'),
            'g-recaptcha-response' => $request->input('g-recaptcha-response') ?? $request->input('recaptcha_response') ?? $request->header('X-Recaptcha-Response'),
        ];

        $result = CaptchaService::verifyRequest($request, $action, array_filter($options));

        return response()->json([
            'success' => $result->isSuccess(),
            'message' => $result->message,
            'score' => $result->score,
            'error_code' => $result->errorCode,
            'driver' => CaptchaService::activeDriverName(),
        ], $result->isSuccess() ? 200 : 422);
    }

    /**
     * Generate or stream acoustic audio readout for a challenge token.
     */
    public function audio(Request $request, string $token): SymfonyResponse
    {
        if (strlen($token) > 64 || ! preg_match('/^[a-zA-Z0-9\-_]+$/', $token)) {
            if ($request->wantsJson() || $request->query('format') === 'json') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid challenge token format.',
                ], 404);
            }

            return response('Invalid audio challenge token', 404, [
                'Content-Type' => 'text/plain',
            ]);
        }

        $cached = Cache::get("captcha_challenge:{$token}");

        if (! $cached) {
            if ($request->wantsJson() || $request->query('format') === 'json') {
                return response()->json([
                    'success' => false,
                    'message' => 'Challenge token expired or not found.',
                ], 404);
            }

            return response('Audio challenge not found or expired', 404, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // Determine speech readout string
        $speechText = $cached['speech'] ?? null;
        if (empty($speechText)) {
            if (! empty($cached['display'])) {
                $speechText = $cached['display'];
            } elseif (! empty($cached['answer'])) {
                try {
                    $speechText = Crypt::decryptString($cached['answer']);
                } catch (Throwable) {
                    $speechText = 'Security Code';
                }
            } else {
                $speechText = 'Security Verification';
            }
        }

        $wav = CaptchaAudioGenerator::generateWav($speechText);

        if ($request->query('format') === 'json') {
            return response()->json([
                'success' => true,
                'token' => $token,
                'speech' => $speechText,
                'audio_base64' => 'data:audio/wav;base64,'.base64_encode($wav),
                'mime_type' => 'audio/wav',
            ]);
        }

        return response($wav, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Length' => (string) strlen($wav),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Content-Disposition' => 'inline; filename="captcha-challenge.wav"',
        ]);
    }

    /**
     * Get system-wide CAPTCHA configuration status.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => CaptchaService::isEnabled(),
            'active_driver' => CaptchaService::activeDriverName(),
            'actions' => [
                'login' => CaptchaService::isEnabled('login'),
                'register' => CaptchaService::isEnabled('register'),
                'forgot_password' => CaptchaService::isEnabled('forgot_password'),
                'contact_us' => CaptchaService::isEnabled('contact_us'),
            ],
            'google' => [
                'version' => Setting::get('captcha.google_version', 'v2_checkbox'),
                'site_key' => Setting::get('captcha.google_site_key') ? 'configured' : 'missing',
                'domain' => Setting::get('captcha.google_domain', 'www.google.com'),
            ],
            'custom' => [
                'char_type' => Setting::get('captcha.custom_char_type', CustomCaptchaDriver::TYPE_ALPHANUMERIC),
                'length' => (int) Setting::get('captcha.custom_length', 5),
                'case_sensitive' => (bool) Setting::get('captcha.custom_case_sensitive', false),
            ],
        ]);
    }

    /**
     * Administrative telemetry, statistics, and system diagnostics.
     */
    public function diagnostics(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $driver = CaptchaService::driver();

        // Run self-test challenge generation
        $testChallenge = null;
        $testSuccess = false;
        $generationTimeMs = 0.0;

        try {
            $genStart = microtime(true);
            $testChallenge = $driver->generateChallenge(['action' => 'test']);
            $generationTimeMs = round((microtime(true) - $genStart) * 1000, 2);
            $testSuccess = ! empty($testChallenge['token']);
            // Clean up test token immediately
            if (! empty($testChallenge['token'])) {
                Cache::forget("captcha_challenge:{$testChallenge['token']}");
            }
        } catch (Throwable $e) {
            Log::warning('Captcha diagnostics self-test failed: '.$e->getMessage());
        }

        // Cache store health
        $cacheHealthy = false;
        try {
            $cacheKey = 'captcha_health_probe_'.time();
            Cache::put($cacheKey, 'ok', 10);
            $cacheHealthy = Cache::get($cacheKey) === 'ok';
            Cache::forget($cacheKey);
        } catch (Throwable) {
            $cacheHealthy = false;
        }

        $stats = CaptchaService::getStatistics();
        $recentLogs = CaptchaLog::latest()
            ->limit(10)
            ->get(['id', 'driver', 'action', 'status', 'score', 'error_code', 'response_time_ms', 'ip_address', 'created_at']);

        return response()->json([
            'status' => 'success',
            'diagnostics' => [
                'active_driver' => $driver->getName(),
                'driver_healthy' => $testSuccess,
                'generation_latency_ms' => $generationTimeMs,
                'cache_store' => config('cache.default'),
                'cache_healthy' => $cacheHealthy,
                'total_diagnostics_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ],
            'statistics' => $stats,
            'recent_telemetry' => $recentLogs,
            'configurations' => [
                'system_enabled' => CaptchaService::isEnabled(),
                'google_version' => Setting::get('captcha.google_version', 'v2_checkbox'),
                'custom_char_type' => Setting::get('captcha.custom_char_type', CustomCaptchaDriver::TYPE_ALPHANUMERIC),
                'custom_length' => (int) Setting::get('captcha.custom_length', 5),
            ],
        ]);
    }

    /**
     * Administrative log pruning endpoint with DB transactions and audit logs.
     */
    public function pruneLogs(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->input('days', 30)));
        $force = (bool) $request->input('force', false);
        $cutoffDate = Carbon::now()->subDays($days);

        return DB::transaction(function () use ($days, $force, $cutoffDate) {
            $query = CaptchaLog::where('created_at', '<', $cutoffDate);
            $count = $query->count();

            if ($force) {
                $query->forceDelete();
            } else {
                $query->delete();
            }

            AuditLogService::log(
                event: 'captcha_logs_pruned',
                description: "Pruned {$count} CAPTCHA verification logs older than {$days} days (".($force ? 'force deleted' : 'soft deleted').')',
                newValues: ['pruned_count' => $count, 'days_threshold' => $days, 'force' => $force]
            );

            return response()->json([
                'success' => true,
                'message' => "Successfully pruned {$count} CAPTCHA logs older than {$days} days.",
                'pruned_count' => $count,
                'days_threshold' => $days,
                'force' => $force,
            ]);
        });
    }
}
