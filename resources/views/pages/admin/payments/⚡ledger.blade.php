<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\FeeType;
use App\Models\PaymentTransaction;
use App\Models\Section;
use App\Models\StudentFeeInvoice;
use App\Models\StudentLedger;
use App\Models\User;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Student Financial Ledger & Statements')] class extends Component {
    use WithPagination;

    #[Url(as: 'mode')]
    public string $viewMode = 'cohort'; // 'cohort' or 'single'

    #[Url(as: 'student')]
    public ?int $selectedStudentId = null;

    #[Url(as: 'tab')]
    public string $singleTab = 'ledger'; // 'ledger', 'invoices', 'payments', 'deposits'

    #[Url(as: 'cohort_tab')]
    public string $cohortTab = 'students'; // 'students' (student summary register) or 'ledger' (full passbook log)

    public string $search = '';
    public string $studentPickerSearch = '';
    public string $batchFilter = 'all';
    public string $courseFilter = 'all';
    public string $sectionFilter = 'all';
    public string $entryTypeFilter = 'all'; // 'all', 'debit', 'credit'
    public string $feeTypeFilter = 'all';
    public string $refTypeFilter = 'all'; // 'all', 'invoice', 'payment', 'deposit', 'refund', 'waiver', 'adjustment'
    public string $balanceFilter = 'all'; // 'all', 'dues', 'cleared', 'advance'
    public string $fromDate = '';
    public string $toDate = '';
    public string $sortBy = 'transaction_date';
    public string $sortDir = 'desc';

    public function mount(?int $student = null, ?string $mode = null): void
    {
        if ($student) {
            $this->selectedStudentId = $student;
            $this->viewMode = 'single';
        } elseif ($this->selectedStudentId) {
            $this->viewMode = 'single';
        }

        if ($mode) {
            $this->viewMode = $mode;
        }
    }

    public function updatingSearch(): void
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

    public function updatingEntryTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFeeTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingRefTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingBalanceFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFromDate(): void
    {
        $this->resetPage();
    }

    public function updatingToDate(): void
    {
        $this->resetPage();
    }

    public function updatingCohortTab(): void
    {
        $this->resetPage();
    }

    public function updatingSingleTab(): void
    {
        $this->resetPage();
    }

    public function selectStudent(int $studentId): void
    {
        $this->selectedStudentId = $studentId;
        $this->viewMode = 'single';
        $this->studentPickerSearch = '';
        $this->singleTab = 'ledger';
        $this->resetPage();
    }

    public function clearStudentSelection(): void
    {
        $this->selectedStudentId = null;
        $this->viewMode = 'cohort';
        $this->studentPickerSearch = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->batchFilter = 'all';
        $this->courseFilter = 'all';
        $this->sectionFilter = 'all';
        $this->entryTypeFilter = 'all';
        $this->feeTypeFilter = 'all';
        $this->refTypeFilter = 'all';
        $this->balanceFilter = 'all';
        $this->fromDate = '';
        $this->toDate = '';
        $this->sortBy = 'transaction_date';
        $this->sortDir = 'desc';
        $this->resetPage();
    }

    public function setSort(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDir = 'desc';
        }
        $this->resetPage();
    }

    /**
     * Build base query for StudentLedger with all current cohort, fee type, date, and entry filters.
     */
    protected function getLedgerQuery(): Builder
    {
        $query = StudentLedger::query()->with([
            'user.studentProfile',
            'user.enrollments.batch',
            'user.enrollments.course',
            'user.enrollments.section',
        ]);

        if ($this->selectedStudentId) {
            $query->where('user_id', $this->selectedStudentId);
        } else {
            // Filter by cohort if batch, course, or section is selected
            if ($this->batchFilter !== 'all' || $this->courseFilter !== 'all' || $this->sectionFilter !== 'all') {
                $query->whereHas('user.enrollments', function (Builder $eq) {
                    if ($this->batchFilter !== 'all') {
                        $eq->where('batch_id', $this->batchFilter);
                    }
                    if ($this->courseFilter !== 'all') {
                        $eq->where('course_id', $this->courseFilter);
                    }
                    if ($this->sectionFilter !== 'all') {
                        $eq->where('section_id', $this->sectionFilter);
                    }
                });
            }
        }

        // Search text
        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('ledger_no', 'like', $term)
                    ->orWhere('student_id', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('user', function (Builder $uq) use ($term) {
                        $uq->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('phone', 'like', $term)
                            ->orWhere('student_id', 'like', $term);
                    });
            });
        }

        // Entry Type (Debit / Credit)
        if ($this->entryTypeFilter !== 'all') {
            $query->where('entry_type', $this->entryTypeFilter);
        }

        // Reference Type (invoice, payment, deposit, refund, waiver, adjustment)
        if ($this->refTypeFilter !== 'all') {
            $query->where('reference_type', $this->refTypeFilter);
        }

        // Fee Type Head Filter
        if ($this->feeTypeFilter !== 'all') {
            $feeTypeId = (int) $this->feeTypeFilter;
            $feeType = FeeType::find($feeTypeId);

            $query->where(function (Builder $q) use ($feeTypeId, $feeType) {
                $q->where(function (Builder $iq) use ($feeTypeId) {
                    $iq->where('reference_type', StudentLedger::REF_INVOICE)
                        ->whereIn('reference_id', StudentFeeInvoice::where('fee_type_id', $feeTypeId)->select('id'));
                })->orWhere(function (Builder $pq) use ($feeTypeId) {
                    $pq->where('reference_type', StudentLedger::REF_PAYMENT)
                        ->whereIn('reference_id', PaymentTransaction::where('fee_type_id', $feeTypeId)->select('id'));
                });

                if ($feeType && $feeType->code === 'library_security') {
                    $q->orWhereIn('reference_type', [StudentLedger::REF_DEPOSIT, StudentLedger::REF_REFUND]);
                }
            });
        }

        // Date Range
        if ($this->fromDate !== '') {
            $query->whereDate('transaction_date', '>=', $this->fromDate);
        }
        if ($this->toDate !== '') {
            $query->whereDate('transaction_date', '<=', $this->toDate);
        }

        // Sorting
        $allowedSorts = ['transaction_date', 'amount', 'balance_after', 'ledger_no', 'id'];
        $sortColumn = in_array($this->sortBy, $allowedSorts) ? $this->sortBy : 'transaction_date';
        $direction = strtolower($this->sortDir) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)->orderBy('id', $direction);
    }

    /**
     * Build query for students cohort summary register.
     */
    protected function getStudentsRegisterQuery(): Builder
    {
        $query = User::query()
            ->where(function (Builder $q) {
                $q->whereHas('roles', fn ($rq) => $rq->where('name', 'Student'))
                    ->orWhereHas('enrollments')
                    ->orWhereHas('studentLedgers');
            })
            ->with([
                'enrollments' => fn ($eq) => $eq->with(['batch', 'course', 'section'])->latest('id'),
                'studentProfile',
            ]);

        // Filter by batch, course, section
        if ($this->batchFilter !== 'all' || $this->courseFilter !== 'all' || $this->sectionFilter !== 'all') {
            $query->whereHas('enrollments', function (Builder $eq) {
                if ($this->batchFilter !== 'all') {
                    $eq->where('batch_id', $this->batchFilter);
                }
                if ($this->courseFilter !== 'all') {
                    $eq->where('course_id', $this->courseFilter);
                }
                if ($this->sectionFilter !== 'all') {
                    $eq->where('section_id', $this->sectionFilter);
                }
            });
        }

        // Search text
        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('student_id', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        // Balance filter (Dues, Cleared, Advance)
        if ($this->balanceFilter !== 'all') {
            $debitsSql = "(SELECT COALESCE(SUM(amount), 0) FROM student_ledgers WHERE student_ledgers.user_id = users.id AND student_ledgers.entry_type = 'debit')";
            $creditsSql = "(SELECT COALESCE(SUM(amount), 0) FROM student_ledgers WHERE student_ledgers.user_id = users.id AND student_ledgers.entry_type = 'credit')";

            if ($this->balanceFilter === 'dues') {
                $query->whereRaw("({$debitsSql} - {$creditsSql}) > 0");
            } elseif ($this->balanceFilter === 'cleared') {
                $query->whereRaw("({$debitsSql} - {$creditsSql}) = 0");
            } elseif ($this->balanceFilter === 'advance') {
                $query->whereRaw("({$debitsSql} - {$creditsSql}) < 0");
            }
        }

        return $query->orderBy('name');
    }

    /**
     * Export reports in Excel, CSV, or PDF formats.
     */
    public function export(string $format = 'xlsx')
    {
        $format = strtolower($format);

        // Case 1: Single Student Statement of Account
        if ($this->viewMode === 'single' && $this->selectedStudentId) {
            $student = User::with(['enrollments.course', 'enrollments.batch', 'enrollments.section'])->findOrFail($this->selectedStudentId);
            $query = $this->getLedgerQuery();
            $headings = ['Date', 'Ledger #', 'Particulars / Description', 'Type', 'Debit (₹)', 'Credit (₹)', 'Running Balance (₹)', 'Reference'];

            $courseName = $student->enrollments->first()?->course?->name ?: 'N/A';
            $batchName = $student->enrollments->first()?->batch?->name ?: 'N/A';
            $title = "Financial Statement of Account - {$student->name} ({$student->student_id}) [{$courseName} - {$batchName}]";

            return TableExporter::download(
                query: $query,
                format: $format,
                filename: "student-ledger-{$student->student_id}-".now()->format('Ymd'),
                headings: $headings,
                mapper: fn (StudentLedger $l) => [
                    $l->transaction_date ? $l->transaction_date->format('d/m/Y') : 'N/A',
                    $l->ledger_no,
                    $l->description,
                    strtoupper($l->entry_type),
                    $l->entry_type === 'debit' ? number_format($l->amount, 2) : '0.00',
                    $l->entry_type === 'credit' ? number_format($l->amount, 2) : '0.00',
                    number_format($l->balance_after, 2),
                    ucfirst($l->reference_type).' #'.($l->reference_id ?? 'N/A'),
                ],
                title: $title
            );
        }

        // Case 2: Multi-Student Cohort Register
        if ($this->cohortTab === 'students') {
            $students = $this->getStudentsRegisterQuery()->get();
            $headings = ['Student ID', 'Student Name', 'Course', 'Batch', 'Section', 'Phone', 'Total Invoiced (₹)', 'Total Paid (₹)', 'Balance Due (₹)', 'Dues Status'];

            $rows = $students->map(function (User $s) {
                $enrollment = $s->enrollments->first();
                $totalInvoiced = (float) StudentLedger::where('user_id', $s->id)->where('entry_type', StudentLedger::ENTRY_DEBIT)->sum('amount');
                $totalPaid = (float) StudentLedger::where('user_id', $s->id)->where('entry_type', StudentLedger::ENTRY_CREDIT)->sum('amount');
                $balance = $totalInvoiced - $totalPaid;
                $status = $balance > 0 ? 'Dues Outstanding' : ($balance < 0 ? 'Advance Surplus' : 'Cleared / Settled');

                return [
                    $s->student_id ?: 'N/A',
                    $s->name,
                    $enrollment?->course?->name ?: 'N/A',
                    $enrollment?->batch?->name ?: 'N/A',
                    $enrollment?->section?->name ?: 'N/A',
                    $s->phone ?: 'N/A',
                    number_format($totalInvoiced, 2),
                    number_format($totalPaid, 2),
                    number_format($balance, 2),
                    $status,
                ];
            });

            // Filter rows by balance if needed
            if ($this->balanceFilter === 'dues') {
                $rows = $rows->filter(fn ($r) => (float) str_replace(',', '', $r[8]) > 0.00)->values();
            } elseif ($this->balanceFilter === 'cleared') {
                $rows = $rows->filter(fn ($r) => (float) str_replace(',', '', $r[8]) == 0.00)->values();
            } elseif ($this->balanceFilter === 'advance') {
                $rows = $rows->filter(fn ($r) => (float) str_replace(',', '', $r[8]) < 0.00)->values();
            }

            $filename = 'cohort-student-financial-summary-'.now()->format('Ymd-His');
            $title = 'SNTCSSC Student Cohort Financial Summary Register';

            if ($format === 'pdf') {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.table', [
                    'title' => $title,
                    'generatedAt' => now(),
                    'headings' => $headings,
                    'rows' => $rows->all(),
                ]);

                return response()->streamDownload(fn () => print($pdf->output()), "{$filename}.pdf", ['Content-Type' => 'application/pdf']);
            }

            return response()->streamDownload(function () use ($headings, $rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $headings);
                foreach ($rows as $r) {
                    fputcsv($out, $r);
                }
                fclose($out);
            }, "{$filename}.csv", ['Content-Type' => 'text/csv']);
        }

        // Case 3: Consolidated Cohort Ledger Entries
        $query = $this->getLedgerQuery();
        $headings = ['Date', 'Ledger #', 'Student Name', 'Student ID', 'Course', 'Batch', 'Type', 'Amount (₹)', 'Running Balance (₹)', 'Particulars', 'Reference'];

        return TableExporter::download(
            query: $query,
            format: $format,
            filename: 'consolidated-financial-ledger-'.now()->format('Ymd-His'),
            headings: $headings,
            mapper: fn (StudentLedger $l) => [
                $l->transaction_date ? $l->transaction_date->format('d/m/Y') : 'N/A',
                $l->ledger_no,
                $l->user?->name ?: 'N/A',
                $l->student_id ?: $l->user?->student_id ?: 'N/A',
                $l->user?->enrollments->first()?->course?->name ?: 'N/A',
                $l->user?->enrollments->first()?->batch?->name ?: 'N/A',
                strtoupper($l->entry_type),
                number_format($l->amount, 2),
                number_format($l->balance_after, 2),
                $l->description,
                ucfirst($l->reference_type),
            ],
            title: 'SNTCSSC Consolidated Double-Entry Student Financial Ledger'
        );
    }

    public function with(): array
    {
        $batches = Batch::orderBy('name')->get(['id', 'name']);
        $courses = Course::orderBy('name')->get(['id', 'name', 'code']);
        $sections = Section::orderBy('name')->get(['id', 'name', 'code']);
        $feeTypes = FeeType::active()->orderBy('name')->get(['id', 'name', 'code']);

        // Quick student picker search results
        $studentPickerResults = [];
        if ($this->studentPickerSearch !== '') {
            $pTerm = '%'.trim($this->studentPickerSearch).'%';
            $studentPickerResults = User::query()
                ->where(function ($q) {
                    $q->whereHas('roles', fn ($rq) => $rq->where('name', 'Student'))
                        ->orWhereHas('enrollments');
                })
                ->where(function ($q) use ($pTerm) {
                    $q->where('name', 'like', $pTerm)
                        ->orWhere('student_id', 'like', $pTerm)
                        ->orWhere('phone', 'like', $pTerm)
                        ->orWhere('email', 'like', $pTerm);
                })
                ->with(['enrollments.course', 'enrollments.batch'])
                ->limit(8)
                ->get();
        }

        // Data for Single Student Mode
        $selectedStudent = null;
        $studentMetrics = null;
        $singleInvoices = collect();
        $singlePayments = collect();
        $singleDeposits = collect();

        if ($this->selectedStudentId) {
            $selectedStudent = User::with([
                'enrollments.course',
                'enrollments.batch',
                'enrollments.section',
                'studentProfile',
            ])->find($this->selectedStudentId);

            if ($selectedStudent) {
                $stDebits = (float) StudentLedger::where('user_id', $selectedStudent->id)->where('entry_type', StudentLedger::ENTRY_DEBIT)->sum('amount');
                $stCredits = (float) StudentLedger::where('user_id', $selectedStudent->id)->where('entry_type', StudentLedger::ENTRY_CREDIT)->sum('amount');
                $stBalance = (float) (StudentLedger::where('user_id', $selectedStudent->id)->latest('id')->value('balance_after') ?? ($stDebits - $stCredits));
                $stHeldDeposit = $selectedStudent->totalHeldLibraryDepositAmount();

                $invStats = StudentFeeInvoice::where('user_id', $selectedStudent->id)->selectRaw("
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN status IN ('pending', 'partial', 'overdue') THEN 1 ELSE 0 END) as pending_count,
                    SUM(total_amount) as total_invoiced,
                    SUM(paid_amount) as total_paid,
                    SUM(balance_amount) as total_due
                ")->first();

                $studentMetrics = [
                    'totalDebits' => $stDebits,
                    'totalCredits' => $stCredits,
                    'netBalance' => $stBalance,
                    'heldDeposit' => $stHeldDeposit,
                    'invoicesTotal' => (int) ($invStats->total_count ?? 0),
                    'invoicesPaid' => (int) ($invStats->paid_count ?? 0),
                    'invoicesPending' => (int) ($invStats->pending_count ?? 0),
                    'invoicesDueAmount' => (float) ($invStats->total_due ?? 0.00),
                ];

                if ($this->singleTab === 'invoices') {
                    $singleInvoices = StudentFeeInvoice::where('user_id', $selectedStudent->id)
                        ->with('feeType')
                        ->orderByDesc('id')
                        ->get();
                } elseif ($this->singleTab === 'payments') {
                    $singlePayments = PaymentTransaction::where('user_id', $selectedStudent->id)
                        ->with(['feeType', 'invoice'])
                        ->orderByDesc('id')
                        ->get();
                } elseif ($this->singleTab === 'deposits') {
                    $singleDeposits = $selectedStudent->libraryDeposits()
                        ->latest('deposit_date')
                        ->latest('id')
                        ->get();
                }
            }
        }

        // Data for Cohort / Multi-Student Mode
        $cohortStudents = null;
        $cohortMetrics = null;

        if ($this->viewMode === 'cohort') {
            $baseLedger = StudentLedger::query();
            if ($this->batchFilter !== 'all' || $this->courseFilter !== 'all' || $this->sectionFilter !== 'all') {
                $baseLedger->whereHas('user.enrollments', function (Builder $eq) {
                    if ($this->batchFilter !== 'all') {
                        $eq->where('batch_id', $this->batchFilter);
                    }
                    if ($this->courseFilter !== 'all') {
                        $eq->where('course_id', $this->courseFilter);
                    }
                    if ($this->sectionFilter !== 'all') {
                        $eq->where('section_id', $this->sectionFilter);
                    }
                });
            }

            $cohortDebits = (float) (clone $baseLedger)->where('entry_type', StudentLedger::ENTRY_DEBIT)->sum('amount');
            $cohortCredits = (float) (clone $baseLedger)->where('entry_type', StudentLedger::ENTRY_CREDIT)->sum('amount');
            $cohortOutstanding = max(0.00, $cohortDebits - $cohortCredits);

            $cohortMetrics = [
                'totalDebits' => $cohortDebits,
                'totalCredits' => $cohortCredits,
                'outstanding' => $cohortOutstanding,
            ];

            if ($this->cohortTab === 'students') {
                $cohortStudents = $this->getStudentsRegisterQuery()->paginate(15);
            }
        }

        // Paginated ledger records (used for single-tab ledger or cohort-tab ledger)
        $ledgerRecords = null;
        if ($this->viewMode === 'single' && $this->singleTab === 'ledger') {
            $ledgerRecords = $this->getLedgerQuery()->paginate(20);
        } elseif ($this->viewMode === 'cohort' && $this->cohortTab === 'ledger') {
            $ledgerRecords = $this->getLedgerQuery()->paginate(20);
        }

        return [
            'batches' => $batches,
            'courses' => $courses,
            'sections' => $sections,
            'feeTypes' => $feeTypes,
            'studentPickerResults' => $studentPickerResults,
            'selectedStudent' => $selectedStudent,
            'studentMetrics' => $studentMetrics,
            'singleInvoices' => $singleInvoices,
            'singlePayments' => $singlePayments,
            'singleDeposits' => $singleDeposits,
            'cohortMetrics' => $cohortMetrics,
            'cohortStudents' => $cohortStudents,
            'ledgerRecords' => $ledgerRecords,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Student Financial Ledger & Statements') }}</h1>
                <span class="inline-flex items-center rounded-md bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary border border-primary/20">
                    {{ $viewMode === 'single' ? __('Student Statement Mode') : __('Cohort Register Mode') }}
                </span>
            </div>
            <p class="text-xs text-muted-foreground mt-1">
                {{ __('Comprehensive financial history, double-entry ledger audits, fee head breakdown, running balance passbook, and multi-format statements.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Mode Switcher --}}
            <div class="flex items-center rounded-lg border border-border bg-card p-0.5 shadow-xs">
                <button
                    wire:click="$set('viewMode', 'cohort')"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $viewMode === 'cohort' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="users" class="h-3.5 w-3.5 inline mr-1"/>
                    {{ __('Cohort / All Students') }}
                </button>
                <button
                    wire:click="$set('viewMode', 'single')"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $viewMode === 'single' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="user" class="h-3.5 w-3.5 inline mr-1"/>
                    {{ __('Single Student Statement') }}
                </button>
            </div>

            {{-- Export Group --}}
            <div class="flex items-center rounded-lg border border-border bg-card p-0.5 shadow-xs">
                <button wire:click="export('xlsx')" class="px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted rounded-md transition-colors" title="Export Excel (.xlsx)">
                    <x-icon name="file-spreadsheet" class="h-3.5 w-3.5 inline mr-1 text-emerald-600"/>
                    Excel
                </button>
                <button wire:click="export('csv')" class="px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted rounded-md transition-colors" title="Export CSV (.csv)">
                    <x-icon name="file-text" class="h-3.5 w-3.5 inline mr-1 text-blue-600"/>
                    CSV
                </button>
                <button wire:click="export('pdf')" class="px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted rounded-md transition-colors" title="Export Statement PDF">
                    <x-icon name="download" class="h-3.5 w-3.5 inline mr-1 text-rose-600"/>
                    PDF
                </button>
            </div>

            @if($selectedStudentId)
                <button
                    wire:click="clearStudentSelection"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-card hover:bg-muted text-foreground transition-colors"
                    title="{{ __('Back to Cohort') }}"
                >
                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5 inline mr-1 text-muted-foreground"/>
                    {{ __('Reset Student') }}
                </button>
            @endif
        </div>
    </div>

    {{-- Single Student Info & Banner (When in single mode) --}}
    @if($viewMode === 'single')
        @if($selectedStudent)
            <div class="rounded-xl border border-primary/20 bg-primary/5 p-4 shadow-xs">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 rounded-full bg-primary/20 text-primary flex items-center justify-center font-bold text-base border border-primary/30 shrink-0">
                            {{ substr($selectedStudent->name, 0, 2) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-base font-bold text-foreground">{{ $selectedStudent->name }}</h2>
                                <span class="font-mono text-xs font-semibold px-2 py-0.5 rounded-full bg-background border border-border text-primary">
                                    ID: {{ $selectedStudent->student_id ?: 'N/A' }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground mt-1">
                                <span><strong>{{ __('Course:') }}</strong> {{ $selectedStudent->enrollments->first()?->course?->name ?: __('Not Enrolled') }}</span>
                                <span><strong>{{ __('Batch:') }}</strong> {{ $selectedStudent->enrollments->first()?->batch?->name ?: 'N/A' }}</span>
                                @if($selectedStudent->enrollments->first()?->section)
                                    <span><strong>{{ __('Section:') }}</strong> {{ $selectedStudent->enrollments->first()->section->name }}</span>
                                @endif
                                <span><strong>{{ __('Phone:') }}</strong> {{ $selectedStudent->phone ?: 'N/A' }}</span>
                                <span><strong>{{ __('Email:') }}</strong> {{ $selectedStudent->email }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 self-start md:self-auto">
                        <a
                            href="{{ route('admin.payments.fees', ['student' => $selectedStudent->id]) }}"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs"
                        >
                            <x-icon name="receipt" class="h-3.5 w-3.5 mr-1.5"/>
                            {{ __('Collect / Invoice Fee') }}
                        </a>
                        <button
                            wire:click="clearStudentSelection"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg border border-border bg-background hover:bg-muted text-foreground transition-colors"
                        >
                            <x-icon name="users" class="h-3.5 w-3.5 mr-1.5 text-muted-foreground"/>
                            {{ __('Switch Student') }}
                        </button>
                    </div>
                </div>
            </div>
        @else
            {{-- Search & Pick Student Banner --}}
            <div class="rounded-xl border border-dashed border-border bg-card p-6 text-center space-y-3">
                <div class="mx-auto h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                    <x-icon name="search" class="h-6 w-6"/>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Select a Student to View Financial Dossier') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5 max-w-md mx-auto">
                        {{ __('Search by student name, lifetime 9-digit student ID, phone, or email to pull up their complete double-entry statement.') }}
                    </p>
                </div>
                <div class="max-w-md mx-auto relative">
                    <x-ui.input
                        wire:model.live.debounce.300ms="studentPickerSearch"
                        placeholder="{{ __('Type student name or ID (e.g., 202600001)...') }}"
                        autofocus
                    />
                    @if(!empty($studentPickerResults))
                        <div class="absolute left-0 right-0 top-full mt-1.5 z-50 rounded-xl border border-border bg-card shadow-lg divide-y divide-border max-h-64 overflow-y-auto text-left">
                            @foreach($studentPickerResults as $st)
                                <button
                                    type="button"
                                    wire:click="selectStudent({{ $st->id }})"
                                    class="w-full px-4 py-2.5 hover:bg-muted/50 transition-colors flex items-center justify-between text-xs cursor-pointer"
                                >
                                    <div>
                                        <div class="font-semibold text-foreground">{{ $st->name }}</div>
                                        <div class="text-[11px] text-muted-foreground font-mono">
                                            ID: {{ $st->student_id ?: 'N/A' }} &bull; {{ $st->enrollments->first()?->course?->name ?: __('General') }}
                                        </div>
                                    </div>
                                    <span class="text-primary font-medium text-[11px]">{{ __('View Statement &rarr;') }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif

    {{-- Metric Statistics Cards --}}
    @if($viewMode === 'single' && $selectedStudent && $studentMetrics)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- Total Invoiced / Debits --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Demanded (Debits)') }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                        <x-icon name="arrow-up-right" class="h-4 w-4"/>
                    </div>
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-amber-600">₹{{ number_format($studentMetrics['totalDebits'], 2) }}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Total institutional fee liabilities issued') }}</p>
            </div>

            {{-- Total Paid / Credits --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Settled (Credits)') }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                        <x-icon name="arrow-down-left" class="h-4 w-4"/>
                    </div>
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-emerald-600">₹{{ number_format($studentMetrics['totalCredits'], 2) }}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Cleared funds, waivers & security credits') }}</p>
            </div>

            {{-- Net Outstanding Balance --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Net Balance Liability') }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg {{ $studentMetrics['netBalance'] > 0 ? 'bg-rose-500/10 text-rose-600' : 'bg-emerald-500/10 text-emerald-600' }}">
                        <x-icon name="scale" class="h-4 w-4"/>
                    </div>
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight {{ $studentMetrics['netBalance'] > 0 ? 'text-rose-600' : ($studentMetrics['netBalance'] < 0 ? 'text-blue-600' : 'text-emerald-600') }}">
                    ₹{{ number_format(abs($studentMetrics['netBalance']), 2) }}
                    <span class="text-xs font-normal">
                        @if($studentMetrics['netBalance'] > 0)
                            <span class="text-rose-600 font-semibold">{{ __('(Due)') }}</span>
                        @elseif($studentMetrics['netBalance'] < 0)
                            <span class="text-blue-600 font-semibold">{{ __('(Advance)') }}</span>
                        @else
                            <span class="text-emerald-600 font-semibold">{{ __('(Cleared)') }}</span>
                        @endif
                    </span>
                </div>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Current net double-entry account standing') }}</p>
            </div>

            {{-- Library Collateral & Invoices --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Active Library Deposit') }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600">
                        <x-icon name="book" class="h-4 w-4"/>
                    </div>
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-blue-600">₹{{ number_format($studentMetrics['heldDeposit'], 2) }}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">
                    {{ __(':paid of :tot Invoices Paid', ['paid' => $studentMetrics['invoicesPaid'], 'tot' => $studentMetrics['invoicesTotal']]) }}
                </p>
            </div>
        </div>
    @elseif($viewMode === 'cohort' && $cohortMetrics)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Cohort Demanded (Debits)') }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                        <x-icon name="arrow-up-right" class="h-4 w-4"/>
                    </div>
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-amber-600">₹{{ number_format($cohortMetrics['totalDebits'], 2) }}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Cumulative student liabilities across filter criteria') }}</p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Cohort Cleared (Credits)') }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                        <x-icon name="arrow-down-left" class="h-4 w-4"/>
                    </div>
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-emerald-600">₹{{ number_format($cohortMetrics['totalCredits'], 2) }}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Payments, waivers & security deposits collected') }}</p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Outstanding Receivables') }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/10 text-rose-600">
                        <x-icon name="scale" class="h-4 w-4"/>
                    </div>
                </div>
                <div class="mt-2 text-2xl font-bold tracking-tight text-rose-600">₹{{ number_format($cohortMetrics['outstanding'], 2) }}</div>
                <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Net uncollected balance liability for selected cohort') }}</p>
            </div>
        </div>
    @endif

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            {{-- Search Bar --}}
            <div class="lg:col-span-2">
                <x-ui.input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search student name, ID, phone, ledger # or description...') }}"
                />
            </div>

            {{-- Batch Filter --}}
            <div>
                <select wire:model.live="batchFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Batches') }}</option>
                    @foreach($batches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Course Filter --}}
            <div>
                <select wire:model.live="courseFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Courses') }}</option>
                    @foreach($courses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                    @endforeach
                </select>
            </div>

            {{-- Section Filter --}}
            <div>
                <select wire:model.live="sectionFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Sections') }}</option>
                    @foreach($sections as $sec)
                        <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Fee Type Filter --}}
            <div>
                <select wire:model.live="feeTypeFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Fee Heads') }}</option>
                    @foreach($feeTypes as $ft)
                        <option value="{{ $ft->id }}">{{ $ft->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Secondary Filter Row: Entry type, Ref type, Date range, Reset --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 pt-2 border-t border-border/60">
            {{-- Entry Type Filter --}}
            <div>
                <select wire:model.live="entryTypeFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Entries (Debit & Credit)') }}</option>
                    <option value="debit">{{ __('Debit Entries (Payable Liability)') }}</option>
                    <option value="credit">{{ __('Credit Entries (Payment Received)') }}</option>
                </select>
            </div>

            {{-- Reference Type Filter --}}
            <div>
                <select wire:model.live="refTypeFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="all">{{ __('All Reference Types') }}</option>
                    <option value="invoice">{{ __('Fee Invoices') }}</option>
                    <option value="payment">{{ __('Payment Receipts') }}</option>
                    <option value="deposit">{{ __('Security Deposits') }}</option>
                    <option value="refund">{{ __('Refund Payouts') }}</option>
                    <option value="waiver">{{ __('Waivers & Concessions') }}</option>
                    <option value="adjustment">{{ __('Adjustments') }}</option>
                </select>
            </div>

            {{-- Balance Standing (When in cohort mode) --}}
            @if($viewMode === 'cohort')
                <div>
                    <select wire:model.live="balanceFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                        <option value="all">{{ __('All Balances') }}</option>
                        <option value="dues">{{ __('Has Dues Outstanding (> ₹0)') }}</option>
                        <option value="cleared">{{ __('Settled / Cleared (₹0)') }}</option>
                        <option value="advance">{{ __('Surplus / Advance (< ₹0)') }}</option>
                    </select>
                </div>
            @else
                <div>
                    <select wire:model.live="sortBy" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                        <option value="transaction_date">{{ __('Sort by Date') }}</option>
                        <option value="amount">{{ __('Sort by Amount') }}</option>
                        <option value="balance_after">{{ __('Sort by Running Balance') }}</option>
                        <option value="ledger_no">{{ __('Sort by Ledger #') }}</option>
                    </select>
                </div>
            @endif

            {{-- From Date --}}
            <div>
                <input
                    type="date"
                    wire:model.live="fromDate"
                    class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs text-foreground"
                    placeholder="From Date"
                    title="{{ __('From Transaction Date') }}"
                />
            </div>

            {{-- To Date --}}
            <div>
                <input
                    type="date"
                    wire:model.live="toDate"
                    class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs text-foreground"
                    placeholder="To Date"
                    title="{{ __('To Transaction Date') }}"
                />
            </div>

            {{-- Clear Filters --}}
            <div class="flex items-center justify-end">
                <button
                    wire:click="resetFilters"
                    class="w-full h-9 inline-flex items-center justify-center px-3 text-xs font-medium rounded-lg border border-border bg-background hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
                >
                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Reset Filters') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Main Presentation Body --}}
    @if($viewMode === 'single' && $selectedStudent)
        {{-- Single Student Dossier Tabs --}}
        <div class="space-y-4">
            <div class="border-b border-border">
                <nav class="flex space-x-6 text-xs font-semibold">
                    <button
                        wire:click="$set('singleTab', 'ledger')"
                        class="pb-3 border-b-2 transition-colors cursor-pointer {{ $singleTab === 'ledger' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="book-marked" class="h-4 w-4 inline mr-1.5"/>
                        {{ __('Statement of Account (Passbook)') }}
                    </button>

                    <button
                        wire:click="$set('singleTab', 'invoices')"
                        class="pb-3 border-b-2 transition-colors cursor-pointer {{ $singleTab === 'invoices' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="receipt" class="h-4 w-4 inline mr-1.5"/>
                        {{ __('Invoices & Dues') }} ({{ $studentMetrics['invoicesTotal'] }})
                    </button>

                    <button
                        wire:click="$set('singleTab', 'payments')"
                        class="pb-3 border-b-2 transition-colors cursor-pointer {{ $singleTab === 'payments' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="banknote" class="h-4 w-4 inline mr-1.5"/>
                        {{ __('Receipts & Transactions') }}
                    </button>

                    <button
                        wire:click="$set('singleTab', 'deposits')"
                        class="pb-3 border-b-2 transition-colors cursor-pointer {{ $singleTab === 'deposits' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="book" class="h-4 w-4 inline mr-1.5"/>
                        {{ __('Library Deposits') }}
                    </button>
                </nav>
            </div>

            {{-- 1. Passbook / Chronological Ledger Tab --}}
            @if($singleTab === 'ledger')
                <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Date') }}</th>
                                    <th class="px-4 py-3">{{ __('Ledger #') }}</th>
                                    <th class="px-4 py-3">{{ __('Particulars / Description') }}</th>
                                    <th class="px-4 py-3">{{ __('Ref Type') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Debit (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Credit (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Running Balance (₹)') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($ledgerRecords as $entry)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-3 font-medium text-foreground whitespace-nowrap">
                                            {{ $entry->transaction_date ? $entry->transaction_date->format('d M Y') : 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">
                                            {{ $entry->ledger_no }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-foreground font-medium">{{ $entry->description }}</div>
                                            @if($entry->reference_type === 'invoice' && $entry->reference_id)
                                                <div class="text-[11px] text-muted-foreground font-mono">Invoice Ref #{{ $entry->reference_id }}</div>
                                            @elseif($entry->reference_type === 'payment' && $entry->reference_id)
                                                <div class="text-[11px] text-muted-foreground font-mono">Transaction Ref #{{ $entry->reference_id }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($entry->entry_type === 'debit')
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                                    {{ __('DEBIT') }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                    {{ __('CREDIT') }}
                                                </span>
                                            @endif
                                            <span class="text-[11px] text-muted-foreground ml-1 font-mono uppercase">{{ $entry->reference_type }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap {{ $entry->entry_type === 'debit' ? 'text-amber-600 font-semibold' : 'text-muted-foreground' }}">
                                            {{ $entry->entry_type === 'debit' ? '₹'.number_format($entry->amount, 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap {{ $entry->entry_type === 'credit' ? 'text-emerald-600 font-semibold' : 'text-muted-foreground' }}">
                                            {{ $entry->entry_type === 'credit' ? '₹'.number_format($entry->amount, 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold whitespace-nowrap {{ $entry->balance_after > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                            ₹{{ number_format($entry->balance_after, 2) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                            <x-icon name="book-open" class="h-8 w-8 mx-auto text-muted-foreground/50 mb-2"/>
                                            <p class="font-medium">{{ __('No ledger entries found matching the filter criteria.') }}</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($ledgerRecords && $ledgerRecords->hasPages())
                        <div class="p-4 border-t border-border">
                            {{ $ledgerRecords->links() }}
                        </div>
                    @endif
                </div>

            {{-- 2. Invoices & Dues Tab --}}
            @elseif($singleTab === 'invoices')
                <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Invoice #') }}</th>
                                    <th class="px-4 py-3">{{ __('Fee Head') }}</th>
                                    <th class="px-4 py-3">{{ __('Period') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Payable (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Paid (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Balance (₹)') }}</th>
                                    <th class="px-4 py-3">{{ __('Due Date') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($singleInvoices as $inv)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">
                                            {{ $inv->invoice_no }}
                                        </td>
                                        <td class="px-4 py-3 font-medium text-foreground whitespace-nowrap">
                                            {{ $inv->title }}
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {{ $inv->month_name ? $inv->month_name.' '.$inv->period_year : ($inv->period_year ?: 'N/A') }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-foreground whitespace-nowrap">
                                            ₹{{ number_format($inv->total_amount, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-emerald-600 whitespace-nowrap">
                                            ₹{{ number_format($inv->paid_amount, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold whitespace-nowrap {{ $inv->balance_amount > 0 ? 'text-rose-600' : 'text-muted-foreground' }}">
                                            ₹{{ number_format($inv->balance_amount, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {{ $inv->due_date ? $inv->due_date->format('d M Y') : 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($inv->status === 'paid')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">{{ __('Paid') }}</span>
                                            @elseif($inv->status === 'partial')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">{{ __('Partial') }}</span>
                                            @elseif($inv->status === 'overdue')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20">{{ __('Overdue') }}</span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-secondary text-muted-foreground border border-border">{{ __('Pending') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            @if($inv->status === 'paid' || $inv->paid_amount > 0)
                                                @php $tx = $inv->transactions()->where('status', 'successful')->latest('id')->first(); @endphp
                                                @if($tx)
                                                    <a href="{{ route('payments.receipt.download', $tx->id) }}" class="text-xs text-primary hover:underline font-medium">
                                                        {{ __('Receipt PDF') }}
                                                    </a>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                            {{ __('No invoices issued to this student.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            {{-- 3. Receipts & Transactions Tab --}}
            @elseif($singleTab === 'payments')
                <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Transaction #') }}</th>
                                    <th class="px-4 py-3">{{ __('Receipt #') }}</th>
                                    <th class="px-4 py-3">{{ __('Date & Time') }}</th>
                                    <th class="px-4 py-3">{{ __('Method / Gateway') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Amount (₹)') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Receipt') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y border-border">
                                @forelse($singlePayments as $pt)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-3 font-mono font-medium text-foreground whitespace-nowrap">
                                            {{ $pt->transaction_no }}
                                        </td>
                                        <td class="px-4 py-3 font-mono font-medium text-emerald-600 whitespace-nowrap">
                                            {{ $pt->receipt_no ?: 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {{ $pt->created_at->format('d M Y, h:i A') }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="font-medium text-foreground">{{ strtoupper(str_replace('_', ' ', $pt->payment_method)) }}</span>
                                            <span class="text-[11px] text-muted-foreground font-mono">({{ ucfirst($pt->gateway) }})</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold text-emerald-600 whitespace-nowrap">
                                            ₹{{ number_format($pt->amount, 2) }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($pt->status === 'successful')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">{{ __('Successful') }}</span>
                                            @elseif($pt->status === 'under_review')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">{{ __('Under Review') }}</span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-secondary text-muted-foreground">{{ ucfirst($pt->status) }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            @if($pt->receipt_no)
                                                <a href="{{ route('payments.receipt.download', $pt->id) }}" class="p-1 rounded text-emerald-600 hover:bg-emerald-500/10 transition-colors inline-block" title="Download E-Receipt PDF">
                                                    <x-icon name="download" class="h-4 w-4"/>
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                            {{ __('No payment transactions recorded for this student.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            {{-- 4. Library Deposits Tab --}}
            @elseif($singleTab === 'deposits')
                <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Deposit #') }}</th>
                                    <th class="px-4 py-3">{{ __('Deposit Date') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Amount (₹)') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3">{{ __('Refund Requested Date') }}</th>
                                    <th class="px-4 py-3">{{ __('Refund Ref / UTR') }}</th>
                                    <th class="px-4 py-3">{{ __('Disbursed Date') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($singleDeposits as $dep)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">
                                            {{ $dep->deposit_no }}
                                        </td>
                                        <td class="px-4 py-3 text-foreground whitespace-nowrap">
                                            {{ $dep->deposit_date->format('d M Y') }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold text-foreground whitespace-nowrap">
                                            ₹{{ number_format($dep->amount, 2) }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($dep->status === 'active')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">{{ __('Active Collateral') }}</span>
                                            @elseif($dep->status === 'refund_requested')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">{{ __('Refund Pending') }}</span>
                                            @elseif($dep->status === 'refund_processed')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/10 text-blue-600 border border-blue-500/20">{{ __('Disbursed') }}</span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-secondary text-muted-foreground">{{ ucfirst($dep->status) }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {{ $dep->refund_requested_at ? $dep->refund_requested_at->format('d M Y') : '—' }}
                                        </td>
                                        <td class="px-4 py-3 font-mono text-muted-foreground whitespace-nowrap">
                                            {{ $dep->refund_transaction_ref ?: '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {{ $dep->refund_processed_at ? $dep->refund_processed_at->format('d M Y') : '—' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                            {{ __('No library security deposits recorded for this student.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

    {{-- Cohort / Multi-Student Presentation Mode --}}
    @elseif($viewMode === 'cohort')
        <div class="space-y-4">
            {{-- Cohort Sub-tabs --}}
            <div class="flex items-center justify-between border-b border-border pb-2">
                <div class="flex items-center gap-4 text-xs font-semibold">
                    <button
                        wire:click="$set('cohortTab', 'students')"
                        class="pb-2 border-b-2 transition-colors cursor-pointer {{ $cohortTab === 'students' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="users" class="h-3.5 w-3.5 inline mr-1"/>
                        {{ __('Students Financial Summary Register') }}
                    </button>

                    <button
                        wire:click="$set('cohortTab', 'ledger')"
                        class="pb-2 border-b-2 transition-colors cursor-pointer {{ $cohortTab === 'ledger' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="book-marked" class="h-3.5 w-3.5 inline mr-1"/>
                        {{ __('Consolidated Double-Entry Ledger') }}
                    </button>
                </div>

                <div class="text-xs text-muted-foreground">
                    @if($cohortTab === 'students' && $cohortStudents)
                        {{ __('Showing :count students', ['count' => $cohortStudents->total()]) }}
                    @endif
                </div>
            </div>

            {{-- 1. Cohort Students Summary Table --}}
            @if($cohortTab === 'students' && $cohortStudents)
                <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Student') }}</th>
                                    <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Total Invoiced (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Total Paid (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Balance Liability (₹)') }}</th>
                                    <th class="px-4 py-3">{{ __('Financial Status') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($cohortStudents as $st)
                                    @php
                                        $enr = $st->enrollments->first();
                                        $stDebits = (float) StudentLedger::where('user_id', $st->id)->where('entry_type', StudentLedger::ENTRY_DEBIT)->sum('amount');
                                        $stCredits = (float) StudentLedger::where('user_id', $st->id)->where('entry_type', StudentLedger::ENTRY_CREDIT)->sum('amount');
                                        $stBal = (float) (StudentLedger::where('user_id', $st->id)->latest('id')->value('balance_after') ?? ($stDebits - $stCredits));
                                    @endphp
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="font-semibold text-foreground">{{ $st->name }}</div>
                                            <div class="text-[11px] text-muted-foreground font-mono">
                                                ID: {{ $st->student_id ?: 'N/A' }}
                                                @if($st->phone) &bull; {{ $st->phone }} @endif
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-foreground">{{ $enr?->course?->name ?: __('General') }}</div>
                                            <div class="text-[11px] text-muted-foreground">
                                                {{ $enr?->batch?->name ?: 'N/A' }}
                                                @if($enr?->section) &bull; {{ $enr->section->name }} @endif
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-amber-600 whitespace-nowrap">
                                            ₹{{ number_format($stDebits, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-emerald-600 whitespace-nowrap">
                                            ₹{{ number_format($stCredits, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold whitespace-nowrap {{ $stBal > 0 ? 'text-rose-600' : ($stBal < 0 ? 'text-blue-600' : 'text-emerald-600') }}">
                                            ₹{{ number_format(abs($stBal), 2) }}
                                            @if($stBal > 0)
                                                <span class="text-[10px] block font-normal text-rose-600">{{ __('Due') }}</span>
                                            @elseif($stBal < 0)
                                                <span class="text-[10px] block font-normal text-blue-600">{{ __('Advance') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($stBal > 0)
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20">
                                                    {{ __('Dues Pending') }}
                                                </span>
                                            @elseif($stBal < 0)
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/10 text-blue-600 border border-blue-500/20">
                                                    {{ __('Surplus Credit') }}
                                                </span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                    {{ __('Settled / Cleared') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-2">
                                                <button
                                                    wire:click="selectStudent({{ $st->id }})"
                                                    class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-primary/10 text-primary hover:bg-primary/20 border border-primary/20 transition-colors"
                                                    title="{{ __('View Individual Financial Statement') }}"
                                                >
                                                    <x-icon name="book-marked" class="h-3.5 w-3.5 mr-1"/>
                                                    {{ __('View Statement') }}
                                                </button>
                                                <a
                                                    href="{{ route('admin.payments.fees', ['student' => $st->id]) }}"
                                                    class="inline-flex items-center p-1 text-muted-foreground hover:text-foreground transition-colors"
                                                    title="{{ __('Fee Operations') }}"
                                                >
                                                    <x-icon name="receipt" class="h-4 w-4"/>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                            <x-icon name="users" class="h-8 w-8 mx-auto text-muted-foreground/50 mb-2"/>
                                            <p class="font-medium">{{ __('No students found matching the selected cohort and filters.') }}</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($cohortStudents && $cohortStudents->hasPages())
                        <div class="p-4 border-t border-border">
                            {{ $cohortStudents->links() }}
                        </div>
                    @endif
                </div>

            {{-- 2. Consolidated Cohort Ledger Entries --}}
            @elseif($cohortTab === 'ledger' && $ledgerRecords)
                <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                                <tr>
                                    <th class="px-4 py-3">{{ __('Date') }}</th>
                                    <th class="px-4 py-3">{{ __('Ledger #') }}</th>
                                    <th class="px-4 py-3">{{ __('Student') }}</th>
                                    <th class="px-4 py-3">{{ __('Particulars / Description') }}</th>
                                    <th class="px-4 py-3">{{ __('Type') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Debit (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Credit (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Running Balance (₹)') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y border-border">
                                @forelse($ledgerRecords as $entry)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-3 font-medium text-foreground whitespace-nowrap">
                                            {{ $entry->transaction_date ? $entry->transaction_date->format('d M Y') : 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">
                                            {{ $entry->ledger_no }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="font-semibold text-foreground">{{ $entry->user?->name ?: __('Deleted Student') }}</div>
                                            <div class="text-[11px] text-muted-foreground font-mono">
                                                ID: {{ $entry->student_id ?: $entry->user?->student_id ?: 'N/A' }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-foreground font-medium">{{ $entry->description }}</div>
                                            <div class="text-[11px] text-muted-foreground font-mono">
                                                {{ ucfirst($entry->reference_type) }} #{{ $entry->reference_id ?? 'N/A' }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($entry->entry_type === 'debit')
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                                    {{ __('DEBIT') }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                    {{ __('CREDIT') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap {{ $entry->entry_type === 'debit' ? 'text-amber-600 font-semibold' : 'text-muted-foreground' }}">
                                            {{ $entry->entry_type === 'debit' ? '₹'.number_format($entry->amount, 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap {{ $entry->entry_type === 'credit' ? 'text-emerald-600 font-semibold' : 'text-muted-foreground' }}">
                                            {{ $entry->entry_type === 'credit' ? '₹'.number_format($entry->amount, 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold whitespace-nowrap {{ $entry->balance_after > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                            ₹{{ number_format($entry->balance_after, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <button
                                                wire:click="selectStudent({{ $entry->user_id }})"
                                                class="text-xs text-primary hover:underline font-medium"
                                                title="{{ __('View this student full ledger') }}"
                                            >
                                                {{ __('Full Dossier &rarr;') }}
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                            {{ __('No consolidated ledger entries found matching the filter criteria.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($ledgerRecords && $ledgerRecords->hasPages())
                        <div class="p-4 border-t border-border">
                            {{ $ledgerRecords->links() }}
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
