<?php

use App\Models\User;
use App\Services\StudentIdCardService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Student Identity Card')] class extends Component {
    #[Computed]
    public function student(): ?User
    {
        return auth()->user();
    }

    #[Computed]
    public function isDownloadEnabled(): bool
    {
        return StudentIdCardService::isDownloadEnabledForStudents();
    }

    #[Computed]
    public function cardPayload(): ?array
    {
        if (! $this->student) {
            return null;
        }

        return StudentIdCardService::getCardPayload($this->student);
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="id-card" class="w-6 h-6" />
                </span>
                {{ __('Official Student Identity Card') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Your official institutional identity credentials at SNTCSSC. Carry at all times on campus and during official assessments.') }}
            </p>
        </div>

        <div class="flex items-center gap-2.5">
            <a
                href="{{ route('student.dashboard') }}"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition"
            >
                <x-icon name="arrow-left" class="w-4 h-4" />
                {{ __('Dashboard') }}
            </a>

            @if($this->student && $this->isDownloadEnabled)
                <a
                    href="{{ route('student.id-card.download', ['student' => $this->student->id]) }}"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-xl bg-blue-600 hover:bg-blue-700 text-white shadow-sm transition"
                >
                    <x-icon name="arrow-down-tray" class="w-4 h-4" />
                    {{ __('Download PDF Card') }}
                </a>
            @endif
        </div>
    </div>

    <!-- Availability Status Notice -->
    @if(!$this->isDownloadEnabled)
        <div class="p-4 rounded-xl border border-amber-300 dark:border-amber-800/60 bg-amber-50 dark:bg-amber-950/20 flex items-start gap-3">
            <x-icon name="information-circle" class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
            <div class="text-xs">
                <strong class="font-bold text-amber-900 dark:text-amber-200 block">{{ __('PDF Download Temporarily Restricted') }}</strong>
                <p class="text-amber-800 dark:text-amber-300/90 mt-0.5">
                    {{ __('Student ID card downloading is currently closed by the institution administration. You can view your verified digital identity credentials on this screen. For physical card reissue, please contact the administrative registrar.') }}
                </p>
            </div>
        </div>
    @endif

    @if($this->cardPayload)
        <!-- Front Side Card -->
        <div class="rounded-3xl border-2 border-blue-500/30 bg-gradient-to-br from-blue-950 via-slate-900 to-indigo-950 p-6 sm:p-8 text-white shadow-2xl relative overflow-hidden">
            <div class="absolute -right-12 -bottom-12 w-56 h-56 rounded-full bg-blue-500/15 blur-3xl pointer-events-none"></div>

            <!-- Card Header -->
            <div class="flex items-start justify-between border-b border-white/15 pb-4">
                <div>
                    <h3 class="font-extrabold text-base sm:text-lg tracking-wide uppercase">{{ $this->cardPayload['settings']['institution_name'] }}</h3>
                    <p class="text-xs text-blue-200 mt-0.5">{{ $this->cardPayload['settings']['institution_sub'] }} &bull; Directorate of Training</p>
                </div>
                <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-blue-500/20 text-blue-200 border border-blue-400/30">
                    {{ $this->cardPayload['settings']['card_title'] }}
                </span>
            </div>

            <!-- Card Middle Content -->
            <div class="mt-6 flex flex-col sm:flex-row gap-6 items-center sm:items-start">
                <!-- Avatar / Photo -->
                <div class="w-28 h-36 rounded-2xl bg-white/10 border-2 border-white/30 flex items-center justify-center shrink-0 overflow-hidden shadow-inner">
                    @if($this->cardPayload['photo_url'])
                        <img src="{{ $this->cardPayload['photo_url'] }}" alt="{{ $this->cardPayload['name'] }}" class="w-full h-full object-cover" />
                    @else
                        <div class="text-center p-3">
                            <x-icon name="user" class="w-12 h-12 text-white/50 mx-auto" />
                            <span class="text-[10px] text-white/60 block mt-1">NO PHOTO</span>
                        </div>
                    @endif
                </div>

                <!-- Personal Information -->
                <div class="flex-1 space-y-2 text-center sm:text-left">
                    <div>
                        <h2 class="text-2xl font-black text-white tracking-tight">{{ $this->cardPayload['name'] }}</h2>
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-white/10 text-blue-200 font-mono text-xs font-semibold mt-1">
                            <span>ID:</span>
                            <span class="text-white">{{ $this->cardPayload['student_id'] }}</span>
                            @if($this->cardPayload['roll_no'] !== '—')
                                <span class="mx-1 text-white/40">&bull;</span>
                                <span>Roll: {{ $this->cardPayload['roll_no'] }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-xs text-blue-200 pt-1">
                        <div>
                            <span class="text-white/60 text-[11px] block">{{ __('Course:') }}</span>
                            <span class="font-medium text-white">{{ $this->cardPayload['course'] }}</span>
                        </div>
                        <div>
                            <span class="text-white/60 text-[11px] block">{{ __('Batch & Section:') }}</span>
                            <span class="font-medium text-white">{{ $this->cardPayload['batch'] }} ({{ $this->cardPayload['section'] }})</span>
                        </div>
                        @if($this->cardPayload['settings']['show_optional_subject'])
                            <div class="sm:col-span-2">
                                <span class="text-white/60 text-[11px] block">{{ __('Optional Subject:') }}</span>
                                <span class="font-bold text-amber-300">{{ $this->cardPayload['optional_subject'] }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-2 text-[11px]">
                        @if($this->cardPayload['settings']['show_blood_group'])
                            <span class="px-2 py-0.5 rounded bg-white/10 text-white font-medium">
                                Blood: {{ $this->cardPayload['blood_group'] }}
                            </span>
                        @endif
                        <span class="px-2 py-0.5 rounded bg-white/10 text-white font-medium">
                            DOB: {{ $this->cardPayload['dob'] }}
                        </span>
                        <span class="px-2 py-0.5 rounded bg-emerald-500/20 border border-emerald-400/30 text-emerald-300 font-semibold">
                            Valid: {{ $this->cardPayload['settings']['validity_period'] }}
                        </span>
                    </div>
                </div>

                <!-- Scannable QR Code -->
                @if($this->cardPayload['settings']['show_qr_code'])
                    <div class="p-2.5 bg-white rounded-2xl shrink-0 shadow-lg text-center">
                        <img src="{{ $this->cardPayload['qr_uri'] }}" alt="QR Code" class="w-20 h-20 sm:w-24 sm:h-24 mx-auto" />
                        <span class="text-[9px] text-zinc-500 block mt-1 font-mono">SCAN TO VERIFY</span>
                    </div>
                @endif
            </div>

            <!-- Card Bottom Barcode -->
            @if($this->cardPayload['settings']['show_barcode'])
                <div class="mt-6 pt-4 border-t border-white/15 flex flex-col sm:flex-row items-center justify-between gap-2">
                    <img src="{{ $this->cardPayload['barcode_uri'] }}" alt="Barcode" class="h-10 bg-white p-1 rounded-lg" />
                    <span class="text-xs font-mono text-blue-300">{{ $this->cardPayload['student_id'] }}</span>
                </div>
            @endif
        </div>

        <!-- Back Side Card / Regulations & Contacts -->
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs space-y-4">
            <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                <h4 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <x-icon name="shield-check" class="w-4 h-4 text-blue-600" />
                    {{ __('Card Regulations & Institution Directives (Card Back)') }}
                </h4>
                <span class="text-xs font-semibold text-zinc-500">
                    {{ __('Official Standing Policy') }}
                </span>
            </div>

            <ol class="list-decimal list-inside space-y-1.5 text-xs text-zinc-600 dark:text-zinc-300">
                @foreach($this->cardPayload['terms'] as $term)
                    <li>{{ $term }}</li>
                @endforeach
            </ol>

            @if($this->cardPayload['settings']['show_emergency_contact'])
                <div class="p-3.5 rounded-xl border border-rose-200 dark:border-rose-900/50 bg-rose-50/50 dark:bg-rose-950/20 flex items-center justify-between text-xs">
                    <div>
                        <span class="font-bold text-rose-900 dark:text-rose-200 block">{{ __('Emergency Helpline / Support Contact:') }}</span>
                        <span class="text-rose-800 dark:text-rose-300 font-mono mt-0.5 block">{{ $this->cardPayload['emergency_contact'] }} &bull; {{ $this->cardPayload['email'] }}</span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] text-zinc-500 uppercase block font-semibold">{{ __('Authorized Authority') }}</span>
                        <span class="text-xs font-bold text-zinc-800 dark:text-zinc-200">{{ $this->cardPayload['settings']['authorized_signatory_title'] }}</span>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>

