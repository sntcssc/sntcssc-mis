<?php

namespace App\Services\Library;

use App\Models\LibraryMember;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class LibraryCardService
{
    /**
     * Get or automatically create a library member profile for a user.
     */
    public static function getOrCreateMemberForUser(User $user): LibraryMember
    {
        $member = LibraryMember::where('user_id', $user->id)->first();

        if (! $member) {
            $memberNo = self::generateMemberNumber();
            $memberType = $user->isStudent() ? LibraryMember::TYPE_STUDENT : LibraryMember::TYPE_STAFF;

            $member = LibraryMember::create([
                'member_number' => $memberNo,
                'user_id' => $user->id,
                'student_id' => $user->student_id,
                'member_type' => $memberType,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'joining_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'status' => LibraryMember::STATUS_ACTIVE,
            ]);
        }

        return $member;
    }

    /**
     * Prepare data payload for library card rendering and printing.
     *
     * @param  LibraryMember  $member  The library member
     * @param  bool  $withDepositDetails  Option to include security deposit amount details
     * @param  bool  $withIssuedBooks  Option to include list of currently issued books
     * @return array<string, mixed>
     */
    public static function getCardPayload(
        LibraryMember $member,
        bool $withDepositDetails = true,
        bool $withIssuedBooks = true
    ): array {
        $member->loadMissing(['user', 'activeIssues.book', 'activeIssues.bookCopy']);

        $barcodeDataUri = BarcodeService::generateBarcodeDataUri($member->member_number, 40, 2, false);
        $qrDataUri = BarcodeService::generateQrCodeDataUri($member->member_number, 4);

        $activeDeposit = $member->getActiveSecurityDeposit();
        $activeIssues = $member->activeIssues;

        return [
            'institution_name' => Setting::appName() ?: 'Satyendranath Tagore Civil Services Study Centre',
            'institution_sub' => 'Government of West Bengal',
            'institution_logo' => Setting::logoUrl() ?: null,
            'member' => $member,
            'member_number' => $member->member_number,
            'student_id' => $member->student_id ?: ($member->user?->student_id ?: 'N/A'),
            'name' => $member->name,
            'email' => $member->email ?: ($member->user?->email ?: 'N/A'),
            'phone' => $member->phone ?: ($member->user?->phone ?: 'N/A'),
            'member_type' => ucfirst($member->member_type),
            'photo_url' => $member->photoUrl(),
            'joining_date' => $member->joining_date ? $member->joining_date->format('d M Y') : now()->format('d M Y'),
            'expiry_date' => $member->expiry_date ? $member->expiry_date->format('d M Y') : now()->addYear()->format('d M Y'),
            'barcode_uri' => $barcodeDataUri,
            'qr_uri' => $qrDataUri,
            'with_deposit_details' => $withDepositDetails,
            'security_deposit_amount' => $activeDeposit,
            'with_issued_books' => $withIssuedBooks,
            'issued_books' => $activeIssues->map(function ($issue) {
                return [
                    'title' => $issue->book->title,
                    'accession_no' => $issue->bookCopy->accession_no,
                    'issue_date' => $issue->issue_date->format('d M Y'),
                    'due_date' => $issue->due_date->format('d M Y'),
                    'status' => ucfirst($issue->status),
                ];
            })->all(),
        ];
    }

    /**
     * Generate printable PDF of the library card.
     */
    public static function downloadCardPdf(
        LibraryMember $member,
        bool $withDepositDetails = true,
        bool $withIssuedBooks = true
    ): Response {
        $data = self::getCardPayload($member, $withDepositDetails, $withIssuedBooks);

        $pdf = Pdf::loadView('pdf.library-card', $data)
            ->setPaper('a4', 'portrait');

        $filename = 'Library-Card-'.$member->member_number.'.pdf';

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

    /**
     * Generate unique sequential Member Number.
     */
    public static function generateMemberNumber(): string
    {
        $year = now()->format('Y');
        $last = LibraryMember::withTrashed()
            ->where('member_number', 'like', "LIB-MEM-{$year}-%")
            ->orderBy('id', 'desc')
            ->first();

        $seq = 1;
        if ($last && preg_match("/LIB-MEM-{$year}-(\d+)/", $last->member_number, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('LIB-MEM-%s-%04d', $year, $seq);
    }
}
