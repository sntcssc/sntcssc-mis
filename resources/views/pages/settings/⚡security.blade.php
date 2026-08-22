<?php

use App\Concerns\PasswordValidationRules;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Toast::dispatch($this, 'success', __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;

        $this->dispatch('modal-open', name: 'delete-passkey-modal');
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';

        $this->dispatch('modal-close', name: 'delete-passkey-modal');
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Security settings') }}</h2>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form wire:submit="updatePassword" class="space-y-5 rounded-xl border border-border bg-card p-5">
            <x-ui.password
                wire:model="current_password"
                :label="__('Current password') .' *'"
                required
                autocomplete="current-password"
                :error="$errors->first('current_password')"
            />
            <x-ui.password
                wire:model="password"
                :label="__('New password') .' *'"
                required
                autocomplete="new-password"
                :error="$errors->first('password')"
            />
            <x-ui.password
                wire:model="password_confirmation"
                :label="__('Confirm password') .' *'"
                required
                autocomplete="new-password"
            />

            <div class="flex items-center justify-end">
                <x-ui.button type="submit" data-test="update-password-button">
                    {{ __('Update password') }}
                </x-ui.button>
            </div>
        </form>

        @if ($canManageTwoFactor)
            <section class="mt-8">
                <h3 class="text-sm font-semibold">{{ __('Two-factor authentication') }}</h3>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Manage your two-factor authentication settings') }}</p>

                <div class="mt-4 rounded-xl border border-border bg-card p-5 space-y-4 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="flex items-center gap-2.5 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3">
                            <x-icon name="shield-check" class="h-4 w-4 shrink-0 text-emerald-500"/>
                            <span class="text-sm">{{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}</span>
                        </div>

                        <div class="flex justify-start">
                            <x-ui.button variant="destructive" wire:click="disable">
                                {{ __('Disable 2FA') }}
                            </x-ui.button>
                        </div>

                        <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                    @else
                        <div class="space-y-4">
                            <p class="text-sm text-muted-foreground">
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </p>

                            <x-ui.button wire:click="$dispatch('start-two-factor-setup')" x-data x-on:click="$store.modals.open('two-factor-setup-modal')">
                                {{ __('Enable 2FA') }}
                            </x-ui.button>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($canManagePasskeys)
            <section class="mt-8">
                <h3 class="text-sm font-semibold">{{ __('Passkeys') }}</h3>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Manage your passkeys for passwordless sign-in') }}</p>

                <div class="mt-4 space-y-4" wire:cloak>
                    <div class="rounded-xl border border-border bg-card overflow-hidden">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-border' : '' }}">
                                <div class="flex items-center gap-4 min-w-0">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-secondary">
                                        <x-icon name="key-round" class="h-5 w-5 text-muted-foreground"/>
                                    </div>
                                    <div class="space-y-1 min-w-0">
                                        <div class="flex items-center gap-2.5 flex-wrap">
                                            <p class="text-sm font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <x-ui.badge color="secondary">{{ $passkey['authenticator'] }}</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="text-muted-foreground text-xs">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-destructive/10 transition-colors cursor-pointer"
                                    title="{{ __('Remove passkey') }}"
                                >
                                    <x-icon name="trash-2" class="h-4 w-4 text-destructive"/>
                                </button>
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-secondary">
                                    <x-icon name="key-round" class="h-7 w-7 text-muted-foreground"/>
                                </div>
                                <p class="text-sm font-medium">{{ __('No passkeys yet') }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ __('Add a passkey to sign in without a password') }}</p>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </section>
        @endif
    </x-pages::settings.layout>

    <x-ui.modal name="delete-passkey-modal" max-width="max-w-md" :title="__('Remove passkey')">
        <p class="text-sm text-muted-foreground">
            {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
        </p>

        <div class="flex gap-3 justify-end mt-5">
            <x-ui.button variant="outline" wire:click="closeDeleteModal">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button variant="destructive" wire:click="deletePasskey">
                {{ __('Remove passkey') }}
            </x-ui.button>
        </div>
    </x-ui.modal>
</section>
