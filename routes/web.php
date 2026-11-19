<?php

use App\Http\Controllers\AcademicCalendarDownloadController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\OtpPasswordResetController;
use App\Http\Controllers\Auth\OtpVerificationController;
use App\Http\Controllers\ChatJoinController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\Hostel\HostelFormDownloadController;
use App\Http\Controllers\LibraryCardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MeetingJoinController;
use App\Http\Controllers\MeetingSignalController;
use App\Http\Controllers\Payment\CCAVenueController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\Public\StudyMaterialShareController;
use App\Http\Controllers\Public\UnsubscribeController;
use App\Http\Controllers\StudentIdCardController;
use App\Http\Controllers\StudentInformationSheetController;
use App\Http\Controllers\StudentTestDownloadController;
use App\Http\Controllers\StudyMaterialFileController;
use App\Http\Middleware\EnsureStudentProfileComplete;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\EnsureUserIsStudent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

Route::view('/', 'welcome')->name('home');

// Top-level /dashboard fallback route to redirect to the user's active team dashboard
Route::get('dashboard', function (Request $request) {
    $user = $request->user();
    if (! $user) {
        return redirect()->route('login');
    }

    $team = $user->ensureCurrentTeam();

    return redirect()->route('dashboard', ['current_team' => $team->slug]);
})->middleware(['auth', 'verified'])->name('dashboard.redirect');

// Top-level /admissions fallback route to redirect to the user's active team admissions hub
Route::get('admissions', function (Request $request) {
    $user = $request->user();
    if (! $user) {
        return redirect()->route('login');
    }

    $team = $user->ensureCurrentTeam();

    return redirect()->route('admin.admissions.index', ['current_team' => $team->slug]);
})->middleware(['auth', 'verified'])->name('admin.admissions.redirect');

// Top-level fallback for student routes when accessed without explicit team prefix
Route::get('student/{path?}', function (Request $request, ?string $path = null) {
    $user = $request->user();
    if (! $user) {
        return redirect()->route('login');
    }

    $team = $user->ensureCurrentTeam();
    $target = $path ? "student/{$path}" : 'student/dashboard';

    return redirect()->to("/{$team->slug}/{$target}");
})->where('path', '^(?!id-card/[0-9]+/download).*$')->middleware(['auth', 'verified'])->name('student.redirect');

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
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['web', 'guest:'.config('fortify.guard'), 'captcha:forgot_password'])
    ->name('password.email');

Route::post('locale', LocaleController::class)->name('locale.switch');

// Public Contact Us & Dynamic Pages routes
Route::livewire('contact', 'pages::public.contact-us')->name('public.contact');
Route::livewire('contact-us', 'pages::public.contact-us')->name('public.contact-us');
Route::livewire('alumni-showcase', 'pages::public.alumni')->name('public.alumni');
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
Route::get('live-chat/join/{code}', [ChatJoinController::class, 'join'])->name('chat.join')->middleware(['auth', 'can:chat.access']);
Route::get('meetings/join/{code}', [MeetingJoinController::class, 'join'])->name('meetings.join')->middleware(['auth', 'can:meetings.view']);
Route::post('meetings/{uuid}/signal', [MeetingSignalController::class, 'signal'])->name('meetings.signal.direct')->middleware(['auth', 'throttle:240,1', 'can:meetings.view']);
Route::get('meetings/{uuid}/sync', [MeetingSignalController::class, 'sync'])->name('meetings.sync.direct')->middleware(['auth', 'throttle:240,1', 'can:meetings.view']);
// CCAvenue Payment Gateway Checkout & Response Routes
Route::get('payments/ccavenue/redirect/{transaction}', [CCAVenueController::class, 'redirect'])->name('payments.ccavenue.redirect')->middleware(['auth']);
Route::match(['get', 'post'], 'payments/ccavenue/response', [CCAVenueController::class, 'response'])->name('payments.ccavenue.response');
Route::match(['get', 'post'], 'payments/ccavenue/cancel', [CCAVenueController::class, 'cancel'])->name('payments.ccavenue.cancel');

Route::get('payments/receipt/{transaction}', [PaymentReceiptController::class, 'download'])->name('payments.receipt.download')->middleware(['auth']);
Route::get('receipts/download/{transaction}', [PaymentReceiptController::class, 'download'])->name('receipts.download')->middleware(['auth']);
Route::get('library/card/{member}/download', [LibraryCardController::class, 'download'])->name('library.card.download')->middleware(['auth']);
Route::get('library/barcode/print', [LibraryCardController::class, 'printLabels'])->name('library.barcode.print')->middleware(['auth']);
Route::get('library/clearance/{deposit}/download', [LibraryCardController::class, 'downloadClearance'])->name('library.clearance.download')->middleware(['auth']);
Route::get('student/id-card/{student}/download', [StudentIdCardController::class, 'download'])->name('student.id-card.download')->middleware(['auth']);
Route::get('admin/student-id-cards/bulk-download', [StudentIdCardController::class, 'bulkDownload'])->name('admin.student-id-cards.bulk-download')->middleware(['auth']);
Route::get('admin/students/information-sheet/download', [StudentInformationSheetController::class, 'download'])->name('admin.students.information-sheet.download')->middleware(['auth']);

// Public and Authenticated Study Material Access & Download Routes
Route::match(['get', 'post'], 'study-materials/share/{token}', [StudyMaterialShareController::class, 'viewShared'])->name('study-materials.public-share');
Route::get('study-materials/share/{token}/download/{fileIndex?}', [StudyMaterialShareController::class, 'downloadShared'])->name('study-materials.public-download');
Route::get('study-materials/{studyMaterial}/download/{fileIndex?}', [StudyMaterialFileController::class, 'download'])->name('study-materials.download')->middleware(['auth']);
Route::get('study-materials/{studyMaterial}/preview/{fileIndex}', [StudyMaterialFileController::class, 'preview'])->name('study-materials.preview')->middleware(['auth']);

// Student Tests Question Paper, Model Answer, and Marksheet Downloads
Route::get('tests/{test}/download/question-paper', [StudentTestDownloadController::class, 'downloadQuestionPaper'])->name('tests.download-paper')->middleware(['auth']);
Route::get('tests/{test}/download/model-answer', [StudentTestDownloadController::class, 'downloadModelAnswer'])->name('tests.download-solution')->middleware(['auth']);
Route::get('tests/results/{result}/marksheet', [StudentTestDownloadController::class, 'downloadMarksheet'])->name('tests.download-marksheet')->middleware(['auth']);

// Academic Calendar File Download and Export Routes
Route::get('academic-calendar/version/{version}/download', [AcademicCalendarDownloadController::class, 'downloadVersion'])->name('academic-calendar.download.version')->middleware(['auth']);
Route::get('academic-calendar/{calendar}/download-latest', [AcademicCalendarDownloadController::class, 'downloadLatest'])->name('academic-calendar.download.latest')->middleware(['auth']);
Route::get('academic-calendar/{calendar}/export-pdf', [AcademicCalendarDownloadController::class, 'exportPdf'])->name('academic-calendar.export.pdf')->middleware(['auth']);
Route::get('academic-calendar/template/import-activities', [AcademicCalendarDownloadController::class, 'sampleImportTemplate'])->name('academic-calendar.template.import')->middleware(['auth']);

// Soft Copies Documents File Download, Stream and Export Routes
Route::get('documents/version/{version}/download', [DocumentDownloadController::class, 'downloadVersion'])->name('documents.download.version')->middleware(['auth']);
Route::get('documents/{document}/download-latest', [DocumentDownloadController::class, 'downloadLatest'])->name('documents.download.latest')->middleware(['auth']);
Route::get('documents/export-pdf', [DocumentDownloadController::class, 'exportPdf'])->name('documents.export.pdf')->middleware(['auth']);
Route::get('documents/template/import', [DocumentDownloadController::class, 'sampleImportTemplate'])->name('documents.template.import')->middleware(['auth']);

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, EnsureStudentProfileComplete::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        // Student Portal Routes (Restricted to enrolled students)
        Route::middleware([EnsureUserIsStudent::class])->group(function () {
            Route::livewire('student/dashboard', 'pages::student.dashboard')->name('student.dashboard');
            Route::livewire('student/academics', 'pages::student.academics')->name('student.academics.index');
            Route::livewire('student/enrollments', 'pages::student.academics')->name('student.enrollments.index');
            Route::livewire('student/payments', 'pages::student.payments')->name('student.payments.index');
            Route::livewire('student/library-deposit', 'pages::student.library-deposit')->name('student.library-deposit.index');
            Route::livewire('student/library', 'pages::student.library')->name('student.library.index');
            Route::livewire('student/library-card', 'pages::student.library-card')->name('student.library.card');
            Route::livewire('student/id-card', 'pages::student.id-card')->name('student.id-card.index');
            Route::livewire('student/leaves', 'pages::student.leaves')->name('student.leaves.index');
            Route::livewire('student/attendance', 'pages::student.attendance')->name('student.attendance.index');
            Route::livewire('student/routine', 'pages::student.routine')->name('student.routine.index');
            Route::livewire('student/academic-calendar', 'pages::student.academic-calendar')->name('student.academic-calendar.index')->middleware('can:academic_calendar.view');
            Route::livewire('student/mock-interviews', 'pages::student.mock-interviews')->name('student.mock-interviews.index');
            Route::livewire('student/study-materials', 'pages::student.study-materials')->name('student.study-materials.index')->middleware('can:study_materials.view');
            Route::livewire('student/tests', 'pages::student.tests')->name('student.tests.index');
            Route::livewire('student/leaderboard', 'pages::student.tests')->name('student.tests.leaderboard');
            Route::livewire('student/hostel', 'pages::student.hostel')->name('student.hostel.index');
        });

        // Admin Fees & Payment Collection System
        Route::livewire('payments/fees', 'pages::admin.payments.fees')->name('admin.payments.fees')->middleware('can:payments.view');
        Route::livewire('payments/schedules', 'pages::admin.payments.schedules')->name('admin.payments.schedules')->middleware('can:payments.create');
        Route::livewire('payments/verifications', 'pages::admin.payments.verifications')->name('admin.payments.verifications')->middleware('can:payments.verify');
        Route::livewire('payments/library', 'pages::admin.payments.library')->name('admin.payments.library')->middleware('can:library_deposit.manage');
        Route::livewire('payments/transactions', 'pages::admin.payments.transactions')->name('admin.payments.transactions')->middleware('can:payments.view');
        Route::livewire('payments/ledger', 'pages::admin.payments.ledger')->name('admin.payments.ledger')->middleware('can:payments.view');
        Route::livewire('payments/reports', 'pages::admin.payments.reports')->name('admin.payments.reports')->middleware('can:payments.export');

        Route::livewire('students', 'pages::admin.students')->name('admin.students.index')->middleware('can:students.view');
        Route::livewire('students/information-sheets', 'pages::admin.students.information-sheet')->name('admin.students.information-sheet')->middleware('can:students.view');
        Route::livewire('student-id-cards', 'pages::admin.student-id-cards.index')->name('admin.student-id-cards.index')->middleware('can:student_id_cards.view');
        Route::livewire('optional-subjects', 'pages::admin.optional-subjects.index')->name('admin.optional-subjects.index')->middleware('can:optional_subjects.view');
        Route::livewire('admissions', 'pages::admin.admissions')->name('admin.admissions.index')->middleware('can:admissions.view');
        Route::livewire('enrollments', 'pages::admin.enrollments')->name('admin.enrollments.index')->middleware('can:students.view');
        Route::livewire('students/withdrawals', 'pages::admin.course-withdrawals')->name('admin.course-withdrawals.index')->middleware('can:withdrawals.view');
        Route::livewire('students/profile-requests', 'pages::admin.profile-requests')->name('admin.profile-requests.index')->middleware('can:students.view');
        Route::livewire('courses', 'pages::admin.courses')->name('admin.courses.index')->middleware('can:courses.manage');
        Route::livewire('batches', 'pages::admin.batches')->name('admin.batches.index')->middleware('can:batches.manage');
        Route::livewire('sections', 'pages::admin.sections')->name('admin.sections.index')->middleware('can:sections.manage');
        Route::livewire('academic-calendar', 'pages::admin.academic-calendar')->name('admin.academic-calendar.index')->middleware('can:academic_calendar.view');
        Route::livewire('documents', 'pages::admin.documents')->name('admin.documents.index')->middleware('can:documents.view');
        Route::livewire('classes', 'pages::admin.classes')->name('admin.classes.index')->middleware('can:classes.view');
        Route::livewire('classes/plans', 'pages::admin.course-plans')->name('admin.classes.plans')->middleware('can:classes.plans');
        Route::livewire('mock-interviews', 'pages::admin.mock-interviews')->name('admin.mock-interviews.index')->middleware('can:mock_interviews.view');
        Route::livewire('classrooms', 'pages::admin.classrooms')->name('admin.classrooms.index')->middleware('can:classrooms.view');
        Route::livewire('faculties', 'pages::admin.faculties')->name('admin.faculties.index')->middleware('can:faculty.view');
        Route::livewire('partner-institutes', 'pages::admin.partner-institutes')->name('admin.partner-institutes.index')->middleware('can:partner_institutes.view');
        Route::livewire('tests', 'pages::admin.tests.index')->name('admin.tests.index')->middleware('can:tests.manage');
        Route::livewire('tests/reports', 'pages::admin.tests.reports')->name('admin.tests.reports')->middleware('can:tests.manage');
        Route::livewire('tests/leaderboard', 'pages::admin.tests.leaderboard')->name('admin.tests.leaderboard')->middleware('can:tests.manage');
        Route::livewire('tests/{test}/results', 'pages::admin.tests.results')->name('admin.tests.results')->middleware('can:tests.manage');
        Route::livewire('users', 'pages::admin.users')->name('admin.users.index')->middleware('can:users.view');
        Route::livewire('content/pages', 'pages::admin.pages')->name('admin.pages.index')->middleware('can:pages.manage');

        // User Support Ticket Portal
        Route::livewire('tickets', 'pages::portal.tickets')->name('tickets.index');
        Route::livewire('tickets/create', 'pages::portal.tickets.create')->name('tickets.create');
        Route::livewire('tickets/{ticket}', 'pages::portal.tickets.show')->name('tickets.show');

        // User Notification Center & Inbox
        Route::livewire('notifications', 'pages::portal.notifications')->name('notifications.index');

        // Realtime Live Chat & Channels Portal
        Route::livewire('chat', 'pages::portal.chat')->name('admin.chat.index')->middleware('can:chat.access');
        Route::livewire('live-chat', 'pages::portal.chat')->name('chat.index')->middleware('can:chat.access');

        // Dedicated Online Meetings & Video Conferencing
        Route::livewire('meetings', 'pages::portal.meetings')->name('meetings.index')->middleware('can:meetings.view');
        Route::livewire('meetings/room/{uuid}', 'pages::portal.meeting-room')->name('meetings.room')->middleware('can:meetings.view');
        Route::post('meetings/{uuid}/signal', [MeetingSignalController::class, 'signal'])->name('meetings.signal')->middleware(['throttle:240,1', 'can:meetings.view']);
        Route::get('meetings/{uuid}/sync', [MeetingSignalController::class, 'sync'])->name('meetings.sync')->middleware(['throttle:240,1', 'can:meetings.view']);

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
        Route::livewire('system/settings/library', 'pages::admin.settings.library')->name('admin.settings.library')->middleware('can:library.settings.manage');
        Route::livewire('system/settings/attendance', 'pages::admin.settings.attendance')->name('admin.settings.attendance')->middleware('can:attendance.settings');
        Route::livewire('system/settings/hostel', 'pages::admin.settings.hostel')->name('admin.settings.hostel')->middleware('can:hostel.settings.manage');
        Route::livewire('system/settings/student-id-card', 'pages::admin.settings.student-id-card')->name('admin.settings.student-id-card')->middleware('can:settings.general');
        Route::livewire('system/settings/captcha', 'pages::admin.settings.captcha')->name('admin.settings.captcha')->middleware('can:settings.captcha');
        Route::livewire('system/settings/storage', 'pages::admin.settings.storage')->name('admin.settings.storage')->middleware('can:settings.storage');
        Route::livewire('system/settings/otr', 'pages::admin.settings.otr')->name('admin.settings.otr')->middleware('can:settings.general');

        // Comprehensive Dynamic Attendance Management System
        Route::livewire('attendance', 'pages::admin.attendance.index')->name('admin.attendance.index')->middleware('can:attendance.view');
        Route::livewire('attendance/mark', 'pages::admin.attendance.mark')->name('admin.attendance.mark')->middleware('can:attendance.manage');
        Route::livewire('attendance/reports', 'pages::admin.attendance.reports')->name('admin.attendance.reports')->middleware('can:attendance.view');
        Route::livewire('attendance/biometric', 'pages::admin.attendance.biometric')->name('admin.attendance.biometric')->middleware('can:attendance.biometric');
        Route::livewire('attendance/calendar', 'pages::admin.attendance.calendar')->name('admin.attendance.calendar')->middleware('can:attendance.settings');

        // Dynamic Leave Management System (Staff & Students)
        Route::livewire('leaves', 'pages::admin.leaves.index')->name('admin.leaves.index')->middleware('can:leaves.view');
        Route::livewire('leaves/categories', 'pages::admin.leaves.categories')->name('admin.leaves.categories')->middleware('can:leaves.categories.manage');

        // Staff / User Personal Portal Routes
        Route::livewire('portal/my-leaves', 'pages::portal.my-leaves')->name('portal.my-leaves.index');
        Route::livewire('portal/my-attendance', 'pages::portal.my-attendance')->name('portal.my-attendance.index');
        Route::livewire('portal/my-hostel', 'pages::portal.my-hostel')->name('portal.my-hostel.index');

        // Comprehensive Dynamic Library Management System
        Route::livewire('library/books', 'pages::admin.library.books')->name('admin.library.books')->middleware('can:library.books.manage');
        Route::livewire('library/accession', 'pages::admin.library.accession')->name('admin.library.accession')->middleware('can:library.accession.manage');
        Route::livewire('library/circulation', 'pages::admin.library.circulation')->name('admin.library.circulation')->middleware('can:library.issue.manage');
        Route::livewire('library/reservations', 'pages::admin.library.reservations')->name('admin.library.reservations')->middleware('can:library.reservations.manage');
        Route::livewire('library/members', 'pages::admin.library.members')->name('admin.library.members')->middleware('can:library.members.manage');
        Route::livewire('library/fines', 'pages::admin.library.fines')->name('admin.library.fines')->middleware('can:library.fines.manage');
        Route::livewire('library/reports', 'pages::admin.library.reports')->name('admin.library.reports')->middleware('can:library.reports.view');
        Route::livewire('library/masters', 'pages::admin.library.masters')->name('admin.library.masters')->middleware('can:library.categories.manage');

        // Comprehensive Dynamic Alumni Management System
        Route::livewire('alumni', 'pages::admin.alumni.index')->name('admin.alumni.index')->middleware('can:alumni.view');
        Route::livewire('alumni/statistics', 'pages::admin.alumni.statistics')->name('admin.alumni.statistics')->middleware('can:alumni.view');

        // Comprehensive Dynamic Study Materials Management System
        Route::livewire('study-materials', 'pages::admin.study-materials.index')->name('admin.study-materials.index')->middleware('can:study_materials.view');
        Route::livewire('study-materials/statistics', 'pages::admin.study-materials.statistics')->name('admin.study-materials.statistics')->middleware('can:study_materials.view');

        // Comprehensive Dynamic API Key Management & External Application Integrations
        Route::livewire('api-keys', 'pages::admin.api-keys.index')->name('admin.api-keys.index')->middleware('can:api_keys.view');
        Route::livewire('api-keys/logs', 'pages::admin.api-keys.logs')->name('admin.api-keys.logs')->middleware('can:api_keys.logs');
        Route::livewire('api-keys/docs', 'pages::admin.api-keys.docs')->name('admin.api-keys.docs')->middleware('can:api_keys.view');

        // Comprehensive Dynamic Hostel Management System
        Route::livewire('hostel', 'pages::admin.hostel.dashboard')->name('admin.hostel.dashboard')->middleware('can:hostel.view');
        Route::livewire('hostel/rooms-board', 'pages::admin.hostel.rooms-board')->name('admin.hostel.rooms.board')->middleware('can:hostel.view');
        Route::livewire('hostel/buildings', 'pages::admin.hostel.buildings')->name('admin.hostel.buildings')->middleware('can:hostel.buildings.manage');
        Route::livewire('hostel/rooms', 'pages::admin.hostel.rooms')->name('admin.hostel.rooms.index')->middleware('can:hostel.rooms.manage');
        Route::livewire('hostel/allocations', 'pages::admin.hostel.allocations')->name('admin.hostel.allocations')->middleware('can:hostel.allocations.view');
        Route::livewire('hostel/requests', 'pages::admin.hostel.requests')->name('admin.hostel.requests')->middleware('can:hostel.requests.manage');
        Route::livewire('hostel/reports', 'pages::admin.hostel.reports')->name('admin.hostel.reports')->middleware('can:hostel.reports.view');
        Route::get('hostel/form/download', [HostelFormDownloadController::class, 'download'])->name('hostel.form.download');

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
        Route::livewire('my-activity', 'pages::admin.user-activity')->name('admin.user-activity.index')->middleware('can:activity.view');
        Route::livewire('reports', 'pages::admin.reports')->name('admin.reports.index')->middleware('can:reports.view');
        Route::livewire('reports/saved', 'pages::admin.reports-saved')->name('admin.reports.saved')->middleware('can:reports.view');
        Route::livewire('profile', 'pages::admin.profile')->name('admin.profile.show');
    });
