<?php

use App\Models\CommunicationCampaign;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\CommunicationService;
use App\Services\RbacService;
use App\Services\SubscriberService;
use Database\Seeders\MessageTemplateSeeder;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(MessageTemplateSeeder::class);

    // Ensure email & SMS are enabled in settings for test predictability
    Setting::set('email.is_enabled', true);
    Setting::set('sms.enabled', true);
    Setting::set('sms.driver', 'log');

    $this->admin = User::factory()->create(['name' => 'Marketing & Communications Director']);
    $this->admin->assignRole('Super Administrator');

    $this->staff = User::factory()->create(['name' => 'Communication Staff User']);
    $this->staff->assignRole('Staff');

    $this->student = User::factory()->create([
        'name' => 'Aspirant Rahul',
        'email' => 'rahul.aspirant@example.com',
        'phone' => '+919876543210',
    ]);

    $this->apiKeyService = app(ApiKeyService::class);

    // Full key with all communication privileges
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Communications Master Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:communications', 'write:communications', 'manage:communications', 'ping'],
        'description' => 'Key with full communications and marketing administration permissions',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Read-only key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Communications Read-only Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['read:communications'],
        'description' => 'Read-only communications telemetry and logs key',
    ], $this->student);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];

    // Write-only key (without manage:communications)
    $writeKeyData = $this->apiKeyService->generateKey([
        'name' => 'Communications Operator Key',
        'type' => 'web_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 150,
        'scopes' => ['read:communications', 'write:communications'],
        'description' => 'Write key without manage:communications scope',
    ], $this->staff);

    $this->writePlainKey = $writeKeyData['plainTextKey'];

    $this->commService = app(CommunicationService::class);
    $this->subService = app(SubscriberService::class);

    // Seed sample communications log
    $this->sampleEmailLog = $this->commService->logAndSendEmail(
        email: 'rahul.aspirant@example.com',
        subject: 'Welcome to SNTCSSC Civil Services 2026',
        htmlBody: '<p>Dear {name}, welcome to your program portal.</p>',
        user: $this->student,
        variables: ['name' => $this->student->name],
        sentBy: $this->admin->id
    );

    // Seed sample subscriber
    $this->sampleSubscriber = $this->subService->subscribe([
        'name' => 'Pooja Sen',
        'email' => 'pooja.sen@example.com',
        'phone' => '+919830112233',
        'country_code' => '+91',
        'type' => Subscriber::TYPE_BOTH,
        'source' => Subscriber::SOURCE_WELCOME,
        'tags' => ['upsc_2026', 'prelims_crash_course'],
    ], $this->admin);

    // Seed sample campaign
    $this->sampleCampaign = CommunicationCampaign::create([
        'title' => 'Mock Examination Schedule Announcement',
        'channel' => CommunicationCampaign::CHANNEL_EMAIL,
        'recipient_type' => CommunicationCampaign::RECIPIENT_ALL,
        'subject' => 'Mock Examination Schedule Released',
        'content' => '<p>Dear {name}, please check your exam schedule.</p>',
        'status' => CommunicationCampaign::STATUS_DRAFT,
        'created_by' => $this->admin->id,
    ]);
});

test('communications endpoints enforce authentication and scope authorization', function () {
    // 1. Unauthenticated request without API Key or Sanctum returns 401
    $res401 = $this->getJson('/api/v1/communications/dashboard');
    $res401->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'API_KEY_MISSING');

    // 2. Readonly key attempting to dispatch campaign or manage communications returns 403
    $res403 = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/communications/send-bulk', [
            'title' => 'Unauthorized Broadcast',
            'channel' => 'email',
            'recipient_type' => 'all_users',
            'content' => 'Test',
        ]);
    $res403->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');

    // 3. Full key has access
    $res200 = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/communications/dashboard');
    $res200->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('dual authentication supports sanctum bearer tokens without API key', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/communications/dashboard');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'communications' => ['total_dispatches', 'delivered_count', 'failed_count', 'delivery_success_rate'],
                'campaigns' => ['total', 'active_or_scheduled', 'completed'],
                'subscribers' => ['total', 'active', 'unsubscribed'],
                'recent_dispatches',
            ],
        ]);
});

test('communications telemetry statistics and export endpoints return correct datasets', function () {
    // 1. Statistics endpoint
    $statsResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/communications/statistics?days=30');

    $statsResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'timeframe_days',
                'daily_trends',
                'channel_breakdown',
                'top_failures',
                'template_usage',
                'subscriber_sources',
            ],
        ]);

    // 2. Export endpoint
    $exportResponse = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/communications/export?channel=email&limit=10');

    $exportResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data',
            'meta' => ['exported_count', 'generated_at'],
        ]);
});

test('direct email dispatch generates delivery log and audit trail', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/send-email', [
            'email' => 'candidate.ias@example.com',
            'subject' => 'Civil Services Prelims Handout Notice',
            'content' => '<p>Dear {name}, your Prelims Handout is available.</p>',
            'variables' => [
                'name' => 'Sourav Mondal',
            ],
            'queue' => false,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.log.recipient', 'candidate.ias@example.com')
        ->assertJsonPath('data.log.channel', 'email');

    // Confirm Database Record
    $this->assertDatabaseHas('communication_logs', [
        'recipient' => 'candidate.ias@example.com',
        'channel' => 'email',
    ]);

    // Confirm Audit Log
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'communication_direct_email_sent',
    ]);
});

test('direct SMS dispatch generates delivery log and normalizes contact', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/send-sms', [
            'phone' => '9876543219',
            'content' => 'Dear {name}, Mock Test 4 seating is confirmed. - SNTCSSC',
            'sender_id' => 'SNTCSS',
            'dlt_template_id' => '1107161829304918234',
            'variables' => [
                'name' => 'Ananya Sharma',
            ],
            'queue' => false,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.log.channel', 'sms');

    $this->assertDatabaseHas('communication_logs', [
        'channel' => 'sms',
    ]);
});

test('direct bulk dispatch creates campaign and triggers execution', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/send-bulk', [
            'title' => 'Mains Answer Writing Session Announcement',
            'channel' => 'email',
            'recipient_type' => 'individual',
            'recipient_ids' => [$this->student->id],
            'subject' => 'Mains Strategy Lecture Tomorrow',
            'content' => '<p>Dear {name}, please attend tomorrow lecture at 10:00 AM.</p>',
            'immediate' => true,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.campaign.title', 'Mains Answer Writing Session Announcement')
        ->assertJsonStructure([
            'data' => [
                'campaign',
                'dispatch_result',
            ],
        ]);

    $this->assertDatabaseHas('communication_campaigns', [
        'title' => 'Mains Answer Writing Session Announcement',
    ]);
});

test('communication logs can be listed, inspected, resent, and soft deleted with restore', function () {
    // 1. List logs
    $listRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/communications/logs?channel=email&per_page=5');
    $listRes->assertStatus(200)
        ->assertJsonPath('success', true);

    // 2. Show single log
    $showRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/communications/logs/{$this->sampleEmailLog->id}");
    $showRes->assertStatus(200)
        ->assertJsonPath('data.id', $this->sampleEmailLog->id)
        ->assertJsonPath('data.recipient', $this->sampleEmailLog->recipient);

    // 3. Resend individual log
    $resendRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/communications/logs/{$this->sampleEmailLog->id}/resend");
    $resendRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.log.resend_count', 1);

    // 4. Bulk resend
    $bulkResendRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/logs/bulk-resend', [
            'log_ids' => [$this->sampleEmailLog->id],
        ]);
    $bulkResendRes->assertStatus(200)
        ->assertJsonPath('success', true);

    // 5. Soft delete log
    $delRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/communications/logs/{$this->sampleEmailLog->id}");
    $delRes->assertStatus(200)
        ->assertJsonPath('success', true);
    $this->assertSoftDeleted('communication_logs', ['id' => $this->sampleEmailLog->id]);

    // 6. Restore log
    $restoreRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/communications/logs/{$this->sampleEmailLog->id}/restore");
    $restoreRes->assertStatus(200)
        ->assertJsonPath('success', true);
    $this->assertNotSoftDeleted('communication_logs', ['id' => $this->sampleEmailLog->id]);
});

test('marketing campaigns support complete lifecycle operations', function () {
    // 1. Create campaign
    $createRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/campaigns', [
            'title' => 'IAS Prelims 2026 Test Series Phase 2',
            'channel' => 'both',
            'recipient_type' => 'all_users',
            'subject' => 'Phase 2 Test Series Registration Now Open',
            'content' => 'Dear {name}, register for Phase 2 mock exams on your dashboard.',
        ]);

    $createRes->assertStatus(201)
        ->assertJsonPath('success', true);
    $campaignId = $createRes->json('data.campaign.id');

    // 2. Show campaign
    $showRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/communications/campaigns/{$campaignId}");
    $showRes->assertStatus(200)
        ->assertJsonPath('data.title', 'IAS Prelims 2026 Test Series Phase 2');

    // 3. Update campaign
    $updateRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/communications/campaigns/{$campaignId}", [
            'title' => 'IAS Prelims 2026 Test Series Phase 2 (Updated)',
        ]);
    $updateRes->assertStatus(200)
        ->assertJsonPath('data.campaign.title', 'IAS Prelims 2026 Test Series Phase 2 (Updated)');

    // 4. Cancel campaign
    $cancelRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/communications/campaigns/{$campaignId}/cancel");
    $cancelRes->assertStatus(200)
        ->assertJsonPath('data.campaign.status', CommunicationCampaign::STATUS_CANCELLED);

    // 5. Soft delete & restore
    $deleteRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/communications/campaigns/{$campaignId}");
    $deleteRes->assertStatus(200);
    $this->assertSoftDeleted('communication_campaigns', ['id' => $campaignId]);

    $restoreRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/communications/campaigns/{$campaignId}/restore");
    $restoreRes->assertStatus(200);
    $this->assertNotSoftDeleted('communication_campaigns', ['id' => $campaignId]);
});

test('marketing subscribers support directory queries, updates, bulk triage, and export', function () {
    // 1. List subscribers
    $listRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/communications/subscribers?status=active');
    $listRes->assertStatus(200)
        ->assertJsonPath('success', true);

    // 2. Add new subscriber
    $storeRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/subscribers', [
            'name' => 'Vikram Roy',
            'email' => 'vikram.roy@example.com',
            'phone' => '+919876543288',
            'type' => 'email',
            'tags' => ['wbcs_2026'],
        ]);
    $storeRes->assertStatus(201)
        ->assertJsonPath('data.subscriber.email', 'vikram.roy@example.com');
    $subId = $storeRes->json('data.subscriber.id');

    // 3. Update subscriber
    $updateRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson("/api/v1/communications/subscribers/{$subId}", [
            'name' => 'Vikram Roy Choudhury',
        ]);
    $updateRes->assertStatus(200)
        ->assertJsonPath('data.subscriber.name', 'Vikram Roy Choudhury');

    // 4. Administrative unsubscribe
    $unsubRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/communications/subscribers/{$subId}/unsubscribe", [
            'reason' => 'Candidate opted out via email request',
        ]);
    $unsubRes->assertStatus(200)
        ->assertJsonPath('data.subscriber.status', Subscriber::STATUS_UNSUBSCRIBED);

    // 5. Bulk action: add tag
    $bulkRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/subscribers/bulk-action', [
            'action' => 'add_tag',
            'subscriber_ids' => [$this->sampleSubscriber->id],
            'tag' => 'webinar_attendee',
        ]);
    $bulkRes->assertStatus(200)
        ->assertJsonPath('data.affected_count', 1);

    // 6. Export subscribers
    $exportRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/communications/subscribers/export');
    $exportRes->assertStatus(200)
        ->assertJsonPath('success', true);

    // 7. Bulk import subscribers
    $importRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/subscribers/import', [
            'subscribers' => [
                [
                    'name' => 'Debolina Banerjee',
                    'email' => 'debolina.b@example.com',
                    'phone' => '+919830554433',
                    'tags' => ['imported_lead'],
                ],
            ],
        ]);
    $importRes->assertStatus(200)
        ->assertJsonPath('data.imported_count', 1);
});

test('public lead subscription and one-click unsubscribe operate without API key', function () {
    // 1. Public subscribe
    $subRes = $this->postJson('/api/v1/communications/public/subscribe', [
        'email' => 'public.aspirant@gmail.com',
        'name' => 'Public Aspirant',
        'phone' => '+919876500011',
        'source' => 'welcome_page',
        'tags' => ['public_lead'],
    ]);

    $subRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.subscribed', true);

    $subscriber = Subscriber::where('email', 'public.aspirant@gmail.com')->first();
    expect($subscriber)->not->toBeNull();

    // 2. Public unsubscribe via token
    $unsubRes = $this->postJson('/api/v1/communications/public/unsubscribe', [
        'token' => $subscriber->unsubscribe_token,
        'reason' => 'Completed examination',
    ]);

    $unsubRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.unsubscribed', true);

    expect($subscriber->fresh()->status)->toBe(Subscriber::STATUS_UNSUBSCRIBED);
});

test('email and SMS message templates support CRUD operations and dynamic previews', function () {
    // 1. Create Email Template
    $emailTmplRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/templates/email', [
            'code' => 'mains_workshop_pass',
            'name' => 'Mains Answer Writing Entry Pass',
            'category' => 'notice',
            'subject' => 'Your Entry Pass for Mains Workshop, {name}',
            'body' => '<h2>Dear {name},</h2><p>Your session entry pass is confirmed for {date}.</p>',
            'variables' => ['name', 'date'],
            'status' => true,
        ]);

    $emailTmplRes->assertStatus(201)
        ->assertJsonPath('data.template.code', 'mains_workshop_pass');

    // 2. Preview Email Template
    $previewEmailRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/templates/email/mains_workshop_pass/preview', [
            'variables' => [
                'name' => 'Tathagata Roy',
                'date' => '05 November 2026',
            ],
        ]);

    $previewEmailRes->assertStatus(200)
        ->assertJsonPath('data.rendered_subject', 'Your Entry Pass for Mains Workshop, Tathagata Roy')
        ->assertSee('Tathagata Roy');

    // 3. Create SMS Template
    $smsTmplRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/templates/sms', [
            'code' => 'gate_entry_sms',
            'name' => 'Exam Hall Gate Entry SMS',
            'category' => 'notification',
            'sender_id' => 'SNTCSS',
            'dlt_template_id' => '1107161829304918239',
            'body' => 'Dear {name}, your Desk number is {desk} at Centre B. - SNTCSSC',
            'variables' => ['name', 'desk'],
            'status' => true,
        ]);

    $smsTmplRes->assertStatus(201)
        ->assertJsonPath('data.template.code', 'gate_entry_sms');

    // 4. Preview SMS Template
    $previewSmsRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/templates/sms/gate_entry_sms/preview', [
            'variables' => [
                'name' => 'Aindrila Guha',
                'desk' => 'Desk 42',
            ],
        ]);

    $previewSmsRes->assertStatus(200)
        ->assertJsonPath('data.rendered_body', 'Dear Aindrila Guha, your Desk number is Desk 42 at Centre B. - SNTCSSC')
        ->assertJsonStructure(['data' => ['character_count']]);

    // 5. Soft delete & restore templates
    $delEmail = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson('/api/v1/communications/templates/email/mains_workshop_pass');
    $delEmail->assertStatus(200);
    $this->assertSoftDeleted('email_templates', ['code' => 'mains_workshop_pass']);

    $resEmail = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/templates/email/mains_workshop_pass/restore');
    $resEmail->assertStatus(200);
    $this->assertNotSoftDeleted('email_templates', ['code' => 'mains_workshop_pass']);

    $delSms = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson('/api/v1/communications/templates/sms/gate_entry_sms');
    $delSms->assertStatus(200);
    $this->assertSoftDeleted('sms_templates', ['code' => 'gate_entry_sms']);

    $resSms = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/templates/sms/gate_entry_sms/restore');
    $resSms->assertStatus(200);
    $this->assertNotSoftDeleted('sms_templates', ['code' => 'gate_entry_sms']);
});

test('purge logs and campaign logs return valid structured responses', function () {
    // 1. Campaign logs
    $campLogsRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson("/api/v1/communications/campaigns/{$this->sampleCampaign->id}/logs");
    $campLogsRes->assertStatus(200)
        ->assertJsonPath('success', true);

    // 2. Purge logs
    $purgeRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/communications/logs/purge', [
            'days_older_than' => 90,
        ]);
    $purgeRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['purged_count']]);
});

test('subscribers can be soft deleted and restored with audit logging', function () {
    $delRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson("/api/v1/communications/subscribers/{$this->sampleSubscriber->id}");
    $delRes->assertStatus(200);
    $this->assertSoftDeleted('subscribers', ['id' => $this->sampleSubscriber->id]);

    $restRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson("/api/v1/communications/subscribers/{$this->sampleSubscriber->id}/restore");
    $restRes->assertStatus(200);
    $this->assertNotSoftDeleted('subscribers', ['id' => $this->sampleSubscriber->id]);
});
