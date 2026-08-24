<?php

use App\Exports\TableExport;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Toast;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new #[Layout('layouts.app')] #[Title('Support Tickets Helpdesk')] class extends Component {
    use WithPagination;

    public string $viewMode = 'table'; // table, kanban
    public string $search = '';
    public string $statusFilter = 'all';
    public string $priorityFilter = 'all';
    public string $categoryFilter = 'all';
    public string $assigneeFilter = 'all';
    public string $slaFilter = 'all'; // all, breached, due_today
    public int $perPage = 15;

    // Bulk selection
    public array $selectedIds = [];
    public bool $selectAll = false;

    // Bulk Action Modal
    public string $bulkActionType = '';
    public string $bulkActionValue = '';

    // Create on behalf of user
    public array $createForm = [
        'user_id' => null,
        'guest_name' => '',
        'guest_email' => '',
        'guest_phone' => '',
        'category_id' => null,
        'priority' => Ticket::PRIORITY_MEDIUM,
        'subject' => '',
        'description' => '',
    ];

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedIds = $this->getTicketsQuery()->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        } else {
            $this->selectedIds = [];
        }
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

    public function updatedPriorityFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatedAssigneeFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function openBulkModal(string $action): void
    {
        $this->bulkActionType = $action;
        $this->bulkActionValue = '';
        $this->dispatch('modal-open', name: 'ticket-bulk-modal');
    }

    public function executeBulkAction(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        /** @var TicketService $service */
        $service = app(TicketService::class);

        $count = $service->bulkAction($this->bulkActionType, $this->selectedIds, $this->bulkActionValue, auth()->user());

        $this->selectedIds = [];
        $this->selectAll = false;
        $this->dispatch('modal-close', name: 'ticket-bulk-modal');

        Toast::dispatch($this, 'success', __("Successfully updated :count support ticket(s).", ['count' => $count]));
    }

    public function triggerSlaCheck(): void
    {
        /** @var TicketService $service */
        $service = app(TicketService::class);
        $breaches = $service->checkSlaBreaches();

        Toast::dispatch($this, 'info', __("SLA evaluation complete. Identified :count breach warning(s).", ['count' => $breaches]));
    }

    public function openCreateModal(): void
    {
        $firstCat = TicketCategory::active()->ordered()->first();
        $this->createForm = [
            'user_id' => null,
            'guest_name' => '',
            'guest_email' => '',
            'guest_phone' => '',
            'category_id' => $firstCat?->id,
            'priority' => $firstCat?->default_priority ?? Ticket::PRIORITY_MEDIUM,
            'subject' => '',
            'description' => '',
        ];
        $this->dispatch('modal-open', name: 'ticket-create-modal');
    }

    public function createTicket(): void
    {
        $this->validate([
            'createForm.subject' => ['required', 'string', 'min:5', 'max:255'],
            'createForm.description' => ['required', 'string', 'min:10'],
            'createForm.category_id' => ['required', 'exists:ticket_categories,id'],
            'createForm.priority' => ['required', 'in:low,medium,high,urgent'],
            'createForm.guest_name' => ['nullable', 'string', 'max:255'],
            'createForm.guest_email' => ['nullable', 'email', 'max:255'],
            'createForm.guest_phone' => ['nullable', 'string', 'max:20'],
        ]);

        /** @var TicketService $service */
        $service = app(TicketService::class);

        $ticket = $service->createTicket(
            data: array_merge($this->createForm, ['source' => Ticket::SOURCE_ADMIN]),
            creator: auth()->user()
        );

        $this->dispatch('modal-close', name: 'ticket-create-modal');
        Toast::dispatch($this, 'success', __("Support ticket #:number created successfully.", ['number' => $ticket->ticket_number]));

        $this->redirect(route('admin.tickets.show', $ticket->ticket_number), navigate: true);
    }

    public function export(string $format = 'csv')
    {
        $tickets = $this->getTicketsQuery()->get();

        $export = new TableExport(
            items: $tickets,
            headings: ['Ticket #', 'Subject', 'Submitter', 'Email', 'Category', 'Priority', 'Status', 'Assignee', 'Created At', 'Resolved At'],
            mapRow: fn (Ticket $t) => [
                $t->ticket_number,
                $t->subject,
                $t->submitterName(),
                $t->submitterEmail(),
                $t->category?->name ?? 'N/A',
                $t->priorityLabel(),
                $t->statusLabel(),
                $t->assignedTo?->name ?? 'Unassigned',
                $t->created_at->format('Y-m-d H:i:s'),
                $t->resolved_at?->format('Y-m-d H:i:s') ?? '',
            ]
        );

        return Excel::download($export, "support-tickets-export-".now()->format('Y-m-d').".{$format}");
    }

    protected function getTicketsQuery()
    {
        return Ticket::query()
            ->with(['category', 'assignedTo', 'user', 'lastReplyBy'])
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('ticket_number', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('guest_name', 'like', $term)
                        ->orWhere('guest_email', 'like', $term);
                });
            })
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->priorityFilter !== 'all', fn ($q) => $q->where('priority', $this->priorityFilter))
            ->when($this->categoryFilter !== 'all', fn ($q) => $q->where('category_id', $this->categoryFilter))
            ->when($this->assigneeFilter === 'unassigned', fn ($q) => $q->whereNull('assigned_to_user_id'))
            ->when($this->assigneeFilter !== 'all' && $this->assigneeFilter !== 'unassigned', fn ($q) => $q->where('assigned_to_user_id', $this->assigneeFilter))
            ->when($this->slaFilter === 'breached', fn ($q) => $q->where(fn ($sub) => $sub->where('is_sla_response_breached', true)->orWhere('is_sla_resolution_breached', true)))
            ->latest('last_reply_at');
    }

    public function with(): array
    {
        $totalOpen = Ticket::open()->count();
        $totalInProgress = Ticket::where('status', Ticket::STATUS_IN_PROGRESS)->count();
        $totalSlaBreached = Ticket::open()->where(fn ($q) => $q->where('is_sla_response_breached', true)->orWhere('is_sla_resolution_breached', true))->count();
        $totalResolvedToday = Ticket::whereDate('resolved_at', today())->count();
        $avgRating = round((float) Ticket::whereNotNull('satisfaction_rating')->avg('satisfaction_rating'), 1);

        $staffUsers = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Administrator', 'Administrator', 'Staff', 'Faculty', 'Admissions Officer']))->get();

        return [
            'tickets' => $this->getTicketsQuery()->paginate($this->perPage),
            'kanbanTickets' => $this->viewMode === 'kanban' ? $this->getTicketsQuery()->limit(100)->get()->groupBy('status') : collect(),
            'categories' => TicketCategory::active()->ordered()->get(),
            'staffUsers' => $staffUsers,
            'metrics' => [
                'open' => $totalOpen,
                'in_progress' => $totalInProgress,
                'sla_breached' => $totalSlaBreached,
                'resolved_today' => $totalResolvedToday,
                'avg_rating' => $avgRating ?: 5.0,
            ],
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Top Header & Helpdesk Actions --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <x-icon name="life-buoy" class="h-5 w-5"/>
                </span>
                {{ __('Support Tickets & Helpdesk') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Unified omnichannel helpdesk, SLA tracking, staff assignments, macros, and student support inquiries.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="outline" size="sm" :href="route('admin.tickets.categories')">
                <x-icon name="tag" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Categories & SLAs') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" :href="route('admin.tickets.canned-responses')">
                <x-icon name="message-square-quote" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Canned Macros') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" wire:click="triggerSlaCheck">
                <x-icon name="clock" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Check SLA') }}
            </x-ui.button>

            <x-ui.button variant="default" size="sm" wire:click="openCreateModal">
                <x-icon name="plus" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('New Ticket') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Metrics Cards Grid --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5">
        {{-- Open Tickets --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('Open Tickets') }}</span>
                <span class="p-1.5 rounded-lg bg-blue-500/10 text-blue-600">
                    <x-icon name="inbox" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['open'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Awaiting first action') }}</div>
        </div>

        {{-- In Progress --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('In Progress') }}</span>
                <span class="p-1.5 rounded-lg bg-amber-500/10 text-amber-600">
                    <x-icon name="refresh-cw" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['in_progress'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Active investigations') }}</div>
        </div>

        {{-- SLA At Risk / Breached --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('SLA Breaches') }}</span>
                <span class="p-1.5 rounded-lg bg-destructive/10 text-destructive">
                    <x-icon name="alert-triangle" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-destructive">{{ $metrics['sla_breached'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Overdue SLA targets') }}</div>
        </div>

        {{-- Resolved Today --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('Resolved Today') }}</span>
                <span class="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-icon name="check-circle-2" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['resolved_today'] }}</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Completed tickets') }}</div>
        </div>

        {{-- CSAT Rating --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-1">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-[11px] font-semibold uppercase tracking-wider">{{ __('Avg Satisfaction') }}</span>
                <span class="p-1.5 rounded-lg bg-amber-500/10 text-amber-500">
                    <x-icon name="star" class="h-3.5 w-3.5"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $metrics['avg_rating'] }} / 5</div>
            <div class="text-[10px] text-muted-foreground">{{ __('Student CSAT Score') }}</div>
        </div>
    </div>

    {{-- Filter & Action Bar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            {{-- Search & Dropdown Filters --}}
            <div class="flex flex-wrap items-center gap-2 flex-1">
                <div class="relative w-full sm:w-60">
                    <x-icon name="search" class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground"/>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="{{ __('Search ticket #, subject, user...') }}"
                        class="w-full pl-8 pr-3 py-1.5 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground"
                    />
                </div>

                {{-- Status Filter --}}
                <select wire:model.live="statusFilter" class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border text-foreground">
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="open">{{ __('Open') }}</option>
                    <option value="in_progress">{{ __('In Progress') }}</option>
                    <option value="pending_user">{{ __('Awaiting Customer') }}</option>
                    <option value="on_hold">{{ __('On Hold') }}</option>
                    <option value="resolved">{{ __('Resolved') }}</option>
                    <option value="closed">{{ __('Closed') }}</option>
                </select>

                {{-- Priority Filter --}}
                <select wire:model.live="priorityFilter" class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border text-foreground">
                    <option value="all">{{ __('All Priorities') }}</option>
                    <option value="urgent">{{ __('Urgent') }}</option>
                    <option value="high">{{ __('High') }}</option>
                    <option value="medium">{{ __('Medium') }}</option>
                    <option value="low">{{ __('Low') }}</option>
                </select>

                {{-- Category Filter --}}
                <select wire:model.live="categoryFilter" class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border text-foreground">
                    <option value="all">{{ __('All Categories') }}</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>

                {{-- Assignee Filter --}}
                <select wire:model.live="assigneeFilter" class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border text-foreground">
                    <option value="all">{{ __('All Assignees') }}</option>
                    <option value="unassigned">{{ __('Unassigned') }}</option>
                    @foreach ($staffUsers as $staff)
                        <option value="{{ $staff->id }}">{{ $staff->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- View Switcher & Export --}}
            <div class="flex items-center gap-2">
                {{-- Bulk Action trigger if items selected --}}
                @if (! empty($selectedIds))
                    <div class="flex items-center gap-1.5 bg-primary/10 border border-primary/20 px-2.5 py-1 rounded-lg">
                        <span class="text-xs font-semibold text-primary">{{ count($selectedIds) }} {{ __('selected') }}</span>
                        <button type="button" wire:click="openBulkModal('status')" class="text-[11px] font-medium text-foreground hover:underline px-1.5 py-0.5 rounded">
                            {{ __('Set Status') }}
                        </button>
                        <button type="button" wire:click="openBulkModal('assign')" class="text-[11px] font-medium text-foreground hover:underline px-1.5 py-0.5 rounded">
                            {{ __('Assign') }}
                        </button>
                        <button type="button" wire:click="openBulkModal('delete')" class="text-[11px] font-medium text-destructive hover:underline px-1.5 py-0.5 rounded">
                            {{ __('Delete') }}
                        </button>
                    </div>
                @endif

                {{-- View Mode Toggle --}}
                <div class="flex items-center bg-secondary/50 p-0.5 rounded-lg border border-border">
                    <button
                        type="button"
                        wire:click="$set('viewMode', 'table')"
                        class="p-1.5 rounded-md transition-colors {{ $viewMode === 'table' ? 'bg-background text-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground' }}"
                        title="{{ __('Table View') }}"
                    >
                        <x-icon name="list" class="h-3.5 w-3.5"/>
                    </button>
                    <button
                        type="button"
                        wire:click="$set('viewMode', 'kanban')"
                        class="p-1.5 rounded-md transition-colors {{ $viewMode === 'kanban' ? 'bg-background text-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground' }}"
                        title="{{ __('Kanban View') }}"
                    >
                        <x-icon name="columns" class="h-3.5 w-3.5"/>
                    </button>
                </div>

                {{-- Export Button --}}
                <x-ui.button variant="outline" size="sm" wire:click="export('csv')">
                    <x-icon name="download" class="h-3.5 w-3.5 mr-1"/>
                    {{ __('Export') }}
                </x-ui.button>
            </div>
        </div>

        {{-- VIEW MODE 1: Table View --}}
        @if ($viewMode === 'table')
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
                            <th class="p-3">{{ __('Ticket #') }}</th>
                            <th class="p-3">{{ __('Subject & Details') }}</th>
                            <th class="p-3">{{ __('Customer / User') }}</th>
                            <th class="p-3">{{ __('Category') }}</th>
                            <th class="p-3">{{ __('Priority') }}</th>
                            <th class="p-3">{{ __('Assignee') }}</th>
                            <th class="p-3">{{ __('Status') }}</th>
                            <th class="p-3">{{ __('SLA Deadline') }}</th>
                            <th class="p-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tickets as $t)
                            <tr wire:key="admin-ticket-row-{{ $t->id }}" class="hover:bg-secondary/15 transition-colors">
                                <td class="p-3 text-center">
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedIds"
                                        value="{{ $t->id }}"
                                        class="rounded border-border text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"
                                    />
                                </td>
                                <td class="p-3 font-mono font-bold text-foreground">
                                    <a href="{{ route('admin.tickets.show', $t->ticket_number) }}" class="text-primary hover:underline">
                                        {{ $t->ticket_number }}
                                    </a>
                                </td>
                                <td class="p-3">
                                    <a href="{{ route('admin.tickets.show', $t->ticket_number) }}" class="font-semibold text-foreground hover:text-primary transition-colors block line-clamp-1">
                                        {{ $t->subject }}
                                    </a>
                                    <div class="text-[11px] text-muted-foreground line-clamp-1 mt-0.5">
                                        {{ Str::limit(strip_tags($t->description), 80) }}
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="font-medium text-foreground">{{ $t->submitterName() }}</div>
                                    <div class="text-[10px] text-muted-foreground font-mono">{{ $t->submitterEmail() }}</div>
                                </td>
                                <td class="p-3">
                                    @if ($t->category)
                                        <x-ui.badge :color="$t->category->color_badge" class="text-[10px]">
                                            {{ $t->category->name }}
                                        </x-ui.badge>
                                    @else
                                        <span class="text-muted-foreground">—</span>
                                    @endif
                                </td>
                                <td class="p-3">
                                    <x-ui.badge :color="$t->priorityBadgeColor()" class="text-[10px]">
                                        {{ $t->priorityLabel() }}
                                    </x-ui.badge>
                                </td>
                                <td class="p-3">
                                    @if ($t->assignedTo)
                                        <div class="flex items-center gap-1.5">
                                            <div class="flex h-5 w-5 items-center justify-center rounded-full bg-secondary text-[10px] font-bold text-foreground">
                                                {{ substr($t->assignedTo->name, 0, 1) }}
                                            </div>
                                            <span class="text-xs text-foreground">{{ $t->assignedTo->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-amber-600 dark:text-amber-400 font-medium">{{ __('Unassigned') }}</span>
                                    @endif
                                </td>
                                <td class="p-3">
                                    <x-ui.badge :color="$t->statusBadgeColor()" class="text-[10px]">
                                        {{ $t->statusLabel() }}
                                    </x-ui.badge>
                                </td>
                                <td class="p-3 text-muted-foreground">
                                    @if ($t->is_sla_response_breached || $t->is_sla_resolution_breached)
                                        <span class="text-destructive font-bold flex items-center gap-1 text-[11px]">
                                            <x-icon name="alert-circle" class="h-3 w-3"/>
                                            {{ __('SLA Breached') }}
                                        </span>
                                    @elseif ($t->resolution_due_at)
                                        <span class="text-[11px] text-foreground">{{ $t->resolution_due_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-muted-foreground">—</span>
                                    @endif
                                </td>
                                <td class="p-3 text-right">
                                    <x-ui.button variant="outline" size="sm" :href="route('admin.tickets.show', $t->ticket_number)">
                                        {{ __('Open') }}
                                        <x-icon name="arrow-right" class="h-3 w-3 ml-1"/>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="p-12 text-center text-muted-foreground">
                                    <x-icon name="life-buoy" class="mx-auto h-8 w-8 mb-2 opacity-40"/>
                                    <p class="text-sm font-medium">{{ __('No tickets found matching filters') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($tickets->hasPages())
                <div class="pt-2 border-t border-border">
                    {{ $tickets->links() }}
                </div>
            @endif
        @else
            {{-- VIEW MODE 2: Kanban Board View --}}
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4 overflow-x-auto pb-4">
                @foreach (['open' => __('Open'), 'in_progress' => __('In Progress'), 'pending_user' => __('Awaiting Customer'), 'on_hold' => __('On Hold'), 'resolved' => __('Resolved')] as $statusCode => $statusTitle)
                    @php $columnTickets = $kanbanTickets->get($statusCode, collect()); @endphp
                    <div class="rounded-xl border border-border bg-secondary/15 p-3 flex flex-col min-w-[240px] space-y-3">
                        <div class="flex items-center justify-between border-b border-border pb-2">
                            <span class="text-xs font-bold text-foreground uppercase tracking-wider">{{ $statusTitle }}</span>
                            <x-ui.badge color="secondary" class="text-[10px]">{{ $columnTickets->count() }}</x-ui.badge>
                        </div>

                        <div class="space-y-2.5 flex-1 overflow-y-auto max-h-[600px] pr-1">
                            @forelse ($columnTickets as $kt)
                                <div class="rounded-lg border border-border bg-card p-3 shadow-2xs space-y-2 hover:border-primary/50 transition-colors">
                                    <div class="flex items-center justify-between">
                                        <a href="{{ route('admin.tickets.show', $kt->ticket_number) }}" class="font-mono text-xs font-bold text-primary hover:underline">
                                            #{{ $kt->ticket_number }}
                                        </a>
                                        <x-ui.badge :color="$kt->priorityBadgeColor()" class="text-[9px]">
                                            {{ $kt->priorityLabel() }}
                                        </x-ui.badge>
                                    </div>

                                    <a href="{{ route('admin.tickets.show', $kt->ticket_number) }}" class="text-xs font-semibold text-foreground line-clamp-2 hover:text-primary">
                                        {{ $kt->subject }}
                                    </a>

                                    <div class="flex items-center justify-between text-[10px] text-muted-foreground pt-1 border-t border-border/50">
                                        <span>{{ $kt->submitterName() }}</span>
                                        <span>{{ $kt->created_at->diffForHumans(null, true) }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="p-6 text-center text-muted-foreground text-xs italic">
                                    {{ __('No tickets') }}
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- MODAL 1: Create Ticket on Behalf of User --}}
    <x-ui.modal name="ticket-create-modal" max-width="max-w-lg" :title="__('Create Ticket on Behalf of User')" :description="__('Log a support inquiry received via phone, in-person front desk, or direct email.')">
        <form wire:submit="createTicket" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-ui.input
                    wire:model="createForm.guest_name"
                    :label="__('Student / Contact Name') . ' *'"
                    placeholder="e.g. John Doe"
                />

                <x-ui.input
                    wire:model="createForm.guest_email"
                    type="email"
                    :label="__('Contact Email')"
                    placeholder="student@example.com"
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-ui.select
                    wire:model="createForm.category_id"
                    :label="__('Category') . ' *'"
                    :options="$categories->pluck('name', 'id')->toArray()"
                />

                <x-ui.select
                    wire:model="createForm.priority"
                    :label="__('Priority') . ' *'"
                    :options="['low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High'), 'urgent' => __('Urgent')]"
                />
            </div>

            <x-ui.input
                wire:model="createForm.subject"
                :label="__('Subject Summary') . ' *'"
                placeholder="Brief summary of inquiry"
            />

            <div>
                <label class="text-xs font-semibold text-foreground block mb-1.5">{{ __('Detailed Description') }} *</label>
                <textarea
                    wire:model="createForm.description"
                    rows="4"
                    class="w-full px-3.5 py-2 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 text-foreground"
                    placeholder="Provide full description..."
                ></textarea>
                @error('createForm.description') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('ticket-create-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" type="submit">
                    <x-icon name="check" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Create Ticket') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- MODAL 2: Bulk Action Modal --}}
    <x-ui.modal name="ticket-bulk-modal" max-width="max-w-md" :title="__('Bulk Action on Tickets')" :description="__('Perform bulk updates across :count selected tickets.', ['count' => count($selectedIds)])">
        <form wire:submit="executeBulkAction" class="space-y-4">
            @if ($bulkActionType === 'status')
                <x-ui.select
                    wire:model="bulkActionValue"
                    :label="__('Select New Status') . ' *'"
                    :options="[
                        'open' => __('Open'),
                        'in_progress' => __('In Progress'),
                        'pending_user' => __('Awaiting Customer'),
                        'on_hold' => __('On Hold'),
                        'resolved' => __('Resolved'),
                        'closed' => __('Closed'),
                    ]"
                />
            @elseif ($bulkActionType === 'assign')
                <x-ui.select
                    wire:model="bulkActionValue"
                    :label="__('Assign to Staff Agent') . ' *'"
                    :options="$staffUsers->pluck('name', 'id')->toArray()"
                />
            @elseif ($bulkActionType === 'delete')
                <p class="text-xs text-destructive font-medium">
                    {{ __('Are you sure you want to soft-delete :count selected tickets?', ['count' => count($selectedIds)]) }}
                </p>
            @endif

            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('ticket-bulk-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button :variant="$bulkActionType === 'delete' ? 'destructive' : 'default'" type="submit">
                    {{ __('Apply Action') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>