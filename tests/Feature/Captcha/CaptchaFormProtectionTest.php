<?php

use App\Models\ContactDepartment;
use App\Models\ContactSubject;
use App\Models\Setting;
use App\Models\User;
use App\Services\Captcha\Drivers\CustomCaptchaDriver;
use App\Services\RbacService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    Setting::flushCache();
    Cache::flush();
});

test('public captcha generate API returns challenge payload when enabled', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');

    $response = $this->getJson(route('api.v1.captcha.generate'));

    $response->assertOk()
        ->assertJson([
            'enabled' => true,
            'driver' => 'custom',
        ])
        ->assertJsonStructure(['token', 'image', 'speech', 'type', 'expires_at']);
});

test('public captcha verify API validates answer correctly', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');

    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    $token = $challenge['token'];

    $cached = Cache::get("captcha_challenge:{$token}");
    $answer = Crypt::decryptString($cached['answer']);

    // Wrong answer
    $this->postJson(route('api.v1.captcha.verify'), [
        'captcha_token' => $token,
        'captcha_answer' => 'wrong',
    ])->assertStatus(422)
        ->assertJson(['success' => false]);

    // Correct answer
    $this->postJson(route('api.v1.captcha.verify'), [
        'captcha_token' => $token,
        'captcha_answer' => $answer,
    ])->assertOk()
        ->assertJson(['success' => true]);
});

test('public captcha status API returns accurate state', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.on_login', true);

    $this->getJson(route('api.v1.captcha.status'))
        ->assertOk()
        ->assertJson([
            'enabled' => true,
            'active_driver' => 'custom',
            'actions' => [
                'login' => true,
            ],
        ]);
});

test('login requires captcha verification when enabled for login', function () {
    $user = User::factory()->create([
        'email' => 'student.tester@example.com',
    ]);

    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.on_login', true);

    // Attempt login without captcha -> should fail with validation error
    $response = $this->post(route('login.store'), [
        'email' => 'student.tester@example.com',
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('captcha');
    $this->assertGuest();

    // Now generate valid captcha and attempt login
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    $cached = Cache::get("captcha_challenge:{$challenge['token']}");
    $answer = Crypt::decryptString($cached['answer']);

    $validResponse = $this->post(route('login.store'), [
        'email' => 'student.tester@example.com',
        'password' => 'password',
        'captcha_token' => $challenge['token'],
        'captcha_answer' => $answer,
    ]);

    $validResponse->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

test('contact form enforces captcha when enabled', function () {
    $department = ContactDepartment::create([
        'name' => 'General Enquiries',
        'code' => 'GEN-ENQ',
        'slug' => 'general-enquiries',
        'is_active' => true,
    ]);

    $subject = ContactSubject::create([
        'department_id' => $department->id,
        'name' => 'Admissions Inquiry',
        'code' => 'ADM-INQ',
        'slug' => 'admissions-inquiry',
        'is_active' => true,
    ]);

    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.on_contact', true);

    // Livewire submit without captcha -> fails
    Livewire::test('pages::public.contact-us')
        ->set('form.name', 'John Doe')
        ->set('form.email', 'john@example.com')
        ->set('form.mobile', '9876543210')
        ->set('form.department_id', $department->id)
        ->set('form.subject_id', $subject->id)
        ->set('form.message', 'This is an inquiry message about new batches.')
        ->call('submit')
        ->assertHasErrors(['captcha']);

    $this->assertDatabaseMissing('contact_submissions', [
        'email' => 'john@example.com',
    ]);

    // Livewire submit with valid captcha -> succeeds
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    $cached = Cache::get("captcha_challenge:{$challenge['token']}");
    $answer = Crypt::decryptString($cached['answer']);

    Livewire::test('pages::public.contact-us')
        ->set('form.name', 'John Doe')
        ->set('form.email', 'john@example.com')
        ->set('form.mobile', '9876543210')
        ->set('form.department_id', $department->id)
        ->set('form.subject_id', $subject->id)
        ->set('form.message', 'This is an inquiry message about new batches.')
        ->call('submit', $challenge['token'], $answer)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('contact_submissions', [
        'email' => 'john@example.com',
    ]);
});

test('custom captcha driver autofills answer in non-production environments', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');

    // In testing environment (app()->isProduction() === false)
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    expect($challenge)->toHaveKey('expected_answer');

    $html = $driver->render('general');
    expect($html)->toContain('Dev Autofill');

    $apiResponse = $this->getJson(route('api.v1.captcha.generate'));
    $apiResponse->assertOk()
        ->assertJsonStructure(['autofill_answer']);
});

test('login page renders captcha in both password and otp forms when enabled', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.on_login', true);

    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('id="otp-captcha-container"', false);
    $response->assertSee('name="otp_captcha_answer"', false);
    $response->assertSee('name="captcha_answer"', false);
});

test('otp login send endpoint enforces captcha validation when enabled for login', function () {
    $user = User::factory()->create([
        'email' => 'otp.student@example.com',
    ]);

    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.on_login', true);
    Setting::set('email.is_enabled', true);

    // 1. Send OTP without captcha -> must fail with 422 and captcha validation error
    $failResponse = $this->postJson(route('login.otp.send'), [
        'identifier' => 'otp.student@example.com',
    ]);

    $failResponse->assertStatus(422)
        ->assertJsonValidationErrors(['captcha']);

    // 2. Generate valid challenge
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    $cached = Cache::get("captcha_challenge:{$challenge['token']}");
    $answer = Crypt::decryptString($cached['answer']);

    // 3. Send OTP with valid captcha inputs -> must succeed
    $successResponse = $this->postJson(route('login.otp.send'), [
        'identifier' => 'otp.student@example.com',
        'otp_captcha_token' => $challenge['token'],
        'otp_captcha_answer' => $answer,
    ]);

    $successResponse->assertOk()
        ->assertJson(['success' => true]);
});

test('otp password reset send endpoint enforces captcha validation when enabled', function () {
    $user = User::factory()->create([
        'email' => 'reset.student@example.com',
    ]);

    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.on_forgot_password', true);
    Setting::set('email.is_enabled', true);

    // 1. Send reset OTP without captcha -> must fail with 422
    $failResponse = $this->postJson(route('password.otp.send'), [
        'identifier' => 'reset.student@example.com',
    ]);

    $failResponse->assertStatus(422)
        ->assertJsonValidationErrors(['captcha']);

    // 2. Generate valid challenge
    $driver = new CustomCaptchaDriver;
    $challenge = $driver->generateChallenge();
    $cached = Cache::get("captcha_challenge:{$challenge['token']}");
    $answer = Crypt::decryptString($cached['answer']);

    // 3. Send reset OTP with valid captcha inputs -> must succeed
    $successResponse = $this->postJson(route('password.otp.send'), [
        'identifier' => 'reset.student@example.com',
        'captcha_token' => $challenge['token'],
        'captcha_answer' => $answer,
    ]);

    $successResponse->assertOk()
        ->assertJson(['success' => true]);
});

test('forgot password page renders captcha in both otp and email reset flows when enabled', function () {
    Setting::set('captcha.enabled', true);
    Setting::set('captcha.driver', 'custom');
    Setting::set('captcha.on_forgot_password', true);

    $response = $this->get(route('password.request'));

    $response->assertOk();
    $response->assertSee('id="forgot-otp-captcha-container"', false);
    $response->assertSee('name="otp_captcha_answer"', false);
});
