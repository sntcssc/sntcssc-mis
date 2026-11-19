<?php

use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\BookPublisher;
use App\Models\LibraryMember;
use App\Services\FileUploadService;
use App\Services\Library\BookCirculationService;
use App\Services\Library\LibraryImportExportService;
use App\Services\Library\LibraryPolicyService;
use App\Services\Library\LibrarySecurityDepositValidator;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Library Catalog & Books')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'cat')]
    public string $categoryFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'avail')]
    public string $availabilityFilter = '';

    public string $sortField = 'title';
    public string $sortDirection = 'asc';
    public int $perPage = 12;

    // View layout: 'grid' or 'table'
    public string $viewMode = 'grid';

    // Form Modal state
    public ?int $editingId = null;
    public array $form = [
        'title' => '',
        'subtitle' => '',
        'isbn' => '',
        'isbn13' => '',
        'edition' => '',
        'category_id' => '',
        'publisher_id' => '',
        'author_ids' => [],
        'classification_number' => '',
        'language' => 'English',
        'pages' => '',
        'published_year' => '',
        'price' => '500.00',
        'is_reference_only' => false,
        'status' => 'active',
        'description' => '',
    ];

    // File uploads
    public $coverImageFile = null;
    public $galleryImageFiles = [];

    // Details Modal
    public ?int $viewingId = null;
    public ?Book $viewingBook = null;

    // Delete Modal
    public ?int $deleteId = null;

    // Quick Issue Modal state
    public bool $showQuickIssueModal = false;
    public ?int $quickIssueBookId = null;
    public ?int $quickIssueCopyId = null;
    public string $quickIssueMemberQuery = '';
    public ?int $quickIssueMemberId = null;
    public int $quickIssueLoanDays = 15;
    public bool $quickIssueBypass = false;
    public string $quickIssueBypassReason = '';
    public string $quickIssueNotes = '';
    public ?array $quickIssueValidation = null;

    // Quick Accession (Add Copies) Modal state
    public bool $showQuickAccessionModal = false;
    public ?int $quickAccessionBookId = null;
    public int $quickAccessionCount = 1;
    public string $quickAccessionPrefix = 'ACC';
    public float $quickAccessionCost = 0.00;
    public string $quickAccessionSupplier = '';
    public string $quickAccessionInvoice = '';
    public string $quickAccessionFunding = 'Institutional Academic Fund';
    public string $quickAccessionShelf = '';
    public string $quickAccessionCondition = 'new';

    // Bulk Import Modal state
    public bool $showImportModal = false;
    public $importFile = null;
    public ?array $importSummary = null;

    public function mount(): void
    {
        $this->quickIssueLoanDays = LibraryPolicyService::getDefaultLoanDays();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAvailabilityFilter(): void
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
    public function stats(): array
    {
        return [
            'total_titles' => Book::count(),
            'total_copies' => BookCopy::count(),
            'available_copies' => BookCopy::where('status', BookCopy::STATUS_AVAILABLE)->count(),
            'issued_copies' => BookCopy::whereIn('status', [BookCopy::STATUS_ISSUED, BookCopy::STATUS_RESERVED])->count(),
        ];
    }

    #[Computed]
    public function categories()
    {
        return BookCategory::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function authors()
    {
        return BookAuthor::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function publishers()
    {
        return BookPublisher::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function books()
    {
        return Book::query()
            ->with(['category', 'publisher', 'authors'])
            ->withCount([
                'copies',
                'availableCopies',
                'activeIssues',
            ])
            ->when($this->search, fn (Builder $q) => $q->search($this->search))
            ->when($this->categoryFilter, fn (Builder $q) => $q->where('category_id', $this->categoryFilter))
            ->when($this->statusFilter, fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->availabilityFilter === 'available', fn (Builder $q) => $q->where('available_copies_count', '>', 0))
            ->when($this->availabilityFilter === 'out_of_stock', fn (Builder $q) => $q->where('available_copies_count', '<=', 0))
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->coverImageFile = null;
        $this->galleryImageFiles = [];
        $this->form = [
            'title' => '',
            'subtitle' => '',
            'isbn' => '',
            'isbn13' => '',
            'edition' => '',
            'category_id' => '',
            'publisher_id' => '',
            'author_ids' => [],
            'classification_number' => '',
            'language' => 'English',
            'pages' => '',
            'published_year' => (string) now()->year,
            'price' => '500.00',
            'is_reference_only' => false,
            'status' => 'active',
            'description' => '',
        ];
        $this->dispatch('modal-open', name: 'book-form-modal');
    }

    public function openEditModal(int $id): void
    {
        $this->resetValidation();
        $this->editingId = $id;
        $this->coverImageFile = null;
        $this->galleryImageFiles = [];
        $book = Book::with('authors')->findOrFail($id);

        $this->form = [
            'title' => $book->title,
            'subtitle' => $book->subtitle ?? '',
            'isbn' => $book->isbn ?? '',
            'isbn13' => $book->isbn13 ?? '',
            'edition' => $book->edition ?? '',
            'category_id' => (string) ($book->category_id ?? ''),
            'publisher_id' => (string) ($book->publisher_id ?? ''),
            'author_ids' => $book->authors->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'classification_number' => $book->classification_number ?? '',
            'language' => $book->language ?? 'English',
            'pages' => $book->pages ? (string) $book->pages : '',
            'published_year' => $book->published_year ? (string) $book->published_year : '',
            'price' => (string) $book->price,
            'is_reference_only' => (bool) $book->is_reference_only,
            'status' => $book->status,
            'description' => $book->description ?? '',
        ];

        $this->dispatch('modal-open', name: 'book-form-modal');
    }

    public function saveBook(): void
    {
        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.category_id' => 'required|exists:book_categories,id',
            'form.price' => 'required|numeric|min:0',
            'coverImageFile' => 'nullable|image|max:5120',
            'galleryImageFiles.*' => 'nullable|image|max:5120',
        ]);

        DB::beginTransaction();
        try {
            $data = [
                'title' => trim($this->form['title']),
                'subtitle' => trim($this->form['subtitle']) ?: null,
                'isbn' => trim($this->form['isbn']) ?: null,
                'isbn13' => trim($this->form['isbn13']) ?: null,
                'edition' => trim($this->form['edition']) ?: null,
                'category_id' => $this->form['category_id'] ?: null,
                'publisher_id' => $this->form['publisher_id'] ?: null,
                'classification_number' => trim($this->form['classification_number']) ?: null,
                'language' => $this->form['language'] ?: 'English',
                'pages' => $this->form['pages'] ? (int) $this->form['pages'] : null,
                'published_year' => $this->form['published_year'] ? (int) $this->form['published_year'] : null,
                'price' => (float) $this->form['price'],
                'is_reference_only' => (bool) $this->form['is_reference_only'],
                'status' => $this->form['status'],
                'description' => trim($this->form['description']) ?: null,
            ];

            // Handle primary cover image upload
            if ($this->coverImageFile) {
                $coverPath = $this->coverImageFile->store('library/covers', 'public');
                $data['cover_image'] = $coverPath;
            }

            // Handle multiple gallery images upload
            if (! empty($this->galleryImageFiles)) {
                $galleryPaths = [];
                foreach ($this->galleryImageFiles as $file) {
                    $galleryPaths[] = $file->store('library/gallery', 'public');
                }
                if ($this->editingId) {
                    $existing = Book::find($this->editingId)?->images ?? [];
                    $data['images'] = array_merge($existing, $galleryPaths);
                } else {
                    $data['images'] = $galleryPaths;
                }
            }

            if ($this->editingId) {
                $book = Book::findOrFail($this->editingId);
                $book->update($data);
                Toast::dispatch($this, 'success', __('Book ":title" updated successfully.', ['title' => $book->title]));
            } else {
                $data['slug'] = Str::slug($data['title']).'-'.Str::random(5);
                $book = Book::create($data);
                Toast::dispatch($this, 'success', __('Book ":title" created successfully.', ['title' => $book->title]));
            }

            // Sync authors
            if (! empty($this->form['author_ids'])) {
                $sync = [];
                foreach ($this->form['author_ids'] as $idx => $authId) {
                    $sync[$authId] = ['is_primary' => $idx === 0];
                }
                $book->authors()->sync($sync);
            } else {
                $book->authors()->detach();
            }

            $book->refreshCopiesCount();
            DB::commit();

            $this->dispatch('modal-close', name: 'book-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save book: :err', ['err' => $e->getMessage()]));
        }
    }

    public function viewBookDetails(int $id): void
    {
        $this->viewingId = $id;
        $this->viewingBook = Book::with([
            'category',
            'publisher',
            'authors',
            'copies' => fn ($q) => $q->latest('id'),
            'activeIssues.libraryMember',
            'activeReservations.libraryMember',
        ])->findOrFail($id);

        $this->dispatch('modal-open', name: 'book-view-modal');
    }

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->dispatch('modal-open', name: 'book-delete-modal');
    }

    public function deleteSelected(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $book = Book::withCount('activeIssues')->findOrFail($this->deleteId);

        if ($book->active_issues_count > 0) {
            Toast::dispatch($this, 'error', __('Cannot delete book with active issued loans (:count loans currently out).', ['count' => $book->active_issues_count]));
            $this->dispatch('modal-close', name: 'book-delete-modal');
            return;
        }

        $title = $book->title;
        $book->delete();

        Toast::dispatch($this, 'success', __('Book ":title" deleted successfully.', ['title' => $title]));
        $this->deleteId = null;
        $this->dispatch('modal-close', name: 'book-delete-modal');
    }

    // Quick Accessioning (Add Copies in 1-click)
    public function openQuickAccession(int $bookId): void
    {
        $book = Book::findOrFail($bookId);
        $this->quickAccessionBookId = $bookId;
        $this->quickAccessionCount = 1;
        $this->quickAccessionCost = (float) $book->price;
        $this->quickAccessionPrefix = 'ACC';
        $this->quickAccessionSupplier = 'College Street Book Syndicate';
        $this->quickAccessionInvoice = 'INV-'.now()->format('Ymd');
        $this->quickAccessionFunding = 'Institutional Academic Fund';
        $this->quickAccessionShelf = 'Stack A, Rack 01, Shelf 1';
        $this->quickAccessionCondition = 'new';

        $this->dispatch('modal-open', name: 'quick-accession-modal');
    }

    public function submitQuickAccession(): void
    {
        $this->validate([
            'quickAccessionCount' => 'required|integer|min:1|max:50',
            'quickAccessionCost' => 'required|numeric|min:0',
            'quickAccessionShelf' => 'required|string|max:150',
        ]);

        $book = Book::findOrFail($this->quickAccessionBookId);

        DB::beginTransaction();
        try {
            $createdCount = 0;
            for ($i = 0; $i < $this->quickAccessionCount; $i++) {
                $accNo = LibraryImportExportService::generateNextAccessionNo($this->quickAccessionPrefix);
                BookCopy::create([
                    'book_id' => $book->id,
                    'accession_no' => $accNo,
                    'barcode' => $accNo,
                    'copy_number' => $book->copies()->count() + 1,
                    'acquisition_date' => now()->toDateString(),
                    'purchase_cost' => $this->quickAccessionCost,
                    'supplier_details' => $this->quickAccessionSupplier ?: null,
                    'invoice_number' => $this->quickAccessionInvoice ?: null,
                    'funding_source' => $this->quickAccessionFunding,
                    'classification_number' => $book->classification_number,
                    'shelf_location' => $this->quickAccessionShelf,
                    'book_condition' => $this->quickAccessionCondition,
                    'status' => BookCopy::STATUS_AVAILABLE,
                    'notes' => 'Quick accession batch.',
                ]);
                $createdCount++;
            }

            $book->refreshCopiesCount();
            DB::commit();

            Toast::dispatch($this, 'success', __('Successfully accessioned :count copies for ":title".', [
                'count' => $createdCount,
                'title' => $book->title,
            ]));
            $this->dispatch('modal-close', name: 'quick-accession-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to accession copies: :err', ['err' => $e->getMessage()]));
        }
    }

    // Quick Issue Modal
    public function openQuickIssue(int $bookId): void
    {
        $book = Book::with('availableCopies')->findOrFail($bookId);
        $firstCopy = $book->availableCopies->first();

        if (! $firstCopy) {
            Toast::dispatch($this, 'error', __('No copies currently available in stock to issue.'));
            return;
        }

        $this->quickIssueBookId = $bookId;
        $this->quickIssueCopyId = $firstCopy->id;
        $this->quickIssueMemberQuery = '';
        $this->quickIssueMemberId = null;
        $this->quickIssueLoanDays = LibraryPolicyService::getDefaultLoanDays();
        $this->quickIssueBypass = false;
        $this->quickIssueBypassReason = '';
        $this->quickIssueNotes = '';
        $this->quickIssueValidation = null;

        $this->dispatch('modal-open', name: 'quick-issue-modal');
    }

    public function selectMemberForIssue(int $memberId): void
    {
        $this->quickIssueMemberId = $memberId;
        $member = LibraryMember::with(['user', 'activeIssues.bookCopy'])->findOrFail($memberId);
        $copy = BookCopy::with('book')->findOrFail($this->quickIssueCopyId);

        $this->quickIssueValidation = LibrarySecurityDepositValidator::validateForIssue(
            $member,
            $copy,
            $this->quickIssueBypass,
            $this->quickIssueBypassReason
        );
    }

    public function updatedQuickIssueBypass(): void
    {
        if ($this->quickIssueMemberId && $this->quickIssueCopyId) {
            $member = LibraryMember::find($this->quickIssueMemberId);
            $copy = BookCopy::find($this->quickIssueCopyId);
            if ($member && $copy) {
                $this->quickIssueValidation = LibrarySecurityDepositValidator::validateForIssue(
                    $member,
                    $copy,
                    $this->quickIssueBypass,
                    $this->quickIssueBypassReason
                );
            }
        }
    }

    public function submitQuickIssue(): void
    {
        $this->validate([
            'quickIssueCopyId' => 'required|exists:book_copies,id',
            'quickIssueMemberId' => 'required|exists:library_members,id',
            'quickIssueLoanDays' => 'required|integer|min:1|max:90',
        ]);

        if ($this->quickIssueBypass && empty(trim($this->quickIssueBypassReason))) {
            $this->addError('quickIssueBypassReason', __('Please specify a justification reason for bypassing security deposit check.'));
            return;
        }

        $copy = BookCopy::findOrFail($this->quickIssueCopyId);
        $member = LibraryMember::findOrFail($this->quickIssueMemberId);

        try {
            $issue = BookCirculationService::issueBook(
                $copy,
                $member,
                $this->quickIssueLoanDays,
                $this->quickIssueBypass,
                $this->quickIssueBypassReason,
                $this->quickIssueNotes,
                auth()->user()
            );

            Toast::dispatch($this, 'success', __('Book ":title" (Copy: :acc) successfully issued to :member.', [
                'title' => $copy->book->title,
                'acc' => $copy->accession_no,
                'member' => $member->name,
            ]));

            $this->dispatch('modal-close', name: 'quick-issue-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    #[Computed]
    public function memberSearchResults()
    {
        if (strlen(trim($this->quickIssueMemberQuery)) < 2) {
            return collect();
        }

        $q = '%'.trim($this->quickIssueMemberQuery).'%';

        return LibraryMember::query()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', $q)
                    ->orWhere('member_number', 'like', $q)
                    ->orWhere('student_id', 'like', $q)
                    ->orWhere('email', 'like', $q)
                    ->orWhere('phone', 'like', $q);
            })
            ->where('status', LibraryMember::STATUS_ACTIVE)
            ->take(8)
            ->get();
    }

    public function export(string $format = 'csv')
    {
        $query = Book::with(['category', 'publisher', 'authors'])->latest('id');

        return TableExporter::download(
            $query,
            $format,
            'library-catalog',
            ['Title', 'ISBN', 'Category', 'Authors', 'Publisher', 'Call Number', 'Price', 'Total Copies', 'Available', 'Status'],
            fn ($b) => [
                $b->title,
                $b->isbn ?? 'N/A',
                $b->category?->name ?? 'Uncategorized',
                $b->authors_list ?? 'Unknown Author',
                $b->publisher?->name ?? 'N/A',
                $b->classification_number ?? 'N/A',
                number_format((float) ($b->price ?? 0), 2),
                $b->total_copies_count ?? 0,
                $b->available_copies_count ?? 0,
                ucfirst($b->status ?? 'active'),
            ],
            'SNTCSSC Library Master Catalog'
        );
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importSummary = null;
        $this->resetErrorBag();
        $this->showImportModal = true;
    }

    public function downloadTemplate()
    {
        return LibraryImportExportService::sampleBooksCsv();
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

            Toast::dispatch($this, 'success', __('Import completed: :imported books and copies imported.', ['imported' => $result['imported']]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :err', ['err' => $e->getMessage()]));
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 border border-teal-200/50 dark:border-teal-800/50">
                    <x-icon name="book-open" class="w-6 h-6" />
                </span>
                {{ __('Library Catalog & Book Inventory') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage bibliographic master records, image galleries, authors, classification codes, and circulation availability.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- View Mode Switcher -->
            <div class="inline-flex rounded-lg border border-border p-0.5 bg-muted/40">
                <button type="button" wire:click="$set('viewMode', 'grid')" class="p-1.5 rounded-md text-xs font-medium transition-all {{ $viewMode === 'grid' ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}" title="{{ __('Grid View') }}">
                    <x-icon name="layout-grid" class="w-4 h-4" />
                </button>
                <button type="button" wire:click="$set('viewMode', 'table')" class="p-1.5 rounded-md text-xs font-medium transition-all {{ $viewMode === 'table' ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}" title="{{ __('Table View') }}">
                    <x-icon name="list" class="w-4 h-4" />
                </button>
            </div>

            <!-- Export Dropdown -->
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground ml-0.5"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Catalog') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            @can('library.books.manage')
            <x-ui.button variant="outline" size="sm" wire:click="openImportModal" class="h-9 gap-1.5 border-teal-200 dark:border-teal-800/60 bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 hover:bg-teal-100">
                <x-icon name="upload" class="h-3.5 w-3.5 text-teal-600 dark:text-teal-400"/>
                {{ __('Import Books') }}
            </x-ui.button>

            <x-ui.button variant="default" size="sm" wire:click="openCreateModal" class="h-9 gap-1.5 bg-teal-600 hover:bg-teal-700 text-white shadow-xs">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('Add New Book') }}
            </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-teal-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Book Titles') }}</span>
                <span class="p-2 rounded-lg bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400">
                    <x-icon name="book-marked" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ $this->stats['total_titles'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Catalogued titles') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-emerald-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Physical Copies') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="layers" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ $this->stats['total_copies'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Barcoded library assets') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-blue-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wider">{{ __('Available on Shelf') }}</span>
                <span class="p-2 rounded-lg bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400">
                    <x-icon name="check-circle-2" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ $this->stats['available_copies'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Ready for borrowing') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-amber-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Currently Circulating') }}</span>
                <span class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="repeat" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ $this->stats['issued_copies'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Issued or on hold') }}</div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="p-4 bg-card border border-border rounded-xl shadow-xs flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
        <div class="flex-1 flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by title, ISBN, author, call no, accession no...') }}"
                    class="w-full pl-9 pr-4 py-2 text-sm bg-background border border-border rounded-lg focus:outline-hidden focus:ring-2 focus:ring-teal-500"
                />
            </div>

            <select wire:model.live="categoryFilter" class="px-3 py-2 text-sm bg-background border border-border rounded-lg focus:outline-hidden focus:ring-2 focus:ring-teal-500 sm:w-48">
                <option value="">{{ __('All Categories') }}</option>
                @foreach($this->categories as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="availabilityFilter" class="px-3 py-2 text-sm bg-background border border-border rounded-lg focus:outline-hidden focus:ring-2 focus:ring-teal-500 sm:w-44">
                <option value="">{{ __('All Availability') }}</option>
                <option value="available">{{ __('In Stock Only') }}</option>
                <option value="out_of_stock">{{ __('Out of Stock') }}</option>
            </select>
        </div>

        @if($search || $categoryFilter || $availabilityFilter || $statusFilter)
            <x-ui.button variant="ghost" size="sm" wire:click="$set('search', ''); $set('categoryFilter', ''); $set('availabilityFilter', ''); $set('statusFilter', '');" class="text-xs text-rose-600 hover:text-rose-700">
                <x-icon name="x" class="w-3.5 h-3.5 mr-1" />
                {{ __('Reset Filters') }}
            </x-ui.button>
        @endif
    </div>

    <!-- Books Display -->
    @if($viewMode === 'grid')
        <!-- GRID VIEW -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
            @forelse($this->books as $book)
                <div class="bg-card border border-border rounded-xl overflow-hidden shadow-xs hover:shadow-md transition-all flex flex-col group border-t-4 {{ $book->available_copies_count > 0 ? 'border-t-teal-500' : 'border-t-amber-500' }}">
                    <!-- Cover Image -->
                    <div class="relative h-48 bg-muted/30 overflow-hidden flex items-center justify-center border-b border-border">
                        @if($book->cover_image)
                            <img src="{{ $book->coverImageUrl() }}" alt="{{ $book->title }}" class="h-full w-full object-cover group-hover:scale-105 transition-transform duration-300" />
                        @else
                            <div class="flex flex-col items-center justify-center text-muted-foreground p-4 text-center">
                                <x-icon name="book" class="w-12 h-12 stroke-[1.2] opacity-40 mb-2" />
                                <span class="text-xs font-medium">{{ $book->category?->name ?? __('General') }}</span>
                            </div>
                        @endif

                        <!-- Badges Overlay -->
                        <div class="absolute top-2.5 left-2.5 flex flex-col gap-1">
                            @if($book->is_reference_only)
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-500 text-white shadow-xs">
                                    {{ __('Reference Only') }}
                                </span>
                            @endif
                        </div>

                        <div class="absolute top-2.5 right-2.5">
                            @if($book->available_copies_count > 0)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/90 text-white backdrop-blur-xs">
                                    {{ __(':count Available', ['count' => $book->available_copies_count]) }}
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/90 text-white backdrop-blur-xs">
                                    {{ __('Out of Stock') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Book Meta Info -->
                    <div class="p-4 flex-1 flex flex-col justify-between">
                        <div>
                            <div class="text-[11px] font-semibold text-teal-600 dark:text-teal-400 uppercase tracking-wider mb-1">
                                {{ $book->category?->name ?? __('Uncategorized') }}
                            </div>
                            <h3 class="font-bold text-foreground text-sm line-clamp-2 leading-snug group-hover:text-teal-600 transition-colors" title="{{ $book->title }}">
                                {{ $book->title }}
                            </h3>
                            <p class="text-xs text-muted-foreground mt-1 line-clamp-1">
                                {{ __('By :authors', ['authors' => $book->authors_list]) }}
                            </p>

                            <div class="mt-3 flex items-center justify-between text-xs text-muted-foreground border-t border-border/60 pt-2.5">
                                <span>{{ __('Call:') }} <strong class="text-foreground font-mono">{{ $book->classification_number ?: 'N/A' }}</strong></span>
                                <span class="font-bold text-foreground font-mono">₹{{ number_format($book->price, 2) }}</span>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="mt-4 pt-3 border-t border-border/60 flex items-center justify-between gap-1">
                            <button wire:click="viewBookDetails({{ $book->id }})" class="text-xs font-semibold text-teal-600 hover:text-teal-700 flex items-center gap-1">
                                <x-icon name="eye" class="w-3.5 h-3.5" />
                                {{ __('Details') }}
                            </button>

                            <div class="flex items-center gap-1">
                                @can('library.issue.manage')
                                    @if($book->available_copies_count > 0)
                                        <button wire:click="openQuickIssue({{ $book->id }})" class="px-2 py-1 bg-teal-600 hover:bg-teal-700 text-white rounded-md text-[11px] font-medium transition-all shadow-2xs flex items-center gap-1" title="{{ __('Quick Issue') }}">
                                            <x-icon name="repeat" class="w-3 h-3" />
                                            {{ __('Issue') }}
                                        </button>
                                    @endif
                                @endcan

                                @can('library.accession.manage')
                                    <button wire:click="openQuickAccession({{ $book->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted" title="{{ __('Add Copies') }}">
                                        <x-icon name="plus-circle" class="w-4 h-4" />
                                    </button>
                                @endcan

                                @can('library.books.manage')
                                    <button wire:click="openEditModal({{ $book->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted" title="{{ __('Edit') }}">
                                        <x-icon name="edit-3" class="w-4 h-4" />
                                    </button>
                                @endcan

                                @can('library.books.delete')
                                    <button wire:click="selectForDelete({{ $book->id }})" class="p-1 rounded-md text-muted-foreground hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/50" title="{{ __('Delete') }}">
                                        <x-icon name="trash" class="w-4 h-4" />
                                    </button>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full py-16 text-center bg-card border border-border rounded-xl">
                    <x-icon name="book-open" class="w-12 h-12 text-muted-foreground mx-auto opacity-30 mb-3" />
                    <h3 class="text-base font-semibold text-foreground">{{ __('No Books Found') }}</h3>
                    <p class="text-sm text-muted-foreground mt-1 max-w-sm mx-auto">
                        {{ __('No catalog books match your filter criteria. Try adjusting your search term or add a new book.') }}
                    </p>
                </div>
            @endforelse
        </div>
    @else
        <!-- TABLE VIEW -->
        <div class="bg-card border border-border rounded-xl overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/40 border-b border-border text-xs uppercase font-semibold text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3">{{ __('Book') }}</th>
                            <th class="px-4 py-3">{{ __('Category') }}</th>
                            <th class="px-4 py-3">{{ __('Authors') }}</th>
                            <th class="px-4 py-3">{{ __('Call No / ISBN') }}</th>
                            <th class="px-4 py-3 text-right cursor-pointer" wire:click="sortBy('price')">
                                {{ __('Price (₹)') }}
                            </th>
                            <th class="px-4 py-3 text-center">{{ __('Copies (Avail/Total)') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @forelse($this->books as $book)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if($book->cover_image)
                                            <img src="{{ $book->coverImageUrl() }}" alt="" class="w-9 h-12 object-cover rounded-md border border-border shrink-0" />
                                        @else
                                            <div class="w-9 h-12 rounded-md bg-muted border border-border flex items-center justify-center text-muted-foreground shrink-0">
                                                <x-icon name="book" class="w-4 h-4 opacity-50" />
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-bold text-foreground leading-tight">{{ $book->title }}</div>
                                            @if($book->subtitle)
                                                <div class="text-xs text-muted-foreground line-clamp-1">{{ $book->subtitle }}</div>
                                            @endif
                                            @if($book->is_reference_only)
                                                <span class="inline-block mt-0.5 px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400">
                                                    {{ __('Reference Only') }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="px-2 py-0.5 rounded-md bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 font-medium">
                                        {{ $book->category?->name ?? 'N/A' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">
                                    {{ $book->authors_list }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    <div>{{ $book->classification_number ?: '—' }}</div>
                                    <div class="text-[11px] text-muted-foreground">{{ $book->isbn ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-semibold text-foreground">
                                    ₹{{ number_format($book->price, 2) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold {{ $book->available_copies_count > 0 ? 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-400 border border-emerald-200/50 dark:border-emerald-800/50' : 'bg-rose-50 dark:bg-rose-950/50 text-rose-700 dark:text-rose-400 border border-rose-200/50 dark:border-rose-800/50' }}">
                                        <strong>{{ $book->available_copies_count }}</strong> / {{ $book->copies_count }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $book->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' }}">
                                        {{ ucfirst($book->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button wire:click="viewBookDetails({{ $book->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground" title="{{ __('View Details') }}">
                                            <x-icon name="eye" class="w-4 h-4" />
                                        </button>
                                        @can('library.issue.manage')
                                            @if($book->available_copies_count > 0)
                                                <button wire:click="openQuickIssue({{ $book->id }})" class="p-1 rounded-md text-teal-600 hover:text-teal-700 hover:bg-teal-50 dark:hover:bg-teal-950/50" title="{{ __('Quick Issue') }}">
                                                    <x-icon name="repeat" class="w-4 h-4" />
                                                </button>
                                            @endif
                                        @endcan
                                        @can('library.accession.manage')
                                            <button wire:click="openQuickAccession({{ $book->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground" title="{{ __('Add Copies') }}">
                                                <x-icon name="plus-circle" class="w-4 h-4" />
                                            </button>
                                        @endcan
                                        @can('library.books.manage')
                                            <button wire:click="openEditModal({{ $book->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground" title="{{ __('Edit') }}">
                                                <x-icon name="edit-3" class="w-4 h-4" />
                                            </button>
                                        @endcan
                                        @can('library.books.delete')
                                            <button wire:click="selectForDelete({{ $book->id }})" class="p-1 rounded-md text-muted-foreground hover:text-rose-600" title="{{ __('Delete') }}">
                                                <x-icon name="trash" class="w-4 h-4" />
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ __('No books match the search criteria.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Pagination -->
    <div class="mt-4">
        {{ $this->books->links() }}
    </div>

    <!-- CREATE / EDIT BOOK MODAL -->
    <x-ui.modal name="book-form-modal" :title="$editingId ? __('Edit Catalog Book') : __('Add Book to Catalog')" maxWidth="3xl">
        <form wire:submit="saveBook" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Book Title') }} *</label>
                    <input type="text" wire:model="form.title" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500" placeholder="{{ __('e.g. Indian Polity for Civil Services') }}" required />
                    @error('form.title') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Subtitle') }}</label>
                    <input type="text" wire:model="form.subtitle" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500" placeholder="{{ __('e.g. 7th Revised & Enlarged Edition') }}" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Category') }} *</label>
                    <select wire:model="form.category_id" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500" required>
                        <option value="">{{ __('Select Category') }}</option>
                        @foreach($this->categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                    @error('form.category_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Publisher') }}</label>
                    <select wire:model="form.publisher_id" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500">
                        <option value="">{{ __('Select Publisher') }}</option>
                        @foreach($this->publishers as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Author(s)') }}</label>
                    <select multiple wire:model="form.author_ids" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500 h-24">
                        @foreach($this->authors as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                    <span class="text-[11px] text-muted-foreground mt-0.5 block">{{ __('Hold Ctrl/Cmd to select multiple authors') }}</span>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Classification / Call Number') }}</label>
                        <input type="text" wire:model="form.classification_number" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500" placeholder="{{ __('e.g. 320.954 LAX') }}" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('ISBN') }}</label>
                        <input type="text" wire:model="form.isbn" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500" placeholder="{{ __('e.g. 978-9355325884') }}" />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Reference / Security Value (₹)') }} *</label>
                    <input type="number" step="0.01" wire:model="form.price" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500" required />
                    <span class="text-[11px] text-muted-foreground mt-0.5 block">{{ __('Used for Security Deposit checks during checkout') }}</span>
                    @error('form.price') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Edition & Year') }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" wire:model="form.edition" class="px-3 py-2 text-sm bg-background border border-border rounded-lg" placeholder="{{ __('Edition') }}" />
                        <input type="number" wire:model="form.published_year" class="px-3 py-2 text-sm bg-background border border-border rounded-lg" placeholder="{{ __('Year') }}" />
                    </div>
                </div>

                <!-- Cover Image (Single) -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Primary Cover Image') }}</label>
                    <input type="file" wire:model="coverImageFile" accept="image/*" class="w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
                    @if ($coverImageFile)
                        <div class="mt-2 text-xs text-emerald-600 flex items-center gap-1">
                            <x-icon name="check" class="w-3.5 h-3.5" />
                            {{ __('Image selected: :name', ['name' => $coverImageFile->getClientOriginalName()]) }}
                        </div>
                    @endif
                    @error('coverImageFile') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- Additional Gallery Images (Multiple upload!) -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Additional Gallery Images') }} ({{ __('Optional') }})</label>
                    <input type="file" multiple wire:model="galleryImageFiles" accept="image/*" class="w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-muted file:text-foreground hover:file:bg-muted/80" />
                    <span class="text-[11px] text-muted-foreground mt-0.5 block">{{ __('Upload one or multiple images for book preview') }}</span>
                    @error('galleryImageFiles.*') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description & Notes') }}</label>
                    <textarea wire:model="form.description" rows="3" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500" placeholder="{{ __('Syllabus relevance, topics covered, table of contents...') }}"></textarea>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="is_ref_only" wire:model="form.is_reference_only" class="rounded border-border text-teal-600 focus:ring-teal-500" />
                    <label for="is_ref_only" class="text-xs font-medium text-foreground cursor-pointer">
                        {{ __('Reference Only (Not available for circulation issue)') }}
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Status') }}</label>
                    <select wire:model="form.status" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'book-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white">
                    {{ $editingId ? __('Update Book') : __('Save Book') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- BOOK DETAILS MODAL -->
    @if($viewingBook)
    <x-ui.modal name="book-view-modal" :title="$viewingBook->title" maxWidth="4xl">
        <div class="space-y-6">
            <div class="flex flex-col md:flex-row gap-5 items-start">
                <div class="w-full md:w-48 shrink-0">
                    @if($viewingBook->cover_image)
                        <img src="{{ $viewingBook->coverImageUrl() }}" class="w-full rounded-xl border border-border shadow-md object-cover" />
                    @else
                        <div class="w-full h-60 rounded-xl bg-muted border border-border flex flex-col items-center justify-center text-muted-foreground p-4 text-center">
                            <x-icon name="book" class="w-12 h-12 opacity-30 mb-2" />
                            <span class="text-xs">{{ __('No Cover Image') }}</span>
                        </div>
                    @endif

                    @if(!empty($viewingBook->images))
                        <div class="mt-3">
                            <div class="text-[11px] font-semibold text-muted-foreground uppercase mb-1.5">{{ __('Gallery Images') }}</div>
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach($viewingBook->images as $img)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($img) }}" class="w-full h-14 object-cover rounded-md border border-border" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex-1 space-y-3">
                    <div>
                        <div class="text-xs font-semibold text-teal-600 dark:text-teal-400 uppercase tracking-wider">
                            {{ $viewingBook->category?->name }} ({{ $viewingBook->category?->code }})
                        </div>
                        <h2 class="text-xl font-bold text-foreground mt-0.5">{{ $viewingBook->title }}</h2>
                        @if($viewingBook->subtitle)
                            <div class="text-sm text-muted-foreground">{{ $viewingBook->subtitle }}</div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 p-3 bg-muted/30 rounded-xl border border-border text-xs">
                        <div>
                            <span class="text-muted-foreground block">{{ __('Authors') }}</span>
                            <strong class="text-foreground">{{ $viewingBook->authors_list }}</strong>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">{{ __('Publisher') }}</span>
                            <strong class="text-foreground">{{ $viewingBook->publisher?->name ?? 'N/A' }}</strong>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">{{ __('Call / DDC No') }}</span>
                            <strong class="text-foreground font-mono">{{ $viewingBook->classification_number ?: 'N/A' }}</strong>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">{{ __('ISBN') }}</span>
                            <strong class="text-foreground font-mono">{{ $viewingBook->isbn ?: 'N/A' }}</strong>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">{{ __('Reference Price') }}</span>
                            <strong class="text-foreground font-mono">₹{{ number_format($viewingBook->price, 2) }}</strong>
                        </div>
                        <div>
                            <span class="text-muted-foreground block">{{ __('Language / Year') }}</span>
                            <strong class="text-foreground">{{ $viewingBook->language }} / {{ $viewingBook->published_year ?: 'N/A' }}</strong>
                        </div>
                    </div>

                    @if($viewingBook->description)
                        <div class="text-xs text-muted-foreground leading-relaxed">
                            {{ $viewingBook->description }}
                        </div>
                    @endif

                    <!-- Physical Copies Breakdown -->
                    <div class="pt-3 border-t border-border">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold text-foreground uppercase tracking-wider">
                                {{ __('Physical Accession Copies') }} ({{ $viewingBook->copies->count() }})
                            </h4>
                            @can('library.accession.manage')
                                <x-ui.button variant="outline" size="sm" wire:click="openQuickAccession({{ $viewingBook->id }})" class="h-7 text-xs">
                                    <x-icon name="plus" class="w-3 h-3 mr-1" />
                                    {{ __('Add Copy') }}
                                </x-ui.button>
                            @endcan
                        </div>

                        <div class="max-h-48 overflow-y-auto rounded-lg border border-border">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('Accession No') }}</th>
                                        <th class="px-3 py-2">{{ __('Shelf Location') }}</th>
                                        <th class="px-3 py-2">{{ __('Condition') }}</th>
                                        <th class="px-3 py-2">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    @forelse($viewingBook->copies as $copy)
                                        <tr>
                                            <td class="px-3 py-2 font-mono font-semibold text-foreground">{{ $copy->accession_no }}</td>
                                            <td class="px-3 py-2 text-muted-foreground">{{ $copy->shelf_location ?: 'General Stack' }}</td>
                                            <td class="px-3 py-2">
                                                <span class="px-1.5 py-0.5 rounded text-[10px] uppercase font-semibold bg-slate-100 text-slate-700">
                                                    {{ $copy->book_condition }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2">
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $copy->status === 'available' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                    {{ ucfirst($copy->status) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-3 py-4 text-center text-muted-foreground">
                                                {{ __('No physical copies catalogued yet. Add copies via Accessioning.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-border flex justify-end">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'book-view-modal' })">
                    {{ __('Close') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
    @endif

    <!-- QUICK ACCESSION (ADD COPIES) MODAL -->
    <x-ui.modal name="quick-accession-modal" :title="__('Add Physical Copies (Bulk Accessioning)')" maxWidth="lg">
        <form wire:submit="submitQuickAccession" class="space-y-4">
            <div class="p-3 bg-teal-50 dark:bg-teal-950/40 rounded-xl border border-teal-200/50 dark:border-teal-800/50 text-xs text-teal-800 dark:text-teal-300">
                {{ __('Each physical copy is assigned a permanent, unique accession number and barcode to ensure complete auditability.') }}
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Number of Copies') }} *</label>
                    <input type="number" min="1" max="50" wire:model="quickAccessionCount" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg font-bold text-teal-600" required />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Accession Prefix') }} *</label>
                    <input type="text" wire:model="quickAccessionPrefix" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg font-mono uppercase" required />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Cost Per Copy (₹)') }} *</label>
                    <input type="number" step="0.01" wire:model="quickAccessionCost" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" required />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Initial Condition') }}</label>
                    <select wire:model="quickAccessionCondition" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                        <option value="new">{{ __('New') }}</option>
                        <option value="good">{{ __('Good') }}</option>
                        <option value="fair">{{ __('Fair') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Shelf Location / Cupboard') }} *</label>
                <input type="text" wire:model="quickAccessionShelf" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" placeholder="{{ __('e.g. Stack A, Rack 02, Shelf 3') }}" required />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Supplier / Vendor') }}</label>
                    <input type="text" wire:model="quickAccessionSupplier" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Invoice Number') }}</label>
                    <input type="text" wire:model="quickAccessionInvoice" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'quick-accession-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white">
                    {{ __('Accession Copies') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- QUICK ISSUE MODAL (WITH LIVE DEPOSIT VALIDATION & BYPASS) -->
    <x-ui.modal name="quick-issue-modal" :title="__('Quick Book Issue Desk')" maxWidth="xl">
        <form wire:submit="submitQuickIssue" class="space-y-4">
            <!-- Member Search -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select Borrower / Library Member') }} *</label>
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.250ms="quickIssueMemberQuery"
                        placeholder="{{ __('Type Member ID, Student ID, or Name...') }}"
                        class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500"
                    />
                </div>

                @if(!empty($this->memberSearchResults) && count($this->memberSearchResults) > 0)
                    <div class="mt-1 bg-card border border-border rounded-lg shadow-lg max-h-40 overflow-y-auto divide-y divide-border/60 z-20">
                        @foreach($this->memberSearchResults as $m)
                            <div wire:click="selectMemberForIssue({{ $m->id }}); $set('quickIssueMemberQuery', '{{ $m->name }} ({{ $m->member_number }})')" class="p-2.5 hover:bg-muted/50 cursor-pointer flex items-center justify-between text-xs">
                                <div>
                                    <strong class="text-foreground">{{ $m->name }}</strong>
                                    <span class="text-muted-foreground ml-1.5 font-mono">({{ $m->member_number }})</span>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-teal-100 text-teal-800">
                                    {{ ucfirst($m->member_type) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Live Security Deposit & Quota Card -->
            @if($quickIssueValidation)
                <div class="p-3.5 rounded-xl border {{ $quickIssueValidation['allowed'] ? 'bg-emerald-50/70 dark:bg-emerald-950/30 border-emerald-300 dark:border-emerald-800' : 'bg-rose-50/70 dark:bg-rose-950/30 border-rose-300 dark:border-rose-800' }}">
                    <div class="flex items-center justify-between text-xs font-semibold {{ $quickIssueValidation['allowed'] ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300' }}">
                        <span>{{ __('Security Deposit Status Verification') }}</span>
                        @if($quickIssueValidation['allowed'])
                            <span class="flex items-center gap-1 text-[11px] font-bold text-emerald-700">
                                <x-icon name="check-circle" class="w-3.5 h-3.5" />
                                {{ __('ELIGIBLE TO BORROW') }}
                            </span>
                        @else
                            <span class="flex items-center gap-1 text-[11px] font-bold text-rose-700">
                                <x-icon name="alert-triangle" class="w-3.5 h-3.5" />
                                {{ __('DEPOSIT CHECK FAILED') }}
                            </span>
                        @endif
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-2 pt-2 border-t border-current/10 text-xs">
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Active Deposit') }}</span>
                            <strong class="font-mono">₹{{ number_format($quickIssueValidation['active_deposit'], 2) }}</strong>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Already Borrowed') }}</span>
                            <strong class="font-mono">₹{{ number_format($quickIssueValidation['current_borrowed_value'], 2) }}</strong>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('New Book Price') }}</span>
                            <strong class="font-mono">₹{{ number_format($quickIssueValidation['new_book_price'], 2) }}</strong>
                        </div>
                    </div>

                    @if(! $quickIssueValidation['allowed'])
                        <div class="mt-2 text-xs font-medium text-rose-700 dark:text-rose-300 leading-snug">
                            {{ $quickIssueValidation['reason'] }}
                        </div>
                    @endif
                </div>
            @endif

            <!-- Special Librarian Bypass Toggle -->
            <div class="p-3 bg-muted/40 rounded-xl border border-border space-y-2">
                <div class="flex items-center justify-between">
                    <label for="bypass_check" class="text-xs font-bold text-foreground flex items-center gap-1.5 cursor-pointer">
                        <x-icon name="shield-alert" class="w-4 h-4 text-amber-500" />
                        {{ __('Librarian Security Deposit Bypass') }}
                    </label>
                    <input type="checkbox" id="bypass_check" wire:model.live="quickIssueBypass" class="rounded border-border text-amber-600 focus:ring-amber-500" />
                </div>
                <p class="text-[11px] text-muted-foreground">
                    {{ __('Bypasses the library security deposit check for faculty, temporary staff, or special approved exceptions.') }}
                </p>

                @if($quickIssueBypass)
                    <div class="pt-1.5">
                        <label class="block text-[11px] font-semibold text-foreground mb-1">{{ __('Bypass Justification / Authorization Note') }} *</label>
                        <input type="text" wire:model="quickIssueBypassReason" placeholder="{{ __('e.g. Temporary staff loan approved by Chief Librarian') }}" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg" required />
                        @error('quickIssueBypassReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            <!-- Loan Timeline -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Loan Duration (Days)') }} *</label>
                    <input type="number" min="1" max="90" wire:model="quickIssueLoanDays" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg font-bold" required />
                    <span class="text-[11px] text-muted-foreground mt-0.5 block">{{ __('Default institutional timeline is 15 days.') }}</span>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Due Date Calculation') }}</label>
                    <div class="px-3 py-2 text-sm bg-muted rounded-lg font-mono text-muted-foreground">
                        {{ now()->addDays($quickIssueLoanDays)->format('d M Y') }}
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'quick-issue-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white" :disabled="!$quickIssueValidation || (!$quickIssueValidation['allowed'] && !$quickIssueBypass)">
                    {{ __('Confirm Book Issue') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-ui.modal name="book-delete-modal" :title="__('Delete Book')" maxWidth="sm">
        <div class="space-y-3">
            <p class="text-sm text-muted-foreground">
                {{ __('Are you sure you want to delete this book record from the catalog? This will soft-delete the book and preserve permanent audit trails.') }}
            </p>
            <div class="flex justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'book-delete-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" variant="destructive" size="sm" wire:click="deleteSelected">
                    {{ __('Delete Book') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- BULK CATALOG IMPORT MODAL -->
    @if($showImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="upload" class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                        {{ __('Bulk Import Books Catalog (CSV / Excel)') }}
                    </h3>
                    <button type="button" wire:click="$set('showImportModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/60 rounded-xl border border-zinc-200 dark:border-zinc-700/60 text-xs text-zinc-600 dark:text-zinc-300 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Need a pre-filled starting template?') }}</span>
                        <button
                            type="button"
                            wire:click="downloadTemplate"
                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 hover:bg-teal-100 dark:hover:bg-teal-900/60 border border-teal-200 dark:border-teal-800 transition"
                        >
                            <x-icon name="download" class="w-3.5 h-3.5" />
                            {{ __('Download Sample CSV') }}
                        </button>
                    </div>
                    <p class="font-mono text-[11px] text-zinc-700 dark:text-zinc-300">
                        {{ __('Headers: Title, Subtitle, ISBN, Category, Author, Publisher, Edition, Price, Accession_No, Shelf_Location, Supplier, Invoice_No, Funding_Source, Condition') }}
                    </p>
                </div>

                <form wire:submit="submitImport" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-900 dark:text-zinc-100 mb-1">{{ __('Select CSV or Excel (.xlsx/.xls) File *') }}</label>
                        <input
                            type="file"
                            wire:model="importFile"
                            accept=".csv,.xlsx,.xls"
                            class="w-full text-xs file:mr-2 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-teal-50 file:text-teal-700 dark:file:bg-teal-950 dark:file:text-teal-300 hover:file:bg-teal-100 cursor-pointer"
                            required
                        />
                        <div wire:loading wire:target="importFile" class="text-xs text-teal-600 dark:text-teal-400 mt-1 flex items-center gap-1.5">
                            <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                            {{ __('Uploading file...') }}
                        </div>
                        @error('importFile') <span class="text-xs text-rose-600 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    @if($importSummary)
                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/80 rounded-xl border border-zinc-200 dark:border-zinc-700 text-xs space-y-1">
                            <div class="text-emerald-600 dark:text-emerald-400 font-bold flex items-center gap-1.5">
                                <x-icon name="check-circle" class="w-4 h-4" />
                                {{ __('Successfully imported: :count books and copies', ['count' => $importSummary['imported']]) }}
                            </div>
                            <div class="text-amber-600 dark:text-amber-400 font-bold flex items-center gap-1.5">
                                <x-icon name="alert-circle" class="w-4 h-4" />
                                {{ __('Skipped / duplicates: :count', ['count' => $importSummary['skipped']]) }}
                            </div>
                            @if(! empty($importSummary['errors']))
                                <div class="text-rose-600 dark:text-rose-400 font-semibold pt-1 border-t border-zinc-200 dark:border-zinc-700">
                                    {{ __('Errors Encountered:') }}
                                    <ul class="list-disc pl-4 text-[11px] font-normal max-h-24 overflow-y-auto mt-1 space-y-0.5">
                                        @foreach($importSummary['errors'] as $err)
                                            <li>{{ $err }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <button
                            type="button"
                            wire:click="$set('showImportModal', false)"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition"
                        >
                            {{ __('Close') }}
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold rounded-lg bg-teal-600 hover:bg-teal-700 text-white shadow-xs transition"
                        >
                            <span wire:loading.remove wire:target="submitImport">{{ __('Process Import') }}</span>
                            <span wire:loading wire:target="submitImport" class="flex items-center gap-1">
                                <x-icon name="loader" class="w-3.5 h-3.5 animate-spin" />
                                {{ __('Processing...') }}
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
