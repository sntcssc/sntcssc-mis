@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2.5 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-sm font-medium text-primary']) }}>
        <x-icon name="check-circle-2" class="h-4 w-4 shrink-0 text-primary"/>
        <span>{{ $status }}</span>
    </div>
@endif
