<x-layouts::auth :title="__('Verify OTP')">
    <div
        class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm"
        x-data="{
            verified: false,
            countdown: 30,

            init() {
                this.startCountdown();
            },

            startCountdown() {
                const timer = setInterval(() => {
                    if (this.countdown > 0) {
                        this.countdown--;
                    } else {
                        clearInterval(timer);
                    }
                }, 1000);
            },

            resend() {
                if (this.countdown > 0) return;

                this.countdown = 30;
                this.startCountdown();
            },
        }"
    >
        <div class="flex flex-col items-center mb-6">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-500/20 mb-3">
                <x-icon name="shield-check" class="h-6 w-6 text-emerald-500" x-show="! verified"/>
                <x-icon name="check-circle-2" class="h-6 w-6 text-emerald-500" x-show="verified" x-cloak/>
            </div>
            <h1 class="text-xl font-semibold">
                <span x-show="! verified">{{ __('Verify your number') }}</span>
                <span x-show="verified" x-cloak>{{ __('Verified') }}</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">
                <span x-show="! verified">{{ __('Enter the 6-digit code we sent to your mobile number.') }}</span>
                <span x-show="verified" x-cloak>{{ __('Your mobile number has been successfully verified.') }}</span>
            </p>
        </div>

        <div x-show="! verified">
            <form class="space-y-5" x-on:submit.prevent="verified = true">
                <x-ui.otp name="otp" length="6" class="my-5"/>

                <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold">
                    {{ __('Verify') }}
                </x-ui.button>
            </form>

            <div class="mt-5 text-center text-sm text-muted-foreground">
                <template x-if="countdown > 0">
                    <span>{{ __('Resend code in') }} <span class="font-medium text-foreground tabular-nums" x-text="countdown"></span> {{ __('seconds') }}</span>
                </template>
                <template x-if="countdown === 0">
                    <button type="button" x-on:click="resend()" class="text-emerald-500 hover:underline font-medium cursor-pointer">
                        {{ __('Resend code') }}
                    </button>
                </template>
            </div>
        </div>

        <div x-show="verified" x-cloak class="flex flex-col items-center gap-4">
            <div class="flex flex-col items-center gap-3 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-6 text-center w-full">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/15">
                    <x-icon name="check-circle-2" class="h-6 w-6 text-emerald-500"/>
                </span>
                <p class="text-sm font-semibold">{{ __('Verification successful') }}</p>
            </div>

            <x-ui.button href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="w-full h-[46px] rounded-lg text-sm font-semibold" wire:navigate>
                {{ auth()->check() ? __('Go to dashboard') : __('Continue to sign in') }}
            </x-ui.button>
        </div>

        <p class="mt-5 text-center text-sm text-muted-foreground">
            <a href="{{ route('login') }}" wire:navigate class="inline-flex items-center gap-1.5 text-emerald-500 hover:underline font-medium">
                <x-icon name="arrow-left" class="h-3.5 w-3.5"/>
                {{ __('Back to sign in') }}
            </a>
        </p>
    </div>
</x-layouts::auth>
