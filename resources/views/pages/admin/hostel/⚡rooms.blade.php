<?php

use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Services\Hostel\HostelImportExportService;
use App\Services\Hostel\HostelService;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Hostel Rooms & Beds')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'building')]
    public ?int $buildingFilter = null;

    // Import State
    public $importFile = null;
    public ?array $importSummary = null;

    #[Url(as: 'floor')]
    public ?int $floorFilter = null;

    #[Url(as: 'type')]
    public string $roomTypeFilter = 'all';

    #[Url(as: 'gender')]
    public string $genderFilter = 'all';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 15;

    // Room Create / Edit Modal State
    public ?int $editingRoomId = null;
    public ?int $rBuildingId = null;
    public ?int $rFloorId = null;
    public string $rRoomNumber = '';
    public string $rRoomType = 'sharing'; // sharing, non_sharing
    public int $rCapacity = 2;
    public string $rGenderAllowed = 'male_only'; // male_only, female_only, any
    public string $rStatus = 'available';
    public array $rAmenities = [];
    public string $rNotes = '';

    // Manage Beds Modal State
    public ?int $viewingBedsRoomId = null;
    public string $newBedNumber = '';

    // Delete Modal
    public ?int $deletingRoomId = null;

    public function mount(): void
    {
        if (! $this->rBuildingId) {
            $first = HostelBuilding::active()->first();
            $this->rBuildingId = $first?->id;
        }
    }

    public function updatedBuildingFilter(): void
    {
        $this->floorFilter = null;
        $this->resetPage();
    }

    public function updatedRBuildingId(): void
    {
        $this->rFloorId = null;
    }

    public function updatedRRoomType(string $val): void
    {
        if ($val === 'non_sharing') {
            $this->rCapacity = 1;
        } elseif ($this->rCapacity === 1) {
            $this->rCapacity = 2;
        }
    }

    #[Computed]
    public function buildings()
    {
        return HostelBuilding::active()->orderBy('display_order')->get();
    }

    #[Computed]
    public function filterFloors()
    {
        if (! $this->buildingFilter) {
            return collect();
        }

        return HostelFloor::where('hostel_building_id', $this->buildingFilter)
            ->active()
            ->orderBy('floor_number')
            ->get();
    }

    #[Computed]
    public function formFloors()
    {
        if (! $this->rBuildingId) {
            return collect();
        }

        return HostelFloor::where('hostel_building_id', $this->rBuildingId)
            ->active()
            ->orderBy('floor_number')
            ->get();
    }

    #[Computed]
    public function viewingRoom(): ?HostelRoom
    {
        if (! $this->viewingBedsRoomId) {
            return null;
        }

        return HostelRoom::with(['beds.currentAllocation.user', 'building', 'floor'])->find($this->viewingBedsRoomId);
    }

    public function render()
    {
        $query = HostelRoom::with([
            'building',
            'floor',
            'beds.currentAllocation.user',
        ]);

        if ($this->buildingFilter) {
            $query->where('hostel_building_id', $this->buildingFilter);
        }

        if ($this->floorFilter) {
            $query->where('hostel_floor_id', $this->floorFilter);
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
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('room_number', 'like', "%{$s}%")
                    ->orWhereHas('building', fn ($bq) => $bq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('beds.currentAllocation.user', fn ($uq) => $uq->where('name', 'like', "%{$s}%"));
            });
        }

        $rooms = $query->orderBy('hostel_building_id')
            ->orderBy('hostel_floor_id')
            ->orderBy('room_number')
            ->paginate($this->perPage);

        return view('pages.admin.hostel.⚡rooms', [
            'rooms' => $rooms,
        ]);
    }

    public function openRoomModal(?int $id = null): void
    {
        $this->resetErrorBag();
        if ($id) {
            $r = HostelRoom::findOrFail($id);
            $this->editingRoomId = $r->id;
            $this->rBuildingId = $r->hostel_building_id;
            $this->rFloorId = $r->hostel_floor_id;
            $this->rRoomNumber = $r->room_number;
            $this->rRoomType = $r->room_type;
            $this->rCapacity = $r->capacity;
            $this->rGenderAllowed = $r->gender_allowed;
            $this->rStatus = $r->status;
            $this->rAmenities = $r->amenities ?? [];
            $this->rNotes = $r->notes ?? '';
        } else {
            $this->editingRoomId = null;
            $this->rBuildingId = $this->buildingFilter ?: ($this->buildings->first()?->id);
            $this->rFloorId = null;
            $this->rRoomNumber = '';
            $this->rRoomType = 'sharing';
            $this->rCapacity = 2;
            $this->rGenderAllowed = 'male_only';
            $this->rStatus = 'available';
            $this->rAmenities = ['study_table', 'wifi'];
            $this->rNotes = '';
        }

        $this->dispatch('modal-open', name: 'room-form-modal');
    }

    public function saveRoom(): void
    {
        $this->validate([
            'rBuildingId' => 'required|exists:hostel_buildings,id',
            'rFloorId' => 'required|exists:hostel_floors,id',
            'rRoomNumber' => 'required|string|max:50',
            'rRoomType' => 'required|in:sharing,non_sharing',
            'rCapacity' => 'required|integer|min:1|max:20',
            'rGenderAllowed' => 'required|in:male_only,female_only,any',
            'rStatus' => 'required|in:available,occupied,reserved,maintenance,inactive',
            'rAmenities' => 'nullable|array',
            'rNotes' => 'nullable|string|max:500',
        ]);

        try {
            $isNew = ! $this->editingRoomId;
            $room = HostelRoom::updateOrCreate(
                ['id' => $this->editingRoomId],
                [
                    'hostel_building_id' => $this->rBuildingId,
                    'hostel_floor_id' => $this->rFloorId,
                    'room_number' => trim($this->rRoomNumber),
                    'room_type' => $this->rRoomType,
                    'capacity' => $this->rRoomType === 'non_sharing' ? 1 : $this->rCapacity,
                    'gender_allowed' => $this->rGenderAllowed,
                    'status' => $this->rStatus,
                    'amenities' => $this->rAmenities,
                    'notes' => $this->rNotes ?: null,
                    'updated_by' => auth()->id(),
                    'created_by' => $isNew ? auth()->id() : null,
                ]
            );

            // Auto-generate beds for newly created rooms if none exist
            if ($isNew) {
                $targetCount = $room->capacity;
                for ($i = 1; $i <= $targetCount; $i++) {
                    $bedLabel = $targetCount === 1 ? 'Bed 1' : ($targetCount <= 4 ? 'Bed '.chr(64 + $i) : "Bed {$i}");
                    HostelBed::create([
                        'hostel_room_id' => $room->id,
                        'bed_number' => $bedLabel,
                        'status' => 'available',
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            HostelService::syncRoomStatus($room);

            Toast::dispatch($this, 'success', $isNew ? __('Room and beds created successfully.') : __('Room updated successfully.'));
            $this->dispatch('modal-close', name: 'room-form-modal');
            $this->editingRoomId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openBedsModal(int $roomId): void
    {
        $this->viewingBedsRoomId = $roomId;
        $this->newBedNumber = '';
        $this->dispatch('modal-open', name: 'manage-beds-modal');
    }

    public function addBedToRoom(): void
    {
        $this->validate([
            'newBedNumber' => 'required|string|max:50',
        ]);

        if (! $this->viewingBedsRoomId) {
            return;
        }

        try {
            $room = HostelRoom::findOrFail($this->viewingBedsRoomId);

            HostelBed::create([
                'hostel_room_id' => $room->id,
                'bed_number' => trim($this->newBedNumber),
                'status' => 'available',
                'created_by' => auth()->id(),
            ]);

            // Update room capacity
            $room->update([
                'capacity' => $room->beds()->count(),
                'room_type' => $room->beds()->count() > 1 ? 'sharing' : 'non_sharing',
            ]);

            HostelService::syncRoomStatus($room);

            Toast::dispatch($this, 'success', __('Bed added to room.'));
            $this->newBedNumber = '';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function toggleBedStatus(int $bedId, string $status): void
    {
        try {
            $bed = HostelBed::findOrFail($bedId);
            if ($bed->status === 'occupied') {
                Toast::dispatch($this, 'warning', __('Cannot modify status of an occupied bed. Please vacate resident first.'));

                return;
            }

            $bed->update([
                'status' => $status,
                'updated_by' => auth()->id(),
            ]);

            HostelService::syncRoomStatus($bed->room);
            Toast::dispatch($this, 'success', __('Bed status set to :status.', ['status' => ucfirst($status)]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function confirmDeleteRoom(int $id): void
    {
        $this->deletingRoomId = $id;
        $this->dispatch('modal-open', name: 'delete-room-modal');
    }

    public function deleteRoom(): void
    {
        if (! $this->deletingRoomId) {
            return;
        }

        try {
            $room = HostelRoom::findOrFail($this->deletingRoomId);
            if ($room->activeAllocations()->exists()) {
                Toast::dispatch($this, 'error', __('Cannot delete a room with active residents. Please vacate all residents first.'));
                $this->dispatch('modal-close', name: 'delete-room-modal');

                return;
            }

            $room->beds()->delete();
            $room->delete();

            Toast::dispatch($this, 'success', __('Room and beds archived successfully.'));
            $this->dispatch('modal-close', name: 'delete-room-modal');
            $this->deletingRoomId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function export(string $format): mixed
    {
        $query = HostelRoom::with(['building', 'floor', 'beds']);

        if ($this->buildingFilter) {
            $query->where('hostel_building_id', $this->buildingFilter);
        }

        if ($this->floorFilter) {
            $query->where('hostel_floor_id', $this->floorFilter);
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

        if ($this->search) {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('room_number', 'like', "%{$s}%")
                    ->orWhereHas('building', fn ($bq) => $bq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                    ->orWhereHas('floor', fn ($fq) => $fq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('beds', fn ($beq) => $beq->where('bed_number', 'like', "%{$s}%"));
            });
        }

        return HostelImportExportService::exportRooms($query, $format);
    }

    public function downloadTemplate(): mixed
    {
        return HostelImportExportService::downloadRoomsSampleTemplate();
    }

    public function openImportModal(): void
    {
        $this->reset(['importFile', 'importSummary']);
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'rooms-import-modal');
    }

    public function importRooms(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $res = HostelImportExportService::importRooms($path, auth()->id());
            $this->importSummary = $res;

            if ($res['success'] > 0) {
                Toast::dispatch($this, 'success', __(':count rooms and corresponding beds processed successfully.', ['count' => $res['success']]));
            } else {
                Toast::dispatch($this, 'error', __('No rooms could be imported. Please review the errors below.'));
            }
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
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Hostel Rooms & Beds Inventory') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ __('Room Config') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Configure room types, sharing bed counts, gender restrictions, and individual bed states.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Export Dropdown -->
            <div x-data="{ open: false }" class="relative">
                <x-ui.button
                    variant="outline"
                    size="sm"
                    x-on:click="open = !open"
                    class="cursor-pointer gap-1.5"
                >
                    <x-icon name="download" class="w-4 h-4 text-muted-foreground" />
                    <span>{{ __('Export') }}</span>
                    <x-icon name="chevron-down" class="w-3.5 h-3.5 text-muted-foreground ml-0.5" />
                </x-ui.button>
                <div
                    x-show="open"
                    x-on:click.outside="open = false"
                    x-transition
                    class="absolute right-0 mt-1 w-44 rounded-xl border border-border bg-card shadow-lg p-1 z-30 space-y-0.5"
                    style="display: none;"
                >
                    <button
                        type="button"
                        wire:click="export('xlsx')"
                        x-on:click="open = false"
                        class="w-full text-left px-3 py-2 text-xs rounded-lg hover:bg-secondary flex items-center gap-2 cursor-pointer transition-colors"
                    >
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600" />
                        <span>{{ __('Excel (.xlsx)') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="export('csv')"
                        x-on:click="open = false"
                        class="w-full text-left px-3 py-2 text-xs rounded-lg hover:bg-secondary flex items-center gap-2 cursor-pointer transition-colors"
                    >
                        <x-icon name="file-text" class="w-3.5 h-3.5 text-blue-600" />
                        <span>{{ __('CSV Document') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="export('pdf')"
                        x-on:click="open = false"
                        class="w-full text-left px-3 py-2 text-xs rounded-lg hover:bg-secondary flex items-center gap-2 cursor-pointer transition-colors"
                    >
                        <x-icon name="file" class="w-3.5 h-3.5 text-rose-600" />
                        <span>{{ __('PDF Document') }}</span>
                    </button>
                </div>
            </div>

            <!-- Import Button -->
            <x-ui.button
                variant="outline"
                size="sm"
                wire:click="openImportModal"
                class="cursor-pointer gap-1.5"
            >
                <x-icon name="upload" class="w-4 h-4 text-muted-foreground" />
                {{ __('Import Rooms') }}
            </x-ui.button>

            <a href="{{ route('admin.hostel.rooms.board') }}" wire:navigate>
                <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                    <x-icon name="layout-grid" class="w-4 h-4 mr-1.5" />
                    {{ __('Floor Map') }}
                </x-ui.button>
            </a>

            <x-ui.button
                variant="default"
                size="sm"
                wire:click="openRoomModal()"
                class="cursor-pointer bg-primary text-primary-foreground"
            >
                <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                {{ __('Add New Room') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="p-4 rounded-2xl border border-border bg-card shadow-xs">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <!-- Building -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Building') }}</label>
                <select
                    wire:model.live="buildingFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="">{{ __('All Buildings') }}</option>
                    @foreach ($this->buildings as $b)
                        <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->code }})</option>
                    @endforeach
                </select>
            </div>

            <!-- Floor -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Floor') }}</label>
                <select
                    wire:model.live="floorFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    @disabled(! $buildingFilter)
                >
                    <option value="">{{ __('All Floors') }}</option>
                    @foreach ($this->filterFloors as $fl)
                        <option value="{{ $fl->id }}">{{ $fl->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Room Type -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Room Type') }}</label>
                <select
                    wire:model.live="roomTypeFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="all">{{ __('All Types') }}</option>
                    <option value="non_sharing">{{ __('Single (Non-Sharing)') }}</option>
                    <option value="sharing">{{ __('Sharing') }}</option>
                </select>
            </div>

            <!-- Gender Indicator -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Gender Wing') }}</label>
                <select
                    wire:model.live="genderFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="all">{{ __('All Gender Allowances') }}</option>
                    <option value="male_only">{{ __('Male Only') }} ♂</option>
                    <option value="female_only">{{ __('Female Only') }} ♀</option>
                    <option value="any">{{ __('Any / Co-Ed') }} ⚧</option>
                </select>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Room Status') }}</label>
                <select
                    wire:model.live="statusFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="available">{{ __('Available') }}</option>
                    <option value="occupied">{{ __('Occupied') }}</option>
                    <option value="reserved">{{ __('Reserved') }}</option>
                    <option value="maintenance">{{ __('Maintenance') }}</option>
                </select>
            </div>

            <!-- Search -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Search') }}</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Room #, resident...') }}"
                    class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                />
            </div>
        </div>
    </div>

    <!-- Rooms Table -->
    <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase font-semibold border-b border-border">
                    <tr>
                        <th class="p-3.5">{{ __('Room Number') }}</th>
                        <th class="p-3.5">{{ __('Building & Floor') }}</th>
                        <th class="p-3.5">{{ __('Room Type & Beds') }}</th>
                        <th class="p-3.5">{{ __('Gender Allowance') }}</th>
                        <th class="p-3.5">{{ __('Occupancy Status') }}</th>
                        <th class="p-3.5">{{ __('Amenities') }}</th>
                        <th class="p-3.5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rooms as $r)
                        @php
                            $beds = $r->beds;
                            $occupied = $beds->where('status', 'occupied')->count();
                            $free = $beds->where('status', 'available')->count();
                        @endphp
                        <tr class="hover:bg-secondary/20 transition-colors" wire:key="room-row-{{ $r->id }}">
                            <!-- Room Number -->
                            <td class="p-3.5 font-bold text-foreground">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-extrabold text-foreground">{{ $r->room_number }}</span>
                                    @if ($r->status === 'maintenance')
                                        <span class="p-1 rounded bg-rose-500/10 text-rose-600" title="{{ __('Under maintenance') }}">
                                            <x-icon name="tool" class="w-3.5 h-3.5" />
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <!-- Building & Floor -->
                            <td class="p-3.5">
                                <div class="font-semibold text-foreground">{{ $r->building->name ?? 'N/A' }}</div>
                                <div class="text-[11px] text-muted-foreground">{{ $r->floor->name ?? 'Floor' }}</div>
                            </td>

                            <!-- Room Type & Beds -->
                            <td class="p-3.5">
                                <div class="flex items-center gap-1.5">
                                    <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase bg-secondary text-secondary-foreground border border-border">
                                        {{ $r->isSharing() ? $r->capacity . '-Sharing' : __('Single') }}
                                    </span>
                                    <span class="text-muted-foreground">({{ $beds->count() }} {{ __('Beds') }})</span>
                                </div>
                                <div class="text-[11px] text-muted-foreground mt-0.5">
                                    <span class="text-blue-600 font-semibold">{{ $occupied }} {{ __('Occupied') }}</span> &bull;
                                    <span class="text-emerald-600 font-semibold">{{ $free }} {{ __('Available') }}</span>
                                </div>
                            </td>

                            <!-- Gender Indicator Badge -->
                            <td class="p-3.5">
                                <span @class([
                                    'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold',
                                    'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20' => $r->gender_allowed === 'male_only',
                                    'bg-pink-500/10 text-pink-600 dark:text-pink-400 border border-pink-500/20' => $r->gender_allowed === 'female_only',
                                    'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20' => $r->gender_allowed === 'any',
                                ])>
                                    @if ($r->gender_allowed === 'male_only')
                                        <span>♂</span> {{ __('Male Only') }}
                                    @elseif ($r->gender_allowed === 'female_only')
                                        <span>♀</span> {{ __('Female Only') }}
                                    @else
                                        <span>⚧</span> {{ __('Any Gender') }}
                                    @endif
                                </span>
                            </td>

                            <!-- Status -->
                            <td class="p-3.5">
                                <span @class([
                                    'px-2.5 py-0.5 rounded text-[11px] font-bold uppercase',
                                    'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20' => $r->status === 'available',
                                    'bg-blue-500/10 text-blue-600 border border-blue-500/20' => $r->status === 'occupied',
                                    'bg-amber-500/10 text-amber-600 border border-amber-500/20' => $r->status === 'reserved',
                                    'bg-rose-500/10 text-rose-600 border border-rose-500/20' => $r->status === 'maintenance',
                                    'bg-secondary text-muted-foreground' => $r->status === 'inactive',
                                ])>
                                    {{ ucfirst($r->status) }}
                                </span>
                            </td>

                            <!-- Amenities -->
                            <td class="p-3.5">
                                @if (! empty($r->amenities) && is_array($r->amenities))
                                    <div class="flex flex-wrap items-center gap-1">
                                        @foreach (array_slice($r->amenities, 0, 3) as $am)
                                            <span class="px-1.5 py-0.2 rounded text-[10px] bg-secondary text-muted-foreground">
                                                {{ ucfirst(str_replace('_', ' ', $am)) }}
                                            </span>
                                        @endforeach
                                        @if (count($r->amenities) > 3)
                                            <span class="text-[10px] text-muted-foreground">+{{ count($r->amenities) - 3 }}</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted-foreground text-[11px]">-</span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="p-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-ui.button
                                        variant="outline"
                                        size="sm"
                                        wire:click="openBedsModal({{ $r->id }})"
                                        class="cursor-pointer text-xs h-7 px-2"
                                        title="{{ __('Manage Beds in Room') }}"
                                    >
                                        <x-icon name="bed" class="w-3.5 h-3.5 mr-1" />
                                        {{ __('Beds') }}
                                    </x-ui.button>

                                    <x-ui.button
                                        variant="outline"
                                        size="sm"
                                        wire:click="openRoomModal({{ $r->id }})"
                                        class="cursor-pointer text-xs h-7 px-2"
                                    >
                                        <x-icon name="edit" class="w-3.5 h-3.5" />
                                    </x-ui.button>

                                    <x-ui.button
                                        variant="outline"
                                        size="sm"
                                        wire:click="confirmDeleteRoom({{ $r->id }})"
                                        class="cursor-pointer text-xs h-7 px-2 text-rose-600 dark:text-rose-400 border-rose-500/20 hover:bg-rose-500/10"
                                    >
                                        <x-icon name="trash" class="w-3.5 h-3.5" />
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-8 text-center text-muted-foreground">
                                {{ __('No hostel rooms found matching your filters.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rooms->hasPages())
            <div class="p-4 border-t border-border">
                {{ $rooms->links() }}
            </div>
        @endif
    </div>

    <!-- Modal: Create / Edit Room -->
    <x-ui.modal name="room-form-modal" max-width="max-w-lg" :title="$editingRoomId ? __('Edit Room Configuration') : __('Add New Hostel Room')">
        <form wire:submit="saveRoom" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <!-- Building -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Building') }} *</label>
                    <select
                        wire:model.live="rBuildingId"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        required
                    >
                        <option value="">{{ __('-- Select Building --') }}</option>
                        @foreach ($this->buildings as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('rBuildingId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Floor -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Floor') }} *</label>
                    <select
                        wire:model="rFloorId"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        required
                    >
                        <option value="">{{ __('-- Select Floor --') }}</option>
                        @foreach ($this->formFloors as $fl)
                            <option value="{{ $fl->id }}">{{ $fl->name }}</option>
                        @endforeach
                    </select>
                    @error('rFloorId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <!-- Room Number -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Room Number') }} *</label>
                    <input
                        type="text"
                        wire:model="rRoomNumber"
                        placeholder="{{ __('e.g. G-101, M-204') }}"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        required
                    />
                    @error('rRoomNumber') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Room Type -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Room Type') }} *</label>
                    <select
                        wire:model.live="rRoomType"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    >
                        <option value="sharing">{{ __('Sharing Room') }}</option>
                        <option value="non_sharing">{{ __('Single (Non-Sharing)') }}</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <!-- Capacity / Beds -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">
                        {{ __('Bed Capacity') }} *
                    </label>
                    <input
                        type="number"
                        wire:model="rCapacity"
                        min="1"
                        max="20"
                        @disabled($rRoomType === 'non_sharing')
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none disabled:opacity-50"
                        required
                    />
                    <span class="text-[10px] text-muted-foreground mt-0.5 block">
                        {{ $rRoomType === 'non_sharing' ? __('Single room has 1 bed.') : __('Number of beds in this sharing room.') }}
                    </span>
                </div>

                <!-- Gender Allowance -->
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Gender Allocation Wing') }} *</label>
                    <select
                        wire:model="rGenderAllowed"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    >
                        <option value="male_only">{{ __('Male Residents Only') }} ♂</option>
                        <option value="female_only">{{ __('Female Residents Only') }} ♀</option>
                        <option value="any">{{ __('Any / Open Allocation') }} ⚧</option>
                    </select>
                </div>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Room Status') }}</label>
                <select
                    wire:model="rStatus"
                    class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="available">{{ __('Available for Allocation') }}</option>
                    <option value="reserved">{{ __('Reserved') }}</option>
                    <option value="maintenance">{{ __('Under Maintenance') }}</option>
                    <option value="inactive">{{ __('Inactive / Closed') }}</option>
                </select>
            </div>

            <!-- Amenities Checklist -->
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1.5">{{ __('Room Amenities & Features') }}</label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach (['attached_washroom' => 'Attached Washroom', 'ac' => 'Air Conditioned (AC)', 'study_table' => 'Study Desks', 'balcony' => 'Balcony', 'wifi' => 'Wi-Fi Enabled', 'geyser' => 'Water Geyser'] as $k => $label)
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border bg-background text-xs cursor-pointer">
                            <input type="checkbox" value="{{ $k }}" wire:model="rAmenities" class="rounded text-primary focus:ring-primary" />
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Room Notes / Special Specs (Optional)') }}</label>
                <textarea
                    wire:model="rNotes"
                    rows="2"
                    placeholder="{{ __('e.g. Corner room, extra ventilation, faculty preference...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('room-form-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                >
                    {{ __('Save Room') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Manage Beds in Room -->
    <x-ui.modal name="manage-beds-modal" max-width="max-w-lg" :title="__('Beds Management')">
        @if ($this->viewingRoom)
            <div class="space-y-4">
                <div class="p-3 rounded-xl border border-border bg-secondary/30 flex items-center justify-between">
                    <div>
                        <span class="font-bold text-sm text-foreground">{{ $this->viewingRoom->building->name ?? '' }} - Room {{ $this->viewingRoom->room_number }}</span>
                        <div class="text-xs text-muted-foreground">{{ $this->viewingRoom->capacity }} {{ __('Beds Registered') }}</div>
                    </div>
                    <span class="px-2 py-0.5 rounded text-xs font-bold uppercase bg-primary/10 text-primary">
                        {{ $this->viewingRoom->gender_label }}
                    </span>
                </div>

                <!-- Add Bed Input -->
                <div class="flex items-center gap-2">
                    <input
                        type="text"
                        wire:model="newBedNumber"
                        placeholder="{{ __('New bed label (e.g. Bed C, Bed 3)') }}"
                        class="flex-1 h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                    <x-ui.button
                        type="button"
                        variant="default"
                        size="sm"
                        wire:click="addBedToRoom"
                        class="bg-primary text-primary-foreground text-xs"
                    >
                        <x-icon name="plus" class="w-3.5 h-3.5 mr-1" />
                        {{ __('Add Bed') }}
                    </x-ui.button>
                </div>

                <!-- Beds List -->
                <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                    @forelse ($this->viewingRoom->beds as $b)
                        <div class="p-3 rounded-xl border border-border bg-background flex items-center justify-between" wire:key="manage-bed-{{ $b->id }}">
                            <div class="flex items-center gap-2.5">
                                <span class="p-1.5 rounded-lg bg-secondary text-foreground">
                                    <x-icon name="bed" class="w-4 h-4" />
                                </span>
                                <div>
                                    <span class="font-bold text-xs text-foreground block">{{ $b->bed_number }}</span>
                                    @if ($b->currentAllocation)
                                        <span class="text-[11px] text-blue-600 dark:text-blue-400 font-medium">
                                            {{ __('Occupied by: ') }}{{ $b->currentAllocation->user->name ?? '' }}
                                        </span>
                                    @else
                                        <span class="text-[11px] text-muted-foreground uppercase font-semibold">
                                            {{ ucfirst($b->status) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if ($b->status !== 'occupied')
                                <div class="flex items-center gap-1.5">
                                    @if ($b->status === 'available')
                                        <button
                                            type="button"
                                            wire:click="toggleBedStatus({{ $b->id }}, 'maintenance')"
                                            class="px-2 py-1 rounded text-[10px] font-semibold bg-rose-500/10 text-rose-600 hover:bg-rose-500/20 cursor-pointer"
                                        >
                                            {{ __('Set Maintenance') }}
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="toggleBedStatus({{ $b->id }}, 'reserved')"
                                            class="px-2 py-1 rounded text-[10px] font-semibold bg-amber-500/10 text-amber-600 hover:bg-amber-500/20 cursor-pointer"
                                        >
                                            {{ __('Reserve') }}
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="toggleBedStatus({{ $b->id }}, 'available')"
                                            class="px-2 py-1 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 cursor-pointer"
                                        >
                                            {{ __('Set Available') }}
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="p-4 text-center text-xs text-muted-foreground border border-dashed rounded-lg">
                            {{ __('No beds registered in this room.') }}
                        </div>
                    @endforelse
                </div>

                <div class="flex justify-end pt-3 border-t border-border">
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        x-on:click="$store.modals.close('manage-beds-modal')"
                    >
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Modal: Delete Room Confirmation -->
    <x-ui.modal name="delete-room-modal" max-width="max-w-sm" :title="__('Delete Room')">
        <div class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('Are you sure you want to archive this room? All unoccupied beds will be archived.') }}
            </p>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('delete-room-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="default"
                    size="sm"
                    wire:click="deleteRoom"
                    class="bg-rose-600 hover:bg-rose-700 text-white"
                >
                    {{ __('Delete') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Import Rooms & Beds -->
    <x-ui.modal name="rooms-import-modal" max-width="max-w-xl" :title="__('Batch Import Hostel Rooms & Beds')">
        <form wire:submit="importRooms" class="space-y-4">
            <div class="p-3.5 rounded-xl border border-blue-500/20 bg-blue-500/5 text-xs text-blue-800 dark:text-blue-300 space-y-1.5">
                <div class="font-bold flex items-center gap-1.5">
                    <x-icon name="info" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                    {{ __('Room Import Instructions') }}
                </div>
                <p>
                    {{ __('Upload a CSV or Excel (.xlsx) file containing room numbers, sharing types, capacity, and gender designations. The system will create the rooms and automatically provision beds matching the room capacity.') }}
                </p>
                <div class="pt-1">
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        wire:click="downloadTemplate"
                        class="text-xs h-7 px-2.5 bg-background border-blue-500/30 text-blue-700 dark:text-blue-300 hover:bg-blue-500/10 cursor-pointer"
                    >
                        <x-icon name="download" class="w-3.5 h-3.5 mr-1 text-blue-600" />
                        {{ __('Download Sample Template (CSV)') }}
                    </x-ui.button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Select Spreadsheet File (.csv, .xlsx)') }} *</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,.xlsx,.xls,text/csv"
                    class="w-full p-2 text-xs rounded-xl border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer"
                    required
                />
                @error('importFile') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div wire:loading wire:target="importFile" class="text-xs text-muted-foreground flex items-center gap-1.5">
                <x-icon name="rotate-cw" class="w-3.5 h-3.5 animate-spin text-primary" />
                {{ __('Uploading and parsing file...') }}
            </div>

            @if ($importSummary)
                <div class="p-3 rounded-xl border border-border bg-secondary/30 space-y-2 text-xs">
                    <div class="font-semibold text-foreground flex items-center justify-between">
                        <span>{{ __('Import Outcome') }}</span>
                        <div class="flex items-center gap-2">
                            <span class="text-emerald-600 font-bold">{{ $importSummary['imported'] }} {{ __('Imported') }}</span>
                            <span class="text-amber-600 font-bold">{{ $importSummary['skipped'] }} {{ __('Skipped') }}</span>
                        </div>
                    </div>
                    @if (!empty($importSummary['errors']))
                        <div class="max-h-36 overflow-y-auto space-y-1 p-2 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-[11px]">
                            @foreach ($importSummary['errors'] as $err)
                                <div>&bull; {{ $err }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('rooms-import-modal')"
                >
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    wire:loading.attr="disabled"
                    wire:target="importRooms"
                    class="bg-primary text-primary-foreground"
                >
                    <span wire:loading.remove wire:target="importRooms">{{ __('Process Import') }}</span>
                    <span wire:loading wire:target="importRooms" class="flex items-center gap-1.5">
                        <x-icon name="rotate-cw" class="w-3.5 h-3.5 animate-spin" />
                        {{ __('Importing...') }}
                    </span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
