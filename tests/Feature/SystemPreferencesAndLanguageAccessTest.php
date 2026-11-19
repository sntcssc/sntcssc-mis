<?php

use App\Http\Middleware\SetAppLocale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Super Administrator', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web']);

    Setting::flushCache();
    Setting::set('localization.language', 'en');
    Setting::set('localization.timezone', 'Asia/Kolkata');
    Setting::set('localization.date_format', 'd M Y');
    Setting::set('localization.time_format', 'h:i A');
    Setting::flushCache();
});

test('students and non-admin users cannot see system preferences card on profile settings', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $this->actingAs($student);

    Livewire::test('pages::settings.profile')
        ->assertDontSee('System Preferences')
        ->assertDontSee('Timezone')
        ->assertDontSee('Date Format')
        ->assertDontSee('Time Format')
        ->assertSee('Language Preference')
        ->assertSee('Save Language');
});

test('students can change their language preference for their own account without modifying system settings', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $this->actingAs($student);

    Livewire::test('pages::settings.profile')
        ->set('language', 'bn')
        ->call('saveLanguage')
        ->assertHasNoErrors();

    // Verify language changed for this student user
    expect($student->fresh()->getPreference('locale'))->toBe('bn')
        ->and(session('locale'))->toBe('bn')
        ->and(app()->getLocale())->toBe('bn');

    // Verify the system-wide default language remains 'en' and was not overwritten
    expect(Setting::get('localization.language'))->toBe('en');
});

test('non-admin users cannot modify system timezone or date formats via savePreferences', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $this->actingAs($student);

    Livewire::test('pages::settings.profile')
        ->set('language', 'hi')
        ->set('timezone', 'America/New_York')
        ->set('date_format', 'Y-m-d')
        ->set('time_format', 'H:i')
        ->call('savePreferences')
        ->assertHasNoErrors();

    // Student's language is updated
    expect($student->fresh()->getPreference('locale'))->toBe('hi')
        ->and(session('locale'))->toBe('hi');

    // System preferences remain unchanged
    expect(Setting::get('localization.timezone'))->toBe('Asia/Kolkata')
        ->and(Setting::get('localization.date_format'))->toBe('d M Y')
        ->and(Setting::get('localization.time_format'))->toBe('h:i A')
        ->and(Setting::get('localization.language'))->toBe('en');
});

test('administrators can access and update system preferences from settings profile', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $this->actingAs($admin);

    Livewire::test('pages::settings.profile')
        ->assertSee('System Preferences')
        ->assertSee('Timezone')
        ->assertSee('Date Format')
        ->assertSee('Time Format')
        ->set('language', 'hi')
        ->set('timezone', 'Asia/Dubai')
        ->set('date_format', 'd/m/Y')
        ->set('time_format', 'H:i')
        ->call('savePreferences')
        ->assertHasNoErrors();

    // System preferences are updated
    expect(Setting::get('localization.timezone'))->toBe('Asia/Dubai')
        ->and(Setting::get('localization.date_format'))->toBe('d/m/Y')
        ->and(Setting::get('localization.time_format'))->toBe('H:i');

    // Admin user's language is updated for that user without mutating the system default
    expect($admin->fresh()->getPreference('locale'))->toBe('hi')
        ->and(session('locale'))->toBe('hi')
        ->and(Setting::get('localization.language'))->toBe('en');
});

test('administrators can access and update system preferences from admin profile', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $this->actingAs($admin);

    Livewire::test('pages::admin.profile')
        ->assertSee('System Preferences')
        ->assertSee('Timezone')
        ->assertSee('Date Display Format')
        ->assertSee('Time Display Format')
        ->set('language', 'bn')
        ->set('timezone', 'UTC')
        ->set('date_format', 'jS F Y')
        ->set('time_format', 'H:i')
        ->call('savePreferences')
        ->assertHasNoErrors();

    // System preferences are updated
    expect(Setting::get('localization.timezone'))->toBe('UTC')
        ->and(Setting::get('localization.date_format'))->toBe('jS F Y')
        ->and(Setting::get('localization.time_format'))->toBe('H:i');

    // Admin user's language is updated for that user without mutating the system default
    expect($admin->fresh()->getPreference('locale'))->toBe('bn')
        ->and(session('locale'))->toBe('bn')
        ->and(Setting::get('localization.language'))->toBe('en');
});

test('locale controller switches language for authenticated user and preserves system setting', function () {
    $user = User::factory()->create();
    $user->assignRole('Student');

    $this->actingAs($user);

    $response = $this->post(route('locale.switch'), [
        'locale' => 'hi',
    ]);

    $response->assertRedirect();
    expect(session('locale'))->toBe('hi')
        ->and($user->fresh()->getPreference('locale'))->toBe('hi')
        ->and(Setting::get('localization.language'))->toBe('en');
});

test('SetAppLocale middleware prioritizes authenticated user preference', function () {
    $user = User::factory()->create([
        'preferences' => ['locale' => 'bn'],
    ]);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $middleware = new SetAppLocale;
    $middleware->handle($request, function ($req) {
        expect(app()->getLocale())->toBe('bn')
            ->and(config('app.locale'))->toBe('bn');

        return response('ok');
    });

    expect(Setting::get('localization.language'))->toBe('en');
});
