<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\CoursePlan;
use App\Models\Faculty;
use App\Services\AuditLogService;
use App\Services\ClassReminderService;
use App\Services\ClassScheduleService;
use App\Services\FacultyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ClassScheduleApiController extends Controller
{
    /**
     * Get list of scheduled classes with advanced filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ClassSchedule::query()
            ->with(['batch', 'course', 'section', 'faculties']);

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($sectionId = $request->input('section_id')) {
            $query->where('section_id', $sectionId);
        }

        if ($facultyId = $request->input('faculty_id')) {
            $query->whereHas('faculties', fn ($q) => $q->where('faculties.id', $facultyId));
        }

        if ($classType = $request->input('class_type')) {
            $query->where('class_type', $classType);
        }

        if ($deliveryMode = $request->input('delivery_mode')) {
            $query->where('delivery_mode', $deliveryMode);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('class_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('class_date', '<=', $request->input('date_to'));
        }

        if ($request->boolean('today', false)) {
            $query->today();
        }

        if ($request->boolean('upcoming', false)) {
            $query->upcoming();
        }

        if ($request->boolean('has_recording', false)) {
            $query->where('recording_status', 'available')
                ->orWhereNotNull('recording_url');
        }

        if ($search = $request->input('search')) {
            $term = '%'.trim($search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)
                    ->orWhere('topics', 'like', $term)
                    ->orWhere('schedule_code', 'like', $term)
                    ->orWhere('lecture_number', 'like', $term)
                    ->orWhere('classroom', 'like', $term)
                    ->orWhereHas('faculties', fn ($f) => $f->where('name', 'like', $term));
            });
        }

        $sortField = in_array($request->input('sort_by'), ['class_date', 'start_time', 'subject', 'lecture_number', 'created_at'], true)
            ? $request->input('sort_by')
            : 'class_date';
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortDir)->orderBy('start_time', 'asc');

        if ($request->boolean('all', false)) {
            $classes = $query->get();

            return response()->json([
                'success' => true,
                'data' => $classes,
                'total' => $classes->count(),
            ]);
        }

        $paginated = $query->paginate($request->integer('per_page', 25));

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
     * Get single class schedule details.
     */
    public function show(int $id): JsonResponse
    {
        $schedule = ClassSchedule::with(['batch', 'course', 'section', 'coursePlan', 'faculties', 'rescheduledFrom'])
            ->find($id);

        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $schedule,
        ]);
    }

    /**
     * Store new class schedule (single or recurring).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id' => 'required|exists:batches,id',
            'course_id' => 'required|exists:courses,id',
            'section_id' => 'nullable|exists:sections,id',
            'course_plan_id' => 'nullable|exists:course_plans,id',
            'class_type' => 'required|string|in:regular,optional,extra,revision,guest_lecture',
            'class_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required',
            'subject' => 'nullable|string|max:191',
            'topics' => 'nullable|string',
            'description' => 'nullable|string',
            'lecture_number' => 'nullable|string|max:50',
            'delivery_mode' => 'required|string|in:offline,online,hybrid',
            'classroom' => 'nullable|string|max:100',
            'platform' => 'nullable|string|max:50',
            'meeting_link' => 'nullable|url',
            'meeting_id' => 'nullable|string|max:100',
            'meeting_password' => 'nullable|string|max:100',
            'platform_other' => 'nullable|string|max:100',
            'faculty_ids' => 'nullable|array',
            'faculty_ids.*' => 'exists:faculties,id',
            'is_recurring' => 'nullable|boolean',
            'recurrence' => 'nullable|array',
            'recurrence.end_date' => 'required_if:is_recurring,true|date|after_or_equal:class_date',
            'recurrence.days' => 'nullable|array',
            'recurrence.start_lecture_no' => 'nullable|integer',
        ]);

        try {
            $facultyIds = $request->input('faculty_ids', []);

            if ($request->boolean('is_recurring')) {
                $recurrence = $request->input('recurrence', []);
                $config = [
                    'start_date' => $validated['class_date'],
                    'end_date' => $recurrence['end_date'],
                    'days_of_week' => $recurrence['days'] ?? [Carbon::parse($validated['class_date'])->format('l')],
                    'start_lecture_no' => $recurrence['start_lecture_no'] ?? null,
                ];

                $result = ClassScheduleService::generateRecurring($validated, $facultyIds, $config);

                return response()->json([
                    'success' => true,
                    'message' => "Successfully generated {$result['count']} recurring classes.",
                    'data' => $result['schedules'],
                ], 201);
            }

            $schedule = ClassScheduleService::createSingle($validated, $facultyIds);

            return response()->json([
                'success' => true,
                'message' => 'Class schedule created successfully.',
                'data' => $schedule,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule class: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update an existing class schedule.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $schedule = ClassSchedule::find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        $validated = $request->validate([
            'batch_id' => 'sometimes|exists:batches,id',
            'course_id' => 'sometimes|exists:courses,id',
            'section_id' => 'nullable|exists:sections,id',
            'course_plan_id' => 'nullable|exists:course_plans,id',
            'class_type' => 'sometimes|string|in:regular,optional,extra,revision,guest_lecture',
            'class_date' => 'sometimes|date',
            'start_time' => 'sometimes',
            'end_time' => 'sometimes',
            'subject' => 'nullable|string|max:191',
            'topics' => 'nullable|string',
            'description' => 'nullable|string',
            'lecture_number' => 'nullable|string|max:50',
            'delivery_mode' => 'sometimes|string|in:offline,online,hybrid',
            'classroom' => 'nullable|string|max:100',
            'platform' => 'nullable|string|max:50',
            'meeting_link' => 'nullable|url',
            'meeting_id' => 'nullable|string|max:100',
            'meeting_password' => 'nullable|string|max:100',
            'platform_other' => 'nullable|string|max:100',
            'faculty_ids' => 'nullable|array',
            'faculty_ids.*' => 'exists:faculties,id',
            'status' => 'sometimes|string|in:scheduled,in_progress,completed,rescheduled,cancelled',
        ]);

        try {
            $facultyIds = $request->has('faculty_ids') ? $request->input('faculty_ids') : null;
            $updated = ClassScheduleService::updateSchedule($schedule, $validated, $facultyIds);

            return response()->json([
                'success' => true,
                'message' => 'Class schedule updated successfully.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update class schedule: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reschedule a class.
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $schedule = ClassSchedule::find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        $validated = $request->validate([
            'new_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $updated = ClassScheduleService::reschedule(
                $schedule,
                $validated['new_date'],
                $validated['start_time'],
                $validated['end_time'],
                $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Class rescheduled successfully.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reschedule class: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel a class.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $schedule = ClassSchedule::find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $cancelled = ClassScheduleService::cancel($schedule, $validated['reason']);

            return response()->json([
                'success' => true,
                'message' => 'Class cancelled successfully.',
                'data' => $cancelled,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel class: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Add or update recording access for a class.
     */
    public function updateRecording(Request $request, int $id): JsonResponse
    {
        $schedule = ClassSchedule::find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        $validated = $request->validate([
            'recording_url' => 'nullable|url',
            'recording_password' => 'nullable|string|max:100',
            'recording_notes' => 'nullable|string',
            'recording_status' => 'nullable|string|in:not_available,available,processing',
        ]);

        try {
            $updated = ClassScheduleService::updateRecording($schedule, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Class recording information updated.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update recording: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Soft delete a scheduled class.
     */
    public function destroy(int $id): JsonResponse
    {
        $schedule = ClassSchedule::find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        try {
            $code = $schedule->schedule_code;
            $schedule->delete();

            AuditLogService::log(
                event: 'class_schedule_deleted',
                description: "Deleted class schedule {$code}",
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => "Class {$code} deleted successfully.",
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete class: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * List faculties for scheduling.
     */
    public function faculties(Request $request): JsonResponse
    {
        $query = Faculty::query()->with('partnerInstitute')->active();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('specialization', 'like', "%{$search}%");
            });
        }

        if ($partnerInstituteId = $request->input('partner_institute_id')) {
            $query->where('partner_institute_id', $partnerInstituteId);
        }

        if ($facultyType = $request->input('faculty_type')) {
            $query->where('faculty_type', $facultyType);
        }

        $faculties = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $faculties,
            'total' => $faculties->count(),
        ]);
    }

    /**
     * Quick register a new faculty member via API.
     */
    public function quickCreateFaculty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'designation' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'partner_institute_id' => 'nullable|exists:partner_institutes,id',
            'faculty_type' => 'nullable|in:regular,guest,visiting,contractual,adjunct',
            'specialization' => 'nullable|string|max:191',
            'subjects' => 'nullable|array',
            'bio' => 'nullable|string',
        ]);

        try {
            $faculty = Faculty::create([
                'name' => trim($validated['name']),
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'designation' => $validated['designation'] ?? 'Faculty Member',
                'department' => $validated['department'] ?? 'General Studies',
                'partner_institute_id' => $validated['partner_institute_id'] ?? null,
                'faculty_type' => $validated['faculty_type'] ?? 'regular',
                'specialization' => $validated['specialization'] ?? null,
                'subjects' => $validated['subjects'] ?? [],
                'bio' => $validated['bio'] ?? null,
                'status' => 'active',
            ]);

            AuditLogService::log(
                event: 'faculty_created_api',
                description: "Created faculty via API: {$faculty->name} ({$faculty->faculty_code})",
                auditable: $faculty,
                newValues: $faculty->toArray(),
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Faculty created successfully.',
                'data' => $faculty->load('partnerInstitute'),
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create faculty: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * List course plans.
     */
    public function coursePlans(Request $request): JsonResponse
    {
        $query = CoursePlan::with(['batch', 'course', 'section']);

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($request->boolean('published_only', true)) {
            $query->published();
        }

        $plans = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $plans,
            'total' => $plans->count(),
        ]);
    }

    /**
     * Store a new course plan.
     */
    public function storeCoursePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'batch_id' => 'required|exists:batches,id',
            'course_id' => 'required|exists:courses,id',
            'section_id' => 'nullable|exists:sections,id',
            'plan_type' => 'required|in:annual,monthly,weekly,custom',
            'academic_year' => 'nullable|string|max:50',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'total_lectures_planned' => 'required|integer|min:1',
            'topics_covered' => 'nullable|string',
            'description' => 'nullable|string',
            'version' => 'nullable|string|max:20',
            'status' => 'required|in:draft,published,under_revision,archived',
        ]);

        try {
            $plan = CoursePlan::create($validated);

            AuditLogService::log(
                event: 'course_plan_created',
                description: "Created course plan {$plan->plan_code}: {$plan->title}",
                auditable: $plan,
                newValues: $plan->toArray(),
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Course plan created successfully.',
                'data' => $plan->load(['batch', 'course', 'section']),
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create course plan: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show a specific course plan.
     */
    public function showCoursePlan(int $id): JsonResponse
    {
        $plan = CoursePlan::with(['batch', 'course', 'section', 'classSchedules'])->find($id);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Course plan not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $plan,
        ]);
    }

    /**
     * Delete a course plan.
     */
    public function destroyCoursePlan(int $id): JsonResponse
    {
        $plan = CoursePlan::find($id);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Course plan not found.'], 404);
        }

        try {
            $code = $plan->plan_code;
            $plan->delete();

            AuditLogService::log(
                event: 'course_plan_deleted',
                description: "Deleted course plan {$code}",
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => "Course plan {$code} deleted successfully.",
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete course plan: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get classes for the authenticated student.
     */
    public function studentClasses(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $query = ClassSchedule::query()
            ->with(['batch', 'course', 'section', 'faculties'])
            ->forStudent($user);

        if ($request->boolean('today', false)) {
            $query->today();
        } elseif ($request->boolean('upcoming', false)) {
            $query->upcoming();
        } elseif ($request->boolean('history', false)) {
            $query->past();
        }

        if ($request->boolean('recordings_only', false)) {
            $query->where(function ($q) {
                $q->where('recording_status', 'available')
                    ->orWhereNotNull('recording_url');
            });
        }

        $classes = $query->orderBy('class_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $classes->items(),
            'meta' => [
                'current_page' => $classes->currentPage(),
                'last_page' => $classes->lastPage(),
                'total' => $classes->total(),
            ],
        ]);
    }

    /**
     * Restore a soft-deleted class schedule.
     */
    public function restore(int $id): JsonResponse
    {
        $schedule = ClassSchedule::onlyTrashed()->find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Soft-deleted class schedule not found.'], 404);
        }

        try {
            DB::transaction(function () use ($schedule) {
                $schedule->restore();
                AuditLogService::log(
                    event: 'class_schedule_restored',
                    description: "Restored class schedule {$schedule->schedule_code}",
                    auditable: $schedule,
                    userId: auth()->id()
                );
            });

            return response()->json([
                'success' => true,
                'message' => "Class {$schedule->schedule_code} restored successfully.",
                'data' => $schedule->fresh()->load(['batch', 'course', 'section', 'faculties']),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore class: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Mark a class lecture as completed.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $schedule = ClassSchedule::find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        try {
            $completed = ClassScheduleService::markCompleted($schedule);

            return response()->json([
                'success' => true,
                'message' => "Class {$schedule->schedule_code} marked as completed.",
                'data' => $completed,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark class as completed: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Trigger reminder alert notification to enrolled students.
     */
    public function sendReminder(Request $request, int $id): JsonResponse
    {
        $schedule = ClassSchedule::with(['batch', 'course', 'section', 'faculties'])->find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class schedule not found.'], 404);
        }

        $validated = $request->validate([
            'channels' => 'nullable|array',
            'channels.*' => 'in:whatsapp,sms,email,telegram',
            'custom_message' => 'nullable|string|max:1000',
        ]);

        try {
            $result = ClassReminderService::sendManualReminder(
                $schedule,
                $validated['channels'] ?? null,
                $validated['custom_message'] ?? null
            );

            $sentCount = $result['sent_count'] ?? $result['recipients_count'] ?? 0;

            return response()->json([
                'success' => $result['success'] ?? true,
                'message' => $result['message'] ?? "Reminder sent to {$sentCount} student(s).",
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send class reminder: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Weekly matrix / calendar schedule view grouped by date or day of week.
     */
    public function calendar(Request $request): JsonResponse
    {
        $query = ClassSchedule::query()->with(['batch', 'course', 'section', 'faculties']);

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }
        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }
        if ($sectionId = $request->input('section_id')) {
            $query->where('section_id', $sectionId);
        }
        if ($facultyId = $request->input('faculty_id')) {
            $query->whereHas('faculties', fn ($q) => $q->where('faculties.id', $facultyId));
        }

        $dateFrom = $request->input('date_from', now()->startOfWeek()->toDateString());
        $dateTo = $request->input('date_to', now()->endOfWeek()->toDateString());

        $query->whereDate('class_date', '>=', $dateFrom)
            ->whereDate('class_date', '<=', $dateTo)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('class_date', 'asc')
            ->orderBy('start_time', 'asc');

        $classes = $query->get();

        $groupedByDate = $classes->groupBy(fn ($item) => Carbon::parse($item->class_date)->format('Y-m-d'));
        $groupedByDay = $classes->groupBy('day_of_week');

        return response()->json([
            'success' => true,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'total_classes' => $classes->count(),
            'by_date' => $groupedByDate,
            'by_day' => $groupedByDay,
            'classes' => $classes,
        ]);
    }

    /**
     * Faculty teaching workload report dataset.
     */
    public function workloadReport(Request $request): JsonResponse
    {
        $filters = $request->only(['department', 'faculty_type', 'date_from', 'date_to', 'partner_institute_id']);
        $data = ClassScheduleService::getFacultyWorkloadData($filters);

        return response()->json([
            'success' => true,
            'data' => $data,
            'total' => count($data),
        ]);
    }

    /**
     * Course syllabus coverage report dataset.
     */
    public function courseCoverageReport(Request $request): JsonResponse
    {
        $filters = $request->only(['batch_id', 'course_id', 'status']);
        $data = ClassScheduleService::getCourseCoverageData($filters);

        return response()->json([
            'success' => true,
            'data' => $data,
            'total' => count($data),
        ]);
    }

    /**
     * Classroom occupancy and utilization report dataset.
     */
    public function classroomUtilizationReport(Request $request): JsonResponse
    {
        $filters = $request->only(['building', 'status', 'date_from', 'date_to']);
        $data = ClassScheduleService::getClassroomUtilizationData($filters);

        return response()->json([
            'success' => true,
            'data' => $data,
            'total' => count($data),
        ]);
    }

    /**
     * Export routine schedule in Excel, CSV, or PDF.
     */
    public function export(Request $request): Response
    {
        $format = strtolower($request->input('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'csv', 'pdf'], true)) {
            $format = 'xlsx';
        }

        $query = ClassSchedule::query()->with(['batch', 'course', 'section', 'faculties']);

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }
        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('class_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('class_date', '<=', $request->input('date_to'));
        }

        $schedules = $query->orderBy('class_date', 'asc')->orderBy('start_time', 'asc')->get();

        return ClassScheduleService::export($schedules, $format);
    }

    /**
     * Bulk import class routines from spreadsheet.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,xls|max:10240',
        ]);

        try {
            $result = ClassScheduleService::import($request->file('file')->getRealPath());

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success']
                    ? "Imported {$result['imported']} schedules successfully."
                    : 'Import completed with errors.',
                'data' => $result,
            ], $result['success'] ? 200 : 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get automated reminder alert configuration.
     */
    public function reminderSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ClassReminderService::getSettings(),
        ]);
    }

    /**
     * Update automated reminder alert configuration.
     */
    public function updateReminderSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'lead_time_minutes' => 'required|integer|min:5|max:1440',
            'channels' => 'required|array',
            'channels.*' => 'in:whatsapp,sms,email,telegram',
            'template' => 'nullable|string|max:1000',
        ]);

        try {
            ClassReminderService::updateSettings(
                $validated['enabled'],
                $validated['lead_time_minutes'],
                $validated['channels'],
                $validated['template'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Reminder alert settings updated successfully.',
                'data' => ClassReminderService::getSettings(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update reminder settings: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update an existing course plan.
     */
    public function updateCoursePlan(Request $request, int $id): JsonResponse
    {
        $plan = CoursePlan::find($id);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Course plan not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'batch_id' => 'sometimes|exists:batches,id',
            'course_id' => 'sometimes|exists:courses,id',
            'section_id' => 'nullable|exists:sections,id',
            'plan_type' => 'sometimes|in:annual,monthly,weekly,custom',
            'academic_year' => 'nullable|string|max:50',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'total_lectures_planned' => 'sometimes|integer|min:1',
            'topics_covered' => 'nullable|string',
            'description' => 'nullable|string',
            'version' => 'nullable|string|max:20',
            'status' => 'sometimes|in:draft,published,under_revision,archived',
        ]);

        try {
            $oldValues = $plan->toArray();
            $plan->update($validated);

            AuditLogService::log(
                event: 'course_plan_updated',
                description: "Updated course plan {$plan->plan_code}: {$plan->title}",
                auditable: $plan,
                oldValues: $oldValues,
                newValues: $plan->fresh()->toArray(),
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Course plan updated successfully.',
                'data' => $plan->fresh()->load(['batch', 'course', 'section']),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update course plan: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Restore a soft-deleted course plan.
     */
    public function restoreCoursePlan(int $id): JsonResponse
    {
        $plan = CoursePlan::onlyTrashed()->find($id);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Soft-deleted course plan not found.'], 404);
        }

        try {
            $plan->restore();

            AuditLogService::log(
                event: 'course_plan_restored',
                description: "Restored course plan {$plan->plan_code}",
                auditable: $plan,
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => "Course plan {$plan->plan_code} restored successfully.",
                'data' => $plan->fresh()->load(['batch', 'course', 'section']),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore course plan: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get syllabus coverage and lecture completion progress for a course plan.
     */
    public function coursePlanProgress(int $id): JsonResponse
    {
        $plan = CoursePlan::with(['batch', 'course', 'section'])->find($id);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Course plan not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'plan_code' => $plan->plan_code,
                'title' => $plan->title,
                'plan_type' => $plan->plan_type,
                'total_lectures_planned' => $plan->total_lectures_planned,
                'conducted_lectures_count' => $plan->conducted_lectures_count,
                'scheduled_lectures_count' => $plan->scheduled_lectures_count,
                'cancelled_lectures_count' => $plan->cancelled_lectures_count,
                'effective_lectures_count' => $plan->effective_lectures_count,
                'remaining_lectures' => $plan->remaining_lectures_to_schedule,
                'progress_percentage' => $plan->progress_percentage,
                'status' => $plan->status,
                'document_url' => $plan->document_url,
            ],
        ]);
    }

    /**
     * Get single lecture detail for authenticated student.
     */
    public function studentClassDetail(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $schedule = ClassSchedule::with(['batch', 'course', 'section', 'faculties', 'coursePlan'])
            ->forStudent($user)
            ->find($id);

        if (! $schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Class not found or not enrolled in this session.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $schedule->id,
                'schedule_code' => $schedule->schedule_code,
                'subject' => $schedule->subject,
                'topics' => $schedule->topics,
                'description' => $schedule->description,
                'lecture_number' => $schedule->lecture_number,
                'class_date' => $schedule->class_date,
                'day_of_week' => $schedule->day_of_week,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'formatted_time' => $schedule->formatted_time,
                'delivery_mode' => $schedule->delivery_mode,
                'classroom' => $schedule->classroom,
                'platform' => $schedule->platform,
                'meeting_link' => $schedule->meeting_link,
                'meeting_id' => $schedule->meeting_id,
                'meeting_password' => $schedule->meeting_password,
                'is_happening_now' => $schedule->isHappeningNow(),
                'recording_url' => $schedule->recording_access_url,
                'recording_password' => $schedule->recording_password,
                'recording_notes' => $schedule->recording_notes,
                'recording_status' => $schedule->recording_status,
                'status' => $schedule->status,
                'faculties' => $schedule->faculties->map(fn ($f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'designation' => $f->designation,
                    'department' => $f->department,
                    'avatar_url' => $f->avatar_url,
                ]),
                'course' => $schedule->course ? ['id' => $schedule->course->id, 'name' => $schedule->course->name, 'code' => $schedule->course->code] : null,
                'batch' => $schedule->batch ? ['id' => $schedule->batch->id, 'name' => $schedule->batch->name, 'code' => $schedule->batch->code] : null,
                'section' => $schedule->section ? ['id' => $schedule->section->id, 'name' => $schedule->section->name, 'code' => $schedule->section->code] : null,
            ],
        ]);
    }

    /**
     * Get published course plans for authenticated student's enrollments.
     */
    public function studentCoursePlans(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $activeEnrollments = $user->studentEnrollments()->where('status', 'active')->get();
        $courseIds = $activeEnrollments->pluck('course_id')->filter()->unique();
        $batchIds = $activeEnrollments->pluck('batch_id')->filter()->unique();

        $plans = CoursePlan::with(['batch', 'course', 'section'])
            ->published()
            ->where(function ($q) use ($courseIds, $batchIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereIn('batch_id', $batchIds);
            })
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $plans,
            'total' => $plans->count(),
        ]);
    }

    /**
     * Download or export personal routine for authenticated student.
     */
    public function studentRoutineDownload(Request $request): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $format = strtolower($request->input('format', 'pdf'));
        $classes = ClassSchedule::with(['batch', 'course', 'section', 'faculties'])
            ->forStudent($user)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('class_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return ClassScheduleService::export($classes, $format);
    }

    /**
     * Personal routine timetable for authenticated faculty user.
     */
    public function mySchedule(Request $request): JsonResponse
    {
        $user = $request->user();
        $faculty = $user?->faculty;

        if (! $faculty) {
            return response()->json(['success' => false, 'message' => 'No faculty profile linked to this account.'], 403);
        }

        $query = $faculty->classSchedules()->with(['batch', 'course', 'section', 'coursePlan']);

        if ($request->boolean('today', false)) {
            $query->today();
        } elseif ($request->boolean('upcoming', false)) {
            $query->upcoming();
        } elseif ($request->boolean('history', false)) {
            $query->past();
        }

        if ($request->filled('date_from')) {
            $query->whereDate('class_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('class_date', '<=', $request->input('date_to'));
        }

        $classes = $query->orderBy('class_date', 'asc')->orderBy('start_time', 'asc')->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $classes->items(),
            'meta' => [
                'current_page' => $classes->currentPage(),
                'last_page' => $classes->lastPage(),
                'total' => $classes->total(),
            ],
        ]);
    }

    /**
     * Profile & statistics for authenticated faculty user.
     */
    public function myProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $faculty = $user?->faculty;

        if (! $faculty) {
            return response()->json(['success' => false, 'message' => 'No faculty profile linked to this account.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'faculty' => $faculty->load('partnerInstitute'),
                'workload' => FacultyService::getFacultyWorkloadStats($faculty),
            ],
        ]);
    }

    /**
     * Workload metrics for authenticated faculty user.
     */
    public function myWorkload(Request $request): JsonResponse
    {
        $user = $request->user();
        $faculty = $user?->faculty;

        if (! $faculty) {
            return response()->json(['success' => false, 'message' => 'No faculty profile linked to this account.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => FacultyService::getFacultyWorkloadStats($faculty),
        ]);
    }

    /**
     * Allow faculty to upload/link recording for their assigned class.
     */
    public function facultyRecording(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $faculty = $user?->faculty;

        if (! $faculty) {
            return response()->json(['success' => false, 'message' => 'No faculty profile linked.'], 403);
        }

        $schedule = $faculty->classSchedules()->find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class not found or not assigned to you.'], 403);
        }

        $validated = $request->validate([
            'recording_url' => 'nullable|url',
            'recording_password' => 'nullable|string|max:100',
            'recording_notes' => 'nullable|string',
            'recording_status' => 'nullable|in:available,not_available,processing',
        ]);

        try {
            $updated = ClassScheduleService::updateRecording($schedule, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Recording updated successfully.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Allow faculty to mark their assigned class completed.
     */
    public function facultyComplete(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $faculty = $user?->faculty;

        if (! $faculty) {
            return response()->json(['success' => false, 'message' => 'No faculty profile linked.'], 403);
        }

        $schedule = $faculty->classSchedules()->find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class not found or not assigned to you.'], 403);
        }

        try {
            $completed = ClassScheduleService::markCompleted($schedule);

            return response()->json([
                'success' => true,
                'message' => "Class {$schedule->schedule_code} marked as completed.",
                'data' => $completed,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Allow faculty to reschedule their assigned class.
     */
    public function facultyReschedule(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $faculty = $user?->faculty;

        if (! $faculty) {
            return response()->json(['success' => false, 'message' => 'No faculty profile linked.'], 403);
        }

        $schedule = $faculty->classSchedules()->find($id);
        if (! $schedule) {
            return response()->json(['success' => false, 'message' => 'Class not found or not assigned to you.'], 403);
        }

        $validated = $request->validate([
            'new_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $updated = ClassScheduleService::reschedule(
                $schedule,
                $validated['new_date'],
                $validated['start_time'],
                $validated['end_time'],
                $validated['reason'] ?? 'Faculty request'
            );

            return response()->json([
                'success' => true,
                'message' => 'Class rescheduled successfully.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
