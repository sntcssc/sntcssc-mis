<?php

namespace App\Services;

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\AcademicCalendarVersion;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Export\TableExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AcademicCalendarService
{
    /**
     * Create a new Academic Calendar, optionally with its first official document file.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public static function createCalendar(array $data, ?UploadedFile $file = null, ?User $user = null): AcademicCalendar
    {
        $userId = $user?->id ?? auth()->id();

        return DB::transaction(function () use ($data, $file, $userId) {
            $isCurrent = (bool) ($data['is_current'] ?? false);

            if ($isCurrent) {
                AcademicCalendar::where('is_current', true)->update(['is_current' => false]);
            }

            $calendar = AcademicCalendar::create([
                'calendar_code' => $data['calendar_code'] ?? AcademicCalendar::generateCode($data['academic_year'] ?? date('Y')),
                'academic_year' => $data['academic_year'],
                'title' => $data['title'],
                'theme_or_motto' => $data['theme_or_motto'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'total_working_days' => (int) ($data['total_working_days'] ?? 0),
                'total_instructional_days' => (int) ($data['total_instructional_days'] ?? 0),
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'published',
                'is_current' => $isCurrent,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            if ($file) {
                $versionCode = $data['initial_version_code'] ?? 'v1.0';
                $versionTitle = $data['initial_version_title'] ?? 'Initial Approved Calendar';
                $changeSummary = $data['initial_change_summary'] ?? 'Official inaugural publication of the academic calendar.';

                $version = static::storeVersionFile($calendar, $file, [
                    'version_code' => $versionCode,
                    'revision_number' => 1,
                    'version_title' => $versionTitle,
                    'change_summary' => $changeSummary,
                    'approved_by' => $data['approved_by'] ?? 'Academic Council',
                    'approval_date' => $data['approval_date'] ?? $data['start_date'],
                    'effective_from' => $data['effective_from'] ?? $data['start_date'],
                    'is_current' => true,
                    'uploaded_by' => $userId,
                ]);

                $calendar->update(['current_version_id' => $version->id]);
            }

            AuditLog::record(
                action: 'academic_calendar.created',
                details: [
                    'calendar_code' => $calendar->calendar_code,
                    'academic_year' => $calendar->academic_year,
                    'title' => $calendar->title,
                    'is_current' => $calendar->is_current,
                ],
                userId: $userId,
                type: 'academic_calendar',
                status: 'success',
                auditableType: AcademicCalendar::class,
                auditableId: $calendar->id,
                description: "Created Academic Calendar {$calendar->calendar_code} for session {$calendar->academic_year}."
            );

            return $calendar->load(['currentVersion', 'versions']);
        });
    }

    /**
     * Update an Academic Calendar's metadata.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public static function updateCalendar(AcademicCalendar $calendar, array $data, ?User $user = null): AcademicCalendar
    {
        $userId = $user?->id ?? auth()->id();

        return DB::transaction(function () use ($calendar, $data, $userId) {
            $isCurrent = (bool) ($data['is_current'] ?? $calendar->is_current);

            if ($isCurrent && ! $calendar->is_current) {
                AcademicCalendar::where('id', '!=', $calendar->id)->update(['is_current' => false]);
            }

            $calendar->update([
                'academic_year' => $data['academic_year'] ?? $calendar->academic_year,
                'title' => $data['title'] ?? $calendar->title,
                'theme_or_motto' => $data['theme_or_motto'] ?? $calendar->theme_or_motto,
                'start_date' => $data['start_date'] ?? $calendar->start_date,
                'end_date' => $data['end_date'] ?? $calendar->end_date,
                'total_working_days' => isset($data['total_working_days']) ? (int) $data['total_working_days'] : $calendar->total_working_days,
                'total_instructional_days' => isset($data['total_instructional_days']) ? (int) $data['total_instructional_days'] : $calendar->total_instructional_days,
                'description' => $data['description'] ?? $calendar->description,
                'status' => $data['status'] ?? $calendar->status,
                'is_current' => $isCurrent,
                'updated_by' => $userId,
            ]);

            AuditLog::record(
                action: 'academic_calendar.updated',
                details: [
                    'calendar_code' => $calendar->calendar_code,
                    'academic_year' => $calendar->academic_year,
                    'title' => $calendar->title,
                ],
                userId: $userId,
                type: 'academic_calendar',
                status: 'success',
                auditableType: AcademicCalendar::class,
                auditableId: $calendar->id,
                description: "Updated Academic Calendar {$calendar->calendar_code}."
            );

            return $calendar;
        });
    }

    /**
     * Set a calendar as current active academic calendar.
     */
    public static function setCurrentCalendar(AcademicCalendar $calendar, ?User $user = null): void
    {
        $userId = $user?->id ?? auth()->id();

        DB::transaction(function () use ($calendar, $userId) {
            AcademicCalendar::where('id', '!=', $calendar->id)->update(['is_current' => false]);
            $calendar->update([
                'is_current' => true,
                'updated_by' => $userId,
            ]);

            AuditLog::record(
                action: 'academic_calendar.set_current',
                details: ['academic_year' => $calendar->academic_year],
                userId: $userId,
                type: 'academic_calendar',
                status: 'success',
                auditableType: AcademicCalendar::class,
                auditableId: $calendar->id,
                description: "Marked Academic Calendar {$calendar->calendar_code} ({$calendar->academic_year}) as currently active session."
            );
        });
    }

    /**
     * Upload a new revision version for an academic calendar.
     * Preserves all previous versions year-wise and updates latest pointer.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public static function uploadRevision(AcademicCalendar $calendar, UploadedFile $file, array $data, ?User $user = null): AcademicCalendarVersion
    {
        $userId = $user?->id ?? auth()->id();

        return DB::transaction(function () use ($calendar, $file, $data, $userId) {
            $maxRev = (int) $calendar->versions()->max('revision_number') ?? 0;
            $nextRev = $maxRev + 1;

            $versionCode = $data['version_code'] ?? ('v1.'.($nextRev - 1));
            if ($maxRev === 0) {
                $versionCode = $data['version_code'] ?? 'v1.0';
            }

            // Deactivate previous active version
            $calendar->versions()->update(['is_current' => false]);

            $version = static::storeVersionFile($calendar, $file, [
                'version_code' => $versionCode,
                'revision_number' => $nextRev,
                'version_title' => $data['version_title'] ?? ("Revision {$nextRev} - ".now()->format('M Y')),
                'change_summary' => $data['change_summary'] ?? 'Calendar schedule revision update.',
                'approved_by' => $data['approved_by'] ?? null,
                'approval_date' => $data['approval_date'] ?? now()->toDateString(),
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'is_current' => true,
                'uploaded_by' => $userId,
            ]);

            // Update master pointer
            $calendar->update([
                'current_version_id' => $version->id,
                'updated_by' => $userId,
            ]);

            AuditLog::record(
                action: 'academic_calendar.version_uploaded',
                details: [
                    'calendar_code' => $calendar->calendar_code,
                    'version_code' => $version->version_code,
                    'revision_number' => $version->revision_number,
                    'file_name' => $version->file_name,
                    'file_size' => $version->human_file_size,
                ],
                userId: $userId,
                type: 'academic_calendar',
                status: 'success',
                auditableType: AcademicCalendarVersion::class,
                auditableId: $version->id,
                description: "Uploaded new Academic Calendar revision {$version->version_code} for {$calendar->academic_year}."
            );

            return $version;
        });
    }

    /**
     * Internal helper to store uploaded document file and create version record.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function storeVersionFile(AcademicCalendar $calendar, UploadedFile $file, array $attributes): AcademicCalendarVersion
    {
        $calendarFolder = 'academic_calendars/'.Str::slug($calendar->calendar_code);
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension() ?: 'pdf';
        $safeName = 'calendar_'.Str::slug($attributes['version_code']).'_'.now()->format('YmdHis').'.'.$extension;

        $path = $file->storeAs($calendarFolder, $safeName, 'public');

        return AcademicCalendarVersion::create([
            'academic_calendar_id' => $calendar->id,
            'version_code' => $attributes['version_code'],
            'revision_number' => $attributes['revision_number'],
            'version_title' => $attributes['version_title'],
            'file_path' => $path,
            'file_name' => $originalName,
            'file_size' => $file->getSize(),
            'file_mime' => $file->getClientMimeType(),
            'change_summary' => $attributes['change_summary'] ?? null,
            'approved_by' => $attributes['approved_by'] ?? null,
            'approval_date' => $attributes['approval_date'] ?? null,
            'effective_from' => $attributes['effective_from'] ?? null,
            'is_current' => (bool) ($attributes['is_current'] ?? false),
            'download_count' => 0,
            'uploaded_by' => $attributes['uploaded_by'] ?? null,
            'created_by' => $attributes['uploaded_by'] ?? null,
            'updated_by' => $attributes['uploaded_by'] ?? null,
        ]);
    }

    /**
     * Create an activity under an academic calendar.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createActivity(AcademicCalendar $calendar, array $data, ?User $user = null): AcademicCalendarActivity
    {
        $userId = $user?->id ?? auth()->id();

        $startDate = Carbon::parse($data['start_date']);
        $endDate = ! empty($data['end_date']) ? Carbon::parse($data['end_date']) : null;

        $activity = AcademicCalendarActivity::create([
            'academic_calendar_id' => $calendar->id,
            'academic_calendar_version_id' => $data['academic_calendar_version_id'] ?? $calendar->current_version_id,
            'activity_code' => $data['activity_code'] ?? AcademicCalendarActivity::generateCode((int) $startDate->format('Y')),
            'title' => $data['title'],
            'category' => $data['category'] ?? 'academic',
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate?->toDateString(),
            'month' => (int) $startDate->format('n'),
            'year' => (int) $startDate->format('Y'),
            'target_audience' => $data['target_audience'] ?? 'all',
            'department' => $data['department'] ?? null,
            'venue_mode' => $data['venue_mode'] ?? 'offline',
            'venue_or_link' => $data['venue_or_link'] ?? null,
            'is_tentative' => (bool) ($data['is_tentative'] ?? false),
            'is_notified' => (bool) ($data['is_notified'] ?? false),
            'status' => $data['status'] ?? 'planned',
            'description' => $data['description'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'display_order' => (int) ($data['display_order'] ?? 0),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        AuditLog::record(
            action: 'academic_calendar.activity_created',
            details: [
                'activity_code' => $activity->activity_code,
                'title' => $activity->title,
                'category' => $activity->category,
                'start_date' => $activity->start_date->toDateString(),
            ],
            userId: $userId,
            type: 'academic_calendar',
            status: 'success',
            auditableType: AcademicCalendarActivity::class,
            auditableId: $activity->id,
            description: "Created Academic Activity {$activity->title} ({$activity->activity_code})."
        );

        return $activity;
    }

    /**
     * Update an activity.
     *
     * @param  array<string, mixed>  $data
     */
    public static function updateActivity(AcademicCalendarActivity $activity, array $data, ?User $user = null): AcademicCalendarActivity
    {
        $userId = $user?->id ?? auth()->id();

        $startDate = isset($data['start_date']) ? Carbon::parse($data['start_date']) : $activity->start_date;
        $endDate = isset($data['end_date']) && ! empty($data['end_date']) ? Carbon::parse($data['end_date']) : (array_key_exists('end_date', $data) ? null : $activity->end_date);

        $activity->update([
            'academic_calendar_version_id' => $data['academic_calendar_version_id'] ?? $activity->academic_calendar_version_id,
            'title' => $data['title'] ?? $activity->title,
            'category' => $data['category'] ?? $activity->category,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate?->toDateString(),
            'month' => (int) $startDate->format('n'),
            'year' => (int) $startDate->format('Y'),
            'target_audience' => $data['target_audience'] ?? $activity->target_audience,
            'department' => $data['department'] ?? $activity->department,
            'venue_mode' => $data['venue_mode'] ?? $activity->venue_mode,
            'venue_or_link' => $data['venue_or_link'] ?? $activity->venue_or_link,
            'is_tentative' => isset($data['is_tentative']) ? (bool) $data['is_tentative'] : $activity->is_tentative,
            'is_notified' => isset($data['is_notified']) ? (bool) $data['is_notified'] : $activity->is_notified,
            'status' => $data['status'] ?? $activity->status,
            'description' => $data['description'] ?? $activity->description,
            'remarks' => $data['remarks'] ?? $activity->remarks,
            'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : $activity->display_order,
            'updated_by' => $userId,
        ]);

        AuditLog::record(
            action: 'academic_calendar.activity_updated',
            details: [
                'activity_code' => $activity->activity_code,
                'title' => $activity->title,
                'status' => $activity->status,
            ],
            userId: $userId,
            type: 'academic_calendar',
            status: 'success',
            auditableType: AcademicCalendarActivity::class,
            auditableId: $activity->id,
            description: "Updated Academic Activity {$activity->title} ({$activity->activity_code})."
        );

        return $activity;
    }

    /**
     * Delete an activity.
     */
    public static function deleteActivity(AcademicCalendarActivity $activity, ?User $user = null): void
    {
        $userId = $user?->id ?? auth()->id();

        $activity->update(['deleted_by' => $userId]);
        $activity->delete();

        AuditLog::record(
            action: 'academic_calendar.activity_deleted',
            details: ['activity_code' => $activity->activity_code, 'title' => $activity->title],
            userId: $userId,
            type: 'academic_calendar',
            status: 'success',
            auditableType: AcademicCalendarActivity::class,
            auditableId: $activity->id,
            description: "Deleted Academic Activity {$activity->title} ({$activity->activity_code})."
        );
    }

    /**
     * Delete an academic calendar along with its versions and activities.
     */
    public static function deleteCalendar(AcademicCalendar $calendar, ?User $user = null): void
    {
        $userId = $user?->id ?? auth()->id();

        DB::transaction(function () use ($calendar, $userId) {
            $calendar->activities()->update(['deleted_by' => $userId]);
            $calendar->activities()->delete();

            $calendar->versions()->update(['deleted_by' => $userId]);
            $calendar->versions()->delete();

            $calendar->update(['deleted_by' => $userId]);
            $calendar->delete();

            AuditLog::record(
                action: 'academic_calendar.deleted',
                details: ['calendar_code' => $calendar->calendar_code, 'academic_year' => $calendar->academic_year],
                userId: $userId,
                type: 'academic_calendar',
                status: 'success',
                auditableType: AcademicCalendar::class,
                auditableId: $calendar->id,
                description: "Deleted Academic Calendar {$calendar->calendar_code} for session {$calendar->academic_year}."
            );
        });
    }

    /**
     * Delete a specific version file.
     */
    public static function deleteVersion(AcademicCalendarVersion $version, ?User $user = null): void
    {
        $userId = $user?->id ?? auth()->id();

        DB::transaction(function () use ($version, $userId) {
            $calendar = $version->academicCalendar;
            $wasCurrent = $version->is_current;

            $version->update(['deleted_by' => $userId]);
            $version->delete();

            // If deleting current version, promote previous highest revision
            if ($wasCurrent && $calendar) {
                $latest = $calendar->versions()->where('id', '!=', $version->id)->orderBy('revision_number', 'desc')->first();
                if ($latest) {
                    $latest->update(['is_current' => true]);
                    $calendar->update(['current_version_id' => $latest->id]);
                } else {
                    $calendar->update(['current_version_id' => null]);
                }
            }

            AuditLog::record(
                action: 'academic_calendar.version_deleted',
                details: ['version_code' => $version->version_code, 'revision_number' => $version->revision_number],
                userId: $userId,
                type: 'academic_calendar',
                status: 'success',
                auditableType: AcademicCalendarVersion::class,
                auditableId: $version->id,
                description: "Deleted Academic Calendar Version {$version->version_code}."
            );
        });
    }

    /**
     * Bulk import monthly activities from spreadsheet (Excel / CSV).
     *
     * Expected columns in row 1:
     * Title, Category, Start Date (YYYY-MM-DD), End Date (optional), Target Audience, Department, Venue/Mode, Venue Details, Status, Description, Is Tentative
     *
     * @return array{imported: int, errors: array<string>}
     */
    public static function importActivities(AcademicCalendar $calendar, string $filePath, ?User $user = null): array
    {
        $userId = $user?->id ?? auth()->id();
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            return ['imported' => 0, 'errors' => ['File contains no activity data rows.']];
        }

        $imported = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach (array_slice($rows, 1) as $index => $row) {
                $rowNumber = $index + 2;
                $title = trim((string) ($row[0] ?? ''));

                if (empty($title)) {
                    continue; // Skip empty row
                }

                $rawCategory = strtolower(trim((string) ($row[1] ?? 'academic')));
                $allowedCategories = ['academic', 'examination', 'admission', 'holiday', 'co_curricular', 'administrative', 'sports_cultural', 'other'];
                $category = in_array($rawCategory, $allowedCategories, true) ? $rawCategory : 'academic';

                // Parse Start Date
                $rawStart = $row[2] ?? null;
                $startDate = null;

                if (is_numeric($rawStart)) {
                    $startDate = Carbon::instance(ExcelDate::excelToDateTimeObject((float) $rawStart));
                } elseif (! empty($rawStart)) {
                    try {
                        $startDate = Carbon::parse($rawStart);
                    } catch (Throwable) {
                        $errors[] = "Row {$rowNumber}: Invalid start date '{$rawStart}'. Expected YYYY-MM-DD.";

                        continue;
                    }
                } else {
                    $errors[] = "Row {$rowNumber}: Start date is required for activity '{$title}'.";

                    continue;
                }

                // Parse End Date
                $rawEnd = $row[3] ?? null;
                $endDate = null;
                if (is_numeric($rawEnd)) {
                    $endDate = Carbon::instance(ExcelDate::excelToDateTimeObject((float) $rawEnd));
                } elseif (! empty($rawEnd)) {
                    try {
                        $endDate = Carbon::parse($rawEnd);
                    } catch (Throwable) {
                        $endDate = null;
                    }
                }

                $targetAudience = trim((string) ($row[4] ?? 'all')) ?: 'all';
                $department = trim((string) ($row[5] ?? ''));
                $venueMode = strtolower(trim((string) ($row[6] ?? 'offline')));
                if (! in_array($venueMode, ['offline', 'online', 'hybrid'], true)) {
                    $venueMode = 'offline';
                }

                $venueDetails = trim((string) ($row[7] ?? ''));
                $status = strtolower(trim((string) ($row[8] ?? 'planned')));
                if (! in_array($status, ['planned', 'ongoing', 'completed', 'postponed', 'cancelled'], true)) {
                    $status = 'planned';
                }

                $description = trim((string) ($row[9] ?? ''));
                $isTentative = in_array(strtolower(trim((string) ($row[10] ?? ''))), ['yes', 'true', '1'], true);

                AcademicCalendarActivity::create([
                    'academic_calendar_id' => $calendar->id,
                    'academic_calendar_version_id' => $calendar->current_version_id,
                    'activity_code' => AcademicCalendarActivity::generateCode((int) $startDate->format('Y')),
                    'title' => $title,
                    'category' => $category,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate?->toDateString(),
                    'month' => (int) $startDate->format('n'),
                    'year' => (int) $startDate->format('Y'),
                    'target_audience' => $targetAudience,
                    'department' => $department ?: null,
                    'venue_mode' => $venueMode,
                    'venue_or_link' => $venueDetails ?: null,
                    'is_tentative' => $isTentative,
                    'status' => $status,
                    'description' => $description ?: null,
                    'display_order' => $imported + 1,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $imported++;
            }

            DB::commit();

            AuditLog::record(
                action: 'academic_calendar.imported',
                details: ['calendar_code' => $calendar->calendar_code, 'imported_count' => $imported],
                userId: $userId,
                type: 'academic_calendar',
                status: 'success',
                auditableType: AcademicCalendar::class,
                auditableId: $calendar->id,
                description: "Bulk imported {$imported} activities into Academic Calendar {$calendar->calendar_code}."
            );

            return ['imported' => $imported, 'errors' => $errors];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['imported' => 0, 'errors' => ['Import failed: '.$e->getMessage()]];
        }
    }

    /**
     * Export activities to CSV, Excel, or PDF.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function exportActivities(AcademicCalendar $calendar, array $filters = [], string $format = 'csv'): BinaryFileResponse|StreamedResponse|Response
    {
        $query = $calendar->activities()->orderBy('start_date', 'asc')->orderBy('display_order', 'asc');

        if (! empty($filters['month'])) {
            $query->where('month', (int) $filters['month']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $activities = $query->get();

        $headings = [
            'Code',
            'Activity Title',
            'Category',
            'Month',
            'Start Date',
            'End Date',
            'Target Audience',
            'Department',
            'Delivery Mode',
            'Venue / Link',
            'Status',
            'Tentative',
            'Description',
        ];

        $rows = $activities->map(function (AcademicCalendarActivity $act) {
            return [
                $act->activity_code,
                $act->title,
                $act->getCategoryLabel(),
                $act->month_name.' '.$act->year,
                $act->start_date->format('Y-m-d'),
                $act->end_date ? $act->end_date->format('Y-m-d') : '',
                ucfirst($act->target_audience),
                $act->department ?? '-',
                ucfirst($act->venue_mode),
                $act->venue_or_link ?? '-',
                ucfirst($act->status),
                $act->is_tentative ? 'Yes' : 'No',
                $act->description ?? '',
            ];
        })->all();

        $filename = 'academic-calendar-'.Str::slug($calendar->academic_year).'-activities';

        if (strtolower($format) === 'pdf') {
            $pdf = Pdf::loadView('pdf.academic-calendar', [
                'calendar' => $calendar->load(['currentVersion', 'versions']),
                'activities' => $activities,
                'title' => 'Academic Calendar & Institutional Activity Schedule - '.$calendar->academic_year,
                'generatedAt' => now(),
            ])->setPaper('a4', 'landscape');

            return response()->streamDownload(
                function () use ($pdf) {
                    echo $pdf->output();
                },
                "{$filename}.pdf",
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
                ]
            );
        }

        return TableExporter::downloadArray(
            rows: $rows,
            format: $format,
            filename: $filename,
            headings: $headings,
            title: "Academic Calendar Activities ({$calendar->academic_year})"
        );
    }

    /**
     * Download version document securely and track audit log.
     */
    public static function downloadVersion(AcademicCalendarVersion $version, ?User $user = null): BinaryFileResponse|StreamedResponse|Response
    {
        $userId = $user?->id ?? auth()->id();

        if (empty($version->file_path) || ! Storage::disk('public')->exists($version->file_path)) {
            abort(404, 'The requested calendar document file could not be located in storage.');
        }

        $version->incrementDownloads();

        AuditLog::record(
            action: 'academic_calendar.version_downloaded',
            details: [
                'version_code' => $version->version_code,
                'file_name' => $version->file_name,
                'download_count' => $version->download_count,
            ],
            userId: $userId,
            type: 'academic_calendar',
            status: 'success',
            auditableType: AcademicCalendarVersion::class,
            auditableId: $version->id,
            description: "Downloaded Academic Calendar Version {$version->version_code} ({$version->file_name})."
        );

        $fullPath = Storage::disk('public')->path($version->file_path);

        return response()->download($fullPath, $version->file_name, [
            'Content-Type' => $version->file_mime ?? 'application/octet-stream',
        ]);
    }

    /**
     * Real-time dashboard KPI metrics for academic calendars.
     *
     * @return array<string, mixed>
     */
    public static function getCalendarStatistics(?AcademicCalendar $calendar = null): array
    {
        $currentCalendar = $calendar ?? AcademicCalendar::where('is_current', true)->first()
            ?? AcademicCalendar::orderBy('start_date', 'desc')->first();

        $currentMonth = (int) now()->format('n');
        $currentYear = (int) now()->format('Y');

        $totalCalendars = AcademicCalendar::count();
        $totalActivities = $currentCalendar ? $currentCalendar->activities()->count() : 0;
        $totalVersions = AcademicCalendarVersion::count();
        $upcomingThisMonth = $currentCalendar
            ? $currentCalendar->activities()->where('month', $currentMonth)->where('year', $currentYear)->count()
            : 0;
        $completedActivities = $currentCalendar
            ? $currentCalendar->activities()->where('status', 'completed')->count()
            : 0;
        $totalDownloads = AcademicCalendarVersion::sum('download_count');

        return [
            'current_calendar' => $currentCalendar,
            'total_calendars' => $totalCalendars,
            'total_activities' => $totalActivities,
            'total_versions' => $totalVersions,
            'upcoming_this_month' => $upcomingThisMonth,
            'completed_activities' => $completedActivities,
            'total_downloads' => $totalDownloads,
            'current_version' => $currentCalendar?->currentVersion,
        ];
    }
}
