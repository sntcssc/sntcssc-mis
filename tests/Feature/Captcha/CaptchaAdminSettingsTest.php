<?php

use App\Models\CaptchaLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    Setting::flushCache();
    Cache::flush();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Administrator');
    $this->actingAs($this->admin);
    $this->team = $this->admin->ensureCurrentTeam();
});

test('unauthenticated users cannot access captcha settings', function () {
    auth()->logout();

    $this->get(route('admin.settings.captcha', ['current_team' => $this->team->slug]))
        ->assertRedirect(route('login'));
});

test('admin with settings.captcha permission can view captcha settings page', function () {
    $this->get(route('admin.settings.captcha', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertSee('reCAPTCHA & Bot Defense')
        ->assertSee('Google reCAPTCHA')
        ->assertSee('Custom CAPTCHA');
});

test('admin can update captcha settings via livewire', function () {
    Livewire::test('pages::admin.settings.captcha', ['current_team' => $this->team->slug])
        ->set('form.enabled', true)
        ->set('form.driver', 'google')
        ->set('form.on_login', true)
        ->set('form.on_register', true)
        ->set('form.google_version', 'v3')
        ->set('form.google_site_key', 'test-site-key-123')
        ->set('form.google_secret_key', 'test-secret-key-456')
        ->set('form.google_score_threshold', 0.7)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect((bool) Setting::get('captcha.enabled'))->toBeTrue()
        ->and(Setting::get('captcha.driver'))->toBe('google')
        ->and(Setting::get('captcha.google_version'))->toBe('v3')
        ->and(Setting::get('captcha.google_site_key'))->toBe('test-site-key-123')
        ->and((float) Setting::get('captcha.google_score_threshold'))->toBe(0.7);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'captcha_settings_updated',
    ]);
});

test('admin can configure custom captcha character modes and parameters', function () {
    Livewire::test('pages::admin.settings.captcha', ['current_team' => $this->team->slug])
        ->set('form.enabled', true)
        ->set('form.driver', 'custom')
        ->set('form.custom_char_type', 'special_characters')
        ->set('form.custom_length', 6)
        ->set('form.custom_case_sensitive', true)
        ->set('form.custom_noise_lines', 4)
        ->set('form.custom_noise_dots', 50)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('captcha.custom_char_type'))->toBe('special_characters')
        ->and((int) Setting::get('captcha.custom_length'))->toBe(6)
        ->and((bool) Setting::get('captcha.custom_case_sensitive'))->toBeTrue();
});

test('admin can test google credentials connection in livewire modal', function () {
    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => false,
            'error-codes' => ['missing-input-response'], // Expected when secret key is valid
        ], 200),
    ]);

    Livewire::test('pages::admin.settings.captcha', ['current_team' => $this->team->slug])
        ->set('form.google_site_key', '6Ldummy')
        ->set('form.google_secret_key', '6LdummySecret')
        ->call('testGoogleConnection')
        ->assertDispatched('modal-open', name: 'google-test-modal')
        ->assertSet('googleTestResult.status', 'success');
});

test('admin can test challenge solver in live tester tab', function () {
    $component = Livewire::test('pages::admin.settings.captcha', ['current_team' => $this->team->slug])
        ->set('activeTab', 'test')
        ->call('refreshTester');

    $token = $component->get('testerChallenge.token');
    expect($token)->not->toBeEmpty();

    $cached = Cache::get("captcha_challenge:{$token}");
    $answer = Crypt::decryptString($cached['answer']);

    $component->set('testerAnswer', $answer)
        ->call('submitTester')
        ->assertSet('testerResult.success', true);
});

test('admin can search, view, soft delete, and restore logs', function () {
    $log = CaptchaLog::factory()->create([
        'ip_address' => '192.168.1.105',
        'status' => 'failed',
    ]);

    Livewire::test('pages::admin.settings.captcha', ['current_team' => $this->team->slug])
        ->set('activeTab', 'logs')
        ->set('search', '192.168.1.105')
        ->assertSee('192.168.1.105')
        ->call('viewLog', $log->id)
        ->assertDispatched('modal-open', name: 'log-detail-modal')
        ->assertSet('selectedLog.id', $log->id)
        ->call('deleteLog', $log->id);

    expect($log->fresh()->trashed())->toBeTrue();

    // Restore
    Livewire::test('pages::admin.settings.captcha', ['current_team' => $this->team->slug])
        ->call('restoreLog', $log->id);

    expect($log->fresh()->trashed())->toBeFalse();
});

test('admin can prune old logs', function () {
    CaptchaLog::factory()->create(['created_at' => now()->subDays(45)]);
    CaptchaLog::factory()->create(['created_at' => now()->subDays(10)]);

    Livewire::test('pages::admin.settings.captcha', ['current_team' => $this->team->slug])
        ->set('pruneDays', 30)
        ->call('pruneLogs');

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'captcha_logs_pruned',
    ]);
});
