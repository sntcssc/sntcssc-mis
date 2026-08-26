<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Live Chat & WebRTC Call Settings')] class extends Component {
    public array $form = [
        'enabled' => true,
        'direct_enabled' => true,
        'group_enabled' => true,
        'channel_enabled' => true,
        'voice_call_enabled' => true,
        'video_call_enabled' => true,
        'meetings_enabled' => true,
        'meeting_max_duration_minutes' => 120,
        'transport_driver' => 'hybrid', // hybrid, polling, broadcasting
        'poll_interval' => '3s',       // 3s, 5s, 10s, 30s
        'webrtc_signaling_driver' => 'reverb', // reverb, internal_poll
        'webrtc_stun_server' => 'stun:stun.l.google.com:19302',
        'webrtc_turn_server' => '',
        'webrtc_turn_username' => '',
        'webrtc_turn_credential' => '',
        'max_file_size_mb' => 25,
        'allowed_file_types' => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,mp3,mp4,wav',
        'edit_time_limit_minutes' => 15,
        'notify_email' => true,
        'notify_sms' => false,
        'notify_whatsapp' => false,
        'notify_telegram' => false,
        'sound_enabled' => true,
    ];

    public function mount(): void
    {
        $settings = Setting::query()
            ->where('group', 'chat')
            ->get()
            ->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "chat.{$key}";

            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_SECRET) {
                    $this->form[$key] = ''; // Do not expose secret in clear text
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
            'form.direct_enabled' => ['boolean'],
            'form.group_enabled' => ['boolean'],
            'form.channel_enabled' => ['boolean'],
            'form.voice_call_enabled' => ['boolean'],
            'form.video_call_enabled' => ['boolean'],
            'form.meetings_enabled' => ['boolean'],
            'form.meeting_max_duration_minutes' => ['required', 'numeric', 'min:15', 'max:480'],
            'form.transport_driver' => ['required', 'string', 'in:hybrid,polling,broadcasting'],
            'form.poll_interval' => ['required', 'string', 'in:3s,5s,10s,30s'],
            'form.webrtc_signaling_driver' => ['required', 'string', 'in:reverb,internal_poll'],
            'form.webrtc_stun_server' => ['nullable', 'string', 'max:255'],
            'form.webrtc_turn_server' => ['nullable', 'string', 'max:255'],
            'form.webrtc_turn_username' => ['nullable', 'string', 'max:100'],
            'form.webrtc_turn_credential' => ['nullable', 'string', 'max:255'],
            'form.max_file_size_mb' => ['required', 'numeric', 'min:1', 'max:100'],
            'form.allowed_file_types' => ['required', 'string', 'max:255'],
            'form.edit_time_limit_minutes' => ['required', 'numeric', 'min:0', 'max:1440'],
            'form.notify_email' => ['boolean'],
            'form.notify_sms' => ['boolean'],
            'form.notify_whatsapp' => ['boolean'],
            'form.notify_telegram' => ['boolean'],
            'form.sound_enabled' => ['boolean'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "chat.{$key}";

                    $type = match ($key) {
                        'enabled', 'direct_enabled', 'group_enabled', 'channel_enabled',
                        'voice_call_enabled', 'video_call_enabled', 'notify_email',
                        'notify_sms', 'notify_whatsapp', 'notify_telegram', 'sound_enabled' => Setting::TYPE_BOOLEAN,
                        'webrtc_turn_credential' => Setting::TYPE_SECRET,
                        'max_file_size_mb', 'edit_time_limit_minutes' => Setting::TYPE_NUMBER,
                        'transport_driver', 'poll_interval', 'webrtc_signaling_driver' => Setting::TYPE_SELECT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'chat';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace(['_', '.'], ' ', $fullKey));

                    // Only update secret fields if non-empty
                    if ($type === Setting::TYPE_SECRET && trim((string) $value) === '') {
                        // Keep existing
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
                    event: 'chat_settings_updated',
                    description: 'Updated system live chat, WebRTC voice/video calling, and notification dispatch settings',
                    newValues: $this->form,
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('Live Chat and WebRTC Calling settings saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save chat settings: ' . $e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save settings: :error', ['error' => $e->getMessage()]));
        }
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto pb-12">
    <!-- Breadcrumb & Settings Nav -->
    <x-settings-nav active="chat" />

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border pb-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <div class="p-2 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="message-square" class="h-6 w-6" />
                </div>
                <span>{{ __('Live Chat & WebRTC Calling Settings') }}</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Control real-time chat modes (Direct, Group, Channels), native in-browser WebRTC voice and video calls, transport drivers, and multi-channel notifications.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button wire:click="save" variant="default" icon="check">
                {{ __('Save Chat Settings') }}
            </x-ui.button>
        </div>
    </div>

    <!-- 1. Master Feature Toggles -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-4">
        <div class="flex items-center justify-between border-b border-border pb-3">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                    <x-icon name="layers" class="h-5 w-5" />
                </div>
                <div>
                    <h2 class="text-base font-semibold text-foreground">{{ __('Communication Modules Master Controls') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ __('Turn entire features on or off system-wide.') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-foreground">{{ __('Master Live Chat') }}</span>
                <x-ui.switch wire:model.live="form.enabled" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 pt-2">
            <!-- 1-on-1 Direct Chat -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['direct_enabled'] ? 'bg-primary/5 border-primary/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="user" class="h-4 w-4 text-primary" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Direct 1-on-1') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.direct_enabled" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Private direct messages between users.') }}</p>
            </div>

            <!-- Group Chat -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['group_enabled'] ? 'bg-blue-500/5 border-blue-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="users" class="h-4 w-4 text-blue-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Group Chat') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.group_enabled" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Multi-user collaborative chat rooms.') }}</p>
            </div>

            <!-- WhatsApp / Telegram Channels -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['channel_enabled'] ? 'bg-emerald-500/5 border-emerald-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="radio" class="h-4 w-4 text-emerald-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Broadcast Channels') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.channel_enabled" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('One-way announcement channels.') }}</p>
            </div>

            <!-- WebRTC Voice Calls -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['voice_call_enabled'] ? 'bg-amber-500/5 border-amber-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="phone-call" class="h-4 w-4 text-amber-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Voice Calls') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.voice_call_enabled" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Native WebRTC in-browser audio calls.') }}</p>
            </div>

            <!-- WebRTC Video Calls -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['video_call_enabled'] ? 'bg-purple-500/5 border-purple-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="video" class="h-4 w-4 text-purple-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Video Calls') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.video_call_enabled" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('HD video calls with screen share.') }}</p>
            </div>

            <!-- Online Meetings & Video Conferencing -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['meetings_enabled'] ? 'bg-sky-500/5 border-sky-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="monitor-up" class="h-4 w-4 text-sky-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Online Meetings') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.meetings_enabled" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Dedicated instant & scheduled meetings.') }}</p>
            </div>
        </div>
    </div>

    <!-- 2. Realtime Transport & Signaling Engine -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex items-center gap-3 border-b border-border pb-3">
            <div class="h-10 w-10 rounded-lg bg-blue-500/10 text-blue-500 flex items-center justify-center">
                <x-icon name="activity" class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-foreground">{{ __('Realtime Transport Engine & Signaling Driver') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Configure how chat messages, delivery ticks, typing events, and WebRTC peer signals are delivered.') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Chat Transport Mode -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Chat Realtime Driver') }}
                </label>
                <select wire:model.live="form.transport_driver" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="hybrid">{{ __('Hybrid (Reverb WebSockets + Fallback Polling)') }}</option>
                    <option value="polling">{{ __('Livewire Polling Only (wire:poll.3s)') }}</option>
                    <option value="broadcasting">{{ __('Broadcasting / Reverb WebSockets Only') }}</option>
                </select>
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('Choose whether Livewire polling, Reverb WebSockets, or both are used for live messages.') }}
                </p>
            </div>

            <!-- Polling Heartbeat Rate -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Polling Heartbeat Rate') }}
                </label>
                <select wire:model.live="form.poll_interval" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="3s">3 {{ __('Seconds (Recommended / Fast Realtime)') }}</option>
                    <option value="5s">5 {{ __('Seconds') }}</option>
                    <option value="10s">10 {{ __('Seconds') }}</option>
                    <option value="30s">30 {{ __('Seconds (Low Server Load)') }}</option>
                </select>
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('Polling interval applied to wire:poll in chat components.') }}
                </p>
            </div>

            <!-- WebRTC Signaling Driver -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('WebRTC Signaling Driver') }}
                </label>
                <select wire:model.live="form.webrtc_signaling_driver" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="reverb">{{ __('Laravel Reverb / WebSockets (Instant Peer Handshake)') }}</option>
                    <option value="internal_poll">{{ __('Internal Database / Polling Exchange (No 3rd Party)') }}</option>
                </select>
                <p class="text-[11px] text-muted-foreground mt-1">
                    {{ __('WebRTC peer connection SDP and ICE candidate signaling method.') }}
                </p>
            </div>

            <!-- Audio Alerts -->
            <div class="flex flex-col justify-between p-3 rounded-lg border border-border bg-secondary/20">
                <div class="flex items-center justify-between">
                    <div class="space-y-0.5">
                        <span class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                            <x-icon name="volume-2" class="h-3.5 w-3.5 text-primary" />
                            {{ __('Audio Chime Alerts') }}
                        </span>
                        <p class="text-[11px] text-muted-foreground">{{ __('Play crystal chime on incoming message') }}</p>
                    </div>
                    <x-ui.switch wire:model.live="form.sound_enabled" />
                </div>
            </div>
        </div>
    </div>

    <!-- 3. WebRTC Peer Connection & ICE Server Configuration -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex items-center gap-3 border-b border-border pb-3">
            <div class="h-10 w-10 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                <x-icon name="phone" class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-foreground">{{ __('WebRTC ICE & STUN / TURN Servers (Built-In)') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Native WebRTC connects peers directly with zero external communication API subscriptions.') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('STUN Server URL') }}
                </label>
                <x-ui.input wire:model="form.webrtc_stun_server" placeholder="stun:stun.l.google.com:19302" />
                <p class="text-[11px] text-muted-foreground mt-1">{{ __('Public free STUN for NAT discovery.') }}</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('TURN Server URL (Optional)') }}
                </label>
                <x-ui.input wire:model="form.webrtc_turn_server" placeholder="turn:turn.yourdomain.com:3478" />
                <p class="text-[11px] text-muted-foreground mt-1">{{ __('Relay server for restrictive firewalls.') }}</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('TURN Username') }}
                </label>
                <x-ui.input wire:model="form.webrtc_turn_username" placeholder="turnuser" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('TURN Credential / Password') }}
                </label>
                <x-ui.input type="password" wire:model="form.webrtc_turn_credential" placeholder="Password" />
            </div>
        </div>
    </div>

    <!-- 4. Attachments & Security Rules -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex items-center gap-3 border-b border-border pb-3">
            <div class="h-10 w-10 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center">
                <x-icon name="shield" class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-foreground">{{ __('Media Attachments & Message Policies') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Configure attachment limits, supported extensions, and message edit restrictions.') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Max Attachment Size (MB)') }}
                </label>
                <x-ui.input type="number" wire:model="form.max_file_size_mb" min="1" max="100" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Message Edit Time Limit (Minutes)') }}
                </label>
                <x-ui.input type="number" wire:model="form.edit_time_limit_minutes" min="0" max="1440" />
                <p class="text-[11px] text-muted-foreground mt-1">{{ __('Set 0 for unlimited edit window.') }}</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1.5">
                    {{ __('Allowed Attachment Formats') }}
                </label>
                <x-ui.input wire:model="form.allowed_file_types" />
                <p class="text-[11px] text-muted-foreground mt-1">{{ __('Comma-separated extension list.') }}</p>
            </div>
        </div>
    </div>

    <!-- 5. Multi-Channel Chat Notifications Matrix -->
    <div class="rounded-xl border border-border bg-card p-5 sm:p-6 shadow-xs space-y-4">
        <div class="flex items-center gap-3 border-b border-border pb-3">
            <div class="h-10 w-10 rounded-lg bg-sky-500/10 text-sky-500 flex items-center justify-center">
                <x-icon name="bell" class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-foreground">{{ __('Multi-Channel Chat Notification Triggers') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Internal in-app bell notification is always active. Toggle external notifications on message receipt.') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-2">
            <!-- Email -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['notify_email'] ? 'bg-blue-500/5 border-blue-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="mail" class="h-4 w-4 text-blue-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Email Alerts') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.notify_email" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Send HTML email preview with direct reply link.') }}</p>
            </div>

            <!-- WhatsApp -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['notify_whatsapp'] ? 'bg-emerald-500/5 border-emerald-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="message-circle" class="h-4 w-4 text-emerald-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('WhatsApp Alerts') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.notify_whatsapp" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Send instant WhatsApp message alert to recipient.') }}</p>
            </div>

            <!-- SMS -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['notify_sms'] ? 'bg-amber-500/5 border-amber-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="smartphone" class="h-4 w-4 text-amber-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('SMS Gateway') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.notify_sms" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Send SMS text notification on new message.') }}</p>
            </div>

            <!-- Telegram -->
            <div class="p-3.5 rounded-xl border border-border {{ $form['notify_telegram'] ? 'bg-sky-500/5 border-sky-500/30' : 'bg-background' }} flex flex-col justify-between gap-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="send" class="h-4 w-4 text-sky-500" />
                        <span class="text-xs font-semibold text-foreground">{{ __('Telegram Alerts') }}</span>
                    </div>
                    <x-ui.switch wire:model.live="form.notify_telegram" />
                </div>
                <p class="text-[11px] text-muted-foreground">{{ __('Dispatch alert to staff Telegram bot/channel.') }}</p>
            </div>
        </div>
    </div>
</div>
