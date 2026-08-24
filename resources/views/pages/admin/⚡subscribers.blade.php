<?php

use App\Exports\TableExport;
use App\Models\Subscriber;
use App\Services\SubscriberService;
use App\Support\Toast;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new #[Layout('layouts.app')] #[Title('Newsletter & WhatsApp Subscribers')] class extends Component {
    use WithFileUploads, WithPagination;

    public string $viewTab = 'all'; // all, email, whatsapp, active, unsubscribed, trash
    public string $search = '';
    public string $statusFilter = 'all';
    public string $sourceFilter = 'all';
    public string $tagFilter = 'all';
    public int $perPage = 15;

    // Bulk selection
    public array $selectedIds = [];
    public bool $selectAll = false;
    public string $bulkActionType = '';
    public string $bulkActionValue = '';

    // Create / Edit Form
    public ?int $editingSubscriberId = null;
    public array $subscriberForm = [
        'type' => 'email',
        'email' => '',
        'country_code' => '+91',
        'phone' => '',
        'name' => '',
        'status' => 'active',
        'source' => 'admin_manual',
        'tags_text' => 'newsletter',
    ];

    // Single item delete/restore target
    public ?int $targetSubscriberId = null;
    public ?string $targetSubscriberName = null;
    public ?string $targetSubscriberContact = null;

    // CSV Import
    public $importFile = null;
    public string $importSource = 'import';
    public string $importTags = 'imported';
    public array $importResults = [];

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedIds = $this->getSubscribersQuery()->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        } else {
            $this->selectedIds = [];
        }
    }

    public function updatedViewTab(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function openCreateModal(): void
    {
        $this->editingSubscriberId = null;
        $this->subscriberForm = [
            'type' => 'email',
            'email' => '',
            'country_code' => '+91',
            'phone' => '',
            'name' => '',
            'status' => 'active',
            'source' => 'admin_manual',
            'tags_text' => 'newsletter, manual',
        ];
        $this->dispatch('modal-open', name: 'subscriber-form-modal');
    }

    public function openEditModal(int $id): void
    {
        $this->editingSubscriberId = $id;
        $sub = Subscriber::withTrashed()->findOrFail($id);
        $this->subscriberForm = [
            'type' => $sub->type,
            'email' => $sub->email ?? '',
            'country_code' => $sub->country_code ?? '+91',
            'phone' => $sub->phone ?? '',
            'name' => $sub->name ?? '',
            'status' => $sub->status,
            'source' => $sub->source,
            'tags_text' => implode(', ', $sub->tags ?? []),
        ];
        $this->dispatch('modal-open', name: 'subscriber-form-modal');
    }

    public function saveSubscriber(SubscriberService $service): void
    {
        $this->validate([
            'subscriberForm.type' => ['required', 'in:email,whatsapp,both'],
            'subscriberForm.email' => ['nullable', 'email', 'max:255'],
            'subscriberForm.phone' => ['nullable', 'string', 'max:30'],
            'subscriberForm.country_code' => ['required', 'string', 'max:10'],
            'subscriberForm.name' => ['nullable', 'string', 'max:100'],
            'subscriberForm.status' => ['required', 'in:active,unsubscribed,bounced,pending_verification'],
            'subscriberForm.source' => ['required', 'string', 'max:50'],
        ]);

        $tags = array_filter(array_map('trim', explode(',', $this->subscriberForm['tags_text'])));

        if ($this->editingSubscriberId) {
            $sub = Subscriber::withTrashed()->findOrFail($this->editingSubscriberId);
            $service->updateSubscriber($sub, [
                'type' => $this->subscriberForm['type'],
                'email' => $this->subscriberForm['email'],
                'country_code' => $this->subscriberForm['country_code'],
                'phone' => $this->subscriberForm['phone'],
                'name' => $this->subscriberForm['name'],
                'status' => $this->subscriberForm['status'],
                'tags' => $tags,
            ], auth()->user());

            Toast::dispatch($this, 'success', __('Subscriber details updated successfully.'));
        } else {
            $service->subscribe([
                'type' => $this->subscriberForm['type'],
                'email' => $this->subscriberForm['email'],
                'country_code' => $this->subscriberForm['country_code'],
                'phone' => $this->subscriberForm['phone'],
                'name' => $this->subscriberForm['name'],
                'source' => $this->subscriberForm['source'],
                'tags' => $tags,
            ], auth()->user());

            Toast::dispatch($this, 'success', __('New subscriber created successfully.'));
        }

        $this->dispatch('modal-close', name: 'subscriber-form-modal');
    }

    public function confirmDelete(int $id): void
    {
        $sub = Subscriber::withTrashed()->findOrFail($id);
        $this->targetSubscriberId = $sub->id;
        $this->targetSubscriberName = $sub->name ?: __('Guest');
        $this->targetSubscriberContact = $sub->email ?: $sub->formattedPhone();
        $this->dispatch('modal-open', name: 'subscriber-delete-modal');
    }

    public function executeDelete(SubscriberService $service, bool $force = false): void
    {
        if (! $this->targetSubscriberId) {
            return;
        }

        $sub = Subscriber::withTrashed()->findOrFail($this->targetSubscriberId);
        $service->deleteSubscriber($sub, auth()->user(), $force);

        $this->targetSubscriberId = null;
        $this->dispatch('modal-close', name: 'subscriber-delete-modal');
        Toast::dispatch($this, 'success', $force ? __('Subscriber permanently removed.') : __('Subscriber moved to trash.'));
    }

    public function confirmRestore(int $id): void
    {
        $sub = Subscriber::onlyTrashed()->findOrFail($id);
        $this->targetSubscriberId = $sub->id;
        $this->targetSubscriberName = $sub->name ?: __('Guest');
        $this->targetSubscriberContact = $sub->email ?: $sub->formattedPhone();
        $this->dispatch('modal-open', name: 'subscriber-restore-modal');
    }

    public function executeRestore(SubscriberService $service): void
    {
        if (! $this->targetSubscriberId) {
            return;
        }

        $service->restoreSubscriber($this->targetSubscriberId, auth()->user());

        $this->targetSubscriberId = null;
        $this->dispatch('modal-close', name: 'subscriber-restore-modal');
        Toast::dispatch($this, 'success', __('Subscriber restored successfully.'));
    }

    public function openBulkModal(string $action): void
    {
        $this->bulkActionType = $action;
        $this->bulkActionValue = '';
        $this->dispatch('modal-open', name: 'subscriber-bulk-modal');
    }

    public function executeBulkAction(SubscriberService $service): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $count = $service->bulkAction($this->bulkActionType, $this->selectedIds, $this->bulkActionValue, auth()->user());

        $this->selectedIds = [];
        $this->selectAll = false;
        $this->dispatch('modal-close', name: 'subscriber-bulk-modal');
        Toast::dispatch($this, 'success', __("Applied bulk action across :count subscriber(s).", ['count' => $count]));
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importResults = [];
        $this->dispatch('modal-open', name: 'subscriber-import-modal');
    }

    public function processImport(SubscriberService $service): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], // max 5MB
        ]);

        $tags = array_filter(array_map('trim', explode(',', $this->importTags)));
        $res = $service->importCsv($this->importFile, $this->importSource, $tags, auth()->user());

        $this->importResults = $res;
        Toast::dispatch($this, 'success', __("Import completed: :imported added/updated, :skipped skipped.", ['imported' => $res['imported'], 'skipped' => $res['skipped']]));
    }

    public function export(string $format = 'csv')
    {
        $subscribers = $this->getSubscribersQuery()->get();

        $export = new TableExport(
            items: $subscribers,
            headings: ['ID', 'Channel Type', 'Name', 'Email', 'Phone', 'Status', 'Source', 'Tags', 'Subscribed At', 'Unsubscribed At'],
            mapRow: fn (Subscriber $s) => [
                $s->id,
                $s->typeLabel(),
                $s->name ?? '',
                $s->email ?? '',
                $s->formattedPhone() ?? '',
                $s->statusLabel(),
                $s->source,
                implode(', ', $s->tags ?? []),
                $s->subscribed_at?->format('Y-m-d H:i:s') ?? '',
                $s->unsubscribed_at?->format('Y-m-d H:i:s') ?? '',
            ]
        );

        return Excel::download($export, "subscribers-list-".now()->format('Y-m-d').".{$format}");
    }

    protected function getSubscribersQuery()
    {
        return Subscriber::query()
            ->when($this->viewTab === 'trash', fn ($q) => $q->onlyTrashed(), fn ($q) => $q->withoutTrashed())
            ->when($this->viewTab === 'email', fn ($q) => $q->email())
            ->when($this->viewTab === 'whatsapp', fn ($q) => $q->whatsapp())
            ->when($this->viewTab === 'active', fn ($q) => $q->active())
            ->when($this->viewTab === 'unsubscribed', fn ($q) => $q->unsubscribed())
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('source', 'like', $term);
                });
            })
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->sourceFilter !== 'all', fn ($q) => $q->where('source', $this->sourceFilter))
            ->latest('id');
    }

    public function with(): array
    {
        $totalAll = Subscriber::count();
        $totalEmail = Subscriber::email()->active()->count();
        $totalWhatsapp = Subscriber::whatsapp()->active()->count();
        $totalUnsubscribed = Subscriber::unsubscribed()->count();
        $newThisMonth = Subscriber::where('subscribed_at', '>=', now()->startOfMonth())->count();
        $churnRate = $totalAll > 0 ? round(($totalUnsubscribed / $totalAll) * 100, 1) : 0.0;

        return [
            'subscribers' => $this->getSubscribersQuery()->paginate($this->perPage),
            'metrics' => [
                'total' => $totalAll,
                'email_active' => $totalEmail,
                'whatsapp_active' => $totalWhatsapp,
                'unsubscribed' => $totalUnsubscribed,
                'churn_rate' => $churnRate,
                'new_this_month' => $newThisMonth,
            ],
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Top Header & Actions --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <x-icon name="mail" class="h-5 w-5"/>
                </span>
                {{ __('Newsletter & WhatsApp Subscribers') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Manage email subscribers, WhatsApp alert contact lists, bulk CSV imports, tag segmentation, and compliance.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="outline" size="sm" wire:click="openImportModal">
                <x-icon name="upload" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Import CSV') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" wire:click="export('csv')">
                <x-icon name="download" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Export List') }}
            </x-ui.button>

            <x-ui.button variant="default" size="sm" wire:click="openCreateModal">
                <x-icon name="plus" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Add Subscriber') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Metrics Cards Grid --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5">
        {{-- Total Registered --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('Total Leads') }}</span>
                <span class="p-1.5 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="users" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['total'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('All captured contacts') }}</div>
        </div>

        {{-- Active Email List --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('Active Email') }}</span>
                <span class="p-1.5 rounded-lg bg-violet-500/10 text-violet-600">
                    <x-icon name="mail" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['email_active'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Deliverable newsletter emails') }}</div>
        </div>

        {{-- Active WhatsApp List --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('Active WhatsApp') }}</span>
                <span class="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-icon name="smartphone" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['whatsapp_active'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Instant mobile alert contacts') }}</div>
        </div>

        {{-- Churn / Unsubscribed --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('Unsubscribed') }}</span>
                <span class="p-1.5 rounded-lg bg-zinc-500/10 text-zinc-600">
                    <x-icon name="user-minus" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['unsubscribed'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ $metrics['churn_rate'] }}% {{ __('churn rate') }}</div>
        </div>

        {{-- New This Month --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('This Month') }}</span>
                <span class="p-1.5 rounded-lg bg-blue-500/10 text-blue-600">
                    <x-icon name="trending-up" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">+{{ $metrics['new_this_month'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Growth since 1st') }}</div>
        </div>
    </div>

    {{-- Filter Bar & Segment Tabs --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            {{-- Segment Tabs --}}
            <div class="flex items-center gap-1 bg-secondary/50 p-1 rounded-lg overflow-x-auto">
                <button
                    type="button"
                    wire:click="$set('viewTab', 'all')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors shrink-0 {{ $viewTab === 'all' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('All') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('viewTab', 'email')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors shrink-0 flex items-center gap-1.5 {{ $viewTab === 'email' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="mail" class="h-3 w-3 text-violet-500"/>
                    <span>{{ __('Email List') }}</span>
                </button>
                <button
                    type="button"
                    wire:click="$set('viewTab', 'whatsapp')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors shrink-0 flex items-center gap-1.5 {{ $viewTab === 'whatsapp' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="smartphone" class="h-3 w-3 text-emerald-500"/>
                    <span>{{ __('WhatsApp List') }}</span>
                </button>
                <button
                    type="button"
                    wire:click="$set('viewTab', 'active')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors shrink-0 {{ $viewTab === 'active' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('Active') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('viewTab', 'unsubscribed')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors shrink-0 {{ $viewTab === 'unsubscribed' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('Unsubscribed') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('viewTab', 'trash')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors shrink-0 text-destructive {{ $viewTab === 'trash' ? 'bg-background shadow-2xs font-semibold' : 'opacity-70 hover:opacity-100' }}"
                >
                    {{ __('Trash') }}
                </button>
            </div>

            {{-- Search & Filters --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-56">
                    <x-icon name="search" class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground"/>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="{{ __('Search email, phone, name...') }}"
                        class="w-full pl-8 pr-3 py-1.5 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground"
                    />
                </div>

                <select wire:model.live="statusFilter" class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border text-foreground">
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="unsubscribed">{{ __('Unsubscribed') }}</option>
                    <option value="bounced">{{ __('Bounced') }}</option>
                </select>

                <select wire:model.live="sourceFilter" class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border text-foreground">
                    <option value="all">{{ __('All Sources') }}</option>
                    <option value="welcome_page">{{ __('Welcome Page') }}</option>
                    <option value="admin_manual">{{ __('Admin Manual') }}</option>
                    <option value="import">{{ __('CSV Import') }}</option>
                </select>
            </div>
        </div>

        {{-- Bulk Selection Bar --}}
        @if (! empty($selectedIds))
            <div class="flex items-center justify-between p-2.5 rounded-lg bg-primary/10 border border-primary/20">
                <div class="flex items-center gap-2">
                    <x-icon name="check-square" class="h-4 w-4 text-primary"/>
                    <span class="text-xs font-semibold text-primary">{{ count($selectedIds) }} {{ __('subscribers selected') }}</span>
                </div>

                <div class="flex items-center gap-2">
                    @if ($viewTab === 'trash')
                        <button type="button" wire:click="openBulkModal('restore')" class="text-xs font-semibold text-primary hover:underline px-2 py-1 rounded bg-background">
                            {{ __('Restore Selected') }}
                        </button>
                        <button type="button" wire:click="openBulkModal('force_delete')" class="text-xs font-semibold text-destructive hover:underline px-2 py-1 rounded bg-background">
                            {{ __('Permanently Purge') }}
                        </button>
                    @else
                        <button type="button" wire:click="openBulkModal('status')" class="text-xs font-medium text-foreground hover:underline px-2 py-1 rounded bg-background border border-border">
                            {{ __('Set Status') }}
                        </button>
                        <button type="button" wire:click="openBulkModal('tag_add')" class="text-xs font-medium text-foreground hover:underline px-2 py-1 rounded bg-background border border-border">
                            {{ __('Add Tag') }}
                        </button>
                        <button type="button" wire:click="openBulkModal('delete')" class="text-xs font-medium text-destructive hover:underline px-2 py-1 rounded bg-background border border-destructive/30">
                            {{ __('Delete') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif

        {{-- Table Listing --}}
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase text-[10px] tracking-wider border-b border-border">
                    <tr>
                        <th class="p-3 w-8 text-center">
                            <input
                                type="checkbox"
                                wire:model.live="selectAll"
                                class="rounded border-border text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"
                            />
                        </th>
                        <th class="p-3">{{ __('Subscriber Contact') }}</th>
                        <th class="p-3">{{ __('Channel Type') }}</th>
                        <th class="p-3">{{ __('Status') }}</th>
                        <th class="p-3">{{ __('Tags') }}</th>
                        <th class="p-3">{{ __('Source') }}</th>
                        <th class="p-3">{{ __('Subscribed Date') }}</th>
                        <th class="p-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($subscribers as $sub)
                        <tr wire:key="subscriber-row-{{ $sub->id }}" class="hover:bg-secondary/15 transition-colors">
                            <td class="p-3 text-center">
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedIds"
                                    value="{{ $sub->id }}"
                                    class="rounded border-border text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"
                                />
                            </td>
                            <td class="p-3">
                                <div class="font-semibold text-foreground">
                                    {{ $sub->name ?: __('Guest Subscriber') }}
                                </div>
                                <div class="flex flex-col gap-0.5 mt-0.5 font-mono text-[11px] text-muted-foreground">
                                    @if ($sub->email)
                                        <span class="flex items-center gap-1">
                                            <x-icon name="mail" class="h-3 w-3 text-violet-500"/>
                                            <span>{{ $sub->email }}</span>
                                        </span>
                                    @endif
                                    @if ($sub->phone)
                                        <span class="flex items-center gap-1">
                                            <x-icon name="smartphone" class="h-3 w-3 text-emerald-500"/>
                                            <span>{{ $sub->formattedPhone() }}</span>
                                            @if ($sub->phone)
                                                <a href="https://wa.me/{{ preg_replace('/[^\d]/', '', $sub->phone) }}" target="_blank" class="text-emerald-600 hover:underline text-[9px]">
                                                    {{ __('Chat') }}
                                                </a>
                                            @endif
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$sub->typeBadgeColor()" class="text-[10px]">
                                    {{ $sub->typeLabel() }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$sub->statusBadgeColor()" class="text-[10px]">
                                    {{ $sub->statusLabel() }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3">
                                <div class="flex flex-wrap gap-1 max-w-[180px]">
                                    @forelse ($sub->tags ?? [] as $tag)
                                        <span class="px-1.5 py-0.5 rounded bg-secondary text-[10px] text-foreground font-mono">
                                            #{{ $tag }}
                                        </span>
                                    @empty
                                        <span class="text-muted-foreground text-[10px]">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="p-3">
                                <span class="capitalize text-muted-foreground font-medium text-[11px]">
                                    {{ str_replace('_', ' ', $sub->source) }}
                                </span>
                            </td>
                            <td class="p-3 text-muted-foreground">
                                <div>{{ $sub->subscribed_at?->format('d M Y') ?? $sub->created_at->format('d M Y') }}</div>
                                <div class="text-[10px]">{{ $sub->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="p-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($sub->trashed())
                                        <button
                                            type="button"
                                            wire:click="confirmRestore({{ $sub->id }})"
                                            class="p-1.5 rounded-md text-primary hover:bg-primary/10 transition-colors cursor-pointer"
                                            title="{{ __('Restore Subscriber') }}"
                                        >
                                            <x-icon name="rotate-ccw" class="h-3.5 w-3.5"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $sub->id }})"
                                            class="p-1.5 rounded-md text-destructive hover:bg-destructive/10 transition-colors cursor-pointer"
                                            title="{{ __('Purge Permanently') }}"
                                        >
                                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="openEditModal({{ $sub->id }})"
                                            class="p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Edit Subscriber') }}"
                                        >
                                            <x-icon name="edit-2" class="h-3.5 w-3.5"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $sub->id }})"
                                            class="p-1.5 rounded-md text-destructive hover:bg-destructive/10 transition-colors cursor-pointer"
                                            title="{{ __('Delete') }}"
                                        >
                                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-12 text-center text-muted-foreground">
                                <x-icon name="mail" class="mx-auto h-8 w-8 mb-2 opacity-40"/>
                                <p class="text-sm font-medium">{{ __('No subscribers found') }}</p>
                                <p class="text-xs mt-1">{{ __('Try adjusting your filters or import subscribers from a CSV file.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($subscribers->hasPages())
            <div class="pt-2 border-t border-border">
                {{ $subscribers->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL 1: Create / Edit Subscriber Form Modal --}}
    <x-ui.modal name="subscriber-form-modal" max-width="max-w-xl" :title="$editingSubscriberId ? __('Edit Subscriber Contact') : __('Add New Subscriber')" :description="__('Configure contact channels, communication preferences, and tag segmentation.')">
        <form wire:submit="saveSubscriber" class="space-y-5 pt-1">
            {{-- Channel Type --}}
            <div class="space-y-1.5">
                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block">
                    {{ __('Subscription Channels') }} <span class="text-destructive">*</span>
                </label>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (['email' => ['label' => __('Email Only'), 'icon' => 'mail'], 'whatsapp' => ['label' => __('WhatsApp Only'), 'icon' => 'smartphone'], 'both' => ['label' => __('Both Channels'), 'icon' => 'layers']] as $cType => $cMeta)
                        <label class="relative flex items-center gap-2 p-2.5 rounded-xl border cursor-pointer transition-all {{ $subscriberForm['type'] === $cType ? 'border-primary bg-primary/10 ring-2 ring-primary/20 text-foreground font-semibold' : 'border-border bg-background text-muted-foreground hover:bg-secondary/40' }}">
                            <input type="radio" wire:model.live="subscriberForm.type" value="{{ $cType }}" class="sr-only"/>
                            <x-icon :name="$cMeta['icon']" class="h-4 w-4 text-primary shrink-0"/>
                            <span class="text-xs">{{ $cMeta['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Name --}}
            <x-ui.input
                wire:model="subscriberForm.name"
                :label="__('Contact Name (Optional)')"
                placeholder="e.g. Rahul Sen"
            />

            {{-- Email & Phone Inputs --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-ui.input
                        wire:model="subscriberForm.email"
                        type="email"
                        :label="__('Email Address')"
                        placeholder="subscriber@example.com"
                    />
                </div>

                <div class="space-y-1.5">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block">
                        {{ __('WhatsApp / Mobile Number') }}
                    </label>
                    <div class="flex gap-2">
                        <select wire:model="subscriberForm.country_code" class="py-2 px-2 text-xs bg-background rounded-lg border border-border text-foreground font-mono w-20 shrink-0">
                            <option value="+91">+91</option>
                            <option value="+1">+1</option>
                            <option value="+44">+44</option>
                            <option value="+971">+971</option>
                            <option value="+880">+880</option>
                        </select>
                        <input
                            type="tel"
                            wire:model="subscriberForm.phone"
                            placeholder="9876543210"
                            class="w-full px-3.5 py-2 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground font-mono"
                        />
                    </div>
                    @error('subscriberForm.phone') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Status & Tags --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.select
                    wire:model="subscriberForm.status"
                    :label="__('Subscription Status') . ' *'"
                    :options="[
                        'active' => __('Active'),
                        'unsubscribed' => __('Unsubscribed'),
                        'bounced' => __('Bounced / Invalid'),
                        'pending_verification' => __('Pending Verification'),
                    ]"
                />

                <x-ui.input
                    wire:model="subscriberForm.tags_text"
                    :label="__('Tags (Comma-separated)')"
                    placeholder="newsletter, admissions, upsc"
                />
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('subscriber-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" size="sm" type="submit">
                    <x-icon name="check" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Save Subscriber') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- MODAL 2: Delete Confirmation Modal (Replaces browser confirm) --}}
    <x-ui.modal name="subscriber-delete-modal" max-width="max-w-md" :title="__('Delete Subscriber')" :description="__('Please confirm before deleting this contact record.')">
        <div class="space-y-4 pt-1">
            <div class="p-4 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs space-y-2">
                <div class="font-bold flex items-center gap-2">
                    <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                    <span>{{ __('Confirm Deletion') }}</span>
                </div>
                <p class="text-[11px] opacity-90 leading-relaxed">
                    {{ __('Are you sure you want to delete :name (:contact)?', ['name' => $targetSubscriberName, 'contact' => $targetSubscriberContact]) }}
                </p>
                <p class="text-[10px] text-muted-foreground">
                    {{ __('This contact will be moved to trash and can be restored anytime.') }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('subscriber-delete-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" size="sm" type="button" wire:click="executeDelete">
                    <x-icon name="trash" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Yes, Delete Subscriber') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- MODAL 3: Restore Confirmation Modal --}}
    <x-ui.modal name="subscriber-restore-modal" max-width="max-w-md" :title="__('Restore Subscriber')" :description="__('Restore contact from trash to active subscribers list.')">
        <div class="space-y-4 pt-1">
            <p class="text-xs text-foreground">
                {{ __('Restore subscriber :name (:contact) back to active marketing lists?', ['name' => $targetSubscriberName, 'contact' => $targetSubscriberContact]) }}
            </p>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('subscriber-restore-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" size="sm" type="button" wire:click="executeRestore">
                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Restore Contact') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- MODAL 4: CSV Import Modal --}}
    <x-ui.modal name="subscriber-import-modal" max-width="max-w-xl" :title="__('Bulk CSV Import')" :description="__('Upload a CSV list of emails and WhatsApp mobile numbers with automatic deduplication.')">
        <form wire:submit="processImport" class="space-y-5 pt-1">
            <div class="space-y-1.5 p-4 rounded-xl border border-dashed border-border bg-secondary/20 text-center">
                <x-icon name="upload" class="h-8 w-8 text-primary mx-auto opacity-70 mb-2"/>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,.txt"
                    class="block w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />
                <p class="text-[11px] text-muted-foreground mt-2">
                    {{ __('CSV must contain column headers: email, phone, name, tags.') }}
                </p>
                @error('importFile') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input
                    wire:model="importTags"
                    :label="__('Default Tags for Imported Contacts')"
                    placeholder="imported_batch_1, upsc_2026"
                />

                <x-ui.select
                    wire:model="importSource"
                    :label="__('Attributed Source')"
                    :options="[
                        'import' => __('Bulk CSV Import'),
                        'offline_campaign' => __('Offline Seminar / Camp'),
                        'partner' => __('Educational Partner'),
                    ]"
                />
            </div>

            {{-- Import Results Preview --}}
            @if (! empty($importResults))
                <div class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-xs space-y-1">
                    <span class="font-bold text-emerald-600 dark:text-emerald-400">
                        {{ __('Imported :count records (:skipped skipped)', ['count' => $importResults['imported'], 'skipped' => $importResults['skipped']]) }}
                    </span>
                    @if (! empty($importResults['errors']))
                        <div class="text-[11px] text-destructive space-y-0.5 pt-1">
                            @foreach ($importResults['errors'] as $err)
                                <div>&bull; {{ $err }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('subscriber-import-modal')">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button variant="default" size="sm" type="submit" wire:loading.attr="disabled">
                    <x-icon name="check" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Start Import') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- MODAL 5: Bulk Actions Modal --}}
    <x-ui.modal name="subscriber-bulk-modal" max-width="max-w-md" :title="__('Bulk Action on Subscribers')" :description="__('Apply bulk updates across :count selected contacts.', ['count' => count($selectedIds)])">
        <form wire:submit="executeBulkAction" class="space-y-4 pt-1">
            @if ($bulkActionType === 'status')
                <x-ui.select
                    wire:model="bulkActionValue"
                    :label="__('Select New Status') . ' *'"
                    :options="[
                        'active' => __('Active'),
                        'unsubscribed' => __('Unsubscribed'),
                        'bounced' => __('Bounced / Invalid'),
                    ]"
                />
            @elseif ($bulkActionType === 'tag_add')
                <x-ui.input
                    wire:model="bulkActionValue"
                    :label="__('Tag to Append') . ' *'"
                    placeholder="e.g. batch_2026"
                />
            @elseif ($bulkActionType === 'delete')
                <div class="p-3 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive text-xs space-y-1">
                    <div class="font-bold flex items-center gap-1.5">
                        <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                        <span>{{ __('Confirm Bulk Deletion') }}</span>
                    </div>
                    <p class="text-[11px] opacity-90">
                        {{ __('Are you sure you want to move :count selected contacts to trash?', ['count' => count($selectedIds)]) }}
                    </p>
                </div>
            @elseif ($bulkActionType === 'force_delete')
                <div class="p-3 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive text-xs space-y-1">
                    <div class="font-bold flex items-center gap-1.5">
                        <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                        <span>{{ __('Permanently Purge Records') }}</span>
                    </div>
                    <p class="text-[11px] opacity-90 font-bold">
                        {{ __('This action CANNOT be undone. Permanently purge :count selected contacts?', ['count' => count($selectedIds)]) }}
                    </p>
                </div>
            @endif

            <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('subscriber-bulk-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button :variant="in_array($bulkActionType, ['delete', 'force_delete'], true) ? 'destructive' : 'default'" size="sm" type="submit">
                    {{ __('Apply Action') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>