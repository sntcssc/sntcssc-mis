<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\FileUploadService;
use App\Services\StorageService;
use App\Support\Toast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & permissions
        $perm = Permission::firstOrCreate(['name' => 'settings.storage', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'Super Administrator', 'guard_name' => 'web']);
        $adminRole->givePermissionTo($perm);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('Super Administrator');

        $this->regularUser = User::factory()->create();
    }

    public function test_guest_is_redirected_from_storage_settings(): void
    {
        $response = $this->get(route('admin.settings.storage'));
        $response->assertRedirect(route('login'));
    }

    public function test_unauthorized_user_cannot_access_storage_settings(): void
    {
        $response = $this->actingAs($this->regularUser)->get(route('admin.settings.storage'));
        $response->assertStatus(403);
    }

    public function test_admin_can_view_storage_settings_page(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.settings.storage'));
        $response->assertStatus(200);
        $response->assertSee('Storage & S3 Cloud Configuration');
        $response->assertSee('Active Storage Disk');
        $response->assertSee('Cloud Storage Provider');
    }

    public function test_admin_can_save_storage_settings(): void
    {
        Livewire::actingAs($this->superAdmin)
            ->test('pages::admin.settings.storage')
            ->set('form.default_disk', 's3')
            ->set('form.provider', 'r2')
            ->set('form.s3_key', 'test_key_12345')
            ->set('form.s3_secret', 'test_secret_67890')
            ->set('form.s3_region', 'auto')
            ->set('form.s3_bucket', 'my-production-bucket')
            ->set('form.s3_endpoint', 'https://example-account.r2.cloudflarestorage.com')
            ->set('form.s3_url', 'https://cdn.example.com')
            ->set('form.s3_use_path_style_endpoint', false)
            ->set('form.max_upload_size_mb', 50)
            ->set('form.auto_backup_to_s3', true)
            ->call('save')
            ->assertHasNoErrors();

        // Verify settings in database
        $this->assertEquals('s3', Setting::get('storage.default_disk'));
        $this->assertEquals('r2', Setting::get('storage.provider'));
        $this->assertEquals('test_key_12345', Setting::get('storage.s3_key'));
        $this->assertEquals('test_secret_67890', Setting::get('storage.s3_secret'));
        $this->assertEquals('auto', Setting::get('storage.s3_region'));
        $this->assertEquals('my-production-bucket', Setting::get('storage.s3_bucket'));
        $this->assertEquals('https://example-account.r2.cloudflarestorage.com', Setting::get('storage.s3_endpoint'));
        $this->assertEquals('https://cdn.example.com', Setting::get('storage.s3_url'));
        $this->assertEquals(50, Setting::get('storage.max_upload_size_mb'));
        $this->assertTrue(Setting::get('storage.auto_backup_to_s3'));

        // Verify audit log entry
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'storage_settings_updated',
        ]);
    }

    public function test_s3_secret_is_encrypted_and_masked_in_component(): void
    {
        $settingModel = Setting::firstOrNew(['key' => 'storage.s3_secret']);
        $settingModel->type = Setting::TYPE_SECRET;
        $settingModel->value = 'very_sensitive_key_phrase';
        $settingModel->save();

        // Stored value in raw column must be encrypted ciphertext
        $rawDatabaseValue = $settingModel->getRawOriginal('value');
        $this->assertNotEquals('very_sensitive_key_phrase', $rawDatabaseValue);
        $this->assertEquals('very_sensitive_key_phrase', $settingModel->rawValue());

        // Component mount keeps the secret field blank for security
        $component = Livewire::actingAs($this->superAdmin)
            ->test('pages::admin.settings.storage');

        $this->assertEquals('', $component->get('form.s3_secret'));
        $this->assertTrue($component->get('isSecretConfigured'));
    }

    public function test_provider_presets_apply_defaults(): void
    {
        Livewire::actingAs($this->superAdmin)
            ->test('pages::admin.settings.storage')
            ->call('selectProvider', 'minio')
            ->assertSet('form.provider', 'minio')
            ->assertSet('form.s3_use_path_style_endpoint', true);

        Livewire::actingAs($this->superAdmin)
            ->test('pages::admin.settings.storage')
            ->call('selectProvider', 'r2')
            ->assertSet('form.provider', 'r2')
            ->assertSet('form.s3_region', 'auto');
    }

    public function test_storage_connection_diagnostic_probe_validation(): void
    {
        // Missing credentials must return failure with diagnostics
        $result = StorageService::testConnection([
            's3_key' => '',
            's3_secret' => '',
            's3_bucket' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('MISSING_ACCESS_KEY', $result['details']['error_code']);

        $resultNoBucket = StorageService::testConnection([
            's3_key' => 'valid_key',
            's3_secret' => 'valid_secret',
            's3_bucket' => '',
        ]);

        $this->assertFalse($resultNoBucket['success']);
        $this->assertEquals('MISSING_BUCKET_NAME', $resultNoBucket['details']['error_code']);
    }

    public function test_storage_telemetry_statistics_returns_valid_shape(): void
    {
        $stats = StorageService::getStorageStatistics();

        $this->assertArrayHasKey('default_disk', $stats);
        $this->assertArrayHasKey('provider', $stats);
        $this->assertArrayHasKey('s3_configured', $stats);
        $this->assertArrayHasKey('public_storage', $stats);
        $this->assertArrayHasKey('private_storage', $stats);
        $this->assertArrayHasKey('policy', $stats);
        $this->assertIsArray($stats['public_storage']['categories']);
    }

    public function test_storage_api_overview_endpoint(): void
    {
        $generated = app(ApiKeyService::class)->generateKey([
            'name' => 'Test Storage Key',
            'scopes' => ['read:settings'],
        ], $this->superAdmin);

        $response = $this->withHeaders([
            'X-API-Key' => $generated['plainTextKey'],
        ])->getJson('/api/v1/settings/storage/overview');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'default_disk',
                'provider',
                'public_storage',
                'policy',
            ],
            'meta' => [
                'providers',
            ],
        ]);
    }

    public function test_storage_api_test_connection_endpoint(): void
    {
        $generated = app(ApiKeyService::class)->generateKey([
            'name' => 'Test Storage Manage Key',
            'scopes' => ['manage:settings'],
        ], $this->superAdmin);

        $response = $this->withHeaders([
            'X-API-Key' => $generated['plainTextKey'],
        ])->postJson('/api/v1/settings/test-storage', [
            's3_key' => '',
            's3_secret' => '',
            's3_bucket' => '',
        ]);

        // Returns 400 with diagnostic failure details
        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'STORAGE_TEST_FAILED',
            ],
        ]);
    }

    public function test_file_upload_service_respects_storage_disk(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $storedPath = FileUploadService::store($file, 'test_docs', 'prefix');
        $this->assertNotEmpty($storedPath);
        Storage::disk('public')->assertExists($storedPath);

        $url = FileUploadService::url($storedPath, 'public');
        $this->assertNotEmpty($url);

        $deleted = FileUploadService::delete($storedPath, 'public');
        $this->assertTrue($deleted);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_storage_explorer_upload_and_delete_actions_in_component(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('test-upload.png', 50, 'image/png');

        $component = Livewire::actingAs($this->superAdmin)
            ->test('pages::admin.settings.storage')
            ->set('testUploadFile', $file)
            ->call('uploadTestFile')
            ->assertHasNoErrors();

        // Prompt delete modal
        $component->call('promptDeleteFile', 'test_uploads/test.png', 'public')
            ->assertSet('showDeleteModal', true)
            ->assertSet('fileToDeletePath', 'test_uploads/test.png')
            ->call('cancelDeleteFile')
            ->assertSet('showDeleteModal', false);
    }

    public function test_dynamic_config_application(): void
    {
        Setting::set('storage.default_disk', 's3');
        Setting::set('storage.s3_bucket', 'dynamic-bucket');
        Setting::set('storage.s3_region', 'ap-south-1');
        Setting::set('storage.s3_endpoint', 'https://s3.ap-south-1.amazonaws.com');
        Setting::set('storage.s3_url', 'https://assets.sntcssc.in');

        StorageService::applyDynamicConfig();

        $this->assertEquals('s3', config('filesystems.default'));
        $this->assertEquals('dynamic-bucket', config('filesystems.disks.s3.bucket'));
        $this->assertEquals('ap-south-1', config('filesystems.disks.s3.region'));
        $this->assertEquals('https://s3.ap-south-1.amazonaws.com', config('filesystems.disks.s3.endpoint'));
        $this->assertEquals('https://assets.sntcssc.in', config('filesystems.disks.s3.url'));
    }

    public function test_storage_settings_actions_dispatch_livewire_toasts_without_polluting_session(): void
    {
        Livewire::actingAs($this->superAdmin)
            ->test('pages::admin.settings.storage')
            ->call('selectProvider', 'r2')
            ->assertDispatched('toast', function ($event, $params) {
                return $params['type'] === 'info' && str_contains($params['message'], 'Cloudflare R2');
            })
            ->call('save')
            ->assertDispatched('toast', function ($event, $params) {
                return $params['type'] === 'success';
            });

        // The session must not have persistent lingering toasts
        $this->assertEmpty(session('toasts', []));
    }

    public function test_toasts_component_clears_flashed_toasts_on_render_to_prevent_reload_persistence(): void
    {
        Toast::add('success', 'Flashed one-time alert');

        // First render pulls and clears toasts from session
        $output = Blade::render('<x-ui.toasts />');
        $this->assertStringContainsString('Flashed one-time alert', $output);

        // Session must be cleared immediately upon render
        $this->assertEmpty(session('toasts', []));

        // Subsequent page reload render must not contain the toast
        $reloadOutput = Blade::render('<x-ui.toasts />');
        $this->assertStringNotContainsString('Flashed one-time alert', $reloadOutput);
    }
}
