<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\StorageService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Storage & S3 Cloud Settings')] class extends Component {
    use WithFileUploads;

    public array $form = [
        'default_disk' => 'public',
        'provider' => 'aws',
        's3_key' => '',
        's3_secret' => '',
        's3_region' => 'us-east-1',
        's3_bucket' => '',
        's3_endpoint' => '',
        's3_url' => '',
        's3_use_path_style_endpoint' => false,
        's3_visibility' => 'public',
        'max_upload_size_mb' => 25,
        'allowed_file_types' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,svg,zip,mp4',
        'auto_backup_to_s3' => false,
    ];

    public bool $revealSecret = false;
    public bool $isTestingConnection = false;
    public ?array $testResult = null;

    // File Explorer state
    public string $activeExplorerDisk = 'public';
    public string $explorerSearch = '';
    public $testUploadFile = null;

    // Interactive Modal Confirmations
    public bool $showDeleteModal = false;
    public ?string $fileToDeletePath = null;
    public ?string $fileToDeleteDisk = null;

    public function mount(): void
    {
        $this->loadSettings();
        $this->activeExplorerDisk = $this->form['default_disk'] === 's3' ? 's3' : 'public';
    }

    public function loadSettings(): void
    {
        $settings = Setting::query()->where('group', 'storage')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "storage.{$key}";
            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_NUMBER) {
                    $this->form[$key] = (int) $setting->typed();
                } elseif ($key === 's3_secret' && $setting->type === Setting::TYPE_SECRET) {
                    // Keep input empty if not changing, but indicate whether it is configured
                    $this->form[$key] = '';
                } else {
                    $this->form[$key] = (string) ($setting->rawValue() ?? $default);
                }
            }
        }
    }

    /**
     * Preset card clicked: select provider and apply recommended defaults.
     */
    public function selectProvider(string $provider): void
    {
        $presets = StorageService::getProviderPresets();
        if (! isset($presets[$provider])) {
            return;
        }

        $this->form['provider'] = $provider;
        $preset = $presets[$provider];

        if (empty($this->form['s3_region']) || $this->form['s3_region'] === 'us-east-1' || $this->form['s3_region'] === 'auto') {
            $this->form['s3_region'] = $preset['default_region'];
        }

        $this->form['s3_use_path_style_endpoint'] = (bool) $preset['path_style'];

        Toast::dispatch($this, 'info', __("Applied :provider configuration template.", ['provider' => $preset['name']]));
    }

    public function toggleSecret(): void
    {
        $this->revealSecret = ! $this->revealSecret;
    }

    /**
     * Run an isolated real-time read/write probe test to verify cloud storage connectivity.
     */
    public function testConnection(): void
    {
        $this->isTestingConnection = true;
        $this->testResult = null;

        $overrides = [
            'provider' => $this->form['provider'],
            's3_key' => $this->form['s3_key'] ?: Setting::get('storage.s3_key'),
            's3_secret' => $this->form['s3_secret'] ?: Setting::get('storage.s3_secret'),
            's3_region' => $this->form['s3_region'],
            's3_bucket' => $this->form['s3_bucket'],
            's3_endpoint' => $this->form['s3_endpoint'] ?: null,
            's3_url' => $this->form['s3_url'] ?: null,
            's3_use_path_style_endpoint' => $this->form['s3_use_path_style_endpoint'],
        ];

        $this->testResult = StorageService::testConnection($overrides);
        $this->isTestingConnection = false;

        if ($this->testResult['success']) {
            Toast::dispatch($this, 'success', $this->testResult['message']);
        } else {
            Toast::dispatch($this, 'error', $this->testResult['message']);
        }

        AuditLogService::log(
            event: 'storage_connection_tested',
            description: "Tested storage connectivity to bucket '{$this->form['s3_bucket']}' ({$this->form['provider']}): ".($this->testResult['success'] ? 'SUCCESS' : 'FAILED'),
            newValues: [
                'provider' => $this->form['provider'],
                'bucket' => $this->form['s3_bucket'],
                'success' => $this->testResult['success'],
                'latency_ms' => $this->testResult['latency_ms'] ?? 0,
            ],
            userId: auth()->id()
        );
    }

    /**
     * Save storage configuration to database with transactions and audit logging.
     */
    public function save(): void
    {
        $this->validate([
            'form.default_disk' => ['required', 'string', 'in:public,s3,local'],
            'form.provider' => ['required', 'string', 'in:aws,r2,minio,wasabi,do_spaces,backblaze,custom'],
            'form.s3_key' => ['nullable', 'string', 'max:255'],
            'form.s3_secret' => ['nullable', 'string', 'max:255'],
            'form.s3_region' => ['nullable', 'string', 'max:100'],
            'form.s3_bucket' => ['nullable', 'string', 'max:255'],
            'form.s3_endpoint' => ['nullable', 'string', 'max:500'],
            'form.s3_url' => ['nullable', 'string', 'max:500'],
            'form.s3_use_path_style_endpoint' => ['boolean'],
            'form.s3_visibility' => ['required', 'string', 'in:public,private'],
            'form.max_upload_size_mb' => ['required', 'numeric', 'min:1', 'max:500'],
            'form.allowed_file_types' => ['required', 'string', 'max:500'],
            'form.auto_backup_to_s3' => ['boolean'],
        ]);

        if ($this->form['default_disk'] === 's3' && empty($this->form['s3_bucket'])) {
            $this->addError('form.s3_bucket', __('Bucket name is required when Amazon S3 is selected as the default storage disk.'));
            return;
        }

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "storage.{$key}";
                    $type = match ($key) {
                        's3_use_path_style_endpoint', 'auto_backup_to_s3' => Setting::TYPE_BOOLEAN,
                        'max_upload_size_mb' => Setting::TYPE_NUMBER,
                        's3_secret' => Setting::TYPE_SECRET,
                        'default_disk', 'provider', 's3_visibility' => Setting::TYPE_SELECT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'storage';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));

                    // Only update secret if user typed a new non-empty value
                    if ($key === 's3_secret') {
                        if (trim((string) $value) !== '') {
                            $setting->value = (string) $value;
                        }
                    } else {
                        $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    }

                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();
                StorageService::applyDynamicConfig();
            });

            AuditLogService::log(
                event: 'storage_settings_updated',
                description: "Updated storage configuration. Default disk: {$this->form['default_disk']}, Provider: {$this->form['provider']}.",
                newValues: [
                    'default_disk' => $this->form['default_disk'],
                    'provider' => $this->form['provider'],
                    'bucket' => $this->form['s3_bucket'],
                    'region' => $this->form['s3_region'],
                ],
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Storage and cloud configuration saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save storage settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save storage settings: ').$e->getMessage());
        }
    }

    /**
     * Upload a test file to verify real file storage and URL resolution.
     */
    public function uploadTestFile(): void
    {
        $this->validate([
            'testUploadFile' => ['required', 'file', 'max:'.($this->form['max_upload_size_mb'] * 1024)],
        ]);

        try {
            $result = StorageService::uploadFile(
                file: $this->testUploadFile,
                folder: 'test_uploads',
                disk: $this->activeExplorerDisk,
                actor: auth()->user()
            );

            $this->testUploadFile = null;
            Toast::dispatch($this, 'success', __("Uploaded test file ':name' to :disk successfully.", [
                'name' => basename($result['path']),
                'disk' => strtoupper($result['disk']),
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Upload failed: ').$e->getMessage());
        }
    }

    /**
     * Request deletion of a file using Interactive Modal (No browser alert/confirm).
     */
    public function promptDeleteFile(string $path, string $disk): void
    {
        $this->fileToDeletePath = $path;
        $this->fileToDeleteDisk = $disk;
        $this->showDeleteModal = true;
    }

    public function cancelDeleteFile(): void
    {
        $this->fileToDeletePath = null;
        $this->fileToDeleteDisk = null;
        $this->showDeleteModal = false;
    }

    public function confirmDeleteFile(): void
    {
        if (! $this->fileToDeletePath) {
            $this->showDeleteModal = false;
            return;
        }

        try {
            $deleted = StorageService::deleteFile($this->fileToDeletePath, $this->fileToDeleteDisk ?? 'public', auth()->user());

            if ($deleted) {
                Toast::dispatch($this, 'success', __("File ':name' was deleted successfully.", ['name' => basename($this->fileToDeletePath)]));
            } else {
                Toast::dispatch($this, 'error', __('Could not delete file or file does not exist on disk.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Delete error: ').$e->getMessage());
        } finally {
            $this->fileToDeletePath = null;
            $this->fileToDeleteDisk = null;
            $this->showDeleteModal = false;
        }
    }

    #[Computed]
    public function statistics(): array
    {
        return StorageService::getStorageStatistics();
    }

    #[Computed]
    public function providerPresets(): array
    {
        return StorageService::getProviderPresets();
    }

    #[Computed]
    public function explorerFiles(): array
    {
        $files = StorageService::listFiles($this->activeExplorerDisk, '', 50);

        if ($this->explorerSearch !== '') {
            $search = strtolower($this->explorerSearch);
            $files = array_filter($files, fn ($file) => str_contains(strtolower($file['name']), $search) || str_contains(strtolower($file['extension']), $search));
        }

        return $files;
    }

    #[Computed]
    public function isSecretConfigured(): bool
    {
        $setting = Setting::query()->where('key', 'storage.s3_secret')->first();
        return $setting && ! empty($setting->rawValue());
    }
};
?>

<div class="space-y-6">
    {{-- Header & Sub-Navigation --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-primary/10 text-primary">
                    <x-icon name="hard-drive" class="h-6 w-6"/>
                </span>
                <span>{{ __('Storage & S3 Cloud Configuration') }}</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage file storage drivers, Amazon S3 and S3-compatible cloud providers, upload policies, and real-time disk telemetry.') }}
            </p>
        </div>

        <div class="flex items-center gap-2.5 shrink-0">
            <x-ui.button
                variant="outline"
                wire:click="testConnection"
                wire:loading.attr="disabled"
                class="flex items-center gap-2 text-xs"
            >
                <x-icon name="wifi" class="h-4 w-4" wire:loading.remove wire:target="testConnection"/>
                <svg wire:loading wire:target="testConnection" class="animate-spin h-4 w-4 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ __('Test S3 Connection') }}</span>
            </x-ui.button>

            <x-ui.button
                variant="primary"
                wire:click="save"
                wire:loading.attr="disabled"
                class="flex items-center gap-2 text-xs font-semibold shadow-sm"
            >
                <x-icon name="check" class="h-4 w-4" wire:loading.remove wire:target="save"/>
                <svg wire:loading wire:target="save" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ __('Save Configurations') }}</span>
            </x-ui.button>
        </div>
    </div>

    {{-- Sub Navigation Tabs --}}
    <x-settings-nav active="storage" />

    {{-- Live Storage Telemetry Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Card 1: Active Storage Disk --}}
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs transition-all hover:border-primary/40">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Active Storage Disk') }}</span>
                <span class="p-2 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="hard-drive" class="h-4 w-4"/>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-xl font-bold uppercase text-foreground">{{ $this->statistics['default_disk'] }}</span>
                <x-ui.badge variant="{{ $this->statistics['default_disk'] === 's3' ? 'success' : 'default' }}">
                    {{ $this->statistics['default_disk'] === 's3' ? __('Cloud Active') : __('Local Disk') }}
                </x-ui.badge>
            </div>
            <p class="text-xs text-muted-foreground mt-1.5 truncate">
                {{ $this->statistics['default_disk'] === 's3' ? __('Files write directly to S3 Cloud') : __('Files write to local storage/app/public') }}
            </p>
        </div>

        {{-- Card 2: Cloud Connectivity --}}
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs transition-all hover:border-primary/40">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Cloud Provider') }}</span>
                <span class="p-2 rounded-lg bg-blue-500/10 text-blue-500">
                    <x-icon name="cloud" class="h-4 w-4"/>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-xl font-bold text-foreground truncate">{{ $this->statistics['provider_name'] }}</span>
            </div>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
                @if($this->statistics['s3_configured'])
                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-emerald-600 dark:text-emerald-400 font-medium truncate">{{ $this->statistics['s3_bucket'] }} ({{ $this->statistics['s3_region'] }})</span>
                @else
                    <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
                    <span class="text-amber-600 dark:text-amber-400 font-medium">{{ __('Credentials Not Configured') }}</span>
                @endif
            </div>
        </div>

        {{-- Card 3: Storage Size & Files --}}
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs transition-all hover:border-primary/40">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Local Storage') }}</span>
                <span class="p-2 rounded-lg bg-emerald-500/10 text-emerald-500">
                    <x-icon name="database" class="h-4 w-4"/>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-xl font-bold text-foreground">{{ $this->statistics['total_size_formatted'] }}</span>
                <span class="text-xs text-muted-foreground">({{ number_format($this->statistics['total_files']) }} {{ __('files') }})</span>
            </div>
            <p class="text-xs text-muted-foreground mt-1.5 truncate">
                {{ __('Public') }}: {{ $this->statistics['public_storage']['size_formatted'] }} • {{ __('Private') }}: {{ $this->statistics['private_storage']['size_formatted'] }}
            </p>
        </div>

        {{-- Card 4: Upload Restrictions --}}
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs transition-all hover:border-primary/40">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Max Upload Size') }}</span>
                <span class="p-2 rounded-lg bg-purple-500/10 text-purple-500">
                    <x-icon name="cloud-upload" class="h-4 w-4"/>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-xl font-bold text-foreground">{{ $this->statistics['policy']['max_upload_mb'] }} MB</span>
                <x-ui.badge variant="secondary">
                    {{ ucfirst($this->statistics['policy']['visibility']) }}
                </x-ui.badge>
            </div>
            <p class="text-xs text-muted-foreground mt-1.5 truncate">
                {{ count($this->statistics['policy']['allowed_extensions']) }} {{ __('file types allowed') }}
            </p>
        </div>
    </div>

    {{-- Diagnostic Test Result Alert (if run) --}}
    @if($testResult)
        <div class="p-4 rounded-xl border {{ $testResult['success'] ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-900 dark:text-emerald-200' : 'border-rose-500/30 bg-rose-500/5 text-rose-900 dark:text-rose-200' }} shadow-xs transition-all">
            <div class="flex items-start gap-3">
                <span class="p-1 rounded-full {{ $testResult['success'] ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/20 text-rose-600 dark:text-rose-400' }} shrink-0 mt-0.5">
                    <x-icon :name="$testResult['success'] ? 'check' : 'alert-circle'" class="h-4 w-4"/>
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold">{{ $testResult['success'] ? __('Connection Test Passed') : __('Connection Test Failed') }}</h4>
                        <span class="text-xs font-mono px-2 py-0.5 rounded bg-background/50 border border-border">
                            {{ $testResult['latency_ms'] }} ms
                        </span>
                    </div>
                    <p class="text-xs mt-1 leading-relaxed">{{ $testResult['message'] }}</p>

                    @if(!empty($testResult['details']['diagnostic_tip']))
                        <div class="mt-2 text-xs p-2 rounded-lg bg-background/60 border border-border/80 flex items-center gap-2">
                            <x-icon name="circle-help" class="h-3.5 w-3.5 text-primary shrink-0"/>
                            <span><strong>{{ __('Troubleshooting Tip') }}:</strong> {{ $testResult['details']['diagnostic_tip'] }}</span>
                        </div>
                    @endif

                    @if(!empty($testResult['probe_url']))
                        <p class="text-xs mt-1.5 text-muted-foreground font-mono truncate">
                            {{ __('Resolved Probe URL') }}: <a href="{{ $testResult['probe_url'] }}" target="_blank" class="underline hover:text-primary">{{ $testResult['probe_url'] }}</a>
                        </p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Main Settings Form Section --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left 2 Columns: Provider Selection & Credentials --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Provider Quick Presets --}}
            <div class="p-5 rounded-2xl border border-border bg-card shadow-xs space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                            <x-icon name="server" class="h-4 w-4 text-primary"/>
                            <span>{{ __('Cloud Storage Provider') }}</span>
                        </h2>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Choose an S3-compatible cloud infrastructure provider or configure custom endpoints.') }}
                        </p>
                    </div>
                </div>

                {{-- Preset Cards Grid --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    @foreach($this->providerPresets as $key => $preset)
                        @php
                            $isSelected = $form['provider'] === $key;
                        @endphp
                        <button
                            type="button"
                            wire:click="selectProvider('{{ $key }}')"
                            class="flex flex-col text-left p-3.5 rounded-xl border text-xs transition-all relative group {{ $isSelected ? 'border-primary ring-2 ring-primary/20 bg-primary/5 text-primary font-medium' : 'border-border bg-card hover:border-primary/40 hover:bg-muted/40 text-foreground' }}"
                        >
                            <div class="flex items-center justify-between w-full mb-1.5">
                                <span class="p-1.5 rounded-lg {{ $isSelected ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground group-hover:text-foreground' }}">
                                    <x-icon :name="$preset['icon']" class="h-4 w-4"/>
                                </span>
                                @if($isSelected)
                                    <x-icon name="badge-check" class="h-4 w-4 text-primary"/>
                                @endif
                            </div>
                            <span class="font-semibold truncate">{{ $preset['name'] }}</span>
                            <span class="text-[10px] text-muted-foreground line-clamp-2 mt-0.5">
                                {{ $preset['description'] }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- S3 Configuration Parameters Form --}}
            <div class="p-5 rounded-2xl border border-border bg-card shadow-xs space-y-5">
                <div>
                    <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="settings" class="h-4 w-4 text-primary"/>
                        <span>{{ __('Connection Credentials & Endpoint') }}</span>
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Configure authentication keys, target bucket, region, and optional custom endpoint URLs.') }}
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Default Storage Disk Selector --}}
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-foreground mb-1.5">
                            {{ __('Default Storage Disk') }} <span class="text-rose-500">*</span>
                        </label>
                        <select
                            wire:model.defer="form.default_disk"
                            class="w-full text-xs rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                        >
                            <option value="public">{{ __('Local Public Disk (storage/app/public) — Stored locally on web server') }}</option>
                            <option value="s3">{{ __('Amazon S3 / S3-Compatible Cloud Storage (Remote Cloud Bucket)') }}</option>
                            <option value="local">{{ __('Local Private Disk (storage/app/private) — Internal secured files') }}</option>
                        </select>
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {{ __('Determines where all user uploads, documents, avatars, study materials, and general media are written by default.') }}
                        </p>
                        @error('form.default_disk') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Target Bucket Name --}}
                    <div>
                        <label class="block text-xs font-medium text-foreground mb-1.5">
                            {{ __('Target S3 Bucket Name') }} <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                type="text"
                                wire:model.defer="form.s3_bucket"
                                placeholder="e.g. sntcssc-production-storage"
                                class="w-full text-xs rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                            />
                        </div>
                        @error('form.s3_bucket') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Region Selector --}}
                    <div>
                        <label class="block text-xs font-medium text-foreground mb-1.5">
                            {{ __('Datacenter / Region') }} <span class="text-rose-500">*</span>
                        </label>
                        @php
                            $currentPreset = $this->providerPresets[$form['provider']] ?? null;
                            $regions = $currentPreset['regions'] ?? ['us-east-1' => 'US East (N. Virginia)'];
                        @endphp
                        <input
                            type="text"
                            wire:model.defer="form.s3_region"
                            list="provider-regions-list"
                            placeholder="e.g. ap-south-1, us-east-1, auto"
                            class="w-full text-xs rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                        />
                        <datalist id="provider-regions-list">
                            @foreach($regions as $rCode => $rLabel)
                                <option value="{{ $rCode }}">{{ $rLabel }}</option>
                            @endforeach
                        </datalist>
                        @error('form.s3_region') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Access Key ID --}}
                    <div>
                        <label class="block text-xs font-medium text-foreground mb-1.5">
                            {{ __('Access Key ID') }}
                        </label>
                        <input
                            type="text"
                            wire:model.defer="form.s3_key"
                            placeholder="e.g. AKIAIOSFODNN7EXAMPLE"
                            class="w-full text-xs font-mono rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                        />
                        @error('form.s3_key') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Secret Access Key --}}
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-medium text-foreground">
                                {{ __('Secret Access Key') }}
                            </label>
                            @if($this->isSecretConfigured)
                                <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                                    <x-icon name="badge-check" class="h-3 w-3"/>
                                    {{ __('Secret Saved') }}
                                </span>
                            @endif
                        </div>
                        <div class="relative">
                            <input
                                type="{{ $revealSecret ? 'text' : 'password' }}"
                                wire:model.defer="form.s3_secret"
                                placeholder="{{ $this->isSecretConfigured ? '•••••••••••••••••••••••• (Leave blank to keep existing)' : 'Enter Secret Access Key' }}"
                                class="w-full text-xs font-mono rounded-lg border-border bg-background pl-3 pr-9 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                            />
                            <button
                                type="button"
                                wire:click="toggleSecret"
                                class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted-foreground hover:text-foreground"
                                title="{{ $revealSecret ? __('Hide Secret') : __('Reveal Secret') }}"
                            >
                                <x-icon :name="$revealSecret ? 'eye-off' : 'eye'" class="h-4 w-4"/>
                            </button>
                        </div>
                        @error('form.s3_secret') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Custom Endpoint URL (MinIO / R2 / Wasabi / DO Spaces) --}}
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-foreground mb-1.5">
                            {{ __('Custom Storage Endpoint URL') }}
                            <span class="text-muted-foreground font-normal">({{ __('Required for MinIO, Cloudflare R2, Wasabi, and DigitalOcean Spaces') }})</span>
                        </label>
                        <input
                            type="url"
                            wire:model.defer="form.s3_endpoint"
                            placeholder="{{ $currentPreset['endpoint_placeholder'] ?? 'https://s3.amazonaws.com' }}"
                            class="w-full text-xs font-mono rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                        />
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {{ __('For Amazon AWS S3, you can leave this blank to use standard regional endpoints.') }}
                        </p>
                        @error('form.s3_endpoint') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Custom Public / CDN Domain URL --}}
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-foreground mb-1.5">
                            {{ __('Custom Public / CDN Domain URL') }}
                            <span class="text-muted-foreground font-normal">({{ __('Optional CloudFront / Cloudflare custom domain') }})</span>
                        </label>
                        <input
                            type="url"
                            wire:model.defer="form.s3_url"
                            placeholder="{{ $currentPreset['url_placeholder'] ?? 'https://cdn.yourdomain.com' }}"
                            class="w-full text-xs font-mono rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                        />
                        <p class="text-[11px] text-muted-foreground mt-1">
                            {{ __('If specified, public file URLs returned across the MIS will use this base URL instead of direct S3 bucket URLs.') }}
                        </p>
                        @error('form.s3_url') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    {{-- Path Style Endpoint Toggle --}}
                    <div class="sm:col-span-2 pt-2 border-t border-border flex items-center justify-between">
                        <div>
                            <span class="text-xs font-semibold text-foreground block">{{ __('Use Path-Style Endpoint') }}</span>
                            <span class="text-[11px] text-muted-foreground block">
                                {{ __('Requests will be formatted as http://endpoint/bucket instead of http://bucket.endpoint. Required for MinIO clusters.') }}
                            </span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.defer="form.s3_use_path_style_endpoint" class="sr-only peer">
                            <div class="w-9 h-5 bg-muted peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                        </label>
                    </div>

                    {{-- Auto Backup to S3 Toggle --}}
                    <div class="sm:col-span-2 pt-3 border-t border-border flex items-center justify-between">
                        <div>
                            <span class="text-xs font-semibold text-foreground block">{{ __('Automatically Store System Backups on S3 Cloud') }}</span>
                            <span class="text-[11px] text-muted-foreground block">
                                {{ __('When enabled, scheduled and manual database/media backup archives will be mirrored directly to your cloud bucket.') }}
                            </span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.defer="form.auto_backup_to_s3" class="sr-only peer">
                            <div class="w-9 h-5 bg-muted peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-border after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right Column: Policies, Upload Limits, & Storage Distribution --}}
        <div class="space-y-6">
            {{-- Upload Policies Card --}}
            <div class="p-5 rounded-2xl border border-border bg-card shadow-xs space-y-4">
                <h3 class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <x-icon name="shield-check" class="h-4 w-4 text-primary"/>
                    <span>{{ __('Upload & Security Policies') }}</span>
                </h3>

                {{-- Max Upload Size --}}
                <div>
                    <label class="block text-xs font-medium text-foreground mb-1.5">
                        {{ __('Max File Size Limit (MB)') }}
                    </label>
                    <div class="relative">
                        <input
                            type="number"
                            min="1"
                            max="500"
                            wire:model.defer="form.max_upload_size_mb"
                            class="w-full text-xs rounded-lg border-border bg-background pl-3 pr-10 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                        />
                        <span class="absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-muted-foreground pointer-events-none">
                            MB
                        </span>
                    </div>
                    @error('form.max_upload_size_mb') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                {{-- Default Upload Visibility --}}
                <div>
                    <label class="block text-xs font-medium text-foreground mb-1.5">
                        {{ __('Default File Visibility') }}
                    </label>
                    <select
                        wire:model.defer="form.s3_visibility"
                        class="w-full text-xs rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs"
                    >
                        <option value="public">{{ __('Public (Direct URLs accessible via browser)') }}</option>
                        <option value="private">{{ __('Private (Requires signed expiring URLs)') }}</option>
                    </select>
                </div>

                {{-- Allowed File Extensions --}}
                <div>
                    <label class="block text-xs font-medium text-foreground mb-1.5">
                        {{ __('Allowed File Extensions (Comma-separated)') }}
                    </label>
                    <textarea
                        rows="3"
                        wire:model.defer="form.allowed_file_types"
                        class="w-full text-xs font-mono rounded-lg border-border bg-background px-3 py-2 text-foreground focus:ring-1 focus:ring-primary focus:border-primary shadow-xs leading-relaxed"
                    ></textarea>
                    <p class="text-[10px] text-muted-foreground mt-1">
                        {{ __('File uploads will be validated against this extension whitelist to prevent malicious script uploads.') }}
                    </p>
                    @error('form.allowed_file_types') <span class="text-[11px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            {{-- Category Breakdown Card --}}
            <div class="p-5 rounded-2xl border border-border bg-card shadow-xs space-y-4">
                <h3 class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <x-icon name="pie-chart" class="h-4 w-4 text-primary"/>
                    <span>{{ __('Local Media Distribution') }}</span>
                </h3>

                <div class="space-y-3">
                    @foreach($this->statistics['public_storage']['categories'] as $catKey => $catData)
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2">
                                <span class="p-1 rounded-md bg-muted text-muted-foreground">
                                    <x-icon :name="$catKey === 'documents' ? 'file-text' : ($catKey === 'images' ? 'images' : ($catKey === 'archives' ? 'archive' : 'hard-drive'))" class="h-3.5 w-3.5"/>
                                </span>
                                <span class="capitalize text-foreground font-medium">{{ __($catKey) }}</span>
                            </div>
                            <div class="text-right">
                                <span class="font-semibold text-foreground">{{ $catData['size_formatted'] }}</span>
                                <span class="text-[10px] text-muted-foreground block">({{ $catData['count'] }} {{ __('files') }})</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- File Explorer & Test Probe Upload Section --}}
    <div class="p-5 rounded-2xl border border-border bg-card shadow-xs space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                    <x-icon name="folder-open" class="h-5 w-5 text-primary"/>
                    <span>{{ __('Storage File Explorer') }}</span>
                </h3>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ __('Browse, inspect, upload test files, or delete files directly from your configured storage disks.') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                {{-- Disk Toggle --}}
                <div class="inline-flex rounded-lg border border-border p-1 bg-muted/40">
                    <button
                        type="button"
                        wire:click="$set('activeExplorerDisk', 'public')"
                        class="px-3 py-1 text-xs rounded-md font-medium transition-all {{ $activeExplorerDisk === 'public' ? 'bg-background shadow-xs text-primary font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('Local Public') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeExplorerDisk', 's3')"
                        class="px-3 py-1 text-xs rounded-md font-medium transition-all {{ $activeExplorerDisk === 's3' ? 'bg-background shadow-xs text-primary font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('Amazon S3 Cloud') }}
                    </button>
                </div>

                {{-- Search Filter --}}
                <div class="relative w-48">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="explorerSearch"
                        placeholder="{{ __('Search files...') }}"
                        class="w-full text-xs rounded-lg border-border bg-background pl-8 pr-3 py-1.5 text-foreground focus:ring-1 focus:ring-primary shadow-xs"
                    />
                    <x-icon name="search" class="h-3.5 w-3.5 text-muted-foreground absolute left-2.5 top-2.5"/>
                </div>
            </div>
        </div>

        {{-- Test File Upload Banner --}}
        <div class="p-3.5 rounded-xl border border-dashed border-border bg-muted/20 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-2.5 text-xs">
                <span class="p-2 rounded-lg bg-primary/10 text-primary shrink-0">
                    <x-icon name="cloud-upload" class="h-4 w-4"/>
                </span>
                <div>
                    <span class="font-medium text-foreground block">{{ __('Upload Test File to Active Disk (:disk)', ['disk' => strtoupper($activeExplorerDisk)]) }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ __('Select an image or document to test end-to-end cloud storage upload and URL generation.') }}</span>
                </div>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto">
                <input
                    type="file"
                    wire:model="testUploadFile"
                    class="text-xs file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
                @if($testUploadFile)
                    <x-ui.button
                        size="sm"
                        variant="primary"
                        wire:click="uploadTestFile"
                        wire:loading.attr="disabled"
                        class="text-xs whitespace-nowrap"
                    >
                        <span wire:loading.remove wire:target="uploadTestFile">{{ __('Upload Now') }}</span>
                        <span wire:loading wire:target="uploadTestFile">{{ __('Uploading...') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </div>
        @error('testUploadFile') <span class="text-xs text-rose-500 block">{{ $message }}</span> @enderror

        {{-- Files List Table --}}
        <div class="overflow-x-auto border border-border rounded-xl">
            <table class="w-full text-xs text-left">
                <thead class="bg-muted/40 text-muted-foreground border-b border-border font-medium">
                    <tr>
                        <th class="py-2.5 px-3.5">{{ __('File Name') }}</th>
                        <th class="py-2.5 px-3.5">{{ __('Size') }}</th>
                        <th class="py-2.5 px-3.5">{{ __('Last Modified') }}</th>
                        <th class="py-2.5 px-3.5">{{ __('Resolved URL') }}</th>
                        <th class="py-2.5 px-3.5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($this->explorerFiles as $file)
                        <tr class="hover:bg-muted/20 transition-colors">
                            <td class="py-2.5 px-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="p-1.5 rounded-lg bg-muted text-muted-foreground shrink-0">
                                        <x-icon :name="$file['is_image'] ? 'images' : 'file-text'" class="h-4 w-4"/>
                                    </span>
                                    <div class="min-w-0 max-w-xs truncate">
                                        <span class="font-medium text-foreground block truncate" title="{{ $file['name'] }}">{{ $file['name'] }}</span>
                                        <span class="text-[10px] text-muted-foreground font-mono truncate block">{{ $file['path'] }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 px-3.5 whitespace-nowrap text-muted-foreground">
                                {{ $file['size_formatted'] }}
                            </td>
                            <td class="py-2.5 px-3.5 whitespace-nowrap text-muted-foreground">
                                {{ $file['last_modified'] }}
                            </td>
                            <td class="py-2.5 px-3.5 max-w-xs truncate">
                                @if($file['url'])
                                    <a
                                        href="{{ $file['url'] }}"
                                        target="_blank"
                                        class="text-primary hover:underline font-mono text-[11px] truncate block"
                                        title="{{ $file['url'] }}"
                                    >
                                        {{ $file['url'] }}
                                    </a>
                                @else
                                    <span class="text-muted-foreground italic text-[11px]">{{ __('Private / No public URL') }}</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3.5 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if($file['url'])
                                        <a
                                            href="{{ $file['url'] }}"
                                            target="_blank"
                                            class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                                            title="{{ __('Open in new tab') }}"
                                        >
                                            <x-icon name="external-link" class="h-3.5 w-3.5"/>
                                        </a>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="promptDeleteFile('{{ addslashes($file['path']) }}', '{{ $activeExplorerDisk }}')"
                                        class="p-1 rounded-md text-muted-foreground hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                                        title="{{ __('Delete File') }}"
                                    >
                                        <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-muted-foreground text-xs">
                                <x-icon name="inbox" class="h-8 w-8 mx-auto mb-2 opacity-50"/>
                                <p>{{ __('No files found in :disk storage.', ['disk' => strtoupper($activeExplorerDisk)]) }}</p>
                                @if($activeExplorerDisk === 's3' && ! $this->statistics['s3_configured'])
                                    <p class="text-[11px] text-amber-500 mt-1">{{ __('Configure and test your S3 bucket credentials above to browse cloud files.') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Interactive Modal Dialog for File Deletion (No native browser alert/confirm) --}}
    @if($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs transition-opacity animate-fade-in">
            <div class="w-full max-w-md rounded-2xl bg-card border border-border p-6 shadow-xl space-y-4">
                <div class="flex items-start gap-3">
                    <span class="p-2 rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 shrink-0">
                        <x-icon name="trash-2" class="h-5 w-5"/>
                    </span>
                    <div>
                        <h4 class="text-base font-bold text-foreground">{{ __('Confirm File Deletion') }}</h4>
                        <p class="text-xs text-muted-foreground mt-1 leading-relaxed">
                            {{ __('Are you sure you want to permanently delete this file? This action is irrevocable.') }}
                        </p>
                        <div class="mt-2 p-2 rounded-lg bg-muted/50 border border-border/80 font-mono text-[11px] text-foreground truncate">
                            {{ $fileToDeletePath }}
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-border">
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="cancelDeleteFile"
                        class="text-xs"
                    >
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="danger"
                        size="sm"
                        wire:click="confirmDeleteFile"
                        wire:loading.attr="disabled"
                        class="text-xs font-semibold"
                    >
                        <span wire:loading.remove wire:target="confirmDeleteFile">{{ __('Delete Permanently') }}</span>
                        <span wire:loading wire:target="confirmDeleteFile">{{ __('Deleting...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
