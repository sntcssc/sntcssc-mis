<?php

use App\Models\CronJob;
use App\Services\AuditLogService;
use App\Support\Toast;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Cron Jobs & Scheduled Tasks')] class extends Component {
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all'; // 'all', 'active', 'inactive', 'failed'

    public bool $showTrashed = false;

    // Form modal state
    public bool $modalOpen = false;
    public ?int $editingJobId = null;
    public string $name = '';
    public string $command = '';
    public string $frequencyPreset = '0 0 * * *';
    public string $customExpression = '* * * * *';
    public string $description = '';
    public string $argumentsText = '';
    public bool $is_active = true;
    public bool $run_in_background = true;
    public bool $without_overlapping = true;

    // Output inspection modal state
    public bool $outputModalOpen = false;
    public ?CronJob $inspectingJob = null;

    // Running state for row-level spinners
    public ?int $runningJobId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedShowTrashed(): void
    {
        $this->resetPage();
    }

    public function updatedFrequencyPreset(string $val): void
    {
        if ($val !== 'custom') {
            $this->customExpression = $val;
        }
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingJobId = null;
        $this->name = '';
        $this->command = '';
        $this->frequencyPreset = '0 0 * * *';
        $this->customExpression = '0 0 * * *';
        $this->description = '';
        $this->argumentsText = '';
        $this->is_active = true;
        $this->run_in_background = true;
        $this->without_overlapping = true;
        $this->modalOpen = true;
    }

    public function openEditModal(int $id): void
    {
        $this->resetValidation();
        $job = CronJob::withTrashed()->findOrFail($id);
        $this->editingJobId = $job->id;
        $this->name = $job->name;
        $this->command = $job->command;
        $this->customExpression = $job->expression;

        $presets = [
            '* * * * *', '*/2 * * * *', '*/5 * * * *', '*/10 * * * *', '*/15 * * * *', '*/30 * * * *',
            '0 * * * *', '0 */2 * * *', '0 */6 * * *', '0 */12 * * *', '0 0 * * *', '0 2 * * *',
            '0 3 * * *', '0 0 * * 0', '0 0 1 * *',
        ];

        $this->frequencyPreset = in_array($job->expression, $presets) ? $job->expression : 'custom';
        $this->description = $job->description ?? '';
        $this->argumentsText = $job->arguments ? json_encode($job->arguments, JSON_PRETTY_PRINT) : '';
        $this->is_active = (bool) $job->is_active;
        $this->run_in_background = (bool) $job->run_in_background;
        $this->without_overlapping = (bool) $job->without_overlapping;
        $this->modalOpen = true;
    }

    public function saveJob(): void
    {
        $expression = $this->frequencyPreset === 'custom' ? trim($this->customExpression) : $this->frequencyPreset;

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'command' => ['required', 'string', 'max:255'],
            'customExpression' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($expression) {
                    try {
                        new CronExpression($expression);
                    } catch (\Throwable) {
                        $fail(__('Invalid cron expression syntax. Expected standard 5-field format e.g. "0 0 * * *".'));
                    }
                },
            ],
            'argumentsText' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if (! empty(trim($value))) {
                        $decoded = json_decode($value, true);
                        if (json_last_error() !== JSON_ERROR_NONE && ! is_array($decoded)) {
                            $fail(__('Arguments must be valid JSON format (e.g. {"--hours": 168}).'));
                        }
                    }
                },
            ],
        ]);

        try {
            DB::transaction(function () use ($expression) {
                $args = ! empty(trim($this->argumentsText)) ? json_decode($this->argumentsText, true) : null;

                $data = [
                    'name' => $this->name,
                    'command' => $this->command,
                    'expression' => $expression,
                    'arguments' => $args,
                    'description' => $this->description ?: null,
                    'is_active' => $this->is_active,
                    'run_in_background' => $this->run_in_background,
                    'without_overlapping' => $this->without_overlapping,
                    'updated_by' => auth()->id(),
                ];

                if ($this->editingJobId) {
                    $job = CronJob::withTrashed()->findOrFail($this->editingJobId);
                    $data['next_run_at'] = $job->calculateNextRunAt();
                    $job->update($data);
                    Toast::dispatch($this, 'success', __('Cron job updated successfully.'));
                } else {
                    $data['created_by'] = auth()->id();
                    $job = CronJob::create($data);
                    $job->update(['next_run_at' => $job->calculateNextRunAt()]);
                    Toast::dispatch($this, 'success', __('Cron job created successfully.'));
                }

                AuditLogService::log(
                    event: $this->editingJobId ? 'cron_job_updated' : 'cron_job_created',
                    description: "Saved cron job '{$job->name}' with schedule '{$job->expression}'",
                    userId: auth()->id()
                );

                $this->modalOpen = false;
            });
        } catch (\Throwable $e) {
            Log::error('Failed to save cron job: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save cron job: :error', ['error' => $e->getMessage()]));
        }
    }

    public function toggleActive(int $id): void
    {
        try {
            $job = CronJob::withTrashed()->findOrFail($id);
            $job->update([
                'is_active' => ! $job->is_active,
                'updated_by' => auth()->id(),
            ]);

            Toast::dispatch($this, 'success', $job->is_active ? __('Cron job activated.') : __('Cron job deactivated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to toggle status.'));
        }
    }

    public function runNow(int $id): void
    {
        $this->runningJobId = $id;

        try {
            $job = CronJob::withTrashed()->findOrFail($id);
            $result = $job->run();

            AuditLogService::log(
                event: 'cron_job_executed_manually',
                description: "Manually triggered cron job '{$job->name}'. Result: ".($result['success'] ? 'SUCCESS' : 'FAILED'),
                userId: auth()->id()
            );

            if ($result['success']) {
                Toast::dispatch($this, 'success', __("Cron job ':name' executed successfully in :sec seconds.", ['name' => $job->name, 'sec' => $result['duration']]));
            } else {
                Toast::dispatch($this, 'error', __("Cron job ':name' failed during execution.", ['name' => $job->name]));
            }

            // Open output inspector automatically
            $this->inspectingJob = $job->fresh();
            $this->outputModalOpen = true;
        } catch (\Throwable $e) {
            Log::error('Manual cron execution failed: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Execution failed: :error', ['error' => $e->getMessage()]));
        } finally {
            $this->runningJobId = null;
        }
    }

    public function openOutputModal(int $id): void
    {
        $this->inspectingJob = CronJob::withTrashed()->findOrFail($id);
        $this->outputModalOpen = true;
    }

    public function deleteJob(int $id): void
    {
        try {
            $job = CronJob::findOrFail($id);
            $job->delete();

            AuditLogService::log(
                event: 'cron_job_deleted',
                description: "Soft deleted cron job '{$job->name}'",
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Cron job moved to trash.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete cron job.'));
        }
    }

    public function restoreJob(int $id): void
    {
        try {
            $job = CronJob::onlyTrashed()->findOrFail($id);
            $job->restore();

            AuditLogService::log(
                event: 'cron_job_restored',
                description: "Restored cron job '{$job->name}'",
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Cron job restored.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to restore cron job.'));
        }
    }

    public function forceDeleteJob(int $id): void
    {
        try {
            $job = CronJob::onlyTrashed()->findOrFail($id);
            $name = $job->name;
            $job->forceDelete();

            AuditLogService::log(
                event: 'cron_job_force_deleted',
                description: "Permanently deleted cron job '{$name}'",
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Cron job permanently deleted.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to permanently delete cron job.'));
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => CronJob::count(),
            'active' => CronJob::active()->count(),
            'failed' => CronJob::failed()->count(),
            'successful' => CronJob::successful()->count(),
        ];
    }

    public function with(): array
    {
        $query = $this->showTrashed ? CronJob::onlyTrashed() : CronJob::query();

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('command', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('expression', 'like', $term);
            });
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        } elseif ($this->statusFilter === 'failed') {
            $query->where('last_run_status', CronJob::STATUS_FAILED);
        }

        $jobs = $query->latest('id')->paginate(15);

        return [
            'jobs' => $jobs,
        ];
    }
}; ?>

<div class="space-y-6 max-w-7xl">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <x-icon name="clock" class="h-6 w-6"/>
            </div>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Cron Jobs & Scheduled Tasks') }}</h1>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Manage automated system background tasks, frequency timings, execution logs, on-demand manual triggers, and schedule health.') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <x-ui.button wire:click="openCreateModal">
                <x-icon name="plus" class="h-4 w-4 mr-1.5"/>
                {{ __('New Cron Job') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Metric Stat Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-muted-foreground">{{ __('Total Tasks') }}</span>
                <div class="text-2xl font-bold text-foreground mt-0.5">{{ $this->stats['total'] }}</div>
            </div>
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary text-foreground">
                <x-icon name="layout-grid" class="h-5 w-5"/>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-muted-foreground">{{ __('Active Schedules') }}</span>
                <div class="text-2xl font-bold text-emerald-600 mt-0.5">{{ $this->stats['active'] }}</div>
            </div>
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                <x-icon name="activity" class="h-5 w-5"/>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-muted-foreground">{{ __('Successful Last Run') }}</span>
                <div class="text-2xl font-bold text-primary mt-0.5">{{ $this->stats['successful'] }}</div>
            </div>
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <x-icon name="check-circle-2" class="h-5 w-5"/>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-xs font-medium text-muted-foreground">{{ __('Failed Tasks') }}</span>
                <div class="text-2xl font-bold text-rose-500 mt-0.5">{{ $this->stats['failed'] }}</div>
            </div>
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500/10 text-rose-500">
                <x-icon name="alert-triangle" class="h-5 w-5"/>
            </div>
        </div>
    </div>

    {{-- Filter Toolbar --}}
    <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3.5 rounded-xl border border-border bg-card p-4 shadow-xs">
        {{-- Status Selector Pills --}}
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-thin scrollbar-thumb-border scrollbar-track-transparent">
            @foreach (['all' => 'All Tasks', 'active' => 'Active', 'inactive' => 'Inactive', 'failed' => 'Failed'] as $sKey => $sLabel)
                <button
                    type="button"
                    wire:click="$set('statusFilter', '{{ $sKey }}')"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer shrink-0 {{ $statusFilter === $sKey ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-secondary/70 hover:text-foreground' }}"
                >
                    {{ __($sLabel) }}
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2.5 w-full lg:w-auto">
            <div class="relative flex-1 sm:w-64">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, command...') }}"
                    class="h-9 w-full rounded-lg border border-input bg-transparent pl-9 pr-3 text-xs outline-none focus:border-ring"
                />
            </div>

            <button
                type="button"
                wire:click="$toggle('showTrashed')"
                class="h-9 px-3 rounded-lg border border-border text-xs font-medium flex items-center gap-1.5 cursor-pointer transition-colors shrink-0 {{ $showTrashed ? 'bg-amber-500/10 text-amber-600 border-amber-500/20' : 'text-muted-foreground hover:bg-secondary/50' }}"
            >
                <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                <span>{{ $showTrashed ? __('Viewing Trash') : __('Trash') }}</span>
            </button>
        </div>
    </div>

    {{-- Scheduled Tasks Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-border bg-secondary/30 text-muted-foreground uppercase text-[10px] font-semibold tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Task Name & Command') }}</th>
                        <th class="px-4 py-3">{{ __('Frequency / Expression') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Last Execution') }}</th>
                        <th class="px-4 py-3">{{ __('Next Due') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($jobs as $job)
                        <tr class="hover:bg-secondary/15 transition-colors {{ $job->trashed() ? 'opacity-70 bg-rose-500/5' : '' }}">
                            {{-- Name & Command --}}
                            <td class="px-4 py-3.5">
                                <div class="font-bold text-foreground text-sm flex items-center gap-2">
                                    <span>{{ $job->name }}</span>
                                    @if ($job->trashed())
                                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-rose-500/10 text-rose-600 border border-rose-500/20 uppercase">
                                            {{ __('Trashed') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1.5 mt-1 font-mono text-[11px] text-muted-foreground">
                                    <span class="px-1.5 py-0.5 rounded bg-secondary text-foreground text-[10px] border border-border">php artisan</span>
                                    <span class="font-semibold text-foreground truncate max-w-xs">{{ $job->command }}</span>
                                </div>
                                @if ($job->description)
                                    <p class="text-[11px] text-muted-foreground mt-1 line-clamp-1">{{ $job->description }}</p>
                                @endif
                            </td>

                            {{-- Frequency & Cron Expression --}}
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                <div class="font-medium text-foreground text-xs">{{ $job->getHumanFrequency() }}</div>
                                <div class="font-mono text-[11px] text-muted-foreground mt-0.5 flex items-center gap-1">
                                    <x-icon name="clock" class="h-3 w-3 text-primary/70"/>
                                    <code>{{ $job->expression }}</code>
                                </div>
                            </td>

                            {{-- Status Toggle --}}
                            <td class="px-4 py-3.5 text-center whitespace-nowrap">
                                @if (! $job->trashed())
                                    <button
                                        type="button"
                                        wire:click="toggleActive({{ $job->id }})"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold transition-colors cursor-pointer {{ $job->is_active ? 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 border border-emerald-500/20' : 'bg-secondary text-muted-foreground hover:bg-secondary/80 border border-border' }}"
                                    >
                                        <span class="size-1.5 rounded-full {{ $job->is_active ? 'bg-emerald-500 animate-pulse' : 'bg-muted-foreground' }}"></span>
                                        <span>{{ $job->is_active ? __('Active') : __('Disabled') }}</span>
                                    </button>
                                @else
                                    <span class="text-xs text-muted-foreground">—</span>
                                @endif
                            </td>

                            {{-- Last Execution & Duration --}}
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                @if ($job->last_run_at)
                                    <div class="flex items-center gap-1.5">
                                        @if ($job->last_run_status === 'success')
                                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600">
                                                <x-icon name="check-circle" class="h-3.5 w-3.5"/>
                                                {{ __('Success') }} ({{ $job->last_run_duration ?? '0' }}s)
                                            </span>
                                        @elseif ($job->last_run_status === 'failed')
                                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-500">
                                                <x-icon name="alert-circle" class="h-3.5 w-3.5"/>
                                                {{ __('Failed') }}
                                            </span>
                                        @elseif ($job->last_run_status === 'running')
                                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-500">
                                                <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin"/>
                                                {{ __('Running') }}
                                            </span>
                                        @endif

                                        @if ($job->last_run_output)
                                            <button
                                                type="button"
                                                wire:click="openOutputModal({{ $job->id }})"
                                                class="text-[10px] text-primary hover:underline ml-1 cursor-pointer"
                                                title="{{ __('View Output Log') }}"
                                            >
                                                {{ __('Log') }}
                                            </button>
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-muted-foreground mt-0.5">
                                        {{ $job->last_run_at->format('d M Y, h:i A') }}
                                    </div>
                                @else
                                    <span class="text-xs text-muted-foreground">{{ __('Never executed') }}</span>
                                @endif
                            </td>

                            {{-- Next Due Schedule --}}
                            <td class="px-4 py-3.5 whitespace-nowrap text-muted-foreground text-[11px]">
                                @if ($job->is_active && ! $job->trashed())
                                    <span class="font-medium text-foreground">
                                        {{ $job->next_run_at ? $job->next_run_at->diffForHumans() : $job->calculateNextRunAt()?->format('d M, h:i A') ?? '—' }}
                                    </span>
                                    <div class="text-[10px] text-muted-foreground">
                                        {{ $job->calculateNextRunAt()?->format('d M Y, h:i A') }}
                                    </div>
                                @else
                                    <span class="text-muted-foreground/60 italic">{{ __('Inactive') }}</span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if (! $job->trashed())
                                        {{-- Run Now Button --}}
                                        <button
                                            type="button"
                                            wire:click="runNow({{ $job->id }})"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-border bg-card hover:bg-secondary text-xs font-semibold text-foreground transition-colors cursor-pointer disabled:opacity-50"
                                            title="{{ __('Execute command immediately') }}"
                                        >
                                            @if ($runningJobId === $job->id)
                                                <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin text-primary"/>
                                                <span>{{ __('Running...') }}</span>
                                            @else
                                                <x-icon name="zap" class="h-3.5 w-3.5 text-amber-500"/>
                                                <span>{{ __('Run Now') }}</span>
                                            @endif
                                        </button>

                                        {{-- Edit Button --}}
                                        <x-ui.button size="sm" variant="outline" wire:click="openEditModal({{ $job->id }})">
                                            <x-icon name="edit-3" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Edit') }}
                                        </x-ui.button>

                                        {{-- Soft Delete --}}
                                        <button
                                            type="button"
                                            wire:click="deleteJob({{ $job->id }})"
                                            wire:confirm="{{ __('Move this cron job to trash?') }}"
                                            class="p-1.5 rounded-md hover:bg-rose-500/10 text-muted-foreground hover:text-rose-600 transition-colors cursor-pointer"
                                            title="{{ __('Move to Trash') }}"
                                        >
                                            <x-icon name="trash-2" class="h-4 w-4"/>
                                        </button>
                                    @else
                                        {{-- Restore --}}
                                        <x-ui.button size="sm" variant="outline" wire:click="restoreJob({{ $job->id }})">
                                            <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Restore') }}
                                        </x-ui.button>

                                        {{-- Force Delete --}}
                                        <button
                                            type="button"
                                            wire:click="forceDeleteJob({{ $job->id }})"
                                            wire:confirm="{{ __('Permanently delete this cron job? This cannot be undone.') }}"
                                            class="p-1.5 rounded-md hover:bg-rose-500/10 text-rose-600 transition-colors cursor-pointer"
                                            title="{{ __('Delete Permanently') }}"
                                        >
                                            <x-icon name="trash" class="h-4 w-4"/>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-12 text-muted-foreground">
                                <x-icon name="clock" class="h-9 w-9 mx-auto text-muted-foreground/40 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No scheduled cron jobs found.') }}</p>
                                <p class="text-xs text-muted-foreground/80 mt-1">{{ __('Click "New Cron Job" to register an automated background command.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($jobs->hasPages())
            <div class="p-4 border-t border-border">
                {{ $jobs->links() }}
            </div>
        @endif
    </div>

    {{-- Create / Edit Cron Job Modal --}}
    @if ($modalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-xl bg-card border border-border rounded-2xl shadow-xl overflow-hidden animate-in fade-in zoom-in-95">
                <form wire:submit="saveJob">
                    <div class="p-6 border-b border-border flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <x-icon name="clock" class="h-5 w-5"/>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-foreground">
                                    {{ $editingJobId ? __('Edit Scheduled Task') : __('Create New Cron Job') }}
                                </h3>
                                <p class="text-xs text-muted-foreground">{{ __('Configure automated command schedule expression and execution parameters.') }}</p>
                            </div>
                        </div>
                        <button type="button" wire:click="$set('modalOpen', false)" class="text-muted-foreground hover:text-foreground cursor-pointer">
                            <x-icon name="x" class="h-5 w-5"/>
                        </button>
                    </div>

                    <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                        {{-- Task Name --}}
                        <div>
                            <x-ui.input
                                wire:model="name"
                                :label="__('Job / Task Name') . ' *'"
                                placeholder="{{ __('e.g., Clean Expired Invitations') }}"
                                required
                            />
                        </div>

                        {{-- Artisan Command --}}
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ __('Artisan Command') }} *
                            </label>
                            <input
                                type="text"
                                wire:model="command"
                                placeholder="{{ __('e.g., model:prune or auth:clear-resets') }}"
                                class="h-9 w-full rounded-lg border border-input bg-transparent px-3 text-xs outline-none focus:border-ring font-mono"
                                required
                            />
                            <p class="text-[11px] text-muted-foreground">{{ __('Common commands: model:prune, auth:clear-resets, queue:prune-failed, teams:prune-invitations') }}</p>
                            @error('command') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        {{-- Frequency Preset Selector --}}
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ __('Schedule Frequency') }} *
                            </label>
                            <select
                                wire:model.live="frequencyPreset"
                                class="h-9 w-full rounded-lg border border-input bg-transparent px-3 text-xs outline-none focus:border-ring"
                            >
                                <option value="* * * * *">{{ __('Every Minute (* * * * *)') }}</option>
                                <option value="*/5 * * * *">{{ __('Every 5 Minutes (*/5 * * * *)') }}</option>
                                <option value="*/15 * * * *">{{ __('Every 15 Minutes (*/15 * * * *)') }}</option>
                                <option value="*/30 * * * *">{{ __('Every 30 Minutes (*/30 * * * *)') }}</option>
                                <option value="0 * * * *">{{ __('Hourly (0 * * * *)') }}</option>
                                <option value="0 */6 * * *">{{ __('Every 6 Hours (0 */6 * * *)') }}</option>
                                <option value="0 0 * * *">{{ __('Daily at Midnight (0 0 * * *)') }}</option>
                                <option value="0 2 * * *">{{ __('Daily at 2:00 AM (0 2 * * *)') }}</option>
                                <option value="0 3 * * *">{{ __('Daily at 3:00 AM (0 3 * * *)') }}</option>
                                <option value="0 0 * * 0">{{ __('Weekly on Sunday (0 0 * * 0)') }}</option>
                                <option value="0 0 1 * *">{{ __('Monthly on 1st (0 0 1 * *)') }}</option>
                                <option value="custom">{{ __('Custom Cron Expression...') }}</option>
                            </select>
                        </div>

                        {{-- Custom Cron Expression --}}
                        @if ($frequencyPreset === 'custom')
                            <div class="space-y-1.5 p-3 rounded-lg bg-secondary/30 border border-border">
                                <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                    {{ __('Custom Cron Expression (5 fields: min hour day month weekday)') }} *
                                </label>
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="customExpression"
                                    placeholder="0 0 * * *"
                                    class="h-9 w-full rounded-lg border border-input bg-transparent px-3 text-xs outline-none focus:border-ring font-mono"
                                    required
                                />
                                @error('customExpression') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        {{-- Arguments (Optional JSON) --}}
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ __('Command Arguments (JSON format, optional)') }}
                            </label>
                            <textarea
                                wire:model="argumentsText"
                                rows="2"
                                placeholder='{"--hours": 168}'
                                class="w-full rounded-lg border border-input bg-transparent p-2.5 text-xs outline-none focus:border-ring font-mono text-[11px]"
                            ></textarea>
                            @error('argumentsText') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        {{-- Description --}}
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ __('Description (Optional)') }}
                            </label>
                            <textarea
                                wire:model="description"
                                rows="2"
                                placeholder="{{ __('Explain the purpose of this scheduled task...') }}"
                                class="w-full rounded-lg border border-input bg-transparent p-2.5 text-xs outline-none focus:border-ring"
                            ></textarea>
                        </div>

                        {{-- Execution Flags --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-border">
                            <label class="flex items-center gap-2 text-xs font-medium cursor-pointer">
                                <input type="checkbox" wire:model="is_active" class="rounded border-input text-primary focus:ring-primary h-4 w-4"/>
                                <span>{{ __('Active Schedule') }}</span>
                            </label>

                            <label class="flex items-center gap-2 text-xs font-medium cursor-pointer">
                                <input type="checkbox" wire:model="run_in_background" class="rounded border-input text-primary focus:ring-primary h-4 w-4"/>
                                <span>{{ __('Run in Background') }}</span>
                            </label>

                            <label class="flex items-center gap-2 text-xs font-medium cursor-pointer">
                                <input type="checkbox" wire:model="without_overlapping" class="rounded border-input text-primary focus:ring-primary h-4 w-4"/>
                                <span>{{ __('Without Overlapping') }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="p-6 border-t border-border bg-secondary/15 flex items-center justify-end gap-2.5">
                        <x-ui.button type="button" variant="outline" wire:click="$set('modalOpen', false)">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button type="submit">
                            <x-icon name="save" class="h-4 w-4 mr-1.5"/>
                            {{ __('Save Cron Job') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Execution Output Inspector Modal --}}
    @if ($outputModalOpen && $inspectingJob)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-2xl bg-card border border-border rounded-2xl shadow-xl overflow-hidden animate-in fade-in zoom-in-95">
                <div class="p-6 border-b border-border flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg {{ $inspectingJob->last_run_status === 'success' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-rose-500/10 text-rose-600' }}">
                            <x-icon name="{{ $inspectingJob->last_run_status === 'success' ? 'check-circle' : 'alert-triangle' }}" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ $inspectingJob->name }}</h3>
                            <div class="text-xs text-muted-foreground font-mono mt-0.5">
                                <span>php artisan {{ $inspectingJob->command }}</span> &bull;
                                <span>{{ $inspectingJob->last_run_duration ?? '0' }}s</span> &bull;
                                <span>{{ $inspectingJob->last_run_at?->format('d M Y, h:i:s A') }}</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" wire:click="$set('outputModalOpen', false)" class="text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-5 w-5"/>
                    </button>
                </div>

                <div class="p-6 space-y-3">
                    <div class="flex items-center justify-between text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        <span>{{ __('Console Output Stream') }}</span>
                        <span class="font-mono text-[10px] text-foreground bg-secondary px-2 py-0.5 rounded">
                            Status: {{ strtoupper($inspectingJob->last_run_status ?? 'UNKNOWN') }}
                        </span>
                    </div>

                    <div class="rounded-xl bg-slate-950 text-emerald-400 p-4 font-mono text-xs overflow-x-auto max-h-80 shadow-inner leading-relaxed whitespace-pre-wrap selection:bg-emerald-900">
                        {{ $inspectingJob->last_run_output ?: __('No output recorded for this task execution.') }}
                    </div>
                </div>

                <div class="p-4 border-t border-border bg-secondary/15 flex items-center justify-end">
                    <x-ui.button type="button" variant="outline" wire:click="$set('outputModalOpen', false)">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
