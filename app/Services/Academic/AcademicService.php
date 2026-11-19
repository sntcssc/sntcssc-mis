<?php

namespace App\Services\Academic;

use App\Models\AttendanceHoliday;
use App\Models\Batch;
use App\Models\BatchCourse;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Models\WorkingCalendar;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcademicService
{
    /**
     * Retrieve global executive telemetry and capacity metrics for Academics.
     *
     * @return array<string, mixed>
     */
    public function getDashboardMetrics(): array
    {
        $totalCourses = Course::count();
        $activeCourses = Course::where('status', 'active')->count();
        $inactiveCourses = Course::where('status', 'inactive')->count();
        $archivedCourses = Course::where('status', 'archived')->count();

        $totalBatches = Batch::count();
        $activeBatches = Batch::where('status', 'active')->count();
        $upcomingBatches = Batch::where('status', 'upcoming')->count();
        $completedBatches = Batch::where('status', 'completed')->count();

        $totalSections = Section::count();
        $activeSections = Section::where('status', 'active')->count();

        $totalEnrolled = Enrollment::where('status', 'active')->count();

        // Calculate total capacity from active courses or batches
        $totalBatchCapacity = (int) Batch::where('status', 'active')->sum('max_students');
        $totalCourseCapacity = (int) Course::where('status', 'active')->whereNotNull('capacity')->sum('capacity');
        $effectiveCapacity = max($totalBatchCapacity, $totalCourseCapacity, 1);

        $occupancyRate = round(($totalEnrolled / $effectiveCapacity) * 100, 1);

        // Course category distribution
        $categories = Course::select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        // Academic years distribution
        $academicYears = Batch::select('academic_year', DB::raw('count(*) as count'))
            ->groupBy('academic_year')
            ->orderByDesc('academic_year')
            ->limit(5)
            ->pluck('count', 'academic_year')
            ->toArray();

        // Recent 5 batches
        $recentBatches = Batch::with(['courses:id,name,code'])
            ->latest('id')
            ->limit(5)
            ->get(['id', 'name', 'code', 'academic_year', 'status', 'start_date', 'end_date', 'max_students'])
            ->map(function (Batch $batch) {
                return [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'code' => $batch->code,
                    'academic_year' => $batch->academic_year,
                    'status' => $batch->status,
                    'start_date' => $batch->start_date?->format('Y-m-d'),
                    'end_date' => $batch->end_date?->format('Y-m-d'),
                    'max_students' => $batch->max_students,
                    'courses' => $batch->courses->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'code' => $c->code,
                    ]),
                ];
            });

        return [
            'overview' => [
                'total_courses' => $totalCourses,
                'active_courses' => $activeCourses,
                'inactive_courses' => $inactiveCourses,
                'archived_courses' => $archivedCourses,
                'total_batches' => $totalBatches,
                'active_batches' => $activeBatches,
                'upcoming_batches' => $upcomingBatches,
                'completed_batches' => $completedBatches,
                'total_sections' => $totalSections,
                'active_sections' => $activeSections,
                'total_active_students' => $totalEnrolled,
                'total_capacity' => $effectiveCapacity,
                'occupancy_percentage' => min(100.0, $occupancyRate),
            ],
            'categories' => $categories,
            'academic_years' => $academicYears,
            'recent_batches' => $recentBatches,
        ];
    }

    /**
     * Retrieve deep analytics for academic capacity, enrollment distribution and intake trends.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        // 1. Course capacity vs active enrolled count
        $coursesBreakdown = Course::withCount([
            'enrollments as active_enrolled_count' => function (Builder $query) {
                $query->where('status', 'active');
            },
            'batches',
            'sections',
        ])
            ->where('status', '!=', 'archived')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'category', 'capacity', 'fee', 'fee_type', 'status'])
            ->map(function (Course $course) {
                $capacity = $course->capacity ?: 100;
                $enrolled = (int) $course->active_enrolled_count;
                $utilization = $capacity > 0 ? round(($enrolled / $capacity) * 100, 1) : 0;

                return [
                    'course_id' => $course->id,
                    'name' => $course->name,
                    'code' => $course->code,
                    'category' => $course->category,
                    'status' => $course->status,
                    'fee' => (float) $course->fee,
                    'fee_type' => $course->fee_type,
                    'capacity' => $course->capacity,
                    'active_enrolled' => $enrolled,
                    'batches_count' => (int) $course->batches_count,
                    'sections_count' => (int) $course->sections_count,
                    'utilization_rate_pct' => min(100.0, $utilization),
                ];
            });

        // 2. Batch capacity vs active enrolled count
        $batchesBreakdown = Batch::withCount([
            'enrollments as active_enrolled_count' => function (Builder $query) {
                $query->where('status', 'active');
            },
            'sections',
        ])
            ->latest('academic_year')
            ->limit(10)
            ->get(['id', 'name', 'code', 'academic_year', 'status', 'max_students', 'start_date', 'end_date'])
            ->map(function (Batch $batch) {
                $capacity = $batch->max_students ?: 100;
                $enrolled = (int) $batch->active_enrolled_count;
                $utilization = $capacity > 0 ? round(($enrolled / $capacity) * 100, 1) : 0;

                return [
                    'batch_id' => $batch->id,
                    'name' => $batch->name,
                    'code' => $batch->code,
                    'academic_year' => $batch->academic_year,
                    'status' => $batch->status,
                    'capacity' => $batch->max_students,
                    'active_enrolled' => $enrolled,
                    'sections_count' => (int) $batch->sections_count,
                    'utilization_rate_pct' => min(100.0, $utilization),
                ];
            });

        // 3. Admissions Intake Trends by Academic Year (DB agnostic)
        $admissionsByYear = Enrollment::select('enrolled_at')
            ->whereNotNull('enrolled_at')
            ->get()
            ->groupBy(function (Enrollment $enrollment) {
                return $enrollment->enrolled_at ? $enrollment->enrolled_at->format('Y') : 'Unknown';
            })
            ->map(fn ($group) => $group->count())
            ->sortKeysDesc()
            ->take(5)
            ->toArray();

        // 4. Section Capacity vs Enrolled Distribution
        $sectionsBreakdown = Section::withCount([
            'enrollments as active_enrolled_count' => function (Builder $query) {
                $query->where('status', 'active');
            },
        ])
            ->with(['batch:id,name,code', 'course:id,name,code'])
            ->limit(15)
            ->get(['id', 'batch_id', 'course_id', 'name', 'code', 'capacity', 'room_number', 'timing', 'status'])
            ->map(function (Section $section) {
                $capacity = $section->capacity ?: 45;
                $enrolled = (int) $section->active_enrolled_count;
                $vacancy = max(0, $capacity - $enrolled);

                return [
                    'section_id' => $section->id,
                    'name' => $section->name,
                    'code' => $section->code,
                    'batch' => $section->batch ? ['id' => $section->batch->id, 'name' => $section->batch->name] : null,
                    'course' => $section->course ? ['id' => $section->course->id, 'name' => $section->course->name] : null,
                    'room_number' => $section->room_number,
                    'timing' => $section->timing,
                    'capacity' => $capacity,
                    'active_enrolled' => $enrolled,
                    'vacancy' => $vacancy,
                    'status' => $section->status,
                ];
            });

        return [
            'courses_breakdown' => $coursesBreakdown,
            'batches_breakdown' => $batchesBreakdown,
            'admissions_by_year' => $admissionsByYear,
            'sections_breakdown' => $sectionsBreakdown,
        ];
    }

    /**
     * Create a new course offering.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCourse(array $data, ?User $user = null): Course
    {
        $userId = $user?->id;

        return DB::transaction(function () use ($data, $userId) {
            $course = Course::create([
                'name' => trim((string) $data['name']),
                'code' => strtoupper(trim((string) $data['code'])),
                'category' => $data['category'] ?? 'Classroom',
                'description' => ! empty($data['description']) ? trim((string) $data['description']) : null,
                'duration' => ! empty($data['duration']) ? trim((string) $data['duration']) : null,
                'fee' => (float) ($data['fee'] ?? 0.0),
                'fee_type' => $data['fee_type'] ?? 'monthly',
                'fee_reserved' => isset($data['fee_reserved']) && $data['fee_reserved'] !== '' ? (float) $data['fee_reserved'] : null,
                'fee_structure' => $data['fee_structure'] ?? null,
                'capacity' => ! empty($data['capacity']) ? (int) $data['capacity'] : null,
                'has_sections' => (bool) ($data['has_sections'] ?? false),
                'identifier_code' => ! empty($data['identifier_code']) ? trim((string) $data['identifier_code']) : null,
                'admit_once_only' => (bool) ($data['admit_once_only'] ?? false),
                'max_attempts' => ! empty($data['max_attempts']) ? (int) $data['max_attempts'] : null,
                'status' => $data['status'] ?? 'active',
                'rules' => $data['rules'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'academic.course.created',
                description: "Created Course '{$course->name}' [Code: {$course->code}]",
                auditable: $course,
                newValues: $course->toArray(),
                userId: $userId
            );

            return $course;
        });
    }

    /**
     * Update an existing course.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCourse(Course $course, array $data, ?User $user = null): Course
    {
        $userId = $user?->id;
        $oldValues = $course->toArray();

        return DB::transaction(function () use ($course, $data, $oldValues, $userId) {
            $course->update([
                'name' => isset($data['name']) ? trim((string) $data['name']) : $course->name,
                'code' => isset($data['code']) ? strtoupper(trim((string) $data['code'])) : $course->code,
                'category' => $data['category'] ?? $course->category,
                'description' => array_key_exists('description', $data) ? (! empty($data['description']) ? trim((string) $data['description']) : null) : $course->description,
                'duration' => array_key_exists('duration', $data) ? (! empty($data['duration']) ? trim((string) $data['duration']) : null) : $course->duration,
                'fee' => isset($data['fee']) ? (float) $data['fee'] : $course->fee,
                'fee_type' => $data['fee_type'] ?? $course->fee_type,
                'fee_reserved' => array_key_exists('fee_reserved', $data) ? ($data['fee_reserved'] !== '' && $data['fee_reserved'] !== null ? (float) $data['fee_reserved'] : null) : $course->fee_reserved,
                'fee_structure' => array_key_exists('fee_structure', $data) ? $data['fee_structure'] : $course->fee_structure,
                'capacity' => array_key_exists('capacity', $data) ? (! empty($data['capacity']) ? (int) $data['capacity'] : null) : $course->capacity,
                'has_sections' => isset($data['has_sections']) ? (bool) $data['has_sections'] : $course->has_sections,
                'identifier_code' => array_key_exists('identifier_code', $data) ? (! empty($data['identifier_code']) ? trim((string) $data['identifier_code']) : null) : $course->identifier_code,
                'admit_once_only' => isset($data['admit_once_only']) ? (bool) $data['admit_once_only'] : $course->admit_once_only,
                'max_attempts' => array_key_exists('max_attempts', $data) ? (! empty($data['max_attempts']) ? (int) $data['max_attempts'] : null) : $course->max_attempts,
                'status' => $data['status'] ?? $course->status,
                'rules' => array_key_exists('rules', $data) ? $data['rules'] : $course->rules,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'academic.course.updated',
                description: "Updated Course '{$course->name}' [Code: {$course->code}]",
                auditable: $course,
                oldValues: $oldValues,
                newValues: $course->fresh()->toArray(),
                userId: $userId
            );

            return $course;
        });
    }

    /**
     * Soft delete a course with dependency guard.
     *
     * @throws ValidationException
     */
    public function deleteCourse(Course $course, ?User $user = null): bool
    {
        $activeEnrollmentsCount = $course->enrollments()->where('status', 'active')->count();

        if ($activeEnrollmentsCount > 0) {
            throw ValidationException::withMessages([
                'course' => ["Cannot delete course '{$course->name}' because it currently has {$activeEnrollmentsCount} active student enrollment(s)."],
            ]);
        }

        $userId = $user?->id;
        $oldValues = $course->toArray();

        return DB::transaction(function () use ($course, $oldValues, $userId) {
            $course->deleted_by = $userId;
            $course->saveQuietly();
            $course->delete();

            AuditLogService::log(
                event: 'academic.course.deleted',
                description: "Soft-deleted Course '{$course->name}' [ID: {$course->id}]",
                auditable: $course,
                oldValues: $oldValues,
                userId: $userId
            );

            return true;
        });
    }

    /**
     * Create an academic batch.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBatch(array $data, ?User $user = null): Batch
    {
        $userId = $user?->id;

        return DB::transaction(function () use ($data, $userId) {
            $batch = Batch::create([
                'name' => trim((string) $data['name']),
                'code' => strtoupper(trim((string) $data['code'])),
                'academic_year' => trim((string) $data['academic_year']),
                'start_date' => ! empty($data['start_date']) ? Carbon::parse($data['start_date']) : null,
                'end_date' => ! empty($data['end_date']) ? Carbon::parse($data['end_date']) : null,
                'status' => $data['status'] ?? 'upcoming',
                'description' => ! empty($data['description']) ? trim((string) $data['description']) : null,
                'max_students' => ! empty($data['max_students']) ? (int) $data['max_students'] : null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // Synchronize course offerings if provided
            if (! empty($data['courses']) && is_array($data['courses'])) {
                foreach ($data['courses'] as $courseInput) {
                    $courseId = is_array($courseInput) ? ($courseInput['course_id'] ?? null) : $courseInput;
                    if ($courseId) {
                        $capacity = is_array($courseInput) ? ($courseInput['capacity'] ?? null) : null;
                        $fee = is_array($courseInput) ? ($courseInput['fee'] ?? null) : null;
                        $startDate = is_array($courseInput) && ! empty($courseInput['start_date']) ? Carbon::parse($courseInput['start_date']) : $batch->start_date;
                        $endDate = is_array($courseInput) && ! empty($courseInput['end_date']) ? Carbon::parse($courseInput['end_date']) : $batch->end_date;
                        $status = is_array($courseInput) ? ($courseInput['status'] ?? 'active') : 'active';
                        $remarks = is_array($courseInput) ? ($courseInput['remarks'] ?? null) : null;

                        BatchCourse::updateOrCreate(
                            ['batch_id' => $batch->id, 'course_id' => (int) $courseId],
                            [
                                'capacity' => $capacity ? (int) $capacity : null,
                                'fee' => $fee !== null ? (float) $fee : null,
                                'start_date' => $startDate,
                                'end_date' => $endDate,
                                'status' => $status,
                                'remarks' => $remarks,
                            ]
                        );
                    }
                }
            }

            AuditLogService::log(
                event: 'academic.batch.created',
                description: "Created Academic Batch '{$batch->name}' [Code: {$batch->code}, Year: {$batch->academic_year}]",
                auditable: $batch,
                newValues: $batch->toArray(),
                userId: $userId
            );

            return $batch;
        });
    }

    /**
     * Update an existing batch.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateBatch(Batch $batch, array $data, ?User $user = null): Batch
    {
        $userId = $user?->id;
        $oldValues = $batch->toArray();

        return DB::transaction(function () use ($batch, $data, $oldValues, $userId) {
            $batch->update([
                'name' => isset($data['name']) ? trim((string) $data['name']) : $batch->name,
                'code' => isset($data['code']) ? strtoupper(trim((string) $data['code'])) : $batch->code,
                'academic_year' => isset($data['academic_year']) ? trim((string) $data['academic_year']) : $batch->academic_year,
                'start_date' => array_key_exists('start_date', $data) ? (! empty($data['start_date']) ? Carbon::parse($data['start_date']) : null) : $batch->start_date,
                'end_date' => array_key_exists('end_date', $data) ? (! empty($data['end_date']) ? Carbon::parse($data['end_date']) : null) : $batch->end_date,
                'status' => $data['status'] ?? $batch->status,
                'description' => array_key_exists('description', $data) ? (! empty($data['description']) ? trim((string) $data['description']) : null) : $batch->description,
                'max_students' => array_key_exists('max_students', $data) ? (! empty($data['max_students']) ? (int) $data['max_students'] : null) : $batch->max_students,
                'updated_by' => $userId,
            ]);

            // Sync courses if provided
            if (isset($data['courses']) && is_array($data['courses'])) {
                $processedCourseIds = [];
                foreach ($data['courses'] as $courseInput) {
                    $courseId = is_array($courseInput) ? ($courseInput['course_id'] ?? null) : $courseInput;
                    if ($courseId) {
                        $processedCourseIds[] = (int) $courseId;
                        BatchCourse::updateOrCreate(
                            ['batch_id' => $batch->id, 'course_id' => (int) $courseId],
                            [
                                'capacity' => is_array($courseInput) && ! empty($courseInput['capacity']) ? (int) $courseInput['capacity'] : null,
                                'fee' => is_array($courseInput) && isset($courseInput['fee']) ? (float) $courseInput['fee'] : null,
                                'start_date' => is_array($courseInput) && ! empty($courseInput['start_date']) ? Carbon::parse($courseInput['start_date']) : $batch->start_date,
                                'end_date' => is_array($courseInput) && ! empty($courseInput['end_date']) ? Carbon::parse($courseInput['end_date']) : $batch->end_date,
                                'status' => is_array($courseInput) ? ($courseInput['status'] ?? 'active') : 'active',
                                'remarks' => is_array($courseInput) ? ($courseInput['remarks'] ?? null) : null,
                            ]
                        );
                    }
                }
            }

            AuditLogService::log(
                event: 'academic.batch.updated',
                description: "Updated Academic Batch '{$batch->name}' [Code: {$batch->code}]",
                auditable: $batch,
                oldValues: $oldValues,
                newValues: $batch->fresh()->toArray(),
                userId: $userId
            );

            return $batch;
        });
    }

    /**
     * Soft delete a batch with dependency guard.
     *
     * @throws ValidationException
     */
    public function deleteBatch(Batch $batch, ?User $user = null): bool
    {
        $activeEnrollmentsCount = $batch->enrollments()->where('status', 'active')->count();

        if ($activeEnrollmentsCount > 0) {
            throw ValidationException::withMessages([
                'batch' => ["Cannot delete batch '{$batch->name}' because it currently has {$activeEnrollmentsCount} active student enrollment(s)."],
            ]);
        }

        $userId = $user?->id;
        $oldValues = $batch->toArray();

        return DB::transaction(function () use ($batch, $oldValues, $userId) {
            $batch->deleted_by = $userId;
            $batch->saveQuietly();
            $batch->delete();

            AuditLogService::log(
                event: 'academic.batch.deleted',
                description: "Soft-deleted Academic Batch '{$batch->name}' [ID: {$batch->id}]",
                auditable: $batch,
                oldValues: $oldValues,
                userId: $userId
            );

            return true;
        });
    }

    /**
     * Attach or update course offering in batch.
     *
     * @param  array<string, mixed>  $offeringData
     */
    public function attachCourseToBatch(Batch $batch, int $courseId, array $offeringData = [], ?User $user = null): BatchCourse
    {
        $userId = $user?->id;
        $course = Course::findOrFail($courseId);

        return DB::transaction(function () use ($batch, $course, $offeringData, $userId) {
            $offering = BatchCourse::updateOrCreate(
                ['batch_id' => $batch->id, 'course_id' => $course->id],
                [
                    'capacity' => ! empty($offeringData['capacity']) ? (int) $offeringData['capacity'] : $course->capacity,
                    'fee' => isset($offeringData['fee']) ? (float) $offeringData['fee'] : $course->fee,
                    'start_date' => ! empty($offeringData['start_date']) ? Carbon::parse($offeringData['start_date']) : $batch->start_date,
                    'end_date' => ! empty($offeringData['end_date']) ? Carbon::parse($offeringData['end_date']) : $batch->end_date,
                    'status' => $offeringData['status'] ?? 'active',
                    'remarks' => ! empty($offeringData['remarks']) ? trim((string) $offeringData['remarks']) : null,
                ]
            );

            AuditLogService::log(
                event: 'academic.batch_course.linked',
                description: "Linked Course '{$course->name}' to Batch '{$batch->name}'",
                auditable: $offering,
                newValues: $offering->toArray(),
                userId: $userId
            );

            return $offering;
        });
    }

    /**
     * Detach course offering from batch with active enrollment guard.
     *
     * @throws ValidationException
     */
    public function detachCourseFromBatch(Batch $batch, int $courseId, ?User $user = null): bool
    {
        $activeEnrollments = Enrollment::where('batch_id', $batch->id)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->count();

        if ($activeEnrollments > 0) {
            throw ValidationException::withMessages([
                'course' => ["Cannot detach course from batch because {$activeEnrollments} active student enrollment(s) exist."],
            ]);
        }

        $userId = $user?->id;

        return DB::transaction(function () use ($batch, $courseId, $userId) {
            $offering = BatchCourse::where('batch_id', $batch->id)->where('course_id', $courseId)->first();
            if ($offering) {
                $offering->delete();
                AuditLogService::log(
                    event: 'academic.batch_course.unlinked',
                    description: "Detached Course ID {$courseId} from Batch '{$batch->name}'",
                    auditable: $batch,
                    userId: $userId
                );
            }

            return true;
        });
    }

    /**
     * Create classroom section.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSection(array $data, ?User $user = null): Section
    {
        $userId = $user?->id;

        return DB::transaction(function () use ($data, $userId) {
            $batchCourse = null;
            if (! empty($data['batch_id']) && ! empty($data['course_id'])) {
                $batchCourse = BatchCourse::where('batch_id', $data['batch_id'])
                    ->where('course_id', $data['course_id'])
                    ->first();
            }

            $section = Section::create([
                'batch_id' => (int) $data['batch_id'],
                'course_id' => (int) $data['course_id'],
                'batch_course_id' => $batchCourse?->id,
                'name' => trim((string) $data['name']),
                'code' => trim((string) $data['code']),
                'capacity' => (int) ($data['capacity'] ?? 45),
                'room_number' => ! empty($data['room_number']) ? trim((string) $data['room_number']) : null,
                'timing' => ! empty($data['timing']) ? trim((string) $data['timing']) : null,
                'status' => $data['status'] ?? 'active',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'academic.section.created',
                description: "Created Section '{$section->name}' [Code: {$section->code}] for Batch ID {$section->batch_id}",
                auditable: $section,
                newValues: $section->toArray(),
                userId: $userId
            );

            return $section;
        });
    }

    /**
     * Update classroom section.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSection(Section $section, array $data, ?User $user = null): Section
    {
        $userId = $user?->id;
        $oldValues = $section->toArray();

        return DB::transaction(function () use ($section, $data, $oldValues, $userId) {
            $section->update([
                'name' => isset($data['name']) ? trim((string) $data['name']) : $section->name,
                'code' => isset($data['code']) ? trim((string) $data['code']) : $section->code,
                'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : $section->capacity,
                'room_number' => array_key_exists('room_number', $data) ? (! empty($data['room_number']) ? trim((string) $data['room_number']) : null) : $section->room_number,
                'timing' => array_key_exists('timing', $data) ? (! empty($data['timing']) ? trim((string) $data['timing']) : null) : $section->timing,
                'status' => $data['status'] ?? $section->status,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'academic.section.updated',
                description: "Updated Section '{$section->name}' [ID: {$section->id}]",
                auditable: $section,
                oldValues: $oldValues,
                newValues: $section->fresh()->toArray(),
                userId: $userId
            );

            return $section;
        });
    }

    /**
     * Soft delete classroom section with active enrollment guard.
     *
     * @throws ValidationException
     */
    public function deleteSection(Section $section, ?User $user = null): bool
    {
        $activeEnrollments = $section->enrollments()->where('status', 'active')->count();

        if ($activeEnrollments > 0) {
            throw ValidationException::withMessages([
                'section' => ["Cannot delete section '{$section->name}' because {$activeEnrollments} active student(s) are assigned to it."],
            ]);
        }

        $userId = $user?->id;
        $oldValues = $section->toArray();

        return DB::transaction(function () use ($section, $oldValues, $userId) {
            $section->deleted_by = $userId;
            $section->saveQuietly();
            $section->delete();

            AuditLogService::log(
                event: 'academic.section.deleted',
                description: "Soft-deleted Section '{$section->name}' [ID: {$section->id}]",
                auditable: $section,
                oldValues: $oldValues,
                userId: $userId
            );

            return true;
        });
    }

    /**
     * List distinct academic years configured in system with batch counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAcademicYears(): array
    {
        return Batch::select('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->get()
            ->map(function ($batch) {
                $year = $batch->academic_year;
                $batchesCount = Batch::where('academic_year', $year)->count();
                $activeBatchesCount = Batch::where('academic_year', $year)->where('status', 'active')->count();
                $activeStudentsCount = Enrollment::whereHas('batch', fn ($b) => $b->where('academic_year', $year))
                    ->where('status', 'active')
                    ->count();

                return [
                    'academic_year' => $year,
                    'total_batches' => $batchesCount,
                    'active_batches' => $activeBatchesCount,
                    'active_students' => $activeStudentsCount,
                    'is_current' => $activeBatchesCount > 0,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Retrieve academic calendar milestones, institutional holidays, and working schedules.
     *
     * @return array<string, mixed>
     */
    public function getAcademicCalendar(?string $academicYear = null, ?int $courseId = null, ?int $batchId = null, ?int $month = null): array
    {
        // 1. Attendance Holidays & Academic Events
        $holidayQuery = AttendanceHoliday::query();

        if ($courseId) {
            $holidayQuery->where(function ($q) use ($courseId) {
                $q->whereNull('course_id')->orWhere('course_id', $courseId);
            });
        }

        if ($batchId) {
            $holidayQuery->where(function ($q) use ($batchId) {
                $q->whereNull('batch_id')->orWhere('batch_id', $batchId);
            });
        }

        if ($month) {
            $holidayQuery->whereMonth('date', $month);
        }

        $holidays = $holidayQuery->orderBy('date')->limit(100)->get()->map(fn (AttendanceHoliday $h) => [
            'id' => $h->id,
            'title' => $h->title,
            'date' => $h->date?->format('Y-m-d'),
            'to_date' => $h->to_date?->format('Y-m-d'),
            'type' => $h->type,
            'description' => $h->description,
            'is_restricted' => $h->is_restricted,
            'course_id' => $h->course_id,
            'batch_id' => $h->batch_id,
        ]);

        // 2. Working Calendars (Class Timetable Policies)
        $calendarQuery = WorkingCalendar::query();
        if ($courseId) {
            $calendarQuery->where(function ($q) use ($courseId) {
                $q->whereNull('course_id')->orWhere('course_id', $courseId);
            });
        }
        if ($batchId) {
            $calendarQuery->where(function ($q) use ($batchId) {
                $q->whereNull('batch_id')->orWhere('batch_id', $batchId);
            });
        }

        $workingCalendars = $calendarQuery->get()->map(fn (WorkingCalendar $wc) => [
            'id' => $wc->id,
            'name' => $wc->name,
            'target_type' => $wc->target_type,
            'course_id' => $wc->course_id,
            'batch_id' => $wc->batch_id,
            'effective_from' => $wc->effective_from?->format('Y-m-d'),
            'effective_to' => $wc->effective_to?->format('Y-m-d'),
            'days_active' => [
                'monday' => $wc->monday_active,
                'tuesday' => $wc->tuesday_active,
                'wednesday' => $wc->wednesday_active,
                'thursday' => $wc->thursday_active,
                'friday' => $wc->friday_active,
                'saturday' => $wc->saturday_active,
                'sunday' => $wc->sunday_active,
            ],
            'expected_start_time' => $wc->expected_start_time,
            'expected_end_time' => $wc->expected_end_time,
            'is_default' => $wc->is_default,
        ]);

        // 3. Batch timeline milestones
        $batchesQuery = Batch::where('status', '!=', 'archived');
        if ($academicYear) {
            $batchesQuery->where('academic_year', $academicYear);
        }
        if ($batchId) {
            $batchesQuery->where('id', $batchId);
        }

        $milestones = $batchesQuery->get()->map(fn (Batch $b) => [
            'batch_id' => $b->id,
            'name' => $b->name,
            'code' => $b->code,
            'academic_year' => $b->academic_year,
            'status' => $b->status,
            'start_date' => $b->start_date?->format('Y-m-d'),
            'end_date' => $b->end_date?->format('Y-m-d'),
        ]);

        return [
            'holidays_and_events' => $holidays,
            'working_calendars' => $workingCalendars,
            'batch_milestones' => $milestones,
        ];
    }

    /**
     * Bulk import courses from associative array.
     *
     * @param  array<int, array<string, mixed>>  $coursesData
     * @return array{created: int, updated: int, errors: array<int, string>}
     */
    public function importCourses(array $coursesData, ?User $user = null): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($coursesData as $index => $row) {
            if (empty($row['name']) || empty($row['code'])) {
                $errors[] = "Row #{$index}: Course name and code are required.";

                continue;
            }

            try {
                $code = strtoupper(trim((string) $row['code']));
                $existing = Course::where('code', $code)->first();

                if ($existing) {
                    $this->updateCourse($existing, $row, $user);
                    $updated++;
                } else {
                    $this->createCourse($row, $user);
                    $created++;
                }
            } catch (Throwable $e) {
                $errors[] = "Row #{$index} ({$row['code']}): ".$e->getMessage();
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Bulk import batches from associative array.
     *
     * @param  array<int, array<string, mixed>>  $batchesData
     * @return array{created: int, updated: int, errors: array<int, string>}
     */
    public function importBatches(array $batchesData, ?User $user = null): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($batchesData as $index => $row) {
            if (empty($row['name']) || empty($row['code']) || empty($row['academic_year'])) {
                $errors[] = "Row #{$index}: Batch name, code, and academic_year are required.";

                continue;
            }

            try {
                $code = strtoupper(trim((string) $row['code']));
                $existing = Batch::where('code', $code)->first();

                if ($existing) {
                    $this->updateBatch($existing, $row, $user);
                    $updated++;
                } else {
                    $this->createBatch($row, $user);
                    $created++;
                }
            } catch (Throwable $e) {
                $errors[] = "Row #{$index} ({$row['code']}): ".$e->getMessage();
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }
}
