<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OtrImportLog;
use App\Models\Section;
use App\Models\Setting;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OtrSyncService
{
    /**
     * Check if the OTR integration feature is enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('otr.enabled', true);
    }

    /**
     * Get OTR Portal Base URL.
     */
    public static function getBaseUrl(): string
    {
        $url = (string) Setting::get('otr.base_url', 'http://127.0.0.1:8000');
        return rtrim($url, '/');
    }

    /**
     * Get decrypted OTR API Key.
     */
    public static function getApiKey(): string
    {
        return (string) Setting::get('otr.api_key', '');
    }

    /**
     * Get connection timeout in seconds.
     */
    public static function getTimeout(): int
    {
        return min(15, max(3, (int) Setting::get('otr.timeout', 10)));
    }

    /**
     * Check if the configured OTR Base URL targets the same host and port as this MIS instance.
     * Prevents single-threaded PHP server (php artisan serve) deadlocks in local development.
     */
    public static function isSelfReferencingPort(?string $url = null): bool
    {
        if (app()->runningInConsole() && ! request()->hasHeader('Host')) {
            return false;
        }

        $target = $url ?: static::getBaseUrl();
        $targetParts = parse_url($target);
        $targetHost = strtolower($targetParts['host'] ?? '');
        $targetPort = (int) ($targetParts['port'] ?? (($targetParts['scheme'] ?? 'http') === 'https' ? 443 : 80));

        $currentHost = strtolower(request()->getHost() ?: '127.0.0.1');
        $currentPort = (int) (request()->getPort() ?: 8000);

        $localHosts = ['localhost', '127.0.0.1', '::1'];

        $isTargetLocal = in_array($targetHost, $localHosts, true);
        $isCurrentLocal = in_array($currentHost, $localHosts, true);

        return $isTargetLocal && $isCurrentLocal && ($targetPort === $currentPort);
    }

    /**
     * Test connection to OTR API /ping endpoint.
     *
     * @param string|null $baseUrl Optional override for test connection modal
     * @param string|null $apiKey Optional override for test connection modal
     * @return array{success: bool, status: int, message: string, latency_ms: int, data: ?array}
     */
    public static function ping(?string $baseUrl = null, ?string $apiKey = null): array
    {
        $base = rtrim($baseUrl ?: static::getBaseUrl(), '/');
        $key = $apiKey ?: static::getApiKey();
        $timeout = static::getTimeout();

        if (blank($base)) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'OTR Portal Base URL is not configured.',
                'latency_ms' => 0,
                'data' => null,
            ];
        }

        if (blank($key)) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'OTR API Key is not configured.',
                'latency_ms' => 0,
                'data' => null,
            ];
        }

        if (static::isSelfReferencingPort($base)) {
            $currentPort = request()->getPort() ?: 8000;
            return [
                'success' => false,
                'status' => 0,
                'message' => "Port collision detected: Both SNTCSSC MIS and Admission OTR cannot run on port {$currentPort}. Admission OTR must run on a distinct port (e.g. 8080 or 8001). Please update OTR Base URL under Admissions > Settings.",
                'latency_ms' => 0,
                'data' => null,
            ];
        }

        $endpoint = "{$base}/api/v1/ping";
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
                'User-Agent' => 'SNTCSSC-MIS-Integration/1.0',
            ])->connectTimeout(3)->timeout(min($timeout, 8))->get($endpoint);

            $latency = (int) round((microtime(true) - $startTime) * 1000);
            $statusCode = $response->status();

            if ($response->successful()) {
                $payload = $response->json();
                return [
                    'success' => true,
                    'status' => $statusCode,
                    'message' => $payload['message'] ?? 'Connected successfully to Admission OTR Portal.',
                    'latency_ms' => $latency,
                    'data' => $payload['data'] ?? null,
                ];
            }

            $errorMessage = match ($statusCode) {
                401 => 'Authentication failed: Invalid or expired API Key.',
                403 => 'Authorization failed: API key does not have required permissions.',
                404 => "Endpoint not found at {$endpoint}. Verify the OTR Base URL.",
                429 => 'Rate limit exceeded on OTR portal.',
                500 => 'Internal server error on OTR portal.',
                default => "HTTP request failed with status {$statusCode}.",
            };

            $body = $response->json();
            if (!empty($body['message'])) {
                $errorMessage .= " ({$body['message']})";
            }

            return [
                'success' => false,
                'status' => $statusCode,
                'message' => $errorMessage,
                'latency_ms' => $latency,
                'data' => null,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $latency = (int) round((microtime(true) - $startTime) * 1000);
            Log::warning('OTR API connection timeout or refused', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'status' => 0,
                'message' => "Connection refused or timed out while reaching {$base}. Ensure Admission OTR is running and reachable.",
                'latency_ms' => $latency,
                'data' => null,
            ];
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $startTime) * 1000);
            Log::warning('OTR API Ping connection failure', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'latency_ms' => $latency,
                'data' => null,
            ];
        }
    }

    /**
     * Fetch all examinations/programmes available on OTR portal.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetchExams(?string $baseUrl = null, ?string $apiKey = null): array
    {
        $base = rtrim($baseUrl ?: static::getBaseUrl(), '/');
        $key = $apiKey ?: static::getApiKey();

        if (blank($base) || blank($key) || static::isSelfReferencingPort($base)) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
            ])->connectTimeout(3)->timeout(min(static::getTimeout(), 6))->get("{$base}/api/v1/exams");

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch exams from OTR', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Fetch admissions from OTR portal with comprehensive related application tables.
     *
     * @param array<string, mixed> $filters
     * @return array{success: bool, data: array, meta: array, message: string}
     */
    public static function fetchAdmissions(array $filters = [], ?string $baseUrl = null, ?string $apiKey = null): array
    {
        if (! static::isEnabled()) {
            return [
                'success' => false,
                'data' => [],
                'meta' => [],
                'message' => 'Admission OTR Integration feature is currently disabled in system settings.',
            ];
        }

        $base = rtrim($baseUrl ?: static::getBaseUrl(), '/');
        $key = $apiKey ?: static::getApiKey();

        if (blank($base) || blank($key)) {
            return [
                'success' => false,
                'data' => [],
                'meta' => [],
                'message' => 'OTR Base URL or API Key is not configured.',
            ];
        }

        if (static::isSelfReferencingPort($base)) {
            $currentPort = request()->getPort() ?: 8000;
            return [
                'success' => false,
                'data' => [],
                'meta' => [],
                'message' => "Port collision detected: Both SNTCSSC MIS and Admission OTR cannot run on port {$currentPort}. Admission OTR must run on a distinct port (e.g. 8080 or 8001). Please update OTR Base URL under Admissions > Settings.",
            ];
        }

        $query = array_filter([
            'status' => $filters['status'] ?? 'admitted',
            'exam_id' => $filters['exam_id'] ?? null,
            'exam_code' => $filters['exam_code'] ?? null,
            'selection_list' => $filters['selection_list'] ?? null,
            'selection_mode' => $filters['selection_mode'] ?? null,
            'payment_status' => $filters['payment_status'] ?? null,
            'search' => $filters['search'] ?? null,
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 15,
            'all' => !empty($filters['all']) ? 'true' : null,
        ], fn ($val) => $val !== null && $val !== '');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
            ])->connectTimeout(3)->timeout(min(static::getTimeout(), 8))->get("{$base}/api/v1/admissions/all-data", $query);

            if ($response->successful()) {
                $payload = $response->json();
                return [
                    'success' => true,
                    'data' => $payload['data'] ?? [],
                    'meta' => $payload['meta'] ?? [],
                    'message' => $payload['message'] ?? 'Admissions fetched successfully.',
                ];
            }

            return [
                'success' => false,
                'data' => [],
                'meta' => [],
                'message' => 'OTR Portal Error (' . $response->status() . '): ' . ($response->json('message') ?? 'Unknown error'),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('OTR fetchAdmissions connection timeout or refused', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'data' => [],
                'meta' => [],
                'message' => "Connection refused or timed out while reaching {$base}. Ensure Admission OTR is active and reachable.",
            ];
        } catch (\Throwable $e) {
            Log::error('OTR fetchAdmissions exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'data' => [],
                'meta' => [],
                'message' => 'Connection failure: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch complete details of a single admission from OTR portal.
     *
     * @param string|int $id
     * @return array{success: bool, data: ?array, message: string}
     */
    public static function fetchAdmissionDetail(string|int $id, ?string $baseUrl = null, ?string $apiKey = null): array
    {
        $base = rtrim($baseUrl ?: static::getBaseUrl(), '/');
        $key = $apiKey ?: static::getApiKey();

        if (blank($base) || blank($key)) {
            return ['success' => false, 'data' => null, 'message' => 'OTR connection credentials missing.'];
        }

        if (static::isSelfReferencingPort($base)) {
            return ['success' => false, 'data' => null, 'message' => 'Port collision detected. Base URL matches local MIS port.'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
            ])->connectTimeout(3)->timeout(min(static::getTimeout(), 8))->get("{$base}/api/v1/admissions/{$id}/all-data");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data'),
                    'message' => 'Admission details loaded successfully.',
                ];
            }

            return [
                'success' => false,
                'data' => null,
                'message' => 'Failed to load admission details (' . $response->status() . ').',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'data' => null,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if a candidate from OTR already exists in MIS (by URN, email, phone, or application number).
     *
     * @param array<string, mixed> $candidateItem
     * @param int|null $targetCourseId
     * @param int|null $targetBatchId
     * @return array{
     *     exists: bool,
     *     user: ?User,
     *     student_id: ?string,
     *     enrollments: array<int, array>,
     *     is_enrolled_in_course: bool,
     *     is_enrolled_in_batch: bool,
     *     already_enrolled_here: bool,
     *     status_type: string,
     *     status_label: string,
     *     badge_color: string
     * }
     */
    public static function checkCandidateMisStatus(array $candidateItem, ?int $targetCourseId = null, ?int $targetBatchId = null): array
    {
        $urn = $candidateItem['users']['urn'] ?? null;
        $email = $candidateItem['users']['email'] ?? null;
        $mobile = $candidateItem['users']['mobile'] ?? null;
        $appNo = $candidateItem['application']['application_number'] ?? null;
        $formNo = $candidateItem['admission']['admission_form']['admission_form_number'] ?? null;

        $user = null;

        // 1. Search by URN
        if (!empty($urn)) {
            $user = User::where('urn', $urn)->first();
        }

        // 2. Search by Email
        if (!$user && !empty($email)) {
            $user = User::where('email', $email)->first();
        }

        // 3. Search by Mobile / Phone
        if (!$user && !empty($mobile)) {
            $user = User::where('phone', $mobile)->orWhere('phone', 'like', "%{$mobile}%")->first();
        }

        // 4. Search by application_no on enrollments table
        if (!$user && !empty($appNo)) {
            $enrollment = Enrollment::where('application_no', $appNo)->first();
            if ($enrollment) {
                $user = $enrollment->user;
            }
        }

        if (! $user) {
            return [
                'exists' => false,
                'user' => null,
                'student_id' => null,
                'enrollments' => [],
                'is_enrolled_in_course' => false,
                'is_enrolled_in_batch' => false,
                'already_enrolled_here' => false,
                'status_type' => 'new',
                'status_label' => 'New Candidate (Not in MIS)',
                'badge_color' => 'indigo',
            ];
        }

        // Load existing active enrollments for this student
        $existingEnrollments = Enrollment::with(['course', 'batch', 'section'])
            ->where('user_id', $user->id)
            ->get();

        $enrolledList = $existingEnrollments->map(fn (Enrollment $e) => [
            'id' => $e->id,
            'enrollment_no' => $e->enrollment_no,
            'course_id' => $e->course_id,
            'course_name' => $e->course?->name ?? 'Course #' . $e->course_id,
            'batch_id' => $e->batch_id,
            'batch_name' => $e->batch?->name ?? 'Batch #' . $e->batch_id,
            'roll_no' => $e->roll_no,
            'status' => $e->status,
            'enrolled_at' => $e->enrolled_at?->toDateString(),
        ])->all();

        $alreadyEnrolledHere = false;
        $isEnrolledInCourse = false;
        $isEnrolledInBatch = false;

        if ($targetCourseId && $targetBatchId) {
            $alreadyEnrolledHere = $existingEnrollments->contains(function (Enrollment $e) use ($targetCourseId, $targetBatchId) {
                return (int) $e->course_id === (int) $targetCourseId && (int) $e->batch_id === (int) $targetBatchId && $e->status !== Enrollment::STATUS_CANCELLED;
            });
        }

        if ($targetCourseId) {
            $isEnrolledInCourse = $existingEnrollments->contains(fn (Enrollment $e) => (int) $e->course_id === (int) $targetCourseId && $e->status !== Enrollment::STATUS_CANCELLED);
        }

        if ($targetBatchId) {
            $isEnrolledInBatch = $existingEnrollments->contains(fn (Enrollment $e) => (int) $e->batch_id === (int) $targetBatchId && $e->status !== Enrollment::STATUS_CANCELLED);
        }

        if ($alreadyEnrolledHere) {
            return [
                'exists' => true,
                'user' => $user,
                'student_id' => $user->student_id,
                'enrollments' => $enrolledList,
                'is_enrolled_in_course' => true,
                'is_enrolled_in_batch' => true,
                'already_enrolled_here' => true,
                'status_type' => 'already_enrolled',
                'status_label' => 'Already Enrolled in this Course & Batch',
                'badge_color' => 'emerald',
            ];
        }

        return [
            'exists' => true,
            'user' => $user,
            'student_id' => $user->student_id,
            'enrollments' => $enrolledList,
            'is_enrolled_in_course' => $isEnrolledInCourse,
            'is_enrolled_in_batch' => $isEnrolledInBatch,
            'already_enrolled_here' => false,
            'status_type' => 'student_exists',
            'status_label' => 'Student Exists in MIS (Ready for New Enrollment)',
            'badge_color' => 'amber',
        ];
    }

    /**
     * Import a single candidate from OTR into MIS.
     *
     * Handles student creation (if new), profile dossier syncing, addresses,
     * educations, employments, other details, documents, and adds an enrollment
     * into the selected Course and Batch.
     *
     * @param array<string, mixed> $candidateItem Complete 14-table snapshot from OTR
     * @param int $courseId Target Course ID in MIS
     * @param int $batchId Target Batch ID in MIS
     * @param int|null $sectionId Target Section ID in MIS (optional)
     * @param array<string, mixed> $options Custom options (fee_amount, fee_status, download_documents, update_existing_profiles)
     * @param User|null $actor Admin user performing the import
     * @return array{success: bool, action: string, message: string, user: ?User, enrollment: ?Enrollment, log: ?OtrImportLog}
     */
    public static function importCandidate(
        array $candidateItem,
        int $courseId,
        int $batchId,
        ?int $sectionId = null,
        array $options = [],
        ?User $actor = null
    ): array {
        if (! static::isEnabled()) {
            throw new \RuntimeException('Admission OTR Integration feature is currently disabled in system settings.');
        }

        $course = Course::findOrFail($courseId);
        $batch = Batch::findOrFail($batchId);
        $section = $sectionId ? Section::find($sectionId) : null;
        $actorId = $actor?->id ?? auth()->id();

        // Extract sub-entities from OTR payload
        $userData = $candidateItem['users'] ?? [];
        $profileData = $candidateItem['application_profiles'] ?? [];
        $addressesData = $candidateItem['application_addresses'] ?? [];
        $educationsData = $candidateItem['appl_edu_qualifications'] ?? [];
        $employmentsData = $candidateItem['appl_empl_histories'] ?? [];
        $documentsData = $candidateItem['appl_documents'] ?? [];
        $otherDetailsData = $candidateItem['appl_other_details'] ?? [];
        $admissionForm = $candidateItem['admission']['admission_form'] ?? [];
        $admissionSelection = $candidateItem['admission']['admission_selection'] ?? [];
        $application = $candidateItem['application'] ?? [];
        $exam = $candidateItem['exams'] ?? [];

        $candidateName = trim($userData['name'] ?? trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')));
        $candidateEmail = strtolower(trim($userData['email'] ?? ''));
        $candidatePhone = trim($userData['mobile'] ?? ($userData['phone'] ?? ''));
        $urn = trim($userData['urn'] ?? '');
        $appNo = trim($application['application_number'] ?? '');
        $formNo = trim($admissionForm['admission_form_number'] ?? '');
        $rollNo = trim($admissionForm['roll_no'] ?? '');

        if (empty($candidateEmail) && empty($urn)) {
            throw new \InvalidArgumentException('Candidate record lacks both email and URN identifiers.');
        }

        $downloadDocuments = $options['download_documents'] ?? (bool) Setting::get('otr.download_documents', false);
        $updateExisting = $options['update_existing_profiles'] ?? (bool) Setting::get('otr.update_existing_profiles', true);

        return DB::transaction(function () use (
            $course, $batch, $section, $actorId, $userData, $profileData, $addressesData,
            $educationsData, $employmentsData, $documentsData, $otherDetailsData,
            $admissionForm, $admissionSelection, $application, $exam,
            $candidateName, $candidateEmail, $candidatePhone, $urn, $appNo, $formNo, $rollNo,
            $downloadDocuments, $updateExisting, $candidateItem, $options
        ) {
            // Check if user already exists in MIS
            $user = null;
            if (!empty($urn)) {
                $user = User::where('urn', $urn)->first();
            }
            if (!$user && !empty($candidateEmail)) {
                $user = User::where('email', $candidateEmail)->first();
            }
            if (!$user && !empty($candidatePhone)) {
                $user = User::where('phone', $candidatePhone)->first();
            }

            $isNewStudent = false;

            if (! $user) {
                // =============================================================
                // 1. CREATE NEW STUDENT USER
                // =============================================================
                $isNewStudent = true;
                $firstName = $userData['first_name'] ?? null;
                $lastName = $userData['last_name'] ?? null;
                if (empty($firstName) && empty($lastName)) {
                    $parts = explode(' ', $candidateName, 2);
                    $firstName = $parts[0] ?? $candidateName;
                    $lastName = $parts[1] ?? null;
                }

                $user = User::create([
                    'uuid' => Str::uuid()->toString(),
                    'urn' => $urn ?: null,
                    'name' => $candidateName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $candidateEmail,
                    'phone' => $candidatePhone ?: null,
                    'whatsapp_no' => $userData['whatsapp'] ?? $candidatePhone ?: null,
                    'dob' => !empty($userData['dob']) ? Carbon::parse($userData['dob']) : (!empty($profileData['dob']) ? Carbon::parse($profileData['dob']) : null),
                    'gender' => $userData['gender'] ?? ($profileData['gender'] ?? null),
                    'tenth_roll' => $userData['tenth_roll'] ?? null,
                    'id_type' => $userData['id_type'] ?? null,
                    'id_number' => $userData['id_number'] ?? null,
                    'status' => 'active',
                    'password' => Hash::make(Str::random(16)),
                    'email_verified_at' => !empty($userData['email_verified_at']) ? Carbon::parse($userData['email_verified_at']) : now(),
                    'phone_verified_at' => !empty($userData['mobile_verified_at']) ? Carbon::parse($userData['mobile_verified_at']) : now(),
                ]);

                // Assign Student Role
                if (! $user->hasRole('Student')) {
                    $user->assignRole('Student');
                }

                // Generate lifetime unique 9-digit Student ID
                if (empty($user->student_id)) {
                    $user->student_id = StudentIdGeneratorService::generateForStudent($user, $course, $section, $batch->academic_year);
                    $user->updateQuietly(['student_id' => $user->student_id]);
                }
            } else {
                // Ensure Student role
                if (! $user->hasRole('Student')) {
                    $user->assignRole('Student');
                }

                if (empty($user->student_id)) {
                    $user->student_id = StudentIdGeneratorService::generateForStudent($user, $course, $section, $batch->academic_year);
                    $user->updateQuietly(['student_id' => $user->student_id]);
                }

                // Sync URN if missing in MIS
                if (empty($user->urn) && !empty($urn)) {
                    $user->updateQuietly(['urn' => $urn]);
                }
            }

            // =================================================================
            // 2. CHECK ENROLLMENT DUPLICATION
            // =================================================================
            $existingEnrollment = Enrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('batch_id', $batch->id)
                ->where('status', '!=', Enrollment::STATUS_CANCELLED)
                ->first();

            if ($existingEnrollment) {
                // Candidate is already enrolled in this exact course and batch
                $log = OtrImportLog::create([
                    'user_id' => $user->id,
                    'enrollment_id' => $existingEnrollment->id,
                    'course_id' => $course->id,
                    'batch_id' => $batch->id,
                    'section_id' => $section?->id ?? $existingEnrollment->section_id,
                    'imported_by' => $actorId,
                    'admission_selection_id' => $admissionSelection['id'] ?? null,
                    'admission_form_id' => $admissionForm['id'] ?? null,
                    'admission_form_number' => $formNo ?: null,
                    'application_id' => $application['id'] ?? null,
                    'application_number' => $appNo ?: null,
                    'exam_id' => $exam['id'] ?? null,
                    'exam_code' => $exam['exam_code'] ?? null,
                    'urn' => $urn ?: $user->urn,
                    'candidate_name' => $candidateName,
                    'candidate_email' => $candidateEmail,
                    'candidate_phone' => $candidatePhone,
                    'status' => OtrImportLog::STATUS_ALREADY_ENROLLED,
                    'action_taken' => OtrImportLog::ACTION_ALREADY_ENROLLED,
                    'downloaded_documents_count' => 0,
                    'payload_snapshot' => $candidateItem,
                    'error_message' => "Candidate is already actively enrolled in {$course->name} ({$batch->name}) with Enrollment No: {$existingEnrollment->enrollment_no}.",
                ]);

                return [
                    'success' => true,
                    'action' => OtrImportLog::ACTION_ALREADY_ENROLLED,
                    'message' => "Candidate {$candidateName} is already enrolled with Enrollment No: {$existingEnrollment->enrollment_no}.",
                    'user' => $user,
                    'enrollment' => $existingEnrollment,
                    'log' => $log,
                ];
            }

            // =================================================================
            // 3. STUDENT PROFILE DOSSIER
            // =================================================================
            $profile = StudentProfile::where('user_id', $user->id)->first();
            $profileFields = [
                'father_name' => $profileData['father_name'] ?? null,
                'mother_name' => $profileData['mother_name'] ?? null,
                'dob' => !empty($profileData['dob']) ? Carbon::parse($profileData['dob']) : $user->dob,
                'gender' => $profileData['gender'] ?? $user->gender,
                'category' => $profileData['category'] ?? null,
                'verified_category' => $profileData['verified_category'] ?? ($profileData['effective_category'] ?? null),
                'verified_by' => $actorId,
                'verified_at' => !empty($profileData['verified_at']) ? Carbon::parse($profileData['verified_at']) : null,
                'category_remark' => $profileData['category_remark'] ?? null,
                'subcaste' => $profileData['subcaste'] ?? null,
                'cert_no' => $profileData['cert_no'] ?? null,
                'issued_date' => !empty($profileData['issued_date']) ? Carbon::parse($profileData['issued_date']) : null,
                'issued_by' => $profileData['issued_by'] ?? null,
                'is_pwbd' => (bool) ($profileData['is_pwbd'] ?? false),
                'pwbd_type' => $profileData['pwbd_type'] ?? null,
                'pwd_percentage' => (int) ($profileData['pwd_percentage'] ?? 0),
                'occupation' => $profileData['occupation'] ?? null,
                'father_occupation' => $profileData['father_occupation'] ?? null,
                'mother_occupation' => $profileData['mother_occupation'] ?? null,
                'family_income' => !empty($profileData['family_income']) ? (float) $profileData['family_income'] : null,
                'highest_edu' => $profileData['highest_edu'] ?? null,
                'school_language' => $profileData['school_language'] ?? null,
                'upsc_community' => $profileData['upsc_community'] ?? null,
                'activities' => is_array($profileData['activities'] ?? null) ? json_encode($profileData['activities']) : ($profileData['activities'] ?? null),
                'hobbies' => is_array($profileData['hobbies'] ?? null) ? json_encode($profileData['hobbies']) : ($profileData['hobbies'] ?? null),
            ];

            if (! $profile) {
                $profile = StudentProfile::create(array_merge(['user_id' => $user->id], $profileFields));
            } elseif ($updateExisting) {
                // Update profile with incoming data where non-empty
                $cleanUpdates = array_filter($profileFields, fn ($v) => $v !== null);
                if (!empty($cleanUpdates)) {
                    $profile->update($cleanUpdates);
                }
            }

            // Sync user and profile consistency
            UserStudentSyncService::syncFromProfile($profile);

            // =================================================================
            // 4. STUDENT ADDRESSES (Permanent & Correspondence)
            // =================================================================
            if (!empty($addressesData) && ($isNewStudent || $updateExisting)) {
                foreach ($addressesData as $addr) {
                    $type = ucfirst(strtolower($addr['address_type'] ?? 'Permanent'));
                    $addrFields = [
                        'state' => $addr['state'] ?? null,
                        'district' => $addr['district'] ?? null,
                        'subdistrict' => $addr['subdistrict'] ?? null,
                        'address_line1' => $addr['address_line1'] ?? null,
                        'address_line2' => $addr['address_line2'] ?? null,
                        'post_office' => $addr['post_office'] ?? null,
                        'police_station' => $addr['police_station'] ?? null,
                        'pin_code' => $addr['pin_code'] ?? null,
                    ];

                    $existingAddr = StudentAddress::where('user_id', $user->id)
                        ->where('address_type', $type)
                        ->first();

                    if (! $existingAddr) {
                        StudentAddress::create(array_merge([
                            'user_id' => $user->id,
                            'address_type' => $type,
                        ], $addrFields));
                    } elseif ($updateExisting) {
                        $existingAddr->update(array_filter($addrFields, fn ($v) => $v !== null));
                    }
                }
            }

            // =================================================================
            // 5. EDUCATIONAL QUALIFICATIONS
            // =================================================================
            if (!empty($educationsData) && ($isNewStudent || $updateExisting)) {
                foreach ($educationsData as $edu) {
                    $level = trim($edu['level'] ?? '');
                    if (empty($level)) {
                        continue;
                    }

                    $eduFields = [
                        'board_university' => $edu['board_university'] ?? null,
                        'institute' => $edu['institute'] ?? null,
                        'stream_subject' => $edu['stream_subject'] ?? null,
                        'passing_year' => $edu['passing_year'] ?? null,
                        'total_marks' => !empty($edu['total_marks']) ? (int) $edu['total_marks'] : null,
                        'marks_obtained' => !empty($edu['marks_obtained']) ? (int) $edu['marks_obtained'] : null,
                        'percentage' => !empty($edu['percentage']) ? (float) $edu['percentage'] : null,
                        'cgpa' => !empty($edu['cgpa']) ? (float) $edu['cgpa'] : null,
                        'division' => $edu['division'] ?? null,
                        'completion_status' => $edu['completion_status'] ?? 'Completed',
                        'is_highest' => (bool) ($edu['is_highest'] ?? false),
                    ];

                    $existingEdu = StudentEducation::where('user_id', $user->id)
                        ->where('level', $level)
                        ->first();

                    if (! $existingEdu) {
                        StudentEducation::create(array_merge([
                            'user_id' => $user->id,
                            'level' => $level,
                        ], $eduFields));
                    } elseif ($updateExisting) {
                        $existingEdu->update(array_filter($eduFields, fn ($v) => $v !== null));
                    }
                }
            }

            // =================================================================
            // 6. EMPLOYMENT HISTORY
            // =================================================================
            if (!empty($employmentsData) && ($isNewStudent || $updateExisting)) {
                foreach ($employmentsData as $emp) {
                    $employer = trim($emp['employer_name'] ?? '');
                    if (empty($employer)) {
                        continue;
                    }

                    $empFields = [
                        'designation' => $emp['designation'] ?? null,
                        'nature_of_employment' => $emp['nature_of_employment'] ?? null,
                        'location' => $emp['location'] ?? null,
                        'from_date' => !empty($emp['from_date']) ? Carbon::parse($emp['from_date']) : null,
                        'to_date' => !empty($emp['to_date']) ? Carbon::parse($emp['to_date']) : null,
                        'is_current' => (bool) ($emp['is_current'] ?? false),
                        'description' => $emp['description'] ?? null,
                    ];

                    $existingEmp = StudentEmployment::where('user_id', $user->id)
                        ->where('employer_name', $employer)
                        ->first();

                    if (! $existingEmp) {
                        StudentEmployment::create(array_merge([
                            'user_id' => $user->id,
                            'employer_name' => $employer,
                        ], $empFields));
                    }
                }
            }

            // =================================================================
            // 7. UPSC OTHER DETAILS
            // =================================================================
            if (!empty($otherDetailsData) && ($isNewStudent || $updateExisting)) {
                $otherFields = [
                    'attempts_already_given' => (int) ($otherDetailsData['attempts_already_given'] ?? 0),
                    'attempts_left' => isset($otherDetailsData['attempts_left']) ? (int) $otherDetailsData['attempts_left'] : null,
                    'optional_subject' => $otherDetailsData['optional_subject'] ?? null,
                    'year_of_appearing' => $otherDetailsData['year_of_appearing'] ?? null,
                    'activities' => is_array($otherDetailsData['activities'] ?? null) ? json_encode($otherDetailsData['activities']) : ($otherDetailsData['activities'] ?? null),
                    'hobbies' => is_array($otherDetailsData['hobbies'] ?? null) ? json_encode($otherDetailsData['hobbies']) : ($otherDetailsData['hobbies'] ?? null),
                    'dynamic_fields' => $otherDetailsData['dynamic_fields'] ?? null,
                ];

                $existingOther = StudentOtherDetail::where('user_id', $user->id)->first();
                if (! $existingOther) {
                    StudentOtherDetail::create(array_merge(['user_id' => $user->id], $otherFields));
                } elseif ($updateExisting) {
                    $existingOther->update(array_filter($otherFields, fn ($v) => $v !== null));
                }
            }

            // =================================================================
            // 8. UPLOADED DOCUMENTS & AVATAR
            // =================================================================
            $docsCount = 0;
            if (!empty($documentsData)) {
                foreach ($documentsData as $doc) {
                    $docType = strtolower(trim((string) ($doc['type'] ?? 'document')));
                    $remoteFilePath = $doc['file_path'] ?? null;
                    $remoteFileUrl = $doc['file_url'] ?? null;
                    $fileHash = $doc['file_hash'] ?? null;

                    if (empty($remoteFilePath) && empty($remoteFileUrl)) {
                        continue;
                    }

                    $localPath = $remoteFilePath;

                    // Optionally download remote file to local storage
                    if ($downloadDocuments && !empty($remoteFileUrl)) {
                        $saved = static::downloadDocumentFile($remoteFileUrl, $user->id, $docType);
                        if ($saved) {
                            $localPath = $saved;
                        }
                    }

                    // Check if document of this type already recorded
                    $existingDoc = StudentDocument::where('user_id', $user->id)
                        ->where('type', $docType)
                        ->first();

                    if (! $existingDoc) {
                        StudentDocument::create([
                            'user_id' => $user->id,
                            'type' => $docType,
                            'file_path' => $localPath,
                            'file_name' => basename($remoteFilePath ?: $localPath),
                            'file_hash' => $fileHash,
                            'is_active' => true,
                            'verified' => (bool) ($doc['verified'] ?? false),
                            'verified_by' => $doc['verified_by'] ?? null,
                            'verified_at' => !empty($doc['verified_at']) ? Carbon::parse($doc['verified_at']) : null,
                            'uploaded_at' => !empty($doc['uploaded_at']) ? Carbon::parse($doc['uploaded_at']) : now(),
                        ]);
                        $docsCount++;
                    }

                    // If photo and user has no avatar, set user avatar
                    if ($docType === 'photo' && empty($user->avatar)) {
                        $user->updateQuietly(['avatar' => $localPath]);
                    }
                }
            }

            // =================================================================
            // 9. CREATE COURSE ENROLLMENT IN MIS
            // =================================================================
            $enrolledDate = !empty($admissionForm['transaction_date'])
                ? Carbon::parse($admissionForm['transaction_date'])->toDateString()
                : (!empty($admissionSelection['selected_at']) ? Carbon::parse($admissionSelection['selected_at'])->toDateString() : now()->toDateString());

            $feeAmount = isset($options['fee_amount'])
                ? (float) $options['fee_amount']
                : (!empty($admissionForm['amount_paid']) ? (float) $admissionForm['amount_paid'] : (float) $course->fee);

            $rawFeeStatus = strtolower($options['fee_status'] ?? ($admissionForm['payment_status'] ?? 'paid'));
            $feeStatus = match ($rawFeeStatus) {
                'paid', 'completed' => 'paid',
                'partial' => 'partial',
                'exempted' => 'exempted',
                default => 'pending',
            };

            $assignedRollNo = $rollNo ?: ($options['roll_no'] ?? null);

            $enrollment = Enrollment::create([
                'user_id' => $user->id,
                'student_id' => $user->student_id,
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => $section?->id,
                'roll_no' => $assignedRollNo ?: null,
                'application_no' => $appNo ?: null,
                'enrolled_at' => $enrolledDate,
                'completed_at' => null,
                'status' => Enrollment::STATUS_ACTIVE,
                'fee_amount' => $feeAmount,
                'fee_status' => $feeStatus,
                'remarks' => "Imported from Admission OTR (Form: {$formNo}, Selection: " . ($admissionSelection['id'] ?? 'N/A') . ")",
                'created_by' => $actorId,
            ]);

            $actionTaken = $isNewStudent
                ? OtrImportLog::ACTION_CREATED_AND_ENROLLED
                : OtrImportLog::ACTION_ENROLLED_EXISTING;

            // =================================================================
            // 10. AUDIT AND IMPORT LOGGING
            // =================================================================
            $log = OtrImportLog::create([
                'user_id' => $user->id,
                'enrollment_id' => $enrollment->id,
                'course_id' => $course->id,
                'batch_id' => $batch->id,
                'section_id' => $section?->id,
                'imported_by' => $actorId,
                'admission_selection_id' => $admissionSelection['id'] ?? null,
                'admission_form_id' => $admissionForm['id'] ?? null,
                'admission_form_number' => $formNo ?: null,
                'application_id' => $application['id'] ?? null,
                'application_number' => $appNo ?: null,
                'exam_id' => $exam['id'] ?? null,
                'exam_code' => $exam['exam_code'] ?? null,
                'urn' => $urn ?: $user->urn,
                'candidate_name' => $candidateName,
                'candidate_email' => $candidateEmail,
                'candidate_phone' => $candidatePhone,
                'status' => OtrImportLog::STATUS_SUCCESS,
                'action_taken' => $actionTaken,
                'downloaded_documents_count' => $docsCount,
                'payload_snapshot' => $candidateItem,
                'error_message' => null,
            ]);

            AuditLogService::log(
                event: 'imported_from_otr',
                description: "Imported admitted candidate {$candidateName} from OTR into {$course->name} ({$batch->name}) with Enrollment No {$enrollment->enrollment_no}",
                auditable: $enrollment,
                newValues: ['enrollment_no' => $enrollment->enrollment_no, 'student_id' => $user->student_id],
                userId: $actorId
            );

            $actionMessage = $isNewStudent
                ? "Registered new student {$candidateName} (ID: {$user->student_id}) and created enrollment {$enrollment->enrollment_no}."
                : "Existing student {$candidateName} (ID: {$user->student_id}) enrolled into {$course->name} with enrollment {$enrollment->enrollment_no}.";

            return [
                'success' => true,
                'action' => $actionTaken,
                'message' => $actionMessage,
                'user' => $user,
                'enrollment' => $enrollment,
                'log' => $log,
            ];
        });
    }

    /**
     * Bulk import multiple candidates from OTR into MIS.
     *
     * @param array<int, array<string, mixed>> $candidateItems
     * @param int $courseId
     * @param int $batchId
     * @param int|null $sectionId
     * @param array<string, mixed> $options
     * @param User|null $actor
     * @return array{
     *     total: int,
     *     success_count: int,
     *     created_and_enrolled: int,
     *     existing_enrolled: int,
     *     already_enrolled: int,
     *     failed_count: int,
     *     errors: array<int, string>,
     *     results: array<int, array>
     * }
     */
    public static function importBulk(
        array $candidateItems,
        int $courseId,
        int $batchId,
        ?int $sectionId = null,
        array $options = [],
        ?User $actor = null
    ): array {
        $total = count($candidateItems);
        $successCount = 0;
        $createdAndEnrolled = 0;
        $existingEnrolled = 0;
        $alreadyEnrolled = 0;
        $failedCount = 0;
        $errors = [];
        $results = [];

        foreach ($candidateItems as $index => $candidateItem) {
            $candidateName = $candidateItem['users']['name'] ?? ('Candidate #' . ($index + 1));
            $appNo = $candidateItem['application']['application_number'] ?? '';

            try {
                $res = static::importCandidate($candidateItem, $courseId, $batchId, $sectionId, $options, $actor);
                $results[] = $res;

                if ($res['success']) {
                    $successCount++;
                    if ($res['action'] === OtrImportLog::ACTION_CREATED_AND_ENROLLED) {
                        $createdAndEnrolled++;
                    } elseif ($res['action'] === OtrImportLog::ACTION_ENROLLED_EXISTING) {
                        $existingEnrolled++;
                    } elseif ($res['action'] === OtrImportLog::ACTION_ALREADY_ENROLLED) {
                        $alreadyEnrolled++;
                    }
                } else {
                    $failedCount++;
                    $errors[] = "Candidate {$candidateName} [{$appNo}]: " . ($res['message'] ?? 'Import failed.');
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = "Candidate {$candidateName} [{$appNo}]: " . $e->getMessage();
                Log::error('OTR Bulk Import Candidate Failed', [
                    'candidate' => $candidateName,
                    'app_no' => $appNo,
                    'error' => $e->getMessage(),
                ]);

                // Create failed log entry for audit trail
                try {
                    OtrImportLog::create([
                        'course_id' => $courseId,
                        'batch_id' => $batchId,
                        'section_id' => $sectionId,
                        'imported_by' => $actor?->id ?? auth()->id(),
                        'application_number' => $appNo ?: null,
                        'admission_form_number' => $candidateItem['admission']['admission_form']['admission_form_number'] ?? null,
                        'urn' => $candidateItem['users']['urn'] ?? null,
                        'candidate_name' => $candidateName,
                        'candidate_email' => $candidateItem['users']['email'] ?? 'unknown',
                        'candidate_phone' => $candidateItem['users']['mobile'] ?? null,
                        'status' => OtrImportLog::STATUS_FAILED,
                        'action_taken' => OtrImportLog::ACTION_FAILED,
                        'downloaded_documents_count' => 0,
                        'payload_snapshot' => $candidateItem,
                        'error_message' => $e->getMessage(),
                    ]);
                } catch (\Throwable $logEx) {
                    // Ignore secondary logging exception
                }
            }
        }

        return [
            'total' => $total,
            'success_count' => $successCount,
            'created_and_enrolled' => $createdAndEnrolled,
            'existing_enrolled' => $existingEnrolled,
            'already_enrolled' => $alreadyEnrolled,
            'failed_count' => $failedCount,
            'errors' => $errors,
            'results' => $results,
        ];
    }

    /**
     * Download a document file from OTR remote URL and save to MIS local storage.
     */
    protected static function downloadDocumentFile(string $remoteUrl, int $userId, string $docType): ?string
    {
        try {
            $resp = Http::connectTimeout(3)->timeout(10)->get($remoteUrl);
            if ($resp->successful()) {
                $content = $resp->body();
                $ext = pathinfo(parse_url($remoteUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'pdf';
                $cleanType = Str::slug($docType, '_');
                $filename = "{$cleanType}_" . time() . '_' . Str::random(6) . ".{$ext}";
                $targetPath = "documents/students/{$userId}/{$filename}";

                Storage::disk('public')->put($targetPath, $content);
                return $targetPath;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to download document from OTR', [
                'url' => $remoteUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get real-time dashboard statistics for OTR integration.
     *
     * @return array{
     *     is_enabled: bool,
     *     total_imported_records: int,
     *     new_students_created: int,
     *     existing_students_enrolled: int,
     *     already_enrolled_count: int,
     *     failed_imports_count: int,
     *     imported_today: int,
     *     last_sync_at: ?string,
     *     last_sync_status: ?string
     * }
     */
    public static function getDashboardStatistics(): array
    {
        $totalLogs = OtrImportLog::count();
        $successful = OtrImportLog::where('status', OtrImportLog::STATUS_SUCCESS)->count();
        $createdAndEnrolled = OtrImportLog::where('action_taken', OtrImportLog::ACTION_CREATED_AND_ENROLLED)->count();
        $existingEnrolled = OtrImportLog::where('action_taken', OtrImportLog::ACTION_ENROLLED_EXISTING)->count();
        $alreadyEnrolled = OtrImportLog::where('action_taken', OtrImportLog::ACTION_ALREADY_ENROLLED)->count();
        $failed = OtrImportLog::where('status', OtrImportLog::STATUS_FAILED)->count();
        $importedToday = OtrImportLog::where('status', OtrImportLog::STATUS_SUCCESS)
            ->whereDate('created_at', Carbon::today())
            ->count();

        $lastLog = OtrImportLog::latest('id')->first();

        return [
            'is_enabled' => static::isEnabled(),
            'total_imported_records' => $totalLogs,
            'successful_imports' => $successful,
            'new_students_created' => $createdAndEnrolled,
            'existing_students_enrolled' => $existingEnrolled,
            'already_enrolled_count' => $alreadyEnrolled,
            'failed_imports_count' => $failed,
            'imported_today' => $importedToday,
            'last_sync_at' => $lastLog?->created_at?->diffForHumans(),
            'last_sync_status' => $lastLog?->status,
        ];
    }
}
