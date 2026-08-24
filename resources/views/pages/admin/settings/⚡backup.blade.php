<?php

use App\Models\Backup;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\BackupService;
use App\Support\Toast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Database & Backups')] class extends Component {
    use WithFileUploads, WithPagination;

    // Filters & Pagination
    public string $search = '';
    public string $typeFilter = 'all';
    public string $statusFilter = 'all';
    public int $perPage = 10;

    // Selection for Bulk Actions
    public array $selectedIds = [];
    public bool $selectAll = false;

    // Create Backup Form
    public array $createForm = [
        'type' => Backup::TYPE_FULL_WITH_MEDIA,
        'name' => '',
        'disk' => 'local',
        'send_email' => true,
        'recipient_email' => '',
    ];

    // Settings Form
    public array $settingsForm = [
        'auto_backup_enabled' => true,
        'schedule_frequency' => 'daily',
        'schedule_time' => '02:00',
        'default_scope' => Backup::TYPE_FULL_WITH_MEDIA,
        'storage_disk' => 'local',
        'retention_count' => 10,
        'retention_days' => 30,
        'notification_email' => '',
        'email_on_success' => true,
        'email_on_failure' => true,
        'email_attachment_max_mb' => 15,
        'scheduled_report_enabled' => true,
        'scheduled_report_frequency' => 'weekly',
    ];

    // Upload Backup
    public $uploadedFile = null;
    public bool $restoreUploadedImmediately = false;

    // Modal Target IDs
    public ?int $selectedBackupId = null;
    public ?Backup $viewingBackup = null;
    public string $customEmailRecipient = '';
    public bool $createPreRestoreSnapshot = true;

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        $settings = Setting::query()->where('group', 'backup')->get()->keyBy('key');

        foreach ($this->settingsForm as $key => $default) {
            $fullKey = "backup.{$key}";
            if (isset($settings[$fullKey])) {
                if (in_array($key, ['auto_backup_enabled', 'email_on_success', 'email_on_failure', 'scheduled_report_enabled'], true)) {
                    $this->settingsForm[$key] = (bool) $settings[$fullKey]->typed();
                } elseif (in_array($key, ['retention_count', 'retention_days', 'email_attachment_max_mb'], true)) {
                    $this->settingsForm[$key] = (int) $settings[$fullKey]->typed();
                } else {
                    $this->settingsForm[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
                }
            }
        }

        if (empty($this->settingsForm['notification_email'])) {
            $this->settingsForm['notification_email'] = BackupService::notificationEmail();
        }

        $this->createForm['recipient_email'] = $this->settingsForm['notification_email'];
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedIds = $this->getBackupsQuery()->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        } else {
            $this->selectedIds = [];
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Trigger immediate on-demand backup.
     */
    public function triggerBackup(): void
    {
        $this->validate([
            'createForm.type' => ['required', 'string', 'in:database_only,full_with_media'],
            'createForm.name' => ['nullable', 'string', 'max:50'],
            'createForm.disk' => ['required', 'string'],
            'createForm.send_email' => ['boolean'],
            'createForm.recipient_email' => ['nullable', 'email', 'max:255'],
        ]);

        /** @var BackupService $service */
        $service = app(BackupService::class);

        $result = $service->createBackup(
            options: $this->createForm,
            creator: auth()->user(),
            triggerType: Backup::TRIGGER_MANUAL
        );

        $this->dispatch('modal-close', name: 'backup-create-modal');

        if ($result['success']) {
            Toast::dispatch($this, 'success', $result['message']);
        } else {
            Toast::dispatch($this, 'error', $result['message']);
        }
    }

    /**
     * Open Restore Confirmation Modal.
     */
    public function openRestoreModal(int $id): void
    {
        $this->selectedBackupId = $id;
        $this->viewingBackup = Backup::findOrFail($id);
        $this->dispatch('modal-open', name: 'backup-restore-modal');
    }

    /**
     * Execute Restore on selected backup.
     */
    public function executeRestore(): void
    {
        if (! $this->selectedBackupId) {
            return;
        }

        $backup = Backup::findOrFail($this->selectedBackupId);

        /** @var BackupService $service */
        $service = app(BackupService::class);

        $result = $service->restoreBackup(
            backupOrPath: $backup,
            actor: auth()->user(),
            createSafetyBackup: $this->createPreRestoreSnapshot
        );

        $this->dispatch('modal-close', name: 'backup-restore-modal');
        $this->selectedBackupId = null;
        $this->viewingBackup = null;

        if ($result['success']) {
            Toast::dispatch($this, 'success', $result['message']);
        } else {
            Toast::dispatch($this, 'error', $result['message']);
        }
    }

    /**
     * Download backup file.
     */
    public function download(int $id)
    {
        $backup = Backup::findOrFail($id);

        /** @var BackupService $service */
        $service = app(BackupService::class);

        return $service->downloadBackup($backup, auth()->user());
    }

    /**
     * Open Email Dispatch Modal.
     */
    public function openEmailModal(int $id): void
    {
        $this->selectedBackupId = $id;
        $this->viewingBackup = Backup::findOrFail($id);
        $this->customEmailRecipient = $this->settingsForm['notification_email'];
        $this->dispatch('modal-open', name: 'backup-email-modal');
    }

    /**
     * Send backup copy to specified email.
     */
    public function sendEmail(): void
    {
        $this->validate([
            'customEmailRecipient' => ['required', 'email', 'max:255'],
        ]);

        if (! $this->selectedBackupId) {
            return;
        }

        $backup = Backup::findOrFail($this->selectedBackupId);

        /** @var BackupService $service */
        $service = app(BackupService::class);

        $sent = $service->sendBackupEmail($backup, $this->customEmailRecipient);
        $this->dispatch('modal-close', name: 'backup-email-modal');

        if ($sent) {
            Toast::dispatch($this, 'success', __("Backup notification dispatched to ':email'.", ['email' => $this->customEmailRecipient]));
        } else {
            Toast::dispatch($this, 'error', __('Failed to dispatch backup email. Verify mail gateway configuration.'));
        }

        $this->selectedBackupId = null;
        $this->viewingBackup = null;
    }

    /**
     * Open Manifest Details Modal.
     */
    public function viewDetails(int $id): void
    {
        $this->viewingBackup = Backup::with('creator')->findOrFail($id);
        $this->dispatch('modal-open', name: 'backup-details-modal');
    }

    /**
     * Open Delete Confirmation Modal.
     */
    public function openDeleteModal(int $id): void
    {
        $this->selectedBackupId = $id;
        $this->viewingBackup = Backup::findOrFail($id);
        $this->dispatch('modal-open', name: 'backup-delete-modal');
    }

    /**
     * Delete a single backup.
     */
    public function deleteSingle(): void
    {
        if (! $this->selectedBackupId) {
            return;
        }

        $backup = Backup::findOrFail($this->selectedBackupId);

        /** @var BackupService $service */
        $service = app(BackupService::class);
        $service->deleteBackup($backup, auth()->user());

        $this->dispatch('modal-close', name: 'backup-delete-modal');
        $this->selectedBackupId = null;
        $this->viewingBackup = null;

        Toast::dispatch($this, 'success', __('Backup record deleted successfully.'));
    }

    /**
     * Delete selected backups in bulk.
     */
    public function bulkDelete(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        /** @var BackupService $service */
        $service = app(BackupService::class);
        $backups = Backup::whereIn('id', $this->selectedIds)->get();
        $count = 0;

        foreach ($backups as $b) {
            if ($service->deleteBackup($b, auth()->user())) {
                $count++;
            }
        }

        $this->selectedIds = [];
        $this->selectAll = false;
        $this->dispatch('modal-close', name: 'backup-bulk-delete-modal');

        Toast::dispatch($this, 'success', __("Successfully deleted :count backup archive(s).", ['count' => $count]));
    }

    /**
     * Handle upload of external backup archive.
     */
    public function uploadBackup(): void
    {
        $this->validate([
            'uploadedFile' => ['required', 'file', 'max:102400', 'mimes:zip,sql'], // max 100MB
        ]);

        $originalName = $this->uploadedFile->getClientOriginalName();
        $extension = $this->uploadedFile->getClientOriginalExtension();
        $sizeBytes = $this->uploadedFile->getSize();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $cleanFilename = "uploaded_{$uuid}_{$originalName}";
        $storagePath = "backups/{$cleanFilename}";

        // Save to local backups folder
        Storage::disk('local')->put($storagePath, file_get_contents($this->uploadedFile->getRealPath()));

        $backup = Backup::create([
            'uuid' => $uuid,
            'filename' => $cleanFilename,
            'disk' => 'local',
            'path' => $storagePath,
            'type' => str_ends_with(strtolower($originalName), '.sql') ? Backup::TYPE_DATABASE_ONLY : Backup::TYPE_FULL_WITH_MEDIA,
            'db_driver' => \Illuminate\Support\Facades\DB::getDriverName(),
            'size_bytes' => $sizeBytes,
            'tables_count' => 0,
            'records_count' => 0,
            'files_count' => 0,
            'checksum' => hash_file('sha256', Storage::disk('local')->path($storagePath)),
            'trigger_type' => Backup::TRIGGER_MANUAL,
            'status' => Backup::STATUS_COMPLETED,
            'created_by' => auth()->id(),
        ]);

        $this->reset('uploadedFile');
        $this->dispatch('modal-close', name: 'backup-upload-modal');

        AuditLogService::log(
            event: 'backup_uploaded',
            description: "Uploaded external backup archive '{$cleanFilename}' ({$backup->formattedSize()}).",
            newValues: ['filename' => $cleanFilename, 'size_bytes' => $sizeBytes]
        );

        if ($this->restoreUploadedImmediately) {
            /** @var BackupService $service */
            $service = app(BackupService::class);
            $result = $service->restoreBackup($backup, auth()->user(), true);

            if ($result['success']) {
                Toast::dispatch($this, 'success', __('Backup uploaded and restored successfully!'));
            } else {
                Toast::dispatch($this, 'error', __('Backup uploaded, but restore failed: :error', ['error' => $result['message']]));
            }
        } else {
            Toast::dispatch($this, 'success', __("Backup ':name' uploaded successfully.", ['name' => $originalName]));
        }
    }

    /**
     * Save automated backup system settings.
     */
    public function saveSettings(): void
    {
        $this->validate([
            'settingsForm.auto_backup_enabled' => ['boolean'],
            'settingsForm.schedule_frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'settingsForm.schedule_time' => ['required', 'string'],
            'settingsForm.default_scope' => ['required', 'string', 'in:database_only,full_with_media'],
            'settingsForm.storage_disk' => ['required', 'string'],
            'settingsForm.retention_count' => ['required', 'integer', 'min:1', 'max:365'],
            'settingsForm.retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'settingsForm.notification_email' => ['nullable', 'email', 'max:255'],
            'settingsForm.email_on_success' => ['boolean'],
            'settingsForm.email_on_failure' => ['boolean'],
            'settingsForm.email_attachment_max_mb' => ['required', 'integer', 'min:1', 'max:100'],
            'settingsForm.scheduled_report_enabled' => ['boolean'],
            'settingsForm.scheduled_report_frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
        ]);

        foreach ($this->settingsForm as $key => $val) {
            $type = match (true) {
                is_bool($val) => Setting::TYPE_BOOLEAN,
                is_int($val) => Setting::TYPE_NUMBER,
                default => Setting::TYPE_STRING,
            };

            Setting::set("backup.{$key}", $val, auth()->id());
        }

        // Update CronJob expression for automated backup
        $cronJob = \App\Models\CronJob::firstWhere('command', 'app:backup:run');
        if ($cronJob) {
            $timeParts = explode(':', $this->settingsForm['schedule_time']);
            $hour = $timeParts[0] ?? '02';
            $minute = $timeParts[1] ?? '00';

            $expression = match ($this->settingsForm['schedule_frequency']) {
                'weekly' => "{$minute} {$hour} * * 0",
                'monthly' => "{$minute} {$hour} 1 * *",
                default => "{$minute} {$hour} * * *",
            };

            $cronJob->update([
                'is_active' => (bool) $this->settingsForm['auto_backup_enabled'],
                'expression' => $expression,
            ]);
        }

        AuditLogService::log(
            event: 'backup_settings_updated',
            description: 'Updated system automated backup, retention, and notification configurations.',
            newValues: $this->settingsForm
        );

        $this->dispatch('modal-close', name: 'backup-settings-modal');
        Toast::dispatch($this, 'success', __('Backup configuration and schedule policies saved successfully.'));
    }

    /**
     * Send immediate health report to admin.
     */
    public function sendReportNow(): void
    {
        /** @var BackupService $service */
        $service = app(BackupService::class);
        $sent = $service->sendScheduledReport($this->settingsForm['notification_email']);

        if ($sent) {
            Toast::dispatch($this, 'success', __('Backup health report dispatched to configured administrator email.'));
        } else {
            Toast::dispatch($this, 'error', __('Could not dispatch report. Check email gateway status.'));
        }
    }

    /**
     * Query builder for backups listing.
     */
    protected function getBackupsQuery()
    {
        return Backup::query()
            ->with('creator')
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('filename', 'like', $term)
                        ->orWhere('uuid', 'like', $term)
                        ->orWhere('path', 'like', $term);
                });
            })
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id');
    }

    public function with(): array
    {
        $totalBackupsCount = Backup::count();
        $totalBytes = (int) Backup::sum('size_bytes');
        $totalSizeFormatted = (new Backup(['size_bytes' => $totalBytes]))->formattedSize();
        $latestBackup = Backup::latest('id')->first();
        $cronJob = \App\Models\CronJob::firstWhere('command', 'app:backup:run');

        return [
            'backups' => $this->getBackupsQuery()->paginate($this->perPage),
            'stats' => [
                'total_count' => $totalBackupsCount,
                'total_size' => $totalSizeFormatted,
                'latest' => $latestBackup,
                'cron_job' => $cronJob,
            ],
            'disks' => array_keys(config('filesystems.disks', ['local' => []])),
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Settings Top Submenu Navigation --}}
    <x-settings-nav active="backup"/>

    {{-- Header Banner & Action Bar --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <x-icon name="database" class="h-5 w-5"/>
                </span>
                {{ __('Database & Backups Management') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Automated database archives, media snapshots, off-site retention, and one-click disaster recovery.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.open('backup-settings-modal')">
                <x-icon name="sliders" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Settings & Schedule') }}
            </x-ui.button>

            <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.open('backup-upload-modal')">
                <x-icon name="upload" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Upload Backup') }}
            </x-ui.button>

            <x-ui.button variant="default" size="sm" type="button" x-data x-on:click="$store.modals.open('backup-create-modal')">
                <x-icon name="plus" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Backup Now') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Stats Cards Grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Total Archives --}}
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5 shadow-2xs space-y-2">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-xs font-semibold uppercase tracking-wider">{{ __('Total Archives') }}</span>
                <span class="p-2 rounded-lg bg-secondary/60 text-foreground">
                    <x-icon name="archive" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $stats['total_count'] }}</div>
            <div class="text-[11px] text-muted-foreground flex items-center gap-1">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                {{ __('Retaining up to :count copies', ['count' => $settingsForm['retention_count']]) }}
            </div>
        </div>

        {{-- Total Storage Volume --}}
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5 shadow-2xs space-y-2">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-xs font-semibold uppercase tracking-wider">{{ __('Storage Utilized') }}</span>
                <span class="p-2 rounded-lg bg-secondary/60 text-foreground">
                    <x-icon name="hard-drive" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-2xl font-bold tracking-tight text-foreground">{{ $stats['total_size'] }}</div>
            <div class="text-[11px] text-muted-foreground">
                {{ __('Disk: :disk', ['disk' => ucfirst($settingsForm['storage_disk'])]) }}
            </div>
        </div>

        {{-- Latest Backup --}}
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5 shadow-2xs space-y-2">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-xs font-semibold uppercase tracking-wider">{{ __('Latest Backup') }}</span>
                <span class="p-2 rounded-lg bg-secondary/60 text-foreground">
                    <x-icon name="clock" class="h-4 w-4"/>
                </span>
            </div>
            <div class="text-base font-bold truncate text-foreground">
                {{ $stats['latest'] ? $stats['latest']->created_at->diffForHumans() : __('No backups yet') }}
            </div>
            <div class="text-[11px] text-muted-foreground truncate">
                {{ $stats['latest'] ? $stats['latest']->filename : __('Trigger your first backup now') }}
            </div>
        </div>

        {{-- Automated Schedule --}}
        <div class="rounded-xl border border-border bg-card p-4 sm:p-5 shadow-2xs space-y-2">
            <div class="flex items-center justify-between text-muted-foreground">
                <span class="text-xs font-semibold uppercase tracking-wider">{{ __('Automated Cron') }}</span>
                <span class="p-2 rounded-lg bg-secondary/60 text-foreground">
                    <x-icon name="refresh-cw" class="h-4 w-4"/>
                </span>
            </div>
            <div class="flex items-center gap-2">
                @if ($settingsForm['auto_backup_enabled'])
                    <x-ui.badge color="emerald" class="text-xs">{{ __('Active') }}</x-ui.badge>
                    <span class="text-xs font-medium text-foreground capitalize">{{ $settingsForm['schedule_frequency'] }} ({{ $settingsForm['schedule_time'] }})</span>
                @else
                    <x-ui.badge color="secondary" class="text-xs">{{ __('Disabled') }}</x-ui.badge>
                @endif
            </div>
            <div class="text-[11px] text-muted-foreground flex items-center justify-between">
                <span>{{ __('Next Run:') }}</span>
                <span class="font-medium text-foreground">{{ $stats['cron_job']?->next_run_at ? $stats['cron_job']->next_run_at->diffForHumans() : '—' }}</span>
            </div>
        </div>
    </div>

    {{-- Filter Bar & Table --}}
    <div class="rounded-xl border border-border bg-card shadow-2xs overflow-hidden space-y-4 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 flex-1">
                {{-- Search Input --}}
                <div class="relative w-full sm:w-64">
                    <x-icon name="search" class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"/>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="{{ __('Search by filename, UUID...') }}"
                        class="w-full pl-9 pr-4 py-1.5 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground placeholder:text-muted-foreground"
                    />
                </div>

                {{-- Type Filter --}}
                <select
                    wire:model.live="typeFilter"
                    class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground"
                >
                    <option value="all">{{ __('All Backup Scopes') }}</option>
                    <option value="database_only">{{ __('Database Only') }}</option>
                    <option value="full_with_media">{{ __('Full (DB + Media)') }}</option>
                </select>

                {{-- Status Filter --}}
                <select
                    wire:model.live="statusFilter"
                    class="py-1.5 px-3 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground"
                >
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="completed">{{ __('Completed') }}</option>
                    <option value="failed">{{ __('Failed') }}</option>
                    <option value="restored">{{ __('Restored') }}</option>
                </select>
            </div>

            {{-- Bulk Actions & Report Trigger --}}
            <div class="flex items-center gap-2">
                @if (! empty($selectedIds))
                    <x-ui.button
                        variant="destructive"
                        size="sm"
                        type="button"
                        x-data
                        x-on:click="$store.modals.open('backup-bulk-delete-modal')"
                    >
                        <x-icon name="trash" class="h-3.5 w-3.5 mr-1"/>
                        {{ __('Delete Selected (:count)', ['count' => count($selectedIds)]) }}
                    </x-ui.button>
                @endif

                <x-ui.button variant="outline" size="sm" type="button" wire:click="sendReportNow">
                    <x-icon name="mail" class="h-3.5 w-3.5 mr-1"/>
                    {{ __('Email Report') }}
                </x-ui.button>
            </div>
        </div>

        {{-- Desktop Backups Table --}}
        <div class="hidden md:block overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase text-[10px] tracking-wider border-b border-border">
                    <tr>
                        <th class="p-3 w-8 text-center">
                            <input
                                type="checkbox"
                                wire:model.live="selectAll"
                                class="rounded border-border text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"
                            />
                        </th>
                        <th class="p-3">{{ __('Archive / Filename') }}</th>
                        <th class="p-3">{{ __('Scope') }}</th>
                        <th class="p-3">{{ __('Size') }}</th>
                        <th class="p-3">{{ __('Tables / Rows') }}</th>
                        <th class="p-3">{{ __('Trigger') }}</th>
                        <th class="p-3">{{ __('Created At') }}</th>
                        <th class="p-3">{{ __('Status') }}</th>
                        <th class="p-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($backups as $b)
                        <tr wire:key="backup-row-{{ $b->id }}" class="hover:bg-secondary/15 transition-colors">
                            <td class="p-3 text-center">
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedIds"
                                    value="{{ $b->id }}"
                                    class="rounded border-border text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"
                                />
                            </td>
                            <td class="p-3 font-medium text-foreground">
                                <div class="flex items-center gap-2">
                                    <x-icon name="{{ $b->isZip() ? 'file-archive' : 'database' }}" class="h-4 w-4 text-primary shrink-0"/>
                                    <div class="truncate max-w-[240px]" title="{{ $b->filename }}">
                                        {{ $b->filename }}
                                    </div>
                                </div>
                                <div class="text-[10px] text-muted-foreground font-mono mt-0.5">
                                    {{ substr($b->uuid, 0, 18) }}...
                                </div>
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$b->type === 'full_with_media' ? 'indigo' : 'secondary'" class="text-[10px]">
                                    {{ $b->type === 'full_with_media' ? __('Full + Media') : __('Database') }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3 font-semibold text-foreground">
                                {{ $b->formattedSize() }}
                            </td>
                            <td class="p-3 text-muted-foreground">
                                <div class="text-foreground font-medium">{{ $b->tables_count }} {{ __('tables') }}</div>
                                <div class="text-[10px]">{{ number_format($b->records_count) }} {{ __('rows') }} @if ($b->files_count > 0) &bull; {{ $b->files_count }} {{ __('files') }} @endif</div>
                            </td>
                            <td class="p-3">
                                <span class="text-[11px] text-muted-foreground flex items-center gap-1">
                                    <x-icon name="{{ match($b->trigger_type) { 'scheduled' => 'clock', 'pre_restore' => 'shield', default => 'user' } }}" class="h-3 w-3"/>
                                    {{ $b->triggerLabel() }}
                                </span>
                            </td>
                            <td class="p-3 text-muted-foreground">
                                <div class="text-foreground font-medium">{{ $b->created_at->format('d M Y, h:i A') }}</div>
                                <div class="text-[10px]">{{ $b->created_at->diffForHumans() }} &bull; {{ $b->creator?->name ?? __('System') }}</div>
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$b->statusBadgeColor()" class="text-[10px] capitalize">
                                    {{ $b->status }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3 text-right">
                                <x-ui.dropdown width="w-44" align="end">
                                    <x-slot:trigger>
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer ml-auto">
                                            <x-icon name="more-vertical" class="h-4 w-4"/>
                                        </button>
                                    </x-slot:trigger>

                                    <x-ui.dropdown.item icon="download" wire:click="download({{ $b->id }})">
                                        {{ __('Download File') }}
                                    </x-ui.dropdown.item>

                                    <x-ui.dropdown.item icon="rotate-ccw" wire:click="openRestoreModal({{ $b->id }})">
                                        {{ __('Restore Database') }}
                                    </x-ui.dropdown.item>

                                    <x-ui.dropdown.item icon="mail" wire:click="openEmailModal({{ $b->id }})">
                                        {{ __('Email Copy') }}
                                    </x-ui.dropdown.item>

                                    <x-ui.dropdown.item icon="info" wire:click="viewDetails({{ $b->id }})">
                                        {{ __('Inspect Manifest') }}
                                    </x-ui.dropdown.item>

                                    <x-ui.dropdown.separator/>

                                    <x-ui.dropdown.item icon="trash-2" danger wire:click="openDeleteModal({{ $b->id }})">
                                        {{ __('Delete Archive') }}
                                    </x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-12 text-center text-muted-foreground">
                                <x-icon name="database" class="mx-auto h-8 w-8 mb-2 opacity-40"/>
                                <p class="text-sm font-medium">{{ __('No backup archives found') }}</p>
                                <p class="text-xs mt-1">{{ __('Generate a manual backup or adjust your search filter.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile Cards Layout (< md) --}}
        <div class="md:hidden space-y-3">
            @forelse ($backups as $b)
                <div wire:key="backup-card-{{ $b->id }}" class="p-4 rounded-lg border border-border bg-card/60 space-y-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <x-icon name="file-archive" class="h-4 w-4 text-primary shrink-0"/>
                            <div class="truncate text-xs font-semibold text-foreground" title="{{ $b->filename }}">
                                {{ $b->filename }}
                            </div>
                        </div>
                        <x-ui.badge :color="$b->statusBadgeColor()" class="text-[10px] capitalize shrink-0">
                            {{ $b->status }}
                        </x-ui.badge>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs text-muted-foreground bg-secondary/20 p-2.5 rounded-md">
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-muted-foreground/70">{{ __('Size') }}</span>
                            <div class="font-medium text-foreground">{{ $b->formattedSize() }}</div>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-muted-foreground/70">{{ __('Scope') }}</span>
                            <div class="font-medium text-foreground">{{ $b->type === 'full_with_media' ? __('Full + Media') : __('Database') }}</div>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-muted-foreground/70">{{ __('Tables / Rows') }}</span>
                            <div class="font-medium text-foreground">{{ $b->tables_count }} tbl &bull; {{ number_format($b->records_count) }} rows</div>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-semibold text-muted-foreground/70">{{ __('Date') }}</span>
                            <div class="font-medium text-foreground">{{ $b->created_at->format('d M, h:i A') }}</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <x-ui.button variant="outline" size="sm" class="flex-1 text-xs" wire:click="download({{ $b->id }})">
                            <x-icon name="download" class="h-3 w-3 mr-1"/>
                            {{ __('Download') }}
                        </x-ui.button>

                        <x-ui.button variant="outline" size="sm" class="flex-1 text-xs" wire:click="openRestoreModal({{ $b->id }})">
                            <x-icon name="rotate-ccw" class="h-3 w-3 mr-1"/>
                            {{ __('Restore') }}
                        </x-ui.button>

                        <x-ui.button variant="outline" size="sm" class="text-destructive text-xs" wire:click="openDeleteModal({{ $b->id }})">
                            <x-icon name="trash" class="h-3 w-3"/>
                        </x-ui.button>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center text-muted-foreground text-xs">
                    {{ __('No backup archives found.') }}
                </div>
            @endforelse
        </div>

        {{-- Pagination --}}
        @if ($backups->hasPages())
            <div class="pt-2 border-t border-border">
                {{ $backups->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL 1: Create Backup Now --}}
    <x-ui.modal name="backup-create-modal" max-width="max-w-lg" :title="__('Create Immediate Backup Archive')" :description="__('Dump database schema, all data records, and optionally bundle uploaded storage media.')">
        <form wire:submit="triggerBackup" class="space-y-4">
            <x-ui.select
                wire:model="createForm.type"
                :label="__('Backup Scope') . ' *'"
                :options="[
                    'full_with_media' => __('Full System Archive (Database Dump + Public Uploads & Media)'),
                    'database_only' => __('Database Schema & Data Only (.sql / .sqlite dump)'),
                ]"
            />

            <x-ui.input
                wire:model="createForm.name"
                :label="__('Custom Backup Label (Optional)')"
                placeholder="e.g. pre_migration, sprint_release"
                :description="__('A timestamp and unique UUID will be automatically appended.')"
            />

            <x-ui.select
                wire:model="createForm.disk"
                :label="__('Destination Storage Disk') . ' *'"
                :options="array_combine($disks, array_map('ucfirst', $disks))"
            />

            <div class="p-3.5 rounded-lg border border-border bg-secondary/10 space-y-3">
                <x-ui.switch
                    wire:model.live="createForm.send_email"
                    :label="__('Dispatch Email Copy / Notification')"
                    :description="__('Sends a completion report. If the archive is <= :mb MB, the .zip is attached directly.', ['mb' => $settingsForm['email_attachment_max_mb']])"
                />

                @if ($createForm['send_email'])
                    <x-ui.input
                        wire:model="createForm.recipient_email"
                        type="email"
                        :label="__('Recipient Email Address')"
                        placeholder="admin@sntcssc.in"
                    />
                @endif
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-create-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" type="submit">
                    <x-icon name="play" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Start Backup') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- MODAL 2: Backup Settings & Automation Drawer / Modal --}}
    <x-ui.modal name="backup-settings-modal" max-width="max-w-2xl" :title="__('Backup Automation & Retention Policies')" :description="__('Configure automated cron schedules, retention limits, storage locations, and email thresholds.')">
        <form wire:submit="saveSettings" class="space-y-4">
            {{-- Section 1: Automated Scheduling --}}
            <div class="p-4 rounded-xl border border-border bg-secondary/15 space-y-3">
                <h3 class="text-xs font-bold text-foreground uppercase tracking-wider">{{ __('1. Automated Backup Schedule') }}</h3>

                <x-ui.switch
                    wire:model.live="settingsForm.auto_backup_enabled"
                    :label="__('Enable Automated Scheduled Backups')"
                    :description="__('Executes via the SNT CSSC MIS internal cron scheduler.')"
                />

                @if ($settingsForm['auto_backup_enabled'])
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                        <x-ui.select
                            wire:model="settingsForm.schedule_frequency"
                            :label="__('Frequency') . ' *'"
                            :options="[
                                'daily' => __('Daily Routine'),
                                'weekly' => __('Weekly Routine (Sundays)'),
                                'monthly' => __('Monthly Routine (1st of month)'),
                            ]"
                        />

                        <x-ui.input
                            wire:model="settingsForm.schedule_time"
                            type="time"
                            :label="__('Execution Time (24h)') . ' *'"
                        />
                    </div>
                @endif
            </div>

            {{-- Section 2: Retention Policies --}}
            <div class="p-4 rounded-xl border border-border bg-secondary/15 space-y-3">
                <h3 class="text-xs font-bold text-foreground uppercase tracking-wider">{{ __('2. Retention & Pruning Limits') }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-ui.input
                        wire:model="settingsForm.retention_count"
                        type="number"
                        min="1"
                        max="365"
                        :label="__('Max Backup Copies to Keep') . ' *'"
                        :description="__('Deletes the oldest archives when the limit is reached.')"
                    />

                    <x-ui.input
                        wire:model="settingsForm.retention_days"
                        type="number"
                        min="1"
                        max="3650"
                        :label="__('Retention Window (Days)') . ' *'"
                        :description="__('Purges archives older than this threshold.')"
                    />
                </div>
            </div>

            {{-- Section 3: Scope & Storage --}}
            <div class="p-4 rounded-xl border border-border bg-secondary/15 space-y-3">
                <h3 class="text-xs font-bold text-foreground uppercase tracking-wider">{{ __('3. Default Scope & Storage Disk') }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-ui.select
                        wire:model="settingsForm.default_scope"
                        :label="__('Default Backup Scope') . ' *'"
                        :options="[
                            'full_with_media' => __('Full (Database + Media Uploads)'),
                            'database_only' => __('Database Schema & Records Only'),
                        ]"
                    />

                    <x-ui.select
                        wire:model="settingsForm.storage_disk"
                        :label="__('Storage Disk Target') . ' *'"
                        :options="array_combine($disks, array_map('ucfirst', $disks))"
                    />
                </div>
            </div>

            {{-- Section 4: Email Notifications & Attachment Limits --}}
            <div class="p-4 rounded-xl border border-border bg-secondary/15 space-y-3">
                <h3 class="text-xs font-bold text-foreground uppercase tracking-wider">{{ __('4. Email Delivery & Attachments') }}</h3>

                <x-ui.input
                    wire:model="settingsForm.notification_email"
                    type="email"
                    :label="__('Notification & Report Recipient Email')"
                    placeholder="admin@sntcssc.in"
                />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-ui.switch
                        wire:model="settingsForm.email_on_success"
                        :label="__('Email on Backup Success')"
                        :description="__('Sends completion notification after each backup.')"
                    />

                    <x-ui.switch
                        wire:model="settingsForm.email_on_failure"
                        :label="__('Email Alert on Failure')"
                        :description="__('Urgent notification if backup encounters errors.')"
                    />
                </div>

                <x-ui.input
                    wire:model="settingsForm.email_attachment_max_mb"
                    type="number"
                    min="1"
                    max="100"
                    :label="__('Max Attachment Threshold (MB)') . ' *'"
                    :description="__('If backup size <= threshold, .zip is attached. If larger, an email summary with dashboard download link is sent.')"
                />
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-settings-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" type="submit">
                    <x-icon name="check" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Save Policies') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- MODAL 3: Restore Confirmation with Safety Pre-Snapshot --}}
    <x-ui.modal name="backup-restore-modal" max-width="max-w-lg" :title="__('Restore Database from Backup')" :description="__('Replace current application database and files with data from the selected archive.')">
        @if ($viewingBackup)
            <div class="space-y-4">
                <div class="p-3.5 rounded-lg border border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-200 text-xs space-y-1.5">
                    <div class="flex items-center gap-2 font-bold">
                        <x-icon name="alert-triangle" class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0"/>
                        <span>{{ __('Warning: Database Overwrite Operation') }}</span>
                    </div>
                    <p>{{ __('Restoring from this backup will overwrite current database records with the snapshot from :date.', ['date' => $viewingBackup->created_at->format('d M Y, h:i A')]) }}</p>
                </div>

                <div class="p-3 rounded-lg border border-border bg-card text-xs space-y-1.5">
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ __('Archive:') }}</span> <span class="font-semibold text-foreground">{{ $viewingBackup->filename }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ __('Size:') }}</span> <span class="font-semibold text-foreground">{{ $viewingBackup->formattedSize() }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ __('Tables / Rows:') }}</span> <span class="font-semibold text-foreground">{{ $viewingBackup->tables_count }} tables &bull; {{ number_format($viewingBackup->records_count) }} rows</span></div>
                </div>

                <div class="p-3 rounded-lg border border-border bg-secondary/10">
                    <x-ui.switch
                        wire:model="createPreRestoreSnapshot"
                        :label="__('Create Safety Pre-Restore Snapshot (Recommended)')"
                        :description="__('Automatically creates a fresh backup of the database right now before applying the restore.')"
                    />
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-restore-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button variant="destructive" type="button" wire:click="executeRestore">
                        <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1.5"/>
                        {{ __('Yes, Restore Now') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- MODAL 4: Upload External Backup --}}
    <x-ui.modal name="backup-upload-modal" max-width="max-w-md" :title="__('Upload Backup Archive')" :description="__('Import an existing .zip or .sql backup file to the local storage.')">
        <form wire:submit="uploadBackup" class="space-y-4">
            <x-ui.input
                wire:model="uploadedFile"
                type="file"
                accept=".zip,.sql"
                :label="__('Select Backup File (.zip, .sql)') . ' *'"
            />

            <div class="p-3 rounded-lg border border-border bg-secondary/10">
                <x-ui.switch
                    wire:model="restoreUploadedImmediately"
                    :label="__('Restore Database Immediately Upon Upload')"
                    :description="__('Applies the uploaded snapshot into the active database immediately after uploading.')"
                />
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-upload-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" type="submit">
                    <x-icon name="upload" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Upload Archive') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- MODAL 5: Email Backup Copy --}}
    <x-ui.modal name="backup-email-modal" max-width="max-w-md" :title="__('Email Backup Copy')" :description="__('Send a backup completion notice and attachment to a specified email address.')">
        <form wire:submit="sendEmail" class="space-y-4">
            <x-ui.input
                wire:model="customEmailRecipient"
                type="email"
                :label="__('Recipient Email Address') . ' *'"
                placeholder="admin@sntcssc.in"
            />

            <p class="text-xs text-muted-foreground">
                {{ __('If the file size is <= :mb MB, the .zip will be attached directly. Otherwise, a secure summary with download link is sent.', ['mb' => $settingsForm['email_attachment_max_mb']]) }}
            </p>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-email-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" type="submit">
                    <x-icon name="send" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Send Email') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- MODAL 6: Inspect Backup Manifest Details --}}
    <x-ui.modal name="backup-details-modal" max-width="max-w-lg" :title="__('Backup Manifest & Details')" :description="__('Detailed archive metadata, checksums, and table summary.')">
        @if ($viewingBackup)
            <div class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-2 p-3 rounded-lg border border-border bg-secondary/15">
                    <div><span class="text-muted-foreground">{{ __('UUID:') }}</span> <div class="font-mono font-medium text-foreground text-[11px]">{{ $viewingBackup->uuid }}</div></div>
                    <div><span class="text-muted-foreground">{{ __('Disk:') }}</span> <div class="font-medium text-foreground uppercase">{{ $viewingBackup->disk }}</div></div>
                    <div><span class="text-muted-foreground">{{ __('Database Engine:') }}</span> <div class="font-medium text-foreground uppercase">{{ $viewingBackup->db_driver }}</div></div>
                    <div><span class="text-muted-foreground">{{ __('Duration:') }}</span> <div class="font-medium text-foreground">{{ $viewingBackup->duration_seconds }}s</div></div>
                </div>

                <div class="p-3 rounded-lg border border-border bg-card space-y-1">
                    <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('SHA256 Checksum') }}</span>
                    <div class="font-mono text-[11px] text-foreground break-all bg-secondary/30 p-1.5 rounded">{{ $viewingBackup->checksum ?? '—' }}</div>
                </div>

                <div class="p-3 rounded-lg border border-border bg-card space-y-1">
                    <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Storage File Path') }}</span>
                    <div class="font-mono text-[11px] text-foreground break-all bg-secondary/30 p-1.5 rounded">{{ $viewingBackup->path }}</div>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-details-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- MODAL 7: Delete Confirmation --}}
    <x-ui.modal name="backup-delete-modal" max-width="max-w-md" :title="__('Delete Backup Archive')" :description="__('Permanently remove this backup archive from storage and database records.')">
        <p class="text-xs text-muted-foreground">
            {{ __('Are you sure you want to delete this backup file? This operation cannot be undone.') }}
        </p>

        <div class="flex justify-end gap-2 pt-4 border-t border-border">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-delete-modal')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button variant="destructive" type="button" wire:click="deleteSingle">
                <x-icon name="trash" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Delete') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- MODAL 8: Bulk Delete Confirmation --}}
    <x-ui.modal name="backup-bulk-delete-modal" max-width="max-w-md" :title="__('Bulk Delete Backups')" :description="__('Permanently remove multiple backup archives from disk.')">
        <p class="text-xs text-muted-foreground">
            {{ __('Are you sure you want to permanently delete :count selected backup archive(s)?', ['count' => count($selectedIds)]) }}
        </p>

        <div class="flex justify-end gap-2 pt-4 border-t border-border">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('backup-bulk-delete-modal')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button variant="destructive" type="button" wire:click="bulkDelete">
                <x-icon name="trash" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Delete Selected') }}
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>