{{--
    Compact 3-way theme control (light / dark / system) for the admin topbar.
    Kept in sync with the floating guest toggle and the appearance settings
    page through the shared Alpine `theme` store.
--}}
<div
    class="flex items-center rounded-lg border border-border bg-background shadow-xs p-0.5 {{ $attributes->get('class') }}"
    x-data
    role="group"
    aria-label="{{ __('Appearance') }}"
    {{ $attributes->except('class') }}
>
    <button
        type="button"
        x-on:click="$store.theme.set('light')"
        :title="@js(__('Light'))"
        :aria-label="@js(__('Light'))"
        class="flex h-7 w-7 items-center justify-center rounded-md transition-colors cursor-pointer outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        :class="$store.theme.current === 'light' ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground'"
    >
        <x-icon name="sun" class="h-3.5 w-3.5"/>
    </button>

    <button
        type="button"
        x-on:click="$store.theme.set('dark')"
        :title="@js(__('Dark'))"
        :aria-label="@js(__('Dark'))"
        class="flex h-7 w-7 items-center justify-center rounded-md transition-colors cursor-pointer outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        :class="$store.theme.current === 'dark' ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground'"
    >
        <x-icon name="moon" class="h-3.5 w-3.5"/>
    </button>

    <button
        type="button"
        x-on:click="$store.theme.set('system')"
        :title="@js(__('Auto (system)'))"
        :aria-label="@js(__('Auto (system)'))"
        class="flex h-7 w-7 items-center justify-center rounded-md transition-colors cursor-pointer outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        :class="$store.theme.current === 'system' ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground'"
    >
        <x-icon name="monitor" class="h-3.5 w-3.5"/>
    </button>
</div>
