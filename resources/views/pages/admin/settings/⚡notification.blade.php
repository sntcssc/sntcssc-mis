<?php

use App\Models\AppNotification;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Notification & Channel Settings')] class extends Component {
    public array $form = [
        // Realtime Transport Engine
        'realtime_driver' => 'hybrid', // hybrid, polling, broadcasting
        'poll_interval' => '3s',       // 3s, 5s, 10s, 30s
        'sound_enabled' => true,
        'browser_push_enabled' => true,

        // Master Channels Enable/Disable
        'channel_database' => true,
        'channel_email' => true,
        'channel_sms' => false,
        'channel_whatsapp' => false,
        'channel_telegram' => false,

        // WhatsApp Gateway Configuration
        'whatsapp_enabled' => false,
        'whatsapp_driver' => 'log', // meta, twilio, ultramsg, log
        'whatsapp_api_token' => '',
        'whatsapp_phone_number_id' => '',
        'whatsapp_business_account_id' => '',
        'whatsapp_base_url' => 'https://graph.facebook.com/v21.0',
        'whatsapp_twilio_sid' => '',
        'whatsapp_twilio_token' => '',
        'whatsapp_twilio_from' => '',
        'whatsapp_ultramsg_instance_id' => '',
        'whatsapp_ultramsg_token' => '',
        'whatsapp_http_timeout' => 15,
        'whatsapp_http_retries' => 2,

        // Telegram Gateway Configuration
        'telegram_enabled' => false,
        'telegram_driver' => 'log', // telegram, log
        'telegram_bot_token' => '',
        'telegram_default_chat_id' => '',
        'telegram_parse_mode' => 'HTML',
        'telegram_http_timeout' => 15,
        'telegram_http_retries' => 2,
    ];

    // Testing Sandbox State
    public string $testWhatsAppPhone = '';
    public string $testWhatsAppMessage = '';
    public string $testTelegramChatId = '';
    public string $testTelegramMessage = '';

    public string $sandboxChannel = 'database';
    public string $sandboxRecipient = '';
    public string $sandboxTitle = '';
    public string $sandboxMessage = '';
    public ?string $sandboxResult = null;
    public bool $sandboxSuccess = false;

    public function mount(): void
    {
        $settings = Setting::query()
            ->whereIn('group', ['notification', 'whatsapp', 'telegram'])
            ->get()
            ->keyBy('key');

        // Load Notification group settings
        foreach ($this->form as $key => $default) {
            $group = str_starts_with($key, 'whatsapp_') ? 'whatsapp' : (str_starts_with($key, 'telegram_') ? 'telegram' : 'notification');
            $pureKey = str_starts_with($key, 'whatsapp_') ? substr($key, 9) : (str_starts_with($key, 'telegram_') ? substr($key, 9) : $key);
            $fullKey = "{$group}.{$pureKey}";

            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_SECRET) {
                    $this->form[$key] = ''; // Do not leak secrets
                } elseif ($setting->type === Setting::TYPE_NUMBER) {
                    $this->form[$key] = (int) $setting->typed();
                } else {
                    $this->form[$key] = (string) ($setting->rawValue() ?? $default);
                }
            }
        }

        $this->sandboxTitle = __('System Verification Notice');
        $this->sandboxMessage = __('This is a real-time verification notification dispatched from the admin settings panel.');
        $this->sandboxRecipient = auth()->user()?->email ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'form.realtime_driver' => ['required', 'string', 'in:hybrid,polling,broadcasting'],
            'form.poll_interval' => ['required', 'string', 'in:3s,5s,10s,30s'],
            'form.sound_enabled' => ['boolean'],
            'form.browser_push_enabled' => ['boolean'],
            'form.channel_database' => ['boolean'],
            'form.channel_email' => ['boolean'],
            'form.channel_sms' => ['boolean'],
            'form.channel_whatsapp' => ['boolean'],
            'form.channel_telegram' => ['boolean'],

            'form.whatsapp_enabled' => ['boolean'],
            'form.whatsapp_driver' => ['required', 'string', 'in:meta,twilio,ultramsg,log'],
            'form.whatsapp_api_token' => ['nullable', 'string', 'max:500'],
            'form.whatsapp_phone_number_id' => ['nullable', 'string', 'max:100'],
            'form.whatsapp_business_account_id' => ['nullable', 'string', 'max:100'],
            'form.whatsapp_base_url' => ['nullable', 'url', 'max:255'],
            'form.whatsapp_twilio_sid' => ['nullable', 'string', 'max:100'],
            'form.whatsapp_twilio_token' => ['nullable', 'string', 'max:100'],
            'form.whatsapp_twilio_from' => ['nullable', 'string', 'max:50'],
            'form.whatsapp_ultramsg_instance_id' => ['nullable', 'string', 'max:100'],
            'form.whatsapp_ultramsg_token' => ['nullable', 'string', 'max:100'],
            'form.whatsapp_http_timeout' => ['required', 'numeric', 'min:1', 'max:60'],
            'form.whatsapp_http_retries' => ['required', 'numeric', 'min:0', 'max:5'],

            'form.telegram_enabled' => ['boolean'],
            'form.telegram_driver' => ['required', 'string', 'in:telegram,log'],
            'form.telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'form.telegram_default_chat_id' => ['nullable', 'string', 'max:100'],
            'form.telegram_parse_mode' => ['required', 'string', 'in:HTML,Markdown,MarkdownV2'],
            'form.telegram_http_timeout' => ['required', 'numeric', 'min:1', 'max:60'],
            'form.telegram_http_retries' => ['required', 'numeric', 'min:0', 'max:5'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $group = str_starts_with($key, 'whatsapp_') ? 'whatsapp' : (str_starts_with($key, 'telegram_') ? 'telegram' : 'notification');
                    $pureKey = str_starts_with($key, 'whatsapp_') ? substr($key, 9) : (str_starts_with($key, 'telegram_') ? substr($key, 9) : $key);
                    $fullKey = "{$group}.{$pureKey}";

                    $type = match ($pureKey) {
                        'sound_enabled', 'browser_push_enabled', 'channel_database', 'channel_email',
                        'channel_sms', 'channel_whatsapp', 'channel_telegram', 'enabled' => Setting::TYPE_BOOLEAN,
                        'api_token', 'bot_token', 'twilio_token', 'ultramsg_token' => Setting::TYPE_SECRET,
                        'http_timeout', 'http_retries' => Setting::TYPE_NUMBER,
                        'realtime_driver', 'poll_interval', 'driver', 'parse_mode' => Setting::TYPE_SELECT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = $group;
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace(['_', '.'], ' ', $fullKey));

                    // Only update secret fields if not left blank
                    if ($type === Setting::TYPE_SECRET && trim((string) $value) === '') {
                        // Skip updating empty secret
                    } else {
                        $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    }

                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                Setting::flushCache();

                AuditLogService::log(
                    event: 'notification_settings_updated',
                    description: 'Updated system realtime and multi-channel notification settings',
                    newValues: [
                        'realtime_driver' => $this->form['realtime_driver'],
                        'poll_interval' => $this->form['poll_interval'],
                        'channels' => [
                            'database' => $this->form['channel_database'],
                            'email' => $this->form['channel_email'],
                            'sms' => $this->form['channel_sms'],
                            'whatsapp' => $this->form['channel_whatsapp'],
                            'telegram' => $this->form['channel_telegram'],
                        ],
                    ],
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('Notification & Gateway settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save notification settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save settings: :error', ['error' => $e->getMessage()]));
        }
    }

    public function testWhatsApp(): void
    {
        $this->validate([
            'testWhatsAppPhone' => ['required', 'string', 'min:7'],
        ]);

        /** @var WhatsAppService $whatsAppService */
        $whatsAppService = app(WhatsAppService::class);
        $result = $whatsAppService->sendTestMessage($this->testWhatsAppPhone, $this->testWhatsAppMessage);

        if ($result['success']) {
            Toast::dispatch($this, 'success', $result['message']);
        } else {
            Toast::dispatch($this, 'error', $result['message']);
        }
    }

    public function testTelegram(): void
    {
        $this->validate([
            'testTelegramChatId' => ['required', 'string', 'min:3'],
        ]);

        /** @var TelegramService $telegramService */
        $telegramService = app(TelegramService::class);
        $result = $telegramService->sendTestMessage($this->testTelegramChatId, $this->testTelegramMessage);

        if ($result['success']) {
            Toast::dispatch($this, 'success', $result['message']);
        } else {
            Toast::dispatch($this, 'error', $result['message']);
        }
    }

    public function runSandboxDispatch(): void
    {
        $this->validate([
            'sandboxTitle' => ['required', 'string', 'max:200'],
            'sandboxMessage' => ['required', 'string', 'max:1000'],
        ]);

        /** @var NotificationService $notificationService */
        $notificationService = app(NotificationService::class);
        $currentUser = auth()->user();

        try {
            $notification = $notificationService->send(
                user: $currentUser,
                title: $this->sandboxTitle,
                message: $this->sandboxMessage,
                category: AppNotification::CATEGORY_SYSTEM,
                options: [
                    'type' => 'system_test',
                    'action_url' => route('dashboard'),
                    'action_label' => __('Go to Dashboard'),
                    'channels' => $this->sandboxChannel === 'all'
                        ? [AppNotification::CHANNEL_DATABASE, AppNotification::CHANNEL_EMAIL, AppNotification::CHANNEL_SMS, AppNotification::CHANNEL_WHATSAPP, AppNotification::CHANNEL_TELEGRAM]
                        : [$this->sandboxChannel],
                ]
            );

            $this->sandboxSuccess = true;
            $this->sandboxResult = __('Real-time notification test dispatched successfully! Notification UUID: :uuid. Check your topbar bell!', ['uuid' => $notification?->uuid ?? 'Broadcasted']);
            Toast::dispatch($this, 'success', $this->sandboxResult);
        } catch (\Throwable $e) {
            $this->sandboxSuccess = false;
            $this->sandboxResult = __('Dispatch failed: :error', ['error' => $e->getMessage()]);
            Toast::dispatch($this, 'error', $this->sandboxResult);
        }
    }

};
?>

<div class="space-y-6 max-w-7xl mx-auto pb-12">
    <!-- Breadcrumb & Settings Nav -->
    <x-settings-nav active="notification" />

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border pb-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <div class="p-2 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="bell" class="h-6 w-6" />
                </div>
                <span>{{ __('Realtime & Multi-Channel Notification Settings') }}</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Configure real-time communication transport, channel master toggles, and WhatsApp/Telegram enterprise gateways.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button wire:click="save" variant="default" icon="check">
                {{ __('Save All Changes') }}
            </x-ui.button>
        </div>

    </div>

    <!-- 1. Realtime Transport Engine Configuration -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-lg bg-blue-500/10 text-blue-500 flex items-center justify-center">
                    <x-icon name="activity" class="h-5 w-5" />
                </div>
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ __('Realtime Delivery Engine & Audio Alerts') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ __('Control how real-time events reach active user browser tabs.') }}</p>
                </div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $form['realtime_driver'] === 'hybrid' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-primary/10 text-primary' }}">
                <span class="h-1.5 w-1.5 rounded-full bg-current animate-pulse"></span>
                {{ ucfirst($form['realtime_driver']) }}
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 pt-2">
            <!-- Transport Mode -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Transport Driver') }}
                </label>
                <select wire:model.live="form.realtime_driver" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="hybrid">{{ __('Hybrid (Auto WebSocket + Fallback Poll)') }}</option>
                    <option value="polling">{{ __('Livewire Polling Only (wire:poll)') }}</option>
                    <option value="broadcasting">{{ __('Broadcasting / Reverb WebSockets') }}</option>
                </select>
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('Hybrid provides instant WebSocket push with automatic polling fallback.') }}
                </p>
            </div>

            <!-- Polling Interval -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Polling Interval') }}
                </label>
                <select wire:model.live="form.poll_interval" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="3s">3 {{ __('Seconds (Recommended for Realtime)') }}</option>
                    <option value="5s">5 {{ __('Seconds') }}</option>
                    <option value="10s">10 {{ __('Seconds') }}</option>
                    <option value="30s">30 {{ __('Seconds (Low Traffic)') }}</option>
                </select>
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('Polling heartbeat rate for in-app topbar unread notifications counter.') }}
                </p>
            </div>

            <!-- Sound Alert Toggle -->
            <div class="flex flex-col justify-between p-3 rounded-lg border border-border bg-secondary/20">
                <div class="flex items-center justify-between">
                    <div class="space-y-0.5">
                        <span class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                            <x-icon name="volume-2" class="h-3.5 w-3.5 text-primary" />
                            {{ __('Audio Chime Alerts') }}
                        </span>
                        <p class="text-[11px] text-muted-foreground">{{ __('Subtle crystal sound on arrival') }}</p>
                    </div>
                    <x-ui.switch wire:model.live="form.sound_enabled" />
                </div>
            </div>

            <!-- Desktop Web Push -->
            <div class="flex flex-col justify-between p-3 rounded-lg border border-border bg-secondary/20">
                <div class="flex items-center justify-between">
                    <div class="space-y-0.5">
                        <span class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                            <x-icon name="monitor" class="h-3.5 w-3.5 text-primary" />
                            {{ __('Browser Push Notification') }}
                        </span>
                        <p class="text-[11px] text-muted-foreground">{{ __('Native desktop push banner') }}</p>
                    </div>
                    <x-ui.switch wire:model.live="form.browser_push_enabled" />
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Master Channels Enable / Disable Matrix -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-4">
        <div class="flex items-center gap-3">
            <div class="h-10 w-10 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                <x-icon name="layers" class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-foreground">{{ __('Multi-Channel Delivery Channels') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Enable or disable individual notification delivery channels across the system.') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 pt-2">
            <!-- Database In-App -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['channel_database'] ? 'bg-primary/5 border-primary/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="bell" class="h-4 w-4 text-primary" />
                        <span class="text-xs font-semibold text-foreground">{{ __('In-App Database') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.channel_database" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Live bell icon, unread drawer, and inbox.') }}</p>
            </div>

            <!-- Email -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['channel_email'] ? 'bg-blue-500/5 border-blue-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="mail" class="h-4 w-4 text-blue-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Email Channel') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.channel_email" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('HTML email templates via configured SMTP.') }}</p>
            </div>

            <!-- SMS -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['channel_sms'] ? 'bg-amber-500/5 border-amber-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="smartphone" class="h-4 w-4 text-amber-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('SMS Gateway') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.channel_sms" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Direct SMS delivery to verified phone.') }}</p>
            </div>

            <!-- WhatsApp -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['channel_whatsapp'] ? 'bg-emerald-500/5 border-emerald-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="message-circle" class="h-4 w-4 text-emerald-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('WhatsApp') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.channel_whatsapp" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Instant WhatsApp alerts via Cloud API/Twilio.') }}</p>
            </div>

            <!-- Telegram -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['channel_telegram'] ? 'bg-sky-500/5 border-sky-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="send" class="h-4 w-4 text-sky-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Telegram') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.channel_telegram" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Direct alerts to Telegram Bot & Channels.') }}</p>
            </div>
        </div>
    </div>

    <!-- 3. WhatsApp Gateway Configuration -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-border pb-4">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                    <x-icon name="message-circle" class="h-5 w-5" />
                </div>
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ __('WhatsApp Gateway Configuration') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ __('Configure Meta WhatsApp Cloud API, Twilio WhatsApp, UltraMsg, or Log driver.') }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Enable Gateway') }}</span>
                <x-ui.switch wire:model.live="form.whatsapp_enabled" />
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Driver Selection -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('WhatsApp Driver') }}
                </label>
                <select wire:model.live="form.whatsapp_driver" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="meta">{{ __('Meta WhatsApp Cloud API (Official)') }}</option>
                    <option value="twilio">{{ __('Twilio WhatsApp API') }}</option>
                    <option value="ultramsg">{{ __('UltraMsg API') }}</option>
                    <option value="log">{{ __('Log Driver (Testing / Development)') }}</option>
                </select>
            </div>

            <!-- Meta Cloud API Fields -->
            @if ($form['whatsapp_driver'] === 'meta')
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('Phone Number ID') }}
                    </label>
                    <x-ui.input wire:model="form.whatsapp_phone_number_id" placeholder="e.g. 104829384920194" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('Business Account ID (WABA ID)') }}
                    </label>
                    <x-ui.input wire:model="form.whatsapp_business_account_id" placeholder="e.g. 849201948291038" />
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('Access Token / Permanent Token') }}
                    </label>
                    <x-ui.input type="password" wire:model="form.whatsapp_api_token" placeholder="EAABw..." />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('Graph API Base URL') }}
                    </label>
                    <x-ui.input wire:model="form.whatsapp_base_url" placeholder="https://graph.facebook.com/v21.0" />
                </div>
            @endif

            <!-- Twilio Fields -->
            @if ($form['whatsapp_driver'] === 'twilio')
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('Twilio Account SID') }}
                    </label>
                    <x-ui.input wire:model="form.whatsapp_twilio_sid" placeholder="AC..." />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('Twilio Auth Token') }}
                    </label>
                    <x-ui.input type="password" wire:model="form.whatsapp_twilio_token" placeholder="Auth Token" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('From WhatsApp Number') }}
                    </label>
                    <x-ui.input wire:model="form.whatsapp_twilio_from" placeholder="+14155238886" />
                </div>
            @endif

            <!-- UltraMsg Fields -->
            @if ($form['whatsapp_driver'] === 'ultramsg')
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('UltraMsg Instance ID') }}
                    </label>
                    <x-ui.input wire:model="form.whatsapp_ultramsg_instance_id" placeholder="instance9876" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                        {{ __('UltraMsg Token') }}
                    </label>
                    <x-ui.input type="password" wire:model="form.whatsapp_ultramsg_token" placeholder="Token" />
                </div>
            @endif
        </div>

        <!-- WhatsApp Test Tool -->
        <div class="p-4 rounded-xl border border-border bg-secondary/30 flex flex-col sm:flex-row items-end gap-3 mt-3">
            <div class="flex-1 w-full space-y-1">
                <label class="block text-xs font-medium text-foreground">
                    {{ __('Send Test WhatsApp Message') }}
                </label>
                <div class="flex gap-2">
                    <x-ui.input wire:model="testWhatsAppPhone" placeholder="+919876543210" class="flex-1" />
                    <x-ui.input wire:model="testWhatsAppMessage" placeholder="{{ __('Custom test message (optional)') }}" class="flex-1 hidden md:block" />
                </div>
            </div>
            <x-ui.button wire:click="testWhatsApp" variant="secondary" icon="send" class="shrink-0 w-full sm:w-auto">
                {{ __('Dispatch WhatsApp Test') }}
            </x-ui.button>
        </div>
    </div>

    <!-- 4. Telegram Gateway Configuration -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-border pb-4">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-lg bg-sky-500/10 text-sky-500 flex items-center justify-center">
                    <x-icon name="send" class="h-5 w-5" />
                </div>
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ __('Telegram Gateway Configuration') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ __('Configure Telegram Bot API credentials for instant staff & system notifications.') }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Enable Gateway') }}</span>
                <x-ui.switch wire:model.live="form.telegram_enabled" />
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Driver Selection -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Telegram Driver') }}
                </label>
                <select wire:model.live="form.telegram_driver" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="telegram">{{ __('Telegram Bot API (Official)') }}</option>
                    <option value="log">{{ __('Log Driver (Testing / Development)') }}</option>
                </select>
            </div>

            <!-- Bot Token -->
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Telegram Bot Token') }}
                </label>
                <x-ui.input type="password" wire:model="form.telegram_bot_token" placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ..." />
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('Create a bot via @BotFather on Telegram to obtain this token.') }}
                </p>
            </div>

            <!-- Default Chat ID -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Default Chat ID / Channel') }}
                </label>
                <x-ui.input wire:model="form.telegram_default_chat_id" placeholder="e.g. -100123456789 or @channel" />
            </div>

            <!-- Parse Mode -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Message Formatting') }}
                </label>
                <select wire:model="form.telegram_parse_mode" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="HTML">HTML</option>
                    <option value="Markdown">Markdown</option>
                    <option value="MarkdownV2">MarkdownV2</option>
                </select>
            </div>
        </div>

        <!-- Telegram Test Tool -->
        <div class="p-4 rounded-xl border border-border bg-secondary/30 flex flex-col sm:flex-row items-end gap-3 mt-3">
            <div class="flex-1 w-full space-y-1">
                <label class="block text-xs font-medium text-foreground">
                    {{ __('Send Test Telegram Message') }}
                </label>
                <div class="flex gap-2">
                    <x-ui.input wire:model="testTelegramChatId" placeholder="{{ __('Chat ID or Channel (e.g. -100...)') }}" class="flex-1" />
                    <x-ui.input wire:model="testTelegramMessage" placeholder="{{ __('Custom test message (optional)') }}" class="flex-1 hidden md:block" />
                </div>
            </div>
            <x-ui.button wire:click="testTelegram" variant="secondary" icon="send" class="shrink-0 w-full sm:w-auto">
                {{ __('Dispatch Telegram Test') }}
            </x-ui.button>
        </div>
    </div>

    <!-- 5. Interactive Real-Time Multi-Channel Sandbox Dispatcher -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex items-center gap-3 border-b border-border pb-4">
            <div class="h-10 w-10 rounded-lg bg-purple-500/10 text-purple-500 flex items-center justify-center">
                <x-icon name="radio" class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-foreground">{{ __('Multi-Channel Live Sandbox Tester') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Simulate and verify instant notification delivery across any channel in real-time.') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Target Channel') }}
                </label>
                <select wire:model="sandboxChannel" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="database">{{ __('In-App Bell & WebSockets (Database)') }}</option>
                    <option value="email">{{ __('Email') }}</option>
                    <option value="sms">{{ __('SMS') }}</option>
                    <option value="whatsapp">{{ __('WhatsApp') }}</option>
                    <option value="telegram">{{ __('Telegram') }}</option>
                    <option value="all">{{ __('All Active Channels Simultaneously') }}</option>
                </select>
            </div>

            <div class="sm:col-span-3">
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Notification Title') }}
                </label>
                <x-ui.input wire:model="sandboxTitle" />
            </div>

            <div class="sm:col-span-4">
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Notification Body / Content') }}
                </label>
                <x-ui.textarea wire:model="sandboxMessage" rows="2" />
            </div>
        </div>

        @if ($sandboxResult)
            <div class="p-3.5 rounded-lg border {{ $sandboxSuccess ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-300' : 'bg-rose-500/10 border-rose-500/30 text-rose-700 dark:text-rose-300' }} text-xs font-medium flex items-center gap-2">
                <x-icon :name="$sandboxSuccess ? 'check-circle' : 'alert-triangle'" class="h-4 w-4 shrink-0" />
                <span>{{ $sandboxResult }}</span>
            </div>
        @endif

        <div class="flex justify-end pt-2">
            <x-ui.button wire:click="runSandboxDispatch" variant="default" icon="zap">
                {{ __('Dispatch Live Test Notification') }}
            </x-ui.button>
        </div>

    </div>
</div>
