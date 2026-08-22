@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2.5 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-sm font-medium text-emerald-600 dark:text-emerald-400']) }}>
        <x-icon name="check-circle-2" class="h-4 w-4 shrink-0 text-emerald-500"/>
        <span>{{ $status }}</span>
    </div>
@endif
