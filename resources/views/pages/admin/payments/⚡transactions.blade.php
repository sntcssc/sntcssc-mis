<?php

use App\Models\PaymentTransaction;
use App\Models\StudentLedger;
use App\Services\Payment\FeePaymentService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Financial Transactions & Ledger')] class extends Component {
    use WithPagination;

    public string $viewMode = 'transactions'; // 'transactions' or 'ledger'
    public string $search = '';
    public string $statusFilter = 'all'; // 'all', 'successful', 'pending', 'under_review', 'failed', 'refunded', 'amount_mismatch'
    public string $gatewayFilter = 'all'; // 'all', 'ccavenue', 'razorpay', 'phonepe', 'offline'
    public string $entryType = 'all'; // 'all', 'debit', 'credit'
    public string $refType = 'all';

    // Detail Modal
    public ?PaymentTransaction $viewingTransaction = null;

    // Status Update Modal
    public ?int $updatingTransactionId = null;
    public string $updateStatus = 'successful';
    public string $updateTrackingId = '';
    public string $updateBankRefNo = '';
    public string $updatePaymentMethod = 'online';
    public string $updateAdminRemarks = '';

    // Refund Modal
    public ?int $refundingTransactionId = null;
    public float $refundAmount = 0.00;
    public float $maxRefundable = 0.00;
    public string $refundReason = 'Duplicate payment made unintentionally by student.';
    public string $refundRefNo = '';
    public string $refundMode = 'online_api'; // 'online_api' or 'manual'

    // Live Sync & Status Tracker Modal
    public ?PaymentTransaction $syncingTransaction = null;
    public ?array $syncDiagnosticResult = null;
    public bool $isSyncingTracker = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingViewMode(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingGatewayFilter(): void
    {
        $this->resetPage();
    }

    public function viewDetails(int $transactionId): void
    {
        $this->viewingTransaction = PaymentTransaction::with(['user.studentProfile', 'invoice.feeType', 'verifier', 'refundedBy'])
            ->findOrFail($transactionId);

        $this->dispatch('modal-open', name: 'transaction-detail-modal');
    }

    public function openUpdateModal(int $transactionId): void
    {
        $txn = PaymentTransaction::findOrFail($transactionId);
        $this->updatingTransactionId = $txn->id;
        $this->updateStatus = $txn->status === PaymentTransaction::STATUS_SUCCESSFUL ? 'successful' : 'successful';
        $this->updateTrackingId = (string) ($txn->gateway_tracking_id ?: '');
        $this->updateBankRefNo = (string) ($txn->gateway_bank_ref_no ?: '');
        $this->updatePaymentMethod = (string) ($txn->payment_method ?: 'online');
        $this->updateAdminRemarks = (string) ($txn->admin_remarks ?: '');

        $this->dispatch('modal-open', name: 'transaction-update-modal');
    }

    public function saveStatusUpdate(): void
    {
        if (! auth()->user()->can('payments.reconcile') && ! auth()->user()->can('payments.verify') && ! auth()->user()->hasRole('Super Administrator')) {
            abort(403, __('Unauthorized to update transaction status.'));
        }

        $this->validate([
            'updateStatus' => ['required', 'string', 'in:successful,pending,under_review,failed,rejected'],
            'updateTrackingId' => ['nullable', 'string', 'max:100'],
            'updateBankRefNo' => ['nullable', 'string', 'max:100'],
            'updatePaymentMethod' => ['required', 'string', 'max:50'],
            'updateAdminRemarks' => ['required', 'string', 'max:500'],
        ]);

        try {
            $txn = PaymentTransaction::findOrFail($this->updatingTransactionId);

            FeePaymentService::updatePaymentStatus(
                transaction: $txn,
                newStatus: $this->updateStatus,
                details: [
                    'gateway_tracking_id' => $this->updateTrackingId ?: null,
                    'gateway_bank_ref_no' => $this->updateBankRefNo ?: null,
                    'payment_method' => $this->updatePaymentMethod,
                    'admin_remarks' => $this->updateAdminRemarks,
                ],
                updatedBy: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Transaction status updated to :status successfully.', [
                'status' => ucfirst(str_replace('_', ' ', $this->updateStatus)),
            ]));

            $this->dispatch('modal-close', name: 'transaction-update-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Status update failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openRefundModal(int $transactionId): void
    {
        $txn = PaymentTransaction::findOrFail($transactionId);

        if (! $txn->canBeRefunded()) {
            Toast::dispatch($this, 'warning', __('This transaction is not eligible for a refund.'));
            return;
        }

        $this->refundingTransactionId = $txn->id;
        $this->maxRefundable = $txn->getRefundableAmount();
        $this->refundAmount = $this->maxRefundable;
        $this->refundReason = 'Duplicate payment made unintentionally by student.';
        $this->refundRefNo = 'REF-' . date('YmdHis') . '-' . strtoupper(substr(uniqid(), -4));
        $this->refundMode = $txn->gateway === PaymentTransaction::GATEWAY_CCAVENUE ? 'online_api' : 'manual';

        $this->dispatch('modal-open', name: 'transaction-refund-modal');
    }

    public function processRefund(): void
    {
        if (! auth()->user()->can('payments.refund') && ! auth()->user()->hasRole('Super Administrator')) {
            abort(403, __('Unauthorized to process refunds.'));
        }

        $this->validate([
            'refundAmount' => ['required', 'numeric', 'min:1', 'max:' . $this->maxRefundable],
            'refundReason' => ['required', 'string', 'min:5', 'max:500'],
            'refundRefNo' => ['required', 'string', 'max:100'],
            'refundMode' => ['required', 'string', 'in:online_api,manual'],
        ]);

        try {
            $txn = PaymentTransaction::findOrFail($this->refundingTransactionId);

            FeePaymentService::refundPayment(
                transaction: $txn,
                amount: (float) $this->refundAmount,
                reason: $this->refundReason,
                refundRefNo: $this->refundRefNo,
                refundedBy: auth()->id(),
                attemptGatewayApi: ($this->refundMode === 'online_api')
            );

            Toast::dispatch($this, 'success', __('Refund of ₹:amt processed successfully. Student ledger adjusted and notification sent.', [
                'amt' => number_format((float) $this->refundAmount, 2),
            ]));

            $this->dispatch('modal-close', name: 'transaction-refund-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Refund processing failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openSyncModal(int $transactionId): void
    {
        if (! auth()->user()->can('payments.reconcile') && ! auth()->user()->can('payments.verify') && ! auth()->user()->hasRole('Super Administrator')) {
            abort(403, __('Unauthorized to sync transaction status.'));
        }

        $this->syncingTransaction = PaymentTransaction::findOrFail($transactionId);
        $this->syncDiagnosticResult = null;
        $this->isSyncingTracker = false;
        $this->dispatch('open-modal', 'transaction-sync-modal');
    }

    public function executeLiveSyncQuery(): void
    {
        if (! $this->syncingTransaction) {
            return;
        }

        if ($this->syncingTransaction->gateway !== PaymentTransaction::GATEWAY_CCAVENUE) {
            Toast::dispatch($this, 'info', __('Live server sync is only applicable to CCAvenue transactions.'));
            return;
        }

        $this->isSyncingTracker = true;
        $this->syncDiagnosticResult = FeePaymentService::syncWithCCAvenue($this->syncingTransaction);
        $this->syncingTransaction->refresh();

        if ($this->syncDiagnosticResult['success']) {
            Toast::dispatch($this, 'success', $this->syncDiagnosticResult['message']);
        } else {
            Toast::dispatch($this, 'warning', $this->syncDiagnosticResult['message']);
        }

        $this->isSyncingTracker = false;
    }

    public function syncTransaction(int $transactionId): void
    {
        $this->openSyncModal($transactionId);
        $this->executeLiveSyncQuery();
    }

    public function export(string $format = 'xlsx')
    {
        if ($this->viewMode === 'ledger') {
            $query = StudentLedger::with('user')->orderByDesc('id');
            $headings = ['Ledger #', 'Student Name', 'Student ID', 'Entry Type', 'Amount (₹)', 'Running Balance (₹)', 'Reference Type', 'Description', 'Date'];

            return TableExporter::download(
                query: $query,
                format: $format,
                filename: 'student-financial-ledger',
                headings: $headings,
                mapper: fn (StudentLedger $l) => [
                    $l->ledger_no,
                    $l->user?->name,
                    $l->student_id ?: $l->user?->student_id,
                    strtoupper($l->entry_type),
                    number_format($l->amount, 2),
                    number_format($l->balance_after, 2),
                    ucfirst($l->reference_type),
                    $l->description,
                    $l->transaction_date->format('d/m/Y'),
                ],
                title: 'SNTCSSC Student Double-Entry Financial Ledger'
            );
        } else {
            $query = PaymentTransaction::with(['user', 'invoice', 'feeType'])->orderByDesc('id');
            $headings = ['Transaction #', 'Student Name', 'Student ID', 'Gateway', 'Method', 'Base Amount (₹)', 'Charged Amount (₹)', 'Gateway Fee (₹)', 'Status', 'Date', 'Receipt #', 'Refund (₹)'];

            return TableExporter::download(
                query: $query,
                format: $format,
                filename: 'payment-transactions-register',
                headings: $headings,
                mapper: fn (PaymentTransaction $t) => [
                    $t->transaction_no,
                    $t->user?->name,
                    $t->student_id ?: $t->user?->student_id,
                    ucfirst($t->gateway),
                    strtoupper(str_replace('_', ' ', (string) $t->payment_method)),
                    number_format((float) $t->amount, 2),
                    number_format((float) ($t->gateway_charged_amount ?: $t->amount), 2),
                    number_format((float) $t->gateway_fee, 2),
                    ucfirst(str_replace('_', ' ', $t->status)),
                    $t->created_at->format('d/m/Y H:i'),
                    $t->receipt_no ?: 'N/A',
                    number_format((float) $t->refund_amount, 2),
                ],
                title: 'SNTCSSC Payment Transactions Register'
            );
        }
    }

    public function with(): array
    {
        if ($this->viewMode === 'ledger') {
            $records = StudentLedger::query()
                ->with('user.studentProfile')
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.trim($this->search).'%';
                    $query->where(function (Builder $q) use ($term) {
                        $q->where('ledger_no', 'like', $term)
                            ->orWhere('student_id', 'like', $term)
                            ->orWhere('description', 'like', $term)
                            ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $term)->orWhere('student_id', 'like', $term));
                    });
                })
                ->when($this->entryType !== 'all', fn ($q) => $q->where('entry_type', $this->entryType))
                ->when($this->refType !== 'all', fn ($q) => $q->where('reference_type', $this->refType))
                ->orderByDesc('id')
                ->paginate(15);
        } else {
            $records = PaymentTransaction::query()
                ->with(['user.studentProfile', 'invoice', 'feeType', 'refundedBy'])
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.trim($this->search).'%';
                    $query->where(function (Builder $q) use ($term) {
                        $q->where('transaction_no', 'like', $term)
                            ->orWhere('student_id', 'like', $term)
                            ->orWhere('offline_reference_id', 'like', $term)
                            ->orWhere('gateway_tracking_id', 'like', $term)
                            ->orWhere('gateway_bank_ref_no', 'like', $term)
                            ->orWhere('receipt_no', 'like', $term)
                            ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $term)->orWhere('student_id', 'like', $term));
                    });
                })
                ->when($this->statusFilter === 'amount_mismatch', fn ($q) => $q->where('amount_mismatch', true))
                ->when($this->statusFilter !== 'all' && $this->statusFilter !== 'amount_mismatch', fn ($q) => $q->where('status', $this->statusFilter))
                ->when($this->gatewayFilter !== 'all', fn ($q) => $q->where('gateway', $this->gatewayFilter))
                ->orderByDesc('id')
                ->paginate(15);
        }

        $totalDebits = StudentLedger::where('entry_type', 'debit')->sum('amount');
        $totalCredits = StudentLedger::where('entry_type', 'credit')->sum('amount');
        $currentOutstandingLiability = max(0.00, $totalDebits - $totalCredits);

        $totalCollected = PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESSFUL)->sum('amount');
        $totalRefunded = PaymentTransaction::sum('refund_amount');
        $pendingCount = PaymentTransaction::whereIn('status', [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_UNDER_REVIEW])->count();
        $mismatchCount = PaymentTransaction::where('amount_mismatch', true)->count();

        return [
            'records' => $records,
            'totalDebits' => $totalDebits,
            'totalCredits' => $totalCredits,
            'currentOutstandingLiability' => $currentOutstandingLiability,
            'totalCollected' => $totalCollected,
            'totalRefunded' => $totalRefunded,
            'pendingCount' => $pendingCount,
            'mismatchCount' => $mismatchCount,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Financial Transactions & Ledger') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Manage payment gateway collections (CCAVenue, Razorpay, PhonePe), live sync, manual updates, fee reconciliation, and refunds.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <div class="flex items-center rounded-lg border border-border bg-card p-1 shadow-xs">
                <button wire:click="$set('viewMode', 'transactions')" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $viewMode === 'transactions' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="credit-card" class="h-3.5 w-3.5 inline mr-1"/>
                    {{ __('Transactions') }}
                </button>
                <button wire:click="$set('viewMode', 'ledger')" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $viewMode === 'ledger' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="book-marked" class="h-3.5 w-3.5 inline mr-1"/>
                    {{ __('Student Ledger') }}
                </button>
            </div>

            <a href="{{ route('admin.payments.ledger') }}" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-muted text-foreground inline-flex items-center shadow-xs">
                <x-icon name="book-marked" class="h-3.5 w-3.5 mr-1.5 text-primary"/>
                {{ __('Student Ledgers') }}
            </a>

            <button wire:click="export('xlsx')" class="p-2 rounded-lg border border-border bg-card hover:bg-muted text-foreground shadow-xs" title="Export Excel">
                <x-icon name="file-spreadsheet" class="h-4 w-4 text-emerald-600"/>
            </button>
        </div>
    </div>

    {{-- Banking Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Fee Collected') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-icon name="check-circle" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-emerald-600">₹{{ number_format($totalCollected, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Confirmed cleared payments') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Refunded') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/10 text-rose-600">
                    <x-icon name="rotate-ccw" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-rose-600">₹{{ number_format($totalRefunded, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Disbursed refunds & reversals') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Pending & Review') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                    <x-icon name="clock" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-amber-600">{{ number_format($pendingCount) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Awaiting verification or gateway sync') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Amount Mismatches') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-500/10 text-orange-600">
                    <x-icon name="alert-triangle" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-orange-600">{{ number_format($mismatchCount) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Gateway surcharge / discrepancy flagged') }}</p>
        </div>
    </div>

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div class="sm:col-span-2">
                <x-ui.input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search txn #, student name, ID, tracking ID, or receipt #...') }}"
                />
            </div>

            @if($viewMode === 'transactions')
                <div>
                    <select wire:model.live="statusFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                        <option value="all">{{ __('All Statuses') }}</option>
                        <option value="successful">{{ __('Successful') }}</option>
                        <option value="pending">{{ __('Pending (Initiated)') }}</option>
                        <option value="under_review">{{ __('Under Review') }}</option>
                        <option value="amount_mismatch">{{ __('Amount Mismatch Flagged') }}</option>
                        <option value="failed">{{ __('Failed / Aborted') }}</option>
                        <option value="refunded">{{ __('Refunded') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                    </select>
                </div>

                <div>
                    <select wire:model.live="gatewayFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                        <option value="all">{{ __('All Gateways') }}</option>
                        <option value="ccavenue">{{ __('CCAVenue') }}</option>
                        <option value="razorpay">{{ __('Razorpay') }}</option>
                        <option value="phonepe">{{ __('PhonePe') }}</option>
                        <option value="offline">{{ __('Offline / Counter') }}</option>
                    </select>
                </div>
            @else
                <div>
                    <select wire:model.live="entryType" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                        <option value="all">{{ __('All Entries (Debit & Credit)') }}</option>
                        <option value="debit">{{ __('Debit Entries (Liability Added)') }}</option>
                        <option value="credit">{{ __('Credit Entries (Payment Received)') }}</option>
                    </select>
                </div>

                <div>
                    <select wire:model.live="refType" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                        <option value="all">{{ __('All Reference Types') }}</option>
                        <option value="invoice">{{ __('Invoice Dues') }}</option>
                        <option value="payment">{{ __('Fee Payments') }}</option>
                        <option value="deposit">{{ __('Security Deposits') }}</option>
                        <option value="refund">{{ __('Refund Payouts') }}</option>
                        <option value="waiver">{{ __('Waivers / Concessions') }}</option>
                    </select>
                </div>
            @endif
        </div>
    </div>

    {{-- Main Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            @if($viewMode === 'ledger')
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                        <tr>
                            <th class="px-4 py-3">{{ __('Ledger #') }}</th>
                            <th class="px-4 py-3">{{ __('Student') }}</th>
                            <th class="px-4 py-3">{{ __('Date') }}</th>
                            <th class="px-4 py-3">{{ __('Type') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Debit (₹)') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Credit (₹)') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Running Balance') }}</th>
                            <th class="px-4 py-3">{{ __('Particulars / Description') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($records as $rec)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3 font-mono font-medium text-primary whitespace-nowrap">
                                    {{ $rec->ledger_no }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-foreground">{{ $rec->user?->name ?: __('Deleted Student') }}</div>
                                    <div class="text-[11px] text-muted-foreground font-mono">{{ $rec->student_id ?: $rec->user?->student_id ?: 'ID: N/A' }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ $rec->transaction_date->format('d M Y') }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if($rec->entry_type === 'debit')
                                        <x-ui.badge variant="warning">{{ __('DEBIT') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="success">{{ __('CREDIT') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-amber-600 whitespace-nowrap">
                                    {{ $rec->entry_type === 'debit' ? '₹' . number_format($rec->amount, 2) : '-' }}
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-emerald-600 whitespace-nowrap">
                                    {{ $rec->entry_type === 'credit' ? '₹' . number_format($rec->amount, 2) : '-' }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-bold text-foreground whitespace-nowrap">
                                    ₹{{ number_format($rec->balance_after, 2) }}
                                </td>
                                <td class="px-4 py-3 text-foreground">
                                    <span class="font-medium">{{ $rec->description }}</span>
                                    <span class="text-[10px] text-muted-foreground block uppercase">Ref: {{ $rec->reference_type }} #{{ $rec->reference_id }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                    <x-icon name="book-marked" class="h-8 w-8 mx-auto mb-2 text-muted-foreground/40"/>
                                    <p>{{ __('No financial ledger entries found.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                        <tr>
                            <th class="px-4 py-3">{{ __('Transaction #') }}</th>
                            <th class="px-4 py-3">{{ __('Student') }}</th>
                            <th class="px-4 py-3">{{ __('Gateway & Mode') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Base Fee') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Gateway Charged') }}</th>
                            <th class="px-4 py-3">{{ __('Date & Time') }}</th>
                            <th class="px-4 py-3">{{ __('Receipt / Tracking') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($records as $txn)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3 font-mono font-semibold text-primary whitespace-nowrap">
                                    <div>{{ $txn->transaction_no }}</div>
                                    @if($txn->amount_mismatch)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-orange-600 bg-orange-500/10 px-1.5 py-0.5 rounded-sm mt-0.5">
                                            <x-icon name="alert-triangle" class="h-2.5 w-2.5"/> {{ __('Amount Mismatch') }}
                                        </span>
                                    @endif
                                    @if((float) $txn->refund_amount > 0)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-rose-600 bg-rose-500/10 px-1.5 py-0.5 rounded-sm mt-0.5">
                                            {{ __('Refunded: ₹:amt', ['amt' => number_format((float) $txn->refund_amount, 2)]) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-foreground">{{ $txn->user?->name ?: __('Deleted Student') }}</div>
                                    <div class="text-[11px] text-muted-foreground font-mono">{{ $txn->student_id ?: $txn->user?->student_id }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-semibold text-foreground flex items-center gap-1.5 capitalize">
                                        @if($txn->gateway === 'ccavenue')
                                            <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                        @elseif($txn->gateway === 'razorpay')
                                            <span class="flex h-2 w-2 rounded-full bg-blue-500"></span>
                                        @else
                                            <span class="flex h-2 w-2 rounded-full bg-purple-500"></span>
                                        @endif
                                        {{ $txn->gateway }}
                                    </div>
                                    <div class="text-[10px] text-muted-foreground uppercase">
                                        {{ $txn->gateway_payment_mode ?: str_replace('_', ' ', (string) $txn->payment_method) }}
                                        @if($txn->gateway_card_name) ({{ $txn->gateway_card_name }}) @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-foreground whitespace-nowrap">
                                    ₹{{ number_format((float) $txn->amount, 2) }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if($txn->gateway_charged_amount && $txn->gateway_charged_amount != $txn->amount)
                                        <div class="font-semibold text-foreground">₹{{ number_format((float) $txn->gateway_charged_amount, 2) }}</div>
                                        <div class="text-[10px] text-muted-foreground">Fee: +₹{{ number_format((float) $txn->gateway_fee, 2) }}</div>
                                    @else
                                        <span class="text-muted-foreground">₹{{ number_format((float) $txn->amount, 2) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="text-foreground">{{ $txn->created_at->format('d M Y') }}</div>
                                    <div class="text-[10px] text-muted-foreground">{{ $txn->created_at->format('h:i A') }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if($txn->receipt_no)
                                        <div class="font-mono font-medium text-emerald-600">{{ $txn->receipt_no }}</div>
                                    @endif
                                    @if($txn->gateway_tracking_id)
                                        <div class="font-mono text-[10px] text-muted-foreground">Trk: {{ $txn->gateway_tracking_id }}</div>
                                    @elseif($txn->offline_reference_id)
                                        <div class="font-mono text-[10px] text-muted-foreground">Ref: {{ $txn->offline_reference_id }}</div>
                                    @else
                                        <span class="text-muted-foreground">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @php
                                        $badgeVariant = match($txn->status) {
                                            'successful' => 'success',
                                            'under_review' => 'warning',
                                            'pending' => 'secondary',
                                            'refunded' => 'destructive',
                                            'failed', 'rejected' => 'destructive',
                                            default => 'secondary'
                                        };
                                    @endphp
                                    <x-ui.badge :variant="$badgeVariant">
                                        {{ ucfirst(str_replace('_', ' ', $txn->status)) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        {{-- View Details --}}
                                        <button wire:click="viewDetails({{ $txn->id }})" class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted" title="{{ __('Inspect Transaction Details') }}">
                                            <x-icon name="eye" class="h-4 w-4"/>
                                        </button>

                                        {{-- Reconcile / Sync with CCAvenue Status Tracker API --}}
                                        @if($txn->gateway === 'ccavenue' && $txn->status !== 'successful')
                                            <button wire:click="openSyncModal({{ $txn->id }})" class="p-1 rounded-md text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/40" title="{{ __('Live Sync with CCAvenue Order Status Tracker') }}">
                                                <x-icon name="refresh-cw" class="h-4 w-4"/>
                                            </button>
                                        @endif

                                        {{-- Manual Status Update (if not successful) --}}
                                        @if($txn->status !== 'successful' && (auth()->user()->can('payments.reconcile') || auth()->user()->can('payments.verify') || auth()->user()->hasRole('Super Administrator')))
                                            <button wire:click="openUpdateModal({{ $txn->id }})" class="p-1 rounded-md text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:hover:bg-amber-950/40" title="{{ __('Update Status & Reference Details') }}">
                                                <x-icon name="edit" class="h-4 w-4"/>
                                            </button>
                                        @endif

                                        {{-- Refund Action (for successful transactions with remaining amount) --}}
                                        @if($txn->canBeRefunded() && (auth()->user()->can('payments.refund') || auth()->user()->hasRole('Super Administrator')))
                                            <button wire:click="openRefundModal({{ $txn->id }})" class="p-1 rounded-md text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40" title="{{ __('Process Refund') }}">
                                                <x-icon name="rotate-ccw" class="h-4 w-4"/>
                                            </button>
                                        @endif

                                        {{-- Receipt Download PDF --}}
                                        @if($txn->receipt_no)
                                            <a href="{{ route('payments.receipt.download', $txn) }}" class="p-1 rounded-md text-primary hover:text-primary/80 hover:bg-muted" title="{{ __('Download PDF Receipt') }}">
                                                <x-icon name="receipt" class="h-4 w-4"/>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                    <x-icon name="credit-card" class="h-8 w-8 mx-auto mb-2 text-muted-foreground/40"/>
                                    <p>{{ __('No payment transactions found matching the selected filters.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>

        <div class="p-4 border-t border-border">
            {{ $records->links() }}
        </div>
    </div>

    {{-- Detail Modal --}}
    <x-ui.modal name="transaction-detail-modal" maxWidth="max-w-3xl" :title="__('Transaction Dossier: :txn', ['txn' => $viewingTransaction?->transaction_no])" :description="__('Complete institutional audit trail, gateway settlement details, fees, and bank authorizations.')">
        @if($viewingTransaction)
            <div class="space-y-4 text-xs">
                {{-- Status & Mismatch Banner --}}
                @if($viewingTransaction->amount_mismatch)
                    <div class="p-3 rounded-lg border border-orange-500/30 bg-orange-500/10 text-orange-800 dark:text-orange-300 space-y-1">
                        <div class="font-semibold flex items-center gap-1.5">
                            <x-icon name="alert-triangle" class="h-4 w-4 text-orange-600"/>
                            {{ __('Amount Discrepancy Flagged') }}
                        </div>
                        <p class="text-[11px] leading-relaxed">{{ $viewingTransaction->amount_mismatch_notes }}</p>
                    </div>
                @endif

                {{-- Two Column Overview --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Student & Core Parameters --}}
                    <div class="rounded-xl border border-border p-3.5 space-y-2 bg-muted/20">
                        <h4 class="font-semibold text-foreground border-b border-border pb-1.5 flex items-center gap-1.5">
                            <x-icon name="user" class="h-3.5 w-3.5 text-primary"/>
                            {{ __('Student Information') }}
                        </h4>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Student Name:') }}</span>
                            <span class="font-medium text-foreground">{{ $viewingTransaction->user?->name }}</span>
                        </div>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Student ID:') }}</span>
                            <span class="font-mono font-medium text-foreground">{{ $viewingTransaction->student_id ?: $viewingTransaction->user?->student_id }}</span>
                        </div>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Email ID:') }}</span>
                            <span class="text-foreground">{{ $viewingTransaction->billing_email ?: $viewingTransaction->user?->email }}</span>
                        </div>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Phone Number:') }}</span>
                            <span class="text-foreground">{{ $viewingTransaction->billing_tel ?: $viewingTransaction->user?->phone }}</span>
                        </div>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Billing Country:') }}</span>
                            <span class="text-foreground">{{ $viewingTransaction->billing_country ?: 'India' }}</span>
                        </div>
                    </div>

                    {{-- Financial Breakdown --}}
                    <div class="rounded-xl border border-border p-3.5 space-y-2 bg-muted/20">
                        <h4 class="font-semibold text-foreground border-b border-border pb-1.5 flex items-center gap-1.5">
                            <x-icon name="dollar-sign" class="h-3.5 w-3.5 text-emerald-600"/>
                            {{ __('Financial Breakdown') }}
                        </h4>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Base Institutional Fee:') }}</span>
                            <span class="font-bold text-foreground">₹{{ number_format((float) $viewingTransaction->amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Gateway Charged Gross:') }}</span>
                            <span class="font-semibold text-foreground">₹{{ number_format((float) ($viewingTransaction->gateway_charged_amount ?: $viewingTransaction->amount), 2) }}</span>
                        </div>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Gateway Fee / Surcharge:') }}</span>
                            <span class="text-muted-foreground">₹{{ number_format((float) $viewingTransaction->gateway_fee, 2) }}</span>
                        </div>
                        <div class="flex justify-between py-0.5">
                            <span class="text-muted-foreground">{{ __('Service Tax / GST:') }}</span>
                            <span class="text-muted-foreground">₹{{ number_format((float) $viewingTransaction->gateway_tax, 2) }}</span>
                        </div>
                        <div class="flex justify-between py-0.5 border-t border-border pt-1">
                            <span class="text-muted-foreground">{{ __('Net Credited to Student:') }}</span>
                            <span class="font-bold text-emerald-600">₹{{ number_format((float) $viewingTransaction->amount, 2) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Gateway & Bank Authorization --}}
                <div class="rounded-xl border border-border p-3.5 space-y-2 bg-card">
                    <h4 class="font-semibold text-foreground border-b border-border pb-1.5 flex items-center gap-1.5">
                        <x-icon name="shield-check" class="h-3.5 w-3.5 text-primary"/>
                        {{ __('Gateway & Bank Authorization Details') }}
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Gateway Driver') }}</span>
                            <span class="font-medium text-foreground capitalize">{{ $viewingTransaction->gateway }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Payment Mode') }}</span>
                            <span class="font-medium text-foreground">{{ strtoupper((string) ($viewingTransaction->gateway_payment_mode ?: $viewingTransaction->payment_method)) }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Card / Issuer') }}</span>
                            <span class="font-medium text-foreground">{{ $viewingTransaction->gateway_card_name ?: '-' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase">{{ __('CCAvenue Tracking ID') }}</span>
                            <span class="font-mono text-foreground">{{ $viewingTransaction->gateway_tracking_id ?: '-' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Bank Reference No') }}</span>
                            <span class="font-mono text-foreground">{{ $viewingTransaction->gateway_bank_ref_no ?: '-' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Receipt Number') }}</span>
                            <span class="font-mono text-emerald-600 font-semibold">{{ $viewingTransaction->receipt_no ?: '-' }}</span>
                        </div>
                    </div>
                </div>

                {{-- Refund Details if applicable --}}
                @if((float) $viewingTransaction->refund_amount > 0)
                    <div class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-3.5 space-y-1.5">
                        <h4 class="font-semibold text-rose-700 dark:text-rose-400 border-b border-rose-500/20 pb-1 flex items-center gap-1.5">
                            <x-icon name="rotate-ccw" class="h-3.5 w-3.5 text-rose-600"/>
                            {{ __('Refund Dossier') }}
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
                            <div>
                                <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Total Refunded') }}</span>
                                <span class="font-bold text-rose-600">₹{{ number_format((float) $viewingTransaction->refund_amount, 2) }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Refund Reference No') }}</span>
                                <span class="font-mono text-foreground">{{ $viewingTransaction->refund_reference_no }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Refunded Date') }}</span>
                                <span class="text-foreground">{{ $viewingTransaction->refunded_at?->format('d M Y, h:i A') }}</span>
                            </div>
                            <div class="sm:col-span-3">
                                <span class="text-muted-foreground block text-[10px] uppercase">{{ __('Refund Reason') }}</span>
                                <span class="text-foreground">{{ $viewingTransaction->refund_reason }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Raw Gateway Payload Inspector --}}
                @if(! empty($viewingTransaction->gateway_response))
                    <div x-data="{ open: false }" class="rounded-xl border border-border bg-muted/30 overflow-hidden">
                        <button type="button" @click="open = !open" class="w-full px-3.5 py-2.5 text-left font-semibold text-foreground flex items-center justify-between hover:bg-muted/50">
                            <span class="flex items-center gap-1.5">
                                <x-icon name="code" class="h-3.5 w-3.5 text-primary"/>
                                {{ __('Inspect Raw Gateway Response Payload') }}
                            </span>
                            <x-icon name="chevron-down" class="h-4 w-4 transition-transform" ::class="open ? 'rotate-180' : ''"/>
                        </button>
                        <div x-show="open" x-cloak class="p-3 border-t border-border bg-slate-950 text-slate-100 font-mono text-[11px] overflow-x-auto max-h-56">
                            <pre>{{ json_encode($viewingTransaction->gateway_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </div>
                @endif

                {{-- Modal Footer --}}
                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    @if($viewingTransaction->receipt_no)
                        <a href="{{ route('payments.receipt.download', $viewingTransaction) }}" class="px-3 py-1.5 rounded-lg border border-border bg-card hover:bg-muted font-semibold text-xs inline-flex items-center gap-1.5">
                            <x-icon name="receipt" class="h-3.5 w-3.5 text-primary"/>
                            {{ __('Download Receipt PDF') }}
                        </a>
                    @endif
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('transaction-detail-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- Manual Status Update Modal --}}
    <x-ui.modal name="transaction-update-modal" maxWidth="max-w-lg" :title="__('Manual Payment Status & Gateway Update')" :description="__('Verify payment details manually from CCAvenue dashboard and update transaction record.')">
        <form wire:submit="saveStatusUpdate" class="space-y-4 text-xs">
            <div>
                <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Update Status') }}</label>
                <select wire:model="updateStatus" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="successful">{{ __('Successful (Approve & Settle Invoice + Issue Receipt)') }}</option>
                    <option value="under_review">{{ __('Under Review (Flag for Inspection)') }}</option>
                    <option value="failed">{{ __('Failed (Gateway Declined / Aborted)') }}</option>
                    <option value="rejected">{{ __('Rejected (Invalid Submission)') }}</option>
                    <option value="pending">{{ __('Pending (Awaiting Confirmation)') }}</option>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <x-ui.input
                        wire:model="updateTrackingId"
                        :label="__('CCAvenue Tracking ID / PG Ref')"
                        placeholder="204000136232"
                    />
                </div>
                <div>
                    <x-ui.input
                        wire:model="updateBankRefNo"
                        :label="__('Bank Authorization Ref No')"
                        placeholder="289049"
                    />
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Payment Method Mode') }}</label>
                <select wire:model="updatePaymentMethod" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                    <option value="online">{{ __('Online (Automated)') }}</option>
                    <option value="card">{{ __('Credit / Debit Card') }}</option>
                    <option value="upi">{{ __('UPI') }}</option>
                    <option value="netbanking">{{ __('Net Banking') }}</option>
                    <option value="neft">{{ __('NEFT / RTGS') }}</option>
                    <option value="imps">{{ __('IMPS') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                </select>
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Administrative Verification Remarks (Required)') }}</label>
                <textarea wire:model="updateAdminRemarks" rows="3" class="w-full rounded-lg border border-border bg-background p-2.5 text-xs text-foreground focus:ring-1 focus:ring-primary" placeholder="{{ __('Verified from CCAvenue M.A.R.S dashboard against Order Ref #...') }}"></textarea>
                @error('updateAdminRemarks') <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('transaction-update-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    {{ __('Save Changes') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Refund Modal --}}
    <x-ui.modal name="transaction-refund-modal" maxWidth="max-w-lg" :title="__('Initiate Payment Refund')" :description="__('Refund full or partial amount for duplicate payments, overpayments, or cancellations.')">
        <form wire:submit="processRefund" class="space-y-4 text-xs">
            <div class="rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 p-3 space-y-1">
                <div class="font-semibold text-amber-800 dark:text-amber-300">{{ __('Refund Eligibility & Double-Entry Impact') }}</div>
                <p class="text-[11px] text-amber-900/90 dark:text-amber-200/90 leading-relaxed">
                    {{ __('Processing a refund will automatically post a debit adjustment to the student\'s ledger, reopen invoice dues if appropriate, and log full audit entries.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <x-ui.input
                        wire:model="refundAmount"
                        type="number"
                        step="0.01"
                        :label="__('Refund Amount (₹) [Max: ₹' . number_format($maxRefundable, 2) . ']')"
                    />
                </div>
                <div>
                    <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Refund Mode') }}</label>
                    <select wire:model="refundMode" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                        <option value="online_api">{{ __('Online via CCAvenue Refund API') }}</option>
                        <option value="manual">{{ __('Manual Record (Dashboard / Bank Transfer)') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <x-ui.input
                    wire:model="refundRefNo"
                    :label="__('Refund Reference Number')"
                    placeholder="REF-20261109-ABCD"
                />
            </div>

            <div>
                <label class="text-xs font-semibold text-foreground mb-1 block">{{ __('Reason for Refund (Mandatory)') }}</label>
                <textarea wire:model="refundReason" rows="3" class="w-full rounded-lg border border-border bg-background p-2.5 text-xs text-foreground focus:ring-1 focus:ring-primary" placeholder="{{ __('Duplicate payment made by student unintentionally / excess payment / withdrawal...') }}"></textarea>
                @error('refundReason') <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('transaction-refund-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" type="submit">
                    {{ __('Confirm & Execute Refund') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Live Sync & Gateway Diagnostics Modal --}}
    <x-ui.modal name="transaction-sync-modal" maxWidth="max-w-lg" :title="__('CCAvenue Order Status Tracker & Reconciliation')" :description="__('Query CCAvenue official status tracker API directly to resolve dropped or pending payment states.')">
        @if($syncingTransaction)
            <div class="space-y-4 text-xs">
                {{-- Transaction Info Summary --}}
                <div class="rounded-lg border border-border bg-muted/40 p-3 space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Transaction ID:') }}</span>
                        <span class="font-mono font-bold text-foreground">#{{ $syncingTransaction->id }} &bull; {{ $syncingTransaction->transaction_no }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Student:') }}</span>
                        <span class="font-medium text-foreground">{{ $syncingTransaction->user?->name ?: 'N/A' }} ({{ $syncingTransaction->user?->student_id ?: 'ID N/A' }})</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Amount Demanded:') }}</span>
                        <span class="font-bold text-emerald-600 dark:text-emerald-400">₹{{ number_format((float) $syncingTransaction->amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Current Local Status:') }}</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $syncingTransaction->status === 'successful' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">
                            {{ ucfirst(str_replace('_', ' ', $syncingTransaction->status)) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Initiated At:') }}</span>
                        <span class="text-muted-foreground">{{ $syncingTransaction->created_at->format('d M Y, h:i A') }} ({{ $syncingTransaction->created_at->diffForHumans() }})</span>
                    </div>
                </div>

                @if(! $syncDiagnosticResult)
                    <div class="rounded-lg bg-sky-50 dark:bg-sky-950/30 border border-sky-200 dark:border-sky-900/40 p-3 text-sky-900 dark:text-sky-200 flex items-start gap-2.5">
                        <x-icon name="info" class="h-4 w-4 text-sky-600 shrink-0 mt-0.5"/>
                        <p class="leading-relaxed">
                            {{ __('Click "Query CCAvenue Status Tracker" to fetch real-time gateway data for this transaction. If the order was completed by the bank, the transaction status, student ledger, and invoice balance will be synchronized.') }}
                        </p>
                    </div>
                @endif

                @if($syncDiagnosticResult)
                    <div class="rounded-lg p-3 text-xs space-y-2 {{ $syncingTransaction->status === 'successful' ? 'bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800' : 'bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800' }}">
                        <div class="flex items-center gap-2 font-bold {{ $syncingTransaction->status === 'successful' ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300' }}">
                            <x-icon name="{{ $syncingTransaction->status === 'successful' ? 'check-circle' : 'alert-circle' }}" class="h-4 w-4"/>
                            <span>{{ $syncDiagnosticResult['order_status'] ?? __('Gateway Tracker Response') }}</span>
                        </div>
                        <p class="text-muted-foreground leading-relaxed">
                            {{ $syncDiagnosticResult['message'] ?? '' }}
                        </p>
                        @if($syncingTransaction->gateway_bank_ref_no || $syncingTransaction->gateway_tracking_id)
                            <div class="pt-2 border-t border-border grid grid-cols-2 gap-2 font-mono text-[11px]">
                                @if($syncingTransaction->gateway_tracking_id)
                                    <div><span class="text-muted-foreground">Tracking ID:</span> {{ $syncingTransaction->gateway_tracking_id }}</div>
                                @endif
                                @if($syncingTransaction->gateway_bank_ref_no)
                                    <div><span class="text-muted-foreground">Bank Ref:</span> {{ $syncingTransaction->gateway_bank_ref_no }}</div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button type="button" x-data x-on:click="$store.modals.close('transaction-sync-modal')" variant="outline">
                        {{ __('Close') }}
                    </x-ui.button>
                    @if($syncingTransaction->status !== 'successful')
                        <x-ui.button wire:click="executeLiveSyncQuery" wire:loading.attr="disabled" class="bg-emerald-600 hover:bg-emerald-700 text-white">
                            <span wire:loading.remove wire:target="executeLiveSyncQuery" class="inline-flex items-center gap-1.5">
                                <x-icon name="refresh-cw" class="h-3.5 w-3.5"/>
                                {{ __('Query CCAvenue Status Tracker') }}
                            </span>
                            <span wire:loading wire:target="executeLiveSyncQuery" class="inline-flex items-center gap-1.5">
                                <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin"/>
                                {{ __('Contacting CCAvenue...') }}
                            </span>
                        </x-ui.button>
                    @endif
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
