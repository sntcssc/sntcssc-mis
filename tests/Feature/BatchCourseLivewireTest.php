<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');
});

test('admin batches page renders and can create a batch', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.batches')
        ->assertOk()
        ->assertSee('Batch Management')
        ->set('form.name', '2029 Test Batch')
        ->set('form.code', 'BATCH-2029')
        ->set('form.academic_year', '2029')
        ->set('form.status', 'upcoming')
        ->call('save')
        ->assertDispatched('modal-close');

    expect(Batch::where('code', 'BATCH-2029')->exists())->toBeTrue();
});

test('admin courses page renders and can create a course', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.courses')
        ->assertOk()
        ->assertSee('Course Management')
        ->set('form.name', 'CSAT Quantitative Masterclass')
        ->set('form.code', 'CSAT-Q')
        ->set('form.category', 'Classroom')
        ->set('form.fee', '6000')
        ->set('form.has_sections', false)
        ->set('form.status', 'active')
        ->call('save')
        ->assertDispatched('modal-close');

    expect(Course::where('code', 'CSAT-Q')->exists())->toBeTrue();
});

test('admin students page renders and can register a student', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.students')
        ->assertOk()
        ->assertSee('Student Management')
        ->set('form.name', 'Subhashree Roy')
        ->set('form.email', 'subhashree.roy@example.com')
        ->set('form.gender', 'female')
        ->set('form.category', 'OBCB')
        ->call('saveStudent')
        ->assertDispatched('modal-close');

    $student = User::where('email', 'subhashree.roy@example.com')->first();
    expect($student)->not->toBeNull()
        ->and($student->studentProfile?->category)->toBe('OBCB');
});

test('admin enrollments page renders and can enroll a student', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create();
    $batch = Batch::where('code', 'BATCH-2026')->first();
    $course = Course::where('code', 'PCC')->first();

    Livewire::test('pages::admin.enrollments')
        ->assertOk()
        ->assertSee('Course Enrollments Management')
        ->set('form.user_id', (string) $student->id)
        ->set('form.batch_id', (string) $batch->id)
        ->set('form.course_id', (string) $course->id)
        ->set('form.enrolled_at', date('Y-m-d'))
        ->set('form.fee_amount', '7500')
        ->set('form.fee_status', 'paid')
        ->set('form.status', 'active')
        ->call('save')
        ->assertDispatched('modal-close');

    expect(Enrollment::where('user_id', $student->id)->where('course_id', $course->id)->exists())->toBeTrue();
});

test('admin profile requests page renders and can approve a request', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create(['name' => 'Prabir Das']);
    $req = ProfileUpdateRequest::create([
        'user_id' => $student->id,
        'section' => 'basic',
        'payload' => ['name' => 'Prabir Kumar Das'],
        'current_data' => ['name' => 'Prabir Das'],
        'reason' => 'Added middle name.',
        'status' => 'pending',
    ]);

    Livewire::test('pages::admin.profile-requests')
        ->assertOk()
        ->assertSee('Student Profile Correction Requests')
        ->call('openReview', $req->id)
        ->assertDispatched('modal-open', name: 'review-modal')
        ->call('approveRequest')
        ->assertDispatched('modal-close', name: 'review-modal');

    expect($req->fresh()->status)->toBe('approved')
        ->and($student->fresh()->name)->toBe('Prabir Kumar Das');
});

test('batches component dispatches modal-open for view, edit, sections and delete modals', function () {
    $this->actingAs($this->admin);

    $batch = Batch::where('code', 'BATCH-2026')->first();

    Livewire::test('pages::admin.batches')
        ->assertOk()
        ->call('create')
        ->assertDispatched('modal-open', name: 'batch-form-modal')
        ->call('edit', $batch->id)
        ->assertDispatched('modal-open', name: 'batch-form-modal')
        ->call('viewDetails', $batch->id)
        ->assertDispatched('modal-open', name: 'batch-view-modal')
        ->call('manageSections', $batch->id)
        ->assertDispatched('modal-open', name: 'batch-sections-modal')
        ->call('selectForDelete', $batch->id)
        ->assertDispatched('modal-open', name: 'batch-delete-modal')
        ->set('search', 'something')
        ->call('resetFilters')
        ->assertSet('search', '');
});

test('courses component dispatches modal-open for view, edit, and delete modals', function () {
    $this->actingAs($this->admin);

    $course = Course::where('code', 'CC')->first();

    Livewire::test('pages::admin.courses')
        ->assertOk()
        ->call('create')
        ->assertDispatched('modal-open', name: 'course-form-modal')
        ->call('edit', $course->id)
        ->assertDispatched('modal-open', name: 'course-form-modal')
        ->call('viewDetails', $course->id)
        ->assertDispatched('modal-open', name: 'course-view-modal')
        ->call('selectForDelete', $course->id)
        ->assertDispatched('modal-open', name: 'course-delete-modal')
        ->set('search', 'something')
        ->call('resetFilters')
        ->assertSet('search', '');
});

test('students component dispatches modal-open for view, edit, quick enroll and delete modals', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create();

    Livewire::test('pages::admin.students')
        ->assertOk()
        ->call('create')
        ->assertDispatched('modal-open', name: 'student-form-modal')
        ->call('edit', $student->id)
        ->assertDispatched('modal-open', name: 'student-form-modal')
        ->call('viewDossier', $student->id)
        ->assertDispatched('modal-open', name: 'student-dossier-modal')
        ->call('openQuickEnroll', $student->id)
        ->assertDispatched('modal-open', name: 'quick-enroll-modal')
        ->call('selectForDelete', $student->id)
        ->assertDispatched('modal-open', name: 'student-delete-modal')
        ->set('search', 'something')
        ->call('resetFilters')
        ->assertSet('search', '');
});

test('enrollments component dispatches modal-open for view, edit and delete modals', function () {
    $this->actingAs($this->admin);

    $student = User::factory()->create();
    $batch = Batch::where('code', 'BATCH-2026')->first();
    $course = Course::where('code', 'CC')->first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'enrolled_at' => date('Y-m-d'),
        'fee_amount' => 5000,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.enrollments')
        ->assertOk()
        ->call('create')
        ->assertDispatched('modal-open', name: 'enrollment-form-modal')
        ->call('edit', $enrollment->id)
        ->assertDispatched('modal-open', name: 'enrollment-form-modal')
        ->call('viewDetails', $enrollment->id)
        ->assertDispatched('modal-open', name: 'enrollment-view-modal')
        ->call('selectForDelete', $enrollment->id)
        ->assertDispatched('modal-open', name: 'enrollment-delete-modal')
        ->set('search', 'something')
        ->call('resetFilters')
        ->assertSet('search', '');
});

test('admin can export batches, courses, students, and enrollments as csv, xlsx, and pdf', function () {
    $this->actingAs($this->admin);

    foreach (['pages::admin.batches', 'pages::admin.courses', 'pages::admin.students', 'pages::admin.enrollments'] as $component) {
        Livewire::test($component)
            ->assertOk()
            ->call('export', 'csv')
            ->assertFileDownloaded();

        Livewire::test($component)
            ->assertOk()
            ->call('export', 'xlsx')
            ->assertFileDownloaded();

        Livewire::test($component)
            ->assertOk()
            ->call('export', 'pdf')
            ->assertFileDownloaded();
    }
});
