<?php

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookReservation;
use App\Models\LibraryMember;
use App\Services\Library\BookCirculationService;
use App\Services\Library\LibraryPolicyService;
use App\Services\Library\LibraryReminderService;
use App\Services\Library\LibrarySecurityDepositValidator;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Book Reservations Desk')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'id';
    public string $sortDirection = 'desc';
    public int $perPage = 15;

    // Create Reservation Modal
    public bool $showCreateModal = false;
    public string $memberSearchQuery = '';
    public ?LibraryMember $selectedMember = null;
    public string $bookSearchQuery = '';
    public ?Book $selectedBook = null;
    public string $reservationNotes = '';
    public ?array $securityValidation = null;

    // Fulfill Modal
    public bool $showFulfillModal = false;
    public ?BookReservation $fulfillingReservation = null;
    public ?int $selectedCopyId = null;
    public int $loanDurationDays = 15;
    public bool $bypassDepositCheck = false;
    public string $bypassJustification = '';

    // Cancel Modal
    public bool $showCancelModal = false;
    public ?BookReservation $cancellingReservation = null;
    public string $cancellationReason = '';

    public function mount(): void
    {
        $this->loanDurationDays = LibraryPolicyService::getDefaultLoanDays();
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
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => BookReservation::count(),
            'pending' => BookReservation::where('status', BookReservation::STATUS_PENDING)->count(),
            'fulfilled' => BookReservation::where('status', BookReservation::STATUS_FULFILLED)->count(),
            'expired' => BookReservation::where('status', BookReservation::STATUS_EXPIRED)->count(),
            'cancelled' => BookReservation::where('status', BookReservation::STATUS_CANCELLED)->count(),
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
            ->with(['user.activeLibraryDeposits'])
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('member_number', 'like', $term)
                    ->orWhere('student_id', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            })
            ->limit(8)
            ->get()
            ->all();
    }

    #[Computed]
    public function bookSearchResults(): array
    {
        if (strlen(trim($this->bookSearchQuery)) < 2) {
            return [];
        }

        $term = '%'.trim($this->bookSearchQuery).'%';

        return Book::query()
            ->with(['authors', 'copies'])
            ->where('is_active', true)
            ->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('isbn', 'like', $term)
                    ->orWhere('classification_number', 'like', $term)
                    ->orWhere('classification_no', 'like', $term);
            })
            ->limit(8)
            ->get()
            ->all();
    }

    public function selectMember(int $id): void
    {
        $this->selectedMember = LibraryMember::with(['user.activeLibraryDeposits'])->find($id);
        $this->memberSearchQuery = '';
        $this->validateDepositEligibility();
    }

    public function clearSelectedMember(): void
    {
        $this->selectedMember = null;
        $this->securityValidation = null;
    }

    public function selectBook(int $id): void
    {
        $this->selectedBook = Book::with(['authors', 'copies'])->find($id);
        $this->bookSearchQuery = '';
        $this->validateDepositEligibility();
    }

    public function clearSelectedBook(): void
    {
        $this->selectedBook = null;
        $this->securityValidation = null;
    }

    private function validateDepositEligibility(): void
    {
        if ($this->selectedMember && $this->selectedBook) {
            $this->securityValidation = LibrarySecurityDepositValidator::validateReservationEligibility(
                $this->selectedMember,
                $this->selectedBook
            );
        } else {
            $this->securityValidation = null;
        }
    }

    public function openCreateModal(): void
    {
        $this->selectedMember = null;
        $this->selectedBook = null;
        $this->reservationNotes = '';
        $this->securityValidation = null;
        $this->showCreateModal = true;
    }

    public function createReservation(): void
    {
        if (! $this->selectedMember) {
            $this->addError('member', __('Please select a member.'));
            return;
        }

        if (! $this->selectedBook) {
            $this->addError('book', __('Please select a book to reserve.'));
            return;
        }

        try {
            $reservation = BookCirculationService::reserveBook(
                $this->selectedBook,
                $this->selectedMember,
                $this->reservationNotes
            );

            Toast::dispatch($this, 'success', __('Reservation :number created successfully for :member.', [
                'number' => $reservation->reservation_number,
                'member' => $this->selectedMember->name,
            ]));

            $this->showCreateModal = false;
            $this->resetPage();
        } catch (\Exception $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openFulfillModal(int $reservationId): void
    {
        $this->fulfillingReservation = BookReservation::with(['book.copies', 'libraryMember.user.activeLibraryDeposits'])->findOrFail($reservationId);
        $this->selectedCopyId = $this->fulfillingReservation->book->copies()
            ->where('status', BookCopy::STATUS_AVAILABLE)
            ->first()?->id;
        $this->loanDurationDays = LibraryPolicyService::getDefaultLoanDays();
        $this->bypassDepositCheck = false;
        $this->bypassJustification = '';
        $this->showFulfillModal = true;
    }

    public function executeFulfill(): void
    {
        if (! $this->fulfillingReservation) {
            return;
        }

        if (! $this->selectedCopyId) {
            Toast::dispatch($this, 'error', __('Please select an available physical copy to fulfill this reservation.'));
            return;
        }

        $copy = BookCopy::findOrFail($this->selectedCopyId);
        if ($copy->status !== BookCopy::STATUS_AVAILABLE) {
            Toast::dispatch($this, 'error', __('Selected copy is no longer available.'));
            return;
        }

        try {
            $issue = BookCirculationService::fulfillReservation(
                $this->fulfillingReservation,
                $copy,
                $this->loanDurationDays,
                $this->bypassDepositCheck,
                $this->bypassJustification,
                auth()->user()
            );

            Toast::dispatch($this, 'success', __('Reservation fulfilled! Book issued successfully as Loan #:no.', [
                'no' => $issue->issue_no,
            ]));

            $this->showFulfillModal = false;
            $this->fulfillingReservation = null;
            $this->resetPage();
        } catch (\Exception $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openCancelModal(int $reservationId): void
    {
        $this->cancellingReservation = BookReservation::findOrFail($reservationId);
        $this->cancellationReason = '';
        $this->showCancelModal = true;
    }

    public function executeCancel(): void
    {
        if (! $this->cancellingReservation) {
            return;
        }

        try {
            BookCirculationService::cancelReservation(
                $this->cancellingReservation,
                $this->cancellationReason ?: 'Cancelled by Librarian'
            );

            Toast::dispatch($this, 'success', __('Reservation :no was successfully cancelled.', [
                'no' => $this->cancellingReservation->reservation_number,
            ]));

            $this->showCancelModal = false;
            $this->cancellingReservation = null;
            $this->resetPage();
        } catch (\Exception $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function expireOverdueHolds(): void
    {
        $expiredCount = BookReservation::where('status', BookReservation::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => BookReservation::STATUS_EXPIRED,
                'cancellation_reason' => 'Reservation expired automatically after hold period.',
            ]);

        Toast::dispatch($this, 'info', __(':count expired reservation(s) updated.', ['count' => $expiredCount]));
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $reservations = $this->queryReservations()->get();

        $headers = [
            'Reservation No', 'Book Title', 'ISBN', 'Member Name', 'Member No',
            'Reserved At', 'Expires At', 'Status', 'Cancellation Reason',
        ];

        $rows = $reservations->map(fn ($r) => [
            $r->reservation_number,
            $r->book?->title,
            $r->book?->isbn,
            $r->libraryMember?->name,
            $r->libraryMember?->member_number,
            $r->reserved_at?->format('Y-m-d H:i'),
            $r->expires_at?->format('Y-m-d H:i') ?? 'N/A',
            ucfirst($r->status),
            $r->cancellation_reason ?? '',
        ])->toArray();

        return TableExporter::csv($headers, $rows, 'book_reservations_'.now()->format('Ymd_His').'.csv');
    }

    public function export(string $format = 'csv')
    {
        if ($format === 'csv') {
            return $this->exportCsv();
        }

        $query = $this->queryReservations();

        return TableExporter::download(
            $query,
            $format,
            'book-reservations',
            ['Reservation No', 'Book Title', 'ISBN', 'Member Name', 'Member No', 'Reserved At', 'Expires At', 'Status', 'Cancellation Reason'],
            fn ($r) => [
                $r->reservation_number,
                $r->book?->title,
                $r->book?->isbn,
                $r->libraryMember?->name,
                $r->libraryMember?->member_number,
                $r->reserved_at?->format('Y-m-d H:i') ?? 'N/A',
                $r->expires_at?->format('Y-m-d H:i') ?? 'N/A',
                ucfirst($r->status),
                $r->cancellation_reason ?? '',
            ],
            'SNTCSSC Book Reservations Register'
        );
    }

    private function queryReservations(): Builder
    {
        return BookReservation::query()
            ->with(['book.authors', 'libraryMember.user'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('reservation_number', 'like', $term)
                        ->orWhereHas('book', fn ($bq) => $bq->where('title', 'like', $term)->orWhere('isbn', 'like', $term))
                        ->orWhereHas('libraryMember', fn ($mq) => $mq->where('name', 'like', $term)->orWhere('member_number', 'like', $term)->orWhere('student_id', 'like', $term));
                });
            })
            ->orderBy($this->sortField, $this->sortDirection);
    }

    #[Computed]
    public function reservations()
    {
        return $this->queryReservations()->paginate($this->perPage);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400">
                    <x-icon name="bookmark-check" class="w-6 h-6" />
                </span>
                {{ __('Book Reservations & Hold Queue') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Manage student and member reservation queues, security deposit eligibility, and instant copy fulfillment.') }}
            </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <button
                type="button"
                wire:click="expireOverdueHolds"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition"
            >
                <x-icon name="rotate-cw" class="w-4 h-4 text-zinc-400" />
                {{ __('Prune Expired Holds') }}
            </button>

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
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Roster') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            <button
                type="button"
                wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl bg-purple-600 hover:bg-purple-700 text-white shadow-sm transition"
            >
                <x-icon name="plus" class="w-4 h-4" />
                {{ __('New Reservation') }}
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Total Requests') }}</span>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($this->stats['total']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-950/20 shadow-xs">
            <span class="text-xs font-medium text-amber-700 dark:text-amber-400">{{ __('Pending Holds') }}</span>
            <div class="mt-1 text-2xl font-bold text-amber-900 dark:text-amber-200">{{ number_format($this->stats['pending']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50/50 dark:bg-emerald-950/20 shadow-xs">
            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ __('Fulfilled Loans') }}</span>
            <div class="mt-1 text-2xl font-bold text-emerald-900 dark:text-emerald-200">{{ number_format($this->stats['fulfilled']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-rose-200 dark:border-rose-900/40 bg-rose-50/50 dark:bg-rose-950/20 shadow-xs">
            <span class="text-xs font-medium text-rose-700 dark:text-rose-400">{{ __('Expired Holds') }}</span>
            <div class="mt-1 text-2xl font-bold text-rose-900 dark:text-rose-200">{{ number_format($this->stats['expired']) }}</div>
        </div>
        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Cancelled') }}</span>
            <div class="mt-1 text-2xl font-bold text-zinc-600 dark:text-zinc-400">{{ number_format($this->stats['cancelled']) }}</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col sm:flex-row items-center gap-3">
        <div class="relative flex-1 w-full">
            <x-icon name="magnifying-glass" class="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by reservation #, book title, ISBN, member name, or student ID...') }}"
                class="w-full pl-9 pr-4 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:ring-2 focus:ring-purple-500"
            />
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <select
                wire:model.live="statusFilter"
                class="w-full sm:w-44 px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
            >
                <option value="">{{ __('All Statuses') }}</option>
                <option value="pending">{{ __('Pending Holds') }}</option>
                <option value="fulfilled">{{ __('Fulfilled') }}</option>
                <option value="expired">{{ __('Expired') }}</option>
                <option value="cancelled">{{ __('Cancelled') }}</option>
            </select>
        </div>
    </div>

    <!-- Reservations Table -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3.5">{{ __('Reservation #') }}</th>
                        <th class="px-4 py-3.5">{{ __('Book Title') }}</th>
                        <th class="px-4 py-3.5">{{ __('Borrower / Member') }}</th>
                        <th class="px-4 py-3.5">{{ __('Reserved On') }}</th>
                        <th class="px-4 py-3.5">{{ __('Expires') }}</th>
                        <th class="px-4 py-3.5">{{ __('Status') }}</th>
                        <th class="px-4 py-3.5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-zinc-700 dark:text-zinc-300">
                    @forelse($this->reservations as $reservation)
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-4 py-3.5">
                                <span class="font-mono text-xs font-semibold text-purple-600 dark:text-purple-400">
                                    {{ $reservation->reservation_number }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    @if($reservation->book?->cover_image_url)
                                        <img src="{{ $reservation->book->cover_image_url }}" alt="{{ $reservation->book->title }}" class="w-10 h-14 object-cover rounded shadow-xs" />
                                    @else
                                        <div class="w-10 h-14 rounded bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center text-purple-600 dark:text-purple-400">
                                            <x-icon name="book-open" class="w-5 h-5" />
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100 line-clamp-1">
                                            {{ $reservation->book?->title }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            ISBN: {{ $reservation->book?->isbn ?: 'N/A' }} &bull; ₹{{ number_format($reservation->book?->price ?? 0, 2) }}
                                        </div>
                                        <div class="text-xs text-zinc-400 dark:text-zinc-500">
                                            {{ $reservation->book?->authors->pluck('name')->join(', ') ?: 'Unknown Author' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $reservation->libraryMember?->name }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Card: <span class="font-mono">{{ $reservation->libraryMember?->member_number }}</span>
                                    @if($reservation->libraryMember?->student_id)
                                        &bull; Roll: {{ $reservation->libraryMember->student_id }}
                                    @endif
                                </div>
                                @php
                                    $deposit = $reservation->libraryMember?->getActiveSecurityDeposit() ?? 0;
                                @endphp
                                <div class="text-xs {{ $deposit >= ($reservation->book?->price ?? 0) ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                    Deposit: ₹{{ number_format($deposit, 2) }}
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-xs text-zinc-600 dark:text-zinc-400">
                                <div>{{ $reservation->reserved_at?->format('d M Y') }}</div>
                                <div class="text-zinc-400">{{ $reservation->reserved_at?->format('h:i A') }}</div>
                            </td>
                            <td class="px-4 py-3.5 text-xs">
                                @if($reservation->expires_at)
                                    <div class="{{ $reservation->expires_at->isPast() && $reservation->status === 'pending' ? 'text-rose-600 dark:text-rose-400 font-semibold' : 'text-zinc-600 dark:text-zinc-400' }}">
                                        {{ $reservation->expires_at->format('d M Y') }}
                                    </div>
                                    <div class="text-zinc-400 text-2xs">
                                        {{ $reservation->expires_at->diffForHumans() }}
                                    </div>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                @if($reservation->status === 'pending')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                        {{ __('Pending Hold') }}
                                    </span>
                                @elseif($reservation->status === 'fulfilled')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                                        {{ __('Fulfilled') }}
                                    </span>
                                @elseif($reservation->status === 'expired')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">
                                        {{ __('Expired') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400">
                                        {{ ucfirst($reservation->status) }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if($reservation->status === 'pending')
                                        <button
                                            type="button"
                                            wire:click="openFulfillModal({{ $reservation->id }})"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white transition shadow-2xs"
                                            title="{{ __('Fulfill and issue book copy') }}"
                                        >
                                            <x-icon name="check" class="w-3.5 h-3.5" />
                                            {{ __('Fulfill') }}
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="openCancelModal({{ $reservation->id }})"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg border border-rose-200 dark:border-rose-900/50 bg-rose-50 dark:bg-rose-950/20 text-rose-600 dark:text-rose-400 hover:bg-rose-100 transition"
                                            title="{{ __('Cancel reservation') }}"
                                        >
                                            <x-icon name="x-mark" class="w-3.5 h-3.5" />
                                            {{ __('Cancel') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                                <x-icon name="bookmark-slash" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                                <div class="font-medium text-sm">{{ __('No book reservations found.') }}</div>
                                <div class="text-xs text-zinc-400 mt-1">{{ __('Adjust your search filters or click "New Reservation" to queue a hold.') }}</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->reservations->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $this->reservations->links() }}
            </div>
        @endif
    </div>

    <!-- Create Reservation Modal -->
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="plus-circle" class="w-5 h-5 text-purple-600 dark:text-purple-400" />
                        {{ __('Create New Book Reservation') }}
                    </h3>
                    <button type="button" wire:click="$set('showCreateModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <!-- Member Lookup -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Select Borrower Member / Student *') }}
                        </label>
                        @if($selectedMember)
                            <div class="p-3 rounded-xl border border-purple-200 dark:border-purple-800/60 bg-purple-50/50 dark:bg-purple-950/20 flex items-center justify-between">
                                <div>
                                    <div class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ $selectedMember->name }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        Card #: <span class="font-mono">{{ $selectedMember->member_number }}</span>
                                        @if($selectedMember->student_id) &bull; ID: {{ $selectedMember->student_id }} @endif
                                    </div>
                                    <div class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 mt-0.5">
                                        Security Deposit: ₹{{ number_format($selectedMember->getActiveSecurityDeposit(), 2) }}
                                    </div>
                                </div>
                                <button type="button" wire:click="clearSelectedMember" class="text-xs font-semibold text-rose-600 hover:underline">
                                    {{ __('Change') }}
                                </button>
                            </div>
                        @else
                            <div class="relative">
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="memberSearchQuery"
                                    placeholder="{{ __('Type member name, card #, or student ID...') }}"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                                />
                                @if(!empty($this->memberSearchResults))
                                    <div class="absolute left-0 right-0 top-full mt-1 z-30 max-h-48 overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 shadow-xl divide-y divide-zinc-100 dark:divide-zinc-700">
                                        @foreach($this->memberSearchResults as $m)
                                            <button
                                                type="button"
                                                wire:click="selectMember({{ $m->id }})"
                                                class="w-full text-left px-3 py-2 text-xs hover:bg-purple-50 dark:hover:bg-purple-950/30 transition flex items-center justify-between"
                                            >
                                                <div>
                                                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $m->name }}</span>
                                                    <span class="text-zinc-500">({{ $m->member_number }})</span>
                                                </div>
                                                <span class="text-emerald-600 font-medium">₹{{ number_format($m->getActiveSecurityDeposit(), 2) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                        @error('member') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>

                    <!-- Book Lookup -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Select Book Title to Reserve *') }}
                        </label>
                        @if($selectedBook)
                            <div class="p-3 rounded-xl border border-purple-200 dark:border-purple-800/60 bg-purple-50/50 dark:bg-purple-950/20 flex items-center justify-between">
                                <div>
                                    <div class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ $selectedBook->title }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        ISBN: {{ $selectedBook->isbn ?: 'N/A' }} &bull; Price: ₹{{ number_format($selectedBook->price, 2) }}
                                    </div>
                                </div>
                                <button type="button" wire:click="clearSelectedBook" class="text-xs font-semibold text-rose-600 hover:underline">
                                    {{ __('Change') }}
                                </button>
                            </div>
                        @else
                            <div class="relative">
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="bookSearchQuery"
                                    placeholder="{{ __('Type book title, ISBN, or classification...') }}"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                                />
                                @if(!empty($this->bookSearchResults))
                                    <div class="absolute left-0 right-0 top-full mt-1 z-30 max-h-48 overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 shadow-xl divide-y divide-zinc-100 dark:divide-zinc-700">
                                        @foreach($this->bookSearchResults as $b)
                                            <button
                                                type="button"
                                                wire:click="selectBook({{ $b->id }})"
                                                class="w-full text-left px-3 py-2 text-xs hover:bg-purple-50 dark:hover:bg-purple-950/30 transition flex items-center justify-between"
                                            >
                                                <div>
                                                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $b->title }}</span>
                                                    <span class="text-zinc-500">({{ $b->isbn ?: 'No ISBN' }})</span>
                                                </div>
                                                <span class="font-mono text-zinc-600 dark:text-zinc-300">₹{{ number_format($b->price, 2) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                        @error('book') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </div>

                    <!-- Live Security Deposit Check Feedback -->
                    @if($securityValidation)
                        <div class="p-3 rounded-xl border {{ $securityValidation['eligible'] ? 'border-emerald-200 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-950/20 text-emerald-800 dark:text-emerald-300' : 'border-rose-200 dark:border-rose-900/40 bg-rose-50 dark:bg-rose-950/20 text-rose-800 dark:text-rose-300' }} text-xs">
                            <div class="font-bold flex items-center gap-1.5">
                                <x-icon name="{{ $securityValidation['eligible'] ? 'check-circle' : 'exclamation-circle' }}" class="w-4 h-4" />
                                {{ $securityValidation['eligible'] ? __('Security Deposit Criteria Satisfied') : __('Deposit Criteria Not Met') }}
                            </div>
                            <div class="mt-1">
                                {{ $securityValidation['reason'] }}
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-2xs pt-2 border-t border-current/20">
                                <div>Deposit Available: <strong>₹{{ number_format($securityValidation['active_deposit'], 2) }}</strong></div>
                                <div>Required Book Value: <strong>₹{{ number_format($securityValidation['book_price'], 2) }}</strong></div>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Reservation Notes (Optional)') }}
                        </label>
                        <textarea
                            wire:model="reservationNotes"
                            rows="2"
                            placeholder="{{ __('e.g., student requested priority hold for upcoming civil services exam...') }}"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                        ></textarea>
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showCreateModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="createReservation"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-purple-600 hover:bg-purple-700 text-white shadow-sm"
                    >
                        {{ __('Confirm Reservation') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Fulfill Reservation Modal -->
    @if($showFulfillModal && $fulfillingReservation)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="check-circle" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                        {{ __('Fulfill Reservation & Issue Book') }}
                    </h3>
                    <button type="button" wire:click="$set('showFulfillModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <!-- Reservation Overview -->
                    <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-700/60 text-xs space-y-1">
                        <div><strong>Hold #:</strong> {{ $fulfillingReservation->reservation_number }}</div>
                        <div><strong>Title:</strong> {{ $fulfillingReservation->book?->title }}</div>
                        <div><strong>Borrower:</strong> {{ $fulfillingReservation->libraryMember?->name }} ({{ $fulfillingReservation->libraryMember?->member_number }})</div>
                        <div><strong>Active Deposit:</strong> ₹{{ number_format($fulfillingReservation->libraryMember?->getActiveSecurityDeposit(), 2) }}</div>
                    </div>

                    <!-- Available Copy Selection -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Select Physical Copy (Available in Stack) *') }}
                        </label>
                        @php
                            $availableCopies = $fulfillingReservation->book?->copies()->where('status', BookCopy::STATUS_AVAILABLE)->get() ?? collect();
                        @endphp

                        @if($availableCopies->isEmpty())
                            <div class="p-3 rounded-lg border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/20 text-amber-800 dark:text-amber-300 text-xs">
                                {{ __('Warning: No physical copies are currently marked as Available for this book. A copy must be returned or accessioned before issuing.') }}
                            </div>
                        @else
                            <select
                                wire:model="selectedCopyId"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                            >
                                <option value="">{{ __('-- Choose Copy to Issue --') }}</option>
                                @foreach($availableCopies as $c)
                                    <option value="{{ $c->id }}">
                                        Accession #{{ $c->accession_no }} (Barcode: {{ $c->barcode }}) &bull; Shelf: {{ $c->shelf_location ?: 'General' }} &bull; {{ ucfirst($c->condition) }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <!-- Loan Duration -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Loan Timeline (Days) *') }}
                        </label>
                        <input
                            type="number"
                            wire:model="loanDurationDays"
                            min="1"
                            max="90"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                        />
                        <span class="text-2xs text-zinc-400">{{ __('Default borrowing duration is 15 days.') }}</span>
                    </div>

                    <!-- Deposit Bypass Feature -->
                    <div class="p-3 rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-950/20 space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model.live="bypassDepositCheck"
                                class="w-4 h-4 text-purple-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-purple-500"
                            />
                            <span class="text-xs font-semibold text-amber-900 dark:text-amber-200">
                                {{ __('Librarian Security Deposit Bypass') }}
                            </span>
                        </label>
                        <p class="text-2xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Enable this special bypass feature if issuing to staff or under special authorization without requiring active security deposit.') }}
                        </p>
                        @if($bypassDepositCheck)
                            <div class="pt-2">
                                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                    {{ __('Mandatory Justification for Bypass *') }}
                                </label>
                                <textarea
                                    wire:model="bypassJustification"
                                    rows="2"
                                    placeholder="{{ __('Enter formal reason for bypassing deposit validation...') }}"
                                    class="w-full px-3 py-2 text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                                ></textarea>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showFulfillModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="executeFulfill"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm"
                    >
                        {{ __('Issue Book & Fulfill') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Cancel Modal -->
    @if($showCancelModal && $cancellingReservation)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="x-circle" class="w-5 h-5 text-rose-600 dark:text-rose-400" />
                        {{ __('Cancel Reservation') }}
                    </h3>
                    <button type="button" wire:click="$set('showCancelModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-3">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Are you sure you want to cancel reservation') }} <strong>{{ $cancellingReservation->reservation_number }}</strong>?
                    </p>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Cancellation Reason') }}
                        </label>
                        <input
                            type="text"
                            wire:model="cancellationReason"
                            placeholder="{{ __('e.g., student cancelled request, hold window passed...') }}"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                        />
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showCancelModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50"
                    >
                        {{ __('Dismiss') }}
                    </button>
                    <button
                        type="button"
                        wire:click="executeCancel"
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-rose-600 hover:bg-rose-700 text-white shadow-sm"
                    >
                        {{ __('Confirm Cancel') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
