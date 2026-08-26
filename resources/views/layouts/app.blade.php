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
                @if (session()->has(\App\Services\ImpersonationService::SESSION_KEY))
                    @php
                        $impersonator = \App\Models\User::find(session(\App\Services\ImpersonationService::SESSION_KEY));
                        $currentUser = auth()->user();
                    @endphp
                    <div class="sticky top-0 z-40 flex flex-wrap items-center justify-between gap-3 bg-amber-500 text-amber-950 dark:bg-amber-600 dark:text-white px-4 py-2 text-xs font-medium shadow-md">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-950/15 dark:bg-white/20">
                                <x-icon name="user-check" class="h-3 w-3"/>
                            </span>
                            <span class="truncate">
                                <strong>{{ __('Impersonation Mode:') }}</strong>
                                {{ __('Browsing as :name (:email) — Signed in via Administrator :admin.', ['name' => $currentUser?->name, 'email' => $currentUser?->email, 'admin' => $impersonator?->name ?? 'Admin']) }}
                            </span>
                        </div>

                        <form method="POST" action="{{ route('admin.impersonate.leave') }}" class="shrink-0">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 rounded-md bg-amber-950 text-white dark:bg-white dark:text-amber-900 px-3 py-1 text-xs font-semibold hover:opacity-90 transition-opacity cursor-pointer shadow-xs"
                            >
                                <x-icon name="log-out" class="h-3.5 w-3.5"/>
                                {{ __('Switch Back to Admin') }}
                            </button>
                        </form>
                    </div>
                @endif

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
        <livewire:chat-call-overlay/>

        {{-- Global Livewire File Upload Progress Indicator --}}
        <div
            x-data="{ uploading: false, progress: 0 }"
            x-on:livewire-upload-start.window="uploading = true; progress = 0;"
            x-on:livewire-upload-finish.window="uploading = false;"
            x-on:livewire-upload-error.window="uploading = false;"
            x-on:livewire-upload-progress.window="progress = $event.detail.progress;"
            x-show="uploading"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-4"
            x-cloak
            class="fixed top-4 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 rounded-xl border border-primary/30 bg-card/95 px-4 py-2.5 shadow-2xl backdrop-blur-md"
        >
            <x-icon name="refresh-cw" class="h-5 w-5 animate-spin text-primary shrink-0"/>
            <div class="flex flex-col">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold text-foreground">{{ __('Uploading file…') }}</span>
                    <span class="text-xs font-bold font-mono text-primary" x-text="`${progress}%`"></span>
                </div>
                <div class="w-48 bg-secondary rounded-full h-1.5 mt-1 overflow-hidden">
                    <div class="bg-primary h-1.5 rounded-full transition-all duration-150" :style="`width: ${progress}%`"></div>
                </div>
            </div>
        </div>

        <x-ui.toasts/>
    </body>
</html>
