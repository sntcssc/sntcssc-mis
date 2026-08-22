@props([
    'align' => 'end',
    'width' => 'w-48',
    'offset' => 'mt-2',
])

@php
    $alignments = [
        'start' => 'left-0 origin-top-left',
        'end' => 'right-0 origin-top-right',
        'center' => 'left-1/2 -translate-x-1/2 origin-top',
    ];
@endphp

<div
    class="relative {{ $attributes->get('class') }}"
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
    {{ $attributes->except('class') }}
>
    <div x-on:click="open = ! open" class="inline-flex">
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="ui-popover-in"
        @if ($align === 'end') style="--ui-popover-origin: top right" @elseif ($align === 'start') style="--ui-popover-origin: top left" @else style="--ui-popover-origin: top center" @endif
        class="absolute z-50 {{ $offset }} {{ $width }} {{ $alignments[$align] }} overflow-hidden rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-md"
    >
        {{ $menu ?? $slot }}
    </div>
</div>
