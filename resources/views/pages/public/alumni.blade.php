<?php

use App\Models\Alumni;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.public')] #[Title('SNTCSSC Alumni Wall of Fame')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'type')]
    public string $serviceType = 'all';

    public ?Alumni $viewingAlumni = null;
    public bool $showDossierModal = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedServiceType(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function featuredAlumni()
    {
        return Alumni::query()
            ->active()
            ->featured()
            ->with(['courseBatches'])
            ->orderBy('display_order')
            ->take(6)
            ->get();
    }

    #[Computed]
    public function alumniList()
    {
        $query = Alumni::query()
            ->active()
            ->with(['courseBatches'])
            ->search($this->search);

        if ($this->serviceType === 'upsc') {
            $query->where('service_type', 'like', '%UPSC%');
        } elseif ($this->serviceType === 'wbcs') {
            $query->where(function ($q) {
                $q->where('service_type', 'like', '%WBCS%')
                    ->orWhere('service_type', 'like', '%WBPS%');
            });
        } elseif ($this->serviceType === 'banking') {
            $query->where('service_type', 'like', '%Banking%');
        }

        return $query->orderByDesc('is_featured')
            ->orderByRaw('COALESCE(all_india_rank, 999999) ASC')
            ->orderByDesc('exam_year')
            ->paginate(12);
    }

    public function showDossier(int $id): void
    {
        $this->viewingAlumni = Alumni::with(['courseBatches'])->findOrFail($id);
        $this->showDossierModal = true;
    }
};
?>

<div class="min-h-screen bg-background py-10 px-4 sm:px-6 lg:px-8">
    <div class="max-w-7xl mx-auto space-y-10">
        <!-- Hero Section -->
        <div class="text-center space-y-4 max-w-3xl mx-auto">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-violet-100 dark:bg-violet-950/80 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-800">
                <x-icon name="award" class="w-3.5 h-3.5" />
                {{ __('SNTCSSC Achievers & Hall of Fame') }}
            </span>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-foreground">
                {{ __('Our Successful Alumni Officers') }}
            </h1>
            <p class="text-base sm:text-lg text-muted-foreground leading-relaxed">
                {{ __('Celebrating the dedication and triumph of our students who cracked UPSC Civil Services, West Bengal Civil Service, and allied competitive examinations.') }}
            </p>
        </div>

        <!-- Featured Spotlight -->
        @if($this->featuredAlumni->isNotEmpty() && empty($search) && $serviceType === 'all')
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-foreground flex items-center gap-2">
                        <x-icon name="star" class="w-5 h-5 text-amber-500 fill-amber-500" />
                        {{ __('Featured Civil Servants') }}
                    </h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($this->featuredAlumni as $officer)
                        <div class="bg-card border-2 border-violet-500/20 rounded-2xl p-5 shadow-sm hover:shadow-md hover:border-violet-500/50 transition-all flex flex-col justify-between group">
                            <div class="space-y-4">
                                <div class="flex items-center gap-4">
                                    <img
                                        src="{{ $officer->photo_display_url }}"
                                        alt="{{ $officer->full_name }}"
                                        class="w-16 h-16 rounded-full object-cover ring-2 ring-violet-500/30 shrink-0"
                                    />
                                    <div>
                                        <div class="flex items-center gap-1.5">
                                            <h3 class="font-bold text-base text-foreground group-hover:text-violet-600 transition-colors">
                                                {{ $officer->full_name }}
                                            </h3>
                                        </div>
                                        <p class="text-xs font-bold text-violet-700 dark:text-violet-300">
                                            {{ $officer->service_name }}
                                            @if($officer->cadre)
                                                ({{ $officer->cadre }})
                                            @endif
                                        </p>
                                        <p class="text-xs text-muted-foreground">{{ $officer->designation }}</p>
                                    </div>
                                </div>

                                @if($officer->all_india_rank)
                                    <div class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-extrabold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300">
                                        AIR {{ $officer->all_india_rank }} • CSE {{ $officer->exam_year }}
                                    </div>
                                @endif

                                @if($officer->short_bio || $officer->testimonial)
                                    <p class="text-xs text-muted-foreground line-clamp-3 italic bg-muted/30 p-2.5 rounded-lg border border-border/50">
                                        "{{ $officer->short_bio ?: $officer->testimonial }}"
                                    </p>
                                @endif
                            </div>

                            <div class="pt-4 mt-4 border-t border-border flex items-center justify-between text-xs">
                                <span class="text-muted-foreground flex items-center gap-1">
                                    <x-icon name="map-pin" class="w-3.5 h-3.5 text-violet-500" />
                                    {{ $officer->posting_city ? $officer->posting_city . ', ' : '' }}{{ $officer->posting_state ?? 'India' }}
                                </span>
                                <button
                                    type="button"
                                    wire:click="showDossier({{ $officer->id }})"
                                    class="text-violet-600 dark:text-violet-400 font-semibold hover:underline"
                                >
                                    {{ __('View Journey') }} →
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Directory & Search Filters -->
        <div class="space-y-6 pt-4">
            <div class="p-4 bg-card border border-border rounded-xl shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
                <!-- Search Box -->
                <div class="relative w-full sm:w-80">
                    <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        type="text"
                        wire:model.live.debounce.350ms="search"
                        placeholder="{{ __('Search by officer name, service, or posting...') }}"
                        class="w-full pl-9 pr-3 py-2 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"
                    />
                </div>

                <!-- Category Filters -->
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                    <button
                        type="button"
                        wire:click="$set('serviceType', 'all')"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $serviceType === 'all' ? 'bg-violet-600 text-white' : 'bg-muted text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('All Services') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('serviceType', 'upsc')"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $serviceType === 'upsc' ? 'bg-violet-600 text-white' : 'bg-muted text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('UPSC Civil Services') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('serviceType', 'wbcs')"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $serviceType === 'wbcs' ? 'bg-violet-600 text-white' : 'bg-muted text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('WBCS / WBPS') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('serviceType', 'banking')"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $serviceType === 'banking' ? 'bg-violet-600 text-white' : 'bg-muted text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('Banking & PSU') }}
                    </button>
                </div>
            </div>

            <!-- Officers Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                @forelse($this->alumniList as $alumnus)
                    <div class="bg-card border border-border rounded-xl p-4 shadow-xs hover:border-violet-500/40 hover:shadow-md transition-all flex flex-col justify-between">
                        <div class="space-y-3">
                            <div class="flex items-center gap-3">
                                <img
                                    src="{{ $alumnus->photo_display_url }}"
                                    alt="{{ $alumnus->full_name }}"
                                    class="w-13 h-13 rounded-full object-cover border border-border shrink-0"
                                />
                                <div>
                                    <h4 class="font-bold text-sm text-foreground">{{ $alumnus->full_name }}</h4>
                                    <p class="text-xs font-semibold text-violet-700 dark:text-violet-300">{{ $alumnus->service_name }}</p>
                                    <p class="text-[11px] text-muted-foreground truncate max-w-[130px]">{{ $alumnus->designation }}</p>
                                </div>
                            </div>

                            <div class="text-xs text-muted-foreground space-y-1">
                                <div class="flex items-center gap-1 truncate">
                                    <x-icon name="map-pin" class="w-3.5 h-3.5 text-violet-500 shrink-0" />
                                    <span>{{ $alumnus->posting_city ? $alumnus->posting_city . ', ' : '' }}{{ $alumnus->posting_state ?? 'India' }}</span>
                                </div>
                                @if($alumnus->all_india_rank)
                                    <div class="text-amber-600 dark:text-amber-400 font-bold">
                                        AIR {{ $alumnus->all_india_rank }} ({{ $alumnus->exam_year }})
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="pt-3 mt-3 border-t border-border flex items-center justify-between">
                            <span class="text-[11px] text-muted-foreground">{{ $alumnus->courses_summary }}</span>
                            <button
                                type="button"
                                wire:click="showDossier({{ $alumnus->id }})"
                                class="text-xs font-medium text-violet-600 dark:text-violet-400 hover:underline"
                            >
                                {{ __('Details') }}
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full p-12 text-center text-muted-foreground text-sm bg-card border border-border rounded-xl">
                        {{ __('No alumni records found matching your search.') }}
                    </div>
                @endforelse
            </div>

            <!-- Pagination -->
            <div class="mt-6">
                {{ $this->alumniList->links() }}
            </div>
        </div>

        <!-- Dossier Modal -->
        @if($showDossierModal && $viewingAlumni)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-200">
                <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden max-h-[85vh] flex flex-col">
                    <div class="bg-violet-600 p-6 text-white relative">
                        <button
                            type="button"
                            wire:click="$set('showDossierModal', false)"
                            class="absolute top-4 right-4 text-white/80 hover:text-white p-1"
                        >
                            <x-icon name="x" class="w-5 h-5" />
                        </button>
                        <div class="flex items-center gap-4">
                            <img src="{{ $viewingAlumni->photo_display_url }}" class="w-16 h-16 rounded-full object-cover ring-2 ring-white/40" />
                            <div>
                                <h3 class="text-xl font-bold">{{ $viewingAlumni->full_name }}</h3>
                                <p class="text-sm font-semibold text-violet-200">{{ $viewingAlumni->service_name }} • {{ $viewingAlumni->designation }}</p>
                                <p class="text-xs text-white/80">{{ $viewingAlumni->posting_city ? $viewingAlumni->posting_city . ', ' : '' }}{{ $viewingAlumni->posting_state ?? 'India' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 overflow-y-auto space-y-4 text-sm">
                        @if($viewingAlumni->short_bio)
                            <p class="text-foreground leading-relaxed">{{ $viewingAlumni->short_bio }}</p>
                        @endif

                        @if($viewingAlumni->success_story)
                            <div class="p-4 rounded-xl bg-violet-50/50 dark:bg-violet-950/30 border border-violet-100 dark:border-violet-900/40">
                                <h4 class="text-xs font-bold text-violet-900 dark:text-violet-300 uppercase tracking-wider mb-1.5">{{ __('Preparation & Guidance Journey') }}</h4>
                                <p class="text-xs text-foreground/90 whitespace-pre-line leading-relaxed">{{ $viewingAlumni->success_story }}</p>
                            </div>
                        @endif

                        <div>
                            <h4 class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-1.5">{{ __('Enrolled Programs at SNTCSSC') }}</h4>
                            <div class="space-y-1.5">
                                @foreach($viewingAlumni->courseBatches as $cb)
                                    <div class="text-xs p-2 rounded-lg bg-muted/30 border border-border flex items-center justify-between">
                                        <span class="font-medium text-foreground">{{ $cb->course_name }}</span>
                                        <span class="text-muted-foreground">{{ $cb->batch_name }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="p-4 border-t border-border bg-muted/20 text-right">
                        <x-ui.button variant="outline" size="sm" wire:click="$set('showDossierModal', false)">
                            {{ __('Close') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
