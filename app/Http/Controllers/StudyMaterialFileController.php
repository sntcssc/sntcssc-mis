<?php

namespace App\Http\Controllers;

use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialFile;
use App\Services\StudyMaterial\StudyMaterialService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class StudyMaterialFileController extends Controller
{
    public function __construct(
        protected StudyMaterialService $studyMaterialService
    ) {}

    /**
     * Download study material file(s).
     */
    public function download(Request $request, StudyMaterial $studyMaterial, ?int $fileIndex = null): BinaryFileResponse|StreamedResponse|Response
    {
        $user = $request->user();

        // Security / Authorization check
        if (! $studyMaterial->isAccessibleBy($user)) {
            abort(403, __('You do not have permission to access or download this study material.'));
        }

        if (! $studyMaterial->isPubliclyAvailable() && $user && ! $user->can('study_materials.download') && ! $user->hasRole('Super Administrator')) {
            abort(403, __('You do not have permission to download this study material.'));
        }

        // If specific file ID requested
        if ($fileIndex) {
            $file = StudyMaterialFile::where('study_material_id', $studyMaterial->id)
                ->where('id', $fileIndex)
                ->firstOrFail();

            return $this->streamSingleFile($studyMaterial, $file);
        }

        $files = $studyMaterial->files;

        if ($files->isEmpty()) {
            if ($studyMaterial->external_url) {
                $this->studyMaterialService->recordAccess($studyMaterial, StudyMaterialAccessLog::ACTION_EXTERNAL_CLICK);

                return redirect()->away($studyMaterial->external_url);
            }

            abort(404, __('No downloadable files found for this study material.'));
        }

        // If single file, download directly
        if ($files->count() === 1) {
            return $this->streamSingleFile($studyMaterial, $files->first());
        }

        // Multiple files: Stream or generate ZIP archive
        return $this->streamZipArchive($studyMaterial, $files);
    }

    /**
     * Preview single file inline (e.g. PDF or Image) in browser.
     */
    public function preview(Request $request, StudyMaterial $studyMaterial, int $fileIndex): Response|BinaryFileResponse
    {
        $user = $request->user();

        if (! $studyMaterial->isAccessibleBy($user)) {
            abort(403, __('You do not have permission to preview this study material file.'));
        }

        $file = StudyMaterialFile::where('study_material_id', $studyMaterial->id)
            ->where('id', $fileIndex)
            ->firstOrFail();

        $disk = Storage::disk($file->disk ?? 'public');
        if (! $disk->exists($file->file_path)) {
            abort(404, __('File not found on storage server.'));
        }

        $this->studyMaterialService->recordAccess($studyMaterial, StudyMaterialAccessLog::ACTION_VIEW, $file->id);

        $path = $disk->path($file->file_path);
        $mime = $file->mime_type ?: mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$file->file_name.'"',
        ]);
    }

    /**
     * Stream single file download.
     */
    protected function streamSingleFile(StudyMaterial $studyMaterial, StudyMaterialFile $file): BinaryFileResponse|StreamedResponse
    {
        $disk = Storage::disk($file->disk ?? 'public');

        if (! $disk->exists($file->file_path)) {
            abort(404, __('Requested file does not exist on storage disk.'));
        }

        $this->studyMaterialService->recordAccess($studyMaterial, StudyMaterialAccessLog::ACTION_DOWNLOAD, $file->id);

        return $disk->download($file->file_path, $file->file_name);
    }

    /**
     * Package multiple files into a ZIP archive and stream download.
     *
     * @param  Collection<int, StudyMaterialFile>  $files
     */
    protected function streamZipArchive(StudyMaterial $studyMaterial, $files): BinaryFileResponse
    {
        $zipFilename = 'study-material-'.str($studyMaterial->title)->slug().'-'.now()->format('YmdHis').'.zip';
        $tempPath = tempnam(sys_get_temp_dir(), 'sm_zip_');

        $zip = new ZipArchive;
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, __('Failed to generate ZIP archive.'));
        }

        foreach ($files as $file) {
            $disk = Storage::disk($file->disk ?? 'public');
            if ($disk->exists($file->file_path)) {
                $zip->addFile($disk->path($file->file_path), $file->file_name);
            }
        }
        $zip->close();

        $this->studyMaterialService->recordAccess($studyMaterial, StudyMaterialAccessLog::ACTION_DOWNLOAD);

        return response()->download($tempPath, $zipFilename)->deleteFileAfterSend(true);
    }
}
