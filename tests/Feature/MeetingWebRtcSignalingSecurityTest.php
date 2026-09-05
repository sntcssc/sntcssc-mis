<?php

use App\Events\MeetingRealtimeEvent;
use App\Models\ChatMeeting;
use App\Models\User;
use App\Services\MeetingService;
use App\Services\RbacService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    RbacService::seedDefaults();
    Event::fake([MeetingRealtimeEvent::class]);
});

test('sync endpoint rejects non-participants of closed meetings', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $outsider = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->scheduleMeeting(
        host: $host,
        title: 'Private Sync Test',
        scheduledAt: now()->addHour(),
        accessMode: ChatMeeting::ACCESS_INVITED_ONLY,
    );

    $this->actingAs($outsider)
        ->getJson(route('meetings.sync.direct', ['uuid' => $meeting->uuid]).'?include_signals=1')
        ->assertStatus(403);
});

test('sync endpoint allows host to read cached signals with polling payload', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Sync Host Test');
    $meetingService->joinMeeting($meeting, $guest);

    $meetingService->sendSignal(
        meetingUuid: $meeting->uuid,
        sender: $guest,
        signalType: 'peer_join',
        payload: ['userId' => $guest->id],
    );

    $this->actingAs($host)
        ->getJson(route('meetings.sync.direct', ['uuid' => $meeting->uuid]).'?include_signals=1')
        ->assertOk()
        ->assertJsonStructure(['ok', 'reactions', 'timestamp', 'signals'])
        ->assertJsonPath('signals.0.signalType', 'peer_join')
        ->assertJsonPath('signals.0.fromUserId', $guest->id);
});

test('signal endpoint rejects non-participants of closed meetings', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $outsider = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->scheduleMeeting(
        host: $host,
        title: 'Private Signal Test',
        scheduledAt: now()->addHour(),
        accessMode: ChatMeeting::ACCESS_INVITED_ONLY,
    );

    $this->actingAs($outsider)
        ->postJson(route('meetings.signal.direct', ['uuid' => $meeting->uuid]), [
            'signalType' => 'peer_join',
            'payload' => ['userId' => $outsider->id],
        ])
        ->assertStatus(403);

    Event::assertNotDispatched(MeetingRealtimeEvent::class);
});

test('signal endpoint rejects oversized payloads', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Oversize Payload Test');

    $this->actingAs($host)
        ->postJson(route('meetings.signal.direct', ['uuid' => $meeting->uuid]), [
            'signalType' => 'offer',
            'payload' => ['sdp' => str_repeat('a', 80000)],
        ])
        ->assertStatus(422);
});

test('signal endpoint accepts realistic SDP offer sizes after Reverb limit fix', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'SDP Size Test');
    $meetingService->joinMeeting($meeting, $guest);

    // A typical Chrome video offer serialized to ~25KB must pass the 60KB ceiling
    // so WebRTC negotiation is never rejected for size.
    $sdp = 'v=0'.str_repeat("\r\no=- 4611731400430051336 2 IN IP4 127.0.0.1", 400);

    $this->actingAs($host)
        ->postJson(route('meetings.signal.direct', ['uuid' => $meeting->uuid]), [
            'signalType' => 'offer',
            'targetUserId' => $guest->id,
            'payload' => ['sdp' => ['type' => 'offer', 'sdp' => $sdp]],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

test('sendSignal broadcasts compact payload without duplicate wrapper keys', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Compact Payload Test');

    $meetingService->sendSignal(
        meetingUuid: $meeting->uuid,
        sender: $host,
        signalType: 'offer',
        payload: ['sdp' => ['type' => 'offer', 'sdp' => 'v=0']],
    );

    Event::assertDispatched(MeetingRealtimeEvent::class, function (MeetingRealtimeEvent $event) {
        if ($event->eventType !== 'meeting_signal') {
            return false;
        }

        // Signal metadata stays at the top level and the raw signal (sdp) rides in
        // "payload" exactly once, keeping large SDP strings from being duplicated.
        expect($event->payload)->toHaveKeys(['signalType', 'fromUserId', 'targetUserId', 'payload']);
        expect($event->payload['payload'])->toHaveKey('sdp');

        return true;
    });
});

test('getRecentSignals excludes signals sent by the requesting user', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Exclude Own Signals Test');

    $meetingService->sendSignal(
        meetingUuid: $meeting->uuid,
        sender: $host,
        signalType: 'peer_state',
        payload: ['isMuted' => true],
    );

    $forHost = $meetingService->getRecentSignals($meeting->uuid, 0, $host->id);
    expect($forHost)->toBeEmpty();

    $forGuest = $meetingService->getRecentSignals($meeting->uuid, 0, $guest->id);
    expect($forGuest)->not->toBeEmpty();
    expect($forGuest[0]['signalType'])->toBe('peer_state');
});

test('ended meetings refuse new signals', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Ended Meeting Test');
    $meetingService->endMeeting($meeting, $host);

    $this->actingAs($host)
        ->postJson(route('meetings.signal.direct', ['uuid' => $meeting->uuid]), [
            'signalType' => 'peer_state',
            'payload' => ['isMuted' => true],
        ])
        ->assertStatus(410);
});

test('unauthenticated users cannot access signal or sync endpoints', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Auth Gate Test');

    $this->postJson(route('meetings.signal.direct', ['uuid' => $meeting->uuid]), ['signalType' => 'peer_join'])
        ->assertStatus(401);

    $this->getJson(route('meetings.sync.direct', ['uuid' => $meeting->uuid]))
        ->assertStatus(401);
});

test('signal cache entries expire and are capped', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);
    $meeting = $meetingService->createInstantMeeting(host: $host, title: 'Cache Cap Test');

    for ($i = 0; $i < 60; $i++) {
        $meetingService->sendSignal(
            meetingUuid: $meeting->uuid,
            sender: $host,
            signalType: 'peer_state',
            payload: ['seq' => $i],
        );
    }

    $cached = Cache::get("meeting:{$meeting->uuid}:signals");
    expect($cached)->toBeArray();
    expect(count($cached))->toBeLessThanOrEqual(50);
    expect($cached[49]['payload']['seq'])->toBe(59);
});
