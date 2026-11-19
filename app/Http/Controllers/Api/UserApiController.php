<?php

namespace App\Http\Controllers\Api;

use App\Actions\Support\UrnGenerator;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RbacService;
use App\Services\UserExportImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class UserApiController extends Controller
{
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
     * Locate user by ID, UUID, URN, student_id, employee_id, email, or phone.
     */
    protected function findTargetUser(string $identifier, bool $withTrashed = false): ?User
    {
        $query = $withTrashed ? User::withTrashed() : User::query();

        return $query->where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('employee_id', $identifier)
                    ->orWhere('urn', $identifier)
                    ->orWhere('phone', $identifier);
            } else {
                $q->where('uuid', $identifier)
                    ->orWhere('email', $identifier)
                    ->orWhere('urn', $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('employee_id', $identifier)
                    ->orWhere('phone', $identifier);
            }
        })->first();
    }

    /**
     * Transform User model into normalized API array representation.
     *
     * @return array<string, mixed>
     */
    protected function formatUser(User $user, bool $detailed = false): array
    {
        $roles = $user->roles->map(fn (Role $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'color' => $r->color,
            'is_system' => (bool) $r->is_system,
        ])->values()->all();

        $data = [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'urn' => $user->urn,
            'student_id' => $user->student_id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_no' => $user->whatsapp_no,
            'gender' => $user->gender,
            'dob' => $user->dob?->toDateString(),
            'designation' => $user->designation,
            'staff_department' => $user->staff_department,
            'status' => $user->status,
            'is_locked' => $user->locked_untill !== null && $user->locked_untill->isFuture(),
            'locked_untill' => $user->locked_untill?->toIso8601String(),
            'failed_login_attempts' => (int) $user->failed_login_attempts,
            'email_verified' => $user->email_verified_at !== null,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'phone_verified' => $user->phone_verified_at !== null,
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'avatar_url' => $user->avatar ? url('storage/'.$user->avatar) : null,
            'primary_role' => $roles[0]['name'] ?? 'None',
            'roles' => $roles,
            'permissions_count' => $user->getAllPermissions()->count(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_login_ip' => $user->last_login_ip,
            'is_deleted' => $user->trashed(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['direct_permissions'] = $user->permissions->pluck('name')->values()->all();
            $data['all_permissions'] = $user->getAllPermissions()->pluck('name')->values()->all();
            $data['permissions'] = $data['all_permissions'];
            $data['tenth_roll'] = $user->tenth_roll;
            $data['id_type'] = $user->id_type;
            $data['id_number'] = $user->id_number;
            $data['biometric_pin'] = $user->biometric_pin;
            $data['rfid_card_no'] = $user->rfid_card_no;
            $data['current_team_id'] = $user->current_team_id;
            $data['teams'] = $user->teams->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'role' => $t->membership?->role,
            ])->values()->all();
        }

        return $data;
    }

    /**
     * 1. Executive Telemetry & Security Overview.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $now = Carbon::now();

            $totalUsers = User::count();
            $activeUsers = User::where('status', 'active')->count();
            $inactiveUsers = User::where('status', 'inactive')->count();
            $suspendedUsers = User::where('status', 'suspended')->count();
            $lockedUsers = User::where('status', 'locked')
                ->orWhere(function ($q) use ($now) {
                    $q->whereNotNull('locked_untill')->where('locked_untill', '>', $now);
                })->count();
            $trashedUsers = User::onlyTrashed()->count();

            $verifiedEmails = User::whereNotNull('email_verified_at')->count();
            $verifiedPhones = User::whereNotNull('phone_verified_at')->count();
            $mfaEnabledCount = User::whereNotNull('two_factor_confirmed_at')->count();

            // Roles distribution
            $rolesDistribution = Role::withCount('users')->get()->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'color' => $r->color,
                'is_system' => (bool) $r->is_system,
                'users_count' => $r->users_count,
            ])->values()->all();

            // 10 Recent logins
            $recentLogins = User::whereNotNull('last_login_at')
                ->orderByDesc('last_login_at')
                ->take(10)
                ->get(['id', 'name', 'email', 'last_login_at', 'last_login_ip', 'designation']);

            // 10 Recent security audit events
            $recentSecurityEvents = AuditLog::with('user:id,name,email,urn')
                ->whereIn('event', ['created', 'updated', 'deleted', 'restored', 'status_changed', 'user_unlocked', 'password_reset', 'roles_synchronized', 'login', 'failed_login'])
                ->latest()
                ->take(10)
                ->get();

            return $this->success([
                'counts' => [
                    'total' => $totalUsers,
                    'active' => $activeUsers,
                    'inactive' => $inactiveUsers,
                    'suspended' => $suspendedUsers,
                    'locked' => $lockedUsers,
                    'trashed' => $trashedUsers,
                    'email_verified' => $verifiedEmails,
                    'phone_verified' => $verifiedPhones,
                    'mfa_enabled' => $mfaEnabledCount,
                ],
                'rates' => [
                    'email_verification_pct' => $totalUsers > 0 ? round(($verifiedEmails / $totalUsers) * 100, 1) : 0,
                    'phone_verification_pct' => $totalUsers > 0 ? round(($verifiedPhones / $totalUsers) * 100, 1) : 0,
                    'mfa_adoption_pct' => $totalUsers > 0 ? round(($mfaEnabledCount / $totalUsers) * 100, 1) : 0,
                ],
                'roles_distribution' => $rolesDistribution,
                'recent_logins' => $recentLogins,
                'recent_security_events' => $recentSecurityEvents,
            ]);
        } catch (Throwable $e) {
            Log::error('Users dashboard telemetry error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('DASHBOARD_TELEMETRY_FAILED', 'Failed to retrieve users dashboard telemetry: '.$e->getMessage(), 500);
        }
    }

    /**
     * 2. Granular Users & Security Analytics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $totalUsers = User::count();

            // Gender distribution
            $genderStats = User::select('gender', DB::raw('count(*) as count'))
                ->whereNotNull('gender')
                ->where('gender', '!=', '')
                ->groupBy('gender')
                ->pluck('count', 'gender')
                ->all();

            // Status distribution
            $statusStats = User::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->all();

            // Department breakdown for staff
            $departmentStats = User::select('staff_department', DB::raw('count(*) as count'))
                ->whereNotNull('staff_department')
                ->where('staff_department', '!=', '')
                ->groupBy('staff_department')
                ->orderByDesc('count')
                ->take(10)
                ->pluck('count', 'staff_department')
                ->all();

            // User growth monthly trends (past 6 months)
            $growthTrends = [];
            for ($i = 5; $i >= 0; $i--) {
                $monthDate = Carbon::now()->subMonths($i);
                $monthKey = $monthDate->format('Y-m');
                $monthLabel = $monthDate->format('M Y');
                $count = User::whereYear('created_at', $monthDate->year)
                    ->whereMonth('created_at', $monthDate->month)
                    ->count();

                $growthTrends[] = [
                    'month_key' => $monthKey,
                    'label' => $monthLabel,
                    'new_users' => $count,
                ];
            }

            return $this->success([
                'total_users' => $totalUsers,
                'gender_distribution' => $genderStats,
                'status_distribution' => $statusStats,
                'department_distribution' => $departmentStats,
                'monthly_growth_trends' => $growthTrends,
            ]);
        } catch (Throwable $e) {
            Log::error('Users statistics error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STATISTICS_QUERY_FAILED', 'Failed to calculate users statistics: '.$e->getMessage(), 500);
        }
    }

    /**
     * 3. Paginated User Registry with Filtering.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = User::with(['roles']);

            // Trashed filter
            $trashed = $request->input('trashed', 'without_trashed');
            if ($trashed === 'with_trashed') {
                $query->withTrashed();
            } elseif ($trashed === 'only_trashed') {
                $query->onlyTrashed();
            }

            // Search query
            if ($search = trim((string) $request->input('search'))) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('urn', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%");
                });
            }

            // Role filter
            if ($role = $request->input('role')) {
                $query->whereHas('roles', function (Builder $r) use ($role) {
                    if (is_numeric($role)) {
                        $r->where('roles.id', (int) $role);
                    } else {
                        $r->where('roles.name', $role);
                    }
                });
            }

            // Status filter
            if ($status = $request->input('status')) {
                if ($status === 'locked') {
                    $query->where(function ($q) {
                        $q->where('status', 'locked')
                            ->orWhere(fn ($sq) => $sq->whereNotNull('locked_untill')->where('locked_untill', '>', Carbon::now()));
                    });
                } else {
                    $query->where('status', $status);
                }
            }

            // Gender filter
            if ($gender = $request->input('gender')) {
                $query->where('gender', $gender);
            }

            // Verification filters
            if ($verified = $request->input('verified')) {
                if ($verified === 'email') {
                    $query->whereNotNull('email_verified_at');
                } elseif ($verified === 'phone') {
                    $query->whereNotNull('phone_verified_at');
                } elseif ($verified === 'both') {
                    $query->whereNotNull('email_verified_at')->whereNotNull('phone_verified_at');
                } elseif ($verified === 'unverified') {
                    $query->whereNull('email_verified_at');
                }
            }

            // Sorting
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

            $allowedSorts = ['id', 'name', 'email', 'created_at', 'last_login_at', 'status'];
            if (in_array($sortField, $allowedSorts, true)) {
                $query->orderBy($sortField, $sortDirection);
            } else {
                $query->latest('id');
            }

            $perPage = max(1, min((int) $request->input('per_page', 15), 100));
            $paginator = $query->paginate($perPage);

            $formatted = collect($paginator->items())->map(fn (User $u) => $this->formatUser($u))->all();

            return $this->success($formatted, [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]);
        } catch (Throwable $e) {
            Log::error('Users index query error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('USERS_QUERY_FAILED', 'Failed to retrieve users: '.$e->getMessage(), 500);
        }
    }

    /**
     * 4. Register New User with Role Assignment.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'whatsapp_no' => 'nullable|string|max:25',
            'password' => 'nullable|string|min:8',
            'gender' => 'nullable|string|in:male,female,other,Male,Female,Other',
            'dob' => 'nullable|date',
            'designation' => 'nullable|string|max:150',
            'staff_department' => 'nullable|string|max:100',
            'employee_id' => 'nullable|string|max:50|unique:users,employee_id',
            'biometric_pin' => 'nullable|string|max:50|unique:users,biometric_pin',
            'rfid_card_no' => 'nullable|string|max:50|unique:users,rfid_card_no',
            'id_type' => 'nullable|string|max:50',
            'id_number' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:active,inactive,suspended',
            'roles' => 'nullable|array',
            'roles.*' => 'string',
            'send_welcome_email' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $plainPassword = ! empty($validated['password'])
                ? $validated['password']
                : Str::password(12);

            $user = new User([
                'uuid' => (string) Str::uuid(),
                'urn' => UrnGenerator::generate(),
                'name' => $validated['name'],
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'whatsapp_no' => $validated['whatsapp_no'] ?? null,
                'gender' => ! empty($validated['gender']) ? ucfirst(strtolower($validated['gender'])) : null,
                'dob' => ! empty($validated['dob']) ? Carbon::parse($validated['dob']) : null,
                'designation' => $validated['designation'] ?? null,
                'staff_department' => $validated['staff_department'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
                'biometric_pin' => $validated['biometric_pin'] ?? null,
                'rfid_card_no' => $validated['rfid_card_no'] ?? null,
                'id_type' => $validated['id_type'] ?? null,
                'id_number' => $validated['id_number'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'password' => Hash::make($plainPassword),
                'created_by' => $actor?->id,
            ]);

            $user->save();

            // Assign roles
            if (! empty($validated['roles'])) {
                $user->syncRoles($validated['roles']);
            } else {
                $user->assignRole('Student');
            }

            // Audit log
            AuditLogService::log(
                event: 'created',
                description: "Created user #{$user->id} ({$user->name}) with email {$user->email}",
                auditable: $user,
                oldValues: null,
                newValues: $user->only(['name', 'email', 'status', 'designation', 'urn']),
                userId: $actor?->id
            );

            DB::commit();

            $formatted = $this->formatUser($user->load('roles'), true);
            if (empty($validated['password'])) {
                $formatted['generated_password'] = $plainPassword;
            }

            return $this->success($formatted, ['message' => 'User created successfully.'], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User creation failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('USER_CREATION_FAILED', 'Failed to create user: '.$e->getMessage(), 500);
        }
    }

    /**
     * 5. Retrieve User 360 Dossier.
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier, withTrashed: true);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $user->load(['roles.permissions', 'permissions', 'teams']);

        return $this->success($this->formatUser($user, detailed: true));
    }

    /**
     * 6. Update User Profile Attributes.
     */
    public function update(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'sometimes|required|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20|unique:users,phone,'.$user->id,
            'whatsapp_no' => 'nullable|string|max:25',
            'gender' => 'nullable|string|in:male,female,other,Male,Female,Other',
            'dob' => 'nullable|date',
            'designation' => 'nullable|string|max:150',
            'staff_department' => 'nullable|string|max:100',
            'employee_id' => 'nullable|string|max:50|unique:users,employee_id,'.$user->id,
            'biometric_pin' => 'nullable|string|max:50|unique:users,biometric_pin,'.$user->id,
            'rfid_card_no' => 'nullable|string|max:50|unique:users,rfid_card_no,'.$user->id,
            'id_type' => 'nullable|string|max:50',
            'id_number' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:active,inactive,suspended,locked',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $oldValues = $user->only([
                'name', 'email', 'phone', 'whatsapp_no', 'designation', 'status',
                'staff_department', 'employee_id', 'biometric_pin', 'rfid_card_no',
            ]);

            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (isset($validated['first_name'])) {
                $user->first_name = $validated['first_name'];
            }
            if (isset($validated['last_name'])) {
                $user->last_name = $validated['last_name'];
            }
            if (isset($validated['email'])) {
                $user->email = strtolower($validated['email']);
            }
            if (array_key_exists('phone', $validated)) {
                $user->phone = $validated['phone'];
            }
            if (array_key_exists('whatsapp_no', $validated)) {
                $user->whatsapp_no = $validated['whatsapp_no'];
            }
            if (array_key_exists('gender', $validated)) {
                $user->gender = $validated['gender'] ? ucfirst(strtolower($validated['gender'])) : null;
            }
            if (array_key_exists('dob', $validated)) {
                $user->dob = $validated['dob'] ? Carbon::parse($validated['dob']) : null;
            }
            if (array_key_exists('designation', $validated)) {
                $user->designation = $validated['designation'];
            }
            if (array_key_exists('staff_department', $validated)) {
                $user->staff_department = $validated['staff_department'];
            }
            if (array_key_exists('employee_id', $validated)) {
                $user->employee_id = $validated['employee_id'];
            }
            if (array_key_exists('biometric_pin', $validated)) {
                $user->biometric_pin = $validated['biometric_pin'];
            }
            if (array_key_exists('rfid_card_no', $validated)) {
                $user->rfid_card_no = $validated['rfid_card_no'];
            }
            if (array_key_exists('id_type', $validated)) {
                $user->id_type = $validated['id_type'];
            }
            if (array_key_exists('id_number', $validated)) {
                $user->id_number = $validated['id_number'];
            }
            if (isset($validated['status'])) {
                $user->status = $validated['status'];
            }

            $user->updated_by = $actor?->id;
            $user->save();

            AuditLogService::log(
                event: 'updated',
                description: "Updated user #{$user->id} profile attributes",
                auditable: $user,
                oldValues: $oldValues,
                newValues: $user->getChanges(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($this->formatUser($user->fresh(['roles']), true), [
                'message' => 'User profile updated successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User update failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('USER_UPDATE_FAILED', 'Failed to update user: '.$e->getMessage(), 500);
        }
    }

    /**
     * 7. Soft Delete User with Safeguards.
     */
    public function destroy(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $actor = $this->resolveUser($request);

        // Guard 1: Cannot delete self
        if ($actor && $actor->id === $user->id) {
            return $this->error('SELF_DELETION_PROHIBITED', 'You cannot delete your own account.', 422);
        }

        // Guard 2: Cannot delete the sole Super Administrator
        if ($user->hasRole('Super Administrator')) {
            $otherSuperAdmins = User::role('Super Administrator')->where('id', '!=', $user->id)->count();
            if ($otherSuperAdmins === 0) {
                return $this->error('LAST_SUPER_ADMIN_PROTECTED', 'Cannot delete the sole active Super Administrator.', 422);
            }
        }

        DB::beginTransaction();
        try {
            $user->deleted_by = $actor?->id;
            $user->save();
            $user->delete();

            AuditLogService::log(
                event: 'deleted',
                description: "Soft-deleted user #{$user->id} ({$user->name})",
                auditable: $user,
                oldValues: $user->only(['name', 'email', 'status']),
                newValues: ['deleted_at' => Carbon::now()->toIso8601String()],
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $user->id,
                'name' => $user->name,
                'deleted' => true,
            ], ['message' => 'User soft-deleted successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User deletion error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('USER_DELETION_FAILED', 'Failed to soft delete user: '.$e->getMessage(), 500);
        }
    }

    /**
     * 8. Restore Soft-Deleted User.
     */
    public function restore(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier, withTrashed: true);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        if (! $user->trashed()) {
            return $this->error('USER_NOT_TRASHED', "User #{$user->id} is not trashed.", 422);
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $user->restore();
            $user->deleted_by = null;
            $user->save();

            AuditLogService::log(
                event: 'restored',
                description: "Restored user #{$user->id} ({$user->name})",
                auditable: $user,
                oldValues: ['trashed' => true],
                newValues: ['trashed' => false],
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($this->formatUser($user->fresh(['roles'])), [
                'message' => 'User restored successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User restoration error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('USER_RESTORATION_FAILED', 'Failed to restore user: '.$e->getMessage(), 500);
        }
    }

    /**
     * 9. Permanently Purge User Record (Super Admin Only).
     */
    public function forceDelete(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier, withTrashed: true);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $actor = $this->resolveUser($request);

        // Guard: Cannot delete self
        if ($actor && $actor->id === $user->id) {
            return $this->error('SELF_DELETION_PROHIBITED', 'You cannot purge your own account.', 422);
        }

        // Guard: Cannot purge sole Super Admin
        if ($user->hasRole('Super Administrator')) {
            $otherSuperAdmins = User::role('Super Administrator')->where('id', '!=', $user->id)->count();
            if ($otherSuperAdmins === 0) {
                return $this->error('LAST_SUPER_ADMIN_PROTECTED', 'Cannot purge the sole Super Administrator.', 422);
            }
        }

        DB::beginTransaction();
        try {
            $userId = $user->id;
            $userName = $user->name;

            $user->forceDelete();

            AuditLogService::log(
                event: 'force_deleted',
                description: "Permanently purged user #{$userId} ({$userName})",
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $userId,
                'purged' => true,
            ], ['message' => 'User permanently purged from database.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User force deletion error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('FORCE_DELETE_FAILED', 'Failed to permanently delete user: '.$e->getMessage(), 500);
        }
    }

    /**
     * 10. Update User Operational Status (Active, Inactive, Suspended, Locked).
     */
    public function updateStatus(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:active,inactive,suspended,locked',
            'reason' => 'nullable|string|max:500',
            'lock_hours' => 'nullable|integer|min:1|max:720',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $newStatus = $request->input('status');

        if ($actor && $actor->id === $user->id && in_array($newStatus, ['suspended', 'locked', 'inactive'], true)) {
            return $this->error('SELF_SUSPENSION_PROHIBITED', 'You cannot deactivate, suspend, or lock your own account.', 422);
        }

        if ($user->hasRole('Super Administrator') && in_array($newStatus, ['suspended', 'locked', 'inactive'], true)) {
            $otherActiveSuperAdmins = User::role('Super Administrator')->where('id', '!=', $user->id)->where('status', 'active')->count();
            if ($otherActiveSuperAdmins === 0) {
                return $this->error('LAST_SUPER_ADMIN_PROTECTED', 'Cannot deactivate or lock the only active Super Administrator.', 422);
            }
        }

        DB::beginTransaction();
        try {
            $oldStatus = $user->status;
            $user->status = $newStatus;

            if ($newStatus === 'locked') {
                $hours = (int) $request->input('lock_hours', 24);
                $user->locked_untill = Carbon::now()->addHours($hours);
            } else {
                $user->locked_untill = null;
                $user->failed_login_attempts = 0;
            }

            $user->updated_by = $actor?->id;
            $user->save();

            AuditLogService::log(
                event: 'status_changed',
                description: "Changed status of user #{$user->id} from {$oldStatus} to {$newStatus}".($request->input('reason') ? ": {$request->input('reason')}" : ''),
                auditable: $user,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus, 'locked_untill' => $user->locked_untill?->toIso8601String()],
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($this->formatUser($user->fresh(['roles'])), [
                'message' => "User status updated to '{$newStatus}' successfully.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User status update error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STATUS_UPDATE_FAILED', 'Failed to update user status: '.$e->getMessage(), 500);
        }
    }

    /**
     * 11. Instantly Unlock Locked Account.
     */
    public function unlock(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $user->locked_untill = null;
            $user->failed_login_attempts = 0;
            if ($user->status === 'locked') {
                $user->status = 'active';
            }
            $user->updated_by = $actor?->id;
            $user->save();

            AuditLogService::log(
                event: 'user_unlocked',
                description: "Unlocked user account #{$user->id} ({$user->name}) and reset failed attempts counter",
                auditable: $user,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($this->formatUser($user->fresh(['roles'])), [
                'message' => 'User account unlocked successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('User unlock error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('UNLOCK_FAILED', 'Failed to unlock user account: '.$e->getMessage(), 500);
        }
    }

    /**
     * 12. Administrative Password Reset.
     */
    public function resetPassword(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'password' => 'nullable|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $newPlainPassword = $request->input('password') ?: Str::password(12);

        DB::beginTransaction();
        try {
            $user->password = Hash::make($newPlainPassword);
            $user->remember_token = null;
            $user->updated_by = $actor?->id;
            $user->save();

            AuditLogService::log(
                event: 'password_reset',
                description: "Administrative password reset performed for user #{$user->id} ({$user->name})",
                auditable: $user,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'user_id' => $user->id,
                'email' => $user->email,
                'temporary_password' => $request->input('password') ? null : $newPlainPassword,
                'reset_at' => Carbon::now()->toIso8601String(),
            ], ['message' => 'Password reset successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Password reset error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PASSWORD_RESET_FAILED', 'Failed to reset user password: '.$e->getMessage(), 500);
        }
    }

    /**
     * 13. Synchronize User Roles.
     */
    public function syncRoles(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'roles' => 'required|array|min:1',
            'roles.*' => 'string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $newRoles = $validator->validated()['roles'];

        // Guard: Cannot remove sole Super Administrator
        if ($user->hasRole('Super Administrator') && ! in_array('Super Administrator', $newRoles, true)) {
            $otherSuperAdmins = User::role('Super Administrator')->where('id', '!=', $user->id)->count();
            if ($otherSuperAdmins === 0) {
                return $this->error('LAST_SUPER_ADMIN_PROTECTED', 'Cannot remove Super Administrator role from the sole Super Administrator.', 422);
            }
        }

        DB::beginTransaction();
        try {
            $oldRoles = $user->roles->pluck('name')->all();

            $user->syncRoles($newRoles);

            AuditLogService::log(
                event: 'roles_synchronized',
                description: "Synchronized roles for user #{$user->id} ({$user->name})",
                auditable: $user,
                oldValues: ['roles' => $oldRoles],
                newValues: ['roles' => $newRoles],
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($this->formatUser($user->fresh(['roles']), true), [
                'message' => 'User roles updated successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Roles synchronization error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('ROLE_SYNC_FAILED', 'Failed to synchronize roles: '.$e->getMessage(), 500);
        }
    }

    /**
     * 14. Query User Audit Logs & Security History.
     */
    public function userAuditLogs(Request $request, string $identifier): JsonResponse
    {
        $user = $this->findTargetUser($identifier, withTrashed: true);

        if (! $user) {
            return $this->error('USER_NOT_FOUND', "User with identifier '{$identifier}' not found.", 404);
        }

        $perPage = max(1, min((int) $request->input('per_page', 20), 100));

        $logs = AuditLog::where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere(function ($sq) use ($user) {
                    $sq->where('auditable_type', User::class)
                        ->where('auditable_id', $user->id);
                });
        })
            ->latest('id')
            ->paginate($perPage);

        return $this->success($logs->items(), [
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }

    /**
     * 15. Export Users Dataset.
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $query = User::with('roles')->latest('id');

            if ($search = trim((string) $request->input('search'))) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('urn', 'like', "%{$search}%");
                });
            }

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            if ($role = $request->input('role')) {
                $query->whereHas('roles', fn ($r) => $r->where('name', $role));
            }

            $users = $query->take(5000)->get()->map(fn (User $u) => $this->formatUser($u));

            return $this->success($users, [
                'total_exported' => $users->count(),
                'generated_at' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('Users export error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('EXPORT_FAILED', 'Failed to export users: '.$e->getMessage(), 500);
        }
    }

    /**
     * 16. Bulk Import Users via JSON Array or File Upload.
     */
    public function import(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);

        // Check if file uploaded
        if ($request->hasFile('file')) {
            try {
                $file = $request->file('file');
                $result = UserExportImportService::importUsers($file);

                AuditLogService::log(
                    event: 'bulk_import',
                    description: "Imported {$result['imported_count']} users via spreadsheet file upload",
                    userId: $actor?->id
                );

                return $this->success($result, ['message' => 'Users file imported successfully.']);
            } catch (Throwable $e) {
                Log::error('User spreadsheet import error: '.$e->getMessage(), ['exception' => $e]);

                return $this->error('FILE_IMPORT_FAILED', 'Failed to import spreadsheet file: '.$e->getMessage(), 422);
            }
        }

        // JSON array import
        $validator = Validator::make($request->all(), [
            'users' => 'required|array|min:1|max:500',
            'users.*.name' => 'required|string|max:255',
            'users.*.email' => 'required|email|max:255',
            'users.*.phone' => 'nullable|string|max:20',
            'users.*.gender' => 'nullable|string',
            'users.*.designation' => 'nullable|string',
            'users.*.role' => 'nullable|string',
            'users.*.password' => 'nullable|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors in import payload.', 422, $validator->errors()->toArray());
        }

        $items = $validator->validated()['users'];
        $createdCount = 0;
        $updatedCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($items as $idx => $row) {
                $email = strtolower(trim($row['email']));
                $existing = User::where('email', $email)->first();

                if ($existing) {
                    $existing->name = $row['name'];
                    if (! empty($row['phone'])) {
                        $existing->phone = $row['phone'];
                    }
                    if (! empty($row['designation'])) {
                        $existing->designation = $row['designation'];
                    }
                    if (! empty($row['gender'])) {
                        $existing->gender = ucfirst(strtolower($row['gender']));
                    }
                    $existing->updated_by = $actor?->id;
                    $existing->save();

                    if (! empty($row['role'])) {
                        $existing->syncRoles([$row['role']]);
                    }

                    $updatedCount++;
                } else {
                    $plainPw = ! empty($row['password']) ? $row['password'] : Str::password(12);

                    $user = User::create([
                        'uuid' => (string) Str::uuid(),
                        'urn' => UrnGenerator::generate(),
                        'name' => $row['name'],
                        'email' => $email,
                        'phone' => $row['phone'] ?? null,
                        'gender' => ! empty($row['gender']) ? ucfirst(strtolower($row['gender'])) : null,
                        'designation' => $row['designation'] ?? null,
                        'status' => 'active',
                        'password' => Hash::make($plainPw),
                        'created_by' => $actor?->id,
                    ]);

                    $user->assignRole($row['role'] ?? 'Student');
                    $createdCount++;
                }
            }

            AuditLogService::log(
                event: 'bulk_import',
                description: "Imported {$createdCount} new users, updated {$updatedCount} users via API",
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'errors_count' => count($errors),
            ], ['message' => "Successfully processed {$createdCount} created, {$updatedCount} updated."]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Users JSON bulk import failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('IMPORT_FAILED', 'Failed to process bulk import: '.$e->getMessage(), 500);
        }
    }

    /**
     * 17. Bulk User Status Update.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer',
            'status' => 'required|string|in:active,inactive,suspended,locked',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $ids = $validator->validated()['user_ids'];
        $newStatus = $validator->validated()['status'];

        // Exclude self if disabling
        if ($actor && in_array($newStatus, ['suspended', 'locked', 'inactive'], true)) {
            $ids = array_values(array_filter($ids, fn ($id) => $id !== $actor->id));
        }

        DB::beginTransaction();
        try {
            $affected = User::whereIn('id', $ids)->update([
                'status' => $newStatus,
                'locked_untill' => $newStatus === 'locked' ? Carbon::now()->addDays(7) : null,
                'failed_login_attempts' => $newStatus === 'active' ? 0 : DB::raw('failed_login_attempts'),
                'updated_by' => $actor?->id,
                'updated_at' => Carbon::now(),
            ]);

            AuditLogService::log(
                event: 'bulk_status_changed',
                description: "Bulk updated status to '{$newStatus}' for {$affected} users",
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'affected_count' => $affected,
                'updated_count' => $affected,
                'status' => $newStatus,
            ], ['message' => "Updated status for {$affected} users."]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Bulk status update error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('BULK_STATUS_FAILED', 'Failed to update users status: '.$e->getMessage(), 500);
        }
    }

    /**
     * 18. List Roles with Metrics.
     */
    public function rolesIndex(Request $request): JsonResponse
    {
        try {
            $roles = Role::withCount(['users', 'permissions'])
                ->when($request->input('search'), function ($q, $search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                })
                ->orderBy('is_system', 'desc')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'description' => $r->description,
                    'color' => $r->color,
                    'is_system' => (bool) $r->is_system,
                    'guard_name' => $r->guard_name,
                    'users_count' => $r->users_count,
                    'permissions_count' => $r->permissions_count,
                    'is_deletable' => ! $r->is_system && $r->users_count === 0,
                    'created_at' => $r->created_at?->toIso8601String(),
                ]);

            return $this->success($roles);
        } catch (Throwable $e) {
            Log::error('Roles index error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('ROLES_QUERY_FAILED', 'Failed to list roles: '.$e->getMessage(), 500);
        }
    }

    /**
     * 19. Create Custom Role.
     */
    public function rolesStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:roles,name',
            'description' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:30',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $role = Role::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'color' => $validated['color'] ?? 'emerald',
                'is_system' => false,
                'guard_name' => 'web',
            ]);

            if (! empty($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            AuditLogService::log(
                event: 'role_created',
                description: "Created custom role '{$role->name}'",
                auditable: $role,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'is_system' => false,
                'permissions' => $role->permissions->pluck('name')->all(),
            ], ['message' => 'Role created successfully.'], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role creation error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('ROLE_CREATION_FAILED', 'Failed to create role: '.$e->getMessage(), 500);
        }
    }

    /**
     * 20. Show Role Dossier with Assigned Permissions & Users.
     */
    public function rolesShow(Request $request, string $id): JsonResponse
    {
        $role = is_numeric($id)
            ? Role::with(['permissions', 'users:id,name,email,urn,avatar,status'])->find((int) $id)
            : Role::with(['permissions', 'users:id,name,email,urn,avatar,status'])->where('name', $id)->first();

        if (! $role) {
            return $this->error('ROLE_NOT_FOUND', "Role '{$id}' not found.", 404);
        }

        $groupedPermissions = $role->permissions
            ->groupBy('module')
            ->map(fn ($perms) => $perms->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
            ]))->all();

        return $this->success([
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'color' => $role->color,
            'is_system' => (bool) $role->is_system,
            'guard_name' => $role->guard_name,
            'users_count' => $role->users->count(),
            'users' => $role->users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'urn' => $u->urn,
                'status' => $u->status,
            ])->values()->all(),
            'permissions_count' => $role->permissions->count(),
            'permissions_grouped' => $groupedPermissions,
            'permissions_flat' => $role->permissions->pluck('name')->all(),
        ]);
    }

    /**
     * 21. Update Role.
     */
    public function rolesUpdate(Request $request, string $id): JsonResponse
    {
        $role = is_numeric($id) ? Role::find((int) $id) : Role::where('name', $id)->first();

        if (! $role) {
            return $this->error('ROLE_NOT_FOUND', "Role '{$id}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100|unique:roles,name,'.$role->id,
            'description' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:30',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $actor = $this->resolveUser($request);

        // Guard: Prevent renaming core system roles
        if ($role->is_system && isset($validated['name']) && $validated['name'] !== $role->name) {
            return $this->error('SYSTEM_ROLE_RENAME_BLOCKED', 'System role names are protected and cannot be changed.', 422);
        }

        DB::beginTransaction();
        try {
            if (! $role->is_system && isset($validated['name'])) {
                $role->name = $validated['name'];
            }
            if (array_key_exists('description', $validated)) {
                $role->description = $validated['description'];
            }
            if (! empty($validated['color'])) {
                $role->color = $validated['color'];
            }

            $role->save();

            if (isset($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            AuditLogService::log(
                event: 'role_updated',
                description: "Updated role '{$role->name}'",
                auditable: $role,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'is_system' => (bool) $role->is_system,
                'permissions_count' => $role->permissions()->count(),
            ], ['message' => 'Role updated successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role update error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('ROLE_UPDATE_FAILED', 'Failed to update role: '.$e->getMessage(), 500);
        }
    }

    /**
     * 22. Delete Custom Role.
     */
    public function rolesDestroy(Request $request, string $id): JsonResponse
    {
        $role = is_numeric($id) ? Role::find((int) $id) : Role::where('name', $id)->first();

        if (! $role) {
            return $this->error('ROLE_NOT_FOUND', "Role '{$id}' not found.", 404);
        }

        if ($role->is_system) {
            return $this->error('SYSTEM_ROLE_PROTECTED', "System role '{$role->name}' is protected and cannot be deleted.", 422);
        }

        $usersCount = $role->users()->count();
        if ($usersCount > 0) {
            return $this->error('ROLE_IN_USE', "Cannot delete role '{$role->name}' because it is assigned to {$usersCount} active user(s).", 422);
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $roleName = $role->name;
            $role->delete();

            AuditLogService::log(
                event: 'role_deleted',
                description: "Deleted custom role '{$roleName}'",
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(['deleted' => true, 'name' => $roleName], ['message' => 'Role deleted successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role deletion error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('ROLE_DELETE_FAILED', 'Failed to delete role: '.$e->getMessage(), 500);
        }
    }

    /**
     * 23. Clone Existing Role.
     */
    public function rolesClone(Request $request, string $id): JsonResponse
    {
        $sourceRole = is_numeric($id) ? Role::find((int) $id) : Role::where('name', $id)->first();

        if (! $sourceRole) {
            return $this->error('ROLE_NOT_FOUND', "Source role '{$id}' not found.", 404);
        }

        $name = $request->input('name') ?? $request->input('new_name');
        $validator = Validator::make(array_merge($request->all(), ['name' => $name]), [
            'name' => 'required|string|max:100|unique:roles,name',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $newName = $validator->validated()['name'];
        $newDescription = $validator->validated()['description'] ?? "Cloned from {$sourceRole->name}";

        DB::beginTransaction();
        try {
            $cloned = RbacService::cloneRole($sourceRole, $newName, $newDescription);

            AuditLogService::log(
                event: 'role_cloned',
                description: "Cloned role '{$sourceRole->name}' to '{$cloned->name}'",
                auditable: $cloned,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $cloned->id,
                'name' => $cloned->name,
                'description' => $cloned->description,
                'color' => $cloned->color,
                'permissions_count' => $cloned->permissions()->count(),
            ], ['message' => 'Role cloned successfully.'], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role clone error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('ROLE_CLONE_FAILED', 'Failed to clone role: '.$e->getMessage(), 500);
        }
    }

    /**
     * 24. Synchronize Role Permissions.
     */
    public function rolesSyncPermissions(Request $request, string $id): JsonResponse
    {
        $role = is_numeric($id) ? Role::find((int) $id) : Role::where('name', $id)->first();

        if (! $role) {
            return $this->error('ROLE_NOT_FOUND', "Role '{$id}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $permissions = $validator->validated()['permissions'];

        DB::beginTransaction();
        try {
            $role->syncPermissions($permissions);

            AuditLogService::log(
                event: 'role_permissions_synchronized',
                description: "Synchronized permissions for role '{$role->name}' ({$role->permissions()->count()} assigned)",
                auditable: $role,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $role->id,
                'name' => $role->name,
                'permissions_count' => $role->permissions()->count(),
                'permissions' => $role->permissions->pluck('name')->all(),
            ], ['message' => 'Role permissions synchronized successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Role permissions sync error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PERMISSIONS_SYNC_FAILED', 'Failed to synchronize permissions: '.$e->getMessage(), 500);
        }
    }

    /**
     * 25. List Permissions.
     */
    public function permissionsIndex(Request $request): JsonResponse
    {
        try {
            $query = Permission::query();

            if ($module = $request->input('module')) {
                $query->where('module', $module);
            }

            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('module', 'like', "%{$search}%");
                });
            }

            $permissions = $query->orderBy('module')->orderBy('name')->get();

            if ($request->boolean('grouped')) {
                $grouped = $permissions->groupBy('module')->map(fn ($p) => $p->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'module' => $item->module,
                    'description' => $item->description,
                ]))->all();

                return $this->success($grouped);
            }

            return $this->success($permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'module' => $p->module,
                'description' => $p->description,
            ]));
        } catch (Throwable $e) {
            Log::error('Permissions index error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PERMISSIONS_QUERY_FAILED', 'Failed to retrieve permissions: '.$e->getMessage(), 500);
        }
    }

    /**
     * 26. Distinct Permission Modules Catalog.
     */
    public function permissionsModules(Request $request): JsonResponse
    {
        try {
            $modules = Permission::select('module', DB::raw('count(*) as count'))
                ->groupBy('module')
                ->orderBy('module')
                ->get()
                ->map(fn ($m) => [
                    'module' => $m->module,
                    'permissions_count' => $m->count,
                ]);

            return $this->success($modules, [
                'total_modules' => $modules->count(),
                'total_permissions' => (int) $modules->sum('permissions_count'),
            ]);
        } catch (Throwable $e) {
            Log::error('Permission modules query error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('MODULES_QUERY_FAILED', 'Failed to retrieve permission modules: '.$e->getMessage(), 500);
        }
    }

    /**
     * 27. Create Custom Permission.
     */
    public function permissionsStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:permissions,name',
            'module' => 'required|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $validated = $validator->validated();

        DB::beginTransaction();
        try {
            $perm = Permission::create([
                'name' => $validated['name'],
                'module' => $validated['module'],
                'description' => $validated['description'] ?? null,
                'guard_name' => 'web',
            ]);

            AuditLogService::log(
                event: 'permission_created',
                description: "Created security permission '{$perm->name}' under module '{$perm->module}'",
                auditable: $perm,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $perm->id,
                'name' => $perm->name,
                'module' => $perm->module,
                'description' => $perm->description,
            ], ['message' => 'Permission created successfully.'], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Permission create error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PERMISSION_CREATION_FAILED', 'Failed to create permission: '.$e->getMessage(), 500);
        }
    }

    /**
     * 28. Update Custom Permission.
     */
    public function permissionsUpdate(Request $request, string $id): JsonResponse
    {
        $perm = is_numeric($id) ? Permission::find((int) $id) : Permission::where('name', $id)->first();

        if (! $perm) {
            return $this->error('PERMISSION_NOT_FOUND', "Permission '{$id}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'module' => 'sometimes|required|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $validated = $validator->validated();

        DB::beginTransaction();
        try {
            if (isset($validated['module'])) {
                $perm->module = $validated['module'];
            }
            if (array_key_exists('description', $validated)) {
                $perm->description = $validated['description'];
            }

            $perm->save();

            AuditLogService::log(
                event: 'permission_updated',
                description: "Updated permission '{$perm->name}'",
                auditable: $perm,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $perm->id,
                'name' => $perm->name,
                'module' => $perm->module,
                'description' => $perm->description,
            ], ['message' => 'Permission updated successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Permission update error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PERMISSION_UPDATE_FAILED', 'Failed to update permission: '.$e->getMessage(), 500);
        }
    }

    /**
     * 29. Delete Custom Permission.
     */
    public function permissionsDestroy(Request $request, string $id): JsonResponse
    {
        $perm = is_numeric($id) ? Permission::find((int) $id) : Permission::where('name', $id)->first();

        if (! $perm) {
            return $this->error('PERMISSION_NOT_FOUND', "Permission '{$id}' not found.", 404);
        }

        // Check if assigned to any role
        $rolesCount = $perm->roles()->count();
        if ($rolesCount > 0) {
            return $this->error('PERMISSION_IN_USE', "Cannot delete permission '{$perm->name}' because it is assigned to {$rolesCount} role(s). Remove it from roles first.", 422);
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $name = $perm->name;
            $perm->delete();

            AuditLogService::log(
                event: 'permission_deleted',
                description: "Deleted security permission '{$name}'",
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(['deleted' => true, 'name' => $name], ['message' => 'Permission deleted successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Permission deletion error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PERMISSION_DELETE_FAILED', 'Failed to delete permission: '.$e->getMessage(), 500);
        }
    }

    /**
     * 30. Authenticated User Self Profile (Me).
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'No authenticated user session found.', 401);
        }

        $user->load(['roles.permissions', 'permissions', 'teams']);

        return $this->success($this->formatUser($user, detailed: true));
    }

    /**
     * 31. Authenticated User Self Profile Update.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'No authenticated user session found.', 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20|unique:users,phone,'.$user->id,
            'whatsapp_no' => 'nullable|string|max:25',
            'gender' => 'nullable|string|in:male,female,other,Male,Female,Other',
            'dob' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();

        DB::beginTransaction();
        try {
            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (isset($validated['first_name'])) {
                $user->first_name = $validated['first_name'];
            }
            if (isset($validated['last_name'])) {
                $user->last_name = $validated['last_name'];
            }
            if (array_key_exists('phone', $validated)) {
                $user->phone = $validated['phone'];
            }
            if (array_key_exists('whatsapp_no', $validated)) {
                $user->whatsapp_no = $validated['whatsapp_no'];
            }
            if (array_key_exists('gender', $validated)) {
                $user->gender = $validated['gender'] ? ucfirst(strtolower($validated['gender'])) : null;
            }
            if (array_key_exists('dob', $validated)) {
                $user->dob = $validated['dob'] ? Carbon::parse($validated['dob']) : null;
            }

            $user->save();

            AuditLogService::log(
                event: 'profile_updated',
                description: "User #{$user->id} updated their self-service profile",
                auditable: $user,
                userId: $user->id
            );

            DB::commit();

            return $this->success($this->formatUser($user->fresh(['roles']), true), [
                'message' => 'Profile updated successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Self profile update error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PROFILE_UPDATE_FAILED', 'Failed to update profile: '.$e->getMessage(), 500);
        }
    }

    /**
     * 32. Authenticated User Password Change.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'No authenticated user session found.', 401);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|different:current_password',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation errors occurred.', 422, $validator->errors()->toArray());
        }

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return $this->error('INVALID_CURRENT_PASSWORD', 'The provided current password does not match.', 422);
        }

        DB::beginTransaction();
        try {
            $user->password = Hash::make($request->input('new_password'));
            $user->remember_token = null;
            $user->save();

            AuditLogService::log(
                event: 'password_changed',
                description: "User #{$user->id} changed their password via self-service API",
                auditable: $user,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'user_id' => $user->id,
                'changed_at' => Carbon::now()->toIso8601String(),
            ], ['message' => 'Password changed successfully.']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Self password change error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PASSWORD_CHANGE_FAILED', 'Failed to change password: '.$e->getMessage(), 500);
        }
    }

    /**
     * 33. System-Wide Security Audit Logs Feed.
     */
    public function securityAuditLogs(Request $request): JsonResponse
    {
        try {
            $query = AuditLog::with('user:id,name,email,urn,avatar');

            if ($event = $request->input('event')) {
                $query->where('event', $event);
            }

            if ($userId = $request->input('user_id')) {
                $query->where('user_id', $userId);
            }

            if ($dateFrom = $request->input('date_from')) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo = $request->input('date_to')) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%");
                });
            }

            $perPage = max(1, min((int) $request->input('per_page', 25), 100));
            $logs = $query->latest('id')->paginate($perPage);

            return $this->success($logs->items(), [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ]);
        } catch (Throwable $e) {
            Log::error('Security audit logs error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('AUDIT_QUERY_FAILED', 'Failed to retrieve audit logs: '.$e->getMessage(), 500);
        }
    }
}
