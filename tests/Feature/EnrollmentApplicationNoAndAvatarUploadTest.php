<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    Storage::fake('public');

    $this->admin = User::factory()->create([
        'first_name' => 'Admin',
        'last_name' => 'User',
        'name' => 'Admin User',
        'email' => 'admin.test@example.com',
    ]);
    $this->admin->assignRole('Super Administrator');
    $this->admin->ensureCurrentTeam();

    $this->batch = Batch::first();
    $this->course = Course::first();
});

test('enrollment can store and search by optional application number', function () {
    $student = User::factory()->create([
        'name' => 'Siddharth Roy',
        'email' => 'siddharth@example.com',
    ]);

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'enrollment_no' => 'ENR-2026-TEST-001',
        'application_no' => 'APP-UPSC-2026-0042',
        'status' => 'active',
        'fee_status' => 'paid',
        'fee_amount' => 15000,
        'enrolled_at' => now(),
    ]);

    expect($enrollment->fresh()->application_no)->toBe('APP-UPSC-2026-0042');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.enrollments')
        ->set('search', 'APP-UPSC-2026-0042')
        ->assertSee('ENR-2026-TEST-001')
        ->assertSee('App:')
        ->assertSee('APP-UPSC-2026-0042');
});

test('admin can create enrollment with optional application number through enrollments page', function () {
    $student = User::factory()->create([
        'name' => 'Pooja Bannerjee',
        'email' => 'pooja@example.com',
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.enrollments')
        ->call('create')
        ->set('form.user_id', (string) $student->id)
        ->set('form.batch_id', (string) $this->batch->id)
        ->set('form.course_id', (string) $this->course->id)
        ->set('form.application_no', 'APP-2026-ENR-99')
        ->set('form.fee_status', 'paid')
        ->set('form.fee_amount', 12000)
        ->set('form.enrolled_at', now()->format('Y-m-d'))
        ->call('save')
        ->assertHasNoErrors();

    $created = Enrollment::where('application_no', 'APP-2026-ENR-99')->first();
    expect($created)->not->toBeNull()
        ->and($created->user_id)->toBe($student->id)
        ->and($created->course_id)->toBe($this->course->id);
});

test('admin can upload photo when registering a new student using RenamesUploads convention', function () {
    $avatarFile = UploadedFile::fake()->image('candidate_portrait.jpg', 300, 300);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.students')
        ->set('form.first_name', 'Vikram')
        ->set('form.last_name', 'Das')
        ->set('form.name', 'Vikram Das')
        ->set('form.email', 'vikram.das@example.com')
        ->set('form.gender', 'male')
        ->set('form.dob', '2001-05-15')
        ->set('form.enroll_batch_id', (string) $this->batch->id)
        ->set('form.enroll_course_id', (string) $this->course->id)
        ->set('form.enroll_application_no', 'APP-VIKRAM-001')
        ->set('avatarFile', $avatarFile)
        ->call('saveStudent')
        ->assertHasNoErrors();

    $student = User::where('email', 'vikram.das@example.com')->first();
    expect($student)->not->toBeNull()
        ->and($student->avatar)->not->toBeNull()
        ->and($student->avatar)->toMatch('/^avatars\/avatar_user_'.$student->id.'_\d{8}_\d{6}\.jpg$/');

    Storage::disk('public')->assertExists($student->avatar);

    $enrollment = Enrollment::where('user_id', $student->id)->first();
    expect($enrollment)->not->toBeNull()
        ->and($enrollment->application_no)->toBe('APP-VIKRAM-001');
});

test('admin can replace and remove student profile photo in edit mode', function () {
    $student = User::factory()->create([
        'name' => 'Meera Bose',
        'email' => 'meera@example.com',
        'avatar' => 'avatars/avatar_user_existing.jpg',
    ]);
    Storage::disk('public')->put('avatars/avatar_user_existing.jpg', 'dummy image content');

    $newAvatar = UploadedFile::fake()->image('new_meera.png', 400, 400);

    // 1. Replace avatar
    Livewire::actingAs($this->admin)
        ->test('pages::admin.students')
        ->call('edit', $student->id)
        ->set('avatarFile', $newAvatar)
        ->call('updateBasicInfo')
        ->assertHasNoErrors();

    $student->refresh();
    expect($student->avatar)->not->toBe('avatars/avatar_user_existing.jpg')
        ->and($student->avatar)->toMatch('/^avatars\/avatar_user_'.$student->id.'_\d{8}_\d{6}\.png$/');

    Storage::disk('public')->assertMissing('avatars/avatar_user_existing.jpg');
    Storage::disk('public')->assertExists($student->avatar);

    // 2. Remove avatar
    Livewire::actingAs($this->admin)
        ->test('pages::admin.students')
        ->call('edit', $student->id)
        ->call('removeStudentAvatar')
        ->assertHasNoErrors();

    $student->refresh();
    expect($student->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($student->avatar);
});

test('admin can update only photo of student without changing any other account details', function () {
    $student = User::factory()->create([
        'first_name' => 'Sourav',
        'last_name' => 'Ganguly',
        'name' => 'Sourav Ganguly',
        'email' => 'sourav.ganguly@example.com',
        'phone' => '9830012345',
        'whatsapp_no' => '9830012345',
        'gender' => 'male',
        'dob' => '2000-07-08',
        'status' => 'active',
        'student_id' => '2026SG0001',
        'tenth_roll' => 'ROLL-10-SG',
        'id_type' => 'Aadhaar',
        'id_number' => '1234-5678-9012',
        'avatar' => null,
    ]);

    $avatarFile = UploadedFile::fake()->image('sourav_new_pic.jpg', 350, 350);

    // Call edit, then ONLY set avatarFile without modifying any userForm fields
    Livewire::actingAs($this->admin)
        ->test('pages::admin.students')
        ->call('edit', $student->id)
        ->set('avatarFile', $avatarFile)
        ->call('updateBasicInfo')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success', message: 'Student profile photo updated successfully.');

    $student->refresh();
    expect($student->avatar)->not->toBeNull()
        ->and($student->avatar)->toMatch('/^avatars\/avatar_user_'.$student->id.'_\d{8}_\d{6}\.jpg$/');

    Storage::disk('public')->assertExists($student->avatar);
});

test('admin can quick-enroll student with optional application number', function () {
    $student = User::factory()->create([
        'name' => 'Rohan Sen',
        'email' => 'rohan@example.com',
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.students')
        ->call('openQuickEnroll', $student->id)
        ->set('quickEnrollForm.batch_id', (string) $this->batch->id)
        ->set('quickEnrollForm.course_id', (string) $this->course->id)
        ->set('quickEnrollForm.roll_no', 'ROLL-QE-101')
        ->set('quickEnrollForm.application_no', 'APP-QUICK-ROHAN-77')
        ->set('quickEnrollForm.fee_amount', 15000)
        ->set('quickEnrollForm.fee_status', 'paid')
        ->call('saveQuickEnroll')
        ->assertHasNoErrors();

    $enr = Enrollment::where('application_no', 'APP-QUICK-ROHAN-77')->first();
    expect($enr)->not->toBeNull()
        ->and($enr->user_id)->toBe($student->id)
        ->and($enr->roll_no)->toBe('ROLL-QE-101');
});

test('admin can upload photo when creating user and replace/remove in edit mode', function () {
    $avatarFile = UploadedFile::fake()->image('staff_photo.jpg', 300, 300);

    // 1. Create User with Photo
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users')
        ->call('create')
        ->set('form.first_name', 'Ananya')
        ->set('form.last_name', 'Gupta')
        ->set('form.email', 'ananya.gupta@example.com')
        ->set('form.role', 'Administrator')
        ->set('form.status', 'active')
        ->set('form.password', 'Password@123')
        ->set('form.password_confirmation', 'Password@123')
        ->set('avatarFile', $avatarFile)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'ananya.gupta@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->avatar)->not->toBeNull()
        ->and($user->avatar)->toMatch('/^avatars\/avatar_user_'.$user->id.'_\d{8}_\d{6}\.jpg$/');

    Storage::disk('public')->assertExists($user->avatar);

    $oldAvatar = $user->avatar;

    // 2. Replace User Photo
    $replacementAvatar = UploadedFile::fake()->image('staff_photo_new.webp', 300, 300);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users')
        ->call('edit', $user->id)
        ->set('avatarFile', $replacementAvatar)
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->avatar)->not->toBe($oldAvatar)
        ->and($user->avatar)->toMatch('/^avatars\/avatar_user_'.$user->id.'_\d{8}_\d{6}\.webp$/');

    Storage::disk('public')->assertMissing($oldAvatar);
    Storage::disk('public')->assertExists($user->avatar);

    // 3. Remove User Photo
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users')
        ->call('edit', $user->id)
        ->call('removeAvatar')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->avatar)->toBeNull();
});
