<?php

use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Models\User;
use App\Services\MeetingService;
use App\Services\RbacService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('MeetingService can create instant and scheduled online meetings', function () {
    $host = User::factory()->create(['name' => 'Prof. Sharma', 'email_verified_at' => now()]);
    $student1 = User::factory()->create(['name' => 'Aarav Gupta', 'email_verified_at' => now()]);
    $student2 = User::factory()->create(['name' => 'Priya Patel', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    // 1. Instant Meeting
    $instantMeeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Instant Doubt Solving',
        mode: ChatMeeting::MODE_VIDEO
    );

    expect($instantMeeting)->not->toBeNull();
    expect($instantMeeting->status)->toBe(ChatMeeting::STATUS_LIVE);
    expect($instantMeeting->type)->toBe(ChatMeeting::TYPE_INSTANT);
    expect($instantMeeting->host_id)->toBe($host->id);
    expect($instantMeeting->invite_code)->not->toBeEmpty();
    expect($instantMeeting->join_url)->toContain($instantMeeting->invite_code);

    // 2. Scheduled Meeting with Invitees
    $scheduledAt = now()->addDays(2);
    $scheduledMeeting = $meetingService->scheduleMeeting(
        host: $host,
        title: 'UPSC Mains Essay Strategy Webinar',
        scheduledAt: $scheduledAt,
        mode: ChatMeeting::MODE_VIDEO,
        durationMinutes: 90,
        description: 'Comprehensive discussion on structure and scoring tips.',
        passcode: '987654',
        inviteeUserIds: [$student1->id, $student2->id],
        dispatchChannels: ['database']
    );

    expect($scheduledMeeting)->not->toBeNull();
    expect($scheduledMeeting->status)->toBe(ChatMeeting::STATUS_SCHEDULED);
    expect($scheduledMeeting->type)->toBe(ChatMeeting::TYPE_SCHEDULED);
    expect($scheduledMeeting->passcode)->toBe('987654');
    expect($scheduledMeeting->participants()->count())->toBe(3); // host + 2 invitees
});

test('User can join meeting room and host can end meeting for all', function () {
    $host = User::factory()->create(['name' => 'Host User', 'email_verified_at' => now()]);
    $guest = User::factory()->create(['name' => 'Guest Student', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Weekly Standup'
    );

    // Guest joins
    $participant = $meetingService->joinMeeting($meeting, $guest);
    expect($participant->status)->toBe(ChatMeetingParticipant::STATUS_JOINED);
    expect($meeting->participants()->where('status', ChatMeetingParticipant::STATUS_JOINED)->count())->toBe(2);

    // Host ends meeting
    $ended = $meetingService->endMeeting($meeting, $host);
    expect($ended)->toBeTrue();
    expect($meeting->fresh()->status)->toBe(ChatMeeting::STATUS_ENDED);
    expect($meeting->fresh()->isEnded())->toBeTrue();
});

test('User can join meeting via invite link code', function () {
    $host = User::factory()->create(['name' => 'Host Teacher', 'email_verified_at' => now()]);
    $student = User::factory()->create(['name' => 'Student Learner', 'email_verified_at' => now()]);
    $student->givePermissionTo('chat.access');

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Mathematics Problem Solving'
    );

    $response = $this->actingAs($student)->get(route('meetings.join', ['code' => $meeting->invite_code]));
    $teamSlug = $student->currentTeam?->slug ?? $student->allTeams()->first()?->slug ?? 'default';

    $response->assertRedirect(route('meetings.room', [
        'current_team' => $teamSlug,
        'uuid' => $meeting->uuid,
    ]));
});

test('Livewire Meetings Hub renders upcoming and allows scheduling', function () {
    $host = User::factory()->create(['name' => 'Faculty Member', 'email_verified_at' => now()]);
    $host->givePermissionTo('chat.access');

    Livewire::actingAs($host)
        ->test('pages::portal.meetings')
        ->assertSee('Online Meetings & Video Conferences')
        ->set('meetingTitle', 'Live Q&A Session with Mentors')
        ->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingMode', 'video')
        ->set('durationMinutes', 45)
        ->call('scheduleMeeting')
        ->assertHasNoErrors();

    expect(ChatMeeting::where('title', 'Live Q&A Session with Mentors')->exists())->toBeTrue();
});

test('Meeting with invited_only access requires waiting room admission', function () {
    $host = User::factory()->create(['name' => 'Dr. Mukherjee', 'email_verified_at' => now()]);
    $invitedStudent = User::factory()->create(['name' => 'Invited Student', 'email_verified_at' => now()]);
    $uninvitedGuest = User::factory()->create(['name' => 'Uninvited Guest', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->scheduleMeeting(
        host: $host,
        title: 'Exclusive Closed Mentorship',
        scheduledAt: now()->addDay(),
        accessMode: ChatMeeting::ACCESS_INVITED_ONLY,
        inviteeUserIds: [$invitedStudent->id]
    );

    // 1. Invited student joins directly
    $part1 = $meetingService->joinMeeting($meeting, $invitedStudent);
    expect($part1->status)->toBe(ChatMeetingParticipant::STATUS_JOINED);

    // 2. Uninvited guest enters waiting room
    $part2 = $meetingService->joinMeeting($meeting, $uninvitedGuest);
    expect($part2->status)->toBe(ChatMeetingParticipant::STATUS_WAITING);

    // 3. Host admits guest
    $admitted = $meetingService->admitParticipant($meeting, $part2->id, $host);
    expect($admitted)->toBeTrue();
    expect($part2->fresh()->status)->toBe(ChatMeetingParticipant::STATUS_JOINED);
});

test('Host can promote co-host and update room restrictions', function () {
    $host = User::factory()->create(['name' => 'Chief Host', 'email_verified_at' => now()]);
    $member = User::factory()->create(['name' => 'Assistant Teacher', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Science Seminar');
    $part = $meetingService->joinMeeting($meeting, $member);

    // Promote to co-host
    $promoted = $meetingService->setParticipantRole($meeting, $part->id, ChatMeetingParticipant::ROLE_CO_HOST, $host);
    expect($promoted)->toBeTrue();
    expect($meeting->isHostOrCoHost($member))->toBeTrue();

    // Co-host updates meeting restrictions
    $updated = $meetingService->updateRestrictions($meeting, ['chat_enabled' => false], $member);
    expect($updated)->toBeTrue();
    expect($meeting->fresh()->isChatAllowed())->toBeFalse();
});

test('MeetingService sends bulk email invitations to list of custom emails', function () {
    Mail::fake();

    $host = User::factory()->create(['name' => 'Webinar Host', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Open Public Webinar');
    $emails = ['candidate1@gmail.com', 'candidate2@domain.edu'];

    $sent = $meetingService->sendBulkEmailInvitations($meeting, $emails, $host);
    expect($sent)->toBe(2);
});

test('Livewire Meetings Hub creates instant meeting with custom topic and access mode', function () {
    $host = User::factory()->create(['name' => 'Instructor Vikram', 'email_verified_at' => now()]);

    Livewire::actingAs($host)
        ->test('pages::portal.meetings')
        ->set('instantTopic', 'Quick UPSC Prelims Doubts')
        ->set('instantMode', 'video')
        ->set('instantAccessMode', 'invited_only')
        ->call('createInstantMeeting')
        ->assertHasNoErrors();

    $meeting = ChatMeeting::where('title', 'Quick UPSC Prelims Doubts')->first();
    expect($meeting)->not->toBeNull();
    expect($meeting->access_mode)->toBe(ChatMeeting::ACCESS_INVITED_ONLY);
    expect($meeting->isLive())->toBeTrue();
});

test('Livewire Meeting Room supports in-meeting chat pinning', function () {
    $host = User::factory()->create(['name' => 'Host Teacher', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Interactive Masterclass');

    Livewire::actingAs($host)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->call('joinRoomFromLobby')
        ->set('showChatDrawer', true)
        ->set('inRoomMessage', 'Meeting Agenda: Chapter 4 Discussion')
        ->call('sendRoomMessage')
        ->call('pinInRoomMessage', 0)
        ->assertSee('Meeting Agenda: Chapter 4 Discussion')
        ->call('unpinInRoomMessage')
        ->assertHasNoErrors();
});

test('Host can customize meeting link slug in meeting room', function () {
    $host = User::factory()->create(['name' => 'Host Lead', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Sprint Retrospective');

    Livewire::actingAs($host)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->set('editMeetingSlug', 'sprint-retro-2026')
        ->call('saveMeetingSlug')
        ->assertHasNoErrors();

    expect($meeting->fresh()->invite_code)->toBe('sprint-retro-2026');
});

test('Meeting chat moderation allows host and allowed participants to edit and delete messages', function () {
    $host = User::factory()->create(['name' => 'Host Lead', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Moderated Discussion');

    Livewire::actingAs($host)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->call('joinRoomFromLobby')
        ->set('showChatDrawer', true)
        ->set('inRoomMessage', 'Draft message to be edited')
        ->call('sendRoomMessage')
        ->call('startEditInRoomMessage', 0)
        ->set('editingInRoomText', 'Final edited message body')
        ->call('saveEditInRoomMessage')
        ->assertSee('Final edited message body')
        ->call('deleteInRoomMessage', 0)
        ->assertDontSee('Final edited message body');
});

test('Host can update scheduled meeting details and recurrence', function () {
    $host = User::factory()->create(['name' => 'Prof Sen', 'email_verified_at' => now()]);
    $student = User::factory()->create(['name' => 'Student Rahul', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $start = now()->addDays(3);
    $end = $start->copy()->addMinutes(90);

    $meeting = $meetingService->scheduleMeeting(
        host: $host,
        title: 'Weekly Seminar Series',
        scheduledAt: $start,
        endsAt: $end,
        repeatType: ChatMeeting::REPEAT_WEEKLY,
        reminderOffsetMinutes: 30,
        inviteeUserIds: [$student->id]
    );

    expect($meeting->repeat_type)->toBe(ChatMeeting::REPEAT_WEEKLY);
    expect($meeting->reminder_offset_minutes)->toBe(30);
    expect($meeting->formattedDuration())->toBe('1 hr 30 mins');

    // Update meeting details
    $newStart = now()->addDays(4);
    $newEnd = $newStart->copy()->addHours(2);

    $updated = $meetingService->updateMeeting(
        meeting: $meeting,
        actor: $host,
        data: [
            'title' => 'Updated Weekly Seminar Series',
            'scheduled_at' => $newStart,
            'ends_at' => $newEnd,
            'duration_minutes' => 120,
            'repeat_type' => ChatMeeting::REPEAT_DAILY,
            'reminder_offset_minutes' => 60,
        ]
    );

    expect($updated->title)->toBe('Updated Weekly Seminar Series');
    expect($updated->repeat_type)->toBe(ChatMeeting::REPEAT_DAILY);
    expect($updated->reminder_offset_minutes)->toBe(60);
    expect($updated->duration_minutes)->toBe(120);
});

test('Host can cancel meeting with multi-channel cancellation notice', function () {
    $host = User::factory()->create(['name' => 'Host Lead', 'email_verified_at' => now()]);
    $attendee = User::factory()->create(['name' => 'Attendee John', 'email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->scheduleMeeting(
        host: $host,
        title: 'Project Kickoff Meeting',
        scheduledAt: now()->addDay(),
        inviteeUserIds: [$attendee->id]
    );

    $cancelled = $meetingService->cancelMeeting(
        meeting: $meeting,
        actor: $host,
        reason: 'Host on emergency leave',
        notifyChannels: ['database']
    );

    expect($cancelled)->toBeTrue();
    expect($meeting->fresh()->status)->toBe(ChatMeeting::STATUS_CANCELLED);
});
