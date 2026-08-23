<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Support\ThemePresets;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Appearance & Theme Settings')] class extends Component {
    use WithFileUploads;

    public array $form = [
        'theme_preset' => 'emerald',
        'primary_color' => '#059669',
        'primary_dark_color' => '#10b981',
        'radius' => '0.625rem',
        'dark_mode' => 'system',
        'sidebar_theme' => 'default',
        'font_family' => 'Inter',
        'logo' => '',
        'icon' => '',
        'custom_css' => '',
    ];

    public $logoFile = null;
    public $iconFile = null;

    public function mount(): void
    {
        $settings = Setting::query()->whereIn('group', ['appearance', 'general'])->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "appearance.{$key}";
            if (isset($settings[$fullKey])) {
                $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
            }
        }

        if (empty($this->form['logo']) && isset($settings['general.site_logo'])) {
            $this->form['logo'] = (string) ($settings['general.site_logo']->rawValue() ?? '');
        }

        if (empty($this->form['icon']) && isset($settings['general.site_favicon'])) {
            $this->form['icon'] = (string) ($settings['general.site_favicon']->rawValue() ?? '');
        }
    }

    #[Computed]
    public function presets(): array
    {
        return ThemePresets::all();
    }

    public function selectPreset(string $key): void
    {
        $preset = ThemePresets::get($key);
        if ($preset) {
            $this->form['theme_preset'] = $key;
            $this->form['primary_color'] = $preset['light']['primary'];
            $this->form['primary_dark_color'] = $preset['dark']['primary'];
            Toast::dispatch($this, 'info', __('Theme preset ":name" selected. Click Save to apply.', ['name' => $preset['name']]));
        }
    }

    public function applyPreset(string $key): void
    {
        $this->selectPreset($key);
        $this->save();
    }

    public function save(): void
    {
        $this->validate([
            'form.theme_preset' => ['required', 'string', 'max:50'],
            'form.primary_color' => ['required', 'string', 'max:50'],
            'form.primary_dark_color' => ['nullable', 'string', 'max:50'],
            'form.radius' => ['required', 'string', 'max:20'],
            'form.dark_mode' => ['required', 'string', 'in:system,light,dark'],
            'form.sidebar_theme' => ['required', 'string', 'in:default,dark,light'],
            'form.font_family' => ['required', 'string', 'max:50'],
            'form.custom_css' => ['nullable', 'string', 'max:10000'],
            'logoFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:5120'],
            'iconFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp,ico', 'max:2048'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                if ($this->logoFile) {
                    $this->form['logo'] = FileUploadService::store(
                        file: $this->logoFile,
                        folder: 'settings/appearance',
                        prefix: 'dashboard_logo',
                        oldPath: $this->form['logo'] ?: null
                    );
                    $this->logoFile = null;
                }

                if ($this->iconFile) {
                    $this->form['icon'] = FileUploadService::store(
                        file: $this->iconFile,
                        folder: 'settings/appearance',
                        prefix: 'dashboard_icon',
                        oldPath: $this->form['icon'] ?: null
                    );
                    $this->iconFile = null;
                }

                foreach ($this->form as $key => $value) {
                    $fullKey = "appearance.{$key}";
                    $type = in_array($key, ['logo', 'icon'], true)
                        ? Setting::TYPE_IMAGE
                        : ($key === 'custom_css' ? Setting::TYPE_TEXT : Setting::TYPE_STRING);

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'appearance';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));
                    $setting->value = $value;
                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                // Sync brand assets to general settings
                if (! empty($this->form['logo'])) {
                    Setting::set('general.site_logo', $this->form['logo'], $userId);
                }
                if (! empty($this->form['icon'])) {
                    Setting::set('general.site_favicon', $this->form['icon'], $userId);
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'theme_changed',
                    description: "Updated theme settings (Preset: {$this->form['theme_preset']}, Primary: {$this->form['primary_color']}).",
                    newValues: $this->form,
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('Appearance settings saved and applied across entire application.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save appearance settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save appearance settings.'));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="appearance"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Appearance & Theme Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Manage centralized application theme color presets, custom brand colors, dark mode, typography and assets.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save & Apply Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: 1-Click Color Combination Presets --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon name="palette" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('Color Combination Presets (1-Click Apply)') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('Select a pre-configured harmonious palette or customize individual colors below.') }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                @foreach ($this->presets as $key => $preset)
                    @php($isSelected = $form['theme_preset'] === $key)
                    <div
                        wire:click="selectPreset('{{ $key }}')"
                        class="relative rounded-xl border p-3.5 cursor-pointer transition-all hover:scale-[1.02] {{ $isSelected ? 'border-primary bg-primary/5 ring-2 ring-primary/20 shadow-xs' : 'border-border bg-card hover:bg-secondary/40' }}"
                    >
                        <div class="flex items-center justify-between gap-2 mb-2.5">
                            <span class="text-xs font-semibold {{ $isSelected ? 'text-primary' : 'text-foreground' }}">{{ $preset['name'] }}</span>
                            @if ($isSelected)
                                <span class="flex h-4 w-4 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                    <x-icon name="check" class="h-2.5 w-2.5" stroke-width="3"/>
                                </span>
                            @endif
                        </div>

                        {{-- Color Swatches Strip --}}
                        <div class="flex items-center gap-1.5 mb-2">
                            <span class="h-6 w-6 rounded-md shadow-xs shrink-0 border border-black/10" style="background-color: {{ $preset['light']['primary'] }}" title="Light Primary: {{ $preset['light']['primary'] }}"></span>
                            <span class="h-6 w-6 rounded-md shadow-xs shrink-0 border border-white/20" style="background-color: {{ $preset['dark']['primary'] }}" title="Dark Primary: {{ $preset['dark']['primary'] }}"></span>
                            @foreach (array_slice($preset['light']['chart'], 0, 3) as $chartColor)
                                <span class="h-6 w-3.5 rounded-sm shrink-0 opacity-80" style="background-color: {{ $chartColor }}"></span>
                            @endforeach
                        </div>

                        <p class="text-[10px] text-muted-foreground line-clamp-2 leading-relaxed">{{ $preset['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Section 2: Custom Color Palette & Mode Controls --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="sliders" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Custom Brand Colors & Appearance Modes') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Override specific colors, border curvature, default dark mode behavior and typography.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Primary Light Accent') }} *</label>
                    <div class="flex items-center gap-2.5 mt-2">
                        <input
                            type="color"
                            wire:model.live="form.primary_color"
                            class="h-9 w-12 rounded border border-input bg-transparent cursor-pointer p-0.5"
                        />
                        <x-ui.input
                            wire:model="form.primary_color"
                            class="flex-1 font-mono text-xs"
                            placeholder="#059669"
                        />
                    </div>
                </div>

                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Primary Dark Accent') }}</label>
                    <div class="flex items-center gap-2.5 mt-2">
                        <input
                            type="color"
                            wire:model.live="form.primary_dark_color"
                            class="h-9 w-12 rounded border border-input bg-transparent cursor-pointer p-0.5"
                        />
                        <x-ui.input
                            wire:model="form.primary_dark_color"
                            class="flex-1 font-mono text-xs"
                            placeholder="#10b981"
                        />
                    </div>
                </div>

                <x-ui.select
                    wire:model="form.radius"
                    :label="__('Component Curvature (Border Radius)') . ' *'"
                    :options="[
                        '0rem' => 'Sharp / Rectangular (0px)',
                        '0.375rem' => 'Subtle (6px)',
                        '0.625rem' => 'Default Balanced (10px)',
                        '0.75rem' => 'Smooth Rounded (12px)',
                        '1rem' => 'Pill Organic (16px)',
                    ]"
                />

                <x-ui.select
                    wire:model="form.dark_mode"
                    :label="__('Default Theme Mode') . ' *'"
                    :options="[
                        'system' => __('System Default (Automatic)'),
                        'light' => __('Light Theme'),
                        'dark' => __('Dark Theme'),
                    ]"
                />

                <x-ui.select
                    wire:model="form.sidebar_theme"
                    :label="__('Sidebar Style') . ' *'"
                    :options="[
                        'default' => __('Default Neutral'),
                        'dark' => __('Deep Slate Dark'),
                        'light' => __('Clean Light Bordered'),
                    ]"
                />

                <x-ui.select
                    wire:model="form.font_family"
                    :label="__('Application Font Family') . ' *'"
                    :options="[
                        'Geist' => 'Geist / Instrument Sans (Modern System)',
                        'Inter' => 'Inter (Modern Sans)',
                        'Plus Jakarta Sans' => 'Plus Jakarta Sans (Geometric)',
                        'Roboto' => 'Roboto (Clean & Readable)',
                        'system-ui' => 'System UI Native Sans',
                    ]"
                />
            </div>

            {{-- Live Sample Component Preview Box --}}
            <div class="mt-4 p-4 rounded-xl border border-border bg-secondary/15 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Interactive UI Live Preview') }}</span>
                    <span class="text-[10px] text-muted-foreground">{{ __('Preview uses your selected primary color') }}</span>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="h-9 px-4 rounded-md text-xs font-semibold text-white shadow-xs transition-opacity hover:opacity-90 cursor-pointer"
                        style="background-color: {{ $form['primary_color'] }};"
                    >
                        {{ __('Primary Action') }}
                    </button>

                    <button
                        type="button"
                        class="h-9 px-4 rounded-md text-xs font-semibold border transition-colors cursor-pointer"
                        style="border-color: {{ $form['primary_color'] }}; color: {{ $form['primary_color'] }};"
                    >
                        {{ __('Outline Button') }}
                    </button>

                    <span
                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium"
                        style="background-color: {{ $form['primary_color'] }}20; color: {{ $form['primary_color'] }};"
                    >
                        {{ __('Active Badge') }}
                    </span>

                    <div class="flex items-center gap-2 text-xs">
                        <span class="h-2 w-2 rounded-full" style="background-color: {{ $form['primary_color'] }};"></span>
                        <span class="font-medium text-foreground">{{ __('Live State Indicator') }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 3: Dashboard Branding & Assets --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="images" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Dashboard Branding & Logo Upload') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Brand imagery displayed in navigation headers, mobile drawer and browser tabs.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <x-ui.file-upload
                    wire:model="logoFile"
                    :file="$logoFile"
                    :value="$form['logo']"
                    :label="__('Admin Dashboard Header Logo')"
                    type="image"
                    maxSize="5MB"
                    folder="settings/appearance"
                    hint="{{ __('Horizontal logo for the admin topbar and navigation.') }}"
                />

                <x-ui.file-upload
                    wire:model="iconFile"
                    :file="$iconFile"
                    :value="$form['icon']"
                    :label="__('Dashboard Icon / Sidebar Mark')"
                    type="image"
                    maxSize="2MB"
                    folder="settings/appearance"
                    hint="{{ __('Square icon mark for collapsed sidebar and mobile header.') }}"
                />
            </div>
        </div>

        {{-- Section 4: Custom CSS Overrides --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="code" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Custom CSS Rules') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Advanced styling rules directly injected into all application layouts.') }}</p>
                </div>
            </div>

            <x-ui.textarea
                wire:model="form.custom_css"
                :label="__('Custom CSS')"
                rows="5"
                class="font-mono text-xs"
                placeholder="/* Custom CSS rules */&#10;:root {&#10;    --primary: #059669;&#10;}"
            />
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                {{ __('Save & Apply Changes') }}
            </x-ui.button>
        </div>
    </form>
</div>

