<?php

use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use App\Services\TranslationService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Language::flushCache();
    Setting::flushCache();
});

/* -------------------------------------------------------------------------- */
/*  Language Model Unit & Feature Tests */
/* -------------------------------------------------------------------------- */

test('Language model can be created and queried', function () {
    $lang = Language::create([
        'code' => 'ta',
        'name' => 'Tamil',
        'native_name' => 'தமிழ்',
        'direction' => 'ltr',
        'flag' => '🇮🇳',
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 5,
    ]);

    expect($lang->exists)->toBeTrue();
    expect(Language::active()->pluck('code')->all())->toContain('ta');
});

test('Language::allCached returns collection and caches results', function () {
    Language::create([
        'code' => 'mr',
        'name' => 'Marathi',
        'native_name' => 'मराठी',
        'direction' => 'ltr',
        'is_active' => true,
    ]);

    $cached = Language::allCached();

    expect($cached->pluck('code')->all())->toContain('mr');
});

/* -------------------------------------------------------------------------- */
/*  TranslationService Tests */
/* -------------------------------------------------------------------------- */

test('TranslationService can read translation strings for existing locales', function () {
    $translations = TranslationService::getTranslations('en');

    expect($translations)->toBeArray();
});

test('TranslationService can set and delete translation keys', function () {
    $locale = 'en';
    $key = 'test_sample_key_'.uniqid();
    $value = 'Sample Translation Value';

    TranslationService::setTranslation($locale, $key, $value);
    $translations = TranslationService::getTranslations($locale);

    expect($translations)->toHaveKey($key)
        ->and($translations[$key])->toBe($value);

    // Clean up
    TranslationService::deleteTranslation($locale, $key);
    $updated = TranslationService::getTranslations($locale);

    expect($updated)->not->toHaveKey($key);
});

/* -------------------------------------------------------------------------- */
/*  Localization Settings Page (Livewire) */
/* -------------------------------------------------------------------------- */

test('localization settings page renders without errors', function () {
    Livewire::test('pages::admin.settings.localization')
        ->assertOk()
        ->assertSee('Localization');
});

test('localization settings save persists configuration', function () {
    Livewire::test('pages::admin.settings.localization')
        ->set('form.timezone', 'Asia/Kolkata')
        ->set('form.currency_code', 'INR')
        ->call('save')
        ->assertDispatched('toast');

    expect(Setting::get('localization.timezone'))->toBe('Asia/Kolkata');
    expect(Setting::get('localization.currency_code'))->toBe('INR');
});

test('localization page can create a new language', function () {
    Livewire::test('pages::admin.settings.localization')
        ->set('languageForm.code', 'gu')
        ->set('languageForm.name', 'Gujarati')
        ->set('languageForm.native_name', 'ગુજરાતી')
        ->set('languageForm.direction', 'ltr')
        ->set('languageForm.flag', '🇮🇳')
        ->set('languageForm.is_active', true)
        ->set('languageForm.is_default', false)
        ->set('languageForm.sort_order', 10)
        ->call('saveLanguage')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('languages', [
        'code' => 'gu',
        'name' => 'Gujarati',
    ]);

    if (file_exists(base_path('lang/gu.json'))) {
        unlink(base_path('lang/gu.json'));
    }
});

test('localization page can toggle language active status', function () {
    $lang = Language::create([
        'code' => 'te',
        'name' => 'Telugu',
        'native_name' => 'తెలుగు',
        'direction' => 'ltr',
        'is_active' => true,
    ]);

    Livewire::test('pages::admin.settings.localization')
        ->call('toggleLanguageStatus', $lang->id)
        ->assertDispatched('toast');

    expect($lang->fresh()->is_active)->toBeFalse();
});
