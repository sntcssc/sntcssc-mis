@props([
    'name' => 'modal',
    'maxWidth' => 'max-w-lg',
    'title' => null,
    'description' => null,
])

<div
    x-data
    x-show="$store.modals.isOpen('{{ $name }}')"
    x-cloak
    class="fixed inset-0 z-50"
    x-on:keydown.escape.window="$store.modals.close('{{ $name }}')"
>
    <div
        class="absolute inset-0 bg-black/50 ui-overlay-in"
        x-on:click="$store.modals.close('{{ $name }}')"
        aria-hidden="true"
    ></div>

    <div
        role="dialog"
        aria-modal="true"
        @if ($title) aria-label="{{ $title }}" @endif
        class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 flex w-[calc(100%-2rem)] {{ $maxWidth }} max-h-[calc(100vh-4rem)] flex-col overflow-hidden rounded-lg border border-border bg-card shadow-lg ui-content-in"
    >
        <button
            type="button"
            class="absolute right-4 top-4 z-10 flex h-7 w-7 items-center justify-center rounded-md bg-card text-muted-foreground shadow-xs transition-colors hover:bg-secondary hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 outline-none cursor-pointer"
            x-on:click="$store.modals.close('{{ $name }}')"
            aria-label="{{ __('Close') }}"
        >
            <x-icon name="x" class="h-4 w-4"/>
        </button>

        <div class="grid gap-4 overflow-y-auto p-6">
            @if ($title || $description)
                <div class="flex flex-col gap-1.5 pr-8">
                    @if ($title)
                        <h2 class="text-lg font-semibold leading-none tracking-tight">{{ $title }}</h2>
                    @endif
                    @if ($description)
                        <p class="text-sm text-muted-foreground">{{ $description }}</p>
                    @endif
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</div>
