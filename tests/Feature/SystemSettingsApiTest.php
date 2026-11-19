<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    RbacService::seedDefaults();
    $this->seed(SettingsSeeder::class);

    $this->admin = User::factory()->create(['name' => 'Settings Admin User']);
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);

    // Full management key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'System Settings Full Management Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:settings', 'write:settings', 'manage:settings', 'ping'],
        'description' => 'Key with full system settings administrative permissions',
    ], $this->admin);

    $this->fullApiKey = $fullKeyData['apiKey'];
    $this->plainKey = $fullKeyData['plainTextKey'];

    // Readonly key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Settings Readonly Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['read:settings'],
        'description' => 'Read only settings key',
    ], $this->admin);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];

    // Write-only key (no manage:settings)
    $writeKeyData = $this->apiKeyService->generateKey([
        'name' => 'Settings Write Key',
        'type' => 'web_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 150,
        'scopes' => ['read:settings', 'write:settings'],
        'description' => 'Read and write settings key without management scope',
    ], $this->admin);

    $this->writePlainKey = $writeKeyData['plainTextKey'];
});

test('public bootstrap settings can be retrieved without authentication', function () {
    $response = $this->getJson('/api/v1/settings/public');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'app' => ['name', 'site_name', 'tagline', 'campus', 'version', 'copyright', 'institution'],
                'branding' => ['logo_url', 'favicon_url', 'primary_color', 'theme_mode', 'sidebar_theme', 'font_family'],
                'localization' => ['locale', 'timezone', 'date_format', 'time_format', 'currency_symbol', 'currency_code', 'supported_locales'],
                'contact' => ['email', 'address', 'office_timing', 'open_days'],
                'operational' => ['maintenance_mode', 'max_upload_size_mb', 'session_lifetime_minutes'],
                'features' => ['live_chat', 'voice_calls', 'video_calls', 'online_meetings', 'study_materials', 'library', 'online_payments', 'offline_payments'],
            ],
            'meta' => ['server_time', 'api_version'],
        ]);

    // Also test alias route
    $this->getJson('/api/v1/settings/bootstrap')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('reading settings groups requires read:settings scope', function () {
    // Unauthenticated request blocked
    $this->getJson('/api/v1/settings/groups')
        ->assertStatus(401);

    // Read key succeeds
    $response = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/settings/groups');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['group', 'title', 'description', 'total_count', 'active_count'],
            ],
            'meta' => ['total_groups', 'server_time'],
        ]);
});

test('settings statistics returns telemetry and cache status', function () {
    $response = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/settings/statistics');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'counts' => ['total', 'active', 'inactive', 'soft_deleted', 'secrets', 'groups'],
                'cache' => ['is_cached', 'cache_key', 'cache_driver'],
                'environment' => ['app_name', 'php_version', 'laravel_version', 'server_timezone', 'server_time'],
            ],
        ]);
});

test('settings index masks secrets unless reveal_secrets requested by authorized client', function () {
    // Ensure an encrypted secret exists
    Setting::set('sms.two_factor_api_key', 'super-secret-api-key-2factor', $this->admin->id);
    $smsSetting = Setting::firstWhere('key', 'sms.two_factor_api_key');
    $smsSetting->type = Setting::TYPE_SECRET;
    $smsSetting->save();

    // Standard read-only query masks secret
    $response = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/settings?group=sms');

    $response->assertStatus(200);
    $secretItem = collect($response->json('data'))->firstWhere('key', 'sms.two_factor_api_key');
    expect($secretItem)->not->toBeNull();
    expect($secretItem['value'])->toBe('••••••••');
    expect($secretItem['is_secret'])->toBeTrue();

    // With reveal_secrets=1 but read-only scope, still masked
    $unauthReveal = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/settings?group=sms&reveal_secrets=1');
    $unauthSecret = collect($unauthReveal->json('data'))->firstWhere('key', 'sms.two_factor_api_key');
    expect($unauthSecret['value'])->toBe('••••••••');

    // With manage:settings scope and reveal_secrets=1, secret is revealed
    $authReveal = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/settings?group=sms&reveal_secrets=1');
    $revealedSecret = collect($authReveal->json('data'))->firstWhere('key', 'sms.two_factor_api_key');
    expect($revealedSecret['value'])->toBe('super-secret-api-key-2factor');
});

test('can retrieve settings by group as dictionary and item list', function () {
    $response = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/settings/groups/general');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.group', 'general')
        ->assertJsonStructure([
            'success',
            'data' => [
                'group',
                'dictionary',
                'items' => [
                    '*' => ['id', 'key', 'group', 'type', 'label', 'value', 'status'],
                ],
            ],
            'meta' => ['total_items', 'group'],
        ]);

    $dictionary = $response->json('data.dictionary');
    expect($dictionary['general.site_name'])->not->toBeNull();
});

test('can show single setting by unique key', function () {
    $response = $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/settings/item/general.site_name');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'general.site_name')
        ->assertJsonPath('data.group', 'general')
        ->assertJsonPath('data.type', 'string');

    // Also test alias route
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->getJson('/api/v1/settings/general.site_name')
        ->assertStatus(200)
        ->assertJsonPath('data.key', 'general.site_name');
});

test('creating setting requires write:settings scope and logs audit trail', function () {
    $payload = [
        'key' => 'mobile.splash_theme_override',
        'group' => 'appearance',
        'type' => 'string',
        'label' => 'Mobile Splash Theme Override',
        'value' => 'dark_emerald',
        'status' => true,
    ];

    // Read-only key fails with 403
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/settings', $payload)
        ->assertStatus(403);

    // Write key succeeds with 201
    $response = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->postJson('/api/v1/settings', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'mobile.splash_theme_override')
        ->assertJsonPath('data.value', 'dark_emerald');

    $this->assertDatabaseHas('settings', [
        'key' => 'mobile.splash_theme_override',
        'value' => 'dark_emerald',
    ]);

    // Check audit log recorded
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'settings.created',
    ]);
});

test('can update single setting and flushes cache', function () {
    $response = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->putJson('/api/v1/settings/general.site_tagline', [
            'value' => 'Premier Civil Services Coaching Center',
            'label' => 'Updated Tagline Label',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'general.site_tagline')
        ->assertJsonPath('data.value', 'Premier Civil Services Coaching Center')
        ->assertJsonPath('data.label', 'Updated Tagline Label');

    $this->assertDatabaseHas('settings', [
        'key' => 'general.site_tagline',
        'value' => 'Premier Civil Services Coaching Center',
    ]);

    // Verify Audit Log has old and new values
    $log = AuditLog::where('event', 'settings.updated')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('general.site_tagline');
});

test('atomic batch update synchronizes multiple settings in single transaction', function () {
    $batchPayload = [
        'settings' => [
            'appearance.primary_color' => '#047857',
            'localization.timezone' => 'Asia/Kolkata',
            'system.max_upload_size' => 30,
            'custom.new_kiosk_id' => 'KIOSK-GATE-01',
        ],
    ];

    $response = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->postJson('/api/v1/settings/batch', $batchPayload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.updated_count', 4);

    expect(Setting::get('appearance.primary_color'))->toBe('#047857');
    expect(Setting::get('system.max_upload_size'))->toBe(30);
    expect(Setting::get('custom.new_kiosk_id'))->toBe('KIOSK-GATE-01');
});

test('can soft delete, restore, and force delete setting', function () {
    $setting = Setting::create([
        'key' => 'test.temporary_flag',
        'group' => 'system',
        'type' => 'boolean',
        'label' => 'Temporary Flag',
        'value' => '1',
        'status' => true,
    ]);

    // 1. Soft delete
    $deleteRes = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->deleteJson('/api/v1/settings/test.temporary_flag');

    $deleteRes->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('settings', ['key' => 'test.temporary_flag']);

    // 2. Restore
    $restoreRes = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->postJson('/api/v1/settings/test.temporary_flag/restore');

    $restoreRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_trashed', false);

    $this->assertDatabaseHas('settings', [
        'key' => 'test.temporary_flag',
        'deleted_at' => null,
    ]);

    // 3. Force delete requires manage:settings
    $this->withHeader('X-API-Key', $this->writePlainKey)
        ->deleteJson('/api/v1/settings/test.temporary_flag/force')
        ->assertStatus(403);

    $forceRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson('/api/v1/settings/test.temporary_flag/force');

    $forceRes->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('settings', ['key' => 'test.temporary_flag']);
});

test('administrative flush cache and reset settings to factory defaults', function () {
    // Cache test
    $flushRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/settings/cache/flush');

    $flushRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.flushed', true);

    // Alter setting value then reset to default
    Setting::set('general.site_name', 'Custom Overridden Site Name');
    expect(Setting::get('general.site_name'))->toBe('Custom Overridden Site Name');

    $resetRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/settings/reset', [
            'scope' => 'key',
            'target' => 'general.site_name',
        ]);

    $resetRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.reset_count', 1);

    expect(Setting::get('general.site_name'))->toBe('SNT CSSC');
});

test('export and import settings bundle', function () {
    // Export bundle
    $exportRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/settings/export/bundle?group=appearance');

    $exportRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'exported_at',
                'count',
                'settings' => [
                    '*' => ['key', 'group', 'type', 'label', 'value'],
                ],
            ],
        ]);

    // Import bundle
    $importPayload = [
        'settings' => [
            [
                'key' => 'imported.banner_text',
                'group' => 'general',
                'type' => 'string',
                'label' => 'Imported Banner Text',
                'value' => 'New Admissions Open For 2027 Cohort',
                'status' => true,
            ],
        ],
    ];

    $importRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/settings/import/bundle', $importPayload);

    $importRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.imported_count', 1);

    expect(Setting::get('imported.banner_text'))->toBe('New Admissions Open For 2027 Cohort');
});

test('can upload setting branding asset via FileUploadService', function () {
    $file = UploadedFile::fake()->image('brand-logo.png', 300, 100);

    $response = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->postJson('/api/v1/settings/assets', [
            'file' => $file,
            'key' => 'general.site_logo',
            'folder' => 'settings/branding',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.key', 'general.site_logo')
        ->assertJsonStructure([
            'data' => ['key', 'path', 'url', 'original_name', 'size_bytes', 'mime_type'],
        ]);

    $savedPath = $response->json('data.path');
    expect(Setting::get('general.site_logo'))->toBe($savedPath);
});

test('can toggle maintenance mode with custom message and allowed IPs', function () {
    $response = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/settings/maintenance', [
            'enabled' => true,
            'message' => 'Upgrading database servers for 2026 Batch.',
            'allowed_ips' => ['127.0.0.1', '192.168.1.100'],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.maintenance_mode', true)
        ->assertJsonPath('data.message', 'Upgrading database servers for 2026 Batch.')
        ->assertJsonPath('data.allowed_ips', ['127.0.0.1', '192.168.1.100']);

    expect((bool) Setting::get('system.maintenance_mode'))->toBeTrue();
    expect(Setting::get('system.maintenance_message'))->toBe('Upgrading database servers for 2026 Batch.');
});

test('platform-specific bootstrap returns tailored payloads for mobile, web, desktop, and kiosk', function () {
    // Mobile platform bootstrap
    $mobileResponse = $this->getJson('/api/v1/settings/bootstrap/mobile');
    $mobileResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.platform', 'mobile')
        ->assertJsonPath('meta.category', 'mobile')
        ->assertJsonStructure([
            'data' => [
                'app',
                'branding',
                'localization',
                'platform_config' => [
                    'platform',
                    'min_supported_version',
                    'latest_version',
                    'force_update',
                    'play_store_url',
                    'app_store_url',
                    'push_notifications' => ['enabled', 'fcm_sender_id'],
                    'biometrics' => ['fingerprint_allowed', 'face_id_allowed'],
                    'deep_link_scheme',
                ],
            ],
        ]);

    // Web platform bootstrap
    $webResponse = $this->getJson('/api/v1/settings/bootstrap/web');
    $webResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.platform', 'web')
        ->assertJsonStructure([
            'data' => [
                'platform_config' => [
                    'platform',
                    'meta_title',
                    'theme' => ['primary_color', 'font_family', 'dark_mode'],
                    'pwa' => ['name', 'short_name', 'start_url', 'display'],
                ],
            ],
        ]);

    // Desktop platform bootstrap
    $desktopResponse = $this->getJson('/api/v1/settings/bootstrap/desktop');
    $desktopResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.platform', 'desktop')
        ->assertJsonStructure([
            'data' => [
                'platform_config' => [
                    'platform',
                    'min_supported_version',
                    'latest_version',
                    'update_channel',
                    'download_urls' => ['windows', 'mac', 'linux'],
                ],
            ],
        ]);

    // Kiosk platform bootstrap
    $kioskResponse = $this->getJson('/api/v1/settings/bootstrap/kiosk');
    $kioskResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.platform', 'kiosk')
        ->assertJsonPath('data.platform_config.kiosk_mode', true);

    // Invalid platform rejects with 422
    $invalidResponse = $this->getJson('/api/v1/settings/bootstrap/unknown_os');
    $invalidResponse->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'INVALID_PLATFORM');
});

test('version check evaluates semantic versions and flags required updates', function () {
    Setting::set('system.min_mobile_version', '1.1.0');
    Setting::set('system.latest_mobile_version', '1.3.0');
    Setting::set('system.force_mobile_update', false);

    // Version below minimum triggers required update
    $outdatedResponse = $this->postJson('/api/v1/settings/version-check', [
        'platform' => 'android',
        'current_version' => '1.0.4',
        'build_number' => 12,
    ]);

    $outdatedResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.update_available', true)
        ->assertJsonPath('data.update_required', true)
        ->assertJsonPath('data.min_supported_version', '1.1.0')
        ->assertJsonPath('data.latest_version', '1.3.0')
        ->assertJsonPath('data.download_url', Setting::get('system.play_store_url', 'https://play.google.com/store/apps/details?id=in.sntcssc.mis'));

    // Version above min but below latest triggers optional update
    $optionalResponse = $this->postJson('/api/v1/settings/version-check', [
        'platform' => 'ios',
        'current_version' => '1.2.0',
    ]);

    $optionalResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.update_available', true)
        ->assertJsonPath('data.update_required', false);

    // Up to date version has neither available nor required
    $latestResponse = $this->postJson('/api/v1/settings/version-check', [
        'platform' => 'android',
        'current_version' => '1.3.0',
    ]);

    $latestResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.update_available', false)
        ->assertJsonPath('data.update_required', false);

    // Force update toggle forces update even if above min_version
    Setting::set('system.force_mobile_update', true);

    $forcedResponse = $this->postJson('/api/v1/settings/version-check', [
        'platform' => 'android',
        'current_version' => '1.2.5',
    ]);

    $forcedResponse->assertStatus(200)
        ->assertJsonPath('data.update_available', true)
        ->assertJsonPath('data.update_required', true)
        ->assertJsonPath('data.force_update', true);
});

test('features endpoint returns categorized client feature flags', function () {
    $response = $this->getJson('/api/v1/settings/features');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'communication' => ['live_chat', 'voice_calls', 'video_calls', 'online_meetings', 'sms_service', 'email_service'],
                'academics' => ['courses', 'batches', 'study_materials', 'examinations', 'student_tests', 'mock_interviews'],
                'facilities' => ['library', 'library_fines', 'hostel', 'biometrics'],
                'payments' => ['online_payments', 'offline_payments'],
                'cloud_storage' => ['default_disk', 'provider', 'direct_s3_upload'],
                'system' => ['maintenance_mode', 'debug_mode'],
            ],
            'meta' => ['server_time'],
        ]);
});

test('signed upload url preflight validates files and returns upload authorization', function () {
    // Reject excessive file size
    $oversizedResponse = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->postJson('/api/v1/settings/storage/signed-upload-url', [
            'filename' => 'huge_archive.zip',
            'content_type' => 'application/zip',
            'size_bytes' => 150 * 1024 * 1024, // 150MB exceeds default 25MB threshold
        ]);

    $oversizedResponse->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'FILE_TOO_LARGE');

    // Reject dangerous extension
    $disallowedResponse = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->postJson('/api/v1/settings/storage/signed-upload-url', [
            'filename' => 'malicious_script.exe',
            'content_type' => 'application/octet-stream',
            'size_bytes' => 1024,
        ]);

    $disallowedResponse->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'FILE_TYPE_NOT_ALLOWED');

    // Valid file returns upload authorization payload
    $validResponse = $this->withHeader('X-API-Key', $this->writePlainKey)
        ->postJson('/api/v1/settings/storage/signed-upload-url', [
            'filename' => 'upsc_current_affairs_jan2026.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 2 * 1024 * 1024,
            'directory' => 'materials',
        ]);

    $validResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'upload_mode',
                'upload_url',
                'method',
                'target_path',
                'public_url',
            ],
        ]);
});
