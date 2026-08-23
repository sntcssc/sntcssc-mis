@props([
    'color' => 'secondary',
])

@php
    $colorMap = [
        'primary' => 'bg-primary/15 text-primary border-0',
        'success' => 'bg-emerald-500/15 text-emerald-500 border-0',
        'emerald' => 'bg-emerald-500/15 text-emerald-500 border-0',
        'warning' => 'bg-amber-500/15 text-amber-500 border-0',
        'amber' => 'bg-amber-500/15 text-amber-500 border-0',
        'danger' => 'bg-rose-500/15 text-rose-500 border-0',
        'destructive' => 'bg-rose-500/15 text-rose-500 border-0',
        'rose' => 'bg-rose-500/15 text-rose-500 border-0',
        'info' => 'bg-cyan-500/15 text-cyan-500 border-0',
        'cyan' => 'bg-cyan-500/15 text-cyan-500 border-0',
        'blue' => 'bg-blue-500/15 text-blue-500 border-0',
        'violet' => 'bg-violet-500/15 text-violet-500 border-0',
        'purple' => 'bg-purple-500/15 text-purple-500 border-0',
        'secondary' => 'bg-secondary text-muted-foreground border-0',
        'outline' => 'text-muted-foreground',
    ];
    $colorClass = $colorMap[$color] ?? $colorMap['secondary'];
@endphp

<span
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium '.$colorClass]) }}
>{{ $slot }}</span>
