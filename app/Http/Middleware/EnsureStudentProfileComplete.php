<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentProfileComplete
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // 1. Guest users are handled by standard auth middleware
        if (! $user) {
            return $next($request);
        }

        // 2. Administrators, Super Administrators, and Impersonators are ALWAYS exempt
        if (
            $user->hasRole('Super Administrator') ||
            $user->hasRole('Administrator') ||
            $user->can('access-admin') ||
            $user->can('users.impersonate') ||
            session()->has('impersonator_id') ||
            session()->has('impersonated_by')
        ) {
            return $next($request);
        }

        // 3. Disciplinary Sanction Enforcement (Suspension / Lifetime Ban)
        // Applies strictly to students under active sanctions regardless of system settings
        if ($user->hasRole('Student') && ($user->isBanned() || $user->isSuspended())) {
            $isPermittedDisciplinaryRoute = $request->routeIs(
                'dashboard',
                'dashboard.redirect',
                'student.dashboard',
                'profile.show',
                'admin.profile.show',
                'tickets.*',
                'logout',
                'verify-otp'
            ) || $request->is(
                '*/dashboard',
                '*/student/dashboard',
                '*/profile',
                '*/tickets*',
                'livewire/*',
                'logout',
                'verify-otp*'
            );

            if (! $isPermittedDisciplinaryRoute) {
                $team = $request->route('current_team');
                $teamSlug = is_string($team) ? $team : ($team?->slug ?? $user->currentTeam?->slug ?? 'default');

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => __('Your student portal access is restricted due to an active institutional disciplinary sanction.'),
                    ], 403);
                }

                return redirect()->route('student.dashboard', ['current_team' => $teamSlug])
                    ->with('disciplinary_warning', __('Your student portal access is currently restricted under an active institutional disciplinary order.'))
                    ->with('disciplinary_sanction_warning', __('Your student portal access is currently restricted under an active institutional disciplinary order. Please review the official notice below.'));
            }
        }

        // 4. Setting toggle check (default: true = allow access even if incomplete)
        $allowIncomplete = (bool) Setting::get(
            'system.allow_incomplete_student_dashboard',
            Setting::get('students.allow_incomplete_dashboard', true)
        );

        if ($allowIncomplete) {
            return $next($request);
        }

        // 5. Only restrict students for incomplete profiles
        if (! $user->hasRole('Student')) {
            return $next($request);
        }

        // 5. Exclude routes required for profile completion, payments, auth, and livewire reactivity
        if (
            $request->routeIs(
                'dashboard',
                'dashboard.redirect',
                'student.dashboard',
                'student.academics.*',
                'student.enrollments.*',
                'student.payments.*',
                'student.library-deposit.*',
                'profile.show',
                'admin.profile.show',
                'logout',
                'verify-otp'
            ) ||
            $request->is(
                '*/dashboard',
                '*/student/dashboard',
                '*/student/academics*',
                '*/student/enrollments*',
                '*/student/payments*',
                '*/student/library-deposit*',
                '*/profile',
                'livewire/*',
                'logout',
                'verify-otp*'
            )
        ) {
            return $next($request);
        }

        // 6. Check student profile completeness across the 7 mandatory sections
        $completeness = $user->getProfileCompleteness();

        if (! $completeness['is_complete']) {
            $team = $request->route('current_team');
            $teamSlug = is_string($team) ? $team : ($team?->slug ?? $user->currentTeam?->slug ?? 'default');

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('Your student profile is incomplete. Please complete all 7 sections first.'),
                    'redirect' => route('dashboard', ['current_team' => $teamSlug]),
                    'completeness' => $completeness,
                ], 403);
            }

            return redirect()->route('dashboard', ['current_team' => $teamSlug])
                ->with('profile_incomplete_warning', __(
                    'Profile Incomplete: Access to other portal routes is restricted until your profile information is complete (:completed/:total sections complete). Please complete the missing details on your dashboard.',
                    ['completed' => $completeness['completed_count'], 'total' => $completeness['total_count']]
                ));
        }

        return $next($request);
    }
}
