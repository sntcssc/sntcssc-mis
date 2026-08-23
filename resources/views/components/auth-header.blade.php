@props([
    'title',
    'description' => null,
    'icon' => null,
])

@php
    $logoUrl = \App\Models\Setting::logoUrl();
    $faviconUrl = \App\Models\Setting::faviconUrl(fallback: false);
    $appName = \App\Models\Setting::appName();
@endphp

<div class="flex flex-col items-center text-center">
    <a href="{{ route('home') }}" wire:navigate class="mb-4 inline-flex items-center gap-2.5 justify-center transition-opacity hover:opacity-90">
        @if ($logoUrl)
            <div class="inline-flex items-center justify-center rounded-xl bg-white p-2 shadow-sm border border-slate-200/80 dark:border-white/20">
                <img src="{{ $logoUrl }}" alt="{{ $appName }}" class="h-9 max-h-10 max-w-[160px] object-contain" />
            </div>
            <span class="font-bold text-lg tracking-tight text-foreground">{{ $appName }}</span>
        @elseif ($faviconUrl)
            <div class="inline-flex items-center justify-center rounded-xl bg-white p-2 shadow-sm border border-slate-200/80 dark:border-white/20">
                <img src="{{ $faviconUrl }}" alt="{{ $appName }}" class="h-8 w-8 object-contain" />
            </div>
            <span class="font-bold text-lg tracking-tight text-foreground">{{ $appName }}</span>
        @else
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/15 text-primary shadow-xs">
                <x-icon :name="$icon ?? 'zap'" class="h-5 w-5 text-primary"/>
            </div>
        @endif
    </a>
    <h1 class="text-xl font-semibold">{{ $title }}</h1>
    @if ($description)
        <p class="text-sm text-muted-foreground mt-1 text-center">{{ $description }}</p>
    @endif
</div>
