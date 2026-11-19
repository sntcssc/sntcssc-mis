<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExamCandidateResource;
use App\Http\Resources\ExamDetailResource;
use App\Http\Resources\ExaminationCenterResource;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExaminationCenter;
use App\Models\ExaminationCenterRoom;
use App\Services\Exam\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExamApiController extends Controller
{
    public function __construct(
        protected ExamService $examService
    ) {}

    /**
     * Get aggregate dashboard metrics for Examination Management.
     */
    public function dashboard(): JsonResponse
    {
        $metrics = $this->examService->getDashboardMetrics();

        return response()->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }

    /**
     * Get paginated examinations list with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Exam::with(['centers'])
            ->withCount(['candidates', 'centers']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('exam_name', 'like', "%{$search}%")
                    ->orWhere('exam_code', 'like', "%{$search}%")
                    ->orWhere('conducting_body', 'like', "%{$search}%");
            });
        }

        // Status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Academic Year
        if ($year = $request->input('academic_year')) {
            $query->where('academic_year', $year);
        }

        // Upcoming only
        if ($request->boolean('upcoming')) {
            $query->upcoming();
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        return ExamResource::collection($query->latest('exam_date')->paginate($perPage));
    }

    /**
     * Create a new examination.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_code' => 'required|string|max:50|unique:exams,exam_code',
            'exam_name' => 'required|string|max:200',
            'academic_year' => 'nullable|string|max:20',
            'conducting_body' => 'nullable|string|max:150',
            'notification_date' => 'nullable|date',
            'apply_start' => 'nullable|date',
            'apply_end' => 'nullable|date|after_or_equal:apply_start',
            'exam_date' => 'nullable|date',
            'exam_time' => 'nullable|string|max:50',
            'reporting_time' => 'nullable|string|max:50',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'fee_general' => 'nullable|numeric|min:0',
            'fee_reserved' => 'nullable|numeric|min:0',
            'total_vacancies' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:draft,published,registration_closed,completed,cancelled',
            'instructions' => 'nullable|string',
            'admit_card_instructions' => 'nullable|string',
            'admit_card_release_date' => 'nullable|date',
            'center_ids' => 'nullable|array',
            'center_ids.*' => 'exists:examination_centers,id',
        ]);

        try {
            $exam = $this->examService->createExam($validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Examination created successfully.'),
                'data' => new ExamDetailResource($exam),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to create examination: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to create examination: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Retrieve single examination dossier.
     */
    public function show(string $identifier): JsonResponse
    {
        $exam = is_numeric($identifier)
            ? Exam::with(['centers.rooms', 'creator'])->findOrFail($identifier)
            : Exam::with(['centers.rooms', 'creator'])->where('uuid', $identifier)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new ExamDetailResource($exam),
        ]);
    }

    /**
     * Update an examination.
     */
    public function update(string $identifier, Request $request): JsonResponse
    {
        $exam = is_numeric($identifier)
            ? Exam::findOrFail($identifier)
            : Exam::where('uuid', $identifier)->firstOrFail();

        $validated = $request->validate([
            'exam_code' => 'sometimes|required|string|max:50|unique:exams,exam_code,'.$exam->id,
            'exam_name' => 'sometimes|required|string|max:200',
            'academic_year' => 'nullable|string|max:20',
            'conducting_body' => 'nullable|string|max:150',
            'notification_date' => 'nullable|date',
            'apply_start' => 'nullable|date',
            'apply_end' => 'nullable|date',
            'exam_date' => 'nullable|date',
            'exam_time' => 'nullable|string|max:50',
            'reporting_time' => 'nullable|string|max:50',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'fee_general' => 'nullable|numeric|min:0',
            'fee_reserved' => 'nullable|numeric|min:0',
            'total_vacancies' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:draft,published,registration_closed,completed,cancelled',
            'instructions' => 'nullable|string',
            'admit_card_instructions' => 'nullable|string',
            'admit_card_release_date' => 'nullable|date',
            'center_ids' => 'nullable|array',
            'center_ids.*' => 'exists:examination_centers,id',
        ]);

        try {
            $updated = $this->examService->updateExam($exam, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Examination updated successfully.'),
                'data' => new ExamDetailResource($updated),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to update examination: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to update examination: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Delete an examination.
     */
    public function destroy(string $identifier, Request $request): JsonResponse
    {
        $exam = is_numeric($identifier)
            ? Exam::findOrFail($identifier)
            : Exam::where('uuid', $identifier)->firstOrFail();

        try {
            $this->examService->deleteExam($exam, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Examination archived successfully.'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to archive examination: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to archive examination: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * List examination centers / venues.
     */
    public function centers(Request $request): AnonymousResourceCollection
    {
        $query = ExaminationCenter::with('rooms')
            ->withCount('rooms');

        if ($city = $request->input('city')) {
            $query->where('city', 'like', "%{$city}%");
        }

        if ($district = $request->input('district')) {
            $query->where('district', 'like', "%{$district}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return ExaminationCenterResource::collection($query->orderBy('name')->paginate($perPage));
    }

    /**
     * Store new examination center.
     */
    public function storeCenter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:examination_centers,code',
            'name' => 'required|string|max:200',
            'address' => 'required|string|max:255',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'contact_person' => 'nullable|string|max:150',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:150',
            'total_capacity' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $center = $this->examService->createCenter($validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Examination center created successfully.'),
                'data' => new ExaminationCenterResource($center),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to create examination center: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to create examination center: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Show examination center details.
     */
    public function showCenter(int $id): JsonResponse
    {
        $center = ExaminationCenter::with('rooms')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new ExaminationCenterResource($center),
        ]);
    }

    /**
     * Update examination center details.
     */
    public function updateCenter(int $id, Request $request): JsonResponse
    {
        $center = ExaminationCenter::findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|required|string|max:50|unique:examination_centers,code,'.$center->id,
            'name' => 'sometimes|required|string|max:200',
            'address' => 'sometimes|required|string|max:255',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'contact_person' => 'nullable|string|max:150',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:150',
            'total_capacity' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $updated = $this->examService->updateCenter($center, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Examination center updated successfully.'),
                'data' => new ExaminationCenterResource($updated),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to update examination center: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to update examination center: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Delete examination center.
     */
    public function destroyCenter(int $id, Request $request): JsonResponse
    {
        $center = ExaminationCenter::findOrFail($id);

        try {
            $this->examService->deleteCenter($center, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Examination center deleted successfully.'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to delete examination center: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to delete examination center: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Add a classroom/hall to an examination center.
     */
    public function storeRoom(int $centerId, Request $request): JsonResponse
    {
        $center = ExaminationCenter::findOrFail($centerId);

        $validated = $request->validate([
            'room_number' => 'required|string|max:50',
            'floor' => 'nullable|string|max:50',
            'building_name' => 'nullable|string|max:100',
            'capacity' => 'required|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $room = $this->examService->createRoom($center, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Room hall added to center successfully.'),
                'data' => $room,
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to create room: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to add room: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Delete a classroom/hall from an examination center.
     */
    public function destroyRoom(int $centerId, int $roomId, Request $request): JsonResponse
    {
        $room = ExaminationCenterRoom::where('examination_center_id', $centerId)->findOrFail($roomId);

        try {
            $this->examService->deleteRoom($room, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Examination hall / room deleted successfully.'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to delete room: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to delete room: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Get live seating plan for an examination across centers and rooms.
     */
    public function seatingPlan(string $identifier): JsonResponse
    {
        $exam = is_numeric($identifier)
            ? Exam::findOrFail($identifier)
            : Exam::where('uuid', $identifier)->firstOrFail();

        $plan = $this->examService->getSeatingPlan($exam);

        return response()->json([
            'success' => true,
            'data' => $plan,
        ]);
    }

    /**
     * Bulk allocate candidate seats and generate roll numbers.
     */
    public function allocateSeats(string $identifier, Request $request): JsonResponse
    {
        $exam = is_numeric($identifier)
            ? Exam::findOrFail($identifier)
            : Exam::where('uuid', $identifier)->firstOrFail();

        $validated = $request->validate([
            'center_id' => 'required|exists:examination_centers,id',
            'room_id' => 'nullable|exists:examination_center_rooms,id',
            'candidates' => 'required|array|min:1',
            'candidates.*.candidate_name' => 'required|string|max:150',
            'candidates.*.candidate_email' => 'nullable|email|max:150',
            'candidates.*.candidate_phone' => 'nullable|string|max:25',
            'candidates.*.student_id' => 'nullable|integer',
            'candidates.*.roll_number' => 'nullable|string|max:50',
            'candidates.*.seat_number' => 'nullable|string|max:50',
        ]);

        try {
            $result = $this->examService->allocateSeats(
                $exam,
                $validated['candidates'],
                (int) $validated['center_id'],
                isset($validated['room_id']) ? (int) $validated['room_id'] : null,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => __('Allocated :count candidate(s) successfully.', ['count' => $result['allocated_count']]),
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to allocate examination seats: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to allocate seats: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * List candidates scheduled for an exam.
     */
    public function candidates(string $identifier, Request $request): AnonymousResourceCollection
    {
        $exam = is_numeric($identifier)
            ? Exam::findOrFail($identifier)
            : Exam::where('uuid', $identifier)->firstOrFail();

        $query = $exam->candidates()->with(['center', 'room']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('candidate_name', 'like', "%{$search}%")
                    ->orWhere('roll_number', 'like', "%{$search}%")
                    ->orWhere('candidate_email', 'like', "%{$search}%")
                    ->orWhere('seat_number', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('attendance_status')) {
            $query->where('attendance_status', $status);
        }

        if ($centerId = $request->input('center_id')) {
            $query->where('examination_center_id', $centerId);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        return ExamCandidateResource::collection($query->orderBy('roll_number')->paginate($perPage));
    }

    /**
     * Mark candidate attendance during exam day.
     */
    public function markAttendance(string $identifier, Request $request): JsonResponse
    {
        $exam = is_numeric($identifier)
            ? Exam::findOrFail($identifier)
            : Exam::where('uuid', $identifier)->firstOrFail();

        $validated = $request->validate([
            'roll_number' => 'required|string',
            'attendance_status' => 'required|string|in:present,absent,disqualified',
            'remarks' => 'nullable|string|max:500',
        ]);

        try {
            $candidate = $this->examService->markAttendance(
                $exam,
                $validated['roll_number'],
                $validated['attendance_status'],
                $validated['remarks'] ?? null,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => __('Attendance recorded successfully.'),
                'data' => new ExamCandidateResource($candidate->load(['center', 'room'])),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to mark attendance: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to record attendance: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Record examination marks and ranks.
     */
    public function recordResults(string $identifier, Request $request): JsonResponse
    {
        $exam = is_numeric($identifier)
            ? Exam::findOrFail($identifier)
            : Exam::where('uuid', $identifier)->firstOrFail();

        $validated = $request->validate([
            'results' => 'required|array|min:1',
            'results.*.roll_number' => 'required|string',
            'results.*.marks_obtained' => 'nullable|numeric|min:0|max:1000',
            'results.*.rank' => 'nullable|integer|min:1',
            'results.*.is_qualified' => 'nullable|boolean',
            'results.*.remarks' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->examService->recordResults($exam, $validated['results'], $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Results updated for :count candidate(s).', ['count' => $result['updated_count']]),
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to record examination results: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to record results: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Verify candidate Admit Card QR token or roll number for invigilator scanning devices.
     */
    public function verifyAdmitCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required_without:roll_number|string',
            'roll_number' => 'required_without:token|string',
        ]);

        $tokenOrRoll = $validated['token'] ?? $validated['roll_number'];

        try {
            $data = $this->examService->verifyAdmitCard($tokenOrRoll);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('Admit card verification failed: Token or Roll Number not recognized.'),
            ], 404);
        }
    }

    /**
     * Student Self-Service: Get registered exams for logged in user or query identifier.
     */
    public function myExams(Request $request): JsonResponse
    {
        $user = $request->user();
        $studentId = $request->input('student_id') ?: $user?->id;

        $query = ExamCandidate::with(['exam', 'center', 'room']);

        if ($user) {
            $query->where('user_id', $user->id);
        } elseif ($studentId) {
            $query->where(function ($q) use ($studentId) {
                $q->where('student_id', $studentId)
                    ->orWhere('user_id', $studentId);
            });
        } else {
            return response()->json([
                'success' => false,
                'message' => __('Student or user identifier is required.'),
            ], 400);
        }

        $candidates = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data' => ExamCandidateResource::collection($candidates),
        ]);
    }

    /**
     * Generate or stream official Admit Card / Hall Ticket payload.
     */
    public function admitCard(string $identifier, Request $request): JsonResponse
    {
        $rollOrToken = $request->input('roll_number') ?: $request->input('token') ?: $identifier;

        $candidate = ExamCandidate::with(['exam', 'center', 'room'])
            ->where(function ($q) use ($rollOrToken) {
                $q->where('roll_number', $rollOrToken)
                    ->orWhere('admit_card_token', $rollOrToken)
                    ->orWhere('id', is_numeric($rollOrToken) ? (int) $rollOrToken : 0);
            })
            ->firstOrFail();

        $user = $request->user();
        $apiKey = $request->attributes->get('api_key');
        $hasExamScope = ($apiKey && $apiKey->hasScope('read:examinations'))
            || ($user && ($user->can('examinations.view') || $user->hasRole('Super Administrator')));

        // Check if admit card release date has arrived
        if (! $hasExamScope && $candidate->exam->admit_card_release_date && $candidate->exam->admit_card_release_date->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => __('Admit Card has not been released yet. Scheduled release date: :date', [
                    'date' => $candidate->exam->admit_card_release_date->format('Y-m-d H:i:s'),
                ]),
            ], 403);
        }

        $admitCardData = $this->examService->generateAdmitCardData($candidate);

        return response()->json([
            'success' => true,
            'data' => $admitCardData,
        ]);
    }
}
