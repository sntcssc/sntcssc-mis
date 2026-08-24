<?php

use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketCannedResponse;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\RbacService;
use App\Services\TicketService;
use Database\Seeders\TicketSystemSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(TicketSystemSeeder::class);
    Storage::fake('local');
});

test('administrator can create, view, and search support tickets from helpdesk desk', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);
    $ticket = $service->createTicket(
        data: [
            'subject' => 'Portal Login Issue with OTP',
            'description' => 'User reports OTP code not arriving on email.',
            'category_id' => $category->id,
            'priority' => Ticket::PRIORITY_HIGH,
            'guest_name' => 'Jane Candidate',
            'guest_email' => 'jane@example.com',
            'source' => Ticket::SOURCE_ADMIN,
        ],
        creator: $admin
    );

    expect($ticket->ticket_number)->toStartWith('TICK-');
    expect($ticket->priority)->toBe(Ticket::PRIORITY_HIGH);
    expect($ticket->status)->toBe(Ticket::STATUS_OPEN);
    expect($ticket->first_response_due_at)->not->toBeNull();

    Livewire::actingAs($admin)
        ->test('pages::admin.tickets')
        ->assertSee('Portal Login Issue with OTP')
        ->assertSee($ticket->ticket_number)
        ->set('search', 'Jane Candidate')
        ->assertSee('Jane Candidate');
});

test('staff can post public replies and confidential internal notes', function () {
    $admin = User::factory()->create(['name' => 'Agent Smith']);
    $admin->assignRole('Administrator');

    $student = User::factory()->create(['name' => 'Alice Student']);
    $student->assignRole('Student');

    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);
    $ticket = $service->createTicket(
        data: [
            'subject' => 'Course material missing for batch A',
            'description' => 'Lecture 4 notes are not downloadable.',
            'category_id' => $category->id,
            'priority' => Ticket::PRIORITY_MEDIUM,
        ],
        creator: $student
    );

    // 1. Post internal note
    $internalMsg = $service->replyTicket(
        ticket: $ticket,
        data: [
            'message' => 'Faculty member has been asked to upload PDF.',
            'type' => TicketMessage::TYPE_INTERNAL_NOTE,
        ],
        sender: $admin
    );

    expect($internalMsg->isInternalNote())->toBeTrue();

    // 2. Post public reply
    $publicMsg = $service->replyTicket(
        ticket: $ticket,
        data: [
            'message' => 'Hello Alice, we have updated Lecture 4 files. Please verify.',
            'type' => TicketMessage::TYPE_PUBLIC_REPLY,
            'new_status' => Ticket::STATUS_PENDING_USER,
        ],
        sender: $admin
    );

    expect($publicMsg->isPublicReply())->toBeTrue();
    $ticket->refresh();
    expect($ticket->status)->toBe(Ticket::STATUS_PENDING_USER);
    expect($ticket->first_responded_at)->not->toBeNull();
});

test('staff can assign ticket, change priority, and transition status', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $staff = User::factory()->create(['name' => 'Support Desk Agent']);
    $staff->assignRole('Staff');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);
    $ticket = $service->createTicket(
        data: [
            'subject' => 'Fee Receipt Mismatch',
            'description' => 'Amount on receipt differs from portal.',
            'category_id' => $category->id,
        ],
        creator: $student
    );

    // Assign
    $service->assignTicket($ticket, $staff, $admin);
    $ticket->refresh();
    expect($ticket->assigned_to_user_id)->toBe($staff->id);

    // Update Priority
    $service->updatePriority($ticket, Ticket::PRIORITY_URGENT, $admin);
    $ticket->refresh();
    expect($ticket->priority)->toBe(Ticket::PRIORITY_URGENT);

    // Resolve
    $service->updateStatus($ticket, Ticket::STATUS_RESOLVED, $admin, 'Receipt corrected in MIS.');
    $ticket->refresh();
    expect($ticket->status)->toBe(Ticket::STATUS_RESOLVED);
    expect($ticket->resolved_at)->not->toBeNull();

    $log = AuditLog::where('event', 'ticket_status_updated')->latest('id')->first();
    expect($log)->not->toBeNull();
});

test('canned responses substitute macro variables correctly', function () {
    $student = User::factory()->create(['name' => 'Rohan Sharma']);
    $agent = User::factory()->create(['name' => 'Agent Priya']);

    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);
    $ticket = $service->createTicket(
        data: [
            'subject' => 'Exam Hall Ticket Question',
            'description' => 'Where to download admit card?',
            'category_id' => $category->id,
        ],
        creator: $student
    );

    $macro = TicketCannedResponse::where('shortcut', '/ack')->first();
    expect($macro)->not->toBeNull();

    $rendered = $macro->renderContent($ticket, $agent);
    expect($rendered)->toContain('Rohan Sharma');
    expect($rendered)->toContain($ticket->ticket_number);
    expect($rendered)->toContain('Agent Priya');
});

test('sla monitoring detects response and resolution deadline breaches', function () {
    $student = User::factory()->create();
    $category = TicketCategory::first();

    /** @var TicketService $service */
    $service = app(TicketService::class);

    // Create overdue ticket
    $ticket = $service->createTicket(
        data: [
            'subject' => 'Overdue ticket',
            'description' => 'Waiting for response...',
            'category_id' => $category->id,
        ],
        creator: $student
    );

    // Artificially move due timestamp into past
    $ticket->update([
        'first_response_due_at' => now()->subHours(5),
        'resolution_due_at' => now()->subHours(1),
    ]);

    $breaches = $service->checkSlaBreaches();
    expect($breaches)->toBeGreaterThan(0);

    $ticket->refresh();
    expect($ticket->is_sla_response_breached)->toBeTrue();
    expect($ticket->is_sla_resolution_breached)->toBeTrue();
});

test('artisan sla breach command executes cleanly', function () {
    $this->artisan('app:tickets:check-sla')
        ->assertSuccessful();
});
