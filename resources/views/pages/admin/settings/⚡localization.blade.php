<?php

use App\Models\Language;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\TranslationService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Localization & Language Settings')] class extends Component {
    public string $activeTab = 'general'; // 'general', 'languages', 'translations'

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

    /* ----------------------------------------------------------------- *
     *  Language CRUD State
     * ----------------------------------------------------------------- */
    public ?int $editingLanguageId = null;
    public ?int $deleteLanguageId = null;

    public array $languageForm = [
        'code' => '',
        'name' => '',
        'native_name' => '',
        'direction' => 'ltr',
        'flag' => '',
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ];

    /* ----------------------------------------------------------------- *
     *  Translation Editor State
     * ----------------------------------------------------------------- */
    public string $selectedLocale = 'hi';
    public string $translationSearch = '';
    public array $translations = [];
    public array $newTranslation = [
        'key' => '',
        'value' => '',
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

        $this->loadTranslations();
    }

    #[Computed]
    public function languages()
    {
        return Language::query()->ordered()->get();
    }

    #[Computed]
    public function languageOptions(): array
    {
        return $this->languages->mapWithKeys(fn (Language $lang) => [
            $lang->code => "{$lang->name} ({$lang->native_name})",
        ])->all();
    }

    public function updatedSelectedLocale(): void
    {
        $this->loadTranslations();
    }

    public function loadTranslations(): void
    {
        $this->translations = TranslationService::getTranslations($this->selectedLocale);
    }

    /* ----------------------------------------------------------------- *
     *  General Settings Save
     * ----------------------------------------------------------------- */
    public function save(): void
    {
        $availableCodes = $this->languages->pluck('code')->all();
        if (empty($availableCodes)) {
            $availableCodes = ['en', 'hi', 'bn'];
        }

        $this->validate([
            'form.language' => ['required', 'string', Rule::in($availableCodes)],
            'form.fallback_language' => ['required', 'string', Rule::in($availableCodes)],
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
                        'language', 'fallback_language' => $this->languageOptions,
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

                AuditLogService::log(
                    event: 'setting_updated',
                    description: 'Updated localization, format, and timezone standards.',
                    newValues: $this->form,
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('Localization & format settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save localization settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save localization settings.'));
        }
    }

    /* ----------------------------------------------------------------- *
     *  Language CRUD Actions
     * ----------------------------------------------------------------- */
    public function createLanguage(): void
    {
        $this->editingLanguageId = null;
        $this->languageForm = [
            'code' => '',
            'name' => '',
            'native_name' => '',
            'direction' => 'ltr',
            'flag' => '🌐',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => (int) ($this->languages->max('sort_order') + 1),
        ];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'language-form');
    }

    public function editLanguage(int $id): void
    {
        $language = Language::query()->findOrFail($id);
        $this->editingLanguageId = $id;
        $this->languageForm = [
            'code' => $language->code,
            'name' => $language->name,
            'native_name' => $language->native_name,
            'direction' => $language->direction,
            'flag' => $language->flag ?? '🌐',
            'is_active' => (bool) $language->is_active,
            'is_default' => (bool) $language->is_default,
            'sort_order' => (int) $language->sort_order,
        ];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'language-form');
    }

    public function saveLanguage(): void
    {
        $this->validate([
            'languageForm.code' => ['required', 'string', 'max:10', 'alpha_dash', Rule::unique(Language::class, 'code')->ignore($this->editingLanguageId)],
            'languageForm.name' => ['required', 'string', 'max:100'],
            'languageForm.native_name' => ['required', 'string', 'max:100'],
            'languageForm.direction' => ['required', 'string', 'in:ltr,rtl'],
            'languageForm.flag' => ['nullable', 'string', 'max:10'],
            'languageForm.sort_order' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            DB::transaction(function () {
                $code = strtolower(trim($this->languageForm['code']));

                if ($this->languageForm['is_default']) {
                    Language::query()->where('id', '!=', $this->editingLanguageId)->update(['is_default' => false]);
                }

                $language = $this->editingLanguageId
                    ? Language::query()->findOrFail($this->editingLanguageId)
                    : new Language;

                $language->fill([
                    'code' => $code,
                    'name' => $this->languageForm['name'],
                    'native_name' => $this->languageForm['native_name'],
                    'direction' => $this->languageForm['direction'],
                    'flag' => $this->languageForm['flag'] ?: '🌐',
                    'is_active' => (bool) $this->languageForm['is_active'],
                    'is_default' => (bool) $this->languageForm['is_default'],
                    'sort_order' => (int) $this->languageForm['sort_order'],
                ]);
                $language->save();

                // Create empty language JSON file if not exists
                $path = base_path("lang/{$code}.json");
                if (! file_exists($path)) {
                    TranslationService::saveTranslations($code, []);
                }

                Language::flushCache();

                AuditLogService::log(
                    event: $this->editingLanguageId ? 'language_updated' : 'language_created',
                    description: "Language {$language->name} ({$language->code}) saved.",
                    auditable: $language,
                    userId: auth()->id()
                );
            });

            Toast::dispatch($this, 'success', $this->editingLanguageId ? __('Language updated.') : __('Language created successfully.'));
            $this->dispatch('modal-close', name: 'language-form');
        } catch (\Throwable $e) {
            Log::error('Failed to save language: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save language.'));
        }
    }

    public function toggleLanguageStatus(int $id): void
    {
        $language = Language::query()->findOrFail($id);

        if ($language->is_default && $language->is_active) {
            Toast::dispatch($this, 'error', __('Default application language cannot be disabled.'));

            return;
        }

        $language->update(['is_active' => ! $language->is_active]);
        Language::flushCache();

        AuditLogService::log(
            event: 'language_updated',
            description: "Toggled language active status for {$language->name} ({$language->code}).",
            auditable: $language
        );

        Toast::dispatch($this, 'success', $language->is_active ? __('Language activated.') : __('Language deactivated.'));
    }

    public function setAsDefaultLanguage(int $id): void
    {
        $language = Language::query()->findOrFail($id);

        DB::transaction(function () use ($language) {
            Language::query()->update(['is_default' => false]);
            $language->update(['is_default' => true, 'is_active' => true]);

            Setting::set('localization.language', $language->code, auth()->id());
            $this->form['language'] = $language->code;

            Language::flushCache();
            Setting::flushCache();

            AuditLogService::log(
                event: 'language_updated',
                description: "Set {$language->name} ({$language->code}) as the default app language.",
                auditable: $language
            );
        });

        Toast::dispatch($this, 'success', __(':name set as default language.', ['name' => $language->name]));
    }

    public function selectLanguageForDelete(int $id): void
    {
        $this->deleteLanguageId = $id;
        $this->dispatch('modal-open', name: 'language-delete');
    }

    public function deleteSelectedLanguage(): void
    {
        if (! $this->deleteLanguageId) {
            return;
        }

        $language = Language::query()->findOrFail($this->deleteLanguageId);

        if ($language->is_default) {
            Toast::dispatch($this, 'error', __('Default language cannot be deleted.'));

            return;
        }

        if ($this->languages->count() <= 1) {
            Toast::dispatch($this, 'error', __('System must retain at least one active language.'));

            return;
        }

        $code = $language->code;
        $name = $language->name;
        $language->delete();
        Language::flushCache();

        AuditLogService::log(
            event: 'language_deleted',
            description: "Deleted language {$name} ({$code}).",
            userId: auth()->id()
        );

        $this->deleteLanguageId = null;
        $this->dispatch('modal-close', name: 'language-delete');
        Toast::dispatch($this, 'success', __('Language deleted successfully.'));
    }

    /* ----------------------------------------------------------------- *
     *  Translation Management Actions
     * ----------------------------------------------------------------- */
    public function saveTranslations(): void
    {
        try {
            $saved = TranslationService::saveTranslations($this->selectedLocale, $this->translations);

            if ($saved) {
                AuditLogService::log(
                    event: 'translations_updated',
                    description: "Updated translation strings for locale: {$this->selectedLocale}.",
                    userId: auth()->id()
                );
                Toast::dispatch($this, 'success', __('Translations saved successfully for :locale.', ['locale' => strtoupper($this->selectedLocale)]));
            } else {
                Toast::dispatch($this, 'error', __('Could not write to translation file. Check folder permissions.'));
            }
        } catch (\Throwable $e) {
            Log::error('Translation save error: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save translations.'));
        }
    }

    public function addTranslationKey(): void
    {
        $this->validate([
            'newTranslation.key' => ['required', 'string', 'max:255'],
            'newTranslation.value' => ['required', 'string'],
        ]);

        $key = trim($this->newTranslation['key']);
        $value = trim($this->newTranslation['value']);

        $this->translations[$key] = $value;
        TranslationService::saveTranslations($this->selectedLocale, $this->translations);

        $this->newTranslation = ['key' => '', 'value' => ''];
        $this->dispatch('modal-close', name: 'new-translation-modal');

        Toast::dispatch($this, 'success', __('Translation string ":key" added.', ['key' => $key]));
    }

    public function deleteTranslationKey(string $key): void
    {
        unset($this->translations[$key]);
        TranslationService::saveTranslations($this->selectedLocale, $this->translations);

        Toast::dispatch($this, 'success', __('Translation string removed.'));
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="localization"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Localization & Language Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Manage system languages, live translation strings, calendar standards, clock formats and currency.') }}</p>
        </div>

        @if ($activeTab === 'general')
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                {{ __('Save Regional Settings') }}
            </x-ui.button>
        @elseif ($activeTab === 'languages')
            <x-ui.button wire:click="createLanguage" class="h-9">
                <x-icon name="plus" class="h-4 w-4 mr-1.5"/>
                {{ __('Add New Language') }}
            </x-ui.button>
        @elseif ($activeTab === 'translations')
            <div class="flex items-center gap-2">
                <x-ui.button variant="outline" class="h-9" x-data x-on:click="$store.modals.open('new-translation-modal')">
                    <x-icon name="plus" class="h-4 w-4 mr-1.5"/>
                    {{ __('New Phrase') }}
                </x-ui.button>
                <x-ui.button wire:click="saveTranslations" wire:loading.attr="disabled" class="h-9">
                    <x-icon name="save" class="h-4 w-4 mr-1.5"/>
                    {{ __('Save Translations') }}
                </x-ui.button>
            </div>
        @endif
    </div>

    {{-- Sub-navigation Tabs --}}
    <div class="flex items-center gap-2 border-b border-border pb-2">
        <button
            type="button"
            wire:click="$set('activeTab', 'general')"
            class="flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer {{ $activeTab === 'general' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="sliders" class="h-3.5 w-3.5"/>
            {{ __('Regional & Formats') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'languages')"
            class="flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer {{ $activeTab === 'languages' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="languages" class="h-3.5 w-3.5"/>
            {{ __('Language Management') }} ({{ $this->languages->count() }})
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'translations')"
            class="flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer {{ $activeTab === 'translations' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="file-text" class="h-3.5 w-3.5"/>
            {{ __('Translation String Editor') }}
        </button>
    </div>

    {{-- TAB 1: General Regional & Formats --}}
    @if ($activeTab === 'general')
        <form wire:submit="save" class="space-y-6">
            {{-- Section 1: Languages & Regional Timezone --}}
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
                <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon name="languages" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('Language & Regional Timezone') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('Locale standards and timezone offset applied across all operations.') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-ui.select
                        wire:model="form.language"
                        :label="__('Default App Language') . ' *'"
                        :options="$this->languageOptions"
                    />

                    <x-ui.select
                        wire:model="form.fallback_language"
                        :label="__('Fallback Language') . ' *'"
                        :options="$this->languageOptions"
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
                        <p class="text-[11px] text-muted-foreground">{{ __('Formatting patterns used across tables, badges, exports and logs.') }}</p>
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
                        <span class="text-xs text-muted-foreground">{{ __('Calculated from current system time') }}</span>
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
                        <p class="text-[11px] text-muted-foreground">{{ __('Standards for fee vouchers, receipts and financial analytics.') }}</p>
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
                    {{ __('Save Regional Settings') }}
                </x-ui.button>
            </div>
        </form>
    @endif

    {{-- TAB 2: Language Management Table & Controls --}}
    @if ($activeTab === 'languages')
        <div class="space-y-4">
            <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                <div class="p-4 border-b border-border flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('Supported Languages Directory') }}</h2>
                        <p class="text-xs text-muted-foreground">{{ __('Active languages appear in the header locale switcher and drive interface translations.') }}</p>
                    </div>

                    <x-ui.button size="sm" class="h-8" wire:click="createLanguage">
                        <x-icon name="plus" class="h-3.5 w-3.5 mr-1"/>
                        {{ __('Add Language') }}
                    </x-ui.button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px]">
                        <thead>
                            <tr class="border-b border-border bg-muted/40">
                                <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Language') }}</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Code') }}</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Native Name') }}</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Direction') }}</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Default') }}</th>
                                <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->languages as $lang)
                                <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="lang-{{ $lang->id }}">
                                    <td class="px-4 py-3.5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-lg">{{ $lang->flag ?: '🌐' }}</span>
                                            <span class="text-sm font-medium">{{ $lang->name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono text-xs">{{ $lang->code }}</td>
                                    <td class="px-4 py-3.5 text-sm">{{ $lang->native_name }}</td>
                                    <td class="px-4 py-3.5 uppercase text-xs font-mono">{{ $lang->direction }}</td>
                                    <td class="px-4 py-3.5">
                                        <button type="button" wire:click="toggleLanguageStatus({{ $lang->id }})" class="cursor-pointer" title="{{ __('Toggle status') }}">
                                            <x-ui.badge :color="$lang->is_active ? 'success' : 'secondary'">
                                                {{ $lang->is_active ? __('Active') : __('Disabled') }}
                                            </x-ui.badge>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        @if ($lang->is_default)
                                            <x-ui.badge color="primary">{{ __('Default') }}</x-ui.badge>
                                        @else
                                            <button type="button" wire:click="setAsDefaultLanguage({{ $lang->id }})" class="text-xs text-muted-foreground hover:text-primary hover:underline cursor-pointer">
                                                {{ __('Set Default') }}
                                            </button>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3.5 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button
                                                type="button"
                                                wire:click="$set('selectedLocale', '{{ $lang->code }}'); $set('activeTab', 'translations');"
                                                class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-primary transition-colors cursor-pointer"
                                                title="{{ __('Edit Translations') }}"
                                            >
                                                <x-icon name="file-text" class="h-4 w-4"/>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="editLanguage({{ $lang->id }})"
                                                class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                                                title="{{ __('Edit') }}"
                                            >
                                                <x-icon name="pencil" class="h-4 w-4"/>
                                            </button>
                                            @if (!$lang->is_default)
                                                <button
                                                    type="button"
                                                    wire:click="selectLanguageForDelete({{ $lang->id }})"
                                                    class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-destructive transition-colors cursor-pointer"
                                                    title="{{ __('Delete') }}"
                                                >
                                                    <x-icon name="trash-2" class="h-4 w-4"/>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-muted-foreground text-sm">
                                        {{ __('No languages registered.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 3: Translation String Editor --}}
    @if ($activeTab === 'translations')
        <div class="space-y-4">
            <div class="rounded-xl border border-border bg-card p-4 space-y-4 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-border">
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <x-icon name="globe" class="h-4 w-4"/>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold">{{ __('Live Translation String Editor') }}</h2>
                            <p class="text-xs text-muted-foreground">{{ __('Edit UI phrases saved directly to JSON translation files.') }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <select
                            wire:model.live="selectedLocale"
                            class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-xs font-medium shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            @foreach ($this->languages as $lang)
                                <option value="{{ $lang->code }}">{{ $lang->flag }} {{ $lang->name }} ({{ $lang->code }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Search & Add --}}
                <div class="flex items-center justify-between gap-3">
                    <div class="relative flex-1 max-w-sm">
                        <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                        <input
                            type="text"
                            wire:model.live.debounce.250ms="translationSearch"
                            placeholder="{{ __('Search phrases or translations…') }}"
                            class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        @if ($translationSearch)
                            <button type="button" wire:click="$set('translationSearch', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                                <x-icon name="x" class="h-3.5 w-3.5"/>
                            </button>
                        @endif
                    </div>

                    <span class="text-xs text-muted-foreground font-mono">
                        {{ count($translations) }} {{ __('phrases') }}
                    </span>
                </div>

                {{-- Translation Items Grid/List --}}
                <div class="space-y-3 max-h-[600px] overflow-y-auto pr-1">
                    @php
                        $filtered = collect($translations)
                            ->when($translationSearch, function ($col) use ($translationSearch) {
                                return $col->filter(function ($val, $k) use ($translationSearch) {
                                    return str_contains(strtolower($k), strtolower($translationSearch))
                                        || str_contains(strtolower($val), strtolower($translationSearch));
                                });
                            });
                    @endphp

                    @forelse ($filtered as $key => $val)
                        <div class="rounded-lg border border-border p-3 bg-secondary/15 space-y-2" wire:key="tr-{{ md5($key) }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-mono font-medium text-foreground truncate select-all" title="{{ $key }}">{{ $key }}</span>
                                <button
                                    type="button"
                                    wire:click="deleteTranslationKey('{{ addslashes($key) }}')"
                                    class="text-[11px] text-destructive hover:underline cursor-pointer shrink-0"
                                    title="{{ __('Delete phrase') }}"
                                >
                                    <x-icon name="trash" class="h-3.5 w-3.5"/>
                                </button>
                            </div>

                            <input
                                type="text"
                                wire:model.defer="translations.{{ $key }}"
                                class="h-8 w-full rounded-md border border-input bg-background px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[2px] focus-visible:ring-ring/40"
                            />
                        </div>
                    @empty
                        <div class="p-8 text-center text-muted-foreground text-xs">
                            <x-icon name="search-x" class="mx-auto h-6 w-6 mb-1.5 opacity-60"/>
                            {{ __('No translation phrases matching your search.') }}
                        </div>
                    @endforelse
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                    <x-ui.button wire:click="saveTranslations" wire:loading.attr="disabled" class="h-9">
                        <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                        {{ __('Save Translations') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Create / Edit Language Modal --}}
    <x-ui.modal name="language-form" max-width="max-w-md" :title="$editingLanguageId ? __('Edit Language') : __('Add New Language')" :description="__('Configure a language code, native title, text direction and emoji flag.')">
        <form wire:submit="saveLanguage" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-ui.input wire:model="languageForm.code" :label="__('Language Code (ISO)') .' *'" placeholder="es" required :disabled="(bool) $editingLanguageId"/>
                    <p class="text-[10px] text-muted-foreground mt-1">{{ __('e.g. en, hi, bn, es, fr') }}</p>
                </div>

                <div>
                    <x-ui.input wire:model="languageForm.flag" :label="__('Flag Emoji')" placeholder="🇪🇸"/>
                </div>

                <div class="col-span-2">
                    <x-ui.input wire:model="languageForm.name" :label="__('Language Name (English)') .' *'" placeholder="Spanish" required/>
                </div>

                <div class="col-span-2">
                    <x-ui.input wire:model="languageForm.native_name" :label="__('Native Name') .' *'" placeholder="Español" required/>
                </div>

                <div>
                    <x-ui.select
                        wire:model="languageForm.direction"
                        :label="__('Text Direction') . ' *'"
                        :options="['ltr' => 'Left-to-Right (LTR)', 'rtl' => 'Right-to-Left (RTL)']"
                    />
                </div>

                <div>
                    <x-ui.input wire:model="languageForm.sort_order" :label="__('Sort Order')" type="number" placeholder="1"/>
                </div>

                <div class="col-span-2 space-y-2">
                    <x-ui.switch wire:model="languageForm.is_active" :label="__('Active Language')" :description="__('Available in switcher and selectable by users.')" :checked="(bool) $languageForm['is_active']"/>
                    <x-ui.switch wire:model="languageForm.is_default" :label="__('Set as Default Application Language')" :checked="(bool) $languageForm['is_default']"/>
                </div>
            </div>

            @if ($errors->any())
                <p class="text-xs text-destructive">{{ $errors->first() }}</p>
            @endif

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('language-form')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save Language') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete Language Modal --}}
    <x-ui.modal name="language-delete" max-width="max-w-sm" :title="__('Delete Language')" :description="__('This action cannot be undone.')">
        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('language-delete')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deleteSelectedLanguage">{{ __('Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Add Translation Phrase Modal --}}
    <x-ui.modal name="new-translation-modal" max-width="max-w-md" :title="__('Add New Translation Phrase')" :description="__('Register a new key and translated value for :locale.', ['locale' => strtoupper($selectedLocale)])">
        <form wire:submit="addTranslationKey" class="space-y-4">
            <x-ui.input wire:model="newTranslation.key" :label="__('Source Key / English Phrase') .' *'" placeholder="Welcome to our platform" required/>
            <x-ui.input wire:model="newTranslation.value" :label="__('Translated String') .' *'" placeholder="हमारे मंच में आपका स्वागत है" required/>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('new-translation-modal')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Add Phrase') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>

