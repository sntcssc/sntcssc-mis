<?php

use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Services\StudyMaterial\StudyMaterialService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('My Study Materials')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tab')]
    public string $activeTab = 'all'; // all, lecture_notes, e_book_pdf, slide_deck, video_recording, question_bank, pinned

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    public string $viewMode = 'grid'; // grid, table
    public int $perPage = 12;

    // Rich Notes Viewer Modal
    public bool $showNotesModal = false;
    public ?StudyMaterial $readingMaterial = null;

    #[Computed]
    public function student()
    {
        return auth()->user();
    }

    #[Computed]
    public function myEnrollments()
    {
        return Enrollment::with(['course', 'batch'])
            ->where('user_id', auth()->id())
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->get();
    }

    #[Computed]
    public function myCourses()
    {
        return $this->myEnrollments->pluck('course')->unique('id')->filter()->values();
    }

    #[Computed]
    public function myBatches()
    {
        return $this->myEnrollments->pluck('batch')->unique('id')->filter()->values();
    }

    #[Computed]
    public function materials()
    {
        $user = auth()->user();
        $query = StudyMaterial::with(['course', 'batches', 'files', 'typeDefinition'])
            ->published()
            ->forUser($user);

        if ($this->activeTab === 'pinned') {
            $query->where('is_pinned', true);
        } elseif ($this->activeTab === 'public') {
            $query->where(function (Builder $q) {
                $q->whereNull('course_id')
                    ->orWhere('access_level', StudyMaterial::ACCESS_PUBLIC_LINK)
                    ->orWhereIn('material_type', [StudyMaterial::TYPE_CURRENT_AFFAIRS, StudyMaterial::TYPE_PIB_SANDHAN])
                    ->orWhereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
            });
        } elseif (in_array($this->activeTab, array_keys(StudyMaterial::materialTypes()), true)) {
            $query->where('material_type', $this->activeTab);
        }

        if ($search = trim($this->search)) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('topic', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('course', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if ($this->courseFilter === 'public') {
            $query->where(function (Builder $q) {
                $q->whereNull('course_id')
                    ->orWhere('access_level', StudyMaterial::ACCESS_PUBLIC_LINK)
                    ->orWhereIn('material_type', [StudyMaterial::TYPE_CURRENT_AFFAIRS, StudyMaterial::TYPE_PIB_SANDHAN])
                    ->orWhereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
            });
        } elseif ($this->courseFilter !== '') {
            $query->where('course_id', $this->courseFilter);
        }

        if ($this->batchFilter !== '') {
            $query->whereHas('batches', fn ($b) => $b->where('batches.id', $this->batchFilter));
        }

        if ($this->typeFilter !== '') {
            $query->where('material_type', $this->typeFilter);
        }

        return $query->ordered()->paginate($this->perPage);
    }

    public function mount(): void
    {
        abort_unless(auth()->user()->can('study_materials.view') || auth()->user()->hasRole('Super Administrator'), 403);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function openNotesModal(int $id, StudyMaterialService $service): void
    {
        $material = StudyMaterial::with(['course', 'batches', 'files'])->findOrFail($id);

        if (! $material->isAccessibleBy(auth()->user())) {
            abort(403, __('You do not have permission to view this study material.'));
        }

        $this->readingMaterial = $material;
        $service->recordAccess($this->readingMaterial, StudyMaterialAccessLog::ACTION_VIEW);
        $this->showNotesModal = true;
    }
}; ?>

<div class="space-y-6">
    <!-- Header Banner -->
    <div class="bg-gradient-to-r from-blue-900 via-indigo-900 to-slate-900 rounded-2xl p-6 sm:p-8 text-white shadow-lg relative overflow-hidden">
        <div class="absolute right-0 top-0 w-80 h-80 bg-white/5 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20"></div>

        <div class="relative z-10 max-w-2xl">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-white/10 text-white/90 backdrop-blur-sm mb-3">
                <x-icon name="graduation-cap" class="w-3.5 h-3.5" />
                {{ __('Official SNTCSSC Academic Repository') }}
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">
                {{ __('My Course Study Materials') }}
            </h1>
            <p class="text-white/80 text-xs sm:text-sm mt-2 leading-relaxed">
                {{ __('Access batch-wise lecture notes, syllabus blueprints, reference PDFs, question banks, and class recordings curated by faculty.') }}
            </p>
        </div>
    </div>

    <!-- Scope Filter Tabs -->
    <div class="border-b border-border">
        <nav class="flex space-x-2 overflow-x-auto scrollbar-none py-1 text-xs">
            <button wire:click="setTab('all')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'all' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="layers" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('All Resources') }}
            </button>
            <button wire:click="setTab('current_affairs')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'current_affairs' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="newspaper" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Current Affairs') }}
            </button>
            <button wire:click="setTab('pib_sandhan')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'pib_sandhan' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="book-marked" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('PIB Sandhan') }}
            </button>
            <button wire:click="setTab('public')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'public' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="globe" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Public Publications') }}
            </button>
            <button wire:click="setTab('lecture_notes')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'lecture_notes' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="file-text" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Lecture Notes') }}
            </button>
            <button wire:click="setTab('e_book_pdf')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'e_book_pdf' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="book-open" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('E-Books & PDFs') }}
            </button>
            <button wire:click="setTab('slide_deck')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'slide_deck' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="presentation" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Slide Decks') }}
            </button>
            <button wire:click="setTab('video_recording')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'video_recording' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="video" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Recordings') }}
            </button>
            <button wire:click="setTab('question_bank')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'question_bank' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="clipboard-check" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Mock Tests & Questions') }}
            </button>
            <button wire:click="setTab('pinned')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'pinned' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="pin" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Priority Pinned') }}
            </button>
        </nav>
    </div>

    <!-- Search & Filter Bar -->
    <div class="bg-card rounded-xl border border-border p-4 shadow-sm space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <!-- Search -->
            <div class="lg:col-span-2 relative">
                <x-icon name="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('Search study materials, topics, notes...') }}"
                       class="w-full pl-9 pr-4 py-2 text-xs rounded-lg border border-border bg-background text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary focus:outline-none" />
                @if($search)
                    <button wire:click="$set('search', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="w-3.5 h-3.5" />
                    </button>
                @endif
            </div>

            <!-- Course Filter -->
            <div>
                <select wire:model.live="courseFilter" class="w-full py-2 px-3 text-xs rounded-lg border border-border bg-background text-foreground focus:ring-2 focus:ring-primary focus:outline-none">
                    <option value="">{{ __('All Enrolled Courses') }}</option>
                    <option value="public">{{ __('Public & General Publications') }}</option>
                    @foreach($this->myCourses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Batch Filter -->
            <div>
                <select wire:model.live="batchFilter" class="w-full py-2 px-3 text-xs rounded-lg border border-border bg-background text-foreground focus:ring-2 focus:ring-primary focus:outline-none">
                    <option value="">{{ __('All My Batches') }}</option>
                    @foreach($this->myBatches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Type Filter -->
            <div>
                <select wire:model.live="typeFilter" class="w-full py-2 px-3 text-xs rounded-lg border border-border bg-background text-foreground focus:ring-2 focus:ring-primary focus:outline-none">
                    <option value="">{{ __('All Types') }}</option>
                    @foreach(StudyMaterial::materialTypes() as $k => $label)
                        <option value="{{ $k }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex items-center justify-between pt-2 border-t border-border/50 text-xs text-muted-foreground">
            <div>
                <span>{{ __('Found') }} <strong class="text-foreground">{{ $this->materials->total() }}</strong> {{ __('materials') }}</span>
                @if($search || $courseFilter || $batchFilter || $typeFilter)
                    <button wire:click="$set('search', ''); $set('courseFilter', ''); $set('batchFilter', ''); $set('typeFilter', '');"
                            class="ml-2 text-primary hover:underline font-medium">
                        {{ __('Reset Filters') }}
                    </button>
                @endif
            </div>

            <!-- Grid vs List Switcher -->
            <div class="flex items-center bg-muted rounded-lg p-0.5 border border-border">
                <button type="button" wire:click="$set('viewMode', 'grid')"
                        class="p-1 rounded {{ $viewMode === 'grid' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' }}"
                        title="{{ __('Cards View') }}">
                    <x-icon name="grid" class="w-3.5 h-3.5" />
                </button>
                <button type="button" wire:click="$set('viewMode', 'table')"
                        class="p-1 rounded {{ $viewMode === 'table' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' }}"
                        title="{{ __('List View') }}">
                    <x-icon name="list" class="w-3.5 h-3.5" />
                </button>
            </div>
        </div>
    </div>

    <!-- Materials Grid / List -->
    @if($this->materials->isEmpty())
        <div class="bg-card rounded-2xl border border-dashed border-border p-12 text-center">
            <div class="w-16 h-16 rounded-full bg-primary/10 text-primary mx-auto flex items-center justify-center mb-4">
                <x-icon name="folder-check" class="w-8 h-8" />
            </div>
            <h3 class="text-base font-semibold text-foreground">{{ __('No Study Materials Available') }}</h3>
            <p class="text-xs text-muted-foreground max-w-md mx-auto mt-1">
                {{ __('There are no published study materials for your enrolled courses matching the current filters. New materials will appear here as soon as faculty publishes them.') }}
            </p>
        </div>
    @elseif($viewMode === 'grid')
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($this->materials as $material)
                <div class="bg-card rounded-xl border border-border hover:border-primary/50 shadow-sm transition-all flex flex-col justify-between group overflow-hidden {{ $material->is_pinned ? 'ring-1 ring-primary/40' : '' }}">
                    @if($material->is_pinned)
                        <div class="bg-primary text-primary-foreground text-[10px] font-bold px-3 py-1 flex items-center gap-1.5">
                            <x-icon name="pin" class="w-3 h-3" />
                            <span>{{ __('PRIORITY COURSE RESOURCE') }}</span>
                        </div>
                    @endif

                    @if($material->thumbnail_image_url)
                        <div class="h-40 w-full bg-muted/60 relative overflow-hidden border-b border-border">
                            <img src="{{ $material->thumbnail_image_url }}" alt="{{ $material->title }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
                            @if($material->class_date)
                                <div class="absolute bottom-2 left-2 bg-background/90 text-foreground backdrop-blur-sm text-[10px] font-semibold px-2 py-0.5 rounded shadow-sm flex items-center gap-1">
                                    <x-icon name="calendar" class="w-3 h-3 text-primary" />
                                    <span>{{ __('Class: :date', ['date' => $material->class_date->format('d M Y')]) }}</span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="p-5 space-y-3">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-primary/10 text-primary border border-primary/20">
                                <x-icon name="{{ $material->material_type_icon }}" class="w-3 h-3" />
                                {{ $material->material_type_label }}
                            </span>
                            @if($material->class_date && !$material->thumbnail_image_url)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                                    <x-icon name="calendar" class="w-3 h-3" />
                                    {{ __('Class: :date', ['date' => $material->class_date->format('d M Y')]) }}
                                </span>
                            @endif
                            @if($material->isPubliclyAvailable())
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                                    <x-icon name="globe" class="w-3 h-3" />
                                    <span>{{ __('Public Access') }}</span>
                                </span>
                            @endif
                            @if($material->subject)
                                <span class="px-2 py-0.5 rounded-md text-[11px] font-medium bg-muted text-muted-foreground border border-border">
                                    {{ $material->subject }}
                                </span>
                            @endif
                        </div>

                        <div>
                            <h3 class="text-base font-bold text-foreground group-hover:text-primary transition-colors line-clamp-2" title="{{ $material->title }}">
                                {{ $material->title }}
                            </h3>
                            @if($material->topic)
                                <p class="text-xs text-muted-foreground mt-0.5 flex items-center gap-1">
                                    <x-icon name="tag" class="w-3 h-3" />
                                    <span>{{ $material->topic }}</span>
                                </p>
                            @endif
                        </div>

                        <div class="pt-2 border-t border-border/50 text-xs text-muted-foreground">
                            <span class="font-medium text-foreground block truncate">{{ $material->course?->name ?: __('General / Public Publication') }}</span>
                            <span class="text-[11px]">{{ $material->course ? $material->batches_summary : __('Open to All Students') }}</span>
                        </div>

                        @if($material->description)
                            <p class="text-xs text-muted-foreground line-clamp-2">
                                {{ $material->description }}
                            </p>
                        @endif

                        <!-- Files list -->
                        @if($material->files->isNotEmpty())
                            <div class="space-y-1.5 pt-1">
                                @foreach($material->files as $file)
                                    <div class="flex items-center justify-between p-2 rounded-lg bg-muted/40 border border-border/60 text-xs">
                                        <div class="flex items-center gap-2 truncate">
                                            <x-icon name="{{ $file->file_icon }}" class="w-4 h-4 text-primary shrink-0" />
                                            <span class="font-medium text-foreground truncate" title="{{ $file->file_name }}">{{ $file->file_name }}</span>
                                            <span class="text-[10px] text-muted-foreground font-mono">({{ $file->formatted_size }})</span>
                                        </div>
                                        <a href="{{ $file->download_url }}"
                                           class="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-primary text-primary-foreground hover:bg-primary/90 text-[11px] font-semibold shadow-sm shrink-0">
                                            <x-icon name="download" class="w-3 h-3" />
                                            <span>{{ __('Download') }}</span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($material->external_url)
                            <div class="pt-1">
                                <a href="{{ $material->external_url }}" target="_blank" rel="noopener noreferrer"
                                   class="w-full inline-flex items-center justify-center gap-1.5 p-2 rounded-lg bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/40 dark:hover:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-800 text-xs font-semibold transition-colors">
                                    <x-icon name="external-link" class="w-3.5 h-3.5" />
                                    <span>{{ __('Open Class / Video Recording') }}</span>
                                </a>
                            </div>
                        @endif
                    </div>

                    <!-- Footer -->
                    <div class="bg-muted/30 px-5 py-3 border-t border-border flex items-center justify-between text-xs">
                        <div class="text-[11px] text-muted-foreground flex flex-col">
                            @if($material->class_date)
                                <span class="font-medium text-foreground flex items-center gap-1">
                                    <x-icon name="calendar" class="w-3 h-3 text-primary" />
                                    <span>{{ __('Class Date: :date', ['date' => $material->class_date->format('d M Y')]) }}</span>
                                </span>
                            @endif
                            <span>{{ __('Published: :date', ['date' => $material->created_at?->format('d M Y')]) }}</span>
                        </div>

                        <div class="flex items-center gap-2">
                            @if($material->content)
                                <button type="button" wire:click="openNotesModal({{ $material->id }})"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-border bg-card hover:bg-muted font-medium text-xs text-foreground">
                                    <x-icon name="book-open" class="w-3.5 h-3.5 text-primary" />
                                    <span>{{ __('Read Notes') }}</span>
                                </button>
                            @endif

                            @if($material->files->isNotEmpty())
                                <a href="{{ route('study-materials.download', ['studyMaterial' => $material->id]) }}"
                                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 font-semibold text-xs shadow-sm">
                                    <x-icon name="download" class="w-3.5 h-3.5" />
                                    <span>{{ __('Get All Files') }}</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <!-- Table View -->
        <div class="bg-card rounded-xl border border-border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase text-[11px] font-semibold">
                        <tr>
                            <th class="py-3 px-4">{{ __('Study Material') }}</th>
                            <th class="py-3 px-4">{{ __('Class Date') }}</th>
                            <th class="py-3 px-4">{{ __('Course') }}</th>
                            <th class="py-3 px-4">{{ __('Type') }}</th>
                            <th class="py-3 px-4">{{ __('Files') }}</th>
                            <th class="py-3 px-4 text-right">{{ __('Access & Download') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @foreach($this->materials as $material)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="py-3 px-4">
                                    <div class="flex items-start gap-2.5">
                                        @if($material->thumbnail_image_url)
                                            <img src="{{ $material->thumbnail_image_url }}" alt="{{ $material->title }}" class="w-9 h-9 rounded-lg object-cover border border-border shrink-0 mt-0.5" />
                                        @else
                                            <div class="w-9 h-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0 mt-0.5">
                                                <x-icon name="{{ $material->material_type_icon }}" class="w-4 h-4" />
                                            </div>
                                        @endif
                                        <div>
                                            <span class="font-bold text-foreground text-sm">{{ $material->title }}</span>
                                            @if($material->subject || $material->topic)
                                                <p class="text-[11px] text-muted-foreground mt-0.5">
                                                    {{ $material->subject }} &bull; {{ $material->topic }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    @if($material->class_date)
                                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-foreground bg-primary/5 px-2 py-0.5 rounded border border-primary/20">
                                            <x-icon name="calendar" class="w-3 h-3 text-primary" />
                                            {{ $material->class_date->format('d M Y') }}
                                        </span>
                                    @else
                                        <span class="text-muted-foreground text-[11px] italic">-</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-medium text-foreground block">{{ $material->course?->name ?: __('General / Public Publication') }}</span>
                                    <span class="text-[11px] text-muted-foreground">{{ $material->course ? $material->batches_summary : __('Open to All Students') }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] bg-muted font-medium">
                                        {{ $material->material_type_label }}
                                    </span>
                                    @if($material->isPubliclyAvailable())
                                        <span class="block text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold mt-0.5">
                                            {{ __('Public') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-medium text-foreground">{{ $material->files->count() }} file(s)</span>
                                    <span class="text-[10px] text-muted-foreground block">{{ $material->formatted_total_size }}</span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($material->content)
                                            <button wire:click="openNotesModal({{ $material->id }})" class="px-3 py-1.5 rounded-lg border border-border bg-card hover:bg-muted font-medium text-xs">
                                                {{ __('Read') }}
                                            </button>
                                        @endif
                                        @if($material->external_url)
                                            <a href="{{ $material->external_url }}" target="_blank" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white font-medium text-xs flex items-center gap-1">
                                                <x-icon name="external-link" class="w-3.5 h-3.5" />
                                                <span>{{ __('Open') }}</span>
                                            </a>
                                        @endif
                                        @if($material->files->isNotEmpty())
                                            <a href="{{ route('study-materials.download', ['studyMaterial' => $material->id]) }}" class="px-3 py-1.5 rounded-lg bg-primary text-primary-foreground font-semibold text-xs flex items-center gap-1">
                                                <x-icon name="download" class="w-3.5 h-3.5" />
                                                <span>{{ __('Download') }}</span>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="mt-4">
        {{ $this->materials->links() }}
    </div>

    <!-- READING MODAL FOR EMBEDDED NOTES -->
    @if($showNotesModal && $readingMaterial)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-background/80 backdrop-blur-sm">
            <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden animate-in fade-in-50 zoom-in-95">
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/30">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-primary">{{ $readingMaterial->material_type_label }}</span>
                        <h2 class="text-base font-bold text-foreground">
                            {{ $readingMaterial->title }}
                        </h2>
                    </div>
                    <button wire:click="$set('showNotesModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg hover:bg-muted">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 overflow-y-auto space-y-4 text-xs flex-1">
                    @if($readingMaterial->thumbnail_image_url)
                        <div class="h-44 w-full rounded-xl overflow-hidden border border-border relative bg-muted/40">
                            <img src="{{ $readingMaterial->thumbnail_image_url }}" alt="{{ $readingMaterial->title }}" class="w-full h-full object-cover" />
                        </div>
                    @endif

                    <div class="flex items-center justify-between text-muted-foreground text-[11px] pb-3 border-b border-border flex-wrap gap-2">
                        <div class="flex items-center gap-2 flex-wrap">
                            @if($readingMaterial->subject) <span>{{ __('Subject:') }} <strong class="text-foreground">{{ $readingMaterial->subject }}</strong></span> @endif
                            @if($readingMaterial->topic) <span>&bull; {{ __('Topic:') }} <strong class="text-foreground">{{ $readingMaterial->topic }}</strong></span> @endif
                        </div>
                        @if($readingMaterial->class_date)
                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary bg-primary/10 px-2 py-0.5 rounded border border-primary/20">
                                <x-icon name="calendar" class="w-3 h-3 text-primary" />
                                <span>{{ __('Class Date: :date', ['date' => $readingMaterial->class_date->format('d M Y')]) }}</span>
                            </span>
                        @endif
                    </div>

                    <div class="prose dark:prose-invert max-w-none text-foreground text-xs sm:text-sm leading-relaxed whitespace-pre-line font-mono p-4 rounded-xl bg-muted/30 border border-border/60">
                        {{ $readingMaterial->content }}
                    </div>
                </div>

                <div class="px-6 py-3 border-t border-border flex items-center justify-end bg-muted/20">
                    <button type="button" wire:click="$set('showNotesModal', false)"
                            class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs">
                        {{ __('Close Reading View') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
