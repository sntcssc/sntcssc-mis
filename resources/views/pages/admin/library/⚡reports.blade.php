<?php

use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\BookFine;
use App\Models\BookIssue;
use App\Models\LibraryMember;
use App\Services\Library\LibraryReminderService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Library Reports & Intelligence')] class extends Component {
    #[Url(as: 'tab')]
    public string $activeTab = 'defaulters'; // 'defaulters', 'popular_books', 'inventory', 'fines_revenue'

    public string $dateRange = 'this_month'; // 'this_month', 'last_month', 'this_year', 'all'

    public function selectTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    #[Computed]
    public function summaryStats(): array
    {
        $totalBookTitles = Book::count();
        $totalCopies = BookCopy::count();
        $totalAssetValue = (float) BookCopy::selectRaw('COALESCE(SUM(COALESCE(price, purchase_cost, 0)), 0) as total')->value('total');
        $availableCopies = BookCopy::where('status', BookCopy::STATUS_AVAILABLE)->count();
        $issuedCopies = BookCopy::where('status', BookCopy::STATUS_ISSUED)->count();
        $activeLoans = BookIssue::whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_RENEWED])->count();
        $overdueLoans = BookIssue::where('status', BookIssue::STATUS_OVERDUE)
            ->orWhere(fn ($q) => $q->whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_RENEWED])->whereDate('due_date', '<', now()))
            ->count();
        $finesCollected = BookFine::where('status', BookFine::STATUS_PAID)->sum('paid_amount');
        $pendingFines = BookFine::where('status', BookFine::STATUS_PENDING)->sum('payable_amount');

        return [
            'total_titles' => $totalBookTitles,
            'total_copies' => $totalCopies,
            'asset_value' => $totalAssetValue,
            'available_copies' => $availableCopies,
            'issued_copies' => $issuedCopies,
            'active_loans' => $activeLoans,
            'overdue_loans' => $overdueLoans,
            'fines_collected' => $finesCollected,
            'pending_fines' => $pendingFines,
        ];
    }

    #[Computed]
    public function defaultersList()
    {
        return BookIssue::query()
            ->with(['book', 'bookCopy', 'libraryMember.user'])
            ->where(function ($q) {
                $q->where('status', BookIssue::STATUS_OVERDUE)
                    ->orWhere(fn ($sub) => $sub->whereIn('status', [BookIssue::STATUS_ISSUED, BookIssue::STATUS_RENEWED])->whereDate('due_date', '<', now()));
            })
            ->orderBy('due_date', 'asc')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function popularBooks()
    {
        return Book::query()
            ->with(['category', 'authors'])
            ->withCount(['issues'])
            ->orderBy('issues_count', 'desc')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function categoryBreakdown()
    {
        return BookCategory::query()
            ->withCount(['books'])
            ->orderBy('books_count', 'desc')
            ->get();
    }

    #[Computed]
    public function revenueSummary()
    {
        return [
            'cash' => BookFine::where('status', BookFine::STATUS_PAID)->where('payment_method', 'cash')->sum('paid_amount'),
            'upi' => BookFine::where('status', BookFine::STATUS_PAID)->where('payment_method', 'upi')->sum('paid_amount'),
            'bank' => BookFine::where('status', BookFine::STATUS_PAID)->where('payment_method', 'bank_transfer')->sum('paid_amount'),
            'waived' => BookFine::sum('waived_amount'),
        ];
    }

    public function sendDefaulterReminder(int $issueId): void
    {
        $issue = BookIssue::findOrFail($issueId);
        $res = LibraryReminderService::sendOverdueReminder($issue);

        if (! empty($res['sent_channels'])) {
            Toast::dispatch($this, 'success', __('Overdue notice successfully dispatched via :channels to :member.', [
                'channels' => implode(', ', $res['sent_channels']),
                'member' => $issue->libraryMember->name,
            ]));
        } else {
            Toast::dispatch($this, 'error', __('Failed to send notification: ').($res['error'] ?? 'Unknown error'));
        }
    }

    public function exportDefaultersCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $defaulters = $this->defaultersList;

        $headers = [
            'Loan No', 'Borrower Name', 'Card No', 'Student ID', 'Phone', 'Email',
            'Book Title', 'Accession No', 'Issue Date', 'Due Date', 'Days Overdue',
        ];

        $rows = $defaulters->map(function ($d) {
            $overdueDays = max(0, (int) now()->startOfDay()->diffInDays($d->due_date->startOfDay(), false) * -1);

            return [
                $d->issue_no,
                $d->libraryMember?->name,
                $d->libraryMember?->member_number,
                $d->libraryMember?->student_id ?? 'N/A',
                $d->libraryMember?->phone ?? 'N/A',
                $d->libraryMember?->email ?? 'N/A',
                $d->book?->title,
                $d->bookCopy?->accession_no,
                $d->issue_date->format('Y-m-d'),
                $d->due_date->format('Y-m-d'),
                $overdueDays,
            ];
        })->toArray();

        return TableExporter::csv($headers, $rows, 'library_defaulters_roster_'.now()->format('Ymd_His').'.csv');
    }

    public function exportInventoryCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $copies = BookCopy::with(['book.category'])->get();

        $headers = [
            'Accession No', 'Barcode', 'Title', 'ISBN', 'Category', 'Condition', 'Status',
            'Price (INR)', 'Supplier', 'Invoice No', 'Acquisition Date', 'Shelf Location',
        ];

        $rows = $copies->map(fn ($c) => [
            $c->accession_no,
            $c->barcode,
            $c->book?->title,
            $c->book?->isbn,
            $c->book?->category?->name ?? 'General',
            ucfirst($c->book_condition ?? $c->condition ?? 'good'),
            ucfirst($c->status),
            number_format((float) ($c->price ?? $c->purchase_cost ?? 0), 2),
            $c->supplier_details ?? $c->supplier_name ?? 'N/A',
            $c->invoice_number ?? $c->invoice_no ?? 'N/A',
            $c->acquisition_date?->format('Y-m-d') ?? 'N/A',
            $c->shelf_location ?? 'General Stack',
        ])->toArray();

        return TableExporter::csv($headers, $rows, 'library_accession_inventory_'.now()->format('Ymd_His').'.csv');
    }

    public function exportPopularBooksCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $books = $this->popularBooks;
        $headers = ['Title', 'ISBN', 'Category', 'Authors', 'Circulation Count', 'Current Available Copies'];
        $rows = $books->map(fn ($b) => [
            $b->title,
            $b->isbn ?? 'N/A',
            $b->category?->name ?? 'General',
            $b->authors_list ?? 'Unknown',
            $b->issues_count,
            $b->available_copies_count ?? 0,
        ])->toArray();

        return TableExporter::csv($headers, $rows, 'popular_library_books_'.now()->format('Ymd_His').'.csv');
    }

    public function exportRevenueCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $fines = BookFine::with(['libraryMember', 'bookIssue.book'])->where('status', BookFine::STATUS_PAID)->latest('paid_at')->get();
        $headers = ['Fine Ref', 'Member Name', 'Member No', 'Amount (INR)', 'Payment Mode', 'Reference', 'Paid Date'];
        $rows = $fines->map(fn ($f) => [
            $f->fine_number,
            $f->libraryMember?->name,
            $f->libraryMember?->member_number,
            number_format((float) ($f->paid_amount ?? 0), 2),
            strtoupper($f->payment_method ?? 'CASH'),
            $f->payment_reference ?? 'N/A',
            $f->paid_at?->format('Y-m-d H:i') ?? 'N/A',
        ])->toArray();

        return TableExporter::csv($headers, $rows, 'library_fines_revenue_'.now()->format('Ymd_His').'.csv');
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="chart-bar" class="w-6 h-6" />
                </span>
                {{ __('Library Intelligence & Circulation Reports') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Real-time statistics, overdue defaulter roster, catalog inventory asset values, and fine revenue.') }}
            </p>
        </div>

        <div class="flex items-center gap-2.5">
            @if($activeTab === 'defaulters')
                <button
                    type="button"
                    wire:click="exportDefaultersCsv"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 transition"
                >
                    <x-icon name="arrow-down-tray" class="w-4 h-4 text-zinc-400" />
                    {{ __('Export Defaulters Roster') }}
                </button>
            @elseif($activeTab === 'inventory')
                <button
                    type="button"
                    wire:click="exportInventoryCsv"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 transition"
                >
                    <x-icon name="arrow-down-tray" class="w-4 h-4 text-zinc-400" />
                    {{ __('Export Accession Inventory') }}
                </button>
            @elseif($activeTab === 'popular_books')
                <button
                    type="button"
                    wire:click="exportPopularBooksCsv"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 transition"
                >
                    <x-icon name="arrow-down-tray" class="w-4 h-4 text-zinc-400" />
                    {{ __('Export Popular Books') }}
                </button>
            @elseif($activeTab === 'fines_revenue')
                <button
                    type="button"
                    wire:click="exportRevenueCsv"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 transition"
                >
                    <x-icon name="arrow-down-tray" class="w-4 h-4 text-zinc-400" />
                    {{ __('Export Revenue Report') }}
                </button>
            @endif
        </div>
    </div>

    <!-- Executive Metric Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Catalog Asset Value') }}</span>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">₹{{ number_format($this->summaryStats['asset_value'], 2) }}</div>
            <span class="text-2xs text-zinc-400 mt-1 block">{{ $this->summaryStats['total_titles'] }} titles &bull; {{ $this->summaryStats['total_copies'] }} physical copies</span>
        </div>

        <div class="p-4 rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-950/20 shadow-xs">
            <span class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Active Loans in Stack') }}</span>
            <div class="mt-1 text-2xl font-bold text-blue-900 dark:text-blue-200">{{ number_format($this->summaryStats['active_loans']) }}</div>
            <span class="text-2xs text-blue-600 dark:text-blue-400 mt-1 block">{{ $this->summaryStats['available_copies'] }} copies currently on shelves</span>
        </div>

        <div class="p-4 rounded-xl border border-rose-200 dark:border-rose-900/40 bg-rose-50/50 dark:bg-rose-950/20 shadow-xs">
            <span class="text-xs font-medium text-rose-700 dark:text-rose-400">{{ __('Overdue Loans Defaulters') }}</span>
            <div class="mt-1 text-2xl font-bold text-rose-900 dark:text-rose-200">{{ number_format($this->summaryStats['overdue_loans']) }}</div>
            <span class="text-2xs text-rose-600 dark:text-rose-400 mt-1 block">{{ __('Exceeded 15-day timeline') }}</span>
        </div>

        <div class="p-4 rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50/50 dark:bg-emerald-950/20 shadow-xs">
            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ __('Fines Revenue Settled') }}</span>
            <div class="mt-1 text-2xl font-bold text-emerald-900 dark:text-emerald-200">₹{{ number_format($this->summaryStats['fines_collected'], 2) }}</div>
            <span class="text-2xs text-amber-600 dark:text-amber-400 mt-1 block">Pending: ₹{{ number_format($this->summaryStats['pending_fines'], 2) }}</span>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-zinc-200 dark:border-zinc-800 flex items-center gap-6">
        <button
            type="button"
            wire:click="selectTab('defaulters')"
            class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'defaulters' ? 'border-rose-600 text-rose-600 dark:text-rose-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
        >
            <x-icon name="exclamation-triangle" class="w-4 h-4" />
            {{ __('Overdue Defaulters Roster') }}
            @if($this->summaryStats['overdue_loans'] > 0)
                <span class="px-1.5 py-0.5 rounded-full text-2xs font-bold bg-rose-100 text-rose-700 dark:bg-rose-900/60 dark:text-rose-300">
                    {{ $this->summaryStats['overdue_loans'] }}
                </span>
            @endif
        </button>

        <button
            type="button"
            wire:click="selectTab('popular_books')"
            class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'popular_books' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
        >
            <x-icon name="sparkles" class="w-4 h-4" />
            {{ __('Circulation Velocity & Popular Titles') }}
        </button>

        <button
            type="button"
            wire:click="selectTab('inventory')"
            class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'inventory' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
        >
            <x-icon name="folder-tree" class="w-4 h-4" />
            {{ __('Subject Category Distribution') }}
        </button>

        <button
            type="button"
            wire:click="selectTab('fines_revenue')"
            class="pb-3 text-sm font-semibold transition border-b-2 flex items-center gap-2 {{ $activeTab === 'fines_revenue' ? 'border-emerald-600 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
        >
            <x-icon name="coins" class="w-4 h-4" />
            {{ __('Revenue & Fine Audit') }}
        </button>
    </div>

    <!-- Tab 1: Defaulters Roster -->
    @if($activeTab === 'defaulters')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-xs">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="exclamation-circle" class="w-4 h-4 text-rose-600 dark:text-rose-400" />
                        {{ __('Active Overdue Defaulters (Borrowers exceeding 15 days)') }}
                    </h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ __('Directly dispatch reminder notices via in-app notification, email, or SMS to prompt return or renewal.') }}
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wider font-semibold">
                        <tr>
                            <th class="px-4 py-3">{{ __('Borrower') }}</th>
                            <th class="px-4 py-3">{{ __('Card / Roll #') }}</th>
                            <th class="px-4 py-3">{{ __('Book Title') }}</th>
                            <th class="px-4 py-3">{{ __('Accession #') }}</th>
                            <th class="px-4 py-3">{{ __('Due Date') }}</th>
                            <th class="px-4 py-3">{{ __('Days Overdue') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Notify') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-zinc-700 dark:text-zinc-300">
                        @forelse($this->defaultersList as $defaulter)
                            @php
                                $overdueDays = max(0, (int) now()->startOfDay()->diffInDays($defaulter->due_date->startOfDay(), false) * -1);
                            @endphp
                            <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $defaulter->libraryMember?->name }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ $defaulter->libraryMember?->member_number }}
                                    @if($defaulter->libraryMember?->student_id)
                                        <div class="text-2xs text-zinc-400">ID: {{ $defaulter->libraryMember->student_id }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs max-w-xs truncate">
                                    {{ $defaulter->book?->title }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ $defaulter->bookCopy?->accession_no }}
                                </td>
                                <td class="px-4 py-3 text-xs text-rose-600 dark:text-rose-400 font-semibold">
                                    {{ $defaulter->due_date->format('d M Y') }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">
                                        {{ $overdueDays }} {{ __('days') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        wire:click="sendDefaulterReminder({{ $defaulter->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-rose-600 hover:bg-rose-700 text-white transition shadow-2xs"
                                        title="{{ __('Send instant reminder to borrower') }}"
                                    >
                                        <x-icon name="bell" class="w-3.5 h-3.5" />
                                        {{ __('Send Reminder') }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                                    <x-icon name="check-circle" class="w-10 h-10 mx-auto text-emerald-400 dark:text-emerald-600 mb-2" />
                                    <div class="font-medium text-sm">{{ __('Zero Overdue Defaulters!') }}</div>
                                    <div class="text-xs text-zinc-400 mt-1">{{ __('All issued books are within their authorized 15-day timeline.') }}</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Tab 2: Popular Books -->
    @if($activeTab === 'popular_books')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 mb-3 flex items-center gap-2">
                    <x-icon name="fire" class="w-4 h-4 text-amber-500" />
                    {{ __('Top Borrowed Books (Circulation Velocity)') }}
                </h3>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($this->popularBooks as $index => $book)
                        <div class="py-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-sm text-zinc-400 w-5">#{{ $index + 1 }}</span>
                                <div>
                                    <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100 line-clamp-1">{{ $book->title }}</div>
                                    <div class="text-xs text-zinc-400">
                                        {{ $book->category?->name ?? 'General' }} &bull; ₹{{ number_format($book->price, 2) }}
                                    </div>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 shrink-0">
                                {{ $book->issues_count }} {{ __('times borrowed') }}
                            </span>
                        </div>
                    @empty
                        <p class="text-xs text-zinc-400 py-6 text-center">{{ __('No circulation history recorded yet.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 mb-3 flex items-center gap-2">
                    <x-icon name="chart-pie" class="w-4 h-4 text-purple-500" />
                    {{ __('Stock Condition Distribution') }}
                </h3>
                @php
                    $goodCount = BookCopy::where(fn ($q) => $q->where('book_condition', 'good')->orWhere('condition', 'good'))->count();
                    $newCount = BookCopy::where(fn ($q) => $q->where('book_condition', 'new')->orWhere('condition', 'new'))->count();
                    $fairCount = BookCopy::where(fn ($q) => $q->where('book_condition', 'fair')->orWhere('condition', 'fair'))->count();
                    $damagedCount = BookCopy::where(fn ($q) => $q->where('book_condition', 'damaged')->orWhere('condition', 'damaged'))->count();
                @endphp
                <div class="space-y-3 pt-2">
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            <span>{{ __('Brand New') }}</span>
                            <span>{{ $newCount }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div class="h-full bg-emerald-500" style="width: {{ $this->summaryStats['total_copies'] > 0 ? ($newCount / $this->summaryStats['total_copies']) * 100 : 0 }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            <span>{{ __('Good Condition') }}</span>
                            <span>{{ $goodCount }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div class="h-full bg-blue-500" style="width: {{ $this->summaryStats['total_copies'] > 0 ? ($goodCount / $this->summaryStats['total_copies']) * 100 : 0 }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            <span>{{ __('Fair / Worn') }}</span>
                            <span>{{ $fairCount }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div class="h-full bg-amber-500" style="width: {{ $this->summaryStats['total_copies'] > 0 ? ($fairCount / $this->summaryStats['total_copies']) * 100 : 0 }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                            <span>{{ __('Damaged / Needs Binding') }}</span>
                            <span>{{ $damagedCount }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div class="h-full bg-rose-500" style="width: {{ $this->summaryStats['total_copies'] > 0 ? ($damagedCount / $this->summaryStats['total_copies']) * 100 : 0 }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Tab 3: Inventory by Category -->
    @if($activeTab === 'inventory')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs">
            <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 mb-4 flex items-center gap-2">
                <x-icon name="folder-tree" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                {{ __('Subject Category Distribution & Asset Allocation') }}
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                @foreach($this->categoryBreakdown as $category)
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40">
                        <div class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ $category->name }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">Code: <span class="font-mono">{{ $category->code }}</span></div>
                        <div class="mt-3 flex items-center justify-between text-xs">
                            <span class="text-zinc-500">{{ __('Catalog Titles:') }}</span>
                            <span class="font-bold text-indigo-600 dark:text-indigo-400">{{ $category->books_count }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Tab 4: Fines & Revenue Statement -->
    @if($activeTab === 'fines_revenue')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs">
            <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 mb-4 flex items-center gap-2">
                <x-icon name="coins" class="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
                {{ __('Revenue & Penalty Collections Statement') }}
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="p-4 rounded-xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50/50 dark:bg-emerald-950/20">
                    <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Cash at Counter') }}</span>
                    <div class="text-xl font-bold text-emerald-900 dark:text-emerald-100 mt-1">₹{{ number_format($this->revenueSummary['cash'], 2) }}</div>
                </div>

                <div class="p-4 rounded-xl border border-blue-200 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-950/20">
                    <span class="text-xs font-semibold text-blue-700 dark:text-blue-300">{{ __('UPI / QR Collections') }}</span>
                    <div class="text-xl font-bold text-blue-900 dark:text-blue-100 mt-1">₹{{ number_format($this->revenueSummary['upi'], 2) }}</div>
                </div>

                <div class="p-4 rounded-xl border border-purple-200 dark:border-purple-900/40 bg-purple-50/50 dark:bg-purple-950/20">
                    <span class="text-xs font-semibold text-purple-700 dark:text-purple-300">{{ __('Bank Transfer / Challan') }}</span>
                    <div class="text-xl font-bold text-purple-900 dark:text-purple-100 mt-1">₹{{ number_format($this->revenueSummary['bank'], 2) }}</div>
                </div>

                <div class="p-4 rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-950/20">
                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-300">{{ __('Total Authorized Waivers') }}</span>
                    <div class="text-xl font-bold text-amber-900 dark:text-amber-100 mt-1">₹{{ number_format($this->revenueSummary['waived'], 2) }}</div>
                </div>
            </div>
        </div>
    @endif
</div>
