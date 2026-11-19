<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchCourse;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AdmissionRestrictionService
{
    /**
     * Validate whether a student can be admitted/enrolled in a course and batch.
     *
     * @param  User  $student  The candidate user
     * @param  Course  $course  Target course
     * @param  Batch  $batch  Target batch
     * @param  Section|null  $section  Target section (optional)
     * @param  bool  $allowOverride  If authorized admin is bypassing restrictions
     * @return array{allowed: bool, reason: ?string, warnings: array<string>}
     */
    public static function checkEligibility(
        User $student,
        Course $course,
        Batch $batch,
        ?Section $section = null,
        bool $allowOverride = false
    ): array {
        $warnings = [];

        // 1. Restriction: Course only allows one admission ever per candidate
        if ($course->admit_once_only) {
            $priorEnrollment = Enrollment::where('user_id', $student->id)
                ->where('course_id', $course->id)
                ->whereNull('deleted_at')
                ->first();

            if ($priorEnrollment) {
                $msg = __("Candidate ':name' was already admitted in :course (Enrollment #: :enr, Batch: :batch). Institute policy permits only one-time admission for this programme.", [
                    'name' => $student->name,
                    'course' => $course->name,
                    'enr' => $priorEnrollment->enrollment_no,
                    'batch' => $priorEnrollment->batch?->name ?? 'N/A',
                ]);

                if (! $allowOverride) {
                    return [
                        'allowed' => false,
                        'reason' => $msg,
                        'warnings' => [],
                    ];
                }

                $warnings[] = $msg.' (Overridden by administrator)';
            }
        }

        // 2. Restriction: Cannot enroll twice in the exact same Course within the exact same Batch
        $duplicateInBatch = Enrollment::where('user_id', $student->id)
            ->where('batch_id', $batch->id)
            ->where('course_id', $course->id)
            ->whereNull('deleted_at')
            ->first();

        if ($duplicateInBatch) {
            $msg = __('Candidate is already enrolled in :course under :batch (Status: :status, Enrollment #: :enr).', [
                'course' => $course->name,
                'batch' => $batch->name,
                'status' => ucfirst($duplicateInBatch->status),
                'enr' => $duplicateInBatch->enrollment_no,
            ]);

            if (! $allowOverride) {
                return [
                    'allowed' => false,
                    'reason' => $msg,
                    'warnings' => [],
                ];
            }

            $warnings[] = $msg.' (Overridden by administrator)';
        }

        // 3. Restriction: Max attempts limit
        if ($course->max_attempts && $course->max_attempts > 0) {
            $attemptCount = Enrollment::where('user_id', $student->id)
                ->where('course_id', $course->id)
                ->count();

            if ($attemptCount >= $course->max_attempts) {
                $msg = __('Candidate has exhausted the maximum allowed attempts (:max) for :course.', [
                    'max' => $course->max_attempts,
                    'course' => $course->name,
                ]);

                if (! $allowOverride) {
                    return [
                        'allowed' => false,
                        'reason' => $msg,
                        'warnings' => [],
                    ];
                }

                $warnings[] = $msg.' (Overridden by administrator)';
            }
        }

        // 4. Section capacity check
        if ($section) {
            if ($section->is_full) {
                $msg = __("Section ':section' is currently at full capacity (:enrolled/:capacity students).", [
                    'section' => $section->name,
                    'enrolled' => $section->enrolled_count,
                    'capacity' => $section->capacity,
                ]);

                if (! $allowOverride) {
                    return [
                        'allowed' => false,
                        'reason' => $msg,
                        'warnings' => [],
                    ];
                }

                $warnings[] = $msg.' (Overridden by administrator)';
            }
        }

        // 5. Course-specific intake capacity check under this batch
        $batchCourse = BatchCourse::where('batch_id', $batch->id)
            ->where('course_id', $course->id)
            ->first();

        // Specific batch_course capacity takes precedence, otherwise fallback to course default capacity
        $courseCapacity = $batchCourse?->capacity ?? $course->capacity;

        if ($courseCapacity !== null && $courseCapacity > 0) {
            $enrolledInCourse = Enrollment::where('batch_id', $batch->id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->count();

            if ($enrolledInCourse >= $courseCapacity) {
                $msg = __("Course ':course' has reached its maximum intake capacity of :capacity students for batch ':batch'.", [
                    'course' => $course->name,
                    'capacity' => $courseCapacity,
                    'batch' => $batch->name,
                ]);

                if (! $allowOverride) {
                    return [
                        'allowed' => false,
                        'reason' => $msg,
                        'warnings' => [],
                    ];
                }

                $warnings[] = $msg.' (Overridden by administrator)';
            }
        }

        // 6. Overall batch capacity check
        if ($batch->max_students && $batch->max_students > 0) {
            $batchEnrolled = $batch->enrollments()->where('status', 'active')->count();

            if ($batchEnrolled >= $batch->max_students) {
                $msg = __("Batch ':batch' has reached its maximum capacity of :max students.", [
                    'batch' => $batch->name,
                    'max' => $batch->max_students,
                ]);

                if (! $allowOverride) {
                    return [
                        'allowed' => false,
                        'reason' => $msg,
                        'warnings' => [],
                    ];
                }

                $warnings[] = $msg.' (Overridden by administrator)';
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * Enforce eligibility check, throwing ValidationException if blocked.
     *
     * @throws ValidationException
     */
    public static function enforceEligibility(
        User $student,
        Course $course,
        Batch $batch,
        ?Section $section = null,
        bool $allowOverride = false
    ): void {
        $check = static::checkEligibility($student, $course, $batch, $section, $allowOverride);

        if (! $check['allowed']) {
            throw ValidationException::withMessages([
                'admission' => [$check['reason']],
            ]);
        }
    }
}
