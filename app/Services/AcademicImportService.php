<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchCourse;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Models\Section;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AcademicImportService
{
    /**
     * Helper to load rows from UploadedFile or string path.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function loadRows(string|UploadedFile $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        return $worksheet->toArray(null, true, true, true);
    }

    // =========================================================================
    // 1. COURSES IMPORT
    // =========================================================================

    /**
     * Import courses from spreadsheet.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importCourses(string|UploadedFile $file): array
    {
        $rows = static::loadRows($file);
        if (count($rows) <= 1) {
            return ['imported' => 0, 'errors' => ['Spreadsheet contains no data rows.']];
        }

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            $isFirst = true;
            foreach ($rows as $rowIndex => $row) {
                if ($isFirst) {
                    $isFirst = false;

                    continue; // Skip header
                }

                $code = strtoupper(trim((string) ($row['A'] ?? '')));
                $name = trim((string) ($row['B'] ?? ''));
                $category = trim((string) ($row['C'] ?? 'UPSC'));
                $duration = trim((string) ($row['D'] ?? ''));
                $fee = ! empty($row['E']) ? (float) $row['E'] : 0.00;
                $feeReserved = ! empty($row['F']) ? (float) $row['F'] : 0.00;
                $capacity = ! empty($row['G']) ? (int) $row['G'] : null;
                $hasSectionsRaw = strtolower(trim((string) ($row['H'] ?? 'no')));
                $status = strtolower(trim((string) ($row['I'] ?? 'active')));
                $description = trim((string) ($row['J'] ?? ''));

                if (empty($name)) {
                    continue; // skip completely blank trailing rows
                }

                if (empty($code)) {
                    $code = 'CRS-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 8));
                }

                $hasSections = in_array($hasSectionsRaw, ['yes', 'true', '1', 'y'], true);
                if (! in_array($status, ['active', 'inactive', 'archived'], true)) {
                    $status = 'active';
                }

                $validator = Validator::make([
                    'code' => $code,
                    'name' => $name,
                    'fee' => $fee,
                ], [
                    'code' => 'required|string|max:50',
                    'name' => 'required|string|max:255',
                    'fee' => 'numeric|min:0',
                ]);

                if ($validator->fails()) {
                    $errors[] = "Row {$rowIndex} [{$name}]: ".implode(' ', $validator->errors()->all());

                    continue;
                }

                Course::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'category' => $category ?: 'UPSC',
                        'duration' => $duration ?: null,
                        'fee' => $fee,
                        'fee_reserved' => $feeReserved,
                        'capacity' => $capacity,
                        'has_sections' => $hasSections,
                        'status' => $status,
                        'description' => $description ?: null,
                        'created_by' => auth()->id(),
                    ]
                );

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'courses_bulk_imported',
                description: "Bulk imported {$imported} academic courses from spreadsheet",
                newValues: ['imported_count' => $imported, 'errors_count' => count($errors)],
                userId: auth()->id()
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    public static function generateCourseTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Courses Import');

        $headers = [
            'A1' => 'Course Code (e.g. UPSC-GS-PRELIMS)',
            'B1' => 'Course Name (Required)',
            'C1' => 'Category (UPSC, WBCS, Optional, etc.)',
            'D1' => 'Duration (e.g. 1 Year, 6 Months)',
            'E1' => 'Fee (Amount, e.g. 15000)',
            'F1' => 'Fee Reserved (e.g. 7500)',
            'G1' => 'Capacity (Numbers, blank for unlimited)',
            'H1' => 'Has Sections (yes/no)',
            'I1' => 'Status (active/inactive/archived)',
            'J1' => 'Description (Optional)',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sampleRows = [
            ['UPSC-GS-PRELIMS', 'UPSC Civil Services GS Prelims Comprehensive', 'UPSC', '1 Year', 15000, 7500, 150, 'yes', 'active', 'Foundation classroom curriculum covering Paper I & CSAT'],
            ['WBCS-EXEC-FOUNDATION', 'WBCS Executive Foundation Program', 'WBCS', '9 Months', 12000, 6000, 100, 'no', 'active', 'Targeted West Bengal Civil Services preliminary and mains batch'],
        ];

        $r = 2;
        foreach ($sampleRows as $row) {
            $c = 'A';
            foreach ($row as $val) {
                $sheet->setCellValue("{$c}{$r}", $val);
                $c++;
            }
            $r++;
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'course_tmpl_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, 'courses_import_template.xlsx')->deleteFileAfterSend(true);
    }

    // =========================================================================
    // 2. BATCHES IMPORT
    // =========================================================================

    /**
     * Import batches from spreadsheet.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importBatches(string|UploadedFile $file): array
    {
        $rows = static::loadRows($file);
        if (count($rows) <= 1) {
            return ['imported' => 0, 'errors' => ['Spreadsheet contains no data rows.']];
        }

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            $isFirst = true;
            foreach ($rows as $rowIndex => $row) {
                if ($isFirst) {
                    $isFirst = false;

                    continue; // Skip header
                }

                $code = strtoupper(trim((string) ($row['A'] ?? '')));
                $name = trim((string) ($row['B'] ?? ''));
                $academicYear = trim((string) ($row['C'] ?? ''));
                $startDate = trim((string) ($row['D'] ?? ''));
                $endDate = trim((string) ($row['E'] ?? ''));
                $maxStudents = ! empty($row['F']) ? (int) $row['F'] : null;
                $status = strtolower(trim((string) ($row['G'] ?? 'active')));
                $courseCodesRaw = trim((string) ($row['H'] ?? ''));
                $description = trim((string) ($row['I'] ?? ''));

                if (empty($name)) {
                    continue; // Skip blank trailing rows
                }

                if (empty($code)) {
                    $code = 'BATCH-'.date('Y').'-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 6));
                }

                if (empty($academicYear)) {
                    $academicYear = date('Y').'-'.(date('Y') + 1);
                }

                if (! in_array($status, ['active', 'upcoming', 'completed', 'archived'], true)) {
                    $status = 'active';
                }

                $parsedStart = ! empty($startDate) ? rescue(fn () => Carbon::parse($startDate)->format('Y-m-d'), null, false) : null;
                $parsedEnd = ! empty($endDate) ? rescue(fn () => Carbon::parse($endDate)->format('Y-m-d'), null, false) : null;

                $validator = Validator::make([
                    'code' => $code,
                    'name' => $name,
                    'academic_year' => $academicYear,
                ], [
                    'code' => 'required|string|max:50',
                    'name' => 'required|string|max:255',
                    'academic_year' => 'required|string|max:20',
                ]);

                if ($validator->fails()) {
                    $errors[] = "Row {$rowIndex} [{$name}]: ".implode(' ', $validator->errors()->all());

                    continue;
                }

                $batch = Batch::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'academic_year' => $academicYear,
                        'start_date' => $parsedStart,
                        'end_date' => $parsedEnd,
                        'max_students' => $maxStudents,
                        'status' => $status,
                        'description' => $description ?: null,
                        'created_by' => auth()->id(),
                    ]
                );

                // Auto-link courses if provided in column H
                if (! empty($courseCodesRaw)) {
                    $codes = array_map('trim', explode(',', $courseCodesRaw));
                    $courseIds = Course::whereIn('code', $codes)->orWhereIn('name', $codes)->pluck('id')->toArray();
                    if (! empty($courseIds)) {
                        $batch->courses()->syncWithoutDetaching($courseIds);
                    }
                }

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'batches_bulk_imported',
                description: "Bulk imported {$imported} academic batches from spreadsheet",
                newValues: ['imported_count' => $imported, 'errors_count' => count($errors)],
                userId: auth()->id()
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    public static function generateBatchTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Batches Import');

        $headers = [
            'A1' => 'Batch Code (e.g. BATCH-2026-GS1)',
            'B1' => 'Batch Name (Required)',
            'C1' => 'Academic Year (e.g. 2026-2027)',
            'D1' => 'Start Date (YYYY-MM-DD)',
            'E1' => 'End Date (YYYY-MM-DD)',
            'F1' => 'Max Students (Capacity, e.g. 120)',
            'G1' => 'Status (active/upcoming/completed/archived)',
            'H1' => 'Linked Course Codes (Comma-separated, e.g. UPSC-GS-PRELIMS)',
            'I1' => 'Description (Optional)',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sampleRows = [
            ['BATCH-2026-GS1', 'UPSC Prelims Morning Batch 2026', '2026-2027', '2026-07-01', '2027-05-31', 120, 'active', 'UPSC-GS-PRELIMS', 'Morning session classroom cohort for UPSC 2026 candidates'],
            ['BATCH-2026-WBCS1', 'WBCS Regular Evening Cohort 2026', '2026-2027', '2026-08-01', '2027-04-30', 90, 'upcoming', '', 'Evening session for working candidates'],
        ];

        $r = 2;
        foreach ($sampleRows as $row) {
            $c = 'A';
            foreach ($row as $val) {
                $sheet->setCellValue("{$c}{$r}", $val);
                $c++;
            }
            $r++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'batch_tmpl_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, 'batches_import_template.xlsx')->deleteFileAfterSend(true);
    }

    // =========================================================================
    // 3. SECTIONS IMPORT
    // =========================================================================

    /**
     * Import classroom sections from spreadsheet.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importSections(string|UploadedFile $file): array
    {
        $rows = static::loadRows($file);
        if (count($rows) <= 1) {
            return ['imported' => 0, 'errors' => ['Spreadsheet contains no data rows.']];
        }

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            $isFirst = true;
            foreach ($rows as $rowIndex => $row) {
                if ($isFirst) {
                    $isFirst = false;

                    continue; // Skip header
                }

                $batchIdentifier = trim((string) ($row['A'] ?? ''));
                $courseIdentifier = trim((string) ($row['B'] ?? ''));
                $name = trim((string) ($row['C'] ?? ''));
                $code = strtoupper(trim((string) ($row['D'] ?? '')));
                $capacity = ! empty($row['E']) ? (int) $row['E'] : 60;
                $roomNumber = trim((string) ($row['F'] ?? ''));
                $timing = trim((string) ($row['G'] ?? ''));
                $status = strtolower(trim((string) ($row['H'] ?? 'active')));

                if (empty($name) && empty($batchIdentifier)) {
                    continue; // Skip blank trailing rows
                }

                if (empty($batchIdentifier) || empty($courseIdentifier) || empty($name)) {
                    $errors[] = "Row {$rowIndex}: Batch Code, Course Code, and Section Name are required.";

                    continue;
                }

                $batch = Batch::where('code', $batchIdentifier)->orWhere('name', $batchIdentifier)->first();
                if (! $batch) {
                    $errors[] = "Row {$rowIndex}: Batch '{$batchIdentifier}' not found.";

                    continue;
                }

                $course = Course::where('code', $courseIdentifier)->orWhere('name', $courseIdentifier)->first();
                if (! $course) {
                    $errors[] = "Row {$rowIndex}: Course '{$courseIdentifier}' not found.";

                    continue;
                }

                // Ensure BatchCourse linkage exists
                $batchCourse = BatchCourse::firstOrCreate(
                    ['batch_id' => $batch->id, 'course_id' => $course->id],
                    ['created_by' => auth()->id()]
                );

                if (empty($code)) {
                    $code = 'SEC-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 4));
                }

                if (! in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }

                Section::updateOrCreate(
                    [
                        'batch_id' => $batch->id,
                        'course_id' => $course->id,
                        'code' => $code,
                    ],
                    [
                        'batch_course_id' => $batchCourse->id,
                        'name' => $name,
                        'capacity' => $capacity,
                        'room_number' => $roomNumber ?: null,
                        'timing' => $timing ?: null,
                        'status' => $status,
                        'created_by' => auth()->id(),
                    ]
                );

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'sections_bulk_imported',
                description: "Bulk imported {$imported} classroom sections from spreadsheet",
                newValues: ['imported_count' => $imported, 'errors_count' => count($errors)],
                userId: auth()->id()
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    public static function generateSectionTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sections Import');

        $headers = [
            'A1' => 'Batch Code (Required, e.g. BATCH-2026-GS1)',
            'B1' => 'Course Code (Required, e.g. UPSC-GS-PRELIMS)',
            'C1' => 'Section Name (Required, e.g. Section A)',
            'D1' => 'Section Code (e.g. SEC-A)',
            'E1' => 'Capacity (Numbers, e.g. 60)',
            'F1' => 'Room Number / Hall (e.g. Hall A / CR-101)',
            'G1' => 'Shift / Timing (e.g. 08:00 AM - 11:00 AM)',
            'H1' => 'Status (active/inactive)',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sampleRows = [
            ['BATCH-2026-GS1', 'UPSC-GS-PRELIMS', 'Section A - Morning Hall', 'SEC-A', 60, 'CR-101', '08:00 AM - 11:00 AM', 'active'],
            ['BATCH-2026-GS1', 'UPSC-GS-PRELIMS', 'Section B - Afternoon Hall', 'SEC-B', 60, 'CR-102', '12:00 PM - 03:00 PM', 'active'],
        ];

        $r = 2;
        foreach ($sampleRows as $row) {
            $c = 'A';
            foreach ($row as $val) {
                $sheet->setCellValue("{$c}{$r}", $val);
                $c++;
            }
            $r++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'section_tmpl_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, 'sections_import_template.xlsx')->deleteFileAfterSend(true);
    }

    // =========================================================================
    // 4. COURSE PLANS IMPORT
    // =========================================================================

    /**
     * Import curriculum course plans from spreadsheet.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importCoursePlans(string|UploadedFile $file): array
    {
        $rows = static::loadRows($file);
        if (count($rows) <= 1) {
            return ['imported' => 0, 'errors' => ['Spreadsheet contains no data rows.']];
        }

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            $isFirst = true;
            foreach ($rows as $rowIndex => $row) {
                if ($isFirst) {
                    $isFirst = false;

                    continue; // Skip header
                }

                $planCode = strtoupper(trim((string) ($row['A'] ?? '')));
                $title = trim((string) ($row['B'] ?? ''));
                $batchIdentifier = trim((string) ($row['C'] ?? ''));
                $courseIdentifier = trim((string) ($row['D'] ?? ''));
                $sectionIdentifier = trim((string) ($row['E'] ?? ''));
                $planType = strtolower(trim((string) ($row['F'] ?? 'annual')));
                $academicYear = trim((string) ($row['G'] ?? ''));
                $startDate = trim((string) ($row['H'] ?? ''));
                $endDate = trim((string) ($row['I'] ?? ''));
                $totalLectures = ! empty($row['J']) ? (int) $row['J'] : 30;
                $topicsCovered = trim((string) ($row['K'] ?? ''));
                $status = strtolower(trim((string) ($row['L'] ?? 'published')));
                $description = trim((string) ($row['M'] ?? ''));

                if (empty($title) && empty($batchIdentifier)) {
                    continue; // Skip blank trailing rows
                }

                if (empty($title) || empty($batchIdentifier) || empty($courseIdentifier) || empty($startDate) || empty($endDate)) {
                    $errors[] = "Row {$rowIndex}: Title, Batch Code, Course Code, Start Date, and End Date are mandatory.";

                    continue;
                }

                $batch = Batch::where('code', $batchIdentifier)->orWhere('name', $batchIdentifier)->first();
                if (! $batch) {
                    $errors[] = "Row {$rowIndex}: Batch '{$batchIdentifier}' not found.";

                    continue;
                }

                $course = Course::where('code', $courseIdentifier)->orWhere('name', $courseIdentifier)->first();
                if (! $course) {
                    $errors[] = "Row {$rowIndex}: Course '{$courseIdentifier}' not found.";

                    continue;
                }

                $sectionId = null;
                if (! empty($sectionIdentifier)) {
                    $sec = Section::where('batch_id', $batch->id)
                        ->where('course_id', $course->id)
                        ->where(function ($q) use ($sectionIdentifier) {
                            $q->where('code', $sectionIdentifier)->orWhere('name', $sectionIdentifier);
                        })->first();
                    $sectionId = $sec?->id;
                }

                $parsedStart = rescue(fn () => Carbon::parse($startDate)->format('Y-m-d'), null, false);
                $parsedEnd = rescue(fn () => Carbon::parse($endDate)->format('Y-m-d'), null, false);

                if (! $parsedStart || ! $parsedEnd) {
                    $errors[] = "Row {$rowIndex}: Invalid date format for Start Date or End Date.";

                    continue;
                }

                if (! in_array($planType, ['annual', 'monthly', 'weekly', 'custom'], true)) {
                    $planType = 'annual';
                }

                if (! in_array($status, ['draft', 'published', 'under_revision', 'archived'], true)) {
                    $status = 'published';
                }

                if (empty($planCode)) {
                    $count = CoursePlan::withTrashed()->count() + 1;
                    $planCode = 'CP-'.date('Y').'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
                }

                CoursePlan::updateOrCreate(
                    ['plan_code' => $planCode],
                    [
                        'title' => $title,
                        'batch_id' => $batch->id,
                        'course_id' => $course->id,
                        'section_id' => $sectionId,
                        'plan_type' => $planType,
                        'academic_year' => $academicYear ?: $batch->academic_year,
                        'start_date' => $parsedStart,
                        'end_date' => $parsedEnd,
                        'total_lectures_planned' => $totalLectures,
                        'topics_covered' => $topicsCovered ?: null,
                        'description' => $description ?: null,
                        'status' => $status,
                        'published_at' => $status === 'published' ? now() : null,
                        'created_by' => auth()->id(),
                    ]
                );

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'course_plans_bulk_imported',
                description: "Bulk imported {$imported} course curriculum plans from spreadsheet",
                newValues: ['imported_count' => $imported, 'errors_count' => count($errors)],
                userId: auth()->id()
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    public static function generateCoursePlanTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Course Plans Import');

        $headers = [
            'A1' => 'Plan Code (Optional, e.g. CP-2026-001)',
            'B1' => 'Plan Title (Required)',
            'C1' => 'Batch Code (Required, e.g. BATCH-2026-GS1)',
            'D1' => 'Course Code (Required, e.g. UPSC-GS-PRELIMS)',
            'E1' => 'Section Code (Optional, e.g. SEC-A)',
            'F1' => 'Plan Type (annual/monthly/weekly/custom)',
            'G1' => 'Academic Year (e.g. 2026-2027)',
            'H1' => 'Start Date (YYYY-MM-DD)',
            'I1' => 'End Date (YYYY-MM-DD)',
            'J1' => 'Total Lectures Planned (Numbers, e.g. 45)',
            'K1' => 'Topics Covered (Units and syllabus scope)',
            'L1' => 'Status (draft/published/under_revision/archived)',
            'M1' => 'Description / Pedagogical Notes (Optional)',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sampleRows = [
            ['CP-2026-001', 'Annual Pedagogical Plan - Indian Polity & Constitution', 'BATCH-2026-GS1', 'UPSC-GS-PRELIMS', 'SEC-A', 'annual', '2026-2027', '2026-07-01', '2027-05-31', 45, 'Constitutional Framework, Fundamental Rights, Judiciary, Executive, Local Governance', 'published', 'Comprehensive 45-lecture syllabus module with weekly mock evaluations'],
            ['CP-2026-002', 'Comprehensive Physical Geography Roadmap', 'BATCH-2026-GS1', 'UPSC-GS-PRELIMS', '', 'annual', '2026-2027', '2026-07-15', '2027-04-30', 36, 'Geomorphology, Climatology, Oceanography, Indian Physiography', 'published', 'Lecture blueprint covering full UPSC geography syllabus'],
        ];

        $r = 2;
        foreach ($sampleRows as $row) {
            $c = 'A';
            foreach ($row as $val) {
                $sheet->setCellValue("{$c}{$r}", $val);
                $c++;
            }
            $r++;
        }

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'cp_tmpl_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, 'course_plans_import_template.xlsx')->deleteFileAfterSend(true);
    }

    // =========================================================================
    // 5. FACULTY MASTER IMPORT
    // =========================================================================

    /**
     * Import faculty directory members from spreadsheet.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importFaculties(string|UploadedFile $file): array
    {
        $rows = static::loadRows($file);
        if (count($rows) <= 1) {
            return ['imported' => 0, 'errors' => ['Spreadsheet contains no data rows.']];
        }

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            $isFirst = true;
            foreach ($rows as $rowIndex => $row) {
                if ($isFirst) {
                    $isFirst = false;

                    continue; // Skip header
                }

                $facultyCode = strtoupper(trim((string) ($row['A'] ?? '')));
                $name = trim((string) ($row['B'] ?? ''));
                $email = strtolower(trim((string) ($row['C'] ?? '')));
                $phone = trim((string) ($row['D'] ?? ''));
                $designation = trim((string) ($row['E'] ?? ''));
                $department = trim((string) ($row['F'] ?? 'General Studies'));
                $facultyType = strtolower(trim((string) ($row['G'] ?? 'regular')));
                $partnerIdentifier = trim((string) ($row['H'] ?? ''));
                $specialization = trim((string) ($row['I'] ?? ''));
                $qualification = trim((string) ($row['J'] ?? ''));
                $status = strtolower(trim((string) ($row['K'] ?? 'active')));
                $subjectsRaw = trim((string) ($row['L'] ?? ''));
                $bio = trim((string) ($row['M'] ?? ''));

                if (empty($name) && empty($email)) {
                    continue; // Skip blank trailing rows
                }

                if (empty($name) || empty($email)) {
                    $errors[] = "Row {$rowIndex}: Faculty Name and Email are mandatory.";

                    continue;
                }

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Row {$rowIndex}: Invalid email format '{$email}'.";

                    continue;
                }

                $partnerId = null;
                if (! empty($partnerIdentifier)) {
                    $partner = PartnerInstitute::where('code', $partnerIdentifier)
                        ->orWhere('name', $partnerIdentifier)
                        ->first();
                    $partnerId = $partner?->id;
                }

                $validTypes = ['regular', 'guest', 'visiting', 'contractual', 'adjunct'];
                if (! in_array($facultyType, $validTypes, true)) {
                    $facultyType = 'regular';
                }

                if (! in_array($status, ['active', 'inactive', 'on_leave'], true)) {
                    $status = 'active';
                }

                $subjects = ! empty($subjectsRaw) ? array_map('trim', explode(',', $subjectsRaw)) : [];

                if (empty($facultyCode)) {
                    $year = date('Y');
                    $count = Faculty::withTrashed()->whereYear('created_at', $year)->count() + 1;
                    $facultyCode = sprintf('FAC-%s-%03d', $year, $count);
                }

                Faculty::updateOrCreate(
                    ['email' => $email],
                    [
                        'faculty_code' => $facultyCode,
                        'name' => $name,
                        'phone' => $phone ?: null,
                        'designation' => $designation ?: 'Faculty Member',
                        'department' => $department ?: 'General Studies',
                        'faculty_type' => $facultyType,
                        'partner_institute_id' => $partnerId,
                        'specialization' => $specialization ?: null,
                        'qualification' => $qualification ?: null,
                        'status' => $status,
                        'subjects' => $subjects,
                        'bio' => $bio ?: null,
                        'created_by' => auth()->id(),
                    ]
                );

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'faculty_bulk_imported',
                description: "Bulk imported {$imported} faculty members from spreadsheet",
                newValues: ['imported_count' => $imported, 'errors_count' => count($errors)],
                userId: auth()->id()
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    public static function generateFacultyTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Faculty Master Import');

        $headers = [
            'A1' => 'Faculty Code (Optional, e.g. FAC-2026-001)',
            'B1' => 'Full Name (Required)',
            'C1' => 'Email (Required / Unique)',
            'D1' => 'Phone / Mobile',
            'E1' => 'Designation (e.g. Senior Faculty / Guest Lecturer)',
            'F1' => 'Department (General Studies, Geography, History, Polity, Economy, CSAT, etc.)',
            'G1' => 'Faculty Type (regular/guest/visiting/contractual/adjunct)',
            'H1' => 'Partner Institute Code or Name (Optional)',
            'I1' => 'Specialization (Domain expertise)',
            'J1' => 'Qualification (e.g. Ph.D., UGC-NET, M.A.)',
            'K1' => 'Status (active/inactive/on_leave)',
            'L1' => 'Competent Subjects (Comma-separated, e.g. Indian Polity, Governance)',
            'M1' => 'Biography / Dossier (Optional)',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sampleRows = [
            ['FAC-2026-001', 'Dr. Rajesh Sharma', 'rajesh.sharma@sntcssc.in', '+91 9830012345', 'Senior Professor', 'Polity', 'regular', '', 'Constitutional Law & Governance', 'Ph.D., UGC-NET (Pol. Sc.)', 'active', 'Indian Polity, Governance, Constitution', 'Over 15 years experience mentoring civil service aspirants'],
            ['FAC-2026-002', 'Prof. Ananya Banerjee', 'ananya.banerjee@sntcssc.in', '+91 9830067890', 'Guest Lecturer', 'Geography', 'guest', 'INST-2026-001', 'Physical & Economic Geography', 'M.Sc., UGC-NET', 'active', 'Physical Geography, World Map Work', 'Author of civil services geography companion guides'],
        ];

        $r = 2;
        foreach ($sampleRows as $row) {
            $c = 'A';
            foreach ($row as $val) {
                $sheet->setCellValue("{$c}{$r}", $val);
                $c++;
            }
            $r++;
        }

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'faculty_tmpl_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, 'faculty_import_template.xlsx')->deleteFileAfterSend(true);
    }
}
