<?php

use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\EnsurePhoneAndEmailVerified;
use App\Http\Middleware\SetAppLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',

    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            CheckMaintenanceMode::class,
            SetAppLocale::class,
            SetTeamUrlDefaults::class,
        ]);

        $middleware->alias([
            'verified' => EnsurePhoneAndEmailVerified::class,
        ]);

        // Signal & sync are JSON XHR endpoints authenticated via session + controller guard.
        // Exempting from CSRF prevents 419 → apparent 404 when the browser omits the session cookie.
        $middleware->validateCsrfTokens(except: [
            'meetings/*/signal',
            '*/meetings/*/signal',
            'meetings/*/sync',
            '*/meetings/*/sync',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => __('A record with this information already exists.'),
                    'errors' => ['duplicate' => [__('A duplicate entry was detected.')]],
                ], 422);
            }

            return back()->withInput()->withErrors([
                'general' => __('A record with this information already exists in the system.'),
            ]);
        });
    })->create();
