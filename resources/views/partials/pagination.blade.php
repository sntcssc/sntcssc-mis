@if ($paginator->total() > 0)
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-border px-4 py-3">
        <div class="flex items-center gap-2 text-xs text-muted-foreground">
            <span>{{ __('Rows per page') }}</span>
            <select
                wire:model.live="perPage"
                class="h-8 rounded-md border border-input bg-transparent px-2 text-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                @foreach ([6, 10, 20, 50, 100] as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <span class="hidden sm:inline">
                {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} {{ __('of') }} {{ $paginator->total() }}
            </span>
        </div>

        <div class="flex items-center gap-2">
            @if ($paginator->onFirstPage())
                <button type="button" disabled class="flex h-8 w-8 items-center justify-center rounded-md border border-border opacity-50 cursor-not-allowed" aria-label="{{ __('Previous page') }}">
                    <x-icon name="chevron-left" class="h-4 w-4"/>
                </button>
            @else
                <button
                    type="button"
                    wire:click="previousPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-secondary transition-colors cursor-pointer"
                    aria-label="{{ __('Previous page') }}"
                >
                    <x-icon name="chevron-left" class="h-4 w-4"/>
                </button>
            @endif

            <span class="rounded-md bg-secondary/50 px-2.5 py-1 text-xs font-medium tabular-nums">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <button
                    type="button"
                    wire:click="nextPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-secondary transition-colors cursor-pointer"
                    aria-label="{{ __('Next page') }}"
                >
                    <x-icon name="chevron-right" class="h-4 w-4"/>
                </button>
            @else
                <button type="button" disabled class="flex h-8 w-8 items-center justify-center rounded-md border border-border opacity-50 cursor-not-allowed" aria-label="{{ __('Next page') }}">
                    <x-icon name="chevron-right" class="h-4 w-4"/>
                </button>
            @endif
        </div>
    </div>
@endif
