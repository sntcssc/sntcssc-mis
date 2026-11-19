<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialFile;
use App\Services\StudyMaterial\StudyMaterialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudyMaterialShareController extends Controller
{
    public function __construct(
        protected StudyMaterialService $studyMaterialService
    ) {}

    /**
     * View shared study material via secure token.
     */
    public function viewShared(Request $request, string $token): Response
    {
        $material = StudyMaterial::where('share_token', $token)->firstOrFail();

        if ($material->status !== StudyMaterial::STATUS_PUBLISHED) {
            abort(404, __('This study material is currently not published or unavailable.'));
        }

        // Expiry check
        if ($material->isExpired()) {
            return response()->view('errors.410', [
                'message' => __('This study material share link has expired.'),
            ], 410);
        }

        // Password protection check
        if (! empty($material->share_password)) {
            $sessionKey = 'sm_share_auth_'.$material->id;
            if (! session($sessionKey)) {
                if ($request->isMethod('post') && $request->has('password')) {
                    if (Hash::check($request->input('password'), $material->share_password)) {
                        session([$sessionKey => true]);
                    } else {
                        return response()->view('pages.public.study-material-password', [
                            'material' => $material,
                            'error' => __('Invalid access password. Please try again.'),
                        ], 403);
                    }
                } else {
                    return response()->view('pages.public.study-material-password', [
                        'material' => $material,
                        'error' => null,
                    ]);
                }
            }
        }

        $this->studyMaterialService->recordAccess($material, StudyMaterialAccessLog::ACTION_VIEW);

        return response()->view('pages.public.study-material-shared', [
            'material' => $material->load(['course', 'batches', 'files']),
        ]);
    }

    /**
     * Download shared file via public link.
     */
    public function downloadShared(Request $request, string $token, ?int $fileIndex = null): BinaryFileResponse|StreamedResponse
    {
        $material = StudyMaterial::where('share_token', $token)->firstOrFail();

        if ($material->status !== StudyMaterial::STATUS_PUBLISHED) {
            abort(404, __('This study material is currently not published or unavailable.'));
        }

        if ($material->isExpired()) {
            abort(410, __('This study material link has expired.'));
        }

        if (! empty($material->share_password)) {
            $sessionKey = 'sm_share_auth_'.$material->id;
            if (! session($sessionKey)) {
                abort(403, __('Password verification required before downloading.'));
            }
        }

        $file = null;
        if ($fileIndex) {
            $file = StudyMaterialFile::where('study_material_id', $material->id)
                ->where('id', $fileIndex)
                ->firstOrFail();
        } else {
            $file = $material->files->first();
            if (! $file) {
                if ($material->external_url) {
                    return redirect()->away($material->external_url);
                }
                abort(404, __('No file found.'));
            }
        }

        $disk = Storage::disk($file->disk ?? 'public');
        if (! $disk->exists($file->file_path)) {
            abort(404, __('File not found on storage server.'));
        }

        $this->studyMaterialService->recordAccess($material, StudyMaterialAccessLog::ACTION_DOWNLOAD, $file->id);

        return $disk->download($file->file_path, $file->file_name);
    }
}
