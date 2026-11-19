<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\Faculty;
use App\Models\Section;
use App\Support\Export\TableExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClassScheduleService
{
    /**
     * Check if a classroom is already booked at an overlapping time.
     */
    public static function checkClassroomConflict(
        int $classroomId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeScheduleId = null
    ): ?ClassSchedule {
        $query = ClassSchedule::where('classroom_id', $classroomId)
            ->whereDate('class_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($sub) use ($startTime, $endTime) {
                    $sub->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                });
            });

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        return $query->with(['batch', 'course', 'section'])->first();
    }

    /**
     * Check if any of the assigned faculties have overlapping classes.
     */
    public static function checkFacultyConflicts(
        array $facultyIds,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeScheduleId = null
    ): Collection {
        if (empty($facultyIds)) {
            return new Collection;
        }

        $query = ClassSchedule::whereDate('class_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($sub) use ($startTime, $endTime) {
                    $sub->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                });
            })
            ->whereHas('faculties', function ($q) use ($facultyIds) {
                $q->whereIn('faculties.id', $facultyIds);
            })
            ->with(['faculties', 'batch', 'course']);

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        return $query->get();
    }

    /**
     * Create a single class schedule.
     */
    public static function createSingle(array $data, array $facultyIds = []): ClassSchedule
    {
        return DB::transaction(function () use ($data, $facultyIds) {
            $classDate = Carbon::parse($data['class_date']);
            $data['day_of_week'] = $data['day_of_week'] ?? $classDate->format('l');

            if (! empty($data['classroom_id'])) {
                $classroom = Classroom::find($data['classroom_id']);
                if ($classroom) {
                    $data['classroom'] = $classroom->name;
                }
            }

            $schedule = ClassSchedule::create($data);

            if (! empty($facultyIds)) {
                $attachData = [];
                foreach ($facultyIds as $index => $facultyId) {
                    $attachData[(int) $facultyId] = [
                        'role' => $index === 0 ? 'lead_faculty' : 'co_faculty',
                    ];
                }
                $schedule->faculties()->sync($attachData);
            }

            AuditLogService::log(
                event: 'class_schedule_created',
                description: "Scheduled class {$schedule->schedule_code} ({$schedule->subject}) on {$schedule->class_date->format('d M Y')}",
                auditable: $schedule,
                newValues: $schedule->toArray(),
                userId: auth()->id()
            );

            return $schedule->load(['batch', 'course', 'section', 'faculties']);
        });
    }

    /**
     * Batch generate recurring class routine entries across a date range.
     *
     * @param  array  $baseData  Base attributes (batch_id, course_id, section_id, subject, time, mode, etc.)
     * @param  array  $facultyIds  Assigned faculties
     * @param  array  $config  ['start_date', 'end_date', 'days_of_week' => ['Monday', ...], 'start_lecture_no' => 1]
     * @return array{count: int, schedules: Collection<int, ClassSchedule>}
     */
    public static function generateRecurring(array $baseData, array $facultyIds, array $config): array
    {
        return DB::transaction(function () use ($baseData, $facultyIds, $config) {
            $startDate = Carbon::parse($config['start_date']);
            $endDate = Carbon::parse($config['end_date']);
            $daysOfWeek = array_map('ucfirst', $config['days_of_week'] ?? []);

            if (! empty($baseData['classroom_id'])) {
                $classroom = Classroom::find($baseData['classroom_id']);
                if ($classroom) {
                    $baseData['classroom'] = $classroom->name;
                }
            }

            if (empty($daysOfWeek)) {
                $daysOfWeek = [$startDate->format('l')];
            }

            $groupId = (string) Str::uuid();
            $ruleDescription = 'Recurring on '.implode(', ', $daysOfWeek).' from '.$startDate->format('d M Y').' to '.$endDate->format('d M Y');

            $period = CarbonPeriod::create($startDate, '1 day', $endDate);
            $lectureCounter = ! empty($config['start_lecture_no']) ? (int) $config['start_lecture_no'] : null;

            $createdSchedules = new Collection;

            foreach ($period as $date) {
                $dayName = $date->format('l');
                if (! in_array($dayName, $daysOfWeek, true)) {
                    continue;
                }

                $lectureNumber = null;
                if ($lectureCounter !== null) {
                    $lectureNumber = 'Lecture '.str_pad((string) $lectureCounter, 2, '0', STR_PAD_LEFT);
                    $lectureCounter++;
                } elseif (! empty($baseData['lecture_number'])) {
                    $lectureNumber = $baseData['lecture_number'];
                }

                $scheduleData = array_merge($baseData, [
                    'class_date' => $date->format('Y-m-d'),
                    'day_of_week' => $dayName,
                    'lecture_number' => $lectureNumber,
                    'recurrence_group_id' => $groupId,
                    'is_recurring' => true,
                    'recurrence_rule' => $ruleDescription,
                    'status' => 'scheduled',
                ]);

                $schedule = ClassSchedule::create($scheduleData);

                if (! empty($facultyIds)) {
                    $attachData = [];
                    foreach ($facultyIds as $index => $facultyId) {
                        $attachData[(int) $facultyId] = [
                            'role' => $index === 0 ? 'lead_faculty' : 'co_faculty',
                        ];
                    }
                    $schedule->faculties()->sync($attachData);
                }

                $createdSchedules->push($schedule);
            }

            AuditLogService::log(
                event: 'recurring_classes_generated',
                description: "Generated {$createdSchedules->count()} recurring class instances ({$ruleDescription})",
                newValues: [
                    'group_id' => $groupId,
                    'count' => $createdSchedules->count(),
                    'batch_id' => $baseData['batch_id'] ?? null,
                    'course_id' => $baseData['course_id'] ?? null,
                ],
                userId: auth()->id()
            );

            return [
                'count' => $createdSchedules->count(),
                'schedules' => $createdSchedules,
            ];
        });
    }

    /**
     * Update an existing class schedule.
     */
    public static function updateSchedule(ClassSchedule $schedule, array $data, ?array $facultyIds = null): ClassSchedule
    {
        return DB::transaction(function () use ($schedule, $data, $facultyIds) {
            $oldValues = $schedule->toArray();

            if (! empty($data['class_date'])) {
                $data['day_of_week'] = Carbon::parse($data['class_date'])->format('l');
            }

            if (array_key_exists('classroom_id', $data) && ! empty($data['classroom_id'])) {
                $classroom = Classroom::find($data['classroom_id']);
                if ($classroom) {
                    $data['classroom'] = $classroom->name;
                }
            }

            $schedule->update($data);

            if ($facultyIds !== null) {
                $attachData = [];
                foreach ($facultyIds as $index => $facultyId) {
                    $attachData[(int) $facultyId] = [
                        'role' => $index === 0 ? 'lead_faculty' : 'co_faculty',
                    ];
                }
                $schedule->faculties()->sync($attachData);
            }

            AuditLogService::log(
                event: 'class_schedule_updated',
                description: "Updated class schedule {$schedule->schedule_code}",
                auditable: $schedule,
                oldValues: $oldValues,
                newValues: $schedule->fresh()->toArray(),
                userId: auth()->id()
            );

            return $schedule->fresh(['batch', 'course', 'section', 'faculties']);
        });
    }

    /**
     * Reschedule a class.
     */
    public static function reschedule(ClassSchedule $schedule, string $newDate, string $newStartTime, string $newEndTime, ?string $reason = null): ClassSchedule
    {
        return DB::transaction(function () use ($schedule, $newDate, $newStartTime, $newEndTime, $reason) {
            $oldValues = $schedule->toArray();

            $schedule->update([
                'class_date' => $newDate,
                'day_of_week' => Carbon::parse($newDate)->format('l'),
                'start_time' => $newStartTime,
                'end_time' => $newEndTime,
                'status' => 'rescheduled',
                'rescheduled_reason' => $reason,
            ]);

            AuditLogService::log(
                event: 'class_rescheduled',
                description: "Class {$schedule->schedule_code} rescheduled to {$newDate} ({$newStartTime}-{$newEndTime}). Reason: {$reason}",
                auditable: $schedule,
                oldValues: $oldValues,
                newValues: $schedule->fresh()->toArray(),
                userId: auth()->id()
            );

            return $schedule->fresh();
        });
    }

    /**
     * Cancel a scheduled class.
     */
    public static function cancel(ClassSchedule $schedule, string $reason): ClassSchedule
    {
        return DB::transaction(function () use ($schedule, $reason) {
            $oldValues = $schedule->toArray();

            $schedule->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
            ]);

            AuditLogService::log(
                event: 'class_cancelled',
                description: "Class {$schedule->schedule_code} cancelled. Reason: {$reason}",
                auditable: $schedule,
                oldValues: $oldValues,
                newValues: ['status' => 'cancelled', 'cancellation_reason' => $reason],
                userId: auth()->id()
            );

            return $schedule->fresh();
        });
    }

    /**
     * Mark a class as completed.
     */
    public static function markCompleted(ClassSchedule $schedule): ClassSchedule
    {
        return DB::transaction(function () use ($schedule) {
            $schedule->update([
                'status' => 'completed',
            ]);

            AuditLogService::log(
                event: 'class_completed',
                description: "Class {$schedule->schedule_code} marked as completed",
                auditable: $schedule,
                newValues: ['status' => 'completed'],
                userId: auth()->id()
            );

            return $schedule->fresh();
        });
    }

    /**
     * Update or upload class recording information.
     */
    public static function updateRecording(ClassSchedule $schedule, array $data): ClassSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $oldValues = [
                'recording_url' => $schedule->recording_url,
                'recording_file_path' => $schedule->recording_file_path,
                'recording_status' => $schedule->recording_status,
            ];

            $updateData = [
                'recording_url' => $data['recording_url'] ?? $schedule->recording_url,
                'recording_file_path' => $data['recording_file_path'] ?? $schedule->recording_file_path,
                'recording_password' => $data['recording_password'] ?? $schedule->recording_password,
                'recording_notes' => $data['recording_notes'] ?? $schedule->recording_notes,
                'recording_status' => ! empty($data['recording_url']) || ! empty($data['recording_file_path']) ? 'available' : ($data['recording_status'] ?? 'not_available'),
            ];

            $schedule->update($updateData);

            AuditLogService::log(
                event: 'class_recording_updated',
                description: "Updated recording access for class {$schedule->schedule_code}",
                auditable: $schedule,
                oldValues: $oldValues,
                newValues: $updateData,
                userId: auth()->id()
            );

            return $schedule->fresh();
        });
    }

    /**
     * Quick create a new faculty/teacher inline.
     */
    public static function quickCreateFaculty(array $facultyData): Faculty
    {
        return DB::transaction(function () use ($facultyData) {
            $faculty = Faculty::create([
                'name' => trim($facultyData['name']),
                'email' => ! empty($facultyData['email']) ? trim($facultyData['email']) : null,
                'phone' => ! empty($facultyData['phone']) ? trim($facultyData['phone']) : null,
                'designation' => ! empty($facultyData['designation']) ? trim($facultyData['designation']) : 'Faculty Member',
                'department' => ! empty($facultyData['department']) ? trim($facultyData['department']) : 'General Studies',
                'specialization' => ! empty($facultyData['specialization']) ? trim($facultyData['specialization']) : null,
                'qualification' => ! empty($facultyData['qualification']) ? trim($facultyData['qualification']) : null,
                'subjects' => ! empty($facultyData['subjects']) ? (is_array($facultyData['subjects']) ? $facultyData['subjects'] : array_map('trim', explode(',', $facultyData['subjects']))) : [],
                'status' => 'active',
            ]);

            AuditLogService::log(
                event: 'faculty_created_quick',
                description: "Quick-created faculty: {$faculty->name} ({$faculty->faculty_code})",
                auditable: $faculty,
                newValues: $faculty->toArray(),
                userId: auth()->id()
            );

            return $faculty;
        });
    }

    /**
     * Export class routine schedules to CSV, Excel, or PDF.
     */
    public static function export(Collection|array $schedules, string $format = 'csv')
    {
        $headers = [
            'Class Code',
            'Date',
            'Day',
            'Time',
            'Batch',
            'Course',
            'Section',
            'Type',
            'Subject',
            'Topics',
            'Faculty',
            'Mode',
            'Classroom / Platform',
            'Meeting Link',
            'Recording Status',
            'Status',
        ];

        $rows = [];
        foreach ($schedules as $item) {
            $facultyNames = $item->faculties->pluck('name')->join(', ') ?: 'Unassigned';
            $locationOrPlatform = $item->delivery_mode === 'online'
                ? ($item->platform ?: 'Online')
                : ($item->classroom ?: 'Campus');

            $rows[] = [
                $item->schedule_code,
                $item->class_date->format('Y-m-d'),
                $item->day_of_week,
                $item->formatted_time,
                $item->batch?->name ?? 'N/A',
                $item->course?->name ?? 'N/A',
                $item->section?->name ?? 'All Sections',
                ucfirst($item->class_type),
                $item->subject ?? 'N/A',
                $item->topics ?? 'N/A',
                $facultyNames,
                ucfirst($item->delivery_mode),
                $locationOrPlatform,
                $item->meeting_link ?? 'N/A',
                ucfirst(str_replace('_', ' ', $item->recording_status)),
                ucfirst($item->status),
            ];
        }

        $title = 'Class Routine and Lecture Schedule Report';
        $filename = 'classes-routine-schedule';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Academic Lecture Timetable & Routine'),
            'xlsx' => StudentImportExportService::exportExcel($filename, $headers, $rows),
            default => StudentImportExportService::exportCsv($filename, $headers, $rows),
        };
    }

    /**
     * Bulk import schedules from a spreadsheet or CSV file.
     *
     * @return array{imported: int, errors: array<string>}
     */
    public static function import(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            return ['imported' => 0, 'errors' => ['File contains no data rows.']];
        }

        $headerRow = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $importedCount = 0;
        $errors = [];

        // Required index mapping
        $dateIdx = array_search('date', $headerRow, true) !== false ? array_search('date', $headerRow, true) : array_search('class date', $headerRow, true);
        $batchIdx = array_search('batch', $headerRow, true) !== false ? array_search('batch', $headerRow, true) : array_search('batch code', $headerRow, true);
        $courseIdx = array_search('course', $headerRow, true) !== false ? array_search('course', $headerRow, true) : array_search('course code', $headerRow, true);
        $sectionIdx = array_search('section', $headerRow, true);
        $subjectIdx = array_search('subject', $headerRow, true);
        $topicIdx = array_search('topics', $headerRow, true) !== false ? array_search('topics', $headerRow, true) : array_search('topic', $headerRow, true);
        $startTimeIdx = array_search('start time', $headerRow, true);
        $endTimeIdx = array_search('end time', $headerRow, true);
        $facultyIdx = array_search('faculty', $headerRow, true) !== false ? array_search('faculty', $headerRow, true) : array_search('teacher', $headerRow, true);
        $modeIdx = array_search('mode', $headerRow, true);
        $typeIdx = array_search('type', $headerRow, true) !== false ? array_search('type', $headerRow, true) : array_search('class type', $headerRow, true);
        $classroomIdx = array_search('classroom', $headerRow, true) !== false ? array_search('classroom', $headerRow, true) : array_search('room', $headerRow, true);
        $platformIdx = array_search('platform', $headerRow, true);
        $meetingLinkIdx = array_search('meeting link', $headerRow, true);

        if ($dateIdx === false || $batchIdx === false || $courseIdx === false) {
            return [
                'imported' => 0,
                'errors' => ['Missing required columns: Date, Batch, Course are mandatory.'],
            ];
        }

        DB::beginTransaction();
        try {
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                if (empty(array_filter($row))) {
                    continue;
                }

                $rawDate = trim((string) ($row[$dateIdx] ?? ''));
                $rawBatch = trim((string) ($row[$batchIdx] ?? ''));
                $rawCourse = trim((string) ($row[$courseIdx] ?? ''));

                if (empty($rawDate) || empty($rawBatch) || empty($rawCourse)) {
                    $errors[] = 'Row '.($i + 1).': Missing Date, Batch, or Course.';

                    continue;
                }

                try {
                    $parsedDate = Carbon::parse($rawDate)->format('Y-m-d');
                } catch (Throwable $e) {
                    $errors[] = 'Row '.($i + 1).": Invalid date format '{$rawDate}'.";

                    continue;
                }

                $batch = Batch::where('code', $rawBatch)->orWhere('name', $rawBatch)->first();
                if (! $batch) {
                    $errors[] = 'Row '.($i + 1).": Batch '{$rawBatch}' not found.";

                    continue;
                }

                $course = Course::where('code', $rawCourse)->orWhere('name', $rawCourse)->first();
                if (! $course) {
                    $errors[] = 'Row '.($i + 1).": Course '{$rawCourse}' not found.";

                    continue;
                }

                $sectionId = null;
                if ($sectionIdx !== false && ! empty($row[$sectionIdx])) {
                    $rawSec = trim((string) $row[$sectionIdx]);
                    $section = Section::where('batch_id', $batch->id)
                        ->where('course_id', $course->id)
                        ->where(function ($q) use ($rawSec) {
                            $q->where('code', $rawSec)->orWhere('name', $rawSec);
                        })->first();
                    $sectionId = $section?->id;
                }

                $startTime = '10:00:00';
                if ($startTimeIdx !== false && ! empty($row[$startTimeIdx])) {
                    try {
                        $startTime = Carbon::parse($row[$startTimeIdx])->format('H:i:s');
                    } catch (Throwable) {
                    }
                }

                $endTime = '12:00:00';
                if ($endTimeIdx !== false && ! empty($row[$endTimeIdx])) {
                    try {
                        $endTime = Carbon::parse($row[$endTimeIdx])->format('H:i:s');
                    } catch (Throwable) {
                    }
                }

                $deliveryMode = 'offline';
                if ($modeIdx !== false && ! empty($row[$modeIdx])) {
                    $rawMode = strtolower(trim((string) $row[$modeIdx]));
                    if (in_array($rawMode, ['online', 'offline', 'hybrid'], true)) {
                        $deliveryMode = $rawMode;
                    }
                }

                $classType = 'regular';
                if ($typeIdx !== false && ! empty($row[$typeIdx])) {
                    $rawType = strtolower(trim((string) $row[$typeIdx]));
                    if (in_array($rawType, ['regular', 'optional', 'extra', 'revision', 'guest_lecture'], true)) {
                        $classType = $rawType;
                    }
                }

                $subject = $subjectIdx !== false ? trim((string) ($row[$subjectIdx] ?? '')) : null;
                $topics = $topicIdx !== false ? trim((string) ($row[$topicIdx] ?? '')) : null;
                $classroom = $classroomIdx !== false ? trim((string) ($row[$classroomIdx] ?? '')) : null;
                $platform = $platformIdx !== false ? trim((string) ($row[$platformIdx] ?? '')) : null;
                $meetingLink = $meetingLinkIdx !== false ? trim((string) ($row[$meetingLinkIdx] ?? '')) : null;

                $schedule = ClassSchedule::create([
                    'batch_id' => $batch->id,
                    'course_id' => $course->id,
                    'section_id' => $sectionId,
                    'class_type' => $classType,
                    'class_date' => $parsedDate,
                    'day_of_week' => Carbon::parse($parsedDate)->format('l'),
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'subject' => $subject ?: 'Subject',
                    'topics' => $topics,
                    'delivery_mode' => $deliveryMode,
                    'classroom' => $classroom,
                    'platform' => $platform,
                    'meeting_link' => $meetingLink,
                    'status' => 'scheduled',
                ]);

                if ($facultyIdx !== false && ! empty($row[$facultyIdx])) {
                    $rawFaculty = trim((string) $row[$facultyIdx]);
                    $faculty = Faculty::where('name', 'like', "%{$rawFaculty}%")
                        ->orWhere('faculty_code', $rawFaculty)
                        ->orWhere('email', $rawFaculty)
                        ->first();

                    if (! $faculty) {
                        // Quick create faculty from imported row
                        $faculty = Faculty::create([
                            'name' => $rawFaculty,
                            'designation' => 'Faculty Member',
                            'department' => 'General Studies',
                            'status' => 'active',
                        ]);
                    }

                    $schedule->faculties()->sync([$faculty->id => ['role' => 'lead_faculty']]);
                }

                $importedCount++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'class_schedules_imported',
                description: "Imported {$importedCount} class schedules via file",
                newValues: ['imported' => $importedCount, 'errors_count' => count($errors)],
                userId: auth()->id()
            );

            return [
                'imported' => $importedCount,
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'imported' => 0,
                'errors' => ['Import failed: '.$e->getMessage()],
            ];
        }
    }

    /**
     * Compile Faculty Teaching Workload Statistics.
     */
    public static function getFacultyWorkloadData(array $filters = []): array
    {
        $facultyQuery = Faculty::query()->with('user');

        if (! empty($filters['faculty_id'])) {
            $facultyQuery->where('id', $filters['faculty_id']);
        }
        if (! empty($filters['department'])) {
            $facultyQuery->where('department', $filters['department']);
        }

        $faculties = $facultyQuery->orderBy('name')->get();
        $reportRows = [];

        foreach ($faculties as $faculty) {
            $scheduleQuery = ClassSchedule::whereHas('faculties', function ($q) use ($faculty) {
                $q->where('faculties.id', $faculty->id);
            });

            if (! empty($filters['batch_id'])) {
                $scheduleQuery->where('batch_id', $filters['batch_id']);
            }
            if (! empty($filters['course_id'])) {
                $scheduleQuery->where('course_id', $filters['course_id']);
            }
            if (! empty($filters['start_date'])) {
                $scheduleQuery->whereDate('class_date', '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $scheduleQuery->whereDate('class_date', '<=', $filters['end_date']);
            }

            $schedules = $scheduleQuery->get();

            $totalAssigned = $schedules->count();
            $conducted = $schedules->where('status', 'completed')->count();
            $scheduled = $schedules->whereIn('status', ['scheduled', 'in_progress'])->count();
            $cancelled = $schedules->where('status', 'cancelled')->count();

            // Total hours calculated from conducted classes only
            $totalMinutes = $schedules->where('status', 'completed')->sum(fn ($s) => $s->duration_minutes);
            $totalHours = round($totalMinutes / 60, 2);

            $completionRate = $totalAssigned > 0 ? round(($conducted / max(1, $conducted + $scheduled)) * 100, 1) : 0;

            $reportRows[] = [
                'faculty_id' => $faculty->id,
                'faculty_code' => $faculty->faculty_code,
                'name' => $faculty->name,
                'department' => $faculty->department ?: 'N/A',
                'designation' => $faculty->designation ?: 'Faculty',
                'total_assigned' => $totalAssigned,
                'conducted' => $conducted,
                'scheduled' => $scheduled,
                'cancelled' => $cancelled,
                'total_hours' => $totalHours,
                'completion_rate' => $completionRate,
            ];
        }

        return $reportRows;
    }

    /**
     * Compile Course Syllabus Routine Coverage Statistics.
     */
    public static function getCourseCoverageData(array $filters = []): array
    {
        $planQuery = CoursePlan::with(['batch', 'course', 'section']);

        if (! empty($filters['batch_id'])) {
            $planQuery->where('batch_id', $filters['batch_id']);
        }
        if (! empty($filters['course_id'])) {
            $planQuery->where('course_id', $filters['course_id']);
        }

        $plans = $planQuery->orderBy('title')->get();
        $reportRows = [];

        foreach ($plans as $plan) {
            $conducted = $plan->conducted_lectures_count;
            $scheduled = $plan->scheduled_lectures_count;
            $cancelled = $plan->cancelled_lectures_count;
            $planned = $plan->total_lectures_planned;
            $coverage = $plan->progress_percentage;
            $remaining = $plan->remaining_lectures_to_schedule;

            $reportRows[] = [
                'plan_code' => $plan->plan_code,
                'title' => $plan->title,
                'batch_name' => $plan->batch?->name ?? 'N/A',
                'course_name' => $plan->course?->name ?? 'N/A',
                'section_name' => $plan->section?->name ?? 'All Sections',
                'plan_type' => ucfirst($plan->plan_type),
                'planned_lectures' => $planned,
                'conducted' => $conducted,
                'scheduled' => $scheduled,
                'cancelled' => $cancelled,
                'remaining' => $remaining,
                'coverage_percent' => $coverage,
            ];
        }

        return $reportRows;
    }

    /**
     * Compile Classroom Utilization Statistics.
     */
    public static function getClassroomUtilizationData(array $filters = []): array
    {
        $rooms = Classroom::query()->orderBy('building')->orderBy('name')->get();
        $reportRows = [];

        foreach ($rooms as $room) {
            $scheduleQuery = ClassSchedule::where('classroom_id', $room->id);

            if (! empty($filters['start_date'])) {
                $scheduleQuery->whereDate('class_date', '>=', $filters['start_date']);
            }
            if (! empty($filters['end_date'])) {
                $scheduleQuery->whereDate('class_date', '<=', $filters['end_date']);
            }

            $schedules = $scheduleQuery->get();

            $conducted = $schedules->where('status', 'completed')->count();
            $scheduled = $schedules->whereIn('status', ['scheduled', 'in_progress'])->count();
            $cancelled = $schedules->where('status', 'cancelled')->count();
            $totalMinutes = $schedules->where('status', 'completed')->sum(fn ($s) => $s->duration_minutes);
            $totalHours = round($totalMinutes / 60, 2);

            $reportRows[] = [
                'room_code' => $room->room_code,
                'name' => $room->name,
                'building' => $room->building,
                'floor' => $room->floor,
                'capacity' => $room->capacity ?: 'N/A',
                'status' => ucfirst($room->status),
                'conducted_sessions' => $conducted,
                'scheduled_sessions' => $scheduled,
                'cancelled_sessions' => $cancelled,
                'total_hours_used' => $totalHours,
            ];
        }

        return $reportRows;
    }

    /**
     * Export any routine report to Excel, CSV, or PDF.
     */
    public static function exportReport(string $reportType, array|string $filters = [], string $format = 'xlsx'): BinaryFileResponse|StreamedResponse|Response
    {
        if (is_string($filters)) {
            $format = $filters;
            $filters = [];
        }
        if ($reportType === 'faculty_workload') {
            $data = self::getFacultyWorkloadData($filters);
            $headings = [
                'Faculty Code',
                'Faculty Name',
                'Department',
                'Designation',
                'Total Assigned',
                'Conducted Classes',
                'Upcoming Scheduled',
                'Cancelled Classes',
                'Total Hours Taught',
                'Completion Rate (%)',
            ];
            $rows = array_map(function ($row) {
                return [
                    $row['faculty_code'],
                    $row['name'],
                    $row['department'],
                    $row['designation'],
                    $row['total_assigned'],
                    $row['conducted'],
                    $row['scheduled'],
                    $row['cancelled'],
                    $row['total_hours'],
                    $row['completion_rate'].'%',
                ];
            }, $data);

            return TableExporter::downloadArray($rows, $format, 'faculty-workload-report', $headings, 'Faculty Workload & Teaching Report');
        }

        if ($reportType === 'course_coverage') {
            $data = self::getCourseCoverageData($filters);
            $headings = [
                'Plan Code',
                'Plan Title',
                'Batch',
                'Course',
                'Section',
                'Type',
                'Planned Quota',
                'Conducted',
                'Scheduled',
                'Cancelled',
                'Remaining',
                'Syllabus Coverage (%)',
            ];
            $rows = array_map(function ($row) {
                return [
                    $row['plan_code'],
                    $row['title'],
                    $row['batch_name'],
                    $row['course_name'],
                    $row['section_name'],
                    $row['plan_type'],
                    $row['planned_lectures'],
                    $row['conducted'],
                    $row['scheduled'],
                    $row['cancelled'],
                    $row['remaining'],
                    $row['coverage_percent'].'%',
                ];
            }, $data);

            return TableExporter::downloadArray($rows, $format, 'course-routine-coverage-report', $headings, 'Course Routine & Syllabus Coverage Report');
        }

        // Default: classroom utilization
        $data = self::getClassroomUtilizationData($filters);
        $headings = [
            'Room Code',
            'Classroom Name',
            'Building',
            'Floor',
            'Capacity',
            'Status',
            'Conducted Sessions',
            'Scheduled Sessions',
            'Cancelled Sessions',
            'Total Hours Used',
        ];
        $rows = array_map(function ($row) {
            return [
                $row['room_code'],
                $row['name'],
                $row['building'],
                $row['floor'],
                $row['capacity'],
                $row['status'],
                $row['conducted_sessions'],
                $row['scheduled_sessions'],
                $row['cancelled_sessions'],
                $row['total_hours_used'],
            ];
        }, $data);

        return TableExporter::downloadArray($rows, $format, 'classroom-utilization-report', $headings, 'Classroom Utilization & Occupancy Report');
    }
}
