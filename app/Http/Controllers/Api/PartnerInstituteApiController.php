<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerInstitute;
use App\Services\AuditLogService;
use App\Services\PartnerInstituteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PartnerInstituteApiController extends Controller
{
    /**
     * List all partner coaching institutes with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PartnerInstitute::query()
            ->with(['batches:id,name,code', 'courses:id,name,code', 'sections:id,name,code'])
            ->withCount(['faculties', 'batches', 'courses', 'sections']);

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $sortField = in_array($request->input('sort_by'), ['name', 'code', 'start_date', 'end_date', 'status', 'created_at'], true)
            ? $request->input('sort_by')
            : 'name';
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortDir);

        if ($request->boolean('all', false)) {
            $institutes = $query->get();

            return response()->json([
                'success' => true,
                'data' => $institutes,
                'total' => $institutes->count(),
            ]);
        }

        $institutes = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $institutes->items(),
            'meta' => [
                'current_page' => $institutes->currentPage(),
                'last_page' => $institutes->lastPage(),
                'per_page' => $institutes->perPage(),
                'total' => $institutes->total(),
            ],
        ]);
    }

    /**
     * Store a new partner coaching institute.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:partner_institutes,code',
            'description' => 'nullable|string|max:2000',
            'website_url' => 'nullable|url|max:255',
            'email' => 'nullable|email|max:255',
            'mobile' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:500',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|in:active,inactive,mou_expired,pending',
            'official_name' => 'nullable|string|max:255',
            'official_designation' => 'nullable|string|max:255',
            'official_contact' => 'nullable|string|max:30',
            'official_email' => 'nullable|email|max:255',
            'batches' => 'nullable|array',
            'batches.*' => 'integer|exists:batches,id',
            'courses' => 'nullable|array',
            'courses.*' => 'integer|exists:courses,id',
            'sections' => 'nullable|array',
            'sections.*' => 'integer|exists:sections,id',
        ]);

        try {
            $institute = PartnerInstituteService::createInstitute($validated);

            return response()->json([
                'success' => true,
                'message' => 'Partner institute created successfully.',
                'data' => $institute,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create partner institute: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show details of a specific partner coaching institute.
     */
    public function show(int $id): JsonResponse
    {
        $institute = PartnerInstitute::with([
            'faculties' => function ($q) {
                $q->orderBy('name');
            },
            'batches' => function ($q) {
                $q->orderBy('name');
            },
            'courses' => function ($q) {
                $q->orderBy('name');
            },
            'sections' => function ($q) {
                $q->with(['batch:id,name', 'course:id,name'])->orderBy('name');
            },
        ])->withCount(['faculties', 'batches', 'courses', 'sections'])->find($id);

        if (! $institute) {
            return response()->json(['success' => false, 'message' => 'Partner institute not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $institute,
        ]);
    }

    /**
     * Update an existing partner coaching institute.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $institute = PartnerInstitute::find($id);
        if (! $institute) {
            return response()->json(['success' => false, 'message' => 'Partner institute not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50|unique:partner_institutes,code,'.$institute->id,
            'description' => 'nullable|string|max:2000',
            'website_url' => 'nullable|url|max:255',
            'email' => 'nullable|email|max:255',
            'mobile' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:500',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => 'sometimes|in:active,inactive,mou_expired,pending',
            'official_name' => 'nullable|string|max:255',
            'official_designation' => 'nullable|string|max:255',
            'official_contact' => 'nullable|string|max:30',
            'official_email' => 'nullable|email|max:255',
            'batches' => 'nullable|array',
            'batches.*' => 'integer|exists:batches,id',
            'courses' => 'nullable|array',
            'courses.*' => 'integer|exists:courses,id',
            'sections' => 'nullable|array',
            'sections.*' => 'integer|exists:sections,id',
        ]);

        try {
            $updated = PartnerInstituteService::updateInstitute($institute, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Partner institute updated successfully.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update partner institute: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete an existing partner coaching institute (soft delete).
     */
    public function destroy(int $id): JsonResponse
    {
        $institute = PartnerInstitute::find($id);
        if (! $institute) {
            return response()->json(['success' => false, 'message' => 'Partner institute not found.'], 404);
        }

        try {
            $name = $institute->name;
            PartnerInstituteService::deleteInstitute($institute);

            return response()->json([
                'success' => true,
                'message' => "Partner institute '{$name}' deleted successfully.",
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete partner institute: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get faculties linked to a specific partner institute.
     */
    public function faculties(int $id): JsonResponse
    {
        $institute = PartnerInstitute::find($id);
        if (! $institute) {
            return response()->json(['success' => false, 'message' => 'Partner institute not found.'], 404);
        }

        $faculties = $institute->faculties()->withCount('classSchedules')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $faculties,
            'total' => $faculties->count(),
        ]);
    }

    /**
     * Restore a soft-deleted partner institute.
     */
    public function restore(int $id): JsonResponse
    {
        $institute = PartnerInstitute::onlyTrashed()->find($id);
        if (! $institute) {
            return response()->json(['success' => false, 'message' => 'Soft-deleted partner institute not found.'], 404);
        }

        try {
            DB::transaction(function () use ($institute) {
                $institute->restore();
                AuditLogService::log(
                    event: 'partner_institute_restored',
                    description: "Restored partner institute {$institute->name} ({$institute->code})",
                    auditable: $institute,
                    userId: auth()->id()
                );
            });

            return response()->json([
                'success' => true,
                'message' => "Partner institute '{$institute->name}' restored successfully.",
                'data' => $institute->fresh(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore partner institute: '.$e->getMessage(),
            ], 422);
        }
    }
}
