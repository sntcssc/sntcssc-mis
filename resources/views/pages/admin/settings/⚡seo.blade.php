<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('SEO Settings')] class extends Component {
    use WithFileUploads;

    public array $form = [
        'meta_title' => '',
        'meta_description' => '',
        'meta_keywords' => '',
        'canonical_url' => '',
        'og_image' => '',
        'twitter_handle' => '',
        'robots_txt' => "User-agent: *\nAllow: /",
        'google_analytics_id' => '',
    ];

    public $ogImageFile = null;

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'seo')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "seo.{$key}";
            if (isset($settings[$fullKey])) {
                $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.meta_title' => ['required', 'string', 'max:255'],
            'form.meta_description' => ['nullable', 'string', 'max:500'],
            'form.meta_keywords' => ['nullable', 'string', 'max:500'],
            'form.canonical_url' => ['nullable', 'url', 'max:255'],
            'form.twitter_handle' => ['nullable', 'string', 'max:50'],
            'form.robots_txt' => ['nullable', 'string', 'max:2000'],
            'form.google_analytics_id' => ['nullable', 'string', 'max:50'],
            'ogImageFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                if ($this->ogImageFile) {
                    $this->form['og_image'] = FileUploadService::store(
                        file: $this->ogImageFile,
                        folder: 'settings/seo',
                        prefix: 'og_image',
                        oldPath: $this->form['og_image'] ?: null
                    );
                    $this->ogImageFile = null;
                }

                foreach ($this->form as $key => $value) {
                    $fullKey = "seo.{$key}";
                    $type = $key === 'og_image'
                        ? Setting::TYPE_IMAGE
                        : (in_array($key, ['meta_description', 'meta_keywords', 'robots_txt'], true) ? Setting::TYPE_TEXT : Setting::TYPE_STRING);

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'seo';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));
                    $setting->value = $value;
                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'setting_updated',
                    description: "Updated SEO and search indexing settings (Title: {$this->form['meta_title']}).",
                    newValues: $this->form,
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('SEO settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save SEO settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save SEO settings.'));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="seo"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('SEO & Meta Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Search engine optimization tags, social graph cards, analytics and crawler instructions.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Standard Meta Tags --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="globe" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Meta Tags & Search Preview') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Define the title and description indexed by Google and Bing.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4">
                <x-ui.input
                    wire:model.live.debounce.300ms="form.meta_title"
                    :label="__('Meta Title') . ' *'"
                    placeholder="SNT CSSC MIS — Student Management Information System"
                    required
                />

                <x-ui.textarea
                    wire:model.live.debounce.300ms="form.meta_description"
                    :label="__('Meta Description')"
                    rows="3"
                    placeholder="Admissions, enrollments, batches, tests and fee management..."
                    hint="{{ __('Recommended: 150–160 characters for optimal search snippet display.') }}"
                />

                <x-ui.textarea
                    wire:model="form.meta_keywords"
                    :label="__('Meta Keywords')"
                    rows="2"
                    placeholder="sntcssc, mis, civil services coaching, admissions"
                    hint="{{ __('Comma-separated keywords.') }}"
                />

                {{-- Live Search Result Mockup --}}
                <div class="mt-3 p-4 rounded-lg border border-border bg-secondary/20">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground block mb-2">{{ __('Search Result Snippet Preview') }}</span>
                    <div class="space-y-1">
                        <div class="text-xs text-emerald-600 dark:text-emerald-400 truncate">{{ $form['canonical_url'] ?: config('app.url') }}</div>
                        <div class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer">{{ $form['meta_title'] ?: __('Website Title') }}</div>
                        <div class="text-xs text-muted-foreground line-clamp-2">{{ $form['meta_description'] ?: __('Your meta description will appear here in search engine results.') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 2: Social Media (Open Graph & Twitter) --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="images" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Social Graph & Sharing (OG Tags)') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Social share card preview image and canonical URL.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input
                    wire:model="form.canonical_url"
                    :label="__('Canonical Base URL')"
                    placeholder="https://sntcssc.in"
                    icon="globe"
                />

                <x-ui.input
                    wire:model="form.twitter_handle"
                    :label="__('Twitter / X Handle')"
                    placeholder="@sntcssc"
                    icon="hash"
                />

                <div class="sm:col-span-2">
                    <x-ui.file-upload
                        wire:model="ogImageFile"
                        :file="$ogImageFile"
                        :value="$form['og_image']"
                        :label="__('Open Graph Share Banner Image')"
                        type="image"
                        maxSize="5MB"
                        folder="settings/seo"
                        hint="{{ __('Recommended: 1200x630px JPG or PNG for high-res cards on Facebook, Twitter, WhatsApp.') }}"
                    />
                </div>
            </div>
        </div>

        {{-- Section 3: Crawlers & Analytics --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="code" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Analytics & Search Crawlers') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Tracking measurement IDs and robots.txt directives.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4">
                <x-ui.input
                    wire:model="form.google_analytics_id"
                    :label="__('Google Analytics Measurement ID / GTM ID')"
                    placeholder="G-XXXXXXXXXX or GTM-XXXXXXX"
                    icon="tag"
                />

                <x-ui.textarea
                    wire:model="form.robots_txt"
                    :label="__('Robots.txt Directives')"
                    rows="4"
                    class="font-mono text-xs"
                    placeholder="User-agent: *&#10;Allow: /"
                    hint="{{ __('Search engine bot crawling instructions.') }}"
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
