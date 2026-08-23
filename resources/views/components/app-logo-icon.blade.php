@props([
    'size' => 'h-8 w-8',
    'iconSize' => 'h-4 w-4',
    'badgeClass' => 'flex items-center justify-center rounded-lg bg-primary/15 text-primary shrink-0',
    'alt' => null,
])

@php
    $faviconUrl = \App\Models\Setting::faviconUrl(fallback: false);
    $appName = \App\Models\Setting::appName();
    $altText = $alt ?? $appName;
@endphp

@if ($faviconUrl)
    <span class="inline-flex items-center justify-center rounded-lg bg-white p-1 shadow-xs border border-slate-200/80 dark:border-white/20 shrink-0">
        <img
            src="{{ $faviconUrl }}"
            alt="{{ $altText }}"
            {{ $attributes->merge(['class' => "{$size} rounded-xs object-contain"]) }}
        />
    </span>
@else
    <span {{ $attributes->merge(['class' => "{$badgeClass} {$size}"]) }}>
        <x-icon name="zap" class="{{ $iconSize }} text-primary" />
    </span>
@endif