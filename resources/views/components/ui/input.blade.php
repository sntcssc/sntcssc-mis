@props([
    'label' => null,
    'error' => null,
    'hint' => null,
    'size' => 'default',
    'mono' => false,
    'icon' => null,
])

@php
    $sizes = [
        'default' => 'h-9 px-3 py-1',
        'sm' => 'h-8 px-3',
        'lg' => 'h-11 px-4 text-base',
    ];

    $inputClasses = trim(($sizes[$size] ?? $sizes['default'])
        .' flex w-full min-w-0 rounded-md border border-input bg-transparent shadow-xs transition-[color,box-shadow] outline-none'
        .' placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground'
        .' focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50'
        .' disabled:cursor-not-allowed disabled:opacity-50'
        .($icon ? ' pl-9' : '')
        .($mono ? ' font-mono' : '')
        .($error ? ' border-destructive focus-visible:border-destructive focus-visible:ring-destructive/30' : ''));
@endphp

<div {{ $attributes->only(['class', 'data-test']) }}>
    @if ($label)
        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {{ $label }}@if ($attributes->get('required')) <span class="text-destructive">*</span>@endif
        </label>
    @endif

    <div class="relative {{ $label ? 'mt-2' : '' }}">
        @if ($icon)
            <x-icon :name="$icon" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"/>
        @endif
        <input {{ $attributes->except(['icon', 'class', 'data-test'])->merge(['class' => $inputClasses]) }}/>
    </div>

    @if ($error)
        <p class="mt-1.5 text-xs text-destructive flex items-center gap-1 font-medium">
            <x-icon name="alert-circle" class="h-3.5 w-3.5 shrink-0"/>
            <span>{{ $error }}</span>
        </p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
