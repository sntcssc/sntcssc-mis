<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RbacService;
use App\Services\RolePermissionExportService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('rbac service seeds standard roles and grouped permissions', function () {
    expect(Role::where('name', 'Super Administrator')->exists())->toBeTrue()
        ->and(Role::where('name', 'Administrator')->exists())->toBeTrue()
        ->and(Permission::where('name', 'users.view')->exists())->toBeTrue()
        ->and(Permission::where('name', 'roles.view')->exists())->toBeTrue();
});

test('custom role can be created, cloned, and deleted', function () {
    $role = RbacService::createRole([
        'name' => 'Lab Assistant',
        'description' => 'Oversees computer lab systems',
        'color' => 'blue',
        'permissions' => ['users.view', 'attendance.manage'],
    ]);

    expect($role->name)->toBe('Lab Assistant')
        ->and($role->hasPermissionTo('users.view', 'web'))->toBeTrue()
        ->and($role->is_system)->toBeFalse();

    // Clone
    $cloned = RbacService::cloneRole($role, 'Senior Lab Assistant');
    expect($cloned->name)->toBe('Senior Lab Assistant')
        ->and($cloned->hasPermissionTo('users.view', 'web'))->toBeTrue();

    // Delete
    expect(RbacService::deleteRole($role))->toBeTrue()
        ->and(Role::where('name', 'Lab Assistant')->exists())->toBeFalse();
});

test('system roles cannot be deleted', function () {
    $superAdmin = Role::where('name', 'Super Administrator')->first();

    expect(fn () => RbacService::deleteRole($superAdmin))->toThrow(ValidationException::class);
});

test('dynamic permissions can be created, updated, and deleted', function () {
    $perm = RbacService::createPermission('library.books_issue', 'Library', 'Issue library books to students');

    expect($perm->name)->toBe('library.books_issue')
        ->and($perm->module)->toBe('Library');

    RbacService::updatePermission($perm, 'library.books_manage', 'Library', 'Manage library catalog');
    expect($perm->fresh()->name)->toBe('library.books_manage');

    RbacService::deletePermission($perm);
    expect(Permission::where('name', 'library.books_manage')->exists())->toBeFalse();
});

test('roles livewire component interacts properly with permissions toggles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $role = Role::where('name', 'Faculty')->first();

    Livewire::actingAs($admin)
        ->test('pages::admin.roles')
        ->assertOk()
        ->assertSee('Roles & Permissions Matrix')
        ->set('activeRoleId', $role->id)
        ->call('togglePermission', 'users.view')
        ->assertOk();
});

test('my activity component displays user timeline', function () {
    $user = User::factory()->create();

    AuditLogService::log(
        event: 'login',
        description: 'Test login event',
        auditable: $user,
        userId: $user->id
    );

    Livewire::actingAs($user)
        ->test('pages::admin.user-activity')
        ->assertOk()
        ->assertSee('My Activity & Security History')
        ->assertSee('Test login event');
});

test('role and permission export services output valid data', function () {
    $csv = RolePermissionExportService::exportRolesCsv();
    expect($csv->getStatusCode())->toBe(200);

    $permCsv = RolePermissionExportService::exportPermissionsCsv();
    expect($permCsv->getStatusCode())->toBe(200);
});

test('super administrator can access all restricted administrative routes', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Administrator');
    $team = $superAdmin->currentTeam;

    $this->actingAs($superAdmin)
        ->get(route('admin.users.index', ['current_team' => $team]))
        ->assertOk();

    $this->actingAs($superAdmin)
        ->get(route('admin.roles.index', ['current_team' => $team]))
        ->assertOk();

    $this->actingAs($superAdmin)
        ->get(route('admin.permissions.index', ['current_team' => $team]))
        ->assertOk();

    $this->actingAs($superAdmin)
        ->get(route('admin.settings.index', ['current_team' => $team]))
        ->assertOk();
});

test('unauthorized user without permissions is forbidden with 403 on restricted routes', function () {
    $generalUser = User::factory()->create();
    $generalUser->assignRole('Student');
    $team = $generalUser->currentTeam;

    $this->actingAs($generalUser)
        ->get(route('admin.users.index', ['current_team' => $team]))
        ->assertForbidden();

    $this->actingAs($generalUser)
        ->get(route('admin.roles.index', ['current_team' => $team]))
        ->assertForbidden();

    $this->actingAs($generalUser)
        ->get(route('admin.permissions.index', ['current_team' => $team]))
        ->assertForbidden();

    $this->actingAs($generalUser)
        ->get(route('admin.settings.index', ['current_team' => $team]))
        ->assertForbidden();

    $this->actingAs($generalUser)
        ->get(route('teams.index'))
        ->assertForbidden();
});

test('sidebar renders only authorized items based on user role', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    $view = (string) $this->actingAs($staff)->blade("@include('layouts.app.sidebar')");

    // Staff has students.view, admissions.view, contacts.manage
    expect($view)->toContain('Students')
        ->and($view)->toContain('Admissions')
        ->and($view)->not->toContain('Roles')
        ->and($view)->not->toContain('Permissions')
        ->and($view)->not->toContain('Database & Backups')
        ->and($view)->not->toContain('Cron Jobs')
        ->and($view)->not->toContain('System settings')
        ->and($view)->not->toContain('Teams');
});

test('header topbar renders uploaded avatar image for authenticated user', function () {
    $user = User::factory()->create([
        'avatar' => 'avatars/sample_user_avatar.webp',
    ]);

    $view = (string) $this->actingAs($user)->blade("@include('layouts.app.topbar')");

    expect($view)->toContain('sample_user_avatar.webp')
        ->and($view)->toContain($user->name);
});
