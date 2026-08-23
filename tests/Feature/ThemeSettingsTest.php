<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\ThemePresets;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Setting::flushCache();
});

/* -------------------------------------------------------------------------- */
/*  ThemePresets Unit Tests */
/* -------------------------------------------------------------------------- */

test('ThemePresets::presets returns 8 presets with required keys', function () {
    $presets = ThemePresets::presets();

    expect($presets)->toHaveCount(8);

    foreach ($presets as $preset) {
        expect($preset)->toHaveKeys(['name', 'colors', 'dark']);
    }
});

test('ThemePresets::generateCss returns non-empty CSS string with :root block', function () {
    $css = ThemePresets::generateCss();

    expect($css)->toBeString()
        ->toContain(':root')
        ->toContain('--primary');
});

test('ThemePresets::generateCss generates dark variant when dark colors set', function () {
    $css = ThemePresets::generateCss();

    expect($css)->toContain('.dark');
});

test('ThemePresets::get returns a valid preset by key', function () {
    $preset = ThemePresets::get('emerald');

    expect($preset)->toHaveKey('name')
        ->toHaveKey('colors');
});

test('ThemePresets::get returns null for an invalid key', function () {
    $preset = ThemePresets::get('nonexistent-preset-key');

    expect($preset)->toBeNull();
});

/* -------------------------------------------------------------------------- */
/*  Appearance Settings Page (Livewire) */
/* -------------------------------------------------------------------------- */

test('appearance settings page renders and loads current preset', function () {
    Livewire::test('pages::admin.settings.appearance')
        ->assertSee('Color Combination Presets')
        ->assertOk();
});

test('appearance page applyPreset updates appearance settings', function () {
    Livewire::test('pages::admin.settings.appearance')
        ->call('applyPreset', 'emerald')
        ->assertDispatched('toast');

    expect(Setting::get('appearance.theme_preset'))->toBe('emerald');
});

test('appearance page save persists custom colour and font settings', function () {
    Livewire::test('pages::admin.settings.appearance')
        ->set('form.primary_color', '#2563eb')
        ->set('form.radius', '0.75rem')
        ->call('save')
        ->assertDispatched('toast');

    expect(Setting::get('appearance.primary_color'))->toBe('#2563eb');
    expect(Setting::get('appearance.radius'))->toBe('0.75rem');
});
