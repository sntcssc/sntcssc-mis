<?php

use App\Models\BookFine;
use App\Models\BookIssue;
use App\Models\LibraryMember;
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

new #[Layout('layouts.app')] #[Title('Library Overdue & Penalties Desk')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    public string $sortField = 'id';
    public string $sortDirection = 'desc';
    public int $perPage = 15;

    // Collect Payment Modal
    public bool $showPaymentModal = false;
    public ?BookFine $collectingFine = null;
    public float $paymentAmount = 0.00;
    public string $paymentMethod = 'cash'; // 'cash', 'upi', 'bank_transfer'
    public string $paymentReference = '';
    public string $paymentNotes = '';

    // Waive Fine Modal
    public bool $showWaiveModal = false;
    public ?BookFine $waivingFine = null;
    public float $waiveAmount = 0.00;
    public string $waiverReason = '';

    // Manual Penalty Modal
    public bool $showManualPenaltyModal = false;
    public string $memberSearchQuery = '';
    public ?LibraryMember $penaltyMember = null;
    public string $penaltyType = 'damage';
    public float $penaltyAmount = 0.00;
    public string $penaltyReason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total_assessed' => (float) BookFine::selectRaw('COALESCE(SUM(COALESCE(amount, calculated_fine, 0)), 0) as total')->value('total'),
            'pending_balance' => (float) BookFine::where('status', BookFine::STATUS_PENDING)->sum('payable_amount'),
            'total_collected' => (float) BookFine::where('status', BookFine::STATUS_PAID)->sum('paid_amount'),
            'total_waived' => (float) BookFine::sum('waived_amount'),
        ];
    }

    #[Computed]
    public function memberSearchResults(): array
    {
        if (strlen(trim($this->memberSearchQuery)) < 2) {
            return [];
        }

        $term = '%'.trim($this->memberSearchQuery).'%';

        return LibraryMember::query()
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('member_number', 'like', $term)
                    ->orWhere('student_id', 'like', $term);
            })
            ->limit(8)
            ->get()
            ->all();
    }

    public function selectPenaltyMember(int $id): void
    {
        $this->penaltyMember = LibraryMember::find($id);
        $this->memberSearchQuery = '';
    }

    public function openPaymentModal(int $fineId): void
    {
        $this->collectingFine = BookFine::with(['libraryMember', 'bookIssue.book'])->findOrFail($fineId);
        $this->paymentAmount = (float) $this->collectingFine->payable_amount;
        $this->paymentMethod = 'cash';
        $this->paymentReference = '';
        $this->paymentNotes = '';
        $this->showPaymentModal = true;
    }

    public function executePayment(): void
    {
        if (! $this->collectingFine) {
            return;
        }

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01|max:'.$this->collectingFine->payable_amount,
            'paymentMethod' => 'required|in:cash,upi,bank_transfer',
        ]);

        try {
            DB::beginTransaction();

            $this->collectingFine->paid_amount += $this->paymentAmount;
            $this->collectingFine->payable_amount -= $this->paymentAmount;

            if ($this->collectingFine->payable_amount <= 0) {
                $this->collectingFine->status = BookFine::STATUS_PAID;
                $this->collectingFine->paid_at = now();
            }

            $this->collectingFine->payment_method = $this->paymentMethod;
            $this->collectingFine->payment_reference = $this->paymentReference ?: null;
            $this->collectingFine->collected_by_id = auth()->id();
            $this->collectingFine->notes = ($this->collectingFine->notes ? $this->collectingFine->notes."\n" : '').
                sprintf('[%s] Collected ₹%.2f via %s. %s', now()->format('Y-m-d H:i'), $this->paymentAmount, strtoupper($this->paymentMethod), $this->paymentNotes);

            $this->collectingFine->save();

            DB::commit();

            Toast::dispatch($this, 'success', __('Payment of ₹:amt collected successfully for fine :fine.', [
                'amt' => number_format($this->paymentAmount, 2),
                'fine' => $this->collectingFine->fine_number,
            ]));

            $this->showPaymentModal = false;
            $this->collectingFine = null;
        } catch (\Exception $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openWaiveModal(int $fineId): void
    {
        $this->waivingFine = BookFine::with(['libraryMember'])->findOrFail($fineId);
        $this->waiveAmount = (float) $this->waivingFine->payable_amount;
        $this->waiverReason = '';
        $this->showWaiveModal = true;
    }

    public function executeWaive(): void
    {
        if (! $this->waivingFine) {
            return;
        }

        $this->validate([
            'waiverReason' => 'required|string|min:4',
            'waiveAmount' => 'required|numeric|min:0.01|max:'.$this->waivingFine->payable_amount,
        ]);

        try {
            DB::beginTransaction();

            $this->waivingFine->waived_amount += $this->waiveAmount;
            $this->waivingFine->payable_amount -= $this->waiveAmount;
            $this->waivingFine->waiver_reason = $this->waiverReason;
            $this->waivingFine->waived_by_id = auth()->id();

            if ($this->waivingFine->payable_amount <= 0) {
                $this->waivingFine->status = BookFine::STATUS_WAIVED;
            }

            $this->waivingFine->save();

            DB::commit();

            Toast::dispatch($this, 'info', __('Fine :fine waived by ₹:amt. Reason: :reason', [
                'fine' => $this->waivingFine->fine_number,
                'amt' => number_format($this->waiveAmount, 2),
                'reason' => $this->waiverReason,
            ]));

            $this->showWaiveModal = false;
            $this->waivingFine = null;
        } catch (\Exception $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openManualPenaltyModal(): void
    {
        $this->penaltyMember = null;
        $this->penaltyType = 'damage';
        $this->penaltyAmount = 50.00;
        $this->penaltyReason = '';
        $this->showManualPenaltyModal = true;
    }

    public function saveManualPenalty(): void
    {
        if (! $this->penaltyMember) {
            Toast::dispatch($this, 'error', __('Please select a member.'));
            return;
        }

        $this->validate([
            'penaltyAmount' => 'required|numeric|min:1',
            'penaltyReason' => 'required|string|min:4',
            'penaltyType' => 'required|in:damage,lost,manual,overdue',
        ]);

        try {
            DB::beginTransaction();

            $fine = BookFine::create([
                'fine_number' => BookFine::generateFineNumber(),
                'library_member_id' => $this->penaltyMember->id,
                'user_id' => $this->penaltyMember->user_id,
                'fine_type' => $this->penaltyType,
                'amount' => $this->penaltyAmount,
                'payable_amount' => $this->penaltyAmount,
                'paid_amount' => 0.00,
                'waived_amount' => 0.00,
                'status' => BookFine::STATUS_PENDING,
                'notes' => $this->penaltyReason,
                'created_by_id' => auth()->id(),
            ]);

            DB::commit();

            Toast::dispatch($this, 'success', __('Penalty of ₹:amt recorded successfully as :ref.', [
                'amt' => number_format($this->penaltyAmount, 2),
                'ref' => $fine->fine_number,
            ]));

            $this->showManualPenaltyModal = false;
            $this->penaltyMember = null;
        } catch (\Exception $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $fines = $this->queryFines()->get();

        $headers = [
            'Fine Reference', 'Member Name', 'Member No', 'Type',
            'Overdue Days', 'Assessed (INR)', 'Paid (INR)', 'Waived (INR)', 'Balance (INR)', 'Status', 'Date',
        ];

        $rows = $fines->map(fn ($f) => [
            $f->fine_number,
            $f->libraryMember?->name,
            $f->libraryMember?->member_number,
            ucfirst($f->fine_type),
            $f->overdue_days,
            number_format((float) ($f->amount ?? $f->calculated_fine ?? 0), 2),
            number_format((float) ($f->paid_amount ?? 0), 2),
            number_format((float) ($f->waived_amount ?? 0), 2),
            number_format((float) ($f->payable_amount ?? 0), 2),
            ucfirst($f->status),
            $f->created_at?->format('Y-m-d H:i') ?? 'N/A',
        ])->toArray();

        return TableExporter::csv($headers, $rows, 'library_fines_'.now()->format('Ymd_His').'.csv');
    }

    public function export(string $format = 'csv')
    {
        if ($format === 'csv') {
            return $this->exportCsv();
        }

        $query = $this->queryFines();

        return TableExporter::download(
            $query,
            $format,
            'library-fines',
            ['Fine Reference', 'Member Name', 'Member No', 'Type', 'Overdue Days', 'Assessed (INR)', 'Paid (INR)', 'Waived (INR)', 'Balance (INR)', 'Status', 'Date'],
            fn ($f) => [
                $f->fine_number,
                $f->libraryMember?->name,
                $f->libraryMember?->member_number,
                ucfirst($f->fine_type),
                $f->overdue_days,
                number_format((float) ($f->amount ?? $f->calculated_fine ?? 0), 2),
                number_format((float) ($f->paid_amount ?? 0), 2),
                number_format((float) ($f->waived_amount ?? 0), 2),
                number_format((float) ($f->payable_amount ?? 0), 2),
                ucfirst($f->status),
                $f->created_at?->format('Y-m-d H:i') ?? 'N/A',
            ],
            'SNTCSSC Library Fines & Penalties'
        );
    }

    private function queryFines(): Builder
    {
        return BookFine::query()
            ->with(['libraryMember', 'bookIssue.book', 'collectedBy', 'waivedBy'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter, fn ($q) => $q->where('fine_type', $this->typeFilter))
            ->when($this->search, function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('fine_number', 'like', $term)
                        ->orWhereHas('libraryMember', fn ($mq) => $mq->where('name', 'like', $term)->orWhere('member_number', 'like', $term)->orWhere('student_id', 'like', $term))
                        ->orWhereHas('bookIssue.book', fn ($bq) => $bq->where('title', 'like', $term));
                });
            })
            ->orderBy($this->sortField, $this->sortDirection);
    }

    #[Computed]
    public function fines()
    {
        return $this->queryFines()->paginate($this->perPage);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="coins" class="w-6 h-6" />
                </span>
                {{ __('Library Overdue & Penalties Management') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Track overdue loan fines, damage replacements, payment collections, and official waivers.') }}
            </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <!-- Export Dropdown -->
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition"
                    >
                        <x-icon name="download" class="w-4 h-4 text-zinc-400" />
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="w-3.5 h-3.5 text-zinc-400 ml-0.5" />
                    </button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Report') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            <button
                type="button"
                wire:click="openManualPenaltyModal"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl bg-amber-600 hover:bg-amber-700 text-white shadow-sm transition"
            >
                <x-icon name="plus" class="w-4 h-4" />
                {{ __('Add Penalty / Fine') }}
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Total Assessed') }}</span>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">₹{{ number_format($this->stats['total_assessed'], 2) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-rose-200 dark:border-rose-900/40 bg-rose-50/50 dark:bg-rose-950/20 shadow-xs">
            <span class="text-xs font-medium text-rose-700 dark:text-rose-400">{{ __('Pending Collection') }}</span>
            <div class="mt-1 text-2xl font-bold text-rose-900 dark:text-rose-200">₹{{ number_format($this->stats['pending_balance'], 2) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50/50 dark:bg-emerald-950/20 shadow-xs">
            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ __('Collected Revenue') }}</span>
            <div class="mt-1 text-2xl font-bold text-emerald-900 dark:text-emerald-200">₹{{ number_format($this->stats['total_collected'], 2) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-950/20 shadow-xs">
            <span class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Authorized Waivers') }}</span>
            <div class="mt-1 text-2xl font-bold text-blue-900 dark:text-blue-200">₹{{ number_format($this->stats['total_waived'], 2) }}</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col sm:flex-row items-center gap-3">
        <div class="relative flex-1 w-full">
            <x-icon name="magnifying-glass" class="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by fine #, member name, card #, or book title...') }}"
                class="w-full pl-9 pr-4 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:ring-2 focus:ring-amber-500"
            />
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <select
                wire:model.live="typeFilter"
                class="w-full sm:w-36 px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"
            >
                <option value="">{{ __('All Types') }}</option>
                <option value="overdue">{{ __('Overdue') }}</option>
                <option value="damage">{{ __('Damage') }}</option>
                <option value="lost">{{ __('Lost Book') }}</option>
                <option value="manual">{{ __('Manual') }}</option>
            </select>

            <select
                wire:model.live="statusFilter"
                class="w-full sm:w-36 px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"
            >
                <option value="">{{ __('All Statuses') }}</option>
                <option value="pending">{{ __('Pending') }}</option>
                <option value="paid">{{ __('Paid') }}</option>
                <option value="waived">{{ __('Waived') }}</option>
            </select>
        </div>
    </div>

    <!-- Fines Table -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3.5">{{ __('Fine Ref #') }}</th>
                        <th class="px-4 py-3.5">{{ __('Member') }}</th>
                        <th class="px-4 py-3.5">{{ __('Reason / Loan') }}</th>
                        <th class="px-4 py-3.5">{{ __('Total Assessed') }}</th>
                        <th class="px-4 py-3.5">{{ __('Balance Payable') }}</th>
                        <th class="px-4 py-3.5">{{ __('Status') }}</th>
                        <th class="px-4 py-3.5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-zinc-700 dark:text-zinc-300">
                    @forelse($this->fines as $fine)
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-4 py-3.5">
                                <span class="font-mono text-xs font-semibold text-amber-600 dark:text-amber-400">
                                    {{ $fine->fine_number }}
                                </span>
                                <div class="text-2xs text-zinc-400">{{ $fine->created_at->format('d M Y, h:i A') }}</div>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $fine->libraryMember?->name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Card: <span class="font-mono">{{ $fine->libraryMember?->member_number }}</span>
                                    @if($fine->libraryMember?->student_id) &bull; Roll: {{ $fine->libraryMember->student_id }} @endif
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-xs">
                                <div class="font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ ucfirst($fine->fine_type) }} Penalty
                                    @if($fine->overdue_days > 0)
                                        <span class="text-zinc-500 font-normal">({{ $fine->overdue_days }} days overdue)</span>
                                    @endif
                                </div>
                                @if($fine->bookIssue?->book)
                                    <div class="text-zinc-500 truncate max-w-xs">
                                        Book: {{ $fine->bookIssue->book->title }}
                                    </div>
                                @endif
                                @if($fine->waiver_reason)
                                    <div class="text-2xs text-blue-600 dark:text-blue-400 italic">
                                        Waived: {{ $fine->waiver_reason }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                ₹{{ number_format($fine->amount, 2) }}
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="font-bold {{ $fine->payable_amount > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    ₹{{ number_format($fine->payable_amount, 2) }}
                                </span>
                                @if($fine->paid_amount > 0)
                                    <span class="block text-2xs text-emerald-600">Paid: ₹{{ number_format($fine->paid_amount, 2) }}</span>
                                @endif
                                @if($fine->waived_amount > 0)
                                    <span class="block text-2xs text-blue-600">Waived: ₹{{ number_format($fine->waived_amount, 2) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                @if($fine->status === 'pending')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">
                                        {{ __('Unpaid') }}
                                    </span>
                                @elseif($fine->status === 'paid')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                                        {{ __('Settled') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">
                                        {{ __('Waived') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if($fine->payable_amount > 0)
                                        <button
                                            type="button"
                                            wire:click="openPaymentModal({{ $fine->id }})"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white transition shadow-2xs"
                                            title="{{ __('Collect Payment') }}"
                                        >
                                            <x-icon name="credit-card" class="w-3.5 h-3.5" />
                                            {{ __('Collect') }}
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="openWaiveModal({{ $fine->id }})"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg border border-blue-200 dark:border-blue-900/50 bg-blue-50 dark:bg-blue-950/20 text-blue-600 dark:text-blue-400 hover:bg-blue-100 transition"
                                            title="{{ __('Authorize Fine Waiver') }}"
                                        >
                                            <x-icon name="shield-check" class="w-3.5 h-3.5" />
                                            {{ __('Waive') }}
                                        </button>
                                    @else
                                        <span class="text-xs text-zinc-400 italic">{{ __('Closed') }}</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                                <x-icon name="check-circle" class="w-10 h-10 mx-auto text-emerald-400 dark:text-emerald-600 mb-2" />
                                <div class="font-medium text-sm">{{ __('No overdue penalties or fines found.') }}</div>
                                <div class="text-xs text-zinc-400 mt-1">{{ __('All loans are settled or no penalties have been assessed.') }}</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->fines->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $this->fines->links() }}
            </div>
        @endif
    </div>

    <!-- Collect Payment Modal -->
    @if($showPaymentModal && $collectingFine)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="banknotes" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                        {{ __('Collect Fine Payment') }}
                    </h3>
                    <button type="button" wire:click="$set('showPaymentModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-700/60 text-xs space-y-1">
                        <div><strong>Fine Ref:</strong> {{ $collectingFine->fine_number }}</div>
                        <div><strong>Member:</strong> {{ $collectingFine->libraryMember?->name }} ({{ $collectingFine->libraryMember?->member_number }})</div>
                        <div><strong>Balance Due:</strong> <span class="font-bold text-rose-600">₹{{ number_format($collectingFine->payable_amount, 2) }}</span></div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Collection Amount (₹) *') }}
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            wire:model="paymentAmount"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Payment Channel *') }}
                        </label>
                        <select
                            wire:model="paymentMethod"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-emerald-500"
                        >
                            <option value="cash">{{ __('Cash at Library Counter') }}</option>
                            <option value="upi">{{ __('UPI / QR Code') }}</option>
                            <option value="bank_transfer">{{ __('Bank Transfer / Challan') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Transaction Reference / Receipt No') }}
                        </label>
                        <input
                            type="text"
                            wire:model="paymentReference"
                            placeholder="{{ __('e.g., UPI Ref / Manual Receipt #') }}"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showPaymentModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="executePayment"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm"
                    >
                        {{ __('Record Collection') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Waive Modal -->
    @if($showWaiveModal && $waivingFine)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="shield-check" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                        {{ __('Authorize Fine Waiver') }}
                    </h3>
                    <button type="button" wire:click="$set('showWaiveModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-700/60 text-xs space-y-1">
                        <div><strong>Fine Ref:</strong> {{ $waivingFine->fine_number }}</div>
                        <div><strong>Borrower:</strong> {{ $waivingFine->libraryMember?->name }}</div>
                        <div><strong>Current Balance:</strong> ₹{{ number_format($waivingFine->payable_amount, 2) }}</div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Waiver Amount (₹) *') }}
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            wire:model="waiveAmount"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Mandatory Justification / Waiver Reason *') }}
                        </label>
                        <textarea
                            wire:model="waiverReason"
                            rows="2"
                            placeholder="{{ __('State approved reason e.g., authorized by Principal due to medical leave...') }}"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-blue-500"
                        ></textarea>
                        @error('waiverReason') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showWaiveModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="executeWaive"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-sm"
                    >
                        {{ __('Confirm Waiver') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Manual Penalty Modal -->
    @if($showManualPenaltyModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="plus-circle" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                        {{ __('Record Ad-hoc Penalty / Fine') }}
                    </h3>
                    <button type="button" wire:click="$set('showManualPenaltyModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Select Member *') }}
                        </label>
                        @if($penaltyMember)
                            <div class="p-3 rounded-xl border border-amber-200 dark:border-amber-800/60 bg-amber-50/50 dark:bg-amber-950/20 flex items-center justify-between">
                                <div>
                                    <div class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ $penaltyMember->name }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">{{ $penaltyMember->member_number }}</div>
                                </div>
                                <button type="button" wire:click="$set('penaltyMember', null)" class="text-xs font-semibold text-rose-600 hover:underline">
                                    {{ __('Change') }}
                                </button>
                            </div>
                        @else
                            <div class="relative">
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="memberSearchQuery"
                                    placeholder="{{ __('Type member name or card number...') }}"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"
                                />
                                @if(!empty($this->memberSearchResults))
                                    <div class="absolute left-0 right-0 top-full mt-1 z-30 max-h-48 overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 shadow-xl divide-y divide-zinc-100 dark:divide-zinc-700">
                                        @foreach($this->memberSearchResults as $m)
                                            <button
                                                type="button"
                                                wire:click="selectPenaltyMember({{ $m->id }})"
                                                class="w-full text-left px-3 py-2 text-xs hover:bg-amber-50 dark:hover:bg-amber-950/30 transition flex items-center justify-between"
                                            >
                                                <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $m->name }}</span>
                                                <span class="text-zinc-500 font-mono">{{ $m->member_number }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Penalty Type *') }}
                            </label>
                            <select
                                wire:model="penaltyType"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"
                            >
                                <option value="damage">{{ __('Book Damage') }}</option>
                                <option value="lost">{{ __('Lost Book') }}</option>
                                <option value="overdue">{{ __('Overdue Fine') }}</option>
                                <option value="manual">{{ __('General Fee') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Amount (₹) *') }}
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                wire:model="penaltyAmount"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Reason & Description *') }}
                        </label>
                        <textarea
                            wire:model="penaltyReason"
                            rows="2"
                            placeholder="{{ __('Specify reason, e.g., spine torn, pages missing, water damage...') }}"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-amber-500"
                        ></textarea>
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showManualPenaltyModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="saveManualPenalty"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-amber-600 hover:bg-amber-700 text-white shadow-sm"
                    >
                        {{ __('Save Penalty') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
