<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\RbacService;
use App\Services\UserExportImportService;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('user model automatically generates uuid and urn on creation', function () {
    $user = User::create([
        'name' => 'Sourav Mukherjee',
        'first_name' => 'Sourav',
        'last_name' => 'Mukherjee',
        'email' => 'sourav.mukherjee@example.com',
        'phone' => '+919876543210',
        'whatsapp_no' => '+919876543210',
        'gender' => 'male',
        'tenth_roll' => 'ROLL-2015-8832',
        'id_type' => 'aadhaar',
        'id_number' => '1234-5678-9012',
        'designation' => 'Faculty Officer',
        'status' => 'active',
        'password' => Hash::make('SecretPassword123!'),
    ]);

    expect($user->uuid)->not->toBeEmpty()
        ->and($user->urn)->not->toBeEmpty()
        ->and(strlen($user->urn))->toBe(14)
        ->and($user->first_name)->toBe('Sourav')
        ->and($user->last_name)->toBe('Mukherjee')
        ->and($user->isLocked())->toBeFalse();
});

test('user locking and unlocking methods work properly with audit trail', function () {
    $user = User::factory()->create(['status' => 'active']);

    expect($user->isLocked())->toBeFalse();

    $user->lockAccount(now()->addHour());
    expect($user->fresh()->isLocked())->toBeTrue()
        ->and($user->fresh()->status)->toBe('locked');

    $user->unlockAccount();
    expect($user->fresh()->isLocked())->toBeFalse()
        ->and($user->fresh()->status)->toBe('active');
});

test('user soft deletes and restoration works correctly', function () {
    $user = User::factory()->create();

    $user->delete();
    expect($user->trashed())->toBeTrue()
        ->and(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();

    $user->restore();
    expect($user->fresh()->trashed())->toBeFalse()
        ->and(User::find($user->id))->not->toBeNull();
});

test('users livewire component renders and can filter by role and status', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $faculty = User::factory()->create(['first_name' => 'Alok', 'name' => 'Alok Nath', 'status' => 'active']);
    $faculty->assignRole('Faculty');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertOk()
        ->assertSee('Users Management')
        ->set('search', 'Alok')
        ->assertSee('Alok Nath')
        ->set('roleFilter', 'Faculty')
        ->assertSee('Alok Nath')
        ->set('roleFilter', 'Admissions Officer')
        ->assertDontSee('Alok Nath');
});

test('users export to csv and excel produces valid stream', function () {
    User::factory()->count(3)->create();

    $response = UserExportImportService::exportCsv(User::query());
    expect($response->getStatusCode())->toBe(200);

    $pdf = UserExportImportService::exportPdf(User::query());
    expect($pdf->getStatusCode())->toBe(200);

    $template = UserExportImportService::downloadImportTemplate();
    expect($template->getStatusCode())->toBe(200);
});

test('batch importing users from csv persists records and assigns roles', function () {
    $csvContent = "first_name,last_name,email,phone,role,status\n".
        "Tanmoy,Dey,tanmoy.dey@example.com,+919988776655,Faculty,active\n".
        "Priyanka,Sen,priyanka.sen@example.com,+919988776644,Admissions Officer,active\n";

    $file = UploadedFile::fake()->createWithContent('users_sample.csv', $csvContent);

    $result = UserExportImportService::importUsers($file);

    expect($result['success'])->toBeTrue()
        ->and($result['imported_count'])->toBe(2)
        ->and(User::where('email', 'tanmoy.dey@example.com')->exists())->toBeTrue()
        ->and(User::where('email', 'priyanka.sen@example.com')->first()->hasRole('Admissions Officer'))->toBeTrue();
});

test('profile page allows user to update extended profile attributes and preferences', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.profile')
        ->assertOk()
        ->assertSee('Profile & Preferences')
        ->assertSee($user->urn)
        ->set('first_name', 'Vikram')
        ->set('last_name', 'Aditya')
        ->set('name', 'Vikram Aditya')
        ->set('whatsapp_no', '+919876500000')
        ->set('gender', 'male')
        ->set('dob', '1995-05-15')
        ->set('tenth_roll', 'WB-10-999888')
        ->set('id_type', 'pan')
        ->set('id_number', 'ABCDE1234F')
        ->set('designation', 'Dean of Academics')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->first_name)->toBe('Vikram')
        ->and($fresh->last_name)->toBe('Aditya')
        ->and($fresh->name)->toBe('Vikram Aditya')
        ->and($fresh->whatsapp_no)->toBe('+919876500000')
        ->and($fresh->tenth_roll)->toBe('WB-10-999888')
        ->and($fresh->id_type)->toBe('pan')
        ->and($fresh->id_number)->toBe('ABCDE1234F')
        ->and($fresh->designation)->toBe('Dean of Academics');
});

test('super admin seeder creates default super admin account with role and personal team', function () {
    $this->seed(SuperAdminSeeder::class);

    $superAdmin = User::where('email', 'admin@sntcssc.in')->first();

    expect($superAdmin)->not->toBeNull()
        ->and($superAdmin->hasRole('Super Administrator'))->toBeTrue()
        ->and($superAdmin->status)->toBe('active')
        ->and($superAdmin->currentTeam)->not->toBeNull()
        ->and(Hash::check('Password@1234', $superAdmin->password))->toBeTrue();
});

test('user management form validation fails gracefully with errors when required fields are empty', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('create')
        ->set('form.first_name', '')
        ->set('form.email', '')
        ->set('form.password', '')
        ->set('form.role', '')
        ->call('save')
        ->assertHasErrors(['form.first_name', 'form.email', 'form.password', 'form.role'])
        ->assertDispatched('toast');
});

test('admin can create a user with a selected role and it is properly assigned', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('create')
        ->set('form.first_name', 'Rupesh')
        ->set('form.last_name', 'Sharma')
        ->set('form.email', 'rupesh.sharma@example.com')
        ->set('form.role', 'Faculty')
        ->set('form.status', 'active')
        ->set('form.password', 'Password@123')
        ->set('form.password_confirmation', 'Password@123')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'rupesh.sharma@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('Faculty'))->toBeTrue()
        ->and($user->name)->toBe('Rupesh Sharma')
        ->and($user->currentTeam)->not->toBeNull()
        ->and($user->currentTeam->slug)->not->toBeEmpty();
});

test('user created with student role auto-generates student_id and syncs student_profile', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('create')
        ->set('form.first_name', 'Kritika')
        ->set('form.last_name', 'Basu')
        ->set('form.email', 'kritika.basu@example.com')
        ->set('form.gender', 'female')
        ->set('form.dob', '2003-04-12')
        ->set('form.role', 'Student')
        ->set('form.status', 'active')
        ->set('form.password', 'Password@123')
        ->set('form.password_confirmation', 'Password@123')
        ->call('save')
        ->assertHasNoErrors();

    $student = User::where('email', 'kritika.basu@example.com')->first();
    expect($student)->not->toBeNull()
        ->and($student->hasRole('Student'))->toBeTrue()
        ->and($student->currentTeam)->not->toBeNull()
        ->and($student->student_id)->not->toBeEmpty()
        ->and($student->studentProfile)->not->toBeNull()
        ->and($student->studentProfile->gender)->toBe('female')
        ->and($student->studentProfile->dob->format('Y-m-d'))->toBe('2003-04-12');
});

test('user import requires a valid role', function () {
    $csvContent = "first_name,last_name,email,phone,role,status\n".
        "NoRole,User,norole@example.com,+919988776611,,active\n".
        "InvalidRole,User,invalidrole@example.com,+919988776622,NonExistentRole,active\n";

    $file = UploadedFile::fake()->createWithContent('invalid_users.csv', $csvContent);

    $result = UserExportImportService::importUsers($file);

    expect($result['imported_count'])->toBe(0)
        ->and($result['skipped_count'])->toBe(2)
        ->and(count($result['errors']))->toBe(2);
});

test('admin can soft delete a user and restore via livewire', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $target = User::factory()->create(['name' => 'Delete Me']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openDeleteModal', $target->id)
        ->call('softDelete')
        ->assertDispatched('toast')
        ->assertDispatched('modal-close');

    expect($target->fresh()->trashed())->toBeTrue()
        ->and($target->fresh()->deleted_by)->toBe($admin->id);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('restoreUser', $target->id)
        ->assertDispatched('toast');

    expect($target->fresh()->trashed())->toBeFalse()
        ->and($target->fresh()->deleted_by)->toBeNull();
});

test('admin can force delete a user via livewire', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $target = User::factory()->create(['name' => 'Purge Me']);
    $targetId = $target->id;

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openForceDeleteModal', $targetId)
        ->call('forceDelete')
        ->assertDispatched('toast')
        ->assertDispatched('modal-close');

    expect(User::withTrashed()->find($targetId))->toBeNull();
});

test('admin cannot soft delete own account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openDeleteModal', $admin->id)
        ->call('softDelete')
        ->assertDispatched('toast');

    expect($admin->fresh()->trashed())->toBeFalse();
});

test('user created by admin is assigned into admin existing team instead of creating new personal team', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $adminTeam = $admin->currentTeam;
    expect($adminTeam)->not->toBeNull();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('create')
        ->set('form.first_name', 'Rahul')
        ->set('form.last_name', 'Verma')
        ->set('form.email', 'rahul.verma@example.com')
        ->set('form.role', 'Faculty')
        ->set('form.status', 'active')
        ->set('form.password', 'Password@123')
        ->set('form.password_confirmation', 'Password@123')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'rahul.verma@example.com')->first();
    expect($user)->not->toBeNull();
    // Must be assigned to admin's existing team
    expect($user->current_team_id)->toBe($adminTeam->id);
    expect($user->belongsToTeam($adminTeam))->toBeTrue();
    expect($user->teamRole($adminTeam))->toBe(TeamRole::Member);

    // No new personal team should be created for this user
    expect($user->personalTeam())->toBeNull();
    expect(Team::where('name', "Rahul Verma's Team")->exists())->toBeFalse();
});
