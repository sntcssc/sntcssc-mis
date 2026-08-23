<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        Event::subscribe(LogAuthenticationEvents::class);
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
