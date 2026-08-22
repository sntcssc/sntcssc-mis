<?php

use App\Http\Controllers\LocaleController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::view('verify-otp', 'pages.auth.verify-otp')->name('verify-otp');

Route::post('locale', LocaleController::class)->name('locale.switch');

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
        Route::livewire('system/settings', 'pages::admin.settings')->name('admin.settings.index');
        Route::livewire('system/settings/general', 'pages::admin.settings.general')->name('admin.settings.general');
        Route::livewire('system/settings/seo', 'pages::admin.settings.seo')->name('admin.settings.seo');
        Route::livewire('system/settings/appearance', 'pages::admin.settings.appearance')->name('admin.settings.appearance');
        Route::livewire('system/settings/email', 'pages::admin.settings.email')->name('admin.settings.email');
        Route::livewire('system/settings/localization', 'pages::admin.settings.localization')->name('admin.settings.localization');
        Route::livewire('system/settings/payment', 'pages::admin.settings.payment')->name('admin.settings.payment');
        Route::livewire('system/settings/sms', 'pages::admin.settings.sms')->name('admin.settings.sms');
        Route::livewire('system/settings/system', 'pages::admin.settings.system')->name('admin.settings.system');
        Route::livewire('roles', 'pages::admin.roles')->name('admin.roles.index');
        Route::livewire('reports', 'pages::admin.reports')->name('admin.reports.index');
        Route::livewire('reports/saved', 'pages::admin.reports-saved')->name('admin.reports.saved');
        Route::livewire('profile', 'pages::admin.profile')->name('admin.profile.show');
    });
