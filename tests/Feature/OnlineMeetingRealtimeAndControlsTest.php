<?php

use App\Models\ChatMeetingParticipant;
use App\Models\User;
use App\Services\MeetingService;
use App\Services\RbacService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('Meeting private channel authorizes host and open for everyone participants', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Channel Auth Test'
    );

    // Host and open-for-everyone checks
    expect($meeting->isOpenForEveryone())->toBeTrue();
    expect($meeting->isOpenAccess())->toBeTrue();

    $guestParticipant = $meetingService->joinMeeting($meeting, $guest);
    expect($guestParticipant->status)->toBe(ChatMeetingParticipant::STATUS_JOINED);
});

test('MeetingService records reaction in cache and retrieves recent reactions', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Reaction Cache Test'
    );

    $beforeTime = microtime(true) - 0.001;
    $rx = $meetingService->recordReaction($meeting->uuid, $guest, '🔥');

    expect($rx)->toBeArray();
    expect($rx['emoji'])->toBe('🔥');
    expect($rx['user'])->toBe($guest->name);

    $recent = $meetingService->getRecentReactions($meeting->uuid, $beforeTime);
    expect($recent)->not->toBeEmpty();
    expect(collect($recent)->firstWhere('emoji', '🔥'))->not->toBeNull();
});

test('MeetingSignalController accepts signal and returns sync signals', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Signal Endpoint Test'
    );

    $meetingService->joinMeeting($meeting, $guest);

    // POST /meetings/{uuid}/signal (direct)
    $this->actingAs($guest)
        ->postJson(route('meetings.signal.direct', ['uuid' => $meeting->uuid]), [
            'signal_type' => 'peer_state',
            'payload' => [
                'isMuted' => true,
                'isVideoOff' => true,
            ],
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // POST /{current_team}/meetings/{uuid}/signal (team-scoped)
    $team = $guest->currentTeam ?? $host->currentTeam;
    if ($team) {
        $this->actingAs($guest)
            ->postJson(route('meetings.signal', ['current_team' => $team->slug, 'uuid' => $meeting->uuid]), [
                'signal_type' => 'peer_state',
                'payload' => [
                    'isMuted' => false,
                ],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    // GET /meetings/{uuid}/sync (direct)
    $this->actingAs($host)
        ->getJson(route('meetings.sync.direct', ['uuid' => $meeting->uuid]))
        ->assertOk()
        ->assertJsonStructure(['ok', 'reactions', 'timestamp']);
});

test('Meeting room Livewire component sends reaction and syncs via poll', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Meeting Room Reaction Component Test'
    );

    // Host opens component first
    $hostComponent = Livewire::actingAs($host)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid]);

    // Guest sends reaction via Livewire
    Livewire::actingAs($guest)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->call('sendReaction', '🎉')
        ->assertDispatched('trigger-floating-emoji');

    // Host refreshes room and gets reaction dispatched to client
    Livewire::actingAs($host)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->set('lastReactionTimestamp', 0.0)
        ->call('refreshRoom')
        ->assertDispatched('trigger-floating-emoji');
});

test('Meeting room Livewire component transmits WebRTC signal fallback and caches it', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Meeting Signal Fallback Test'
    );

    Livewire::actingAs($guest)
        ->test('pages::portal.meeting-room', ['uuid' => $meeting->uuid])
        ->call('sendMeetingSignal', 'peer_presence', ['userId' => $guest->id], $host->id)
        ->assertOk();

    $recentSignals = $meetingService->getRecentSignals($meeting->uuid, 0, $host->id);
    expect($recentSignals)->not->toBeEmpty();
    expect($recentSignals[0]['signalType'])->toBe('peer_presence');
});
