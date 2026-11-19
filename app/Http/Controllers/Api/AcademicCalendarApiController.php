<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\AcademicCalendarVersion;
use App\Services\AcademicCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AcademicCalendarApiController extends Controller
{
    /**
     * Get paginated or list of academic calendars.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AcademicCalendar::query()
            ->with(['currentVersion', 'creator'])
            ->withCount(['activities', 'versions']);

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->input('academic_year'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('is_current')) {
            $query->where('is_current', filter_var($request->input('is_current'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        $query->orderBy('is_current', 'desc')->orderBy('start_date', 'desc');

        $perPage = min((int) ($request->input('per_page', 15)), 100);
        $calendars = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $calendars->items(),
            'meta' => [
                'current_page' => $calendars->currentPage(),
                'last_page' => $calendars->lastPage(),
                'per_page' => $calendars->perPage(),
                'total' => $calendars->total(),
            ],
        ]);
    }

    /**
     * Get the active / current academic calendar for the institute.
     */
    public function current(Request $request): JsonResponse
    {
        $calendar = AcademicCalendar::where('is_current', true)
            ->with([
                'currentVersion',
                'versions' => fn ($q) => $q->orderBy('revision_number', 'desc'),
                'activities' => fn ($q) => $q->orderBy('start_date', 'asc')->orderBy('display_order', 'asc'),
            ])
            ->first();

        if (! $calendar) {
            $calendar = AcademicCalendar::orderBy('start_date', 'desc')
                ->with(['currentVersion', 'versions', 'activities'])
                ->first();
        }

        if (! $calendar) {
            return response()->json([
                'success' => false,
                'message' => 'No academic calendar found.',
            ], 404);
        }

        $stats = AcademicCalendarService::getCalendarStatistics($calendar);

        return response()->json([
            'success' => true,
            'calendar' => $calendar,
            'statistics' => $stats,
        ]);
    }

    /**
     * Get specific academic calendar details.
     */
    public function show(int $id): JsonResponse
    {
        $calendar = AcademicCalendar::with([
            'currentVersion',
            'versions' => fn ($q) => $q->orderBy('revision_number', 'desc'),
            'activities' => fn ($q) => $q->orderBy('start_date', 'asc')->orderBy('display_order', 'asc'),
        ])->find($id);

        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'calendar' => $calendar,
            'versions_count' => $calendar->versions->count(),
            'activities_count' => $calendar->activities->count(),
        ]);
    }

    /**
     * Create a new Academic Calendar.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:255'],
            'theme_or_motto' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_working_days' => ['nullable', 'integer', 'min:0'],
            'total_instructional_days' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'is_current' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'mimes:pdf,docx,xlsx,png,jpg', 'max:25600'],
            'initial_version_code' => ['nullable', 'string', 'max:20'],
            'initial_version_title' => ['nullable', 'string', 'max:255'],
            'approved_by' => ['nullable', 'string', 'max:150'],
            'approval_date' => ['nullable', 'date'],
            'effective_from' => ['nullable', 'date'],
        ]);

        try {
            $file = $request->file('file');
            $calendar = AcademicCalendarService::createCalendar($validated, $file, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Academic calendar created successfully.',
                'calendar' => $calendar,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create academic calendar: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an academic calendar.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $calendar = AcademicCalendar::find($id);
        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        $validated = $request->validate([
            'academic_year' => ['sometimes', 'required', 'string', 'max:30'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'theme_or_motto' => ['nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'total_working_days' => ['nullable', 'integer', 'min:0'],
            'total_instructional_days' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'is_current' => ['nullable', 'boolean'],
        ]);

        try {
            $calendar = AcademicCalendarService::updateCalendar($calendar, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Academic calendar updated successfully.',
                'calendar' => $calendar,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update academic calendar: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set calendar as currently active session.
     */
    public function setCurrent(Request $request, int $id): JsonResponse
    {
        $calendar = AcademicCalendar::find($id);
        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        AcademicCalendarService::setCurrentCalendar($calendar, $request->user());

        return response()->json([
            'success' => true,
            'message' => "Academic Calendar for session {$calendar->academic_year} set as currently active.",
            'calendar' => $calendar->fresh(),
        ]);
    }

    /**
     * Soft delete an academic calendar.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $calendar = AcademicCalendar::find($id);
        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        AcademicCalendarService::deleteCalendar($calendar, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Academic calendar deleted successfully.',
        ]);
    }

    /**
     * Get version control history for an academic calendar.
     */
    public function versions(int $id): JsonResponse
    {
        $calendar = AcademicCalendar::find($id);
        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        $versions = $calendar->versions()->with('uploader:id,name,email')->get();

        return response()->json([
            'success' => true,
            'academic_year' => $calendar->academic_year,
            'calendar_code' => $calendar->calendar_code,
            'versions' => $versions,
        ]);
    }

    /**
     * Upload a new revised calendar version file.
     */
    public function uploadRevision(Request $request, int $id): JsonResponse
    {
        $calendar = AcademicCalendar::find($id);
        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,xlsx,png,jpg', 'max:25600'],
            'version_code' => ['nullable', 'string', 'max:20'],
            'version_title' => ['nullable', 'string', 'max:255'],
            'change_summary' => ['nullable', 'string'],
            'approved_by' => ['nullable', 'string', 'max:150'],
            'approval_date' => ['nullable', 'date'],
            'effective_from' => ['nullable', 'date'],
        ]);

        try {
            $version = AcademicCalendarService::uploadRevision(
                $calendar,
                $request->file('file'),
                $validated,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'New revised calendar version uploaded successfully.',
                'version' => $version,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload calendar revision: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a specific document version file.
     */
    public function downloadVersion(Request $request, int $id, int $versionId): Response
    {
        $version = AcademicCalendarVersion::where('academic_calendar_id', $id)->find($versionId);

        if (! $version) {
            return response()->json(['success' => false, 'message' => 'Calendar version document not found.'], 404);
        }

        return AcademicCalendarService::downloadVersion($version, $request->user());
    }

    /**
     * Get month-wise activities of a calendar with filtering.
     */
    public function activities(Request $request, int $id): JsonResponse
    {
        $calendar = AcademicCalendar::find($id);
        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        $query = $calendar->activities()->with('version');

        if ($request->filled('month')) {
            $query->where('month', (int) $request->input('month'));
        }

        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('target_audience')) {
            $query->where('target_audience', $request->input('target_audience'));
        }

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        $activities = $query->orderBy('start_date', 'asc')->orderBy('display_order', 'asc')->get();

        return response()->json([
            'success' => true,
            'academic_year' => $calendar->academic_year,
            'total' => $activities->count(),
            'activities' => $activities,
        ]);
    }

    /**
     * Create an activity under an academic calendar.
     */
    public function storeActivity(Request $request, int $id): JsonResponse
    {
        $calendar = AcademicCalendar::find($id);
        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'Academic calendar not found.'], 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:academic,examination,admission,holiday,co_curricular,administrative,sports_cultural,other'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'target_audience' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:150'],
            'venue_mode' => ['nullable', 'in:offline,online,hybrid'],
            'venue_or_link' => ['nullable', 'string', 'max:255'],
            'is_tentative' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:planned,ongoing,completed,postponed,cancelled'],
            'description' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer'],
        ]);

        try {
            $activity = AcademicCalendarService::createActivity($calendar, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Activity created successfully.',
                'activity' => $activity,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create activity: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an activity.
     */
    public function updateActivity(Request $request, int $id, int $activityId): JsonResponse
    {
        $activity = AcademicCalendarActivity::where('academic_calendar_id', $id)->find($activityId);
        if (! $activity) {
            return response()->json(['success' => false, 'message' => 'Activity not found.'], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'in:academic,examination,admission,holiday,co_curricular,administrative,sports_cultural,other'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'target_audience' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:150'],
            'venue_mode' => ['nullable', 'in:offline,online,hybrid'],
            'venue_or_link' => ['nullable', 'string', 'max:255'],
            'is_tentative' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:planned,ongoing,completed,postponed,cancelled'],
            'description' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer'],
        ]);

        try {
            $activity = AcademicCalendarService::updateActivity($activity, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Activity updated successfully.',
                'activity' => $activity,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update activity: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an activity.
     */
    public function destroyActivity(Request $request, int $id, int $activityId): JsonResponse
    {
        $activity = AcademicCalendarActivity::where('academic_calendar_id', $id)->find($activityId);
        if (! $activity) {
            return response()->json(['success' => false, 'message' => 'Activity not found.'], 404);
        }

        AcademicCalendarService::deleteActivity($activity, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Activity deleted successfully.',
        ]);
    }

    /**
     * Student portal endpoint for current academic calendar and month-wise activities.
     */
    public function studentCalendar(Request $request): JsonResponse
    {
        $calendar = AcademicCalendar::where('is_current', true)
            ->with(['currentVersion', 'versions' => fn ($q) => $q->orderBy('revision_number', 'desc')])
            ->first();

        if (! $calendar) {
            $calendar = AcademicCalendar::orderBy('start_date', 'desc')
                ->with(['currentVersion', 'versions'])
                ->first();
        }

        if (! $calendar) {
            return response()->json(['success' => false, 'message' => 'No active calendar found.'], 404);
        }

        $query = $calendar->activities()->orderBy('start_date', 'asc');

        if ($request->filled('month')) {
            $query->where('month', (int) $request->input('month'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $activities = $query->get();

        return response()->json([
            'success' => true,
            'calendar' => [
                'id' => $calendar->id,
                'code' => $calendar->calendar_code,
                'academic_year' => $calendar->academic_year,
                'title' => $calendar->title,
                'theme' => $calendar->theme_or_motto,
                'period' => $calendar->formatted_period,
                'current_version' => $calendar->currentVersion ? [
                    'version_code' => $calendar->currentVersion->version_code,
                    'version_title' => $calendar->currentVersion->version_title,
                    'file_name' => $calendar->currentVersion->file_name,
                    'file_size' => $calendar->currentVersion->human_file_size,
                    'approval_date' => $calendar->currentVersion->approval_date?->format('d M Y'),
                    'download_url' => route('academic-calendar.download.version', $calendar->currentVersion->id),
                ] : null,
                'versions' => $calendar->versions->map(fn ($v) => [
                    'id' => $v->id,
                    'version_code' => $v->version_code,
                    'version_title' => $v->version_title,
                    'file_name' => $v->file_name,
                    'is_current' => $v->is_current,
                    'download_url' => route('academic-calendar.download.version', $v->id),
                ]),
            ],
            'activities' => $activities,
        ]);
    }
}
