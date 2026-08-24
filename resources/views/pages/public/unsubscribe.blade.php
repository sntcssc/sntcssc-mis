<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('partials.head', ['title' => __('Unsubscribe Preferences')])
    </head>
    <body class="min-h-full bg-background text-foreground antialiased selection:bg-primary selection:text-primary-foreground flex flex-col justify-center items-center p-4">
        <div class="w-full max-w-md bg-card border border-border rounded-2xl shadow-xl p-6 sm:p-8 space-y-5 text-center">
            <div class="flex justify-center">
                <x-app-logo :href="route('home')"/>
            </div>

            @if (! $subscriber)
                <div class="space-y-3">
                    <x-icon name="alert-triangle" class="h-10 w-10 text-amber-500 mx-auto"/>
                    <h1 class="text-lg font-bold text-foreground">{{ __('Invalid or Expired Link') }}</h1>
                    <p class="text-xs text-muted-foreground">{{ __('The unsubscribe link you followed is invalid or has expired.') }}</p>
                    <a href="{{ route('home') }}" class="inline-block text-xs font-semibold text-primary hover:underline mt-2">{{ __('Return to Home') }}</a>
                </div>
            @elseif ($subscriber->isUnsubscribed() || session('success'))
                <div class="space-y-3">
                    <x-icon name="check-circle-2" class="h-10 w-10 text-emerald-500 mx-auto"/>
                    <h1 class="text-lg font-bold text-foreground">{{ __('Successfully Unsubscribed') }}</h1>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('You have been unsubscribed and will no longer receive notifications on :contact.', ['contact' => $subscriber->email ?: $subscriber->phone]) }}
                    </p>
                    <div class="pt-2">
                        <a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-semibold shadow-xs hover:bg-primary/90 transition-colors">
                            <x-icon name="arrow-left" class="h-3.5 w-3.5"/>
                            <span>{{ __('Return to Home') }}</span>
                        </a>
                    </div>
                </div>
            @else
                <div class="space-y-3 text-left">
                    <div class="text-center">
                        <h1 class="text-lg font-bold text-foreground">{{ __('Unsubscribe Confirmation') }}</h1>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ __('Confirm you want to stop receiving updates on :contact', ['contact' => $subscriber->email ?: $subscriber->phone]) }}
                        </p>
                    </div>

                    <form action="{{ route('unsubscribe.process', $token) }}" method="POST" class="space-y-4 pt-2">
                        @csrf
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-foreground block">{{ __('Reason (Optional)') }}</label>
                            <select name="reason" class="w-full py-2 px-3 text-xs bg-background rounded-lg border border-border text-foreground">
                                <option value="">{{ __('Please select a reason...') }}</option>
                                <option value="too_frequent">{{ __('Too many messages / emails') }}</option>
                                <option value="no_longer_aspirant">{{ __('No longer pursuing Civil Services examination') }}</option>
                                <option value="found_alternative">{{ __('Found alternative sources') }}</option>
                                <option value="other">{{ __('Other reason') }}</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-2 pt-2">
                            <button type="submit" class="w-full py-2.5 rounded-xl bg-destructive text-destructive-foreground text-xs font-semibold shadow-xs hover:bg-destructive/90 transition-colors cursor-pointer">
                                {{ __('Confirm Unsubscribe') }}
                            </button>
                            <a href="{{ route('home') }}" class="w-full py-2 rounded-xl border border-border text-center text-xs font-medium text-foreground hover:bg-secondary transition-colors">
                                {{ __('Cancel & Keep Subscription') }}
                            </a>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </body>
</html>