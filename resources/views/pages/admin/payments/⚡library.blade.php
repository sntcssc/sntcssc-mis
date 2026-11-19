<?php

use App\Models\LibraryDeposit;
use App\Models\User;
use App\Services\Payment\LibraryDepositService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Library Security Deposit & Refunds')] class extends Component {
    use WithFileUploads, WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';

    // Direct Collect Modal
    public bool $showCollectModal = false;
    public ?int $selectedStudentId = null;
    public float $collectAmount = 1000.00;
    public string $collectMethod = 'cash';
    public string $collectReference = '';
    public string $collectRemarks = '';

    // Multi-selection for batch operations
    public array $selectedDepositIds = [];
    public bool $selectAll = false;

    // Admin Raise Refund Request Modal
    public bool $showRequestModal = false;
    public ?int $selectedDepositId = null;
    public string $refundReason = '';
    public string $bankName = '';
    public string $accountNumber = '';
    public string $ifscCode = '';
    public string $accountHolderName = '';
    public string $upiId = '';

    // Review / Approve Modal
    public bool $showReviewModal = false;
    public ?LibraryDeposit $selectedDeposit = null;
    public float $approvedAmount = 0.00;
    public string $reviewNotes = '';

    // Disburse Modal
    public bool $showDisburseModal = false;
    public string $disbursementRef = '';
    public $disbursementProof = null;
    public string $disbursementNotes = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggleSelectDeposit(int $depositId): void
    {
        if (in_array($depositId, $this->selectedDepositIds)) {
            $this->selectedDepositIds = array_values(array_diff($this->selectedDepositIds, [$depositId]));
        } else {
            $this->selectedDepositIds[] = $depositId;
        }
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedDepositIds = LibraryDeposit::query()
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.trim($this->search).'%';
                    $query->where(function (Builder $q) use ($term) {
                        $q->where('deposit_no', 'like', $term)
                            ->orWhere('student_id', 'like', $term)
                            ->orWhere('refund_transaction_ref', 'like', $term)
                            ->orWhereHas('user', function (Builder $uq) use ($term) {
                                $uq->where('name', 'like', $term)
                                    ->orWhere('email', 'like', $term)
                                    ->orWhere('student_id', 'like', $term);
                            });
                    });
                })
                ->when($this->statusFilter !== 'all', fn (Builder $q) => $q->where('status', $this->statusFilter))
                ->pluck('id')
                ->all();
        } else {
            $this->selectedDepositIds = [];
        }
    }

    public function openCollectModal(): void
    {
        $this->selectedStudentId = null;
        $this->collectAmount = 1000.00;
        $this->collectMethod = 'cash';
        $this->collectReference = 'LIB-'.now()->format('YmdHis');
        $this->collectRemarks = 'Library security deposit collected at library desk.';
        $this->showCollectModal = true;
    }

    public function saveCollection(): void
    {
        $this->validate([
            'selectedStudentId' => ['required', 'exists:users,id'],
            'collectAmount' => ['required', 'numeric', 'min:100'],
            'collectMethod' => ['required', 'string'],
            'collectReference' => ['required', 'string', 'max:100'],
        ]);

        try {
            $deposit = LibraryDepositService::createDirectDeposit([
                'user_id' => $this->selectedStudentId,
                'amount' => $this->collectAmount,
                'payment_method' => $this->collectMethod,
                'payment_reference' => $this->collectReference,
                'remarks' => $this->collectRemarks,
            ], auth()->id());

            Toast::dispatch($this, 'success', __("Recorded library security deposit :dep of ₹:amt.", [
                'dep' => $deposit->deposit_no,
                'amt' => number_format($deposit->amount, 2),
            ]));

            $this->showCollectModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to collect deposit: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openRequestModal(int $depositId): void
    {
        $this->selectedDepositIds = [$depositId];
        $this->openRequestModalForSelected();
    }

    public function openRequestModalForSelected(): void
    {
        if (empty($this->selectedDepositIds)) {
            Toast::dispatch($this, 'warning', __('Please select at least one active deposit to request a refund.'));
            return;
        }

        $activeDeposits = LibraryDeposit::with('user')->whereIn('id', $this->selectedDepositIds)->where('status', LibraryDeposit::STATUS_ACTIVE)->get();
        if ($activeDeposits->isEmpty()) {
            Toast::dispatch($this, 'warning', __('None of the selected deposits are active.'));
            return;
        }

        $this->selectedDepositIds = $activeDeposits->pluck('id')->all();
        $this->selectedDepositId = $this->selectedDepositIds[0];
        $first = $activeDeposits->first();
        $this->refundReason = 'Course completed / Library clearance obtained.';
        $this->bankName = $first->bank_name ?: '';
        $this->accountNumber = $first->account_number ?: '';
        $this->ifscCode = $first->ifsc_code ?: '';
        $this->accountHolderName = $first->account_holder_name ?: ($first->user?->name ?: '');
        $this->upiId = $first->upi_id ?: '';
        $this->showRequestModal = true;
    }

    public function submitRefundRequest(): void
    {
        if (empty($this->selectedDepositIds)) {
            Toast::dispatch($this, 'warning', __('No deposits selected for refund request.'));
            return;
        }

        $this->validate([
            'refundReason' => ['required', 'string', 'min:5', 'max:500'],
            'upiId' => ['nullable', 'string', 'max:100'],
            'accountNumber' => ['nullable', 'string', 'max:50'],
            'ifscCode' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $refunded = LibraryDepositService::requestRefundMultiple(
                depositIds: $this->selectedDepositIds,
                requestedBy: auth()->id(),
                bankDetails: [
                    'refund_reason' => $this->refundReason,
                    'bank_name' => $this->bankName,
                    'account_number' => $this->accountNumber,
                    'ifsc_code' => $this->ifscCode,
                    'account_holder_name' => $this->accountHolderName,
                    'upi_id' => $this->upiId,
                ]
            );

            Toast::dispatch($this, 'success', __('Refund request registered for :count deposit(s).', ['count' => $refunded->count()]));
            $this->selectedDepositIds = [];
            $this->showRequestModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to submit refund request: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openReviewModal(int $depositId): void
    {
        $this->selectedDepositIds = [$depositId];
        $this->openReviewModalForSelected();
    }

    public function openReviewModalForSelected(): void
    {
        if (empty($this->selectedDepositIds)) {
            Toast::dispatch($this, 'warning', __('Please select at least one refund claim to review.'));
            return;
        }

        $reviewDeposits = LibraryDeposit::with(['user', 'requester'])
            ->whereIn('id', $this->selectedDepositIds)
            ->where('status', LibraryDeposit::STATUS_REFUND_REQUESTED)
            ->get();

        if ($reviewDeposits->isEmpty()) {
            Toast::dispatch($this, 'warning', __('None of the selected deposits have pending refund requests.'));
            return;
        }

        $this->selectedDepositIds = $reviewDeposits->pluck('id')->all();
        $this->selectedDeposit = $reviewDeposits->first();
        $this->approvedAmount = (float) $reviewDeposits->sum('amount');
        $this->reviewNotes = 'Verified library clearance card. No pending books or overdue fines.';
        $this->showReviewModal = true;
    }

    public function approveRefund(): void
    {
        if (empty($this->selectedDepositIds)) {
            return;
        }

        try {
            $approved = LibraryDepositService::approveRefundMultiple(
                depositIds: $this->selectedDepositIds,
                reviewedBy: auth()->id(),
                adminNotes: $this->reviewNotes
            );

            Toast::dispatch($this, 'success', __('Approved :count refund claim(s) and queued for disbursement.', ['count' => $approved->count()]));
            $this->selectedDepositIds = [];
            $this->showReviewModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Approval failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function rejectRefund(): void
    {
        if (empty($this->selectedDepositIds)) {
            return;
        }

        $this->validate([
            'reviewNotes' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $rejected = LibraryDepositService::rejectRefundMultiple(
                depositIds: $this->selectedDepositIds,
                reviewedBy: auth()->id(),
                rejectionNotes: $this->reviewNotes
            );

            Toast::dispatch($this, 'success', __('Declined :count refund claim(s).', ['count' => $rejected->count()]));
            $this->selectedDepositIds = [];
            $this->showReviewModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Rejection failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openDisburseModal(int $depositId): void
    {
        $this->selectedDepositIds = [$depositId];
        $this->openDisburseModalForSelected();
    }

    public function openDisburseModalForSelected(): void
    {
        if (empty($this->selectedDepositIds)) {
            Toast::dispatch($this, 'warning', __('Please select at least one approved refund to disburse.'));
            return;
        }

        $approvedDeposits = LibraryDeposit::with('user')
            ->whereIn('id', $this->selectedDepositIds)
            ->where('status', LibraryDeposit::STATUS_REFUND_APPROVED)
            ->get();

        if ($approvedDeposits->isEmpty()) {
            Toast::dispatch($this, 'warning', __('None of the selected deposits are approved for disbursement.'));
            return;
        }

        $this->selectedDepositIds = $approvedDeposits->pluck('id')->all();
        $this->selectedDeposit = $approvedDeposits->first();
        $this->disbursementRef = 'UTR-'.now()->format('YmdHis').'-'.rand(100, 999);
        $this->disbursementProof = null;
        $this->disbursementNotes = 'Disbursed via online bank NEFT transfer.';
        $this->showDisburseModal = true;
    }

    public function processDisbursement(): void
    {
        if (empty($this->selectedDepositIds)) {
            return;
        }

        $this->validate([
            'disbursementRef' => ['required', 'string', 'max:100'],
            'disbursementProof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        try {
            $disbursed = LibraryDepositService::processDisbursementMultiple(
                depositIds: $this->selectedDepositIds,
                processedBy: auth()->id(),
                transactionRef: $this->disbursementRef,
                proofFile: $this->disbursementProof,
                adminNotes: $this->disbursementNotes
            );

            Toast::dispatch($this, 'success', __('Disbursed :count deposit refund(s) and recorded in student ledger.', ['count' => $disbursed->count()]));
            $this->selectedDepositIds = [];
            $this->showDisburseModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Disbursement failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function export(string $format = 'xlsx')
    {
        $query = LibraryDeposit::with(['user', 'requester', 'reviewer'])->orderByDesc('created_at');
        $headings = ['Deposit #', 'Student Name', 'Student ID', 'Deposit Amount (₹)', 'Deposit Date', 'Status', 'Refund Requested Date', 'Bank / UPI Ref', 'Refund Ref / UTR'];

        return TableExporter::download(
            query: $query,
            format: $format,
            filename: 'library-security-deposits',
            headings: $headings,
            mapper: fn (LibraryDeposit $d) => [
                $d->deposit_no,
                $d->user?->name,
                $d->student_id ?: $d->user?->student_id,
                number_format($d->amount, 2),
                $d->deposit_date->format('d/m/Y'),
                ucwords(str_replace('_', ' ', $d->status)),
                $d->refund_requested_at?->format('d/m/Y') ?: 'N/A',
                $d->upi_id ?: ($d->account_number ? "A/C: {$d->account_number} ({$d->ifsc_code})" : 'N/A'),
                $d->refund_transaction_ref ?: 'N/A',
            ],
            title: 'SNTCSSC Library Security Deposits & Refund Register'
        );
    }

    public function with(): array
    {
        $deposits = LibraryDeposit::query()
            ->with(['user.studentProfile', 'requester', 'reviewer', 'processor', 'paymentTransaction'])
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('deposit_no', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhere('refund_transaction_ref', 'like', $term)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $term)->orWhere('student_id', 'like', $term));
                });
            })
            ->when($this->statusFilter !== 'all', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('created_at')
            ->paginate(15);

        $activeCount = LibraryDeposit::where('status', 'active')->count();
        $activeFunds = LibraryDeposit::where('status', 'active')->sum('amount');
        $pendingRefunds = LibraryDeposit::whereIn('status', ['refund_requested', 'refund_approved'])->count();
        $disbursedFunds = LibraryDeposit::where('status', 'refund_processed')->sum('amount');

        return [
            'deposits' => $deposits,
            'activeCount' => $activeCount,
            'activeFunds' => $activeFunds,
            'pendingRefunds' => $pendingRefunds,
            'disbursedFunds' => $disbursedFunds,
            'students' => User::whereNotNull('student_id')->orWhereHas('enrollments')->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Library Security Deposit & Refunds') }}</h1>
                @if($pendingRefunds > 0)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                        {{ $pendingRefunds }} {{ __('Refund Requests Pending') }}
                    </span>
                @endif
            </div>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Manage refundable institutional library deposits, student refund claims, clearances, and bank disbursement audits.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button wire:click="openCollectModal">
                <x-icon name="plus" class="h-4 w-4 mr-1.5"/>
                {{ __('Collect Deposit') }}
            </x-ui.button>

            <button wire:click="export('xlsx')" class="p-2 rounded-lg border border-border bg-card hover:bg-muted text-foreground" title="Export Excel">
                <x-icon name="file-spreadsheet" class="h-4 w-4 text-emerald-600"/>
            </button>
        </div>
    </div>

    {{-- Deposit Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Active Depositors') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600">
                    <x-icon name="book-open" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-foreground">{{ $activeCount }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Students holding active library lending cards') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Total Funds Held') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-icon name="vault" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-emerald-600">₹{{ number_format($activeFunds, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Security collateral held in reserve') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Pending Refunds') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                    <x-icon name="hourglass" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-amber-600">{{ $pendingRefunds }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Claims awaiting review or disbursement') }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Refunds Disbursed') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600">
                    <x-icon name="undo-2" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-purple-600">₹{{ number_format($disbursedFunds, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Refunded back upon course completion') }}</p>
        </div>
    </div>

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-ui.input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search deposit #, student name, ID or UTR...') }}"
            />

            <select wire:model.live="statusFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                <option value="all">{{ __('All Deposit Records') }}</option>
                <option value="active">{{ __('Active (Held in Reserve)') }}</option>
                <option value="refund_requested">{{ __('Refund Requested (Action Required)') }}</option>
                <option value="refund_approved">{{ __('Approved (Ready for Disbursement)') }}</option>
                <option value="refund_processed">{{ __('Refund Disbursed') }}</option>
                <option value="refund_rejected">{{ __('Refund Rejected') }}</option>
                <option value="forfeited">{{ __('Forfeited') }}</option>
            </select>
        </div>
    </div>

    {{-- Bulk Action Bar --}}
    @if(count($selectedDepositIds) > 0)
        @php
            $selectedModels = \App\Models\LibraryDeposit::whereIn('id', $selectedDepositIds)->get();
            $activeInSelection = $selectedModels->where('status', 'active')->count();
            $reviewInSelection = $selectedModels->where('status', 'refund_requested')->count();
            $approvedInSelection = $selectedModels->where('status', 'refund_approved')->count();
            $totalSelectedAmount = (float) $selectedModels->sum('amount');
        @endphp
        <div class="rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="font-bold text-xs text-primary">{{ __('Selected :count deposit(s)', ['count' => count($selectedDepositIds)]) }}</span>
                <span class="text-xs text-muted-foreground font-mono">(Total: ₹{{ number_format($totalSelectedAmount, 2) }})</span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($activeInSelection > 0)
                    <x-ui.button wire:click="openRequestModalForSelected" size="sm" variant="outline" class="text-xs">
                        <x-icon name="undo" class="h-3.5 w-3.5 mr-1 text-amber-500"/>
                        {{ __('Raise Refund (:count)', ['count' => $activeInSelection]) }}
                    </x-ui.button>
                @endif

                @if($reviewInSelection > 0)
                    <x-ui.button wire:click="openReviewModalForSelected" size="sm" class="text-xs">
                        <x-icon name="check-circle" class="h-3.5 w-3.5 mr-1"/>
                        {{ __('Batch Approve (:count)', ['count' => $reviewInSelection]) }}
                    </x-ui.button>
                @endif

                @if($approvedInSelection > 0)
                    <x-ui.button wire:click="openDisburseModalForSelected" size="sm" class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white">
                        <x-icon name="send" class="h-3.5 w-3.5 mr-1"/>
                        {{ __('Batch Disburse (:count)', ['count' => $approvedInSelection]) }}
                    </x-ui.button>
                @endif

                <x-ui.button wire:click="$set('selectedDepositIds', [])" variant="ghost" size="sm" class="text-xs">
                    {{ __('Clear') }}
                </x-ui.button>
            </div>
        </div>
    @endif

    {{-- Deposits Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                    <tr>
                        <th class="px-3 py-3 w-10">
                            <input type="checkbox" wire:model.live="selectAll" class="rounded border-border text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"/>
                        </th>
                        <th class="px-4 py-3">{{ __('Deposit #') }}</th>
                        <th class="px-4 py-3">{{ __('Student') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Deposit Amount') }}</th>
                        <th class="px-4 py-3">{{ __('Deposit Date') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Refund Bank / UPI') }}</th>
                        <th class="px-4 py-3">{{ __('Disbursement Ref') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($deposits as $dep)
                        <tr class="hover:bg-muted/30 transition-colors {{ in_array($dep->id, $selectedDepositIds) ? 'bg-primary/5' : '' }}">
                            <td class="px-3 py-3">
                                <input type="checkbox" wire:click="toggleSelectDeposit({{ $dep->id }})" @checked(in_array($dep->id, $selectedDepositIds)) class="rounded border-border text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer"/>
                            </td>
                            <td class="px-4 py-3 font-semibold whitespace-nowrap">
                                <span class="font-mono text-primary">{{ $dep->deposit_no }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-medium text-foreground">{{ $dep->user?->name ?: __('Deleted Student') }}</div>
                                <div class="text-[11px] text-muted-foreground font-mono">{{ $dep->student_id ?: $dep->user?->student_id ?: 'ID: N/A' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-foreground whitespace-nowrap">
                                ₹{{ number_format($dep->amount, 2) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ $dep->deposit_date->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($dep->status === 'active')
                                    <x-ui.badge variant="success">{{ __('Active (Held)') }}</x-ui.badge>
                                @elseif($dep->status === 'refund_requested')
                                    <x-ui.badge variant="warning">{{ __('Refund Requested') }}</x-ui.badge>
                                @elseif($dep->status === 'refund_approved')
                                    <x-ui.badge variant="secondary" class="bg-blue-500/10 text-blue-600 border-blue-500/20">{{ __('Approved for Payout') }}</x-ui.badge>
                                @elseif($dep->status === 'refund_processed')
                                    <x-ui.badge variant="default" class="bg-purple-500/10 text-purple-600 border-purple-500/20">{{ __('Disbursed / Returned') }}</x-ui.badge>
                                @elseif($dep->status === 'refund_rejected')
                                    <x-ui.badge variant="destructive">{{ __('Rejected') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">{{ ucfirst($dep->status) }}</x-ui.badge>
                                @endif

                                {{-- Clearance Certificate Status --}}
                                @if($dep->clearance_status === 'cleared')
                                    <div class="mt-1 flex items-center gap-1">
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                            <x-icon name="shield-check" class="h-3 w-3" />
                                            {{ __('Librarian Cleared') }}
                                        </span>
                                        @if($dep->clearance_certificate_no)
                                            <a href="{{ route('library.clearance.download', $dep->id) }}" target="_blank" title="{{ __('Download Clearance Certificate') }}" class="text-primary hover:text-primary/80">
                                                <x-icon name="file-text" class="h-3.5 w-3.5" />
                                            </a>
                                        @endif
                                    </div>
                                @elseif($dep->clearance_status === 'pending' || ($dep->status === 'refund_requested' && !$dep->clearance_status))
                                    <div class="mt-1">
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                            <x-icon name="clock" class="h-3 w-3" />
                                            {{ __('Clearance Pending') }}
                                        </span>
                                    </div>
                                @elseif($dep->clearance_status === 'rejected')
                                    <div class="mt-1">
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20">
                                            <x-icon name="x-circle" class="h-3 w-3" />
                                            {{ __('Clearance Rejected') }}
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($dep->upi_id)
                                    <span class="font-mono text-xs text-foreground">{{ $dep->upi_id }}</span>
                                    <span class="text-[10px] text-muted-foreground block">{{ __('UPI VPA') }}</span>
                                @elseif($dep->account_number)
                                    <span class="font-mono text-xs text-foreground">A/C: ••••{{ substr($dep->account_number, -4) }}</span>
                                    <span class="text-[10px] text-muted-foreground block">{{ $dep->ifsc_code }} ({{ $dep->bank_name }})</span>
                                @else
                                    <span class="text-muted-foreground text-[11px]">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap font-mono text-[11px]">
                                @if($dep->refund_transaction_ref)
                                    <span class="text-emerald-600 font-semibold">{{ $dep->refund_transaction_ref }}</span>
                                    @if($dep->refund_receipt_path)
                                        <a href="{{ Storage::url($dep->refund_receipt_path) }}" target="_blank" class="ml-1 text-primary hover:underline">
                                            <x-icon name="external-link" class="h-3 w-3 inline"/>
                                        </a>
                                    @endif
                                @else
                                    <span class="text-muted-foreground">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if($dep->status === 'active')
                                        <x-ui.button wire:click="openRequestModal({{ $dep->id }})" variant="outline" size="sm" class="text-xs">
                                            <x-icon name="undo" class="h-3.5 w-3.5 mr-1 text-amber-500"/>
                                            {{ __('Raise Refund') }}
                                        </x-ui.button>
                                    @elseif($dep->status === 'refund_requested')
                                        <x-ui.button wire:click="openReviewModal({{ $dep->id }})" size="sm" class="text-xs">
                                            <x-icon name="check-circle" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Review Claim') }}
                                        </x-ui.button>
                                    @elseif($dep->status === 'refund_approved')
                                        <x-ui.button wire:click="openDisburseModal({{ $dep->id }})" variant="default" size="sm" class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white">
                                            <x-icon name="send" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Disburse UTR') }}
                                        </x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="inbox" class="h-8 w-8 mx-auto mb-2 text-muted-foreground/40"/>
                                <p>{{ __('No library security deposit records found.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $deposits->links() }}
        </div>
    </div>

    {{-- Direct Collect Deposit Modal --}}
    @if($showCollectModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">{{ __('Collect Library Security Deposit') }}</h3>
                    <button wire:click="$set('showCollectModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-foreground mb-1">{{ __('Select Enrolled Student') }} *</label>
                        <select wire:model.live="selectedStudentId" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                            <option value="">{{ __('-- Select Student --') }}</option>
                            @foreach($students as $st)
                                <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->student_id ?: $st->email }})</option>
                            @endforeach
                        </select>
                    </div>

                    @php
                        $stUser = $selectedStudentId ? \App\Models\User::find($selectedStudentId) : null;
                        $stActiveDeposits = $stUser ? $stUser->libraryDeposits()->where('status', 'active')->get() : collect();
                    @endphp
                    @if($stUser && $stActiveDeposits->isNotEmpty())
                        <div class="p-2.5 rounded-lg bg-primary/10 border border-primary/20 text-xs text-primary flex items-center justify-between">
                            <span>{{ __('Existing Active Deposits Held:') }}</span>
                            <span class="font-bold font-mono">₹{{ number_format($stActiveDeposits->sum('amount'), 2) }} ({{ $stActiveDeposits->count() }} {{ __('deposits') }})</span>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-medium text-foreground mb-1">{{ __('Deposit Tier (INR)') }} *</label>
                            <select wire:model="collectAmount" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                                <option value="500">₹500.00</option>
                                <option value="1000">₹1,000.00 (Standard)</option>
                                <option value="1500">₹1,500.00</option>
                                <option value="2000">₹2,000.00</option>
                                <option value="2500">₹2,500.00</option>
                                <option value="3000">₹3,000.00</option>
                            </select>
                        </div>

                        <x-ui.select
                            wire:model="collectMethod"
                            :label="__('Collection Mode') . ' *'"
                            :options="[
                                'cash' => 'Cash in Hand',
                                'static_qr' => 'UPI QR',
                                'bank_transfer' => 'Bank Transfer',
                            ]"
                        />
                    </div>

                    <x-ui.input
                        wire:model="collectReference"
                        :label="__('Reference / Money Receipt #') . ' *'"
                        required
                    />

                    <x-ui.textarea
                        wire:model="collectRemarks"
                        :label="__('Remarks')"
                        rows="2"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showCollectModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="saveCollection" wire:loading.attr="disabled">
                        <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                        {{ __('Save Deposit') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Admin Raise Refund Request Modal --}}
    @if($showRequestModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">{{ __('Raise Security Deposit Refund Request') }}</h3>
                    <button wire:click="$set('showRequestModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                @php
                    $reqSelectedModels = \App\Models\LibraryDeposit::with('user')->whereIn('id', $selectedDepositIds)->get();
                    $reqSelectedTotal = (float) $reqSelectedModels->sum('amount');
                @endphp
                @if($reqSelectedModels->isNotEmpty())
                    <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-900 dark:text-amber-200 text-xs space-y-1">
                        <div class="flex justify-between font-bold">
                            <span>{{ __('Selected Deposits for Refund:') }} ({{ $reqSelectedModels->count() }})</span>
                            <span class="text-amber-700 dark:text-amber-300 font-mono">₹{{ number_format($reqSelectedTotal, 2) }}</span>
                        </div>
                        <div class="flex flex-wrap gap-1.5 pt-1">
                            @foreach($reqSelectedModels as $rm)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono bg-background border border-border text-foreground">
                                    {{ $rm->deposit_no }} ({{ $rm->user?->name }}) - ₹{{ number_format($rm->amount, 2) }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="space-y-3 text-xs">
                    <x-ui.textarea
                        wire:model="refundReason"
                        :label="__('Reason for Refund Claim') . ' *'"
                        rows="2"
                        required
                    />

                    <div class="p-3 rounded-lg bg-muted/40 border border-border space-y-3">
                        <div class="font-semibold text-foreground">{{ __('Disbursement Bank / UPI Destination:') }}</div>

                        <x-ui.input
                            wire:model="upiId"
                            :label="__('UPI ID (VPA) - Instant Transfer')"
                            placeholder="student@sbi"
                        />

                        <div class="relative flex py-1 items-center">
                            <div class="grow border-t border-border"></div>
                            <span class="shrink mx-2 text-[10px] text-muted-foreground uppercase">{{ __('OR Bank Details') }}</span>
                            <div class="grow border-t border-border"></div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.input wire:model="bankName" :label="__('Bank Name')" placeholder="SBI / HDFC"/>
                            <x-ui.input wire:model="accountHolderName" :label="__('Account Holder Name')"/>
                            <x-ui.input wire:model="accountNumber" :label="__('Bank Account Number')"/>
                            <x-ui.input wire:model="ifscCode" :label="__('IFSC Code')"/>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showRequestModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="submitRefundRequest" wire:loading.attr="disabled">
                        <x-icon name="send" class="h-4 w-4 mr-1.5"/>
                        {{ __('Submit Refund Claim') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Review / Approve Refund Modal --}}
    @if($showReviewModal && !empty($selectedDepositIds))
        @php
            $revSelectedModels = \App\Models\LibraryDeposit::with(['user', 'requester', 'clearedBy'])->whereIn('id', $selectedDepositIds)->get();
            $revSelectedTotal = (float) $revSelectedModels->sum('amount');
            $unclearedCount = $revSelectedModels->where('clearance_status', '!=', 'cleared')->count();
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">{{ __('Review Security Deposit Refund Claim(s)') }}</h3>
                    <button wire:click="$set('showReviewModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                {{-- Clearance Requirement Warning Banner --}}
                @if($unclearedCount > 0)
                    <div class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-800 dark:text-amber-300 text-xs flex items-start gap-2.5">
                        <x-icon name="alert-triangle" class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
                        <div>
                            <span class="font-bold">{{ __('Librarian Clearance Required') }}</span>
                            <p class="mt-0.5 text-[11px] text-amber-700 dark:text-amber-400">
                                {{ __(':count of the selected claims have not yet received official clearance from the Librarian. All books must be returned and the Librarian must issue a clearance certificate before refund approval.', ['count' => $unclearedCount]) }}
                            </p>
                        </div>
                    </div>
                @else
                    <div class="p-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-800 dark:text-emerald-300 text-xs flex items-center gap-2">
                        <x-icon name="shield-check" class="h-4 w-4 shrink-0 text-emerald-600" />
                        <span>{{ __('All selected claims have been cleared by the Librarian with verified clearance certificates.') }}</span>
                    </div>
                @endif

                <div class="space-y-3 text-xs">
                    <div class="p-3 rounded-lg bg-muted/40 border border-border text-xs space-y-2">
                        <div class="flex justify-between font-bold">
                            <span>{{ __('Selected Claims to Review:') }} ({{ $revSelectedModels->count() }})</span>
                            <span class="text-primary font-mono text-sm">₹{{ number_format($revSelectedTotal, 2) }}</span>
                        </div>
                        <div class="space-y-2 max-h-44 overflow-y-auto pr-1">
                            @foreach($revSelectedModels as $rm)
                                <div class="p-2 rounded bg-background/50 border border-border/50 text-[11px] space-y-1">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <span class="font-mono text-primary font-semibold">{{ $rm->deposit_no }}</span>
                                            <span class="text-foreground ml-1 font-medium">{{ $rm->user?->name }}</span>
                                        </div>
                                        <span class="font-bold text-foreground">₹{{ number_format($rm->amount, 2) }}</span>
                                    </div>
                                    <div class="text-[10px] text-muted-foreground">{{ $rm->refund_reason }}</div>
                                    <div class="flex items-center justify-between pt-1 border-t border-border/30">
                                        @if($rm->clearance_status === 'cleared')
                                            <div class="flex items-center gap-1.5 text-emerald-600 text-[10px] font-semibold">
                                                <x-icon name="shield-check" class="h-3.5 w-3.5" />
                                                <span>{{ __('Cleared: :cert', ['cert' => $rm->clearance_certificate_no]) }}</span>
                                                <a href="{{ route('library.clearance.download', $rm->id) }}" target="_blank" class="text-primary hover:underline ml-1">
                                                    ({{ __('View PDF') }})
                                                </a>
                                            </div>
                                        @elseif($rm->clearance_status === 'rejected')
                                            <div class="flex items-center gap-1 text-rose-600 text-[10px] font-semibold">
                                                <x-icon name="x-circle" class="h-3 w-3" />
                                                <span>{{ __('Clearance Rejected by Librarian') }}</span>
                                            </div>
                                        @else
                                            <div class="flex items-center gap-1 text-amber-600 text-[10px] font-semibold">
                                                <x-icon name="clock" class="h-3 w-3" />
                                                <span>{{ __('Awaiting Librarian Clearance Certificate') }}</span>
                                            </div>
                                        @endif

                                        @if($rm->clearedBy)
                                            <span class="text-[9px] text-muted-foreground">{{ __('By: :name', ['name' => $rm->clearedBy->name]) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <x-ui.input
                        wire:model="approvedAmount"
                        :label="__('Approved Refund Amount (INR)') . ' *'"
                        type="number"
                        step="0.01"
                        required
                    />

                    <x-ui.textarea
                        wire:model="reviewNotes"
                        :label="__('Library Clearance Verification Notes')"
                        rows="2"
                    />
                </div>

                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <x-ui.button wire:click="rejectRefund" variant="destructive" size="sm" wire:loading.attr="disabled">
                        {{ __('Reject Claim') }}
                    </x-ui.button>

                    <div class="flex items-center gap-2">
                        <x-ui.button wire:click="$set('showReviewModal', false)" variant="outline">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button wire:click="approveRefund" wire:loading.attr="disabled" :disabled="$unclearedCount > 0" class="{{ $unclearedCount > 0 ? 'opacity-50 cursor-not-allowed' : '' }}">
                            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                            {{ __('Approve Claim') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Disburse Refund Modal --}}
    @if($showDisburseModal && !empty($selectedDepositIds))
        @php
            $disSelectedModels = \App\Models\LibraryDeposit::with('user')->whereIn('id', $selectedDepositIds)->get();
            $disSelectedTotal = (float) $disSelectedModels->sum(fn ($d) => $d->refund_approved_amount ?: $d->amount);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">{{ __('Process Bank Disbursement / Refund Payout') }}</h3>
                    <button wire:click="$set('showDisburseModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-800 dark:text-emerald-300">
                        <div class="flex justify-between font-bold text-sm">
                            <span>{{ __('Total Payout to Disburse:') }} ({{ $disSelectedModels->count() }})</span>
                            <span class="font-mono font-extrabold text-base">₹{{ number_format($disSelectedTotal, 2) }}</span>
                        </div>
                        <div class="space-y-1.5 max-h-32 overflow-y-auto mt-2 pt-2 border-t border-emerald-500/20">
                            @foreach($disSelectedModels as $dm)
                                <div class="flex items-center justify-between text-[11px]">
                                    <div>
                                        <span class="font-mono font-semibold">{{ $dm->deposit_no }}</span>
                                        <span class="ml-1">{{ $dm->user?->name }}</span>
                                        <span class="block text-[10px] text-muted-foreground font-mono">{{ $dm->upi_id ? 'UPI: '.$dm->upi_id : "A/C: {$dm->account_number} ({$dm->ifsc_code})" }}</span>
                                    </div>
                                    <span class="font-bold">₹{{ number_format($dm->refund_approved_amount ?: $dm->amount, 2) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <x-ui.input
                        wire:model="disbursementRef"
                        :label="__('Bank Transfer UTR / Transaction Reference') . ' *'"
                        required
                    />

                    <div>
                        <label class="block font-medium text-foreground mb-1">{{ __('Payment Advice / Debit Proof Slip') }}</label>
                        <input type="file" wire:model="disbursementProof" class="block w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:opacity-90"/>
                    </div>

                    <x-ui.textarea
                        wire:model="disbursementNotes"
                        :label="__('Disbursement Notes')"
                        rows="2"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showDisburseModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="processDisbursement" wire:loading.attr="disabled" class="bg-emerald-600 hover:bg-emerald-700 text-white">
                        <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                        {{ __('Confirm Disbursement & Debit Ledger') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
