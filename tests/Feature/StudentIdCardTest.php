<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Setting;
use App\Models\User;
use App\Services\RbacService;
use App\Services\StudentIdCardService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'Dean Academics',
        'email' => 'dean@example.com',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->studentUser = User::factory()->create([
        'name' => 'Sourav Mukherjee',
        'email' => 'sourav.mukherjee@example.com',
        'student_id' => 'STU-2026-0042',
        'phone' => '+91 9830099887',
    ]);
    $this->studentUser->assignRole('Student');

    $this->otherStudent = User::factory()->create([
        'name' => 'Priyanka Das',
        'email' => 'priyanka.das@example.com',
        'student_id' => 'STU-2026-0043',
    ]);
    $this->otherStudent->assignRole('Student');

    $this->course = Course::create([
        'name' => 'UPSC Civil Services Foundation Course',
        'code' => 'UPSC-CSFC',
        'fee' => 15000,
        'status' => 'active',
    ]);

    $this->batch = Batch::create([
        'name' => '2026 Morning Batch',
        'code' => 'BATCH-2026-M',
        'academic_year' => '2026',
        'status' => 'active',
    ]);

    $this->enrollment = Enrollment::create([
        'enrollment_no' => 'ENR-2026-0042',
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    // Ensure download enabled by default
    Setting::set('student_id_card.enable_student_download', true);
});

test('admin can view student id cards registry and management page', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.student-id-cards.index')
        ->assertOk()
        ->assertSee('Student Identity Cards')
        ->assertSee('Sourav Mukherjee');

    $response = $this->get("/{$this->admin->currentTeam->slug}/student-id-cards");
    $response->assertSuccessful();
    $response->assertSee('Student Identity Cards');
});

test('admin can view student id card configuration settings', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.settings.student-id-card')
        ->assertOk()
        ->assertSee('Student ID Card Configuration')
        ->assertSee('Student Portal Download Access');

    $response = $this->get("/{$this->admin->currentTeam->slug}/system/settings/student-id-card");
    $response->assertSuccessful();
    $response->assertSee('Student ID Card Configuration');
});

test('admin can update student id card settings via livewire', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.settings.student-id-card')
        ->set('form.enable_student_download', false)
        ->set('form.card_title', 'OFFICIAL ACADEMIC ID')
        ->set('form.validity_period', 'Session 2026-2027')
        ->call('save');

    expect(StudentIdCardService::isDownloadEnabledForStudents())->toBeFalse();
    expect(Setting::get('student_id_card.card_title'))->toBe('OFFICIAL ACADEMIC ID');
});

test('student can view digital student id card in portal', function () {
    $this->actingAs($this->studentUser);

    Livewire::test('pages::student.id-card')
        ->assertOk()
        ->assertSee('Student Identity Card')
        ->assertSee('Sourav Mukherjee')
        ->assertSee('STU-2026-0042');

    $response = $this->get("/{$this->studentUser->currentTeam->slug}/student/id-card");
    $response->assertSuccessful();
    $response->assertSee('Sourav Mukherjee');
});

test('student can download id card pdf when download is enabled', function () {
    Setting::set('student_id_card.enable_student_download', true);
    $this->actingAs($this->studentUser);

    $response = $this->get(route('student.id-card.download', ['student' => $this->studentUser->id]));
    $response->assertSuccessful();
    $response->assertHeader('content-type', 'application/pdf');
});

test('student is blocked from downloading id card when download is disabled in admin settings', function () {
    Setting::set('student_id_card.enable_student_download', false);
    $this->actingAs($this->studentUser);

    $response = $this->get(route('student.id-card.download', ['student' => $this->studentUser->id]));
    $response->assertForbidden();
});

test('admin can download student id card even if student download is disabled', function () {
    Setting::set('student_id_card.enable_student_download', false);
    $this->actingAs($this->admin);

    $response = $this->get(route('student.id-card.download', ['student' => $this->studentUser->id]));
    $response->assertSuccessful();
    $response->assertHeader('content-type', 'application/pdf');
});

test('admin can bulk download student id cards as single consolidated pdf', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('admin.student-id-cards.bulk-download', [
        'student_ids' => [$this->studentUser->id, $this->otherStudent->id],
    ]));

    $response->assertSuccessful();
    $response->assertHeader('content-type', 'application/pdf');
});

test('api returns student id card settings', function () {
    $response = $this->getJson(route('api.v1.student.id-card.settings'));
    $response->assertSuccessful();
    $response->assertJsonStructure([
        'success',
        'data' => [
            'enable_student_download',
            'institution_name',
            'validity_period',
        ],
    ]);
});

test('api returns digital id card payload for authenticated student', function () {
    $this->actingAs($this->studentUser);

    $response = $this->getJson(route('api.v1.student.id-card.payload'));
    $response->assertSuccessful();
    $response->assertJsonPath('data.student.student_id', 'STU-2026-0042');
    $response->assertJsonPath('data.student.name', 'Sourav Mukherjee');
});

test('student cannot access another student id card payload via api', function () {
    $this->actingAs($this->studentUser);

    $response = $this->getJson(route('api.v1.student-id-cards.payload', ['identifier' => $this->otherStudent->id]));
    $response->assertForbidden();
});
