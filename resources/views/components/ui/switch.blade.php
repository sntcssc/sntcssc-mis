@props([
    'checked' => false,
    'label' => null,
    'description' => null,
])

<label class="flex items-start gap-3 cursor-pointer select-none" {{ $attributes->only(['data-test']) }}>
    <span class="relative inline-flex">
        <input
            type="checkbox"
            {{ $attributes->except(['data-test'])->merge(['class' => 'peer sr-only']) }}
            @checked($checked)
        />
        <span
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-border bg-secondary transition-colors outline-none
                   peer-checked:border-primary peer-checked:bg-primary
                   peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50"
            aria-hidden="true"
        >
            <span class="block h-4 w-4 translate-x-0.5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-[18px]"></span>
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
