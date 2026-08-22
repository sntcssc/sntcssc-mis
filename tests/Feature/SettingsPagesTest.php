<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Setting::flushCache();
});

/* -------------------------------------------------------------------------- */
/*  FileUploadService Unit & Integration Tests */
/* -------------------------------------------------------------------------- */

test('FileUploadService stores files with sanitized timestamp renaming', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('test_avatar.png', 100, 100);

    $path = FileUploadService::store(
        file: $file,
        folder: 'settings/general',
        prefix: 'site_logo'
    );

    expect($path)->toStartWith('settings/general/site_logo_')
        ->and($path)->toEndWith('.png');

    Storage::disk('public')->assertExists($path);
});

test('FileUploadService deletes old file on replacement', function () {
    Storage::fake('public');

    $oldFile = UploadedFile::fake()->image('old_logo.png', 100, 100);
    $oldPath = FileUploadService::store($oldFile, 'settings/general', 'old');
    Storage::disk('public')->assertExists($oldPath);

    $newFile = UploadedFile::fake()->image('new_logo.png', 100, 100);
    $newPath = FileUploadService::store($newFile, 'settings/general', 'new', oldPath: $oldPath);

    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertMissing($oldPath);
});

test('FileUploadService humanSize formats bytes correctly', function () {
    expect(FileUploadService::humanSize(500))->toBe('500.0 B')
        ->and(FileUploadService::humanSize(1024 * 512))->toBe('512.0 KB')
        ->and(FileUploadService::humanSize(1024 * 1024 * 5))->toBe('5.0 MB');
});

/* -------------------------------------------------------------------------- */
/*  General Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('general settings page renders and saves fields and logo upload', function () {
    Storage::fake('public');

    $logo = UploadedFile::fake()->image('site_logo.png', 200, 60);

    Livewire::test('pages::admin.settings.general')
        ->assertSee('General Settings')
        ->set('form.site_name', 'SNT Civil Services Centre')
        ->set('form.site_tagline', 'Excellence in Civil Services')
        ->set('form.site_email', 'contact@sntcssc.org')
        ->set('logoFile', $logo)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('general.site_name'))->toBe('SNT Civil Services Centre')
        ->and(Setting::get('general.site_tagline'))->toBe('Excellence in Civil Services')
        ->and(Setting::get('general.site_email'))->toBe('contact@sntcssc.org')
        ->and(Setting::get('general.site_logo'))->toStartWith('settings/general/site_logo_');

    Storage::disk('public')->assertExists(Setting::get('general.site_logo'));
});

/* -------------------------------------------------------------------------- */
/*  SEO Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('seo settings page renders and saves meta tags and og image', function () {
    Storage::fake('public');

    $ogImage = UploadedFile::fake()->image('og_card.jpg', 1200, 630);

    Livewire::test('pages::admin.settings.seo')
        ->assertSee('SEO & Meta Settings')
        ->set('form.meta_title', 'Custom SEO Title')
        ->set('form.meta_description', 'Custom Meta Description for SNT CSSC')
        ->set('form.canonical_url', 'https://sntcssc.in')
        ->set('ogImageFile', $ogImage)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('seo.meta_title'))->toBe('Custom SEO Title')
        ->and(Setting::get('seo.meta_description'))->toBe('Custom Meta Description for SNT CSSC')
        ->and(Setting::get('seo.canonical_url'))->toBe('https://sntcssc.in')
        ->and(Setting::get('seo.og_image'))->toStartWith('settings/seo/og_image_');

    Storage::disk('public')->assertExists(Setting::get('seo.og_image'));
});

/* -------------------------------------------------------------------------- */
/*  Appearance Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('appearance settings page renders and saves colors, font and assets', function () {
    Storage::fake('public');

    $dashLogo = UploadedFile::fake()->image('dash_logo.png', 180, 50);

    Livewire::test('pages::admin.settings.appearance')
        ->assertSee('Appearance & Theme Settings')
        ->set('form.primary_color', '#0ea5e9')
        ->set('form.dark_mode', 'dark')
        ->set('form.font_family', 'Plus Jakarta Sans')
        ->set('logoFile', $dashLogo)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('appearance.primary_color'))->toBe('#0ea5e9')
        ->and(Setting::get('appearance.dark_mode'))->toBe('dark')
        ->and(Setting::get('appearance.font_family'))->toBe('Plus Jakarta Sans')
        ->and(Setting::get('appearance.logo'))->toStartWith('settings/appearance/dashboard_logo_');

    Storage::disk('public')->assertExists(Setting::get('appearance.logo'));
});

/* -------------------------------------------------------------------------- */
/*  Email Provider Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('email settings page renders, saves configuration, and sends test email', function () {
    Mail::fake();

    Livewire::test('pages::admin.settings.email')
        ->assertSee('Email Provider & SMTP Settings')
        ->set('form.driver', 'smtp')
        ->set('form.smtp_host', 'mail.sntcssc.in')
        ->set('form.smtp_port', 465)
        ->set('form.smtp_username', 'mailer@sntcssc.in')
        ->set('form.smtp_password', 'secret-smtp-pass')
        ->set('form.from_address', 'notifications@sntcssc.in')
        ->set('form.from_name', 'SNT CSSC Notifications')
        ->set('form.send_to', 'admin@sntcssc.in')
        ->call('save')
        ->assertHasNoErrors()
        ->call('sendTestEmail')
        ->assertHasNoErrors();

    expect(Setting::get('email.driver'))->toBe('smtp')
        ->and(Setting::get('email.smtp_host'))->toBe('mail.sntcssc.in')
        ->and((int) Setting::get('email.smtp_port'))->toBe(465)
        ->and(Setting::get('email.from_address'))->toBe('notifications@sntcssc.in');
});

/* -------------------------------------------------------------------------- */
/*  Localization Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('localization settings page renders and saves date, time and locale standards', function () {
    Livewire::test('pages::admin.settings.localization')
        ->assertSee('Localization & Format Settings')
        ->set('form.language', 'bn')
        ->set('form.timezone', 'Asia/Kolkata')
        ->set('form.date_format', 'd-m-Y')
        ->set('form.time_format', 'H:i')
        ->set('form.currency_symbol', '₹')
        ->set('form.currency_code', 'INR')
        ->set('form.number_format', 'indian')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('localization.language'))->toBe('bn')
        ->and(Setting::get('localization.timezone'))->toBe('Asia/Kolkata')
        ->and(Setting::get('localization.date_format'))->toBe('d-m-Y')
        ->and(Setting::get('localization.time_format'))->toBe('H:i')
        ->and(Setting::get('localization.number_format'))->toBe('indian');
});

/* -------------------------------------------------------------------------- */
/*  Payment Gateway Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('payment gateway settings page renders and saves razorpay and phonepe configurations', function () {
    Livewire::test('pages::admin.settings.payment')
        ->assertSee('Payment Gateway Settings')
        ->set('form.enabled', true)
        ->set('form.driver', 'razorpay')
        ->set('form.currency', 'INR')
        ->set('form.razorpay_enabled', true)
        ->set('form.razorpay_key_id', 'rzp_test_1234567890')
        ->set('form.razorpay_key_secret', 'rzp_secret_abcdef')
        ->set('form.phonepe_enabled', true)
        ->set('form.phonepe_merchant_id', 'MERCHANT123')
        ->set('form.phonepe_mode', 'UAT')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('payment.enabled'))->toBeTrue()
        ->and(Setting::get('payment.driver'))->toBe('razorpay')
        ->and(Setting::get('payment.razorpay_enabled'))->toBeTrue()
        ->and(Setting::get('payment.razorpay_key_id'))->toBe('rzp_test_1234567890')
        ->and(Setting::get('payment.phonepe_merchant_id'))->toBe('MERCHANT123');
});

/* -------------------------------------------------------------------------- */
/*  SMS Gateway Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('sms gateway settings page renders and saves 2factor parameters and dispatches test sms', function () {
    Livewire::test('pages::admin.settings.sms')
        ->assertSee('SMS Gateway & OTP Settings')
        ->set('form.enabled', true)
        ->set('form.driver', '2factor')
        ->set('form.two_factor_api_key', '2factor-api-key-xyz')
        ->set('form.two_factor_sender_id', 'SNTCSS')
        ->set('form.otp_length', 6)
        ->set('form.otp_expiry_minutes', 10)
        ->set('testPhone', '+919876543210')
        ->call('save')
        ->assertHasNoErrors()
        ->call('sendTestSms')
        ->assertHasNoErrors();

    expect(Setting::get('sms.enabled'))->toBeTrue()
        ->and(Setting::get('sms.two_factor_sender_id'))->toBe('SNTCSS')
        ->and((int) Setting::get('sms.otp_length'))->toBe(6)
        ->and((int) Setting::get('sms.otp_expiry_minutes'))->toBe(10);
});

/* -------------------------------------------------------------------------- */
/*  System Settings Page Tests */
/* -------------------------------------------------------------------------- */

test('system settings page renders, saves flags and runs maintenance utilities', function () {
    Livewire::test('pages::admin.settings.system')
        ->assertSee('System & Server Settings')
        ->set('form.maintenance_mode', true)
        ->set('form.debug_mode', false)
        ->set('form.app_name', 'SNT CSSC Management Portal')
        ->set('form.max_upload_size', 25)
        ->set('form.cache_driver', 'redis')
        ->call('save')
        ->assertHasNoErrors()
        ->call('clearCache')
        ->assertHasNoErrors()
        ->call('linkStorage')
        ->assertHasNoErrors();

    expect(Setting::get('system.maintenance_mode'))->toBeTrue()
        ->and(Setting::get('system.app_name'))->toBe('SNT CSSC Management Portal')
        ->and((int) Setting::get('system.max_upload_size'))->toBe(25)
        ->and(Setting::get('system.cache_driver'))->toBe('redis');
});
