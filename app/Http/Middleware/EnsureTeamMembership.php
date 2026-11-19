<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $team] = [$request->user(), $this->team($request)];

        abort_if(! $user || ! $team || (! $user->belongsToTeam($team) && ! $user->hasRole('Super Administrator')), 403);

        $this->ensureTeamMemberHasRequiredRole($user, $team, $minimumRole);

        if ($request->route('current_team') && ! $user->isCurrentTeam($team)) {
            $user->switchTeam($team);
        }

        $routeParam = $request->route('current_team');
        if (($routeParam === 'super-admins-team' || is_numeric($routeParam)) && $team && $team->slug !== (string) $routeParam) {
            $parameters = $request->route()->parameters();
            unset($parameters['view'], $parameters['status']);
            $parameters['current_team'] = $team->slug;
            $routeName = $request->route()->getName();
            if ($routeName) {
                return redirect()->route($routeName, $parameters);
            }
        }

        return $next($request);
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensureTeamMemberHasRequiredRole(User $user, Team $team, ?string $minimumRole): void
    {
        if ($minimumRole === null || $user->hasRole('Super Administrator')) {
            return;
        }

        $role = $user->teamRole($team);

        $requiredRole = TeamRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    /**
     * Get the team associated with the request.
     */
    protected function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if ($team instanceof Team) {
            return $team;
        }

        if (is_string($team) || is_numeric($team)) {
            return Team::where('slug', (string) $team)
                ->when($team === 'super-admins-team', fn ($q) => $q->orWhere('slug', 'sntcssc'))
                ->when(is_numeric($team), fn ($q) => $q->orWhere('id', (int) $team))
                ->first();
        }

        return null;
    }
}
