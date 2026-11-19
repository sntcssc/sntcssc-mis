<?php

use App\Models\BookCopy;
use App\Models\BookFine;
use App\Models\BookIssue;
use App\Models\LibraryDeposit;
use App\Models\LibraryMember;
use App\Services\Library\BookCirculationService;
use App\Services\Library\LibraryClearanceService;
use App\Services\Library\LibraryPolicyService;
use App\Services\Library\LibraryReminderService;
use App\Services\Library\LibrarySecurityDepositValidator;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Library Circulation Desk')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'active_loans'; // 'active_loans', 'quick_issue', 'history'

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'id';
    public string $sortDirection = 'desc';
    public int $perPage = 15;

    // Issue Desk state
    public string $scanAccessionBarcode = '';
    public ?BookCopy $scannedCopy = null;
    public string $memberSearchQuery = '';
    public ?LibraryMember $selectedMember = null;
    public int $loanDurationDays = 15;
    public bool $bypassDepositCheck = false;
    public string $bypassJustification = '';
    public string $issueNotes = '';
    public ?array $securityValidation = null;

    // Return Modal state
    public bool $showReturnModal = false;
    public ?BookIssue $returningIssue = null;
    public string $returnCondition = 'good';
    public string $returnNotes = '';
    public float $assessedFineAmount = 0.00;
    public int $assessedOverdueDays = 0;
    public bool $waiveFineImmediately = false;
    public string $fineWaiverReason = '';

    // Reminder Modal state
    public bool $showReminderModal = false;
    public ?BookIssue $remindingIssue = null;
    public array $reminderChannels = ['database', 'email', 'sms'];

    // In-House Reading Room Desk state
    public string $inhouseScanBarcode = '';
    public array $inhouseSelectedCopyIds = [];
    public string $inhouseMemberSearchQuery = '';
    public ?LibraryMember $inhouseSelectedMember = null;
    public string $inhouseReaderName = '';
    public string $inhouseReaderPhone = '';
    public string $inhouseReaderId = '';
    public string $inhouseSeatNo = '';
    public string $inhouseDueTime = '17:00';
    public string $inhouseIssueNotes = '';
    public bool $showInhouseIssuePanel = false;

    // Renewal Approval & Direct Renew Modal state
    public ?BookIssue $renewalTargetIssue = null;
    public string $renewalRejectionReason = '';
    public ?BookIssue $renewalApproveTargetIssue = null;
    public string $renewalApprovalNotes = '';
    public ?BookIssue $directRenewTargetIssue = null;

    public function mount(): void
    {
        $this->loanDurationDays = LibraryPolicyService::getDefaultLoanDays();
        $this->reminderChannels = LibraryPolicyService::getReminderChannels();
        $this->inhouseDueTime = LibraryPolicyService::getInhouseReadingEndTime();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'active_loans' => BookIssue::whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_RENEWED])->count(),
            'overdue_loans' => BookIssue::where('status', BookIssue::STATUS_OVERDUE)
                ->orWhere(fn ($q) => $q->whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_RENEWED])->whereDate('due_date', '<', now()))
                ->count(),
            'returned_this_month' => BookIssue::where('status', BookIssue::STATUS_RETURNED)
                ->whereMonth('return_date', now()->month)
                ->whereYear('return_date', now()->year)
                ->count(),
            'pending_fines' => BookFine::where('status', BookFine::STATUS_PENDING)->sum('payable_amount'),
        ];
    }

    #[Computed]
    public function loans()
    {
        $query = BookIssue::query()
            ->with(['book', 'bookCopy', 'libraryMember.user'])
            ->when($this->activeTab === 'active_loans', fn ($q) => $q->whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_OVERDUE, BookIssue::STATUS_RENEWED]))
            ->when($this->activeTab === 'history', fn ($q) => $q->where('status', BookIssue::STATUS_RETURNED))
            ->when($this->search, function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('issue_no', 'like', $term)
                        ->orWhereHas('book', fn ($bq) => $bq->where('title', 'like', $term))
                        ->orWhereHas('bookCopy', fn ($cq) => $cq->where('accession_no', 'like', $term)->orWhere('barcode', 'like', $term))
                        ->orWhereHas('libraryMember', fn ($mq) => $mq->where('name', 'like', $term)->orWhere('member_number', 'like', $term)->orWhere('student_id', 'like', $term));
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortField, $this->sortDirection);

        return $query->paginate($this->perPage);
    }

    // Fast Barcode Lookup for Book Copy
    public function lookupCopyBarcode(): void
    {
        $code = trim($this->scanAccessionBarcode);
        if (empty($code)) {
            return;
        }

        $copy = BookCopy::with('book')
            ->where('accession_no', $code)
            ->orWhere('barcode', $code)
            ->first();

        if (! $copy) {
            Toast::dispatch($this, 'error', __('No physical copy found for code ":code"', ['code' => $code]));
            $this->scannedCopy = null;
            return;
        }

        if ($copy->status !== BookCopy::STATUS_AVAILABLE) {
            Toast::dispatch($this, 'error', __('Copy :acc is currently :status and cannot be issued.', [
                'acc' => $copy->accession_no,
                'status' => ucfirst(str_replace('_', ' ', $copy->status)),
            ]));
        }

        $this->scannedCopy = $copy;
        $this->refreshSecurityValidation();
    }

    // Member Search Lookup
    #[Computed]
    public function memberSearchResults()
    {
        if (strlen(trim($this->memberSearchQuery)) < 2) {
            return collect();
        }

        $q = '%'.trim($this->memberSearchQuery).'%';

        return LibraryMember::query()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', $q)
                    ->orWhere('member_number', 'like', $q)
                    ->orWhere('student_id', 'like', $q)
                    ->orWhere('email', 'like', $q)
                    ->orWhere('phone', 'like', $q);
            })
            ->where('status', LibraryMember::STATUS_ACTIVE)
            ->take(8)
            ->get();
    }

    public function selectMember(int $id): void
    {
        $this->selectedMember = LibraryMember::with(['user', 'activeIssues.bookCopy'])->findOrFail($id);
        $this->memberSearchQuery = "{$this->selectedMember->name} ({$this->selectedMember->member_number})";
        $this->refreshSecurityValidation();
    }

    public function updatedBypassDepositCheck(): void
    {
        $this->refreshSecurityValidation();
    }

    private function refreshSecurityValidation(): void
    {
        if ($this->selectedMember && $this->scannedCopy) {
            $this->securityValidation = LibrarySecurityDepositValidator::validateForIssue(
                $this->selectedMember,
                $this->scannedCopy,
                $this->bypassDepositCheck,
                $this->bypassJustification
            );
        } else {
            $this->securityValidation = null;
        }
    }

    public function executeIssue(): void
    {
        if (! $this->scannedCopy) {
            Toast::dispatch($this, 'error', __('Please scan or select a physical book copy to issue.'));
            return;
        }

        if (! $this->selectedMember) {
            Toast::dispatch($this, 'error', __('Please select a borrower member.'));
            return;
        }

        if ($this->bypassDepositCheck && empty(trim($this->bypassJustification))) {
            $this->addError('bypassJustification', __('Please enter a mandatory justification for bypassing the security deposit check.'));
            return;
        }

        try {
            $issue = BookCirculationService::issueBook(
                $this->scannedCopy,
                $this->selectedMember,
                $this->loanDurationDays,
                $this->bypassDepositCheck,
                $this->bypassJustification,
                $this->issueNotes,
                auth()->user()
            );

            Toast::dispatch($this, 'success', __('Book ":title" (Copy: :acc) successfully issued to :member.', [
                'title' => $this->scannedCopy->book->title,
                'acc' => $this->scannedCopy->accession_no,
                'member' => $this->selectedMember->name,
            ]));

            // Reset issue desk form
            $this->scanAccessionBarcode = '';
            $this->scannedCopy = null;
            $this->memberSearchQuery = '';
            $this->selectedMember = null;
            $this->bypassDepositCheck = false;
            $this->bypassJustification = '';
            $this->issueNotes = '';
            $this->securityValidation = null;
            $this->activeTab = 'active_loans';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    // Return Flow
    public function openReturnModal(int $issueId): void
    {
        $this->returningIssue = BookIssue::with(['book', 'bookCopy', 'libraryMember'])->findOrFail($issueId);
        $this->returnCondition = $this->returningIssue->bookCopy->book_condition ?: 'good';
        $this->returnNotes = '';
        $this->waiveFineImmediately = false;
        $this->fineWaiverReason = '';

        $fineAssessment = LibraryPolicyService::assessIssueFine($this->returningIssue);
        $this->assessedFineAmount = $fineAssessment['fine_amount'];
        $this->assessedOverdueDays = $fineAssessment['overdue_days'];

        $this->dispatch('modal-open', name: 'return-book-modal');
    }

    public function executeReturn(): void
    {
        if (! $this->returningIssue) {
            return;
        }

        DB::beginTransaction();
        try {
            $result = BookCirculationService::returnBook(
                $this->returningIssue,
                $this->returnCondition,
                $this->returnNotes,
                auth()->user()
            );

            // Immediate fine waiver if authorized staff selected
            if ($result['fine'] && $this->waiveFineImmediately) {
                $result['fine']->update([
                    'status' => BookFine::STATUS_WAIVED,
                    'waived_amount' => $result['fine']->calculated_fine,
                    'payable_amount' => 0.00,
                    'waived_by' => auth()->id(),
                    'waived_at' => now(),
                    'waiver_reason' => $this->fineWaiverReason ?: 'Immediate return waiver by staff desk.',
                ]);
            }

            DB::commit();

            Toast::dispatch($this, 'success', __('Book ":title" returned successfully.', [
                'title' => $this->returningIssue->book->title,
            ]));

            $this->dispatch('modal-close', name: 'return-book-modal');
            $this->returningIssue = null;
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    // Direct Renewal Flow (+15 days)
    public function openDirectRenewModal(int $issueId): void
    {
        $this->directRenewTargetIssue = BookIssue::with(['book', 'libraryMember', 'bookCopy'])->findOrFail($issueId);
        $this->dispatch('modal-open', name: 'direct-renew-modal');
    }

    public function executeDirectRenew(): void
    {
        if (! $this->directRenewTargetIssue) {
            return;
        }

        try {
            $renewed = BookCirculationService::renewBook(
                $this->directRenewTargetIssue,
                LibraryPolicyService::getDefaultLoanDays(),
                auth()->user()
            );

            Toast::dispatch($this, 'success', __('Loan renewed for ":title". New due date: :due.', [
                'title' => $renewed->book->title,
                'due' => $renewed->due_date->format('d M Y'),
            ]));

            $this->dispatch('modal-close', name: 'direct-renew-modal');
            $this->directRenewTargetIssue = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function renewLoan(int $issueId): void
    {
        $issue = BookIssue::findOrFail($issueId);

        try {
            $renewed = BookCirculationService::renewBook(
                $issue,
                LibraryPolicyService::getDefaultLoanDays(),
                auth()->user()
            );

            Toast::dispatch($this, 'success', __('Loan renewed for ":title". New due date: :due.', [
                'title' => $renewed->book->title,
                'due' => $renewed->due_date->format('d M Y'),
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    // Renewal Approval & Rejection Flow
    public function openApproveRenewalModal(int $issueId): void
    {
        $this->renewalApproveTargetIssue = BookIssue::with(['book', 'libraryMember', 'bookCopy'])->findOrFail($issueId);
        $this->renewalApprovalNotes = '';
        $this->dispatch('modal-open', name: 'approve-renewal-modal');
    }

    public function executeApproveRenewal(): void
    {
        if (! $this->renewalApproveTargetIssue) {
            return;
        }

        try {
            $renewed = BookCirculationService::approveLoanRenewal(
                $this->renewalApproveTargetIssue,
                notes: trim($this->renewalApprovalNotes) ?: null,
                reviewedBy: auth()->user()
            );

            Toast::dispatch($this, 'success', __('Renewal approved for ":title". New due date: :due.', [
                'title' => $renewed->book?->title,
                'due' => $renewed->due_date->format('d M Y'),
            ]));

            $this->dispatch('modal-close', name: 'approve-renewal-modal');
            $this->renewalApproveTargetIssue = null;
            $this->renewalApprovalNotes = '';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function approveRenewal(int $issueId, ?string $notes = null): void
    {
        $issue = BookIssue::findOrFail($issueId);

        try {
            $renewed = BookCirculationService::approveLoanRenewal(
                $issue,
                notes: $notes,
                reviewedBy: auth()->user()
            );

            Toast::dispatch($this, 'success', __('Renewal approved for ":title". New due date: :due.', [
                'title' => $renewed->book?->title,
                'due' => $renewed->due_date->format('d M Y'),
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openRenewalRejectModal(int $issueId): void
    {
        $this->renewalTargetIssue = BookIssue::with(['book', 'libraryMember'])->findOrFail($issueId);
        $this->renewalRejectionReason = '';
        $this->dispatch('modal-open', name: 'reject-renewal-modal');
    }

    public function executeRejectRenewal(): void
    {
        if (! $this->renewalTargetIssue) {
            return;
        }

        if (empty(trim($this->renewalRejectionReason))) {
            Toast::dispatch($this, 'error', __('Please enter a rejection reason.'));

            return;
        }

        try {
            BookCirculationService::rejectLoanRenewal(
                $this->renewalTargetIssue,
                $this->renewalRejectionReason,
                auth()->user()
            );

            Toast::dispatch($this, 'info', __('Renewal request for ":title" has been rejected.', [
                'title' => $this->renewalTargetIssue->book?->title,
            ]));

            $this->dispatch('modal-close', name: 'reject-renewal-modal');
            $this->renewalTargetIssue = null;
            $this->renewalRejectionReason = '';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    #[Computed]
    public function pendingRenewalRequestsCount(): int
    {
        return BookIssue::where('renewal_status', BookIssue::RENEWAL_REQUESTED)->count();
    }

    #[Computed]
    public function pendingRenewals()
    {
        return BookIssue::with(['book', 'bookCopy', 'libraryMember.user'])
            ->where('renewal_status', BookIssue::RENEWAL_REQUESTED)
            ->orderBy('renewal_requested_at', 'asc')
            ->get();
    }

    // Reminder Flow
    public function openReminderModal(int $issueId): void
    {
        $this->remindingIssue = BookIssue::with(['book', 'bookCopy', 'libraryMember', 'user'])->findOrFail($issueId);
        $this->dispatch('modal-open', name: 'send-reminder-modal');
    }

    public function executeSendReminder(): void
    {
        if (! $this->remindingIssue) {
            return;
        }

        try {
            $result = LibraryReminderService::sendIssueReminder(
                $this->remindingIssue,
                $this->reminderChannels,
                auth()->id()
            );

            if ($result['success']) {
                Toast::dispatch($this, 'success', __('Reminder dispatched via :channels.', [
                    'channels' => implode(', ', $result['channels_sent']),
                ]));
            } else {
                Toast::dispatch($this, 'warning', __('No communication channel was successfully reached. Check user contacts.'));
            }

            $this->dispatch('modal-close', name: 'send-reminder-modal');
            $this->remindingIssue = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function export(string $format = 'csv')
    {
        $query = BookIssue::with(['book', 'bookCopy', 'libraryMember'])->latest('id');

        return TableExporter::download(
            $query,
            $format,
            'circulation-loans',
            ['Issue No', 'Book Title', 'Accession No', 'Member Name', 'Member No', 'Issue Date', 'Due Date', 'Return Date', 'Status', 'Renewals', 'Deposit Bypassed'],
            fn ($i) => [
                $i->issue_no ?? $i->issue_number ?? 'N/A',
                $i->book?->title ?? 'N/A',
                $i->bookCopy?->accession_no ?? 'N/A',
                $i->libraryMember?->name ?? $i->reader_name ?? 'In-House Reader',
                $i->libraryMember?->member_number ?? $i->reader_identifier ?? 'N/A',
                $i->issue_date?->format('Y-m-d') ?? 'N/A',
                $i->due_date?->format('Y-m-d') ?? 'N/A',
                $i->return_date ? $i->return_date->format('Y-m-d') : 'Not Returned',
                ucfirst($i->status ?? 'issued'),
                $i->renewal_count ?? 0,
                $i->is_deposit_bypassed ? 'Yes ('.($i->bypass_reason ?? '').')' : 'No',
            ],
            'SNTCSSC Library Circulation Register'
        );
    }

    // Clearance State
    public ?int $selectedClearanceDepositId = null;
    public ?LibraryDeposit $selectedClearanceDeposit = null;
    public string $clearanceRemarks = '';
    public string $clearanceRejectReason = '';

    #[Computed]
    public function pendingClearanceCount(): int
    {
        return LibraryDeposit::where('clearance_status', LibraryDeposit::CLEARANCE_PENDING)->count();
    }

    #[Computed]
    public function clearanceRequests()
    {
        return LibraryDeposit::with(['user', 'clearedBy'])
            ->whereIn('clearance_status', [LibraryDeposit::CLEARANCE_PENDING, LibraryDeposit::CLEARANCE_CLEARED, LibraryDeposit::CLEARANCE_REJECTED])
            ->when($this->search, function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('deposit_no', 'like', $term)
                        ->orWhere('clearance_certificate_no', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $term)->orWhere('email', 'like', $term));
                });
            })
            ->latest('refund_requested_at')
            ->paginate($this->perPage);
    }

    public function openClearanceModal(int $depositId): void
    {
        $deposit = LibraryDeposit::with('user')->findOrFail($depositId);
        $eligibility = LibraryClearanceService::checkEligibility($deposit->user_id);

        if (! $eligibility['eligible']) {
            Toast::dispatch($this, 'error', $eligibility['blocking_reason']);
            return;
        }

        $this->selectedClearanceDepositId = $depositId;
        $this->selectedClearanceDeposit = $deposit;
        $this->clearanceRemarks = 'All books returned in good condition. Zero outstanding dues or overdue fines. Official library clearance granted for security deposit refund.';
        $this->dispatch('modal-open', name: 'issue-clearance-modal');
    }

    public function executeIssueClearance(): void
    {
        if (! $this->selectedClearanceDepositId) {
            return;
        }

        try {
            $deposit = LibraryClearanceService::issueClearanceCertificate(
                $this->selectedClearanceDepositId,
                auth()->id(),
                $this->clearanceRemarks
            );

            Toast::dispatch($this, 'success', __('Library Clearance Certificate :cert successfully issued for :name (:dep).', [
                'cert' => $deposit->clearance_certificate_no,
                'name' => $deposit->user->name,
                'dep' => $deposit->deposit_no,
            ]));

            $this->dispatch('modal-close', name: 'issue-clearance-modal');
            $this->selectedClearanceDeposit = null;
            $this->selectedClearanceDepositId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openRejectClearanceModal(int $depositId): void
    {
        $this->selectedClearanceDepositId = $depositId;
        $this->selectedClearanceDeposit = LibraryDeposit::with('user')->findOrFail($depositId);
        $this->clearanceRejectReason = '';
        $this->dispatch('modal-open', name: 'reject-clearance-modal');
    }

    public function executeRejectClearance(): void
    {
        if (! $this->selectedClearanceDepositId) {
            return;
        }

        $this->validate([
            'clearanceRejectReason' => 'required|string|min:5|max:500',
        ]);

        try {
            LibraryClearanceService::rejectClearance(
                $this->selectedClearanceDepositId,
                auth()->id(),
                $this->clearanceRejectReason
            );

            Toast::dispatch($this, 'success', __('Clearance request rejected for :name.', [
                'name' => $this->selectedClearanceDeposit->user->name,
            ]));

            $this->dispatch('modal-close', name: 'reject-clearance-modal');
            $this->selectedClearanceDeposit = null;
            $this->selectedClearanceDepositId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    // In-House Reading Desk Methods
    #[Computed]
    public function activeInhouseCount(): int
    {
        return BookIssue::where('issue_type', BookIssue::TYPE_IN_HOUSE)
            ->where('status', BookIssue::STATUS_ISSUED)
            ->count();
    }

    #[Computed]
    public function inhouseSessions()
    {
        return BookIssue::with(['book', 'bookCopy', 'libraryMember.user', 'issuer'])
            ->where('issue_type', BookIssue::TYPE_IN_HOUSE)
            ->where('status', BookIssue::STATUS_ISSUED)
            ->latest('id')
            ->get()
            ->groupBy('inhouse_session_token');
    }

    #[Computed]
    public function inhouseSelectedCopies()
    {
        if (empty($this->inhouseSelectedCopyIds)) {
            return collect();
        }

        return BookCopy::with(['book.authors', 'book.category'])->whereIn('id', $this->inhouseSelectedCopyIds)->get();
    }

    #[Computed]
    public function inhouseMemberSearchResults()
    {
        if (strlen(trim($this->inhouseMemberSearchQuery)) < 2) {
            return collect();
        }

        $term = '%'.trim($this->inhouseMemberSearchQuery).'%';

        return LibraryMember::query()
            ->where('status', LibraryMember::STATUS_ACTIVE)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('member_number', 'like', $term)
                    ->orWhere('student_id', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            })
            ->limit(6)
            ->get();
    }

    public function lookupInhouseCopyBarcode(): void
    {
        $code = trim($this->inhouseScanBarcode);
        if (empty($code)) {
            return;
        }

        $copy = BookCopy::with('book')
            ->where('accession_no', $code)
            ->orWhere('barcode', $code)
            ->first();

        if (! $copy) {
            Toast::dispatch($this, 'error', __('No physical copy found for code ":code"', ['code' => $code]));
            return;
        }

        if ($copy->status !== BookCopy::STATUS_AVAILABLE) {
            Toast::dispatch($this, 'warning', __('Copy ":acc" is not available (Status: :status).', [
                'acc' => $copy->accession_no,
                'status' => ucfirst(str_replace('_', ' ', $copy->status)),
            ]));
            return;
        }

        if (in_array($copy->id, $this->inhouseSelectedCopyIds)) {
            Toast::dispatch($this, 'info', __('Copy ":acc" is already in the selected tray.', ['acc' => $copy->accession_no]));
            $this->inhouseScanBarcode = '';
            return;
        }

        $max = LibraryPolicyService::getInhouseReadingMaxItems();
        if (count($this->inhouseSelectedCopyIds) >= $max) {
            Toast::dispatch($this, 'warning', __('Max limit reached: You can serve up to :max items at once.', ['max' => $max]));
            return;
        }

        $this->inhouseSelectedCopyIds[] = $copy->id;
        $this->inhouseScanBarcode = '';
        Toast::dispatch($this, 'success', __('Added ":title" (:acc) to reading tray.', [
            'title' => $copy->book?->title,
            'acc' => $copy->accession_no,
        ]));
    }

    public function removeInhouseCopy(int $copyId): void
    {
        $this->inhouseSelectedCopyIds = array_values(array_filter(
            $this->inhouseSelectedCopyIds,
            fn ($id) => $id !== $copyId
        ));
    }

    public function selectInhouseMember(int $id): void
    {
        $this->inhouseSelectedMember = LibraryMember::with('user')->find($id);
        if ($this->inhouseSelectedMember) {
            $this->inhouseReaderName = $this->inhouseSelectedMember->name;
            $this->inhouseReaderPhone = $this->inhouseSelectedMember->phone ?? '';
            $this->inhouseReaderId = $this->inhouseSelectedMember->student_id ?: $this->inhouseSelectedMember->member_number;
        }
        $this->inhouseMemberSearchQuery = '';
    }

    public function clearInhouseMember(): void
    {
        $this->inhouseSelectedMember = null;
        $this->inhouseReaderName = '';
        $this->inhouseReaderPhone = '';
        $this->inhouseReaderId = '';
    }

    public function executeInhouseIssue(): void
    {
        if (empty($this->inhouseSelectedCopyIds)) {
            Toast::dispatch($this, 'error', __('Please scan or select at least one book or journal copy.'));
            return;
        }

        if (empty($this->inhouseReaderName)) {
            Toast::dispatch($this, 'error', __('Please enter or select reader/student name.'));
            return;
        }

        try {
            $issues = BookCirculationService::issueInHouseReading(
                copies: $this->inhouseSelectedCopyIds,
                member: $this->inhouseSelectedMember,
                readerDetails: [
                    'name' => $this->inhouseReaderName,
                    'phone' => $this->inhouseReaderPhone,
                    'identifier' => $this->inhouseReaderId,
                    'seat_no' => $this->inhouseSeatNo,
                ],
                dueTime: $this->inhouseDueTime,
                notes: $this->inhouseIssueNotes,
                issuedBy: auth()->user()
            );

            Toast::dispatch($this, 'success', __('Successfully served :count item(s) to :reader for in-house reading.', [
                'count' => $issues->count(),
                'reader' => $this->inhouseReaderName,
            ]));

            $this->inhouseSelectedCopyIds = [];
            $this->inhouseScanBarcode = '';
            $this->inhouseSelectedMember = null;
            $this->inhouseReaderName = '';
            $this->inhouseReaderPhone = '';
            $this->inhouseReaderId = '';
            $this->inhouseSeatNo = '';
            $this->inhouseIssueNotes = '';
            $this->showInhouseIssuePanel = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function returnInhouseItem(int $issueId): void
    {
        $issue = BookIssue::findOrFail($issueId);
        try {
            BookCirculationService::returnInHouseIssue($issue, returnedBy: auth()->user());
            Toast::dispatch($this, 'success', __('Returned ":title" to stacks.', [
                'title' => $issue->book?->title ?? 'Item',
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function returnInhouseSessionGroup(string $sessionToken): void
    {
        try {
            $count = BookCirculationService::returnInHouseSession($sessionToken, returnedBy: auth()->user());
            Toast::dispatch($this, 'success', __('Successfully returned all :count in-house item(s) from reader.', [
                'count' => $count,
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 border border-teal-200/50 dark:border-teal-800/50">
                    <x-icon name="repeat" class="w-6 h-6" />
                </span>
                {{ __('Library Circulation Desk') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Real-time book issue, returns, 15-day timeline renewals, automatic overdue fine calculations, and multi-channel return reminders.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <!-- Navigation Tabs -->
            <div class="inline-flex rounded-xl border border-border p-1 bg-muted/40 flex-wrap">
                <button type="button" wire:click="$set('activeTab', 'active_loans')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $activeTab === 'active_loans' ? 'bg-background text-teal-600 dark:text-teal-400 shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="book-open" class="w-3.5 h-3.5 inline-block mr-1" />
                    {{ __('Active Loans') }}
                </button>
                <button type="button" wire:click="$set('activeTab', 'quick_issue')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $activeTab === 'quick_issue' ? 'bg-background text-teal-600 dark:text-teal-400 shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="plus" class="w-3.5 h-3.5 inline-block mr-1" />
                    {{ __('Issue Desk') }}
                </button>
                <button type="button" wire:click="$set('activeTab', 'inhouse_reading')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $activeTab === 'inhouse_reading' ? 'bg-background text-teal-600 dark:text-teal-400 shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="book-open" class="w-3.5 h-3.5 inline-block mr-1" />
                    {{ __('In-House Reading') }}
                    @if($this->activeInhouseCount > 0)
                        <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-teal-600 text-white leading-none">{{ $this->activeInhouseCount }}</span>
                    @endif
                </button>
                <button type="button" wire:click="$set('activeTab', 'history')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $activeTab === 'history' ? 'bg-background text-teal-600 dark:text-teal-400 shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="history" class="w-3.5 h-3.5 inline-block mr-1" />
                    {{ __('Return History') }}
                </button>
                <button type="button" wire:click="$set('activeTab', 'clearance')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $activeTab === 'clearance' ? 'bg-background text-teal-600 dark:text-teal-400 shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="award" class="w-3.5 h-3.5 inline-block mr-1" />
                    {{ __('Clearance & No-Dues') }}
                    @if($this->pendingClearanceCount > 0)
                        <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500 text-white leading-none">{{ $this->pendingClearanceCount }}</span>
                    @endif
                </button>
                <button type="button" wire:click="$set('activeTab', 'renewal_requests')" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $activeTab === 'renewal_requests' ? 'bg-background text-teal-600 dark:text-teal-400 shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    <x-icon name="rotate-cw" class="w-3.5 h-3.5 inline-block mr-1" />
                    {{ __('Renewal Requests') }}
                    @if($this->pendingRenewalRequestsCount > 0)
                        <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500 text-white leading-none">{{ $this->pendingRenewalRequestsCount }}</span>
                    @endif
                </button>
            </div>

            <!-- Export -->
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export') }}
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export Circulation CSV') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Circulation Excel') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export Circulation PDF') }}</x-ui.dropdown.item>
            </x-ui.dropdown>
        </div>
    </div>

    <!-- Stats KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-teal-600 dark:text-teal-400 uppercase tracking-wider">{{ __('Active Loans Out') }}</span>
            <div class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['active_loans'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Books currently in circulation') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-rose-600 dark:text-rose-400 uppercase tracking-wider">{{ __('Overdue Loans') }}</span>
            <div class="text-2xl font-bold text-rose-600 dark:text-rose-400 mt-1">{{ $this->stats['overdue_loans'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Exceeded 15-day timeline') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-sky-600 dark:text-sky-400 uppercase tracking-wider">{{ __('In-House Readers') }}</span>
            <div class="text-2xl font-bold text-sky-600 dark:text-sky-400 mt-1">{{ $this->activeInhouseCount }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Reading room day loans') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Returned This Month') }}</span>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ $this->stats['returned_this_month'] }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Restocked into stacks') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Pending Fines') }}</span>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1 font-mono">₹{{ number_format($this->stats['pending_fines'], 2) }}</div>
            <div class="text-[11px] text-muted-foreground mt-0.5">{{ __('Uncollected overdue fines') }}</div>
        </div>
    </div>

    @if($activeTab === 'quick_issue')
        <!-- ISSUE DESK WORKSPACE -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Left Panel: Scanner & Member Selector -->
            <div class="lg:col-span-7 space-y-5 bg-card border border-border rounded-2xl p-5 shadow-xs">
                <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                    <x-icon name="scan" class="w-5 h-5 text-teal-600" />
                    {{ __('Scan & Check-Out Book') }}
                </h3>

                <!-- 1. Barcode Scanner Input -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5">
                        {{ __('1. Scan / Enter Book Accession Number or Barcode') }} *
                    </label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <x-icon name="barcode" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                type="text"
                                wire:model="scanAccessionBarcode"
                                wire:keydown.enter.prevent="lookupCopyBarcode"
                                placeholder="{{ __('Scan barcode or type ACC-2026-00001...') }}"
                                class="w-full pl-9 pr-3 py-2 text-sm bg-background border border-border rounded-lg font-mono focus:ring-2 focus:ring-teal-500"
                            />
                        </div>
                        <x-ui.button type="button" variant="default" size="sm" wire:click="lookupCopyBarcode" class="bg-teal-600 hover:bg-teal-700 text-white shrink-0">
                            {{ __('Lookup Copy') }}
                        </x-ui.button>
                    </div>

                    @if($scannedCopy)
                        <div class="mt-3 p-3 bg-muted/40 rounded-xl border border-border flex items-start justify-between gap-3 text-xs">
                            <div>
                                <h4 class="font-bold text-foreground">{{ $scannedCopy->book->title }}</h4>
                                <div class="text-muted-foreground mt-0.5">
                                    {{ __('Accession:') }} <strong class="font-mono text-foreground">{{ $scannedCopy->accession_no }}</strong> &bull;
                                    {{ __('Shelf:') }} <strong class="text-foreground">{{ $scannedCopy->shelf_location }}</strong>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="font-bold font-mono text-sm text-foreground">₹{{ number_format($scannedCopy->purchase_cost, 2) }}</span>
                                <span class="block text-[10px] text-emerald-600 font-semibold">{{ __('AVAILABLE') }}</span>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- 2. Borrower Member Lookup -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5">
                        {{ __('2. Select Borrower Member (Student or Staff)') }} *
                    </label>
                    <div class="relative">
                        <x-icon name="user" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            wire:model.live.debounce.250ms="memberSearchQuery"
                            placeholder="{{ __('Type member name, Student ID, or Member No...') }}"
                            class="w-full pl-9 pr-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500"
                        />
                    </div>

                    @if(!empty($this->memberSearchResults) && count($this->memberSearchResults) > 0)
                        <div class="mt-1 bg-card border border-border rounded-lg shadow-lg max-h-48 overflow-y-auto divide-y divide-border/60 z-20">
                            @foreach($this->memberSearchResults as $m)
                                <div wire:click="selectMember({{ $m->id }})" class="p-2.5 hover:bg-muted/50 cursor-pointer flex items-center justify-between text-xs">
                                    <div>
                                        <strong class="text-foreground">{{ $m->name }}</strong>
                                        <span class="text-muted-foreground ml-1.5 font-mono">({{ $m->member_number }})</span>
                                        @if($m->student_id)
                                            <span class="text-teal-600 font-mono ml-1 font-semibold">[{{ $m->student_id }}]</span>
                                        @endif
                                    </div>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-teal-100 text-teal-800">
                                        {{ ucfirst($m->member_type) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($selectedMember)
                        <div class="mt-3 p-3 bg-muted/40 rounded-xl border border-border flex items-center justify-between text-xs">
                            <div>
                                <strong class="text-foreground text-sm">{{ $selectedMember->name }}</strong>
                                <div class="text-muted-foreground mt-0.5">
                                    {{ __('Member No:') }} <span class="font-mono">{{ $selectedMember->member_number }}</span> &bull;
                                    {{ __('Loans:') }} <strong>{{ $selectedMember->active_issued_count }}</strong> / {{ \App\Services\Library\LibraryPolicyService::getMaxBooksAllowed() }}
                                </div>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/50">
                                {{ __('Active Member') }}
                            </span>
                        </div>
                    @endif
                </div>

                <!-- 3. Loan Duration & Notes -->
                <div class="grid grid-cols-2 gap-3 pt-2 border-t border-border/60">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Duration (Days)') }} *</label>
                        <input type="number" min="1" max="90" wire:model="loanDurationDays" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg font-bold" />
                        <span class="text-[11px] text-muted-foreground mt-0.5 block">{{ __('Standard: 15 Days') }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Calculated Due Date') }}</label>
                        <div class="px-3 py-2 text-sm bg-muted rounded-lg font-mono text-muted-foreground">
                            {{ now()->addDays($loanDurationDays)->format('d M Y') }}
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Checkout Remarks / Notes') }}</label>
                    <input type="text" wire:model="issueNotes" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg" placeholder="{{ __('Optional checkout note...') }}" />
                </div>
            </div>

            <!-- Right Panel: Security Deposit Verification & Bypass Desk -->
            <div class="lg:col-span-5 space-y-4">
                <div class="bg-card border border-border rounded-2xl p-5 shadow-xs space-y-4">
                    <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                        <x-icon name="shield-check" class="w-5 h-5 text-teal-600" />
                        {{ __('Security Deposit Rule Engine') }}
                    </h3>

                    @if($securityValidation)
                        <div class="p-4 rounded-xl border {{ $securityValidation['allowed'] ? 'bg-emerald-50/60 dark:bg-emerald-950/30 border-emerald-300 dark:border-emerald-800' : 'bg-rose-50/60 dark:bg-rose-950/30 border-rose-300 dark:border-rose-800' }} space-y-2.5">
                            <div class="flex items-center justify-between text-xs font-bold {{ $securityValidation['allowed'] ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300' }}">
                                <span>{{ __('Rule Verification') }}</span>
                                <span>{{ $securityValidation['allowed'] ? __('APPROVED') : __('BLOCKED') }}</span>
                            </div>

                            <div class="space-y-1.5 text-xs border-t border-current/10 pt-2 font-mono">
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground">{{ __('Active Security Deposit:') }}</span>
                                    <strong>₹{{ number_format($securityValidation['active_deposit'], 2) }}</strong>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground">{{ __('Already Borrowed Books:') }}</span>
                                    <strong>₹{{ number_format($securityValidation['current_borrowed_value'], 2) }}</strong>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground">{{ __('This Book Price:') }}</span>
                                    <strong>₹{{ number_format($securityValidation['new_book_price'], 2) }}</strong>
                                </div>
                                <div class="flex justify-between pt-1 border-t border-current/10 font-bold">
                                    <span>{{ __('Total Liability After Issue:') }}</span>
                                    <span>₹{{ number_format($securityValidation['total_borrowed_value_after'], 2) }}</span>
                                </div>
                            </div>

                            @if(! $securityValidation['allowed'])
                                <div class="p-2.5 bg-rose-100 dark:bg-rose-950/80 rounded-lg text-xs font-medium text-rose-800 dark:text-rose-200">
                                    {{ $securityValidation['reason'] }}
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="p-4 bg-muted/40 rounded-xl border border-dashed border-border text-center text-xs text-muted-foreground">
                            {{ __('Scan a physical book copy and select a borrower member to execute the real-time security deposit verification.') }}
                        </div>
                    @endif

                    <!-- Special Librarian Bypass Feature -->
                    <div class="p-4 bg-amber-50/70 dark:bg-amber-950/30 border border-amber-300 dark:border-amber-800 rounded-xl space-y-2.5">
                        <div class="flex items-center justify-between">
                            <label for="circ_bypass" class="text-xs font-bold text-amber-900 dark:text-amber-200 flex items-center gap-1.5 cursor-pointer">
                                <x-icon name="shield-alert" class="w-4 h-4 text-amber-600" />
                                {{ __('Librarian Security Deposit Bypass') }}
                            </label>
                            <input type="checkbox" id="circ_bypass" wire:model.live="bypassDepositCheck" class="rounded border-border text-amber-600 focus:ring-amber-500" />
                        </div>
                        <p class="text-[11px] text-amber-800 dark:text-amber-300 leading-snug">
                            {{ __('Special institutional override for staff, faculty, or temporary borrowing without deposit liability.') }}
                        </p>

                        @if($bypassDepositCheck)
                            <div class="pt-1.5">
                                <label class="block text-[11px] font-semibold text-amber-900 dark:text-amber-200 mb-1">
                                    {{ __('Mandatory Justification Reason') }} *
                                </label>
                                <input
                                    type="text"
                                    wire:model="bypassJustification"
                                    placeholder="{{ __('e.g. Temporary staff research loan approved by Librarian') }}"
                                    class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg"
                                    required
                                />
                                @error('bypassJustification') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>

                    <!-- Confirm Issue Action Button -->
                    <x-ui.button
                        type="button"
                        variant="default"
                        size="lg"
                        wire:click="executeIssue"
                        class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold h-11 shadow-sm"
                        :disabled="!$scannedCopy || !$selectedMember || (!$securityValidation['allowed'] && !$bypassDepositCheck)"
                    >
                        <x-icon name="check" class="w-4 h-4 mr-2" />
                        {{ __('Confirm Book Checkout') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @elseif($activeTab === 'inhouse_reading')
        <!-- IN-HOUSE LIBRARY READING ROOM DESK WORKSPACE -->
        <div class="space-y-6">
            <!-- Header Banner -->
            <div class="p-5 rounded-2xl bg-gradient-to-r from-teal-500/10 via-sky-500/10 to-transparent border border-teal-500/20 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="space-y-1">
                    <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                        <x-icon name="book-open" class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                        {{ __('In-House Library Reading Room Facility') }}
                    </h3>
                    <p class="text-xs text-muted-foreground max-w-2xl">
                        {{ __('Serve one or multiple books, journals, or reference volumes to students or visitors for reading inside library rooms without checking security deposits. Materials can be taken back anytime within the day.') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2 pt-1 text-xs">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-700 dark:text-teal-300 border border-teal-500/20 font-medium text-[11px]">
                            <x-icon name="clock" class="w-3.5 h-3.5 text-teal-600" />
                            {{ __('Official Reading Hours: :hours', ['hours' => \App\Services\Library\LibraryPolicyService::getInhouseReadingHoursFormatted()]) }}
                        </span>

                        @if(\App\Services\Library\LibraryPolicyService::isWithinInhouseReadingHours())
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                {{ __('Reading Room Open') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                <x-icon name="alert-triangle" class="w-3 h-3 text-amber-500" />
                                {{ __('Outside Official Hours') }}
                            </span>
                        @endif

                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/10 text-blue-600 border border-blue-500/20">
                            <x-icon name="shield" class="w-3 h-3 text-blue-500" />
                            {{ __('Zero Deposit Criteria') }}
                        </span>
                    </div>
                </div>

                <div class="shrink-0 flex items-center gap-2">
                    <x-ui.button
                        type="button"
                        variant="{{ $showInhouseIssuePanel ? 'outline' : 'default' }}"
                        size="sm"
                        wire:click="$toggle('showInhouseIssuePanel')"
                        class="{{ $showInhouseIssuePanel ? '' : 'bg-teal-600 hover:bg-teal-700 text-white' }} shadow-xs"
                    >
                        <x-icon name="{{ $showInhouseIssuePanel ? 'x' : 'plus' }}" class="w-4 h-4 mr-1.5" />
                        {{ $showInhouseIssuePanel ? __('Close Issue Panel') : __('+ Serve Books to Reader') }}
                    </x-ui.button>
                </div>
            </div>

            <!-- SERVE BOOKS WORKSPACE PANEL -->
            @if($showInhouseIssuePanel || $this->inhouseSessions->isEmpty())
                <div class="bg-card border border-border rounded-2xl p-5 shadow-xs space-y-5">
                    <div class="flex items-center justify-between pb-3 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400">
                                <x-icon name="scan" class="w-5 h-5" />
                            </span>
                            <div>
                                <h4 class="font-bold text-sm text-foreground">{{ __('Serve Books / Journals for In-House Reading') }}</h4>
                                <p class="text-[11px] text-muted-foreground">{{ __('Scan or add physical copies, assign to student or reader, and issue without deposit checks.') }}</p>
                            </div>
                        </div>
                        <span class="text-xs text-muted-foreground font-mono">
                            {{ __('Max Allowed: :max items', ['max' => \App\Services\Library\LibraryPolicyService::getInhouseReadingMaxItems()]) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                        <!-- Left Panel (7 Cols): Barcode Scanner & Multi-Item Tray -->
                        <div class="lg:col-span-7 space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-foreground mb-1.5">
                                    {{ __('1. Scan / Enter Book or Journal Accession Barcode') }} *
                                </label>
                                <div class="flex gap-2">
                                    <div class="relative flex-1">
                                        <x-icon name="barcode" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                                        <input
                                            type="text"
                                            wire:model="inhouseScanBarcode"
                                            wire:keydown.enter.prevent="lookupInhouseCopyBarcode"
                                            placeholder="{{ __('Scan barcode or type ACC-2026-00001...') }}"
                                            class="w-full pl-9 pr-3 py-2 text-sm bg-background border border-border rounded-lg font-mono focus:ring-2 focus:ring-teal-500"
                                        />
                                    </div>
                                    <x-ui.button type="button" variant="default" size="sm" wire:click="lookupInhouseCopyBarcode" class="bg-teal-600 hover:bg-teal-700 text-white shrink-0">
                                        <x-icon name="plus" class="w-3.5 h-3.5 mr-1" />
                                        {{ __('Add to Tray') }}
                                    </x-ui.button>
                                </div>
                            </div>

                            <!-- Selected Items Reading Tray -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-foreground">
                                        {{ __('Reading Room Tray (:count items)', ['count' => count($inhouseSelectedCopyIds)]) }}
                                    </span>
                                    @if(count($inhouseSelectedCopyIds) > 0)
                                        <button type="button" wire:click="$set('inhouseSelectedCopyIds', [])" class="text-rose-500 hover:underline text-[11px]">
                                            {{ __('Clear All') }}
                                        </button>
                                    @endif
                                </div>

                                @if($this->inhouseSelectedCopies->isNotEmpty())
                                    <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                        @foreach($this->inhouseSelectedCopies as $scopy)
                                            <div class="p-3 bg-muted/30 rounded-xl border border-border flex items-center justify-between gap-3 text-xs">
                                                <div class="space-y-0.5 min-w-0">
                                                    <div class="font-bold text-foreground truncate">{{ $scopy->book?->title }}</div>
                                                    <div class="text-muted-foreground text-[11px] flex items-center gap-2">
                                                        <span class="font-mono text-primary font-medium">{{ $scopy->accession_no }}</span>
                                                        <span>&bull;</span>
                                                        <span>{{ __('Shelf:') }} {{ $scopy->shelf_location ?: 'General' }}</span>
                                                        <span>&bull;</span>
                                                        <span class="capitalize px-1.5 py-0.2 rounded bg-muted text-[10px]">{{ $scopy->book?->material_type ?: 'Book' }}</span>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    wire:click="removeInhouseCopy({{ $scopy->id }})"
                                                    class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-500/10 shrink-0"
                                                    title="{{ __('Remove from tray') }}"
                                                >
                                                    <x-icon name="trash" class="w-4 h-4" />
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="p-6 bg-muted/20 border border-dashed border-border rounded-xl text-center text-xs text-muted-foreground space-y-1">
                                        <x-icon name="book-open" class="w-6 h-6 mx-auto text-muted-foreground/40" />
                                        <p>{{ __('No books or journals added to tray yet.') }}</p>
                                        <p class="text-[11px] text-muted-foreground/70">{{ __('Scan an accession barcode above to add materials to this reading session.') }}</p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Right Panel (5 Cols): Reader Details & Desk Confirmation -->
                        <div class="lg:col-span-5 space-y-4 border-t lg:border-t-0 lg:border-l lg:pl-6 border-border">
                            <!-- Borrower / Student Search -->
                            <div>
                                <label class="block text-xs font-semibold text-foreground mb-1.5">
                                    {{ __('2. Student / Member Lookup') }}
                                </label>
                                <div class="relative">
                                    <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        type="text"
                                        wire:model.live.debounce.250ms="inhouseMemberSearchQuery"
                                        placeholder="{{ __('Search by student name, ID, or phone...') }}"
                                        class="w-full pl-9 pr-3 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500"
                                    />
                                </div>

                                @if($this->inhouseMemberSearchResults->isNotEmpty())
                                    <div class="mt-2 bg-card border border-border rounded-xl shadow-lg divide-y divide-border/60 max-h-48 overflow-y-auto">
                                        @foreach($this->inhouseMemberSearchResults as $resMem)
                                            <button
                                                type="button"
                                                wire:click="selectInhouseMember({{ $resMem->id }})"
                                                class="w-full p-2.5 text-left hover:bg-muted/50 transition flex items-center justify-between text-xs"
                                            >
                                                <div>
                                                    <div class="font-bold text-foreground">{{ $resMem->name }}</div>
                                                    <div class="text-[11px] text-muted-foreground font-mono">{{ $resMem->student_id ?: $resMem->member_number }} &bull; {{ $resMem->phone }}</div>
                                                </div>
                                                <span class="text-[10px] text-teal-600 font-semibold">{{ __('Select') }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif

                                @if($inhouseSelectedMember)
                                    <div class="mt-2 p-2.5 bg-teal-50/60 dark:bg-teal-950/40 rounded-xl border border-teal-200 dark:border-teal-800 flex items-center justify-between text-xs">
                                        <div>
                                            <strong class="text-teal-900 dark:text-teal-200">{{ $inhouseSelectedMember->name }}</strong>
                                            <span class="text-[11px] text-teal-700 dark:text-teal-300 block font-mono">{{ $inhouseSelectedMember->student_id ?: $inhouseSelectedMember->member_number }}</span>
                                        </div>
                                        <button type="button" wire:click="clearInhouseMember" class="text-xs text-rose-500 hover:underline">
                                            {{ __('Change') }}
                                        </button>
                                    </div>
                                @endif
                            </div>

                            <!-- Walk-in / Direct Reader Details -->
                            <div class="space-y-2.5">
                                <label class="block text-xs font-semibold text-foreground">
                                    {{ __('Reader Name & Seat Details') }} *
                                </label>
                                <div class="grid grid-cols-2 gap-2">
                                    <input
                                        type="text"
                                        wire:model="inhouseReaderName"
                                        placeholder="{{ __('Reader Name *') }}"
                                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg"
                                        required
                                    />
                                    <input
                                        type="text"
                                        wire:model="inhouseSeatNo"
                                        placeholder="{{ __('Seat / Table # (e.g. Table 4)') }}"
                                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg"
                                    />
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <input
                                        type="text"
                                        wire:model="inhouseReaderId"
                                        placeholder="{{ __('Student ID / Roll / Visitor ID') }}"
                                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg font-mono"
                                    />
                                    <input
                                        type="text"
                                        wire:model="inhouseReaderPhone"
                                        placeholder="{{ __('Contact Phone') }}"
                                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg font-mono"
                                    />
                                </div>
                            </div>

                            <!-- Closing / Due Time -->
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <label class="block font-semibold text-foreground mb-1">{{ __('Return By Time') }}</label>
                                    <input
                                        type="time"
                                        wire:model="inhouseDueTime"
                                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg font-mono"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold text-foreground mb-1">{{ __('Session Notes') }}</label>
                                    <input
                                        type="text"
                                        wire:model="inhouseIssueNotes"
                                        placeholder="{{ __('Optional notes...') }}"
                                        class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg"
                                    />
                                </div>
                            </div>

                            <!-- Zero Deposit Notice -->
                            <div class="p-3 bg-emerald-50/60 dark:bg-emerald-950/30 rounded-xl border border-emerald-200 dark:border-emerald-800 text-[11px] text-emerald-800 dark:text-emerald-300">
                                <span class="font-bold flex items-center gap-1">
                                    <x-icon name="check-circle" class="w-3.5 h-3.5 text-emerald-600" />
                                    {{ __('Zero Security Deposit Check') }}
                                </span>
                                <p class="mt-0.5 text-muted-foreground text-[10px]">
                                    {{ __('Security deposit criteria is bypassed for in-house reading. Material is issued for today and can be retrieved anytime within official hours.') }}
                                </p>
                            </div>

                            <!-- Submit Button -->
                            <x-ui.button
                                type="button"
                                variant="default"
                                size="lg"
                                wire:click="executeInhouseIssue"
                                class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold h-10 shadow-sm"
                                :disabled="empty($inhouseSelectedCopyIds) || empty($inhouseReaderName)"
                            >
                                <x-icon name="check" class="w-4 h-4 mr-1.5" />
                                {{ __('Serve :count Item(s) to Reader', ['count' => count($inhouseSelectedCopyIds)]) }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            @endif

            <!-- ACTIVE IN-HOUSE READING SESSIONS TODAY -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="font-bold text-sm text-foreground flex items-center gap-2">
                        <x-icon name="users" class="w-4 h-4 text-teal-600" />
                        {{ __('Active Reading Room Sessions Today (:count)', ['count' => $this->inhouseSessions->count()]) }}
                    </h4>
                    <span class="text-xs text-muted-foreground">
                        {{ __('Total Items in Rooms: :count', ['count' => $this->activeInhouseCount]) }}
                    </span>
                </div>

                @if($this->inhouseSessions->isNotEmpty())
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($this->inhouseSessions as $sessionToken => $sessionIssues)
                            @php
                                $firstIssue = $sessionIssues->first();
                                $isOverdueToday = now()->format('H:i') > ($firstIssue->due_time ?: '17:00');
                            @endphp
                            <div class="bg-card border {{ $isOverdueToday ? 'border-amber-500/50 dark:border-amber-500/40' : 'border-border' }} rounded-2xl p-4 shadow-xs space-y-3">
                                <!-- Session Header -->
                                <div class="flex items-start justify-between gap-3 pb-2.5 border-b border-border">
                                    <div>
                                        <div class="font-bold text-sm text-foreground flex items-center gap-1.5">
                                            <span>{{ $firstIssue->reader_name }}</span>
                                            @if($firstIssue->reading_room_seat_no)
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-500/10 text-teal-700 dark:text-teal-300 border border-teal-500/20">
                                                    {{ $firstIssue->reading_room_seat_no }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-muted-foreground font-mono mt-0.5">
                                            {{ $firstIssue->reader_identifier ?: 'ID: N/A' }} &bull;
                                            {{ $firstIssue->reader_phone ?: 'No phone' }}
                                        </div>
                                    </div>

                                    <div class="text-right shrink-0">
                                        <div class="text-[10px] font-mono text-muted-foreground">
                                            {{ __('Issued:') }} {{ $firstIssue->created_at->format('h:i A') }}
                                        </div>
                                        <div class="mt-0.5">
                                            @if($isOverdueToday)
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/10 text-rose-600 border border-rose-500/20">
                                                    {{ __('Past Due (:time)', ['time' => $firstIssue->due_time]) }}
                                                </span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                    {{ __('Due by :time', ['time' => $firstIssue->due_time]) }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <!-- Items List -->
                                <div class="space-y-1.5">
                                    <div class="text-[11px] font-semibold text-muted-foreground">
                                        {{ __('Served Materials (:count):', ['count' => $sessionIssues->count()]) }}
                                    </div>
                                    @foreach($sessionIssues as $sItem)
                                        <div class="p-2 rounded-lg bg-muted/30 border border-border/60 flex items-center justify-between gap-2 text-xs">
                                            <div class="min-w-0">
                                                <div class="font-medium text-foreground truncate text-[11px]">{{ $sItem->book?->title }}</div>
                                                <div class="text-[10px] text-muted-foreground font-mono flex items-center gap-1.5">
                                                    <span class="text-primary font-semibold">{{ $sItem->bookCopy?->accession_no }}</span>
                                                    <span>&bull;</span>
                                                    <span>{{ $sItem->bookCopy?->shelf_location ?: 'Stacks' }}</span>
                                                    <span>&bull;</span>
                                                    <span class="capitalize">{{ $sItem->book?->material_type ?: 'book' }}</span>
                                                </div>
                                            </div>

                                            <button
                                                type="button"
                                                wire:click="returnInhouseItem({{ $sItem->id }})"
                                                wire:confirm="{{ __('Take back and return copy ":acc" to stacks?', ['acc' => $sItem->bookCopy?->accession_no]) }}"
                                                class="px-2 py-1 rounded text-[10px] font-semibold bg-background hover:bg-muted text-foreground border border-border shadow-2xs shrink-0"
                                            >
                                                {{ __('Take Back') }}
                                            </button>
                                        </div>
                                    @endforeach
                                </div>

                                <!-- Session Actions -->
                                <div class="pt-2 border-t border-border flex items-center justify-between">
                                    <span class="text-[10px] font-mono text-muted-foreground">
                                        Token: {{ $sessionToken }}
                                    </span>
                                    <x-ui.button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        wire:click="returnInHouseSessionGroup('{{ $sessionToken }}')"
                                        wire:confirm="{{ __('Take back and return all :count items from :reader?', ['count' => $sessionIssues->count(), 'reader' => $firstIssue->reader_name]) }}"
                                        class="text-xs text-teal-700 dark:text-teal-300 border-teal-500/30 hover:bg-teal-500/10 font-semibold"
                                    >
                                        <x-icon name="check-check" class="w-3.5 h-3.5 mr-1 text-teal-600" />
                                        {{ __('Take Back All (:count) Items', ['count' => $sessionIssues->count()]) }}
                                    </x-ui.button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-10 bg-card border border-border rounded-2xl text-center space-y-2">
                        <x-icon name="book-open" class="w-10 h-10 mx-auto text-muted-foreground/30" />
                        <h5 class="font-bold text-sm text-foreground">{{ __('No Active In-House Reading Sessions Today') }}</h5>
                        <p class="text-xs text-muted-foreground max-w-md mx-auto">
                            {{ __('All issued reading room materials have been safely returned. To serve new books or journals to students or visitors for reading inside library rooms, click "+ Serve Books to Reader" above.') }}
                        </p>
                    </div>
                @endif
            </div>
        </div>
    @elseif($activeTab === 'clearance')
        <!-- CLEARANCE & NO-DUES VERIFICATION DESK -->
        <div class="space-y-4">
            <!-- Clearance Overview Banner -->
            <div class="p-5 rounded-2xl bg-gradient-to-r from-teal-500/10 via-emerald-500/10 to-transparent border border-teal-500/20 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="space-y-1">
                    <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                        <x-icon name="award" class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                        {{ __('Library Clearance & No-Dues Verification Desk') }}
                    </h3>
                    <p class="text-xs text-muted-foreground max-w-2xl">
                        {{ __('Review student library security deposit refund clearance requests. Verify that all borrowed books are fully returned and zero overdue fines remain before issuing official clearance certificates.') }}
                    </p>
                </div>
                <div class="shrink-0 flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/30 text-xs font-semibold">
                        <x-icon name="clock" class="w-4 h-4 text-amber-500" />
                        {{ __(':count Pending Requests', ['count' => $this->pendingClearanceCount]) }}
                    </span>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
                <div class="relative">
                    <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by student name, email, student ID, deposit number, certificate number...') }}"
                        class="w-full pl-9 pr-4 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500"
                    />
                </div>
            </div>

            <!-- Clearance Table -->
            <div class="bg-card border border-border rounded-xl overflow-hidden shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-muted/40 border-b border-border text-xs uppercase font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3">{{ __('Student / Member') }}</th>
                                <th class="px-4 py-3">{{ __('Deposit Details') }}</th>
                                <th class="px-3 py-3">{{ __('Request Date & Reason') }}</th>
                                <th class="px-3 py-3 text-center">{{ __('Active Loans Check') }}</th>
                                <th class="px-3 py-3 text-center">{{ __('Unpaid Fines') }}</th>
                                <th class="px-3 py-3 text-center">{{ __('Clearance Status') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Clearance Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse($this->clearanceRequests as $deposit)
                                @php
                                    $eligibility = \App\Services\Library\LibraryClearanceService::checkEligibility($deposit->user_id);
                                @endphp
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-foreground">{{ $deposit->user->name }}</div>
                                        <div class="text-xs text-muted-foreground font-mono">{{ $deposit->user->email }}</div>
                                        @if($deposit->user->student_id)
                                            <span class="inline-block mt-0.5 text-[10px] font-semibold text-teal-600 bg-teal-50 dark:bg-teal-950/40 px-1.5 py-0.5 rounded font-mono">
                                                ID: {{ $deposit->user->student_id }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="font-mono font-bold text-foreground text-xs">{{ $deposit->deposit_no }}</div>
                                        <div class="text-xs font-semibold text-foreground mt-0.5">₹{{ number_format($deposit->amount, 2) }}</div>
                                        <div class="text-[10px] text-muted-foreground">{{ __('Deposited:') }} {{ $deposit->deposit_date ? $deposit->deposit_date->format('d M Y') : 'N/A' }}</div>
                                    </td>

                                    <td class="px-3 py-3 text-xs">
                                        <div class="text-muted-foreground">{{ $deposit->refund_requested_at ? $deposit->refund_requested_at->format('d M Y, h:i A') : 'N/A' }}</div>
                                        <div class="text-[11px] text-foreground font-medium mt-1 italic max-w-xs truncate" title="{{ $deposit->refund_reason }}">
                                            "{{ $deposit->refund_reason }}"
                                        </div>
                                    </td>

                                    <td class="px-3 py-3 text-center whitespace-nowrap">
                                        @if($eligibility['active_loans_count'] > 0)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-500/10 text-rose-600 border border-rose-500/30" title="{{ $eligibility['blocking_reason'] }}">
                                                <x-icon name="alert-triangle" class="w-3.5 h-3.5" />
                                                {{ __(':count Unreturned', ['count' => $eligibility['active_loans_count']]) }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                <x-icon name="check-circle" class="w-3.5 h-3.5" />
                                                {{ __('0 Books Out') }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-3 py-3 text-center whitespace-nowrap">
                                        @if($eligibility['unpaid_fines'] > 0)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-500/10 text-amber-600 border border-amber-500/30">
                                                ₹{{ number_format($eligibility['unpaid_fines'], 2) }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                                ₹0.00
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-3 py-3 text-center whitespace-nowrap">
                                        @if($deposit->clearance_status === \App\Models\LibraryDeposit::CLEARANCE_CLEARED)
                                            <div class="inline-flex flex-col items-center">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/10 text-emerald-600 border border-emerald-500/30">
                                                    <x-icon name="badge-check" class="w-3.5 h-3.5" />
                                                    {{ __('Cleared') }}
                                                </span>
                                                <span class="font-mono text-[10px] text-muted-foreground mt-0.5">{{ $deposit->clearance_certificate_no }}</span>
                                            </div>
                                        @elseif($deposit->clearance_status === \App\Models\LibraryDeposit::CLEARANCE_PENDING)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/10 text-amber-600 border border-amber-500/30">
                                                <x-icon name="clock" class="w-3.5 h-3.5" />
                                                {{ __('Pending Review') }}
                                            </span>
                                        @elseif($deposit->clearance_status === \App\Models\LibraryDeposit::CLEARANCE_REJECTED)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-500/10 text-rose-600 border border-rose-500/30">
                                                <x-icon name="x-circle" class="w-3.5 h-3.5" />
                                                {{ __('Rejected') }}
                                            </span>
                                        @else
                                            <span class="text-xs text-muted-foreground">—</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-right">
                                        @if($deposit->clearance_status === \App\Models\LibraryDeposit::CLEARANCE_PENDING)
                                            @if($eligibility['eligible'])
                                                <button
                                                    type="button"
                                                    wire:click="openClearanceModal({{ $deposit->id }})"
                                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition"
                                                >
                                                    <x-icon name="award" class="w-3.5 h-3.5" />
                                                    {{ __('Issue Clearance') }}
                                                </button>
                                            @else
                                                <div class="inline-flex items-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        wire:click="openRejectClearanceModal({{ $deposit->id }})"
                                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg border border-rose-300 dark:border-rose-800 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition"
                                                    >
                                                        <x-icon name="x-mark" class="w-3.5 h-3.5" />
                                                        {{ __('Reject') }}
                                                    </button>
                                                    <span class="text-[11px] text-rose-600 font-semibold" title="{{ $eligibility['blocking_reason'] }}">
                                                        {{ __('Dues Pending') }}
                                                    </span>
                                                </div>
                                            @endif
                                        @elseif($deposit->clearance_status === \App\Models\LibraryDeposit::CLEARANCE_CLEARED)
                                            <a
                                                href="{{ route('library.clearance.download', $deposit->id) }}"
                                                target="_blank"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold rounded-lg border border-teal-500/30 bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 hover:bg-teal-100 transition"
                                            >
                                                <x-icon name="printer" class="w-3.5 h-3.5" />
                                                {{ __('Print Certificate') }}
                                            </a>
                                        @else
                                            <span class="text-xs text-muted-foreground">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                        <div class="max-w-sm mx-auto space-y-2">
                                            <div class="w-12 h-12 rounded-full bg-teal-50 dark:bg-teal-950/40 text-teal-600 dark:text-teal-400 flex items-center justify-center mx-auto">
                                                <x-icon name="award" class="w-6 h-6" />
                                            </div>
                                            <h4 class="font-bold text-foreground text-sm">{{ __('No Clearance Requests Found') }}</h4>
                                            <p class="text-xs text-muted-foreground">{{ __('When students or members request a library security deposit refund, their clearance verification records will appear here for librarian review.') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="mt-4">
                {{ $this->clearanceRequests->links() }}
            </div>
        </div>
    @elseif($activeTab === 'renewal_requests')
        <!-- RENEWAL REQUESTS APPROVAL DESK -->
        <div class="space-y-4">
            <!-- Header Banner -->
            <div class="p-5 rounded-2xl bg-gradient-to-r from-teal-500/10 via-amber-500/10 to-transparent border border-teal-500/20 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="space-y-1">
                    <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                        <x-icon name="rotate-cw" class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                        {{ __('Online Book Loan Renewal Requests') }}
                    </h3>
                    <p class="text-xs text-muted-foreground max-w-2xl">
                        {{ __('Review and approve or decline loan renewal requests submitted by students and members. Approving extends the due date by 15 days.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20 flex items-center gap-1.5">
                        <x-icon name="clock" class="w-4 h-4 text-amber-600" />
                        {{ __(':count Request(s) Pending Review', ['count' => $this->pendingRenewalRequestsCount]) }}
                    </span>
                </div>
            </div>

            <!-- Pending Requests Cards / Table -->
            <div class="bg-card border border-border rounded-xl overflow-hidden shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-muted/40 border-b border-border text-xs uppercase font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3">{{ __('Borrower / Student') }}</th>
                                <th class="px-4 py-3">{{ __('Book & Copy Details') }}</th>
                                <th class="px-3 py-3">{{ __('Issue & Due Dates') }}</th>
                                <th class="px-3 py-3 text-center">{{ __('Renewals Used') }}</th>
                                <th class="px-3 py-3">{{ __('Requested On & Note') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Review Decision') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            @forelse($this->pendingRenewals as $req)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-foreground">{{ $req->libraryMember?->name }}</div>
                                        <div class="text-xs text-muted-foreground font-mono">
                                            {{ $req->libraryMember?->member_number }}
                                            @if($req->libraryMember?->student_id)
                                                &bull; <span class="text-teal-600 font-semibold">{{ $req->libraryMember?->student_id }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-foreground max-w-xs truncate">{{ $req->book?->title }}</div>
                                        <div class="text-xs text-muted-foreground font-mono flex items-center gap-1.5">
                                            <span>Acc #: <strong class="text-foreground">{{ $req->bookCopy?->accession_no }}</strong></span>
                                            @if($req->book?->classification_no || $req->book?->classification_number)
                                                <span>&bull;</span>
                                                <span>Call: {{ $req->book?->classification_no ?: $req->book?->classification_number }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="text-xs text-muted-foreground">
                                            Issued: <span class="text-foreground font-medium">{{ $req->issue_date->format('d M Y') }}</span>
                                        </div>
                                        <div class="text-xs font-semibold {{ $req->isOverdue() ? 'text-rose-600' : 'text-foreground' }}">
                                            Due: {{ $req->due_date->format('d M Y') }}
                                        </div>
                                        @if($req->isOverdue())
                                            <span class="text-[10px] text-rose-600 font-bold uppercase">{{ __('OVERDUE') }}</span>
                                        @else
                                            <span class="text-[10px] text-emerald-600 font-medium">{{ __(':days days left', ['days' => max(0, (int) now()->diffInDays($req->due_date, false))]) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-center text-xs font-mono">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-muted text-foreground">
                                            {{ $req->renewal_count }} / {{ $req->max_renewals_allowed }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="text-xs font-medium text-foreground">
                                            {{ $req->renewal_requested_at?->format('d M Y, h:i A') ?: 'Recent' }}
                                        </div>
                                        @if($req->renewal_request_reason)
                                            <div class="text-xs text-muted-foreground italic max-w-xs mt-0.5">
                                                "{{ $req->renewal_request_reason }}"
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                wire:click="openApproveRenewalModal({{ $req->id }})"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition cursor-pointer"
                                            >
                                                <x-icon name="check" class="w-3.5 h-3.5" />
                                                {{ __('Approve (+15 Days)') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openRenewalRejectModal({{ $req->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg border border-rose-300 dark:border-rose-800 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition"
                                            >
                                                <x-icon name="x-mark" class="w-3.5 h-3.5" />
                                                {{ __('Decline') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                        <div class="max-w-sm mx-auto space-y-2">
                                            <div class="w-12 h-12 rounded-full bg-teal-50 dark:bg-teal-950/40 text-teal-600 dark:text-teal-400 flex items-center justify-center mx-auto">
                                                <x-icon name="check-circle" class="w-6 h-6" />
                                            </div>
                                            <h4 class="font-bold text-foreground text-sm">{{ __('No Pending Renewal Requests') }}</h4>
                                            <p class="text-xs text-muted-foreground">{{ __('All student loan renewal requests have been reviewed and approved or processed.') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        <!-- LOANS TABLE / CIRCULATION REGISTER -->
        <div class="space-y-4">
            <!-- Filter Bar -->
            <div class="p-4 bg-card border border-border rounded-xl shadow-xs flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between">
                <div class="relative flex-1">
                    <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search issue no, book title, accession no, member name, student ID...') }}"
                        class="w-full pl-9 pr-4 py-2 text-sm bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500"
                    />
                </div>

                @if($activeTab === 'active_loans')
                    <select wire:model.live="statusFilter" class="px-3 py-2 text-sm bg-background border border-border rounded-lg sm:w-44">
                        <option value="">{{ __('All Loan Statuses') }}</option>
                        <option value="issued">{{ __('Active Loans') }}</option>
                        <option value="overdue">{{ __('Overdue Only') }}</option>
                        <option value="renewed">{{ __('Renewed Loans') }}</option>
                    </select>
                @endif
            </div>

            <!-- Table -->
            <div class="bg-card border border-border rounded-xl overflow-hidden shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-muted/40 border-b border-border text-xs uppercase font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3">{{ __('Issue No') }}</th>
                                <th class="px-4 py-3">{{ __('Book & Copy') }}</th>
                                <th class="px-4 py-3">{{ __('Borrower Member') }}</th>
                                <th class="px-3 py-3">{{ __('Issue Date') }}</th>
                                <th class="px-3 py-3">{{ __('Due Date & Timeline') }}</th>
                                <th class="px-3 py-3 text-center">{{ __('Renewals') }}</th>
                                <th class="px-3 py-3 text-center">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Circulation Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            @forelse($this->loans as $loan)
                                <tr class="hover:bg-muted/30 transition-colors {{ $loan->isOverdue() ? 'bg-rose-50/20 dark:bg-rose-950/10' : '' }}">
                                    <td class="px-4 py-3 font-mono font-bold text-foreground">
                                        {{ $loan->issue_no }}
                                        @if($loan->is_deposit_bypassed)
                                            <span class="block text-[10px] text-amber-600 font-semibold" title="{{ $loan->bypass_reason }}">
                                                {{ __('Bypassed Deposit') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-foreground">{{ $loan->book->title }}</div>
                                        <div class="text-xs text-muted-foreground font-mono">
                                            {{ __('Copy:') }} {{ $loan->bookCopy->accession_no }} &bull;
                                            {{ __('Shelf:') }} {{ $loan->bookCopy->shelf_location }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-foreground">{{ $loan->libraryMember->name }}</div>
                                        <div class="text-xs text-muted-foreground font-mono">
                                            {{ $loan->libraryMember->member_number }}
                                            @if($loan->libraryMember->student_id)
                                                &bull; {{ $loan->libraryMember->student_id }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-xs text-muted-foreground">
                                        {{ $loan->issue_date->format('d M Y') }}
                                    </td>
                                    <td class="px-3 py-3 text-xs">
                                        <div class="font-bold {{ $loan->isOverdue() ? 'text-rose-600' : 'text-foreground' }}">
                                            {{ $loan->due_date->format('d M Y') }}
                                        </div>
                                        @if($loan->status === 'returned')
                                            <span class="text-[11px] text-emerald-600 font-medium">
                                                {{ __('Returned on :date', ['date' => $loan->return_date?->format('d M Y')]) }}
                                            </span>
                                        @elseif($loan->isOverdue())
                                            <span class="text-[11px] text-rose-600 font-bold block">
                                                {{ __(':days days OVERDUE', ['days' => $loan->getOverdueDays()]) }}
                                            </span>
                                        @else
                                            <span class="text-[11px] text-muted-foreground">
                                                {{ __(':days days left', ['days' => max(0, (int) now()->diffInDays($loan->due_date, false))]) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-center text-xs font-mono">
                                        {{ $loan->renewal_count }} / {{ $loan->max_renewals_allowed }}
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold {{ match($loan->status) {
                                            'issued' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300 border border-blue-200/50',
                                            'overdue' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300 border border-rose-200/50 animate-pulse',
                                            'renewed' => 'bg-teal-50 text-teal-700 dark:bg-teal-950/50 dark:text-teal-300 border border-teal-200/50',
                                            'returned' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200/50',
                                            default => 'bg-zinc-100 text-zinc-700',
                                        } }}">
                                            {{ ucfirst($loan->status) }}
                                        </span>
                                        @if($loan->renewal_status === \App\Models\BookIssue::RENEWAL_REQUESTED)
                                            <span class="block mt-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-300 dark:border-amber-800 animate-pulse" title="{{ __('Renewal requested: :reason', ['reason' => $loan->renewal_request_reason ?: 'No notes']) }}">
                                                {{ __('Renewal Req.') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if($loan->status !== 'returned')
                                            <div class="flex items-center justify-end gap-1.5">
                                                @if($loan->renewal_status === \App\Models\BookIssue::RENEWAL_REQUESTED)
                                                    <!-- Quick Approve Renewal Button -->
                                                    <button
                                                        type="button"
                                                        wire:click="openApproveRenewalModal({{ $loan->id }})"
                                                        class="p-1 rounded-md text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 cursor-pointer"
                                                        title="{{ __('Approve Renewal (+15 Days)') }}"
                                                    >
                                                        <x-icon name="check-circle" class="w-4 h-4" />
                                                    </button>

                                                    <!-- Quick Reject Renewal Button -->
                                                    <button
                                                        type="button"
                                                        wire:click="openRenewalRejectModal({{ $loan->id }})"
                                                        class="p-1 rounded-md text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/50 cursor-pointer"
                                                        title="{{ __('Decline Renewal Request') }}"
                                                    >
                                                        <x-icon name="x-circle" class="w-4 h-4" />
                                                    </button>
                                                @endif

                                                <!-- Return Button -->
                                                <button wire:click="openReturnModal({{ $loan->id }})" class="px-2 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-xs font-semibold shadow-2xs" title="{{ __('Return Book') }}">
                                                    {{ __('Return') }}
                                                </button>

                                                <!-- Staff Direct Renew Button -->
                                                @if($loan->renewal_count < $loan->max_renewals_allowed && $loan->renewal_status !== \App\Models\BookIssue::RENEWAL_REQUESTED)
                                                    <button type="button" wire:click="openDirectRenewModal({{ $loan->id }})" class="p-1 rounded-md text-teal-600 hover:text-teal-700 hover:bg-teal-50 dark:hover:bg-teal-950/50 cursor-pointer" title="{{ __('Renew (+15 Days)') }}">
                                                        <x-icon name="rotate-cw" class="w-4 h-4" />
                                                    </button>
                                                @endif

                                                <!-- Send Reminder Button -->
                                                <button wire:click="openReminderModal({{ $loan->id }})" class="p-1 rounded-md text-amber-600 hover:text-amber-700 hover:bg-amber-50" title="{{ __('Send Return Reminder') }}">
                                                    <x-icon name="bell" class="w-4 h-4" />
                                                </button>
                                            </div>
                                        @else
                                            <span class="text-xs text-muted-foreground">{{ __('Loan Closed') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                        {{ __('No circulation loan records found.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="mt-4">
                {{ $this->loans->links() }}
            </div>
        </div>
    @endif

    <!-- RETURN BOOK MODAL -->
    @if($returningIssue)
    <x-ui.modal name="return-book-modal" :title="__('Return Book: :title', ['title' => $returningIssue->book->title])" maxWidth="lg">
        <form wire:submit="executeReturn" class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-xl border border-border text-xs space-y-1">
                <div>{{ __('Borrower:') }} <strong>{{ $returningIssue->libraryMember->name }}</strong> ({{ $returningIssue->libraryMember->member_number }})</div>
                <div>{{ __('Accession No:') }} <strong class="font-mono">{{ $returningIssue->bookCopy->accession_no }}</strong></div>
                <div>{{ __('Due Date:') }} <strong>{{ $returningIssue->due_date->format('d M Y') }}</strong></div>
            </div>

            <!-- Overdue Fine Assessment Banner -->
            @if($assessedFineAmount > 0)
                <div class="p-3.5 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-xl space-y-2">
                    <div class="flex items-center justify-between text-xs font-bold text-rose-800 dark:text-rose-300">
                        <span>{{ __('Overdue Fine Assessment') }}</span>
                        <span class="font-mono text-base">₹{{ number_format($assessedFineAmount, 2) }}</span>
                    </div>
                    <p class="text-[11px] text-rose-700 dark:text-rose-400">
                        {{ __('This book is :days days overdue (Rate: ₹:rate/day). A pending fine record will be generated.', [
                            'days' => $assessedOverdueDays,
                            'rate' => \App\Services\Library\LibraryPolicyService::getFinePerDay(),
                        ]) }}
                    </p>

                    <div class="pt-2 border-t border-rose-200 dark:border-rose-800">
                        <label class="flex items-center gap-2 text-xs font-semibold text-rose-900 dark:text-rose-200 cursor-pointer">
                            <input type="checkbox" wire:model.live="waiveFineImmediately" class="rounded text-rose-600" />
                            {{ __('Waive fine immediately (authorized staff waiver)') }}
                        </label>
                        @if($waiveFineImmediately)
                            <input type="text" wire:model="fineWaiverReason" placeholder="{{ __('Reason for waiver (e.g. medical excuse, holiday grace)...') }}" class="mt-2 w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg" required />
                        @endif
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Return Physical Condition') }} *</label>
                <select wire:model="returnCondition" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg">
                    <option value="good">{{ __('Good') }}</option>
                    <option value="fair">{{ __('Fair / Normal Wear') }}</option>
                    <option value="damaged">{{ __('Damaged (Spine / Pages torn)') }}</option>
                    <option value="lost">{{ __('Reported Lost / Missing') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Return Remarks') }}</label>
                <textarea wire:model="returnNotes" rows="2" class="w-full px-3 py-2 text-sm bg-background border border-border rounded-lg" placeholder="{{ __('Optional return condition notes...') }}"></textarea>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'return-book-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-emerald-600 hover:bg-emerald-700 text-white">
                    {{ __('Complete Return') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
    @endif

    <!-- MULTI-CHANNEL RETURN REMINDER MODAL -->
    @if($remindingIssue)
    <x-ui.modal name="send-reminder-modal" :title="__('Send Return Reminder: :title', ['title' => $remindingIssue->book->title])" maxWidth="md">
        <form wire:submit="executeSendReminder" class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-xl border border-border text-xs space-y-1">
                <div>{{ __('Borrower:') }} <strong>{{ $remindingIssue->libraryMember->name }}</strong></div>
                <div>{{ __('Due Date:') }} <strong class="{{ $remindingIssue->isOverdue() ? 'text-rose-600' : '' }}">{{ $remindingIssue->due_date->format('d M Y') }}</strong></div>
                <div>{{ __('Previous Reminders Sent:') }} <strong>{{ $remindingIssue->reminders_count }}</strong></div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-2">{{ __('Select Dispatch Channels:') }}</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer">
                        <input type="checkbox" value="database" wire:model="reminderChannels" class="rounded text-teal-600" />
                        <x-icon name="bell" class="w-3.5 h-3.5 text-teal-600" />
                        {{ __('In-App Notification (Student Dashboard)') }}
                    </label>
                    <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer">
                        <input type="checkbox" value="email" wire:model="reminderChannels" class="rounded text-teal-600" />
                        <x-icon name="mail" class="w-3.5 h-3.5 text-blue-600" />
                        {{ __('Email Notice (:email)', ['email' => $remindingIssue->libraryMember->email ?: 'N/A']) }}
                    </label>
                    <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer">
                        <input type="checkbox" value="sms" wire:model="reminderChannels" class="rounded text-teal-600" />
                        <x-icon name="smartphone" class="w-3.5 h-3.5 text-amber-600" />
                        {{ __('SMS Gateway Notice (:phone)', ['phone' => $remindingIssue->libraryMember->phone ?: 'N/A']) }}
                    </label>
                    <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer">
                        <input type="checkbox" value="whatsapp" wire:model="reminderChannels" class="rounded text-teal-600" />
                        <x-icon name="message-square" class="w-3.5 h-3.5 text-emerald-600" />
                        {{ __('WhatsApp Message') }}
                    </label>
                    <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer">
                        <input type="checkbox" value="telegram" wire:model="reminderChannels" class="rounded text-teal-600" />
                        <x-icon name="send" class="w-3.5 h-3.5 text-sky-600" />
                        {{ __('Telegram Bot Alert') }}
                    </label>
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'send-reminder-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white">
                    <x-icon name="send" class="w-3.5 h-3.5 mr-1" />
                    {{ __('Dispatch Reminder') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
    @endif

    <!-- ISSUE CLEARANCE CERTIFICATE MODAL -->
    @if($selectedClearanceDeposit)
    <x-ui.modal name="issue-clearance-modal" :title="__('Issue Official Library Clearance Certificate')" maxWidth="lg">
        <form wire:submit="executeIssueClearance" class="space-y-4">
            <div class="p-4 bg-muted/40 rounded-xl border border-border space-y-2 text-xs">
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground">{{ __('Student / Member:') }}</span>
                    <strong class="text-foreground font-bold text-sm">{{ $selectedClearanceDeposit->user->name }}</strong>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground">{{ __('Student ID / Email:') }}</span>
                    <span class="font-mono text-foreground">{{ $selectedClearanceDeposit->user->student_id ?: $selectedClearanceDeposit->user->email }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground">{{ __('Security Deposit No:') }}</span>
                    <span class="font-mono text-foreground font-bold">{{ $selectedClearanceDeposit->deposit_no }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground">{{ __('Deposit Refund Amount:') }}</span>
                    <span class="font-bold text-emerald-600 text-sm">₹{{ number_format($selectedClearanceDeposit->amount, 2) }}</span>
                </div>
            </div>

            <!-- Verification Checklist Card -->
            <div class="p-3.5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl space-y-1.5 text-xs text-emerald-800 dark:text-emerald-300">
                <div class="font-bold flex items-center gap-1.5 text-emerald-900 dark:text-emerald-200">
                    <x-icon name="shield-check" class="w-4 h-4 text-emerald-600" />
                    {{ __('Pre-Clearance Verification Checklist') }}
                </div>
                <div class="flex items-center gap-2">
                    <x-icon name="check" class="w-3.5 h-3.5 text-emerald-600" />
                    <span>{{ __('0 (Zero) books currently on loan — all borrowed materials returned.') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <x-icon name="check" class="w-3.5 h-3.5 text-emerald-600" />
                    <span>{{ __('0 overdue fines or loss penalties outstanding.') }}</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">
                    {{ __('Librarian Clearance Endorsement / Remarks') }} *
                </label>
                <textarea
                    wire:model="clearanceRemarks"
                    rows="3"
                    class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg focus:ring-2 focus:ring-teal-500"
                    placeholder="{{ __('Remarks to print on official clearance certificate...') }}"
                    required
                ></textarea>
                <span class="text-[11px] text-muted-foreground mt-1 block">
                    {{ __('This endorsement will be printed on the official Library Clearance Certificate and made available to Admin and the Student.') }}
                </span>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'issue-clearance-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold">
                    <x-icon name="award" class="w-4 h-4 mr-1.5" />
                    {{ __('Generate & Issue Certificate') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- REJECT CLEARANCE MODAL -->
    <x-ui.modal name="reject-clearance-modal" :title="__('Decline Library Clearance Request')" maxWidth="md">
        <form wire:submit="confirmRejectClearance" class="space-y-4">
            <div class="p-3 bg-rose-50 dark:bg-rose-950/40 rounded-xl border border-rose-200 dark:border-rose-800 text-xs text-rose-800 dark:text-rose-300">
                {{ __('Declining will notify the student and halt the security deposit refund process until unreturned books or dues are settled.') }}
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">
                    {{ __('Reason for Declining Clearance') }} *
                </label>
                <textarea
                    wire:model="clearanceRejectReason"
                    rows="3"
                    class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg"
                    placeholder="{{ __('Specify reason (e.g. 1 book pending return, overdue fines unpaid)...') }}"
                    required
                ></textarea>
                @error('clearanceRejectReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'reject-clearance-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="destructive" size="sm" class="bg-rose-600 hover:bg-rose-700 text-white">
                    <x-icon name="x-mark" class="w-3.5 h-3.5 mr-1" />
                    {{ __('Confirm Reject') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
    @endif

    <!-- DECLINE RENEWAL MODAL -->
    @if($renewalTargetIssue)
    <x-ui.modal name="reject-renewal-modal" :title="__('Decline Book Loan Renewal Request')" maxWidth="md">
        <form wire:submit="executeRejectRenewal" class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-xl border border-border text-xs space-y-1">
                <div>{{ __('Book:') }} <strong class="text-foreground">{{ $renewalTargetIssue->book?->title }}</strong></div>
                <div>{{ __('Borrower:') }} <strong class="text-foreground">{{ $renewalTargetIssue->libraryMember?->name }}</strong></div>
                <div>{{ __('Current Due Date:') }} <strong class="{{ $renewalTargetIssue->isOverdue() ? 'text-rose-600' : 'text-foreground' }}">{{ $renewalTargetIssue->due_date->format('d M Y') }}</strong></div>
                @if($renewalTargetIssue->renewal_request_reason)
                    <div class="pt-1 text-muted-foreground">{{ __('Student Request Note:') }} <span class="italic text-foreground">"{{ $renewalTargetIssue->renewal_request_reason }}"</span></div>
                @endif
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">
                    {{ __('Reason for Declining Renewal') }} *
                </label>
                <textarea
                    wire:model="renewalRejectionReason"
                    rows="3"
                    class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg focus:ring-2 focus:ring-rose-500"
                    placeholder="{{ __('Reason (e.g. Reserved by another student, physical copy recall, max timeline reached)...') }}"
                    required
                ></textarea>
                <span class="text-[11px] text-muted-foreground mt-1 block">
                    {{ __('This reason will be visible to the student in their dashboard.') }}
                </span>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'reject-renewal-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="destructive" size="sm" class="bg-rose-600 hover:bg-rose-700 text-white font-bold">
                    <x-icon name="x-mark" class="w-3.5 h-3.5 mr-1" />
                    {{ __('Decline Renewal') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
    @endif

    <!-- APPROVE RENEWAL MODAL -->
    @if($renewalApproveTargetIssue)
    <x-ui.modal name="approve-renewal-modal" :title="__('Approve Book Loan Renewal')" maxWidth="md">
        <form wire:submit="executeApproveRenewal" class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-xl border border-border text-xs space-y-1.5">
                <div>{{ __('Book:') }} <strong class="text-foreground">{{ $renewalApproveTargetIssue->book?->title }}</strong></div>
                <div>{{ __('Accession #:') }} <span class="font-mono font-semibold text-foreground">{{ $renewalApproveTargetIssue->bookCopy?->accession_no }}</span></div>
                <div>{{ __('Borrower:') }} <strong class="text-foreground">{{ $renewalApproveTargetIssue->libraryMember?->name }}</strong> ({{ $renewalApproveTargetIssue->libraryMember?->member_number }})</div>
                @if($renewalApproveTargetIssue->renewal_request_reason)
                    <div class="pt-1 text-muted-foreground">{{ __('Student Request Note:') }} <span class="italic text-foreground">"{{ $renewalApproveTargetIssue->renewal_request_reason }}"</span></div>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 text-xs">
                <div>
                    <span class="text-muted-foreground block">{{ __('Current Due Date') }}</span>
                    <strong class="text-foreground text-sm mt-0.5 block">{{ $renewalApproveTargetIssue->due_date->format('d M Y') }}</strong>
                </div>
                <div>
                    <span class="text-emerald-700 dark:text-emerald-300 font-semibold block">{{ __('New Due Date (+15 Days)') }}</span>
                    <strong class="text-emerald-700 dark:text-emerald-300 text-sm mt-0.5 block">
                        {{ $renewalApproveTargetIssue->due_date->copy()->addDays(15)->format('d M Y') }}
                    </strong>
                    <span class="text-[10px] text-muted-foreground block mt-0.5">
                        {{ __('Renewal :count of :max', ['count' => $renewalApproveTargetIssue->renewal_count + 1, 'max' => $renewalApproveTargetIssue->max_renewals_allowed ?: 2]) }}
                    </span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">
                    {{ __('Approval Endorsement / Notes (Optional)') }}
                </label>
                <input
                    type="text"
                    wire:model="renewalApprovalNotes"
                    placeholder="{{ __('e.g., Approved for examination preparation...') }}"
                    class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg focus:ring-2 focus:ring-emerald-500"
                />
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'approve-renewal-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold">
                    <x-icon name="check" class="w-3.5 h-3.5 mr-1" />
                    {{ __('Confirm Approval') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
    @endif

    <!-- DIRECT STAFF RENEW LOAN MODAL -->
    @if($directRenewTargetIssue)
    <x-ui.modal name="direct-renew-modal" :title="__('Renew Book Loan (+15 Days)')" maxWidth="md">
        <form wire:submit="executeDirectRenew" class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-xl border border-border text-xs space-y-1.5">
                <div>{{ __('Book:') }} <strong class="text-foreground">{{ $directRenewTargetIssue->book?->title }}</strong></div>
                <div>{{ __('Accession #:') }} <span class="font-mono font-semibold text-foreground">{{ $directRenewTargetIssue->bookCopy?->accession_no }}</span></div>
                <div>{{ __('Borrower:') }} <strong class="text-foreground">{{ $directRenewTargetIssue->libraryMember?->name }}</strong> ({{ $directRenewTargetIssue->libraryMember?->member_number }})</div>
            </div>

            <div class="grid grid-cols-2 gap-3 p-3 rounded-xl bg-teal-50 dark:bg-teal-950/30 border border-teal-200 dark:border-teal-800/40 text-xs">
                <div>
                    <span class="text-muted-foreground block">{{ __('Current Due Date') }}</span>
                    <strong class="text-foreground text-sm mt-0.5 block">{{ $directRenewTargetIssue->due_date->format('d M Y') }}</strong>
                </div>
                <div>
                    <span class="text-teal-700 dark:text-teal-300 font-semibold block">{{ __('New Due Date (+15 Days)') }}</span>
                    <strong class="text-teal-700 dark:text-teal-300 text-sm mt-0.5 block">
                        {{ $directRenewTargetIssue->due_date->copy()->addDays(15)->format('d M Y') }}
                    </strong>
                    <span class="text-[10px] text-muted-foreground block mt-0.5">
                        {{ __('Renewal :count of :max', ['count' => $directRenewTargetIssue->renewal_count + 1, 'max' => $directRenewTargetIssue->max_renewals_allowed ?: 2]) }}
                    </span>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                {{ __('Directly extend this active loan by 15 days as an authorized library staff counter action.') }}
            </p>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'direct-renew-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-teal-600 hover:bg-teal-700 text-white font-bold">
                    <x-icon name="rotate-cw" class="w-3.5 h-3.5 mr-1" />
                    {{ __('Confirm Loan Renewal') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
    @endif
</div>

