<?php

namespace App\Services\Test;

use App\Models\AuditLog;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TestScoreCalculatorService
{
    /**
     * Recompute scores, percentages, percentiles and merit ranks for all attendees of a test.
     */
    public function recalculateAndRank(StudentTest $test): void
    {
        DB::transaction(function () use ($test) {
            $results = $test->results()->get();

            // 1. Recompute each student's score & percentage
            foreach ($results as $result) {
                if ($result->attendance_status === StudentTestResult::STATUS_PRESENT) {
                    if ($result->marks_obtained === null) {
                        $result->computeScores($test);
                    } elseif ($result->total_marks > 0) {
                        $result->percentage = round(($result->marks_obtained / $result->total_marks) * 100, 2);
                    }
                    if ($test->pass_marks !== null && $test->pass_marks > 0) {
                        $result->is_passed = ($result->marks_obtained >= (float) $test->pass_marks);
                    }
                } else {
                    $result->marks_obtained = 0.00;
                    $result->percentage = 0.00;
                    $result->rank = null;
                    $result->percentile = null;
                    $result->is_top_ten = false;
                    $result->is_passed = false;
                    $result->right_answers = 0;
                    $result->wrong_answers = 0;
                    $result->blank_answers = (int) ($result->total_questions ?: $test->total_questions);
                }
                $result->saveQuietly();
            }

            // 2. Filter present attendees for ranking
            $presentResults = $results->where('attendance_status', StudentTestResult::STATUS_PRESENT)
                ->sortByDesc(function (StudentTestResult $r) {
                    // Sort by marks desc, then right_answers desc, then wrong_answers asc
                    return sprintf(
                        '%010.2f_%06d_%06d',
                        $r->marks_obtained + 10000, // offset for negative values
                        $r->right_answers,
                        999999 - $r->wrong_answers
                    );
                })
                ->values();

            $totalPresent = $presentResults->count();

            if ($totalPresent > 0) {
                $currentRank = 1;
                $previousScore = null;
                $denseIndex = 1;

                foreach ($presentResults as $index => $res) {
                    // Standard competition ranking (1, 2, 2, 4...)
                    if ($previousScore !== null && (float) $res->marks_obtained === (float) $previousScore) {
                        // same rank
                    } else {
                        $currentRank = $index + 1;
                    }
                    $previousScore = (float) $res->marks_obtained;

                    $res->rank = $currentRank;
                    $res->is_top_ten = ($currentRank <= 10);

                    // Percentile formula: ((total - index) / total) * 100 or count of scores below / total
                    $countBelow = $presentResults->filter(fn ($other) => (float) $other->marks_obtained < (float) $res->marks_obtained)->count();
                    $percentile = round(($countBelow / $totalPresent) * 100, 2);
                    $res->percentile = $percentile;

                    $res->saveQuietly();
                }
            }

            // If test has results and at least one evaluated, mark as completed
            if ($totalPresent > 0 && $test->status === StudentTest::STATUS_SCHEDULED) {
                $test->update(['status' => StudentTest::STATUS_COMPLETED]);
            }
        });
    }

    /**
     * Record or update single student test result.
     */
    public function recordStudentScore(StudentTest $test, int $userId, array $scoreData): StudentTestResult
    {
        return DB::transaction(function () use ($test, $userId, $scoreData) {
            $result = StudentTestResult::firstOrNew([
                'student_test_id' => $test->id,
                'user_id' => $userId,
            ]);

            $result->attendance_status = $scoreData['attendance_status'] ?? StudentTestResult::STATUS_PRESENT;
            $result->total_questions = (int) ($scoreData['total_questions'] ?? $test->total_questions);
            $result->total_marks = (float) ($scoreData['total_marks'] ?? $test->total_marks);

            if ($result->attendance_status === StudentTestResult::STATUS_PRESENT) {
                if ($test->isMcq()) {
                    $result->right_answers = (int) ($scoreData['right_answers'] ?? 0);
                    $result->wrong_answers = (int) ($scoreData['wrong_answers'] ?? 0);
                    $result->blank_answers = (int) ($scoreData['blank_answers'] ?? max(0, $result->total_questions - ($result->right_answers + $result->wrong_answers)));
                    $result->computeScores($test);

                    // Check manual override marks if provided
                    if (isset($scoreData['manual_override_marks']) && $scoreData['manual_override_marks'] !== '' && $scoreData['manual_override_marks'] !== null) {
                        $result->marks_obtained = round((float) $scoreData['manual_override_marks'], 2);
                        $result->percentage = $result->total_marks > 0
                            ? round(($result->marks_obtained / $result->total_marks) * 100, 2)
                            : 0.00;
                    }
                } else {
                    $result->marks_obtained = (float) ($scoreData['marks_obtained'] ?? 0.00);
                    $result->percentage = $result->total_marks > 0
                        ? round(($result->marks_obtained / $result->total_marks) * 100, 2)
                        : 0.00;
                }

                if (isset($scoreData['is_passed']) && $scoreData['is_passed'] !== null && $scoreData['is_passed'] !== '') {
                    $result->is_passed = (bool) $scoreData['is_passed'];
                } else {
                    $result->is_passed = ($test->pass_marks === null || $result->marks_obtained >= (float) $test->pass_marks);
                }

                if (! empty($scoreData['subject_scores'])) {
                    $result->subject_scores = $scoreData['subject_scores'];
                }
            } else {
                $result->marks_obtained = 0.00;
                $result->percentage = 0.00;
                $result->rank = null;
                $result->percentile = null;
                $result->is_top_ten = false;
                $result->is_passed = false;
                $result->right_answers = 0;
                $result->wrong_answers = 0;
                $result->blank_answers = (int) $result->total_questions;
                $result->positive_marks = 0.00;
                $result->negative_marks = 0.00;
            }

            if (array_key_exists('remarks', $scoreData)) {
                $result->remarks = $scoreData['remarks'] !== null && $scoreData['remarks'] !== '' ? $scoreData['remarks'] : null;
            }
            if (array_key_exists('feedback', $scoreData)) {
                $result->feedback = $scoreData['feedback'] !== null && $scoreData['feedback'] !== '' ? $scoreData['feedback'] : null;
            }
            if (array_key_exists('roll_number', $scoreData)) {
                $result->roll_number = $scoreData['roll_number'] !== null && $scoreData['roll_number'] !== '' ? $scoreData['roll_number'] : null;
            } elseif (empty($result->roll_number)) {
                $user = User::with(['activeEnrollment', 'enrollments'])->find($userId);
                $enrollment = $user?->activeEnrollment ?? $user?->enrollments()->latest()->first();
                $result->roll_number = $enrollment?->roll_no ?: $user?->student_id;
                if (! $result->section_id && $enrollment?->section_id) {
                    $result->section_id = $enrollment->section_id;
                }
            }
            if (array_key_exists('section_id', $scoreData)) {
                $result->section_id = ! empty($scoreData['section_id']) ? (int) $scoreData['section_id'] : null;
            }

            if (! empty($scoreData['evaluated_by'])) {
                $result->evaluated_by = (int) $scoreData['evaluated_by'];
            } elseif (! $result->evaluated_by) {
                $result->evaluated_by = auth()->id();
            }

            if (! empty($scoreData['evaluated_at'])) {
                try {
                    $result->evaluated_at = Carbon::parse($scoreData['evaluated_at']);
                } catch (\Throwable) {
                    $result->evaluated_at = now();
                }
            } elseif (! $result->evaluated_at) {
                $result->evaluated_at = now();
            }
            $result->save();

            // Recompute ranking across all present students
            $this->recalculateAndRank($test);

            $result->refresh();

            AuditLog::record(
                action: 'test.score_recorded',
                details: [
                    'test_id' => $test->id,
                    'user_id' => $userId,
                    'marks_obtained' => $result->marks_obtained,
                    'percentage' => $result->percentage,
                    'rank' => $result->rank,
                    'attendance' => $result->attendance_status,
                ],
                auditableType: StudentTestResult::class,
                auditableId: $result->id,
                description: "Recorded score for student #{$userId} on test {$test->code}: {$result->marks_obtained}/{$result->total_marks} (Rank #{$result->rank})"
            );

            return $result;
        });
    }
}
