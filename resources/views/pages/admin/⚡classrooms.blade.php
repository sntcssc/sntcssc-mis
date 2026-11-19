<?php

use App\Models\Classroom;
use App\Models\Course;
use App\Models\Section;
use App\Services\ClassroomService;
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

new #[Layout('layouts.app')] #[Title('Classrooms Master Management - SNTCSSC MIS')] class extends Component
{
    use WithFileUploads, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $buildingFilter = '';

    #[Url]
    public string $typeFilter = '';

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

    public int $perPage = 15;

    // Create / Edit modal
    public bool $showFormModal = false;

    public ?int $editingId = null;

    public string $room_code = '';

    public string $name = '';

    public string $building = '';

    public string $floor = '';

    public ?int $capacity = null;

    public string $room_type = 'lecture_hall';

    public string $status = 'active';

    public array $facilities = [];

    public array $assigned_courses = [];

    public array $assigned_sections = [];

    public string $remarks = '';

    // View Details Modal
    public bool $showDetailsModal = false;

    public ?Classroom $selectedRoom = null;

    // Delete Modal
    public bool $showDeleteModal = false;

    public ?int $deletingId = null;

    public string $deletingName = '';

    // Import Modal
    public bool $showImportModal = false;

    public $importFile = null;

    public array $importResult = [];

    public const ROOM_TYPES = [
        'lecture_hall' => 'Lecture Hall',
        'seminar_room' => 'Seminar Room',
        'smart_classroom' => 'Smart Classroom',
        'lab' => 'Computer / Language Lab',
        'auditorium' => 'Auditorium / Amphitheatre',
        'other' => 'Other / Discussion Room',
    ];

    public const FACILITY_OPTIONS = [
        'Projector' => 'Projector',
        'Smartboard' => 'Interactive Smartboard',
        'Air Conditioned' => 'Air Conditioned (AC)',
        'Sound System' => 'Audio & Mic Sound System',
        'Video Conferencing' => 'Video Conferencing Kit',
        'Wi-Fi' => 'High-Speed Wi-Fi / LAN',
        'Podium' => 'Faculty Podium / Lectern',
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBuildingFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function sections()
    {
        return Section::orderBy('name')->get();
    }

    #[Computed]
    public function buildings()
    {
        return Classroom::distinct()->whereNotNull('building')->where('building', '!=', '')->pluck('building')->sort()->values();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Classroom::count(),
            'active' => Classroom::where('status', 'active')->count(),
            'maintenance' => Classroom::where('status', 'maintenance')->count(),
            'total_capacity' => (int) Classroom::where('status', 'active')->sum('capacity'),
        ];
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->room_code = '';
        $this->name = '';
        $this->building = 'Main Academic Block';
        $this->floor = '1st Floor';
        $this->capacity = 60;
        $this->room_type = 'lecture_hall';
        $this->status = 'active';
        $this->facilities = ['Projector', 'Air Conditioned', 'Sound System'];
        $this->assigned_courses = [];
        $this->assigned_sections = [];
        $this->remarks = '';
        $this->dispatch('modal-open', name: 'classroom-form-modal');
    }

    public function openEditModal(int $id): void
    {
        $this->resetValidation();
        $room = Classroom::findOrFail($id);
        $this->editingId = $room->id;
        $this->room_code = $room->room_code;
        $this->name = $room->name;
        $this->building = $room->building;
        $this->floor = $room->floor;
        $this->capacity = $room->capacity;
        $this->room_type = $room->room_type;
        $this->status = $room->status;
        $this->facilities = $room->facilities ?? [];
        $this->assigned_courses = $room->assigned_courses ?? [];
        $this->assigned_sections = $room->assigned_sections ?? [];
        $this->remarks = $room->remarks ?? '';
        $this->dispatch('modal-open', name: 'classroom-form-modal');
    }

    public function save(): void
    {
        $rules = [
            'name' => 'required|string|max:191',
            'building' => 'required|string|max:100',
            'floor' => 'required|string|max:50',
            'capacity' => 'nullable|integer|min:1|max:5000',
            'room_type' => 'required|string|in:lecture_hall,seminar_room,smart_classroom,lab,auditorium,other',
            'status' => 'required|string|in:active,inactive,maintenance',
            'room_code' => 'nullable|string|max:50|unique:classrooms,room_code,'.($this->editingId ?? 'NULL').',id',
            'facilities' => 'nullable|array',
            'assigned_courses' => 'nullable|array',
            'assigned_sections' => 'nullable|array',
            'remarks' => 'nullable|string|max:1000',
        ];

        $validated = $this->validate($rules);

        $data = [
            'name' => $this->name,
            'building' => $this->building,
            'floor' => $this->floor,
            'capacity' => $this->capacity,
            'room_type' => $this->room_type,
            'status' => $this->status,
            'facilities' => array_values($this->facilities),
            'assigned_courses' => ! empty($this->assigned_courses) ? array_map('intval', $this->assigned_courses) : null,
            'assigned_sections' => ! empty($this->assigned_sections) ? array_map('intval', $this->assigned_sections) : null,
            'remarks' => $this->remarks,
        ];

        if (! empty($this->room_code)) {
            $data['room_code'] = $this->room_code;
        }

        if ($this->editingId) {
            $room = Classroom::findOrFail($this->editingId);
            ClassroomService::updateClassroom($room, $data);
            Toast::dispatch($this, 'success', __("Classroom ':name' updated successfully.", ['name' => $room->name]));
        } else {
            $newRoom = ClassroomService::createClassroom($data);
            Toast::dispatch($this, 'success', __("Classroom ':name' created successfully.", ['name' => $newRoom->name]));
        }

        $this->dispatch('modal-close', name: 'classroom-form-modal');
        $this->editingId = null;
    }

    public function viewDetails(int $id): void
    {
        $this->selectedRoom = Classroom::with([
            'classSchedules' => function ($q) {
                $q->orderBy('class_date', 'desc')->take(10)->with(['batch', 'course', 'faculties']);
            },
        ])->findOrFail($id);

        $this->dispatch('modal-open', name: 'classroom-details-modal');
    }

    public function confirmDelete(int $id, string $name): void
    {
        $this->deletingId = $id;
        $this->deletingName = $name;
        $this->dispatch('modal-open', name: 'classroom-delete-modal');
    }

    public function deleteClassroom(): void
    {
        if (! $this->deletingId) {
            return;
        }

        $room = Classroom::findOrFail($this->deletingId);
        ClassroomService::deleteClassroom($room);

        Toast::dispatch($this, 'info', __("Classroom ':name' deleted successfully.", ['name' => $this->deletingName]));
        $this->dispatch('modal-close', name: 'classroom-delete-modal');
        $this->deletingId = null;
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importResult = [];
        $this->dispatch('modal-open', name: 'classroom-import-modal');
    }

    public function downloadTemplate()
    {
        $path = ClassroomService::generateSampleTemplate();

        return response()->download($path, 'classrooms_import_template.xlsx')->deleteFileAfterSend(true);
    }

    public function processImport(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $realPath = $this->importFile->getRealPath();
        $result = ClassroomService::importFromSpreadsheet($realPath);
        $this->importResult = $result;

        if ($result['imported'] > 0) {
            $this->dispatch('toast', message: "Successfully imported {$result['imported']} classroom(s).", type: 'success');
        }
    }

    public function export(string $format = 'xlsx')
    {
        $query = $this->buildClassroomsQuery();

        return ClassroomService::exportClassrooms($query, $format);
    }

    private function buildClassroomsQuery(): Builder
    {
        return Classroom::query()
            ->when($this->search, function (Builder $q) {
                $q->where(function ($sub) {
                    $sub->where('name', 'like', "%{$this->search}%")
                        ->orWhere('room_code', 'like', "%{$this->search}%")
                        ->orWhere('building', 'like', "%{$this->search}%")
                        ->orWhere('floor', 'like', "%{$this->search}%")
                        ->orWhere('remarks', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->buildingFilter, fn (Builder $q) => $q->where('building', $this->buildingFilter))
            ->when($this->typeFilter, fn (Builder $q) => $q->where('room_type', $this->typeFilter))
            ->orderBy($this->sortField, $this->sortDirection);
    }

    public function render()
    {
        $classrooms = $this->buildClassroomsQuery()->paginate($this->perPage);

        return view('pages.admin.⚡classrooms', [
            'classrooms' => $classrooms,
            'stats' => $this->stats,
            'buildingsList' => $this->buildings,
            'coursesList' => $this->courses,
            'sectionsList' => $this->sections,
        ]);
    }
};
?>

<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action Buttons -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-primary-600 dark:hover:text-primary-400">{{ __('Dashboard') }}</a>
                <x-icon name="chevron-right" class="w-3.5 h-3.5" />
                <a href="{{ route('admin.classes.index') }}" class="hover:text-primary-600 dark:hover:text-primary-400">{{ __('Academics') }}</a>
                <x-icon name="chevron-right" class="w-3.5 h-3.5" />
                <span class="text-slate-700 dark:text-slate-200">{{ __('Classrooms Master') }}</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white flex items-center gap-2.5">
                <div class="p-2 rounded-xl bg-primary-50 dark:bg-primary-950/50 text-primary-600 dark:text-primary-400 ring-1 ring-primary-500/20">
                    <x-icon name="building" class="w-6 h-6" />
                </div>
                <span>{{ __('Classrooms Master Management') }}</span>
            </h1>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                {{ __('Manage physical halls, smart classrooms, seating capacities, and assign to course routines.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Export dropdown -->
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" type="button" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/50 shadow-sm transition">
                    <x-icon name="download" class="w-3.5 h-3.5" />
                    <span>{{ __('Export') }}</span>
                    <x-icon name="chevron-down" class="w-3.5 h-3.5 opacity-60" />
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak class="absolute right-0 mt-1 w-36 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg py-1 z-30 text-xs">
                    <button wire:click="export('xlsx')" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center gap-2">
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600" />
                        <span>{{ __('Excel (.xlsx)') }}</span>
                    </button>
                    <button wire:click="export('csv')" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center gap-2">
                        <x-icon name="file-text" class="w-3.5 h-3.5 text-blue-600" />
                        <span>{{ __('CSV (.csv)') }}</span>
                    </button>
                    <button wire:click="export('pdf')" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center gap-2">
                        <x-icon name="download" class="w-3.5 h-3.5 text-rose-600" />
                        <span>{{ __('PDF (.pdf)') }}</span>
                    </button>
                </div>
            </div>

            <!-- Import button -->
            <x-ui.button type="button" variant="outline" size="sm" class="h-9 gap-1.5" wire:click="openImportModal" x-data x-on:click="$store.modals.open('classroom-import-modal')">
                <x-icon name="upload" class="w-3.5 h-3.5" />
                <span>{{ __('Import') }}</span>
            </x-ui.button>

            <!-- New Classroom Button -->
            <x-ui.button type="button" size="sm" class="h-9 gap-1.5" wire:click="openCreateModal" x-data x-on:click="$store.modals.open('classroom-form-modal')">
                <x-icon name="plus" class="w-4 h-4" />
                <span>{{ __('Add Classroom') }}</span>
            </x-ui.button>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="p-4 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Total Classrooms') }}</span>
                <span class="p-1.5 rounded-lg bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400">
                    <x-icon name="building" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{{ number_format($stats['total']) }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('Academic infrastructure') }}</div>
        </div>

        <div class="p-4 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Active Classrooms') }}</span>
                <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($stats['active']) }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('Available for routine') }}</div>
        </div>

        <div class="p-4 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Total Seating Capacity') }}</span>
                <span class="p-1.5 rounded-lg bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400">
                    <x-icon name="users" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($stats['total_capacity']) }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('Total student seats') }}</div>
        </div>

        <div class="p-4 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Maintenance / Inactive') }}</span>
                <span class="p-1.5 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="wrench" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($stats['maintenance']) }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('Under repair/hold') }}</div>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-4 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800 shadow-sm space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Search -->
            <div class="relative">
                <x-icon name="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('Search code, name, building...') }}" class="w-full pl-9 pr-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
            </div>

            <!-- Status filter -->
            <div>
                <select wire:model.live="statusFilter" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500">
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="maintenance">{{ __('Maintenance') }}</option>
                </select>
            </div>

            <!-- Building filter -->
            <div>
                <select wire:model.live="buildingFilter" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500">
                    <option value="">{{ __('All Buildings') }}</option>
                    @foreach ($buildingsList as $bld)
                        <option value="{{ $bld }}">{{ $bld }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Room Type filter -->
            <div>
                <select wire:model.live="typeFilter" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500">
                    <option value="">{{ __('All Room Types') }}</option>
                    @foreach (self::ROOM_TYPES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <!-- Classrooms Table -->
    <div class="rounded-xl border border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th wire:click="sortBy('room_code')" class="py-3 px-4 cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Code') }}</span>
                                @if ($sortField === 'room_code')
                                    <x-icon name="chevron-up" class="w-3.5 h-3.5 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" />
                                @endif
                            </div>
                        </th>
                        <th wire:click="sortBy('name')" class="py-3 px-4 cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Classroom Name') }}</span>
                                @if ($sortField === 'name')
                                    <x-icon name="chevron-up" class="w-3.5 h-3.5 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" />
                                @endif
                            </div>
                        </th>
                        <th wire:click="sortBy('building')" class="py-3 px-4 cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Building & Floor') }}</span>
                                @if ($sortField === 'building')
                                    <x-icon name="chevron-up" class="w-3.5 h-3.5 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" />
                                @endif
                            </div>
                        </th>
                        <th wire:click="sortBy('capacity')" class="py-3 px-4 cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Capacity') }}</span>
                                @if ($sortField === 'capacity')
                                    <x-icon name="chevron-up" class="w-3.5 h-3.5 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" />
                                @endif
                            </div>
                        </th>
                        <th class="py-3 px-4">{{ __('Type & Facilities') }}</th>
                        <th class="py-3 px-4">{{ __('Assigned Scope') }}</th>
                        <th wire:click="sortBy('status')" class="py-3 px-4 cursor-pointer hover:text-slate-700 dark:hover:text-slate-200 select-none">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Status') }}</span>
                                @if ($sortField === 'status')
                                    <x-icon name="chevron-up" class="w-3.5 h-3.5 {{ $sortDirection === 'desc' ? 'rotate-180' : '' }}" />
                                @endif
                            </div>
                        </th>
                        <th class="py-3 px-4 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700/60 text-slate-700 dark:text-slate-300">
                    @forelse ($classrooms as $room)
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-700/30 transition">
                            <td class="py-3 px-4 font-mono font-medium text-primary-600 dark:text-primary-400">
                                {{ $room->room_code }}
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-semibold text-slate-900 dark:text-white">{{ $room->name }}</div>
                                @if ($room->remarks)
                                    <div class="text-[11px] text-slate-400 truncate max-w-xs">{{ $room->remarks }}</div>
                                @endif
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-medium text-slate-800 dark:text-slate-200">{{ $room->building }}</div>
                                <div class="text-[11px] text-slate-500">{{ $room->floor }}</div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                                    <x-icon name="users" class="w-3 h-3 text-slate-400" />
                                    <span>{{ $room->capacity ? $room->capacity.' seats' : __('N/A') }}</span>
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-medium text-slate-800 dark:text-slate-200">
                                    {{ self::ROOM_TYPES[$room->room_type] ?? $room->room_type }}
                                </div>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @if (! empty($room->facilities))
                                        @foreach (array_slice($room->facilities, 0, 3) as $facility)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                                                {{ $facility }}
                                            </span>
                                        @endforeach
                                        @if (count($room->facilities) > 3)
                                            <span class="text-[10px] text-slate-400">+{{ count($room->facilities) - 3 }}</span>
                                        @endif
                                    @else
                                        <span class="text-[10px] text-slate-400">{{ __('Standard') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                @php
                                    $coursesCount = ! empty($room->assigned_courses) ? count($room->assigned_courses) : 0;
                                    $sectionsCount = ! empty($room->assigned_sections) ? count($room->assigned_sections) : 0;
                                @endphp
                                @if ($coursesCount === 0 && $sectionsCount === 0)
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                                        {{ __('Open to All Courses') }}
                                    </span>
                                @else
                                    <div class="text-[11px] font-medium text-slate-700 dark:text-slate-200">
                                        @if ($coursesCount > 0)
                                            <span>{{ $coursesCount }} {{ __('Course(s)') }}</span>
                                        @endif
                                        @if ($sectionsCount > 0)
                                            <span class="text-slate-400">·</span>
                                            <span>{{ $sectionsCount }} {{ __('Section(s)') }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 px-4">
                                @if ($room->status === 'active')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        <span>{{ __('Active') }}</span>
                                    </span>
                                @elseif ($room->status === 'maintenance')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        <span>{{ __('Maintenance') }}</span>
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400">
                                        <span>{{ __('Inactive') }}</span>
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <button wire:click="viewDetails({{ $room->id }})" title="{{ __('View Classroom Details') }}" class="p-1.5 rounded-lg text-slate-500 hover:text-primary-600 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                                        <x-icon name="eye" class="w-4 h-4" />
                                    </button>
                                    <button wire:click="openEditModal({{ $room->id }})" title="{{ __('Edit Classroom') }}" class="p-1.5 rounded-lg text-slate-500 hover:text-amber-600 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                                        <x-icon name="edit" class="w-4 h-4" />
                                    </button>
                                    <button wire:click="confirmDelete({{ $room->id }}, '{{ addslashes($room->name) }}')" title="{{ __('Delete Classroom') }}" class="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                                        <x-icon name="trash-2" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="p-3 rounded-full bg-slate-100 dark:bg-slate-700/50 text-slate-400 mb-2">
                                        <x-icon name="building" class="w-8 h-8" />
                                    </div>
                                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-300">{{ __('No classrooms found') }}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">{{ __('Adjust your search filters or click Add Classroom to create one.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($classrooms->hasPages())
            <div class="p-4 border-t border-slate-200 dark:border-slate-700">
                {{ $classrooms->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Modal -->
    <x-ui.modal name="classroom-form-modal" maxWidth="max-w-2xl" :title="$editingId ? __('Edit Classroom') : __('Create New Classroom')" :description="__('Configure classroom hall name, building, floor, seating capacity, facilities and course bindings.')">
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Room Code -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        {{ __('Room Code') }} <span class="text-slate-400 font-normal">({{ __('Auto if empty') }})</span>
                    </label>
                    <input wire:model="room_code" type="text" placeholder="e.g. CR-101" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
                    @error('room_code') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Classroom Name -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        {{ __('Classroom Name') }} <span class="text-rose-500">*</span>
                    </label>
                    <input wire:model="name" type="text" placeholder="e.g. Hall A - Smart Lecture Hall" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
                    @error('name') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Building Name -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        {{ __('Building Name') }} <span class="text-rose-500">*</span>
                    </label>
                    <input wire:model="building" type="text" placeholder="e.g. Main Academic Block" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
                    @error('building') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Floor -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        {{ __('Floor') }} <span class="text-rose-500">*</span>
                    </label>
                    <input wire:model="floor" type="text" placeholder="e.g. Ground Floor / 2nd Floor" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
                    @error('floor') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Capacity -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        {{ __('Seating Capacity (Students)') }}
                    </label>
                    <input wire:model="capacity" type="number" min="1" placeholder="e.g. 80" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
                    @error('capacity') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Room Type -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        {{ __('Room Type') }} <span class="text-rose-500">*</span>
                    </label>
                    <select wire:model="room_type" class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500">
                        @foreach (self::ROOM_TYPES as $k => $lbl)
                            <option value="{{ $k }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    @error('room_type') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Status -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        {{ __('Status') }} <span class="text-rose-500">*</span>
                    </label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input wire:model="status" type="radio" value="active" class="text-primary-600 focus:ring-primary-500" />
                            <span>{{ __('Active (Available for Routine)') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input wire:model="status" type="radio" value="maintenance" class="text-amber-600 focus:ring-amber-500" />
                            <span>{{ __('Under Maintenance') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input wire:model="status" type="radio" value="inactive" class="text-slate-600 focus:ring-slate-500" />
                            <span>{{ __('Inactive') }}</span>
                        </label>
                    </div>
                    @error('status') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
            </div>

            <!-- Facilities -->
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-2">
                    {{ __('Room Facilities & Equipment') }}
                </label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/30">
                    @foreach (self::FACILITY_OPTIONS as $facKey => $facLabel)
                        <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                            <input type="checkbox" wire:model="facilities" value="{{ $facKey }}" class="rounded text-primary-600 focus:ring-primary-500" />
                            <span>{{ $facLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Course-wise / Section-wise assignment -->
            <div class="p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/30 space-y-3">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">
                            {{ __('Assigned Courses (Optional)') }}
                        </label>
                        <span class="text-[10px] text-slate-400">{{ __('Leave empty if classroom is available for all courses') }}</span>
                    </div>
                    <div class="max-h-28 overflow-y-auto space-y-1.5 p-2 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
                        @foreach ($coursesList as $c)
                            <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                                <input type="checkbox" wire:model="assigned_courses" value="{{ $c->id }}" class="rounded text-primary-600 focus:ring-primary-500" />
                                <span>{{ $c->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">
                            {{ __('Assigned Sections (Optional)') }}
                        </label>
                        <span class="text-[10px] text-slate-400">{{ __('Leave empty if available for all sections') }}</span>
                    </div>
                    <div class="max-h-24 overflow-y-auto space-y-1.5 p-2 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
                        @foreach ($sectionsList as $sec)
                            <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                                <input type="checkbox" wire:model="assigned_sections" value="{{ $sec->id }}" class="rounded text-primary-600 focus:ring-primary-500" />
                                <span>{{ $sec->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Remarks -->
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    {{ __('Remarks & Additional Notes') }}
                </label>
                <textarea wire:model="remarks" rows="2" placeholder="e.g. Equipped with high-power audio and backup generator line." class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"></textarea>
                @error('remarks') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-slate-200 dark:border-slate-700">
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('classroom-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4" />
                    <span>{{ $editingId ? __('Update Classroom') : __('Create Classroom') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- View Details Modal -->
    <x-ui.modal name="classroom-details-modal" maxWidth="max-w-2xl" :title="$selectedRoom?->name ?? __('Classroom Dossier')" :description="__('Detailed room specifications, facilities, and recent class sessions.')">
        @if ($selectedRoom)
            <div class="space-y-4 text-xs">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700">
                    <div>
                        <span class="text-slate-400 block">{{ __('Building') }}</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ $selectedRoom->building }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block">{{ __('Floor') }}</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ $selectedRoom->floor }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block">{{ __('Capacity') }}</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ $selectedRoom->capacity ? $selectedRoom->capacity.' seats' : __('N/A') }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block">{{ __('Status') }}</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200 capitalize">{{ $selectedRoom->status }}</span>
                    </div>
                </div>

                <!-- Facilities list -->
                @if (! empty($selectedRoom->facilities))
                    <div>
                        <h4 class="font-semibold text-slate-800 dark:text-slate-200 mb-2">{{ __('Available Facilities') }}</h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($selectedRoom->facilities as $f)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-primary-50 dark:bg-primary-950/50 text-primary-700 dark:text-primary-300 border border-primary-200 dark:border-primary-800">
                                    <x-icon name="check" class="w-3.5 h-3.5" />
                                    <span>{{ $f }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Recent Class Schedules -->
                <div>
                    <h4 class="font-semibold text-slate-800 dark:text-slate-200 mb-2">{{ __('Recent & Upcoming Classes in this Room') }}</h4>
                    @if ($selectedRoom->classSchedules->isEmpty())
                        <p class="text-slate-400 italic">{{ __('No class sessions recorded yet.') }}</p>
                    @else
                        <div class="divide-y divide-slate-200 dark:divide-slate-700 rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
                            @foreach ($selectedRoom->classSchedules as $cs)
                                <div class="p-3 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <div>
                                        <div class="font-semibold text-slate-900 dark:text-white">{{ $cs->subject }}</div>
                                        <div class="text-[11px] text-slate-500">
                                            {{ $cs->class_date->format('d M Y') }} ({{ $cs->formatted_time }}) · {{ $cs->course?->name }}
                                        </div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase {{ $cs->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($cs->status === 'cancelled' ? 'bg-rose-100 text-rose-700' : 'bg-blue-100 text-blue-700') }}">
                                        {{ $cs->status }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex justify-end pt-3 border-t border-slate-200 dark:border-slate-700">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'classroom-details-modal' })" class="px-4 py-2 text-xs font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg transition">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal name="classroom-delete-modal" maxWidth="max-w-md" :title="__('Confirm Deletion')" :description="__('Are you sure you want to delete this classroom? This record will be soft deleted.')">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">{{ __('Classroom Deletion') }}</h3>
                    <p class="text-xs text-slate-500">{{ __('Are you sure you want to delete this classroom?') }}</p>
                </div>
            </div>

            <p class="text-xs text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/50 p-3 rounded-lg border border-slate-200 dark:border-slate-700">
                {{ __('Classroom:') }} <strong class="text-slate-900 dark:text-white">{{ $deletingName }}</strong>.
                <br>
                <span class="text-slate-400">{{ __('This record will be soft deleted and historical class schedules will remain preserved.') }}</span>
            </p>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-200 dark:border-slate-700">
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('classroom-delete-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" variant="destructive" size="sm" wire:click="deleteClassroom" class="gap-1.5">
                    <x-icon name="trash-2" class="h-4 w-4" />
                    <span>{{ __('Delete Classroom') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Import Modal -->
    <x-ui.modal name="classroom-import-modal" maxWidth="max-w-lg" :title="__('Bulk Import Classrooms')" :description="__('Upload an Excel (.xlsx, .xls) or CSV (.csv) spreadsheet matching the required template columns.')">
        <div class="space-y-4">
            <div class="p-3 rounded-xl bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900/50 flex items-start gap-2.5 text-xs text-blue-800 dark:text-blue-300">
                <x-icon name="info" class="w-4 h-4 shrink-0 mt-0.5" />
                <div>
                    <p class="font-semibold">{{ __('Import Instructions') }}</p>
                    <p class="mt-0.5">{{ __('Upload an Excel (.xlsx, .xls) or CSV (.csv) spreadsheet matching the required template columns.') }}</p>
                </div>
            </div>

            <div>
                <button type="button" wire:click="downloadTemplate" class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary-600 hover:text-primary-700 dark:text-primary-400">
                    <x-icon name="download" class="w-4 h-4" />
                    <span>{{ __('Download Sample Excel Template') }}</span>
                </button>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    {{ __('Select Spreadsheet File') }} <span class="text-rose-500">*</span>
                </label>
                <input type="file" wire:model="importFile" accept=".xlsx,.xls,.csv" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary-700 dark:file:bg-primary-950/50 dark:file:text-primary-300 hover:file:bg-primary-100" />
                @error('importFile') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
            </div>

            @if (! empty($importResult))
                <div class="p-3 rounded-xl {{ $importResult['imported'] > 0 ? 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-800 dark:text-emerald-300' : 'bg-rose-50 dark:bg-rose-950/50 text-rose-800 dark:text-rose-300' }} border text-xs">
                    <p class="font-bold">{{ __('Import Results:') }}</p>
                    <p>{{ __('Imported:') }} {{ $importResult['imported'] }} {{ __('classrooms') }}</p>
                    @if (! empty($importResult['errors']))
                        <ul class="list-disc list-inside mt-1 space-y-0.5 text-[11px]">
                            @foreach ($importResult['errors'] as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-slate-200 dark:border-slate-700">
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('classroom-import-modal')">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" wire:click="processImport" wire:loading.attr="disabled" class="gap-1.5 disabled:opacity-50">
                    <x-icon name="upload" class="h-4 w-4" />
                    <span wire:loading.remove wire:target="processImport">{{ __('Upload & Import') }}</span>
                    <span wire:loading wire:target="processImport">{{ __('Processing...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
