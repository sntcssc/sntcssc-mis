<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Setting::flushCache();
});

test('Setting::logoUrl() returns null by default when no logo is uploaded', function () {
    Setting::set('general.site_logo', null);
    Setting::set('appearance.logo', null);

    expect(Setting::logoUrl())->toBeNull();
});

test('Setting::faviconUrl() returns /favicon.ico by default with fallback', function () {
    Setting::set('general.site_favicon', null);
    Setting::set('appearance.icon', null);

    expect(Setting::faviconUrl(fallback: true))->toBe('/favicon.ico')
        ->and(Setting::faviconUrl(fallback: false))->toBeNull();
});

test('Setting::logoUrl() and faviconUrl() return proper public URLs when set', function () {
    Setting::set('general.site_logo', 'settings/general/site_logo_test.png');
    Setting::set('general.site_favicon', 'settings/general/site_favicon_test.png');

    expect(Setting::logoUrl())->toContain('settings/general/site_logo_test.png')
        ->and(Setting::faviconUrl())->toContain('settings/general/site_favicon_test.png');
});

test('Setting::logoUrl() and faviconUrl() fallback to appearance settings if general settings are blank', function () {
    Setting::set('general.site_logo', null);
    Setting::set('general.site_favicon', null);
    Setting::set('appearance.logo', 'settings/appearance/dashboard_logo_test.png');
    Setting::set('appearance.icon', 'settings/appearance/dashboard_icon_test.png');

    expect(Setting::logoUrl())->toContain('settings/appearance/dashboard_logo_test.png')
        ->and(Setting::faviconUrl())->toContain('settings/appearance/dashboard_icon_test.png');
});

test('Setting::appName(), siteName(), and copyrightText() return configured values', function () {
    Setting::set('general.app_name', 'Test Academy MIS');
    Setting::set('general.site_name', 'Test Academy');
    Setting::set('general.copyright_text', '© :year Test Academy. All rights reserved.');

    $currentYear = date('Y');

    expect(Setting::appName())->toBe('Test Academy MIS')
        ->and(Setting::siteName())->toBe('Test Academy')
        ->and(Setting::copyrightText())->toBe("© {$currentYear} Test Academy. All rights reserved.");
});

test('x-app-logo component renders default fallback and custom logo with app name beside it', function () {
    Setting::set('general.app_name', 'SNT CSSC MIS');
    Setting::set('general.site_logo', null);
    Setting::set('appearance.logo', null);

    $htmlDefault = Blade::render('<x-app-logo />');
    expect($htmlDefault)->toContain('<svg')
        ->and($htmlDefault)->toContain('SNT CSSC MIS')
        ->and($htmlDefault)->not->toContain('<img');

    Setting::set('general.site_logo', 'settings/general/brand_logo.png');

    $htmlCustom = Blade::render('<x-app-logo />');
    expect($htmlCustom)->toContain('<img')
        ->and($htmlCustom)->toContain('settings/general/brand_logo.png')
        ->and($htmlCustom)->toContain('SNT CSSC MIS');

    $htmlHideText = Blade::render('<x-app-logo :hideText="true" />');
    expect($htmlHideText)->toContain('<img')
        ->and($htmlHideText)->toContain('settings/general/brand_logo.png')
        ->and($htmlHideText)->not->toContain('text-foreground">SNT CSSC MIS</span>');
});

test('x-app-logo-icon component renders default zap badge and custom icon', function () {
    Setting::set('general.site_favicon', null);
    Setting::set('appearance.icon', null);

    $htmlDefault = Blade::render('<x-app-logo-icon />');
    expect($htmlDefault)->toContain('<svg')
        ->and($htmlDefault)->not->toContain('<img');

    Setting::set('general.site_favicon', 'settings/general/brand_favicon.png');

    $htmlCustom = Blade::render('<x-app-logo-icon />');
    expect($htmlCustom)->toContain('<img')
        ->and($htmlCustom)->toContain('settings/general/brand_favicon.png');
});

test('uploading logo and favicon in general settings synchronizes to appearance settings', function () {
    Storage::fake('public');

    $logo = UploadedFile::fake()->image('custom_site_logo.png', 300, 80);
    $favicon = UploadedFile::fake()->image('custom_site_favicon.png', 32, 32);

    Livewire::test('pages::admin.settings.general')
        ->set('logoFile', $logo)
        ->set('faviconFile', $favicon)
        ->call('save')
        ->assertHasNoErrors();

    $siteLogo = Setting::get('general.site_logo');
    $siteFavicon = Setting::get('general.site_favicon');
    $appearanceLogo = Setting::get('appearance.logo');
    $appearanceIcon = Setting::get('appearance.icon');

    expect($siteLogo)->not->toBeEmpty()
        ->and($appearanceLogo)->toBe($siteLogo)
        ->and($siteFavicon)->not->toBeEmpty()
        ->and($appearanceIcon)->toBe($siteFavicon)
        ->and(Setting::logoUrl())->toContain($siteLogo)
        ->and(Setting::faviconUrl())->toContain($siteFavicon);
});

test('uploading logo and icon in appearance settings synchronizes to general settings', function () {
    Storage::fake('public');

    $logo = UploadedFile::fake()->image('dashboard_header_logo.png', 300, 80);
    $icon = UploadedFile::fake()->image('dashboard_favicon_icon.png', 32, 32);

    Livewire::test('pages::admin.settings.appearance')
        ->set('logoFile', $logo)
        ->set('iconFile', $icon)
        ->call('save')
        ->assertHasNoErrors();

    $appearanceLogo = Setting::get('appearance.logo');
    $appearanceIcon = Setting::get('appearance.icon');
    $siteLogo = Setting::get('general.site_logo');
    $siteFavicon = Setting::get('general.site_favicon');

    expect($appearanceLogo)->not->toBeEmpty()
        ->and($siteLogo)->toBe($appearanceLogo)
        ->and($appearanceIcon)->not->toBeEmpty()
        ->and($siteFavicon)->toBe($appearanceIcon)
        ->and(Setting::logoUrl())->toContain($appearanceLogo)
        ->and(Setting::faviconUrl())->toContain($appearanceIcon);
});

test('admin profile preferences save and synchronize with system localization settings', function () {
    Livewire::test('pages::admin.profile')
        ->set('language', 'hi')
        ->set('timezone', 'Asia/Dubai')
        ->set('date_format', 'd/m/Y')
        ->set('time_format', 'H:i')
        ->call('savePreferences')
        ->assertHasNoErrors();

    expect(Setting::get('localization.language'))->toBe('hi')
        ->and(Setting::get('localization.timezone'))->toBe('Asia/Dubai')
        ->and(Setting::get('localization.date_format'))->toBe('d/m/Y')
        ->and(Setting::get('localization.time_format'))->toBe('H:i')
        ->and(session('locale'))->toBe('hi')
        ->and(app()->getLocale())->toBe('hi');
});

test('settings profile preferences save and synchronize with system localization settings', function () {
    Livewire::test('pages::settings.profile')
        ->set('language', 'bn')
        ->set('timezone', 'UTC')
        ->set('date_format', 'Y-m-d')
        ->set('time_format', 'h:i A')
        ->call('savePreferences')
        ->assertHasNoErrors();

    expect(Setting::get('localization.language'))->toBe('bn')
        ->and(Setting::get('localization.timezone'))->toBe('UTC')
        ->and(Setting::get('localization.date_format'))->toBe('Y-m-d')
        ->and(Setting::get('localization.time_format'))->toBe('h:i A')
        ->and(session('locale'))->toBe('bn')
        ->and(app()->getLocale())->toBe('bn');
});

test('user can upload and remove profile photo on admin profile page', function () {
    Storage::fake('public');

    $avatar = UploadedFile::fake()->image('my_avatar.png', 200, 200);

    Livewire::test('pages::admin.profile')
        ->set('avatarFile', $avatar)
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->avatar)->not->toBeEmpty()
        ->and($this->user->avatarUrl())->toContain($this->user->avatar);

    Storage::disk('public')->assertExists($this->user->avatar);

    Livewire::test('pages::admin.profile')
        ->call('removeAvatar')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->avatar)->toBeNull()
        ->and($this->user->avatarUrl())->toBeNull();
});

test('welcome landing page renders successfully with modern layout', function () {
    $response = $this->get('/');

    $response->assertStatus(200)
        ->assertSee('Management Information System');
});

test('welcome landing page renders Hindi and Bengali translations properly', function () {
    app()->setLocale('hi');
    $responseHi = $this->withSession(['locale' => 'hi'])->get('/');
    $responseHi->assertStatus(200)
        ->assertSee('प्रबंधन सूचना प्रणाली')
        ->assertSee('डिजिटल प्रवेश');

    app()->setLocale('bn');
    $responseBn = $this->withSession(['locale' => 'bn'])->get('/');
    $responseBn->assertStatus(200)
        ->assertSee('ম্যানেজমেন্ট ইনফরমেশন সিস্টেম')
        ->assertSee('ডিজিটাল ভর্তি');
});
