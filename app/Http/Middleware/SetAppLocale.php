<?php

namespace App\Http\Middleware;

use App\Models\Language;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocale
{
    /** Fallback supported locales if database is not yet ready. */
    public const SUPPORTED = ['en', 'hi', 'bn'];

    /**
     * Get all currently supported active locale codes.
     *
     * @return array<int, string>
     */
    public static function getSupportedLocales(): array
    {
        try {
            if (Schema::hasTable('languages')) {
                $codes = Language::activeCached()->pluck('code')->all();
                if (! empty($codes)) {
                    return $codes;
                }
            }
        } catch (\Throwable) {
            // Migrations in progress
        }

        return self::SUPPORTED;
    }

    /**
     * Apply the user's chosen locale (session, falling back to a long-lived
     * cookie for logged-out visitors, falling back to database default) to the current request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = self::getSupportedLocales();

        $defaultLocale = (string) Setting::get('localization.language', config('app.locale', 'en'));
        $fallbackLocale = (string) Setting::get('localization.fallback_language', config('app.fallback_locale', 'en'));

        if (! empty($fallbackLocale)) {
            App::setFallbackLocale($fallbackLocale);
            config(['app.fallback_locale' => $fallbackLocale]);
        }

        $locale = $request->session()->get('locale') ?? $request->cookie('locale');

        if (! $locale || ! in_array($locale, $supported, true)) {
            $locale = in_array($defaultLocale, $supported, true) ? $defaultLocale : 'en';
        }

        App::setLocale($locale);
        config(['app.locale' => $locale]);

        $timezone = (string) Setting::get('localization.timezone');
        if (! empty($timezone)) {
            config(['app.timezone' => $timezone]);
            @date_default_timezone_set($timezone);
        }

        return $next($request);
    }
}
