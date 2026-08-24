<?php

use App\Jobs\DispatchCommunicationCampaignJob;
use App\Jobs\SendQueuedEmailJob;
use App\Jobs\SendQueuedSmsJob;
use App\Models\CommunicationCampaign;
use App\Models\CronJob;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\CommunicationService;
use App\Services\OtpService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('otp cooldown wait seconds is always a rounded integer without fractions', function () {
    $otpService = app(OtpService::class);
    $user = User::factory()->create(['email' => 'cooldown_test@example.com']);

    // Create an OTP created 10 seconds ago
    DB::table('otp_codes')->insert([
        'identifier' => 'cooldown_test@example.com',
        'type' => OtpCode::TYPE_REGISTRATION_EMAIL,
        'code_hash' => hash('sha256', '123456'),
        'expires_at' => now()->addMinutes(10),
        'created_at' => now()->subSeconds(10),
        'updated_at' => now()->subSeconds(10),
    ]);

    $cooldown = $otpService->checkCooldown('cooldown_test@example.com', OtpCode::TYPE_REGISTRATION_EMAIL, 60);

    expect($cooldown['can_resend'])->toBeFalse()
        ->and($cooldown['wait_seconds'])->toBeInt()
        ->and($cooldown['wait_seconds'])->toBeGreaterThanOrEqual(49)
        ->and($cooldown['wait_seconds'])->toBeLessThanOrEqual(51);

    // Call generateAndSend and check message
    $response = $otpService->generateAndSend('cooldown_test@example.com', OtpCode::TYPE_REGISTRATION_EMAIL, $user);

    expect($response['success'])->toBeFalse()
        ->and($response['cooldown_seconds'])->toBeInt()
        ->and($response['message'])->toMatch('/Please wait \d+ seconds before requesting another code\./');
});

test('non-otp messages and campaigns can be dispatched via queued jobs', function () {
    Queue::fake();

    $commService = app(CommunicationService::class);
    $user = User::factory()->create();

    // 1. Queue email
    $commService->queueEmail(
        email: 'student@example.com',
        subject: 'Weekly Announcement',
        htmlBody: '<p>Welcome {name}</p>',
        user: $user
    );

    Queue::assertPushed(SendQueuedEmailJob::class, function ($job) {
        return $job->email === 'student@example.com' && $job->subject === 'Weekly Announcement';
    });

    // 2. Queue SMS
    $commService->queueSms(
        phone: '+919876543210',
        message: 'Hello student',
        user: $user
    );

    Queue::assertPushed(SendQueuedSmsJob::class, function ($job) {
        return $job->phone === '+919876543210';
    });

    // 3. Queue Campaign
    $campaign = CommunicationCampaign::create([
        'title' => 'Broadcast Test',
        'channel' => 'email',
        'recipient_type' => 'all_users',
        'content' => 'Hello all',
        'status' => 'draft',
    ]);

    $commService->queueCampaign($campaign);

    Queue::assertPushed(DispatchCommunicationCampaignJob::class, function ($job) use ($campaign) {
        return $job->campaign->id === $campaign->id;
    });
});

test('cron job model can calculate next run date and execute commands', function () {
    $job = CronJob::create([
        'name' => 'Clear Password Resets Test',
        'command' => 'auth:clear-resets',
        'expression' => '0 0 * * *',
        'description' => 'Test clearing resets',
        'is_active' => true,
    ]);

    expect($job->calculateNextRunAt())->not->toBeNull()
        ->and($job->getHumanFrequency())->toBe('Daily at Midnight (00:00)');

    $result = $job->run();

    expect($result['success'])->toBeTrue()
        ->and($job->fresh()->last_run_status)->toBe(CronJob::STATUS_SUCCESS)
        ->and($job->fresh()->last_run_at)->not->toBeNull()
        ->and($job->fresh()->last_run_duration)->toBeGreaterThanOrEqual(0);
});

test('admin can manage cron jobs via livewire dashboard', function () {
    $admin = User::factory()->create();

    $existing = CronJob::create([
        'name' => 'Prune Expired Team Invitations',
        'command' => 'teams:prune-invitations',
        'expression' => '0 0 * * *',
        'description' => 'Deletes expired invitations',
        'is_active' => true,
    ]);

    // 1. Render page
    Livewire::actingAs($admin)
        ->test('pages::admin.system.cron-jobs')
        ->assertOk()
        ->assertSee('Cron Jobs & Scheduled Tasks')
        ->assertSee('Prune Expired Team Invitations');

    // 2. Create new cron job
    Livewire::actingAs($admin)
        ->test('pages::admin.system.cron-jobs')
        ->set('name', 'Custom Hourly Test Task')
        ->set('command', 'auth:clear-resets')
        ->set('frequencyPreset', '0 * * * *')
        ->set('description', 'Runs hourly')
        ->call('saveJob')
        ->assertHasNoErrors();

    $created = CronJob::where('name', 'Custom Hourly Test Task')->first();
    expect($created)->not->toBeNull()
        ->and($created->expression)->toBe('0 * * * *');

    // 3. Toggle status
    Livewire::actingAs($admin)
        ->test('pages::admin.system.cron-jobs')
        ->call('toggleActive', $created->id);

    expect($created->fresh()->is_active)->toBeFalse();

    // 4. Run Now manually
    Livewire::actingAs($admin)
        ->test('pages::admin.system.cron-jobs')
        ->call('runNow', $created->id)
        ->assertSet('outputModalOpen', true);

    expect($created->fresh()->last_run_status)->toBe(CronJob::STATUS_SUCCESS);

    // 5. Soft Delete and Restore
    Livewire::actingAs($admin)
        ->test('pages::admin.system.cron-jobs')
        ->call('deleteJob', $created->id);

    expect($created->fresh()->trashed())->toBeTrue();

    Livewire::actingAs($admin)
        ->test('pages::admin.system.cron-jobs')
        ->call('restoreJob', $created->id);

    expect($created->fresh()->trashed())->toBeFalse();
});
