@php
    $appDarkMode = (string) \App\Models\Setting::get('appearance.dark_mode', 'system');
    $cookieTheme = request()->cookie('theme');
    $isDarkInitial = $cookieTheme === 'dark' || ($cookieTheme === null && $appDarkMode === 'dark');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $isDarkInitial])>
    <head>
        @include('partials.head')
    </head>
    <body class="bg-background">
        <div
            class="min-h-screen flex bg-background"
            x-data="{
                collapsed: localStorage.getItem('sidebar-collapsed') === '1',
                mobileOpen: false,

                toggleSidebar() {
                    this.collapsed = ! this.collapsed;
                    localStorage.setItem('sidebar-collapsed', this.collapsed ? '1' : '0');
                },

                openSubmenu: @js(request()->routeIs('settings.*') ? 'settings' : null),
            }"
            x-on:keydown.escape.window="mobileOpen = false"
        >
            @include('layouts.app.sidebar')

            <div
                class="flex-1 flex flex-col transition-all duration-300 min-w-0"
                :class="collapsed ? 'lg:ml-16' : 'lg:ml-60'"
            >
                @include('layouts.app.topbar')

                <main class="flex-1 p-3 sm:p-4 lg:p-6 space-y-4 sm:space-y-6 max-w-[1440px] w-full">
                    {{ $slot }}
                </main>

                <footer class="border-t border-border px-3 sm:px-4 lg:px-6 py-4">
                    <div class="max-w-[1440px] flex flex-col sm:flex-row items-center justify-between gap-2 text-[10px] sm:text-xs text-muted-foreground">
                        <span>{{ \App\Models\Setting::copyrightText() }}</span>
                        <div class="flex items-center gap-4">
                            <a href="https://laravel.com/docs" target="_blank" class="hover:text-foreground transition-colors">{{ __('Documentation') }}</a>
                            <a href="#" class="hover:text-foreground transition-colors">{{ __('Support') }}</a>
                            <span class="flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                                v1.0.0
                            </span>
                        </div>
                    </div>
                </footer>
            </div>
        </div>

        <livewire:create-team-modal/>

        <x-ui.toasts/>
    </body>
</html>
