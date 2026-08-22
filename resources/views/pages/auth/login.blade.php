<x-layouts::auth :title="__('Log in')">
    <div class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm">
        <div
            class="flex flex-col items-center mb-6"
            x-data="{
                mode: 'email',
                mobileNote: false,
            }"
        >
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 mb-3">
                <x-icon name="zap" class="h-5 w-5 text-emerald-500"/>
            </div>
            <h1 class="text-xl font-semibold">{{ __('Sign in') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">
                <span x-show="mode === 'email'">{{ __('Welcome back to :app. Use your account email and password.', ['app' => config('app.name')]) }}</span>
                <span x-show="mode === 'mobile'" x-cloak>{{ __('Enter your mobile number to receive a one-time verification code.') }}</span>
            </p>

            {{-- Email / Mobile segmented switcher --}}
            <div class="flex gap-1 p-1 bg-secondary/50 rounded-lg mt-6 w-full">
                <button
                    type="button"
                    x-on:click="mode = 'email'; mobileNote = false"
                    class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-md text-sm font-medium transition-colors cursor-pointer"
                    :class="mode === 'email' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                >
                    <x-icon name="mail" class="h-4 w-4"/>
                    {{ __('Email') }}
                </button>
                <button
                    type="button"
                    x-on:click="mode = 'mobile'"
                    class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-md text-sm font-medium transition-colors cursor-pointer"
                    :class="mode === 'mobile' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                >
                    <x-icon name="smartphone" class="h-4 w-4"/>
                    {{ __('Mobile') }}
                </button>
            </div>
        </div>

        {{-- Session Status --}}
        <x-auth-session-status class="mb-5" :status="session('status')"/>

        @if ($teamInvitation)
            <div class="mb-5">
                <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Log in')"/>
            </div>
        @endif

        {{-- Email login --}}
        <div x-show="mode === 'email'">
            <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                @csrf

                <x-ui.input
                    name="email"
                    :label="__('Email')"
                    value="{{ old('email') }}"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="email@example.com"
                    size="lg"
                    :error="$errors->first('email')"
                />

                <div>
                    <x-ui.password
                        name="password"
                        :label="__('Password')"
                        required
                        autocomplete="current-password"
                        placeholder="{{ __('Password') }}"
                        size="lg"
                        :error="$errors->first('password')"
                    />

                    @if (Route::has('password.request'))
                        <div class="text-right mt-2">
                            <a href="{{ route('password.request') }}" wire:navigate class="text-sm text-emerald-500 hover:underline">
                                {{ __('Forgot password?') }}
                            </a>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-between">
                    <x-ui.checkbox name="remember" :label="__('Remember me')" @checked(old('remember'))/>
                </div>

                <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold" data-test="login-button">
                    {{ __('Sign in') }}
                </x-ui.button>
            </form>

            <div class="mt-4">
                <x-passkey-verify :separator="__('Or sign in with a passkey')"/>
            </div>

            @if (app()->environment('local'))
                <div class="mt-6 pt-6 border-t border-border">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-sm font-medium">{{ __('Demo Credentials') }}</span>
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground bg-secondary px-2 py-0.5 rounded-md">{{ __('Demo') }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ __('Register a new account to try the dashboard — credentials below are placeholders.') }}</p>
                </div>
            @endif
        </div>

        {{-- Mobile OTP login (visual only until SMS gateway is configured) --}}
        <div x-show="mode === 'mobile'" x-cloak>
            <form class="space-y-5" x-on:submit.prevent="mobileNote = true">
                <div class="space-y-2">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Mobile number') }}</label>
                    <input
                        type="tel"
                        placeholder="+91 98765 43210"
                        maxlength="15"
                        class="flex h-11 w-full rounded-lg border border-input bg-transparent px-4 outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    <p class="text-xs text-muted-foreground">{{ __('We will send a 6-digit verification code to this number.') }}</p>
                </div>

                <button
                    type="submit"
                    class="flex w-full h-[46px] items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer"
                >
                    {{ __('Send verification code') }}
                </button>

                <div x-show="mobileNote" x-cloak class="flex items-center gap-2.5 rounded-lg border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-600 dark:text-amber-400">
                    <x-icon name="alert-triangle" class="h-4 w-4 shrink-0 text-amber-500"/>
                    <span>{{ __('SMS gateway is not configured yet. Mobile OTP sign-in will be enabled once it is set up.') }}</span>
                </div>
            </form>

            <p class="mt-5 text-center text-sm text-muted-foreground">
                {{ __('Remember your password?') }}
                <button type="button" x-on:click="mode = 'email'" class="text-emerald-500 hover:underline font-medium cursor-pointer">
                    {{ __('Sign in with email') }}
                </button>
            </p>
        </div>

        <p class="mt-5 text-center text-sm text-muted-foreground" x-show="mode === 'email'">
            {{ __("Don't have an account?") }}
            <a
                href="{{ $teamInvitation ? route('register', ['invitation' => $teamInvitation['code']]) : route('register') }}"
                data-test="register-link"
                wire:navigate
                class="text-emerald-500 hover:underline font-medium"
            >
                {{ __('Sign up') }}
            </a>
        </p>
    </div>
</x-layouts::auth>
