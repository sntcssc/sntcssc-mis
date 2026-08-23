<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! Schema::hasTable('settings')) {
                return $next($request);
            }
        } catch (\Throwable) {
            return $next($request);
        }

        $maintenanceEnabled = (bool) Setting::get('system.maintenance_mode', false);

        if (! $maintenanceEnabled) {
            return $next($request);
        }

        $secret = (string) (Setting::get('system.maintenance_secret') ?: Setting::get('system.maintenance_token', ''));

        // 1. Check Query Parameter Bypass Token
        if (! empty($secret) && $request->query('secret') === $secret) {
            Cookie::queue('maintenance_bypass', $secret, 60 * 24 * 7); // 7 days

            return redirect()->to($request->url());
        }

        // 2. Check Bypass Cookie
        if (! empty($secret) && $request->cookie('maintenance_bypass') === $secret) {
            return $next($request);
        }

        // 3. Allow Admin Panel & Auth Routes so admins can manage the system
        if ($request->is('admin*') || $request->is('login*') || $request->is('logout*') || $request->is('two-factor-challenge*')) {
            return $next($request);
        }

        // 4. Logged-in admin users can bypass
        if (Auth::check()) {
            return $next($request);
        }

        $message = (string) Setting::get('system.maintenance_message', __('The application is currently undergoing scheduled maintenance. Please check back shortly.'));

        return response()->view('errors.503', [
            'message' => $message,
            'secretConfigured' => ! empty($secret),
        ], 503);
    }
}
