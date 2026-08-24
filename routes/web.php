<?php

use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\OtpPasswordResetController;
use App\Http\Controllers\Auth\OtpVerificationController;
use App\Http\Controllers\LocaleController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::view('verify-otp', 'pages.auth.verify-otp')->name('verify-otp');

// OTP Authentication & Verification routes
Route::post('login/otp/send', [OtpLoginController::class, 'sendOtp'])->name('login.otp.send');
Route::post('login/otp/verify', [OtpLoginController::class, 'verifyOtp'])->name('login.otp.verify');

Route::post('verify-otp/send', [OtpVerificationController::class, 'sendOtp'])->name('verify-otp.send');
Route::post('verify-otp/verify', [OtpVerificationController::class, 'verifyOtp'])->name('verify-otp.verify');

Route::post('forgot-password/otp/send', [OtpPasswordResetController::class, 'sendOtp'])->name('password.otp.send');
Route::post('forgot-password/otp/reset', [OtpPasswordResetController::class, 'verifyAndReset'])->name('password.otp.reset');

Route::post('locale', LocaleController::class)->name('locale.switch');

// Public Contact Us & Dynamic Pages routes
Route::livewire('contact', 'pages::public.contact-us')->name('public.contact');
Route::livewire('contact-us', 'pages::public.contact-us')->name('public.contact-us');
Route::livewire('pages/{slug}', 'pages::public.page-view')->name('public.page');

// Standard Direct Policy Route Aliases
foreach (['about-us', 'privacy-policy', 'terms-and-conditions', 'refund-and-cancellation-policy', 'legal-disclaimer', 'copyright-policy', 'hyperlink-policy'] as $policySlug) {
    Route::livewire($policySlug, 'pages::public.page-view', ['slug' => $policySlug])->name("public.page.{$policySlug}");
}

// Settings routes must be registered before the {current_team} group so that
// paths like /settings/profile are not captured by /{current_team}/profile.
require __DIR__.'/settings.php';

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::livewire('students', 'pages::admin.students')->name('admin.students.index');
        Route::livewire('admissions', 'pages::admin.admissions')->name('admin.admissions.index');
        Route::livewire('enrollments', 'pages::admin.enrollments')->name('admin.enrollments.index');
        Route::livewire('courses', 'pages::admin.courses')->name('admin.courses.index');
        Route::livewire('batches', 'pages::admin.batches')->name('admin.batches.index');
        Route::livewire('tests', 'pages::admin.tests')->name('admin.tests.index');
        Route::livewire('users', 'pages::admin.users')->name('admin.users.index');
        Route::livewire('content/pages', 'pages::admin.pages')->name('admin.pages.index');
        Route::livewire('support/contacts', 'pages::admin.contacts')->name('admin.contacts.index');
        Route::livewire('system/settings', 'pages::admin.settings')->name('admin.settings.index');
        Route::livewire('system/settings/general', 'pages::admin.settings.general')->name('admin.settings.general');
        Route::livewire('system/settings/seo', 'pages::admin.settings.seo')->name('admin.settings.seo');
        Route::livewire('system/settings/appearance', 'pages::admin.settings.appearance')->name('admin.settings.appearance');
        Route::livewire('system/settings/email', 'pages::admin.settings.email')->name('admin.settings.email');
        Route::livewire('system/settings/localization', 'pages::admin.settings.localization')->name('admin.settings.localization');
        Route::livewire('system/settings/payment', 'pages::admin.settings.payment')->name('admin.settings.payment');
        Route::livewire('system/settings/sms', 'pages::admin.settings.sms')->name('admin.settings.sms');
        Route::livewire('system/settings/system', 'pages::admin.settings.system')->name('admin.settings.system');
        Route::livewire('communications/logs', 'pages::admin.communications.logs')->name('admin.communications.logs');
        Route::livewire('communications/compose', 'pages::admin.communications.compose')->name('admin.communications.compose');
        Route::livewire('system/templates/sms', 'pages::admin.templates.sms-templates')->name('admin.sms-templates.index');
        Route::livewire('system/templates/email', 'pages::admin.templates.email-templates')->name('admin.email-templates.index');
        Route::livewire('system/cron-jobs', 'pages::admin.system.cron-jobs')->name('admin.cron-jobs.index');
        Route::livewire('system/audit-logs', 'pages::admin.audit-logs')->name('admin.audit-logs.index');
        Route::livewire('roles', 'pages::admin.roles')->name('admin.roles.index');
        Route::livewire('reports', 'pages::admin.reports')->name('admin.reports.index');
        Route::livewire('reports/saved', 'pages::admin.reports-saved')->name('admin.reports.saved');
        Route::livewire('profile', 'pages::admin.profile')->name('admin.profile.show');
    });
