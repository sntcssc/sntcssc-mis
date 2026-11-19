<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\ApiKey\ApiKeyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(
        protected ApiKeyService $apiKeyService
    ) {}

    /**
     * Handle an incoming API request authenticated via API Key.
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $startTime = microtime(true);

        // Allow pre-bound API key from internal Developer Console sandbox
        /** @var ApiKey|null $boundKey */
        if ($boundKey = $request->attributes->get('api_key')) {
            if ($boundKey->user) {
                Auth::setUser($boundKey->user);
            }

            return $next($request);
        }

        // 1. Extract API Key from headers or query string
        $plainKey = $this->extractApiKey($request);

        if (! $plainKey) {
            // Allow authenticated Sanctum user (for native mobile, web, and desktop apps when API key header is not sent)
            if ($sanctumUser = Auth::guard('sanctum')->user()) {
                Auth::setUser($sanctumUser);

                return $next($request);
            }

            if ($mode === 'optional') {
                return $next($request);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->apiKeyService->logRequest($request, null, 401, $duration, 'API key missing');

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'API_KEY_MISSING',
                    'message' => 'API Key is required. Please provide it via the X-API-Key header or Authorization: Bearer header.',
                ],
            ], 401);
        }

        // 2. Hash and lookup the API Key
        $keyHash = hash('sha256', $plainKey);
        $apiKey = ApiKey::where('key_hash', $keyHash)->first();

        if (! $apiKey) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->apiKeyService->logRequest($request, null, 401, $duration, 'Invalid or unrecognized API key');

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'API_KEY_INVALID',
                    'message' => 'The provided API key is invalid or unrecognized.',
                ],
            ], 401);
        }

        // 3. Status checks
        if ($apiKey->isRevoked()) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->apiKeyService->logRequest($request, $apiKey, 401, $duration, 'API key has been revoked');

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'API_KEY_REVOKED',
                    'message' => 'This API key has been revoked and cannot be used.',
                ],
            ], 401);
        }

        if ($apiKey->isSuspended()) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->apiKeyService->logRequest($request, $apiKey, 403, $duration, 'API key is suspended');

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'API_KEY_SUSPENDED',
                    'message' => 'This API key is currently suspended. Please contact administrator.',
                ],
            ], 403);
        }

        if ($apiKey->isExpired()) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->apiKeyService->logRequest($request, $apiKey, 401, $duration, 'API key expired');

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'API_KEY_EXPIRED',
                    'message' => 'This API key expired on '.$apiKey->expires_at?->toIso8601String().'.',
                ],
            ], 401);
        }

        // 4. IP Whitelist check
        $clientIp = $request->ip();
        if (! $apiKey->allowsIp($clientIp)) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->apiKeyService->logRequest($request, $apiKey, 403, $duration, "Client IP {$clientIp} not in allowlist");

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'IP_UNAUTHORIZED',
                    'message' => "Client IP address '{$clientIp}' is not authorized to use this API key.",
                ],
            ], 403);
        }

        // 5. Rate limiting check
        $rateLimitKey = 'api_key_throttle:'.$apiKey->id;
        $maxAttempts = $apiKey->rate_limit_per_minute;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $duration = (microtime(true) - $startTime) * 1000;
            $this->apiKeyService->logRequest($request, $apiKey, 429, $duration, "Rate limit of {$maxAttempts}/min exceeded");

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'message' => "API rate limit of {$maxAttempts} requests per minute exceeded. Please retry in {$seconds} seconds.",
                ],
            ], 429, [
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($rateLimitKey, 60);

        // 6. Bind API Key and Authenticate User (if linked)
        $request->attributes->set('api_key', $apiKey);

        if ($apiKey->user) {
            Auth::setUser($apiKey->user);
            $request->setUserResolver(fn () => $apiKey->user);
        }

        // 7. Proceed with request execution
        /** @var Response $response */
        $response = $next($request);

        $duration = (microtime(true) - $startTime) * 1000;

        // 8. Record telemetry and update key usage
        $apiKey->recordUsage($clientIp ?: '127.0.0.1', $request->header('User-Agent'));

        $statusCode = $response->getStatusCode();
        $errorSummary = null;
        if ($statusCode >= 400 && $response instanceof JsonResponse) {
            $data = $response->getData(true);
            $errorSummary = $data['error']['message'] ?? $data['message'] ?? null;
        }

        $this->apiKeyService->logRequest($request, $apiKey, $statusCode, $duration, $errorSummary);

        // 9. Attach RateLimit response headers
        $remaining = RateLimiter::remaining($rateLimitKey, $maxAttempts);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-API-Environment', $apiKey->environment);

        return $response;
    }

    /**
     * Extract API key token from headers or query string.
     */
    protected function extractApiKey(Request $request): ?string
    {
        // 1. Check X-API-Key header
        if ($headerKey = $request->header('X-API-Key')) {
            return trim($headerKey);
        }

        // 2. Check Authorization: Bearer header
        if ($bearer = $request->bearerToken()) {
            return trim($bearer);
        }

        // 3. Check query string parameter (fallback)
        if ($queryKey = $request->query('api_key')) {
            return trim((string) $queryKey);
        }

        return null;
    }
}
