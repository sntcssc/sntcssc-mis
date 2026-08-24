<?php

use App\Models\CommunicationLog;
use App\Services\AuditLogService;
use App\Services\CommunicationService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Communications Delivery Logs')] class extends Component {
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'channel')]
    public string $channelFilter = 'all';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'date')]
    public string $dateFilter = 'all';

    public bool $showTrashed = false;

    // Selection for bulk actions
    public array $selectedLogs = [];
    public bool $selectAll = false;

    // Details modal
    public bool $detailsModalOpen = false;
    public ?int $viewingLogId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedLogs = $this->getCurrentPageLogIds();
        } else {
            $this->selectedLogs = [];
        }
    }

    protected function getCurrentPageLogIds(): array
    {
        return $this->getFilteredLogsQuery()->pluck('id')->map(fn ($id) => (string) $id)->toArray();
    }

    public function viewLogDetails(int $id): void
    {
        $this->viewingLogId = $id;
        $this->detailsModalOpen = true;
    }

    public function resendLog(int $id, CommunicationService $communicationService): void
    {
        try {
            $log = CommunicationLog::withTrashed()->findOrFail($id);
            $result = $communicationService->resend($log);

            if ($result['success']) {
                Toast::dispatch($this, 'success', $result['message']);
            } else {
                Toast::dispatch($this, 'error', $result['message']);
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to resend: :error', ['error' => $e->getMessage()]));
        }
    }

    public function bulkResendSelected(CommunicationService $communicationService): void
    {
        if (empty($this->selectedLogs)) {
            Toast::dispatch($this, 'warning', __('Please select at least one message to resend.'));

            return;
        }

        try {
            $result = $communicationService->bulkResend(array_map('intval', $this->selectedLogs));
            $this->selectedLogs = [];
            $this->selectAll = false;

            if ($result['success']) {
                Toast::dispatch($this, 'success', $result['message']);
            } else {
                Toast::dispatch($this, 'error', $result['message']);
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Bulk resend failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public ?int $deletingLogId = null;

    public function confirmDelete(int $id): void
    {
        $this->deletingLogId = $id;
        $this->dispatch('modal-open', name: 'delete-log-modal');
    }

    public function deleteLog(int $id): void
    {
        $this->deletingLogId = $id;
        $this->executeDelete();
    }

    public function executeDelete(): void
    {
        if (! $this->deletingLogId) {
            return;
        }

        try {
            $log = CommunicationLog::findOrFail($this->deletingLogId);
            $log->delete();

            AuditLogService::log(
                event: 'communication_log_deleted',
                description: "Soft deleted communication log #{$this->deletingLogId} ({$log->channel} to {$log->recipient})",
                userId: auth()->id()
            );

            $this->deletingLogId = null;
            $this->dispatch('modal-close', name: 'delete-log-modal');
            Toast::dispatch($this, 'success', __('Message log moved to trash.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete message log.'));
        }
    }

    public function confirmBulkDelete(): void
    {
        if (empty($this->selectedLogs)) {
            Toast::dispatch($this, 'warning', __('Please select items to delete.'));

            return;
        }

        $this->dispatch('modal-open', name: 'bulk-delete-log-modal');
    }

    public function bulkDeleteSelected(): void
    {
        $this->executeBulkDelete();
    }

    public function executeBulkDelete(): void
    {
        if (empty($this->selectedLogs)) {
            return;
        }

        try {
            $count = CommunicationLog::whereIn('id', $this->selectedLogs)->delete();
            $this->selectedLogs = [];
            $this->selectAll = false;

            AuditLogService::log(
                event: 'communication_logs_bulk_deleted',
                description: "Soft deleted {$count} communication logs in bulk.",
                userId: auth()->id()
            );

            $this->dispatch('modal-close', name: 'bulk-delete-log-modal');
            Toast::dispatch($this, 'success', __(':count logs moved to trash.', ['count' => $count]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Bulk deletion failed.'));
        }
    }

    public function restoreLog(int $id): void
    {
        try {
            $log = CommunicationLog::onlyTrashed()->findOrFail($id);
            $log->restore();

            AuditLogService::log(
                event: 'communication_log_restored',
                description: "Restored communication log #{$id}",
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Log entry restored successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to restore log.'));
        }
    }

    public function forceDeleteLog(int $id): void
    {
        try {
            $log = CommunicationLog::onlyTrashed()->findOrFail($id);
            $log->forceDelete();

            Toast::dispatch($this, 'success', __('Log permanently deleted.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to permanently delete log.'));
        }
    }

    protected function getFilteredLogsQuery(): Builder
    {
        return CommunicationLog::query()
            ->with(['user', 'sender'])
            ->when($this->showTrashed, fn (Builder $q) => $q->onlyTrashed())
            ->channel($this->channelFilter)
            ->status($this->statusFilter)
            ->when($this->dateFilter !== 'all', function (Builder $q) {
                match ($this->dateFilter) {
                    'today' => $q->whereDate('created_at', Carbon::today()),
                    '7days' => $q->where('created_at', '>=', now()->subDays(7)),
                    '30days' => $q->where('created_at', '>=', now()->subDays(30)),
                    default => null,
                };
            })
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('recipient', 'like', $term)
                        ->orWhere('recipient_name', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhere('content', 'like', $term)
                        ->orWhere('error_message', 'like', $term)
                        ->orWhere('template_code', 'like', $term);
                });
            })
            ->latest('id');
    }

    public function with(): array
    {
        $logs = $this->getFilteredLogsQuery()->paginate(15);

        // Overall aggregate metrics
        $totalLogs = CommunicationLog::count();
        $successfulLogs = CommunicationLog::whereIn('status', [CommunicationLog::STATUS_SENT, CommunicationLog::STATUS_DELIVERED])->count();
        $failedLogs = CommunicationLog::where('status', CommunicationLog::STATUS_FAILED)->count();
        $emailLogs = CommunicationLog::where('channel', CommunicationLog::CHANNEL_EMAIL)->count();
        $smsLogs = CommunicationLog::where('channel', CommunicationLog::CHANNEL_SMS)->count();
        $successRate = $totalLogs > 0 ? round(($successfulLogs / $totalLogs) * 100, 1) : 100;

        $viewingLog = $this->viewingLogId ? CommunicationLog::withTrashed()->with(['user', 'sender'])->find($this->viewingLogId) : null;

        return [
            'logs' => $logs,
            'metrics' => [
                'total' => $totalLogs,
                'successful' => $successfulLogs,
                'failed' => $failedLogs,
                'email' => $emailLogs,
                'sms' => $smsLogs,
                'success_rate' => $successRate,
            ],
            'viewingLog' => $viewingLog,
        ];
    }
}; ?>

<div class="space-y-6 max-w-7xl">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <x-icon name="activity" class="h-6 w-6"/>
            </div>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Communications Delivery Logs') }}</h1>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Monitor real-time delivery status, content, recipient details, failure reasons, and resend SMS & emails.') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <a
                href="{{ route('admin.communications.compose') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs transition-colors cursor-pointer"
            >
                <x-icon name="send" class="h-4 w-4"/>
                {{ __('Compose & Bulk Send') }}
            </a>
        </div>
    </div>

    {{-- Metric Stat Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5">
        <x-ui.stat-card
            icon="message-square"
            color="primary"
            label="{{ __('Total Messages') }}"
            value="{{ number_format($metrics['total']) }}"
        />
        <x-ui.stat-card
            icon="check-circle-2"
            color="emerald"
            label="{{ __('Success Rate') }}"
            value="{{ $metrics['success_rate'] }}%"
            hint="{{ number_format($metrics['successful']) }} {{ __('delivered') }}"
        />
        <x-ui.stat-card
            icon="alert-circle"
            color="rose"
            label="{{ __('Failed Deliveries') }}"
            value="{{ number_format($metrics['failed']) }}"
            hint="{{ __('Requires attention') }}"
        />
        <x-ui.stat-card
            icon="smartphone"
            color="cyan"
            label="{{ __('SMS Dispatches') }}"
            value="{{ number_format($metrics['sms']) }}"
        />
        <x-ui.stat-card
            icon="mail"
            color="violet"
            label="{{ __('Email Dispatches') }}"
            value="{{ number_format($metrics['email']) }}"
        />
    </div>

    {{-- Filters & Actions Toolbar --}}
    <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3.5 rounded-xl border border-border bg-card p-4 shadow-xs">
        {{-- Search & Primary Filters --}}
        <div class="flex flex-wrap items-center gap-2.5 flex-1">
            <div class="relative flex-1 min-w-[200px] sm:max-w-xs">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search recipient, subject, content...') }}"
                    class="h-9 w-full rounded-lg border border-input bg-transparent pl-9 pr-3 text-xs outline-none focus:border-ring"
                />
            </div>

            {{-- Channel Filter Pills --}}
            <div class="flex items-center rounded-lg border border-border bg-secondary/30 p-0.5 text-xs">
                <button
                    type="button"
                    wire:click="$set('channelFilter', 'all')"
                    class="px-2.5 py-1 rounded-md transition-colors cursor-pointer {{ $channelFilter === 'all' ? 'bg-background text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('All Channels') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('channelFilter', 'email')"
                    class="px-2.5 py-1 rounded-md transition-colors cursor-pointer flex items-center gap-1 {{ $channelFilter === 'email' ? 'bg-background text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="mail" class="h-3 w-3 text-violet-500"/>
                    {{ __('Email') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('channelFilter', 'sms')"
                    class="px-2.5 py-1 rounded-md transition-colors cursor-pointer flex items-center gap-1 {{ $channelFilter === 'sms' ? 'bg-background text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="smartphone" class="h-3 w-3 text-cyan-500"/>
                    {{ __('SMS') }}
                </button>
            </div>

            {{-- Status Filter --}}
            <select
                wire:model.live="statusFilter"
                class="h-9 rounded-lg border border-input bg-transparent px-2.5 text-xs outline-none focus:border-ring text-muted-foreground"
            >
                <option value="all">{{ __('All Statuses') }}</option>
                <option value="sent">{{ __('Sent / Delivered') }}</option>
                <option value="failed">{{ __('Failed') }}</option>
                <option value="pending">{{ __('Pending') }}</option>
            </select>

            {{-- Date Filter --}}
            <select
                wire:model.live="dateFilter"
                class="h-9 rounded-lg border border-input bg-transparent px-2.5 text-xs outline-none focus:border-ring text-muted-foreground"
            >
                <option value="all">{{ __('All Time') }}</option>
                <option value="today">{{ __('Today') }}</option>
                <option value="7days">{{ __('Last 7 Days') }}</option>
                <option value="30days">{{ __('Last 30 Days') }}</option>
            </select>
        </div>

        {{-- Bulk actions & Trash toggle --}}
        <div class="flex items-center gap-2">
            @if (! empty($selectedLogs))
                <button
                    type="button"
                    wire:click="bulkResendSelected"
                    wire:loading.attr="disabled"
                    class="h-9 px-3 rounded-lg bg-emerald-600 text-white text-xs font-semibold flex items-center gap-1.5 hover:bg-emerald-700 transition-colors cursor-pointer shadow-xs"
                >
                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5"/>
                    <span>{{ __('Resend Selected (:count)', ['count' => count($selectedLogs)]) }}</span>
                </button>

                <button
                    type="button"
                    wire:click="confirmBulkDelete"
                    class="h-9 px-3 rounded-lg bg-rose-600/15 text-rose-600 text-xs font-semibold flex items-center gap-1.5 hover:bg-rose-600/25 transition-colors cursor-pointer border border-rose-500/20"
                >
                    <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                    <span>{{ __('Delete (:count)', ['count' => count($selectedLogs)]) }}</span>
                </button>
            @endif

            <button
                type="button"
                wire:click="$toggle('showTrashed')"
                class="h-9 px-3 rounded-lg border border-border text-xs font-medium flex items-center gap-1.5 cursor-pointer transition-colors {{ $showTrashed ? 'bg-amber-500/10 text-amber-600 border-amber-500/20' : 'text-muted-foreground hover:bg-secondary/50' }}"
            >
                <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                <span>{{ $showTrashed ? __('Viewing Trash') : __('Trash') }}</span>
            </button>
        </div>
    </div>

    {{-- Delivery Logs Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-border bg-secondary/30 text-muted-foreground uppercase text-[10px] font-semibold tracking-wider">
                    <tr>
                        <th class="w-10 px-4 py-3 text-center">
                            <input
                                type="checkbox"
                                wire:model.live="selectAll"
                                class="rounded border-input text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"
                            />
                        </th>
                        <th class="px-4 py-3">{{ __('Recipient') }}</th>
                        <th class="px-4 py-3">{{ __('Channel & Type') }}</th>
                        <th class="px-4 py-3">{{ __('Subject / Message Preview') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Sent Date & Retries') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-secondary/15 transition-colors {{ in_array((string) $log->id, $selectedLogs) ? 'bg-primary/5' : '' }}">
                            <td class="px-4 py-3.5 text-center">
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedLogs"
                                    value="{{ (string) $log->id }}"
                                    class="rounded border-input text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"
                                />
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-secondary text-[11px] font-bold text-foreground">
                                        {{ $log->user ? $log->user->initials() : \Illuminate\Support\Str::substr($log->recipient_name ?: $log->recipient, 0, 2) }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-semibold text-foreground truncate max-w-[180px]">
                                            {{ $log->recipient_name ?: ($log->user?->name ?: __('Guest User')) }}
                                        </div>
                                        <div class="text-[11px] text-muted-foreground font-mono truncate max-w-[180px]" title="{{ $log->recipient }}">
                                            {{ $log->recipient }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-1.5">
                                    @if ($log->isEmail())
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-violet-500/10 text-violet-600 border border-violet-500/20">
                                            <x-icon name="mail" class="h-3 w-3"/>
                                            {{ __('Email') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-cyan-500/10 text-cyan-600 border border-cyan-500/20">
                                            <x-icon name="smartphone" class="h-3 w-3"/>
                                            {{ __('SMS') }}
                                        </span>
                                    @endif

                                    <span class="text-[10px] text-muted-foreground uppercase font-semibold">
                                        {{ str_replace('_', ' ', $log->type) }}
                                    </span>
                                </div>
                                @if ($log->template_code)
                                    <div class="text-[10px] font-mono text-muted-foreground/70 mt-0.5">
                                        {{ $log->template_code }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 max-w-sm">
                                @if ($log->subject)
                                    <div class="font-medium text-foreground text-xs truncate" title="{{ $log->subject }}">
                                        {{ $log->subject }}
                                    </div>
                                @endif
                                <p class="line-clamp-2 text-[11px] text-muted-foreground mt-0.5">
                                    {{ strip_tags($log->content) }}
                                </p>
                            </td>
                            <td class="px-4 py-3.5 text-center">
                                @if ($log->trashed())
                                    <x-ui.badge color="destructive">{{ __('Deleted') }}</x-ui.badge>
                                @elseif ($log->isDelivered())
                                    <x-ui.badge color="emerald">
                                        <x-icon name="check-circle" class="h-3 w-3"/>
                                        {{ __('Sent') }}
                                    </x-ui.badge>
                                @else
                                    <div class="inline-flex flex-col items-center">
                                        <x-ui.badge color="destructive" title="{{ $log->error_message }}">
                                            <x-icon name="alert-circle" class="h-3 w-3"/>
                                            {{ __('Failed') }}
                                        </x-ui.badge>
                                        @if ($log->error_message)
                                            <span class="text-[9px] text-rose-500 truncate max-w-[120px] mt-0.5" title="{{ $log->error_message }}">
                                                {{ $log->error_message }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                <div class="text-[11px] text-foreground font-medium">
                                    {{ $log->created_at->format('d M Y, h:i A') }}
                                </div>
                                @if ($log->resend_count > 0)
                                    <div class="inline-flex items-center gap-1 text-[10px] text-amber-600 font-medium mt-0.5">
                                        <x-icon name="rotate-ccw" class="h-2.5 w-2.5"/>
                                        {{ __('Resent :count times', ['count' => $log->resend_count]) }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($log->trashed())
                                        <button
                                            type="button"
                                            wire:click="restoreLog({{ $log->id }})"
                                            class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                                            title="{{ __('Restore Log') }}"
                                        >
                                            <x-icon name="rotate-ccw" class="h-4 w-4"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="forceDeleteLog({{ $log->id }})"
                                            wire:confirm="{{ __('Permanently delete this communication log entry?') }}"
                                            class="p-1.5 rounded-md hover:bg-rose-500/10 text-muted-foreground hover:text-rose-600 transition-colors cursor-pointer"
                                            title="{{ __('Permanent Delete') }}"
                                        >
                                            <x-icon name="trash" class="h-4 w-4"/>
                                        </button>
                                    @else
                                        {{-- Inspect Details --}}
                                        <button
                                            type="button"
                                            wire:click="viewLogDetails({{ $log->id }})"
                                            class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                                            title="{{ __('View Content & Details') }}"
                                        >
                                            <x-icon name="eye" class="h-4 w-4"/>
                                        </button>

                                        {{-- One-Click Resend --}}
                                        <button
                                            type="button"
                                            wire:click="resendLog({{ $log->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="resendLog({{ $log->id }})"
                                            class="p-1.5 rounded-md hover:bg-emerald-500/10 text-muted-foreground hover:text-emerald-600 transition-colors cursor-pointer"
                                            title="{{ __('Resend this message now') }}"
                                        >
                                            <x-icon name="rotate-ccw" class="h-4 w-4" wire:loading.remove wire:target="resendLog({{ $log->id }})"/>
                                            <x-icon name="refresh-cw" class="h-4 w-4 animate-spin text-emerald-600" wire:loading wire:target="resendLog({{ $log->id }})"/>
                                        </button>

                                        {{-- Soft Delete --}}
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $log->id }})"
                                            class="p-1.5 rounded-md hover:bg-rose-500/10 text-muted-foreground hover:text-rose-600 transition-colors cursor-pointer"
                                            title="{{ __('Delete') }}"
                                        >
                                            <x-icon name="trash-2" class="h-4 w-4"/>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-muted-foreground">
                                <x-icon name="inbox" class="h-9 w-9 mx-auto text-muted-foreground/30 mb-2"/>
                                <p class="text-sm font-semibold">{{ __('No communication logs found.') }}</p>
                                <p class="text-xs text-muted-foreground/70 mt-1">{{ __('Try adjusting your filters or search keywords.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="p-4 border-t border-border">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

    {{-- Details Inspection Modal --}}
    @if ($detailsModalOpen && $viewingLog)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/60 backdrop-blur-xs overflow-y-auto" wire:keydown.escape="$set('detailsModalOpen', false)">
            <div class="fixed inset-0" wire:click="$set('detailsModalOpen', false)"></div>
            <div class="relative w-full max-w-3xl bg-card border border-border rounded-2xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 max-h-[88vh] flex flex-col my-auto z-10">
                {{-- Modal Header --}}
                <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-border bg-secondary/30 shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl {{ $viewingLog->isEmail() ? 'bg-violet-500/15 text-violet-600' : 'bg-cyan-500/15 text-cyan-600' }}">
                            <x-icon :name="$viewingLog->isEmail() ? 'mail' : 'smartphone'" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h2 class="text-sm sm:text-base font-bold text-foreground flex items-center gap-2">
                                <span>{{ __('Delivery Log Details') }}</span>
                                <span class="font-mono text-xs px-2 py-0.5 rounded-md bg-secondary text-muted-foreground">#{{ $viewingLog->id }}</span>
                            </h2>
                            <p class="text-[11px] text-muted-foreground mt-0.5">
                                <span class="font-bold uppercase tracking-wider">{{ $viewingLog->channel }}</span> &bull; {{ $viewingLog->created_at->format('d M Y, h:i:s A') }} ({{ $viewingLog->created_at->diffForHumans() }})
                            </p>
                        </div>
                    </div>

                    <button type="button" wire:click="$set('detailsModalOpen', false)" class="h-8 w-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer" aria-label="{{ __('Close') }}">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                {{-- Modal Body --}}
                <div class="p-5 sm:p-6 overflow-y-auto space-y-4 flex-1 text-xs">
                    {{-- Status Banner --}}
                    @if ($viewingLog->isDelivered())
                        <div class="flex items-center justify-between p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-400">
                            <div class="flex items-center gap-2.5">
                                <x-icon name="check-circle-2" class="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400"/>
                                <div>
                                    <span class="font-bold text-xs sm:text-sm block">{{ __('Successfully Dispatched & Delivered') }}</span>
                                    <p class="text-[11px] opacity-90">{{ __('Message was successfully delivered via the configured gateway.') }}</p>
                                </div>
                            </div>
                            @if ($viewingLog->delivered_at)
                                <span class="text-[11px] font-mono px-2 py-1 rounded bg-emerald-500/10 shrink-0">{{ $viewingLog->delivered_at->format('h:i A') }}</span>
                            @endif
                        </div>
                    @else
                        <div class="p-3.5 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive space-y-1.5">
                            <div class="flex items-center gap-2">
                                <x-icon name="alert-triangle" class="h-5 w-5 shrink-0 text-destructive"/>
                                <span class="font-bold text-xs sm:text-sm">{{ __('Delivery Failure Reason') }}</span>
                            </div>
                            <p class="font-mono text-[11px] bg-background/80 p-2.5 rounded-lg border border-destructive/20 break-words leading-relaxed">
                                {{ $viewingLog->error_message ?: __('Unknown gateway failure occurred during message transmission.') }}
                            </p>
                        </div>
                    @endif

                    {{-- Recipient & Sender Metadata Grid --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3.5 rounded-xl bg-secondary/30 border border-border">
                        <div class="space-y-0.5">
                            <span class="text-[10px] font-bold uppercase text-muted-foreground tracking-wider">{{ __('Recipient Name') }}</span>
                            <div class="font-semibold text-foreground text-xs">{{ $viewingLog->recipient_name ?: ($viewingLog->user?->name ?: __('Guest Contact')) }}</div>
                        </div>
                        <div class="space-y-0.5">
                            <span class="text-[10px] font-bold uppercase text-muted-foreground tracking-wider">{{ __('Destination Address / Phone') }}</span>
                            <div class="font-mono text-foreground text-xs break-all">{{ $viewingLog->recipient }}</div>
                        </div>
                        <div class="space-y-0.5">
                            <span class="text-[10px] font-bold uppercase text-muted-foreground tracking-wider">{{ __('Template Code') }}</span>
                            <div class="font-mono text-primary font-bold text-xs">{{ $viewingLog->template_code ?: __('Custom Ad-hoc Message') }}</div>
                        </div>
                        <div class="space-y-0.5">
                            <span class="text-[10px] font-bold uppercase text-muted-foreground tracking-wider">{{ __('Dispatched By') }}</span>
                            <div class="text-foreground text-xs">{{ $viewingLog->sender?->name ?? __('System Background Service') }}</div>
                        </div>
                    </div>

                    {{-- Rendered Content Preview --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">{{ __('Rendered Message Payload') }}</span>
                            @if ($viewingLog->subject)
                                <span class="text-[11px] text-foreground truncate max-w-[280px]">
                                    <strong class="text-muted-foreground">{{ __('Subject:') }}</strong> {{ $viewingLog->subject }}
                                </span>
                            @endif
                        </div>

                        @if ($viewingLog->isEmail())
                            <div class="rounded-xl border border-border bg-white dark:bg-zinc-950 p-4 text-zinc-900 dark:text-zinc-100 overflow-x-auto shadow-inner max-h-72 leading-relaxed text-xs break-words">
                                {!! $viewingLog->content !!}
                            </div>
                        @else
                            <div class="rounded-xl bg-secondary/70 border border-border p-4 text-foreground shadow-xs font-mono text-xs leading-relaxed whitespace-pre-wrap break-words">
                                {{ $viewingLog->content }}
                            </div>
                        @endif
                    </div>

                    {{-- Variables Injected --}}
                    @if (! empty($viewingLog->variables))
                        <div class="space-y-1.5">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">{{ __('Interpolated Variable Data') }}</span>
                            <div class="flex flex-wrap gap-1.5 max-h-28 overflow-y-auto p-2 rounded-lg bg-secondary/20 border border-border">
                                @foreach ($viewingLog->variables as $vKey => $vVal)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-card text-[10px] font-mono border border-border">
                                        <strong class="text-primary">{!! e('{'.$vKey.'}') !!}:</strong>
                                        <span class="text-foreground truncate max-w-[200px]">{{ is_scalar($vVal) ? $vVal : json_encode($vVal) }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Modal Footer --}}
                <div class="flex items-center justify-between px-5 sm:px-6 py-3.5 border-t border-border bg-secondary/30 shrink-0">
                    <div class="text-[11px] text-muted-foreground font-medium">
                        {{ __('Resend Count: :count', ['count' => $viewingLog->resend_count]) }}
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button type="button" variant="outline" size="sm" wire:click="$set('detailsModalOpen', false)">
                            {{ __('Close') }}
                        </x-ui.button>

                        <x-ui.button
                            type="button"
                            variant="default"
                            size="sm"
                            wire:click="resendLog({{ $viewingLog->id }})"
                            wire:loading.attr="disabled"
                            wire:target="resendLog({{ $viewingLog->id }})"
                        >
                            <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1.5" wire:loading.remove wire:target="resendLog({{ $viewingLog->id }})"/>
                            <x-icon name="refresh-cw" class="h-3.5 w-3.5 mr-1.5 animate-spin" wire:loading wire:target="resendLog({{ $viewingLog->id }})"/>
                            {{ __('Resend Now') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Single Delete Confirmation Modal --}}
    <x-ui.modal name="delete-log-modal" max-width="max-w-md" :title="__('Delete Message Log')" :description="__('Move this communication delivery log record to trash.')">
        <div class="space-y-4 pt-1">
            <div class="p-4 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs space-y-2">
                <div class="font-bold flex items-center gap-2">
                    <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                    <span>{{ __('Confirm Log Deletion') }}</span>
                </div>
                <p class="text-[11px] opacity-90 leading-relaxed">
                    {{ __('Are you sure you want to move log #:id to trash? Trashed logs can be reviewed or restored from the Trash view.', ['id' => $deletingLogId]) }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('delete-log-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" size="sm" type="button" wire:click="executeDelete">
                    <x-icon name="trash-2" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Delete Log') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Bulk Delete Confirmation Modal --}}
    <x-ui.modal name="bulk-delete-log-modal" max-width="max-w-md" :title="__('Bulk Delete Message Logs')" :description="__('Move selected communication logs to trash.')">
        <div class="space-y-4 pt-1">
            <div class="p-4 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs space-y-2">
                <div class="font-bold flex items-center gap-2">
                    <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                    <span>{{ __('Confirm Bulk Deletion') }}</span>
                </div>
                <p class="text-[11px] opacity-90 leading-relaxed">
                    {{ __('Are you sure you want to move :count selected log entries to trash?', ['count' => count($selectedLogs)]) }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('bulk-delete-log-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" size="sm" type="button" wire:click="executeBulkDelete">
                    <x-icon name="trash-2" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Delete Selected Logs') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
