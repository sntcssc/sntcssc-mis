<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Redirecting to CCAvenue...') }} - {{ config('app.name', 'SNTCSSC') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-slate-50 dark:bg-slate-950 font-sans text-slate-800 dark:text-slate-200 antialiased flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl text-center space-y-6">
        {{-- Institute Header --}}
        <div class="flex flex-col items-center justify-center space-y-2">
            <div class="h-16 w-16 rounded-2xl bg-primary/10 text-primary flex items-center justify-center shadow-inner">
                <svg class="h-8 w-8 animate-spin text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">{{ __('Connecting to CCAvenue') }}</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ __('Transferring securely to CCAvenue 256-Bit SSL Payment Gateway.') }}
            </p>
        </div>

        {{-- Order Card --}}
        <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-800 p-4 text-left space-y-2 text-xs">
            <div class="flex justify-between py-1 border-b border-slate-200 dark:border-slate-700/60">
                <span class="text-slate-500 dark:text-slate-400">{{ __('Transaction Reference') }}</span>
                <span class="font-mono font-semibold text-slate-900 dark:text-slate-100">{{ $transaction->transaction_no }}</span>
            </div>
            <div class="flex justify-between py-1 border-b border-slate-200 dark:border-slate-700/60">
                <span class="text-slate-500 dark:text-slate-400">{{ __('Student Name') }}</span>
                <span class="font-medium text-slate-900 dark:text-slate-100">{{ $transaction->user?->name }}</span>
            </div>
            <div class="flex justify-between py-1 items-baseline">
                <span class="text-slate-500 dark:text-slate-400">{{ __('Payable Amount') }}</span>
                <span class="text-base font-bold text-emerald-600 dark:text-emerald-400">₹{{ number_format((float) $transaction->amount, 2) }}</span>
            </div>
        </div>

        {{-- Warning Box --}}
        <div class="flex items-start gap-2.5 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900 text-left">
            <svg class="h-4 w-4 text-amber-600 shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <p class="text-[11px] text-amber-800 dark:text-amber-300 leading-snug">
                {{ __('Please do not refresh this page, close your browser, or click the back button while the payment is processing.') }}
            </p>
        </div>

        {{-- Auto-Submitting Form --}}
        <form id="ccavenue-redirect-form" method="POST" action="{{ $orderData['endpoint'] }}">
            <input type="hidden" name="encRequest" value="{{ $orderData['enc_request'] }}">
            <input type="hidden" name="access_code" value="{{ $orderData['access_code'] }}">
            <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-primary text-primary-foreground font-semibold text-xs shadow-md hover:bg-primary/90 transition-colors">
                {{ __('Click here if you are not redirected automatically in 5 seconds') }}
            </button>
        </form>

        <p class="text-[10px] text-slate-400 dark:text-slate-500">
            🔒 {{ __('Secured with PCI-DSS Level 1 & 256-bit encryption.') }}
        </p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!navigator.onLine) {
                alert("{{ __('You appear to be offline. A stable internet connection is required to proceed with CCAvenue payments.') }}");
                return;
            }
            setTimeout(function() {
                var form = document.getElementById('ccavenue-redirect-form');
                if (form) {
                    form.submit();
                }
            }, 600);
        });
    </script>
</body>
</html>

