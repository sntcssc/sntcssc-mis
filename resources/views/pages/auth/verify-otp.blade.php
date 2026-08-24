@php
    $targetType = request()->query('type', 'phone');
    $user = auth()->user();
    $targetIdentifier = $targetType === 'email' ? ($user?->email ?? request()->query('identifier', '')) : ($user?->phone ?? request()->query('identifier', ''));
    $smsEnabled = \App\Services\SmsService::isEnabled();
@endphp

<x-layouts::auth :title="__('Verify OTP')">
    <div
        class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm"
        x-data="{
            targetType: '{{ $targetType }}',
            identifier: '{{ $targetIdentifier }}',
            otpCode: '',
            verified: false,
            loading: false,
            resending: false,
            countdown: 60,
            timer: null,
            errorMessage: '',
            statusMessage: '{{ session('status') }}',
            plainOtp: '',

            init() {
                this.startCountdown(60);
                if (! this.statusMessage) {
                    this.sendOtp();
                }
            },

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
                if (this.resending) return;
                this.resending = true;
                this.errorMessage = '';

                try {
                    const response = await fetch('{{ route('verify-otp.send') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            type: this.targetType,
                            identifier: this.identifier
                        })
                    });

                    const data = await response.json();

                    if (! response.ok) {
                        this.errorMessage = data.message || (data.errors ? Object.values(data.errors)[0][0] : '{{ __('Failed to send verification code.') }}');
                    } else {
                        this.statusMessage = data.message;
                        this.plainOtp = data.plain_code || '';
                        this.startCountdown(data.cooldown_seconds || 60);
                    }
                } catch (e) {
                    this.errorMessage = '{{ __('Network error while sending code.') }}';
                } finally {
                    this.resending = false;
                }
            },

            async verifyOtp() {
                if (! this.otpCode || this.otpCode.length < 4) {
                    this.errorMessage = '{{ __('Please enter the complete verification code.') }}';
                    return;
                }

                this.loading = true;
                this.errorMessage = '';

                try {
                    const response = await fetch('{{ route('verify-otp.verify') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            type: this.targetType,
                            identifier: this.identifier,
                            code: this.otpCode
                        })
                    });

                    const data = await response.json();

                    if (! response.ok) {
                        this.errorMessage = data.message || (data.errors ? Object.values(data.errors)[0][0] : '{{ __('Verification failed.') }}');
                    } else {
                        this.verified = true;
                        setTimeout(() => {
                            window.location.href = data.redirect_url || '{{ route('home') }}';
                        }, 1200);
                    }
                } catch (e) {
                    this.errorMessage = '{{ __('Network error during verification.') }}';
                } finally {
                    this.loading = false;
                }
            }
        }"
    >
        <div class="flex flex-col items-center mb-6">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-500/20 mb-3">
                <x-icon name="shield-check" class="h-6 w-6 text-emerald-500" x-show="! verified"/>
                <x-icon name="check-circle-2" class="h-6 w-6 text-emerald-500" x-show="verified" x-cloak/>
            </div>
            <h1 class="text-xl font-semibold">
                <span x-show="! verified">
                    {{ $targetType === 'email' ? __('Verify your email') : __('Verify your mobile number') }}
                </span>
                <span x-show="verified" x-cloak>{{ __('Verification Complete') }}</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">
                <span x-show="! verified">
                    {{ __('We have sent a verification code to :target.', ['target' => $targetIdentifier ?: ($targetType === 'email' ? __('your email') : __('your phone'))]) }}
                </span>
                <span x-show="verified" x-cloak>{{ __('Your account is verified! Redirecting to dashboard...') }}</span>
            </p>
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

        <div x-show="! verified">
            <form class="space-y-5" x-on:submit.prevent="verifyOtp()">
                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground text-center block">
                        {{ __('Enter 6-Digit OTP') }}
                    </label>
                    <input
                        type="text"
                        x-model="otpCode"
                        maxlength="8"
                        placeholder="• • • • • •"
                        class="flex h-12 w-full text-center tracking-[8px] text-lg font-mono font-bold rounded-lg border border-input bg-transparent px-4 outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        autofocus
                    />
                </div>

                <template x-if="plainOtp">
                    <div class="p-2 rounded bg-emerald-500/10 border border-emerald-500/20 text-center text-xs text-emerald-600 font-mono">
                        {{ __('Dev OTP:') }} <strong x-text="plainOtp"></strong>
                    </div>
                </template>

                <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold cursor-pointer" ::disabled="loading">
                    <span x-show="! loading">{{ __('Verify & Proceed') }}</span>
                    <span x-show="loading" x-cloak class="flex items-center gap-2">
                        <x-icon name="refresh-cw" class="h-4 w-4 animate-spin"/>
                        {{ __('Verifying...') }}
                    </span>
                </x-ui.button>
            </form>

            <div class="mt-5 text-center text-xs text-muted-foreground">
                <template x-if="countdown > 0">
                    <span>{{ __('Resend code in') }} <span class="font-medium text-foreground tabular-nums" x-text="countdown"></span> {{ __('seconds') }}</span>
                </template>
                <template x-if="countdown === 0">
                    <button type="button" x-on:click="sendOtp()" :disabled="resending" class="text-emerald-500 hover:underline font-medium cursor-pointer">
                        <span x-show="! resending">{{ __('Resend code') }}</span>
                        <span x-show="resending" x-cloak>{{ __('Sending...') }}</span>
                    </button>
                </template>
            </div>
        </div>

        <div x-show="verified" x-cloak class="flex flex-col items-center gap-4">
            <div class="flex flex-col items-center gap-3 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-6 text-center w-full">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/15">
                    <x-icon name="check-circle-2" class="h-6 w-6 text-emerald-500"/>
                </span>
                <p class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">{{ __('Verification successful') }}</p>
            </div>
        </div>

        <p class="mt-6 text-center text-sm text-muted-foreground">
            @if (auth()->check())
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="log-out" class="h-3.5 w-3.5"/>
                        {{ __('Sign out and return') }}
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" wire:navigate class="inline-flex items-center gap-1.5 text-emerald-500 hover:underline font-medium text-xs">
                    <x-icon name="arrow-left" class="h-3.5 w-3.5"/>
                    {{ __('Back to sign in') }}
                </a>
            @endif
        </p>
    </div>
</x-layouts::auth>
