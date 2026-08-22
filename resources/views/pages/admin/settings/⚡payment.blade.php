<?php

use App\Models\Setting;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Payment Gateway Settings')] class extends Component {
    public array $form = [
        'enabled' => false,
        'driver' => 'razorpay',
        'currency' => 'INR',
        'razorpay_enabled' => false,
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'razorpay_webhook_secret' => '',
        'phonepe_enabled' => false,
        'phonepe_merchant_id' => '',
        'phonepe_salt_key' => '',
        'phonepe_salt_index' => 1,
        'phonepe_mode' => 'UAT',
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
    }

    public function save(): void
    {
        $this->validate([
            'form.enabled' => ['boolean'],
            'form.driver' => ['required', 'string', 'in:razorpay,phonepe,stripe'],
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
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                $secrets = ['razorpay_key_secret', 'razorpay_webhook_secret', 'phonepe_salt_key'];
                $booleans = ['enabled', 'razorpay_enabled', 'phonepe_enabled'];
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
                    } elseif (in_array($key, ['driver', 'phonepe_mode'], true)) {
                        $type = Setting::TYPE_SELECT;
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

                Setting::flushCache();
            });

            Toast::dispatch($this, 'success', __('Payment gateway settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save payment settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save payment settings.'));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="payment"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Payment Gateway Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Configure online fee payments, Razorpay and PhonePe merchant integration keys.') }}</p>
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
                    <p class="text-[11px] text-muted-foreground">{{ __('Enable or disable online student fee checkouts.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.switch
                        wire:model.live="form.enabled"
                        :label="__('Enable Online Student Payments')"
                        :description="__('When enabled, students can pay admission and exam fees online through active gateways.')"
                        :checked="(bool) $form['enabled']"
                    />
                </div>

                <x-ui.select
                    wire:model="form.driver"
                    :label="__('Default Active Gateway') . ' *'"
                    :options="[
                        'razorpay' => 'Razorpay (Cards, UPI, NetBanking)',
                        'phonepe' => 'PhonePe PG (Direct UPI & QR)',
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
                        <h2 class="text-sm font-semibold">Razorpay Integration</h2>
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

                <x-ui.input
                    wire:model="form.razorpay_key_secret"
                    :label="__('Razorpay Key Secret')"
                    type="password"
                    placeholder="{{ __('Leave blank to keep stored secret') }}"
                    autocomplete="new-password"
                />

                <x-ui.input
                    wire:model="form.razorpay_webhook_secret"
                    :label="__('Razorpay Webhook Secret')"
                    type="password"
                    placeholder="{{ __('Leave blank to keep stored secret') }}"
                    autocomplete="new-password"
                    hint="{{ __('Required to verify asynchronous payment signatures.') }}"
                />
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
                        <h2 class="text-sm font-semibold">PhonePe PG Integration</h2>
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

                <div class="sm:col-span-2">
                    <x-ui.input
                        wire:model="form.phonepe_salt_key"
                        :label="__('PhonePe Salt Key')"
                        type="password"
                        placeholder="{{ __('Leave blank to keep stored secret') }}"
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

        <div class="flex items-center justify-end gap-3 pt-2">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    </form>
</div>
