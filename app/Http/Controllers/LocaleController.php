<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetAppLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Store the selected locale in the session (primary) and a long-lived
     * cookie (so logged-out visitors keep their choice) and redirect back.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetAppLocale::SUPPORTED)],
        ]);

        session(['locale' => $validated['locale']]);

        Cookie::queue('locale', $validated['locale'], 60 * 24 * 365);

        return redirect()->back();
    }
}
