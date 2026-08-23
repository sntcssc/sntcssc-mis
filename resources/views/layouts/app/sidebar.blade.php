@php
    $navSections = [
        [
            'title' => __('MAIN'),
            'items' => [
                ['icon' => 'layout-dashboard', 'label' => __('Dashboard'), 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
            ],
        ],
        [
            'title' => __('STUDENTS'),
            'items' => [
                ['icon' => 'users', 'label' => __('Students'), 'href' => route('admin.students.index'), 'active' => request()->routeIs('admin.students.*')],
                ['icon' => 'file-text', 'label' => __('Admissions'), 'href' => route('admin.admissions.index'), 'active' => request()->routeIs('admin.admissions.*')],
                ['icon' => 'book-open', 'label' => __('Enrollments'), 'href' => route('admin.enrollments.index'), 'active' => request()->routeIs('admin.enrollments.*')],
            ],
        ],
        [
            'title' => __('ACADEMICS'),
            'items' => [
                ['icon' => 'graduation-cap', 'label' => __('Courses'), 'href' => route('admin.courses.index'), 'active' => request()->routeIs('admin.courses.*')],
                ['icon' => 'layers', 'label' => __('Batches'), 'href' => route('admin.batches.index'), 'active' => request()->routeIs('admin.batches.*')],
                ['icon' => 'clipboard-check', 'label' => __('Tests & Selections'), 'href' => route('admin.tests.index'), 'active' => request()->routeIs('admin.tests.*')],
            ],
        ],
        [
            'title' => __('USER & ACCESS MANAGEMENT'),
            'items' => [
                ['icon' => 'user-cog', 'label' => __('Users'), 'href' => route('admin.users.index'), 'active' => request()->routeIs('admin.users.*')],
                ['icon' => 'shield', 'label' => __('Roles'), 'href' => route('admin.roles.index'), 'active' => request()->routeIs('admin.roles.*')],
            ],
        ],
        [
            'title' => __('REPORTS'),
            'items' => [
                ['icon' => 'bar-chart-3', 'label' => __('All reports'), 'href' => route('admin.reports.index'), 'active' => request()->routeIs('admin.reports.index')],
                ['icon' => 'bookmark', 'label' => __('Saved reports'), 'href' => route('admin.reports.saved'), 'active' => request()->routeIs('admin.reports.saved')],
            ],
        ],
        [
            'title' => __('SYSTEM'),
            'items' => [
                ['icon' => 'user-circle', 'label' => __('Profile'), 'href' => route('admin.profile.show'), 'active' => request()->routeIs('admin.profile.*')],
                ['icon' => 'shield-check', 'label' => __('Audit Logs'), 'href' => route('admin.audit-logs.index'), 'active' => request()->routeIs('admin.audit-logs.*')],
                [
                    'icon' => 'settings',
                    'label' => __('Settings'),
                    'children' => [
                        ['icon' => 'user', 'label' => __('Profile settings'), 'href' => route('profile.edit'), 'active' => request()->routeIs('profile.edit')],
                        ['icon' => 'lock', 'label' => __('Security'), 'href' => route('security.edit'), 'active' => request()->routeIs('security.edit')],
                        ['icon' => 'palette', 'label' => __('Appearance'), 'href' => route('appearance.edit'), 'active' => request()->routeIs('appearance.edit')],
                        ['icon' => 'users', 'label' => __('Teams'), 'href' => route('teams.index'), 'active' => request()->routeIs('teams.*')],
                        ['icon' => 'cpu', 'label' => __('System settings'), 'href' => route('admin.settings.index'), 'active' => request()->routeIs('admin.settings.*')],
                    ],
                ],
            ],
        ],
    ];

    $appName = \App\Models\Setting::appName();
@endphp

{{-- Desktop sidebar: fixed, collapsible --}}
<aside
    class="hidden lg:flex fixed left-0 top-0 z-40 h-screen flex-col border-r border-sidebar-border bg-sidebar transition-all duration-300"
    :class="collapsed ? 'w-16' : 'w-60'"
>
    {{-- Expanded mode --}}
    <div class="flex flex-col h-full min-h-0" x-show="! collapsed">
        <div class="flex h-14 items-center justify-between px-4 border-b border-sidebar-border shrink-0">
            <x-app-logo :href="route('dashboard')"/>
            <button
                type="button"
                x-on:click="toggleSidebar()"
                class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-sidebar-accent transition-colors cursor-pointer"
                aria-label="{{ __('Toggle sidebar') }}"
            >
                <x-icon name="panel-left-close" class="h-4 w-4 text-muted-foreground"/>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto py-3 px-2">
            @foreach ($navSections as $section)
                <div class="mb-2">
                    <h3 class="px-2 mb-1 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/60">
                        {{ $section['title'] }}
                    </h3>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            @if (isset($item['children']))
                                <li>
                                    <button
                                        type="button"
                                        x-on:click="openSubmenu = openSubmenu === '{{ $item['label'] }}' ? null : '{{ $item['label'] }}'"
                                        class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground hover:bg-sidebar-accent transition-colors cursor-pointer"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0 text-muted-foreground"/>
                                        <span class="flex-1 text-left truncate">{{ $item['label'] }}</span>
                                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-muted-foreground transition-transform" x-bind:class="openSubmenu === '{{ $item['label'] }}' ? '' : '-rotate-90'"/>
                                    </button>
                                    <div x-show="openSubmenu === '{{ $item['label'] }}'" x-collapse x-cloak>
                                        <ul class="ml-5 mt-0.5 space-y-0.5 border-l border-sidebar-border pl-2">
                                            @foreach ($item['children'] as $child)
                                                <li>
                                                    <a
                                                        href="{{ $child['href'] }}"
                                                        wire:navigate
                                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($child['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                                    >
                                                        <x-icon :name="$child['icon']" class="h-3.5 w-3.5 shrink-0"/>
                                                        <span class="truncate">{{ $child['label'] }}</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </li>
                            @else
                                <li>
                                    <a
                                        href="{{ $item['href'] }}"
                                        wire:navigate
                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($item['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0"/>
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-sidebar-border px-3 py-3 shrink-0">
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                <span>{{ __('All systems online') }}</span>
            </div>
            <p class="mt-1 text-[10px] text-muted-foreground/60">v1.0.0</p>
        </div>
    </div>

    {{-- Collapsed mode: icons only --}}
    <div class="flex flex-col h-full min-h-0" x-show="collapsed" x-cloak>
        <div class="flex h-14 items-center justify-center border-b border-sidebar-border shrink-0">
            <button
                type="button"
                x-on:click="toggleSidebar()"
                class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-sidebar-accent transition-colors cursor-pointer"
                aria-label="{{ __('Expand sidebar') }}"
            >
                <x-icon name="panel-left" class="h-4 w-4 text-muted-foreground"/>
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto py-3 px-2">
            @foreach ($navSections as $section)
                <div class="mb-2">
                    <div class="my-2 border-t border-sidebar-border"></div>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            <li>
                                <a
                                    href="{{ $item['href'] ?? '#' }}"
                                    wire:navigate
                                    title="{{ $item['label'] }}"
                                    class="flex items-center justify-center rounded-md px-2 py-1.5 text-sm transition-colors {{ ($item['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                >
                                    <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0"/>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
        <div class="border-t border-sidebar-border px-3 py-3 flex justify-center shrink-0">
            <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
        </div>
    </div>
</aside>

{{-- Mobile sidebar: drawer overlay --}}
<div
    class="lg:hidden fixed inset-0 z-40 bg-black/50 backdrop-blur-sm transition-opacity"
    x-show="mobileOpen"
    x-transition.opacity
    x-cloak
    x-on:click="mobileOpen = false"
    aria-hidden="true"
></div>

<aside
    class="lg:hidden fixed inset-y-0 left-0 z-50 w-72 bg-sidebar border-r border-sidebar-border transition-transform duration-300 ease-in-out"
    :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
    aria-label="{{ __('Main navigation') }}"
>
    <div class="flex flex-col h-full">
        <div class="flex h-14 items-center justify-between px-4 border-b border-sidebar-border shrink-0">
            <x-app-logo :href="route('dashboard')"/>
            <button
                type="button"
                x-on:click="mobileOpen = false"
                class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-sidebar-accent transition-colors cursor-pointer"
                aria-label="{{ __('Close menu') }}"
            >
                <x-icon name="x" class="h-4 w-4 text-muted-foreground"/>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto py-3 px-2">
            @foreach ($navSections as $section)
                <div class="mb-2">
                    <h3 class="px-2 mb-1 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/60">
                        {{ $section['title'] }}
                    </h3>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            @if (isset($item['children']))
                                <li>
                                    <button
                                        type="button"
                                        x-on:click="openSubmenu = openSubmenu === '{{ $item['label'] }}' ? null : '{{ $item['label'] }}'"
                                        class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground hover:bg-sidebar-accent transition-colors cursor-pointer"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0 text-muted-foreground"/>
                                        <span class="flex-1 text-left truncate">{{ $item['label'] }}</span>
                                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-muted-foreground transition-transform" x-bind:class="openSubmenu === '{{ $item['label'] }}' ? '' : '-rotate-90'"/>
                                    </button>
                                    <div x-show="openSubmenu === '{{ $item['label'] }}'" x-collapse x-cloak>
                                        <ul class="ml-5 mt-0.5 space-y-0.5 border-l border-sidebar-border pl-2">
                                            @foreach ($item['children'] as $child)
                                                <li>
                                                    <a
                                                        href="{{ $child['href'] }}"
                                                        wire:navigate
                                                        x-on:click="mobileOpen = false"
                                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($child['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                                    >
                                                        <x-icon :name="$child['icon']" class="h-3.5 w-3.5 shrink-0"/>
                                                        <span class="truncate">{{ $child['label'] }}</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </li>
                            @else
                                <li>
                                    <a
                                        href="{{ $item['href'] }}"
                                        wire:navigate
                                        x-on:click="mobileOpen = false"
                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($item['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0"/>
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-sidebar-border px-3 py-3 shrink-0">
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                <span>{{ __('All systems online') }}</span>
            </div>
            <p class="mt-1 text-[10px] text-muted-foreground/60">v1.0.0</p>
        </div>
    </div>
</aside>
