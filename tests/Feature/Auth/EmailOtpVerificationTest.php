<?php

use App\Models\OtpCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\RbacService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
});

test('new user registration dispatches email OTP when OTP mode is active', function () {
    Setting::set('email.is_enabled', '1');
    Setting::set('email.verification_mode', 'otp');

    $response = $this->post(route('register.store'), [
        'name' => 'OTP User',
        'email' => 'otpuser@example.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
    ]);

    $user = User::where('email', 'otpuser@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();

    $otp = OtpCode::where('identifier', 'otpuser@example.com')
        ->whereIn('type', [OtpCode::TYPE_REGISTRATION_EMAIL, OtpCode::TYPE_VERIFY_EMAIL])
        ->first();

    expect($otp)->not->toBeNull();
});

test('new user registration dispatches link notification when Link mode is active', function () {
    Notification::fake();

    Setting::set('email.is_enabled', '1');
    Setting::set('email.verification_mode', 'link');

    $response = $this->post(route('register.store'), [
        'name' => 'Link User',
        'email' => 'linkuser@example.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
    ]);

    $user = User::where('email', 'linkuser@example.com')->first();
    expect($user)->not->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('unverified user is redirected to verify-otp when OTP mode is enabled', function () {
    Setting::set('email.verification_mode', 'otp');

    $user = User::factory()->unverified()->create();
    $team = $user->personalTeam();

    $response = $this->actingAs($user)->get("/{$team->slug}/dashboard");

    $response->assertRedirect(route('verify-otp', ['type' => 'email']));
});

test('unverified user is redirected to verification.notice when Link mode is enabled', function () {
    Setting::set('email.verification_mode', 'link');

    $user = User::factory()->unverified()->create();
    $team = $user->personalTeam();

    $response = $this->actingAs($user)->get("/{$team->slug}/dashboard");

    $response->assertRedirect(route('verification.notice'));
});

test('email can be verified with valid OTP code', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'testverify@example.com',
    ]);

    /** @var OtpService $otpService */
    $otpService = app(OtpService::class);
    $sendResult = $otpService->generateAndSend(
        identifier: 'testverify@example.com',
        type: OtpCode::TYPE_VERIFY_EMAIL,
        user: $user
    );

    expect($sendResult['success'])->toBeTrue();
    $plainCode = $sendResult['plain_code'];
    expect($plainCode)->not->toBeNull();

    $response = $this->actingAs($user)->postJson(route('verify-otp.verify'), [
        'type' => 'email',
        'identifier' => 'testverify@example.com',
        'code' => $plainCode,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('email verification fails with invalid OTP code', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'wrongcode@example.com',
    ]);

    /** @var OtpService $otpService */
    $otpService = app(OtpService::class);
    $otpService->generateAndSend(
        identifier: 'wrongcode@example.com',
        type: OtpCode::TYPE_VERIFY_EMAIL,
        user: $user
    );

    $response = $this->actingAs($user)->postJson(route('verify-otp.verify'), [
        'type' => 'email',
        'identifier' => 'wrongcode@example.com',
        'code' => '999999',
    ]);

    $response->assertStatus(422);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('admin can update email verification mode in email settings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.email')
        ->set('form.verification_mode', 'link')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('email.verification_mode'))->toBe('link');
    expect(EmailService::getVerificationMode())->toBe('link');
    expect(EmailService::isOtpVerification())->toBeFalse();

    Livewire::actingAs($admin)
        ->test('pages::admin.settings.email')
        ->set('form.verification_mode', 'otp')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('email.verification_mode'))->toBe('otp');
    expect(EmailService::getVerificationMode())->toBe('otp');
    expect(EmailService::isOtpVerification())->toBeTrue();
});
