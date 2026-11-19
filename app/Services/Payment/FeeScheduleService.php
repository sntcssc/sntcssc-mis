<?php

namespace App\Services\Payment;

use App\Helpers\CourseData;
use App\Models\AppNotification;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\StudentFeeInvoice;
use App\Models\StudentLedger;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeeScheduleService
{
    /**
     * Initiate fee payment collection schedule for a specific course and batch.
     *
     * @param  array{
     *     batch_id: int,
     *     course_id: int,
     *     fee_type_id: int,
     *     title: string,
     *     academic_year?: string|null,
     *     period_month?: int|null,
     *     period_year?: int|null,
     *     billing_cycle?: string|null,
     *     due_date: string|Carbon,
     *     grace_period_days?: int,
     *     late_fee_amount?: float,
     *     description?: string|null
     * }  $data
     */
    public static function initiateBatchCourseFeeSchedule(array $data, ?int $createdBy = null): FeeSchedule
    {
        $batch = Batch::findOrFail($data['batch_id']);
        $course = Course::findOrFail($data['course_id']);
        $feeType = FeeType::findOrFail($data['fee_type_id']);

        return DB::transaction(function () use ($data, $batch, $course, $feeType, $createdBy) {
            $dueDate = Carbon::parse($data['due_date']);
            $graceDays = (int) ($data['grace_period_days'] ?? 7);
            $lateFee = (float) ($data['late_fee_amount'] ?? 0.00);
            $periodMonth = ! empty($data['period_month']) ? (int) $data['period_month'] : null;
            $periodYear = ! empty($data['period_year']) ? (int) $data['period_year'] : (int) date('Y');
            $academicYear = $data['academic_year'] ?? $batch->academic_year ?: date('Y').'-'.(date('Y') + 1);

            // 1. Create the Schedule master record
            $schedule = FeeSchedule::create([
                'schedule_no' => FeeSchedule::generateScheduleNo(),
                'fee_type_id' => $feeType->id,
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'title' => $data['title'],
                'academic_year' => $academicYear,
                'period_month' => $periodMonth,
                'period_year' => $periodYear,
                'billing_cycle' => $data['billing_cycle'] ?? $feeType->frequency,
                'due_date' => $dueDate->toDateString(),
                'grace_period_days' => $graceDays,
                'late_fee_amount' => $lateFee,
                'description' => $data['description'] ?? null,
                'status' => FeeSchedule::STATUS_ACTIVE,
                'created_by' => $createdBy,
            ]);

            // 2. Fetch all active enrollments for this Batch and Course
            $enrollments = Enrollment::query()
                ->where('batch_id', $batch->id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->with(['user.studentProfile'])
                ->get();

            $targetedCount = 0;
            $totalInvoiced = 0.00;

            foreach ($enrollments as $enrollment) {
                $user = $enrollment->user;
                if (! $user) {
                    continue;
                }

                // Check for existing invoice for this period
                $existingQuery = StudentFeeInvoice::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('fee_type_id', $feeType->id);

                if ($periodMonth && $periodYear) {
                    $existingQuery->where('period_month', $periodMonth)->where('period_year', $periodYear);
                } else {
                    $existingQuery->where('fee_schedule_id', $schedule->id);
                }

                if ($existingQuery->exists()) {
                    continue;
                }

                // Calculate category-specific fee using centralized matrix
                $category = $user->studentProfile?->verified_category
                    ?: $user->studentProfile?->category
                    ?: 'UR';

                $feeAmount = CourseData::calculateFee($course, $category);

                // If enrollment has custom fee set, respect that
                if ((float) $enrollment->fee_amount > 0 && $feeType->code === 'tuition' && $course->fee_type !== 'monthly') {
                    $feeAmount = (float) $enrollment->fee_amount;
                }

                $isExempted = $course->isFree() || $feeAmount <= 0.00;
                $status = $isExempted ? StudentFeeInvoice::STATUS_PAID : StudentFeeInvoice::STATUS_PENDING;

                $invoice = StudentFeeInvoice::create([
                    'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
                    'user_id' => $user->id,
                    'student_id' => $enrollment->student_id ?: $user->student_id,
                    'enrollment_id' => $enrollment->id,
                    'fee_schedule_id' => $schedule->id,
                    'fee_type_id' => $feeType->id,
                    'title' => $data['title'].' - '.$course->name,
                    'description' => $schedule->description,
                    'period_month' => $periodMonth,
                    'period_year' => $periodYear,
                    'amount' => $feeAmount,
                    'discount_amount' => 0.00,
                    'late_fee_amount' => 0.00,
                    'total_amount' => $feeAmount,
                    'paid_amount' => $isExempted ? $feeAmount : 0.00,
                    'balance_amount' => $isExempted ? 0.00 : $feeAmount,
                    'due_date' => $dueDate->toDateString(),
                    'status' => $status,
                    'paid_at' => $isExempted ? now() : null,
                    'created_by' => $createdBy,
                ]);

                // Record Debit in Student Ledger (double-entry banking model)
                if (! $isExempted && $feeAmount > 0.00) {
                    StudentLedger::recordDebit(
                        userId: $user->id,
                        amount: $feeAmount,
                        referenceType: StudentLedger::REF_INVOICE,
                        referenceId: $invoice->id,
                        description: "Fee Invoice {$invoice->invoice_no} ({$invoice->title})",
                        studentId: $invoice->student_id,
                        createdBy: $createdBy
                    );
                }

                // Send In-App Notification to student
                AppNotification::create([
                    'user_id' => $user->id,
                    'type' => 'fee_invoice_issued',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('New Fee Invoice Issued'),
                    'message' => __(':title has been generated. Due date is :date.', [
                        'title' => $invoice->title,
                        'date' => $dueDate->format('d M Y'),
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'invoice_id' => $invoice->id,
                        'invoice_no' => $invoice->invoice_no,
                        'amount' => $feeAmount,
                        'due_date' => $dueDate->toDateString(),
                    ],
                ]);

                $targetedCount++;
                $totalInvoiced += $feeAmount;
            }

            $schedule->update([
                'total_targeted_students' => $targetedCount,
                'total_invoiced_amount' => $totalInvoiced,
            ]);

            AuditLogService::log(
                event: 'fee_schedule_initiated',
                description: "Initiated fee schedule '{$schedule->title}' for Course: {$course->name}, Batch: {$batch->name}. Targeted {$targetedCount} students, Total: ₹".number_format($totalInvoiced, 2),
                auditable: $schedule,
                userId: $createdBy
            );

            return $schedule;
        });
    }

    /**
     * Revert or cancel a fee schedule, cancelling uncollected invoices and offsetting ledger debits.
     *
     * @return array{success: bool, cancelled_count: int, adjusted_count: int, schedule: FeeSchedule}
     */
    public static function revertSchedule(int|FeeSchedule $schedule, string $reason, ?int $revertedBy = null): array
    {
        $model = $schedule instanceof FeeSchedule ? $schedule : FeeSchedule::findOrFail($schedule);
        $revertedBy = $revertedBy ?? auth()->id();

        return DB::transaction(function () use ($model, $reason, $revertedBy) {
            $invoices = $model->invoices()
                ->whereNotIn('status', [StudentFeeInvoice::STATUS_PAID, StudentFeeInvoice::STATUS_CANCELLED])
                ->get();

            $cancelledCount = 0;
            $adjustedCount = 0;

            foreach ($invoices as $inv) {
                if ((float) $inv->paid_amount <= 0.00) {
                    // Reverse entire invoice liability in student ledger
                    StudentLedger::recordCredit(
                        userId: $inv->user_id,
                        amount: (float) $inv->total_amount,
                        referenceType: StudentLedger::REF_ADJUSTMENT,
                        referenceId: $inv->id,
                        description: "Schedule {$model->schedule_no} cancelled: {$reason}",
                        studentId: $inv->student_id,
                        createdBy: $revertedBy
                    );

                    $inv->update([
                        'status' => StudentFeeInvoice::STATUS_CANCELLED,
                        'balance_amount' => 0.00,
                    ]);
                    $cancelledCount++;
                } else {
                    // Partially paid invoice: waive/cancel the remaining unpaid balance liability
                    $remainingBalance = (float) $inv->balance_amount;
                    if ($remainingBalance > 0.00) {
                        StudentLedger::recordCredit(
                            userId: $inv->user_id,
                            amount: $remainingBalance,
                            referenceType: StudentLedger::REF_ADJUSTMENT,
                            referenceId: $inv->id,
                            description: "Unpaid balance waived on schedule revert: {$reason}",
                            studentId: $inv->student_id,
                            createdBy: $revertedBy
                        );
                    }

                    $inv->update([
                        'status' => StudentFeeInvoice::STATUS_WAIVED,
                        'balance_amount' => 0.00,
                    ]);
                    $adjustedCount++;
                }
            }

            $model->update([
                'status' => FeeSchedule::STATUS_CANCELLED,
                'description' => trim(($model->description ? $model->description."\n" : '').'[Reverted on '.now()->toDateTimeString()." by User #{$revertedBy}: {$reason}]"),
            ]);

            AuditLogService::log(
                event: 'fee_schedule_reverted',
                description: "Reverted fee schedule '{$model->title}' ({$model->schedule_no}). Cancelled {$cancelledCount} pending invoices, adjusted {$adjustedCount} partial invoices. Reason: {$reason}",
                auditable: $model,
                userId: $revertedBy
            );

            return [
                'success' => true,
                'cancelled_count' => $cancelledCount,
                'adjusted_count' => $adjustedCount,
                'schedule' => $model,
            ];
        });
    }

    /**
     * Delete an uncollected fee schedule along with its invoices and ledger debits.
     *
     * @return array{success: bool, deleted_invoices_count: int, schedule_no: string}
     */
    public static function deleteSchedule(int|FeeSchedule $schedule, ?int $deletedBy = null): array
    {
        $model = $schedule instanceof FeeSchedule ? $schedule : FeeSchedule::findOrFail($schedule);
        $deletedBy = $deletedBy ?? auth()->id();

        if (! $model->canBeDeleted()) {
            throw ValidationException::withMessages([
                'schedule' => __('Cannot delete this fee schedule because ₹:amt has already been collected against it. Please use the Revert / Cancel option instead.', [
                    'amt' => number_format($model->total_collected_amount, 2),
                ]),
            ]);
        }

        return DB::transaction(function () use ($model, $deletedBy) {
            $invoices = $model->invoices()->get();
            $deletedInvoiceCount = $invoices->count();

            foreach ($invoices as $inv) {
                // Remove ledger debit if present
                StudentLedger::where('reference_type', StudentLedger::REF_INVOICE)
                    ->where('reference_id', $inv->id)
                    ->delete();

                $inv->delete();
            }

            $scheduleNo = $model->schedule_no;
            $title = $model->title;
            $model->delete();

            AuditLogService::log(
                event: 'fee_schedule_deleted',
                description: "Deleted fee schedule '{$title}' ({$scheduleNo}) along with {$deletedInvoiceCount} uncollected invoices.",
                auditable: $model,
                userId: $deletedBy
            );

            return [
                'success' => true,
                'deleted_invoices_count' => $deletedInvoiceCount,
                'schedule_no' => $scheduleNo,
            ];
        });
    }
}
