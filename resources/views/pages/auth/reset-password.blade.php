<x-layouts::auth :title="__('Reset password')">
    <div class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm">
        <div class="flex flex-col items-center mb-6">
            <a href="{{ route('home') }}" wire:navigate class="mb-3 transition-opacity hover:opacity-90">
                <x-app-logo-icon size="h-11 w-11" iconSize="h-5 w-5" />
            </a>
            <h1 class="text-xl font-semibold">{{ __('Reset password') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">{{ __('Please enter your new password below') }}</p>
        </div>

        {{-- Session Status --}}
        <x-auth-session-status class="mb-5" :status="session('status')"/>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <x-ui.input
                name="email"
                value="{{ old('email', request('email')) }}"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
                size="lg"
                :error="$errors->first('email')"
            />

            <x-ui.password
                name="password"
                :label="__('Password')"
                required
                autocomplete="new-password"
                placeholder="{{ __('Password') }}"
                size="lg"
                :error="$errors->first('password')"
            />

            <x-ui.password
                name="password_confirmation"
                :label="__('Confirm password')"
                required
                autocomplete="new-password"
                placeholder="{{ __('Confirm password') }}"
                size="lg"
                :error="$errors->first('password_confirmation')"
            />

            <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold" data-test="reset-password-button">
                {{ __('Reset password') }}
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
