@php
    $user = auth()->user();
    $tabs = [
        ['label' => __('Profile'), 'href' => route('profile.edit'), 'active' => request()->routeIs('profile.edit')],
        ['label' => __('Security'), 'href' => route('security.edit'), 'active' => request()->routeIs('security.edit')],
    ];

    if ($user?->isAdmin()) {
        $tabs[] = ['label' => __('Teams'), 'href' => route('teams.index'), 'active' => request()->routeIs('teams.*')];
    }

    $tabs[] = ['label' => __('Appearance'), 'href' => route('appearance.edit'), 'active' => request()->routeIs('appearance.edit')];
@endphp

<div class="w-full">
    <div class="flex gap-1 p-1 bg-secondary/50 rounded-lg w-full sm:w-fit overflow-x-auto">
        @foreach ($tabs as $tab)
            <a
                href="{{ $tab['href'] }}"
                wire:navigate
                class="flex-1 sm:flex-none flex items-center justify-center px-3.5 py-2 rounded-md text-sm font-medium transition-colors whitespace-nowrap {{ $tab['active'] ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' }}"
            >
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>

    <div class="mt-6">
        <div class="flex items-center gap-2.5 flex-wrap">
            <h2 class="text-lg font-semibold tracking-tight">{{ $heading ?? '' }}</h2>
            @if (auth()->check())
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded font-mono text-[11px] font-semibold bg-muted text-foreground border border-border/70" title="{{ __('Universal Registration Number') }}">
                    <span class="text-muted-foreground">{{ __('URN:') }}</span>
                    @if (!empty(auth()->user()->urn))
                        <span class="font-bold">{{ auth()->user()->urn }}</span>
                    @else
                        <span class="text-muted-foreground/70 italic text-[10px]">{{ __('Not Assigned') }}</span>
                    @endif
                </span>
            @endif
        </div>
        @if (! empty($subheading))
            <p class="text-sm text-muted-foreground mt-1">{{ $subheading }}</p>
        @endif

        <div class="mt-6 w-full max-w-2xl">
            {{ $slot ?? '' }}
        </div>
    </div>
</div>
