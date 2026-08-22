<?php

use App\Models\Team;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public ?int $memberId = null;

    public string $memberName = '';

    public string $modalName = 'remove-member';

    public function mount(
        Team $team,
        ?int $memberId = null,
        ?string $memberName = null,
        ?string $modalName = null,
    ): void
    {
        $this->team = $team;
        $this->memberId = $memberId;
        $this->memberName = $memberName ?? '';
        $this->modalName = $modalName ?? ($memberId ? "remove-member-{$memberId}" : 'remove-member');
    }

    public function removeMember(): void
    {
        Gate::authorize('removeMember', $this->team);

        $user = User::findOrFail($this->memberId);

        if ($this->memberName === '') {
            $this->memberName = $user->name;
        }

        $this->team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($this->team)) {
            $user->switchTeam($user->personalTeam());
        }

        $this->dispatch('close-modal', name: $this->modalName);

        Toast::dispatch($this, 'success', __('Member removed.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<x-ui.modal :name="$modalName" max-width="max-w-md" :title="__('Remove team member')">
    <form wire:submit="removeMember" class="space-y-5">
        <p class="text-sm text-muted-foreground">
            {{ __('Are you sure you want to remove :name from this team?', ['name' => $memberName]) }}
        </p>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('{{ $modalName }}')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button variant="destructive" type="submit" data-test="remove-member-confirm">{{ __('Remove member') }}</x-ui.button>
        </div>
    </form>
</x-ui.modal>
