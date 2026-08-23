<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => request()->cookie('theme') === 'dark'])>
    <head>
        @include('partials.head')
    </head>
    <body class="bg-background">
        <div class="min-h-screen flex flex-col bg-background">
            <div class="fixed top-4 left-4 z-50">
                <x-ui.theme-toggle/>
            </div>

            <div class="flex-1 flex items-center justify-center px-4 py-8">
                <div class="w-full max-w-[440px]">
                    {{ $slot }}

                    <p class="mt-6 text-center text-xs text-muted-foreground">
                        {{ \App\Models\Setting::copyrightText() }}
                    </p>
                </div>
            </div>
        </div>

        <x-ui.toasts/>

        {{-- Auth pages are plain Blade (no Livewire components), so Livewire/Alpine
             is not auto-injected here. Load it explicitly for Alpine (theme toggle,
             toasts, OTP inputs, passkey buttons) and wire:navigate links. --}}
        @livewireScripts
    </body>
</html>
