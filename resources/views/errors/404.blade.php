<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => request()->cookie('theme') === 'dark'])>
    <head>
        @include('partials.head')
    </head>
    <body class="bg-background">
        <div class="min-h-screen flex flex-col items-center justify-center px-4 py-16 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/15 mb-6">
                <x-icon name="file-question" class="h-7 w-7 text-amber-500"/>
            </div>

            <h1 class="text-7xl sm:text-8xl font-bold tracking-tight text-muted-foreground/20 select-none">404</h1>
            <h2 class="mt-4 text-lg font-semibold">{{ __('Page not found') }}</h2>
            <p class="mt-2 max-w-md text-sm text-muted-foreground">
                {{ __('The page you are looking for does not exist or has been moved. Check the URL or head back to the dashboard.') }}
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-center gap-3">
                <x-ui.button href="{{ auth()->check() ? route('dashboard') : route('home') }}" wire:navigate>
                    {{ __('Go to dashboard') }}
                </x-ui.button>
                <x-ui.button variant="outline" onclick="history.back()">
                    {{ __('Go back') }}
                </x-ui.button>
            </div>
        </div>

        <x-ui.toasts/>
    </body>
</html>
