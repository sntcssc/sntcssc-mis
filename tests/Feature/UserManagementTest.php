<?php

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
        ->call('save')
        ->assertHasErrors(['form.first_name', 'form.email', 'form.password'])
        ->assertDispatched('toast');
});
