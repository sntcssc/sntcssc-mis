<?php

namespace App\Services\Payment;

use App\Models\AppNotification;
use App\Models\FeeType;
use App\Models\LibraryDeposit;
use App\Models\PaymentTransaction;
use App\Models\StudentLedger;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Library\LibraryClearanceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LibraryDepositService
{
    /**
     * Create or record a library security deposit directly.
     *
     * @param  array{
     *     user_id: int,
     *     student_id?: string|null,
     *     amount: float,
     *     payment_method: string,
     *     payment_reference?: string|null,
     *     remarks?: string|null
     * }  $data
     */
    public static function createDirectDeposit(array $data, int $recordedBy): LibraryDeposit
    {
        $user = User::findOrFail($data['user_id']);
        $amount = (float) $data['amount'];
        $feeType = FeeType::where('code', 'library_security')->first();

        return DB::transaction(function () use ($data, $user, $amount, $feeType, $recordedBy) {
            // Create payment transaction
            $transaction = PaymentTransaction::create([
                'transaction_no' => PaymentTransaction::generateTransactionNo(),
                'user_id' => $user->id,
                'student_id' => $data['student_id'] ?? $user->student_id,
                'fee_type_id' => $feeType?->id,
                'amount' => $amount,
                'currency' => 'INR',
                'gateway' => PaymentTransaction::GATEWAY_OFFLINE,
                'payment_method' => $data['payment_method'],
                'status' => PaymentTransaction::STATUS_SUCCESSFUL,
                'is_offline' => true,
                'offline_reference_id' => $data['payment_reference'] ?? 'CASH-OFFICE',
                'offline_payment_date' => now()->toDateString(),
                'verified_by' => $recordedBy,
                'verified_at' => now(),
                'receipt_no' => PaymentTransaction::generateReceiptNo(),
                'receipt_generated_at' => now(),
                'admin_remarks' => $data['remarks'] ?? 'Direct office library security deposit collection.',
                'created_by' => $recordedBy,
            ]);

            // Credit Student Ledger for deposit receipt
            StudentLedger::recordCredit(
                userId: $user->id,
                amount: $amount,
                referenceType: StudentLedger::REF_DEPOSIT,
                referenceId: $transaction->id,
                description: "Refundable Library Security Deposit ({$transaction->receipt_no})",
                studentId: $transaction->student_id,
                createdBy: $recordedBy
            );

            // Create active LibraryDeposit record
            $deposit = LibraryDeposit::create([
                'deposit_no' => LibraryDeposit::generateDepositNo(),
                'user_id' => $user->id,
                'student_id' => $transaction->student_id,
                'amount' => $amount,
                'payment_transaction_id' => $transaction->id,
                'deposit_date' => now()->toDateString(),
                'status' => LibraryDeposit::STATUS_ACTIVE,
                'created_by' => $recordedBy,
            ]);

            AuditLogService::log(
                event: 'library_deposit_created',
                description: "Collected library security deposit {$deposit->deposit_no} of ₹{$amount} for Student {$user->name}.",
                auditable: $deposit,
                userId: $recordedBy
            );

            return $deposit;
        });
    }

    /**
     * Submit a request for refund of library security deposit.
     * Can be invoked by student self-service or by admin on behalf of student.
     *
     * @param  array{
     *     refund_reason: string,
     *     bank_name?: string|null,
     *     account_number?: string|null,
     *     ifsc_code?: string|null,
     *     account_holder_name?: string|null,
     *     upi_id?: string|null,
     * }  $bankDetails
     */
    public static function requestRefund(
        int|LibraryDeposit $deposit,
        int $requestedBy,
        array $bankDetails
    ): LibraryDeposit {
        $model = $deposit instanceof LibraryDeposit ? $deposit : LibraryDeposit::findOrFail($deposit);

        if (! $model->canRequestRefund()) {
            throw ValidationException::withMessages([
                'status' => __('Only active library deposits can be submitted for refund.'),
            ]);
        }

        $eligibility = LibraryClearanceService::checkEligibility($model->user_id);
        if (! $eligibility['eligible']) {
            throw ValidationException::withMessages([
                'books' => $eligibility['blocking_reason'],
            ]);
        }

        if (empty($bankDetails['upi_id']) && (empty($bankDetails['account_number']) || empty($bankDetails['ifsc_code']))) {
            throw ValidationException::withMessages([
                'bank_details' => __('Please provide either valid Bank Account with IFSC code or a UPI VPA for refund disbursement.'),
            ]);
        }

        return DB::transaction(function () use ($model, $requestedBy, $bankDetails) {
            $model->update([
                'status' => LibraryDeposit::STATUS_REFUND_REQUESTED,
                'clearance_status' => LibraryDeposit::CLEARANCE_PENDING,
                'refund_requested_at' => now(),
                'refund_requested_by' => $requestedBy,
                'refund_reason' => $bankDetails['refund_reason'],
                'bank_name' => $bankDetails['bank_name'] ?? null,
                'account_number' => $bankDetails['account_number'] ?? null,
                'ifsc_code' => ! empty($bankDetails['ifsc_code']) ? strtoupper(trim($bankDetails['ifsc_code'])) : null,
                'account_holder_name' => $bankDetails['account_holder_name'] ?? null,
                'upi_id' => $bankDetails['upi_id'] ?? null,
            ]);

            AppNotification::create([
                'user_id' => $model->user_id,
                'type' => 'library_refund_requested',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Library Deposit Refund Requested'),
                'message' => __('Your security deposit refund request (:dep) for ₹:amount has been submitted for review.', [
                    'dep' => $model->deposit_no,
                    'amount' => number_format($model->amount, 2),
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'deposit_id' => $model->id,
                    'deposit_no' => $model->deposit_no,
                ],
            ]);

            AuditLogService::log(
                event: 'library_refund_requested',
                description: "Library security deposit refund requested for {$model->deposit_no} (₹{$model->amount}).",
                auditable: $model,
                userId: $requestedBy
            );

            return $model;
        });
    }

    /**
     * Approve library security deposit refund.
     */
    public static function approveRefund(
        int|LibraryDeposit $deposit,
        int $reviewedBy,
        ?float $approvedAmount = null,
        ?string $adminNotes = null
    ): LibraryDeposit {
        $model = $deposit instanceof LibraryDeposit ? $deposit : LibraryDeposit::findOrFail($deposit);

        if ($model->clearance_status !== LibraryDeposit::CLEARANCE_CLEARED) {
            throw ValidationException::withMessages([
                'clearance' => __('Cannot approve refund for deposit :dep: Librarian clearance certificate has not been issued yet. The Librarian must verify book returns and issue a clearance certificate before Admin can approve this refund.', [
                    'dep' => $model->deposit_no,
                ]),
            ]);
        }

        return DB::transaction(function () use ($model, $reviewedBy, $approvedAmount, $adminNotes) {
            $finalAmount = $approvedAmount !== null ? $approvedAmount : (float) $model->amount;

            $model->update([
                'status' => LibraryDeposit::STATUS_REFUND_APPROVED,
                'refund_reviewed_by' => $reviewedBy,
                'refund_reviewed_at' => now(),
                'refund_approved_amount' => $finalAmount,
                'admin_notes' => $adminNotes,
            ]);

            AppNotification::create([
                'user_id' => $model->user_id,
                'type' => 'library_refund_approved',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Library Refund Approved'),
                'message' => __('Your library deposit refund (:dep) of ₹:amount has been approved and queued for payment processing.', [
                    'dep' => $model->deposit_no,
                    'amount' => number_format($finalAmount, 2),
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => ['deposit_id' => $model->id],
            ]);

            AuditLogService::log(
                event: 'library_refund_approved',
                description: "Approved library deposit refund {$model->deposit_no} for ₹{$finalAmount}.",
                auditable: $model,
                userId: $reviewedBy
            );

            return $model;
        });
    }

    /**
     * Reject library deposit refund request.
     */
    public static function rejectRefund(
        int|LibraryDeposit $deposit,
        int $reviewedBy,
        string $rejectionNotes
    ): LibraryDeposit {
        $model = $deposit instanceof LibraryDeposit ? $deposit : LibraryDeposit::findOrFail($deposit);

        return DB::transaction(function () use ($model, $reviewedBy, $rejectionNotes) {
            $model->update([
                'status' => LibraryDeposit::STATUS_REFUND_REJECTED,
                'refund_reviewed_by' => $reviewedBy,
                'refund_reviewed_at' => now(),
                'admin_notes' => $rejectionNotes,
            ]);

            AppNotification::create([
                'user_id' => $model->user_id,
                'type' => 'library_refund_rejected',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Library Refund Request Rejected'),
                'message' => __('Your library deposit refund request (:dep) was declined. Notes: :notes', [
                    'dep' => $model->deposit_no,
                    'notes' => $rejectionNotes,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => ['deposit_id' => $model->id],
            ]);

            AuditLogService::log(
                event: 'library_refund_rejected',
                description: "Rejected library deposit refund {$model->deposit_no}. Reason: {$rejectionNotes}",
                auditable: $model,
                userId: $reviewedBy
            );

            return $model;
        });
    }

    /**
     * Formally complete and disburse library security deposit refund.
     */
    public static function processDisbursement(
        int|LibraryDeposit $deposit,
        int $processedBy,
        string $transactionRef,
        ?UploadedFile $proofFile = null,
        ?string $adminNotes = null
    ): LibraryDeposit {
        $model = $deposit instanceof LibraryDeposit ? $deposit : LibraryDeposit::findOrFail($deposit);

        return DB::transaction(function () use ($model, $processedBy, $transactionRef, $proofFile, $adminNotes) {
            $proofPath = null;
            if ($proofFile) {
                $proofPath = $proofFile->store('attachments/refunds', 'public');
            }

            $refundAmount = $model->refund_approved_amount ?: $model->amount;

            $model->update([
                'status' => LibraryDeposit::STATUS_REFUND_PROCESSED,
                'refund_processed_by' => $processedBy,
                'refund_processed_at' => now(),
                'refund_transaction_ref' => $transactionRef,
                'refund_receipt_path' => $proofPath ?: $model->refund_receipt_path,
                'admin_notes' => $adminNotes ?: $model->admin_notes,
            ]);

            // Record Debit in Student Ledger for refund payout
            StudentLedger::recordDebit(
                userId: $model->user_id,
                amount: (float) $refundAmount,
                referenceType: StudentLedger::REF_REFUND,
                referenceId: $model->id,
                description: "Library Security Deposit Refund Disbursed ({$model->deposit_no}, Ref: {$transactionRef})",
                studentId: $model->student_id,
                createdBy: $processedBy
            );

            AppNotification::create([
                'user_id' => $model->user_id,
                'type' => 'library_refund_disbursed',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Library Security Deposit Refunded'),
                'message' => __('₹:amount has been credited to your bank account/UPI. Payment Ref / UTR: :ref', [
                    'amount' => number_format($refundAmount, 2),
                    'ref' => $transactionRef,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'deposit_id' => $model->id,
                    'transaction_ref' => $transactionRef,
                ],
            ]);

            AuditLogService::log(
                event: 'library_refund_disbursed',
                description: "Disbursed library deposit refund {$model->deposit_no} of ₹{$refundAmount} (UTR: {$transactionRef}).",
                auditable: $model,
                userId: $processedBy
            );

            return $model;
        });
    }

    /**
     * Submit refund requests for multiple library security deposits at once.
     *
     * @param  int[]  $depositIds
     * @param  array{
     *     refund_reason: string,
     *     bank_name?: string|null,
     *     account_number?: string|null,
     *     ifsc_code?: string|null,
     *     account_holder_name?: string|null,
     *     upi_id?: string|null,
     * }  $bankDetails
     * @return Collection<int, LibraryDeposit>
     */
    public static function requestRefundMultiple(
        array $depositIds,
        int $requestedBy,
        array $bankDetails
    ): Collection {
        if (empty($depositIds)) {
            throw ValidationException::withMessages([
                'deposits' => __('Please select at least one active library security deposit to request a refund.'),
            ]);
        }

        if (empty($bankDetails['upi_id']) && (empty($bankDetails['account_number']) || empty($bankDetails['ifsc_code']))) {
            throw ValidationException::withMessages([
                'bank_details' => __('Please provide either valid Bank Account with IFSC code or a UPI VPA for refund disbursement.'),
            ]);
        }

        $deposits = LibraryDeposit::whereIn('id', $depositIds)->get();

        if ($deposits->isEmpty()) {
            throw ValidationException::withMessages([
                'deposits' => __('No valid library deposits found for the selected IDs.'),
            ]);
        }

        $invalid = $deposits->filter(fn (LibraryDeposit $d) => ! $d->canRequestRefund());
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'deposits' => __('One or more selected deposits cannot be submitted for refund because they are not currently active.'),
            ]);
        }

        foreach ($deposits as $deposit) {
            $eligibility = LibraryClearanceService::checkEligibility($deposit->user_id);
            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'books' => $eligibility['blocking_reason'],
                ]);
            }
        }

        return DB::transaction(function () use ($deposits, $requestedBy, $bankDetails) {
            $formattedIfsc = ! empty($bankDetails['ifsc_code']) ? strtoupper(trim($bankDetails['ifsc_code'])) : null;

            foreach ($deposits as $deposit) {
                $deposit->update([
                    'status' => LibraryDeposit::STATUS_REFUND_REQUESTED,
                    'clearance_status' => LibraryDeposit::CLEARANCE_PENDING,
                    'refund_requested_at' => now(),
                    'refund_requested_by' => $requestedBy,
                    'refund_reason' => $bankDetails['refund_reason'],
                    'bank_name' => $bankDetails['bank_name'] ?? null,
                    'account_number' => $bankDetails['account_number'] ?? null,
                    'ifsc_code' => $formattedIfsc,
                    'account_holder_name' => $bankDetails['account_holder_name'] ?? null,
                    'upi_id' => $bankDetails['upi_id'] ?? null,
                ]);

                AuditLogService::log(
                    event: 'library_refund_requested',
                    description: "Library security deposit refund requested for {$deposit->deposit_no} (₹{$deposit->amount}).",
                    auditable: $deposit,
                    userId: $requestedBy
                );
            }

            $byUser = $deposits->groupBy('user_id');
            foreach ($byUser as $userId => $userDeposits) {
                $count = $userDeposits->count();
                $totalAmt = $userDeposits->sum('amount');
                $depList = $userDeposits->pluck('deposit_no')->join(', ');

                AppNotification::create([
                    'user_id' => $userId,
                    'type' => 'library_refund_requested',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Library Deposit Refund Requested'),
                    'message' => __('Your security deposit refund request for :count deposit(s) (:deps) totaling ₹:amount has been submitted for clearance review.', [
                        'count' => $count,
                        'deps' => $depList,
                        'amount' => number_format($totalAmt, 2),
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'deposit_ids' => $userDeposits->pluck('id')->all(),
                        'total_amount' => $totalAmt,
                    ],
                ]);
            }

            return $deposits;
        });
    }

    /**
     * Batch approve library security deposit refund claims.
     *
     * @param  int[]  $depositIds
     * @return Collection<int, LibraryDeposit>
     */
    public static function approveRefundMultiple(
        array $depositIds,
        int $reviewedBy,
        ?string $adminNotes = null
    ): Collection {
        if (empty($depositIds)) {
            throw ValidationException::withMessages([
                'deposits' => __('Please select at least one refund request to approve.'),
            ]);
        }

        $deposits = LibraryDeposit::whereIn('id', $depositIds)
            ->where('status', LibraryDeposit::STATUS_REFUND_REQUESTED)
            ->get();

        if ($deposits->isEmpty()) {
            throw ValidationException::withMessages([
                'deposits' => __('No pending refund requests found for the selected IDs.'),
            ]);
        }

        $notCleared = $deposits->filter(fn (LibraryDeposit $d) => $d->clearance_status !== LibraryDeposit::CLEARANCE_CLEARED);
        if ($notCleared->isNotEmpty()) {
            throw ValidationException::withMessages([
                'clearance' => __('Cannot approve refund: :count of the selected deposits do not have an official Library Clearance Certificate issued by the Librarian yet. All books must be returned and verified before approval.', [
                    'count' => $notCleared->count(),
                ]),
            ]);
        }

        return DB::transaction(function () use ($deposits, $reviewedBy, $adminNotes) {
            foreach ($deposits as $deposit) {
                $finalAmount = (float) $deposit->amount;

                $deposit->update([
                    'status' => LibraryDeposit::STATUS_REFUND_APPROVED,
                    'refund_reviewed_by' => $reviewedBy,
                    'refund_reviewed_at' => now(),
                    'refund_approved_amount' => $finalAmount,
                    'admin_notes' => $adminNotes ?: $deposit->admin_notes,
                ]);

                AuditLogService::log(
                    event: 'library_refund_approved',
                    description: "Approved library deposit refund {$deposit->deposit_no} for ₹{$finalAmount}.",
                    auditable: $deposit,
                    userId: $reviewedBy
                );
            }

            $byUser = $deposits->groupBy('user_id');
            foreach ($byUser as $userId => $userDeposits) {
                $totalAmt = $userDeposits->sum('amount');
                $count = $userDeposits->count();

                AppNotification::create([
                    'user_id' => $userId,
                    'type' => 'library_refund_approved',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Library Refund Approved'),
                    'message' => __('Your library deposit refund request for :count deposit(s) totaling ₹:amount has been approved and queued for payment processing.', [
                        'count' => $count,
                        'amount' => number_format($totalAmt, 2),
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'deposit_ids' => $userDeposits->pluck('id')->all(),
                    ],
                ]);
            }

            return $deposits;
        });
    }

    /**
     * Batch reject library security deposit refund claims.
     *
     * @param  int[]  $depositIds
     * @return Collection<int, LibraryDeposit>
     */
    public static function rejectRefundMultiple(
        array $depositIds,
        int $reviewedBy,
        string $rejectionNotes
    ): Collection {
        if (empty($depositIds)) {
            throw ValidationException::withMessages([
                'deposits' => __('Please select at least one refund request to reject.'),
            ]);
        }

        $deposits = LibraryDeposit::whereIn('id', $depositIds)
            ->where('status', LibraryDeposit::STATUS_REFUND_REQUESTED)
            ->get();

        if ($deposits->isEmpty()) {
            throw ValidationException::withMessages([
                'deposits' => __('No pending refund requests found for the selected IDs.'),
            ]);
        }

        return DB::transaction(function () use ($deposits, $reviewedBy, $rejectionNotes) {
            foreach ($deposits as $deposit) {
                $deposit->update([
                    'status' => LibraryDeposit::STATUS_REFUND_REJECTED,
                    'refund_reviewed_by' => $reviewedBy,
                    'refund_reviewed_at' => now(),
                    'admin_notes' => $rejectionNotes,
                ]);

                AuditLogService::log(
                    event: 'library_refund_rejected',
                    description: "Rejected library deposit refund {$deposit->deposit_no}. Reason: {$rejectionNotes}",
                    auditable: $deposit,
                    userId: $reviewedBy
                );
            }

            $byUser = $deposits->groupBy('user_id');
            foreach ($byUser as $userId => $userDeposits) {
                $count = $userDeposits->count();
                AppNotification::create([
                    'user_id' => $userId,
                    'type' => 'library_refund_rejected',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Library Refund Request Declined'),
                    'message' => __('Your library deposit refund request for :count deposit(s) was declined. Notes: :notes', [
                        'count' => $count,
                        'notes' => $rejectionNotes,
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'deposit_ids' => $userDeposits->pluck('id')->all(),
                    ],
                ]);
            }

            return $deposits;
        });
    }

    /**
     * Formally complete and disburse refunds for multiple library security deposits at once.
     *
     * @param  int[]  $depositIds
     * @return Collection<int, LibraryDeposit>
     */
    public static function processDisbursementMultiple(
        array $depositIds,
        int $processedBy,
        string $transactionRef,
        ?UploadedFile $proofFile = null,
        ?string $adminNotes = null
    ): Collection {
        if (empty($depositIds)) {
            throw ValidationException::withMessages([
                'deposits' => __('Please select at least one approved refund to disburse.'),
            ]);
        }

        $deposits = LibraryDeposit::whereIn('id', $depositIds)
            ->where('status', LibraryDeposit::STATUS_REFUND_APPROVED)
            ->get();

        if ($deposits->isEmpty()) {
            throw ValidationException::withMessages([
                'deposits' => __('No approved refund requests ready for disbursement found for the selected IDs.'),
            ]);
        }

        return DB::transaction(function () use ($deposits, $processedBy, $transactionRef, $proofFile, $adminNotes) {
            $proofPath = null;
            if ($proofFile) {
                $proofPath = $proofFile->store('attachments/refunds', 'public');
            }

            foreach ($deposits as $deposit) {
                $refundAmount = $deposit->refund_approved_amount ?: $deposit->amount;

                $deposit->update([
                    'status' => LibraryDeposit::STATUS_REFUND_PROCESSED,
                    'refund_processed_by' => $processedBy,
                    'refund_processed_at' => now(),
                    'refund_transaction_ref' => $transactionRef,
                    'refund_receipt_path' => $proofPath ?: $deposit->refund_receipt_path,
                    'admin_notes' => $adminNotes ?: $deposit->admin_notes,
                ]);

                // Record Debit in Student Ledger for refund payout
                StudentLedger::recordDebit(
                    userId: $deposit->user_id,
                    amount: (float) $refundAmount,
                    referenceType: StudentLedger::REF_REFUND,
                    referenceId: $deposit->id,
                    description: "Library Security Deposit Refund Disbursed ({$deposit->deposit_no}, Ref: {$transactionRef})",
                    studentId: $deposit->student_id,
                    createdBy: $processedBy
                );

                AuditLogService::log(
                    event: 'library_refund_disbursed',
                    description: "Disbursed library deposit refund {$deposit->deposit_no} of ₹{$refundAmount} (UTR: {$transactionRef}).",
                    auditable: $deposit,
                    userId: $processedBy
                );
            }

            $byUser = $deposits->groupBy('user_id');
            foreach ($byUser as $userId => $userDeposits) {
                $totalAmt = $userDeposits->sum(fn (LibraryDeposit $d) => $d->refund_approved_amount ?: $d->amount);
                $count = $userDeposits->count();

                AppNotification::create([
                    'user_id' => $userId,
                    'type' => 'library_refund_disbursed',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Library Security Deposit Refunded'),
                    'message' => __('Refund of ₹:amount for :count deposit(s) has been credited to your bank account/UPI. Payment Ref / UTR: :ref', [
                        'amount' => number_format($totalAmt, 2),
                        'count' => $count,
                        'ref' => $transactionRef,
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'deposit_ids' => $userDeposits->pluck('id')->all(),
                        'transaction_ref' => $transactionRef,
                    ],
                ]);
            }

            return $deposits;
        });
    }
}
