<?php

use App\Models\ApiKey;
use App\Models\ApiKeyLog;
use App\Services\ApiKey\ApiKeyService;
use App\Support\Toast;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('API Key Management - SNTCSSC MIS')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'env')]
    public string $environmentFilter = '';

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';
    public int $perPage = 15;

    // Create Modal State
    public bool $showCreateModal = false;
    public array $createForm = [
        'name' => '',
        'type' => 'external_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'expires_preset' => '90_days',
        'custom_expires_at' => '',
        'ip_allowlist' => '',
        'allowed_domains' => '',
        'description' => '',
        'scopes' => ['ping', 'read:academics', 'read:students', 'read:materials'],
    ];

    // Secret Key Reveal Modal (Shown ONCE only)
    public bool $showKeyRevealModal = false;
    public string $revealedSecret = '';
    public string $revealedPrefix = '';
    public string $revealedName = '';
    public string $revealedQrUri = '';
    public string $revealedCurl = '';

    // Edit Modal State
    public bool $showEditModal = false;
    public ?int $editingId = null;
    public array $editForm = [
        'name' => '',
        'type' => 'external_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'expires_at' => '',
        'ip_allowlist' => '',
        'allowed_domains' => '',
        'description' => '',
        'scopes' => [],
    ];

    // Rotate Secret Modal State
    public bool $showRotateModal = false;
    public ?int $rotatingId = null;
    public string $rotatingName = '';

    // Revoke Modal State
    public bool $showRevokeModal = false;
    public ?int $revokingId = null;
    public string $revokingName = '';
    public string $revocationReason = '';

    // Delete / Purge Modal State
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';
    public bool $isPermanentDelete = false;

    // Quick Test Modal State
    public bool $showTestModal = false;
    public ?int $testingKeyId = null;
    public string $testEndpoint = 'ping';
    public ?array $testResponse = null;
    public ?int $testStatusCode = null;
    public ?float $testDurationMs = null;
    public bool $isTesting = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEnvironmentFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'desc';
        }
    }

    #[Computed]
    public function stats(): array
    {
        return app(ApiKeyService::class)->getDashboardStatistics();
    }

    #[Computed]
    public function availableScopes(): array
    {
        return ApiKeyService::getAvailableScopes();
    }

    #[Computed]
    public function apiKeys()
    {
        $query = ApiKey::query()->with(['user:id,name,email', 'creator:id,name']);

        if ($this->statusFilter === 'trashed') {
            $query->onlyTrashed();
        } else {
            if ($this->statusFilter === 'active') {
                $query->where('status', ApiKey::STATUS_ACTIVE)
                    ->where(function (Builder $q) {
                        $q->whereNull('expires_at')
                            ->orWhere('expires_at', '>', Carbon::now());
                    });
            } elseif ($this->statusFilter === 'suspended') {
                $query->where('status', ApiKey::STATUS_SUSPENDED);
            } elseif ($this->statusFilter === 'revoked') {
                $query->where('status', ApiKey::STATUS_REVOKED);
            } elseif ($this->statusFilter === 'expired') {
                $query->whereNotNull('expires_at')
                    ->where('expires_at', '<=', Carbon::now());
            }
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->environmentFilter) {
            $query->where('environment', $this->environmentFilter);
        }

        if ($this->search) {
            $query->search($this->search);
        }

        return $query->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function openCreateModal(): void
    {
        $this->createForm = [
            'name' => '',
            'type' => 'external_app',
            'environment' => 'production',
            'rate_limit_per_minute' => 60,
            'expires_preset' => '90_days',
            'custom_expires_at' => '',
            'ip_allowlist' => '',
            'allowed_domains' => '',
            'description' => '',
            'scopes' => ['ping', 'read:academics', 'read:students', 'read:materials'],
        ];
        $this->resetErrorBag();
        $this->showCreateModal = true;
    }

    public function selectAllScopes(): void
    {
        $all = [];
        foreach ($this->availableScopes as $module => $scopes) {
            foreach ($scopes as $key => $desc) {
                $all[] = $key;
            }
        }
        $this->createForm['scopes'] = array_unique($all);
    }

    public function clearAllScopes(): void
    {
        $this->createForm['scopes'] = ['ping'];
    }

    public function toggleScopeGroup(string $module): void
    {
        $moduleScopes = array_keys($this->availableScopes[$module] ?? []);
        $allSelected = count(array_intersect($moduleScopes, $this->createForm['scopes'])) === count($moduleScopes);

        if ($allSelected) {
            $this->createForm['scopes'] = array_values(array_diff($this->createForm['scopes'], $moduleScopes));
        } else {
            $this->createForm['scopes'] = array_values(array_unique(array_merge($this->createForm['scopes'], $moduleScopes)));
        }
    }

    public function createKey(ApiKeyService $service): void
    {
        $this->validate([
            'createForm.name' => 'required|string|min:3|max:100',
            'createForm.type' => 'required|string|in:mobile_app,desktop_app,external_app,service,testing',
            'createForm.environment' => 'required|string|in:production,sandbox',
            'createForm.rate_limit_per_minute' => 'required|integer|min:1|max:10000',
            'createForm.scopes' => 'required|array|min:1',
            'createForm.description' => 'nullable|string|max:500',
        ]);

        $expiresAt = match ($this->createForm['expires_preset']) {
            '30_days' => Carbon::now()->addDays(30),
            '60_days' => Carbon::now()->addDays(60),
            '90_days' => Carbon::now()->addDays(90),
            '1_year' => Carbon::now()->addYear(),
            'custom' => ! empty($this->createForm['custom_expires_at']) ? Carbon::parse($this->createForm['custom_expires_at']) : null,
            default => null,
        };

        $result = $service->generateKey([
            'name' => $this->createForm['name'],
            'type' => $this->createForm['type'],
            'environment' => $this->createForm['environment'],
            'rate_limit_per_minute' => $this->createForm['rate_limit_per_minute'],
            'expires_at' => $expiresAt,
            'ip_allowlist' => $this->createForm['ip_allowlist'],
            'allowed_domains' => $this->createForm['allowed_domains'],
            'description' => $this->createForm['description'],
            'scopes' => $this->createForm['scopes'],
        ]);

        $this->showCreateModal = false;

        // Prepare Key Reveal Modal
        $this->revealedSecret = $result['plainTextKey'];
        $this->revealedPrefix = $result['apiKey']->key_prefix;
        $this->revealedName = $result['apiKey']->name;

        // Generate QR code data URI
        try {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'imageBase64' => true,
            ]);
            $this->revealedQrUri = (new QRCode($options))->render($this->revealedSecret);
        } catch (\Throwable $e) {
            $this->revealedQrUri = '';
        }

        $apiUrl = url('/api/v1/ping');
        $this->revealedCurl = "curl -X GET \"{$apiUrl}\" \\\n  -H \"X-API-Key: {$this->revealedSecret}\" \\\n  -H \"Accept: application/json\"";

        $this->showKeyRevealModal = true;
        Toast::dispatch($this, 'success', __('API Key generated successfully! Save your secret key now.'));
    }

    public function openEditModal(int $id): void
    {
        $key = ApiKey::findOrFail($id);
        $this->editingId = $id;

        $this->editForm = [
            'name' => $key->name,
            'type' => $key->type,
            'environment' => $key->environment,
            'rate_limit_per_minute' => $key->rate_limit_per_minute,
            'expires_at' => $key->expires_at?->format('Y-m-d\TH:i'),
            'ip_allowlist' => is_array($key->ip_allowlist) ? implode("\n", $key->ip_allowlist) : '',
            'allowed_domains' => is_array($key->allowed_domains) ? implode("\n", $key->allowed_domains) : '',
            'description' => $key->description ?? '',
            'scopes' => $key->scopes ?? [],
        ];

        $this->resetErrorBag();
        $this->showEditModal = true;
    }

    public function updateKey(ApiKeyService $service): void
    {
        $this->validate([
            'editForm.name' => 'required|string|min:3|max:100',
            'editForm.type' => 'required|string|in:mobile_app,desktop_app,external_app,service,testing',
            'editForm.environment' => 'required|string|in:production,sandbox',
            'editForm.rate_limit_per_minute' => 'required|integer|min:1|max:10000',
            'editForm.scopes' => 'required|array|min:1',
            'editForm.description' => 'nullable|string|max:500',
        ]);

        $key = ApiKey::findOrFail($this->editingId);

        $service->updateKey($key, [
            'name' => $this->editForm['name'],
            'type' => $this->editForm['type'],
            'environment' => $this->editForm['environment'],
            'rate_limit_per_minute' => $this->editForm['rate_limit_per_minute'],
            'expires_at' => $this->editForm['expires_at'] ?: null,
            'ip_allowlist' => $this->editForm['ip_allowlist'],
            'allowed_domains' => $this->editForm['allowed_domains'],
            'description' => $this->editForm['description'],
            'scopes' => $this->editForm['scopes'],
        ]);

        $this->showEditModal = false;
        $this->editingId = null;
        Toast::dispatch($this, 'success', __('API Key configuration updated successfully!'));
    }

    public function toggleStatus(int $id, ApiKeyService $service): void
    {
        $key = ApiKey::findOrFail($id);
        $service->toggleStatus($key);
        $statusText = $key->fresh()->status === ApiKey::STATUS_ACTIVE ? 'activated' : 'suspended';
        Toast::dispatch($this, 'info', __("API Key status changed to {$statusText}."));
    }

    public function openRotateModal(int $id): void
    {
        $key = ApiKey::findOrFail($id);
        $this->rotatingId = $id;
        $this->rotatingName = $key->name;
        $this->showRotateModal = true;
    }

    public function confirmRotate(ApiKeyService $service): void
    {
        $key = ApiKey::findOrFail($this->rotatingId);
        $result = $service->rotateKey($key);

        $this->showRotateModal = false;
        $this->rotatingId = null;

        // Reveal new secret key
        $this->revealedSecret = $result['plainTextKey'];
        $this->revealedPrefix = $result['apiKey']->key_prefix;
        $this->revealedName = $result['apiKey']->name;

        try {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'imageBase64' => true,
            ]);
            $this->revealedQrUri = (new QRCode($options))->render($this->revealedSecret);
        } catch (\Throwable $e) {
            $this->revealedQrUri = '';
        }

        $apiUrl = url('/api/v1/ping');
        $this->revealedCurl = "curl -X GET \"{$apiUrl}\" \\\n  -H \"X-API-Key: {$this->revealedSecret}\" \\\n  -H \"Accept: application/json\"";

        $this->showKeyRevealModal = true;
        Toast::dispatch($this, 'success', __('Secret key rotated successfully! Save the new secret key now.'));
    }

    public function openRevokeModal(int $id): void
    {
        $key = ApiKey::findOrFail($id);
        $this->revokingId = $id;
        $this->revokingName = $key->name;
        $this->revocationReason = '';
        $this->showRevokeModal = true;
    }

    public function confirmRevoke(ApiKeyService $service): void
    {
        $key = ApiKey::findOrFail($this->revokingId);
        $service->revokeKey($key, $this->revocationReason);

        $this->showRevokeModal = false;
        $this->revokingId = null;
        Toast::dispatch($this, 'warning', __('API Key has been revoked immediately.'));
    }

    public function openDeleteModal(int $id, bool $permanent = false): void
    {
        $key = $permanent ? ApiKey::withTrashed()->findOrFail($id) : ApiKey::findOrFail($id);
        $this->deletingId = $id;
        $this->deletingName = $key->name;
        $this->isPermanentDelete = $permanent;
        $this->showDeleteModal = true;
    }

    public function confirmDelete(ApiKeyService $service): void
    {
        if ($this->isPermanentDelete) {
            $key = ApiKey::withTrashed()->findOrFail($this->deletingId);
            $service->forceDeleteKey($key);
            Toast::dispatch($this, 'error', __('API Key and telemetry records purged permanently.'));
        } else {
            $key = ApiKey::findOrFail($this->deletingId);
            $service->deleteKey($key);
            Toast::dispatch($this, 'info', __('API Key moved to trash.'));
        }

        $this->showDeleteModal = false;
        $this->deletingId = null;
    }

    public function restoreKey(int $id, ApiKeyService $service): void
    {
        $service->restoreKey($id);
        Toast::dispatch($this, 'success', __('API Key restored from trash.'));
    }

    public function openTestModal(int $id): void
    {
        $this->testingKeyId = $id;
        $this->testEndpoint = 'ping';
        $this->testResponse = null;
        $this->testStatusCode = null;
        $this->testDurationMs = null;
        $this->showTestModal = true;
    }

    public function runQuickTest(): void
    {
        $key = ApiKey::findOrFail($this->testingKeyId);
        $this->isTesting = true;

        $startTime = microtime(true);

        try {
            // Internal request execution with mock/bypass
            $endpointPath = match ($this->testEndpoint) {
                'ping' => '/api/v1/ping',
                'app-info' => '/api/v1/app/info',
                'courses' => '/api/v1/courses',
                'students-dashboard' => '/api/v1/students/dashboard',
                'students-stats' => '/api/v1/students/statistics',
                'students' => '/api/v1/students?per_page=5',
                'students-batches' => '/api/v1/students/batches',
                'students-courses' => '/api/v1/students/courses',
                'students-sections' => '/api/v1/students/sections',
                'students-profile-requests' => '/api/v1/students/profile-requests',
                'enrollments' => '/api/v1/enrollments?per_page=5',
                'study-materials' => '/api/v1/study-materials?per_page=5',
                'study-materials-types' => '/api/v1/study-materials/types',
                'study-materials-stats' => '/api/v1/study-materials/statistics',
                'study-materials-my' => '/api/v1/study-materials/my-materials',
                'study-materials-detail' => '/api/v1/study-materials/1',
                'study-materials-download-token' => '/api/v1/study-materials/1/download-token',
                'study-materials-external' => '/api/v1/external/study-materials?per_page=5',
                'study-materials-external-types' => '/api/v1/external/study-materials/types',
                'settings-public' => '/api/v1/settings/public',
                'settings-bootstrap-mobile' => '/api/v1/settings/bootstrap/mobile',
                'settings-features' => '/api/v1/settings/features',
                'settings-storage-overview' => '/api/v1/settings/storage/overview',
                'settings-groups' => '/api/v1/settings/groups',
                'settings-stats' => '/api/v1/settings/statistics',
                'settings-list' => '/api/v1/settings?per_page=10',
                'settings-general' => '/api/v1/settings/groups/general',
                'settings-appearance' => '/api/v1/settings/groups/appearance',
                'settings-localization' => '/api/v1/settings/groups/localization',
                'settings-system' => '/api/v1/settings/groups/system',
                'settings-single' => '/api/v1/settings/item/general.site_name',
                'chat-status' => '/api/v1/chat/status',
                'chat-ice' => '/api/v1/chat/calls/ice-servers',
                'chat-conversations' => '/api/v1/chat/conversations',
                'chat-meetings' => '/api/v1/chat/meetings',
                'tickets-dashboard' => '/api/v1/tickets/dashboard',
                'tickets-stats' => '/api/v1/tickets/statistics',
                'tickets-list' => '/api/v1/tickets?per_page=5',
                'tickets-my' => '/api/v1/tickets/my-tickets',
                'tickets-categories' => '/api/v1/tickets/categories',
                'tickets-canned-responses' => '/api/v1/tickets/canned-responses',
                'tickets-detail' => '/api/v1/tickets/1',
                'tickets-messages' => '/api/v1/tickets/1/messages',
                'tickets-export' => '/api/v1/tickets/export',
                'communications-dashboard' => '/api/v1/communications/dashboard',
                'communications-stats' => '/api/v1/communications/statistics',
                'communications-logs' => '/api/v1/communications/logs?per_page=5',
                'communications-campaigns' => '/api/v1/communications/campaigns?per_page=5',
                'communications-subscribers' => '/api/v1/communications/subscribers?per_page=5',
                'communications-templates-email' => '/api/v1/communications/templates/email',
                'communications-templates-sms' => '/api/v1/communications/templates/sms',
                'communications-export' => '/api/v1/communications/export?limit=5',
                'alumni' => '/api/v1/alumni?per_page=5',
                'library-dashboard' => '/api/v1/library/dashboard',
                'library-stats' => '/api/v1/library/statistics',
                'library-books' => '/api/v1/library/books?per_page=5',
                'library-catalog' => '/api/v1/library/catalog',
                'exam-dashboard' => '/api/v1/examinations/dashboard',
                'exam-centers' => '/api/v1/examinations/centers',
                'hostel-dashboard' => '/api/v1/hostel/dashboard',
                'hostel-visual-board' => '/api/v1/hostel/visual-board',
                'hostel-buildings' => '/api/v1/hostel/buildings',
                'hostel-rooms' => '/api/v1/hostel/rooms?per_page=5',
                'hostel-allocations' => '/api/v1/hostel/allocations?per_page=5',
                'hostel-policies' => '/api/v1/hostel/policies',
                'hostel-occupancy' => '/api/v1/hostel/reports/occupancy',
                'attendance-dashboard' => '/api/v1/attendance/dashboard',
                'attendance-stats' => '/api/v1/attendance/statistics',
                'attendance-register' => '/api/v1/attendance?per_page=5',
                'attendance-defaulters' => '/api/v1/attendance/defaulters',
                'attendance-holidays' => '/api/v1/attendance/holidays',
                'attendance-calendars' => '/api/v1/attendance/calendars',
                'attendance-devices' => '/api/v1/attendance/devices',
                'leaves-dashboard' => '/api/v1/leaves/dashboard',
                'leaves-stats' => '/api/v1/leaves/statistics',
                'leaves-categories' => '/api/v1/leaves/categories',
                'leaves-allocations' => '/api/v1/leaves/allocations?per_page=5',
                'leaves-applications' => '/api/v1/leaves/applications?per_page=5',
                'payments-dashboard' => '/api/v1/payments/dashboard',
                'payments-stats' => '/api/v1/payments/statistics',
                'payments-transactions' => '/api/v1/payments/transactions?per_page=5',
                'users-dashboard' => '/api/v1/users/dashboard',
                'users-stats' => '/api/v1/users/statistics',
                'users-list' => '/api/v1/users?per_page=5',
                'users-roles' => '/api/v1/roles',
                'users-permissions' => '/api/v1/permissions/modules',
                'users-me' => '/api/v1/user/me',
                'users-audit-logs' => '/api/v1/security/audit-logs?per_page=10',
                default => '/api/v1/ping',
            };

            // Call internally via Application request
            $subRequest = \Illuminate\Http\Request::create(
                $endpointPath,
                'GET',
                [],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_USER_AGENT' => 'SNTCSSC-Console/1.0',
                    'REMOTE_ADDR' => '127.0.0.1',
                ]
            );

            // Mock active key attribute directly
            $subRequest->attributes->set('api_key', $key);

            $response = app()->handle($subRequest);

            $this->testDurationMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->testStatusCode = $response->getStatusCode();
            $this->testResponse = json_decode($response->getContent(), true) ?: ['raw' => $response->getContent()];
        } catch (\Throwable $e) {
            $this->testDurationMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->testStatusCode = 500;
            $this->testResponse = [
                'success' => false,
                'error' => [
                    'code' => 'TEST_EXECUTION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ];
        } finally {
            $this->isTesting = false;
        }
    }
};
?>

<div class="space-y-6">
    <!-- Top Header & Actions -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="p-2 bg-primary/10 text-primary rounded-xl">
                    <x-icon name="key" class="w-6 h-6" />
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-foreground tracking-tight">{{ __('API Key Management') }}</h1>
                    <p class="text-xs sm:text-sm text-muted-foreground">{{ __('Secure token authentication & scopes for external web, mobile, and desktop applications.') }}</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.api-keys.logs') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="activity" class="w-4 h-4 text-emerald-500" />
                <span>{{ __('Telemetry Logs') }}</span>
            </a>

            <a href="{{ route('admin.api-keys.docs') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="terminal" class="w-4 h-4 text-primary" />
                <span>{{ __('API Console & Docs') }}</span>
            </a>

            @can('api_keys.create')
                <button wire:click="openCreateModal" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('Generate API Key') }}</span>
                </button>
            @endcan
        </div>
    </div>

    <!-- Real-Time Metrics & Statistics Bar -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Total Keys -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Total API Keys') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['total_keys']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-primary/10 text-primary">
                    <x-icon name="key" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                <span class="text-emerald-500 font-semibold">{{ $this->stats['active_keys'] }} active</span>
                <span>•</span>
                <span>{{ $this->stats['suspended_keys'] }} paused</span>
            </div>
        </div>

        <!-- 24h Traffic -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Requests (24h)') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['requests_24h']) }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-500">
                    <x-icon name="activity" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span>{{ number_format($this->stats['total_calls']) }} {{ __('all-time calls') }}</span>
            </div>
        </div>

        <!-- Success Rate -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Success Rate') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['success_rate'] }}%</p>
                </div>
                <div class="p-2.5 rounded-xl bg-sky-500/10 text-sky-500">
                    <x-icon name="shield-check" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span>{{ __('HTTP 2xx response ratio') }}</span>
            </div>
        </div>

        <!-- Average Latency -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Avg Latency (24h)') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['avg_latency_ms'] }} <span class="text-xs font-normal text-muted-foreground">ms</span></p>
                </div>
                <div class="p-2.5 rounded-xl bg-amber-500/10 text-amber-500">
                    <x-icon name="zap" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                <span>{{ __('End-to-end response time') }}</span>
            </div>
        </div>

        <!-- Revoked / Expired -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Revoked / Expired') }}</span>
                    <p class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['revoked_keys'] + $this->stats['expired_keys'] }}</p>
                </div>
                <div class="p-2.5 rounded-xl bg-destructive/10 text-destructive">
                    <x-icon name="shield-alert" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                <span>{{ $this->stats['revoked_keys'] }} revoked</span>
                <span>•</span>
                <span>{{ $this->stats['expired_keys'] }} expired</span>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="bg-card rounded-xl border border-border p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-3">
        <!-- Search bar -->
        <div class="relative flex-1">
            <x-icon name="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('Search by client name, prefix, or description...') }}"
                   class="w-full pl-9 pr-4 py-2 text-xs rounded-lg border border-border bg-background text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
            @if($search)
                <button wire:click="$set('search', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                    <x-icon name="x" class="w-3.5 h-3.5" />
                </button>
            @endif
        </div>

        <!-- Filter Selectors -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Status Filter -->
            <select wire:model.live="statusFilter" class="px-2.5 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="">{{ __('All Statuses') }}</option>
                <option value="active">{{ __('Active Only') }}</option>
                <option value="suspended">{{ __('Suspended') }}</option>
                <option value="revoked">{{ __('Revoked') }}</option>
                <option value="expired">{{ __('Expired') }}</option>
                <option value="trashed">{{ __('Trashed') }}</option>
            </select>

            <!-- Type Filter -->
            <select wire:model.live="typeFilter" class="px-2.5 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="">{{ __('All Client Types') }}</option>
                <option value="mobile_app">{{ __('Mobile App (iOS/Android)') }}</option>
                <option value="desktop_app">{{ __('Desktop App') }}</option>
                <option value="external_app">{{ __('External Web App') }}</option>
                <option value="service">{{ __('Backend Service / Microservice') }}</option>
                <option value="testing">{{ __('Testing / Sandbox') }}</option>
            </select>

            <!-- Environment Filter -->
            <select wire:model.live="environmentFilter" class="px-2.5 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="">{{ __('All Environments') }}</option>
                <option value="production">{{ __('Production') }}</option>
                <option value="sandbox">{{ __('Sandbox / Testing') }}</option>
            </select>

            <!-- Per Page -->
            <select wire:model.live="perPage" class="px-2 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                <option value="15">15 / page</option>
                <option value="30">30 / page</option>
                <option value="50">50 / page</option>
            </select>
        </div>
    </div>

    <!-- API Keys Table Card -->
    <div class="bg-card rounded-xl border border-border shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-muted-foreground font-semibold">
                        <th class="py-3 px-4 cursor-pointer hover:text-foreground" wire:click="sortBy('name')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Client Name & Prefix') }}</span>
                                @if($sortField === 'name')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3.5 h-3.5" />
                                @endif
                            </div>
                        </th>
                        <th class="py-3 px-4">{{ __('Type & Env') }}</th>
                        <th class="py-3 px-4">{{ __('Rate Limit') }}</th>
                        <th class="py-3 px-4">{{ __('Granted Scopes') }}</th>
                        <th class="py-3 px-4 cursor-pointer hover:text-foreground" wire:click="sortBy('last_used_at')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Last Activity') }}</span>
                                @if($sortField === 'last_used_at')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3.5 h-3.5" />
                                @endif
                            </div>
                        </th>
                        <th class="py-3 px-4 cursor-pointer hover:text-foreground" wire:click="sortBy('expires_at')">
                            <div class="flex items-center gap-1">
                                <span>{{ __('Expiration') }}</span>
                                @if($sortField === 'expires_at')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3.5 h-3.5" />
                                @endif
                            </div>
                        </th>
                        <th class="py-3 px-4">{{ __('Status') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($this->apiKeys as $key)
                        <tr class="hover:bg-muted/30 transition-colors {{ $key->trashed() ? 'opacity-60 bg-muted/10' : '' }}">
                            <!-- Client Name & Prefix -->
                            <td class="py-3.5 px-4">
                                <div class="flex items-start gap-2.5">
                                    <div class="p-2 rounded-lg mt-0.5 {{ $key->environment === 'production' ? 'bg-primary/10 text-primary' : 'bg-amber-500/10 text-amber-500' }}">
                                        @if($key->type === 'mobile_app')
                                            <x-icon name="smartphone" class="w-4 h-4" />
                                        @elseif($key->type === 'desktop_app')
                                            <x-icon name="laptop" class="w-4 h-4" />
                                        @elseif($key->type === 'service')
                                            <x-icon name="cpu" class="w-4 h-4" />
                                        @else
                                            <x-icon name="globe" class="w-4 h-4" />
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-semibold text-foreground flex items-center gap-1.5">
                                            <span>{{ $key->name }}</span>
                                            @if($key->ip_allowlist && count($key->ip_allowlist) > 0)
                                                <span class="inline-flex items-center text-[10px] px-1.5 py-0.5 rounded bg-muted text-muted-foreground font-mono" title="{{ implode(', ', $key->ip_allowlist) }}">
                                                    <x-icon name="lock" class="w-2.5 h-2.5 mr-0.5 text-emerald-500" />
                                                    IP Locked ({{ count($key->ip_allowlist) }})
                                                </span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <code class="px-1.5 py-0.5 rounded bg-muted font-mono text-[11px] text-muted-foreground select-all">{{ $key->key_prefix }}</code>
                                            <span class="text-[11px] text-muted-foreground">• {{ $key->total_requests }} calls</span>
                                        </div>
                                        @if($key->description)
                                            <p class="text-[11px] text-muted-foreground/80 mt-1 line-clamp-1">{{ $key->description }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <!-- Type & Env -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                <div class="space-y-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium capitalize {{ $key->environment === 'production' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400' }}">
                                        {{ $key->environment }}
                                    </span>
                                    <div class="text-[11px] text-muted-foreground capitalize">
                                        {{ str_replace('_', ' ', $key->type) }}
                                    </div>
                                </div>
                            </td>

                            <!-- Rate Limit -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                <div class="font-mono text-xs text-foreground">{{ $key->rate_limit_per_minute }} <span class="text-[10px] text-muted-foreground font-sans">req/min</span></div>
                            </td>

                            <!-- Granted Scopes -->
                            <td class="py-3.5 px-4">
                                <div class="flex flex-wrap gap-1 max-w-xs">
                                    @php
                                        $scopes = $key->scopes ?? [];
                                        $displayedScopes = array_slice($scopes, 0, 3);
                                        $moreCount = count($scopes) - 3;
                                    @endphp
                                    @if(in_array('*', $scopes))
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-violet-500/10 text-violet-600 dark:text-violet-400">
                                            <x-icon name="sparkles" class="w-3 h-3 mr-1" />
                                            {{ __('Full Access (*)') }}
                                        </span>
                                    @else
                                        @foreach($displayedScopes as $scope)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono bg-muted text-muted-foreground border border-border">
                                                {{ $scope }}
                                            </span>
                                        @endforeach
                                        @if($moreCount > 0)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono bg-muted text-muted-foreground" title="{{ implode(', ', array_slice($scopes, 3)) }}">
                                                +{{ $moreCount }} more
                                            </span>
                                        @endif
                                    @endif
                                </div>
                            </td>

                            <!-- Last Activity -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                @if($key->last_used_at)
                                    <div>
                                        <span class="text-xs text-foreground font-medium">{{ $key->last_used_at->diffForHumans() }}</span>
                                        <div class="text-[11px] text-muted-foreground font-mono">{{ $key->last_used_ip ?: 'unknown ip' }}</div>
                                    </div>
                                @else
                                    <span class="text-xs text-muted-foreground italic">{{ __('Never used') }}</span>
                                @endif
                            </td>

                            <!-- Expiration -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                @if($key->expires_at)
                                    <div class="{{ $key->isExpired() ? 'text-destructive' : 'text-foreground' }}">
                                        <span class="text-xs font-medium">{{ $key->expires_at->format('M d, Y') }}</span>
                                        <div class="text-[11px] {{ $key->isExpired() ? 'text-destructive font-semibold' : 'text-muted-foreground' }}">
                                            {{ $key->isExpired() ? __('Expired') : $key->expires_at->diffForHumans() }}
                                        </div>
                                    </div>
                                @else
                                    <span class="inline-flex items-center text-xs text-muted-foreground">
                                        <x-icon name="check" class="w-3 h-3 mr-1 text-emerald-500" />
                                        {{ __('No Expiration') }}
                                    </span>
                                @endif
                            </td>

                            <!-- Status Badge -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                @if($key->trashed())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-destructive/10 text-destructive">
                                        {{ __('Trashed') }}
                                    </span>
                                @elseif($key->isRevoked())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-destructive/10 text-destructive">
                                        {{ __('Revoked') }}
                                    </span>
                                @elseif($key->isExpired())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600">
                                        {{ __('Expired') }}
                                    </span>
                                @elseif($key->isSuspended())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-zinc-500/10 text-zinc-600 dark:text-zinc-400">
                                        {{ __('Suspended') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                        <span class="size-1.5 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span>
                                        {{ __('Active') }}
                                    </span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1">
                                    @if($key->trashed())
                                        @can('api_keys.delete')
                                            <button wire:click="restoreKey({{ $key->id }})" title="{{ __('Restore API Key') }}"
                                                    class="p-1.5 text-emerald-600 hover:bg-emerald-500/10 rounded-lg transition-colors">
                                                <x-icon name="rotate-cw" class="w-4 h-4" />
                                            </button>
                                            <button wire:click="openDeleteModal({{ $key->id }}, true)" title="{{ __('Permanently Purge') }}"
                                                    class="p-1.5 text-destructive hover:bg-destructive/10 rounded-lg transition-colors">
                                                <x-icon name="trash" class="w-4 h-4" />
                                            </button>
                                        @endcan
                                    @else
                                        <!-- Test Console -->
                                        <button wire:click="openTestModal({{ $key->id }})" title="{{ __('Quick API Test') }}"
                                                class="p-1.5 text-muted-foreground hover:text-primary hover:bg-muted rounded-lg transition-colors">
                                            <x-icon name="terminal" class="w-4 h-4" />
                                        </button>

                                        @can('api_keys.edit')
                                            <!-- Edit Settings -->
                                            <button wire:click="openEditModal({{ $key->id }})" title="{{ __('Edit Settings') }}"
                                                    class="p-1.5 text-muted-foreground hover:text-foreground hover:bg-muted rounded-lg transition-colors">
                                                <x-icon name="edit" class="w-4 h-4" />
                                            </button>

                                            <!-- Toggle Suspend/Active -->
                                            <button wire:click="toggleStatus({{ $key->id }})" title="{{ $key->isSuspended() ? __('Activate Key') : __('Pause / Suspend Key') }}"
                                                    class="p-1.5 text-muted-foreground hover:text-foreground hover:bg-muted rounded-lg transition-colors">
                                                <x-icon name="{{ $key->isSuspended() ? 'play-circle' : 'pause' }}" class="w-4 h-4" />
                                            </button>
                                        @endcan

                                        @can('api_keys.rotate')
                                            <!-- Rotate Secret -->
                                            <button wire:click="openRotateModal({{ $key->id }})" title="{{ __('Roll / Rotate Secret Key') }}"
                                                    class="p-1.5 text-muted-foreground hover:text-amber-500 hover:bg-amber-500/10 rounded-lg transition-colors">
                                                <x-icon name="refresh-cw" class="w-4 h-4" />
                                            </button>
                                        @endcan

                                        @can('api_keys.revoke')
                                            @if(! $key->isRevoked())
                                                <!-- Revoke -->
                                                <button wire:click="openRevokeModal({{ $key->id }})" title="{{ __('Revoke Key Immediately') }}"
                                                        class="p-1.5 text-muted-foreground hover:text-destructive hover:bg-destructive/10 rounded-lg transition-colors">
                                                    <x-icon name="shield-alert" class="w-4 h-4" />
                                                </button>
                                            @endif
                                        @endcan

                                        @can('api_keys.delete')
                                            <!-- Soft Delete -->
                                            <button wire:click="openDeleteModal({{ $key->id }}, false)" title="{{ __('Move to Trash') }}"
                                                    class="p-1.5 text-muted-foreground hover:text-destructive hover:bg-destructive/10 rounded-lg transition-colors">
                                                <x-icon name="trash" class="w-4 h-4" />
                                            </button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-muted-foreground">
                                <div class="max-w-sm mx-auto flex flex-col items-center">
                                    <div class="p-3 bg-muted rounded-full mb-3 text-muted-foreground">
                                        <x-icon name="key" class="w-8 h-8 stroke-1" />
                                    </div>
                                    <p class="font-semibold text-foreground text-sm">{{ __('No API Keys Found') }}</p>
                                    <p class="text-xs text-muted-foreground mt-1">{{ __('Generate your first API key to connect mobile applications, desktop tools, or external services safely.') }}</p>
                                    @can('api_keys.create')
                                        <button wire:click="openCreateModal" class="mt-4 px-4 py-2 bg-primary text-primary-foreground text-xs font-semibold rounded-lg hover:bg-primary/90 transition-all flex items-center gap-1.5">
                                            <x-icon name="plus" class="w-4 h-4" />
                                            <span>{{ __('Generate New API Key') }}</span>
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->apiKeys->hasPages())
            <div class="p-4 border-t border-border">
                {{ $this->apiKeys->links() }}
            </div>
        @endif
    </div>

    <!-- ========================================== -->
    <!-- MODAL 1: CREATE API KEY MODAL              -->
    <!-- ========================================== -->
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/60 backdrop-blur-xs overflow-y-auto" wire:keydown.escape="$set('showCreateModal', false)">
            <div class="relative w-full max-w-2xl bg-card border border-border rounded-2xl shadow-2xl overflow-hidden my-8">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/20">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 bg-primary/10 text-primary rounded-xl">
                            <x-icon name="key" class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-foreground">{{ __('Generate New API Key') }}</h2>
                            <p class="text-xs text-muted-foreground">{{ __('Create a secured token with scoped permissions and IP firewall controls.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showCreateModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Form Body -->
                <form wire:submit="createKey" class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <!-- Key Name & Client Type -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Application / Client Name') }} <span class="text-destructive">*</span></label>
                            <input type="text" wire:model="createForm.name" placeholder="e.g. SNTCSSC Student Mobile App (Flutter)"
                                   class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                            @error('createForm.name') <span class="text-destructive text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Integration Type') }} <span class="text-destructive">*</span></label>
                            <select wire:model="createForm.type" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <option value="mobile_app">{{ __('Mobile Application (iOS / Android)') }}</option>
                                <option value="desktop_app">{{ __('Desktop Application (Windows / macOS)') }}</option>
                                <option value="external_app">{{ __('External Web Application') }}</option>
                                <option value="service">{{ __('Backend Service / Biometric Server') }}</option>
                                <option value="testing">{{ __('Testing / Development Sandbox') }}</option>
                            </select>
                            @error('createForm.type') <span class="text-destructive text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Environment & Rate Limit -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Target Environment') }} <span class="text-destructive">*</span></label>
                            <select wire:model="createForm.environment" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <option value="production">{{ __('Production (sntc_live_...)') }}</option>
                                <option value="sandbox">{{ __('Sandbox / Testing (sntc_test_...)') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Rate Limit (Requests / Minute)') }} <span class="text-destructive">*</span></label>
                            <input type="number" wire:model="createForm.rate_limit_per_minute" min="1" max="10000"
                                   class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary">
                            <span class="text-[10px] text-muted-foreground block mt-1">{{ __('Default is 60 req/min. High-traffic services can set up to 10,000.') }}</span>
                            @error('createForm.rate_limit_per_minute') <span class="text-destructive text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Expiration Preset -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Key Expiration') }}</label>
                            <select wire:model.live="createForm.expires_preset" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <option value="30_days">{{ __('30 Days') }}</option>
                                <option value="60_days">{{ __('60 Days') }}</option>
                                <option value="90_days">{{ __('90 Days (Recommended)') }}</option>
                                <option value="1_year">{{ __('1 Year') }}</option>
                                <option value="never">{{ __('Never Expire (Lifetime)') }}</option>
                                <option value="custom">{{ __('Custom Date & Time...') }}</option>
                            </select>
                        </div>

                        @if($createForm['expires_preset'] === 'custom')
                            <div>
                                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Custom Expiry Timestamp') }}</label>
                                <input type="datetime-local" wire:model="createForm.custom_expires_at"
                                       class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                        @else
                            <div>
                                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description / Notes') }}</label>
                                <input type="text" wire:model="createForm.description" placeholder="e.g. Production iOS app integration token"
                                       class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                            </div>
                        @endif
                    </div>

                    <!-- IP Allowlist & Allowed Domains -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">
                                {{ __('IP Address Whitelist (Optional)') }}
                                <span class="text-[10px] font-normal text-muted-foreground">({{ __('One per line or comma') }})</span>
                            </label>
                            <textarea wire:model="createForm.ip_allowlist" rows="2" placeholder="e.g. 192.168.1.100&#10;10.0.0.0/24"
                                      class="w-full px-3 py-2 text-xs font-mono rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20"></textarea>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Leave empty to allow all IP addresses.') }}</span>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">
                                {{ __('Allowed Web Origins (CORS / Domains)') }}
                                <span class="text-[10px] font-normal text-muted-foreground">({{ __('Optional') }})</span>
                            </label>
                            <textarea wire:model="createForm.allowed_domains" rows="2" placeholder="e.g. app.sntcssc.ac.in&#10;https://portal.domain.com"
                                      class="w-full px-3 py-2 text-xs font-mono rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20"></textarea>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Optional domain restriction for web clients.') }}</span>
                        </div>
                    </div>

                    <!-- Granted Permissions / Scopes Selector -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <label class="block text-xs font-semibold text-foreground">{{ __('API Resource Scopes & Permissions') }} <span class="text-destructive">*</span></label>
                                <span class="text-[11px] text-muted-foreground">{{ __('Select what services and data endpoints this API key can access.') }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="selectAllScopes" class="text-xs text-primary hover:underline font-medium">
                                    {{ __('Select All') }}
                                </button>
                                <span class="text-muted-foreground">•</span>
                                <button type="button" wire:click="clearAllScopes" class="text-xs text-muted-foreground hover:text-foreground">
                                    {{ __('Reset') }}
                                </button>
                            </div>
                        </div>

                        @error('createForm.scopes') <span class="text-destructive text-[11px] block mb-2">{{ $message }}</span> @enderror

                        <!-- Accordion of scopes -->
                        <div class="space-y-3">
                            @foreach($this->availableScopes as $module => $scopes)
                                <div class="rounded-xl border border-border bg-muted/10 p-3">
                                    <div class="flex items-center justify-between mb-2 pb-1.5 border-b border-border/50">
                                        <span class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                            <span>{{ $module }}</span>
                                        </span>
                                        <button type="button" wire:click="toggleScopeGroup('{{ $module }}')"
                                                class="text-[11px] text-primary hover:underline font-medium">
                                            {{ __('Toggle Group') }}
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        @foreach($scopes as $scopeKey => $scopeDesc)
                                            <label class="flex items-start gap-2 p-1.5 rounded-lg hover:bg-muted/40 cursor-pointer">
                                                <input type="checkbox" wire:model="createForm.scopes" value="{{ $scopeKey }}"
                                                       class="mt-0.5 rounded border-border text-primary focus:ring-primary/20">
                                                <div class="leading-tight">
                                                    <span class="font-mono text-xs text-foreground font-semibold block">{{ $scopeKey }}</span>
                                                    <span class="text-[10px] text-muted-foreground block">{{ $scopeDesc }}</span>
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Footer Buttons -->
                    <div class="pt-4 border-t border-border flex items-center justify-end gap-2">
                        <button type="button" wire:click="$set('showCreateModal', false)"
                                class="px-4 py-2 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted transition-colors">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="px-5 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all flex items-center gap-1.5">
                            <x-icon name="plus" class="w-4 h-4" />
                            <span>{{ __('Generate & Reveal Key') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- ============================================================== -->
    <!-- MODAL 2: ZERO-TRUST SECRET KEY REVEAL & QR CODE MODAL          -->
    <!-- ============================================================== -->
    @if($showKeyRevealModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/75 backdrop-blur-sm overflow-y-auto" x-data="{ copied: false, curlCopied: false }">
            <div class="relative w-full max-w-xl bg-card border border-border rounded-2xl shadow-2xl overflow-hidden my-8 animate-in fade-in zoom-in-95 duration-150">
                <!-- Header -->
                <div class="px-6 py-4 bg-emerald-500/10 border-b border-emerald-500/20 flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 bg-emerald-500 text-white rounded-xl shadow-xs">
                            <x-icon name="shield-check" class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-foreground">{{ __('API Key Generated Successfully!') }}</h2>
                            <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">{{ __('Zero-Trust Secret Reveal — copy this key now.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showKeyRevealModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="p-6 space-y-4">
                    <!-- Warning Banner -->
                    <div class="p-3.5 rounded-xl border border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-200 text-xs flex items-start gap-2.5">
                        <x-icon name="alert-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                        <div>
                            <span class="font-bold">{{ __('Please copy your secret key immediately!') }}</span>
                            <p class="mt-0.5 text-[11px] leading-relaxed">{{ __('For enterprise security, the plain secret key is hashed using SHA-256 and never stored in plain text. You will NOT be able to view it again once this dialog is closed.') }}</p>
                        </div>
                    </div>

                    <!-- Client Details & Secret Box -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold text-foreground">{{ $revealedName }}</span>
                            <span class="text-[11px] font-mono text-muted-foreground">{{ $revealedPrefix }}</span>
                        </div>

                        <!-- Plain Key Copy Box -->
                        <div class="relative flex items-center">
                            <input type="text" readonly value="{{ $revealedSecret }}" id="secretKeyInput"
                                   class="w-full pl-3 pr-24 py-3 font-mono text-xs sm:text-sm bg-muted/60 text-foreground border border-border rounded-xl font-bold select-all focus:outline-none">
                            <button type="button"
                                    @click="navigator.clipboard.writeText('{{ $revealedSecret }}'); copied = true; setTimeout(() => copied = false, 2500)"
                                    class="absolute right-2 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5"
                                    :class="copied ? 'bg-emerald-600 text-white' : 'bg-primary text-primary-foreground hover:bg-primary/90'">
                                <x-icon name="copy" class="w-3.5 h-3.5" x-show="!copied" />
                                <x-icon name="check" class="w-3.5 h-3.5" x-show="copied" />
                                <span x-text="copied ? 'Copied!' : 'Copy Key'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- QR Code for Mobile App Pairing -->
                    @if($revealedQrUri)
                        <div class="p-4 rounded-xl border border-border bg-muted/20 flex flex-col sm:flex-row items-center gap-4">
                            <div class="bg-white p-2 rounded-xl shadow-xs shrink-0">
                                <img src="{{ $revealedQrUri }}" alt="API Key QR Code" class="size-28">
                            </div>
                            <div class="text-center sm:text-left">
                                <span class="text-xs font-bold text-foreground flex items-center gap-1.5 justify-center sm:justify-start">
                                    <x-icon name="qr-code" class="w-4 h-4 text-primary" />
                                    {{ __('Instant Mobile App QR Scan') }}
                                </span>
                                <p class="text-[11px] text-muted-foreground mt-1 leading-relaxed">
                                    {{ __('Scan this QR code with the SNTCSSC Mobile Application or Desktop Client to pair this device and initialize secure API communication.') }}
                                </p>
                            </div>
                        </div>
                    @endif

                    <!-- Quick cURL Snippet -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[11px] font-semibold text-muted-foreground">{{ __('Sample Verification Request (cURL)') }}</span>
                            <button type="button"
                                    @click="navigator.clipboard.writeText(`{{ $revealedCurl }}`); curlCopied = true; setTimeout(() => curlCopied = false, 2500)"
                                    class="text-[11px] text-primary hover:underline font-medium flex items-center gap-1">
                                <x-icon name="copy" class="w-3 h-3" />
                                <span x-text="curlCopied ? 'Copied Snippet!' : 'Copy cURL'"></span>
                            </button>
                        </div>
                        <pre class="p-3 bg-muted/60 text-muted-foreground rounded-xl font-mono text-[11px] overflow-x-auto border border-border leading-relaxed"><code>{{ $revealedCurl }}</code></pre>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 bg-muted/20 border-t border-border flex items-center justify-end">
                    <button type="button" wire:click="$set('showKeyRevealModal', false)"
                            class="px-5 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all">
                        {{ __('I Have Securely Copied This Key') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL 3: EDIT API KEY MODAL                -->
    <!-- ========================================== -->
    @if($showEditModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/60 backdrop-blur-xs overflow-y-auto" wire:keydown.escape="$set('showEditModal', false)">
            <div class="relative w-full max-w-2xl bg-card border border-border rounded-2xl shadow-2xl overflow-hidden my-8">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/20">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 bg-primary/10 text-primary rounded-xl">
                            <x-icon name="edit" class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-foreground">{{ __('Edit API Key Configuration') }}</h2>
                            <p class="text-xs text-muted-foreground">{{ __('Modify rate limits, IP whitelist, scopes, and expiration timestamp.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showEditModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Form -->
                <form wire:submit="updateKey" class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Client Name') }} <span class="text-destructive">*</span></label>
                            <input type="text" wire:model="editForm.name" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                            @error('editForm.name') <span class="text-destructive text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Integration Type') }}</label>
                            <select wire:model="editForm.type" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground">
                                <option value="mobile_app">{{ __('Mobile Application (iOS / Android)') }}</option>
                                <option value="desktop_app">{{ __('Desktop Application') }}</option>
                                <option value="external_app">{{ __('External Web Application') }}</option>
                                <option value="service">{{ __('Backend Service / Biometric Server') }}</option>
                                <option value="testing">{{ __('Testing Sandbox') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Rate Limit (req / min)') }}</label>
                            <input type="number" wire:model="editForm.rate_limit_per_minute" min="1" max="10000" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Expiration Timestamp') }}</label>
                            <input type="datetime-local" wire:model="editForm.expires_at" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground">
                            <span class="text-[10px] text-muted-foreground block mt-1">{{ __('Clear field to make this key lifetime / no expiration.') }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('IP Allowlist') }}</label>
                            <textarea wire:model="editForm.ip_allowlist" rows="2" class="w-full px-3 py-2 text-xs font-mono rounded-lg border border-border bg-background text-foreground"></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description') }}</label>
                            <textarea wire:model="editForm.description" rows="2" class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground"></textarea>
                        </div>
                    </div>

                    <!-- Scopes Selector -->
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-2">{{ __('Granted Scopes') }} <span class="text-destructive">*</span></label>
                        <div class="space-y-3">
                            @foreach($this->availableScopes as $module => $scopes)
                                <div class="rounded-xl border border-border bg-muted/10 p-3">
                                    <span class="text-xs font-bold text-foreground block mb-2">{{ $module }}</span>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        @foreach($scopes as $scopeKey => $scopeDesc)
                                            <label class="flex items-start gap-2 p-1.5 rounded-lg hover:bg-muted/40 cursor-pointer">
                                                <input type="checkbox" wire:model="editForm.scopes" value="{{ $scopeKey }}"
                                                       class="mt-0.5 rounded border-border text-primary focus:ring-primary/20">
                                                <div class="leading-tight">
                                                    <span class="font-mono text-xs text-foreground font-semibold block">{{ $scopeKey }}</span>
                                                    <span class="text-[10px] text-muted-foreground block">{{ $scopeDesc }}</span>
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="pt-4 border-t border-border flex items-center justify-end gap-2">
                        <button type="button" wire:click="$set('showEditModal', false)"
                                class="px-4 py-2 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted transition-colors">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="px-5 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all">
                            {{ __('Save Changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL 4: ROTATE SECRET KEY MODAL           -->
    <!-- ========================================== -->
    @if($showRotateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="relative w-full max-w-md bg-card border border-border rounded-2xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-amber-500/10 text-amber-500 rounded-xl">
                        <x-icon name="refresh-cw" class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Roll / Rotate Secret Key') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ $rotatingName }}</p>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-amber-500/10 text-amber-800 dark:text-amber-200 text-xs leading-relaxed">
                    {{ __('Rotating this API key will immediately invalidate the existing secret key. Any external apps or services utilizing the previous secret must be updated with the newly generated secret.') }}
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" wire:click="$set('showRotateModal', false)"
                            class="px-4 py-2 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted transition-colors">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="confirmRotate"
                            class="px-4 py-2 text-xs font-semibold rounded-lg bg-amber-600 text-white hover:bg-amber-700 shadow-sm transition-all flex items-center gap-1.5">
                        <x-icon name="refresh-cw" class="w-3.5 h-3.5" />
                        <span>{{ __('Regenerate Secret Now') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL 5: REVOKE KEY MODAL                  -->
    <!-- ========================================== -->
    @if($showRevokeModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="relative w-full max-w-md bg-card border border-border rounded-2xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-destructive/10 text-destructive rounded-xl">
                        <x-icon name="shield-alert" class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Revoke API Key') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ $revokingName }}</p>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground leading-relaxed">
                    {{ __('Are you sure you want to revoke this API key? All subsequent calls using this token will be rejected immediately with 401 Unauthorized.') }}
                </p>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Revocation Reason (Optional)') }}</label>
                    <input type="text" wire:model="revocationReason" placeholder="e.g. Compromised token, decommissioned service"
                           class="w-full px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20">
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" wire:click="$set('showRevokeModal', false)"
                            class="px-4 py-2 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted transition-colors">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="confirmRevoke"
                            class="px-4 py-2 text-xs font-semibold rounded-lg bg-destructive text-destructive-foreground hover:bg-destructive/90 shadow-sm transition-all flex items-center gap-1.5">
                        <x-icon name="shield-alert" class="w-3.5 h-3.5" />
                        <span>{{ __('Revoke Immediately') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL 6: DELETE / PURGE MODAL              -->
    <!-- ========================================== -->
    @if($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="relative w-full max-w-md bg-card border border-border rounded-2xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-destructive/10 text-destructive rounded-xl">
                        <x-icon name="trash" class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ $isPermanentDelete ? __('Permanently Purge API Key') : __('Move to Trash') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ $deletingName }}</p>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground leading-relaxed">
                    @if($isPermanentDelete)
                        {{ __('This action cannot be undone. The API Key record and all associated telemetry log data will be permanently removed from the database.') }}
                    @else
                        {{ __('This key will be moved to the trash bin. You can restore it anytime from the Trashed filter.') }}
                    @endif
                </p>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" wire:click="$set('showDeleteModal', false)"
                            class="px-4 py-2 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted transition-colors">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="confirmDelete"
                            class="px-4 py-2 text-xs font-semibold rounded-lg bg-destructive text-destructive-foreground hover:bg-destructive/90 shadow-sm transition-all">
                        {{ $isPermanentDelete ? __('Purge Permanently') : __('Move to Trash') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL 7: QUICK TEST CONSOLE MODAL          -->
    <!-- ========================================== -->
    @if($showTestModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/60 backdrop-blur-xs overflow-y-auto" wire:keydown.escape="$set('showTestModal', false)">
            <div class="relative w-full max-w-xl bg-card border border-border rounded-2xl shadow-2xl overflow-hidden my-8">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/20">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 bg-primary/10 text-primary rounded-xl">
                            <x-icon name="terminal" class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-foreground">{{ __('Interactive API Test Console') }}</h2>
                            <p class="text-xs text-muted-foreground">{{ __('Simulate real HTTP requests against live API endpoints with this key.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showTestModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select Test Target Endpoint') }}</label>
                        <div class="flex items-center gap-2">
                            <select wire:model="testEndpoint" class="flex-1 px-3 py-2 text-xs rounded-lg border border-border bg-background text-foreground font-mono">
                                <optgroup label="{{ __('Diagnostics & Core') }}">
                                    <option value="ping">GET /api/v1/ping (Diagnostic Ping)</option>
                                    <option value="app-info">GET /api/v1/app/info (App Metadata)</option>
                                    <option value="courses">GET /api/v1/courses (Academics Courses)</option>
                                </optgroup>
                                <optgroup label="{{ __('Student Management & Admissions') }}">
                                    <option value="students-dashboard">GET /api/v1/students/dashboard (Executive Telemetry)</option>
                                    <option value="students-stats">GET /api/v1/students/statistics (Admissions Trends)</option>
                                    <option value="students">GET /api/v1/students (Students Registry)</option>
                                    <option value="students-batches">GET /api/v1/students/batches (Academic Batches)</option>
                                    <option value="students-courses">GET /api/v1/students/courses (Offered Courses)</option>
                                    <option value="students-sections">GET /api/v1/students/sections (Sections & Vacancy)</option>
                                    <option value="students-profile-requests">GET /api/v1/students/profile-requests (Correction Queue)</option>
                                </optgroup>
                                <optgroup label="{{ __('Course Enrollments') }}">
                                    <option value="enrollments">GET /api/v1/enrollments (Enrollments List)</option>
                                </optgroup>
                                <optgroup label="{{ __('Dynamic Study Materials') }}">
                                    <option value="study-materials">GET /api/v1/study-materials (Published Materials)</option>
                                    <option value="study-materials-types">GET /api/v1/study-materials/types (Material Types)</option>
                                    <option value="study-materials-stats">GET /api/v1/study-materials/statistics (Storage & KPI)</option>
                                    <option value="study-materials-my">GET /api/v1/study-materials/my-materials (Personal Feed)</option>
                                    <option value="study-materials-detail">GET /api/v1/study-materials/1 (Single Material Dossier)</option>
                                    <option value="study-materials-download-token">GET /api/v1/study-materials/1/download-token (Download Token)</option>
                                    <option value="study-materials-external">GET /api/v1/external/study-materials (Public Materials)</option>
                                    <option value="study-materials-external-types">GET /api/v1/external/study-materials/types (Public Material Types)</option>
                                </optgroup>
                                <optgroup label="{{ __('Application System Settings') }}">
                                    <option value="settings-public">GET /api/v1/settings/public (Public Bootstrap & Branding)</option>
                                    <option value="settings-bootstrap-mobile">GET /api/v1/settings/bootstrap/mobile (Mobile Platform Bootstrap)</option>
                                    <option value="settings-features">GET /api/v1/settings/features (Client Feature Flags)</option>
                                    <option value="settings-storage-overview">GET /api/v1/settings/storage/overview (Cloud S3 Storage Telemetry)</option>
                                    <option value="settings-groups">GET /api/v1/settings/groups (Configuration Groups)</option>
                                    <option value="settings-stats">GET /api/v1/settings/statistics (Telemetry & Cache)</option>
                                    <option value="settings-list">GET /api/v1/settings (Settings Catalog)</option>
                                    <option value="settings-general">GET /api/v1/settings/groups/general (General Settings)</option>
                                    <option value="settings-appearance">GET /api/v1/settings/groups/appearance (Appearance & Themes)</option>
                                    <option value="settings-localization">GET /api/v1/settings/groups/localization (Localization & Currency)</option>
                                    <option value="settings-system">GET /api/v1/settings/groups/system (System & Environment)</option>
                                    <option value="settings-single">GET /api/v1/settings/item/general.site_name (Single Setting)</option>
                                </optgroup>
                                <optgroup label="{{ __('Live Chat & Calls') }}">
                                    <option value="chat-status">GET /api/v1/chat/status (Realtime WebSocket Telemetry)</option>
                                    <option value="chat-ice">GET /api/v1/chat/calls/ice-servers (STUN/TURN ICE Servers)</option>
                                    <option value="chat-conversations">GET /api/v1/chat/conversations (Conversations Feed)</option>
                                    <option value="chat-meetings">GET /api/v1/chat/meetings (Online Meetings & Conferences)</option>
                                </optgroup>
                                <optgroup label="{{ __('Support Tickets & Helpdesk') }}">
                                    <option value="tickets-dashboard">GET /api/v1/tickets/dashboard (Executive Dashboard & Metrics)</option>
                                    <option value="tickets-stats">GET /api/v1/tickets/statistics (SLA & Satisfaction Analytics)</option>
                                    <option value="tickets-list">GET /api/v1/tickets (Master Tickets Catalog)</option>
                                    <option value="tickets-my">GET /api/v1/tickets/my-tickets (Student Self-Service Feed)</option>
                                    <option value="tickets-categories">GET /api/v1/tickets/categories (Categories & SLA Catalog)</option>
                                    <option value="tickets-canned-responses">GET /api/v1/tickets/canned-responses (Canned Responses & Macros)</option>
                                    <option value="tickets-detail">GET /api/v1/tickets/1 (Single Ticket Dossier)</option>
                                    <option value="tickets-messages">GET /api/v1/tickets/1/messages (Conversation Thread)</option>
                                    <option value="tickets-export">GET /api/v1/tickets/export (Export Tickets Dataset)</option>
                                </optgroup>
                                <optgroup label="{{ __('Communications & Marketing') }}">
                                    <option value="communications-dashboard">GET /api/v1/communications/dashboard (Realtime Telemetry & Channels)</option>
                                    <option value="communications-stats">GET /api/v1/communications/statistics (Volume Trends & Delivery Rates)</option>
                                    <option value="communications-logs">GET /api/v1/communications/logs (Delivery Logs Directory)</option>
                                    <option value="communications-campaigns">GET /api/v1/communications/campaigns (Marketing Campaigns Catalog)</option>
                                    <option value="communications-subscribers">GET /api/v1/communications/subscribers (Newsletter & WhatsApp Audience)</option>
                                    <option value="communications-templates-email">GET /api/v1/communications/templates/email (Email Templates)</option>
                                    <option value="communications-templates-sms">GET /api/v1/communications/templates/sms (SMS Templates)</option>
                                    <option value="communications-export">GET /api/v1/communications/export (Export Delivery Dataset)</option>
                                </optgroup>
                                <optgroup label="{{ __('Library Management') }}">
                                    <option value="library-dashboard">GET /api/v1/library/dashboard (Executive Overview)</option>
                                    <option value="library-stats">GET /api/v1/library/statistics (Circulation Analytics)</option>
                                    <option value="library-books">GET /api/v1/library/books (Book Catalog)</option>
                                    <option value="library-catalog">GET /api/v1/library/catalog (Public OPAC)</option>
                                </optgroup>
                                <optgroup label="{{ __('Hostel Management') }}">
                                    <option value="hostel-dashboard">GET /api/v1/hostel/dashboard (Hostel Overview)</option>
                                    <option value="hostel-visual-board">GET /api/v1/hostel/visual-board (Live Visual Room Board)</option>
                                    <option value="hostel-buildings">GET /api/v1/hostel/buildings (Buildings & Floors)</option>
                                    <option value="hostel-rooms">GET /api/v1/hostel/rooms (Rooms & Beds)</option>
                                    <option value="hostel-allocations">GET /api/v1/hostel/allocations (Allocations)</option>
                                    <option value="hostel-policies">GET /api/v1/hostel/policies (Hostel Rules & Policies)</option>
                                    <option value="hostel-occupancy">GET /api/v1/hostel/reports/occupancy (Occupancy Report)</option>
                                </optgroup>
                                <optgroup label="{{ __('Examinations & Alumni') }}">
                                    <option value="exam-dashboard">GET /api/v1/examinations/dashboard (Exam Overview)</option>
                                    <option value="exam-centers">GET /api/v1/examinations/centers (Centers Directory)</option>
                                    <option value="alumni">GET /api/v1/alumni (Alumni Directory)</option>
                                </optgroup>
                                <optgroup label="{{ __('Attendance & Shifts') }}">
                                    <option value="attendance-dashboard">GET /api/v1/attendance/dashboard (Executive Overview)</option>
                                    <option value="attendance-stats">GET /api/v1/attendance/statistics (Attendance Analytics)</option>
                                    <option value="attendance-register">GET /api/v1/attendance (Master Register)</option>
                                    <option value="attendance-defaulters">GET /api/v1/attendance/defaulters (Shortage Defaulters <75%)</option>
                                    <option value="attendance-calendars">GET /api/v1/attendance/calendars (Working Calendars)</option>
                                    <option value="attendance-holidays">GET /api/v1/attendance/holidays (Holiday Calendar)</option>
                                    <option value="attendance-devices">GET /api/v1/attendance/devices (Biometric Devices)</option>
                                </optgroup>
                                <optgroup label="{{ __('Leave Management') }}">
                                    <option value="leaves-dashboard">GET /api/v1/leaves/dashboard (Leave Overview)</option>
                                    <option value="leaves-stats">GET /api/v1/leaves/statistics (Leave Analytics)</option>
                                    <option value="leaves-categories">GET /api/v1/leaves/categories (Leave Categories & Quotas)</option>
                                    <option value="leaves-allocations">GET /api/v1/leaves/allocations (User Quota Allocations)</option>
                                    <option value="leaves-applications">GET /api/v1/leaves/applications (Submitted Applications)</option>
                                </optgroup>
                                <optgroup label="{{ __('Fees & Payments') }}">
                                    <option value="payments-dashboard">GET /api/v1/payments/dashboard (Financial Telemetry)</option>
                                    <option value="payments-stats">GET /api/v1/payments/statistics (Collection Breakdown)</option>
                                    <option value="payments-transactions">GET /api/v1/payments/transactions (Recent Collections)</option>
                                </optgroup>
                                <optgroup label="{{ __('Users & Access (RBAC)') }}">
                                    <option value="users-dashboard">GET /api/v1/users/dashboard (RBAC & User Telemetry)</option>
                                    <option value="users-stats">GET /api/v1/users/statistics (User Demographics & Growth)</option>
                                    <option value="users-list">GET /api/v1/users (Users Directory)</option>
                                    <option value="users-roles">GET /api/v1/roles (Roles & Permissions Catalog)</option>
                                    <option value="users-permissions">GET /api/v1/permissions/modules (Module Permissions)</option>
                                    <option value="users-me">GET /api/v1/user/me (Current User Profile & Matrix)</option>
                                    <option value="users-audit-logs">GET /api/v1/security/audit-logs (Security Audit Trail)</option>
                                </optgroup>
                            </select>
                            <button type="button" wire:click="runQuickTest" wire:loading.attr="disabled"
                                    class="px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all flex items-center gap-1.5 shrink-0">
                                <x-icon name="zap" class="w-3.5 h-3.5" />
                                <span>{{ __('Send Request') }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Results Area -->
                    @if($testResponse !== null)
                        <div class="space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-foreground">{{ __('Response Status:') }}</span>
                                    <span class="px-2 py-0.5 rounded font-mono font-bold text-[11px] {{ $testStatusCode >= 200 && $testStatusCode < 300 ? 'bg-emerald-500/10 text-emerald-600' : 'bg-destructive/10 text-destructive' }}">
                                        {{ $testStatusCode }} {{ $testStatusCode === 200 ? 'OK' : '' }}
                                    </span>
                                </div>
                                @if($testDurationMs !== null)
                                    <span class="text-muted-foreground font-mono text-[11px]">{{ $testDurationMs }} ms latency</span>
                                @endif
                            </div>

                            <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-[11px] overflow-x-auto max-h-72 border border-border leading-relaxed"><code>{{ json_encode($testResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        </div>
                    @else
                        <div class="py-8 text-center text-muted-foreground border border-dashed border-border rounded-xl">
                            <x-icon name="terminal" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                            <p class="text-xs">{{ __('Click "Send Request" to simulate an API call.') }}</p>
                        </div>
                    @endif
                </div>

                <div class="px-6 py-4 bg-muted/20 border-t border-border flex items-center justify-end">
                    <button type="button" wire:click="$set('showTestModal', false)"
                            class="px-4 py-2 text-xs font-medium rounded-lg border border-border text-foreground hover:bg-muted transition-colors">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
