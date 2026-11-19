<?php

namespace App\Services\Exam;

use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExaminationCenter;
use App\Models\ExaminationCenterRoom;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamService
{
    /**
     * Get aggregate metrics for the Examination Dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDashboardMetrics(): array
    {
        $today = now()->toDateString();

        $totalExams = Exam::count();
        $upcomingExams = Exam::where('exam_date', '>=', $today)->count();
        $totalCenters = ExaminationCenter::active()->count();
        $totalCapacity = (int) ExaminationCenter::active()->sum('total_capacity');
        $totalCandidates = ExamCandidate::count();
        $presentCandidates = ExamCandidate::where('attendance_status', ExamCandidate::ATTENDANCE_PRESENT)->count();
        $absentCandidates = ExamCandidate::where('attendance_status', ExamCandidate::ATTENDANCE_ABSENT)->count();

        $recentExams = Exam::withCount(['candidates', 'centers'])
            ->latest('exam_date')
            ->take(5)
            ->get();

        return [
            'metrics' => [
                'total_exams' => $totalExams,
                'upcoming_exams' => $upcomingExams,
                'total_centers' => $totalCenters,
                'total_center_capacity' => $totalCapacity,
                'total_candidates' => $totalCandidates,
                'present_candidates' => $presentCandidates,
                'absent_candidates' => $absentCandidates,
                'attendance_rate' => $totalCandidates > 0 ? round(($presentCandidates / $totalCandidates) * 100, 1) : 0.0,
            ],
            'recent_exams' => $recentExams->map(fn (Exam $e) => [
                'id' => $e->id,
                'uuid' => $e->uuid,
                'exam_code' => $e->exam_code,
                'exam_name' => $e->exam_name,
                'exam_date' => $e->exam_date?->format('Y-m-d'),
                'status' => $e->status,
                'candidates_count' => $e->candidates_count,
                'centers_count' => $e->centers_count,
            ]),
        ];
    }

    /**
     * Create a new examination with DB transaction and audit logging.
     *
     * @param  array<string, mixed>  $data
     */
    public function createExam(array $data, ?User $actor = null): Exam
    {
        return DB::transaction(function () use ($data, $actor) {
            $exam = Exam::create([
                'exam_code' => strtoupper(trim((string) $data['exam_code'])),
                'exam_name' => trim((string) $data['exam_name']),
                'academic_year' => $data['academic_year'] ?? date('Y'),
                'conducting_body' => $data['conducting_body'] ?? 'SNTCSSC Examination Authority',
                'notification_date' => ! empty($data['notification_date']) ? $data['notification_date'] : null,
                'apply_start' => ! empty($data['apply_start']) ? $data['apply_start'] : null,
                'apply_end' => ! empty($data['apply_end']) ? $data['apply_end'] : null,
                'exam_date' => ! empty($data['exam_date']) ? $data['exam_date'] : null,
                'exam_time' => $data['exam_time'] ?? '11:00 AM - 01:00 PM',
                'reporting_time' => $data['reporting_time'] ?? '10:00 AM',
                'duration_minutes' => (int) ($data['duration_minutes'] ?? 120),
                'fee_general' => (float) ($data['fee_general'] ?? 0.00),
                'fee_reserved' => (float) ($data['fee_reserved'] ?? 0.00),
                'total_vacancies' => isset($data['total_vacancies']) ? (int) $data['total_vacancies'] : null,
                'status' => $data['status'] ?? Exam::STATUS_DRAFT,
                'instructions' => $data['instructions'] ?? null,
                'admit_card_instructions' => $data['admit_card_instructions'] ?? null,
                'admit_card_release_date' => ! empty($data['admit_card_release_date']) ? $data['admit_card_release_date'] : null,
                'created_by' => $actor?->id,
            ]);

            if (! empty($data['center_ids'])) {
                $syncData = [];
                foreach ($data['center_ids'] as $cid) {
                    $syncData[$cid] = ['is_active' => true];
                }
                $exam->centers()->sync($syncData);
            }

            AuditLogService::log(
                event: 'exam_created',
                description: "Created examination '{$exam->exam_name}' [{$exam->exam_code}].",
                auditable: $exam,
                newValues: $exam->toArray(),
                userId: $actor?->id
            );

            return $exam;
        });
    }

    /**
     * Update an existing examination with DB transaction and audit logging.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateExam(Exam $exam, array $data, ?User $actor = null): Exam
    {
        return DB::transaction(function () use ($exam, $data, $actor) {
            $oldValues = $exam->only(['exam_code', 'exam_name', 'status', 'exam_date', 'fee_general']);

            $exam->update([
                'exam_code' => isset($data['exam_code']) ? strtoupper(trim((string) $data['exam_code'])) : $exam->exam_code,
                'exam_name' => isset($data['exam_name']) ? trim((string) $data['exam_name']) : $exam->exam_name,
                'academic_year' => $data['academic_year'] ?? $exam->academic_year,
                'conducting_body' => $data['conducting_body'] ?? $exam->conducting_body,
                'notification_date' => array_key_exists('notification_date', $data) ? $data['notification_date'] : $exam->notification_date,
                'apply_start' => array_key_exists('apply_start', $data) ? $data['apply_start'] : $exam->apply_start,
                'apply_end' => array_key_exists('apply_end', $data) ? $data['apply_end'] : $exam->apply_end,
                'exam_date' => array_key_exists('exam_date', $data) ? $data['exam_date'] : $exam->exam_date,
                'exam_time' => $data['exam_time'] ?? $exam->exam_time,
                'reporting_time' => $data['reporting_time'] ?? $exam->reporting_time,
                'duration_minutes' => isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : $exam->duration_minutes,
                'fee_general' => isset($data['fee_general']) ? (float) $data['fee_general'] : $exam->fee_general,
                'fee_reserved' => isset($data['fee_reserved']) ? (float) $data['fee_reserved'] : $exam->fee_reserved,
                'total_vacancies' => array_key_exists('total_vacancies', $data) ? (int) $data['total_vacancies'] : $exam->total_vacancies,
                'status' => $data['status'] ?? $exam->status,
                'instructions' => array_key_exists('instructions', $data) ? $data['instructions'] : $exam->instructions,
                'admit_card_instructions' => array_key_exists('admit_card_instructions', $data) ? $data['admit_card_instructions'] : $exam->admit_card_instructions,
                'admit_card_release_date' => array_key_exists('admit_card_release_date', $data) ? $data['admit_card_release_date'] : $exam->admit_card_release_date,
            ]);

            if (isset($data['center_ids'])) {
                $exam->centers()->sync($data['center_ids']);
            }

            AuditLogService::log(
                event: 'exam_updated',
                description: "Updated examination '{$exam->exam_name}' (#{$exam->id}).",
                auditable: $exam,
                oldValues: $oldValues,
                newValues: $exam->only(['exam_code', 'exam_name', 'status', 'exam_date', 'fee_general']),
                userId: $actor?->id
            );

            return $exam->load('centers');
        });
    }

    /**
     * Soft delete an examination with cascade validation and audit logging.
     */
    public function deleteExam(Exam $exam, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($exam, $actor) {
            if ($exam->status === Exam::STATUS_COMPLETED && $exam->candidates()->where('is_qualified', true)->exists()) {
                throw ValidationException::withMessages(['exam' => __('Completed examinations with declared results cannot be deleted.')]);
            }

            $name = $exam->exam_name;
            $id = $exam->id;

            // Soft delete candidates
            $exam->candidates()->delete();

            $exam->delete();

            AuditLogService::log(
                event: 'exam_deleted',
                description: "Deleted examination '{$name}' (#{$id}).",
                auditable: $exam,
                userId: $actor?->id
            );

            return true;
        });
    }

    /**
     * Create an examination center with audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCenter(array $data, ?User $actor = null): ExaminationCenter
    {
        return DB::transaction(function () use ($data, $actor) {
            $center = ExaminationCenter::create($data);

            AuditLogService::log(
                event: 'examination_center_created',
                description: "Created examination center '{$center->name}' [{$center->code}].",
                auditable: $center,
                newValues: $center->toArray(),
                userId: $actor?->id
            );

            return $center;
        });
    }

    /**
     * Update an examination center.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCenter(ExaminationCenter $center, array $data, ?User $actor = null): ExaminationCenter
    {
        return DB::transaction(function () use ($center, $data, $actor) {
            $oldValues = $center->only(['code', 'name', 'city', 'total_capacity', 'is_active']);

            $center->update($data);

            AuditLogService::log(
                event: 'examination_center_updated',
                description: "Updated examination center '{$center->name}' (#{$center->id}).",
                auditable: $center,
                oldValues: $oldValues,
                newValues: $center->only(['code', 'name', 'city', 'total_capacity', 'is_active']),
                userId: $actor?->id
            );

            return $center->load('rooms');
        });
    }

    /**
     * Delete an examination center with relation check.
     */
    public function deleteCenter(ExaminationCenter $center, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($center, $actor) {
            if ($center->candidates()->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages([
                    'center' => __('Cannot delete center with active candidate seat allocations.'),
                ]);
            }

            $name = $center->name;
            $id = $center->id;

            // Soft delete child rooms
            $center->rooms()->delete();
            $center->delete();

            AuditLogService::log(
                event: 'examination_center_deleted',
                description: "Deleted examination center '{$name}' (#{$id}).",
                auditable: $center,
                userId: $actor?->id
            );

            return true;
        });
    }

    /**
     * Add a classroom/hall to an examination center.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRoom(ExaminationCenter $center, array $data, ?User $actor = null): ExaminationCenterRoom
    {
        return DB::transaction(function () use ($center, $data, $actor) {
            $room = $center->rooms()->create($data);

            // Re-calculate center total capacity
            $center->update([
                'total_capacity' => (int) $center->rooms()->sum('capacity'),
            ]);

            AuditLogService::log(
                event: 'examination_room_created',
                description: "Added room '{$room->room_number}' to center '{$center->name}'.",
                auditable: $room,
                newValues: $room->toArray(),
                userId: $actor?->id
            );

            return $room;
        });
    }

    /**
     * Update an examination center room.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRoom(ExaminationCenterRoom $room, array $data, ?User $actor = null): ExaminationCenterRoom
    {
        return DB::transaction(function () use ($room, $data, $actor) {
            $oldValues = $room->only(['room_number', 'capacity', 'floor', 'is_active']);

            $room->update($data);

            if ($room->center) {
                $room->center->update([
                    'total_capacity' => (int) $room->center->rooms()->sum('capacity'),
                ]);
            }

            AuditLogService::log(
                event: 'examination_room_updated',
                description: "Updated room '{$room->room_number}' (#{$room->id}).",
                auditable: $room,
                oldValues: $oldValues,
                newValues: $room->only(['room_number', 'capacity', 'floor', 'is_active']),
                userId: $actor?->id
            );

            return $room;
        });
    }

    /**
     * Delete an examination center room with integrity validation.
     */
    public function deleteRoom(ExaminationCenterRoom $room, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($room, $actor) {
            if ($room->candidates()->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages([
                    'room' => __('Cannot delete examination hall with assigned candidate seats.'),
                ]);
            }

            $roomNumber = $room->room_number;
            $center = $room->center;

            $room->delete();

            if ($center) {
                $center->update([
                    'total_capacity' => (int) $center->rooms()->sum('capacity'),
                ]);
            }

            AuditLogService::log(
                event: 'examination_room_deleted',
                description: "Deleted room '{$roomNumber}' from center '{$center?->name}'.",
                auditable: $room,
                userId: $actor?->id
            );

            return true;
        });
    }

    /**
     * Build the real-time seating plan matrix for an examination across all centers and rooms.
     *
     * @return array<string, mixed>
     */
    public function getSeatingPlan(Exam $exam): array
    {
        $centers = $exam->centers()->with(['rooms' => fn ($q) => $q->active()])->get();

        $centersData = [];
        $totalCapacity = 0;
        $totalAssigned = 0;

        foreach ($centers as $center) {
            $centerRooms = [];
            $centerCapacity = 0;
            $centerAssigned = 0;

            foreach ($center->rooms as $room) {
                $candidatesCount = ExamCandidate::where('exam_id', $exam->id)
                    ->where('examination_center_room_id', $room->id)
                    ->count();

                $centerRooms[] = [
                    'id' => $room->id,
                    'room_number' => $room->room_number,
                    'floor' => $room->floor,
                    'building_name' => $room->building_name,
                    'capacity' => $room->capacity,
                    'assigned_candidates' => $candidatesCount,
                    'vacant_seats' => max(0, $room->capacity - $candidatesCount),
                ];

                $centerCapacity += $room->capacity;
                $centerAssigned += $candidatesCount;
            }

            $totalCapacity += $centerCapacity;
            $totalAssigned += $centerAssigned;

            $centersData[] = [
                'id' => $center->id,
                'name' => $center->name,
                'code' => $center->code,
                'city' => $center->city,
                'district' => $center->district,
                'total_capacity' => $centerCapacity ?: $center->total_capacity,
                'assigned_candidates' => $centerAssigned,
                'vacant_seats' => max(0, ($centerCapacity ?: $center->total_capacity) - $centerAssigned),
                'rooms' => $centerRooms,
            ];
        }

        return [
            'exam' => [
                'id' => $exam->id,
                'exam_code' => $exam->exam_code,
                'exam_name' => $exam->exam_name,
                'exam_date' => $exam->exam_date?->format('Y-m-d'),
                'exam_time' => $exam->exam_time,
                'reporting_time' => $exam->reporting_time,
            ],
            'summary' => [
                'total_centers' => count($centersData),
                'total_capacity' => $totalCapacity,
                'total_assigned_seats' => $totalAssigned,
                'total_vacant_seats' => max(0, $totalCapacity - $totalAssigned),
            ],
            'centers' => $centersData,
        ];
    }

    /**
     * Allocate seats and generate roll numbers for candidates with DB transaction and audit logging.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function allocateSeats(Exam $exam, array $candidates, int $centerId, ?int $roomId = null, ?User $actor = null): array
    {
        $center = ExaminationCenter::findOrFail($centerId);
        $room = $roomId ? ExaminationCenterRoom::where('examination_center_id', $centerId)->findOrFail($roomId) : null;

        $createdCandidates = [];

        DB::transaction(function () use ($exam, $center, $room, $candidates, &$createdCandidates) {
            $currentCount = ExamCandidate::where('exam_id', $exam->id)->count();

            foreach ($candidates as $index => $cData) {
                $seq = $currentCount + $index + 1;
                $rollNumber = $cData['roll_number'] ?? sprintf('%s-%s-%04d', $exam->exam_code, $center->code, $seq);
                $seatNumber = $cData['seat_number'] ?? sprintf('%s-S%03d', $room ? $room->room_number : 'H1', $seq);

                $candidate = ExamCandidate::updateOrCreate(
                    [
                        'exam_id' => $exam->id,
                        'roll_number' => $rollNumber,
                    ],
                    [
                        'student_id' => $cData['student_id'] ?? null,
                        'user_id' => $cData['user_id'] ?? null,
                        'candidate_name' => trim((string) $cData['candidate_name']),
                        'candidate_email' => $cData['candidate_email'] ?? null,
                        'candidate_phone' => $cData['candidate_phone'] ?? null,
                        'examination_center_id' => $center->id,
                        'examination_center_room_id' => $room?->id,
                        'seat_number' => $seatNumber,
                        'admit_card_token' => Str::random(32),
                        'attendance_status' => ExamCandidate::ATTENDANCE_PENDING,
                    ]
                );

                $createdCandidates[] = $candidate;
            }
        });

        AuditLogService::log(
            event: 'exam_seats_allocated',
            description: 'Allocated '.count($createdCandidates)." candidate seats for exam '{$exam->exam_name}' at center '{$center->name}'.",
            auditable: $exam,
            userId: $actor?->id
        );

        return [
            'exam_id' => $exam->id,
            'center_id' => $center->id,
            'allocated_count' => count($createdCandidates),
            'candidates' => $createdCandidates,
        ];
    }

    /**
     * Mark attendance for a candidate during exam day with DB transaction and audit logging.
     */
    public function markAttendance(Exam $exam, string $rollNumber, string $status, ?string $remarks = null, ?User $actor = null): ExamCandidate
    {
        if (! in_array($status, [ExamCandidate::ATTENDANCE_PRESENT, ExamCandidate::ATTENDANCE_ABSENT, ExamCandidate::ATTENDANCE_DISQUALIFIED], true)) {
            throw ValidationException::withMessages(['status' => __('Invalid attendance status.')]);
        }

        return DB::transaction(function () use ($exam, $rollNumber, $status, $remarks, $actor) {
            $candidate = ExamCandidate::where('exam_id', $exam->id)
                ->where(function ($q) use ($rollNumber) {
                    $q->where('roll_number', $rollNumber)
                        ->orWhere('admit_card_token', $rollNumber);
                })
                ->firstOrFail();

            $oldStatus = $candidate->attendance_status;

            $candidate->update([
                'attendance_status' => $status,
                'attendance_marked_at' => now(),
                'remarks' => $remarks ?: $candidate->remarks,
            ]);

            AuditLogService::log(
                event: 'exam_attendance_marked',
                description: "Marked attendance '{$status}' for candidate '{$candidate->candidate_name}' (Roll: {$candidate->roll_number}).",
                auditable: $candidate,
                oldValues: ['attendance_status' => $oldStatus],
                newValues: ['attendance_status' => $status, 'marked_at' => now()->toIso8601String()],
                userId: $actor?->id
            );

            return $candidate;
        });
    }

    /**
     * Record examination results and qualification status with DB transaction and audit logging.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public function recordResults(Exam $exam, array $results, ?User $actor = null): array
    {
        $updatedCount = 0;

        DB::transaction(function () use ($exam, $results, &$updatedCount) {
            foreach ($results as $item) {
                $rollNumber = $item['roll_number'] ?? null;
                if (! $rollNumber) {
                    continue;
                }

                $candidate = ExamCandidate::where('exam_id', $exam->id)
                    ->where('roll_number', $rollNumber)
                    ->first();

                if ($candidate) {
                    $candidate->update([
                        'marks_obtained' => isset($item['marks_obtained']) ? (float) $item['marks_obtained'] : $candidate->marks_obtained,
                        'rank' => isset($item['rank']) ? (int) $item['rank'] : $candidate->rank,
                        'is_qualified' => isset($item['is_qualified']) ? (bool) $item['is_qualified'] : $candidate->is_qualified,
                        'remarks' => $item['remarks'] ?? $candidate->remarks,
                    ]);
                    $updatedCount++;
                }
            }
        });

        AuditLogService::log(
            event: 'exam_results_recorded',
            description: "Recorded examination scores & ranks for {$updatedCount} candidate(s) in '{$exam->exam_name}'.",
            auditable: $exam,
            userId: $actor?->id
        );

        return [
            'exam_id' => $exam->id,
            'updated_count' => $updatedCount,
        ];
    }

    /**
     * Verify Admit Card QR token or roll number for invigilator scanning apps.
     *
     * @return array<string, mixed>
     */
    public function verifyAdmitCard(string $tokenOrRoll): array
    {
        $candidate = ExamCandidate::with(['exam', 'center', 'room'])
            ->where('admit_card_token', $tokenOrRoll)
            ->orWhere('roll_number', $tokenOrRoll)
            ->firstOrFail();

        return [
            'is_valid' => true,
            'candidate' => [
                'id' => $candidate->id,
                'name' => $candidate->candidate_name,
                'roll_number' => $candidate->roll_number,
                'seat_number' => $candidate->seat_number,
                'attendance_status' => $candidate->attendance_status,
                'attendance_marked_at' => $candidate->attendance_marked_at?->toIso8601String(),
            ],
            'examination' => [
                'id' => $candidate->exam->id,
                'exam_code' => $candidate->exam->exam_code,
                'exam_name' => $candidate->exam->exam_name,
                'exam_date' => $candidate->exam->exam_date?->format('Y-m-d'),
                'exam_time' => $candidate->exam->exam_time,
                'reporting_time' => $candidate->exam->reporting_time,
            ],
            'venue' => [
                'center_name' => $candidate->center?->name ?? 'Main Campus',
                'center_code' => $candidate->center?->code ?? 'CC-01',
                'room_number' => $candidate->room?->room_number ?? 'Hall A',
                'floor' => $candidate->room?->floor ?? 'Ground Floor',
            ],
        ];
    }

    /**
     * Generate full official Admit Card dataset for printable PDF or Mobile wallet.
     *
     * @return array<string, mixed>
     */
    public function generateAdmitCardData(ExamCandidate $candidate): array
    {
        $exam = $candidate->exam;
        $center = $candidate->center;
        $room = $candidate->room;

        if (! $candidate->admit_card_downloaded_at) {
            $candidate->update(['admit_card_downloaded_at' => now()]);
        }

        return [
            'institute' => [
                'name' => 'Satyendranath Tagore Civil Services Study Centre',
                'authority' => 'Government of West Bengal',
                'address' => 'NSOU Campus, Salt Lake City, Kolkata - 700064',
                'portal_url' => url('/'),
            ],
            'candidate' => [
                'name' => $candidate->candidate_name,
                'roll_number' => $candidate->roll_number,
                'email' => $candidate->candidate_email,
                'phone' => $candidate->candidate_phone,
                'seat_number' => $candidate->seat_number,
                'admit_token' => $candidate->admit_card_token,
                'verification_qr_payload' => json_encode([
                    'exam_code' => $exam->exam_code,
                    'roll_number' => $candidate->roll_number,
                    'token' => $candidate->admit_card_token,
                    'timestamp' => now()->timestamp,
                ]),
            ],
            'examination' => [
                'exam_code' => $exam->exam_code,
                'exam_name' => $exam->exam_name,
                'academic_year' => $exam->academic_year,
                'exam_date' => $exam->exam_date?->format('l, F j, Y'),
                'exam_time' => $exam->exam_time,
                'reporting_time' => $exam->reporting_time,
                'duration_minutes' => $exam->duration_minutes,
            ],
            'venue' => [
                'center_name' => $center?->name ?? 'Main Administrative Campus',
                'center_code' => $center?->code ?? 'CC-01',
                'address' => $center?->address ?? 'Kolkata Campus',
                'city' => $center?->city ?? 'Kolkata',
                'room_number' => $room?->room_number ?? 'Hall A',
                'floor' => $room?->floor ?? 'Ground Floor',
            ],
            'instructions' => array_filter(array_map('trim', explode("\n", $exam->admit_card_instructions ?: "1. Candidates must carry this Admit Card along with an original Photo Identity Proof.\n2. Electronic gadgets, mobile phones, and calculators are strictly prohibited inside the examination hall.\n3. Candidates must report at the designated venue by reporting time."))),
        ];
    }
}
