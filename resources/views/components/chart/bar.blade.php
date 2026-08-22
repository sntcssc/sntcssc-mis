@props([
    'data' => [],
    'height' => 'h-52 sm:h-64',
    'prefix' => '$',
    'currentLabel' => 'This period',
    'previousLabel' => 'Previous',
])

@php
    $max = 0;
    foreach ($data as $row) {
        $max = max($max, $row['current'] ?? 0, $row['previous'] ?? 0);
    }

    $niceMax = (int) (ceil($max / 5) * 5);
    if ($niceMax < $max || $niceMax === 0) {
        $niceMax = max($niceMax, 100);
    }

    $format = function ($value) use ($prefix) {
        if ($value >= 1000) {
            return $prefix.round($value / 1000).'k';
        }

        return $prefix.$value;
    };

    $gridLines = [100, 75, 50, 25];
    $count = count($data);
    $labelIndexes = $count > 1 ? [0, intdiv($count - 1, 2), $count - 1] : [0];
@endphp

<div class="w-full {{ $attributes->get('class') }}">
    <div class="relative {{ $height }} pl-10">
        @foreach ($gridLines as $line)
            <div class="absolute inset-x-0 left-10 h-px border-t border-dashed border-black/[.06] dark:border-white/[.06]" style="top: {{ $line }}%"></div>
        @endforeach

        <div class="absolute inset-0 flex flex-col justify-between">
            @foreach ([100, 75, 50, 25, 0] as $line)
                <span class="absolute left-0 -translate-y-1/2 w-9 text-right text-[10px] text-muted-foreground/70" style="top: {{ 100 - $line }}%">
                    {{ $format((int) round($niceMax * $line / 100)) }}
                </span>
            @endforeach
        </div>

        <div class="absolute inset-0 flex items-end">
            @foreach ($data as $row)
                @php
                    $currentHeight = $niceMax > 0 ? max(round(($row['current'] ?? 0) / $niceMax * 100), $row['current'] > 0 || $row['previous'] > 0 ? 1 : 0) : 0;
                    $previousHeight = $niceMax > 0 ? max(round(($row['previous'] ?? 0) / $niceMax * 100), $row['current'] > 0 || $row['previous'] > 0 ? 1 : 0) : 0;
                @endphp
                <div
                    class="flex-1 h-full flex items-end justify-center gap-[2px] group"
                    title="{{ $row['label'] }} — {{ $currentLabel }}: {{ number_format($row['current'] ?? 0) }} · {{ $previousLabel }}: {{ number_format($row['previous'] ?? 0) }}"
                >
                    <div class="w-2.5 rounded-t-[3px] bg-emerald-500 transition-colors group-hover:bg-emerald-400" style="height: {{ $currentHeight }}%"></div>
                    <div class="w-2.5 rounded-t-[3px] bg-slate-200 dark:bg-zinc-800 transition-colors group-hover:bg-slate-300 dark:group-hover:bg-zinc-700" style="height: {{ $previousHeight }}%"></div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mt-1.5 pl-10 flex">
        @foreach ($data as $index => $row)
            <span class="flex-1 text-center text-[10px] text-muted-foreground">
                @if (in_array($index, $labelIndexes) && ($index !== $count - 1 || $count <= 2 || $index !== intdiv($count - 1, 2)))
                    {{ $row['label'] }}
                @endif
            </span>
        @endforeach
    </div>
</div>
