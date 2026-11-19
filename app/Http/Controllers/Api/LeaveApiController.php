<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveAllocation;
use App\Models\LeaveApplication;
use App\Models\LeaveCategory;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Leave\LeaveManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LeaveApiController extends Controller
{
    public function __construct(
        protected LeaveManagementService $leaveService
    ) {}

    /**
     * Resolve the active user context (via Sanctum/token, session, or API key owner).
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
     * Locate user by ID, UUID, student_id, employee_id, or email.
     */
    protected function findTargetUser(string $identifier): ?User
    {
        return User::where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('employee_id', $identifier)
                    ->orWhere('phone', $identifier);
            } else {
                $q->where('uuid', $identifier)
                    ->orWhere('email', $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('employee_id', $identifier);
            }
        })->first();
    }

    /**
     * Executive telemetry: active leaves today, pending reviews, category distribution.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $today = Carbon::today()->toDateString();

            $activeLeavesToday = LeaveApplication::where('status', LeaveApplication::STATUS_APPROVED)
                ->whereDate('from_date', '<=', $today)
                ->whereDate('to_date', '>=', $today)
                ->count();

            $pendingCount = LeaveApplication::where('status', LeaveApplication::STATUS_PENDING)->count();

            $approvedThisMonth = LeaveApplication::where('status', LeaveApplication::STATUS_APPROVED)
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count();

            $rejectedThisMonth = LeaveApplication::where('status', LeaveApplication::STATUS_REJECTED)
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count();

            // Category breakdown
            $categoriesBreakdown = LeaveCategory::withCount([
                'applications' => fn ($q) => $q->where('status', LeaveApplication::STATUS_APPROVED),
            ])->get(['id', 'name', 'code', 'color', 'annual_quota', 'applications_count']);

            // Recent 10 applications feed
            $recentApplications = LeaveApplication::with([
                'user:id,name,email,student_id,employee_id,staff_department,profile_photo_url',
                'category:id,name,code,color',
            ])
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            return $this->success([
                'today' => $today,
                'summary' => [
                    'active_leaves_today' => $activeLeavesToday,
                    'pending_reviews' => $pendingCount,
                    'approved_this_month' => $approvedThisMonth,
                    'rejected_this_month' => $rejectedThisMonth,
                ],
                'categories' => $categoriesBreakdown,
                'recent_applications' => $recentApplications,
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to load leave dashboard: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('LEAVE_DASHBOARD_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Granular leave analytics & approval turnaround metrics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $year = (int) $request->input('year', Carbon::now()->year);

            $totalApplications = LeaveApplication::whereYear('created_at', $year)->count();
            $statusCounts = LeaveApplication::whereYear('created_at', $year)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $userTypeCounts = LeaveApplication::whereYear('created_at', $year)
                ->select('user_type', DB::raw('count(*) as count'))
                ->groupBy('user_type')
                ->pluck('count', 'user_type')
                ->toArray();

            // Monthly breakdown
            $monthlyTrend = [];
            for ($m = 1; $m <= 12; $m++) {
                $monthDate = Carbon::create($year, $m, 1);
                $count = LeaveApplication::whereYear('created_at', $year)
                    ->whereMonth('created_at', $m)
                    ->count();
                $monthlyTrend[] = [
                    'month' => $m,
                    'month_name' => $monthDate->format('M'),
                    'applications' => $count,
                ];
            }

            return $this->success([
                'year' => $year,
                'total_applications' => $totalApplications,
                'status_distribution' => $statusCounts,
                'user_type_distribution' => $userTypeCounts,
                'monthly_trend' => $monthlyTrend,
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to load leave statistics: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('LEAVE_STATISTICS_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * List leave categories (Casual, Medical, Academic, Duty, Maternity, etc.).
     */
    public function categories(Request $request): JsonResponse
    {
        $query = LeaveCategory::query();

        if ($applicableTo = $request->input('applicable_to')) {
            $query->whereIn('applicable_to', [$applicableTo, 'both']);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $categories = $query->withCount('applications')->orderBy('name')->get();

        return $this->success($categories);
    }

    /**
     * Create a new leave category.
     */
    public function storeCategory(Request $request): JsonResponse
    {
        $input = $request->all();
        if (isset($input['applicable_to']) && $input['applicable_to'] === 'all') {
            $input['applicable_to'] = 'both';
        }
        if (! isset($input['annual_quota']) && isset($input['max_days_per_year'])) {
            $input['annual_quota'] = $input['max_days_per_year'];
        }
        if (! isset($input['requires_attachment']) && isset($input['requires_proof'])) {
            $input['requires_attachment'] = $input['requires_proof'];
        }

        $validator = Validator::make($input, [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:leave_categories,code',
            'applicable_to' => 'required|string|in:staff,student,both,all',
            'color' => 'nullable|string|max:30',
            'annual_quota' => 'required|numeric|min:0|max:365',
            'monthly_limit' => 'nullable|numeric|min:0|max:31',
            'requires_attachment' => 'boolean',
            'requires_approval' => 'boolean',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid leave category parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $category = LeaveCategory::create(array_merge($validator->validated(), [
                'created_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'leave_category.created',
                description: "Leave category '{$category->name}' ({$category->code}) created.",
                auditable: $category,
                newValues: $category->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($category, null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to create leave category: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('CATEGORY_CREATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Get single leave category dossier.
     */
    public function showCategory(int $id): JsonResponse
    {
        $category = LeaveCategory::withCount(['applications', 'allocations'])->findOrFail($id);

        return $this->success($category);
    }

    /**
     * Update leave category.
     */
    public function updateCategory(int $id, Request $request): JsonResponse
    {
        $category = LeaveCategory::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'code' => "sometimes|required|string|max:20|unique:leave_categories,code,{$id}",
            'applicable_to' => 'sometimes|required|string|in:staff,student,both',
            'color' => 'nullable|string|max:30',
            'annual_quota' => 'sometimes|required|numeric|min:0|max:365',
            'monthly_limit' => 'nullable|numeric|min:0|max:31',
            'requires_attachment' => 'boolean',
            'requires_approval' => 'boolean',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid category parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $oldValues = $category->toArray();

        DB::beginTransaction();
        try {
            $category->update(array_merge($validator->validated(), [
                'updated_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'leave_category.updated',
                description: "Leave category '{$category->name}' updated.",
                auditable: $category,
                oldValues: $oldValues,
                newValues: $category->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($category);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('CATEGORY_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Soft delete leave category. Guarded: blocked if active applications exist.
     */
    public function destroyCategory(int $id, Request $request): JsonResponse
    {
        $category = LeaveCategory::findOrFail($id);

        if ($category->applications()->whereIn('status', [LeaveApplication::STATUS_PENDING, LeaveApplication::STATUS_APPROVED])->exists()) {
            return $this->error(
                'ACTIVE_APPLICATIONS_PREVENTED',
                "Cannot delete leave category '{$category->name}' because active leave applications are associated with it.",
                422
            );
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $category->update(['deleted_by' => $actor?->id]);
            $category->delete();

            AuditLogService::log(
                event: 'leave_category.deleted',
                description: "Leave category '{$category->name}' soft deleted.",
                auditable: $category,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(['message' => __('Leave category deleted successfully.')]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('CATEGORY_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * List user leave allocations and balance quotas.
     */
    public function allocations(Request $request): JsonResponse
    {
        $query = LeaveAllocation::with([
            'user:id,name,email,student_id,employee_id,staff_department',
            'category:id,name,code,color,annual_quota',
        ]);

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($year = $request->input('year')) {
            $query->where('year', $year);
        }
        if ($categoryId = $request->input('leave_category_id')) {
            $query->where('leave_category_id', $categoryId);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $paginated = $query->orderByDesc('year')->orderBy('user_id')->paginate($perPage);

        return $this->success(
            $paginated->items(),
            [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]
        );
    }

    /**
     * Assign or initialize leave quota allocation for a user.
     */
    public function storeAllocation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'leave_category_id' => 'required|exists:leave_categories,id',
            'year' => 'required|integer|min:2020|max:2050',
            'total_days' => 'required|numeric|min:0|max:365',
            'carried_forward_days' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid allocation parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $validated = $validator->validated();

        DB::beginTransaction();
        try {
            $allocation = LeaveAllocation::updateOrCreate(
                [
                    'user_id' => $validated['user_id'],
                    'leave_category_id' => $validated['leave_category_id'],
                    'year' => $validated['year'],
                ],
                [
                    'total_days' => $validated['total_days'],
                    'carried_forward_days' => $validated['carried_forward_days'] ?? 0.0,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $actor?->id,
                ]
            );

            AuditLogService::log(
                event: 'leave_allocation.set',
                description: "Leave quota allocation set for User #{$allocation->user_id} (Category #{$allocation->leave_category_id}, Year {$allocation->year}) to {$allocation->total_days} days.",
                auditable: $allocation,
                newValues: $allocation->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($allocation->load(['user:id,name', 'category:id,name,code']), null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('ALLOCATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Show single leave allocation profile.
     */
    public function showAllocation(int $id): JsonResponse
    {
        $allocation = LeaveAllocation::with(['user:id,name,email', 'category'])->findOrFail($id);

        return $this->success($allocation);
    }

    /**
     * Adjust user leave allocation days.
     */
    public function updateAllocation(int $id, Request $request): JsonResponse
    {
        $allocation = LeaveAllocation::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'total_days' => 'sometimes|required|numeric|min:0|max:365',
            'used_days' => 'nullable|numeric|min:0',
            'pending_days' => 'nullable|numeric|min:0',
            'carried_forward_days' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid update parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $oldValues = $allocation->toArray();

        DB::beginTransaction();
        try {
            $allocation->update(array_merge($validator->validated(), [
                'updated_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'leave_allocation.updated',
                description: "Leave allocation #{$allocation->id} adjusted.",
                auditable: $allocation,
                oldValues: $oldValues,
                newValues: $allocation->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($allocation);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('ALLOCATION_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Complete category-wise balance sheet for a target user.
     */
    public function userBalances(string $userId, Request $request): JsonResponse
    {
        try {
            $user = $this->findTargetUser($userId);
            if (! $user) {
                return $this->error('USER_NOT_FOUND', "User identifier '{$userId}' not found.", 404);
            }

            $year = (int) $request->input('year', Carbon::now()->year);
            $rawBalances = $this->leaveService->getUserLeaveBalances($user, $year);
            $balances = array_map(function ($b) {
                return array_merge($b, [
                    'category_id' => $b['category']->id ?? null,
                    'category_name' => $b['category']->name ?? null,
                    'category_code' => $b['category']->code ?? null,
                ]);
            }, $rawBalances);

            return $this->success([
                'user_id' => $user->id,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'student_id' => $user->student_id,
                    'employee_id' => $user->employee_id,
                ],
                'year' => $year,
                'balances' => $balances,
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to load user leave balances: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('USER_BALANCES_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Search, filter, and paginate leave applications.
     */
    public function applications(Request $request): JsonResponse
    {
        try {
            $query = LeaveApplication::with([
                'user:id,name,email,student_id,employee_id,staff_department,phone,profile_photo_url',
                'category:id,name,code,color',
                'approver:id,name',
            ]);

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }
            if ($userId = $request->input('user_id')) {
                $query->where('user_id', $userId);
            }
            if ($userType = $request->input('user_type')) {
                $query->where('user_type', $userType);
            }
            if ($categoryId = $request->input('leave_category_id')) {
                $query->where('leave_category_id', $categoryId);
            }
            if ($fromDate = $request->input('from_date')) {
                $query->whereDate('from_date', '>=', $fromDate);
            }
            if ($toDate = $request->input('to_date')) {
                $query->whereDate('to_date', '<=', $toDate);
            }

            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('application_no', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhereHas('user', function (Builder $uq) use ($search) {
                            $uq->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('student_id', 'like', "%{$search}%")
                                ->orWhere('employee_id', 'like', "%{$search}%");
                        });
                });
            }

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $paginated = $query->orderByDesc('id')->paginate($perPage);

            return $this->success(
                $paginated->items(),
                [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ]
            );
        } catch (Throwable $e) {
            Log::error("Failed to query leave applications: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('QUERY_LEAVES_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Submit a new leave application (Atomic Transaction + Overlap & Balance Check).
     */
    public function storeApplication(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['from_date']) && isset($input['start_date'])) {
            $input['from_date'] = $input['start_date'];
        }
        if (! isset($input['to_date']) && isset($input['end_date'])) {
            $input['to_date'] = $input['end_date'];
        }

        $validator = Validator::make($input, [
            'user_id' => 'required|exists:users,id',
            'leave_category_id' => 'required|exists:leave_categories,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'is_half_day' => 'boolean',
            'half_day_type' => 'nullable|string|in:first_half,second_half',
            'reason' => 'required|string|max:1000',
            'emergency_contact' => 'nullable|string|max:50',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid leave application parameters.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $applicant = User::findOrFail($validated['user_id']);
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            // Handle file upload
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = 'leave_'.time().'_'.Str::random(8).'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs('leave-attachments', $filename, 'public');
                $validated['attachment_path'] = $path;
            }

            $application = $this->leaveService->applyLeave($applicant, $validated);

            AuditLogService::log(
                event: 'leave_application.submitted',
                description: "Leave application {$application->application_no} submitted for {$applicant->name} ({$application->total_days} days).",
                auditable: $application,
                newValues: $application->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(array_merge($application->load(['user:id,name,email', 'category:id,name,code,color'])->toArray(), [
                'application' => $application,
                'message' => __('Leave application submitted successfully.'),
            ]), null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to submit leave application: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('LEAVE_APPLY_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * Show single leave application dossier.
     */
    public function showApplication(string $identifier): JsonResponse
    {
        try {
            $application = is_numeric($identifier)
                ? LeaveApplication::with(['user', 'category', 'approver', 'attendances'])->findOrFail($identifier)
                : LeaveApplication::with(['user', 'category', 'approver', 'attendances'])->where('application_no', $identifier)->firstOrFail();

            return $this->success([
                'id' => $application->id,
                'application_no' => $application->application_no,
                'user' => [
                    'id' => $application->user_id,
                    'name' => $application->user?->name,
                    'email' => $application->user?->email,
                    'student_id' => $application->user?->student_id,
                    'employee_id' => $application->user?->employee_id,
                    'user_type' => $application->user_type,
                    'department' => $application->user?->staff_department,
                ],
                'category' => [
                    'id' => $application->category?->id,
                    'name' => $application->category?->name,
                    'code' => $application->category?->code,
                    'color' => $application->category?->color,
                ],
                'from_date' => $application->from_date?->toDateString(),
                'to_date' => $application->to_date?->toDateString(),
                'total_days' => $application->total_days,
                'is_half_day' => $application->is_half_day,
                'half_day_type' => $application->half_day_type,
                'reason' => $application->reason,
                'emergency_contact' => $application->emergency_contact,
                'attachment_url' => $application->attachment_path ? Storage::disk('public')->url($application->attachment_path) : null,
                'status' => $application->status,
                'action_by' => $application->approver ? ['id' => $application->approver->id, 'name' => $application->approver->name] : null,
                'action_at' => $application->action_at?->toIso8601String(),
                'action_remarks' => $application->action_remarks,
                'is_synced_to_attendance' => $application->is_synced_to_attendance,
                'synced_attendances_count' => $application->attendances->count(),
                'created_at' => $application->created_at?->toIso8601String(),
                'updated_at' => $application->updated_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return $this->error('LEAVE_NOT_FOUND', "Leave application '{$identifier}' not found.", 404);
        }
    }

    /**
     * Update pending leave application.
     */
    public function updateApplication(string $identifier, Request $request): JsonResponse
    {
        $application = is_numeric($identifier)
            ? LeaveApplication::findOrFail($identifier)
            : LeaveApplication::where('application_no', $identifier)->firstOrFail();

        if ($application->status !== LeaveApplication::STATUS_PENDING) {
            return $this->error('LEAVE_LOCKED', "Cannot update leave application because its current status is '{$application->status}'.", 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'sometimes|required|string|max:1000',
            'emergency_contact' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid update parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $oldValues = $application->toArray();

        DB::beginTransaction();
        try {
            $application->update(array_merge($validator->validated(), [
                'updated_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'leave_application.updated',
                description: "Leave application {$application->application_no} updated.",
                auditable: $application,
                oldValues: $oldValues,
                newValues: $application->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($application);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('LEAVE_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Approve leave application (Atomic Transaction + Auto Attendance Sync + Notification).
     */
    public function approve(string $identifier, Request $request): JsonResponse
    {
        $application = is_numeric($identifier)
            ? LeaveApplication::findOrFail($identifier)
            : LeaveApplication::where('application_no', $identifier)->firstOrFail();

        $actor = $this->resolveUser($request);
        if (! $actor) {
            return $this->error('UNAUTHENTICATED', 'Approver identity required.', 401);
        }

        $remarks = $request->input('remarks', $request->input('approver_remarks'));

        DB::beginTransaction();
        try {
            $approved = $this->leaveService->approveLeave($application, $actor, $remarks);

            AuditLogService::log(
                event: 'leave_application.approved',
                description: "Leave application {$application->application_no} approved by {$actor->name}.",
                auditable: $approved,
                newValues: $approved->toArray(),
                userId: $actor->id
            );

            DB::commit();

            return $this->success(array_merge($approved->load(['user:id,name', 'category', 'attendances'])->toArray(), [
                'application' => $approved,
                'synced_attendance_days' => $approved->attendances()->count(),
                'message' => __('Leave application :no approved successfully. Attendance records synchronized.', ['no' => $approved->application_no]),
            ]));
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to approve leave: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('LEAVE_APPROVAL_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * Reject leave application with mandatory remarks (Restores quota allocation).
     */
    public function reject(string $identifier, Request $request): JsonResponse
    {
        $application = is_numeric($identifier)
            ? LeaveApplication::findOrFail($identifier)
            : LeaveApplication::where('application_no', $identifier)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'remarks' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Rejection remarks are mandatory.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        if (! $actor) {
            return $this->error('UNAUTHENTICATED', 'Approver identity required.', 401);
        }

        DB::beginTransaction();
        try {
            $rejected = $this->leaveService->rejectLeave($application, $actor, $request->input('remarks'));

            AuditLogService::log(
                event: 'leave_application.rejected',
                description: "Leave application {$application->application_no} rejected by {$actor->name}.",
                auditable: $rejected,
                newValues: $rejected->toArray(),
                userId: $actor->id
            );

            DB::commit();

            return $this->success([
                'application' => $rejected->load(['user:id,name', 'category']),
                'message' => __('Leave application :no has been rejected.', ['no' => $rejected->application_no]),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to reject leave: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('LEAVE_REJECT_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * Cancel leave application (Restores quota & cleans synced attendance entries).
     */
    public function cancel(string $identifier, Request $request): JsonResponse
    {
        $application = is_numeric($identifier)
            ? LeaveApplication::findOrFail($identifier)
            : LeaveApplication::where('application_no', $identifier)->firstOrFail();

        $actor = $this->resolveUser($request);
        if (! $actor) {
            return $this->error('UNAUTHENTICATED', 'Actor identity required.', 401);
        }

        $remarks = $request->input('remarks');

        DB::beginTransaction();
        try {
            $cancelled = $this->leaveService->cancelLeave($application, $actor, $remarks);

            AuditLogService::log(
                event: 'leave_application.cancelled',
                description: "Leave application {$application->application_no} cancelled.",
                auditable: $cancelled,
                newValues: $cancelled->toArray(),
                userId: $actor->id
            );

            DB::commit();

            return $this->success(array_merge($cancelled->load(['user:id,name', 'category'])->toArray(), [
                'application' => $cancelled,
                'message' => __('Leave application :no cancelled successfully.', ['no' => $cancelled->application_no]),
            ]));
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to cancel leave: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('LEAVE_CANCEL_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * Soft delete leave application.
     */
    public function destroyApplication(string $identifier, Request $request): JsonResponse
    {
        $application = is_numeric($identifier)
            ? LeaveApplication::findOrFail($identifier)
            : LeaveApplication::where('application_no', $identifier)->firstOrFail();

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $application->update(['deleted_by' => $actor?->id]);
            $application->delete();

            AuditLogService::log(
                event: 'leave_application.deleted',
                description: "Leave application {$application->application_no} soft deleted.",
                auditable: $application,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(['message' => __('Leave application deleted successfully.')]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('LEAVE_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Authenticated candidate's personal leave balance quotas.
     */
    public function myBalances(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authenticated user context required.', 401);
        }

        $year = (int) $request->input('year', Carbon::now()->year);
        $balances = $this->leaveService->getUserLeaveBalances($user, $year);

        return $this->success([
            'year' => $year,
            'balances' => $balances,
        ]);
    }

    /**
     * Authenticated candidate's submitted leave applications feed.
     */
    public function myApplications(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authenticated user context required.', 401);
        }

        $applications = LeaveApplication::with(['category', 'approver:id,name'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return $this->success($applications);
    }

    /**
     * Authenticated user self-submits a leave application.
     */
    public function apply(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authenticated user context required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'leave_category_id' => 'required|exists:leave_categories,id',
            'from_date' => 'required|date|after_or_equal:today',
            'to_date' => 'required|date|after_or_equal:from_date',
            'is_half_day' => 'boolean',
            'half_day_type' => 'nullable|string|in:first_half,second_half',
            'reason' => 'required|string|max:1000',
            'emergency_contact' => 'nullable|string|max:50',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid application details.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();

        DB::beginTransaction();
        try {
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = 'leave_'.time().'_'.Str::random(8).'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs('leave-attachments', $filename, 'public');
                $validated['attachment_path'] = $path;
            }

            $application = $this->leaveService->applyLeave($user, $validated);

            AuditLogService::log(
                event: 'leave_application.self_submitted',
                description: "{$user->name} applied for leave ({$application->application_no}, {$application->total_days} days).",
                auditable: $application,
                newValues: $application->toArray(),
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'application' => $application->load(['category']),
                'message' => __('Your leave application has been submitted for review.'),
            ], null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Self leave application failed: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('LEAVE_APPLY_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * Authenticated candidate cancels their own pending application.
     */
    public function cancelMyApplication(string $identifier, Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authenticated user context required.', 401);
        }

        $application = is_numeric($identifier)
            ? LeaveApplication::where('user_id', $user->id)->findOrFail($identifier)
            : LeaveApplication::where('user_id', $user->id)->where('application_no', $identifier)->firstOrFail();

        if ($application->status !== LeaveApplication::STATUS_PENDING) {
            return $this->error('LEAVE_NOT_CANCELLABLE', "You can only cancel pending applications. Current status is '{$application->status}'.", 422);
        }

        DB::beginTransaction();
        try {
            $cancelled = $this->leaveService->cancelLeave($application, $user, 'Cancelled by applicant via portal.');

            AuditLogService::log(
                event: 'leave_application.self_cancelled',
                description: "{$user->name} cancelled their own leave application {$application->application_no}.",
                auditable: $cancelled,
                newValues: $cancelled->toArray(),
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'application' => $cancelled,
                'message' => __('Leave application cancelled successfully.'),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('LEAVE_CANCEL_FAILED', $e->getMessage(), 422);
        }
    }
}
