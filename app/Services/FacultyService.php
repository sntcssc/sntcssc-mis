<?php

namespace App\Services;

use App\Concerns\RenamesUploads;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Support\Export\TableExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FacultyService
{
    use RenamesUploads;

    /**
     * Create a new faculty member with file handling, transactions, and audit logging.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFaculty(array $data, ?UploadedFile $photo = null, ?UploadedFile $cv = null): Faculty
    {
        return DB::transaction(function () use ($data, $photo, $cv) {
            $userId = auth()->id() ?? 'sys';

            if ($photo) {
                $filename = (new self)->generateFileName('faculty_photo', $photo, $userId);
                $path = $photo->storeAs('faculties/photos', $filename, 'public');
                $data['photo_path'] = $path;
                $data['avatar_path'] = $path;
            }

            if ($cv) {
                $filename = (new self)->generateFileName('faculty_cv', $cv, $userId);
                $path = $cv->storeAs('faculties/cvs', $filename, 'public');
                $data['cv_path'] = $path;
            }

            if (isset($data['subjects']) && is_string($data['subjects'])) {
                $data['subjects'] = array_filter(array_map('trim', explode(',', $data['subjects'])));
            }

            $faculty = Faculty::create([
                'user_id' => $data['user_id'] ?? null,
                'partner_institute_id' => $data['partner_institute_id'] ?? null,
                'faculty_code' => $data['faculty_code'] ?? null,
                'name' => trim($data['name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'designation' => $data['designation'] ?? 'Faculty Member',
                'faculty_type' => $data['faculty_type'] ?? 'regular',
                'department' => $data['department'] ?? 'General Studies',
                'specialization' => $data['specialization'] ?? null,
                'qualification' => $data['qualification'] ?? null,
                'bio' => $data['bio'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
                'avatar_path' => $data['avatar_path'] ?? null,
                'cv_path' => $data['cv_path'] ?? null,
                'status' => $data['status'] ?? 'active',
                'subjects' => $data['subjects'] ?? [],
            ]);

            AuditLogService::log(
                event: 'faculty_created',
                description: "Created faculty {$faculty->name} ({$faculty->faculty_code})",
                auditable: $faculty,
                newValues: $faculty->toArray(),
                userId: auth()->id()
            );

            return $faculty->load('partnerInstitute');
        });
    }

    /**
     * Update an existing faculty member with file replacement, transactions, and audit logging.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateFaculty(
        Faculty $faculty,
        array $data,
        ?UploadedFile $photo = null,
        ?UploadedFile $cv = null,
        bool $removePhoto = false,
        bool $removeCv = false
    ): Faculty {
        return DB::transaction(function () use ($faculty, $data, $photo, $cv, $removePhoto, $removeCv) {
            $oldValues = $faculty->toArray();
            $userId = auth()->id() ?? 'sys';

            if ($removePhoto && $faculty->photo_path) {
                if (Storage::disk('public')->exists($faculty->photo_path)) {
                    Storage::disk('public')->delete($faculty->photo_path);
                }
                $data['photo_path'] = null;
                $data['avatar_path'] = null;
            } elseif ($photo) {
                if ($faculty->photo_path && Storage::disk('public')->exists($faculty->photo_path)) {
                    Storage::disk('public')->delete($faculty->photo_path);
                }
                $filename = (new self)->generateFileName('faculty_photo', $photo, $userId);
                $path = $photo->storeAs('faculties/photos', $filename, 'public');
                $data['photo_path'] = $path;
                $data['avatar_path'] = $path;
            }

            if ($removeCv && $faculty->cv_path) {
                if (Storage::disk('public')->exists($faculty->cv_path)) {
                    Storage::disk('public')->delete($faculty->cv_path);
                }
                $data['cv_path'] = null;
            } elseif ($cv) {
                if ($faculty->cv_path && Storage::disk('public')->exists($faculty->cv_path)) {
                    Storage::disk('public')->delete($faculty->cv_path);
                }
                $filename = (new self)->generateFileName('faculty_cv', $cv, $userId);
                $path = $cv->storeAs('faculties/cvs', $filename, 'public');
                $data['cv_path'] = $path;
            }

            if (isset($data['subjects']) && is_string($data['subjects'])) {
                $data['subjects'] = array_filter(array_map('trim', explode(',', $data['subjects'])));
            }

            $faculty->update(array_filter($data, fn ($value) => $value !== null || in_array($value, [null, ''], true)));

            AuditLogService::log(
                event: 'faculty_updated',
                description: "Updated faculty {$faculty->name} ({$faculty->faculty_code})",
                auditable: $faculty,
                oldValues: $oldValues,
                newValues: $faculty->fresh()->toArray(),
                userId: auth()->id()
            );

            return $faculty->fresh()->load('partnerInstitute');
        });
    }

    /**
     * Soft delete a faculty member with audit logging.
     */
    public static function deleteFaculty(Faculty $faculty): bool
    {
        return DB::transaction(function () use ($faculty) {
            $code = $faculty->faculty_code;
            $name = $faculty->name;

            $faculty->delete();

            AuditLogService::log(
                event: 'faculty_deleted',
                description: "Soft deleted faculty {$name} ({$code})",
                auditable: $faculty,
                userId: auth()->id()
            );

            return true;
        });
    }

    /**
     * Restore a soft-deleted faculty member.
     */
    public static function restoreFaculty(Faculty $faculty): bool
    {
        return DB::transaction(function () use ($faculty) {
            $faculty->restore();

            AuditLogService::log(
                event: 'faculty_restored',
                description: "Restored faculty {$faculty->name} ({$faculty->faculty_code})",
                auditable: $faculty,
                userId: auth()->id()
            );

            return true;
        });
    }

    /**
     * Toggle faculty status between active and inactive.
     */
    public static function toggleStatus(Faculty $faculty): string
    {
        return DB::transaction(function () use ($faculty) {
            $newStatus = $faculty->status === 'active' ? 'inactive' : 'active';
            $oldStatus = $faculty->status;

            $faculty->update(['status' => $newStatus]);

            AuditLogService::log(
                event: 'faculty_status_toggled',
                description: "Toggled status of faculty {$faculty->name} from {$oldStatus} to {$newStatus}",
                auditable: $faculty,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus],
                userId: auth()->id()
            );

            return $newStatus;
        });
    }

    /**
     * Compute comprehensive workload analytics for a specific faculty member.
     *
     * @return array<string, mixed>
     */
    public static function getFacultyWorkloadStats(Faculty $faculty): array
    {
        $schedules = $faculty->classSchedules()->with(['batch', 'course', 'section'])->get();

        $totalAssigned = $schedules->count();
        $conducted = $schedules->where('status', 'completed')->count();
        $upcoming = $schedules->whereIn('status', ['scheduled', 'rescheduled'])->where('class_date', '>=', today()->toDateString())->count();
        $cancelled = $schedules->where('status', 'cancelled')->count();

        $totalHoursTaught = 0.0;
        foreach ($schedules->where('status', 'completed') as $s) {
            if ($s->start_time && $s->end_time) {
                $start = Carbon::parse($s->start_time);
                $end = Carbon::parse($s->end_time);
                $diff = $start->diffInMinutes($end) / 60.0;
                $totalHoursTaught += max(0, $diff);
            }
        }

        $batchesTaught = $schedules->pluck('batch.name')->filter()->unique()->values()->all();
        $coursesTaught = $schedules->pluck('course.name')->filter()->unique()->values()->all();

        return [
            'faculty_id' => $faculty->id,
            'faculty_name' => $faculty->name,
            'faculty_code' => $faculty->faculty_code,
            'department' => $faculty->department,
            'faculty_type' => $faculty->faculty_type,
            'status' => $faculty->status,
            'total_assigned_classes' => $totalAssigned,
            'conducted_classes' => $conducted,
            'upcoming_classes' => $upcoming,
            'cancelled_classes' => $cancelled,
            'total_hours_taught' => round($totalHoursTaught, 2),
            'completion_rate' => $totalAssigned > 0 ? round(($conducted / $totalAssigned) * 100, 1) : 0,
            'batches_taught' => $batchesTaught,
            'courses_taught' => $coursesTaught,
        ];
    }

    /**
     * Compute workload analytics across all faculties.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getGlobalWorkloadStats(): array
    {
        $faculties = Faculty::with(['classSchedules', 'partnerInstitute'])->get();

        return $faculties->map(fn (Faculty $f) => self::getFacultyWorkloadStats($f))->toArray();
    }

    /**
     * Export faculties in Excel, CSV, or PDF format.
     */
    public static function exportFaculties(Builder|Collection|iterable $query, string $format = 'xlsx'): BinaryFileResponse|StreamedResponse|Response
    {
        $faculties = $query instanceof Builder ? $query->get() : collect($query);

        $headers = [
            'ID',
            'Faculty Code',
            'Name',
            'Email',
            'Phone',
            'Designation',
            'Faculty Type',
            'Department',
            'Partner Institute',
            'Specialization',
            'Qualification',
            'Status',
            'Assigned Classes',
            'Created At',
        ];

        $rows = $faculties->map(function (Faculty $f) {
            return [
                $f->id,
                $f->faculty_code,
                $f->name,
                $f->email ?? 'N/A',
                $f->phone ?? 'N/A',
                $f->designation ?? 'N/A',
                $f->faculty_type_label,
                $f->department,
                $f->partnerInstitute?->name ?? 'Direct SNTCSSC',
                $f->specialization ?? 'N/A',
                $f->qualification ?? 'N/A',
                ucfirst($f->status),
                $f->classSchedules()->count(),
                $f->created_at?->format('d M Y, h:i A') ?? 'N/A',
            ];
        })->toArray();

        $filename = 'faculties-directory-'.now()->format('Ymd_His');

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.table-pdf', [
                'title' => 'Faculty Master Directory',
                'meta' => 'Generated on '.now()->format('d M Y, h:i A').' | Total Records: '.count($rows),
                'headers' => $headers,
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                "{$filename}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        }

        if ($format === 'csv') {
            return TableExporter::downloadCsv($headers, $rows, "{$filename}.csv");
        }

        return TableExporter::downloadExcel($headers, $rows, "{$filename}.xlsx", 'Faculties Directory');
    }

    /**
     * Bulk import faculties from an uploaded spreadsheet or CSV.
     *
     * @return array{success: bool, imported: int, updated: int, errors: array<string>}
     */
    public static function importFromSpreadsheet(UploadedFile|string $file): array
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            return [
                'success' => false,
                'imported' => 0,
                'updated' => 0,
                'errors' => ['File is empty or contains only header row.'],
            ];
        }

        $header = array_map(fn ($col) => strtolower(trim((string) $col)), array_shift($rows));
        $imported = 0;
        $updated = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index;
                $rowAssoc = [];
                $colIndex = 0;
                foreach ($header as $colKey) {
                    $letter = chr(65 + $colIndex);
                    $rowAssoc[$colKey] = trim((string) ($row[$letter] ?? ''));
                    $colIndex++;
                }

                $name = $rowAssoc['name'] ?? $rowAssoc['faculty_name'] ?? null;
                if (! $name) {
                    continue;
                }

                $email = ! empty($rowAssoc['email']) ? $rowAssoc['email'] : null;
                $facultyCode = ! empty($rowAssoc['faculty_code']) ? $rowAssoc['faculty_code'] : null;

                // Match existing faculty by code or email
                $faculty = null;
                if ($facultyCode) {
                    $faculty = Faculty::where('faculty_code', $facultyCode)->first();
                }
                if (! $faculty && $email) {
                    $faculty = Faculty::where('email', $email)->first();
                }

                $partnerInstId = null;
                $instName = $rowAssoc['partner_institute'] ?? $rowAssoc['institute'] ?? null;
                if ($instName) {
                    $inst = PartnerInstitute::where('name', 'like', "%{$instName}%")
                        ->orWhere('code', $instName)
                        ->first();
                    $partnerInstId = $inst?->id;
                }

                $facultyData = [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $rowAssoc['phone'] ?? $rowAssoc['mobile'] ?? null,
                    'designation' => $rowAssoc['designation'] ?? 'Faculty Member',
                    'department' => $rowAssoc['department'] ?? 'General Studies',
                    'faculty_type' => in_array(strtolower($rowAssoc['faculty_type'] ?? ''), array_keys(Faculty::FACULTY_TYPES), true)
                        ? strtolower($rowAssoc['faculty_type'])
                        : 'regular',
                    'partner_institute_id' => $partnerInstId,
                    'specialization' => $rowAssoc['specialization'] ?? null,
                    'qualification' => $rowAssoc['qualification'] ?? null,
                    'bio' => $rowAssoc['bio'] ?? null,
                    'status' => in_array(strtolower($rowAssoc['status'] ?? ''), ['active', 'inactive', 'on_leave'], true)
                        ? strtolower($rowAssoc['status'])
                        : 'active',
                ];

                if (! empty($rowAssoc['subjects'])) {
                    $facultyData['subjects'] = array_filter(array_map('trim', explode(',', $rowAssoc['subjects'])));
                }

                if ($faculty) {
                    $faculty->update($facultyData);
                    $updated++;
                } else {
                    Faculty::create($facultyData);
                    $imported++;
                }
            }

            DB::commit();

            AuditLogService::log(
                event: 'faculties_bulk_imported',
                description: "Bulk imported {$imported} faculties, updated {$updated}",
                userId: auth()->id()
            );

            return [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'imported' => 0,
                'updated' => 0,
                'errors' => ['Import failed: '.$e->getMessage()],
            ];
        }
    }
}
