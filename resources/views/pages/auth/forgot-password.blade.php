@php
    $smsEnabled = \App\Services\SmsService::isEnabled();
    $emailEnabled = \App\Services\EmailService::isEnabled();
@endphp

<x-layouts::auth :title="__('Forgot password')">
    <div
        class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm"
        x-data="{
            resetMethod: '{{ $smsEnabled ? 'otp' : 'email' }}', // 'otp' or 'email'
            identifier: '',
            otpCode: '',
            password: '',
            passwordConfirmation: '',
            step: 'request', // 'request' or 'verify'
            loading: false,
            countdown: 0,
            timer: null,
            errorMessage: '',
            statusMessage: '{{ session('status') }}',
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
                    this.errorMessage = '{{ __('Please enter your registered email or mobile number.') }}';
                    return;
                }

                this.loading = true;
                this.errorMessage = '';

                try {
                    const response = await fetch('{{ route('password.otp.send') }}', {
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
                        this.errorMessage = data.message || (data.errors ? Object.values(data.errors)[0][0] : '{{ __('Failed to dispatch reset code.') }}');
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

            async resetPassword() {
                if (! this.otpCode || ! this.password || ! this.passwordConfirmation) {
                    this.errorMessage = '{{ __('Please fill in all required fields.') }}';
                    return;
                }

                if (this.password !== this.passwordConfirmation) {
                    this.errorMessage = '{{ __('Password confirmation does not match.') }}';
                    return;
                }

                this.loading = true;
                this.errorMessage = '';

                try {
                    const response = await fetch('{{ route('password.otp.reset') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            identifier: this.identifier,
                            code: this.otpCode,
                            password: this.password,
                            password_confirmation: this.passwordConfirmation
                        })
                    });

                    const data = await response.json();

                    if (! response.ok) {
                        this.errorMessage = data.message || (data.errors ? Object.values(data.errors)[0][0] : '{{ __('Password reset failed.') }}');
                    } else {
                        window.location.href = data.redirect_url || '{{ route('login') }}';
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
            <h1 class="text-xl font-semibold">{{ __('Forgot password?') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">
                {{ __('Reset your account password quickly via verification code or email reset link.') }}
            </p>

            @if ($smsEnabled && $emailEnabled)
                {{-- Reset method tab switcher --}}
                <div class="flex gap-1 p-1 bg-secondary/50 rounded-lg mt-6 w-full">
                    <button
                        type="button"
                        x-on:click="resetMethod = 'otp'; step = 'request'; errorMessage = ''"
                        class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs font-medium transition-colors cursor-pointer"
                        :class="resetMethod === 'otp' ? 'bg-card text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="smartphone" class="h-4 w-4"/>
                        {{ __('SMS / Email OTP') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="resetMethod = 'email'; errorMessage = ''"
                        class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs font-medium transition-colors cursor-pointer"
                        :class="resetMethod === 'email' ? 'bg-card text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="mail" class="h-4 w-4"/>
                        {{ __('Email Reset Link') }}
                    </button>
                </div>
            @endif
        </div>

        {{-- Error Banner --}}
        <div x-show="errorMessage" x-cloak class="mb-5 flex items-center gap-2.5 rounded-lg border border-rose-500/20 bg-rose-500/5 px-4 py-3 text-xs text-rose-600 dark:text-rose-400">
            <x-icon name="alert-circle" class="h-4 w-4 shrink-0 text-rose-500"/>
            <span x-text="errorMessage"></span>
        </div>

        {{-- Status Banner --}}
        <div x-show="statusMessage" x-cloak class="mb-5 flex items-center gap-2.5 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-xs text-emerald-600 dark:text-emerald-400">
            <x-icon name="check-circle-2" class="h-4 w-4 shrink-0 text-emerald-500"/>
            <span x-text="statusMessage"></span>
        </div>

        {{-- Option 1: OTP Password Reset Flow --}}
        <div x-show="resetMethod === 'otp'">
            {{-- Step 1: Request OTP --}}
            <div x-show="step === 'request'" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Registered Email or Mobile Number') }} *
                    </label>
                    <input
                        type="text"
                        x-model="identifier"
                        placeholder="{{ $smsEnabled ? __('email@example.com or +91 98765 43210') : __('email@example.com') }}"
                        class="flex h-11 w-full rounded-lg border border-input bg-transparent px-4 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        x-on:keydown.enter.prevent="sendOtp()"
                        autofocus
                    />
                    <p class="text-xs text-muted-foreground">{{ __('We will dispatch a secure one-time code to verify your identity.') }}</p>
                </div>

                <button
                    type="button"
                    x-on:click="sendOtp()"
                    :disabled="loading"
                    class="flex w-full h-[46px] items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer disabled:opacity-50"
                >
                    <span x-show="! loading">{{ __('Send Reset Code') }}</span>
                    <span x-show="loading" x-cloak class="flex items-center gap-2">
                        <x-icon name="refresh-cw" class="h-4 w-4 animate-spin"/>
                        {{ __('Sending Code...') }}
                    </span>
                </button>
            </div>

            {{-- Step 2: Enter OTP & New Password --}}
            <div x-show="step === 'verify'" x-cloak class="space-y-4">
                <div class="rounded-xl border border-border bg-secondary/20 p-3 text-center">
                    <p class="text-xs text-muted-foreground">{{ __('Reset code sent to:') }} <strong class="text-foreground" x-text="identifier"></strong></p>
                    <button type="button" x-on:click="step = 'request'" class="text-[11px] text-emerald-500 hover:underline mt-0.5 cursor-pointer">
                        {{ __('Change recipient') }}
                    </button>
                </div>

                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Verification Code') }} *
                    </label>
                    <input
                        type="text"
                        x-model="otpCode"
                        maxlength="8"
                        placeholder="• • • • • •"
                        class="flex h-11 w-full text-center tracking-[6px] text-base font-mono font-bold rounded-lg border border-input bg-transparent px-4 outline-none focus-visible:border-ring"
                    />
                </div>

                <template x-if="plainOtp">
                    <div class="p-2 rounded bg-emerald-500/10 border border-emerald-500/20 text-center text-xs text-emerald-600 font-mono">
                        {{ __('Dev Mode OTP:') }} <strong x-text="plainOtp"></strong>
                    </div>
                </template>

                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('New Password') }} *
                    </label>
                    <input
                        type="password"
                        x-model="password"
                        placeholder="{{ __('New Password') }}"
                        class="flex h-11 w-full rounded-lg border border-input bg-transparent px-4 text-sm outline-none focus-visible:border-ring"
                    />
                </div>

                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Confirm New Password') }} *
                    </label>
                    <input
                        type="password"
                        x-model="passwordConfirmation"
                        placeholder="{{ __('Confirm New Password') }}"
                        class="flex h-11 w-full rounded-lg border border-input bg-transparent px-4 text-sm outline-none focus-visible:border-ring"
                        x-on:keydown.enter.prevent="resetPassword()"
                    />
                </div>

                <button
                    type="button"
                    x-on:click="resetPassword()"
                    :disabled="loading"
                    class="flex w-full h-[46px] items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer disabled:opacity-50"
                >
                    <span x-show="! loading">{{ __('Reset & Save Password') }}</span>
                    <span x-show="loading" x-cloak class="flex items-center gap-2">
                        <x-icon name="refresh-cw" class="h-4 w-4 animate-spin"/>
                        {{ __('Updating Password...') }}
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

        {{-- Option 2: Traditional Email Reset Link Flow --}}
        <div x-show="resetMethod === 'email'" x-cloak>
            <form id="resend-form" method="POST" action="{{ route('password.email') }}" class="space-y-5">
                @csrf

                <x-ui.input
                    name="email"
                    :label="__('Email address')"
                    type="email"
                    required
                    autofocus
                    placeholder="email@example.com"
                    size="lg"
                    :error="$errors->first('email')"
                />

                <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold cursor-pointer" data-test="email-password-reset-link-button">
                    {{ __('Send Reset Link') }}
                </x-ui.button>
            </form>
        </div>

        <p class="mt-6 text-center text-sm text-muted-foreground">
            <a href="{{ route('login') }}" wire:navigate class="inline-flex items-center gap-1.5 text-emerald-500 hover:underline font-medium">
                <x-icon name="arrow-left" class="h-3.5 w-3.5"/>
                {{ __('Back to sign in') }}
            </a>
        </p>
    </div>
</x-layouts::auth>
