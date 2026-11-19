<?php

use App\Models\HostelAllocation;
use App\Models\HostelBuilding;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Services\Hostel\HostelImportExportService;
use App\Services\Hostel\HostelService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Hostel Reports & Analytics')] class extends Component {
    public string $activeTab = 'occupancy'; // occupancy, gender, compliance, expiring
    public ?int $selectedBuildingId = null;

    #[Computed]
    public function stats(): array
    {
        return HostelService::getStatistics($this->selectedBuildingId);
    }

    #[Computed]
    public function buildings()
    {
        return HostelBuilding::with(['floors.rooms.beds'])->orderBy('display_order')->get();
    }

    #[Computed]
    public function buildingFloorMatrix()
    {
        $buildings = HostelBuilding::with([
            'floors' => fn ($q) => $q->with([
                'rooms.beds',
            ])->orderBy('floor_number'),
        ])->orderBy('display_order')->get();

        $rows = [];
        foreach ($buildings as $b) {
            foreach ($b->floors as $fl) {
                $rooms = $fl->rooms;
                $beds = $rooms->flatMap->beds;
                $totalBeds = $beds->count();
                $occupied = $beds->where('status', 'occupied')->count();
                $available = $beds->where('status', 'available')->count();
                $maintenance = $beds->where('status', 'maintenance')->count();
                $reserved = $beds->where('status', 'reserved')->count();

                $rows[] = [
                    'building_name' => $b->name,
                    'building_code' => $b->code,
                    'floor_name' => $fl->name,
                    'gender_type' => $fl->gender_label,
                    'total_rooms' => $rooms->count(),
                    'total_beds' => $totalBeds,
                    'occupied' => $occupied,
                    'available' => $available,
                    'reserved' => $reserved,
                    'maintenance' => $maintenance,
                    'rate' => $totalBeds > 0 ? round(($occupied / $totalBeds) * 100, 1) : 0,
                ];
            }
        }

        return $rows;
    }

    #[Computed]
    public function pendingComplianceAllocations()
    {
        return HostelAllocation::with(['user.studentProfile', 'room.building', 'bed'])
            ->active()
            ->where(function ($q) {
                $q->where('hard_copy_submitted', false)
                    ->orWhereNull('soft_copy_path');
            })
            ->orderBy('id', 'desc')
            ->take(20)
            ->get();
    }

    #[Computed]
    public function upcomingExpiringAllocations()
    {
        return HostelAllocation::with(['user', 'room.building', 'bed'])
            ->active()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->orderBy('end_date')
            ->get();
    }

    public function exportReport(string $format): mixed
    {
        return match ($this->activeTab) {
            'compliance' => $this->exportCompliance($format),
            'expiring' => $this->exportExpiring($format),
            default => $this->exportMatrix($format),
        };
    }

    public function exportMatrix(string $format): mixed
    {
        return HostelImportExportService::exportReportsMatrix($this->buildingFloorMatrix, $format);
    }

    public function exportCompliance(string $format): mixed
    {
        return HostelImportExportService::exportReportsCompliance($format);
    }

    public function exportExpiring(string $format): mixed
    {
        return HostelImportExportService::exportReportsExpiring($format);
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Hostel Reports & Analytics') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                    {{ __('Analytics & Compliance') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('High-resolution occupancy reports, gender demographic metrics, form submission compliance, and stay expirations.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <!-- Universal Report Export Dropdown -->
            <div x-data="{ open: false }" class="relative">
                <x-ui.button
                    variant="outline"
                    size="sm"
                    x-on:click="open = !open"
                    class="cursor-pointer gap-1.5"
                >
                    <x-icon name="download" class="w-4 h-4 text-muted-foreground" />
                    <span>{{ __('Export Active Report') }}</span>
                    <x-icon name="chevron-down" class="w-3.5 h-3.5 text-muted-foreground ml-0.5" />
                </x-ui.button>
                <div
                    x-show="open"
                    x-on:click.outside="open = false"
                    x-transition
                    class="absolute right-0 mt-1 w-48 rounded-xl border border-border bg-card shadow-lg p-1 z-30 space-y-0.5"
                    style="display: none;"
                >
                    <button
                        type="button"
                        wire:click="exportReport('xlsx')"
                        x-on:click="open = false"
                        class="w-full text-left px-3 py-2 text-xs rounded-lg hover:bg-secondary flex items-center gap-2 cursor-pointer transition-colors"
                    >
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 text-emerald-600" />
                        <span>{{ __('Excel Spreadsheet (.xlsx)') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="exportReport('csv')"
                        x-on:click="open = false"
                        class="w-full text-left px-3 py-2 text-xs rounded-lg hover:bg-secondary flex items-center gap-2 cursor-pointer transition-colors"
                    >
                        <x-icon name="file-text" class="w-3.5 h-3.5 text-blue-600" />
                        <span>{{ __('CSV Document') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="exportReport('pdf')"
                        x-on:click="open = false"
                        class="w-full text-left px-3 py-2 text-xs rounded-lg hover:bg-secondary flex items-center gap-2 cursor-pointer transition-colors"
                    >
                        <x-icon name="file" class="w-3.5 h-3.5 text-rose-600" />
                        <span>{{ __('Landscape PDF Document') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary KPI Strip -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-xs text-muted-foreground font-semibold uppercase">{{ __('Total Beds') }}</span>
            <div class="mt-1 text-2xl font-bold text-foreground">{{ $this->stats['total_beds'] }}</div>
            <span class="text-[11px] text-muted-foreground">{{ $this->stats['total_rooms'] }} {{ __('Rooms') }}</span>
        </div>

        <div class="p-4 rounded-xl border border-blue-500/20 bg-blue-500/5 shadow-xs">
            <span class="text-xs text-blue-600 dark:text-blue-400 font-semibold uppercase">{{ __('Occupied') }}</span>
            <div class="mt-1 text-2xl font-bold text-blue-700 dark:text-blue-300">{{ $this->stats['occupied_beds'] }}</div>
            <span class="text-[11px] text-blue-600/80">{{ $this->stats['occupancy_rate'] }}% {{ __('Rate') }}</span>
        </div>

        <div class="p-4 rounded-xl border border-emerald-500/20 bg-emerald-500/5 shadow-xs">
            <span class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold uppercase">{{ __('Available (Free)') }}</span>
            <div class="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ $this->stats['available_beds'] }}</div>
            <span class="text-[11px] text-emerald-600/80">{{ __('Ready for allotment') }}</span>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-xs text-muted-foreground font-semibold uppercase">{{ __('Male Rooms') }}</span>
            <div class="mt-1 text-2xl font-bold text-blue-600">{{ $this->stats['male_rooms'] }}</div>
            <span class="text-[11px] text-muted-foreground">♂ {{ __('Designated') }}</span>
        </div>

        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <span class="text-xs text-muted-foreground font-semibold uppercase">{{ __('Female Rooms') }}</span>
            <div class="mt-1 text-2xl font-bold text-pink-600">{{ $this->stats['female_rooms'] }}</div>
            <span class="text-[11px] text-muted-foreground">♀ {{ __('Designated') }}</span>
        </div>

        <div class="p-4 rounded-xl border border-amber-500/20 bg-amber-500/5 shadow-xs">
            <span class="text-xs text-amber-600 dark:text-amber-400 font-semibold uppercase">{{ __('Form Due') }}</span>
            <div class="mt-1 text-2xl font-bold text-amber-700 dark:text-amber-300">{{ $this->stats['pending_hard_copy'] }}</div>
            <span class="text-[11px] text-amber-600/80">{{ __('Hardcopy pending') }}</span>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex flex-wrap items-center gap-2 border-b border-border pb-2">
        <button
            type="button"
            wire:click="$set('activeTab', 'occupancy')"
            @class([
                'px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-2',
                'bg-primary text-primary-foreground shadow-xs' => $activeTab === 'occupancy',
                'bg-secondary text-secondary-foreground hover:bg-secondary/80' => $activeTab !== 'occupancy',
            ])
        >
            <x-icon name="building-2" class="w-4 h-4" />
            <span>{{ __('Building & Floor Occupancy Matrix') }}</span>
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'compliance')"
            @class([
                'px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-2',
                'bg-primary text-primary-foreground shadow-xs' => $activeTab === 'compliance',
                'bg-secondary text-secondary-foreground hover:bg-secondary/80' => $activeTab !== 'compliance',
            ])
        >
            <x-icon name="file-check" class="w-4 h-4" />
            <span>{{ __('Admission Form Compliance Audit') }}</span>
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'expiring')"
            @class([
                'px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-2',
                'bg-primary text-primary-foreground shadow-xs' => $activeTab === 'expiring',
                'bg-secondary text-secondary-foreground hover:bg-secondary/80' => $activeTab !== 'expiring',
            ])
        >
            <x-icon name="alert-circle" class="w-4 h-4" />
            <span>{{ __('Expirations (Next 30 Days)') }}</span>
        </button>
    </div>

    <!-- Tab 1: Building & Floor Matrix -->
    @if ($activeTab === 'occupancy')
        <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="p-4 border-b border-border bg-secondary/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-foreground">{{ __('Floor-by-Floor Capacity and Occupancy Breakdown') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ __('Live count of occupied, free, and maintenance beds per floor') }}</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportMatrix('xlsx')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Matrix to Excel') }}"
                    >
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 mr-1 text-emerald-600" />
                        {{ __('Excel') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportMatrix('csv')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Matrix to CSV') }}"
                    >
                        <x-icon name="file-text" class="w-3.5 h-3.5 mr-1 text-blue-600" />
                        {{ __('CSV') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportMatrix('pdf')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Matrix to Landscape PDF') }}"
                    >
                        <x-icon name="file" class="w-3.5 h-3.5 mr-1 text-rose-600" />
                        {{ __('PDF') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-secondary/40 text-muted-foreground uppercase font-semibold border-b border-border">
                        <tr>
                            <th class="p-3.5">{{ __('Building') }}</th>
                            <th class="p-3.5">{{ __('Floor Name') }}</th>
                            <th class="p-3.5">{{ __('Gender Wing') }}</th>
                            <th class="p-3.5 text-center">{{ __('Total Rooms') }}</th>
                            <th class="p-3.5 text-center">{{ __('Total Beds') }}</th>
                            <th class="p-3.5 text-center">{{ __('Occupied') }}</th>
                            <th class="p-3.5 text-center">{{ __('Free (Available)') }}</th>
                            <th class="p-3.5 text-center">{{ __('Maintenance') }}</th>
                            <th class="p-3.5 text-right">{{ __('Occupancy %') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($this->buildingFloorMatrix as $row)
                            <tr class="hover:bg-secondary/20 transition-colors">
                                <td class="p-3.5 font-bold text-foreground">
                                    {{ $row['building_name'] }}
                                    <span class="text-[10px] text-muted-foreground ml-1">({{ $row['building_code'] }})</span>
                                </td>
                                <td class="p-3.5 font-semibold text-foreground">{{ $row['floor_name'] }}</td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-secondary text-secondary-foreground">
                                        {{ $row['gender_type'] }}
                                    </span>
                                </td>
                                <td class="p-3.5 text-center font-bold">{{ $row['total_rooms'] }}</td>
                                <td class="p-3.5 text-center font-bold">{{ $row['total_beds'] }}</td>
                                <td class="p-3.5 text-center font-bold text-blue-600 dark:text-blue-400">{{ $row['occupied'] }}</td>
                                <td class="p-3.5 text-center font-bold text-emerald-600 dark:text-emerald-400">{{ $row['available'] }}</td>
                                <td class="p-3.5 text-center font-bold text-rose-600 dark:text-rose-400">{{ $row['maintenance'] }}</td>
                                <td class="p-3.5 text-right font-extrabold">
                                    <span @class([
                                        'px-2 py-0.5 rounded text-xs',
                                        'text-emerald-600 bg-emerald-500/10' => $row['rate'] < 70,
                                        'text-amber-600 bg-amber-500/10' => $row['rate'] >= 70 && $row['rate'] < 90,
                                        'text-rose-600 bg-rose-500/10' => $row['rate'] >= 90,
                                    ])>
                                        {{ $row['rate'] }}%
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Tab 2: Compliance Audit -->
    @if ($activeTab === 'compliance')
        <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="p-4 border-b border-border bg-secondary/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-foreground">{{ __('Hostel Admission Form Submission Compliance') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ __('Active residents with pending hardcopy submission or missing softcopy upload') }}</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportCompliance('xlsx')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Compliance to Excel') }}"
                    >
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 mr-1 text-emerald-600" />
                        {{ __('Excel') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportCompliance('csv')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Compliance to CSV') }}"
                    >
                        <x-icon name="file-text" class="w-3.5 h-3.5 mr-1 text-blue-600" />
                        {{ __('CSV') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportCompliance('pdf')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Compliance to Landscape PDF') }}"
                    >
                        <x-icon name="file" class="w-3.5 h-3.5 mr-1 text-rose-600" />
                        {{ __('PDF') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-secondary/40 text-muted-foreground uppercase font-semibold border-b border-border">
                        <tr>
                            <th class="p-3.5">{{ __('Allotment #') }}</th>
                            <th class="p-3.5">{{ __('Resident') }}</th>
                            <th class="p-3.5">{{ __('Contact') }}</th>
                            <th class="p-3.5">{{ __('Room & Bed') }}</th>
                            <th class="p-3.5">{{ __('Check-In Date') }}</th>
                            <th class="p-3.5">{{ __('Hardcopy Form') }}</th>
                            <th class="p-3.5">{{ __('Softcopy Upload') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->pendingComplianceAllocations as $pa)
                            <tr class="hover:bg-secondary/20 transition-colors">
                                <td class="p-3.5 font-bold font-mono text-foreground">{{ $pa->allocation_no }}</td>
                                <td class="p-3.5 font-bold text-foreground">
                                    {{ $pa->user->name ?? 'N/A' }}
                                    <div class="text-[10px] text-muted-foreground font-normal">
                                        ID: {{ $pa->user->student_id ?? 'N/A' }}
                                    </div>
                                </td>
                                <td class="p-3.5 text-muted-foreground">
                                    <div>{{ $pa->user->phone ?? 'N/A' }}</div>
                                    <div class="text-[10px]">{{ $pa->user->email }}</div>
                                </td>
                                <td class="p-3.5">
                                    <div class="font-semibold text-foreground">{{ $pa->room->building->code ?? '' }} - {{ $pa->room->room_number ?? '' }}</div>
                                    <div class="text-[10px] text-muted-foreground">{{ $pa->bed->bed_number ?? '' }}</div>
                                </td>
                                <td class="p-3.5 font-semibold text-foreground">{{ $pa->start_date->format('d M Y') }}</td>
                                <td class="p-3.5">
                                    @if ($pa->hard_copy_submitted)
                                        <span class="inline-flex items-center gap-1 text-emerald-600 font-semibold">
                                            <x-icon name="check-circle" class="w-3.5 h-3.5" />
                                            {{ __('Submitted') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-amber-600 font-bold">
                                            <x-icon name="alert-circle" class="w-3.5 h-3.5" />
                                            {{ __('Pending Hardcopy') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="p-3.5">
                                    @if ($pa->soft_copy_path)
                                        <span class="inline-flex items-center gap-1 text-emerald-600 font-semibold">
                                            <x-icon name="file-check" class="w-3.5 h-3.5" />
                                            {{ __('Uploaded') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-rose-600 font-bold">
                                            <x-icon name="x-circle" class="w-3.5 h-3.5" />
                                            {{ __('Missing Softcopy') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-8 text-center text-muted-foreground">
                                    <x-icon name="check-circle" class="w-8 h-8 text-emerald-500 mx-auto mb-2" />
                                    {{ __('All active residents are 100% compliant with admission form submissions!') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Tab 3: Expiring Allocations -->
    @if ($activeTab === 'expiring')
        <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="p-4 border-b border-border bg-secondary/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-foreground">{{ __('Upcoming Stay Expirations (Next 30 Days)') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ __('Residents whose accommodation timeline is concluding soon') }}</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportExpiring('xlsx')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Expirations to Excel') }}"
                    >
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5 mr-1 text-emerald-600" />
                        {{ __('Excel') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportExpiring('csv')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Expirations to CSV') }}"
                    >
                        <x-icon name="file-text" class="w-3.5 h-3.5 mr-1 text-blue-600" />
                        {{ __('CSV') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="outline"
                        size="sm"
                        wire:click="exportExpiring('pdf')"
                        class="text-xs h-7 px-2.5 cursor-pointer"
                        title="{{ __('Export Expirations to Landscape PDF') }}"
                    >
                        <x-icon name="file" class="w-3.5 h-3.5 mr-1 text-rose-600" />
                        {{ __('PDF') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-secondary/40 text-muted-foreground uppercase font-semibold border-b border-border">
                        <tr>
                            <th class="p-3.5">{{ __('Allotment Ref') }}</th>
                            <th class="p-3.5">{{ __('Resident') }}</th>
                            <th class="p-3.5">{{ __('Room & Bed') }}</th>
                            <th class="p-3.5">{{ __('Start Date') }}</th>
                            <th class="p-3.5">{{ __('End Date') }}</th>
                            <th class="p-3.5">{{ __('Days Remaining') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->upcomingExpiringAllocations as $ua)
                            <tr class="hover:bg-secondary/20 transition-colors">
                                <td class="p-3.5 font-bold font-mono text-foreground">{{ $ua->allocation_no }}</td>
                                <td class="p-3.5 font-bold text-foreground">
                                    {{ $ua->user->name ?? 'N/A' }}
                                    <div class="text-[10px] text-muted-foreground font-normal">
                                        ID: {{ $ua->user->student_id ?? 'N/A' }} &bull; {{ $ua->resident_type }}
                                    </div>
                                </td>
                                <td class="p-3.5">
                                    <div class="font-semibold text-foreground">{{ $ua->room->building->name ?? '' }} - Room {{ $ua->room->room_number ?? '' }}</div>
                                    <div class="text-[10px] text-muted-foreground">{{ $ua->bed->bed_number ?? '' }}</div>
                                </td>
                                <td class="p-3.5 text-muted-foreground">{{ $ua->start_date->format('d M Y') }}</td>
                                <td class="p-3.5 font-bold text-foreground">{{ $ua->end_date?->format('d M Y') }}</td>
                                <td class="p-3.5">
                                    <span @class([
                                        'px-2 py-0.5 rounded text-xs font-bold',
                                        'text-emerald-600 bg-emerald-500/10' => $ua->daysRemaining() > 15,
                                        'text-amber-600 bg-amber-500/10' => $ua->daysRemaining() <= 15 && $ua->daysRemaining() >= 0,
                                        'text-rose-600 bg-rose-500/10' => $ua->daysRemaining() < 0,
                                    ])>
                                        {{ $ua->daysRemaining() }} {{ __('Days') }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8 text-center text-muted-foreground">
                                    {{ __('No accommodations scheduled to expire in the next 30 days.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
