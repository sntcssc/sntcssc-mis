<?php

use App\Data\UserTeam;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function currentTeam(): ?array
    {
        $team = Auth::user()->currentTeam;

        return $team ? [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
        ] : null;
    }

    /**
     * @return Collection<int, UserTeam>
     */
    public function teams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }

    public function switchTeam(string $slug): void
    {
        $user = Auth::user();

        abort_unless(
            $user->belongsToTeam($team = Team::where('slug', $slug)->firstOrFail()),
            403,
        );

        $currentTeamSlug = $user->currentTeam?->slug;

        $user->switchTeam($team);

        if (! request()->header('Referer')) {
            $this->redirectRoute('dashboard', ['current_team' => $team->slug], navigate: true);

            return;
        }

        if (! $currentTeamSlug) {
            $this->redirect(request()->header('Referer'), navigate: true);

            return;
        }

        $redirectTo = $this->replaceCurrentTeamInReferer(
            request()->header('Referer'),
            $currentTeamSlug,
            $team->slug,
        );

        $this->redirect($redirectTo ?? request()->header('Referer'), navigate: true);
    }

    protected function replaceCurrentTeamInReferer(string $referer, string $currentTeamSlug, string $newTeamSlug): ?string
    {
        $redirectTo = preg_replace(
            '#/'.preg_quote($currentTeamSlug, '#').'(?=/|\?|$)#',
            '/'.$newTeamSlug,
            $referer,
            1,
        );

        return preg_replace(
            '#([?&]current_team=)'.preg_quote($currentTeamSlug, '#').'(?=&|$)#',
            '$1'.$newTeamSlug,
            $redirectTo ?? $referer,
            1,
        );
    }
}; ?>

<div class="hidden sm:block">
    <x-ui.dropdown width="w-56" align="end" offset="mt-1">
        <x-slot:trigger>
            <button
                type="button"
                class="flex h-9 items-center gap-1.5 rounded-md border border-border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:hover:bg-input/50 px-3 text-xs font-medium cursor-pointer transition-colors"
                data-test="team-switcher-trigger"
            >
                <span class="text-muted-foreground uppercase tracking-wide">{{ __('Team') }}</span>
                <span class="max-w-[120px] truncate">{{ $this->currentTeam()['name'] ?? __('Select team') }}</span>
                <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground"/>
            </button>
        </x-slot:trigger>

        <x-ui.dropdown.label>{{ __('Teams') }}</x-ui.dropdown.label>
        <x-ui.dropdown.separator/>

        @foreach ($this->teams() as $team)
            <button
                type="button"
                wire:click="switchTeam('{{ $team->slug }}')"
                class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm text-popover-foreground hover:bg-secondary transition-colors cursor-pointer"
                data-test="team-switcher-item"
            >
                <span class="truncate">{{ $team->name }}</span>
                @if ($team->isCurrent)
                    <span class="flex h-4 w-4 items-center justify-center rounded-full bg-primary/15">
                        <x-icon name="check" class="h-2.5 w-2.5 text-primary" stroke-width="3"/>
                    </span>
                @endif
            </button>
        @endforeach

        <x-ui.dropdown.separator/>
        <button
            type="button"
            x-data
            x-on:click="$store.modals.open('create-team')"
            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-primary hover:bg-secondary transition-colors cursor-pointer"
            data-test="team-switcher-new-team"
        >
            <x-icon name="plus" class="h-4 w-4"/>
            {{ __('New team') }}
        </button>
    </x-ui.dropdown>
</div>
