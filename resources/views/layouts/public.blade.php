<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-full bg-background text-foreground antialiased selection:bg-primary selection:text-primary-foreground flex flex-col justify-between">
        {{ $slot }}

        @livewireScripts
    </body>
</html>
