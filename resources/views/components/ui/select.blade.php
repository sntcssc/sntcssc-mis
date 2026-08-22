@props([
    'label' => null,
    'options' => [],
    'placeholder' => null,
    'size' => 'default',
])

@php
    $sizes = [
        'default' => 'h-9',
        'sm' => 'h-8',
        'lg' => 'h-11',
    ];

    $selectClasses = trim(($sizes[$size] ?? $sizes['default'])
        .' w-full appearance-none items-center justify-between rounded-md border border-input bg-transparent px-3 py-1 pr-8 shadow-xs shadow-black/[.03] outline-none transition-[color,box-shadow]'
        .' focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer'
        .' [&>option]:bg-popover [&>option]:text-popover-foreground');
@endphp

<div {{ $attributes->only(['class', 'data-test']) }}>
    @if ($label)
        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {{ $label }}@if ($attributes->get('required')) <span class="text-destructive">*</span>@endif
        </label>
    @endif

    <div class="relative {{ $label ? 'mt-2' : '' }}">
        <select
            {{ $attributes->except(['class', 'data-test'])->merge(['class' => $selectClasses]) }}
        >
            @if ($placeholder)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach ($options as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
            {!! $slot !!}
        </select>
        <x-icon name="chevron-down" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground"/>
    </div>
</div>
