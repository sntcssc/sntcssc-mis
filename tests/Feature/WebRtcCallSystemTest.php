<?php

use App\Events\WebRtcCallSignalEvent;
use App\Models\AuditLog;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChatService;
use App\Services\RbacService;
use App\Services\WebRtcCallService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    Setting::set('chat.enabled', true);
    Setting::set('chat.voice_call_enabled', true);
    Setting::set('chat.video_call_enabled', true);
    Setting::set('chat.require_websocket_for_calls', true);

    // Configure Reverb broadcast environment for tests
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', 'test-key');
    Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    Config::set('broadcasting.connections.reverb.app_id', 'test-app-id');
    Config::set('broadcasting.connections.reverb.options.host', '127.0.0.1');
    Config::set('broadcasting.connections.reverb.options.port', 8080);
    Config::set('broadcasting.connections.reverb.options.scheme', 'http');
});

test('WebRtcCallService correctly detects live realtime WebSocket support', function () {
    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);

    // 1. With Reverb configured
    expect($service->isRealtimeSupported())->toBeTrue();
    $status = $service->getRealtimeStatus();
    expect($status['supported'])->toBeTrue();
    expect($status['driver'])->toBe('reverb');
    expect($status['host'])->toBe('127.0.0.1');
    expect($status['port'])->toBe(8080);

    // 2. With null driver
    Config::set('broadcasting.default', 'null');
    expect($service->isRealtimeSupported())->toBeFalse();
    $statusNull = $service->getRealtimeStatus();
    expect($statusNull['supported'])->toBeFalse();
    expect($statusNull['reason'])->not->toBeNull();

    // 3. With missing reverb key
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', '');
    expect($service->isRealtimeSupported())->toBeFalse();
});

test('Initiating a call requires active WebSocket connection when setting is enabled', function () {
    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);

    // When broadcasting driver is null and require_websocket_for_calls is true
    Config::set('broadcasting.default', 'null');
    Setting::set('chat.require_websocket_for_calls', true);

    expect(fn () => $service->initiateCall($caller, $receiver, ChatCall::TYPE_VIDEO))
        ->toThrow(ValidationException::class);

    // When require_websocket_for_calls is false, it allows initiating even with null driver
    Setting::set('chat.require_websocket_for_calls', false);
    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_AUDIO);
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(ChatCall::STATUS_RINGING);
});

test('Caller can successfully initiate 1-on-1 WebRTC call and dispatch signals', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);

    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_VIDEO);

    expect($call)->not->toBeNull();
    expect($call->caller_id)->toBe($caller->id);
    expect($call->receiver_id)->toBe($receiver->id);
    expect($call->type)->toBe(ChatCall::TYPE_VIDEO);
    expect($call->status)->toBe(ChatCall::STATUS_RINGING);

    // Check participants
    expect($call->participants()->count())->toBe(2);
    $callerPart = $call->participants()->where('user_id', $caller->id)->first();
    $receiverPart = $call->participants()->where('user_id', $receiver->id)->first();

    expect($callerPart->status)->toBe(ChatCallParticipant::STATUS_JOINED);
    expect($receiverPart->status)->toBe(ChatCallParticipant::STATUS_RINGING);

    // Check realtime event dispatch
    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call, $receiver) {
        return $event->callUuid === $call->uuid
            && $event->recipientUserId === $receiver->id
            && $event->signalType === 'incoming_call';
    });
});

test('Caller can initiate group WebRTC call to all group members', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Host']);
    $member1 = User::factory()->create(['name' => 'Member 1']);
    $member2 = User::factory()->create(['name' => 'Member 2']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->createGroupConversation($caller, 'Study Group', [$member1->id, $member2->id]);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);

    $call = $service->initiateGroupCall($caller, $conv, ChatCall::TYPE_AUDIO);

    expect($call)->not->toBeNull();
    expect($call->isAudio())->toBeTrue();
    expect($call->status)->toBe(ChatCall::STATUS_RINGING);

    // Total participants = host + 2 members = 3
    expect($call->participants()->count())->toBe(3);

    // Check signal dispatch to both members
    Event::assertDispatched(WebRtcCallSignalEvent::class, 2);
});

test('Call receiver can accept incoming WebRTC call', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);
    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_VIDEO);

    $acceptedCall = $service->acceptCall($call->uuid, $receiver);

    expect($acceptedCall->status)->toBe(ChatCall::STATUS_CONNECTED);
    expect($acceptedCall->started_at)->not->toBeNull();

    $receiverPart = $acceptedCall->participants()->where('user_id', $receiver->id)->first();
    expect($receiverPart->status)->toBe(ChatCallParticipant::STATUS_JOINED);

    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call) {
        return $event->callUuid === $call->uuid && $event->signalType === 'call_accepted';
    });
});

test('Call receiver can reject incoming call', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);
    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_VIDEO);

    $rejectedCall = $service->rejectCall($call->uuid, $receiver, 'busy');

    expect($rejectedCall->status)->toBe(ChatCall::STATUS_REJECTED);
    expect($rejectedCall->ended_at)->not->toBeNull();

    $receiverPart = $rejectedCall->participants()->where('user_id', $receiver->id)->first();
    expect($receiverPart->status)->toBe(ChatCallParticipant::STATUS_DECLINED);

    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call) {
        return $event->callUuid === $call->uuid && $event->signalType === 'call_rejected';
    });
});

test('Active call can be ended and duration is computed in seconds', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);
    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_VIDEO);
    $service->acceptCall($call->uuid, $receiver);

    // Simulate 75 seconds call
    $call->update(['started_at' => now()->subSeconds(75)]);

    $endedCall = $service->endCall($call->uuid, $caller);

    expect($endedCall->status)->toBe(ChatCall::STATUS_ENDED);
    expect($endedCall->ended_at)->not->toBeNull();
    expect($endedCall->duration_seconds)->toBeGreaterThanOrEqual(74);

    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call) {
        return $event->callUuid === $call->uuid && $event->signalType === 'call_ended';
    });
});

test('WebRtcCallService returns configured STUN and TURN ICE servers', function () {
    Setting::set('chat.webrtc_stun_server', 'stun:stun1.l.google.com:19302');
    Setting::set('chat.webrtc_turn_server', 'turn:turn.example.com:3478');
    Setting::set('chat.webrtc_turn_username', 'turnuser');
    Setting::set('chat.webrtc_turn_credential', 'turnpass');

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);
    $servers = $service->getIceServers();

    expect($servers)->toHaveCount(2);
    expect($servers[0]['urls'])->toBe('stun:stun1.l.google.com:19302');
    expect($servers[1]['urls'])->toBe('turn:turn.example.com:3478');
    expect($servers[1]['username'])->toBe('turnuser');
    expect($servers[1]['credential'])->toBe('turnpass');
});

test('Chat livewire component verifies WebSocket state when prompting and launching calls', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);
    $user1->givePermissionTo('chat.access');

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    // 1. When Reverb is active
    Livewire::actingAs($user1)
        ->test('pages::portal.chat', ['c' => $conv->uuid])
        ->call('promptCall', 'video', false)
        ->assertDispatched('open-pre-call-preview')
        ->call('launchConfirmedCall', $user2->id, 'video', $conv->id)
        ->assertDispatched('start-call');

    // 2. When Reverb is unconfigured and require_websocket_for_calls is true
    Config::set('broadcasting.default', 'null');
    Setting::set('chat.require_websocket_for_calls', true);

    Livewire::actingAs($user1)
        ->test('pages::portal.chat', ['c' => $conv->uuid])
        ->call('promptCall', 'video', false)
        ->assertDispatched('toast', type: 'warning')
        ->call('launchConfirmedCall', $user2->id, 'video', $conv->id)
        ->assertDispatched('toast', type: 'warning');
});

test('Admin chat settings component allows configuring require_websocket_for_calls', function () {
    $admin = User::factory()->create(['name' => 'Super Admin']);
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.chat')
        ->assertSet('form.require_websocket_for_calls', true)
        ->set('form.require_websocket_for_calls', false)
        ->call('save')
        ->assertDispatched('toast', type: 'success');

    expect((bool) Setting::get('chat.require_websocket_for_calls'))->toBeFalse();
});

test('Voice call request correctly preserves audio type across signals and overlay', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);

    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_AUDIO);

    expect($call)->not->toBeNull();
    expect($call->type)->toBe(ChatCall::TYPE_AUDIO);
    expect($call->isAudio())->toBeTrue();

    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call) {
        return $event->callUuid === $call->uuid
            && $event->signalType === 'incoming_call'
            && ($event->payload['call_type'] ?? '') === 'audio';
    });

    // Test overlay component incoming call handling and acceptance
    Livewire::actingAs($receiver)
        ->test('chat-call-overlay')
        ->call('handleIncomingBroadcastedCall', $call->uuid, 'audio', $caller->id, $caller->name, null)
        ->assertSet('activeCallUuid', $call->uuid)
        ->assertSet('callType', 'audio')
        ->assertSet('callStatus', 'incoming')
        ->call('acceptCall')
        ->assertSet('callStatus', 'connected')
        ->assertSet('callType', 'audio')
        ->assertDispatched('webrtc-call-accepted');
});

test('Call participants can request and respond to call mode switches with dynamic renegotiation', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);

    // 1. Start Audio call
    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_AUDIO);
    $service->acceptCall($call->uuid, $receiver);

    expect($call->fresh()->type)->toBe(ChatCall::TYPE_AUDIO);

    // 2. Request switch from Audio -> Video
    $switchReqCall = $service->requestModeSwitch($call->uuid, $caller, ChatCall::TYPE_VIDEO);
    expect($switchReqCall)->not->toBeNull();

    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call) {
        return $event->callUuid === $call->uuid
            && $event->signalType === 'switch_mode_request'
            && ($event->payload['requested_type'] ?? '') === 'video';
    });

    // 3. Accept switch to Video
    $acceptedSwitchCall = $service->respondModeSwitch($call->uuid, $receiver, ChatCall::TYPE_VIDEO, true);
    expect($acceptedSwitchCall->fresh()->type)->toBe(ChatCall::TYPE_VIDEO);

    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call) {
        return $event->callUuid === $call->uuid
            && $event->signalType === 'switch_mode_response'
            && ! empty($event->payload['accepted'])
            && ($event->payload['requested_type'] ?? '') === 'video';
    });

    // 4. Test decline switch back to Audio
    $service->requestModeSwitch($call->uuid, $receiver, ChatCall::TYPE_AUDIO);
    $declinedSwitchCall = $service->respondModeSwitch($call->uuid, $caller, ChatCall::TYPE_AUDIO, false);
    expect($declinedSwitchCall->fresh()->type)->toBe(ChatCall::TYPE_VIDEO); // Stays Video

    Event::assertDispatched(WebRtcCallSignalEvent::class, function ($event) use ($call) {
        return $event->callUuid === $call->uuid
            && $event->signalType === 'switch_mode_response'
            && empty($event->payload['accepted']);
    });
});

test('Inviting new users to ongoing call works with soft deletes and audit logging', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);
    $newUser = User::factory()->create(['name' => 'Charlie']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);

    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_VIDEO);
    $service->acceptCall($call->uuid, $receiver);

    // Invite Charlie
    $service->inviteUserToOngoingCall($call->uuid, $caller, $newUser);

    expect($call->fresh()->participants()->count())->toBe(3);
    $charliePart = $call->fresh()->participants()->where('user_id', $newUser->id)->first();
    expect($charliePart)->not->toBeNull();
    expect($charliePart->status)->toBe(ChatCallParticipant::STATUS_RINGING);

    // Test soft delete on participant
    $charliePart->delete();
    expect(ChatCallParticipant::withTrashed()->where('id', $charliePart->id)->exists())->toBeTrue();
    expect(ChatCallParticipant::where('id', $charliePart->id)->exists())->toBeFalse();

    // Verify audit log exists
    expect(AuditLog::where('event', 'webrtc_participant_invited')->exists())->toBeTrue();
});

test('ChatCallOverlay component executes mode switch workflow smoothly', function () {
    $caller = User::factory()->create(['name' => 'Alice']);
    $receiver = User::factory()->create(['name' => 'Bob']);

    /** @var WebRtcCallService $service */
    $service = app(WebRtcCallService::class);
    $call = $service->initiateCall($caller, $receiver, ChatCall::TYPE_AUDIO);

    Livewire::actingAs($receiver)
        ->test('chat-call-overlay')
        ->set('activeCallUuid', $call->uuid)
        ->set('callType', 'audio')
        ->set('callStatus', 'connected')
        ->call('requestSwitchCallMode', 'video')
        ->assertDispatched('toast', type: 'info');

    Livewire::actingAs($caller)
        ->test('chat-call-overlay')
        ->set('activeCallUuid', $call->uuid)
        ->set('callType', 'audio')
        ->set('callStatus', 'connected')
        ->set('requestedSwitchType', 'video')
        ->set('showSwitchModeConfirmModal', true)
        ->call('acceptSwitchMode')
        ->assertSet('callType', 'video')
        ->assertSet('showSwitchModeConfirmModal', false)
        ->assertDispatched('webrtc-call-type-switched', ['callType' => 'video'])
        ->assertDispatched('toast', type: 'success');
});
