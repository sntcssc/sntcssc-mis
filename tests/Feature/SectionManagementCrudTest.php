<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->batch = Batch::create([
        'name' => 'UPSC CSE 2026',
        'code' => 'CSE2026',
        'academic_year' => '2026-2027',
        'year' => 2026,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $this->course = Course::create([
        'name' => 'Civil Services Foundation',
        'code' => 'CSF',
        'category' => 'Classroom',
        'fee' => 25000,
        'has_sections' => true,
        'status' => 'active',
    ]);
});

test('sections management page renders successfully with stats and controls', function () {
    Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Alpha Section',
        'code' => '1',
        'capacity' => 50,
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.sections')
        ->assertSee('Sections Management')
        ->assertSee('Total Sections')
        ->assertSee('Active Sections')
        ->assertSee('Total Capacity')
        ->assertSee('Alpha Section')
        ->assertSee('Add Section');
});

test('it can filter sections by batch, course, status and search', function () {
    $batch2 = Batch::create([
        'name' => 'WBCS 2026',
        'code' => 'WBCS2026',
        'academic_year' => '2026-2027',
        'year' => 2026,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $course2 = Course::create([
        'name' => 'Prelims Crash Course',
        'code' => 'PCC',
        'category' => 'Crash Course',
        'fee' => 10000,
        'status' => 'active',
    ]);

    $section1 = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Morning Batch Section',
        'code' => 'M1',
        'capacity' => 40,
        'room_number' => 'Hall A',
        'status' => 'active',
    ]);

    $section2 = Section::create([
        'batch_id' => $batch2->id,
        'course_id' => $course2->id,
        'name' => 'Evening Batch Section',
        'code' => 'E1',
        'capacity' => 30,
        'room_number' => 'Room 102',
        'status' => 'inactive',
    ]);

    // Search by name
    Livewire::test('pages::admin.sections')
        ->set('search', 'Morning')
        ->assertSee('Morning Batch Section')
        ->assertDontSee('Evening Batch Section');

    // Filter by batch
    Livewire::test('pages::admin.sections')
        ->set('batchFilter', (string) $batch2->id)
        ->assertSee('Evening Batch Section')
        ->assertDontSee('Morning Batch Section');

    // Filter by status
    Livewire::test('pages::admin.sections')
        ->set('statusFilter', 'inactive')
        ->assertSee('Evening Batch Section')
        ->assertDontSee('Morning Batch Section');
});

test('it creates a new section with audit log', function () {
    Livewire::test('pages::admin.sections')
        ->call('create')
        ->set('form.batch_id', (string) $this->batch->id)
        ->set('form.course_id', (string) $this->course->id)
        ->set('form.name', 'Premier Cohort')
        ->set('form.code', 'PC')
        ->set('form.capacity', 60)
        ->set('form.room_number', 'Audi 1')
        ->set('form.timing', 'Mon-Fri 9:00 AM - 12:00 PM')
        ->set('form.status', 'active')
        ->call('save')
        ->assertHasNoErrors();

    $section = Section::where('name', 'Premier Cohort')->first();
    expect($section)->not->toBeNull()
        ->and($section->batch_id)->toBe($this->batch->id)
        ->and($section->course_id)->toBe($this->course->id)
        ->and($section->code)->toBe('PC')
        ->and($section->capacity)->toBe(60)
        ->and($section->room_number)->toBe('Audi 1');

    $audit = AuditLog::where('event', 'section_created')->first();
    expect($audit)->not->toBeNull();
});

test('it validates required fields when saving section', function () {
    Livewire::test('pages::admin.sections')
        ->set('form.batch_id', '')
        ->set('form.course_id', '')
        ->set('form.name', '')
        ->set('form.capacity', 0)
        ->call('save')
        ->assertHasErrors(['form.batch_id', 'form.course_id', 'form.name', 'form.capacity']);
});

test('it edits an existing section successfully', function () {
    $section = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Initial Section Name',
        'code' => 'INIT',
        'capacity' => 45,
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.sections')
        ->call('edit', $section->id)
        ->set('form.name', 'Updated Section Name')
        ->set('form.capacity', 55)
        ->call('save')
        ->assertHasNoErrors();

    expect($section->fresh()->name)->toBe('Updated Section Name')
        ->and($section->fresh()->capacity)->toBe(55);

    $audit = AuditLog::where('event', 'section_updated')->first();
    expect($audit)->not->toBeNull();
});

test('it can toggle section status', function () {
    $section = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Toggle Test Section',
        'code' => 'T1',
        'capacity' => 40,
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.sections')
        ->call('toggleStatus', $section->id);

    expect($section->fresh()->status)->toBe('inactive');

    Livewire::test('pages::admin.sections')
        ->call('toggleStatus', $section->id);

    expect($section->fresh()->status)->toBe('active');
});

test('it views section details and enrolled students list', function () {
    $section = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Detail View Section',
        'code' => 'D1',
        'capacity' => 40,
        'room_number' => 'Room 201',
        'status' => 'active',
    ]);

    $student = User::factory()->create(['name' => 'Ananya Roy', 'student_id' => '202610001']);
    Enrollment::create([
        'user_id' => $student->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $section->id,
        'enrolled_at' => now(),
        'enrollment_no' => 'ENR-2026-001',
        'roll_no' => '001',
        'fee_amount' => 25000,
        'fee_status' => 'paid',
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.sections')
        ->call('showDetails', $section->id)
        ->assertSee('Detail View Section')
        ->assertSee('Ananya Roy')
        ->assertSee('202610001');
});

test('it deletes section successfully and logs audit', function () {
    $section = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'To Be Deleted Section',
        'code' => 'DEL1',
        'capacity' => 30,
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.sections')
        ->call('confirmDelete', $section->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Section::find($section->id))->toBeNull();

    $audit = AuditLog::where('event', 'section_deleted')->first();
    expect($audit)->not->toBeNull();
});

test('students page filters list by section', function () {
    $sectionA = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Section A',
        'code' => 'A',
        'capacity' => 50,
        'status' => 'active',
    ]);

    $sectionB = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Section B',
        'code' => 'B',
        'capacity' => 50,
        'status' => 'active',
    ]);

    $student1 = User::factory()->create(['name' => 'Student In Section A', 'student_id' => '202610001']);
    $student2 = User::factory()->create(['name' => 'Student In Section B', 'student_id' => '202610002']);

    Enrollment::create([
        'user_id' => $student1->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $sectionA->id,
        'enrolled_at' => now(),
        'enrollment_no' => 'ENR-001',
        'fee_amount' => 10000,
        'status' => 'active',
    ]);

    Enrollment::create([
        'user_id' => $student2->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $sectionB->id,
        'enrolled_at' => now(),
        'enrollment_no' => 'ENR-002',
        'fee_amount' => 10000,
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.students')
        ->assertSee('Student In Section A')
        ->assertSee('Student In Section B')
        ->set('sectionFilter', (string) $sectionA->id)
        ->assertSee('Student In Section A')
        ->assertDontSee('Student In Section B');
});

test('enrollments page filters list by section', function () {
    $sectionA = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Batch Sec Alpha',
        'code' => 'A1',
        'capacity' => 50,
        'status' => 'active',
    ]);

    $sectionB = Section::create([
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'name' => 'Batch Sec Beta',
        'code' => 'B1',
        'capacity' => 50,
        'status' => 'active',
    ]);

    $student1 = User::factory()->create(['name' => 'Cadet One']);
    $student2 = User::factory()->create(['name' => 'Cadet Two']);

    Enrollment::create([
        'user_id' => $student1->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $sectionA->id,
        'enrolled_at' => now(),
        'enrollment_no' => 'ENR-ALPHA-01',
        'fee_amount' => 20000,
        'status' => 'active',
    ]);

    Enrollment::create([
        'user_id' => $student2->id,
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $sectionB->id,
        'enrolled_at' => now(),
        'enrollment_no' => 'ENR-BETA-02',
        'fee_amount' => 20000,
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.enrollments')
        ->assertSee('ENR-ALPHA-01')
        ->assertSee('ENR-BETA-02')
        ->set('sectionFilter', (string) $sectionA->id)
        ->assertSee('ENR-ALPHA-01')
        ->assertDontSee('ENR-BETA-02');
});
