<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OptionalSubject;
use App\Models\OptionalSubjectChangeRequest;
use App\Models\StudentOtherDetail;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class OptionalSubjectApiController extends Controller
{
    /**
     * Resolve the active user context (via session/Sanctum or API key owner).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Get list of optional subjects (with optional category & search filter).
     */
    public function index(Request $request): JsonResponse
    {
        $query = OptionalSubject::query();

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        $sortField = in_array($request->input('sort_by'), ['name', 'code', 'category', 'created_at'], true)
            ? $request->input('sort_by')
            : 'name';
        $sortDirection = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortDirection);

        if ($request->boolean('all', false)) {
            $subjects = $query->get();

            return response()->json([
                'success' => true,
                'data' => $subjects,
                'total' => $subjects->count(),
            ]);
        }

        $paginated = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true,
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
     * Get list of unique optional subject categories with counts.
     */
    public function categories(): JsonResponse
    {
        $categories = OptionalSubject::active()
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get student optional subject allocations (Admin/Staff view).
     */
    public function allocations(Request $request): JsonResponse
    {
        $query = User::role('Student')
            ->with([
                'studentOtherDetails:id,user_id,optional_subject,year_of_appearing,attempts_already_given',
                'activeEnrollments.course:id,name,code',
                'activeEnrollments.batch:id,name',
                'activeEnrollments.section:id,name',
            ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhere('urn', 'like', "%{$search}%");
            });
        }

        if ($courseId = $request->input('course_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $courseId));
        }

        if ($batchId = $request->input('batch_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', $batchId));
        }

        if ($sectionId = $request->input('section_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('section_id', $sectionId));
        }

        if ($subject = $request->input('optional_subject')) {
            if ($subject === '__unassigned__') {
                $query->whereDoesntHave('studentOtherDetails')
                    ->orWhereHas('studentOtherDetails', fn ($q) => $q->whereNull('optional_subject')->orWhere('optional_subject', ''));
            } else {
                $query->whereHas('studentOtherDetails', fn ($q) => $q->where('optional_subject', $subject));
            }
        }

        $paginated = $query->orderBy('name')->paginate($request->integer('per_page', 25));

        $items = collect($paginated->items())->map(function ($student) {
            $enrollment = $student->activeEnrollments->first();
            $latestRequest = OptionalSubjectChangeRequest::where('user_id', $student->id)->latest('id')->first();

            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'student_id' => $student->student_id,
                'urn' => $student->urn,
                'course' => $enrollment?->course?->name,
                'batch' => $enrollment?->batch?->name,
                'section' => $enrollment?->section?->name,
                'optional_subject' => $student->studentOtherDetails?->optional_subject ?: 'Not Assigned',
                'latest_change_request' => $latestRequest ? [
                    'request_no' => $latestRequest->request_no,
                    'status' => $latestRequest->status,
                    'requested_subject' => $latestRequest->requested_subject,
                    'created_at' => $latestRequest->created_at?->toISOString(),
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Admin/Staff quick update of a student's optional subject.
     */
    public function quickUpdate(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);

        if ($actor && ! ($actor->can('optional_subjects.manage') || $actor->hasRole('Super Administrator') || $actor->hasRole('Administrator') || $request->attributes->get('api_key')?->hasScope('write:optional_subjects'))) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update optional subject allocations.',
            ], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'optional_subject' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $student = User::findOrFail($validated['user_id']);

        // Check if student has role Student
        if (! $student->hasRole('Student')) {
            return response()->json([
                'success' => false,
                'message' => 'User must be a registered student.',
            ], 422);
        }

        $newSubject = trim($validated['optional_subject']);
        $subModel = OptionalSubject::where('name', $newSubject)->first();

        if ($subModel && $subModel->max_capacity) {
            $currentCount = StudentOtherDetail::where('optional_subject', $subModel->name)
                ->where('user_id', '!=', $student->id)
                ->count();
            if ($currentCount >= $subModel->max_capacity) {
                return response()->json([
                    'success' => false,
                    'message' => "Subject '{$newSubject}' has reached maximum enrollment capacity ({$subModel->max_capacity}).",
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $otherDetails = StudentOtherDetail::firstOrCreate(
                ['user_id' => $student->id],
                [
                    'attempts_already_given' => 0,
                    'attempts_left' => 6,
                ]
            );

            $oldSubject = $otherDetails->optional_subject;

            $otherDetails->update([
                'optional_subject' => $newSubject,
            ]);

            AuditLogService::log(
                event: 'student.optional_subject_quick_updated',
                description: "Optional subject for {$student->name} updated to '{$newSubject}'.",
                auditable: $otherDetails,
                oldValues: ['optional_subject' => $oldSubject],
                newValues: ['optional_subject' => $newSubject],
                userId: $actor?->id
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Optional subject for {$student->name} updated successfully to '{$newSubject}'.",
                'data' => [
                    'user_id' => $student->id,
                    'student_name' => $student->name,
                    'previous_subject' => $oldSubject,
                    'optional_subject' => $newSubject,
                ],
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update optional subject: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the authenticated student's current optional subject and request history.
     */
    public function myOptionalSubject(Request $request): JsonResponse
    {
        /** @var User|null $student */
        $student = $this->resolveUser($request);

        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        if (! $student->hasRole('Student')) {
            return response()->json([
                'success' => false,
                'message' => 'Only enrolled students may access personalized optional subject details.',
            ], 403);
        }

        $otherDetails = $student->studentOtherDetails;
        $currentSubject = $otherDetails?->optional_subject;

        $requests = OptionalSubjectChangeRequest::where('user_id', $student->id)
            ->with('reviewer:id,name')
            ->latest('id')
            ->get();

        $pendingRequest = $requests->firstWhere('status', OptionalSubjectChangeRequest::STATUS_PENDING);

        return response()->json([
            'success' => true,
            'data' => [
                'current_subject' => $currentSubject,
                'has_optional_subject' => ! empty($currentSubject),
                'has_pending_request' => $pendingRequest !== null,
                'pending_request' => $pendingRequest ? [
                    'id' => $pendingRequest->id,
                    'request_no' => $pendingRequest->request_no,
                    'requested_subject' => $pendingRequest->requested_subject,
                    'reason' => $pendingRequest->reason,
                    'created_at' => $pendingRequest->created_at?->toISOString(),
                ] : null,
                'history' => $requests->map(fn ($req) => [
                    'id' => $req->id,
                    'request_no' => $req->request_no,
                    'current_subject' => $req->current_subject,
                    'requested_subject' => $req->requested_subject,
                    'reason' => $req->reason,
                    'status' => $req->status,
                    'admin_remarks' => $req->admin_remarks,
                    'reviewed_by' => $req->reviewer?->name,
                    'reviewed_at' => $req->reviewed_at?->toISOString(),
                    'created_at' => $req->created_at?->toISOString(),
                ]),
            ],
        ]);
    }

    /**
     * Submit a change request for current student.
     */
    public function submitRequest(Request $request): JsonResponse
    {
        /** @var User|null $student */
        $student = $this->resolveUser($request);

        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        if (! $student->hasRole('Student')) {
            return response()->json([
                'success' => false,
                'message' => 'Only enrolled students may submit optional subject change requests.',
            ], 403);
        }

        $validated = $request->validate([
            'requested_subject' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $currentSubject = $student->studentOtherDetails?->optional_subject;

        if (trim($validated['requested_subject']) === trim((string) $currentSubject)) {
            return response()->json([
                'success' => false,
                'message' => 'Requested subject cannot be the same as your current optional subject.',
            ], 422);
        }

        // Check if there is already a pending request
        $hasPending = OptionalSubjectChangeRequest::where('user_id', $student->id)
            ->where('status', OptionalSubjectChangeRequest::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active pending change request awaiting review.',
            ], 422);
        }

        // Validate that requested subject is in active subjects or valid string
        $subModel = OptionalSubject::active()->where('name', $validated['requested_subject'])->first();
        if (! $subModel) {
            return response()->json([
                'success' => false,
                'message' => 'Selected optional subject is not in the approved active curriculum catalog.',
            ], 422);
        }

        if ($subModel->max_capacity) {
            $currentAssigned = StudentOtherDetail::where('optional_subject', $subModel->name)
                ->where('user_id', '!=', $student->id)
                ->count();
            if ($currentAssigned >= $subModel->max_capacity) {
                return response()->json([
                    'success' => false,
                    'message' => "Subject '{$subModel->name}' has reached its maximum enrollment capacity ({$subModel->max_capacity} students).",
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $changeRequest = OptionalSubjectChangeRequest::create([
                'request_no' => OptionalSubjectChangeRequest::generateRequestNo(),
                'user_id' => $student->id,
                'current_subject' => $currentSubject,
                'requested_subject' => trim($validated['requested_subject']),
                'optional_subject_id' => $subModel->id,
                'reason' => trim($validated['reason']),
                'status' => OptionalSubjectChangeRequest::STATUS_PENDING,
            ]);

            AuditLogService::log(
                event: 'student.optional_subject_change_requested',
                description: "Change request #{$changeRequest->request_no} submitted for '{$changeRequest->requested_subject}'. Reason: {$changeRequest->reason}",
                auditable: $changeRequest,
                oldValues: ['current_subject' => $currentSubject],
                newValues: ['requested_subject' => $changeRequest->requested_subject],
                userId: $student->id
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Your request to change optional subject has been submitted successfully.',
                'data' => [
                    'id' => $changeRequest->id,
                    'request_no' => $changeRequest->request_no,
                    'current_subject' => $changeRequest->current_subject,
                    'requested_subject' => $changeRequest->requested_subject,
                    'status' => $changeRequest->status,
                    'created_at' => $changeRequest->created_at?->toISOString(),
                ],
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit request: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all change requests (Admin view).
     */
    public function requests(Request $request): JsonResponse
    {
        $query = OptionalSubjectChangeRequest::with([
            'user:id,name,email,student_id,urn',
            'reviewer:id,name',
        ]);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('request_no', 'like', "%{$search}%")
                    ->orWhere('requested_subject', 'like', "%{$search}%")
                    ->orWhere('current_subject', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%");
                    });
            });
        }

        $paginated = $query->latest('id')->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true,
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
     * Review (approve / reject) an optional subject change request (Admin).
     */
    public function reviewRequest(Request $request, int $id): JsonResponse
    {
        $changeRequest = OptionalSubjectChangeRequest::findOrFail($id);

        if (! $changeRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => "Request #{$changeRequest->request_no} has already been reviewed ({$changeRequest->status}).",
            ], 422);
        }

        $action = $request->input('action');
        if (! in_array($action, ['approve', 'reject'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Action must be either 'approve' or 'reject'.",
            ], 422);
        }

        $reviewer = $this->resolveUser($request);

        if (! $reviewer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated reviewer context.',
            ], 401);
        }

        $hasScope = $request->attributes->get('api_key')?->hasScope('write:optional_subjects');
        $hasPermission = $reviewer->can('optional_subjects.requests') || $reviewer->hasRole('Super Administrator') || $reviewer->hasRole('Administrator');

        if (! $hasScope && ! $hasPermission) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to review optional subject change requests.',
            ], 403);
        }

        if ($action === 'approve') {
            // Check capacity before approving
            $subModel = OptionalSubject::where('name', $changeRequest->requested_subject)->first();
            if ($subModel && $subModel->max_capacity) {
                $currentAssigned = StudentOtherDetail::where('optional_subject', $subModel->name)
                    ->where('user_id', '!=', $changeRequest->user_id)
                    ->count();
                if ($currentAssigned >= $subModel->max_capacity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Cannot approve: Subject '{$subModel->name}' has reached its maximum enrollment capacity ({$subModel->max_capacity} students).",
                    ], 422);
                }
            }

            $remarks = $request->input('remarks');
            $changeRequest->approve($reviewer, $remarks);

            return response()->json([
                'success' => true,
                'message' => "Request #{$changeRequest->request_no} approved and student optional subject updated successfully.",
                'data' => $changeRequest->fresh(),
            ]);
        }

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $changeRequest->reject($reviewer, $validated['remarks']);

        return response()->json([
            'success' => true,
            'message' => "Request #{$changeRequest->request_no} rejected.",
            'data' => $changeRequest->fresh(),
        ]);
    }
}
