<?php

use App\Concerns\InteractsWithDataTable;
use App\Models\CaptchaLog;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\Captcha\CaptchaService;
use App\Services\Captcha\Drivers\CustomCaptchaDriver;
use App\Services\Captcha\Drivers\GoogleRecaptchaDriver;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('reCAPTCHA & Bot Defense Settings')] class extends Component
{
    use InteractsWithDataTable;
    use WithPagination;

    public string $activeTab = 'config'; // 'config', 'test', 'logs'

    public array $form = [
        'enabled' => false,
        'driver' => 'custom',
        'on_login' => true,
        'on_register' => true,
        'on_forgot_password' => true,
        'on_contact' => true,
        'google_version' => 'v2_checkbox',
        'google_site_key' => '',
        'google_secret_key' => '',
        'google_score_threshold' => 0.5,
        'google_domain' => 'www.google.com',
        'custom_char_type' => 'alphanumeric',
        'custom_length' => 5,
        'custom_case_sensitive' => false,
        'custom_noise_lines' => 3,
        'custom_noise_dots' => 35,
        'custom_distortion' => true,
        'custom_expire_minutes' => 5,
        'custom_max_attempts' => 3,
    ];

    // Live Simulator / Challenge Preview State
    public array $previewChallenge = [];

    // Interactive Google Handshake Test State
    public bool $isTestingGoogle = false;

    public ?array $googleTestResult = null;

    // Interactive In-Settings Verification Tester State
    public array $testerChallenge = [];

    public string $testerAnswer = '';

    public ?array $testerResult = null;

    // Log Inspection & Management State
    public ?int $viewingLogId = null;

    public ?CaptchaLog $selectedLog = null;

    public int $pruneDays = 30;

    private const LOGS_SEARCHABLE = ['ip_address', 'session_id', 'user_agent', 'error_code'];

    private const LOGS_SORTABLE = ['created_at', 'status', 'driver', 'action', 'response_time_ms', 'score'];

    public function mount(): void
    {
        if (request()->has('tab')) {
            $tab = (string) request()->query('tab');
            $this->activeTab = in_array($tab, ['config', 'test', 'logs'], true) ? $tab : ($tab === 'tester' ? 'test' : 'config');
        }

        if (request()->has('search')) {
            $this->search = (string) request()->query('search');
            $this->activeTab = 'logs';
        }

        $settings = Setting::query()->where('group', 'captcha')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "captcha.{$key}";
            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_NUMBER) {
                    $this->form[$key] = is_float($default) ? (float) $setting->typed() : (int) $setting->typed();
                } else {
                    $this->form[$key] = (string) ($setting->rawValue() ?? $default);
                }
            }
        }

        $this->refreshSimulator();
        $this->refreshTester();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function statistics(): array
    {
        return CaptchaService::getStatistics();
    }

    #[Computed]
    public function logs()
    {
        return $this->applyDataTable($this->logsQuery(), self::LOGS_SEARCHABLE, [
            'status' => 'status',
            'driver' => 'driver',
            'action' => 'action',
        ], self::LOGS_SORTABLE)->paginate($this->perPage);
    }

    protected function logsQuery(): Builder
    {
        return CaptchaLog::query()->with('user:id,name,email');
    }

    public function refreshSimulator(): void
    {
        $driver = app(CustomCaptchaDriver::class);
        $this->previewChallenge = $driver->generateChallenge([
            'char_type' => $this->form['custom_char_type'],
            'length' => (int) $this->form['custom_length'],
            'noise_lines' => (int) $this->form['custom_noise_lines'],
            'noise_dots' => (int) $this->form['custom_noise_dots'],
            'distortion' => (bool) $this->form['custom_distortion'],
        ]);
    }

    public function refreshTester(): void
    {
        $this->testerAnswer = '';
        $this->testerResult = null;

        if ($this->form['driver'] === 'custom') {
            $driver = app(CustomCaptchaDriver::class);
            $this->testerChallenge = $driver->generateChallenge();
        } else {
            $this->testerChallenge = [
                'driver' => 'google',
                'version' => $this->form['google_version'],
                'site_key' => $this->form['google_site_key'],
                'domain' => $this->form['google_domain'],
            ];
        }
    }

    public function submitTester(): void
    {
        if ($this->form['driver'] !== 'custom') {
            $this->testerResult = [
                'success' => false,
                'message' => __('Live interactive solving tester is supported natively for Custom CAPTCHA. For Google reCAPTCHA, use the "Test Google Connection" button below to verify secret and site keys.'),
            ];

            return;
        }

        if (empty($this->testerAnswer)) {
            $this->testerResult = [
                'success' => false,
                'message' => __('Please enter a verification answer.'),
            ];

            return;
        }

        $request = new Request([
            'captcha_token' => $this->testerChallenge['token'] ?? null,
            'captcha_answer' => $this->testerAnswer,
        ]);

        $start = microtime(true);
        $result = app(CustomCaptchaDriver::class)->verify($request, 'test');
        $elapsed = round((microtime(true) - $start) * 1000);

        CaptchaService::recordLog(
            driver: 'custom',
            action: CaptchaLog::ACTION_TEST,
            result: $result,
            request: request(),
            responseTimeMs: (int) $elapsed
        );

        $this->testerResult = [
            'success' => $result->isSuccess(),
            'message' => $result->message,
            'response_time_ms' => $elapsed,
        ];

        if ($result->isSuccess()) {
            $this->dispatch('captcha-verified');
        }
    }

    public function testGoogleConnection(): void
    {
        $siteKey = trim($this->form['google_site_key']);
        $secretKey = trim($this->form['google_secret_key']);

        if (empty($secretKey)) {
            Toast::dispatch($this, 'error', __('Please enter a Google Secret Key first.'));

            return;
        }

        $this->isTestingGoogle = true;
        $driver = app(GoogleRecaptchaDriver::class);
        $this->googleTestResult = $driver->testConnection(
            siteKey: $siteKey,
            secretKey: $secretKey,
            domain: $this->form['google_domain']
        );
        $this->isTestingGoogle = false;

        $this->dispatch('modal-open', name: 'google-test-modal');
    }

    public function save(): void
    {
        $this->validate([
            'form.enabled' => ['required', 'boolean'],
            'form.driver' => ['required', 'in:google,custom'],
            'form.on_login' => ['required', 'boolean'],
            'form.on_register' => ['required', 'boolean'],
            'form.on_forgot_password' => ['required', 'boolean'],
            'form.on_contact' => ['required', 'boolean'],
            'form.google_version' => ['required', 'in:v2_checkbox,v2_invisible,v3'],
            'form.google_site_key' => ['nullable', 'string', 'max:255'],
            'form.google_secret_key' => ['nullable', 'string', 'max:255'],
            'form.google_score_threshold' => ['required', 'numeric', 'min:0.1', 'max:1.0'],
            'form.google_domain' => ['required', 'in:www.google.com,www.recaptcha.net'],
            'form.custom_char_type' => ['required', 'in:numbers,alphabets,alphanumeric,special_characters,math'],
            'form.custom_length' => ['required', 'integer', 'min:3', 'max:8'],
            'form.custom_case_sensitive' => ['required', 'boolean'],
            'form.custom_noise_lines' => ['required', 'integer', 'min:0', 'max:8'],
            'form.custom_noise_dots' => ['required', 'integer', 'min:0', 'max:100'],
            'form.custom_distortion' => ['required', 'boolean'],
            'form.custom_expire_minutes' => ['required', 'integer', 'min:1', 'max:30'],
            'form.custom_max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "captcha.{$key}";
                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'captcha';
                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;

                    if ($key === 'google_secret_key') {
                        $setting->type = Setting::TYPE_SECRET;
                    } elseif (is_bool($value)) {
                        $setting->type = Setting::TYPE_BOOLEAN;
                    } elseif (is_int($value) || is_float($value)) {
                        $setting->type = Setting::TYPE_NUMBER;
                    } else {
                        $setting->type = Setting::TYPE_STRING;
                    }

                    $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    $setting->save();
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'captcha_settings_updated',
                    description: 'Updated reCAPTCHA and bot defense security settings.',
                    newValues: collect($this->form)->except('google_secret_key')->all(),
                    userId: $userId
                );
            });

            $this->refreshSimulator();
            $this->refreshTester();

            Toast::dispatch($this, 'success', __('reCAPTCHA & Bot Defense settings saved successfully.'));
        } catch (Throwable $e) {
            Log::error('Failed to save captcha settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save settings: ').$e->getMessage());
        }
    }

    public function viewLog(int $id): void
    {
        $this->selectedLog = CaptchaLog::withTrashed()->with('user')->findOrFail($id);
        $this->viewingLogId = $id;
        $this->dispatch('modal-open', name: 'log-detail-modal');
    }

    public function deleteLog(int $id): void
    {
        try {
            $log = CaptchaLog::findOrFail($id);
            $log->delete();

            AuditLogService::log(
                event: 'captcha_log_deleted',
                description: "Soft deleted CAPTCHA verification log #{$id} (IP: {$log->ip_address})"
            );

            Toast::dispatch($this, 'success', __('Verification log entry soft deleted.'));
        } catch (Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete log entry.'));
        }
    }

    public function restoreLog(int $id): void
    {
        try {
            $log = CaptchaLog::onlyTrashed()->findOrFail($id);
            $log->restore();

            AuditLogService::log(
                event: 'captcha_log_restored',
                description: "Restored soft deleted CAPTCHA verification log #{$id}"
            );

            Toast::dispatch($this, 'success', __('Verification log entry restored.'));
        } catch (Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to restore log entry.'));
        }
    }

    public function pruneLogs(): void
    {
        try {
            $cutoff = now()->subDays($this->pruneDays);
            $count = CaptchaLog::where('created_at', '<', $cutoff)->count();

            DB::transaction(function () use ($cutoff) {
                CaptchaLog::where('created_at', '<', $cutoff)->delete();

                AuditLogService::log(
                    event: 'captcha_logs_pruned',
                    description: "Pruned verification logs older than {$this->pruneDays} days."
                );
            });

            $this->dispatch('modal-close', name: 'prune-confirm-modal');
            Toast::dispatch($this, 'success', __(':count verification log entries pruned.', ['count' => $count]));
        } catch (Throwable $e) {
            Log::error('Prune logs error: '.$e->getMessage());
            Toast::dispatch($this, 'error', __('Failed to prune logs.'));
        }
    }

    public function export(string $format = 'xlsx')
    {
        $headings = [
            __('ID'),
            __('Date & Time'),
            __('Driver'),
            __('Action'),
            __('Status'),
            __('Score'),
            __('IP Address'),
            __('Response Time (ms)'),
            __('Error Code'),
            __('User'),
        ];

        return TableExporter::download(
            $this->logsQuery(),
            $format,
            'captcha-verification-logs',
            $headings,
            fn (CaptchaLog $log) => [
                $log->id,
                $log->created_at?->format('Y-m-d H:i:s'),
                ucfirst($log->driver),
                $log->formatted_action,
                strtoupper($log->status),
                $log->score !== null ? number_format($log->score, 2) : '—',
                $log->ip_address ?? '—',
                $log->response_time_ms.' ms',
                $log->error_code ?? 'None',
                $log->user?->name ?? 'Guest',
            ],
            __('reCAPTCHA & Bot Defense Verification Audit Trail')
        );
    }
}; ?>

<div class="space-y-6 max-w-6xl pb-12">
    <x-settings-nav active="captcha"/>

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="p-2 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="shield-check" class="h-6 w-6"/>
                </span>
                {{ __('reCAPTCHA & Bot Defense') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Protect your MIS authentication and public forms from automated spam, brute-force attempts, and credential stuffing.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.badge :color="$form['enabled'] ? 'emerald' : 'secondary'" size="md" class="gap-1.5 px-3 py-1 font-semibold">
                <span class="h-2 w-2 rounded-full {{ $form['enabled'] ? 'bg-emerald-500 animate-pulse' : 'bg-muted-foreground' }}"></span>
                {{ $form['enabled'] ? __('Bot Defense Active') : __('Protection Disabled') }}
            </x-ui.badge>

            <x-ui.badge color="primary" size="md" class="uppercase tracking-wider font-mono">
                {{ $form['driver'] === 'google' ? 'Google reCAPTCHA' : 'Custom Service' }}
            </x-ui.badge>
        </div>
    </div>

    {{-- Telemetry Statistics Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-ui.stat-card
            icon="shield"
            color="primary"
            :label="__('Today\'s Challenges')"
            :value="number_format($this->statistics['today_total'])"
            :hint="__(':total total challenges all-time', ['total' => number_format($this->statistics['total_all_time'])])"
        />

        <x-ui.stat-card
            icon="shield-check"
            color="emerald"
            :label="__('Passed Verifications')"
            :value="number_format($this->statistics['today_passed'])"
            :hint="__(':rate% overall pass rate today', ['rate' => $this->statistics['pass_rate']])"
        />

        <x-ui.stat-card
            icon="shield-alert"
            color="rose"
            :label="__('Blocked Bot Attempts')"
            :value="number_format($this->statistics['today_failed'])"
            :hint="__('Failed verification challenges')"
        />

        <x-ui.stat-card
            icon="clock"
            color="cyan"
            :label="__('Avg Response Time')"
            :value="$this->statistics['avg_response_time'] . ' ms'"
            :hint="__('Average verification latency')"
        />
    </div>

    {{-- Tab Controls --}}
    <div class="flex items-center border-b border-border gap-2">
        <button
            type="button"
            wire:click="$set('activeTab', 'config')"
            class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'config' ? 'border-primary text-primary font-semibold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="settings" class="h-4 w-4"/>
            {{ __('Configuration & Rules') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'test')"
            class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'test' ? 'border-primary text-primary font-semibold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="play-circle" class="h-4 w-4"/>
            {{ __('Interactive Tester & Simulator') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'logs')"
            class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'logs' ? 'border-primary text-primary font-semibold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="file-text" class="h-4 w-4"/>
            {{ __('Verification Logs & Audit Trail') }}
        </button>
    </div>

    {{-- TAB 1: Configuration & Rules --}}
    @if ($activeTab === 'config')
        <form wire:submit="save" class="space-y-6">
            {{-- Master Enable & Provider Card --}}
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-6">
                <div>
                    <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="toggle-right" class="h-5 w-5 text-primary"/>
                        {{ __('General Protection Switch & Active Provider') }}
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Choose whether CAPTCHA is enabled and select your preferred verification provider.') }}
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                    {{-- Master Switch --}}
                    <div class="flex items-center justify-between p-4 rounded-lg border border-border bg-secondary/20">
                        <div class="space-y-0.5">
                            <label for="form_enabled" class="text-sm font-medium text-foreground cursor-pointer">{{ __('Enable CAPTCHA Protection') }}</label>
                            <p class="text-xs text-muted-foreground">{{ __('Enforce security verification challenges across selected forms.') }}</p>
                        </div>
                        <input
                            type="checkbox"
                            id="form_enabled"
                            wire:model.live="form.enabled"
                            class="h-5 w-5 rounded border-input text-primary focus:ring-ring cursor-pointer"
                        />
                    </div>

                    {{-- Driver Selection --}}
                    <div class="p-4 rounded-lg border border-border bg-secondary/20 space-y-2">
                        <label class="text-sm font-medium text-foreground">{{ __('Active CAPTCHA Provider') }}</label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center gap-2.5 p-2.5 rounded-md border cursor-pointer transition-all {{ $form['driver'] === 'google' ? 'border-primary bg-primary/10 text-primary font-medium' : 'border-border bg-card text-muted-foreground hover:text-foreground' }}">
                                <input type="radio" wire:model.live="form.driver" value="google" class="sr-only">
                                <x-icon name="globe" class="h-4 w-4 shrink-0"/>
                                <span class="text-xs">{{ __('Google reCAPTCHA') }}</span>
                            </label>

                            <label class="flex items-center gap-2.5 p-2.5 rounded-md border cursor-pointer transition-all {{ $form['driver'] === 'custom' ? 'border-primary bg-primary/10 text-primary font-medium' : 'border-border bg-card text-muted-foreground hover:text-foreground' }}">
                                <input type="radio" wire:model.live="form.driver" value="custom" class="sr-only">
                                <x-icon name="cpu" class="h-4 w-4 shrink-0"/>
                                <span class="text-xs">{{ __('Custom CAPTCHA') }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Protected Forms Checkboxes --}}
                <div class="space-y-3 pt-2">
                    <label class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Protected MIS Forms') }}</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="flex items-center gap-3 p-3 rounded-lg border border-border bg-card hover:bg-secondary/30 transition-colors cursor-pointer">
                            <input type="checkbox" wire:model="form.on_login" class="h-4 w-4 rounded border-input text-primary focus:ring-ring cursor-pointer">
                            <div>
                                <p class="text-xs font-medium text-foreground">{{ __('User Sign In') }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ __('Password & OTP sign in') }}</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-lg border border-border bg-card hover:bg-secondary/30 transition-colors cursor-pointer">
                            <input type="checkbox" wire:model="form.on_register" class="h-4 w-4 rounded border-input text-primary focus:ring-ring cursor-pointer">
                            <div>
                                <p class="text-xs font-medium text-foreground">{{ __('Registration') }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ __('New student/user signup') }}</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-lg border border-border bg-card hover:bg-secondary/30 transition-colors cursor-pointer">
                            <input type="checkbox" wire:model="form.on_forgot_password" class="h-4 w-4 rounded border-input text-primary focus:ring-ring cursor-pointer">
                            <div>
                                <p class="text-xs font-medium text-foreground">{{ __('Password Reset') }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ __('Forgot password requests') }}</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-lg border border-border bg-card hover:bg-secondary/30 transition-colors cursor-pointer">
                            <input type="checkbox" wire:model="form.on_contact" class="h-4 w-4 rounded border-input text-primary focus:ring-ring cursor-pointer">
                            <div>
                                <p class="text-xs font-medium text-foreground">{{ __('Contact Us') }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ __('Public inquiry submissions') }}</p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Google reCAPTCHA Configuration --}}
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-6 {{ $form['driver'] === 'google' ? 'ring-2 ring-primary/40' : 'opacity-85' }}">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                            <x-icon name="globe" class="h-5 w-5 text-primary"/>
                            {{ __('Google reCAPTCHA Parameters') }}
                        </h2>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Supports reCAPTCHA v2 (Checkbox or Invisible) and score-evaluated reCAPTCHA v3.') }}
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="testGoogleConnection"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-secondary hover:bg-secondary/80 text-foreground transition-colors cursor-pointer"
                    >
                        <x-icon name="zap" class="h-3.5 w-3.5 text-amber-500" wire:loading.remove wire:target="testGoogleConnection"/>
                        <span wire:loading wire:target="testGoogleConnection" class="animate-spin h-3.5 w-3.5 border-2 border-primary border-t-transparent rounded-full"></span>
                        {{ __('Test Google Connection') }}
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-xs font-medium text-foreground">{{ __('reCAPTCHA Version') }}</label>
                        <select
                            wire:model.live="form.google_version"
                            class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background text-sm text-foreground shadow-xs focus:ring-2 focus:ring-ring/50 outline-none cursor-pointer"
                        >
                            <option value="v2_checkbox">{{ __('reCAPTCHA v2 — "I\'m not a robot" Checkbox') }}</option>
                            <option value="v2_invisible">{{ __('reCAPTCHA v2 — Invisible Background Badge') }}</option>
                            <option value="v3">{{ __('reCAPTCHA v3 — Frictionless Human Score (0.0 – 1.0)') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-foreground">{{ __('API Endpoint Domain') }}</label>
                        <select
                            wire:model="form.google_domain"
                            class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background text-sm text-foreground shadow-xs focus:ring-2 focus:ring-ring/50 outline-none cursor-pointer"
                        >
                            <option value="www.google.com">{{ __('www.google.com (Default Global Endpoint)') }}</option>
                            <option value="www.recaptcha.net">{{ __('www.recaptcha.net (Accessible worldwide / China CDN)') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-foreground">{{ __('Google Site Key (Public Key)') }}</label>
                        <input
                            type="text"
                            wire:model="form.google_site_key"
                            placeholder="6Lxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background font-mono text-xs text-foreground shadow-xs focus:ring-2 focus:ring-ring/50 outline-none"
                        />
                    </div>

                    <div x-data="{ showSecret: false }">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-medium text-foreground">{{ __('Google Secret Key (Private Key)') }}</label>
                            <button type="button" x-on:click="showSecret = !showSecret" class="text-[11px] text-muted-foreground hover:text-foreground cursor-pointer">
                                <span x-show="!showSecret">{{ __('Reveal') }}</span>
                                <span x-show="showSecret" x-cloak>{{ __('Hide') }}</span>
                            </button>
                        </div>
                        <input
                            :type="showSecret ? 'text' : 'password'"
                            wire:model="form.google_secret_key"
                            placeholder="6Lxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background font-mono text-xs text-foreground shadow-xs focus:ring-2 focus:ring-ring/50 outline-none"
                        />
                    </div>

                    @if ($form['google_version'] === 'v3')
                        <div class="md:col-span-2 p-4 rounded-lg border border-primary/20 bg-primary/5 space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-semibold text-foreground">{{ __('reCAPTCHA v3 Minimum Confidence Score') }}: <span class="font-mono text-primary">{{ $form['google_score_threshold'] }}</span></label>
                                <span class="text-[11px] text-muted-foreground">{{ __('Scores below this threshold are blocked as automated bots.') }}</span>
                            </div>
                            <input
                                type="range"
                                min="0.1"
                                max="0.9"
                                step="0.05"
                                wire:model.live="form.google_score_threshold"
                                class="w-full cursor-pointer accent-primary"
                            />
                            <div class="flex justify-between text-[10px] text-muted-foreground">
                                <span>0.1 (Lenient — allows more users)</span>
                                <span>0.5 (Recommended)</span>
                                <span>0.9 (Strict — high protection)</span>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Custom CAPTCHA Service Configuration --}}
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-6 {{ $form['driver'] === 'custom' ? 'ring-2 ring-primary/40' : 'opacity-85' }}">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                            <x-icon name="cpu" class="h-5 w-5 text-primary"/>
                            {{ __('Custom CAPTCHA Service Parameters') }}
                        </h2>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Self-hosted, zero-external-dependency challenge generator with numbers, alphabets, symbols, or mathematical puzzles.') }}
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="refreshSimulator"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-secondary hover:bg-secondary/80 text-foreground transition-colors cursor-pointer"
                    >
                        <x-icon name="refresh-cw" class="h-3.5 w-3.5 text-primary"/>
                        {{ __('Regenerate Simulator') }}
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    {{-- Character Type Selection --}}
                    <div>
                        <label class="text-xs font-medium text-foreground">{{ __('Challenge Content Mode') }}</label>
                        <select
                            wire:model.live="form.custom_char_type"
                            wire:change="refreshSimulator"
                            class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background text-sm text-foreground shadow-xs focus:ring-2 focus:ring-ring/50 outline-none cursor-pointer"
                        >
                            <option value="numbers">{{ __('1. Only Numbers (Digits 0-9)') }}</option>
                            <option value="alphabets">{{ __('2. Only Alphabets (Letters A-Z, a-z)') }}</option>
                            <option value="alphanumeric">{{ __('3. Mix of Numbers & Alphabets (Default)') }}</option>
                            <option value="special_characters">{{ __('4. Complex with Special Characters (@#$%&)') }}</option>
                            <option value="math">{{ __('5. Mathematical Challenge (e.g. 12 + 7 = ?)') }}</option>
                        </select>
                    </div>

                    {{-- Code Length --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-medium text-foreground">{{ __('Challenge Code Length') }}</label>
                            <span class="text-xs font-mono font-bold text-primary">{{ $form['custom_length'] }} chars</span>
                        </div>
                        <input
                            type="range"
                            min="3"
                            max="8"
                            step="1"
                            wire:model.live="form.custom_length"
                            wire:change="refreshSimulator"
                            class="mt-3 w-full cursor-pointer accent-primary"
                            {{ $form['custom_char_type'] === 'math' ? 'disabled' : '' }}
                        />
                        <div class="flex justify-between text-[10px] text-muted-foreground mt-1">
                            <span>3 (Short)</span>
                            <span>5 (Optimal)</span>
                            <span>8 (Long)</span>
                        </div>
                    </div>

                    {{-- Noise Lines Slider --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-medium text-foreground">{{ __('Wavy Noise Lines') }}</label>
                            <span class="text-xs font-mono font-bold text-primary">{{ $form['custom_noise_lines'] }} lines</span>
                        </div>
                        <input
                            type="range"
                            min="0"
                            max="6"
                            step="1"
                            wire:model.live="form.custom_noise_lines"
                            wire:change="refreshSimulator"
                            class="mt-3 w-full cursor-pointer accent-primary"
                        />
                    </div>

                    {{-- Noise Dots Slider --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-medium text-foreground">{{ __('Scatter Noise Dots') }}</label>
                            <span class="text-xs font-mono font-bold text-primary">{{ $form['custom_noise_dots'] }} dots</span>
                        </div>
                        <input
                            type="range"
                            min="0"
                            max="80"
                            step="5"
                            wire:model.live="form.custom_noise_dots"
                            wire:change="refreshSimulator"
                            class="mt-3 w-full cursor-pointer accent-primary"
                        />
                    </div>

                    {{-- TTL / Expiry --}}
                    <div>
                        <label class="text-xs font-medium text-foreground">{{ __('Challenge Expiry (Minutes)') }}</label>
                        <select
                            wire:model="form.custom_expire_minutes"
                            class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background text-sm text-foreground shadow-xs focus:ring-2 focus:ring-ring/50 outline-none cursor-pointer"
                        >
                            <option value="2">2 minutes</option>
                            <option value="5">5 minutes (Default)</option>
                            <option value="10">10 minutes</option>
                            <option value="15">15 minutes</option>
                        </select>
                    </div>

                    {{-- Max Attempts --}}
                    <div>
                        <label class="text-xs font-medium text-foreground">{{ __('Max Failed Attempts Per Token') }}</label>
                        <select
                            wire:model="form.custom_max_attempts"
                            class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background text-sm text-foreground shadow-xs focus:ring-2 focus:ring-ring/50 outline-none cursor-pointer"
                        >
                            <option value="2">2 attempts</option>
                            <option value="3">3 attempts (Default)</option>
                            <option value="5">5 attempts</option>
                        </select>
                    </div>
                </div>

                {{-- Feature Toggles & Simulator Row --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-3 border-t border-border">
                    <div class="space-y-3">
                        <label class="flex items-center gap-3 p-3 rounded-lg border border-border bg-secondary/20 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.live="form.custom_case_sensitive"
                                class="h-4 w-4 rounded border-input text-primary focus:ring-ring cursor-pointer"
                            />
                            <div>
                                <p class="text-xs font-medium text-foreground">{{ __('Case Sensitive Verification') }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ __('When disabled, "ABC" and "abc" are both accepted for user convenience.') }}</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-lg border border-border bg-secondary/20 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.live="form.custom_distortion"
                                wire:change="refreshSimulator"
                                class="h-4 w-4 rounded border-input text-primary focus:ring-ring cursor-pointer"
                            />
                            <div>
                                <p class="text-xs font-medium text-foreground">{{ __('Character Rotation & Distortion') }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ __('Applies random angular rotation and offsets to disrupt OCR optical bot solvers.') }}</p>
                            </div>
                        </label>
                    </div>

                    {{-- Live SVG Simulator --}}
                    <div class="p-4 rounded-xl border border-border bg-card flex flex-col items-center justify-center space-y-2">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Live Generator Preview') }}</span>
                        @if (!empty($previewChallenge['image']))
                            <div class="p-2 rounded-lg border border-border bg-muted/30 shadow-xs flex items-center justify-center">
                                <img src="{{ $previewChallenge['image'] }}" alt="Challenge Preview" class="h-12 w-48 object-contain">
                            </div>
                            <span class="text-[11px] text-muted-foreground font-mono">
                                {{ __('Type') }}: {{ ucfirst($form['custom_char_type']) }} | {{ __('Audio text') }}: "{{ $previewChallenge['speech'] ?? '' }}"
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Save Submit Bar --}}
            <div class="flex items-center justify-end gap-3 pt-2">
                <x-ui.button type="submit" size="lg" class="px-6 font-semibold gap-2">
                    <x-icon name="check" class="h-4 w-4"/>
                    {{ __('Save CAPTCHA Settings') }}
                </x-ui.button>
            </div>
        </form>
    @endif

    {{-- TAB 2: Interactive Tester & Simulator --}}
    @if ($activeTab === 'test')
        <div class="space-y-6">
            <div class="rounded-xl border border-border bg-card p-6 shadow-xs space-y-5">
                <div>
                    <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="play-circle" class="h-5 w-5 text-primary"/>
                        {{ __('Live CAPTCHA Simulator & Interactive Diagnostic') }}
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Test the current configuration end-to-end to verify that challenges render cleanly and responses validate correctly.') }}
                    </p>
                </div>

                <div class="p-5 rounded-xl border border-border bg-secondary/15 max-w-xl mx-auto space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                            <x-icon name="shield-check" class="h-4 w-4 text-primary"/>
                            {{ __('Active Engine') }}: <span class="font-mono text-primary uppercase">{{ $form['driver'] }}</span>
                        </span>

                        <button
                            type="button"
                            wire:click="refreshTester"
                            class="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1 cursor-pointer"
                        >
                            <x-icon name="refresh-cw" class="h-3.5 w-3.5"/>
                            {{ __('New Challenge') }}
                        </button>
                    </div>

                    @if ($form['driver'] === 'custom')
                        <div class="flex flex-col sm:flex-row items-center gap-3">
                            @if (!empty($testerChallenge['image']))
                                <div class="rounded-lg border border-border bg-card overflow-hidden shrink-0 shadow-xs">
                                    <img src="{{ $testerChallenge['image'] }}" alt="Test Captcha" class="h-12 w-48 object-contain">
                                </div>
                            @endif

                            <div class="flex-1 w-full">
                                <input
                                    type="text"
                                    wire:model="testerAnswer"
                                    wire:keydown.enter="submitTester"
                                    placeholder="{{ __('Enter the code above…') }}"
                                    class="w-full h-11 px-3.5 rounded-lg border border-input bg-background text-sm font-medium tracking-wider text-foreground shadow-xs outline-none focus:ring-2 focus:ring-ring/50"
                                />
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <span class="text-[11px] text-muted-foreground font-mono">
                                {{ __('Audio readout') }}: "{{ $testerChallenge['speech'] ?? '' }}"
                            </span>

                            <x-ui.button type="button" size="sm" wire:click="submitTester" class="font-medium gap-1.5">
                                <x-icon name="check-circle" class="h-4 w-4"/>
                                {{ __('Verify Answer') }}
                            </x-ui.button>
                        </div>
                    @else
                        {{-- Google reCAPTCHA Diagnostic Info --}}
                        <div class="p-4 rounded-lg border border-border bg-card space-y-3">
                            <p class="text-xs text-foreground font-medium">{{ __('Google reCAPTCHA Diagnostics') }}</p>
                            <ul class="text-xs text-muted-foreground space-y-1">
                                <li><strong>{{ __('Version') }}:</strong> {{ $form['google_version'] }}</li>
                                <li><strong>{{ __('Site Key') }}:</strong> {{ $form['google_site_key'] ? substr($form['google_site_key'], 0, 12).'…' : __('Missing') }}</li>
                                <li><strong>{{ __('Domain') }}:</strong> {{ $form['google_domain'] }}</li>
                            </ul>
                            <x-ui.button type="button" size="sm" wire:click="testGoogleConnection" class="gap-1.5">
                                <x-icon name="zap" class="h-4 w-4 text-amber-500"/>
                                {{ __('Run Google Connection Test') }}
                            </x-ui.button>
                        </div>
                    @endif

                    {{-- Test Result Alert --}}
                    @if ($testerResult)
                        <div class="p-3.5 rounded-lg border {{ $testerResult['success'] ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300' }} text-xs font-medium flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <x-icon :name="$testerResult['success'] ? 'check-circle' : 'alert-circle'" class="h-4 w-4 shrink-0"/>
                                <span>{{ $testerResult['message'] }}</span>
                            </div>
                            @if (isset($testerResult['response_time_ms']))
                                <span class="text-[10px] font-mono opacity-80">{{ $testerResult['response_time_ms'] }} ms</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 3: Verification Logs & Audit Trail --}}
    @if ($activeTab === 'logs')
        <div class="space-y-4">
            {{-- Logs Filters & Toolbar --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <div class="relative">
                        <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search by IP, session, user agent…') }}"
                            class="h-9 w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-[2px] focus:ring-ring/50"
                        />
                        @if ($search)
                            <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                                <x-icon name="x" class="h-3.5 w-3.5"/>
                            </button>
                        @endif
                    </div>

                    <select
                        wire:model.live="tableFilters.status"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus:ring-[2px] focus:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
                    >
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="passed">{{ __('Passed') }}</option>
                        <option value="failed">{{ __('Failed') }}</option>
                        <option value="expired">{{ __('Expired') }}</option>
                        <option value="blocked">{{ __('Blocked') }}</option>
                    </select>

                    <select
                        wire:model.live="tableFilters.driver"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus:ring-[2px] focus:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
                    >
                        <option value="">{{ __('All drivers') }}</option>
                        <option value="custom">{{ __('Custom CAPTCHA') }}</option>
                        <option value="google">{{ __('Google reCAPTCHA') }}</option>
                    </select>

                    <select
                        wire:model.live="tableFilters.action"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus:ring-[2px] focus:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
                    >
                        <option value="">{{ __('All actions') }}</option>
                        <option value="login">{{ __('Sign In') }}</option>
                        <option value="register">{{ __('Registration') }}</option>
                        <option value="forgot_password">{{ __('Password Reset') }}</option>
                        <option value="contact_us">{{ __('Contact Form') }}</option>
                        <option value="test">{{ __('Test Diagnostics') }}</option>
                    </select>

                    <x-ui.button size="sm" variant="outline" class="h-9 text-xs" wire:click="resetFilters">
                        {{ __('Reset') }}
                    </x-ui.button>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button size="sm" variant="outline" class="h-9 text-xs gap-1.5" x-data x-on:click="$store.modals.open('prune-confirm-modal')">
                        <x-icon name="trash" class="h-3.5 w-3.5 text-muted-foreground"/>
                        {{ __('Prune Old Logs') }}
                    </x-ui.button>

                    <x-ui.dropdown width="w-40" align="end">
                        <x-slot:trigger>
                            <x-ui.button size="sm" variant="outline" class="h-9 text-xs gap-1.5">
                                <x-icon name="download" class="h-3.5 w-3.5"/>
                                {{ __('Export') }}
                                <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground"/>
                            </x-ui.button>
                        </x-slot:trigger>
                        <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Excel (.xlsx)') }}</x-ui.dropdown.item>
                        <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('CSV (.csv)') }}</x-ui.dropdown.item>
                        <x-ui.dropdown.item icon="file-down" wire:click="export('pdf')">{{ __('PDF (.pdf)') }}</x-ui.dropdown.item>
                    </x-ui.dropdown>
                </div>
            </div>

            {{-- Table --}}
            <div class="rounded-lg border border-border bg-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-xs">
                        <thead>
                            <tr class="border-b border-border bg-secondary/20">
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground w-14">#</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Timestamp') }}</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Driver') }}</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Action') }}</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground">{{ __('IP Address') }}</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Latency') }}</th>
                                <th class="px-4 py-3 text-left font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Score') }}</th>
                                <th class="px-4 py-3 text-right font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($this->logs as $log)
                                <tr class="hover:bg-secondary/30 transition-colors" wire:key="captcha-log-{{ $log->id }}">
                                    <td class="px-4 py-3 text-muted-foreground font-mono">{{ $log->id }}</td>
                                    <td class="px-4 py-3 text-foreground whitespace-nowrap">{{ $log->created_at?->format('M d, Y H:i:s') }}</td>
                                    <td class="px-4 py-3 font-mono">
                                        <x-ui.badge color="secondary" size="sm">{{ ucfirst($log->driver) }}</x-ui.badge>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-foreground">{{ $log->formatted_action }}</td>
                                    <td class="px-4 py-3">
                                        <x-ui.badge :color="$log->status_badge_color" size="sm" class="uppercase">
                                            {{ $log->status }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-muted-foreground">{{ $log->ip_address ?? '—' }}</td>
                                    <td class="px-4 py-3 text-muted-foreground font-mono">{{ $log->response_time_ms }} ms</td>
                                    <td class="px-4 py-3 font-mono text-muted-foreground">
                                        {{ $log->score !== null ? number_format($log->score, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                wire:click="viewLog({{ $log->id }})"
                                                class="p-1 rounded text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer"
                                                title="{{ __('View Details') }}"
                                            >
                                                <x-icon name="eye" class="h-4 w-4"/>
                                            </button>

                                            @if ($log->trashed())
                                                <button
                                                    type="button"
                                                    wire:click="restoreLog({{ $log->id }})"
                                                    class="p-1 rounded text-emerald-600 hover:bg-emerald-500/10 cursor-pointer"
                                                    title="{{ __('Restore') }}"
                                                >
                                                    <x-icon name="rotate-ccw" class="h-4 w-4"/>
                                                </button>
                                            @else
                                                <button
                                                    type="button"
                                                    wire:click="deleteLog({{ $log->id }})"
                                                    class="p-1 rounded text-rose-600 hover:bg-rose-500/10 cursor-pointer"
                                                    title="{{ __('Soft Delete') }}"
                                                >
                                                    <x-icon name="trash" class="h-4 w-4"/>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                        <x-icon name="shield" class="h-8 w-8 mx-auto mb-2 opacity-30"/>
                                        <p class="text-sm font-medium">{{ __('No verification logs recorded yet.') }}</p>
                                        <p class="text-xs text-muted-foreground mt-0.5">{{ __('Log entries will appear here automatically when forms are submitted.') }}</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($this->logs->hasPages())
                    <div class="p-3 border-t border-border">
                        {{ $this->logs->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- INTERACTIVE MODAL 1: Google Handshake Test Result --}}
    <x-ui.modal name="google-test-modal" :title="__('Google reCAPTCHA Connection Test')">
        @if ($googleTestResult)
            <div class="space-y-4">
                <div class="p-4 rounded-xl border {{ $googleTestResult['status'] === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300' }} flex items-start gap-3">
                    <x-icon :name="$googleTestResult['status'] === 'success' ? 'check-circle' : 'alert-circle'" class="h-5 w-5 mt-0.5 shrink-0"/>
                    <div class="space-y-1">
                        <p class="text-sm font-semibold">{{ $googleTestResult['message'] }}</p>
                        @if ($googleTestResult['response_time_ms'] > 0)
                            <p class="text-xs opacity-80">{{ __('Handshake round-trip latency') }}: {{ $googleTestResult['response_time_ms'] }} ms</p>
                        @endif
                    </div>
                </div>

                <div class="space-y-1.5">
                    <p class="text-xs font-semibold text-foreground uppercase tracking-wider">{{ __('Diagnostic Response Data') }}</p>
                    <pre class="p-3 rounded-lg bg-secondary/50 text-[11px] font-mono overflow-x-auto text-foreground border border-border">{{ json_encode($googleTestResult['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>

                <div class="flex justify-end pt-2">
                    <x-ui.button type="button" size="sm" x-data x-on:click="$store.modals.close('google-test-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- INTERACTIVE MODAL 2: Log Entry Details --}}
    <x-ui.modal name="log-detail-modal" :title="__('Verification Challenge Details')">
        @if ($selectedLog)
            <div class="space-y-4 text-xs">
                <div class="grid grid-cols-2 gap-3 p-3.5 rounded-lg border border-border bg-secondary/20">
                    <div>
                        <span class="text-muted-foreground">{{ __('Record ID') }}:</span>
                        <p class="font-mono font-bold text-foreground">#{{ $selectedLog->id }}</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Timestamp') }}:</span>
                        <p class="text-foreground">{{ $selectedLog->created_at?->format('M d, Y H:i:s') }}</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Driver') }}:</span>
                        <p class="font-mono text-foreground uppercase">{{ $selectedLog->driver }}</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Action') }}:</span>
                        <p class="text-foreground font-medium">{{ $selectedLog->formatted_action }}</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Verification Status') }}:</span>
                        <p>
                            <x-ui.badge :color="$selectedLog->status_badge_color" size="sm" class="uppercase">
                                {{ $selectedLog->status }}
                            </x-ui.badge>
                        </p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Score') }}:</span>
                        <p class="font-mono text-foreground">{{ $selectedLog->score !== null ? number_format($selectedLog->score, 2) : '—' }}</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Latency') }}:</span>
                        <p class="font-mono text-foreground">{{ $selectedLog->response_time_ms }} ms</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('IP Address') }}:</span>
                        <p class="font-mono text-foreground">{{ $selectedLog->ip_address ?? '—' }}</p>
                    </div>
                </div>

                <div>
                    <span class="text-muted-foreground block mb-1 font-medium">{{ __('User Agent') }}:</span>
                    <p class="p-2.5 rounded-md border border-border bg-card font-mono text-[11px] text-foreground break-all">
                        {{ $selectedLog->user_agent ?? 'Unknown' }}
                    </p>
                </div>

                @if ($selectedLog->error_code)
                    <div>
                        <span class="text-muted-foreground block mb-1 font-medium">{{ __('Error Code') }}:</span>
                        <p class="p-2 rounded-md border border-rose-500/20 bg-rose-500/10 font-mono text-rose-700 dark:text-rose-300">
                            {{ $selectedLog->error_code }}
                        </p>
                    </div>
                @endif

                @if ($selectedLog->metadata)
                    <div>
                        <span class="text-muted-foreground block mb-1 font-medium">{{ __('Metadata & Diagnostics') }}:</span>
                        <pre class="p-3 rounded-lg border border-border bg-secondary/50 font-mono text-[11px] text-foreground overflow-x-auto">{{ json_encode($selectedLog->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endif

                <div class="flex justify-end pt-2">
                    <x-ui.button type="button" size="sm" x-data x-on:click="$store.modals.close('log-detail-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- INTERACTIVE MODAL 3: Prune Old Logs Confirmation --}}
    <x-ui.modal name="prune-confirm-modal" :title="__('Prune Verification Logs')">
        <div class="space-y-4">
            <p class="text-sm text-muted-foreground">
                {{ __('Select a retention window. Verification audit logs older than the specified days will be permanently archived.') }}
            </p>

            <div>
                <label class="text-xs font-medium text-foreground">{{ __('Retention Period') }}</label>
                <select
                    wire:model="pruneDays"
                    class="mt-1.5 w-full h-10 px-3 rounded-lg border border-input bg-background text-sm text-foreground shadow-xs outline-none cursor-pointer focus:ring-2 focus:ring-ring/50"
                >
                    <option value="7">{{ __('Older than 7 days') }}</option>
                    <option value="30">{{ __('Older than 30 days (Recommended)') }}</option>
                    <option value="60">{{ __('Older than 60 days') }}</option>
                    <option value="90">{{ __('Older than 90 days') }}</option>
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('prune-confirm-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" variant="destructive" wire:click="pruneLogs" class="gap-1.5">
                    <x-icon name="trash" class="h-4 w-4"/>
                    {{ __('Prune Logs') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>

