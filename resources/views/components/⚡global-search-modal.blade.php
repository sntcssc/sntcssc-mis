<?php

use App\Services\GlobalSearchService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $search = '';
    public string $category = 'all';

    #[Computed]
    public function searchPayload(): array
    {
        return GlobalSearchService::search($this->search, Auth::user(), 30);
    }

    #[Computed]
    public function results(): array
    {
        $payload = $this->searchPayload;
        $items = $payload['items'] ?? [];

        if ($this->category !== 'all') {
            $items = array_values(array_filter($items, function ($item) {
                return ($item['category'] ?? '') === $this->category;
            }));
        }

        return $items;
    }

    #[Computed]
    public function categories(): array
    {
        $payload = $this->searchPayload;
        return $payload['categories'] ?? [];
    }

    public function selectCategory(string $category): void
    {
        $this->category = $category;
    }

    public function clear(): void
    {
        $this->reset(['search', 'category']);
    }
}; ?>

<div
    x-data="{
        isOpen: false,
        selectedIndex: 0,
        recentSearches: [],

        init() {
            try {
                this.recentSearches = JSON.parse(localStorage.getItem('admin_recent_searches') || '[]');
            } catch (e) {
                this.recentSearches = [];
            }

            // Global Keyboard Shortcut: Ctrl+K or Cmd+K
            window.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    this.toggle();
                } else if (e.key === 'Escape' && this.isOpen) {
                    this.close();
                }
            });

            // Listen for open-global-search window event
            window.addEventListener('open-global-search', () => {
                this.open();
            });
        },

        open() {
            this.isOpen = true;
            this.selectedIndex = 0;
            this.$nextTick(() => {
                const input = this.$refs.searchInput;
                if (input) {
                    input.focus();
                    input.select();
                }
            });
        },

        close() {
            this.isOpen = false;
        },

        toggle() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        },

        navigateDown(total) {
            if (total <= 0) return;
            this.selectedIndex = (this.selectedIndex + 1) % total;
            this.scrollToSelected();
        },

        navigateUp(total) {
            if (total <= 0) return;
            this.selectedIndex = (this.selectedIndex - 1 + total) % total;
            this.scrollToSelected();
        },

        scrollToSelected() {
            this.$nextTick(() => {
                const el = this.$refs.resultsContainer?.querySelector(`[data-index='${this.selectedIndex}']`);
                if (el) {
                    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            });
        },

        selectByIndex(index) {
            const el = this.$refs.resultsContainer?.querySelector(`[data-index='${index}']`);
            if (el) {
                el.click();
            }
        },

        selectItem(item) {
            if (!item) return;

            // Save to recent searches if has url
            if (item.url && item.title) {
                this.addRecentSearch(item);
            }

            // Check if special JavaScript action
            if (item.action === 'toggle-fullscreen') {
                this.close();
                window.dispatchEvent(new CustomEvent('toggle-admin-fullscreen'));
                return;
            }

            if (item.action === 'cycle-theme') {
                this.close();
                if (window.Alpine && window.Alpine.store('theme')) {
                    const current = window.Alpine.store('theme').current;
                    const next = current === 'dark' ? 'light' : 'dark';
                    window.Alpine.store('theme').set(next);
                }
                return;
            }

            if (item.url) {
                this.close();
                if (window.Livewire && window.Livewire.navigate) {
                    window.Livewire.navigate(item.url);
                } else {
                    window.location.href = item.url;
                }
            }
        },

        addRecentSearch(item) {
            let list = this.recentSearches.filter(i => i.id !== item.id);
            list.unshift({
                id: item.id,
                title: item.title,
                url: item.url,
                category: item.category,
                icon: item.icon || 'search'
            });
            this.recentSearches = list.slice(0, 5);
            try {
                localStorage.setItem('admin_recent_searches', JSON.stringify(this.recentSearches));
            } catch (e) {}
        },

        clearRecentSearches() {
            this.recentSearches = [];
            try {
                localStorage.removeItem('admin_recent_searches');
            } catch (e) {}
        }
    }"
    x-show="isOpen"
    x-cloak
    class="fixed inset-0 z-50 overflow-y-auto p-3 sm:p-6 md:p-10 flex items-start justify-center"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Global Search & Command Palette') }}"
>
    {{-- Translucent Backdrop --}}
    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-background/80 backdrop-blur-md transition-opacity"
        x-on:click="close()"
        aria-hidden="true"
    ></div>

    {{-- Command Palette Container --}}
    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95 -translate-y-4"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 -translate-y-4"
        class="relative w-full max-w-2xl bg-card border border-border rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh] sm:max-h-[80vh] my-auto sm:my-8 text-foreground"
        x-on:keydown.down.prevent="navigateDown({{ count($this->results) }})"
        x-on:keydown.up.prevent="navigateUp({{ count($this->results) }})"
        x-on:keydown.enter.prevent="selectByIndex(selectedIndex)"
    >
        {{-- Search Input Header --}}
        <div class="relative flex items-center border-b border-border px-4 py-3 sm:py-3.5 bg-card shrink-0">
            <x-icon name="search" class="h-5 w-5 text-muted-foreground mr-3 shrink-0"/>

            <input
                x-ref="searchInput"
                type="text"
                wire:model.live.debounce.250ms="search"
                x-on:input="selectedIndex = 0"
                placeholder="{{ __('Search modules, actions, pages, records (Ctrl+K)...') }}"
                class="w-full bg-transparent border-0 p-0 text-sm sm:text-base font-normal text-foreground placeholder-muted-foreground focus:outline-none focus:ring-0"
                autocomplete="off"
                spellcheck="false"
            />

            {{-- Livewire Loading Spinner --}}
            <div wire:loading wire:target="search" class="shrink-0 mr-2 text-primary">
                <x-icon name="rotate-cw" class="h-4 w-4 animate-spin"/>
            </div>

            {{-- Clear Search Button --}}
            @if (! empty($search))
                <button
                    type="button"
                    wire:click="clear"
                    x-on:click="selectedIndex = 0; $refs.searchInput.focus()"
                    class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors mr-2 cursor-pointer"
                    title="{{ __('Clear search') }}"
                >
                    <x-icon name="x" class="h-4 w-4"/>
                </button>
            @endif

            {{-- ESC Shortcut Badge / Close Button --}}
            <button
                type="button"
                x-on:click="close()"
                class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-border bg-secondary/60 text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors text-xs font-mono cursor-pointer"
                title="{{ __('Close dialog (Esc)') }}"
            >
                <span class="text-[10px] uppercase font-bold">ESC</span>
            </button>
        </div>

        {{-- Category Filter Pills --}}
        @if (count($this->categories) > 1)
            <div class="flex items-center gap-1.5 px-4 py-2 border-b border-border/60 bg-muted/30 overflow-x-auto scrollbar-none text-xs shrink-0">
                <button
                    type="button"
                    wire:click="selectCategory('all')"
                    x-on:click="selectedIndex = 0"
                    class="px-2.5 py-1 rounded-lg font-medium transition-colors whitespace-nowrap cursor-pointer {{ $category === 'all' ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-secondary/60 text-muted-foreground hover:text-foreground hover:bg-secondary' }}"
                >
                    {{ __('All Categories') }}
                </button>
                @foreach ($this->categories as $cat)
                    <button
                        type="button"
                        wire:click="selectCategory('{{ $cat }}')"
                        x-on:click="selectedIndex = 0"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors whitespace-nowrap cursor-pointer {{ $category === $cat ? 'bg-primary text-primary-foreground shadow-xs' : 'bg-secondary/60 text-muted-foreground hover:text-foreground hover:bg-secondary' }}"
                    >
                        {{ __($cat) }}
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Results Scroll Area --}}
        <div x-ref="resultsContainer" class="flex-1 overflow-y-auto p-2 sm:p-3 space-y-1">
            {{-- Recent Searches (Shown when query is empty and user has recent searches) --}}
            <template x-if="recentSearches.length > 0 && (!$wire.search || $wire.search.trim() === '')">
                <div class="mb-3 px-2">
                    <div class="flex items-center justify-between pb-1.5 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                        <span class="flex items-center gap-1.5">
                            <x-icon name="history" class="h-3 w-3"/>
                            {{ __('Recent Searches') }}
                        </span>
                        <button
                            type="button"
                            x-on:click="clearRecentSearches()"
                            class="text-[10px] text-muted-foreground hover:text-destructive transition-colors cursor-pointer"
                        >
                            {{ __('Clear') }}
                        </button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        <template x-for="item in recentSearches" :key="item.id">
                            <button
                                type="button"
                                x-on:click="selectItem(item)"
                                class="flex items-center gap-2.5 p-2 rounded-xl border border-border/50 bg-secondary/30 hover:bg-secondary text-left transition-colors cursor-pointer group"
                            >
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-card text-muted-foreground group-hover:text-primary">
                                    <x-icon name="clock" class="h-3.5 w-3.5"/>
                                </span>
                                <span class="text-xs font-medium text-foreground truncate" x-text="item.title"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

            @php $items = $this->results; @endphp

            @if (count($items) > 0)
                @foreach ($items as $index => $item)
                    <button
                        type="button"
                        data-index="{{ $index }}"
                        x-on:click="selectItem({{ json_encode($item) }})"
                        x-on:mouseenter="selectedIndex = {{ $index }}"
                        class="w-full flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-left transition-all cursor-pointer border"
                        :class="selectedIndex === {{ $index }} ? 'bg-primary/10 border-primary/40 text-foreground ring-1 ring-primary/20 shadow-xs' : 'border-transparent hover:bg-secondary/60 text-foreground'"
                    >
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="flex h-8 w-8 sm:h-9 sm:w-9 shrink-0 items-center justify-center rounded-xl {{ ($item['type'] ?? '') === 'action' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-primary/10 text-primary' }}">
                                <x-icon :name="$item['icon'] ?? 'search'" class="h-4 w-4"/>
                            </div>

                            <div class="min-w-0 flex flex-col">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-xs sm:text-sm font-semibold truncate">{{ $item['title'] }}</span>

                                    @if (! empty($item['badge']))
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-semibold tracking-wide uppercase {{ ($item['type'] ?? '') === 'action' ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : 'bg-secondary text-muted-foreground' }}">
                                            {{ __($item['badge']) }}
                                        </span>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2 text-[11px] text-muted-foreground truncate mt-0.5">
                                    <span class="font-medium text-foreground/70">{{ __($item['category'] ?? 'Module') }}</span>
                                    @if (! empty($item['subtitle']))
                                        <span>•</span>
                                        <span class="truncate">{{ $item['subtitle'] }}</span>
                                    @elseif (! empty($item['description']))
                                        <span>•</span>
                                        <span class="truncate">{{ $item['description'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="shrink-0 flex items-center gap-1.5 text-muted-foreground">
                            <span class="hidden sm:inline-flex items-center gap-1 px-1.5 py-0.5 rounded border border-border bg-card text-[10px] font-mono" :class="selectedIndex === {{ $index }} ? 'opacity-100 text-primary border-primary/40' : 'opacity-0 group-hover:opacity-100'">
                                <span>{{ __('Open') }}</span>
                                <x-icon name="corner-down-left" class="h-2.5 w-2.5"/>
                            </span>
                            <x-icon name="arrow-right" class="h-3.5 w-3.5 sm:hidden"/>
                        </div>
                    </button>
                @endforeach
            @else
                {{-- Empty Search Results State --}}
                <div class="py-12 px-4 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-muted/60 text-muted-foreground mb-3">
                        <x-icon name="search" class="h-6 w-6"/>
                    </div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('No results found') }}</h3>
                    <p class="text-xs text-muted-foreground max-w-sm mx-auto mt-1">
                        {{ __('No matching administrative modules, actions, or authorized data records found for ":query".', ['query' => $search]) }}
                    </p>
                    <p class="text-[11px] text-muted-foreground/80 mt-2">
                        {{ __('Tip: Check for spelling, or try searching for broader keywords like "students", "fees", "books", or "attendance".') }}
                    </p>
                </div>
            @endif
        </div>

        {{-- Command Palette Footer --}}
        <div class="flex items-center justify-between border-t border-border px-4 py-2.5 bg-muted/40 text-[11px] text-muted-foreground shrink-0">
            <div class="flex items-center gap-3">
                <span class="hidden sm:inline-flex items-center gap-1">
                    <kbd class="px-1.5 py-0.5 rounded border border-border bg-card font-mono text-[10px]">↑</kbd>
                    <kbd class="px-1.5 py-0.5 rounded border border-border bg-card font-mono text-[10px]">↓</kbd>
                    <span>{{ __('Navigate') }}</span>
                </span>
                <span class="hidden sm:inline-flex items-center gap-1">
                    <kbd class="px-1.5 py-0.5 rounded border border-border bg-card font-mono text-[10px]">↵</kbd>
                    <span>{{ __('Select') }}</span>
                </span>
                <span class="inline-flex items-center gap-1">
                    <kbd class="px-1.5 py-0.5 rounded border border-border bg-card font-mono text-[10px]">ESC</kbd>
                    <span>{{ __('Close') }}</span>
                </span>
            </div>

            <div class="flex items-center gap-1.5 text-foreground/80 font-medium">
                <x-icon name="shield-check" class="h-3.5 w-3.5 text-emerald-500"/>
                <span>{{ __('Access-Controlled Results') }}</span>
            </div>
        </div>
    </div>
</div>
