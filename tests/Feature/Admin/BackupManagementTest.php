<?php

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use App\Services\RbacService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    Storage::fake('local');
    File::ensureDirectoryExists(storage_path('app/backups'));
});

test('administrator can create a database-only backup archive', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var BackupService $service */
    $service = app(BackupService::class);
    $result = $service->createBackup(
        options: ['type' => Backup::TYPE_DATABASE_ONLY, 'send_email' => false],
        creator: $admin,
        triggerType: Backup::TRIGGER_MANUAL
    );

    expect($result['success'])->toBeTrue();
    expect($result['backup'])->not->toBeNull();

    $backup = $result['backup'];
    expect($backup->status)->toBe(Backup::STATUS_COMPLETED);
    expect($backup->type)->toBe(Backup::TYPE_DATABASE_ONLY);
    expect($backup->tables_count)->toBeGreaterThan(0);
    expect($backup->size_bytes)->toBeGreaterThan(0);

    $log = AuditLog::where('event', 'backup_created')
        ->where('user_id', $admin->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
});

test('administrator can create a full backup archive with media files', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    // Create a dummy file in storage/app/public
    File::ensureDirectoryExists(storage_path('app/public/test'));
    File::put(storage_path('app/public/test/logo.txt'), 'SNT CSSC Logo Test Content');

    /** @var BackupService $service */
    $service = app(BackupService::class);
    $result = $service->createBackup(
        options: ['type' => Backup::TYPE_FULL_WITH_MEDIA, 'send_email' => false],
        creator: $admin,
        triggerType: Backup::TRIGGER_MANUAL
    );

    expect($result['success'])->toBeTrue();
    $backup = $result['backup'];
    expect($backup->type)->toBe(Backup::TYPE_FULL_WITH_MEDIA);
    expect($backup->files_count)->toBeGreaterThan(0);
});

test('administrator can restore database from backup archive', function () {
    $admin = User::factory()->create(['name' => 'Original Admin Name']);
    $admin->assignRole('Super Administrator');

    /** @var BackupService $service */
    $service = app(BackupService::class);

    // 1. Create a snapshot
    $createResult = $service->createBackup(
        options: ['type' => Backup::TYPE_DATABASE_ONLY, 'send_email' => false],
        creator: $admin
    );

    $backup = $createResult['backup'];
    expect($backup)->not->toBeNull();

    // 2. Restore
    $restoreResult = $service->restoreBackup($backup, $admin, createSafetyBackup: false);
    if (! $restoreResult['success']) {
        dump($restoreResult);
    }
    expect($restoreResult['success'])->toBeTrue();

    $log = AuditLog::where('event', 'backup_restored')->latest('id')->first();
    expect($log)->not->toBeNull();
});

test('backup retention policy prunes archives exceeding count limit', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Setting::set('backup.retention_count', 3, $admin->id);
    Setting::set('backup.retention_days', 365, $admin->id);

    /** @var BackupService $service */
    $service = app(BackupService::class);

    // Create 4 backups
    for ($i = 0; $i < 4; $i++) {
        $service->createBackup(
            options: ['type' => Backup::TYPE_DATABASE_ONLY, 'send_email' => false],
            creator: $admin
        );
    }

    // Active backups count should be at most 3
    $activeCount = Backup::count();
    expect($activeCount)->toBeLessThanOrEqual(3);
});

test('email notification is dispatched when backup is created', function () {
    Mail::fake();

    Setting::set('email.is_enabled', '1');
    Setting::set('backup.notification_email', 'admin@example.com');
    Setting::set('backup.email_on_success', '1');
    Setting::set('backup.email_attachment_max_mb', 15);

    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var BackupService $service */
    $service = app(BackupService::class);
    $result = $service->createBackup(
        options: ['type' => Backup::TYPE_DATABASE_ONLY, 'send_email' => true, 'recipient_email' => 'admin@example.com'],
        creator: $admin
    );

    expect($result['success'])->toBeTrue();
    $backup = $result['backup'];
    expect($backup->email_sent)->toBeTrue();
    expect($backup->email_recipient)->toBe('admin@example.com');
});

test('scheduled health report is dispatched to configured administrator email', function () {
    Mail::fake();

    Setting::set('email.is_enabled', '1');
    Setting::set('backup.notification_email', 'report@example.com');

    /** @var BackupService $service */
    $service = app(BackupService::class);
    $sent = $service->sendScheduledReport('report@example.com');

    expect($sent)->toBeTrue();
});

test('livewire database backups page allows searching, filtering, and settings updates', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var BackupService $service */
    $service = app(BackupService::class);
    $service->createBackup(
        options: ['type' => Backup::TYPE_DATABASE_ONLY, 'name' => 'unit_test_backup', 'send_email' => false],
        creator: $admin
    );

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.backup')
        ->assertSee('Database & Backups')
        ->set('search', 'unit_test_backup')
        ->assertSee('unit_test_backup')
        ->set('settingsForm.retention_count', 15)
        ->set('settingsForm.schedule_frequency', 'weekly')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect((int) Setting::get('backup.retention_count'))->toBe(15);
    expect(Setting::get('backup.schedule_frequency'))->toBe('weekly');
});

test('livewire component allows single and bulk deletion of backup archives', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    /** @var BackupService $service */
    $service = app(BackupService::class);
    $b1 = $service->createBackup(['type' => Backup::TYPE_DATABASE_ONLY, 'send_email' => false], $admin)['backup'];
    $b2 = $service->createBackup(['type' => Backup::TYPE_DATABASE_ONLY, 'send_email' => false], $admin)['backup'];

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.backup')
        ->set('selectedBackupId', $b1->id)
        ->call('deleteSingle')
        ->assertHasNoErrors();

    expect(Backup::find($b1->id))->toBeNull();

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.backup')
        ->set('selectedIds', [$b2->id])
        ->call('bulkDelete')
        ->assertHasNoErrors();

    expect(Backup::find($b2->id))->toBeNull();
});

test('artisan backup commands execute cleanly', function () {
    $this->artisan('app:backup:run --no-email')
        ->assertSuccessful();

    $this->artisan('app:backup:clean')
        ->assertSuccessful();
});
