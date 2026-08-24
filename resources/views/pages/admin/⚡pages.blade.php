<?php

use App\Models\Language;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Pages Management')] class extends Component {
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    public bool $showTrashed = false;

    public string $sortField = 'sort_order';
    public string $sortDirection = 'asc';

    // Modal state
    public bool $modalOpen = false;
    public bool $previewModalOpen = false;
    public ?int $editingPageId = null;

    // Active editing language tab
    public string $activeLocale = 'en';

    // Editor view mode: 'edit' or 'preview'
    public string $editorTab = 'edit';

    // Form data
    public array $form = [
        'slug' => '',
        'status' => Page::STATUS_PUBLISHED,
        'sort_order' => 0,
        'is_system' => false,
    ];

    /**
     * Multilingual translations array:
     * ['en' => ['title' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '', 'content' => ''], ...]
     */
    public array $translations = [];

    // Preview state
    public ?Page $previewPage = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedShowTrashed(): void
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
    public function availableLanguages()
    {
        return Language::activeCached();
    }

    #[Computed]
    public function pages()
    {
        $query = Page::query()
            ->with(['translations', 'creator', 'editor'])
            ->when($this->showTrashed, fn (Builder $q) => $q->onlyTrashed(), fn (Builder $q) => $q->withoutTrashed())
            ->when($this->statusFilter !== 'all', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', function (Builder $q) {
                $term = trim($this->search);
                $q->where(function (Builder $sub) use ($term) {
                    $sub->where('slug', 'like', "%{$term}%")
                        ->orWhereHas('translations', function (Builder $t) use ($term) {
                            $t->where('title', 'like', "%{$term}%")
                                ->orWhere('meta_description', 'like', "%{$term}%")
                                ->orWhere('content', 'like', "%{$term}%");
                        });
                });
            });

        if (in_array($this->sortField, ['id', 'slug', 'status', 'sort_order', 'view_count', 'updated_at'], true)) {
            $query->orderBy($this->sortField, $this->sortDirection);
        } else {
            $query->ordered();
        }

        return $query->paginate(10);
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingPageId = null;
        $this->editorTab = 'edit';
        $this->activeLocale = 'en';

        $maxSort = (int) (Page::max('sort_order') ?? 0);

        $this->form = [
            'slug' => '',
            'status' => Page::STATUS_PUBLISHED,
            'sort_order' => $maxSort + 1,
            'is_system' => false,
        ];

        $this->translations = [];
        foreach ($this->availableLanguages as $lang) {
            $this->translations[$lang->code] = [
                'title' => '',
                'meta_title' => '',
                'meta_description' => '',
                'meta_keywords' => '',
                'content' => '',
            ];
        }

        $this->modalOpen = true;
    }

    public function editPage(int $id): void
    {
        $this->resetValidation();
        $page = Page::withTrashed()->with('translations')->findOrFail($id);

        $this->editingPageId = $page->id;
        $this->editorTab = 'edit';
        $this->activeLocale = 'en';

        $this->form = [
            'slug' => $page->slug,
            'status' => $page->status,
            'sort_order' => $page->sort_order,
            'is_system' => (bool) $page->is_system,
        ];

        $this->translations = [];
        foreach ($this->availableLanguages as $lang) {
            $trans = $page->translations->firstWhere('locale', $lang->code);
            $this->translations[$lang->code] = [
                'title' => $trans?->title ?? '',
                'meta_title' => $trans?->meta_title ?? '',
                'meta_description' => $trans?->meta_description ?? '',
                'meta_keywords' => $trans?->meta_keywords ?? '',
                'content' => $trans?->content ?? '',
            ];
        }

        $this->modalOpen = true;
    }

    public function generateSlug(): void
    {
        if (! empty($this->translations['en']['title']) && empty($this->form['slug'])) {
            $this->form['slug'] = Str::slug($this->translations['en']['title']);
        }
    }

    public function save(): void
    {
        $rules = [
            'form.slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:pages,slug,'.($this->editingPageId ?? 'NULL').',id',
            ],
            'form.status' => ['required', 'in:published,draft,inactive'],
            'form.sort_order' => ['required', 'integer', 'min:0'],
            'translations.en.title' => ['required', 'string', 'max:255'],
        ];

        $this->validate($rules, [
            'form.slug.regex' => __('The slug must contain only lowercase letters, numbers, and hyphens (e.g. about-us).'),
            'translations.en.title.required' => __('English page title is required.'),
        ]);

        try {
            DB::transaction(function () {
                $isNew = ! $this->editingPageId;

                $page = $this->editingPageId
                    ? Page::withTrashed()->findOrFail($this->editingPageId)
                    : new Page();

                $oldValues = $isNew ? null : $page->toArray();

                $page->slug = Str::slug($this->form['slug']);
                $page->status = $this->form['status'];
                $page->sort_order = (int) $this->form['sort_order'];

                if ($isNew) {
                    $page->created_by = Auth::id();
                    $page->is_system = false;
                } else {
                    $page->updated_by = Auth::id();
                }

                $page->save();

                // Save or update translations
                foreach ($this->translations as $locale => $data) {
                    // Only save if at least title or content is filled
                    if (filled($data['title']) || filled($data['content'])) {
                        $trans = PageTranslation::withTrashed()->firstOrNew([
                            'page_id' => $page->id,
                            'locale' => $locale,
                        ]);

                        $trans->title = $data['title'] ?: ($this->translations['en']['title'] ?? $page->slug);
                        $trans->meta_title = $data['meta_title'] ?: $trans->title;
                        $trans->meta_description = $data['meta_description'] ?: null;
                        $trans->meta_keywords = $data['meta_keywords'] ?: null;
                        $trans->content = $data['content'] ?: '';

                        if (! $trans->exists) {
                            $trans->created_by = Auth::id();
                        } else {
                            $trans->updated_by = Auth::id();
                        }

                        $trans->save();

                        if ($trans->trashed()) {
                            $trans->restore();
                        }
                    }
                }

                AuditLogService::log(
                    event: $isNew ? 'page.created' : 'page.updated',
                    description: ($isNew ? 'Created dynamic page: ' : 'Updated dynamic page: ').$page->slug,
                    auditable: $page,
                    oldValues: $oldValues,
                    newValues: $page->fresh()->toArray()
                );
            });

            $this->modalOpen = false;
            Toast::dispatch($this, 'success', $this->editingPageId ? __('Page updated successfully.') : __('Page created successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save page: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function toggleStatus(int $id): void
    {
        try {
            $page = Page::findOrFail($id);
            $newStatus = $page->status === Page::STATUS_PUBLISHED ? Page::STATUS_DRAFT : Page::STATUS_PUBLISHED;

            $page->update([
                'status' => $newStatus,
                'updated_by' => Auth::id(),
            ]);

            AuditLogService::log(
                event: 'page.status_changed',
                description: "Changed status of page [{$page->slug}] to {$newStatus}",
                auditable: $page
            );

            Toast::dispatch($this, 'success', __('Status updated to :status.', ['status' => ucfirst($newStatus)]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to change status.'));
        }
    }

    public function deletePage(int $id): void
    {
        try {
            $page = Page::findOrFail($id);

            if ($page->is_system) {
                Toast::dispatch($this, 'warning', __('Core system policy pages cannot be deleted, but you can set their status to Draft.'));

                return;
            }

            $page->deleted_by = Auth::id();
            $page->save();
            $page->delete();

            AuditLogService::log(
                event: 'page.deleted',
                description: "Soft deleted page [{$page->slug}]",
                auditable: $page
            );

            Toast::dispatch($this, 'success', __('Page moved to trash.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete page.'));
        }
    }

    public function restorePage(int $id): void
    {
        try {
            $page = Page::onlyTrashed()->findOrFail($id);
            $page->restore();

            AuditLogService::log(
                event: 'page.restored',
                description: "Restored page [{$page->slug}] from trash",
                auditable: $page
            );

            Toast::dispatch($this, 'success', __('Page restored successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to restore page.'));
        }
    }

    public function forceDeletePage(int $id): void
    {
        try {
            $page = Page::onlyTrashed()->findOrFail($id);

            if ($page->is_system) {
                Toast::dispatch($this, 'error', __('Core system policy pages cannot be permanently deleted.'));

                return;
            }

            $slug = $page->slug;
            $page->translations()->forceDelete();
            $page->forceDelete();

            AuditLogService::log(
                event: 'page.permanently_deleted',
                description: "Permanently deleted page [{$slug}] and all its translations"
            );

            Toast::dispatch($this, 'success', __('Page permanently removed.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to permanently delete page.'));
        }
    }

    public function openLivePreview(int $id): void
    {
        $this->previewPage = Page::withTrashed()->with('translations')->findOrFail($id);
        $this->previewModalOpen = true;
    }
}; ?>

<div class="space-y-6">
    {{-- Header with Title, Stats and Create CTA --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-2xs">
                    <x-icon name="file-text" class="h-5 w-5"/>
                </span>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground">{{ __('Pages & Content Management') }}</h1>
                    <p class="text-xs sm:text-sm text-muted-foreground">{{ __('Create dynamic SEO-friendly institutional pages with rich text formatting & multi-language translations.') }}</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <button
                type="button"
                wire:click="openCreateModal"
                class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
            >
                <x-icon name="plus" class="h-4 w-4"/>
                <span>{{ __('Create New Page') }}</span>
            </button>
        </div>
    </div>

    {{-- Filter and Search Controls Bar --}}
    <div class="rounded-2xl border border-border bg-card p-4 shadow-2xs space-y-3">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
            {{-- Search Bar --}}
            <div class="relative w-full sm:w-80">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by title, slug, or content…') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 text-xs sm:text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            {{-- Status & Trash Filters --}}
            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto justify-end">
                <select
                    wire:model.live="statusFilter"
                    class="h-9 rounded-md border border-input bg-card px-3 text-xs sm:text-sm shadow-xs outline-none focus-visible:border-ring"
                >
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="published">{{ __('Published') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </select>

                <button
                    type="button"
                    wire:click="$toggle('showTrashed')"
                    class="inline-flex items-center gap-1.5 h-9 rounded-md border px-3 text-xs font-medium transition-colors cursor-pointer {{ $showTrashed ? 'border-destructive bg-destructive/10 text-destructive' : 'border-border bg-card text-muted-foreground hover:bg-secondary' }}"
                >
                    <x-icon name="trash" class="h-3.5 w-3.5"/>
                    <span>{{ $showTrashed ? __('Showing Trash') : __('Trash') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Pages Data Table --}}
    <div class="rounded-2xl border border-border bg-card shadow-2xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead class="bg-muted/60 border-b border-border text-muted-foreground uppercase text-[10px] tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('sort_order')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('Order') }}</span>
                                <x-icon name="arrow-up-down" class="h-3 w-3 opacity-50"/>
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Page Title & Slug') }}</th>
                        <th class="px-4 py-3">{{ __('Language Translations') }}</th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('status')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('Status') }}</span>
                                <x-icon name="arrow-up-down" class="h-3 w-3 opacity-50"/>
                            </div>
                        </th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('view_count')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('Views') }}</span>
                                <x-icon name="arrow-up-down" class="h-3 w-3 opacity-50"/>
                            </div>
                        </th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('updated_at')">
                            <div class="flex items-center gap-1.5">
                                <span>{{ __('Updated') }}</span>
                                <x-icon name="arrow-up-down" class="h-3 w-3 opacity-50"/>
                            </div>
                        </th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->pages as $page)
                        <tr class="hover:bg-secondary/30 transition-colors" wire:key="page-row-{{ $page->id }}">
                            {{-- Order --}}
                            <td class="px-4 py-3.5 font-mono text-xs text-muted-foreground font-semibold">
                                #{{ $page->sort_order }}
                            </td>

                            {{-- Title & Slug --}}
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="font-bold text-foreground truncate max-w-xs sm:max-w-md">{{ $page->title }}</p>
                                            @if ($page->is_system)
                                                <span class="inline-flex items-center rounded-md bg-secondary px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                                    {{ __('System') }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 text-xs text-muted-foreground mt-0.5 font-mono">
                                            <span>/pages/{{ $page->slug }}</span>
                                            <a
                                                href="{{ route('public.page', $page->slug) }}"
                                                target="_blank"
                                                class="text-primary hover:underline inline-flex items-center gap-0.5"
                                                title="{{ __('Open public page') }}"
                                            >
                                                <x-icon name="external-link" class="h-3 w-3"/>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            {{-- Translation Badges --}}
                            <td class="px-4 py-3.5">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach ($this->availableLanguages as $lang)
                                        @php($hasTrans = $page->hasTranslation($lang->code))
                                        <span
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-semibold {{ $hasTrans ? 'bg-primary/10 text-primary border border-primary/20' : 'bg-muted text-muted-foreground/60 border border-border' }}"
                                            title="{{ $lang->name }} ({{ $hasTrans ? __('Translated') : __('Missing') }})"
                                        >
                                            <span class="uppercase font-mono">{{ $lang->code }}</span>
                                            @if ($hasTrans)
                                                <x-icon name="check" class="h-2.5 w-2.5"/>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            </td>

                            {{-- Status --}}
                            <td class="px-4 py-3.5">
                                @if ($showTrashed)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2.5 py-1 text-xs font-semibold text-destructive">
                                        <x-icon name="trash" class="h-3 w-3"/>
                                        <span>{{ __('Trashed') }}</span>
                                    </span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="toggleStatus({{ $page->id }})"
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold cursor-pointer transition-all {{ $page->status === 'published' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20' : ($page->status === 'draft' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20' : 'bg-muted text-muted-foreground hover:bg-muted/80') }}"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full {{ $page->status === 'published' ? 'bg-emerald-500' : ($page->status === 'draft' ? 'bg-amber-500' : 'bg-muted-foreground') }}"></span>
                                        <span>{{ ucfirst($page->status) }}</span>
                                    </button>
                                @endif
                            </td>

                            {{-- Views --}}
                            <td class="px-4 py-3.5 font-mono text-xs text-muted-foreground">
                                {{ number_format($page->view_count) }}
                            </td>

                            {{-- Updated At --}}
                            <td class="px-4 py-3.5 text-xs text-muted-foreground">
                                {{ $page->updated_at->diffForHumans() }}
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($showTrashed)
                                        <button
                                            type="button"
                                            wire:click="restorePage({{ $page->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Restore page') }}"
                                        >
                                            <x-icon name="rotate-ccw" class="h-3.5 w-3.5 text-emerald-500"/>
                                            <span>{{ __('Restore') }}</span>
                                        </button>

                                        @if (! $page->is_system)
                                            <button
                                                type="button"
                                                wire:click="forceDeletePage({{ $page->id }})"
                                                wire:confirm="{{ __('Are you sure you want to permanently delete this page and all translations?') }}"
                                                class="inline-flex items-center gap-1 rounded-lg border border-destructive/30 bg-destructive/10 px-2.5 py-1 text-xs font-semibold text-destructive hover:bg-destructive/20 transition-colors cursor-pointer"
                                                title="{{ __('Permanently delete') }}"
                                            >
                                                <x-icon name="trash" class="h-3.5 w-3.5"/>
                                            </button>
                                        @endif
                                    @else
                                        {{-- Live Preview Modal --}}
                                        <button
                                            type="button"
                                            wire:click="openLivePreview({{ $page->id }})"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Preview inside admin') }}"
                                        >
                                            <x-icon name="eye" class="h-4 w-4"/>
                                        </button>

                                        {{-- Edit --}}
                                        <button
                                            type="button"
                                            wire:click="editPage({{ $page->id }})"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-primary hover:bg-secondary transition-colors cursor-pointer"
                                            title="{{ __('Edit Page') }}"
                                        >
                                            <x-icon name="pencil" class="h-4 w-4"/>
                                        </button>

                                        {{-- Delete (if non-system) --}}
                                        @if (! $page->is_system)
                                            <button
                                                type="button"
                                                wire:click="deletePage({{ $page->id }})"
                                                wire:confirm="{{ __('Are you sure you want to move this page to trash?') }}"
                                                class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-border bg-card text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors cursor-pointer"
                                                title="{{ __('Move to Trash') }}"
                                            >
                                                <x-icon name="trash-2" class="h-4 w-4"/>
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                <div class="max-w-xs mx-auto space-y-2">
                                    <x-icon name="file-question" class="h-8 w-8 mx-auto text-muted-foreground/60"/>
                                    <p class="text-sm font-medium">{{ __('No pages found.') }}</p>
                                    <p class="text-xs text-muted-foreground/80">{{ __('Try modifying your search or click "Create New Page" to add one.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->pages->hasPages())
            <div class="p-4 border-t border-border">
                {{ $this->pages->links() }}
            </div>
        @endif
    </div>

    {{-- Create / Edit Page Modal --}}
    @if ($modalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
            <div class="bg-card border border-border rounded-2xl w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden ui-content-in">
                {{-- Modal Header --}}
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-muted/40 shrink-0">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <x-icon name="file-text" class="h-4 w-4"/>
                        </span>
                        <div>
                            <h2 class="text-base font-bold text-foreground">
                                {{ $editingPageId ? __('Edit Page: :title', ['title' => $translations['en']['title'] ?: $form['slug']]) : __('Create New Page') }}
                            </h2>
                            <p class="text-xs text-muted-foreground">{{ __('Manage content, slugs, meta tags, and multi-language translations.') }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        {{-- Edit vs Live Preview Mode Switcher inside Editor --}}
                        <div class="inline-flex rounded-lg border border-border bg-muted p-0.5">
                            <button
                                type="button"
                                wire:click="$set('editorTab', 'edit')"
                                class="px-2.5 py-1 text-xs font-semibold rounded-md transition-colors cursor-pointer {{ $editorTab === 'edit' ? 'bg-card text-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground' }}"
                            >
                                <span class="flex items-center gap-1">
                                    <x-icon name="pencil" class="h-3 w-3"/>
                                    <span>{{ __('Edit') }}</span>
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="$set('editorTab', 'preview')"
                                class="px-2.5 py-1 text-xs font-semibold rounded-md transition-colors cursor-pointer {{ $editorTab === 'preview' ? 'bg-card text-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground' }}"
                            >
                                <span class="flex items-center gap-1">
                                    <x-icon name="eye" class="h-3 w-3"/>
                                    <span>{{ __('Live Preview') }}</span>
                                </span>
                            </button>
                        </div>

                        <button
                            type="button"
                            wire:click="$set('modalOpen', false)"
                            class="flex h-8 w-8 items-center justify-center rounded-lg hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                        >
                            <x-icon name="x" class="h-4 w-4"/>
                        </button>
                    </div>
                </div>

                {{-- Modal Body with tabs --}}
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    @if ($editorTab === 'preview')
                        {{-- Real-time Live Preview Pane --}}
                        <div class="space-y-4">
                            {{-- Language preview picker --}}
                            <div class="flex items-center justify-between bg-muted/60 p-3 rounded-xl border border-border">
                                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Previewing Language') }}:</span>
                                <div class="flex items-center gap-1.5">
                                    @foreach ($this->availableLanguages as $lang)
                                        <button
                                            type="button"
                                            wire:click="$set('activeLocale', '{{ $lang->code }}')"
                                            class="px-2.5 py-1 rounded-md text-xs font-bold transition-all cursor-pointer {{ $activeLocale === $lang->code ? 'bg-primary text-primary-foreground shadow-2xs' : 'bg-card text-muted-foreground hover:bg-secondary' }}"
                                        >
                                            {{ $lang->name }} ({{ strtoupper($lang->code) }})
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Rendered Container Mockup --}}
                            <div class="bg-background border border-border rounded-xl p-6 sm:p-8 shadow-xs space-y-6">
                                <header class="border-b border-border pb-4">
                                    <span class="text-[10px] uppercase font-bold text-primary tracking-widest">{{ __('Page Preview') }}</span>
                                    <h1 class="text-2xl sm:text-3xl font-extrabold text-foreground mt-1">
                                        {{ $translations[$activeLocale]['title'] ?: ($translations['en']['title'] ?: __('Untitled Page')) }}
                                    </h1>
                                    @if (! empty($translations[$activeLocale]['meta_description']))
                                        <p class="text-sm text-muted-foreground mt-2">{{ $translations[$activeLocale]['meta_description'] }}</p>
                                    @endif
                                </header>

                                <div class="prose dark:prose-invert max-w-none text-foreground leading-relaxed">
                                    {!! $translations[$activeLocale]['content'] ?: ($translations['en']['content'] ?: '<p class="italic text-muted-foreground">' . __('No content entered for this language yet.') . '</p>') !!}
                                </div>
                            </div>
                        </div>
                    @else
                        {{-- Core Attributes Row --}}
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 bg-muted/40 p-4 rounded-xl border border-border">
                            {{-- Slug --}}
                            <div class="sm:col-span-1">
                                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                    {{ __('URL Slug') }} <span class="text-destructive">*</span>
                                </label>
                                <input
                                    type="text"
                                    wire:model="form.slug"
                                    placeholder="{{ __('e.g. scholarship-policy') }}"
                                    class="h-9 w-full rounded-md border border-input bg-card px-3 font-mono text-xs shadow-xs outline-none focus-visible:border-ring"
                                    {{ ($form['is_system'] ?? false) ? 'readonly' : '' }}
                                />
                                @error('form.slug') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                            </div>

                            {{-- Status --}}
                            <div class="sm:col-span-1">
                                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                    {{ __('Publication Status') }} <span class="text-destructive">*</span>
                                </label>
                                <select
                                    wire:model="form.status"
                                    class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                                >
                                    <option value="published">{{ __('Published') }}</option>
                                    <option value="draft">{{ __('Draft') }}</option>
                                    <option value="inactive">{{ __('Inactive') }}</option>
                                </select>
                            </div>

                            {{-- Sort Order --}}
                            <div class="sm:col-span-1">
                                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                    {{ __('Sort Order') }}
                                </label>
                                <input
                                    type="number"
                                    wire:model="form.sort_order"
                                    class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring"
                                />
                            </div>
                        </div>

                        {{-- Multi-Language Tabs --}}
                        <div class="space-y-4">
                            <div class="flex items-center justify-between border-b border-border pb-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ __('Translations') }}:</span>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($this->availableLanguages as $lang)
                                            <button
                                                type="button"
                                                wire:click="$set('activeLocale', '{{ $lang->code }}')"
                                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer {{ $activeLocale === $lang->code ? 'bg-primary text-primary-foreground shadow-2xs' : 'bg-muted text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
                                            >
                                                <span>{{ $lang->name }}</span>
                                                <span class="text-[10px] opacity-75 font-mono uppercase">({{ $lang->code }})</span>
                                                @if (! empty($translations[$lang->code]['title']))
                                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            {{-- Fields for Current Language Tab --}}
                            @foreach ($this->availableLanguages as $lang)
                                <div x-show="'{{ $activeLocale }}' === '{{ $lang->code }}'" class="space-y-4" wire:key="tab-{{ $lang->code }}">
                                    {{-- Title --}}
                                    <div>
                                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                            {{ __('Page Title') }} ({{ $lang->name }})@if ($lang->code === 'en') <span class="text-destructive">*</span>@endif
                                        </label>
                                        <input
                                            type="text"
                                            wire:model="translations.{{ $lang->code }}.title"
                                            wire:blur="generateSlug"
                                            placeholder="{{ __('e.g. About Satyendranath Tagore Study Centre') }}"
                                            class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        />
                                        @error("translations.{$lang->code}.title") <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                    </div>

                                    {{-- Rich Text Editor --}}
                                    <div>
                                        <x-ui.rich-text-editor
                                            :label="__('Page Content') . ' (' . $lang->name . ')'"
                                            wire:model="translations.{{ $lang->code }}.content"
                                            placeholder="{{ __('Write complete structured content, formatted lists, tables, and sections…') }}"
                                            minHeight="280px"
                                        />
                                        @error("translations.{$lang->code}.content") <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                    </div>

                                    {{-- SEO Meta Accordion / Card --}}
                                    <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                                        <h3 class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                                            <x-icon name="sparkles" class="h-3.5 w-3.5 text-primary"/>
                                            <span>{{ __('SEO & Search Engine Meta Tags') }} ({{ $lang->name }})</span>
                                        </h3>

                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="text-[10px] font-semibold uppercase text-muted-foreground block mb-1">
                                                    {{ __('SEO Meta Title') }}
                                                </label>
                                                <input
                                                    type="text"
                                                    wire:model="translations.{{ $lang->code }}.meta_title"
                                                    placeholder="{{ __('Custom title for search engines (defaults to page title)') }}"
                                                    class="h-8 w-full rounded-md border border-input bg-transparent px-2.5 text-xs shadow-xs outline-none focus-visible:border-ring"
                                                />
                                            </div>

                                            <div>
                                                <label class="text-[10px] font-semibold uppercase text-muted-foreground block mb-1">
                                                    {{ __('SEO Meta Keywords') }}
                                                </label>
                                                <input
                                                    type="text"
                                                    wire:model="translations.{{ $lang->code }}.meta_keywords"
                                                    placeholder="{{ __('comma, separated, keywords') }}"
                                                    class="h-8 w-full rounded-md border border-input bg-transparent px-2.5 text-xs shadow-xs outline-none focus-visible:border-ring"
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label class="text-[10px] font-semibold uppercase text-muted-foreground block mb-1">
                                                {{ __('SEO Meta Description') }}
                                            </label>
                                            <textarea
                                                wire:model="translations.{{ $lang->code }}.meta_description"
                                                rows="2"
                                                placeholder="{{ __('150-160 characters summary for Google / Bing search result snippets…') }}"
                                                class="w-full rounded-md border border-input bg-transparent px-2.5 py-1.5 text-xs shadow-xs outline-none focus-visible:border-ring"
                                            ></textarea>
                                        </div>

                                        {{-- Google SERP Snippet Preview --}}
                                        <div class="mt-3 bg-muted/40 rounded-lg p-3 border border-border text-xs space-y-1">
                                            <p class="text-[10px] uppercase font-bold text-muted-foreground tracking-wider mb-1">{{ __('Search Result SERP Snippet Preview') }}:</p>
                                            <p class="text-blue-600 dark:text-blue-400 font-semibold text-sm truncate hover:underline cursor-pointer">
                                                {{ $translations[$lang->code]['meta_title'] ?: ($translations[$lang->code]['title'] ?: __('Page Title')) }} — {{ \App\Models\Setting::siteName() }}
                                            </p>
                                            <p class="text-emerald-700 dark:text-emerald-500 text-[11px] font-mono truncate">
                                                {{ url('/pages/' . ($form['slug'] ?: 'page-slug')) }}
                                            </p>
                                            <p class="text-muted-foreground text-xs line-clamp-2">
                                                {{ $translations[$lang->code]['meta_description'] ?: __('Brief description of the page for search engine results and social card shares.') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Modal Footer --}}
                <div class="flex items-center justify-between px-6 py-4 border-t border-border bg-muted/40 shrink-0">
                    <button
                        type="button"
                        wire:click="$set('modalOpen', false)"
                        class="rounded-xl border border-border bg-card px-4 py-2 text-xs font-semibold text-foreground hover:bg-secondary transition-colors cursor-pointer"
                    >
                        {{ __('Cancel') }}
                    </button>

                    <button
                        type="button"
                        wire:click="save"
                        class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-2 text-xs font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                    >
                        <x-icon name="save" class="h-3.5 w-3.5"/>
                        <span>{{ $editingPageId ? __('Update Page') : __('Create Page') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Standalone Live Preview Modal --}}
    @if ($previewModalOpen && $previewPage)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-3 sm:p-6 overflow-y-auto">
            <div class="bg-card border border-border rounded-2xl w-full max-w-3xl max-h-[85vh] flex flex-col shadow-2xl overflow-hidden ui-content-in">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-muted/40 shrink-0">
                    <div class="flex items-center gap-2">
                        <x-icon name="eye" class="h-4 w-4 text-primary"/>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Page Preview: :slug', ['slug' => $previewPage->slug]) }}</h3>
                    </div>
                    <button
                        type="button"
                        wire:click="$set('previewModalOpen', false)"
                        class="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-secondary text-muted-foreground hover:text-foreground cursor-pointer"
                    >
                        <x-icon name="x" class="h-4 w-4"/>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-6 sm:p-8 space-y-6">
                    <header class="border-b border-border pb-4">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] uppercase font-bold text-primary tracking-widest">{{ __('Verified Institutional Page') }}</span>
                            <span class="text-xs text-muted-foreground">{{ $previewPage->reading_time }} {{ __('min read') }}</span>
                        </div>
                        <h1 class="text-2xl sm:text-3xl font-extrabold text-foreground mt-2">
                            {{ $previewPage->title }}
                        </h1>
                        @if ($previewPage->meta_description)
                            <p class="text-sm text-muted-foreground mt-2">{{ $previewPage->meta_description }}</p>
                        @endif
                    </header>

                    <div class="prose dark:prose-invert max-w-none text-foreground leading-relaxed">
                        {!! $previewPage->content !!}
                    </div>
                </div>

                <div class="flex items-center justify-end px-6 py-3 border-t border-border bg-muted/30">
                    <a
                        href="{{ route('public.page', $previewPage->slug) }}"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline cursor-pointer"
                    >
                        <span>{{ __('Open Public Page in New Tab') }}</span>
                        <x-icon name="external-link" class="h-3 w-3"/>
                    </a>
                </div>
            </div>
        </div>
    @endif
</div>
