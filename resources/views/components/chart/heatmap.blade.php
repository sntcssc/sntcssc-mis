@props([
    'data' => [],
    'title' => null,
    'subtitle' => null,
])

@php
    $days = array_keys($data) ?: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $hours = range(0, 23);

    $cellColor = function ($value) {
        if ($value == 0) {
            return 'bg-gray-100 dark:bg-zinc-900/50';
        }
        if ($value <= 0.2) {
            return 'bg-emerald-900/40';
        }
        if ($value <= 0.4) {
            return 'bg-emerald-800/50';
        }
        if ($value <= 0.6) {
            return 'bg-emerald-700/60';
        }
        if ($value <= 0.8) {
            return 'bg-emerald-600/70';
        }

        return 'bg-emerald-500/80';
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-border bg-card p-4 sm:p-5']) }}>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
        <div>
            @if ($title)
                <h3 class="text-sm font-semibold">{{ $title }}</h3>
            @endif
            @if ($subtitle)
                <p class="text-xs text-muted-foreground mt-0.5">{{ $subtitle }}</p>
            @endif
        </div>
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-1.5">
                <span class="text-[10px] text-muted-foreground">Low</span>
                <div class="flex gap-px">
                    <span class="h-2.5 w-3 rounded-sm bg-gray-100 dark:bg-zinc-900/50"></span>
                    <span class="h-2.5 w-3 rounded-sm bg-emerald-900/40"></span>
                    <span class="h-2.5 w-3 rounded-sm bg-emerald-800/50"></span>
                    <span class="h-2.5 w-3 rounded-sm bg-emerald-700/60"></span>
                    <span class="h-2.5 w-3 rounded-sm bg-emerald-600/70"></span>
                    <span class="h-2.5 w-3 rounded-sm bg-emerald-500/80"></span>
                </div>
                <span class="text-[10px] text-muted-foreground">High</span>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto -mx-1 px-1">
        <div class="flex mb-1 ml-10 min-w-[360px]">
            @foreach ($hours as $hour)
                @if ($hour % 2 === 0)
                    <div class="text-[9px] text-muted-foreground/60 text-center flex-1">{{ $hour }}</div>
                @endif
            @endforeach
        </div>

        <div class="space-y-0.5 min-w-[360px]">
            @foreach ($days as $day)
                <div class="flex items-center">
                    <span class="w-10 text-[10px] text-muted-foreground/60 shrink-0">{{ $day }}</span>
                    <div class="flex flex-1 gap-px">
                        @foreach ($hours as $hour)
                            @php($value = $data[$day][$hour] ?? 0)
                            <div
                                class="h-5 sm:h-4 rounded-sm flex-1 transition-colors {{ $cellColor($value) }}"
                                title="{{ $day }} {{ $hour }}:00"
                            ></div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
