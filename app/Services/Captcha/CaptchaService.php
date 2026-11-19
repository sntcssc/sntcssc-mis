<?php

namespace App\Services\Captcha;

use App\Models\CaptchaLog;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\Captcha\Contracts\CaptchaDriverInterface;
use App\Services\Captcha\Drivers\CustomCaptchaDriver;
use App\Services\Captcha\Drivers\GoogleRecaptchaDriver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CaptchaService
{
    private static ?array $drivers = null;

    /**
     * Check if CAPTCHA protection is enabled globally and for the specific action.
     */
    public static function isEnabled(?string $action = null): bool
    {
        $globallyEnabled = (bool) Setting::get('captcha.enabled', false);

        if (! $globallyEnabled) {
            return false;
        }

        if ($action === null) {
            return true;
        }

        $actionKey = "captcha.on_{$action}";

        return (bool) Setting::get($actionKey, true);
    }

    /**
     * Get the currently active driver name ('google' or 'custom').
     */
    public static function activeDriverName(): string
    {
        $driver = (string) Setting::get('captcha.driver', 'custom');

        return in_array($driver, ['google', 'custom'], true) ? $driver : 'custom';
    }

    /**
     * Resolve the requested or active driver instance.
     */
    public static function driver(?string $name = null): CaptchaDriverInterface
    {
        $name = $name ?: static::activeDriverName();

        if (self::$drivers === null) {
            self::$drivers = [
                'google' => app(GoogleRecaptchaDriver::class),
                'custom' => app(CustomCaptchaDriver::class),
            ];
        }

        return self::$drivers[$name] ?? self::$drivers['custom'];
    }

    /**
     * Render the active CAPTCHA widget markup.
     */
    public static function render(string $action = 'general', array $options = []): string
    {
        if (! static::isEnabled($action)) {
            return '';
        }

        return static::driver()->render($action, $options);
    }

    /**
     * Verify an incoming HTTP request against the active CAPTCHA provider and log the attempt.
     */
    public static function verifyRequest(Request $request, string $action = 'general', array $options = []): CaptchaResult
    {
        if (! static::isEnabled($action)) {
            return CaptchaResult::success('Captcha is disabled.', 1.0);
        }

        $token = $options['token']
            ?? $options['captcha_token']
            ?? ($request->input('captcha_token')
                ?: $request->input('otp_captcha_token')
                ?: $request->header('X-Captcha-Token')
                ?: $request->input('token')
                ?: $request->input('g-recaptcha-response')
                ?: $request->header('X-Recaptcha-Token'));

        $memoKey = '_captcha_verified_'.md5((string) $token);

        if ($token && $request->attributes->has($memoKey)) {
            /** @var CaptchaResult */
            return $request->attributes->get($memoKey);
        }

        $driver = static::driver();
        $start = microtime(true);

        $result = $driver->verify($request, $action, $options);
        $responseTimeMs = (int) round((microtime(true) - $start) * 1000);

        if ($token && $result->isSuccess()) {
            $request->attributes->set($memoKey, $result);
        }

        // Record log asynchronously or synchronously with DB transaction
        static::recordLog(
            driver: $driver->getName(),
            action: $action,
            result: $result,
            request: $request,
            responseTimeMs: $responseTimeMs
        );

        return $result;
    }

    /**
     * Record verification log into the database with audit tracking.
     */
    public static function recordLog(
        string $driver,
        string $action,
        CaptchaResult $result,
        ?Request $request = null,
        int $responseTimeMs = 0
    ): ?CaptchaLog {
        try {
            $req = $request ?? request();
            $status = $result->isSuccess()
                ? CaptchaLog::STATUS_PASSED
                : ($result->errorCode === 'expired-token' ? CaptchaLog::STATUS_EXPIRED : CaptchaLog::STATUS_FAILED);

            $sessionId = null;
            if ($req && $req->hasSession()) {
                $sessionId = $req->session()->getId();
            }

            $userId = Auth::id();

            $log = CaptchaLog::create([
                'session_id' => $sessionId,
                'driver' => $driver,
                'action' => $action,
                'ip_address' => $req?->ip(),
                'user_agent' => $req?->header('User-Agent') ? mb_substr($req->header('User-Agent'), 0, 500) : null,
                'status' => $status,
                'score' => $result->score,
                'error_code' => $result->errorCode,
                'response_time_ms' => $responseTimeMs,
                'user_id' => $userId,
                'metadata' => [
                    'message' => $result->message,
                    'raw' => $result->raw,
                ],
            ]);

            // Audit log when high-frequency bot or brute-force pattern detected
            if (! $result->isSuccess() && $status === CaptchaLog::STATUS_FAILED) {
                AuditLogService::log(
                    event: 'captcha_verification_failed',
                    description: "Captcha challenge failed for action '{$action}' using driver '{$driver}' (IP: {$req?->ip()})",
                    newValues: ['error_code' => $result->errorCode, 'action' => $action]
                );
            }

            return $log;
        } catch (Throwable $e) {
            Log::warning('Failed to record CAPTCHA verification log: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * Get real-time dashboard statistics and analytics.
     */
    public static function getStatistics(): array
    {
        try {
            $today = Carbon::today();

            $totalAllTime = CaptchaLog::count();
            $todayTotal = CaptchaLog::whereDate('created_at', $today)->count();
            $todayPassed = CaptchaLog::whereDate('created_at', $today)->where('status', CaptchaLog::STATUS_PASSED)->count();
            $todayFailed = CaptchaLog::whereDate('created_at', $today)->whereIn('status', [CaptchaLog::STATUS_FAILED, CaptchaLog::STATUS_BLOCKED, CaptchaLog::STATUS_EXPIRED])->count();

            $passRate = $todayTotal > 0 ? round(($todayPassed / $todayTotal) * 100, 1) : 100.0;
            $avgResponseTime = (int) round((float) CaptchaLog::whereDate('created_at', $today)->avg('response_time_ms') ?: 0);

            $driverBreakdown = CaptchaLog::select('driver', DB::raw('count(*) as count'))
                ->whereDate('created_at', '>=', now()->subDays(7))
                ->groupBy('driver')
                ->pluck('count', 'driver')
                ->all();

            $actionBreakdown = CaptchaLog::select('action', DB::raw('count(*) as count'))
                ->whereDate('created_at', '>=', now()->subDays(7))
                ->groupBy('action')
                ->pluck('count', 'action')
                ->all();

            return [
                'total_all_time' => $totalAllTime,
                'today_total' => $todayTotal,
                'today_passed' => $todayPassed,
                'today_failed' => $todayFailed,
                'pass_rate' => $passRate,
                'avg_response_time' => $avgResponseTime,
                'driver_breakdown' => $driverBreakdown,
                'action_breakdown' => $actionBreakdown,
                'active_driver' => static::activeDriverName(),
                'is_enabled' => static::isEnabled(),
            ];
        } catch (Throwable $e) {
            Log::warning('Error computing captcha statistics: '.$e->getMessage());

            return [
                'total_all_time' => 0,
                'today_total' => 0,
                'today_passed' => 0,
                'today_failed' => 0,
                'pass_rate' => 100.0,
                'avg_response_time' => 0,
                'driver_breakdown' => [],
                'action_breakdown' => [],
                'active_driver' => static::activeDriverName(),
                'is_enabled' => static::isEnabled(),
            ];
        }
    }
}
