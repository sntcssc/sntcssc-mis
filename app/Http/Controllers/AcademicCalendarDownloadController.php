<?php

namespace App\Http\Controllers;

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarVersion;
use App\Services\AcademicCalendarService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AcademicCalendarDownloadController extends Controller
{
    /**
     * Download a specific version file of an academic calendar.
     */
    public function downloadVersion(Request $request, AcademicCalendarVersion $version): Response
    {
        $user = $request->user();

        if (! $user || (! $user->can('academic_calendar.download') && ! $user->can('academic_calendar.view') && ! $user->isStudent())) {
            abort(403, 'Unauthorized to download academic calendar documents.');
        }

        return AcademicCalendarService::downloadVersion($version, $user);
    }

    /**
     * Download current active version of an academic calendar.
     */
    public function downloadLatest(Request $request, AcademicCalendar $calendar): Response
    {
        $user = $request->user();

        if (! $user || (! $user->can('academic_calendar.download') && ! $user->can('academic_calendar.view') && ! $user->isStudent())) {
            abort(403, 'Unauthorized to download academic calendar documents.');
        }

        $currentVersion = $calendar->currentVersion
            ?? $calendar->versions()->orderBy('revision_number', 'desc')->first();

        if (! $currentVersion) {
            abort(404, 'No document version uploaded for this academic calendar session yet.');
        }

        return AcademicCalendarService::downloadVersion($currentVersion, $user);
    }

    /**
     * Export complete calendar schedule as PDF booklet.
     */
    public function exportPdf(Request $request, AcademicCalendar $calendar): Response
    {
        $user = $request->user();

        if (! $user || (! $user->can('academic_calendar.export') && ! $user->can('academic_calendar.view') && ! $user->isStudent())) {
            abort(403, 'Unauthorized to export academic calendar.');
        }

        $filters = [
            'month' => $request->query('month'),
            'category' => $request->query('category'),
            'status' => $request->query('status'),
            'search' => $request->query('q'),
        ];

        return AcademicCalendarService::exportActivities($calendar, $filters, 'pdf');
    }

    /**
     * Download CSV template for bulk importing activities.
     */
    public function sampleImportTemplate(): Response
    {
        $headers = [
            'Title',
            'Category (academic|examination|admission|holiday|co_curricular|administrative|sports_cultural|other)',
            'Start Date (YYYY-MM-DD)',
            'End Date (YYYY-MM-DD)',
            'Target Audience (all|students|faculty|staff|aspirants)',
            'Department',
            'Venue Mode (offline|online|hybrid)',
            'Venue Details or Online Link',
            'Status (planned|ongoing|completed|postponed|cancelled)',
            'Description',
            'Is Tentative (yes|no)',
        ];

        $sampleRow1 = [
            'UPSC Prelims Orientation Session',
            'academic',
            '2026-04-10',
            '2026-04-10',
            'students',
            'Academics',
            'offline',
            'Auditorium Hall 1',
            'planned',
            'Orientation lecture on prelims syllabus roadmap and study material distribution.',
            'no',
        ];

        $sampleRow2 = [
            'May Day Institutional Holiday',
            'holiday',
            '2026-05-01',
            '',
            'all',
            'Administration',
            'offline',
            'Campus Closed',
            'planned',
            'International Workers Day observed holiday.',
            'no',
        ];

        $callback = function () use ($headers, $sampleRow1, $sampleRow2) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fputcsv($handle, $sampleRow1);
            fputcsv($handle, $sampleRow2);
            fclose($handle);
        };

        return response()->streamDownload($callback, 'academic_calendar_activities_template.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="academic_calendar_activities_template.csv"',
        ]);
    }
}
