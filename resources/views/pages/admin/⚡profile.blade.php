<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Setting;
use App\Services\FileUploadService;
use App\Support\Toast;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Profile & Preferences')] class extends Component {
    use PasswordValidationRules;
    use ProfileValidationRules;
    use WithFileUploads;

    public string $name = '';
    public string $email = '';

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public string $language = 'en';
    public string $timezone = 'Asia/Kolkata';
    public string $date_format = 'd M Y';
    public string $time_format = 'h:i A';

    public $avatarFile = null;

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;

        $this->language = (string) (session('locale') ?? Setting::get('localization.language', 'en'));
        $this->timezone = (string) Setting::get('localization.timezone', 'Asia/Kolkata');
        $this->date_format = (string) Setting::get('localization.date_format', 'd M Y');
        $this->time_format = (string) Setting::get('localization.time_format', 'h:i A');
    }

    public function updatedAvatarFile(): void
    {
        $this->validate([
            'avatarFile' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $user = Auth::user();
        $user->avatar = FileUploadService::store(
            file: $this->avatarFile,
            folder: 'avatars',
            prefix: 'user_avatar',
            oldPath: $user->avatar ?: null
        );
        $user->save();

        $this->avatarFile = null;
        Toast::dispatch($this, 'success', __('Profile photo updated successfully.'));
    }

    public function removeAvatar(): void
    {
        $user = Auth::user();
        if ($user->avatar) {
            FileUploadService::delete($user->avatar);
            $user->avatar = null;
            $user->save();
        }

        $this->avatarFile = null;
        Toast::dispatch($this, 'info', __('Profile photo removed.'));
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
        $this->validate([
            'language' => ['required', 'string', 'in:en,hi,bn'],
            'timezone' => ['required', 'string', 'max:100'],
            'date_format' => ['required', 'string', 'max:50'],
            'time_format' => ['required', 'string', 'max:50'],
        ]);

        $userId = Auth::id();

        Setting::set('localization.language', $this->language, $userId);
        Setting::set('localization.timezone', $this->timezone, $userId);
        Setting::set('localization.date_format', $this->date_format, $userId);
        Setting::set('localization.time_format', $this->time_format, $userId);

        Setting::flushCache();

        session(['locale' => $this->language]);
        app()->setLocale($this->language);

        Toast::dispatch($this, 'success', __('Preferences saved and synchronized with system settings.'));
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
                    <div class="relative group">
                        <div class="relative overflow-hidden rounded-full ring-2 ring-primary/20 shadow-md">
                            @if ($avatarFile && method_exists($avatarFile, 'temporaryUrl'))
                                <img src="{{ $avatarFile->temporaryUrl() }}" alt="{{ $name }}" class="size-20 rounded-full object-cover"/>
                            @else
                                <x-ui.avatar :name="$name" :initials="auth()->user()->initials()" :src="auth()->user()->avatarUrl()" size="size-20 text-2xl"/>
                            @endif

                            {{-- Upload spinner --}}
                            <div wire:loading wire:target="avatarFile" class="absolute inset-0 flex items-center justify-center bg-background/80 backdrop-blur-xs rounded-full">
                                <x-icon name="refresh-cw" class="h-5 w-5 animate-spin text-primary"/>
                            </div>
                        </div>

                        {{-- Hidden file input --}}
                        <input
                            type="file"
                            id="admin-avatar-upload"
                            wire:model="avatarFile"
                            accept="image/png,image/jpeg,image/webp"
                            class="sr-only"
                        />

                        {{-- Camera button --}}
                        <label
                            for="admin-avatar-upload"
                            class="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md hover:bg-primary/90 cursor-pointer transition-transform hover:scale-105"
                            title="{{ __('Change profile photo') }}"
                        >
                            <x-icon name="camera" class="h-3.5 w-3.5"/>
                        </label>
                    </div>

                    @error('avatarFile')
                        <p class="mt-2 text-[11px] text-destructive text-center">{{ $message }}</p>
                    @enderror

                    @if (auth()->user()->avatar)
                        <button
                            type="button"
                            wire:click="removeAvatar"
                            class="mt-2 text-[11px] text-destructive hover:underline cursor-pointer flex items-center gap-1"
                        >
                            <x-icon name="trash-2" class="h-3 w-3"/>
                            {{ __('Remove photo') }}
                        </button>
                    @endif

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
                <div class="flex items-center gap-2 pb-3 border-b border-border">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon name="sliders" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('Preferences') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Interface language, timezone, and date/time formats synchronized with system settings.') }}</p>
                    </div>
                </div>

                <form wire:submit="savePreferences" class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.select
                            wire:model="language"
                            :label="__('Interface Language')"
                            :options="[
                                'en' => 'English',
                                'hi' => 'हिन्दी (Hindi)',
                                'bn' => 'বাংলা (Bengali)',
                            ]"
                        />

                        <x-ui.select
                            wire:model="timezone"
                            :label="__('Timezone')"
                            :options="[
                                'Asia/Kolkata' => 'Asia/Kolkata (IST +5:30)',
                                'Asia/Dhaka' => 'Asia/Dhaka (BST +6:00)',
                                'Asia/Dubai' => 'Asia/Dubai (GST +4:00)',
                                'UTC' => 'UTC (Greenwich +0:00)',
                                'America/New_York' => 'America/New_York (EST -5:00)',
                                'Europe/London' => 'Europe/London (GMT +0:00)',
                            ]"
                        />

                        <x-ui.select
                            wire:model="date_format"
                            :label="__('Date Display Format')"
                            :options="[
                                'd M Y' => '23 Aug 2026 (d M Y)',
                                'd/m/Y' => '23/08/2026 (d/m/Y)',
                                'Y-m-d' => '2026-08-23 (Y-m-d)',
                                'd-m-Y' => '23-08-2026 (d-m-Y)',
                                'jS F Y' => '23rd August 2026 (jS F Y)',
                            ]"
                        />

                        <x-ui.select
                            wire:model="time_format"
                            :label="__('Time Display Format')"
                            :options="[
                                'h:i A' => '05:30 PM (12-Hour)',
                                'H:i' => '17:30 (24-Hour)',
                            ]"
                        />
                    </div>

                    <div class="mt-4 flex justify-end">
                        <x-ui.button type="submit">{{ __('Save preferences') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
