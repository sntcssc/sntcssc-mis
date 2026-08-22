<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Applies database settings over the framework configuration at runtime so
 * values managed from the (future) settings UI — app name, mail transport,
 * etc. — actually drive the application.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole() && ! $this->shouldBootForConsole()) {
            return;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $this->applySettingsToConfig();
        } catch (Throwable) {
            // Fresh installs / migrations in progress — fall back to .env config.
        }
    }

    /**
     * Boot for console commands that serve traffic or send mail; skip purely
     * administrative commands (migrate, db:wipe, …) to avoid extra queries.
     */
    protected function shouldBootForConsole(): bool
    {
        return in_array($_SERVER['argv'][1] ?? null, [
            'serve', 'octane:start', 'queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'test', 'pest',
        ], true);
    }

    protected function applySettingsToConfig(): void
    {
        $get = fn (string $key, mixed $default = null): mixed => Setting::get($key, $default);

        // Application identity + locale default.
        config([
            'app.name' => $get('general.app_name', config('app.name')),
            'app.locale' => $get('localization.language', config('app.locale')),
        ]);

        // Mail transport.
        $driver = $get('email.driver');

        if (in_array($driver, ['log', 'smtp', 'sendmail', 'array', 'failover'], true)) {
            config(['mail.default' => $driver]);
        }

        if ($driver === 'smtp') {
            $encryption = $get('email.smtp_encryption', 'tls');
            $encryption = ($encryption === 'none') ? null : $encryption;

            config([
                'mail.mailers.smtp.host' => $get('email.smtp_host', config('mail.mailers.smtp.host')),
                'mail.mailers.smtp.port' => $get('email.smtp_port', config('mail.mailers.smtp.port')),
                'mail.mailers.smtp.username' => $get('email.smtp_username', config('mail.mailers.smtp.username')),
                'mail.mailers.smtp.password' => $get('email.smtp_password', config('mail.mailers.smtp.password')),
                'mail.mailers.smtp.encryption' => $encryption,
            ]);
        }

        if ($fromAddress = $get('email.from_address')) {
            config(['mail.from.address' => $fromAddress]);
        }

        if ($fromName = $get('email.from_name')) {
            config(['mail.from.name' => $fromName]);
        }
    }
}
