<?php

namespace App\Http\Controllers;

use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Services\Test\TestImportExportService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class StudentTestDownloadController extends Controller
{
    public function __construct(
        protected TestImportExportService $importExportService
    ) {}

    /**
     * Download test question paper.
     */
    public function downloadQuestionPaper(StudentTest|string|int $test): Response
    {
        $user = auth()->user();

        if (! ($test instanceof StudentTest)) {
            $test = StudentTest::where('uuid', $test)->orWhere('id', $test)->orWhere('code', $test)->firstOrFail();
        }

        if (! $test->canDownloadSolutions($user)) {
            $end = $test->scheduled_end_datetime->format('d M Y, h:i A');
            abort(403, "The Question Paper will be accessible for download once the examination concludes at {$end}.");
        }

        if (empty($test->question_paper_path) || ! Storage::disk('public')->exists($test->question_paper_path)) {
            abort(404, 'The question paper file has not been uploaded yet or is unavailable.');
        }

        $fullPath = Storage::disk('public')->path($test->question_paper_path);
        $filename = $test->question_paper_name ?: basename($fullPath);

        return response()->download($fullPath, $filename);
    }

    /**
     * Download test model answers and explanations.
     */
    public function downloadModelAnswer(StudentTest|string|int $test): Response
    {
        $user = auth()->user();

        if (! ($test instanceof StudentTest)) {
            $test = StudentTest::where('uuid', $test)->orWhere('id', $test)->orWhere('code', $test)->firstOrFail();
        }

        if (! $test->canDownloadSolutions($user)) {
            $end = $test->scheduled_end_datetime->format('d M Y, h:i A');
            abort(403, "Model Answers and Explanations will be accessible for download once the examination concludes at {$end}.");
        }

        if (empty($test->model_answer_path) || ! Storage::disk('public')->exists($test->model_answer_path)) {
            abort(404, 'The model answer file has not been uploaded yet or is unavailable.');
        }

        $fullPath = Storage::disk('public')->path($test->model_answer_path);
        $filename = $test->model_answer_name ?: basename($fullPath);

        return response()->download($fullPath, $filename);
    }

    /**
     * Download student marksheet PDF.
     */
    public function downloadMarksheet(StudentTestResult|string|int $result): Response
    {
        $user = auth()->user();

        if (! ($result instanceof StudentTestResult)) {
            $result = StudentTestResult::where('uuid', $result)->orWhere('id', $result)->firstOrFail();
        }

        // Ensure user is authorized: admin, faculty or the candidate themselves
        $canManage = ! $user->isStudent() && ($user->hasRole(['Super Administrator', 'Administrator', 'Faculty']) || $user->can('tests.results.manage') || $user->can('tests.export'));

        if (! $canManage && $result->user_id !== $user->id) {
            abort(403, 'You are not authorized to view or download another candidate’s marksheet.');
        }

        return $this->importExportService->downloadMarksheetPdf($result);
    }
}
