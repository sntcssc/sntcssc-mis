<?php

use App\Models\ChatConversation;
use App\Models\ChatMeeting;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\MeetingService;
use App\Services\RbacService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    Setting::set('chat.enabled', true);
    Setting::set('meeting.enabled', true);
    Setting::set('chat.meetings_enabled', true);

    $this->team = Team::first() ?? Team::create(['name' => 'Main Institute', 'personal_team' => false]);
});

test('users without chat.access cannot access live chat routes or join links', function () {
    $user = User::factory()->create();
    $user->ensureCurrentTeam();

    // Direct chat routes
    $this->actingAs($user)
        ->get(route('chat.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('admin.chat.index'))
        ->assertForbidden();

    // Chat join link
    $this->actingAs($user)
        ->get(route('chat.join', ['code' => 'invalid-or-valid-code']))
        ->assertForbidden();

    // Livewire component direct mount
    Livewire::actingAs($user)
        ->test('pages::portal.chat')
        ->assertStatus(403);
});

test('users with chat.access can access live chat and chat join link', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $student->ensureCurrentTeam();

    $this->actingAs($student)
        ->get(route('chat.index'))
        ->assertOk();

    $this->actingAs($student)
        ->get(route('admin.chat.index'))
        ->assertOk();

    Livewire::actingAs($student)
        ->test('pages::portal.chat')
        ->assertOk();

    // Test join link with an actual conversation
    $conversation = ChatConversation::create([
        'title' => 'Open Discussion',
        'type' => ChatConversation::TYPE_GROUP,
        'invite_code' => 'join-test-code-123',
    ]);

    $this->actingAs($student)
        ->get(route('chat.join', ['code' => $conversation->invite_code]))
        ->assertRedirect();
});

test('users without meetings.view cannot access meetings routes or join links', function () {
    $user = User::factory()->create();
    $user->ensureCurrentTeam();

    // Direct meeting routes
    $this->actingAs($user)
        ->get(route('meetings.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('meetings.join', ['code' => 'meet-code-123']))
        ->assertForbidden();

    // Direct room access
    $host = User::factory()->create();
    $host->assignRole('Super Administrator');
    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Weekly Strategy',
        mode: ChatMeeting::MODE_VIDEO
    );

    $this->actingAs($user)
        ->get(route('meetings.room', ['uuid' => $meeting->uuid]))
        ->assertForbidden();

    // Direct signaling and sync routes
    $this->actingAs($user)
        ->post(route('meetings.signal.direct', ['uuid' => $meeting->uuid]), ['type' => 'ping'])
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('meetings.sync.direct', ['uuid' => $meeting->uuid]))
        ->assertForbidden();

    // Livewire components direct mount
    Livewire::actingAs($user)
        ->test('pages::portal.meetings')
        ->assertStatus(403);

    Livewire::actingAs($user)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->assertStatus(403);
});

test('users with meetings.view can access meetings list, room, and join link', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $student->ensureCurrentTeam();

    $this->actingAs($student)
        ->get(route('meetings.index'))
        ->assertOk();

    Livewire::actingAs($student)
        ->test('pages::portal.meetings')
        ->assertOk();

    $host = User::factory()->create();
    $host->assignRole('Super Administrator');
    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Interactive Q&A Session',
        mode: ChatMeeting::MODE_VIDEO
    );

    $this->actingAs($student)
        ->get(route('meetings.join', ['code' => $meeting->invite_code]))
        ->assertRedirect(route('meetings.room', ['current_team' => $student->currentTeam->slug, 'uuid' => $meeting->uuid]));

    $this->actingAs($student)
        ->get(route('meetings.room', ['uuid' => $meeting->uuid]))
        ->assertOk();

    Livewire::actingAs($student)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->assertOk();
});

test('chat and meetings links are properly gated in topbar, sidebar, and dashboard', function () {
    // 1. User without permissions
    $plainUser = User::factory()->create();
    $plainUser->ensureCurrentTeam();

    $plainResponse = $this->actingAs($plainUser)->get(route('dashboard'));
    $plainResponse->assertOk();
    $plainResponse->assertDontSee(route('admin.chat.index'));
    $plainResponse->assertDontSee(route('meetings.index'));
    $plainResponse->assertDontSee('Live Community Chat');
    $plainResponse->assertDontSee('Online Meetings & Classes');

    // 2. User with chat.access and meetings.view (e.g. Student)
    $student = User::factory()->create();
    $student->assignRole('Student');
    $studentTeam = $student->ensureCurrentTeam();

    $studentResponse = $this->actingAs($student)->get(route('dashboard', ['current_team' => $studentTeam->slug]));
    $studentResponse->assertOk();
    $studentResponse->assertSee(route('admin.chat.index'));
    $studentResponse->assertSee(route('meetings.index'));
});

test('users without meetings.create cannot see create buttons or trigger creation actions', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $student->ensureCurrentTeam();

    // Verify UI buttons and creation modals are hidden
    Livewire::actingAs($student)->test('pages::portal.meetings')
        ->assertDontSee('Start Instant Meeting')
        ->assertDontSee('Schedule Online Meeting')
        ->assertDontSee('instant-meeting-modal')
        ->assertDontSee('schedule-meeting-modal');

    // Verify instant creation action is rejected with 403
    Livewire::actingAs($student)->test('pages::portal.meetings')
        ->call('createInstantMeeting')
        ->assertForbidden();

    // Verify schedule creation action is rejected with 403
    Livewire::actingAs($student)->test('pages::portal.meetings')
        ->call('scheduleMeeting')
        ->assertForbidden();

    // Verify open instant modal is rejected with 403
    Livewire::actingAs($student)->test('pages::portal.meetings')
        ->call('openInstantModal')
        ->assertForbidden();
});

test('users with meetings.create can view create buttons and create meetings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $admin->ensureCurrentTeam();

    $component = Livewire::actingAs($admin)->test('pages::portal.meetings');
    $component->assertSee('Start Instant Meeting');
    $component->assertSee('Schedule Meeting');

    $component->set('instantTopic', 'Emergency Faculty Sync')
        ->set('instantMode', 'video')
        ->set('instantAccessMode', 'open')
        ->call('createInstantMeeting')
        ->assertHasNoErrors()
        ->assertRedirect();
});

test('chat group and channel creation actions are gated by permissions', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $student->ensureCurrentTeam();

    // Student has chat.access, chat.voice_call, chat.video_call, but NOT chat.create_group or chat.create_channel
    // Verify creation modals and dropdown items are hidden
    Livewire::actingAs($student)->test('pages::portal.chat')
        ->assertDontSee('new-group-modal')
        ->assertDontSee('new-channel-modal');

    // createGroup should be rejected with 403
    Livewire::actingAs($student)->test('pages::portal.chat')
        ->call('createGroup')
        ->assertForbidden();

    // createChannel should be rejected with 403
    Livewire::actingAs($student)->test('pages::portal.chat')
        ->call('createChannel')
        ->assertForbidden();
});
