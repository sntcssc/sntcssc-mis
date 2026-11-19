<?php

use App\Models\CaptchaLog;
use App\Models\Setting;
use App\Services\Captcha\CaptchaResult;
use App\Services\Captcha\CaptchaService;
use App\Services\Captcha\Drivers\CustomCaptchaDriver;
use App\Services\Captcha\Drivers\GoogleRecaptchaDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Setting::flushCache();
    Cache::flush();
});

test('CaptchaService is disabled by default', function () {
    Setting::set('captcha.enabled', false);

    expect(CaptchaService::isEnabled())->toBeFalse()
        ->and(CaptchaService::isEnabled('login'))->toBeFalse();

    $request = new Request;
    $result = CaptchaService::verifyRequest($request, 'login');

    expect($result->isSuccess())->toBeTrue();
});

test('CaptchaService respects per-action toggles when enabled', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.on_login', true);
    Setting::set('captcha.on_register', false);

    expect(CaptchaService::isEnabled('login'))->toBeTrue()
        ->and(CaptchaService::isEnabled('register'))->toBeFalse();
});

test('CustomCaptchaDriver generates challenge with valid token and image', function () {
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();

    expect($challenge)->toHaveKeys(['token', 'image', 'speech', 'type', 'expires_at'])
        ->and($challenge['token'])->toBeString()
        ->and($challenge['image'])->toStartWith('data:image/svg+xml;base64,');

    // Token stored in cache
    $cacheKey = "captcha_challenge:{$challenge['token']}";
    expect(Cache::has($cacheKey))->toBeTrue();
});

test('CustomCaptchaDriver supports different character modes', function () {
    $driver = new CustomCaptchaDriver;

    // Numbers mode
    $numChallenge = $driver->generateChallenge(['char_type' => 'numbers', 'length' => 4]);
    $cached = Cache::get("captcha_challenge:{$numChallenge['token']}");
    $answer = Crypt::decryptString($cached['answer']);
    expect(ctype_digit($answer))->toBeTrue();

    // Alphabets mode
    $alphaChallenge = $driver->generateChallenge(['char_type' => 'alphabets', 'length' => 5]);
    $cachedAlpha = Cache::get("captcha_challenge:{$alphaChallenge['token']}");
    $alphaAnswer = Crypt::decryptString($cachedAlpha['answer']);
    expect(ctype_alpha($alphaAnswer))->toBeTrue();

    // Math mode
    $mathChallenge = $driver->generateChallenge(['char_type' => 'math']);
    $cachedMath = Cache::get("captcha_challenge:{$mathChallenge['token']}");
    $mathAnswer = Crypt::decryptString($cachedMath['answer']);
    expect(is_numeric($mathAnswer))->toBeTrue();
});

test('CustomCaptchaDriver verifies correct answer and invalidates single-use token', function () {
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    $token = $challenge['token'];

    $cached = Cache::get("captcha_challenge:{$token}");
    $answer = Crypt::decryptString($cached['answer']);

    $request = new Request([
        'captcha_token' => $token,
        'captcha_answer' => $answer,
    ]);

    $result = $driver->verify($request, 'login');

    expect($result->isSuccess())->toBeTrue();
    // Replay attack prevention: token must be gone
    expect(Cache::has("captcha_challenge:{$token}"))->toBeFalse();
});

test('CustomCaptchaDriver rejects invalid answer and enforces max attempts', function () {
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    $token = $challenge['token'];

    $request = new Request([
        'captcha_token' => $token,
        'captcha_answer' => 'wrong-answer-xyz',
    ]);

    // Attempt 1
    $res1 = $driver->verify($request, 'login');
    expect($res1->isFailure())->toBeTrue()
        ->and($res1->errorCode)->toBe('mismatched-answer');

    // Attempt 2
    $res2 = $driver->verify($request, 'login');
    expect($res2->isFailure())->toBeTrue();

    // Attempt 3: max attempts reached, token invalidated
    $res3 = $driver->verify($request, 'login');
    expect($res3->isFailure())->toBeTrue()
        ->and($res3->errorCode)->toBe('max-attempts-exceeded')
        ->and(Cache::has("captcha_challenge:{$token}"))->toBeFalse();
});

test('CustomCaptchaDriver rejects expired tokens', function () {
    $driver = new CustomCaptchaDriver;
    $request = new Request([
        'captcha_token' => 'non-existent-uuid',
        'captcha_answer' => '12345',
    ]);

    $result = $driver->verify($request, 'login');

    expect($result->isFailure())->toBeTrue()
        ->and($result->errorCode)->toBe('expired-token');
});

test('GoogleRecaptchaDriver verifies response via Google siteverify API', function () {
    Setting::set('captcha.google_site_key', 'test-site-key');
    Setting::set('captcha.google_secret_key', 'test-secret-key');
    Setting::set('captcha.google_version', 'v2_checkbox');

    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'challenge_ts' => now()->toIso8601String(),
            'hostname' => 'localhost',
        ], 200),
    ]);

    $driver = new GoogleRecaptchaDriver;
    $request = new Request([
        'g-recaptcha-response' => 'mock-google-token',
    ]);

    $result = $driver->verify($request, 'login');

    expect($result->isSuccess())->toBeTrue()
        ->and($result->score)->toBe(1.0);
});

test('GoogleRecaptchaDriver enforces score threshold on v3', function () {
    Setting::set('captcha.google_site_key', 'test-site-key');
    Setting::set('captcha.google_secret_key', 'test-secret-key');
    Setting::set('captcha.google_version', 'v3');
    Setting::set('captcha.google_score_threshold', 0.6);

    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'score' => 0.2, // bot score
            'action' => 'login',
        ], 200),
    ]);

    $driver = new GoogleRecaptchaDriver;
    $request = new Request([
        'g-recaptcha-response' => 'low-score-token',
    ]);

    $result = $driver->verify($request, 'login');

    expect($result->isFailure())->toBeTrue()
        ->and($result->errorCode)->toBe('score-below-threshold');
});

test('CaptchaService records verification logs and calculates statistics', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');

    $log1 = CaptchaService::recordLog(
        driver: 'custom',
        action: 'login',
        result: CaptchaResult::success('Passed', 1.0),
        request: Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
        responseTimeMs: 25
    );

    $log2 = CaptchaService::recordLog(
        driver: 'custom',
        action: 'login',
        result: CaptchaResult::failure('Failed code', 'mismatched-answer'),
        request: Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
        responseTimeMs: 30
    );

    expect($log1)->toBeInstanceOf(CaptchaLog::class)
        ->and($log1->status)->toBe('passed')
        ->and($log2->status)->toBe('failed');

    $stats = CaptchaService::getStatistics();

    expect($stats['today_total'])->toBeGreaterThanOrEqual(2)
        ->and($stats['today_passed'])->toBeGreaterThanOrEqual(1)
        ->and($stats['today_failed'])->toBeGreaterThanOrEqual(1)
        ->and($stats['active_driver'])->toBe('custom');
});
