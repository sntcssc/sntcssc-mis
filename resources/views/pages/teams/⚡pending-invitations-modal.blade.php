<?php

use App\Models\TeamInvitation;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $showPendingInvitationsModal = true;

    public function mount(): void
    {
        if (session()->pull('team-invitation-accepted')) {
            Toast::dispatch($this, 'success', __('Invitation accepted.'));
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{code: string, inviter_name: string, team_name: string}>
     */
    #[Computed]
    public function pendingInvitations(): \Illuminate\Support\Collection
    {
        $email = Str::lower(Auth::user()->email);

        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviter_name' => $invitation->inviter->name,
                'team_name' => $invitation->team->name,
            ]);
    }

    public function acceptInvitation(string $code): void
    {
        $invitation = $this->findPendingInvitation($code);

        $user = Auth::user();

        DB::transaction(function () use ($user, $invitation) {
            $team = $invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role]
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });

        session()->flash('team-invitation-accepted', true);

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function declineInvitation(string $code): void
    {
        $invitation = $this->findPendingInvitation($code);

        $invitation->delete();

        Toast::dispatch($this, 'success', __('Invitation declined.'));
    }

    private function findPendingInvitation(string $code): TeamInvitation
    {
        $invitation = TeamInvitation::query()
            ->where('code', $code)
            ->whereNull('accepted_at')
            ->firstOrFail();

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has expired.')],
            ]);
        }

        if (Str::lower($invitation->email) !== Str::lower(Auth::user()->email)) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation was sent to a different email address.')],
            ]);
        }

        return $invitation;
    }
}; ?>

<div>
    @if ($this->pendingInvitations->isNotEmpty())
        <x-ui.modal name="pending-invitations" max-width="max-w-md" :title="__('Pending team invitations')" :description="__('Accept or decline the teams you have been invited to join.')">
            <div data-test="pending-invitations-modal" class="space-y-4">
                @foreach ($this->pendingInvitations as $invitation)
                    <div data-test="pending-invitation-row" class="rounded-lg border border-border p-4">
                        <div class="space-y-1">
                            <p class="text-sm font-medium">{{ $invitation['team_name'] }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ __(':inviter invited you to join this team.', ['inviter' => $invitation['inviter_name']]) }}
                            </p>
                        </div>

                        <div class="mt-4 flex justify-end gap-2">
                            <x-ui.button
                                variant="outline"
                                wire:click="declineInvitation('{{ $invitation['code'] }}')"
                                wire:loading.attr="disabled"
                                data-test="pending-invitation-decline"
                            >
                                {{ __('Decline') }}
                            </x-ui.button>

                            <x-ui.button
                                wire:click="acceptInvitation('{{ $invitation['code'] }}')"
                                wire:loading.attr="disabled"
                                data-test="pending-invitation-accept"
                            >
                                {{ __('Accept') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endforeach

                @error('invitation')
                    <p class="text-xs text-destructive">{{ $message }}</p>
                @enderror
            </div>
        </x-ui.modal>
    @endif
</div>
