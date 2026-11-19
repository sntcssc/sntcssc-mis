<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StudentInformationSheetService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentInformationSheetController extends Controller
{
    /**
     * Download printable official PDF Student Detailed Information Sheet(s).
     */
    public function download(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $canView = $user->can('students.view')
            || $user->hasRole('Super Administrator')
            || $user->hasRole('Administrator')
            || $user->hasRole('Admissions Officer')
            || $user->hasRole('Staff');

        $studentIds = $request->input('ids');
        if (! $studentIds && $request->has('student_id')) {
            $studentIds = [$request->input('student_id')];
        }

        if (is_string($studentIds)) {
            $studentIds = array_filter(array_map('trim', explode(',', $studentIds)));
        }

        // If a student is downloading their own sheet
        if (! $canView) {
            if (count($studentIds) === 1 && (int) reset($studentIds) === (int) $user->id) {
                // Allowed self-access
            } else {
                abort(403, __('Unauthorized access to student detailed information sheets.'));
            }
        }

        $query = User::query()->where(function ($q) {
            $q->whereHas('roles', fn ($r) => $r->where('name', 'Student'))
                ->orWhereNotNull('student_id')
                ->orWhereHas('enrollments');
        });

        if (! empty($studentIds)) {
            $query->whereIn('id', $studentIds);
        } else {
            // Apply optional filters if no specific IDs provided
            if ($batchId = $request->query('batch_id')) {
                $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', (int) $batchId));
            }
            if ($courseId = $request->query('course_id')) {
                $query->whereHas('enrollments', fn ($q) => $q->where('course_id', (int) $courseId));
            }
            if ($status = $request->query('status')) {
                $query->where('status', $status);
            }
            $limit = min((int) $request->query('limit', 50), 200);
            $query->latest('id')->take($limit);
        }

        $students = $query->get();

        if ($students->isEmpty()) {
            abort(404, __('No students found matching criteria for detailed information sheets.'));
        }

        $includeDocuments = filter_var($request->query('include_documents', false), FILTER_VALIDATE_BOOLEAN);

        return StudentInformationSheetService::downloadPdf($students, $includeDocuments);
    }
}
