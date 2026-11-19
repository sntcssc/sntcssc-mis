<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SearchApiController extends Controller
{
    /**
     * Resolve the active authenticated user context (via Sanctum/token, session, or API key owner).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Standard JSON success response envelope.
     */
    protected function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Standard JSON error response envelope.
     */
    protected function error(string $code, string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    /**
     * Execute unified global search across navigation, actions, and permissible data records.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'q' => ['nullable', 'string', 'max:100'],
                'query' => ['nullable', 'string', 'max:100'],
                'category' => ['nullable', 'string', 'max:50'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            if ($validator->fails()) {
                return $this->error('VALIDATION_ERROR', 'Invalid search query parameters.', 422, $validator->errors()->toArray());
            }

            $user = $this->resolveUser($request);
            if (! $user) {
                return $this->error('UNAUTHENTICATED', 'Authentication is required to perform global search.', 401);
            }

            $rawQuery = $request->input('q', $request->input('query', '')) ?? '';
            $limit = (int) $request->input('limit', 20);
            $category = $request->input('category');

            $results = GlobalSearchService::search($rawQuery, $user, $limit);

            // Filter by category if explicitly passed in API request
            if (! empty($category) && $category !== 'all') {
                $results['items'] = array_values(array_filter($results['items'], function ($item) use ($category) {
                    return strtolower($item['category'] ?? '') === strtolower($category);
                }));
                $results['total'] = count($results['items']);
            }

            // Record audit log for significant queries
            if (mb_strlen($rawQuery) >= 3) {
                AuditLogService::log(
                    event: 'global_search_executed',
                    description: "Global search executed for query: '{$rawQuery}' (Found: {$results['total']} items)",
                    newValues: ['query' => $rawQuery, 'results_count' => $results['total']],
                    userId: $user->id
                );
            }

            return $this->success($results, [
                'query' => $rawQuery,
                'total_results' => $results['total'],
                'categories' => $results['categories'],
                'user_id' => $user->id,
                'role' => $user->roles->first()?->name ?? 'User',
            ]);
        } catch (Throwable $e) {
            Log::error('SearchApiController::search error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SEARCH_EXECUTION_ERROR', 'An error occurred while executing global search.', 500);
        }
    }

    /**
     * Retrieve complete catalog of accessible modules, pages, and options for caller.
     */
    public function modules(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            if (! $user) {
                return $this->error('UNAUTHENTICATED', 'Authentication is required to inspect module catalog.', 401);
            }

            $navigation = GlobalSearchService::getAccessibleNavigation($user);
            $quickActions = GlobalSearchService::getAccessibleQuickActions($user);

            $groupedByModule = $navigation->groupBy('category')->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'count' => $items->count(),
                    'items' => $items->values(),
                ];
            })->values();

            return $this->success([
                'modules' => $groupedByModule,
                'quick_actions' => $quickActions,
                'total_modules' => $groupedByModule->count(),
                'total_navigation_items' => $navigation->count(),
            ], [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'role' => $user->roles->first()?->name ?? 'User',
            ]);
        } catch (Throwable $e) {
            Log::error('SearchApiController::modules error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('MODULES_RETRIEVAL_ERROR', 'Unable to retrieve accessible modules catalog.', 500);
        }
    }

    /**
     * Retrieve viewport display and search preferences for the current user and system.
     */
    public function preferences(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            if (! $user) {
                return $this->error('UNAUTHENTICATED', 'Authentication is required to access viewport preferences.', 401);
            }

            $prefs = GlobalSearchService::getViewportPreferences($user);

            return $this->success($prefs);
        } catch (Throwable $e) {
            Log::error('SearchApiController::preferences error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PREFERENCES_RETRIEVAL_ERROR', 'Unable to retrieve viewport preferences.', 500);
        }
    }

    /**
     * Update viewport display and search preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'default_fullscreen' => ['nullable', 'string', 'in:normal,fullscreen'],
                'search_history_enabled' => ['nullable', 'boolean'],
                'quick_actions_enabled' => ['nullable', 'boolean'],
                'apply_system_wide' => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->error('VALIDATION_ERROR', 'Invalid preferences payload.', 422, $validator->errors()->toArray());
            }

            $user = $this->resolveUser($request);
            if (! $user) {
                return $this->error('UNAUTHENTICATED', 'Authentication is required to update preferences.', 401);
            }

            $applySystemWide = (bool) $request->input('apply_system_wide', false);

            if ($applySystemWide && ! ($user->hasRole('Super Administrator') || $user->can('settings.appearance'))) {
                return $this->error('FORBIDDEN', 'You do not have permission to modify system-wide appearance settings.', 403);
            }

            $updatedPrefs = GlobalSearchService::updateViewportPreferences(
                $user,
                $validator->validated(),
                $applySystemWide
            );

            return $this->success($updatedPrefs, [
                'message' => 'Viewport and search preferences updated successfully.',
            ]);
        } catch (Throwable $e) {
            Log::error('SearchApiController::updatePreferences error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PREFERENCES_UPDATE_ERROR', 'Unable to update viewport preferences.', 500);
        }
    }
}
