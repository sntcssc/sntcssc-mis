@props([
    'label' => null,
])

<label class="relative inline-flex items-center gap-2 cursor-pointer select-none {{ $attributes->get('class') }}" {{ $attributes->only(['data-test']) }}>
    <input
        type="checkbox"
        {{ $attributes->except(['class', 'data-test'])->merge(['class' => 'peer size-4 shrink-0 appearance-none rounded-[4px] border border-input dark:bg-input/30 shadow-xs transition-shadow outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 checked:bg-primary checked:border-primary disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer']) }}
    />
    <x-icon name="check" class="pointer-events-none absolute left-[2px] -translate-y-1/2 top-1/2 h-3 w-3 text-primary-foreground opacity-0 transition-opacity peer-checked:opacity-100" stroke-width="3"/>
    @if ($label)
        <span class="text-sm text-muted-foreground leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
            {{ $label }}
        </span>
    @endif
</label>
