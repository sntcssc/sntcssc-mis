<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head', ['title' => __('System Maintenance')])
</head>
<body class="min-h-screen bg-background text-foreground flex items-center justify-center p-4 antialiased">
    <div class="max-w-md w-full text-center space-y-6 bg-card border border-border p-8 rounded-2xl shadow-lg">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-500/10 text-amber-500">
            <x-icon name="tool" class="h-8 w-8"/>
        </div>

        <div class="space-y-2">
            <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Under Scheduled Maintenance') }}</h1>
            <p class="text-sm text-muted-foreground leading-relaxed">
                {{ $message ?? __('We are performing scheduled maintenance and updates to improve your experience. We will be back online shortly.') }}
            </p>
        </div>

        <div class="pt-4 border-t border-border flex flex-col gap-3">
            <a href="{{ route('login') }}" class="text-xs text-primary hover:underline font-medium">
                {{ __('Administrator Sign In') }} &rarr;
            </a>

            @if (!empty($secretConfigured))
                <details class="text-left text-xs text-muted-foreground pt-2">
                    <summary class="cursor-pointer font-medium hover:text-foreground">{{ __('Have a Bypass Secret Key?') }}</summary>
                    <form method="GET" action="{{ url()->current() }}" class="mt-3 flex gap-2">
                        <input
                            type="text"
                            name="secret"
                            placeholder="{{ __('Enter secret key') }}"
                            class="h-8 flex-1 rounded-md border border-input bg-background px-2.5 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[2px] focus-visible:ring-ring/40 font-mono"
                            required
                        />
                        <button type="submit" class="h-8 px-3 rounded-md bg-primary text-primary-foreground font-medium text-xs shadow-xs hover:bg-primary/90 cursor-pointer">
                            {{ __('Bypass') }}
                        </button>
                    </form>
                </details>
            @endif
        </div>
    </div>
</body>
</html>
