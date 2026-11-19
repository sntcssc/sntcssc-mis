@props([
    'active' => 'all',
])

@php
    $tabs = [
        'all' => [
            'label' => __('All settings'),
            'icon' => 'layout-grid',
            'route' => 'admin.settings.index',
            'permission' => 'settings.general',
        ],
        'general' => [
            'label' => __('General'),
            'icon' => 'settings',
            'route' => 'admin.settings.general',
            'permission' => 'settings.general',
        ],
        'seo' => [
            'label' => __('SEO'),
            'icon' => 'globe',
            'route' => 'admin.settings.seo',
            'permission' => 'settings.general',
        ],
        'appearance' => [
            'label' => __('Appearance'),
            'icon' => 'palette',
            'route' => 'admin.settings.appearance',
            'permission' => 'settings.appearance',
        ],
        'email' => [
            'label' => __('Email Provider'),
            'icon' => 'mail',
            'route' => 'admin.settings.email',
            'permission' => 'settings.email',
        ],
        'localization' => [
            'label' => __('Localization & Formats'),
            'icon' => 'languages',
            'route' => 'admin.settings.localization',
            'permission' => 'settings.localization',
        ],
        'payment' => [
            'label' => __('Payment Gateways'),
            'icon' => 'credit-card',
            'route' => 'admin.settings.payment',
            'permission' => 'settings.payment',
        ],
        'sms' => [
            'label' => __('SMS Gateway'),
            'icon' => 'smartphone',
            'route' => 'admin.settings.sms',
            'permission' => 'settings.sms',
        ],
        'notification' => [
            'label' => __('Realtime & Notifications'),
            'icon' => 'bell',
            'route' => 'admin.settings.notification',
            'permission' => 'settings.general',
        ],
        'chat' => [
            'label' => __('Live Chat & Calls'),
            'icon' => 'message-square',
            'route' => 'admin.settings.chat',
            'permission' => 'settings.general',
        ],
        'meetings' => [
            'label' => __('Online Meetings'),
            'icon' => 'video',
            'route' => 'admin.settings.meetings',
            'permission' => 'settings.general',
        ],
        'library' => [
            'label' => __('Library Policy'),
            'icon' => 'book',
            'route' => 'admin.settings.library',
            'permission' => 'library.settings.manage',
        ],
        'attendance' => [
            'label' => __('Attendance & Shifts'),
            'icon' => 'calendar-check',
            'route' => 'admin.settings.attendance',
            'permission' => 'attendance.settings',
        ],
        'hostel' => [
            'label' => __('Hostel Policies'),
            'icon' => 'bed',
            'route' => 'admin.settings.hostel',
            'permission' => 'hostel.settings.manage',
        ],
        'student-id-card' => [
            'label' => __('Student ID Card'),
            'icon' => 'id-card',
            'route' => 'admin.settings.student-id-card',
            'permission' => 'settings.general',
        ],
        'captcha' => [
            'label' => __('reCAPTCHA & Bot Defense'),
            'icon' => 'shield-check',
            'route' => 'admin.settings.captcha',
            'permission' => 'settings.captcha',
        ],
        'storage' => [
            'label' => __('Storage & S3 Cloud'),
            'icon' => 'hard-drive',
            'route' => 'admin.settings.storage',
            'permission' => 'settings.storage',
        ],
        'system' => [
            'label' => __('System'),
            'icon' => 'cpu',
            'route' => 'admin.settings.system',
            'permission' => 'settings.general',
        ],
        'backup' => [
            'label' => __('Database & Backups'),
            'icon' => 'database',
            'route' => 'admin.settings.backup',
            'permission' => 'settings.backup',
        ],
    ];

@endphp

<div class="border-b border-border mb-6 w-full">
    <div class="flex items-center gap-1 overflow-x-auto pb-1.5 scrollbar-thin scrollbar-thumb-border hover:scrollbar-thumb-muted-foreground/30 scrollbar-track-transparent">
        @foreach ($tabs as $key => $tab)
            @php
                if (isset($tab['permission']) && ! (auth()->user()?->can($tab['permission']) || auth()->user()?->hasRole('Super Administrator'))) {
                    continue;
                }
                $isActive = $active === $key;
                $href = route($tab['route']);
            @endphp
            <a
                href="{{ $href }}"
                wire:navigate
                class="flex items-center gap-2 px-3.5 py-2.5 text-xs font-medium rounded-t-lg border-b-2 transition-all shrink-0 whitespace-nowrap {{ $isActive ? 'border-primary text-primary bg-primary/5 font-semibold' : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-secondary/40' }}"
            >
                <x-icon :name="$tab['icon']" class="h-4 w-4 shrink-0 {{ $isActive ? 'text-primary' : 'text-muted-foreground' }}"/>
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
