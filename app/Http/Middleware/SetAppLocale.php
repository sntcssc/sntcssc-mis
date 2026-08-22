<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocale
{
    /** Locales selectable from the header language switcher. */
    public const SUPPORTED = ['en', 'hi', 'bn'];

    /**
     * Apply the user's chosen locale (session, falling back to a long-lived
     * cookie for logged-out visitors) to the current request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale') ?? $request->cookie('locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
