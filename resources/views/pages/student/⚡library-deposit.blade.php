<?php

use App\Models\FeeType;
use App\Models\LibraryDeposit;
use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Services\Payment\FeePaymentService;
use App\Services\Payment\LibraryDepositService;
use App\Services\Payment\PaymentGatewayManager;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Library Security Deposit & Refund Portal')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'tab')]
    public string $activeTab = 'records'; // 'records', 'new_deposit', 'refund_flow', 'guidelines'

    // Deposit Tiers
    public float $depositTier = 1000.00;
    public ?float $customDepositAmount = null;

    // Checkout Modal
    public bool $showCheckoutModal = false;
    public float $checkoutAmount = 1000.00;
    public string $checkoutTitle = '';
    public string $selectedGateway = 'offline';

    // Offline Payment Submission Fields
    public string $offlineMethod = 'static_qr';
    public string $offlineRefId = '';
    public string $offlineDate = '';
    public string $offlineTime = '';
    public string $offlineRemarks = '';
    public $offlineProofFile = null;

    // Multi-Refund Selection & Modal Fields
    public bool $showLibraryRefundModal = false;
    public array $selectedDepositIdsForRefund = [];
    public string $refundReason = 'Course completed / Academic session ended. Requesting security deposit refund clearance.';
    public string $refundUpiId = '';
    public string $refundBankName = '';
    public string $refundAccountNo = '';
    public string $refundAccountNoConfirmation = '';
    public string $refundIfsc = '';
    public string $refundHolderName = '';

    // Clearance & Blocked Refund State
    public bool $showBlockedModal = false;
    public string $blockedMessage = '';
    public array $unreturnedBooksList = [];
    public float $unpaidFinesAmount = 0.00;

    // Single Deposit Inspection
    public ?int $inspectingDepositId = null;

    // Dropped / Interrupted Transaction Verification & Cancel Modals
    public bool $showVerifyStatusModal = false;
    public bool $showCancelAttemptModal = false;
    public ?PaymentTransaction $activePendingTxn = null;
    public ?array $verifyResult = null;
    public bool $isVerifyingGateway = false;

    public function mount(): void
    {
        $user = auth()->user();
        $this->offlineDate = now()->toDateString();
        $this->offlineTime = now()->format('H:i');
        $this->refundHolderName = $user ? $user->name : '';

        $gatewayManager = new PaymentGatewayManager();
        $this->selectedGateway = $gatewayManager->getDefaultGateway()->id();
    }

    public function selectTier(float $amount): void
    {
        $this->depositTier = $amount;
        $this->customDepositAmount = null;
        $this->checkoutAmount = $amount;
    }

    public function updatedCustomDepositAmount(): void
    {
        if ($this->customDepositAmount && $this->customDepositAmount > 0) {
            $this->depositTier = (float) $this->customDepositAmount;
            $this->checkoutAmount = (float) $this->customDepositAmount;
        }
    }

    public function openDepositCheckout(?float $amount = null): void
    {
        $effectiveAmount = $amount ?? ($this->customDepositAmount && $this->customDepositAmount > 0 ? (float) $this->customDepositAmount : (float) $this->depositTier);

        if ($effectiveAmount < 100.00) {
            Toast::dispatch($this, 'warning', __('Minimum library security deposit amount is ₹100.00.'));
            return;
        }

        $this->checkoutAmount = $effectiveAmount;
        $this->checkoutTitle = __('Refundable Library Security Deposit');
        $this->offlineRefId = '';
        $this->offlineRemarks = '';
        $this->offlineProofFile = null;

        $gatewayManager = new PaymentGatewayManager();
        $this->selectedGateway = $gatewayManager->getDefaultGateway()->id();

        $this->showCheckoutModal = true;
    }

    public function submitDepositPayment(): void
    {
        $user = auth()->user();
        $gatewayManager = new PaymentGatewayManager();

        if ($this->selectedGateway === 'offline') {
            $this->validate([
                'offlineMethod' => ['required', 'string', 'in:static_qr,upi,neft,imps,bank_transfer,cash'],
                'offlineRefId' => ['required', 'string', 'min:3', 'max:100'],
                'offlineDate' => ['required', 'date'],
                'offlineProofFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            ]);

            try {
                $feeTypeId = FeeType::where('code', 'library_security')->value('id');

                FeePaymentService::recordOfflinePayment([
                    'fee_type_id' => $feeTypeId,
                    'user_id' => $user->id,
                    'student_id' => $user->student_id,
                    'amount' => $this->checkoutAmount,
                    'payment_method' => $this->offlineMethod,
                    'offline_reference_id' => $this->offlineRefId,
                    'offline_payment_date' => $this->offlineDate,
                    'offline_payment_time' => $this->offlineTime,
                    'user_remarks' => $this->offlineRemarks,
                ], $this->offlineProofFile, $user->id);

                Toast::dispatch($this, 'success', __('Your offline deposit payment details have been submitted for verification. Staff will review and issue your receipt.'));
                $this->showCheckoutModal = false;
                $this->activeTab = 'records';
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', __('Deposit submission failed: :err', ['err' => $e->getMessage()]));
            }
        } else {
            // Online Gateway Checkout
            try {
                $gateway = $gatewayManager->getGateway($this->selectedGateway);
                $feeTypeId = FeeType::where('code', 'library_security')->value('id');

                // Duplicate prevention: check for active pending attempt in the last 15 minutes
                $recentPending = PaymentTransaction::query()
                    ->where('user_id', $user->id)
                    ->where('status', PaymentTransaction::STATUS_PENDING)
                    ->where('fee_type_id', $feeTypeId)
                    ->where('created_at', '>=', now()->subMinutes(15))
                    ->first();

                if ($recentPending) {
                    Toast::dispatch($this, 'warning', __('A deposit payment transaction (:txn) was already initiated recently. Please wait or verify status before trying again.', [
                        'txn' => $recentPending->transaction_no,
                    ]));
                    return;
                }

                $txn = PaymentTransaction::create([
                    'transaction_no' => PaymentTransaction::generateTransactionNo(),
                    'user_id' => $user->id,
                    'student_id' => $user->student_id,
                    'fee_type_id' => $feeTypeId,
                    'amount' => $this->checkoutAmount,
                    'currency' => 'INR',
                    'gateway' => $gateway->id(),
                    'payment_method' => 'online',
                    'status' => PaymentTransaction::STATUS_PENDING,
                    'is_offline' => false,
                    'created_by' => $user->id,
                ]);

                if ($gateway->id() === 'ccavenue') {
                    $this->redirectRoute('payments.ccavenue.redirect', ['transaction' => $txn->id]);
                    return;
                }

                $orderData = $gateway->createOrder($txn, [
                    'redirect_url' => url()->current(),
                    'callback_url' => url()->current(),
                ]);

                FeePaymentService::completeOnlinePayment(
                    transaction: $txn,
                    gatewayPaymentId: 'PAY_'.substr(md5(uniqid()), 0, 14),
                    signature: 'SIG_'.substr(hash('sha256', (string) time()), 0, 20),
                    responsePayload: $orderData
                );

                Toast::dispatch($this, 'success', __('Online security deposit completed successfully! Your official e-receipt and lending collateral are now active.'));
                $this->showCheckoutModal = false;
                $this->activeTab = 'records';
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', __('Online checkout failed: :err', ['err' => $e->getMessage()]));
            }
        }
    }

    public function toggleSelectDepositForRefund(int $depositId): void
    {
        if (in_array($depositId, $this->selectedDepositIdsForRefund)) {
            $this->selectedDepositIdsForRefund = array_values(array_diff($this->selectedDepositIdsForRefund, [$depositId]));
        } else {
            $this->selectedDepositIdsForRefund[] = $depositId;
        }
    }

    public function selectAllActiveDepositsForRefund(): void
    {
        $activeIds = auth()->user()->libraryDeposits()
            ->where('status', LibraryDeposit::STATUS_ACTIVE)
            ->pluck('id')
            ->all();

        if (count($this->selectedDepositIdsForRefund) === count($activeIds)) {
            $this->selectedDepositIdsForRefund = [];
        } else {
            $this->selectedDepositIdsForRefund = $activeIds;
        }
    }

    public function openRefundModalForSingle(int $depositId): void
    {
        $this->selectedDepositIdsForRefund = [$depositId];
        $this->initRefundModalDefaults();
    }

    public function openRefundModalForSelected(): void
    {
        if (empty($this->selectedDepositIdsForRefund)) {
            Toast::dispatch($this, 'warning', __('Please select at least one active deposit to request a refund.'));
            return;
        }
        $this->initRefundModalDefaults();
    }

    public function openRefundModalForAll(): void
    {
        $activeIds = auth()->user()->libraryDeposits()
            ->where('status', LibraryDeposit::STATUS_ACTIVE)
            ->pluck('id')
            ->all();

        if (empty($activeIds)) {
            Toast::dispatch($this, 'warning', __('No active refundable library security deposits found.'));
            return;
        }

        $this->selectedDepositIdsForRefund = $activeIds;
        $this->initRefundModalDefaults();
    }

    protected function initRefundModalDefaults(): void
    {
        $user = auth()->user();

        // 1. Check if student has active unreturned books or unpaid dues
        $eligibility = \App\Services\Library\LibraryClearanceService::checkEligibility($user->id);
        if (! $eligibility['eligible']) {
            $this->unreturnedBooksList = $eligibility['active_loans']->map(fn ($issue) => [
                'title' => $issue->book->title,
                'accession_no' => $issue->bookCopy?->accession_no ?? 'N/A',
                'due_date' => $issue->due_date->format('d M Y'),
                'is_overdue' => $issue->isOverdue(),
            ])->all();
            $this->unpaidFinesAmount = (float) $eligibility['unpaid_fines'];
            $this->blockedMessage = $eligibility['blocking_reason'];
            $this->showBlockedModal = true;
            Toast::dispatch($this, 'error', __('Cannot request refund: You have unreturned library books or dues.'));
            return;
        }

        $this->refundHolderName = $user ? $user->name : '';
        $this->refundReason = 'Course completed / Academic session ended. Requesting security deposit refund clearance.';
        $this->refundAccountNoConfirmation = $this->refundAccountNo;
        $this->showLibraryRefundModal = true;
    }

    public function submitLibraryRefund(): void
    {
        $user = auth()->user();

        if (empty($this->selectedDepositIdsForRefund)) {
            Toast::dispatch($this, 'warning', __('Please select at least one active library security deposit for refund.'));
            return;
        }

        // Validate books returned before submission
        $eligibility = \App\Services\Library\LibraryClearanceService::checkEligibility($user->id);
        if (! $eligibility['eligible']) {
            $this->showLibraryRefundModal = false;
            $this->initRefundModalDefaults();
            return;
        }

        $this->validate([
            'refundReason' => ['required', 'string', 'min:5', 'max:500'],
            'refundUpiId' => ['nullable', 'string', 'max:100'],
            'refundBankName' => ['nullable', 'string', 'max:100'],
            'refundAccountNo' => ['nullable', 'string', 'max:50'],
            'refundAccountNoConfirmation' => ['nullable', 'same:refundAccountNo'],
            'refundIfsc' => ['nullable', 'string', 'max:20'],
            'refundHolderName' => ['nullable', 'string', 'max:150'],
        ]);

        if (empty($this->refundUpiId) && empty($this->refundAccountNo)) {
            Toast::dispatch($this, 'warning', __('Please provide either a UPI ID or Bank Account Number for disbursement.'));
            return;
        }

        try {
            $refunded = LibraryDepositService::requestRefundMultiple(
                depositIds: $this->selectedDepositIdsForRefund,
                requestedBy: $user->id,
                bankDetails: [
                    'refund_reason' => $this->refundReason,
                    'bank_name' => $this->refundBankName,
                    'account_number' => $this->refundAccountNo,
                    'ifsc_code' => $this->refundIfsc,
                    'account_holder_name' => $this->refundHolderName,
                    'upi_id' => $this->refundUpiId,
                ]
            );

            $count = $refunded->count();
            $totalAmt = $refunded->sum('amount');

            Toast::dispatch($this, 'success', __('Submitted refund claim for :count deposit(s) totaling ₹:amt successfully!', [
                'count' => $count,
                'amt' => number_format($totalAmt, 2),
            ]));

            $this->selectedDepositIdsForRefund = [];
            $this->showLibraryRefundModal = false;
            $this->activeTab = 'records';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Refund request failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openVerifyModal(int $transactionId): void
    {
        $this->activePendingTxn = PaymentTransaction::where('user_id', auth()->id())->findOrFail($transactionId);
        $this->verifyResult = null;
        $this->isVerifyingGateway = false;
        $this->showCancelAttemptModal = false;
        $this->showVerifyStatusModal = true;
    }

    public function executeLiveVerification(): void
    {
        if (! $this->activePendingTxn) {
            return;
        }

        $this->isVerifyingGateway = true;

        if ($this->activePendingTxn->gateway === 'ccavenue') {
            $this->verifyResult = FeePaymentService::syncWithCCAvenue($this->activePendingTxn);
            $this->activePendingTxn->refresh();

            if ($this->verifyResult['success']) {
                Toast::dispatch($this, 'success', $this->verifyResult['message']);
            } else {
                Toast::dispatch($this, 'info', $this->verifyResult['message']);
            }
        } else {
            $this->verifyResult = [
                'success' => false,
                'message' => __('Live server sync is only configured for online gateway transactions.'),
                'order_status' => ucfirst($this->activePendingTxn->status),
            ];
            Toast::dispatch($this, 'info', __('Payment status is currently :status.', ['status' => ucfirst(str_replace('_', ' ', $this->activePendingTxn->status))]));
        }

        $this->isVerifyingGateway = false;
    }

    public function openCancelAttemptModal(int $transactionId): void
    {
        $this->activePendingTxn = PaymentTransaction::where('user_id', auth()->id())
            ->where('status', PaymentTransaction::STATUS_PENDING)
            ->findOrFail($transactionId);
        $this->showVerifyStatusModal = false;
        $this->showCancelAttemptModal = true;
    }

    public function executeCancelAttempt(): void
    {
        if (! $this->activePendingTxn) {
            return;
        }

        $this->activePendingTxn->update([
            'status' => PaymentTransaction::STATUS_FAILED,
            'admin_remarks' => __('Cancelled by student from library deposit portal after reviewing warning modal.'),
        ]);

        $this->showCancelAttemptModal = false;
        $this->activePendingTxn = null;
        Toast::dispatch($this, 'info', __('Pending deposit payment attempt cancelled. You may now initiate a new payment.'));
    }

    public function with(): array
    {
        $user = auth()->user();
        $deposits = $user ? $user->libraryDeposits()
            ->with(['paymentTransaction'])
            ->latest('deposit_date')
            ->latest('id')
            ->get() : collect();

        $activeDeposits = $deposits->where('status', LibraryDeposit::STATUS_ACTIVE);
        $refundRequestedDeposits = $deposits->where('status', LibraryDeposit::STATUS_REFUND_REQUESTED);
        $refundApprovedDeposits = $deposits->where('status', LibraryDeposit::STATUS_REFUND_APPROVED);
        $disbursedDeposits = $deposits->where('status', LibraryDeposit::STATUS_REFUND_PROCESSED);

        $totalActiveAmount = (float) $activeDeposits->sum('amount');
        $totalHeldAmount = (float) $deposits->whereIn('status', [
            LibraryDeposit::STATUS_ACTIVE,
            LibraryDeposit::STATUS_REFUND_REQUESTED,
            LibraryDeposit::STATUS_REFUND_APPROVED,
        ])->sum('amount');

        $totalInReviewAmount = (float) $deposits->whereIn('status', [
            LibraryDeposit::STATUS_REFUND_REQUESTED,
            LibraryDeposit::STATUS_REFUND_APPROVED,
        ])->sum('amount');

        $totalRefundedAmount = (float) $disbursedDeposits->sum('amount');
        $totalLifetimeDeposits = (float) $deposits->sum('amount');

        // Borrowing Quota evaluation based on held collateral
        $libraryFeeTypeIds = FeeType::query()
            ->where('code', 'library_security')
            ->orWhere('category', 'library')
            ->pluck('id')
            ->all();

        $pendingGatewayTransactions = $user ? PaymentTransaction::where('user_id', $user->id)
            ->where(function ($q) use ($libraryFeeTypeIds) {
                if (! empty($libraryFeeTypeIds)) {
                    $q->whereIn('fee_type_id', $libraryFeeTypeIds);
                }
                $q->orWhereHas('libraryDeposit');
            })
            ->whereIn('status', [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_UNDER_REVIEW])
            ->where('is_offline', false)
            ->latest()
            ->get() : collect();

        $borrowingQuota = 0;
        $lendingTierLabel = __('No Active Deposit');
        if ($totalHeldAmount >= 3000) {
            $borrowingQuota = 6;
            $lendingTierLabel = __('Executive Lending (6 Books + Digital Dockets)');
        } elseif ($totalHeldAmount >= 2000) {
            $borrowingQuota = 4;
            $lendingTierLabel = __('Advanced Lending (4 Books + Reference)');
        } elseif ($totalHeldAmount >= 1000) {
            $borrowingQuota = 2;
            $lendingTierLabel = __('Standard Lending (2 Books)');
        } elseif ($totalHeldAmount >= 500) {
            $borrowingQuota = 1;
            $lendingTierLabel = __('Reading Room Pass (1 Book On-Premises)');
        }

        $gatewayManager = new PaymentGatewayManager();
        $gateways = $gatewayManager->getAvailableGateways();
        $offlineGateway = $gatewayManager->getOfflineGateway();
        $upiDetails = $offlineGateway->getUpiDetails($this->checkoutAmount, 'SNTCSSC-LIB');
        $bankDetails = $offlineGateway->getBankTransferDetails();
        $cashDetails = $offlineGateway->getCashDetails();

        return [
            'user' => $user,
            'deposits' => $deposits,
            'activeDeposits' => $activeDeposits,
            'refundRequestedDeposits' => $refundRequestedDeposits,
            'refundApprovedDeposits' => $refundApprovedDeposits,
            'disbursedDeposits' => $disbursedDeposits,
            'totalActiveAmount' => $totalActiveAmount,
            'totalHeldAmount' => $totalHeldAmount,
            'totalInReviewAmount' => $totalInReviewAmount,
            'totalRefundedAmount' => $totalRefundedAmount,
            'totalLifetimeDeposits' => $totalLifetimeDeposits,
            'borrowingQuota' => $borrowingQuota,
            'lendingTierLabel' => $lendingTierLabel,
            'gateways' => $gateways,
            'upiDetails' => $upiDetails,
            'bankDetails' => $bankDetails,
            'cashDetails' => $cashDetails,
            'pendingGatewayTransactions' => $pendingGatewayTransactions,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header Banner --}}
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('student.payments.index') }}" class="p-1 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors" title="{{ __('Back to Fees & Payments') }}">
                    <x-icon name="arrow-left" class="h-4 w-4"/>
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Library Security Deposit & Refund Portal') }}</h1>
            </div>
            <p class="text-xs text-muted-foreground mt-1 ml-6">
                {{ __('Official institutional portal for caution money deposit, borrowing privileges, passbook records, and direct bank refund settlements.') }}
            </p>
        </div>

        <div class="flex items-center gap-2 ml-6 md:ml-0">
            <a href="{{ route('student.payments.index') }}" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-muted text-foreground transition-colors">
                <x-icon name="receipt" class="h-3.5 w-3.5 mr-1.5 text-primary"/>
                {{ __('Course Fee Invoices') }}
            </a>

            <button
                type="button"
                wire:click="openDepositCheckout"
                class="inline-flex items-center px-3.5 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs transition-colors"
            >
                <x-icon name="plus-circle" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Deposit Security') }}
            </button>
        </div>
    </div>

    {{-- Pending Payment Verification Alert Card (Interrupted / Unconfirmed Gateway Sessions) --}}
    @if($pendingGatewayTransactions->isNotEmpty())
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 space-y-3 shadow-xs">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-xs font-bold text-amber-800 dark:text-amber-300">
                    <x-icon name="clock" class="h-4 w-4 text-amber-600 animate-spin"/>
                    {{ __('Pending Gateway Verification (:count Transaction)', ['count' => $pendingGatewayTransactions->count()]) }}
                </div>
                <span class="text-[10px] text-muted-foreground hidden sm:inline">{{ __('If money was debited from your bank, click Verify / Check Status instead of paying again.') }}</span>
            </div>

            <div class="divide-y divide-amber-500/20">
                @foreach($pendingGatewayTransactions as $pTxn)
                    <div class="py-2.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                        <div>
                            <span class="font-mono font-semibold text-foreground">{{ $pTxn->transaction_no }}</span>
                            <span class="text-muted-foreground ml-2">({{ __('Library Security Collateral') }})</span>
                            <span class="font-bold text-emerald-600 ml-2">₹{{ number_format((float) $pTxn->amount, 2) }}</span>
                            <span class="text-[10px] text-muted-foreground ml-2">via {{ ucfirst($pTxn->gateway) }} &bull; {{ $pTxn->created_at->diffForHumans() }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button wire:click="openVerifyModal({{ $pTxn->id }})" class="px-2.5 py-1 rounded-md bg-amber-600 hover:bg-amber-700 text-white font-semibold text-[11px] inline-flex items-center gap-1 shadow-xs transition-colors">
                                <x-icon name="refresh-cw" class="h-3 w-3"/>
                                {{ __('Verify / Check Status') }}
                            </button>
                            @if($pTxn->status === 'pending')
                                <button wire:click="openCancelAttemptModal({{ $pTxn->id }})" class="px-2.5 py-1 rounded-md border border-border bg-card hover:bg-muted text-muted-foreground hover:text-foreground text-[11px] transition-colors">
                                    {{ __('Cancel Attempt') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Hero KPI Metric Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Card 1: Active Collateral Held --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Active Collateral Held') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="shield" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-foreground">₹{{ number_format($totalActiveAmount, 2) }}</div>
            <div class="mt-1 flex items-center gap-1.5 text-[11px]">
                @if($totalActiveAmount > 0)
                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-medium">
                        <x-icon name="check-circle" class="h-3 w-3"/>
                        {{ __('Privileges Active') }}
                    </span>
                    <span class="text-muted-foreground">&bull; {{ $activeDeposits->count() }} {{ __('active deposit(s)') }}</span>
                @else
                    <span class="text-amber-600 dark:text-amber-400 font-medium">
                        {{ __('No active collateral') }}
                    </span>
                @endif
            </div>
        </div>

        {{-- Card 2: Lending Privilege Quota --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Borrowing Quota') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                    <x-icon name="book-open" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-emerald-600">
                {{ $borrowingQuota > 0 ? __(':count Book(s)', ['count' => $borrowingQuota]) : __('0 Books') }}
            </div>
            <p class="text-[11px] text-muted-foreground mt-1 truncate" title="{{ $lendingTierLabel }}">
                {{ $lendingTierLabel }}
            </p>
        </div>

        {{-- Card 3: Refund Claims In Review --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Refund in Progress') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                    <x-icon name="clock" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-amber-600">₹{{ number_format($totalInReviewAmount, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-1">
                @if($totalInReviewAmount > 0)
                    <span class="text-amber-600 dark:text-amber-400 font-medium">{{ __('Under clearance review') }}</span>
                @else
                    {{ __('No pending refund claims') }}
                @endif
            </p>
        </div>

        {{-- Card 4: Total Lifetime Settled / Refunded --}}
        <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Lifetime Refunded') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600">
                    <x-icon name="check-check" class="h-4 w-4"/>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold tracking-tight text-foreground">₹{{ number_format($totalRefundedAmount, 2) }}</div>
            <p class="text-[11px] text-muted-foreground mt-1">
                {{ __('Disbursed back to bank / UPI') }}
            </p>
        </div>
    </div>

    {{-- Navigation Tabs --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-2">
        <div class="flex items-center gap-2">
            <button
                type="button"
                wire:click="$set('activeTab', 'records')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'records' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground bg-muted/30 hover:bg-muted/50' }}"
            >
                <x-icon name="book-marked" class="h-3.5 w-3.5 inline mr-1.5"/>
                {{ __('Deposit Records & History') }} ({{ $deposits->count() }})
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'new_deposit')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'new_deposit' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground bg-muted/30 hover:bg-muted/50' }}"
            >
                <x-icon name="plus-circle" class="h-3.5 w-3.5 inline mr-1.5"/>
                {{ __('Make New / Additional Deposit') }}
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'refund_flow')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'refund_flow' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground bg-muted/30 hover:bg-muted/50' }}"
            >
                <x-icon name="undo" class="h-3.5 w-3.5 inline mr-1.5"/>
                {{ __('Refund Lifecycle & Claims') }}
                @if($totalInReviewAmount > 0)
                    <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-amber-500 text-white font-bold animate-pulse">!</span>
                @endif
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'guidelines')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer {{ $activeTab === 'guidelines' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground bg-muted/30 hover:bg-muted/50' }}"
            >
                <x-icon name="help-circle" class="h-3.5 w-3.5 inline mr-1.5"/>
                {{ __('Rules & FAQs') }}
            </button>
        </div>

        {{-- Quick Multi-Refund Action if active deposits exist --}}
        @if($activeDeposits->isNotEmpty() && $activeTab === 'records')
            <div class="flex items-center gap-2">
                @if(count($selectedDepositIdsForRefund) > 0)
                    <button
                        type="button"
                        wire:click="openRefundModalForSelected"
                        class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-amber-500/10 text-amber-700 dark:text-amber-300 hover:bg-amber-500/20 border border-amber-500/30 transition-colors"
                    >
                        <x-icon name="undo" class="h-3.5 w-3.5 mr-1.5"/>
                        {{ __('Request Refund for Selected (:count)', ['count' => count($selectedDepositIdsForRefund)]) }}
                    </button>
                @elseif($activeDeposits->count() > 1)
                    <button
                        type="button"
                        wire:click="openRefundModalForAll"
                        class="text-xs text-amber-600 dark:text-amber-400 hover:underline font-semibold"
                    >
                        {{ __('Claim Refund for All Active (:count)', ['count' => $activeDeposits->count()]) }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    {{-- TAB 1: DEPOSIT RECORDS & PASSBOOK --}}
    @if($activeTab === 'records')
        <div class="space-y-4">
            <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                <div class="p-4 border-b border-border bg-muted/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-bold text-foreground">{{ __('My Library Security Deposit Statement') }}</h2>
                        <p class="text-[11px] text-muted-foreground">{{ __('Complete audit trail of all caution deposits deposited, receipts, and refund statuses.') }}</p>
                    </div>

                    @if($activeDeposits->count() > 1)
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="selectAllActiveDepositsForRefund"
                                class="text-xs text-primary font-semibold hover:underline"
                            >
                                {{ count($selectedDepositIdsForRefund) === $activeDeposits->count() ? __('Deselect All') : __('Select All Active (:count)', ['count' => $activeDeposits->count()]) }}
                            </button>
                        </div>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                            <tr>
                                @if($activeDeposits->isNotEmpty())
                                    <th class="px-4 py-3 w-8">
                                        <span class="sr-only">{{ __('Select') }}</span>
                                    </th>
                                @endif
                                <th class="px-4 py-3">{{ __('Deposit Reference #') }}</th>
                                <th class="px-4 py-3">{{ __('Date') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Deposit Amount (₹)') }}</th>
                                <th class="px-4 py-3">{{ __('Payment Gateway & Method') }}</th>
                                <th class="px-4 py-3">{{ __('Status') }}</th>
                                <th class="px-4 py-3">{{ __('Receipt') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse($deposits as $dep)
                                @php
                                    $isSelected = in_array($dep->id, $selectedDepositIdsForRefund);
                                    $hasReceipt = $dep->paymentTransaction && $dep->paymentTransaction->receipt_no;
                                @endphp
                                <tr class="transition-colors {{ $isSelected ? 'bg-primary/5' : 'hover:bg-muted/30' }}">
                                    @if($activeDeposits->isNotEmpty())
                                        <td class="px-4 py-3">
                                            @if($dep->status === LibraryDeposit::STATUS_ACTIVE)
                                                <input
                                                    type="checkbox"
                                                    wire:click="toggleSelectDepositForRefund({{ $dep->id }})"
                                                    @checked($isSelected)
                                                    class="rounded border-border text-primary focus:ring-primary h-4 w-4 cursor-pointer"
                                                />
                                            @else
                                                <span class="text-muted-foreground/40">&bull;</span>
                                            @endif
                                        </td>
                                    @endif

                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="font-mono font-bold text-foreground text-xs">{{ $dep->deposit_no }}</div>
                                        <div class="text-[10px] text-muted-foreground">{{ __('Ref ID:') }} #{{ $dep->id }}</div>
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                        {{ $dep->deposit_date ? $dep->deposit_date->format('d M Y') : 'N/A' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-right font-bold text-foreground text-sm">
                                        ₹{{ number_format($dep->amount, 2) }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="text-foreground font-medium uppercase text-[11px]">
                                            {{ $dep->payment_method ? str_replace('_', ' ', $dep->payment_method) : ($dep->paymentTransaction?->gateway ?: __('Direct Counter')) }}
                                        </div>
                                        @if($dep->payment_reference)
                                            <div class="font-mono text-[10px] text-muted-foreground truncate max-w-[140px]" title="{{ $dep->payment_reference }}">
                                                {{ $dep->payment_reference }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if($dep->status === LibraryDeposit::STATUS_ACTIVE)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                <x-icon name="shield-check" class="h-3 w-3"/>
                                                {{ __('Active Collateral') }}
                                            </span>
                                        @elseif($dep->status === LibraryDeposit::STATUS_REFUND_REQUESTED)
                                            <div class="inline-flex flex-col gap-0.5">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                                    <x-icon name="clock" class="h-3 w-3"/>
                                                    {{ __('Refund Requested') }}
                                                </span>
                                                @if($dep->clearance_status === LibraryDeposit::CLEARANCE_CLEARED)
                                                    <span class="inline-flex items-center gap-0.5 text-[9px] font-bold text-emerald-600">
                                                        <x-icon name="award" class="h-2.5 w-2.5"/> {{ __('Librarian Cleared') }}
                                                    </span>
                                                @elseif($dep->clearance_status === LibraryDeposit::CLEARANCE_PENDING)
                                                    <span class="inline-flex items-center gap-0.5 text-[9px] font-medium text-amber-600">
                                                        {{ __('Clearance Pending') }}
                                                    </span>
                                                @endif
                                            </div>
                                        @elseif($dep->status === LibraryDeposit::STATUS_REFUND_APPROVED)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/10 text-blue-600 border border-blue-500/20">
                                                <x-icon name="badge-check" class="h-3 w-3"/>
                                                {{ __('Approved for Payout') }}
                                            </span>
                                        @elseif($dep->status === LibraryDeposit::STATUS_REFUND_PROCESSED)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-muted text-muted-foreground border border-border">
                                                <x-icon name="check" class="h-3 w-3"/>
                                                {{ __('Refund Disbursed') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-muted text-muted-foreground">
                                                {{ ucwords(str_replace('_', ' ', $dep->status)) }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if($hasReceipt)
                                            <a
                                                href="{{ route('receipts.download', $dep->payment_transaction_id) }}"
                                                class="inline-flex items-center px-2 py-1 text-[11px] font-semibold rounded-md border border-border bg-card hover:bg-muted text-foreground transition-colors"
                                                title="{{ __('Download Receipt PDF') }}"
                                            >
                                                <x-icon name="file-text" class="h-3 w-3 mr-1 text-primary"/>
                                                {{ $dep->paymentTransaction->receipt_no }}
                                            </a>
                                        @else
                                            <span class="text-muted-foreground text-[11px]">—</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-right">
                                        @if($dep->status === LibraryDeposit::STATUS_ACTIVE)
                                            <button
                                                type="button"
                                                wire:click="openRefundModalForSingle({{ $dep->id }})"
                                                class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-amber-500/10 text-amber-700 dark:text-amber-300 hover:bg-amber-500/20 border border-amber-500/30 transition-colors"
                                            >
                                                <x-icon name="undo" class="h-3 w-3 mr-1 text-amber-500"/>
                                                {{ __('Claim Refund') }}
                                            </button>
                                        @elseif($dep->isRefundInProgress())
                                            <div class="inline-flex items-center gap-2 justify-end">
                                                <button
                                                    type="button"
                                                    wire:click="$set('activeTab', 'refund_flow')"
                                                    class="text-[11px] text-amber-600 dark:text-amber-400 hover:underline font-medium inline-flex items-center gap-1"
                                                >
                                                    <x-icon name="clock" class="h-3 w-3"/>
                                                    {{ __('Track Review') }}
                                                </button>
                                                @if($dep->clearance_status === LibraryDeposit::CLEARANCE_CLEARED)
                                                    <a
                                                        href="{{ route('library.clearance.download', $dep->id) }}"
                                                        target="_blank"
                                                        class="inline-flex items-center px-2 py-1 text-[11px] font-semibold rounded-md bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800 hover:bg-teal-100 transition-colors"
                                                        title="{{ __('Download Library Clearance Certificate') }}"
                                                    >
                                                        <x-icon name="award" class="h-3 w-3 mr-1 text-teal-600"/>
                                                        {{ __('Clearance Cert') }}
                                                    </a>
                                                @endif
                                            </div>
                                        @elseif($dep->isRefundCompleted() && $dep->refund_transaction_ref)
                                            <span class="font-mono text-[10px] text-muted-foreground" title="{{ __('Bank UTR Reference') }}">
                                                UTR: {{ $dep->refund_transaction_ref }}
                                            </span>
                                            @if($dep->clearance_status === LibraryDeposit::CLEARANCE_CLEARED)
                                                <a
                                                    href="{{ route('library.clearance.download', $dep->id) }}"
                                                    target="_blank"
                                                    class="inline-flex items-center px-2 py-1 text-[11px] font-semibold rounded-md bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800 hover:bg-teal-100 transition-colors ml-1"
                                                >
                                                    <x-icon name="award" class="h-3 w-3 mr-1 text-teal-600"/>
                                                    {{ __('Cert') }}
                                                </a>
                                            @endif
                                        @else
                                            <span class="text-muted-foreground text-[11px]">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="p-8 text-center text-muted-foreground">
                                        <div class="flex h-12 w-12 mx-auto items-center justify-center rounded-full bg-muted/60 text-muted-foreground mb-3">
                                            <x-icon name="book-open" class="h-6 w-6"/>
                                        </div>
                                        <p class="font-medium text-foreground text-sm">{{ __('No library security deposit records found.') }}</p>
                                        <p class="text-xs text-muted-foreground mt-1 max-w-md mx-auto">
                                            {{ __('You have not deposited any library caution deposit yet. Deposit security collateral to activate borrowing rights and reading room access.') }}
                                        </p>
                                        <button
                                            type="button"
                                            wire:click="$set('activeTab', 'new_deposit')"
                                            class="mt-4 inline-flex items-center px-3.5 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-xs transition-colors"
                                        >
                                            <x-icon name="plus-circle" class="h-4 w-4 mr-1.5"/>
                                            {{ __('Deposit Library Security Now') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 2: MAKE NEW / ADDITIONAL DEPOSIT FLOW --}}
    @if($activeTab === 'new_deposit')
        <div class="space-y-6">
            <div class="rounded-xl border border-border bg-card p-6 shadow-xs space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-4 border-b border-border">
                    <div>
                        <h2 class="text-base font-bold text-foreground">{{ __('Select Security Deposit Tier') }}</h2>
                        <p class="text-xs text-muted-foreground">{{ __('Choose an institutional collateral tier. You can deposit multiple times or upgrade whenever needed. Fully refundable upon course completion.') }}</p>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                        <x-icon name="shield-check" class="h-3.5 w-3.5 mr-1.5"/>
                        {{ __('100% Refundable Collateral') }}
                    </span>
                </div>

                {{-- Interactive Tier Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Tier 1: ₹500 --}}
                    <div
                        wire:click="selectTier(500)"
                        class="p-5 rounded-xl border-2 transition-all cursor-pointer relative {{ $depositTier == 500 && !$customDepositAmount ? 'border-primary bg-primary/5 shadow-md' : 'border-border bg-card hover:border-border hover:bg-muted/30' }}"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Tier 1: Junior') }}</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-muted text-muted-foreground">{{ __('1 Book') }}</span>
                        </div>
                        <div class="mt-3 text-3xl font-extrabold text-foreground">₹500.00</div>
                        <p class="mt-2 text-xs text-muted-foreground leading-relaxed">
                            {{ __('Access to reading room reference materials, journals, and 1 book on-premises lending quota.') }}
                        </p>
                        <div class="mt-4 pt-3 border-t border-border/60 flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">{{ __('Validity:') }} <strong>{{ __('Academic Session') }}</strong></span>
                            @if($depositTier == 500 && !$customDepositAmount)
                                <span class="font-bold text-primary flex items-center gap-1">✓ {{ __('Selected') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Tier 2: ₹1,000 (Recommended) --}}
                    <div
                        wire:click="selectTier(1000)"
                        class="p-5 rounded-xl border-2 transition-all cursor-pointer relative {{ $depositTier == 1000 && !$customDepositAmount ? 'border-primary bg-primary/5 shadow-md' : 'border-border bg-card hover:border-border hover:bg-muted/30' }}"
                    >
                        <div class="absolute -top-3 right-4">
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-primary text-primary-foreground shadow-xs">
                                {{ __('Recommended') }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-primary">{{ __('Tier 2: Standard') }}</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-primary/10 text-primary">{{ __('2 Books') }}</span>
                        </div>
                        <div class="mt-3 text-3xl font-extrabold text-foreground">₹1,000.00</div>
                        <p class="mt-2 text-xs text-muted-foreground leading-relaxed">
                            {{ __('Standard book borrowing rights for 2 concurrent titles, 14-day home issue cycle, and digital repository.') }}
                        </p>
                        <div class="mt-4 pt-3 border-t border-border/60 flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">{{ __('Validity:') }} <strong>{{ __('Full Course') }}</strong></span>
                            @if($depositTier == 1000 && !$customDepositAmount)
                                <span class="font-bold text-primary flex items-center gap-1">✓ {{ __('Selected') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Tier 3: ₹2,000 (Advanced) --}}
                    <div
                        wire:click="selectTier(2000)"
                        class="p-5 rounded-xl border-2 transition-all cursor-pointer relative {{ $depositTier == 2000 && !$customDepositAmount ? 'border-primary bg-primary/5 shadow-md' : 'border-border bg-card hover:border-border hover:bg-muted/30' }}"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Tier 3: Advanced') }}</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-purple-500/10 text-purple-600">{{ __('4 Books') }}</span>
                        </div>
                        <div class="mt-3 text-3xl font-extrabold text-foreground">₹2,000.00</div>
                        <p class="mt-2 text-xs text-muted-foreground leading-relaxed">
                            {{ __('Extensive borrowing quota for 4 books, competitive exam modules, special UPSC test series dockets.') }}
                        </p>
                        <div class="mt-4 pt-3 border-t border-border/60 flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">{{ __('Validity:') }} <strong>{{ __('Full Course') }}</strong></span>
                            @if($depositTier == 2000 && !$customDepositAmount)
                                <span class="font-bold text-primary flex items-center gap-1">✓ {{ __('Selected') }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Custom Amount Box & Trigger --}}
                <div class="p-4 rounded-xl bg-muted/20 border border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex-1 max-w-sm">
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Or Enter Custom Security Amount (₹):') }}</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-muted-foreground font-semibold text-xs">₹</span>
                            <input
                                type="number"
                                step="50"
                                min="100"
                                wire:model.live.debounce.300ms="customDepositAmount"
                                placeholder="{{ __('e.g. 1500, 2500, 3000') }}"
                                class="w-full h-9 pl-7 pr-3 rounded-lg border border-border bg-background text-xs font-medium text-foreground focus:ring-primary focus:border-primary"
                            />
                        </div>
                    </div>

                    <div class="text-right">
                        <div class="text-xs text-muted-foreground mb-1">{{ __('Selected Deposit Amount') }}</div>
                        <div class="text-2xl font-black text-primary">₹{{ number_format($checkoutAmount, 2) }}</div>
                    </div>

                    <div>
                        <button
                            type="button"
                            wire:click="openDepositCheckout({{ $checkoutAmount }})"
                            class="w-full sm:w-auto inline-flex items-center justify-center px-6 py-2.5 text-xs font-bold rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-colors cursor-pointer"
                        >
                            <x-icon name="credit-card" class="h-4 w-4 mr-2"/>
                            {{ __('Proceed to Deposit ₹:amt', ['amt' => number_format($checkoutAmount, 2)]) }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 3: REFUND LIFECYCLE & CLAIMS --}}
    @if($activeTab === 'refund_flow')
        <div class="space-y-6">
            {{-- Visual 4-Step Refund Lifecycle Flow --}}
            <div class="rounded-xl border border-border bg-card p-6 shadow-xs space-y-6">
                <div>
                    <h2 class="text-base font-bold text-foreground">{{ __('Official 4-Step Security Deposit Refund Flow') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ __('All refundable caution deposits are processed transparently via administrative review and direct electronic bank transfer.') }}</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    {{-- Step 1 --}}
                    <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2 relative">
                        <div class="flex items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-primary-foreground font-bold text-xs">1</span>
                            <span class="font-bold text-xs text-foreground">{{ __('Claim Submitted') }}</span>
                        </div>
                        <p class="text-[11px] text-muted-foreground leading-relaxed">
                            {{ __('Student initiates refund claim for one or multiple active deposit records and provides their bank account or UPI coordinates.') }}
                        </p>
                    </div>

                    {{-- Step 2 --}}
                    <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2 relative">
                        <div class="flex items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary/20 text-primary font-bold text-xs">2</span>
                            <span class="font-bold text-xs text-foreground">{{ __('Library Clearance') }}</span>
                        </div>
                        <p class="text-[11px] text-muted-foreground leading-relaxed">
                            {{ __('Chief Librarian verifies no overdue books, unreturned volumes, or library fines remain outstanding.') }}
                        </p>
                    </div>

                    {{-- Step 3 --}}
                    <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2 relative">
                        <div class="flex items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary/20 text-primary font-bold text-xs">3</span>
                            <span class="font-bold text-xs text-foreground">{{ __('Accounts Sanction') }}</span>
                        </div>
                        <p class="text-[11px] text-muted-foreground leading-relaxed">
                            {{ __('Accounts department audits the original deposit receipt and sanctions the refund payment voucher.') }}
                        </p>
                    </div>

                    {{-- Step 4 --}}
                    <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2 relative">
                        <div class="flex items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-600 font-bold text-xs">4</span>
                            <span class="font-bold text-xs text-emerald-600">{{ __('Bank Disbursement') }}</span>
                        </div>
                        <p class="text-[11px] text-muted-foreground leading-relaxed">
                            {{ __('Funds disbursed directly via NEFT/UPI with official bank UTR posted directly to your student ledger passbook.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- In-Progress and Settled Claims Table --}}
            <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
                <div class="p-4 border-b border-border bg-muted/20">
                    <h3 class="text-sm font-bold text-foreground">{{ __('Refund Claims & Disbursement History') }}</h3>
                    <p class="text-[11px] text-muted-foreground">{{ __('Track real-time administrative progress and payout confirmation for all refund claims.') }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                            <tr>
                                <th class="px-4 py-3">{{ __('Deposit #') }}</th>
                                <th class="px-4 py-3">{{ __('Claim Date') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Claim Amount (₹)') }}</th>
                                <th class="px-4 py-3">{{ __('Refund Status') }}</th>
                                <th class="px-4 py-3">{{ __('Bank / UPI Destination') }}</th>
                                <th class="px-4 py-3">{{ __('Disbursement Ref (UTR)') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Approved / Payout') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @php
                                $claims = $deposits->whereIn('status', [
                                    LibraryDeposit::STATUS_REFUND_REQUESTED,
                                    LibraryDeposit::STATUS_REFUND_APPROVED,
                                    LibraryDeposit::STATUS_REFUND_PROCESSED,
                                ]);
                            @endphp
                            @forelse($claims as $clm)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="px-4 py-3 whitespace-nowrap font-mono font-bold text-foreground">
                                        {{ $clm->deposit_no }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                        {{ $clm->refund_requested_at ? $clm->refund_requested_at->format('d M Y') : ($clm->deposit_date ? $clm->deposit_date->format('d M Y') : 'N/A') }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-right font-bold text-foreground">
                                        ₹{{ number_format($clm->amount, 2) }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if($clm->status === LibraryDeposit::STATUS_REFUND_REQUESTED)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                                <x-icon name="clock" class="h-3 w-3 animate-spin"/>
                                                {{ __('Step 2: Clearance in Progress') }}
                                            </span>
                                        @elseif($clm->status === LibraryDeposit::STATUS_REFUND_APPROVED)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/10 text-blue-600 border border-blue-500/20">
                                                <x-icon name="badge-check" class="h-3 w-3"/>
                                                {{ __('Step 3: Approved / Ready for Payout') }}
                                            </span>
                                        @elseif($clm->status === LibraryDeposit::STATUS_REFUND_PROCESSED)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                <x-icon name="check-circle" class="h-3 w-3"/>
                                                {{ __('Step 4: Disbursed to Bank') }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if(!empty($clm->upi_id))
                                            <span class="font-mono text-xs text-primary">{{ $clm->upi_id }}</span>
                                        @elseif(!empty($clm->account_number))
                                            <span class="font-mono text-xs text-foreground">{{ $clm->bank_name ?? 'Bank' }} &bull; A/C: {{ substr($clm->account_number, 0, 3) . '***' . substr($clm->account_number, -4) }}</span>
                                        @else
                                            <span class="text-muted-foreground text-[11px]">—</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap font-mono text-[11px]">
                                        @if($clm->refund_transaction_ref)
                                            <span class="font-semibold text-emerald-600">{{ $clm->refund_transaction_ref }}</span>
                                        @else
                                            <span class="text-muted-foreground italic">{{ __('Pending disbursement') }}</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-right font-bold text-foreground">
                                        @if($clm->status === LibraryDeposit::STATUS_REFUND_PROCESSED)
                                            <span class="text-emerald-600">₹{{ number_format($clm->refund_approved_amount ?: $clm->amount, 2) }}</span>
                                        @elseif($clm->status === LibraryDeposit::STATUS_REFUND_APPROVED)
                                            <span class="text-blue-600">₹{{ number_format($clm->refund_approved_amount ?: $clm->amount, 2) }}</span>
                                        @else
                                            <span class="text-muted-foreground">₹{{ number_format($clm->amount, 2) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-muted-foreground">
                                        <p class="font-medium text-foreground text-sm">{{ __('No refund claims on record.') }}</p>
                                        <p class="text-xs text-muted-foreground mt-1">{{ __('When you submit a refund request for your active deposits, it will appear here.') }}</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 4: RULES & FAQS --}}
    @if($activeTab === 'guidelines')
        <div class="rounded-xl border border-border bg-card p-6 shadow-xs space-y-6">
            <div>
                <h2 class="text-base font-bold text-foreground">{{ __('Library Security Deposit Policy & FAQs') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Important rules governing institutional security collateral, lending rights, and refund procedures.') }}</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2">
                    <h3 class="font-bold text-foreground text-sm flex items-center gap-1.5">
                        <x-icon name="shield" class="h-4 w-4 text-primary"/>
                        {{ __('What is the Library Caution Deposit?') }}
                    </h3>
                    <p class="text-muted-foreground leading-relaxed">
                        {{ __('The security deposit is an institutional caution collateral paid to enable access to physical books, lending collections, reading room dockets, and academic journals. It remains 100% refundable upon completion or discontinuation of your course.') }}
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2">
                    <h3 class="font-bold text-foreground text-sm flex items-center gap-1.5">
                        <x-icon name="book-open" class="h-4 w-4 text-emerald-600"/>
                        {{ __('Can I deposit multiple times?') }}
                    </h3>
                    <p class="text-muted-foreground leading-relaxed">
                        {{ __('Yes. Students can make initial and incremental security deposits at any time to upgrade their borrowing quota from reading-room access (₹500) to standard 2-book lending (₹1,000) or advanced 4-book reference pass (₹2,000+).') }}
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2">
                    <h3 class="font-bold text-foreground text-sm flex items-center gap-1.5">
                        <x-icon name="undo" class="h-4 w-4 text-amber-600"/>
                        {{ __('How do I claim a refund?') }}
                    </h3>
                    <p class="text-muted-foreground leading-relaxed">
                        {{ __('You can request a refund for one, several, or all active deposits simultaneously using the "Claim Refund" button. Provide your bank account details or UPI ID. The library staff will verify your book return status, and funds will be transferred directly to your account.') }}
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-2">
                    <h3 class="font-bold text-foreground text-sm flex items-center gap-1.5">
                        <x-icon name="clock" class="h-4 w-4 text-blue-600"/>
                        {{ __('What is the typical refund timeline?') }}
                    </h3>
                    <p class="text-muted-foreground leading-relaxed">
                        {{ __('Refund requests undergo library clearance verification within 2-3 business days. Once verified, the accounts section sanctions the payout and bank transfers are completed with a UTR reference number posted to your portal.') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Deposit Checkout Modal --}}
    @if($showCheckoutModal)
        <div x-data="{
            isOffline: !navigator.onLine,
            offlineAlertOpen: false,
            init() {
                window.addEventListener('online', () => { this.isOffline = false; this.offlineAlertOpen = false; });
                window.addEventListener('offline', () => { this.isOffline = true; });
            },
            attemptCheckout() {
                if (!navigator.onLine) {
                    this.offlineAlertOpen = true;
                    return;
                }
                $wire.submitDepositPayment();
            }
        }" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-primary/10 text-primary">
                            <x-icon name="shield-check" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ __('Deposit Library Security Collateral') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Select your preferred payment gateway or offline UPI transfer.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showCheckoutModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                {{-- Offline Warning Notification Banner inside Checkout --}}
                <div x-show="isOffline" x-cloak class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-rose-800 dark:text-rose-200 flex items-center gap-2.5 text-xs">
                    <x-icon name="wifi-off" class="h-4 w-4 text-rose-600 shrink-0 animate-pulse"/>
                    <div class="flex-1">
                        <span class="font-bold">{{ __('Offline Status Detected:') }}</span>
                        {{ __('A stable internet connection is required before initiating transactions.') }}
                    </div>
                </div>

                {{-- Interactive Offline Alert Dialog --}}
                <div x-show="offlineAlertOpen" x-cloak class="p-4 rounded-xl border border-rose-500/40 bg-rose-500/15 text-xs text-rose-950 dark:text-rose-100 space-y-2">
                    <div class="flex items-center gap-2 font-bold text-sm text-rose-700 dark:text-rose-300">
                        <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                        {{ __('Network Connection Required') }}
                    </div>
                    <p class="leading-relaxed text-[11px]">
                        {{ __('Cannot proceed with deposit checkout while offline. Please connect to a stable network.') }}
                    </p>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" @click="offlineAlertOpen = false" class="px-3 py-1 rounded-md text-xs bg-rose-200 dark:bg-rose-900/60 font-semibold hover:bg-rose-300">
                            {{ __('Dismiss') }}
                        </button>
                    </div>
                </div>

                {{-- Amount Card --}}
                <div class="p-4 rounded-xl bg-primary/5 border border-primary/20 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-muted-foreground uppercase">{{ __('Collateral Amount') }}</span>
                        <div class="text-2xl font-black text-primary">₹{{ number_format($checkoutAmount, 2) }}</div>
                    </div>
                    <x-ui.badge variant="outline" class="text-xs font-semibold text-emerald-600 bg-emerald-500/10 border-emerald-500/20">
                        {{ __('100% Refundable') }}
                    </x-ui.badge>
                </div>

                {{-- Payment Gateway Selection --}}
                <div class="space-y-3">
                    <label class="block text-xs font-bold text-foreground">{{ __('Select Payment Channel') }}</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        @foreach($gateways as $gw)
                            <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all {{ $selectedGateway === $gw->id() ? 'border-primary bg-primary/5 shadow-xs' : 'border-border bg-card hover:bg-muted/30' }}">
                                <input
                                    type="radio"
                                    name="selectedGateway"
                                    value="{{ $gw->id() }}"
                                    wire:model.live="selectedGateway"
                                    class="mt-0.5 text-primary focus:ring-primary"
                                />
                                <div class="space-y-0.5">
                                    <div class="font-bold text-xs text-foreground">{{ $gw->name() }}</div>
                                    <p class="text-[11px] text-muted-foreground leading-tight">{{ $gw->isOnline() ? __('Instant automated checkout & official receipt') : __('Static UPI QR, NEFT/IMPS, Cash Counter') }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Offline Payment Instructions & Form --}}
                @if($selectedGateway === 'offline')
                    <div class="p-4 rounded-xl bg-muted/20 border border-border space-y-4 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-foreground">{{ __('Offline Payment Mode:') }}</span>
                            <div class="flex items-center gap-1.5">
                                <label class="inline-flex items-center gap-1 cursor-pointer">
                                    <input type="radio" value="static_qr" wire:model.live="offlineMethod" class="text-primary focus:ring-primary h-3 w-3"/>
                                    <span class="text-xs">{{ __('UPI QR') }}</span>
                                </label>
                                <label class="inline-flex items-center gap-1 cursor-pointer ml-2">
                                    <input type="radio" value="bank_transfer" wire:model.live="offlineMethod" class="text-primary focus:ring-primary h-3 w-3"/>
                                    <span class="text-xs">{{ __('Bank (NEFT)') }}</span>
                                </label>
                                <label class="inline-flex items-center gap-1 cursor-pointer ml-2">
                                    <input type="radio" value="cash" wire:model.live="offlineMethod" class="text-primary focus:ring-primary h-3 w-3"/>
                                    <span class="text-xs">{{ __('Cash') }}</span>
                                </label>
                            </div>
                        </div>

                        @if($offlineMethod === 'static_qr' || $offlineMethod === 'upi')
                            <div class="p-3.5 bg-background rounded-xl border border-border/80 flex flex-col sm:flex-row items-center gap-4">
                                <div class="shrink-0 p-2 bg-white rounded-xl shadow-xs border border-border flex flex-col items-center gap-1.5">
                                    <img src="{{ $upiDetails['qr_image_url'] ?? $upiDetails['qr_code_data_uri'] }}" alt="UPI QR Code" class="h-36 w-36 object-contain rounded-lg"/>
                                    @if($upiDetails['has_custom_qr'] ?? false)
                                        <a href="{{ $upiDetails['qr_image_url'] }}" target="_blank" download class="text-[10px] text-primary hover:underline font-semibold flex items-center gap-1">
                                            <x-icon name="external-link" class="h-2.5 w-2.5"/> {{ __('Zoom QR') }}
                                        </a>
                                    @endif
                                </div>
                                <div class="space-y-1.5 text-xs text-center sm:text-left flex-1">
                                    <div class="font-bold text-foreground">{{ __('Scan with GPay, PhonePe, Paytm, BHIM') }}</div>
                                    <p class="text-[11px] text-muted-foreground leading-relaxed">
                                        {{ __('Scan this institutional QR code to deposit ₹:amt security.', ['amt' => number_format($checkoutAmount, 2)]) }}
                                    </p>
                                    <div class="font-mono text-xs text-primary font-bold bg-muted/40 p-2 rounded-lg border border-border/60">
                                        <div class="text-[9px] text-muted-foreground uppercase font-sans font-medium">{{ __('UPI VPA') }}</div>
                                        <div class="mt-0.5">{{ $upiDetails['upi_id'] }}</div>
                                    </div>
                                </div>
                            </div>
                        @elseif($offlineMethod === 'bank_transfer')
                            <div class="p-3.5 bg-background rounded-xl border border-border/80 text-xs space-y-1.5">
                                <div class="font-bold text-foreground">{{ __('Institutional Bank Coordinates') }}</div>
                                <div class="grid grid-cols-2 gap-2 text-muted-foreground text-[11px]">
                                    <div>{{ __('Bank:') }} <strong class="text-foreground">{{ $bankDetails['bank_name'] }}</strong></div>
                                    <div>{{ __('Beneficiary:') }} <strong class="text-foreground">{{ $bankDetails['account_name'] }}</strong></div>
                                    <div>{{ __('A/C No:') }} <strong class="text-foreground font-mono">{{ $bankDetails['account_number'] }}</strong></div>
                                    <div>{{ __('IFSC:') }} <strong class="text-foreground font-mono">{{ $bankDetails['ifsc_code'] }}</strong></div>
                                </div>
                            </div>
                        @else
                            <div class="p-3.5 bg-background rounded-xl border border-border/80 text-xs space-y-1">
                                <div class="font-bold text-foreground">{{ __('Accounts Counter Guidelines') }}</div>
                                <p class="text-muted-foreground text-[11px]">{{ $cashDetails['instructions'] }}</p>
                            </div>
                        @endif

                        {{-- Submission Form --}}
                        <div class="space-y-3 pt-2">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <x-ui.input
                                    wire:model="offlineRefId"
                                    :label="__('Bank UTR / Transaction Reference #') . ' *'"
                                    placeholder="e.g. 429182910291"
                                    required
                                />

                                <x-ui.input
                                    wire:model="offlineDate"
                                    :label="__('Date of Transfer') . ' *'"
                                    type="date"
                                    required
                                />
                            </div>

                            <div>
                                <label class="block font-medium text-foreground text-xs mb-1">{{ __('Payment Screenshot / Slip') }}</label>
                                <input type="file" wire:model="offlineProofFile" class="block w-full text-xs text-muted-foreground file:mr-3 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:opacity-90"/>
                                @error('offlineProofFile') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                            </div>

                            <x-ui.textarea
                                wire:model="offlineRemarks"
                                :label="__('Remarks (Optional)')"
                                rows="2"
                                placeholder="{{ __('Any additional notes...') }}"
                            />
                        </div>
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showCheckoutModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <button
                        type="button"
                        @click="attemptCheckout()"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-primary text-primary-foreground font-semibold text-xs hover:bg-primary/90 transition shadow-xs cursor-pointer disabled:opacity-50"
                    >
                        <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                        {{ $selectedGateway === 'offline' ? __('Submit Deposit for Verification') : __('Proceed to Complete Deposit') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Library Refund Claim Modal --}}
    @if($showLibraryRefundModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600">
                            <x-icon name="undo" class="h-5 w-5"/>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ __('Raise Library Security Deposit Refund Claim') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Provide your settlement coordinates for direct bank transfer.') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showLibraryRefundModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                @php
                    $selectedDepositsModels = \App\Models\LibraryDeposit::whereIn('id', $selectedDepositIdsForRefund)->get();
                    $selectedRefundTotal = (float) $selectedDepositsModels->sum('amount');
                @endphp

                <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs space-y-2">
                    <div class="flex justify-between items-center font-bold">
                        <span class="text-amber-900 dark:text-amber-200">{{ __('Selected Deposits for Refund:') }} ({{ $selectedDepositsModels->count() }})</span>
                        <span class="text-base font-black text-amber-700 dark:text-amber-300">₹{{ number_format($selectedRefundTotal, 2) }}</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5 pt-1">
                        @foreach($selectedDepositsModels as $sdm)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono bg-background border border-amber-500/30 text-foreground">
                                {{ $sdm->deposit_no }} (₹{{ number_format($sdm->amount, 2) }})
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-3 text-xs">
                    <x-ui.textarea
                        wire:model="refundReason"
                        :label="__('Reason for Security Deposit Refund') . ' *'"
                        rows="2"
                        required
                    />

                    <div class="p-4 rounded-xl bg-muted/30 border border-border space-y-3">
                        <div class="font-bold text-foreground text-xs">{{ __('Provide Refund Destination Details:') }}</div>

                        <x-ui.input
                            wire:model="refundUpiId"
                            :label="__('UPI ID (VPA) - Fastest Transfer')"
                            placeholder="yourname@okhdfcbank"
                        />

                        <div class="relative flex py-1 items-center">
                            <div class="grow border-t border-border"></div>
                            <span class="shrink mx-2 text-[10px] text-muted-foreground uppercase">{{ __('OR Bank Account Details') }}</span>
                            <div class="grow border-t border-border"></div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <x-ui.input wire:model="refundBankName" :label="__('Bank Name')" placeholder="e.g. SBI, HDFC"/>
                            <x-ui.input wire:model="refundHolderName" :label="__('Account Holder Name')"/>
                            <x-ui.input wire:model="refundAccountNo" :label="__('Account Number')"/>
                            <x-ui.input wire:model="refundAccountNoConfirmation" :label="__('Confirm Account Number')"/>
                            <div class="sm:col-span-2">
                                <x-ui.input wire:model="refundIfsc" :label="__('Bank IFSC Code')" placeholder="e.g. SBIN0001234"/>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showLibraryRefundModal', false)" variant="outline">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="submitLibraryRefund" wire:loading.attr="disabled" class="bg-amber-600 hover:bg-amber-700 text-white">
                        <x-icon name="send" class="h-4 w-4 mr-1.5"/>
                        {{ __('Submit Refund Claim for ₹:amt', ['amt' => number_format($selectedRefundTotal, 2)]) }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Blocked Refund Modal: Unreturned Books or Dues Alert --}}
    @if($showBlockedModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-rose-300 dark:border-rose-800 bg-card p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-3">
                        <div class="p-2.5 rounded-xl bg-rose-500/10 text-rose-600 border border-rose-500/20 shrink-0">
                            <x-icon name="alert-triangle" class="h-6 w-6"/>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ __('Refund Request Blocked') }}</h3>
                            <p class="text-xs text-rose-600 font-semibold">{{ __('Action Required: Unreturned Library Books Found') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showBlockedModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-xs text-rose-800 dark:text-rose-200 leading-relaxed">
                    {{ __('Under institutional library rules, students with active book loans cannot request a security deposit refund. You currently have :count book(s) issued that have not been returned yet. Please return all borrowed materials to the central library circulation desk before initiating a refund request.', ['count' => count($unreturnedBooksList)]) }}
                </div>

                @if(!empty($unreturnedBooksList))
                    <div class="space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Unreturned Books Currently on Loan:') }}</h4>
                        <div class="divide-y divide-border rounded-xl border border-border bg-muted/20 overflow-hidden">
                            @foreach($unreturnedBooksList as $item)
                                <div class="p-3 flex items-start justify-between gap-3 text-xs">
                                    <div>
                                        <div class="font-bold text-foreground">{{ $item['title'] }}</div>
                                        <div class="text-[11px] text-muted-foreground mt-0.5">
                                            {{ __('Accession No:') }} <strong class="font-mono text-foreground">{{ $item['accession_no'] }}</strong>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-[11px] font-semibold {{ $item['is_overdue'] ? 'text-rose-600' : 'text-amber-600' }}">
                                            {{ __('Due:') }} {{ $item['due_date'] }}
                                        </div>
                                        @if($item['is_overdue'])
                                            <span class="inline-block mt-0.5 px-1.5 py-0.2 rounded text-[10px] font-bold bg-rose-100 dark:bg-rose-900/60 text-rose-700 dark:text-rose-300">
                                                {{ __('OVERDUE') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($unpaidFinesAmount > 0)
                    <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300 flex items-center justify-between">
                        <span>{{ __('Outstanding Overdue Fines:') }}</span>
                        <strong class="font-mono text-sm">₹{{ number_format($unpaidFinesAmount, 2) }}</strong>
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button wire:click="$set('showBlockedModal', false)" variant="outline">
                        {{ __('Close') }}
                    </x-ui.button>
                    <a href="{{ route('student.library.index', ['current_team' => auth()->user()->current_team_id ?? 'default']) }}" class="inline-flex items-center justify-center px-4 py-2 text-xs font-semibold rounded-md bg-teal-600 hover:bg-teal-700 text-white transition">
                        <x-icon name="book-open" class="h-4 w-4 mr-1.5"/>
                        {{ __('View My Library Loans') }}
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- Verify / Check Status Interactive Modal --}}
    @if($showVerifyStatusModal && $activePendingTxn)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <x-icon name="refresh-cw" class="h-5 w-5 {{ $isVerifyingGateway ? 'animate-spin' : '' }}"/>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ __('Verify Deposit Transaction Status') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Live status query with official payment gateway tracker') }}</p>
                        </div>
                    </div>
                    <button wire:click="$set('showVerifyStatusModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                {{-- Transaction Info Card --}}
                <div class="rounded-lg border border-border bg-muted/40 p-3 text-xs space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Transaction No:') }}</span>
                        <span class="font-mono font-bold text-foreground">{{ $activePendingTxn->transaction_no }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Deposit Purpose:') }}</span>
                        <span class="font-medium text-foreground">{{ __('Library Security Collateral Deposit') }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Amount Demanded:') }}</span>
                        <span class="font-bold text-emerald-600 dark:text-emerald-400">₹{{ number_format((float) $activePendingTxn->amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Gateway:') }}</span>
                        <span class="font-medium uppercase text-foreground">{{ $activePendingTxn->gateway ?: 'Online Gateway' }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Initiated:') }}</span>
                        <span class="text-muted-foreground">{{ $activePendingTxn->created_at->format('d M Y, h:i A') }} ({{ $activePendingTxn->created_at->diffForHumans() }})</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">{{ __('Current Local Status:') }}</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $activePendingTxn->status === 'successful' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">
                            {{ ucfirst(str_replace('_', ' ', $activePendingTxn->status)) }}
                        </span>
                    </div>
                </div>

                {{-- Guidance Alert --}}
                @if(! $verifyResult)
                    <div class="rounded-lg bg-sky-50 dark:bg-sky-950/30 border border-sky-200 dark:border-sky-900/40 p-3 text-xs text-sky-900 dark:text-sky-200 flex items-start gap-2.5">
                        <x-icon name="info" class="h-4 w-4 text-sky-600 shrink-0 mt-0.5"/>
                        <p class="leading-relaxed">
                            {{ __('If money was debited from your bank account or card, clicking "Query Gateway Tracker Now" will connect to the payment gateway to retrieve bank authorization reference. If confirmed, your library security collateral will be credited immediately.') }}
                        </p>
                    </div>
                @endif

                {{-- Verification Results Box --}}
                @if($verifyResult)
                    <div class="rounded-lg p-3 text-xs space-y-2 {{ $activePendingTxn->status === 'successful' ? 'bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800' : 'bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800' }}">
                        <div class="flex items-center gap-2 font-bold {{ $activePendingTxn->status === 'successful' ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300' }}">
                            <x-icon name="{{ $activePendingTxn->status === 'successful' ? 'check-circle' : 'alert-circle' }}" class="h-4 w-4"/>
                            <span>{{ $verifyResult['order_status'] ?? __('Status Verification Response') }}</span>
                        </div>
                        <p class="text-muted-foreground leading-relaxed">
                            {{ $verifyResult['message'] ?? '' }}
                        </p>
                    </div>
                @endif

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                    <x-ui.button wire:click="$set('showVerifyStatusModal', false)" variant="outline">
                        {{ __('Close') }}
                    </x-ui.button>
                    @if($activePendingTxn->status !== 'successful')
                        <x-ui.button wire:click="executeLiveVerification" wire:loading.attr="disabled" class="bg-amber-600 hover:bg-amber-700 text-white">
                            <span wire:loading.remove wire:target="executeLiveVerification" class="inline-flex items-center gap-1.5">
                                <x-icon name="refresh-cw" class="h-3.5 w-3.5"/>
                                {{ __('Query Gateway Tracker Now') }}
                            </span>
                            <span wire:loading wire:target="executeLiveVerification" class="inline-flex items-center gap-1.5">
                                <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin"/>
                                {{ __('Contacting Gateway...') }}
                            </span>
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Cancel Pending Payment Attempt Confirmation Modal --}}
    @if($showCancelAttemptModal && $activePendingTxn)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-2xl space-y-4">
                <div class="flex items-start gap-3">
                    <div class="p-2.5 rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 shrink-0">
                        <x-icon name="alert-triangle" class="h-6 w-6"/>
                    </div>
                    <div class="space-y-1">
                        <h3 class="text-base font-bold text-foreground">{{ __('Cancel Pending Deposit Attempt?') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Transaction :txn &bull; ₹:amount', ['txn' => $activePendingTxn->transaction_no, 'amount' => number_format((float) $activePendingTxn->amount, 2)]) }}</p>
                    </div>
                </div>

                {{-- Prominent Critical Warning Box --}}
                <div class="p-3.5 rounded-lg bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/50 text-rose-900 dark:text-rose-200 text-xs space-y-2">
                    <div class="font-bold flex items-center gap-1.5 text-rose-700 dark:text-rose-300">
                        <x-icon name="shield-alert" class="h-4 w-4"/>
                        <span>{{ __('Has money been deducted from your bank?') }}</span>
                    </div>
                    <p class="leading-relaxed">
                        {{ __('If your bank account, card, or UPI app was already debited, DO NOT cancel this attempt. Cancelling will close this transaction session.') }}
                    </p>
                    <p class="leading-relaxed font-semibold">
                        {{ __('Instead, click "Check Status Instead" so our system can verify the bank UTR and issue your library collateral receipt.') }}
                    </p>
                </div>

                <div class="rounded-lg border border-border bg-muted/40 p-2.5 text-xs text-muted-foreground">
                    <p>{{ __('Only cancel this attempt if you closed the payment page before entering OTP, or if your bank transaction was declined.') }}</p>
                </div>

                {{-- Modal Action Buttons --}}
                <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2 pt-2 border-t border-border">
                    <button type="button" wire:click="openVerifyModal({{ $activePendingTxn->id }})" class="px-3 py-1.5 rounded-lg border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-300 font-semibold text-xs inline-flex items-center justify-center gap-1.5 transition-colors">
                        <x-icon name="refresh-cw" class="h-3.5 w-3.5"/>
                        {{ __('Check Status Instead') }}
                    </button>
                    <x-ui.button wire:click="$set('showCancelAttemptModal', false)" variant="outline">
                        {{ __('Keep Attempt') }}
                    </x-ui.button>
                    <button type="button" wire:click="executeCancelAttempt" wire:loading.attr="disabled" class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-semibold text-xs inline-flex items-center justify-center gap-1.5 shadow-xs transition-colors">
                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                        {{ __('Yes, Cancel Attempt') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
