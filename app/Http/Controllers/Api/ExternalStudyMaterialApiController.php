<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialFile;
use App\Models\StudyMaterialType;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\OtpService;
use App\Services\StudyMaterial\StudyMaterialService;
use App\Services\StudyMaterial\StudyMaterialTypeService;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ExternalStudyMaterialApiController extends Controller
{
    public function __construct(
        protected StudyMaterialService $studyMaterialService,
        protected StudyMaterialTypeService $studyMaterialTypeService,
        protected SubscriberService $subscriberService,
        protected OtpService $otpService
    ) {}

    /**
     * Get active public material types (e.g. Current Affairs, PIB Sandhan, E-Books).
     */
    public function types(Request $request): JsonResponse
    {
        $types = StudyMaterialType::query()
            ->public()
            ->ordered()
            ->withCount(['studyMaterials' => fn ($q) => $q->published()])
            ->get()
            ->map(fn (StudyMaterialType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'description' => $t->description,
                'icon' => $t->icon,
                'badge_color' => $t->badge_color,
                'display_order' => $t->display_order,
                'materials_count' => $t->study_materials_count,
            ]);

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * Fetch study materials for external web and mobile integration.
     */
    public function index(Request $request): JsonResponse
    {
        $query = StudyMaterial::query()
            ->with(['course', 'files'])
            ->published()
            ->where(function ($q) {
                $q->where('access_level', StudyMaterial::ACCESS_PUBLIC_LINK)
                    ->orWhereNull('course_id')
                    ->orWhereIn('material_type', [StudyMaterial::TYPE_CURRENT_AFFAIRS, StudyMaterial::TYPE_PIB_SANDHAN])
                    ->orWhereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
            });

        // 1. Filter by Material Type (e.g., 'current_affairs', 'pib_sandhan')
        $type = $request->input('type') ?: $request->input('material_type');
        if ($type) {
            $query->where('material_type', $type);
        }

        // 2. Search keyword
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('topic', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // 3. Year / Month filter (useful for monthly Current Affairs and PIB Sandhan)
        if ($year = $request->input('year')) {
            $query->whereYear('created_at', $year);
        }
        if ($month = $request->input('month')) {
            $query->whereMonth('created_at', $month);
        }

        // 4. Sorting
        $sort = $request->input('sort', 'order');
        match ($sort) {
            'latest' => $query->latest(),
            'oldest' => $query->oldest(),
            'downloads' => $query->orderByDesc('download_count'),
            'views' => $query->orderByDesc('view_count'),
            'title' => $query->orderBy('title'),
            'class_date' => $query->orderByDesc('class_date')->orderByDesc('id'),
            default => $query->ordered(),
        };

        $perPage = min(max((int) $request->input('per_page', 12), 1), 50);
        $paginator = $query->paginate($perPage);

        $typesMap = StudyMaterial::materialTypes();

        $items = collect($paginator->items())->map(function (StudyMaterial $material) use ($typesMap) {
            return [
                'id' => $material->id,
                'uuid' => $material->uuid,
                'title' => $material->title,
                'slug' => $material->slug,
                'subject' => $material->subject,
                'topic' => $material->topic,
                'module_code' => $material->module_code,
                'material_type' => [
                    'code' => $material->material_type,
                    'name' => $typesMap[$material->material_type] ?? ucfirst(str_replace('_', ' ', $material->material_type)),
                ],
                'course' => $material->course ? [
                    'id' => $material->course->id,
                    'name' => $material->course->name,
                ] : null,
                'description' => $material->description,
                'thumbnail_url' => $material->thumbnail_image_url,
                'class_date' => $material->class_date?->format('Y-m-d'),
                'display_order' => (int) $material->display_order,
                'has_content' => ! empty($material->content),
                'external_url' => $material->external_url,
                'is_pinned' => (bool) $material->is_pinned,
                'published_at' => $material->created_at?->toIso8601String(),
                'view_count' => (int) $material->view_count,
                'download_count' => (int) $material->download_count,
                'files_count' => $material->files->count(),
                'files' => $material->files->map(fn (StudyMaterialFile $f) => [
                    'id' => $f->id,
                    'file_name' => $f->file_name,
                    'file_size' => (int) $f->file_size,
                    'file_size_formatted' => $f->file_size_formatted,
                    'file_type' => $f->file_type,
                    'icon' => $f->file_icon,
                    'download_count' => (int) $f->download_count,
                ]),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Get specific study material details by ID or UUID.
     */
    public function show(string $identifier): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::with(['course', 'files'])->published()->findOrFail($identifier)
            : StudyMaterial::with(['course', 'files'])->published()->where('uuid', $identifier)->firstOrFail();

        if (! $material->isPubliclyAvailable()) {
            abort(403, __('This study material is restricted to enrolled students.'));
        }

        $this->studyMaterialService->recordAccess($material, StudyMaterialAccessLog::ACTION_VIEW);

        $typesMap = StudyMaterial::materialTypes();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $material->id,
                'uuid' => $material->uuid,
                'title' => $material->title,
                'slug' => $material->slug,
                'subject' => $material->subject,
                'topic' => $material->topic,
                'module_code' => $material->module_code,
                'material_type' => [
                    'code' => $material->material_type,
                    'name' => $typesMap[$material->material_type] ?? ucfirst(str_replace('_', ' ', $material->material_type)),
                ],
                'course' => $material->course ? [
                    'id' => $material->course->id,
                    'name' => $material->course->name,
                ] : null,
                'description' => $material->description,
                'thumbnail_url' => $material->thumbnail_image_url,
                'class_date' => $material->class_date?->format('Y-m-d'),
                'display_order' => (int) $material->display_order,
                'content' => $material->content,
                'external_url' => $material->external_url,
                'is_pinned' => (bool) $material->is_pinned,
                'published_at' => $material->created_at?->toIso8601String(),
                'view_count' => (int) $material->view_count,
                'download_count' => (int) $material->download_count,
                'files' => $material->files->map(fn (StudyMaterialFile $f) => [
                    'id' => $f->id,
                    'file_name' => $f->file_name,
                    'file_size' => (int) $f->file_size,
                    'file_size_formatted' => $f->file_size_formatted,
                    'file_type' => $f->file_type,
                    'icon' => $f->file_icon,
                    'download_count' => (int) $f->download_count,
                ]),
            ],
        ]);
    }

    /**
     * Step 1: Initiate download request with lead capture information.
     * Checks if email/phone already verified in subscribers/system:
     * - If exists & verified: Grants immediate download token without re-verification.
     * - If new / unverified: Sends mobile & email OTPs and returns verification session.
     */
    public function requestDownload(Request $request, string $identifier): JsonResponse
    {
        $material = is_numeric($identifier)
            ? StudyMaterial::with('files')->published()->findOrFail($identifier)
            : StudyMaterial::with('files')->published()->where('uuid', $identifier)->firstOrFail();

        if (! $material->isPubliclyAvailable()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'MATERIAL_RESTRICTED',
                    'message' => __('This study material is restricted to enrolled students and is not available for public download.'),
                ],
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'min:2', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required_without:mobile_no', 'string', 'min:8', 'max:20'],
            'mobile_no' => ['required_without:phone', 'string', 'min:8', 'max:20'],
            'file_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => __('Validation failed.'),
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $validated = $validator->validated();
        $firstName = trim($validated['first_name']);
        $lastName = ! empty($validated['last_name']) ? trim($validated['last_name']) : '';
        $fullName = trim($firstName.' '.$lastName);
        $email = strtolower(trim($validated['email']));
        $phoneInput = $validated['phone'] ?? $validated['mobile_no'];
        $phone = preg_replace('/[^\d+]/', '', trim($phoneInput));
        $fileId = ! empty($validated['file_id']) ? (int) $validated['file_id'] : null;

        if ($fileId && ! $material->files->contains('id', $fileId)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_FILE',
                    'message' => __('Requested file does not belong to this study material.'),
                ],
            ], 422);
        }

        // Check if user or subscriber already exists & has been verified
        $alreadyVerified = $this->isUserAlreadyVerified($email, $phone);

        if ($alreadyVerified) {
            // Existing verified user -> Bypass verification and provide immediate download access!
            $subscriber = $this->subscriberService->subscribe([
                'name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'country_code' => str_starts_with($phone, '+') ? substr($phone, 0, 3) : '+91',
                'type' => Subscriber::TYPE_BOTH,
                'source' => Subscriber::SOURCE_STUDY_MATERIAL,
                'tags' => ['study_materials_lead', 'external_api', 'material_type_'.$material->material_type],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'last_material_id' => $material->id,
                    'last_material_title' => $material->title,
                    'verified' => true,
                    'downloaded_at' => now()->toIso8601String(),
                ],
            ]);

            // Log access telemetry
            $this->studyMaterialService->recordAccess(
                material: $material,
                action: StudyMaterialAccessLog::ACTION_DOWNLOAD,
                fileId: $fileId,
                subscriber: $subscriber,
                leadDetails: [
                    'name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                ],
                isVerified: true,
                metadata: [
                    'api_client' => $request->attributes->get('api_key')?->name ?? 'External App',
                    'bypassed_otp' => true,
                ]
            );

            // Generate secure temporary download token
            $token = $this->generateDownloadToken($material->id, $fileId, $subscriber->id, $email, $phone);

            return response()->json([
                'success' => true,
                'requires_verification' => false,
                'message' => __('Your information has been verified. You can download the study material now.'),
                'download_token' => $token,
                'download_url' => route('api.v1.external.study-materials.download', ['token' => $token]),
                'subscriber' => [
                    'id' => $subscriber->id,
                    'name' => $subscriber->name,
                    'email' => $subscriber->email,
                    'phone' => $subscriber->phone,
                ],
            ]);
        }

        // New / Unverified User -> Generate OTPs for verification
        $sessionToken = 'sntc_dl_sess_'.Str::random(40);

        // Generate and dispatch OTPs via OtpService
        $emailResult = $this->otpService->generateAndSend(
            identifier: $email,
            type: OtpCode::TYPE_VERIFY_EMAIL,
            extraVariables: [
                'name' => $firstName,
                'material_title' => $material->title,
            ]
        );

        $smsResult = $this->otpService->generateAndSend(
            identifier: $phone,
            type: OtpCode::TYPE_VERIFY_PHONE,
            extraVariables: [
                'name' => $firstName,
                'material_title' => $material->title,
            ]
        );

        // Cache verification session data for 15 minutes
        Cache::put('study_mat_sess_'.$sessionToken, [
            'material_id' => $material->id,
            'file_id' => $fileId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'email_otp_id' => $emailResult['otp_id'] ?? null,
            'sms_otp_id' => $smsResult['otp_id'] ?? null,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        $responsePayload = [
            'success' => true,
            'requires_verification' => true,
            'session_token' => $sessionToken,
            'message' => __('Verification code(s) have been dispatched to your email and mobile number. Please enter the OTP to verify your request and access the download.'),
            'channels' => [
                'email' => [
                    'dispatched' => (bool) $emailResult['success'],
                    'target' => $this->maskEmail($email),
                    'message' => $emailResult['message'] ?? null,
                ],
                'phone' => [
                    'dispatched' => (bool) $smsResult['success'],
                    'target' => $this->maskPhone($phone),
                    'message' => $smsResult['message'] ?? null,
                ],
            ],
            'expires_in_seconds' => 900,
        ];

        // In local/testing environments, include test code for rapid validation
        if (app()->environment('local', 'testing')) {
            $responsePayload['debug_otp'] = $emailResult['plain_code'] ?? $smsResult['plain_code'] ?? null;
        }

        return response()->json($responsePayload);
    }

    /**
     * Step 2: Verify OTP and grant study material download access.
     * Records/updates the user under the subscribers table and logs the lead.
     */
    public function verifyOtpAndDownload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_token' => ['required', 'string'],
            'otp_code' => ['required_without_all:email_otp,mobile_otp', 'string', 'min:4', 'max:8'],
            'email_otp' => ['nullable', 'string', 'min:4', 'max:8'],
            'mobile_otp' => ['nullable', 'string', 'min:4', 'max:8'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => __('Validation failed.'),
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $sessionToken = $request->input('session_token');
        $session = Cache::get('study_mat_sess_'.$sessionToken);

        if (! $session) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SESSION_EXPIRED',
                    'message' => __('Your verification session has expired or is invalid. Please submit your information again.'),
                ],
            ], 422);
        }

        $code = $request->input('otp_code') ?: $request->input('email_otp') ?: $request->input('mobile_otp');
        $email = $session['email'];
        $phone = $session['phone'];

        // Perform verification against Email OTP or Mobile OTP
        $verified = false;
        $verificationMessage = '';

        $verifyEmail = $this->otpService->verify($email, $code, OtpCode::TYPE_VERIFY_EMAIL);
        if ($verifyEmail['success']) {
            $verified = true;
        } else {
            $verifyPhone = $this->otpService->verify($phone, $code, OtpCode::TYPE_VERIFY_PHONE);
            if ($verifyPhone['success']) {
                $verified = true;
            } else {
                $verificationMessage = $verifyEmail['message'] ?? $verifyPhone['message'] ?? __('Incorrect verification code.');
            }
        }

        if (! $verified) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OTP_INVALID',
                    'message' => $verificationMessage,
                ],
            ], 422);
        }

        $material = StudyMaterial::findOrFail($session['material_id']);
        $fileId = $session['file_id'] ?? null;
        $fullName = trim($session['first_name'].' '.$session['last_name']);

        // 1. RECORD UNDER SUBSCRIBERS TABLE
        $subscriber = $this->subscriberService->subscribe([
            'name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'country_code' => str_starts_with($phone, '+') ? substr($phone, 0, 3) : '+91',
            'type' => Subscriber::TYPE_BOTH,
            'source' => Subscriber::SOURCE_STUDY_MATERIAL,
            'tags' => ['study_materials_lead', 'verified_otp', 'external_api', 'material_type_'.$material->material_type],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'first_name' => $session['first_name'],
                'last_name' => $session['last_name'],
                'material_id' => $material->id,
                'material_title' => $material->title,
                'verified_at' => now()->toIso8601String(),
                'source_api' => 'external_api',
            ],
        ]);

        // 2. Record access log
        $this->studyMaterialService->recordAccess(
            material: $material,
            action: StudyMaterialAccessLog::ACTION_DOWNLOAD,
            fileId: $fileId,
            subscriber: $subscriber,
            leadDetails: [
                'name' => $fullName,
                'email' => $email,
                'phone' => $phone,
            ],
            isVerified: true,
            metadata: [
                'api_client' => $request->attributes->get('api_key')?->name ?? 'External App',
                'otp_verified_at' => now()->toIso8601String(),
            ]
        );

        // 3. Generate secure download token
        $downloadToken = $this->generateDownloadToken($material->id, $fileId, $subscriber->id, $email, $phone);

        // Clean up verification session
        Cache::forget('study_mat_sess_'.$sessionToken);

        return response()->json([
            'success' => true,
            'verified' => true,
            'message' => __('Verification successful! You can now download the study material.'),
            'download_token' => $downloadToken,
            'download_url' => route('api.v1.external.study-materials.download', ['token' => $downloadToken]),
            'subscriber' => [
                'id' => $subscriber->id,
                'name' => $subscriber->name,
                'email' => $subscriber->email,
                'phone' => $subscriber->phone,
            ],
        ]);
    }

    /**
     * Resend verification code for an existing download session.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => __('Session token is required.'),
                ],
            ], 422);
        }

        $sessionToken = $request->input('session_token');
        $session = Cache::get('study_mat_sess_'.$sessionToken);

        if (! $session) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SESSION_EXPIRED',
                    'message' => __('Verification session has expired. Please initiate download request again.'),
                ],
            ], 422);
        }

        $email = $session['email'];
        $phone = $session['phone'];

        $emailResult = $this->otpService->generateAndSend(
            identifier: $email,
            type: OtpCode::TYPE_VERIFY_EMAIL,
            extraVariables: ['name' => $session['first_name']]
        );

        $smsResult = $this->otpService->generateAndSend(
            identifier: $phone,
            type: OtpCode::TYPE_VERIFY_PHONE,
            extraVariables: ['name' => $session['first_name']]
        );

        $responsePayload = [
            'success' => true,
            'message' => __('Verification codes have been re-dispatched.'),
            'channels' => [
                'email' => [
                    'dispatched' => (bool) $emailResult['success'],
                    'message' => $emailResult['message'] ?? null,
                ],
                'phone' => [
                    'dispatched' => (bool) $smsResult['success'],
                    'message' => $smsResult['message'] ?? null,
                ],
            ],
        ];

        if (app()->environment('local', 'testing')) {
            $responsePayload['debug_otp'] = $emailResult['plain_code'] ?? $smsResult['plain_code'] ?? null;
        }

        return response()->json($responsePayload);
    }

    /**
     * Direct binary file download via time-limited secure token.
     * Can be invoked directly by browsers or apps.
     */
    public function downloadFile(string $token): BinaryFileResponse|StreamedResponse|Response
    {
        $payload = Cache::get('sntc_dl_tok_'.$token);

        if (! $payload) {
            abort(404, __('Download link has expired or is invalid. Please re-verify to generate a fresh link.'));
        }

        $material = StudyMaterial::with('files')->findOrFail($payload['material_id']);

        if (! $material->isPubliclyAvailable()) {
            abort(403, __('This study material is restricted to enrolled students.'));
        }

        $fileId = $payload['file_id'] ?? null;

        if ($fileId) {
            $file = StudyMaterialFile::where('study_material_id', $material->id)
                ->where('id', $fileId)
                ->firstOrFail();

            return $this->streamFile($file);
        }

        $files = $material->files;

        if ($files->isEmpty()) {
            if ($material->external_url) {
                return redirect()->away($material->external_url);
            }
            abort(404, __('No downloadable files found for this study material.'));
        }

        if ($files->count() === 1) {
            return $this->streamFile($files->first());
        }

        // Multiple files: stream zip archive
        return $this->streamZipArchive($material, $files);
    }

    /**
     * Determine if a user or subscriber is already verified.
     */
    protected function isUserAlreadyVerified(string $email, string $phone): bool
    {
        // 1. Check in active subscribers
        $subscriberExists = Subscriber::query()
            ->where('status', Subscriber::STATUS_ACTIVE)
            ->where(function ($q) use ($email, $phone) {
                $q->where('email', $email)
                    ->orWhere('phone', $phone);
            })
            ->exists();

        if ($subscriberExists) {
            return true;
        }

        // 2. Check registered users with verified email/phone
        $userExists = User::query()
            ->where(function ($q) use ($email, $phone) {
                $q->where('email', $email)
                    ->orWhere('phone', $phone);
            })
            ->exists();

        if ($userExists) {
            return true;
        }

        // 3. Check historical verified OTP records
        return OtpCode::query()
            ->whereIn('identifier', [$email, $phone])
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * Generate secure, time-limited download token.
     */
    protected function generateDownloadToken(int $materialId, ?int $fileId, int $subscriberId, string $email, string $phone): string
    {
        $token = Str::random(48);

        Cache::put('sntc_dl_tok_'.$token, [
            'material_id' => $materialId,
            'file_id' => $fileId,
            'subscriber_id' => $subscriberId,
            'email' => $email,
            'phone' => $phone,
            'generated_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        return $token;
    }

    /**
     * Stream single file attachment.
     */
    protected function streamFile(StudyMaterialFile $file): BinaryFileResponse|StreamedResponse
    {
        $disk = Storage::disk($file->disk ?? 'public');

        if (! $disk->exists($file->file_path)) {
            abort(404, __('The requested file is not available on the storage server.'));
        }

        $file->increment('download_count');

        return $disk->download($file->file_path, $file->file_name);
    }

    /**
     * Stream a dynamically generated ZIP archive for multiple attachments.
     */
    protected function streamZipArchive(StudyMaterial $studyMaterial, $files): BinaryFileResponse
    {
        $zipFileName = Str::slug($studyMaterial->title).'-resources-'.now()->format('Ymd').'.zip';
        $tempZipPath = tempnam(sys_get_temp_dir(), 'sm_zip_');

        $zip = new ZipArchive;
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, __('Unable to package study material files.'));
        }

        foreach ($files as $file) {
            $disk = Storage::disk($file->disk ?? 'public');
            if ($disk->exists($file->file_path)) {
                $zip->addFromString($file->file_name, $disk->get($file->file_path));
                $file->increment('download_count');
            }
        }

        $zip->close();

        return response()->download($tempZipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        $maskedName = substr($name, 0, 1).str_repeat('*', max(1, strlen($name) - 2)).substr($name, -1);

        return $maskedName.'@'.$domain;
    }

    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 4) {
            return str_repeat('*', strlen($phone));
        }

        return substr($phone, 0, 3).str_repeat('*', strlen($phone) - 6).substr($phone, -3);
    }
}
