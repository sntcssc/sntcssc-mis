@props([
    'icon' => null,
    'danger' => false,
])

@php
    $classes = 'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none transition-colors cursor-pointer select-none '
        .($danger ? 'text-destructive hover:bg-destructive/10 [&_svg]:text-destructive' : 'text-popover-foreground hover:bg-secondary [&_svg]:text-muted-foreground');
@endphp

@if ($attributes->has('href'))
    <a {{ $attributes->except([])->merge(['class' => $classes]) }} wire:navigate>
        @if ($icon)<x-icon :name="$icon" class="h-4 w-4 shrink-0"/>@endif
        <span class="flex-1 text-start truncate">{{ $slot }}</span>
        {{ $trailing ?? '' }}
    </a>
@else
    <button {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="h-4 w-4 shrink-0"/>@endif
        <span class="flex-1 text-start truncate">{{ $slot }}</span>
        {{ $trailing ?? '' }}
    </button>
@endif
