<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\UniqueTeamInvitation;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function createInvitation(): void
    {
        Gate::authorize('inviteMember', $this->team);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team)],
            'inviteRole' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        $invitation = $this->team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => TeamRole::from($validated['inviteRole']),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        $this->reset('inviteEmail', 'inviteRole');
        $this->dispatch('close-modal', name: 'invite-member');

        Toast::dispatch($this, 'success', __('Invitation sent.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }

    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }
}; ?>

<x-ui.modal name="invite-member" max-width="max-w-md" :title="__('Invite a team member')" :description="__('Send an invitation to join this team.')">
    <form wire:submit="createInvitation" class="space-y-4">
        <x-ui.input wire:model="inviteEmail" type="email" :label="__('Email address') .' *'" required data-test="invite-email" :error="$errors->first('inviteEmail')"/>

        <div>
            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Role') }}</label>
            <x-ui.select wire:model="inviteRole" class="mt-2" data-test="invite-role">
                @foreach ($this->availableRoles as $role)
                    <option value="{{ $role['value'] }}">{{ $role['label'] }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('invite-member')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="submit" data-test="invite-submit">{{ __('Send invitation') }}</x-ui.button>
        </div>
    </form>
</x-ui.modal>
