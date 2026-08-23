<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('System & Maintenance Settings')] class extends Component {
    public array $form = [
        'maintenance_mode' => false,
        'maintenance_secret' => '',
        'maintenance_token' => '',
        'maintenance_message' => 'The application is currently undergoing scheduled maintenance. Please check back shortly.',
        'debug_mode' => false,
        'app_name' => 'SNT CSSC MIS',
        'app_version' => '1.0.0',
        'developed_by' => 'SNT CSSC IT Team',
        'developer_contact' => 'dev@sntcssc.in',
        'developer_github' => 'https://github.com/sntcssc',
        'developer_website' => 'https://sntcssc.in',
        'max_upload_size' => 10,
        'session_lifetime' => 120,
        'cache_driver' => 'file',
    ];

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'system')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "system.{$key}";
            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_NUMBER) {
                    $this->form[$key] = (int) $setting->typed();
                } else {
                    $this->form[$key] = (string) ($setting->rawValue() ?? $default);
                }
            }
        }

        if (empty($this->form['maintenance_secret']) && ! empty($this->form['maintenance_token'])) {
            $this->form['maintenance_secret'] = $this->form['maintenance_token'];
        }
        if (empty($this->form['maintenance_secret'])) {
            $this->form['maintenance_secret'] = Str::random(16);
        }
        $this->form['maintenance_token'] = $this->form['maintenance_secret'];
    }

    public function generateNewSecret(): void
    {
        $this->form['maintenance_secret'] = Str::random(24);
        $this->form['maintenance_token'] = $this->form['maintenance_secret'];
        Toast::dispatch($this, 'info', __('New secret bypass token generated. Remember to save changes.'));
    }

    public function regenerateToken(): void
    {
        $this->generateNewSecret();
    }

    public function save(): void
    {
        if ($this->form['maintenance_token'] !== '' && $this->form['maintenance_token'] !== $this->form['maintenance_secret']) {
            $this->form['maintenance_secret'] = $this->form['maintenance_token'];
        } else {
            $this->form['maintenance_token'] = $this->form['maintenance_secret'];
        }

        $this->validate([
            'form.maintenance_mode' => ['boolean'],
            'form.maintenance_secret' => ['nullable', 'string', 'max:100'],
            'form.maintenance_token' => ['nullable', 'string', 'max:100'],
            'form.maintenance_message' => ['nullable', 'string', 'max:500'],
            'form.debug_mode' => ['boolean'],
            'form.app_name' => ['required', 'string', 'max:255'],
            'form.app_version' => ['required', 'string', 'max:50'],
            'form.developed_by' => ['nullable', 'string', 'max:255'],
            'form.developer_contact' => ['nullable', 'string', 'max:255'],
            'form.developer_github' => ['nullable', 'url', 'max:255'],
            'form.developer_website' => ['nullable', 'url', 'max:255'],
            'form.max_upload_size' => ['required', 'numeric', 'min:1', 'max:100'],
            'form.session_lifetime' => ['required', 'numeric', 'min:1', 'max:1440'],
            'form.cache_driver' => ['required', 'string', 'in:file,redis,database,array'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "system.{$key}";

                    $type = match ($key) {
                        'maintenance_mode', 'debug_mode' => Setting::TYPE_BOOLEAN,
                        'max_upload_size', 'session_lifetime' => Setting::TYPE_NUMBER,
                        'cache_driver' => Setting::TYPE_SELECT,
                        'maintenance_message' => Setting::TYPE_TEXT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'system';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));
                    $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'maintenance_mode_toggled',
                    description: $this->form['maintenance_mode'] ? 'Application Maintenance Mode ENABLED with Secret Token.' : 'Application Maintenance Mode DISABLED.',
                    newValues: [
                        'maintenance_mode' => $this->form['maintenance_mode'],
                        'debug_mode' => $this->form['debug_mode'],
                        'app_name' => $this->form['app_name'],
                    ],
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('System settings and maintenance configuration saved.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save system settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save system settings.'));
        }
    }

    public function clearCache(): void
    {
        try {
            Artisan::call('cache:clear');
            Setting::flushCache();
            Toast::dispatch($this, 'success', __('Application cache cleared successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to clear cache: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Cache clear failed.'));
        }
    }

    public function clearViews(): void
    {
        try {
            Artisan::call('view:clear');
            Toast::dispatch($this, 'success', __('Compiled Blade views cleared.'));
        } catch (\Throwable $e) {
            Log::error('Failed to clear views: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('View cache clear failed.'));
        }
    }

    public function linkStorage(): void
    {
        try {
            Artisan::call('storage:link');
            Toast::dispatch($this, 'success', __('Public storage symlink verified.'));
        } catch (\Throwable $e) {
            Log::error('Storage link failed: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Storage link failed.'));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="system"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('System & Maintenance Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Centralized application environment, maintenance mode with secret token bypass, cache drivers and quotas.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Maintenance Mode with Secret Token Bypass --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="alert-triangle" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Application Status & Maintenance Mode') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Lock the public interface while allowing authorized stakeholders to bypass using a secret URL token.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2">
                    <x-ui.switch
                        wire:model="form.maintenance_mode"
                        :label="__('Enable Application Maintenance Mode')"
                        :description="__('Renders a 503 Maintenance page to students/public. Admin panel stays accessible.')"
                        :checked="(bool) $form['maintenance_mode']"
                    />
                </div>

                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2">
                    <x-ui.switch
                        wire:model="form.debug_mode"
                        :label="__('Enable Diagnostic Mode (Debug)')"
                        :description="__('Detailed stack traces in dev/staging environments.')"
                        :checked="(bool) $form['debug_mode']"
                    />
                </div>
            </div>

            {{-- Secret Token Configuration --}}
            <div class="p-4 rounded-xl border border-border bg-secondary/20 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xs font-semibold text-foreground">{{ __('Secret Token Bypass Key') }}</h3>
                        <p class="text-[11px] text-muted-foreground">{{ __('Anyone opening the app with this token (?secret=TOKEN) will receive a bypass cookie and can browse normally.') }}</p>
                    </div>

                    <button
                        type="button"
                        wire:click="generateNewSecret"
                        class="text-xs text-primary hover:underline font-medium cursor-pointer flex items-center gap-1"
                    >
                        <x-icon name="refresh-cw" class="h-3 w-3"/>
                        {{ __('Generate New Token') }}
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input
                        wire:model="form.maintenance_secret"
                        :label="__('Bypass Secret Token')"
                        placeholder="e.g. snt_bypass_2026"
                        class="font-mono text-xs"
                    />

                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Bypass URL Link') }}</label>
                        <div class="flex items-center gap-2 mt-1">
                            <input
                                type="text"
                                readonly
                                value="{{ url('/') }}?secret={{ $form['maintenance_secret'] }}"
                                class="h-9 flex-1 rounded-md border border-input bg-background/50 px-3 font-mono text-xs text-muted-foreground select-all"
                            />
                        </div>
                    </div>
                </div>

                <x-ui.textarea
                    wire:model="form.maintenance_message"
                    :label="__('Public Maintenance Notice Message')"
                    rows="2"
                    placeholder="We are currently upgrading server systems. Please check back shortly."
                />
            </div>
        </div>

        {{-- Section 2: Application & Developer Information --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="cpu" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Build & Developer Credits') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Internal application release metadata and contact references.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.input
                    wire:model="form.app_name"
                    :label="__('Application Name') . ' *'"
                    placeholder="SNT CSSC MIS"
                    required
                />

                <x-ui.input
                    wire:model="form.app_version"
                    :label="__('Release Version') . ' *'"
                    placeholder="1.0.0"
                    required
                />

                <x-ui.input
                    wire:model="form.developed_by"
                    :label="__('Developed By')"
                    placeholder="SNT CSSC IT Team"
                />

                <x-ui.input
                    wire:model="form.developer_contact"
                    :label="__('Support / Developer Email')"
                    placeholder="dev@sntcssc.in"
                    icon="mail"
                />

                <x-ui.input
                    wire:model="form.developer_github"
                    :label="__('GitHub Repository')"
                    placeholder="https://github.com/sntcssc"
                    icon="code"
                />

                <x-ui.input
                    wire:model="form.developer_website"
                    :label="__('Documentation / Website')"
                    placeholder="https://sntcssc.in"
                    icon="globe"
                />
            </div>
        </div>

        {{-- Section 3: Quotas & Storage Limits --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="hard-drive" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Storage Limits & Sessions') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Upload size limits and session timeouts.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.input
                    wire:model="form.max_upload_size"
                    :label="__('Max Upload Limit (MB)') . ' *'"
                    type="number"
                    placeholder="10"
                    hint="{{ __('Max size per document / receipt.') }}"
                />

                <x-ui.input
                    wire:model="form.session_lifetime"
                    :label="__('Session Inactivity Timeout (Min)') . ' *'"
                    type="number"
                    placeholder="120"
                />

                <x-ui.select
                    wire:model="form.cache_driver"
                    :label="__('Primary Cache Driver') . ' *'"
                    :options="[
                        'file' => 'File Cache (Local Disk)',
                        'redis' => 'Redis (In-Memory)',
                        'database' => 'Database Table',
                        'array' => 'Array (Testing)',
                    ]"
                />
            </div>
        </div>

        {{-- Section 4: System Utilities & Maintenance Tools --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="rotate-ccw" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Maintenance Utilities') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Clear application cache and refresh compiled assets.') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="clearCache"
                    wire:loading.attr="disabled"
                    wire:target="clearCache"
                    class="h-9 gap-1.5"
                >
                    <x-icon name="refresh-cw" class="h-4 w-4" wire:loading.remove wire:target="clearCache"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="clearCache"/>
                    {{ __('Clear App Cache') }}
                </x-ui.button>

                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="clearViews"
                    wire:loading.attr="disabled"
                    wire:target="clearViews"
                    class="h-9 gap-1.5"
                >
                    <x-icon name="layers" class="h-4 w-4" wire:loading.remove wire:target="clearViews"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="clearViews"/>
                    {{ __('Clear Compiled Views') }}
                </x-ui.button>

                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="linkStorage"
                    wire:loading.attr="disabled"
                    wire:target="linkStorage"
                    class="h-9 gap-1.5"
                >
                    <x-icon name="hard-drive" class="h-4 w-4" wire:loading.remove wire:target="linkStorage"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="linkStorage"/>
                    {{ __('Verify Storage Symlink') }}
                </x-ui.button>
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

