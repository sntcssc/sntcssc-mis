@props([
    'name' => null,
    'initials' => null,
    'src' => null,
])

@php
    $initials = $initials ?: collect(explode(' ', trim((string) $name) ?: '?'))
        ->filter()
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->take(2)
        ->implode('');
@endphp

<span class="relative flex {{ $attributes->get('size', 'size-9') }} shrink-0 overflow-hidden rounded-full {{ $attributes->except('size') }}">
    @if ($src)
        <img src="{{ $src }}" alt="{{ $name }}" class="aspect-square size-full object-cover"/>
    @else
        <span class="flex size-full items-center justify-center rounded-full bg-emerald-500/20 text-emerald-500 font-semibold">
            {{ $initials }}
        </span>
    @endif
</span>
