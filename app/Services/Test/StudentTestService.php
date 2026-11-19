<?php

namespace App\Services\Test;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Services\EmailService;
use App\Services\FileUploadService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentTestService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected WhatsAppService $whatsAppService,
        protected SmsService $smsService,
        protected EmailService $emailService
    ) {}

    /**
     * Create a new student mock test / examination.
     */
    public function createTest(
        array $data,
        ?array $subjects = null,
        ?UploadedFile $questionPaper = null,
        ?UploadedFile $modelAnswer = null
    ): StudentTest {
        return DB::transaction(function () use ($data, $subjects, $questionPaper, $modelAnswer) {
            // Upload files if provided
            if ($questionPaper) {
                $paperPath = FileUploadService::store($questionPaper, 'tests/question_papers', 'paper');
                $data['question_paper_path'] = $paperPath;
                $data['question_paper_name'] = $questionPaper->getClientOriginalName();
            }

            if ($modelAnswer) {
                $answerPath = FileUploadService::store($modelAnswer, 'tests/model_answers', 'answer');
                $data['model_answer_path'] = $answerPath;
                $data['model_answer_name'] = $modelAnswer->getClientOriginalName();
            }

            // Derive test day from test date
            if (! empty($data['test_date']) && empty($data['test_day'])) {
                $data['test_day'] = Carbon::parse($data['test_date'])->format('l');
            }

            $test = StudentTest::create($data);

            // Create assigned subjects if provided
            if (! empty($subjects)) {
                $order = 1;
                $totalQ = 0;
                $totalM = 0;

                foreach ($subjects as $subj) {
                    if (empty($subj['subject_name'])) {
                        continue;
                    }

                    $qCount = (int) ($subj['questions_count'] ?? 0);
                    $mPerQ = (float) ($subj['marks_per_question'] ?? $test->marks_per_question ?? 2.0);
                    $negM = (float) ($subj['negative_marking'] ?? round($mPerQ * ($test->negative_marking_ratio ?? 0.33), 2));
                    $maxM = (float) ($subj['max_marks'] ?? ($qCount * $mPerQ));

                    $test->subjects()->create([
                        'subject_name' => $subj['subject_name'],
                        'subject_code' => $subj['subject_code'] ?? null,
                        'questions_count' => $qCount,
                        'max_marks' => $maxM,
                        'marks_per_question' => $mPerQ,
                        'negative_marking' => $negM,
                        'display_order' => $order++,
                    ]);

                    $totalQ += $qCount;
                    $totalM += $maxM;
                }

                // If composite subjects defined questions and marks, update test totals
                if ($totalQ > 0 && ($test->total_questions == 0 || empty($data['total_questions']))) {
                    $test->total_questions = $totalQ;
                }
                if ($totalM > 0 && ($test->total_marks == 0 || empty($data['total_marks']))) {
                    $test->total_marks = $totalM;
                }
                $test->saveQuietly();
            }

            // Automatically initialize roster results for enrolled students in this batch/course/section
            $this->initializeRosterForTest($test);

            // Notify students if requested
            if (! empty($test->notify_students)) {
                $this->notifyEnrolledStudents($test);
            }

            AuditLog::record(
                action: 'test.created',
                details: [
                    'test_id' => $test->id,
                    'code' => $test->code,
                    'title' => $test->title,
                    'batch_id' => $test->batch_id,
                    'course_id' => $test->course_id,
                    'section_id' => $test->section_id,
                    'stage' => $test->test_stage,
                    'type' => $test->test_type,
                    'test_date' => $test->test_date->format('Y-m-d'),
                ],
                auditableType: StudentTest::class,
                auditableId: $test->id,
                description: "Created examination {$test->code}: {$test->title} ({$test->test_stage} - {$test->test_type})"
            );

            return $test;
        });
    }

    /**
     * Update an existing test.
     */
    public function updateTest(
        StudentTest $test,
        array $data,
        ?array $subjects = null,
        ?UploadedFile $questionPaper = null,
        ?UploadedFile $modelAnswer = null
    ): StudentTest {
        return DB::transaction(function () use ($test, $data, $subjects, $questionPaper, $modelAnswer) {
            if ($questionPaper) {
                $paperPath = FileUploadService::store(
                    $questionPaper,
                    'tests/question_papers',
                    'paper',
                    'public',
                    $test->question_paper_path
                );
                $data['question_paper_path'] = $paperPath;
                $data['question_paper_name'] = $questionPaper->getClientOriginalName();
            }

            if ($modelAnswer) {
                $answerPath = FileUploadService::store(
                    $modelAnswer,
                    'tests/model_answers',
                    'answer',
                    'public',
                    $test->model_answer_path
                );
                $data['model_answer_path'] = $answerPath;
                $data['model_answer_name'] = $modelAnswer->getClientOriginalName();
            }

            if (! empty($data['test_date']) && empty($data['test_day'])) {
                $data['test_day'] = Carbon::parse($data['test_date'])->format('l');
            }

            $test->update($data);

            if ($subjects !== null) {
                $test->subjects()->delete();
                $order = 1;
                $totalQ = 0;
                $totalM = 0;

                foreach ($subjects as $subj) {
                    if (empty($subj['subject_name'])) {
                        continue;
                    }
                    $qCount = (int) ($subj['questions_count'] ?? 0);
                    $mPerQ = (float) ($subj['marks_per_question'] ?? $test->marks_per_question ?? 2.0);
                    $negM = (float) ($subj['negative_marking'] ?? round($mPerQ * ($test->negative_marking_ratio ?? 0.33), 2));
                    $maxM = (float) ($subj['max_marks'] ?? ($qCount * $mPerQ));

                    $test->subjects()->create([
                        'subject_name' => $subj['subject_name'],
                        'subject_code' => $subj['subject_code'] ?? null,
                        'questions_count' => $qCount,
                        'max_marks' => $maxM,
                        'marks_per_question' => $mPerQ,
                        'negative_marking' => $negM,
                        'display_order' => $order++,
                    ]);

                    $totalQ += $qCount;
                    $totalM += $maxM;
                }

                if ($totalQ > 0 && empty($data['total_questions'])) {
                    $test->total_questions = $totalQ;
                }
                if ($totalM > 0 && empty($data['total_marks'])) {
                    $test->total_marks = $totalM;
                }
                $test->saveQuietly();
            }

            // Sync any newly enrolled students into test roster
            $this->initializeRosterForTest($test);

            // If notifications requested and not yet sent
            if (! empty($test->notify_students) && ! $test->notification_sent_at) {
                $this->notifyEnrolledStudents($test);
            }

            AuditLog::record(
                action: 'test.updated',
                details: [
                    'test_id' => $test->id,
                    'code' => $test->code,
                    'title' => $test->title,
                ],
                auditableType: StudentTest::class,
                auditableId: $test->id,
                description: "Updated examination {$test->code}: {$test->title}"
            );

            return $test;
        });
    }

    /**
     * Soft delete a test and its result records.
     */
    public function deleteTest(StudentTest $test): bool
    {
        return DB::transaction(function () use ($test) {
            $code = $test->code;
            $title = $test->title;
            $id = $test->id;

            $test->results()->delete();
            $deleted = (bool) $test->delete();

            AuditLog::record(
                action: 'test.deleted',
                details: ['test_id' => $id, 'code' => $code, 'title' => $title],
                auditableType: StudentTest::class,
                auditableId: $id,
                description: "Deleted examination {$code}: {$title}"
            );

            return $deleted;
        });
    }

    /**
     * Initialize roster records for active enrolled students for the test.
     */
    public function initializeRosterForTest(StudentTest $test): int
    {
        $query = Enrollment::where('batch_id', $test->batch_id)
            ->where('course_id', $test->course_id)
            ->where('status', 'active');

        if ($test->section_id) {
            $query->where('section_id', $test->section_id);
        }

        $enrollments = $query->with('user')->get();
        $created = 0;

        foreach ($enrollments as $enrollment) {
            if (! $enrollment->user_id) {
                continue;
            }

            $exists = StudentTestResult::where('student_test_id', $test->id)
                ->where('user_id', $enrollment->user_id)
                ->exists();

            if (! $exists) {
                StudentTestResult::create([
                    'student_test_id' => $test->id,
                    'user_id' => $enrollment->user_id,
                    'enrollment_id' => $enrollment->id,
                    'section_id' => $enrollment->section_id ?? $test->section_id,
                    'roll_number' => $enrollment->roll_no ?: ($enrollment->user?->student_id ?? null),
                    'attendance_status' => StudentTestResult::STATUS_PRESENT,
                    'total_questions' => $test->total_questions,
                    'total_marks' => $test->total_marks,
                    'attempted' => 0,
                    'right_answers' => 0,
                    'wrong_answers' => 0,
                    'blank_answers' => $test->total_questions,
                    'marks_obtained' => 0.00,
                    'percentage' => 0.00,
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Dispatch multi-channel notifications to all enrolled students for this test.
     */
    public function notifyEnrolledStudents(StudentTest $test): int
    {
        $channels = $test->notification_channels ?: ['in_app', 'email', 'whatsapp'];

        $query = Enrollment::where('batch_id', $test->batch_id)
            ->where('course_id', $test->course_id)
            ->where('status', 'active');

        if ($test->section_id) {
            $query->where('section_id', $test->section_id);
        }

        $enrollments = $query->with('user')->get();
        $notifiedCount = 0;

        $dateFormatted = $test->test_date->format('d M Y');
        $timingFormatted = ($test->start_time ? $test->start_time : '').($test->end_time ? ' - '.$test->end_time : '');
        $title = "Scheduled Exam: {$test->title} ({$test->code})";
        $message = "Dear Student, your {$test->test_for} ({$test->test_stage}) is scheduled on {$dateFormatted} ({$test->test_day}) at {$timingFormatted}. Mode: ".strtoupper($test->mode).($test->venue_room ? " ({$test->venue_room})" : '').". Max Marks: {$test->total_marks}. Please prepare well.";

        foreach ($enrollments as $enrollment) {
            $user = $enrollment->user;
            if (! $user) {
                continue;
            }

            try {
                // 1. In-app notification
                if (in_array('in_app', $channels, true)) {
                    $this->notificationService->sendInternal(
                        $user,
                        'exam_scheduled',
                        $title,
                        $message,
                        route('student.tests.index'),
                        'View Exam Details'
                    );
                }

                // 2. Email notification
                if (in_array('email', $channels, true) && ! empty($user->email)) {
                    $this->emailService->send(
                        to: $user->email,
                        subject: "SNTCSSC Examination Notice: {$test->title}",
                        html: "<p>{$message}</p><p>Visit your student dashboard to review the schedule and syllabus.</p>"
                    );
                }

                // 3. WhatsApp notification
                if (in_array('whatsapp', $channels, true) && ! empty($user->phone)) {
                    $this->whatsAppService->send(
                        to: $user->phone,
                        message: "🏛️ *SNTCSSC Examination Schedule*\n\n{$title}\n\n📅 Date: {$dateFormatted} ({$test->test_day})\n⏰ Time: {$timingFormatted}\n📝 Type: {$test->test_type} ({$test->test_stage})\n🎯 Total Marks: {$test->total_marks}\n📍 Mode/Venue: ".strtoupper($test->mode).($test->venue_room ? " - {$test->venue_room}" : '')."\n\nAll the best for your preparation!"
                    );
                }

                // 4. SMS notification
                if (in_array('sms', $channels, true) && ! empty($user->phone)) {
                    $this->smsService->send(
                        to: $user->phone,
                        message: "SNTCSSC Exam Notice: {$test->title} is scheduled on {$dateFormatted} {$timingFormatted}. Please check your student portal for instructions."
                    );
                }

                $notifiedCount++;
            } catch (\Throwable $e) {
                Log::warning("Failed to dispatch test notification to user {$user->id}: ".$e->getMessage());
            }
        }

        $test->update(['notification_sent_at' => now()]);

        return $notifiedCount;
    }
}
