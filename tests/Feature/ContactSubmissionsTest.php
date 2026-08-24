<?php

use App\Models\ContactDepartment;
use App\Models\ContactSubject;
use App\Models\ContactSubmission;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\ContactMasterSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    Language::flushCache();
    Setting::flushCache();
    $this->seed(SettingsSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(ContactMasterSeeder::class);
    $this->user = User::factory()->create();
});

test('public contact us page renders correctly with form and master data', function () {
    $response = $this->get(route('public.contact'));

    $response->assertOk()
        ->assertSee('Contact Us')
        ->assertSee('Admissions & Counseling')
        ->assertSee('Submit Inquiry');
});

test('contact form validates required fields', function () {
    Livewire::test('pages::public.contact-us')
        ->call('submit')
        ->assertHasErrors(['form.name', 'form.email', 'form.mobile', 'form.message']);
});

test('successful contact form submission creates database record and generates tracking reference', function () {
    $dept = ContactDepartment::first();
    $subject = ContactSubject::where('department_id', $dept->id)->first();

    Livewire::test('pages::public.contact-us')
        ->set('form.name', 'Aspirant Candidate')
        ->set('form.email', 'aspirant@example.com')
        ->set('form.mobile', '+91 9876543210')
        ->set('form.whatsapp', '+91 9876543210')
        ->set('form.department_id', $dept->id)
        ->set('form.subject_id', $subject?->id)
        ->set('form.message', 'I want to know the upcoming batch schedule for UPSC CSE Prelims 2027.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('isSubmitted', true)
        ->assertSee('Thank you! Your inquiry has been received.');

    $submission = ContactSubmission::where('email', 'aspirant@example.com')->first();
    expect($submission)->not->toBeNull()
        ->and($submission->reference_no)->toStartWith('SNT-REQ-')
        ->and($submission->name)->toBe('Aspirant Candidate')
        ->and($submission->department_id)->toBe($dept->id)
        ->and($submission->status)->toBe(ContactSubmission::STATUS_NEW);
});

test('honeypot traps automated bots without creating submission', function () {
    $initialCount = ContactSubmission::count();

    Livewire::test('pages::public.contact-us')
        ->set('website_url_hp', 'http://spam-bot-url.com')
        ->set('form.name', 'Spam Bot')
        ->set('form.email', 'spam@bot.com')
        ->set('form.mobile', '1234567890')
        ->set('form.message', 'Buy cheap products now')
        ->call('submit')
        ->assertSet('isSubmitted', true);

    expect(ContactSubmission::count())->toBe($initialCount);
});

test('admin can manage contact submissions, update status, and respond', function () {
    $this->actingAs($this->user);

    $submission = ContactSubmission::create([
        'reference_no' => ContactSubmission::generateReferenceNumber(),
        'name' => 'John Doe',
        'email' => 'john@test.com',
        'mobile' => '+91 9000000000',
        'message' => 'Need counseling for optional subject selection.',
        'status' => ContactSubmission::STATUS_NEW,
        'priority' => ContactSubmission::PRIORITY_HIGH,
    ]);

    Livewire::test('pages::admin.contacts')
        ->assertSee('Contact Inquiries & Helpdesk')
        ->assertSee($submission->reference_no)
        ->assertSee('John Doe')
        // Test View & Respond
        ->call('viewDetails', $submission->id)
        ->set('updateStatus', ContactSubmission::STATUS_RESOLVED)
        ->set('updatePriority', ContactSubmission::PRIORITY_URGENT)
        ->set('adminResponseNotes', 'Called candidate and scheduled mentorship call.')
        ->call('updateSubmissionStatus')
        ->assertHasNoErrors();

    expect($submission->fresh()->status)->toBe(ContactSubmission::STATUS_RESOLVED)
        ->and($submission->fresh()->priority)->toBe(ContactSubmission::PRIORITY_URGENT)
        ->and($submission->fresh()->admin_notes)->toBe('Called candidate and scheduled mentorship call.')
        ->and($submission->fresh()->replied_by)->toBe($this->user->id);

    // Test Delete & Restore
    Livewire::test('pages::admin.contacts')
        ->call('deleteSubmission', $submission->id);

    expect($submission->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::admin.contacts')
        ->set('showTrashed', true)
        ->call('restoreSubmission', $submission->id);

    expect($submission->fresh()->trashed())->toBeFalse();
});

test('admin can manage departments and subjects', function () {
    $this->actingAs($this->user);

    // Test Create Department
    Livewire::test('pages::admin.contacts')
        ->call('openCreateDepartmentModal')
        ->set('departmentForm.name', 'Scholarship Wing')
        ->set('departmentForm.code', 'SCHOLARSHIP_WING')
        ->set('departmentForm.email', 'scholarships@sntcssc.in')
        ->set('departmentForm.sort_order', 10)
        ->call('saveDepartment')
        ->assertHasNoErrors();

    $dept = ContactDepartment::where('code', 'SCHOLARSHIP_WING')->first();
    expect($dept)->not->toBeNull();

    // Test Create Subject
    Livewire::test('pages::admin.contacts')
        ->call('openCreateSubjectModal')
        ->set('subjectForm.department_id', $dept->id)
        ->set('subjectForm.name', 'Merit Scholarship Application')
        ->set('subjectForm.code', 'MERIT_APPLY')
        ->set('subjectForm.sort_order', 1)
        ->call('saveSubject')
        ->assertHasNoErrors();

    $subject = ContactSubject::where('code', 'MERIT_APPLY')->first();
    expect($subject)->not->toBeNull()
        ->and($subject->department_id)->toBe($dept->id);
});
