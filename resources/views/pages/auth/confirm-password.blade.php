<x-layouts::auth :title="__('Confirm password')">
    <div class="rounded-2xl border border-border bg-card p-8 sm:p-10 shadow-sm">
        <div class="flex flex-col items-center mb-6">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 mb-3">
                <x-icon name="lock" class="h-5 w-5 text-emerald-500"/>
            </div>
            <h1 class="text-xl font-semibold">{{ __('Confirm password') }}</h1>
            <p class="text-sm text-muted-foreground mt-1 text-center">
                {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
            </p>
        </div>

        <x-auth-session-status class="mb-5" :status="session('status')"/>

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
            @csrf

            <x-ui.password
                name="password"
                :label="__('Password')"
                required
                autocomplete="current-password"
                placeholder="{{ __('Password') }}"
                size="lg"
                :error="$errors->first('password')"
            />

            <x-ui.button type="submit" class="w-full h-[46px] rounded-lg text-sm font-semibold" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </x-ui.button>
        </form>
    </div>
</x-layouts::auth>
