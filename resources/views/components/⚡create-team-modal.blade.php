<?php

use App\Actions\Teams\CreateTeam;
use App\Rules\TeamName;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $teamName = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['teamName']);

        $this->dispatch('modal-close', name: 'create-team');

        $this->reset('teamName');

        Toast::dispatch($this, 'success', __('Team created.'));

        $this->redirectRoute('teams.edit', ['team' => $team->slug], navigate: true);
    }
}; ?>

<x-ui.modal name="create-team" max-width="max-w-md" :title="__('Create a new team')" :description="__('Give your team a name to get started.')">
    <form wire:submit="createTeam" class="space-y-5">
        <x-ui.input
            wire:model="teamName"
            :label="__('Team name')"
            type="text"
            required
            :error="$errors->first('teamName')"
            data-test="switcher-create-team-name"
        />

        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('create-team')">
                {{ __('Cancel') }}
            </x-ui.button>

            <x-ui.button type="submit" data-test="switcher-create-team-submit">
                {{ __('Create team') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
