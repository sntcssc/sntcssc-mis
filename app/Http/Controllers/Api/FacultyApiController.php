<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Services\AuditLogService;
use App\Services\FacultyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FacultyApiController extends Controller
{
    /**
     * List all faculty members with advanced filtering, relations, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Faculty::query()
            ->with(['partnerInstitute:id,name,code,status', 'user:id,name,email'])
            ->withCount(['classSchedules']);

        if ($search = $request->input('search')) {
            $term = '%'.trim($search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('faculty_code', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('department', 'like', $term)
                    ->orWhere('specialization', 'like', $term);
            });
        }

        if ($dept = $request->input('department')) {
            $query->where('department', $dept);
        }

        if ($partnerInstId = $request->input('partner_institute_id')) {
            $query->where('partner_institute_id', $partnerInstId);
        }

        if ($facultyType = $request->input('faculty_type')) {
            $query->where('faculty_type', $facultyType);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($request->boolean('with_trashed', false) && auth()->check()) {
            $query->withTrashed();
        }

        $sortField = in_array($request->input('sort_by'), ['name', 'faculty_code', 'department', 'faculty_type', 'status', 'created_at'], true)
            ? $request->input('sort_by')
            : 'name';
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortDir);

        if ($request->boolean('all', false)) {
            $faculties = $query->get();

            return response()->json([
                'success' => true,
                'data' => $faculties,
                'total' => $faculties->count(),
            ]);
        }

        $paginated = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'total' => $paginated->total(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Store a new faculty member.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'email' => 'nullable|email|max:191|unique:faculties,email',
            'phone' => 'nullable|string|max:50',
            'designation' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'faculty_type' => 'nullable|in:regular,guest,visiting,contractual,adjunct',
            'partner_institute_id' => 'nullable|exists:partner_institutes,id',
            'specialization' => 'nullable|string|max:191',
            'qualification' => 'nullable|string|max:191',
            'bio' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,on_leave',
            'subjects' => 'nullable|array',
            'user_id' => 'nullable|exists:users,id',
            'photo' => 'nullable|image|max:5120',
            'cv' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        try {
            $faculty = FacultyService::createFaculty(
                $validated,
                $request->file('photo'),
                $request->file('cv')
            );

            return response()->json([
                'success' => true,
                'message' => 'Faculty member created successfully.',
                'data' => $faculty,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create faculty member: '.$e->getMessage(),
                'error' => [
                    'code' => 'FACULTY_CREATE_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Show full faculty dossier with workload statistics.
     */
    public function show(int $id): JsonResponse
    {
        $faculty = Faculty::with(['partnerInstitute', 'user:id,name,email'])
            ->withCount(['classSchedules'])
            ->find($id);

        if (! $faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Faculty member not found.',
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Faculty ID does not exist.'],
            ], 404);
        }

        $workload = FacultyService::getFacultyWorkloadStats($faculty);

        $recentClasses = $faculty->classSchedules()
            ->with(['batch:id,name,code', 'course:id,name,code', 'section:id,name,code'])
            ->orderBy('class_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'faculty' => $faculty,
                'workload' => $workload,
                'recent_classes' => $recentClasses,
            ],
        ]);
    }

    /**
     * Update an existing faculty member.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $faculty = Faculty::find($id);

        if (! $faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Faculty member not found.',
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Faculty ID does not exist.'],
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:191',
            'email' => 'nullable|email|max:191|unique:faculties,email,'.$id,
            'phone' => 'nullable|string|max:50',
            'designation' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'faculty_type' => 'nullable|in:regular,guest,visiting,contractual,adjunct',
            'partner_institute_id' => 'nullable|exists:partner_institutes,id',
            'specialization' => 'nullable|string|max:191',
            'qualification' => 'nullable|string|max:191',
            'bio' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,on_leave',
            'subjects' => 'nullable|array',
            'user_id' => 'nullable|exists:users,id',
            'photo' => 'nullable|image|max:5120',
            'cv' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'remove_photo' => 'nullable|boolean',
            'remove_cv' => 'nullable|boolean',
        ]);

        try {
            $updated = FacultyService::updateFaculty(
                $faculty,
                $validated,
                $request->file('photo'),
                $request->file('cv'),
                $request->boolean('remove_photo', false),
                $request->boolean('remove_cv', false)
            );

            return response()->json([
                'success' => true,
                'message' => 'Faculty member updated successfully.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update faculty member: '.$e->getMessage(),
                'error' => [
                    'code' => 'FACULTY_UPDATE_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Soft delete a faculty member.
     */
    public function destroy(int $id): JsonResponse
    {
        $faculty = Faculty::find($id);

        if (! $faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Faculty member not found.',
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Faculty ID does not exist.'],
            ], 404);
        }

        try {
            FacultyService::deleteFaculty($faculty);

            return response()->json([
                'success' => true,
                'message' => "Faculty member {$faculty->name} ({$faculty->faculty_code}) deleted successfully.",
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete faculty: '.$e->getMessage(),
                'error' => [
                    'code' => 'FACULTY_DELETE_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Restore a soft-deleted faculty member.
     */
    public function restore(int $id): JsonResponse
    {
        $faculty = Faculty::onlyTrashed()->find($id);

        if (! $faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Soft-deleted faculty member not found.',
                'error' => ['code' => 'NOT_FOUND', 'message' => 'No deleted faculty with this ID.'],
            ], 404);
        }

        try {
            FacultyService::restoreFaculty($faculty);

            return response()->json([
                'success' => true,
                'message' => "Faculty member {$faculty->name} ({$faculty->faculty_code}) restored successfully.",
                'data' => $faculty->fresh()->load('partnerInstitute'),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore faculty: '.$e->getMessage(),
                'error' => [
                    'code' => 'FACULTY_RESTORE_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Get timetable / class routine assigned to a specific faculty member.
     */
    public function schedule(Request $request, int $id): JsonResponse
    {
        $faculty = Faculty::find($id);

        if (! $faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Faculty member not found.',
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Faculty ID does not exist.'],
            ], 404);
        }

        $query = $faculty->classSchedules()
            ->with(['batch:id,name,code', 'course:id,name,code', 'section:id,name,code', 'coursePlan:id,title,plan_code']);

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
        } elseif ($request->boolean('past', false) || $request->boolean('history', false)) {
            $query->past();
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortField = in_array($request->input('sort_by'), ['class_date', 'start_time', 'subject', 'status'], true)
            ? $request->input('sort_by')
            : 'class_date';
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortDir)->orderBy('start_time', 'asc');

        if ($request->boolean('all', false)) {
            $classes = $query->get();

            return response()->json([
                'success' => true,
                'faculty' => [
                    'id' => $faculty->id,
                    'name' => $faculty->name,
                    'faculty_code' => $faculty->faculty_code,
                ],
                'data' => $classes,
                'total' => $classes->count(),
            ]);
        }

        $paginated = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'faculty' => [
                'id' => $faculty->id,
                'name' => $faculty->name,
                'faculty_code' => $faculty->faculty_code,
            ],
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Get workload metrics for a specific faculty member.
     */
    public function workload(int $id): JsonResponse
    {
        $faculty = Faculty::find($id);

        if (! $faculty) {
            return response()->json([
                'success' => false,
                'message' => 'Faculty member not found.',
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Faculty ID does not exist.'],
            ], 404);
        }

        $stats = FacultyService::getFacultyWorkloadStats($faculty);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get global faculty workload analytics and leaderboard.
     */
    public function workloadStats(Request $request): JsonResponse
    {
        $stats = FacultyService::getGlobalWorkloadStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
            'total_faculties' => count($stats),
        ]);
    }

    /**
     * Export faculty directory dataset in Excel, CSV, or PDF format.
     */
    public function export(Request $request): Response
    {
        $format = strtolower($request->input('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'csv', 'pdf'], true)) {
            $format = 'xlsx';
        }

        $query = Faculty::query()->with(['partnerInstitute']);

        if ($dept = $request->input('department')) {
            $query->where('department', $dept);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return FacultyService::exportFaculties($query, $format);
    }

    /**
     * Bulk import faculties from an uploaded spreadsheet or JSON array.
     */
    public function import(Request $request): JsonResponse
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,csv,xls|max:10240',
            ]);

            $result = FacultyService::importFromSpreadsheet($request->file('file'));

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success']
                    ? "Imported {$result['imported']} faculties, updated {$result['updated']}."
                    : 'Import completed with errors.',
                'data' => $result,
            ], $result['success'] ? 200 : 422);
        }

        if ($request->has('faculties') && is_array($request->input('faculties'))) {
            $facultiesData = $request->input('faculties');
            $imported = 0;
            $updated = 0;
            $errors = [];

            DB::beginTransaction();
            try {
                foreach ($facultiesData as $idx => $fData) {
                    if (empty($fData['name'])) {
                        $errors[] = "Row #{$idx}: Name is required.";

                        continue;
                    }

                    $faculty = null;
                    if (! empty($fData['faculty_code'])) {
                        $faculty = Faculty::where('faculty_code', $fData['faculty_code'])->first();
                    }
                    if (! $faculty && ! empty($fData['email'])) {
                        $faculty = Faculty::where('email', $fData['email'])->first();
                    }

                    if ($faculty) {
                        $faculty->update($fData);
                        $updated++;
                    } else {
                        Faculty::create($fData);
                        $imported++;
                    }
                }

                DB::commit();

                AuditLogService::log(
                    event: 'faculties_bulk_imported_json',
                    description: "Imported {$imported} faculties, updated {$updated} via API",
                    userId: auth()->id()
                );

                return response()->json([
                    'success' => true,
                    'message' => "Successfully imported {$imported} faculties, updated {$updated}.",
                    'imported' => $imported,
                    'updated' => $updated,
                    'errors' => $errors,
                ]);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to import faculties: '.$e->getMessage(),
                ], 422);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Please provide an uploaded spreadsheet file or a "faculties" JSON array.',
        ], 400);
    }
}
