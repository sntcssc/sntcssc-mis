<x-layouts::auth :title="__('Two-factor authentication')">
    <div
        class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm"
        x-data="{
            showRecoveryInput: @js($errors->has('recovery_code')),

            toggleInput() {
                this.showRecoveryInput = ! this.showRecoveryInput;

                $nextTick(() => {
                    if (this.showRecoveryInput) {
                        this.$root.querySelector('[data-recovery-input]')?.focus();
                    }
                });
            },
        }"
    >
        <div class="flex flex-col items-center mb-6">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-500/20 mb-3">
                <x-icon name="shield-check" class="h-6 w-6 text-emerald-500"/>
            </div>
            <h1 class="text-xl font-semibold" x-show="! showRecoveryInput">{{ __('Authentication code') }}</h1>
            <h1 class="text-xl font-semibold" x-show="showRecoveryInput" x-cloak>{{ __('Recovery code') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center" x-show="! showRecoveryInput">
                {{ __('Enter the authentication code provided by your authenticator application.') }}
            </p>
            <p class="text-sm text-muted-foreground mt-1 text-center" x-show="showRecoveryInput" x-cloak>
                {{ __('Please confirm access to your account by entering one of your emergency recovery codes.') }}
            </p>
        </div>

        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf

            <div class="space-y-5">
                <div x-show="! showRecoveryInput">
                    <x-ui.otp name="code" length="6" class="my-5"/>
                </div>

                <div x-show="showRecoveryInput" x-cloak>
                    <input
                        type="text"
                        name="recovery_code"
                        data-recovery-input
                        x-bind:required="showRecoveryInput"
                        autocomplete="one-time-code"
                        placeholder="xxxx-xxxx"
                        class="mt-2 flex h-11 w-full rounded-lg border border-input bg-transparent px-4 font-mono shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />

                    @error('recovery_code')
                        <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                @error('code')
                    <p class="text-xs text-destructive">{{ $message }}</p>
                @enderror

                <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold">
                    {{ __('Continue') }}
                </x-ui.button>
            </div>

            <div class="mt-5 space-x-0.5 text-sm leading-5 text-center">
                <span class="opacity-50">{{ __('or you can') }}</span>
                <span class="inline font-medium underline cursor-pointer opacity-80" x-on:click="toggleInput()">
                    <span x-show="! showRecoveryInput">{{ __('login using a recovery code') }}</span>
                    <span x-show="showRecoveryInput" x-cloak>{{ __('login using an authentication code') }}</span>
                </span>
            </div>
        </form>
    </div>
</x-layouts::auth>
