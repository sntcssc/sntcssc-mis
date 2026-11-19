<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\Section;
use App\Support\Export\TableExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClassroomService
{
    /**
     * Create a new classroom.
     */
    public static function createClassroom(array $data): Classroom
    {
        return DB::transaction(function () use ($data) {
            $classroom = Classroom::create($data);

            AuditLogService::log(
                event: 'classroom_created',
                description: "Created classroom {$classroom->room_code} ({$classroom->name}) in {$classroom->building}",
                auditable: $classroom,
                newValues: $classroom->toArray(),
                userId: auth()->id()
            );

            return $classroom;
        });
    }

    /**
     * Update an existing classroom.
     */
    public static function updateClassroom(Classroom $classroom, array $data): Classroom
    {
        return DB::transaction(function () use ($classroom, $data) {
            $oldValues = $classroom->toArray();
            $classroom->update($data);

            AuditLogService::log(
                event: 'classroom_updated',
                description: "Updated classroom {$classroom->room_code} ({$classroom->name})",
                auditable: $classroom,
                oldValues: $oldValues,
                newValues: $classroom->fresh()->toArray(),
                userId: auth()->id()
            );

            return $classroom->fresh();
        });
    }

    /**
     * Delete a classroom.
     */
    public static function deleteClassroom(Classroom $classroom): bool
    {
        return DB::transaction(function () use ($classroom) {
            $oldValues = $classroom->toArray();
            $deleted = $classroom->delete();

            AuditLogService::log(
                event: 'classroom_deleted',
                description: "Deleted classroom {$classroom->room_code} ({$classroom->name})",
                auditable: $classroom,
                oldValues: $oldValues,
                userId: auth()->id()
            );

            return (bool) $deleted;
        });
    }

    /**
     * Export classrooms query into Excel, CSV, or PDF.
     */
    public static function exportClassrooms(Builder|Collection|iterable $query, string $format = 'xlsx'): BinaryFileResponse|StreamedResponse|Response
    {
        $headings = [
            'Room Code',
            'Classroom Name',
            'Building',
            'Floor',
            'Capacity',
            'Room Type',
            'Status',
            'Facilities',
            'Assigned Courses',
            'Assigned Sections',
            'Remarks',
        ];

        if ($query instanceof Collection) {
            $builder = Classroom::whereIn('id', $query->pluck('id'));
        } elseif ($query instanceof Builder) {
            $builder = $query;
        } else {
            $builder = Classroom::query();
        }

        return TableExporter::download(
            $builder,
            $format,
            'classrooms-master',
            $headings,
            function (Classroom $room) {
                $courses = [];
                if (! empty($room->assigned_courses)) {
                    $courses = Course::whereIn('id', $room->assigned_courses)->pluck('name')->toArray();
                }

                $sections = [];
                if (! empty($room->assigned_sections)) {
                    $sections = Section::whereIn('id', $room->assigned_sections)->pluck('name')->toArray();
                }

                return [
                    $room->room_code,
                    $room->name,
                    $room->building,
                    $room->floor,
                    $room->capacity ?: 'N/A',
                    ucwords(str_replace('_', ' ', $room->room_type)),
                    ucfirst($room->status),
                    is_array($room->facilities) ? implode(', ', $room->facilities) : '',
                    implode(', ', $courses) ?: 'All Courses',
                    implode(', ', $sections) ?: 'All Sections',
                    $room->remarks ?: '',
                ];
            },
            'Classrooms Master Directory'
        );
    }

    /**
     * Import classrooms from uploaded Excel or CSV file.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function importFromSpreadsheet(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, true);

        if (count($rows) <= 1) {
            return ['imported' => 0, 'errors' => ['The uploaded spreadsheet is empty or has no data rows.']];
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

                $roomCode = trim((string) ($row['A'] ?? ''));
                $name = trim((string) ($row['B'] ?? ''));
                $building = trim((string) ($row['C'] ?? ''));
                $floor = trim((string) ($row['D'] ?? ''));
                $capacity = ! empty($row['E']) ? (int) $row['E'] : null;
                $roomType = trim((string) ($row['F'] ?? 'lecture_hall'));
                $status = strtolower(trim((string) ($row['G'] ?? 'active')));
                $facilitiesRaw = trim((string) ($row['H'] ?? ''));
                $remarks = trim((string) ($row['I'] ?? ''));

                if (empty($name) || empty($building) || empty($floor)) {
                    $errors[] = "Row {$rowIndex}: Classroom name, building, and floor are mandatory.";

                    continue;
                }

                $facilities = ! empty($facilitiesRaw) ? array_map('trim', explode(',', $facilitiesRaw)) : [];

                if (! in_array($status, ['active', 'inactive', 'maintenance'], true)) {
                    $status = 'active';
                }

                $validTypes = ['lecture_hall', 'seminar_room', 'smart_classroom', 'lab', 'auditorium', 'other'];
                $cleanType = strtolower(str_replace(' ', '_', $roomType));
                if (! in_array($cleanType, $validTypes, true)) {
                    $cleanType = 'lecture_hall';
                }

                Classroom::updateOrCreate(
                    ['room_code' => $roomCode ?: ('CR-'.str_pad((string) (Classroom::count() + 1), 3, '0', STR_PAD_LEFT))],
                    [
                        'name' => $name,
                        'building' => $building,
                        'floor' => $floor,
                        'capacity' => $capacity,
                        'room_type' => $cleanType,
                        'status' => $status,
                        'facilities' => $facilities,
                        'remarks' => $remarks,
                    ]
                );

                $imported++;
            }

            DB::commit();

            AuditLogService::log(
                event: 'classrooms_bulk_imported',
                description: "Bulk imported {$imported} classrooms from spreadsheet",
                newValues: ['imported_count' => $imported, 'errors_count' => count($errors)],
                userId: auth()->id()
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    /**
     * Generate an Excel template for classroom import.
     */
    public static function generateSampleTemplate(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Classrooms Template');

        $headers = [
            'A1' => 'Room Code (Optional)',
            'B1' => 'Classroom Name (Required)',
            'C1' => 'Building Name (Required)',
            'D1' => 'Floor (Required)',
            'E1' => 'Capacity (Numbers)',
            'F1' => 'Room Type (lecture_hall/seminar_room/smart_classroom/lab/auditorium)',
            'G1' => 'Status (active/inactive/maintenance)',
            'H1' => 'Facilities (Comma separated, e.g. Projector, AC, Smartboard)',
            'I1' => 'Remarks (Optional)',
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sampleRow = [
            'A2' => 'CR-101',
            'B2' => 'Hall A - Smart Lecture Room',
            'C2' => 'Main Academic Block',
            'D2' => '1st Floor',
            'E2' => 80,
            'F2' => 'smart_classroom',
            'G2' => 'active',
            'H2' => 'Projector, AC, Smartboard, Sound System, Wi-Fi',
            'I2' => 'Primary room for UPSC Prelims batches',
        ];

        foreach ($sampleRow as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'classroom_template_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        return $tmpPath;
    }
}
