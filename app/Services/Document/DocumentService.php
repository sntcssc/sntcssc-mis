<?php

namespace App\Services\Document;

use App\Concerns\RenamesUploads;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class DocumentService
{
    use RenamesUploads;

    /**
     * Allowed file types and extensions for soft copy documents.
     */
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'webp', 'svg', 'gif',
        'zip', 'rar', '7z', 'tar', 'gz', 'txt', 'rtf',
    ];

    /**
     * Maximum upload file size in kilobytes (50 MB).
     */
    public const MAX_FILE_SIZE_KB = 51200;

    /**
     * Create a new Document and its initial DocumentVersion (v1.0) with file upload.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public static function createDocument(array $data, UploadedFile $file, ?User $user = null): Document
    {
        return DB::transaction(function () use ($data, $file, $user) {
            $userId = $user?->id ?? auth()->id();

            // 1. Prepare master document
            $documentNumber = ! empty($data['document_number'])
                ? trim($data['document_number'])
                : Document::generateDocumentNumber();

            $tags = null;
            if (! empty($data['tags'])) {
                $tags = is_array($data['tags'])
                    ? array_values(array_filter(array_map('trim', $data['tags'])))
                    : array_values(array_filter(array_map('trim', explode(',', (string) $data['tags']))));
            }

            $document = Document::create([
                'document_number' => $documentNumber,
                'title' => trim($data['title']),
                'purpose' => ! empty($data['purpose']) ? trim($data['purpose']) : null,
                'document_category_id' => ! empty($data['document_category_id']) ? (int) $data['document_category_id'] : null,
                'department' => ! empty($data['department']) ? trim($data['department']) : null,
                'tags' => $tags,
                'access_level' => $data['access_level'] ?? 'internal',
                'status' => $data['status'] ?? 'published',
                'description' => ! empty($data['description']) ? trim($data['description']) : null,
                'notes' => ! empty($data['notes']) ? trim($data['notes']) : null,
                'remarks' => ! empty($data['remarks']) ? trim($data['remarks']) : null,
                'total_versions_count' => 1,
                'total_downloads_count' => 0,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // 2. Store physical file securely using RenamesUploads
            $originalName = $file->getClientOriginalName();
            $fileSize = $file->getSize() ?: 0;
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $fileHash = hash_file('sha256', $file->getRealPath());

            $folder = 'documents/'.Str::slug($document->document_number);
            $typePrefix = 'doc_'.$document->document_number.'_v1_0';
            $safeFileName = (new self)->generateFileName($typePrefix, $file, $userId ?? 'sys');
            $extension = strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION) ?: ($file->getClientOriginalExtension() ?: 'bin'));
            $filePath = $file->storeAs($folder, $safeFileName, 'public');

            // 3. Create initial DocumentVersion
            $versionNumber = ! empty($data['version_number']) ? trim($data['version_number']) : '1.0';
            $versionTitle = ! empty($data['version_title']) ? trim($data['version_title']) : 'Initial Release';

            $version = DocumentVersion::create([
                'document_id' => $document->id,
                'version_number' => $versionNumber,
                'revision_sequence' => 1,
                'version_title' => $versionTitle,
                'file_path' => $filePath,
                'original_file_name' => $originalName,
                'file_type' => $extension,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'file_hash' => $fileHash,
                'change_summary' => ! empty($data['change_summary']) ? trim($data['change_summary']) : 'Initial document upload',
                'change_description' => ! empty($data['change_description']) ? trim($data['change_description']) : null,
                'remarks' => ! empty($data['version_remarks']) ? trim($data['version_remarks']) : null,
                'is_current' => true,
                'download_count' => 0,
                'uploaded_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
                'effective_date' => ! empty($data['effective_date']) ? Carbon::parse($data['effective_date']) : now()->toDateString(),
            ]);

            // 4. Update parent document current_version_id
            $document->update([
                'current_version_id' => $version->id,
            ]);

            // 5. Record Audit Trail
            AuditLogService::log(
                event: 'document_created',
                description: "Created document '{$document->title}' ({$document->document_number}) with initial version {$version->version_number}.",
                auditable: $document,
                newValues: [
                    'document_number' => $document->document_number,
                    'title' => $document->title,
                    'file_name' => $originalName,
                    'version' => $version->version_number,
                    'size' => $fileSize,
                ]
            );

            return $document->load(['currentVersion', 'category', 'versions']);
        });
    }

    /**
     * Upload a revised file to an existing document, creating a new DocumentVersion
     * and preserving all previous historical versions intact.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public static function uploadRevision(Document $document, array $data, UploadedFile $file, ?User $user = null): DocumentVersion
    {
        return DB::transaction(function () use ($document, $data, $file, $user) {
            $userId = $user?->id ?? auth()->id();

            // Next revision sequence number
            $nextSequence = ((int) $document->versions()->max('revision_sequence')) + 1;

            // Determine version number
            $suggestedVersion = ! empty($data['version_number'])
                ? trim($data['version_number'])
                : 'v'.number_format((float) ($document->currentVersion?->version_number ? str_replace('v', '', $document->currentVersion->version_number) : 1) + 0.1, 1);

            // Store physical file securely using RenamesUploads
            $originalName = $file->getClientOriginalName();
            $fileSize = $file->getSize() ?: 0;
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $fileHash = hash_file('sha256', $file->getRealPath());

            $folder = 'documents/'.Str::slug($document->document_number);
            $cleanVer = Str::slug(str_replace('.', '_', $suggestedVersion), '_');
            $typePrefix = 'doc_'.$document->document_number.'_'.$cleanVer;
            $safeFileName = (new self)->generateFileName($typePrefix, $file, $userId ?? 'sys');
            $extension = strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION) ?: ($file->getClientOriginalExtension() ?: 'bin'));
            $filePath = $file->storeAs($folder, $safeFileName, 'public');

            // Deactivate previous active version
            DocumentVersion::where('document_id', $document->id)->update(['is_current' => false]);

            // Create new revision
            $versionTitle = ! empty($data['version_title'])
                ? trim($data['version_title'])
                : "Revision {$nextSequence} ({$suggestedVersion})";

            $version = DocumentVersion::create([
                'document_id' => $document->id,
                'version_number' => $suggestedVersion,
                'revision_sequence' => $nextSequence,
                'version_title' => $versionTitle,
                'file_path' => $filePath,
                'original_file_name' => $originalName,
                'file_type' => $extension,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'file_hash' => $fileHash,
                'change_summary' => ! empty($data['change_summary']) ? trim($data['change_summary']) : 'Revised document upload',
                'change_description' => ! empty($data['change_description']) ? trim($data['change_description']) : null,
                'remarks' => ! empty($data['remarks']) ? trim($data['remarks']) : null,
                'is_current' => true,
                'download_count' => 0,
                'uploaded_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
                'effective_date' => ! empty($data['effective_date']) ? Carbon::parse($data['effective_date']) : now()->toDateString(),
            ]);

            // Update parent document pointer & version count
            $totalVersions = $document->versions()->count();
            $document->update([
                'current_version_id' => $version->id,
                'total_versions_count' => $totalVersions,
                'updated_by' => $userId,
            ]);

            // Record Audit Trail
            AuditLogService::log(
                event: 'document_revised',
                description: "Uploaded revised version {$version->version_number} for '{$document->title}' ({$document->document_number}).",
                auditable: $document,
                newValues: [
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'revision_sequence' => $version->revision_sequence,
                    'original_file_name' => $originalName,
                    'change_summary' => $version->change_summary,
                ]
            );

            return $version;
        });
    }

    /**
     * Set a specific historical version as current active version (Rollback).
     */
    public static function setCurrentVersion(Document $document, DocumentVersion $version, ?User $user = null): Document
    {
        return DB::transaction(function () use ($document, $version, $user) {
            $userId = $user?->id ?? auth()->id();

            DocumentVersion::where('document_id', $document->id)->update(['is_current' => false]);
            DocumentVersion::where('id', $version->id)->update(['is_current' => true]);
            $version->refresh();

            $document->update([
                'current_version_id' => $version->id,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'document_version_switched',
                description: "Designated version {$version->version_number} as active version for '{$document->title}'.",
                auditable: $document,
                newValues: [
                    'active_version_id' => $version->id,
                    'version_number' => $version->version_number,
                ]
            );

            return $document->load(['currentVersion', 'versions']);
        });
    }

    /**
     * Update document metadata.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateMetadata(Document $document, array $data, ?User $user = null): Document
    {
        return DB::transaction(function () use ($document, $data, $user) {
            $userId = $user?->id ?? auth()->id();
            $oldValues = $document->only([
                'title', 'purpose', 'document_category_id', 'department',
                'access_level', 'status', 'description', 'notes', 'remarks',
            ]);

            $tags = $document->tags;
            if (array_key_exists('tags', $data)) {
                $tags = is_array($data['tags'])
                    ? array_values(array_filter(array_map('trim', $data['tags'])))
                    : array_values(array_filter(array_map('trim', explode(',', (string) $data['tags']))));
            }

            $document->update([
                'title' => trim($data['title']),
                'purpose' => ! empty($data['purpose']) ? trim($data['purpose']) : null,
                'document_category_id' => ! empty($data['document_category_id']) ? (int) $data['document_category_id'] : null,
                'department' => ! empty($data['department']) ? trim($data['department']) : null,
                'tags' => $tags,
                'access_level' => $data['access_level'] ?? $document->access_level,
                'status' => $data['status'] ?? $document->status,
                'description' => ! empty($data['description']) ? trim($data['description']) : null,
                'notes' => ! empty($data['notes']) ? trim($data['notes']) : null,
                'remarks' => ! empty($data['remarks']) ? trim($data['remarks']) : null,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'document_updated',
                description: "Updated metadata for document '{$document->title}' ({$document->document_number}).",
                auditable: $document,
                oldValues: $oldValues,
                newValues: $document->only([
                    'title', 'purpose', 'document_category_id', 'department',
                    'access_level', 'status', 'description', 'notes', 'remarks',
                ])
            );

            return $document->fresh(['currentVersion', 'category', 'versions']);
        });
    }

    /**
     * Soft-delete document and its versions.
     */
    public static function deleteDocument(Document $document, ?User $user = null): bool
    {
        return DB::transaction(function () use ($document, $user) {
            $userId = $user?->id ?? auth()->id();

            $document->update(['deleted_by' => $userId]);
            $document->versions()->update(['deleted_by' => $userId]);
            $document->versions()->delete();
            $result = (bool) $document->delete();

            AuditLogService::log(
                event: 'document_deleted',
                description: "Soft-deleted document '{$document->title}' ({$document->document_number}).",
                auditable: $document
            );

            return $result;
        });
    }

    /**
     * Restore soft-deleted document.
     */
    public static function restoreDocument(int $documentId, ?User $user = null): ?Document
    {
        return DB::transaction(function () use ($documentId, $user) {
            $userId = $user?->id ?? auth()->id();

            $document = Document::withTrashed()->find($documentId);
            if (! $document) {
                return null;
            }

            $document->restore();
            $document->update(['deleted_by' => null, 'updated_by' => $userId]);
            $document->versions()->withTrashed()->restore();
            $document->versions()->update(['deleted_by' => null]);

            AuditLogService::log(
                event: 'document_restored',
                description: "Restored soft-deleted document '{$document->title}' ({$document->document_number}).",
                auditable: $document
            );

            return $document->fresh(['currentVersion', 'category', 'versions']);
        });
    }

    /**
     * Soft-delete a single version.
     */
    public static function deleteVersion(DocumentVersion $version, ?User $user = null): bool
    {
        return DB::transaction(function () use ($version, $user) {
            $userId = $user?->id ?? auth()->id();
            $document = $version->document;

            $version->update(['deleted_by' => $userId]);
            $version->delete();

            // If the deleted version was active, designate another remaining version as active
            if ($version->is_current) {
                $newActive = DocumentVersion::where('document_id', $document->id)
                    ->orderBy('revision_sequence', 'desc')
                    ->first();

                if ($newActive) {
                    $newActive->update(['is_current' => true]);
                    $document->update([
                        'current_version_id' => $newActive->id,
                        'total_versions_count' => $document->versions()->count(),
                        'updated_by' => $userId,
                    ]);
                } else {
                    $document->update([
                        'current_version_id' => null,
                        'total_versions_count' => 0,
                        'updated_by' => $userId,
                    ]);
                }
            } else {
                $document->update([
                    'total_versions_count' => $document->versions()->count(),
                    'updated_by' => $userId,
                ]);
            }

            AuditLogService::log(
                event: 'document_version_deleted',
                description: "Deleted version {$version->version_number} of document '{$document->title}'.",
                auditable: $document
            );

            return true;
        });
    }

    /**
     * Compute comprehensive system telemetry & statistics.
     *
     * @return array<string, mixed>
     */
    public static function getStatistics(): array
    {
        $totalDocuments = Document::count();
        $totalVersions = DocumentVersion::count();
        $totalDownloads = (int) DocumentVersion::sum('download_count');
        $totalStorageBytes = (int) DocumentVersion::sum('file_size');

        // Storage units conversion
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $storageFormatted = '0 B';
        if ($totalStorageBytes > 0) {
            $i = floor(log($totalStorageBytes, 1024));
            $storageFormatted = round($totalStorageBytes / pow(1024, $i), 2).' '.$units[$i];
        }

        // Breakdown by file type
        $fileTypes = DocumentVersion::selectRaw('file_type, count(*) as count, sum(file_size) as total_size')
            ->groupBy('file_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'type' => strtoupper($row->file_type),
                'count' => (int) $row->count,
                'size_bytes' => (int) $row->total_size,
            ])
            ->all();

        // Breakdown by category
        $byCategory = DocumentCategory::withCount('documents')
            ->orderByDesc('documents_count')
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'count' => $cat->documents_count,
                'color' => $cat->color,
            ])
            ->all();

        // Breakdown by department
        $byDepartment = Document::selectRaw('department, count(*) as count')
            ->whereNotNull('department')
            ->groupBy('department')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'department' => $row->department,
                'count' => (int) $row->count,
            ])
            ->all();

        // Recent revisions
        $recentRevisions = DocumentVersion::with(['document', 'uploader'])
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        return [
            'total_documents' => $totalDocuments,
            'total_versions' => $totalVersions,
            'total_downloads' => $totalDownloads,
            'total_storage_bytes' => $totalStorageBytes,
            'storage_formatted' => $storageFormatted,
            'file_types' => $fileTypes,
            'by_category' => $byCategory,
            'by_department' => $byDepartment,
            'recent_revisions' => $recentRevisions,
        ];
    }

    /**
     * Import document records from an Excel or CSV file.
     *
     * Expected columns in sheet:
     * - Title / Document Title
     * - Purpose
     * - Category
     * - Department
     * - Access Level (public, internal, confidential, restricted)
     * - Tags (comma-separated)
     * - Description
     * - Notes
     * - Remarks
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importFromSpreadsheet(UploadedFile|string $file, ?User $user = null): array
    {
        $filePath = is_string($file) ? $file : $file->getRealPath();
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows) || count($rows) < 2) {
            return ['imported' => 0, 'errors' => ['The uploaded file has no data rows.']];
        }

        $headerRow = array_map(fn ($col) => strtolower(trim((string) $col)), array_shift($rows));

        // Find column indices
        $colMap = [
            'title' => false,
            'purpose' => false,
            'category' => false,
            'department' => false,
            'access_level' => false,
            'tags' => false,
            'description' => false,
            'notes' => false,
            'remarks' => false,
        ];

        foreach ($headerRow as $idx => $heading) {
            if (str_contains($heading, 'title') || str_contains($heading, 'name')) {
                $colMap['title'] = $idx;
            } elseif (str_contains($heading, 'purpose')) {
                $colMap['purpose'] = $idx;
            } elseif (str_contains($heading, 'category')) {
                $colMap['category'] = $idx;
            } elseif (str_contains($heading, 'department') || str_contains($heading, 'dept')) {
                $colMap['department'] = $idx;
            } elseif (str_contains($heading, 'access') || str_contains($heading, 'level')) {
                $colMap['access_level'] = $idx;
            } elseif (str_contains($heading, 'tag')) {
                $colMap['tags'] = $idx;
            } elseif (str_contains($heading, 'desc')) {
                $colMap['description'] = $idx;
            } elseif (str_contains($heading, 'note')) {
                $colMap['notes'] = $idx;
            } elseif (str_contains($heading, 'remark')) {
                $colMap['remarks'] = $idx;
            }
        }

        if ($colMap['title'] === false) {
            return ['imported' => 0, 'errors' => ["Could not find 'Title' column in spreadsheet header."]];
        }

        $imported = 0;
        $errors = [];
        $userId = $user?->id ?? auth()->id();

        foreach ($rows as $rowNum => $row) {
            $lineNum = $rowNum + 2;
            $title = trim((string) ($row[$colMap['title']] ?? ''));

            if ($title === '') {
                continue; // Skip empty rows
            }

            try {
                $purpose = $colMap['purpose'] !== false ? trim((string) ($row[$colMap['purpose']] ?? '')) : null;
                $dept = $colMap['department'] !== false ? trim((string) ($row[$colMap['department']] ?? '')) : null;
                $access = $colMap['access_level'] !== false ? strtolower(trim((string) ($row[$colMap['access_level']] ?? 'internal'))) : 'internal';
                if (! in_array($access, ['public', 'internal', 'confidential', 'restricted'], true)) {
                    $access = 'internal';
                }

                $tagsRaw = $colMap['tags'] !== false ? trim((string) ($row[$colMap['tags']] ?? '')) : '';
                $tags = ! empty($tagsRaw) ? array_values(array_filter(array_map('trim', explode(',', $tagsRaw)))) : null;
                $desc = $colMap['description'] !== false ? trim((string) ($row[$colMap['description']] ?? '')) : null;
                $notes = $colMap['notes'] !== false ? trim((string) ($row[$colMap['notes']] ?? '')) : null;
                $remarks = $colMap['remarks'] !== false ? trim((string) ($row[$colMap['remarks']] ?? '')) : null;

                // Category match or create
                $categoryId = null;
                if ($colMap['category'] !== false) {
                    $catName = trim((string) ($row[$colMap['category']] ?? ''));
                    if ($catName !== '') {
                        $cat = DocumentCategory::firstOrCreate(
                            ['name' => $catName],
                            ['slug' => Str::slug($catName), 'icon' => 'folder', 'color' => 'blue', 'is_active' => true]
                        );
                        $categoryId = $cat->id;
                    }
                }

                Document::create([
                    'document_number' => Document::generateDocumentNumber(),
                    'title' => $title,
                    'purpose' => $purpose ?: null,
                    'document_category_id' => $categoryId,
                    'department' => $dept ?: null,
                    'tags' => $tags,
                    'access_level' => $access,
                    'status' => 'published',
                    'description' => $desc ?: null,
                    'notes' => $notes ?: null,
                    'remarks' => $remarks ?: null,
                    'total_versions_count' => 0,
                    'total_downloads_count' => 0,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $imported++;
            } catch (Throwable $e) {
                $errors[] = "Row {$lineNum}: ".$e->getMessage();
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }
}
