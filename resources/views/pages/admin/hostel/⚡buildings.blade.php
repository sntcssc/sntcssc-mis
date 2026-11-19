<?php

use App\Models\HostelBuilding;
use App\Models\HostelCaretaker;
use App\Models\HostelFloor;
use App\Services\Hostel\HostelImportExportService;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Hostel Buildings, Management & Caretakers')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'q')]
    public string $search = '';

    // Import State
    public $importFile = null;
    public ?array $importSummary = null;

    // Building Modal State
    public ?int $editingBuildingId = null;
    public string $bName = '';
    public string $bCode = '';
    public string $bGenderType = 'coed';
    public int $bTotalFloors = 1;
    public string $bWardenName = '';
    public string $bWardenContact = '';
    public string $bWardenEmail = '';
    public string $bManagerName = '';
    public string $bManagerContact = '';
    public string $bManagerEmail = '';
    public string $bDescription = '';
    public string $bStatus = 'active';

    // Floor Modal State
    public ?int $editingFloorId = null;
    public ?int $floorBuildingId = null;
    public int $fFloorNumber = 0;
    public string $fName = '';
    public string $fGenderType = 'coed';
    public string $fStatus = 'active';

    // Caretakers Modal State
    public ?int $caretakersBuildingId = null;
    public ?int $editingCaretakerId = null;
    public string $ctName = '';
    public string $ctPhone = '';
    public string $ctEmail = '';
    public string $ctShift = 'day';
    public bool $ctIsPrimary = false;
    public string $ctStatus = 'active';
    public string $ctNotes = '';
    public ?int $deletingCaretakerId = null;

    // Delete Modals
    public ?int $deletingBuildingId = null;
    public ?int $deletingFloorId = null;

    #[Computed]
    public function buildings()
    {
        return HostelBuilding::withCount(['floors', 'rooms', 'beds'])
            ->with([
                'floors' => fn ($q) => $q->withCount('rooms')->orderBy('floor_number'),
                'caretakers' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('name'),
            ])
            ->when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")
                    ->orWhere('warden_name', 'like', "%{$this->search}%")
                    ->orWhere('manager_name', 'like', "%{$this->search}%")
                    ->orWhereHas('caretakers', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"));
            })
            ->orderBy('display_order')
            ->get();
    }

    #[Computed]
    public function selectedBuildingForCaretakers(): ?HostelBuilding
    {
        if (! $this->caretakersBuildingId) {
            return null;
        }

        return HostelBuilding::with(['caretakers' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('name')])
            ->find($this->caretakersBuildingId);
    }

    public function openBuildingModal(?int $id = null): void
    {
        $this->resetErrorBag();
        if ($id) {
            $b = HostelBuilding::findOrFail($id);
            $this->editingBuildingId = $b->id;
            $this->bName = $b->name;
            $this->bCode = $b->code;
            $this->bGenderType = $b->gender_type;
            $this->bTotalFloors = $b->total_floors;
            $this->bWardenName = $b->warden_name ?? '';
            $this->bWardenContact = $b->warden_contact ?? '';
            $this->bWardenEmail = $b->warden_email ?? '';
            $this->bManagerName = $b->manager_name ?? '';
            $this->bManagerContact = $b->manager_contact ?? '';
            $this->bManagerEmail = $b->manager_email ?? '';
            $this->bDescription = $b->description ?? '';
            $this->bStatus = $b->status;
        } else {
            $this->editingBuildingId = null;
            $this->bName = '';
            $this->bCode = '';
            $this->bGenderType = 'coed';
            $this->bTotalFloors = 1;
            $this->bWardenName = '';
            $this->bWardenContact = '';
            $this->bWardenEmail = '';
            $this->bManagerName = '';
            $this->bManagerContact = '';
            $this->bManagerEmail = '';
            $this->bDescription = '';
            $this->bStatus = 'active';
        }
        $this->dispatch('modal-open', name: 'building-form-modal');
    }

    public function saveBuilding(): void
    {
        $this->validate([
            'bName' => 'required|string|max:100',
            'bCode' => 'required|string|max:30|unique:hostel_buildings,code,'.$this->editingBuildingId,
            'bGenderType' => 'required|in:boys_only,girls_only,coed',
            'bTotalFloors' => 'required|integer|min:1|max:30',
            'bWardenName' => 'nullable|string|max:100',
            'bWardenContact' => 'nullable|string|max:50',
            'bWardenEmail' => 'nullable|email|max:100',
            'bManagerName' => 'nullable|string|max:100',
            'bManagerContact' => 'nullable|string|max:50',
            'bManagerEmail' => 'nullable|email|max:100',
            'bDescription' => 'nullable|string|max:500',
            'bStatus' => 'required|in:active,inactive,maintenance',
        ]);

        try {
            HostelBuilding::updateOrCreate(
                ['id' => $this->editingBuildingId],
                [
                    'name' => $this->bName,
                    'code' => strtoupper($this->bCode),
                    'gender_type' => $this->bGenderType,
                    'total_floors' => $this->bTotalFloors,
                    'warden_name' => $this->bWardenName ?: null,
                    'warden_contact' => $this->bWardenContact ?: null,
                    'warden_email' => $this->bWardenEmail ?: null,
                    'manager_name' => $this->bManagerName ?: null,
                    'manager_contact' => $this->bManagerContact ?: null,
                    'manager_email' => $this->bManagerEmail ?: null,
                    'description' => $this->bDescription ?: null,
                    'status' => $this->bStatus,
                    'updated_by' => auth()->id(),
                    'created_by' => $this->editingBuildingId ? null : auth()->id(),
                ]
            );

            Toast::dispatch($this, 'success', $this->editingBuildingId ? __('Building updated successfully.') : __('New building registered successfully.'));
            $this->dispatch('modal-close', name: 'building-form-modal');
            $this->editingBuildingId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openFloorModal(int $buildingId, ?int $floorId = null): void
    {
        $this->resetErrorBag();
        $this->floorBuildingId = $buildingId;

        if ($floorId) {
            $f = HostelFloor::findOrFail($floorId);
            $this->editingFloorId = $f->id;
            $this->fFloorNumber = $f->floor_number;
            $this->fName = $f->name;
            $this->fGenderType = $f->gender_type;
            $this->fStatus = $f->status;
        } else {
            $building = HostelBuilding::findOrFail($buildingId);
            $nextNum = HostelFloor::where('hostel_building_id', $buildingId)->max('floor_number');
            $nextNum = is_null($nextNum) ? 0 : $nextNum + 1;

            $this->editingFloorId = null;
            $this->fFloorNumber = $nextNum;
            $this->fName = $nextNum === 0 ? __('Ground Floor') : __(':numth Floor', ['num' => $nextNum]);
            $this->fGenderType = $building->gender_type;
            $this->fStatus = 'active';
        }

        $this->dispatch('modal-open', name: 'floor-form-modal');
    }

    public function saveFloor(): void
    {
        $this->validate([
            'floorBuildingId' => 'required|exists:hostel_buildings,id',
            'fFloorNumber' => 'required|integer|min:0|max:50',
            'fName' => 'required|string|max:100',
            'fGenderType' => 'required|in:boys_only,girls_only,coed',
            'fStatus' => 'required|in:active,inactive,maintenance',
        ]);

        try {
            HostelFloor::updateOrCreate(
                ['id' => $this->editingFloorId],
                [
                    'hostel_building_id' => $this->floorBuildingId,
                    'floor_number' => $this->fFloorNumber,
                    'name' => $this->fName,
                    'gender_type' => $this->fGenderType,
                    'status' => $this->fStatus,
                    'display_order' => $this->fFloorNumber,
                    'updated_by' => auth()->id(),
                    'created_by' => $this->editingFloorId ? null : auth()->id(),
                ]
            );

            Toast::dispatch($this, 'success', $this->editingFloorId ? __('Floor updated successfully.') : __('New floor added successfully.'));
            $this->dispatch('modal-close', name: 'floor-form-modal');
            $this->editingFloorId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function confirmDeleteBuilding(int $id): void
    {
        $this->deletingBuildingId = $id;
        $this->dispatch('modal-open', name: 'delete-building-modal');
    }

    public function deleteBuilding(): void
    {
        if (! $this->deletingBuildingId) {
            return;
        }

        try {
            $b = HostelBuilding::findOrFail($this->deletingBuildingId);
            $b->delete();

            Toast::dispatch($this, 'success', __('Building archived successfully.'));
            $this->dispatch('modal-close', name: 'delete-building-modal');
            $this->deletingBuildingId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function confirmDeleteFloor(int $id): void
    {
        $this->deletingFloorId = $id;
        $this->dispatch('modal-open', name: 'delete-floor-modal');
    }

    public function deleteFloor(): void
    {
        if (! $this->deletingFloorId) {
            return;
        }

        try {
            $f = HostelFloor::findOrFail($this->deletingFloorId);
            $f->delete();

            Toast::dispatch($this, 'success', __('Floor archived successfully.'));
            $this->dispatch('modal-close', name: 'delete-floor-modal');
            $this->deletingFloorId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    // Caretakers Modal Actions
    public function openCaretakersModal(int $buildingId): void
    {
        $this->resetErrorBag();
        $this->caretakersBuildingId = $buildingId;
        $this->resetCaretakerForm();
        $this->dispatch('modal-open', name: 'caretakers-modal');
    }

    public function resetCaretakerForm(): void
    {
        $this->editingCaretakerId = null;
        $this->ctName = '';
        $this->ctPhone = '';
        $this->ctEmail = '';
        $this->ctShift = 'day';
        $this->ctIsPrimary = false;
        $this->ctStatus = 'active';
        $this->ctNotes = '';
    }

    public function editCaretaker(int $id): void
    {
        $ct = HostelCaretaker::findOrFail($id);
        $this->editingCaretakerId = $ct->id;
        $this->ctName = $ct->name;
        $this->ctPhone = $ct->phone;
        $this->ctEmail = $ct->email ?? '';
        $this->ctShift = $ct->shift;
        $this->ctIsPrimary = (bool) $ct->is_primary;
        $this->ctStatus = $ct->status;
        $this->ctNotes = $ct->notes ?? '';
    }

    public function saveCaretaker(): void
    {
        if (! $this->caretakersBuildingId) {
            return;
        }

        $this->validate([
            'ctName' => 'required|string|max:100',
            'ctPhone' => 'required|string|max:30',
            'ctEmail' => 'nullable|email|max:100',
            'ctShift' => 'required|in:day,evening,night,general',
            'ctStatus' => 'required|in:active,inactive',
            'ctNotes' => 'nullable|string|max:300',
        ]);

        try {
            if ($this->ctIsPrimary) {
                // If setting this one as primary, reset others in this building
                HostelCaretaker::where('hostel_building_id', $this->caretakersBuildingId)
                    ->where('id', '!=', $this->editingCaretakerId)
                    ->update(['is_primary' => false]);
            }

            HostelCaretaker::updateOrCreate(
                ['id' => $this->editingCaretakerId],
                [
                    'hostel_building_id' => $this->caretakersBuildingId,
                    'name' => $this->ctName,
                    'phone' => $this->ctPhone,
                    'email' => $this->ctEmail ?: null,
                    'shift' => $this->ctShift,
                    'is_primary' => $this->ctIsPrimary,
                    'status' => $this->ctStatus,
                    'notes' => $this->ctNotes ?: null,
                    'updated_by' => auth()->id(),
                    'created_by' => $this->editingCaretakerId ? null : auth()->id(),
                ]
            );

            Toast::dispatch($this, 'success', $this->editingCaretakerId ? __('Caretaker record updated.') : __('New caretaker added to hostel.'));
            $this->resetCaretakerForm();
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function toggleCaretakerStatus(int $id): void
    {
        try {
            $ct = HostelCaretaker::findOrFail($id);
            $ct->status = $ct->status === 'active' ? 'inactive' : 'active';
            $ct->save();

            Toast::dispatch($this, 'success', __('Caretaker status updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function confirmDeleteCaretaker(int $id): void
    {
        $this->deletingCaretakerId = $id;
        $this->dispatch('modal-open', name: 'delete-caretaker-modal');
    }

    public function deleteCaretaker(): void
    {
        if (! $this->deletingCaretakerId) {
            return;
        }

        try {
            $ct = HostelCaretaker::findOrFail($this->deletingCaretakerId);
            $ct->delete();

            Toast::dispatch($this, 'success', __('Caretaker record removed.'));
            $this->dispatch('modal-close', name: 'delete-caretaker-modal');
            $this->deletingCaretakerId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function export(string $format): mixed
    {
        return HostelImportExportService::exportBuildings(null, $format);
    }

    public function downloadTemplate(): mixed
    {
        return HostelImportExportService::downloadBuildingsSampleTemplate();
    }

    public function openImportModal(): void
    {
        $this->reset(['importFile', 'importSummary']);
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'buildings-import-modal');
    }

    public function importBuildings(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $res = HostelImportExportService::importBuildings($path, auth()->id());
            $this->importSummary = $res;

            if ($res['success'] > 0) {
                Toast::dispatch($this, 'success', __(':count buildings and corresponding floors processed successfully.', ['count' => $res['success']]));
            } else {
                Toast::dispatch($this, 'error', __('No buildings could be imported. Please review the errors below.'));
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
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Hostel Buildings, Management & Caretakers') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ __('Structural & Staff Roster') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage residential hostel buildings, warden details, hostel managers, shift-wise caretakers, and floor wings.') }}
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
                {{ __('Import Buildings') }}
            </x-ui.button>

            <!-- Add Building -->
            <x-ui.button
                variant="default"
                size="sm"
                wire:click="openBuildingModal()"
                class="cursor-pointer bg-primary text-primary-foreground"
            >
                <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                {{ __('Add New Building') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Search input -->
    <div class="max-w-md relative">
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search buildings, warden, manager, or caretakers...') }}"
            class="w-full h-10 pl-9 pr-3 text-xs rounded-xl border border-border bg-card focus:ring-2 focus:ring-primary focus:outline-none"
        />
        <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-3" />
    </div>

    <!-- Buildings List -->
    <div class="space-y-6">
        @forelse ($this->buildings as $b)
            <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden" wire:key="building-card-{{ $b->id }}">
                <!-- Building Header Strip -->
                <div class="p-5 border-b border-border bg-secondary/30 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <span class="text-xl font-bold text-foreground">{{ $b->name }}</span>
                            <span class="px-2 py-0.5 rounded text-xs font-extrabold uppercase bg-primary/10 text-primary border border-primary/20">
                                {{ $b->code }}
                            </span>
                            <span @class([
                                'px-2.5 py-0.5 rounded-full text-xs font-semibold',
                                'bg-blue-500/10 text-blue-600 dark:text-blue-400' => $b->gender_type === 'boys_only',
                                'bg-pink-500/10 text-pink-600 dark:text-pink-400' => $b->gender_type === 'girls_only',
                                'bg-purple-500/10 text-purple-600 dark:text-purple-400' => $b->gender_type === 'coed',
                            ])>
                                {{ $b->gender_label }}
                            </span>
                        </div>

                        <!-- Warden & Manager Info -->
                        <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground mt-2.5">
                            @if ($b->warden_name)
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-background border border-border">
                                    <x-icon name="user" class="w-3.5 h-3.5 text-primary" />
                                    <span class="font-semibold text-foreground">{{ __('Warden:') }} {{ $b->warden_name }}</span>
                                    @if ($b->warden_contact)
                                        <span class="text-muted-foreground">({{ $b->warden_contact }})</span>
                                    @endif
                                </div>
                            @endif

                            @if ($b->manager_name)
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-background border border-border">
                                    <x-icon name="shield" class="w-3.5 h-3.5 text-amber-500" />
                                    <span class="font-semibold text-foreground">{{ __('Manager:') }} {{ $b->manager_name }}</span>
                                    @if ($b->manager_contact)
                                        <span class="text-muted-foreground">({{ $b->manager_contact }})</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <!-- Caretakers Roster Pill Strip -->
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Caretakers on Duty:') }}</span>
                            @forelse ($b->caretakers as $ct)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[11px] border border-border bg-background" wire:key="ct-chip-{{ $ct->id }}">
                                    @if ($ct->is_primary)
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500" title="{{ __('Primary Caretaker') }}"></span>
                                    @else
                                        <span class="w-1.5 h-1.5 rounded-full bg-muted-foreground"></span>
                                    @endif
                                    <span class="font-medium text-foreground">{{ $ct->name }}</span>
                                    <span class="text-[10px] text-muted-foreground">({{ ucfirst($ct->shift) }} &bull; {{ $ct->phone }})</span>
                                </span>
                            @empty
                                <span class="text-xs text-muted-foreground italic">{{ __('No caretakers registered yet') }}</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button
                            variant="default"
                            size="sm"
                            wire:click="openCaretakersModal({{ $b->id }})"
                            class="cursor-pointer text-xs bg-secondary hover:bg-secondary/80 text-foreground border border-border"
                        >
                            <x-icon name="users" class="w-3.5 h-3.5 mr-1 text-primary" />
                            {{ __('Manage Caretakers') }} ({{ $b->caretakers->count() }})
                        </x-ui.button>

                        <x-ui.button
                            variant="outline"
                            size="sm"
                            wire:click="openFloorModal({{ $b->id }})"
                            class="cursor-pointer text-xs"
                        >
                            <x-icon name="plus" class="w-3.5 h-3.5 mr-1" />
                            {{ __('Add Floor') }}
                        </x-ui.button>

                        <x-ui.button
                            variant="outline"
                            size="sm"
                            wire:click="openBuildingModal({{ $b->id }})"
                            class="cursor-pointer text-xs"
                        >
                            <x-icon name="edit" class="w-3.5 h-3.5 mr-1" />
                            {{ __('Edit') }}
                        </x-ui.button>

                        <x-ui.button
                            variant="outline"
                            size="sm"
                            wire:click="confirmDeleteBuilding({{ $b->id }})"
                            class="cursor-pointer text-xs text-rose-600 dark:text-rose-400 border-rose-500/20 hover:bg-rose-500/10"
                        >
                            <x-icon name="trash" class="w-3.5 h-3.5" />
                        </x-ui.button>
                    </div>
                </div>

                <!-- Building Floors Accordion/Table -->
                <div class="p-5 space-y-3">
                    <div class="flex items-center justify-between text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <span>{{ __('Floors Configuration') }} ({{ $b->floors->count() }} {{ __('Floors') }})</span>
                        <span>{{ $b->rooms_count }} {{ __('Rooms') }} &bull; {{ $b->beds_count }} {{ __('Total Beds') }}</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        @forelse ($b->floors as $fl)
                            <div class="p-3.5 rounded-xl border border-border bg-background flex items-center justify-between hover:border-primary/40 transition-colors" wire:key="fl-{{ $fl->id }}">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-xs text-foreground">{{ $fl->name }}</span>
                                        <span @class([
                                            'px-1.5 py-0.2 rounded text-[10px] font-bold uppercase',
                                            'bg-blue-500/10 text-blue-600' => $fl->gender_type === 'boys_only',
                                            'bg-pink-500/10 text-pink-600' => $fl->gender_type === 'girls_only',
                                            'bg-purple-500/10 text-purple-600' => $fl->gender_type === 'coed',
                                        ])>
                                            {{ $fl->gender_label }}
                                        </span>
                                    </div>
                                    <span class="text-[11px] text-muted-foreground mt-0.5 block">
                                        {{ $fl->rooms_count }} {{ __('Rooms registered on this floor') }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        wire:click="openFloorModal({{ $b->id }}, {{ $fl->id }})"
                                        class="p-1 rounded text-muted-foreground hover:text-foreground cursor-pointer"
                                        title="{{ __('Edit Floor') }}"
                                    >
                                        <x-icon name="edit" class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="confirmDeleteFloor({{ $fl->id }})"
                                        class="p-1 rounded text-muted-foreground hover:text-rose-600 cursor-pointer"
                                        title="{{ __('Delete Floor') }}"
                                    >
                                        <x-icon name="trash" class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="col-span-full p-4 text-center text-xs text-muted-foreground border border-dashed rounded-xl">
                                {{ __('No floors defined yet. Click "Add Floor" to create the first floor.') }}
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @empty
            <div class="p-12 text-center rounded-2xl border border-dashed border-border bg-card">
                <x-icon name="building-2" class="w-12 h-12 text-muted-foreground mx-auto mb-3" />
                <h3 class="text-base font-bold text-foreground">{{ __('No Hostel Buildings Registered') }}</h3>
                <p class="text-xs text-muted-foreground mt-1">{{ __('Click "Add New Building" to configure your first hostel.') }}</p>
            </div>
        @endforelse
    </div>

    <!-- Modal: Building Form -->
    <x-ui.modal name="building-form-modal" max-width="max-w-xl" :title="$editingBuildingId ? __('Edit Hostel Building') : __('Register New Hostel Building')">
        <form wire:submit="saveBuilding" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Building Name') }} *</label>
                    <input
                        type="text"
                        wire:model="bName"
                        placeholder="{{ __('e.g. Ganga Hostel') }}"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        required
                    />
                    @error('bName') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Building Code') }} *</label>
                    <input
                        type="text"
                        wire:model="bCode"
                        placeholder="{{ __('e.g. GH') }}"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none uppercase"
                        required
                    />
                    @error('bCode') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Gender Designation') }} *</label>
                    <select
                        wire:model="bGenderType"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    >
                        <option value="boys_only">{{ __('Boys Only') }}</option>
                        <option value="girls_only">{{ __('Girls Only') }}</option>
                        <option value="coed">{{ __('Co-Ed / Multi-Wing') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Total Floors') }} *</label>
                    <input
                        type="number"
                        wire:model="bTotalFloors"
                        min="1"
                        max="30"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        required
                    />
                </div>
            </div>

            <!-- Hostel Manager Details -->
            <div class="p-3.5 rounded-xl border border-border bg-secondary/20 space-y-3">
                <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('Hostel Manager Details') }}
                </span>

                <div>
                    <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Manager Name') }}</label>
                    <input
                        type="text"
                        wire:model="bManagerName"
                        placeholder="{{ __('e.g. Mr. Biplab Karmakar') }}"
                        class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                    />
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Manager Phone / Mobile') }}</label>
                        <input
                            type="text"
                            wire:model="bManagerContact"
                            placeholder="{{ __('+91 98300 11223') }}"
                            class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                        />
                    </div>
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Manager Email Address') }}</label>
                        <input
                            type="email"
                            wire:model="bManagerEmail"
                            placeholder="{{ __('manager.ganga@sntcssc.edu.in') }}"
                            class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                        />
                    </div>
                </div>
            </div>

            <!-- Warden Details -->
            <div class="p-3.5 rounded-xl border border-border bg-secondary/20 space-y-3">
                <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('Hostel Warden / In-Charge Details') }}
                </span>

                <div>
                    <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Warden Name') }}</label>
                    <input
                        type="text"
                        wire:model="bWardenName"
                        placeholder="{{ __('e.g. Prof. S. K. Banerjee') }}"
                        class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                    />
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Phone / Mobile') }}</label>
                        <input
                            type="text"
                            wire:model="bWardenContact"
                            placeholder="{{ __('+91 98301 23456') }}"
                            class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                        />
                    </div>
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Email Address') }}</label>
                        <input
                            type="email"
                            wire:model="bWardenEmail"
                            placeholder="{{ __('warden@institution.edu.in') }}"
                            class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                        />
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Description & Facilities') }}</label>
                <textarea
                    wire:model="bDescription"
                    rows="2"
                    placeholder="{{ __('Residential blocks, study rooms, recreation, security specs...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('building-form-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                >
                    {{ __('Save Building') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Floor Form -->
    <x-ui.modal name="floor-form-modal" max-width="max-w-md" :title="$editingFloorId ? __('Edit Floor') : __('Add Floor to Building')">
        <form wire:submit="saveFloor" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Floor Number (0 = Ground)') }} *</label>
                <input
                    type="number"
                    wire:model="fFloorNumber"
                    min="0"
                    max="50"
                    class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Floor Display Name') }} *</label>
                <input
                    type="text"
                    wire:model="fName"
                    placeholder="{{ __('e.g. Ground Floor, 1st Floor, Wing A') }}"
                    class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Floor Gender Wing') }} *</label>
                <select
                    wire:model="fGenderType"
                    class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="boys_only">{{ __('Boys Wing') }}</option>
                    <option value="girls_only">{{ __('Girls Wing') }}</option>
                    <option value="coed">{{ __('Co-Ed / Open Wing') }}</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('floor-form-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                >
                    {{ __('Save Floor') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Manage Caretakers -->
    <x-ui.modal name="caretakers-modal" max-width="max-w-3xl" :title="__('Hostel Caretakers & Duty Roster')">
        <div class="space-y-5">
            @if ($this->selectedBuildingForCaretakers)
                <div class="p-3 rounded-xl bg-secondary/30 border border-border flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-foreground">{{ $this->selectedBuildingForCaretakers->name }}</span>
                        <span class="text-[11px] text-muted-foreground ml-2">({{ $this->selectedBuildingForCaretakers->code }})</span>
                    </div>
                    @if ($this->selectedBuildingForCaretakers->manager_name)
                        <span class="text-xs text-muted-foreground">
                            {{ __('Hostel Manager:') }} <strong class="text-foreground">{{ $this->selectedBuildingForCaretakers->manager_name }}</strong>
                        </span>
                    @endif
                </div>

                <!-- Caretaker Add / Edit Form -->
                <form wire:submit="saveCaretaker" class="p-4 rounded-xl border border-primary/20 bg-primary/5 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-foreground">
                            {{ $editingCaretakerId ? __('Edit Caretaker Details') : __('Add New Caretaker to this Hostel') }}
                        </span>
                        @if ($editingCaretakerId)
                            <button type="button" wire:click="resetCaretakerForm" class="text-xs text-primary underline cursor-pointer">
                                {{ __('+ Add New Instead') }}
                            </button>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        <div>
                            <label class="block text-[11px] font-semibold text-muted-foreground mb-1">{{ __('Full Name') }} *</label>
                            <input
                                type="text"
                                wire:model="ctName"
                                placeholder="{{ __('e.g. Ramesh Chandra') }}"
                                class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                                required
                            />
                            @error('ctName') <span class="text-[10px] text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-[11px] font-semibold text-muted-foreground mb-1">{{ __('Phone / Mobile') }} *</label>
                            <input
                                type="text"
                                wire:model="ctPhone"
                                placeholder="{{ __('+91 98300 44556') }}"
                                class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                                required
                            />
                            @error('ctPhone') <span class="text-[10px] text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-[11px] font-semibold text-muted-foreground mb-1">{{ __('Email Address') }}</label>
                            <input
                                type="email"
                                wire:model="ctEmail"
                                placeholder="{{ __('caretaker@sntcssc.edu.in') }}"
                                class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 items-center">
                        <div>
                            <label class="block text-[11px] font-semibold text-muted-foreground mb-1">{{ __('Duty Shift') }} *</label>
                            <select
                                wire:model="ctShift"
                                class="w-full h-8 px-2 text-xs rounded-md border border-border bg-background"
                            >
                                <option value="day">{{ __('Day Shift (6 AM - 2 PM)') }}</option>
                                <option value="evening">{{ __('Evening Shift (2 PM - 10 PM)') }}</option>
                                <option value="night">{{ __('Night Shift (10 PM - 6 AM)') }}</option>
                                <option value="general">{{ __('General / 24x7') }}</option>
                            </select>
                        </div>

                        <div class="flex items-center gap-2 pt-4">
                            <input
                                type="checkbox"
                                id="ctIsPrimary"
                                wire:model="ctIsPrimary"
                                class="rounded border-border text-primary focus:ring-primary h-4 w-4"
                            />
                            <label for="ctIsPrimary" class="text-xs text-foreground cursor-pointer font-medium">
                                {{ __('Primary In-Charge Caretaker') }}
                            </label>
                        </div>

                        <div class="pt-4 flex justify-end">
                            <x-ui.button
                                type="submit"
                                variant="default"
                                size="sm"
                                class="w-full bg-primary text-primary-foreground text-xs"
                            >
                                {{ $editingCaretakerId ? __('Update Caretaker') : __('Save Caretaker') }}
                            </x-ui.button>
                        </div>
                    </div>
                </form>

                <!-- Caretakers Roster Table -->
                <div class="rounded-xl border border-border overflow-hidden">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-border bg-muted/40 text-muted-foreground font-semibold">
                                <th class="p-2.5">{{ __('Name & Role') }}</th>
                                <th class="p-2.5">{{ __('Duty Shift') }}</th>
                                <th class="p-2.5">{{ __('Phone & Email') }}</th>
                                <th class="p-2.5 text-center">{{ __('Status') }}</th>
                                <th class="p-2.5 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($this->selectedBuildingForCaretakers->caretakers as $ct)
                                <tr class="hover:bg-muted/20 transition-colors" wire:key="ct-row-{{ $ct->id }}">
                                    <td class="p-2.5">
                                        <div class="font-bold text-foreground flex items-center gap-1.5">
                                            {{ $ct->name }}
                                            @if ($ct->is_primary)
                                                <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                                    {{ __('Primary') }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="p-2.5">
                                        <span class="px-2 py-0.5 rounded text-[11px] font-medium bg-secondary text-foreground">
                                            {{ $ct->shift_label }}
                                        </span>
                                    </td>
                                    <td class="p-2.5">
                                        <div class="font-medium text-foreground">{{ $ct->phone }}</div>
                                        @if ($ct->email)
                                            <div class="text-[11px] text-muted-foreground">{{ $ct->email }}</div>
                                        @endif
                                    </td>
                                    <td class="p-2.5 text-center">
                                        <button
                                            type="button"
                                            wire:click="toggleCaretakerStatus({{ $ct->id }})"
                                            class="px-2 py-0.5 rounded-full text-[10px] font-bold cursor-pointer transition-colors {{ $ct->status === 'active' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-muted text-muted-foreground' }}"
                                        >
                                            {{ ucfirst($ct->status) }}
                                        </button>
                                    </td>
                                    <td class="p-2.5 text-right space-x-1">
                                        <button
                                            type="button"
                                            wire:click="editCaretaker({{ $ct->id }})"
                                            class="p-1 rounded text-muted-foreground hover:text-foreground cursor-pointer"
                                            title="{{ __('Edit Caretaker') }}"
                                        >
                                            <x-icon name="edit" class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="confirmDeleteCaretaker({{ $ct->id }})"
                                            class="p-1 rounded text-muted-foreground hover:text-rose-600 cursor-pointer"
                                            title="{{ __('Delete Caretaker') }}"
                                        >
                                            <x-icon name="trash" class="w-3.5 h-3.5" />
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-6 text-center text-xs text-muted-foreground">
                                        {{ __('No caretakers registered for this hostel building yet.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="flex items-center justify-end pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('caretakers-modal')"
                >
                    {{ __('Close') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Delete Caretaker Confirmation -->
    <x-ui.modal name="delete-caretaker-modal" max-width="max-w-sm" :title="__('Delete Caretaker Record')">
        <div class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('Are you sure you want to remove this caretaker from the hostel records?') }}
            </p>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('delete-caretaker-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="default"
                    size="sm"
                    wire:click="deleteCaretaker"
                    class="bg-rose-600 hover:bg-rose-700 text-white"
                >
                    {{ __('Delete') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Delete Building Confirmation -->
    <x-ui.modal name="delete-building-modal" max-width="max-w-sm" :title="__('Delete Hostel Building')">
        <div class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('Are you sure you want to archive this building? Its associated rooms, beds and floors will also be preserved in archive.') }}
            </p>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('delete-building-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="default"
                    size="sm"
                    wire:click="deleteBuilding"
                    class="bg-rose-600 hover:bg-rose-700 text-white"
                >
                    {{ __('Delete') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Delete Floor Confirmation -->
    <x-ui.modal name="delete-floor-modal" max-width="max-w-sm" :title="__('Delete Floor')">
        <div class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('Are you sure you want to delete this floor?') }}
            </p>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('delete-floor-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="default"
                    size="sm"
                    wire:click="deleteFloor"
                    class="bg-rose-600 hover:bg-rose-700 text-white"
                >
                    {{ __('Delete') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Modal: Import Buildings & Floors -->
    <x-ui.modal name="buildings-import-modal" max-width="max-w-xl" :title="__('Batch Import Hostel Buildings & Floors')">
        <form wire:submit="importBuildings" class="space-y-4">
            <div class="p-3.5 rounded-xl border border-blue-500/20 bg-blue-500/5 text-xs text-blue-800 dark:text-blue-300 space-y-1.5">
                <div class="font-bold flex items-center gap-1.5">
                    <x-icon name="info" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                    {{ __('Batch Import Instructions') }}
                </div>
                <p>
                    {{ __('Upload a CSV or Excel (.xlsx) file containing building structures. The system will create the building, assign the warden/manager details, and automatically provision floor records up to the specified floor count.') }}
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
                    x-on:click="$store.modals.close('buildings-import-modal')"
                >
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    wire:loading.attr="disabled"
                    wire:target="importBuildings"
                    class="bg-primary text-primary-foreground"
                >
                    <span wire:loading.remove wire:target="importBuildings">{{ __('Process Import') }}</span>
                    <span wire:loading wire:target="importBuildings" class="flex items-center gap-1.5">
                        <x-icon name="rotate-cw" class="w-3.5 h-3.5 animate-spin" />
                        {{ __('Importing...') }}
                    </span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
