@props([
    'href' => null,
    'iconOnly' => false,
    'hideText' => false,
    'imgClass' => 'h-7 max-w-[140px] object-contain shrink-0',
    'iconBadgeClass' => 'flex h-8 w-8 items-center justify-center rounded-lg bg-primary/15 text-primary shrink-0',
    'iconClass' => 'h-4 w-4 text-primary',
    'textClass' => 'font-semibold text-sm tracking-tight truncate text-foreground',
    'alt' => null,
])

@php
    $logoUrl = \App\Models\Setting::logoUrl();
    $faviconUrl = \App\Models\Setting::faviconUrl(fallback: false);
    $appName = \App\Models\Setting::appName();
    $altText = $alt ?? $appName;
@endphp

@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->merge(['class' => 'flex items-center gap-2.5 transition-opacity hover:opacity-90 min-w-0']) }}>
@else
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2.5 min-w-0']) }}>
@endif

    @if ($logoUrl && ! $iconOnly)
        <div class="inline-flex items-center justify-center rounded-lg bg-white p-1.5 shadow-xs border border-slate-200/80 dark:border-white/20 shrink-0">
            <img src="{{ $logoUrl }}" alt="{{ $altText }}" class="{{ $imgClass }}" />
        </div>
        @if (! $hideText)
            <span class="{{ $textClass }}">{{ $appName }}</span>
        @endif
    @elseif ($faviconUrl && $iconOnly)
        <div class="inline-flex items-center justify-center rounded-lg bg-white p-1.5 shadow-xs border border-slate-200/80 dark:border-white/20 shrink-0">
            <img src="{{ $faviconUrl }}" alt="{{ $altText }}" class="h-6 w-6 object-contain" />
        </div>
    @else
        <span class="{{ $iconBadgeClass }}">
            <x-icon name="zap" class="{{ $iconClass }}" />
        </span>
        @if (! $iconOnly && ! $hideText)
            <span class="{{ $textClass }}">{{ $appName }}</span>
        @endif
    @endif

@if ($href)
    </a>
@else
    </div>
@endif