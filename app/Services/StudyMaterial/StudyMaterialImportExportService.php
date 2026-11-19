<?php

namespace App\Services\StudyMaterial;

use App\Models\Batch;
use App\Models\Course;
use App\Models\StudyMaterial;
use App\Support\Export\TableExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class StudyMaterialImportExportService
{
    /**
     * Parse rows from either CSV or Excel file.
     *
     * @return array<int, array<string, string|null>>
     */
    public static function parseRowsFromFile(string $filePath): array
    {
        $rows = [];
        $isExcel = str_ends_with(strtolower($filePath), '.xlsx') || str_ends_with(strtolower($filePath), '.xls');

        if (! $isExcel) {
            if (($handle = fopen($filePath, 'r')) !== false) {
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
            // Excel import
            try {
                $import = new class implements ToArray
                {
                    public function array(array $array): array
                    {
                        return $array;
                    }
                };
                $sheet = Excel::toArray($import, $filePath);
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
            } catch (Throwable) {
                // Fallback to CSV parser
                if (($handle = fopen($filePath, 'r')) !== false) {
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
            }
        }

        return $rows;
    }

    /**
     * Import study material records from CSV or Excel spreadsheet.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importMaterials(string $filePath): array
    {
        $rows = self::parseRowsFromFile($filePath);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;

            $title = trim($row['title'] ?? '');
            $courseIdentifier = trim($row['course_code'] ?? ($row['course_name'] ?? ''));

            if ($title === '' || $courseIdentifier === '') {
                $skipped++;

                continue;
            }

            // Resolve course
            $course = Course::where('code', $courseIdentifier)
                ->orWhere('name', 'like', "%{$courseIdentifier}%")
                ->first();

            if (! $course) {
                $errors[] = "Line {$line}: Course '{$courseIdentifier}' not found.";
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($row, $course, $title, &$imported) {
                    $materialType = trim(strtolower($row['material_type'] ?? 'lecture_notes'));
                    $validTypes = array_keys(StudyMaterial::materialTypes());
                    if (! in_array($materialType, $validTypes, true)) {
                        $materialType = StudyMaterial::TYPE_LECTURE_NOTES;
                    }

                    $status = trim(strtolower($row['status'] ?? 'published'));
                    if (! in_array($status, ['published', 'draft', 'archived'], true)) {
                        $status = 'published';
                    }

                    $material = StudyMaterial::create([
                        'course_id' => $course->id,
                        'title' => $title,
                        'subject' => ! empty($row['subject']) ? trim($row['subject']) : null,
                        'topic' => ! empty($row['topic']) ? trim($row['topic']) : null,
                        'module_code' => ! empty($row['module_code']) ? trim($row['module_code']) : null,
                        'material_type' => $materialType,
                        'description' => ! empty($row['description']) ? trim($row['description']) : null,
                        'content' => ! empty($row['content']) ? trim($row['content']) : null,
                        'external_url' => ! empty($row['external_url']) ? trim($row['external_url']) : null,
                        'access_level' => ! empty($row['access_level']) ? trim($row['access_level']) : StudyMaterial::ACCESS_ENROLLED_ONLY,
                        'is_pinned' => strtolower(trim($row['is_pinned'] ?? 'no')) === 'yes' || strtolower(trim($row['is_pinned'] ?? '0')) === '1',
                        'status' => $status,
                        'created_by' => auth()->id(),
                    ]);

                    // Resolve batch associations if provided (comma-separated codes or names)
                    $batchesRaw = trim($row['batch_codes'] ?? ($row['batches'] ?? ''));
                    if ($batchesRaw !== '') {
                        $batchNames = array_map('trim', explode(';', $batchesRaw));
                        if (count($batchNames) === 1 && str_contains($batchesRaw, ',')) {
                            $batchNames = array_map('trim', explode(',', $batchesRaw));
                        }

                        $batchIds = Batch::where(function ($q) use ($batchNames) {
                            $q->whereIn('code', $batchNames)
                                ->orWhereIn('name', $batchNames);
                        })->pluck('id')->all();

                        if (! empty($batchIds)) {
                            $material->batches()->sync($batchIds);
                        }
                    }

                    $imported++;
                });
            } catch (Throwable $e) {
                $errors[] = "Line {$line}: Failed to import '{$title}' - {$e->getMessage()}";
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
     * Download sample CSV template with instructional headers.
     */
    public static function downloadTemplate(): StreamedResponse
    {
        $headers = [
            'Title',
            'Course_Code',
            'Batches',
            'Subject',
            'Topic',
            'Module_Code',
            'Material_Type',
            'Description',
            'External_URL',
            'Access_Level',
            'Is_Pinned',
            'Status',
        ];

        $sampleRows = [
            [
                'Indian Polity & Constitution - Comprehensive Preamble Notes',
                'UPSC-CSE-F',
                'Batch 2026-A; Batch 2026-B',
                'Indian Polity',
                'Preamble & Basic Structure',
                'GS-POL-01',
                'lecture_notes',
                'Detailed notes covering Supreme Court landmark judgments (Kesavananda Bharati, Minerva Mills, LIC).',
                'https://drive.google.com/open?id=example123',
                'enrolled_only',
                'yes',
                'published',
            ],
            [
                'Modern Indian History - Revolt of 1857 Lecture Slides',
                'WBCS-EXE-26',
                'WBCS Regular 2026',
                'Modern History',
                'Revolt of 1857 & Leaders of Bengal',
                'GS-HIS-04',
                'slide_deck',
                'High-definition lecture presentation covering civil rebellions and peasant uprisings in Bengal.',
                'https://youtu.be/exampleSlideVideo',
                'enrolled_only',
                'no',
                'published',
            ],
            [
                'General Studies Paper II CSAT Mock Test 1 with Solutions',
                'UPSC-CSE-F',
                '',
                'Aptitude & Reasoning',
                'Reading Comprehension & Syllogism',
                'CSAT-01',
                'question_bank',
                'Mock test paper containing 80 questions with step-by-step explanatory answer keys.',
                '',
                'enrolled_only',
                'no',
                'published',
            ],
        ];

        return TableExporter::csv($headers, $sampleRows, 'sntcssc-study-materials-template.csv');
    }

    /**
     * Export study materials query to Excel, CSV, or PDF.
     *
     * @param  Builder<StudyMaterial>  $query
     */
    public static function export(Builder $query, string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $headings = [
            'Title',
            'Course',
            'Batches',
            'Subject',
            'Topic',
            'Material Type',
            'Total Files',
            'Total Size',
            'Views',
            'Downloads',
            'Access Level',
            'Status',
            'Uploaded At',
        ];

        $mapper = fn (StudyMaterial $m) => [
            $m->title,
            $m->course?->name ?? 'N/A',
            $m->batches_summary,
            $m->subject ?? 'N/A',
            $m->topic ? "{$m->topic} ".($m->module_code ? "({$m->module_code})" : '') : 'N/A',
            $m->material_type_label,
            $m->files()->count(),
            $m->formatted_total_size,
            $m->view_count,
            $m->download_count,
            ucfirst(str_replace('_', ' ', $m->access_level)),
            ucfirst($m->status),
            $m->created_at ? $m->created_at->format('d M Y, h:i A') : 'N/A',
        ];

        return TableExporter::download(
            query: $query,
            format: $format,
            filename: 'study-materials-catalog',
            headings: $headings,
            mapper: $mapper,
            title: 'SNTCSSC Study Materials & Academic Resources Catalog'
        );
    }
}
