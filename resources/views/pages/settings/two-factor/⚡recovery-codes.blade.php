<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div
    class="rounded-xl border border-border bg-card overflow-hidden"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div class="p-5 space-y-1.5">
        <div class="flex items-center gap-2">
            <x-icon name="lock" class="h-4 w-4 text-muted-foreground"/>
            <h3 class="text-sm font-semibold">{{ __('2FA recovery codes') }}</h3>
        </div>
        <p class="text-xs text-muted-foreground">
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </p>
    </div>

    <div class="px-5 pb-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-ui.button size="sm" x-show="! showRecoveryCodes" x-on:click="showRecoveryCodes = true">
                <x-icon name="eye" class="h-4 w-4"/>
                {{ __('View recovery codes') }}
            </x-ui.button>

            <x-ui.button size="sm" x-show="showRecoveryCodes" x-cloak x-on:click="showRecoveryCodes = false">
                <x-icon name="eye-off" class="h-4 w-4"/>
                {{ __('Hide recovery codes') }}
            </x-ui.button>

            @if (filled($recoveryCodes))
                <x-ui.button size="sm" variant="outline" x-show="showRecoveryCodes" x-cloak wire:click="regenerateRecoveryCodes">
                    <x-icon name="refresh-cw" class="h-4 w-4"/>
                    {{ __('Regenerate codes') }}
                </x-ui.button>
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            x-cloak
            class="relative overflow-hidden"
        >
            <div class="mt-4 space-y-3">
                @error('recoveryCodes')
                    <div class="flex items-center gap-2.5 rounded-lg border border-rose-500/20 bg-rose-500/5 px-4 py-3 text-sm text-rose-600 dark:text-rose-400">
                        <x-icon name="alert-circle" class="h-4 w-4 shrink-0 text-rose-500"/>
                        {{ $message }}
                    </div>
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        class="grid gap-1 p-4 font-mono text-sm rounded-lg bg-secondary"
                        role="list"
                        aria-label="{{ __('Recovery codes') }}"
                    >
                        @foreach ($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="select-text"
                                wire:loading.class="opacity-50 animate-pulse"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
