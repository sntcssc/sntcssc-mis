<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OptionalSubject;
use App\Models\OptionalSubjectChangeRequest;
use App\Models\Section;
use App\Models\StudentOtherDetail;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\OptionalSubjectSeeder;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(OptionalSubjectSeeder::class);

    $this->admin = User::factory()->create([
        'name' => 'Admin Controller',
        'email' => 'admin.opt@example.com',
    ]);
    $this->admin->assignRole('Super Administrator');

    $this->studentUser = User::factory()->create([
        'name' => 'Ananya Sen',
        'email' => 'ananya.sen@example.com',
        'student_id' => 'STU-OPT-001',
    ]);
    $this->studentUser->assignRole('Student');

    $this->course = Course::create([
        'name' => 'UPSC Civil Services Integrated',
        'code' => 'UPSC-INT-TEST',
        'fee' => 10000,
        'status' => 'active',
    ]);

    $this->batch = Batch::create([
        'name' => '2026 Foundation Batch A',
        'code' => 'BATCH-2026-A',
        'academic_year' => '2026',
        'status' => 'active',
    ]);

    $this->section = Section::create([
        'batch_id' => $this->batch->id,
        'name' => 'Section Alpha',
        'code' => 'SEC-ALPHA',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $this->enrollment = Enrollment::create([
        'enrollment_no' => 'ENR-2026-0001',
        'user_id' => $this->studentUser->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->section->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $this->otherDetail = StudentOtherDetail::create([
        'user_id' => $this->studentUser->id,
        'attempts_already_given' => 0,
        'attempts_left' => 6,
        'optional_subject' => 'History',
    ]);
});

test('admin can view optional subjects management component', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.optional-subjects.index')
        ->assertOk()
        ->assertSee('Optional Subject Management')
        ->assertSee('Enrolled Students')
        ->assertSee('Quick Update');
});

test('admin can visit optional subjects management route', function () {
    $this->actingAs($this->admin);

    $response = $this->get("/{$this->admin->currentTeam->slug}/optional-subjects");
    $response->assertSuccessful();
    $response->assertSee('Optional Subject Management & Reports');
});

test('unauthorized user cannot access optional subjects management', function () {
    $unauthorizedUser = User::factory()->create();
    $this->actingAs($unauthorizedUser);

    $response = $this->get("/{$unauthorizedUser->currentTeam->slug}/optional-subjects");
    $response->assertForbidden();
});

test('master catalog is seeded with official upsc optional subjects', function () {
    expect(OptionalSubject::count())->toBeGreaterThanOrEqual(48);
    expect(OptionalSubject::where('name', 'History')->exists())->toBeTrue();
    expect(OptionalSubject::where('name', 'Geography')->exists())->toBeTrue();
    expect(OptionalSubject::where('name', 'Public Administration')->exists())->toBeTrue();
    expect(OptionalSubject::where('name', 'Sociology')->exists())->toBeTrue();
});

test('admin can quick update student optional subject via livewire component', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.optional-subjects.index')
        ->call('openQuickUpdate', $this->studentUser->id)
        ->set('quickUpdateSubject', 'Political Science & International Relations')
        ->call('saveQuickUpdate');

    $this->otherDetail->refresh();
    expect($this->otherDetail->optional_subject)->toBe('Political Science & International Relations');
});

test('admin can manage master catalog CRUD', function () {
    $this->actingAs($this->admin);

    // Create custom subject
    Livewire::test('pages::admin.optional-subjects.index')
        ->call('openCreateSubjectModal')
        ->set('subjectForm.name', 'Tribal Studies & Indigenous Governance')
        ->set('subjectForm.code', 'CUSTOM-TRI')
        ->set('subjectForm.category', 'Humanities & Social Sciences')
        ->set('subjectForm.description', 'Interdisciplinary optional curriculum.')
        ->call('saveSubject');

    $created = OptionalSubject::where('code', 'CUSTOM-TRI')->first();
    expect($created)->not->toBeNull();
    expect($created->name)->toBe('Tribal Studies & Indigenous Governance');

    // Toggle active
    Livewire::test('pages::admin.optional-subjects.index')
        ->call('toggleSubjectStatus', $created->id);

    expect($created->fresh()->is_active)->toBeFalse();

    // Delete
    Livewire::test('pages::admin.optional-subjects.index')
        ->call('confirmDeleteSubject', $created->id)
        ->call('deleteSubject');

    expect(OptionalSubject::where('id', $created->id)->exists())->toBeFalse();
    expect(OptionalSubject::withTrashed()->where('id', $created->id)->exists())->toBeTrue();
});

test('student can submit optional subject change request with reason', function () {
    $this->actingAs($this->studentUser);

    $response = $this->postJson(route('api.v1.student.optional-subject.request'), [
        'requested_subject' => 'Sociology',
        'reason' => 'I have completed my undergraduate degree in Sociology and feel more confident in it.',
    ]);

    $response->assertSuccessful();

    $request = OptionalSubjectChangeRequest::where('user_id', $this->studentUser->id)->first();
    expect($request)->not->toBeNull();
    expect($request->current_subject)->toBe('History');
    expect($request->requested_subject)->toBe('Sociology');
    expect($request->isPending())->toBeTrue();
});

test('student cannot request the same subject as their current subject', function () {
    $this->actingAs($this->studentUser);

    $response = $this->postJson(route('api.v1.student.optional-subject.request'), [
        'requested_subject' => 'History',
        'reason' => 'I want to reconfirm history as my optional subject.',
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['success' => false]);
});

test('student cannot submit a second request while a pending request exists', function () {
    $this->actingAs($this->studentUser);

    OptionalSubjectChangeRequest::create([
        'request_no' => OptionalSubjectChangeRequest::generateRequestNo(),
        'user_id' => $this->studentUser->id,
        'current_subject' => 'History',
        'requested_subject' => 'Sociology',
        'reason' => 'First pending request for change.',
        'status' => OptionalSubjectChangeRequest::STATUS_PENDING,
    ]);

    $response = $this->postJson(route('api.v1.student.optional-subject.request'), [
        'requested_subject' => 'Geography',
        'reason' => 'Second change request attempt.',
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['success' => false]);
});

test('admin can approve student optional subject change request and update details', function () {
    $this->actingAs($this->admin);

    $changeRequest = OptionalSubjectChangeRequest::create([
        'request_no' => OptionalSubjectChangeRequest::generateRequestNo(),
        'user_id' => $this->studentUser->id,
        'current_subject' => 'History',
        'requested_subject' => 'Anthropology',
        'reason' => 'Completed Anthropology prerequisite workshop.',
        'status' => OptionalSubjectChangeRequest::STATUS_PENDING,
    ]);

    Livewire::test('pages::admin.optional-subjects.index')
        ->call('openApproveModal', $changeRequest->id)
        ->set('actionRemarks', 'Approved after consultation with academic mentor.')
        ->call('confirmApprove');

    $changeRequest->refresh();
    expect($changeRequest->status)->toBe(OptionalSubjectChangeRequest::STATUS_APPROVED);
    expect($changeRequest->reviewed_by)->toBe($this->admin->id);

    $this->otherDetail->refresh();
    expect($this->otherDetail->optional_subject)->toBe('Anthropology');
});

test('admin can reject student optional subject change request with mandatory remarks', function () {
    $this->actingAs($this->admin);

    $changeRequest = OptionalSubjectChangeRequest::create([
        'request_no' => OptionalSubjectChangeRequest::generateRequestNo(),
        'user_id' => $this->studentUser->id,
        'current_subject' => 'History',
        'requested_subject' => 'Mathematics',
        'reason' => 'Wish to change to mathematics.',
        'status' => OptionalSubjectChangeRequest::STATUS_PENDING,
    ]);

    Livewire::test('pages::admin.optional-subjects.index')
        ->call('openRejectModal', $changeRequest->id)
        ->set('actionRemarks', 'Rejected due to course syllabus midway cutoff deadline passed.')
        ->call('confirmReject');

    $changeRequest->refresh();
    expect($changeRequest->status)->toBe(OptionalSubjectChangeRequest::STATUS_REJECTED);
    expect($changeRequest->admin_remarks)->toContain('cutoff deadline');

    $this->otherDetail->refresh();
    // Subject should stay original
    expect($this->otherDetail->optional_subject)->toBe('History');
});

test('admin can export optional subjects allocation report to csv', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::admin.optional-subjects.index');
    $response = $component->call('exportAllocations', 'csv');

    expect($response)->not->toBeNull();
});

test('api v1 optional subjects listing returns active subjects', function () {
    $response = $this->getJson(route('api.v1.catalog.optional-subjects'));

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'success',
        'data',
    ]);
});

test('unauthenticated or unauthorized users cannot review change requests via api', function () {
    $changeRequest = OptionalSubjectChangeRequest::create([
        'request_no' => OptionalSubjectChangeRequest::generateRequestNo(),
        'user_id' => $this->studentUser->id,
        'current_subject' => 'History',
        'requested_subject' => 'Sociology',
        'reason' => 'Wish to change subject.',
        'status' => OptionalSubjectChangeRequest::STATUS_PENDING,
    ]);

    // Unauthenticated
    $response = $this->postJson("/api/v1/optional-subjects/requests/{$changeRequest->id}/review", [
        'action' => 'approve',
    ]);
    $response->assertStatus(401);

    // Student user without permission
    $response = $this->actingAs($this->studentUser)->postJson("/api/v1/optional-subjects/requests/{$changeRequest->id}/review", [
        'action' => 'approve',
    ]);
    $response->assertStatus(403);
});

test('quick update respects subject max capacity', function () {
    $this->actingAs($this->admin);

    $subject = OptionalSubject::create([
        'name' => 'Space Technology & Astronomy',
        'code' => 'SPACETEC',
        'category' => 'Science & Mathematics',
        'max_capacity' => 1,
        'is_active' => true,
    ]);

    // Student 1 gets the slot
    Livewire::test('pages::admin.optional-subjects.index')
        ->call('openQuickUpdate', $this->studentUser->id)
        ->set('quickUpdateSubject', 'Space Technology & Astronomy')
        ->call('saveQuickUpdate');

    $this->otherDetail->refresh();
    expect($this->otherDetail->optional_subject)->toBe('Space Technology & Astronomy');

    // Create student 2
    $student2 = User::factory()->create();
    $student2->assignRole('Student');
    $other2 = StudentOtherDetail::create(['user_id' => $student2->id, 'optional_subject' => null]);

    // Student 2 tries to get the same slot, but capacity is full
    Livewire::test('pages::admin.optional-subjects.index')
        ->call('openQuickUpdate', $student2->id)
        ->set('quickUpdateSubject', 'Space Technology & Astronomy')
        ->call('saveQuickUpdate');

    $other2->refresh();
    expect($other2->optional_subject)->toBeNull();
});

test('changing filters resets datatable pagination and clear filters works', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::admin.optional-subjects.index')
        ->set('search', 'Test Student');

    expect($component->get('hasActiveFilters'))->toBeTrue();

    $component->call('resetFilters');
    expect($component->get('hasActiveFilters'))->toBeFalse();
    expect($component->get('search'))->toBe('');
});
