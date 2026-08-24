@php
    $user = auth()->user();

    $breadcrumbs = collect();

    if (request()->routeIs('dashboard')) {
        $breadcrumbs->push(['label' => __('Dashboard'), 'href' => null]);
    } elseif (request()->routeIs('admin.*')) {
        $labelMap = [
            'students' => __('Students'),
            'admissions' => __('Admissions'),
            'enrollments' => __('Enrollments'),
            'courses' => __('Courses'),
            'batches' => __('Batches'),
            'tests' => __('Tests & Selections'),
            'users' => __('Users'),
            'roles' => __('Roles'),
            'reports' => __('All reports'),
            'profile' => __('Profile & Preferences'),
        ];

        $segment = request()->segment(2);
        $breadcrumbs->push(['label' => __('Dashboard'), 'href' => route('dashboard')]);
        $breadcrumbs->push(['label' => $labelMap[$segment] ?? ucfirst($segment), 'href' => null]);
    } elseif (request()->routeIs('settings.*') || request()->routeIs('*.edit')) {
        $labelMap = [
            'profile' => __('Profile settings'),
            'security' => __('Security'),
            'appearance' => __('Appearance'),
            'teams' => __('Teams'),
        ];

        $breadcrumbs->push(['label' => __('Settings'), 'href' => null]);
        $breadcrumbs->push(['label' => $labelMap[request()->segment(2)] ?? __('Settings'), 'href' => null]);
    }
@endphp

<header class="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-border bg-background/80 backdrop-blur-md px-3 sm:px-4 lg:px-6">
    <div class="flex items-center gap-2 min-w-0">
        <button
            type="button"
            x-on:click="mobileOpen = ! mobileOpen"
            class="flex lg:hidden h-9 w-9 items-center justify-center rounded-lg hover:bg-secondary transition-colors cursor-pointer shrink-0"
            aria-label="{{ __('Toggle menu') }}"
        >
            <x-icon name="menu" class="h-5 w-5"/>
        </button>

        {{-- Mobile Application Branding --}}
        <a href="{{ route('dashboard') }}" wire:navigate class="flex lg:hidden items-center gap-2 min-w-0 max-w-[170px] xs:max-w-[210px] truncate hover:opacity-90">
            <x-app-logo-icon size="h-7 w-7" iconSize="h-3.5 w-3.5" />
            <span class="font-semibold text-xs sm:text-sm tracking-tight text-foreground truncate">{{ \App\Models\Setting::appName() }}</span>
        </a>

        <nav class="hidden sm:flex items-center text-sm text-muted-foreground gap-1">
            @foreach ($breadcrumbs as $crumb)
                <span class="flex items-center gap-1">
                    @if (! $loop->first)
                        <x-icon name="chevron-right" class="h-3 w-3"/>
                    @endif
                    @if ($crumb['href'])
                        <a href="{{ $crumb['href'] }}" wire:navigate class="hover:text-foreground transition-colors max-w-[120px] truncate">{{ $crumb['label'] }}</a>
                    @else
                        <span class="font-medium text-foreground max-w-[120px] truncate">{{ $crumb['label'] }}</span>
                    @endif
                </span>
            @endforeach
        </nav>
    </div>

    <div class="flex items-center gap-1 sm:gap-2">
        {{-- Search --}}
        <button
            type="button"
            class="hidden sm:flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-2.5 sm:px-3 py-1.5 text-sm text-muted-foreground hover:bg-secondary transition-colors h-9 cursor-pointer"
        >
            <x-icon name="search" class="h-3.5 w-3.5 shrink-0"/>
            <span class="hidden md:inline">{{ __('Search students, courses…') }}</span>
            <kbd class="hidden lg:inline-flex h-5 items-center gap-0.5 rounded border border-border bg-muted px-1.5 font-mono text-[10px] text-muted-foreground">
                {{ __('Ctrl') }} K
            </kbd>
        </button>

        {{-- Team switcher (replaces the template's store selector) --}}
        <livewire:team-switcher/>

        {{-- Language switcher --}}
        <x-locale-switcher/>

        {{-- Theme switcher: light / dark / system --}}
        <x-ui.theme-switch/>

        {{-- Notifications --}}
        <x-ui.dropdown width="w-80" offset="mt-1">
            <x-slot:trigger>
                <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-lg hover:bg-secondary transition-colors cursor-pointer" aria-label="{{ __('Notifications') }}">
                    <x-icon name="bell" class="h-4 w-4"/>
                    <span class="absolute -top-0.5 -right-0.5 h-4 w-4 flex items-center justify-center rounded-full bg-emerald-500 text-[10px] font-semibold text-white">
                        3
                    </span>
                </button>
            </x-slot:trigger>

            <x-ui.dropdown.label>{{ __('Notifications') }}</x-ui.dropdown.label>
            <x-ui.dropdown.separator/>
            <div class="flex flex-col gap-1">
                <div class="flex flex-col gap-1 rounded-md px-2 py-1.5 hover:bg-secondary cursor-pointer">
                    <span class="text-sm font-medium">{{ __('New admission received') }}</span>
                    <span class="text-xs text-muted-foreground">{{ __('Application #1247 submitted today') }}</span>
                </div>
                <div class="flex flex-col gap-1 rounded-md px-2 py-1.5 hover:bg-secondary cursor-pointer">
                    <span class="text-sm font-medium">{{ __('Batch starting soon') }}</span>
                    <span class="text-xs text-muted-foreground">{{ __('Prelims crash course starts in 3 days') }}</span>
                </div>
                <div class="flex flex-col gap-1 rounded-md px-2 py-1.5 hover:bg-secondary cursor-pointer">
                    <span class="text-sm font-medium">{{ __('Fee payment reminder') }}</span>
                    <span class="text-xs text-muted-foreground">{{ __('2 installments due this week') }}</span>
                </div>
            </div>
        </x-ui.dropdown>

        {{-- User menu --}}
        <x-ui.dropdown width="w-64" offset="mt-1">
            <x-slot:trigger>
                <button type="button" class="flex items-center gap-2 rounded-lg px-1 py-1 hover:bg-secondary transition-colors cursor-pointer" data-test="sidebar-menu-button">
                    <x-ui.avatar :name="$user->name" :initials="$user->initials()" :src="$user->avatarUrl()" size="size-7 text-xs"/>
                    <span class="hidden md:flex flex-col items-start">
                        <span class="text-sm font-medium leading-tight truncate max-w-[140px]">{{ $user->name }}</span>
                        <span class="text-[11px] text-muted-foreground leading-tight truncate max-w-[140px]">{{ $user->roles->first()?->name ?? __('User') }}</span>
                    </span>
                </button>
            </x-slot:trigger>

            <div class="flex items-center gap-3 px-2 py-2">
                <x-ui.avatar :name="$user->name" :initials="$user->initials()" :src="$user->avatarUrl()" size="size-10 text-sm"/>
                <div class="min-w-0">
                    <p class="text-sm font-medium truncate">{{ $user->name }}</p>
                    <p class="text-xs text-muted-foreground truncate">{{ $user->email }}</p>
                </div>
            </div>
            <x-ui.dropdown.separator/>
            <x-ui.dropdown.item icon="user" href="{{ route('admin.profile.show') }}">{{ __('Profile & preferences') }}</x-ui.dropdown.item>
            <x-ui.dropdown.item icon="settings" href="{{ route('profile.edit') }}">{{ __('Settings') }}</x-ui.dropdown.item>
            <x-ui.dropdown.separator/>

            <div class="px-2 py-1.5">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2">
                    {{ __('Appearance') }}
                </p>
                <div class="grid grid-cols-3 gap-1 rounded-lg border border-border p-1" x-data>
                    <button
                        type="button"
                        x-on:click="$store.theme.set('light')"
                        class="flex flex-col items-center gap-1 rounded-md px-2 py-1.5 text-xs transition-colors cursor-pointer"
                        :class="$store.theme.current === 'light' ? 'bg-secondary text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="sun" class="h-3.5 w-3.5"/>
                        <span>{{ __('Light') }}</span>
                    </button>
                    <button
                        type="button"
                        x-on:click="$store.theme.set('dark')"
                        class="flex flex-col items-center gap-1 rounded-md px-2 py-1.5 text-xs transition-colors cursor-pointer"
                        :class="$store.theme.current === 'dark' ? 'bg-secondary text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="moon" class="h-3.5 w-3.5"/>
                        <span>{{ __('Dark') }}</span>
                    </button>
                    <button
                        type="button"
                        x-on:click="$store.theme.set('system')"
                        class="flex flex-col items-center gap-1 rounded-md px-2 py-1.5 text-xs transition-colors cursor-pointer"
                        :class="$store.theme.current === 'system' ? 'bg-secondary text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="monitor" class="h-3.5 w-3.5"/>
                        <span>{{ __('Auto') }}</span>
                    </button>
                </div>
            </div>
            <x-ui.dropdown.separator/>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ui.dropdown.item icon="log-out" danger data-test="logout-button" type="submit">
                    {{ __('Sign out') }}
                </x-ui.dropdown.item>
            </form>
        </x-ui.dropdown>
    </div>
</header>
