<?php

use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    Setting::flushCache();
});

/* -------------------------------------------------------------------------- */
/*  Maintenance Mode Middleware */
/* -------------------------------------------------------------------------- */

test('maintenance mode off allows normal access', function () {
    Setting::updateOrCreate(
        ['key' => 'system.maintenance_mode'],
        ['value' => '0', 'group' => 'system', 'type' => 'boolean', 'label' => 'Maintenance Mode', 'status' => true]
    );
    Setting::flushCache();

    $this->get('/')->assertStatus(200);
});

test('maintenance mode on returns 503 for guests', function () {
    Setting::updateOrCreate(
        ['key' => 'system.maintenance_mode'],
        ['value' => '1', 'group' => 'system', 'type' => 'boolean', 'label' => 'Maintenance Mode', 'status' => true]
    );
    Setting::updateOrCreate(
        ['key' => 'system.maintenance_token'],
        ['value' => 'test-secret', 'group' => 'system', 'type' => 'string', 'label' => 'Maintenance Token', 'status' => true]
    );
    Setting::flushCache();

    $this->get('/')->assertStatus(503);
});

test('maintenance mode secret token in query string grants access via cookie redirect', function () {
    Setting::updateOrCreate(
        ['key' => 'system.maintenance_mode'],
        ['value' => '1', 'group' => 'system', 'type' => 'boolean', 'label' => 'Maintenance Mode', 'status' => true]
    );
    Setting::updateOrCreate(
        ['key' => 'system.maintenance_token'],
        ['value' => 'bypass-me', 'group' => 'system', 'type' => 'string', 'label' => 'Maintenance Token', 'status' => true]
    );
    Setting::flushCache();

    $this->get('/?secret=bypass-me')->assertRedirect('/');
});

test('maintenance mode allows admin routes when authenticated', function () {
    $this->actingAs($this->user);

    Setting::updateOrCreate(
        ['key' => 'system.maintenance_mode'],
        ['value' => '1', 'group' => 'system', 'type' => 'boolean', 'label' => 'Maintenance Mode', 'status' => true]
    );
    Setting::flushCache();

    // Admin routes are exempt from maintenance mode check
    $this->get(route('profile.edit'))->assertStatus(200);
});

/* -------------------------------------------------------------------------- */
/*  System Settings Page (Livewire) */
/* -------------------------------------------------------------------------- */

test('system settings page renders without errors', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::admin.settings.system')
        ->assertOk();
});

test('system settings toggles maintenance mode and saves token', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::admin.settings.system')
        ->set('form.maintenance_mode', true)
        ->set('form.maintenance_token', 'my-secret-token')
        ->call('save')
        ->assertDispatched('toast');

    expect((bool) Setting::get('system.maintenance_mode'))->toBeTrue();
    expect(Setting::get('system.maintenance_token'))->toBe('my-secret-token');
});

test('system settings regenerate token changes the token value', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::admin.settings.system')
        ->set('form.maintenance_token', 'original-token')
        ->call('regenerateToken')
        ->assertSet('form.maintenance_token', fn ($value) => $value !== 'original-token' && strlen($value) > 10);
});
