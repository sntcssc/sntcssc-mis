<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClassroomApiController extends Controller
{
    /**
     * List all classrooms with filtering and capacity parameters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Classroom::query()->withCount('classSchedules');

        if ($search = $request->input('search')) {
            $term = '%'.trim($search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('room_code', 'like', $term)
                    ->orWhere('building', 'like', $term)
                    ->orWhere('floor', 'like', $term);
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($building = $request->input('building')) {
            $query->where('building', $building);
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($minCapacity = $request->integer('min_capacity')) {
            $query->where('capacity', '>=', $minCapacity);
        }

        $sortField = in_array($request->input('sort_by'), ['name', 'room_code', 'building', 'capacity', 'created_at'], true)
            ? $request->input('sort_by')
            : 'name';
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortDir);

        if ($request->boolean('all', false)) {
            $classrooms = $query->get();

            return response()->json([
                'success' => true,
                'data' => $classrooms,
                'total' => $classrooms->count(),
            ]);
        }

        $classrooms = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $classrooms->items(),
            'meta' => [
                'current_page' => $classrooms->currentPage(),
                'last_page' => $classrooms->lastPage(),
                'per_page' => $classrooms->perPage(),
                'total' => $classrooms->total(),
            ],
        ]);
    }

    /**
     * Store a new classroom.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'room_code' => 'nullable|string|max:50|unique:classrooms,room_code',
            'building' => 'required|string|max:191',
            'floor' => 'required|string|max:50',
            'capacity' => 'nullable|integer|min:1',
            'room_type' => 'required|string|max:50',
            'status' => 'required|in:active,inactive,maintenance',
            'facilities' => 'nullable|array',
            'assigned_courses' => 'nullable|array',
            'assigned_sections' => 'nullable|array',
            'remarks' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                if (empty($validated['room_code'])) {
                    $validated['room_code'] = 'CR-'.strtoupper(substr(uniqid(), -6));
                }

                $classroom = Classroom::create($validated);

                AuditLogService::log(
                    event: 'classroom_created',
                    description: "Created classroom: {$classroom->name} ({$classroom->room_code})",
                    auditable: $classroom,
                    newValues: $classroom->toArray(),
                    userId: auth()->id()
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Classroom created successfully.',
                    'data' => $classroom,
                ], 201);
            });
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create classroom: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show details of a specific classroom.
     */
    public function show(int $id): JsonResponse
    {
        $classroom = Classroom::with(['classSchedules' => function ($q) {
            $q->latest()->take(10)->with(['course', 'batch']);
        }])->withCount('classSchedules')->find($id);

        if (! $classroom) {
            return response()->json(['success' => false, 'message' => 'Classroom not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $classroom,
        ]);
    }

    /**
     * Update an existing classroom.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::find($id);
        if (! $classroom) {
            return response()->json(['success' => false, 'message' => 'Classroom not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:191',
            'room_code' => 'nullable|string|max:50|unique:classrooms,room_code,'.$classroom->id,
            'building' => 'sometimes|string|max:191',
            'floor' => 'sometimes|string|max:50',
            'capacity' => 'nullable|integer|min:1',
            'room_type' => 'sometimes|string|max:50',
            'status' => 'sometimes|in:active,inactive,maintenance',
            'facilities' => 'nullable|array',
            'assigned_courses' => 'nullable|array',
            'assigned_sections' => 'nullable|array',
            'remarks' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($classroom, $validated) {
                $old = $classroom->toArray();
                $classroom->update($validated);

                AuditLogService::log(
                    event: 'classroom_updated',
                    description: "Updated classroom: {$classroom->name} ({$classroom->room_code})",
                    auditable: $classroom,
                    oldValues: $old,
                    newValues: $classroom->fresh()->toArray(),
                    userId: auth()->id()
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Classroom updated successfully.',
                    'data' => $classroom,
                ]);
            });
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update classroom: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete an existing classroom (soft delete).
     */
    public function destroy(int $id): JsonResponse
    {
        $classroom = Classroom::find($id);
        if (! $classroom) {
            return response()->json(['success' => false, 'message' => 'Classroom not found.'], 404);
        }

        try {
            $name = $classroom->name;
            $classroom->delete();

            AuditLogService::log(
                event: 'classroom_deleted',
                description: "Deleted classroom: {$name}",
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => "Classroom '{$name}' deleted successfully.",
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete classroom: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Restore a soft-deleted classroom.
     */
    public function restore(int $id): JsonResponse
    {
        $classroom = Classroom::onlyTrashed()->find($id);
        if (! $classroom) {
            return response()->json(['success' => false, 'message' => 'Soft-deleted classroom not found.'], 404);
        }

        try {
            DB::transaction(function () use ($classroom) {
                $classroom->restore();
                AuditLogService::log(
                    event: 'classroom_restored',
                    description: "Restored classroom: {$classroom->name}",
                    auditable: $classroom,
                    userId: auth()->id()
                );
            });

            return response()->json([
                'success' => true,
                'message' => "Classroom '{$classroom->name}' restored successfully.",
                'data' => $classroom->fresh(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore classroom: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get timetable schedule and occupancy for a specific classroom.
     */
    public function schedule(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::find($id);
        if (! $classroom) {
            return response()->json(['success' => false, 'message' => 'Classroom not found.'], 404);
        }

        $query = $classroom->classSchedules()
            ->with(['batch:id,name,code', 'course:id,name,code', 'section:id,name,code', 'faculties']);

        if ($request->filled('date_from')) {
            $query->whereDate('class_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('class_date', '<=', $request->input('date_to'));
        }
        if ($request->boolean('today', false)) {
            $query->today();
        } elseif ($request->boolean('upcoming', false)) {
            $query->upcoming();
        }

        $classes = $query->orderBy('class_date', 'asc')->orderBy('start_time', 'asc')->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'classroom' => [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'room_code' => $classroom->room_code,
                'building' => $classroom->building,
                'floor' => $classroom->floor,
                'capacity' => $classroom->capacity,
            ],
            'data' => $classes->items(),
            'meta' => [
                'current_page' => $classes->currentPage(),
                'last_page' => $classes->lastPage(),
                'total' => $classes->total(),
            ],
        ]);
    }
}
