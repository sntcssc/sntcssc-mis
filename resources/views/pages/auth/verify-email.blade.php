<x-layouts::auth :title="__('Email verification')">
    <div class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm">
        <div class="flex flex-col items-center mb-6">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 mb-3">
                <x-icon name="mail" class="h-5 w-5 text-emerald-500"/>
            </div>
            <h1 class="text-xl font-semibold">{{ __('Verify your email') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">
                {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
            </p>
        </div>

        @if (session('status') == 'verification-link-sent')
            <div class="mb-5 flex items-center gap-2.5 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                <x-icon name="check-circle-2" class="h-4 w-4 shrink-0 text-emerald-500"/>
                <span>{{ __('A new verification link has been sent to the email address you provided during registration.') }}</span>
            </div>
        @endif

        <div class="flex flex-col items-center justify-between gap-3">
            @if (\App\Services\EmailService::isOtpVerification())
                <a href="{{ route('verify-otp', ['type' => 'email']) }}" class="w-full">
                    <x-ui.button type="button" class="w-full h-[46px] rounded-lg text-sm font-semibold">
                        <x-icon name="key-round" class="h-4 w-4 mr-2"/>
                        {{ __('Enter 6-Digit OTP Code') }}
                    </x-ui.button>
                </a>
            @else
                <form method="POST" action="{{ route('verification.send') }}" class="w-full">
                    @csrf
                    <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold">
                        {{ __('Resend verification email') }}
                    </x-ui.button>
                </form>
            @endif

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <x-ui.button variant="ghost" type="submit" class="w-full text-sm cursor-pointer" data-test="logout-button">
                    {{ __('Log out') }}
                </x-ui.button>
            </form>
        </div>
    </div>
</x-layouts::auth>
