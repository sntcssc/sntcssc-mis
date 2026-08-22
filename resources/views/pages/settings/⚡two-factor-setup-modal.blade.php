<?php

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showVerificationStep = false;

    public bool $setupComplete = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    /**
     * Mount the component.
     */
    public function mount(bool $requiresConfirmation): void
    {
        $this->requiresConfirmation = $requiresConfirmation;
    }

    #[On('start-two-factor-setup')]
    public function startTwoFactorSetup(): void
    {
        $enableTwoFactorAuthentication = app(EnableTwoFactorAuthentication::class);
        $enableTwoFactorAuthentication(auth()->user());

        $this->loadSetupData();
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = auth()->user()?->fresh();

        try {
            if (! $user || ! $user->two_factor_secret) {
                throw new Exception('Two-factor setup secret is not available.');
            }

            $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
            $this->manualSetupKey = decrypt($user->two_factor_secret);
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
        $this->dispatch('two-factor-enabled');
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $confirmTwoFactorAuthentication(auth()->user(), $this->code);

        $this->setupComplete = true;

        $this->closeModal();

        $this->dispatch('two-factor-enabled');
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showVerificationStep',
            'setupComplete',
        );

        $this->resetErrorBag();

        $this->dispatch('modal-close', name: 'two-factor-setup-modal');
    }

    /**
     * Get the current modal configuration state.
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->setupComplete) {
            return [
                'title' => __('Two-factor authentication enabled'),
                'description' => __('Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.'),
                'buttonText' => __('Close'),
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => __('Verify authentication code'),
                'description' => __('Enter the 6-digit code from your authenticator app.'),
                'buttonText' => __('Continue'),
            ];
        }

        return [
            'title' => __('Enable two-factor authentication'),
            'description' => __('To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.'),
            'buttonText' => __('Continue'),
        ];
    }
}; ?>

<x-ui.modal name="two-factor-setup-modal" max-width="max-w-md" :title="$this->modalConfig['title']" :description="$this->modalConfig['description']">
    <div class="space-y-6">
        <div class="flex flex-col items-center space-y-4">
            <div class="p-0.5 w-auto rounded-full border border-stone-100 dark:border-stone-600 bg-white dark:bg-stone-800 shadow-sm">
                <div class="p-2.5 rounded-full border border-stone-200 dark:border-stone-600 overflow-hidden bg-stone-100 dark:bg-stone-200 relative">
                    <div class="flex items-stretch absolute inset-0 w-full h-full divide-x [&>div]:flex-1 divide-stone-200 dark:divide-stone-300 justify-around opacity-50">
                        @for ($i = 1; $i <= 5; $i++)
                            <div></div>
                        @endfor
                    </div>

                    <div class="flex flex-col items-stretch absolute w-full h-full divide-y [&>div]:flex-1 inset-0 divide-stone-200 dark:divide-stone-300 justify-around opacity-50">
                        @for ($i = 1; $i <= 5; $i++)
                            <div></div>
                        @endfor
                    </div>

                    <x-icon name="qr-code" class="relative z-20 h-4 w-4 text-foreground"/>
                </div>
            </div>
        </div>

        @if ($showVerificationStep)
            <div class="space-y-6">
                <div class="flex flex-col items-center justify-center">
                    <x-ui.otp name="code" model="code" length="6"/>
                </div>

                @error('code')
                    <p class="text-center text-xs text-destructive">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <x-ui.button variant="outline" class="flex-1" wire:click="resetVerification">
                        {{ __('Back') }}
                    </x-ui.button>

                    <x-ui.button class="flex-1" wire:click="confirmTwoFactor" x-data x-bind:disabled="$wire.code.length < 6">
                        {{ __('Confirm') }}
                    </x-ui.button>
                </div>
            </div>
        @else
            @error('setupData')
                <div class="flex items-center gap-2.5 rounded-lg border border-rose-500/20 bg-rose-500/5 px-4 py-3 text-sm text-rose-600 dark:text-rose-400">
                    <x-icon name="alert-circle" class="h-4 w-4 shrink-0 text-rose-500"/>
                    {{ $message }}
                </div>
            @enderror

            <div class="flex justify-center">
                <div class="relative w-56 overflow-hidden rounded-lg border border-border aspect-square">
                    @empty($qrCodeSvg)
                        <div class="absolute inset-0 flex items-center justify-center bg-muted animate-pulse">
                            <x-icon name="loader-2" class="h-5 w-5 text-muted-foreground animate-spin"/>
                        </div>
                    @else
                        <div class="flex items-center justify-center h-full p-4">
                            <div class="bg-white p-3 rounded">
                                {!! $qrCodeSvg !!}
                            </div>
                        </div>
                    @endempty
                </div>
            </div>

            <div>
                <x-ui.button class="w-full" :disabled="$errors->has('setupData')" wire:click="showVerificationIfNecessary">
                    {{ $this->modalConfig['buttonText'] }}
                </x-ui.button>
            </div>

            <div class="space-y-4">
                <div class="relative flex items-center justify-center w-full">
                    <div class="absolute inset-0 w-full h-px top-1/2 bg-border"></div>
                    <span class="relative px-2 text-sm bg-card text-muted-foreground">
                        {{ __('or, enter the code manually') }}
                    </span>
                </div>

                <div
                    class="flex items-stretch w-full rounded-lg border border-border overflow-hidden"
                    x-data="{
                        copied: false,
                        async copy() {
                            try {
                                await navigator.clipboard.writeText('{{ $manualSetupKey }}');
                                this.copied = true;
                                setTimeout(() => this.copied = false, 1500);
                            } catch (e) {
                                console.warn('Could not copy to clipboard');
                            }
                        }
                    }"
                >
                    @empty($manualSetupKey)
                        <div class="flex items-center justify-center w-full p-3 bg-muted">
                            <x-icon name="loader-2" class="h-4 w-4 text-muted-foreground animate-spin"/>
                        </div>
                    @else
                        <input
                            type="text"
                            readonly
                            value="{{ $manualSetupKey }}"
                            class="w-full p-3 bg-transparent outline-none font-mono text-sm"
                        />

                        <button
                            type="button"
                            x-on:click="copy()"
                            class="px-3 transition-colors border-l border-border hover:bg-secondary cursor-pointer"
                            aria-label="{{ __('Copy setup key') }}"
                        >
                            <x-icon name="copy" class="h-4 w-4 text-muted-foreground" x-show="! copied"/>
                            <x-icon name="check" class="h-4 w-4 text-emerald-500" x-show="copied" x-cloak/>
                        </button>
                    @endempty
                </div>
            </div>
        @endif
    </div>
</x-ui.modal>
