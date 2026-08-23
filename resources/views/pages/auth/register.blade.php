<x-layouts::auth :title="__('Register')">
    <div class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm">
        <div class="flex flex-col items-center mb-6">
            <a href="{{ route('home') }}" wire:navigate class="mb-3 transition-opacity hover:opacity-90">
                <x-app-logo-icon size="h-11 w-11" iconSize="h-5 w-5" />
            </a>
            <h1 class="text-xl font-semibold">{{ __('Create an account') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">{{ __('Enter your details below to create your account') }}</p>
        </div>

        {{-- Session Status --}}
        <x-auth-session-status class="mb-5" :status="session('status')"/>

        @if ($teamInvitation)
            <div class="mb-5">
                <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Register')"/>
            </div>
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="space-y-5">
            @csrf

            <x-ui.input
                name="name"
                :label="__('Full name')"
                value="{{ old('name') }}"
                type="text"
                required
                autofocus
                autocomplete="name"
                placeholder="{{ __('Full name') }}"
                size="lg"
                :error="$errors->first('name')"
            />

            <x-ui.input
                name="email"
                :label="__('Email')"
                value="{{ old('email') }}"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
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

            <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold" data-test="register-user-button">
                {{ __('Create account') }}
            </x-ui.button>
        </form>

        <p class="mt-5 text-center text-sm text-muted-foreground">
            {{ __('Already have an account?') }}
            <a
                href="{{ $teamInvitation ? route('login', ['invitation' => $teamInvitation['code']]) : route('login') }}"
                data-test="team-invitation-login-link"
                wire:navigate
                class="text-emerald-500 hover:underline font-medium"
            >
                {{ __('Sign in') }}
            </a>
        </p>
    </div>
</x-layouts::auth>
