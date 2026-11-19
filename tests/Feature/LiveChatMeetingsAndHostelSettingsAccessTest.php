<?php

use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\HostelSeeder;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(HostelSeeder::class);

    $this->team = Team::first() ?? Team::create(['name' => 'Main Institute', 'personal_team' => false]);
});

test('live chat is hidden from sidebar and inaccessible when feature is disabled', function () {
    Setting::set('chat.enabled', false);

    $student = User::factory()->create([
        'name' => 'Amit Student',
        'email' => 'amit.chat@example.com',
        'student_id' => 'STU-CHAT-01',
    ]);
    $student->assignRole('Student');

    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $response->assertDontSee('Live Chat');

    // Attempt direct access
    $this->actingAs($student)
        ->get(route('admin.chat.index'))
        ->assertForbidden();
});

test('live chat is visible in sidebar and accessible when feature is enabled and user has access', function () {
    Setting::set('chat.enabled', true);

    $student = User::factory()->create([
        'name' => 'Amit Student',
        'email' => 'amit.chat2@example.com',
        'student_id' => 'STU-CHAT-02',
    ]);
    $student->assignRole('Student');

    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $response->assertSee('Live Chat');

    $this->actingAs($student)
        ->get(route('admin.chat.index'))
        ->assertOk();
});

test('admin can visit chat route when transport driver is polling without undefined variable error', function () {
    Setting::set('chat.enabled', true);
    Setting::set('chat.transport_driver', 'polling');

    $admin = User::factory()->create([
        'name' => 'Admin User Chat',
        'email' => 'admin.chat.polling@example.com',
    ]);
    $admin->assignRole('Super Administrator');

    $response = $this->actingAs($admin)
        ->get(route('admin.chat.index'));

    $response->assertOk();
    $response->assertSee('Polling');
});

test('online meetings is hidden from sidebar and inaccessible when feature is disabled', function () {
    Setting::set('meeting.enabled', false);
    Setting::set('chat.meetings_enabled', false);

    $student = User::factory()->create([
        'name' => 'Rohit Student',
        'email' => 'rohit.meet@example.com',
        'student_id' => 'STU-MEET-01',
    ]);
    $student->assignRole('Student');

    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $response->assertDontSee('Online Meetings');

    // Attempt direct access
    $this->actingAs($student)
        ->get(route('meetings.index'))
        ->assertForbidden();
});

test('online meetings is visible in sidebar and accessible when feature is enabled and user has access', function () {
    Setting::set('meeting.enabled', true);
    Setting::set('chat.meetings_enabled', true);

    $student = User::factory()->create([
        'name' => 'Rohit Student',
        'email' => 'rohit.meet2@example.com',
        'student_id' => 'STU-MEET-02',
    ]);
    $student->assignRole('Student');

    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $response->assertSee('Online Meetings');

    $this->actingAs($student)
        ->get(route('meetings.index'))
        ->assertOk();
});

test('live chat is hidden and inaccessible when user lacks chat.access permission even if enabled', function () {
    Setting::set('chat.enabled', true);

    // Standard user without Student or Admin role (no chat.access)
    $user = User::factory()->create([
        'name' => 'Guest User',
        'email' => 'guest.nochat@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]));

    $response->assertOk();
    $response->assertDontSee('Live Chat');

    $this->actingAs($user)
        ->get(route('admin.chat.index'))
        ->assertForbidden();
});

test('online meetings is hidden and inaccessible when user lacks meetings.view permission even if enabled', function () {
    Setting::set('meeting.enabled', true);
    Setting::set('chat.meetings_enabled', true);

    $user = User::factory()->create([
        'name' => 'Guest User 2',
        'email' => 'guest.nomeet@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]));

    $response->assertOk();
    $response->assertDontSee('Online Meetings');

    $this->actingAs($user)
        ->get(route('meetings.index'))
        ->assertForbidden();
});

test('student without hostel assignment or history does not see hostel in sidebar and cannot download form', function () {
    $student = User::factory()->create([
        'name' => 'Sunil NoHostel',
        'email' => 'sunil.nohostel@example.com',
        'student_id' => 'STU-HOSTEL-00',
    ]);
    $student->assignRole('Student');

    // Ensure student has no hostel allocations
    expect($student->hostelAllocations()->exists())->toBeFalse();

    // Check dashboard & sidebar: hostel must not be visible
    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $response->assertDontSee('My Hostel');

    // Direct page view has no active accommodation and no download button
    $pageResponse = $this->actingAs($student)
        ->get(route('student.hostel.index'))
        ->assertOk();
    $pageResponse->assertSee('No Active Hostel Accommodation');
    $pageResponse->assertDontSee('Download Hostel Form (PDF)');

    // Form download blocked: only active accommodate students can download
    $this->actingAs($student)
        ->get(route('hostel.form.download'))
        ->assertForbidden();
});

test('student with vacated past hostel history sees hostel in sidebar but cannot download hostel form', function () {
    $student = User::factory()->create([
        'name' => 'Vikas PastHostel',
        'email' => 'vikas.pasthostel@example.com',
        'student_id' => 'STU-HOSTEL-01',
    ]);
    $student->assignRole('Student');

    $room = HostelRoom::first();
    $bed = HostelBed::where('hostel_room_id', $room->id)->first();

    HostelAllocation::create([
        'allocation_no' => 'HST-TEST-VACATED-01',
        'user_id' => $student->id,
        'resident_type' => 'student',
        'hostel_room_id' => $room->id,
        'hostel_bed_id' => $bed->id,
        'start_date' => now()->subMonths(6),
        'end_date' => now()->subMonths(1),
        'actual_vacated_date' => now()->subMonths(1),
        'status' => 'vacated',
    ]);

    // Has history
    expect($student->hostelAllocations()->exists())->toBeTrue();
    // But no active allocation
    expect($student->activeHostelAllocation)->toBeNull();

    // Should see My Hostel in sidebar
    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $response->assertSee('My Hostel');

    // Can visit student hostel page to see past history
    $this->actingAs($student)
        ->get(route('student.hostel.index'))
        ->assertOk()
        ->assertSee('Past Hostel Accommodations History');

    // But CANNOT download hostel form because not actively accommodated
    $this->actingAs($student)
        ->get(route('hostel.form.download'))
        ->assertForbidden();
});

test('active accommodated student sees hostel in sidebar and can download hostel form', function () {
    $student = User::factory()->create([
        'name' => 'Karan ActiveHostel',
        'email' => 'karan.activehostel@example.com',
        'student_id' => 'STU-HOSTEL-02',
    ]);
    $student->assignRole('Student');

    $room = HostelRoom::first();
    $bed = HostelBed::where('hostel_room_id', $room->id)->first();

    HostelAllocation::create([
        'allocation_no' => 'HST-TEST-ACTIVE-01',
        'user_id' => $student->id,
        'resident_type' => 'student',
        'hostel_room_id' => $room->id,
        'hostel_bed_id' => $bed->id,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(5),
        'status' => 'active',
    ]);

    expect($student->activeHostelAllocation)->not->toBeNull();
    expect($student->activeHostelAllocation->isActive())->toBeTrue();

    // Should see My Hostel in sidebar
    $response = $this
        ->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $response->assertSee('My Hostel');

    // Can view active accommodation
    $this->actingAs($student)
        ->get(route('student.hostel.index'))
        ->assertOk()
        ->assertSee('Download Hostel Form (PDF)');

    // Can download hostel form successfully
    $downloadResponse = $this->actingAs($student)
        ->get(route('hostel.form.download'));

    $downloadResponse->assertOk();
    expect($downloadResponse->headers->get('content-type'))->toBe('application/pdf');
});

test('when chat realtime driver is set to polling websockets are disabled and reverb key is omitted from head', function () {
    Setting::set('chat.enabled', true);
    Setting::set('chat.transport_driver', 'polling');

    $student = User::factory()->create([
        'name' => 'Polling Test User',
        'email' => 'polling.test@example.com',
    ]);
    $student->assignRole('Student');

    $response = $this->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $content = $response->getContent();

    expect($content)->toContain('<meta name="chat-transport-driver" content="polling" />')
        ->and($content)->toContain('<meta name="websocket-enabled" content="false" />')
        ->and($content)->toContain('<meta name="broadcast-driver" content="null" />')
        ->and($content)->not->toContain('<meta name="reverb-key"');
});

test('when chat realtime driver is hybrid and reverb is configured websockets are enabled in head', function () {
    Setting::set('chat.enabled', true);
    Setting::set('chat.transport_driver', 'hybrid');
    config(['broadcasting.default' => 'reverb']);
    config(['broadcasting.connections.reverb.key' => 'test-reverb-key-123']);

    $student = User::factory()->create([
        'name' => 'Hybrid Test User',
        'email' => 'hybrid.test@example.com',
    ]);
    $student->assignRole('Student');

    $response = $this->actingAs($student)
        ->get(route('dashboard', ['current_team' => $student->currentTeam->slug]));

    $response->assertOk();
    $content = $response->getContent();

    expect($content)->toContain('<meta name="chat-transport-driver" content="hybrid" />')
        ->and($content)->toContain('<meta name="websocket-enabled" content="true" />')
        ->and($content)->toContain('<meta name="broadcast-driver" content="reverb" />')
        ->and($content)->toContain('<meta name="reverb-key" content="test-reverb-key-123" />');
});
