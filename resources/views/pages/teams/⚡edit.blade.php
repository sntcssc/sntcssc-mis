<?php

use App\Data\TeamPermissions;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Rules\TeamName;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public string $teamName = '';

    public array $teamData = [];

    public array $members = [];

    public array $invitations = [];

    public array $availableRoles = [];

    public bool $isCurrentTeam = false;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->teamName = $team->name;

        $this->populateTeamData();
    }

    public function updateTeam(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = DB::transaction(function () use ($validated) {
            $team = Team::whereKey($this->teamModel->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $validated['teamName']]);

            return $team;
        });

        $this->teamModel = $team;

        $this->populateTeamData();

        Toast::dispatch($this, 'success', __('Team updated.'));

        $this->redirectRoute('teams.edit', ['team' => $this->teamModel->fresh()->slug], navigate: true);
    }

    public function updateMember(int $userId, string $role): void
    {
        Gate::authorize('updateMember', $this->teamModel);

        $validated = Validator::make(['role' => $role], [
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ])->validate();

        $this->teamModel->memberships()
            ->where('user_id', $userId)
            ->firstOrFail()
            ->update(['role' => TeamRole::from($validated['role'])]);

        $this->populateTeamData();

        Toast::dispatch($this, 'success', __('Member role updated.'));
    }

    private function populateTeamData(): void
    {
        $user = Auth::user();

        $team = $this->teamModel->fresh();

        $this->teamData = [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'is_personal' => $team->is_personal,
        ];

        $this->members = $team->members()->get()->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar' => $member->avatar ?? null,
            'initials' => $member->initials(),
            'role' => $member->pivot->role->value,
            'role_label' => $member->pivot->role->label(),
        ])->toArray();

        $this->invitations = $team->invitations()
            ->whereNull('accepted_at')
            ->get()
            ->map(fn ($invitation) => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->toArray();

        $this->availableRoles = TeamRole::assignable();

        $this->isCurrentTeam = $user->isCurrentTeam($team);
    }

    public function render()
    {
        $teamName = $this->teamData['name'] ?? $this->teamModel->name;

        $title = $this->permissions->canUpdateTeam
            ? __('Edit :name', ['name' => $teamName])
            : __('View :name', ['name' => $teamName]);

        return $this->view()->title($title);
    }

    #[Computed]
    public function permissions(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Teams') }}</h2>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your team settings')">
        <div class="space-y-8">
            <div class="space-y-5">
                @if ($this->permissions->canUpdateTeam)
                    <form wire:submit="updateTeam" class="space-y-5 rounded-xl border border-border bg-card p-5">
                        <x-ui.input wire:model="teamName" :label="__('Team name') .' *'" required data-test="team-name-input" :error="$errors->first('teamName')"/>

                        <div class="flex justify-end">
                            <x-ui.button type="submit" data-test="team-save-button">
                                {{ __('Save') }}
                            </x-ui.button>
                        </div>
                    </form>
                @else
                    <div class="rounded-xl border border-border bg-card p-5">
                        <p class="text-sm font-medium">{{ $teamData['name'] }}</p>
                    </div>
                @endif
            </div>

            <div class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('Team members') }}</h3>
                        @if ($this->permissions->canAddMember || $this->permissions->canUpdateMember || $this->permissions->canRemoveMember)
                            <p class="text-xs text-muted-foreground mt-0.5">{{ __('Manage who belongs to this team') }}</p>
                        @endif
                    </div>

                    @if ($this->permissions->canCreateInvitation)
                        <x-ui.button size="sm" x-data x-on:click="$store.modals.open('invite-member')" data-test="invite-member-button">
                            <x-icon name="user-plus" class="h-4 w-4"/>
                            {{ __('Invite member') }}
                        </x-ui.button>
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach ($members as $member)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-4" data-test="member-row">
                            <div class="flex items-center gap-3 min-w-0">
                                <x-ui.avatar :name="$member['name']" :initials="$member['initials']" size="size-10 text-sm"/>
                                <div class="min-w-0">
                                    <div class="text-sm font-medium truncate">{{ $member['name'] }}</div>
                                    <p class="text-xs text-muted-foreground truncate">{{ $member['email'] }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                @if ($member['role'] !== 'owner' && $this->permissions->canUpdateMember)
                                    <x-ui.dropdown width="w-40" align="end">
                                        <x-slot:trigger>
                                            <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-xs font-medium hover:bg-secondary transition-colors cursor-pointer" data-test="member-role-trigger">
                                                {{ $member['role_label'] }}
                                                <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground"/>
                                            </button>
                                        </x-slot:trigger>
                                        @foreach ($availableRoles as $role)
                                            <x-ui.dropdown.item wire:click="updateMember({{ $member['id'] }}, '{{ $role['value'] }}')" data-test="member-role-option">
                                                {{ $role['label'] }}
                                            </x-ui.dropdown.item>
                                        @endforeach
                                    </x-ui.dropdown>
                                @else
                                    <x-ui.badge color="secondary">{{ $member['role_label'] }}</x-ui.badge>
                                @endif

                                @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                                    <button
                                        type="button"
                                        x-data
                                        x-on:click="$store.modals.open('remove-member-{{ $member['id'] }}')"
                                        class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-destructive/10 transition-colors cursor-pointer"
                                        title="{{ __('Remove member') }}"
                                        data-test="member-remove-button"
                                    >
                                        <x-icon name="x" class="h-4 w-4 text-destructive"/>
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                            <livewire:pages::teams.remove-member-modal
                                :team="$teamModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'remove-member-'.$member['id']"
                                :key="'remove-member-modal-'.$member['id']"
                            />
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($invitations) > 0)
                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('Pending invitations') }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">{{ __('Invitations that have not been accepted yet') }}</p>
                    </div>

                    <div class="space-y-3">
                        @foreach ($invitations as $invitation)
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-4" data-test="invitation-row">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-secondary">
                                        <x-icon name="mail" class="h-4 w-4 text-muted-foreground"/>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium truncate">{{ $invitation['email'] }}</div>
                                        <p class="text-xs text-muted-foreground">{{ $invitation['role_label'] }}</p>
                                    </div>
                                </div>

                                @if ($this->permissions->canCancelInvitation)
                                    <button
                                        type="button"
                                        x-data
                                        x-on:click="$store.modals.open('cancel-invitation-{{ $invitation['code'] }}')"
                                        class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-destructive/10 transition-colors cursor-pointer"
                                        title="{{ __('Cancel invitation') }}"
                                        data-test="invitation-cancel-button"
                                    >
                                        <x-icon name="x" class="h-4 w-4 text-destructive"/>
                                    </button>
                                @endif
                            </div>
                            @if ($this->permissions->canCancelInvitation)
                                <livewire:pages::teams.cancel-invitation-modal
                                    :team="$teamModel"
                                    :invitation-code="$invitation['code']"
                                    :invitation-email="$invitation['email']"
                                    :modal-name="'cancel-invitation-'.$invitation['code']"
                                    :key="'cancel-invitation-modal-'.$invitation['code']"
                                />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('Delete team') }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">{{ __('Permanently delete your team') }}</p>
                    </div>

                    <div class="space-y-4 rounded-xl border border-rose-500/20 bg-rose-500/5 p-5">
                        <div>
                            <p class="text-sm font-medium text-rose-600 dark:text-rose-400">{{ __('Warning') }}</p>
                            <p class="text-sm text-muted-foreground mt-0.5">{{ __('Please proceed with caution, this cannot be undone.') }}</p>
                        </div>

                        <x-ui.button variant="destructive" x-data x-on:click="$store.modals.open('delete-team')" data-test="delete-team-button">
                            {{ __('Delete team') }}
                        </x-ui.button>
                    </div>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>

    @if ($this->permissions->canCreateInvitation)
        <livewire:pages::teams.invite-member-modal :team="$teamModel" />
    @endif

    @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
        <livewire:pages::teams.delete-team-modal :team="$teamModel" />
    @endif
</section>
