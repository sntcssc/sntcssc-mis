<?php

namespace App\Services\Alumni;

use App\Models\Alumni;
use App\Models\AlumniCourseBatch;
use App\Models\AlumniExamination;
use App\Models\AlumniPosting;
use App\Models\AlumniServiceCategory;
use App\Models\Batch;
use App\Models\Course;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class AlumniService
{
    /**
     * Create a new alumni record with multi-course/batch entries, examinations, and postings.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $courseBatches
     * @param  array<int, array<string, mixed>>  $examinations
     * @param  array<int, array<string, mixed>>  $postings
     *
     * @throws Throwable
     */
    public static function createAlumni(
        array $data,
        UploadedFile|TemporaryUploadedFile|null $photo = null,
        array $courseBatches = [],
        array $examinations = [],
        array $postings = []
    ): Alumni {
        return DB::transaction(function () use ($data, $photo, $courseBatches, $examinations, $postings) {
            if ($photo) {
                $data['photo_url'] = FileUploadService::store($photo, 'alumni', 'photo');
            }

            if (! isset($data['full_name']) || empty($data['full_name'])) {
                $data['full_name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            }

            if (! empty($data['service_type']) && empty($data['service_category_id'])) {
                $category = AlumniServiceCategory::where('code', $data['service_type'])
                    ->orWhere('name', $data['service_type'])
                    ->first();
                if ($category) {
                    $data['service_category_id'] = $category->id;
                }
            }

            /** @var Alumni $alumni */
            $alumni = Alumni::create($data);

            if (! empty($courseBatches)) {
                self::syncCourseBatches($alumni, $courseBatches);
            }

            if (! empty($examinations)) {
                self::syncExaminations($alumni, $examinations);
            }

            if (! empty($postings)) {
                self::syncPostings($alumni, $postings);
            }

            AuditLogService::log(
                event: 'alumni_created',
                description: "Created alumni record for {$alumni->full_name} ({$alumni->service_name} - {$alumni->designation}).",
                auditable: $alumni,
                newValues: $alumni->auditableAttributes()
            );

            return $alumni->load(['courseBatches', 'examinations', 'postings']);
        });
    }

    /**
     * Update an existing alumni record and course/batch, examinations, and postings relations.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $courseBatches
     * @param  array<int, array<string, mixed>>  $examinations
     * @param  array<int, array<string, mixed>>  $postings
     *
     * @throws Throwable
     */
    public static function updateAlumni(
        Alumni $alumni,
        array $data,
        UploadedFile|TemporaryUploadedFile|null $photo = null,
        array $courseBatches = [],
        array $examinations = [],
        array $postings = []
    ): Alumni {
        return DB::transaction(function () use ($alumni, $data, $photo, $courseBatches, $examinations, $postings) {
            $oldValues = $alumni->auditableAttributes();

            if ($photo) {
                $data['photo_url'] = FileUploadService::store(
                    file: $photo,
                    folder: 'alumni',
                    prefix: 'photo',
                    oldPath: $alumni->photo_url
                );
            }

            if (isset($data['first_name']) || isset($data['last_name'])) {
                $first = $data['first_name'] ?? $alumni->first_name;
                $last = $data['last_name'] ?? $alumni->last_name;
                $data['full_name'] = trim($first.' '.$last);
            }

            if (isset($data['service_type']) && empty($data['service_category_id'])) {
                $category = AlumniServiceCategory::where('code', $data['service_type'])
                    ->orWhere('name', $data['service_type'])
                    ->first();
                $data['service_category_id'] = $category?->id;
            }

            $alumni->update($data);

            if (! empty($courseBatches)) {
                self::syncCourseBatches($alumni, $courseBatches);
            }

            if (! empty($examinations)) {
                self::syncExaminations($alumni, $examinations);
            }

            if (! empty($postings)) {
                self::syncPostings($alumni, $postings);
            }

            AuditLogService::log(
                event: 'alumni_updated',
                description: "Updated alumni record for {$alumni->full_name} ({$alumni->service_name}).",
                auditable: $alumni,
                oldValues: $oldValues,
                newValues: $alumni->auditableAttributes()
            );

            return $alumni->fresh(['courseBatches', 'examinations', 'postings']);
        });
    }

    /**
     * Soft-delete an alumni record.
     *
     * @throws Throwable
     */
    public static function deleteAlumni(Alumni $alumni): bool
    {
        return DB::transaction(function () use ($alumni) {
            AuditLogService::log(
                event: 'alumni_deleted',
                description: "Soft deleted alumni record for {$alumni->full_name} ({$alumni->service_name}).",
                auditable: $alumni,
                oldValues: $alumni->auditableAttributes()
            );

            return (bool) $alumni->delete();
        });
    }

    /**
     * Restore a soft-deleted alumni record.
     *
     * @throws Throwable
     */
    public static function restoreAlumni(Alumni $alumni): bool
    {
        return DB::transaction(function () use ($alumni) {
            $result = (bool) $alumni->restore();

            AuditLogService::log(
                event: 'alumni_restored',
                description: "Restored soft deleted alumni record for {$alumni->full_name}.",
                auditable: $alumni,
                newValues: $alumni->auditableAttributes()
            );

            return $result;
        });
    }

    /**
     * Permanently delete an alumni record and remove files.
     *
     * @throws Throwable
     */
    public static function forceDeleteAlumni(Alumni $alumni): bool
    {
        return DB::transaction(function () use ($alumni) {
            if ($alumni->photo_url) {
                FileUploadService::delete($alumni->photo_url);
            }

            $alumni->courseBatches()->forceDelete();

            AuditLogService::log(
                event: 'alumni_purged',
                description: "Permanently purged alumni record for {$alumni->full_name}.",
                auditable: null,
                oldValues: $alumni->auditableAttributes()
            );

            return (bool) $alumni->forceDelete();
        });
    }

    /**
     * Convert an enrolled student user into an alumni profile.
     *
     * @param  array<string, mixed>  $serviceData
     *
     * @throws Throwable
     */
    public static function convertStudentToAlumni(User|int $user, array $serviceData = []): Alumni
    {
        return DB::transaction(function () use ($user, $serviceData) {
            $userModel = $user instanceof User ? $user : User::with(['studentProfile', 'enrollments.course', 'enrollments.batch'])->findOrFail($user);
            $profile = $userModel->studentProfile;

            // Check if alumni record already exists for this user
            $existing = Alumni::withTrashed()->where('user_id', $userModel->id)->first();
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                return self::updateAlumni($existing, $serviceData);
            }

            // Extract names
            $parts = explode(' ', trim($userModel->name), 2);
            $firstName = $parts[0] ?? 'Alumnus';
            $lastName = $parts[1] ?? '';

            // Extract enrollments into course batches
            $courseBatches = [];
            foreach ($userModel->enrollments as $enrollment) {
                $courseBatches[] = [
                    'course_id' => $enrollment->course_id,
                    'batch_id' => $enrollment->batch_id,
                    'course_name' => $enrollment->course?->name ?? 'Course '.$enrollment->course_id,
                    'batch_name' => $enrollment->batch?->name ?? 'Batch '.$enrollment->batch_id,
                    'enrollment_no' => $enrollment->enrollment_no,
                    'roll_no' => $enrollment->roll_no,
                    'start_year' => $enrollment->enrolled_at ? $enrollment->enrolled_at->year : null,
                    'completion_year' => $enrollment->completed_at ? $enrollment->completed_at->year : now()->year,
                    'status' => 'completed',
                    'remarks' => 'Imported from student enrollment record',
                ];
            }

            $alumniData = array_merge([
                'user_id' => $userModel->id,
                'student_profile_id' => $profile?->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $userModel->name,
                'email' => $userModel->email,
                'phone' => $userModel->phone,
                'photo_url' => $userModel->avatar,
                'gender' => $profile?->gender ?? $userModel->gender,
                'dob' => $profile?->dob ?? $userModel->dob,
                'passing_year' => now()->year,
                'service_type' => $serviceData['service_type'] ?? 'UPSC CSE',
                'service_name' => $serviceData['service_name'] ?? 'Civil Services',
                'designation' => $serviceData['designation'] ?? 'Officer Trainee',
                'posting_state' => $serviceData['posting_state'] ?? 'West Bengal',
                'posting_country' => 'India',
                'source' => Alumni::SOURCE_STUDENT,
                'status' => Alumni::STATUS_ACTIVE,
                'is_verified' => true,
            ], $serviceData);

            return self::createAlumni($alumniData, null, $courseBatches);
        });
    }

    /**
     * Synchronize multiple course and batch rows for an alumnus.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function syncCourseBatches(Alumni $alumni, array $rows): void
    {
        $existingIds = [];

        foreach ($rows as $row) {
            $courseId = ! empty($row['course_id']) ? (int) $row['course_id'] : null;
            $batchId = ! empty($row['batch_id']) ? (int) $row['batch_id'] : null;

            // Resolve course name
            $courseName = trim($row['course_name'] ?? '');
            if (empty($courseName) && $courseId) {
                $courseName = Course::find($courseId)?->name ?? "Course #{$courseId}";
            }

            // Resolve batch name
            $batchName = trim($row['batch_name'] ?? '');
            if (empty($batchName) && $batchId) {
                $batchName = Batch::find($batchId)?->name ?? "Batch #{$batchId}";
            }

            if (empty($courseName)) {
                $courseName = 'General Course';
            }
            if (empty($batchName)) {
                $batchName = 'General Batch';
            }

            $payload = [
                'course_id' => $courseId,
                'batch_id' => $batchId,
                'course_name' => $courseName,
                'batch_name' => $batchName,
                'enrollment_no' => $row['enrollment_no'] ?? null,
                'roll_no' => $row['roll_no'] ?? null,
                'academic_year' => $row['academic_year'] ?? null,
                'start_year' => ! empty($row['start_year']) ? (int) $row['start_year'] : null,
                'completion_year' => ! empty($row['completion_year']) ? (int) $row['completion_year'] : null,
                'status' => $row['status'] ?? 'completed',
                'remarks' => $row['remarks'] ?? null,
            ];

            if (! empty($row['id'])) {
                $cb = AlumniCourseBatch::where('alumni_id', $alumni->id)->find($row['id']);
                if ($cb) {
                    $cb->update($payload);
                    $existingIds[] = $cb->id;

                    continue;
                }
            }

            $cb = $alumni->courseBatches()->create($payload);
            $existingIds[] = $cb->id;
        }

        // Delete any rows that were removed
        $alumni->courseBatches()->whereNotIn('id', $existingIds)->delete();
    }

    /**
     * Synchronize multiple examination selections and qualification records.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function syncExaminations(Alumni $alumni, array $rows): void
    {
        $existingIds = [];
        $primaryExam = null;

        foreach ($rows as $row) {
            $examName = trim($row['examination_name'] ?? '');
            $serviceAllotted = trim($row['service_allotted'] ?? '');
            $rank = ! empty($row['all_india_rank']) ? (int) $row['all_india_rank'] : null;

            // Skip completely empty rows
            if (empty($examName) && empty($row['exam_year']) && empty($serviceAllotted) && empty($rank)) {
                continue;
            }

            $isPrimary = ! empty($row['is_primary']);
            if (empty($examName)) {
                $examName = 'Civil Services Examination';
            }

            $payload = [
                'examination_name' => $examName,
                'exam_year' => ! empty($row['exam_year']) ? (int) $row['exam_year'] : now()->year,
                'service_allotted' => $serviceAllotted ?: null,
                'all_india_rank' => $rank,
                'state_rank' => ! empty($row['state_rank']) ? (int) $row['state_rank'] : null,
                'roll_number' => trim($row['roll_number'] ?? '') ?: null,
                'optional_subject' => trim($row['optional_subject'] ?? '') ?: null,
                'score_or_marks' => trim($row['score_or_marks'] ?? '') ?: null,
                'category_rank' => trim($row['category_rank'] ?? '') ?: null,
                'is_primary' => $isPrimary,
                'remarks' => trim($row['remarks'] ?? '') ?: null,
            ];

            if (! empty($row['id'])) {
                $e = AlumniExamination::where('alumni_id', $alumni->id)->find($row['id']);
                if ($e) {
                    $e->update($payload);
                    $existingIds[] = $e->id;
                    if ($isPrimary) {
                        $primaryExam = $e;
                    }

                    continue;
                }
            }

            $e = $alumni->examinations()->create($payload);
            $existingIds[] = $e->id;
            if ($isPrimary) {
                $primaryExam = $e;
            }
        }

        // Delete removed
        $alumni->examinations()->whereNotIn('id', $existingIds)->delete();

        // If a primary exam is specified, sync parent record rank & service details
        if ($primaryExam) {
            $updates = [
                'exam_year' => $primaryExam->exam_year ?: $alumni->exam_year,
            ];
            if ($primaryExam->all_india_rank) {
                $updates['all_india_rank'] = $primaryExam->all_india_rank;
            }
            if ($primaryExam->state_rank) {
                $updates['state_rank'] = $primaryExam->state_rank;
            }
            if ($primaryExam->roll_number) {
                $updates['roll_number'] = $primaryExam->roll_number;
            }
            if (! empty($primaryExam->service_allotted)) {
                $updates['service_name'] = $primaryExam->service_allotted;
            }
            $alumni->updateQuietly($updates);
        }
    }

    /**
     * Synchronize multiple career posting history entries.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function syncPostings(Alumni $alumni, array $rows): void
    {
        $existingIds = [];
        $currentPosting = null;

        foreach ($rows as $row) {
            $designation = trim($row['designation'] ?? '');
            $dept = trim($row['department_ministry'] ?? '');
            $city = trim($row['posting_city'] ?? '');

            // Skip completely empty rows
            if (empty($designation) && empty($dept) && empty($city)) {
                continue;
            }

            $isCurrent = ! empty($row['is_current']);
            if (empty($designation)) {
                $designation = $alumni->designation ?: 'Officer';
            }

            $payload = [
                'designation' => $designation,
                'department_ministry' => $dept ?: null,
                'posting_city' => $city ?: null,
                'posting_district' => trim($row['posting_district'] ?? '') ?: null,
                'posting_state' => trim($row['posting_state'] ?? 'West Bengal') ?: null,
                'posting_country' => trim($row['posting_country'] ?? 'India') ?: 'India',
                'office_address' => trim($row['office_address'] ?? '') ?: null,
                'from_date' => ! empty($row['from_date']) ? $row['from_date'] : null,
                'to_date' => $isCurrent ? null : (! empty($row['to_date']) ? $row['to_date'] : null),
                'is_current' => $isCurrent,
                'order_number' => trim($row['order_number'] ?? '') ?: null,
                'responsibilities' => trim($row['responsibilities'] ?? '') ?: null,
                'remarks' => trim($row['remarks'] ?? '') ?: null,
            ];

            if (! empty($row['id'])) {
                $p = AlumniPosting::where('alumni_id', $alumni->id)->find($row['id']);
                if ($p) {
                    $p->update($payload);
                    $existingIds[] = $p->id;
                    if ($isCurrent) {
                        $currentPosting = $p;
                    }

                    continue;
                }
            }

            $p = $alumni->postings()->create($payload);
            $existingIds[] = $p->id;
            if ($isCurrent) {
                $currentPosting = $p;
            }
        }

        // Delete removed
        $alumni->postings()->whereNotIn('id', $existingIds)->delete();

        // If a current posting exists, synchronize latest details with parent alumni record
        if ($currentPosting) {
            $alumni->updateQuietly([
                'designation' => $currentPosting->designation ?: $alumni->designation,
                'posting_city' => $currentPosting->posting_city ?: $alumni->posting_city,
                'posting_district' => $currentPosting->posting_district ?: $alumni->posting_district,
                'posting_state' => $currentPosting->posting_state ?: $alumni->posting_state,
                'department_ministry' => $currentPosting->department_ministry ?: $alumni->department_ministry,
                'office_address' => $currentPosting->office_address ?: $alumni->office_address,
                'posting_date' => $currentPosting->from_date ?: $alumni->posting_date,
            ]);
        }
    }

    /**
     * Add a single posting record to an alumnus.
     *
     * @param  array<string, mixed>  $data
     */
    public static function addPosting(Alumni $alumni, array $data): AlumniPosting
    {
        return DB::transaction(function () use ($alumni, $data) {
            $isCurrent = ! empty($data['is_current']);
            if ($isCurrent) {
                // Untoggle other current postings
                $alumni->postings()->where('is_current', true)->update(['is_current' => false]);
            }

            $posting = $alumni->postings()->create($data);

            if ($isCurrent) {
                $alumni->updateQuietly([
                    'designation' => $posting->designation,
                    'posting_city' => $posting->posting_city,
                    'posting_district' => $posting->posting_district,
                    'posting_state' => $posting->posting_state,
                    'department_ministry' => $posting->department_ministry,
                    'office_address' => $posting->office_address,
                    'posting_date' => $posting->from_date,
                ]);
            }

            AuditLogService::log(
                event: 'alumni_posting_added',
                description: "Added posting '{$posting->designation}' in {$posting->posting_city} for {$alumni->full_name}.",
                auditable: $alumni,
                newValues: $posting->auditableAttributes()
            );

            return $posting;
        });
    }

    /**
     * Update an individual posting record.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updatePosting(AlumniPosting $posting, array $data): AlumniPosting
    {
        return DB::transaction(function () use ($posting, $data) {
            $alumni = $posting->alumni;
            $oldValues = $posting->auditableAttributes();

            if (! empty($data['is_current']) && ! $posting->is_current) {
                $alumni->postings()->where('id', '!=', $posting->id)->where('is_current', true)->update(['is_current' => false]);
            }

            $posting->update($data);

            if ($posting->is_current) {
                $alumni->updateQuietly([
                    'designation' => $posting->designation,
                    'posting_city' => $posting->posting_city,
                    'posting_district' => $posting->posting_district,
                    'posting_state' => $posting->posting_state,
                    'department_ministry' => $posting->department_ministry,
                    'office_address' => $posting->office_address,
                    'posting_date' => $posting->from_date,
                ]);
            }

            AuditLogService::log(
                event: 'alumni_posting_updated',
                description: "Updated posting '{$posting->designation}' for {$alumni->full_name}.",
                auditable: $alumni,
                oldValues: $oldValues,
                newValues: $posting->auditableAttributes()
            );

            return $posting;
        });
    }

    /**
     * Delete an individual posting record.
     */
    public static function deletePosting(AlumniPosting $posting): bool
    {
        return DB::transaction(function () use ($posting) {
            $alumni = $posting->alumni;
            AuditLogService::log(
                event: 'alumni_posting_deleted',
                description: "Deleted posting '{$posting->designation}' for {$alumni->full_name}.",
                auditable: $alumni,
                oldValues: $posting->auditableAttributes()
            );

            return (bool) $posting->delete();
        });
    }

    /**
     * Add a single examination selection record.
     *
     * @param  array<string, mixed>  $data
     */
    public static function addExamination(Alumni $alumni, array $data): AlumniExamination
    {
        return DB::transaction(function () use ($alumni, $data) {
            $isPrimary = ! empty($data['is_primary']);
            if ($isPrimary) {
                $alumni->examinations()->where('is_primary', true)->update(['is_primary' => false]);
            }

            $exam = $alumni->examinations()->create($data);

            if ($isPrimary) {
                $alumni->updateQuietly([
                    'exam_year' => $exam->exam_year,
                    'all_india_rank' => $exam->all_india_rank ?: $alumni->all_india_rank,
                    'state_rank' => $exam->state_rank ?: $alumni->state_rank,
                    'roll_number' => $exam->roll_number ?: $alumni->roll_number,
                    'service_name' => $exam->service_allotted ?: $alumni->service_name,
                ]);
            }

            AuditLogService::log(
                event: 'alumni_exam_added',
                description: "Added examination selection '{$exam->examination_name} ({$exam->exam_year})' for {$alumni->full_name}.",
                auditable: $alumni,
                newValues: $exam->auditableAttributes()
            );

            return $exam;
        });
    }

    /**
     * Update an individual examination record.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateExamination(AlumniExamination $exam, array $data): AlumniExamination
    {
        return DB::transaction(function () use ($exam, $data) {
            $alumni = $exam->alumni;
            $oldValues = $exam->auditableAttributes();

            if (! empty($data['is_primary']) && ! $exam->is_primary) {
                $alumni->examinations()->where('id', '!=', $exam->id)->where('is_primary', true)->update(['is_primary' => false]);
            }

            $exam->update($data);

            if ($exam->is_primary) {
                $alumni->updateQuietly([
                    'exam_year' => $exam->exam_year,
                    'all_india_rank' => $exam->all_india_rank ?: $alumni->all_india_rank,
                    'state_rank' => $exam->state_rank ?: $alumni->state_rank,
                    'roll_number' => $exam->roll_number ?: $alumni->roll_number,
                    'service_name' => $exam->service_allotted ?: $alumni->service_name,
                ]);
            }

            AuditLogService::log(
                event: 'alumni_exam_updated',
                description: "Updated examination selection '{$exam->examination_name}' for {$alumni->full_name}.",
                auditable: $alumni,
                oldValues: $oldValues,
                newValues: $exam->auditableAttributes()
            );

            return $exam;
        });
    }

    /**
     * Delete an individual examination selection record.
     */
    public static function deleteExamination(AlumniExamination $exam): bool
    {
        return DB::transaction(function () use ($exam) {
            $alumni = $exam->alumni;
            AuditLogService::log(
                event: 'alumni_exam_deleted',
                description: "Deleted examination selection '{$exam->examination_name}' for {$alumni->full_name}.",
                auditable: $alumni,
                oldValues: $exam->auditableAttributes()
            );

            return (bool) $exam->delete();
        });
    }

    /**
     * Compute comprehensive, real-time statistics for the alumni dashboard.
     *
     * @return array<string, mixed>
     */
    public static function getStatistics(): array
    {
        $totalAlumni = Alumni::count();
        $activeAlumni = Alumni::where('status', Alumni::STATUS_ACTIVE)->count();
        $featuredAlumni = Alumni::where('is_featured', true)->count();
        $verifiedAlumni = Alumni::where('is_verified', true)->count();

        // Civil Servants count (UPSC CSE, IAS, IPS, IFS, WBCS, WBPS)
        $civilServantsCount = Alumni::where(function ($q) {
            $q->where('service_type', 'like', '%UPSC%')
                ->orWhere('service_type', 'like', '%Civil Service%')
                ->orWhere('service_type', 'like', '%Police%')
                ->orWhere('service_name', 'like', '%IAS%')
                ->orWhere('service_name', 'like', '%IPS%')
                ->orWhere('service_name', 'like', '%IFS%')
                ->orWhere('service_name', 'like', '%WBCS%')
                ->orWhere('service_name', 'like', '%WBPS%');
        })->count();

        // Unique courses and batches attended
        $uniqueCoursesCount = AlumniCourseBatch::distinct('course_name')->count('course_name');
        $uniqueBatchesCount = AlumniCourseBatch::distinct('batch_name')->count('batch_name');

        // Breakdown by Service Type
        $byServiceType = Alumni::select('service_type', DB::raw('count(*) as count'))
            ->groupBy('service_type')
            ->orderByDesc('count')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->service_type => (int) $item->count])
            ->all();

        // Breakdown by Exam Year / Passing Year
        $byYear = Alumni::select(DB::raw('COALESCE(exam_year, passing_year) as year'), DB::raw('count(*) as count'))
            ->whereNotNull(DB::raw('COALESCE(exam_year, passing_year)'))
            ->groupBy('year')
            ->orderByDesc('year')
            ->take(8)
            ->get()
            ->mapWithKeys(fn ($item) => [(string) $item->year => (int) $item->count])
            ->all();

        // Breakdown by Posting State
        $byState = Alumni::select('posting_state', DB::raw('count(*) as count'))
            ->whereNotNull('posting_state')
            ->where('posting_state', '!=', '')
            ->groupBy('posting_state')
            ->orderByDesc('count')
            ->take(10)
            ->get()
            ->mapWithKeys(fn ($item) => [$item->posting_state => (int) $item->count])
            ->all();

        // Top Cadres
        $byCadre = Alumni::select('cadre', DB::raw('count(*) as count'))
            ->whereNotNull('cadre')
            ->where('cadre', '!=', '')
            ->groupBy('cadre')
            ->orderByDesc('count')
            ->take(6)
            ->get()
            ->mapWithKeys(fn ($item) => [$item->cadre => (int) $item->count])
            ->all();

        return [
            'total_alumni' => $totalAlumni,
            'active_alumni' => $activeAlumni,
            'featured_alumni' => $featuredAlumni,
            'verified_alumni' => $verifiedAlumni,
            'civil_servants_count' => $civilServantsCount,
            'unique_courses_count' => $uniqueCoursesCount,
            'unique_batches_count' => $uniqueBatchesCount,
            'by_service_type' => $byServiceType,
            'by_year' => $byYear,
            'by_exam_year' => $byYear,
            'by_state' => $byState,
            'by_cadre' => $byCadre,
        ];
    }

    /**
     * Search enrolled students or users for 1-click alumni conversion.
     *
     * @return Collection<int, User>
     */
    public static function searchStudentsForConversion(string $query): Collection
    {
        if (strlen(trim($query)) < 2) {
            return new Collection;
        }

        $term = '%'.trim($query).'%';

        return User::query()
            ->with(['studentProfile', 'enrollments.course', 'enrollments.batch'])
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhereHas('enrollments', function ($eq) use ($term) {
                        $eq->where('enrollment_no', 'like', $term)
                            ->orWhere('roll_no', 'like', $term);
                    });
            })
            ->whereDoesntHave('alumniProfile')
            ->take(10)
            ->get();
    }
}
