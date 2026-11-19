<?php

use App\Concerns\RenamesUploads;
use App\Exports\TableExport;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Services\Document\DocumentService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Layout('layouts.app')] #[Title('Soft Copies Documents Management')] class extends Component {
    use RenamesUploads, WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'dept')]
    public string $departmentFilter = '';

    #[Url(as: 'type')]
    public string $fileTypeFilter = '';

    #[Url(as: 'access')]
    public string $accessFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $sortField = 'id';

    public string $sortDirection = 'desc';

    public int $perPage = 15;

    public string $activeTab = 'all';

    // Create Modal State
    public array $createForm = [
        'title' => '',
        'purpose' => '',
        'document_category_id' => '',
        'department' => '',
        'tags' => '',
        'access_level' => 'internal',
        'status' => 'published',
        'description' => '',
        'notes' => '',
        'remarks' => '',
        'version_number' => '1.0',
        'version_title' => 'Initial Approved Release',
        'change_summary' => 'Initial document creation and file upload',
        'effective_date' => '',
    ];

    public $newDocumentFile = null;

    // Edit Metadata Modal State
    public ?int $editingDocumentId = null;

    public array $editForm = [
        'title' => '',
        'purpose' => '',
        'document_category_id' => '',
        'department' => '',
        'tags' => '',
        'access_level' => 'internal',
        'status' => 'published',
        'description' => '',
        'notes' => '',
        'remarks' => '',
    ];

    // Upload Revision Modal State
    public ?int $revisingDocumentId = null;

    public array $revisionForm = [
        'version_number' => '',
        'version_title' => '',
        'change_summary' => '',
        'change_description' => '',
        'remarks' => '',
        'effective_date' => '',
    ];

    public $revisedFile = null;

    // Version History Modal State
    public ?int $historyDocumentId = null;

    // Document Details Modal State
    public ?int $detailsDocumentId = null;

    // Bulk Import Modal State
    public $importFile = null;

    public array $importErrors = [];

    // Delete Confirmation Modal State
    public ?int $deleteDocumentId = null;

    public string $deleteDocumentTitle = '';

    // Category Management Modal State
    public array $categoryForm = [
        'name' => '',
        'description' => '',
        'icon' => 'folder',
        'color' => 'blue',
    ];

    public function mount(): void
    {
        $this->createForm['effective_date'] = now()->toDateString();
        $this->revisionForm['effective_date'] = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDepartmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFileTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAccessFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->fileTypeFilter = match ($tab) {
            'pdf' => 'pdf',
            'word' => 'docx',
            'excel' => 'xlsx',
            'ppt' => 'pptx',
            'image' => 'png',
            'archive' => 'zip',
            default => '',
        };
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
        $this->search = '';
        $this->categoryFilter = '';
        $this->departmentFilter = '';
        $this->fileTypeFilter = '';
        $this->accessFilter = '';
        $this->statusFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->activeTab = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function statistics(): array
    {
        return DocumentService::getStatistics();
    }

    #[Computed]
    public function categories(): array
    {
        return DocumentCategory::active()->get()->all();
    }

    #[Computed]
    public function departments(): array
    {
        return [
            'Academics',
            'Administration',
            'Examinations',
            'Finance & Accounts',
            'Library',
            'Student Affairs',
            'IT & Media',
            'Hostel Administration',
            'General',
        ];
    }

    #[Computed]
    public function documents()
    {
        $query = Document::query()
            ->with(['currentVersion', 'category', 'creator'])
            ->withCount('versions');

        if (! empty($this->search)) {
            $query->search($this->search);
        }

        if (! empty($this->categoryFilter)) {
            $query->category($this->categoryFilter);
        }

        if (! empty($this->departmentFilter)) {
            $query->department($this->departmentFilter);
        }

        if (! empty($this->fileTypeFilter)) {
            $query->fileType($this->fileTypeFilter);
        }

        if (! empty($this->accessFilter)) {
            $query->accessLevel($this->accessFilter);
        }

        if (! empty($this->statusFilter)) {
            $query->status($this->statusFilter);
        }

        if (! empty($this->dateFrom)) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if (! empty($this->dateTo)) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $allowedSorts = ['id', 'document_number', 'title', 'total_versions_count', 'total_downloads_count', 'created_at', 'updated_at'];
        $field = in_array($this->sortField, $allowedSorts, true) ? $this->sortField : 'id';

        return $query->orderBy($field, $this->sortDirection)->paginate($this->perPage);
    }

    #[Computed]
    public function historyDocument(): ?Document
    {
        if (! $this->historyDocumentId) {
            return null;
        }

        return Document::with([
            'category',
            'creator',
            'currentVersion',
            'versions' => fn ($q) => $q->orderBy('revision_sequence', 'desc')->with(['uploader', 'creator']),
        ])->find($this->historyDocumentId);
    }

    #[Computed]
    public function detailsDocument(): ?Document
    {
        if (! $this->detailsDocumentId) {
            return null;
        }

        return Document::with([
            'category',
            'creator',
            'currentVersion',
            'versions' => fn ($q) => $q->orderBy('revision_sequence', 'desc')->with('uploader'),
        ])->find($this->detailsDocumentId);
    }

    #[Computed]
    public function revisingDocument(): ?Document
    {
        if (! $this->revisingDocumentId) {
            return null;
        }

        return Document::with(['currentVersion', 'category'])->find($this->revisingDocumentId);
    }

    // --- Modal Openers & Actions ---

    public function openCreateModal(): void
    {
        $this->resetErrorBag();
        $this->newDocumentFile = null;
        $this->createForm = [
            'title' => '',
            'purpose' => '',
            'document_category_id' => ! empty($this->categoryFilter) ? $this->categoryFilter : '',
            'department' => ! empty($this->departmentFilter) ? $this->departmentFilter : '',
            'tags' => '',
            'access_level' => 'internal',
            'status' => 'published',
            'description' => '',
            'notes' => '',
            'remarks' => '',
            'version_number' => '1.0',
            'version_title' => 'Initial Approved Release',
            'change_summary' => 'Initial document creation and file upload',
            'effective_date' => now()->toDateString(),
        ];
        $this->dispatch('modal-open', name: 'document-create-modal');
    }

    public function saveDocument(): void
    {
        $this->validate([
            'newDocumentFile' => [
                'required',
                'file',
                'max:'.DocumentService::MAX_FILE_SIZE_KB,
                'mimes:'.implode(',', DocumentService::ALLOWED_EXTENSIONS),
            ],
            'createForm.title' => 'required|string|max:255',
            'createForm.purpose' => 'nullable|string|max:255',
            'createForm.document_category_id' => 'nullable|exists:document_categories,id',
            'createForm.department' => 'nullable|string|max:100',
            'createForm.access_level' => 'required|in:public,internal,confidential,restricted',
            'createForm.status' => 'required|in:draft,published,archived',
            'createForm.version_number' => 'nullable|string|max:30',
            'createForm.version_title' => 'nullable|string|max:255',
            'createForm.change_summary' => 'nullable|string|max:500',
            'createForm.effective_date' => 'nullable|date',
        ]);

        try {
            $data = array_merge($this->createForm, [
                'document_category_id' => $this->createForm['document_category_id'] ?: null,
            ]);

            DocumentService::createDocument($data, $this->newDocumentFile, auth()->user());

            $this->dispatch('modal-close', name: 'document-create-modal');
            $this->newDocumentFile = null;
            $this->resetPage();
            Toast::dispatch($this, 'success', __('Document and initial version uploaded successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to upload document: ').$e->getMessage());
        }
    }

    public function editDocument(int $id): void
    {
        $doc = Document::find($id);
        if (! $doc) {
            Toast::dispatch($this, 'error', __('Document not found.'));

            return;
        }

        $this->resetErrorBag();
        $this->editingDocumentId = $doc->id;
        $this->editForm = [
            'title' => $doc->title,
            'purpose' => $doc->purpose ?? '',
            'document_category_id' => $doc->document_category_id ? (string) $doc->document_category_id : '',
            'department' => $doc->department ?? '',
            'tags' => is_array($doc->tags) ? implode(', ', $doc->tags) : '',
            'access_level' => $doc->access_level,
            'status' => $doc->status,
            'description' => $doc->description ?? '',
            'notes' => $doc->notes ?? '',
            'remarks' => $doc->remarks ?? '',
        ];

        $this->dispatch('modal-open', name: 'document-edit-modal');
    }

    public function updateDocument(): void
    {
        if (! $this->editingDocumentId) {
            return;
        }

        $doc = Document::find($this->editingDocumentId);
        if (! $doc) {
            Toast::dispatch($this, 'error', __('Document not found.'));

            return;
        }

        $this->validate([
            'editForm.title' => 'required|string|max:255',
            'editForm.purpose' => 'nullable|string|max:255',
            'editForm.document_category_id' => 'nullable|exists:document_categories,id',
            'editForm.department' => 'nullable|string|max:100',
            'editForm.access_level' => 'required|in:public,internal,confidential,restricted',
            'editForm.status' => 'required|in:draft,published,archived',
        ]);

        try {
            DocumentService::updateMetadata($doc, $this->editForm, auth()->user());

            $this->dispatch('modal-close', name: 'document-edit-modal');
            $this->editingDocumentId = null;
            Toast::dispatch($this, 'success', __('Document metadata updated successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update document: ').$e->getMessage());
        }
    }

    public function openRevisionModal(int $id): void
    {
        $doc = Document::with('currentVersion')->find($id);
        if (! $doc) {
            Toast::dispatch($this, 'error', __('Document not found.'));

            return;
        }

        $this->resetErrorBag();
        $this->revisingDocumentId = $doc->id;
        $this->revisedFile = null;

        $currentVer = $doc->currentVersion?->version_number ? str_replace('v', '', $doc->currentVersion->version_number) : '1.0';
        $nextVer = is_numeric($currentVer) ? number_format((float) $currentVer + 0.1, 1) : 'v'.($doc->versions()->count() + 1).'.0';

        $this->revisionForm = [
            'version_number' => $nextVer,
            'version_title' => "Revision #".($doc->versions()->count() + 1),
            'change_summary' => '',
            'change_description' => '',
            'remarks' => '',
            'effective_date' => now()->toDateString(),
        ];

        $this->dispatch('modal-close', name: 'document-history-modal');
        $this->dispatch('modal-open', name: 'document-revision-modal');
    }

    public function saveRevision(): void
    {
        if (! $this->revisingDocumentId) {
            return;
        }

        $doc = Document::find($this->revisingDocumentId);
        if (! $doc) {
            Toast::dispatch($this, 'error', __('Document not found.'));

            return;
        }

        $this->validate([
            'revisedFile' => [
                'required',
                'file',
                'max:'.DocumentService::MAX_FILE_SIZE_KB,
                'mimes:'.implode(',', DocumentService::ALLOWED_EXTENSIONS),
            ],
            'revisionForm.version_number' => 'required|string|max:30',
            'revisionForm.version_title' => 'nullable|string|max:255',
            'revisionForm.change_summary' => 'required|string|max:500',
            'revisionForm.effective_date' => 'nullable|date',
        ]);

        try {
            $version = DocumentService::uploadRevision($doc, $this->revisionForm, $this->revisedFile, auth()->user());

            $this->dispatch('modal-close', name: 'document-revision-modal');
            $this->revisingDocumentId = null;
            $this->revisedFile = null;

            Toast::dispatch($this, 'success', __("Revised version {$version->version_number} uploaded successfully. Previous versions preserved."));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to upload revised version: ').$e->getMessage());
        }
    }

    public function openHistoryModal(int $id): void
    {
        $this->historyDocumentId = $id;
        $this->dispatch('modal-open', name: 'document-history-modal');
    }

    public function rollbackVersion(int $versionId): void
    {
        if (! $this->historyDocumentId) {
            return;
        }

        $doc = Document::find($this->historyDocumentId);
        $version = DocumentVersion::where('document_id', $this->historyDocumentId)->find($versionId);

        if (! $doc || ! $version) {
            Toast::dispatch($this, 'error', __('Version record not found.'));

            return;
        }

        try {
            DocumentService::setCurrentVersion($doc, $version, auth()->user());
            Toast::dispatch($this, 'success', __("Active version successfully rolled back to version {$version->version_number}."));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to switch version: ').$e->getMessage());
        }
    }

    public function viewDetails(int $id): void
    {
        $this->detailsDocumentId = $id;
        $this->dispatch('modal-open', name: 'document-details-modal');
    }

    public function confirmDelete(int $id): void
    {
        $doc = Document::find($id);
        if (! $doc) {
            Toast::dispatch($this, 'error', __('Document not found.'));

            return;
        }

        $this->deleteDocumentId = $doc->id;
        $this->deleteDocumentTitle = $doc->title;
        $this->dispatch('modal-open', name: 'document-delete-modal');
    }

    public function deleteDocument(): void
    {
        if (! $this->deleteDocumentId) {
            return;
        }

        $doc = Document::find($this->deleteDocumentId);
        if ($doc) {
            try {
                DocumentService::deleteDocument($doc, auth()->user());
                Toast::dispatch($this, 'success', __('Document soft-deleted successfully and archived.'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', __('Failed to delete document: ').$e->getMessage());
            }
        }

        $this->dispatch('modal-close', name: 'document-delete-modal');
        $this->deleteDocumentId = null;
        $this->deleteDocumentTitle = '';
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importErrors = [];
        $this->dispatch('modal-open', name: 'document-import-modal');
    }

    public function importDocuments(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:xlsx,csv,xls|max:10240',
        ]);

        try {
            $result = DocumentService::importFromSpreadsheet($this->importFile, auth()->user());
            $this->importErrors = $result['errors'];

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __("Successfully imported {$result['imported']} documents."));
                if (empty($result['errors'])) {
                    $this->dispatch('modal-close', name: 'document-import-modal');
                    $this->importFile = null;
                }
            } else {
                Toast::dispatch($this, 'warning', __('No records were imported. Please review the errors.'));
            }
        } catch (\Throwable $e) {
            $this->importErrors = [$e->getMessage()];
            Toast::dispatch($this, 'error', __('Import failed: ').$e->getMessage());
        }
    }

    public function openCategoryModal(): void
    {
        $this->categoryForm = [
            'name' => '',
            'description' => '',
            'icon' => 'folder',
            'color' => 'blue',
        ];
        $this->dispatch('modal-open', name: 'document-category-modal');
    }

    public function saveCategory(): void
    {
        $this->validate([
            'categoryForm.name' => 'required|string|max:150|unique:document_categories,name',
            'categoryForm.description' => 'nullable|string|max:500',
            'categoryForm.icon' => 'required|string|max:50',
            'categoryForm.color' => 'required|string|max:50',
        ]);

        try {
            DocumentCategory::create([
                'name' => trim($this->categoryForm['name']),
                'slug' => \Illuminate\Support\Str::slug($this->categoryForm['name']),
                'description' => trim($this->categoryForm['description'] ?? ''),
                'icon' => $this->categoryForm['icon'],
                'color' => $this->categoryForm['color'],
                'created_by' => auth()->id(),
            ]);

            $this->dispatch('modal-close', name: 'document-category-modal');
            Toast::dispatch($this, 'success', __('Document category created successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to create category: ').$e->getMessage());
        }
    }

    // --- Exports ---

    public function exportExcel(): BinaryFileResponse
    {
        $query = Document::with(['category', 'currentVersion', 'creator'])
            ->orderBy('id', 'desc');

        if (! empty($this->search)) {
            $query->search($this->search);
        }
        if (! empty($this->categoryFilter)) {
            $query->category($this->categoryFilter);
        }
        if (! empty($this->departmentFilter)) {
            $query->department($this->departmentFilter);
        }
        if (! empty($this->fileTypeFilter)) {
            $query->fileType($this->fileTypeFilter);
        }
        if (! empty($this->accessFilter)) {
            $query->accessLevel($this->accessFilter);
        }
        if (! empty($this->statusFilter)) {
            $query->status($this->statusFilter);
        }

        $headings = [
            'ID',
            'Document Number',
            'Title',
            'Purpose',
            'Category',
            'Department',
            'Access Level',
            'Status',
            'Active Version',
            'Original File Name',
            'File Type',
            'File Size',
            'Total Revisions',
            'Downloads',
            'Created Date',
            'Last Updated',
        ];

        $mapper = function (Document $doc) {
            $ver = $doc->currentVersion;

            return [
                $doc->id,
                $doc->document_number,
                $doc->title,
                $doc->purpose ?? 'N/A',
                $doc->category?->name ?? 'Uncategorized',
                $doc->department ?? 'General',
                strtoupper($doc->access_level),
                strtoupper($doc->status),
                $ver ? 'v'.$ver->version_number : 'N/A',
                $ver?->original_file_name ?? 'N/A',
                $ver ? strtoupper($ver->file_type) : 'N/A',
                $ver?->formatted_file_size ?? '0 B',
                $doc->total_versions_count,
                $doc->total_downloads_count,
                $doc->created_at?->format('Y-m-d H:i') ?? 'N/A',
                $doc->updated_at?->format('Y-m-d H:i') ?? 'N/A',
            ];
        };

        return (new TableExport($query, $headings, $mapper))
            ->download('SNTCSSC_Documents_Register_'.date('Ymd_His').'.xlsx');
    }

    public function exportCsv(): BinaryFileResponse
    {
        $query = Document::with(['category', 'currentVersion', 'creator'])
            ->orderBy('id', 'desc');

        if (! empty($this->search)) {
            $query->search($this->search);
        }
        if (! empty($this->categoryFilter)) {
            $query->category($this->categoryFilter);
        }
        if (! empty($this->departmentFilter)) {
            $query->department($this->departmentFilter);
        }
        if (! empty($this->fileTypeFilter)) {
            $query->fileType($this->fileTypeFilter);
        }

        $headings = [
            'Document Number',
            'Title',
            'Purpose',
            'Category',
            'Department',
            'Access Level',
            'Status',
            'Active Version',
            'File Name',
            'File Type',
            'File Size',
            'Revisions',
            'Downloads',
            'Created At',
        ];

        $mapper = function (Document $doc) {
            $ver = $doc->currentVersion;

            return [
                $doc->document_number,
                $doc->title,
                $doc->purpose ?? '',
                $doc->category?->name ?? '',
                $doc->department ?? '',
                $doc->access_level,
                $doc->status,
                $ver?->version_number ?? '',
                $ver?->original_file_name ?? '',
                $ver?->file_type ?? '',
                $ver?->formatted_file_size ?? '',
                $doc->total_versions_count,
                $doc->total_downloads_count,
                $doc->created_at?->toDateTimeString() ?? '',
            ];
        };

        return (new TableExport($query, $headings, $mapper))
            ->download('SNTCSSC_Documents_Register_'.date('Ymd_His').'.csv');
    }
}; ?>

<div class="space-y-6">
    {{-- Header Banner & Page Breadcrumb --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ route('dashboard', ['current_team' => auth()->user()?->currentTeam?->slug ?? 'default']) }}" class="hover:text-foreground transition-colors">{{ __('Dashboard') }}</a>
                <span>/</span>
                <span class="text-foreground font-medium">{{ __('Document Management') }}</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="folder-archive" class="h-7 w-7 text-primary" />
                {{ __('Soft Copies Documents & Version Archive') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-0.5">
                {{ __('Enterprise multi-format institutional repository (PDF, Word, Excel, PPT, Images, Zip) with complete historical revision preservation.') }}
            </p>
        </div>

        {{-- Action Buttons --}}
        <div class="flex flex-wrap items-center gap-2">
            @can('documents.create')
                <button
                    wire:click="openCreateModal"
                    type="button"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs transition-colors cursor-pointer"
                >
                    <x-icon name="upload" class="h-4 w-4" />
                    <span>{{ __('Upload Document') }}</span>
                </button>
            @endcan

            @can('documents.import')
                <button
                    wire:click="openImportModal"
                    type="button"
                    class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium rounded-lg bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-border shadow-xs transition-colors cursor-pointer"
                    title="{{ __('Bulk Import Documents from Excel/CSV') }}"
                >
                    <x-icon name="file-spreadsheet" class="h-4 w-4" />
                    <span class="hidden md:inline">{{ __('Bulk Import') }}</span>
                </button>
            @endcan

            @can('documents.export')
                {{-- Export Dropdown --}}
                <div x-data="{ open: false }" class="relative">
                    <button
                        @click="open = !open"
                        @click.outside="open = false"
                        type="button"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-lg bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-border shadow-xs transition-colors cursor-pointer"
                    >
                        <x-icon name="download" class="h-4 w-4" />
                        <span>{{ __('Export') }}</span>
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-muted-foreground" />
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition
                        class="absolute right-0 mt-2 w-48 rounded-lg border border-border bg-card shadow-lg py-1 z-30"
                    >
                        <button
                            wire:click="exportExcel"
                            @click="open = false"
                            class="w-full text-left px-4 py-2 text-xs flex items-center gap-2 text-foreground hover:bg-secondary transition-colors"
                        >
                            <x-icon name="file-spreadsheet" class="h-4 w-4 text-emerald-600" />
                            <span>{{ __('Export as Excel (.xlsx)') }}</span>
                        </button>
                        <button
                            wire:click="exportCsv"
                            @click="open = false"
                            class="w-full text-left px-4 py-2 text-xs flex items-center gap-2 text-foreground hover:bg-secondary transition-colors"
                        >
                            <x-icon name="file-text" class="h-4 w-4 text-blue-600" />
                            <span>{{ __('Export as CSV (.csv)') }}</span>
                        </button>
                        <a
                            href="{{ route('documents.export.pdf', [
                                'q' => $search,
                                'category' => $categoryFilter,
                                'department' => $departmentFilter,
                                'type' => $fileTypeFilter,
                                'access' => $accessFilter,
                            ]) }}"
                            target="_blank"
                            @click="open = false"
                            class="w-full text-left px-4 py-2 text-xs flex items-center gap-2 text-foreground hover:bg-secondary transition-colors border-t border-border"
                        >
                            <x-icon name="file-text" class="h-4 w-4 text-red-600" />
                            <span>{{ __('Official PDF Register') }}</span>
                        </a>
                    </div>
                </div>
            @endcan

            <button
                wire:click="openCategoryModal"
                type="button"
                class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer border border-border"
                title="{{ __('Manage Categories Taxonomy') }}"
            >
                <x-icon name="folder-tree" class="h-4 w-4" />
                <span class="hidden lg:inline">{{ __('Taxonomy') }}</span>
            </button>
        </div>
    </div>

    {{-- Telemetry Statistics Cards --}}
    @php $stats = $this->statistics; @endphp
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-4.5 shadow-xs flex items-center gap-4">
            <div class="h-12 w-12 rounded-lg bg-blue-500/10 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                <x-icon name="file-text" class="h-6 w-6" />
            </div>
            <div>
                <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">{{ __('Total Documents') }}</p>
                <h3 class="text-2xl font-bold text-foreground mt-0.5">{{ number_format($stats['total_documents'] ?? 0) }}</h3>
                <p class="text-[11px] text-muted-foreground mt-0.5">Across {{ count($this->categories) }} categories</p>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4.5 shadow-xs flex items-center gap-4">
            <div class="h-12 w-12 rounded-lg bg-violet-500/10 dark:bg-violet-500/20 text-violet-600 dark:text-violet-400 flex items-center justify-center shrink-0">
                <x-icon name="history" class="h-6 w-6" />
            </div>
            <div>
                <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">{{ __('Revisions Preserved') }}</p>
                <h3 class="text-2xl font-bold text-foreground mt-0.5">{{ number_format($stats['total_versions'] ?? 0) }}</h3>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('100% historical archival') }}</p>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4.5 shadow-xs flex items-center gap-4">
            <div class="h-12 w-12 rounded-lg bg-emerald-500/10 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                <x-icon name="hard-drive" class="h-6 w-6" />
            </div>
            <div>
                <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">{{ __('Total Storage Used') }}</p>
                <h3 class="text-2xl font-bold text-foreground mt-0.5">{{ $stats['storage_formatted'] ?? '0 B' }}</h3>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ count($stats['file_types'] ?? []) }} file extensions</p>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4.5 shadow-xs flex items-center gap-4">
            <div class="h-12 w-12 rounded-lg bg-amber-500/10 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                <x-icon name="download" class="h-6 w-6" />
            </div>
            <div>
                <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">{{ __('Cumulative Downloads') }}</p>
                <h3 class="text-2xl font-bold text-foreground mt-0.5">{{ number_format($stats['total_downloads'] ?? 0) }}</h3>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('All revisions tracked') }}</p>
            </div>
        </div>
    </div>

    {{-- File Type Quick Tabs --}}
    <div class="flex items-center gap-1.5 overflow-x-auto pb-1 border-b border-border text-sm">
        <button
            wire:click="setTab('all')"
            class="px-3.5 py-1.5 rounded-lg font-medium transition-colors cursor-pointer {{ $activeTab === 'all' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            {{ __('All Files') }}
        </button>
        <button
            wire:click="setTab('pdf')"
            class="px-3.5 py-1.5 rounded-lg font-medium transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'pdf' ? 'bg-red-600 text-white shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="file-text" class="h-3.5 w-3.5" />
            <span>PDF</span>
        </button>
        <button
            wire:click="setTab('word')"
            class="px-3.5 py-1.5 rounded-lg font-medium transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'word' ? 'bg-blue-600 text-white shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="file-text" class="h-3.5 w-3.5" />
            <span>Word (.docx)</span>
        </button>
        <button
            wire:click="setTab('excel')"
            class="px-3.5 py-1.5 rounded-lg font-medium transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'excel' ? 'bg-emerald-600 text-white shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="file-spreadsheet" class="h-3.5 w-3.5" />
            <span>Excel (.xlsx, .csv)</span>
        </button>
        <button
            wire:click="setTab('ppt')"
            class="px-3.5 py-1.5 rounded-lg font-medium transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'ppt' ? 'bg-amber-600 text-white shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="file-bar-chart" class="h-3.5 w-3.5" />
            <span>PowerPoint</span>
        </button>
        <button
            wire:click="setTab('image')"
            class="px-3.5 py-1.5 rounded-lg font-medium transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'image' ? 'bg-purple-600 text-white shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="image" class="h-3.5 w-3.5" />
            <span>Images</span>
        </button>
        <button
            wire:click="setTab('archive')"
            class="px-3.5 py-1.5 rounded-lg font-medium transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'archive' ? 'bg-orange-600 text-white shadow-xs' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
        >
            <x-icon name="file-archive" class="h-3.5 w-3.5" />
            <span>Archives (.zip)</span>
        </button>
    </div>

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            {{-- Search Input --}}
            <div class="lg:col-span-2 relative">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Search document name, purpose, tags, doc#...') }}"
                    class="w-full pl-9 pr-8 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
                @if ($search)
                    <button
                        wire:click="$set('search', '')"
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer"
                    >
                        <x-icon name="x" class="h-3.5 w-3.5" />
                    </button>
                @endif
            </div>

            {{-- Category Filter --}}
            <div>
                <select
                    wire:model.live="categoryFilter"
                    class="w-full py-2 px-3 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Categories') }}</option>
                    @foreach ($this->categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Department Filter --}}
            <div>
                <select
                    wire:model.live="departmentFilter"
                    class="w-full py-2 px-3 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Departments') }}</option>
                    @foreach ($this->departments as $dept)
                        <option value="{{ $dept }}">{{ $dept }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Access Level Filter --}}
            <div>
                <select
                    wire:model.live="accessFilter"
                    class="w-full py-2 px-3 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Access Levels') }}</option>
                    <option value="public">{{ __('Public') }}</option>
                    <option value="internal">{{ __('Internal') }}</option>
                    <option value="confidential">{{ __('Confidential') }}</option>
                    <option value="restricted">{{ __('Restricted') }}</option>
                </select>
            </div>

            {{-- Status Filter --}}
            <div>
                <select
                    wire:model.live="statusFilter"
                    class="w-full py-2 px-3 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="published">{{ __('Published') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="archived">{{ __('Archived') }}</option>
                </select>
            </div>
        </div>

        {{-- Date Range & Clear Filters Row --}}
        <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-border/60 text-xs">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1.5 text-muted-foreground">
                    <span>{{ __('Created between:') }}</span>
                    <input
                        wire:model.live="dateFrom"
                        type="date"
                        class="px-2 py-1 rounded-md border border-border bg-background text-foreground text-xs"
                    />
                    <span>{{ __('to') }}</span>
                    <input
                        wire:model.live="dateTo"
                        type="date"
                        class="px-2 py-1 rounded-md border border-border bg-background text-foreground text-xs"
                    />
                </div>

                @if ($search || $categoryFilter || $departmentFilter || $fileTypeFilter || $accessFilter || $statusFilter || $dateFrom || $dateTo)
                    <button
                        wire:click="resetFilters"
                        class="text-xs text-primary hover:underline flex items-center gap-1 cursor-pointer"
                    >
                        <x-icon name="rotate-cw" class="h-3 w-3" />
                        <span>{{ __('Reset Filters') }}</span>
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-2 text-muted-foreground">
                <span>{{ __('Rows per page:') }}</span>
                <select wire:model.live="perPage" class="py-1 px-2 rounded-md border border-border bg-background text-foreground text-xs">
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </div>
        </div>
    </div>

    {{-- Documents Table --}}
    @php $docsList = $this->documents; @endphp
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="border-b border-border bg-secondary/50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <th class="py-3 px-4 w-12 text-center">#</th>
                        <th wire:click="sortBy('document_number')" class="py-3 px-4 cursor-pointer hover:text-foreground">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Document') }}</span>
                                @if ($sortField === 'document_number')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3.5 w-3.5" />
                                @endif
                            </div>
                        </th>
                        <th class="py-3 px-4">{{ __('Category & Dept') }}</th>
                        <th class="py-3 px-4 text-center">{{ __('Active Version') }}</th>
                        <th class="py-3 px-4 text-center">{{ __('File & Size') }}</th>
                        <th wire:click="sortBy('total_versions_count')" class="py-3 px-4 text-center cursor-pointer hover:text-foreground">
                            <div class="flex items-center justify-center gap-1">
                                <span>{{ __('Versions') }}</span>
                                @if ($sortField === 'total_versions_count')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3.5 w-3.5" />
                                @endif
                            </div>
                        </th>
                        <th wire:click="sortBy('total_downloads_count')" class="py-3 px-4 text-center cursor-pointer hover:text-foreground">
                            <div class="flex items-center justify-center gap-1">
                                <span>{{ __('Downloads') }}</span>
                                @if ($sortField === 'total_downloads_count')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3.5 w-3.5" />
                                @endif
                            </div>
                        </th>
                        <th wire:click="sortBy('created_at')" class="py-3 px-4 cursor-pointer hover:text-foreground">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Dates & Uploader') }}</span>
                                @if ($sortField === 'created_at')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="h-3.5 w-3.5" />
                                @endif
                            </div>
                        </th>
                        <th class="py-3 px-4 text-right pr-6">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($docsList as $doc)
                        @php
                            $curVer = $doc->currentVersion;
                            $badge = $curVer?->file_type_badge ?? [
                                'badge_bg' => 'bg-secondary border-border',
                                'badge_text' => 'text-foreground',
                                'icon' => 'file',
                                'label' => 'FILE',
                            ];
                        @endphp
                        <tr class="hover:bg-secondary/30 transition-colors">
                            <td class="py-3.5 px-4 text-center text-xs text-muted-foreground font-mono">
                                {{ $doc->id }}
                            </td>

                            {{-- Document Info --}}
                            <td class="py-3.5 px-4">
                                <div class="flex flex-col">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs font-bold text-primary bg-primary/10 px-2 py-0.5 rounded border border-primary/20">
                                            {{ $doc->document_number }}
                                        </span>
                                        @if ($doc->access_level !== 'internal')
                                            <span class="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded {{ $doc->access_level === 'public' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20' : ($doc->access_level === 'confidential' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20' : 'bg-red-500/10 text-red-700 dark:text-red-400 border border-red-500/20') }}">
                                                {{ $doc->access_level }}
                                            </span>
                                        @endif
                                        @if ($doc->status !== 'published')
                                            <span class="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border border-zinc-500/20">
                                                {{ $doc->status }}
                                            </span>
                                        @endif
                                    </div>

                                    <button
                                        wire:click="viewDetails({{ $doc->id }})"
                                        class="font-semibold text-foreground text-left mt-1 hover:text-primary transition-colors cursor-pointer line-clamp-1"
                                        title="{{ $doc->title }}"
                                    >
                                        {{ $doc->title }}
                                    </button>

                                    @if ($doc->purpose)
                                        <span class="text-xs text-muted-foreground line-clamp-1">
                                            <span class="font-medium text-foreground/80">{{ __('Purpose:') }}</span> {{ $doc->purpose }}
                                        </span>
                                    @endif

                                    @if (! empty($doc->tags))
                                        <div class="flex flex-wrap items-center gap-1 mt-1">
                                            @foreach (array_slice((array) $doc->tags, 0, 3) as $t)
                                                <span class="text-[10px] text-muted-foreground bg-secondary px-1.5 py-0.5 rounded border border-border">
                                                    #{{ $t }}
                                                </span>
                                            @endforeach
                                            @if (count((array) $doc->tags) > 3)
                                                <span class="text-[10px] text-muted-foreground">+{{ count((array) $doc->tags) - 3 }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </td>

                            {{-- Category & Dept --}}
                            <td class="py-3.5 px-4">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs font-medium text-foreground">
                                        {{ $doc->category?->name ?? __('Uncategorized') }}
                                    </span>
                                    <span class="text-[11px] text-muted-foreground flex items-center gap-1">
                                        <x-icon name="building-2" class="h-3 w-3 text-muted-foreground/80" />
                                        {{ $doc->department ?? __('General') }}
                                    </span>
                                </div>
                            </td>

                            {{-- Active Version --}}
                            <td class="py-3.5 px-4 text-center">
                                @if ($curVer)
                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-primary bg-primary/10 border border-primary/20 px-2 py-0.5 rounded-full">
                                        <span>v{{ $curVer->version_number }}</span>
                                    </span>
                                    @if ($curVer->version_title)
                                        <p class="text-[10px] text-muted-foreground mt-0.5 max-w-[130px] mx-auto truncate" title="{{ $curVer->version_title }}">
                                            {{ $curVer->version_title }}
                                        </p>
                                    @endif
                                @else
                                    <span class="text-xs text-muted-foreground">&mdash;</span>
                                @endif
                            </td>

                            {{-- File Type & Size --}}
                            <td class="py-3.5 px-4 text-center">
                                @if ($curVer)
                                    <div class="flex flex-col items-center">
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded border {{ $badge['badge_bg'] }} {{ $badge['badge_text'] }}">
                                            <x-icon name="{{ $badge['icon'] }}" class="h-3 w-3" />
                                            <span>{{ $badge['label'] }}</span>
                                        </span>
                                        <span class="text-[10px] text-muted-foreground font-mono mt-0.5">
                                            {{ $curVer->formatted_file_size }}
                                        </span>
                                    </div>
                                @else
                                    <span class="text-xs text-muted-foreground">&mdash;</span>
                                @endif
                            </td>

                            {{-- Versions count & History button --}}
                            <td class="py-3.5 px-4 text-center">
                                <button
                                    wire:click="openHistoryModal({{ $doc->id }})"
                                    type="button"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-secondary hover:bg-secondary/80 text-foreground border border-border cursor-pointer transition-colors"
                                    title="{{ __('View full version timeline and download past revisions') }}"
                                >
                                    <x-icon name="history" class="h-3 w-3 text-primary" />
                                    <span>{{ $doc->total_versions_count ?: $doc->versions_count }}</span>
                                </button>
                            </td>

                            {{-- Downloads --}}
                            <td class="py-3.5 px-4 text-center">
                                <span class="font-mono text-xs font-semibold text-emerald-600 dark:text-emerald-400 flex items-center justify-center gap-1">
                                    <x-icon name="download" class="h-3 w-3" />
                                    <span>{{ $doc->total_downloads_count }}</span>
                                </span>
                            </td>

                            {{-- Dates & Creator --}}
                            <td class="py-3.5 px-4">
                                <div class="flex flex-col text-xs">
                                    <span class="text-foreground font-medium">{{ $doc->created_at?->format('d M Y') }}</span>
                                    <span class="text-[11px] text-muted-foreground truncate max-w-[130px]">
                                        by {{ $doc->creator?->name ?? 'System' }}
                                    </span>
                                    @if ($doc->updated_at && $doc->updated_at->ne($doc->created_at))
                                        <span class="text-[10px] text-muted-foreground/80 mt-0.5">
                                            Rev: {{ $doc->updated_at->diffForHumans() }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            {{-- Actions Dropdown / Buttons --}}
                            <td class="py-3.5 px-4 text-right pr-6">
                                <div class="flex items-center justify-end gap-1.5">
                                    {{-- Quick Download Latest --}}
                                    @if ($curVer)
                                        <a
                                            href="{{ route('documents.download.version', $curVer) }}"
                                            class="p-1.5 rounded-md text-foreground hover:bg-primary hover:text-primary-foreground transition-colors"
                                            title="{{ __('Download active file: ') . $curVer->original_file_name }}"
                                        >
                                            <x-icon name="download" class="h-4 w-4" />
                                        </a>
                                    @endif

                                    {{-- Version History --}}
                                    <button
                                        wire:click="openHistoryModal({{ $doc->id }})"
                                        type="button"
                                        class="p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                        title="{{ __('Version History & Timeline') }}"
                                    >
                                        <x-icon name="history" class="h-4 w-4" />
                                    </button>

                                    {{-- Upload Revision --}}
                                    @can('documents.revise')
                                        <button
                                            wire:click="openRevisionModal({{ $doc->id }})"
                                            type="button"
                                            class="p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Upload Revised Version') }}"
                                        >
                                            <x-icon name="upload" class="h-4 w-4" />
                                        </button>
                                    @endcan

                                    {{-- Edit Metadata --}}
                                    @can('documents.edit')
                                        <button
                                            wire:click="editDocument({{ $doc->id }})"
                                            type="button"
                                            class="p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Edit Metadata') }}"
                                        >
                                            <x-icon name="edit" class="h-4 w-4" />
                                        </button>
                                    @endcan

                                    {{-- Soft Delete --}}
                                    @can('documents.delete')
                                        <button
                                            wire:click="confirmDelete({{ $doc->id }})"
                                            type="button"
                                            class="p-1.5 rounded-md text-red-500 hover:bg-red-500/10 transition-colors cursor-pointer"
                                            title="{{ __('Soft-Delete Document') }}"
                                        >
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-3">
                                    <div class="h-14 w-14 rounded-full bg-secondary flex items-center justify-center text-muted-foreground">
                                        <x-icon name="folder-open" class="h-7 w-7" />
                                    </div>
                                    <h4 class="text-base font-semibold text-foreground">{{ __('No Documents Found') }}</h4>
                                    <p class="text-sm text-muted-foreground max-w-sm">
                                        {{ __('No soft copy document files match the designated filter criteria. Try clearing filters or uploading a new document.') }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-2">
                                        @if ($search || $categoryFilter || $departmentFilter || $fileTypeFilter || $accessFilter || $statusFilter || $dateFrom || $dateTo)
                                            <button
                                                wire:click="resetFilters"
                                                class="px-3.5 py-1.5 text-xs font-medium rounded-lg bg-secondary text-foreground hover:bg-secondary/80 border border-border cursor-pointer transition-colors"
                                            >
                                                {{ __('Clear All Filters') }}
                                            </button>
                                        @endif
                                        @can('documents.create')
                                            <button
                                                wire:click="openCreateModal"
                                                class="px-3.5 py-1.5 text-xs font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs cursor-pointer transition-colors"
                                            >
                                                {{ __('Upload First Document') }}
                                            </button>
                                        @endcan
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($docsList->hasPages())
            <div class="p-4 border-t border-border">
                {{ $docsList->links() }}
            </div>
        @endif
    </div>

    {{-- ======================================================== --}}
    {{-- MODAL 1: Upload New Document with Initial File (v1.0)     --}}
    {{-- ======================================================== --}}
    <x-ui.modal name="document-create-modal" maxWidth="max-w-2xl" :title="__('Upload New Soft Copy Document')" :description="__('Register official institutional document with initial version (v1.0). Supported: PDF, Word, Excel, PPT, Images, Zip.')">
        <form wire:submit="saveDocument" class="space-y-4">
            {{-- File Upload Drop Zone --}}
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                    {{ __('Document File (PDF, DOCX, XLSX, PPTX, JPG, PNG, ZIP etc.)') }} <span class="text-red-500">*</span>
                </label>
                <div class="relative border-2 border-dashed border-border hover:border-primary rounded-xl p-5 text-center transition-colors bg-secondary/30">
                    <input
                        wire:model="newDocumentFile"
                        type="file"
                        id="newDocumentFileInput"
                        class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                    />
                    <div class="flex flex-col items-center justify-center gap-1.5 pointer-events-none">
                        <x-icon name="upload" class="h-8 w-8 text-primary" />
                        <span class="text-sm font-semibold text-foreground">
                            {{ $newDocumentFile ? $newDocumentFile->getClientOriginalName() : __('Click or drag document file here to upload') }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ $newDocumentFile ? round($newDocumentFile->getSize() / 1024, 1).' KB' : __('Maximum size: 50 MB per file') }}
                        </span>
                    </div>
                </div>
                <div wire:loading wire:target="newDocumentFile" class="text-xs text-primary font-medium mt-1 flex items-center gap-1">
                    <x-icon name="loader" class="h-3 w-3 animate-spin" />
                    <span>{{ __('Uploading and verifying file integrity...') }}</span>
                </div>
                @if ($newDocumentFile)
                    <div class="mt-2 flex items-center justify-between p-2.5 rounded-lg bg-primary/10 border border-primary/20 text-xs">
                        <div class="flex items-center gap-2 truncate">
                            <x-icon name="file-check" class="h-4 w-4 text-primary shrink-0" />
                            <span class="font-medium text-foreground truncate">{{ $newDocumentFile->getClientOriginalName() }}</span>
                            <span class="text-muted-foreground font-mono">({{ round($newDocumentFile->getSize() / 1024, 1) }} KB)</span>
                        </div>
                        <button
                            type="button"
                            wire:click="$set('newDocumentFile', null)"
                            class="text-red-500 hover:text-red-600 p-1 cursor-pointer transition-colors"
                            title="{{ __('Remove selected file') }}"
                        >
                            <x-icon name="x" class="h-4 w-4" />
                        </button>
                    </div>
                @endif
                @error('newDocumentFile') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {{-- Title --}}
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Document Title / Name') }} <span class="text-red-500">*</span>
                    </label>
                    <input
                        wire:model="createForm.title"
                        type="text"
                        placeholder="{{ __('e.g., Annual UPSC Mains Foundation Syllabus 2026-27') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    @error('createForm.title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Purpose --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Purpose / Objective') }}
                    </label>
                    <input
                        wire:model="createForm.purpose"
                        type="text"
                        placeholder="{{ __('e.g., Student Curriculum Guidance, Gazetted Order') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    @error('createForm.purpose') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Category --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Document Category') }}
                    </label>
                    <select
                        wire:model="createForm.document_category_id"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option value="">{{ __('-- Select Category --') }}</option>
                        @foreach ($this->categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('createForm.document_category_id') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Department --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Department') }}
                    </label>
                    <select
                        wire:model="createForm.department"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option value="">{{ __('-- Select Department --') }}</option>
                        @foreach ($this->departments as $d)
                            <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Access Level --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Access Level') }}
                    </label>
                    <select
                        wire:model="createForm.access_level"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option value="internal">{{ __('Internal (Staff & Enrolled Students)') }}</option>
                        <option value="public">{{ __('Public (All Portals & Unrestricted)') }}</option>
                        <option value="confidential">{{ __('Confidential (Administrators & Officers)') }}</option>
                        <option value="restricted">{{ __('Restricted (Authorized Personnel Only)') }}</option>
                    </select>
                </div>

                {{-- Tags --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Search Tags (Comma-Separated)') }}
                    </label>
                    <input
                        wire:model="createForm.tags"
                        type="text"
                        placeholder="{{ __('syllabus, upsc, mains, 2026') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                {{-- Initial Version Code & Title --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Initial Version Code') }}
                    </label>
                    <input
                        wire:model="createForm.version_number"
                        type="text"
                        placeholder="{{ __('1.0') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                {{-- Effective Date --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Effective Operational Date') }}
                    </label>
                    <input
                        wire:model="createForm.effective_date"
                        type="date"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                {{-- Description --}}
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Document Description / Synopsis') }}
                    </label>
                    <textarea
                        wire:model="createForm.description"
                        rows="2"
                        placeholder="{{ __('Detailed background and summary of this document...') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    ></textarea>
                </div>

                {{-- Notes / Remarks --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Internal Notes') }}
                    </label>
                    <input
                        wire:model="createForm.notes"
                        type="text"
                        placeholder="{{ __('Notes for internal administrative staff') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Approval Remarks') }}
                    </label>
                    <input
                        wire:model="createForm.remarks"
                        type="text"
                        placeholder="{{ __('Ratified by Academic Council') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <button
                    type="button"
                    x-on:click="$store.modals.close('document-create-modal')"
                    class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs cursor-pointer transition-colors flex items-center gap-1.5"
                >
                    <x-icon name="upload" class="h-4 w-4" />
                    <span>{{ __('Upload & Register Document') }}</span>
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- ======================================================== --}}
    {{-- MODAL 2: Upload Revised File Version (Version Control)   --}}
    {{-- ======================================================== --}}
    @php $revDoc = $this->revisingDocument; @endphp
    <x-ui.modal name="document-revision-modal" maxWidth="max-w-xl" :title="__('Upload Revised Document Version')" :description="__('Upload a revised file. The new version will become active, while all previous versions remain permanently preserved in historical revision history.')">
        @if ($revDoc)
            <div class="p-3 rounded-lg bg-secondary/50 border border-border text-xs mb-3 space-y-1">
                <div class="flex items-center justify-between">
                    <span class="font-bold text-foreground font-mono">{{ $revDoc->document_number }}</span>
                    <span class="text-primary font-bold">Current Version: v{{ $revDoc->currentVersion?->version_number ?? '1.0' }}</span>
                </div>
                <p class="font-medium text-foreground">{{ $revDoc->title }}</p>
                @if ($revDoc->currentVersion)
                    <p class="text-muted-foreground font-mono text-[11px]">
                        Active file: {{ $revDoc->currentVersion->original_file_name }} ({{ $revDoc->currentVersion->formatted_file_size }})
                    </p>
                @endif
            </div>
        @endif

        <form wire:submit="saveRevision" class="space-y-4">
            {{-- File Upload --}}
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                    {{ __('Revised Document File') }} <span class="text-red-500">*</span>
                </label>
                <div class="relative border-2 border-dashed border-border hover:border-primary rounded-xl p-5 text-center transition-colors bg-secondary/30">
                    <input
                        wire:model="revisedFile"
                        type="file"
                        class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                    />
                    <div class="flex flex-col items-center justify-center gap-1 pointer-events-none">
                        <x-icon name="upload" class="h-7 w-7 text-primary" />
                        <span class="text-sm font-semibold text-foreground">
                            {{ $revisedFile ? $revisedFile->getClientOriginalName() : __('Click or drag revised file here') }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ $revisedFile ? round($revisedFile->getSize() / 1024, 1).' KB' : __('PDF, Word, Excel, PPT, Image, Zip (up to 50 MB)') }}
                        </span>
                    </div>
                </div>
                <div wire:loading wire:target="revisedFile" class="text-xs text-primary font-medium mt-1 flex items-center gap-1">
                    <x-icon name="loader" class="h-3 w-3 animate-spin" />
                    <span>{{ __('Uploading file...') }}</span>
                </div>
                @if ($revisedFile)
                    <div class="mt-2 flex items-center justify-between p-2.5 rounded-lg bg-primary/10 border border-primary/20 text-xs">
                        <div class="flex items-center gap-2 truncate">
                            <x-icon name="file-check" class="h-4 w-4 text-primary shrink-0" />
                            <span class="font-medium text-foreground truncate">{{ $revisedFile->getClientOriginalName() }}</span>
                            <span class="text-muted-foreground font-mono">({{ round($revisedFile->getSize() / 1024, 1) }} KB)</span>
                        </div>
                        <button
                            type="button"
                            wire:click="$set('revisedFile', null)"
                            class="text-red-500 hover:text-red-600 p-1 cursor-pointer transition-colors"
                            title="{{ __('Remove selected file') }}"
                        >
                            <x-icon name="x" class="h-4 w-4" />
                        </button>
                    </div>
                @endif
                @error('revisedFile') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {{-- Revision Version Number --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('New Version Code') }} <span class="text-red-500">*</span>
                    </label>
                    <input
                        wire:model="revisionForm.version_number"
                        type="text"
                        placeholder="{{ __('e.g., 1.1 or 2.0') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    @error('revisionForm.version_number') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Version Title --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Revision Headline / Title') }}
                    </label>
                    <input
                        wire:model="revisionForm.version_title"
                        type="text"
                        placeholder="{{ __('e.g., Revised Examination Schedule') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                {{-- Change Summary (Required) --}}
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Change Summary / Revision Notes') }} <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        wire:model="revisionForm.change_summary"
                        rows="2"
                        placeholder="{{ __('Explain what was modified or revised in this version so it is visible in the audit trail...') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    ></textarea>
                    @error('revisionForm.change_summary') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Effective Date --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Effective From') }}
                    </label>
                    <input
                        wire:model="revisionForm.effective_date"
                        type="date"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                {{-- Remarks --}}
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Authority Remarks') }}
                    </label>
                    <input
                        wire:model="revisionForm.remarks"
                        type="text"
                        placeholder="{{ __('Approved by Directorate') }}"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <button
                    type="button"
                    x-on:click="$store.modals.close('document-revision-modal')"
                    class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs cursor-pointer transition-colors flex items-center gap-1.5"
                >
                    <x-icon name="upload" class="h-4 w-4" />
                    <span>{{ __('Save & Publish Revision') }}</span>
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- ======================================================== --}}
    {{-- MODAL 3: Version History Timeline & Archival Downloads   --}}
    {{-- ======================================================== --}}
    @php $histDoc = $this->historyDocument; @endphp
    <x-ui.modal name="document-history-modal" maxWidth="max-w-3xl" :title="__('Version History & Revision Timeline')" :description="__('Complete historical archive of every uploaded revision. Any past version file can be reviewed and downloaded anytime.')">
        @if ($histDoc)
            <div class="space-y-4">
                {{-- Document Header Info --}}
                <div class="p-4 rounded-xl bg-secondary/40 border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs font-bold text-primary bg-primary/10 px-2 py-0.5 rounded border border-primary/20">
                                {{ $histDoc->document_number }}
                            </span>
                            <span class="text-xs text-muted-foreground">{{ $histDoc->category?->name ?? 'Uncategorized' }}</span>
                        </div>
                        <h3 class="text-base font-bold text-foreground mt-1">{{ $histDoc->title }}</h3>
                        @if ($histDoc->purpose)
                            <p class="text-xs text-muted-foreground">{{ $histDoc->purpose }}</p>
                        @endif
                    </div>

                    @can('documents.revise')
                        <button
                            wire:click="openRevisionModal({{ $histDoc->id }})"
                            type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs cursor-pointer transition-colors shrink-0"
                        >
                            <x-icon name="upload" class="h-3.5 w-3.5" />
                            <span>{{ __('Upload New Revision') }}</span>
                        </button>
                    @endcan
                </div>

                {{-- Versions Timeline --}}
                <div class="space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                        {{ __('Chronological Revisions Archive') }} ({{ $histDoc->versions->count() }} {{ __('versions preserved') }})
                    </h4>

                    <div class="relative pl-6 space-y-4 before:absolute before:left-2.5 before:top-3 before:bottom-3 before:w-0.5 before:bg-border">
                        @foreach ($histDoc->versions as $ver)
                            @php
                                $badge = $ver->file_type_badge;
                            @endphp
                            <div class="relative p-4 rounded-xl border {{ $ver->is_current ? 'border-primary/50 bg-primary/5 shadow-xs' : 'border-border bg-card' }}">
                                {{-- Timeline Marker --}}
                                <div class="absolute -left-[27px] top-4 h-4 w-4 rounded-full border-2 {{ $ver->is_current ? 'border-primary bg-primary' : 'border-border bg-background' }}"></div>

                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-sm {{ $ver->is_current ? 'text-primary' : 'text-foreground' }}">
                                                Version {{ $ver->version_number }}
                                            </span>
                                            @if ($ver->is_current)
                                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">
                                                    {{ __('CURRENT ACTIVE') }}
                                                </span>
                                            @else
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded bg-secondary text-muted-foreground border border-border">
                                                    {{ __('Revision #') }}{{ $ver->revision_sequence }}
                                                </span>
                                            @endif
                                            <span class="text-[11px] font-bold px-1.5 py-0.5 rounded border {{ $badge['badge_bg'] }} {{ $badge['badge_text'] }}">
                                                {{ strtoupper($ver->file_type) }}
                                            </span>
                                        </div>

                                        @if ($ver->version_title)
                                            <p class="text-xs font-semibold text-foreground">{{ $ver->version_title }}</p>
                                        @endif

                                        <p class="text-xs text-muted-foreground">
                                            <span class="font-medium text-foreground/80">{{ __('File:') }}</span>
                                            <span class="font-mono">{{ $ver->original_file_name }}</span>
                                            <span class="text-muted-foreground/80 font-mono">({{ $ver->formatted_file_size }})</span>
                                        </p>

                                        @if ($ver->change_summary)
                                            <div class="text-xs bg-secondary/40 p-2 rounded-md border border-border text-foreground/90 mt-1">
                                                <span class="font-semibold text-primary">{{ __('Change Note:') }}</span> {{ $ver->change_summary }}
                                            </div>
                                        @endif

                                        <div class="flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground pt-1">
                                            <span>Uploaded {{ $ver->created_at?->format('d M Y, h:i A') }} by {{ $ver->uploader?->name ?? 'System' }}</span>
                                            @if ($ver->effective_date)
                                                <span>&bull; Effective {{ $ver->effective_date->format('d M Y') }}</span>
                                            @endif
                                            <span>&bull; {{ $ver->download_count }} downloads</span>
                                        </div>
                                    </div>

                                    {{-- Actions: Download & Set Current (Rollback) --}}
                                    <div class="flex items-center gap-2 shrink-0">
                                        <a
                                            href="{{ route('documents.download.version', $ver) }}"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs transition-colors"
                                            title="{{ __('Download this revision file') }}"
                                        >
                                            <x-icon name="download" class="h-3.5 w-3.5" />
                                            <span>{{ __('Download') }}</span>
                                        </a>

                                        @if (! $ver->is_current)
                                            @can('documents.revise')
                                                <button
                                                    wire:click="rollbackVersion({{ $ver->id }})"
                                                    type="button"
                                                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-secondary hover:bg-secondary/80 text-foreground border border-border cursor-pointer transition-colors"
                                                    title="{{ __('Designate this past revision as the active document version') }}"
                                                >
                                                    <x-icon name="rotate-cw" class="h-3.5 w-3.5 text-primary" />
                                                    <span>{{ __('Make Active') }}</span>
                                                </button>
                                            @endcan
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Modal Footer --}}
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-4 border-t border-border">
                    <span class="text-xs text-muted-foreground flex items-center gap-1.5">
                        <x-icon name="info" class="h-3.5 w-3.5 text-primary shrink-0" />
                        {{ __('Click "Make Active" to designate any historical revision as the current live file.') }}
                    </span>
                    <button
                        type="button"
                        x-on:click="$store.modals.close('document-history-modal')"
                        class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors self-end sm:self-auto"
                    >
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- ======================================================== --}}
    {{-- MODAL 4: Edit Document Metadata                          --}}
    {{-- ======================================================== --}}
    <x-ui.modal name="document-edit-modal" maxWidth="max-w-2xl" :title="__('Edit Document Metadata')" :description="__('Modify document classification, purpose, notes, tags, and access permissions without affecting file revisions.')">
        <form wire:submit="updateDocument" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Document Title') }} <span class="text-red-500">*</span>
                    </label>
                    <input
                        wire:model="editForm.title"
                        type="text"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    @error('editForm.title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Purpose') }}
                    </label>
                    <input
                        wire:model="editForm.purpose"
                        type="text"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Category') }}
                    </label>
                    <select
                        wire:model="editForm.document_category_id"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option value="">{{ __('-- Select Category --') }}</option>
                        @foreach ($this->categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Department') }}
                    </label>
                    <select
                        wire:model="editForm.department"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option value="">{{ __('-- Select Department --') }}</option>
                        @foreach ($this->departments as $d)
                            <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Access Level') }}
                    </label>
                    <select
                        wire:model="editForm.access_level"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option value="internal">{{ __('Internal') }}</option>
                        <option value="public">{{ __('Public') }}</option>
                        <option value="confidential">{{ __('Confidential') }}</option>
                        <option value="restricted">{{ __('Restricted') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Status') }}
                    </label>
                    <select
                        wire:model="editForm.status"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option value="published">{{ __('Published') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="archived">{{ __('Archived') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Tags (Comma-Separated)') }}
                    </label>
                    <input
                        wire:model="editForm.tags"
                        type="text"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Description / Synopsis') }}
                    </label>
                    <textarea
                        wire:model="editForm.description"
                        rows="2"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    ></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Internal Notes') }}
                    </label>
                    <input
                        wire:model="editForm.notes"
                        type="text"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Approval Remarks') }}
                    </label>
                    <input
                        wire:model="editForm.remarks"
                        type="text"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <button
                    type="button"
                    x-on:click="$store.modals.close('document-edit-modal')"
                    class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    type="submit"
                    class="px-5 py-2 text-sm font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs cursor-pointer transition-colors"
                >
                    {{ __('Update Metadata') }}
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- ======================================================== --}}
    {{-- MODAL 5: Bulk Import from Excel / CSV                    --}}
    {{-- ======================================================== --}}
    <x-ui.modal name="document-import-modal" maxWidth="max-w-xl" :title="__('Bulk Import Soft Copy Documents')" :description="__('Import multiple document records from an Excel (.xlsx) or CSV spreadsheet.')">
        <form wire:submit="importDocuments" class="space-y-4">
            <div class="p-3.5 rounded-lg bg-secondary/50 border border-border text-xs flex items-center justify-between">
                <div>
                    <p class="font-semibold text-foreground">{{ __('Need the standard spreadsheet format?') }}</p>
                    <p class="text-muted-foreground">{{ __('Download our pre-structured template with example rows.') }}</p>
                </div>
                <a
                    href="{{ route('documents.template.import') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-card text-foreground hover:bg-secondary border border-border shadow-xs transition-colors shrink-0"
                >
                    <x-icon name="download" class="h-3.5 w-3.5 text-primary" />
                    <span>{{ __('Template') }}</span>
                </a>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                    {{ __('Select Spreadsheet File (.xlsx or .csv)') }} <span class="text-red-500">*</span>
                </label>
                <input
                    wire:model="importFile"
                    type="file"
                    accept=".xlsx,.xls,.csv"
                    class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground"
                />
                @error('importFile') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            @if (! empty($importErrors))
                <div class="p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400 space-y-1">
                    <p class="font-bold">{{ __('Import Warnings & Errors:') }}</p>
                    <ul class="list-disc pl-4 space-y-0.5">
                        @foreach ($importErrors as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <button
                    type="button"
                    x-on:click="$store.modals.close('document-import-modal')"
                    class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs cursor-pointer transition-colors flex items-center gap-1.5"
                >
                    <x-icon name="upload" class="h-4 w-4" />
                    <span>{{ __('Import Records') }}</span>
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- ======================================================== --}}
    {{-- MODAL 6: Interactive Delete Confirmation                 --}}
    {{-- ======================================================== --}}
    <x-ui.modal name="document-delete-modal" maxWidth="max-w-md" :title="__('Confirm Document Deletion')" :description="__('This action will soft-delete the document and all its versions while recording an immutable audit log.')">
        <div class="space-y-4">
            <div class="p-3.5 rounded-lg bg-red-500/10 border border-red-500/20 text-xs text-red-700 dark:text-red-400">
                <p class="font-bold">{{ __('Are you sure you want to soft-delete this document?') }}</p>
                <p class="mt-1 font-semibold text-foreground">{{ $deleteDocumentTitle }}</p>
                <p class="mt-1 text-[11px] text-muted-foreground">{{ __('The document and its versions will be archived in the system and can be recovered if necessary.') }}</p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <button
                    type="button"
                    x-on:click="$store.modals.close('document-delete-modal')"
                    class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    wire:click="deleteDocument"
                    type="button"
                    class="px-4 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700 shadow-xs cursor-pointer transition-colors"
                >
                    {{ __('Confirm Soft Delete') }}
                </button>
            </div>
        </div>
    </x-ui.modal>

    {{-- ======================================================== --}}
    {{-- MODAL 7: Category Taxonomy Creator                       --}}
    {{-- ======================================================== --}}
    <x-ui.modal name="document-category-modal" maxWidth="max-w-md" :title="__('Add Document Category Taxonomy')" :description="__('Create a new classification bucket for soft copy documents.')">
        <form wire:submit="saveCategory" class="space-y-3">
            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                    {{ __('Category Name') }} <span class="text-red-500">*</span>
                </label>
                <input
                    wire:model="categoryForm.name"
                    type="text"
                    placeholder="{{ __('e.g., Annual Accreditation Reports') }}"
                    class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
                @error('categoryForm.name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                    {{ __('Description') }}
                </label>
                <input
                    wire:model="categoryForm.description"
                    type="text"
                    placeholder="{{ __('Description of this classification') }}"
                    class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Icon') }}
                    </label>
                    <select wire:model="categoryForm.icon" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground">
                        <option value="folder">Folder</option>
                        <option value="graduation-cap">Academic</option>
                        <option value="landmark">Government</option>
                        <option value="clipboard-check">Examination</option>
                        <option value="shield">Policy</option>
                        <option value="banknote">Financial</option>
                        <option value="image">Media</option>
                        <option value="file-archive">Archive</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">
                        {{ __('Theme Color') }}
                    </label>
                    <select wire:model="categoryForm.color" class="w-full px-3 py-2 text-sm rounded-lg border border-border bg-background text-foreground">
                        <option value="blue">Blue</option>
                        <option value="indigo">Indigo</option>
                        <option value="emerald">Emerald</option>
                        <option value="red">Red</option>
                        <option value="amber">Amber</option>
                        <option value="purple">Purple</option>
                        <option value="pink">Pink</option>
                        <option value="orange">Orange</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <button
                    type="button"
                    x-on:click="$store.modals.close('document-category-modal')"
                    class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    type="submit"
                    class="px-4 py-2 text-sm font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs cursor-pointer transition-colors"
                >
                    {{ __('Create Category') }}
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- ======================================================== --}}
    {{-- MODAL 8: Document Details & Full Dossier View            --}}
    {{-- ======================================================== --}}
    @php $detDoc = $this->detailsDocument; @endphp
    <x-ui.modal name="document-details-modal" maxWidth="max-w-2xl" :title="__('Document Details & Audit Information')" :description="__('Full dossier view of metadata, current revision pointers, and institutional archival classification.')">
        @if ($detDoc)
            <div class="space-y-4">
                <div class="p-4 rounded-xl bg-secondary/30 border border-border space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs font-bold text-primary bg-primary/10 px-2 py-0.5 rounded border border-primary/20">
                            {{ $detDoc->document_number }}
                        </span>
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs uppercase font-bold px-2 py-0.5 rounded bg-secondary border border-border text-foreground">
                                {{ $detDoc->access_level }}
                            </span>
                            <span class="text-xs uppercase font-bold px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                {{ $detDoc->status }}
                            </span>
                        </div>
                    </div>

                    <h3 class="text-lg font-bold text-foreground">{{ $detDoc->title }}</h3>

                    @if ($detDoc->purpose)
                        <p class="text-xs text-muted-foreground">
                            <span class="font-semibold text-foreground">{{ __('Purpose:') }}</span> {{ $detDoc->purpose }}
                        </p>
                    @endif

                    @if ($detDoc->description)
                        <div class="text-xs text-foreground bg-background p-3 rounded-lg border border-border leading-relaxed">
                            {{ $detDoc->description }}
                        </div>
                    @endif

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-border/60 text-xs">
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase font-bold">{{ __('Category') }}</span>
                            <span class="font-medium text-foreground">{{ $detDoc->category?->name ?? 'None' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase font-bold">{{ __('Department') }}</span>
                            <span class="font-medium text-foreground">{{ $detDoc->department ?? 'General' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase font-bold">{{ __('Total Versions') }}</span>
                            <span class="font-bold text-primary">{{ $detDoc->versions->count() }} revisions</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase font-bold">{{ __('Downloads') }}</span>
                            <span class="font-bold text-emerald-600">{{ $detDoc->total_downloads_count }} times</span>
                        </div>
                    </div>
                </div>

                {{-- Active File Download Card --}}
                @if ($detDoc->currentVersion)
                    @php $cVer = $detDoc->currentVersion; $badge = $cVer->file_type_badge; @endphp
                    <div class="p-3.5 rounded-xl border border-primary/30 bg-primary/5 flex items-center justify-between">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-xs text-primary">Version {{ $cVer->version_number }}</span>
                                <span class="text-[11px] font-bold px-1.5 py-0.5 rounded border {{ $badge['badge_bg'] }} {{ $badge['badge_text'] }}">
                                    {{ strtoupper($cVer->file_type) }}
                                </span>
                            </div>
                            <p class="text-xs font-mono text-foreground font-medium">{{ $cVer->original_file_name }} ({{ $cVer->formatted_file_size }})</p>
                            @if ($cVer->change_summary)
                                <p class="text-[11px] text-muted-foreground italic">"{{ $cVer->change_summary }}"</p>
                            @endif
                        </div>

                        <a
                            href="{{ route('documents.download.version', $cVer) }}"
                            class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs transition-colors shrink-0"
                        >
                            <x-icon name="download" class="h-4 w-4" />
                            <span>{{ __('Download File') }}</span>
                        </a>
                    </div>
                @endif

                {{-- Audit & Creation Info --}}
                <div class="flex flex-wrap items-center justify-between text-xs text-muted-foreground pt-2 border-t border-border">
                    <span>Created: {{ $detDoc->created_at?->format('d M Y, h:i A') }} by {{ $detDoc->creator?->name ?? 'System' }}</span>
                    <span>Last Updated: {{ $detDoc->updated_at?->format('d M Y, h:i A') }}</span>
                </div>

                {{-- Modal Footer --}}
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button
                        type="button"
                        wire:click="openHistoryModal({{ $detDoc->id }})"
                        class="px-3.5 py-2 text-xs font-medium rounded-lg bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-border cursor-pointer transition-colors flex items-center gap-1.5"
                    >
                        <x-icon name="history" class="h-3.5 w-3.5 text-primary" />
                        <span>{{ __('Version History') }}</span>
                    </button>
                    <button
                        type="button"
                        x-on:click="$store.modals.close('document-details-modal')"
                        class="px-4 py-2 text-sm font-medium rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground cursor-pointer transition-colors"
                    >
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
