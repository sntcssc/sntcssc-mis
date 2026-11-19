<?php

namespace App\Services\Payment;

use App\Models\AppNotification;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\LibraryDeposit;
use App\Models\PaymentTransaction;
use App\Models\StudentFeeInvoice;
use App\Models\StudentLedger;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Payment\Gateways\CCAVenueGateway;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeePaymentService
{
    /**
     * Submit an offline fee payment with transaction details and proof attachment.
     *
     * @param  array{
     *     invoice_id?: int|null,
     *     fee_type_id?: int|null,
     *     user_id: int,
     *     student_id?: string|null,
     *     amount: float,
     *     payment_method: string,
     *     offline_reference_id: string,
     *     offline_payment_date: string,
     *     offline_payment_time?: string|null,
     *     user_remarks?: string|null,
     * }  $data
     */
    public static function recordOfflinePayment(
        array $data,
        ?UploadedFile $proofFile = null,
        ?int $recordedBy = null
    ): PaymentTransaction {
        return DB::transaction(function () use ($data, $proofFile, $recordedBy) {
            $invoice = ! empty($data['invoice_id']) ? StudentFeeInvoice::find($data['invoice_id']) : null;
            $feeTypeId = $data['fee_type_id'] ?? $invoice?->fee_type_id;
            $feeType = $feeTypeId ? FeeType::find($feeTypeId) : ($invoice?->feeType);

            // Enforce: One student can make payment against a month only once in case of tuition fees
            if ($feeType && $feeType->code === 'tuition') {
                $month = (int) ($invoice?->period_month ?? $data['period_month'] ?? 0);
                $year = (int) ($invoice?->period_year ?? $data['period_year'] ?? date('Y'));

                if ($invoice && $invoice->status === StudentFeeInvoice::STATUS_PAID) {
                    throw ValidationException::withMessages([
                        'invoice' => __('Tuition fee for this month has already been paid.'),
                    ]);
                }

                if ($month > 0 && $year > 0) {
                    $alreadyPaid = StudentFeeInvoice::where('user_id', $data['user_id'])
                        ->where('fee_type_id', $feeType->id)
                        ->where('period_month', $month)
                        ->where('period_year', $year)
                        ->where('status', StudentFeeInvoice::STATUS_PAID)
                        ->when($invoice, fn ($q) => $q->where('id', '!=', $invoice->id))
                        ->exists();

                    if ($alreadyPaid) {
                        $monthName = Carbon::create(null, $month, 1)->format('F');
                        throw ValidationException::withMessages([
                            'invoice' => __('Tuition fee for :month :year has already been paid for this student. Only one tuition fee payment is allowed per month.', [
                                'month' => $monthName,
                                'year' => $year,
                            ]),
                        ]);
                    }
                }
            }

            $proofPath = null;
            if ($proofFile) {
                $proofPath = $proofFile->store('attachments/payments', 'public');
            }

            $transaction = PaymentTransaction::create([
                'transaction_no' => PaymentTransaction::generateTransactionNo(),
                'invoice_id' => $invoice?->id,
                'user_id' => $data['user_id'],
                'student_id' => $data['student_id'] ?? $invoice?->student_id,
                'fee_type_id' => $feeTypeId,
                'amount' => (float) $data['amount'],
                'currency' => 'INR',
                'gateway' => PaymentTransaction::GATEWAY_OFFLINE,
                'payment_method' => $data['payment_method'],
                'status' => PaymentTransaction::STATUS_UNDER_REVIEW,
                'is_offline' => true,
                'offline_reference_id' => $data['offline_reference_id'],
                'offline_payment_date' => $data['offline_payment_date'],
                'offline_payment_time' => $data['offline_payment_time'] ?? null,
                'user_remarks' => $data['user_remarks'] ?? null,
                'proof_document_path' => $proofPath,
                'created_by' => $recordedBy ?? $data['user_id'],
            ]);

            // Notify staff about new payment under review
            AuditLogService::log(
                event: 'offline_payment_submitted',
                description: "Student submitted offline payment {$transaction->transaction_no} of ₹{$transaction->amount} (Ref: {$transaction->offline_reference_id}) for review.",
                auditable: $transaction,
                userId: $recordedBy ?? $data['user_id']
            );

            return $transaction;
        });
    }

    /**
     * Review and verify an offline payment transaction.
     *
     * @param  array{
     *     amount?: float,
     *     payment_method?: string,
     *     offline_reference_id?: string,
     *     offline_payment_date?: string,
     *     admin_remarks?: string|null
     * }|null  $updatedFields
     */
    public static function verifyOfflinePayment(
        int|PaymentTransaction $transaction,
        bool $approved,
        int $verifiedBy,
        ?string $adminRemarks = null,
        ?array $updatedFields = null
    ): PaymentTransaction {
        $model = $transaction instanceof PaymentTransaction ? $transaction : PaymentTransaction::findOrFail($transaction);

        if (! $model->is_offline && $model->isOnline()) {
            throw ValidationException::withMessages([
                'gateway' => __('Online gateway payments are automated and their financial core parameters cannot be altered manually.'),
            ]);
        }

        return DB::transaction(function () use ($model, $approved, $verifiedBy, $adminRemarks, $updatedFields) {
            // Authorized staff can update offline payment metadata
            if (! empty($updatedFields) && is_array($updatedFields)) {
                $allowed = ['amount', 'payment_method', 'offline_reference_id', 'offline_payment_date', 'user_remarks'];
                foreach ($allowed as $f) {
                    if (isset($updatedFields[$f])) {
                        $model->{$f} = $updatedFields[$f];
                    }
                }
            }

            $model->verified_by = $verifiedBy;
            $model->verified_at = now();
            $model->admin_remarks = $adminRemarks;

            if ($approved) {
                $model->status = PaymentTransaction::STATUS_SUCCESSFUL;
                $model->receipt_no = PaymentTransaction::generateReceiptNo();
                $model->receipt_generated_at = now();
                $model->save();

                // 1. Credit the Student's Ledger
                StudentLedger::recordCredit(
                    userId: $model->user_id,
                    amount: (float) $model->amount,
                    referenceType: StudentLedger::REF_PAYMENT,
                    referenceId: $model->id,
                    description: "Payment verified ({$model->receipt_no}) via ".strtoupper(str_replace('_', ' ', $model->payment_method)),
                    studentId: $model->student_id,
                    createdBy: $verifiedBy
                );

                // 2. Settle linked StudentFeeInvoice
                if ($model->invoice_id && $model->invoice) {
                    $invoice = $model->invoice;
                    $newPaid = (float) $invoice->paid_amount + (float) $model->amount;
                    $newBalance = max(0.00, (float) $invoice->total_amount - $newPaid);
                    $newStatus = $newBalance <= 0.00 ? StudentFeeInvoice::STATUS_PAID : StudentFeeInvoice::STATUS_PARTIAL;

                    $invoice->update([
                        'paid_amount' => $newPaid,
                        'balance_amount' => $newBalance,
                        'status' => $newStatus,
                        'paid_at' => $newStatus === StudentFeeInvoice::STATUS_PAID ? now() : $invoice->paid_at,
                    ]);

                    // Update Enrollment fee_status if fully cleared
                    if ($invoice->enrollment) {
                        $hasRemaining = StudentFeeInvoice::where('enrollment_id', $invoice->enrollment_id)
                            ->where('status', '!=', StudentFeeInvoice::STATUS_PAID)
                            ->where('status', '!=', StudentFeeInvoice::STATUS_WAIVED)
                            ->exists();

                        if (! $hasRemaining) {
                            $invoice->enrollment->update(['fee_status' => 'paid']);
                        }
                    }

                    // Update FeeSchedule collected aggregate
                    if ($invoice->fee_schedule_id && $invoice->feeSchedule) {
                        $invoice->feeSchedule->increment('total_collected_amount', (float) $model->amount);
                    }
                }

                // 3. If this payment is for Library Security Deposit, activate or record LibraryDeposit
                $feeType = $model->feeType ?: ($model->invoice?->feeType);
                if ($feeType && $feeType->code === 'library_security') {
                    $existingDeposit = LibraryDeposit::where('payment_transaction_id', $model->id)->first();
                    LibraryDeposit::updateOrCreate(
                        ['payment_transaction_id' => $model->id],
                        [
                            'deposit_no' => $existingDeposit?->deposit_no ?: LibraryDeposit::generateDepositNo(),
                            'user_id' => $model->user_id,
                            'student_id' => $model->student_id,
                            'amount' => $model->amount,
                            'deposit_date' => $model->offline_payment_date ?: now()->toDateString(),
                            'status' => LibraryDeposit::STATUS_ACTIVE,
                            'created_by' => $verifiedBy,
                        ]
                    );
                }

                // Notify student
                AppNotification::create([
                    'user_id' => $model->user_id,
                    'type' => 'payment_verified',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Payment Verified & Receipt Generated'),
                    'message' => __('Your payment of ₹:amount has been approved. Receipt No: :receipt.', [
                        'amount' => number_format($model->amount, 2),
                        'receipt' => $model->receipt_no,
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'transaction_id' => $model->id,
                        'receipt_no' => $model->receipt_no,
                        'amount' => $model->amount,
                    ],
                ]);

                AuditLogService::log(
                    event: 'offline_payment_approved',
                    description: "Approved payment {$model->transaction_no} of ₹{$model->amount}. Issued receipt {$model->receipt_no}.",
                    auditable: $model,
                    userId: $verifiedBy
                );
            } else {
                $model->status = PaymentTransaction::STATUS_REJECTED;
                $model->save();

                AppNotification::create([
                    'user_id' => $model->user_id,
                    'type' => 'payment_rejected',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Payment Verification Rejected'),
                    'message' => __('Your payment submission (:txn) was declined. Remarks: :remarks', [
                        'txn' => $model->transaction_no,
                        'remarks' => $adminRemarks ?: __('Could not verify bank transfer reference.'),
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'transaction_id' => $model->id,
                    ],
                ]);

                AuditLogService::log(
                    event: 'offline_payment_rejected',
                    description: "Rejected payment {$model->transaction_no} of ₹{$model->amount}. Remarks: {$adminRemarks}",
                    auditable: $model,
                    userId: $verifiedBy
                );
            }

            return $model;
        });
    }

    /**
     * Process automated online payment success.
     */
    public static function completeOnlinePayment(
        PaymentTransaction $transaction,
        string $gatewayPaymentId,
        ?string $signature = null,
        ?array $responsePayload = null
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $gatewayPaymentId, $signature, $responsePayload) {
            $invoice = $transaction->invoice;
            $feeType = $transaction->feeType ?: ($invoice?->feeType);

            // Enforce: One student can make payment against a month only once in case of tuition fees
            if ($feeType && $feeType->code === 'tuition') {
                $month = (int) ($invoice?->period_month ?? 0);
                $year = (int) ($invoice?->period_year ?? date('Y'));

                if ($invoice && $invoice->status === StudentFeeInvoice::STATUS_PAID) {
                    throw ValidationException::withMessages([
                        'payment' => __('Tuition fee for this month has already been paid.'),
                    ]);
                }

                if ($month > 0 && $year > 0) {
                    $alreadyPaid = StudentFeeInvoice::where('user_id', $transaction->user_id)
                        ->where('fee_type_id', $feeType->id)
                        ->where('period_month', $month)
                        ->where('period_year', $year)
                        ->where('status', StudentFeeInvoice::STATUS_PAID)
                        ->when($invoice, fn ($q) => $q->where('id', '!=', $invoice->id))
                        ->exists();

                    if ($alreadyPaid) {
                        $monthName = Carbon::create(null, $month, 1)->format('F');
                        throw ValidationException::withMessages([
                            'payment' => __('Tuition fee for :month :year has already been paid for this student. Only one tuition fee payment is allowed per month.', [
                                'month' => $monthName,
                                'year' => $year,
                            ]),
                        ]);
                    }
                }
            }

            $receiptNo = $transaction->receipt_no ?: PaymentTransaction::generateReceiptNo();
            $transaction->update([
                'status' => PaymentTransaction::STATUS_SUCCESSFUL,
                'gateway_payment_id' => $gatewayPaymentId,
                'gateway_signature' => $signature,
                'gateway_response' => $responsePayload,
                'receipt_no' => $receiptNo,
                'receipt_generated_at' => $transaction->receipt_generated_at ?: now(),
            ]);

            // 1. Post Credit to Student Ledger
            StudentLedger::recordCredit(
                userId: $transaction->user_id,
                amount: (float) $transaction->amount,
                referenceType: StudentLedger::REF_PAYMENT,
                referenceId: $transaction->id,
                description: "Online Payment ({$transaction->receipt_no}) via ".strtoupper($transaction->gateway),
                studentId: $transaction->student_id
            );

            // 2. Settle linked StudentFeeInvoice
            if ($transaction->invoice_id && $transaction->invoice) {
                $invoice = $transaction->invoice;
                $newPaid = (float) $invoice->paid_amount + (float) $transaction->amount;
                $newBalance = max(0.00, (float) $invoice->total_amount - $newPaid);
                $newStatus = $newBalance <= 0.00 ? StudentFeeInvoice::STATUS_PAID : StudentFeeInvoice::STATUS_PARTIAL;

                $invoice->update([
                    'paid_amount' => $newPaid,
                    'balance_amount' => $newBalance,
                    'status' => $newStatus,
                    'paid_at' => $newStatus === StudentFeeInvoice::STATUS_PAID ? now() : $invoice->paid_at,
                ]);

                if ($invoice->enrollment) {
                    $hasRemaining = StudentFeeInvoice::where('enrollment_id', $invoice->enrollment_id)
                        ->where('status', '!=', StudentFeeInvoice::STATUS_PAID)
                        ->where('status', '!=', StudentFeeInvoice::STATUS_WAIVED)
                        ->exists();

                    if (! $hasRemaining) {
                        $invoice->enrollment->update(['fee_status' => 'paid']);
                    }
                }

                if ($invoice->fee_schedule_id && $invoice->feeSchedule) {
                    $invoice->feeSchedule->increment('total_collected_amount', (float) $transaction->amount);
                }
            }

            // 3. Check for Library Security Deposit
            $feeType = $transaction->feeType ?: ($transaction->invoice?->feeType);
            if ($feeType && $feeType->code === 'library_security') {
                $existingDeposit = LibraryDeposit::where('payment_transaction_id', $transaction->id)->first();
                LibraryDeposit::updateOrCreate(
                    ['payment_transaction_id' => $transaction->id],
                    [
                        'deposit_no' => $existingDeposit?->deposit_no ?: LibraryDeposit::generateDepositNo(),
                        'user_id' => $transaction->user_id,
                        'student_id' => $transaction->student_id,
                        'amount' => $transaction->amount,
                        'deposit_date' => now()->toDateString(),
                        'status' => LibraryDeposit::STATUS_ACTIVE,
                    ]
                );
            }

            // Notify student
            AppNotification::create([
                'user_id' => $transaction->user_id,
                'type' => 'payment_success',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Online Payment Successful'),
                'message' => __('Your online payment of ₹:amount was successful. Receipt No: :receipt.', [
                    'amount' => number_format($transaction->amount, 2),
                    'receipt' => $transaction->receipt_no,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'receipt_no' => $transaction->receipt_no,
                    'amount' => $transaction->amount,
                ],
            ]);

            AuditLogService::log(
                event: 'online_payment_completed',
                description: "Completed online payment {$transaction->transaction_no} of ₹{$transaction->amount} via {$transaction->gateway}. Receipt: {$transaction->receipt_no}.",
                auditable: $transaction,
                userId: $transaction->user_id
            );

            return $transaction;
        });
    }

    /**
     * Process online payment completion with gateway fee, surcharge, and bank reference details.
     *
     * @param  array{
     *     gateway_payment_id?: string|null,
     *     gateway_tracking_id?: string|null,
     *     gateway_bank_ref_no?: string|null,
     *     gateway_payment_mode?: string|null,
     *     gateway_card_name?: string|null,
     *     gateway_charged_amount?: float|null,
     *     gateway_fee?: float|null,
     *     gateway_tax?: float|null,
     *     signature?: string|null,
     *     response_payload?: array|null,
     *     payment_method?: string|null,
     * }  $data
     */
    public static function completeOnlinePaymentWithCharges(
        PaymentTransaction $transaction,
        array $data = []
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $data) {
            $transaction = PaymentTransaction::lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->status === PaymentTransaction::STATUS_SUCCESSFUL) {
                return $transaction;
            }

            $invoice = $transaction->invoice;
            $feeType = $transaction->feeType ?: ($invoice?->feeType);

            // Enforce: One student can make payment against a month only once in case of tuition fees
            if ($feeType && $feeType->code === 'tuition') {
                $month = (int) ($invoice?->period_month ?? 0);
                $year = (int) ($invoice?->period_year ?? date('Y'));

                if ($invoice && $invoice->status === StudentFeeInvoice::STATUS_PAID) {
                    throw ValidationException::withMessages([
                        'payment' => __('Tuition fee for this month has already been paid.'),
                    ]);
                }

                if ($month > 0 && $year > 0) {
                    $alreadyPaid = StudentFeeInvoice::where('user_id', $transaction->user_id)
                        ->where('fee_type_id', $feeType->id)
                        ->where('period_month', $month)
                        ->where('period_year', $year)
                        ->where('status', StudentFeeInvoice::STATUS_PAID)
                        ->when($invoice, fn ($q) => $q->where('id', '!=', $invoice->id))
                        ->exists();

                    if ($alreadyPaid) {
                        $monthName = Carbon::create(null, $month, 1)->format('F');
                        throw ValidationException::withMessages([
                            'payment' => __('Tuition fee for :month :year has already been paid for this student. Only one tuition fee payment is allowed per month.', [
                                'month' => $monthName,
                                'year' => $year,
                            ]),
                        ]);
                    }
                }
            }

            $receiptNo = $transaction->receipt_no ?: PaymentTransaction::generateReceiptNo();

            $trackingId = $data['gateway_tracking_id'] ?? $data['tracking_id'] ?? $data['gateway_payment_id'] ?? $transaction->gateway_tracking_id;
            $bankRefNo = $data['gateway_bank_ref_no'] ?? $data['bank_ref_no'] ?? $transaction->gateway_bank_ref_no;
            $paymentMode = $data['gateway_payment_mode'] ?? $data['payment_mode'] ?? null;
            $cardName = $data['gateway_card_name'] ?? $data['card_name'] ?? null;
            $chargedAmount = isset($data['gateway_charged_amount']) ? (float) $data['gateway_charged_amount'] : (isset($data['amount']) ? (float) $data['amount'] : (float) $transaction->amount);
            $feeAmount = isset($data['gateway_fee']) ? (float) $data['gateway_fee'] : (isset($data['trans_fee']) || isset($data['service_tax']) ? ((float) ($data['trans_fee'] ?? 0) + (float) ($data['service_tax'] ?? 0)) : 0.00);
            $taxAmount = isset($data['gateway_tax']) ? (float) $data['gateway_tax'] : (float) ($data['service_tax'] ?? 0.00);

            $paymentMethod = $data['payment_method']
                ?? (! empty($paymentMode) ? strtolower((string) $paymentMode) : 'online');

            $transaction->update([
                'status' => PaymentTransaction::STATUS_SUCCESSFUL,
                'payment_method' => $paymentMethod,
                'gateway_payment_id' => $data['gateway_payment_id'] ?? $trackingId,
                'gateway_tracking_id' => $trackingId,
                'gateway_bank_ref_no' => $bankRefNo,
                'gateway_payment_mode' => $paymentMode,
                'gateway_card_name' => $cardName,
                'gateway_charged_amount' => $chargedAmount,
                'gateway_fee' => $feeAmount,
                'gateway_tax' => $taxAmount,
                'gateway_signature' => $data['signature'] ?? $transaction->gateway_signature,
                'gateway_response' => $data['response_payload'] ?? ($data ?: $transaction->gateway_response),
                'amount_mismatch' => false,
                'amount_mismatch_notes' => null,
                'receipt_no' => $receiptNo,
                'receipt_generated_at' => $transaction->receipt_generated_at ?: now(),
            ]);

            // 1. Post Credit to Student Ledger with institutional base amount
            StudentLedger::recordCredit(
                userId: $transaction->user_id,
                amount: (float) $transaction->amount,
                referenceType: StudentLedger::REF_PAYMENT,
                referenceId: $transaction->id,
                description: "Online Payment ({$transaction->receipt_no}) via ".strtoupper((string) $transaction->gateway),
                studentId: $transaction->student_id
            );

            // 2. Settle linked StudentFeeInvoice
            if ($transaction->invoice_id && $transaction->invoice) {
                $invoice = $transaction->invoice;
                $newPaid = (float) $invoice->paid_amount + (float) $transaction->amount;
                $newBalance = max(0.00, (float) $invoice->total_amount - $newPaid);
                $newStatus = $newBalance <= 0.00 ? StudentFeeInvoice::STATUS_PAID : StudentFeeInvoice::STATUS_PARTIAL;

                $invoice->update([
                    'paid_amount' => $newPaid,
                    'balance_amount' => $newBalance,
                    'status' => $newStatus,
                    'paid_at' => $newStatus === StudentFeeInvoice::STATUS_PAID ? now() : $invoice->paid_at,
                ]);

                if ($invoice->enrollment) {
                    $hasRemaining = StudentFeeInvoice::where('enrollment_id', $invoice->enrollment_id)
                        ->where('status', '!=', StudentFeeInvoice::STATUS_PAID)
                        ->where('status', '!=', StudentFeeInvoice::STATUS_WAIVED)
                        ->exists();

                    if (! $hasRemaining) {
                        $invoice->enrollment->update(['fee_status' => 'paid']);
                    }
                }

                if ($invoice->feeSchedule) {
                    $invoice->feeSchedule->increment('total_collected_amount', (float) $transaction->amount);
                }
            }

            // 3. Check for Library Security Deposit
            $feeType = $transaction->feeType ?: ($transaction->invoice?->feeType);
            if ($feeType && $feeType->code === 'library_security') {
                $existingDeposit = LibraryDeposit::where('payment_transaction_id', $transaction->id)->first();
                LibraryDeposit::updateOrCreate(
                    ['payment_transaction_id' => $transaction->id],
                    [
                        'deposit_no' => $existingDeposit?->deposit_no ?: LibraryDeposit::generateDepositNo(),
                        'user_id' => $transaction->user_id,
                        'student_id' => $transaction->student_id,
                        'amount' => $transaction->amount,
                        'deposit_date' => now()->toDateString(),
                        'status' => LibraryDeposit::STATUS_ACTIVE,
                    ]
                );
            }

            // Student Notification
            AppNotification::create([
                'user_id' => $transaction->user_id,
                'type' => 'payment_success',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Online Payment Successful'),
                'message' => __('Your online payment of ₹:amount via :gateway was successful. Receipt No: :receipt.', [
                    'amount' => number_format((float) $transaction->amount, 2),
                    'gateway' => strtoupper((string) $transaction->gateway),
                    'receipt' => $transaction->receipt_no,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'receipt_no' => $transaction->receipt_no,
                    'amount' => (float) $transaction->amount,
                    'gateway_charged_amount' => (float) $transaction->gateway_charged_amount,
                ],
            ]);

            AuditLogService::log(
                event: 'online_payment_completed',
                description: "Completed online payment {$transaction->transaction_no} of ₹{$transaction->amount} (Charged ₹{$transaction->gateway_charged_amount}) via {$transaction->gateway}. Tracking: {$transaction->gateway_tracking_id}. Receipt: {$transaction->receipt_no}.",
                auditable: $transaction,
                userId: $transaction->user_id
            );

            return $transaction;
        });
    }

    /**
     * Handle Amount Mismatch between requested institutional fee and gateway response.
     */
    public static function handleAmountMismatch(
        PaymentTransaction $transaction,
        array $data,
        string $reason
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $data, $reason) {
            $trackingId = $data['gateway_tracking_id'] ?? $data['tracking_id'] ?? $transaction->gateway_tracking_id;
            $bankRefNo = $data['gateway_bank_ref_no'] ?? $data['bank_ref_no'] ?? $transaction->gateway_bank_ref_no;
            $chargedAmount = isset($data['gateway_charged_amount']) ? (float) $data['gateway_charged_amount'] : (isset($data['amount']) ? (float) $data['amount'] : null);
            $feeAmount = isset($data['gateway_fee']) ? (float) $data['gateway_fee'] : (isset($data['trans_fee']) || isset($data['service_tax']) ? ((float) ($data['trans_fee'] ?? 0) + (float) ($data['service_tax'] ?? 0)) : 0.00);

            $transaction->update([
                'status' => PaymentTransaction::STATUS_UNDER_REVIEW,
                'amount_mismatch' => true,
                'amount_mismatch_notes' => $reason,
                'gateway_tracking_id' => $trackingId,
                'gateway_bank_ref_no' => $bankRefNo,
                'gateway_charged_amount' => $chargedAmount,
                'gateway_fee' => $feeAmount,
                'gateway_response' => $data['response_payload'] ?? ($data ?: $transaction->gateway_response),
            ]);

            AppNotification::create([
                'user_id' => $transaction->user_id,
                'type' => 'payment_review',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Payment Under Review: Amount Discrepancy'),
                'message' => __('Your payment of transaction :txn is held under administrative review due to an amount discrepancy. Our accounts desk will verify and reconcile.', [
                    'txn' => $transaction->transaction_no,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'notes' => $reason,
                ],
            ]);

            AuditLogService::log(
                event: 'payment_amount_mismatch_flagged',
                description: "Payment {$transaction->transaction_no} flagged with amount mismatch: {$reason}",
                auditable: $transaction,
                userId: $transaction->user_id
            );

            return $transaction;
        });
    }

    /**
     * Manually update payment transaction status and details (Admin Reconcile / Manual Override).
     */
    public static function updatePaymentStatus(
        int|PaymentTransaction $transaction,
        string $newStatus,
        array $details,
        int $updatedBy
    ): PaymentTransaction {
        $model = $transaction instanceof PaymentTransaction ? $transaction : PaymentTransaction::findOrFail($transaction);

        return DB::transaction(function () use ($model, $newStatus, $details, $updatedBy) {
            $oldStatus = $model->status;
            $adminRemarks = $details['admin_remarks'] ?? $model->admin_remarks;

            if (in_array(strtolower((string) $newStatus), [PaymentTransaction::STATUS_SUCCESSFUL, 'completed', 'success', 'paid'], true)) {
                $newStatus = PaymentTransaction::STATUS_SUCCESSFUL;
            }

            if (isset($details['gateway_tracking_id'])) {
                $model->gateway_tracking_id = $details['gateway_tracking_id'];
            }
            if (isset($details['gateway_bank_ref_no'])) {
                $model->gateway_bank_ref_no = $details['gateway_bank_ref_no'];
            }
            if (isset($details['payment_method'])) {
                $model->payment_method = $details['payment_method'];
            }
            if (isset($details['amount']) && (float) $details['amount'] > 0) {
                $model->amount = (float) $details['amount'];
            }

            $model->verified_by = $updatedBy;
            $model->verified_at = now();
            $model->admin_remarks = $adminRemarks;

            if ($newStatus === PaymentTransaction::STATUS_SUCCESSFUL && $oldStatus !== PaymentTransaction::STATUS_SUCCESSFUL) {
                $model->status = PaymentTransaction::STATUS_SUCCESSFUL;
                $model->receipt_no = $model->receipt_no ?: PaymentTransaction::generateReceiptNo();
                $model->receipt_generated_at = $model->receipt_generated_at ?: now();
                $model->amount_mismatch = false;
                $model->save();

                // Credit Ledger
                StudentLedger::recordCredit(
                    userId: $model->user_id,
                    amount: (float) $model->amount,
                    referenceType: StudentLedger::REF_PAYMENT,
                    referenceId: $model->id,
                    description: "Payment manually verified ({$model->receipt_no}) by Staff #{$updatedBy} - ".strtoupper((string) $model->gateway),
                    studentId: $model->student_id,
                    createdBy: $updatedBy
                );

                // Settle Invoice
                if ($model->invoice_id && $model->invoice) {
                    $invoice = $model->invoice;
                    $newPaid = (float) $invoice->paid_amount + (float) $model->amount;
                    $newBalance = max(0.00, (float) $invoice->total_amount - $newPaid);
                    $invStatus = $newBalance <= 0.00 ? StudentFeeInvoice::STATUS_PAID : StudentFeeInvoice::STATUS_PARTIAL;

                    $invoice->update([
                        'paid_amount' => $newPaid,
                        'balance_amount' => $newBalance,
                        'status' => $invStatus,
                        'paid_at' => $invStatus === StudentFeeInvoice::STATUS_PAID ? now() : $invoice->paid_at,
                    ]);

                    if ($invoice->feeSchedule) {
                        $invoice->feeSchedule->increment('total_collected_amount', (float) $model->amount);
                    }
                }

                // If Library Deposit
                $feeType = $model->feeType ?: ($model->invoice?->feeType);
                if ($feeType && $feeType->code === 'library_security') {
                    $existingDeposit = LibraryDeposit::where('payment_transaction_id', $model->id)->first();
                    LibraryDeposit::updateOrCreate(
                        ['payment_transaction_id' => $model->id],
                        [
                            'deposit_no' => $existingDeposit?->deposit_no ?: LibraryDeposit::generateDepositNo(),
                            'user_id' => $model->user_id,
                            'student_id' => $model->student_id,
                            'amount' => $model->amount,
                            'deposit_date' => now()->toDateString(),
                            'status' => LibraryDeposit::STATUS_ACTIVE,
                            'created_by' => $updatedBy,
                        ]
                    );
                }

                AppNotification::create([
                    'user_id' => $model->user_id,
                    'type' => 'payment_verified',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Payment Verified & Approved'),
                    'message' => __('Your payment of ₹:amount has been verified by the accounts desk. Receipt: :receipt.', [
                        'amount' => number_format((float) $model->amount, 2),
                        'receipt' => $model->receipt_no,
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => ['transaction_id' => $model->id, 'receipt_no' => $model->receipt_no],
                ]);
            } else {
                $model->status = $newStatus;
                $model->save();
            }

            AuditLogService::log(
                event: 'payment_status_manually_updated',
                description: "Admin updated payment {$model->transaction_no} status from '{$oldStatus}' to '{$newStatus}'. Remarks: {$adminRemarks}",
                auditable: $model,
                userId: $updatedBy
            );

            return $model;
        });
    }

    /**
     * Process Refund for a payment transaction (Duplicate payment, student refund, cancellation).
     */
    public static function refundPayment(
        int|PaymentTransaction $transaction,
        float $amount,
        string $reason,
        ?string $refundRefNo = null,
        ?int $refundedBy = null,
        bool $attemptGatewayApi = true
    ): PaymentTransaction {
        $model = $transaction instanceof PaymentTransaction ? $transaction : PaymentTransaction::findOrFail($transaction);
        $refundedBy = $refundedBy ?? auth()->id();

        if (! $model->canBeRefunded()) {
            throw ValidationException::withMessages([
                'amount' => __('This transaction is not eligible for a refund or has already been fully refunded.'),
            ]);
        }

        $refundableMax = $model->getRefundableAmount();
        if ($amount <= 0.00 || $amount > ($refundableMax + 0.01)) {
            throw ValidationException::withMessages([
                'amount' => __('Refund amount must be between ₹1.00 and ₹:max.', ['max' => number_format($refundableMax, 2)]),
            ]);
        }

        $refundRefNo = $refundRefNo ?: ('REF-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4)));

        // If gateway is CCAvenue and online API refund is desired:
        if ($attemptGatewayApi && $model->gateway === PaymentTransaction::GATEWAY_CCAVENUE && ! empty($model->gateway_tracking_id)) {
            $gateway = new CCAVenueGateway;
            if ($gateway->isConfigured()) {
                $apiResult = $gateway->refundOrder($model, $amount, $refundRefNo, $reason);
                if (! $apiResult['success']) {
                    throw ValidationException::withMessages([
                        'gateway' => __('CCAvenue Refund API returned error: :err', ['err' => $apiResult['message']]),
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($model, $amount, $reason, $refundRefNo, $refundedBy) {
            $newRefundTotal = round((float) ($model->refund_amount ?? 0.00) + $amount, 2);
            $isFullyRefunded = $newRefundTotal >= ((float) $model->amount - 0.01);

            $model->update([
                'refund_amount' => $newRefundTotal,
                'refund_reason' => $reason,
                'refund_reference_no' => $refundRefNo,
                'refund_status' => 'processed',
                'refunded_at' => now(),
                'refunded_by' => $refundedBy,
                'status' => $isFullyRefunded ? PaymentTransaction::STATUS_REFUNDED : $model->status,
            ]);

            // 1. Post Debit to Student Ledger to reverse liability deduction
            StudentLedger::recordDebit(
                userId: $model->user_id,
                amount: $amount,
                referenceType: StudentLedger::REF_REFUND,
                referenceId: $model->id,
                description: "Refund of payment ({$model->receipt_no}) [Ref: {$refundRefNo}]: {$reason}",
                studentId: $model->student_id,
                createdBy: $refundedBy
            );

            // 2. Adjust linked StudentFeeInvoice
            if ($model->invoice_id && $model->invoice) {
                $invoice = $model->invoice;
                $newPaid = max(0.00, (float) $invoice->paid_amount - $amount);
                $totalInvoiceAmount = (float) ($invoice->total_amount ?: $invoice->amount);
                $newBalance = max(0.00, $totalInvoiceAmount - $newPaid);
                $newStatus = $newBalance <= 0.00 ? StudentFeeInvoice::STATUS_PAID : ($newPaid > 0.00 ? StudentFeeInvoice::STATUS_PARTIAL : StudentFeeInvoice::STATUS_PENDING);

                $invoice->update([
                    'paid_amount' => $newPaid,
                    'balance_amount' => $newBalance,
                    'status' => $newStatus,
                ]);

                if ($invoice->feeSchedule) {
                    $invoice->feeSchedule->decrement('total_collected_amount', $amount);
                }
            }

            // Student Notification
            AppNotification::create([
                'user_id' => $model->user_id,
                'type' => 'payment_refunded',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Payment Refund Processed'),
                'message' => __('A refund of ₹:amount has been issued against transaction :txn (Receipt: :receipt). Reason: :reason', [
                    'amount' => number_format($amount, 2),
                    'txn' => $model->transaction_no,
                    'receipt' => $model->receipt_no,
                    'reason' => $reason,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'transaction_id' => $model->id,
                    'refund_amount' => $amount,
                    'refund_ref_no' => $refundRefNo,
                ],
            ]);

            AuditLogService::log(
                event: 'payment_refunded',
                description: "Refunded ₹{$amount} for payment {$model->transaction_no} (Receipt: {$model->receipt_no}). Ref: {$refundRefNo}. Reason: {$reason}",
                auditable: $model,
                userId: $refundedBy
            );

            return $model;
        });
    }

    /**
     * Query live CCAvenue server status and synchronize transaction state.
     *
     * @return array{success: bool, message: string, order_status?: string}
     */
    public static function syncWithCCAvenue(int|PaymentTransaction $transaction): array
    {
        $model = $transaction instanceof PaymentTransaction ? $transaction : PaymentTransaction::findOrFail($transaction);

        if ($model->gateway !== PaymentTransaction::GATEWAY_CCAVENUE) {
            return [
                'success' => false,
                'message' => __('Transaction does not belong to CCAvenue gateway.'),
            ];
        }

        $gateway = new CCAVenueGateway;
        $queryResult = $gateway->queryOrderStatus($model);

        if (! $queryResult['success']) {
            return [
                'success' => false,
                'message' => $queryResult['error'] ?? __('Failed to contact CCAvenue status tracker.'),
            ];
        }

        $data = $queryResult['data'] ?? [];
        if (! empty($data['order_status_result']) && is_array($data['order_status_result'])) {
            $data = array_merge($data, $data['order_status_result']);
        }
        $rawStatus = trim((string) ($queryResult['status'] ?? $data['order_status'] ?? ''));

        if (strcasecmp($rawStatus, 'Successful') === 0 || strcasecmp($rawStatus, 'Success') === 0 || strcasecmp($rawStatus, 'Shipped') === 0) {
            $trackingId = (string) ($data['reference_no'] ?? $model->gateway_tracking_id);
            $chargedAmt = isset($data['order_amt']) ? (float) $data['order_amt'] : (float) $model->amount;
            $feePercVal = isset($data['order_fee_perc_value']) ? (float) $data['order_fee_perc_value'] : 0.00;
            $feeFlat = isset($data['order_fee_flat']) ? (float) $data['order_fee_flat'] : 0.00;
            $tax = isset($data['order_tax']) ? (float) $data['order_tax'] : 0.00;
            $bankRef = $data['order_bank_ref_no'] ?? null;
            $cardName = $data['order_card_name'] ?? null;
            $optionType = $data['order_option_type'] ?? null;

            self::completeOnlinePaymentWithCharges($model, [
                'gateway_payment_id' => $trackingId,
                'gateway_tracking_id' => $trackingId,
                'gateway_bank_ref_no' => $bankRef,
                'gateway_payment_mode' => $optionType,
                'gateway_card_name' => $cardName,
                'gateway_charged_amount' => $chargedAmt,
                'gateway_fee' => round($feePercVal + $feeFlat, 2),
                'gateway_tax' => $tax,
                'response_payload' => $data,
            ]);

            return [
                'success' => true,
                'message' => __('CCAvenue confirmed payment as SUCCESSFUL. Transaction synchronized and official receipt generated.'),
                'order_status' => 'Successful',
            ];
        }

        if (strcasecmp($rawStatus, 'Aborted') === 0 || strcasecmp($rawStatus, 'Failure') === 0 || strcasecmp($rawStatus, 'Unsuccessful') === 0) {
            $model->update([
                'status' => PaymentTransaction::STATUS_FAILED,
                'gateway_response' => $data,
                'admin_remarks' => "Reconciled from CCAvenue: Order status is {$rawStatus}",
            ]);

            return [
                'success' => true,
                'message' => __("CCAvenue confirmed order status as ':status'. Transaction marked as failed.", ['status' => $rawStatus]),
                'order_status' => $rawStatus,
            ];
        }

        return [
            'success' => true,
            'message' => __('CCAvenue returned current status: :status.', ['status' => $rawStatus ?: 'Awaited']),
            'order_status' => $rawStatus ?: 'Awaited',
        ];
    }

    /**
     * Record a direct manual fee collection for any student by admin.
     *
     * @param  array{
     *     user_id: int,
     *     invoice_id?: int|null,
     *     fee_type_id?: int|null,
     *     fee_type_code?: string|null,
     *     title?: string|null,
     *     amount: float,
     *     payment_method: string,
     *     reference_no?: string|null,
     *     payment_date?: string|null,
     *     payment_time?: string|null,
     *     remarks?: string|null,
     *     period_month?: int|null,
     *     period_year?: int|null,
     *     enrollment_id?: int|null,
     * }  $data
     */
    public static function recordManualCollection(array $data, ?int $collectedBy = null): PaymentTransaction
    {
        $collectedBy = $collectedBy ?? auth()->id();
        $userId = $data['user_id'] ?? $data['student_id'] ?? null;
        $user = User::findOrFail($userId);
        $amount = (float) $data['amount'];

        return DB::transaction(function () use ($data, $user, $amount, $collectedBy) {
            $invoice = ! empty($data['invoice_id']) ? StudentFeeInvoice::find($data['invoice_id']) : null;
            $feeType = ! empty($data['fee_type_id']) ? FeeType::find($data['fee_type_id']) : ($invoice?->feeType);

            if (! $feeType && ! empty($data['fee_type_code'])) {
                $feeType = FeeType::where('code', $data['fee_type_code'])->first();
            }

            // Enforce: One student can make payment against a month only once in case of tuition fees
            if ($feeType && $feeType->code === 'tuition') {
                $month = (int) ($data['period_month'] ?? $invoice?->period_month ?? 0);
                $year = (int) ($data['period_year'] ?? $invoice?->period_year ?? date('Y'));

                if ($invoice && $invoice->status === StudentFeeInvoice::STATUS_PAID) {
                    throw ValidationException::withMessages([
                        'period_month' => __('This tuition fee invoice has already been fully paid.'),
                    ]);
                }

                if ($month > 0 && $year > 0) {
                    $alreadyPaid = StudentFeeInvoice::where('user_id', $user->id)
                        ->where('fee_type_id', $feeType->id)
                        ->where('period_month', $month)
                        ->where('period_year', $year)
                        ->where('status', StudentFeeInvoice::STATUS_PAID)
                        ->when($invoice, fn ($q) => $q->where('id', '!=', $invoice->id))
                        ->exists();

                    if ($alreadyPaid) {
                        $monthName = Carbon::create(null, $month, 1)->format('F');
                        throw ValidationException::withMessages([
                            'period_month' => __('Tuition fee for :month :year has already been paid for this student. A student can only pay tuition fee once per month.', [
                                'month' => $monthName,
                                'year' => $year,
                            ]),
                        ]);
                    }
                }
            }

            // If no invoice was provided and it's not a library deposit, create a settled invoice record for transparency
            if (! $invoice && ($feeType?->code !== 'library_security')) {
                $invoice = StudentFeeInvoice::create([
                    'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
                    'user_id' => $user->id,
                    'student_id' => $user->student_id ?: ($data['student_id_code'] ?? null),
                    'enrollment_id' => $data['enrollment_id'] ?? $user->activeEnrollments()->first()?->id,
                    'fee_type_id' => $feeType?->id ?? FeeType::first()?->id,
                    'title' => $data['title'] ?? $data['custom_title'] ?? ($feeType?->name ?: __('Manual Fee Payment')),
                    'description' => $data['remarks'] ?? 'Direct manual counter fee payment collected by admin.',
                    'period_month' => $data['period_month'] ?? null,
                    'period_year' => $data['period_year'] ?? (int) date('Y'),
                    'amount' => $amount,
                    'discount_amount' => 0.00,
                    'late_fee_amount' => 0.00,
                    'total_amount' => $amount,
                    'paid_amount' => $amount,
                    'balance_amount' => 0.00,
                    'due_date' => now()->toDateString(),
                    'status' => StudentFeeInvoice::STATUS_PAID,
                    'paid_at' => now(),
                    'created_by' => $collectedBy,
                ]);

                // Record invoice debit in ledger first
                StudentLedger::recordDebit(
                    userId: $user->id,
                    amount: $amount,
                    referenceType: StudentLedger::REF_INVOICE,
                    referenceId: $invoice->id,
                    description: "Fee Invoice {$invoice->invoice_no} ({$invoice->title})",
                    studentId: $invoice->student_id,
                    createdBy: $collectedBy
                );
            }

            // Create completed payment transaction
            $transaction = PaymentTransaction::create([
                'transaction_no' => PaymentTransaction::generateTransactionNo(),
                'invoice_id' => $invoice?->id,
                'user_id' => $user->id,
                'student_id' => $user->student_id ?: ($data['student_id_code'] ?? null),
                'fee_type_id' => $feeType?->id,
                'amount' => $amount,
                'currency' => 'INR',
                'gateway' => PaymentTransaction::GATEWAY_OFFLINE,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'status' => PaymentTransaction::STATUS_SUCCESSFUL,
                'is_offline' => true,
                'offline_reference_id' => $data['reference_no'] ?? $data['payment_reference'] ?? ('COUNTER-'.now()->format('YmdHis')),
                'offline_payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'offline_payment_time' => $data['payment_time'] ?? now()->format('H:i'),
                'verified_by' => $collectedBy,
                'verified_at' => now(),
                'receipt_no' => PaymentTransaction::generateReceiptNo(),
                'receipt_generated_at' => now(),
                'user_remarks' => $data['remarks'] ?? null,
                'admin_remarks' => 'Direct manual payment recorded by staff User #'.$collectedBy,
                'created_by' => $collectedBy,
            ]);

            // Post credit to Student Ledger
            StudentLedger::recordCredit(
                userId: $user->id,
                amount: $amount,
                referenceType: ($feeType?->code === 'library_security' ? StudentLedger::REF_DEPOSIT : StudentLedger::REF_PAYMENT),
                referenceId: $transaction->id,
                description: "Direct fee payment verified ({$transaction->receipt_no}) via ".strtoupper(str_replace('_', ' ', $transaction->payment_method)),
                studentId: $transaction->student_id,
                createdBy: $collectedBy
            );

            // If linked to an invoice that was pending/partial, update invoice balance
            if ($invoice && $invoice->status !== StudentFeeInvoice::STATUS_PAID) {
                $newPaid = (float) $invoice->paid_amount + $amount;
                $newBalance = max(0.00, (float) $invoice->total_amount - $newPaid);
                $newStatus = $newBalance <= 0.00 ? StudentFeeInvoice::STATUS_PAID : StudentFeeInvoice::STATUS_PARTIAL;

                $invoice->update([
                    'paid_amount' => $newPaid,
                    'balance_amount' => $newBalance,
                    'status' => $newStatus,
                    'paid_at' => $newStatus === StudentFeeInvoice::STATUS_PAID ? now() : $invoice->paid_at,
                ]);

                if ($invoice->enrollment) {
                    $hasRemaining = StudentFeeInvoice::where('enrollment_id', $invoice->enrollment_id)
                        ->whereNotIn('status', [StudentFeeInvoice::STATUS_PAID, StudentFeeInvoice::STATUS_WAIVED])
                        ->exists();

                    if (! $hasRemaining) {
                        $invoice->enrollment->update(['fee_status' => 'paid']);
                    }
                }

                if ($invoice->fee_schedule_id && $invoice->feeSchedule) {
                    $invoice->feeSchedule->increment('total_collected_amount', $amount);
                }
            }

            // If this is for library deposit, create or activate LibraryDeposit record
            if ($feeType?->code === 'library_security') {
                $existingDeposit = LibraryDeposit::where('payment_transaction_id', $transaction->id)->first();
                LibraryDeposit::updateOrCreate(
                    ['payment_transaction_id' => $transaction->id],
                    [
                        'deposit_no' => $existingDeposit?->deposit_no ?: LibraryDeposit::generateDepositNo(),
                        'user_id' => $user->id,
                        'student_id' => $transaction->student_id,
                        'amount' => $amount,
                        'deposit_date' => $transaction->offline_payment_date ?: now()->toDateString(),
                        'status' => LibraryDeposit::STATUS_ACTIVE,
                        'created_by' => $collectedBy,
                    ]
                );
            }

            // Notification
            AppNotification::create([
                'user_id' => $user->id,
                'type' => 'payment_verified',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Fee Payment Recorded & Receipt Ready'),
                'message' => __('Your payment of ₹:amount (:method) has been processed. Receipt No: :receipt.', [
                    'amount' => number_format($amount, 2),
                    'method' => strtoupper(str_replace('_', ' ', $transaction->payment_method)),
                    'receipt' => $transaction->receipt_no,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'receipt_no' => $transaction->receipt_no,
                    'amount' => $amount,
                ],
            ]);

            AuditLogService::log(
                event: 'manual_fee_collected',
                description: "Manually recorded payment {$transaction->transaction_no} of ₹{$amount} for Student {$user->name}. Receipt: {$transaction->receipt_no}.",
                auditable: $transaction,
                userId: $collectedBy
            );

            return $transaction;
        });
    }

    /**
     * Cancel an unpaid or partially paid student fee invoice and adjust student ledger liability.
     */
    public static function cancelInvoice(int|StudentFeeInvoice $invoice, string $reason, ?int $cancelledBy = null): StudentFeeInvoice
    {
        $model = $invoice instanceof StudentFeeInvoice ? $invoice : StudentFeeInvoice::findOrFail($invoice);
        $cancelledBy = $cancelledBy ?? auth()->id();

        if ($model->status === StudentFeeInvoice::STATUS_PAID) {
            throw ValidationException::withMessages([
                'invoice' => __('Paid invoices cannot be cancelled directly. Please process a refund or adjustment.'),
            ]);
        }

        return DB::transaction(function () use ($model, $reason, $cancelledBy) {
            $unpaidBalance = (float) $model->balance_amount;

            if ($unpaidBalance > 0.00) {
                StudentLedger::recordCredit(
                    userId: $model->user_id,
                    amount: $unpaidBalance,
                    referenceType: StudentLedger::REF_ADJUSTMENT,
                    referenceId: $model->id,
                    description: "Invoice {$model->invoice_no} cancelled by Admin: {$reason}",
                    studentId: $model->student_id,
                    createdBy: $cancelledBy
                );
            }

            $model->update([
                'status' => (float) $model->paid_amount > 0.00 ? StudentFeeInvoice::STATUS_WAIVED : StudentFeeInvoice::STATUS_CANCELLED,
                'balance_amount' => 0.00,
                'description' => trim(($model->description ? $model->description."\n" : '').'[Cancelled on '.now()->toDateTimeString()." by User #{$cancelledBy}: {$reason}]"),
            ]);

            AuditLogService::log(
                event: 'fee_invoice_cancelled',
                description: "Cancelled fee invoice {$model->invoice_no} for student ID {$model->student_id}. Reason: {$reason}",
                auditable: $model,
                userId: $cancelledBy
            );

            return $model;
        });
    }

    /**
     * Delete an uncollected fee invoice and reverse its ledger debit.
     */
    public static function deleteInvoice(int|StudentFeeInvoice $invoice, ?int $deletedBy = null): bool
    {
        $model = $invoice instanceof StudentFeeInvoice ? $invoice : StudentFeeInvoice::findOrFail($invoice);
        $deletedBy = $deletedBy ?? auth()->id();

        if (! $model->canBeDeleted()) {
            throw ValidationException::withMessages([
                'invoice' => __('Cannot delete invoice because payments have already been collected against it. Please cancel or adjust instead.'),
            ]);
        }

        return DB::transaction(function () use ($model, $deletedBy) {
            // Remove associated ledger debit
            StudentLedger::where('reference_type', StudentLedger::REF_INVOICE)
                ->where('reference_id', $model->id)
                ->delete();

            $invoiceNo = $model->invoice_no;
            $model->delete();

            AuditLogService::log(
                event: 'fee_invoice_deleted',
                description: "Deleted fee invoice {$invoiceNo} along with its ledger debit record.",
                auditable: $model,
                userId: $deletedBy
            );

            return true;
        });
    }
}
