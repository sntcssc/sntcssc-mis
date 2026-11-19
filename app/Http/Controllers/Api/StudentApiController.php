<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ProfileUpdateRequest;
use App\Models\Section;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AdmissionRestrictionService;
use App\Services\AuditLogService;
use App\Services\StudentIdGeneratorService;
use App\Services\StudentImportExportService;
use App\Services\UserStudentSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class StudentApiController extends Controller
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
     * Locate a student user by ID, UUID, Student ID, URN, or Email.
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
        })
            ->where(function ($q) {
                $q->role('Student')
                    ->orWhereNotNull('student_id')
                    ->orWhereHas('studentProfile')
                    ->orWhereHas('enrollments');
            })
            ->first();
    }

    // =========================================================================
    // 1. Executive Telemetry & Verification Kiosks
    // =========================================================================

    /**
     * Executive student telemetry, active enrollment counts, and demographic distributions.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $totalStudents = User::role('Student')->count();
            $activeStudents = User::role('Student')->where('status', 'active')->count();

            $totalEnrollments = Enrollment::count();
            $activeEnrollments = Enrollment::where('status', 'active')->count();
            $completedEnrollments = Enrollment::where('status', 'completed')->count();
            $discontinuedEnrollments = Enrollment::whereIn('status', ['discontinued', 'dropped', 'cancelled'])->count();

            // Category breakdown (UR, SC, ST, OBCA, OBCB, EWS)
            $categoryDistribution = StudentProfile::select('category', DB::raw('count(*) as count'))
                ->whereNotNull('category')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray();

            // Gender breakdown
            $genderDistribution = User::role('Student')
                ->select('gender', DB::raw('count(*) as count'))
                ->whereNotNull('gender')
                ->groupBy('gender')
                ->pluck('count', 'gender')
                ->toArray();

            // Pending profile correction requests
            $pendingProfileRequests = ProfileUpdateRequest::where('status', 'pending')->count();

            // Course enrollment counts
            $courseDistribution = Enrollment::join('courses', 'enrollments.course_id', '=', 'courses.id')
                ->select('courses.name as course_name', 'courses.code as course_code', DB::raw('count(*) as count'))
                ->where('enrollments.status', 'active')
                ->groupBy('courses.id', 'courses.name', 'courses.code')
                ->orderByDesc('count')
                ->limit(8)
                ->get();

            // Recent 5 admissions
            $recentAdmissions = Enrollment::with(['user:id,name,student_id,email,phone,avatar', 'course:id,name,code', 'batch:id,name,code'])
                ->latest('enrolled_at')
                ->limit(5)
                ->get()
                ->map(fn ($enr) => [
                    'id' => $enr->id,
                    'enrollment_no' => $enr->enrollment_no,
                    'student_id' => $enr->user?->student_id,
                    'student_name' => $enr->user?->name,
                    'student_email' => $enr->user?->email,
                    'course' => $enr->course?->name,
                    'course_code' => $enr->course?->code,
                    'batch' => $enr->batch?->name,
                    'enrolled_at' => $enr->enrolled_at?->toDateString(),
                    'fee_status' => $enr->fee_status,
                    'status' => $enr->status,
                ]);

            return $this->success([
                'overview' => [
                    'total_students' => $totalStudents,
                    'active_students' => $activeStudents,
                    'inactive_students' => max(0, $totalStudents - $activeStudents),
                    'total_enrollments' => $totalEnrollments,
                    'active_enrollments' => $activeEnrollments,
                    'completed_enrollments' => $completedEnrollments,
                    'discontinued_enrollments' => $discontinuedEnrollments,
                    'pending_profile_requests' => $pendingProfileRequests,
                ],
                'category_distribution' => $categoryDistribution,
                'gender_distribution' => $genderDistribution,
                'course_distribution' => $courseDistribution,
                'recent_admissions' => $recentAdmissions,
            ]);
        } catch (Throwable $e) {
            return $this->error('TELEMETRY_FETCH_FAILED', 'Failed to retrieve telemetry data: '.$e->getMessage(), 500);
        }
    }

    /**
     * Analytical statistics including batch occupancy and multi-year admissions trend.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            // Batch occupancy
            $batches = Batch::with(['courses' => function ($q) {
                $q->select('courses.id', 'courses.name', 'courses.code');
            }])
                ->latest('academic_year')
                ->limit(6)
                ->get()
                ->map(function ($batch) {
                    $enrolled = Enrollment::where('batch_id', $batch->id)->where('status', 'active')->count();
                    $capacity = $batch->max_students ?: 100;
                    $utilization = $capacity > 0 ? round(($enrolled / $capacity) * 100, 1) : 0;

                    return [
                        'batch_id' => $batch->id,
                        'name' => $batch->name,
                        'code' => $batch->code,
                        'academic_year' => $batch->academic_year,
                        'status' => $batch->status,
                        'capacity' => $capacity,
                        'enrolled_count' => $enrolled,
                        'utilization_rate_pct' => min(100.0, $utilization),
                    ];
                });

            // Admissions by Academic Year
            $driver = DB::connection()->getDriverName();
            $yearSql = match ($driver) {
                'sqlite' => "strftime('%Y', enrolled_at)",
                'pgsql' => "to_char(enrolled_at, 'YYYY')",
                'sqlsrv' => 'YEAR(enrolled_at)',
                default => 'DATE_FORMAT(enrolled_at, "%Y")',
            };

            $yearTrends = Enrollment::select(DB::raw("{$yearSql} as year, count(*) as count"))
                ->whereNotNull('enrolled_at')
                ->groupBy('year')
                ->orderByDesc('year')
                ->limit(5)
                ->pluck('count', 'year')
                ->toArray();

            // Fee collection breakdown
            $feeBreakdown = Enrollment::select('fee_status', DB::raw('count(*) as count, sum(fee_amount) as total_amount'))
                ->groupBy('fee_status')
                ->get()
                ->keyBy('fee_status');

            return $this->success([
                'batches_occupancy' => $batches,
                'admissions_by_year' => $yearTrends,
                'fee_breakdown' => $feeBreakdown,
            ]);
        } catch (Throwable $e) {
            return $this->error('STATISTICS_FETCH_FAILED', 'Failed to retrieve statistics: '.$e->getMessage(), 500);
        }
    }

    /**
     * Rapid identity verification for campus turnstiles, USB/RFID scanners, and gatekeeper kiosks.
     */
    public function verifyIdentity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required_without_all:barcode,student_id,urn,roll_no|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'student_id' => 'nullable|string|max:50',
            'urn' => 'nullable|string|max:50',
            'roll_no' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Identifier parameter is required.', 422, $validator->errors()->toArray());
        }

        $lookup = $request->input('identifier')
            ?: $request->input('barcode')
            ?: $request->input('student_id')
            ?: $request->input('urn');

        $student = null;

        if ($lookup) {
            $student = $this->findStudent($lookup);
        }

        if (! $student && $rollNo = $request->input('roll_no')) {
            $enrollment = Enrollment::where('roll_no', $rollNo)->with('user')->first();
            $student = $enrollment?->user;
        }

        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "No student record found matching identity '{$lookup}'.", 404);
        }

        $activeEnrollment = $student->activeEnrollment()
            ->with(['course:id,name,code', 'batch:id,name,code', 'section:id,name,code,room_number'])
            ->first();

        $profile = $student->studentProfile;

        return $this->success([
            'verified' => true,
            'student' => [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'urn' => $student->urn,
                'name' => $student->name,
                'email' => $student->email,
                'phone' => $student->phone,
                'gender' => $student->gender,
                'category' => $profile?->category ?? 'UR',
                'status' => $student->status,
                'avatar_url' => $student->avatar_url,
            ],
            'active_enrollment' => $activeEnrollment ? [
                'enrollment_no' => $activeEnrollment->enrollment_no,
                'course' => $activeEnrollment->course?->name,
                'course_code' => $activeEnrollment->course?->code,
                'batch' => $activeEnrollment->batch?->name,
                'section' => $activeEnrollment->section?->name,
                'room_number' => $activeEnrollment->section?->room_number,
                'roll_no' => $activeEnrollment->roll_no,
                'enrolled_at' => $activeEnrollment->enrolled_at?->toDateString(),
                'fee_status' => $activeEnrollment->fee_status,
                'status' => $activeEnrollment->status,
            ] : null,
            'flags' => [
                'is_active' => $student->status === 'active',
                'has_active_enrollment' => (bool) $activeEnrollment,
                'fee_clear' => in_array($activeEnrollment?->fee_status, ['paid', 'exempted']),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    // =========================================================================
    // 2. Students Directory & Detailed Dossier (CRUD)
    // =========================================================================

    /**
     * Search and paginate students with multi-attribute filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::role('Student');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhere('urn', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($courseId = $request->input('course_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $courseId));
        }

        if ($batchId = $request->input('batch_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', $batchId));
        }

        if ($sectionId = $request->input('section_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('section_id', $sectionId));
        }

        if ($category = $request->input('category')) {
            $query->whereHas('studentProfile', fn ($q) => $q->where('category', $category));
        }

        if ($gender = $request->input('gender')) {
            $query->where('gender', $gender);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortField = in_array($request->input('sort_by'), ['name', 'student_id', 'created_at', 'email'])
            ? $request->input('sort_by')
            : 'name';
        $sortDirection = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';

        $perPage = min(100, max(5, $request->integer('per_page', 25)));

        $students = $query->select([
            'id', 'uuid', 'urn', 'student_id', 'name', 'first_name', 'last_name',
            'email', 'phone', 'whatsapp_no', 'gender', 'dob', 'status', 'avatar', 'created_at',
        ])
            ->with([
                'studentProfile:id,user_id,category,father_name,mother_name,is_pwbd',
                'activeEnrollment' => fn ($q) => $q->with(['course:id,name,code', 'batch:id,name,code', 'section:id,name,code']),
            ])
            ->orderBy($sortField, $sortDirection)
            ->paginate($perPage);

        $items = collect($students->items())->map(fn ($student) => [
            'id' => $student->id,
            'uuid' => $student->uuid,
            'student_id' => $student->student_id,
            'urn' => $student->urn,
            'name' => $student->name,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'email' => $student->email,
            'phone' => $student->phone,
            'whatsapp_no' => $student->whatsapp_no,
            'gender' => $student->gender,
            'dob' => $student->dob ? Carbon::parse($student->dob)->toDateString() : null,
            'category' => $student->studentProfile?->category ?? 'UR',
            'father_name' => $student->studentProfile?->father_name,
            'is_pwbd' => (bool) $student->studentProfile?->is_pwbd,
            'status' => $student->status,
            'avatar_url' => $student->avatar_url,
            'current_enrollment' => $student->activeEnrollment ? [
                'enrollment_no' => $student->activeEnrollment->enrollment_no,
                'course' => $student->activeEnrollment->course?->name,
                'course_code' => $student->activeEnrollment->course?->code,
                'batch' => $student->activeEnrollment->batch?->name,
                'section' => $student->activeEnrollment->section?->name,
                'fee_status' => $student->activeEnrollment->fee_status,
                'status' => $student->activeEnrollment->status,
            ] : null,
            'created_at' => $student->created_at?->toIso8601String(),
        ]);

        return $this->success($items, [
            'current_page' => $students->currentPage(),
            'last_page' => $students->lastPage(),
            'per_page' => $students->perPage(),
            'total' => $students->total(),
        ]);
    }

    /**
     * Create/register a new student record with auto ID generation and optional initial course enrollment.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20|unique:users,phone',
            'whatsapp_no' => 'nullable|string|max:20',
            'gender' => 'required|string|in:male,female,others,Male,Female,Other,Others',
            'dob' => 'nullable|date',
            'category' => 'nullable|string|in:UR,SC,ST,OBCA,OBCB,EWS,General,OBC-A,OBC-B',
            'father_name' => 'nullable|string|max:150',
            'mother_name' => 'nullable|string|max:150',
            'is_pwbd' => 'nullable|boolean',
            'is_working_professional' => 'nullable|boolean',
            'status' => 'nullable|string|in:active,inactive,suspended',

            // Optional initial enrollment
            'course_id' => 'nullable|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
            'section_id' => 'nullable|exists:sections,id',
            'fee_status' => 'nullable|string|in:pending,partial,paid,exempted',
            'allow_override' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for student creation.', 422, $validator->errors()->toArray());
        }

        $creator = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $normalizedGender = ucfirst(strtolower($request->input('gender')));
            if ($normalizedGender === 'Others') {
                $normalizedGender = 'Other';
            }

            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'whatsapp_no' => $request->input('whatsapp_no') ?: $request->input('phone'),
                'gender' => $normalizedGender,
                'dob' => $request->input('dob'),
                'password' => Hash::make(Str::random(16)), // Secure default random password
                'status' => $request->input('status', 'active'),
            ]);

            $user->assignRole('Student');

            // Generate lifetime 9-digit unique Student ID
            $course = $request->input('course_id') ? Course::find($request->input('course_id')) : null;
            $section = $request->input('section_id') ? Section::find($request->input('section_id')) : null;
            $batch = $request->input('batch_id') ? Batch::find($request->input('batch_id')) : null;

            $studentId = StudentIdGeneratorService::generateForStudent($user, $course, $section, $batch?->academic_year);
            $user->updateQuietly(['student_id' => $studentId]);

            // Create Student Profile Dossier
            $profile = StudentProfile::create([
                'user_id' => $user->id,
                'father_name' => $request->input('father_name'),
                'mother_name' => $request->input('mother_name'),
                'dob' => $request->input('dob'),
                'gender' => $normalizedGender,
                'category' => $request->input('category', 'UR'),
                'is_pwbd' => (bool) $request->input('is_pwbd', false),
            ]);

            // Optional Initial Enrollment
            $enrollment = null;
            if ($course && $batch) {
                $eligibility = AdmissionRestrictionService::checkEligibility(
                    $user,
                    $course,
                    $batch,
                    $section,
                    (bool) $request->input('allow_override', false)
                );

                if (! $eligibility['allowed']) {
                    DB::rollBack();

                    return $this->error('ADMISSION_RESTRICTION', $eligibility['reason'] ?? 'Candidate is not eligible for this programme.', 422);
                }

                $enrollment = Enrollment::create([
                    'user_id' => $user->id,
                    'batch_id' => $batch->id,
                    'course_id' => $course->id,
                    'section_id' => $section?->id,
                    'enrolled_at' => now(),
                    'fee_amount' => $course->fee ?: 0.00,
                    'fee_status' => $request->input('fee_status', 'pending'),
                    'status' => 'active',
                    'created_by' => $creator?->id,
                ]);
            }

            // Sync user student properties
            UserStudentSyncService::syncFromUser($user);

            AuditLogService::log(
                event: 'student_registered',
                description: "Student {$user->name} registered with Lifetime Student ID {$user->student_id}",
                auditable: $user,
                oldValues: null,
                newValues: $user->only(['id', 'student_id', 'name', 'email', 'phone', 'gender']),
                userId: $creator?->id
            );

            DB::commit();

            return $this->success([
                'id' => $user->id,
                'uuid' => $user->uuid,
                'student_id' => $user->student_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'category' => $profile->category,
                'status' => $user->status,
                'initial_enrollment' => $enrollment ? [
                    'id' => $enrollment->id,
                    'enrollment_no' => $enrollment->enrollment_no,
                    'course' => $course->name,
                    'batch' => $batch->name,
                ] : null,
            ], null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('STUDENT_CREATION_FAILED', 'Failed to register student: '.$e->getMessage(), 500);
        }
    }

    /**
     * Show comprehensive 360-degree student profile dossier.
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);

        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student with identifier '{$identifier}' was not found.", 404);
        }

        $student->load([
            'studentProfile',
            'studentAddresses',
            'studentEducations' => fn ($q) => $q->orderBy('passing_year', 'asc'),
            'studentEmployments' => fn ($q) => $q->orderBy('from_date', 'desc'),
            'studentOtherDetails',
            'studentDocuments' => fn ($q) => $q->where('is_active', true),
            'enrollments' => fn ($q) => $q->with(['course:id,name,code,duration,fee', 'batch:id,name,code,academic_year', 'section:id,name,code,room_number'])->orderBy('enrolled_at', 'desc'),
        ]);

        $profile = $student->studentProfile;
        $completeness = $student->getProfileCompleteness();

        return $this->success([
            'student' => [
                'id' => $student->id,
                'uuid' => $student->uuid,
                'urn' => $student->urn,
                'student_id' => $student->student_id,
                'name' => $student->name,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'email' => $student->email,
                'phone' => $student->phone,
                'whatsapp_no' => $student->whatsapp_no,
                'gender' => $student->gender,
                'dob' => $student->dob ? Carbon::parse($student->dob)->toDateString() : null,
                'status' => $student->status,
                'avatar_url' => $student->avatar_url,
                'created_at' => $student->created_at?->toIso8601String(),
                'updated_at' => $student->updated_at?->toIso8601String(),
            ],
            'profile_dossier' => $profile ? [
                'father_name' => $profile->father_name,
                'mother_name' => $profile->mother_name,
                'category' => $profile->category,
                'subcaste' => $profile->subcaste,
                'cert_no' => $profile->cert_no,
                'issued_by' => $profile->issued_by,
                'is_pwbd' => (bool) $profile->is_pwbd,
                'pwbd_type' => $profile->pwbd_type,
                'pwd_percentage' => $profile->pwd_percentage,
                'father_occupation' => $profile->father_occupation,
                'mother_occupation' => $profile->mother_occupation,
                'family_income' => $profile->family_income,
                'school_language' => $profile->school_language,
                'hobbies' => $profile->hobbies,
                'activities' => $profile->activities,
            ] : null,
            'addresses' => $student->studentAddresses->map(fn ($addr) => [
                'id' => $addr->id,
                'address_type' => $addr->address_type,
                'state' => $addr->state,
                'district' => $addr->district,
                'subdistrict' => $addr->subdistrict,
                'address_line1' => $addr->address_line1,
                'address_line2' => $addr->address_line2,
                'post_office' => $addr->post_office,
                'police_station' => $addr->police_station,
                'pin_code' => $addr->pin_code,
                'formatted' => $addr->formatted_address,
            ]),
            'educations' => $student->studentEducations->map(fn ($edu) => [
                'id' => $edu->id,
                'level' => $edu->level,
                'board_university' => $edu->board_university,
                'institute' => $edu->institute,
                'stream_subject' => $edu->stream_subject,
                'passing_year' => $edu->passing_year,
                'percentage' => $edu->percentage,
                'cgpa' => $edu->cgpa,
                'division' => $edu->division,
                'is_highest' => (bool) $edu->is_highest,
            ]),
            'employments' => $student->studentEmployments->map(fn ($emp) => [
                'id' => $emp->id,
                'employer_name' => $emp->employer_name,
                'designation' => $emp->designation,
                'nature_of_employment' => $emp->nature_of_employment,
                'location' => $emp->location,
                'from_date' => $emp->from_date?->toDateString(),
                'to_date' => $emp->to_date?->toDateString(),
                'is_current' => (bool) $emp->is_current,
            ]),
            'upsc_details' => $student->studentOtherDetails ? [
                'attempts_already_given' => $student->studentOtherDetails->attempts_already_given,
                'attempts_left' => $student->studentOtherDetails->attempts_left,
                'optional_subject' => $student->studentOtherDetails->optional_subject,
                'year_of_appearing' => $student->studentOtherDetails->year_of_appearing,
            ] : null,
            'documents' => $student->studentDocuments->map(fn ($doc) => [
                'id' => $doc->id,
                'type' => $doc->type,
                'document_type' => $doc->document_type,
                'file_name' => $doc->file_name,
                'file_path' => $doc->file_path,
                'verified' => (bool) $doc->verified,
                'verified_at' => $doc->verified_at?->toIso8601String(),
                'uploaded_at' => $doc->uploaded_at?->toIso8601String(),
            ]),
            'enrollments' => $student->enrollments->map(fn ($enr) => [
                'id' => $enr->id,
                'enrollment_no' => $enr->enrollment_no,
                'course' => [
                    'id' => $enr->course?->id,
                    'name' => $enr->course?->name,
                    'code' => $enr->course?->code,
                ],
                'batch' => [
                    'id' => $enr->batch?->id,
                    'name' => $enr->batch?->name,
                    'academic_year' => $enr->batch?->academic_year,
                ],
                'section' => $enr->section ? [
                    'id' => $enr->section->id,
                    'name' => $enr->section->name,
                    'room_number' => $enr->section->room_number,
                ] : null,
                'roll_no' => $enr->roll_no,
                'enrolled_at' => $enr->enrolled_at?->toDateString(),
                'completed_at' => $enr->completed_at?->toDateString(),
                'fee_amount' => $enr->fee_amount,
                'fee_status' => $enr->fee_status,
                'status' => $enr->status,
            ]),
            'profile_completeness' => $completeness,
        ]);
    }

    /**
     * Update student record, contact info, and profile details.
     */
    public function update(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);

        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student with identifier '{$identifier}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:20|unique:users,phone,'.$student->id,
            'whatsapp_no' => 'nullable|string|max:20',
            'gender' => 'nullable|string|in:male,female,others,Male,Female,Other,Others',
            'dob' => 'nullable|date',
            'status' => 'nullable|string|in:active,inactive,suspended',
            'father_name' => 'nullable|string|max:150',
            'mother_name' => 'nullable|string|max:150',
            'category' => 'nullable|string|in:UR,SC,ST,OBCA,OBCB,EWS,General,OBC-A,OBC-B',
            'subcaste' => 'nullable|string|max:150',
            'is_pwbd' => 'nullable|boolean',
            'pwbd_type' => 'nullable|string|max:150',
            'pwd_percentage' => 'nullable|integer|min:0|max:100',
            'family_income' => 'nullable|numeric|min:0',
            'school_language' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for student update.', 422, $validator->errors()->toArray());
        }

        $updater = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $oldUserValues = $student->only(['name', 'phone', 'whatsapp_no', 'gender', 'dob', 'status']);

            $userUpdates = array_filter($request->only(['name', 'phone', 'whatsapp_no', 'status']), fn ($v) => $v !== null);

            if ($gender = $request->input('gender')) {
                $userUpdates['gender'] = ucfirst(strtolower($gender));
            }
            if ($request->has('dob')) {
                $userUpdates['dob'] = $request->input('dob');
            }

            if (! empty($userUpdates)) {
                $student->update($userUpdates);
            }

            // Update or create profile
            $profile = $student->studentProfile ?: new StudentProfile(['user_id' => $student->id]);
            $profileUpdates = array_filter($request->only([
                'father_name', 'mother_name', 'category', 'subcaste',
                'is_pwbd', 'pwbd_type', 'pwd_percentage', 'family_income', 'school_language',
            ]), fn ($v) => $v !== null);

            if (! empty($profileUpdates)) {
                $profile->fill($profileUpdates);
                $profile->save();
            }

            UserStudentSyncService::syncFromUser($student);

            AuditLogService::log(
                event: 'student_updated',
                description: "Student {$student->name} ({$student->student_id}) profile updated",
                auditable: $student,
                oldValues: $oldUserValues,
                newValues: $student->only(['name', 'phone', 'whatsapp_no', 'gender', 'dob', 'status']),
                userId: $updater?->id
            );

            DB::commit();

            return $this->success([
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name' => $student->name,
                'phone' => $student->phone,
                'gender' => $student->gender,
                'status' => $student->status,
                'category' => $profile->category,
                'updated_at' => $student->updated_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('STUDENT_UPDATE_FAILED', 'Failed to update student: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete student account and protect linked academic history.
     */
    public function destroy(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);

        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student with identifier '{$identifier}' was not found.", 404);
        }

        $deleter = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $studentId = $student->student_id;
            $name = $student->name;

            // Soft-delete user and profile
            $student->studentProfile?->delete();
            $student->delete();

            AuditLogService::log(
                event: 'student_deleted',
                description: "Student {$name} ({$studentId}) was soft-deleted",
                auditable: $student,
                userId: $deleter?->id
            );

            DB::commit();

            return $this->success([
                'deleted' => true,
                'message' => "Student record '{$name}' ({$studentId}) was safely soft-deleted.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('STUDENT_DELETION_FAILED', 'Failed to delete student: '.$e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 3. Dossier Sub-Resources (Addresses, Qualifications, Documents)
    // =========================================================================

    /**
     * List student addresses.
     */
    public function addresses(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        return $this->success($student->studentAddresses);
    }

    /**
     * Create or update address for student.
     */
    public function storeAddress(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'address_type' => 'required|string|in:Permanent,Correspondence,permanent,correspondence,present,Present',
            'state' => 'required|string|max:100',
            'district' => 'required|string|max:100',
            'subdistrict' => 'nullable|string|max:100',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'post_office' => 'nullable|string|max:100',
            'police_station' => 'nullable|string|max:100',
            'pin_code' => 'required|string|max:15',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for address.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $rawType = strtolower($request->input('address_type'));
            $addressType = in_array($rawType, ['present', 'correspondence']) ? 'Correspondence' : 'Permanent';

            $address = StudentAddress::updateOrCreate(
                [
                    'user_id' => $student->id,
                    'address_type' => $addressType,
                ],
                array_merge(
                    $request->only([
                        'state', 'district', 'subdistrict', 'address_line1',
                        'address_line2', 'post_office', 'police_station', 'pin_code',
                    ]),
                    ['address_type' => $addressType]
                )
            );

            AuditLogService::log(
                event: 'student_address_saved',
                description: "Updated {$address->address_type} address for student {$student->name}",
                auditable: $address,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($address, null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('ADDRESS_SAVE_FAILED', 'Failed to save address: '.$e->getMessage(), 500);
        }
    }

    /**
     * Delete an address record.
     */
    public function destroyAddress(Request $request, string $identifier, int $addressId): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        $address = StudentAddress::where('user_id', $student->id)->where('id', $addressId)->first();
        if (! $address) {
            return $this->error('ADDRESS_NOT_FOUND', 'Address record not found.', 404);
        }

        $address->delete();

        return $this->success(['deleted' => true, 'message' => 'Address deleted successfully.']);
    }

    /**
     * List student educational qualifications.
     */
    public function educations(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        return $this->success($student->studentEducations()->orderBy('passing_year')->get());
    }

    /**
     * Add academic qualification record.
     */
    public function storeEducation(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'level' => 'required|string|max:100',
            'board_university' => 'nullable|string|max:200',
            'institute' => 'nullable|string|max:200',
            'stream_subject' => 'nullable|string|max:200',
            'passing_year' => 'nullable|integer|min:1970|max:'.(date('Y') + 5),
            'total_marks' => 'nullable|integer|min:0',
            'marks_obtained' => 'nullable|integer|min:0',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'cgpa' => 'nullable|numeric|min:0|max:10',
            'division' => 'nullable|string|max:50',
            'is_highest' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for education.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            if ($request->boolean('is_highest')) {
                StudentEducation::where('user_id', $student->id)->update(['is_highest' => false]);
            }

            $education = StudentEducation::create([
                'user_id' => $student->id,
                'level' => $request->input('level'),
                'board_university' => $request->input('board_university'),
                'institute' => $request->input('institute'),
                'stream_subject' => $request->input('stream_subject'),
                'passing_year' => $request->input('passing_year'),
                'total_marks' => $request->input('total_marks'),
                'marks_obtained' => $request->input('marks_obtained'),
                'percentage' => $request->input('percentage'),
                'cgpa' => $request->input('cgpa'),
                'division' => $request->input('division'),
                'is_highest' => $request->boolean('is_highest'),
            ]);

            AuditLogService::log(
                event: 'student_education_added',
                description: "Added education level {$education->level} for student {$student->name}",
                auditable: $education,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($education, null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('EDUCATION_SAVE_FAILED', 'Failed to save qualification: '.$e->getMessage(), 500);
        }
    }

    /**
     * Delete an academic qualification.
     */
    public function destroyEducation(Request $request, string $identifier, int $educationId): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        $education = StudentEducation::where('user_id', $student->id)->where('id', $educationId)->first();
        if (! $education) {
            return $this->error('EDUCATION_NOT_FOUND', 'Education record not found.', 404);
        }

        $education->delete();

        return $this->success(['deleted' => true, 'message' => 'Qualification removed.']);
    }

    /**
     * List uploaded verification documents.
     */
    public function documents(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        return $this->success($student->studentDocuments()->where('is_active', true)->get());
    }

    /**
     * Upload or attach verification document.
     */
    public function storeDocument(Request $request, string $identifier): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:100',
            'document_type' => 'nullable|string|max:100',
            'file_name' => 'nullable|string|max:255',
            'file_path' => 'nullable|string|max:500',
            'file' => 'nullable|file|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for document.', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $filePath = $request->input('file_path');
            $fileName = $request->input('file_name');
            $fileHash = null;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $filePath = $file->store("documents/students/{$student->id}", 'public');
                $fileName = $file->getClientOriginalName();
                $fileHash = hash_file('sha256', $file->getRealPath());
            }

            $doc = StudentDocument::create([
                'user_id' => $student->id,
                'type' => $request->input('type'),
                'document_type' => $request->input('document_type', $request->input('type')),
                'file_path' => $filePath ?? '',
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'is_active' => true,
                'verified' => false,
                'uploaded_at' => now(),
            ]);

            AuditLogService::log(
                event: 'student_document_uploaded',
                description: "Document {$doc->type} uploaded for student {$student->name}",
                auditable: $doc,
                userId: $actor?->id
            );

            DB::commit();

            return $this->success($doc, null, 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DOCUMENT_UPLOAD_FAILED', 'Failed to store document: '.$e->getMessage(), 500);
        }
    }

    /**
     * Administrative verification of candidate uploaded document.
     */
    public function verifyDocument(Request $request, string $identifier, int $documentId): JsonResponse
    {
        $student = $this->findStudent($identifier);
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$identifier}' not found.", 404);
        }

        $doc = StudentDocument::where('user_id', $student->id)->where('id', $documentId)->first();
        if (! $doc) {
            return $this->error('DOCUMENT_NOT_FOUND', 'Document record not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|string|in:verify,reject',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation error.', 422, $validator->errors()->toArray());
        }

        $verifier = $this->resolveUser($request);
        $isVerify = $request->input('action') === 'verify';

        $doc->update([
            'verified' => $isVerify,
            'verified_by' => $verifier?->id,
            'verified_at' => now(),
        ]);

        AuditLogService::log(
            event: $isVerify ? 'document_verified' : 'document_rejected',
            description: ($isVerify ? 'Verified' : 'Rejected')." document {$doc->type} for {$student->name}",
            auditable: $doc,
            userId: $verifier?->id
        );

        return $this->success([
            'id' => $doc->id,
            'type' => $doc->type,
            'verified' => $doc->verified,
            'verified_at' => $doc->verified_at?->toIso8601String(),
        ]);
    }

    // =========================================================================
    // 4. Academic Structure Lookups (Batches, Courses, Sections)
    // =========================================================================

    /**
     * Query batches with active enrollments count and academic offerings.
     */
    public function batches(Request $request): JsonResponse
    {
        $query = Batch::query();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($year = $request->input('academic_year')) {
            $query->where('academic_year', $year);
        }

        $batches = $query->with(['courses:id,name,code,category,fee'])
            ->withCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('academic_year')
            ->paginate($request->integer('per_page', 20));

        return $this->success($batches->items(), [
            'current_page' => $batches->currentPage(),
            'last_page' => $batches->lastPage(),
            'total' => $batches->total(),
        ]);
    }

    /**
     * Create new academic batch.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'code' => 'required|string|max:50|unique:batches,code',
            'academic_year' => 'required|string|max:20',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|string|in:upcoming,active,completed,archived',
            'description' => 'nullable|string',
            'max_students' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for batch creation.', 422, $validator->errors()->toArray());
        }

        $creator = $this->resolveUser($request);

        $batch = Batch::create(array_merge($request->only([
            'name', 'code', 'academic_year', 'start_date', 'end_date', 'status', 'description', 'max_students',
        ]), ['created_by' => $creator?->id]));

        AuditLogService::log('batch_created', "Batch {$batch->name} created", $batch, userId: $creator?->id);

        return $this->success($batch, null, 201);
    }

    /**
     * Query courses with fees and admission policies.
     */
    public function courses(Request $request): JsonResponse
    {
        $query = Course::query();

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $courses = $query->withCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return $this->success($courses->items(), [
            'current_page' => $courses->currentPage(),
            'last_page' => $courses->lastPage(),
            'total' => $courses->total(),
        ]);
    }

    /**
     * Create new course offering.
     */
    public function storeCourse(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'code' => 'required|string|max:50|unique:courses,code',
            'category' => 'required|string|max:50',
            'fee' => 'nullable|numeric|min:0',
            'duration' => 'nullable|string|max:100',
            'has_sections' => 'nullable|boolean',
            'identifier_code' => 'nullable|string|max:10',
            'admit_once_only' => 'nullable|boolean',
            'max_attempts' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:active,inactive,archived',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for course creation.', 422, $validator->errors()->toArray());
        }

        $creator = $this->resolveUser($request);

        $course = Course::create(array_merge($request->only([
            'name', 'code', 'category', 'fee', 'duration', 'has_sections',
            'identifier_code', 'admit_once_only', 'max_attempts', 'status', 'description',
        ]), ['created_by' => $creator?->id]));

        AuditLogService::log('course_created', "Course {$course->name} created", $course, userId: $creator?->id);

        return $this->success($course, null, 201);
    }

    /**
     * Query sections filtered by batch and course.
     */
    public function sections(Request $request): JsonResponse
    {
        $query = Section::query();

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', $batchId);
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', $courseId);
        }

        $sections = $query->with(['batch:id,name', 'course:id,name,code'])
            ->withCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'capacity' => $s->capacity,
                'active_students_count' => $s->active_students_count,
                'vacant_seats' => max(0, $s->capacity - $s->active_students_count),
                'room_number' => $s->room_number,
                'timing' => $s->timing,
                'status' => $s->status,
                'batch' => $s->batch?->name,
                'course' => $s->course?->name,
            ]);

        return $this->success($sections);
    }

    /**
     * Create new classroom section.
     */
    public function storeSection(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'batch_id' => 'required|exists:batches,id',
            'course_id' => 'required|exists:courses,id',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:10',
            'capacity' => 'nullable|integer|min:1',
            'room_number' => 'nullable|string|max:50',
            'timing' => 'nullable|string|max:150',
            'status' => 'nullable|string|in:active,full,inactive',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for section creation.', 422, $validator->errors()->toArray());
        }

        $creator = $this->resolveUser($request);

        $section = Section::create(array_merge($request->only([
            'batch_id', 'course_id', 'name', 'code', 'capacity', 'room_number', 'timing', 'status',
        ]), ['created_by' => $creator?->id]));

        AuditLogService::log('section_created', "Section {$section->name} created", $section, userId: $creator?->id);

        return $this->success($section, null, 201);
    }

    // =========================================================================
    // 5. Student Profile Correction Requests Workflow
    // =========================================================================

    /**
     * Query pending or reviewed profile correction requests.
     */
    public function profileRequests(Request $request): JsonResponse
    {
        $query = ProfileUpdateRequest::with(['user:id,name,student_id,email,phone', 'reviewer:id,name']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($studentId = $request->input('student_id')) {
            $query->whereHas('user', fn ($q) => $q->where('student_id', $studentId)->orWhere('id', $studentId));
        }

        $requests = $query->latest()->paginate($request->integer('per_page', 20));

        return $this->success($requests->items(), [
            'current_page' => $requests->currentPage(),
            'last_page' => $requests->lastPage(),
            'total' => $requests->total(),
        ]);
    }

    /**
     * Submit new profile correction request.
     */
    public function storeProfileRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'nullable|string',
            'section' => 'required|string|in:basic,address,education,documents',
            'payload' => 'required|array',
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = null;
        if ($studentId = $request->input('student_id')) {
            $user = $this->findStudent($studentId);
        } else {
            $user = $this->resolveUser($request);
        }

        if (! $user) {
            return $this->error('USER_NOT_FOUND', 'Candidate context could not be resolved.', 404);
        }

        $req = ProfileUpdateRequest::create([
            'user_id' => $user->id,
            'section' => $request->input('section'),
            'payload' => $request->input('payload'),
            'reason' => $request->input('reason'),
            'status' => 'pending',
        ]);

        AuditLogService::log(
            event: 'profile_request_submitted',
            description: "Profile correction request submitted by {$user->name}",
            auditable: $req,
            userId: $user->id
        );

        return $this->success($req, null, 201);
    }

    /**
     * Review profile correction request (approve and atomic merge or reject).
     */
    public function reviewProfileRequest(Request $request, int $id): JsonResponse
    {
        $req = ProfileUpdateRequest::with('user')->find($id);

        if (! $req) {
            return $this->error('REQUEST_NOT_FOUND', 'Profile correction request not found.', 404);
        }

        if ($req->status !== 'pending') {
            return $this->error('INVALID_STATUS', "Request is already {$req->status}.", 400);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|string|in:approve,reject',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $reviewer = $this->resolveUser($request);
        $action = $request->input('action');

        DB::beginTransaction();
        try {
            if ($action === 'approve') {
                $user = $req->user;
                $payload = $req->payload ?: [];

                // Atomic Merge into candidate records based on section
                if ($req->section === 'basic') {
                    $userFields = array_intersect_key($payload, array_flip(['name', 'phone', 'whatsapp_no', 'gender', 'dob']));
                    foreach ($userFields as $k => $v) {
                        if (is_string($v) && trim($v) === '') {
                            $userFields[$k] = null;
                        }
                    }
                    if (! empty($userFields)) {
                        $user->update($userFields);
                    }

                    $profile = $user->studentProfile ?: new StudentProfile(['user_id' => $user->id]);
                    $profileFields = array_intersect_key($payload, array_flip([
                        'father_name', 'mother_name', 'category', 'subcaste', 'is_pwbd', 'pwbd_type', 'family_income',
                    ]));
                    foreach ($profileFields as $k => $v) {
                        if (is_string($v) && trim($v) === '') {
                            $profileFields[$k] = null;
                        }
                    }
                    if (! empty($profileFields)) {
                        $profile->fill($profileFields);
                        $profile->save();
                    }
                } elseif ($req->section === 'address') {
                    $type = $payload['address_type'] ?? 'Permanent';
                    StudentAddress::updateOrCreate(
                        ['user_id' => $user->id, 'address_type' => $type],
                        array_intersect_key($payload, array_flip([
                            'state', 'district', 'subdistrict', 'address_line1', 'address_line2', 'pin_code', 'post_office', 'police_station',
                        ]))
                    );
                } elseif ($req->section === 'education') {
                    if (isset($payload['level'])) {
                        StudentEducation::create(array_merge(
                            ['user_id' => $user->id],
                            array_intersect_key($payload, array_flip([
                                'level', 'board_university', 'institute', 'stream_subject', 'passing_year', 'percentage', 'cgpa', 'division',
                            ]))
                        ));
                    }
                }

                UserStudentSyncService::syncFromUser($user);

                $req->update([
                    'status' => 'approved',
                    'admin_notes' => $request->input('admin_notes', 'Approved by administrator'),
                    'reviewed_by' => $reviewer?->id,
                    'reviewed_at' => now(),
                ]);

                AuditLogService::log(
                    event: 'profile_request_approved',
                    description: "Approved profile correction request #{$req->request_no} for {$user->name}",
                    auditable: $req,
                    userId: $reviewer?->id
                );
            } else {
                $req->update([
                    'status' => 'rejected',
                    'admin_notes' => $request->input('admin_notes', 'Rejected by administrator'),
                    'reviewed_by' => $reviewer?->id,
                    'reviewed_at' => now(),
                ]);

                AuditLogService::log(
                    event: 'profile_request_rejected',
                    description: "Rejected profile correction request #{$req->request_no}",
                    auditable: $req,
                    userId: $reviewer?->id
                );
            }

            DB::commit();

            return $this->success([
                'id' => $req->id,
                'request_no' => $req->request_no,
                'status' => $req->status,
                'reviewed_at' => $req->reviewed_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('REVIEW_FAILED', 'Failed to process request: '.$e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 6. Student Self-Service Endpoints
    // =========================================================================

    /**
     * Authenticated student self-service: retrieve own profile dossier.
     */
    public function myProfile(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required for personalized profile.', 401);
        }

        return $this->show($request, (string) $user->id);
    }

    /**
     * Authenticated student self-service: retrieve active and past course enrollments.
     */
    public function myEnrollments(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $enrollments = Enrollment::where('user_id', $user->id)
            ->with(['course:id,name,code,duration,fee', 'batch:id,name,code,academic_year', 'section:id,name,code,room_number,timing'])
            ->orderBy('enrolled_at', 'desc')
            ->get();

        return $this->success($enrollments);
    }

    /**
     * Authenticated student self-service: submit profile correction request.
     */
    public function submitCorrectionRequest(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $request->merge(['student_id' => (string) $user->id]);

        return $this->storeProfileRequest($request);
    }

    // =========================================================================
    // 7. Bulk Data Exchange (Import & Export)
    // =========================================================================

    /**
     * Bulk import students from JSON array or uploaded CSV/Excel spreadsheet.
     */
    public function importStudents(Request $request): JsonResponse
    {
        $creator = $this->resolveUser($request);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $tempPath = $file->getRealPath();
            $result = StudentImportExportService::importStudents($tempPath);

            AuditLogService::log('students_bulk_imported', "Imported {$result['success']} students from file", userId: $creator?->id);

            return $this->success($result);
        }

        $validator = Validator::make($request->all(), [
            'students' => 'required|array|min:1',
            'students.*.name' => 'required|string|max:150',
            'students.*.email' => 'required|email',
            'students.*.phone' => 'required|string',
            'students.*.gender' => 'nullable|string',
            'students.*.category' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed for students array.', 422, $validator->errors()->toArray());
        }

        $records = $request->input('students');
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $idx => $data) {
            DB::beginTransaction();
            try {
                if (User::where('email', $data['email'])->exists()) {
                    $errors[] = "Row #{$idx}: Email '{$data['email']}' already exists.";
                    $failed++;
                    DB::rollBack();

                    continue;
                }

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'gender' => ucfirst(strtolower($data['gender'] ?? 'Male')),
                    'password' => Hash::make(Str::random(16)),
                    'status' => 'active',
                ]);
                $user->assignRole('Student');

                $studentId = StudentIdGeneratorService::generateForStudent($user);
                $user->updateQuietly(['student_id' => $studentId]);

                StudentProfile::create([
                    'user_id' => $user->id,
                    'gender' => $user->gender,
                    'category' => $data['category'] ?? 'UR',
                ]);

                UserStudentSyncService::syncFromUser($user);
                DB::commit();
                $success++;
            } catch (Throwable $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "Row #{$idx}: {$e->getMessage()}";
            }
        }

        AuditLogService::log('students_bulk_imported', "Bulk imported {$success} students via JSON", userId: $creator?->id);

        return $this->success([
            'imported' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /**
     * Export students dataset as JSON array with filters.
     */
    public function exportStudents(Request $request): JsonResponse
    {
        $query = User::role('Student')->with([
            'studentProfile:id,user_id,category,father_name,mother_name,is_pwbd',
            'activeEnrollment' => fn ($q) => $q->with(['course:id,name,code', 'batch:id,name,code', 'section:id,name,code']),
        ]);

        if ($courseId = $request->input('course_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $courseId));
        }

        if ($batchId = $request->input('batch_id')) {
            $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', $batchId));
        }

        $students = $query->orderBy('name')->limit(1000)->get()->map(fn ($s) => [
            'student_id' => $s->student_id,
            'urn' => $s->urn,
            'name' => $s->name,
            'email' => $s->email,
            'phone' => $s->phone,
            'gender' => $s->gender,
            'category' => $s->studentProfile?->category ?? 'UR',
            'father_name' => $s->studentProfile?->father_name,
            'course' => $s->activeEnrollment?->course?->name,
            'batch' => $s->activeEnrollment?->batch?->name,
            'section' => $s->activeEnrollment?->section?->name,
            'roll_no' => $s->activeEnrollment?->roll_no,
            'fee_status' => $s->activeEnrollment?->fee_status,
            'status' => $s->status,
        ]);

        return $this->success($students, ['count' => $students->count()]);
    }
}
