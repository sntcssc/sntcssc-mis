<?php

use App\Actions\Teams\CreateTeam;
use App\Data\UserTeam;
use App\Models\Team;
use App\Rules\TeamName;
use App\Support\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Teams')] class extends Component {
    public string $name = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['name']);

        $this->dispatch('close-modal', name: 'create-team');

        $this->reset('name');

        Toast::dispatch($this, 'success', __('Team created.'));

        $this->redirectRoute('teams.edit', ['team' => $team->slug], navigate: true);
    }

    public function leaveTeam(int $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $user = Auth::user();

        Gate::authorize('leave', $team);

        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        $this->dispatch('close-modal', name: "leave-team-{$teamId}");

        Toast::dispatch($this, 'success', __('You left the team ":name"', ['name' => $team->name]));

        $this->redirectRoute('teams.index', navigate: true);
    }

    /**
     * @return Collection<int, UserTeam>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Teams') }}</h2>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your teams and team memberships')">
        <div class="flex items-center justify-end">
            <x-ui.button x-data x-on:click="$store.modals.open('create-team')" data-test="teams-new-team-button">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New team') }}
            </x-ui.button>
        </div>

        <div class="mt-6 space-y-3">
            @forelse ($this->teams as $team)
                <div class="flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4" data-test="team-row">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 shrink-0">
                            <x-icon name="users" class="h-5 w-5 text-emerald-500"/>
                        </span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium">{{ $team->name }}</span>
                                @if ($team->isPersonal)
                                    <x-ui.badge color="secondary">{{ __('Personal') }}</x-ui.badge>
                                @endif
                                @if ($team->isCurrent)
                                    <x-ui.badge color="success">{{ __('Current') }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="text-xs text-muted-foreground mt-0.5">{{ $team->roleLabel }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-1">
                        @if (! $team->isPersonal && $team->role !== 'owner')
                            <button
                                type="button"
                                x-data
                                x-on:click="$store.modals.open('leave-team-{{ $team->id }}')"
                                class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary transition-colors cursor-pointer"
                                title="{{ __('Leave team') }}"
                                data-test="team-leave-button"
                            >
                                <x-icon name="log-out" class="h-4 w-4 text-muted-foreground"/>
                            </button>
                        @endif

                        <a
                            href="{{ route('teams.edit', $team->slug) }}"
                            wire:navigate
                            class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary transition-colors"
                            title="{{ $team->role === 'member' ? __('View team') : __('Edit team') }}"
                            data-test="{{ $team->role === 'member' ? 'team-view-button' : 'team-edit-button' }}"
                        >
                            <x-icon name="{{ $team->role === 'member' ? 'eye' : 'pencil' }}" class="h-4 w-4 text-muted-foreground"/>
                        </a>
                    </div>
                </div>

                @if (! $team->isPersonal && $team->role !== 'owner')
                    <x-ui.modal name="leave-team-{{ $team->id }}" max-width="max-w-md" :title="__('Leave team')">
                        <form wire:submit="leaveTeam({{ $team->id }})" class="space-y-5">
                            <p class="text-sm text-muted-foreground">
                                {{ __('Are you sure you want to leave :name?', ['name' => $team->name]) }}
                            </p>

                            <div class="flex justify-end gap-2">
                                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('leave-team-{{ $team->id }}')">
                                    {{ __('Cancel') }}
                                </x-ui.button>

                                <x-ui.button variant="destructive" type="submit" data-test="leave-team-confirm">
                                    {{ __('Leave team') }}
                                </x-ui.button>
                            </div>
                        </form>
                    </x-ui.modal>
                @endif
            @empty
                <div class="rounded-xl border border-border bg-card p-8 text-center text-muted-foreground text-sm">
                    {{ __("You don't belong to any teams yet.") }}
                </div>
            @endforelse
        </div>
    </x-pages::settings.layout>

    <x-ui.modal name="create-team" max-width="max-w-md" :title="__('Create a new team')" :description="__('Give your team a name to get started.')">
        <form wire:submit="createTeam" class="space-y-5">
            <x-ui.input
                wire:model="name"
                :label="__('Team name') .' *'"
                type="text"
                required
                autofocus
                :error="$errors->first('name')"
                data-test="create-team-name"
            />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('create-team')">
                    {{ __('Cancel') }}
                </x-ui.button>

                <x-ui.button type="submit" data-test="create-team-submit">
                    {{ __('Create team') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
