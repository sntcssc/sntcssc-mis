<?php

use App\Models\Ticket;
use App\Models\TicketCategory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('My Support Tickets')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusTab = 'all'; // all, open, closed
    public string $categoryFilter = 'all';
    public int $perPage = 10;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusTab(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = Ticket::query()
            ->with(['category', 'assignedTo', 'lastReplyBy'])
            ->where('user_id', $user->id)
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('ticket_number', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->when($this->categoryFilter !== 'all', fn ($q) => $q->where('category_id', $this->categoryFilter))
            ->when($this->statusTab === 'open', fn ($q) => $q->open())
            ->when($this->statusTab === 'closed', fn ($q) => $q->closed())
            ->latest('last_reply_at');

        $totalOpen = Ticket::where('user_id', $user->id)->open()->count();
        $totalClosed = Ticket::where('user_id', $user->id)->closed()->count();
        $totalAll = Ticket::where('user_id', $user->id)->count();

        return [
            'tickets' => $query->paginate($this->perPage),
            'categories' => TicketCategory::active()->ordered()->get(),
            'counts' => [
                'all' => $totalAll,
                'open' => $totalOpen,
                'closed' => $totalClosed,
            ],
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header Banner --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <x-icon name="life-buoy" class="h-5 w-5"/>
                </span>
                {{ __('My Support Tickets') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Track your inquiries, submit help requests, and communicate with the academic & technical support cell.') }}
            </p>
        </div>

        <div>
            <x-ui.button variant="default" size="sm" :href="route('tickets.create')">
                <x-icon name="plus" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Open New Ticket') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Filter Bar & Status Tabs --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            {{-- Status Tabs --}}
            <div class="flex items-center gap-1 bg-secondary/50 p-1 rounded-lg">
                <button
                    type="button"
                    wire:click="$set('statusTab', 'all')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $statusTab === 'all' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('All Tickets') }} ({{ $counts['all'] }})
                </button>
                <button
                    type="button"
                    wire:click="$set('statusTab', 'open')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $statusTab === 'open' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('Active / In Progress') }} ({{ $counts['open'] }})
                </button>
                <button
                    type="button"
                    wire:click="$set('statusTab', 'closed')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $statusTab === 'closed' ? 'bg-background text-foreground shadow-2xs font-semibold' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    {{ __('Resolved / Closed') }} ({{ $counts['closed'] }})
                </button>
            </div>

            {{-- Search & Category Filter --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-56">
                    <x-icon name="search" class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground"/>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="{{ __('Search subject, #ID...') }}"
                        class="w-full pl-8 pr-3 py-1.5 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground"
                    />
                </div>

                <select
                    wire:model.live="categoryFilter"
                    class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground"
                >
                    <option value="all">{{ __('All Categories') }}</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Tickets Listing Table --}}
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase text-[10px] tracking-wider border-b border-border">
                    <tr>
                        <th class="p-3">{{ __('Ticket #') }}</th>
                        <th class="p-3">{{ __('Subject & Details') }}</th>
                        <th class="p-3">{{ __('Category') }}</th>
                        <th class="p-3">{{ __('Priority') }}</th>
                        <th class="p-3">{{ __('Status') }}</th>
                        <th class="p-3">{{ __('Last Activity') }}</th>
                        <th class="p-3 text-right">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($tickets as $ticket)
                        <tr wire:key="ticket-row-{{ $ticket->id }}" class="hover:bg-secondary/15 transition-colors">
                            <td class="p-3 font-mono font-bold text-foreground">
                                <a href="{{ route('tickets.show', $ticket->ticket_number) }}" class="text-primary hover:underline">
                                    {{ $ticket->ticket_number }}
                                </a>
                            </td>
                            <td class="p-3">
                                <a href="{{ route('tickets.show', $ticket->ticket_number) }}" class="font-medium text-foreground hover:text-primary transition-colors block line-clamp-1">
                                    {{ $ticket->subject }}
                                </a>
                                <p class="text-[11px] text-muted-foreground line-clamp-1 mt-0.5">
                                    {{ Str::limit(strip_tags($ticket->description), 80) }}
                                </p>
                            </td>
                            <td class="p-3">
                                @if ($ticket->category)
                                    <x-ui.badge :color="$ticket->category->color_badge" class="text-[10px]">
                                        {{ $ticket->category->name }}
                                    </x-ui.badge>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$ticket->priorityBadgeColor()" class="text-[10px]">
                                    {{ $ticket->priorityLabel() }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$ticket->statusBadgeColor()" class="text-[10px]">
                                    {{ $ticket->statusLabel() }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3 text-muted-foreground">
                                <div class="text-foreground font-medium">{{ $ticket->last_reply_at?->diffForHumans() ?? $ticket->created_at->diffForHumans() }}</div>
                                <div class="text-[10px]">{{ $ticket->lastReplyBy?->name ?? $ticket->submitterName() }}</div>
                            </td>
                            <td class="p-3 text-right">
                                <x-ui.button variant="outline" size="sm" :href="route('tickets.show', $ticket->ticket_number)">
                                    {{ __('View Thread') }}
                                    <x-icon name="arrow-right" class="h-3 w-3 ml-1"/>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-12 text-center text-muted-foreground">
                                <x-icon name="life-buoy" class="mx-auto h-8 w-8 mb-2 opacity-40"/>
                                <p class="text-sm font-medium">{{ __('No support tickets found') }}</p>
                                <p class="text-xs mt-1">{{ __('Have a query or need assistance? Open a new ticket now.') }}</p>
                                <div class="mt-4">
                                    <x-ui.button variant="default" size="sm" :href="route('tickets.create')">
                                        <x-icon name="plus" class="h-3.5 w-3.5 mr-1.5"/>
                                        {{ __('Submit Ticket') }}
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($tickets->hasPages())
            <div class="pt-2 border-t border-border">
                {{ $tickets->links() }}
            </div>
        @endif
    </div>
</div>