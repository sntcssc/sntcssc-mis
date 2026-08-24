@props([
    'label' => null,
    'error' => null,
    'hint' => null,
    'size' => 'default',
    'mono' => true,
])

@php
    $sizes = [
        'default' => 'h-9',
        'sm' => 'h-8',
        'lg' => 'h-11',
    ];

    $inputClasses = trim(($sizes[$size] ?? $sizes['default'])
        .' flex w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 shadow-xs transition-[color,box-shadow] outline-none'
        .' placeholder:text-muted-foreground'
        .' focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50'
        .' disabled:cursor-not-allowed disabled:opacity-50'
        .($mono ? ' font-mono' : '')
        .($error ? ' border-destructive focus-visible:border-destructive focus-visible:ring-destructive/30' : ''));
@endphp

<div {{ $attributes->only(['class', 'data-test']) }} x-data="{ visible: false }">
    @if ($label)
        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {{ $label }}@if ($attributes->get('required')) <span class="text-destructive">*</span>@endif
        </label>
    @endif

    <div class="relative {{ $label ? 'mt-2' : '' }}">
        <input
            {{ $attributes->except(['class', 'data-test', 'type'])->merge(['class' => $inputClasses, 'type' => 'password']) }}
            x-bind:type="visible ? 'text' : 'password'"
        />
        <button
            type="button"
            x-on:click="visible = ! visible"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
            :aria-label="visible ? @js(__('Hide password')) : @js(__('Show password'))"
        >
            <x-icon name="eye" class="h-4 w-4" x-show="! visible" x-cloak/>
            <x-icon name="eye-off" class="h-4 w-4" x-show="visible"/>
        </button>
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
