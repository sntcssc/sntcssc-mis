<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('users without activity.view permission are forbidden from accessing my activity page', function () {
    $user = User::factory()->create();
    $user->ensureCurrentTeam();

    $this->actingAs($user)
        ->get(route('admin.user-activity.index'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::admin.user-activity')
        ->assertStatus(403);
});

test('students with activity.view permission can access my activity and only see their own records', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $studentTeam = $student->ensureCurrentTeam();

    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->ensureCurrentTeam();

    // Create log for student
    $studentLog = AuditLog::create([
        'user_id' => $student->id,
        'team_id' => $studentTeam->id,
        'event' => 'login',
        'description' => 'Student secret login event',
        'ip_address' => '10.0.0.1',
    ]);

    // Create log for other user
    $otherLog = AuditLog::create([
        'user_id' => $otherUser->id,
        'team_id' => $otherTeam->id,
        'event' => 'login',
        'description' => 'Other user confidential action',
        'ip_address' => '10.0.0.2',
    ]);

    $this->actingAs($student)
        ->get(route('admin.user-activity.index', ['current_team' => $studentTeam->slug]))
        ->assertOk();

    Livewire::actingAs($student)
        ->test('pages::admin.user-activity')
        ->assertOk()
        ->assertSee('Student secret login event')
        ->assertDontSee('Other user confidential action')
        ->assertSet('isAdmin', false)
        ->assertSet('effectiveUserId', $student->id);
});

test('students cannot bypass scoping to view another users activity by tampering with userFilter', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $otherUser = User::factory()->create();

    AuditLog::create([
        'user_id' => $student->id,
        'event' => 'login',
        'description' => 'Student visible entry',
    ]);

    AuditLog::create([
        'user_id' => $otherUser->id,
        'event' => 'login',
        'description' => 'Hidden other user entry',
    ]);

    // Attempt to pass other user ID via query parameter / Livewire property
    Livewire::actingAs($student)
        ->test('pages::admin.user-activity', ['userFilter' => $otherUser->id])
        ->assertOk()
        ->assertSee('Student visible entry')
        ->assertDontSee('Hidden other user entry')
        ->assertSet('effectiveUserId', $student->id);
});

test('students cannot inspect another users audit log record', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $otherUser = User::factory()->create();

    $otherLog = AuditLog::create([
        'user_id' => $otherUser->id,
        'event' => 'profile_updated',
        'description' => 'Other user updated private settings',
    ]);

    expect(function () use ($student, $otherLog) {
        Livewire::actingAs($student)
            ->test('pages::admin.user-activity')
            ->call('inspect', $otherLog->id);
    })->toThrow(ModelNotFoundException::class);
});

test('admins can view any users activity and filter by specific users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $studentLog = AuditLog::create([
        'user_id' => $student->id,
        'event' => 'test_submission',
        'description' => 'Student submitted preliminary mock test',
    ]);

    $adminLog = AuditLog::create([
        'user_id' => $admin->id,
        'event' => 'system_update',
        'description' => 'Admin updated maintenance settings',
    ]);

    // When admin views all (userFilter = null)
    Livewire::actingAs($admin)
        ->test('pages::admin.user-activity')
        ->assertOk()
        ->assertSet('isAdmin', true)
        ->assertSee('Student submitted preliminary mock test')
        ->assertSee('Admin updated maintenance settings');

    // When admin filters specifically by student
    Livewire::actingAs($admin)
        ->test('pages::admin.user-activity')
        ->set('userFilter', $student->id)
        ->assertOk()
        ->assertSee('Student submitted preliminary mock test')
        ->assertDontSee('Admin updated maintenance settings');

    // Admin can inspect student log diff
    Livewire::actingAs($admin)
        ->test('pages::admin.user-activity')
        ->call('inspect', $studentLog->id)
        ->assertSet('selectedLogId', $studentLog->id)
        ->assertSee('Student submitted preliminary mock test');
});

test('sidebar renders my activity link for students with activity.view and hides it without permission', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $student->ensureCurrentTeam();

    $htmlWithPerm = (string) $this->actingAs($student)->blade("@include('layouts.app.sidebar')");
    expect($htmlWithPerm)->toContain(route('admin.user-activity.index'));

    $plainUser = User::factory()->create();
    $plainUser->ensureCurrentTeam();

    $htmlWithoutPerm = (string) $this->actingAs($plainUser)->blade("@include('layouts.app.sidebar')");
    expect($htmlWithoutPerm)->not->toContain(route('admin.user-activity.index'));
});

test('profile page shows my activity button only when user has activity.view permission', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $student->ensureCurrentTeam();

    Livewire::actingAs($student)
        ->test('pages::admin.profile')
        ->assertSeeHtml(route('admin.user-activity.index'));

    $plainUser = User::factory()->create();
    $plainUser->ensureCurrentTeam();

    Livewire::actingAs($plainUser)
        ->test('pages::admin.profile')
        ->assertDontSeeHtml(route('admin.user-activity.index'));
});
