<?php

namespace App\Services\Test;

use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use Illuminate\Support\Collection;

class TestReportService
{
    /**
     * Get detailed performance statistics for a specific test.
     */
    public function getTestPerformanceStats(StudentTest $test): array
    {
        $results = $test->results()->with(['user.studentProfile', 'section'])->get();
        $totalEnrolled = $results->count();
        $present = $results->where('attendance_status', StudentTestResult::STATUS_PRESENT);
        $attendedCount = $present->count();
        $absentCount = $results->where('attendance_status', StudentTestResult::STATUS_ABSENT)->count();
        $attendanceRate = $totalEnrolled > 0 ? round(($attendedCount / $totalEnrolled) * 100, 1) : 0;

        $avgMarks = $attendedCount > 0 ? round((float) $present->avg('marks_obtained'), 2) : 0.0;
        $highestMarks = $attendedCount > 0 ? (float) $present->max('marks_obtained') : 0.0;
        $lowestMarks = $attendedCount > 0 ? (float) $present->min('marks_obtained') : 0.0;

        $topScorerResult = $present->sortByDesc('marks_obtained')->first();
        $passedCount = $present->where('is_passed', true)->count();
        $passRate = $attendedCount > 0 ? round(($passedCount / $attendedCount) * 100, 1) : 0;

        // Grade bands
        $gradeA = $present->filter(fn ($r) => $r->percentage >= 75)->count();
        $gradeB = $present->filter(fn ($r) => $r->percentage >= 60 && $r->percentage < 75)->count();
        $gradeC = $present->filter(fn ($r) => $r->percentage >= 45 && $r->percentage < 60)->count();
        $gradeD = $present->filter(fn ($r) => $r->percentage < 45)->count();

        // Subject breakdown averages if subjects exist
        $subjectsStats = [];
        $subjects = $test->subjects;
        if ($subjects->isNotEmpty()) {
            foreach ($subjects as $subj) {
                $subjectsStats[] = [
                    'name' => $subj->subject_name,
                    'code' => $subj->subject_code,
                    'questions' => $subj->questions_count,
                    'max_marks' => $subj->max_marks,
                ];
            }
        }

        return [
            'test' => $test,
            'total_enrolled' => $totalEnrolled,
            'attended_count' => $attendedCount,
            'absent_count' => $absentCount,
            'attendance_rate' => $attendanceRate,
            'avg_marks' => $avgMarks,
            'highest_marks' => $highestMarks,
            'lowest_marks' => $lowestMarks,
            'top_scorer' => $topScorerResult?->user,
            'top_score' => $topScorerResult?->marks_obtained,
            'top_percentage' => $topScorerResult?->percentage,
            'passed_count' => $passedCount,
            'pass_rate' => $passRate,
            'grades' => [
                'grade_a' => $gradeA,
                'grade_b' => $gradeB,
                'grade_c' => $gradeC,
                'grade_d' => $gradeD,
            ],
            'subjects' => $subjectsStats,
        ];
    }

    /**
     * Get cross-test comprehensive performance report for a specific student.
     */
    public function getStudentOverallReport(int $userId): array
    {
        $student = User::with(['studentProfile', 'activeEnrollment.course', 'activeEnrollment.batch', 'activeEnrollment.section'])->findOrFail($userId);
        $enrollment = $student->activeEnrollment ?? $student->enrollments()->latest()->first();

        // Find all tests applicable to this student's enrollment
        $testsQuery = StudentTest::query();
        if ($enrollment) {
            $testsQuery->where('batch_id', $enrollment->batch_id)
                ->where('course_id', $enrollment->course_id)
                ->where(function ($q) use ($enrollment) {
                    $q->whereNull('section_id')
                        ->orWhere('section_id', $enrollment->section_id);
                });
        }

        $allTestsCount = $testsQuery->count();

        // All test results for this student
        $results = StudentTestResult::with(['test.course', 'test.batch', 'section'])
            ->where('user_id', $userId)
            ->whereHas('test')
            ->get()
            ->sortByDesc(fn ($r) => $r->test?->test_date);

        $attended = $results->where('attendance_status', StudentTestResult::STATUS_PRESENT);
        $attendedCount = $attended->count();
        $absentCount = $results->where('attendance_status', StudentTestResult::STATUS_ABSENT)->count();
        $participationRate = $allTestsCount > 0 ? round(($attendedCount / $allTestsCount) * 100, 1) : 0;

        $avgMarks = $attendedCount > 0 ? round((float) $attended->avg('marks_obtained'), 2) : 0.0;
        $avgPercentage = $attendedCount > 0 ? round((float) $attended->avg('percentage'), 2) : 0.0;
        $highestMarks = $attendedCount > 0 ? (float) $attended->max('marks_obtained') : 0.0;
        $bestRank = $attendedCount > 0 ? (int) $attended->whereNotNull('rank')->min('rank') : null;
        $topTenCount = $attended->where('is_top_ten', true)->count();

        // Chronological timeline for trajectory
        $timeline = $attended->sortBy(fn ($r) => $r->test?->test_date)->values()->map(function ($r) {
            return [
                'test_code' => $r->test?->code,
                'test_title' => $r->test?->title,
                'test_date' => $r->test?->test_date?->format('d M'),
                'marks_obtained' => (float) $r->marks_obtained,
                'total_marks' => (float) $r->total_marks,
                'percentage' => (float) $r->percentage,
                'rank' => $r->rank,
            ];
        })->all();

        return [
            'student' => $student,
            'enrollment' => $enrollment,
            'total_tests_conducted' => $allTestsCount,
            'attended_count' => $attendedCount,
            'absent_count' => $absentCount,
            'participation_rate' => $participationRate,
            'avg_marks' => $avgMarks,
            'avg_percentage' => $avgPercentage,
            'highest_marks' => $highestMarks,
            'best_rank' => $bestRank,
            'top_ten_count' => $topTenCount,
            'results' => $results,
            'timeline' => $timeline,
        ];
    }

    /**
     * Get attendance & participation analytics across all tests:
     * - Participated ALL tests (100% participation)
     * - Participated NONE (0% participation / Chronic absentees)
     * - Participated MAXIMUM tests
     * - Participated LEAST tests
     */
    public function getParticipationAnalytics(?int $batchId = null, ?int $courseId = null, ?int $sectionId = null): array
    {
        // 1. Get tests in scope
        $testsQuery = StudentTest::query();
        if ($batchId) {
            $testsQuery->where('batch_id', $batchId);
        }
        if ($courseId) {
            $testsQuery->where('course_id', $courseId);
        }
        if ($sectionId) {
            $testsQuery->where('section_id', $sectionId);
        }

        $totalTestsConducted = $testsQuery->count();

        // 2. Get active enrollments in scope
        $enrollmentQuery = Enrollment::where('status', 'active')->with(['user.studentProfile', 'course', 'batch', 'section']);
        if ($batchId) {
            $enrollmentQuery->where('batch_id', $batchId);
        }
        if ($courseId) {
            $enrollmentQuery->where('course_id', $courseId);
        }
        if ($sectionId) {
            $enrollmentQuery->where('section_id', $sectionId);
        }

        $enrollments = $enrollmentQuery->get();
        $totalStudents = $enrollments->count();

        // 3. Aggregate each student's attendance across the tests
        $studentAttendance = [];

        foreach ($enrollments as $enrollment) {
            $userId = $enrollment->user_id;
            if (! $userId) {
                continue;
            }

            // Count tests available for this specific student
            $studentTestsCount = StudentTest::where('batch_id', $enrollment->batch_id)
                ->where('course_id', $enrollment->course_id)
                ->where(function ($q) use ($enrollment) {
                    $q->whereNull('section_id')->orWhere('section_id', $enrollment->section_id);
                })
                ->count();

            // Count present test results
            $presentCount = StudentTestResult::where('user_id', $userId)
                ->where('attendance_status', StudentTestResult::STATUS_PRESENT)
                ->whereHas('test', function ($q) use ($enrollment) {
                    $q->where('batch_id', $enrollment->batch_id)
                        ->where('course_id', $enrollment->course_id);
                })
                ->count();

            // Absent count
            $absentCount = StudentTestResult::where('user_id', $userId)
                ->where('attendance_status', StudentTestResult::STATUS_ABSENT)
                ->whereHas('test', function ($q) use ($enrollment) {
                    $q->where('batch_id', $enrollment->batch_id)
                        ->where('course_id', $enrollment->course_id);
                })
                ->count();

            $rate = $studentTestsCount > 0 ? round(($presentCount / $studentTestsCount) * 100, 1) : 0;

            // Avg marks
            $avgMarks = (float) (StudentTestResult::where('user_id', $userId)
                ->where('attendance_status', StudentTestResult::STATUS_PRESENT)
                ->avg('marks_obtained') ?? 0);

            $studentAttendance[] = [
                'user' => $enrollment->user,
                'enrollment' => $enrollment,
                'available_tests' => $studentTestsCount,
                'attended_tests' => $presentCount,
                'absent_tests' => $absentCount,
                'participation_rate' => $rate,
                'avg_marks' => round($avgMarks, 2),
            ];
        }

        $coll = collect($studentAttendance);

        // 100% participation (attended all available tests and available > 0)
        $allTestsAttended = $coll->filter(fn ($item) => $item['available_tests'] > 0 && $item['attended_tests'] >= $item['available_tests'])
            ->sortByDesc('avg_marks')
            ->values();

        // 0% participation (attended 0 tests while available > 0)
        $zeroTestsAttended = $coll->filter(fn ($item) => $item['available_tests'] > 0 && $item['attended_tests'] === 0)
            ->sortBy('user.name')
            ->values();

        // Top participants (max tests attended)
        $maxTestsAttended = $coll->filter(fn ($item) => $item['attended_tests'] > 0)
            ->sortByDesc('attended_tests')
            ->values()
            ->take(15);

        // Least participants (fewest tests attended, > 0)
        $leastTestsAttended = $coll->filter(fn ($item) => $item['attended_tests'] > 0)
            ->sortBy('attended_tests')
            ->values()
            ->take(15);

        $overallAttendedAvg = $coll->avg('participation_rate') ?? 0;

        return [
            'total_tests_conducted' => $totalTestsConducted,
            'total_students' => $totalStudents,
            'overall_attendance_rate' => round($overallAttendedAvg, 1),
            'participated_all' => $allTestsAttended,
            'participated_all_count' => $allTestsAttended->count(),
            'participated_none' => $zeroTestsAttended,
            'participated_none_count' => $zeroTestsAttended->count(),
            'participated_max' => $maxTestsAttended,
            'participated_least' => $leastTestsAttended,
        ];
    }

    /**
     * Compare sections performance for any course.
     */
    public function getSectionComparisonStats(int $courseId, ?int $batchId = null): Collection
    {
        $sections = Section::where('course_id', $courseId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId))
            ->get();

        return $sections->map(function (Section $section) {
            $studentCount = $section->enrollments()->where('status', 'active')->count();
            $testsCount = StudentTest::where('course_id', $section->course_id)
                ->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $section->id))
                ->count();

            $results = StudentTestResult::where('section_id', $section->id)
                ->where('attendance_status', StudentTestResult::STATUS_PRESENT)
                ->get();

            $avgMarks = $results->avg('marks_obtained') ?? 0;
            $avgPercentage = $results->avg('percentage') ?? 0;
            $highestMarks = $results->max('marks_obtained') ?? 0;

            return [
                'section' => $section,
                'student_count' => $studentCount,
                'tests_count' => $testsCount,
                'avg_marks' => round((float) $avgMarks, 2),
                'avg_percentage' => round((float) $avgPercentage, 2),
                'highest_marks' => round((float) $highestMarks, 2),
            ];
        });
    }
}
