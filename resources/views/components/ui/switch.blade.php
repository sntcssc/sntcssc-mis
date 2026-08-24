@props([
    'checked' => false,
    'label' => null,
    'description' => null,
])

<label class="flex items-start gap-3 cursor-pointer select-none" {{ $attributes->only(['data-test']) }}>
    <span class="relative inline-flex items-center">
        <input
            type="checkbox"
            {{ $attributes->except(['data-test'])->merge(['class' => 'peer sr-only']) }}
            @checked($checked)
        />
        <span
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-border bg-secondary transition-colors duration-200 outline-none
                   peer-checked:border-primary peer-checked:bg-primary peer-checked:[&>span]:translate-x-[16px]
                   peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50"
            aria-hidden="true"
        >
            <span class="pointer-events-none block h-4 w-4 translate-x-0.5 rounded-full bg-white shadow-xs transition-transform duration-200 ease-in-out"></span>
        </span>
    </span>

    @if ($label)
        <span class="grid gap-0.5">
            <span class="text-sm font-medium leading-none">{{ $label }}</span>
            @if ($description)
                <span class="text-xs text-muted-foreground">{{ $description }}</span>
            @endif
        </span>
    @endif
</label>
