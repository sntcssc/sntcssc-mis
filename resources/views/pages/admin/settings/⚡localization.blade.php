<?php

use App\Models\Setting;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Localization & Format Settings')] class extends Component {
    public array $form = [
        'language' => 'en',
        'fallback_language' => 'en',
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'd M Y',
        'time_format' => 'h:i A',
        'currency_symbol' => '₹',
        'currency_code' => 'INR',
        'number_format' => 'indian',
    ];

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'localization')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "localization.{$key}";
            if (isset($settings[$fullKey])) {
                $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.language' => ['required', 'string', 'in:en,hi,bn'],
            'form.fallback_language' => ['required', 'string', 'in:en,hi,bn'],
            'form.timezone' => ['required', 'string', 'max:100'],
            'form.date_format' => ['required', 'string', 'max:50'],
            'form.time_format' => ['required', 'string', 'max:50'],
            'form.currency_symbol' => ['required', 'string', 'max:10'],
            'form.currency_code' => ['required', 'string', 'max:10'],
            'form.number_format' => ['required', 'string', 'in:indian,international'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "localization.{$key}";

                    $options = match ($key) {
                        'language', 'fallback_language' => ['en' => 'English', 'hi' => 'Hindi', 'bn' => 'Bengali'],
                        'date_format' => [
                            'd M Y' => '23 Aug 2026',
                            'd/m/Y' => '23/08/2026',
                            'Y-m-d' => '2026-08-23',
                            'd-m-Y' => '23-08-2026',
                            'jS F Y' => '23rd August 2026',
                        ],
                        'time_format' => ['h:i A' => '05:30 PM', 'H:i' => '17:30'],
                        default => null,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'localization';
                    $setting->type = $options ? Setting::TYPE_SELECT : Setting::TYPE_STRING;
                    $setting->label = ucwords(str_replace('_', ' ', $key));
                    $setting->options = $options;
                    $setting->value = $value;
                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();
            });

            Toast::dispatch($this, 'success', __('Localization & format settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save localization settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save localization settings.'));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="localization"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Localization & Format Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Set default locale, regional timezone, calendar date display, clock formats and currency standards.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Languages & Regional Timezone --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="languages" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Language & Regional Timezone') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Locale translation settings and system time offsets.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.select
                    wire:model="form.language"
                    :label="__('Default App Language') . ' *'"
                    :options="[
                        'en' => 'English (US/UK)',
                        'hi' => 'Hindi (हिंदी)',
                        'bn' => 'Bengali (বাংলা)',
                    ]"
                />

                <x-ui.select
                    wire:model="form.fallback_language"
                    :label="__('Fallback Language') . ' *'"
                    :options="[
                        'en' => 'English',
                        'hi' => 'Hindi',
                        'bn' => 'Bengali',
                    ]"
                />

                <x-ui.select
                    wire:model="form.timezone"
                    :label="__('Default Timezone') . ' *'"
                    :options="[
                        'Asia/Kolkata' => 'Asia/Kolkata (IST, UTC+5:30)',
                        'UTC' => 'UTC (Coordinated Universal Time)',
                        'Asia/Dubai' => 'Asia/Dubai (GST, UTC+4:00)',
                        'Asia/Singapore' => 'Asia/Singapore (SGT, UTC+8:00)',
                        'Europe/London' => 'Europe/London (GMT/BST)',
                        'America/New_York' => 'America/New York (EST/EDT)',
                    ]"
                />
            </div>
        </div>

        {{-- Section 2: Date & Time Formatting --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="calendar" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Date & Time Display Standards') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Choose formatting patterns used in tables, exports and timestamps.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.select
                    wire:model.live="form.date_format"
                    :label="__('Date Format') . ' *'"
                    :options="[
                        'd M Y' => '23 Aug 2026 (d M Y)',
                        'd/m/Y' => '23/08/2026 (d/m/Y)',
                        'Y-m-d' => '2026-08-23 (ISO Y-m-d)',
                        'd-m-Y' => '23-08-2026 (d-m-Y)',
                        'jS F Y' => '23rd August 2026 (jS F Y)',
                    ]"
                />

                <x-ui.select
                    wire:model.live="form.time_format"
                    :label="__('Time Format') . ' *'"
                    :options="[
                        'h:i A' => '05:30 PM (12-hour with AM/PM)',
                        'H:i' => '17:30 (24-hour clock)',
                    ]"
                />

                {{-- Live preview sample --}}
                <div class="sm:col-span-2 p-3.5 rounded-lg border border-border bg-secondary/20 flex flex-wrap items-center justify-between gap-4">
                    <div class="space-y-0.5">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Formatted Timestamp Preview') }}</span>
                        <p class="text-sm font-medium font-mono text-primary">
                            {{ now()->format($form['date_format']) }} &bull; {{ now()->format($form['time_format']) }}
                        </p>
                    </div>
                    <span class="text-xs text-muted-foreground">{{ __('Calculated from current server time') }}</span>
                </div>
            </div>
        </div>

        {{-- Section 3: Currency & Number Formatting --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="dollar-sign" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Currency & Number Notation') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Fee vouchers, receipts and financial reports formatting.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.input
                    wire:model="form.currency_symbol"
                    :label="__('Currency Symbol') . ' *'"
                    placeholder="₹"
                    required
                />

                <x-ui.input
                    wire:model="form.currency_code"
                    :label="__('Currency Code (ISO)') . ' *'"
                    placeholder="INR"
                    required
                />

                <x-ui.select
                    wire:model.live="form.number_format"
                    :label="__('Number Separator Style') . ' *'"
                    :options="[
                        'indian' => 'Indian Numbering (₹ 12,34,567.00)',
                        'international' => 'International ($ 1,234,567.00)',
                    ]"
                />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    </form>
</div>
