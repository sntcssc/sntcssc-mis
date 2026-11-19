<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\GlobalSearchService;
use App\Services\RbacService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('students cannot access teams index page', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $this->actingAs($student)
        ->get(route('teams.index'))
        ->assertForbidden();
});

test('non-admin users cannot access teams index page', function () {
    $faculty = User::factory()->create();
    $faculty->assignRole('Faculty');

    $this->actingAs($faculty)
        ->get(route('teams.index'))
        ->assertForbidden();

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    $this->actingAs($staff)
        ->get(route('teams.index'))
        ->assertForbidden();

    $plainUser = User::factory()->create();

    $this->actingAs($plainUser)
        ->get(route('teams.index'))
        ->assertForbidden();
});

test('students cannot access team edit page', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = Team::factory()->create();
    $team->members()->attach($student, ['role' => TeamRole::Owner->value]);

    $this->actingAs($student)
        ->get(route('teams.edit', $team))
        ->assertForbidden();
});

test('non-admin users cannot access team edit page', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Staff');
    $team = Team::factory()->create();
    $team->members()->attach($staff, ['role' => TeamRole::Owner->value]);

    $this->actingAs($staff)
        ->get(route('teams.edit', $team))
        ->assertForbidden();
});

test('students cannot mount or create teams via teams index livewire component', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $this->actingAs($student);

    Livewire::test('pages::teams.index')
        ->assertForbidden();
});

test('students cannot mount or update teams via team edit livewire component', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = Team::factory()->create();
    $team->members()->attach($student, ['role' => TeamRole::Owner->value]);

    $this->actingAs($student);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->assertForbidden();
});

test('students cannot create team via create-team modal component', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $this->actingAs($student);

    Livewire::test('create-team-modal')
        ->set('teamName', 'Student Team')
        ->call('createTeam')
        ->assertForbidden();

    $this->assertDatabaseMissing('teams', [
        'name' => 'Student Team',
    ]);
});

test('students cannot switch teams via team-switcher component', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = Team::factory()->create();
    $team->members()->attach($student, ['role' => TeamRole::Member->value]);

    $this->actingAs($student);

    Livewire::test('team-switcher')
        ->call('switchTeam', $team->slug)
        ->assertForbidden();
});

test('students cannot invite members via invite-member modal', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = Team::factory()->create();
    $team->members()->attach($student, ['role' => TeamRole::Owner->value]);

    $this->actingAs($student);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $team])
        ->assertForbidden();
});

test('students cannot remove members via remove-member modal', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($student, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($student);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $team, 'memberId' => $member->id])
        ->assertForbidden();
});

test('students cannot cancel invitations via cancel-invitation modal', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = Team::factory()->create();
    $team->members()->attach($student, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $student->id,
    ]);

    $this->actingAs($student);

    Livewire::test('pages::teams.cancel-invitation-modal', ['team' => $team, 'invitationCode' => $invitation->code])
        ->assertForbidden();
});

test('students cannot delete teams via delete-team modal', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $team = Team::factory()->create();
    $team->members()->attach($student, ['role' => TeamRole::Owner->value]);

    $this->actingAs($student);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->assertForbidden();
});

test('students cannot accept or decline invitations via pending-invitations modal', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $student = User::factory()->create(['email' => 'student@example.com']);
    $student->assignRole('Student');

    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'student@example.com',
        'invited_by' => $admin->id,
    ]);

    $this->actingAs($student);

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertForbidden();

    Livewire::test('pages::teams.pending-invitations-modal')
        ->call('declineInvitation', $invitation->code)
        ->assertForbidden();
});

test('settings tabs hide teams tab for students and non-admin users', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $html = (string) $this->actingAs($student)->blade("@include('pages.settings.layout')");
    expect($html)->not->toContain(route('teams.index'));

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    $htmlStaff = (string) $this->actingAs($staff)->blade("@include('pages.settings.layout')");
    expect($htmlStaff)->not->toContain(route('teams.index'));
});

test('settings tabs show teams tab for administrators', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $html = (string) $this->actingAs($admin)->blade("@include('pages.settings.layout')");
    expect($html)->toContain(route('teams.index'));

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Administrator');

    $htmlSuper = (string) $this->actingAs($superAdmin)->blade("@include('pages.settings.layout')");
    expect($htmlSuper)->toContain(route('teams.index'));
});

test('topbar does not render team switcher for students and non-admins', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $html = (string) $this->actingAs($student)->blade("@include('layouts.app.topbar')");
    expect($html)->not->toContain('team-switcher');

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    $htmlStaff = (string) $this->actingAs($staff)->blade("@include('layouts.app.topbar')");
    expect($htmlStaff)->not->toContain('team-switcher');
});

test('topbar renders team switcher for administrators', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $admin->ensureCurrentTeam();

    $html = (string) $this->actingAs($admin)->blade("@include('layouts.app.topbar')");
    expect($html)->toContain('team-switcher');
});

test('sidebar does not render teams navigation item for students and non-admins', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $html = (string) $this->actingAs($student)->blade("@include('layouts.app.sidebar')");
    expect($html)->not->toContain(route('teams.index'));

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    $htmlStaff = (string) $this->actingAs($staff)->blade("@include('layouts.app.sidebar')");
    expect($htmlStaff)->not->toContain(route('teams.index'));
});

test('sidebar renders teams navigation item for administrators with settings.general permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $html = (string) $this->actingAs($admin)->blade("@include('layouts.app.sidebar')");
    expect($html)->toContain(route('teams.index'));
});

test('global search hides teams management from students and non-admins', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $items = GlobalSearchService::getAccessibleNavigation($student);
    expect($items->pluck('id')->contains('nav_teams'))->toBeFalse();

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    $staffItems = GlobalSearchService::getAccessibleNavigation($staff);
    expect($staffItems->pluck('id')->contains('nav_teams'))->toBeFalse();

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $adminItems = GlobalSearchService::getAccessibleNavigation($admin);
    expect($adminItems->pluck('id')->contains('nav_teams'))->toBeTrue();
});

test('administrators can access teams index and edit pages', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Owner->value]);

    $this->actingAs($admin)
        ->get(route('teams.index'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('teams.edit', $team))
        ->assertOk();
});
