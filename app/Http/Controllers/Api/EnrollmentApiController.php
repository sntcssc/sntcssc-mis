<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Services\AdmissionRestrictionService;
use App\Services\AuditLogService;
use App\Services\StudentIdGeneratorService;
use App\Services\StudentImportExportService;
use App\Services\UserStudentSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EnrollmentApiController extends Controller
{
    /**
     * Resolve the active user context (via bearer/Sanctum, session, or API key owner).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Standard JSON success response envelope.
     */
    protected function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Standard JSON error response envelope.
     */
    protected function error(string $code, string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    /**
     * Locate enrollment by ID or sequential Enrollment Number.
     */
    protected function findEnrollment(string $identifier): ?Enrollment
    {
        return Enrollment::where(function ($q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('enrollment_no', $identifier);
            } else {
                $q->where('enrollment_no', $identifier);
            }
        })->first();
    }

    /**
     * Locate student user by ID, UUID, student_id, URN, or email.
     */
    protected function findStudent(string $identifier): ?User
    {
        return User::where(function ($q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('phone', $identifier);
            } else {
                $q->where('uuid', $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('urn', $identifier)
                    ->orWhere('email', $identifier);
            }
        })->first();
    }

    /**
     * Search and paginate course enrollments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Enrollment::with([
            'user:id,uuid,name,student_id,urn,email,phone,gender,avatar',
            'course:id,name,code,category,fee',
            'batch:id,name,code,academic_year',
            'section:id,name,code,room_number',
        ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('enrollment_no', 'like', "%{$search}%")
                    ->orWhere('application_no', 'like', "%{$search}%")
                    ->orWhere('roll_no', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }

        if ($sectionId = $request->input('section_id')) {
            $query->where('section_id', $sectionId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($feeStatus = $request->input('fee_status')) {
            $query->where('fee_status', $feeStatus);
        }

        $sortField = in_array($request->input('sort_by'), ['enrolled_at', 'enrollment_no', 'roll_no', 'fee_amount'])
            ? $request->input('sort_by')
            : 'enrolled_at';
        $sortDirection = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = min(100, max(5, $request->integer('per_page', 25)));
        $enrollments = $query->orderBy($sortField, $sortDirection)->paginate($perPage);

        $items = collect($enrollments->items())->map(fn ($enr) => [
            'id' => $enr->id,
            'enrollment_no' => $enr->enrollment_no,
            'application_no' => $enr->application_no,
            'student' => $enr->user ? [
                'id' => $enr->user->id,
                'student_id' => $enr->user->student_id,
                'urn' => $enr->user->urn,
                'name' => $enr->user->name,
                'email' => $enr->user->email,
                'phone' => $enr->user->phone,
                'gender' => $enr->user->gender,
                'avatar_url' => $enr->user->avatar_url,
            ] : null,
            'course' => $enr->course ? [
                'id' => $enr->course->id,
                'name' => $enr->course->name,
                'code' => $enr->course->code,
                'category' => $enr->course->category,
            ] : null,
            'batch' => $enr->batch ? [
                'id' => $enr->batch->id,
                'name' => $enr->batch->name,
                'code' => $enr->batch->code,
                'academic_year' => $enr->batch->academic_year,
            ] : null,
            'section' => $enr->section ? [
                'id' => $enr->section->id,
                'name' => $enr->section->name,
                'code' => $enr->section->code,
                'room_number' => $enr->section->room_number,
            ] : null,
            'roll_no' => $enr->roll_no,
            'enrolled_at' => $enr->enrolled_at?->toDateString(),
            'completed_at' => $enr->completed_at?->toDateString(),
            'fee_amount' => $enr->fee_amount,
            'fee_status' => $enr->fee_status,
            'status' => $enr->status,
            'remarks' => $enr->remarks,
        ]);

        return $this->success($items, [
            'current_page' => $enrollments->currentPage(),
            'last_page' => $enrollments->lastPage(),
            'per_page' => $enrollments->perPage(),
            'total' => $enrollments->total(),
        ]);
    }

    /**
     * Create new course enrollment with admission restriction and lifetime ID validation.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_identifier' => 'required_without:user_id|string|max:100',
            'user_id' => 'nullable|exists:users,id',
            'course_id' => 'required|exists:courses,id',
            'batch_id' => 'required|exists:batches,id',
            'section_id' => 'nullable|exists:sections,id',
            'roll_no' => 'nullable|string|max:50',
            'application_no' => 'nullable|string|max:50',
            'enrolled_at' => 'nullable|date',
            'fee_amount' => 'nullable|numeric|min:0',
            'fee_status' => 'nullable|string|in:pending,partial,paid,exempted',
            'remarks' => 'nullable|string|max:1000',
            'allow_override' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for enrollment creation.', 422, $validator->errors()->toArray());
        }

        $studentIdentifier = $request->input('user_id') ?: $request->input('student_identifier');
        $student = $this->findStudent((string) $studentIdentifier);

        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Candidate '{$studentIdentifier}' was not found.", 404);
        }

        $course = Course::findOrFail($request->input('course_id'));
        $batch = Batch::findOrFail($request->input('batch_id'));
        $section = $request->input('section_id') ? Section::find($request->input('section_id')) : null;

        if ($section && ($section->batch_id !== $batch->id || $section->course_id !== $course->id)) {
            return $this->error('INVALID_SECTION', 'The specified section does not belong to the selected course and batch.', 422);
        }

        // Validate eligibility via AdmissionRestrictionService
        $eligibility = AdmissionRestrictionService::checkEligibility(
            $student,
            $course,
            $batch,
            $section,
            (bool) $request->input('allow_override', false)
        );

        if (! $eligibility['allowed']) {
            return $this->error('ADMISSION_RESTRICTION', $eligibility['reason'] ?? 'Admission restriction policy violation.', 422, [
                'warnings' => $eligibility['warnings'] ?? [],
            ]);
        }

        $creator = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            // Generate or retain lifetime Student ID
            if (empty($student->student_id)) {
                $studentId = StudentIdGeneratorService::generateForEnrollment(
                    $student,
                    $course,
                    $section,
                    $batch->academic_year,
                    true
                );
                $student->student_id = $studentId;
                $student->updateQuietly(['student_id' => $studentId]);
            }

            if (! $student->hasRole('Student')) {
                $student->assignRole('Student');
            }

            $feeAmount = $request->input('fee_amount') !== null
                ? (float) $request->input('fee_amount')
                : (float) $course->fee;

            $enrollment = Enrollment::create([
                'user_id' => $student->id,
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => $section?->id,
                'roll_no' => $request->input('roll_no'),
                'application_no' => $request->input('application_no'),
                'enrolled_at' => $request->input('enrolled_at', now()->toDateString()),
                'fee_amount' => $feeAmount,
                'fee_status' => $request->input('fee_status', 'pending'),
                'remarks' => $request->input('remarks'),
                'status' => 'active',
                'created_by' => $creator?->id,
            ]);

            UserStudentSyncService::syncFromUser($student);

            AuditLogService::log(
                event: 'enrollment_created',
                description: "Enrolled student {$student->name} ({$student->student_id}) into {$course->name} - {$batch->name} (#{$enrollment->enrollment_no})",
                auditable: $enrollment,
                userId: $creator?->id
            );

            DB::commit();

            return $this->success([
                'id' => $enrollment->id,
                'enrollment_no' => $enrollment->enrollment_no,
                'application_no' => $enrollment->application_no,
                'student_id' => $student->student_id,
                'student_name' => $student->name,
                'course' => $course->name,
                'batch' => $batch->name,
                'section' => $section?->name,
                'roll_no' => $enrollment->roll_no,
                'enrolled_at' => $enrollment->enrolled_at?->toDateString(),
                'fee_amount' => $enrollment->fee_amount,
                'fee_status' => $enrollment->fee_status,
                'status' => $enrollment->status,
                'warnings' => $eligibility['warnings'] ?? [],
            ], null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('ENROLLMENT_FAILED', 'Failed to create enrollment: '.$e->getMessage(), 500);
        }
    }

    /**
     * Show single enrollment details.
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        $enrollment = $this->findEnrollment($identifier);

        if (! $enrollment) {
            return $this->error('ENROLLMENT_NOT_FOUND', "Enrollment '{$identifier}' was not found.", 404);
        }

        $enrollment->load([
            'user.studentProfile',
            'course',
            'batch',
            'section',
            'feeInvoices',
            'studentTestResults.studentTest',
        ]);

        return $this->success([
            'id' => $enrollment->id,
            'enrollment_no' => $enrollment->enrollment_no,
            'application_no' => $enrollment->application_no,
            'student' => [
                'id' => $enrollment->user?->id,
                'student_id' => $enrollment->user?->student_id,
                'urn' => $enrollment->user?->urn,
                'name' => $enrollment->user?->name,
                'email' => $enrollment->user?->email,
                'phone' => $enrollment->user?->phone,
                'category' => $enrollment->user?->studentProfile?->category ?? 'UR',
            ],
            'course' => [
                'id' => $enrollment->course?->id,
                'name' => $enrollment->course?->name,
                'code' => $enrollment->course?->code,
                'category' => $enrollment->course?->category,
                'duration' => $enrollment->course?->duration,
            ],
            'batch' => [
                'id' => $enrollment->batch?->id,
                'name' => $enrollment->batch?->name,
                'code' => $enrollment->batch?->code,
                'academic_year' => $enrollment->batch?->academic_year,
            ],
            'section' => $enrollment->section ? [
                'id' => $enrollment->section->id,
                'name' => $enrollment->section->name,
                'code' => $enrollment->section->code,
                'room_number' => $enrollment->section->room_number,
                'timing' => $enrollment->section->timing,
            ] : null,
            'roll_no' => $enrollment->roll_no,
            'enrolled_at' => $enrollment->enrolled_at?->toDateString(),
            'completed_at' => $enrollment->completed_at?->toDateString(),
            'fee_amount' => $enrollment->fee_amount,
            'fee_status' => $enrollment->fee_status,
            'status' => $enrollment->status,
            'remarks' => $enrollment->remarks,
            'cancellation_reason' => $enrollment->cancellation_reason,
            'discontinued_at' => $enrollment->discontinued_at?->toDateString(),
            'reinstated_at' => $enrollment->reinstated_at?->toIso8601String(),
            'fee_invoices_count' => $enrollment->feeInvoices->count(),
            'test_results_count' => $enrollment->studentTestResults->count(),
        ]);
    }

    /**
     * Update enrollment details (roll number, section, fees, status, remarks).
     */
    public function update(Request $request, string $identifier): JsonResponse
    {
        $enrollment = $this->findEnrollment($identifier);

        if (! $enrollment) {
            return $this->error('ENROLLMENT_NOT_FOUND', "Enrollment '{$identifier}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'roll_no' => 'nullable|string|max:50',
            'application_no' => 'nullable|string|max:50',
            'section_id' => 'nullable|exists:sections,id',
            'fee_amount' => 'nullable|numeric|min:0',
            'fee_status' => 'nullable|string|in:pending,partial,paid,exempted',
            'status' => 'nullable|string|in:active,completed,dropped,suspended,discontinued,cancelled',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation error.', 422, $validator->errors()->toArray());
        }

        $updater = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $oldValues = $enrollment->only(['roll_no', 'section_id', 'fee_amount', 'fee_status', 'status', 'remarks']);

            $updates = array_filter($request->only(['roll_no', 'section_id', 'fee_amount', 'fee_status', 'status', 'remarks']), fn ($v) => $v !== null);

            $enrollment->update($updates);

            AuditLogService::log(
                event: 'enrollment_updated',
                description: "Updated enrollment #{$enrollment->enrollment_no}",
                auditable: $enrollment,
                oldValues: $oldValues,
                newValues: $enrollment->only(['roll_no', 'section_id', 'fee_amount', 'fee_status', 'status', 'remarks']),
                userId: $updater?->id
            );

            DB::commit();

            return $this->success([
                'id' => $enrollment->id,
                'enrollment_no' => $enrollment->enrollment_no,
                'application_no' => $enrollment->application_no,
                'roll_no' => $enrollment->roll_no,
                'fee_status' => $enrollment->fee_status,
                'status' => $enrollment->status,
                'updated_at' => $enrollment->updated_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('UPDATE_FAILED', 'Failed to update enrollment: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete / cancel an enrollment.
     */
    public function destroy(Request $request, string $identifier): JsonResponse
    {
        $enrollment = $this->findEnrollment($identifier);

        if (! $enrollment) {
            return $this->error('ENROLLMENT_NOT_FOUND', "Enrollment '{$identifier}' was not found.", 404);
        }

        $deleter = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $enrNo = $enrollment->enrollment_no;
            $studentName = $enrollment->user?->name;

            $enrollment->delete();

            AuditLogService::log(
                event: 'enrollment_deleted',
                description: "Soft deleted enrollment #{$enrNo} for student {$studentName}",
                auditable: $enrollment,
                userId: $deleter?->id
            );

            DB::commit();

            return $this->success([
                'deleted' => true,
                'message' => "Enrollment record #{$enrNo} was safely soft-deleted.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DELETION_FAILED', 'Failed to delete enrollment: '.$e->getMessage(), 500);
        }
    }

    /**
     * Transfer student to a different section within the same batch & course.
     */
    public function transferSection(Request $request, string $identifier): JsonResponse
    {
        $enrollment = $this->findEnrollment($identifier);

        if (! $enrollment) {
            return $this->error('ENROLLMENT_NOT_FOUND', "Enrollment '{$identifier}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'new_section_id' => 'required|exists:sections,id',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation error.', 422, $validator->errors()->toArray());
        }

        $newSection = Section::findOrFail($request->input('new_section_id'));

        if ($newSection->batch_id !== $enrollment->batch_id || $newSection->course_id !== $enrollment->course_id) {
            return $this->error('MISMATCH_SECTION', 'Target section does not belong to the same course and batch.', 422);
        }

        // Check section capacity
        $activeInSection = Enrollment::where('section_id', $newSection->id)->where('status', 'active')->count();
        if ($newSection->capacity && $activeInSection >= $newSection->capacity) {
            return $this->error('SECTION_CAPACITY_EXCEEDED', "Section '{$newSection->name}' is at full capacity ({$newSection->capacity} seats).", 422);
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $oldSectionName = $enrollment->section?->name ?? 'None';
            $enrollment->section_id = $newSection->id;
            $enrollment->remarks = trim(($enrollment->remarks ? $enrollment->remarks."\n" : '').'Transferred from '.$oldSectionName.' to '.$newSection->name.': '.$request->input('reason'));
            $enrollment->save();

            AuditLogService::log(
                event: 'enrollment_section_transferred',
                description: "Transferred #{$enrollment->enrollment_no} from {$oldSectionName} to {$newSection->name}",
                auditable: $enrollment,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'transferred' => true,
                'enrollment_no' => $enrollment->enrollment_no,
                'previous_section' => $oldSectionName,
                'new_section' => $newSection->name,
                'section_code' => $newSection->code,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('TRANSFER_FAILED', 'Failed to transfer section: '.$e->getMessage(), 500);
        }
    }

    /**
     * Mark course enrollment as completed (Graduation / Alumni workflow).
     */
    public function complete(Request $request, string $identifier): JsonResponse
    {
        $enrollment = $this->findEnrollment($identifier);

        if (! $enrollment) {
            return $this->error('ENROLLMENT_NOT_FOUND', "Enrollment '{$identifier}' was not found.", 404);
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $enrollment->update([
                'status' => 'completed',
                'completed_at' => $request->input('completed_at', now()->toDateString()),
            ]);

            AuditLogService::log(
                event: 'enrollment_completed',
                description: "Enrollment #{$enrollment->enrollment_no} marked completed",
                auditable: $enrollment,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'completed' => true,
                'enrollment_no' => $enrollment->enrollment_no,
                'status' => 'completed',
                'completed_at' => $enrollment->completed_at?->toDateString(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('COMPLETION_FAILED', 'Failed to mark enrollment completed: '.$e->getMessage(), 500);
        }
    }

    /**
     * Discontinue / withdraw candidate from course.
     */
    public function discontinue(Request $request, string $identifier): JsonResponse
    {
        $enrollment = $this->findEnrollment($identifier);

        if (! $enrollment) {
            return $this->error('ENROLLMENT_NOT_FOUND', "Enrollment '{$identifier}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'A withdrawal reason is required.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $enrollment->update([
                'status' => 'discontinued',
                'cancellation_reason' => $request->input('reason'),
                'discontinued_at' => now()->toDateString(),
                'discontinued_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'enrollment_discontinued',
                description: "Enrollment #{$enrollment->enrollment_no} discontinued. Reason: {$request->input('reason')}",
                auditable: $enrollment,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'discontinued' => true,
                'enrollment_no' => $enrollment->enrollment_no,
                'status' => 'discontinued',
                'discontinued_at' => $enrollment->discontinued_at?->toDateString(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DISCONTINUE_FAILED', 'Failed to discontinue enrollment: '.$e->getMessage(), 500);
        }
    }

    /**
     * Reinstate a discontinued/suspended enrollment with audit trail.
     */
    public function reinstate(Request $request, string $identifier): JsonResponse
    {
        $enrollment = $this->findEnrollment($identifier);

        if (! $enrollment) {
            return $this->error('ENROLLMENT_NOT_FOUND', "Enrollment '{$identifier}' was not found.", 404);
        }

        $reason = $request->input('reinstatement_reason') ?? $request->input('reason') ?? $request->input('remarks');

        if (empty($reason)) {
            return $this->error('VALIDATION_ERROR', 'Reinstatement reason is required.', 422, [
                'reinstatement_reason' => ['The reinstatement reason field is required.'],
            ]);
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $enrollment->update([
                'status' => 'active',
                'reinstated_at' => now(),
                'reinstated_by' => $actor?->id,
                'reinstatement_reason' => $reason,
            ]);

            AuditLogService::log(
                event: 'enrollment_reinstated',
                description: "Enrollment #{$enrollment->enrollment_no} reinstated to active status",
                auditable: $enrollment,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success([
                'reinstated' => true,
                'enrollment_no' => $enrollment->enrollment_no,
                'status' => 'active',
                'reinstated_at' => $enrollment->reinstated_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('REINSTATE_FAILED', 'Failed to reinstate enrollment: '.$e->getMessage(), 500);
        }
    }

    /**
     * Bulk import enrollments from JSON array or uploaded spreadsheet.
     */
    public function importEnrollments(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $result = StudentImportExportService::importEnrollments($file->getRealPath());

            AuditLogService::log('enrollments_bulk_imported', "Imported {$result['success']} enrollments from file", userId: $actor?->id);

            return $this->success($result);
        }

        $validator = Validator::make($request->all(), [
            'enrollments' => 'required|array|min:1',
            'enrollments.*.student_identifier' => 'required|string',
            'enrollments.*.course_code' => 'required|string',
            'enrollments.*.batch_code' => 'required|string',
            'enrollments.*.section_code' => 'nullable|string',
            'enrollments.*.fee_status' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation error.', 422, $validator->errors()->toArray());
        }

        $records = $request->input('enrollments');
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $idx => $row) {
            DB::beginTransaction();
            try {
                $student = $this->findStudent($row['student_identifier']);
                if (! $student) {
                    $errors[] = "Row #{$idx}: Student '{$row['student_identifier']}' not found.";
                    $failed++;
                    DB::rollBack();

                    continue;
                }

                $course = Course::where('code', $row['course_code'])->first();
                $batch = Batch::where('code', $row['batch_code'])->first();

                if (! $course || ! $batch) {
                    $errors[] = "Row #{$idx}: Course '{$row['course_code']}' or Batch '{$row['batch_code']}' not found.";
                    $failed++;
                    DB::rollBack();

                    continue;
                }

                $section = null;
                if (! empty($row['section_code'])) {
                    $section = Section::where('batch_id', $batch->id)
                        ->where('course_id', $course->id)
                        ->where('code', $row['section_code'])
                        ->first();
                }

                Enrollment::create([
                    'user_id' => $student->id,
                    'batch_id' => $batch->id,
                    'course_id' => $course->id,
                    'section_id' => $section?->id,
                    'enrolled_at' => now(),
                    'fee_amount' => $course->fee ?: 0.00,
                    'fee_status' => $row['fee_status'] ?? 'paid',
                    'status' => 'active',
                    'created_by' => $actor?->id,
                ]);

                DB::commit();
                $success++;
            } catch (Throwable $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "Row #{$idx}: {$e->getMessage()}";
            }
        }

        AuditLogService::log('enrollments_bulk_imported', "Bulk imported {$success} enrollments via JSON", userId: $actor?->id);

        return $this->success([
            'imported' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /**
     * Export enrollments dataset as JSON array with filters.
     */
    public function exportEnrollments(Request $request): JsonResponse
    {
        $query = Enrollment::with([
            'user:id,name,student_id,urn,email,phone',
            'course:id,name,code',
            'batch:id,name,code,academic_year',
            'section:id,name,code',
        ]);

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $enrollments = $query->latest('enrolled_at')->limit(1000)->get()->map(fn ($e) => [
            'enrollment_no' => $e->enrollment_no,
            'application_no' => $e->application_no,
            'student_id' => $e->user?->student_id,
            'student_name' => $e->user?->name,
            'student_email' => $e->user?->email,
            'course' => $e->course?->name,
            'batch' => $e->batch?->name,
            'section' => $e->section?->name,
            'roll_no' => $e->roll_no,
            'enrolled_at' => $e->enrolled_at?->toDateString(),
            'completed_at' => $e->completed_at?->toDateString(),
            'fee_amount' => $e->fee_amount,
            'fee_status' => $e->fee_status,
            'status' => $e->status,
        ]);

        return $this->success($enrollments, ['count' => $enrollments->count()]);
    }
}
