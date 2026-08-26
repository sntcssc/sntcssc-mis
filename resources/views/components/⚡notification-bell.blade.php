<?php

use App\Models\AppNotification;
use App\Models\Setting;
use App\Services\NotificationService;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $activeTab = 'all'; // all, unread, ticket, system, chat, call
    public bool $isOpen = false;
    public int $previousUnreadCount = 0;
    public bool $soundMuted = false;

    public function mount(): void
    {
        $this->previousUnreadCount = $this->unreadCount;
    }

    #[Computed]
    public function unreadCount(): int
    {
        $user = auth()->user();

        return $user ? AppNotification::forUser($user->id)->unread()->count() : 0;
    }

    #[Computed]
    public function notifications()
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $query = AppNotification::forUser($user->id)->orderBy('created_at', 'desc')->limit(15);

        if ($this->activeTab === 'unread') {
            $query->unread();
        } elseif (in_array($this->activeTab, [
            AppNotification::CATEGORY_TICKET,
            AppNotification::CATEGORY_SYSTEM,
            AppNotification::CATEGORY_CHAT,
            AppNotification::CATEGORY_CALL,
        ], true)) {
            $query->category($this->activeTab);
        }

        return $query->get();
    }

    #[Computed]
    public function pollInterval(): string
    {
        $driver = (string) Setting::get('notification.realtime_driver', 'hybrid');

        if ($driver === 'broadcasting') {
            return '30s'; // Relaxed heartbeat for pure broadcasting
        }

        return (string) Setting::get('notification.poll_interval', '3s');
    }

    #[Computed]
    public function soundEnabled(): bool
    {
        return (bool) Setting::get('notification.sound_enabled', true) && ! $this->soundMuted;
    }

    public function markAsRead(int $id): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        /** @var NotificationService $service */
        $service = app(NotificationService::class);
        $service->markAsRead($id, $user->id);
    }

    public function markAllAsRead(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        /** @var NotificationService $service */
        $service = app(NotificationService::class);
        $service->markAllAsRead($user->id);
        Toast::dispatch($this, 'success', __('All notifications marked as read.'));
    }


    public function deleteNotification(int $id): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        /** @var NotificationService $service */
        $service = app(NotificationService::class);
        $service->deleteNotification($id, $user->id);
    }

    public function toggleMute(): void
    {
        $this->soundMuted = ! $this->soundMuted;
    }

    public function openAndMark(int $id, ?string $url = null): void
    {
        $this->markAsRead($id);

        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }
};
?>

<div
    x-data="{
        open: @entangle('isOpen'),
        unread: @entangle('unreadCount'),
        soundEnabled: @entangle('soundEnabled'),
        prevUnread: 0,
        playChime() {
            if (!this.soundEnabled) return;
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return;
                const ctx = new AudioContext();
                
                // First bell harmonic (pleasant high tone)
                const osc1 = ctx.createOscillator();
                const gain1 = ctx.createGain();
                osc1.type = 'sine';
                osc1.frequency.setValueAtTime(880, ctx.currentTime); // A5
                osc1.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.35);
                gain1.gain.setValueAtTime(0.2, ctx.currentTime);
                gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                osc1.connect(gain1);
                gain1.connect(ctx.destination);
                osc1.start();
                osc1.stop(ctx.currentTime + 0.35);

                // Second warm overtone chime
                const osc2 = ctx.createOscillator();
                const gain2 = ctx.createGain();
                osc2.type = 'triangle';
                osc2.frequency.setValueAtTime(1320, ctx.currentTime + 0.08); // E6
                osc2.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.45);
                gain2.gain.setValueAtTime(0.15, ctx.currentTime + 0.08);
                gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
                osc2.connect(gain2);
                gain2.connect(ctx.destination);
                osc2.start(ctx.currentTime + 0.08);
                osc2.stop(ctx.currentTime + 0.45);
            } catch (e) {
                console.debug('Audio chime suppressed by browser policy', e);
            }
        },
        init() {
            this.prevUnread = this.unread;
            this.$watch('unread', (val) => {
                if (val > this.prevUnread && val > 0) {
                    this.playChime();
                    if (window.Notification && Notification.permission === 'granted' && document.hidden) {
                        new Notification('{{ \App\Models\Setting::appName() }}', {
                            body: 'You have ' + val + ' unread notification(s)',
                            icon: '{{ \App\Models\Setting::faviconUrl() ?? '/favicon.ico' }}'
                        });
                    }
                }
                this.prevUnread = val;
            });
        }
    }"
    @keydown.escape.window="open = false"
    @click.outside="open = false"
    wire:poll.{{ $this->pollInterval }}
    class="relative inline-block text-left"
>
    <!-- Bell Trigger Button -->
    <button
        type="button"
        @click="open = !open"
        class="relative flex h-9 w-9 items-center justify-center rounded-lg hover:bg-secondary transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary/40"
        aria-label="{{ __('Notifications') }}"
    >
        <x-icon name="bell" class="h-4 w-4 text-foreground {{ $this->unreadCount > 0 ? 'animate-wiggle' : '' }}" />

        @if ($this->unreadCount > 0)
            <span class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 px-1 items-center justify-center rounded-full bg-rose-500 text-[10px] font-bold text-white shadow-xs animate-pulse">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    <!-- Slide-over / Dropdown Drawer -->
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
        x-cloak
        class="absolute right-0 mt-2 w-84 sm:w-96 rounded-2xl border border-border bg-popover text-popover-foreground shadow-2xl z-50 overflow-hidden flex flex-col max-h-[85vh]"
    >
        <!-- Top Drawer Header -->
        <div class="p-3.5 border-b border-border bg-card/60 backdrop-blur-xs flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="p-1.5 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="bell" class="h-4 w-4" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold leading-none">{{ __('Notifications') }}</h3>
                    <p class="text-[11px] text-muted-foreground mt-0.5">
                        @if ($this->unreadCount > 0)
                            <span class="font-medium text-rose-500">{{ __(':count unread', ['count' => $this->unreadCount]) }}</span>
                        @else
                            {{ __('All caught up!') }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-1">
                <!-- Sound Toggle -->
                <button
                    type="button"
                    wire:click="toggleMute"
                    class="p-1.5 rounded-lg hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                    title="{{ $soundMuted ? __('Unmute Sound') : __('Mute Sound') }}"
                >
                    <x-icon :name="$soundMuted ? 'volume-x' : 'volume-2'" class="h-4 w-4 {{ $soundMuted ? 'text-rose-400' : 'text-muted-foreground' }}" />
                </button>

                <!-- Mark all as read -->
                @if ($this->unreadCount > 0)
                    <button
                        type="button"
                        wire:click="markAllAsRead"
                        class="p-1.5 rounded-lg hover:bg-secondary text-muted-foreground hover:text-primary transition-colors cursor-pointer text-xs flex items-center gap-1 font-medium"
                        title="{{ __('Mark all as read') }}"
                    >
                        <x-icon name="check-check" class="h-4 w-4" />
                    </button>
                @endif
            </div>
        </div>

        <!-- Filter Category Tabs -->
        <div class="flex items-center gap-1 px-3 py-2 border-b border-border bg-secondary/30 overflow-x-auto scrollbar-none text-xs">
            @php
                $tabs = [
                    'all' => __('All'),
                    'unread' => __('Unread'),
                    'ticket' => __('Tickets'),
                    'chat' => __('Chat'),
                    'call' => __('Calls'),
                    'system' => __('System'),
                ];
            @endphp
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="$set('activeTab', '{{ $key }}')"
                    class="px-2.5 py-1 rounded-full whitespace-nowrap font-medium transition-colors cursor-pointer {{ $activeTab === $key ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground hover:bg-secondary' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <!-- Notifications List -->
        <div class="overflow-y-auto divide-y divide-border/60 max-h-[380px] scrollbar-thin">
            @forelse ($this->notifications as $item)
                @php
                    $isUnread = ! $item->isRead();
                @endphp
                <div
                    wire:key="notif-{{ $item->id }}"
                    class="group relative p-3.5 transition-colors hover:bg-secondary/40 flex items-start gap-3 {{ $isUnread ? 'bg-primary/5' : '' }}"
                >
                    <!-- Category Icon Badge -->
                    <div class="h-9 w-9 shrink-0 rounded-xl border flex items-center justify-center {{ $item->colorClass() }}">
                        <x-icon :name="$item->iconName()" class="h-4 w-4" />
                    </div>

                    <!-- Content Body -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-1">
                            <h4 class="text-xs font-semibold text-foreground truncate pr-2 {{ $isUnread ? 'font-bold text-foreground' : 'text-foreground/85' }}">
                                {{ $item->title }}
                            </h4>
                            <span class="text-[10px] text-muted-foreground whitespace-nowrap shrink-0">
                                {{ $item->created_at?->diffForHumans(null, true) }}
                            </span>
                        </div>

                        <p class="text-xs text-muted-foreground line-clamp-2 mt-0.5 leading-relaxed">
                            {{ $item->message }}
                        </p>

                        <!-- Action Link or Tag -->
                        <div class="mt-2 flex items-center justify-between gap-2">
                            @if ($item->actionUrl())
                                <button
                                    type="button"
                                    wire:click="openAndMark({{ $item->id }}, '{{ $item->actionUrl() }}')"
                                    class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline cursor-pointer"
                                >
                                    <span>{{ $item->actionLabel() }}</span>
                                    <x-icon name="arrow-up-right" class="h-3 w-3" />
                                </button>
                            @else
                                <span class="text-[10px] font-medium px-2 py-0.5 rounded-md bg-secondary text-muted-foreground">
                                    {{ $item->categoryLabel() }}
                                </span>
                            @endif

                            <!-- Hover Actions -->
                            <div class="flex items-center gap-1">
                                @if ($isUnread)
                                    <button
                                        type="button"
                                        wire:click="markAsRead({{ $item->id }})"
                                        class="p-1 rounded-md text-muted-foreground hover:text-primary hover:bg-secondary transition-colors cursor-pointer"
                                        title="{{ __('Mark as read') }}"
                                    >
                                        <x-icon name="check" class="h-3 w-3" />
                                    </button>
                                @endif
                                <button
                                    type="button"
                                    wire:click="deleteNotification({{ $item->id }})"
                                    class="p-1 rounded-md text-muted-foreground hover:text-rose-500 hover:bg-secondary transition-colors cursor-pointer"
                                    title="{{ __('Dismiss') }}"
                                >
                                    <x-icon name="trash-2" class="h-3 w-3" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Unread Indicator Dot -->
                    @if ($isUnread)
                        <span class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary ring-2 ring-background"></span>
                    @endif
                </div>
            @empty
                <div class="p-8 text-center flex flex-col items-center justify-center space-y-2">
                    <div class="h-12 w-12 rounded-full bg-secondary/80 flex items-center justify-center text-muted-foreground">
                        <x-icon name="bell-off" class="h-6 w-6 opacity-60" />
                    </div>
                    <p class="text-xs font-semibold text-foreground">{{ __('No notifications here') }}</p>
                    <p class="text-[11px] text-muted-foreground max-w-[200px]">
                        {{ __('You do not have any notifications matching this filter.') }}
                    </p>
                </div>
            @endforelse
        </div>

        <!-- Footer Bar -->
        <div class="p-3 border-t border-border bg-card/60 flex items-center justify-between text-xs">
            @php
                $team = auth()->user()?->currentTeam ?? auth()->user()?->personalTeam();
                $inboxUrl = $team ? route('notifications.index', ['current_team' => $team->slug]) : url('/notifications');
            @endphp
            <a
                href="{{ $inboxUrl }}"
                wire:navigate
                @click="open = false"
                class="font-medium text-primary hover:underline flex items-center gap-1.5"
            >
                <span>{{ __('Open Notification Inbox') }}</span>
                <x-icon name="arrow-right" class="h-3.5 w-3.5" />
            </a>

            @if (auth()->user()?->can('settings.general'))
                @php
                    $settingsUrl = $team ? route('admin.settings.notification', ['current_team' => $team->slug]) : url('/system/settings/notification');
                @endphp
                <a
                    href="{{ $settingsUrl }}"
                    wire:navigate
                    @click="open = false"
                    class="text-muted-foreground hover:text-foreground flex items-center gap-1"
                    title="{{ __('Notification Settings') }}"
                >
                    <x-icon name="settings" class="h-3.5 w-3.5" />
                </a>
            @endif
        </div>
    </div>
</div>
