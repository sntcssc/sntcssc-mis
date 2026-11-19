<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchCourse;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Services\Academic\AcademicService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcademicApiController extends Controller
{
    public function __construct(
        protected AcademicService $academicService
    ) {}

    /**
     * Resolve the active user context (via bearer/Sanctum, session, or API key owner).
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
     * Find course by numeric ID or unique Course code.
     */
    protected function findCourse(string $identifier): ?Course
    {
        if (is_numeric($identifier)) {
            return Course::find((int) $identifier);
        }

        return Course::where('code', strtoupper(trim($identifier)))->first();
    }

    /**
     * Find batch by numeric ID or unique Batch code.
     */
    protected function findBatch(string $identifier): ?Batch
    {
        if (is_numeric($identifier)) {
            return Batch::find((int) $identifier);
        }

        return Batch::where('code', strtoupper(trim($identifier)))->first();
    }

    /**
     * 1. Academics Executive Dashboard & Telemetry.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $metrics = $this->academicService->getDashboardMetrics();

            return $this->success($metrics);
        } catch (Throwable $e) {
            return $this->error('DASHBOARD_METRICS_FAILED', 'Failed to retrieve academic dashboard telemetry: '.$e->getMessage(), 500);
        }
    }

    /**
     * 2. Academics Capacity Utilization & Year-Wise Statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $stats = $this->academicService->getStatistics();

            return $this->success($stats);
        } catch (Throwable $e) {
            return $this->error('STATISTICS_FETCH_FAILED', 'Failed to retrieve academic statistics: '.$e->getMessage(), 500);
        }
    }

    /**
     * 3. Courses Directory (Filtered, Searchable, Paginated).
     */
    public function courses(Request $request): JsonResponse
    {
        $query = Course::query();

        // Status filter
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'active');
        }

        // Category filter
        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        // Fee type filter
        if ($feeType = $request->input('fee_type')) {
            $query->where('fee_type', $feeType);
        }

        // Sections filter
        if ($request->has('has_sections')) {
            $query->where('has_sections', $request->boolean('has_sections'));
        }

        // Admit once only filter
        if ($request->has('admit_once_only')) {
            $query->where('admit_once_only', $request->boolean('admit_once_only'));
        }

        // Keyword Search
        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Eager loading counts
        $query->withCount([
            'batches',
            'sections',
            'enrollments as active_enrollments_count' => fn (Builder $q) => $q->where('status', 'active'),
        ]);

        if ($request->boolean('with_batches')) {
            $query->with(['batches' => fn ($b) => $b->select('batches.id', 'batches.name', 'batches.code', 'batches.academic_year', 'batches.status')]);
        }

        if ($request->boolean('with_sections')) {
            $query->with(['sections:id,course_id,batch_id,name,code,capacity,room_number,timing,status']);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = strtolower($request->input('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        if (in_array($sortBy, ['name', 'code', 'fee', 'created_at', 'capacity'], true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('name');
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function (Course $course) {
            return [
                'id' => $course->id,
                'name' => $course->name,
                'code' => $course->code,
                'category' => $course->category,
                'description' => $course->description,
                'duration' => $course->duration,
                'fee' => (float) $course->fee,
                'fee_type' => $course->fee_type,
                'fee_reserved' => $course->fee_reserved !== null ? (float) $course->fee_reserved : null,
                'fee_structure' => $course->fee_structure,
                'capacity' => $course->capacity,
                'has_sections' => (bool) $course->has_sections,
                'identifier_code' => $course->identifier_code,
                'admit_once_only' => (bool) $course->admit_once_only,
                'max_attempts' => $course->max_attempts,
                'status' => $course->status,
                'rules' => $course->rules,
                'batches_count' => (int) $course->batches_count,
                'sections_count' => (int) $course->sections_count,
                'active_enrollments_count' => (int) $course->active_enrollments_count,
                'created_at' => $course->created_at?->toIso8601String(),
                'updated_at' => $course->updated_at?->toIso8601String(),
                'batches' => $course->relationLoaded('batches') ? $course->batches : null,
                'sections' => $course->relationLoaded('sections') ? $course->sections : null,
            ];
        });

        return $this->success($items, [
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ]);
    }

    /**
     * 4. Create New Course Offering.
     */
    public function storeCourse(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'code' => 'required|string|max:50|unique:courses,code',
            'category' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'duration' => 'nullable|string|max:100',
            'fee' => 'required|numeric|min:0',
            'fee_type' => 'nullable|string|in:monthly,one_time,free',
            'fee_reserved' => 'nullable|numeric|min:0',
            'fee_structure' => 'nullable|array',
            'capacity' => 'nullable|integer|min:1',
            'has_sections' => 'nullable|boolean',
            'identifier_code' => 'nullable|string|max:10',
            'admit_once_only' => 'nullable|boolean',
            'max_attempts' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:active,inactive,archived',
            'rules' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The course payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $course = $this->academicService->createCourse($validator->validated(), $user);

            return $this->success([
                'id' => $course->id,
                'name' => $course->name,
                'code' => $course->code,
                'category' => $course->category,
                'fee' => (float) $course->fee,
                'fee_type' => $course->fee_type,
                'capacity' => $course->capacity,
                'status' => $course->status,
                'created_at' => $course->created_at?->toIso8601String(),
            ], ['message' => 'Course offering created successfully.'], 201);
        } catch (Throwable $e) {
            return $this->error('COURSE_CREATION_FAILED', 'Failed to create course: '.$e->getMessage(), 500);
        }
    }

    /**
     * 5. Retrieve Course Detailed Dossier.
     */
    public function showCourse(string $identifier): JsonResponse
    {
        $course = $this->findCourse($identifier);

        if (! $course) {
            return $this->error('COURSE_NOT_FOUND', "Course matching '{$identifier}' could not be found.", 404);
        }

        $course->load([
            'batches' => fn ($b) => $b->withPivot(['capacity', 'fee', 'start_date', 'end_date', 'status', 'remarks']),
            'sections' => fn ($s) => $s->withCount(['enrollments as active_enrolled_count' => fn ($q) => $q->where('status', 'active')]),
            'creator:id,name,email',
            'updater:id,name,email',
        ]);

        $activeEnrolled = $course->enrollments()->where('status', 'active')->count();
        $capacity = $course->capacity ?: 100;
        $utilizationRate = $capacity > 0 ? round(($activeEnrolled / $capacity) * 100, 1) : 0;

        return $this->success([
            'id' => $course->id,
            'name' => $course->name,
            'code' => $course->code,
            'category' => $course->category,
            'description' => $course->description,
            'duration' => $course->duration,
            'fee' => (float) $course->fee,
            'fee_type' => $course->fee_type,
            'fee_reserved' => $course->fee_reserved !== null ? (float) $course->fee_reserved : null,
            'fee_structure' => $course->fee_structure,
            'capacity' => $course->capacity,
            'active_enrolled_count' => $activeEnrolled,
            'utilization_rate_pct' => min(100.0, $utilizationRate),
            'has_sections' => (bool) $course->has_sections,
            'identifier_code' => $course->identifier_code,
            'admit_once_only' => (bool) $course->admit_once_only,
            'max_attempts' => $course->max_attempts,
            'status' => $course->status,
            'rules' => $course->rules,
            'batches' => $course->batches->map(fn (Batch $b) => [
                'batch_id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'academic_year' => $b->academic_year,
                'status' => $b->status,
                'pivot' => [
                    'capacity' => $b->pivot->capacity,
                    'fee' => $b->pivot->fee !== null ? (float) $b->pivot->fee : null,
                    'start_date' => $b->pivot->start_date ? Carbon::parse($b->pivot->start_date)->format('Y-m-d') : null,
                    'end_date' => $b->pivot->end_date ? Carbon::parse($b->pivot->end_date)->format('Y-m-d') : null,
                    'status' => $b->pivot->status,
                    'remarks' => $b->pivot->remarks,
                ],
            ]),
            'sections' => $course->sections->map(fn (Section $s) => [
                'section_id' => $s->id,
                'batch_id' => $s->batch_id,
                'name' => $s->name,
                'code' => $s->code,
                'capacity' => $s->capacity,
                'room_number' => $s->room_number,
                'timing' => $s->timing,
                'status' => $s->status,
                'active_enrolled_count' => (int) $s->active_enrolled_count,
            ]),
            'created_by' => $course->creator ? ['id' => $course->creator->id, 'name' => $course->creator->name] : null,
            'updated_by' => $course->updater ? ['id' => $course->updater->id, 'name' => $course->updater->name] : null,
            'created_at' => $course->created_at?->toIso8601String(),
            'updated_at' => $course->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * 6. Update Course Offering.
     */
    public function updateCourse(string $identifier, Request $request): JsonResponse
    {
        $course = $this->findCourse($identifier);

        if (! $course) {
            return $this->error('COURSE_NOT_FOUND', "Course matching '{$identifier}' could not be found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:150',
            'code' => 'sometimes|required|string|max:50|unique:courses,code,'.$course->id,
            'category' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'duration' => 'nullable|string|max:100',
            'fee' => 'sometimes|required|numeric|min:0',
            'fee_type' => 'nullable|string|in:monthly,one_time,free',
            'fee_reserved' => 'nullable|numeric|min:0',
            'fee_structure' => 'nullable|array',
            'capacity' => 'nullable|integer|min:1',
            'has_sections' => 'nullable|boolean',
            'identifier_code' => 'nullable|string|max:10',
            'admit_once_only' => 'nullable|boolean',
            'max_attempts' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:active,inactive,archived',
            'rules' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The course update payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $updated = $this->academicService->updateCourse($course, $validator->validated(), $user);

            return $this->success([
                'id' => $updated->id,
                'name' => $updated->name,
                'code' => $updated->code,
                'category' => $updated->category,
                'fee' => (float) $updated->fee,
                'fee_type' => $updated->fee_type,
                'capacity' => $updated->capacity,
                'status' => $updated->status,
                'updated_at' => $updated->updated_at?->toIso8601String(),
            ], ['message' => 'Course updated successfully.']);
        } catch (Throwable $e) {
            return $this->error('COURSE_UPDATE_FAILED', 'Failed to update course: '.$e->getMessage(), 500);
        }
    }

    /**
     * 7. Soft Delete Course Offering.
     */
    public function destroyCourse(string $identifier, Request $request): JsonResponse
    {
        $course = $this->findCourse($identifier);

        if (! $course) {
            return $this->error('COURSE_NOT_FOUND', "Course matching '{$identifier}' could not be found.", 404);
        }

        try {
            $user = $this->resolveUser($request);
            $this->academicService->deleteCourse($course, $user);

            return $this->success(null, ['message' => "Course '{$course->name}' was successfully soft-deleted."]);
        } catch (ValidationException $e) {
            return $this->error('ACTIVE_DEPENDENCY_PREVENTED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('COURSE_DELETION_FAILED', 'Failed to delete course: '.$e->getMessage(), 500);
        }
    }

    /**
     * 8. Batches Directory (Filtered, Searchable, Paginated).
     */
    public function batches(Request $request): JsonResponse
    {
        $query = Batch::query();

        // Academic Year Filter
        if ($year = $request->input('academic_year') ?: $request->input('year')) {
            $query->where('academic_year', $year);
        }

        // Status filter
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Course offering filter (via batch_courses pivot)
        if ($courseId = $request->input('course_id')) {
            $query->whereHas('courses', function (Builder $q) use ($courseId) {
                if (is_numeric($courseId)) {
                    $q->where('courses.id', (int) $courseId);
                } else {
                    $q->where('courses.code', strtoupper(trim($courseId)));
                }
            });
        }

        // Keyword Search
        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Eager loads
        $query->withCount([
            'sections',
            'enrollments as active_enrollments_count' => fn (Builder $q) => $q->where('status', 'active'),
        ]);

        $query->with(['courses:id,name,code,category,fee']);

        // Sorting
        $sortBy = $request->input('sort_by', 'academic_year');
        $sortDirection = strtolower($request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sortBy, ['academic_year', 'name', 'code', 'start_date', 'created_at'], true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderByDesc('id');
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function (Batch $batch) {
            $capacity = $batch->max_students ?: 100;
            $enrolled = (int) $batch->active_enrollments_count;
            $utilization = $capacity > 0 ? round(($enrolled / $capacity) * 100, 1) : 0;

            return [
                'id' => $batch->id,
                'name' => $batch->name,
                'code' => $batch->code,
                'academic_year' => $batch->academic_year,
                'start_date' => $batch->start_date?->format('Y-m-d'),
                'end_date' => $batch->end_date?->format('Y-m-d'),
                'status' => $batch->status,
                'description' => $batch->description,
                'max_students' => $batch->max_students,
                'active_enrollments_count' => $enrolled,
                'utilization_rate_pct' => min(100.0, $utilization),
                'sections_count' => (int) $batch->sections_count,
                'courses' => $batch->courses->map(fn (Course $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'code' => $c->code,
                    'category' => $c->category,
                    'fee' => (float) $c->fee,
                ]),
                'created_at' => $batch->created_at?->toIso8601String(),
                'updated_at' => $batch->updated_at?->toIso8601String(),
            ];
        });

        return $this->success($items, [
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ]);
    }

    /**
     * 9. Create New Academic Batch.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'code' => 'required|string|max:50|unique:batches,code',
            'academic_year' => 'required|string|max:20',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|string|in:upcoming,active,completed,archived',
            'description' => 'nullable|string',
            'max_students' => 'nullable|integer|min:1',
            'courses' => 'nullable|array',
            'courses.*' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The batch creation payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $batch = $this->academicService->createBatch($validator->validated(), $user);

            return $this->success([
                'id' => $batch->id,
                'name' => $batch->name,
                'code' => $batch->code,
                'academic_year' => $batch->academic_year,
                'status' => $batch->status,
                'start_date' => $batch->start_date?->format('Y-m-d'),
                'end_date' => $batch->end_date?->format('Y-m-d'),
                'max_students' => $batch->max_students,
                'created_at' => $batch->created_at?->toIso8601String(),
            ], ['message' => 'Academic batch created successfully.'], 201);
        } catch (Throwable $e) {
            return $this->error('BATCH_CREATION_FAILED', 'Failed to create batch: '.$e->getMessage(), 500);
        }
    }

    /**
     * 10. Retrieve Batch Detailed Dossier.
     */
    public function showBatch(string $identifier): JsonResponse
    {
        $batch = $this->findBatch($identifier);

        if (! $batch) {
            return $this->error('BATCH_NOT_FOUND', "Batch matching '{$identifier}' could not be found.", 404);
        }

        $batch->load([
            'courses' => fn ($c) => $c->withPivot(['capacity', 'fee', 'start_date', 'end_date', 'status', 'remarks']),
            'sections' => fn ($s) => $s->withCount(['enrollments as active_enrolled_count' => fn ($q) => $q->where('status', 'active')]),
            'creator:id,name,email',
            'updater:id,name,email',
        ]);

        $activeEnrolled = $batch->enrollments()->where('status', 'active')->count();
        $capacity = $batch->max_students ?: 100;
        $utilizationRate = $capacity > 0 ? round(($activeEnrolled / $capacity) * 100, 1) : 0;

        return $this->success([
            'id' => $batch->id,
            'name' => $batch->name,
            'code' => $batch->code,
            'academic_year' => $batch->academic_year,
            'start_date' => $batch->start_date?->format('Y-m-d'),
            'end_date' => $batch->end_date?->format('Y-m-d'),
            'status' => $batch->status,
            'description' => $batch->description,
            'max_students' => $batch->max_students,
            'active_enrolled_count' => $activeEnrolled,
            'utilization_rate_pct' => min(100.0, $utilizationRate),
            'courses' => $batch->courses->map(fn (Course $c) => [
                'course_id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'category' => $c->category,
                'base_fee' => (float) $c->fee,
                'offering' => [
                    'capacity' => $c->pivot->capacity,
                    'fee' => $c->pivot->fee !== null ? (float) $c->pivot->fee : null,
                    'start_date' => $c->pivot->start_date ? Carbon::parse($c->pivot->start_date)->format('Y-m-d') : null,
                    'end_date' => $c->pivot->end_date ? Carbon::parse($c->pivot->end_date)->format('Y-m-d') : null,
                    'status' => $c->pivot->status,
                    'remarks' => $c->pivot->remarks,
                ],
            ]),
            'sections' => $batch->sections->map(fn (Section $s) => [
                'section_id' => $s->id,
                'course_id' => $s->course_id,
                'name' => $s->name,
                'code' => $s->code,
                'capacity' => $s->capacity,
                'room_number' => $s->room_number,
                'timing' => $s->timing,
                'status' => $s->status,
                'active_enrolled_count' => (int) $s->active_enrolled_count,
            ]),
            'created_by' => $batch->creator ? ['id' => $batch->creator->id, 'name' => $batch->creator->name] : null,
            'updated_by' => $batch->updater ? ['id' => $batch->updater->id, 'name' => $batch->updater->name] : null,
            'created_at' => $batch->created_at?->toIso8601String(),
            'updated_at' => $batch->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * 11. Update Academic Batch.
     */
    public function updateBatch(string $identifier, Request $request): JsonResponse
    {
        $batch = $this->findBatch($identifier);

        if (! $batch) {
            return $this->error('BATCH_NOT_FOUND', "Batch matching '{$identifier}' could not be found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:150',
            'code' => 'sometimes|required|string|max:50|unique:batches,code,'.$batch->id,
            'academic_year' => 'sometimes|required|string|max:20',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|string|in:upcoming,active,completed,archived',
            'description' => 'nullable|string',
            'max_students' => 'nullable|integer|min:1',
            'courses' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The batch update payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $updated = $this->academicService->updateBatch($batch, $validator->validated(), $user);

            return $this->success([
                'id' => $updated->id,
                'name' => $updated->name,
                'code' => $updated->code,
                'academic_year' => $updated->academic_year,
                'status' => $updated->status,
                'updated_at' => $updated->updated_at?->toIso8601String(),
            ], ['message' => 'Academic batch updated successfully.']);
        } catch (Throwable $e) {
            return $this->error('BATCH_UPDATE_FAILED', 'Failed to update batch: '.$e->getMessage(), 500);
        }
    }

    /**
     * 12. Soft Delete Academic Batch.
     */
    public function destroyBatch(string $identifier, Request $request): JsonResponse
    {
        $batch = $this->findBatch($identifier);

        if (! $batch) {
            return $this->error('BATCH_NOT_FOUND', "Batch matching '{$identifier}' could not be found.", 404);
        }

        try {
            $user = $this->resolveUser($request);
            $this->academicService->deleteBatch($batch, $user);

            return $this->success(null, ['message' => "Academic batch '{$batch->name}' was successfully soft-deleted."]);
        } catch (ValidationException $e) {
            return $this->error('ACTIVE_DEPENDENCY_PREVENTED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('BATCH_DELETION_FAILED', 'Failed to delete batch: '.$e->getMessage(), 500);
        }
    }

    /**
     * 13. List Course Offerings in a Specific Batch.
     */
    public function batchCourses(string $batchIdentifier): JsonResponse
    {
        $batch = $this->findBatch($batchIdentifier);

        if (! $batch) {
            return $this->error('BATCH_NOT_FOUND', "Batch matching '{$batchIdentifier}' could not be found.", 404);
        }

        $offerings = BatchCourse::where('batch_id', $batch->id)
            ->with(['course'])
            ->get()
            ->map(function (BatchCourse $bc) use ($batch) {
                $enrolled = Enrollment::where('batch_id', $batch->id)
                    ->where('course_id', $bc->course_id)
                    ->where('status', 'active')
                    ->count();

                return [
                    'offering_id' => $bc->id,
                    'batch_id' => $bc->batch_id,
                    'course_id' => $bc->course_id,
                    'course_name' => $bc->course?->name,
                    'course_code' => $bc->course?->code,
                    'category' => $bc->course?->category,
                    'capacity' => $bc->capacity ?: $bc->course?->capacity,
                    'fee' => $bc->fee !== null ? (float) $bc->fee : (float) $bc->course?->fee,
                    'start_date' => $bc->start_date?->format('Y-m-d'),
                    'end_date' => $bc->end_date?->format('Y-m-d'),
                    'status' => $bc->status,
                    'remarks' => $bc->remarks,
                    'active_enrolled_count' => $enrolled,
                ];
            });

        return $this->success($offerings);
    }

    /**
     * 14. Attach Course Offering to Batch.
     */
    public function attachBatchCourse(string $batchIdentifier, Request $request): JsonResponse
    {
        $batch = $this->findBatch($batchIdentifier);

        if (! $batch) {
            return $this->error('BATCH_NOT_FOUND', "Batch matching '{$batchIdentifier}' could not be found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'capacity' => 'nullable|integer|min:1',
            'fee' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|string|in:active,inactive',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The course offering payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $offering = $this->academicService->attachCourseToBatch($batch, (int) $request->input('course_id'), $validator->validated(), $user);

            return $this->success([
                'id' => $offering->id,
                'batch_id' => $offering->batch_id,
                'course_id' => $offering->course_id,
                'capacity' => $offering->capacity,
                'fee' => $offering->fee !== null ? (float) $offering->fee : null,
                'status' => $offering->status,
            ], ['message' => 'Course linked to batch successfully.'], 201);
        } catch (Throwable $e) {
            return $this->error('COURSE_ATTACH_FAILED', 'Failed to attach course to batch: '.$e->getMessage(), 500);
        }
    }

    /**
     * 15. Update Course Offering in Batch.
     */
    public function updateBatchCourse(string $batchIdentifier, int $courseId, Request $request): JsonResponse
    {
        $batch = $this->findBatch($batchIdentifier);

        if (! $batch) {
            return $this->error('BATCH_NOT_FOUND', "Batch matching '{$batchIdentifier}' could not be found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'capacity' => 'nullable|integer|min:1',
            'fee' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|string|in:active,inactive',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The offering update payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $offering = $this->academicService->attachCourseToBatch($batch, $courseId, $validator->validated(), $user);

            return $this->success([
                'id' => $offering->id,
                'batch_id' => $offering->batch_id,
                'course_id' => $offering->course_id,
                'capacity' => $offering->capacity,
                'fee' => $offering->fee !== null ? (float) $offering->fee : null,
                'status' => $offering->status,
            ], ['message' => 'Course offering terms updated successfully.']);
        } catch (Throwable $e) {
            return $this->error('COURSE_OFFERING_UPDATE_FAILED', 'Failed to update course offering: '.$e->getMessage(), 500);
        }
    }

    /**
     * 16. Detach Course Offering from Batch.
     */
    public function detachBatchCourse(string $batchIdentifier, int $courseId, Request $request): JsonResponse
    {
        $batch = $this->findBatch($batchIdentifier);

        if (! $batch) {
            return $this->error('BATCH_NOT_FOUND', "Batch matching '{$batchIdentifier}' could not be found.", 404);
        }

        try {
            $user = $this->resolveUser($request);
            $this->academicService->detachCourseFromBatch($batch, $courseId, $user);

            return $this->success(null, ['message' => 'Course offering successfully detached from batch.']);
        } catch (ValidationException $e) {
            return $this->error('ACTIVE_DEPENDENCY_PREVENTED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('COURSE_DETACH_FAILED', 'Failed to detach course: '.$e->getMessage(), 500);
        }
    }

    /**
     * 17. Classroom Sections Directory.
     */
    public function sections(Request $request): JsonResponse
    {
        $query = Section::query();

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('room_number', 'like', "%{$search}%");
            });
        }

        $query->withCount([
            'enrollments as active_enrolled_count' => fn (Builder $q) => $q->where('status', 'active'),
        ]);

        $query->with(['batch:id,name,code', 'course:id,name,code']);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function (Section $section) {
            $capacity = $section->capacity ?: 45;
            $enrolled = (int) $section->active_enrolled_count;
            $vacancy = max(0, $capacity - $enrolled);

            return [
                'id' => $section->id,
                'batch_id' => $section->batch_id,
                'course_id' => $section->course_id,
                'name' => $section->name,
                'code' => $section->code,
                'capacity' => $capacity,
                'room_number' => $section->room_number,
                'timing' => $section->timing,
                'status' => $section->status,
                'active_enrolled_count' => $enrolled,
                'vacancy' => $vacancy,
                'batch' => $section->batch ? ['id' => $section->batch->id, 'name' => $section->batch->name, 'code' => $section->batch->code] : null,
                'course' => $section->course ? ['id' => $section->course->id, 'name' => $section->course->name, 'code' => $section->course->code] : null,
                'created_at' => $section->created_at?->toIso8601String(),
                'updated_at' => $section->updated_at?->toIso8601String(),
            ];
        });

        return $this->success($items, [
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ]);
    }

    /**
     * 18. Create Classroom Section.
     */
    public function storeSection(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'batch_id' => 'required|exists:batches,id',
            'course_id' => 'required|exists:courses,id',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:10',
            'capacity' => 'nullable|integer|min:1',
            'room_number' => 'nullable|string|max:50',
            'timing' => 'nullable|string|max:150',
            'status' => 'nullable|string|in:active,full,inactive',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The section creation payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $section = $this->academicService->createSection($validator->validated(), $user);

            return $this->success([
                'id' => $section->id,
                'batch_id' => $section->batch_id,
                'course_id' => $section->course_id,
                'name' => $section->name,
                'code' => $section->code,
                'capacity' => $section->capacity,
                'room_number' => $section->room_number,
                'timing' => $section->timing,
                'status' => $section->status,
                'created_at' => $section->created_at?->toIso8601String(),
            ], ['message' => 'Classroom section created successfully.'], 201);
        } catch (Throwable $e) {
            return $this->error('SECTION_CREATION_FAILED', 'Failed to create section: '.$e->getMessage(), 500);
        }
    }

    /**
     * 19. Retrieve Section Details.
     */
    public function showSection(int $id): JsonResponse
    {
        $section = Section::with([
            'batch:id,name,code,academic_year',
            'course:id,name,code,category',
            'creator:id,name,email',
            'updater:id,name,email',
        ])
            ->withCount(['enrollments as active_enrolled_count' => fn ($q) => $q->where('status', 'active')])
            ->find($id);

        if (! $section) {
            return $this->error('SECTION_NOT_FOUND', "Classroom section ID {$id} not found.", 404);
        }

        $capacity = $section->capacity ?: 45;
        $enrolled = (int) $section->active_enrolled_count;
        $vacancy = max(0, $capacity - $enrolled);

        return $this->success([
            'id' => $section->id,
            'name' => $section->name,
            'code' => $section->code,
            'batch' => $section->batch ? ['id' => $section->batch->id, 'name' => $section->batch->name, 'code' => $section->batch->code, 'academic_year' => $section->batch->academic_year] : null,
            'course' => $section->course ? ['id' => $section->course->id, 'name' => $section->course->name, 'code' => $section->course->code, 'category' => $section->course->category] : null,
            'capacity' => $capacity,
            'active_enrolled_count' => $enrolled,
            'vacancy' => $vacancy,
            'occupancy_pct' => $capacity > 0 ? round(($enrolled / $capacity) * 100, 1) : 0,
            'room_number' => $section->room_number,
            'timing' => $section->timing,
            'status' => $section->status,
            'created_by' => $section->creator ? ['id' => $section->creator->id, 'name' => $section->creator->name] : null,
            'updated_by' => $section->updater ? ['id' => $section->updater->id, 'name' => $section->updater->name] : null,
            'created_at' => $section->created_at?->toIso8601String(),
            'updated_at' => $section->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * 20. Update Section Details.
     */
    public function updateSection(int $id, Request $request): JsonResponse
    {
        $section = Section::find($id);

        if (! $section) {
            return $this->error('SECTION_NOT_FOUND', "Classroom section ID {$id} not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'code' => 'sometimes|required|string|max:10',
            'capacity' => 'nullable|integer|min:1',
            'room_number' => 'nullable|string|max:50',
            'timing' => 'nullable|string|max:150',
            'status' => 'nullable|string|in:active,full,inactive',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The section update payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $updated = $this->academicService->updateSection($section, $validator->validated(), $user);

            return $this->success([
                'id' => $updated->id,
                'name' => $updated->name,
                'code' => $updated->code,
                'capacity' => $updated->capacity,
                'room_number' => $updated->room_number,
                'timing' => $updated->timing,
                'status' => $updated->status,
                'updated_at' => $updated->updated_at?->toIso8601String(),
            ], ['message' => 'Classroom section updated successfully.']);
        } catch (Throwable $e) {
            return $this->error('SECTION_UPDATE_FAILED', 'Failed to update section: '.$e->getMessage(), 500);
        }
    }

    /**
     * 21. Soft Delete Classroom Section.
     */
    public function destroySection(int $id, Request $request): JsonResponse
    {
        $section = Section::find($id);

        if (! $section) {
            return $this->error('SECTION_NOT_FOUND', "Classroom section ID {$id} not found.", 404);
        }

        try {
            $user = $this->resolveUser($request);
            $this->academicService->deleteSection($section, $user);

            return $this->success(null, ['message' => "Classroom section '{$section->name}' was successfully soft-deleted."]);
        } catch (ValidationException $e) {
            return $this->error('ACTIVE_DEPENDENCY_PREVENTED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('SECTION_DELETION_FAILED', 'Failed to delete section: '.$e->getMessage(), 500);
        }
    }

    /**
     * 22. List Academic Years & Sessions.
     */
    public function academicYears(Request $request): JsonResponse
    {
        try {
            $years = $this->academicService->getAcademicYears();

            return $this->success($years);
        } catch (Throwable $e) {
            return $this->error('ACADEMIC_YEARS_FAILED', 'Failed to retrieve academic years: '.$e->getMessage(), 500);
        }
    }

    /**
     * 23. Retrieve Academic Calendar, Holidays & Schedules.
     */
    public function calendar(Request $request): JsonResponse
    {
        try {
            $academicYear = $request->input('academic_year');
            $courseId = $request->input('course_id') ? (int) $request->input('course_id') : null;
            $batchId = $request->input('batch_id') ? (int) $request->input('batch_id') : null;
            $month = $request->input('month') ? (int) $request->input('month') : null;

            $calendar = $this->academicService->getAcademicCalendar($academicYear, $courseId, $batchId, $month);

            return $this->success($calendar);
        } catch (Throwable $e) {
            return $this->error('CALENDAR_FETCH_FAILED', 'Failed to retrieve academic calendar: '.$e->getMessage(), 500);
        }
    }

    /**
     * 24. Export Courses Catalog as JSON Dataset.
     */
    public function exportCourses(Request $request): JsonResponse
    {
        $courses = Course::withCount(['batches', 'sections'])
            ->orderBy('name')
            ->get();

        return $this->success($courses, [
            'total_exported' => $courses->count(),
            'exported_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 25. Bulk Import Courses via JSON Array.
     */
    public function importCourses(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'courses' => 'required|array|min:1',
            'courses.*.name' => 'required|string|max:150',
            'courses.*.code' => 'required|string|max:50',
            'courses.*.fee' => 'nullable|numeric|min:0',
            'courses.*.category' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The courses import payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $result = $this->academicService->importCourses($request->input('courses'), $user);

            return $this->success($result, ['message' => "Import complete: {$result['created']} created, {$result['updated']} updated."]);
        } catch (Throwable $e) {
            return $this->error('COURSES_IMPORT_FAILED', 'Failed to import courses: '.$e->getMessage(), 500);
        }
    }

    /**
     * 26. Export Batches Dataset as JSON.
     */
    public function exportBatches(Request $request): JsonResponse
    {
        $batches = Batch::with(['courses:id,name,code'])
            ->withCount(['sections'])
            ->orderByDesc('academic_year')
            ->get();

        return $this->success($batches, [
            'total_exported' => $batches->count(),
            'exported_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 27. Bulk Import Batches via JSON Array.
     */
    public function importBatches(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'batches' => 'required|array|min:1',
            'batches.*.name' => 'required|string|max:150',
            'batches.*.code' => 'required|string|max:50',
            'batches.*.academic_year' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The batches import payload failed validation.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $result = $this->academicService->importBatches($request->input('batches'), $user);

            return $this->success($result, ['message' => "Import complete: {$result['created']} created, {$result['updated']} updated."]);
        } catch (Throwable $e) {
            return $this->error('BATCHES_IMPORT_FAILED', 'Failed to import batches: '.$e->getMessage(), 500);
        }
    }

    /**
     * 28. Public Course Discovery Catalog (Mobile App & Web Landing Pages).
     */
    public function publicCatalog(Request $request): JsonResponse
    {
        $query = Course::where('status', 'active');

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $courses = $query->with([
            'batches' => fn ($b) => $b->where('batches.status', '!=', 'archived')
                ->select('batches.id', 'batches.name', 'batches.code', 'batches.academic_year', 'batches.status', 'batches.start_date', 'batches.end_date'),
        ])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'category', 'description', 'duration', 'fee', 'fee_type', 'fee_reserved', 'fee_structure', 'capacity', 'has_sections', 'admit_once_only'])
            ->map(function (Course $course) {
                return [
                    'id' => $course->id,
                    'name' => $course->name,
                    'code' => $course->code,
                    'category' => $course->category,
                    'description' => $course->description,
                    'duration' => $course->duration,
                    'fee' => (float) $course->fee,
                    'fee_type' => $course->fee_type,
                    'fee_reserved' => $course->fee_reserved !== null ? (float) $course->fee_reserved : null,
                    'fee_structure' => $course->fee_structure,
                    'is_free' => $course->isFree(),
                    'capacity' => $course->capacity,
                    'open_batches' => $course->batches->map(fn ($b) => [
                        'batch_id' => $b->id,
                        'name' => $b->name,
                        'code' => $b->code,
                        'academic_year' => $b->academic_year,
                        'status' => $b->status,
                        'start_date' => $b->start_date?->format('Y-m-d'),
                        'end_date' => $b->end_date?->format('Y-m-d'),
                    ]),
                ];
            });

        return $this->success($courses);
    }

    /**
     * 29. Public Single Course Profile & Syllabus Overview.
     */
    public function publicCourseDetail(string $identifier): JsonResponse
    {
        $course = $this->findCourse($identifier);

        if (! $course || $course->status !== 'active') {
            return $this->error('COURSE_NOT_FOUND', "Course matching '{$identifier}' could not be found or is not currently open.", 404);
        }

        $course->load([
            'batches' => fn ($b) => $b->where('batches.status', '!=', 'archived')
                ->select('batches.id', 'batches.name', 'batches.code', 'batches.academic_year', 'batches.status', 'batches.start_date', 'batches.end_date'),
        ]);

        return $this->success([
            'id' => $course->id,
            'name' => $course->name,
            'code' => $course->code,
            'category' => $course->category,
            'description' => $course->description,
            'duration' => $course->duration,
            'fee' => (float) $course->fee,
            'fee_type' => $course->fee_type,
            'fee_reserved' => $course->fee_reserved !== null ? (float) $course->fee_reserved : null,
            'fee_structure' => $course->fee_structure,
            'is_free' => $course->isFree(),
            'capacity' => $course->capacity,
            'admit_once_only' => (bool) $course->admit_once_only,
            'max_attempts' => $course->max_attempts,
            'open_batches' => $course->batches->map(fn ($b) => [
                'batch_id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'academic_year' => $b->academic_year,
                'status' => $b->status,
                'start_date' => $b->start_date?->format('Y-m-d'),
                'end_date' => $b->end_date?->format('Y-m-d'),
            ]),
        ]);
    }
}
