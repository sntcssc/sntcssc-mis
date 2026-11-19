<?php

use App\Models\LibraryMember;
use App\Services\Library\LibraryCardService;
use App\Services\Library\LibraryPolicyService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Digital Library Membership Card')] class extends Component {
    public bool $withDepositDetails = true;
    public bool $withIssuedBooks = true;

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
    public function cardPayload(): ?array
    {
        if (! $this->member) {
            return null;
        }

        return LibraryCardService::getCardPayload(
            $this->member,
            $this->withDepositDetails,
            $this->withIssuedBooks
        );
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="id-card" class="w-6 h-6" />
                </span>
                {{ __('Digital Library Membership Card') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Your official student library identification card. Present at circulation desk for issuing or returning physical books.') }}
            </p>
        </div>

        <div class="flex items-center gap-2.5">
            <a
                href="{{ route('student.library.index') }}"
                class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 transition"
            >
                <x-icon name="arrow-left" class="w-4 h-4" />
                {{ __('Catalog') }}
            </a>

            @if($this->member)
                <a
                    href="{{ route('library.card.download', ['member' => $this->member->id, 'include_deposit' => $withDepositDetails ? 1 : 0, 'include_books' => $withIssuedBooks ? 1 : 0]) }}"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-xl bg-blue-600 hover:bg-blue-700 text-white shadow-sm transition"
                >
                    <x-icon name="arrow-down-tray" class="w-4 h-4" />
                    {{ __('Download PDF Card') }}
                </a>
            @endif
        </div>
    </div>

    <!-- Print / Render Customization Controls -->
    <div class="p-4 rounded-xl border border-blue-200 dark:border-blue-900/50 bg-blue-50/50 dark:bg-blue-950/20">
        <span class="block text-xs font-bold uppercase tracking-wider text-blue-900 dark:text-blue-300 mb-2">
            {{ __('Print & Download Preferences') }}
        </span>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    wire:model.live="withDepositDetails"
                    class="w-4 h-4 text-blue-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-blue-500"
                />
                <span class="font-medium text-zinc-800 dark:text-zinc-200">
                    {{ __('Include Security Deposit Amount Details') }}
                </span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    wire:model.live="withIssuedBooks"
                    class="w-4 h-4 text-blue-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-blue-500"
                />
                <span class="font-medium text-zinc-800 dark:text-zinc-200">
                    {{ __('Include Active Issued Books Log Entries (Card Back)') }}
                </span>
            </label>
        </div>
    </div>

    @if($this->cardPayload)
        <!-- Front Side Card -->
        <div class="rounded-3xl border-2 border-blue-500/30 bg-gradient-to-br from-blue-950 via-indigo-950 to-slate-900 p-8 text-white shadow-2xl relative overflow-hidden">
            <div class="absolute -right-12 -bottom-12 w-56 h-56 rounded-full bg-blue-500/15 blur-3xl"></div>

            <!-- Card Header -->
            <div class="flex items-start justify-between border-b border-white/15 pb-5">
                <div>
                    <h3 class="font-extrabold text-lg sm:text-xl tracking-wide">{{ $this->cardPayload['institution_name'] }}</h3>
                    <p class="text-xs text-blue-200 mt-0.5">{{ $this->cardPayload['institution_sub'] }} &bull; Department of Library Services</p>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-white/20 text-white border border-white/30">
                    {{ $this->cardPayload['member_type'] }}
                </span>
            </div>

            <!-- Card Middle Content -->
            <div class="mt-6 flex flex-col sm:flex-row gap-6 items-center sm:items-start">
                <!-- Avatar / Photo -->
                <div class="w-24 h-32 rounded-2xl bg-white/10 border-2 border-white/30 flex items-center justify-center shrink-0 overflow-hidden shadow-inner">
                    @if($this->member->photoUrl())
                        <img src="{{ $this->member->photoUrl() }}" alt="{{ $this->member->name }}" class="w-full h-full object-cover" />
                    @else
                        <x-icon name="user" class="w-12 h-12 text-white/50" />
                    @endif
                </div>

                <!-- Personal Information -->
                <div class="flex-1 space-y-1.5 text-center sm:text-left">
                    <h2 class="text-2xl font-bold text-white">{{ $this->cardPayload['name'] }}</h2>
                    <div class="font-mono text-sm text-blue-200 tracking-wider">
                        Card Number: <span class="font-bold text-white">{{ $this->cardPayload['member_number'] }}</span>
                    </div>

                    @if($this->cardPayload['student_id'] !== 'N/A')
                        <div class="text-xs text-blue-200">
                            Student ID / Roll: <span class="font-mono font-semibold text-white">{{ $this->cardPayload['student_id'] }}</span>
                        </div>
                    @endif

                    <div class="text-xs text-blue-200">
                        Valid Until: <span class="text-white font-medium">{{ $this->cardPayload['expiry_date'] }}</span>
                    </div>

                    @if($withDepositDetails)
                        <div class="mt-3 inline-flex items-center gap-2 px-3 py-1 rounded-xl bg-emerald-500/20 border border-emerald-400/30 text-emerald-300 text-xs font-semibold">
                            <span>Library Security Deposit:</span>
                            <strong class="text-sm">₹{{ number_format($this->cardPayload['security_deposit_amount'], 2) }}</strong>
                        </div>
                    @endif
                </div>

                <!-- Scannable QR Code -->
                <div class="p-2 bg-white rounded-2xl shrink-0 shadow-lg">
                    <img src="{{ $this->cardPayload['qr_uri'] }}" alt="QR Code" class="w-24 h-24" />
                </div>
            </div>

            <!-- Card Bottom Barcode -->
            <div class="mt-6 pt-4 border-t border-white/15 flex flex-col sm:flex-row items-center justify-between gap-2">
                <img src="{{ $this->cardPayload['barcode_uri'] }}" alt="Barcode" class="h-12 bg-white p-1 rounded-lg" />
                <span class="text-xs font-mono text-blue-300">{{ $this->cardPayload['member_number'] }}</span>
            </div>
        </div>

        <!-- Back Side Card / Borrowing Register -->
        @if($withIssuedBooks)
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h4 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="book-open" class="w-4 h-4 text-blue-600" />
                        {{ __('Active Borrowed Books (Card Reverse Entry)') }}
                    </h4>
                    <span class="text-xs font-semibold text-zinc-500">
                        {{ count($this->cardPayload['issued_books']) }} {{ __('Active Loan(s)') }}
                    </span>
                </div>

                @if(empty($this->cardPayload['issued_books']))
                    <p class="text-xs text-zinc-400 italic py-2">
                        {{ __('No books currently issued to this member. A blank loan endorsement register will be printed on the back.') }}
                    </p>
                @else
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-800 text-xs">
                        @foreach($this->cardPayload['issued_books'] as $book)
                            <div class="py-2.5 flex items-center justify-between">
                                <div>
                                    <div class="font-bold text-zinc-900 dark:text-zinc-100">{{ $book['title'] }}</div>
                                    <div class="text-2xs text-zinc-400 mt-0.5">
                                        Accession #: <span class="font-mono">{{ $book['accession_no'] }}</span> &bull; Issued on: {{ $book['issue_date'] }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="text-xs font-bold text-amber-600 dark:text-amber-400">Due: {{ $book['due_date'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <!-- Borrowing Rules Card -->
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50 p-5 text-xs text-zinc-600 dark:text-zinc-400 space-y-2">
            <h5 class="font-bold text-zinc-900 dark:text-zinc-100 text-sm flex items-center gap-1.5">
                <x-icon name="information-circle" class="w-4 h-4 text-purple-600" />
                {{ __('Library Rules & Borrowing Conditions') }}
            </h5>
            <ul class="list-disc list-inside space-y-1 text-2xs sm:text-xs">
                <li>{{ __('The standard loan period is 15 days from the date of issue.') }}</li>
                <li>{{ __('Active borrowing limit is governed by your active Library Security Deposit balance.') }}</li>
                <li>{{ __('Loans may be renewed up to 2 times online before the due date if no other hold exists.') }}</li>
                <li>{{ __('Overdue loans will accrue fine per day as per library policy until renewed or returned.') }}</li>
                <li>{{ __('This digital card is non-transferable and must be produced upon request.') }}</li>
            </ul>
        </div>
    @endif
</div>
