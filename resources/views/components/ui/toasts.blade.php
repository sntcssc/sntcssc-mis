@php
    $flash = collect(session('toasts', []));
@endphp

<div
    x-data
    x-init="$store.toasts.bootstrap(@js($flash))"
    class="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-end gap-2 p-4 sm:p-6"
    aria-live="polite"
>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-border bg-popover p-4 text-popover-foreground shadow-lg ui-toast-in"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
        >
            <span
                class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                :class="toast.type === 'danger' || toast.type === 'error' ? 'bg-rose-500/15 text-rose-500' : (toast.type === 'warning' ? 'bg-amber-500/15 text-amber-500' : (toast.type === 'info' ? 'bg-cyan-500/15 text-cyan-500' : 'bg-primary/15 text-primary'))"
            >
                <x-icon name="check-circle-2" class="h-3.5 w-3.5" x-show="toast.type === 'success' || ! toast.type"/>
                <x-icon name="alert-circle" class="h-3.5 w-3.5" x-show="toast.type === 'danger' || toast.type === 'error'"/>
                <x-icon name="alert-triangle" class="h-3.5 w-3.5" x-show="toast.type === 'warning'"/>
                <x-icon name="info" class="h-3.5 w-3.5" x-show="toast.type === 'info'"/>
            </span>

            <div class="grid gap-1">
                <p class="text-sm font-medium" x-text="toast.message"></p>
                <p class="text-xs text-muted-foreground" x-text="toast.type === 'danger' || toast.type === 'error' ? @js(__('Something went wrong')) : @js(__('Just now'))"></p>
            </div>

            <button
                type="button"
                class="ml-auto rounded-md p-1 text-muted-foreground transition-colors hover:text-foreground cursor-pointer"
                x-on:click="$store.toasts.dismiss(toast.id)"
                aria-label="{{ __('Dismiss') }}"
            >
                <x-icon name="x" class="h-3.5 w-3.5"/>
            </button>
        </div>
    </template>
</div>
