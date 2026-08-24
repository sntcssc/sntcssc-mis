<?php

use App\Models\OtpCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\SmsService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Ensure settings are clean and populated
    Setting::set('sms.enabled', true);
    Setting::set('sms.driver', 'log');
    Setting::set('sms.default_country_code', '+91');
    Setting::set('sms.otp_length', 6);
    Setting::set('sms.otp_expiry_minutes', 5);

    Setting::set('email.is_enabled', true);
    Setting::set('email.driver', 'log');
    Setting::set('email.from_address', 'noreply@sntcssc.in');
    Setting::set('email.from_name', 'SNT CSSC MIS');

    // Ensure templates exist
    (new MessageTemplateSeeder)->run();
});

test('sms service normalizes phone numbers correctly', function () {
    $smsService = app(SmsService::class);

    expect($smsService->normalizePhoneNumber('9876543210'))->toBe('+919876543210')
        ->and($smsService->normalizePhoneNumber('+919876543210'))->toBe('+919876543210')
        ->and($smsService->normalizePhoneNumber('09876543210'))->toBe('+919876543210');
});

test('sms service respects enabled toggle', function () {
    $smsService = app(SmsService::class);

    Setting::set('sms.enabled', true);
    expect($smsService->send('+919876543210', 'Test message'))->toBeTrue();

    Setting::set('sms.enabled', false);
    expect($smsService->send('+919876543210', 'Test message'))->toBeFalse();
});

test('email service respects enabled toggle', function () {
    $emailService = app(EmailService::class);

    Setting::set('email.is_enabled', true);
    expect($emailService->send('test@example.com', 'Test Subject', '<p>Hello</p>'))->toBeTrue();

    Setting::set('email.is_enabled', false);
    expect($emailService->send('test@example.com', 'Test Subject', '<p>Hello</p>'))->toBeFalse();
});

test('otp service generates, hashes and verifies valid OTP codes', function () {
    $otpService = app(OtpService::class);
    $phone = '+919876543210';

    $result = $otpService->generateAndSend($phone, OtpCode::TYPE_LOGIN_SMS);

    expect($result['success'])->toBeTrue()
        ->and($result['otp_id'])->not->toBeNull();

    $otp = OtpCode::find($result['otp_id']);
    expect($otp)->not->toBeNull()
        ->and($otp->identifier)->toBe($phone)
        ->and($otp->verified_at)->toBeNull()
        ->and(Hash::check($result['plain_code'], $otp->code_hash))->toBeTrue();

    // Verify with correct plain code
    $verifyResult = $otpService->verify($phone, $result['plain_code'], OtpCode::TYPE_LOGIN_SMS);
    expect($verifyResult['success'])->toBeTrue();

    $otp->refresh();
    expect($otp->verified_at)->not->toBeNull();
});

test('otp service rejects invalid code and enforces cooldown', function () {
    $otpService = app(OtpService::class);
    $email = 'student@example.com';

    $result = $otpService->generateAndSend($email, OtpCode::TYPE_LOGIN_EMAIL);
    expect($result['success'])->toBeTrue();

    // Attempt verify with wrong code
    $wrongVerify = $otpService->verify($email, '000000', OtpCode::TYPE_LOGIN_EMAIL);
    expect($wrongVerify['success'])->toBeFalse();

    // Attempt generate immediately (should hit cooldown)
    $cooldownResult = $otpService->generateAndSend($email, OtpCode::TYPE_LOGIN_EMAIL);
    expect($cooldownResult['success'])->toBeFalse()
        ->and($cooldownResult['cooldown_seconds'])->toBeGreaterThan(0);
});

test('users can register with mobile number and receive initial OTP', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Aditi Sen',
        'email' => 'aditi@example.com',
        'phone' => '+919876543211',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'aditi@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->phone)->toBe('+919876543211')
        ->and($user->name)->toBe('Aditi Sen');

    $otp = OtpCode::where('identifier', '+919876543211')
        ->where('type', OtpCode::TYPE_REGISTRATION_SMS)
        ->first();

    expect($otp)->not->toBeNull();
});

test('users can authenticate with mobile number and password', function () {
    $user = User::factory()->create([
        'phone' => '+919830012345',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->post(route('login.store'), [
        'email' => '+919830012345',
        'password' => 'password123',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('users can request and verify login OTP via email', function () {
    $user = User::factory()->create([
        'email' => 'login_user@example.com',
    ]);

    // Request Login OTP
    $sendResponse = $this->postJson(route('login.otp.send'), [
        'identifier' => 'login_user@example.com',
    ]);

    $sendResponse->assertOk()
        ->assertJson(['success' => true]);

    $otp = OtpCode::where('identifier', 'login_user@example.com')
        ->where('type', OtpCode::TYPE_LOGIN_EMAIL)
        ->latest('id')
        ->first();

    expect($otp)->not->toBeNull();

    $plainCode = $sendResponse->json('plain_code');

    // Verify OTP and Login
    $verifyResponse = $this->postJson(route('login.otp.verify'), [
        'identifier' => 'login_user@example.com',
        'code' => $plainCode,
    ]);

    $verifyResponse->assertOk()
        ->assertJson(['success' => true]);

    $this->assertAuthenticatedAs($user);
});

test('users can request and verify login OTP via mobile SMS', function () {
    $user = User::factory()->create([
        'phone' => '+919876500000',
    ]);

    // Request Login OTP
    $sendResponse = $this->postJson(route('login.otp.send'), [
        'identifier' => '+919876500000',
    ]);

    $sendResponse->assertOk()
        ->assertJson(['success' => true]);

    $plainCode = $sendResponse->json('plain_code');

    // Verify OTP and Login
    $verifyResponse = $this->postJson(route('login.otp.verify'), [
        'identifier' => '+919876500000',
        'code' => $plainCode,
    ]);

    $verifyResponse->assertOk()
        ->assertJson(['success' => true]);

    $this->assertAuthenticatedAs($user);
});

test('login otp is rejected gracefully when sms gateway is disabled in settings', function () {
    Setting::set('sms.enabled', false);

    $user = User::factory()->create([
        'phone' => '+919876599999',
    ]);

    $sendResponse = $this->postJson(route('login.otp.send'), [
        'identifier' => '+919876599999',
    ]);

    $sendResponse->assertStatus(422)
        ->assertJsonValidationErrors(['identifier']);
});

test('unverified mobile user is redirected to verify-otp page', function () {
    $user = User::factory()->create([
        'phone' => '+919876511111',
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);

    Setting::set('sms.enabled', true);

    $response = $this->actingAs($user)->get(route('dashboard', ['current_team' => $user->personalTeam()->slug]));

    $response->assertRedirect(route('verify-otp', ['type' => 'phone']));
});

test('unverified user can verify phone number with OTP', function () {
    $user = User::factory()->create([
        'phone' => '+919876522222',
        'phone_verified_at' => null,
    ]);

    $otpService = app(OtpService::class);
    $result = $otpService->generateAndSend($user->phone, OtpCode::TYPE_VERIFY_PHONE, $user);

    $verifyResponse = $this->actingAs($user)->postJson(route('verify-otp.verify'), [
        'type' => 'phone',
        'identifier' => $user->phone,
        'code' => $result['plain_code'],
    ]);

    $verifyResponse->assertOk()
        ->assertJson(['success' => true]);

    $user->refresh();
    expect($user->hasVerifiedPhone())->toBeTrue();
});

test('user can reset password using OTP', function () {
    $user = User::factory()->create([
        'phone' => '+919876533333',
        'password' => Hash::make('old-password'),
    ]);

    $sendResponse = $this->postJson(route('password.otp.send'), [
        'identifier' => '+919876533333',
    ]);

    $sendResponse->assertOk()
        ->assertJson(['success' => true]);

    $plainCode = $sendResponse->json('plain_code');

    $resetResponse = $this->postJson(route('password.otp.reset'), [
        'identifier' => '+919876533333',
        'code' => $plainCode,
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ]);

    $resetResponse->assertOk()
        ->assertJson(['success' => true]);

    $user->refresh();
    expect(Hash::check('NewSecurePassword123!', $user->password))->toBeTrue();
});
