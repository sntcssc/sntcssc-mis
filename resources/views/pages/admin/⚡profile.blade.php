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
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public string $phone = '';
    public string $whatsapp_no = '';
    public string $dob = '';
    public string $gender = '';
    public string $tenth_roll = '';
    public string $id_type = '';
    public string $id_number = '';
    public string $designation = '';

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
        $user = Auth::user();

        $this->name = $user->name ?? '';
        $this->first_name = $user->first_name ?? '';
        $this->last_name = $user->last_name ?? '';
        $this->email = $user->email ?? '';
        $this->phone = $user->phone ?? '';
        $this->whatsapp_no = $user->whatsapp_no ?? '';
        $this->dob = $user->dob ? (is_string($user->dob) ? substr($user->dob, 0, 10) : $user->dob->format('Y-m-d')) : '';
        $this->gender = $user->gender ?? '';
        $this->tenth_roll = $user->tenth_roll ?? '';
        $this->id_type = $user->id_type ?? '';
        $this->id_number = $user->id_number ?? '';
        $this->designation = $user->designation ?? '';

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

        try {
            $validated = $this->validate($this->profileRules($user->id), [
                'name.required' => __('Full name is required.'),
                'email.required' => __('Email address is required.'),
                'email.email' => __('Please enter a valid email address.'),
                'email.unique' => __('This email address is already in use.'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Toast::dispatch($this, 'error', __('Please fill in all required fields properly.'));

            throw $e;
        }

        if (! empty($validated['name'])) {
            $expectedCombined = trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''));
            if ($validated['name'] !== $expectedCombined) {
                $parts = explode(' ', trim($validated['name']), 2);
                $validated['first_name'] = $parts[0] ?? '';
                $validated['last_name'] = $parts[1] ?? '';
            }
        }

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($user->isDirty('phone')) {
            $user->phone_verified_at = null;
        }

        $user->save();
        $this->name = $user->name;

        Toast::dispatch($this, 'success', __('Profile updated successfully.'));
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
            ], [
                'current_password.required' => __('Current password is required.'),
                'password.required' => __('New password is required.'),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');
            Toast::dispatch($this, 'error', __('Please fill in all required fields properly.'));

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Toast::dispatch($this, 'success', __('Password updated successfully.'));
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
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Profile & Preferences') }}</h1>
            <p class="text-sm text-muted-foreground mt-1">{{ __('Manage your personal, institutional, security credentials and display preferences.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5" href="{{ route('admin.user-activity.index') }}" wire:navigate>
                <x-icon name="activity" class="h-3.5 w-3.5"/>
                {{ __('My Activity') }}
            </x-ui.button>
            <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5" href="{{ route('security.edit') }}" wire:navigate>
                <x-icon name="lock" class="h-3.5 w-3.5"/>
                {{ __('Security settings') }}
            </x-ui.button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 sm:gap-6">
        {{-- Left: User Profile & Institutional Dossier Card --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-border bg-card p-5 shadow-xs">
                <div class="flex flex-col items-center text-center py-2">
                    <div
                        x-data="{ isUploading: false, progress: 0 }"
                        x-on:livewire-upload-start="isUploading = true; progress = 0"
                        x-on:livewire-upload-finish="isUploading = false"
                        x-on:livewire-upload-error="isUploading = false"
                        x-on:livewire-upload-progress="progress = $event.detail.progress"
                        class="relative group"
                    >
                        <div class="relative overflow-hidden rounded-full ring-2 ring-primary/20 shadow-md">
                            @if ($avatarFile && method_exists($avatarFile, 'temporaryUrl'))
                                <img src="{{ $avatarFile->temporaryUrl() }}" alt="{{ $name }}" class="size-24 rounded-full object-cover"/>
                            @else
                                <x-ui.avatar :name="$name" :initials="auth()->user()->initials()" :src="auth()->user()->avatarUrl()" size="size-24 text-3xl"/>
                            @endif

                            {{-- Prominent Upload spinner & percentage --}}
                            <div
                                x-show="isUploading"
                                x-cloak
                                class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/90 backdrop-blur-xs rounded-full p-1 text-center"
                            >
                                <x-icon name="refresh-cw" class="h-6 w-6 animate-spin text-primary shrink-0"/>
                                <span class="text-[10px] font-bold font-mono text-primary mt-1" x-text="`${progress}%`"></span>
                            </div>

                            <div wire:loading wire:target="avatarFile" class="absolute inset-0 z-10 flex items-center justify-center bg-background/80 backdrop-blur-xs rounded-full">
                                <x-icon name="refresh-cw" class="h-6 w-6 animate-spin text-primary"/>
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
                            class="absolute -bottom-1 -right-1 flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md hover:bg-primary/90 cursor-pointer transition-transform hover:scale-105"
                            title="{{ __('Change profile photo') }}"
                        >
                            <x-icon name="camera" class="h-4 w-4"/>
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

                    <h2 class="mt-3.5 text-base font-bold text-foreground">{{ $name }}</h2>
                    @if ($designation)
                        <p class="text-xs font-medium text-primary mt-0.5">{{ $designation }}</p>
                    @endif
                    <p class="text-xs text-muted-foreground mt-0.5">{{ $email }}</p>

                    <div class="flex flex-wrap items-center justify-center gap-1.5 mt-3">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 capitalize">
                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                            {{ auth()->user()->status ?? 'active' }}
                        </span>

                        @if (auth()->user()->email_verified_at)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-primary/10 text-primary">
                                <x-icon name="check-circle-2" class="h-3 w-3"/>
                                {{ __('Email Verified') }}
                            </span>
                        @endif

                        @if (auth()->user()->hasVerifiedPhone())
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400">
                                <x-icon name="phone" class="h-3 w-3"/>
                                {{ __('Phone Verified') }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Institutional Details Table --}}
                <div class="border-t border-border mt-4 pt-4 space-y-2.5 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('URN') }}</span>
                        <span class="font-mono font-bold text-foreground bg-muted px-2 py-0.5 rounded">{{ auth()->user()->urn ?? '—' }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('UUID') }}</span>
                        <span class="font-mono text-[10px] text-muted-foreground truncate max-w-[150px]" title="{{ auth()->user()->uuid }}">{{ auth()->user()->uuid ?? '—' }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Role(s)') }}</span>
                        <div class="flex flex-wrap gap-1 justify-end max-w-[170px]">
                            @forelse (auth()->user()->roles as $role)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-primary/10 text-primary">{{ $role->name }}</span>
                            @empty
                                <span class="font-medium text-muted-foreground">{{ __('General User') }}</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Direct Permissions') }}</span>
                        <span class="font-semibold text-foreground">{{ auth()->user()->permissions->count() }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Last Login') }}</span>
                        <span class="font-medium text-foreground">{{ auth()->user()->last_login_at ? auth()->user()->last_login_at->diffForHumans() : __('Never') }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Last Login IP') }}</span>
                        <span class="font-mono text-[11px] text-muted-foreground">{{ auth()->user()->last_login_ip ?? '—' }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Member Since') }}</span>
                        <span class="font-medium text-foreground">{{ auth()->user()->created_at->format('d M Y') }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Current Team') }}</span>
                        <span class="font-medium truncate max-w-[150px] text-foreground">{{ auth()->user()->currentTeam?->name ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- Active Session Card --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Active Session Security') }}</h3>
                <div class="mt-3 space-y-2">
                    <div class="flex items-center gap-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2.5">
                        <x-icon name="monitor" class="h-4 w-4 text-emerald-500 shrink-0"/>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium truncate">{{ __('This device') }} · {{ request()->userAgent() ? \Illuminate\Support\Str::before(request()->userAgent(), '(') : 'Browser' }}</p>
                            <p class="text-[10px] text-muted-foreground font-mono">{{ request()->ip() }} · {{ __('Current session') }}</p>
                        </div>
                        <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: Profile Edit Forms --}}
        <div class="xl:col-span-2 space-y-4 sm:space-y-6">
            {{-- Personal & Contact Information Card --}}
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs">
                <div class="flex items-center gap-2 pb-3 border-b border-border">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon name="user" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('Personal & Contact Details') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Update your personal information, name, contact and identity information.') }}</p>
                    </div>
                </div>

                <form wire:submit="updateProfileInformation" class="mt-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.input
                            wire:model="first_name"
                            :label="__('First Name')"
                            type="text"
                            placeholder="e.g. Rahul"
                            :error="$errors->first('first_name')"
                        />

                        <x-ui.input
                            wire:model="last_name"
                            :label="__('Last Name')"
                            type="text"
                            placeholder="e.g. Sharma"
                            :error="$errors->first('last_name')"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.input
                            wire:model="name"
                            :label="__('Full Display Name') .' *'"
                            type="text"
                            required
                            autocomplete="name"
                            :error="$errors->first('name')"
                        />

                        <x-ui.input
                            wire:model="email"
                            :label="__('Email Address') .' *'"
                            type="email"
                            required
                            autocomplete="email"
                            :error="$errors->first('email')"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.input
                            wire:model="phone"
                            :label="__('Mobile Number')"
                            type="tel"
                            placeholder="+91 98765 43210"
                            :error="$errors->first('phone')"
                        />

                        <x-ui.input
                            wire:model="whatsapp_no"
                            :label="__('WhatsApp Number')"
                            type="tel"
                            placeholder="+91 98765 43210"
                            :error="$errors->first('whatsapp_no')"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.input
                            wire:model="dob"
                            :label="__('Date of Birth')"
                            type="date"
                            :error="$errors->first('dob')"
                        />

                        <x-ui.select
                            wire:model="gender"
                            :label="__('Gender')"
                            :options="[
                                '' => __('Select Gender'),
                                'male' => __('Male'),
                                'female' => __('Female'),
                                'other' => __('Other'),
                            ]"
                            :error="$errors->first('gender')"
                        />
                    </div>

                    {{-- Identity & Academic Details --}}
                    <div class="pt-4 border-t border-border">
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ __('Identity & Academic Details') }}</h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-ui.input
                                wire:model="designation"
                                :label="__('Designation / Title')"
                                type="text"
                                placeholder="e.g. Senior Faculty / Admissions Officer"
                                :error="$errors->first('designation')"
                            />

                            <x-ui.input
                                wire:model="tenth_roll"
                                :label="__('10th Board Roll No.')"
                                type="text"
                                placeholder="e.g. WB-10-883210"
                                :error="$errors->first('tenth_roll')"
                            />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                            <x-ui.select
                                wire:model="id_type"
                                :label="__('Identity Document Type')"
                                :options="[
                                    '' => __('Select Document Type'),
                                    'aadhaar' => __('Aadhaar Card'),
                                    'pan' => __('PAN Card'),
                                    'voter_id' => __('Voter ID Card'),
                                    'passport' => __('Passport'),
                                    'driving_license' => __('Driving License'),
                                ]"
                                :error="$errors->first('id_type')"
                            />

                            <x-ui.input
                                wire:model="id_number"
                                :label="__('Document / ID Number')"
                                type="text"
                                placeholder="e.g. 1234-5678-9012"
                                :error="$errors->first('id_number')"
                            />
                        </div>
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

                    <div class="flex justify-end pt-2">
                        <x-ui.button type="submit" data-test="update-profile-button">{{ __('Save Profile Details') }}</x-ui.button>
                    </div>
                </form>
            </div>

            {{-- Change Password Card --}}
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs">
                <div class="flex items-center gap-2 pb-3 border-b border-border">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-500">
                        <x-icon name="key" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('Change Password') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Ensure your account uses a strong, random password to stay secure.') }}</p>
                    </div>
                </div>

                <form wire:submit="updatePassword" class="mt-4 space-y-4">
                    <x-ui.password
                        wire:model="current_password"
                        :label="__('Current password') .' *'"
                        required
                        autocomplete="current-password"
                        :error="$errors->first('current_password')"
                    />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.password
                            wire:model="password"
                            :label="__('New password') .' *'"
                            required
                            autocomplete="new-password"
                            :error="$errors->first('password')"
                        />
                        <x-ui.password
                            wire:model="password_confirmation"
                            :label="__('Confirm password') .' *'"
                            required
                            autocomplete="new-password"
                        />
                    </div>

                    <div class="flex justify-end pt-2">
                        <x-ui.button type="submit">{{ __('Update Password') }}</x-ui.button>
                    </div>
                </form>
            </div>

            {{-- Preferences Card --}}
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs">
                <div class="flex items-center gap-2 pb-3 border-b border-border">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon name="sliders" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('System Preferences') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Interface language, timezone, and display formats synchronized with your session.') }}</p>
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

                    <div class="flex justify-end pt-2">
                        <x-ui.button type="submit">{{ __('Save Preferences') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
