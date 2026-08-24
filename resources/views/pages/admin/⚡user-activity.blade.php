<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('My Activity Log & Security Trail')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'event')]
    public string $eventFilter = '';

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    public int $perPage = 15;

    public ?int $selectedLogId = null;
    public ?AuditLog $selectedLog = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'eventFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    #[Computed]
    public function stats(): array
    {
        $userId = Auth::id();

        return [
            'total' => AuditLog::where('user_id', $userId)->count(),
            'logins' => AuditLog::where('user_id', $userId)->whereIn('event', ['login', 'failed_login', 'logout'])->count(),
            'today' => AuditLog::where('user_id', $userId)->whereDate('created_at', today())->count(),
            'last_login' => Auth::user()->last_login_at?->diffForHumans() ?? 'Current session',
        ];
    }

    #[Computed]
    public function activities()
    {
        $userId = Auth::id();

        return AuditLog::query()
            ->where('user_id', $userId)
            ->when($this->search, function (Builder $query, $search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->when($this->eventFilter, fn ($q, $event) => $q->where('event', $event))
            ->inDateRange($this->dateFrom, $this->dateTo)
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function inspect(int $id): void
    {
        $userId = Auth::id();
        $this->selectedLog = AuditLog::where('user_id', $userId)->findOrFail($id);
        $this->selectedLogId = $id;
        $this->dispatch('modal-open', name: 'activity-diff-modal');
    }
}; ?>

<div class="space-y-4 sm:space-y-6 max-w-5xl">
    {{-- Page Header --}}
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('My Activity & Security History') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">
                {{ __('Transparent compliance record of all your actions, session authentication events, and profile updates.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5" href="{{ route('profile.edit') }}" wire:navigate>
                <x-icon name="user" class="h-3.5 w-3.5"/>
                {{ __('Profile Settings') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Total Actions Recorded') }}</span>
            <p class="text-2xl font-bold tracking-tight mt-1">{{ number_format($this->stats['total']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Authentication Events') }}</span>
            <p class="text-2xl font-bold tracking-tight mt-1 text-primary">{{ number_format($this->stats['logins']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Events Today') }}</span>
            <p class="text-2xl font-bold tracking-tight mt-1 text-emerald-600 dark:text-emerald-400">{{ number_format($this->stats['today']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Last Login Session') }}</span>
            <p class="text-sm font-semibold tracking-tight mt-2 text-foreground truncate">{{ $this->stats['last_login'] }}</p>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="rounded-xl border border-border bg-card p-4 space-y-3 shadow-xs">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
            <div class="relative md:col-span-5 min-w-0">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search your activity description, IP…') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <div class="md:col-span-3 min-w-0">
                <select
                    wire:model.live="eventFilter"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 truncate"
                >
                    <option value="">{{ __('All Event Types') }}</option>
                    <option value="login">{{ __('Logins') }}</option>
                    <option value="logout">{{ __('Logouts') }}</option>
                    <option value="password_reset">{{ __('Password Reset') }}</option>
                    <option value="created">{{ __('Created Items') }}</option>
                    <option value="updated">{{ __('Updated Items') }}</option>
                    <option value="deleted">{{ __('Deleted Items') }}</option>
                </select>
            </div>

            <div class="md:col-span-4 flex items-center gap-1.5 min-w-0">
                <input type="date" wire:model.live="dateFrom" class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-2 text-xs shadow-xs outline-none focus-visible:border-ring" title="{{ __('From date') }}"/>
                <span class="text-xs text-muted-foreground shrink-0">-</span>
                <input type="date" wire:model.live="dateTo" class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-2 text-xs shadow-xs outline-none focus-visible:border-ring" title="{{ __('To date') }}"/>
            </div>
        </div>

        @if ($search || $eventFilter || $dateFrom || $dateTo)
            <div class="flex items-center justify-between pt-2 border-t border-border">
                <span class="text-xs text-muted-foreground">{{ __('Active filter applied') }}</span>
                <button type="button" wire:click="resetFilters" class="text-xs text-primary hover:underline font-medium cursor-pointer">
                    {{ __('Clear filters') }}
                </button>
            </div>
        @endif
    </div>

    {{-- Activity Timeline --}}
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 space-y-4">
            @forelse ($this->activities as $activity)
                <div class="flex items-start gap-3.5 pb-4 border-b border-border last:border-b-0 last:pb-0" wire:key="act-{{ $activity->id }}">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-{{ $activity->eventBadgeColor() }}-500/10 text-{{ $activity->eventBadgeColor() }}-600 mt-0.5">
                        <x-icon :name="$activity->eventIcon()" class="h-4 w-4"/>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center justify-between gap-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-foreground capitalize">{{ str_replace('_', ' ', $activity->event) }}</span>
                                <span class="text-[10px] font-mono text-muted-foreground px-1.5 py-0.2 rounded bg-secondary">
                                    {{ $activity->ip_address ?: '127.0.0.1' }}
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground font-mono">{{ $activity->created_at->format('d M Y, h:i A') }} ({{ $activity->created_at->diffForHumans() }})</span>
                        </div>

                        <p class="text-xs text-muted-foreground mt-1 leading-relaxed">{{ $activity->description ?: 'Action completed.' }}</p>

                        @if ($activity->old_values || $activity->new_values)
                            <div class="mt-2">
                                <button
                                    type="button"
                                    wire:click="inspect({{ $activity->id }})"
                                    class="inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline cursor-pointer"
                                >
                                    <x-icon name="eye" class="h-3 w-3"/>
                                    {{ __('View Change Diff') }}
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="py-12 text-center text-muted-foreground text-xs">
                    <x-icon name="activity" class="mx-auto h-8 w-8 mb-2 opacity-40"/>
                    {{ __('No activity records matching criteria.') }}
                </div>
            @endforelse
        </div>

        @if ($this->activities->hasPages())
            <div class="p-4 border-t border-border">
                {{ $this->activities->links() }}
            </div>
        @endif
    </div>

    {{-- Activity Diff Inspector Modal --}}
    <x-ui.modal name="activity-diff-modal" max-width="max-w-xl" :title="__('Activity Change Details')" :description="__('Inspecting state modifications for this action.')">
        @if ($selectedLog)
            <div class="space-y-4 text-xs">
                <div class="p-3 rounded-lg border border-border bg-secondary/20 space-y-1 font-mono text-[11px]">
                    <div><strong>{{ __('Event:') }}</strong> {{ $selectedLog->event }}</div>
                    <div><strong>{{ __('Timestamp:') }}</strong> {{ $selectedLog->created_at->format('d M Y, H:i:s') }}</div>
                    <div><strong>{{ __('IP Address:') }}</strong> {{ $selectedLog->ip_address }}</div>
                    <div><strong>{{ __('Description:') }}</strong> {{ $selectedLog->description }}</div>
                </div>

                @if ($selectedLog->new_values)
                    <div class="space-y-1.5">
                        <span class="font-semibold text-foreground">{{ __('Recorded Data:') }}</span>
                        <pre class="p-3 rounded-lg bg-background border border-border font-mono text-[11px] overflow-x-auto max-h-48">{{ json_encode($selectedLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endif

                <div class="flex justify-end pt-2 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('activity-diff-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
