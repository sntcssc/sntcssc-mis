<?php

use App\Models\CaptchaLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Captcha\Drivers\CustomCaptchaDriver;
use App\Services\RbacService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.custom_char_type', CustomCaptchaDriver::TYPE_ALPHANUMERIC);
    Setting::set('captcha.custom_length', 5);
    Setting::set('captcha.custom_case_sensitive', false);
    Setting::set('captcha.custom_expire_minutes', 5);
    Setting::set('captcha.custom_max_attempts', 3);
});

test('it returns client specifications for multi-platform bootstrap via api', function () {
    $response = $this->getJson(route('api.v1.captcha.specs'));

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.active_driver', 'custom')
        ->assertJsonPath('data.supported_drivers', ['custom', 'google'])
        ->assertJsonStructure([
            'status',
            'data' => [
                'enabled',
                'active_driver',
                'supported_drivers',
                'protected_actions' => ['login', 'register', 'forgot_password', 'contact_us', 'api'],
                'google' => ['version', 'site_key', 'domain', 'score_threshold'],
                'custom' => ['char_type', 'length', 'case_sensitive', 'expire_minutes', 'max_attempts', 'audio_supported'],
                'client_endpoints' => ['specs', 'generate', 'verify', 'refresh', 'audio', 'status', 'diagnostics'],
                'environment',
                'dev_autofill_enabled',
            ],
        ]);
});

test('it generates dynamic challenge with anti-ocr svg and audio url', function () {
    $response = $this->getJson(route('api.v1.captcha.generate', ['action' => 'login']));

    $response->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('driver', 'custom')
        ->assertJsonStructure([
            'enabled',
            'driver',
            'token',
            'image',
            'raw_svg',
            'speech',
            'audio_url',
            'type',
            'expires_at',
            'autofill_answer',
        ]);

    $data = $response->json();
    expect($data['token'])->not->toBeEmpty();
    expect($data['image'])->toStartWith('data:image/svg+xml;base64,');
    expect($data['audio_url'])->toContain('/api/v1/captcha/audio/'.$data['token']);
    expect(Cache::has("captcha_challenge:{$data['token']}"))->toBeTrue();
});

test('it verifies custom captcha challenge via json payload', function () {
    $gen = $this->getJson(route('api.v1.captcha.generate', ['action' => 'api']))->json();
    $token = $gen['token'];
    $answer = $gen['autofill_answer'];

    // Successful verification
    $verifyRes = $this->postJson(route('api.v1.captcha.verify'), [
        'action' => 'api',
        'token' => $token,
        'answer' => $answer,
    ]);

    $verifyRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('driver', 'custom');

    // Token must be single-use and evicted from cache
    expect(Cache::has("captcha_challenge:{$token}"))->toBeFalse();

    // Verify telemetry logged
    expect(CaptchaLog::where('action', 'api')->where('status', 'passed')->exists())->toBeTrue();
});

test('it verifies challenge using headers for headless api clients', function () {
    $gen = $this->getJson(route('api.v1.captcha.generate', ['action' => 'api']))->json();
    $token = $gen['token'];
    $answer = $gen['autofill_answer'];

    $verifyRes = $this->postJson(
        route('api.v1.captcha.verify'),
        ['action' => 'api'],
        [
            'X-Captcha-Token' => $token,
            'X-Captcha-Answer' => $answer,
        ]
    );

    $verifyRes->assertOk()->assertJsonPath('success', true);
});

test('it fails verification with 422 for invalid answer', function () {
    $gen = $this->getJson(route('api.v1.captcha.generate', ['action' => 'api']))->json();
    $token = $gen['token'];

    $verifyRes = $this->postJson(route('api.v1.captcha.verify'), [
        'action' => 'api',
        'token' => $token,
        'answer' => 'INCORRECT_ANSWER',
    ]);

    $verifyRes->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'mismatched-answer');
});

test('it invalidates old token and refreshes challenge in atomic round-trip', function () {
    $gen1 = $this->getJson(route('api.v1.captcha.generate', ['action' => 'login']))->json();
    $oldToken = $gen1['token'];
    expect(Cache::has("captcha_challenge:{$oldToken}"))->toBeTrue();

    $refreshRes = $this->postJson(route('api.v1.captcha.refresh'), [
        'action' => 'login',
        'old_token' => $oldToken,
    ]);

    $refreshRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('enabled', true)
        ->assertJsonStructure(['success', 'enabled', 'driver', 'token', 'image', 'speech', 'audio_url', 'expires_at']);

    $newToken = $refreshRes->json('token');
    expect($newToken)->not->toBe($oldToken);

    // Old token should be revoked from cache
    expect(Cache::has("captcha_challenge:{$oldToken}"))->toBeFalse();
    // New token exists in cache
    expect(Cache::has("captcha_challenge:{$newToken}"))->toBeTrue();
});

test('it streams 16-bit pcm wav audio for accessibility and mobile players', function () {
    $token = 'test-audio-token-123';
    Cache::put("captcha_challenge:{$token}", [
        'answer' => Crypt::encryptString('7B9KX'),
        'display' => '7B9KX',
        'speech' => '7 B 9 K X',
        'attempts' => 0,
        'max_attempts' => 3,
        'case_sensitive' => false,
        'char_type' => 'alphanumeric',
    ], now()->addMinutes(5));

    // 1. Binary streaming request
    $binaryRes = $this->get(route('api.v1.captcha.audio', ['token' => $token]));
    $binaryRes->assertOk();
    $binaryRes->assertHeader('Content-Type', 'audio/wav');
    $content = $binaryRes->getContent();

    // Verify WAV RIFF header
    expect(substr($content, 0, 4))->toBe('RIFF');
    expect(substr($content, 8, 4))->toBe('WAVE');
    expect(substr($content, 12, 4))->toBe('fmt ');
    expect(strlen($content))->toBeGreaterThan(100);

    // 2. JSON format request
    $jsonRes = $this->getJson(route('api.v1.captcha.audio', ['token' => $token, 'format' => 'json']));
    $jsonRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('token', $token)
        ->assertJsonPath('speech', '7 B 9 K X')
        ->assertJsonPath('mime_type', 'audio/wav');
    expect($jsonRes->json('audio_base64'))->toStartWith('data:audio/wav;base64,');
});

test('it returns 404 for expired or non-existent audio token', function () {
    $res = $this->get(route('api.v1.captcha.audio', ['token' => 'non-existent-token']));
    $res->assertNotFound();

    $jsonRes = $this->getJson(route('api.v1.captcha.audio', ['token' => 'non-existent-token', 'format' => 'json']));
    $jsonRes->assertNotFound()->assertJsonPath('success', false);
});

test('it provides administrative diagnostics and health probe', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin, 'sanctum');

    $response = $this->getJson(route('api.v1.captcha.diagnostics'));

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('diagnostics.driver_healthy', true)
        ->assertJsonPath('diagnostics.cache_healthy', true)
        ->assertJsonStructure([
            'status',
            'diagnostics' => ['active_driver', 'driver_healthy', 'generation_latency_ms', 'cache_store', 'cache_healthy', 'total_diagnostics_time_ms'],
            'statistics',
            'recent_telemetry',
            'configurations',
        ]);
});

test('it prunes old captcha verification logs within database transaction', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin, 'sanctum');

    // Seed test logs
    $oldLog = CaptchaLog::create([
        'session_id' => 'old_session',
        'driver' => 'custom',
        'action' => 'login',
        'status' => 'passed',
    ]);
    $oldLog->created_at = Carbon::now()->subDays(45);
    $oldLog->saveQuietly();

    $recentLog = CaptchaLog::create([
        'session_id' => 'recent_session',
        'driver' => 'custom',
        'action' => 'login',
        'status' => 'passed',
    ]);
    $recentLog->created_at = Carbon::now()->subDays(5);
    $recentLog->saveQuietly();

    $pruneRes = $this->postJson(route('api.v1.captcha.logs.prune'), [
        'days' => 30,
        'force' => false,
    ]);

    $pruneRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('pruned_count', 1);

    expect(CaptchaLog::where('session_id', 'old_session')->exists())->toBeFalse();
    expect(CaptchaLog::where('session_id', 'recent_session')->exists())->toBeTrue();
});

test('it integrates captcha module seamlessly inside developer console and docs', function () {
    RbacService::seedDefaults();
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.api-keys.docs')
        ->assertOk()
        ->assertSee('Bot Defense &amp; CAPTCHA', false)
        ->assertSee('/api/v1/captcha/specs')
        ->assertSee('/api/v1/captcha/generate')
        ->assertSee('/api/v1/captcha/verify')
        // Test selecting preset
        ->call('selectPreset', 'GET', '/api/v1/captcha/specs')
        ->assertSet('sandboxMethod', 'GET')
        ->assertSet('sandboxEndpoint', '/api/v1/captcha/specs')
        // Test executing sandbox request
        ->call('executeSandboxRequest')
        ->assertSet('sandboxStatusCode', 200)
        // Test opening the integration guide modal
        ->call('openGuide', 'captcha')
        ->assertSet('showGuideModal', true)
        ->assertSet('guideTitle', 'Enterprise CAPTCHA & Bot Defense REST API — Multi-Platform Integration Guide')
        ->assertSee('Enterprise CAPTCHA &amp; Bot Defense REST API Integration Guide', false)
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});
