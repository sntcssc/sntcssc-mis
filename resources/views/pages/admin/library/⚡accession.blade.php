<?php

use App\Models\Book;
use App\Models\BookAccessionHistory;
use App\Models\BookCopy;
use App\Services\Library\BarcodeService;
use App\Services\Library\LibraryImportExportService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Book Accessioning Register')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'cond')]
    public string $conditionFilter = '';

    #[Url(as: 'funding')]
    public string $fundingFilter = '';

    public string $sortField = 'id';
    public string $sortDirection = 'desc';
    public int $perPage = 15;

    // Bulk selection for barcode labels
    public array $selectedCopyIds = [];
    public bool $selectAll = false;

    // Single/Bulk Accession Modal
    public bool $showAccessionModal = false;
    public ?int $editingCopyId = null;
    public array $accessionForm = [
        'book_id' => '',
        'copies_count' => 1,
        'accession_mode' => 'auto', // 'auto' or 'manual'
        'manual_accession_no' => '',
        'accession_prefix' => 'ACC',
        'acquisition_date' => '',
        'purchase_cost' => '0.00',
        'supplier_details' => 'College Street Book Syndicate, Kolkata',
        'invoice_number' => '',
        'funding_source' => 'State Government Academic Grant',
        'classification_number' => '',
        'shelf_location' => 'Stack A, Rack 01, Shelf 1',
        'book_condition' => 'new',
        'status' => 'available',
        'notes' => '',
    ];

    // Condition & Relocation Modal
    public bool $showConditionModal = false;
    public ?int $updatingCopyId = null;
    public string $newCondition = 'good';
    public string $newShelfLocation = '';
    public string $newStatus = 'available';
    public string $historyNotes = '';

    // History View Modal
    public bool $showHistoryModal = false;
    public ?BookCopy $viewingCopy = null;

    // Excel/CSV Import Modal
    public bool $showImportModal = false;
    public $importFile = null;
    public ?array $importSummary = null;

    // Barcode Single Preview Modal
    public ?BookCopy $barcodePreviewCopy = null;

    public function mount(): void
    {
        $this->accessionForm['acquisition_date'] = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedConditionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFundingFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedCopyIds = $this->copies->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $this->selectedCopyIds = [];
        }
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
    public function stats(): array
    {
        return [
            'total_copies' => BookCopy::count(),
            'available' => BookCopy::where('status', BookCopy::STATUS_AVAILABLE)->count(),
            'issued' => BookCopy::where('status', BookCopy::STATUS_ISSUED)->count(),
            'maintenance' => BookCopy::where('status', BookCopy::STATUS_MAINTENANCE)->count(),
            'lost_damaged' => BookCopy::whereIn('book_condition', [BookCopy::CONDITION_DAMAGED, BookCopy::CONDITION_LOST])->count(),
        ];
    }

    #[Computed]
    public function books()
    {
        return Book::orderBy('title')->get();
    }

    #[Computed]
    public function fundingSources(): array
    {
        return BookCopy::distinct()->whereNotNull('funding_source')->pluck('funding_source')->all();
    }

    #[Computed]
    public function copies()
    {
        return BookCopy::query()
            ->with(['book.category', 'book.publisher'])
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('accession_no', 'like', $term)
                        ->orWhere('barcode', 'like', $term)
                        ->orWhere('shelf_location', 'like', $term)
                        ->orWhere('supplier_details', 'like', $term)
                        ->orWhere('invoice_number', 'like', $term)
                        ->orWhereHas('book', fn ($bq) => $bq->where('title', 'like', $term)->orWhere('isbn', 'like', $term));
                });
            })
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->conditionFilter, fn (Builder $q) => $q->where('book_condition', $this->conditionFilter))
            ->when($this->fundingFilter, fn (Builder $q) => $q->where('funding_source', $this->fundingFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function openCreateAccessionModal(): void
    {
        $this->resetValidation();
        $this->editingCopyId = null;
        $this->accessionForm = [
            'book_id' => '',
            'copies_count' => 1,
            'accession_mode' => 'auto',
            'manual_accession_no' => '',
            'accession_prefix' => 'ACC',
            'acquisition_date' => now()->toDateString(),
            'purchase_cost' => '0.00',
            'supplier_details' => 'College Street Book Syndicate, Kolkata',
            'invoice_number' => 'INV-'.now()->format('Ymd'),
            'funding_source' => 'State Government Academic Grant',
            'classification_number' => '',
            'shelf_location' => 'Stack A, Rack 01, Shelf 1',
            'book_condition' => 'new',
            'status' => 'available',
            'notes' => '',
        ];

        $this->showAccessionModal = true;
        $this->dispatch('modal-open', name: 'accession-form-modal');
    }

    public function updatedAccessionFormBookId($value): void
    {
        if ($value) {
            $book = Book::find($value);
            if ($book) {
                $this->accessionForm['purchase_cost'] = (string) $book->price;
                $this->accessionForm['classification_number'] = $book->classification_number ?? '';
            }
        }
    }

    public function saveAccession(): void
    {
        $this->validate([
            'accessionForm.book_id' => 'required|exists:books,id',
            'accessionForm.copies_count' => 'required|integer|min:1|max:100',
            'accessionForm.acquisition_date' => 'required|date',
            'accessionForm.purchase_cost' => 'required|numeric|min:0',
            'accessionForm.shelf_location' => 'required|string|max:150',
        ]);

        $book = Book::findOrFail($this->accessionForm['book_id']);

        DB::beginTransaction();
        try {
            $createdCount = 0;
            $count = (int) $this->accessionForm['copies_count'];

            if ($this->accessionForm['accession_mode'] === 'manual') {
                if ($count > 1) {
                    throw new \InvalidArgumentException(__('Manual accession number entry can only be used for 1 copy at a time. Use auto-numbering for bulk copies.'));
                }
                $accNo = trim($this->accessionForm['manual_accession_no']);
                if (empty($accNo)) {
                    throw new \InvalidArgumentException(__('Please enter a manual accession number.'));
                }
                if (BookCopy::where('accession_no', $accNo)->exists()) {
                    throw new \InvalidArgumentException(__('Accession number ":no" already exists! Duplicates are strictly prohibited.', ['no' => $accNo]));
                }

                BookCopy::create([
                    'book_id' => $book->id,
                    'accession_no' => $accNo,
                    'barcode' => $accNo,
                    'copy_number' => $book->copies()->count() + 1,
                    'acquisition_date' => $this->accessionForm['acquisition_date'],
                    'purchase_cost' => (float) $this->accessionForm['purchase_cost'],
                    'supplier_details' => $this->accessionForm['supplier_details'] ?: null,
                    'invoice_number' => $this->accessionForm['invoice_number'] ?: null,
                    'funding_source' => $this->accessionForm['funding_source'] ?: null,
                    'classification_number' => $this->accessionForm['classification_number'] ?: $book->classification_number,
                    'shelf_location' => $this->accessionForm['shelf_location'],
                    'book_condition' => $this->accessionForm['book_condition'],
                    'status' => BookCopy::STATUS_AVAILABLE,
                    'notes' => $this->accessionForm['notes'] ?: null,
                ]);
                $createdCount = 1;
            } else {
                // Auto sequential accession numbering
                $prefix = trim($this->accessionForm['accession_prefix']) ?: 'ACC';
                for ($i = 0; $i < $count; $i++) {
                    $accNo = LibraryImportExportService::generateNextAccessionNo($prefix);
                    BookCopy::create([
                        'book_id' => $book->id,
                        'accession_no' => $accNo,
                        'barcode' => $accNo,
                        'copy_number' => $book->copies()->count() + 1,
                        'acquisition_date' => $this->accessionForm['acquisition_date'],
                        'purchase_cost' => (float) $this->accessionForm['purchase_cost'],
                        'supplier_details' => $this->accessionForm['supplier_details'] ?: null,
                        'invoice_number' => $this->accessionForm['invoice_number'] ?: null,
                        'funding_source' => $this->accessionForm['funding_source'] ?: null,
                        'classification_number' => $this->accessionForm['classification_number'] ?: $book->classification_number,
                        'shelf_location' => $this->accessionForm['shelf_location'],
                        'book_condition' => $this->accessionForm['book_condition'],
                        'status' => BookCopy::STATUS_AVAILABLE,
                        'notes' => $this->accessionForm['notes'] ?: null,
                    ]);
                    $createdCount++;
                }
            }

            $book->refreshCopiesCount();
            DB::commit();

            Toast::dispatch($this, 'success', __('Successfully registered :count copies into the Accession Register.', ['count' => $createdCount]));
            $this->dispatch('modal-close', name: 'accession-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openConditionModal(int $copyId): void
    {
        $this->updatingCopyId = $copyId;
        $copy = BookCopy::findOrFail($copyId);
        $this->newCondition = $copy->book_condition;
        $this->newShelfLocation = $copy->shelf_location ?? '';
        $this->newStatus = $copy->status;
        $this->historyNotes = '';

        $this->dispatch('modal-open', name: 'condition-update-modal');
    }

    public function submitConditionUpdate(): void
    {
        $copy = BookCopy::findOrFail($this->updatingCopyId);

        DB::beginTransaction();
        try {
            $changed = false;
            if ($copy->book_condition !== $this->newCondition) {
                $copy->markCondition($this->newCondition, $this->historyNotes);
                $changed = true;
            }
            if ($copy->shelf_location !== $this->newShelfLocation) {
                $copy->markRelocated($this->newShelfLocation, $this->historyNotes);
                $changed = true;
            }
            if ($copy->status !== $this->newStatus) {
                $copy->markStatus($this->newStatus, $this->historyNotes);
                $changed = true;
            }

            DB::commit();
            Toast::dispatch($this, 'success', __('Accession record :acc updated successfully.', ['acc' => $copy->accession_no]));
            $this->dispatch('modal-close', name: 'condition-update-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openHistoryModal(int $copyId): void
    {
        $this->viewingCopy = BookCopy::with(['histories.creator', 'book'])->findOrFail($copyId);
        $this->dispatch('modal-open', name: 'accession-history-modal');
    }

    public function previewBarcode(int $copyId): void
    {
        $this->barcodePreviewCopy = BookCopy::with('book')->findOrFail($copyId);
        $this->dispatch('modal-open', name: 'barcode-preview-modal');
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importSummary = null;
        $this->dispatch('modal-open', name: 'accession-import-modal');
    }

    public function submitImport(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $result = LibraryImportExportService::importBooksAndCopies($path);
            $this->importSummary = $result;

            Toast::dispatch($this, 'success', __('Import completed: :imported records imported.', ['imported' => $result['imported']]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'csv')
    {
        $query = BookCopy::with(['book.category'])->latest('id');

        return TableExporter::download(
            $query,
            $format,
            'accession-register',
            ['Accession No', 'Barcode', 'Book Title', 'Category', 'Call Number', 'Acquisition Date', 'Cost (INR)', 'Shelf Location', 'Supplier', 'Funding', 'Condition', 'Status'],
            fn ($c) => [
                $c->accession_no,
                $c->barcode,
                $c->book?->title ?? 'N/A',
                $c->book?->category?->name ?? 'N/A',
                $c->classification_number ?? 'N/A',
                $c->acquisition_date ? $c->acquisition_date->format('Y-m-d') : 'N/A',
                number_format((float) ($c->purchase_cost ?? $c->price ?? 0), 2),
                $c->shelf_location ?? 'Stack',
                $c->supplier_details ?? 'N/A',
                $c->funding_source ?? 'N/A',
                ucfirst($c->book_condition ?? 'good'),
                ucfirst($c->status ?? 'available'),
            ],
            'SNTCSSC Central Library Permanent Accession Register'
        );
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 border border-teal-200/50 dark:border-teal-800/50">
                    <x-icon name="barcode" class="w-6 h-6" />
                </span>
                {{ __('Book Accessioning Register') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Permanent accession register for every physical copy: unique accession numbers, barcodes, supplier invoices, shelf allocations, and audit history.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Print Barcode Labels -->
            @if(!empty($selectedCopyIds))
                <a href="{{ route('library.barcode.print', ['ids' => implode(',', $selectedCopyIds)]) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-500 hover:bg-amber-600 text-white shadow-xs transition-colors">
                    <x-icon name="printer" class="w-3.5 h-3.5" />
                    {{ __('Print :count Barcodes', ['count' => count($selectedCopyIds)]) }}
                </a>
            @else
                <a href="{{ route('library.barcode.print', ['limit' => 24]) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-card hover:bg-muted text-foreground transition-colors">
                    <x-icon name="printer" class="w-3.5 h-3.5" />
                    {{ __('Print Latest Barcode Sheet') }}
                </a>
            @endif

            <!-- Import Modal Trigger -->
            @can('library.import')
            <x-ui.button variant="outline" size="sm" wire:click="openImportModal" class="h-9 gap-1.5">
                <x-icon name="upload" class="h-3.5 w-3.5" />
                {{ __('Import CSV/Excel') }}
            </x-ui.button>
            @endcan

            <!-- Export Dropdown -->
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground ml-0.5"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export Accession CSV') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Accession Excel') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export Accession PDF') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            @can('library.accession.manage')
            <x-ui.button variant="default" size="sm" wire:click="openCreateAccessionModal" class="h-9 gap-1.5 bg-teal-600 hover:bg-teal-700 text-white shadow-xs">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('Accession Copies') }}
            </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-muted-foreground uppercase">{{ __('Accessioned Assets') }}</span>
            <div class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['total_copies'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Total registered copies') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase">{{ __('Available in Stacks') }}</span>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ $this->stats['available'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Shelf status: Available') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase">{{ __('Currently Borrowed') }}</span>
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">{{ $this->stats['issued'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Active loans') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase">{{ __('In Maintenance') }}</span>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ $this->stats['maintenance'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Binding / Repair') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-rose-600 dark:text-rose-400 uppercase">{{ __('Damaged / Lost') }}</span>
            <div class="text-2xl font-bold text-rose-600 dark:text-rose-400 mt-1">{{ $this->stats['lost_damaged'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Reported loss or wear') }}</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="p-4 bg-card border border-border rounded-xl shadow-xs flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
        <div class="flex-1 flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search accession no, barcode, title, shelf, supplier, invoice...') }}"
                    class="w-full pl-9 pr-4 py-2 text-sm bg-background border border-border rounded-lg focus:outline-hidden focus:ring-2 focus:ring-teal-500"
                />
            </div>

            <select wire:model.live="statusFilter" class="px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500 sm:w-40">
                <option value="">{{ __('All Statuses') }}</option>
                <option value="available">{{ __('Available') }}</option>
                <option value="issued">{{ __('Issued') }}</option>
                <option value="reserved">{{ __('Reserved') }}</option>
                <option value="under_maintenance">{{ __('Maintenance') }}</option>
                <option value="lost">{{ __('Lost') }}</option>
                <option value="written_off">{{ __('Written Off') }}</option>
            </select>

            <select wire:model.live="conditionFilter" class="px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500 sm:w-40">
                <option value="">{{ __('All Conditions') }}</option>
                <option value="new">{{ __('New') }}</option>
                <option value="good">{{ __('Good') }}</option>
                <option value="fair">{{ __('Fair') }}</option>
                <option value="damaged">{{ __('Damaged') }}</option>
                <option value="worn">{{ __('Worn') }}</option>
            </select>
        </div>

        @if($search || $statusFilter || $conditionFilter || $fundingFilter)
            <x-ui.button variant="ghost" size="sm" wire:click="$set('search', ''); $set('statusFilter', ''); $set('conditionFilter', ''); $set('fundingFilter', '');" class="text-xs text-rose-600 hover:text-rose-700">
                <x-icon name="x" class="w-3.5 h-3.5 mr-1" />
                {{ __('Reset') }}
            </x-ui.button>
        @endif
    </div>

    <!-- Accession Register Table -->
    <div class="bg-card border border-border rounded-xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/40 border-b border-border text-xs uppercase font-semibold text-muted-foreground">
                    <tr>
                        <th class="w-10 px-3 py-3 text-center">
                            <input type="checkbox" wire:model.live="selectAll" class="rounded border-border text-teal-600 focus:ring-teal-500" />
                        </th>
                        <th class="px-3 py-3 cursor-pointer" wire:click="sortBy('accession_no')">
                            {{ __('Accession No') }}
                        </th>
                        <th class="px-3 py-3">{{ __('Barcode') }}</th>
                        <th class="px-4 py-3">{{ __('Book Title & Info') }}</th>
                        <th class="px-3 py-3">{{ __('Call No & Shelf') }}</th>
                        <th class="px-3 py-3">{{ __('Acquisition') }}</th>
                        <th class="px-3 py-3 text-right">{{ __('Cost (₹)') }}</th>
                        <th class="px-3 py-3 text-center">{{ __('Condition') }}</th>
                        <th class="px-3 py-3 text-center">{{ __('Status') }}</th>
                        <th class="px-3 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($this->copies as $copy)
                        <tr class="hover:bg-muted/30 transition-colors {{ in_array($copy->id, $selectedCopyIds) ? 'bg-teal-50/40 dark:bg-teal-950/20' : '' }}">
                            <td class="w-10 px-3 py-3 text-center">
                                <input type="checkbox" value="{{ $copy->id }}" wire:model.live="selectedCopyIds" class="rounded border-border text-teal-600 focus:ring-teal-500" />
                            </td>
                            <td class="px-3 py-3 font-mono font-bold text-foreground">
                                {{ $copy->accession_no }}
                            </td>
                            <td class="px-3 py-3 font-mono text-xs text-muted-foreground">
                                <button type="button" wire:click="previewBarcode({{ $copy->id }})" class="hover:text-teal-600 flex items-center gap-1 font-semibold" title="{{ __('View Barcode') }}">
                                    <x-icon name="barcode" class="w-4 h-4" />
                                    {{ $copy->barcode }}
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-bold text-foreground">{{ $copy->book->title }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ $copy->book->authors_list }} &bull; {{ $copy->book->category?->name }}
                                </div>
                            </td>
                            <td class="px-3 py-3 text-xs">
                                <div class="font-mono font-semibold text-foreground">{{ $copy->classification_number ?: '—' }}</div>
                                <div class="text-muted-foreground">{{ $copy->shelf_location ?: 'General Stack' }}</div>
                            </td>
                            <td class="px-3 py-3 text-xs text-muted-foreground">
                                <div>{{ $copy->acquisition_date ? $copy->acquisition_date->format('d M Y') : '—' }}</div>
                                @if($copy->supplier_details)
                                    <div class="text-[10px] truncate max-w-xs">{{ $copy->supplier_details }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right font-mono font-semibold text-foreground">
                                ₹{{ number_format($copy->purchase_cost, 2) }}
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase {{ match($copy->book_condition) {
                                    'new' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
                                    'good' => 'bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300',
                                    'fair' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
                                    'damaged' => 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
                                    default => 'bg-zinc-100 text-zinc-700',
                                } }}">
                                    {{ $copy->book_condition }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold {{ match($copy->status) {
                                    'available' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200/50',
                                    'issued' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300 border border-blue-200/50',
                                    'reserved' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 border border-amber-200/50',
                                    'under_maintenance' => 'bg-violet-50 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300 border border-violet-200/50',
                                    'lost' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300 border border-rose-200/50',
                                    default => 'bg-zinc-100 text-zinc-700',
                                } }}">
                                    {{ ucfirst(str_replace('_', ' ', $copy->status)) }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button wire:click="openConditionModal({{ $copy->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground" title="{{ __('Update Condition / Shelf Location') }}">
                                        <x-icon name="edit-3" class="w-4 h-4" />
                                    </button>
                                    <button wire:click="openHistoryModal({{ $copy->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground" title="{{ __('Accession History Log') }}">
                                        <x-icon name="clock" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center text-muted-foreground">
                                {{ __('No accession records match the current filters.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-4">
        {{ $this->copies->links() }}
    </div>

    <!-- ACCESSION FORM MODAL (SINGLE / BULK) -->
    <x-ui.modal name="accession-form-modal" :title="__('Accession Books (Acquisition Register)')" maxWidth="2xl">
        <form wire:submit="saveAccession" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Book Master Title') }} *</label>
                <select wire:model.live="accessionForm.book_id" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" required>
                    <option value="">{{ __('Select Title from Catalog') }}</option>
                    @foreach($this->books as $b)
                        <option value="{{ $b->id }}">{{ $b->title }} ({{ $b->isbn ?: 'No ISBN' }})</option>
                    @endforeach
                </select>
                @error('accessionForm.book_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Number of Copies') }} *</label>
                    <input type="number" min="1" max="100" wire:model="accessionForm.copies_count" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg font-bold text-teal-600" required />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Accession Mode') }} *</label>
                    <select wire:model.live="accessionForm.accession_mode" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                        <option value="auto">{{ __('Auto-Generated Sequential (Recommended)') }}</option>
                        <option value="manual">{{ __('Manual Accession Number') }}</option>
                    </select>
                </div>
            </div>

            @if($accessionForm['accession_mode'] === 'manual')
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Manual Accession Number') }} *</label>
                    <input type="text" wire:model="accessionForm.manual_accession_no" placeholder="{{ __('e.g. ACC-2026-99999') }}" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg font-mono uppercase" required />
                    <span class="text-[11px] text-muted-foreground mt-0.5 block">{{ __('Must be strictly unique across the entire permanent accession ledger.') }}</span>
                </div>
            @else
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Accession Prefix') }} *</label>
                    <input type="text" wire:model="accessionForm.accession_prefix" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg font-mono uppercase" required />
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Acquisition Date') }} *</label>
                    <input type="date" wire:model="accessionForm.acquisition_date" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" required />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Purchase Cost Per Copy (₹)') }} *</label>
                    <input type="number" step="0.01" wire:model="accessionForm.purchase_cost" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" required />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Shelf Location / Cupboard') }} *</label>
                    <input type="text" wire:model="accessionForm.shelf_location" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" placeholder="{{ __('e.g. Stack A, Rack 02, Shelf 3') }}" required />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Classification / Call Number') }}</label>
                    <input type="text" wire:model="accessionForm.classification_number" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Supplier / Vendor Details') }}</label>
                    <input type="text" wire:model="accessionForm.supplier_details" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Invoice Number') }}</label>
                    <input type="text" wire:model="accessionForm.invoice_number" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Funding Source / Grant') }}</label>
                <input type="text" wire:model="accessionForm.funding_source" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" placeholder="{{ __('e.g. State Grant, Institutional Fund, Donation') }}" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Initial Physical Condition') }}</label>
                    <select wire:model="accessionForm.book_condition" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                        <option value="new">{{ __('New') }}</option>
                        <option value="good">{{ __('Good') }}</option>
                        <option value="fair">{{ __('Fair') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Initial Circulation Status') }}</label>
                    <select wire:model="accessionForm.status" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                        <option value="available">{{ __('Available') }}</option>
                        <option value="under_maintenance">{{ __('Under Maintenance') }}</option>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'accession-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white">
                    {{ __('Register Accession') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- CONDITION & SHELF RELOCATION MODAL -->
    <x-ui.modal name="condition-update-modal" :title="__('Update Copy Condition & Relocation')" maxWidth="md">
        <form wire:submit="submitConditionUpdate" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Physical Condition') }} *</label>
                <select wire:model="newCondition" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                    <option value="new">{{ __('New') }}</option>
                    <option value="good">{{ __('Good') }}</option>
                    <option value="fair">{{ __('Fair') }}</option>
                    <option value="damaged">{{ __('Damaged') }}</option>
                    <option value="worn">{{ __('Worn Out') }}</option>
                    <option value="lost">{{ __('Lost') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Circulation Status') }} *</label>
                <select wire:model="newStatus" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                    <option value="available">{{ __('Available for Issue') }}</option>
                    <option value="under_maintenance">{{ __('Under Maintenance / Binding') }}</option>
                    <option value="lost">{{ __('Mark as Lost') }}</option>
                    <option value="written_off">{{ __('Written Off / Discarded') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Shelf Location') }} *</label>
                <input type="text" wire:model="newShelfLocation" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" required />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Audit Notes / Justification') }}</label>
                <textarea wire:model="historyNotes" rows="2" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" placeholder="{{ __('Reason for condition downgrade, binding repair, or stack relocation...') }}"></textarea>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'condition-update-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white">
                    {{ __('Save & Audit') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- ACCESSION HISTORY AUDIT MODAL -->
    @if($viewingCopy)
    <x-ui.modal name="accession-history-modal" :title="__('Permanent Accession Audit Log - :acc', ['acc' => $viewingCopy->accession_no])" maxWidth="lg">
        <div class="space-y-4">
            <div class="p-3 bg-muted/30 rounded-xl border border-border text-xs">
                <strong class="text-foreground block">{{ $viewingCopy->book->title }}</strong>
                <span class="text-muted-foreground">{{ __('Accession No: :acc &bull; Current Shelf: :shelf', ['acc' => $viewingCopy->accession_no, 'shelf' => $viewingCopy->shelf_location]) }}</span>
            </div>

            <div class="space-y-3 max-h-80 overflow-y-auto">
                @forelse($viewingCopy->histories as $h)
                    <div class="p-3 bg-card border border-border rounded-lg text-xs space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-teal-600 uppercase">{{ ucfirst(str_replace('_', ' ', $h->action)) }}</span>
                            <span class="text-muted-foreground font-mono">{{ $h->created_at->format('d M Y, h:i A') }}</span>
                        </div>
                        @if($h->old_condition || $h->new_condition)
                            <div class="text-muted-foreground">
                                {{ __('Condition: :old -> :new', ['old' => $h->old_condition ?? 'Initial', 'new' => $h->new_condition]) }}
                            </div>
                        @endif
                        @if($h->old_shelf_location || $h->new_shelf_location)
                            <div class="text-muted-foreground">
                                {{ __('Relocation: :old -> :new', ['old' => $h->old_shelf_location ?? 'Initial', 'new' => $h->new_shelf_location]) }}
                            </div>
                        @endif
                        @if($h->notes)
                            <div class="text-foreground italic">"{{ $h->notes }}"</div>
                        @endif
                        <div class="text-[10px] text-muted-foreground pt-1 border-t border-border/40">
                            {{ __('Recorded by: :user', ['user' => $h->creator?->name ?? 'System']) }}
                        </div>
                    </div>
                @empty
                    <div class="text-center py-6 text-muted-foreground text-xs">
                        {{ __('No audit entries recorded for this copy.') }}
                    </div>
                @endforelse
            </div>

            <div class="pt-3 border-t border-border flex justify-end">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'accession-history-modal' })">
                    {{ __('Close') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
    @endif

    <!-- BARCODE SINGLE PREVIEW MODAL -->
    @if($barcodePreviewCopy)
    <x-ui.modal name="barcode-preview-modal" :title="__('Accession Barcode & QR Code')" maxWidth="sm">
        <div class="space-y-4 text-center py-2">
            <div>
                <h4 class="font-bold text-foreground text-sm">{{ $barcodePreviewCopy->book->title }}</h4>
                <div class="text-xs text-muted-foreground font-mono mt-0.5">{{ $barcodePreviewCopy->accession_no }}</div>
            </div>

            <div class="p-4 bg-white border border-border rounded-xl inline-block shadow-xs">
                <img src="{{ \App\Services\Library\BarcodeService::generateBarcodeDataUri($barcodePreviewCopy->barcode, 45, 2, true) }}" alt="Barcode" class="mx-auto" />
            </div>

            <div class="flex justify-center gap-2">
                <a href="{{ route('library.barcode.print', ['ids' => $barcodePreviewCopy->id]) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-teal-600 hover:bg-teal-700 text-white shadow-xs">
                    <x-icon name="printer" class="w-3.5 h-3.5" />
                    {{ __('Print Label') }}
                </a>
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'barcode-preview-modal' })">
                    {{ __('Close') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
    @endif

    <!-- EXCEL / CSV IMPORT MODAL -->
    <x-ui.modal name="accession-import-modal" :title="__('Import Books & Accession Register (Excel/CSV)')" maxWidth="lg">
        <form wire:submit="submitImport" class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-xl border border-border text-xs text-muted-foreground space-y-1">
                <strong>{{ __('Supported format & column headers:') }}</strong>
                <p class="font-mono text-[11px] text-foreground">
                    Title, Subtitle, ISBN, Category, Author, Publisher, Edition, Price, Accession_No, Shelf_Location, Supplier, Invoice_No, Funding_Source, Condition
                </p>
                <p>{{ __('If Accession_No is omitted, the system will auto-generate unique sequential numbers.') }}</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select CSV or Excel (.xlsx) File') }} *</label>
                <input type="file" wire:model="importFile" accept=".csv,.xlsx,.xls" class="w-full text-xs file:mr-2 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" required />
                @error('importFile') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            @if($importSummary)
                <div class="p-3 bg-slate-50 dark:bg-slate-900 rounded-xl border border-border text-xs space-y-1">
                    <div class="text-emerald-600 font-bold">{{ __('Imported: :count', ['count' => $importSummary['imported']]) }}</div>
                    <div class="text-amber-600 font-bold">{{ __('Skipped: :count', ['count' => $importSummary['skipped']]) }}</div>
                    @if(!empty($importSummary['errors']))
                        <div class="text-rose-600 font-semibold pt-1 border-t border-border/60">
                            {{ __('Errors Encountered:') }}
                            <ul class="list-disc pl-4 text-[11px] font-normal max-h-24 overflow-y-auto mt-1">
                                @foreach($importSummary['errors'] as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'accession-import-modal' })">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white">
                    {{ __('Process Import') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
