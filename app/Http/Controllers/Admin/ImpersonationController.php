<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function __construct(
        protected ImpersonationService $impersonationService
    ) {}

    /**
     * Start an impersonation session for the target user.
     */
    public function impersonate(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $result = $this->impersonationService->impersonate($actor, $user);

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        $team = $user->currentTeam ?? $user->personalTeam();
        $targetUrl = $team
            ? route('dashboard', ['current_team' => $team->slug])
            : route('home');

        return redirect()->to($targetUrl)->with('status', $result['message']);
    }

    /**
     * Terminate the active impersonation session and return to administrator dashboard.
     */
    public function leave(Request $request): RedirectResponse
    {
        $result = $this->impersonationService->leave();

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        /** @var User|null $admin */
        $admin = $result['user'];
        $team = $admin?->currentTeam ?? $admin?->personalTeam();
        $targetUrl = $team
            ? route('admin.users.index', ['current_team' => $team->slug])
            : route('home');

        return redirect()->to($targetUrl)->with('status', $result['message']);
    }
}
