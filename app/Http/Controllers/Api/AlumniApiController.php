<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlumniDetailResource;
use App\Http\Resources\AlumniResource;
use App\Models\Alumni;
use App\Models\AlumniServiceCategory;
use App\Services\Alumni\AlumniService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlumniApiController extends Controller
{
    /**
     * Get paginated alumni records with search, filter, and sorting.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Alumni::query()
            ->active()
            ->with(['courseBatches']);

        // Search term
        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Service Type filter
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->input('service_type'));
        }

        // Exam Year filter
        if ($request->filled('exam_year')) {
            $year = (int) $request->input('exam_year');
            $query->where(function ($q) use ($year) {
                $q->where('exam_year', $year)
                    ->orWhere('passing_year', $year)
                    ->orWhereHas('examinations', function ($eq) use ($year) {
                        $eq->where('exam_year', $year);
                    });
            });
        }

        // Posting State filter
        if ($request->filled('posting_state')) {
            $query->where('posting_state', $request->input('posting_state'));
        }

        // Featured flag filter
        if ($request->has('featured')) {
            $isFeatured = filter_var($request->input('featured'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_featured', $isFeatured);
        }

        // Sorting
        $sort = $request->input('sort', 'created_at');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sort === 'name') {
            $query->orderBy('full_name', $order);
        } elseif ($sort === 'air' || $sort === 'rank') {
            $query->orderByRaw('COALESCE(all_india_rank, 999999) '.$order);
        } elseif ($sort === 'exam_year') {
            $query->orderBy('exam_year', $order);
        } else {
            $query->orderBy('id', $order);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return AlumniResource::collection($query->paginate($perPage));
    }

    /**
     * Get detailed alumni dossier with multiple examinations, posting history, and enrolled courses.
     */
    public function show(string $identifier): JsonResponse|AlumniDetailResource
    {
        $alumni = Alumni::query()
            ->with([
                'courseBatches',
                'examinations',
                'postings',
            ])
            ->where(function ($q) use ($identifier) {
                if (is_numeric($identifier)) {
                    $q->where('id', (int) $identifier);
                }
                $q->orWhere('uuid', $identifier);
            })
            ->first();

        if (! $alumni) {
            return response()->json([
                'success' => false,
                'message' => 'Alumni officer record not found.',
            ], 404);
        }

        return new AlumniDetailResource($alumni);
    }

    /**
     * Get spotlight featured alumni for webapp / mobile app home screen.
     */
    public function featured(): AnonymousResourceCollection
    {
        $featured = Alumni::query()
            ->active()
            ->featured()
            ->with(['courseBatches'])
            ->orderBy('display_order')
            ->take(10)
            ->get();

        return AlumniResource::collection($featured);
    }

    /**
     * Get real-time statistics and placement metrics.
     */
    public function statistics(): JsonResponse
    {
        $stats = AlumniService::getStatistics();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get dynamic active service categories catalog for mobile apps and webapps.
     */
    public function categories(): JsonResponse
    {
        $categories = AlumniServiceCategory::active()
            ->ordered()
            ->withCount(['alumni' => fn ($q) => $q->active()])
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'code' => $cat->code,
                'short_name' => $cat->short_name,
                'description' => $cat->description,
                'icon' => $cat->icon,
                'badge_color' => $cat->badge_color,
                'is_system' => (bool) $cat->is_system,
                'alumni_count' => (int) $cat->alumni_count,
                'active_alumni_count' => (int) $cat->alumni_count,
            ]);

        return response()->json([
            'success' => true,
            'data' => $categories,
            'meta' => [
                'total_categories' => $categories->count(),
            ],
        ]);
    }
}
