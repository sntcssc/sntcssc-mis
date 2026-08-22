<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Support\Toast;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Profile')] class extends Component {
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public string $language = 'en';
    public string $timezone = 'Asia/Kolkata';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Toast::dispatch($this, 'success', __('Profile updated.'));
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Toast::dispatch($this, 'success', __('Password updated.'));
    }

    public function savePreferences(): void
    {
        Toast::dispatch($this, 'success', __('Preferences saved (design preview).'));
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Profile & Preferences') }}</h1>
            <p class="text-sm text-muted-foreground mt-1">{{ __('Manage your account details and preferences.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" class="h-9" href="{{ route('security.edit') }}" wire:navigate>
                <x-icon name="lock" class="h-4 w-4"/>
                {{ __('Security settings') }}
            </x-ui.button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 sm:gap-4">
        {{-- Left: profile card --}}
        <div class="space-y-3 sm:space-y-4">
            <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
                <div class="flex flex-col items-center py-4">
                    <div class="relative">
                        <x-ui.avatar :name="$name" :initials="auth()->user()->initials()" size="size-20 text-2xl"/>
                        <button type="button" class="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 cursor-pointer transition-colors" title="{{ __('Upload photo (coming soon)') }}">
                            <x-icon name="pencil" class="h-3 w-3"/>
                        </button>
                    </div>
                    <h2 class="mt-3 text-base font-semibold">{{ $name }}</h2>
                    <p class="text-xs text-muted-foreground">{{ $email }}</p>
                    @if (auth()->user()->email_verified_at)
                        <x-ui.badge color="success" class="mt-2">
                            <x-icon name="check" class="h-3 w-3" stroke-width="3"/>
                            {{ __('Verified') }}
                        </x-ui.badge>
                    @endif
                </div>

                <div class="border-t border-border pt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Role') }}</span>
                        <span class="font-medium">{{ __('Administrator') }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Member since') }}</span>
                        <span class="font-medium">{{ auth()->user()->created_at->format('d M Y') }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Team') }}</span>
                        <span class="font-medium truncate max-w-[140px]">{{ auth()->user()->currentTeam?->name ?? '—' }}</span>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
                <h3 class="text-sm font-semibold">{{ __('Active sessions') }}</h3>
                <div class="mt-3 space-y-2">
                    <div class="flex items-center gap-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2.5">
                        <x-icon name="monitor" class="h-4 w-4 text-emerald-500"/>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium">{{ __('This device') }} · {{ request()->userAgent() ? \Illuminate\Support\Str::before(request()->userAgent(), '(') : 'Browser' }}</p>
                            <p class="text-[10px] text-muted-foreground">{{ __('Current session') }}</p>
                        </div>
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    </div>
                    <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5">
                        <x-icon name="smartphone" class="h-4 w-4 text-muted-foreground"/>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium">{{ __('Mobile · Chrome on Android') }}</p>
                            <p class="text-[10px] text-muted-foreground">{{ __('Last active 2 hours ago') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: forms --}}
        <div class="xl:col-span-2 space-y-3 sm:space-y-4">
            <div class="rounded-xl border border-border bg-card p-4 sm:p-6">
                <h3 class="text-sm font-semibold">{{ __('Personal details') }}</h3>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Update your name and email address.') }}</p>

                <form wire:submit="updateProfileInformation" class="mt-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.input wire:model="name" :label="__('Name') .' *'" type="text" required autofocus autocomplete="name"/>
                        <x-ui.input wire:model="email" :label="__('Email') .' *'" type="email" required autocomplete="email"/>
                    </div>

                    @if (auth()->user() instanceof MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                        <div class="rounded-lg border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-600 dark:text-amber-400">
                            {{ __('Your email address is unverified.') }}
                            <button type="button" wire:click.prevent="resendVerificationNotification" class="font-medium underline cursor-pointer">
                                {{ __('Click here to re-send the verification email.') }}
                            </button>

                            @if (session('status') === 'verification-link-sent')
                                <p class="mt-1 font-medium text-emerald-600 dark:text-emerald-400">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="flex justify-end">
                        <x-ui.button type="submit" data-test="update-profile-button">{{ __('Save changes') }}</x-ui.button>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 sm:p-6">
                <h3 class="text-sm font-semibold">{{ __('Change password') }}</h3>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Ensure your account uses a long, random password to stay secure.') }}</p>

                <form wire:submit="updatePassword" class="mt-5 space-y-4">
                    <x-ui.password wire:model="current_password" :label="__('Current password') .' *'" required autocomplete="current-password" :error="$errors->first('current_password')"/>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.password wire:model="password" :label="__('New password') .' *'" required autocomplete="new-password" :error="$errors->first('password')"/>
                        <x-ui.password wire:model="password_confirmation" :label="__('Confirm password') .' *'" required autocomplete="new-password"/>
                    </div>

                    <div class="flex justify-end">
                        <x-ui.button type="submit">{{ __('Update password') }}</x-ui.button>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 sm:p-6">
                <h3 class="text-sm font-semibold">{{ __('Preferences') }}</h3>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Interface language and timezone.') }}</p>

                <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Language') }}</label>
                        <x-ui.select wire:model="language" class="mt-2" :options="['en' => 'English', 'hi' => 'हिन्दी', 'bn' => 'বাংলা']"/>
                    </div>
                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Timezone') }}</label>
                        <x-ui.select wire:model="timezone" class="mt-2" :options="['Asia/Kolkata' => 'Asia/Kolkata (IST)', 'Asia/Dubai' => 'Asia/Dubai (GST)', 'UTC' => 'UTC']"/>
                    </div>
                </div>

                <div class="mt-4 flex justify-end">
                    <x-ui.button variant="outline" wire:click="savePreferences">{{ __('Save preferences') }}</x-ui.button>
                </div>
            </div>
        </div>
    </div>
</div>
