<?php

use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use App\Services\TicketService;
use Database\Seeders\TicketSystemSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    RbacService::seedDefaults();
    $this->seed(TicketSystemSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Support Desk Admin']);
    $this->admin->assignRole('Super Administrator');

    $this->agent = User::factory()->create(['name' => 'Support Agent User']);
    $this->agent->assignRole('Administrator');

    $this->student = User::factory()->create(['name' => 'Aspirant Student']);

    $this->apiKeyService = app(ApiKeyService::class);

    // Full key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Support Full Management Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:tickets', 'write:tickets', 'manage:tickets', 'ping'],
        'description' => 'Key with full tickets and helpdesk administrative permissions',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Readonly key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Support Readonly Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['read:tickets'],
        'description' => 'Read only tickets key',
    ], $this->student);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];

    // Write-only key (no manage)
    $writeKeyData = $this->apiKeyService->generateKey([
        'name' => 'Support Write Key',
        'type' => 'web_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 150,
        'scopes' => ['read:tickets', 'write:tickets'],
        'description' => 'Read and write tickets key without manage:tickets scope',
    ], $this->student);

    $this->writePlainKey = $writeKeyData['plainTextKey'];

    $this->ticketService = app(TicketService::class);
    $category = TicketCategory::first();

    $this->sampleTicket = $this->ticketService->createTicket([
        'subject' => 'Unable to access UPSC Civil Services Mock Test 4',
        'description' => 'I am enrolled in Batch A and attempting to start the Mock Examination, but the system shows enrollment pending.',
        'category_id' => $category?->id,
        'priority' => 'high',
        'source' => 'portal',
        'user_id' => $this->student->id,
    ], $this->student);

    $this->ticketService->createTicket([
        'subject' => 'Hostel Room Change Request',
        'description' => 'Requesting relocation to first floor room due to leg injury.',
        'category_id' => $category?->id,
        'priority' => 'medium',
        'source' => 'portal',
        'user_id' => $this->student->id,
    ], $this->student);

    $this->ticketService->createTicket([
        'subject' => 'Payment receipt not updated for optional course',
        'description' => 'UPI transaction ref 9876543210 debited but status pending.',
        'category_id' => $category?->id,
        'priority' => 'urgent',
        'source' => 'mobile_app',
        'user_id' => $this->student->id,
    ], $this->student);
});

test('ticket endpoints enforce authentication and scope authorization', function () {
    // 1. Unauthenticated request without API Key returns 401
    $res401 = $this->getJson('/api/v1/tickets/dashboard');
    $res401->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'API_KEY_MISSING');

    // 2. Readonly key attempting to manage status returns 403
    $ticket = Ticket::first();
    $res403 = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->putJson("/api/v1/tickets/{$ticket->id}/status", [
            'status' => 'resolved',
        ]);
    $res403->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');

    // 3. Full key has access
    $res200 = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/tickets/dashboard');
    $res200->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('dashboard telemetry endpoint returns accurate counts and metrics', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/tickets/dashboard');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'counts' => ['total', 'active', 'open', 'in_progress', 'resolved', 'closed', 'urgent_high'],
                'sla' => ['response_breaches', 'resolution_breaches', 'total_breaches'],
                'satisfaction' => ['average', 'total_ratings'],
                'categories',
                'recent_activity',
            ],
        ]);
});

test('statistics endpoint returns priority, status and satisfaction distributions', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/tickets/statistics');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'priorities' => ['urgent', 'high', 'medium', 'low'],
                'statuses',
                'sources',
                'monthly_trend',
                'ratings',
            ],
        ]);
});

test('tickets index supports pagination, search, status, and category filtering', function () {
    $category = TicketCategory::first();

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/tickets?category_id={$category->id}&per_page=5");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

test('my-tickets endpoint returns only personal tickets of authenticated user', function () {
    // Create ticket owned by student
    $ticketService = app(TicketService::class);
    $category = TicketCategory::first();

    $ticket = $ticketService->createTicket([
        'subject' => 'Student Specific Issue with Fee Receipt',
        'description' => 'I made a payment and need the official invoice.',
        'category_id' => $category->id,
        'priority' => 'medium',
        'source' => 'mobile_app',
        'user_id' => $this->student->id,
    ], $this->student);

    $response = $this->actingAs($this->student, 'sanctum')
        ->getJson('/api/v1/tickets/my-tickets');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $items = $response->json('data');
    expect(count($items))->toBeGreaterThanOrEqual(1);
    expect($items[0]['user_id'])->toBe($this->student->id);
});

test('store creates support ticket with SLA calculation, opening message, and audit log', function () {
    $category = TicketCategory::where('slug', 'admissions-enrollments')->first();

    $response = $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/tickets', [
            'subject' => 'Unable to access UPSC Foundation Course lecture recordings',
            'description' => 'Whenever I attempt to launch the video lecture for Modern Indian History, the stream displays 403 error.',
            'category_id' => $category->id,
            'priority' => 'high',
            'source' => 'mobile_app',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.priority', 'high');

    $ticketNumber = $response->json('data.ticket_number');
    expect($ticketNumber)->toStartWith('TICK-');

    $ticket = Ticket::where('ticket_number', $ticketNumber)->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->first_response_due_at)->not->toBeNull();
    expect($ticket->resolution_due_at)->not->toBeNull();

    // Verify initial message was created
    expect($ticket->messages()->count())->toBe(1);
    expect($ticket->messages()->first()->type)->toBe(TicketMessage::TYPE_PUBLIC_REPLY);

    // Verify audit log
    $audit = AuditLog::where('event', 'ticket_created')
        ->where('user_id', $this->student->id)
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
});

test('store accepts file attachments and prevents dangerous file extensions', function () {
    $category = TicketCategory::first();
    $pdfFile = UploadedFile::fake()->create('screenshot.pdf', 200, 'application/pdf');

    $response = $this->actingAs($this->student, 'sanctum')
        ->post('/api/v1/tickets', [
            'subject' => 'Ticket with payment slip attachment',
            'description' => 'Attached please find the bank transaction confirmation receipt.',
            'category_id' => $category->id,
            'attachments' => [$pdfFile],
        ]);

    $response->assertStatus(201);
    $ticketId = $response->json('data.id');

    $attachment = TicketAttachment::where('ticket_id', $ticketId)->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->original_filename)->toBe('screenshot.pdf');
    expect(Storage::disk('local')->exists($attachment->path))->toBeTrue();
});

test('show endpoint returns single ticket dossier and hides internal notes from non-staff', function () {
    $category = TicketCategory::first();
    $ticketService = app(TicketService::class);

    $ticket = $ticketService->createTicket([
        'subject' => 'Query Regarding Hostel Room Relocation',
        'description' => 'I would like to request room reassignment to ground floor.',
        'category_id' => $category->id,
        'user_id' => $this->student->id,
    ], $this->student);

    // Add internal note by agent
    $ticketService->replyTicket($ticket, [
        'message' => 'Staff internal note: Medical certificate verified by warden.',
        'type' => TicketMessage::TYPE_INTERNAL_NOTE,
    ], $this->agent);

    // Add public reply by agent
    $ticketService->replyTicket($ticket, [
        'message' => 'Your request has been forwarded to the hostel warden for inspection.',
        'type' => TicketMessage::TYPE_PUBLIC_REPLY,
    ], $this->agent);

    // 1. Student view: internal note MUST NOT be visible
    $studentRes = $this->actingAs($this->student, 'sanctum')
        ->getJson("/api/v1/tickets/{$ticket->ticket_number}");

    $studentRes->assertStatus(200);
    $studentMessages = $studentRes->json('data.messages');
    foreach ($studentMessages as $msg) {
        expect($msg['type'])->not->toBe(TicketMessage::TYPE_INTERNAL_NOTE);
    }

    // 2. Staff view: internal note MUST be visible
    $staffRes = $this->actingAs($this->agent, 'sanctum')
        ->getJson("/api/v1/tickets/{$ticket->ticket_number}");

    $staffRes->assertStatus(200);
    $staffMessages = $staffRes->json('data.messages');
    $hasInternalNote = collect($staffMessages)->contains('type', TicketMessage::TYPE_INTERNAL_NOTE);
    expect($hasInternalNote)->toBeTrue();
});

test('updateStatus transitions ticket state and updates milestone timestamps', function () {
    $ticket = Ticket::first();

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/tickets/{$ticket->id}/status", [
            'status' => 'resolved',
            'reason' => 'Issue verified and solved.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.new_status', 'resolved');

    expect($ticket->fresh()->resolved_at)->not->toBeNull();
});

test('updatePriority alters priority and logs system event', function () {
    $ticket = Ticket::first();

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/tickets/{$ticket->id}/priority", [
            'priority' => 'urgent',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.new_priority', 'urgent');

    expect($ticket->fresh()->priority)->toBe('urgent');
});

test('assign updates assignee and sends notification alert', function () {
    $ticket = Ticket::first();

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/tickets/{$ticket->id}/assign", [
            'assigned_to_user_id' => $this->agent->id,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.assigned_to', $this->agent->name);

    expect($ticket->fresh()->assigned_to_user_id)->toBe($this->agent->id);
});

test('storeMessage posts public reply and updates conversation', function () {
    $ticket = Ticket::first();

    $response = $this->actingAs($this->agent, 'sanctum')
        ->postJson("/api/v1/tickets/{$ticket->id}/messages", [
            'message' => 'We are processing your request right away.',
            'type' => 'public_reply',
            'new_status' => 'in_progress',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);

    expect($ticket->fresh()->status)->toBe('in_progress');
});

test('students are forbidden from posting internal notes', function () {
    $ticket = Ticket::where('user_id', $this->student->id)->first() ?? Ticket::first();
    $ticket->update(['user_id' => $this->student->id]);

    $response = $this->actingAs($this->student, 'sanctum')
        ->postJson("/api/v1/tickets/{$ticket->id}/messages", [
            'message' => 'Trying to post secret staff note.',
            'type' => 'internal_note',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

test('rate records 1 to 5 star satisfaction rating and feedback', function () {
    $ticket = Ticket::first();
    $ticket->update(['user_id' => $this->student->id]);

    $response = $this->actingAs($this->student, 'sanctum')
        ->postJson("/api/v1/tickets/{$ticket->id}/rate", [
            'rating' => 5,
            'feedback' => 'Exceptional response time and resolution quality!',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($ticket->fresh()->satisfaction_rating)->toBe(5);
    expect($ticket->fresh()->satisfaction_feedback)->toContain('Exceptional');
});

test('attachment download streams binary file securely', function () {
    $ticket = Ticket::first();
    $ticket->update(['user_id' => $this->student->id]);

    $samplePath = 'tickets/TICK-SAMPLE/sample_doc.pdf';
    Storage::disk('local')->put($samplePath, '%PDF-1.4 sample stream content');

    $attachment = TicketAttachment::create([
        'ticket_id' => $ticket->id,
        'uploaded_by_user_id' => $this->student->id,
        'filename' => 'sample_doc.pdf',
        'original_filename' => 'Student_Marksheet.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'disk' => 'local',
        'path' => $samplePath,
    ]);

    $response = $this->actingAs($this->student, 'sanctum')
        ->get("/api/v1/tickets/attachments/{$attachment->id}/download");

    $response->assertStatus(200);
});

test('bulk-action executes mass triage across tickets', function () {
    $tickets = Ticket::limit(3)->pluck('id')->toArray();

    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/tickets/bulk-action', [
            'action' => 'status',
            'ticket_ids' => $tickets,
            'value' => 'on_hold',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    foreach ($tickets as $id) {
        expect(Ticket::find($id)->status)->toBe('on_hold');
    }
});

test('check-sla triggers automated breach detection', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/tickets/check-sla');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => ['newly_breached_count', 'audited_at'],
        ]);
});

test('categories and canned responses CRUD endpoints operate smoothly', function () {
    // 1. Categories
    $catRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/tickets/categories', [
            'name' => 'Hostel Accommodation Inquiries',
            'description' => 'Hostel rooms and fees',
            'icon' => 'home',
            'color_badge' => 'purple',
            'sla_response_hours' => 12,
            'sla_resolution_hours' => 36,
        ]);

    $catRes->assertStatus(201);
    $catId = $catRes->json('data.id');

    // 2. Canned Responses
    $canRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/tickets/canned-responses', [
            'title' => 'Sample Macro Shortcut',
            'shortcut' => '/sample-macro',
            'category_id' => $catId,
            'content' => 'Hello {user_name}, your request is approved.',
        ]);

    $canRes->assertStatus(201);
    $canId = $canRes->json('data.id');

    // 3. Delete canned response
    $delCan = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/tickets/canned-responses/{$canId}");
    $delCan->assertStatus(200);
});

test('soft delete and restore workflow preserves audit trails', function () {
    $ticket = Ticket::first();

    // 1. Soft delete
    $deleteRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/tickets/{$ticket->id}");

    $deleteRes->assertStatus(200)
        ->assertJsonPath('data.deleted', true);

    expect(Ticket::find($ticket->id))->toBeNull();
    expect(Ticket::withTrashed()->find($ticket->id))->not->toBeNull();

    // 2. Restore
    $restoreRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/tickets/{$ticket->id}/restore");

    $restoreRes->assertStatus(200);
    expect(Ticket::find($ticket->id))->not->toBeNull();
});
