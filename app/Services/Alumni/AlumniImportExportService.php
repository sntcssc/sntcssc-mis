<?php

namespace App\Services\Alumni;

use App\Models\Alumni;
use App\Models\AlumniCourseBatch;
use App\Models\Batch;
use App\Models\Course;
use App\Support\Export\TableExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AlumniImportExportService
{
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
                // Strip possible UTF-8 BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                $header = null;
                while (($data = fgetcsv($handle, 4000, ',')) !== false) {
                    if (! $header) {
                        $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), $data);
                    } else {
                        if (count($header) === count($data)) {
                            $rows[] = array_combine($header, $data);
                        }
                    }
                }
                fclose($handle);
            }
        } else {
            // Excel import (xlsx, xls, etc.)
            $sheet = Excel::toArray([], $filePath);
            if (! empty($sheet) && ! empty($sheet[0])) {
                $sheetRows = $sheet[0];
                $rawHeader = array_shift($sheetRows);
                $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), (array) $rawHeader);
                foreach ($sheetRows as $r) {
                    if (count($header) === count($r)) {
                        $rows[] = array_combine($header, $r);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Import alumni records from CSV or Excel file.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importAlumni(string $filePath): array
    {
        $rows = self::parseRowsFromFile($filePath);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;

            $firstName = trim($row['first_name'] ?? '');
            $lastName = trim($row['last_name'] ?? '');
            $fullName = trim($row['full_name'] ?? '');

            if ($fullName === '' && $firstName === '') {
                $skipped++;

                continue;
            }

            if ($fullName !== '' && $firstName === '') {
                $parts = explode(' ', $fullName, 2);
                $firstName = $parts[0] ?? 'Alumnus';
                $lastName = $parts[1] ?? '';
            }

            if ($fullName === '') {
                $fullName = trim($firstName.' '.$lastName);
            }

            $email = trim($row['email'] ?? '');
            if ($email !== '' && Alumni::where('email', $email)->exists()) {
                $errors[] = "Row {$line}: Email '{$email}' already exists in database. Skipped duplicate.";
                $skipped++;

                continue;
            }

            $serviceType = trim($row['service_type'] ?? 'UPSC CSE');
            $serviceName = trim($row['service_name'] ?? 'Civil Services');
            $designation = trim($row['designation'] ?? 'Officer Trainee');

            try {
                DB::transaction(function () use ($row, $firstName, $lastName, $fullName, $email, $serviceType, $serviceName, $designation, &$imported) {
                    $alumni = Alumni::create([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'full_name' => $fullName,
                        'email' => $email ?: null,
                        'phone' => trim($row['phone'] ?? '') ?: null,
                        'gender' => trim($row['gender'] ?? '') ?: null,
                        'passing_year' => ! empty($row['passing_year']) ? (int) $row['passing_year'] : null,
                        'service_type' => $serviceType,
                        'service_name' => $serviceName,
                        'designation' => $designation,
                        'cadre' => trim($row['cadre'] ?? '') ?: null,
                        'all_india_rank' => ! empty($row['all_india_rank'] ?? $row['air'] ?? null) ? (int) ($row['all_india_rank'] ?? $row['air']) : null,
                        'exam_year' => ! empty($row['exam_year']) ? (int) $row['exam_year'] : null,
                        'posting_city' => trim($row['posting_city'] ?? '') ?: null,
                        'posting_state' => trim($row['posting_state'] ?? '') ?: null,
                        'posting_country' => trim($row['posting_country'] ?? 'India') ?: 'India',
                        'department_ministry' => trim($row['department_ministry'] ?? $row['ministry'] ?? '') ?: null,
                        'short_bio' => trim($row['short_bio'] ?? $row['bio'] ?? '') ?: null,
                        'success_story' => trim($row['success_story'] ?? '') ?: null,
                        'testimonial' => trim($row['testimonial'] ?? '') ?: null,
                        'is_featured' => ! empty($row['is_featured']) && in_array(strtolower((string) $row['is_featured']), ['1', 'true', 'yes', 'y']),
                        'is_verified' => true,
                        'status' => strtolower(trim($row['status'] ?? 'active')) ?: 'active',
                        'source' => Alumni::SOURCE_IMPORT,
                    ]);

                    // Parse multiple courses and batches
                    $coursesStr = trim($row['courses'] ?? $row['course_name'] ?? '');
                    $batchesStr = trim($row['batches'] ?? $row['batch_name'] ?? '');

                    $coursesList = array_filter(array_map('trim', preg_split('/[;,]/', $coursesStr)));
                    $batchesList = array_filter(array_map('trim', preg_split('/[;,]/', $batchesStr)));

                    $count = max(count($coursesList), count($batchesList), 1);

                    for ($i = 0; $i < $count; $i++) {
                        $cName = $coursesList[$i] ?? ($coursesList[0] ?? 'Civil Services Guidance');
                        $bName = $batchesList[$i] ?? ($batchesList[0] ?? 'Regular Batch');

                        // Match system course/batch if exists
                        $course = Course::where('name', 'like', "%{$cName}%")->first();
                        $batch = Batch::where('name', 'like', "%{$bName}%")->first();

                        AlumniCourseBatch::create([
                            'alumni_id' => $alumni->id,
                            'course_id' => $course?->id,
                            'batch_id' => $batch?->id,
                            'course_name' => $cName,
                            'batch_name' => $bName,
                            'status' => 'completed',
                            'completion_year' => $alumni->passing_year ?: $alumni->exam_year,
                        ]);
                    }

                    $imported++;
                });
            } catch (Throwable $e) {
                $errors[] = "Row {$line}: ".$e->getMessage();
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Download alumni sample template (CSV).
     */
    public static function downloadTemplate(): StreamedResponse
    {
        $headers = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'gender',
            'passing_year',
            'service_type',
            'service_name',
            'designation',
            'cadre',
            'all_india_rank',
            'exam_year',
            'posting_city',
            'posting_state',
            'department_ministry',
            'courses',
            'batches',
            'short_bio',
            'success_story',
            'is_featured',
        ];

        $sampleRows = [
            [
                'Ananya',
                'Banerjee',
                'ananya.banerjee.ias@example.com',
                '+91 9876543210',
                'female',
                '2022',
                'UPSC CSE',
                'IAS',
                'Sub-Divisional Magistrate (SDM)',
                'West Bengal',
                '14',
                '2021',
                'Siliguri',
                'West Bengal',
                'Department of Personnel & Administrative Reforms',
                'UPSC Prelims Cum Mains Comprehensive; CSAT Quantitative',
                'Batch 2020-21; Test Series 2021',
                'SNTCSSC enabled me to crack UPSC in my first attempt with comprehensive mentorship and answer evaluation.',
                'Through dedicated mock tests and peer study groups at SNTCSSC, I secured AIR 14.',
                'yes',
            ],
            [
                'Sourav',
                'Mukherjee',
                'sourav.ips@example.com',
                '+91 9831122334',
                'male',
                '2023',
                'UPSC CSE',
                'IPS',
                'Assistant Commissioner of Police',
                'West Bengal',
                '48',
                '2022',
                'Kolkata',
                'West Bengal',
                'Home and Hill Affairs Department',
                'UPSC CSE Foundation; Interview Guidance Program',
                'Batch 2021-22; Mock Interview Panel',
                'Guided by top retired civil servants and expert faculty at SNTCSSC.',
                'Secured IPS in UPSC CSE 2022. Grateful to SNTCSSC mentors for personality test coaching.',
                'yes',
            ],
            [
                'Priyanka',
                'Dey',
                'priyanka.wbcs@example.com',
                '+91 9830556677',
                'female',
                '2023',
                'West Bengal Civil Service (WBCS)',
                'WBCS (Exe)',
                'Deputy Magistrate & Deputy Collector',
                'West Bengal',
                '5',
                '2022',
                'Burdwan',
                'West Bengal',
                'District Administration Purba Bardhaman',
                'WBCS Foundation Executive; General Studies Optional',
                'WBCS Batch 2022',
                'Rank 5 in WBCS (Exe) 2022 examination.',
                'SNTCSSC classroom study modules gave me complete command over Bengal history and current affairs.',
                'no',
            ],
        ];

        return TableExporter::csv($headers, $sampleRows, 'sntcssc-alumni-import-template.csv');
    }

    /**
     * Export alumni query to Excel, CSV, or PDF.
     *
     * @param  Builder<Alumni>  $query
     */
    public static function export(Builder $query, string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $headings = [
            'Full Name',
            'Email',
            'Phone',
            'Service Type',
            'Service / Cadre',
            'Designation',
            'Posting City & State',
            'Exam / Passing Year',
            'Rank (AIR)',
            'Enrolled Courses',
            'Enrolled Batches',
            'Featured',
            'Status',
        ];

        $mapper = fn (Alumni $a) => [
            $a->full_name,
            $a->email ?? 'N/A',
            $a->phone ?? 'N/A',
            $a->service_type,
            $a->service_name.($a->cadre ? " ({$a->cadre})" : ''),
            $a->designation,
            trim(($a->posting_city ? "{$a->posting_city}, " : '').($a->posting_state ?? 'India')),
            $a->exam_year ?: ($a->passing_year ?: 'N/A'),
            $a->all_india_rank ? "AIR {$a->all_india_rank}" : ($a->state_rank ? "Rank {$a->state_rank}" : 'N/A'),
            $a->courses_summary,
            $a->batches_summary,
            $a->is_featured ? 'Yes' : 'No',
            ucfirst($a->status),
        ];

        return TableExporter::download(
            query: $query,
            format: $format,
            filename: 'alumni-directory',
            headings: $headings,
            mapper: $mapper,
            title: 'SNTCSSC Alumni Directory & Success Register'
        );
    }
}
