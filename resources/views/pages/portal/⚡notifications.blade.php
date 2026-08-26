<?php

use App\Models\AppNotification;
use App\Services\NotificationService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Notification Center & Inbox')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $selectedCategory = 'all';
    public string $selectedStatus = 'all'; // all, unread, read
    public int $perPage = 15;

    public array $selectedIds = [];
    public bool $selectAll = false;

    public ?int $viewingNotificationId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedIds = $this->notifications->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $this->selectedIds = [];
        }
    }

    #[Computed]
    public function notifications()
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $query = AppNotification::forUser($user->id)->orderBy('created_at', 'desc');

        if (! empty(trim($this->search))) {
            $s = trim($this->search);
            $query->where(function (Builder $q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                    ->orWhere('message', 'like', "%{$s}%")
                    ->orWhere('category', 'like', "%{$s}%");
            });
        }

        if ($this->selectedCategory !== 'all') {
            $query->category($this->selectedCategory);
        }

        if ($this->selectedStatus === 'unread') {
            $query->unread();
        } elseif ($this->selectedStatus === 'read') {
            $query->read();
        }

        return $query->paginate($this->perPage);
    }

    #[Computed]
    public function unreadTotal(): int
    {
        $user = auth()->user();

        return $user ? AppNotification::forUser($user->id)->unread()->count() : 0;
    }

    #[Computed]
    public function viewingNotification(): ?AppNotification
    {
        if (! $this->viewingNotificationId) {
            return null;
        }

        $user = auth()->user();

        return $user ? AppNotification::forUser($user->id)->find($this->viewingNotificationId) : null;
    }

    public function viewDetails(int $id): void
    {
        $this->viewingNotificationId = $id;
        $this->markAsRead($id);
        $this->dispatch('modal-open', name: 'notification-details');
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

    public function markAsUnread(int $id): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $notif = AppNotification::forUser($user->id)->find($id);
        $notif?->markAsUnread();
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
        Toast::dispatch($this, 'success', __('Notification removed.'));
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

    public function bulkMarkAsRead(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $user = auth()->user();
        if (! $user) {
            return;
        }

        /** @var NotificationService $service */
        $service = app(NotificationService::class);
        $service->markAsRead($this->selectedIds, $user->id);

        $this->selectedIds = [];
        $this->selectAll = false;
        Toast::dispatch($this, 'success', __('Selected notifications marked as read.'));
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $user = auth()->user();
        if (! $user) {
            return;
        }

        /** @var NotificationService $service */
        $service = app(NotificationService::class);
        foreach ($this->selectedIds as $id) {
            $service->deleteNotification((int) $id, $user->id);
        }

        $this->selectedIds = [];
        $this->selectAll = false;
        Toast::dispatch($this, 'success', __('Selected notifications deleted.'));
    }

    public function clearAll(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        /** @var NotificationService $service */
        $service = app(NotificationService::class);
        $service->clearAll($user->id);

        $this->selectedIds = [];
        $this->selectAll = false;
        Toast::dispatch($this, 'success', __('All notifications cleared.'));
    }
};

?>

<div class="space-y-6 max-w-7xl mx-auto pb-12">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border pb-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <div class="p-2 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="inbox" class="h-6 w-6" />
                </div>
                <span>{{ __('Notification Center & Inbox') }}</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage your real-time alerts, support ticket updates, and system communications.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->unreadTotal > 0)
                <x-ui.button wire:click="markAllAsRead" variant="outline" icon="check-check" size="sm">
                    {{ __('Mark All as Read') }}
                </x-ui.button>
            @endif

            <x-ui.button wire:click="clearAll" variant="outline" icon="trash-2" size="sm" wire:confirm="{{ __('Are you sure you want to clear all your notifications?') }}">
                {{ __('Clear All') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="flex flex-col md:flex-row md:items-center gap-3 justify-between">
            <!-- Search Input -->
            <div class="relative flex-1">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" />
                <x-ui.input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search notifications by title or message…') }}"
                    class="pl-9"
                />
            </div>

            <!-- Filter Controls -->
            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Category -->
                <select wire:model.live="selectedCategory" class="rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="all">{{ __('All Categories') }}</option>
                    <option value="ticket">{{ __('Support Tickets') }}</option>
                    <option value="chat">{{ __('Live Chat') }}</option>
                    <option value="call">{{ __('Audio / Video Calls') }}</option>
                    <option value="system">{{ __('System Alerts') }}</option>
                    <option value="security">{{ __('Security') }}</option>
                </select>

                <!-- Status -->
                <select wire:model.live="selectedStatus" class="rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="all">{{ __('All Status') }}</option>
                    <option value="unread">{{ __('Unread Only') }}</option>
                    <option value="read">{{ __('Read Only') }}</option>
                </select>

                <!-- Per Page -->
                <select wire:model.live="perPage" class="rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="10">10 / {{ __('page') }}</option>
                    <option value="15">15 / {{ __('page') }}</option>
                    <option value="25">25 / {{ __('page') }}</option>
                    <option value="50">50 / {{ __('page') }}</option>
                </select>
            </div>
        </div>

        <!-- Bulk Action Bar -->
        @if (count($selectedIds) > 0)
            <div class="p-2.5 rounded-lg bg-primary/10 border border-primary/20 flex flex-wrap items-center justify-between gap-2 text-xs">
                <div class="flex items-center gap-2 text-foreground font-semibold">
                    <x-icon name="check-square" class="h-4 w-4 text-primary" />
                    <span>{{ __(':count selected', ['count' => count($selectedIds)]) }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.button wire:click="bulkMarkAsRead" variant="secondary" size="sm" icon="check">
                        {{ __('Mark Read') }}
                    </x-ui.button>
                    <x-ui.button wire:click="bulkDelete" variant="destructive" size="sm" icon="trash-2" wire:confirm="{{ __('Delete selected notifications?') }}">
                        {{ __('Delete Selected') }}
                    </x-ui.button>
                </div>

            </div>
        @endif
    </div>

    <!-- Notifications List View -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="divide-y divide-border">
            <!-- Header Checkbox row -->
            <div class="p-3.5 bg-secondary/30 flex items-center justify-between text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                <div class="flex items-center gap-3">
                    <input type="checkbox" wire:model.live="selectAll" class="rounded border-border text-primary focus:ring-primary h-4 w-4 cursor-pointer" />
                    <span>{{ __('Notification Item') }}</span>
                </div>
                <div class="hidden sm:flex items-center gap-12 pr-4">
                    <span>{{ __('Category') }}</span>
                    <span>{{ __('Time') }}</span>
                    <span>{{ __('Actions') }}</span>
                </div>
            </div>

            <!-- List Items -->
            @forelse ($this->notifications as $notif)
                @php
                    $isUnread = ! $notif->isRead();
                @endphp
                <div
                    wire:key="inbox-notif-{{ $notif->id }}"
                    class="p-4 transition-colors hover:bg-secondary/40 flex flex-col sm:flex-row sm:items-center justify-between gap-3 {{ $isUnread ? 'bg-primary/5 font-medium' : '' }}"
                >
                    <!-- Left: Checkbox + Icon + Details -->
                    <div class="flex items-start gap-3 flex-1 min-w-0">
                        <input
                            type="checkbox"
                            wire:model.live="selectedIds"
                            value="{{ $notif->id }}"
                            class="rounded border-border text-primary focus:ring-primary h-4 w-4 mt-1 cursor-pointer"
                        />

                        <!-- Category Icon -->
                        <div class="h-10 w-10 shrink-0 rounded-xl border flex items-center justify-center {{ $notif->colorClass() }}">
                            <x-icon :name="$notif->iconName()" class="h-5 w-5" />
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-foreground truncate">
                                    {{ $notif->title }}
                                </h3>
                                @if ($isUnread)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-primary text-primary-foreground">
                                        {{ __('New') }}
                                    </span>
                                @endif
                            </div>

                            <p class="text-xs text-muted-foreground line-clamp-2 mt-1 leading-relaxed">
                                {{ $notif->message }}
                            </p>

                            <!-- Mobile Category & Time -->
                            <div class="flex sm:hidden items-center gap-2 mt-2 text-[11px] text-muted-foreground">
                                <span class="px-2 py-0.5 rounded-md bg-secondary text-foreground font-medium">
                                    {{ $notif->categoryLabel() }}
                                </span>
                                <span>•</span>
                                <span>{{ $notif->created_at?->diffForHumans() }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Metadata + Actions -->
                    <div class="flex items-center justify-between sm:justify-end gap-3 shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-border/60">
                        <div class="hidden sm:flex flex-col items-end text-right">
                            <span class="text-xs font-medium px-2 py-0.5 rounded-md bg-secondary text-foreground">
                                {{ $notif->categoryLabel() }}
                            </span>
                            <span class="text-[11px] text-muted-foreground mt-1">
                                {{ $notif->created_at?->diffForHumans() }}
                            </span>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-1.5">
                            @if ($notif->actionUrl())
                                <a
                                    href="{{ $notif->actionUrl() }}"
                                    wire:navigate
                                    wire:click="markAsRead({{ $notif->id }})"
                                    class="px-2.5 py-1.5 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold flex items-center gap-1 transition-colors"
                                >
                                    <span>{{ $notif->actionLabel() }}</span>
                                    <x-icon name="arrow-up-right" class="h-3 w-3" />
                                </a>
                            @endif

                            <x-ui.button
                                wire:click="viewDetails({{ $notif->id }})"
                                variant="outline"
                                size="sm"
                                icon="eye"
                                title="{{ __('View Details') }}"
                            />

                            @if ($isUnread)
                                <x-ui.button
                                    wire:click="markAsRead({{ $notif->id }})"
                                    variant="outline"
                                    size="sm"
                                    icon="check"
                                    title="{{ __('Mark as Read') }}"
                                />
                            @else
                                <x-ui.button
                                    wire:click="markAsUnread({{ $notif->id }})"
                                    variant="outline"
                                    size="sm"
                                    icon="rotate-ccw"
                                    title="{{ __('Mark as Unread') }}"
                                />
                            @endif

                            <x-ui.button
                                wire:click="deleteNotification({{ $notif->id }})"
                                variant="outline"
                                size="sm"
                                icon="trash-2"
                                title="{{ __('Delete') }}"
                                class="text-rose-500 hover:text-rose-600 hover:bg-rose-500/10"
                            />

                        </div>
                    </div>
                </div>
            @empty
                <div class="p-12 text-center flex flex-col items-center justify-center space-y-3">
                    <div class="h-16 w-16 rounded-full bg-secondary/80 flex items-center justify-center text-muted-foreground">
                        <x-icon name="inbox" class="h-8 w-8 opacity-50" />
                    </div>
                    <h3 class="text-base font-semibold text-foreground">{{ __('No notifications found') }}</h3>
                    <p class="text-xs text-muted-foreground max-w-sm">
                        {{ __('You do not have any notifications matching your current filters or search criteria.') }}
                    </p>
                </div>
            @endforelse
        </div>

        <!-- Pagination -->
        @if ($this->notifications instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->notifications->hasPages())
            <div class="p-4 border-t border-border bg-card">
                {{ $this->notifications->links() }}
            </div>
        @endif
    </div>

    <!-- Notification Details Modal -->
    <x-ui.modal name="notification-details" maxWidth="lg">
        @if ($this->viewingNotification)
            <div class="p-6 space-y-5">
                <div class="flex items-start gap-3.5 border-b border-border pb-4">
                    <div class="h-12 w-12 rounded-xl border flex items-center justify-center shrink-0 {{ $this->viewingNotification->colorClass() }}">
                        <x-icon :name="$this->viewingNotification->iconName()" class="h-6 w-6" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-md bg-secondary text-foreground">
                                {{ $this->viewingNotification->categoryLabel() }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ $this->viewingNotification->created_at?->format('d M Y, h:i A') }}
                            </span>
                        </div>
                        <h2 class="text-base font-bold text-foreground mt-1">
                            {{ $this->viewingNotification->title }}
                        </h2>
                    </div>
                </div>

                <div class="text-sm text-foreground leading-relaxed whitespace-pre-wrap">
                    {{ $this->viewingNotification->message }}
                </div>

                <!-- Structured Data Metadata Preview if present -->
                @if (!empty($this->viewingNotification->data['metadata']))
                    <div class="p-3.5 rounded-xl border border-border bg-secondary/30 space-y-2">
                        <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {{ __('Event Metadata') }}
                        </span>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            @foreach ($this->viewingNotification->data['metadata'] as $mKey => $mVal)
                                @if (is_scalar($mVal))
                                    <div>
                                        <span class="text-muted-foreground font-medium">{{ ucwords(str_replace('_', ' ', $mKey)) }}:</span>
                                        <span class="text-foreground font-semibold ml-1">{{ (string) $mVal }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <x-ui.button x-on:click="$store.modals.close('notification-details')" variant="outline">
                        {{ __('Close') }}
                    </x-ui.button>

                    @if ($this->viewingNotification->actionUrl())
                        <a
                            href="{{ $this->viewingNotification->actionUrl() }}"
                            wire:navigate
                            class="px-4 py-2 rounded-lg bg-primary text-primary-foreground font-semibold text-xs flex items-center gap-1.5 hover:bg-primary/90 transition-colors"
                        >
                            <span>{{ $this->viewingNotification->actionLabel() }}</span>
                            <x-icon name="arrow-up-right" class="h-4 w-4" />
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
