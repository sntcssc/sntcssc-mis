<?php

namespace App\Http\Controllers\Hostel;

use App\Http\Controllers\Controller;
use App\Models\HostelAllocation;
use App\Services\Hostel\HostelFormService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HostelFormDownloadController extends Controller
{
    /**
     * Download the hostel admission & accommodation form.
     */
    public function download(Request $request): Response|BinaryFileResponse
    {
        $user = $request->user();
        $allocationId = $request->query('allocation_id');
        $allocation = null;

        if ($allocationId) {
            $allocation = HostelAllocation::find($allocationId);
            if ($allocation && $allocation->user_id !== $user->id) {
                if (! $user->can('hostel.allocations.view') && ! $user->can('hostel.allocations.manage') && ! $user->hasRole('Super Administrator')) {
                    abort(403, __('Unauthorized to access another resident\'s hostel admission form.'));
                }
            }
        }

        if (! $allocation) {
            $allocation = $user->activeHostelAllocation;
        }

        if (! $allocation || ! $allocation->isActive()) {
            abort(403, __('Only active accommodated students can download the hostel admission form.'));
        }

        $resident = ($allocation && $allocation->user) ? $allocation->user : $user;

        return HostelFormService::downloadForm($resident, $allocation);
    }
}
