<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Pusher\Pusher;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerBladeDirectives();
        $this->registerBroadcasters();

        Gate::before(function ($user, $ability) {

            if ($user->hasRole('Super Administrator')) {
                return true;
            }
        });
    }

    /**
     * Register resilient Reverb / WebSocket broadcasters with graceful fallback.
     */
    protected function registerBroadcasters(): void
    {
        Broadcast::extend('reverb', function ($app, $config) {
            if (class_exists(Pusher::class)) {
                $guzzleClient = new GuzzleClient(
                    array_merge(
                        [
                            'connect_timeout' => 10,
                            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                            'timeout' => 30,
                        ],
                        $config['client_options'] ?? [],
                    )
                );

                $pusher = new Pusher(
                    $config['key'] ?? '',
                    $config['secret'] ?? '',
                    $config['app_id'] ?? '',
                    $config['options'] ?? [],
                    $guzzleClient,
                );

                return new PusherBroadcaster($pusher, $config['jsonp'] ?? false);
            }

            return new NullBroadcaster;
        });
    }

    /**
     * Register Blade directives for localization formatting.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('formatDate', function ($expression) {
            return "<?php echo \App\Support\Format::date($expression); ?>";
        });

        Blade::directive('formatTime', function ($expression) {
            return "<?php echo \App\Support\Format::time($expression); ?>";
        });

        Blade::directive('formatDateTime', function ($expression) {
            return "<?php echo \App\Support\Format::dateTime($expression); ?>";
        });

        Blade::directive('formatCurrency', function ($expression) {
            return "<?php echo \App\Support\Format::currency($expression); ?>";
        });

        Blade::directive('formatNumber', function ($expression) {
            return "<?php echo \App\Support\Format::number($expression); ?>";
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
