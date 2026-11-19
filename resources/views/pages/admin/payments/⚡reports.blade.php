<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\FeeType;
use App\Models\LibraryDeposit;
use App\Models\PaymentTransaction;
use App\Models\Section;
use App\Models\StudentFeeInvoice;
use App\Services\Payment\FeeReminderService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Payment & Fee Collection Reports')] class extends Component {
    use WithPagination;

    public string $activeTab = 'feetype'; // 'feetype', 'collections', 'defaulters', 'paid', 'library'
    public string $batchFilter = 'all';
    public string $courseFilter = 'all';
    public string $sectionFilter = 'all';
    public string $feeTypeFilter = 'all';
    public string $monthFilter = 'all';
    public string $yearFilter = 'all';
    public string $statusFilter = 'all'; // 'all', 'paid', 'unpaid', 'overdue', 'partial'
    public string $fromDate = '';
    public string $toDate = '';
    public string $search = '';

    public function mount(): void
    {
        $this->fromDate = now()->startOfYear()->toDateString();
        $this->toDate = now()->toDateString();
    }

    public function updatingActiveTab(): void
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

    public function updatingSectionFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFeeTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingMonthFilter(): void
    {
        $this->resetPage();
    }

    public function updatingYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->feeTypeFilter = 'all';
        $this->monthFilter = 'all';
        $this->yearFilter = 'all';
        $this->statusFilter = 'all';
        $this->courseFilter = 'all';
        $this->batchFilter = 'all';
        $this->sectionFilter = 'all';
        $this->resetPage();
    }

    public function sendReminder(int $invoiceId): void
    {
        try {
            FeeReminderService::sendInvoiceReminder($invoiceId, ['database', 'email', 'sms'], auth()->id());
            Toast::dispatch($this, 'success', __('Reminder dispatched to student.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'xlsx')
    {
        if ($this->activeTab === 'feetype') {
            $query = $this->getFeeTypeInvoicesQuery();
            $headings = ['Invoice #', 'Invoice Date', 'Student Name', 'Student ID', 'Phone', 'Course', 'Batch', 'Section', 'Fee Head', 'Billing Period', 'Invoiced (₹)', 'Paid (₹)', 'Balance Due (₹)', 'Due Date', 'Status', 'Payment Method', 'Receipt #', 'Paid At'];

            return TableExporter::download(
                query: $query,
                format: $format,
                filename: 'fee-type-collection-report',
                headings: $headings,
                mapper: fn (StudentFeeInvoice $inv) => [
                    $inv->invoice_no,
                    $inv->created_at->format('d/m/Y'),
                    $inv->user?->name ?: 'N/A',
                    $inv->student_id ?: $inv->user?->student_id ?: 'N/A',
                    $inv->user?->phone ?: 'N/A',
                    $inv->enrollment?->course?->name ?: 'N/A',
                    $inv->enrollment?->batch?->name ?: 'N/A',
                    $inv->enrollment?->section?->name ?: 'N/A',
                    $inv->feeType?->name ?: 'N/A',
                    $inv->month_name ? ($inv->month_name.' '.$inv->period_year) : ($inv->period_year ?: 'N/A'),
                    number_format($inv->total_amount, 2),
                    number_format($inv->paid_amount, 2),
                    number_format($inv->balance_amount, 2),
                    $inv->due_date ? $inv->due_date->format('d/m/Y') : 'N/A',
                    ucfirst($inv->effective_status),
                    $inv->transactions()->where('status', 'successful')->latest('id')->value('payment_method') ? strtoupper(str_replace('_', ' ', $inv->transactions()->where('status', 'successful')->latest('id')->value('payment_method'))) : 'N/A',
                    $inv->transactions()->where('status', 'successful')->latest('id')->value('receipt_no') ?: 'N/A',
                    $inv->paid_at ? $inv->paid_at->format('d/m/Y') : 'N/A',
                ],
                title: 'SNTCSSC Fee Type Collection & Dues Register Report'
            );
        } elseif ($this->activeTab === 'defaulters') {
            $query = $this->getDefaultersQuery();
            $headings = ['Invoice #', 'Student Name', 'Student ID', 'Phone', 'Course', 'Batch', 'Fee Head', 'Billing Period', 'Balance Due (₹)', 'Due Date', 'Days Overdue'];

            return TableExporter::download(
                query: $query,
                format: $format,
                filename: 'fee-defaulters-report',
                headings: $headings,
                mapper: fn (StudentFeeInvoice $inv) => [
                    $inv->invoice_no,
                    $inv->user?->name ?: 'N/A',
                    $inv->student_id ?: $inv->user?->student_id ?: 'N/A',
                    $inv->user?->phone ?: 'N/A',
                    $inv->enrollment?->course?->name ?: 'N/A',
                    $inv->enrollment?->batch?->name ?: 'N/A',
                    $inv->feeType?->name ?: 'N/A',
                    $inv->month_name ? ($inv->month_name.' '.$inv->period_year) : ($inv->period_year ?: 'N/A'),
                    number_format($inv->balance_amount, 2),
                    $inv->due_date->format('d/m/Y'),
                    $inv->due_date->diffInDays(now()),
                ],
                title: 'SNTCSSC Unpaid & Defaulter Students Report'
            );
        } elseif ($this->activeTab === 'paid') {
            $query = $this->getPaidQuery();
            $headings = ['Invoice #', 'Student Name', 'Student ID', 'Course', 'Batch', 'Fee Head', 'Billing Period', 'Amount Paid (₹)', 'Receipt #', 'Paid Date'];

            return TableExporter::download(
                query: $query,
                format: $format,
                filename: 'fee-paid-students-report',
                headings: $headings,
                mapper: fn (StudentFeeInvoice $inv) => [
                    $inv->invoice_no,
                    $inv->user?->name ?: 'N/A',
                    $inv->student_id ?: $inv->user?->student_id ?: 'N/A',
                    $inv->enrollment?->course?->name ?: 'N/A',
                    $inv->enrollment?->batch?->name ?: 'N/A',
                    $inv->feeType?->name ?: 'N/A',
                    $inv->month_name ? ($inv->month_name.' '.$inv->period_year) : ($inv->period_year ?: 'N/A'),
                    number_format($inv->paid_amount, 2),
                    $inv->transactions()->where('status', 'successful')->latest('id')->value('receipt_no') ?: 'Cleared',
                    $inv->paid_at?->format('d/m/Y H:i') ?: 'N/A',
                ],
                title: 'SNTCSSC Fee Paid Students Report'
            );
        } elseif ($this->activeTab === 'library') {
            $query = $this->getLibraryQuery();
            $headings = ['Deposit #', 'Student Name', 'Student ID', 'Deposit Amount (₹)', 'Deposit Date', 'Status', 'Claim Date', 'Refund Ref / UTR', 'Refund Disbursed Date'];

            return TableExporter::download(
                query: $query,
                format: $format,
                filename: 'library-deposit-withdrawal-report',
                headings: $headings,
                mapper: fn (LibraryDeposit $d) => [
                    $d->deposit_no,
                    $d->user?->name ?: 'N/A',
                    $d->student_id ?: $d->user?->student_id ?: 'N/A',
                    number_format($d->amount, 2),
                    $d->deposit_date->format('d/m/Y'),
                    ucwords(str_replace('_', ' ', $d->status)),
                    $d->refund_requested_at?->format('d/m/Y') ?: 'N/A',
                    $d->refund_transaction_ref ?: 'N/A',
                    $d->refund_processed_at?->format('d/m/Y') ?: 'N/A',
                ],
                title: 'SNTCSSC Library Security Deposit & Withdrawal Audit Report'
            );
        } else {
            // Collection Summary export
            $summary = $this->getCollectionSummary();
            $headings = ['Course', 'Batch', 'Target Students', 'Invoiced Amount (₹)', 'Collected Amount (₹)', 'Pending Balance (₹)', 'Collection Rate (%)'];

            $format = strtolower($format);
            $filename = 'fee-collection-summary-'.now()->format('Ymd-His');

            if ($format === 'pdf') {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.table', [
                    'title' => 'SNTCSSC Fee Collection Summary Report',
                    'generatedAt' => now(),
                    'headings' => $headings,
                    'rows' => collect($summary)->map(fn ($r) => [
                        $r['course_name'],
                        $r['batch_name'],
                        $r['students_count'],
                        number_format($r['invoiced'], 2),
                        number_format($r['collected'], 2),
                        number_format($r['balance'], 2),
                        $r['rate'].'%',
                    ])->all(),
                ]);

                return response()->streamDownload(fn () => print($pdf->output()), "{$filename}.pdf", ['Content-Type' => 'application/pdf']);
            }

            return response()->streamDownload(function () use ($headings, $summary) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $headings);
                foreach ($summary as $r) {
                    fputcsv($out, [
                        $r['course_name'],
                        $r['batch_name'],
                        $r['students_count'],
                        number_format($r['invoiced'], 2),
                        number_format($r['collected'], 2),
                        number_format($r['balance'], 2),
                        $r['rate'].'%',
                    ]);
                }
                fclose($out);
            }, "{$filename}.csv", ['Content-Type' => 'text/csv']);
        }
    }

    protected function getFeeTypeInvoicesQuery(): Builder
    {
        return StudentFeeInvoice::query()
            ->with(['user.studentProfile', 'enrollment.course', 'enrollment.batch', 'enrollment.section', 'feeType', 'transactions'])
            ->when($this->feeTypeFilter !== 'all', fn ($q) => $q->where('fee_type_id', (int) $this->feeTypeFilter))
            ->when($this->monthFilter !== 'all', fn ($q) => $q->where('period_month', (int) $this->monthFilter))
            ->when($this->yearFilter !== 'all', fn ($q) => $q->where('period_year', (int) $this->yearFilter))
            ->when($this->batchFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('batch_id', (int) $this->batchFilter)))
            ->when($this->courseFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('course_id', (int) $this->courseFilter)))
            ->when($this->sectionFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('section_id', (int) $this->sectionFilter)))
            ->when($this->statusFilter !== 'all', function ($q) {
                if ($this->statusFilter === 'paid') {
                    $q->where('status', StudentFeeInvoice::STATUS_PAID);
                } elseif ($this->statusFilter === 'unpaid') {
                    $q->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL, StudentFeeInvoice::STATUS_OVERDUE]);
                } elseif ($this->statusFilter === 'overdue') {
                    $q->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL, StudentFeeInvoice::STATUS_OVERDUE])
                        ->where('due_date', '<', now()->toDateString());
                } elseif ($this->statusFilter === 'partial') {
                    $q->where('status', StudentFeeInvoice::STATUS_PARTIAL);
                }
            })
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
                                ->orWhere('urn', 'like', $term);
                        })
                        ->orWhereHas('transactions', function (Builder $tq) use ($term) {
                            $tq->where('receipt_no', 'like', $term)
                                ->orWhere('transaction_no', 'like', $term)
                                ->orWhere('offline_reference_id', 'like', $term);
                        });
                });
            })
            ->orderByDesc('created_at');
    }

    protected function getDefaultersQuery(): Builder
    {
        return StudentFeeInvoice::query()
            ->with(['user.studentProfile', 'enrollment.course', 'enrollment.batch', 'enrollment.section', 'feeType'])
            ->whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL, StudentFeeInvoice::STATUS_OVERDUE])
            ->where('due_date', '<', now()->toDateString())
            ->when($this->batchFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('batch_id', (int) $this->batchFilter)))
            ->when($this->courseFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('course_id', (int) $this->courseFilter)))
            ->when($this->sectionFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('section_id', (int) $this->sectionFilter)))
            ->when($this->feeTypeFilter !== 'all', fn ($q) => $q->where('fee_type_id', (int) $this->feeTypeFilter))
            ->when($this->monthFilter !== 'all', fn ($q) => $q->where('period_month', (int) $this->monthFilter))
            ->when($this->yearFilter !== 'all', fn ($q) => $q->where('period_year', (int) $this->yearFilter))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('invoice_no', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $term)->orWhere('phone', 'like', $term));
                });
            })
            ->orderBy('due_date');
    }

    protected function getPaidQuery(): Builder
    {
        return StudentFeeInvoice::query()
            ->with(['user.studentProfile', 'enrollment.course', 'enrollment.batch', 'feeType', 'transactions'])
            ->where('status', StudentFeeInvoice::STATUS_PAID)
            ->when($this->batchFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('batch_id', (int) $this->batchFilter)))
            ->when($this->courseFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('course_id', (int) $this->courseFilter)))
            ->when($this->sectionFilter !== 'all', fn ($q) => $q->whereHas('enrollment', fn ($eq) => $eq->where('section_id', (int) $this->sectionFilter)))
            ->when($this->feeTypeFilter !== 'all', fn ($q) => $q->where('fee_type_id', (int) $this->feeTypeFilter))
            ->when($this->monthFilter !== 'all', fn ($q) => $q->where('period_month', (int) $this->monthFilter))
            ->when($this->yearFilter !== 'all', fn ($q) => $q->where('period_year', (int) $this->yearFilter))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('invoice_no', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $term));
                });
            })
            ->orderByDesc('paid_at');
    }

    protected function getLibraryQuery(): Builder
    {
        return LibraryDeposit::query()
            ->with(['user.studentProfile', 'paymentTransaction'])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('deposit_no', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhere('refund_transaction_ref', 'like', $term)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $term));
                });
            })
            ->orderByDesc('created_at');
    }

    protected function getCollectionSummary(): array
    {
        $courses = Course::with(['enrollments.batch', 'feeSchedules'])->get();
        $summary = [];

        foreach ($courses as $c) {
            $batches = $c->batches;
            if ($batches->isEmpty()) {
                $batches = Batch::all();
            }

            foreach ($batches as $b) {
                if ($this->batchFilter !== 'all' && $b->id != $this->batchFilter) {
                    continue;
                }
                if ($this->courseFilter !== 'all' && $c->id != $this->courseFilter) {
                    continue;
                }

                $invoices = StudentFeeInvoice::whereHas('enrollment', function ($eq) use ($b, $c) {
                    $eq->where('batch_id', $b->id)->where('course_id', $c->id);
                })
                    ->when($this->feeTypeFilter !== 'all', fn ($q) => $q->where('fee_type_id', (int) $this->feeTypeFilter))
                    ->when($this->monthFilter !== 'all', fn ($q) => $q->where('period_month', (int) $this->monthFilter))
                    ->when($this->yearFilter !== 'all', fn ($q) => $q->where('period_year', (int) $this->yearFilter))
                    ->get();

                if ($invoices->isEmpty()) {
                    continue;
                }

                $invoiced = $invoices->sum('total_amount');
                $collected = $invoices->sum('paid_amount');
                $balance = $invoices->sum('balance_amount');
                $rate = $invoiced > 0 ? (int) round(($collected / $invoiced) * 100) : 0;
                $studentsCount = $invoices->unique('user_id')->count();

                $summary[] = [
                    'course_name' => $c->name,
                    'batch_name' => $b->name,
                    'students_count' => $studentsCount,
                    'invoiced' => $invoiced,
                    'collected' => $collected,
                    'balance' => $balance,
                    'rate' => $rate,
                ];
            }
        }

        return $summary;
    }

    public function with(): array
    {
        $batches = Batch::orderBy('name')->get();
        $courses = Course::orderBy('name')->get();
        $sections = Section::orderBy('name')->get();
        $feeTypes = FeeType::active()->get();

        $feeTypeInvoices = $this->getFeeTypeInvoicesQuery()->paginate(15);
        $totalInvoicedAmount = (clone $this->getFeeTypeInvoicesQuery())->sum('total_amount');
        $totalCollectedAmount = (clone $this->getFeeTypeInvoicesQuery())->sum('paid_amount');
        $totalBalanceAmount = (clone $this->getFeeTypeInvoicesQuery())->sum('balance_amount');
        $totalRecordsCount = (clone $this->getFeeTypeInvoicesQuery())->count();

        $data = [
            'feeTypeInvoices' => $feeTypeInvoices,
            'totalInvoicedAmount' => $totalInvoicedAmount,
            'totalCollectedAmount' => $totalCollectedAmount,
            'totalBalanceAmount' => $totalBalanceAmount,
            'totalRecordsCount' => $totalRecordsCount,
        ];

        if ($this->activeTab === 'defaulters') {
            $data['defaulters'] = $this->getDefaultersQuery()->paginate(15);
        } elseif ($this->activeTab === 'paid') {
            $data['paid'] = $this->getPaidQuery()->paginate(15);
        } elseif ($this->activeTab === 'library') {
            $data['library'] = $this->getLibraryQuery()->paginate(15);
        } else {
            $data['summary'] = $this->getCollectionSummary();
        }

        return array_merge($data, [
            'batches' => $batches,
            'courses' => $courses,
            'sections' => $sections,
            'feeTypes' => $feeTypes,
        ]);
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Payment Collection & Financial Reports') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Generate comprehensive reports: Course/Batch/Section wise collection, paid roster, overdue defaulters list, and library deposit audit.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="export('xlsx')" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-muted text-foreground inline-flex items-center">
                <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 mr-1.5 text-emerald-600"/>
                {{ __('Excel (.xlsx)') }}
            </button>
            <button wire:click="export('csv')" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-muted text-foreground inline-flex items-center">
                <x-icon name="file-text" class="h-3.5 w-3.5 mr-1.5 text-blue-600"/>
                {{ __('CSV') }}
            </button>
            <button wire:click="export('pdf')" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-muted text-foreground inline-flex items-center">
                <x-icon name="file" class="h-3.5 w-3.5 mr-1.5 text-rose-600"/>
                {{ __('PDF') }}
            </button>
        </div>
    </div>

    {{-- Report Navigation Tabs --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-border pb-3">
        <button wire:click="$set('activeTab', 'feetype')" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors {{ $activeTab === 'feetype' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-muted/40 text-muted-foreground hover:text-foreground' }}">
            <x-icon name="layers" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Fee Type & Periodic Collections Register') }}
        </button>

        <button wire:click="$set('activeTab', 'collections')" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors {{ $activeTab === 'collections' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-muted/40 text-muted-foreground hover:text-foreground' }}">
            <x-icon name="bar-chart-3" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Cohort Summary Matrix') }}
        </button>

        <button wire:click="$set('activeTab', 'defaulters')" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors {{ $activeTab === 'defaulters' ? 'bg-rose-600 text-white shadow-xs' : 'bg-muted/40 text-muted-foreground hover:text-foreground' }}">
            <x-icon name="alert-triangle" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Unpaid & Defaulters') }}
        </button>

        <button wire:click="$set('activeTab', 'paid')" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors {{ $activeTab === 'paid' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-muted/40 text-muted-foreground hover:text-foreground' }}">
            <x-icon name="badge-check" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Paid Students Roster') }}
        </button>

        <button wire:click="$set('activeTab', 'library')" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors {{ $activeTab === 'library' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-muted/40 text-muted-foreground hover:text-foreground' }}">
            <x-icon name="book-open" class="h-3.5 w-3.5 inline mr-1.5"/>
            {{ __('Library Deposit & Refunds') }}
        </button>

        <a href="{{ route('admin.payments.ledger') }}" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors bg-muted/40 text-muted-foreground hover:text-foreground inline-flex items-center">
            <x-icon name="book-marked" class="h-3.5 w-3.5 inline mr-1.5 text-primary"/>
            {{ __('Student Financial Ledgers') }}
        </a>
    </div>

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <x-ui.input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search student, ID, URN, invoice #, receipt #...') }}"
                />
            </div>

            <div>
                <select wire:model.live="feeTypeFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Fee Heads / Types') }}</option>
                    @foreach($feeTypes as $ft)
                        <option value="{{ $ft->id }}">{{ $ft->name }} ({{ strtoupper($ft->code) }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select wire:model.live="monthFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Billing Months') }}</option>
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}">{{ \Carbon\Carbon::create(null, $m, 1)->format('F') }}</option>
                    @endfor
                </select>
            </div>

            <div>
                <select wire:model.live="yearFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Billing Years') }}</option>
                    @foreach([2024, 2025, 2026, 2027, 2028] as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 pt-2 border-t border-border/60">
            <div>
                <select wire:model.live="statusFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Payment Statuses') }}</option>
                    <option value="paid">{{ __('Paid (Full Clearance)') }}</option>
                    <option value="unpaid">{{ __('Unpaid (All Pending & Due)') }}</option>
                    <option value="overdue">{{ __('Overdue Defaulters Only') }}</option>
                    <option value="partial">{{ __('Partially Paid') }}</option>
                </select>
            </div>

            <div>
                <select wire:model.live="courseFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Courses') }}</option>
                    @foreach($courses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
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
                <select wire:model.live="sectionFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Sections') }}</option>
                    @foreach($sections as $sec)
                        <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center">
                <button wire:click="clearFilters" class="w-full h-9 px-3 text-xs font-medium rounded-lg border border-border bg-muted/40 hover:bg-muted text-muted-foreground hover:text-foreground transition-colors flex items-center justify-center gap-1.5">
                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5"/>
                    {{ __('Reset Filters') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Tab 0: Fee Type Collections & Dues Register --}}
    @if($activeTab === 'feetype')
        <div class="space-y-4">
            {{-- KPI Summary Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                    <span class="text-xs text-muted-foreground font-medium uppercase tracking-wider">{{ __('Total Invoiced') }}</span>
                    <div class="text-xl font-bold text-foreground mt-1">₹{{ number_format($totalInvoicedAmount, 2) }}</div>
                    <span class="text-[11px] text-muted-foreground">{{ __('Total demand raised') }}</span>
                </div>

                <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                    <span class="text-xs text-emerald-600 font-medium uppercase tracking-wider">{{ __('Total Collected') }}</span>
                    <div class="text-xl font-bold text-emerald-600 mt-1">₹{{ number_format($totalCollectedAmount, 2) }}</div>
                    <span class="text-[11px] text-muted-foreground">
                        @if($totalInvoicedAmount > 0)
                            {{ round(($totalCollectedAmount / $totalInvoicedAmount) * 100, 1) }}% {{ __('recovery rate') }}
                        @else
                            {{ __('No demand') }}
                        @endif
                    </span>
                </div>

                <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                    <span class="text-xs text-rose-600 font-medium uppercase tracking-wider">{{ __('Balance Due') }}</span>
                    <div class="text-xl font-bold text-rose-600 mt-1">₹{{ number_format($totalBalanceAmount, 2) }}</div>
                    <span class="text-[11px] text-muted-foreground">{{ __('Uncollected pending liabilities') }}</span>
                </div>

                <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                    <span class="text-xs text-muted-foreground font-medium uppercase tracking-wider">{{ __('Matching Records') }}</span>
                    <div class="text-xl font-bold text-foreground mt-1">{{ number_format($totalRecordsCount) }}</div>
                    <span class="text-[11px] text-muted-foreground">{{ __('Student fee invoice accounts') }}</span>
                </div>
            </div>

            {{-- Table --}}
            <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                <div class="p-4 border-b border-border bg-muted/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">{{ __('Fee Type & Periodic Collection Ledger') }}</h3>
                        <p class="text-[11px] text-muted-foreground">{{ __('Month-wise and cohort-wise breakdown of student collections, pending balances, and receipt references.') }}</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                            <tr>
                                <th class="px-4 py-3">{{ __('Invoice #') }}</th>
                                <th class="px-4 py-3">{{ __('Student') }}</th>
                                <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                                <th class="px-4 py-3">{{ __('Fee Head & Period') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Invoiced') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Paid') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Balance Due') }}</th>
                                <th class="px-4 py-3">{{ __('Due Date & Status') }}</th>
                                <th class="px-4 py-3">{{ __('Receipt / Mode') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse($feeTypeInvoices as $inv)
                                @php
                                    $txn = $inv->transactions()->where('status', 'successful')->latest('id')->first();
                                    $isOverdue = $inv->isOverdue();
                                @endphp
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">
                                        {{ $inv->invoice_no }}
                                        <div class="text-[10px] text-muted-foreground font-sans">{{ $inv->created_at->format('d/m/Y') }}</div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="font-medium text-foreground">{{ $inv->user?->name }}</div>
                                        <div class="text-[11px] text-muted-foreground font-mono">
                                            {{ $inv->student_id ?: $inv->user?->student_id ?: 'No ID' }}
                                            @if($inv->user?->phone)
                                                • {{ $inv->user->phone }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="font-medium text-foreground">{{ $inv->enrollment?->course?->name ?: 'N/A' }}</div>
                                        <div class="text-[11px] text-muted-foreground">
                                            {{ $inv->enrollment?->batch?->name ?: 'N/A' }}
                                            @if($inv->enrollment?->section)
                                                • {{ $inv->enrollment->section->name }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="font-semibold text-foreground">{{ $inv->feeType?->name ?: 'N/A' }}</div>
                                        <div class="text-[11px] text-primary">
                                            @if($inv->month_name)
                                                {{ $inv->month_name }} {{ $inv->period_year }}
                                            @elseif($inv->period_year)
                                                {{ $inv->period_year }}
                                            @else
                                                {{ __('One-Time') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium whitespace-nowrap">
                                        ₹{{ number_format($inv->total_amount, 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-emerald-600 whitespace-nowrap">
                                        ₹{{ number_format($inv->paid_amount, 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold whitespace-nowrap {{ $inv->balance_amount > 0 ? ($isOverdue ? 'text-rose-600' : 'text-amber-600') : 'text-muted-foreground' }}">
                                        ₹{{ number_format($inv->balance_amount, 2) }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center gap-1.5">
                                            @if($inv->effective_status === 'paid')
                                                <x-ui.badge variant="success" class="text-[10px]">{{ __('Paid') }}</x-ui.badge>
                                            @elseif($inv->effective_status === 'partial')
                                                <x-ui.badge variant="warning" class="text-[10px]">{{ __('Partial') }}</x-ui.badge>
                                            @elseif($inv->effective_status === 'overdue')
                                                <x-ui.badge variant="destructive" class="text-[10px]">{{ __('Overdue') }}</x-ui.badge>
                                            @elseif($inv->effective_status === 'cancelled')
                                                <x-ui.badge variant="secondary" class="text-[10px]">{{ __('Cancelled') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="secondary" class="text-[10px]">{{ __('Pending') }}</x-ui.badge>
                                            @endif
                                        </div>
                                        <div class="text-[10px] text-muted-foreground mt-0.5">
                                            {{ __('Due:') }} {{ $inv->due_date ? $inv->due_date->format('d M Y') : 'N/A' }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if($txn)
                                            <div class="font-mono text-[11px] font-semibold text-emerald-600">{{ $txn->receipt_no }}</div>
                                            <div class="text-[10px] text-muted-foreground uppercase">{{ str_replace('_', ' ', $txn->payment_method) }}</div>
                                        @elseif($inv->status === 'paid')
                                            <span class="text-emerald-600 font-medium">{{ __('Cleared') }}</span>
                                        @else
                                            <span class="text-muted-foreground text-[11px]">{{ __('Pending') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            @if($txn)
                                                <a href="{{ route('payments.receipt.download', $txn) }}" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium text-primary hover:bg-primary/10 transition-colors" title="{{ __('Download PDF Receipt') }}">
                                                    <x-icon name="receipt" class="h-3.5 w-3.5 mr-1"/>
                                                    {{ __('PDF') }}
                                                </a>
                                            @elseif($inv->balance_amount > 0)
                                                <button wire:click="sendReminder({{ $inv->id }})" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium text-amber-600 hover:bg-amber-500/10 transition-colors" title="{{ __('Send Payment Reminder') }}">
                                                    <x-icon name="bell" class="h-3.5 w-3.5 mr-1 text-amber-500"/>
                                                    {{ __('Remind') }}
                                                </button>
                                            @else
                                                <span class="text-muted-foreground">-</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="px-4 py-12 text-center text-muted-foreground">
                                        <x-icon name="inbox" class="h-8 w-8 mx-auto mb-2 text-muted-foreground/40"/>
                                        <p class="font-medium text-foreground">{{ __('No fee invoice or collection records found.') }}</p>
                                        <p class="text-xs text-muted-foreground mt-0.5">{{ __('Try adjusting your fee type, month, cohort, or status filters.') }}</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-border">
                    {{ $feeTypeInvoices->links() }}
                </div>
            </div>
        </div>
    @endif

    {{-- Tab 1: Collection Summary --}}
    @if($activeTab === 'collections')
        <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="p-4 border-b border-border bg-muted/20">
                <h3 class="text-sm font-semibold text-foreground">{{ __('Institutional Cohort Fee Collection Matrix') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                        <tr>
                            <th class="px-4 py-3">{{ __('Course') }}</th>
                            <th class="px-4 py-3">{{ __('Batch') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('Active Students') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total Demanded') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total Collected') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Pending Balance') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('Recovery Rate') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($summary as $row)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3 font-semibold text-foreground">{{ $row['course_name'] }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $row['batch_name'] }}</td>
                                <td class="px-4 py-3 text-center font-mono font-medium">{{ $row['students_count'] }}</td>
                                <td class="px-4 py-3 text-right font-medium text-foreground">₹{{ number_format($row['invoiced'], 2) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-emerald-600">₹{{ number_format($row['collected'], 2) }}</td>
                                <td class="px-4 py-3 text-right font-bold text-amber-600">₹{{ number_format($row['balance'], 2) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <div class="inline-flex items-center gap-1.5">
                                        <div class="w-16 bg-muted rounded-full h-2 overflow-hidden">
                                            <div class="bg-emerald-500 h-2 rounded-full" style="width: {{ min(100, $row['rate']) }}%"></div>
                                        </div>
                                        <span class="font-bold text-[11px] text-foreground">{{ $row['rate'] }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                    <p>{{ __('No fee demand records found for current filters.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Tab 2: Defaulters / Unpaid Students --}}
    @if($activeTab === 'defaulters')
        <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="p-4 border-b border-border bg-rose-500/10 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-rose-800 dark:text-rose-300">{{ __('Fee Defaulters & Overdue Account Report') }}</h3>
                    <p class="text-[11px] text-rose-700 dark:text-rose-400">{{ __('Students who have not paid their respected fees within the assigned timeline.') }}</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                        <tr>
                            <th class="px-4 py-3">{{ __('Invoice #') }}</th>
                            <th class="px-4 py-3">{{ __('Student') }}</th>
                            <th class="px-4 py-3">{{ __('Contact Phone') }}</th>
                            <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Overdue Amount') }}</th>
                            <th class="px-4 py-3">{{ __('Due Date') }}</th>
                            <th class="px-4 py-3">{{ __('Days Lapsed') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Reminder Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($defaulters as $def)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">{{ $def->invoice_no }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-foreground">{{ $def->user?->name }}</div>
                                    <div class="text-[11px] text-muted-foreground font-mono">{{ $def->student_id ?: $def->user?->student_id }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap font-mono text-muted-foreground">
                                    {{ $def->user?->phone ?: 'N/A' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div>{{ $def->enrollment?->course?->name }}</div>
                                    <div class="text-[11px] text-muted-foreground">{{ $def->enrollment?->batch?->name }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-rose-600 whitespace-nowrap">
                                    ₹{{ number_format($def->balance_amount, 2) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-rose-600 font-medium">
                                    {{ $def->due_date->format('d M Y') }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap font-bold text-rose-600">
                                    {{ $def->due_date->diffInDays(now()) }} {{ __('Days') }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <x-ui.button wire:click="sendReminder({{ $def->id }})" size="sm" variant="outline" class="text-xs">
                                        <x-icon name="bell" class="h-3.5 w-3.5 mr-1 text-amber-500"/>
                                        {{ __('Remind') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                    <x-icon name="check-circle" class="h-8 w-8 mx-auto mb-2 text-emerald-500/50"/>
                                    <p class="font-medium text-emerald-600">{{ __('No overdue fee defaulters found! All dues are clear.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-border">
                {{ $defaulters->links() }}
            </div>
        </div>
    @endif

    {{-- Tab 3: Paid Students Roster --}}
    @if($activeTab === 'paid')
        <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="p-4 border-b border-border bg-emerald-500/10">
                <h3 class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">{{ __('Paid Students Cleared Roster') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                        <tr>
                            <th class="px-4 py-3">{{ __('Invoice #') }}</th>
                            <th class="px-4 py-3">{{ __('Student') }}</th>
                            <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                            <th class="px-4 py-3">{{ __('Fee Item') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Amount Cleared') }}</th>
                            <th class="px-4 py-3">{{ __('Receipt #') }}</th>
                            <th class="px-4 py-3">{{ __('Paid Timestamp') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Receipt') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($paid as $p)
                            @php
                                $txn = $p->transactions()->where('status', 'successful')->latest('id')->first();
                            @endphp
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">{{ $p->invoice_no }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-foreground">{{ $p->user?->name }}</div>
                                    <div class="text-[11px] text-muted-foreground font-mono">{{ $p->student_id ?: $p->user?->student_id }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div>{{ $p->enrollment?->course?->name }}</div>
                                    <div class="text-[11px] text-muted-foreground">{{ $p->enrollment?->batch?->name }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ $p->feeType?->name }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-emerald-600 whitespace-nowrap">
                                    ₹{{ number_format($p->paid_amount, 2) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap font-mono text-emerald-600">
                                    {{ $txn?->receipt_no ?: 'Cleared' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ $p->paid_at?->format('d M Y, h:i A') ?: 'N/A' }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if($txn)
                                        <a href="{{ route('payments.receipt.download', $txn) }}" class="inline-flex items-center text-primary font-medium hover:underline">
                                            <x-icon name="receipt" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('PDF') }}
                                        </a>
                                    @else
                                        <span class="text-muted-foreground">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                    <p>{{ __('No paid student records found for the filter criteria.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-border">
                {{ $paid->links() }}
            </div>
        </div>
    @endif

    {{-- Tab 4: Library Security Deposit Audit --}}
    @if($activeTab === 'library')
        <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="p-4 border-b border-border bg-primary/10">
                <h3 class="text-sm font-semibold text-foreground">{{ __('Library Security Deposit & Withdrawal Audit Report') }}</h3>
                <p class="text-[11px] text-muted-foreground">{{ __('Tracks students who deposited library security, who requested refund, clearance status, and who withdrew the deposit.') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                        <tr>
                            <th class="px-4 py-3">{{ __('Deposit #') }}</th>
                            <th class="px-4 py-3">{{ __('Student') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Deposit Amount') }}</th>
                            <th class="px-4 py-3">{{ __('Deposit Date') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3">{{ __('Withdrawal Requested') }}</th>
                            <th class="px-4 py-3">{{ __('Bank / UTR Disbursed') }}</th>
                            <th class="px-4 py-3">{{ __('Clearance Date') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($library as $lib)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">{{ $lib->deposit_no }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-foreground">{{ $lib->user?->name }}</div>
                                    <div class="text-[11px] text-muted-foreground font-mono">{{ $lib->student_id ?: $lib->user?->student_id }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-foreground whitespace-nowrap">
                                    ₹{{ number_format($lib->amount, 2) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $lib->deposit_date->format('d M Y') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if($lib->status === 'active')
                                        <x-ui.badge variant="success">{{ __('Deposit Active (Held)') }}</x-ui.badge>
                                    @elseif($lib->status === 'refund_requested')
                                        <x-ui.badge variant="warning">{{ __('Refund Claimed') }}</x-ui.badge>
                                    @elseif($lib->status === 'refund_approved')
                                        <x-ui.badge variant="secondary" class="bg-blue-500/10 text-blue-600">{{ __('Approved for Payout') }}</x-ui.badge>
                                    @elseif($lib->status === 'refund_processed')
                                        <x-ui.badge variant="default" class="bg-purple-500/10 text-purple-600">{{ __('Withdrawn / Returned') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="secondary">{{ ucfirst($lib->status) }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                    {{ $lib->refund_requested_at?->format('d M Y') ?: 'Not Requested' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap font-mono text-[11px]">
                                    @if($lib->refund_transaction_ref)
                                        <span class="text-emerald-600 font-semibold">{{ $lib->refund_transaction_ref }}</span>
                                    @else
                                        <span class="text-muted-foreground">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                    {{ $lib->refund_processed_at?->format('d M Y') ?: '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                    <p>{{ __('No library security deposit records found.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-border">
                {{ $library->links() }}
            </div>
        </div>
    @endif
</div>
