@props([
    'slices' => [],
    'size' => 130,
    'centerLabel' => null,
    'centerValue' => null,
    'prefix' => '$',
    'showLegend' => true,
    'legendCols' => 'grid-cols-2',
])

@php
    $total = array_sum(array_column($slices, 'value'));
    $radius = 52;
    $circumference = 2 * pi() * $radius;
    $gap = 1.5; // degrees between slices, like recharts paddingAngle
    $offset = 0.0;
    $computed = [];

    foreach ($slices as $slice) {
        $fraction = $total > 0 ? $slice['value'] / $total : 0;
        $degrees = $fraction * 360;
        $arcLength = max($circumference * ($degrees - $gap) / 360, 0);
        $computed[] = $slice + [
            'dasharray' => $arcLength.' '.$circumference,
            'dashoffset' => -$circumference * ($offset / 360) + $circumference * ($gap / 2) / 360,
            'percent' => $total > 0 ? $fraction * 100 : 0,
        ];
        $offset += $degrees;
    }
@endphp

<div class="w-full {{ $attributes->get('class') }}">
    <div class="flex flex-col items-center gap-4">
        <div class="relative" style="width: {{ $size }}px; height: {{ $size }}px">
            <svg viewBox="0 0 130 130" class="size-full -rotate-90">
                <circle cx="65" cy="65" r="{{ $radius }}" fill="none" stroke="var(--secondary)" stroke-width="20" class="opacity-50"/>
                @foreach ($computed as $slice)
                    <circle
                        cx="65" cy="65" r="{{ $radius }}"
                        fill="none"
                        stroke="{{ $slice['color'] }}"
                        stroke-width="20"
                        stroke-dasharray="{{ $slice['dasharray'] }}"
                        stroke-dashoffset="{{ $slice['dashoffset'] }}"
                    >
                        <title>{{ $slice['name'] }}: {{ $prefix.number_format($slice['value'], 2) }} ({{ number_format($slice['percent'], 1) }}%)</title>
                    </circle>
                @endforeach
            </svg>

            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ $centerLabel }}</span>
                <span class="text-xl font-bold tracking-tight">{{ $centerValue !== null ? $prefix.number_format($centerValue) : $prefix.number_format($total) }}</span>
            </div>
        </div>

        @if ($showLegend)
            <div class="grid {{ $legendCols }} gap-x-4 gap-y-2 w-full">
                @foreach ($computed as $slice)
                    <div class="flex items-center gap-2 min-w-0" title="{{ $slice['name'] }} · {{ $prefix.number_format($slice['value'], 2) }}">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $slice['color'] }}"></span>
                        <span class="text-xs text-muted-foreground truncate flex-1">{{ $slice['name'] }}</span>
                        <span class="text-xs font-medium">{{ number_format($slice['percent'], 1) }}%</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
