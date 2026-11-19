<?php

use App\Helpers\CourseData;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Services\Payment\FeeReminderService;
use App\Services\Payment\FeeScheduleService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Fee Schedules & Billing Timelines')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $batchFilter = 'all';
    public string $courseFilter = 'all';

    // Initiate Modal
    public bool $showCreateModal = false;
    public int|string|null $selectedBatchId = null;
    public int|string|null $selectedCourseId = null;
    public int|string|null $selectedFeeTypeId = null;
    public string $scheduleTitle = '';
    public ?int $periodMonth = null;
    public int $periodYear = 2026;
    public string $dueDate = '';
    public int $gracePeriodDays = 7;
    public float $lateFeeAmount = 0.00;
    public string $description = '';

    // Bulk Remind Modal
    public bool $showRemindModal = false;
    public ?int $remindScheduleId = null;
    public array $reminderChannels = ['database', 'email', 'sms'];

    // Revert Modal
    public bool $showRevertModal = false;
    public ?int $revertScheduleId = null;
    public string $revertReason = '';

    // Delete Modal
    public bool $showDeleteModal = false;
    public ?int $deleteScheduleId = null;

    public function mount(): void
    {
        $this->periodMonth = (int) date('n');
        $this->periodYear = (int) date('Y');
        $this->dueDate = now()->addDays(7)->toDateString();
    }

    public function updatedSelectedCourseId(mixed $val = null): void
    {
        if ($this->selectedCourseId === '') {
            $this->selectedCourseId = null;
        }
        $this->updateDefaultTitle();
    }

    public function updatedSelectedBatchId(mixed $val = null): void
    {
        if ($this->selectedBatchId === '') {
            $this->selectedBatchId = null;
        }
    }

    public function updatedSelectedFeeTypeId(mixed $val = null): void
    {
        if ($this->selectedFeeTypeId === '') {
            $this->selectedFeeTypeId = null;
        }
    }

    public function updatedPeriodMonth(): void
    {
        $this->updateDefaultTitle();
    }

    public function updatedPeriodYear(): void
    {
        $this->updateDefaultTitle();
    }

    protected function updateDefaultTitle(): void
    {
        $course = $this->selectedCourseId ? Course::find((int) $this->selectedCourseId) : null;
        $monthName = $this->periodMonth ? \Carbon\Carbon::create(null, (int) $this->periodMonth, 1)->format('F') : '';
        $courseName = $course ? $course->name : '';

        if ($monthName) {
            $this->scheduleTitle = "Tuition Fees for {$monthName} {$this->periodYear}".($courseName ? " - {$courseName}" : '');
        } else {
            $this->scheduleTitle = "Course Fees {$this->periodYear}".($courseName ? " - {$courseName}" : '');
        }
    }

    public function openCreateModal(): void
    {
        $firstBatch = Batch::first();
        $firstCourse = Course::first();
        $tuitionFeeType = FeeType::where('code', 'tuition')->first() ?? FeeType::first();

        $this->selectedBatchId = $firstBatch?->id;
        $this->selectedCourseId = $firstCourse?->id;
        $this->selectedFeeTypeId = $tuitionFeeType?->id;
        $this->periodMonth = (int) date('n');
        $this->periodYear = (int) date('Y');
        $this->dueDate = now()->addDays(7)->toDateString();
        $this->gracePeriodDays = 7;
        $this->lateFeeAmount = 0.00;
        $this->updateDefaultTitle();

        $this->showCreateModal = true;
    }

    public function initiateSchedule(): void
    {
        $this->validate([
            'selectedBatchId' => ['required', 'exists:batches,id'],
            'selectedCourseId' => ['required', 'exists:courses,id'],
            'selectedFeeTypeId' => ['required', 'exists:fee_types,id'],
            'scheduleTitle' => ['required', 'string', 'max:200'],
            'dueDate' => ['required', 'date'],
            'gracePeriodDays' => ['required', 'integer', 'min:1', 'max:31'],
            'lateFeeAmount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $schedule = FeeScheduleService::initiateBatchCourseFeeSchedule([
                'batch_id' => (int) $this->selectedBatchId,
                'course_id' => (int) $this->selectedCourseId,
                'fee_type_id' => (int) $this->selectedFeeTypeId,
                'title' => $this->scheduleTitle,
                'period_month' => $this->periodMonth,
                'period_year' => $this->periodYear,
                'due_date' => $this->dueDate,
                'grace_period_days' => $this->gracePeriodDays,
                'late_fee_amount' => $this->lateFeeAmount,
                'description' => $this->description,
            ], auth()->id());

            Toast::dispatch($this, 'success', __("Fee schedule initiated for :count students (Total Demand: ₹:amt).", [
                'count' => $schedule->total_targeted_students,
                'amt' => number_format($schedule->total_invoiced_amount, 2),
            ]));

            $this->showCreateModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to initiate schedule: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openRemindModal(int $scheduleId): void
    {
        $this->remindScheduleId = $scheduleId;
        $this->reminderChannels = ['database', 'email', 'sms'];
        $this->showRemindModal = true;
    }

    public function sendBulkReminders(): void
    {
        try {
            $result = FeeReminderService::sendScheduleBulkReminders(
                schedule: $this->remindScheduleId,
                channels: $this->reminderChannels,
                triggeredBy: auth()->id()
            );

            Toast::dispatch($this, 'success', __("Dispatched reminders to :count unpaid students under this schedule.", ['count' => $result['total_sent']]));
            $this->showRemindModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to send reminders: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openRevertModal(int $scheduleId): void
    {
        $this->revertScheduleId = $scheduleId;
        $this->revertReason = 'Administrative schedule reversal / billing timeline correction.';
        $this->showRevertModal = true;
    }

    public function revertSchedule(): void
    {
        $this->validate([
            'revertReason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $result = FeeScheduleService::revertSchedule(
                schedule: $this->revertScheduleId,
                reason: $this->revertReason,
                revertedBy: auth()->id()
            );

            Toast::dispatch($this, 'success', __("Fee schedule reverted. Cancelled :cancelled pending invoices and adjusted :adjusted partial invoices.", [
                'cancelled' => $result['cancelled_count'],
                'adjusted' => $result['adjusted_count'],
            ]));
            $this->showRevertModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to revert fee schedule: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openDeleteModal(int $scheduleId): void
    {
        $this->deleteScheduleId = $scheduleId;
        $this->showDeleteModal = true;
    }

    public function deleteSchedule(): void
    {
        try {
            $result = FeeScheduleService::deleteSchedule(
                schedule: $this->deleteScheduleId,
                deletedBy: auth()->id()
            );

            Toast::dispatch($this, 'success', __("Fee schedule and its :count uncollected invoices have been deleted.", [
                'count' => $result['deleted_invoices_count'],
            ]));
            $this->showDeleteModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Cannot delete schedule: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'xlsx')
    {
        $query = FeeSchedule::with(['batch', 'course', 'feeType'])->orderByDesc('created_at');
        $headings = ['Schedule #', 'Title', 'Course', 'Batch', 'Fee Type', 'Period', 'Due Date', 'Students', 'Invoiced (₹)', 'Collected (₹)', 'Status'];

        return TableExporter::download(
            query: $query,
            format: $format,
            filename: 'fee-schedules-register',
            headings: $headings,
            mapper: fn (FeeSchedule $s) => [
                $s->schedule_no,
                $s->title,
                $s->course?->name,
                $s->batch?->name,
                $s->feeType?->name,
                $s->period_month ? \Carbon\Carbon::create(null, $s->period_month, 1)->format('M').' '.$s->period_year : 'One-Time',
                $s->due_date->format('d/m/Y'),
                $s->total_targeted_students,
                number_format($s->total_invoiced_amount, 2),
                number_format($s->total_collected_amount, 2),
                ucfirst($s->status),
            ],
            title: 'SNTCSSC Fee Billing Schedules'
        );
    }

    public function with(): array
    {
        $schedules = FeeSchedule::query()
            ->with(['batch', 'course', 'feeType', 'invoices'])
            ->when($this->search !== '', fn ($q) => $q->where('title', 'like', "%{$this->search}%")->orWhere('schedule_no', 'like', "%{$this->search}%"))
            ->when($this->batchFilter !== 'all', fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter !== 'all', fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->orderByDesc('created_at')
            ->paginate(10);

        return [
            'schedules' => $schedules,
            'batches' => Batch::orderBy('name')->get(),
            'courses' => Course::orderBy('name')->get(),
            'feeTypes' => FeeType::active()->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Fee Schedules & Billing Timelines') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Initiate course and batch-wise periodic fee collection timelines (e.g. 1st to 7th of each month) and monitor target progress.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button wire:click="openCreateModal">
                <x-icon name="plus" class="h-4 w-4 mr-1.5"/>
                {{ __('Initiate Fee Schedule') }}
            </x-ui.button>

            <button wire:click="export('xlsx')" class="p-2 rounded-lg border border-border bg-card hover:bg-muted text-foreground" title="Export Excel">
                <x-icon name="file-spreadsheet" class="h-4 w-4 text-emerald-600"/>
            </button>
        </div>
    </div>

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-ui.input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search schedules by title or reference #...') }}"
            />

            <select wire:model.live="batchFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                <option value="all">{{ __('All Batches') }}</option>
                @foreach($batches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="courseFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                <option value="all">{{ __('All Courses') }}</option>
                @foreach($courses as $c)
                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Schedules Listing --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($schedules as $s)
            @php
                $collectedPct = $s->total_invoiced_amount > 0 ? (int) round(($s->total_collected_amount / $s->total_invoiced_amount) * 100) : 0;
                $isDuePassed = $s->due_date->isPast();
            @endphp
            <div class="rounded-xl border border-border bg-card p-5 shadow-xs space-y-4 flex flex-col justify-between hover:border-primary/40 transition-colors">
                <div class="space-y-2.5">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs font-semibold text-primary">{{ $s->schedule_no }}</span>
                        <x-ui.badge variant="{{ $s->status === 'active' ? 'success' : ($s->isReverted() ? 'destructive' : 'secondary') }}" class="text-[10px]">
                            {{ ucfirst($s->status) }}
                        </x-ui.badge>
                    </div>

                    <div>
                        <h3 class="text-sm font-bold text-foreground leading-snug">{{ $s->title }}</h3>
                        <p class="text-[11px] text-muted-foreground mt-0.5">{{ $s->course?->name }} • {{ $s->batch?->name }}</p>
                    </div>

                    {{-- Timeline Card --}}
                    <div class="p-3 rounded-lg bg-muted/40 border border-border/60 text-xs space-y-1.5">
                        <div class="flex justify-between items-center text-muted-foreground text-[11px]">
                            <span>{{ __('Due Date Window') }}</span>
                            <span class="font-medium {{ $isDuePassed ? 'text-rose-600' : 'text-foreground' }}">
                                {{ $s->due_date->format('d M Y') }} ({{ $s->grace_period_days }} {{ __('Days') }})
                            </span>
                        </div>
                        @if($s->late_fee_amount > 0)
                            <div class="flex justify-between items-center text-[11px] text-rose-600">
                                <span>{{ __('Late Fee Charge') }}</span>
                                <span class="font-semibold">₹{{ number_format($s->late_fee_amount, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Financial Stats --}}
                    <div class="grid grid-cols-2 gap-2 text-xs pt-1">
                        <div>
                            <span class="text-muted-foreground text-[11px] block">{{ __('Target Demand') }}</span>
                            <span class="font-bold text-foreground">₹{{ number_format($s->total_invoiced_amount, 2) }}</span>
                            <span class="text-[10px] text-muted-foreground block">({{ $s->total_targeted_students }} {{ __('Students') }})</span>
                        </div>
                        <div class="text-right">
                            <span class="text-muted-foreground text-[11px] block">{{ __('Collected') }}</span>
                            <span class="font-bold text-emerald-600">₹{{ number_format($s->total_collected_amount, 2) }}</span>
                            <span class="text-[10px] font-semibold text-emerald-600 block">{{ $collectedPct }}% {{ __('Cleared') }}</span>
                        </div>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="w-full bg-muted rounded-full h-1.5 overflow-hidden">
                        <div class="bg-emerald-500 h-1.5 rounded-full" style="width: {{ min(100, $collectedPct) }}%"></div>
                    </div>
                </div>

                {{-- Card Actions --}}
                <div class="pt-3 border-t border-border/80 flex flex-wrap items-center justify-between gap-2">
                    <a href="{{ route('admin.payments.fees', ['batchFilter' => $s->batch_id, 'courseFilter' => $s->course_id]) }}" class="text-xs text-primary font-medium hover:underline inline-flex items-center">
                        <x-icon name="receipt" class="h-3.5 w-3.5 mr-1"/>
                        {{ __('View Invoices') }}
                    </a>

                    <div class="flex items-center gap-1.5">
                        <x-ui.button wire:click="openRemindModal({{ $s->id }})" variant="outline" size="sm" class="text-xs">
                            <x-icon name="bell" class="h-3.5 w-3.5 mr-1 text-amber-500"/>
                            {{ __('Bulk Remind') }}
                        </x-ui.button>

                        @if($s->canBeReverted())
                            <x-ui.button wire:click="openRevertModal({{ $s->id }})" variant="outline" size="sm" class="text-xs text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:hover:bg-amber-950/30 border-amber-200 dark:border-amber-800" title="{{ __('Revert / Cancel Schedule') }}">
                                <x-icon name="undo-2" class="h-3.5 w-3.5 mr-1"/>
                                {{ __('Revert') }}
                            </x-ui.button>
                        @endif

                        @if($s->canBeDeleted())
                            <x-ui.button wire:click="openDeleteModal({{ $s->id }})" variant="outline" size="sm" class="text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/30 border-rose-200 dark:border-rose-800" title="{{ __('Delete Uncollected Schedule') }}">
                                <x-icon name="trash" class="h-3.5 w-3.5 mr-1"/>
                                {{ __('Delete') }}
                            </x-ui.button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full p-12 text-center text-muted-foreground rounded-xl border border-dashed border-border">
                <x-icon name="calendar-days" class="h-10 w-10 mx-auto mb-2 text-muted-foreground/30"/>
                <p class="font-medium text-foreground">{{ __('No fee collection schedules initiated yet.') }}</p>
                <p class="text-xs mt-1">{{ __('Click "Initiate Fee Schedule" to generate monthly or periodic fee demands for any batch.') }}</p>
            </div>
        @endforelse
    </div>

    <div>
        {{ $schedules->links() }}
    </div>

    {{-- Initiate Fee Schedule Modal --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-xl border border-border bg-card p-6 shadow-xl space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div>
                        <h3 class="text-base font-semibold text-foreground">{{ __('Initiate Course Fee Payment Schedule') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Generates fee demands for all active enrolled students in the selected course and batch.') }}</p>
                    </div>
                    <button wire:click="$set('showCreateModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-medium text-foreground mb-1">{{ __('Academic Batch') }} *</label>
                            <select wire:model.live="selectedBatchId" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                @foreach($batches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block font-medium text-foreground mb-1">{{ __('Target Course') }} *</label>
                            <select wire:model.live="selectedCourseId" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                @foreach($courses as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block font-medium text-foreground mb-1">{{ __('Fee Type') }} *</label>
                            <select wire:model.live="selectedFeeTypeId" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                @foreach($feeTypes as $ft)
                                    <option value="{{ $ft->id }}">{{ $ft->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block font-medium text-foreground mb-1">{{ __('Billing Month') }}</label>
                            <select wire:model.live="periodMonth" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                <option value="">{{ __('None / One-Time') }}</option>
                                @for($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}">{{ \Carbon\Carbon::create(null, $m, 1)->format('F') }}</option>
                                @endfor
                            </select>
                        </div>

                        <div>
                            <x-ui.input
                                wire:model.live="periodYear"
                                :label="__('Billing Year') . ' *'"
                                type="number"
                                required
                            />
                        </div>
                    </div>

                    <x-ui.input
                        wire:model="scheduleTitle"
                        :label="__('Schedule Title / Demand Header') . ' *'"
                        placeholder="Tuition Fees for January 2026..."
                        required
                    />

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <x-ui.input
                                wire:model="dueDate"
                                :label="__('Payment Due Date') . ' *'"
                                type="date"
                                required
                            />
                        </div>

                        <div>
                            <x-ui.input
                                wire:model="gracePeriodDays"
                                :label="__('Timeline Window (Days)') . ' *'"
                                type="number"
                                min="1"
                                max="31"
                                hint="e.g. 7 days: 1st to 7th"
                                required
                            />
                        </div>

                        <div>
                            <x-ui.input
                                wire:model="lateFeeAmount"
                                :label="__('Late Charge (INR)')"
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                            />
                        </div>
                    </div>

                    <x-ui.textarea
                        wire:model="description"
                        :label="__('Notice / Instructions for Students')"
                        rows="2"
                        placeholder="Dear students, please pay your tuition fees within the due date window..."
                    />

                    @php
                        $targetEnrolledCount = 0;
                        if ($selectedBatchId && $selectedCourseId) {
                            $targetEnrolledCount = Enrollment::where('batch_id', (int) $selectedBatchId)
                                ->where('course_id', (int) $selectedCourseId)
                                ->where('status', 'active')
                                ->count();
                        }
                    @endphp
                    <div class="p-3 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-700 dark:text-blue-300">
                        <div class="font-semibold">{{ __('Target Population:') }} {{ $targetEnrolledCount }} {{ __('Enrolled Students') }}</div>
                        <p class="text-[11px] mt-0.5">
                            {{ __('Category fees (General vs SC/ST/OBC/EWS) will be calculated dynamically for each candidate based on official records in CourseData.') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showCreateModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="initiateSchedule" wire:loading.attr="disabled">
                        <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                        {{ __('Confirm & Initiate Invoices') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Bulk Remind Modal --}}
    @if($showRemindModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">{{ __('Dispatch Bulk Fee Reminders') }}</h3>
                    <button wire:click="$set('showRemindModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="space-y-3 text-xs">
                    <p class="text-muted-foreground">{{ __('Send automated reminders to all unpaid students under this schedule across selected channels:') }}</p>

                    <label class="flex items-center gap-2 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="database" class="rounded border-border text-primary focus:ring-primary"/>
                        <div>
                            <div class="font-medium text-foreground">{{ __('In-App Notification') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Displays banner and notification bell alert on student portal') }}</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-2 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="email" class="rounded border-border text-primary focus:ring-primary"/>
                        <div>
                            <div class="font-medium text-foreground">{{ __('Institutional Email Alert') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Dispatches personalized email with invoice balance') }}</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-2 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="sms" class="rounded border-border text-primary focus:ring-primary"/>
                        <div>
                            <div class="font-medium text-foreground">{{ __('Mobile SMS Alert') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Sends concise SMS notice to registered phone numbers') }}</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-2 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="whatsapp" class="rounded border-border text-primary focus:ring-primary"/>
                        <div>
                            <div class="font-medium text-foreground">{{ __('WhatsApp Alert') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Routes fee alert via WhatsApp template') }}</div>
                        </div>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showRemindModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="sendBulkReminders" wire:loading.attr="disabled">
                        <x-icon name="send" class="h-4 w-4 mr-1.5"/>
                        {{ __('Dispatch Reminders') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Revert Fee Schedule Modal --}}
    @if($showRevertModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600">
                            <x-icon name="undo-2" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-foreground">{{ __('Revert Fee Schedule') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Cancels uncollected fee invoices and adjusts student ledgers.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showRevertModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-800 dark:text-amber-200 text-xs space-y-1">
                    <div class="font-bold">{{ __('Impact of Reversion:') }}</div>
                    <ul class="list-disc list-inside space-y-0.5 text-[11px] leading-relaxed">
                        <li>{{ __('All unpaid invoices under this schedule will be set to Cancelled.') }}</li>
                        <li>{{ __('Offsetting credit adjustments will be posted to student ledgers.') }}</li>
                        <li>{{ __('Partially paid invoices will have their unpaid balance waived.') }}</li>
                        <li>{{ __('The schedule will be archived and marked as Cancelled/Reverted.') }}</li>
                    </ul>
                </div>

                <div class="space-y-3 text-xs">
                    <x-ui.textarea
                        wire:model="revertReason"
                        :label="__('Reason for Schedule Reversal') . ' *'"
                        rows="3"
                        placeholder="State reason (e.g. Generated with wrong fee rate or date window)..."
                        required
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showRevertModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="revertSchedule" class="bg-amber-600 hover:bg-amber-700 text-white" wire:loading.attr="disabled">
                        <x-icon name="undo-2" class="h-4 w-4 mr-1.5"/>
                        {{ __('Confirm Schedule Revert') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete Fee Schedule Modal --}}
    @if($showDeleteModal)
        @php
            $targetSchedule = \App\Models\FeeSchedule::find($deleteScheduleId);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-rose-500/10 text-rose-600">
                            <x-icon name="trash" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-foreground">{{ __('Delete Fee Schedule') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Permanently remove schedule and uncollected demands.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showDeleteModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                @if($targetSchedule && ! $targetSchedule->canBeDeleted())
                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-800 dark:text-rose-200 text-xs space-y-2">
                        <div class="font-bold flex items-center gap-1.5">
                            <x-icon name="alert-triangle" class="h-4 w-4 text-rose-600"/>
                            {{ __('Schedule Cannot Be Hard-Deleted') }}
                        </div>
                        <p class="text-[11px] leading-relaxed">
                            {{ __('Payments totalling ₹:amt have already been collected for this schedule. Deleting it would compromise accounting and audit trail integrity. Please use the "Revert" option instead.', [
                                'amt' => number_format($targetSchedule->total_collected_amount, 2),
                            ]) }}
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button wire:click="$set('showDeleteModal', false)" variant="outline">
                            {{ __('Close') }}
                        </x-ui.button>
                        <x-ui.button wire:click="openRevertModal({{ $targetSchedule->id }})" class="bg-amber-600 hover:bg-amber-700 text-white">
                            <x-icon name="undo-2" class="h-4 w-4 mr-1.5"/>
                            {{ __('Use Revert Option Instead') }}
                        </x-ui.button>
                    </div>
                @else
                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-800 dark:text-rose-200 text-xs space-y-1">
                        <div class="font-bold">{{ __('Are you sure you want to permanently delete this schedule?') }}</div>
                        <p class="text-[11px] leading-relaxed">
                            {{ __('This will permanently delete schedule :sched along with all of its generated uncollected invoices and clean up student ledger debits.', [
                                'sched' => $targetSchedule?->schedule_no,
                            ]) }}
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button wire:click="$set('showDeleteModal', false)" variant="outline">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button wire:click="deleteSchedule" class="bg-rose-600 hover:bg-rose-700 text-white" wire:loading.attr="disabled">
                            <x-icon name="trash" class="h-4 w-4 mr-1.5"/>
                            {{ __('Confirm Permanent Deletion') }}
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
