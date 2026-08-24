<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\RbacService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('administrator can impersonate a regular user when setting is enabled', function () {
    Setting::set('system.allow_user_impersonation', '1');

    $admin = User::factory()->create(['name' => 'Root Administrator']);
    $admin->assignRole('Administrator');

    $targetUser = User::factory()->create(['name' => 'Regular Student']);
    $targetUser->assignRole('Student');

    $response = $this->actingAs($admin)->post(route('admin.impersonate', ['user' => $targetUser->id]));

    $team = $targetUser->personalTeam();
    $response->assertRedirect("/{$team->slug}/dashboard");

    $this->assertAuthenticatedAs($targetUser);
    expect(session()->has(ImpersonationService::SESSION_KEY))->toBeTrue();
    expect((int) session(ImpersonationService::SESSION_KEY))->toBe($admin->id);

    $log = AuditLog::where('event', 'user_impersonation_started')
        ->where('user_id', $admin->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
});

test('regular user cannot impersonate another user', function () {
    Setting::set('system.allow_user_impersonation', '1');

    $regularUser = User::factory()->create();
    $regularUser->assignRole('Student');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($regularUser)->post(route('admin.impersonate', ['user' => $targetUser->id]));

    $this->assertAuthenticatedAs($regularUser);
    expect(session()->has(ImpersonationService::SESSION_KEY))->toBeFalse();
});

test('administrator cannot impersonate when feature is disabled in settings', function () {
    Setting::set('system.allow_user_impersonation', '0');

    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.impersonate', ['user' => $targetUser->id]));

    $this->assertAuthenticatedAs($admin);
    expect(session()->has(ImpersonationService::SESSION_KEY))->toBeFalse();
});

test('administrator cannot impersonate self or deleted user', function () {
    Setting::set('system.allow_user_impersonation', '1');

    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var ImpersonationService $service */
    $service = app(ImpersonationService::class);

    // Self check
    expect($service->canImpersonate($admin, $admin))->toBeFalse();

    // Soft-deleted check
    $deletedUser = User::factory()->create();
    $deletedUser->delete();
    expect($service->canImpersonate($admin, $deletedUser))->toBeFalse();
});

test('administrator can leave impersonation and return to admin session', function () {
    $admin = User::factory()->create(['name' => 'Original Admin']);
    $admin->assignRole('Administrator');

    $targetUser = User::factory()->create(['name' => 'Target User']);

    // Start session
    $this->actingAs($admin);
    session()->put(ImpersonationService::SESSION_KEY, $admin->id);
    Auth::login($targetUser);

    $this->assertAuthenticatedAs($targetUser);

    $response = $this->post(route('admin.impersonate.leave'));

    $adminTeam = $admin->personalTeam();
    $response->assertRedirect("/{$adminTeam->slug}/users");

    $this->assertAuthenticatedAs($admin);
    expect(session()->has(ImpersonationService::SESSION_KEY))->toBeFalse();

    $log = AuditLog::where('event', 'user_impersonation_ended')
        ->where('user_id', $admin->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
});

test('admin can toggle impersonation setting from system settings page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.system')
        ->set('form.allow_user_impersonation', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('system.allow_user_impersonation'))->toBeFalse();
    expect(ImpersonationService::isAllowed())->toBeFalse();

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.system')
        ->set('form.allow_user_impersonation', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('system.allow_user_impersonation'))->toBeTrue();
    expect(ImpersonationService::isAllowed())->toBeTrue();
});
