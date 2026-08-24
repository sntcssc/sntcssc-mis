<?php

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\RbacService;
use App\Services\TicketService;
use Database\Seeders\TicketSystemSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(TicketSystemSeeder::class);
    Storage::fake('local');
});

test('student user can open a support ticket with attachments from portal', function () {
    $student = User::factory()->create(['name' => 'Aditi Sen', 'email' => 'aditi@example.com']);
    $student->assignRole('Student');

    $category = TicketCategory::first();

    $dummyFile = UploadedFile::fake()->create('screenshot.png', 100, 'image/png');

    Livewire::actingAs($student)
        ->test('pages::portal.tickets.create')
        ->set('category_id', $category->id)
        ->set('priority', 'high')
        ->set('subject', 'Admit Card Download Error')
        ->set('description', 'I receive a 404 error when clicking on admit card download link.')
        ->set('attachments', [$dummyFile])
        ->call('submitTicket')
        ->assertHasNoErrors();

    $ticket = Ticket::where('user_id', $student->id)->latest('id')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('Admit Card Download Error');
    expect($ticket->priority)->toBe('high');
    expect($ticket->attachments)->toHaveCount(1);
});

test('student user can view their ticket conversation and post replies', function () {
    $student = User::factory()->create(['name' => 'Rahul Verma']);
    $student->assignRole('Student');

    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);
    $ticket = $service->createTicket(
        data: [
            'subject' => 'Timetable query for batch 2',
            'description' => 'Is Saturday lecture offline or online?',
            'category_id' => $category->id,
        ],
        creator: $student
    );

    Livewire::actingAs($student)
        ->test('pages::portal.tickets.show', ['ticket' => $ticket->ticket_number])
        ->assertSee('Timetable query for batch 2')
        ->set('replyMessage', 'Thank you for checking, please let me know asap.')
        ->call('postReply')
        ->assertHasNoErrors();

    $ticket->refresh();
    expect($ticket->messages)->toHaveCount(2);
});

test('student user can mark ticket as resolved and submit CSAT satisfaction rating', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);
    $ticket = $service->createTicket(
        data: [
            'subject' => 'Password reset help',
            'description' => 'Need help resetting account.',
            'category_id' => $category->id,
        ],
        creator: $student
    );

    Livewire::actingAs($student)
        ->test('pages::portal.tickets.show', ['ticket' => $ticket->ticket_number])
        ->call('markAsResolved')
        ->assertHasNoErrors()
        ->set('rating', 5)
        ->set('feedback', 'Excellent and fast support desk response!')
        ->call('submitRating')
        ->assertHasNoErrors();

    $ticket->refresh();
    expect($ticket->status)->toBe(Ticket::STATUS_RESOLVED);
    expect($ticket->satisfaction_rating)->toBe(5);
    expect($ticket->satisfaction_feedback)->toBe('Excellent and fast support desk response!');
});

test('user cannot view another user private support ticket', function () {
    $user1 = User::factory()->create();
    $user1->assignRole('Student');

    $user2 = User::factory()->create();
    $user2->assignRole('Student');

    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);
    $ticket = $service->createTicket(
        data: [
            'subject' => 'User 1 Confidential Ticket',
            'description' => 'Confidential details...',
            'category_id' => $category->id,
        ],
        creator: $user1
    );

    Livewire::actingAs($user2)
        ->test('pages::portal.tickets.show', ['ticket' => $ticket->ticket_number])
        ->assertForbidden();
});
