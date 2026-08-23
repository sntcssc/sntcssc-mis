@props([
    'icon' => 'activity',
    'color' => 'primary',
    'label' => null,
    'value' => null,
    'hint' => null,
])

@php
    $colors = [
        'primary' => 'bg-primary/15 text-primary',
        'emerald' => 'bg-emerald-500/15 text-emerald-500',
        'amber' => 'bg-amber-500/15 text-amber-500',
        'rose' => 'bg-rose-500/15 text-rose-500',
        'cyan' => 'bg-cyan-500/15 text-cyan-500',
        'violet' => 'bg-violet-500/15 text-violet-500',
        'secondary' => 'bg-secondary text-muted-foreground',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-border bg-card p-4']) }}>
    <div class="flex items-center gap-3">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $colors[$color] ?? $colors['emerald'] }}">
            <x-icon :name="$icon" class="h-4 w-4"/>
        </span>
        <div class="min-w-0 flex-1">
            @if ($label)
                <p class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ $label }}</p>
            @endif
            <p class="mt-0.5 text-xl font-bold tracking-tight">{{ $value }}</p>
        </div>
        @isset($trend)
            <span class="text-xs font-medium text-muted-foreground">{{ $trend }}</span>
        @endisset
    </div>
    @if ($hint)
        <p class="mt-2 text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
    {{ $slot }}
</div>
