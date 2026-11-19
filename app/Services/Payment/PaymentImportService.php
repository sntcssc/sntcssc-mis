<?php

namespace App\Services\Payment;

use App\Models\StudentFeeInvoice;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class PaymentImportService
{
    /**
     * Process bulk import of offline payment records from CSV/Excel.
     *
     * @return array{
     *     total: int,
     *     imported: int,
     *     skipped: int,
     *     errors: array<string>
     * }
     */
    public static function import(UploadedFile $file, int $importedBy, bool $autoApprove = true): array
    {
        $rows = Excel::toArray([], $file)[0] ?? [];

        if (count($rows) <= 1) {
            return [
                'total' => 0,
                'imported' => 0,
                'skipped' => 0,
                'errors' => [__('The uploaded file appears to be empty or contains only header row.')],
            ];
        }

        // Header mapping
        $header = array_map(fn ($col) => strtolower(trim((string) $col)), $rows[0]);
        $dataRows = array_slice($rows, 1);

        $studentIdIdx = array_search('student_id', $header, true);
        if ($studentIdIdx === false) {
            $studentIdIdx = array_search('student id', $header, true);
        }
        if ($studentIdIdx === false) {
            $studentIdIdx = array_search('roll_no', $header, true);
        }
        if ($studentIdIdx === false) {
            $studentIdIdx = 0; // Default first column
        }

        $amountIdx = array_search('amount', $header, true);
        if ($amountIdx === false) {
            $amountIdx = 1;
        }

        $methodIdx = array_search('payment_method', $header, true);
        if ($methodIdx === false) {
            $methodIdx = array_search('method', $header, true);
        }
        if ($methodIdx === false) {
            $methodIdx = 2;
        }

        $refIdx = array_search('reference_id', $header, true);
        if ($refIdx === false) {
            $refIdx = array_search('utr', $header, true);
        }
        if ($refIdx === false) {
            $refIdx = 3;
        }

        $dateIdx = array_search('payment_date', $header, true);
        if ($dateIdx === false) {
            $dateIdx = array_search('date', $header, true);
        }
        if ($dateIdx === false) {
            $dateIdx = 4;
        }

        $invoiceNoIdx = array_search('invoice_no', $header, true);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($dataRows as $rowIdx => $row) {
            $lineNum = $rowIdx + 2;
            $rawStudentId = trim((string) ($row[$studentIdIdx] ?? ''));
            $rawAmount = (float) trim((string) ($row[$amountIdx] ?? 0));
            $rawMethod = strtolower(trim((string) ($row[$methodIdx] ?? 'bank_transfer')));
            $rawRef = trim((string) ($row[$refIdx] ?? ''));
            $rawDate = trim((string) ($row[$dateIdx] ?? ''));
            $rawInvoiceNo = $invoiceNoIdx !== false ? trim((string) ($row[$invoiceNoIdx] ?? '')) : null;

            if (empty($rawStudentId) || $rawAmount <= 0) {
                $skipped++;

                continue;
            }

            // Find student by student_id or email or roll_no
            $user = User::where('student_id', $rawStudentId)
                ->orWhere('email', $rawStudentId)
                ->orWhereHas('enrollments', fn ($q) => $q->where('roll_no', $rawStudentId))
                ->first();

            if (! $user) {
                $errors[] = __("Row :row: Student not found with identifier ':id'", ['row' => $lineNum, 'id' => $rawStudentId]);
                $skipped++;

                continue;
            }

            // Find matching pending invoice if provided or latest pending
            $invoice = null;
            if ($rawInvoiceNo) {
                $invoice = StudentFeeInvoice::where('invoice_no', $rawInvoiceNo)->where('user_id', $user->id)->first();
            }
            if (! $invoice) {
                $invoice = StudentFeeInvoice::where('user_id', $user->id)
                    ->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL, StudentFeeInvoice::STATUS_OVERDUE])
                    ->orderBy('due_date')
                    ->first();
            }

            try {
                $paymentDate = ! empty($rawDate) ? Carbon::parse($rawDate)->toDateString() : now()->toDateString();
            } catch (\Throwable) {
                $paymentDate = now()->toDateString();
            }

            try {
                DB::transaction(function () use ($user, $invoice, $rawAmount, $rawMethod, $rawRef, $paymentDate, $importedBy, $autoApprove, &$imported) {
                    $txn = FeePaymentService::recordOfflinePayment([
                        'invoice_id' => $invoice?->id,
                        'user_id' => $user->id,
                        'student_id' => $user->student_id,
                        'amount' => $rawAmount,
                        'payment_method' => in_array($rawMethod, ['upi', 'static_qr', 'neft', 'imps', 'bank_transfer', 'cash', 'cheque', 'dd'], true) ? $rawMethod : 'bank_transfer',
                        'offline_reference_id' => $rawRef ?: ('BULK-'.now()->format('YmdHis').'-'.rand(100, 999)),
                        'offline_payment_date' => $paymentDate,
                        'user_remarks' => 'Imported via bulk CSV/Excel upload.',
                    ], null, $importedBy);

                    if ($autoApprove) {
                        FeePaymentService::verifyOfflinePayment(
                            transaction: $txn,
                            approved: true,
                            verifiedBy: $importedBy,
                            adminRemarks: 'Bulk verified upon Excel import.'
                        );
                    }

                    $imported++;
                });
            } catch (\Throwable $e) {
                $errors[] = __('Row :row: Failed to record payment - :err', ['row' => $lineNum, 'err' => $e->getMessage()]);
                $skipped++;
            }
        }

        AuditLogService::log(
            event: 'payments_bulk_imported',
            description: "Bulk imported {$imported} payments. Total rows processed: ".count($dataRows),
            userId: $importedBy
        );

        return [
            'total' => count($dataRows),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
