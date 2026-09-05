<?php

use App\Events\ChatMessageReadEvent;
use App\Events\ChatMessageSentEvent;
use App\Events\ChatMessageUpdatedEvent;
use App\Events\ChatUserTypingEvent;
use App\Events\WebRtcCallSignalEvent;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageStatus;
use App\Models\ChatParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChatService;
use App\Services\RbacService;
use App\Services\WebRtcCallService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    Storage::fake('local');

    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', 'test-key');
    Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    Config::set('broadcasting.connections.reverb.app_id', 'test-app-id');
});

test('ChatConversation model supports direct, group, and channel types with helper methods', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);

    // Direct Conversation
    $direct = $chatService->findOrCreateDirectConversation($user1, $user2);
    expect($direct->isDirect())->toBeTrue();
    expect($direct->isGroup())->toBeFalse();
    expect($direct->isChannel())->toBeFalse();
    expect($direct->displayNameFor($user1))->toBe('Bob');
    expect($direct->displayNameFor($user2))->toBe('Alice');

    // Group Conversation
    $group = $chatService->createGroupConversation($user1, 'Study Group 2026', [$user2->id]);
    expect($group->isGroup())->toBeTrue();
    expect($group->displayNameFor($user1))->toBe('Study Group 2026');
    expect($group->isUserAdmin($user1->id))->toBeTrue();
    expect($group->isUserAdmin($user2->id))->toBeFalse();

    // Channel Conversation
    $channel = $chatService->createChannel($user1, 'Official Notices', [$user2->id], isBroadcastOnly: true);
    expect($channel->isChannel())->toBeTrue();
    expect($channel->canPost($user1))->toBeTrue();
    expect($channel->canPost($user2))->toBeFalse();
});

test('Sending messages dispatches events and creates delivery status records', function () {
    Event::fake([ChatMessageSentEvent::class]);

    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    $msg = $chatService->sendMessage($conv, $user1, 'Hello Bob! Welcome to live chat.');

    expect($msg)->not->toBeNull();
    expect($msg->body)->toBe('Hello Bob! Welcome to live chat.');
    expect($msg->uuid)->not->toBeEmpty();
    expect($msg->is_edited)->toBeFalse();

    // Check delivery status
    $status = ChatMessageStatus::where('message_id', $msg->id)->where('user_id', $user2->id)->first();
    expect($status)->not->toBeNull();
    expect($status->is_delivered)->toBeTrue();
    expect($status->is_read)->toBeFalse();

    // Outgoing tick should be double tick (delivered)
    expect($msg->tickStatus())->toBe('double_tick');

    Event::assertDispatched(ChatMessageSentEvent::class);
});

test('Marking conversation as read updates statuses to blue double ticks', function () {
    Event::fake([ChatMessageReadEvent::class]);

    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    $msg = $chatService->sendMessage($conv, $user1, 'Checking read receipts.');

    expect($conv->unreadCountFor($user2->id))->toBe(1);

    // User 2 reads conversation
    $readCount = $chatService->markConversationAsRead($conv, $user2);
    expect($readCount)->toBe(1);
    expect($conv->unreadCountFor($user2->id))->toBe(0);

    // Outgoing tick from sender perspective is now blue double tick
    expect($msg->fresh()->tickStatus())->toBe('blue_double_tick');

    Event::assertDispatched(ChatMessageReadEvent::class);
});

test('Quote-style replies link to original message and preserve metadata', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    $originalMsg = $chatService->sendMessage($conv, $user1, 'What time is the lecture today?');
    $replyMsg = $chatService->sendMessage($conv, $user2, 'It starts at 4:00 PM.', replyToId: $originalMsg->id);

    expect($replyMsg->reply_to_id)->toBe($originalMsg->id);
    expect($replyMsg->replyTo->body)->toBe('What time is the lecture today?');
    expect($originalMsg->replies->count())->toBe(1);
});

test('Editing message within time window updates body and sets is_edited flag', function () {
    Event::fake([ChatMessageUpdatedEvent::class]);

    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    $msg = $chatService->sendMessage($conv, $user1, 'Initial text with typo');
    $edited = $chatService->editMessage($msg, $user1, 'Corrected message text');

    expect($edited->body)->toBe('Corrected message text');
    expect($edited->is_edited)->toBeTrue();
    expect($edited->edited_at)->not->toBeNull();

    Event::assertDispatched(ChatMessageUpdatedEvent::class);
});

test('Message deletion handles delete for me vs delete for everyone', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    // Delete for me
    $msg1 = $chatService->sendMessage($conv, $user1, 'Secret message 1');
    $chatService->deleteMessageForMe($msg1, $user1);

    expect($conv->messages()->visibleForUser($user1->id)->where('id', $msg1->id)->exists())->toBeFalse();
    expect($conv->messages()->visibleForUser($user2->id)->where('id', $msg1->id)->exists())->toBeTrue();

    // Delete for everyone
    $msg2 = $chatService->sendMessage($conv, $user1, 'Delete this for all');
    $chatService->deleteMessageForEveryone($msg2, $user1);

    expect($msg2->fresh()->is_deleted_for_everyone)->toBeTrue();
    expect($msg2->fresh()->body)->toBe(__('This message was deleted'));
});

test('Group member management allows adding, removing, and changing roles', function () {
    $admin = User::factory()->create(['name' => 'Admin User']);
    $member1 = User::factory()->create(['name' => 'Member One']);
    $member2 = User::factory()->create(['name' => 'Member Two']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $group = $chatService->createGroupConversation($admin, 'UPSC Mentorship', [$member1->id]);

    expect($group->participants()->count())->toBe(2);

    // Add Member 2
    $chatService->addParticipant($group, $member2, $admin);
    expect($group->participants()->where('user_id', $member2->id)->exists())->toBeTrue();

    // Promote Member 1 to Admin
    $chatService->updateParticipantRole($group, $member1, ChatParticipant::ROLE_ADMIN, $admin);
    expect($group->fresh()->isUserAdmin($member1->id))->toBeTrue();

    // Remove Member 2
    $chatService->removeParticipant($group, $member2, $admin);
    expect($group->fresh()->participants()->where('user_id', $member2->id)->exists())->toBeFalse();
});

test('Admin can send bulk personalized broadcast messages to filtered users', function () {
    $admin = User::factory()->create(['name' => 'Director']);
    $students = User::factory()->count(3)->create();

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);

    $template = 'Hello {first_name}, welcome to the new academic term! Your email is {email}.';
    $result = $chatService->sendBroadcastMessage($admin, $students, $template);

    expect($result['sent_count'])->toBe(3);
    expect($result['failed_count'])->toBe(0);

    foreach ($students as $student) {
        $conv = ChatConversation::direct()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $admin->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $student->id))
            ->first();

        expect($conv)->not->toBeNull();
        $msg = $conv->messages()->latest('id')->first();
        expect($msg->body)->toContain(explode(' ', $student->name)[0]);
        expect($msg->body)->toContain($student->email);
    }
});

test('WebRTC audio and video calls support initiation, signaling, and termination', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Caller']);
    $receiver = User::factory()->create(['name' => 'Receiver']);

    /** @var WebRtcCallService $callService */
    $callService = app(WebRtcCallService::class);

    // Initiate Call
    $call = $callService->initiateCall($caller, $receiver, ChatCall::TYPE_VIDEO);
    expect($call->status)->toBe(ChatCall::STATUS_RINGING);
    expect($call->isVideo())->toBeTrue();
    expect($call->isAudio())->toBeFalse();

    // Send SDP Offer signal
    $signaled = $callService->sendSignal($call->uuid, $caller, 'offer', ['sdp' => 'v=0...']);
    expect($signaled)->toBeTrue();

    // Accept Call
    $acceptedCall = $callService->acceptCall($call->uuid, $receiver);
    expect($acceptedCall->status)->toBe(ChatCall::STATUS_CONNECTED);
    expect($acceptedCall->started_at)->not->toBeNull();

    // End Call
    $endedCall = $callService->endCall($call->uuid, $caller);
    expect($endedCall->status)->toBe(ChatCall::STATUS_ENDED);
    expect($endedCall->ended_at)->not->toBeNull();

    Event::assertDispatched(WebRtcCallSignalEvent::class);
});

test('Admin Chat Settings page saves configuration properly', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.chat')
        ->set('form.enabled', true)
        ->set('form.transport_driver', 'hybrid')
        ->set('form.poll_interval', '3s')
        ->set('form.voice_call_enabled', true)
        ->set('form.video_call_enabled', true)
        ->set('form.max_file_size_mb', 30)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('chat.transport_driver'))->toBe('hybrid');
    expect(Setting::get('chat.poll_interval'))->toBe('3s');
    expect(Setting::get('chat.max_file_size_mb'))->toBe(30);
});

test('Live Chat portal UI renders and handles message sending', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);
    $user1->givePermissionTo('chat.access');

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    Livewire::actingAs($user1)
        ->test('pages::portal.chat', ['c' => $conv->uuid])
        ->assertSee('Bob')
        ->set('messageText', 'Testing livewire component message input')
        ->call('sendMessage')
        ->assertHasNoErrors()
        ->assertSet('messageText', '');

    expect($conv->messages()->where('body', 'Testing livewire component message input')->exists())->toBeTrue();
});

test('User can join group via invite link and QR code code', function () {
    $owner = User::factory()->create(['name' => 'Group Owner', 'email_verified_at' => now()]);
    $newUser = User::factory()->create(['name' => 'New Student', 'email_verified_at' => now()]);
    $newUser->givePermissionTo('chat.access');

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $group = $chatService->createGroupConversation($owner, 'Open Study Group', []);

    expect($group->invite_code)->not->toBeEmpty();
    expect($group->invite_url)->toContain($group->invite_code);

    $response = $this->actingAs($newUser)->get(route('chat.join', ['code' => $group->invite_code]));
    $response->assertRedirectContains('/chat?c='.$group->uuid);

    expect($group->fresh()->participants()->where('user_id', $newUser->id)->exists())->toBeTrue();
    expect($group->fresh()->messages()->where('type', ChatMessage::TYPE_SYSTEM)->exists())->toBeTrue();
});

test('Group voice and video calls dispatch signals to all group members', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Caller']);
    $member1 = User::factory()->create(['name' => 'Member One']);
    $member2 = User::factory()->create(['name' => 'Member Two']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $group = $chatService->createGroupConversation($caller, 'Study Circle', [$member1->id, $member2->id]);

    /** @var WebRtcCallService $callService */
    $callService = app(WebRtcCallService::class);
    $call = $callService->initiateGroupCall($caller, $group, ChatCall::TYPE_VIDEO);

    expect($call)->not->toBeNull();
    expect($call->status)->toBe(ChatCall::STATUS_RINGING);
    expect($call->participants()->count())->toBe(3);

    Event::assertDispatched(WebRtcCallSignalEvent::class);
});

test('Super Admin can audit original content of messages deleted for everyone', function () {
    $author = User::factory()->create(['name' => 'Normal Author']);
    $superAdmin = User::factory()->create(['name' => 'Super Administrator']);
    $superAdmin->assignRole('Super Administrator');

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($author, $superAdmin);

    $msg = $chatService->sendMessage($conv, $author, 'Confidential text before deletion');
    $chatService->deleteMessageForEveryone($msg, $author);

    $deletedMsg = $msg->fresh();
    expect($deletedMsg->is_deleted_for_everyone)->toBeTrue();
    expect($deletedMsg->body)->toBe(__('This message was deleted'));

    // Normal User View in Chat UI
    $author->givePermissionTo('chat.access');
    Livewire::actingAs($author)
        ->test('pages::portal.chat', ['c' => $conv->uuid])
        ->assertSee(__('This message was deleted'))
        ->assertDontSee('Deleted Message (Super Admin View)');

    // Super Admin View in Chat UI
    $superAdmin->givePermissionTo('chat.access');
    Livewire::actingAs($superAdmin)
        ->test('pages::portal.chat', ['c' => $conv->uuid])
        ->assertSee('Deleted Message (Super Admin View)')
        ->assertSee('Confidential text before deletion');
});

test('Users and Admins can pin and unpin chat messages', function () {
    $admin = User::factory()->create(['name' => 'Group Admin']);
    $member = User::factory()->create(['name' => 'Group Member']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $group = $chatService->createGroupConversation($admin, 'Exam Revision 2026', [$member->id]);

    $msg = $chatService->sendMessage($group, $admin, 'Important Announcement: Final Exam date announced!');

    // 1. Pin Message
    $pinned = $chatService->pinMessage($msg, $admin);
    expect($pinned)->toBeTrue();
    expect($msg->fresh()->is_pinned)->toBeTrue();
    expect($msg->fresh()->pinned_by)->toBe($admin->id);
    expect($msg->fresh()->pinned_at)->not->toBeNull();

    // 2. Chat UI verifies Pinned Banner
    $admin->givePermissionTo('chat.access');
    Livewire::actingAs($admin)
        ->test('pages::portal.chat', ['c' => $group->uuid])
        ->assertSee('Pinned Message')
        ->assertSee('Important Announcement: Final Exam date announced!')
        ->call('unpinMessage', $msg->id)
        ->assertHasNoErrors();

    expect($msg->fresh()->is_pinned)->toBeFalse();
});

test('Group and Channel admin can upload, change, and remove group display picture', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['name' => 'Group Admin']);
    $member = User::factory()->create(['name' => 'Group Member']);
    $admin->givePermissionTo('chat.access');
    $member->givePermissionTo('chat.access');

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $group = $chatService->createGroupConversation($admin, 'UPSC Aspirants 2026', [$member->id]);

    $file = UploadedFile::fake()->image('group_avatar.png', 200, 200);

    // 1. Direct Avatar Upload from Drawer by Admin
    Livewire::actingAs($admin)
        ->test('pages::portal.chat', ['c' => $group->uuid])
        ->set('directAvatarUpload', $file)
        ->assertHasNoErrors();

    expect($group->fresh()->avatar)->not->toBeNull();
    expect($group->fresh()->displayAvatarFor($admin))->not->toBeNull();

    // 2. Direct Avatar Removal by Admin
    Livewire::actingAs($admin)
        ->test('pages::portal.chat', ['c' => $group->uuid])
        ->call('removeActiveConversationAvatarDirect')
        ->assertHasNoErrors();

    expect($group->fresh()->avatar)->toBeNull();

    // 3. Update Avatar via Edit Group Profile Modal
    $newFile = UploadedFile::fake()->image('updated_group.jpg', 300, 300);

    Livewire::actingAs($admin)
        ->test('pages::portal.chat', ['c' => $group->uuid])
        ->set('editTitle', 'UPSC Aspirants 2026 Updated')
        ->set('editPostingPermission', 'all')
        ->set('editAvatar', $newFile)
        ->call('saveGroupProfile')
        ->assertHasNoErrors();

    expect($group->fresh()->title)->toBe('UPSC Aspirants 2026 Updated');
    expect($group->fresh()->avatar)->not->toBeNull();

    // 4. Non-admin member cannot update group avatar
    Livewire::actingAs($member)
        ->test('pages::portal.chat', ['c' => $group->uuid])
        ->set('directAvatarUpload', $file)
        ->assertDispatched('toast', type: 'error');
});

test('Creating new group or channel with uploaded avatar stores display picture', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['name' => 'Creator Admin']);
    $user2 = User::factory()->create(['name' => 'Student Two']);
    $admin->givePermissionTo('chat.access');

    $groupAvatar = UploadedFile::fake()->image('batch_2026.png', 200, 200);
    $channelAvatar = UploadedFile::fake()->image('announcements.png', 200, 200);

    // Create Group with Avatar
    Livewire::actingAs($admin)
        ->test('pages::portal.chat')
        ->set('groupTitle', 'Batch 2026 Prelims')
        ->set('groupSelectedMembers', [$user2->id])
        ->set('groupAvatar', $groupAvatar)
        ->call('createGroup')
        ->assertHasNoErrors();

    $createdGroup = ChatConversation::where('title', 'Batch 2026 Prelims')->first();
    expect($createdGroup)->not->toBeNull();
    expect($createdGroup->avatar)->not->toBeNull();

    // Create Channel with Avatar
    Livewire::actingAs($admin)
        ->test('pages::portal.chat')
        ->set('channelTitle', 'Official Press Releases')
        ->set('channelAvatar', $channelAvatar)
        ->call('createChannel')
        ->assertHasNoErrors();

    $createdChannel = ChatConversation::where('title', 'Official Press Releases')->first();
    expect($createdChannel)->not->toBeNull();
    expect($createdChannel->avatar)->not->toBeNull();
});

test('Users can react to chat messages with emojis and toggle reactions', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);
    $user1->givePermissionTo('chat.access');

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);
    $msg = $chatService->sendMessage($conv, $user2, 'Hello Alice!');

    // Alice reacts with 👍
    Livewire::actingAs($user1)
        ->test('pages::portal.chat', ['conversation' => $conv->uuid])
        ->call('toggleReaction', $msg->id, '👍')
        ->assertHasNoErrors();

    expect($msg->reactions()->where('emoji', '👍')->where('user_id', $user1->id)->exists())->toBeTrue();

    // Alice un-reacts with 👍
    Livewire::actingAs($user1)
        ->test('pages::portal.chat', ['conversation' => $conv->uuid])
        ->call('toggleReaction', $msg->id, '👍')
        ->assertHasNoErrors();

    expect($msg->reactions()->where('emoji', '👍')->where('user_id', $user1->id)->exists())->toBeFalse();
});

test('User can view profile and start direct message from member profile', function () {
    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob', 'phone' => '+919876543210']);
    $user1->givePermissionTo('chat.access');

    Livewire::actingAs($user1)
        ->test('pages::portal.chat')
        ->call('viewUserProfile', $user2->id)
        ->assertSet('viewingUserProfile.id', $user2->id)
        ->call('startDirectMessage', $user2->id)
        ->assertHasNoErrors();

    $conv = ChatConversation::where('type', ChatConversation::TYPE_DIRECT)->first();
    expect($conv)->not->toBeNull();
});

test('Typing indicator broadcasts ChatUserTypingEvent with user metadata and respecting posting permissions', function () {
    Event::fake([ChatUserTypingEvent::class]);

    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);
    $user3 = User::factory()->create(['name' => 'Charlie']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);

    // 1. Direct Conversation Typing
    $direct = $chatService->findOrCreateDirectConversation($user1, $user2);
    $sent = $chatService->broadcastTypingIndicator($direct, $user1, true);
    expect($sent)->toBeTrue();

    Event::assertDispatched(ChatUserTypingEvent::class, function ($event) use ($direct, $user1) {
        return $event->conversationId === $direct->id
            && $event->user->id === $user1->id
            && $event->isTyping === true
            && $event->user->name === 'Alice';
    });

    // 2. Broadcast-only Channel: Non-admin cannot broadcast typing
    $channel = $chatService->createChannel($user1, 'News Channel', [$user2->id, $user3->id], isBroadcastOnly: true);
    $adminSent = $chatService->broadcastTypingIndicator($channel, $user1, true);
    expect($adminSent)->toBeTrue();

    $memberSent = $chatService->broadcastTypingIndicator($channel, $user2, true);
    expect($memberSent)->toBeFalse();
});

test('Livewire chat component dispatches typing indicator to conversation channel', function () {
    Event::fake([ChatUserTypingEvent::class]);

    $user1 = User::factory()->create(['name' => 'Alice']);
    $user2 = User::factory()->create(['name' => 'Bob']);
    $user1->givePermissionTo('chat.access');

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->findOrCreateDirectConversation($user1, $user2);

    Livewire::actingAs($user1)
        ->test('pages::portal.chat', ['conversation' => $conv->uuid])
        ->call('sendTypingIndicator', true)
        ->assertHasNoErrors();

    Event::assertDispatched(ChatUserTypingEvent::class, function ($event) use ($conv, $user1) {
        return $event->conversationId === $conv->id && $event->user->id === $user1->id && $event->isTyping === true;
    });
});

test('Group audio and video calling supports multi-party concurrent initiation, join, and leave', function () {
    Event::fake([WebRtcCallSignalEvent::class]);

    $caller = User::factory()->create(['name' => 'Host Alice']);
    $member1 = User::factory()->create(['name' => 'Member Bob']);
    $member2 = User::factory()->create(['name' => 'Member Charlie']);

    /** @var ChatService $chatService */
    $chatService = app(ChatService::class);
    $conv = $chatService->createGroupConversation($caller, 'Study Team', [$member1->id, $member2->id]);

    /** @var WebRtcCallService $callService */
    $callService = app(WebRtcCallService::class);

    // 1. Host initiates group video call
    $call = $callService->initiateGroupCall($caller, $conv, ChatCall::TYPE_VIDEO);
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(ChatCall::STATUS_RINGING);
    expect($call->participants()->count())->toBe(3);

    // Caller should be marked as joined immediately
    $callerPart = $call->participants()->where('user_id', $caller->id)->first();
    expect($callerPart->status)->toBe(ChatCallParticipant::STATUS_JOINED);

    // Other members should be ringing
    $bobPart = $call->participants()->where('user_id', $member1->id)->first();
    expect($bobPart->status)->toBe(ChatCallParticipant::STATUS_RINGING);

    // 2. Bob joins group call
    $joinedCall = $callService->joinGroupCall($call->uuid, $member1);
    expect($joinedCall->status)->toBe(ChatCall::STATUS_CONNECTED);
    $bobPart->refresh();
    expect($bobPart->status)->toBe(ChatCallParticipant::STATUS_JOINED);

    // 3. Charlie joins group call
    $callService->joinGroupCall($call->uuid, $member2);
    $member2Part = $call->participants()->where('user_id', $member2->id)->first();
    expect($member2Part->status)->toBe(ChatCallParticipant::STATUS_JOINED);
    expect($callService->getActiveGroupCall($conv->id)?->uuid)->toBe($call->uuid);

    // 4. Bob leaves call - Call should remain active for Alice and Charlie
    $callService->leaveCall($call->uuid, $member1);
    $bobPart->refresh();
    expect($bobPart->status)->toBe(ChatCallParticipant::STATUS_LEFT);
    $call->refresh();
    expect($call->status)->toBe(ChatCall::STATUS_CONNECTED);

    // 5. Charlie leaves call - active participants count drops to 1, call ends
    $callService->leaveCall($call->uuid, $member2);
    $call->refresh();
    expect($call->status)->toBe(ChatCall::STATUS_ENDED);
});
