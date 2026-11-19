<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiScope
{
    /**
     * Handle an incoming request to verify required API scopes.
     *
     * @param  string  ...$scopes  One or more required scopes (any one must match, or all if specified)
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey) {
            return $next($request);
        }

        // Check if any of the required scopes is granted
        $hasPermission = false;
        foreach ($scopes as $scope) {
            if ($apiKey->hasScope(trim($scope))) {
                $hasPermission = true;
                break;
            }
        }

        if (! $hasPermission) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_SCOPE',
                    'message' => 'This API key does not have the required scope(s) ['.implode(', ', $scopes).'] to access this resource.',
                    'required_scopes' => $scopes,
                    'granted_scopes' => $apiKey->scopes ?? [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
