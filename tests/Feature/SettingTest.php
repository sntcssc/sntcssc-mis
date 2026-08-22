<?php

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (! Schema::hasTable('settings')) {
        $this->markTestSkipped('settings table has not been migrated.');
    }

    Setting::flushCache();
});

test('settings seeder creates every expected group', function () {
    $this->seed(SettingsSeeder::class);

    foreach (['general', 'appearance', 'seo', 'localization', 'system', 'sms', 'payment', 'email'] as $group) {
        expect(Setting::where('group', $group)->count())->toBeGreaterThan(0);
    }

    expect(Setting::count())->toBeGreaterThanOrEqual(60);

    foreach ([
        'general.site_name',
        'general.site_tagline',
        'general.site_description',
        'general.app_name',
        'general.title',
        'general.site_logo',
        'general.site_favicon',
        'general.site_campus',
        'general.site_email',
        'general.site_mobile',
        'general.site_phone',
        'general.site_address',
        'general.site_timing',
        'general.site_open_days',
        'general.copyright_text',
        'appearance.logo',
        'appearance.icon',
        'seo.meta_title',
        'seo.meta_description',
        'seo.meta_keywords',
        'localization.language',
        'localization.date_format',
        'localization.time_format',
        'localization.currency_symbol',
        'system.maintenance_mode',
        'system.app_name',
        'system.app_version',
        'system.developed_by',
        'system.developer_contact',
        'system.developer_github',
        'system.developer_website',
        'sms.enabled',
        'sms.driver',
        'sms.two_factor_api_key',
        'sms.otp_length',
        'payment.driver',
        'payment.razorpay_key_secret',
        'payment.phonepe_salt_key',
        'email.driver',
        'email.smtp_host',
        'email.smtp_password',
        'email.from_address',
        'email.send_to',
    ] as $key) {
        expect(Setting::where('key', $key)->exists())->toBeTrue("Missing setting: {$key}");
    }
});

test('values are returned cast to their type', function () {
    $this->seed(SettingsSeeder::class);

    expect(Setting::get('general.site_name'))->toBeString();
    expect(Setting::get('system.maintenance_mode'))->toBeBool();
    expect(Setting::get('sms.otp_length'))->toBeInt(6);
    expect(Setting::get('localization.language'))->toBe('en');
});

test('set() updates a value and refreshes the cache', function () {
    $this->seed(SettingsSeeder::class);

    Setting::set('general.site_name', 'SNT CSSC Academy');

    $setting = Setting::withTrashed()->firstWhere('key', 'general.site_name');

    expect(Setting::get('general.site_name'))->toBe('SNT CSSC Academy')
        ->and($setting->updated_by)->toBeNull();
});

test('set() creates a missing setting on the fly', function () {
    Setting::set('general.new_flag', 'hello');

    expect(Setting::get('general.new_flag'))->toBe('hello');
});

test('secret values are encrypted at rest and readable through the model', function () {
    $this->seed(SettingsSeeder::class);

    Setting::set('email.smtp_password', 'super-secret-pass');

    $raw = DB::table('settings')->where('key', 'email.smtp_password')->value('value');

    expect($raw)->not->toBe('super-secret-pass')
        ->and(Crypt::decryptString($raw))->toBe('super-secret-pass')
        ->and(Setting::get('email.smtp_password'))->toBe('super-secret-pass');
});

test('disabled settings fall back to the default', function () {
    $this->seed(SettingsSeeder::class);

    Setting::where('key', 'general.site_name')->update(['status' => false]);
    Setting::flushCache();

    expect(Setting::get('general.site_name', 'fallback'))->toBe('fallback');
});

test('seeder is idempotent and preserves configured values', function () {
    $this->seed(SettingsSeeder::class);

    Setting::set('general.site_email', 'configured@sntcssc.in');

    $this->seed(SettingsSeeder::class);

    expect(Setting::get('general.site_email'))->toBe('configured@sntcssc.in');
});

test('database settings override the app and mail config', function () {
    $this->seed(SettingsSeeder::class);

    Setting::set('general.app_name', 'DB App Name');
    Setting::set('email.driver', 'smtp');
    Setting::set('email.smtp_host', 'smtp.example.test');
    Setting::set('email.smtp_port', 465);
    Setting::set('email.smtp_encryption', 'none');

    // Re-apply like the service provider does on boot.
    $provider = new SettingsServiceProvider(app());
    $reflected = new ReflectionMethod($provider, 'applySettingsToConfig');
    $reflected->setAccessible(true);
    $reflected->invoke($provider);

    expect(config('app.name'))->toBe('DB App Name')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.encryption'))->toBeNull();
});
