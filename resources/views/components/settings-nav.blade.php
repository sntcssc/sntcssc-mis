@props([
    'active' => 'all',
])

@php
    $tabs = [
        'all' => [
            'label' => __('All settings'),
            'icon' => 'layout-grid',
            'route' => 'admin.settings.index',
        ],
        'general' => [
            'label' => __('General'),
            'icon' => 'settings',
            'route' => 'admin.settings.general',
        ],
        'seo' => [
            'label' => __('SEO'),
            'icon' => 'globe',
            'route' => 'admin.settings.seo',
        ],
        'appearance' => [
            'label' => __('Appearance'),
            'icon' => 'palette',
            'route' => 'admin.settings.appearance',
        ],
        'email' => [
            'label' => __('Email Provider'),
            'icon' => 'mail',
            'route' => 'admin.settings.email',
        ],
        'localization' => [
            'label' => __('Localization & Formats'),
            'icon' => 'languages',
            'route' => 'admin.settings.localization',
        ],
        'payment' => [
            'label' => __('Payment Gateways'),
            'icon' => 'credit-card',
            'route' => 'admin.settings.payment',
        ],
        'sms' => [
            'label' => __('SMS Gateway'),
            'icon' => 'smartphone',
            'route' => 'admin.settings.sms',
        ],
        'system' => [
            'label' => __('System'),
            'icon' => 'cpu',
            'route' => 'admin.settings.system',
        ],
    ];
@endphp

<div class="border-b border-border mb-6">
    <div class="flex items-center gap-1 overflow-x-auto pb-px scrollbar-none">
        @foreach ($tabs as $key => $tab)
            @php
                $isActive = $active === $key;
                $href = route($tab['route']);
            @endphp
            <a
                href="{{ $href }}"
                wire:navigate
                class="flex items-center gap-2 px-3.5 py-2.5 text-xs font-medium rounded-t-lg border-b-2 transition-all whitespace-nowrap {{ $isActive ? 'border-primary text-primary bg-primary/5 font-semibold' : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-secondary/40' }}"
            >
                <x-icon :name="$tab['icon']" class="h-4 w-4 shrink-0 {{ $isActive ? 'text-primary' : 'text-muted-foreground' }}"/>
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
