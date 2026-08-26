<?php

use App\Events\RealtimeNotificationEvent;
use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Services\WhatsAppService;
use Database\Seeders\TicketSystemSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    Storage::fake('local');
});

test('AppNotification model supports auto uuid, soft delete, casts, scopes, and helper methods', function () {
    $user = User::factory()->create();

    $notification = AppNotification::create([
        'user_id' => $user->id,
        'type' => 'ticket_created',
        'category' => AppNotification::CATEGORY_TICKET,
        'title' => 'Ticket #TICK-1001 Created',
        'message' => 'Your ticket has been opened successfully.',
        'data' => [
            'action_url' => '/tickets/TICK-1001',
            'action_label' => 'View Ticket',
            'icon' => 'ticket',
        ],
        'channel' => AppNotification::CHANNEL_DATABASE,
    ]);

    expect($notification->uuid)->not->toBeEmpty();
    expect($notification->isRead())->toBeFalse();
    expect($notification->actionUrl())->toBe('/tickets/TICK-1001');
    expect($notification->actionLabel())->toBe('View Ticket');
    expect($notification->iconName())->toBe('ticket');
    expect($notification->categoryLabel())->toBe('Ticket');
    expect($user->unreadAppNotificationsCount())->toBe(1);

    // Mark as read
    $notification->markAsRead();
    expect($notification->fresh()->isRead())->toBeTrue();
    expect($user->unreadAppNotificationsCount())->toBe(0);

    // Mark as unread
    $notification->markAsUnread();
    expect($notification->fresh()->isRead())->toBeFalse();
    expect($user->unreadAppNotificationsCount())->toBe(1);

    // Scopes
    expect(AppNotification::forUser($user->id)->unread()->count())->toBe(1);
    expect(AppNotification::forUser($user->id)->category(AppNotification::CATEGORY_TICKET)->count())->toBe(1);

    // Soft delete
    $notification->delete();
    expect(AppNotification::forUser($user->id)->count())->toBe(0);
    expect(AppNotification::withTrashed()->where('id', $notification->id)->count())->toBe(1);
});

test('WhatsAppService normalizes phone numbers and dispatches via log driver', function () {
    /** @var WhatsAppService $service */
    $service = app(WhatsAppService::class);

    Setting::set('notification.channel_whatsapp', true);
    Setting::set('whatsapp.enabled', true);
    Setting::set('whatsapp.driver', 'log');

    $normalized = $service->normalizePhoneNumber('+91 98765-43210');
    expect($normalized)->toBe('919876543210');

    $normalized10Digit = $service->normalizePhoneNumber('9876543210');
    expect($normalized10Digit)->toBe('919876543210');

    $result = $service->sendDirect('9876543210', 'Test WhatsApp Message');
    expect($result['success'])->toBeTrue();
    expect($result['error'])->toBeNull();
    expect($result['message_id'])->toStartWith('WA-LOG-');

    $testRes = $service->sendTestMessage('9876543210');
    expect($testRes['success'])->toBeTrue();
});

test('TelegramService formats and dispatches messages via log driver', function () {
    /** @var TelegramService $service */
    $service = app(TelegramService::class);

    Setting::set('notification.channel_telegram', true);
    Setting::set('telegram.enabled', true);
    Setting::set('telegram.driver', 'log');
    Setting::set('telegram.default_chat_id', '-100123456789');

    $result = $service->sendDirect('-100123456789', 'Hello Telegram');
    expect($result['success'])->toBeTrue();
    expect($result['error'])->toBeNull();
    expect($result['message_id'])->toStartWith('TG-LOG-');

    $formatted = $service->sendFormatted(
        title: 'System Alert',
        body: 'Server load is normal.',
        chatId: '-100123456789',
        actionUrl: 'https://example.com',
        actionLabel: 'Check Status'
    );
    expect($formatted['success'])->toBeTrue();

    $testRes = $service->sendTestMessage('-100123456789');
    expect($testRes['success'])->toBeTrue();
});

test('NotificationService orchestrates in-app, broadcasting, and domain events', function () {
    Event::fake([RealtimeNotificationEvent::class]);

    $user = User::factory()->create();
    $sender = User::factory()->create(['name' => 'Agent Smith']);

    /** @var NotificationService $service */
    $service = app(NotificationService::class);

    Setting::set('notification.channel_database', true);
    Setting::set('notification.realtime_driver', 'hybrid');

    // 1. General Notification
    $notif = $service->send(
        user: $user,
        title: 'Welcome to System',
        message: 'Your account is active.',
        category: AppNotification::CATEGORY_SYSTEM,
        options: [
            'action_url' => '/dashboard',
            'action_label' => 'Dashboard',
            'icon' => 'check',
        ]
    );

    expect($notif)->not->toBeNull();
    expect($notif->title)->toBe('Welcome to System');
    expect($user->unreadAppNotificationsCount())->toBe(1);

    Event::assertDispatched(RealtimeNotificationEvent::class, function ($event) use ($notif) {
        return $event->notification->id === $notif->id && $event->broadcastAs() === 'RealtimeNotificationEvent';
    });

    // 2. Chat Event
    $chatNotif = $service->notifyLiveChat(
        recipient: $user,
        sender: $sender,
        message: 'Hey, are you free to review the ticket?',
        roomId: 'room-101'
    );
    expect($chatNotif->category)->toBe(AppNotification::CATEGORY_CHAT);
    expect($chatNotif->data['metadata']['room_id'])->toBe('room-101');

    // 3. WebRTC Call Event
    $callNotif = $service->notifyWebRtcCall(
        recipient: $user,
        caller: $sender,
        callUuid: 'call-xyz-123',
        callType: 'video',
        status: 'ringing'
    );
    expect($callNotif->category)->toBe(AppNotification::CATEGORY_CALL);
    expect($callNotif->data['metadata']['call_uuid'])->toBe('call-xyz-123');

    // 4. Mark all as read & clear
    expect($service->getUnreadCount($user->id))->toBe(3);
    $service->markAllAsRead($user->id);
    expect($service->getUnreadCount($user->id))->toBe(0);

    $recent = $service->getRecentNotifications($user->id, 10);
    expect($recent)->toHaveCount(3);

    $service->clearAll($user->id);
    expect($service->getRecentNotifications($user->id, 10))->toHaveCount(0);
});

test('Livewire notification-bell component renders unread badge and handles actions', function () {
    $user = User::factory()->create();

    $notification = AppNotification::create([
        'user_id' => $user->id,
        'type' => 'system_alert',
        'category' => AppNotification::CATEGORY_SYSTEM,
        'title' => 'System Maintenance Scheduled',
        'message' => 'Maintenance will start at midnight.',
        'channel' => AppNotification::CHANNEL_DATABASE,
    ]);

    Livewire::actingAs($user)
        ->test('notification-bell')
        ->assertSee('System Maintenance Scheduled')
        ->assertSee('1 unread')
        ->call('markAsRead', $notification->id)
        ->assertSee('All caught up!')
        ->call('toggleMute')
        ->assertSet('soundMuted', true);
});

test('Livewire portal notifications inbox supports filtering, search, and bulk operations', function () {
    $user = User::factory()->create();

    $n1 = AppNotification::create([
        'user_id' => $user->id,
        'type' => 'ticket_created',
        'category' => AppNotification::CATEGORY_TICKET,
        'title' => 'Admission Ticket #1001',
        'message' => 'Admission verification pending.',
        'channel' => AppNotification::CHANNEL_DATABASE,
    ]);

    $n2 = AppNotification::create([
        'user_id' => $user->id,
        'type' => 'system_alert',
        'category' => AppNotification::CATEGORY_SYSTEM,
        'title' => 'Security Password Updated',
        'message' => 'Your password was recently modified.',
        'channel' => AppNotification::CHANNEL_DATABASE,
    ]);

    Livewire::actingAs($user)
        ->test('pages::portal.notifications')
        ->assertSee('Admission Ticket #1001')
        ->assertSee('Security Password Updated')
        // Filter by category
        ->set('selectedCategory', 'ticket')
        ->assertSee('Admission Ticket #1001')
        ->assertDontSee('Security Password Updated')
        ->set('selectedCategory', 'all')
        // Search
        ->set('search', 'Security')
        ->assertSee('Security Password Updated')
        ->assertDontSee('Admission Ticket #1001')
        ->set('search', '')
        // Bulk mark as read
        ->set('selectedIds', [$n1->id, $n2->id])
        ->call('bulkMarkAsRead')
        ->assertDispatched('toast', type: 'success')
        // View details modal
        ->call('viewDetails', $n1->id)
        ->assertDispatched('modal-open', name: 'notification-details');

    expect($n1->fresh()->isRead())->toBeTrue();
    expect($n2->fresh()->isRead())->toBeTrue();
});

test('Livewire admin notification settings page saves configurations and executes test sandbox', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.notification')
        ->assertSee('Realtime & Multi-Channel Notification Settings')
        ->set('form.realtime_driver', 'hybrid')
        ->set('form.poll_interval', '3s')
        ->set('form.channel_whatsapp', true)
        ->set('form.channel_telegram', true)
        ->set('form.whatsapp_driver', 'log')
        ->set('form.telegram_driver', 'log')
        ->call('save')
        ->assertDispatched('toast', type: 'success')
        // Test WhatsApp dispatch
        ->set('testWhatsAppPhone', '9876543210')
        ->call('testWhatsApp')
        ->assertDispatched('toast', type: 'success')
        // Test Telegram dispatch
        ->set('testTelegramChatId', '-100123456789')
        ->call('testTelegram')
        ->assertDispatched('toast', type: 'success')
        // Test Live Sandbox
        ->set('sandboxTitle', 'Admin Verification Test')
        ->set('sandboxMessage', 'Testing live sandbox multi-channel dispatch.')
        ->set('sandboxChannel', 'database')
        ->call('runSandboxDispatch')
        ->assertDispatched('toast', type: 'success');

    expect(Setting::get('notification.realtime_driver'))->toBe('hybrid');
    expect(Setting::get('notification.poll_interval'))->toBe('3s');
    expect(Setting::get('notification.channel_whatsapp'))->toBeTrue();
    expect(Setting::get('notification.channel_telegram'))->toBeTrue();
});

test('TicketService triggers realtime multi-channel notifications on ticket creation and reply', function () {
    $this->seed(TicketSystemSeeder::class);

    $student = User::factory()->create(['name' => 'John Candidate']);
    $student->assignRole('Student');

    $staff = User::factory()->create(['name' => 'Support Staff Dave']);
    $staff->assignRole('Administrator');

    $category = TicketCategory::first();
    $category->update(['default_assigned_user_id' => $staff->id]);

    /** @var TicketService $ticketService */
    $ticketService = app(TicketService::class);

    // 1. Student creates ticket
    $ticket = $ticketService->createTicket(
        data: [
            'subject' => 'Application Fee Receipt Download Issue',
            'description' => 'Unable to download payment receipt from portal.',
            'category_id' => $category->id,
            'priority' => Ticket::PRIORITY_HIGH,
            'user_id' => $student->id,
            'source' => Ticket::SOURCE_PORTAL,
        ],
        creator: $student
    );

    // Verify student and assigned staff received in-app notifications
    expect(AppNotification::forUser($student->id)->count())->toBe(1);
    expect(AppNotification::forUser($staff->id)->count())->toBe(1);

    $staffNotif = AppNotification::forUser($staff->id)->first();
    expect($staffNotif->title)->toContain($ticket->ticket_number);
    expect($staffNotif->category)->toBe(AppNotification::CATEGORY_TICKET);

    // 2. Staff replies to ticket
    $ticketService->replyTicket(
        ticket: $ticket,
        data: [
            'message' => 'We have regenerated your payment receipt. Please check the portal now.',
            'type' => TicketMessage::TYPE_PUBLIC_REPLY,
        ],
        sender: $staff
    );

    // Verify student received reply notification
    expect(AppNotification::forUser($student->id)->count())->toBe(2);
    $studentReplyNotif = AppNotification::forUser($student->id)->latest()->first();
    expect($studentReplyNotif->title)->toContain('Support Staff Replied');
});

test('Authentication and account lifecycle events dispatch security and system alerts', function () {
    $user = User::factory()->create([
        'name' => 'Alice Test',
        'email' => 'alice@example.com',
    ]);

    // 1. Successful Login Event
    event(new Login('web', $user, false));
    expect(AppNotification::forUser($user->id)->category(AppNotification::CATEGORY_SECURITY)->count())->toBe(1);
    $loginNotif = AppNotification::forUser($user->id)->latest()->first();
    expect($loginNotif->title)->toContain('New Sign-in Detected');
    expect($loginNotif->iconName())->toBe('shield-check');

    // 2. Failed Login Event
    event(new Failed('web', $user, ['email' => 'alice@example.com']));
    expect(AppNotification::forUser($user->id)->category(AppNotification::CATEGORY_SECURITY)->count())->toBe(2);
    $failedNotif = AppNotification::forUser($user->id)->latest()->first();
    expect($failedNotif->title)->toContain('Failed Sign-in Attempt');
    expect($failedNotif->iconName())->toBe('shield-alert');

    // 3. Password Reset Event
    event(new PasswordReset($user));
    expect(AppNotification::forUser($user->id)->category(AppNotification::CATEGORY_SECURITY)->count())->toBe(3);
    $resetNotif = AppNotification::forUser($user->id)->latest()->first();
    expect($resetNotif->title)->toContain('Password Changed');

    // 4. Registration Welcome Event
    $newUser = User::factory()->create([
        'name' => 'Bob Newbie',
        'email' => 'bob@example.com',
    ]);
    event(new Registered($newUser));
    expect(AppNotification::forUser($newUser->id)->category(AppNotification::CATEGORY_SYSTEM)->count())->toBe(1);
    $regNotif = AppNotification::forUser($newUser->id)->latest()->first();
    expect($regNotif->title)->toContain('Welcome');

    // 5. Email Verified Event
    event(new Verified($newUser));
    expect(AppNotification::forUser($newUser->id)->category(AppNotification::CATEGORY_SECURITY)->count())->toBe(1);
    $verifiedNotif = AppNotification::forUser($newUser->id)->latest()->first();
    expect($verifiedNotif->title)->toContain('Email Verified');
});
