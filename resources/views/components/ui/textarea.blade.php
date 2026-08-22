@props([
    'label' => null,
    'error' => null,
    'hint' => null,
])

<div {{ $attributes->only(['class', 'data-test']) }}>
    @if ($label)
        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {{ $label }}@if ($attributes->get('required')) <span class="text-destructive">*</span>@endif
        </label>
    @endif

    <textarea
        {{ $attributes->except(['class', 'data-test'])->merge(['class' => 'mt-2 flex min-h-16 w-full rounded-md border border-input bg-transparent px-3 py-2 shadow-xs placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 outline-none disabled:cursor-not-allowed disabled:opacity-50']) }}
    >{{ $slot }}</textarea>

    @if ($error)
        <p class="mt-1.5 text-xs text-destructive">{{ $error }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
