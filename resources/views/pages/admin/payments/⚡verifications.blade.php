<?php

use App\Models\PaymentTransaction;
use App\Services\Payment\FeePaymentService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Offline Payment Verifications')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'under_review';
    public string $methodFilter = 'all';

    // Review & Verify Modal
    public bool $showReviewModal = false;
    public ?PaymentTransaction $selectedTransaction = null;
    public float $editAmount = 0.00;
    public string $editMethod = '';
    public string $editReferenceId = '';
    public string $editPaymentDate = '';
    public string $adminRemarks = '';
    public string $rejectionReason = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openReviewModal(int $transactionId): void
    {
        $this->selectedTransaction = PaymentTransaction::with(['user.studentProfile', 'invoice.enrollment.course', 'feeType'])->findOrFail($transactionId);
        $this->editAmount = (float) $this->selectedTransaction->amount;
        $this->editMethod = $this->selectedTransaction->payment_method;
        $this->editReferenceId = (string) $this->selectedTransaction->offline_reference_id;
        $this->editPaymentDate = $this->selectedTransaction->offline_payment_date?->toDateString() ?: now()->toDateString();
        $this->adminRemarks = (string) ($this->selectedTransaction->admin_remarks ?: '');
        $this->rejectionReason = '';
        $this->showReviewModal = true;
    }

    public function approvePayment(): void
    {
        if (! $this->selectedTransaction) {
            return;
        }

        $this->validate([
            'editAmount' => ['required', 'numeric', 'min:1'],
            'editReferenceId' => ['required', 'string', 'max:100'],
            'editPaymentDate' => ['required', 'date'],
            'adminRemarks' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            FeePaymentService::verifyOfflinePayment(
                transaction: $this->selectedTransaction,
                approved: true,
                verifiedBy: auth()->id(),
                adminRemarks: $this->adminRemarks,
                updatedFields: [
                    'amount' => $this->editAmount,
                    'payment_method' => $this->editMethod,
                    'offline_reference_id' => $this->editReferenceId,
                    'offline_payment_date' => $this->editPaymentDate,
                ]
            );

            Toast::dispatch($this, 'success', __('Payment verified and official receipt generated successfully.'));
            $this->showReviewModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Verification failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function rejectPayment(): void
    {
        if (! $this->selectedTransaction) {
            return;
        }

        $this->validate([
            'rejectionReason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            FeePaymentService::verifyOfflinePayment(
                transaction: $this->selectedTransaction,
                approved: false,
                verifiedBy: auth()->id(),
                adminRemarks: $this->rejectionReason
            );

            Toast::dispatch($this, 'success', __('Payment was marked as rejected.'));
            $this->showReviewModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Rejection failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function with(): array
    {
        $transactions = PaymentTransaction::query()
            ->with(['user.studentProfile', 'invoice.enrollment.course', 'feeType', 'verifier'])
            ->where('is_offline', true)
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('transaction_no', 'like', $term)
                        ->orWhere('offline_reference_id', 'like', $term)
                        ->orWhere('receipt_no', 'like', $term)
                        ->orWhereHas('user', function (Builder $uq) use ($term) {
                            $uq->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('student_id', 'like', $term);
                        });
                });
            })
            ->when($this->statusFilter !== 'all', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->methodFilter !== 'all', fn (Builder $q) => $q->where('payment_method', $this->methodFilter))
            ->orderByDesc('created_at')
            ->paginate(15);

        $pendingCount = PaymentTransaction::where('is_offline', true)->where('status', 'under_review')->count();

        return [
            'transactions' => $transactions,
            'pendingCount' => $pendingCount,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Offline Payment Verifications') }}</h1>
                @if($pendingCount > 0)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                        {{ $pendingCount }} {{ __('Pending Review') }}
                    </span>
                @endif
            </div>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Inspect candidate proof submissions (Static UPI QR, NEFT, IMPS, Cash receipt), edit transaction info if necessary, and approve or reject payments.') }}</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.payments.fees') }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-muted text-foreground">
                <x-icon name="receipt" class="h-3.5 w-3.5 mr-1.5"/>
                {{ __('Fee Invoices') }}
            </a>
        </div>
    </div>

    {{-- Filter Toolbar --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-ui.input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search transaction #, UTR, student name or ID...') }}"
            />

            <select wire:model.live="statusFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                <option value="under_review">{{ __('Pending Review (Action Required)') }}</option>
                <option value="all">{{ __('All Transactions') }}</option>
                <option value="successful">{{ __('Approved / Verified') }}</option>
                <option value="rejected">{{ __('Rejected') }}</option>
            </select>

            <select wire:model.live="methodFilter" class="w-full h-9 rounded-lg border border-border bg-background px-3 text-xs">
                <option value="all">{{ __('All Payment Methods') }}</option>
                <option value="static_qr">{{ __('Static UPI QR') }}</option>
                <option value="neft">{{ __('NEFT / RTGS') }}</option>
                <option value="imps">{{ __('IMPS') }}</option>
                <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                <option value="cash">{{ __('Cash at Office') }}</option>
                <option value="cheque">{{ __('Cheque / DD') }}</option>
            </select>
        </div>
    </div>

    {{-- Transactions Table --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold text-[11px]">
                    <tr>
                        <th class="px-4 py-3">{{ __('Transaction #') }}</th>
                        <th class="px-4 py-3">{{ __('Student') }}</th>
                        <th class="px-4 py-3">{{ __('Fee Item') }}</th>
                        <th class="px-4 py-3">{{ __('Method') }}</th>
                        <th class="px-4 py-3">{{ __('Bank Ref / UTR') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                        <th class="px-4 py-3">{{ __('Payment Date') }}</th>
                        <th class="px-4 py-3">{{ __('Proof') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($transactions as $txn)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 font-semibold whitespace-nowrap">
                                <span class="font-mono text-primary">{{ $txn->transaction_no }}</span>
                                @if($txn->receipt_no)
                                    <div class="text-[10px] text-emerald-600 font-mono">{{ $txn->receipt_no }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-medium text-foreground">{{ $txn->user?->name ?: __('Deleted Student') }}</div>
                                <div class="text-[11px] text-muted-foreground font-mono">{{ $txn->student_id ?: $txn->user?->student_id ?: 'ID: N/A' }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-foreground font-medium">{{ $txn->invoice?->title ?: $txn->feeType?->name ?: __('Direct Deposit') }}</div>
                                <div class="text-[11px] text-muted-foreground">{{ $txn->invoice?->invoice_no }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap uppercase text-[11px] font-semibold text-muted-foreground">
                                {{ str_replace('_', ' ', $txn->payment_method) }}
                            </td>
                            <td class="px-4 py-3 font-mono font-medium text-foreground whitespace-nowrap">
                                {{ $txn->offline_reference_id ?: 'N/A' }}
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-foreground whitespace-nowrap">
                                ₹{{ number_format($txn->amount, 2) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ $txn->offline_payment_date?->format('d M Y') ?: $txn->created_at->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($txn->proof_document_path)
                                    <a href="{{ Storage::url($txn->proof_document_path) }}" target="_blank" class="inline-flex items-center text-primary hover:underline font-medium text-xs">
                                        <x-icon name="paperclip" class="h-3.5 w-3.5 mr-1"/>
                                        {{ __('View Slip') }}
                                    </a>
                                @else
                                    <span class="text-muted-foreground text-[11px]">{{ __('None') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($txn->status === 'under_review')
                                    <x-ui.badge variant="warning">{{ __('Under Review') }}</x-ui.badge>
                                @elseif($txn->status === 'successful')
                                    <x-ui.badge variant="success">{{ __('Approved') }}</x-ui.badge>
                                @elseif($txn->status === 'rejected')
                                    <x-ui.badge variant="destructive">{{ __('Rejected') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">{{ ucfirst($txn->status) }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-ui.button wire:click="openReviewModal({{ $txn->id }})" size="sm" variant="{{ $txn->status === 'under_review' ? 'default' : 'outline' }}">
                                        @if($txn->status === 'under_review')
                                            <x-icon name="check-circle" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Verify') }}
                                        @else
                                            <x-icon name="eye" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Details') }}
                                        @endif
                                    </x-ui.button>

                                    @if($txn->status === 'successful' && $txn->receipt_no)
                                        <a href="{{ route('payments.receipt.download', $txn) }}" class="p-1.5 rounded-lg border border-border text-emerald-600 hover:bg-emerald-500/10" title="Receipt PDF">
                                            <x-icon name="receipt" class="h-3.5 w-3.5"/>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="inbox" class="h-8 w-8 mx-auto mb-2 text-muted-foreground/40"/>
                                <p>{{ __('No offline payment transactions found matching the filter.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $transactions->links() }}
        </div>
    </div>

    {{-- Review & Verify Modal --}}
    @if($showReviewModal && $selectedTransaction)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-2xl rounded-xl border border-border bg-card p-6 shadow-xl space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div>
                        <h3 class="text-base font-semibold text-foreground">{{ __('Offline Payment Verification & Audit') }}</h3>
                        <p class="text-xs text-muted-foreground font-mono">{{ $selectedTransaction->transaction_no }}</p>
                    </div>
                    <button wire:click="$set('showReviewModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                {{-- Student & Invoice Card --}}
                <div class="p-4 rounded-lg bg-muted/40 border border-border/60 text-xs space-y-2">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <div>
                            <span class="text-muted-foreground text-[11px] block">{{ __('Student Name') }}</span>
                            <span class="font-semibold text-foreground">{{ $selectedTransaction->user?->name }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground text-[11px] block">{{ __('Student ID') }}</span>
                            <span class="font-mono font-medium text-foreground">{{ $selectedTransaction->student_id ?: $selectedTransaction->user?->student_id }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground text-[11px] block">{{ __('Course') }}</span>
                            <span class="text-foreground">{{ $selectedTransaction->invoice?->enrollment?->course?->name ?: 'N/A' }}</span>
                        </div>
                    </div>

                    @if($selectedTransaction->user_remarks)
                        <div class="pt-2 border-t border-border/40">
                            <span class="text-muted-foreground text-[11px] block">{{ __('Candidate Note / Remarks:') }}</span>
                            <p class="text-foreground italic">"{{ $selectedTransaction->user_remarks }}"</p>
                        </div>
                    @endif
                </div>

                {{-- Payment Slip Preview if attached --}}
                @if($selectedTransaction->proof_document_path)
                    <div class="space-y-1.5">
                        <label class="block font-medium text-foreground text-xs">{{ __('Uploaded Payment Slip / Bank Advice:') }}</label>
                        <div class="p-3 rounded-lg border border-border bg-muted/20 flex items-center justify-between">
                            <span class="text-xs text-muted-foreground truncate">{{ basename($selectedTransaction->proof_document_path) }}</span>
                            <a href="{{ Storage::url($selectedTransaction->proof_document_path) }}" target="_blank" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-primary text-primary-foreground text-xs font-semibold hover:opacity-90">
                                <x-icon name="external-link" class="h-3.5 w-3.5 mr-1"/>
                                {{ __('Inspect Slip') }}
                            </a>
                        </div>
                    </div>
                @endif

                {{-- Editable fields for Staff (as requested) --}}
                <div class="space-y-3 pt-1 border-t border-border text-xs">
                    <div class="font-semibold text-foreground">{{ __('Financial Parameters (Authorized Staff Verification):') }}</div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-ui.input
                            wire:model="editAmount"
                            :label="__('Verified Amount (INR)') . ' *'"
                            type="number"
                            step="0.01"
                            required
                        />

                        <x-ui.select
                            wire:model="editMethod"
                            :label="__('Payment Mode') . ' *'"
                            :options="[
                                'static_qr' => 'Static UPI QR',
                                'upi' => 'Direct UPI',
                                'neft' => 'NEFT',
                                'imps' => 'IMPS Transfer',
                                'bank_transfer' => 'Bank Transfer / Deposit',
                                'cash' => 'Cash Counter',
                                'cheque' => 'Cheque',
                                'dd' => 'Demand Draft',
                            ]"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-ui.input
                            wire:model="editReferenceId"
                            :label="__('Bank Reference / UTR Number') . ' *'"
                            required
                        />
                        <x-ui.input
                            wire:model="editPaymentDate"
                            :label="__('Verified Payment Date') . ' *'"
                            type="date"
                            required
                        />
                    </div>

                    <x-ui.textarea
                        wire:model="adminRemarks"
                        :label="__('Verification Remarks / Accounts Note')"
                        rows="2"
                        placeholder="Verified through bank statement..."
                    />
                </div>

                {{-- Rejection Box if declining --}}
                <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-xs space-y-2">
                    <span class="font-semibold text-rose-600 block">{{ __('Decline / Reject Payment:') }}</span>
                    <x-ui.input
                        wire:model="rejectionReason"
                        placeholder="{{ __('Specify reason for rejecting payment (e.g. UTR mismatch, payment not received)...') }}"
                    />
                </div>

                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <x-ui.button wire:click="rejectPayment" variant="destructive" size="sm" wire:loading.attr="disabled">
                        <x-icon name="x" class="h-4 w-4 mr-1.5"/>
                        {{ __('Reject Payment') }}
                    </x-ui.button>

                    <div class="flex items-center gap-2">
                        <x-ui.button wire:click="$set('showReviewModal', false)" variant="outline">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button wire:click="approvePayment" wire:loading.attr="disabled">
                            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                            {{ __('Approve & Settle Invoice') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
