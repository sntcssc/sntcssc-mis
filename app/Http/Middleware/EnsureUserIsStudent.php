<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStudent
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Active students or impersonated students have access
        if ($user->isStudent() || session()->has('impersonator_id') || session()->has('impersonated_by')) {
            return $next($request);
        }

        $team = $request->route('current_team');
        $teamSlug = is_string($team) ? $team : ($team?->slug ?? $user->currentTeam?->slug ?? 'default');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('The student portal is only accessible to enrolled students.'),
            ], 403);
        }

        // If administrative user navigated here, intelligently redirect to relevant admin desk
        if ($user->can('payments.view') && ($request->routeIs('student.payments.*') || $request->is('*/student/payments*') || $request->routeIs('student.library-deposit.*') || $request->is('*/student/library-deposit*'))) {
            return redirect()->route('admin.payments.fees', ['current_team' => $teamSlug])
                ->with('info', __('The student payment portal is reserved for students. Redirected to Fee Management.'));
        }

        if ($user->can('courses.manage') && ($request->routeIs('student.academics.*') || $request->is('*/student/academics*') || $request->is('*/student/enrollments*'))) {
            return redirect()->route('admin.courses.index', ['current_team' => $teamSlug])
                ->with('info', __('The student academics portal is reserved for students. Redirected to Courses.'));
        }

        if ($user->can('study_materials.view') && ($request->routeIs('student.study-materials.*') || $request->is('*/student/study-materials*'))) {
            return redirect()->route('admin.study-materials.index', ['current_team' => $teamSlug])
                ->with('info', __('The student study materials portal is reserved for students. Redirected to Study Materials Management.'));
        }

        if ($user->can('hostel.view') && ($request->routeIs('student.hostel.*') || $request->is('*/student/hostel*'))) {
            return redirect()->route('admin.hostel.dashboard', ['current_team' => $teamSlug])
                ->with('info', __('The student hostel portal is reserved for students. Redirected to Hostel Management.'));
        }

        return redirect()->route('dashboard', ['current_team' => $teamSlug])
            ->with('info', __('The student portal is only accessible to enrolled students.'));
    }
}
