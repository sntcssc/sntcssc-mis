<?php

use App\Models\BookAuthor;
use App\Models\BookCategory;
use App\Models\BookPublisher;
use App\Services\Library\LibraryImportExportService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Library Master Data')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'categories'; // 'categories', 'authors', 'publishers'

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 15;

    // Category modal
    public bool $showCategoryModal = false;
    public ?int $editingCategoryId = null;
    public string $catName = '';
    public string $catCode = '';
    public string $catDdc = '';
    public string $catDescription = '';
    public bool $catActive = true;

    // Author modal
    public bool $showAuthorModal = false;
    public ?int $editingAuthorId = null;
    public string $authorName = '';
    public string $authorBio = '';
    public string $authorEmail = '';
    public string $authorNationality = '';
    public string $authorWebsite = '';
    public bool $authorActive = true;

    // Publisher modal
    public bool $showPublisherModal = false;
    public ?int $editingPublisherId = null;
    public string $pubName = '';
    public string $pubContact = '';
    public string $pubEmail = '';
    public string $pubPhone = '';
    public string $pubAddress = '';
    public string $pubWebsite = '';
    public bool $pubActive = true;

    // Import Modal
    public bool $showImportModal = false;
    public $importFile = null;
    public ?array $importSummary = null;

    public function selectTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->search = '';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // --- Category methods ---
    public function openCategoryModal(?int $id = null): void
    {
        $this->resetErrorBag();
        if ($id) {
            $cat = BookCategory::findOrFail($id);
            $this->editingCategoryId = $cat->id;
            $this->catName = $cat->name;
            $this->catCode = $cat->code;
            $this->catDdc = $cat->ddc_classification ?? '';
            $this->catDescription = $cat->description ?? '';
            $this->catActive = (bool) ($cat->is_active ?? true);
        } else {
            $this->editingCategoryId = null;
            $this->catName = '';
            $this->catCode = '';
            $this->catDdc = '';
            $this->catDescription = '';
            $this->catActive = true;
        }
        $this->showCategoryModal = true;
    }

    public function saveCategory(): void
    {
        $this->validate([
            'catName' => 'required|string|max:255',
            'catCode' => 'required|string|max:50|unique:book_categories,code,'.$this->editingCategoryId,
        ]);

        $payload = [
            'name' => $this->catName,
            'slug' => Str::slug($this->catName),
            'code' => strtoupper($this->catCode),
            'ddc_classification' => $this->catDdc ?: null,
            'description' => $this->catDescription ?: null,
            'is_active' => $this->catActive,
            'status' => $this->catActive ? 'active' : 'inactive',
        ];

        if ($this->editingCategoryId) {
            $cat = BookCategory::findOrFail($this->editingCategoryId);
            $cat->update($payload);
            Toast::dispatch($this, 'success', __('Category updated successfully.'));
        } else {
            BookCategory::create($payload);
            Toast::dispatch($this, 'success', __('Category created successfully.'));
        }

        $this->showCategoryModal = false;
    }

    public function deleteCategory(int $id): void
    {
        $cat = BookCategory::withCount('books')->findOrFail($id);
        if ($cat->books_count > 0) {
            Toast::dispatch($this, 'error', __('Cannot delete category because it has :count linked books.', ['count' => $cat->books_count]));
            return;
        }

        $cat->delete();
        Toast::dispatch($this, 'info', __('Category deleted.'));
    }

    // --- Author methods ---
    public function openAuthorModal(?int $id = null): void
    {
        $this->resetErrorBag();
        if ($id) {
            $author = BookAuthor::findOrFail($id);
            $this->editingAuthorId = $author->id;
            $this->authorName = $author->name;
            $this->authorBio = $author->biography ?? '';
            $this->authorEmail = $author->email ?? '';
            $this->authorNationality = $author->nationality ?? $author->country ?? '';
            $this->authorWebsite = $author->website ?? '';
            $this->authorActive = (bool) ($author->is_active ?? true);
        } else {
            $this->editingAuthorId = null;
            $this->authorName = '';
            $this->authorBio = '';
            $this->authorEmail = '';
            $this->authorNationality = 'Indian';
            $this->authorWebsite = '';
            $this->authorActive = true;
        }
        $this->showAuthorModal = true;
    }

    public function saveAuthor(): void
    {
        $this->validate([
            'authorName' => 'required|string|max:255',
            'authorEmail' => 'nullable|email|max:255',
            'authorNationality' => 'nullable|string|max:100',
            'authorWebsite' => 'nullable|string|max:255',
        ]);

        $payload = [
            'name' => $this->authorName,
            'slug' => Str::slug($this->authorName),
            'biography' => $this->authorBio ?: null,
            'email' => $this->authorEmail ?: null,
            'country' => $this->authorNationality ?: null,
            'nationality' => $this->authorNationality ?: null,
            'website' => $this->authorWebsite ?: null,
            'is_active' => $this->authorActive,
            'status' => $this->authorActive ? 'active' : 'inactive',
        ];

        if ($this->editingAuthorId) {
            $author = BookAuthor::findOrFail($this->editingAuthorId);
            $author->update($payload);
            Toast::dispatch($this, 'success', __('Author updated successfully.'));
        } else {
            BookAuthor::create($payload);
            Toast::dispatch($this, 'success', __('Author created successfully.'));
        }

        $this->showAuthorModal = false;
    }

    public function deleteAuthor(int $id): void
    {
        $author = BookAuthor::withCount('books')->findOrFail($id);
        if ($author->books_count > 0) {
            Toast::dispatch($this, 'error', __('Cannot delete author because :count books are credited.', ['count' => $author->books_count]));
            return;
        }

        $author->delete();
        Toast::dispatch($this, 'info', __('Author deleted.'));
    }

    // --- Publisher methods ---
    public function openPublisherModal(?int $id = null): void
    {
        $this->resetErrorBag();
        if ($id) {
            $pub = BookPublisher::findOrFail($id);
            $this->editingPublisherId = $pub->id;
            $this->pubName = $pub->name;
            $this->pubContact = $pub->contact_person ?? '';
            $this->pubEmail = $pub->email ?? '';
            $this->pubPhone = $pub->phone ?? '';
            $this->pubAddress = $pub->address ?? '';
            $this->pubWebsite = $pub->website ?? '';
            $this->pubActive = (bool) ($pub->is_active ?? true);
        } else {
            $this->editingPublisherId = null;
            $this->pubName = '';
            $this->pubContact = '';
            $this->pubEmail = '';
            $this->pubPhone = '';
            $this->pubAddress = '';
            $this->pubWebsite = '';
            $this->pubActive = true;
        }
        $this->showPublisherModal = true;
    }

    public function savePublisher(): void
    {
        $this->validate([
            'pubName' => 'required|string|max:255',
            'pubEmail' => 'nullable|email|max:255',
            'pubPhone' => 'nullable|string|max:50',
            'pubWebsite' => 'nullable|string|max:255',
        ]);

        $payload = [
            'name' => $this->pubName,
            'slug' => Str::slug($this->pubName),
            'contact_person' => $this->pubContact ?: null,
            'email' => $this->pubEmail ?: null,
            'phone' => $this->pubPhone ?: null,
            'address' => $this->pubAddress ?: null,
            'website' => $this->pubWebsite ?: null,
            'is_active' => $this->pubActive,
            'status' => $this->pubActive ? 'active' : 'inactive',
        ];

        if ($this->editingPublisherId) {
            $pub = BookPublisher::findOrFail($this->editingPublisherId);
            $pub->update($payload);
            Toast::dispatch($this, 'success', __('Publisher updated successfully.'));
        } else {
            BookPublisher::create($payload);
            Toast::dispatch($this, 'success', __('Publisher created successfully.'));
        }

        $this->showPublisherModal = false;
    }

    public function deletePublisher(int $id): void
    {
        $pub = BookPublisher::withCount('books')->findOrFail($id);
        if ($pub->books_count > 0) {
            Toast::dispatch($this, 'error', __('Cannot delete publisher with :count linked books.', ['count' => $pub->books_count]));
            return;
        }

        $pub->delete();
        Toast::dispatch($this, 'info', __('Publisher deleted.'));
    }

    #[Computed]
    public function categories()
    {
        return BookCategory::query()
            ->withCount('books')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('code', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function authors()
    {
        return BookAuthor::query()
            ->withCount('books')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function publishers()
    {
        return BookPublisher::query()
            ->withCount('books')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    public function export(string $format = 'csv')
    {
        if ($this->activeTab === 'categories') {
            $query = BookCategory::withCount('books')->orderBy('name');

            return TableExporter::download(
                $query,
                $format,
                'library-categories',
                ['Code', 'Name', 'DDC Classification', 'Description', 'Books Count', 'Status'],
                fn ($c) => [
                    $c->code,
                    $c->name,
                    $c->ddc_classification ?? 'N/A',
                    $c->description ?? 'N/A',
                    $c->books_count,
                    ucfirst($c->status),
                ],
                'SNTCSSC Library Categories Catalog'
            );
        }

        if ($this->activeTab === 'authors') {
            $query = BookAuthor::withCount('books')->orderBy('name');

            return TableExporter::download(
                $query,
                $format,
                'library-authors',
                ['Name', 'Country', 'Email', 'Website', 'Biography', 'Titles Count', 'Status'],
                fn ($a) => [
                    $a->name,
                    $a->country ?? 'N/A',
                    $a->email ?? 'N/A',
                    $a->website ?? 'N/A',
                    $a->biography ?? 'N/A',
                    $a->books_count,
                    ucfirst($a->status),
                ],
                'SNTCSSC Library Authors Catalog'
            );
        }

        // publishers
        $query = BookPublisher::withCount('books')->orderBy('name');

        return TableExporter::download(
            $query,
            $format,
            'library-publishers',
            ['Name', 'Contact Person', 'Email', 'Phone', 'Address', 'Website', 'Titles Count', 'Status'],
            fn ($p) => [
                $p->name,
                $p->contact_person ?? 'N/A',
                $p->email ?? 'N/A',
                $p->phone ?? 'N/A',
                $p->address ?? 'N/A',
                $p->website ?? 'N/A',
                $p->books_count,
                ucfirst($p->status),
            ],
            'SNTCSSC Library Publishers Catalog'
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
        if ($this->activeTab === 'categories') {
            return LibraryImportExportService::sampleCategoriesCsv();
        }

        if ($this->activeTab === 'authors') {
            return LibraryImportExportService::sampleAuthorsCsv();
        }

        return LibraryImportExportService::samplePublishersCsv();
    }

    public function submitImport(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();

            if ($this->activeTab === 'categories') {
                $result = LibraryImportExportService::importCategories($path);
            } elseif ($this->activeTab === 'authors') {
                $result = LibraryImportExportService::importAuthors($path);
            } else {
                $result = LibraryImportExportService::importPublishers($path);
            }

            $this->importSummary = $result;

            Toast::dispatch($this, 'success', __('Import completed: :imported records imported.', ['imported' => $result['imported']]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :err', ['err' => $e->getMessage()]));
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-teal-500/10 text-teal-600 dark:text-teal-400">
                    <x-icon name="folder-tree" class="w-6 h-6" />
                </span>
                {{ __('Library Master Data Catalog') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Maintain taxonomy categories, classification codes, authors roster, and publishing houses.') }}
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
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
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            <!-- Import Button -->
            <x-ui.button variant="outline" size="sm" wire:click="openImportModal" class="h-9 gap-1.5 border-teal-200 dark:border-teal-800/60 bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 hover:bg-teal-100">
                <x-icon name="upload" class="h-3.5 w-3.5 text-teal-600 dark:text-teal-400"/>
                {{ __('Import :type', ['type' => ucfirst($activeTab)]) }}
            </x-ui.button>

            @if($activeTab === 'categories')
                <button
                    type="button"
                    wire:click="openCategoryModal()"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl bg-teal-600 hover:bg-teal-700 text-white shadow-sm transition"
                >
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('New Category') }}
                </button>
            @elseif($activeTab === 'authors')
                <button
                    type="button"
                    wire:click="openAuthorModal()"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl bg-teal-600 hover:bg-teal-700 text-white shadow-sm transition"
                >
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('New Author') }}
                </button>
            @elseif($activeTab === 'publishers')
                <button
                    type="button"
                    wire:click="openPublisherModal()"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl bg-teal-600 hover:bg-teal-700 text-white shadow-sm transition"
                >
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('New Publisher') }}
                </button>
            @endif
        </div>
    </div>

    <!-- Tabs & Search -->
    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-2 w-full sm:w-auto">
            <button
                type="button"
                wire:click="selectTab('categories')"
                class="px-4 py-2 text-xs font-semibold rounded-lg transition {{ $activeTab === 'categories' ? 'bg-teal-600 text-white shadow-xs' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200' }}"
            >
                {{ __('Categories & Classification') }}
            </button>
            <button
                type="button"
                wire:click="selectTab('authors')"
                class="px-4 py-2 text-xs font-semibold rounded-lg transition {{ $activeTab === 'authors' ? 'bg-teal-600 text-white shadow-xs' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200' }}"
            >
                {{ __('Authors Directory') }}
            </button>
            <button
                type="button"
                wire:click="selectTab('publishers')"
                class="px-4 py-2 text-xs font-semibold rounded-lg transition {{ $activeTab === 'publishers' ? 'bg-teal-600 text-white shadow-xs' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200' }}"
            >
                {{ __('Publishing Houses') }}
            </button>
        </div>

        <div class="relative w-full sm:w-72">
            <x-icon name="magnifying-glass" class="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search records...') }}"
                class="w-full pl-9 pr-4 py-1.5 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-teal-500"
            />
        </div>
    </div>

    <!-- Active Tab Content -->
    @if($activeTab === 'categories')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3">{{ __('Code') }}</th>
                        <th class="px-4 py-3">{{ __('Category Name') }}</th>
                        <th class="px-4 py-3">{{ __('DDC Classification') }}</th>
                        <th class="px-4 py-3">{{ __('Linked Books') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-zinc-700 dark:text-zinc-300">
                    @forelse($this->categories as $c)
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-teal-600 dark:text-teal-400">{{ $c->code }}</td>
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $c->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ $c->ddc_classification ?: 'General' }}</td>
                            <td class="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{{ $c->books_count }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-2xs font-semibold {{ $c->is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-500' }}">
                                    {{ $c->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" wire:click="openCategoryModal({{ $c->id }})" class="p-1 text-zinc-500 hover:text-zinc-700 rounded">
                                        <x-icon name="pencil" class="w-4 h-4" />
                                    </button>
                                    <button type="button" wire:click="deleteCategory({{ $c->id }})" wire:confirm="Are you sure?" class="p-1 text-rose-500 hover:text-rose-700 rounded">
                                        <x-icon name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-xs text-zinc-400">{{ __('No categories found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($this->categories->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">{{ $this->categories->links() }}</div>
            @endif
        </div>
    @elseif($activeTab === 'authors')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3">{{ __('Author Name') }}</th>
                        <th class="px-4 py-3">{{ __('Country / Nationality') }}</th>
                        <th class="px-4 py-3">{{ __('Contact Email') }}</th>
                        <th class="px-4 py-3">{{ __('Website') }}</th>
                        <th class="px-4 py-3">{{ __('Catalog Titles') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-zinc-700 dark:text-zinc-300">
                    @forelse($this->authors as $a)
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $a->name }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500">{{ $a->nationality ?: ($a->country ?: 'Indian') }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500">{{ $a->email ?: 'N/A' }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500">
                                @if($a->website)
                                    <a href="{{ Str::startsWith($a->website, ['http://', 'https://']) ? $a->website : 'https://'.$a->website }}" target="_blank" rel="noopener" class="text-teal-600 hover:underline flex items-center gap-1">
                                        <x-icon name="globe-alt" class="w-3.5 h-3.5" />
                                        <span class="truncate max-w-[120px]">{{ parse_url($a->website, PHP_URL_HOST) ?: $a->website }}</span>
                                    </a>
                                @else
                                    <span class="text-zinc-400">N/A</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{{ $a->books_count }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-2xs font-semibold {{ $a->is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-500' }}">
                                    {{ $a->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" wire:click="openAuthorModal({{ $a->id }})" class="p-1 text-zinc-500 hover:text-zinc-700 rounded" title="{{ __('Edit Author') }}">
                                        <x-icon name="pencil" class="w-4 h-4" />
                                    </button>
                                    <button type="button" wire:click="deleteAuthor({{ $a->id }})" wire:confirm="Are you sure?" class="p-1 text-rose-500 hover:text-rose-700 rounded" title="{{ __('Delete Author') }}">
                                        <x-icon name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-xs text-zinc-400">{{ __('No authors found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($this->authors->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">{{ $this->authors->links() }}</div>
            @endif
        </div>
    @elseif($activeTab === 'publishers')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3">{{ __('Publisher Name') }}</th>
                        <th class="px-4 py-3">{{ __('Contact Person') }}</th>
                        <th class="px-4 py-3">{{ __('Email & Phone') }}</th>
                        <th class="px-4 py-3">{{ __('Website & Address') }}</th>
                        <th class="px-4 py-3">{{ __('Catalog Titles') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-zinc-700 dark:text-zinc-300">
                    @forelse($this->publishers as $p)
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $p->name }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500">{{ $p->contact_person ?: 'N/A' }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500">
                                <div>{{ $p->email ?: 'N/A' }}</div>
                                @if($p->phone)
                                    <div class="text-zinc-400 text-[11px]">{{ $p->phone }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-500">
                                @if($p->website)
                                    <a href="{{ Str::startsWith($p->website, ['http://', 'https://']) ? $p->website : 'https://'.$p->website }}" target="_blank" rel="noopener" class="text-teal-600 hover:underline flex items-center gap-1 mb-0.5">
                                        <x-icon name="globe-alt" class="w-3.5 h-3.5" />
                                        <span class="truncate max-w-[120px]">{{ parse_url($p->website, PHP_URL_HOST) ?: $p->website }}</span>
                                    </a>
                                @endif
                                @if($p->address)
                                    <div class="text-[11px] text-zinc-400 truncate max-w-[150px]" title="{{ $p->address }}">{{ $p->address }}</div>
                                @elseif(! $p->website)
                                    <span class="text-zinc-400">N/A</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-semibold text-zinc-900 dark:text-zinc-100">{{ $p->books_count }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-2xs font-semibold {{ $p->is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-500' }}">
                                    {{ $p->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" wire:click="openPublisherModal({{ $p->id }})" class="p-1 text-zinc-500 hover:text-zinc-700 rounded" title="{{ __('Edit Publisher') }}">
                                        <x-icon name="pencil" class="w-4 h-4" />
                                    </button>
                                    <button type="button" wire:click="deletePublisher({{ $p->id }})" wire:confirm="Are you sure?" class="p-1 text-rose-500 hover:text-rose-700 rounded" title="{{ __('Delete Publisher') }}">
                                        <x-icon name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-xs text-zinc-400">{{ __('No publishers found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($this->publishers->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">{{ $this->publishers->links() }}</div>
            @endif
        </div>
    @endif

    <!-- Category Modal -->
    @if($showCategoryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ $editingCategoryId ? __('Edit Category') : __('New Category') }}</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Name *') }}</label>
                        <input type="text" wire:model="catName" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" />
                        @error('catName') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Code *') }}</label>
                        <input type="text" wire:model="catCode" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 uppercase" placeholder="e.g. POL" />
                        @error('catCode') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('DDC Classification') }}</label>
                        <input type="text" wire:model="catDdc" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="e.g. 320" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Description') }}</label>
                        <textarea wire:model="catDescription" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800"></textarea>
                    </div>
                    <div class="pt-1">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="catActive" class="rounded border-zinc-300 dark:border-zinc-700 text-teal-600 focus:ring-teal-500" />
                            <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Category is Active & Available in Catalog') }}</span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button" wire:click="$set('showCategoryModal', false)" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-zinc-300 dark:border-zinc-700">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveCategory" class="px-4 py-1.5 text-xs font-semibold rounded-lg bg-teal-600 text-white">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Author Modal -->
    @if($showAuthorModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ $editingAuthorId ? __('Edit Author') : __('New Author') }}</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Author Name *') }}</label>
                        <input type="text" wire:model="authorName" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('e.g. Bipan Chandra') }}" />
                        @error('authorName') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1">{{ __('Country / Nationality') }}</label>
                            <input type="text" wire:model="authorNationality" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('e.g. Indian') }}" />
                            @error('authorNationality') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1">{{ __('Contact Email') }}</label>
                            <input type="email" wire:model="authorEmail" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('author@example.com') }}" />
                            @error('authorEmail') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Official Website / Profile URL') }}</label>
                        <input type="url" wire:model="authorWebsite" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('https://...') }}" />
                        @error('authorWebsite') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Biography / Background') }}</label>
                        <textarea wire:model="authorBio" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('Author biography, achievements or academic affiliation...') }}"></textarea>
                    </div>
                    <div class="pt-1">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="authorActive" class="rounded border-zinc-300 dark:border-zinc-700 text-teal-600 focus:ring-teal-500" />
                            <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Author is Active & Creditable in Catalog') }}</span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button" wire:click="$set('showAuthorModal', false)" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-zinc-300 dark:border-zinc-700">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="saveAuthor" class="px-4 py-1.5 text-xs font-semibold rounded-lg bg-teal-600 text-white">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Publisher Modal -->
    @if($showPublisherModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ $editingPublisherId ? __('Edit Publisher') : __('New Publisher') }}</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Publisher Name *') }}</label>
                        <input type="text" wire:model="pubName" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('e.g. Oxford University Press') }}" />
                        @error('pubName') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1">{{ __('Contact Person') }}</label>
                            <input type="text" wire:model="pubContact" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('Representative name') }}" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1">{{ __('Email') }}</label>
                            <input type="email" wire:model="pubEmail" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('publisher@example.com') }}" />
                            @error('pubEmail') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1">{{ __('Phone') }}</label>
                            <input type="text" wire:model="pubPhone" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('+91 98765 43210') }}" />
                            @error('pubPhone') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1">{{ __('Website') }}</label>
                            <input type="url" wire:model="pubWebsite" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('https://...') }}" />
                            @error('pubWebsite') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">{{ __('Postal / Office Address') }}</label>
                        <textarea wire:model="pubAddress" rows="2" class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800" placeholder="{{ __('Headquarters or dispatch address...') }}"></textarea>
                    </div>
                    <div class="pt-1">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="pubActive" class="rounded border-zinc-300 dark:border-zinc-700 text-teal-600 focus:ring-teal-500" />
                            <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Publisher is Active & Available in Catalog') }}</span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button" wire:click="$set('showPublisherModal', false)" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-zinc-300 dark:border-zinc-700">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="savePublisher" class="px-4 py-1.5 text-xs font-semibold rounded-lg bg-teal-600 text-white">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Import Modal -->
    @if($showImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="upload" class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                        {{ __('Import :type (CSV / Excel)', ['type' => ucfirst($activeTab)]) }}
                    </h3>
                    <button type="button" wire:click="$set('showImportModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/60 rounded-xl border border-zinc-200 dark:border-zinc-700/60 text-xs text-zinc-600 dark:text-zinc-300 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Need a starting template?') }}</span>
                        <button
                            type="button"
                            wire:click="downloadTemplate"
                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 hover:bg-teal-100 dark:hover:bg-teal-900/60 border border-teal-200 dark:border-teal-800 transition"
                        >
                            <x-icon name="download" class="w-3.5 h-3.5" />
                            {{ __('Download Sample CSV') }}
                        </button>
                    </div>
                    <div class="font-mono text-[11px] text-zinc-700 dark:text-zinc-300">
                        @if($activeTab === 'categories')
                            {{ __('Headers: Code, Name, DDC_Classification, Description, Status') }}
                        @elseif($activeTab === 'authors')
                            {{ __('Headers: Name, Biography, Country, Email, Website, Status') }}
                        @else
                            {{ __('Headers: Name, Contact_Person, Email, Phone, Address, Website, Status') }}
                        @endif
                    </div>
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
                                {{ __('Successfully imported: :count', ['count' => $importSummary['imported']]) }}
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

