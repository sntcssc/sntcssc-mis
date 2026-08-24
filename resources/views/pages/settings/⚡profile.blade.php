<?php

use App\Concerns\ProfileValidationRules;
use App\Models\Setting;
use App\Services\FileUploadService;
use App\Support\Toast;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Profile settings')] class extends Component {
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

    public string $language = 'en';
    public string $timezone = 'Asia/Kolkata';
    public string $date_format = 'd M Y';
    public string $time_format = 'h:i A';

    public $avatarFile = null;

    /**
     * Mount the component.
     */
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

    /**
     * Update the profile information for the currently authenticated user.
     */
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

        Toast::dispatch($this, 'success', __('Profile updated.'));
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

    /**
     * Send an email verification notification to the current user.
     */
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

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Profile settings') }}</h2>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your personal details, contact and institutional credentials')">
        {{-- Profile Avatar Section --}}
        <div class="rounded-xl border border-border bg-card p-5 mb-6 flex flex-col sm:flex-row items-center gap-5 shadow-xs">
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
                        <img src="{{ $avatarFile->temporaryUrl() }}" alt="{{ $name }}" class="size-20 rounded-full object-cover"/>
                    @else
                        <x-ui.avatar :name="$name" :initials="auth()->user()->initials()" :src="auth()->user()->avatarUrl()" size="size-20 text-2xl"/>
                    @endif

                    {{-- Prominent Upload spinner & percentage --}}
                    <div
                        x-show="isUploading"
                        x-cloak
                        class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/90 backdrop-blur-xs rounded-full p-1 text-center"
                    >
                        <x-icon name="refresh-cw" class="h-5 w-5 animate-spin text-primary shrink-0"/>
                        <span class="text-[10px] font-bold font-mono text-primary mt-1" x-text="`${progress}%`"></span>
                    </div>

                    <div wire:loading wire:target="avatarFile" class="absolute inset-0 z-10 flex items-center justify-center bg-background/80 backdrop-blur-xs rounded-full">
                        <x-icon name="refresh-cw" class="h-5 w-5 animate-spin text-primary"/>
                    </div>
                </div>

                <input
                    type="file"
                    id="settings-avatar-upload"
                    wire:model="avatarFile"
                    accept="image/png,image/jpeg,image/webp"
                    class="sr-only"
                />

                <label
                    for="settings-avatar-upload"
                    class="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md hover:bg-primary/90 cursor-pointer transition-transform hover:scale-105"
                    title="{{ __('Change profile photo') }}"
                >
                    <x-icon name="camera" class="h-3.5 w-3.5"/>
                </label>
            </div>

            <div class="space-y-1 text-center sm:text-left">
                <h3 class="text-sm font-semibold">{{ __('Profile Photo') }}</h3>
                <p class="text-xs text-muted-foreground">{{ __('JPG, PNG or WEBP up to 5MB. Real-time preview applied.') }}</p>
                <div class="flex items-center justify-center sm:justify-start gap-2 pt-1">
                    <label for="settings-avatar-upload" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline cursor-pointer">
                        <x-icon name="upload" class="h-3.5 w-3.5"/>
                        <span>{{ __('Upload new photo') }}</span>
                    </label>
                    @if (auth()->user()->avatar)
                        <span class="text-muted-foreground">·</span>
                        <button type="button" wire:click="removeAvatar" class="text-xs text-destructive hover:underline cursor-pointer flex items-center gap-1">
                            <x-icon name="trash-2" class="h-3 w-3"/>
                            <span>{{ __('Remove') }}</span>
                        </button>
                    @endif
                </div>
                @error('avatarFile')
                    <p class="text-[11px] text-destructive">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Profile Form --}}
        <form wire:submit="updateProfileInformation" class="space-y-5 rounded-xl border border-border bg-card p-5 shadow-xs">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input wire:model="first_name" :label="__('First Name')" type="text" placeholder="e.g. Rahul" :error="$errors->first('first_name')"/>
                <x-ui.input wire:model="last_name" :label="__('Last Name')" type="text" placeholder="e.g. Sharma" :error="$errors->first('last_name')"/>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input wire:model="name" :label="__('Full Display Name') .' *'" type="text" required autofocus autocomplete="name" :error="$errors->first('name')"/>
                <x-ui.input wire:model="email" :label="__('Email Address') .' *'" type="email" required autocomplete="email" :error="$errors->first('email')"/>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input wire:model="phone" :label="__('Mobile Number')" type="tel" placeholder="+91 98765 43210" :error="$errors->first('phone')"/>
                <x-ui.input wire:model="whatsapp_no" :label="__('WhatsApp Number')" type="tel" placeholder="+91 98765 43210" :error="$errors->first('whatsapp_no')"/>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input wire:model="dob" :label="__('Date of Birth')" type="date" :error="$errors->first('dob')"/>
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

            {{-- Identity & Academic Section --}}
            <div class="pt-4 border-t border-border">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ __('Identity & Academic Details') }}</h4>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input wire:model="designation" :label="__('Designation / Title')" type="text" placeholder="e.g. Professor / Admissions Officer" :error="$errors->first('designation')"/>
                    <x-ui.input wire:model="tenth_roll" :label="__('10th Board Roll No.')" type="text" placeholder="e.g. WB-10-883210" :error="$errors->first('tenth_roll')"/>
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

                    <x-ui.input wire:model="id_number" :label="__('Document / ID Number')" type="text" placeholder="e.g. 1234-5678-9012" :error="$errors->first('id_number')"/>
                </div>
            </div>

            @if ($this->hasUnverifiedEmail)
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

            <div class="flex items-center justify-end pt-2">
                <x-ui.button type="submit" data-test="update-profile-button">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>

        {{-- System Preferences Card --}}
        <form wire:submit="savePreferences" class="space-y-4 rounded-xl border border-border bg-card p-5 mt-6 shadow-xs">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="sliders" class="h-4 w-4"/>
                </div>
                <div>
                    <h3 class="text-sm font-semibold">{{ __('System Preferences') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ __('Interface language, timezone, and display formats.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.select
                    wire:model="language"
                    :label="__('Language')"
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
                    :label="__('Date Format')"
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
                    :label="__('Time Format')"
                    :options="[
                        'h:i A' => '05:30 PM (12-Hour)',
                        'H:i' => '17:30 (24-Hour)',
                    ]"
                />
            </div>

            <div class="flex items-center justify-end pt-2">
                <x-ui.button type="submit">
                    {{ __('Save preferences') }}
                </x-ui.button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <div class="mt-6">
                <livewire:pages::settings.delete-user-form />
            </div>
        @endif
    </x-pages::settings.layout>
</section>
