<?php

namespace App\Services\Test;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\User;
use App\Services\Library\BarcodeService;
use App\Support\Export\TableExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestImportExportService
{
    public function __construct(
        protected TestScoreCalculatorService $calculatorService
    ) {}

    /**
     * Download individual student marksheet PDF.
     */
    public function downloadMarksheetPdf(StudentTestResult $result): StreamedResponse
    {
        $result->load(['test.batch', 'test.course', 'test.section', 'test.subjects', 'user.studentProfile', 'section', 'evaluator']);
        $test = $result->test;
        $student = $result->user;

        // Generate QR code data URI
        $qrContent = sprintf(
            "SNTCSSC VERIFIED MARKSHEET\nExam: %s (%s)\nCandidate: %s\nRoll: %s\nMarks: %s / %s\nRank: %s\nDate: %s\nVerify: %s",
            $test->title,
            $test->code,
            $student->name,
            $result->roll_number ?: 'N/A',
            $result->marks_obtained,
            $result->total_marks,
            $result->rank ? "#{$result->rank}" : 'N/A',
            $test->test_date->format('d/m/Y'),
            route('student.tests.index')
        );

        $qrDataUri = null;
        try {
            if (class_exists(BarcodeService::class)) {
                $qrDataUri = BarcodeService::generateQrCodeDataUri($qrContent, 4);
            }
        } catch (\Throwable) {
            $qrDataUri = null;
        }

        $pdf = Pdf::loadView('exports.marksheet-pdf', [
            'result' => $result,
            'test' => $test,
            'student' => $student,
            'qrDataUri' => $qrDataUri,
            'generatedAt' => now(),
        ]);

        $pdf->setPaper('a4', 'portrait');

        $safeName = str($student->name)->slug();
        $safeCode = str($test->code)->slug();
        $filename = "marksheet-{$safeCode}-{$safeName}.pdf";

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Export test-wise results as CSV, Excel, or PDF.
     */
    public function exportTestResults(StudentTest $test, string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $format = strtolower($format);
        $test->load(['batch', 'course', 'section']);
        $results = $test->results()
            ->with(['user', 'section', 'evaluator'])
            ->orderByRaw('CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END, `rank` ASC, marks_obtained DESC')
            ->get();

        $filename = "test-results-{$test->code}";

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.test-report-pdf', [
                'title' => "Examination Results: {$test->title} ({$test->code})",
                'subtitle' => "Batch: {$test->batch?->name} | Course: {$test->course?->name} | Date: {$test->test_date->format('d M Y')}",
                'test' => $test,
                'results' => $results,
                'generatedAt' => now(),
            ]);

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                "{$filename}-".now()->format('Ymd-His').'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        $headers = [
            'Rank',
            'Roll Number',
            'Student Name',
            'Email',
            'Section',
            'Attendance',
            'Right Answers',
            'Wrong Answers',
            'Blank Answers',
            'Marks Obtained',
            'Total Marks',
            'Percentage (%)',
            'Percentile',
            'Result Status',
            'Remarks',
            'Feedback',
            'Evaluated By',
            'Evaluated At',
        ];

        $rows = $results->map(fn ($res) => [
            $res->rank ? "#{$res->rank}" : 'N/A',
            $res->roll_number ?? 'N/A',
            $res->user?->name ?? 'N/A',
            $res->user?->email ?? 'N/A',
            $res->section?->name ?? 'Default',
            strtoupper((string) $res->attendance_status),
            $res->right_answers,
            $res->wrong_answers,
            $res->blank_answers,
            $res->marks_obtained,
            $res->total_marks,
            $res->percentage.'%',
            $res->percentile !== null ? $res->percentile.'%' : 'N/A',
            $res->is_passed ? 'PASSED' : 'NEEDS IMPROVEMENT',
            $res->remarks ?? '',
            $res->feedback ?? '',
            $res->evaluator?->name ?? ($res->evaluated_by ? "User #{$res->evaluated_by}" : ''),
            $res->evaluated_at ? $res->evaluated_at->format('Y-m-d H:i') : '',
        ])->all();

        return TableExporter::downloadArray(
            rows: $rows,
            format: $format,
            filename: $filename,
            headings: $headers,
            title: "Examination Results - {$test->title} ({$test->code})"
        );
    }

    /**
     * Export student-wise overall dossier across all tests as CSV, Excel, or PDF.
     */
    public function exportStudentDossier(User $student, array $dossierData, string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $format = strtolower($format);
        $cleanName = str($student->name)->slug();
        $filename = "student-dossier-{$cleanName}";

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.student-dossier-pdf', [
                'student' => $student,
                'enrollment' => $dossierData['enrollment'] ?? null,
                'dossier' => $dossierData,
                'generatedAt' => now(),
            ]);

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                "{$filename}-".now()->format('Ymd-His').'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        $headers = [
            'Test Date',
            'Test Code',
            'Examination Title',
            'Stage',
            'Type',
            'Attendance',
            'Marks Obtained',
            'Total Marks',
            'Percentage (%)',
            'Merit Rank',
            'Percentile',
            'Result Status',
            'Remarks',
            'Feedback',
            'Evaluated By',
            'Evaluated At',
        ];

        $rows = collect($dossierData['results'] ?? [])->map(fn ($res) => [
            $res->test?->test_date?->format('Y-m-d') ?? 'N/A',
            $res->test?->code ?? 'N/A',
            $res->test?->title ?? 'N/A',
            strtoupper((string) ($res->test?->test_stage ?? '')),
            strtoupper((string) ($res->test?->test_type ?? '')),
            strtoupper((string) $res->attendance_status),
            $res->marks_obtained,
            $res->total_marks,
            $res->percentage.'%',
            $res->rank ? "#{$res->rank}" : 'N/A',
            $res->percentile !== null ? $res->percentile.'%' : 'N/A',
            $res->is_passed ? 'PASSED' : 'REMEDIAL',
            $res->remarks ?? '',
            $res->feedback ?? '',
            $res->evaluator?->name ?? ($res->evaluated_by ? "User #{$res->evaluated_by}" : ''),
            $res->evaluated_at ? $res->evaluated_at->format('Y-m-d H:i') : '',
        ])->all();

        return TableExporter::downloadArray(
            rows: $rows,
            format: $format,
            filename: $filename,
            headings: $headers,
            title: "Student Examination Dossier - {$student->name}"
        );
    }

    /**
     * Export attendance & participation audit as CSV, Excel, or PDF.
     */
    public function exportParticipationAudit(array $data, string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $format = strtolower($format);
        $filename = 'participation-analytics-audit';

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.participation-audit-pdf', [
                'data' => $data,
                'generatedAt' => now(),
            ]);

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                "{$filename}-".now()->format('Ymd-His').'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        $headers = [
            'Category',
            'Roll Number',
            'Student Name',
            'Email',
            'Phone',
            'Course',
            'Batch',
            'Section',
            'Attended Tests',
            'Available Tests',
            'Attendance Rate (%)',
            'Average Marks',
        ];

        $rows = [];

        foreach ($data['participated_all'] as $item) {
            $rows[] = [
                '100% ATTENDANCE CHAMPION',
                $item['enrollment']->roll_no ?: $item['user']->student_id,
                $item['user']->name,
                $item['user']->email,
                $item['user']->phone ?? '',
                $item['enrollment']->course?->name ?? '',
                $item['enrollment']->batch?->name ?? '',
                $item['enrollment']->section?->name ?? 'Default',
                $item['attended_tests'],
                $item['available_tests'],
                $item['participation_rate'].'%',
                $item['avg_marks'],
            ];
        }

        foreach ($data['participated_none'] as $item) {
            $rows[] = [
                'CHRONIC ABSENTEE (0%)',
                $item['enrollment']->roll_no ?: $item['user']->student_id,
                $item['user']->name,
                $item['user']->email,
                $item['user']->phone ?? '',
                $item['enrollment']->course?->name ?? '',
                $item['enrollment']->batch?->name ?? '',
                $item['enrollment']->section?->name ?? 'Default',
                0,
                $item['available_tests'],
                '0.0%',
                0.00,
            ];
        }

        return TableExporter::downloadArray(
            rows: $rows,
            format: $format,
            filename: $filename,
            headings: $headers,
            title: 'Attendance & Participation Audit Report'
        );
    }

    /**
     * Export section comparison statistics as CSV, Excel, or PDF.
     */
    public function exportSectionComparison(Collection|array $sectionsData, string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $format = strtolower($format);
        $filename = 'sections-performance-comparison';
        $sections = is_array($sectionsData) ? $sectionsData : $sectionsData->all();

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.section-comparison-pdf', [
                'sections' => $sections,
                'generatedAt' => now(),
            ]);

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                "{$filename}-".now()->format('Ymd-His').'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        $headers = [
            'Section Name',
            'Classroom Code',
            'Enrolled Students',
            'Tests Held',
            'Average Score',
            'Average Percentage (%)',
            'Highest Recorded Score',
        ];

        $rows = collect($sections)->map(fn ($sec) => [
            $sec['section']->name,
            $sec['section']->code,
            $sec['student_count'],
            $sec['tests_count'],
            $sec['avg_marks'],
            $sec['avg_percentage'].'%',
            $sec['highest_marks'],
        ])->all();

        return TableExporter::downloadArray(
            rows: $rows,
            format: $format,
            filename: $filename,
            headings: $headers,
            title: 'Batch & Section Comparative Performance Analysis'
        );
    }

    /**
     * Generate pre-filled sample CSV or Excel template for candidate score entry.
     */
    public function generateSampleTemplate(StudentTest $test, string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $test->load(['batch', 'course', 'section']);

        $query = Enrollment::where('batch_id', $test->batch_id)
            ->where('course_id', $test->course_id)
            ->where('status', 'active');

        if ($test->section_id) {
            $query->where('section_id', $test->section_id);
        }

        $enrollments = $query->with('user')->get();

        $filename = "sample-score-entry-{$test->code}";
        $headers = [
            'roll_number',
            'student_name',
            'email',
            'attendance_status', // present / absent
            'right_answers',
            'wrong_answers',
            'blank_answers',
            'marks_obtained',
            'remarks',
            'feedback',
            'evaluated_by',
            'evaluated_at',
        ];

        $rows = [];
        $evaluatorIdentity = auth()->user()?->email ?: 'evaluator@sntcssc.in';
        $nowTimestamp = now()->format('Y-m-d H:i');

        foreach ($enrollments as $enr) {
            $user = $enr->user;
            $rows[] = [
                $enr->roll_no ?: ($user?->student_id ?? ''),
                $user?->name ?? '',
                $user?->email ?? '',
                'present',
                0,
                0,
                $test->total_questions,
                0.00,
                'Good analytical attempt',
                'Focus on CSAT reasoning speed and accuracy',
                $evaluatorIdentity,
                $nowTimestamp,
            ];
        }

        if (empty($rows)) {
            $rows[] = [
                'ROLL-001',
                'Sample Candidate',
                'student@example.com',
                'present',
                45,
                15,
                $test->total_questions > 60 ? $test->total_questions - 60 : 20,
                80.10,
                'Strong GS fundamentals',
                'Review negative marking penalties in Paper I',
                $evaluatorIdentity,
                $nowTimestamp,
            ];
        }

        return TableExporter::downloadArray(
            rows: $rows,
            format: $format,
            filename: $filename,
            headings: $headers,
            title: "Score Entry Template - {$test->code}"
        );
    }

    /**
     * Import candidate scores from CSV or Excel file (.csv, .xlsx, .xls).
     */
    public function importScores(StudentTest $test, UploadedFile $file): array
    {
        $filePath = $file->getRealPath();
        $ext = strtolower($file->getClientOriginalExtension());
        $rows = [];

        try {
            if ($ext === 'csv' || str_ends_with(strtolower($filePath), '.csv')) {
                $rawLines = array_map('str_getcsv', file($filePath));
                if (! empty($rawLines)) {
                    $rawHeader = array_shift($rawLines);
                    $headers = array_map(fn ($h) => Str::snake(trim((string) $h)), $rawHeader);
                    foreach ($rawLines as $row) {
                        if (empty(array_filter($row))) {
                            continue;
                        }
                        $rowMap = [];
                        foreach ($headers as $index => $key) {
                            $rowMap[$key] = $row[$index] ?? null;
                        }
                        $rows[] = $rowMap;
                    }
                }
            } else {
                // Parse Excel file (.xlsx, .xls) using Maatwebsite Excel
                $excelData = Excel::toArray([], $filePath);
                if (! empty($excelData[0])) {
                    $rawRows = $excelData[0];
                    $rawHeader = array_shift($rawRows);
                    $headers = array_map(fn ($h) => Str::snake(trim((string) $h)), $rawHeader);
                    foreach ($rawRows as $row) {
                        if (empty(array_filter($row))) {
                            continue;
                        }
                        $rowMap = [];
                        foreach ($headers as $index => $key) {
                            $rowMap[$key] = $row[$index] ?? null;
                        }
                        $rows[] = $rowMap;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to read file: '.$e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'errors' => ['File parsing error: '.$e->getMessage()],
            ];
        }

        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'The uploaded file is empty or does not contain valid candidate data rows.',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['File format error: empty data rows.'],
            ];
        }

        $imported = 0;
        $updated = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $idx => $rowAssoc) {
                $rowNum = $idx + 2;

                $roll = trim((string) ($rowAssoc['roll_number'] ?? $rowAssoc['roll_no'] ?? ''));
                $email = trim((string) ($rowAssoc['email'] ?? ''));

                if (empty($roll) && empty($email)) {
                    $errors[] = "Row #{$rowNum}: Neither roll_number nor email was specified.";

                    continue;
                }

                // Match student user
                $user = null;
                if (! empty($roll)) {
                    $user = User::where('student_id', $roll)->first();
                    if (! $user) {
                        $enrollment = Enrollment::where('roll_no', $roll)
                            ->where('batch_id', $test->batch_id)
                            ->first();
                        $user = $enrollment?->user;
                    }
                }
                if (! $user && ! empty($email)) {
                    $user = User::where('email', $email)->first();
                }

                if (! $user) {
                    $errors[] = "Row #{$rowNum}: Could not find enrolled candidate matching roll '{$roll}' or email '{$email}'.";

                    continue;
                }

                $attendance = strtolower(trim((string) ($rowAssoc['attendance_status'] ?? 'present')));
                if (! in_array($attendance, ['present', 'absent', 'exempted', 'disqualified'], true)) {
                    $attendance = 'present';
                }

                $right = isset($rowAssoc['right_answers']) ? (int) $rowAssoc['right_answers'] : 0;
                $wrong = isset($rowAssoc['wrong_answers']) ? (int) $rowAssoc['wrong_answers'] : 0;
                $blank = isset($rowAssoc['blank_answers']) ? (int) $rowAssoc['blank_answers'] : max(0, $test->total_questions - ($right + $wrong));
                $marksObtained = isset($rowAssoc['marks_obtained']) ? (float) $rowAssoc['marks_obtained'] : 0.00;

                // Dedicated fields: remarks, feedback, evaluated_by, evaluated_at
                $remarks = trim((string) ($rowAssoc['remarks'] ?? ''));
                $feedback = trim((string) ($rowAssoc['feedback'] ?? ''));
                $evaluatedByRaw = trim((string) ($rowAssoc['evaluated_by'] ?? ''));
                $evaluatedAtRaw = trim((string) ($rowAssoc['evaluated_at'] ?? ''));

                // Resolve evaluator user
                $evaluatorId = auth()->id();
                if (! empty($evaluatedByRaw)) {
                    if (is_numeric($evaluatedByRaw)) {
                        $evalUser = User::find((int) $evaluatedByRaw);
                        if ($evalUser) {
                            $evaluatorId = $evalUser->id;
                        }
                    } elseif (filter_var($evaluatedByRaw, FILTER_VALIDATE_EMAIL)) {
                        $evalUser = User::where('email', $evaluatedByRaw)->first();
                        if ($evalUser) {
                            $evaluatorId = $evalUser->id;
                        }
                    } else {
                        $evalUser = User::where('name', 'like', "%{$evaluatedByRaw}%")->first();
                        if ($evalUser) {
                            $evaluatorId = $evalUser->id;
                        }
                    }
                }

                // Resolve evaluation timestamp
                $evaluatedAt = now();
                if (! empty($evaluatedAtRaw)) {
                    try {
                        $evaluatedAt = Carbon::parse($evaluatedAtRaw);
                    } catch (\Throwable) {
                        $evaluatedAt = now();
                    }
                }

                $result = StudentTestResult::firstOrNew([
                    'student_test_id' => $test->id,
                    'user_id' => $user->id,
                ]);

                $isNew = ! $result->exists;

                $result->roll_number = $roll ?: ($user->student_id ?: $result->roll_number);
                $result->attendance_status = $attendance;
                $result->total_questions = $test->total_questions;
                $result->total_marks = $test->total_marks;
                $result->right_answers = $right;
                $result->wrong_answers = $wrong;
                $result->blank_answers = $blank;
                $result->remarks = $remarks ?: null;
                $result->feedback = $feedback ?: null;
                $result->evaluated_by = $evaluatorId;
                $result->evaluated_at = $evaluatedAt;

                if ($attendance === 'present') {
                    if ($test->isMcq()) {
                        $result->computeScores($test);
                    } else {
                        $result->marks_obtained = $marksObtained;
                        $result->percentage = $test->total_marks > 0
                            ? round(($marksObtained / $test->total_marks) * 100, 2)
                            : 0.00;
                        $result->is_passed = ($test->pass_marks === null || $marksObtained >= (float) $test->pass_marks);
                    }
                } else {
                    $result->marks_obtained = 0.00;
                    $result->percentage = 0.00;
                    $result->rank = null;
                    $result->is_passed = false;
                }

                $result->save();

                if ($isNew) {
                    $imported++;
                } else {
                    $updated++;
                }
            }

            // Recalculate ranks across present students
            $this->calculatorService->recalculateAndRank($test);

            DB::commit();

            AuditLog::record(
                action: 'test.scores_imported',
                details: [
                    'test_id' => $test->id,
                    'imported_count' => $imported,
                    'updated_count' => $updated,
                    'errors_count' => count($errors),
                ],
                auditableType: StudentTest::class,
                auditableId: $test->id,
                description: "Imported candidate scores for {$test->code}: {$imported} imported, {$updated} updated, ".count($errors).' errors.'
            );

            return [
                'success' => true,
                'message' => "Candidate scores successfully processed: {$imported} new imported, {$updated} updated.",
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Import failed: '.$e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'errors' => [$e->getMessage()],
            ];
        }
    }
}
