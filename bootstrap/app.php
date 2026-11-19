<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\EnsurePhoneAndEmailVerified;
use App\Http\Middleware\EnsureStudentProfileComplete;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\SetAppLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\TrimStrings as AppTrimStrings;
use App\Http\Middleware\ValidateCaptchaMiddleware;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\TrimStrings as FrameworkTrimStrings;
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
        // Preserve trailing CRLF in WebRTC SDP payloads (see App\Http\Middleware\TrimStrings).
        $middleware->replace(FrameworkTrimStrings::class, AppTrimStrings::class);

        $middleware->web(append: [
            CheckMaintenanceMode::class,
            SetAppLocale::class,
            SetTeamUrlDefaults::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'verified' => EnsurePhoneAndEmailVerified::class,
            'student.profile.complete' => EnsureStudentProfileComplete::class,
            'auth.api_key' => AuthenticateApiKey::class,
            'api.scope' => RequireApiScope::class,
            'captcha' => ValidateCaptchaMiddleware::class,
        ]);

        // Meeting sync polling is a JSON XHR authenticated via session + controller guard.
        // Signal POSTs keep CSRF protection: the client always sends the X-CSRF-TOKEN header.
        // Payment gateway callbacks (e.g. CCAvenue) come directly from external gateway servers.
        $middleware->validateCsrfTokens(except: [
            'meetings/*/sync',
            '*/meetings/*/sync',
            'payments/ccavenue/*',
            'api/v1/payments/ccavenue/*',
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
