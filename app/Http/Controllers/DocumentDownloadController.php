<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    /**
     * Download a specific document version file.
     */
    public function downloadVersion(DocumentVersion $version): BinaryFileResponse|StreamedResponse|Response
    {
        Gate::authorize('download', $version->document);

        if (! Storage::disk('public')->exists($version->file_path)) {
            abort(404, __('The requested document file could not be found on storage disk.'));
        }

        // Increment download counters
        $version->increment('download_count');
        $version->document->increment('total_downloads_count');

        // Audit Trail
        AuditLogService::log(
            event: 'document_downloaded',
            description: "Downloaded file '{$version->original_file_name}' (version {$version->version_number}) for document '{$version->document->title}'.",
            auditable: $version->document,
            newValues: [
                'version_id' => $version->id,
                'version_number' => $version->version_number,
                'file_name' => $version->original_file_name,
                'file_size' => $version->file_size,
            ]
        );

        $path = Storage::disk('public')->path($version->file_path);

        return response()->download($path, $version->original_file_name, [
            'Content-Type' => $version->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Download the latest/current version of a document.
     */
    public function downloadLatest(Document $document): BinaryFileResponse|StreamedResponse|Response
    {
        Gate::authorize('download', $document);

        $version = $document->currentVersion;
        if (! $version) {
            abort(404, __('No active version exists for this document.'));
        }

        return $this->downloadVersion($version);
    }

    /**
     * Export documents directory to a certified institutional PDF report.
     */
    public function exportPdf(Request $request): Response
    {
        Gate::authorize('export', Document::class);

        $query = Document::with(['category', 'currentVersion', 'creator'])
            ->orderBy('id', 'desc');

        if ($request->filled('q')) {
            $query->search((string) $request->input('q'));
        }
        if ($request->filled('category')) {
            $query->category($request->input('category'));
        }
        if ($request->filled('department')) {
            $query->department((string) $request->input('department'));
        }
        if ($request->filled('type')) {
            $query->fileType((string) $request->input('type'));
        }
        if ($request->filled('access')) {
            $query->accessLevel((string) $request->input('access'));
        }

        $documents = $query->limit(200)->get();

        $pdf = Pdf::loadView('pdf.documents-register', [
            'documents' => $documents,
            'title' => 'Soft Copies Document Files Register & Version Archive',
            'generatedAt' => now()->format('d M Y, h:i A'),
            'filters' => [
                'category' => $request->input('category'),
                'department' => $request->input('department'),
                'type' => $request->input('type'),
            ],
        ])->setPaper('a4', 'landscape');

        return $pdf->download('SNTCSSC_Documents_Register_'.date('Ymd_His').'.pdf');
    }

    /**
     * Provide a blank/sample CSV template for bulk importing documents.
     */
    public function sampleImportTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="documents_bulk_import_template.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Title',
                'Purpose',
                'Category',
                'Department',
                'Access Level',
                'Tags',
                'Description',
                'Notes',
                'Remarks',
            ]);
            fputcsv($handle, [
                'Civil Services Examination 2026 Comprehensive Syllabus',
                'Student Syllabus & Reference Guide',
                'Academic Circulars & Syllabi',
                'Academics',
                'public',
                'syllabus, upsc, 2026, exam',
                'Official detailed breakdown of Prelims & Mains examination papers with syllabus pointers.',
                'Reviewed by Academic Advisory Committee on 15 Oct 2026.',
                'Approved for public distribution.',
            ]);
            fputcsv($handle, [
                'Hostel Code of Conduct & Maintenance Regulations',
                'Resident Rules & Code of Ethics',
                'Policy Documents',
                'Administration',
                'internal',
                'hostel, rules, code of conduct',
                'Mandatory institutional residence code for admitted scholars and hostelers.',
                'Annual revision applicable for session 2026-27.',
                'Ratified by Director & Warden.',
            ]);
            fclose($handle);
        }, 200, $headers);
    }
}
