<?php

namespace App\Services\Leave;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\LeaveAllocation;
use App\Models\LeaveApplication;
use App\Models\LeaveCategory;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveManagementService
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Submit a new leave application.
     *
     * @param  array{
     *     leave_category_id: int,
     *     from_date: string,
     *     to_date: string,
     *     is_half_day?: bool,
     *     half_day_type?: string|null,
     *     reason: string,
     *     attachment_path?: string|null,
     *     emergency_contact?: string|null
     * }  $data
     */
    public function applyLeave(User $user, array $data): LeaveApplication
    {
        $fromDate = Carbon::parse($data['from_date'])->startOfDay();
        $toDate = Carbon::parse($data['to_date'])->startOfDay();

        if ($toDate->lt($fromDate)) {
            throw ValidationException::withMessages([
                'to_date' => __('The leave end date cannot be earlier than the start date.'),
            ]);
        }

        $category = LeaveCategory::findOrFail($data['leave_category_id']);

        $userType = $user->isStudent() ? 'student' : 'staff';

        // Check if category is applicable to user
        if ($category->applicable_to !== 'both' && $category->applicable_to !== 'all' && $category->applicable_to !== $userType) {
            throw ValidationException::withMessages([
                'leave_category_id' => __('This leave category is not applicable for your user role (:type).', ['type' => ucfirst($userType)]),
            ]);
        }

        // Check for overlapping active applications
        $hasOverlap = LeaveApplication::where('user_id', $user->id)
            ->whereIn('status', [LeaveApplication::STATUS_PENDING, LeaveApplication::STATUS_APPROVED])
            ->where(function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('from_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhereBetween('to_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhere(function ($q) use ($fromDate, $toDate) {
                        $q->where('from_date', '<=', $fromDate->toDateString())
                            ->where('to_date', '>=', $toDate->toDateString());
                    });
            })
            ->exists();

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'from_date' => __('You already have a pending or approved leave application overlapping with these dates.'),
            ]);
        }

        // Calculate total days
        $isHalfDay = (bool) ($data['is_half_day'] ?? false);
        $totalDays = $isHalfDay ? 0.5 : ($fromDate->diffInDays($toDate) + 1);

        if ($category->requires_attachment && empty($data['attachment_path'])) {
            throw ValidationException::withMessages([
                'attachment' => __('A supporting document/medical certificate is mandatory for this leave category.'),
            ]);
        }

        return DB::transaction(function () use ($user, $userType, $category, $data, $fromDate, $toDate, $totalDays, $isHalfDay) {
            $applicationNo = LeaveApplication::generateApplicationNumber();

            $application = LeaveApplication::create([
                'application_no' => $applicationNo,
                'user_id' => $user->id,
                'leave_category_id' => $category->id,
                'user_type' => $userType,
                'from_date' => $fromDate->toDateString(),
                'to_date' => $toDate->toDateString(),
                'total_days' => $totalDays,
                'is_half_day' => $isHalfDay,
                'half_day_type' => $isHalfDay ? ($data['half_day_type'] ?? 'first_half') : null,
                'reason' => $data['reason'],
                'attachment_path' => $data['attachment_path'] ?? null,
                'emergency_contact' => $data['emergency_contact'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING,
                'created_by' => $user->id,
            ]);

            // Track in allocation for the current year
            $year = $fromDate->year;
            $allocation = LeaveAllocation::firstOrCreate(
                ['user_id' => $user->id, 'leave_category_id' => $category->id, 'year' => $year],
                ['total_days' => $category->annual_quota, 'used_days' => 0, 'pending_days' => 0, 'carried_forward_days' => 0]
            );

            $allocation->increment('pending_days', $totalDays);

            AuditLog::record(
                action: 'leave_applied',
                details: [
                    'application_no' => $applicationNo,
                    'user_id' => $user->id,
                    'category' => $category->name,
                    'from' => $fromDate->toDateString(),
                    'to' => $toDate->toDateString(),
                    'days' => $totalDays,
                ],
                userId: $user->id,
                auditableType: LeaveApplication::class,
                auditableId: $application->id,
                description: "Submitted leave application {$applicationNo} ({$totalDays} days) for {$category->name}"
            );

            return $application;
        });
    }

    /**
     * Approve a leave application and automatically sync with the attendance register.
     */
    public function approveLeave(LeaveApplication $application, User $approver, ?string $remarks = null): LeaveApplication
    {
        return DB::transaction(function () use ($application, $approver, $remarks) {
            $application->loadMissing(['user', 'category']);

            $previousStatus = $application->status;
            $application->status = LeaveApplication::STATUS_APPROVED;
            $application->action_by = $approver->id;
            $application->action_at = Carbon::now();
            $application->action_remarks = $remarks;
            $application->updated_by = $approver->id;

            // Update allocation: deduct from pending and add to used
            $year = Carbon::parse($application->from_date)->year;
            $allocation = LeaveAllocation::firstOrCreate(
                ['user_id' => $application->user_id, 'leave_category_id' => $application->leave_category_id, 'year' => $year],
                ['total_days' => $application->category->annual_quota, 'used_days' => 0, 'pending_days' => 0, 'carried_forward_days' => 0]
            );

            if ($previousStatus === LeaveApplication::STATUS_PENDING) {
                $allocation->pending_days = max(0, $allocation->pending_days - $application->total_days);
            }
            $allocation->used_days += $application->total_days;
            $allocation->save();

            // Automatically sync to attendance system if enabled
            $autoSync = (bool) Setting::get('leave.auto_sync_to_attendance', true);
            if ($autoSync) {
                $this->syncLeaveToAttendance($application);
                $application->is_synced_to_attendance = true;
            }

            $application->save();

            AuditLog::record(
                action: 'leave_approved',
                details: [
                    'application_no' => $application->application_no,
                    'approver' => $approver->name,
                    'remarks' => $remarks,
                ],
                userId: $approver->id,
                auditableType: LeaveApplication::class,
                auditableId: $application->id,
                description: "Approved leave application {$application->application_no} for {$application->user->name}"
            );

            // Notify user
            $this->notificationService->send(
                user: $application->user,
                title: __('Leave Application Approved (:no)', ['no' => $application->application_no]),
                message: __('Your leave request from :from to :to has been approved by :approver. Remarks: :remarks', [
                    'from' => $application->from_date->format('d M Y'),
                    'to' => $application->to_date->format('d M Y'),
                    'approver' => $approver->name,
                    'remarks' => $remarks ?: __('No remarks'),
                ]),
                category: 'leaves',
                options: [
                    'type' => 'leave_approved',
                    'action_url' => $application->user_type === 'student' ? route('student.leaves.index') : route('portal.my-leaves.index'),
                    'action_label' => __('View Leave History'),
                    'color' => 'emerald',
                ]
            );

            return $application;
        });
    }

    /**
     * Reject a leave application.
     */
    public function rejectLeave(LeaveApplication $application, User $approver, string $remarks): LeaveApplication
    {
        return DB::transaction(function () use ($application, $approver, $remarks) {
            $application->loadMissing(['user', 'category']);

            $previousStatus = $application->status;
            $application->status = LeaveApplication::STATUS_REJECTED;
            $application->action_by = $approver->id;
            $application->action_at = Carbon::now();
            $application->action_remarks = $remarks;
            $application->updated_by = $approver->id;

            // Revert pending days
            $year = Carbon::parse($application->from_date)->year;
            $allocation = LeaveAllocation::where('user_id', $application->user_id)
                ->where('leave_category_id', $application->leave_category_id)
                ->where('year', $year)
                ->first();

            if ($allocation) {
                if ($previousStatus === LeaveApplication::STATUS_PENDING) {
                    $allocation->pending_days = max(0, $allocation->pending_days - $application->total_days);
                } elseif ($previousStatus === LeaveApplication::STATUS_APPROVED) {
                    $allocation->used_days = max(0, $allocation->used_days - $application->total_days);
                }
                $allocation->save();
            }

            // If previously synced to attendance, remove or revert
            if ($application->is_synced_to_attendance) {
                Attendance::where('leave_application_id', $application->id)->delete();
                $application->is_synced_to_attendance = false;
            }

            $application->save();

            AuditLog::record(
                action: 'leave_rejected',
                details: [
                    'application_no' => $application->application_no,
                    'approver' => $approver->name,
                    'remarks' => $remarks,
                ],
                userId: $approver->id,
                auditableType: LeaveApplication::class,
                auditableId: $application->id,
                description: "Rejected leave application {$application->application_no} for {$application->user->name}"
            );

            // Notify user
            $this->notificationService->send(
                user: $application->user,
                title: __('Leave Application Rejected (:no)', ['no' => $application->application_no]),
                message: __('Your leave request from :from to :to was rejected. Reason: :remarks', [
                    'from' => $application->from_date->format('d M Y'),
                    'to' => $application->to_date->format('d M Y'),
                    'remarks' => $remarks,
                ]),
                category: 'leaves',
                options: [
                    'type' => 'leave_rejected',
                    'action_url' => $application->user_type === 'student' ? route('student.leaves.index') : route('portal.my-leaves.index'),
                    'action_label' => __('View Leave History'),
                    'color' => 'rose',
                ]
            );

            return $application;
        });
    }

    /**
     * Cancel a leave application by user or admin.
     */
    public function cancelLeave(LeaveApplication $application, User $user, ?string $remarks = null): LeaveApplication
    {
        return DB::transaction(function () use ($application, $user, $remarks) {
            $previousStatus = $application->status;
            $application->status = LeaveApplication::STATUS_CANCELLED;
            $application->action_remarks = $remarks ?: __('Cancelled by applicant');
            $application->updated_by = $user->id;

            $year = Carbon::parse($application->from_date)->year;
            $allocation = LeaveAllocation::where('user_id', $application->user_id)
                ->where('leave_category_id', $application->leave_category_id)
                ->where('year', $year)
                ->first();

            if ($allocation) {
                if ($previousStatus === LeaveApplication::STATUS_PENDING) {
                    $allocation->pending_days = max(0, $allocation->pending_days - $application->total_days);
                } elseif ($previousStatus === LeaveApplication::STATUS_APPROVED) {
                    $allocation->used_days = max(0, $allocation->used_days - $application->total_days);
                }
                $allocation->save();
            }

            if ($application->is_synced_to_attendance) {
                Attendance::where('leave_application_id', $application->id)->delete();
                $application->is_synced_to_attendance = false;
            }

            $application->save();

            AuditLog::record(
                action: 'leave_cancelled',
                details: [
                    'application_no' => $application->application_no,
                    'cancelled_by' => $user->name,
                ],
                userId: $user->id,
                auditableType: LeaveApplication::class,
                auditableId: $application->id,
                description: "Cancelled leave application {$application->application_no}"
            );

            return $application;
        });
    }

    /**
     * Sync approved leave dates into the attendances table.
     */
    public function syncLeaveToAttendance(LeaveApplication $application): void
    {
        $application->loadMissing(['user', 'category']);

        $period = CarbonPeriod::create($application->from_date, $application->to_date);

        // Fetch student active enrollment if student
        $courseId = null;
        $batchId = null;
        $sectionId = null;

        if ($application->user_type === 'student') {
            $enrollment = $application->user->activeEnrollments()->first();
            if ($enrollment) {
                $courseId = $enrollment->course_id;
                $batchId = $enrollment->batch_id;
                $sectionId = $enrollment->section_id;
            }
        }

        foreach ($period as $date) {
            $dateString = $date->toDateString();

            $attendance = Attendance::where('user_id', $application->user_id)
                ->whereDate('date', $dateString)
                ->first() ?? new Attendance([
                    'user_id' => $application->user_id,
                    'date' => $dateString,
                ]);

            if (! $attendance->exists) {
                $attendance->attendance_no = Attendance::generateAttendanceNumber();
            }

            $attendance->user_type = $application->user_type;
            $attendance->course_id = $courseId;
            $attendance->batch_id = $batchId;
            $attendance->section_id = $sectionId;
            $attendance->status = $application->is_half_day ? Attendance::STATUS_HALF_DAY : Attendance::STATUS_ON_LEAVE;
            $attendance->entry_source = Attendance::SOURCE_SYSTEM_AUTO_LEAVE;
            $attendance->leave_application_id = $application->id;
            $attendance->remarks = __('Approved Leave: :cat (:no)', [
                'cat' => $application->category->name,
                'no' => $application->application_no,
            ]);
            $attendance->marked_by = $application->action_by;
            $attendance->save();
        }
    }

    /**
     * Retrieve category-wise leave balance for a user.
     *
     * @return array<int, array{
     *     category: LeaveCategory,
     *     total_days: float,
     *     used_days: float,
     *     pending_days: float,
     *     remaining_days: float
     * }>
     */
    public function getUserLeaveBalances(User $user, ?int $year = null): array
    {
        $year = $year ?: Carbon::now()->year;
        $userType = $user->isStudent() ? 'student' : 'staff';

        $categories = LeaveCategory::active()
            ->where(function ($q) use ($userType) {
                $q->where('applicable_to', 'both')->orWhere('applicable_to', $userType);
            })
            ->get();

        $balances = [];

        foreach ($categories as $category) {
            $allocation = LeaveAllocation::where('user_id', $user->id)
                ->where('leave_category_id', $category->id)
                ->where('year', $year)
                ->first();

            $total = $allocation ? (float) ($allocation->total_days + $allocation->carried_forward_days) : (float) $category->annual_quota;
            $used = $allocation ? (float) $allocation->used_days : 0.0;
            $pending = $allocation ? (float) $allocation->pending_days : 0.0;
            $remaining = max(0.0, $total - $used - $pending);

            $balances[] = [
                'category' => $category,
                'total_days' => $total,
                'used_days' => $used,
                'pending_days' => $pending,
                'remaining_days' => $remaining,
            ];
        }

        return $balances;
    }
}
