<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use App\Services\Test\StudentTestService;
use App\Services\Test\TestImportExportService;
use App\Services\Test\TestReportService;
use App\Services\Test\TestScoreCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentTestApiController extends Controller
{
    public function __construct(
        protected StudentTestService $testService,
        protected TestScoreCalculatorService $calculatorService,
        protected TestReportService $reportService,
        protected TestImportExportService $importExportService
    ) {}

    /**
     * Executive test management dashboard overview & real-time analytics.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $totalTests = StudentTest::count();
        $completedTests = StudentTest::where('test_date', '<', now()->toDateString())
            ->orWhere('status', 'completed')
            ->count();
        $upcomingTests = StudentTest::where('test_date', '>=', now()->toDateString())->count();

        $resultsQuery = StudentTestResult::query();
        $totalEvaluations = $resultsQuery->count();
        $presentCount = (clone $resultsQuery)->where('attendance_status', StudentTestResult::STATUS_PRESENT)->count();
        $absentCount = (clone $resultsQuery)->where('attendance_status', StudentTestResult::STATUS_ABSENT)->count();
        $attendanceRate = $totalEvaluations > 0 ? round(($presentCount / $totalEvaluations) * 100, 1) : 0;

        $avgScore = round((float) (clone $resultsQuery)->where('attendance_status', StudentTestResult::STATUS_PRESENT)->avg('percentage'), 2);
        $passCount = (clone $resultsQuery)->where('attendance_status', StudentTestResult::STATUS_PRESENT)->where('is_passed', true)->count();
        $passRate = $presentCount > 0 ? round(($passCount / $presentCount) * 100, 1) : 0;

        // Stage & type distributions
        $byStage = StudentTest::select('test_stage', DB::raw('count(*) as count'))
            ->groupBy('test_stage')
            ->pluck('count', 'test_stage');

        $byType = StudentTest::select('test_type', DB::raw('count(*) as count'))
            ->groupBy('test_type')
            ->pluck('count', 'test_type');

        // Recent 5 examinations
        $recentTests = StudentTest::with(['batch:id,name,code', 'course:id,name,code'])
            ->orderByDesc('test_date')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'uuid' => $t->uuid,
                'code' => $t->code,
                'title' => $t->title,
                'stage' => $t->test_stage,
                'type' => $t->test_type,
                'total_marks' => $t->total_marks,
                'test_date' => $t->test_date->format('Y-m-d'),
                'course' => $t->course?->name,
                'batch' => $t->batch?->name,
            ]);

        // Top 5 overall aggregate achievers
        $topAchievers = StudentTestResult::with(['user.studentProfile'])
            ->where('attendance_status', StudentTestResult::STATUS_PRESENT)
            ->select('user_id', DB::raw('AVG(percentage) as avg_pct'), DB::raw('SUM(marks_obtained) as tot_marks'), DB::raw('COUNT(*) as tests_count'))
            ->groupBy('user_id')
            ->having('tests_count', '>=', 1)
            ->orderByDesc('avg_pct')
            ->limit(5)
            ->get()
            ->map(function ($row, $idx) {
                $user = User::with('studentProfile')->find($row->user_id);
                $enrollment = $user?->activeEnrollment ?? $user?->enrollments()->latest()->first();

                return [
                    'rank' => $idx + 1,
                    'student_id' => $user?->id,
                    'student_name' => $user?->name,
                    'student_avatar' => $user?->avatarUrl(),
                    'roll_number' => $enrollment?->roll_no ?: $user?->student_id,
                    'total_marks' => round((float) $row->tot_marks, 2),
                    'average_percentage' => round((float) $row->avg_pct, 2),
                    'tests_count' => $row->tests_count,
                ];
            });

        return response()->json([
            'success' => true,
            'summary' => [
                'total_tests' => $totalTests,
                'completed_tests' => $completedTests,
                'upcoming_tests' => $upcomingTests,
                'total_evaluations' => $totalEvaluations,
                'attended_count' => $presentCount,
                'absent_count' => $absentCount,
                'overall_attendance_rate' => $attendanceRate,
                'average_score_percentage' => $avgScore,
                'overall_pass_rate' => $passRate,
            ],
            'distribution' => [
                'by_stage' => $byStage,
                'by_type' => $byType,
            ],
            'recent_tests' => $recentTests,
            'top_achievers' => $topAchievers,
        ]);
    }

    /**
     * List mock tests / examinations with dynamic filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = StudentTest::with(['batch:id,name,code', 'course:id,name,code', 'section:id,name,code', 'subjects'])
            ->when($request->batch_id, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($request->course_id, fn ($q, $v) => $q->where('course_id', $v))
            ->when($request->section_id, fn ($q, $v) => $q->where('section_id', $v))
            ->when($request->stage, fn ($q, $v) => $q->where('test_stage', $v))
            ->when($request->type, fn ($q, $v) => $q->where('test_type', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->search, function ($q, $s) {
                $q->where(function ($sub) use ($s) {
                    $sub->where('title', 'like', "%{$s}%")
                        ->orWhere('code', 'like', "%{$s}%");
                });
            })
            ->orderByDesc('test_date');

        $tests = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $tests->items(),
            'meta' => [
                'current_page' => $tests->currentPage(),
                'last_page' => $tests->lastPage(),
                'total' => $tests->total(),
            ],
        ]);
    }

    /**
     * Show test details and summary metrics.
     */
    public function show(string $identifier): JsonResponse
    {
        $test = StudentTest::where('uuid', $identifier)
            ->orWhere('code', $identifier)
            ->orWhere('id', $identifier)
            ->with(['batch', 'course', 'section', 'subjects'])
            ->firstOrFail();

        $stats = $this->reportService->getTestPerformanceStats($test);

        return response()->json([
            'success' => true,
            'data' => $test,
            'statistics' => [
                'total_enrolled' => $stats['total_enrolled'],
                'attended_count' => $stats['attended_count'],
                'absent_count' => $stats['absent_count'],
                'attendance_rate' => $stats['attendance_rate'],
                'avg_marks' => $stats['avg_marks'],
                'highest_marks' => $stats['highest_marks'],
                'lowest_marks' => $stats['lowest_marks'],
                'pass_rate' => $stats['pass_rate'],
                'top_scorer' => $stats['top_scorer'] ? [
                    'id' => $stats['top_scorer']->id,
                    'name' => $stats['top_scorer']->name,
                    'marks' => $stats['top_score'],
                    'percentage' => $stats['top_percentage'],
                ] : null,
            ],
        ]);
    }

    /**
     * Create a new mock test.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'code' => 'nullable|string|max:50|unique:student_tests,code',
            'batch_id' => 'required|exists:batches,id',
            'course_id' => 'required|exists:courses,id',
            'section_id' => 'nullable|exists:sections,id',
            'test_stage' => ['required', Rule::in(['prelims', 'mains', 'interview_prep'])],
            'test_type' => ['required', Rule::in(['mcq', 'descriptive', 'essay', 'answer_writing'])],
            'test_for' => ['required', Rule::in(['class_test', 'mock_test', 'live_test', 'full_length_test', 'sectional_test'])],
            'test_number' => 'nullable|integer|min:1',
            'total_marks' => 'required|numeric|min:1',
            'pass_marks' => 'nullable|numeric|min:0',
            'total_questions' => 'nullable|integer|min:0',
            'mode' => ['nullable', Rule::in(['offline', 'online', 'hybrid'])],
            'test_date' => 'required|date',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:10',
            'venue_room' => 'nullable|string|max:100',
            'instructions' => 'nullable|string',
            'negative_marking_ratio' => 'nullable|numeric|min:0|max:1',
            'marks_per_question' => 'nullable|numeric|min:0.5',
            'notify_students' => 'nullable|boolean',
            'notification_channels' => 'nullable|array',
            'subjects' => 'nullable|array',
            'subjects.*.subject_name' => 'required_with:subjects|string|max:150',
            'subjects.*.subject_code' => 'nullable|string|max:50',
            'subjects.*.questions_count' => 'nullable|integer|min:1',
            'subjects.*.max_marks' => 'nullable|numeric|min:0',
            'subjects.*.marks_per_question' => 'nullable|numeric',
            'subjects.*.negative_marking' => 'nullable|numeric',
        ]);

        $subjects = $validated['subjects'] ?? null;
        unset($validated['subjects']);

        $test = $this->testService->createTest(
            $validated,
            $subjects,
            $request->file('question_paper'),
            $request->file('model_answer')
        );

        return response()->json([
            'success' => true,
            'message' => 'Examination successfully scheduled and registered.',
            'data' => $test->load('subjects'),
        ], 201);
    }

    /**
     * Get candidate results & merit ranking for a test.
     */
    public function results(Request $request, string $identifier): JsonResponse
    {
        $test = StudentTest::where('uuid', $identifier)
            ->orWhere('code', $identifier)
            ->orWhere('id', $identifier)
            ->firstOrFail();

        $query = $test->results()
            ->with(['user.studentProfile', 'section:id,name,code'])
            ->when($request->attendance_status, fn ($q, $v) => $q->where('attendance_status', $v))
            ->when($request->search, function ($q, $s) {
                $q->where(function ($sub) use ($s) {
                    $sub->where('roll_number', 'like', "%{$s}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
                });
            })
            ->orderByRaw('CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END, `rank` ASC, marks_obtained DESC');

        $results = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'test' => [
                'id' => $test->id,
                'code' => $test->code,
                'title' => $test->title,
                'total_marks' => $test->total_marks,
                'total_questions' => $test->total_questions,
                'test_type' => $test->test_type,
            ],
            'data' => $results->items(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    /**
     * Record scores for students on a test.
     */
    public function recordScores(Request $request, string $identifier): JsonResponse
    {
        $test = StudentTest::where('uuid', $identifier)
            ->orWhere('code', $identifier)
            ->orWhere('id', $identifier)
            ->firstOrFail();

        $validated = $request->validate([
            'scores' => 'required|array|min:1',
            'scores.*.user_id' => 'required|exists:users,id',
            'scores.*.attendance_status' => ['required', Rule::in(['present', 'absent', 'exempted', 'disqualified'])],
            'scores.*.right_answers' => 'nullable|integer|min:0',
            'scores.*.wrong_answers' => 'nullable|integer|min:0',
            'scores.*.blank_answers' => 'nullable|integer|min:0',
            'scores.*.marks_obtained' => 'nullable|numeric',
            'scores.*.remarks' => 'nullable|string|max:500',
        ]);

        $updated = 0;
        foreach ($validated['scores'] as $scoreItem) {
            $this->calculatorService->recordStudentScore($test, $scoreItem['user_id'], $scoreItem);
            $updated++;
        }

        return response()->json([
            'success' => true,
            'message' => "Scores recorded and merit ranking recalculated for {$updated} students.",
        ]);
    }

    /**
     * Retrieve Top Rankers / Leaderboard for a specific test.
     */
    public function leaderboard(Request $request, string $identifier): JsonResponse
    {
        $test = StudentTest::where('uuid', $identifier)
            ->orWhere('code', $identifier)
            ->orWhere('id', $identifier)
            ->with(['batch', 'course'])
            ->firstOrFail();

        $defaultLimit = (int) Setting::get('test_leaderboard_limit', 10);
        $limit = max(1, min(100, $request->integer('limit', $defaultLimit)));

        $rankers = $test->rankers($limit)->map(function (StudentTestResult $r) {
            return [
                'rank' => $r->rank,
                'student_id' => $r->user_id,
                'student_name' => $r->user?->name,
                'student_avatar' => $r->user?->avatarUrl(),
                'roll_number' => $r->roll_number,
                'section' => $r->section?->name ?? 'Default',
                'marks_obtained' => $r->marks_obtained,
                'total_marks' => $r->total_marks,
                'percentage' => $r->percentage,
                'percentile' => $r->percentile,
                'right_answers' => $r->right_answers,
                'wrong_answers' => $r->wrong_answers,
                'is_passed' => $r->is_passed,
            ];
        });

        return response()->json([
            'success' => true,
            'test' => [
                'id' => $test->id,
                'uuid' => $test->uuid,
                'code' => $test->code,
                'title' => $test->title,
                'course' => $test->course?->name,
                'batch' => $test->batch?->name,
                'total_marks' => $test->total_marks,
                'test_date' => $test->test_date->format('Y-m-d'),
            ],
            'limit' => $limit,
            'leaderboard' => $rankers,
        ]);
    }

    /**
     * Retrieve global aggregate Hall of Fame / Top Rankers across tests.
     */
    public function rankers(Request $request): JsonResponse
    {
        $defaultLimit = (int) Setting::get('test_leaderboard_limit', 10);
        $limit = max(1, min(100, $request->integer('limit', $defaultLimit)));

        $query = StudentTestResult::with(['user.studentProfile'])
            ->where('attendance_status', StudentTestResult::STATUS_PRESENT)
            ->when($request->course_id, function ($q, $v) {
                $q->whereHas('test', fn ($tq) => $tq->where('course_id', $v));
            })
            ->when($request->batch_id, function ($q, $v) {
                $q->whereHas('test', fn ($tq) => $tq->where('batch_id', $v));
            })
            ->select('user_id', DB::raw('AVG(percentage) as avg_pct'), DB::raw('SUM(marks_obtained) as tot_marks'), DB::raw('COUNT(*) as tests_count'))
            ->groupBy('user_id')
            ->having('tests_count', '>=', 1)
            ->orderByDesc('avg_pct')
            ->limit($limit);

        $rankers = $query->get()->map(function ($row, $idx) {
            $user = User::with('studentProfile')->find($row->user_id);
            $enrollment = $user?->activeEnrollment ?? $user?->enrollments()->latest()->first();

            return [
                'rank' => $idx + 1,
                'student_id' => $user?->id,
                'student_name' => $user?->name,
                'student_avatar' => $user?->avatarUrl(),
                'roll_number' => $enrollment?->roll_no ?: $user?->student_id,
                'section' => $enrollment?->section?->name ?? 'Default',
                'total_marks' => round((float) $row->tot_marks, 2),
                'average_percentage' => round((float) $row->avg_pct, 2),
                'tests_count' => $row->tests_count,
            ];
        });

        return response()->json([
            'success' => true,
            'limit' => $limit,
            'total_featured' => $rankers->count(),
            'rankers' => $rankers,
        ]);
    }

    /**
     * Section-wise comparative performance breakdown for a given test.
     */
    public function sectionComparison(string $identifier): JsonResponse
    {
        $test = StudentTest::where('uuid', $identifier)
            ->orWhere('code', $identifier)
            ->orWhere('id', $identifier)
            ->with(['course', 'batch'])
            ->firstOrFail();

        $results = $test->results()->with('section')->get();
        $grouped = $results->groupBy(fn ($r) => $r->section_id ?: 0);

        $sectionsComparison = $grouped->map(function ($items, $secId) {
            $section = $items->first()->section;
            $totalEnrolled = $items->count();
            $present = $items->where('attendance_status', StudentTestResult::STATUS_PRESENT);
            $attendedCount = $present->count();
            $absentCount = $items->where('attendance_status', StudentTestResult::STATUS_ABSENT)->count();

            $avgMarks = $attendedCount > 0 ? round((float) $present->avg('marks_obtained'), 2) : 0.0;
            $avgPct = $attendedCount > 0 ? round((float) $present->avg('percentage'), 2) : 0.0;
            $highestMarks = $attendedCount > 0 ? (float) $present->max('marks_obtained') : 0.0;
            $passedCount = $present->where('is_passed', true)->count();
            $passRate = $attendedCount > 0 ? round(($passedCount / $attendedCount) * 100, 1) : 0;

            return [
                'section_id' => $secId > 0 ? $secId : null,
                'section_name' => $section?->name ?? 'Default / Apex',
                'section_code' => $section?->code ?? 'N/A',
                'enrolled_count' => $totalEnrolled,
                'attended_count' => $attendedCount,
                'absent_count' => $absentCount,
                'attendance_rate' => $totalEnrolled > 0 ? round(($attendedCount / $totalEnrolled) * 100, 1) : 0,
                'avg_marks' => $avgMarks,
                'avg_percentage' => $avgPct,
                'highest_marks' => $highestMarks,
                'pass_rate' => $passRate,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'test' => [
                'id' => $test->id,
                'code' => $test->code,
                'title' => $test->title,
                'total_marks' => $test->total_marks,
            ],
            'sections' => $sectionsComparison,
        ]);
    }

    /**
     * Multipart file upload API to bulk import candidate scores.
     */
    public function importScores(Request $request, string $identifier): JsonResponse
    {
        $test = StudentTest::where('uuid', $identifier)
            ->orWhere('code', $identifier)
            ->orWhere('id', $identifier)
            ->firstOrFail();

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        $report = $this->importExportService->importScores($test, $request->file('file'));

        return response()->json([
            'success' => $report['success'],
            'message' => $report['message'],
            'imported_count' => ($report['imported'] ?? 0) + ($report['updated'] ?? 0),
            'new_count' => $report['imported'] ?? 0,
            'updated_count' => $report['updated'] ?? 0,
            'errors' => $report['errors'] ?? [],
            'test_code' => $test->code,
        ], $report['success'] ? 200 : 422);
    }

    /**
     * Retrieve full candidate marksheet data & signed download link.
     */
    public function marksheet(string $resultId): JsonResponse
    {
        $result = StudentTestResult::where('id', $resultId)
            ->with(['test.course', 'test.batch', 'test.section', 'user.studentProfile', 'section', 'evaluator'])
            ->firstOrFail();

        $test = $result->test;
        $user = $result->user;

        return response()->json([
            'success' => true,
            'result' => [
                'id' => $result->id,
                'attendance_status' => $result->attendance_status,
                'roll_number' => $result->roll_number,
                'section' => $result->section?->name ?? 'Default',
                'right_answers' => $result->right_answers,
                'wrong_answers' => $result->wrong_answers,
                'blank_answers' => $result->blank_answers,
                'marks_obtained' => $result->marks_obtained,
                'total_marks' => $result->total_marks,
                'percentage' => $result->percentage,
                'rank' => $result->rank,
                'percentile' => $result->percentile,
                'is_top_ten' => $result->is_top_ten,
                'is_passed' => $result->is_passed,
                'remarks' => $result->remarks,
                'feedback' => $result->feedback,
                'evaluated_by' => $result->evaluator?->name ?? ($result->evaluated_by ? "User #{$result->evaluated_by}" : null),
                'evaluated_at' => $result->evaluated_at?->toIso8601String(),
                'download_pdf_url' => route('tests.download-marksheet', $result),
            ],
            'candidate' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'avatar_url' => $user?->avatarUrl(),
            ],
            'test' => [
                'id' => $test?->id,
                'code' => $test?->code,
                'title' => $test?->title,
                'stage' => $test?->test_stage,
                'type' => $test?->test_type,
                'total_marks' => $test?->total_marks,
                'pass_marks' => $test?->pass_marks,
                'total_questions' => $test?->total_questions,
                'marks_per_question' => $test?->marks_per_question,
                'negative_marking_ratio' => $test?->negative_marking_ratio,
                'test_date' => $test?->test_date?->format('Y-m-d'),
                'course' => $test?->course?->name,
                'batch' => $test?->batch?->name,
            ],
        ]);
    }

    /**
     * Student's personal test dashboard feed.
     */
    public function myTests(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $report = $this->reportService->getStudentOverallReport($user->id);

        return response()->json([
            'success' => true,
            'summary' => [
                'total_tests_conducted' => $report['total_tests_conducted'],
                'attended_count' => $report['attended_count'],
                'absent_count' => $report['absent_count'],
                'participation_rate' => $report['participation_rate'],
                'avg_percentage' => $report['avg_percentage'],
                'highest_marks' => $report['highest_marks'],
                'best_rank' => $report['best_rank'],
                'top_ten_count' => $report['top_ten_count'],
            ],
            'tests' => $report['results']->map(function (StudentTestResult $r) {
                return [
                    'id' => $r->id,
                    'test_id' => $r->student_test_id,
                    'test_code' => $r->test?->code,
                    'test_title' => $r->test?->title,
                    'test_stage' => $r->test?->test_stage,
                    'test_type' => $r->test?->test_type,
                    'test_date' => $r->test?->test_date?->format('Y-m-d'),
                    'attendance_status' => $r->attendance_status,
                    'marks_obtained' => $r->marks_obtained,
                    'total_marks' => $r->total_marks,
                    'percentage' => $r->percentage,
                    'rank' => $r->rank,
                    'percentile' => $r->percentile,
                    'is_top_ten' => $r->is_top_ten,
                    'can_download_solutions' => $r->test?->canDownloadSolutions(),
                    'has_question_paper' => ! empty($r->test?->question_paper_path),
                    'has_model_answer' => ! empty($r->test?->model_answer_path),
                ];
            })->values(),
            'timeline' => $report['timeline'],
        ]);
    }

    /**
     * Attendance & participation statistics (100% attendees, 0% absentees, max/least attendees).
     */
    public function participationStats(Request $request): JsonResponse
    {
        $batchId = $request->integer('batch_id') ?: null;
        $courseId = $request->integer('course_id') ?: null;
        $sectionId = $request->integer('section_id') ?: null;

        $stats = $this->reportService->getParticipationAnalytics($batchId, $courseId, $sectionId);

        return response()->json([
            'success' => true,
            'data' => [
                'total_tests_conducted' => $stats['total_tests_conducted'],
                'total_students' => $stats['total_students'],
                'overall_attendance_rate' => $stats['overall_attendance_rate'],
                'participated_all_count' => $stats['participated_all_count'],
                'participated_none_count' => $stats['participated_none_count'],
                'participated_all' => $stats['participated_all']->map(fn ($item) => [
                    'id' => $item['user']->id,
                    'name' => $item['user']->name,
                    'roll_no' => $item['enrollment']->roll_no,
                    'attended' => $item['attended_tests'],
                    'avg_marks' => $item['avg_marks'],
                ]),
                'participated_none' => $stats['participated_none']->map(fn ($item) => [
                    'id' => $item['user']->id,
                    'name' => $item['user']->name,
                    'roll_no' => $item['enrollment']->roll_no,
                    'email' => $item['user']->email,
                    'phone' => $item['user']->phone,
                ]),
            ],
        ]);
    }
}
