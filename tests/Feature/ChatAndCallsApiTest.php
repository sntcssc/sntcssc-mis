<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ChatService;
use App\Services\RbacService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    RbacService::seedDefaults();
    Storage::fake('local');

    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', 'test-key');
    Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    Config::set('broadcasting.connections.reverb.app_id', 'test-app-id');
});

test('Chat API: status returns realtime telemetry and ICE configuration', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::create([
        'name' => 'Test Mobile Client Key',
        'key_hash' => hash('sha256', 'sntc_live_test_key_123'),
        'key_prefix' => 'sntc_live_test',
        'type' => 'mobile_app',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['read:chat'],
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-API-Key' => 'sntc_live_test_key_123',
    ])->getJson('/api/v1/chat/status');

    $response->assertOk()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'success',
            'data' => [
                'realtime',
                'ice_configured',
                'telemetry' => [
                    'active_calls',
                    'ongoing_meetings',
                    'server_time',
                ],
                'authenticated_user' => [
                    'id',
                    'name',
                    'email',
                ],
            ],
        ]);
});

test('Chat API: ice-servers returns valid STUN/TURN server list', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::create([
        'name' => 'WebRTC Client Key',
        'key_hash' => hash('sha256', 'sntc_live_test_key_ice'),
        'key_prefix' => 'sntc_live_ice',
        'type' => 'web_portal',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['call:chat'],
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-API-Key' => 'sntc_live_test_key_ice',
    ])->getJson('/api/v1/chat/calls/ice-servers');

    $response->assertOk()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'data' => [
                'ice_servers',
                'ice_transport_policy',
                'ice_candidate_pool_size',
            ],
        ]);
});

test('Chat API: direct and group conversations creation, listing and management', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);
    $user3 = User::factory()->create(['name' => 'Charlie']);

    $apiKey = ApiKey::create([
        'name' => 'Full Chat API Key',
        'key_hash' => hash('sha256', 'sntc_live_test_full_chat'),
        'key_prefix' => 'sntc_live_full',
        'type' => 'developer_console',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['read:chat', 'write:chat', 'call:chat', 'meeting:chat'],
        'user_id' => $user1->id,
    ]);

    // 1. Start Direct Conversation
    $directRes = $this->withHeaders(['X-API-Key' => 'sntc_live_test_full_chat'])
        ->postJson('/api/v1/chat/conversations/direct', [
            'recipient_id' => $user2->id,
        ]);

    $directRes->assertStatus(201)
        ->assertJsonPath('data.type', 'direct')
        ->assertJsonPath('data.recipient.id', $user2->id);

    $directId = $directRes->json('data.id');

    // 2. Create Group Conversation
    $groupRes = $this->withHeaders(['X-API-Key' => 'sntc_live_test_full_chat'])
        ->postJson('/api/v1/chat/conversations/group', [
            'title' => 'WBCS Ethics & Polity Circle',
            'description' => 'Mains preparation peer group',
            'participant_ids' => [$user2->id],
        ]);

    $groupRes->assertStatus(201)
        ->assertJsonPath('data.type', 'group')
        ->assertJsonPath('data.title', 'WBCS Ethics & Polity Circle');

    $groupId = $groupRes->json('data.id');

    // 3. Add Participant to Group
    $addRes = $this->withHeaders(['X-API-Key' => 'sntc_live_test_full_chat'])
        ->postJson("/api/v1/chat/conversations/{$groupId}/participants", [
            'user_id' => $user3->id,
            'role' => 'member',
        ]);

    $addRes->assertStatus(201);

    // 4. Update Participant Role
    $roleRes = $this->withHeaders(['X-API-Key' => 'sntc_live_test_full_chat'])
        ->putJson("/api/v1/chat/conversations/{$groupId}/participants/{$user3->id}/role", [
            'role' => 'moderator',
        ]);

    $roleRes->assertOk()
        ->assertJsonPath('data.new_role', 'moderator');

    // 5. Query Conversations
    $listRes = $this->withHeaders(['X-API-Key' => 'sntc_live_test_full_chat'])
        ->getJson('/api/v1/chat/conversations');

    $listRes->assertOk()
        ->assertJsonPath('meta.total', 2);

    // 6. View Single Conversation
    $showRes = $this->withHeaders(['X-API-Key' => 'sntc_live_test_full_chat'])
        ->getJson("/api/v1/chat/conversations/{$groupId}");

    $showRes->assertOk()
        ->assertJsonPath('data.id', $groupId)
        ->assertJsonPath('data.is_admin', true);
});

test('Chat API: messaging lifecycle — send, paginate, edit, pin, react, and delete', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conversation = $chatService->findOrCreateDirectConversation($user1, $user2);

    $apiKey = ApiKey::create([
        'name' => 'Alice Key',
        'key_hash' => hash('sha256', 'sntc_live_alice_key'),
        'key_prefix' => 'sntc_live_alice',
        'type' => 'mobile_app',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['read:chat', 'write:chat'],
        'user_id' => $user1->id,
    ]);

    // 1. Send Message
    $sendRes = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->postJson("/api/v1/chat/conversations/{$conversation->id}/messages", [
            'body' => 'Welcome to the SNTCSSC civil services study room!',
        ]);

    $sendRes->assertStatus(201)
        ->assertJsonPath('data.body', 'Welcome to the SNTCSSC civil services study room!');

    $messageId = $sendRes->json('data.id');

    // 2. Fetch Messages
    $msgList = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->getJson("/api/v1/chat/conversations/{$conversation->id}/messages");

    $msgList->assertOk()
        ->assertJsonPath('meta.total', 1);

    // 3. Edit Message
    $editRes = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->putJson("/api/v1/chat/messages/{$messageId}", [
            'body' => 'Welcome to the SNTCSSC civil services study room! (Updated)',
        ]);

    $editRes->assertOk()
        ->assertJsonPath('data.body', 'Welcome to the SNTCSSC civil services study room! (Updated)');

    // 4. Pin Message
    $pinRes = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->postJson("/api/v1/chat/messages/{$messageId}/pin");

    $pinRes->assertOk()
        ->assertJsonPath('data.is_pinned', true);

    // 5. React to Message
    $reactRes = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->postJson("/api/v1/chat/messages/{$messageId}/reaction", [
            'emoji' => '🎯',
        ]);

    $reactRes->assertOk()
        ->assertJsonPath('data.emoji', '🎯');

    // 6. Broadcast Typing Indicator
    $typingRes = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->postJson("/api/v1/chat/conversations/{$conversation->id}/typing", [
            'is_typing' => true,
        ]);

    $typingRes->assertOk()
        ->assertJsonPath('data.is_typing', true);

    // 7. Mark as Read
    $readRes = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->postJson("/api/v1/chat/conversations/{$conversation->id}/read");

    $readRes->assertOk();

    // 8. Delete Message
    $deleteRes = $this->withHeaders(['X-API-Key' => 'sntc_live_alice_key'])
        ->deleteJson("/api/v1/chat/messages/{$messageId}", [
            'mode' => 'everyone',
        ]);

    $deleteRes->assertOk()
        ->assertJsonPath('data.action', 'deleted_for_everyone');
});

test('Chat API: WebRTC call initiation, signaling relay, accept, and termination', function () {
    $caller = User::factory()->create(['name' => 'Caller User']);
    $callee = User::factory()->create(['name' => 'Callee User']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conversation = $chatService->findOrCreateDirectConversation($caller, $callee);

    $callerKey = ApiKey::create([
        'name' => 'Caller Key',
        'key_hash' => hash('sha256', 'sntc_live_caller_key'),
        'key_prefix' => 'sntc_live_caller',
        'type' => 'mobile_app',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['call:chat', 'read:chat'],
        'user_id' => $caller->id,
    ]);

    $calleeKey = ApiKey::create([
        'name' => 'Callee Key',
        'key_hash' => hash('sha256', 'sntc_live_callee_key'),
        'key_prefix' => 'sntc_live_callee',
        'type' => 'mobile_app',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['call:chat', 'read:chat'],
        'user_id' => $callee->id,
    ]);

    // 1. Initiate Video Call
    $initRes = $this->withHeaders(['X-API-Key' => 'sntc_live_caller_key'])
        ->postJson('/api/v1/chat/calls/initiate', [
            'conversation_id' => $conversation->id,
            'call_type' => 'video',
        ]);

    $initRes->assertStatus(201)
        ->assertJsonPath('data.call_type', 'video')
        ->assertJsonPath('data.status', 'ringing');

    $callUuid = $initRes->json('data.uuid');

    // 2. Relay SDP Offer Signal
    $signalRes = $this->withHeaders(['X-API-Key' => 'sntc_live_caller_key'])
        ->postJson("/api/v1/chat/calls/{$callUuid}/signal", [
            'signal_type' => 'offer',
            'target_user_id' => $callee->id,
            'payload' => [
                'type' => 'offer',
                'sdp' => 'v=0\r\no=- 12345 2 IN IP4 127.0.0.1...',
            ],
        ]);

    $signalRes->assertOk()
        ->assertJsonPath('data.signal_type', 'offer');

    // 3. Accept Call by Callee
    $acceptRes = $this->withHeaders(['X-API-Key' => 'sntc_live_callee_key'])
        ->postJson("/api/v1/chat/calls/{$callUuid}/accept");

    $acceptRes->assertOk()
        ->assertJsonPath('data.status', 'connected');

    // 4. Query Call Status
    $statusRes = $this->withHeaders(['X-API-Key' => 'sntc_live_caller_key'])
        ->getJson("/api/v1/chat/calls/{$callUuid}/status");

    $statusRes->assertOk()
        ->assertJsonPath('data.status', 'connected');

    // 5. Terminate Call
    $endRes = $this->withHeaders(['X-API-Key' => 'sntc_live_caller_key'])
        ->postJson("/api/v1/chat/calls/{$callUuid}/end");

    $endRes->assertOk()
        ->assertJsonPath('data.status', 'ended');

    // 6. Query Call History
    $historyRes = $this->withHeaders(['X-API-Key' => 'sntc_live_caller_key'])
        ->getJson('/api/v1/chat/calls/history');

    $historyRes->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('Chat API: Online meetings lifecycle — schedule, join with passcode, waiting room, sync, reactions, and end', function () {
    $host = User::factory()->create(['name' => 'Host Professor']);
    $student = User::factory()->create(['name' => 'Student Candidate']);

    $hostKey = ApiKey::create([
        'name' => 'Host Key',
        'key_hash' => hash('sha256', 'sntc_live_host_key'),
        'key_prefix' => 'sntc_live_host',
        'type' => 'web_portal',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['meeting:chat', 'read:chat'],
        'user_id' => $host->id,
    ]);

    $studentKey = ApiKey::create([
        'name' => 'Student Key',
        'key_hash' => hash('sha256', 'sntc_live_student_key'),
        'key_prefix' => 'sntc_live_student',
        'type' => 'mobile_app',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['meeting:chat', 'read:chat'],
        'user_id' => $student->id,
    ]);

    // 1. Create Meeting with Passcode and Waiting Room
    $createRes = $this->withHeaders(['X-API-Key' => 'sntc_live_host_key'])
        ->postJson('/api/v1/chat/meetings', [
            'title' => 'Civil Services Prelims 2026 Strategy Masterclass',
            'description' => 'GS Paper I & II orientation for all registered cohorts',
            'meeting_type' => 'scheduled',
            'start_time' => Carbon::now()->addDays(2)->toDateTimeString(),
            'duration_minutes' => 90,
            'passcode' => 'PRELIMS26',
            'require_passcode' => true,
            'waiting_room_enabled' => true,
            'allow_screen_share' => true,
        ]);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.title', 'Civil Services Prelims 2026 Strategy Masterclass')
        ->assertJsonPath('data.waiting_room_enabled', true);

    $meetingUuid = $createRes->json('data.uuid');

    // 2. Query Meetings List
    $meetingsList = $this->withHeaders(['X-API-Key' => 'sntc_live_host_key'])
        ->getJson('/api/v1/chat/meetings');

    $meetingsList->assertOk()
        ->assertJsonPath('meta.total', 1);

    // 3. Student Joins with Passcode (Enters Waiting Room)
    $joinRes = $this->withHeaders(['X-API-Key' => 'sntc_live_student_key'])
        ->postJson("/api/v1/chat/meetings/{$meetingUuid}/join", [
            'passcode' => 'PRELIMS26',
        ]);

    $joinRes->assertOk()
        ->assertJsonPath('data.status', 'waiting')
        ->assertJsonPath('data.in_waiting_room', true);

    $participantId = $joinRes->json('data.participant_id');

    // 4. Host Admits Student from Waiting Room
    $admitRes = $this->withHeaders(['X-API-Key' => 'sntc_live_host_key'])
        ->postJson("/api/v1/chat/meetings/{$meetingUuid}/participants/{$participantId}/admit");

    $admitRes->assertOk()
        ->assertJsonPath('data.admitted', true);

    // 5. Send Realtime Meeting Reaction
    $reactRes = $this->withHeaders(['X-API-Key' => 'sntc_live_student_key'])
        ->postJson("/api/v1/chat/meetings/{$meetingUuid}/reaction", [
            'emoji' => '👏',
        ]);

    $reactRes->assertOk();

    // 6. Sync Meeting State
    $syncRes = $this->withHeaders(['X-API-Key' => 'sntc_live_student_key'])
        ->getJson("/api/v1/chat/meetings/{$meetingUuid}/sync");

    $syncRes->assertOk()
        ->assertJsonStructure([
            'data' => [
                'meeting_status',
                'participants',
                'reactions',
                'signals',
            ],
        ]);

    // 7. Update Meeting Host Restrictions
    $restrRes = $this->withHeaders(['X-API-Key' => 'sntc_live_host_key'])
        ->postJson("/api/v1/chat/meetings/{$meetingUuid}/restrictions", [
            'allow_screen_share' => false,
            'lock_meeting' => true,
        ]);

    $restrRes->assertOk()
        ->assertJsonPath('data.updated', true);

    // 8. End Meeting
    $endRes = $this->withHeaders(['X-API-Key' => 'sntc_live_host_key'])
        ->postJson("/api/v1/chat/meetings/{$meetingUuid}/end");

    $endRes->assertOk()
        ->assertJsonPath('data.message', 'Meeting successfully ended.');
});

test('Chat API: unauthorized access is blocked', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $outsider = User::factory()->create();

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conversation = $chatService->findOrCreateDirectConversation($user1, $user2);

    $outsiderKey = ApiKey::create([
        'name' => 'Outsider Key',
        'key_hash' => hash('sha256', 'sntc_live_outsider_key'),
        'key_prefix' => 'sntc_live_outsider',
        'type' => 'mobile_app',
        'environment' => 'production',
        'status' => 'active',
        'scopes' => ['read:chat', 'write:chat'],
        'user_id' => $outsider->id,
    ]);

    // Outsider cannot read messages in private conversation
    $res = $this->withHeaders(['X-API-Key' => 'sntc_live_outsider_key'])
        ->getJson("/api/v1/chat/conversations/{$conversation->id}/messages");

    $res->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});
