<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Course;
use App\Models\PartnerInstitute;
use App\Models\Section;
use App\Support\Export\TableExporter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PartnerInstituteService
{
    /**
     * Sanitize input data converting empty strings to null.
     */
    public static function sanitizeData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                $data[$key] = $trimmed === '' ? null : $trimmed;
            }
        }

        if (! empty($data['website_url']) && ! preg_match('~^(?:f|ht)tps?://~i', $data['website_url'])) {
            $data['website_url'] = 'https://'.$data['website_url'];
        }

        return $data;
    }

    /**
     * Create a new partner institute.
     */
    public static function createInstitute(array $data): PartnerInstitute
    {
        $data = static::sanitizeData($data);

        $batches = $data['batches'] ?? null;
        unset($data['batches']);
        $courses = $data['courses'] ?? null;
        unset($data['courses']);
        $sections = $data['sections'] ?? null;
        unset($data['sections']);

        return DB::transaction(function () use ($data, $batches, $courses, $sections) {
            $institute = PartnerInstitute::create($data);

            if ($batches !== null) {
                $institute->batches()->sync($batches);
            }
            if ($courses !== null) {
                $institute->courses()->sync($courses);
            }
            if ($sections !== null) {
                $institute->sections()->sync($sections);
            }

            AuditLogService::log(
                event: 'partner_institute_created',
                description: "Created partner coaching institute '{$institute->name}' ({$institute->code})",
                auditable: $institute,
                newValues: $institute->load(['batches', 'courses', 'sections'])->toArray(),
                userId: auth()->id()
            );

            return $institute;
        });
    }

    /**
     * Update an existing partner institute.
     */
    public static function updateInstitute(PartnerInstitute $institute, array $data): PartnerInstitute
    {
        $data = static::sanitizeData($data);

        $batches = array_key_exists('batches', $data) ? $data['batches'] : null;
        unset($data['batches']);
        $courses = array_key_exists('courses', $data) ? $data['courses'] : null;
        unset($data['courses']);
        $sections = array_key_exists('sections', $data) ? $data['sections'] : null;
        unset($data['sections']);

        return DB::transaction(function () use ($institute, $data, $batches, $courses, $sections) {
            $oldValues = $institute->load(['batches', 'courses', 'sections'])->toArray();
            $institute->update($data);

            if ($batches !== null) {
                $institute->batches()->sync($batches);
            }
            if ($courses !== null) {
                $institute->courses()->sync($courses);
            }
            if ($sections !== null) {
                $institute->sections()->sync($sections);
            }

            AuditLogService::log(
                event: 'partner_institute_updated',
                description: "Updated partner coaching institute '{$institute->name}' ({$institute->code})",
                auditable: $institute,
                oldValues: $oldValues,
                newValues: $institute->fresh(['batches', 'courses', 'sections'])->toArray(),
                userId: auth()->id()
            );

            return $institute;
        });
    }

    /**
     * Delete an existing partner institute (soft delete).
     */
    public static function deleteInstitute(PartnerInstitute $institute): bool
    {
        return DB::transaction(function () use ($institute) {
            $instituteId = $institute->id;
            $name = $institute->name;
            $code = $institute->code;

            $deleted = $institute->delete();

            AuditLogService::log(
                event: 'partner_institute_deleted',
                description: "Deleted partner coaching institute '{$name}' ({$code})",
                auditable: $institute,
                oldValues: ['id' => $instituteId, 'name' => $name, 'code' => $code],
                userId: auth()->id()
            );

            return (bool) $deleted;
        });
    }

    /**
     * Export partner institutes to Excel, CSV, or PDF.
     */
    public static function exportInstitutes(Builder|Collection|iterable $query, string $format = 'xlsx'): BinaryFileResponse|StreamedResponse|Response
    {
        if ($query instanceof Collection) {
            $builder = PartnerInstitute::whereIn('id', $query->pluck('id'));
        } elseif ($query instanceof Builder) {
            $builder = $query;
        } else {
            $builder = PartnerInstitute::query();
        }

        $headings = [
            'Institute Code',
            'Institute Name',
            'Status',
            'Associated Batches',
            'Associated Courses',
            'Associated Sections',
            'Email',
            'Mobile',
            'Phone',
            'Website URL',
            'MoU Start Date',
            'MoU End Date',
            'Key Official Name',
            'Designation',
            'Official Contact',
            'Official Email',
            'Address',
            'Faculties Associated',
        ];

        return TableExporter::download(
            $builder->with(['batches', 'courses', 'sections'])->withCount('faculties'),
            $format,
            'partner-institutes',
            $headings,
            function (PartnerInstitute $inst) {
                return [
                    $inst->code,
                    $inst->name,
                    $inst->status_label,
                    $inst->batches->pluck('name')->implode(', ') ?: 'All / None',
                    $inst->courses->pluck('name')->implode(', ') ?: 'All / None',
                    $inst->sections->pluck('name')->implode(', ') ?: 'All / None',
                    $inst->email ?: 'N/A',
                    $inst->mobile ?: 'N/A',
                    $inst->phone ?: 'N/A',
                    $inst->website_url ?: 'N/A',
                    $inst->start_date ? $inst->start_date->format('Y-m-d') : 'N/A',
                    $inst->end_date ? $inst->end_date->format('Y-m-d') : 'N/A',
                    $inst->official_name ?: 'N/A',
                    $inst->official_designation ?: 'N/A',
                    $inst->official_contact ?: 'N/A',
                    $inst->official_email ?: 'N/A',
                    $inst->address ?: 'N/A',
                    (string) ($inst->faculties_count ?? 0),
                ];
            },
            'Partner Coaching Institutes Master'
        );
    }

    /**
     * Import partner institutes from an Excel or CSV file.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importFromSpreadsheet(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, true);

        if (count($rows) <= 1) {
            return ['imported' => 0, 'errors' => ['Spreadsheet contains no data rows.']];
        }

        $imported = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            // First row is heading, iterate data rows
            $rowIndex = 1;
            foreach ($rows as $row) {
                $rowIndex++;
                if ($rowIndex === 2) {
                    continue; // Skip header
                }

                $name = trim((string) ($row['B'] ?? $row['A'] ?? ''));
                if (empty($name) || strtolower($name) === 'institute name') {
                    continue;
                }

                $code = trim((string) ($row['A'] ?? ''));
                $email = trim((string) ($row['C'] ?? ''));
                $mobile = trim((string) ($row['D'] ?? ''));
                $phone = trim((string) ($row['E'] ?? ''));
                $website = trim((string) ($row['F'] ?? ''));
                $startDate = trim((string) ($row['G'] ?? ''));
                $endDate = trim((string) ($row['H'] ?? ''));
                $status = strtolower(trim((string) ($row['I'] ?? 'active')));
                $officialName = trim((string) ($row['J'] ?? ''));
                $officialDesig = trim((string) ($row['K'] ?? ''));
                $officialContact = trim((string) ($row['L'] ?? ''));
                $officialEmail = trim((string) ($row['M'] ?? ''));
                $address = trim((string) ($row['N'] ?? ''));
                $batchCodesRaw = trim((string) ($row['O'] ?? ''));
                $courseCodesRaw = trim((string) ($row['P'] ?? ''));
                $sectionCodesRaw = trim((string) ($row['Q'] ?? ''));

                if (! in_array($status, ['active', 'inactive', 'mou_expired', 'pending'], true)) {
                    $status = 'active';
                }

                $validator = Validator::make([
                    'name' => $name,
                    'email' => $email ?: null,
                ], [
                    'name' => 'required|string|max:191',
                    'email' => 'nullable|email|max:191',
                ]);

                if ($validator->fails()) {
                    $errors[] = "Row {$rowIndex} [{$name}]: ".implode(' ', $validator->errors()->all());

                    continue;
                }

                $parsedStart = ! empty($startDate) ? rescue(fn () => Carbon::parse($startDate)->format('Y-m-d'), null, false) : null;
                $parsedEnd = ! empty($endDate) ? rescue(fn () => Carbon::parse($endDate)->format('Y-m-d'), null, false) : null;

                $inst = PartnerInstitute::updateOrCreate(
                    ['name' => $name],
                    [
                        'code' => $code ?: null,
                        'email' => $email ?: null,
                        'mobile' => $mobile ?: null,
                        'phone' => $phone ?: null,
                        'website_url' => $website ?: null,
                        'start_date' => $parsedStart,
                        'end_date' => $parsedEnd,
                        'status' => $status,
                        'official_name' => $officialName ?: null,
                        'official_designation' => $officialDesig ?: null,
                        'official_contact' => $officialContact ?: null,
                        'official_email' => $officialEmail ?: null,
                        'address' => $address ?: null,
                        'created_by' => auth()->id(),
                    ]
                );

                if (! empty($batchCodesRaw)) {
                    $batchNames = array_map('trim', explode(',', $batchCodesRaw));
                    $batchIds = Batch::whereIn('code', $batchNames)->orWhereIn('name', $batchNames)->pluck('id')->toArray();
                    if (! empty($batchIds)) {
                        $inst->batches()->syncWithoutDetaching($batchIds);
                    }
                }

                if (! empty($courseCodesRaw)) {
                    $courseNames = array_map('trim', explode(',', $courseCodesRaw));
                    $courseIds = Course::whereIn('code', $courseNames)->orWhereIn('name', $courseNames)->pluck('id')->toArray();
                    if (! empty($courseIds)) {
                        $inst->courses()->syncWithoutDetaching($courseIds);
                    }
                }

                if (! empty($sectionCodesRaw)) {
                    $sectionNames = array_map('trim', explode(',', $sectionCodesRaw));
                    $sectionIds = Section::whereIn('code', $sectionNames)->orWhereIn('name', $sectionNames)->pluck('id')->toArray();
                    if (! empty($sectionIds)) {
                        $inst->sections()->syncWithoutDetaching($sectionIds);
                    }
                }

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'partner_institutes_imported',
                description: "Imported {$imported} partner institutes from spreadsheet.",
                userId: auth()->id()
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    /**
     * Generate a sample Excel import template.
     */
    public static function generateSampleTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Partner Institutes Import');

        $headers = [
            'A1' => 'Institute Code (Optional)',
            'B1' => 'Institute Name (Required)',
            'C1' => 'Institute Email',
            'D1' => 'Institute Mobile',
            'E1' => 'Phone',
            'F1' => 'Website URL',
            'G1' => 'MoU Start Date (YYYY-MM-DD)',
            'H1' => 'MoU End Date (YYYY-MM-DD)',
            'I1' => 'Status (active/inactive/mou_expired/pending)',
            'J1' => 'Official In-charge Name',
            'K1' => 'Official Designation',
            'L1' => 'Official Contact',
            'M1' => 'Official Email',
            'N1' => 'Address / Campus Coordinates',
            'O1' => 'Associated Batch Codes (Comma-separated)',
            'P1' => 'Associated Course Codes (Comma-separated)',
            'Q1' => 'Associated Section Codes (Comma-separated)',
        ];

        foreach ($headers as $cell => $text) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        // Sample rows
        $sampleData = [
            ['INST-2026-001', 'Vision IAS Coaching Institute', 'contact@visionias.in', '9830012345', '033-22891234', 'https://visionias.in', '2026-01-01', '2027-12-31', 'active', 'Dr. Ajay Kumar', 'Director of Academics', '9830012346', 'ajay.kumar@visionias.in', 'Sector V, Salt Lake, Kolkata', 'BATCH-2026-GS1', 'UPSC-GS-PRELIMS', 'SEC-A'],
            ['INST-2026-002', 'Drishti Foundation IAS Centre', 'info@drishtiias.com', '9830098765', '033-22899876', 'https://drishtiias.com', '2026-02-15', '2028-02-14', 'active', 'Prof. Ramesh Chandra', 'Zonal Head', '9830098766', 'ramesh@drishtiias.com', 'College Street, Kolkata', '', '', ''],
        ];

        $rowIdx = 2;
        foreach ($sampleData as $row) {
            $colLetter = 'A';
            foreach ($row as $val) {
                $sheet->setCellValue("{$colLetter}{$rowIdx}", $val);
                $colLetter++;
            }
            $rowIdx++;
        }

        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'partner_inst_sample_');
        $writer->save($tempFile);

        return response()->download($tempFile, 'partner-institutes-sample-template.xlsx')->deleteFileAfterSend(true);
    }
}
