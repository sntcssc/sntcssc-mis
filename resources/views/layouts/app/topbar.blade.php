@php
    $user = auth()->user();
    $viewportPreferences = \App\Services\GlobalSearchService::getViewportPreferences($user);
    $defaultFullscreen = $viewportPreferences['effective_fullscreen'] ?? 'normal';

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
        {{-- Mobile Search Trigger --}}
        <button
            type="button"
            x-on:click="$dispatch('open-global-search')"
            class="flex sm:hidden h-9 w-9 items-center justify-center rounded-lg border border-border bg-card text-foreground hover:bg-secondary transition-colors cursor-pointer"
            aria-label="{{ __('Search admin panel') }}"
            title="{{ __('Search admin panel (Ctrl+K)') }}"
        >
            <x-icon name="search" class="h-4 w-4"/>
        </button>

        {{-- Desktop/Tablet Global Search Trigger --}}
        <button
            type="button"
            x-on:click="$dispatch('open-global-search')"
            class="hidden sm:flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-2.5 sm:px-3 py-1.5 text-sm text-muted-foreground hover:bg-secondary hover:text-foreground transition-colors h-9 cursor-pointer"
            title="{{ __('Search admin panel (Ctrl+K)') }}"
        >
            <x-icon name="search" class="h-3.5 w-3.5 shrink-0"/>
            <span class="hidden md:inline">{{ __('Search…') }}</span>
            <kbd class="hidden lg:inline-flex h-5 items-center gap-0.5 rounded border border-border bg-muted px-1.5 font-mono text-[10px] text-muted-foreground">
                {{ __('Ctrl') }} K
            </kbd>
        </button>

        {{-- Team switcher (replaces the template's store selector - accessible only to administrators) --}}
        @if ($user?->isAdmin())
            <livewire:team-switcher/>
        @endif

        {{-- Language switcher --}}
        <x-locale-switcher/>

        {{-- Theme switcher: light / dark / system --}}
        <x-ui.theme-switch/>

        {{-- Fullscreen Mode Toggle & Auto-Fullscreen Engine --}}
        <div
            x-data="{
                isFullscreen: false,
                configDefault: @js($defaultFullscreen),

                init() {
                    this.isFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);

                    const syncState = () => {
                        this.isFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
                    };

                    document.addEventListener('fullscreenchange', syncState);
                    document.addEventListener('webkitfullscreenchange', syncState);
                    document.addEventListener('mozfullscreenchange', syncState);
                    document.addEventListener('MSFullscreenChange', syncState);

                    // Global custom event listener
                    window.addEventListener('toggle-admin-fullscreen', () => {
                        this.toggle();
                    });

                    // Check if default fullscreen is enabled in config or user preference
                    const userSavedPref = localStorage.getItem('admin_default_fullscreen');
                    const shouldAutoFullscreen = userSavedPref === 'fullscreen' || (userSavedPref === null && this.configDefault === 'fullscreen');

                    if (shouldAutoFullscreen && !this.isFullscreen) {
                        this.requestAutoFullscreen();
                    }
                },

                toggle() {
                    if (!this.isFullscreen) {
                        this.enter();
                    } else {
                        this.exit();
                    }
                },

                enter() {
                    const el = document.documentElement;
                    const rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
                    if (rfs) {
                        rfs.call(el).then(() => {
                            this.isFullscreen = true;
                        }).catch(err => {
                            console.warn('Fullscreen entry prevented:', err);
                        });
                    }
                },

                exit() {
                    const efs = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen || document.msExitFullscreen;
                    if (efs && (document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement)) {
                        efs.call(document).then(() => {
                            this.isFullscreen = false;
                        }).catch(err => {
                            console.warn('Fullscreen exit prevented:', err);
                        });
                    }
                },

                requestAutoFullscreen() {
                    const el = document.documentElement;
                    const rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
                    if (rfs) {
                        try {
                            const promise = rfs.call(el);
                            if (promise && promise.catch) {
                                promise.catch(() => {
                                    const onUserGesture = () => {
                                        if (!this.isFullscreen) {
                                            this.enter();
                                        }
                                        window.removeEventListener('click', onUserGesture);
                                        window.removeEventListener('keydown', onUserGesture);
                                    };
                                    window.addEventListener('click', onUserGesture, { once: true });
                                    window.addEventListener('keydown', onUserGesture, { once: true });
                                });
                            }
                        } catch (e) {}
                    }
                }
            }"
            class="flex items-center"
        >
            <button
                type="button"
                x-on:click="toggle()"
                class="flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-card text-foreground hover:bg-secondary transition-colors cursor-pointer"
                :title="isFullscreen ? '{{ __('Exit Full Screen (Esc / F11)') }}' : '{{ __('Toggle Full Screen (F11)') }}'"
                :aria-label="isFullscreen ? '{{ __('Exit Full Screen') }}' : '{{ __('Enter Full Screen') }}'"
            >
                <template x-if="!isFullscreen">
                    <x-icon name="maximize-2" class="h-4 w-4 text-foreground/80"/>
                </template>
                <template x-if="isFullscreen">
                    <x-icon name="minimize-2" class="h-4 w-4 text-primary"/>
                </template>
            </button>
        </div>

        {{-- Live Chat & Online Meetings Quick Access Buttons --}}
        @php
            $chatEnabled = (bool) \App\Models\Setting::get('chat.enabled', true);
            $hasChatAccess = $chatEnabled && $user && ($user->can('chat.access') || $user->hasRole('Super Administrator'));
            $unreadChat = ($hasChatAccess && $user) ? $user->unreadChatMessagesCount() : 0;
            $meetingsEnabled = (bool) \App\Models\Setting::get('meeting.enabled', \App\Models\Setting::get('chat.meetings_enabled', true));
            $hasMeetingsAccess = $meetingsEnabled && $user && ($user->can('meetings.view') || $user->hasRole('Super Administrator'));
        @endphp
        @if ($hasChatAccess)
            <a
                href="{{ route('admin.chat.index') }}"
                wire:navigate
                class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-card text-foreground hover:bg-secondary transition-colors cursor-pointer"
                title="{{ __('Live Chat & Calls') }}"
            >
                <x-icon name="message-square" class="h-4 w-4" />
                @if ($unreadChat > 0)
                    <span class="absolute -top-1 -right-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold text-primary-foreground shadow-xs animate-pulse">
                        {{ $unreadChat > 99 ? '99+' : $unreadChat }}
                    </span>
                @endif
            </a>
        @endif
        @if ($hasMeetingsAccess)
            <a
                href="{{ route('meetings.index') }}"
                wire:navigate
                class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-card text-foreground hover:bg-secondary transition-colors cursor-pointer"
                title="{{ __('Online Meetings') }}"
            >
                <x-icon name="video" class="h-4 w-4" />
            </a>
        @endif

        {{-- Realtime Interactive Notifications Bell & Drawer --}}
        <livewire:notification-bell />


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
