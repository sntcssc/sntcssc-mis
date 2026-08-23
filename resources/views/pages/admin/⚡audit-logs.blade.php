<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Audit Logs & Compliance')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'event')]
    public string $eventFilter = '';

    #[Url(as: 'user')]
    public ?int $userFilter = null;

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    public int $perPage = 25;

    public ?int $inspectingLogId = null;
    public ?AuditLog $selectedLog = null;
    public int $pruneDays = 90;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUserFilter(): void
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
        $this->search = '';
        $this->eventFilter = '';
        $this->userFilter = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get(['id', 'name', 'email']);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => AuditLog::query()->count(),
            'today' => AuditLog::query()->whereDate('created_at', today())->count(),
            'auth' => AuditLog::query()->whereIn('event', ['login', 'logout', 'failed_login', 'password_reset'])->count(),
            'settings' => AuditLog::query()->whereIn('event', ['setting_updated', 'theme_changed', 'translations_updated', 'maintenance_mode_toggled', 'language_created', 'language_updated', 'language_deleted'])->count(),
        ];
    }

    #[Computed]
    public function logs()
    {
        return AuditLog::query()
            ->with(['user', 'team'])
            ->when($this->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($uq) use ($search) {
                            $uq->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($this->eventFilter, fn ($q, $event) => $q->where('event', $event))
            ->when($this->userFilter, fn ($q, $userId) => $q->where('user_id', $userId))
            ->inDateRange($this->dateFrom, $this->dateTo)
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function inspect(int $id): void
    {
        $this->selectedLog = AuditLog::with(['user', 'team'])->findOrFail($id);
        $this->inspectingLogId = $id;
        $this->dispatch('modal-open', name: 'diff-inspector-modal');
    }

    public function pruneLogs(): void
    {
        $deleted = AuditLogService::prune($this->pruneDays);
        $this->dispatch('modal-close', name: 'prune-modal');
        Toast::dispatch($this, 'success', __(':count audit records older than :days days pruned.', ['count' => $deleted, 'days' => $this->pruneDays]));
    }

    public function exportCsv(): StreamedResponse
    {
        $filename = 'audit-logs-'.now()->format('Y-m-d-His').'.csv';

        $query = AuditLog::query()
            ->with(['user', 'team'])
            ->when($this->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->when($this->eventFilter, fn ($q, $event) => $q->where('event', $event))
            ->when($this->userFilter, fn ($q, $userId) => $q->where('user_id', $userId))
            ->inDateRange($this->dateFrom, $this->dateTo)
            ->latest('id');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Timestamp', 'User Name', 'User Email', 'Event', 'Target', 'IP Address', 'Method', 'URL', 'Description']);

            $query->chunk(250, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $log->created_at->format('Y-m-d H:i:s'),
                        $log->user?->name ?? 'System',
                        $log->user?->email ?? 'N/A',
                        $log->event,
                        $log->targetLabel(),
                        $log->ip_address,
                        $log->method,
                        $log->url,
                        $log->description,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}; ?>

<div class="space-y-6 max-w-7xl">
    {{-- Page Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('System Audit Logs & Security Trail') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Chronological record of user authentication, model lifecycle changes, system configuration updates, and security events.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5" wire:click="exportCsv">
                <x-icon name="download" class="h-3.5 w-3.5"/>
                {{ __('Export CSV') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5 text-destructive hover:bg-destructive/10" x-data x-on:click="$store.modals.open('prune-modal')">
                <x-icon name="trash" class="h-3.5 w-3.5"/>
                {{ __('Prune Logs') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Total Audit Records') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="shield-check" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2">{{ number_format($this->stats['total']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Events Today') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500">
                    <x-icon name="activity" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2">{{ number_format($this->stats['today']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('Authentication Events') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-500/10 text-blue-500">
                    <x-icon name="key" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2">{{ number_format($this->stats['auth']) }}</p>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground">{{ __('System & Configuration Changes') }}</span>
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/10 text-amber-500">
                    <x-icon name="sliders" class="h-3.5 w-3.5"/>
                </div>
            </div>
            <p class="text-2xl font-bold tracking-tight mt-2">{{ number_format($this->stats['settings']) }}</p>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="rounded-xl border border-border bg-card p-4 space-y-3 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            {{-- Search input --}}
            <div class="relative lg:col-span-2">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search description, IP, event, user…') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            {{-- Event Type Dropdown --}}
            <select
                wire:model.live="eventFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <option value="">{{ __('All Event Types') }}</option>
                <option value="login">{{ __('Login (Success)') }}</option>
                <option value="logout">{{ __('Logout') }}</option>
                <option value="failed_login">{{ __('Failed Login') }}</option>
                <option value="password_reset">{{ __('Password Reset') }}</option>
                <option value="setting_updated">{{ __('Setting Updated') }}</option>
                <option value="theme_changed">{{ __('Theme Preset / Color Changed') }}</option>
                <option value="translations_updated">{{ __('Translations Updated') }}</option>
                <option value="language_created">{{ __('Language Created') }}</option>
                <option value="language_updated">{{ __('Language Updated') }}</option>
                <option value="language_deleted">{{ __('Language Deleted') }}</option>
                <option value="maintenance_mode_toggled">{{ __('Maintenance Mode Toggled') }}</option>
                <option value="created">{{ __('Model Created') }}</option>
                <option value="updated">{{ __('Model Updated') }}</option>
                <option value="deleted">{{ __('Model Deleted') }}</option>
            </select>

            {{-- User Dropdown --}}
            <select
                wire:model.live="userFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <option value="">{{ __('All Users / System') }}</option>
                @foreach ($this->users as $u)
                    <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                @endforeach
            </select>

            {{-- Date Range --}}
            <div class="flex items-center gap-1.5">
                <input
                    type="date"
                    wire:model.live="dateFrom"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-xs shadow-xs outline-none focus-visible:border-ring"
                    title="{{ __('From date') }}"
                />
                <span class="text-xs text-muted-foreground">-</span>
                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-xs shadow-xs outline-none focus-visible:border-ring"
                    title="{{ __('To date') }}"
                />
            </div>
        </div>

        @if ($search || $eventFilter || $userFilter || $dateFrom || $dateTo)
            <div class="flex items-center justify-between pt-2 border-t border-border">
                <span class="text-xs text-muted-foreground">{{ __('Active filters applied') }}</span>
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="text-xs text-primary hover:underline font-medium cursor-pointer"
                >
                    {{ __('Clear all filters') }}
                </button>
            </div>
        @endif
    </div>

    {{-- Audit Log Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-border bg-muted/40">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Timestamp') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('User') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Event') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Target') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Description & Context') }}</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('IP & Method') }}</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Inspect') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->logs as $log)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="log-{{ $log->id }}">
                            <td class="px-4 py-3 text-xs whitespace-nowrap">
                                <div class="font-mono text-foreground">{{ $log->created_at->format('d M Y, H:i:s') }}</div>
                                <div class="text-[10px] text-muted-foreground">{{ $log->created_at->diffForHumans() }}</div>
                            </td>

                            <td class="px-4 py-3 text-xs whitespace-nowrap">
                                @if ($log->user)
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">
                                            {{ $log->user->initials() }}
                                        </div>
                                        <div class="truncate max-w-[130px]">
                                            <div class="font-medium text-foreground truncate">{{ $log->user->name }}</div>
                                            <div class="text-[10px] text-muted-foreground truncate">{{ $log->user->email }}</div>
                                        </div>
                                    </div>
                                @else
                                    <span class="inline-flex items-center gap-1 text-muted-foreground font-mono text-[11px]">
                                        <x-icon name="cpu" class="h-3 w-3"/>
                                        {{ __('System') }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-xs whitespace-nowrap">
                                <x-ui.badge :color="$log->eventBadgeColor()" class="gap-1 capitalize text-[10px]">
                                    <x-icon :name="$log->eventIcon()" class="h-3 w-3"/>
                                    {{ str_replace('_', ' ', $log->event) }}
                                </x-ui.badge>
                            </td>

                            <td class="px-4 py-3 text-xs font-mono text-muted-foreground whitespace-nowrap">
                                {{ $log->targetLabel() }}
                            </td>

                            <td class="px-4 py-3 text-xs max-w-sm">
                                <p class="text-foreground leading-relaxed truncate" title="{{ $log->description }}">
                                    {{ $log->description ?: '—' }}
                                </p>
                            </td>

                            <td class="px-4 py-3 text-xs whitespace-nowrap font-mono text-muted-foreground">
                                <div>{{ $log->ip_address ?: '—' }}</div>
                                <div class="text-[10px]">{{ $log->method }}</div>
                            </td>

                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if ($log->old_values || $log->new_values || $log->user_agent || $log->url)
                                    <button
                                        type="button"
                                        wire:click="inspect({{ $log->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-medium bg-secondary hover:bg-secondary/80 text-foreground transition-colors cursor-pointer"
                                    >
                                        <x-icon name="eye" class="h-3 w-3"/>
                                        {{ __('Diff') }}
                                    </button>
                                @else
                                    <span class="text-muted-foreground text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground text-sm">
                                <x-icon name="shield" class="mx-auto h-8 w-8 mb-2 opacity-40"/>
                                {{ __('No audit log records match your filter criteria.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->logs->hasPages())
            <div class="p-4 border-t border-border">
                {{ $this->logs->links() }}
            </div>
        @endif
    </div>

    {{-- Visual Diff & Metadata Inspector Modal --}}
    <x-ui.modal name="diff-inspector-modal" max-width="max-w-3xl" :title="__('Audit Record #') . ($selectedLog?->id ?? '')" :description="__('Full inspection of payload changes, HTTP request context and user agent.')">
        @if ($selectedLog)
            <div class="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
                {{-- Meta details grid --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-lg border border-border bg-secondary/15 text-xs">
                    <div>
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Event') }}</span>
                        <div class="mt-0.5 font-medium capitalize">{{ str_replace('_', ' ', $selectedLog->event) }}</div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Initiator') }}</span>
                        <div class="mt-0.5 font-medium truncate">{{ $selectedLog->user?->name ?? 'System' }}</div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('IP Address') }}</span>
                        <div class="mt-0.5 font-mono">{{ $selectedLog->ip_address ?: '—' }}</div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Timestamp') }}</span>
                        <div class="mt-0.5 font-mono">{{ $selectedLog->created_at->format('d M Y, H:i:s') }}</div>
                    </div>
                    <div class="col-span-2 sm:col-span-4">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Request URL & Method') }}</span>
                        <div class="mt-0.5 font-mono text-[11px] truncate text-muted-foreground">
                            <span class="font-bold text-foreground">{{ $selectedLog->method }}</span> {{ $selectedLog->url }}
                        </div>
                    </div>
                    @if ($selectedLog->user_agent)
                        <div class="col-span-2 sm:col-span-4">
                            <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('User Agent') }}</span>
                            <div class="mt-0.5 text-[11px] font-mono text-muted-foreground truncate" title="{{ $selectedLog->user_agent }}">
                                {{ $selectedLog->user_agent }}
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Old vs New Values Visual Side-by-Side Diff --}}
                <div class="space-y-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Attribute Changes & JSON Payload') }}</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {{-- Old Values --}}
                        <div class="rounded-lg border border-red-500/20 bg-red-500/5 p-3 space-y-1.5">
                            <div class="flex items-center justify-between text-red-600 dark:text-red-400 font-semibold text-xs">
                                <span>{{ __('Previous Values (Before)') }}</span>
                                <x-icon name="minus" class="h-3.5 w-3.5"/>
                            </div>
                            <pre class="font-mono text-[11px] p-2 rounded bg-background/60 text-foreground overflow-x-auto max-h-60 leading-relaxed">{{ !empty($selectedLog->old_values) ? json_encode($selectedLog->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null' }}</pre>
                        </div>

                        {{-- New Values --}}
                        <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 p-3 space-y-1.5">
                            <div class="flex items-center justify-between text-emerald-600 dark:text-emerald-400 font-semibold text-xs">
                                <span>{{ __('New Values (After)') }}</span>
                                <x-icon name="plus" class="h-3.5 w-3.5"/>
                            </div>
                            <pre class="font-mono text-[11px] p-2 rounded bg-background/60 text-foreground overflow-x-auto max-h-60 leading-relaxed">{{ !empty($selectedLog->new_values) ? json_encode($selectedLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null' }}</pre>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-2 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('diff-inspector-modal')">
                        {{ __('Close Inspector') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- Prune Modal --}}
    <x-ui.modal name="prune-modal" max-width="max-w-sm" :title="__('Prune Audit Log Records')" :description="__('Purge historic log entries older than the selected retention window.')">
        <div class="space-y-4">
            <x-ui.select
                wire:model="pruneDays"
                :label="__('Retention Window') . ' *'"
                :options="[
                    30 => __('Older than 30 days'),
                    60 => __('Older than 60 days'),
                    90 => __('Older than 90 days (Recommended)'),
                    180 => __('Older than 180 days'),
                    365 => __('Older than 1 year'),
                ]"
            />

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('prune-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" wire:click="pruneLogs">
                    {{ __('Prune Records') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
