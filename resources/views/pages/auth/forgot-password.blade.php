<x-layouts::auth :title="__('Forgot password')">
    <div class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm">
        <div class="flex flex-col items-center mb-6">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 mb-3">
                <x-icon name="zap" class="h-5 w-5 text-emerald-500"/>
            </div>
            <h1 class="text-xl font-semibold">{{ __('Forgot password?') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">{{ __('No problem. Enter your account email and we will send you a password reset link.') }}</p>
        </div>

        {{-- Session Status --}}
        <x-auth-session-status class="mb-5" :status="session('status')"/>

        @if (session('status'))
            <div class="flex flex-col items-center gap-4 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-6 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/15">
                    <x-icon name="check-circle-2" class="h-6 w-6 text-emerald-500"/>
                </span>
                <div>
                    <p class="text-sm font-semibold">{{ __('Reset link sent') }}</p>
                    <p class="text-sm text-muted-foreground mt-1">{{ session('status') }}</p>
                </div>
                <x-ui.button variant="outline" type="submit" form="resend-form">
                    {{ __('Send again') }}
                </x-ui.button>
            </div>
        @endif

        <form id="resend-form" method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf

            <x-ui.input
                name="email"
                :label="__('Email')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
                size="lg"
                :error="$errors->first('email')"
            />

            <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold" data-test="email-password-reset-link-button">
                {{ __('Send reset link') }}
            </x-ui.button>
        </form>

        <p class="mt-5 text-center text-sm text-muted-foreground">
            <a href="{{ route('login') }}" wire:navigate class="inline-flex items-center gap-1.5 text-emerald-500 hover:underline font-medium">
                <x-icon name="arrow-left" class="h-3.5 w-3.5"/>
                {{ __('Back to sign in') }}
            </a>
        </p>
    </div>
</x-layouts::auth>
