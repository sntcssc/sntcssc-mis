<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Online Meetings & Video Conferencing Settings')] class extends Component {
    public array $form = [
        'enabled' => true,
        'max_duration_minutes' => 120,
        'instant_meetings_enabled' => true,
        'scheduled_meetings_enabled' => true,
        'default_access_mode' => 'open', // open, invited_only
        'waiting_room_enabled' => true,
        'screen_sharing_enabled' => true,
        'in_room_chat_enabled' => true,
        'emoji_reactions_enabled' => true,
        'webrtc_stun_server' => 'stun:stun.l.google.com:19302',
        'webrtc_turn_server' => '',
        'webrtc_turn_username' => '',
        'webrtc_turn_credential' => '',
    ];

    public function mount(): void
    {
        $settings = Setting::query()
            ->whereIn('group', ['meeting', 'chat'])
            ->get()
            ->keyBy('key');

        foreach ($this->form as $key => $default) {
            $meetingKey = "meeting.{$key}";
            // Legacy/fallback chat key mapping for backward compatibility
            $chatKey = match ($key) {
                'enabled' => 'chat.meetings_enabled',
                'max_duration_minutes' => 'chat.meeting_max_duration_minutes',
                'webrtc_stun_server' => 'chat.webrtc_stun_server',
                'webrtc_turn_server' => 'chat.webrtc_turn_server',
                'webrtc_turn_username' => 'chat.webrtc_turn_username',
                'webrtc_turn_credential' => 'chat.webrtc_turn_credential',
                default => null,
            };

            $setting = $settings[$meetingKey] ?? ($chatKey ? ($settings[$chatKey] ?? null) : null);

            if ($setting) {
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_SECRET) {
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
            'form.max_duration_minutes' => ['required', 'numeric', 'min:15', 'max:480'],
            'form.instant_meetings_enabled' => ['boolean'],
            'form.scheduled_meetings_enabled' => ['boolean'],
            'form.default_access_mode' => ['required', 'string', 'in:open,invited_only'],
            'form.waiting_room_enabled' => ['boolean'],
            'form.screen_sharing_enabled' => ['boolean'],
            'form.in_room_chat_enabled' => ['boolean'],
            'form.emoji_reactions_enabled' => ['boolean'],
            'form.webrtc_stun_server' => ['nullable', 'string', 'max:255'],
            'form.webrtc_turn_server' => ['nullable', 'string', 'max:255'],
            'form.webrtc_turn_username' => ['nullable', 'string', 'max:100'],
            'form.webrtc_turn_credential' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "meeting.{$key}";

                    $type = match ($key) {
                        'enabled', 'instant_meetings_enabled', 'scheduled_meetings_enabled',
                        'waiting_room_enabled', 'screen_sharing_enabled', 'in_room_chat_enabled',
                        'emoji_reactions_enabled' => Setting::TYPE_BOOLEAN,
                        'webrtc_turn_credential' => Setting::TYPE_SECRET,
                        'max_duration_minutes' => Setting::TYPE_NUMBER,
                        'default_access_mode' => Setting::TYPE_SELECT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'meeting';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace(['_', '.'], ' ', $fullKey));

                    if ($type === Setting::TYPE_SECRET && empty($value)) {
                        continue;
                    }

                    $oldValue = $setting->exists ? $setting->rawValue() : null;

                    if ($type === Setting::TYPE_BOOLEAN) {
                        $setting->value = $value ? '1' : '0';
                    } else {
                        $setting->value = (string) $value;
                    }

                    $setting->status = true;
                    $setting->save();

                    // Sync legacy chat settings for backward compatibility with older components
                    if ($key === 'enabled') {
                        Setting::set('chat.meetings_enabled', $value ? '1' : '0', $userId);
                    } elseif ($key === 'max_duration_minutes') {
                        Setting::set('chat.meeting_max_duration_minutes', (string) $value, $userId);
                    }

                    AuditLogService::log(
                        event: 'setting_updated',
                        description: "Updated online meeting setting '{$fullKey}'",
                        auditable: $setting,
                        oldValues: ['value' => $oldValue],
                        newValues: ['value' => $type === Setting::TYPE_SECRET ? '[HIDDEN]' : $setting->value],
                        userId: $userId
                    );
                }

                Setting::flushCache();
            });

            Toast::dispatch($this, 'success', __('Online meeting configuration updated successfully!'));
        } catch (\Throwable $e) {
            Log::error('Failed to save meeting settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to update meeting settings: :error', ['error' => $e->getMessage()]));
        }
    }
}; ?>

<div class="max-w-6xl mx-auto space-y-6 pb-12">
    <!-- Breadcrumb & Settings Nav -->
    <x-settings-nav active="meetings" />
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border/60 pb-5">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="video" class="w-6 h-6" />
                </span>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">
                    {{ __('Online Meetings & Video Conferencing Settings') }}
                </h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Configure online meeting platform parameters, security, access modes, STUN/TURN servers, and duration limits independently from chat.') }}
            </p>
        </div>

        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.settings.chat') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-card hover:bg-accent text-foreground transition-colors shadow-xs"
            >
                <x-icon name="message-square" class="w-4 h-4 text-muted-foreground" />
                {{ __('Chat & Calls Settings') }}
            </a>
            <a
                href="{{ route('meetings.index') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
            >
                <x-icon name="external-link" class="w-4 h-4" />
                {{ __('Go to Meetings Hub') }}
            </a>
        </div>
    </div>

    <form wire:submit.prevent="save" class="space-y-6">
        <!-- 1. Platform Availability & Durations -->
        <div class="rounded-xl border border-border bg-card text-card-foreground shadow-xs p-6">
            <div class="flex items-center justify-between border-b border-border/60 pb-4 mb-6">
                <div>
                    <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="power" class="w-4 h-4 text-indigo-500" />
                        {{ __('Platform Availability & Scheduling') }}
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Enable or disable online meetings globally and specify max meeting duration.') }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Global Meetings Enabled -->
                <div class="flex items-start justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border/50">
                    <div>
                        <label class="text-sm font-medium text-foreground cursor-pointer" for="meeting-enabled">
                            {{ __('Enable Online Meetings Platform') }}
                        </label>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Turn on or off video conferencing and online meetings across the institution.') }}
                        </p>
                    </div>
                    <input
                        id="meeting-enabled"
                        type="checkbox"
                        wire:model="form.enabled"
                        class="rounded border-input text-primary shadow-xs focus:ring-primary h-5 w-5 cursor-pointer mt-0.5"
                    />
                </div>

                <!-- Max Duration -->
                <div class="flex flex-col justify-between p-4 rounded-lg bg-muted/40 border border-border/50">
                    <label class="text-sm font-medium text-foreground" for="meeting-duration">
                        {{ __('Maximum Meeting Duration (Minutes)') }}
                    </label>
                    <p class="text-xs text-muted-foreground mt-1 mb-2">
                        {{ __('Limits the runtime of scheduled and instant meetings (15 to 480 minutes).') }}
                    </p>
                    <input
                        id="meeting-duration"
                        type="number"
                        min="15"
                        max="480"
                        wire:model="form.max_duration_minutes"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                    @error('form.max_duration_minutes')
                        <span class="text-xs text-destructive mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Instant Meetings -->
                <div class="flex items-start justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border/50">
                    <div>
                        <label class="text-sm font-medium text-foreground cursor-pointer" for="meeting-instant">
                            {{ __('Allow Instant Meetings') }}
                        </label>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Allow teachers and faculty to launch instant doubt-solving sessions.') }}
                        </p>
                    </div>
                    <input
                        id="meeting-instant"
                        type="checkbox"
                        wire:model="form.instant_meetings_enabled"
                        class="rounded border-input text-primary shadow-xs focus:ring-primary h-5 w-5 cursor-pointer mt-0.5"
                    />
                </div>

                <!-- Scheduled Meetings -->
                <div class="flex items-start justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border/50">
                    <div>
                        <label class="text-sm font-medium text-foreground cursor-pointer" for="meeting-scheduled">
                            {{ __('Allow Scheduled Meetings & Recurring Series') }}
                        </label>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Allow planning webinars, lectures, and series with invitations & reminders.') }}
                        </p>
                    </div>
                    <input
                        id="meeting-scheduled"
                        type="checkbox"
                        wire:model="form.scheduled_meetings_enabled"
                        class="rounded border-input text-primary shadow-xs focus:ring-primary h-5 w-5 cursor-pointer mt-0.5"
                    />
                </div>
            </div>
        </div>

        <!-- 2. Security, Access & Waiting Room -->
        <div class="rounded-xl border border-border bg-card text-card-foreground shadow-xs p-6">
            <div class="flex items-center justify-between border-b border-border/60 pb-4 mb-6">
                <div>
                    <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="shield-check" class="w-4 h-4 text-emerald-500" />
                        {{ __('Access Modes & Security Control') }}
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Default room permissions, participant admission rules, and waiting room security.') }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Default Access Mode -->
                <div class="p-4 rounded-lg bg-muted/40 border border-border/50">
                    <label class="text-sm font-medium text-foreground" for="meeting-access-mode">
                        {{ __('Default Meeting Access Mode') }}
                    </label>
                    <p class="text-xs text-muted-foreground mt-1 mb-2">
                        {{ __('Open mode allows anyone with invite link; Invited Only requires specific invitation.') }}
                    </p>
                    <select
                        id="meeting-access-mode"
                        wire:model="form.default_access_mode"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs focus:border-primary focus:ring-1 focus:ring-primary"
                    >
                        <option value="open">{{ __('Open (Anyone with link / code can request join)') }}</option>
                        <option value="invited_only">{{ __('Invited Only (Strict enrollment / invite required)') }}</option>
                    </select>
                </div>

                <!-- Waiting Room Enabled -->
                <div class="flex items-start justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border/50">
                    <div>
                        <label class="text-sm font-medium text-foreground cursor-pointer" for="meeting-waiting-room">
                            {{ __('Enable Waiting Room Admission') }}
                        </label>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Uninvited attendees must wait in lobby until host or co-host admits them.') }}
                        </p>
                    </div>
                    <input
                        id="meeting-waiting-room"
                        type="checkbox"
                        wire:model="form.waiting_room_enabled"
                        class="rounded border-input text-primary shadow-xs focus:ring-primary h-5 w-5 cursor-pointer mt-0.5"
                    />
                </div>

                <!-- Screen Sharing -->
                <div class="flex items-start justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border/50">
                    <div>
                        <label class="text-sm font-medium text-foreground cursor-pointer" for="meeting-screenshare">
                            {{ __('Allow Screen Sharing') }}
                        </label>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Allow presenters and hosts to stream tabs, application windows, or displays.') }}
                        </p>
                    </div>
                    <input
                        id="meeting-screenshare"
                        type="checkbox"
                        wire:model="form.screen_sharing_enabled"
                        class="rounded border-input text-primary shadow-xs focus:ring-primary h-5 w-5 cursor-pointer mt-0.5"
                    />
                </div>

                <!-- In-Room Chat -->
                <div class="flex items-start justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border/50">
                    <div>
                        <label class="text-sm font-medium text-foreground cursor-pointer" for="meeting-chat">
                            {{ __('Enable In-Room Chat Drawer') }}
                        </label>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Permit real-time text chat, Q&A, and links exchange inside the meeting.') }}
                        </p>
                    </div>
                    <input
                        id="meeting-chat"
                        type="checkbox"
                        wire:model="form.in_room_chat_enabled"
                        class="rounded border-input text-primary shadow-xs focus:ring-primary h-5 w-5 cursor-pointer mt-0.5"
                    />
                </div>

                <!-- Emoji Reactions -->
                <div class="flex items-start justify-between gap-4 p-4 rounded-lg bg-muted/40 border border-border/50 md:col-span-2">
                    <div>
                        <label class="text-sm font-medium text-foreground cursor-pointer" for="meeting-emoji">
                            {{ __('Allow Floating Emoji Reactions & Hand Raises') }}
                        </label>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Enable interactive non-verbal reactions (👏, ❤️, 💡, 🚀) and hand raising.') }}
                        </p>
                    </div>
                    <input
                        id="meeting-emoji"
                        type="checkbox"
                        wire:model="form.emoji_reactions_enabled"
                        class="rounded border-input text-primary shadow-xs focus:ring-primary h-5 w-5 cursor-pointer mt-0.5"
                    />
                </div>
            </div>
        </div>

        <!-- 3. WebRTC ICE Infrastructure (STUN / TURN) -->
        <div class="rounded-xl border border-border bg-card text-card-foreground shadow-xs p-6">
            <div class="flex items-center justify-between border-b border-border/60 pb-4 mb-6">
                <div>
                    <h2 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="network" class="w-4 h-4 text-blue-500" />
                        {{ __('WebRTC ICE & Relay Infrastructure') }}
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Configure dedicated STUN/TURN servers to guarantee peer-to-peer connectivity across strict firewalls.') }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2">
                    <label class="text-sm font-medium text-foreground" for="meeting-stun">
                        {{ __('STUN Server URL') }}
                    </label>
                    <p class="text-xs text-muted-foreground mt-0.5 mb-2">
                        {{ __('Public STUN server used for NAT traversal. Default: stun:stun.l.google.com:19302') }}
                    </p>
                    <input
                        id="meeting-stun"
                        type="text"
                        wire:model="form.webrtc_stun_server"
                        placeholder="stun:stun.l.google.com:19302"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                </div>

                <div class="md:col-span-2">
                    <label class="text-sm font-medium text-foreground" for="meeting-turn">
                        {{ __('TURN Relay Server URL (Optional)') }}
                    </label>
                    <p class="text-xs text-muted-foreground mt-0.5 mb-2">
                        {{ __('Coturn or Twilio/Xirsys TURN server URL for symmetric NAT / corporate networks.') }}
                    </p>
                    <input
                        id="meeting-turn"
                        type="text"
                        wire:model="form.webrtc_turn_server"
                        placeholder="turn:turn.example.com:3478?transport=udp"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                </div>

                <div>
                    <label class="text-sm font-medium text-foreground" for="meeting-turn-user">
                        {{ __('TURN Username') }}
                    </label>
                    <input
                        id="meeting-turn-user"
                        type="text"
                        wire:model="form.webrtc_turn_username"
                        placeholder="username"
                        class="w-full mt-1.5 rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                </div>

                <div>
                    <label class="text-sm font-medium text-foreground" for="meeting-turn-cred">
                        {{ __('TURN Credential / Password') }}
                    </label>
                    <input
                        id="meeting-turn-cred"
                        type="password"
                        wire:model="form.webrtc_turn_credential"
                        placeholder="••••••••"
                        class="w-full mt-1.5 rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border/60">
            <button
                type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-primary text-primary-foreground px-5 py-2.5 text-sm font-semibold hover:bg-primary/90 transition-all shadow-xs cursor-pointer"
            >
                <x-icon name="check" class="w-4 h-4" />
                {{ __('Save Meeting Settings') }}
            </button>
        </div>
    </form>
</div>
