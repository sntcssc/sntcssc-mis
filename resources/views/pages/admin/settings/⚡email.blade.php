<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Email Provider Settings')] class extends Component {
    public array $form = [
        'is_enabled' => true,
        'driver' => 'smtp',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
        'from_address' => 'noreply@sntcssc.in',
        'from_name' => 'SNT CSSC MIS',
        'cc_to' => '',
        'send_to' => '',
    ];

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'email')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "email.{$key}";
            if (isset($settings[$fullKey])) {
                if ($key === 'is_enabled') {
                    $this->form[$key] = (bool) $settings[$fullKey]->typed();
                } elseif ($key === 'smtp_password' && $settings[$fullKey]->type === Setting::TYPE_SECRET) {
                    $this->form[$key] = '';
                } else {
                    $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
                }
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.is_enabled' => ['boolean'],
            'form.driver' => ['required', 'string', 'in:smtp,log,sendmail,mailgun,ses'],
            'form.smtp_host' => ['nullable', 'string', 'max:255'],
            'form.smtp_port' => ['nullable', 'numeric', 'min:1', 'max:65535'],
            'form.smtp_username' => ['nullable', 'string', 'max:255'],
            'form.smtp_password' => ['nullable', 'string', 'max:255'],
            'form.smtp_encryption' => ['nullable', 'string', 'in:tls,ssl,none'],
            'form.from_address' => ['required', 'email', 'max:255'],
            'form.from_name' => ['required', 'string', 'max:255'],
            'form.cc_to' => ['nullable', 'string', 'max:255'],
            'form.send_to' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "email.{$key}";
                    $type = match ($key) {
                        'is_enabled' => Setting::TYPE_BOOLEAN,
                        'smtp_port' => Setting::TYPE_NUMBER,
                        'smtp_password' => Setting::TYPE_SECRET,
                        'driver', 'smtp_encryption' => Setting::TYPE_SELECT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'email';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));

                    // Only update secret password if not left blank
                    if ($key !== 'smtp_password' || trim((string) $value) !== '') {
                        $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    }

                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'setting_updated',
                    description: 'Updated email provider and SMTP delivery settings.',
                    newValues: collect($this->form)->except('smtp_password')->all(),
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('Email provider settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save email settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save email settings.'));
        }
    }

    public function sendTestEmail(): void
    {
        $this->validate([
            'form.send_to' => ['required', 'email'],
        ], [
            'form.send_to.required' => __('Please provide an email address to receive the test message.'),
        ]);

        try {
            $to = $this->form['send_to'];
            $fromName = $this->form['from_name'] ?: 'SNT CSSC MIS';
            $fromAddress = $this->form['from_address'] ?: 'noreply@sntcssc.in';

            Mail::raw(
                "Hello,\n\nThis is a verification test email sent from SNT CSSC MIS.\n\nTime: ".now()->toDayDateTimeString()."\nDriver: ".$this->form['driver']."\n\nIf you received this message, your mail configuration is functioning properly.",
                function ($message) use ($to, $fromName, $fromAddress) {
                    $message->to($to)
                        ->subject('SNT CSSC MIS — Email Delivery Test')
                        ->from($fromAddress, $fromName);
                }
            );

            Toast::dispatch($this, 'success', __('Test email dispatched to :email.', ['email' => $to]));
        } catch (\Throwable $e) {
            Log::error('Test email sending failed: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Email delivery failed: :msg', ['msg' => $e->getMessage()]));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="email"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Email Provider & SMTP Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Configure mail dispatching, SMTP credentials, sender identity and verify deliverability.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Mail Transport Service --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="mail" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Mail Transport & Driver') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Select your primary email delivery service.') }}</p>
                </div>
            <div class="p-3.5 rounded-lg border border-border bg-secondary/15">
                <x-ui.switch
                    wire:model="form.is_enabled"
                    :label="__('Enable Email Dispatching')"
                    :description="__('When disabled, outgoing system emails will be suppressed.')"
                    :checked="(bool) $form['is_enabled']"
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.select
                    wire:model.live="form.driver"
                    :label="__('Mail Driver') . ' *'"
                    :options="[
                        'smtp' => __('SMTP Server (Recommended for Production)'),
                        'log' => __('Log File (Development & Testing)'),
                        'sendmail' => __('Sendmail (Server Daemon)'),
                        'mailgun' => 'Mailgun API',
                        'ses' => 'Amazon Simple Email Service (SES)',
                    ]"
                />
            </div>
        </div>

        {{-- Section 2: SMTP Credentials --}}
        @if ($form['driver'] === 'smtp')
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
                <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon name="lock" class="h-4 w-4"/>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('SMTP Server Credentials') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('Host, port and authenticated user details.') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <x-ui.input
                            wire:model="form.smtp_host"
                            :label="__('SMTP Host')"
                            placeholder="smtp.mailtrap.io or smtp.gmail.com"
                        />
                    </div>

                    <x-ui.input
                        wire:model="form.smtp_port"
                        :label="__('SMTP Port')"
                        type="number"
                        placeholder="587"
                    />

                    <x-ui.select
                        wire:model="form.smtp_encryption"
                        :label="__('Encryption Protocol')"
                        :options="[
                            'tls' => 'TLS (STARTTLS)',
                            'ssl' => 'SSL',
                            'none' => __('None (Unencrypted)'),
                        ]"
                    />

                    <x-ui.input
                        wire:model="form.smtp_username"
                        :label="__('SMTP Username')"
                        placeholder="your-username"
                        autocomplete="off"
                    />

                    <x-ui.input
                        wire:model="form.smtp_password"
                        :label="__('SMTP Password / App Password')"
                        type="password"
                        placeholder="{{ __('Leave blank to keep stored secret') }}"
                        autocomplete="new-password"
                    />
                </div>
            </div>
        @endif

        {{-- Section 3: Sender Identity --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="user" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('From Sender Information') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Default sender name and email address for transactional notifications.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.input
                    wire:model="form.from_address"
                    :label="__('From Email Address') . ' *'"
                    type="email"
                    placeholder="noreply@sntcssc.in"
                    required
                />

                <x-ui.input
                    wire:model="form.from_name"
                    :label="__('From Sender Name') . ' *'"
                    placeholder="SNT CSSC MIS"
                    required
                />

                <x-ui.input
                    wire:model="form.cc_to"
                    :label="__('Default CC Recipient')"
                    placeholder="admin@sntcssc.in"
                    hint="{{ __('Optional copy address.') }}"
                />
            </div>
        </div>

        {{-- Section 4: Email Verification & Test Delivery --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="send" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Deliverability Test') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Send a test email to verify SMTP connection and spam score.') }}</p>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-end gap-3">
                <div class="flex-1 w-full">
                    <x-ui.input
                        wire:model="form.send_to"
                        :label="__('Test Email Recipient')"
                        type="email"
                        placeholder="your-email@example.com"
                    />
                </div>

                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="sendTestEmail"
                    wire:loading.attr="disabled"
                    wire:target="sendTestEmail"
                    class="h-9 shrink-0"
                >
                    <x-icon name="send" class="h-4 w-4 mr-1.5" wire:loading.remove wire:target="sendTestEmail"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 mr-1.5 animate-spin" wire:loading wire:target="sendTestEmail"/>
                    {{ __('Send Test Email') }}
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
