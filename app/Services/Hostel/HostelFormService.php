<?php

namespace App\Services\Hostel;

use App\Models\HostelAllocation;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HostelFormService
{
    /**
     * Generate an official personalized PDF admission and accommodation form.
     */
    public static function generatePersonalizedFormPdf(User|HostelAllocation $target, ?HostelAllocation $allocation = null): \Barryvdh\DomPDF\PDF
    {
        if ($target instanceof HostelAllocation) {
            $allocation = $target;
            $user = $target->user;
        } else {
            $user = $target;
            $allocation = $allocation ?? $user->activeHostelAllocation;
        }

        if ($allocation) {
            $allocation->loadMissing(['room.building.caretakers', 'room.floor', 'bed', 'user.studentProfile', 'user.activeEnrollment.course', 'user.activeEnrollment.batch', 'user.activeEnrollment.section']);
            $building = $allocation->room?->building;
            $room = $allocation->room;
            $bed = $allocation->bed;
        } else {
            $building = null;
            $room = null;
            $bed = null;
        }

        $user->loadMissing(['studentProfile', 'activeEnrollment.course', 'activeEnrollment.batch', 'activeEnrollment.section']);

        $enrollment = $user->activeEnrollment;
        $formRef = $allocation?->allocation_no ? 'HST-FRM-'.$allocation->allocation_no : 'HST-FRM-'.now()->format('Y').'-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT);

        $data = [
            'resident' => $user,
            'allocation' => $allocation,
            'building' => $building,
            'room' => $room,
            'bed' => $bed,
            'enrollment' => $enrollment,
            'formRef' => $formRef,
        ];

        return Pdf::loadView('pdf.hostel-admission-form', $data)->setPaper('a4', 'portrait');
    }

    /**
     * Resolve and return the downloadable form based on administrative configuration.
     * Either streams the custom uploaded file or generates a personalized PDF.
     */
    public static function downloadForm(User $user, ?HostelAllocation $allocation = null): Response|BinaryFileResponse
    {
        $mode = (string) Setting::get('hostel.form_download_mode', 'generated');
        $customPath = Setting::get('hostel.form_custom_pdf_path');

        if ($mode === 'uploaded' && ! empty($customPath) && Storage::disk('public')->exists($customPath)) {
            $fullPath = Storage::disk('public')->path($customPath);
            $fileName = 'SNTCSSC_Official_Hostel_Admission_Form.pdf';

            return response()->download($fullPath, $fileName, [
                'Content-Type' => 'application/pdf',
            ]);
        }

        $pdf = self::generatePersonalizedFormPdf($user, $allocation);
        $fileName = 'Hostel_Admission_Form_'.str_replace(' ', '_', $user->name).'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
