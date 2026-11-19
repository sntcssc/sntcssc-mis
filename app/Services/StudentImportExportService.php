<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentAddress;
use App\Models\StudentProfile;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentImportExportService
{
    /**
     * Export data as an elegant, styled PDF.
     */
    public static function exportPdf(string $title, array $headers, array $rows, ?string $subtitle = null): StreamedResponse
    {
        $pdf = Pdf::loadView('exports.table-pdf', [
            'title' => $title,
            'subtitle' => $subtitle,
            'headers' => $headers,
            'rows' => $rows,
        ])->setPaper('a4', count($headers) > 6 ? 'landscape' : 'portrait');

        $safeName = Str::slug($title).'-'.date('YmdHis').'.pdf';

        return response()->streamDownload(
            function () use ($pdf) {
                echo $pdf->output();
            },
            $safeName,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
            ]
        );
    }

    /**
     * Export data as a formatted Excel (.xlsx) file.
     */
    public static function exportExcel(string $filename, array $headers, array $rows): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        // Header row
        $colIdx = 1;
        foreach ($headers as $header) {
            $sheet->setCellValue([$colIdx, 1], $header);
            $colIdx++;
        }

        // Style Header
        $highestColumn = $sheet->getHighestColumn();
        $headerRange = 'A1:'.$highestColumn.'1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0369A1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Data rows
        $rowIdx = 2;
        foreach ($rows as $row) {
            $colIdx = 1;
            foreach ($row as $val) {
                $sheet->setCellValue([$colIdx, $rowIdx], (string) ($val ?? ''));
                $colIdx++;
            }
            $rowIdx++;
        }

        // Auto-size columns
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mis_export_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpFile);

        $downloadName = Str::slug($filename).'-'.date('YmdHis').'.xlsx';

        return response()->download($tmpFile, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Export data as CSV with UTF-8 BOM.
     */
    public static function exportCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        $safeName = Str::slug($filename).'-'.date('YmdHis').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($cell) => (string) ($cell ?? ''), $row));
            }

            fclose($handle);
        }, $safeName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Download Student Import Sample Template.
     */
    public static function downloadStudentTemplate(string $format = 'csv')
    {
        $headers = [
            'Name',
            'Email',
            'Phone',
            'WhatsApp',
            'DOB (YYYY-MM-DD)',
            'Gender (Male/Female/Others)',
            'Category (UR/SC/ST/OBCA/OBCB/EWS)',
            'Father Name',
            'Mother Name',
            'State',
            'District',
            'Address',
            'PIN Code',
            'Student ID (Optional - auto-generated if blank)',
        ];

        $sampleRows = [
            [
                'Aarav Sen',
                'aarav.sen@example.com',
                '9830012345',
                '9830012345',
                '2001-05-14',
                'Male',
                'UR',
                'Debabrata Sen',
                'Mita Sen',
                'West Bengal',
                'Kolkata',
                'Salt Lake, Sector 1',
                '700064',
                '',
            ],
            [
                'Ananya Roy',
                'ananya.roy@example.com',
                '9830054321',
                '9830054321',
                '2002-08-20',
                'Female',
                'SC',
                'Tapan Roy',
                'Sunita Roy',
                'West Bengal',
                'North 24 Parganas',
                'Barasat Main Road',
                '700124',
                '',
            ],
        ];

        if (strtolower($format) === 'xlsx') {
            return static::exportExcel('student-import-template', $headers, $sampleRows);
        }

        return static::exportCsv('student-import-template', $headers, $sampleRows);
    }

    /**
     * Download Enrollment Import Sample Template.
     */
    public static function downloadEnrollmentTemplate(string $format = 'csv')
    {
        $headers = [
            'Student Email Or Phone Or ID',
            'Batch Code',
            'Course Code',
            'Section Code (Optional)',
            'Enrolled Date (YYYY-MM-DD)',
            'Fee Amount',
            'Fee Status (Pending/Partial/Paid/Exempted)',
            'Status (Active/Completed/Dropped)',
            'Roll Number (Optional)',
            'Application Number (Optional)',
        ];

        $sampleRows = [
            [
                'aarav.sen@example.com',
                'BATCH-2026',
                'CC',
                '1',
                '2026-01-15',
                '15000',
                'Paid',
                'Active',
                'CC-2026-A-01',
                'APP-2026-0001',
            ],
            [
                'ananya.roy@example.com',
                'BATCH-2026',
                'PCC',
                '',
                '2026-02-01',
                '7500',
                'Paid',
                'Active',
                'PCC-2026-02',
                'APP-2026-0002',
            ],
        ];

        if (strtolower($format) === 'xlsx') {
            return static::exportExcel('enrollment-import-template', $headers, $sampleRows);
        }

        return static::exportCsv('enrollment-import-template', $headers, $sampleRows);
    }

    /**
     * Import students from an uploaded file (CSV or XLSX).
     */
    public static function importStudents(string $filePath): array
    {
        $rows = static::readSpreadsheetOrCsv($filePath);
        if (empty($rows)) {
            return ['success' => 0, 'failed' => 0, 'errors' => ['File is empty or could not be parsed.']];
        }

        $headerRow = array_shift($rows);
        $headerMap = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string) $h))), $headerRow);

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $idx => $row) {
            $line = $idx + 2;
            if (empty(array_filter($row))) {
                continue;
            }

            $data = [];
            foreach ($headerMap as $hIdx => $key) {
                $data[$key] = isset($row[$hIdx]) ? trim((string) $row[$hIdx]) : '';
            }

            $name = $data['name'] ?? '';
            $email = $data['email'] ?? '';
            $phone = $data['phone'] ?? '';

            if (empty($name) || (empty($email) && empty($phone))) {
                $failed++;
                $errors[] = "Row {$line}: Name and either Email or Phone are required.";

                continue;
            }

            // Check if user already exists
            $user = null;
            if (! empty($email)) {
                $user = User::where('email', $email)->first();
            }
            if (! $user && ! empty($phone)) {
                $user = User::where('phone', $phone)->first();
            }

            try {
                DB::transaction(function () use ($data, $name, $email, $phone, &$user, &$success) {
                    $isNew = false;
                    if (! $user) {
                        $isNew = true;
                        $user = new User;
                        $user->name = $name;
                        $user->email = ! empty($email) ? $email : 'student_'.time().'_'.Str::random(5).'@sntcssc.in';
                        $user->phone = ! empty($phone) ? $phone : null;
                        $user->password = Hash::make(Str::random(12));
                    } else {
                        $user->name = $name;
                        if (! empty($email)) {
                            $user->email = $email;
                        }
                        if (! empty($phone)) {
                            $user->phone = $phone;
                        }
                    }

                    $user->whatsapp_no = $data['whatsapp'] ?? $user->whatsapp_no;
                    $user->dob = ! empty($data['dobyyyymmdd']) ? $data['dobyyyymmdd'] : $user->dob;
                    $user->gender = strtolower($data['gendermalefemaleothers'] ?? $data['gender'] ?? $user->gender ?? 'others');

                    // If student ID is specified in import and user has no student ID, assign it
                    $providedId = $data['studentidoptionalautogeneratedifblank'] ?? $data['studentid'] ?? null;
                    if (! empty($providedId) && empty($user->student_id)) {
                        $user->student_id = substr($providedId, 0, 10);
                    }

                    $user->save();

                    // Assign Student role
                    if (! $user->hasRole('Student')) {
                        $user->assignRole('Student');
                    }

                    // Ensure student ID is generated
                    if (empty($user->student_id)) {
                        $user->student_id = StudentIdGeneratorService::generateForStudent($user);
                        $user->saveQuietly();
                    }

                    // Save or update StudentProfile and StudentAddress in unified sync
                    $category = strtoupper($data['categoryurscstobcaobcbews'] ?? $data['category'] ?? '');
                    $father = $data['fathername'] ?? null;
                    $mother = $data['mothername'] ?? null;

                    $state = $data['state'] ?? null;
                    $district = $data['district'] ?? null;
                    $address = $data['address'] ?? null;
                    $pin = $data['pincode'] ?? null;

                    UserStudentSyncService::syncStudent(
                        $user,
                        [],
                        [
                            'category' => $category ?: null,
                            'father_name' => $father,
                            'mother_name' => $mother,
                            'dob' => $user->dob,
                            'gender' => $user->gender,
                        ],
                        [
                            'state' => $state ?: 'West Bengal',
                            'district' => $district,
                            'address_line1' => $address,
                            'pin_code' => $pin,
                        ]
                    );

                    $success++;
                });
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Row {$line} ({$name}): ".$e->getMessage();
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Import enrollments from an uploaded file (CSV or XLSX).
     */
    public static function importEnrollments(string $filePath): array
    {
        $rows = static::readSpreadsheetOrCsv($filePath);
        if (empty($rows)) {
            return ['success' => 0, 'failed' => 0, 'errors' => ['File is empty or could not be parsed.']];
        }

        $headerRow = array_shift($rows);
        $headerMap = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string) $h))), $headerRow);

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $idx => $row) {
            $line = $idx + 2;
            if (empty(array_filter($row))) {
                continue;
            }

            $data = [];
            foreach ($headerMap as $hIdx => $key) {
                $data[$key] = isset($row[$hIdx]) ? trim((string) $row[$hIdx]) : '';
            }

            $studentIdentifier = $data['studentemailorphoneorid'] ?? $data['student'] ?? '';
            $batchCode = $data['batchcode'] ?? '';
            $courseCode = $data['coursecode'] ?? '';
            $sectionCode = $data['sectioncodeoptional'] ?? $data['sectioncode'] ?? '';

            if (empty($studentIdentifier) || empty($batchCode) || empty($courseCode)) {
                $failed++;
                $errors[] = "Row {$line}: Student identifier, Batch Code, and Course Code are required.";

                continue;
            }

            // Find student
            $student = User::where('student_id', $studentIdentifier)
                ->orWhere('email', $studentIdentifier)
                ->orWhere('phone', $studentIdentifier)
                ->orWhere('urn', $studentIdentifier)
                ->first();

            if (! $student) {
                $failed++;
                $errors[] = "Row {$line}: Student not found with identifier '{$studentIdentifier}'.";

                continue;
            }

            // Find Batch
            $batch = Batch::where('code', $batchCode)->orWhere('name', $batchCode)->first();
            if (! $batch) {
                $failed++;
                $errors[] = "Row {$line}: Batch '{$batchCode}' not found.";

                continue;
            }

            // Find Course
            $course = Course::where('code', $courseCode)->orWhere('name', $courseCode)->first();
            if (! $course) {
                $failed++;
                $errors[] = "Row {$line}: Course '{$courseCode}' not found.";

                continue;
            }

            // Find Section if given
            $section = null;
            if (! empty($sectionCode)) {
                $section = Section::where('batch_id', $batch->id)
                    ->where('course_id', $course->id)
                    ->where(fn ($q) => $q->where('code', $sectionCode)->orWhere('name', $sectionCode))
                    ->first();
            }

            // Check admission restrictions
            $check = AdmissionRestrictionService::checkEligibility($student, $course, $batch, $section, false);
            if (! $check['allowed']) {
                $failed++;
                $errors[] = "Row {$line} ({$student->name}): ".$check['reason'];

                continue;
            }

            try {
                DB::transaction(function () use ($data, $student, $batch, $course, $section, &$success) {
                    // Ensure student has a lifetime unique student ID and Student role
                    if (! $student->hasRole('Student')) {
                        $student->assignRole('Student');
                    }
                    if (empty($student->student_id)) {
                        StudentIdGeneratorService::generateForStudent($student, $course, $section, $batch->academic_year);
                    }
                    UserStudentSyncService::syncFromUser($student);

                    $enrolledDate = ! empty($data['enrolleddateyyyymmdd']) ? $data['enrolleddateyyyymmdd'] : date('Y-m-d');
                    $feeAmount = ! empty($data['feeamount']) ? (float) $data['feeamount'] : (float) $course->fee;
                    $feeStatus = strtolower($data['feestatuspendingpartialpaidexempted'] ?? $data['feestatus'] ?? 'paid');
                    $status = strtolower($data['statusactivecompleteddropped'] ?? $data['status'] ?? 'active');
                    $rollNo = $data['rollnumberoptional'] ?? $data['rollnumber'] ?? null;
                    $applicationNo = $data['applicationnumberoptional'] ?? $data['applicationnumber'] ?? $data['applicationno'] ?? null;

                    Enrollment::create([
                        'user_id' => $student->id,
                        'batch_id' => $batch->id,
                        'course_id' => $course->id,
                        'section_id' => $section?->id,
                        'roll_no' => $rollNo,
                        'application_no' => $applicationNo ?: null,
                        'enrolled_at' => $enrolledDate,
                        'fee_amount' => $feeAmount,
                        'fee_status' => $feeStatus,
                        'status' => $status,
                    ]);

                    $success++;
                });
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Row {$line}: ".$e->getMessage();
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Read spreadsheet (XLSX, XLS, CSV) into 2D array.
     */
    protected static function readSpreadsheetOrCsv(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            $rows = [];
            if (($handle = fopen($filePath, 'r')) !== false) {
                while (($data = fgetcsv($handle)) !== false) {
                    $rows[] = $data;
                }
                fclose($handle);
            }

            return $rows;
        }

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();

            return $sheet->toArray(null, true, true, false);
        } catch (\Throwable) {
            return [];
        }
    }
}
