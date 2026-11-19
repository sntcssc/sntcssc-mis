<?php

use App\Concerns\RenamesUploads;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\PartnerInstitute;
use App\Models\Section;
use App\Services\PartnerInstituteService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Partner Institutes for Coaching')] class extends Component {
    use RenamesUploads, WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

    public int $perPage = 15;

    // Form Modal State
    public ?int $editingId = null;

    public $imageFile = null;

    public ?string $existing_image_url = null;

    public ?string $existing_image_path = null;

    public bool $remove_image = false;

    public array $assigned_batches = [];

    public array $assigned_courses = [];

    public array $assigned_sections = [];

    public array $form = [
        'name' => '',
        'code' => '',
        'description' => '',
        'website_url' => '',
        'email' => '',
        'mobile' => '',
        'phone' => '',
        'address' => '',
        'start_date' => '',
        'end_date' => '',
        'status' => 'active',
        'official_name' => '',
        'official_designation' => '',
        'official_contact' => '',
        'official_email' => '',
    ];

    // Detail Modal State
    public ?PartnerInstitute $viewingInstitute = null;

    // Delete Modal State
    public ?PartnerInstitute $deletingInstitute = null;

    // Import Modal State
    public $importFile = null;

    public array $importResult = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
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

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => PartnerInstitute::count(),
            'active' => PartnerInstitute::where('status', PartnerInstitute::STATUS_ACTIVE)->count(),
            'expired' => PartnerInstitute::where('status', PartnerInstitute::STATUS_MOU_EXPIRED)
                ->orWhere(function ($q) {
                    $q->whereNotNull('end_date')->where('end_date', '<', now()->toDateString());
                })->count(),
            'faculties' => Faculty::whereNotNull('partner_institute_id')->count(),
        ];
    }

    #[Computed]
    public function institutes()
    {
        return PartnerInstitute::query()
            ->with(['batches:id,name,code', 'courses:id,name,code', 'sections:id,name,code'])
            ->withCount(['faculties', 'batches', 'courses', 'sections'])
            ->when($this->search, fn (Builder $q) => $q->search($this->search))
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    #[Computed]
    public function batchesList()
    {
        return Batch::orderBy('name')->get(['id', 'name', 'code']);
    }

    #[Computed]
    public function coursesList()
    {
        return Course::orderBy('name')->get(['id', 'name', 'code']);
    }

    #[Computed]
    public function availableSectionsList()
    {
        return Section::query()
            ->with(['batch:id,name', 'course:id,name'])
            ->when(! empty($this->assigned_batches), fn ($q) => $q->whereIn('batch_id', $this->assigned_batches))
            ->when(! empty($this->assigned_courses), fn ($q) => $q->whereIn('course_id', $this->assigned_courses))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'batch_id', 'course_id']);
    }

    public function openCreateModal(): void
    {
        $this->authorize('partner_institutes.manage');
        $this->resetForm();
        $this->dispatch('modal-open', name: 'partner-institute-form-modal');
    }

    public function openEditModal(int $id): void
    {
        $this->authorize('partner_institutes.manage');
        $this->resetForm();

        $institute = PartnerInstitute::with(['batches', 'courses', 'sections'])->findOrFail($id);
        $this->editingId = $institute->id;
        $this->existing_image_url = $institute->image_url;
        $this->existing_image_path = $institute->image_path;
        $this->assigned_batches = $institute->batches->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $this->assigned_courses = $institute->courses->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $this->assigned_sections = $institute->sections->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $this->form = [
            'name' => $institute->name,
            'code' => $institute->code,
            'description' => $institute->description ?? '',
            'website_url' => $institute->website_url ?? '',
            'email' => $institute->email ?? '',
            'mobile' => $institute->mobile ?? '',
            'phone' => $institute->phone ?? '',
            'address' => $institute->address ?? '',
            'start_date' => $institute->start_date?->format('Y-m-d') ?? '',
            'end_date' => $institute->end_date?->format('Y-m-d') ?? '',
            'status' => $institute->status,
            'official_name' => $institute->official_name ?? '',
            'official_designation' => $institute->official_designation ?? '',
            'official_contact' => $institute->official_contact ?? '',
            'official_email' => $institute->official_email ?? '',
        ];

        $this->dispatch('modal-open', name: 'partner-institute-form-modal');
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->imageFile = null;
        $this->existing_image_url = null;
        $this->existing_image_path = null;
        $this->remove_image = false;
        $this->assigned_batches = [];
        $this->assigned_courses = [];
        $this->assigned_sections = [];

        $this->form = [
            'name' => '',
            'code' => '',
            'description' => '',
            'website_url' => '',
            'email' => '',
            'mobile' => '',
            'phone' => '',
            'address' => '',
            'start_date' => '',
            'end_date' => '',
            'status' => 'active',
            'official_name' => '',
            'official_designation' => '',
            'official_contact' => '',
            'official_email' => '',
        ];

        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorize('partner_institutes.manage');

        $rules = [
            'form.name' => ['required', 'string', 'max:255'],
            'form.code' => ['nullable', 'string', 'max:50', 'unique:partner_institutes,code,'.($this->editingId ?? 'NULL')],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.website_url' => ['nullable', 'string', 'max:255'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.mobile' => ['nullable', 'string', 'max:20'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.address' => ['nullable', 'string', 'max:500'],
            'form.start_date' => ['nullable', 'date'],
            'form.end_date' => ['nullable', 'date'],
            'form.status' => ['required', 'in:active,inactive,mou_expired,pending'],
            'form.official_name' => ['nullable', 'string', 'max:255'],
            'form.official_designation' => ['nullable', 'string', 'max:255'],
            'form.official_contact' => ['nullable', 'string', 'max:30'],
            'form.official_email' => ['nullable', 'email', 'max:255'],
            'assigned_batches' => ['nullable', 'array'],
            'assigned_batches.*' => ['integer', 'exists:batches,id'],
            'assigned_courses' => ['nullable', 'array'],
            'assigned_courses.*' => ['integer', 'exists:courses,id'],
            'assigned_sections' => ['nullable', 'array'],
            'assigned_sections.*' => ['integer', 'exists:sections,id'],
            'imageFile' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,svg', 'max:2048'],
        ];

        if (! empty($this->form['start_date']) && ! empty($this->form['end_date'])) {
            $rules['form.end_date'][] = 'after_or_equal:form.start_date';
        }

        try {
            $this->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Toast::dispatch($this, 'error', __('Please resolve validation errors in the form.'));
            throw $ve;
        }

        $data = PartnerInstituteService::sanitizeData($this->form);

        // Auto uppercase code or generate
        if (! empty($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        } else {
            unset($data['code']);
        }

        // Attach academic cohort bindings
        $data['batches'] = array_map('intval', $this->assigned_batches);
        $data['courses'] = array_map('intval', $this->assigned_courses);
        $data['sections'] = array_map('intval', $this->assigned_sections);

        // Handle Image upload with RenamesUploads
        if ($this->imageFile) {
            if ($this->existing_image_path && Storage::disk('public')->exists($this->existing_image_path)) {
                Storage::disk('public')->delete($this->existing_image_path);
            }
            $filename = $this->generateFileName('partner_institute', $this->imageFile, auth()->id() ?? 1);
            $data['image_path'] = $this->imageFile->storeAs('partner_institutes', $filename, 'public');
        } elseif ($this->remove_image && $this->existing_image_path) {
            if (Storage::disk('public')->exists($this->existing_image_path)) {
                Storage::disk('public')->delete($this->existing_image_path);
            }
            $data['image_path'] = null;
        }

        try {
            if ($this->editingId) {
                $institute = PartnerInstitute::findOrFail($this->editingId);
                PartnerInstituteService::updateInstitute($institute, $data);
                Toast::dispatch($this, 'success', __('Partner institute ":name" updated successfully.', ['name' => $institute->name]));
            } else {
                $created = PartnerInstituteService::createInstitute($data);
                Toast::dispatch($this, 'success', __('Partner institute ":name" created successfully.', ['name' => $created->name]));
            }

            $this->dispatch('modal-close', name: 'partner-institute-form-modal');
            $this->resetForm();
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save partner institute: :error', ['error' => $e->getMessage()]));
        }
    }

    public function viewDetails(int $id): void
    {
        $this->authorize('partner_institutes.view');
        $this->viewingInstitute = PartnerInstitute::with([
            'faculties' => fn ($q) => $q->orderBy('name'),
            'batches' => fn ($q) => $q->orderBy('name'),
            'courses' => fn ($q) => $q->orderBy('name'),
            'sections' => fn ($q) => $q->with(['batch', 'course'])->orderBy('name'),
        ])->withCount(['faculties', 'batches', 'courses', 'sections'])->findOrFail($id);

        $this->dispatch('modal-open', name: 'partner-institute-view-modal');
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('partner_institutes.delete');
        $this->deletingInstitute = PartnerInstitute::withCount('faculties')->findOrFail($id);
        $this->dispatch('modal-open', name: 'partner-institute-delete-modal');
    }

    public function deleteInstitute(): void
    {
        $this->authorize('partner_institutes.delete');

        if (! $this->deletingInstitute) {
            return;
        }

        try {
            $name = $this->deletingInstitute->name;
            PartnerInstituteService::deleteInstitute($this->deletingInstitute);
            Toast::dispatch($this, 'success', __('Partner institute ":name" deleted successfully.', ['name' => $name]));
            $this->dispatch('modal-close', name: 'partner-institute-delete-modal');
            $this->deletingInstitute = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete partner institute: :error', ['error' => $e->getMessage()]));
        }
    }

    public function openImportModal(): void
    {
        $this->authorize('partner_institutes.import');
        $this->importFile = null;
        $this->importResult = [];
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'partner-institute-import-modal');
    }

    public function downloadTemplate()
    {
        $this->authorize('partner_institutes.import');

        return PartnerInstituteService::generateSampleTemplate();
    }

    public function processImport(): void
    {
        $this->authorize('partner_institutes.import');

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = PartnerInstituteService::importFromSpreadsheet($this->importFile, auth()->id());
            $this->importResult = $result;

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __(':count partner institutes imported successfully.', ['count' => $result['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('No partner institutes were imported. Please check errors.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function export(string $format)
    {
        $this->authorize('partner_institutes.export');

        $query = PartnerInstitute::query()
            ->when($this->search, fn (Builder $q) => $q->search($this->search))
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortField, $this->sortDirection);

        return PartnerInstituteService::exportInstitutes($query, $format);
    }
}; ?>

<div class="space-y-6">
    <!-- Breadcrumb & Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <nav class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-foreground transition-colors">{{ __('Dashboard') }}</a>
                <span>/</span>
                <span>{{ __('Academics') }}</span>
                <span>/</span>
                <span class="text-foreground font-medium">{{ __('Partner Institutes') }}</span>
            </nav>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <x-icon name="landmark" class="h-6 w-6 text-primary" />
                <span>{{ __('Partner Institutes for Coaching') }}</span>
            </h1>
            <p class="text-xs text-muted-foreground mt-0.5">
                {{ __('Manage affiliated and partner coaching institutes, MoU tenures, officials contact, and faculty member linkages.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('partner_institutes.export')
                <div x-data="{ open: false }" class="relative">
                    <x-ui.button type="button" variant="outline" size="sm" @click="open = !open" class="gap-1.5">
                        <x-icon name="download" class="h-4 w-4" />
                        <span>{{ __('Export') }}</span>
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-muted-foreground" />
                    </x-ui.button>
                    <div
                        x-show="open"
                        @click.outside="open = false"
                        x-cloak
                        class="absolute right-0 mt-1 w-36 bg-popover border border-border rounded-lg shadow-lg py-1 z-30 text-xs"
                    >
                        <button type="button" wire:click="export('xlsx')" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-accent text-foreground flex items-center gap-2">
                            <x-icon name="file-spreadsheet" class="h-4 w-4 text-emerald-600" />
                            <span>{{ __('Excel (.xlsx)') }}</span>
                        </button>
                        <button type="button" wire:click="export('csv')" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-accent text-foreground flex items-center gap-2">
                            <x-icon name="file-text" class="h-4 w-4 text-blue-600" />
                            <span>{{ __('CSV (.csv)') }}</span>
                        </button>
                        <button type="button" wire:click="export('pdf')" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-accent text-foreground flex items-center gap-2">
                            <x-icon name="file" class="h-4 w-4 text-rose-600" />
                            <span>{{ __('PDF Document') }}</span>
                        </button>
                    </div>
                </div>
            @endcan

            @can('partner_institutes.import')
                <x-ui.button type="button" variant="outline" size="sm" wire:click="openImportModal" x-data x-on:click="$store.modals.open('partner-institute-import-modal')" class="gap-1.5">
                    <x-icon name="upload" class="h-4 w-4" />
                    <span>{{ __('Import') }}</span>
                </x-ui.button>
            @endcan

            @can('partner_institutes.manage')
                <x-ui.button type="button" size="sm" wire:click="openCreateModal" x-data x-on:click="$store.modals.open('partner-institute-form-modal')" class="gap-1.5">
                    <x-icon name="plus" class="h-4 w-4" />
                    <span>{{ __('Add Partner Institute') }}</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Total Partner Institutes') }}</p>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['total']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-primary/10 text-primary">
                    <x-icon name="landmark" class="w-5 h-5" />
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Active Partnerships') }}</p>
                    <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ number_format($this->stats['active']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle" class="w-5 h-5" />
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('MoU Expired / Due') }}</p>
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ number_format($this->stats['expired']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="calendar" class="w-5 h-5" />
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-muted-foreground">{{ __('Linked Faculty Members') }}</p>
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">{{ number_format($this->stats['faculties']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="users" class="w-5 h-5" />
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Table Section -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border flex flex-col md:flex-row items-center justify-between gap-3 bg-muted/20">
            <!-- Search & Filter Controls -->
            <div class="flex flex-wrap items-center gap-2.5 w-full md:w-auto flex-1">
                <div class="relative w-full sm:w-72">
                    <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="text"
                        placeholder="{{ __('Search by name, code, official, email...') }}"
                        class="w-full pl-9 pr-3 py-1.5 text-xs rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                <select
                    wire:model.live="statusFilter"
                    class="px-2.5 py-1.5 text-xs rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="mou_expired">{{ __('MoU Expired') }}</option>
                    <option value="pending">{{ __('Pending Approval') }}</option>
                </select>

                @if ($search || $statusFilter)
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
                    >
                        <x-icon name="x" class="w-3.5 h-3.5" />
                        <span>{{ __('Clear') }}</span>
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <label class="text-xs text-muted-foreground">{{ __('Per page:') }}</label>
                <select
                    wire:model.live="perPage"
                    class="px-2 py-1 text-xs rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-muted/40 border-b border-border font-semibold text-muted-foreground uppercase tracking-wider text-[11px]">
                    <tr>
                        <th class="px-4 py-3 cursor-pointer hover:text-foreground" wire:click="sortBy('name')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('Institute Details') }}</span>
                                @if ($sortField === 'name')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3 h-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 cursor-pointer hover:text-foreground" wire:click="sortBy('code')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('Code') }}</span>
                                @if ($sortField === 'code')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3 h-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Academic Scope') }}</th>
                        <th class="px-4 py-3">{{ __('Officials & Contact') }}</th>
                        <th class="px-4 py-3 cursor-pointer hover:text-foreground" wire:click="sortBy('start_date')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('MoU Tenure') }}</span>
                                @if ($sortField === 'start_date')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-3 h-3 text-primary" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 text-center">{{ __('Faculties') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-foreground">
                    @forelse ($this->institutes as $inst)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @if ($inst->image_url)
                                        <img src="{{ $inst->image_url }}" alt="{{ $inst->name }}" class="h-9 w-9 rounded-lg object-cover border border-border shrink-0" />
                                    @else
                                        <div class="h-9 w-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                                            {{ strtoupper(substr($inst->name, 0, 2)) }}
                                        </div>
                                    @endif
                                    <div>
                                        <button
                                            type="button"
                                            wire:click="viewDetails({{ $inst->id }})"
                                            class="font-semibold text-foreground hover:text-primary transition-colors text-left"
                                        >
                                            {{ $inst->name }}
                                        </button>
                                        @if ($inst->website_url)
                                            <div class="flex items-center gap-1 text-[11px] text-muted-foreground mt-0.5">
                                                <x-icon name="globe" class="w-3 h-3" />
                                                <a href="{{ $inst->website_url }}" target="_blank" rel="noopener noreferrer" class="hover:underline truncate max-w-[200px]">
                                                    {{ $inst->website_url }}
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-[11px] px-2 py-0.5 rounded-md bg-muted text-foreground border border-border">
                                    {{ $inst->code }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $bCount = $inst->batches_count ?? $inst->batches->count();
                                    $cCount = $inst->courses_count ?? $inst->courses->count();
                                    $sCount = $inst->sections_count ?? $inst->sections->count();
                                @endphp
                                @if ($bCount === 0 && $cCount === 0 && $sCount === 0)
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-medium bg-muted text-muted-foreground">
                                        {{ __('All Batches & Courses') }}
                                    </span>
                                @else
                                    <div class="flex flex-col gap-1">
                                        @if ($bCount > 0)
                                            <span class="inline-flex items-center gap-1 text-[11px] font-medium text-foreground">
                                                <x-icon name="layers" class="w-3 h-3 text-primary shrink-0" />
                                                <span>{{ $bCount }} {{ __('Batch(es)') }}</span>
                                            </span>
                                        @endif
                                        @if ($cCount > 0)
                                            <span class="inline-flex items-center gap-1 text-[11px] font-medium text-foreground">
                                                <x-icon name="book-open" class="w-3 h-3 text-blue-600 shrink-0" />
                                                <span>{{ $cCount }} {{ __('Course(s)') }}</span>
                                            </span>
                                        @endif
                                        @if ($sCount > 0)
                                            <span class="inline-flex items-center gap-1 text-[11px] font-medium text-foreground">
                                                <x-icon name="grid" class="w-3 h-3 text-purple-600 shrink-0" />
                                                <span>{{ $sCount }} {{ __('Section(s)') }}</span>
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($inst->official_name)
                                    <div class="font-medium text-foreground">{{ $inst->official_name }}</div>
                                    <div class="text-[11px] text-muted-foreground">{{ $inst->official_designation ?? __('Official Representative') }}</div>
                                @endif
                                <div class="text-[11px] text-muted-foreground flex flex-col gap-0.5 mt-0.5">
                                    @if ($inst->email || $inst->official_email)
                                        <span class="flex items-center gap-1">
                                            <x-icon name="mail" class="w-3 h-3 shrink-0" />
                                            <span>{{ $inst->official_email ?: $inst->email }}</span>
                                        </span>
                                    @endif
                                    @if ($inst->mobile || $inst->official_contact || $inst->phone)
                                        <span class="flex items-center gap-1">
                                            <x-icon name="phone" class="w-3 h-3 shrink-0" />
                                            <span>{{ $inst->official_contact ?: ($inst->mobile ?: $inst->phone) }}</span>
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($inst->start_date)
                                    <div class="font-medium text-foreground">
                                        {{ $inst->start_date->format('d M Y') }}
                                        @if ($inst->end_date)
                                            <span class="text-muted-foreground">&rarr;</span> {{ $inst->end_date->format('d M Y') }}
                                        @endif
                                    </div>
                                    @if ($inst->end_date)
                                        @if ($inst->end_date->isPast())
                                            <span class="text-[10px] text-rose-600 dark:text-rose-400 font-semibold">{{ __('MoU Expired') }}</span>
                                        @else
                                            <span class="text-[10px] text-muted-foreground">{{ $inst->end_date->diffForHumans() }}</span>
                                        @endif
                                    @endif
                                @else
                                    <span class="text-muted-foreground italic">{{ __('Not specified') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-secondary text-secondary-foreground">
                                    <x-icon name="users" class="w-3 h-3" />
                                    <span>{{ $inst->faculties_count }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-semibold uppercase {{ $inst->status_badge_color }}">
                                    {{ $inst->status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        wire:click="viewDetails({{ $inst->id }})"
                                        class="p-1.5 text-muted-foreground hover:text-foreground rounded-md hover:bg-muted transition-colors cursor-pointer"
                                        title="{{ __('View Dossier') }}"
                                    >
                                        <x-icon name="eye" class="h-4 w-4" />
                                    </button>

                                    @can('partner_institutes.manage')
                                        <button
                                            type="button"
                                            wire:click="openEditModal({{ $inst->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-primary rounded-md hover:bg-muted transition-colors cursor-pointer"
                                            title="{{ __('Edit Institute') }}"
                                        >
                                            <x-icon name="edit" class="h-4 w-4" />
                                        </button>
                                    @endcan

                                    @can('partner_institutes.delete')
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $inst->id }})"
                                            class="p-1.5 text-muted-foreground hover:text-destructive rounded-md hover:bg-muted transition-colors cursor-pointer"
                                            title="{{ __('Delete Institute') }}"
                                        >
                                            <x-icon name="trash-2" class="h-4 w-4" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="landmark" class="h-8 w-8 text-muted-foreground/40" />
                                    <p class="font-medium text-sm">{{ __('No partner institutes found.') }}</p>
                                    <p class="text-xs text-muted-foreground">{{ __('Try clearing filters or add a new coaching institute.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->institutes->hasPages())
            <div class="border-t border-border p-4">
                {{ $this->institutes->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Modal -->
    <x-ui.modal
        name="partner-institute-form-modal"
        maxWidth="max-w-3xl"
        :title="$editingId ? __('Edit Partner Coaching Institute') : __('Add Partner Coaching Institute')"
        :description="__('Configure institute credentials, official representative contacts, MoU validities and branding.')"
    >
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Institute Name -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Institute Name') }} <span class="text-destructive">*</span>
                    </label>
                    <input
                        type="text"
                        wire:model="form.name"
                        placeholder="{{ __('e.g. Apex IAS Academy / Chanakya Mentorship Forum') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.name') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Institute Code -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Institute Code') }} <span class="text-muted-foreground font-normal">({{ __('Auto if empty') }})</span>
                    </label>
                    <input
                        type="text"
                        wire:model="form.code"
                        placeholder="{{ __('e.g. INST-APEX-2026') }}"
                        class="w-full uppercase rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.code') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Website URL -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Website URL') }}
                    </label>
                    <input
                        type="url"
                        wire:model="form.website_url"
                        placeholder="https://example.com"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.website_url') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Institute Email -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Institute Official Email') }}
                    </label>
                    <input
                        type="email"
                        wire:model="form.email"
                        placeholder="info@institute.org"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.email') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Institute Mobile / Phone -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Mobile / Phone Contact') }}
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <input
                            type="text"
                            wire:model="form.mobile"
                            placeholder="{{ __('Mobile') }}"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                        <input
                            type="text"
                            wire:model="form.phone"
                            placeholder="{{ __('Landline / Phone') }}"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        />
                    </div>
                </div>

                <!-- Physical Address -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Physical Campus / Headquarters Address') }}
                    </label>
                    <textarea
                        wire:model="form.address"
                        rows="2"
                        placeholder="{{ __('Plot 12, Academic Square, Knowledge City, Kolkata, WB - 700091') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    ></textarea>
                    @error('form.address') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- MoU Start & End Dates -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('MoU / Partnership Start Date') }}
                    </label>
                    <input
                        type="date"
                        wire:model="form.start_date"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.start_date') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('MoU / Partnership End Date') }}
                    </label>
                    <input
                        type="date"
                        wire:model="form.end_date"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                    @error('form.end_date') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Status -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Partnership Status') }} <span class="text-destructive">*</span>
                    </label>
                    <div class="flex flex-wrap gap-4 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input wire:model="form.status" type="radio" value="active" class="text-primary focus:ring-primary" />
                            <span>{{ __('Active (Valid MoU)') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input wire:model="form.status" type="radio" value="mou_expired" class="text-amber-600 focus:ring-amber-500" />
                            <span>{{ __('MoU Expired / Under Renewal') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input wire:model="form.status" type="radio" value="pending" class="text-blue-600 focus:ring-blue-500" />
                            <span>{{ __('Pending Approval') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input wire:model="form.status" type="radio" value="inactive" class="text-muted-foreground focus:ring-muted-foreground" />
                            <span>{{ __('Inactive / Terminated') }}</span>
                        </label>
                    </div>
                    @error('form.status') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Institute Official Contact Section -->
                <div class="sm:col-span-2 p-3.5 rounded-xl border border-border bg-muted/30 space-y-3">
                    <h4 class="text-xs font-bold text-foreground flex items-center gap-2">
                        <x-icon name="user" class="w-4 h-4 text-primary" />
                        <span>{{ __('Key Liaison Official / Nodal Officer Details') }}</span>
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Official Name') }}</label>
                            <input
                                type="text"
                                wire:model="form.official_name"
                                placeholder="{{ __('Prof. S. K. Sen') }}"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Official Designation') }}</label>
                            <input
                                type="text"
                                wire:model="form.official_designation"
                                placeholder="{{ __('Director / Academic Coordinator') }}"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Official Contact Number') }}</label>
                            <input
                                type="text"
                                wire:model="form.official_contact"
                                placeholder="+91 98765 43210"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Official Direct Email') }}</label>
                            <input
                                type="email"
                                wire:model="form.official_email"
                                placeholder="director@institute.org"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>
                    </div>
                </div>

                <!-- Logo / Image Upload with RenamesUploads & Remove Option -->
                <div class="sm:col-span-2 p-3.5 rounded-xl border border-border bg-muted/30 space-y-3">
                    <h4 class="text-xs font-bold text-foreground flex items-center gap-2">
                        <x-icon name="image" class="w-4 h-4 text-primary" />
                        <span>{{ __('Institute Logo / Emblem') }}</span>
                    </h4>

                    <div class="flex items-center gap-4">
                        @if ($imageFile)
                            <img src="{{ $imageFile->temporaryUrl() }}" alt="Preview" class="h-16 w-16 rounded-lg object-cover border border-border shadow-xs" />
                        @elseif ($existing_image_url && ! $remove_image)
                            <img src="{{ $existing_image_url }}" alt="Existing Logo" class="h-16 w-16 rounded-lg object-cover border border-border shadow-xs" />
                        @else
                            <div class="h-16 w-16 rounded-lg bg-muted text-muted-foreground flex items-center justify-center border border-dashed border-border">
                                <x-icon name="image" class="h-6 w-6 text-muted-foreground/50" />
                            </div>
                        @endif

                        <div class="space-y-1.5 flex-1">
                            <input
                                type="file"
                                wire:model="imageFile"
                                accept="image/*"
                                class="w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer"
                            />
                            <p class="text-[11px] text-muted-foreground">{{ __('Supported formats: PNG, JPG, WEBP, SVG (Max 2MB).') }}</p>

                            @if ($existing_image_url || $imageFile)
                                <div class="pt-1">
                                    <button
                                        type="button"
                                        wire:click="$set('remove_image', true); $set('imageFile', null)"
                                        class="inline-flex items-center gap-1 text-[11px] text-destructive hover:underline"
                                    >
                                        <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                        <span>{{ __('Remove uploaded logo') }}</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                    @error('imageFile') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Description / Remarks -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Description / Scope of Collaboration') }}
                    </label>
                    <textarea
                        wire:model="form.description"
                        rows="3"
                        placeholder="{{ __('Details on course areas covered, joint mentorship programs, civil services mock interview guidance, etc.') }}"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                    ></textarea>
                    @error('form.description') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
                </div>

                <!-- Academic Alignment: Batches, Courses & Sections -->
                <div class="sm:col-span-2 p-3.5 rounded-xl border border-border bg-muted/30 space-y-4">
                    <div>
                        <h4 class="text-xs font-bold text-foreground flex items-center gap-2">
                            <x-icon name="graduation-cap" class="w-4 h-4 text-primary" />
                            <span>{{ __('Associated Academic Cohorts, Courses & Sections') }}</span>
                        </h4>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            {{ __('Link this partner coaching institute to specific batches, courses, and sections for co-delivered routines.') }}
                        </p>
                    </div>

                    <!-- Batches -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                <x-icon name="layers" class="w-3.5 h-3.5 text-primary" />
                                <span>{{ __('Associated Batches (Optional)') }}</span>
                            </label>
                            <span class="text-[10px] text-muted-foreground">{{ __('Select one or multiple batches') }}</span>
                        </div>
                        <div class="max-h-32 overflow-y-auto space-y-1 p-2 rounded-lg bg-background border border-border">
                            @forelse ($this->batchesList as $b)
                                <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer hover:bg-muted/40 p-1 rounded">
                                    <input type="checkbox" wire:model.live="assigned_batches" value="{{ $b->id }}" class="rounded text-primary focus:ring-primary" />
                                    <span class="font-medium">{{ $b->name }}</span>
                                    <span class="text-[10px] font-mono text-muted-foreground">({{ $b->code }})</span>
                                </label>
                            @empty
                                <p class="text-muted-foreground text-[11px] italic p-1">{{ __('No batches available.') }}</p>
                            @endforelse
                        </div>
                    </div>

                    <!-- Courses -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                <x-icon name="book-open" class="w-3.5 h-3.5 text-blue-600" />
                                <span>{{ __('Associated Courses (Optional)') }}</span>
                            </label>
                            <span class="text-[10px] text-muted-foreground">{{ __('Select courses conducted in association with this institute') }}</span>
                        </div>
                        <div class="max-h-32 overflow-y-auto space-y-1 p-2 rounded-lg bg-background border border-border">
                            @forelse ($this->coursesList as $c)
                                <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer hover:bg-muted/40 p-1 rounded">
                                    <input type="checkbox" wire:model.live="assigned_courses" value="{{ $c->id }}" class="rounded text-primary focus:ring-primary" />
                                    <span class="font-medium">{{ $c->name }}</span>
                                    <span class="text-[10px] font-mono text-muted-foreground">({{ $c->code }})</span>
                                </label>
                            @empty
                                <p class="text-muted-foreground text-[11px] italic p-1">{{ __('No courses available.') }}</p>
                            @endforelse
                        </div>
                    </div>

                    <!-- Sections -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                <x-icon name="grid" class="w-3.5 h-3.5 text-purple-600" />
                                <span>{{ __('Associated Sections (Optional)') }}</span>
                            </label>
                            <span class="text-[10px] text-muted-foreground">{{ __('Filtered by selected batches & courses') }}</span>
                        </div>
                        <div class="max-h-32 overflow-y-auto space-y-1 p-2 rounded-lg bg-background border border-border">
                            @forelse ($this->availableSectionsList as $sec)
                                <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer hover:bg-muted/40 p-1 rounded">
                                    <input type="checkbox" wire:model="assigned_sections" value="{{ $sec->id }}" class="rounded text-primary focus:ring-primary" />
                                    <span class="font-medium">{{ $sec->name }}</span>
                                    <span class="text-[10px] text-muted-foreground">({{ $sec->batch?->name ?? 'N/A' }} &bull; {{ $sec->course?->name ?? 'N/A' }})</span>
                                </label>
                            @empty
                                <p class="text-muted-foreground text-[11px] italic p-1">{{ __('No sections matching current batch/course selection.') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('partner-institute-form-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" size="sm" class="gap-1.5">
                    <x-icon name="check" class="h-4 w-4" />
                    <span>{{ $editingId ? __('Update Institute') : __('Save Institute') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- View Details Modal -->
    <x-ui.modal
        name="partner-institute-view-modal"
        maxWidth="max-w-2xl"
        :title="$viewingInstitute?->name ?? __('Institute Dossier')"
        :description="__('Complete coaching partner profile, MoU details, official representatives, and linked faculty members.')"
    >
        @if ($viewingInstitute)
            <div class="space-y-4 text-xs">
                <!-- Header card with logo -->
                <div class="flex items-start gap-4 p-4 rounded-xl bg-muted/40 border border-border">
                    @if ($viewingInstitute->image_url)
                        <img src="{{ $viewingInstitute->image_url }}" alt="{{ $viewingInstitute->name }}" class="h-16 w-16 rounded-xl object-cover border border-border shadow-xs shrink-0" />
                    @else
                        <div class="h-16 w-16 rounded-xl bg-primary/10 text-primary flex items-center justify-center font-bold text-lg shrink-0">
                            {{ strtoupper(substr($viewingInstitute->name, 0, 2)) }}
                        </div>
                    @endif
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded-md bg-muted text-foreground border border-border font-semibold">
                                {{ $viewingInstitute->code }}
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase {{ $viewingInstitute->status_badge_color }}">
                                {{ $viewingInstitute->status_label }}
                            </span>
                        </div>
                        <h3 class="text-base font-bold text-foreground mt-1">{{ $viewingInstitute->name }}</h3>
                        @if ($viewingInstitute->website_url)
                            <a href="{{ $viewingInstitute->website_url }}" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline flex items-center gap-1 mt-1 text-[11px]">
                                <x-icon name="globe" class="w-3.5 h-3.5" />
                                <span>{{ $viewingInstitute->website_url }}</span>
                            </a>
                        @endif
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="grid grid-cols-2 gap-3 p-3.5 rounded-xl border border-border bg-card">
                    <div>
                        <span class="text-muted-foreground block text-[11px]">{{ __('MoU Validity') }}</span>
                        <span class="font-medium text-foreground">
                            {{ $viewingInstitute->start_date ? $viewingInstitute->start_date->format('d M Y') : __('N/A') }}
                            &rarr;
                            {{ $viewingInstitute->end_date ? $viewingInstitute->end_date->format('d M Y') : __('Ongoing') }}
                        </span>
                    </div>

                    <div>
                        <span class="text-muted-foreground block text-[11px]">{{ __('Contact Numbers') }}</span>
                        <span class="font-medium text-foreground">
                            {{ $viewingInstitute->mobile ?: ($viewingInstitute->phone ?: __('N/A')) }}
                        </span>
                    </div>

                    <div>
                        <span class="text-muted-foreground block text-[11px]">{{ __('General Email') }}</span>
                        <span class="font-medium text-foreground">{{ $viewingInstitute->email ?: __('N/A') }}</span>
                    </div>

                    <div>
                        <span class="text-muted-foreground block text-[11px]">{{ __('Physical Address') }}</span>
                        <span class="font-medium text-foreground">{{ $viewingInstitute->address ?: __('N/A') }}</span>
                    </div>
                </div>

                <!-- Liaison Official Details -->
                @if ($viewingInstitute->official_name)
                    <div class="p-3.5 rounded-xl border border-border bg-card space-y-1.5">
                        <h4 class="font-bold text-foreground flex items-center gap-1.5 text-xs">
                            <x-icon name="user-check" class="w-4 h-4 text-primary" />
                            <span>{{ __('Key Liaison / Nodal Official') }}</span>
                        </h4>
                        <div class="grid grid-cols-2 gap-2 text-[11px]">
                            <div>
                                <span class="text-muted-foreground">{{ __('Name:') }}</span>
                                <span class="font-medium text-foreground ml-1">{{ $viewingInstitute->official_name }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Designation:') }}</span>
                                <span class="font-medium text-foreground ml-1">{{ $viewingInstitute->official_designation ?? __('Official') }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Direct Contact:') }}</span>
                                <span class="font-medium text-foreground ml-1">{{ $viewingInstitute->official_contact ?? __('N/A') }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Direct Email:') }}</span>
                                <span class="font-medium text-foreground ml-1">{{ $viewingInstitute->official_email ?? __('N/A') }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($viewingInstitute->description)
                    <div class="p-3 rounded-xl border border-border bg-card text-xs">
                        <span class="text-muted-foreground block font-semibold mb-1">{{ __('Scope of Collaboration / Description') }}</span>
                        <p class="text-foreground leading-relaxed">{{ $viewingInstitute->description }}</p>
                    </div>
                @endif

                <!-- Associated Academic Cohorts, Courses & Sections -->
                <div class="p-3.5 rounded-xl border border-border bg-card space-y-3">
                    <h4 class="font-bold text-foreground flex items-center justify-between text-xs">
                        <span class="flex items-center gap-1.5">
                            <x-icon name="graduation-cap" class="w-4 h-4 text-primary" />
                            <span>{{ __('Associated Academic Cohorts, Courses & Sections') }}</span>
                        </span>
                        <span class="text-[11px] text-muted-foreground font-normal">
                            {{ count($viewingInstitute->batches) }} {{ __('Batches') }} &bull; {{ count($viewingInstitute->courses) }} {{ __('Courses') }} &bull; {{ count($viewingInstitute->sections) }} {{ __('Sections') }}
                        </span>
                    </h4>

                    <div class="space-y-2.5 text-xs">
                        <!-- Batches -->
                        <div>
                            <span class="text-[11px] font-semibold text-muted-foreground block mb-1">{{ __('Associated Batches:') }}</span>
                            @if ($viewingInstitute->batches->isNotEmpty())
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($viewingInstitute->batches as $b)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-primary/10 text-primary border border-primary/20 text-[11px] font-medium">
                                            <x-icon name="layers" class="w-3 h-3" />
                                            <span>{{ $b->name }}</span>
                                            <span class="font-mono text-[10px] opacity-75">({{ $b->code }})</span>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-muted-foreground italic text-[11px]">{{ __('Not restricted to specific batches (Applicable across all batches).') }}</span>
                            @endif
                        </div>

                        <!-- Courses -->
                        <div>
                            <span class="text-[11px] font-semibold text-muted-foreground block mb-1">{{ __('Associated Courses:') }}</span>
                            @if ($viewingInstitute->courses->isNotEmpty())
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($viewingInstitute->courses as $c)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-700 dark:text-blue-300 border border-blue-500/20 text-[11px] font-medium">
                                            <x-icon name="book-open" class="w-3 h-3" />
                                            <span>{{ $c->name }}</span>
                                            <span class="font-mono text-[10px] opacity-75">({{ $c->code }})</span>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-muted-foreground italic text-[11px]">{{ __('Not restricted to specific courses (Applicable across all courses).') }}</span>
                            @endif
                        </div>

                        <!-- Sections -->
                        <div>
                            <span class="text-[11px] font-semibold text-muted-foreground block mb-1">{{ __('Associated Sections:') }}</span>
                            @if ($viewingInstitute->sections->isNotEmpty())
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($viewingInstitute->sections as $s)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-purple-500/10 text-purple-700 dark:text-purple-300 border border-purple-500/20 text-[11px] font-medium">
                                            <x-icon name="grid" class="w-3 h-3" />
                                            <span>{{ $s->name }}</span>
                                            <span class="text-[10px] opacity-75">({{ $s->batch?->name ?? 'N/A' }})</span>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-muted-foreground italic text-[11px]">{{ __('Not restricted to specific sections.') }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Linked Faculty Members -->
                <div class="space-y-2">
                    <h4 class="font-bold text-foreground flex items-center justify-between text-xs">
                        <span class="flex items-center gap-1.5">
                            <x-icon name="users" class="w-4 h-4 text-primary" />
                            <span>{{ __('Affiliated / Linked Faculty Members') }}</span>
                        </span>
                        <span class="text-[11px] text-muted-foreground font-normal">
                            {{ __('Total :count faculties', ['count' => $viewingInstitute->faculties->count()]) }}
                        </span>
                    </h4>

                    @if ($viewingInstitute->faculties->isEmpty())
                        <div class="p-3 rounded-lg border border-border text-center text-muted-foreground italic text-xs">
                            {{ __('No faculty members linked to this partner institute yet.') }}
                        </div>
                    @else
                        <div class="rounded-xl border border-border divide-y divide-border overflow-hidden">
                            @foreach ($viewingInstitute->faculties as $fac)
                                <div class="p-2.5 flex items-center justify-between hover:bg-muted/30">
                                    <div class="flex items-center gap-2.5">
                                        @if ($fac->avatar_url)
                                            <img src="{{ $fac->avatar_url }}" alt="{{ $fac->name }}" class="w-7 h-7 rounded-full object-cover border border-border" />
                                        @else
                                            <div class="w-7 h-7 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[10px]">
                                                {{ $fac->initials }}
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-semibold text-foreground">{{ $fac->name }}</div>
                                            <div class="text-[10px] text-muted-foreground">{{ $fac->department }} &bull; {{ $fac->designation }}</div>
                                        </div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-muted text-foreground capitalize">
                                        {{ $fac->faculty_type_label }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex justify-end pt-3 border-t border-border">
                    <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('partner-institute-view-modal')">
                        {{ __('Close Dossier') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Delete Confirmation Modal -->
    <x-ui.modal
        name="partner-institute-delete-modal"
        maxWidth="max-w-md"
        :title="__('Confirm Deletion')"
        :description="__('Are you sure you want to delete this partner institute?')"
    >
        @if ($deletingInstitute)
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-full bg-rose-500/10 text-destructive">
                        <x-icon name="alert-triangle" class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Delete Institute Record') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('This action will soft-delete the institute record.') }}</p>
                    </div>
                </div>

                <div class="text-xs text-foreground bg-muted/40 p-3 rounded-lg border border-border">
                    <p>{{ __('Institute:') }} <strong class="text-foreground">{{ $deletingInstitute->name }}</strong> ({{ $deletingInstitute->code }}).</p>
                    @if ($deletingInstitute->faculties_count > 0)
                        <div class="mt-2 text-amber-600 dark:text-amber-400 font-medium">
                            {{ __('Warning: There are :count faculty members currently linked to this institute.', ['count' => $deletingInstitute->faculties_count]) }}
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('partner-institute-delete-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="destructive" size="sm" wire:click="deleteInstitute" class="gap-1.5">
                        <x-icon name="trash-2" class="h-4 w-4" />
                        <span>{{ __('Delete Institute') }}</span>
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Import Modal -->
    <x-ui.modal
        name="partner-institute-import-modal"
        maxWidth="max-w-lg"
        :title="__('Bulk Import Partner Coaching Institutes')"
        :description="__('Upload an Excel (.xlsx, .xls) or CSV (.csv) spreadsheet matching the required template columns.')"
    >
        <div class="space-y-4 text-xs">
            <div class="p-3 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-700 dark:text-blue-300 flex items-start gap-2.5">
                <x-icon name="info" class="w-4 h-4 shrink-0 mt-0.5" />
                <div>
                    <p class="font-semibold">{{ __('Import Instructions') }}</p>
                    <p class="mt-0.5">{{ __('Download the sample template below, fill in your institute records and upload the spreadsheet.') }}</p>
                </div>
            </div>

            <div>
                <button type="button" wire:click="downloadTemplate" class="inline-flex items-center gap-1.5 font-semibold text-primary hover:underline">
                    <x-icon name="download" class="w-4 h-4" />
                    <span>{{ __('Download Sample Partner Institutes Template (.xlsx)') }}</span>
                </button>
            </div>

            <div>
                <label class="block font-semibold text-foreground mb-1">
                    {{ __('Select Spreadsheet File') }} <span class="text-destructive">*</span>
                </label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".xlsx,.xls,.csv"
                    class="w-full text-muted-foreground file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer"
                />
                @error('importFile') <span class="text-destructive text-[11px]">{{ $message }}</span> @enderror
            </div>

            @if (! empty($importResult))
                <div class="p-3 rounded-xl {{ $importResult['imported'] > 0 ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/20' : 'bg-destructive/10 text-destructive border-destructive/20' }} border">
                    <p class="font-bold">{{ __('Import Results:') }}</p>
                    <p>{{ __('Imported:') }} {{ $importResult['imported'] }} {{ __('partner institutes') }}</p>
                    @if (! empty($importResult['errors']))
                        <ul class="list-disc list-inside mt-1 space-y-0.5 text-[11px]">
                            @foreach ($importResult['errors'] as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" x-data x-on:click="$store.modals.close('partner-institute-import-modal')">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" wire:click="processImport" wire:loading.attr="disabled" class="gap-1.5 disabled:opacity-50">
                    <span wire:loading.remove wire:target="processImport">{{ __('Upload & Import') }}</span>
                    <span wire:loading wire:target="processImport">{{ __('Processing...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
