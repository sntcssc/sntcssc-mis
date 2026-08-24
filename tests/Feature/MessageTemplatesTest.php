<?php

use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\MessageTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new MessageTemplateSeeder)->run();
    RbacService::seedDefaults();
});

test('sms templates page can be rendered by authenticated user', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrator');

    $response = $this->actingAs($user)->get(route('admin.sms-templates.index', ['current_team' => $user->personalTeam()->slug]));

    $response->assertOk();
});

test('email templates page can be rendered by authenticated user', function () {
    $user = User::factory()->create();
    $user->assignRole('Super Administrator');

    $response = $this->actingAs($user)->get(route('admin.email-templates.index', ['current_team' => $user->personalTeam()->slug]));

    $response->assertOk();
});

test('sms templates livewire component creates, updates, and soft deletes template', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.templates.sms-templates')
        ->set('form.code', 'mock_test_reminder')
        ->set('form.name', 'Mock Test Reminder')
        ->set('form.category', 'notice')
        ->set('form.body', 'Dear {name}, mock test starts in 1 hour.')
        ->set('form.variables_text', 'name, test_name')
        ->set('form.status', true)
        ->call('save')
        ->assertHasNoErrors();

    $template = SmsTemplate::where('code', 'mock_test_reminder')->first();
    expect($template)->not->toBeNull()
        ->and($template->name)->toBe('Mock Test Reminder')
        ->and($template->variables)->toBe(['name', 'test_name']);

    // Render placeholder
    expect($template->render(['name' => 'Rahul']))->toBe('Dear Rahul, mock test starts in 1 hour.');

    // Toggle status
    Livewire::actingAs($user)
        ->test('pages::admin.templates.sms-templates')
        ->call('toggleStatus', $template->id);

    expect($template->fresh()->status)->toBeFalse();

    // Soft delete
    Livewire::actingAs($user)
        ->test('pages::admin.templates.sms-templates')
        ->call('deleteTemplate', $template->id);

    expect($template->fresh()->trashed())->toBeTrue();

    // Restore
    Livewire::actingAs($user)
        ->test('pages::admin.templates.sms-templates')
        ->call('restoreTemplate', $template->id);

    expect($template->fresh()->trashed())->toBeFalse();
});

test('email templates livewire component creates, updates, and renders html preview', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.templates.email-templates')
        ->set('form.code', 'monthly_newsletter')
        ->set('form.name', 'Monthly Newsletter')
        ->set('form.category', 'promotional')
        ->set('form.subject', 'Updates for {month}')
        ->set('form.body', '<h1>Hello {name}</h1><p>Here is your update for {month}.</p>')
        ->set('form.variables_text', 'name, month')
        ->set('form.status', true)
        ->call('save')
        ->assertHasNoErrors();

    $template = EmailTemplate::where('code', 'monthly_newsletter')->first();
    expect($template)->not->toBeNull()
        ->and($template->subject)->toBe('Updates for {month}');

    $rendered = $template->render(['name' => 'Sunita', 'month' => 'September']);
    expect($rendered['subject'])->toBe('Updates for September')
        ->and($rendered['body'])->toContain('Hello Sunita')
        ->and($rendered['body'])->toContain('update for September');
});
