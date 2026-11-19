<?php

use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\BookFine;
use App\Models\BookIssue;
use App\Models\BookReservation;
use App\Models\LibraryMember;
use App\Services\Library\BookCirculationService;
use App\Services\Library\LibraryCardService;
use App\Services\Library\LibraryPolicyService;
use App\Services\Library\LibrarySecurityDepositValidator;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Library & Book Catalog')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'catalog'; // 'catalog', 'my_loans', 'my_reservations', 'my_fines'

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'cat')]
    public ?int $selectedCategoryId = null;

    public bool $inStockOnly = false;
    public int $perPage = 12;

    // Book Detail Modal
    public bool $showDetailModal = false;
    public ?Book $selectedBook = null;
    public ?array $securityValidation = null;

    // Multi-Select Reservation
    public array $selectedBookIds = [];
    public bool $showBatchReserveModal = false;
    public string $reservationNotes = '';

    // Loan Renewal Request Modal
    public bool $showRenewalModal = false;
    public ?int $renewalLoanId = null;
    public ?BookIssue $selectedLoanForRenewal = null;
    public string $renewalReason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedInStockOnly(): void
    {
        $this->resetPage();
    }

    public function selectTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    #[Computed]
    public function member(): ?LibraryMember
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return LibraryCardService::getOrCreateMemberForUser($user);
    }

    #[Computed]
    public function activeDeposit(): float
    {
        return $this->member ? $this->member->getActiveSecurityDeposit() : 0.00;
    }

    #[Computed]
    public function currentBorrowedValue(): float
    {
        if (! $this->member) {
            return 0.00;
        }

        return $this->member->activeIssues()
            ->with('book')
            ->get()
            ->sum(fn ($i) => (float) ($i->book?->price ?? 0));
    }

    #[Computed]
    public function borrowingCapacity(): float
    {
        return max(0.00, $this->activeDeposit - $this->currentBorrowedValue);
    }

    #[Computed]
    public function categories()
    {
        return BookCategory::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function books()
    {
        return Book::query()
            ->with(['category', 'authors', 'publisher', 'copies'])
            ->where('is_active', true)
            ->when($this->search, function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', $term)
                        ->orWhere('isbn', 'like', $term)
                        ->orWhere('classification_number', 'like', $term)
                        ->orWhere('classification_no', 'like', $term)
                        ->orWhereHas('authors', fn ($aq) => $aq->where('name', 'like', $term));
                });
            })
            ->when($this->selectedCategoryId, fn ($q) => $q->where('category_id', $this->selectedCategoryId))
            ->when($this->inStockOnly, function (Builder $q) {
                $q->whereHas('copies', fn ($cq) => $cq->where('status', BookCopy::STATUS_AVAILABLE));
            })
            ->orderBy('title')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function myLoans()
    {
        if (! $this->member) {
            return collect();
        }

        return BookIssue::query()
            ->with(['book.authors', 'bookCopy'])
            ->where('library_member_id', $this->member->id)
            ->whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_OVERDUE, BookIssue::STATUS_RENEWED])
            ->orderBy('due_date', 'asc')
            ->get();
    }

    #[Computed]
    public function myReservations()
    {
        if (! $this->member) {
            return collect();
        }

        return BookReservation::query()
            ->with(['book.authors'])
            ->where('library_member_id', $this->member->id)
            ->orderBy('id', 'desc')
            ->get();
    }

    #[Computed]
    public function myFines()
    {
        if (! $this->member) {
            return collect();
        }

        return BookFine::query()
            ->with(['bookIssue.book'])
            ->where('library_member_id', $this->member->id)
            ->orderBy('id', 'desc')
            ->get();
    }

    public function viewBook(int $id): void
    {
        $this->selectedBook = Book::with(['category', 'authors', 'publisher', 'copies'])->findOrFail($id);
        if ($this->member) {
            $this->securityValidation = LibrarySecurityDepositValidator::validateReservationEligibility(
                $this->member,
                $this->selectedBook
            );
        }
        $this->showDetailModal = true;
    }

    public function toggleBookSelection(int $id): void
    {
        if (in_array($id, $this->selectedBookIds)) {
            $this->selectedBookIds = array_values(array_diff($this->selectedBookIds, [$id]));
        } else {
            $this->selectedBookIds[] = $id;
        }
    }

    public function reserveSelectedBook(): void
    {
        if (! $this->selectedBook || ! $this->member) {
            return;
        }

        $validation = LibrarySecurityDepositValidator::validateReservationEligibility($this->member, $this->selectedBook);
        if (! $validation['eligible']) {
            Toast::dispatch($this, 'error', $validation['reason']);
            return;
        }

        try {
            $reservation = BookCirculationService::reserveBook(
                $this->selectedBook,
                $this->member,
                $this->reservationNotes
            );

            Toast::dispatch($this, 'success', __('Reservation :number placed successfully for ":title"!', [
                'number' => $reservation->reservation_number,
                'title' => $this->selectedBook->title,
            ]));

            $this->showDetailModal = false;
            $this->selectedBook = null;
            $this->activeTab = 'my_reservations';
        } catch (\Exception $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openBatchReserveModal(): void
    {
        if (empty($this->selectedBookIds)) {
            Toast::dispatch($this, 'error', __('Please select at least one book to reserve.'));
            return;
        }

        $this->reservationNotes = '';
        $this->showBatchReserveModal = true;
    }

    public function submitBatchReservation(): void
    {
        if (empty($this->selectedBookIds) || ! $this->member) {
            return;
        }

        $books = Book::whereIn('id', $this->selectedBookIds)->get();
        $totalBatchPrice = $books->sum('price');

        if (LibraryPolicyService::isSecurityDepositRequired()) {
            if ($totalBatchPrice > $this->borrowingCapacity) {
                Toast::dispatch($this, 'error', __('Total price of requested books (₹:price) exceeds your available deposit headroom (₹:avail). Please request fewer books or top up your library security deposit.', [
                    'price' => number_format($totalBatchPrice, 2),
                    'avail' => number_format($this->borrowingCapacity, 2),
                ]));
                return;
            }
        }

        $successCount = 0;
        foreach ($books as $book) {
            try {
                BookCirculationService::reserveBook($book, $this->member, $this->reservationNotes);
                $successCount++;
            } catch (\Exception $e) {
                // Continue with next
            }
        }

        Toast::dispatch($this, 'success', __(':count book reservation(s) placed successfully!', ['count' => $successCount]));
        $this->selectedBookIds = [];
        $this->showBatchReserveModal = false;
        $this->activeTab = 'my_reservations';
    }

    public function cancelReservation(int $id): void
    {
        $res = BookReservation::where('id', $id)->where('library_member_id', $this->member?->id)->firstOrFail();
        try {
            BookCirculationService::cancelReservation($res, 'Cancelled by student from portal');
            Toast::dispatch($this, 'info', __('Reservation :no has been cancelled.', ['no' => $res->reservation_number]));
        } catch (\Exception $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openRenewalModal(int $loanId): void
    {
        $loan = BookIssue::with(['book.authors', 'bookCopy'])
            ->where('id', $loanId)
            ->where('library_member_id', $this->member?->id)
            ->firstOrFail();

        $this->renewalLoanId = $loan->id;
        $this->selectedLoanForRenewal = $loan;
        $this->renewalReason = '';
        $this->showRenewalModal = true;
    }

    public function closeRenewalModal(): void
    {
        $this->showRenewalModal = false;
        $this->renewalLoanId = null;
        $this->selectedLoanForRenewal = null;
        $this->renewalReason = '';
    }

    public function submitRenewalRequest(): void
    {
        if (! $this->renewalLoanId || ! $this->member) {
            return;
        }

        $issue = BookIssue::where('id', $this->renewalLoanId)
            ->where('library_member_id', $this->member->id)
            ->firstOrFail();

        try {
            $reason = trim($this->renewalReason) ?: 'Requested by student via student portal';
            $updated = BookCirculationService::requestLoanRenewal(
                $issue,
                $reason,
                LibraryPolicyService::getDefaultLoanDays(),
                auth()->user()
            );

            if ($updated->renewal_status === BookIssue::RENEWAL_REQUESTED) {
                Toast::dispatch($this, 'info', __('Renewal request submitted! Awaiting Librarian / Admin approval.'));
            } else {
                Toast::dispatch($this, 'success', __('Loan renewed! New due date is :due.', ['due' => $updated->due_date->format('d M Y')]));
            }

            $this->closeRenewalModal();
        } catch (\Exception $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function requestRenewal(int $issueId, string $reason = 'Requested by student via student portal'): void
    {
        $issue = BookIssue::where('id', $issueId)->where('library_member_id', $this->member?->id)->firstOrFail();

        try {
            $updated = BookCirculationService::requestLoanRenewal($issue, $reason, LibraryPolicyService::getDefaultLoanDays(), auth()->user());
            if ($updated->renewal_status === BookIssue::RENEWAL_REQUESTED) {
                Toast::dispatch($this, 'info', __('Renewal request submitted! Awaiting Librarian / Admin approval.'));
            } else {
                Toast::dispatch($this, 'success', __('Loan renewed! New due date is :due.', ['due' => $updated->due_date->format('d M Y')]));
            }
        } catch (\Exception $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Top Summary Banner -->
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-gradient-to-r from-purple-900/10 via-indigo-900/10 to-blue-900/10 p-5 shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="inline-flex items-center justify-center p-2 rounded-xl bg-purple-600 text-white shadow-xs">
                        <x-icon name="book-open" class="w-6 h-6" />
                    </span>
                    {{ __('Library Catalog & Borrowing Portal') }}
                </h1>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Browse library catalog, check shelf availability, manage loans with 15-day timeline, and reserve books within your deposit threshold.') }}
                </p>
            </div>

            <!-- Member Badge & Quick Stats -->
            <div class="flex items-center gap-3 flex-wrap">
                <div class="px-3 py-2 rounded-xl border border-emerald-200 dark:border-emerald-900/50 bg-emerald-50/80 dark:bg-emerald-950/30 text-xs">
                    <span class="text-emerald-700 dark:text-emerald-400 font-medium block">{{ __('Security Deposit') }}</span>
                    <strong class="text-sm font-bold text-emerald-900 dark:text-emerald-200">₹{{ number_format($this->activeDeposit, 2) }}</strong>
                </div>

                <div class="px-3 py-2 rounded-xl border border-purple-200 dark:border-purple-900/50 bg-purple-50/80 dark:bg-purple-950/30 text-xs">
                    <span class="text-purple-700 dark:text-purple-400 font-medium block">{{ __('Available Borrowing Headroom') }}</span>
                    <strong class="text-sm font-bold text-purple-900 dark:text-purple-200">₹{{ number_format($this->borrowingCapacity, 2) }}</strong>
                </div>

                <a
                    href="{{ route('student.library.card') }}"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold rounded-xl bg-blue-600 hover:bg-blue-700 text-white shadow-xs transition"
                >
                    <x-icon name="id-card" class="w-4 h-4" />
                    {{ __('Digital Library Card') }}
                </a>

                @if($this->activeDeposit <= 0)
                    <a
                        href="{{ route('student.library-deposit.index') }}"
                        class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold rounded-xl bg-amber-600 hover:bg-amber-700 text-white shadow-xs transition"
                    >
                        <x-icon name="plus" class="w-3.5 h-3.5" />
                        {{ __('Pay Security Deposit') }}
                    </a>
                @endif
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
        <div class="flex items-center gap-6">
            <button
                type="button"
                wire:click="selectTab('catalog')"
                class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'catalog' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="magnifying-glass" class="w-4 h-4" />
                {{ __('Book Catalog') }}
            </button>

            <button
                type="button"
                wire:click="selectTab('my_loans')"
                class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'my_loans' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="clock" class="w-4 h-4" />
                {{ __('My Borrowed Books') }}
                @if(count($this->myLoans) > 0)
                    <span class="px-1.5 py-0.5 rounded-full text-2xs font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/60 dark:text-purple-300">
                        {{ count($this->myLoans) }}
                    </span>
                @endif
            </button>

            <button
                type="button"
                wire:click="selectTab('my_reservations')"
                class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'my_reservations' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="bookmark" class="w-4 h-4" />
                {{ __('My Book Holds') }}
                @if(count($this->myReservations) > 0)
                    <span class="px-1.5 py-0.5 rounded-full text-2xs font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-300">
                        {{ count($this->myReservations) }}
                    </span>
                @endif
            </button>

            <button
                type="button"
                wire:click="selectTab('my_fines')"
                class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'my_fines' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
            >
                <x-icon name="coins" class="w-4 h-4" />
                {{ __('Fines & Penalties') }}
            </button>
        </div>

        @if(!empty($selectedBookIds))
            <button
                type="button"
                wire:click="openBatchReserveModal"
                class="mb-2 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-purple-600 text-white shadow-xs hover:bg-purple-700"
            >
                <x-icon name="bookmark-check" class="w-4 h-4" />
                {{ __('Reserve Selected (:count)', ['count' => count($selectedBookIds)]) }}
            </button>
        @endif
    </div>

    <!-- Tab 1: Book Catalog Browser -->
    @if($activeTab === 'catalog')
        <!-- Search & Category Filters -->
        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col sm:flex-row items-center gap-3">
            <div class="relative flex-1 w-full">
                <x-icon name="magnifying-glass" class="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by title, author, ISBN, or topic...') }}"
                    class="w-full pl-9 pr-4 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:ring-2 focus:ring-purple-500"
                />
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto">
                <select
                    wire:model.live="selectedCategoryId"
                    class="w-full sm:w-48 px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                >
                    <option value="">{{ __('All Categories') }}</option>
                    @foreach($this->categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>

                <label class="flex items-center gap-2 text-xs font-semibold text-zinc-700 dark:text-zinc-300 shrink-0 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model.live="inStockOnly"
                        class="w-4 h-4 text-purple-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-purple-500"
                    />
                    {{ __('In-Stock Only') }}
                </label>
            </div>
        </div>

        <!-- Books Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @forelse($this->books as $book)
                @php
                    $availableCopies = $book->copies->where('status', BookCopy::STATUS_AVAILABLE)->count();
                    $isSelected = in_array($book->id, $selectedBookIds);
                @endphp
                <div class="rounded-2xl border {{ $isSelected ? 'border-purple-500 ring-2 ring-purple-500/20' : 'border-zinc-200 dark:border-zinc-800' }} bg-white dark:bg-zinc-900 shadow-xs overflow-hidden flex flex-col hover:border-purple-300 dark:hover:border-purple-700 transition">
                    <!-- Book Cover & Quick Selection -->
                    <div class="relative h-48 bg-zinc-100 dark:bg-zinc-800/80 flex items-center justify-center overflow-hidden">
                        @if($book->cover_image_url)
                            <img src="{{ $book->cover_image_url }}" alt="{{ $book->title }}" class="w-full h-full object-cover" />
                        @else
                            <div class="p-6 text-center text-zinc-400">
                                <x-icon name="book-open" class="w-12 h-12 mx-auto text-purple-400 dark:text-purple-600 mb-1" />
                                <span class="text-2xs font-mono uppercase">{{ $book->category?->name ?? 'General' }}</span>
                            </div>
                        @endif

                        <!-- Checkbox Overlay for Batch Reservation -->
                        <div class="absolute top-2 left-2">
                            <input
                                type="checkbox"
                                wire:click="toggleBookSelection({{ $book->id }})"
                                {{ $isSelected ? 'checked' : '' }}
                                class="w-4 h-4 text-purple-600 rounded border-zinc-300 bg-white shadow focus:ring-purple-500"
                            />
                        </div>

                        <!-- Price Tag -->
                        <div class="absolute bottom-2 right-2 px-2 py-0.5 rounded-lg bg-black/75 backdrop-blur-xs text-white text-xs font-bold">
                            ₹{{ number_format($book->price, 2) }}
                        </div>
                    </div>

                    <!-- Book Body -->
                    <div class="p-4 flex-1 flex flex-col justify-between">
                        <div>
                            <span class="text-2xs font-semibold text-purple-600 dark:text-purple-400 uppercase tracking-wider block">
                                {{ $book->category?->name ?? 'General' }}
                            </span>
                            <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 line-clamp-2 mt-0.5" title="{{ $book->title }}">
                                {{ $book->title }}
                            </h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 line-clamp-1">
                                {{ $book->authors->pluck('name')->join(', ') ?: 'Unknown Author' }}
                            </p>
                            <div class="text-2xs text-zinc-400 font-mono mt-1">
                                ISBN: {{ $book->isbn ?: 'N/A' }}
                            </div>
                        </div>

                        <!-- Shelf & Stock Footer -->
                        <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-2xs font-semibold {{ $availableCopies > 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300' }}">
                                {{ $availableCopies > 0 ? __(':count Available', ['count' => $availableCopies]) : __('On Loan / Reserved') }}
                            </span>

                            <button
                                type="button"
                                wire:click="viewBook({{ $book->id }})"
                                class="text-xs font-semibold text-purple-600 dark:text-purple-400 hover:underline"
                            >
                                {{ __('Details & Hold &rarr;') }}
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full py-16 text-center text-zinc-500 dark:text-zinc-400">
                    <x-icon name="book-open" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                    <div class="font-medium text-sm">{{ __('No books found matching your criteria.') }}</div>
                    <div class="text-xs text-zinc-400 mt-1">{{ __('Try refining your search keyword or clearing the category filter.') }}</div>
                </div>
            @endforelse
        </div>

        @if($this->books->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $this->books->links() }}
            </div>
        @endif
    @endif

    <!-- Tab 2: My Active Borrowed Books -->
    @if($activeTab === 'my_loans')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-800">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <x-icon name="clock" class="w-4 h-4 text-purple-600" />
                    {{ __('Currently Issued Books (15-Day Timeline)') }}
                </h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ __('Books must be renewed or returned within 15 days to avoid overdue fines.') }}
                </p>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse($this->myLoans as $loan)
                    @php
                        $isOverdue = $loan->due_date->isPast();
                        $diffDays = (int) now()->startOfDay()->diffInDays($loan->due_date->startOfDay(), false);
                    @endphp
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="w-12 h-16 rounded bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center shrink-0 text-purple-600 dark:text-purple-400">
                                <x-icon name="book-open" class="w-6 h-6" />
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ $loan->book?->title }}</h4>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Accession #: <span class="font-mono font-semibold">{{ $loan->bookCopy?->accession_no }}</span>
                                    &bull; Price: ₹{{ number_format($loan->book?->price ?? 0, 2) }}
                                </div>
                                <div class="text-xs text-zinc-400 mt-0.5">
                                    Issued: {{ $loan->issue_date->format('d M Y') }} &bull; Renewals: {{ $loan->renewal_count }} / 2
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <div class="text-xs font-semibold {{ $isOverdue ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                                    Due: {{ $loan->due_date->format('d M Y') }}
                                </div>
                                <span class="text-2xs font-semibold {{ $isOverdue ? 'text-rose-600' : 'text-emerald-600' }}">
                                    {{ $isOverdue ? abs($diffDays).' days overdue' : $diffDays.' days remaining' }}
                                </span>
                            </div>

                            @if($loan->renewal_status === \App\Models\BookIssue::RENEWAL_REQUESTED)
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800/50">
                                    <x-icon name="clock" class="w-3.5 h-3.5 text-amber-600 animate-pulse" />
                                    {{ __('Renewal Pending Approval') }}
                                </span>
                            @elseif($loan->renewal_count < 2 && ! $isOverdue)
                                <div class="flex flex-col items-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="openRenewalModal({{ $loan->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-purple-600 hover:bg-purple-700 text-white shadow-2xs transition cursor-pointer"
                                    >
                                        <x-icon name="rotate-cw" class="w-3.5 h-3.5" />
                                        {{ __('Request Renewal (15 Days)') }}
                                    </button>
                                    @if($loan->renewal_status === \App\Models\BookIssue::RENEWAL_REJECTED)
                                        <span class="text-[10px] text-rose-600 dark:text-rose-400 font-medium" title="{{ $loan->renewal_rejection_reason }}">
                                            {{ __('Previous request rejected') }}@if($loan->renewal_rejection_reason): {{ Str::limit($loan->renewal_rejection_reason, 25) }}@endif
                                        </span>
                                    @endif
                                </div>
                            @elseif($isOverdue)
                                <span class="px-2.5 py-1 text-2xs font-bold rounded-lg bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                                    {{ __('Return to Counter') }}
                                </span>
                            @else
                                <span class="text-xs text-zinc-400 italic">{{ __('Max renewals reached') }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-zinc-500 dark:text-zinc-400">
                        <x-icon name="check-circle" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                        <div class="font-medium text-sm">{{ __('No books currently borrowed.') }}</div>
                        <div class="text-xs text-zinc-400 mt-1">{{ __('Browse our catalog and request a book to read.') }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <!-- Tab 3: My Book Reservations -->
    @if($activeTab === 'my_reservations')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-800">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <x-icon name="bookmark" class="w-4 h-4 text-amber-500" />
                    {{ __('My Active Book Holds & Reservations') }}
                </h3>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse($this->myReservations as $reservation)
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-purple-600 dark:text-purple-400">
                                    {{ $reservation->reservation_number }}
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-2xs font-semibold {{ $reservation->status === 'pending' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : ($reservation->status === 'fulfilled' ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-100 text-zinc-500') }}">
                                    {{ ucfirst($reservation->status) }}
                                </span>
                            </div>
                            <h4 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 mt-1">{{ $reservation->book?->title }}</h4>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                Reserved: {{ $reservation->reserved_at?->format('d M Y, h:i A') }}
                                @if($reservation->expires_at)
                                    &bull; Hold expires: {{ $reservation->expires_at->format('d M Y') }}
                                @endif
                            </div>
                        </div>

                        <div>
                            @if($reservation->status === 'pending')
                                <button
                                    type="button"
                                    wire:click="cancelReservation({{ $reservation->id }})"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-rose-200 dark:border-rose-900/50 bg-rose-50 dark:bg-rose-950/20 text-rose-600 dark:text-rose-400 hover:bg-rose-100"
                                >
                                    {{ __('Cancel Hold') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-zinc-500 dark:text-zinc-400">
                        <x-icon name="bookmark-slash" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                        <div class="font-medium text-sm">{{ __('No active book reservations.') }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <!-- Tab 4: Fines & Penalties -->
    @if($activeTab === 'my_fines')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-800">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <x-icon name="coins" class="w-4 h-4 text-amber-500" />
                    {{ __('Overdue Fines & Penalties Statement') }}
                </h3>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse($this->myFines as $fine)
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <span class="font-mono text-xs font-semibold text-amber-600 dark:text-amber-400">{{ $fine->fine_number }}</span>
                            <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100 mt-0.5">
                                {{ ucfirst($fine->fine_type) }} Penalty &bull; Book: {{ $fine->bookIssue?->book?->title ?? 'Library Item' }}
                            </div>
                            <div class="text-xs text-zinc-400">{{ $fine->created_at->format('d M Y, h:i A') }}</div>
                        </div>

                        <div class="text-right">
                            <div class="text-sm font-bold text-zinc-900 dark:text-zinc-100">₹{{ number_format($fine->amount, 2) }}</div>
                            <span class="px-2 py-0.5 rounded-full text-2xs font-semibold {{ $fine->status === 'paid' ? 'bg-emerald-100 text-emerald-700' : ($fine->status === 'waived' ? 'bg-blue-100 text-blue-700' : 'bg-rose-100 text-rose-700') }}">
                                {{ ucfirst($fine->status) }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-zinc-500 dark:text-zinc-400">
                        <x-icon name="check-circle" class="w-10 h-10 mx-auto text-emerald-400 mb-2" />
                        <div class="font-medium text-sm">{{ __('No fines on your account!') }}</div>
                        <div class="text-xs text-zinc-400 mt-1">{{ __('You have maintained good borrowing records without overdue penalites.') }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <!-- Book Detail & Reservation Modal -->
    @if($showDetailModal && $selectedBook)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ $selectedBook->title }}</h3>
                    <button type="button" wire:click="$set('showDetailModal', false)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <!-- Gallery or Cover -->
                    @if(!empty($selectedBook->gallery_images))
                        <div class="grid grid-cols-3 gap-2">
                            @foreach($selectedBook->gallery_images as $img)
                                <img src="{{ $img }}" alt="Gallery" class="h-24 w-full object-cover rounded-lg border border-zinc-200 dark:border-zinc-700" />
                            @endforeach
                        </div>
                    @endif

                    <!-- Metadata -->
                    <div class="grid grid-cols-2 gap-4 text-xs bg-zinc-50 dark:bg-zinc-800/60 p-4 rounded-xl">
                        <div><strong>Authors:</strong> {{ $selectedBook->authors->pluck('name')->join(', ') ?: 'N/A' }}</div>
                        <div><strong>Category:</strong> {{ $selectedBook->category?->name ?? 'General' }}</div>
                        <div><strong>ISBN:</strong> {{ $selectedBook->isbn ?: 'N/A' }}</div>
                        <div><strong>Edition:</strong> {{ $selectedBook->edition ?: '1st Edition' }}</div>
                        <div><strong>Replacement Price:</strong> ₹{{ number_format($selectedBook->price, 2) }}</div>
                        <div><strong>Available Copies:</strong> {{ $selectedBook->copies->where('status', 'available')->count() }} / {{ $selectedBook->copies->count() }}</div>
                    </div>

                    @if($selectedBook->description)
                        <div class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                            {{ $selectedBook->description }}
                        </div>
                    @endif

                    <!-- Deposit Criteria Check -->
                    @if($securityValidation)
                        <div class="p-3.5 rounded-xl border {{ $securityValidation['eligible'] ? 'border-emerald-200 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-950/20 text-emerald-800 dark:text-emerald-300' : 'border-rose-200 dark:border-rose-900/40 bg-rose-50 dark:bg-rose-950/20 text-rose-800 dark:text-rose-300' }} text-xs">
                            <div class="font-bold flex items-center gap-1.5">
                                <x-icon name="{{ $securityValidation['eligible'] ? 'check-circle' : 'exclamation-circle' }}" class="w-4 h-4" />
                                {{ $securityValidation['eligible'] ? __('Deposit Criteria Satisfied') : __('Security Deposit Threshold Notice') }}
                            </div>
                            <div class="mt-1">{{ $securityValidation['reason'] }}</div>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Reservation Notes (Optional)') }}
                        </label>
                        <input
                            type="text"
                            wire:model="reservationNotes"
                            placeholder="{{ __('e.g., preparing for optional paper...') }}"
                            class="w-full px-3 py-2 text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                        />
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="$set('showDetailModal', false)"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300"
                    >
                        {{ __('Close') }}
                    </button>

                    <button
                        type="button"
                        wire:click="reserveSelectedBook"
                        @if($securityValidation && !$securityValidation['eligible']) disabled @endif
                        class="px-4 py-2 text-xs font-semibold rounded-lg bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white shadow-sm"
                    >
                        {{ __('Place Hold & Reserve Book') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Batch Reservation Modal -->
    @if($showBatchReserveModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <x-icon name="bookmark-check" class="w-5 h-5 text-purple-600" />
                    {{ __('Confirm Multi-Book Reservation') }}
                </h3>

                <p class="text-xs text-zinc-600 dark:text-zinc-400">
                    {{ __('You have selected :count book(s) to reserve simultaneously.', ['count' => count($selectedBookIds)]) }}
                </p>

                <div class="p-3 rounded-xl bg-purple-50 dark:bg-purple-950/20 border border-purple-200 dark:border-purple-800 text-xs">
                    <div>Available Borrowing Headroom: <strong>₹{{ number_format($this->borrowingCapacity, 2) }}</strong></div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Optional Notes') }}
                    </label>
                    <textarea
                        wire:model="reservationNotes"
                        rows="2"
                        placeholder="{{ __('Any specific remarks for librarian...') }}"
                        class="w-full px-3 py-2 text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800"
                    ></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button" wire:click="$set('showBatchReserveModal', false)" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-zinc-300 dark:border-zinc-700">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="submitBatchReservation" class="px-4 py-1.5 text-xs font-semibold rounded-lg bg-purple-600 text-white">
                        {{ __('Confirm All Holds') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Book Loan Renewal Request Modal -->
    @if($showRenewalModal && $selectedLoanForRenewal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-5 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex items-center justify-center p-2 rounded-xl bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-300">
                            <x-icon name="rotate-cw" class="w-5 h-5" />
                        </span>
                        <div>
                            <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ __('Request Loan Renewal') }}</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Submit renewal request for administrative approval') }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeRenewalModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <!-- Book Summary -->
                    <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40 flex items-start gap-3">
                        <div class="w-10 h-14 rounded bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center shrink-0 text-purple-600 dark:text-purple-400">
                            <x-icon name="book-open" class="w-5 h-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h4 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 truncate">{{ $selectedLoanForRenewal->book?->title }}</h4>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                {{ $selectedLoanForRenewal->book?->authors->pluck('name')->join(', ') ?: 'Author unknown' }}
                            </div>
                            <div class="text-xs text-zinc-400 mt-1 font-mono">
                                Accession #: <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $selectedLoanForRenewal->bookCopy?->accession_no }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline Extension Preview -->
                    <div class="grid grid-cols-2 gap-3 p-3.5 rounded-xl bg-purple-50/60 dark:bg-purple-950/20 border border-purple-200/70 dark:border-purple-800/40 text-xs">
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400 block">{{ __('Current Due Date') }}</span>
                            <span class="font-bold text-zinc-900 dark:text-zinc-100 text-sm mt-0.5 block">
                                {{ $selectedLoanForRenewal->due_date->format('d M Y') }}
                            </span>
                        </div>
                        <div>
                            <span class="text-purple-700 dark:text-purple-300 font-semibold block">{{ __('Proposed New Due Date') }}</span>
                            <span class="font-bold text-purple-700 dark:text-purple-300 text-sm mt-0.5 block">
                                {{ $selectedLoanForRenewal->due_date->copy()->addDays(15)->format('d M Y') }}
                            </span>
                            <span class="text-[10px] text-purple-600 dark:text-purple-400 font-medium">{{ __('+15 Days Extension') }}</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-xs px-1 text-zinc-500 dark:text-zinc-400">
                        <span>{{ __('Renewal Allowance') }}:</span>
                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                            {{ __('Attempt :current of 2 permitted', ['current' => $selectedLoanForRenewal->renewal_count + 1]) }}
                        </span>
                    </div>

                    <!-- Notice -->
                    <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/40 text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2">
                        <x-icon name="information-circle" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                        <div>
                            <strong>{{ __('Approval Required') }}:</strong>
                            {{ __('This request will be sent to the library desk. Your due date will officially be extended after a Librarian or Admin reviews and approves it.') }}
                        </div>
                    </div>

                    <!-- Reason / Remarks Input -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ __('Reason for Renewal (Optional)') }}
                        </label>
                        <textarea
                            wire:model="renewalReason"
                            rows="2"
                            placeholder="{{ __('e.g., preparing for exams, continuing research revision...') }}"
                            class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                        ></textarea>
                    </div>
                </div>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="closeRenewalModal"
                        class="px-4 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 cursor-pointer"
                    >
                        {{ __('Cancel') }}
                    </button>

                    <button
                        type="button"
                        wire:click="submitRenewalRequest"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white shadow-xs cursor-pointer"
                    >
                        <x-icon name="check" class="w-4 h-4" />
                        <span wire:loading.remove wire:target="submitRenewalRequest">{{ __('Submit Request') }}</span>
                        <span wire:loading wire:target="submitRenewalRequest">{{ __('Submitting...') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
