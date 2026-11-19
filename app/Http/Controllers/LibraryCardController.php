<?php

namespace App\Http\Controllers;

use App\Models\BookCopy;
use App\Models\LibraryDeposit;
use App\Models\LibraryMember;
use App\Models\Setting;
use App\Services\Library\BarcodeService;
use App\Services\Library\LibraryCardService;
use App\Services\Library\LibraryClearanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LibraryCardController extends Controller
{
    /**
     * Download or stream official Library Card PDF with configurable options.
     */
    public function download(Request $request, LibraryMember $member): Response
    {
        $user = $request->user();

        // Authorization: Self or staff with permission
        if (! $user) {
            abort(403);
        }

        $isOwner = $member->user_id === $user->id;
        $isStaff = $user->can('library.members.manage') || $user->hasRole('Super Administrator') || $user->hasRole('Librarian');

        if (! $isOwner && ! $isStaff) {
            abort(403, 'Unauthorized access to library card.');
        }

        $withDeposit = filter_var($request->query('with_deposit', true), FILTER_VALIDATE_BOOLEAN);
        $withBooks = filter_var($request->query('with_books', true), FILTER_VALIDATE_BOOLEAN);

        return LibraryCardService::downloadCardPdf($member, $withDeposit, $withBooks);
    }

    /**
     * Download printable adhesive Barcode Labels sheet for book copies.
     */
    public function printLabels(Request $request): Response
    {
        $user = $request->user();
        if (! $user || (! $user->can('library.accession.manage') && ! $user->hasRole('Super Administrator') && ! $user->hasRole('Librarian'))) {
            abort(403, 'Unauthorized access to barcode printing.');
        }

        $copyIds = $request->query('ids');
        $query = BookCopy::with('book');

        if (! empty($copyIds)) {
            $ids = is_array($copyIds) ? $copyIds : explode(',', $copyIds);
            $query->whereIn('id', $ids);
        } else {
            $limit = (int) $request->query('limit', 24);
            $query->latest('id')->take(min($limit, 96));
        }

        $copies = $query->get();

        $labels = $copies->map(function ($copy) {
            return [
                'accession_no' => $copy->accession_no,
                'barcode_uri' => BarcodeService::generateBarcodeDataUri($copy->barcode, 32, 2, false),
                'title' => $copy->book->title,
                'classification_number' => $copy->classification_number ?: $copy->book->classification_number,
                'shelf_location' => $copy->shelf_location,
            ];
        })->all();

        // Chunk into 3 columns per row
        $chunks = array_chunk($labels, 3);

        $data = [
            'institution_name' => Setting::appName() ?: 'Satyendranath Tagore Civil Services Study Centre',
            'chunks' => $chunks,
        ];

        $pdf = Pdf::loadView('pdf.barcode-labels', $data)->setPaper('a4', 'portrait');

        $filename = 'Barcode-Labels-'.now()->format('Ymd-His').'.pdf';

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
     * Download or stream official Library Clearance & No-Dues Certificate PDF.
     */
    public function downloadClearance(Request $request, LibraryDeposit $deposit): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $isOwner = $deposit->user_id === $user->id;
        $isStaff = $user->can('library.circulation.manage') || $user->hasRole('Super Administrator') || $user->hasRole('Librarian') || $user->can('payments.manage');

        if (! $isOwner && ! $isStaff) {
            abort(403, 'Unauthorized access to library clearance certificate.');
        }

        if ($deposit->clearance_status !== LibraryDeposit::CLEARANCE_CLEARED) {
            abort(404, 'Clearance certificate has not been issued yet for this deposit.');
        }

        return LibraryClearanceService::generateCertificatePdf($deposit);
    }
}
