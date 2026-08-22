@props([
    'color' => 'secondary',
])

@php
    $colors = [
        'primary' => 'bg-primary/15 text-primary border-0',
        'success' => 'bg-emerald-500/15 text-emerald-500 border-0',
        'warning' => 'bg-amber-500/15 text-amber-500 border-0',
        'danger' => 'bg-rose-500/15 text-rose-500 border-0',
        'info' => 'bg-cyan-500/15 text-cyan-500 border-0',
        'violet' => 'bg-violet-500/15 text-violet-500 border-0',
        'secondary' => 'bg-secondary text-muted-foreground border-0',
        'outline' => 'text-muted-foreground',
    ];
@endphp

<span
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium '.$colors[$color]]) }}
>{{ $slot }}</span>
