<?php

namespace App\Services\Library;

use App\Models\AppNotification;
use App\Models\LibraryDeposit;
use App\Models\LibraryMember;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryClearanceService
{
    /**
     * Check if a student or user is eligible for library clearance and deposit refund.
     *
     * @return array{
     *     eligible: bool,
     *     active_loans_count: int,
     *     active_loans: Collection,
     *     unpaid_fines: float,
     *     member: LibraryMember|null,
     *     blocking_reason: string|null
     * }
     */
    public static function checkEligibility(User|int $user): array
    {
        $userId = $user instanceof User ? $user->id : $user;
        $member = LibraryMember::where('user_id', $userId)->first();

        if (! $member) {
            return [
                'eligible' => true,
                'active_loans_count' => 0,
                'active_loans' => collect(),
                'unpaid_fines' => 0.0,
                'member' => null,
                'blocking_reason' => null,
            ];
        }

        $activeLoans = $member->activeIssues()
            ->with(['book', 'bookCopy'])
            ->get();

        $unpaidFines = (float) $member->fines()
            ->where('payment_status', 'unpaid')
            ->sum('fine_amount');

        $activeLoansCount = $activeLoans->count();

        if ($activeLoansCount > 0) {
            $bookTitles = $activeLoans->map(function ($issue) {
                $acc = $issue->bookCopy?->accession_no ? " [Acc: {$issue->bookCopy->accession_no}]" : '';

                return "• \"{$issue->book->title}\"{$acc} (Due: {$issue->due_date->format('d M Y')})";
            })->join("\n");

            $reason = __("You have :count unreturned book(s) currently issued:\n:books\nPlease return all issued books to the library before requesting a security deposit refund.", [
                'count' => $activeLoansCount,
                'books' => $bookTitles,
            ]);

            return [
                'eligible' => false,
                'active_loans_count' => $activeLoansCount,
                'active_loans' => $activeLoans,
                'unpaid_fines' => $unpaidFines,
                'member' => $member,
                'blocking_reason' => $reason,
            ];
        }

        if ($unpaidFines > 0) {
            $reason = __('You have unpaid library overdue fines of ₹:amount. Please clear any outstanding fines before requesting clearance.', [
                'amount' => number_format($unpaidFines, 2),
            ]);

            return [
                'eligible' => false,
                'active_loans_count' => 0,
                'active_loans' => collect(),
                'unpaid_fines' => $unpaidFines,
                'member' => $member,
                'blocking_reason' => $reason,
            ];
        }

        return [
            'eligible' => true,
            'active_loans_count' => 0,
            'active_loans' => collect(),
            'unpaid_fines' => 0.0,
            'member' => $member,
            'blocking_reason' => null,
        ];
    }

    /**
     * Issue an official library clearance (no-dues) certificate by the librarian.
     */
    public static function issueClearanceCertificate(
        LibraryDeposit|int $deposit,
        int $clearedBy,
        ?string $remarks = null
    ): LibraryDeposit {
        $model = $deposit instanceof LibraryDeposit ? $deposit : LibraryDeposit::findOrFail($deposit);

        $eligibility = self::checkEligibility($model->user_id);
        if (! $eligibility['eligible']) {
            throw ValidationException::withMessages([
                'clearance' => $eligibility['blocking_reason'] ?: __('User has active library loans or unpaid dues.'),
            ]);
        }

        return DB::transaction(function () use ($model, $clearedBy, $remarks) {
            $certificateNo = $model->clearance_certificate_no ?: LibraryDeposit::generateClearanceCertificateNo();
            $defaultRemarks = 'All library books returned in good condition. Zero outstanding dues or overdue fines. Official library clearance granted for security deposit refund.';

            $model->update([
                'clearance_status' => LibraryDeposit::CLEARANCE_CLEARED,
                'clearance_certificate_no' => $certificateNo,
                'cleared_at' => now(),
                'cleared_by_id' => $clearedBy,
                'clearance_remarks' => $remarks ?: $defaultRemarks,
            ]);

            $clearedByUser = User::find($clearedBy);
            $librarianName = $clearedByUser ? $clearedByUser->name : 'Librarian';

            AppNotification::create([
                'user_id' => $model->user_id,
                'type' => 'library_clearance_issued',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Library Clearance Certificate Issued'),
                'message' => __('Your official Library Clearance Certificate (:cert) has been issued by :lib. Your security deposit refund (:dep) is now pending Admin approval.', [
                    'cert' => $certificateNo,
                    'lib' => $librarianName,
                    'dep' => $model->deposit_no,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'deposit_id' => $model->id,
                    'certificate_no' => $certificateNo,
                ],
            ]);

            AuditLogService::log(
                event: 'library_clearance_issued',
                description: "Issued official library clearance certificate {$certificateNo} for {$model->deposit_no} (User: {$model->user->name}).",
                auditable: $model,
                userId: $clearedBy
            );

            return $model;
        });
    }

    /**
     * Reject a clearance request with reasons.
     */
    public static function rejectClearance(
        LibraryDeposit|int $deposit,
        int $reviewedBy,
        string $remarks
    ): LibraryDeposit {
        $model = $deposit instanceof LibraryDeposit ? $deposit : LibraryDeposit::findOrFail($deposit);

        return DB::transaction(function () use ($model, $reviewedBy, $remarks) {
            $model->update([
                'clearance_status' => LibraryDeposit::CLEARANCE_REJECTED,
                'cleared_at' => now(),
                'cleared_by_id' => $reviewedBy,
                'clearance_remarks' => $remarks,
            ]);

            AppNotification::create([
                'user_id' => $model->user_id,
                'type' => 'library_clearance_rejected',
                'category' => AppNotification::CATEGORY_SYSTEM,
                'title' => __('Library Clearance Request Rejected'),
                'message' => __('Your library clearance request for deposit :dep was declined. Remarks: :rem', [
                    'dep' => $model->deposit_no,
                    'rem' => $remarks,
                ]),
                'channel' => AppNotification::CHANNEL_DATABASE,
                'data' => [
                    'deposit_id' => $model->id,
                ],
            ]);

            AuditLogService::log(
                event: 'library_clearance_rejected',
                description: "Rejected library clearance request for deposit {$model->deposit_no}. Remarks: {$remarks}",
                auditable: $model,
                userId: $reviewedBy
            );

            return $model;
        });
    }

    /**
     * Generate printable PDF certificate response.
     */
    public static function generateCertificatePdf(LibraryDeposit $deposit): StreamedResponse
    {
        $deposit->loadMissing(['user', 'clearedBy']);
        $member = LibraryMember::where('user_id', $deposit->user_id)->first();

        $qrDataUri = BarcodeService::generateQrCodeDataUri(
            $deposit->clearance_certificate_no ?: $deposit->deposit_no,
            4
        );
        $barcodeDataUri = BarcodeService::generateBarcodeDataUri(
            $deposit->clearance_certificate_no ?: $deposit->deposit_no,
            40,
            2,
            false
        );

        $instituteName = Setting::get('institution_name', 'Satyendranath Tagore Civil Services Study Centre');
        $instituteAddress = Setting::get('institution_address', 'Kolkata, West Bengal, India');

        $pdf = Pdf::loadView('pdf.library-clearance-certificate', [
            'deposit' => $deposit,
            'member' => $member,
            'user' => $deposit->user,
            'clearedBy' => $deposit->clearedBy,
            'qrDataUri' => $qrDataUri,
            'barcodeDataUri' => $barcodeDataUri,
            'instituteName' => $instituteName,
            'instituteAddress' => $instituteAddress,
            'generatedAt' => now()->format('d M Y, h:i A'),
        ]);

        $pdf->setPaper('a4', 'portrait');

        $filename = 'Library-Clearance-Certificate-'.($deposit->clearance_certificate_no ?: $deposit->deposit_no).'.pdf';

        return response()->streamDownload(
            function () use ($pdf) {
                echo $pdf->output();
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }
}
