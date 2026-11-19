<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\RbacService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard.redirect'));
    $response->assertRedirect(route('login'));
});

test('authenticated standard user sees user dashboard with candidate dossier and course spotlight', function () {
    $user = User::factory()->create([
        'name' => 'John Candidate',
        'email' => 'john.candidate@example.com',
    ]);
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertSee('Candidate Dossier');
    $response->assertSee('Registered Candidate');
    $response->assertSee('Complete Student Profile');
});

test('authenticated student sees student dashboard with academics & enrolments tab', function () {
    $student = User::factory()->create([
        'name' => 'Priya Student',
        'email' => 'priya.student@example.com',
        'student_id' => '202610001',
    ]);
    $student->assignRole('Student');
    $team = $student->currentTeam;

    StudentProfile::create([
        'user_id' => $student->id,
        'category' => 'UR',
    ]);

    $batch = Batch::create([
        'name' => 'Batch 2026-2027',
        'code' => 'B2026',
        'academic_year' => '2026',
        'start_date' => '2026-08-01',
        'end_date' => '2027-05-31',
        'status' => 'active',
        'max_students' => 200,
    ]);

    $course = Course::create([
        'name' => 'Composite Course (CC)',
        'code' => 'CC',
        'category' => 'UPSC CSE - Prelims Cum Mains',
        'duration_months' => 10,
        'fee_type' => 'monthly',
        'ur_monthly_fee' => 1000,
        'reserved_monthly_fee' => 500,
        'status' => 'active',
    ]);

    $section = Section::create([
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'name' => 'Section A',
        'code' => 'SEC-A',
        'id_code' => 1,
        'capacity' => 100,
        'status' => 'active',
    ]);

    Enrollment::create([
        'enrollment_no' => 'ENR-2026-00001',
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'section_id' => $section->id,
        'enrolled_at' => now(),
        'status' => 'active',
        'fee_amount' => 1000.00,
        'fee_status' => 'paid',
    ]);

    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertSee('Academics & Enrolments');
    $response->assertSee('Student ID:');
    $response->assertSee('202610001');
});

test('authenticated administrator sees administrative operations and academic analytics', function () {
    $admin = User::factory()->create([
        'name' => 'Admin Officer',
        'email' => 'admin.officer@example.com',
    ]);
    $admin->assignRole('Super Administrator');
    $team = $admin->currentTeam;

    $response = $this
        ->actingAs($admin)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertSee('Operations & Management');
    $response->assertSee('Academic Highlights');
    $response->assertSee('Top Academic Courses');
});

test('student dashboard component displays free course exemption and section cohort id code in academics tab', function () {
    $student = User::factory()->create([
        'name' => 'Anil Aspirant',
        'email' => 'anil.aspirant@example.com',
        'student_id' => '202620005',
    ]);
    $student->assignRole('Student');

    StudentProfile::create([
        'user_id' => $student->id,
        'category' => 'SC',
    ]);

    $batch = Batch::create([
        'name' => 'Batch 2026-2027',
        'code' => 'B2026',
        'academic_year' => '2026',
        'start_date' => '2026-08-01',
        'end_date' => '2027-05-31',
        'status' => 'active',
        'max_students' => 200,
    ]);

    $freeCourse = Course::create([
        'name' => 'Free UPSC CSE Foundation Seminar',
        'code' => 'FOS',
        'category' => 'UPSC CSE - Foundation / Seminar',
        'duration_weeks' => 1,
        'fee_type' => 'free',
        'ur_one_time_fee' => 0,
        'reserved_one_time_fee' => 0,
        'status' => 'active',
    ]);

    $sectionB = Section::create([
        'batch_id' => $batch->id,
        'course_id' => $freeCourse->id,
        'name' => 'Section B',
        'code' => 'SEC-B',
        'id_code' => 2,
        'capacity' => 100,
        'status' => 'active',
    ]);

    Enrollment::create([
        'enrollment_no' => 'ENR-2026-00005',
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'batch_id' => $batch->id,
        'course_id' => $freeCourse->id,
        'section_id' => $sectionB->id,
        'enrolled_at' => now(),
        'status' => 'active',
        'fee_amount' => 0.00,
        'fee_status' => 'exempted',
    ]);

    $this->actingAs($student);

    Livewire::test('pages::student.dashboard')
        ->set('activeTab', 'academics')
        ->assertSee('Free UPSC CSE Foundation Seminar')
        ->assertSee('Free of Cost / No Fee')
        ->assertSee('Exempted (Zero Fee)')
        ->assertSee('Section B')
        ->assertSee('ID Code: 2')
        ->assertSee('202620005');
});

test('student dashboard header and identity cards display URN when present and handles empty URN gracefully', function () {
    // 1. With valid URN
    $studentWithUrn = User::factory()->create([
        'name' => 'Student With URN',
        'student_id' => '202610111',
        'urn' => '20261003998877',
    ]);
    $studentWithUrn->assignRole('Student');
    StudentProfile::create([
        'user_id' => $studentWithUrn->id,
        'category' => 'UR',
    ]);

    $this->actingAs($studentWithUrn);
    Livewire::test('pages::student.dashboard')
        ->assertSee('URN:')
        ->assertSee('20261003998877');

    // 2. With empty / null URN
    $studentWithoutUrn = User::factory()->create([
        'name' => 'Student Without URN',
        'student_id' => '202610222',
    ]);
    DB::table('users')->where('id', $studentWithoutUrn->id)->update(['urn' => null]);
    $studentWithoutUrn->refresh();
    $studentWithoutUrn->assignRole('Student');
    StudentProfile::create([
        'user_id' => $studentWithoutUrn->id,
        'category' => 'SC',
    ]);

    $this->actingAs($studentWithoutUrn);
    Livewire::test('pages::student.dashboard')
        ->assertSee('URN:')
        ->assertSee('Not Assigned')
        ->assertDontSee('20261003998877');
});

test('admin dashboard and profile header display URN and handle empty URN gracefully', function () {
    $admin = User::factory()->create([
        'name' => 'Admin User',
        'urn' => '20261003554433',
    ]);
    $admin->assignRole('Administrator');
    $team = $admin->currentTeam;

    // Admin dashboard greeting header
    $response = $this->actingAs($admin)->get(route('dashboard', ['current_team' => $team->slug]));
    $response->assertOk()
        ->assertSee('URN:')
        ->assertSee('20261003554433');

    // Admin profile header and dossier card
    Livewire::actingAs($admin)
        ->test('pages::admin.profile')
        ->assertSee('URN:')
        ->assertSee('20261003554433');

    // With null URN
    $adminWithoutUrn = User::factory()->create([
        'name' => 'Admin Without URN',
    ]);
    DB::table('users')->where('id', $adminWithoutUrn->id)->update(['urn' => null]);
    $adminWithoutUrn->refresh();
    $adminWithoutUrn->assignRole('Administrator');
    $team2 = $adminWithoutUrn->currentTeam;

    $response2 = $this->actingAs($adminWithoutUrn)->get(route('dashboard', ['current_team' => $team2->slug]));
    $response2->assertOk()
        ->assertSee('URN:')
        ->assertSee('Not Assigned');

    Livewire::actingAs($adminWithoutUrn)
        ->test('pages::admin.profile')
        ->assertSee('URN:')
        ->assertSee('Not Assigned');
});
