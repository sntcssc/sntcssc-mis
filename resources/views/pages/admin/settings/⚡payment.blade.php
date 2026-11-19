<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Payment Gateway Settings')] class extends Component {
    use WithFileUploads;

    public $customQrImage = null;
    public ?string $existingCustomQrUrl = null;

    public array $secretConfigured = [
        'razorpay_key_secret' => false,
        'razorpay_webhook_secret' => false,
        'phonepe_salt_key' => false,
        'ccavenue_working_key' => false,
    ];

    public array $form = [
        'enabled' => false,
        'driver' => 'razorpay',
        'currency' => 'INR',

        // Razorpay
        'razorpay_enabled' => false,
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'razorpay_webhook_secret' => '',

        // PhonePe
        'phonepe_enabled' => false,
        'phonepe_merchant_id' => '',
        'phonepe_salt_key' => '',
        'phonepe_salt_index' => 1,
        'phonepe_mode' => 'UAT',

        // CCAVenue
        'ccavenue_enabled' => false,
        'ccavenue_merchant_id' => '',
        'ccavenue_access_code' => '',
        'ccavenue_working_key' => '',
        'ccavenue_mode' => 'test',
        'ccavenue_billing_page_enabled' => false,
        'ccavenue_default_billing_address' => 'Kolkata',
        'ccavenue_default_billing_city' => 'Kolkata',
        'ccavenue_default_billing_state' => 'West Bengal',
        'ccavenue_default_billing_zip' => '700106',
        'ccavenue_default_billing_country' => 'India',
        'ccavenue_default_billing_tel' => '9999999999',
        'ccavenue_default_billing_email' => 'info@sntcssc.in',

        // Offline Gateway Fallback
        'offline_enabled' => true,
        'offline_bank_transfer_enabled' => true,
        'offline_bank_name' => 'State Bank of India',
        'offline_account_name' => 'Satyendranath Tagore Civil Services Study Centre',
        'offline_account_number' => '39824589201',
        'offline_ifsc_code' => 'SBIN0000123',
        'offline_branch' => 'Kolkata Main Branch',
        'offline_upi_enabled' => true,
        'offline_upi_id' => 'sntcssc@indianbk',
        'offline_upi_payee_name' => 'SNTCSSC Kolkata',
        'offline_cash_enabled' => true,
        'offline_cash_instructions' => 'Pay cash directly at Accounts Counter, Room 102, Administrative Block between 10:00 AM and 4:00 PM on working days and collect official signed receipt.',
    ];

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'payment')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "payment.{$key}";
            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_SECRET) {
                    // Do not pre-fill secrets
                    $this->form[$key] = '';
                } else {
                    $this->form[$key] = $setting->rawValue() ?? $default;
                }
            }
        }

        foreach (array_keys($this->secretConfigured) as $sec) {
            $s = $settings["payment.{$sec}"] ?? null;
            $this->secretConfigured[$sec] = ! empty($s?->rawValue());
        }

        $customQrPath = Setting::get('payment.offline_custom_qr_image');
        if (! empty($customQrPath)) {
            if (Storage::disk('public')->exists($customQrPath)) {
                $this->existingCustomQrUrl = Storage::disk('public')->url($customQrPath);
            } elseif (file_exists(public_path($customQrPath))) {
                $this->existingCustomQrUrl = asset($customQrPath);
            }
        }
    }

    public function removeCustomQrImage(): void
    {
        $customQrPath = Setting::get('payment.offline_custom_qr_image');
        if (! empty($customQrPath) && Storage::disk('public')->exists($customQrPath)) {
            Storage::disk('public')->delete($customQrPath);
        }

        $setting = Setting::firstWhere('key', 'payment.offline_custom_qr_image');
        if ($setting) {
            $setting->update(['value' => null]);
        }

        Setting::flushCache();
        $this->existingCustomQrUrl = null;
        $this->customQrImage = null;

        Toast::dispatch($this, 'info', __('Custom QR code image removed. Dynamic UPI QR generation will be used.'));
    }

    public function save(): void
    {
        $this->validate([
            'form.enabled' => ['boolean'],
            'form.driver' => ['required', 'string', 'in:razorpay,phonepe,ccavenue,offline'],
            'form.currency' => ['required', 'string', 'max:10'],

            'form.razorpay_enabled' => ['boolean'],
            'form.razorpay_key_id' => ['nullable', 'string', 'max:255'],
            'form.razorpay_key_secret' => ['nullable', 'string', 'max:255'],
            'form.razorpay_webhook_secret' => ['nullable', 'string', 'max:255'],

            'form.phonepe_enabled' => ['boolean'],
            'form.phonepe_merchant_id' => ['nullable', 'string', 'max:255'],
            'form.phonepe_salt_key' => ['nullable', 'string', 'max:255'],
            'form.phonepe_salt_index' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'form.phonepe_mode' => ['nullable', 'string', 'in:UAT,LIVE'],

            'form.ccavenue_enabled' => ['boolean'],
            'form.ccavenue_merchant_id' => ['nullable', 'string', 'max:255'],
            'form.ccavenue_access_code' => ['nullable', 'string', 'max:255'],
            'form.ccavenue_working_key' => ['nullable', 'string', 'max:255'],
            'form.ccavenue_mode' => ['nullable', 'string', 'in:test,live'],
            'form.ccavenue_billing_page_enabled' => ['boolean'],
            'form.ccavenue_default_billing_address' => ['nullable', 'string', 'max:255'],
            'form.ccavenue_default_billing_city' => ['nullable', 'string', 'max:100'],
            'form.ccavenue_default_billing_state' => ['nullable', 'string', 'max:100'],
            'form.ccavenue_default_billing_zip' => ['nullable', 'string', 'max:20'],
            'form.ccavenue_default_billing_country' => ['nullable', 'string', 'max:100'],
            'form.ccavenue_default_billing_tel' => ['nullable', 'string', 'max:30'],
            'form.ccavenue_default_billing_email' => ['nullable', 'email', 'max:150'],

            'form.offline_enabled' => ['boolean'],
            'form.offline_bank_transfer_enabled' => ['boolean'],
            'form.offline_bank_name' => ['nullable', 'string', 'max:255'],
            'form.offline_account_name' => ['nullable', 'string', 'max:255'],
            'form.offline_account_number' => ['nullable', 'string', 'max:255'],
            'form.offline_ifsc_code' => ['nullable', 'string', 'max:50'],
            'form.offline_branch' => ['nullable', 'string', 'max:255'],
            'form.offline_upi_enabled' => ['boolean'],
            'form.offline_upi_id' => ['nullable', 'string', 'max:255'],
            'form.offline_upi_payee_name' => ['nullable', 'string', 'max:255'],
            'form.offline_cash_enabled' => ['boolean'],
            'form.offline_cash_instructions' => ['nullable', 'string'],

            'customQrImage' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:5120'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                $secrets = ['razorpay_key_secret', 'razorpay_webhook_secret', 'phonepe_salt_key', 'ccavenue_working_key'];
                $booleans = [
                    'enabled', 'razorpay_enabled', 'phonepe_enabled', 'ccavenue_enabled', 'ccavenue_billing_page_enabled',
                    'offline_enabled', 'offline_bank_transfer_enabled', 'offline_upi_enabled', 'offline_cash_enabled'
                ];
                $numbers = ['phonepe_salt_index'];

                foreach ($this->form as $key => $value) {
                    $fullKey = "payment.{$key}";

                    $type = Setting::TYPE_STRING;
                    if (in_array($key, $secrets, true)) {
                        $type = Setting::TYPE_SECRET;
                    } elseif (in_array($key, $booleans, true)) {
                        $type = Setting::TYPE_BOOLEAN;
                    } elseif (in_array($key, $numbers, true)) {
                        $type = Setting::TYPE_NUMBER;
                    } elseif (in_array($key, ['driver', 'phonepe_mode', 'ccavenue_mode'], true)) {
                        $type = Setting::TYPE_SELECT;
                    } elseif ($key === 'offline_cash_instructions') {
                        $type = Setting::TYPE_TEXT;
                    }

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'payment';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));

                    // Only overwrite secret if a new value was submitted
                    if (! in_array($key, $secrets, true) || trim((string) $value) !== '') {
                        $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    }

                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                if ($this->customQrImage) {
                    $path = $this->customQrImage->store('payments/qr', 'public');

                    $oldPath = Setting::get('payment.offline_custom_qr_image');
                    if (! empty($oldPath) && $oldPath !== $path && Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }

                    $qrSetting = Setting::withTrashed()->firstWhere('key', 'payment.offline_custom_qr_image')
                        ?? new Setting(['key' => 'payment.offline_custom_qr_image']);
                    if ($qrSetting->trashed()) {
                        $qrSetting->restore();
                    }
                    $qrSetting->group = 'payment';
                    $qrSetting->type = Setting::TYPE_IMAGE;
                    $qrSetting->label = 'Offline Custom QR Image';
                    $qrSetting->value = $path;
                    $qrSetting->status = true;
                    $qrSetting->updated_by = $userId;
                    $qrSetting->created_by ??= $userId;
                    $qrSetting->save();

                    $this->existingCustomQrUrl = Storage::disk('public')->url($path);
                    $this->customQrImage = null;
                }

                foreach (array_keys($this->secretConfigured) as $sec) {
                    $val = $this->form[$sec];
                    if (trim((string) $val) !== '') {
                        $this->secretConfigured[$sec] = true;
                    }
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'setting_updated',
                    description: 'Updated payment gateway configuration (Master: '.($this->form['enabled'] ? 'Enabled' : 'Disabled').', Razorpay: '.($this->form['razorpay_enabled'] ? 'On' : 'Off').', PhonePe: '.($this->form['phonepe_enabled'] ? 'On' : 'Off').', CCAVenue: '.($this->form['ccavenue_enabled'] ? 'On' : 'Off').', Offline: '.($this->form['offline_enabled'] ? 'On' : 'Off').').',
                    newValues: collect($this->form)->except($secrets)->all(),
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('Payment gateway settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save payment settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save payment settings.'));
        }
    }

    public function testCcavenueApi(): void
    {
        $gateway = new \App\Services\Payment\Gateways\CCAVenueGateway();
        $workingKey = ! empty($this->form['ccavenue_working_key']) ? $this->form['ccavenue_working_key'] : $gateway->getWorkingKey();

        try {
            $testPlain = 'sntcssc_ccavenue_diagnostic_'.time();
            $encrypted = $gateway->encrypt($testPlain, $workingKey);
            $decrypted = $gateway->decrypt($encrypted, $workingKey);

            if ($decrypted === $testPlain) {
                Toast::dispatch($this, 'success', __('CCAvenue AES-128 Encryption & Decryption test passed! Endpoint: :url', [
                    'url' => $gateway->getCheckoutUrl(),
                ]));
            } else {
                Toast::dispatch($this, 'error', __('CCAvenue diagnostic failed: Decrypted output did not match input string.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('CCAvenue diagnostic error: :err', ['err' => $e->getMessage()]));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="payment"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Payment Gateway Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Configure online payment gateways (Razorpay, PhonePe, CCAVenue) and offline payment fallbacks (Static UPI QR, NEFT/IMPS, Cash Counter).') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Global Payment Control --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="credit-card" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Master Payment Configuration') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Enable or disable online student fee checkouts and default primary gateway.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.switch
                        wire:model.live="form.enabled"
                        :label="__('Enable Online Student Payments')"
                        :description="__('When enabled, students can pay tuition fees, security deposits, and test fees online through active configured gateways.')"
                        :checked="(bool) $form['enabled']"
                    />
                </div>

                <x-ui.select
                    wire:model="form.driver"
                    :label="__('Default Primary Gateway') . ' *'"
                    :options="[
                        'razorpay' => 'Razorpay (Cards, UPI, NetBanking)',
                        'phonepe' => 'PhonePe PG (Direct UPI & QR)',
                        'ccavenue' => 'CCAVenue (Multi-Bank, NetBanking, Wallets)',
                        'offline' => 'Offline Gateway Only (Manual Verification)',
                    ]"
                />

                <x-ui.input
                    wire:model="form.currency"
                    :label="__('Payment Currency Code') . ' *'"
                    placeholder="INR"
                    required
                />
            </div>
        </div>

        {{-- Section 2: Razorpay Integration --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
                        <x-icon name="banknote" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('Razorpay Integration') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('API keys for Razorpay payment processing and webhooks.') }}</p>
                    </div>
                </div>

                <x-ui.switch
                    wire:model="form.razorpay_enabled"
                    :checked="(bool) $form['razorpay_enabled']"
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.input
                        wire:model="form.razorpay_key_id"
                        :label="__('Razorpay Key ID')"
                        placeholder="rzp_live_xxxxxxxxxxxx or rzp_test_xxxxxxxxxxxx"
                        autocomplete="off"
                    />
                </div>

                <div class="space-y-1">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground">{{ __('Razorpay Key Secret') }}</label>
                        @if($secretConfigured['razorpay_key_secret'])
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-600 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                                <x-icon name="shield-check" class="h-3 w-3"/> {{ __('Encrypted Secret Saved') }}
                            </span>
                        @else
                            <span class="text-[10px] text-amber-600 font-semibold">{{ __('Not configured') }}</span>
                        @endif
                    </div>
                    <x-ui.input
                        wire:model="form.razorpay_key_secret"
                        type="password"
                        :placeholder="$secretConfigured['razorpay_key_secret'] ? __('•••••••••••••••• (Leave blank to keep current secret)') : __('Enter Razorpay Key Secret')"
                        autocomplete="new-password"
                    />
                </div>

                <div class="space-y-1">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground">{{ __('Razorpay Webhook Secret') }}</label>
                        @if($secretConfigured['razorpay_webhook_secret'])
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-600 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                                <x-icon name="shield-check" class="h-3 w-3"/> {{ __('Secret Saved') }}
                            </span>
                        @endif
                    </div>
                    <x-ui.input
                        wire:model="form.razorpay_webhook_secret"
                        type="password"
                        :placeholder="$secretConfigured['razorpay_webhook_secret'] ? __('•••••••••••••••• (Leave blank to keep current secret)') : __('Enter Webhook Secret')"
                        autocomplete="new-password"
                        hint="{{ __('Required to verify asynchronous payment signatures.') }}"
                    />
                </div>
            </div>
        </div>

        {{-- Section 3: PhonePe Integration --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400">
                        <x-icon name="wallet" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('PhonePe PG Integration') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('Direct PhonePe UPI merchant gateway integration.') }}</p>
                    </div>
                </div>

                <x-ui.switch
                    wire:model="form.phonepe_enabled"
                    :checked="(bool) $form['phonepe_enabled']"
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.input
                        wire:model="form.phonepe_merchant_id"
                        :label="__('PhonePe Merchant ID')"
                        placeholder="M22XXXXXXXXX"
                    />
                </div>

                <x-ui.select
                    wire:model="form.phonepe_mode"
                    :label="__('PhonePe Gateway Mode')"
                    :options="[
                        'UAT' => 'UAT (Sandbox Testing)',
                        'LIVE' => 'LIVE (Production)',
                    ]"
                />

                <div class="sm:col-span-2 space-y-1">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground">{{ __('PhonePe Salt Key') }}</label>
                        @if($secretConfigured['phonepe_salt_key'])
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-600 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                                <x-icon name="shield-check" class="h-3 w-3"/> {{ __('Encrypted Salt Key Saved') }}
                            </span>
                        @else
                            <span class="text-[10px] text-amber-600 font-semibold">{{ __('Not configured') }}</span>
                        @endif
                    </div>
                    <x-ui.input
                        wire:model="form.phonepe_salt_key"
                        type="password"
                        :placeholder="$secretConfigured['phonepe_salt_key'] ? __('•••••••••••••••• (Leave blank to keep current salt key)') : __('Enter PhonePe Salt Key')"
                        autocomplete="new-password"
                    />
                </div>

                <x-ui.input
                    wire:model="form.phonepe_salt_index"
                    :label="__('Salt Index (Key Index)')"
                    type="number"
                    placeholder="1"
                />
            </div>
        </div>

        {{-- Section 4: CCAVenue Integration --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-5 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                        <x-icon name="building-2" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('CCAVenue Payment Gateway') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('Enterprise merchant credentials for CCAVenue payment processing & banking compliance.') }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" wire:click="testCcavenueApi" class="px-2.5 py-1 text-xs font-medium rounded-lg border border-border bg-muted/50 hover:bg-muted text-foreground transition-colors inline-flex items-center gap-1.5">
                        <x-icon name="shield-check" class="h-3.5 w-3.5 text-emerald-600"/>
                        {{ __('Test AES Encryption') }}
                    </button>
                    <x-ui.switch
                        wire:model="form.ccavenue_enabled"
                        :checked="(bool) $form['ccavenue_enabled']"
                    />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-1">
                    <x-ui.input
                        wire:model="form.ccavenue_merchant_id"
                        :label="__('CCAVenue Merchant ID')"
                        placeholder="2981923"
                    />
                </div>

                <div class="sm:col-span-1">
                    <x-ui.input
                        wire:model="form.ccavenue_access_code"
                        :label="__('CCAVenue Access Code')"
                        placeholder="AVKZ02KL..."
                    />
                </div>

                <div class="sm:col-span-1">
                    <x-ui.select
                        wire:model="form.ccavenue_mode"
                        :label="__('Environment Mode')"
                        :options="[
                            'test' => 'Test (Sandbox Environment)',
                            'live' => 'Live (Production Environment)',
                        ]"
                    />
                </div>

                <div class="sm:col-span-3 space-y-1">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground">{{ __('CCAVenue Working Key (AES-128 Key)') }}</label>
                        @if($secretConfigured['ccavenue_working_key'])
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-600 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                                <x-icon name="shield-check" class="h-3 w-3"/> {{ __('Encrypted Key Saved') }}
                            </span>
                        @else
                            <span class="text-[10px] text-amber-600 font-semibold">{{ __('Auto test fallback active in sandbox mode') }}</span>
                        @endif
                    </div>
                    <x-ui.input
                        wire:model="form.ccavenue_working_key"
                        type="password"
                        :placeholder="$secretConfigured['ccavenue_working_key'] ? __('•••••••••••••••• (Leave blank to keep current key)') : __('Enter 32-character AES Working Key (or leave blank in test mode)')"
                        autocomplete="new-password"
                        hint="{{ __('Used to encrypt merchant payment requests and decrypt gateway confirmation responses. In test mode, a standard test key is auto-used if left blank.') }}"
                    />
                </div>

                {{-- CCAvenue Checkout Billing Information Step Toggle --}}
                <div class="sm:col-span-3 rounded-xl border border-border bg-muted/30 p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="space-y-0.5">
                            <label class="text-xs font-semibold text-foreground flex items-center gap-2">
                                <x-icon name="file-text" class="h-4 w-4 text-primary"/>
                                {{ __('Billing Information Step on Checkout Page') }}
                            </label>
                            <p class="text-[11px] text-muted-foreground">
                                {{ __('Enable if your CCAvenue merchant account still requires the customer billing details form on the gateway checkout page.') }}
                            </p>
                        </div>
                        <x-ui.switch
                            wire:model="form.ccavenue_billing_page_enabled"
                            :checked="(bool) $form['ccavenue_billing_page_enabled']"
                        />
                    </div>

                    {{-- Important Banking & 6-Month Warning Notice --}}
                    <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 space-y-1.5 text-xs">
                        <div class="flex items-center gap-2 font-semibold text-amber-800 dark:text-amber-300">
                            <x-icon name="alert-triangle" class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"/>
                            {{ __('Important Compliance & 6-Month Penalty Warning (CCAvenue Mandate)') }}
                        </div>
                        <p class="text-[11px] text-amber-900/90 dark:text-amber-200/90 leading-relaxed">
                            {{ __('If the Billing Information step has been removed or turned off from your CCAvenue checkout pages, our backend mode automatically posts the billing country, customer contact number, and email ID with every order. If these required fields are ever omitted from any live transaction, CCAvenue and issuing banks will reactivate the billing information step without notice for a mandatory period of 6 months.') }}
                        </p>
                    </div>
                </div>

                {{-- Default Fallback Billing Details (Auto-injected if student profile is incomplete) --}}
                <div class="sm:col-span-3 rounded-xl border border-border bg-card p-4 space-y-3">
                    <div class="border-b border-border pb-2">
                        <h3 class="text-xs font-semibold text-foreground flex items-center gap-2">
                            <x-icon name="map-pin" class="h-3.5 w-3.5 text-primary"/>
                            {{ __('Default Fallback Billing Information (Safeguards Backend Transmission)') }}
                        </h3>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            {{ __('When a student profile is missing address or contact parameters, these defaults will be sent from the backend to ensure zero rejections and prevent CCAvenue from re-enabling billing checkout.') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <div class="sm:col-span-2">
                            <x-ui.input
                                wire:model="form.ccavenue_default_billing_address"
                                :label="__('Default Address Line')"
                                placeholder="Kolkata"
                            />
                        </div>
                        <div class="sm:col-span-1">
                            <x-ui.input
                                wire:model="form.ccavenue_default_billing_city"
                                :label="__('Default City')"
                                placeholder="Kolkata"
                            />
                        </div>
                        <div class="sm:col-span-1">
                            <x-ui.input
                                wire:model="form.ccavenue_default_billing_state"
                                :label="__('Default State')"
                                placeholder="West Bengal"
                            />
                        </div>
                        <div class="sm:col-span-1">
                            <x-ui.input
                                wire:model="form.ccavenue_default_billing_zip"
                                :label="__('Default ZIP / Postal Code')"
                                placeholder="700106"
                            />
                        </div>
                        <div class="sm:col-span-1">
                            <x-ui.input
                                wire:model="form.ccavenue_default_billing_country"
                                :label="__('Default Country')"
                                placeholder="India"
                            />
                        </div>
                        <div class="sm:col-span-1">
                            <x-ui.input
                                wire:model="form.ccavenue_default_billing_tel"
                                :label="__('Default Fallback Contact No')"
                                placeholder="9999999999"
                            />
                        </div>
                        <div class="sm:col-span-1">
                            <x-ui.input
                                wire:model="form.ccavenue_default_billing_email"
                                :label="__('Default Fallback Email ID')"
                                placeholder="info@sntcssc.in"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 5: Offline Gateway & Fallback Configuration --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <x-icon name="qr-code" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('Offline Payment Gateway & Fallback') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('Displayed as fallback when online gateways are inactive or credentials fail, or as an alternative student checkout option.') }}</p>
                    </div>
                </div>

                <x-ui.switch
                    wire:model="form.offline_enabled"
                    :checked="(bool) $form['offline_enabled']"
                />
            </div>

            <div class="space-y-5">
                {{-- Static UPI QR Section --}}
                <div class="p-4 rounded-lg bg-muted/20 border border-border/60 space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-icon name="smartphone" class="h-4 w-4 text-primary"/>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Static UPI QR Code Checkout') }}</span>
                        </div>
                        <x-ui.switch wire:model="form.offline_upi_enabled" :checked="(bool) $form['offline_upi_enabled']"/>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.input
                            wire:model="form.offline_upi_id"
                            :label="__('Institutional UPI ID (VPA)')"
                            placeholder="sntcssc@indianbk"
                        />
                        <x-ui.input
                            wire:model="form.offline_upi_payee_name"
                            :label="__('Payee Display Name')"
                            placeholder="SNTCSSC Kolkata"
                        />
                    </div>

                    {{-- Custom Standee / Official UPI QR Image Upload --}}
                    <div class="pt-3 border-t border-border/60">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                            <div>
                                <label class="text-xs font-bold text-foreground block">{{ __('Custom QR Code Image (Standee / Bank UPI Sticker)') }}</label>
                                <p class="text-[11px] text-muted-foreground">{{ __('Upload your official Bank or Merchant UPI QR image. If uploaded, students will see this image during checkout instead of the auto-generated QR code.') }}</p>
                            </div>
                            @if($existingCustomQrUrl || $customQrImage)
                                <button type="button" wire:click="removeCustomQrImage" wire:confirm="{{ __('Are you sure you want to remove the custom QR code image? The system will revert to dynamically generated UPI QR codes.') }}" class="inline-flex items-center gap-1 text-[11px] text-rose-600 hover:text-rose-700 font-semibold px-2 py-1 rounded bg-rose-500/10 hover:bg-rose-500/20 transition-colors">
                                    <x-icon name="trash-2" class="h-3 w-3"/>
                                    {{ __('Remove Custom QR') }}
                                </button>
                            @endif
                        </div>

                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 p-3 rounded-lg bg-card border border-border">
                            <div class="shrink-0 h-28 w-28 rounded-lg border border-border bg-muted/40 flex items-center justify-center overflow-hidden p-1.5 shadow-2xs">
                                @if($customQrImage)
                                    <img src="{{ $customQrImage->temporaryUrl() }}" alt="Custom QR Preview" class="h-full w-full object-contain rounded"/>
                                @elseif($existingCustomQrUrl)
                                    <img src="{{ $existingCustomQrUrl }}" alt="Existing Custom QR" class="h-full w-full object-contain rounded"/>
                                @else
                                    <div class="flex flex-col items-center justify-center text-center p-2 text-muted-foreground">
                                        <x-icon name="qr-code" class="h-8 w-8 stroke-1 text-muted-foreground/60"/>
                                        <span class="text-[9px] mt-1">{{ __('No Custom QR') }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="space-y-2 flex-1">
                                <div class="flex items-center gap-2">
                                    @if($customQrImage)
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 bg-blue-500/10 px-2 py-0.5 rounded-full">
                                            <x-icon name="clock" class="h-3 w-3"/> {{ __('New QR Selected (Save changes to apply)') }}
                                        </span>
                                    @elseif($existingCustomQrUrl)
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                                            <x-icon name="check-circle" class="h-3 w-3"/> {{ __('Custom QR Standee Active') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-muted-foreground bg-muted px-2 py-0.5 rounded-full">
                                            <x-icon name="sparkles" class="h-3 w-3 text-amber-500"/> {{ __('Currently Using Dynamic UPI QR Generation') }}
                                        </span>
                                    @endif
                                </div>

                                <div>
                                    <input type="file" wire:model="customQrImage" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="block w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:opacity-90"/>
                                    @error('customQrImage') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                                    <span wire:loading wire:target="customQrImage" class="text-[11px] text-primary animate-pulse block mt-1">{{ __('Processing QR image upload...') }}</span>
                                </div>
                                <p class="text-[10px] text-muted-foreground">{{ __('Supported formats: PNG, JPG, WebP, SVG. Max file size: 5MB.') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bank Transfer / NEFT / IMPS Section --}}
                <div class="p-4 rounded-lg bg-muted/20 border border-border/60 space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-icon name="landmark" class="h-4 w-4 text-primary"/>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Institutional Bank Transfer (NEFT / IMPS / RTGS)') }}</span>
                        </div>
                        <x-ui.switch wire:model="form.offline_bank_transfer_enabled" :checked="(bool) $form['offline_bank_transfer_enabled']"/>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-ui.input
                            wire:model="form.offline_bank_name"
                            :label="__('Bank Name')"
                            placeholder="State Bank of India"
                        />
                        <x-ui.input
                            wire:model="form.offline_account_name"
                            :label="__('Beneficiary Account Name')"
                            placeholder="Satyendranath Tagore CSSC"
                        />
                        <x-ui.input
                            wire:model="form.offline_account_number"
                            :label="__('Account Number')"
                            placeholder="39824589201"
                        />
                        <x-ui.input
                            wire:model="form.offline_ifsc_code"
                            :label="__('IFSC Code')"
                            placeholder="SBIN0000123"
                        />
                        <div class="sm:col-span-2">
                            <x-ui.input
                                wire:model="form.offline_branch"
                                :label="__('Bank Branch Name & City')"
                                placeholder="Kolkata Main Branch, West Bengal"
                            />
                        </div>
                    </div>
                </div>

                {{-- In-Person Cash Payment Section --}}
                <div class="p-4 rounded-lg bg-muted/20 border border-border/60 space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-icon name="hand-coins" class="h-4 w-4 text-primary"/>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ __('In-Person Cash Payment at Office Counter') }}</span>
                        </div>
                        <x-ui.switch wire:model="form.offline_cash_enabled" :checked="(bool) $form['offline_cash_enabled']"/>
                    </div>

                    <x-ui.textarea
                        wire:model="form.offline_cash_instructions"
                        :label="__('Student Counter Instructions')"
                        rows="2"
                        placeholder="Pay cash directly at Accounts Counter..."
                    />
                </div>
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
