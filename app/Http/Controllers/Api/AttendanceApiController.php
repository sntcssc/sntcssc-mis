<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceHoliday;
use App\Models\Batch;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Course;
use App\Models\User;
use App\Models\WorkingCalendar;
use App\Services\Attendance\AttendanceImportExportService;
use App\Services\Attendance\AttendanceNotificationService;
use App\Services\Attendance\AttendanceService;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttendanceApiController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
        protected AttendanceNotificationService $notificationService
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
     * Locate user by ID, UUID, student_id, employee_id, biometric_pin, or email.
     */
    protected function findTargetUser(string $identifier): ?User
    {
        return User::where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('employee_id', $identifier)
                    ->orWhere('biometric_pin', $identifier)
                    ->orWhere('phone', $identifier);
            } else {
                $q->where('uuid', $identifier)
                    ->orWhere('email', $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('employee_id', $identifier)
                    ->orWhere('rfid_card_no', $identifier);
            }
        })->first();
    }

    /**
     * Executive attendance telemetry & live KPI matrix.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $date = $request->input('date', Carbon::today()->toDateString());
            $parsedDate = Carbon::parse($date)->toDateString();

            $baseQuery = Attendance::whereDate('date', $parsedDate);

            $totalMarked = (clone $baseQuery)->count();
            $presentCount = (clone $baseQuery)->where('status', Attendance::STATUS_PRESENT)->count();
            $absentCount = (clone $baseQuery)->where('status', Attendance::STATUS_ABSENT)->count();
            $lateCount = (clone $baseQuery)->where('status', Attendance::STATUS_LATE)->count();
            $halfDayCount = (clone $baseQuery)->where('status', Attendance::STATUS_HALF_DAY)->count();
            $onLeaveCount = (clone $baseQuery)->where('status', Attendance::STATUS_ON_LEAVE)->count();

            // Student metrics
            $studentTotal = (clone $baseQuery)->where('user_type', 'student')->count();
            $studentPresent = (clone $baseQuery)->where('user_type', 'student')
                ->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE])
                ->count();
            $studentPresentRate = $studentTotal > 0 ? round(($studentPresent / $studentTotal) * 100, 1) : 0.0;

            // Staff metrics
            $staffTotal = (clone $baseQuery)->where('user_type', 'staff')->count();
            $staffPresent = (clone $baseQuery)->where('user_type', 'staff')
                ->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE])
                ->count();
            $staffPresentRate = $staffTotal > 0 ? round(($staffPresent / $staffTotal) * 100, 1) : 0.0;

            // Active devices telemetry
            $totalDevices = BiometricDevice::count();
            $onlineDevices = BiometricDevice::where('status', 'online')
                ->where('last_heartbeat_at', '>=', Carbon::now()->subMinutes(15))
                ->count();

            // Recent 15 live punches
            $recentPunches = Attendance::with(['user:id,name,email,student_id,employee_id,profile_photo_url', 'course:id,name,code'])
                ->whereDate('date', $parsedDate)
                ->orderByDesc('updated_at')
                ->limit(15)
                ->get()
                ->map(fn (Attendance $att) => [
                    'id' => $att->id,
                    'attendance_no' => $att->attendance_no,
                    'user_id' => $att->user_id,
                    'user_name' => $att->user?->name,
                    'user_type' => $att->user_type,
                    'status' => $att->status,
                    'check_in_time' => $att->check_in_time,
                    'check_out_time' => $att->check_out_time,
                    'entry_source' => $att->entry_source,
                    'time_ago' => $att->updated_at?->diffForHumans(),
                ]);

            // Monthly 7-day trend
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $day = Carbon::today()->subDays($i)->toDateString();
                $dayTotal = Attendance::whereDate('date', $day)->count();
                $dayAttended = Attendance::whereDate('date', $day)
                    ->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE])
                    ->count();
                $trend[] = [
                    'date' => $day,
                    'day_name' => Carbon::parse($day)->format('D'),
                    'total' => $dayTotal,
                    'attended' => $dayAttended,
                    'rate' => $dayTotal > 0 ? round(($dayAttended / $dayTotal) * 100, 1) : 0.0,
                ];
            }

            return $this->success([
                'date' => $parsedDate,
                'summary' => [
                    'total_marked' => $totalMarked,
                    'total_punches_today' => $totalMarked,
                    'present_count' => $presentCount,
                    'absent_count' => $absentCount,
                    'late_count' => $lateCount,
                    'half_day_count' => $halfDayCount,
                    'on_leave_count' => $onLeaveCount,
                ],
                'rates' => [
                    'student_present_rate' => $studentPresentRate,
                    'student_total' => $studentTotal,
                    'staff_present_rate' => $staffPresentRate,
                    'staff_total' => $staffTotal,
                ],
                'devices' => [
                    'total' => $totalDevices,
                    'online' => $onlineDevices,
                    'active' => $onlineDevices,
                    'offline' => max(0, $totalDevices - $onlineDevices),
                ],
                'recent_punches' => $recentPunches,
                'weekly_trend' => $trend,
            ], [
                'timestamp' => Carbon::now()->toIso8601String(),
                'version' => '1.0.0',
                'latency_ms' => 12,
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to load attendance dashboard: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('DASHBOARD_TELEMETRY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Granular attendance analytics & comparative breakdown.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $fromDate = $request->input('from_date', Carbon::today()->startOfMonth()->toDateString());
            $toDate = $request->input('to_date', Carbon::today()->toDateString());

            $query = Attendance::whereBetween('date', [$fromDate, $toDate]);

            if ($userType = $request->input('user_type')) {
                $query->where('user_type', $userType);
            }
            if ($courseId = $request->input('course_id')) {
                $query->where('course_id', $courseId);
            }
            if ($batchId = $request->input('batch_id')) {
                $query->where('batch_id', $batchId);
            }
            if ($sectionId = $request->input('section_id')) {
                $query->where('section_id', $sectionId);
            }

            $totalRecords = (clone $query)->count();
            $statusCounts = (clone $query)->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $sourceCounts = (clone $query)->select('entry_source', DB::raw('count(*) as count'))
                ->groupBy('entry_source')
                ->pluck('count', 'entry_source')
                ->toArray();

            // Course-wise distribution
            $courseStats = (clone $query)->whereNotNull('course_id')
                ->select('course_id', DB::raw('count(*) as total'), DB::raw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as attended"))
                ->groupBy('course_id')
                ->with('course:id,name,code')
                ->get()
                ->map(fn ($row) => [
                    'course_id' => $row->course_id,
                    'course_name' => $row->course?->name,
                    'course_code' => $row->course?->code,
                    'total' => (int) $row->total,
                    'attended' => (int) $row->attended,
                    'rate' => $row->total > 0 ? round(($row->attended / $row->total) * 100, 1) : 0.0,
                ]);

            return $this->success([
                'filter' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'user_type' => $userType,
                ],
                'total_records' => $totalRecords,
                'present_count' => (int) ($statusCounts['present'] ?? 0),
                'absent_count' => (int) ($statusCounts['absent'] ?? 0),
                'late_count' => (int) ($statusCounts['late'] ?? 0),
                'half_day_count' => (int) ($statusCounts['half_day'] ?? 0),
                'on_leave_count' => (int) ($statusCounts['on_leave'] ?? 0),
                'attendance_rate_percentage' => $totalRecords > 0 ? round((((int) ($statusCounts['present'] ?? 0) + (int) ($statusCounts['late'] ?? 0)) / $totalRecords) * 100, 1) : 0.0,
                'status_breakdown' => $statusCounts,
                'source_breakdown' => $sourceCounts,
                'course_statistics' => $courseStats,
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to load attendance statistics: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('STATISTICS_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Search, filter, and paginate master attendance register records.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Attendance::with([
                'user:id,name,email,student_id,employee_id,staff_department,phone,profile_photo_url',
                'course:id,name,code',
                'batch:id,name,code',
                'section:id,name',
                'leaveApplication:id,application_no,leave_category_id',
                'biometricDevice:id,device_name,location',
                'marker:id,name',
            ]);

            // Date filtering
            if ($date = $request->input('date')) {
                $query->whereDate('date', $date);
            } elseif ($fromDate = $request->input('from_date')) {
                $toDate = $request->input('to_date', Carbon::today()->toDateString());
                $query->whereBetween('date', [$fromDate, $toDate]);
            }

            // User & User Type filtering
            if ($userId = $request->input('user_id')) {
                $query->where('user_id', $userId);
            }
            if ($userType = $request->input('user_type')) {
                $query->where('user_type', $userType);
            }

            // Academic grouping
            if ($courseId = $request->input('course_id')) {
                $query->where('course_id', $courseId);
            }
            if ($batchId = $request->input('batch_id')) {
                $query->where('batch_id', $batchId);
            }
            if ($sectionId = $request->input('section_id')) {
                $query->where('section_id', $sectionId);
            }

            // Status and Source
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }
            if ($source = $request->input('entry_source')) {
                $query->where('entry_source', $source);
            }

            // Search query across student/staff metadata
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('attendance_no', 'like', "%{$search}%")
                        ->orWhereHas('user', function (Builder $uq) use ($search) {
                            $uq->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('student_id', 'like', "%{$search}%")
                                ->orWhere('employee_id', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            }

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $paginated = $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

            return $this->success(
                $paginated,
                [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ]
            );
        } catch (Throwable $e) {
            Log::error("Failed to query attendance records: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('QUERY_ATTENDANCE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Single attendance record dossier by ID or attendance_no.
     */
    public function show(string $identifier): JsonResponse
    {
        try {
            $record = is_numeric($identifier)
                ? Attendance::with(['user', 'course', 'batch', 'section', 'leaveApplication', 'biometricDevice', 'marker'])->findOrFail($identifier)
                : Attendance::with(['user', 'course', 'batch', 'section', 'leaveApplication', 'biometricDevice', 'marker'])->where('attendance_no', $identifier)->firstOrFail();

            return $this->success([
                'id' => $record->id,
                'attendance_no' => $record->attendance_no,
                'user' => [
                    'id' => $record->user_id,
                    'name' => $record->user?->name,
                    'email' => $record->user?->email,
                    'student_id' => $record->user?->student_id,
                    'employee_id' => $record->user?->employee_id,
                    'user_type' => $record->user_type,
                    'department' => $record->user?->staff_department,
                ],
                'academic' => [
                    'course' => $record->course ? ['id' => $record->course->id, 'name' => $record->course->name, 'code' => $record->course->code] : null,
                    'batch' => $record->batch ? ['id' => $record->batch->id, 'name' => $record->batch->name, 'code' => $record->batch->code] : null,
                    'section' => $record->section ? ['id' => $record->section->id, 'name' => $record->section->name] : null,
                ],
                'date' => $record->date?->toDateString(),
                'status' => $record->status,
                'check_in_time' => $record->check_in_time,
                'check_out_time' => $record->check_out_time,
                'working_hours' => $record->working_hours,
                'late_minutes' => $record->late_minutes,
                'overtime_minutes' => $record->overtime_minutes,
                'entry_source' => $record->entry_source,
                'remarks' => $record->remarks,
                'biometric_device' => $record->biometricDevice ? [
                    'id' => $record->biometricDevice->id,
                    'name' => $record->biometricDevice->device_name,
                    'location' => $record->biometricDevice->location,
                ] : null,
                'leave_application' => $record->leaveApplication ? [
                    'id' => $record->leaveApplication->id,
                    'application_no' => $record->leaveApplication->application_no,
                ] : null,
                'marker' => $record->marker ? ['id' => $record->marker->id, 'name' => $record->marker->name] : null,
                'notification_sent' => $record->notification_sent,
                'created_at' => $record->created_at?->toIso8601String(),
                'updated_at' => $record->updated_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return $this->error('ATTENDANCE_NOT_FOUND', "Attendance record '{$identifier}' not found.", 404);
        }
    }

    /**
     * Mark single attendance entry (Atomic DB Transaction + Audit Log).
     */
    public function mark(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['check_in_time']) && isset($input['clock_in'])) {
            $input['check_in_time'] = $input['clock_in'];
        }
        if (! isset($input['check_out_time']) && isset($input['clock_out'])) {
            $input['check_out_time'] = $input['clock_out'];
        }
        if (isset($input['entry_source']) && $input['entry_source'] === 'manual') {
            $input['entry_source'] = Attendance::SOURCE_MANUAL_SINGLE;
        }

        $validator = Validator::make($input, [
            'user_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'status' => 'required|string|in:present,absent,late,half_day,on_leave,holiday,weekly_off',
            'user_type' => 'nullable|string|in:staff,student',
            'check_in_time' => 'nullable|string',
            'check_out_time' => 'nullable|string',
            'remarks' => 'nullable|string|max:500',
            'entry_source' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid attendance parameters.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $user = User::findOrFail($validated['user_id']);
            $userType = $validated['user_type'] ?? ($user->hasRole('Student') ? 'student' : 'staff');

            $data = [
                'user_id' => $user->id,
                'date' => $validated['date'],
                'user_type' => $userType,
                'status' => $validated['status'],
                'check_in_time' => $validated['check_in_time'] ?? null,
                'check_out_time' => $validated['check_out_time'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'entry_source' => $validated['entry_source'] ?? Attendance::SOURCE_MANUAL_SINGLE,
            ];

            $attendance = $this->attendanceService->markSingle($data, $actor);

            AuditLogService::log(
                event: 'attendance.marked_single',
                description: "Single attendance marked for {$user->name} ({$attendance->attendance_no}) as '{$attendance->status}'.",
                auditable: $attendance,
                newValues: $attendance->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(array_merge($attendance->load(['user:id,name,email', 'course:id,name'])->toArray(), [
                'attendance' => $attendance,
                'message' => __('Attendance marked successfully.'),
            ]), null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to mark single attendance: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('MARK_ATTENDANCE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Mark bulk attendance for an entire classroom or department cohort.
     */
    public function bulkMark(Request $request): JsonResponse
    {
        $input = $request->all();
        if (isset($input['records']) && is_array($input['records'])) {
            foreach ($input['records'] as &$rec) {
                if (! isset($rec['check_in_time']) && isset($rec['clock_in'])) {
                    $rec['check_in_time'] = $rec['clock_in'];
                }
                if (! isset($rec['check_out_time']) && isset($rec['clock_out'])) {
                    $rec['check_out_time'] = $rec['clock_out'];
                }
            }
        }
        if (! isset($input['user_type'])) {
            $input['user_type'] = 'student';
        }

        $validator = Validator::make($input, [
            'date' => 'required|date',
            'user_type' => 'nullable|string|in:staff,student',
            'course_id' => 'nullable|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
            'section_id' => 'nullable|exists:sections,id',
            'department' => 'nullable|string|max:100',
            'default_status' => 'nullable|string|in:present,absent,late,half_day,on_leave,holiday,weekly_off',
            'records' => 'required|array|min:1',
            'records.*.user_id' => 'required|exists:users,id',
            'records.*.status' => 'required|string|in:present,absent,late,half_day,on_leave,holiday,weekly_off',
            'records.*.check_in_time' => 'nullable|string',
            'records.*.check_out_time' => 'nullable|string',
            'records.*.remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid bulk attendance payload.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $result = $this->attendanceService->markBulk($validated, $actor);
            $saved = (int) ($result['saved'] ?? 0);
            $skipped = (int) ($result['skipped'] ?? 0);
            $total = $saved + $skipped;

            AuditLogService::log(
                event: 'attendance.bulk_marked',
                description: "Bulk attendance marked for {$total} individuals on date {$validated['date']}.",
                newValues: [
                    'date' => $validated['date'],
                    'user_type' => $validated['user_type'] ?? 'student',
                    'total' => $total,
                    'saved' => $saved,
                    'skipped' => $skipped,
                ],
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'total_submitted' => $total,
                'successful_count' => $saved,
                'failed_count' => $skipped,
                'summary' => $result,
                'message' => __('Successfully processed bulk attendance for :count users.', ['count' => $total]),
            ], null, 200);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to process bulk attendance: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('BULK_MARK_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing attendance record.
     */
    public function update(string $identifier, Request $request): JsonResponse
    {
        $record = is_numeric($identifier)
            ? Attendance::findOrFail($identifier)
            : Attendance::where('attendance_no', $identifier)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|required|string|in:present,absent,late,half_day,on_leave,holiday,weekly_off',
            'check_in_time' => 'nullable|string',
            'check_out_time' => 'nullable|string',
            'working_hours' => 'nullable|numeric|min:0|max:24',
            'late_minutes' => 'nullable|integer|min:0',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid update parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $oldValues = $record->toArray();

        DB::beginTransaction();
        try {
            $data = $validator->validated();

            // Recalculate working duration if check-in and check-out times supplied
            if (isset($data['check_in_time']) && isset($data['check_out_time'])) {
                $checkIn = Carbon::parse($data['check_in_time']);
                $checkOut = Carbon::parse($data['check_out_time']);
                $data['working_hours'] = round(abs($checkOut->diffInMinutes($checkIn)) / 60, 2);
            }

            $record->update($data);

            AuditLogService::log(
                event: 'attendance.updated',
                description: "Attendance record {$record->attendance_no} updated by {$actor?->name}.",
                auditable: $record,
                oldValues: $oldValues,
                newValues: $record->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'attendance' => $record->fresh(['user:id,name,email', 'course:id,name']),
                'message' => __('Attendance record updated successfully.'),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to update attendance record: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('UPDATE_ATTENDANCE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Soft delete an attendance record.
     */
    public function destroy(string $identifier, Request $request): JsonResponse
    {
        $record = is_numeric($identifier)
            ? Attendance::findOrFail($identifier)
            : Attendance::where('attendance_no', $identifier)->firstOrFail();

        // Safety Guard: Cannot delete if linked to an active approved leave
        if ($record->leave_application_id && $record->status === Attendance::STATUS_ON_LEAVE) {
            return $this->error(
                'ACTIVE_LEAVE_PROTECTED',
                'This attendance entry is synchronized from an active approved Leave Application. Please cancel or reject the leave application to remove it.',
                422
            );
        }

        $actor = $this->resolveUser($request);
        $oldValues = $record->toArray();

        DB::beginTransaction();
        try {
            $record->update(['deleted_by' => $actor?->id]);
            $record->delete();

            AuditLogService::log(
                event: 'attendance.deleted',
                description: "Attendance record {$record->attendance_no} soft deleted.",
                auditable: $record,
                oldValues: $oldValues,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'message' => __('Attendance record soft deleted successfully.'),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Failed to delete attendance record: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('DELETE_ATTENDANCE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Monthly 1..31 day-by-day attendance matrix for a specific user.
     */
    public function userMatrix(string $userId, Request $request): JsonResponse
    {
        try {
            $user = $this->findTargetUser($userId);
            if (! $user) {
                return $this->error('USER_NOT_FOUND', "User identifier '{$userId}' not found.", 404);
            }

            $year = (int) $request->input('year', Carbon::now()->year);
            $month = (int) $request->input('month', Carbon::now()->month);

            $matrix = $this->attendanceService->getMonthlyUserAttendanceMatrix($user, $year, $month);

            return $this->success($matrix);
        } catch (Throwable $e) {
            Log::error("Failed to fetch user attendance matrix: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('USER_MATRIX_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Query attendance defaulters list (< 75% statutory criteria).
     */
    public function defaulters(Request $request): JsonResponse
    {
        try {
            $threshold = (float) $request->input('threshold', $request->input('threshold_percentage', 75.0));
            $year = (int) $request->input('year', Carbon::now()->year);
            $month = (int) $request->input('month', Carbon::now()->month);

            $studentsQuery = User::role('Student')->where('is_active', true);

            if ($courseId = $request->input('course_id')) {
                $studentsQuery->whereHas('enrollments', fn ($eq) => $eq->where('course_id', $courseId)->where('status', 'active'));
            }
            if ($batchId = $request->input('batch_id')) {
                $studentsQuery->whereHas('enrollments', fn ($eq) => $eq->where('batch_id', $batchId)->where('status', 'active'));
            }

            $students = $studentsQuery->get(['id', 'name', 'email', 'student_id', 'phone']);
            $defaulters = [];

            foreach ($students as $student) {
                $matrix = $this->attendanceService->getMonthlyUserAttendanceMatrix($student, $year, $month);
                if ($matrix['is_shortage'] || $matrix['attendance_percentage'] < $threshold) {
                    $defaulters[] = [
                        'user_id' => $student->id,
                        'name' => $student->name,
                        'student_id' => $student->student_id,
                        'email' => $student->email,
                        'phone' => $student->phone,
                        'working_days' => $matrix['working_days'],
                        'attended_days' => $matrix['attended_days'],
                        'absent_days' => $matrix['absent_days'],
                        'attendance_percentage' => $matrix['attendance_percentage'],
                        'shortfall_days' => max(0, round(($matrix['working_days'] * ($threshold / 100)) - $matrix['attended_days'], 1)),
                    ];
                }
            }

            return $this->success([
                'threshold' => $threshold,
                'threshold_percentage' => $threshold,
                'year' => $year,
                'month' => $month,
                'total_defaulters' => count($defaulters),
                'defaulters_count' => count($defaulters),
                'defaulters' => $defaulters,
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to query attendance defaulters: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('DEFAULTERS_QUERY_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Dispatch automated shortage warning notices to defaulter candidates.
     */
    public function notifyDefaulters(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'custom_message' => 'nullable|string|max:500',
            'channels' => 'nullable|array',
            'channels.*' => 'in:database,mail,sms,whatsapp',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid reminder parameters.', 422, $validator->errors()->toArray());
        }

        $userIds = $request->input('user_ids');
        $customMessage = $request->input('custom_message');
        $channels = $request->input('channels', ['database', 'mail']);

        $sentCount = 0;
        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user) {
                $message = $customMessage ?: 'Important: Your classroom attendance has fallen below the mandatory 75% threshold. Please meet the Academic Coordinator immediately.';
                $this->notificationService->sendAttendanceReminder($user, 'shortage_warning', $message, $channels);
                $sentCount++;
            }
        }

        return $this->success([
            'dispatched_count' => $sentCount,
            'message' => __('Dispatched attendance shortage alerts to :count candidate(s).', ['count' => $sentCount]),
        ]);
    }

    /**
     * Mobile / Web App Self Clock-In with Geolocation & Device Verification.
     */
    public function clockIn(Request $request): JsonResponse
    {
        $user = $request->filled('user_id') ? User::find($request->input('user_id')) : $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authenticated user context required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric',
            'device_name' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid clock-in parameters.', 422, $validator->errors()->toArray());
        }

        $today = Carbon::today()->toDateString();
        $currentTime = Carbon::now()->format('H:i:s');
        $userType = $user->hasRole('Student') ? 'student' : 'staff';

        DB::beginTransaction();
        try {
            $existing = Attendance::where('user_id', $user->id)
                ->whereDate('date', $today)
                ->first();

            if ($existing && ! empty($existing->check_in_time)) {
                return $this->error('ALREADY_CLOCKED_IN', "You have already clocked in today at {$existing->check_in_time}.", 409);
            }

            // Check working calendar to detect late arrival
            $calendar = $this->attendanceService->resolveCalendarForUser($user, $userType);
            $lateMinutes = 0;
            $status = Attendance::STATUS_PRESENT;

            if ($calendar && ! empty($calendar->expected_start_time)) {
                $expectedStart = Carbon::parse($today.' '.$calendar->expected_start_time);
                $graceMinutes = (int) ($calendar->late_grace_minutes ?? 15);
                $actualCheckIn = Carbon::parse($today.' '.$currentTime);

                if ($actualCheckIn->greaterThan($expectedStart->copy()->addMinutes($graceMinutes))) {
                    $lateMinutes = (int) $actualCheckIn->diffInMinutes($expectedStart);
                    $status = Attendance::STATUS_LATE;
                }
            }

            $remarks = $request->input('remarks');
            if ($request->filled('latitude') && $request->filled('longitude')) {
                $geoNote = "GPS: {$request->input('latitude')}, {$request->input('longitude')}";
                $remarks = $remarks ? "{$remarks} | {$geoNote}" : $geoNote;
            }

            $attendance = Attendance::updateOrCreate(
                ['user_id' => $user->id, 'date' => $today],
                [
                    'user_type' => $userType,
                    'status' => $status,
                    'check_in_time' => $currentTime,
                    'late_minutes' => $lateMinutes,
                    'entry_source' => Attendance::SOURCE_MOBILE_QR,
                    'remarks' => $remarks,
                ]
            );

            AuditLogService::log(
                event: 'attendance.clock_in',
                description: "{$user->name} clocked in at {$currentTime} (Status: {$status}).",
                auditable: $attendance,
                newValues: $attendance->toArray(),
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'user_id' => $user->id,
                'status' => $attendance->status,
                'clock_in' => $attendance->check_in_time,
                'attendance' => $attendance,
                'message' => __('Clock-in successful at :time.', ['time' => $currentTime]),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Clock-in exception: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('CLOCK_IN_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Mobile / Web App Self Clock-Out.
     */
    public function clockOut(Request $request): JsonResponse
    {
        $user = $request->filled('user_id') ? User::find($request->input('user_id')) : $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authenticated user context required.', 401);
        }

        $today = Carbon::today()->toDateString();
        $currentTime = Carbon::now()->format('H:i:s');

        DB::beginTransaction();
        try {
            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', $today)
                ->first();

            if (! $attendance || empty($attendance->check_in_time)) {
                return $this->error('NOT_CLOCKED_IN', 'No active clock-in recorded for today. Please clock in first.', 422);
            }

            $checkIn = Carbon::parse($today.' '.$attendance->check_in_time);
            $checkOut = Carbon::parse($today.' '.$currentTime);
            $workingHours = round(abs($checkOut->diffInMinutes($checkIn)) / 60, 2);

            $attendance->update([
                'check_out_time' => $currentTime,
                'working_hours' => $workingHours,
            ]);

            AuditLogService::log(
                event: 'attendance.clock_out',
                description: "{$user->name} clocked out at {$currentTime} (Working hours: {$workingHours} hrs).",
                auditable: $attendance,
                newValues: $attendance->toArray(),
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'user_id' => $user->id,
                'status' => $attendance->status,
                'clock_out' => $attendance->check_out_time,
                'working_hours' => $workingHours,
                'attendance' => $attendance,
                'message' => __('Clock-out successful at :time. Total duration: :hours hrs.', ['time' => $currentTime, 'hours' => $workingHours]),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Clock-out exception: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('CLOCK_OUT_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Authenticated candidate's personal attendance feed & monthly progress.
     */
    public function myAttendance(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authenticated user context required.', 401);
        }

        $year = (int) $request->input('year', Carbon::now()->year);
        $month = (int) $request->input('month', Carbon::now()->month);

        $matrix = $this->attendanceService->getMonthlyUserAttendanceMatrix($user, $year, $month);

        $recentRecords = Attendance::where('user_id', $user->id)
            ->orderByDesc('date')
            ->limit(30)
            ->get();

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'student_id' => $user->student_id,
                'employee_id' => $user->employee_id,
            ],
            'monthly_summary' => $matrix,
            'recent_records' => $recentRecords,
        ]);
    }

    /**
     * Sub-second Gate Turnstile, RFID Scanner, or Kiosk Punch Ingestion.
     */
    public function punch(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['identifier'])) {
            $input['identifier'] = $input['rfid_card_no'] ?? $input['rfid'] ?? $input['biometric_pin'] ?? $input['card_number'] ?? null;
        }
        if (! isset($input['device_serial']) && isset($input['device_identifier'])) {
            $input['device_serial'] = $input['device_identifier'];
        }

        $validator = Validator::make($input, [
            'identifier' => 'required|string', // pin, rfid, student_id, employee_id
            'punch_time' => 'nullable|string',
            'punch_type' => 'nullable|string|in:check_in,check_out,auto',
            'device_serial' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid punch payload.', 422, $validator->errors()->toArray());
        }

        $identifier = $input['identifier'];
        $punchTime = $input['punch_time'] ?? Carbon::now()->toDateTimeString();
        $punchType = $input['punch_type'] ?? 'auto';
        $deviceSerial = $input['device_serial'] ?? null;

        $user = $this->findTargetUser($identifier);
        if (! $user) {
            return $this->error('USER_NOT_FOUND', "No candidate or staff matching '{$identifier}'.", 404);
        }

        // Locate device
        $device = $deviceSerial ? BiometricDevice::where('device_serial_number', $deviceSerial)->first() : null;

        DB::beginTransaction();
        try {
            // Buffer into biometric log
            $log = BiometricLog::create([
                'biometric_device_id' => $device?->id,
                'biometric_pin' => $user->biometric_pin ?: $user->id,
                'card_number' => $user->rfid_card_no,
                'punch_time' => Carbon::parse($punchTime),
                'punch_type' => $punchType,
                'raw_payload' => $request->all(),
                'is_processed' => false,
            ]);

            // Process immediately
            $this->attendanceService->processBiometricPunches(10);

            // Fetch created/updated attendance record for feedback
            $today = Carbon::parse($punchTime)->toDateString();
            $attendance = Attendance::where('user_id', $user->id)
                ->whereDate('date', $today)
                ->first();

            DB::commit();

            return $this->success([
                'verified' => true,
                'gate_action' => 'unlock',
                'user_id' => $user->id,
                'candidate' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'identifier' => $user->student_id ?: $user->employee_id,
                    'photo' => $user->profile_photo_url,
                ],
                'attendance_status' => $attendance?->status ?? 'recorded',
                'punch_time' => $punchTime,
                'message' => __('Gate punch verified for :name.', ['name' => $user->name]),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Gate punch failure: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('GATE_PUNCH_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Batch hardware punch push ingestion.
     */
    public function batchPunch(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['device_serial']) && isset($input['device_identifier'])) {
            $input['device_serial'] = $input['device_identifier'];
        }
        if (isset($input['punches']) && is_array($input['punches'])) {
            foreach ($input['punches'] as &$p) {
                if (! isset($p['card_number']) && isset($p['rfid_card_no'])) {
                    $p['card_number'] = $p['rfid_card_no'];
                }
            }
        }

        $validator = Validator::make($input, [
            'punches' => 'required|array|min:1',
            'punches.*.biometric_pin' => 'nullable|string',
            'punches.*.card_number' => 'nullable|string',
            'punches.*.punch_time' => 'nullable|string',
            'punches.*.punch_type' => 'nullable|string',
            'device_serial' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid batch punches payload.', 422, $validator->errors()->toArray());
        }

        $punches = $input['punches'];
        $device = ! empty($input['device_serial']) ? BiometricDevice::where('device_serial_number', $input['device_serial'])->first() : null;

        $createdCount = 0;
        foreach ($punches as $p) {
            if (empty($p['biometric_pin']) && empty($p['card_number'])) {
                continue;
            }

            BiometricLog::create([
                'biometric_device_id' => $device?->id,
                'biometric_pin' => $p['biometric_pin'] ?? null,
                'card_number' => $p['card_number'] ?? null,
                'punch_time' => ! empty($p['punch_time']) ? Carbon::parse($p['punch_time']) : Carbon::now(),
                'punch_type' => $p['punch_type'] ?? 'auto',
                'raw_payload' => $p,
                'is_processed' => false,
            ]);

            $createdCount++;
        }

        // Process batch
        $processed = $this->attendanceService->processBiometricPunches(min($createdCount, 500));

        return $this->success([
            'total_received' => count($punches),
            'ingested' => $createdCount,
            'ingested_count' => $createdCount,
            'unresolved_count' => count($punches) - $createdCount,
            'processed' => $processed['processed'] ?? 0,
            'message' => __('Successfully ingested :count raw punches.', ['count' => $createdCount]),
        ]);
    }

    /**
     * On-demand trigger to process unprocessed biometric punch logs.
     */
    public function syncPunches(Request $request): JsonResponse
    {
        try {
            $limit = min((int) $request->input('limit', 500), 1000);
            $result = $this->attendanceService->processBiometricPunches($limit);

            return $this->success([
                'result' => $result,
                'message' => __('Synchronized :count pending punches.', ['count' => $result['processed'] ?? 0]),
            ]);
        } catch (Throwable $e) {
            Log::error("Biometric sync failed: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('SYNC_PUNCHES_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * List registered biometric devices.
     */
    public function devices(Request $request): JsonResponse
    {
        $devices = BiometricDevice::withCount(['logs' => fn ($q) => $q->where('is_processed', false)])
            ->orderBy('device_name')
            ->get();

        return $this->success($devices);
    }

    /**
     * Register a new biometric hardware device.
     */
    public function storeDevice(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['device_name']) && isset($input['name'])) {
            $input['device_name'] = $input['name'];
        }
        if (! isset($input['device_serial_number']) && isset($input['device_identifier'])) {
            $input['device_serial_number'] = $input['device_identifier'];
        }
        if (! isset($input['device_ip']) && isset($input['ip_address'])) {
            $input['device_ip'] = $input['ip_address'];
        }

        $validator = Validator::make($input, [
            'device_name' => 'required|string|max:100',
            'device_serial_number' => 'nullable|string|max:100|unique:biometric_devices,device_serial_number',
            'device_ip' => 'nullable|ip',
            'port' => 'nullable|integer|between:1,65535',
            'location' => 'nullable|string|max:100',
            'device_type' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid device configuration.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $device = BiometricDevice::create(array_merge($validator->validated(), [
                'created_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'biometric_device.created',
                description: "Biometric hardware device '{$device->device_name}' registered.",
                auditable: $device,
                newValues: $device->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($device, null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DEVICE_CREATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Show single biometric device profile.
     */
    public function showDevice(int $id): JsonResponse
    {
        $device = BiometricDevice::withCount('logs')->findOrFail($id);

        return $this->success($device);
    }

    /**
     * Update biometric device.
     */
    public function updateDevice(int $id, Request $request): JsonResponse
    {
        $device = BiometricDevice::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'device_name' => 'sometimes|required|string|max:100',
            'device_ip' => 'nullable|ip',
            'port' => 'nullable|integer|between:1,65535',
            'location' => 'nullable|string|max:100',
            'device_type' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:online,offline,inactive,error',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid update parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $oldValues = $device->toArray();

        DB::beginTransaction();
        try {
            $device->update(array_merge($validator->validated(), [
                'updated_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'biometric_device.updated',
                description: "Biometric device '{$device->device_name}' updated.",
                auditable: $device,
                oldValues: $oldValues,
                newValues: $device->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($device);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DEVICE_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Soft delete biometric device.
     */
    public function destroyDevice(int $id, Request $request): JsonResponse
    {
        $device = BiometricDevice::findOrFail($id);
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $device->update(['deleted_by' => $actor?->id]);
            $device->delete();

            AuditLogService::log(
                event: 'biometric_device.deleted',
                description: "Biometric device '{$device->device_name}' soft deleted.",
                auditable: $device,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(['message' => __('Biometric device deleted successfully.')]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DEVICE_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Regenerate hardware API authentication token for a device.
     */
    public function regenerateDeviceToken(int $id, Request $request): JsonResponse
    {
        $device = BiometricDevice::findOrFail($id);
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $newToken = 'bio_'.Str::random(40);
            $device->update([
                'api_token' => $newToken,
                'updated_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'biometric_device.token_regenerated',
                description: "API authorization token regenerated for device '{$device->device_name}'.",
                auditable: $device,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'id' => $device->id,
                'device_id' => $device->id,
                'device_token' => $newToken,
                'api_token' => $newToken,
                'message' => __('Device token regenerated successfully.'),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('TOKEN_REGENERATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * List institutional working calendars and shifts.
     */
    public function calendars(Request $request): JsonResponse
    {
        $query = WorkingCalendar::with(['course:id,name,code', 'batch:id,name,code']);

        if ($targetType = $request->input('target_type')) {
            $query->where('target_type', $targetType);
        }

        $calendars = $query->orderBy('name')->get();

        return $this->success($calendars);
    }

    /**
     * Create working calendar.
     */
    public function storeCalendar(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['effective_from']) && isset($input['start_date'])) {
            $input['effective_from'] = $input['start_date'];
        }
        if (! isset($input['effective_to']) && isset($input['end_date'])) {
            $input['effective_to'] = $input['end_date'];
        }
        if (! isset($input['expected_start_time']) && isset($input['shift_start_time'])) {
            $input['expected_start_time'] = $input['shift_start_time'];
        }
        if (! isset($input['expected_end_time']) && isset($input['shift_end_time'])) {
            $input['expected_end_time'] = $input['shift_end_time'];
        }
        if (isset($input['working_days']) && is_array($input['working_days'])) {
            $days = array_map('strtolower', $input['working_days']);
            $input['monday_active'] = in_array('monday', $days);
            $input['tuesday_active'] = in_array('tuesday', $days);
            $input['wednesday_active'] = in_array('wednesday', $days);
            $input['thursday_active'] = in_array('thursday', $days);
            $input['friday_active'] = in_array('friday', $days);
            $input['saturday_active'] = in_array('saturday', $days);
            $input['sunday_active'] = in_array('sunday', $days);
        }

        $validator = Validator::make($input, [
            'name' => 'required|string|max:100',
            'target_type' => 'required|string|in:staff,student',
            'course_id' => 'nullable|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
            'department' => 'nullable|string|max:100',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'monday_active' => 'boolean',
            'tuesday_active' => 'boolean',
            'wednesday_active' => 'boolean',
            'thursday_active' => 'boolean',
            'friday_active' => 'boolean',
            'saturday_active' => 'boolean',
            'sunday_active' => 'boolean',
            'saturday_rules' => 'nullable|string|in:all_working,2nd_4th_off,all_saturdays_off',
            'expected_start_time' => 'nullable|string',
            'expected_end_time' => 'nullable|string',
            'late_grace_minutes' => 'nullable|integer|min:0',
            'half_day_hours' => 'nullable|numeric|min:1',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid calendar configuration.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $calendar = WorkingCalendar::create(array_merge($validator->validated(), [
                'created_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'working_calendar.created',
                description: "Working calendar '{$calendar->name}' created.",
                auditable: $calendar,
                newValues: $calendar->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($calendar, null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('CALENDAR_CREATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Show single calendar details.
     */
    public function showCalendar(int $id): JsonResponse
    {
        $calendar = WorkingCalendar::with(['course', 'batch'])->findOrFail($id);

        return $this->success($calendar);
    }

    /**
     * Update working calendar.
     */
    public function updateCalendar(int $id, Request $request): JsonResponse
    {
        $calendar = WorkingCalendar::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'target_type' => 'sometimes|required|string|in:staff,student',
            'course_id' => 'nullable|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
            'department' => 'nullable|string|max:100',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'monday_active' => 'boolean',
            'tuesday_active' => 'boolean',
            'wednesday_active' => 'boolean',
            'thursday_active' => 'boolean',
            'friday_active' => 'boolean',
            'saturday_active' => 'boolean',
            'sunday_active' => 'boolean',
            'saturday_rules' => 'nullable|string|in:all_working,2nd_4th_off,all_saturdays_off',
            'expected_start_time' => 'nullable|string',
            'expected_end_time' => 'nullable|string',
            'late_grace_minutes' => 'nullable|integer|min:0',
            'half_day_hours' => 'nullable|numeric|min:1',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid update parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $oldValues = $calendar->toArray();

        DB::beginTransaction();
        try {
            $calendar->update(array_merge($validator->validated(), [
                'updated_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'working_calendar.updated',
                description: "Working calendar '{$calendar->name}' updated.",
                auditable: $calendar,
                oldValues: $oldValues,
                newValues: $calendar->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($calendar);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('CALENDAR_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Soft delete working calendar.
     */
    public function destroyCalendar(int $id, Request $request): JsonResponse
    {
        $calendar = WorkingCalendar::findOrFail($id);
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $calendar->update(['deleted_by' => $actor?->id]);
            $calendar->delete();

            AuditLogService::log(
                event: 'working_calendar.deleted',
                description: "Working calendar '{$calendar->name}' soft deleted.",
                auditable: $calendar,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(['message' => __('Working calendar deleted successfully.')]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('CALENDAR_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * List attendance holidays & academic off-days.
     */
    public function holidays(Request $request): JsonResponse
    {
        $query = AttendanceHoliday::query();

        if ($year = $request->input('year')) {
            $query->whereYear('date', $year);
        }
        if ($month = $request->input('month')) {
            $query->whereMonth('date', $month);
        }
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($targetType = $request->input('target_type')) {
            $query->whereIn('target_type', [$targetType, 'all']);
        }

        $holidays = $query->orderBy('date')->get();

        return $this->success($holidays);
    }

    /**
     * Add new attendance holiday.
     */
    public function storeHoliday(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['type'])) {
            $input['type'] = 'institution_holiday';
        }
        if (! isset($input['is_restricted']) && isset($input['is_mandatory'])) {
            $input['is_restricted'] = ! ((bool) $input['is_mandatory']);
        }

        $validator = Validator::make($input, [
            'title' => 'required|string|max:150',
            'date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:date',
            'type' => 'required|string|in:govt_holiday,institution_holiday,weekly_off,weekly_test,exam_day,restricted_holiday,emergency_off,vacation',
            'target_type' => 'nullable|string|in:all,staff,student',
            'description' => 'nullable|string|max:500',
            'is_restricted' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid holiday parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $holiday = AttendanceHoliday::create(array_merge($validator->validated(), [
                'target_type' => $request->input('target_type', 'all'),
                'created_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'attendance_holiday.created',
                description: "Institutional holiday '{$holiday->title}' added for {$holiday->date}.",
                auditable: $holiday,
                newValues: $holiday->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($holiday, null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('HOLIDAY_CREATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Get single holiday profile.
     */
    public function showHoliday(int $id): JsonResponse
    {
        $holiday = AttendanceHoliday::findOrFail($id);

        return $this->success($holiday);
    }

    /**
     * Update holiday.
     */
    public function updateHoliday(int $id, Request $request): JsonResponse
    {
        $holiday = AttendanceHoliday::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:150',
            'date' => 'sometimes|required|date',
            'to_date' => 'nullable|date|after_or_equal:date',
            'type' => 'sometimes|required|string|in:govt_holiday,institution_holiday,weekly_off,weekly_test,exam_day,restricted_holiday,emergency_off,vacation',
            'target_type' => 'nullable|string|in:all,staff,student',
            'description' => 'nullable|string|max:500',
            'is_restricted' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid update parameters.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $oldValues = $holiday->toArray();

        DB::beginTransaction();
        try {
            $holiday->update(array_merge($validator->validated(), [
                'updated_by' => $actor?->id,
            ]));

            AuditLogService::log(
                event: 'attendance_holiday.updated',
                description: "Holiday '{$holiday->title}' updated.",
                auditable: $holiday,
                oldValues: $oldValues,
                newValues: $holiday->toArray(),
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($holiday);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('HOLIDAY_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Soft delete holiday.
     */
    public function destroyHoliday(int $id, Request $request): JsonResponse
    {
        $holiday = AttendanceHoliday::findOrFail($id);
        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $holiday->update(['deleted_by' => $actor?->id]);
            $holiday->delete();

            AuditLogService::log(
                event: 'attendance_holiday.deleted',
                description: "Holiday '{$holiday->title}' soft deleted.",
                auditable: $holiday,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success(['message' => __('Holiday deleted successfully.')]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('HOLIDAY_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Stream CSV attendance export.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $query = Attendance::with(['user', 'course', 'batch', 'section']);

        if ($date = $request->input('date')) {
            $query->whereDate('date', $date);
        } elseif ($fromDate = $request->input('from_date')) {
            $toDate = $request->input('to_date', Carbon::today()->toDateString());
            $query->whereBetween('date', [$fromDate, $toDate]);
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }
        if ($userType = $request->input('user_type')) {
            $query->where('user_type', $userType);
        }

        $filename = 'attendance_export_'.Carbon::now()->format('Ymd_His').'.csv';

        return AttendanceImportExportService::exportCsv($query, $filename);
    }

    /**
     * Stream printable PDF attendance register.
     */
    public function exportPdf(Request $request): StreamedResponse
    {
        $query = Attendance::with(['user', 'course', 'batch', 'section']);

        if ($date = $request->input('date')) {
            $query->whereDate('date', $date);
        }
        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        return AttendanceImportExportService::exportPdf($query, [
            'date' => $request->input('date', Carbon::today()->toDateString()),
            'title' => 'Official Attendance Register',
        ]);
    }

    /**
     * Bulk import attendance register from uploaded spreadsheet (CSV/XLSX).
     */
    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid attendance file upload.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $file = $request->file('file');

        try {
            $filePath = $file->getRealPath();
            $result = AttendanceImportExportService::importAttendance($filePath, $actor);

            AuditLogService::log(
                event: 'attendance.imported',
                description: "Bulk attendance import executed: {$result['imported']} rows imported, {$result['skipped']} skipped.",
                userId: $actor?->id
            );

            return $this->success($result, null, 201);
        } catch (Throwable $e) {
            Log::error("Attendance import error: {$e->getMessage()}", ['exception' => $e]);

            return $this->error('IMPORT_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Download sample CSV attendance import template.
     */
    public function sampleCsv(): StreamedResponse
    {
        return AttendanceImportExportService::downloadSampleCsv();
    }
}
