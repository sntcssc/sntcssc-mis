<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\StudentInformationSheetService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StudentInformationSheetApiController extends Controller
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
     *
     * @param  array<string, mixed>|null  $meta
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
     *
     * @param  array<string, mixed>|null  $details
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
     * Locate a student user by ID, UUID, Student ID, URN, or Email.
     */
    protected function findStudent(string $identifier, bool $withTrashed = false): ?User
    {
        $query = User::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->where(function ($q) use ($identifier) {
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
        })
            ->where(function ($q) {
                $q->role('Student')
                    ->orWhereNotNull('student_id')
                    ->orWhereHas('studentProfile')
                    ->orWhereHas('enrollments');
            })
            ->first();
    }

    /**
     * 1. Query & paginate candidate summary cards available for Information Sheet generation.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $search = trim((string) $request->query('search', ''));
            $courseId = $request->query('course_id');
            $batchId = $request->query('batch_id');
            $gender = $request->query('gender');
            $category = $request->query('category');
            $status = $request->query('status');
            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

            $query = User::query()
                ->where(function ($q) {
                    $q->role('Student')
                        ->orWhereNotNull('student_id')
                        ->orWhereHas('studentProfile')
                        ->orWhereHas('enrollments');
                })
                ->with([
                    'studentProfile',
                    'activeEnrollments.batch',
                    'activeEnrollments.course',
                    'studentDocuments',
                ]);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%")
                        ->orWhere('urn', 'like', "%{$search}%");
                });
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($courseId) {
                $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $courseId));
            }

            if ($batchId) {
                $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', $batchId));
            }

            if ($gender) {
                $query->where(function ($q) use ($gender) {
                    $q->where('gender', $gender)
                        ->orWhereHas('studentProfile', fn ($sp) => $sp->where('gender', $gender));
                });
            }

            if ($category) {
                $query->where(function ($q) use ($category) {
                    $q->where('category', $category)
                        ->orWhereHas('studentProfile', fn ($sp) => $sp->where('category', $category));
                });
            }

            $paginated = $query->orderBy('name')->paginate($perPage);

            $items = collect($paginated->items())->map(function (User $student) {
                $profile = $student->studentProfile;
                $activeEnrollment = $student->activeEnrollments->first();

                return [
                    'id' => $student->id,
                    'uuid' => $student->uuid,
                    'student_id' => $student->student_id,
                    'urn' => $student->urn,
                    'name' => $student->name,
                    'email' => $student->email,
                    'phone' => $student->phone,
                    'gender' => $student->gender ?? $profile?->gender ?? '—',
                    'category' => $student->category ?? $profile?->category ?? '—',
                    'status' => $student->status ?? 'active',
                    'batch_name' => $activeEnrollment?->batch?->name ?? '—',
                    'course_name' => $activeEnrollment?->course?->name ?? '—',
                    'documents_count' => $student->studentDocuments->count(),
                    'endpoints' => [
                        'sheet' => route('api.v1.students.information-sheet.show', ['identifier' => $student->id]),
                        'download_pdf' => route('api.v1.students.information-sheet.download-single', ['identifier' => $student->id]),
                    ],
                ];
            });

            return $this->success($items, [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'metadata' => StudentInformationSheetService::getMetadata(),
            ]);
        } catch (Throwable $e) {
            Log::error('API Error in StudentInformationSheetApiController@index: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->error('STUDENT_SHEETS_INDEX_FAILED', 'Failed to retrieve student information sheets directory: '.$e->getMessage(), 500);
        }
    }

    /**
     * 2. Comprehensive JSON dossier Information Sheet for a single student.
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        try {
            $includeDocuments = filter_var($request->query('include_documents', false), FILTER_VALIDATE_BOOLEAN);
            $withTrashed = filter_var($request->query('with_trashed', false), FILTER_VALIDATE_BOOLEAN);

            $student = $this->findStudent($identifier, $withTrashed);

            if (! $student) {
                return $this->error('STUDENT_NOT_FOUND', "No student found matching identifier '{$identifier}'.", 404);
            }

            $sheetData = StudentInformationSheetService::getStudentData($student, $includeDocuments);
            $user = $this->resolveUser($request);

            AuditLogService::log(
                event: 'student_information_sheet_viewed',
                description: "Viewed information sheet for student {$student->name} ({$student->student_id})",
                auditable: $student,
                userId: $user?->id
            );

            return $this->success($sheetData, [
                'student_id' => $student->student_id,
                'urn' => $student->urn,
                'retrieved_at' => now()->toIso8601String(),
                'documents_included' => $includeDocuments,
            ]);
        } catch (Throwable $e) {
            Log::error("API Error in StudentInformationSheetApiController@show for '{$identifier}': ".$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->error('STUDENT_SHEET_FETCH_FAILED', 'Failed to compile student information sheet: '.$e->getMessage(), 500);
        }
    }

    /**
     * 3. Bulk fetch comprehensive Information Sheets JSON payloads for multiple students.
     */
    public function bulk(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required',
                'include_documents' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return $this->error('VALIDATION_ERROR', 'Validation failed for bulk information sheet request.', 422, $validator->errors()->toArray());
            }

            $rawIds = $request->input('ids');
            $ids = is_array($rawIds) ? $rawIds : array_map('trim', explode(',', (string) $rawIds));
            $ids = array_values(array_filter(array_unique($ids)));

            if (empty($ids)) {
                return $this->error('EMPTY_ID_LIST', 'Please provide at least one valid student ID or identifier.', 422);
            }

            if (count($ids) > 100) {
                return $this->error('BULK_LIMIT_EXCEEDED', 'A maximum of 100 student information sheets can be queried per bulk request.', 422);
            }

            $includeDocuments = filter_var($request->input('include_documents', false), FILTER_VALIDATE_BOOLEAN);

            $students = User::whereIn('id', $ids)
                ->orWhereIn('student_id', $ids)
                ->orWhereIn('urn', $ids)
                ->orWhereIn('uuid', $ids)
                ->where(function ($q) {
                    $q->role('Student')
                        ->orWhereNotNull('student_id')
                        ->orWhereHas('studentProfile')
                        ->orWhereHas('enrollments');
                })
                ->get();

            if ($students->isEmpty()) {
                return $this->error('STUDENTS_NOT_FOUND', 'None of the requested student identifiers could be found.', 404);
            }

            $sheets = StudentInformationSheetService::getMultipleStudentsData($students, $includeDocuments);
            $user = $this->resolveUser($request);

            AuditLogService::log(
                event: 'student_information_sheets_bulk_viewed',
                description: 'Bulk viewed '.count($sheets).' student detailed information sheets',
                userId: $user?->id
            );

            return $this->success($sheets, [
                'count' => count($sheets),
                'requested_count' => count($ids),
                'documents_included' => $includeDocuments,
                'metadata' => StudentInformationSheetService::getMetadata(),
            ]);
        } catch (Throwable $e) {
            Log::error('API Error in StudentInformationSheetApiController@bulk: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->error('BULK_SHEET_FETCH_FAILED', 'Failed to compile bulk student information sheets: '.$e->getMessage(), 500);
        }
    }

    /**
     * 4. Stream or download compiled PDF Information Sheet for single or multiple students.
     */
    public function download(Request $request, ?string $identifier = null): Response|JsonResponse
    {
        try {
            $includeDocuments = filter_var($request->input('include_documents', $request->query('include_documents', false)), FILTER_VALIDATE_BOOLEAN);
            $disposition = strtolower((string) $request->input('disposition', $request->query('disposition', 'attachment')));
            $format = strtolower((string) $request->input('format', $request->query('format', 'pdf')));

            $students = collect();

            if ($identifier !== null) {
                $student = $this->findStudent($identifier);
                if (! $student) {
                    return $this->error('STUDENT_NOT_FOUND', "No student found matching identifier '{$identifier}'.", 404);
                }
                $students->push($student);
            } else {
                $rawIds = $request->input('ids', $request->query('ids'));
                if (! $rawIds) {
                    return $this->error('MISSING_IDENTIFIER', 'Please specify a student identifier in the path or provide an array of student ids.', 422);
                }

                $ids = is_array($rawIds) ? $rawIds : array_map('trim', explode(',', (string) $rawIds));
                $ids = array_values(array_filter(array_unique($ids)));

                if (empty($ids)) {
                    return $this->error('EMPTY_ID_LIST', 'Please provide at least one valid student identifier.', 422);
                }

                if (count($ids) > 100) {
                    return $this->error('BULK_LIMIT_EXCEEDED', 'A maximum of 100 student information sheets can be downloaded in a single PDF request.', 422);
                }

                $students = User::where(function ($q) use ($ids) {
                    $q->whereIn('id', $ids)
                        ->orWhereIn('student_id', $ids)
                        ->orWhereIn('urn', $ids)
                        ->orWhereIn('uuid', $ids);
                })
                    ->where(function ($q) {
                        $q->role('Student')
                            ->orWhereNotNull('student_id')
                            ->orWhereHas('studentProfile')
                            ->orWhereHas('enrollments');
                    })
                    ->get();

                if ($students->isEmpty()) {
                    return $this->error('STUDENTS_NOT_FOUND', 'None of the requested student identifiers could be found.', 404);
                }
            }

            $studentsData = StudentInformationSheetService::getMultipleStudentsData($students, $includeDocuments);
            $user = $this->resolveUser($request);

            $filename = count($studentsData) === 1
                ? 'Student-Information-Sheet-'.Str::slug($studentsData[0]['student_id'] ?: $studentsData[0]['name']).'-'.now()->format('Ymd').'.pdf'
                : 'Student-Information-Sheets-Batch-'.count($studentsData).'-Students-'.now()->format('Ymd-His').'.pdf';

            // Log administrative download audit trail
            AuditLogService::log(
                event: 'student_information_sheet_downloaded',
                description: count($studentsData) === 1
                    ? "Downloaded PDF information sheet for {$studentsData[0]['name']} ({$studentsData[0]['student_id']})"
                    : 'Downloaded compiled batch PDF information sheets for '.count($studentsData).' students',
                auditable: count($studentsData) === 1 ? $students->first() : null,
                userId: $user?->id
            );

            // Support JSON/base64 response for headless or Developer Console API test environments
            if ($format === 'base64' || ($request->wantsJson() && $format !== 'binary')) {
                $pdf = StudentInformationSheetService::generatePdf($studentsData, $includeDocuments);
                $pdfOutput = $pdf->output();

                return $this->success([
                    'filename' => $filename,
                    'student_count' => count($studentsData),
                    'documents_included' => $includeDocuments,
                    'file_size_bytes' => strlen($pdfOutput),
                    'mime_type' => 'application/pdf',
                    'base64_pdf' => base64_encode($pdfOutput),
                    'download_url' => count($studentsData) === 1
                        ? route('api.v1.students.information-sheet.download-single', ['identifier' => $studentsData[0]['id'], 'format' => 'binary'])
                        : null,
                ]);
            }

            // Normal binary / streamed PDF output for browsers and direct HTTP clients
            $pdf = StudentInformationSheetService::generatePdf($studentsData, $includeDocuments);

            if ($disposition === 'inline') {
                return $pdf->stream($filename);
            }

            return $pdf->download($filename);
        } catch (Throwable $e) {
            Log::error('API Error in StudentInformationSheetApiController@download: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->error('PDF_GENERATION_FAILED', 'Failed to generate PDF information sheet: '.$e->getMessage(), 500);
        }
    }

    /**
     * 5. Student self-service endpoint: retrieve logged-in candidate's own Information Sheet.
     */
    public function mySheet(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);

            if (! $user) {
                return $this->error('UNAUTHENTICATED', 'Authentication required to access student information sheet.', 401);
            }

            $includeDocuments = filter_var($request->query('include_documents', false), FILTER_VALIDATE_BOOLEAN);
            $sheetData = StudentInformationSheetService::getStudentData($user, $includeDocuments);

            AuditLogService::log(
                event: 'student_self_information_sheet_viewed',
                description: "Student {$user->name} viewed own information sheet dossier",
                auditable: $user,
                userId: $user->id
            );

            return $this->success($sheetData, [
                'student_id' => $user->student_id,
                'urn' => $user->urn,
                'retrieved_at' => now()->toIso8601String(),
                'documents_included' => $includeDocuments,
            ]);
        } catch (Throwable $e) {
            Log::error('API Error in StudentInformationSheetApiController@mySheet: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->error('STUDENT_SELF_SHEET_FAILED', 'Failed to retrieve your student information sheet: '.$e->getMessage(), 500);
        }
    }

    /**
     * 6. Student self-service endpoint: download logged-in candidate's own PDF Information Sheet.
     */
    public function mySheetDownload(Request $request): Response|JsonResponse
    {
        try {
            $user = $this->resolveUser($request);

            if (! $user) {
                return $this->error('UNAUTHENTICATED', 'Authentication required to download student information sheet.', 401);
            }

            $includeDocuments = filter_var($request->input('include_documents', $request->query('include_documents', false)), FILTER_VALIDATE_BOOLEAN);
            $disposition = strtolower((string) $request->input('disposition', $request->query('disposition', 'attachment')));
            $format = strtolower((string) $request->input('format', $request->query('format', 'pdf')));

            $studentsData = [StudentInformationSheetService::getStudentData($user, $includeDocuments)];
            $filename = 'Student-Information-Sheet-'.Str::slug($user->student_id ?: $user->name).'-'.now()->format('Ymd').'.pdf';

            AuditLogService::log(
                event: 'student_self_information_sheet_downloaded',
                description: "Student {$user->name} downloaded own information sheet PDF",
                auditable: $user,
                userId: $user->id
            );

            if ($format === 'base64' || ($request->wantsJson() && $format !== 'binary')) {
                $pdf = StudentInformationSheetService::generatePdf($studentsData, $includeDocuments);
                $pdfOutput = $pdf->output();

                return $this->success([
                    'filename' => $filename,
                    'student_count' => 1,
                    'documents_included' => $includeDocuments,
                    'file_size_bytes' => strlen($pdfOutput),
                    'mime_type' => 'application/pdf',
                    'base64_pdf' => base64_encode($pdfOutput),
                ]);
            }

            $pdf = StudentInformationSheetService::generatePdf($studentsData, $includeDocuments);

            if ($disposition === 'inline') {
                return $pdf->stream($filename);
            }

            return $pdf->download($filename);
        } catch (Throwable $e) {
            Log::error('API Error in StudentInformationSheetApiController@mySheetDownload: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->error('PDF_GENERATION_FAILED', 'Failed to generate your PDF information sheet: '.$e->getMessage(), 500);
        }
    }
}
