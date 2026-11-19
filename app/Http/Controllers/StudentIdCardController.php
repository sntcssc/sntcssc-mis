<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StudentIdCardService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentIdCardController extends Controller
{
    /**
     * Download individual printable Student ID Card PDF.
     */
    public function download(Request $request, User $student): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $isSelf = $student->id === $user->id;
        $isStaff = $user->can('student_id_cards.download')
            || $user->can('students.view')
            || $user->hasRole('Super Administrator')
            || $user->hasRole('Administrator')
            || $user->hasRole('Admissions Officer')
            || $user->hasRole('Staff');

        // Check if student is downloading own card
        if ($isSelf) {
            if (! StudentIdCardService::isDownloadEnabledForStudents()) {
                abort(403, __('Student ID Card downloading is currently disabled by the institution administration. Please contact the administrative desk.'));
            }
        } elseif (! $isStaff) {
            abort(403, __('Unauthorized access to student ID card.'));
        }

        return StudentIdCardService::downloadCardPdf($student);
    }

    /**
     * Bulk download student ID cards for multiple selected students.
     */
    public function bulkDownload(Request $request): Response
    {
        $user = $request->user();

        if (! $user || (! $user->can('student_id_cards.download') && ! $user->can('students.view') && ! $user->hasRole('Super Administrator') && ! $user->hasRole('Administrator'))) {
            abort(403, __('Unauthorized access to bulk student ID card generation.'));
        }

        $studentIds = $request->input('ids');
        if (is_string($studentIds)) {
            $studentIds = explode(',', $studentIds);
        }

        $query = User::query()->whereNotNull('student_id');

        if (! empty($studentIds)) {
            $query->whereIn('id', $studentIds);
        } else {
            // Apply batch/course filters if provided
            if ($batchId = $request->query('batch_id')) {
                $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', $batchId));
            }
            if ($courseId = $request->query('course_id')) {
                $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $courseId));
            }
            $limit = min((int) $request->query('limit', 50), 200);
            $query->latest('id')->take($limit);
        }

        $students = $query->get();

        if ($students->isEmpty()) {
            abort(404, __('No enrolled students found for ID card export.'));
        }

        return StudentIdCardService::downloadBulkCardsPdf($students);
    }
}
