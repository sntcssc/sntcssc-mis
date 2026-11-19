<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\LeaveApplication;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceImportExportService
{
    /**
     * Clean and ensure string is valid UTF-8, stripping or substituting bad bytes.
     */
    public static function cleanUtf8(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $string = (string) $value;

        if (! mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
        }

        return mb_scrub($string, 'UTF-8');
    }

    /**
     * Parse rows from either CSV or Excel file.
     *
     * @return array<int, array<string, string|null>>
     */
    public static function parseRowsFromFile(string $filePath): array
    {
        $rows = [];

        if (str_ends_with(strtolower($filePath), '.csv')) {
            if (($handle = fopen($filePath, 'r')) !== false) {
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                $header = null;
                while (($data = fgetcsv($handle, 2000, ',')) !== false) {
                    $cleanData = array_map(fn ($val) => self::cleanUtf8($val), $data);

                    if (! $header) {
                        $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), $cleanData);
                    } else {
                        if (count($header) === count($cleanData)) {
                            $rows[] = array_combine($header, $cleanData);
                        }
                    }
                }
                fclose($handle);
            }
        } else {
            $sheet = Excel::toArray([], $filePath);
            if (! empty($sheet) && ! empty($sheet[0])) {
                $sheetRows = $sheet[0];
                $rawHeader = array_shift($sheetRows);
                $cleanRawHeader = array_map(fn ($h) => self::cleanUtf8($h), (array) $rawHeader);
                $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), $cleanRawHeader);
                foreach ($sheetRows as $r) {
                    $cleanR = array_map(fn ($v) => self::cleanUtf8($v), (array) $r);
                    if (count($header) === count($cleanR)) {
                        $rows[] = array_combine($header, $cleanR);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Bulk import attendance records from a CSV or Excel spreadsheet.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importAttendance(string $filePath, ?User $marker = null): array
    {
        $rows = self::parseRowsFromFile($filePath);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;

            $dateRaw = trim($row['date'] ?? $row['attendance_date'] ?? '');
            if ($dateRaw === '') {
                $errors[] = "Row {$line}: Date is required.";
                $skipped++;

                continue;
            }

            try {
                $date = Carbon::parse($dateRaw)->toDateString();
            } catch (\Throwable) {
                $errors[] = "Row {$line}: Invalid date format '{$dateRaw}'.";
                $skipped++;

                continue;
            }

            // Identify user by user_id, email, student_id, biometric_pin, or employee_id
            $identifier = trim($row['user_id'] ?? $row['student_id'] ?? $row['email'] ?? $row['employee_id'] ?? $row['biometric_pin'] ?? '');
            if ($identifier === '') {
                $errors[] = "Row {$line}: Missing student or employee identifier.";
                $skipped++;

                continue;
            }

            $user = User::where('id', $identifier)
                ->orWhere('email', $identifier)
                ->orWhere('student_id', $identifier)
                ->orWhere('employee_id', $identifier)
                ->orWhere('biometric_pin', $identifier)
                ->first();

            if (! $user) {
                $errors[] = "Row {$line}: No user found matching identifier '{$identifier}'.";
                $skipped++;

                continue;
            }

            $statusRaw = strtolower(trim($row['status'] ?? 'present'));
            $status = match ($statusRaw) {
                'p', 'present' => Attendance::STATUS_PRESENT,
                'a', 'absent' => Attendance::STATUS_ABSENT,
                'l', 'late' => Attendance::STATUS_LATE,
                'hd', 'half_day', 'half day' => Attendance::STATUS_HALF_DAY,
                'leave', 'on_leave', 'on leave' => Attendance::STATUS_ON_LEAVE,
                'holiday', 'h' => Attendance::STATUS_HOLIDAY,
                'weekly_off', 'off', 'w' => Attendance::STATUS_WEEKLY_OFF,
                default => Attendance::STATUS_PRESENT,
            };

            $userType = $user->isStudent() ? 'student' : 'staff';
            $courseId = null;
            $batchId = null;
            $sectionId = null;

            if ($userType === 'student') {
                $enrollment = $user->activeEnrollments()->first();
                if ($enrollment) {
                    $courseId = $enrollment->course_id;
                    $batchId = $enrollment->batch_id;
                    $sectionId = $enrollment->section_id;
                }
            }

            // Check approved leave
            $approvedLeave = LeaveApplication::where('user_id', $user->id)
                ->where('status', LeaveApplication::STATUS_APPROVED)
                ->where('from_date', '<=', $date)
                ->where('to_date', '>=', $date)
                ->first();

            $leaveId = null;
            if ($approvedLeave) {
                $status = Attendance::STATUS_ON_LEAVE;
                $leaveId = $approvedLeave->id;
            }

            $checkIn = ! empty($row['check_in_time'] ?? $row['check_in']) ? trim($row['check_in_time'] ?? $row['check_in']) : null;
            $checkOut = ! empty($row['check_out_time'] ?? $row['check_out']) ? trim($row['check_out_time'] ?? $row['check_out']) : null;
            $workingHours = (float) ($row['working_hours'] ?? 0.00);

            if ($checkIn && $checkOut && $workingHours <= 0.00) {
                try {
                    $in = Carbon::parse("{$date} {$checkIn}");
                    $out = Carbon::parse("{$date} {$checkOut}");
                    if ($out->gte($in)) {
                        $workingHours = round($in->diffInMinutes($out) / 60, 2);
                    }
                } catch (\Throwable) {
                    // Ignore parsing error
                }
            }

            try {
                DB::transaction(function () use ($user, $date, $userType, $courseId, $batchId, $sectionId, $status, $checkIn, $checkOut, $workingHours, $leaveId, $row, $marker, &$imported) {
                    $attendance = Attendance::firstOrNew([
                        'user_id' => $user->id,
                        'date' => $date,
                    ]);

                    if (! $attendance->exists) {
                        $attendance->attendance_no = Attendance::generateAttendanceNumber();
                        $attendance->created_by = $marker?->id;
                    }

                    $attendance->user_type = $userType;
                    $attendance->course_id = $courseId;
                    $attendance->batch_id = $batchId;
                    $attendance->section_id = $sectionId;
                    $attendance->status = $status;
                    $attendance->check_in_time = $checkIn;
                    $attendance->check_out_time = $checkOut;
                    $attendance->working_hours = $workingHours;
                    $attendance->entry_source = Attendance::SOURCE_IMPORT;
                    $attendance->leave_application_id = $leaveId;
                    $attendance->marked_by = $marker?->id;
                    $attendance->remarks = $row['remarks'] ?? 'Imported via spreadsheet';
                    $attendance->updated_by = $marker?->id;
                    $attendance->save();

                    $imported++;
                });
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: Failed to save ({$e->getMessage()}).";
                $skipped++;
            }
        }

        AuditLog::record(
            action: 'attendance_imported',
            details: ['imported' => $imported, 'skipped' => $skipped],
            userId: $marker?->id,
            description: "Bulk imported {$imported} attendance records from spreadsheet"
        );

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Stream attendance records to CSV.
     */
    public static function exportCsv(Builder $query, string $filename = 'attendance_register.csv'): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM

            fputcsv($handle, [
                'Attendance No',
                'Date',
                'User Type',
                'Name',
                'Email',
                'Student / Employee ID',
                'Course / Dept',
                'Batch',
                'Status',
                'Check In',
                'Check Out',
                'Hours Worked',
                'Late (Mins)',
                'Source',
                'Remarks',
            ]);

            $query->with(['user', 'course', 'batch'])
                ->chunk(200, function ($records) use ($handle) {
                    foreach ($records as $att) {
                        fputcsv($handle, [
                            $att->attendance_no,
                            $att->date ? $att->date->format('Y-m-d') : '',
                            ucfirst($att->user_type),
                            $att->user?->name ?? 'N/A',
                            $att->user?->email ?? 'N/A',
                            $att->user?->student_id ?? $att->user?->employee_id ?? 'N/A',
                            $att->course?->name ?? $att->user?->staff_department ?? 'N/A',
                            $att->batch?->name ?? 'N/A',
                            $att->statusLabel(),
                            $att->check_in_time ?? '',
                            $att->check_out_time ?? '',
                            $att->working_hours,
                            $att->late_minutes,
                            ucfirst(str_replace('_', ' ', $att->entry_source)),
                            $att->remarks ?? '',
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Export attendance register to formatted Excel (.xlsx) spreadsheet.
     */
    public static function exportExcel(Builder $query, string $filename = 'attendance_register.xlsx'): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance Register');

        $headers = [
            'Attendance No',
            'Date',
            'User Type',
            'Name',
            'Email',
            'Student / Employee ID',
            'Course / Dept',
            'Batch',
            'Status',
            'Check In',
            'Check Out',
            'Hours Worked',
            'Late (Mins)',
            'Source',
            'Remarks',
        ];

        // Header row
        foreach ($headers as $colIdx => $header) {
            $sheet->setCellValue([$colIdx + 1, 1], $header);
        }

        // Style header
        $highestColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$highestColumn.'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $rowIdx = 2;
        $query->with(['user', 'course', 'batch'])
            ->chunk(200, function ($records) use ($sheet, &$rowIdx) {
                foreach ($records as $att) {
                    $sheet->setCellValue([1, $rowIdx], $att->attendance_no);
                    $sheet->setCellValue([2, $rowIdx], $att->date ? $att->date->format('Y-m-d') : '');
                    $sheet->setCellValue([3, $rowIdx], ucfirst($att->user_type));
                    $sheet->setCellValue([4, $rowIdx], $att->user?->name ?? 'N/A');
                    $sheet->setCellValue([5, $rowIdx], $att->user?->email ?? 'N/A');
                    $sheet->setCellValue([6, $rowIdx], $att->user?->student_id ?? $att->user?->employee_id ?? 'N/A');
                    $sheet->setCellValue([7, $rowIdx], $att->course?->name ?? $att->user?->staff_department ?? 'N/A');
                    $sheet->setCellValue([8, $rowIdx], $att->batch?->name ?? 'N/A');
                    $sheet->setCellValue([9, $rowIdx], $att->statusLabel());
                    $sheet->setCellValue([10, $rowIdx], $att->check_in_time ?? '');
                    $sheet->setCellValue([11, $rowIdx], $att->check_out_time ?? '');
                    $sheet->setCellValue([12, $rowIdx], $att->working_hours);
                    $sheet->setCellValue([13, $rowIdx], $att->late_minutes);
                    $sheet->setCellValue([14, $rowIdx], ucfirst(str_replace('_', ' ', $att->entry_source)));
                    $sheet->setCellValue([15, $rowIdx], $att->remarks ?? '');
                    $rowIdx++;
                }
            });

        // Auto-size columns
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $tempPath = (string) tempnam(sys_get_temp_dir(), 'att_xlsx_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Download a sample CSV template for bulk attendance import.
     */
    public static function downloadSampleCsv(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="attendance_import_sample.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM

            fputcsv($handle, ['date', 'student_id', 'status', 'check_in_time', 'check_out_time', 'remarks']);
            fputcsv($handle, [Carbon::today()->toDateString(), 'STU-2026-0001', 'present', '09:30:00', '17:30:00', 'On time classroom session']);
            fputcsv($handle, [Carbon::today()->toDateString(), 'STU-2026-0002', 'late', '09:55:00', '17:30:00', 'Late by 25 mins']);
            fputcsv($handle, [Carbon::today()->toDateString(), 'EMP-1001', 'half_day', '09:30:00', '13:30:00', 'First half only']);
            fputcsv($handle, [Carbon::today()->toDateString(), 'student@example.com', 'absent', '', '', 'Uninformed leave']);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Export attendance register to high-resolution printable PDF.
     */
    public static function exportPdf(Builder $query, array $meta = []): StreamedResponse
    {
        $attendances = $query->with(['user', 'course', 'batch'])->limit(500)->get();

        $pdf = Pdf::loadView('pdf.attendance-register', [
            'attendances' => $attendances,
            'title' => $meta['title'] ?? __('Institutional Attendance Register'),
            'dateRange' => $meta['date_range'] ?? Carbon::now()->format('d M, Y'),
            'generatedAt' => Carbon::now()->format('d M, Y H:i:s'),
            'totalRecords' => $attendances->count(),
            'presentCount' => $attendances->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE, Attendance::STATUS_HALF_DAY])->count(),
            'absentCount' => $attendances->where('status', Attendance::STATUS_ABSENT)->count(),
            'leaveCount' => $attendances->where('status', Attendance::STATUS_ON_LEAVE)->count(),
        ])->setPaper('a4', 'landscape');

        $filename = 'attendance_register_'.Carbon::now()->format('Ymd_His').'.pdf';

        return response()->streamDownload(
            function () use ($pdf) {
                echo $pdf->output();
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }
}
