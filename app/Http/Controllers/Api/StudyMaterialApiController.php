<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudyMaterialDetailResource;
use App\Http\Resources\StudyMaterialFileResource;
use App\Http\Resources\StudyMaterialResource;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialFile;
use App\Models\StudyMaterialType;
use App\Services\StudyMaterial\StudyMaterialService;
use App\Services\StudyMaterial\StudyMaterialTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use ZipArchive;

class StudyMaterialApiController extends Controller
{
    public function __construct(
        protected StudyMaterialService $studyMaterialService,
        protected StudyMaterialTypeService $studyMaterialTypeService
    ) {}

    /**
     * Get paginated study materials list with filters and search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StudyMaterial::with(['course', 'batches', 'files'])
            ->published();

        $user = $request->user();
        $apiKey = $request->attributes->get('api_key');
        $hasFullAccess = ($apiKey && $apiKey->hasScope('read:materials'))
            || ($user && ($user->can('study_materials.view') || $user->hasRole('Super Administrator')));

        if (! $hasFullAccess) {
            $query->where(function ($q) {
                $q->whereNull('course_id')
                    ->orWhere('access_level', StudyMaterial::ACCESS_PUBLIC_LINK)
                    ->orWhereIn('material_type', [StudyMaterial::TYPE_CURRENT_AFFAIRS, StudyMaterial::TYPE_PIB_SANDHAN])
                    ->orWhereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
            });
        }

        // Search query
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('topic', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by course
        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        // Filter by batch
        if ($batchId = $request->input('batch_id')) {
            $query->whereHas('batches', fn ($b) => $b->where('batches.id', $batchId));
        }

        // Filter by type
        if ($type = $request->input('material_type') ?: $request->input('type')) {
            $query->where('material_type', $type);
        }

        // Pinned only
        if ($request->boolean('pinned')) {
            $query->where('is_pinned', true);
        }

        // Sorting
        $sort = $request->input('sort', 'order');
        match ($sort) {
            'latest' => $query->latest(),
            'oldest' => $query->oldest(),
            'downloads' => $query->orderByDesc('download_count'),
            'views' => $query->orderByDesc('view_count'),
            'title' => $query->orderBy('title'),
            'class_date' => $query->orderByDesc('class_date')->latest('id'),
            default => $query->ordered(),
        };

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        return StudyMaterialResource::collection($query->paginate($perPage));
    }

    /**
     * Create a new study material (administrative & write scopes).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'course_id' => 'nullable|exists:courses,id',
            'batch_ids' => 'nullable|array',
            'batch_ids.*' => 'exists:batches,id',
            'student_ids' => 'nullable|array',
            'material_type' => 'nullable|string|max:50',
            'subject' => 'nullable|string|max:100',
            'topic' => 'nullable|string|max:255',
            'module_code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'external_url' => 'nullable|url|max:255',
            'thumbnail_url' => 'nullable|url|max:255',
            'class_date' => 'nullable|date',
            'display_order' => 'nullable|integer|min:0',
            'access_level' => 'nullable|string|in:enrolled_only,public_link,restricted_students',
            'share_password' => 'nullable|string|max:100',
            'share_expires_at' => 'nullable|date',
            'is_pinned' => 'nullable|boolean',
            'status' => 'nullable|string|in:draft,published,archived',
            'files' => 'nullable|array',
            'files.*' => 'nullable|file|max:51200|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,zip,rar,mp4,mp3,webm,jpg,jpeg,png',
        ]);

        try {
            $uploadedFiles = $request->file('files') ?: [];

            $material = $this->studyMaterialService->createMaterial(
                $validated,
                is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles]
            );

            return response()->json([
                'success' => true,
                'message' => __('Study material created successfully.'),
                'data' => new StudyMaterialDetailResource($material->load(['course', 'batches', 'files', 'creator'])),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to create study material: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to create study material: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Get detailed study material dossier by ID or UUID.
     */
    public function show(string $identifier, Request $request): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::with(['course', 'batches', 'files', 'creator'])->findOrFail($identifier)
            : StudyMaterial::with(['course', 'batches', 'files', 'creator'])->where('uuid', $identifier)->firstOrFail();

        $user = $request->user();
        $apiKey = $request->attributes->get('api_key');
        $hasFullAccess = ($apiKey && $apiKey->hasScope('read:materials'))
            || ($user && ($user->can('study_materials.view') || $user->hasRole('Super Administrator')));

        if (! $hasFullAccess && ! $material->isAccessibleBy($user)) {
            abort(403, __('You do not have permission to access this study material.'));
        }

        return response()->json([
            'success' => true,
            'data' => new StudyMaterialDetailResource($material),
        ]);
    }

    /**
     * Update an existing study material.
     */
    public function update(string $identifier, Request $request): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::findOrFail($identifier)
            : StudyMaterial::where('uuid', $identifier)->firstOrFail();

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'course_id' => 'nullable|exists:courses,id',
            'batch_ids' => 'nullable|array',
            'batch_ids.*' => 'exists:batches,id',
            'student_ids' => 'nullable|array',
            'material_type' => 'nullable|string|max:50',
            'subject' => 'nullable|string|max:100',
            'topic' => 'nullable|string|max:255',
            'module_code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'external_url' => 'nullable|url|max:255',
            'thumbnail_url' => 'nullable|url|max:255',
            'class_date' => 'nullable|date',
            'display_order' => 'nullable|integer|min:0',
            'access_level' => 'nullable|string|in:enrolled_only,public_link,restricted_students',
            'share_password' => 'nullable|string|max:100',
            'remove_share_password' => 'nullable|boolean',
            'share_expires_at' => 'nullable|date',
            'is_pinned' => 'nullable|boolean',
            'status' => 'nullable|string|in:draft,published,archived',
            'delete_file_ids' => 'nullable|array',
            'delete_file_ids.*' => 'integer',
            'files' => 'nullable|array',
            'files.*' => 'nullable|file|max:51200|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,zip,rar,mp4,mp3,webm,jpg,jpeg,png',
        ]);

        try {
            $newFiles = $request->file('files') ?: [];
            $deleteFileIds = $validated['delete_file_ids'] ?? [];

            $updated = $this->studyMaterialService->updateMaterial(
                $material,
                $validated,
                is_array($newFiles) ? $newFiles : [$newFiles],
                $deleteFileIds
            );

            return response()->json([
                'success' => true,
                'message' => __('Study material updated successfully.'),
                'data' => new StudyMaterialDetailResource($updated),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to update study material: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to update study material: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Soft delete a study material.
     */
    public function destroy(string $identifier): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::findOrFail($identifier)
            : StudyMaterial::where('uuid', $identifier)->firstOrFail();

        try {
            $this->studyMaterialService->deleteMaterial($material);

            return response()->json([
                'success' => true,
                'message' => __('Study material deleted successfully.'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to delete study material: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to delete study material: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Upload and attach new files to an existing study material.
     */
    public function uploadFiles(string $identifier, Request $request): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::findOrFail($identifier)
            : StudyMaterial::where('uuid', $identifier)->firstOrFail();

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:51200|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,zip,rar,mp4,mp3,webm,jpg,jpeg,png',
        ]);

        try {
            $files = $request->file('files') ?: [];
            $attachedModels = $this->studyMaterialService->attachFiles($material, is_array($files) ? $files : [$files], $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Uploaded :count file(s) successfully.', ['count' => count($attachedModels)]),
                'data' => StudyMaterialFileResource::collection(collect($attachedModels)),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to attach files: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to attach files: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Delete an attached file.
     */
    public function deleteFile(string $identifier, int $fileId, Request $request): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::findOrFail($identifier)
            : StudyMaterial::where('uuid', $identifier)->firstOrFail();

        try {
            $this->studyMaterialService->detachFile($material, $fileId, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('File attachment deleted successfully.'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to delete file attachment: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to delete file attachment: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Get study materials for a specific course.
     */
    public function byCourse(Course $course, Request $request): AnonymousResourceCollection
    {
        $query = $course->studyMaterials()
            ->with(['batches', 'files'])
            ->published();

        $user = $request->user();
        $apiKey = $request->attributes->get('api_key');
        $hasFullAccess = ($apiKey && $apiKey->hasScope('read:materials'))
            || ($user && ($user->can('study_materials.view') || $user->hasRole('Super Administrator')));

        if (! $hasFullAccess) {
            $query->where(function ($q) {
                $q->where('access_level', StudyMaterial::ACCESS_PUBLIC_LINK)
                    ->orWhereIn('material_type', [StudyMaterial::TYPE_CURRENT_AFFAIRS, StudyMaterial::TYPE_PIB_SANDHAN])
                    ->orWhereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        return StudyMaterialResource::collection($query->latest()->paginate($perPage));
    }

    /**
     * Get study materials for a specific batch.
     */
    public function byBatch(Batch $batch, Request $request): AnonymousResourceCollection
    {
        $query = $batch->studyMaterials()
            ->with(['course', 'files'])
            ->published();

        $user = $request->user();
        $apiKey = $request->attributes->get('api_key');
        $hasFullAccess = ($apiKey && $apiKey->hasScope('read:materials'))
            || ($user && ($user->can('study_materials.view') || $user->hasRole('Super Administrator')));

        if (! $hasFullAccess) {
            $query->where(function ($q) {
                $q->where('access_level', StudyMaterial::ACCESS_PUBLIC_LINK)
                    ->orWhereIn('material_type', [StudyMaterial::TYPE_CURRENT_AFFAIRS, StudyMaterial::TYPE_PIB_SANDHAN])
                    ->orWhereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        return StudyMaterialResource::collection($query->latest()->paginate($perPage));
    }

    /**
     * Dynamic Material Types catalog with counts.
     */
    public function types(Request $request): JsonResponse
    {
        $query = StudyMaterialType::ordered()
            ->withCount(['studyMaterials' => fn ($q) => $q->published()]);

        if (! $request->boolean('all')) {
            $query->active();
        }

        $types = $query->get()->map(fn (StudyMaterialType $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'code' => $t->code,
            'description' => $t->description,
            'icon' => $t->icon,
            'badge_color' => $t->badge_color,
            'is_public' => $t->is_public,
            'is_system' => $t->is_system,
            'is_active' => $t->is_active,
            'display_order' => $t->display_order,
            'materials_count' => $t->study_materials_count,
        ]);

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * Create custom study material type.
     */
    public function storeType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50|unique:study_material_types,code',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
            'badge_color' => 'nullable|string|max:30',
            'is_public' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'display_order' => 'nullable|integer',
        ]);

        try {
            $type = $this->studyMaterialTypeService->createType($validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Material type created successfully.'),
                'data' => $type,
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to create material type: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to create material type: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Update custom study material type.
     */
    public function updateType(int $id, Request $request): JsonResponse
    {
        $type = StudyMaterialType::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'code' => 'nullable|string|max:50|unique:study_material_types,code,'.$type->id,
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
            'badge_color' => 'nullable|string|max:30',
            'is_public' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'display_order' => 'nullable|integer',
        ]);

        try {
            $updated = $this->studyMaterialTypeService->updateType($type, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Material type updated successfully.'),
                'data' => $updated,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to update material type: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to update material type: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Delete custom study material type.
     */
    public function destroyType(int $id, Request $request): JsonResponse
    {
        $type = StudyMaterialType::findOrFail($id);

        try {
            $this->studyMaterialTypeService->deleteType($type, $request->user());

            return response()->json([
                'success' => true,
                'message' => __('Material type deleted successfully.'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Failed to delete material type: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to delete material type: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Client View Telemetry (mobile app, desktop client, or web portal).
     */
    public function trackView(string $identifier, Request $request): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::findOrFail($identifier)
            : StudyMaterial::where('uuid', $identifier)->firstOrFail();

        $platform = $request->header('X-Client-Platform') ?: $request->input('platform', 'web');

        // Increment view count
        $material->increment('view_count');

        // Record access log
        StudyMaterialAccessLog::create([
            'study_material_id' => $material->id,
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip() ?: '127.0.0.1',
            'user_agent' => Str::limit($request->userAgent() ?: "Client-{$platform}", 255, ''),
            'action' => 'view',
        ]);

        return response()->json([
            'success' => true,
            'message' => __('View recorded.'),
            'view_count' => $material->view_count,
        ]);
    }

    /**
     * Issue a secure, time-limited download token for streaming without direct file access.
     */
    public function downloadToken(string $identifier, Request $request): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::with('files')->findOrFail($identifier)
            : StudyMaterial::with('files')->where('uuid', $identifier)->firstOrFail();

        $fileId = $request->input('file_id');
        $file = $fileId ? $material->files->firstWhere('id', $fileId) : $material->files->first();

        if (! $file) {
            return response()->json([
                'success' => false,
                'message' => __('No file attachment found for this study material.'),
            ], 404);
        }

        $token = 'sntc_dl_'.Str::random(40);
        $ttlSeconds = 1800; // 30 minutes validity

        Cache::put("study_material_token_{$token}", [
            'material_id' => $material->id,
            'file_id' => $file->id,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ], $ttlSeconds);

        return response()->json([
            'success' => true,
            'token' => $token,
            'file_name' => $file->file_name,
            'file_size' => $file->file_size,
            'formatted_size' => $file->formatted_size,
            'download_url' => route('api.v1.study-materials.token-download', ['token' => $token]),
            'expires_in_seconds' => $ttlSeconds,
        ]);
    }

    /**
     * Stream binary download file using secure time-limited token.
     */
    public function downloadFileWithToken(string $token, Request $request): Response
    {
        $cacheKey = "study_material_token_{$token}";
        $data = Cache::get($cacheKey);

        if (! $data) {
            abort(403, __('The download link has expired or is invalid. Please request a new token.'));
        }

        $file = StudyMaterialFile::find($data['file_id']);
        if (! $file) {
            abort(404, __('The requested file was not found.'));
        }

        $disk = Storage::disk($file->disk ?? 'public');
        if (! $disk->exists($file->file_path)) {
            abort(404, __('Physical file not found on server storage.'));
        }

        // Increment stats
        $file->increment('download_count');
        if ($file->studyMaterial) {
            $file->studyMaterial->increment('download_count');
        }

        // Log download
        StudyMaterialAccessLog::create([
            'study_material_id' => $data['material_id'],
            'user_id' => $data['user_id'] ?? null,
            'ip_address' => $request->ip() ?: '127.0.0.1',
            'user_agent' => Str::limit($request->userAgent() ?: 'MobileApp-Downloader', 255, ''),
            'action' => 'download',
        ]);

        $fullPath = $disk->path($file->file_path);

        // Security check: ensure path traversal is completely prevented
        $diskRoot = realpath($disk->path(''));
        $realFilePath = realpath($fullPath);
        if ($diskRoot && $realFilePath && ! str_starts_with($realFilePath, $diskRoot)) {
            abort(403, __('Unauthorized file access detected.'));
        }

        return response()->download($fullPath, $file->file_name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
        ]);
    }

    /**
     * Download all file attachments of a study material bundled into a single ZIP archive.
     */
    public function downloadZip(string $identifier, Request $request): Response
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::with('files')->findOrFail($identifier)
            : StudyMaterial::with('files')->where('uuid', $identifier)->firstOrFail();

        $user = $request->user();
        $apiKey = $request->attributes->get('api_key');
        $hasFullAccess = ($apiKey && ($apiKey->hasScope('read:materials') || $apiKey->hasScope('download:materials')))
            || ($user && ($user->can('study_materials.view') || $user->hasRole('Super Administrator')));

        if (! $hasFullAccess && ! $material->isAccessibleBy($user)) {
            abort(403, __('You do not have permission to access this study material.'));
        }

        $files = $material->files;
        if ($files->isEmpty()) {
            abort(404, __('No file attachments found in this study material to package.'));
        }

        $zipFileName = Str::slug($material->title).'-files.zip';
        $tempPath = tempnam(sys_get_temp_dir(), 'sntc_zip_');

        $zip = new ZipArchive;
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, __('Unable to initialize archive package.'));
        }

        $addedCount = 0;
        foreach ($files as $file) {
            $disk = Storage::disk($file->disk ?? 'public');
            if ($disk->exists($file->file_path)) {
                $fullPath = $disk->path($file->file_path);
                // Sanitize entry filename inside zip
                $entryName = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $file->file_name);
                $zip->addFile($fullPath, $entryName);
                $file->increment('download_count');
                $addedCount++;
            }
        }

        $zip->close();

        if ($addedCount === 0) {
            @unlink($tempPath);
            abort(404, __('None of the attached files could be located on server storage.'));
        }

        $material->increment('download_count');

        // Log download access
        StudyMaterialAccessLog::create([
            'study_material_id' => $material->id,
            'user_id' => $user?->id,
            'ip_address' => $request->ip() ?: '127.0.0.1',
            'user_agent' => Str::limit($request->userAgent() ?: 'BatchDownloader/1.0', 255, ''),
            'action' => 'download_zip',
        ]);

        return response()->download($tempPath, $zipFileName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Personalized student study materials feed (Mobile App & Student Portal).
     */
    public function myMaterials(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $studentId = $request->input('student_id');

        // Resolve enrolled course and batch IDs
        $enrolledBatchIds = [];
        $enrolledCourseIds = [];

        if ($user) {
            $enrollments = Enrollment::where('user_id', $user->id)
                ->where('status', 'enrolled')
                ->get();

            $enrolledBatchIds = $enrollments->pluck('batch_id')->filter()->unique()->toArray();
            $enrolledCourseIds = $enrollments->pluck('course_id')->filter()->unique()->toArray();
        } elseif ($studentId) {
            $enrollments = Enrollment::where('student_id', $studentId)
                ->where('status', 'enrolled')
                ->get();

            $enrolledBatchIds = $enrollments->pluck('batch_id')->filter()->unique()->toArray();
            $enrolledCourseIds = $enrollments->pluck('course_id')->filter()->unique()->toArray();
        }

        $query = StudyMaterial::with(['course', 'batches', 'files'])
            ->published()
            ->where(function ($q) use ($enrolledCourseIds, $enrolledBatchIds, $user, $studentId) {
                // Public materials
                $q->where('access_level', StudyMaterial::ACCESS_PUBLIC_LINK)
                    ->orWhereIn('material_type', [StudyMaterial::TYPE_CURRENT_AFFAIRS, StudyMaterial::TYPE_PIB_SANDHAN]);

                // Materials of enrolled batches
                if (! empty($enrolledBatchIds)) {
                    $q->orWhereHas('batches', fn ($b) => $b->whereIn('batches.id', $enrolledBatchIds));
                }

                // Materials of enrolled courses
                if (! empty($enrolledCourseIds)) {
                    $q->orWhereIn('course_id', $enrolledCourseIds);
                }

                // Explicitly assigned to student
                if ($user || $studentId) {
                    $q->orWhereHas('students', function ($sq) use ($user, $studentId) {
                        if ($user) {
                            $sq->where('users.id', $user->id);
                        }
                        if ($studentId) {
                            $sq->orWhere('users.id', $studentId);
                        }
                    });
                }
            });

        // Search & Type filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('topic', 'like', "%{$search}%");
            });
        }

        if ($type = $request->input('material_type')) {
            $query->where('material_type', $type);
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return StudyMaterialResource::collection($query->latest()->paginate($perPage));
    }

    /**
     * Generate or refresh secure share link for a study material.
     */
    public function shareLink(string $identifier, Request $request): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::findOrFail($identifier)
            : StudyMaterial::where('uuid', $identifier)->firstOrFail();

        $validated = $request->validate([
            'share_password' => 'nullable|string|max:100',
            'share_expires_at' => 'nullable|date|after:now',
            'regenerate_token' => 'nullable|boolean',
        ]);

        $updatePayload = [];

        if ($request->boolean('regenerate_token') || empty($material->share_token)) {
            $updatePayload['share_token'] = Str::random(40);
        }

        if (array_key_exists('share_password', $validated)) {
            $updatePayload['share_password'] = ! empty($validated['share_password']) ? bcrypt($validated['share_password']) : null;
        }

        if (array_key_exists('share_expires_at', $validated)) {
            $updatePayload['share_expires_at'] = $validated['share_expires_at'];
        }

        try {
            if (! empty($updatePayload)) {
                $material->update($updatePayload);
            }

            return response()->json([
                'success' => true,
                'message' => __('Share link updated.'),
                'data' => [
                    'share_url' => $material->public_share_url,
                    'share_token' => $material->share_token,
                    'has_password' => ! empty($material->share_password),
                    'share_expires_at' => $material->share_expires_at?->toIso8601String(),
                    'is_expired' => $material->isExpired(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to update share link: {$e->getMessage()}", ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to update share link: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Get real-time study materials KPI statistics.
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->studyMaterialService->getStatistics();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
