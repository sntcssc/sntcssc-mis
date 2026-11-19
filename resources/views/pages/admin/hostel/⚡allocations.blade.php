<?php

use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\Hostel\HostelImportExportService;
use App\Services\Hostel\HostelService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Hostel Room Allocations & Residents Register')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'active'; // active, extended, vacated, cancelled, all

    #[Url(as: 'type')]
    public string $residentTypeFilter = 'all'; // all, student, staff

    #[Url(as: 'hardcopy')]
    public string $hardCopyFilter = 'all'; // all, submitted, pending

    #[Url(as: 'softcopy')]
    public string $softCopyFilter = 'all'; // all, uploaded, pending

    #[Url(as: 'room_type')]
    public string $roomTypeFilter = 'all'; // all, sharing, non_sharing

    #[Url(as: 'previous_batch')]
    public string $previousBatchFilter = 'all'; // all, yes, no

    #[Url(as: 'expiring')]
    public bool $expiringFilter = false;

    #[Url(as: 'building')]
    public ?int $buildingFilter = null;

    #[Url(as: 'floor')]
    public ?int $floorFilter = null;

    #[Url(as: 'sort')]
    public string $sortBy = 'id';

    #[Url(as: 'dir')]
    public string $sortDirection = 'desc';

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 15;

    // Allocate Modal Form State
    public ?int $formUserId = null;
    public string $formResidentType = 'student';
    public ?int $formBuildingId = null;
    public ?int $formFloorId = null;
    public ?int $formRoomId = null;
    public ?int $formBedId = null;
    public string $formStartDate = '';
    public string $formDurationType = 'months';
    public int $formDurationValue = 6;
    public ?string $formCustomEndDate = null;
    public string $formRemarks = '';
    public bool $formIsPreviousBatch = false;
    public string $formPreviousBatchName = '';
    public bool $formHardCopySubmitted = false;
    public ?string $formHardCopySubmittedAt = null;
    public string $formHardCopyNotes = '';
    public $formSoftCopyFile = null;

    // Extend Modal State
    public ?int $extendingAllocationId = null;
    public string $extendNewEndDate = '';
    public string $extendNotes = '';

    // Vacate Modal State
    public ?int $vacatingAllocationId = null;
    public string $vacateDate = '';
    public string $vacateRemarks = '';

    // Dossier Modal State
    public ?int $viewingAllocationId = null;

    // Import Modal State
    public $importFile = null;
    public ?array $importSummary = null;

    public function mount(): void
    {
        $this->formStartDate = now()->toDateString();
        $this->formHardCopySubmittedAt = now()->toDateString();
        $this->vacateDate = now()->toDateString();
        $this->formDurationValue = (int) Setting::get('hostel.default_duration_months', 6);
    }

    public function updatedFormBuildingId(): void
    {
        $this->formFloorId = null;
        $this->formRoomId = null;
        $this->formBedId = null;
    }

    public function updatedFormFloorId(): void
    {
        $this->formRoomId = null;
        $this->formBedId = null;
    }

    public function updatedFormRoomId(): void
    {
        $this->formBedId = null;
    }

    public function setSort(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
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
        if (! $this->formBuildingId) {
            return collect();
        }

        return HostelFloor::where('hostel_building_id', $this->formBuildingId)
            ->active()
            ->orderBy('floor_number')
            ->get();
    }

    #[Computed]
    public function formRooms()
    {
        if (! $this->formFloorId) {
            return collect();
        }

        return HostelRoom::where('hostel_floor_id', $this->formFloorId)
            ->where('status', '!=', 'inactive')
            ->orderBy('room_number')
            ->get();
    }

    #[Computed]
    public function formBeds()
    {
        if (! $this->formRoomId) {
            return collect();
        }

        return HostelBed::where('hostel_room_id', $this->formRoomId)
            ->where('status', 'available')
            ->orderBy('bed_number')
            ->get();
    }

    #[Computed]
    public function selectableUsers()
    {
        $activeUserIds = HostelAllocation::active()->pluck('user_id')->toArray();

        $query = User::query()
            ->whereNotIn('id', $activeUserIds)
            ->where('status', 'active');

        if ($this->formResidentType === 'student') {
            $query->where(function ($q) {
                $q->whereHas('roles', fn ($rq) => $rq->where('name', 'Student'))
                    ->orWhereNotNull('student_id');
            });
        }

        return $query->with('studentProfile')->orderBy('name')->take(80)->get();
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

    public function buildAllocationsQuery(): Builder
    {
        $query = HostelAllocation::with([
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'user.activeEnrollment.section',
            'room.building.caretakers',
            'room.floor',
            'bed',
        ]);

        if ($this->statusFilter === 'active') {
            $query->whereIn('status', ['active', 'extended']);
        } elseif ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->residentTypeFilter !== 'all') {
            $query->where('resident_type', $this->residentTypeFilter);
        }

        if ($this->hardCopyFilter === 'submitted') {
            $query->where('hard_copy_submitted', true);
        } elseif ($this->hardCopyFilter === 'pending') {
            $query->where('hard_copy_submitted', false);
        }

        if ($this->softCopyFilter === 'uploaded') {
            $query->whereNotNull('soft_copy_path');
        } elseif ($this->softCopyFilter === 'pending') {
            $query->whereNull('soft_copy_path');
        }

        if ($this->roomTypeFilter !== 'all') {
            $query->whereHas('room', fn ($q) => $q->where('room_type', $this->roomTypeFilter));
        }

        if ($this->previousBatchFilter === 'yes') {
            $query->where('is_previous_batch_student', true);
        } elseif ($this->previousBatchFilter === 'no') {
            $query->where('is_previous_batch_student', false);
        }

        if ($this->expiringFilter) {
            $noticeDays = (int) Setting::get('hostel.expiry_notice_days', 15);
            $query->expiringSoon($noticeDays);
        }

        if ($this->buildingFilter) {
            $query->whereHas('room', fn ($q) => $q->where('hostel_building_id', $this->buildingFilter));
        }

        if ($this->floorFilter) {
            $query->whereHas('room', fn ($q) => $q->where('hostel_floor_id', $this->floorFilter));
        }

        if (! empty($this->search)) {
            $s = trim($this->search);
            $query->where(function ($q) use ($s) {
                $q->where('allocation_no', 'like', "%{$s}%")
                    ->orWhereHas('user', function ($uq) use ($s) {
                        $uq->where('name', 'like', "%{$s}%")
                            ->orWhere('student_id', 'like', "%{$s}%")
                            ->orWhere('email', 'like', "%{$s}%")
                            ->orWhere('phone', 'like', "%{$s}%")
                            ->orWhereHas('studentProfile', fn ($spq) => $spq->where('urn', 'like', "%{$s}%")->orWhere('student_id', 'like', "%{$s}%"))
                            ->orWhereHas('activeEnrollment', function ($aeq) use ($s) {
                                $aeq->where('enrollment_no', 'like', "%{$s}%")
                                    ->orWhereHas('course', fn ($cq) => $cq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                                    ->orWhereHas('batch', fn ($bq) => $bq->where('name', 'like', "%{$s}%"))
                                    ->orWhereHas('section', fn ($sq) => $sq->where('name', 'like', "%{$s}%"));
                            });
                    })
                    ->orWhereHas('room', fn ($rq) => $rq->where('room_number', 'like', "%{$s}%"))
                    ->orWhereHas('bed', fn ($bq) => $bq->where('bed_number', 'like', "%{$s}%"));
            });
        }

        return match ($this->sortBy) {
            'allocation_no' => $query->orderBy('allocation_no', $this->sortDirection),
            'start_date' => $query->orderBy('start_date', $this->sortDirection),
            'end_date' => $query->orderBy('end_date', $this->sortDirection),
            'status' => $query->orderBy('status', $this->sortDirection),
            default => $query->orderBy('id', $this->sortDirection),
        };
    }

    public function render()
    {
        $allocations = $this->buildAllocationsQuery()->paginate($this->perPage);

        return view('pages.admin.hostel.⚡allocations', [
            'allocations' => $allocations,
        ]);
    }

    public function export(string $format = 'xlsx')
    {
        return HostelImportExportService::export(
            $this->buildAllocationsQuery(),
            $format,
            'Hostel Allocations Register'
        );
    }

    public function downloadSampleTemplate()
    {
        return HostelImportExportService::downloadSampleTemplate();
    }

    public function openImportModal(): void
    {
        $this->resetErrorBag();
        $this->importFile = null;
        $this->importSummary = null;
        $this->dispatch('modal-open', name: 'allocation-import-modal');
    }

    public function importAllocations(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $res = HostelImportExportService::importAllocations($path, auth()->id());
            $this->importSummary = $res;

            if ($res['imported'] > 0) {
                Toast::dispatch($this, 'success', __(':count accommodation records imported successfully.', ['count' => $res['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('Import completed. 0 records imported, :skipped skipped.', ['skipped' => $res['skipped']]));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openAllocateModal(): void
    {
        $this->resetErrorBag();
        $this->formUserId = null;
        $this->formResidentType = 'student';
        $this->formBuildingId = $this->buildings->first()?->id;
        $this->formFloorId = null;
        $this->formRoomId = null;
        $this->formBedId = null;
        $this->formStartDate = now()->toDateString();
        $this->formDurationType = 'months';
        $this->formDurationValue = (int) Setting::get('hostel.default_duration_months', 6);
        $this->formCustomEndDate = null;
        $this->formRemarks = '';
        $this->formIsPreviousBatch = false;
        $this->formPreviousBatchName = '';
        $this->formHardCopySubmitted = false;
        $this->formHardCopySubmittedAt = now()->toDateString();
        $this->formHardCopyNotes = '';
        $this->formSoftCopyFile = null;

        $this->dispatch('modal-open', name: 'allocation-create-modal');
    }

    public function saveAllocation(): void
    {
        $this->validate([
            'formUserId' => 'required|exists:users,id',
            'formBedId' => 'required|exists:hostel_beds,id',
            'formResidentType' => 'required|in:student,staff',
            'formStartDate' => 'required|date',
            'formDurationType' => 'required|in:days,months,years,custom,indefinite',
            'formDurationValue' => 'nullable|required_if:formDurationType,days,months,years|integer|min:1',
            'formCustomEndDate' => 'nullable|required_if:formDurationType,custom|date|after:formStartDate',
            'formRemarks' => 'nullable|string|max:500',
            'formPreviousBatchName' => 'nullable|required_if:formIsPreviousBatch,true|string|max:150',
            'formSoftCopyFile' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        try {
            $softCopyPath = null;
            if ($this->formSoftCopyFile) {
                $softCopyPath = FileUploadService::store(
                    $this->formSoftCopyFile,
                    'hostel/forms',
                    'hostel_form',
                    'public'
                );
            }

            HostelService::allocateResident([
                'user_id' => $this->formUserId,
                'hostel_bed_id' => $this->formBedId,
                'resident_type' => $this->formResidentType,
                'start_date' => $this->formStartDate,
                'end_date' => $this->formCustomEndDate,
                'duration_type' => $this->formDurationType,
                'duration_value' => $this->formDurationValue,
                'remarks' => $this->formRemarks,
                'hard_copy_submitted' => $this->formHardCopySubmitted,
                'hard_copy_submitted_at' => $this->formHardCopySubmitted ? $this->formHardCopySubmittedAt : null,
                'hard_copy_received_by' => $this->formHardCopySubmitted ? auth()->id() : null,
                'hard_copy_notes' => $this->formHardCopyNotes,
                'soft_copy_path' => $softCopyPath,
                'is_previous_batch_student' => $this->formIsPreviousBatch,
                'previous_batch_name' => $this->formIsPreviousBatch ? $this->formPreviousBatchName : null,
            ], auth()->id());

            Toast::dispatch($this, 'success', __('Room & bed successfully allocated.'));
            $this->dispatch('modal-close', name: 'allocation-create-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openExtendModal(int $id): void
    {
        $this->resetErrorBag();
        $allocation = HostelAllocation::findOrFail($id);
        $this->extendingAllocationId = $allocation->id;
        $this->extendNewEndDate = $allocation->end_date ? $allocation->end_date->addMonths(3)->toDateString() : now()->addMonths(3)->toDateString();
        $this->extendNotes = '';
        $this->dispatch('modal-open', name: 'allocation-extend-modal');
    }

    public function processExtension(): void
    {
        $this->validate([
            'extendNewEndDate' => 'required|date|after:today',
            'extendNotes' => 'nullable|string|max:500',
        ]);

        if (! $this->extendingAllocationId) {
            return;
        }

        try {
            $allocation = HostelAllocation::findOrFail($this->extendingAllocationId);
            HostelService::extendAllocation(
                $allocation,
                $this->extendNewEndDate,
                $this->extendNotes,
                auth()->id()
            );

            Toast::dispatch($this, 'success', __('Accommodation timeline extended successfully.'));
            $this->dispatch('modal-close', name: 'allocation-extend-modal');
            $this->extendingAllocationId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openVacateModal(int $id): void
    {
        $this->resetErrorBag();
        $allocation = HostelAllocation::findOrFail($id);
        $this->vacatingAllocationId = $allocation->id;
        $this->vacateDate = now()->toDateString();
        $this->vacateRemarks = '';
        $this->dispatch('modal-open', name: 'allocation-vacate-modal');
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
            $this->dispatch('modal-close', name: 'allocation-vacate-modal');
            $this->vacatingAllocationId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openDossier(int $id): void
    {
        $this->viewingAllocationId = $id;
        $this->dispatch('modal-open', name: 'allocation-dossier-modal');
    }

    public function toggleHardCopyStatus(int $id): void
    {
        try {
            $allocation = HostelAllocation::findOrFail($id);
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
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Hostel Room Allocations & Residents Register') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ __('Master Accommodations Register') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Track all current and historical accommodations with live student enrollment info, batch/section details, import/export tools, and form compliance.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Export Tools Dropdown / Group -->
            <div class="inline-flex rounded-xl border border-border bg-card p-0.5 shadow-xs">
                <button
                    type="button"
                    wire:click="export('xlsx')"
                    class="px-2.5 py-1 text-xs font-semibold text-foreground hover:bg-secondary rounded-lg transition-colors inline-flex items-center gap-1 cursor-pointer"
                    title="{{ __('Export to Excel Spreadsheet') }}"
                >
                    <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600" />
                    <span>{{ __('Excel') }}</span>
                </button>
                <button
                    type="button"
                    wire:click="export('csv')"
                    class="px-2.5 py-1 text-xs font-semibold text-foreground hover:bg-secondary rounded-lg transition-colors inline-flex items-center gap-1 cursor-pointer border-l border-border"
                    title="{{ __('Export to CSV File') }}"
                >
                    <x-icon name="file-text" class="w-3.5 h-3.5 text-blue-600" />
                    <span>{{ __('CSV') }}</span>
                </button>
                <button
                    type="button"
                    wire:click="export('pdf')"
                    class="px-2.5 py-1 text-xs font-semibold text-foreground hover:bg-secondary rounded-lg transition-colors inline-flex items-center gap-1 cursor-pointer border-l border-border"
                    title="{{ __('Export to PDF Document') }}"
                >
                    <x-icon name="file-down" class="w-3.5 h-3.5 text-rose-600" />
                    <span>{{ __('PDF') }}</span>
                </button>
            </div>

            <!-- Import Allocations Button -->
            <x-ui.button
                variant="outline"
                size="sm"
                wire:click="openImportModal"
                class="cursor-pointer"
            >
                <x-icon name="upload" class="w-4 h-4 mr-1.5 text-primary" />
                {{ __('Import') }}
            </x-ui.button>

            <!-- Visual Floor Map -->
            <a href="{{ route('admin.hostel.rooms.board') }}" wire:navigate>
                <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                    <x-icon name="layout-grid" class="w-4 h-4 mr-1.5" />
                    {{ __('Floor Map') }}
                </x-ui.button>
            </a>

            <!-- New Accommodation Button -->
            <x-ui.button
                variant="default"
                size="sm"
                wire:click="openAllocateModal"
                class="cursor-pointer bg-primary text-primary-foreground"
            >
                <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                {{ __('New Accommodation') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Dynamic Filters Bar -->
    <div class="p-4 rounded-2xl border border-border bg-card shadow-xs space-y-3">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
            <!-- Status -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Status') }}</label>
                <select
                    wire:model.live="statusFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="active">{{ __('Active & Extended') }}</option>
                    <option value="vacated">{{ __('Vacated (Past Stays)') }}</option>
                    <option value="cancelled">{{ __('Cancelled') }}</option>
                    <option value="all">{{ __('All Allocations') }}</option>
                </select>
            </div>

            <!-- Resident Type -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Resident') }}</label>
                <select
                    wire:model.live="residentTypeFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="all">{{ __('All Types') }}</option>
                    <option value="student">{{ __('Students') }}</option>
                    <option value="staff">{{ __('Faculty & Staff') }}</option>
                </select>
            </div>

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

            <!-- Room Configuration -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Room Type') }}</label>
                <select
                    wire:model.live="roomTypeFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="all">{{ __('All Configs') }}</option>
                    <option value="sharing">{{ __('Sharing Beds') }}</option>
                    <option value="non_sharing">{{ __('Single Non-Sharing') }}</option>
                </select>
            </div>

            <!-- Hardcopy Status -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Hardcopy Form') }}</label>
                <select
                    wire:model.live="hardCopyFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="all">{{ __('All Hardcopy') }}</option>
                    <option value="submitted">{{ __('Submitted') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                </select>
            </div>

            <!-- Softcopy Status -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Softcopy Form') }}</label>
                <select
                    wire:model.live="softCopyFilter"
                    class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="all">{{ __('All Softcopy') }}</option>
                    <option value="uploaded">{{ __('Uploaded PDF') }}</option>
                    <option value="pending">{{ __('Missing') }}</option>
                </select>
            </div>

            <!-- Expiring Filter Toggle -->
            <div>
                <label class="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">{{ __('Urgency') }}</label>
                <button
                    type="button"
                    wire:click="$toggle('expiringFilter')"
                    @class([
                        'w-full h-9 px-2.5 text-xs rounded-lg border flex items-center justify-between font-semibold cursor-pointer transition-colors',
                        'bg-amber-500/10 border-amber-500/30 text-amber-700 dark:text-amber-300' => $expiringFilter,
                        'border-border bg-background text-muted-foreground' => ! $expiringFilter,
                    ])
                >
                    <span>{{ __('Expiring Soon') }}</span>
                    <x-icon name="alert-circle" class="w-3.5 h-3.5" />
                </button>
            </div>
        </div>

        <!-- Search Bar with Live Indicators -->
        <div class="relative">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search dynamically by student name, email, student ID / URN, enrollment ID, course, batch, section, room or bed number...') }}"
                class="w-full h-10 pl-9 pr-3 text-xs rounded-xl border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
            />
            <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-3" />
        </div>
    </div>

    <!-- Allocations Master Table -->
    <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase font-semibold border-b border-border">
                    <tr>
                        <th class="p-3.5 cursor-pointer hover:text-foreground" wire:click="setSort('allocation_no')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Allotment Ref') }}</span>
                                @if ($sortBy === 'allocation_no')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3 h-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="p-3.5">{{ __('Resident & Identity') }}</th>
                        <th class="p-3.5">{{ __('Current Academic Enrollment') }}</th>
                        <th class="p-3.5">{{ __('Room & Bed Allotted') }}</th>
                        <th class="p-3.5 cursor-pointer hover:text-foreground" wire:click="setSort('start_date')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Timeline & Stays') }}</span>
                                @if ($sortBy === 'start_date')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3 h-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="p-3.5">{{ __('Forms Compliance') }}</th>
                        <th class="p-3.5 cursor-pointer hover:text-foreground" wire:click="setSort('status')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Status') }}</span>
                                @if ($sortBy === 'status')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3 h-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="p-3.5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($allocations as $a)
                        <tr class="hover:bg-secondary/20 transition-colors" wire:key="alloc-row-{{ $a->id }}">
                            <!-- Allotment Ref -->
                            <td class="p-3.5">
                                <span class="font-extrabold text-foreground font-mono">{{ $a->allocation_no }}</span>
                                <div class="text-[10px] text-muted-foreground mt-0.5">
                                    {{ __('Allotted') }} {{ $a->created_at->format('d M Y') }}
                                </div>
                            </td>

                            <!-- Resident & Identity Details -->
                            <td class="p-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-full bg-secondary flex items-center justify-center font-bold text-xs text-foreground shrink-0">
                                        {{ substr($a->user->name ?? 'U', 0, 2) }}
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-bold text-foreground">{{ $a->user->name }}</span>
                                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-secondary text-secondary-foreground">
                                                {{ $a->resident_type }}
                                            </span>
                                        </div>
                                        <div class="text-[11px] text-muted-foreground flex flex-wrap items-center gap-1 mt-0.5">
                                            <span class="font-mono text-primary font-bold">{{ $a->student_identifier }}</span>
                                            <span>&bull; {{ $a->user->email }}</span>
                                        </div>
                                        @if ($a->is_previous_batch_student)
                                            <span class="inline-block px-1.5 py-0.2 rounded text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 mt-0.5">
                                                {{ __('Past Resident:') }} {{ $a->previous_batch_name }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <!-- Current Academic Enrollment (Student ID, Enrollment ID, Course, Batch, Section) -->
                            <td class="p-3.5">
                                @if ($a->isResidentStudent() || $a->course_name)
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-1.5">
                                            <span class="px-2 py-0.5 rounded text-[11px] font-extrabold bg-primary/10 text-primary border border-primary/20">
                                                {{ __('Enr:') }} {{ $a->enrollment_no }}
                                            </span>
                                            @if ($a->section_name)
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-secondary text-foreground">
                                                    {{ __('Sec:') }} {{ $a->section_name }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="font-semibold text-foreground text-xs leading-tight">
                                            {{ $a->course_name }}
                                        </div>
                                        @if ($a->batch_name)
                                            <div class="text-[11px] text-muted-foreground">
                                                {{ __('Batch:') }} {{ $a->batch_name }}
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <div class="text-xs text-muted-foreground italic">
                                        {{ __('Staff / Non-enrolled resident') }}
                                    </div>
                                @endif
                            </td>

                            <!-- Room & Bed -->
                            <td class="p-3.5">
                                <div class="font-bold text-foreground">
                                    {{ $a->room->building->name ?? '' }} - Room {{ $a->room->room_number ?? '' }}
                                </div>
                                <div class="text-[11px] text-muted-foreground flex items-center gap-1.5 mt-0.5">
                                    <span class="text-primary font-semibold">{{ $a->bed->bed_number ?? '' }}</span>
                                    <span>&bull;</span>
                                    <span>{{ $a->room->floor->name ?? '' }}</span>
                                    @if ($a->room && $a->room->isSharing())
                                        <span class="px-1 py-0.2 rounded text-[9px] font-semibold bg-purple-500/10 text-purple-600">
                                            {{ __('Sharing') }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <!-- Timeline & Duration -->
                            <td class="p-3.5">
                                <div class="font-semibold text-foreground">
                                    {{ $a->start_date->format('d M Y') }}
                                    &rarr;
                                    {{ $a->end_date ? $a->end_date->format('d M Y') : __('Indefinite') }}
                                </div>

                                @if ($a->isActive() && $a->end_date)
                                    <div class="text-[11px] mt-0.5">
                                        @if ($a->daysRemaining() > 15)
                                            <span class="text-emerald-600 font-semibold">{{ $a->daysRemaining() }} {{ __('days left') }}</span>
                                        @elseif ($a->daysRemaining() >= 0)
                                            <span class="text-amber-600 font-bold">{{ $a->daysRemaining() }} {{ __('days left (Expiring Soon)') }}</span>
                                        @else
                                            <span class="text-rose-600 font-bold">{{ abs($a->daysRemaining()) }} {{ __('days overdue') }}</span>
                                        @endif
                                    </div>
                                @elseif ($a->status === 'vacated')
                                    <div class="text-[11px] text-muted-foreground mt-0.5">
                                        {{ __('Vacated on ') }}{{ $a->actual_vacated_date?->format('d M Y') }}
                                    </div>
                                @endif
                            </td>

                            <!-- Forms Compliance & Download -->
                            <td class="p-3.5">
                                <div class="space-y-1">
                                    <!-- Hardcopy -->
                                    <button
                                        type="button"
                                        wire:click="toggleHardCopyStatus({{ $a->id }})"
                                        class="inline-flex items-center gap-1.5 cursor-pointer text-[11px] hover:underline"
                                    >
                                        @if ($a->hard_copy_submitted)
                                            <x-icon name="file-check" class="w-3.5 h-3.5 text-emerald-600" />
                                            <span class="text-emerald-600 font-semibold">{{ __('Hardcopy In') }}</span>
                                        @else
                                            <x-icon name="alert-circle" class="w-3.5 h-3.5 text-amber-600" />
                                            <span class="text-amber-600 font-semibold">{{ __('Hardcopy Due') }}</span>
                                        @endif
                                    </button>

                                    <!-- Softcopy & Download -->
                                    <div class="flex items-center gap-2">
                                        @if ($a->soft_copy_path)
                                            <a
                                                href="{{ Storage::disk('public')->url($a->soft_copy_path) }}"
                                                target="_blank"
                                                class="inline-flex items-center gap-1 text-[11px] text-blue-600 dark:text-blue-400 hover:underline"
                                            >
                                                <x-icon name="download" class="w-3.5 h-3.5" />
                                                {{ __('Softcopy') }}
                                            </a>
                                        @endif

                                        <a
                                            href="{{ route('hostel.form.download', ['allocation_id' => $a->id]) }}"
                                            target="_blank"
                                            class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline font-semibold"
                                            title="{{ __('Download Institutional Hostel Form (PDF)') }}"
                                        >
                                            <x-icon name="file-down" class="w-3.5 h-3.5" />
                                            {{ __('PDF Form') }}
                                        </a>
                                    </div>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="p-3.5">
                                <span @class([
                                    'px-2 py-0.5 rounded-full text-[11px] font-bold uppercase',
                                    'bg-emerald-500/10 text-emerald-600' => $a->status === 'active',
                                    'bg-blue-500/10 text-blue-600' => $a->status === 'extended',
                                    'bg-secondary text-muted-foreground' => $a->status === 'vacated',
                                    'bg-rose-500/10 text-rose-600' => $a->status === 'cancelled',
                                ])>
                                    {{ $a->status_label }}
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="p-3.5 text-right space-x-1">
                                <button
                                    type="button"
                                    wire:click="openDossier({{ $a->id }})"
                                    class="p-1 rounded text-muted-foreground hover:text-foreground cursor-pointer"
                                    title="{{ __('View Resident Dossier') }}"
                                >
                                    <x-icon name="eye" class="w-4 h-4" />
                                </button>

                                @if ($a->isActive())
                                    <button
                                        type="button"
                                        wire:click="openExtendModal({{ $a->id }})"
                                        class="p-1 rounded text-muted-foreground hover:text-primary cursor-pointer"
                                        title="{{ __('Extend Timeline') }}"
                                    >
                                        <x-icon name="calendar-plus" class="w-4 h-4" />
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="openVacateModal({{ $a->id }})"
                                        class="p-1 rounded text-muted-foreground hover:text-rose-600 cursor-pointer"
                                        title="{{ __('Vacate Bed') }}"
                                    >
                                        <x-icon name="user-minus" class="w-4 h-4" />
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-10 text-center text-xs text-muted-foreground">
                                <x-icon name="bed" class="w-10 h-10 text-muted-foreground mx-auto mb-2 opacity-60" />
                                <div class="font-bold text-foreground text-sm">{{ __('No Hostel Allocations Found') }}</div>
                                <p class="mt-1">{{ __('Try adjusting your filter, building, search terms or create a new accommodation.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $allocations->links() }}
        </div>
    </div>

    <!-- Modal: Batch Import Accommodations -->
    <x-ui.modal name="allocation-import-modal" max-width="max-w-lg" :title="__('Batch Import Hostel Accommodations')">
        <form wire:submit="importAllocations" class="space-y-4">
            <div class="p-3 rounded-xl border border-primary/20 bg-primary/5 flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-foreground block">{{ __('Need standard format template?') }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ __('Download our CSV template with sample rows and headers.') }}</span>
                </div>
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    wire:click="downloadSampleTemplate"
                    class="text-xs shrink-0 cursor-pointer"
                >
                    <x-icon name="download" class="w-3.5 h-3.5 mr-1 text-primary" />
                    {{ __('Template CSV') }}
                </x-ui.button>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Spreadsheet File (.xlsx, .csv)') }} *</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,.xlsx,.xls,.txt"
                    class="w-full text-xs text-muted-foreground file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                    required
                />
                @error('importFile') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            @if ($importSummary)
                <div class="p-3 rounded-xl border border-border bg-secondary/30 space-y-2 text-xs">
                    <span class="font-bold text-foreground block">{{ __('Import Results Breakdown:') }}</span>
                    <div class="flex items-center gap-4">
                        <span class="text-emerald-600 font-bold">{{ $importSummary['imported'] }} {{ __('Imported') }}</span>
                        <span class="text-amber-600 font-bold">{{ $importSummary['skipped'] }} {{ __('Skipped') }}</span>
                    </div>
                    @if (! empty($importSummary['errors']))
                        <div class="max-h-32 overflow-y-auto space-y-1 text-[11px] text-rose-600 dark:text-rose-400 bg-background p-2 rounded border border-border">
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
                    x-on:click="$store.modals.close('allocation-import-modal')"
                >
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground cursor-pointer"
                >
                    <x-icon name="upload" class="w-3.5 h-3.5 mr-1" />
                    {{ __('Process Import') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Create New Accommodation -->
    <x-ui.modal name="allocation-create-modal" max-width="max-w-2xl" :title="__('New Room Accommodation & Check-In')">
        <form wire:submit="saveAllocation" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Resident Type') }} *</label>
                    <select
                        wire:model.live="formResidentType"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    >
                        <option value="student">{{ __('Student') }}</option>
                        <option value="staff">{{ __('Faculty / Staff') }}</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Resident / Student') }} *</label>
                    <select
                        wire:model="formUserId"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        required
                    >
                        <option value="">{{ __('-- Select Resident --') }}</option>
                        @foreach ($this->selectableUsers as $u)
                            <option value="{{ $u->id }}">
                                {{ $u->name }} @if ($u->student_id) ({{ $u->student_id }}) @endif - {{ ucfirst($u->gender ?? $u->studentProfile?->gender ?? 'N/A') }}
                            </option>
                        @endforeach
                    </select>
                    @error('formUserId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Previous Batch Student Toggle -->
            <div class="p-3.5 rounded-xl border border-amber-500/30 bg-amber-500/5 space-y-2">
                <label class="flex items-center gap-2 text-xs font-bold text-amber-900 dark:text-amber-200 cursor-pointer">
                    <input type="checkbox" wire:model.live="formIsPreviousBatch" class="rounded text-primary focus:ring-primary" />
                    <span>{{ __('Previous Batch Student / Historical Resident') }}</span>
                </label>
                <p class="text-[11px] text-muted-foreground">
                    {{ __('Enable this if the student has been accommodated in the hostel from last few years.') }}
                </p>

                @if ($formIsPreviousBatch)
                    <div class="pt-2">
                        <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Past Batch Name / Academic Reference') }} *</label>
                        <input
                            type="text"
                            wire:model="formPreviousBatchName"
                            placeholder="{{ __('e.g. UPSC Prelims 2023, WBCS Foundation 2022') }}"
                            class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        />
                        @error('formPreviousBatchName') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            <!-- Building, Floor, Room, Bed Cascade -->
            <div class="p-3.5 rounded-xl border border-border bg-secondary/20 space-y-3">
                <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('Select Building, Room & Bed') }}
                </span>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <!-- Building -->
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Building') }} *</label>
                        <select
                            wire:model.live="formBuildingId"
                            class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            required
                        >
                            <option value="">{{ __('-- Building --') }}</option>
                            @foreach ($this->buildings as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Floor -->
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Floor') }} *</label>
                        <select
                            wire:model.live="formFloorId"
                            class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            required
                            @disabled(! $formBuildingId)
                        >
                            <option value="">{{ __('-- Floor --') }}</option>
                            @foreach ($this->formFloors as $fl)
                                <option value="{{ $fl->id }}">{{ $fl->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Room -->
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Room') }} *</label>
                        <select
                            wire:model.live="formRoomId"
                            class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            required
                            @disabled(! $formFloorId)
                        >
                            <option value="">{{ __('-- Room --') }}</option>
                            @foreach ($this->formRooms as $rm)
                                <option value="{{ $rm->id }}">
                                    {{ $rm->room_number }} ({{ $rm->gender_label }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Bed -->
                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Available Bed') }} *</label>
                        <select
                            wire:model="formBedId"
                            class="w-full h-9 px-2 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            required
                            @disabled(! $formRoomId)
                        >
                            <option value="">{{ __('-- Bed --') }}</option>
                            @foreach ($this->formBeds as $bd)
                                <option value="{{ $bd->id }}">{{ $bd->bed_number }}</option>
                            @endforeach
                        </select>
                        @error('formBedId') <span class="text-xs text-rose-500 mt-0.5 block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <!-- Timeline Configuration -->
            <div class="p-3.5 rounded-xl border border-border bg-background space-y-3">
                <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('Accommodation Timeline') }}
                </span>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Start Date / Check-In') }} *</label>
                        <input
                            type="date"
                            wire:model="formStartDate"
                            class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                            required
                        />
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Duration Mode') }}</label>
                        <select
                            wire:model.live="formDurationType"
                            class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                        >
                            <option value="months">{{ __('Duration in Months') }}</option>
                            <option value="days">{{ __('Duration in Days') }}</option>
                            <option value="years">{{ __('Duration in Years') }}</option>
                            <option value="custom">{{ __('Custom End Date') }}</option>
                            <option value="indefinite">{{ __('Indefinite / Open Stay') }}</option>
                        </select>
                    </div>

                    @if (in_array($formDurationType, ['days', 'months', 'years'], true))
                        <div>
                            <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Duration Value') }} *</label>
                            <input
                                type="number"
                                wire:model="formDurationValue"
                                min="1"
                                max="1000"
                                class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                                required
                            />
                        </div>
                    @elseif ($formDurationType === 'custom')
                        <div>
                            <label class="block text-[11px] font-semibold text-foreground uppercase mb-1">{{ __('Custom End Date') }} *</label>
                            <input
                                type="date"
                                wire:model="formCustomEndDate"
                                class="w-full h-9 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                                required
                            />
                            @error('formCustomEndDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    @endif
                </div>
            </div>

            <!-- Forms Compliance & Verification -->
            <div class="p-3.5 rounded-xl border border-border bg-secondary/10 space-y-3">
                <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('Forms Compliance & Verification') }}
                </span>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer font-medium">
                            <input type="checkbox" wire:model.live="formHardCopySubmitted" class="rounded text-primary focus:ring-primary" />
                            <span>{{ __('Hardcopy Admission Form Submitted') }}</span>
                        </label>

                        @if ($formHardCopySubmitted)
                            <div>
                                <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Submission Date') }}</label>
                                <input
                                    type="date"
                                    wire:model="formHardCopySubmittedAt"
                                    class="w-full h-8 px-2.5 text-xs rounded-md border border-border bg-background"
                                />
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="block text-[11px] text-muted-foreground mb-1">{{ __('Upload Signed Softcopy Form (PDF / Image)') }}</label>
                        <input
                            type="file"
                            wire:model="formSoftCopyFile"
                            accept=".pdf,image/*"
                            class="w-full text-xs text-muted-foreground file:mr-2 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-[11px] file:bg-primary file:text-primary-foreground"
                        />
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Allotment Remarks & Notes') }}</label>
                <textarea
                    wire:model="formRemarks"
                    rows="2"
                    placeholder="{{ __('Any special accommodation instructions, medical considerations...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('allocation-create-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                >
                    {{ __('Complete Allocation') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Extend Accommodation Timeline -->
    <x-ui.modal name="allocation-extend-modal" max-width="max-w-md" :title="__('Extend Accommodation Timeline')">
        <form wire:submit="processExtension" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('New Extended End Date') }} *</label>
                <input
                    type="date"
                    wire:model="extendNewEndDate"
                    class="w-full h-10 px-3 text-sm rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                />
                @error('extendNewEndDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Extension Reason / Notes') }}</label>
                <textarea
                    wire:model="extendNotes"
                    rows="3"
                    placeholder="{{ __('e.g. Preparation for Mains examination, course extended by department...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('allocation-extend-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                >
                    {{ __('Extend Accommodation') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Vacate Resident -->
    <x-ui.modal name="allocation-vacate-modal" max-width="max-w-md" :title="__('Vacate Resident & Release Bed')">
        <form wire:submit="processVacate" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Actual Vacated Date') }} *</label>
                <input
                    type="date"
                    wire:model="vacateDate"
                    class="w-full h-10 px-3 text-sm rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Remarks / Dues Cleared') }}</label>
                <textarea
                    wire:model="vacateRemarks"
                    rows="3"
                    placeholder="{{ __('Keys returned, room condition verified...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('allocation-vacate-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-rose-600 hover:bg-rose-700 text-white"
                >
                    {{ __('Confirm Vacate') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Full Allotment Dossier -->
    <x-ui.modal name="allocation-dossier-modal" max-width="max-w-2xl" :title="__('Hostel Allotment Dossier')">
        @if ($this->viewingAllocation)
            @php
                $al = $this->viewingAllocation;
            @endphp
            <div class="space-y-4">
                <div class="p-4 rounded-xl border border-border bg-secondary/30 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ $al->user->name }}</h3>
                        <div class="text-xs text-muted-foreground mt-0.5">
                            @if ($al->user->student_id)
                                <span>ID: {{ $al->user->student_id }}</span> &bull;
                            @endif
                            <span>{{ $al->user->email }}</span>
                        </div>
                    </div>
                    <span class="px-2.5 py-0.5 rounded text-xs font-bold uppercase bg-primary text-primary-foreground">
                        {{ $al->resident_type }}
                    </span>
                </div>

                <!-- Academic Enrollment Card -->
                @if ($al->isResidentStudent() || $al->course_name)
                    <div class="p-3.5 rounded-xl border border-primary/20 bg-primary/5 space-y-2">
                        <span class="text-xs font-bold text-primary uppercase tracking-wider block">
                            {{ __('Student Enrollment & Academic Details') }}
                        </span>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Student ID / URN') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $al->student_identifier }}</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Enrollment ID') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $al->enrollment_no }}</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Course & Batch') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $al->course_name }} ({{ $al->batch_name }})</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Section') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $al->section_name }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Building') }}</span>
                        <span class="text-xs font-bold text-foreground mt-0.5 block">{{ $al->room->building->name ?? '' }}</span>
                    </div>
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Room No') }}</span>
                        <span class="text-xs font-bold text-foreground mt-0.5 block">{{ $al->room->room_number ?? '' }}</span>
                    </div>
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Bed') }}</span>
                        <span class="text-xs font-bold text-primary mt-0.5 block">{{ $al->bed->bed_number ?? '' }}</span>
                    </div>
                    <div class="p-3 rounded-lg border border-border bg-background">
                        <span class="text-[10px] text-muted-foreground uppercase font-bold block">{{ __('Gender Allowed') }}</span>
                        <span class="text-xs font-bold text-foreground mt-0.5 block">{{ $al->room->gender_label }}</span>
                    </div>
                </div>

                <!-- Hostel Management & Caretakers -->
                <div class="p-3.5 rounded-xl border border-border bg-background space-y-2 text-xs">
                    <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                        {{ __('Hostel Administration & Caretakers') }}
                    </span>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                        <div class="p-2 rounded-lg bg-secondary/30 border border-border">
                            <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Hostel Manager') }}</span>
                            <span class="font-bold text-foreground">{{ $al->room?->building?->manager_name ?? __('Not Assigned') }}</span>
                            @if ($al->room?->building?->manager_contact)
                                <span class="text-[11px] text-muted-foreground block">{{ $al->room->building->manager_contact }}</span>
                            @endif
                        </div>
                        <div class="p-2 rounded-lg bg-secondary/30 border border-border">
                            <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Hostel Warden') }}</span>
                            <span class="font-bold text-foreground">{{ $al->room?->building?->warden_name ?? __('Not Assigned') }}</span>
                            @if ($al->room?->building?->warden_contact)
                                <span class="text-[11px] text-muted-foreground block">{{ $al->room->building->warden_contact }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($al->room?->building?->caretakers && $al->room->building->caretakers->isNotEmpty())
                        <div class="pt-1.5 flex flex-wrap items-center gap-1.5">
                            <span class="text-[10px] font-semibold text-muted-foreground uppercase">{{ __('Caretakers on duty:') }}</span>
                            @foreach ($al->room->building->caretakers as $ct)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-secondary text-foreground border border-border">
                                    {{ $ct->name }} ({{ ucfirst($ct->shift) }}: {{ $ct->phone }})
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="p-3.5 rounded-xl border border-border bg-background space-y-2 text-xs">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">{{ __('Check-In Date') }}:</span>
                        <span class="font-bold text-foreground">{{ $al->start_date->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">{{ __('Accommodation End Date') }}:</span>
                        <span class="font-bold text-foreground">{{ $al->end_date ? $al->end_date->format('d M Y') : __('Indefinite') }}</span>
                    </div>
                    @if ($al->end_date)
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">{{ __('Days Remaining') }}:</span>
                            <span class="font-bold text-primary">{{ $al->daysRemaining() }} {{ __('Days') }}</span>
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                    <div class="p-3 rounded-xl border border-border bg-background flex items-center justify-between">
                        <div>
                            <span class="font-bold text-foreground block">{{ __('Hardcopy Form') }}</span>
                            <span class="text-[11px] text-muted-foreground">
                                {{ $al->hard_copy_submitted ? __('Submitted') : __('Pending Submission') }}
                            </span>
                        </div>
                        <x-ui.button
                            type="button"
                            variant="outline"
                            size="sm"
                            wire:click="toggleHardCopyStatus({{ $al->id }})"
                            class="text-xs h-7 px-2"
                        >
                            {{ $al->hard_copy_submitted ? __('Mark Pending') : __('Mark Submitted') }}
                        </x-ui.button>
                    </div>

                    <div class="p-3 rounded-xl border border-border bg-background flex items-center justify-between">
                        <div>
                            <span class="font-bold text-foreground block">{{ __('Softcopy Form') }}</span>
                            <span class="text-[11px] text-muted-foreground">
                                {{ $al->soft_copy_path ? __('Uploaded') : __('Not Uploaded') }}
                            </span>
                        </div>
                        @if ($al->soft_copy_path)
                            <a
                                href="{{ Storage::disk('public')->url($al->soft_copy_path) }}"
                                target="_blank"
                                class="text-xs font-bold text-primary hover:underline inline-flex items-center gap-1"
                            >
                                <x-icon name="download" class="w-3.5 h-3.5" />
                                {{ __('Download') }}
                            </a>
                        @endif
                    </div>
                </div>

                @if ($al->remarks)
                    <div class="p-3 rounded-lg bg-secondary/50 text-xs">
                        <span class="font-bold text-foreground block mb-1">{{ __('Remarks & Notes') }}:</span>
                        <p class="text-muted-foreground whitespace-pre-line">{{ $al->remarks }}</p>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <a
                        href="{{ route('hostel.form.download', ['allocation_id' => $al->id]) }}"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
                    >
                        <x-icon name="file-down" class="w-3.5 h-3.5" />
                        {{ __('Download Official Form (PDF)') }}
                    </a>

                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        x-on:click="$store.modals.close('allocation-dossier-modal')"
                    >
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
