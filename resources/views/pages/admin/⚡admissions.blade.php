<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OtrImportLog;
use App\Models\Section;
use App\Models\Setting;
use App\Services\OtrSyncService;
use App\Support\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Admissions & OTR Import Hub')] class extends Component {
    use WithPagination;

    // View Navigation
    public string $activeTab = 'feed'; // 'feed', 'history', 'config'

    // Live Feed Filters
    public string $search = '';
    public string $selectedExamId = '';
    public string $statusFilter = 'admitted'; // 'admitted', 'selected', 'all'
    public string $misStatusFilter = 'all'; // 'all', 'pending', 'existing'
    public string $selectionListFilter = ''; // '', 'A', 'B'

    // Pagination for feed
    public int $feedPerPage = 15;
    public int $feedPage = 1;

    // Asynchronous Feed State
    public bool $isFeedLoading = true;
    public array $feedItems = [];
    public array $feedMeta = [
        'total' => 0,
        'current_page' => 1,
        'last_page' => 1,
        'per_page' => 15,
    ];
    public ?string $feedError = null;
    public array $availableExams = [];

    // Selected Candidates for Bulk Operations
    public array $selectedCandidateKeys = [];
    public bool $selectAllOnPage = false;

    // Target Import Configuration (Pre-selected for quick or bulk imports)
    public ?int $targetCourseId = null;
    public ?int $targetBatchId = null;
    public ?int $targetSectionId = null;
    public ?float $targetFeeAmount = null;
    public string $targetFeeStatus = 'paid';
    public bool $downloadDocuments = false;
    public bool $updateExistingProfiles = true;

    // Interactive Modals State
    public bool $showDossierModal = false;
    public ?array $activeCandidateDossier = null;
    public string $dossierActiveTab = 'profile'; // 'profile', 'addresses', 'educations', 'employments', 'documents', 'upsc'

    public bool $showSingleImportModal = false;
    public ?array $candidateToImport = null;

    public bool $showBulkImportModal = false;

    public bool $showResultsModal = false;
    public ?array $importSummaryResult = null;

    public bool $showTestModal = false;
    public ?array $testConnectionResult = null;
    public bool $isTestingConnection = false;

    // Quick Settings State (in config tab)
    public array $configForm = [
        'enabled' => true,
        'base_url' => '',
        'api_key' => '',
        'timeout' => 30,
        'download_documents' => false,
        'update_existing_profiles' => true,
    ];

    public function mount(): void
    {
        $this->loadConfig();

        // Initialize target course and batch from defaults
        $this->targetCourseId = (int) Setting::get('otr.default_course_id', 1) ?: (Course::first()?->id ?? 1);
        $this->targetBatchId = (int) Setting::get('otr.default_batch_id', 1) ?: (Batch::first()?->id ?? 1);
        $this->downloadDocuments = (bool) Setting::get('otr.download_documents', false);
        $this->updateExistingProfiles = (bool) Setting::get('otr.update_existing_profiles', true);
    }

    public function loadConfig(): void
    {
        $this->configForm = [
            'enabled' => OtrSyncService::isEnabled(),
            'base_url' => OtrSyncService::getBaseUrl(),
            'api_key' => OtrSyncService::getApiKey(),
            'timeout' => OtrSyncService::getTimeout(),
            'download_documents' => (bool) Setting::get('otr.download_documents', false),
            'update_existing_profiles' => (bool) Setting::get('otr.update_existing_profiles', true),
        ];
    }

    public function updatedSearch(): void
    {
        $this->feedPage = 1;
        $this->selectedCandidateKeys = [];
        $this->selectAllOnPage = false;
        $this->loadFeed();
    }

    public function updatedSelectedExamId(): void
    {
        $this->feedPage = 1;
        $this->selectedCandidateKeys = [];
        $this->selectAllOnPage = false;
        $this->loadFeed();
    }

    public function updatedStatusFilter(): void
    {
        $this->feedPage = 1;
        $this->selectedCandidateKeys = [];
        $this->selectAllOnPage = false;
        $this->loadFeed();
    }

    public function updatedMisStatusFilter(): void
    {
        $this->feedPage = 1;
        $this->selectedCandidateKeys = [];
        $this->selectAllOnPage = false;
        $this->loadFeed();
    }

    public function updatedSelectionListFilter(): void
    {
        $this->feedPage = 1;
        $this->selectedCandidateKeys = [];
        $this->selectAllOnPage = false;
        $this->loadFeed();
    }

    public function updatedTargetCourseId($courseId): void
    {
        $course = Course::find($courseId);
        if ($course && $this->targetFeeAmount === null) {
            $this->targetFeeAmount = (float) $course->fee;
        }
        $this->loadFeed();
    }

    public function updatedTargetBatchId(): void
    {
        $this->loadFeed();
    }

    public function toggleFeature(): void
    {
        $newState = ! $this->configForm['enabled'];
        $this->configForm['enabled'] = $newState;

        Setting::updateOrCreate(
            ['key' => 'otr.enabled', 'group' => 'otr'],
            ['type' => Setting::TYPE_BOOLEAN, 'value' => $newState ? '1' : '0', 'label' => 'Enable Admission OTR Integration Feature']
        );
        Setting::flushCache();

        $this->loadConfig();
        $this->loadExams();
        $this->loadFeed();

        Toast::dispatch(
            $this,
            $newState ? 'success' : 'info',
            $newState ? __('Admission OTR Integration feature enabled.') : __('Admission OTR Integration feature disabled.')
        );
    }

    public function runTestConnection(): void
    {
        $this->isTestingConnection = true;
        $this->showTestModal = true;
        $this->testConnectionResult = null;

        try {
            $this->testConnectionResult = OtrSyncService::ping(
                baseUrl: $this->configForm['base_url'],
                apiKey: $this->configForm['api_key']
            );
        } catch (\Throwable $e) {
            $this->testConnectionResult = [
                'success' => false,
                'status' => 0,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
                'data' => null,
            ];
        } finally {
            $this->isTestingConnection = false;
        }
    }

    public function saveQuickConfig(): void
    {
        $this->validate([
            'configForm.base_url' => ['required', 'url'],
            'configForm.timeout' => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        try {
            Setting::updateOrCreate(
                ['key' => 'otr.enabled', 'group' => 'otr'],
                ['type' => Setting::TYPE_BOOLEAN, 'value' => $this->configForm['enabled'] ? '1' : '0', 'label' => 'Enable Admission OTR Integration Feature']
            );
            Setting::updateOrCreate(
                ['key' => 'otr.base_url', 'group' => 'otr'],
                ['type' => Setting::TYPE_STRING, 'value' => rtrim($this->configForm['base_url'], '/'), 'label' => 'Admission OTR Portal Base URL']
            );
            if (!empty($this->configForm['api_key'])) {
                Setting::updateOrCreate(
                    ['key' => 'otr.api_key', 'group' => 'otr'],
                    ['type' => Setting::TYPE_SECRET, 'value' => $this->configForm['api_key'], 'label' => 'Admission OTR API Key']
                );
            }
            Setting::updateOrCreate(
                ['key' => 'otr.timeout', 'group' => 'otr'],
                ['type' => Setting::TYPE_NUMBER, 'value' => (string) $this->configForm['timeout'], 'label' => 'Connection Request Timeout (Seconds)']
            );
            Setting::updateOrCreate(
                ['key' => 'otr.download_documents', 'group' => 'otr'],
                ['type' => Setting::TYPE_BOOLEAN, 'value' => $this->configForm['download_documents'] ? '1' : '0', 'label' => 'Download and Store Document Files to MIS Storage']
            );
            Setting::updateOrCreate(
                ['key' => 'otr.update_existing_profiles', 'group' => 'otr'],
                ['type' => Setting::TYPE_BOOLEAN, 'value' => $this->configForm['update_existing_profiles'] ? '1' : '0', 'label' => 'Update Student Dossier If Candidate Already Exists']
            );

            Setting::flushCache();
            $this->loadConfig();
            $this->loadExams();
            $this->loadFeed();

            Toast::dispatch($this, 'success', __('OTR API credentials and policies updated successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update configuration: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openDossierModal(int $candidateIndex): void
    {
        $candidate = $this->feedItems[$candidateIndex] ?? null;
        if (! $candidate) {
            return;
        }

        $this->activeCandidateDossier = $candidate;
        $this->dossierActiveTab = 'profile';
        $this->showDossierModal = true;
    }

    public function closeDossierModal(): void
    {
        $this->showDossierModal = false;
        $this->activeCandidateDossier = null;
    }

    public function openSingleImportModal(int $candidateIndex): void
    {
        $candidate = $this->feedItems[$candidateIndex] ?? null;
        if (! $candidate) {
            return;
        }

        $this->candidateToImport = $candidate;
        $this->showSingleImportModal = true;
    }

    public function closeSingleImportModal(): void
    {
        $this->showSingleImportModal = false;
        $this->candidateToImport = null;
    }

    public function confirmSingleImport(): void
    {
        if (! $this->candidateToImport) {
            return;
        }

        if (! $this->targetCourseId || ! $this->targetBatchId) {
            Toast::dispatch($this, 'error', __('Please select a target Course and Batch for enrollment.'));
            return;
        }

        try {
            $options = [
                'fee_amount' => $this->targetFeeAmount,
                'fee_status' => $this->targetFeeStatus,
                'download_documents' => $this->downloadDocuments,
                'update_existing_profiles' => $this->updateExistingProfiles,
            ];

            $result = OtrSyncService::importCandidate(
                candidateItem: $this->candidateToImport,
                courseId: $this->targetCourseId,
                batchId: $this->targetBatchId,
                sectionId: $this->targetSectionId,
                options: $options,
                actor: auth()->user()
            );

            $this->closeSingleImportModal();

            if ($result['success']) {
                $this->loadFeed();
                $this->importSummaryResult = [
                    'total' => 1,
                    'success_count' => 1,
                    'created_and_enrolled' => $result['action'] === OtrImportLog::ACTION_CREATED_AND_ENROLLED ? 1 : 0,
                    'existing_enrolled' => $result['action'] === OtrImportLog::ACTION_ENROLLED_EXISTING ? 1 : 0,
                    'already_enrolled' => $result['action'] === OtrImportLog::ACTION_ALREADY_ENROLLED ? 1 : 0,
                    'failed_count' => 0,
                    'errors' => [],
                    'message' => $result['message'],
                ];
                $this->showResultsModal = true;
                Toast::dispatch($this, 'success', $result['message']);
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function toggleSelectAllOnPage(): void
    {
        $this->selectAllOnPage = ! $this->selectAllOnPage;

        if ($this->selectAllOnPage) {
            $keys = [];
            foreach ($this->feedItems as $idx => $item) {
                $keys[] = (string) $idx;
            }
            $this->selectedCandidateKeys = $keys;
        } else {
            $this->selectedCandidateKeys = [];
        }
    }

    public function selectPendingOnly(): void
    {
        $keys = [];
        foreach ($this->feedItems as $idx => $item) {
            if (!empty($item['_mis_status']) && $item['_mis_status']['status_type'] !== 'already_enrolled') {
                $keys[] = (string) $idx;
            }
        }
        $this->selectedCandidateKeys = $keys;
        $this->selectAllOnPage = count($keys) === count($this->feedItems);
    }

    public function openBulkImportModal(): void
    {
        if (empty($this->selectedCandidateKeys)) {
            Toast::dispatch($this, 'warning', __('Please select at least one candidate to import.'));
            return;
        }

        if (! $this->targetCourseId || ! $this->targetBatchId) {
            Toast::dispatch($this, 'error', __('Please select a target Course and Batch before proceeding.'));
            return;
        }

        $this->showBulkImportModal = true;
    }

    public function closeBulkImportModal(): void
    {
        $this->showBulkImportModal = false;
    }

    public function executeBulkImport(): void
    {
        if (empty($this->selectedCandidateKeys)) {
            $this->closeBulkImportModal();
            return;
        }

        $candidatesToImport = [];
        foreach ($this->selectedCandidateKeys as $key) {
            $idx = (int) $key;
            if (isset($this->feedItems[$idx])) {
                $candidatesToImport[] = $this->feedItems[$idx];
            }
        }

        if (empty($candidatesToImport)) {
            $this->closeBulkImportModal();
            return;
        }

        $options = [
            'fee_amount' => $this->targetFeeAmount,
            'fee_status' => $this->targetFeeStatus,
            'download_documents' => $this->downloadDocuments,
            'update_existing_profiles' => $this->updateExistingProfiles,
        ];

        try {
            $summary = OtrSyncService::importBulk(
                candidateItems: $candidatesToImport,
                courseId: $this->targetCourseId,
                batchId: $this->targetBatchId,
                sectionId: $this->targetSectionId,
                options: $options,
                actor: auth()->user()
            );

            $this->closeBulkImportModal();
            $this->selectedCandidateKeys = [];
            $this->selectAllOnPage = false;
            $this->loadFeed();

            $this->importSummaryResult = $summary;
            $this->showResultsModal = true;

            Toast::dispatch($this, 'success', __(
                'Bulk import completed: :success of :total candidates processed successfully (:created new students, :enrolled existing).',
                [
                    'success' => $summary['success_count'],
                    'total' => $summary['total'],
                    'created' => $summary['created_and_enrolled'],
                    'enrolled' => $summary['existing_enrolled'],
                ]
            ));
        } catch (\Throwable $e) {
            $this->closeBulkImportModal();
            Toast::dispatch($this, 'error', __('Bulk import exception: :err', ['err' => $e->getMessage()]));
        }
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::orderBy('academic_year', 'desc')->orderBy('name')->get();
    }

    #[Computed]
    public function sections()
    {
        if (! $this->targetCourseId || ! $this->targetBatchId) {
            return collect();
        }

        return Section::where('course_id', $this->targetCourseId)
            ->where('batch_id', $this->targetBatchId)
            ->orderBy('name')
            ->get();
    }

    public function initAdmissionsHub(): void
    {
        $this->loadExams();
        $this->loadFeed();
    }

    public function loadExams(): void
    {
        if (! OtrSyncService::isEnabled()) {
            $this->availableExams = [];
            return;
        }

        $this->availableExams = OtrSyncService::fetchExams(
            baseUrl: $this->configForm['base_url'],
            apiKey: $this->configForm['api_key']
        );
    }

    public function loadFeed(): void
    {
        $this->isFeedLoading = true;
        $this->feedError = null;

        if (! OtrSyncService::isEnabled()) {
            $this->feedItems = [];
            $this->feedMeta = [
                'total' => 0,
                'per_page' => $this->feedPerPage,
                'current_page' => 1,
                'last_page' => 1,
            ];
            $this->feedError = __('Admission OTR Integration feature is currently disabled.');
            $this->isFeedLoading = false;
            return;
        }

        $filters = [
            'status' => $this->statusFilter,
            'exam_id' => $this->selectedExamId ?: null,
            'selection_list' => $this->selectionListFilter ?: null,
            'search' => $this->search ?: null,
            'page' => $this->feedPage,
            'per_page' => $this->feedPerPage,
        ];

        $resp = OtrSyncService::fetchAdmissions(
            filters: $filters,
            baseUrl: $this->configForm['base_url'],
            apiKey: $this->configForm['api_key']
        );

        if (! $resp['success']) {
            $this->feedItems = [];
            $this->feedMeta = [
                'total' => 0,
                'per_page' => $this->feedPerPage,
                'current_page' => 1,
                'last_page' => 1,
            ];
            $this->feedError = $resp['message'];
            $this->isFeedLoading = false;
            return;
        }

        $items = $resp['data'] ?? [];
        $meta = $resp['meta'] ?? [];

        // Decorate items with MIS status detection
        foreach ($items as &$item) {
            $item['_mis_status'] = OtrSyncService::checkCandidateMisStatus(
                $item,
                $this->targetCourseId,
                $this->targetBatchId
            );
        }
        unset($item);

        // Filter by MIS Status if set
        if ($this->misStatusFilter === 'pending') {
            $items = array_values(array_filter($items, fn ($i) => ($i['_mis_status']['status_type'] ?? '') !== 'already_enrolled'));
        } elseif ($this->misStatusFilter === 'existing') {
            $items = array_values(array_filter($items, fn ($i) => ($i['_mis_status']['status_type'] ?? '') !== 'new'));
        }

        $this->feedItems = $items;
        $this->feedMeta = [
            'total' => $meta['total'] ?? count($items),
            'per_page' => $meta['per_page'] ?? $this->feedPerPage,
            'current_page' => $meta['current_page'] ?? $this->feedPage,
            'last_page' => $meta['last_page'] ?? 1,
        ];
        $this->isFeedLoading = false;
    }

    public function setFeedPage(int $page): void
    {
        $this->feedPage = max(1, min($page, $this->feedMeta['last_page'] ?? 1));
        $this->loadFeed();
    }

    #[Computed]
    public function stats(): array
    {
        return OtrSyncService::getDashboardStatistics();
    }

    #[Computed]
    public function importHistory()
    {
        return OtrImportLog::with(['user', 'enrollment.course', 'enrollment.batch', 'course', 'batch', 'importedBy'])
            ->latest('id')
            ->paginate(15);
    }
}; ?>

<div class="space-y-6" wire:init="initAdmissionsHub">
    {{-- Page Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <span class="p-2.5 rounded-xl bg-primary/10 text-primary">
                <x-icon name="users" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Admissions & OTR Import Hub') }}</h1>
                    @if($configForm['enabled'])
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                            <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            {{ __('OTR API Online') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                            {{ __('Feature Disabled') }}
                        </span>
                    @endif
                </div>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ __('Live candidate synchronization from SNTCSSC Admission OTR portal, multi-table dossiers, and direct course enrollments.') }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <button
                type="button"
                wire:click="runTestConnection"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-xs"
            >
                <x-icon name="activity" class="h-4 w-4 text-primary" wire:loading.remove wire:target="runTestConnection" />
                <x-icon name="refresh-cw" class="h-4 w-4 animate-spin text-primary" wire:loading wire:target="runTestConnection" />
                {{ __('Test Connection') }}
            </button>

            <a
                href="{{ route('admin.settings.otr') }}"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-xs"
            >
                <x-icon name="settings" class="h-4 w-4" />
                {{ __('OTR Settings') }}
            </a>

            @if(!empty($selectedCandidateKeys))
                <button
                    type="button"
                    wire:click="openBulkImportModal"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-sm animate-pulse"
                >
                    <x-icon name="download" class="h-4 w-4" />
                    {{ __('Import Selected (:count)', ['count' => count($selectedCandidateKeys)]) }}
                </button>
            @endif
        </div>
    </div>

    {{-- Feature Disabled Notice Banner --}}
    @if(! $configForm['enabled'])
        <div class="p-4 rounded-xl border border-amber-500/30 bg-amber-500/10 text-amber-950 dark:text-amber-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="p-2 rounded-lg bg-amber-500/20 text-amber-600 dark:text-amber-400 shrink-0">
                    <x-icon name="alert-circle" class="h-5 w-5" />
                </span>
                <div>
                    <h4 class="text-sm font-bold">{{ __('Admission OTR Integration Feature is Currently Disabled') }}</h4>
                    <p class="text-xs text-amber-800/90 dark:text-amber-300/90 mt-0.5 leading-relaxed">
                        {{ __('Both applications are operating independently. MIS does not make any external requests to the Admission OTR portal while disabled. You can re-enable this feature at any time without data loss.') }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                wire:click="toggleFeature"
                class="px-4 py-2 text-xs font-bold rounded-lg bg-amber-600 text-white hover:bg-amber-700 transition-colors shrink-0 shadow-xs"
            >
                {{ __('Enable OTR Feature Now') }}
            </button>
        </div>
    @endif

    {{-- Real-Time Telemetry & Statistics Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3.5">
        {{-- Card 1: Admitted Candidates in OTR --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('OTR Candidates') }}</span>
                <span class="p-1.5 rounded-md bg-primary/10 text-primary">
                    <x-icon name="users" class="h-3.5 w-3.5" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold font-mono text-foreground flex items-center">
                @if($isFeedLoading)
                    <span class="inline-block h-7 w-14 rounded bg-muted animate-pulse"></span>
                @else
                    {{ $feedMeta['total'] ?? 0 }}
                @endif
            </div>
            <p class="mt-1 text-[11px] text-muted-foreground">
                {{ __('Admitted in current OTR query') }}
            </p>
        </div>

        {{-- Card 2: New Students Created & Enrolled --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('New Students') }}</span>
                <span class="p-1.5 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check" class="h-3.5 w-3.5" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400">
                {{ $this->stats['new_students_created'] }}
            </div>
            <p class="mt-1 text-[11px] text-muted-foreground">
                {{ __('Registered from OTR imports') }}
            </p>
        </div>

        {{-- Card 3: Existing Students Enrolled --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Multi-Course') }}</span>
                <span class="p-1.5 rounded-md bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="layers" class="h-3.5 w-3.5" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold font-mono text-amber-600 dark:text-amber-400">
                {{ $this->stats['existing_students_enrolled'] }}
            </div>
            <p class="mt-1 text-[11px] text-muted-foreground">
                {{ __('Added new course enrollments') }}
            </p>
        </div>

        {{-- Card 4: Total Import Logs --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Total Synchronized') }}</span>
                <span class="p-1.5 rounded-md bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="download" class="h-3.5 w-3.5" />
                </span>
            </div>
            <div class="mt-2 text-2xl font-bold font-mono text-indigo-600 dark:text-indigo-400">
                {{ $this->stats['total_imported_records'] }}
            </div>
            <p class="mt-1 text-[11px] text-muted-foreground">
                {{ __('Successful + skipped logs') }}
            </p>
        </div>

        {{-- Card 5: Last Sync Time --}}
        <div class="col-span-2 lg:col-span-1 rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between text-muted-foreground text-xs font-medium">
                <span>{{ __('Last Sync') }}</span>
                <span class="p-1.5 rounded-md bg-secondary text-secondary-foreground">
                    <x-icon name="refresh-cw" class="h-3.5 w-3.5" />
                </span>
            </div>
            <div class="mt-2 text-base font-bold text-foreground truncate">
                {{ $this->stats['last_sync_at'] ?? __('Never') }}
            </div>
            <p class="mt-1 text-[11px] text-muted-foreground">
                {{ __('Audit status:') }} <span class="capitalize font-medium">{{ $this->stats['last_sync_status'] ?? __('Idle') }}</span>
            </p>
        </div>
    </div>

    {{-- Main Tabs Navigation --}}
    <div class="border-b border-border">
        <nav class="flex gap-4">
            <button
                type="button"
                wire:click="$set('activeTab', 'feed')"
                class="pb-3 text-sm font-semibold border-b-2 transition-colors flex items-center gap-2 {{ $activeTab === 'feed' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="users" class="h-4 w-4" />
                <span>{{ __('OTR Admitted Candidates Feed') }}</span>
                @if($isFeedLoading)
                    <span class="ml-1 inline-block h-4 w-6 rounded-full bg-muted animate-pulse"></span>
                @else
                    <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-bold {{ $activeTab === 'feed' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground' }}">
                        {{ $feedMeta['total'] ?? 0 }}
                    </span>
                @endif
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'history')"
                class="pb-3 text-sm font-semibold border-b-2 transition-colors flex items-center gap-2 {{ $activeTab === 'history' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="clock" class="h-4 w-4" />
                <span>{{ __('Import History & Audit Log') }}</span>
                <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-bold {{ $activeTab === 'history' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground' }}">
                    {{ $this->stats['total_imported_records'] }}
                </span>
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'config')"
                class="pb-3 text-sm font-semibold border-b-2 transition-colors flex items-center gap-2 {{ $activeTab === 'config' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="settings" class="h-4 w-4" />
                <span>{{ __('Quick Connection Config') }}</span>
            </button>
        </nav>
    </div>

    {{-- ================================================================= --}}
    {{-- TAB 1: LIVE OTR CANDIDATES FEED --}}
    {{-- ================================================================= --}}
    @if($activeTab === 'feed')
        <div class="space-y-4">
            {{-- Target Enrollment Configuration Bar --}}
            <div class="rounded-xl border border-primary/20 bg-primary/5 p-4 shadow-xs">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="p-1.5 rounded-md bg-primary text-primary-foreground shrink-0">
                            <x-icon name="graduation-cap" class="h-4 w-4" />
                        </span>
                        <div>
                            <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                                {{ __('Target MIS Enrollment Target') }}
                            </span>
                            <span class="text-[11px] text-muted-foreground block">
                                {{ __('Candidates imported will be enrolled into the selected course and batch.') }}
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2.5 w-full lg:w-auto">
                        {{-- Course Select --}}
                        <div>
                            <select
                                wire:model.live="targetCourseId"
                                class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs font-medium text-foreground shadow-xs outline-none focus:border-primary"
                            >
                                <option value="">{{ __('-- Select Course --') }}</option>
                                @foreach($this->courses as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Batch Select --}}
                        <div>
                            <select
                                wire:model.live="targetBatchId"
                                class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs font-medium text-foreground shadow-xs outline-none focus:border-primary"
                            >
                                <option value="">{{ __('-- Select Batch --') }}</option>
                                @foreach($this->batches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Section Select (Optional) --}}
                        <div>
                            <select
                                wire:model.live="targetSectionId"
                                class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs font-medium text-foreground shadow-xs outline-none focus:border-primary"
                            >
                                <option value="">{{ __('Auto / No Section') }}</option>
                                @foreach($this->sections as $sec)
                                    <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Fee Status --}}
                        <div>
                            <select
                                wire:model.live="targetFeeStatus"
                                class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs font-medium text-foreground shadow-xs outline-none focus:border-primary"
                            >
                                <option value="paid">{{ __('Fee: Paid') }}</option>
                                <option value="pending">{{ __('Fee: Pending') }}</option>
                                <option value="partial">{{ __('Fee: Partial') }}</option>
                                <option value="exempted">{{ __('Fee: Exempted') }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Feed Search and Filters Bar --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
                    {{-- Search Input --}}
                    <div class="md:col-span-2 relative">
                        <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" />
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search candidate, email, mobile, URN, roll, app no...') }}"
                            class="h-9 w-full rounded-lg border border-input bg-transparent pl-9 pr-8 text-xs shadow-xs outline-none placeholder:text-muted-foreground focus:border-primary"
                        />
                        @if(!empty($search))
                            <button
                                type="button"
                                wire:click="$set('search', '')"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            >
                                <x-icon name="x" class="h-3.5 w-3.5" />
                            </button>
                        @endif
                    </div>

                    {{-- Exam Filter --}}
                    <div>
                        <select
                            wire:model.live="selectedExamId"
                            class="h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-xs font-medium text-foreground shadow-xs outline-none focus:border-primary"
                        >
                            <option value="">{{ __('All Examinations / Programmes') }}</option>
                            @foreach($availableExams as $ex)
                                <option value="{{ $ex['id'] }}">{{ $ex['exam_name'] }} ({{ $ex['exam_code'] }})</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- MIS Status Filter --}}
                    <div>
                        <select
                            wire:model.live="misStatusFilter"
                            class="h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-xs font-medium text-foreground shadow-xs outline-none focus:border-primary"
                        >
                            <option value="all">{{ __('All MIS Statuses') }}</option>
                            <option value="pending">{{ __('Pending Import (Not in MIS)') }}</option>
                            <option value="existing">{{ __('Already In MIS (Enrolled)') }}</option>
                        </select>
                    </div>

                    {{-- Selection List Filter --}}
                    <div>
                        <select
                            wire:model.live="selectionListFilter"
                            class="h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-xs font-medium text-foreground shadow-xs outline-none focus:border-primary"
                        >
                            <option value="">{{ __('All Selection Lists') }}</option>
                            <option value="A">{{ __('List A') }}</option>
                            <option value="B">{{ __('List B') }}</option>
                        </select>
                    </div>
                </div>

                {{-- Bulk Selection Bar --}}
                <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-border/60 text-xs">
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 cursor-pointer font-medium text-foreground">
                            <input
                                type="checkbox"
                                wire:click="toggleSelectAllOnPage"
                                {{ $selectAllOnPage ? 'checked' : '' }}
                                class="rounded border-input text-primary focus:ring-primary/20 h-4 w-4"
                            />
                            <span>{{ __('Select All on Page') }}</span>
                        </label>

                        <button
                            type="button"
                            wire:click="selectPendingOnly"
                            class="text-primary hover:underline font-medium text-[11px]"
                        >
                            {{ __('Select Pending Import Only') }}
                        </button>

                        @if(!empty($selectedCandidateKeys))
                            <span class="text-muted-foreground text-[11px]">
                                ({{ count($selectedCandidateKeys) }} {{ __('selected') }})
                            </span>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-[11px] text-muted-foreground">
                            {{ __('Showing page :current of :last', ['current' => $feedMeta['current_page'], 'last' => $feedMeta['last_page']]) }}
                        </span>

                        <button
                            type="button"
                            wire:click="setFeedPage({{ max(1, $feedPage - 1) }})"
                            @disabled($feedPage <= 1 || $isFeedLoading)
                            class="p-1 rounded-md border border-border bg-card text-foreground hover:bg-muted disabled:opacity-40 transition-colors"
                        >
                            <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
                        </button>

                        <button
                            type="button"
                            wire:click="setFeedPage({{ $feedPage + 1 }})"
                            @disabled($feedPage >= ($feedMeta['last_page'] ?? 1) || $isFeedLoading)
                            class="p-1 rounded-md border border-border bg-card text-foreground hover:bg-muted disabled:opacity-40 transition-colors"
                        >
                            <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            {{-- Candidate Feed Table --}}
            @if($isFeedLoading)
                {{-- Deferred Livewire Skeleton Loader --}}
                <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
                    <div class="p-4 border-b border-border flex items-center justify-between bg-muted/20 animate-pulse">
                        <div class="flex items-center gap-2">
                            <span class="size-4 rounded bg-muted"></span>
                            <span class="h-3.5 w-40 rounded bg-muted"></span>
                        </div>
                        <span class="h-3.5 w-24 rounded bg-muted"></span>
                    </div>
                    <div class="divide-y divide-border">
                        @for($sk = 0; $sk < 6; $sk++)
                            <div class="p-4 flex items-center justify-between gap-4 animate-pulse">
                                <div class="flex items-center gap-3">
                                    <div class="size-8 rounded-full bg-muted"></div>
                                    <div class="space-y-1.5">
                                        <div class="h-3.5 w-36 rounded bg-muted"></div>
                                        <div class="h-2.5 w-44 rounded bg-muted"></div>
                                        <div class="h-2 w-24 rounded bg-muted"></div>
                                    </div>
                                </div>
                                <div class="hidden sm:block space-y-1.5">
                                    <div class="h-3 w-28 rounded bg-muted"></div>
                                    <div class="h-2.5 w-20 rounded bg-muted"></div>
                                </div>
                                <div class="hidden md:block space-y-1.5">
                                    <div class="h-3 w-20 rounded bg-muted"></div>
                                    <div class="h-2.5 w-16 rounded bg-muted"></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="h-6 w-16 rounded-md bg-muted"></div>
                                    <div class="h-6 w-24 rounded-md bg-muted"></div>
                                </div>
                            </div>
                        @endfor
                    </div>
                </div>
            @elseif(!empty($feedError))
                <div class="p-6 rounded-2xl border border-destructive/20 bg-destructive/10 text-destructive text-center space-y-3">
                    <x-icon name="alert-circle" class="h-8 w-8 mx-auto" />
                    <h3 class="text-sm font-bold">{{ __('Could not load candidates from OTR') }}</h3>
                    <p class="text-xs max-w-lg mx-auto leading-relaxed text-destructive/90">{{ $feedError }}</p>
                    <div class="flex flex-wrap items-center justify-center gap-2.5 pt-1">
                        <button
                            type="button"
                            wire:click="loadFeed"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-lg bg-destructive text-destructive-foreground hover:bg-destructive/90 transition-colors shadow-xs"
                        >
                            <x-icon name="refresh-cw" class="h-3.5 w-3.5" />
                            {{ __('Retry Request') }}
                        </button>
                        <button
                            type="button"
                            wire:click="runTestConnection"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-xs"
                        >
                            <x-icon name="activity" class="h-3.5 w-3.5 text-primary" />
                            {{ __('Test Connection') }}
                        </button>
                        <a
                            href="{{ route('admin.settings.otr') }}"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-xs"
                        >
                            <x-icon name="settings" class="h-3.5 w-3.5" />
                            {{ __('OTR Settings') }}
                        </a>
                    </div>
                </div>
            @elseif(empty($feedItems))
                <div class="p-12 rounded-2xl border border-border bg-card text-center space-y-3">
                    <x-icon name="users" class="h-10 w-10 mx-auto text-muted-foreground/60" />
                    <h3 class="text-base font-bold text-foreground">{{ __('No Admitted Candidates Found') }}</h3>
                    <p class="text-xs text-muted-foreground max-w-md mx-auto leading-relaxed">
                        {{ __('No candidate records matching your search and filter criteria were returned from the Admission OTR portal.') }}
                    </p>
                    <button
                        type="button"
                        wire:click="loadFeed"
                        class="mt-1 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors"
                    >
                        <x-icon name="refresh-cw" class="h-3.5 w-3.5" />
                        {{ __('Refresh Feed') }}
                    </button>
                </div>
            @else
                <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-border bg-muted/40 text-[11px] font-bold text-muted-foreground uppercase tracking-wider">
                                    <th class="py-3 px-4 w-10 text-center">
                                        <span class="sr-only">{{ __('Select') }}</span>
                                    </th>
                                    <th class="py-3 px-4">{{ __('Candidate & OTR Profile') }}</th>
                                    <th class="py-3 px-4">{{ __('Examination & Application') }}</th>
                                    <th class="py-3 px-4">{{ __('Selection & Payment') }}</th>
                                    <th class="py-3 px-4">{{ __('MIS Status') }}</th>
                                    <th class="py-3 px-4 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($feedItems as $index => $candidate)
                                    @php
                                        $user = $candidate['users'] ?? [];
                                        $app = $candidate['application'] ?? [];
                                        $form = $candidate['admission']['admission_form'] ?? [];
                                        $sel = $candidate['admission']['admission_selection'] ?? [];
                                        $exam = $candidate['exams'] ?? [];
                                        $misStatus = $candidate['_mis_status'] ?? [];
                                        $isSelected = in_array((string) $index, $selectedCandidateKeys, true);
                                    @endphp
                                    <tr class="hover:bg-muted/30 transition-colors {{ $isSelected ? 'bg-primary/5' : '' }}">
                                        {{-- Checkbox --}}
                                        <td class="py-3.5 px-4 text-center">
                                            <input
                                                type="checkbox"
                                                value="{{ $index }}"
                                                wire:model.live="selectedCandidateKeys"
                                                class="rounded border-input text-primary focus:ring-primary/20 h-4 w-4"
                                            />
                                        </td>

                                        {{-- Candidate Info --}}
                                        <td class="py-3.5 px-4">
                                            <div class="flex items-start gap-3">
                                                <div class="size-8 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center shrink-0 text-xs">
                                                    {{ strtoupper(substr($user['name'] ?? 'U', 0, 1)) }}
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="font-bold text-foreground text-xs truncate">
                                                        {{ $user['name'] ?? __('Unnamed Candidate') }}
                                                    </div>
                                                    <div class="text-[11px] text-muted-foreground truncate">
                                                        {{ $user['email'] ?? 'N/A' }}
                                                    </div>
                                                    <div class="flex items-center gap-2 mt-0.5 text-[10px] text-muted-foreground">
                                                        @if(!empty($user['mobile']))
                                                            <span>{{ $user['mobile'] }}</span>
                                                        @endif
                                                        @if(!empty($user['urn']))
                                                            <span class="font-mono bg-secondary px-1 py-0.2 rounded">{{ $user['urn'] }}</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        {{-- Exam & Application --}}
                                        <td class="py-3.5 px-4">
                                            <div class="space-y-1">
                                                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold bg-primary/10 text-primary">
                                                    {{ $exam['exam_code'] ?? ($exam['exam_name'] ?? 'Exam') }}
                                                </span>
                                                <div class="text-[11px] font-mono text-foreground font-semibold">
                                                    App: {{ $app['application_number'] ?? 'N/A' }}
                                                </div>
                                                @if(!empty($form['admission_form_number']))
                                                    <div class="text-[10px] font-mono text-muted-foreground">
                                                        Form: {{ $form['admission_form_number'] }}
                                                    </div>
                                                @endif
                                                @if(!empty($form['roll_no']))
                                                    <div class="text-[10px] text-muted-foreground">
                                                        Roll: <strong class="font-mono">{{ $form['roll_no'] }}</strong>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>

                                        {{-- Selection & Payment --}}
                                        <td class="py-3.5 px-4">
                                            <div class="space-y-1">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold {{ ($sel['selection_list'] ?? '') === 'A' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-purple-500/10 text-purple-600 dark:text-purple-400' }}">
                                                        {{ $sel['selection_list_label'] ?? ('List ' . ($sel['selection_list'] ?? 'A')) }}
                                                    </span>
                                                    <span class="text-[10px] text-muted-foreground capitalize">
                                                        {{ str_replace('_', ' ', $sel['selection_mode'] ?? 'test') }}
                                                    </span>
                                                </div>

                                                <div class="flex items-center gap-1.5 text-[11px]">
                                                    <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">
                                                        {{ $form['payment_status'] ?? __('Paid') }}
                                                    </span>
                                                    @if(!empty($form['amount_paid']))
                                                        <span class="text-muted-foreground text-[10px]">
                                                            (₹{{ number_format((float) $form['amount_paid'], 0) }})
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>

                                        {{-- MIS Status Detection --}}
                                        <td class="py-3.5 px-4">
                                            @if(($misStatus['status_type'] ?? '') === 'already_enrolled')
                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20">
                                                    <x-icon name="check-circle" class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" />
                                                    <span>{{ __('Enrolled in MIS') }}</span>
                                                </span>
                                                @if(!empty($misStatus['enrollments']))
                                                    <div class="text-[10px] font-mono text-muted-foreground mt-1">
                                                        {{ $misStatus['enrollments'][0]['enrollment_no'] ?? '' }}
                                                    </div>
                                                @endif
                                            @elseif(($misStatus['status_type'] ?? '') === 'student_exists')
                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20">
                                                    <x-icon name="user" class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400 shrink-0" />
                                                    <span>{{ __('Student Exists') }}</span>
                                                </span>
                                                <div class="text-[10px] text-muted-foreground mt-1">
                                                    ID: <strong class="font-mono">{{ $misStatus['student_id'] ?? '' }}</strong>
                                                </div>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border border-indigo-500/20">
                                                    <x-icon name="plus-circle" class="h-3.5 w-3.5 text-indigo-600 dark:text-indigo-400 shrink-0" />
                                                    <span>{{ __('New Candidate') }}</span>
                                                </span>
                                                <div class="text-[10px] text-muted-foreground mt-1">
                                                    {{ __('Ready to import') }}
                                                </div>
                                            @endif
                                        </td>

                                        {{-- Actions --}}
                                        <td class="py-3.5 px-4 text-right">
                                            <div class="inline-flex items-center gap-1.5">
                                                <button
                                                    type="button"
                                                    wire:click="openDossierModal({{ $index }})"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-md border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-2xs"
                                                    title="{{ __('View 14-table candidate application dossier') }}"
                                                >
                                                    <x-icon name="eye" class="h-3.5 w-3.5 text-muted-foreground" />
                                                    <span>{{ __('Dossier') }}</span>
                                                </button>

                                                @if(($misStatus['status_type'] ?? '') === 'already_enrolled')
                                                    <button
                                                        type="button"
                                                        disabled
                                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md border border-border bg-muted/40 text-muted-foreground opacity-60 cursor-not-allowed"
                                                    >
                                                        <x-icon name="check" class="h-3.5 w-3.5" />
                                                        <span>{{ __('Enrolled') }}</span>
                                                    </button>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="openSingleImportModal({{ $index }})"
                                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
                                                    >
                                                        <x-icon name="download" class="h-3.5 w-3.5" />
                                                        <span>{{ __('Import & Enroll') }}</span>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- TAB 2: IMPORT HISTORY & AUDIT LOG --}}
    {{-- ================================================================= --}}
    @if($activeTab === 'history')
        <div class="space-y-4">
            <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
                <div class="p-4 border-b border-border flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Historical OTR Imports & Enrollment Logs') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Complete audit trail of all candidate synchronization transactions from Admission OTR.') }}</p>
                    </div>
                    <span class="text-xs font-mono px-2 py-0.5 rounded bg-muted text-muted-foreground">
                        {{ $this->importHistory->total() }} {{ __('records') }}
                    </span>
                </div>

                @if($this->importHistory->isEmpty())
                    <div class="p-12 text-center space-y-2">
                        <x-icon name="clock" class="h-8 w-8 mx-auto text-muted-foreground/60" />
                        <h4 class="text-sm font-bold text-foreground">{{ __('No Import Logs Yet') }}</h4>
                        <p class="text-xs text-muted-foreground">{{ __('Candidate imports executed from the OTR feed will appear here with audit timestamps.') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-border bg-muted/40 text-[11px] font-bold text-muted-foreground uppercase tracking-wider">
                                    <th class="py-3 px-4">{{ __('Candidate & Identifiers') }}</th>
                                    <th class="py-3 px-4">{{ __('Course & Batch') }}</th>
                                    <th class="py-3 px-4">{{ __('Enrollment Created') }}</th>
                                    <th class="py-3 px-4">{{ __('Action & Status') }}</th>
                                    <th class="py-3 px-4">{{ __('Imported By & Time') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($this->importHistory as $log)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="py-3 px-4">
                                            <div class="font-bold text-foreground text-xs">{{ $log->candidate_name }}</div>
                                            <div class="text-[11px] text-muted-foreground">{{ $log->candidate_email }}</div>
                                            <div class="text-[10px] font-mono text-muted-foreground">
                                                App: {{ $log->application_number ?? 'N/A' }} | Form: {{ $log->admission_form_number ?? 'N/A' }}
                                            </div>
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="font-semibold text-foreground text-xs">{{ $log->course?->name ?? 'Course #' . $log->course_id }}</div>
                                            <div class="text-[11px] text-muted-foreground">{{ $log->batch?->name ?? 'Batch #' . $log->batch_id }}</div>
                                        </td>
                                        <td class="py-3 px-4">
                                            @if($log->enrollment)
                                                <span class="font-mono text-xs font-bold text-primary">{{ $log->enrollment->enrollment_no }}</span>
                                                <div class="text-[10px] text-muted-foreground">ID: {{ $log->user?->student_id }}</div>
                                            @else
                                                <span class="text-muted-foreground">—</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $log->status === 'success' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400' }}">
                                                {{ ucfirst($log->status) }}
                                            </span>
                                            <div class="text-[10px] text-muted-foreground capitalize mt-0.5">
                                                {{ str_replace('_', ' ', $log->action_taken) }}
                                            </div>
                                            @if($log->error_message)
                                                <div class="text-[10px] text-rose-600 dark:text-rose-400 mt-0.5 max-w-xs truncate" title="{{ $log->error_message }}">
                                                    {{ $log->error_message }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="text-xs text-foreground">{{ $log->importedBy?->name ?? __('System') }}</div>
                                            <div class="text-[11px] text-muted-foreground">{{ $log->created_at?->diffForHumans() }}</div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 border-t border-border">
                        {{ $this->importHistory->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- TAB 3: QUICK CONNECTION CONFIGURATION --}}
    {{-- ================================================================= --}}
    @if($activeTab === 'config')
        <div class="max-w-3xl space-y-5">
            <div class="rounded-2xl border border-border bg-card p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <div>
                        <h3 class="text-sm font-bold text-foreground uppercase tracking-wider">{{ __('OTR API Connectivity') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Configure connection parameters for communication between MIS and Admission OTR.') }}</p>
                    </div>
                    <a href="{{ route('admin.settings.otr') }}" class="text-xs text-primary hover:underline font-semibold flex items-center gap-1">
                        <span>{{ __('Advanced Settings') }}</span>
                        <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1.5">{{ __('Admission OTR Portal Base URL') }}</label>
                        <input
                            type="url"
                            wire:model.defer="configForm.base_url"
                            placeholder="http://127.0.0.1:8000"
                            class="h-10 w-full rounded-lg border border-input bg-transparent px-3 text-xs font-mono shadow-xs outline-none focus:border-primary"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1.5">{{ __('Admission OTR API Key (Bearer Token)') }}</label>
                        <input
                            type="password"
                            wire:model.defer="configForm.api_key"
                            placeholder="adm_live_..."
                            class="h-10 w-full rounded-lg border border-input bg-transparent px-3 text-xs font-mono shadow-xs outline-none focus:border-primary"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1.5">{{ __('Request Timeout (Seconds)') }}</label>
                            <input
                                type="number"
                                wire:model.defer="configForm.timeout"
                                min="5"
                                max="120"
                                class="h-10 w-full rounded-lg border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus:border-primary"
                            />
                        </div>

                        <div class="flex items-end">
                            <button
                                type="button"
                                wire:click="runTestConnection"
                                class="h-10 w-full inline-flex items-center justify-center gap-2 px-3 rounded-lg border border-primary/30 bg-primary/10 text-primary text-xs font-semibold hover:bg-primary/20 transition-colors"
                            >
                                <x-icon name="activity" class="h-4 w-4" />
                                <span>{{ __('Test API Connection') }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-border flex items-center justify-between">
                        <button
                            type="button"
                            wire:click="saveQuickConfig"
                            class="px-4 py-2 text-xs font-bold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
                        >
                            {{ __('Save Credentials') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- MODAL 1: CANDIDATE DOSSIER MODAL (Full 14-Table Application View) --}}
    {{-- ================================================================= --}}
    @if($showDossierModal && $activeCandidateDossier)
        @php
            $candUser = $activeCandidateDossier['users'] ?? [];
            $candProfile = $activeCandidateDossier['application_profiles'] ?? [];
            $candAddresses = $activeCandidateDossier['application_addresses'] ?? [];
            $candEducations = $activeCandidateDossier['appl_edu_qualifications'] ?? [];
            $candEmployments = $activeCandidateDossier['appl_empl_histories'] ?? [];
            $candDocs = $activeCandidateDossier['appl_documents'] ?? [];
            $candOther = $activeCandidateDossier['appl_other_details'] ?? [];
            $candApp = $activeCandidateDossier['application'] ?? [];
            $candForm = $activeCandidateDossier['admission']['admission_form'] ?? [];
            $candSel = $activeCandidateDossier['admission']['admission_selection'] ?? [];
            $candExam = $activeCandidateDossier['exams'] ?? [];
        @endphp

        <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/60 backdrop-blur-xs transition-opacity animate-fade-in">
            <div class="w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-card border border-border shadow-2xl overflow-hidden animate-scale-up">
                {{-- Modal Header --}}
                <div class="p-5 border-b border-border flex items-start justify-between bg-muted/20">
                    <div class="flex items-start gap-3.5">
                        <div class="size-11 rounded-xl bg-primary/10 text-primary font-bold flex items-center justify-center shrink-0 text-base">
                            {{ strtoupper(substr($candUser['name'] ?? 'U', 0, 1)) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-bold text-foreground">
                                    {{ $candUser['name'] ?? __('Candidate Dossier') }}
                                </h3>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-primary/10 text-primary">
                                    {{ $candExam['exam_code'] ?? 'EXAM' }}
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ $candUser['email'] ?? 'N/A' }} &bull; {{ $candUser['mobile'] ?? 'N/A' }}
                                @if(!empty($candUser['urn']))
                                    &bull; <span class="font-mono">{{ $candUser['urn'] }}</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="closeDossierModal"
                        class="p-1.5 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                    >
                        <x-icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                {{-- Dossier Sub-Tabs --}}
                <div class="px-5 border-b border-border bg-card">
                    <nav class="flex gap-4 overflow-x-auto text-xs font-semibold py-2">
                        <button
                            type="button"
                            wire:click="$set('dossierActiveTab', 'profile')"
                            class="pb-1 transition-colors {{ $dossierActiveTab === 'profile' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            {{ __('Personal & Family') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('dossierActiveTab', 'addresses')"
                            class="pb-1 transition-colors {{ $dossierActiveTab === 'addresses' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            {{ __('Addresses (:count)', ['count' => count($candAddresses)]) }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('dossierActiveTab', 'educations')"
                            class="pb-1 transition-colors {{ $dossierActiveTab === 'educations' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            {{ __('Educations (:count)', ['count' => count($candEducations)]) }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('dossierActiveTab', 'employments')"
                            class="pb-1 transition-colors {{ $dossierActiveTab === 'employments' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            {{ __('Employment (:count)', ['count' => count($candEmployments)]) }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('dossierActiveTab', 'documents')"
                            class="pb-1 transition-colors {{ $dossierActiveTab === 'documents' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            {{ __('Documents (:count)', ['count' => count($candDocs)]) }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('dossierActiveTab', 'upsc')"
                            class="pb-1 transition-colors {{ $dossierActiveTab === 'upsc' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            {{ __('UPSC & Other') }}
                        </button>
                    </nav>
                </div>

                {{-- Modal Body (Scrollable) --}}
                <div class="p-5 overflow-y-auto space-y-4 text-xs flex-1">
                    {{-- Subtab: Profile --}}
                    @if($dossierActiveTab === 'profile')
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Date of Birth & Gender') }}</span>
                                <div class="font-semibold text-foreground">{{ $candProfile['dob'] ?? ($candUser['dob'] ?? 'N/A') }}</div>
                                <div class="text-muted-foreground capitalize">{{ $candProfile['gender'] ?? ($candUser['gender'] ?? 'N/A') }}</div>
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Category & Subcaste') }}</span>
                                <div class="font-bold text-primary">{{ $candProfile['category'] ?? 'General' }}</div>
                                <div class="text-muted-foreground">{{ $candProfile['subcaste'] ?? 'N/A' }}</div>
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Category Certificate') }}</span>
                                <div class="font-mono text-foreground">{{ $candProfile['cert_no'] ?? 'N/A' }}</div>
                                <div class="text-[10px] text-muted-foreground">{{ $candProfile['issued_by'] ?? 'N/A' }} ({{ $candProfile['issued_date'] ?? 'N/A' }})</div>
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Father\'s Details') }}</span>
                                <div class="font-semibold text-foreground">{{ $candProfile['father_name'] ?? 'N/A' }}</div>
                                <div class="text-muted-foreground">{{ $candProfile['father_occupation'] ?? 'N/A' }}</div>
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Mother\'s Details') }}</span>
                                <div class="font-semibold text-foreground">{{ $candProfile['mother_name'] ?? 'N/A' }}</div>
                                <div class="text-muted-foreground">{{ $candProfile['mother_occupation'] ?? 'N/A' }}</div>
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Family Income & Occupation') }}</span>
                                <div class="font-semibold text-foreground">₹{{ $candProfile['family_income'] ?? '0' }} {{ __('Lakhs/Year') }}</div>
                                <div class="text-muted-foreground">{{ $candProfile['occupation'] ?? 'Student' }}</div>
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Highest Education') }}</span>
                                <div class="font-semibold text-foreground">{{ $candProfile['highest_edu'] ?? 'N/A' }}</div>
                                <div class="text-muted-foreground">{{ __('School Medium: ') }}{{ $candProfile['school_language'] ?? 'N/A' }}</div>
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('PwBD Status') }}</span>
                                <div class="font-semibold text-foreground">
                                    {{ !empty($candProfile['is_pwbd']) ? __('Yes - ' . ($candProfile['pwbd_type'] ?? '')) : __('No') }}
                                </div>
                                @if(!empty($candProfile['pwd_percentage']))
                                    <div class="text-muted-foreground">{{ $candProfile['pwd_percentage'] }}% {{ __('Disability') }}</div>
                                @endif
                            </div>

                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Admission Form No.') }}</span>
                                <div class="font-mono text-foreground font-bold">{{ $candForm['admission_form_number'] ?? 'N/A' }}</div>
                                <div class="text-muted-foreground">Roll: {{ $candForm['roll_no'] ?? 'N/A' }}</div>
                            </div>
                        </div>
                    @endif

                    {{-- Subtab: Addresses --}}
                    @if($dossierActiveTab === 'addresses')
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @forelse($candAddresses as $addr)
                                <div class="p-4 rounded-xl border border-border bg-muted/20 space-y-2">
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-primary/10 text-primary uppercase">
                                        {{ $addr['address_type'] ?? 'Address' }}
                                    </span>
                                    <div class="text-xs font-semibold text-foreground">
                                        {{ $addr['address_line1'] }} {{ $addr['address_line2'] ? ', ' . $addr['address_line2'] : '' }}
                                    </div>
                                    <div class="text-[11px] text-muted-foreground space-y-0.5">
                                        <div>P.O: {{ $addr['post_office'] ?? 'N/A' }}, P.S: {{ $addr['police_station'] ?? 'N/A' }}</div>
                                        <div>Subdistrict: {{ $addr['subdistrict'] ?? 'N/A' }}</div>
                                        <div>District: {{ $addr['district'] ?? 'N/A' }}, State: {{ $addr['state'] ?? 'N/A' }}</div>
                                        <div class="font-mono font-bold text-foreground">PIN: {{ $addr['pin_code'] ?? 'N/A' }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="col-span-2 text-center py-6 text-muted-foreground">
                                    {{ __('No address records attached to this application.') }}
                                </div>
                            @endforelse
                        </div>
                    @endif

                    {{-- Subtab: Educations --}}
                    @if($dossierActiveTab === 'educations')
                        <div class="rounded-xl border border-border overflow-hidden">
                            <table class="w-full text-left text-xs border-collapse">
                                <thead>
                                    <tr class="bg-muted/40 text-[10px] uppercase font-bold text-muted-foreground border-b border-border">
                                        <th class="p-2.5">{{ __('Level') }}</th>
                                        <th class="p-2.5">{{ __('Board / University') }}</th>
                                        <th class="p-2.5">{{ __('Institute') }}</th>
                                        <th class="p-2.5">{{ __('Stream') }}</th>
                                        <th class="p-2.5">{{ __('Year') }}</th>
                                        <th class="p-2.5">{{ __('Marks / %') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse($candEducations as $edu)
                                        <tr>
                                            <td class="p-2.5 font-bold text-foreground">{{ $edu['level'] }}</td>
                                            <td class="p-2.5 text-muted-foreground">{{ $edu['board_university'] ?? 'N/A' }}</td>
                                            <td class="p-2.5 text-muted-foreground">{{ $edu['institute'] ?? 'N/A' }}</td>
                                            <td class="p-2.5 text-muted-foreground">{{ $edu['stream_subject'] ?? 'N/A' }}</td>
                                            <td class="p-2.5 font-mono">{{ $edu['passing_year'] ?? 'N/A' }}</td>
                                            <td class="p-2.5 font-semibold text-foreground">
                                                {{ $edu['percentage'] ? $edu['percentage'] . '%' : ($edu['marks_obtained'] ? $edu['marks_obtained'] . '/' . $edu['total_marks'] : 'N/A') }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="p-4 text-center text-muted-foreground">
                                                {{ __('No educational qualification records found.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- Subtab: Employments --}}
                    @if($dossierActiveTab === 'employments')
                        <div class="space-y-3">
                            @forelse($candEmployments as $emp)
                                <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-1">
                                    <div class="flex items-center justify-between">
                                        <h4 class="font-bold text-foreground text-xs">{{ $emp['employer_name'] }}</h4>
                                        <span class="text-[10px] text-muted-foreground font-mono">
                                            {{ $emp['from_date'] ?? 'N/A' }} &rarr; {{ $emp['to_date'] ?? 'Present' }}
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-primary font-medium">{{ $emp['designation'] ?? 'N/A' }} ({{ $emp['nature_of_employment'] ?? 'Full-time' }})</div>
                                    @if(!empty($emp['description']))
                                        <p class="text-[11px] text-muted-foreground mt-1">{{ $emp['description'] }}</p>
                                    @endif
                                </div>
                            @empty
                                <div class="text-center py-6 text-muted-foreground">
                                    {{ __('No employment history recorded for this candidate.') }}
                                </div>
                            @endforelse
                        </div>
                    @endif

                    {{-- Subtab: Documents --}}
                    @if($dossierActiveTab === 'documents')
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                            @forelse($candDocs as $doc)
                                <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-primary">
                                            {{ str_replace('_', ' ', $doc['type']) }}
                                        </span>
                                        @if(!empty($doc['verified']))
                                            <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-0.5">
                                                <x-icon name="check" class="h-3 w-3" />
                                                {{ __('Verified') }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="text-[11px] font-mono text-muted-foreground truncate" title="{{ $doc['file_path'] }}">
                                        {{ basename($doc['file_path']) }}
                                    </div>
                                    @if(!empty($doc['file_url']))
                                        <a
                                            href="{{ $doc['file_url'] }}"
                                            target="_blank"
                                            class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline font-semibold"
                                        >
                                            <x-icon name="eye" class="h-3 w-3" />
                                            <span>{{ __('Open Document') }}</span>
                                        </a>
                                    @endif
                                </div>
                            @empty
                                <div class="col-span-3 text-center py-6 text-muted-foreground">
                                    {{ __('No documents attached.') }}
                                </div>
                            @endforelse
                        </div>
                    @endif

                    {{-- Subtab: UPSC & Other --}}
                    @if($dossierActiveTab === 'upsc')
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Optional Subject') }}</span>
                                <div class="font-bold text-foreground text-xs">{{ $candOther['optional_subject'] ?? 'Not specified' }}</div>
                            </div>

                            <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('UPSC Attempts') }}</span>
                                <div class="font-semibold text-foreground text-xs">
                                    {{ __('Given: ') }}{{ $candOther['attempts_already_given'] ?? 0 }} &bull; {{ __('Left: ') }}{{ $candOther['attempts_left'] ?? 'N/A' }}
                                </div>
                            </div>

                            <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-1 col-span-2">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Extracurricular Activities & Hobbies') }}</span>
                                <div class="text-foreground text-xs">
                                    <strong>{{ __('Activities:') }}</strong> {{ is_array($candOther['activities'] ?? null) ? implode(', ', $candOther['activities']) : ($candOther['activities'] ?? 'None') }}
                                </div>
                                <div class="text-foreground text-xs mt-1">
                                    <strong>{{ __('Hobbies:') }}</strong> {{ is_array($candOther['hobbies'] ?? null) ? implode(', ', $candOther['hobbies']) : ($candOther['hobbies'] ?? 'None') }}
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Modal Footer --}}
                <div class="p-4 border-t border-border bg-muted/20 flex items-center justify-between">
                    <button
                        type="button"
                        wire:click="closeDossierModal"
                        class="px-4 py-1.5 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-2xs"
                    >
                        {{ __('Close Dossier') }}
                    </button>

                    <button
                        type="button"
                        wire:click="openSingleImportModal({{ array_search($activeCandidateDossier, $this->feedItems, true) }})"
                        class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-bold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
                    >
                        <x-icon name="download" class="h-3.5 w-3.5" />
                        <span>{{ __('Import This Candidate') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- MODAL 2: SINGLE IMPORT CONFIRMATION MODAL --}}
    {{-- ================================================================= --}}
    @if($showSingleImportModal && $candidateToImport)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs transition-opacity animate-fade-in">
            <div class="w-full max-w-md rounded-2xl bg-card border border-border p-6 shadow-2xl space-y-4 animate-scale-up">
                <div class="flex items-start gap-3">
                    <span class="p-2.5 rounded-xl bg-primary/10 text-primary shrink-0">
                        <x-icon name="download" class="h-5 w-5" />
                    </span>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Confirm Candidate Import & Enrollment') }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Import candidate details from OTR and create an official course enrollment in MIS.') }}
                        </p>
                    </div>
                </div>

                {{-- Candidate Details Box --}}
                <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-1 text-xs">
                    <div class="font-bold text-foreground text-sm">
                        {{ $candidateToImport['users']['name'] ?? 'Candidate' }}
                    </div>
                    <div class="text-muted-foreground">
                        {{ $candidateToImport['users']['email'] ?? '' }} &bull; {{ $candidateToImport['users']['mobile'] ?? '' }}
                    </div>
                    <div class="text-[11px] text-muted-foreground font-mono">
                        App No: {{ $candidateToImport['application']['application_number'] ?? 'N/A' }} | Form No: {{ $candidateToImport['admission']['admission_form']['admission_form_number'] ?? 'N/A' }}
                    </div>
                </div>

                {{-- Target Parameters Form --}}
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-semibold text-foreground mb-1">{{ __('Target Academic Course') }} <span class="text-destructive">*</span></label>
                        <select
                            wire:model.defer="targetCourseId"
                            class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs text-foreground shadow-xs outline-none focus:border-primary"
                        >
                            <option value="">{{ __('-- Select Course --') }}</option>
                            @foreach($this->courses as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold text-foreground mb-1">{{ __('Target Academic Batch') }} <span class="text-destructive">*</span></label>
                        <select
                            wire:model.defer="targetBatchId"
                            class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs text-foreground shadow-xs outline-none focus:border-primary"
                        >
                            <option value="">{{ __('-- Select Batch --') }}</option>
                            @foreach($this->batches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Fee Status') }}</label>
                            <select
                                wire:model.defer="targetFeeStatus"
                                class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs text-foreground shadow-xs outline-none focus:border-primary"
                            >
                                <option value="paid">{{ __('Paid') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="partial">{{ __('Partial') }}</option>
                                <option value="exempted">{{ __('Exempted') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Section') }}</label>
                            <select
                                wire:model.defer="targetSectionId"
                                class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs text-foreground shadow-xs outline-none focus:border-primary"
                            >
                                <option value="">{{ __('Auto Assign') }}</option>
                                @foreach($this->sections as $sec)
                                    <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="pt-2 space-y-2 border-t border-border">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.defer="downloadDocuments"
                                class="rounded border-input text-primary focus:ring-primary/20 h-4 w-4"
                            />
                            <span class="text-[11px] text-muted-foreground">{{ __('Download & save binary files to MIS storage') }}</span>
                        </label>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.defer="updateExistingProfiles"
                                class="rounded border-input text-primary focus:ring-primary/20 h-4 w-4"
                            />
                            <span class="text-[11px] text-muted-foreground">{{ __('Update profile dossier if candidate already exists in MIS') }}</span>
                        </label>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                    <button
                        type="button"
                        wire:click="closeSingleImportModal"
                        class="px-3.5 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-2xs"
                    >
                        {{ __('Cancel') }}
                    </button>

                    <button
                        type="button"
                        wire:click="confirmSingleImport"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-sm disabled:opacity-50"
                    >
                        <x-icon name="check" class="h-3.5 w-3.5" wire:loading.remove wire:target="confirmSingleImport" />
                        <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="confirmSingleImport" />
                        {{ __('Confirm & Enroll') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- MODAL 3: BULK IMPORT CONFIRMATION MODAL --}}
    {{-- ================================================================= --}}
    @if($showBulkImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs transition-opacity animate-fade-in">
            <div class="w-full max-w-md rounded-2xl bg-card border border-border p-6 shadow-2xl space-y-4 animate-scale-up">
                <div class="flex items-start gap-3">
                    <span class="p-2.5 rounded-xl bg-primary/10 text-primary shrink-0">
                        <x-icon name="layers" class="h-5 w-5" />
                    </span>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Execute Bulk Candidate Import') }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Import and enroll all :count selected candidates into MIS.', ['count' => count($selectedCandidateKeys)]) }}
                        </p>
                    </div>
                </div>

                <div class="p-4 rounded-xl border border-primary/20 bg-primary/5 space-y-2 text-xs">
                    <div class="flex items-center justify-between font-bold text-foreground">
                        <span>{{ __('Selected Candidates Count') }}</span>
                        <span class="font-mono text-sm px-2 py-0.5 rounded bg-primary text-primary-foreground">
                            {{ count($selectedCandidateKeys) }}
                        </span>
                    </div>
                    <p class="text-muted-foreground text-[11px] leading-relaxed">
                        {{ __('Each candidate will be imported inside an individual transaction. Students not yet in MIS will be created with their 9-digit Student ID, profile dossier, addresses, and enrollments. Candidates who already exist will receive a new course enrollment.') }}
                    </p>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-semibold text-foreground mb-1">{{ __('Target Course') }} <span class="text-destructive">*</span></label>
                        <select
                            wire:model.defer="targetCourseId"
                            class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs text-foreground shadow-xs outline-none focus:border-primary"
                        >
                            @foreach($this->courses as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold text-foreground mb-1">{{ __('Target Batch') }} <span class="text-destructive">*</span></label>
                        <select
                            wire:model.defer="targetBatchId"
                            class="h-9 w-full rounded-lg border border-input bg-card px-2.5 text-xs text-foreground shadow-xs outline-none focus:border-primary"
                        >
                            @foreach($this->batches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                    <button
                        type="button"
                        wire:click="closeBulkImportModal"
                        class="px-3.5 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-2xs"
                    >
                        {{ __('Cancel') }}
                    </button>

                    <button
                        type="button"
                        wire:click="executeBulkImport"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-sm disabled:opacity-50"
                    >
                        <x-icon name="download" class="h-3.5 w-3.5" wire:loading.remove wire:target="executeBulkImport" />
                        <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="executeBulkImport" />
                        {{ __('Start Bulk Import (:count)', ['count' => count($selectedCandidateKeys)]) }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- MODAL 4: IMPORT RESULTS MODAL --}}
    {{-- ================================================================= --}}
    @if($showResultsModal && $importSummaryResult)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs transition-opacity animate-fade-in">
            <div class="w-full max-w-lg rounded-2xl bg-card border border-border p-6 shadow-2xl space-y-4 animate-scale-up">
                <div class="flex items-start gap-3">
                    <span class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 shrink-0">
                        <x-icon name="check-circle" class="h-5 w-5" />
                    </span>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Import Results & Summary') }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Candidate synchronization process finished successfully.') }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-center text-xs">
                    <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                        <span class="text-[10px] uppercase text-muted-foreground font-bold">{{ __('Total') }}</span>
                        <div class="text-lg font-bold font-mono text-foreground">{{ $importSummaryResult['total'] ?? 0 }}</div>
                    </div>
                    <div class="p-3 rounded-xl border border-emerald-500/20 bg-emerald-500/10 space-y-1">
                        <span class="text-[10px] uppercase text-emerald-600 dark:text-emerald-400 font-bold">{{ __('New Students') }}</span>
                        <div class="text-lg font-bold font-mono text-emerald-600 dark:text-emerald-400">{{ $importSummaryResult['created_and_enrolled'] ?? 0 }}</div>
                    </div>
                    <div class="p-3 rounded-xl border border-amber-500/20 bg-amber-500/10 space-y-1">
                        <span class="text-[10px] uppercase text-amber-600 dark:text-amber-400 font-bold">{{ __('Multi-Course') }}</span>
                        <div class="text-lg font-bold font-mono text-amber-600 dark:text-amber-400">{{ $importSummaryResult['existing_enrolled'] ?? 0 }}</div>
                    </div>
                    <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-1">
                        <span class="text-[10px] uppercase text-muted-foreground font-bold">{{ __('Skipped') }}</span>
                        <div class="text-lg font-bold font-mono text-muted-foreground">{{ $importSummaryResult['already_enrolled'] ?? 0 }}</div>
                    </div>
                </div>

                @if(!empty($importSummaryResult['errors']))
                    <div class="p-3.5 rounded-xl border border-rose-500/20 bg-rose-500/10 space-y-2 text-xs">
                        <span class="font-bold text-rose-600 dark:text-rose-400 flex items-center gap-1.5">
                            <x-icon name="alert-circle" class="h-4 w-4" />
                            {{ __('Notices & Errors (:count)', ['count' => count($importSummaryResult['errors'])]) }}
                        </span>
                        <ul class="space-y-1 max-h-32 overflow-y-auto text-[11px] text-rose-950 dark:text-rose-200">
                            @foreach($importSummaryResult['errors'] as $err)
                                <li>&bull; {{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                    <button
                        type="button"
                        wire:click="$set('showResultsModal', false)"
                        class="px-4 py-2 text-xs font-bold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
                    >
                        {{ __('Done') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================================================= --}}
    {{-- MODAL 5: TEST CONNECTION MODAL --}}
    {{-- ================================================================= --}}
    @if($showTestModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs transition-opacity animate-fade-in">
            <div class="w-full max-w-md rounded-2xl bg-card border border-border p-6 shadow-2xl space-y-4 animate-scale-up">
                <div class="flex items-start gap-3">
                    <span class="p-2.5 rounded-xl bg-primary/10 text-primary shrink-0">
                        <x-icon name="activity" class="h-5 w-5" />
                    </span>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('OTR API Diagnostics & Test') }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ __('Verifying connectivity and credential scopes with Admission OTR.') }}
                        </p>
                    </div>
                </div>

                @if($isTestingConnection)
                    <div class="p-8 text-center space-y-3">
                        <x-icon name="refresh-cw" class="h-8 w-8 animate-spin mx-auto text-primary" />
                        <p class="text-xs font-medium text-foreground">{{ __('Pinging Admission OTR Portal...') }}</p>
                    </div>
                @elseif($testConnectionResult)
                    <div class="p-4 rounded-xl border {{ $testConnectionResult['success'] ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-950 dark:text-emerald-200' : 'bg-rose-500/10 border-rose-500/30 text-rose-950 dark:text-rose-200' }} text-xs space-y-2">
                        <div class="flex items-center justify-between font-bold">
                            <span class="flex items-center gap-1.5">
                                <x-icon name="{{ $testConnectionResult['success'] ? 'check' : 'alert-circle' }}" class="h-4 w-4" />
                                {{ $testConnectionResult['success'] ? __('Connected (HTTP ' . $testConnectionResult['status'] . ')') : __('Test Failed') }}
                            </span>
                            <span class="font-mono text-[11px] px-2 py-0.5 rounded bg-card/60 border border-border">
                                {{ $testConnectionResult['latency_ms'] }} ms
                            </span>
                        </div>
                        <p class="text-[11px] leading-relaxed">{{ $testConnectionResult['message'] }}</p>

                        @if(!empty($testConnectionResult['data']))
                            <div class="mt-2 pt-2 border-t border-border/40 space-y-1 text-[11px]">
                                <div><span class="opacity-70">{{ __('Key Preview:') }}</span> <strong class="font-mono">{{ $testConnectionResult['data']['api_key']['preview'] ?? 'N/A' }}</strong></div>
                                <div><span class="opacity-70">{{ __('Scopes:') }}</span> <strong class="font-mono">{{ implode(', ', $testConnectionResult['data']['api_key']['scopes'] ?? []) }}</strong></div>
                                <div><span class="opacity-70">{{ __('Server Time:') }}</span> <strong>{{ $testConnectionResult['data']['server_time'] ?? 'N/A' }}</strong></div>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                    <button
                        type="button"
                        wire:click="$set('showTestModal', false)"
                        class="px-4 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted transition-colors shadow-2xs"
                    >
                        {{ __('Close') }}
                    </button>

                    <button
                        type="button"
                        wire:click="runTestConnection"
                        class="px-4 py-2 text-xs font-bold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
                    >
                        {{ __('Test Again') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
