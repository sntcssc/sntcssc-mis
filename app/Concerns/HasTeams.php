<?php

namespace App\Concerns;

use App\Actions\Teams\CreateTeam;
use App\Data\TeamPermissions;
use App\Data\UserTeam;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasTeams
{
    /**
     * Get all of the teams the user belongs to.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get a collection of all the teams the user belongs to.
     *
     * @return Collection<int, Team>
     */
    public function allTeams(): Collection
    {
        return $this->teams()->get();
    }

    /**
     * Assign the user to an existing team.
     */
    public function assignToTeam(Team $team, TeamRole $role = TeamRole::Member): bool
    {
        $team->members()->syncWithoutDetaching([
            $this->id => ['role' => $role->value],
        ]);

        return $this->switchTeam($team);
    }

    /**
     * Create a personal team for the user.
     */
    public function createPersonalTeam(?string $name = null): Team
    {
        return app(CreateTeam::class)->handle(
            $this,
            $name ?? ($this->name ? "{$this->name}'s Team" : 'Personal Team'),
            isPersonal: true
        );
    }

    /**
     * Ensure the user has an active current team, assigning to an existing team if none exists.
     */
    public function ensureCurrentTeam(): Team
    {
        if ($this->current_team_id && $this->relationLoaded('currentTeam') && $this->currentTeam) {
            URL::defaults([
                'current_team' => $this->currentTeam->slug,
                'team' => $this->currentTeam->slug,
            ]);

            return $this->currentTeam;
        }

        if ($this->current_team_id && ($team = $this->currentTeam()->first())) {
            $this->setRelation('currentTeam', $team);
            URL::defaults([
                'current_team' => $team->slug,
                'team' => $team->slug,
            ]);

            return $team;
        }

        $team = $this->personalTeam() ?? $this->teams()->first();

        if (! $team) {
            $existingTeam = Team::where('slug', 'sntcssc')
                ->orWhere('slug', 'super-admins-team')
                ->orWhere('is_personal', false)
                ->oldest('id')
                ->first() ?? Team::oldest('id')->first();

            if ($existingTeam) {
                $this->assignToTeam($existingTeam, TeamRole::Member);
                $team = $existingTeam;
            } else {
                $team = $this->createPersonalTeam();
            }
        } else {
            $this->switchTeam($team);
        }

        return $team;
    }

    /**
     * Get all of the teams the user owns.
     *
     * @return HasManyThrough<Team, Membership, $this>
     */
    public function ownedTeams(): HasManyThrough
    {
        return $this->hasManyThrough(
            Team::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'team_id',
        )->where('team_members.role', TeamRole::Owner->value);
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current team.
     *
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Get the user's personal team.
     */
    public function personalTeam(): ?Team
    {
        return $this->teams()
            ->wherePivot('role', TeamRole::Owner->value)
            ->where('is_personal', true)
            ->first();
    }

    /**
     * Switch to the given team.
     */
    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->update(['current_team_id' => $team->id]);
        $this->setRelation('currentTeam', $team);

        URL::defaults([
            'current_team' => $team->slug,
            'team' => $team->slug,
        ]);

        return true;
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->where('teams.id', $team->id)->exists();
    }

    /**
     * Determine if the given team is the user's current team.
     */
    public function isCurrentTeam(Team $team): bool
    {
        return $this->current_team_id === $team->id;
    }

    /**
     * Determine if the user is the owner of the given team.
     */
    public function ownsTeam(Team $team): bool
    {
        return $this->teamRole($team) === TeamRole::Owner;
    }

    /**
     * Get the user's role on the given team.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        return $this->teamMemberships()
            ->where('team_id', $team->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's teams as a collection of UserTeam objects.
     *
     * @return Collection<int, UserTeam>
     */
    public function toUserTeams(bool $includeCurrent = false): Collection
    {
        return $this->teams()
            ->get()
            ->map(fn (Team $team) => ! $includeCurrent && $this->isCurrentTeam($team) ? null : $this->toUserTeam($team))
            ->filter()
            ->values();
    }

    /**
     * Get the user's team as a UserTeam object.
     */
    public function toUserTeam(Team $team): UserTeam
    {
        $role = $this->teamRole($team);

        return new UserTeam(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            isPersonal: $team->is_personal,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentTeam($team),
        );
    }

    /**
     * Get the standard permissions for a team as a TeamPermissions object.
     */
    public function toTeamPermissions(Team $team): TeamPermissions
    {
        if (! $this->isAdmin()) {
            return new TeamPermissions(
                canUpdateTeam: false,
                canDeleteTeam: false,
                canAddMember: false,
                canUpdateMember: false,
                canRemoveMember: false,
                canCreateInvitation: false,
                canCancelInvitation: false,
            );
        }

        $role = $this->teamRole($team);

        return new TeamPermissions(
            canUpdateTeam: $role?->hasPermission(TeamPermission::UpdateTeam) ?? false,
            canDeleteTeam: $role?->hasPermission(TeamPermission::DeleteTeam) ?? false,
            canAddMember: $role?->hasPermission(TeamPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(TeamPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(TeamPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(TeamPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(TeamPermission::CancelInvitation) ?? false,
        );
    }

    public function fallbackTeam(?Team $excluding = null): ?Team
    {
        return $this->teams()
            ->when($excluding, fn ($query) => $query->where('teams.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(teams.name)')
            ->first();
    }

    /**
     * Determine if the user has the given permission on the team.
     */
    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }
}
