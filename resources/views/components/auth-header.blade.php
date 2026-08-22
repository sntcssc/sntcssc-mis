@props([
    'title',
    'description',
])

<div class="flex flex-col items-center text-center">
    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 mb-3">
        <x-icon name="zap" class="h-5 w-5 text-emerald-500"/>
    </div>
    <h1 class="text-xl font-semibold">{{ $title }}</h1>
    @if ($description)
        <p class="text-sm text-muted-foreground mt-1 text-center">{{ $description }}</p>
    @endif
</div>
