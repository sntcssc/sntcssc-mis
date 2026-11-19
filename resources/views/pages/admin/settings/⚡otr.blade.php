<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\OtrSyncService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Admission OTR Integration Settings')] class extends Component {
    public array $form = [
        'enabled' => true,
        'base_url' => 'http://127.0.0.1:8000',
        'api_key' => '',
        'timeout' => 30,
        'auto_sync_enabled' => false,
        'auto_sync_frequency' => 'daily',
        'default_course_id' => null,
        'default_batch_id' => null,
        'download_documents' => false,
        'update_existing_profiles' => true,
    ];

    public bool $revealApiKey = false;
    public bool $isTestingConnection = false;
    public ?array $testResult = null;

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        $settings = Setting::query()->where('group', 'otr')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "otr.{$key}";
            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_NUMBER) {
                    $this->form[$key] = (int) $setting->typed();
                } elseif ($key === 'api_key' && $setting->type === Setting::TYPE_SECRET) {
                    $this->form[$key] = (string) ($setting->rawValue() ?? '');
                } else {
                    $this->form[$key] = $setting->typed() ?? $default;
                }
            }
        }
    }

    public function testConnection(): void
    {
        $this->isTestingConnection = true;
        $this->testResult = null;

        try {
            $this->testResult = OtrSyncService::ping(
                baseUrl: $this->form['base_url'],
                apiKey: $this->form['api_key']
            );

            if ($this->testResult['success']) {
                Toast::dispatch($this, 'success', __('Connection test successful! Connected to Admission OTR Portal.'));
            } else {
                Toast::dispatch($this, 'error', __('Connection test failed: :msg', ['msg' => $this->testResult['message']]));
            }
        } catch (\Throwable $e) {
            $this->testResult = [
                'success' => false,
                'status' => 0,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
                'data' => null,
            ];
            Toast::dispatch($this, 'error', __('Connection error: :msg', ['msg' => $e->getMessage()]));
        } finally {
            $this->isTestingConnection = false;
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.base_url' => ['required', 'url'],
            'form.timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'form.default_course_id' => ['nullable', 'exists:courses,id'],
            'form.default_batch_id' => ['nullable', 'exists:batches,id'],
        ]);

        try {
            DB::transaction(function () {
                $existing = Setting::query()->where('group', 'otr')->get()->keyBy('key');

                $definitions = [
                    'enabled' => ['type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Admission OTR Integration Feature'],
                    'base_url' => ['type' => Setting::TYPE_STRING, 'label' => 'Admission OTR Portal Base URL'],
                    'api_key' => ['type' => Setting::TYPE_SECRET, 'label' => 'Admission OTR API Key'],
                    'timeout' => ['type' => Setting::TYPE_NUMBER, 'label' => 'Connection Request Timeout (Seconds)'],
                    'auto_sync_enabled' => ['type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Automated Scheduled Sync'],
                    'auto_sync_frequency' => ['type' => Setting::TYPE_SELECT, 'label' => 'Automated Sync Frequency', 'options' => ['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly']],
                    'default_course_id' => ['type' => Setting::TYPE_NUMBER, 'label' => 'Default Academic Course for Imports'],
                    'default_batch_id' => ['type' => Setting::TYPE_NUMBER, 'label' => 'Default Academic Batch for Imports'],
                    'download_documents' => ['type' => Setting::TYPE_BOOLEAN, 'label' => 'Download and Store Document Files to MIS Storage'],
                    'update_existing_profiles' => ['type' => Setting::TYPE_BOOLEAN, 'label' => 'Update Student Dossier If Candidate Already Exists'],
                ];

                $oldValues = [];
                $newValues = [];

                foreach ($definitions as $key => $meta) {
                    $fullKey = "otr.{$key}";
                    $val = $this->form[$key] ?? null;

                    $setting = $existing[$fullKey] ?? new Setting([
                        'key' => $fullKey,
                        'group' => 'otr',
                    ]);

                    $oldValues[$key] = $setting->exists ? $setting->rawValue() : null;
                    $newValues[$key] = ($key === 'api_key' && !empty($val)) ? '********' : $val;

                    $setting->type = $meta['type'];
                    $setting->label = $meta['label'];
                    if (isset($meta['options'])) {
                        $setting->options = $meta['options'];
                    }

                    if ($setting->type === Setting::TYPE_BOOLEAN) {
                        $setting->value = (bool) $val ? '1' : '0';
                    } else {
                        $setting->value = $val !== null ? (string) $val : null;
                    }

                    $setting->save();
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'settings_updated',
                    description: 'Updated Admission OTR Integration settings and credentials',
                    oldValues: $oldValues,
                    newValues: $newValues
                );
            });

            Toast::dispatch($this, 'success', __('Admission OTR Integration settings saved successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save settings: :err', ['err' => $e->getMessage()]));
        }
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::orderBy('academic_year', 'desc')->orderBy('name')->get();
    }

    #[Computed]
    public function stats()
    {
        return OtrSyncService::getDashboardStatistics();
    }
}; ?>

<div class="space-y-6 max-w-6xl">
    {{-- Page Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border pb-5">
        <div>
            <div class="flex items-center gap-3">
                <span class="p-2.5 rounded-xl bg-primary/10 text-primary">
                    <x-icon name="link" class="h-6 w-6" />
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Admission OTR Integration Settings') }}</h1>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Manage API credentials, automatic synchronization, course mappings, and candidate import policies for Admission OTR.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <a
                href="{{ route('admin.admissions.index') }}"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-xs"
            >
                <x-icon name="arrow-right" class="h-4 w-4 rotate-180" />
                {{ __('Back to Admissions Hub') }}
            </a>
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-sm disabled:opacity-50"
            >
                <x-icon name="check" class="h-4 w-4" wire:loading.remove wire:target="save" />
                <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="save" />
                {{ __('Save Settings') }}
            </button>
        </div>
    </div>

    {{-- System Status Banner --}}
    <div class="p-4 rounded-xl border {{ $form['enabled'] ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-800 dark:text-emerald-300' : 'bg-amber-500/10 border-amber-500/20 text-amber-800 dark:text-amber-300' }} flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="p-2 rounded-lg {{ $form['enabled'] ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/20 text-amber-600 dark:text-amber-400' }}">
                <x-icon name="{{ $form['enabled'] ? 'check' : 'alert-circle' }}" class="h-5 w-5" />
            </span>
            <div>
                <p class="text-sm font-semibold">
                    {{ $form['enabled'] ? __('Admission OTR Integration Feature is Active') : __('Admission OTR Integration Feature is Disabled') }}
                </p>
                <p class="text-xs opacity-90 mt-0.5">
                    {{ $form['enabled']
                        ? __('MIS is configured to connect to Admission OTR for candidate lookups, candidate dossiers, and live student imports.')
                        : __('Both systems are completely decoupled. MIS operates standalone without any external requests or dependency on Admission OTR.') }}
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="$toggle('form.enabled')"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg border {{ $form['enabled'] ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300 hover:bg-amber-500/20' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/20' }} transition-colors"
            >
                {{ $form['enabled'] ? __('Disable Feature') : __('Enable Feature') }}
            </button>
        </div>
    </div>

    {{-- Form Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left 2 Columns: Configuration Form --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Card 1: Connection & API Credentials --}}
            <div class="rounded-2xl border border-border bg-card p-6 shadow-xs space-y-5">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="cloud" class="h-4 w-4 text-primary" />
                        <h2 class="text-sm font-bold text-foreground uppercase tracking-wider">{{ __('OTR Connection & Credentials') }}</h2>
                    </div>
                    <span class="text-xs text-muted-foreground">{{ __('REST API v1 Gateway') }}</span>
                </div>

                <div class="space-y-4">
                    {{-- Base URL --}}
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1.5">
                            {{ __('Admission OTR Portal Base URL') }} <span class="text-destructive">*</span>
                        </label>
                        <div class="relative">
                            <x-icon name="link" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" />
                            <input
                                type="url"
                                wire:model.defer="form.base_url"
                                placeholder="https://admission.sntcssc.in or http://127.0.0.1:8000"
                                class="h-10 w-full rounded-lg border border-input bg-transparent pl-9 pr-3 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20"
                            />
                        </div>
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {{ __('The root domain or origin where Admission OTR is deployed (without trailing slash).') }}
                        </p>
                    </div>

                    {{-- API Key --}}
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1.5">
                            {{ __('Admission OTR API Key (Bearer Token)') }} <span class="text-destructive">*</span>
                        </label>
                        <div class="relative">
                            <x-icon name="database" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" />
                            <input
                                type="{{ $revealApiKey ? 'text' : 'password' }}"
                                wire:model.defer="form.api_key"
                                placeholder="adm_live_..."
                                class="h-10 w-full rounded-lg border border-input bg-transparent pl-9 pr-10 font-mono text-xs shadow-xs outline-none placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20"
                            />
                            <button
                                type="button"
                                wire:click="$toggle('revealApiKey')"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-muted-foreground hover:text-foreground"
                                title="{{ $revealApiKey ? __('Hide Secret') : __('Reveal Secret') }}"
                            >
                                <x-icon name="{{ $revealApiKey ? 'lock' : 'eye' }}" class="h-4 w-4" />
                            </button>
                        </div>
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {{ __('Encrypted at rest using AES-256. Generated from Admission OTR Admin > Platform Management > API Keys.') }}
                        </p>
                    </div>

                    {{-- Timeout & Controls --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1.5">
                                {{ __('Request Timeout (Seconds)') }}
                            </label>
                            <input
                                type="number"
                                wire:model.defer="form.timeout"
                                min="5"
                                max="120"
                                class="h-10 w-full rounded-lg border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                            />
                        </div>

                        <div class="flex items-end">
                            <button
                                type="button"
                                wire:click="testConnection"
                                wire:loading.attr="disabled"
                                class="h-10 w-full inline-flex items-center justify-center gap-2 px-4 rounded-lg border border-primary/30 bg-primary/10 text-primary hover:bg-primary/20 text-xs font-semibold transition-colors disabled:opacity-50"
                            >
                                <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="testConnection" />
                                <x-icon name="activity" class="h-4 w-4" wire:loading.remove wire:target="testConnection" />
                                <span>{{ __('Test API Connection') }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Live Test Connection Result Box --}}
                @if($testResult)
                    <div class="mt-4 p-4 rounded-xl border {{ $testResult['success'] ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-950 dark:text-emerald-200' : 'bg-rose-500/10 border-rose-500/30 text-rose-950 dark:text-rose-200' }} animate-fade-in text-xs space-y-2">
                        <div class="flex items-center justify-between font-bold">
                            <span class="flex items-center gap-1.5">
                                <x-icon name="{{ $testResult['success'] ? 'check' : 'alert-circle' }}" class="h-4 w-4" />
                                {{ $testResult['success'] ? __('Connection Verified (HTTP 200)') : __('Connection Failed') }}
                            </span>
                            <span class="font-mono text-[11px] px-2 py-0.5 rounded bg-card/60 border border-border">
                                {{ $testResult['latency_ms'] }} ms
                            </span>
                        </div>
                        <p class="leading-relaxed opacity-90">{{ $testResult['message'] }}</p>

                        @if(!empty($testResult['data']))
                            <div class="mt-2 pt-2 border-t border-border/40 grid grid-cols-2 gap-2 text-[11px]">
                                <div><span class="opacity-70">{{ __('Key Name:') }}</span> <strong>{{ $testResult['data']['api_key']['name'] ?? 'N/A' }}</strong></div>
                                <div><span class="opacity-70">{{ __('Preview:') }}</span> <strong class="font-mono">{{ $testResult['data']['api_key']['preview'] ?? 'N/A' }}</strong></div>
                                <div><span class="opacity-70">{{ __('Environment:') }}</span> <strong class="uppercase">{{ $testResult['data']['api_key']['environment'] ?? 'N/A' }}</strong></div>
                                <div><span class="opacity-70">{{ __('Server Time:') }}</span> <strong>{{ $testResult['data']['server_time'] ?? 'N/A' }}</strong></div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Card 2: Default Mappings & Import Policies --}}
            <div class="rounded-2xl border border-border bg-card p-6 shadow-xs space-y-5">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="graduation-cap" class="h-4 w-4 text-primary" />
                        <h2 class="text-sm font-bold text-foreground uppercase tracking-wider">{{ __('Default Mappings & Import Policies') }}</h2>
                    </div>
                    <span class="text-xs text-muted-foreground">{{ __('Enrollment Defaults') }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Default Course --}}
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1.5">
                            {{ __('Default Course for Imports') }}
                        </label>
                        <select
                            wire:model.defer="form.default_course_id"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-xs shadow-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 text-foreground"
                        >
                            <option value="">{{ __('-- Select Default Course --') }}</option>
                            @foreach($this->courses as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {{ __('Pre-selected course on the admissions import screen.') }}
                        </p>
                    </div>

                    {{-- Default Batch --}}
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1.5">
                            {{ __('Default Batch for Imports') }}
                        </label>
                        <select
                            wire:model.defer="form.default_batch_id"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-xs shadow-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 text-foreground"
                        >
                            <option value="">{{ __('-- Select Default Batch --') }}</option>
                            @foreach($this->batches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {{ __('Pre-selected academic batch on the admissions import screen.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4 pt-2 border-t border-border">
                    {{-- Toggle: Update Existing Profiles --}}
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.defer="form.update_existing_profiles"
                            class="mt-0.5 rounded border-input text-primary focus:ring-primary/20 h-4 w-4"
                        />
                        <div>
                            <span class="text-xs font-semibold text-foreground block">{{ __('Update Student Dossier If Candidate Already Exists') }}</span>
                            <span class="text-[11px] text-muted-foreground block">
                                {{ __('When importing an admitted candidate who already has a user account in MIS, synchronize their StudentProfile, addresses, and educational qualifications with the latest OTR form snapshot.') }}
                            </span>
                        </div>
                    </label>

                    {{-- Toggle: Download Documents --}}
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.defer="form.download_documents"
                            class="mt-0.5 rounded border-input text-primary focus:ring-primary/20 h-4 w-4"
                        />
                        <div>
                            <span class="text-xs font-semibold text-foreground block">{{ __('Download & Mirror Document Files to Local Storage') }}</span>
                            <span class="text-[11px] text-muted-foreground block">
                                {{ __('When enabled, binary document files (photo, signature, caste certificate, marksheet) are downloaded from OTR and permanently saved into MIS storage. If disabled, external file URLs are referenced.') }}
                            </span>
                        </div>
                    </label>

                    {{-- Toggle: Auto Sync --}}
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.defer="form.auto_sync_enabled"
                            class="mt-0.5 rounded border-input text-primary focus:ring-primary/20 h-4 w-4"
                        />
                        <div>
                            <span class="text-xs font-semibold text-foreground block">{{ __('Enable Automated Scheduled Background Sync') }}</span>
                            <span class="text-[11px] text-muted-foreground block">
                                {{ __('Periodically trigger automated fetch and reconciliation via MIS cron jobs.') }}
                            </span>
                        </div>
                    </label>

                    @if($form['auto_sync_enabled'])
                        <div class="pl-7 pt-2 max-w-xs">
                            <label class="block text-xs font-semibold text-foreground mb-1">
                                {{ __('Sync Frequency') }}
                            </label>
                            <select
                                wire:model.defer="form.auto_sync_frequency"
                                class="h-9 w-full rounded-lg border border-input bg-card px-3 text-xs shadow-xs outline-none focus:border-primary text-foreground"
                            >
                                <option value="hourly">{{ __('Hourly') }}</option>
                                <option value="daily">{{ __('Daily (Midnight)') }}</option>
                                <option value="weekly">{{ __('Weekly') }}</option>
                            </select>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Right Column: Telemetry & Architecture Quick Guide --}}
        <div class="space-y-6">
            {{-- Statistics Card --}}
            <div class="rounded-2xl border border-border bg-card p-5 shadow-xs space-y-4">
                <div class="flex items-center gap-2 border-b border-border pb-3">
                    <x-icon name="bar-chart-2" class="h-4 w-4 text-primary" />
                    <h3 class="text-xs font-bold text-foreground uppercase tracking-wider">{{ __('Integration Telemetry') }}</h3>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">{{ __('Total Imports Logged') }}</span>
                        <span class="font-bold font-mono">{{ $this->stats['total_imported_records'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">{{ __('New Students Created') }}</span>
                        <span class="font-bold font-mono text-emerald-600 dark:text-emerald-400">{{ $this->stats['new_students_created'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">{{ __('Existing Students Enrolled') }}</span>
                        <span class="font-bold font-mono text-amber-600 dark:text-amber-400">{{ $this->stats['existing_students_enrolled'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">{{ __('Already Enrolled (Skipped)') }}</span>
                        <span class="font-bold font-mono text-muted-foreground">{{ $this->stats['already_enrolled_count'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">{{ __('Failed Imports') }}</span>
                        <span class="font-bold font-mono text-rose-600 dark:text-rose-400">{{ $this->stats['failed_imports_count'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs pt-2 border-t border-border">
                        <span class="text-muted-foreground">{{ __('Last Synchronization') }}</span>
                        <span class="text-[11px] font-medium text-foreground">{{ $this->stats['last_sync_at'] ?? __('Never') }}</span>
                    </div>
                </div>
            </div>

            {{-- Security & Architecture Banner --}}
            <div class="rounded-2xl border border-border bg-muted/40 p-5 space-y-3 text-xs">
                <div class="flex items-center gap-2 font-bold text-foreground">
                    <x-icon name="check-circle" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                    <span>{{ __('Decoupled Architecture') }}</span>
                </div>
                <p class="text-muted-foreground leading-relaxed text-[11px]">
                    {{ __('Both SNTCSSC MIS and Admission OTR portals operate across isolated databases and independent domains. All data synchronization is executed securely over authenticated REST endpoints with rate limiting, IP controls, SHA-256 API key hashing, and full database transaction rollbacks.') }}
                </p>
                <div class="pt-2">
                    <a
                        href="{{ route('admin.admissions.index') }}"
                        class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-card border border-border text-foreground hover:bg-muted transition-colors shadow-xs"
                    >
                        <x-icon name="users" class="h-4 w-4 text-primary" />
                        <span>{{ __('Open Admissions Feed & Import') }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

