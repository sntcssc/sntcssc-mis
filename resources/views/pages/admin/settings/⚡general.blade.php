<?php

use App\Models\Setting;
use App\Services\FileUploadService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('General Settings')] class extends Component {
    use WithFileUploads;

    public array $form = [
        'site_name' => 'SNT CSSC',
        'site_tagline' => 'Shaping future civil servants',
        'site_description' => '',
        'app_name' => 'SNT CSSC MIS',
        'title' => 'SNT CSSC MIS',
        'site_logo' => '',
        'site_favicon' => '',
        'site_campus' => '',
        'site_email' => '',
        'site_mobile' => '',
        'site_phone' => '',
        'site_address' => '',
        'site_timing' => '',
        'site_open_days' => '',
        'copyright_text' => '© :year SNT CSSC. All rights reserved.',
    ];

    public $logoFile = null;
    public $faviconFile = null;

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'general')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "general.{$key}";
            if (isset($settings[$fullKey])) {
                $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.site_name' => ['required', 'string', 'max:255'],
            'form.site_tagline' => ['nullable', 'string', 'max:255'],
            'form.site_description' => ['nullable', 'string', 'max:1000'],
            'form.app_name' => ['required', 'string', 'max:255'],
            'form.title' => ['nullable', 'string', 'max:255'],
            'form.site_campus' => ['nullable', 'string', 'max:255'],
            'form.site_email' => ['nullable', 'email', 'max:255'],
            'form.site_mobile' => ['nullable', 'string', 'max:50'],
            'form.site_phone' => ['nullable', 'string', 'max:50'],
            'form.site_address' => ['nullable', 'string', 'max:500'],
            'form.site_timing' => ['nullable', 'string', 'max:100'],
            'form.site_open_days' => ['nullable', 'string', 'max:100'],
            'form.copyright_text' => ['nullable', 'string', 'max:255'],
            'logoFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:5120'],
            'faviconFile' => ['nullable', 'image', 'mimes:png,ico,svg,jpg,jpeg', 'max:2048'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                if ($this->logoFile) {
                    $this->form['site_logo'] = FileUploadService::store(
                        file: $this->logoFile,
                        folder: 'settings/general',
                        prefix: 'site_logo',
                        oldPath: $this->form['site_logo'] ?: null
                    );
                    $this->logoFile = null;
                }

                if ($this->faviconFile) {
                    $this->form['site_favicon'] = FileUploadService::store(
                        file: $this->faviconFile,
                        folder: 'settings/general',
                        prefix: 'site_favicon',
                        oldPath: $this->form['site_favicon'] ?: null
                    );
                    $this->faviconFile = null;
                }

                foreach ($this->form as $key => $value) {
                    $fullKey = "general.{$key}";
                    $type = in_array($key, ['site_logo', 'site_favicon'], true)
                        ? Setting::TYPE_IMAGE
                        : (in_array($key, ['site_description', 'site_address'], true) ? Setting::TYPE_TEXT : Setting::TYPE_STRING);

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'general';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));
                    $setting->value = $value;
                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();
            });

            Toast::dispatch($this, 'success', __('General settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save general settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save settings. Please try again.'));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    {{-- Top Settings Navigation --}}
    <x-settings-nav active="general"/>

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('General Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Configure essential site identity, branding, contact and institutional details.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Institution & Website Info --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="building-2" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Website & Institution Information') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Basic public profile and titles shown in browsers and headers.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input
                    wire:model="form.site_name"
                    :label="__('Site Name') . ' *'"
                    placeholder="SNT CSSC"
                    required
                />

                <x-ui.input
                    wire:model="form.app_name"
                    :label="__('Application Name') . ' *'"
                    placeholder="SNT CSSC MIS"
                    required
                />

                <div class="sm:col-span-2">
                    <x-ui.input
                        wire:model="form.site_tagline"
                        :label="__('Site Tagline')"
                        placeholder="Shaping future civil servants"
                    />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.textarea
                        wire:model="form.site_description"
                        :label="__('Site Description')"
                        rows="2"
                        placeholder="Brief overview of the institution..."
                    />
                </div>

                <x-ui.input
                    wire:model="form.title"
                    :label="__('Page Title Suffix')"
                    placeholder="SNT CSSC MIS"
                    hint="{{ __('Appended to browser tab titles.') }}"
                />

                <x-ui.input
                    wire:model="form.site_campus"
                    :label="__('Campus Name')"
                    placeholder="Main Campus, Kolkata"
                />
            </div>
        </div>

        {{-- Section 2: Branding & Logos --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="images" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Branding Assets & Logo Upload') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Upload logos with timestamped storage and live preview.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <x-ui.file-upload
                    wire:model="logoFile"
                    :file="$logoFile"
                    :value="$form['site_logo']"
                    :label="__('Site Logo')"
                    type="image"
                    maxSize="5MB"
                    folder="settings/general"
                    hint="{{ __('Recommended: Transparent PNG or SVG (approx. 240x60px).') }}"
                />

                <x-ui.file-upload
                    wire:model="faviconFile"
                    :file="$faviconFile"
                    :value="$form['site_favicon']"
                    :label="__('Site Favicon')"
                    type="image"
                    accept="image/png,image/x-icon,image/svg+xml"
                    maxSize="2MB"
                    folder="settings/general"
                    hint="{{ __('Square icon for browser tabs (32x32 or 64x64px).') }}"
                />
            </div>
        </div>

        {{-- Section 3: Contact & Address --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="phone" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Contact Details & Location') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Institutional contact channels and physical address.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.input
                    wire:model="form.site_email"
                    :label="__('Contact Email')"
                    type="email"
                    placeholder="info@sntcssc.in"
                    icon="mail"
                />

                <x-ui.input
                    wire:model="form.site_mobile"
                    :label="__('Mobile Number')"
                    placeholder="+91 90000 00000"
                    icon="smartphone"
                />

                <x-ui.input
                    wire:model="form.site_phone"
                    :label="__('Landline Phone')"
                    placeholder="033 0000 0000"
                    icon="phone"
                />

                <div class="sm:col-span-3">
                    <x-ui.textarea
                        wire:model="form.site_address"
                        :label="__('Physical Address')"
                        rows="2"
                        placeholder="Campus address..."
                    />
                </div>
            </div>
        </div>

        {{-- Section 4: Operational Hours & Legal --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="clock" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Office Hours & Footer Info') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Working schedule and footer copyright line.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input
                    wire:model="form.site_timing"
                    :label="__('Office Timing')"
                    placeholder="10:00 AM – 6:00 PM"
                    icon="clock"
                />

                <x-ui.input
                    wire:model="form.site_open_days"
                    :label="__('Open Days')"
                    placeholder="Monday – Saturday"
                    icon="calendar"
                />

                <div class="sm:col-span-2">
                    <x-ui.input
                        wire:model="form.copyright_text"
                        :label="__('Copyright Notice')"
                        placeholder="© :year SNT CSSC. All rights reserved."
                        hint="{{ __('Use :year for automatic dynamic year replacement.') }}"
                    />
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-end gap-3 pt-2">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    </form>
</div>
