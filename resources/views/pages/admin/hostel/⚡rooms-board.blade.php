<?php

use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\Hostel\HostelService;
use App\Support\Toast;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Hostel Visual Room Board')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'building')]
    public ?int $selectedBuildingId = null;

    #[Url(as: 'floor')]
    public ?int $selectedFloorId = null;

    #[Url(as: 'type')]
    public string $roomTypeFilter = 'all'; // all, sharing, non_sharing

    #[Url(as: 'gender')]
    public string $genderFilter = 'all'; // all, male_only, female_only, any

    #[Url(as: 'status')]
    public string $statusFilter = 'all'; // all, available, occupied, reserved, maintenance

    #[Url(as: 'q')]
    public string $search = '';

    // Allocation Modal State (Quick Check-in)
    public bool $showAllocateModal = false;
    public ?int $allocatingRoomId = null;
    public ?int $allocatingBedId = null;
    public ?int $selectedUserId = null;
    public string $residentType = 'student';
    public string $startDate = '';
    public string $durationType = 'months';
    public int $durationValue = 6;
    public ?string $customEndDate = null;
    public string $remarks = '';
    public bool $isPreviousBatchStudent = false;
    public string $previousBatchName = '';
    public bool $hardCopySubmitted = false;
    public ?string $hardCopySubmittedAt = null;
    public string $hardCopyNotes = '';
    public $softCopyFile = null;

    // Resident Dossier / Detail Modal State
    public ?int $viewingAllocationId = null;

    // Extension Modal State
    public ?int $extendingAllocationId = null;
    public string $newEndDate = '';
    public string $extensionNotes = '';

    // Vacate Modal State
    public ?int $vacatingAllocationId = null;
    public string $vacateDate = '';
    public string $vacateRemarks = '';

    // Room Status Switch Modal State
    public ?int $editingRoomId = null;
    public string $newRoomStatus = 'available';

    public function mount(): void
    {
        $this->startDate = now()->toDateString();
        $this->hardCopySubmittedAt = now()->toDateString();
        $this->vacateDate = now()->toDateString();

        if (! $this->selectedBuildingId) {
            $first = HostelBuilding::active()->orderBy('display_order')->first();
            $this->selectedBuildingId = $first?->id;
        }
    }

    public function selectBuilding(?int $id): void
    {
        $this->selectedBuildingId = $id;
        $this->selectedFloorId = null;
    }

    public function selectFloor(?int $id): void
    {
        $this->selectedFloorId = $id;
    }

    #[Computed]
    public function buildings()
    {
        return HostelBuilding::active()->orderBy('display_order')->get();
    }

    #[Computed]
    public function floors()
    {
        if (! $this->selectedBuildingId) {
            return collect();
        }

        return HostelFloor::where('hostel_building_id', $this->selectedBuildingId)
            ->active()
            ->orderBy('floor_number')
            ->get();
    }

    #[Computed]
    public function roomsGroupedByFloor()
    {
        $query = HostelRoom::with([
            'building',
            'floor',
            'beds.currentAllocation.user.studentProfile',
        ]);

        if ($this->selectedBuildingId) {
            $query->where('hostel_building_id', $this->selectedBuildingId);
        }

        if ($this->selectedFloorId) {
            $query->where('hostel_floor_id', $this->selectedFloorId);
        }

        if ($this->roomTypeFilter !== 'all') {
            $query->where('room_type', $this->roomTypeFilter);
        }

        if ($this->genderFilter !== 'all') {
            $query->where('gender_allowed', $this->genderFilter);
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if (! empty($this->search)) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('room_number', 'like', "%{$search}%")
                    ->orWhereHas('beds.currentAllocation.user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%");
                    });
            });
        }

        return $query->get()->groupBy('hostel_floor_id');
    }

    #[Computed]
    public function availableUsers()
    {
        $activeUserIds = HostelAllocation::active()->pluck('user_id')->toArray();

        $query = User::query()
            ->whereNotIn('id', $activeUserIds)
            ->where('status', 'active');

        if ($this->residentType === 'student') {
            $query->where(function ($q) {
                $q->whereHas('roles', fn ($rq) => $rq->where('name', 'Student'))
                    ->orWhereNotNull('student_id');
            });
        }

        return $query->with('studentProfile')->orderBy('name')->take(60)->get();
    }

    #[Computed]
    public function currentBuilding(): ?HostelBuilding
    {
        if (! $this->selectedBuildingId) {
            return null;
        }

        return HostelBuilding::with(['caretakers' => fn ($q) => $q->active()->orderByDesc('is_primary')])
            ->find($this->selectedBuildingId);
    }

    #[Computed]
    public function viewingAllocation(): ?HostelAllocation
    {
        if (! $this->viewingAllocationId) {
            return null;
        }

        return HostelAllocation::with([
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'user.activeEnrollment.section',
            'room.building.caretakers',
            'room.floor',
            'bed',
            'hardCopyReceiver',
        ])->find($this->viewingAllocationId);
    }

    // --- Action Handlers ---

    public function openAllocateModal(int $bedId): void
    {
        $this->resetErrorBag();
        $bed = HostelBed::with('room.building')->findOrFail($bedId);

        $this->allocatingBedId = $bed->id;
        $this->allocatingRoomId = $bed->hostel_room_id;
        $this->selectedUserId = null;
        $this->residentType = 'student';
        $this->startDate = now()->toDateString();
        $this->durationType = 'months';
        $this->durationValue = (int) Setting::get('hostel.default_duration_months', 6);
        $this->customEndDate = null;
        $this->remarks = '';
        $this->isPreviousBatchStudent = false;
        $this->previousBatchName = '';
        $this->hardCopySubmitted = false;
        $this->hardCopySubmittedAt = now()->toDateString();
        $this->hardCopyNotes = '';
        $this->softCopyFile = null;

        $this->dispatch('modal-open', name: 'board-allocate-modal');
    }

    public function saveAllocation(): void
    {
        $this->validate([
            'selectedUserId' => 'required|exists:users,id',
            'allocatingBedId' => 'required|exists:hostel_beds,id',
            'startDate' => 'required|date',
            'durationType' => 'required|in:days,months,years,custom,indefinite',
            'durationValue' => 'nullable|required_if:durationType,days,months,years|integer|min:1',
            'customEndDate' => 'nullable|required_if:durationType,custom|date|after:startDate',
            'remarks' => 'nullable|string|max:500',
            'previousBatchName' => 'nullable|required_if:isPreviousBatchStudent,true|string|max:150',
            'softCopyFile' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        try {
            $softCopyPath = null;
            if ($this->softCopyFile) {
                $softCopyPath = FileUploadService::store(
                    $this->softCopyFile,
                    'hostel/forms',
                    'hostel_form',
                    'public'
                );
            }

            HostelService::allocateResident([
                'user_id' => $this->selectedUserId,
                'hostel_bed_id' => $this->allocatingBedId,
                'resident_type' => $this->residentType,
                'start_date' => $this->startDate,
                'end_date' => $this->customEndDate,
                'duration_type' => $this->durationType,
                'duration_value' => $this->durationValue,
                'remarks' => $this->remarks,
                'hard_copy_submitted' => $this->hardCopySubmitted,
                'hard_copy_submitted_at' => $this->hardCopySubmitted ? $this->hardCopySubmittedAt : null,
                'hard_copy_received_by' => $this->hardCopySubmitted ? auth()->id() : null,
                'hard_copy_notes' => $this->hardCopyNotes,
                'soft_copy_path' => $softCopyPath,
                'is_previous_batch_student' => $this->isPreviousBatchStudent,
                'previous_batch_name' => $this->isPreviousBatchStudent ? $this->previousBatchName : null,
            ], auth()->id());

            Toast::dispatch($this, 'success', __('Room & bed successfully allocated to resident.'));
            $this->dispatch('modal-close', name: 'board-allocate-modal');
            $this->allocatingBedId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openResidentDossier(int $allocationId): void
    {
        $this->viewingAllocationId = $allocationId;
        $this->dispatch('modal-open', name: 'board-resident-modal');
    }

    public function toggleHardCopyStatus(int $allocationId): void
    {
        try {
            $allocation = HostelAllocation::findOrFail($allocationId);
            $newStatus = ! $allocation->hard_copy_submitted;

            $allocation->update([
                'hard_copy_submitted' => $newStatus,
                'hard_copy_submitted_at' => $newStatus ? now()->toDateString() : null,
                'hard_copy_received_by' => $newStatus ? auth()->id() : null,
                'updated_by' => auth()->id(),
            ]);

            Toast::dispatch($this, 'success', $newStatus ? __('Hardcopy form verified & marked submitted.') : __('Hardcopy form marked pending.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openExtendModalFromDossier(): void
    {
        if (! $this->viewingAllocationId) {
            return;
        }

        $allocation = HostelAllocation::findOrFail($this->viewingAllocationId);
        $this->extendingAllocationId = $allocation->id;
        $this->newEndDate = $allocation->end_date ? $allocation->end_date->addMonths(3)->toDateString() : now()->addMonths(3)->toDateString();
        $this->extensionNotes = '';

        $this->dispatch('modal-close', name: 'board-resident-modal');
        $this->dispatch('modal-open', name: 'board-extend-modal');
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

            Toast::dispatch($this, 'success', __('Accommodation timeline extended successfully.'));
            $this->dispatch('modal-close', name: 'board-extend-modal');
            $this->extendingAllocationId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openVacateModalFromDossier(): void
    {
        if (! $this->viewingAllocationId) {
            return;
        }

        $allocation = HostelAllocation::findOrFail($this->viewingAllocationId);
        $this->vacatingAllocationId = $allocation->id;
        $this->vacateDate = now()->toDateString();
        $this->vacateRemarks = '';

        $this->dispatch('modal-close', name: 'board-resident-modal');
        $this->dispatch('modal-open', name: 'board-vacate-modal');
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

            Toast::dispatch($this, 'success', __('Resident vacated successfully and bed released.'));
            $this->dispatch('modal-close', name: 'board-vacate-modal');
            $this->vacatingAllocationId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openRoomStatusModal(int $roomId): void
    {
        $room = HostelRoom::findOrFail($roomId);
        $this->editingRoomId = $room->id;
        $this->newRoomStatus = $room->status;
        $this->dispatch('modal-open', name: 'board-room-status-modal');
    }

    public function updateRoomStatus(): void
    {
        if (! $this->editingRoomId) {
            return;
        }

        try {
            $room = HostelRoom::findOrFail($this->editingRoomId);
            $room->update([
                'status' => $this->newRoomStatus,
                'updated_by' => auth()->id(),
            ]);

            // If room marked maintenance or reserved, also update beds that are not occupied
            if (in_array($this->newRoomStatus, ['maintenance', 'reserved'], true)) {
                $room->beds()->where('status', 'available')->update([
                    'status' => $this->newRoomStatus,
                    'updated_by' => auth()->id(),
                ]);
            } elseif ($this->newRoomStatus === 'available') {
                $room->beds()->whereIn('status', ['maintenance', 'reserved'])->update([
                    'status' => 'available',
                    'updated_by' => auth()->id(),
                ]);
            }

            HostelService::syncRoomStatus($room);

            Toast::dispatch($this, 'success', __('Room status updated to :status.', ['status' => ucfirst($this->newRoomStatus)]));
            $this->dispatch('modal-close', name: 'board-room-status-modal');
            $this->editingRoomId = null;
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
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Visual Room Board & Floor Map') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ __('Interactive Grid') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Real-time visual map of rooms, bed occupancy, gender wings, and one-click resident check-in.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.hostel.allocations') }}" wire:navigate>
                <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                    <x-icon name="list" class="w-4 h-4 mr-1.5" />
                    {{ __('Allocations Table') }}
                </x-ui.button>
            </a>

            <a href="{{ route('admin.hostel.rooms.index') }}" wire:navigate>
                <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                    <x-icon name="door-closed" class="w-4 h-4 mr-1.5" />
                    {{ __('Manage Rooms') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    <!-- Building Switcher Tabs -->
    <div class="flex flex-wrap items-center gap-2 border-b border-border pb-3">
        @foreach ($this->buildings as $b)
            <button
                type="button"
                wire:click="selectBuilding({{ $b->id }})"
                @class([
                    'flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer shadow-xs',
                    'bg-primary text-primary-foreground shadow-primary/20 shadow-md' => $selectedBuildingId === $b->id,
                    'bg-secondary/70 text-secondary-foreground hover:bg-secondary' => $selectedBuildingId !== $b->id,
                ])
            >
                <x-icon name="building-2" class="w-4 h-4" />
                <span>{{ $b->name }}</span>
                <span @class([
                    'px-1.5 py-0.2 rounded text-[10px] font-bold uppercase',
                    'bg-primary-foreground/20 text-primary-foreground' => $selectedBuildingId === $b->id,
                    'bg-secondary text-muted-foreground' => $selectedBuildingId !== $b->id,
                ])>
                    {{ $b->gender_label }}
                </span>
            </button>
        @endforeach
    </div>

    @if ($this->currentBuilding)
        @php $cb = $this->currentBuilding; @endphp
        <div class="p-3.5 rounded-xl border border-border bg-secondary/20 flex flex-wrap items-center justify-between gap-3 text-xs">
            <div class="flex flex-wrap items-center gap-3">
                <span class="font-bold text-foreground">{{ $cb->name }} ({{ $cb->code }})</span>
                @if ($cb->manager_name)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-background border border-border text-foreground">
                        <x-icon name="shield" class="w-3.5 h-3.5 text-amber-500" />
                        <strong>{{ __('Manager:') }}</strong> {{ $cb->manager_name }}
                        @if ($cb->manager_contact)
                            <span class="text-muted-foreground text-[11px]">({{ $cb->manager_contact }})</span>
                        @endif
                    </span>
                @endif
                @if ($cb->warden_name)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-background border border-border text-foreground">
                        <x-icon name="user" class="w-3.5 h-3.5 text-primary" />
                        <strong>{{ __('Warden:') }}</strong> {{ $cb->warden_name }}
                    </span>
                @endif
            </div>

            <!-- Caretakers on duty -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] font-semibold text-muted-foreground uppercase">{{ __('Caretakers:') }}</span>
                @forelse ($cb->caretakers as $ct)
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-background border border-border text-[11px] text-foreground">
                        <span class="w-1.5 h-1.5 rounded-full {{ $ct->is_primary ? 'bg-emerald-500' : 'bg-muted-foreground' }}"></span>
                        {{ $ct->name }}
                        <span class="text-[10px] text-muted-foreground">({{ ucfirst($ct->shift) }}: {{ $ct->phone }})</span>
                    </span>
                @empty
                    <span class="text-[11px] text-muted-foreground italic">{{ __('No caretakers listed') }}</span>
                @endforelse
            </div>
        </div>
    @endif

    <!-- Filters & Visual Legend Bar -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 p-4 rounded-2xl border border-border bg-card shadow-xs">
        <div class="flex flex-wrap items-center gap-3">
            <!-- Floor Filter -->
            <select
                wire:model.live="selectedFloorId"
                class="h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
            >
                <option value="">{{ __('All Floors') }}</option>
                @foreach ($this->floors as $fl)
                    <option value="{{ $fl->id }}">{{ $fl->name }}</option>
                @endforeach
            </select>

            <!-- Room Type -->
            <select
                wire:model.live="roomTypeFilter"
                class="h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
            >
                <option value="all">{{ __('All Room Types') }}</option>
                <option value="non_sharing">{{ __('Single (Non-Sharing)') }}</option>
                <option value="sharing">{{ __('Sharing Rooms') }}</option>
            </select>

            <!-- Gender Indicator Filter -->
            <select
                wire:model.live="genderFilter"
                class="h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
            >
                <option value="all">{{ __('All Gender Allowances') }}</option>
                <option value="male_only">{{ __('Male Only') }} ♂</option>
                <option value="female_only">{{ __('Female Only') }} ♀</option>
                <option value="any">{{ __('Any / Co-Ed') }} ⚧</option>
            </select>

            <!-- Room Status Filter -->
            <select
                wire:model.live="statusFilter"
                class="h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
            >
                <option value="all">{{ __('All Room Statuses') }}</option>
                <option value="available">{{ __('Available Rooms') }}</option>
                <option value="occupied">{{ __('Occupied Rooms') }}</option>
                <option value="reserved">{{ __('Reserved') }}</option>
                <option value="maintenance">{{ __('Maintenance') }}</option>
            </select>

            <!-- Search input -->
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search room or resident...') }}"
                    class="h-9 pl-8 pr-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none w-48"
                />
                <x-icon name="search" class="w-3.5 h-3.5 text-muted-foreground absolute left-2.5 top-3" />
            </div>
        </div>

        <!-- Visual Bed Status Legend -->
        <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span class="font-semibold text-foreground uppercase tracking-wider text-[10px]">{{ __('Legend:') }}</span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                <span>{{ __('Free (Available)') }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-blue-600"></span>
                <span>{{ __('Occupied') }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                <span>{{ __('Reserved') }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-rose-500"></span>
                <span>{{ __('Maintenance') }}</span>
            </span>
        </div>
    </div>

    <!-- Floors & Rooms Visual Floor Plan Grid -->
    <div class="space-y-8">
        @forelse ($this->roomsGroupedByFloor as $floorId => $rooms)
            @php
                $floor = $rooms->first()?->floor;
            @endphp
            <div class="space-y-4" wire:key="floor-group-{{ $floorId }}">
                <!-- Floor Section Banner -->
                <div class="flex items-center justify-between pb-2 border-b border-border">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-xl bg-secondary text-foreground">
                            <x-icon name="layers" class="w-4 h-4" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-foreground">
                                {{ $floor->name ?? __('Floor') }}
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                {{ $rooms->count() }} {{ __('Rooms') }} &bull; {{ $rooms->sum('capacity') }} {{ __('Total Beds') }}
                            </p>
                        </div>
                    </div>

                    @if ($floor && $floor->gender_type)
                        <span @class([
                            'px-2.5 py-1 rounded-full text-xs font-semibold border',
                            'bg-blue-500/10 text-blue-600 border-blue-500/20' => $floor->gender_type === 'boys_only',
                            'bg-pink-500/10 text-pink-600 border-pink-500/20' => $floor->gender_type === 'girls_only',
                            'bg-purple-500/10 text-purple-600 border-purple-500/20' => $floor->gender_type === 'coed',
                        ])>
                            {{ $floor->gender_label }}
                        </span>
                    @endif
                </div>

                <!-- Rooms Grid in this Floor -->
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    @foreach ($rooms as $room)
                        @php
                            $roomBeds = $room->beds;
                            $occupiedCount = $roomBeds->where('status', 'occupied')->count();
                            $freeCount = $roomBeds->where('status', 'available')->count();
                        @endphp
                        <div
                            @class([
                                'rounded-2xl border transition-all duration-200 bg-card shadow-xs overflow-hidden flex flex-col justify-between',
                                'border-border hover:border-primary/50' => $room->status !== 'maintenance',
                                'border-rose-500/40 bg-rose-500/5' => $room->status === 'maintenance',
                            ])
                            wire:key="room-card-{{ $room->id }}"
                        >
                            <!-- Room Header -->
                            <div class="p-4 border-b border-border/70 bg-secondary/30">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-lg font-extrabold text-foreground tracking-tight">
                                                {{ $room->room_number }}
                                            </span>

                                            <!-- Room Type Badge -->
                                            <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase bg-secondary text-secondary-foreground border border-border">
                                                {{ $room->isSharing() ? $room->capacity . '-Sharing' : __('Single') }}
                                            </span>
                                        </div>

                                        <div class="flex items-center gap-2 mt-1.5">
                                            <!-- Gender Indicator Badge -->
                                            <span @class([
                                                'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold',
                                                'bg-blue-500/10 text-blue-600 dark:text-blue-400' => $room->gender_allowed === 'male_only',
                                                'bg-pink-500/10 text-pink-600 dark:text-pink-400' => $room->gender_allowed === 'female_only',
                                                'bg-purple-500/10 text-purple-600 dark:text-purple-400' => $room->gender_allowed === 'any',
                                            ])>
                                                @if ($room->gender_allowed === 'male_only')
                                                    <span>♂</span> {{ __('Male Only') }}
                                                @elseif ($room->gender_allowed === 'female_only')
                                                    <span>♀</span> {{ __('Female Only') }}
                                                @else
                                                    <span>⚧</span> {{ __('Co-Ed / Open') }}
                                                @endif
                                            </span>

                                            <!-- Room Occupancy Ratio -->
                                            <span class="text-[11px] text-muted-foreground">
                                                {{ $occupiedCount }}/{{ $room->capacity }} {{ __('Occupied') }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Room Status & Options Menu -->
                                    <div class="flex items-center gap-1.5">
                                        <span @class([
                                            'px-2 py-0.5 rounded text-[10px] font-bold uppercase',
                                            'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20' => $room->status === 'available',
                                            'bg-blue-500/10 text-blue-600 border border-blue-500/20' => $room->status === 'occupied',
                                            'bg-amber-500/10 text-amber-600 border border-amber-500/20' => $room->status === 'reserved',
                                            'bg-rose-500/10 text-rose-600 border border-rose-500/20' => $room->status === 'maintenance',
                                        ])>
                                            {{ ucfirst($room->status) }}
                                        </span>

                                        <button
                                            type="button"
                                            wire:click="openRoomStatusModal({{ $room->id }})"
                                            class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer"
                                            title="{{ __('Change Room Status') }}"
                                        >
                                            <x-icon name="settings" class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Amenities icons if present -->
                                @if (! empty($room->amenities) && is_array($room->amenities))
                                    <div class="flex flex-wrap items-center gap-1 mt-2.5">
                                        @foreach ($room->amenities as $am)
                                            <span class="px-1.5 py-0.5 rounded bg-background/80 text-[10px] font-medium text-muted-foreground border border-border/50">
                                                {{ ucfirst(str_replace('_', ' ', $am)) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <!-- Beds Container Inside the Room Card -->
                            <div class="p-4 space-y-2.5">
                                <span class="text-[10px] font-bold text-muted-foreground uppercase tracking-wider block">
                                    {{ __('Beds in Room') }}:
                                </span>

                                <div class="space-y-2">
                                    @forelse ($roomBeds as $bed)
                                        @php
                                            $activeAlloc = $bed->currentAllocation;
                                        @endphp
                                        @if ($bed->status === 'available')
                                            <!-- Free / Available Bed Card (Clickable to allocate) -->
                                            <button
                                                type="button"
                                                wire:click="openAllocateModal({{ $bed->id }})"
                                                class="w-full p-2.5 rounded-xl border border-dashed border-emerald-500/40 bg-emerald-500/5 hover:bg-emerald-500/15 hover:border-emerald-500 transition-all flex items-center justify-between text-left cursor-pointer group"
                                            >
                                                <div class="flex items-center gap-2.5">
                                                    <span class="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 group-hover:scale-110 transition-transform">
                                                        <x-icon name="bed" class="w-4 h-4" />
                                                    </span>
                                                    <div>
                                                        <span class="font-bold text-xs text-foreground block">{{ $bed->bed_number }}</span>
                                                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold uppercase">{{ __('Available - Click to Allocate') }}</span>
                                                    </div>
                                                </div>

                                                <span class="p-1 rounded-md bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 text-xs font-bold">
                                                    + {{ __('Allot') }}
                                                </span>
                                            </button>
                                        @elseif ($bed->status === 'occupied' && $activeAlloc)
                                            <!-- Occupied Bed Card (Clickable to view resident dossier) -->
                                            <button
                                                type="button"
                                                wire:click="openResidentDossier({{ $activeAlloc->id }})"
                                                class="w-full p-2.5 rounded-xl border border-blue-500/30 bg-blue-500/5 hover:bg-blue-500/15 hover:border-blue-500 transition-all flex items-center justify-between text-left cursor-pointer group"
                                            >
                                                <div class="flex items-center gap-2.5">
                                                    <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-xs">
                                                        {{ substr($activeAlloc->user->name ?? 'R', 0, 2) }}
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="font-bold text-xs text-foreground truncate block max-w-[130px]">
                                                                {{ $activeAlloc->user->name ?? __('Occupied') }}
                                                            </span>
                                                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-blue-500/20 text-blue-700 dark:text-blue-300">
                                                                {{ $activeAlloc->resident_type }}
                                                            </span>
                                                        </div>
                                                        <span class="text-[10px] text-muted-foreground block truncate">
                                                            {{ $bed->bed_number }} &bull; {{ $activeAlloc->start_date->format('d M y') }}
                                                            @if ($activeAlloc->end_date)
                                                                - {{ $activeAlloc->end_date->format('d M y') }}
                                                            @endif
                                                        </span>
                                                    </div>
                                                </div>

                                                <div class="text-right shrink-0">
                                                    @if ($activeAlloc->hard_copy_submitted)
                                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-600 dark:text-emerald-400">
                                                            <x-icon name="file-check" class="w-3 h-3" />
                                                            {{ __('Verified') }}
                                                        </span>
                                                    @else
                                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-600 dark:text-amber-400">
                                                            <x-icon name="alert-circle" class="w-3 h-3" />
                                                            {{ __('Form Due') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </button>
                                        @elseif ($bed->status === 'reserved')
                                            <!-- Reserved Bed Card -->
                                            <div class="p-2.5 rounded-xl border border-amber-500/30 bg-amber-500/5 flex items-center justify-between">
                                                <div class="flex items-center gap-2">
                                                    <span class="p-1.5 rounded-lg bg-amber-500/10 text-amber-600">
                                                        <x-icon name="bookmark" class="w-4 h-4" />
                                                    </span>
                                                    <div>
                                                        <span class="font-bold text-xs text-foreground block">{{ $bed->bed_number }}</span>
                                                        <span class="text-[10px] text-amber-600 font-semibold uppercase">{{ __('Reserved Bed') }}</span>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    wire:click="openAllocateModal({{ $bed->id }})"
                                                    class="text-xs text-amber-600 font-bold hover:underline cursor-pointer"
                                                >
                                                    {{ __('Fulfill Allotment') }}
                                                </button>
                                            </div>
                                        @else
                                            <!-- Maintenance Bed Card -->
                                            <div class="p-2.5 rounded-xl border border-rose-500/30 bg-rose-500/5 flex items-center justify-between">
                                                <div class="flex items-center gap-2">
                                                    <span class="p-1.5 rounded-lg bg-rose-500/10 text-rose-600">
                                                        <x-icon name="tool" class="w-4 h-4" />
                                                    </span>
                                                    <div>
                                                        <span class="font-bold text-xs text-foreground block">{{ $bed->bed_number }}</span>
                                                        <span class="text-[10px] text-rose-600 font-semibold uppercase">{{ __('Under Maintenance') }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @empty
                                        <div class="p-3 text-center text-xs text-muted-foreground border border-dashed rounded-lg">
                                            {{ __('No beds registered in this room.') }}
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="p-12 text-center rounded-2xl border border-dashed border-border bg-card">
                <x-icon name="bed" class="w-12 h-12 text-muted-foreground mx-auto mb-3" />
                <h3 class="text-base font-bold text-foreground">{{ __('No Rooms Found Matching Criteria') }}</h3>
                <p class="text-xs text-muted-foreground mt-1">{{ __('Try adjusting your building, floor, gender, or status filters.') }}</p>
            </div>
        @endforelse
    </div>

    <!-- Modal: Allocate Resident Directly to Bed -->
    <x-ui.modal name="board-allocate-modal" max-width="max-w-2xl" :title="__('Allot Hostel Room & Bed')">
        <form wire:submit="saveAllocation" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Resident Type -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Resident Category') }} *</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border bg-background cursor-pointer text-xs font-medium">
                            <input type="radio" value="student" wire:model.live="residentType" name="resType" />
                            <span>{{ __('Student') }}</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border bg-background cursor-pointer text-xs font-medium">
                            <input type="radio" value="staff" wire:model.live="residentType" name="resType" />
                            <span>{{ __('Faculty / Staff') }}</span>
                        </label>
                    </div>
                </div>

                <!-- Select User -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Select Person to Accommodate') }} *</label>
                    <select
                        wire:model="selectedUserId"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        required
                    >
                        <option value="">{{ __('-- Select Resident --') }}</option>
                        @foreach ($this->availableUsers as $u)
                            <option value="{{ $u->id }}">
                                {{ $u->name }} @if ($u->student_id) ({{ $u->student_id }}) @endif - {{ ucfirst($u->gender ?? $u->studentProfile?->gender ?? 'N/A') }}
                            </option>
                        @endforeach
                    </select>
                    @error('selectedUserId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Previous Batch Student Toggle -->
            <div class="p-3.5 rounded-xl border border-amber-500/30 bg-amber-500/5 space-y-2">
                <label class="flex items-center gap-2 text-xs font-bold text-amber-900 dark:text-amber-200 cursor-pointer">
                    <input type="checkbox" wire:model.live="isPreviousBatchStudent" class="rounded text-primary focus:ring-primary" />
                    <span>{{ __('Previous Batch Student / Historical Resident') }}</span>
                </label>
                <p class="text-[11px] text-muted-foreground">
                    {{ __('Enable this if the student has been residing in the hostel for past batches/years.') }}
                </p>

                @if ($isPreviousBatchStudent)
                    <div class="pt-2">
                        <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Past Batch Name / Academic Reference') }} *</label>
                        <input
                            type="text"
                            wire:model="previousBatchName"
                            placeholder="{{ __('e.g. UPSC Prelims 2023, WBCS Foundation 2022') }}"
                            class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        />
                        @error('previousBatchName') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            <!-- Timeline Configuration -->
            <div class="p-3.5 rounded-xl border border-border bg-secondary/20 space-y-3">
                <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('Accommodation Timeline & Duration (Optional)') }}
                </span>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Start Date / Check-In') }} *</label>
                        <input
                            type="date"
                            wire:model="startDate"
                            class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            required
                        />
                        @error('startDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Timeline Calculation') }}</label>
                        <select
                            wire:model.live="durationType"
                            class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        >
                            <option value="months">{{ __('Duration in Months') }}</option>
                            <option value="days">{{ __('Duration in Days') }}</option>
                            <option value="years">{{ __('Duration in Years') }}</option>
                            <option value="custom">{{ __('Custom End Date') }}</option>
                            <option value="indefinite">{{ __('Indefinite / Open Stay') }}</option>
                        </select>
                    </div>

                    @if (in_array($durationType, ['days', 'months', 'years'], true))
                        <div>
                            <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Duration Value') }} *</label>
                            <input
                                type="number"
                                wire:model="durationValue"
                                min="1"
                                class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                            @error('durationValue') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    @elseif ($durationType === 'custom')
                        <div>
                            <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Specific End Date') }} *</label>
                            <input
                                type="date"
                                wire:model="customEndDate"
                                class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            />
                            @error('customEndDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    @endif
                </div>
            </div>

            <!-- Hard Copy & Soft Copy Form Tracking -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Hard Copy Tracking -->
                <div class="p-3 rounded-xl border border-border bg-background space-y-2">
                    <label class="flex items-center gap-2 text-xs font-bold text-foreground cursor-pointer">
                        <input type="checkbox" wire:model.live="hardCopySubmitted" class="rounded text-primary focus:ring-primary" />
                        <span>{{ __('Hard-Copy Form Submitted to Office') }}</span>
                    </label>

                    @if ($hardCopySubmitted)
                        <div class="space-y-2 pt-1">
                            <div>
                                <label class="block text-[11px] text-muted-foreground mb-0.5">{{ __('Date Received') }}</label>
                                <input
                                    type="date"
                                    wire:model="hardCopySubmittedAt"
                                    class="w-full h-8 px-2 text-xs rounded-md border border-border bg-background"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] text-muted-foreground mb-0.5">{{ __('Filing Shelf / Ref No') }}</label>
                                <input
                                    type="text"
                                    wire:model="hardCopyNotes"
                                    placeholder="{{ __('e.g. File #Hostel-2026/Ganga') }}"
                                    class="w-full h-8 px-2 text-xs rounded-md border border-border bg-background"
                                />
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Soft Copy Upload -->
                <div class="p-3 rounded-xl border border-border bg-background space-y-2">
                    <label class="block text-xs font-bold text-foreground">
                        {{ __('Upload Soft-Copy Form (Optional)') }}
                    </label>
                    <input
                        type="file"
                        wire:model="softCopyFile"
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full text-xs text-muted-foreground file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-secondary file:text-foreground hover:file:bg-secondary/80"
                    />
                    <span class="text-[10px] text-muted-foreground block">
                        {{ __('Accepted formats: PDF, JPG, PNG (Max: 5MB)') }}
                    </span>
                    @error('softCopyFile') <span class="text-xs text-rose-500 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Remarks / Notes -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Remarks / Administrative Notes (Optional)') }}</label>
                <textarea
                    wire:model="remarks"
                    rows="2"
                    placeholder="{{ __('Special room requirements, medical alerts, fee concessions, or hostel office notes...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('board-allocate-modal')"
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
                    <span wire:loading.remove wire:target="saveAllocation">{{ __('Confirm Allocation') }}</span>
                    <span wire:loading wire:target="saveAllocation">{{ __('Allocating...') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Resident Dossier / Detail View -->
    <x-ui.modal name="board-resident-modal" max-width="max-w-xl" :title="__('Hostel Resident Dossier')">
        @if ($this->viewingAllocation)
            @php
                $res = $this->viewingAllocation;
                $u = $res->user;
            @endphp
            <div class="space-y-4">
                <!-- Resident Identity Strip -->
                <div class="p-4 rounded-xl border border-border bg-secondary/30 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-primary/20 text-primary flex items-center justify-center font-extrabold text-base">
                            {{ substr($u->name ?? 'U', 0, 2) }}
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ $u->name }}</h3>
                            <div class="flex items-center gap-2 text-xs text-muted-foreground mt-0.5">
                                @if ($u->student_id)
                                    <span>ID: {{ $u->student_id }}</span> &bull;
                                @endif
                                <span>{{ $u->email }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="text-right">
                        <span class="px-2 py-0.5 rounded text-xs font-bold uppercase bg-primary text-primary-foreground">
                            {{ $res->resident_type }}
                        </span>
                        <div class="text-[11px] text-muted-foreground mt-1">
                            {{ __('Allotment #') }}{{ $res->allocation_no }}
                        </div>
                    </div>
                </div>

                <!-- Enrolled Academic Details (if enrolled) -->
                @if ($res->isResidentStudent() || $res->course_name)
                    <div class="p-3.5 rounded-xl border border-primary/20 bg-primary/5 space-y-2">
                        <span class="text-xs font-bold text-primary uppercase tracking-wider block">
                            {{ __('Student Enrollment & Academic Details') }}
                        </span>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Student ID / URN') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $res->student_identifier }}</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Enrollment ID') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $res->enrollment_no }}</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Course & Batch') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $res->course_name }} ({{ $res->batch_name }})</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Section') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $res->section_name }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Room & Bed Allotment Details -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Building') }}</span>
                        <span class="text-xs font-bold text-foreground mt-0.5 block">{{ $res->room->building->name ?? 'N/A' }}</span>
                    </div>
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Floor & Room') }}</span>
                        <span class="text-xs font-bold text-foreground mt-0.5 block">{{ $res->room->room_number ?? 'N/A' }}</span>
                    </div>
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Assigned Bed') }}</span>
                        <span class="text-xs font-bold text-primary mt-0.5 block">{{ $res->bed->bed_number ?? 'N/A' }}</span>
                    </div>
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Gender Allowed') }}</span>
                        <span class="text-xs font-bold text-foreground mt-0.5 block">{{ $res->room->gender_label }}</span>
                    </div>
                </div>

                <!-- Hostel Management & Caretakers on Duty -->
                <div class="p-3.5 rounded-xl border border-border bg-background space-y-2 text-xs">
                    <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                        {{ __('Hostel Administration & Caretakers') }}
                    </span>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                        <div class="p-2 rounded-lg bg-secondary/30 border border-border">
                            <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Hostel Manager') }}</span>
                            <span class="font-bold text-foreground">{{ $res->room?->building?->manager_name ?? __('Not Assigned') }}</span>
                            @if ($res->room?->building?->manager_contact)
                                <span class="text-[11px] text-muted-foreground block">{{ $res->room->building->manager_contact }}</span>
                            @endif
                        </div>
                        <div class="p-2 rounded-lg bg-secondary/30 border border-border">
                            <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Hostel Warden') }}</span>
                            <span class="font-bold text-foreground">{{ $res->room?->building?->warden_name ?? __('Not Assigned') }}</span>
                            @if ($res->room?->building?->warden_contact)
                                <span class="text-[11px] text-muted-foreground block">{{ $res->room->building->warden_contact }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($res->room?->building?->caretakers && $res->room->building->caretakers->isNotEmpty())
                        <div class="pt-1.5 flex flex-wrap items-center gap-1.5">
                            <span class="text-[10px] font-semibold text-muted-foreground uppercase">{{ __('Caretakers on duty:') }}</span>
                            @foreach ($res->room->building->caretakers as $ct)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-secondary text-foreground border border-border">
                                    {{ $ct->name }} ({{ ucfirst($ct->shift) }}: {{ $ct->phone }})
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Timeline & Stays -->
                <div class="p-3.5 rounded-xl border border-border bg-background space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-semibold text-muted-foreground">{{ __('Check-In / Start Date') }}:</span>
                        <span class="font-bold text-foreground">{{ $res->start_date->format('d M Y') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-semibold text-muted-foreground">{{ __('Accommodation End Date') }}:</span>
                        <span class="font-bold text-foreground">
                            {{ $res->end_date ? $res->end_date->format('d M Y') : __('Indefinite') }}
                        </span>
                    </div>
                    @if ($res->end_date)
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-semibold text-muted-foreground">{{ __('Remaining Stay') }}:</span>
                            <span @class([
                                'font-bold',
                                'text-emerald-600 dark:text-emerald-400' => $res->daysRemaining() > 15,
                                'text-amber-600 dark:text-amber-400' => $res->daysRemaining() <= 15 && $res->daysRemaining() >= 0,
                                'text-rose-600 dark:text-rose-400' => $res->daysRemaining() < 0,
                            ])>
                                {{ $res->daysRemaining() }} {{ __('Days Remaining') }}
                            </span>
                        </div>
                    @endif
                </div>

                <!-- Form Tracking Status -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <!-- Hardcopy -->
                    <div class="p-3 rounded-xl border border-border bg-background flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-foreground block">{{ __('Hardcopy Admission Form') }}</span>
                            <span class="text-[11px] text-muted-foreground">
                                {{ $res->hard_copy_submitted ? __('Submitted on ') . $res->hard_copy_submitted_at?->format('d M Y') : __('Pending Physical Submission') }}
                            </span>
                        </div>
                        <x-ui.button
                            type="button"
                            variant="outline"
                            size="sm"
                            wire:click="toggleHardCopyStatus({{ $res->id }})"
                            class="text-xs h-7 px-2"
                        >
                            {{ $res->hard_copy_submitted ? __('Mark Pending') : __('Mark Submitted') }}
                        </x-ui.button>
                    </div>

                    <!-- Softcopy -->
                    <div class="p-3 rounded-xl border border-border bg-background flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-foreground block">{{ __('Softcopy Form') }}</span>
                            <span class="text-[11px] text-muted-foreground">
                                {{ $res->soft_copy_path ? __('Uploaded on system') : __('No softcopy uploaded') }}
                            </span>
                        </div>
                        @if ($res->soft_copy_path)
                            <a
                                href="{{ Storage::disk('public')->url($res->soft_copy_path) }}"
                                target="_blank"
                                class="text-xs font-bold text-primary hover:underline inline-flex items-center gap-1"
                            >
                                <x-icon name="download" class="w-3.5 h-3.5" />
                                {{ __('View') }}
                            </a>
                        @endif
                    </div>
                </div>

                <!-- Remarks -->
                @if ($res->remarks)
                    <div class="p-3 rounded-lg bg-secondary/50 text-xs">
                        <span class="font-bold text-foreground block mb-1">{{ __('Remarks & Notes') }}:</span>
                        <p class="text-muted-foreground whitespace-pre-line">{{ $res->remarks }}</p>
                    </div>
                @endif

                <!-- Dossier Action Footer -->
                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        wire:click="openVacateModalFromDossier"
                        class="text-rose-600 dark:text-rose-400 border-rose-500/30 hover:bg-rose-500/10 cursor-pointer"
                    >
                        <x-icon name="user-minus" class="w-4 h-4 mr-1.5" />
                        {{ __('Vacate Resident') }}
                    </x-ui.button>

                    <div class="flex items-center gap-2">
                        <a
                            href="{{ route('hostel.form.download', ['allocation_id' => $res->id]) }}"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-secondary hover:bg-secondary/80 text-foreground border border-border transition-colors cursor-pointer"
                        >
                            <x-icon name="file-down" class="w-3.5 h-3.5 text-primary" />
                            {{ __('Download Form (PDF)') }}
                        </a>

                        <x-ui.button
                            type="button"
                            variant="outline"
                            size="sm"
                            x-on:click="$store.modals.close('board-resident-modal')"
                        >
                            {{ __('Close') }}
                        </x-ui.button>

                        <x-ui.button
                            type="button"
                            variant="default"
                            size="sm"
                            wire:click="openExtendModalFromDossier"
                            class="bg-primary text-primary-foreground cursor-pointer"
                        >
                            <x-icon name="calendar-plus" class="w-4 h-4 mr-1.5" />
                            {{ __('Extend Stay Timeline') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Modal: Extend Stay Timeline -->
    <x-ui.modal name="board-extend-modal" max-width="max-w-md" :title="__('Extend Room Accommodation')">
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
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Extension Notes') }}</label>
                <textarea
                    wire:model="extensionNotes"
                    rows="3"
                    placeholder="{{ __('Reason for extension...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('board-extend-modal')"
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
                    {{ __('Save Extension') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Vacate Bed -->
    <x-ui.modal name="board-vacate-modal" max-width="max-w-md" :title="__('Vacate Resident from Bed')">
        <form wire:submit="processVacate" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Vacated Date') }} *</label>
                <input
                    type="date"
                    wire:model="vacateDate"
                    class="w-full h-10 px-3 text-sm rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Remarks') }}</label>
                <textarea
                    wire:model="vacateRemarks"
                    rows="3"
                    placeholder="{{ __('Dues cleared, room key handed over, etc.') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('board-vacate-modal')"
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
                    {{ __('Confirm Vacate') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Switch Room Status -->
    <x-ui.modal name="board-room-status-modal" max-width="max-w-sm" :title="__('Change Room Status')">
        <form wire:submit="updateRoomStatus" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Room Status') }}</label>
                <select
                    wire:model="newRoomStatus"
                    class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="available">{{ __('Available') }}</option>
                    <option value="reserved">{{ __('Reserved') }}</option>
                    <option value="maintenance">{{ __('Maintenance') }}</option>
                    <option value="inactive">{{ __('Inactive / Closed') }}</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('board-room-status-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                >
                    {{ __('Update') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
