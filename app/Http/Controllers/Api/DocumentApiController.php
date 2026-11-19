<?php

namespace App\Http\Controllers\Api;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Services\Document\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentApiController extends Controller
{
    /**
     * List documents with dynamic filtering, search, sorting, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Document::query()
            ->with(['currentVersion', 'category', 'creator'])
            ->withCount('versions');

        if ($request->filled('q')) {
            $query->search((string) $request->input('q'));
        }

        if ($request->filled('category_id')) {
            $query->category($request->input('category_id'));
        }

        if ($request->filled('department')) {
            $query->department((string) $request->input('department'));
        }

        if ($request->filled('file_type')) {
            $query->fileType((string) $request->input('file_type'));
        }

        if ($request->filled('access_level')) {
            $query->accessLevel((string) $request->input('access_level'));
        }

        if ($request->filled('status')) {
            $query->status((string) $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower((string) $request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'document_number', 'title', 'total_versions_count', 'total_downloads_count', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $perPage = min(max((int) ($request->input('per_page', 15)), 1), 100);
        $documents = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $documents->items(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    /**
     * Get real-time system statistics and telemetry for documents.
     */
    public function statistics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => DocumentService::getStatistics(),
        ]);
    }

    /**
     * List active document categories taxonomy.
     */
    public function categories(): JsonResponse
    {
        $categories = DocumentCategory::active()
            ->withCount('documents')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get single document details with complete version timeline and download links.
     */
    public function show(int $id): JsonResponse
    {
        $document = Document::with([
            'category',
            'creator',
            'currentVersion',
            'versions' => fn ($q) => $q->orderBy('revision_sequence', 'desc')->with('uploader'),
        ])->find($id);

        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => $document,
        ]);
    }

    /**
     * Upload a new soft copy document along with its initial file version (v1.0).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:'.DocumentService::MAX_FILE_SIZE_KB,
                'mimes:'.implode(',', DocumentService::ALLOWED_EXTENSIONS),
            ],
            'title' => 'required|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'document_category_id' => 'nullable|exists:document_categories,id',
            'department' => 'nullable|string|max:100',
            'tags' => 'nullable',
            'access_level' => 'nullable|in:public,internal,confidential,restricted',
            'status' => 'nullable|in:draft,published,archived',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
            'version_number' => 'nullable|string|max:30',
            'version_title' => 'nullable|string|max:255',
            'change_summary' => 'nullable|string',
            'effective_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $document = DocumentService::createDocument(
                $validator->validated(),
                $request->file('file'),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded and registered successfully.',
                'data' => $document,
            ], Response::HTTP_CREATED);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create document: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update document metadata without altering file versions.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $document = Document::find($id);
        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'document_category_id' => 'nullable|exists:document_categories,id',
            'department' => 'nullable|string|max:100',
            'tags' => 'nullable',
            'access_level' => 'nullable|in:public,internal,confidential,restricted',
            'status' => 'nullable|in:draft,published,archived',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $updated = DocumentService::updateMetadata($document, $validator->validated(), $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Document metadata updated successfully.',
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft-delete document and all associated version archives.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $document = Document::find($id);
        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            DocumentService::deleteDocument($document, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Document and versions soft-deleted successfully.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Restore soft-deleted document.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        try {
            $document = DocumentService::restoreDocument($id, $request->user());
            if (! $document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Soft-deleted document not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'message' => 'Document restored successfully.',
                'data' => $document,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore document: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List all version records and revision history for a document.
     */
    public function versions(int $id): JsonResponse
    {
        $document = Document::find($id);
        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $versions = $document->versions()->with('uploader')->get();

        return response()->json([
            'success' => true,
            'data' => $versions,
        ]);
    }

    /**
     * Upload a revised file version to an existing document.
     */
    public function uploadRevision(Request $request, int $id): JsonResponse
    {
        $document = Document::find($id);
        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:'.DocumentService::MAX_FILE_SIZE_KB,
                'mimes:'.implode(',', DocumentService::ALLOWED_EXTENSIONS),
            ],
            'version_number' => 'nullable|string|max:30',
            'version_title' => 'nullable|string|max:255',
            'change_summary' => 'required|string|max:500',
            'change_description' => 'nullable|string',
            'remarks' => 'nullable|string',
            'effective_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $version = DocumentService::uploadRevision(
                $document,
                $validator->validated(),
                $request->file('file'),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => "Revised version {$version->version_number} uploaded successfully. Historical versions preserved.",
                'data' => $version,
            ], Response::HTTP_CREATED);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload revision: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Rollback or switch active version to a specified revision.
     */
    public function setCurrentVersion(Request $request, int $id, int $versionId): JsonResponse
    {
        $document = Document::find($id);
        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $version = DocumentVersion::where('document_id', $document->id)->find($versionId);
        if (! $version) {
            return response()->json([
                'success' => false,
                'message' => 'Version not found for this document.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $updated = DocumentService::setCurrentVersion($document, $version, $request->user());

            return response()->json([
                'success' => true,
                'message' => "Version {$version->version_number} is now the active version.",
                'data' => $updated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to switch version: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download or stream a specific version file.
     */
    public function downloadVersion(int $id, int $versionId): BinaryFileResponse|JsonResponse
    {
        $document = Document::find($id);
        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $version = DocumentVersion::where('document_id', $document->id)->find($versionId);
        if (! $version) {
            return response()->json([
                'success' => false,
                'message' => 'Version not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! Storage::disk('public')->exists($version->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Physical file not found on disk storage.',
            ], Response::HTTP_NOT_FOUND);
        }

        $version->increment('download_count');
        $document->increment('total_downloads_count');

        $path = Storage::disk('public')->path($version->file_path);

        return response()->download($path, $version->original_file_name, [
            'Content-Type' => $version->mime_type ?: 'application/octet-stream',
        ]);
    }

    /**
     * Download or stream the current/active version file of a document.
     */
    public function downloadLatest(int $id): BinaryFileResponse|JsonResponse
    {
        $document = Document::find($id);
        if (! $document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $version = $document->currentVersion;
        if (! $version) {
            return response()->json([
                'success' => false,
                'message' => 'No active version exists for this document.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->downloadVersion($document->id, $version->id);
    }

    /**
     * Create a new document category taxonomy via API.
     */
    public function storeCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150|unique:document_categories,name',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'display_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $category = DocumentCategory::create([
                'name' => trim((string) $request->input('name')),
                'slug' => Str::slug($request->input('name')),
                'description' => $request->filled('description') ? trim((string) $request->input('description')) : null,
                'icon' => $request->input('icon', 'folder'),
                'color' => $request->input('color', 'blue'),
                'display_order' => (int) $request->input('display_order', 0),
                'is_active' => true,
                'created_by' => $request->user()?->id ?? auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Document category created successfully.',
                'data' => $category,
            ], Response::HTTP_CREATED);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Bulk import document metadata records from an uploaded spreadsheet file (Excel/CSV).
     */
    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,csv,xls|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = DocumentService::importFromSpreadsheet($request->file('file'), $request->user());

            return response()->json([
                'success' => true,
                'message' => "Successfully imported {$result['imported']} documents.",
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download the standard CSV template for bulk importing documents.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="documents_bulk_import_template.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Title', 'Purpose', 'Category', 'Department', 'Access Level',
                'Tags', 'Description', 'Notes', 'Remarks',
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
            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Export documents directory register as Excel (.xlsx), CSV (.csv), or JSON.
     */
    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        $query = Document::with(['category', 'currentVersion', 'creator'])
            ->orderBy('id', 'desc');

        if ($request->filled('q')) {
            $query->search((string) $request->input('q'));
        }
        if ($request->filled('category_id')) {
            $query->category($request->input('category_id'));
        }
        if ($request->filled('department')) {
            $query->department((string) $request->input('department'));
        }
        if ($request->filled('file_type')) {
            $query->fileType((string) $request->input('file_type'));
        }
        if ($request->filled('access_level')) {
            $query->accessLevel((string) $request->input('access_level'));
        }
        if ($request->filled('status')) {
            $query->status((string) $request->input('status'));
        }

        $format = strtolower((string) $request->input('format', 'csv'));

        if ($format === 'json') {
            return response()->json([
                'success' => true,
                'total' => $query->count(),
                'data' => $query->limit(500)->get(),
            ]);
        }

        $headings = [
            'Document Number', 'Title', 'Purpose', 'Category', 'Department',
            'Access Level', 'Status', 'Active Version', 'File Name', 'File Type',
            'File Size', 'Total Revisions', 'Downloads', 'Created At',
        ];

        $mapper = function (Document $doc) {
            $ver = $doc->currentVersion;

            return [
                $doc->document_number,
                $doc->title,
                $doc->purpose ?? 'N/A',
                $doc->category?->name ?? 'Uncategorized',
                $doc->department ?? 'General',
                strtoupper($doc->access_level),
                strtoupper($doc->status),
                $ver ? 'v'.$ver->version_number : 'N/A',
                $ver?->original_file_name ?? 'N/A',
                $ver ? strtoupper($ver->file_type) : 'N/A',
                $ver?->formatted_file_size ?? '0 B',
                $doc->total_versions_count,
                $doc->total_downloads_count,
                $doc->created_at?->toDateTimeString() ?? 'N/A',
            ];
        };

        $extension = in_array($format, ['xlsx', 'xls'], true) ? 'xlsx' : 'csv';

        return (new TableExport($query, $headings, $mapper))
            ->download('SNTCSSC_Documents_Register_'.date('Ymd_His').'.'.$extension);
    }
}
