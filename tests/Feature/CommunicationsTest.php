<?php

use App\Models\CommunicationCampaign;
use App\Models\CommunicationLog;
use App\Models\OtpCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommunicationService;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Support\LucideIcons;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    (new SettingsSeeder)->run();
    (new MessageTemplateSeeder)->run();

    Setting::set('sms.enabled', true);
    Setting::set('sms.driver', 'log');
    Setting::set('email.is_enabled', true);
    Mail::fake();
});

test('communications delivery logs page renders for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('admin.communications.logs', ['current_team' => $user->personalTeam()->slug]));

    $response->assertOk();
});

test('compose and bulk messaging page renders for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('admin.communications.compose', ['current_team' => $user->personalTeam()->slug]));

    $response->assertOk();
});

test('sms service and email service inject personalized user name', function () {
    $user = User::factory()->create([
        'name' => 'Aditi Sharma',
        'email' => 'aditi@example.com',
        'phone' => '+919876543210',
    ]);

    $smsService = app(SmsService::class);
    $emailService = app(EmailService::class);

    // Send SMS with template
    $smsService->sendTemplate($user->phone, 'admission_confirmation', ['course' => 'UPSC GS 2026', 'application_no' => 'APP-9988'], $user);

    $smsLog = CommunicationLog::where('recipient', $user->phone)->latest('id')->first();
    expect($smsLog)->not->toBeNull()
        ->and($smsLog->recipient_name)->toBe('Aditi Sharma')
        ->and($smsLog->content)->toContain('Dear Aditi Sharma');

    // Send Email with template
    $emailService->sendTemplate($user->email, 'admission_confirmation', ['course' => 'UPSC GS 2026', 'application_no' => 'APP-9988'], [], $user);

    $emailLog = CommunicationLog::where('recipient', $user->email)->latest('id')->first();
    expect($emailLog)->not->toBeNull()
        ->and($emailLog->recipient_name)->toBe('Aditi Sharma')
        ->and($emailLog->content)->toContain('Dear <strong>Aditi Sharma</strong>');
});

test('otp service personalizes greeting with user name in SMS and Email', function () {
    $user = User::factory()->create([
        'name' => 'Rohan Sen',
        'email' => 'rohan@example.com',
        'phone' => '+919876543211',
    ]);

    $otpService = app(OtpService::class);

    // 1. Mobile OTP
    $resultSms = $otpService->generateAndSend(
        identifier: $user->phone,
        type: OtpCode::TYPE_LOGIN_SMS,
        user: $user
    );

    expect($resultSms['success'])->toBeTrue();

    $smsLog = CommunicationLog::where('recipient', $user->phone)->latest('id')->first();
    expect($smsLog)->not->toBeNull()
        ->and($smsLog->recipient_name)->toBe('Rohan Sen')
        ->and($smsLog->content)->toContain('Dear Rohan Sen');

    // 2. Email OTP
    $resultEmail = $otpService->generateAndSend(
        identifier: $user->email,
        type: OtpCode::TYPE_LOGIN_EMAIL,
        user: $user
    );

    expect($resultEmail['success'])->toBeTrue();

    $emailLog = CommunicationLog::where('recipient', $user->email)->latest('id')->first();
    expect($emailLog)->not->toBeNull()
        ->and($emailLog->recipient_name)->toBe('Rohan Sen')
        ->and($emailLog->content)->toContain('Hello <strong>Rohan Sen</strong>');
});

test('communication service logs failed status and reason when gateway is disabled', function () {
    Setting::set('sms.enabled', false);

    $commService = app(CommunicationService::class);
    $log = $commService->logAndSendSms('+919876543212', 'Test message without gateway');

    expect($log->status)->toBe(CommunicationLog::STATUS_FAILED)
        ->and($log->error_message)->toContain('disabled');
});

test('communication service resends failed and sent messages', function () {
    $commService = app(CommunicationService::class);

    // Start with disabled SMS to create a failed log
    Setting::set('sms.enabled', false);
    $log = $commService->logAndSendSms('+919876543213', 'Important Alert');
    expect($log->status)->toBe(CommunicationLog::STATUS_FAILED);

    // Enable SMS and resend
    Setting::set('sms.enabled', true);
    $resendResult = $commService->resend($log);

    expect($resendResult['success'])->toBeTrue()
        ->and($resendResult['log']->status)->toBe(CommunicationLog::STATUS_SENT)
        ->and($resendResult['log']->resend_count)->toBe(1)
        ->and($resendResult['log']->last_resent_at)->not->toBeNull();
});

test('bulk resend dispatches multiple logs simultaneously', function () {
    $commService = app(CommunicationService::class);

    $log1 = $commService->logAndSendEmail('student1@example.com', 'Subject 1', '<p>Body 1</p>');
    $log2 = $commService->logAndSendSms('+919876543214', 'SMS Body 2');

    $result = $commService->bulkResend([$log1->id, $log2->id]);

    expect($result['success'])->toBeTrue()
        ->and($result['resend_count'])->toBe(2)
        ->and($result['failed_count'])->toBe(0);

    expect($log1->fresh()->resend_count)->toBe(1)
        ->and($log2->fresh()->resend_count)->toBe(1);
});

test('campaign bulk dispatch personalizes message for each target recipient', function () {
    $user1 = User::factory()->create(['name' => 'Alice Green', 'email' => 'alice@example.com', 'phone' => '+919876543221']);
    $user2 = User::factory()->create(['name' => 'Bob Smith', 'email' => 'bob@example.com', 'phone' => '+919876543222']);

    $commService = app(CommunicationService::class);

    $campaign = CommunicationCampaign::create([
        'title' => 'Personalised Test Batch Announcement',
        'channel' => CommunicationCampaign::CHANNEL_EMAIL,
        'recipient_type' => CommunicationCampaign::RECIPIENT_INDIVIDUAL,
        'recipient_ids' => [$user1->id, $user2->id],
        'subject' => 'Hello {name}',
        'content' => '<p>Dear {name}, your registered email is {email}.</p>',
        'status' => CommunicationCampaign::STATUS_DRAFT,
    ]);

    $dispatchResult = $commService->dispatchCampaign($campaign);

    expect($dispatchResult['success'])->toBeTrue()
        ->and($dispatchResult['sent'])->toBe(2)
        ->and($campaign->fresh()->status)->toBe(CommunicationCampaign::STATUS_COMPLETED);

    $log1 = CommunicationLog::where('recipient', 'alice@example.com')->first();
    expect($log1)->not->toBeNull()
        ->and($log1->subject)->toBe('Hello Alice Green')
        ->and($log1->content)->toContain('Dear Alice Green, your registered email is alice@example.com.');

    $log2 = CommunicationLog::where('recipient', 'bob@example.com')->first();
    expect($log2)->not->toBeNull()
        ->and($log2->subject)->toBe('Hello Bob Smith')
        ->and($log2->content)->toContain('Dear Bob Smith, your registered email is bob@example.com.');
});

test('communication drafts can be saved, updated, and deleted', function () {
    $user = User::factory()->create();

    // 1. Save draft via Livewire
    Livewire::actingAs($user)
        ->test('pages::admin.communications.compose')
        ->set('title', 'Upcoming Prelims Guidance')
        ->set('channel', 'email')
        ->set('recipientType', 'all_users')
        ->set('subject', 'Prelims Guidance 2026')
        ->set('content', '<p>Dear {name}, here are the instructions...</p>')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $draft = CommunicationCampaign::where('title', 'Upcoming Prelims Guidance')->first();
    expect($draft)->not->toBeNull()
        ->and($draft->status)->toBe(CommunicationCampaign::STATUS_DRAFT);

    // 2. Load and update draft
    Livewire::actingAs($user)
        ->test('pages::admin.communications.compose')
        ->call('loadDraft', $draft->id)
        ->set('subject', 'Updated Prelims Guidance 2026')
        ->call('saveDraft');

    expect($draft->fresh()->subject)->toBe('Updated Prelims Guidance 2026');

    // 3. Delete draft
    Livewire::actingAs($user)
        ->test('pages::admin.communications.compose')
        ->call('deleteDraft', $draft->id);

    expect($draft->fresh()->trashed())->toBeTrue();
});

test('delivery logs livewire component can filter, resend, and soft delete logs', function () {
    $user = User::factory()->create();
    $commService = app(CommunicationService::class);

    $log = $commService->logAndSendSms('+919876543299', 'Livewire log test message', null, null, $user);

    Livewire::actingAs($user)
        ->test('pages::admin.communications.logs')
        ->assertSee('+919876543299')
        ->call('resendLog', $log->id)
        ->call('deleteLog', $log->id);

    expect($log->fresh()->trashed())->toBeTrue();

    // Restore
    Livewire::actingAs($user)
        ->test('pages::admin.communications.logs')
        ->call('restoreLog', $log->id);

    expect($log->fresh()->trashed())->toBeFalse();
});

test('compose component handles individual user selection and removal accurately', function () {
    $admin = User::factory()->create();
    $u1 = User::factory()->create(['name' => 'Kavita Roy']);
    $u2 = User::factory()->create(['name' => 'Manish Gupta']);

    Livewire::actingAs($admin)
        ->test('pages::admin.communications.compose')
        ->set('selectedUserIds', [(string) $u1->id, (string) $u2->id])
        ->assertSee('Kavita Roy')
        ->assertSee('Manish Gupta')
        ->call('removeSelectedUser', $u1->id)
        ->assertSet('selectedUserIds', [(string) $u2->id])
        ->call('clearSelectedUsers')
        ->assertSet('selectedUserIds', []);
});

test('lucide icons helper resolves all newly registered icons', function () {
    expect(LucideIcons::get('edit-3'))->not->toBeNull()
        ->and(LucideIcons::get('save'))->not->toBeNull()
        ->and(LucideIcons::get('tool'))->not->toBeNull()
        ->and(LucideIcons::get('trash'))->not->toBeNull()
        ->and(LucideIcons::get('message-square'))->not->toBeNull()
        ->and(LucideIcons::get('key'))->not->toBeNull()
        ->and(LucideIcons::get('shield-alert'))->not->toBeNull()
        ->and(LucideIcons::get('database'))->not->toBeNull();
});
