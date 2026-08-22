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

new #[Layout('layouts.app')] #[Title('Appearance Settings')] class extends Component {
    use WithFileUploads;

    public array $form = [
        'logo' => '',
        'icon' => '',
        'primary_color' => '#10b981',
        'dark_mode' => 'system',
        'sidebar_theme' => 'default',
        'font_family' => 'Inter',
        'custom_css' => '',
    ];

    public $logoFile = null;
    public $iconFile = null;

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'appearance')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "appearance.{$key}";
            if (isset($settings[$fullKey])) {
                $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.primary_color' => ['required', 'string', 'max:50'],
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

                Setting::flushCache();
            });

            Toast::dispatch($this, 'success', __('Appearance settings saved successfully.'));
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
            <p class="text-xs text-muted-foreground mt-1">{{ __('Customize dashboard branding, color palettes, dark mode defaults and typography.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Dashboard Branding --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="images" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Dashboard Assets & Icons') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Brand images rendered in the topbar and admin navigation.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <x-ui.file-upload
                    wire:model="logoFile"
                    :file="$logoFile"
                    :value="$form['logo']"
                    :label="__('Admin Dashboard Logo')"
                    type="image"
                    maxSize="5MB"
                    folder="settings/appearance"
                    hint="{{ __('Horizontal logo for the admin top navigation header.') }}"
                />

                <x-ui.file-upload
                    wire:model="iconFile"
                    :file="$iconFile"
                    :value="$form['icon']"
                    :label="__('Dashboard Icon / App Tile')"
                    type="image"
                    maxSize="2MB"
                    folder="settings/appearance"
                    hint="{{ __('Square icon mark for collapsed sidebar.') }}"
                />
            </div>
        </div>

        {{-- Section 2: Colors & Theme --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="palette" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Color & Display Mode') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Define brand accents, dark mode behavior and typography.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Primary Accent Color') }} *</label>
                    <div class="flex items-center gap-2.5 mt-2">
                        <input
                            type="color"
                            wire:model.live="form.primary_color"
                            class="h-9 w-12 rounded border border-input bg-transparent cursor-pointer p-0.5"
                        />
                        <x-ui.input
                            wire:model="form.primary_color"
                            class="flex-1 font-mono"
                            placeholder="#10b981"
                        />
                    </div>
                </div>

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

                <div class="sm:col-span-3">
                    <x-ui.select
                        wire:model="form.font_family"
                        :label="__('Application Font Family') . ' *'"
                        :options="[
                            'Inter' => 'Inter (Modern Sans)',
                            'Plus Jakarta Sans' => 'Plus Jakarta Sans (Geometric)',
                            'Roboto' => 'Roboto (Clean & Readable)',
                            'system-ui' => 'System UI Native Sans',
                        ]"
                    />
                </div>
            </div>
        </div>

        {{-- Section 3: Custom CSS --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="code" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Custom CSS Overrides') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Inject customized stylesheet rules into dashboard templates.') }}</p>
                </div>
            </div>

            <x-ui.textarea
                wire:model="form.custom_css"
                :label="__('Custom CSS')"
                rows="5"
                class="font-mono text-xs"
                placeholder="/* Custom CSS rules */&#10;:root {&#10;    --primary: 160 84% 39%;&#10;}"
            />
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    </form>
</div>
