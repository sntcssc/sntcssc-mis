<?php

use App\Models\ApiKey;
use App\Models\ApiKeyLog;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('API Request Telemetry & Logs - SNTCSSC MIS')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = ''; // all, 2xx, 4xx, 5xx, 401, 403, 429

    #[Url(as: 'method')]
    public string $methodFilter = ''; // GET, POST, PUT, DELETE

    #[Url(as: 'key')]
    public string $keyFilter = '';

    public int $perPage = 25;

    // Log Detail Modal
    public bool $showDetailModal = false;
    public ?int $selectedLogId = null;

    // Prune Logs Modal
    public bool $showPruneModal = false;
    public string $pruneDays = '30';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMethodFilter(): void
    {
        $this->resetPage();
    }

    public function updatedKeyFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function stats(): array
    {
        $total = ApiKeyLog::count();
        $last24h = ApiKeyLog::where('created_at', '>=', Carbon::now()->subHours(24))->count();
        $successful = ApiKeyLog::whereBetween('status_code', [200, 299])->count();
        $failed = ApiKeyLog::where('status_code', '>=', 400)->count();
        $avgDuration = (float) ApiKeyLog::whereNotNull('duration_ms')->avg('duration_ms');

        $rateLimited = ApiKeyLog::where('status_code', 429)->count();

        $successRate = $total > 0 ? round(($successful / $total) * 100, 1) : 100.0;

        return [
            'total' => $total,
            'last_24h' => $last24h,
            'failed' => $failed,
            'rate_limited' => $rateLimited,
            'avg_duration_ms' => round($avgDuration, 1),
            'success_rate' => $successRate,
        ];
    }

    #[Computed]
    public function apiKeysList()
    {
        return ApiKey::withTrashed()->orderBy('name')->get(['id', 'name', 'key_prefix']);
    }

    #[Computed]
    public function selectedLog(): ?ApiKeyLog
    {
        if (! $this->selectedLogId) {
            return null;
        }

        return ApiKeyLog::with('apiKey')->find($this->selectedLogId);
    }

    #[Computed]
    public function logs()
    {
        $query = ApiKeyLog::query()->with('apiKey');

        if ($this->keyFilter) {
            $query->where('api_key_id', $this->keyFilter);
        }

        if ($this->methodFilter) {
            $query->where('method', strtoupper($this->methodFilter));
        }

        if ($this->statusFilter) {
            if ($this->statusFilter === '2xx') {
                $query->whereBetween('status_code', [200, 299]);
            } elseif ($this->statusFilter === '4xx') {
                $query->whereBetween('status_code', [400, 499]);
            } elseif ($this->statusFilter === '5xx') {
                $query->whereBetween('status_code', [500, 599]);
            } else {
                $query->where('status_code', (int) $this->statusFilter);
            }
        }

        if ($this->search) {
            $query->where(function (Builder $q) {
                $q->where('endpoint', 'like', "%{$this->search}%")
                    ->orWhere('ip_address', 'like', "%{$this->search}%")
                    ->orWhere('api_key_name', 'like', "%{$this->search}%")
                    ->orWhere('response_summary', 'like', "%{$this->search}%");
            });
        }

        return $query->orderByDesc('created_at')->paginate($this->perPage);
    }

    public function openDetailModal(int $id): void
    {
        $this->selectedLogId = $id;
        $this->showDetailModal = true;
    }

    public function openPruneModal(): void
    {
        $this->pruneDays = '30';
        $this->showPruneModal = true;
    }

    public function confirmPrune(): void
    {
        $query = ApiKeyLog::query();

        if ($this->pruneDays !== 'all') {
            $days = (int) $this->pruneDays;
            $cutoff = Carbon::now()->subDays($days);
            $query->where('created_at', '<', $cutoff);
        }

        $deletedCount = $query->delete();

        $this->showPruneModal = false;
        Toast::dispatch($this, 'info', __("Purged {$deletedCount} historical telemetry logs."));
    }
};
?>

<div class="space-y-6">
    <!-- Top Header & Actions -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="p-2 bg-emerald-500/10 text-emerald-500 rounded-xl">
                    <x-icon name="activity" class="w-6 h-6" />
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-foreground tracking-tight">{{ __('API Telemetry & Request Logs') }}</h1>
                    <p class="text-xs sm:text-sm text-muted-foreground">{{ __('Audit incoming requests, latency telemetry, headers, and security events.') }}</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.api-keys.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="key" class="w-4 h-4 text-primary" />
                <span>{{ __('Manage Keys') }}</span>
            </a>

            <a href="{{ route('admin.api-keys.docs') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="terminal" class="w-4 h-4 text-primary" />
                <span>{{ __('API Console & Docs') }}</span>
            </a>

            @can('api_keys.delete')
                <button wire:click="openPruneModal" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-destructive hover:bg-destructive/10 shadow-sm transition-colors">
                    <x-icon name="trash" class="w-4 h-4" />
                    <span>{{ __('Prune Old Logs') }}</span>
                </button>
            @endcan
        </div>
    </div>

    <!-- Telemetry Metric Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Total Logged -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Total Requests Logged') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['total']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-primary/10 text-primary">
                    <x-icon name="activity" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span class="text-emerald-500 font-semibold">+{{ number_format($this->stats['last_24h']) }}</span> in last 24h
            </div>
        </div>

        <!-- Success Rate -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Success Ratio') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['success_rate'] }}%</p>
                </div>
                <div class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-500">
                    <x-icon name="shield-check" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span>{{ __('All-time HTTP 2xx calls') }}</span>
            </div>
        </div>

        <!-- Failed Requests -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Failed / Blocked') }}</span>
                    <p class="text-2xl font-bold {{ $this->stats['failed'] > 0 ? 'text-destructive' : 'text-foreground' }} mt-1">{{ number_format($this->stats['failed']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-destructive/10 text-destructive">
                    <x-icon name="shield-alert" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span>{{ __('4xx & 5xx error responses') }}</span>
            </div>
        </div>

        <!-- Rate Limited Hits -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Rate Limited (429)') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['rate_limited']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-amber-500/10 text-amber-500">
                    <x-icon name="clock" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span>{{ __('Throttled excessive calls') }}</span>
            </div>
        </div>

        <!-- Average Latency -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Average Latency') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['avg_duration_ms'] }} <span class="text-xs font-normal text-muted-foreground">ms</span></p>
                </div>
                <div class="p-2.5 rounded-xl bg-sky-500/10 text-sky-500">
                    <x-icon name="zap" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span>{{ __('Mean execution time') }}</span>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-card rounded-xl border border-border p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-3">
        <!-- Search -->
        <div class="relative flex-1">
            <x-icon name="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('Filter by endpoint (e.g. /api/v1/ping), client IP, or error summary...') }}"
                   class="w-full pl-9 pr-4 py-2 text-xs rounded-lg border border-border bg-background text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
            @if($search)
                <button wire:click="$set('search', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                    <x-icon name="x" class="w-3.5 h-3.5" />
                </button>
            @endif
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Key Filter -->
            <select wire:model.live="keyFilter" class="px-2.5 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="">{{ __('All API Keys') }}</option>
                @foreach($this->apiKeysList as $k)
                    <option value="{{ $k->id }}">{{ $k->name }} ({{ $k->key_prefix }})</option>
                @endforeach
            </select>

            <!-- Status Filter -->
            <select wire:model.live="statusFilter" class="px-2.5 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="">{{ __('All Status Codes') }}</option>
                <option value="2xx">{{ __('2xx Success') }}</option>
                <option value="4xx">{{ __('4xx Client Errors') }}</option>
                <option value="401">{{ __('401 Unauthorized') }}</option>
                <option value="403">{{ __('403 Forbidden / Scope') }}</option>
                <option value="429">{{ __('429 Rate Limited') }}</option>
                <option value="5xx">{{ __('5xx Server Errors') }}</option>
            </select>

            <!-- Method Filter -->
            <select wire:model.live="methodFilter" class="px-2.5 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="">{{ __('All Methods') }}</option>
                <option value="GET">GET</option>
                <option value="POST">POST</option>
                <option value="PUT">PUT</option>
                <option value="DELETE">DELETE</option>
            </select>

            <!-- Per Page -->
            <select wire:model.live="perPage" class="px-2 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="25">25 / page</option>
                <option value="50">50 / page</option>
                <option value="100">100 / page</option>
            </select>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="bg-card rounded-xl border border-border shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-muted-foreground font-semibold">
                        <th class="py-3 px-4">{{ __('Timestamp') }}</th>
                        <th class="py-3 px-4">{{ __('Method') }}</th>
                        <th class="py-3 px-4">{{ __('Endpoint Path') }}</th>
                        <th class="py-3 px-4">{{ __('Status') }}</th>
                        <th class="py-3 px-4">{{ __('Latency') }}</th>
                        <th class="py-3 px-4">{{ __('Client IP & User Agent') }}</th>
                        <th class="py-3 px-4">{{ __('API Key') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('Inspector') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border font-mono">
                    @forelse($this->logs as $log)
                        <tr class="hover:bg-muted/30 transition-colors {{ $log->status_code >= 400 ? 'bg-destructive/5' : '' }}">
                            <!-- Timestamp -->
                            <td class="py-3 px-4 whitespace-nowrap text-foreground font-sans">
                                <div>
                                    <span class="font-medium text-xs">{{ $log->created_at->format('H:i:s') }}</span>
                                    <span class="text-[10px] text-muted-foreground block font-mono">{{ $log->created_at->format('Y-m-d') }}</span>
                                </div>
                            </td>

                            <!-- Method -->
                            <td class="py-3 px-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                    {{ $log->method === 'GET' ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400' :
                                       ($log->method === 'POST' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' :
                                       ($log->method === 'DELETE' ? 'bg-red-500/10 text-red-600 dark:text-red-400' : 'bg-amber-500/10 text-amber-600')) }}">
                                    {{ $log->method }}
                                </span>
                            </td>

                            <!-- Endpoint -->
                            <td class="py-3 px-4">
                                <span class="text-xs text-foreground font-medium select-all">/{{ ltrim($log->endpoint, '/') }}</span>
                                @if($log->response_summary)
                                    <span class="text-[10px] text-destructive block truncate max-w-xs font-sans mt-0.5">
                                        {{ $log->response_summary }}
                                    </span>
                                @endif
                            </td>

                            <!-- Status -->
                            <td class="py-3 px-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold
                                    {{ $log->status_code >= 200 && $log->status_code < 300 ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' :
                                       ($log->status_code === 429 ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' :
                                       'bg-destructive/10 text-destructive') }}">
                                    {{ $log->status_code }}
                                </span>
                            </td>

                            <!-- Latency -->
                            <td class="py-3 px-4 whitespace-nowrap font-mono text-[11px]">
                                @if($log->duration_ms !== null)
                                    <span class="{{ $log->duration_ms < 150 ? 'text-emerald-600' : ($log->duration_ms < 500 ? 'text-amber-600' : 'text-destructive') }}">
                                        {{ $log->duration_ms }} ms
                                    </span>
                                @else
                                    <span class="text-muted-foreground">-</span>
                                @endif
                            </td>

                            <!-- IP & User Agent -->
                            <td class="py-3 px-4">
                                <span class="text-xs text-foreground font-mono select-all block">{{ $log->ip_address }}</span>
                                <span class="text-[10px] text-muted-foreground font-sans truncate block max-w-xs" title="{{ $log->user_agent }}">
                                    {{ $log->user_agent ? Str::limit($log->user_agent, 35) : 'Unknown client' }}
                                </span>
                            </td>

                            <!-- API Key Name -->
                            <td class="py-3 px-4 whitespace-nowrap font-sans">
                                @if($log->apiKey)
                                    <span class="inline-flex items-center text-xs font-medium text-foreground">
                                        <x-icon name="key" class="w-3 h-3 mr-1 text-primary" />
                                        {{ $log->apiKey->name }}
                                    </span>
                                @else
                                    <span class="text-xs text-muted-foreground italic">
                                        {{ $log->api_key_name ?: __('Anonymous') }}
                                    </span>
                                @endif
                            </td>

                            <!-- Action -->
                            <td class="py-3 px-4 text-right whitespace-nowrap font-sans">
                                <button type="button" wire:click="openDetailModal({{ $log->id }})"
                                        class="px-2.5 py-1 text-[11px] font-medium rounded-lg border border-border bg-background text-foreground hover:bg-muted shadow-xs transition-colors flex items-center gap-1 ml-auto">
                                    <x-icon name="eye" class="w-3.5 h-3.5 text-muted-foreground" />
                                    <span>{{ __('Inspect') }}</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-muted-foreground font-sans">
                                <x-icon name="activity" class="w-8 h-8 mx-auto mb-2 opacity-50 stroke-1" />
                                <p class="font-semibold text-foreground text-sm">{{ __('No Telemetry Records Found') }}</p>
                                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Requests sent with valid or invalid API keys will be streamed and recorded here in real-time.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->logs->hasPages())
            <div class="p-4 border-t border-border font-sans">
                {{ $this->logs->links() }}
            </div>
        @endif
    </div>

    <!-- ============================================== -->
    <!-- MODAL: REQUEST TELEMETRY INSPECTOR MODAL       -->
    <!-- ============================================== -->
    @if($showDetailModal && $this->selectedLog)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/60 backdrop-blur-xs overflow-y-auto" wire:keydown.escape="$set('showDetailModal', false)">
            <div class="relative w-full max-w-2xl bg-card border border-border rounded-2xl shadow-2xl overflow-hidden my-8">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/20">
                    <div class="flex items-center gap-2.5">
                        <span class="px-2.5 py-1 rounded-lg text-xs font-bold uppercase
                            {{ $this->selectedLog->method === 'GET' ? 'bg-blue-500/10 text-blue-600' : 'bg-emerald-500/10 text-emerald-600' }}">
                            {{ $this->selectedLog->method }}
                        </span>
                        <div>
                            <h2 class="text-sm font-bold text-foreground font-mono">/{{ ltrim($this->selectedLog->endpoint, '/') }}</h2>
                            <p class="text-xs text-muted-foreground">{{ $this->selectedLog->created_at->format('M d, Y • H:i:s') }} ({{ $this->selectedLog->created_at->diffForHumans() }})</p>
                        </div>
                    </div>
                    <button wire:click="$set('showDetailModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <!-- Metrics Bar -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-xl bg-muted/30 border border-border">
                        <div>
                            <span class="text-[10px] text-muted-foreground font-medium block">{{ __('HTTP Status') }}</span>
                            <span class="text-sm font-bold font-mono {{ $this->selectedLog->status_code >= 200 && $this->selectedLog->status_code < 300 ? 'text-emerald-600' : 'text-destructive' }}">
                                {{ $this->selectedLog->status_code }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground font-medium block">{{ __('Latency') }}</span>
                            <span class="text-sm font-bold font-mono text-foreground">{{ $this->selectedLog->duration_ms ?? '-' }} ms</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground font-medium block">{{ __('Client IP') }}</span>
                            <span class="text-xs font-bold font-mono text-foreground">{{ $this->selectedLog->ip_address }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground font-medium block">{{ __('Authenticated Key') }}</span>
                            <span class="text-xs font-semibold text-foreground truncate block">
                                {{ $this->selectedLog->apiKey?->name ?? $this->selectedLog->api_key_name }}
                            </span>
                        </div>
                    </div>

                    @if($this->selectedLog->response_summary)
                        <div class="p-3 rounded-xl bg-destructive/10 border border-destructive/20 text-xs text-destructive">
                            <span class="font-bold block mb-0.5">{{ __('Error / Diagnostics Summary:') }}</span>
                            <span>{{ $this->selectedLog->response_summary }}</span>
                        </div>
                    @endif

                    <!-- User Agent -->
                    <div>
                        <span class="text-xs font-bold text-foreground block mb-1">{{ __('Client User Agent') }}</span>
                        <div class="p-2.5 rounded-lg bg-muted/40 border border-border font-mono text-xs text-muted-foreground break-all">
                            {{ $this->selectedLog->user_agent ?: 'None provided' }}
                        </div>
                    </div>

                    <!-- Sanitized Request Headers -->
                    <div>
                        <span class="text-xs font-bold text-foreground block mb-1">{{ __('Sanitized Request Headers') }}</span>
                        <pre class="p-3 rounded-lg bg-muted/40 border border-border font-mono text-[11px] text-foreground max-h-48 overflow-y-auto leading-relaxed"><code>{{ json_encode($this->selectedLog->request_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}' }}</code></pre>
                    </div>

                    <!-- Sanitized Request Payload -->
                    <div>
                        <span class="text-xs font-bold text-foreground block mb-1">{{ __('Request Parameters / Payload') }}</span>
                        <pre class="p-3 rounded-lg bg-muted/40 border border-border font-mono text-[11px] text-foreground max-h-48 overflow-y-auto leading-relaxed"><code>{{ json_encode($this->selectedLog->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'No body content' }}</code></pre>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 bg-muted/20 border-t border-border flex items-center justify-end">
                    <button type="button" wire:click="$set('showDetailModal', false)"
                            class="px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-all">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ============================================== -->
    <!-- MODAL: PRUNE HISTORICAL LOGS MODAL             -->
    <!-- ============================================== -->
    @if($showPruneModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="relative w-full max-w-md bg-card border border-border rounded-2xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-destructive/10 text-destructive rounded-xl">
                        <x-icon name="trash" class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Prune Historical API Logs') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Clean up disk and database storage by removing older telemetry.') }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Retention Period') }}</label>
                    <select wire:model="pruneDays" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground">
                        <option value="7">{{ __('Delete logs older than 7 days') }}</option>
                        <option value="30">{{ __('Delete logs older than 30 days (Recommended)') }}</option>
                        <option value="90">{{ __('Delete logs older than 90 days') }}</option>
                        <option value="all">{{ __('Purge ALL historical telemetry logs') }}</option>
                    </select>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" wire:click="$set('showPruneModal', false)"
                            class="px-4 py-2 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted transition-colors">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="confirmPrune"
                            class="px-4 py-2 text-xs font-semibold rounded-lg bg-destructive text-destructive-foreground hover:bg-destructive/90 shadow-sm transition-all flex items-center gap-1.5">
                        <x-icon name="trash" class="w-3.5 h-3.5" />
                        <span>{{ __('Prune Telemetry') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
