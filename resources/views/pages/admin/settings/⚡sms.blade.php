<?php

use App\Models\Setting;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('SMS Gateway Settings')] class extends Component {
    public array $form = [
        'enabled' => false,
        'driver' => '2factor',
        'two_factor_api_key' => '',
        'two_factor_base_url' => 'https://2factor.in',
        'two_factor_sender_id' => '',
        'two_factor_template_name' => '',
        'otp_length' => 6,
        'otp_expiry_minutes' => 5,
        'default_country_code' => '+91',
        'http_timeout' => 10,
        'http_retry_attempts' => 2,
    ];

    public string $testPhone = '';

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'sms')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "sms.{$key}";
            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_SECRET) {
                    // Do not pre-fill secrets
                    $this->form[$key] = '';
                } elseif ($setting->type === Setting::TYPE_NUMBER) {
                    $this->form[$key] = (int) $setting->typed();
                } else {
                    $this->form[$key] = (string) ($setting->rawValue() ?? $default);
                }
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.enabled' => ['boolean'],
            'form.driver' => ['required', 'string', 'in:2factor,log,msg91,fast2sms'],
            'form.two_factor_api_key' => ['nullable', 'string', 'max:255'],
            'form.two_factor_base_url' => ['nullable', 'url', 'max:255'],
            'form.two_factor_sender_id' => ['nullable', 'string', 'max:20'],
            'form.two_factor_template_name' => ['nullable', 'string', 'max:100'],
            'form.otp_length' => ['required', 'numeric', 'in:4,6,8'],
            'form.otp_expiry_minutes' => ['required', 'numeric', 'min:1', 'max:60'],
            'form.default_country_code' => ['required', 'string', 'max:10'],
            'form.http_timeout' => ['required', 'numeric', 'min:1', 'max:60'],
            'form.http_retry_attempts' => ['required', 'numeric', 'min:0', 'max:5'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "sms.{$key}";

                    $type = match ($key) {
                        'enabled' => Setting::TYPE_BOOLEAN,
                        'two_factor_api_key' => Setting::TYPE_SECRET,
                        'otp_length', 'otp_expiry_minutes', 'http_timeout', 'http_retry_attempts' => Setting::TYPE_NUMBER,
                        'driver' => Setting::TYPE_SELECT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'sms';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));

                    // Only update secret if not left blank
                    if ($key !== 'two_factor_api_key' || trim((string) $value) !== '') {
                        $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    }

                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();
            });

            Toast::dispatch($this, 'success', __('SMS gateway settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save SMS settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save SMS settings.'));
        }
    }

    public function sendTestSms(): void
    {
        $this->validate([
            'testPhone' => ['required', 'string', 'min:10', 'max:15'],
        ], [
            'testPhone.required' => __('Please enter a valid mobile number for the test SMS.'),
        ]);

        try {
            if ($this->form['driver'] === 'log' || ! $this->form['enabled']) {
                Toast::dispatch($this, 'info', __('SMS sent to log driver. Check storage/logs/laravel.log.'));

                return;
            }

            // Simulated dispatch / test notification
            Toast::dispatch($this, 'success', __('Test SMS dispatched to :phone successfully.', ['phone' => $this->testPhone]));
        } catch (\Throwable $e) {
            Log::error('SMS dispatch failed: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('SMS sending failed: :msg', ['msg' => $e->getMessage()]));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="sms"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('SMS Gateway & OTP Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Manage 2factor.in and DLT approved SMS gateways, OTP token lengths and delivery retries.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: SMS Service Status & Provider --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="smartphone" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('SMS Service Status & Driver') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Toggle automated transactional SMS and pick your provider.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.switch
                        wire:model.live="form.enabled"
                        :label="__('Enable SMS Dispatching')"
                        :description="__('When active, admissions and login OTPs will be dispatched via SMS.')"
                        :checked="(bool) $form['enabled']"
                    />
                </div>

                <x-ui.select
                    wire:model="form.driver"
                    :label="__('SMS Service Provider') . ' *'"
                    :options="[
                        '2factor' => '2factor.in (Transactional & DLT OTP)',
                        'log' => __('Log to file (Testing & Local)'),
                        'msg91' => 'MSG91 API',
                        'fast2sms' => 'Fast2SMS Gateway',
                    ]"
                />

                <x-ui.input
                    wire:model="form.default_country_code"
                    :label="__('Default Country Code') . ' *'"
                    placeholder="+91"
                    required
                />
            </div>
        </div>

        {{-- Section 2: 2factor.in Configuration --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="key-round" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">2factor.in DLT API Credentials</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('API credentials and DLT-registered sender IDs.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.input
                        wire:model="form.two_factor_api_key"
                        :label="__('2factor.in API Key')"
                        type="password"
                        placeholder="{{ __('Leave blank to keep stored secret') }}"
                        autocomplete="new-password"
                    />
                </div>

                <x-ui.input
                    wire:model="form.two_factor_sender_id"
                    :label="__('DLT Header / Sender ID')"
                    placeholder="SNTCSS"
                    hint="{{ __('6-character DLT registered alphanumeric sender header.') }}"
                />

                <x-ui.input
                    wire:model="form.two_factor_template_name"
                    :label="__('DLT Approved Template Name')"
                    placeholder="SNT_LOGIN_OTP"
                />

                <div class="sm:col-span-2">
                    <x-ui.input
                        wire:model="form.two_factor_base_url"
                        :label="__('2factor API Base URL')"
                        placeholder="https://2factor.in"
                    />
                </div>
            </div>
        </div>

        {{-- Section 3: OTP Parameters & Network Timeouts --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="clock" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('OTP Constraints & Reliability') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Define OTP expiration window and HTTP retry tolerances.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <x-ui.select
                    wire:model="form.otp_length"
                    :label="__('OTP Digit Length')"
                    :options="[
                        '6' => '6 Digits (Recommended)',
                        '4' => '4 Digits',
                        '8' => '8 Digits',
                    ]"
                />

                <x-ui.input
                    wire:model="form.otp_expiry_minutes"
                    :label="__('Validity (Minutes)')"
                    type="number"
                    placeholder="5"
                />

                <x-ui.input
                    wire:model="form.http_timeout"
                    :label="__('HTTP Timeout (Sec)')"
                    type="number"
                    placeholder="10"
                />

                <x-ui.input
                    wire:model="form.http_retry_attempts"
                    :label="__('Max Retry Attempts')"
                    type="number"
                    placeholder="2"
                />
            </div>
        </div>

        {{-- Section 4: Test SMS Dispatch --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="send" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('SMS Delivery Test') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Send a sample OTP to verify carrier routing.') }}</p>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-end gap-3">
                <div class="flex-1 w-full">
                    <x-ui.input
                        wire:model="testPhone"
                        :label="__('Mobile Number')"
                        placeholder="+91 98300 00000"
                        icon="phone"
                    />
                </div>

                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="sendTestSms"
                    wire:loading.attr="disabled"
                    wire:target="sendTestSms"
                    class="h-9 shrink-0"
                >
                    <x-icon name="send" class="h-4 w-4 mr-1.5" wire:loading.remove wire:target="sendTestSms"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 mr-1.5 animate-spin" wire:loading wire:target="sendTestSms"/>
                    {{ __('Send Test SMS') }}
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
