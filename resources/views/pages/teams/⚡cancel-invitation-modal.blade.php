<?php

use App\Models\Team;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $invitationCode = '';

    public string $invitationEmail = '';

    public string $modalName = 'cancel-invitation';

    public function mount(
        Team $team,
        ?string $invitationCode = null,
        ?string $invitationEmail = null,
        ?string $modalName = null,
    ): void
    {
        $this->team = $team;
        $this->invitationCode = $invitationCode ?? '';
        $this->invitationEmail = $invitationEmail ?? '';
        $this->modalName = $modalName ?? ($invitationCode ? "cancel-invitation-{$invitationCode}" : 'cancel-invitation');
    }

    public function cancelInvitation(): void
    {
        $invitation = $this->team->invitations()->where('code', $this->invitationCode)->firstOrFail();

        if ($this->invitationEmail === '') {
            $this->invitationEmail = $invitation->email;
        }

        Gate::authorize('cancelInvitation', $this->team);

        $invitation->delete();

        $this->dispatch('close-modal', name: $this->modalName);

        Toast::dispatch($this, 'success', __('Invitation cancelled.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<x-ui.modal :name="$modalName" max-width="max-w-md" :title="__('Cancel invitation')">
    <form wire:submit="cancelInvitation" class="space-y-5">
        <p class="text-sm text-muted-foreground">
            {{ __('Are you sure you want to cancel the invitation for :email?', ['email' => $invitationEmail]) }}
        </p>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('{{ $modalName }}')">
                {{ __('Keep invitation') }}
            </x-ui.button>
            <x-ui.button variant="destructive" type="submit" data-test="cancel-invitation-confirm">{{ __('Cancel invitation') }}</x-ui.button>
        </div>
    </form>
</x-ui.modal>
