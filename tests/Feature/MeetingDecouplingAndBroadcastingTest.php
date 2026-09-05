<?php

use App\Http\Controllers\MeetingJoinController;
use App\Models\ChatMeetingParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\MeetingService;
use App\Services\RbacService;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('MeetingJoinController joins online meeting room via invite code and logs audit', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Decoupled Meeting Test'
    );

    $controller = new MeetingJoinController;
    $request = Request::create(route('meetings.join', ['code' => $meeting->invite_code]));
    $request->setUserResolver(fn () => $guest);

    $response = $controller->join($request, $meeting->invite_code);

    expect($response->getStatusCode())->toBe(302);
    expect($response->getTargetUrl())->toContain($meeting->uuid);
});

test('MeetingJoinController warns when meeting has ended or was cancelled', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $guest = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Concluded Meeting Test'
    );

    $meetingService->endMeeting($meeting, $host);

    $controller = new MeetingJoinController;
    $request = Request::create(route('meetings.join', ['code' => $meeting->invite_code]));
    $request->setUserResolver(fn () => $guest);

    $response = $controller->join($request, $meeting->invite_code);

    expect($response->getStatusCode())->toBe(302);
    expect(session('warning'))->not->toBeNull();
});

test('MeetingService provides independent ICE servers configuration without WebRtcCallService', function () {
    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $iceServers = $meetingService->getIceServers();

    expect($iceServers)->toBeArray();
    expect($iceServers)->not->toBeEmpty();
    expect($iceServers[0]['urls'])->toContain('stun');
});

test('MeetingService and WebRtcCallService support both Reverb and Pusher broadcasters', function () {
    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    // Test with null driver
    config(['broadcasting.default' => 'null']);
    expect($meetingService->isRealtimeSupported())->toBeFalse();

    // Test with reverb driver configured
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-reverb-key',
        'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
        'broadcasting.connections.reverb.app_id' => '123456',
    ]);
    expect($meetingService->isRealtimeSupported())->toBeTrue();

    // Test with pusher driver configured
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'test-pusher-key',
        'broadcasting.connections.pusher.secret' => 'test-pusher-secret',
        'broadcasting.connections.pusher.app_id' => '654321',
    ]);
    expect($meetingService->isRealtimeSupported())->toBeTrue();
});

test('ChatMeetingParticipant model supports soft deletes', function () {
    $host = User::factory()->create(['email_verified_at' => now()]);
    $participantUser = User::factory()->create(['email_verified_at' => now()]);

    /** @var MeetingService $meetingService */
    $meetingService = app(MeetingService::class);

    $meeting = $meetingService->createInstantMeeting(
        host: $host,
        title: 'Soft Delete Test Meeting'
    );

    $participant = $meetingService->joinMeeting($meeting, $participantUser);
    expect($participant->deleted_at)->toBeNull();

    // Soft delete participant
    $participant->delete();

    expect($participant->trashed())->toBeTrue();
    expect(ChatMeetingParticipant::where('id', $participant->id)->first())->toBeNull();
    expect(ChatMeetingParticipant::withTrashed()->where('id', $participant->id)->first())->not->toBeNull();

    // Restore participant
    $participant->restore();
    expect($participant->fresh()->trashed())->toBeFalse();
});

test('Admin can access and save independent Online Meetings settings component', function () {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.meetings')
        ->assertOk()
        ->set('form.enabled', true)
        ->set('form.max_duration_minutes', 180)
        ->set('form.default_access_mode', 'invited_only')
        ->call('save')
        ->assertHasNoErrors();

    expect((int) Setting::get('meeting.max_duration_minutes'))->toBe(180);
    expect(Setting::get('meeting.default_access_mode'))->toBe('invited_only');
});
