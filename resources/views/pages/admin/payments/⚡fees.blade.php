<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\FeeType;
use App\Models\Section;
use App\Models\StudentFeeInvoice;
use App\Models\User;
use App\Services\Payment\FeePaymentService;
use App\Services\Payment\FeeReminderService;
use App\Services\Payment\PaymentImportService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Fee Management & Invoices')] class extends Component {
    use WithFileUploads, WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';
    public string $feeTypeFilter = 'all';
    public string $batchFilter = 'all';
    public string $courseFilter = 'all';
    public string $sectionFilter = 'all';
    public string $sortBy = 'created_at';
    public string $sortDir = 'desc';

    // Manual Fee Collection Modal
    public bool $showCollectModal = false;
    public string $studentSearch = '';
    public ?int $selectedUserId = null;
    public ?int $selectedInvoiceId = null;
    public ?int $selectedFeeTypeId = null;
    public string $customFeeTitle = '';
    public ?int $periodMonth = null;
    public int $periodYear = 2026;
    public float $collectAmount = 0.00;
    public string $collectMethod = 'cash';
    public string $collectReference = '';
    public string $collectDate = '';
    public string $collectRemarks = '';
    public bool $isAdHoc = false;

    // Cancel / Revert Invoice Modal
    public bool $showCancelModal = false;
    public ?int $cancelInvoiceId = null;
    public string $cancelReason = '';

    // Delete Invoice Modal
    public bool $showDeleteModal = false;
    public ?int $deleteInvoiceId = null;

    // Reminder Modal
    public bool $showReminderModal = false;
    public ?int $reminderInvoiceId = null;
    public array $reminderChannels = ['database', 'email', 'sms'];

    // Bulk Import Modal
    public bool $showImportModal = false;
    public $importFile = null;
    public bool $importAutoApprove = true;
    public array $importResult = [];

    public function mount(): void
    {
        $this->collectDate = now()->toDateString();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingBatchFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCourseFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    protected function getInvoicesQuery(): Builder
    {
        return StudentFeeInvoice::query()
            ->with(['user.studentProfile', 'enrollment.course', 'enrollment.batch', 'enrollment.section', 'feeType', 'feeSchedule'])
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('invoice_no', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhereHas('user', function (Builder $uq) use ($term) {
                            $uq->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('phone', 'like', $term)
                                ->orWhere('student_id', 'like', $term);
                        });
                });
            })
            ->when($this->statusFilter !== 'all', function (Builder $query) {
                if ($this->statusFilter === 'overdue') {
                    $query->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL])
                        ->where('due_date', '<', now()->toDateString());
                } else {
                    $query->where('status', $this->statusFilter);
                }
            })
            ->when($this->feeTypeFilter !== 'all', fn (Builder $q) => $q->where('fee_type_id', (int) $this->feeTypeFilter))
            ->when($this->batchFilter !== 'all', fn (Builder $q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('batch_id', (int) $this->batchFilter)))
            ->when($this->courseFilter !== 'all', fn (Builder $q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('course_id', (int) $this->courseFilter)))
            ->when($this->sectionFilter !== 'all', fn (Builder $q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('section_id', (int) $this->sectionFilter)))
            ->orderBy($this->sortBy, $this->sortDir);
    }

    public function openDirectCollectionModal(): void
    {
        $this->studentSearch = '';
        $this->selectedUserId = null;
        $this->selectedInvoiceId = null;
        $this->selectedFeeTypeId = FeeType::where('code', 'tuition')->value('id') ?? FeeType::first()?->id;
        $this->periodMonth = (int) date('n');
        $this->periodYear = (int) date('Y');
        $this->collectAmount = 0.00;
        $this->collectMethod = 'cash';
        $this->collectReference = 'COUNTER-'.now()->format('YmdHis');
        $this->collectDate = now()->toDateString();
        $this->collectRemarks = 'Direct manual counter fee collection by admin.';
        $this->isAdHoc = true;
        $this->syncFeeTitle();
        $this->showCollectModal = true;
    }

    public function selectStudent(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->studentSearch = '';

        $pendingInvoice = StudentFeeInvoice::where('user_id', $userId)
            ->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL, StudentFeeInvoice::STATUS_OVERDUE])
            ->orderBy('due_date')
            ->first();

        if ($pendingInvoice) {
            $this->selectedInvoiceId = $pendingInvoice->id;
            $this->selectedFeeTypeId = $pendingInvoice->fee_type_id;
            $this->periodMonth = $pendingInvoice->period_month;
            $this->periodYear = $pendingInvoice->period_year ?: (int) date('Y');
            $this->collectAmount = (float) $pendingInvoice->balance_amount;
            $this->customFeeTitle = $pendingInvoice->title;
            $this->isAdHoc = false;
        } else {
            $this->selectedInvoiceId = null;
            $this->isAdHoc = true;
            $this->selectedFeeTypeId = FeeType::where('code', 'tuition')->value('id') ?? FeeType::first()?->id;
            $this->periodMonth = (int) date('n');
            $this->periodYear = (int) date('Y');
            $this->collectAmount = 1000.00;
            $this->syncFeeTitle();
        }
    }

    public function updatedSelectedInvoiceId(): void
    {
        if ($this->selectedInvoiceId) {
            $invoice = StudentFeeInvoice::find($this->selectedInvoiceId);
            if ($invoice) {
                $this->collectAmount = (float) $invoice->balance_amount;
                $this->selectedFeeTypeId = $invoice->fee_type_id;
                $this->periodMonth = $invoice->period_month;
                $this->periodYear = $invoice->period_year ?: (int) date('Y');
                $this->customFeeTitle = $invoice->title;
                $this->isAdHoc = false;
            }
        } else {
            $this->isAdHoc = true;
            $this->syncFeeTitle();
        }
    }

    public function updatedPeriodMonth(): void
    {
        $this->syncFeeTitle();
    }

    public function updatedPeriodYear(): void
    {
        $this->syncFeeTitle();
    }

    public function updatedSelectedFeeTypeId(): void
    {
        $this->syncFeeTitle();
    }

    protected function syncFeeTitle(): void
    {
        $feeType = $this->selectedFeeTypeId ? FeeType::find($this->selectedFeeTypeId) : null;
        if (! $feeType) {
            return;
        }

        if ($feeType->code === 'library_security') {
            $this->collectAmount = $this->collectAmount > 0 ? $this->collectAmount : 1000.00;
            $this->customFeeTitle = __('Refundable Library Security Deposit');
        } elseif ($feeType->code === 'admission') {
            $this->customFeeTitle = __('Admission Fee');
        } else {
            $monthName = $this->periodMonth ? \Carbon\Carbon::create(null, (int) $this->periodMonth, 1)->format('F') : '';
            if ($monthName) {
                $this->customFeeTitle = "{$feeType->name} for {$monthName} {$this->periodYear}";
            } else {
                $this->customFeeTitle = "{$feeType->name} {$this->periodYear}";
            }
        }
    }

    public function openCollectModal(int $invoiceId): void
    {
        $invoice = StudentFeeInvoice::with('user')->findOrFail($invoiceId);
        $this->selectedInvoiceId = $invoice->id;
        $this->selectedUserId = $invoice->user_id;
        $this->selectedFeeTypeId = $invoice->fee_type_id;
        $this->customFeeTitle = $invoice->title;
        $this->collectAmount = (float) $invoice->balance_amount;
        $this->collectMethod = 'cash';
        $this->collectReference = 'COUNTER-'.now()->format('YmdHis');
        $this->collectDate = now()->toDateString();
        $this->collectRemarks = 'Direct fee collection at institute counter.';
        $this->isAdHoc = false;
        $this->showCollectModal = true;
    }

    public function getIsTuitionAlreadyPaidProperty(): bool
    {
        if (! $this->selectedUserId) {
            return false;
        }

        $feeType = $this->selectedFeeTypeId ? FeeType::find($this->selectedFeeTypeId) : null;
        if (! $feeType || $feeType->code !== 'tuition') {
            return false;
        }

        $month = (int) $this->periodMonth;
        $year = (int) $this->periodYear;

        if ($month <= 0 || $year <= 0) {
            return false;
        }

        return StudentFeeInvoice::where('user_id', $this->selectedUserId)
            ->where('fee_type_id', $feeType->id)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->where('status', StudentFeeInvoice::STATUS_PAID)
            ->when($this->selectedInvoiceId, fn ($q) => $q->where('id', '!=', $this->selectedInvoiceId))
            ->exists();
    }

    public function saveCollection(): void
    {
        if ($this->isTuitionAlreadyPaid) {
            $monthName = \Carbon\Carbon::create(null, (int) $this->periodMonth, 1)->format('F');
            $this->addError('periodMonth', __("Tuition fee for :month :year has already been paid for this student. Only one tuition payment per month is permitted.", [
                'month' => $monthName,
                'year' => $this->periodYear,
            ]));
            return;
        }

        $this->validate([
            'selectedUserId' => ['required', 'exists:users,id'],
            'collectAmount' => ['required', 'numeric', 'min:1'],
            'collectMethod' => ['required', 'string', 'in:cash,upi,static_qr,neft,imps,bank_transfer,cheque,dd,card'],
            'collectReference' => ['required', 'string', 'max:100'],
            'collectDate' => ['required', 'date'],
            'collectRemarks' => ['nullable', 'string', 'max:500'],
            'selectedFeeTypeId' => ['nullable', 'exists:fee_types,id'],
        ]);

        try {
            $txn = FeePaymentService::recordManualCollection([
                'user_id' => $this->selectedUserId,
                'invoice_id' => $this->selectedInvoiceId,
                'fee_type_id' => $this->selectedFeeTypeId,
                'title' => $this->customFeeTitle,
                'amount' => $this->collectAmount,
                'payment_method' => $this->collectMethod,
                'reference_no' => $this->collectReference,
                'payment_date' => $this->collectDate,
                'remarks' => $this->collectRemarks,
                'period_month' => $this->periodMonth,
                'period_year' => $this->periodYear,
            ], auth()->id());

            Toast::dispatch($this, 'success', __('Manual payment of ₹:amt recorded successfully. Receipt No: :rec', [
                'amt' => number_format($txn->amount, 2),
                'rec' => $txn->receipt_no,
            ]));

            $this->showCollectModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to record payment: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openCancelModal(int $invoiceId): void
    {
        $this->cancelInvoiceId = $invoiceId;
        $this->cancelReason = 'Administrative fee cancellation / ledger adjustment.';
        $this->showCancelModal = true;
    }

    public function cancelInvoice(): void
    {
        $this->validate([
            'cancelReason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $invoice = FeePaymentService::cancelInvoice(
                invoice: $this->cancelInvoiceId,
                reason: $this->cancelReason,
                cancelledBy: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Invoice :inv cancelled and student ledger adjusted.', ['inv' => $invoice->invoice_no]));
            $this->showCancelModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to cancel invoice: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openDeleteModal(int $invoiceId): void
    {
        $this->deleteInvoiceId = $invoiceId;
        $this->showDeleteModal = true;
    }

    public function deleteInvoice(): void
    {
        try {
            FeePaymentService::deleteInvoice(
                invoice: $this->deleteInvoiceId,
                deletedBy: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Fee invoice and associated debit record deleted.'));
            $this->showDeleteModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Cannot delete invoice: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openReminderModal(int $invoiceId): void
    {
        $this->reminderInvoiceId = $invoiceId;
        $this->reminderChannels = ['database', 'email', 'sms'];
        $this->showReminderModal = true;
    }

    public function sendReminder(): void
    {
        if (empty($this->reminderChannels)) {
            Toast::dispatch($this, 'warning', __('Please select at least one delivery channel.'));
            return;
        }

        try {
            $result = FeeReminderService::sendInvoiceReminder(
                invoice: $this->reminderInvoiceId,
                channels: $this->reminderChannels,
                triggeredBy: auth()->id()
            );

            if ($result['success']) {
                Toast::dispatch($this, 'success', __('Payment reminder dispatched successfully.'));
            } else {
                Toast::dispatch($this, 'warning', __('Reminder was not dispatched (fee might already be settled).'));
            }
            $this->showReminderModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to send reminder: :err', ['err' => $e->getMessage()]));
        }
    }

    public function processImport(): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,csv,xls', 'max:10240'],
        ]);

        try {
            $this->importResult = PaymentImportService::import(
                file: $this->importFile,
                importedBy: auth()->id(),
                autoApprove: $this->importAutoApprove
            );

            Toast::dispatch($this, 'success', __("Imported :count payment records successfully.", ['count' => $this->importResult['imported']]));
            $this->importFile = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'xlsx')
    {
        $query = $this->getInvoicesQuery();
        $headings = [
            'Invoice No', 'Student Name', 'Student ID', 'Course', 'Batch', 'Fee Type',
            'Total Payable (₹)', 'Paid Amount (₹)', 'Balance Due (₹)', 'Due Date', 'Status'
        ];

        return TableExporter::download(
            query: $query,
            format: $format,
            filename: 'fee-invoices-report',
            headings: $headings,
            mapper: fn (StudentFeeInvoice $inv) => [
                $inv->invoice_no,
                $inv->user?->name ?: 'N/A',
                $inv->student_id ?: 'N/A',
                $inv->enrollment?->course?->name ?: 'N/A',
                $inv->enrollment?->batch?->name ?: 'N/A',
                $inv->feeType?->name ?: 'N/A',
                number_format($inv->total_amount, 2),
                number_format($inv->paid_amount, 2),
                number_format($inv->balance_amount, 2),
                $inv->due_date->format('d/m/Y'),
                ucfirst($inv->effective_status),
            ],
            title: 'SNTCSSC Fee Invoices & Dues Register'
        );
    }

    public function with(): array
    {
        $invoices = $this->getInvoicesQuery()->paginate(15);

        $totalInvoiced = StudentFeeInvoice::sum('total_amount');
        $totalCollected = StudentFeeInvoice::sum('paid_amount');
        $totalOutstanding = StudentFeeInvoice::whereIn('status', ['pending', 'partial', 'overdue'])->sum('balance_amount');
        $defaultersCount = StudentFeeInvoice::whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('due_date', '<', now()->toDateString())
            ->distinct('user_id')
            ->count('user_id');

        $studentSearchResults = [];
        if (! empty($this->studentSearch) && strlen(trim($this->studentSearch)) >= 2) {
            $term = '%'.trim($this->studentSearch).'%';
            $studentSearchResults = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'Student'))
                ->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('urn', 'like', $term);
                })
                ->with(['activeEnrollments.course', 'activeEnrollments.batch', 'studentProfile'])
                ->take(8)
                ->get();
        }

        $selectedStudent = $this->selectedUserId
            ? User::with(['activeEnrollments.course', 'activeEnrollments.batch', 'studentProfile'])->find($this->selectedUserId)
            : null;

        $studentPendingInvoices = $this->selectedUserId
            ? StudentFeeInvoice::where('user_id', $this->selectedUserId)
                ->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL, StudentFeeInvoice::STATUS_OVERDUE])
                ->orderBy('due_date')
                ->get()
            : collect();

        return [
            'invoices' => $invoices,
            'feeTypes' => FeeType::active()->get(),
            'batches' => Batch::orderBy('name')->get(),
            'courses' => Course::orderBy('name')->get(),
            'sections' => Section::orderBy('name')->get(),
            'totalInvoiced' => $totalInvoiced,
            'totalCollected' => $totalCollected,
            'totalOutstanding' => $totalOutstanding,
            'defaultersCount' => $defaultersCount,
            'studentSearchResults' => $studentSearchResults,
            'selectedStudent' => $selectedStudent,
            'studentPendingInvoices' => $studentPendingInvoices,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Fee Management & Invoices') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Manage student billing schedules, collect dues, monitor defaulters, and generate payment receipts.') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button wire:click="openDirectCollectionModal" size="sm" class="bg-primary text-primary-foreground">
                <x-icon name="hand-coins" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Collect / Add Student Fee') }}
            </x-ui.button>

            <a href="{{ route('admin.payments.schedules') }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded-lg bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-border">
                <x-icon name="calendar-days" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Initiate Fee Schedule') }}
            </a>

            <x-ui.button wire:click="$set('showImportModal', true)" variant="outline" size="sm">
                <x-icon name="upload" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Import Excel/CSV') }}
            </x-ui.button>

            <div class="flex items-center rounded-lg border border-border bg-card p-0.5">
                <button wire:click="export('xlsx')" class="px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted rounded-md transition-colors" title="Export Excel">
                    <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 inline mr-1 text-emerald-600"/>
                    {{ __('Excel') }}
                </button>
                <button wire:click="export('csv')" class="px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted rounded-md transition-colors" title="Export CSV">
                    <x-icon name="file-text" class="h-3.5 w-3.5 inline mr-1 text-blue-600"/>
                    {{ __('CSV') }}
                </button>
                <button wire:click="export('pdf')" class="px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted rounded-md transition-colors" title="Export PDF">
                    <x-icon name="file" class="h-3.5 w-3.5 inline mr-1 text-rose-600"/>
                    {{ __('PDF') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Financial KPI Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Invoiced') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600">
                    <x-icon name="receipt" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-foreground">₹{{ number_format($totalInvoiced, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Total institutional fee demands generated') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Collected') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-icon name="badge-check" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-emerald-600">₹{{ number_format($totalCollected, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Cleared funds credited into institute accounts') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Outstanding Dues') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                    <x-icon name="clock" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-amber-600">₹{{ number_format($totalOutstanding, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Uncollected pending and overdue balance') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Defaulters (Overdue)') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/10 text-rose-600">
                    <x-icon name="alert-triangle" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-rose-600">{{ $defaultersCount }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Students with lapsed payment deadlines') }}</p>
        </div>
    </div>

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <div class="lg:col-span-2">
                <x-ui.input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search invoice #, student name, ID or phone...') }}"
                />
            </div>

            <div>
                <x-ui.select
                    wire:model.live="statusFilter"
                    :options="[
                        'all' => __('All Statuses'),
                        'pending' => __('Pending Payment'),
                        'partial' => __('Partially Paid'),
                        'paid' => __('Fully Paid'),
                        'overdue' => __('Overdue Only'),
                    ]"
                />
            </div>

            <div>
                <select wire:model.live="feeTypeFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Fee Types') }}</option>
                    @foreach($feeTypes as $ft)
                        <option value="{{ $ft->id }}">{{ $ft->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select wire:model.live="batchFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Batches') }}</option>
                    @foreach($batches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select wire:model.live="courseFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Courses') }}</option>
                    @foreach($courses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Invoices Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                    <tr>
                        <th class="px-4 py-3">{{ __('Invoice #') }}</th>
                        <th class="px-4 py-3">{{ __('Student') }}</th>
                        <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                        <th class="px-4 py-3">{{ __('Fee Head') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Payable') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Paid') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Balance') }}</th>
                        <th class="px-4 py-3">{{ __('Due Date') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($invoices as $inv)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 font-semibold text-foreground whitespace-nowrap">
                                <span class="font-mono text-primary">{{ $inv->invoice_no }}</span>
                                @if($inv->reminder_count > 0)
                                    <span class="ml-1 text-[10px] text-muted-foreground" title="{{ __('Reminders sent: :c', ['c' => $inv->reminder_count]) }}">
                                        ({{ $inv->reminder_count }} 🔔)
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-medium text-foreground">{{ $inv->user?->name ?: __('Deleted Student') }}</div>
                                <div class="text-[11px] text-muted-foreground font-mono">{{ $inv->student_id ?: $inv->user?->student_id ?: 'ID: N/A' }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-foreground">{{ $inv->enrollment?->course?->name ?: 'N/A' }}</div>
                                <div class="text-[11px] text-muted-foreground">{{ $inv->enrollment?->batch?->name ?: 'General' }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-medium text-foreground">{{ $inv->feeType?->name }}</div>
                                @if($inv->period_month)
                                    <div class="text-[11px] text-muted-foreground">
                                        {{ \Carbon\Carbon::create(null, $inv->period_month, 1)->format('M') }} {{ $inv->period_year }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-foreground whitespace-nowrap">
                                ₹{{ number_format($inv->total_amount, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-emerald-600 whitespace-nowrap">
                                ₹{{ number_format($inv->paid_amount, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-bold whitespace-nowrap {{ (float) $inv->balance_amount > 0 ? 'text-amber-600' : 'text-muted-foreground' }}">
                                ₹{{ number_format($inv->balance_amount, 2) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="{{ $inv->isOverdue() ? 'text-rose-600 font-semibold' : 'text-foreground' }}">
                                    {{ $inv->due_date->format('d M Y') }}
                                </div>
                                @if($inv->isOverdue())
                                    <span class="text-[10px] text-rose-500 block">{{ $inv->due_date->diffForHumans() }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($inv->isPaid())
                                    <x-ui.badge variant="success">{{ __('Fully Paid') }}</x-ui.badge>
                                @elseif($inv->status === 'cancelled')
                                    <x-ui.badge variant="destructive">{{ __('Cancelled') }}</x-ui.badge>
                                @elseif($inv->status === 'waived')
                                    <x-ui.badge variant="secondary">{{ __('Waived / Adjusted') }}</x-ui.badge>
                                @elseif($inv->isOverdue())
                                    <x-ui.badge variant="destructive">{{ __('Overdue') }}</x-ui.badge>
                                @elseif($inv->status === 'partial')
                                    <x-ui.badge variant="warning">{{ __('Partially Paid') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">{{ __('Pending') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if(! $inv->isPaid())
                                        <button wire:click="openCollectModal({{ $inv->id }})" class="p-1 rounded text-primary hover:bg-primary/10 transition-colors" title="{{ __('Collect Fee at Counter') }}">
                                            <x-icon name="hand-coins" class="h-4 w-4"/>
                                        </button>
                                        <button wire:click="openReminderModal({{ $inv->id }})" class="p-1 rounded text-amber-600 hover:bg-amber-500/10 transition-colors" title="{{ __('Send Payment Reminder') }}">
                                            <x-icon name="bell" class="h-4 w-4"/>
                                        </button>
                                    @else
                                        @php
                                            $latestTxn = $inv->transactions()->where('status', 'successful')->latest('id')->first();
                                        @endphp
                                        @if($latestTxn)
                                            <a href="{{ route('payments.receipt.download', $latestTxn) }}" class="p-1 rounded text-emerald-600 hover:bg-emerald-500/10 transition-colors" title="{{ __('Download E-Receipt PDF') }}">
                                                <x-icon name="receipt" class="h-4 w-4"/>
                                            </a>
                                        @endif
                                    @endif

                                    @if($inv->canBeCancelled())
                                        <button wire:click="openCancelModal({{ $inv->id }})" class="p-1 rounded text-amber-600 hover:bg-amber-500/10 transition-colors" title="{{ __('Cancel / Revert Invoice') }}">
                                            <x-icon name="undo-2" class="h-4 w-4"/>
                                        </button>
                                    @endif

                                    @if($inv->canBeDeleted())
                                        <button wire:click="openDeleteModal({{ $inv->id }})" class="p-1 rounded text-rose-600 hover:bg-rose-500/10 transition-colors" title="{{ __('Delete Uncollected Invoice') }}">
                                            <x-icon name="trash" class="h-4 w-4"/>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="inbox" class="h-8 w-8 mx-auto mb-2 text-muted-foreground/40"/>
                                <p>{{ __('No fee invoices match the current filters.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $invoices->links() }}
        </div>
    </div>

    {{-- Collect Payment Modal --}}
    @if($showCollectModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-xl border border-border bg-card p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-primary/10 text-primary">
                            <x-icon name="hand-coins" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-foreground">{{ __('Collect & Add Student Fee Details') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Record manual counter collections, settle invoices, or add direct payments.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showCollectModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                {{-- Student Selector --}}
                <div class="space-y-3">
                    @if($selectedStudent)
                        <div class="p-3 rounded-xl bg-primary/5 border border-primary/20 flex items-center justify-between">
                            <div class="space-y-0.5">
                                <div class="font-bold text-xs text-foreground flex items-center gap-2">
                                    <span>{{ $selectedStudent->name }}</span>
                                    @if($selectedStudent->student_id)
                                        <span class="font-mono text-[10px] text-primary bg-primary/10 px-1.5 py-0.5 rounded">{{ $selectedStudent->student_id }}</span>
                                    @endif
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    {{ $selectedStudent->email }} • {{ $selectedStudent->phone ?: 'No Phone' }}
                                    @if($selectedStudent->activeEnrollments->first()?->course)
                                        • <span class="font-medium text-foreground">{{ $selectedStudent->activeEnrollments->first()->course->name }}</span>
                                    @endif
                                </div>
                            </div>
                            <button wire:click="$set('selectedUserId', null)" class="text-xs text-primary hover:underline font-semibold">
                                {{ __('Change') }}
                            </button>
                        </div>
                    @else
                        <div class="space-y-1.5">
                            <label class="block font-medium text-foreground text-xs">{{ __('Search & Select Student') }} *</label>
                            <div class="relative">
                                <x-ui.input
                                    wire:model.live.debounce.300ms="studentSearch"
                                    placeholder="Type student name, student ID, URN, email or mobile..."
                                    class="text-xs"
                                />
                            </div>

                            @if(!empty($studentSearchResults) && count($studentSearchResults) > 0)
                                <div class="rounded-lg border border-border bg-popover divide-y divide-border max-h-48 overflow-y-auto shadow-md">
                                    @foreach($studentSearchResults as $stu)
                                        <div wire:click="selectStudent({{ $stu->id }})" class="p-2.5 hover:bg-muted cursor-pointer flex items-center justify-between text-xs transition-colors">
                                            <div>
                                                <div class="font-semibold text-foreground">{{ $stu->name }}</div>
                                                <div class="text-[10px] text-muted-foreground">
                                                    {{ $stu->student_id ?: 'No ID' }} • {{ $stu->email }}
                                                    @if($stu->activeEnrollments->first()?->course)
                                                        • {{ $stu->activeEnrollments->first()->course->name }}
                                                    @endif
                                                </div>
                                            </div>
                                            <span class="text-[10px] font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
                                                {{ __('Select') }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif(strlen(trim($studentSearch)) >= 2)
                                <p class="text-[11px] text-muted-foreground italic px-1">{{ __('No students found matching your search.') }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Fee Purpose & Invoice Target --}}
                @if($selectedUserId)
                    <div class="space-y-3 pt-2 border-t border-border text-xs">
                        @if($studentPendingInvoices->isNotEmpty())
                            <div>
                                <label class="block font-medium text-foreground mb-1">{{ __('Target Invoice or Ad-hoc Collection') }} *</label>
                                <select wire:model.live="selectedInvoiceId" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                    <option value="0">{{ __('+ Record Ad-hoc / New Direct Fee Payment') }}</option>
                                    @foreach($studentPendingInvoices as $pi)
                                        <option value="{{ $pi->id }}">
                                            {{ $pi->invoice_no }} — {{ $pi->title }} ({{ __('Due:') }} ₹{{ number_format($pi->balance_amount, 2) }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if($isAdHoc || empty($selectedInvoiceId))
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block font-medium text-foreground mb-1">{{ __('Fee Category / Type') }} *</label>
                                    <select wire:model.live="selectedFeeTypeId" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                        @foreach($feeTypes as $ft)
                                            <option value="{{ $ft->id }}">{{ $ft->name }} ({{ strtoupper($ft->code) }})</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-medium text-foreground mb-1">{{ __('Billing Month (for Tuition)') }}</label>
                                    <select wire:model.live="periodMonth" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                        <option value="">{{ __('None / One-Time Fee') }}</option>
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
                                        min="2020"
                                        max="2035"
                                        required
                                    />
                                </div>
                            </div>

                            @if($this->isTuitionAlreadyPaid)
                                <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-800 dark:text-amber-300 text-xs flex items-center gap-2">
                                    <x-icon name="alert-triangle" class="h-4 w-4 shrink-0 text-amber-500"/>
                                    <div>
                                        <span class="font-semibold">{{ __('Tuition Fee Already Paid:') }}</span>
                                        {{ __('Tuition fee for :month :year has already been paid for this student. Only one payment against a month is allowed for tuition fees.', [
                                            'month' => $periodMonth ? \Carbon\Carbon::create(null, (int) $periodMonth, 1)->format('F') : '',
                                            'year' => $periodYear,
                                        ]) }}
                                    </div>
                                </div>
                            @endif

                            <div>
                                <x-ui.input
                                    wire:model="customFeeTitle"
                                    :label="__('Fee Description / Demand Header') . ' *'"
                                    placeholder="e.g. Tuition Fees for October 2026"
                                    required
                                />
                            </div>
                        @else
                            @php
                                $selectedInvoiceModel = StudentFeeInvoice::find($selectedInvoiceId);
                            @endphp
                            @if($selectedInvoiceModel)
                                <div class="p-3 rounded-lg bg-muted/50 border border-border text-xs space-y-1">
                                    <div class="flex items-center justify-between font-semibold text-foreground">
                                        <span>{{ $selectedInvoiceModel->title }}</span>
                                        <span class="font-mono text-primary">{{ $selectedInvoiceModel->invoice_no }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3 text-muted-foreground text-[11px]">
                                        <span>{{ __('Due Date:') }} {{ $selectedInvoiceModel->due_date->format('d/m/Y') }}</span>
                                        @if($selectedInvoiceModel->month_name)
                                            <span class="font-medium text-foreground">{{ __('Period:') }} {{ $selectedInvoiceModel->month_name }} {{ $selectedInvoiceModel->period_year }}</span>
                                        @endif
                                        <span class="font-bold text-amber-600 dark:text-amber-400">{{ __('Balance Due:') }} ₹{{ number_format($selectedInvoiceModel->balance_amount, 2) }}</span>
                                    </div>
                                </div>
                            @endif
                        @endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <x-ui.input
                                wire:model="collectAmount"
                                :label="__('Collection Amount (INR)') . ' *'"
                                type="number"
                                step="0.01"
                                required
                            />

                            <div>
                                <label class="block font-medium text-foreground mb-1">{{ __('Payment Mode') }} *</label>
                                <select wire:model="collectMethod" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                    <option value="cash">{{ __('Cash In Hand') }}</option>
                                    <option value="static_qr">{{ __('Static UPI QR / BHIM / GPay') }}</option>
                                    <option value="upi">{{ __('Direct UPI Transfer (VPA)') }}</option>
                                    <option value="neft">{{ __('NEFT / RTGS Transfer') }}</option>
                                    <option value="imps">{{ __('IMPS Immediate Transfer') }}</option>
                                    <option value="bank_transfer">{{ __('Bank Deposit') }}</option>
                                    <option value="cheque">{{ __('Bank Cheque') }}</option>
                                    <option value="dd">{{ __('Demand Draft (DD)') }}</option>
                                    <option value="card">{{ __('POS / Debit Card / Credit Card') }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <x-ui.input
                                wire:model="collectReference"
                                :label="__('Reference / Money Receipt # / UTR') . ' *'"
                                placeholder="e.g. COUNTER-01 or Bank UTR"
                                required
                            />
                            <x-ui.input
                                wire:model="collectDate"
                                :label="__('Payment Date') . ' *'"
                                type="date"
                                required
                            />
                        </div>

                        <x-ui.textarea
                            wire:model="collectRemarks"
                            :label="__('Collection Remarks / Audit Notes')"
                            rows="2"
                            placeholder="Counter notes or receipt remarks..."
                        />
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showCollectModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    @if($selectedUserId)
                        <x-ui.button wire:click="saveCollection" wire:loading.attr="disabled" :disabled="$this->isTuitionAlreadyPaid">
                            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                            {{ __('Confirm Collection & Generate Receipt') }}
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Cancel / Revert Invoice Modal --}}
    @if($showCancelModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600">
                            <x-icon name="undo-2" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-foreground">{{ __('Cancel / Revert Fee Invoice') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Cancels pending liability and adjusts student ledger.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showCancelModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="space-y-3 text-xs">
                    <x-ui.textarea
                        wire:model="cancelReason"
                        :label="__('Cancellation / Reversal Reason') . ' *'"
                        rows="3"
                        placeholder="State reason for cancellation..."
                        required
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showCancelModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="cancelInvoice" class="bg-amber-600 hover:bg-amber-700 text-white" wire:loading.attr="disabled">
                        <x-icon name="undo-2" class="h-4 w-4 mr-1.5"/>
                        {{ __('Confirm Invoice Cancellation') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete Invoice Modal --}}
    @if($showDeleteModal)
        @php
            $targetInv = \App\Models\StudentFeeInvoice::find($deleteInvoiceId);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-rose-500/10 text-rose-600">
                            <x-icon name="trash" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-foreground">{{ __('Delete Fee Invoice') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Permanently remove invoice and debit record.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showDeleteModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                @if($targetInv && ! $targetInv->canBeDeleted())
                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-800 dark:text-rose-200 text-xs space-y-2">
                        <div class="font-bold flex items-center gap-1.5">
                            <x-icon name="alert-triangle" class="h-4 w-4 text-rose-600"/>
                            {{ __('Invoice Cannot Be Deleted') }}
                        </div>
                        <p class="text-[11px] leading-relaxed">
                            {{ __('Payments have already been collected against invoice :inv. Please use Cancel / Revert instead.', [
                                'inv' => $targetInv->invoice_no,
                            ]) }}
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button wire:click="$set('showDeleteModal', false)" variant="outline">
                            {{ __('Close') }}
                        </x-ui.button>
                    </div>
                @else
                    <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-800 dark:text-rose-200 text-xs space-y-1">
                        <div class="font-bold">{{ __('Are you sure you want to permanently delete this invoice?') }}</div>
                        <p class="text-[11px] leading-relaxed">
                            {{ __('This will permanently delete invoice :inv and remove its ledger debit record.', [
                                'inv' => $targetInv?->invoice_no,
                            ]) }}
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button wire:click="$set('showDeleteModal', false)" variant="outline">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button wire:click="deleteInvoice" class="bg-rose-600 hover:bg-rose-700 text-white" wire:loading.attr="disabled">
                            <x-icon name="trash" class="h-4 w-4 mr-1.5"/>
                            {{ __('Confirm Delete') }}
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Send Reminder Modal --}}
    @if($showReminderModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">{{ __('Dispatch Fee Payment Reminder') }}</h3>
                    <button wire:click="$set('showReminderModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="space-y-3 text-xs">
                    <p class="text-muted-foreground">{{ __('Select channels to dispatch automated overdue / pending fee notifications:') }}</p>

                    <label class="flex items-center gap-2.5 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="database" class="rounded border-border text-primary focus:ring-primary">
                        <div>
                            <div class="font-medium text-foreground">{{ __('In-App Notification') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Shows banner & dashboard inbox alert to student') }}</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-2.5 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="email" class="rounded border-border text-primary focus:ring-primary">
                        <div>
                            <div class="font-medium text-foreground">{{ __('Official Email Alert') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Dispatched via institutional SMTP mailer') }}</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-2.5 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="sms" class="rounded border-border text-primary focus:ring-primary">
                        <div>
                            <div class="font-medium text-foreground">{{ __('SMS Notification') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Sends DLT compliant SMS alert to student mobile') }}</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-2.5 p-2 rounded-lg border border-border hover:bg-muted/40 cursor-pointer">
                        <input type="checkbox" wire:model="reminderChannels" value="whatsapp" class="rounded border-border text-primary focus:ring-primary">
                        <div>
                            <div class="font-medium text-foreground">{{ __('WhatsApp Alert') }}</div>
                            <div class="text-[11px] text-muted-foreground">{{ __('Logs and routes notification via WhatsApp channel') }}</div>
                        </div>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showReminderModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="sendReminder" wire:loading.attr="disabled">
                        <x-icon name="send" class="h-4 w-4 mr-1.5"/>
                        {{ __('Dispatch Now') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Import Payments Modal --}}
    @if($showImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">{{ __('Bulk Import Payments (Excel / CSV)') }}</h3>
                    <button wire:click="$set('showImportModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <p class="text-muted-foreground leading-relaxed">
                        {{ __('Upload an Excel (.xlsx) or CSV file with payment transaction entries. Supported columns: student_id (or roll_no), amount, payment_method, reference_id (UTR), payment_date (YYYY-MM-DD), invoice_no (optional).') }}
                    </p>

                    <div>
                        <input type="file" wire:model="importFile" class="block w-full text-xs text-muted-foreground file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:opacity-90"/>
                        @error('importFile') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="importAutoApprove" class="rounded border-border text-primary focus:ring-primary"/>
                        <span class="text-foreground">{{ __('Automatically verify and clear imported payments (settle invoices immediately)') }}</span>
                    </label>

                    @if(! empty($importResult))
                        <div class="p-3 rounded-lg bg-muted/40 border border-border space-y-1">
                            <div class="font-semibold text-foreground">{{ __('Import Results:') }}</div>
                            <div>{{ __('Processed Rows:') }} {{ $importResult['total'] }}</div>
                            <div class="text-emerald-600">{{ __('Successfully Imported:') }} {{ $importResult['imported'] }}</div>
                            <div class="text-amber-600">{{ __('Skipped / Invalid:') }} {{ $importResult['skipped'] }}</div>
                            @if(! empty($importResult['errors']))
                                <div class="mt-2 text-rose-600 space-y-0.5 max-h-32 overflow-y-auto">
                                    @foreach($importResult['errors'] as $err)
                                        <div>• {{ $err }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showImportModal', false)" variant="outline">
                        {{ __('Close') }}
                    </x-ui.button>
                    <x-ui.button wire:click="processImport" wire:loading.attr="disabled">
                        <x-icon name="upload" class="h-4 w-4 mr-1.5"/>
                        {{ __('Start Import') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
