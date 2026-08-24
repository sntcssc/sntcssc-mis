@php
    $smsEnabled = \App\Services\SmsService::isEnabled();
    $emailEnabled = \App\Services\EmailService::isEnabled();
@endphp

<x-layouts::auth :title="__('Log in')">
    <div
        class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm"
        x-data="{
            loginMethod: 'password', // 'password' or 'otp'
            otpChannel: '{{ $emailEnabled ? 'email' : 'mobile' }}', // 'email' or 'mobile'
            identifier: '',
            otpCode: '',
            remember: false,
            step: 'request', // 'request' or 'verify'
            loading: false,
            countdown: 0,
            timer: null,
            statusMessage: '',
            errorMessage: '',
            plainOtp: '',

            startCountdown(seconds = 60) {
                this.countdown = seconds;
                if (this.timer) clearInterval(this.timer);
                this.timer = setInterval(() => {
                    if (this.countdown > 0) {
                        this.countdown--;
                    } else {
                        clearInterval(this.timer);
                    }
                }, 1000);
            },

            async sendOtp() {
                if (! this.identifier) {
                    this.errorMessage = '{{ __('Please enter your email or mobile number.') }}';
                    return;
                }

                this.loading = true;
                this.errorMessage = '';
                this.statusMessage = '';

                try {
                    const response = await fetch('{{ route('login.otp.send') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            identifier: this.identifier
                        })
                    });

                    const data = await response.json();

                    if (! response.ok) {
                        this.errorMessage = data.message || (data.errors ? Object.values(data.errors)[0][0] : '{{ __('Failed to send OTP.') }}');
                    } else {
                        this.step = 'verify';
                        this.statusMessage = data.message;
                        this.plainOtp = data.plain_code || '';
                        this.startCountdown(data.cooldown_seconds || 60);
                    }
                } catch (e) {
                    this.errorMessage = '{{ __('Network error. Please try again.') }}';
                } finally {
                    this.loading = false;
                }
            },

            async verifyOtp() {
                if (! this.otpCode || this.otpCode.length < 4) {
                    this.errorMessage = '{{ __('Please enter the verification code.') }}';
                    return;
                }

                this.loading = true;
                this.errorMessage = '';

                try {
                    const response = await fetch('{{ route('login.otp.verify') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            identifier: this.identifier,
                            code: this.otpCode,
                            remember: this.remember
                        })
                    });

                    const data = await response.json();

                    if (! response.ok) {
                        this.errorMessage = data.message || (data.errors ? Object.values(data.errors)[0][0] : '{{ __('Verification failed.') }}');
                    } else {
                        window.location.href = data.redirect_url || '{{ route('home') }}';
                    }
                } catch (e) {
                    this.errorMessage = '{{ __('Network error. Please try again.') }}';
                } finally {
                    this.loading = false;
                }
            }
        }"
    >
        <div class="flex flex-col items-center mb-6">
            <a href="{{ route('home') }}" wire:navigate class="mb-3 transition-opacity hover:opacity-90">
                <x-app-logo-icon size="h-11 w-11" iconSize="h-5 w-5" />
            </a>
            <h1 class="text-xl font-semibold">{{ __('Sign in') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">
                <span x-show="loginMethod === 'password'">{{ __('Welcome back to :app. Sign in with your password or one-time OTP.', ['app' => \App\Models\Setting::appName()]) }}</span>
                <span x-show="loginMethod === 'otp'" x-cloak>{{ __('Sign in passwordless using a one-time code sent to your email or phone.') }}</span>
            </p>

            {{-- Method Switcher (Password vs OTP) --}}
            <div class="flex gap-1 p-1 bg-secondary/50 rounded-lg mt-6 w-full">
                <button
                    type="button"
                    x-on:click="loginMethod = 'password'; errorMessage = ''; statusMessage = ''"
                    class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs sm:text-sm font-medium transition-colors cursor-pointer"
                    :class="loginMethod === 'password' ? 'bg-card text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'"
                >
                    <x-icon name="key-round" class="h-4 w-4"/>
                    {{ __('Password Login') }}
                </button>
                <button
                    type="button"
                    x-on:click="loginMethod = 'otp'; errorMessage = ''; statusMessage = ''"
                    class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs sm:text-sm font-medium transition-colors cursor-pointer"
                    :class="loginMethod === 'otp' ? 'bg-card text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'"
                >
                    <x-icon name="shield-check" class="h-4 w-4"/>
                    {{ __('OTP Sign In') }}
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

        {{-- Method 1: Password Login (Supports Email OR Mobile Number) --}}
        <div x-show="loginMethod === 'password'">
            <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                @csrf

                <x-ui.input
                    name="email"
                    :label="__('Email or Mobile Number')"
                    value="{{ old('email') }}"
                    type="text"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="{{ $smsEnabled ? __('email@example.com or +91 98765 43210') : __('email@example.com') }}"
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
        </div>

        {{-- Method 2: OTP Login (Email or Phone OTP) --}}
        <div x-show="loginMethod === 'otp'" x-cloak class="space-y-5">
            {{-- Error & Status Banners --}}
            <div x-show="errorMessage" x-cloak class="flex items-center gap-2.5 rounded-lg border border-rose-500/20 bg-rose-500/5 px-4 py-3 text-xs text-rose-600 dark:text-rose-400">
                <x-icon name="alert-circle" class="h-4 w-4 shrink-0 text-rose-500"/>
                <span x-text="errorMessage"></span>
            </div>

            <div x-show="statusMessage" x-cloak class="flex items-center gap-2.5 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-xs text-emerald-600 dark:text-emerald-400">
                <x-icon name="check-circle-2" class="h-4 w-4 shrink-0 text-emerald-500"/>
                <span x-text="statusMessage"></span>
            </div>

            {{-- Step 1: Request OTP --}}
            <div x-show="step === 'request'" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Registered Email or Mobile Number') }} *
                    </label>
                    <div class="relative">
                        <input
                            type="text"
                            x-model="identifier"
                            placeholder="{{ $smsEnabled ? __('name@example.com or +91 9876543210') : __('name@example.com') }}"
                            class="flex h-11 w-full rounded-lg border border-input bg-transparent px-4 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            x-on:keydown.enter.prevent="sendOtp()"
                            autofocus
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        @if ($smsEnabled && $emailEnabled)
                            {{ __('If you enter a mobile number, you will receive an SMS OTP. If you enter an email, you will receive an Email OTP.') }}
                        @elseif ($emailEnabled)
                            {{ __('A one-time login code will be sent to your registered email address.') }}
                        @elseif ($smsEnabled)
                            {{ __('A one-time login code will be sent to your registered mobile number.') }}
                        @endif
                    </p>
                </div>

                @if (! $smsEnabled)
                    <div class="flex items-center gap-2 rounded-md bg-amber-500/10 border border-amber-500/20 p-2.5 text-[11px] text-amber-600">
                        <x-icon name="info" class="h-3.5 w-3.5 shrink-0"/>
                        <span>{{ __('SMS Gateway is currently offline. Please use your email address to receive login OTP.') }}</span>
                    </div>
                @endif

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer">
                        <input type="checkbox" x-model="remember" class="rounded border-input text-primary focus:ring-primary h-4 w-4"/>
                        <span>{{ __('Remember this device') }}</span>
                    </label>
                </div>

                <button
                    type="button"
                    x-on:click="sendOtp()"
                    :disabled="loading"
                    class="flex w-full h-[46px] items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer disabled:opacity-50"
                >
                    <span x-show="! loading">{{ __('Send Login Code') }}</span>
                    <span x-show="loading" x-cloak class="flex items-center gap-2">
                        <x-icon name="refresh-cw" class="h-4 w-4 animate-spin"/>
                        {{ __('Sending OTP...') }}
                    </span>
                </button>
            </div>

            {{-- Step 2: Enter & Verify OTP --}}
            <div x-show="step === 'verify'" x-cloak class="space-y-4">
                <div class="rounded-xl border border-border bg-secondary/20 p-4 text-center">
                    <p class="text-xs text-muted-foreground">{{ __('Code sent to:') }}</p>
                    <p class="text-sm font-semibold text-foreground mt-0.5" x-text="identifier"></p>
                    <button
                        type="button"
                        x-on:click="step = 'request'; errorMessage = ''; statusMessage = ''"
                        class="text-[11px] text-emerald-500 hover:underline mt-1 cursor-pointer"
                    >
                        {{ __('Change number or email') }}
                    </button>
                </div>

                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground text-center block">
                        {{ __('Enter Verification Code') }}
                    </label>
                    <input
                        type="text"
                        x-model="otpCode"
                        maxlength="8"
                        placeholder="• • • • • •"
                        class="flex h-12 w-full text-center tracking-[8px] text-lg font-mono font-bold rounded-lg border border-input bg-transparent px-4 outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        x-on:keydown.enter.prevent="verifyOtp()"
                        autofocus
                    />
                </div>

                <template x-if="plainOtp">
                    <div class="p-2 rounded bg-emerald-500/10 border border-emerald-500/20 text-center text-xs text-emerald-600 font-mono">
                        {{ __('Dev Mode OTP:') }} <strong x-text="plainOtp"></strong>
                    </div>
                </template>

                <button
                    type="button"
                    x-on:click="verifyOtp()"
                    :disabled="loading"
                    class="flex w-full h-[46px] items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer disabled:opacity-50"
                >
                    <span x-show="! loading">{{ __('Verify & Sign in') }}</span>
                    <span x-show="loading" x-cloak class="flex items-center gap-2">
                        <x-icon name="refresh-cw" class="h-4 w-4 animate-spin"/>
                        {{ __('Verifying...') }}
                    </span>
                </button>

                <div class="text-center text-xs text-muted-foreground pt-1">
                    <template x-if="countdown > 0">
                        <span>{{ __('Resend code in') }} <span class="font-semibold text-foreground tabular-nums" x-text="countdown"></span>s</span>
                    </template>
                    <template x-if="countdown === 0">
                        <button
                            type="button"
                            x-on:click="sendOtp()"
                            class="text-emerald-500 hover:underline font-medium cursor-pointer"
                        >
                            {{ __('Resend Code') }}
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <p class="mt-6 text-center text-sm text-muted-foreground">
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
