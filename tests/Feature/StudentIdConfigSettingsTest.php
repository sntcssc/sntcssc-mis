<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Setting::flushCache();
});

test('system settings page renders student id config section and controls', function () {
    Livewire::test('pages::admin.settings.system')
        ->assertSee('Student ID Generation Configuration')
        ->assertSee('student_id_config')
        ->assertSee('Live Format Simulator & Formula')
        ->assertSee('Sequence Padding Length')
        ->assertSee('Default Fallback Identifier')
        ->assertSee('Course Identifiers')
        ->assertSee('Section Identifiers')
        ->assertSee('Save ID Rules')
        ->assertSee('Reset Defaults');
});

test('system settings page loads existing student_id_config from setting', function () {
    $customConfig = [
        'sequence_length' => 5,
        'default_fallback_identifier' => '9',
        'course_identifiers' => [
            'CUSTOM' => '7',
        ],
        'section_identifiers' => [
            'Batch Alpha' => '8',
        ],
    ];

    Setting::set('student_id_config', $customConfig);
    Setting::flushCache();

    Livewire::test('pages::admin.settings.system')
        ->assertSet('studentIdConfig.sequence_length', 5)
        ->assertSet('studentIdConfig.default_fallback_identifier', '9')
        ->assertSet('studentIdConfig.course_identifiers.CUSTOM', '7')
        ->assertSet('studentIdConfig.section_identifiers.Batch Alpha', '8')
        ->assertSee('CUSTOM')
        ->assertSee('Batch Alpha');
});

test('it can add and remove course identifiers', function () {
    Livewire::test('pages::admin.settings.system')
        ->set('newCourseCode', 'UPSC')
        ->set('newCourseIdentifier', '4')
        ->call('addCourseIdentifier')
        ->assertSet('studentIdConfig.course_identifiers.UPSC', '4')
        ->assertSet('newCourseCode', '')
        ->assertSet('newCourseIdentifier', '')
        ->call('removeCourseIdentifier', 'UPSC')
        ->assertSet('studentIdConfig.course_identifiers.UPSC', null);
});

test('it rejects duplicate course identifier code', function () {
    Livewire::test('pages::admin.settings.system')
        ->set('studentIdConfig.course_identifiers.CC', '1')
        ->set('newCourseCode', 'CC')
        ->set('newCourseIdentifier', '2')
        ->call('addCourseIdentifier')
        ->assertSet('studentIdConfig.course_identifiers.CC', '1');
});

test('it can add and remove section identifiers', function () {
    Livewire::test('pages::admin.settings.system')
        ->set('newSectionName', 'Section Delta')
        ->set('newSectionIdentifier', '4')
        ->call('addSectionIdentifier')
        ->assertSet('studentIdConfig.section_identifiers.Section Delta', '4')
        ->assertSet('newSectionName', '')
        ->assertSet('newSectionIdentifier', '')
        ->call('removeSectionIdentifier', 'Section Delta')
        ->assertSet('studentIdConfig.section_identifiers.Section Delta', null);
});

test('it syncs unmapped courses from database', function () {
    Course::create([
        'name' => 'WBCS Foundation',
        'code' => 'WBCSF',
        'category' => 'civil_services',
        'fee' => 15000,
        'status' => 'active',
        'identifier_code' => '6',
    ]);

    Livewire::test('pages::admin.settings.system')
        ->set('studentIdConfig.course_identifiers', [])
        ->call('syncCoursesFromDb')
        ->assertSet('studentIdConfig.course_identifiers.WBCSF', '6');
});

test('it syncs unmapped sections from database', function () {
    $course = Course::create([
        'name' => 'Crash Course',
        'code' => 'CRASH',
        'category' => 'civil_services',
        'fee' => 5000,
        'status' => 'active',
    ]);

    $batch = Batch::create([
        'name' => 'Batch 2026',
        'code' => 'B2026',
        'year' => 2026,
        'academic_year' => '2026-2027',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    Section::create([
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'name' => 'Special Batch A',
        'code' => '3',
        'capacity' => 40,
        'status' => 'active',
    ]);

    Livewire::test('pages::admin.settings.system')
        ->set('studentIdConfig.section_identifiers', [])
        ->call('syncSectionsFromDb')
        ->assertSet('studentIdConfig.section_identifiers.Special Batch A', '3');
});

test('it accurately computes preview generated id formula breakdown', function () {
    $component = Livewire::test('pages::admin.settings.system')
        ->set('previewYear', '2026')
        ->set('studentIdConfig.sequence_length', 4)
        ->set('studentIdConfig.course_identifiers', ['CC' => '1'])
        ->set('previewSelectedCourse', 'CC')
        ->set('previewSelectedSection', '');

    $preview = $component->get('previewGeneratedId');

    expect($preview['year'])->toBe('2026')
        ->and($preview['identifier'])->toBe('1')
        ->and($preview['seq_length'])->toBe(4)
        ->and($preview['sample_id'])->toBe('202610001')
        ->and($preview['length'])->toBe(9)
        ->and($preview['is_valid'])->toBeTrue();

    // Section overrides course
    $component->set('studentIdConfig.section_identifiers', ['Section 5' => '5'])
        ->set('previewSelectedSection', 'Section 5');

    $previewWithSection = $component->get('previewGeneratedId');
    expect($previewWithSection['identifier'])->toBe('5')
        ->and($previewWithSection['sample_id'])->toBe('202650001');
});

test('it warns when id length exceeds 10 digits in simulator', function () {
    $component = Livewire::test('pages::admin.settings.system')
        ->set('previewYear', '2026')
        ->set('studentIdConfig.sequence_length', 5)
        ->set('studentIdConfig.default_fallback_identifier', '99')
        ->set('previewSelectedCourse', '')
        ->set('previewSelectedSection', '');

    // 2026 (4) + 99 (2) + 00001 (5) = 11 digits
    $preview = $component->get('previewGeneratedId');
    expect($preview['length'])->toBe(11)
        ->and($preview['is_valid'])->toBeFalse();
});

test('it saves student id config independently and logs audit', function () {
    Livewire::test('pages::admin.settings.system')
        ->set('studentIdConfig.sequence_length', 5)
        ->set('studentIdConfig.default_fallback_identifier', '8')
        ->set('studentIdConfig.course_identifiers', ['MOCK' => '7'])
        ->set('studentIdConfig.section_identifiers', ['Sec 1' => '2'])
        ->call('saveStudentIdConfig')
        ->assertHasNoErrors();

    $saved = Setting::get('student_id_config');
    expect($saved)->toBeArray()
        ->and($saved['sequence_length'])->toBe(5)
        ->and($saved['default_fallback_identifier'])->toBe('8')
        ->and($saved['course_identifiers']['MOCK'])->toBe('7')
        ->and($saved['section_identifiers']['Sec 1'])->toBe('2');

    $audit = AuditLog::where('event', 'student_id_config_updated')->latest()->first();
    expect($audit)->not->toBeNull();
});

test('it saves student id config along with entire system settings form', function () {
    Livewire::test('pages::admin.settings.system')
        ->set('form.app_name', 'SNT CSSC Integrated Portal')
        ->set('studentIdConfig.sequence_length', 3)
        ->set('studentIdConfig.default_fallback_identifier', '0')
        ->call('save')
        ->assertHasNoErrors();

    $savedConfig = Setting::get('student_id_config');
    expect((int) $savedConfig['sequence_length'])->toBe(3);
});

test('it resets student id config to defaults', function () {
    Livewire::test('pages::admin.settings.system')
        ->set('studentIdConfig.sequence_length', 5)
        ->set('studentIdConfig.default_fallback_identifier', '9')
        ->call('resetStudentIdConfigToDefaults')
        ->assertSet('studentIdConfig.sequence_length', 4)
        ->assertSet('studentIdConfig.default_fallback_identifier', '0')
        ->assertSet('studentIdConfig.course_identifiers.CC', '1')
        ->assertSet('studentIdConfig.course_identifiers.PCC', '3');
});
