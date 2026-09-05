<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\OtpPasswordResetController;
use App\Http\Controllers\Auth\OtpVerificationController;
use App\Http\Controllers\ChatJoinController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MeetingJoinController;
use App\Http\Controllers\MeetingSignalController;
use App\Http\Controllers\Public\UnsubscribeController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Top-level /dashboard fallback route to redirect to the user's active team dashboard
Route::get('dashboard', function (Request $request) {
    $user = $request->user();
    if (! $user) {
        return redirect()->route('login');
    }

    $team = $user->currentTeam ?? $user->personalTeam() ?? $user->allTeams()->first();
    if ($team) {
        return redirect()->route('dashboard', ['current_team' => $team->slug]);
    }

    return redirect('/');
})->middleware(['auth', 'verified'])->name('dashboard.redirect');

Route::view('verify-otp', 'pages.auth.verify-otp')->name('verify-otp');

// Impersonation routes
Route::middleware(['auth'])->group(function () {
    Route::post('admin/impersonate/leave', [ImpersonationController::class, 'leave'])->name('admin.impersonate.leave');
    Route::post('admin/impersonate/{user}', [ImpersonationController::class, 'impersonate'])->name('admin.impersonate');
});

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

// Public 1-Click Unsubscribe Routes
Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe.show');
Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'process'])->name('unsubscribe.process');

// Settings routes must be registered before the {current_team} group so that
// paths like /settings/profile are not captured by /{current_team}/profile.
require __DIR__.'/settings.php';

// Public & Direct Chat & Meeting Join via Invite Code
Route::get('live-chat/join/{code}', [ChatJoinController::class, 'join'])->name('chat.join')->middleware(['auth']);
Route::get('meetings/join/{code}', [MeetingJoinController::class, 'join'])->name('meetings.join')->middleware(['auth']);
Route::post('meetings/{uuid}/signal', [MeetingSignalController::class, 'signal'])->name('meetings.signal.direct')->middleware(['auth']);
Route::get('meetings/{uuid}/sync', [MeetingSignalController::class, 'sync'])->name('meetings.sync.direct')->middleware(['auth']);

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::livewire('students', 'pages::admin.students')->name('admin.students.index')->middleware('can:students.view');
        Route::livewire('admissions', 'pages::admin.admissions')->name('admin.admissions.index')->middleware('can:admissions.view');
        Route::livewire('enrollments', 'pages::admin.enrollments')->name('admin.enrollments.index')->middleware('can:students.view');
        Route::livewire('courses', 'pages::admin.courses')->name('admin.courses.index')->middleware('can:courses.manage');
        Route::livewire('batches', 'pages::admin.batches')->name('admin.batches.index')->middleware('can:batches.manage');
        Route::livewire('tests', 'pages::admin.tests')->name('admin.tests.index')->middleware('can:tests.manage');
        Route::livewire('users', 'pages::admin.users')->name('admin.users.index')->middleware('can:users.view');
        Route::livewire('content/pages', 'pages::admin.pages')->name('admin.pages.index')->middleware('can:pages.manage');

        // User Support Ticket Portal
        Route::livewire('tickets', 'pages::portal.tickets')->name('tickets.index');
        Route::livewire('tickets/create', 'pages::portal.tickets.create')->name('tickets.create');
        Route::livewire('tickets/{ticket}', 'pages::portal.tickets.show')->name('tickets.show');

        // User Notification Center & Inbox
        Route::livewire('notifications', 'pages::portal.notifications')->name('notifications.index');

        // Realtime Live Chat & Channels Portal
        Route::livewire('chat', 'pages::portal.chat')->name('admin.chat.index');
        Route::livewire('live-chat', 'pages::portal.chat')->name('chat.index');

        // Dedicated Online Meetings & Video Conferencing
        Route::livewire('meetings', 'pages::portal.meetings')->name('meetings.index');
        Route::livewire('meetings/room/{uuid}', 'pages::portal.meeting-room')->name('meetings.room');
        Route::post('meetings/{uuid}/signal', [MeetingSignalController::class, 'signal'])->name('meetings.signal');
        Route::get('meetings/{uuid}/sync', [MeetingSignalController::class, 'sync'])->name('meetings.sync');

        // Admin Helpdesk Desk & Ticket Management
        Route::livewire('support/tickets', 'pages::admin.tickets')->name('admin.tickets.index')->middleware('can:tickets.view');
        Route::livewire('support/tickets/{ticket}', 'pages::admin.tickets.show')->name('admin.tickets.show')->middleware('can:tickets.view');
        Route::livewire('support/ticket-categories', 'pages::admin.tickets.categories')->name('admin.tickets.categories')->middleware('can:tickets.categories');
        Route::livewire('support/canned-responses', 'pages::admin.tickets.canned-responses')->name('admin.tickets.canned-responses')->middleware('can:tickets.canned_responses');
        Route::livewire('support/contacts', 'pages::admin.contacts')->name('admin.contacts.index')->middleware('can:contacts.manage');
        Route::livewire('system/settings', 'pages::admin.settings')->name('admin.settings.index')->middleware('can:settings.general');
        Route::livewire('system/settings/general', 'pages::admin.settings.general')->name('admin.settings.general')->middleware('can:settings.general');
        Route::livewire('system/settings/seo', 'pages::admin.settings.seo')->name('admin.settings.seo')->middleware('can:settings.general');
        Route::livewire('system/settings/appearance', 'pages::admin.settings.appearance')->name('admin.settings.appearance')->middleware('can:settings.appearance');
        Route::livewire('system/settings/email', 'pages::admin.settings.email')->name('admin.settings.email')->middleware('can:settings.email');
        Route::livewire('system/settings/localization', 'pages::admin.settings.localization')->name('admin.settings.localization')->middleware('can:settings.localization');
        Route::livewire('system/settings/payment', 'pages::admin.settings.payment')->name('admin.settings.payment')->middleware('can:settings.payment');
        Route::livewire('system/settings/sms', 'pages::admin.settings.sms')->name('admin.settings.sms')->middleware('can:settings.sms');
        Route::livewire('system/settings/notification', 'pages::admin.settings.notification')->name('admin.settings.notification')->middleware('can:settings.general');
        Route::livewire('system/settings/chat', 'pages::admin.settings.chat')->name('admin.settings.chat')->middleware('can:settings.general');
        Route::livewire('system/settings/meetings', 'pages::admin.settings.meetings')->name('admin.settings.meetings')->middleware('can:settings.general');
        Route::livewire('system/settings/system', 'pages::admin.settings.system')->name('admin.settings.system')->middleware('can:settings.general');
        Route::livewire('system/settings/backup', 'pages::admin.settings.backup')->name('admin.settings.backup')->middleware('can:settings.backup');

        Route::livewire('system/backups', 'pages::admin.settings.backup')->name('admin.backups.index')->middleware('can:settings.backup');
        Route::livewire('communications/logs', 'pages::admin.communications.logs')->name('admin.communications.logs')->middleware('can:communications.view');
        Route::livewire('communications/compose', 'pages::admin.communications.compose')->name('admin.communications.compose')->middleware('can:communications.send');
        Route::livewire('communications/broadcast', 'pages::admin.communications.broadcast-chat')->name('admin.chat.broadcast')->middleware('can:chat.broadcast');
        Route::livewire('marketing/subscribers', 'pages::admin.subscribers')->name('admin.subscribers.index')->middleware('can:subscribers.view');
        Route::livewire('system/templates/sms', 'pages::admin.templates.sms-templates')->name('admin.sms-templates.index')->middleware('can:templates.manage');
        Route::livewire('system/templates/email', 'pages::admin.templates.email-templates')->name('admin.email-templates.index')->middleware('can:templates.manage');
        Route::livewire('system/cron-jobs', 'pages::admin.system.cron-jobs')->name('admin.cron-jobs.index')->middleware('can:settings.cron');
        Route::livewire('system/audit-logs', 'pages::admin.audit-logs')->name('admin.audit-logs.index')->middleware('can:audit.view');
        Route::livewire('roles', 'pages::admin.roles')->name('admin.roles.index')->middleware('can:roles.view');
        Route::livewire('permissions', 'pages::admin.permissions')->name('admin.permissions.index')->middleware('can:permissions.manage');
        Route::livewire('my-activity', 'pages::admin.user-activity')->name('admin.user-activity.index');
        Route::livewire('reports', 'pages::admin.reports')->name('admin.reports.index')->middleware('can:reports.view');
        Route::livewire('reports/saved', 'pages::admin.reports-saved')->name('admin.reports.saved')->middleware('can:reports.view');
        Route::livewire('profile', 'pages::admin.profile')->name('admin.profile.show');
    });
