{{--
    Floating sun/moon toggle used on guest (auth) pages.
    Uses a plain onclick handler — no Alpine required — so it works on any page
    even before/without Livewire's Alpine bundle. Icon states are pure CSS.
--}}
<button
    type="button"
    onclick="window.SntcsscTheme && window.SntcsscTheme.toggle()"
    class="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-card hover:bg-secondary transition-colors cursor-pointer"
    aria-label="{{ __('Toggle theme') }}"
    title="{{ __('Toggle theme') }}"
    {{ $attributes }}
>
    <x-icon name="moon" class="h-4 w-4 text-muted-foreground dark:hidden"/>
    <x-icon name="sun" class="hidden h-4 w-4 text-muted-foreground dark:block"/>
</button>
