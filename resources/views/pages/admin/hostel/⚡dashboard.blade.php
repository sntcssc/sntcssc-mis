<?php

use App\Models\HostelAllocation;
use App\Models\HostelBuilding;
use App\Models\HostelExtensionRequest;
use App\Models\Setting;
use App\Services\Hostel\HostelService;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Hostel Command Center')] class extends Component {
    public ?int $selectedBuildingId = null;

    // Quick Extension Modal
    public ?int $extendingAllocationId = null;
    public string $newEndDate = '';
    public string $extensionNotes = '';

    // Vacate Modal
    public ?int $vacatingAllocationId = null;
    public string $vacateDate = '';
    public string $vacateRemarks = '';

    public function mount(): void
    {
        $this->vacateDate = now()->toDateString();
    }

    #[Computed]
    public function stats(): array
    {
        return HostelService::getStatistics($this->selectedBuildingId);
    }

    #[Computed]
    public function buildings()
    {
        return HostelBuilding::withCount(['rooms', 'floors'])
            ->with(['rooms.beds'])
            ->orderBy('display_order')
            ->get();
    }

    #[Computed]
    public function expiringAllocations()
    {
        $noticeDays = (int) Setting::get('hostel.expiry_notice_days', 15);

        return HostelAllocation::with(['user', 'room.building', 'bed'])
            ->expiringSoon($noticeDays)
            ->orderBy('end_date')
            ->take(10)
            ->get();
    }

    #[Computed]
    public function recentAllocations()
    {
        return HostelAllocation::with(['user', 'room.building', 'bed'])
            ->latest('id')
            ->take(8)
            ->get();
    }

    #[Computed]
    public function pendingExtensionRequestsCount(): int
    {
        return HostelExtensionRequest::pending()->count();
    }

    public function openExtendModal(int $allocationId): void
    {
        $this->resetErrorBag();
        $allocation = HostelAllocation::findOrFail($allocationId);
        $this->extendingAllocationId = $allocation->id;
        $this->newEndDate = $allocation->end_date ? $allocation->end_date->addMonths(3)->toDateString() : now()->addMonths(3)->toDateString();
        $this->extensionNotes = '';
        $this->dispatch('open-modal', name: 'quick-extend-modal');
    }

    public function processExtension(): void
    {
        $this->validate([
            'newEndDate' => 'required|date|after:today',
            'extensionNotes' => 'nullable|string|max:500',
        ]);

        if (! $this->extendingAllocationId) {
            return;
        }

        try {
            $allocation = HostelAllocation::findOrFail($this->extendingAllocationId);
            HostelService::extendAllocation(
                $allocation,
                $this->newEndDate,
                $this->extensionNotes,
                auth()->id()
            );

            Toast::dispatch($this, 'success', __('Hostel accommodation extended successfully.'));
            $this->dispatch('close-modal', name: 'quick-extend-modal');
            $this->extendingAllocationId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openVacateModal(int $allocationId): void
    {
        $this->resetErrorBag();
        $allocation = HostelAllocation::findOrFail($allocationId);
        $this->vacatingAllocationId = $allocation->id;
        $this->vacateDate = now()->toDateString();
        $this->vacateRemarks = '';
        $this->dispatch('open-modal', name: 'quick-vacate-modal');
    }

    public function processVacate(): void
    {
        $this->validate([
            'vacateDate' => 'required|date',
            'vacateRemarks' => 'nullable|string|max:500',
        ]);

        if (! $this->vacatingAllocationId) {
            return;
        }

        try {
            $allocation = HostelAllocation::findOrFail($this->vacatingAllocationId);
            HostelService::vacateAllocation(
                $allocation,
                $this->vacateDate,
                $this->vacateRemarks,
                auth()->id()
            );

            Toast::dispatch($this, 'success', __('Resident vacated successfully and bed marked free.'));
            $this->dispatch('close-modal', name: 'quick-vacate-modal');
            $this->vacatingAllocationId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Hostel Command Center') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                    {{ __('Live Management') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Dynamic room and bed allocations, occupancy status, visual floor tracking, and resident management.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.hostel.rooms.board') }}" wire:navigate>
                <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                    <x-icon name="layout-grid" class="w-4 h-4 mr-1.5" />
                    {{ __('Visual Room Board') }}
                </x-ui.button>
            </a>

            <a href="{{ route('admin.hostel.allocations') }}" wire:navigate>
                <x-ui.button variant="default" size="sm" class="cursor-pointer bg-primary text-primary-foreground">
                    <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                    {{ __('Allocate Room') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    <!-- Real-time Statistics KPI Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <!-- Total Capacity -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Beds') }}</span>
                <span class="p-2 rounded-lg bg-primary/10 text-primary">
                    <x-icon name="bed" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-foreground">{{ $this->stats['total_beds'] }}</div>
            <div class="mt-1 text-xs text-muted-foreground">{{ $this->stats['total_rooms'] }} {{ __('Rooms in') }} {{ $this->stats['total_buildings'] }} {{ __('Buildings') }}</div>
        </div>

        <!-- Occupied Beds -->
        <div class="p-4 rounded-xl border border-blue-500/20 bg-blue-500/5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wider">{{ __('Occupied') }}</span>
                <span class="p-2 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="users" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-blue-700 dark:text-blue-300">{{ $this->stats['occupied_beds'] }}</div>
            <div class="mt-1 text-xs text-blue-600/80 dark:text-blue-400/80">{{ $this->stats['occupancy_rate'] }}% {{ __('Occupancy Rate') }}</div>
        </div>

        <!-- Available (Free) Beds -->
        <div class="p-4 rounded-xl border border-emerald-500/20 bg-emerald-500/5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Available (Free)') }}</span>
                <span class="p-2 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ $this->stats['available_beds'] }}</div>
            <div class="mt-1 text-xs text-emerald-600/80 dark:text-emerald-400/80">{{ __('Ready for allotment') }}</div>
        </div>

        <!-- Reserved Beds -->
        <div class="p-4 rounded-xl border border-amber-500/20 bg-amber-500/5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Reserved') }}</span>
                <span class="p-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="bookmark" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-amber-700 dark:text-amber-300">{{ $this->stats['reserved_beds'] }}</div>
            <div class="mt-1 text-xs text-amber-600/80 dark:text-amber-400/80">{{ __('Pending check-in') }}</div>
        </div>

        <!-- Maintenance Beds -->
        <div class="p-4 rounded-xl border border-rose-500/20 bg-rose-500/5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">{{ __('Maintenance') }}</span>
                <span class="p-2 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400">
                    <x-icon name="tool" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-rose-700 dark:text-rose-300">{{ $this->stats['maintenance_beds'] }}</div>
            <div class="mt-1 text-xs text-rose-600/80 dark:text-rose-400/80">{{ __('Under repair/service') }}</div>
        </div>

        <!-- Pending Extension Requests -->
        <a href="{{ route('admin.hostel.requests') }}" wire:navigate class="p-4 rounded-xl border border-purple-500/20 bg-purple-500/5 shadow-xs hover:border-purple-500/40 transition-colors">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-purple-600 dark:text-purple-400 uppercase tracking-wider">{{ __('Requests') }}</span>
                <span class="p-2 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400">
                    <x-icon name="calendar-plus" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-purple-700 dark:text-purple-300">{{ $this->stats['pending_extension_requests'] }}</div>
            <div class="mt-1 text-xs text-purple-600/80 dark:text-purple-400/80">{{ __('Awaiting review') }} &rarr;</div>
        </a>
    </div>

    <!-- Buildings Live Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach ($this->buildings as $b)
            @php
                $bBeds = $b->beds;
                $bTotalBeds = $bBeds->count();
                $bOccupied = $bBeds->where('status', 'occupied')->count();
                $bFree = $bBeds->where('status', 'available')->count();
                $bRate = $bTotalBeds > 0 ? round(($bOccupied / $bTotalBeds) * 100, 1) : 0;
            @endphp
            <div class="p-5 rounded-2xl border border-border bg-card shadow-xs hover:border-primary/40 transition-all flex flex-col justify-between" wire:key="building-{{ $b->id }}">
                <div>
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-bold text-foreground">{{ $b->name }}</h3>
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider bg-secondary text-secondary-foreground">
                                    {{ $b->code }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mt-1">
                                <span @class([
                                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-blue-500/10 text-blue-600 dark:text-blue-400' => $b->gender_type === 'boys_only',
                                    'bg-pink-500/10 text-pink-600 dark:text-pink-400' => $b->gender_type === 'girls_only',
                                    'bg-purple-500/10 text-purple-600 dark:text-purple-400' => $b->gender_type === 'coed',
                                ])>
                                    {{ $b->gender_label }}
                                </span>
                                <span class="text-xs text-muted-foreground">&bull; {{ $b->floors_count }} {{ __('Floors') }}</span>
                            </div>
                        </div>

                        <span class="p-2.5 rounded-xl bg-secondary text-foreground">
                            <x-icon name="building-2" class="w-5 h-5" />
                        </span>
                    </div>

                    <p class="text-xs text-muted-foreground mt-3 line-clamp-2">
                        {{ $b->description ?? __('Warden: ') . ($b->warden_name ?: __('Unassigned')) }}
                    </p>

                    <!-- Occupancy Progress -->
                    <div class="mt-4 space-y-1.5">
                        <div class="flex justify-between text-xs">
                            <span class="text-muted-foreground">{{ __('Occupancy') }} ({{ $bOccupied }}/{{ $bTotalBeds }} {{ __('Beds') }})</span>
                            <span class="font-bold text-foreground">{{ $bRate }}%</span>
                        </div>
                        <div class="w-full bg-secondary rounded-full h-2 overflow-hidden">
                            <div
                                @class([
                                    'h-2 rounded-full transition-all duration-500',
                                    'bg-emerald-500' => $bRate < 70,
                                    'bg-amber-500' => $bRate >= 70 && $bRate < 90,
                                    'bg-rose-500' => $bRate >= 90,
                                ])
                                style="width: {{ $bRate }}%"
                            ></div>
                        </div>
                    </div>

                    <!-- Mini Stat Badges -->
                    <div class="grid grid-cols-2 gap-2 mt-4 pt-4 border-t border-border/60 text-xs">
                        <div class="flex items-center justify-between px-2.5 py-1.5 rounded-lg bg-secondary/50">
                            <span class="text-muted-foreground">{{ __('Free Beds') }}:</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $bFree }}</span>
                        </div>
                        <div class="flex items-center justify-between px-2.5 py-1.5 rounded-lg bg-secondary/50">
                            <span class="text-muted-foreground">{{ __('Total Rooms') }}:</span>
                            <span class="font-bold text-foreground">{{ $b->rooms_count }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-5 pt-3 border-t border-border flex items-center justify-between">
                    <a
                        href="{{ route('admin.hostel.rooms.board', ['building' => $b->id]) }}"
                        wire:navigate
                        class="text-xs font-semibold text-primary hover:underline inline-flex items-center gap-1"
                    >
                        {{ __('View Floor Plan') }} &rarr;
                    </a>

                    <a
                        href="{{ route('admin.hostel.rooms.index', ['building' => $b->id]) }}"
                        wire:navigate
                        class="text-xs text-muted-foreground hover:text-foreground"
                    >
                        {{ __('Manage Rooms') }}
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    <!-- 2-Column Section: Expiring Allocations & Recent Allocations -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Expiring Soon Allocations -->
        <div class="rounded-2xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="p-4 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="p-1.5 rounded-md bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <x-icon name="alert-circle" class="w-4 h-4" />
                    </span>
                    <div>
                        <h2 class="text-base font-bold text-foreground">{{ __('Allocations Expiring Soon') }}</h2>
                        <p class="text-xs text-muted-foreground">{{ __('Residents whose accommodation timeline ends in next :days days', ['days' => Setting::get('hostel.expiry_notice_days', 15)]) }}</p>
                    </div>
                </div>
                <a href="{{ route('admin.hostel.allocations', ['expiring' => 1]) }}" wire:navigate class="text-xs text-primary font-medium hover:underline">
                    {{ __('View All') }} &rarr;
                </a>
            </div>

            <div class="divide-y divide-border overflow-x-auto">
                @forelse ($this->expiringAllocations as $ea)
                    <div class="p-4 flex items-center justify-between gap-4 hover:bg-secondary/30 transition-colors" wire:key="ea-{{ $ea->id }}">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-secondary flex items-center justify-center font-bold text-xs text-foreground shrink-0">
                                {{ substr($ea->user->name ?? 'U', 0, 2) }}
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-sm text-foreground">{{ $ea->user->name }}</span>
                                    <span class="px-1.5 py-0.2 rounded text-[10px] font-bold uppercase bg-secondary text-secondary-foreground">
                                        {{ $ea->resident_type }}
                                    </span>
                                </div>
                                <div class="text-xs text-muted-foreground mt-0.5">
                                    {{ $ea->room->building->name ?? '' }} &bull; {{ $ea->room->room_number ?? '' }} ({{ $ea->bed->bed_number ?? '' }})
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            <div class="text-right">
                                <div class="text-xs font-semibold text-amber-600 dark:text-amber-400">
                                    {{ $ea->end_date?->format('d M Y') }}
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    {{ $ea->daysRemaining() }} {{ __('days left') }}
                                </div>
                            </div>

                            <x-ui.button
                                variant="outline"
                                size="sm"
                                wire:click="openExtendModal({{ $ea->id }})"
                                class="cursor-pointer text-xs h-7 px-2"
                            >
                                <x-icon name="calendar-plus" class="w-3.5 h-3.5 mr-1" />
                                {{ __('Extend') }}
                            </x-ui.button>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-muted-foreground">
                        <x-icon name="check-circle" class="w-8 h-8 text-emerald-500 mx-auto mb-2" />
                        {{ __('No accommodations expiring in the current notice window.') }}
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Recent Room Accommodations -->
        <div class="rounded-2xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="p-4 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="p-1.5 rounded-md bg-blue-500/10 text-blue-600 dark:text-blue-400">
                        <x-icon name="users" class="w-4 h-4" />
                    </span>
                    <div>
                        <h2 class="text-base font-bold text-foreground">{{ __('Recent Accommodations') }}</h2>
                        <p class="text-xs text-muted-foreground">{{ __('Latest students and staff checked into hostel') }}</p>
                    </div>
                </div>
                <a href="{{ route('admin.hostel.allocations') }}" wire:navigate class="text-xs text-primary font-medium hover:underline">
                    {{ __('Manage All') }} &rarr;
                </a>
            </div>

            <div class="divide-y divide-border overflow-x-auto">
                @forelse ($this->recentAllocations as $ra)
                    <div class="p-4 flex items-center justify-between gap-4 hover:bg-secondary/30 transition-colors" wire:key="ra-{{ $ra->id }}">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                                {{ substr($ra->user->name ?? 'U', 0, 2) }}
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-sm text-foreground">{{ $ra->user->name }}</span>
                                    @if ($ra->is_previous_batch_student)
                                        <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                            {{ __('Previous Batch') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="text-xs text-muted-foreground mt-0.5">
                                    {{ $ra->room->building->code ?? '' }} - {{ $ra->room->room_number ?? '' }} ({{ $ra->bed->bed_number ?? '' }})
                                    &bull; {{ __('Since') }} {{ $ra->start_date->format('d M Y') }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            @if ($ra->hard_copy_submitted)
                                <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400 font-medium" title="{{ __('Hardcopy form submitted') }}">
                                    <x-icon name="file-check" class="w-3.5 h-3.5" />
                                    {{ __('Form In') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-[11px] text-amber-600 dark:text-amber-400 font-medium" title="{{ __('Physical form pending') }}">
                                    <x-icon name="alert-circle" class="w-3.5 h-3.5" />
                                    {{ __('Form Due') }}
                                </span>
                            @endif

                            @if ($ra->isActive())
                                <x-ui.button
                                    variant="outline"
                                    size="sm"
                                    wire:click="openVacateModal({{ $ra->id }})"
                                    class="cursor-pointer text-xs h-7 px-2 text-rose-600 dark:text-rose-400 border-rose-500/20 hover:bg-rose-500/10"
                                >
                                    {{ __('Vacate') }}
                                </x-ui.button>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-secondary text-muted-foreground uppercase">
                                    {{ $ra->status }}
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-muted-foreground">
                        {{ __('No recent hostel accommodations recorded.') }}
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Interactive Modal: Quick Extend Stay -->
    <x-ui.modal name="quick-extend-modal" max-width="max-w-md" :title="__('Extend Room Accommodation Timeline')">
        <form wire:submit="processExtension" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('New Extended End Date') }} *</label>
                <input
                    type="date"
                    wire:model="newEndDate"
                    class="w-full h-10 px-3 text-sm rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                />
                @error('newEndDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Extension Remarks / Reason') }}</label>
                <textarea
                    wire:model="extensionNotes"
                    rows="3"
                    placeholder="{{ __('Reason for extension, approval authority, or academic justification...') }}"
                    class="w-full p-3 text-sm rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
                @error('extensionNotes') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('quick-extend-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="processExtension">{{ __('Confirm Extension') }}</span>
                    <span wire:loading wire:target="processExtension">{{ __('Saving...') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Interactive Modal: Quick Vacate Resident -->
    <x-ui.modal name="quick-vacate-modal" max-width="max-w-md" :title="__('Vacate Resident from Bed')">
        <form wire:submit="processVacate" class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('This will mark the accommodation as vacated, release the assigned bed back to Available (Free) status, and preserve complete stay history.') }}
            </p>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Actual Vacated Date') }} *</label>
                <input
                    type="date"
                    wire:model="vacateDate"
                    class="w-full h-10 px-3 text-sm rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                />
                @error('vacateDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Vacating Clearance Remarks / Notes') }}</label>
                <textarea
                    wire:model="vacateRemarks"
                    rows="3"
                    placeholder="{{ __('Key returned, room inspection status, dues cleared, etc.') }}"
                    class="w-full p-3 text-sm rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
                @error('vacateRemarks') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('quick-vacate-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-rose-600 hover:bg-rose-700 text-white"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="processVacate">{{ __('Confirm Vacate') }}</span>
                    <span wire:loading wire:target="processVacate">{{ __('Processing...') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
