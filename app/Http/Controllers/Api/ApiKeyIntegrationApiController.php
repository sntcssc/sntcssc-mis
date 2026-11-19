<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ApiKeyIntegrationApiController extends Controller
{
    /**
     * Handshake and connectivity test for external, mobile, and desktop clients.
     */
    public function ping(Request $request): JsonResponse
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        return response()->json([
            'success' => true,
            'message' => 'Pong! SNTCSSC MIS REST API is live and operational.',
            'data' => [
                'server_time' => Carbon::now()->toIso8601String(),
                'version' => '1.0.0',
                'client' => [
                    'id' => $apiKey?->uuid,
                    'name' => $apiKey?->name,
                    'type' => $apiKey?->type,
                    'environment' => $apiKey?->environment,
                    'prefix' => $apiKey?->key_prefix,
                    'rate_limit_per_minute' => $apiKey?->rate_limit_per_minute,
                    'scopes' => $apiKey?->scopes ?? ['*'],
                    'last_used_at' => $apiKey?->last_used_at?->toIso8601String(),
                ],
            ],
            'meta' => [
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ],
        ]);
    }

    /**
     * Get system and application metadata for mobile and desktop application configuration.
     */
    public function appInfo(Request $request): JsonResponse
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $appName = config('app.name', 'SNTCSSC MIS');

        return response()->json([
            'success' => true,
            'data' => [
                'app_name' => $appName,
                'institution' => 'Satyendranath Tagore Civil Services Study Centre',
                'api_version' => 'v1',
                'supported_modules' => [
                    'academics' => true,
                    'academic_calendar' => true,
                    'students' => true,
                    'study_materials' => true,
                    'examinations' => true,
                    'hostel' => true,
                    'alumni' => true,
                    'attendance' => true,
                    'fees' => true,
                    'library' => true,
                    'notifications' => true,
                ],
                'client_environment' => $apiKey?->environment ?? 'production',
                'auth_type' => 'api_key',
            ],
        ]);
    }

    /**
     * Echo endpoint for payload testing and debugging external integrations.
     */
    public function echoPayload(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Payload received successfully.',
            'received' => [
                'method' => $request->method(),
                'headers' => [
                    'content_type' => $request->header('Content-Type'),
                    'user_agent' => $request->header('User-Agent'),
                ],
                'query' => $request->query(),
                'body' => $request->all(),
            ],
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }
}
