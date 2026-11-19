<?php

use App\Http\Controllers\Api\AcademicApiController;
use App\Http\Controllers\Api\AcademicCalendarApiController;
use App\Http\Controllers\Api\AlumniApiController;
use App\Http\Controllers\Api\ApiKeyIntegrationApiController;
use App\Http\Controllers\Api\AttendanceApiController;
use App\Http\Controllers\Api\BiometricApiController;
use App\Http\Controllers\Api\CaptchaApiController;
use App\Http\Controllers\Api\ChatApiController;
use App\Http\Controllers\Api\ClassroomApiController;
use App\Http\Controllers\Api\ClassScheduleApiController;
use App\Http\Controllers\Api\CommunicationApiController;
use App\Http\Controllers\Api\DocumentApiController;
use App\Http\Controllers\Api\EnrollmentApiController;
use App\Http\Controllers\Api\ExamApiController;
use App\Http\Controllers\Api\ExternalStudyMaterialApiController;
use App\Http\Controllers\Api\FacultyApiController;
use App\Http\Controllers\Api\HostelApiController;
use App\Http\Controllers\Api\LeaveApiController;
use App\Http\Controllers\Api\LibraryApiController;
use App\Http\Controllers\Api\MockInterviewApiController;
use App\Http\Controllers\Api\OptionalSubjectApiController;
use App\Http\Controllers\Api\PartnerInstituteApiController;
use App\Http\Controllers\Api\PaymentApiController;
use App\Http\Controllers\Api\SearchApiController;
use App\Http\Controllers\Api\StudentApiController;
use App\Http\Controllers\Api\StudentIdCardApiController;
use App\Http\Controllers\Api\StudentInformationSheetApiController;
use App\Http\Controllers\Api\StudentTestApiController;
use App\Http\Controllers\Api\StudyMaterialApiController;
use App\Http\Controllers\Api\SystemSettingsApiController;
use App\Http\Controllers\Api\TicketApiController;
use App\Http\Controllers\Api\UserApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Biometric Machine API Endpoints (supports device token or API Key)
Route::prefix('v1/biometric')->group(function () {
    Route::post('/punch', [BiometricApiController::class, 'pushPunches'])->name('api.biometric.punch');
    Route::post('/heartbeat', [BiometricApiController::class, 'heartbeat'])->name('api.biometric.heartbeat');
});

// Public Communications & Marketing Lead Capture (Headless / Cross-Platform)
Route::prefix('v1/communications/public')->middleware(['throttle:30,1'])->group(function () {
    Route::post('/subscribe', [CommunicationApiController::class, 'publicSubscribe'])->name('api.v1.communications.public.subscribe');
    Route::post('/unsubscribe', [CommunicationApiController::class, 'publicUnsubscribe'])->name('api.v1.communications.public.unsubscribe');
});

// Public Captcha Generation, Verification & Acoustic Audio Readout (Multi-Platform Mobile, Web, Desktop)
Route::prefix('v1/captcha')->middleware(['throttle:120,1'])->group(function () {
    Route::get('/specs', [CaptchaApiController::class, 'specs'])->name('api.v1.captcha.specs');
    Route::get('/generate', [CaptchaApiController::class, 'generate'])->name('api.v1.captcha.generate');
    Route::post('/verify', [CaptchaApiController::class, 'verify'])->name('api.v1.captcha.verify');
    Route::post('/refresh', [CaptchaApiController::class, 'refresh'])->name('api.v1.captcha.refresh');
    Route::get('/audio/{token}', [CaptchaApiController::class, 'audio'])->name('api.v1.captcha.audio');
    Route::get('/status', [CaptchaApiController::class, 'status'])->name('api.v1.captcha.status');
});

// Authenticated API Key RESTful Services (Enforced API Key requirement)
Route::prefix('v1')->middleware(['auth.api_key'])->group(function () {
    // 1. Diagnostics, Handshake & Metadata
    Route::get('/ping', [ApiKeyIntegrationApiController::class, 'ping'])
        ->middleware('api.scope:ping')
        ->name('api.v1.ping');

    Route::get('/app/info', [ApiKeyIntegrationApiController::class, 'appInfo'])
        ->middleware('api.scope:read:system,ping')
        ->name('api.v1.app-info');

    Route::post('/echo', [ApiKeyIntegrationApiController::class, 'echoPayload'])
        ->middleware('api.scope:ping')
        ->name('api.v1.echo');

    // 2. Comprehensive Course and Academics Management System
    Route::prefix('academics')->group(function () {
        // Executive Telemetry & Analytics
        Route::get('/dashboard', [AcademicApiController::class, 'dashboard'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.dashboard');
        Route::get('/statistics', [AcademicApiController::class, 'statistics'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.statistics');

        // Academic Sessions & Years
        Route::get('/academic-years', [AcademicApiController::class, 'academicYears'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.academic-years');

        // Academic Working Calendar & Holidays
        Route::get('/calendar', [AcademicApiController::class, 'calendar'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.calendar');

        // Bulk Data Exchange
        Route::get('/courses/export', [AcademicApiController::class, 'exportCourses'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.courses.export');
        Route::post('/courses/import', [AcademicApiController::class, 'importCourses'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.courses.import');
        Route::get('/batches/export', [AcademicApiController::class, 'exportBatches'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.batches.export');
        Route::post('/batches/import', [AcademicApiController::class, 'importBatches'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.batches.import');

        // Courses Management
        Route::get('/courses', [AcademicApiController::class, 'courses'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.courses');
        Route::post('/courses', [AcademicApiController::class, 'storeCourse'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.courses.store');
        Route::get('/courses/{identifier}', [AcademicApiController::class, 'showCourse'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.courses.show');
        Route::put('/courses/{identifier}', [AcademicApiController::class, 'updateCourse'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.courses.update');
        Route::delete('/courses/{identifier}', [AcademicApiController::class, 'destroyCourse'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.courses.destroy');

        // Batches Management
        Route::get('/batches', [AcademicApiController::class, 'batches'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.batches');
        Route::post('/batches', [AcademicApiController::class, 'storeBatch'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.batches.store');
        Route::get('/batches/{identifier}', [AcademicApiController::class, 'showBatch'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.batches.show');
        Route::put('/batches/{identifier}', [AcademicApiController::class, 'updateBatch'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.batches.update');
        Route::delete('/batches/{identifier}', [AcademicApiController::class, 'destroyBatch'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.batches.destroy');

        // Batch Course Offerings
        Route::get('/batches/{batchId}/courses', [AcademicApiController::class, 'batchCourses'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.batches.courses.index');
        Route::post('/batches/{batchId}/courses', [AcademicApiController::class, 'attachBatchCourse'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.batches.courses.store');
        Route::put('/batches/{batchId}/courses/{courseId}', [AcademicApiController::class, 'updateBatchCourse'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.batches.courses.update');
        Route::delete('/batches/{batchId}/courses/{courseId}', [AcademicApiController::class, 'detachBatchCourse'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.batches.courses.destroy');

        // Classroom Sections
        Route::get('/sections', [AcademicApiController::class, 'sections'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.sections.index');
        Route::post('/sections', [AcademicApiController::class, 'storeSection'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.sections.store');
        Route::get('/sections/{id}', [AcademicApiController::class, 'showSection'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academics.sections.show');
        Route::put('/sections/{id}', [AcademicApiController::class, 'updateSection'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.sections.update');
        Route::delete('/sections/{id}', [AcademicApiController::class, 'destroySection'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academics.sections.destroy');
    });

    // Top-level aliases for Courses, Batches, and Sections
    Route::get('/courses', [AcademicApiController::class, 'courses'])
        ->middleware('api.scope:read:academics')
        ->name('api.v1.courses');
    Route::post('/courses', [AcademicApiController::class, 'storeCourse'])
        ->middleware('api.scope:write:academics')
        ->name('api.v1.courses.store');
    Route::get('/courses/{identifier}', [AcademicApiController::class, 'showCourse'])
        ->middleware('api.scope:read:academics')
        ->name('api.v1.courses.show');
    Route::put('/courses/{identifier}', [AcademicApiController::class, 'updateCourse'])
        ->middleware('api.scope:write:academics')
        ->name('api.v1.courses.update');
    Route::delete('/courses/{identifier}', [AcademicApiController::class, 'destroyCourse'])
        ->middleware('api.scope:write:academics')
        ->name('api.v1.courses.destroy');

    Route::get('/batches', [AcademicApiController::class, 'batches'])
        ->middleware('api.scope:read:academics')
        ->name('api.v1.batches');
    Route::post('/batches', [AcademicApiController::class, 'storeBatch'])
        ->middleware('api.scope:write:academics')
        ->name('api.v1.batches.store');
    Route::get('/batches/{identifier}', [AcademicApiController::class, 'showBatch'])
        ->middleware('api.scope:read:academics')
        ->name('api.v1.batches.show');
    Route::put('/batches/{identifier}', [AcademicApiController::class, 'updateBatch'])
        ->middleware('api.scope:write:academics')
        ->name('api.v1.batches.update');
    Route::delete('/batches/{identifier}', [AcademicApiController::class, 'destroyBatch'])
        ->middleware('api.scope:write:academics')
        ->name('api.v1.batches.destroy');

    Route::get('/sections', [AcademicApiController::class, 'sections'])
        ->middleware('api.scope:read:academics')
        ->name('api.v1.sections');
    Route::post('/sections', [AcademicApiController::class, 'storeSection'])
        ->middleware('api.scope:write:academics')
        ->name('api.v1.sections.store');
    Route::get('/sections/{id}', [AcademicApiController::class, 'showSection'])
        ->whereNumber('id')
        ->middleware('api.scope:read:academics')
        ->name('api.v1.sections.show');
    Route::put('/sections/{id}', [AcademicApiController::class, 'updateSection'])
        ->whereNumber('id')
        ->middleware('api.scope:write:academics')
        ->name('api.v1.sections.update');
    Route::delete('/sections/{id}', [AcademicApiController::class, 'destroySection'])
        ->whereNumber('id')
        ->middleware('api.scope:write:academics')
        ->name('api.v1.sections.destroy');

    // 2.0 Academic Calendar & Annual Activity Planning with Version Control
    Route::prefix('academic-calendars')->group(function () {
        Route::get('/', [AcademicCalendarApiController::class, 'index'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academic-calendars.index');
        Route::get('/current', [AcademicCalendarApiController::class, 'current'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academic-calendars.current');
        Route::get('/{id}', [AcademicCalendarApiController::class, 'show'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academic-calendars.show');
        Route::post('/', [AcademicCalendarApiController::class, 'store'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.store');
        Route::put('/{id}', [AcademicCalendarApiController::class, 'update'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.update');
        Route::delete('/{id}', [AcademicCalendarApiController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.destroy');
        Route::post('/{id}/set-current', [AcademicCalendarApiController::class, 'setCurrent'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.set-current');
        Route::get('/{id}/versions', [AcademicCalendarApiController::class, 'versions'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academic-calendars.versions');
        Route::post('/{id}/versions', [AcademicCalendarApiController::class, 'uploadRevision'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.versions.store');
        Route::get('/{id}/versions/{versionId}/download', [AcademicCalendarApiController::class, 'downloadVersion'])
            ->whereNumber('id')
            ->whereNumber('versionId')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academic-calendars.versions.download');
        Route::get('/{id}/activities', [AcademicCalendarApiController::class, 'activities'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.academic-calendars.activities');
        Route::post('/{id}/activities', [AcademicCalendarApiController::class, 'storeActivity'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.activities.store');
        Route::put('/{id}/activities/{activityId}', [AcademicCalendarApiController::class, 'updateActivity'])
            ->whereNumber('id')
            ->whereNumber('activityId')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.activities.update');
        Route::delete('/{id}/activities/{activityId}', [AcademicCalendarApiController::class, 'destroyActivity'])
            ->whereNumber('id')
            ->whereNumber('activityId')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.academic-calendars.activities.destroy');
    });

    // 2.05 Soft Copies Document Files Management with Version Control
    Route::prefix('documents')->group(function () {
        Route::get('/', [DocumentApiController::class, 'index'])
            ->middleware('api.scope:read:documents,read:academics')
            ->name('api.v1.documents.index');
        Route::get('/statistics', [DocumentApiController::class, 'statistics'])
            ->middleware('api.scope:read:documents,read:academics')
            ->name('api.v1.documents.statistics');
        Route::get('/categories', [DocumentApiController::class, 'categories'])
            ->middleware('api.scope:read:documents,read:academics')
            ->name('api.v1.documents.categories');
        Route::post('/categories', [DocumentApiController::class, 'storeCategory'])
            ->middleware('api.scope:write:documents,admin:documents,write:academics')
            ->name('api.v1.documents.categories.store');
        Route::get('/template', [DocumentApiController::class, 'downloadTemplate'])
            ->middleware('api.scope:read:documents,write:documents,read:academics')
            ->name('api.v1.documents.template');
        Route::post('/import', [DocumentApiController::class, 'import'])
            ->middleware('api.scope:write:documents,admin:documents,write:academics')
            ->name('api.v1.documents.import');
        Route::get('/export', [DocumentApiController::class, 'export'])
            ->middleware('api.scope:read:documents,read:academics')
            ->name('api.v1.documents.export');
        Route::get('/{id}', [DocumentApiController::class, 'show'])
            ->whereNumber('id')
            ->middleware('api.scope:read:documents,read:academics')
            ->name('api.v1.documents.show');
        Route::get('/{id}/download', [DocumentApiController::class, 'downloadLatest'])
            ->whereNumber('id')
            ->middleware('api.scope:read:documents,download:documents,read:academics')
            ->name('api.v1.documents.download.latest');
        Route::post('/', [DocumentApiController::class, 'store'])
            ->middleware('api.scope:write:documents,write:academics')
            ->name('api.v1.documents.store');
        Route::put('/{id}', [DocumentApiController::class, 'update'])
            ->whereNumber('id')
            ->middleware('api.scope:write:documents,write:academics')
            ->name('api.v1.documents.update');
        Route::delete('/{id}', [DocumentApiController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('api.scope:write:documents,admin:documents,write:academics')
            ->name('api.v1.documents.destroy');
        Route::post('/{id}/restore', [DocumentApiController::class, 'restore'])
            ->whereNumber('id')
            ->middleware('api.scope:write:documents,admin:documents,write:academics')
            ->name('api.v1.documents.restore');
        Route::get('/{id}/versions', [DocumentApiController::class, 'versions'])
            ->whereNumber('id')
            ->middleware('api.scope:read:documents,read:academics')
            ->name('api.v1.documents.versions');
        Route::post('/{id}/versions', [DocumentApiController::class, 'uploadRevision'])
            ->whereNumber('id')
            ->middleware('api.scope:write:documents,write:academics')
            ->name('api.v1.documents.versions.store');
        Route::post('/{id}/versions/{versionId}/set-current', [DocumentApiController::class, 'setCurrentVersion'])
            ->whereNumber('id')
            ->whereNumber('versionId')
            ->middleware('api.scope:write:documents,admin:documents,write:academics')
            ->name('api.v1.documents.versions.set-current');
        Route::get('/{id}/versions/{versionId}/download', [DocumentApiController::class, 'downloadVersion'])
            ->whereNumber('id')
            ->whereNumber('versionId')
            ->middleware('api.scope:read:documents,download:documents,read:academics')
            ->name('api.v1.documents.versions.download');
    });

    // 2.1 Class Routine & Lecture Schedule Management
    Route::prefix('classes')->group(function () {
        Route::get('/', [ClassScheduleApiController::class, 'index'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.index');
        Route::post('/', [ClassScheduleApiController::class, 'store'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.store');
        Route::get('/calendar', [ClassScheduleApiController::class, 'calendar'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.calendar');
        Route::get('/export', [ClassScheduleApiController::class, 'export'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.export');
        Route::post('/import', [ClassScheduleApiController::class, 'import'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.import');
        Route::get('/settings/reminders', [ClassScheduleApiController::class, 'reminderSettings'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.settings.reminders');
        Route::put('/settings/reminders', [ClassScheduleApiController::class, 'updateReminderSettings'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.settings.reminders.update');
        Route::get('/reports/faculty-workload', [ClassScheduleApiController::class, 'workloadReport'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.reports.faculty-workload');
        Route::get('/reports/course-coverage', [ClassScheduleApiController::class, 'courseCoverageReport'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.reports.course-coverage');
        Route::get('/reports/classroom-utilization', [ClassScheduleApiController::class, 'classroomUtilizationReport'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.reports.classroom-utilization');
        Route::get('/faculties', [ClassScheduleApiController::class, 'faculties'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.faculties');
        Route::post('/faculties', [ClassScheduleApiController::class, 'quickCreateFaculty'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.faculties.store');
        Route::get('/course-plans', [ClassScheduleApiController::class, 'coursePlans'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.course-plans');
        Route::post('/course-plans', [ClassScheduleApiController::class, 'storeCoursePlan'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.course-plans.store');
        Route::get('/course-plans/{id}', [ClassScheduleApiController::class, 'showCoursePlan'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.course-plans.show');
        Route::put('/course-plans/{id}', [ClassScheduleApiController::class, 'updateCoursePlan'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.course-plans.update');
        Route::delete('/course-plans/{id}', [ClassScheduleApiController::class, 'destroyCoursePlan'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.course-plans.destroy');
        Route::post('/course-plans/{id}/restore', [ClassScheduleApiController::class, 'restoreCoursePlan'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.course-plans.restore');
        Route::get('/course-plans/{id}/progress', [ClassScheduleApiController::class, 'coursePlanProgress'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.course-plans.progress');
        Route::get('/{id}', [ClassScheduleApiController::class, 'show'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classes.show');
        Route::put('/{id}', [ClassScheduleApiController::class, 'update'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.update');
        Route::delete('/{id}', [ClassScheduleApiController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.destroy');
        Route::post('/{id}/restore', [ClassScheduleApiController::class, 'restore'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.restore');
        Route::post('/{id}/complete', [ClassScheduleApiController::class, 'complete'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.complete');
        Route::post('/{id}/reschedule', [ClassScheduleApiController::class, 'reschedule'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.reschedule');
        Route::post('/{id}/cancel', [ClassScheduleApiController::class, 'cancel'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.cancel');
        Route::post('/{id}/recording', [ClassScheduleApiController::class, 'updateRecording'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.recording');
        Route::post('/{id}/send-reminder', [ClassScheduleApiController::class, 'sendReminder'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classes.send-reminder');
    });

    // 2.2 Faculty Master Management
    Route::prefix('faculties')->group(function () {
        Route::get('/', [FacultyApiController::class, 'index'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.faculties.index');
        Route::post('/', [FacultyApiController::class, 'store'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.faculties.store');
        Route::get('/workload-stats', [FacultyApiController::class, 'workloadStats'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.faculties.workload-stats');
        Route::get('/export', [FacultyApiController::class, 'export'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.faculties.export');
        Route::post('/import', [FacultyApiController::class, 'import'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.faculties.import');
        Route::get('/{id}', [FacultyApiController::class, 'show'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.faculties.show');
        Route::put('/{id}', [FacultyApiController::class, 'update'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.faculties.update');
        Route::delete('/{id}', [FacultyApiController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.faculties.destroy');
        Route::post('/{id}/restore', [FacultyApiController::class, 'restore'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.faculties.restore');
        Route::get('/{id}/schedule', [FacultyApiController::class, 'schedule'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.faculties.schedule');
        Route::get('/{id}/workload', [FacultyApiController::class, 'workload'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.faculties.workload');
    });

    // 2.3 Classrooms Master Management
    Route::prefix('classrooms')->group(function () {
        Route::get('/', [ClassroomApiController::class, 'index'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classrooms.index');
        Route::post('/', [ClassroomApiController::class, 'store'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classrooms.store');
        Route::get('/{id}', [ClassroomApiController::class, 'show'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classrooms.show');
        Route::put('/{id}', [ClassroomApiController::class, 'update'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classrooms.update');
        Route::delete('/{id}', [ClassroomApiController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classrooms.destroy');
        Route::post('/{id}/restore', [ClassroomApiController::class, 'restore'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.classrooms.restore');
        Route::get('/{id}/schedule', [ClassroomApiController::class, 'schedule'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.classrooms.schedule');
    });

    // 2.4 Partner Coaching Institutes Management
    Route::prefix('partner-institutes')->group(function () {
        Route::get('/', [PartnerInstituteApiController::class, 'index'])
            ->middleware('api.scope:read:academics')
            ->name('api.v1.partner-institutes.index');
        Route::post('/', [PartnerInstituteApiController::class, 'store'])
            ->middleware('api.scope:write:academics')
            ->name('api.v1.partner-institutes.store');
        Route::get('/{id}', [PartnerInstituteApiController::class, 'show'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.partner-institutes.show');
        Route::put('/{id}', [PartnerInstituteApiController::class, 'update'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.partner-institutes.update');
        Route::delete('/{id}', [PartnerInstituteApiController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.partner-institutes.destroy');
        Route::post('/{id}/restore', [PartnerInstituteApiController::class, 'restore'])
            ->whereNumber('id')
            ->middleware('api.scope:write:academics')
            ->name('api.v1.partner-institutes.restore');
        Route::get('/{id}/faculties', [PartnerInstituteApiController::class, 'faculties'])
            ->whereNumber('id')
            ->middleware('api.scope:read:academics')
            ->name('api.v1.partner-institutes.faculties');
    });

    // 3. Comprehensive Student Management & Admissions System
    Route::prefix('students')->group(function () {
        // Executive Telemetry & Hardware Verification Kiosk
        Route::get('/dashboard', [StudentApiController::class, 'dashboard'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.dashboard');
        Route::get('/statistics', [StudentApiController::class, 'statistics'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.statistics');
        Route::post('/verify-identity', [StudentApiController::class, 'verifyIdentity'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.verify-identity');

        // Academic Structure Management (Batches, Courses, Sections)
        Route::get('/batches', [StudentApiController::class, 'batches'])
            ->middleware('api.scope:read:students,read:academics')
            ->name('api.v1.students.batches.index');
        Route::post('/batches', [StudentApiController::class, 'storeBatch'])
            ->middleware('api.scope:write:students,write:academics')
            ->name('api.v1.students.batches.store');
        Route::get('/courses', [StudentApiController::class, 'courses'])
            ->middleware('api.scope:read:students,read:academics')
            ->name('api.v1.students.courses.index');
        Route::post('/courses', [StudentApiController::class, 'storeCourse'])
            ->middleware('api.scope:write:students,write:academics')
            ->name('api.v1.students.courses.store');
        Route::get('/sections', [StudentApiController::class, 'sections'])
            ->middleware('api.scope:read:students,read:academics')
            ->name('api.v1.students.sections.index');
        Route::post('/sections', [StudentApiController::class, 'storeSection'])
            ->middleware('api.scope:write:students,write:academics')
            ->name('api.v1.students.sections.store');

        // Student Profile Correction Requests Queue
        Route::get('/profile-requests', [StudentApiController::class, 'profileRequests'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.profile-requests.index');
        Route::post('/profile-requests', [StudentApiController::class, 'storeProfileRequest'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.profile-requests.store');
        Route::post('/profile-requests/{id}/review', [StudentApiController::class, 'reviewProfileRequest'])
            ->whereNumber('id')
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.profile-requests.review');

        // Bulk Data Exchange
        Route::post('/import', [StudentApiController::class, 'importStudents'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.import');
        Route::get('/export', [StudentApiController::class, 'exportStudents'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.export');

        // Student Information Sheets (Future-Ready Multi-Platform Cross-Integration)
        Route::get('/information-sheets', [StudentInformationSheetApiController::class, 'index'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.information-sheets.index');
        Route::post('/information-sheets/bulk', [StudentInformationSheetApiController::class, 'bulk'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.information-sheets.bulk');
        Route::match(['get', 'post'], '/information-sheets/download', [StudentInformationSheetApiController::class, 'download'])
            ->middleware('api.scope:read:students,download:students')
            ->name('api.v1.students.information-sheets.download-bulk');
        Route::get('/{identifier}/information-sheet', [StudentInformationSheetApiController::class, 'show'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.information-sheet.show');
        Route::get('/{identifier}/information-sheet/download', [StudentInformationSheetApiController::class, 'download'])
            ->middleware('api.scope:read:students,download:students')
            ->name('api.v1.students.information-sheet.download-single');

        // Students Directory & Dossier CRUD
        Route::get('/', [StudentApiController::class, 'index'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.index');
        Route::post('/', [StudentApiController::class, 'store'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.store');
        Route::get('/{identifier}', [StudentApiController::class, 'show'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.show');
        Route::put('/{identifier}', [StudentApiController::class, 'update'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.update');
        Route::delete('/{identifier}', [StudentApiController::class, 'destroy'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.destroy');

        // Dossier Sub-Resources (Addresses, Qualifications, Documents)
        Route::get('/{identifier}/addresses', [StudentApiController::class, 'addresses'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.addresses.index');
        Route::post('/{identifier}/addresses', [StudentApiController::class, 'storeAddress'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.addresses.store');
        Route::delete('/{identifier}/addresses/{addressId}', [StudentApiController::class, 'destroyAddress'])
            ->whereNumber('addressId')
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.addresses.destroy');

        Route::get('/{identifier}/educations', [StudentApiController::class, 'educations'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.educations.index');
        Route::post('/{identifier}/educations', [StudentApiController::class, 'storeEducation'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.educations.store');
        Route::delete('/{identifier}/educations/{educationId}', [StudentApiController::class, 'destroyEducation'])
            ->whereNumber('educationId')
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.educations.destroy');

        Route::get('/{identifier}/documents', [StudentApiController::class, 'documents'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.students.documents.index');
        Route::post('/{identifier}/documents', [StudentApiController::class, 'storeDocument'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.documents.store');
        Route::post('/{identifier}/documents/{documentId}/verify', [StudentApiController::class, 'verifyDocument'])
            ->whereNumber('documentId')
            ->middleware('api.scope:write:students')
            ->name('api.v1.students.documents.verify');
    });

    // 3.1 Course Enrollments & Admission Lifecycle Management
    Route::prefix('enrollments')->group(function () {
        Route::get('/', [EnrollmentApiController::class, 'index'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.enrollments.index');
        Route::post('/', [EnrollmentApiController::class, 'store'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.store');
        Route::post('/import', [EnrollmentApiController::class, 'importEnrollments'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.import');
        Route::get('/export', [EnrollmentApiController::class, 'exportEnrollments'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.enrollments.export');
        Route::get('/{identifier}', [EnrollmentApiController::class, 'show'])
            ->middleware('api.scope:read:students')
            ->name('api.v1.enrollments.show');
        Route::put('/{identifier}', [EnrollmentApiController::class, 'update'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.update');
        Route::delete('/{identifier}', [EnrollmentApiController::class, 'destroy'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.destroy');
        Route::post('/{identifier}/transfer-section', [EnrollmentApiController::class, 'transferSection'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.transfer-section');
        Route::post('/{identifier}/complete', [EnrollmentApiController::class, 'complete'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.complete');
        Route::post('/{identifier}/discontinue', [EnrollmentApiController::class, 'discontinue'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.discontinue');
        Route::post('/{identifier}/reinstate', [EnrollmentApiController::class, 'reinstate'])
            ->middleware('api.scope:write:students')
            ->name('api.v1.enrollments.reinstate');
    });

    // 4. External Study Materials & Public Lead Capture Integration
    Route::prefix('external/study-materials')->middleware('api.scope:read:materials,download:materials')->group(function () {
        Route::get('/types', [ExternalStudyMaterialApiController::class, 'types'])->name('api.v1.external.study-materials.types');
        Route::get('/', [ExternalStudyMaterialApiController::class, 'index'])->name('api.v1.external.study-materials.index');
        Route::get('/{identifier}', [ExternalStudyMaterialApiController::class, 'show'])->name('api.v1.external.study-materials.show');
        Route::post('/{identifier}/download-request', [ExternalStudyMaterialApiController::class, 'requestDownload'])->name('api.v1.external.study-materials.download-request');
        Route::post('/verify-otp-and-download', [ExternalStudyMaterialApiController::class, 'verifyOtpAndDownload'])->name('api.v1.external.study-materials.verify-otp');
        Route::post('/resend-otp', [ExternalStudyMaterialApiController::class, 'resendOtp'])->name('api.v1.external.study-materials.resend-otp');
    });

    // 5. Comprehensive Dynamic Hostel Management System (Administrative & Operations)
    Route::prefix('hostel')->group(function () {
        // Executive Dashboard & Real-Time Visual Room Board
        Route::get('/dashboard', [HostelApiController::class, 'dashboard'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.dashboard');
        Route::get('/visual-board', [HostelApiController::class, 'visualBoard'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.visual-board');

        // Buildings & Floors
        Route::get('/buildings', [HostelApiController::class, 'buildings'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.buildings.index');
        Route::get('/buildings/{id}', [HostelApiController::class, 'showBuilding'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.buildings.show');
        Route::post('/buildings', [HostelApiController::class, 'storeBuilding'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.buildings.store');
        Route::put('/buildings/{id}', [HostelApiController::class, 'updateBuilding'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.buildings.update');
        Route::delete('/buildings/{id}', [HostelApiController::class, 'destroyBuilding'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.buildings.destroy');

        // Rooms & Beds
        Route::get('/rooms/available', [HostelApiController::class, 'availableRooms'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.rooms.available');
        Route::get('/rooms', [HostelApiController::class, 'rooms'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.rooms.index');
        Route::get('/rooms/{id}', [HostelApiController::class, 'showRoom'])
            ->whereNumber('id')
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.rooms.show');
        Route::post('/rooms', [HostelApiController::class, 'storeRoom'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.rooms.store');
        Route::put('/rooms/{id}', [HostelApiController::class, 'updateRoom'])
            ->whereNumber('id')
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.rooms.update');
        Route::delete('/rooms/{id}', [HostelApiController::class, 'destroyRoom'])
            ->whereNumber('id')
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.rooms.destroy');
        Route::post('/beds/{id}/status', [HostelApiController::class, 'updateBedStatus'])
            ->whereNumber('id')
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.beds.status');
        Route::put('/beds/{id}/status', [HostelApiController::class, 'updateBedStatus'])
            ->whereNumber('id')
            ->middleware('api.scope:write:hostel');

        // Allocations & Stay Management
        Route::get('/allocations', [HostelApiController::class, 'allocations'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.allocations.index');
        Route::get('/allocations/{id}', [HostelApiController::class, 'showAllocation'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.allocations.show');
        Route::post('/allocations', [HostelApiController::class, 'storeAllocation'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.allocations.store');
        Route::post('/allocations/{id}/transfer', [HostelApiController::class, 'transferAllocation'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.allocations.transfer');
        Route::post('/allocations/{id}/extend', [HostelApiController::class, 'extendAllocation'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.allocations.extend');
        Route::post('/allocations/{id}/vacate', [HostelApiController::class, 'vacateAllocation'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.allocations.vacate');
        Route::post('/allocations/{id}/verify-form', [HostelApiController::class, 'verifyAllocationForm'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.allocations.verify-form');

        // Resident Verification & Gatekeeper Kiosk
        Route::post('/verify-resident', [HostelApiController::class, 'verifyResident'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.verify-resident');

        // Caretakers & Wardens Directory
        Route::get('/caretakers', [HostelApiController::class, 'caretakers'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.caretakers.index');
        Route::get('/caretakers/{id}', [HostelApiController::class, 'showCaretaker'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.caretakers.show');
        Route::post('/caretakers', [HostelApiController::class, 'storeCaretaker'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.caretakers.store');
        Route::put('/caretakers/{id}', [HostelApiController::class, 'updateCaretaker'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.caretakers.update');
        Route::delete('/caretakers/{id}', [HostelApiController::class, 'destroyCaretaker'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.caretakers.destroy');

        // Extension Requests (Admin & Staff Review)
        Route::get('/extension-requests', [HostelApiController::class, 'extensionRequests'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.extension-requests.index');
        Route::post('/extension-requests/{id}/review', [HostelApiController::class, 'reviewExtensionRequest'])
            ->middleware('api.scope:write:hostel')
            ->name('api.v1.hostel.extension-requests.review');

        // Analytical Reports
        Route::get('/reports/occupancy', [HostelApiController::class, 'occupancyReport'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.reports.occupancy');
        Route::get('/reports/compliance', [HostelApiController::class, 'complianceReport'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.reports.compliance');
        Route::get('/reports/expiring', [HostelApiController::class, 'expiringReport'])
            ->middleware('api.scope:read:hostel')
            ->name('api.v1.hostel.reports.expiring');
    });

    // 6. Dynamic Study Material Management System (Administrative, Authoring & Telemetry)
    Route::prefix('study-materials')->group(function () {
        // Types Catalog & Types Management (Must be before /{identifier})
        Route::get('/types', [StudyMaterialApiController::class, 'types'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.types.auth');
        Route::post('/types', [StudyMaterialApiController::class, 'storeType'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.types.store');
        Route::put('/types/{id}', [StudyMaterialApiController::class, 'updateType'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.types.update');
        Route::delete('/types/{id}', [StudyMaterialApiController::class, 'destroyType'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.types.destroy');

        // Analytics, Feeds & Course/Batch Groups (Must be before /{identifier})
        Route::get('/statistics', [StudyMaterialApiController::class, 'statistics'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.statistics.auth');
        Route::get('/my-materials', [StudyMaterialApiController::class, 'myMaterials'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.my-materials.auth');
        Route::get('/courses/{course}', [StudyMaterialApiController::class, 'byCourse'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.course.auth');
        Route::get('/batches/{batch}', [StudyMaterialApiController::class, 'byBatch'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.batch.auth');

        // Catalog & Authoring
        Route::get('/', [StudyMaterialApiController::class, 'index'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.index.auth');
        Route::post('/', [StudyMaterialApiController::class, 'store'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.store');

        // Specific Item Actions
        Route::get('/{identifier}', [StudyMaterialApiController::class, 'show'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.show.auth');
        Route::put('/{identifier}', [StudyMaterialApiController::class, 'update'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.update');
        Route::delete('/{identifier}', [StudyMaterialApiController::class, 'destroy'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.destroy');
        Route::post('/{identifier}/files', [StudyMaterialApiController::class, 'uploadFiles'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.upload-files');
        Route::delete('/{identifier}/files/{fileId}', [StudyMaterialApiController::class, 'deleteFile'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.delete-file');
        Route::post('/{identifier}/share-link', [StudyMaterialApiController::class, 'shareLink'])
            ->middleware('api.scope:write:materials')
            ->name('api.v1.study-materials.share-link');
        Route::post('/{identifier}/track-view', [StudyMaterialApiController::class, 'trackView'])
            ->middleware('api.scope:read:materials')
            ->name('api.v1.study-materials.track-view');
        Route::get('/{identifier}/download-token', [StudyMaterialApiController::class, 'downloadToken'])
            ->middleware('api.scope:download:materials')
            ->name('api.v1.study-materials.download-token');
        Route::get('/{identifier}/download-zip', [StudyMaterialApiController::class, 'downloadZip'])
            ->middleware('api.scope:download:materials')
            ->name('api.v1.study-materials.download-zip');
    });

    // 7. Examination Management System (Administrative, Scheduling, Seating & Grading)
    Route::prefix('examinations')->group(function () {
        // Executive Dashboard & Overview
        Route::get('/dashboard', [ExamApiController::class, 'dashboard'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.dashboard');
        Route::post('/verify-admit-card', [ExamApiController::class, 'verifyAdmitCard'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.verify-admit-card');

        // Examination Centers & Room Venues
        Route::get('/centers', [ExamApiController::class, 'centers'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.centers.index');
        Route::post('/centers', [ExamApiController::class, 'storeCenter'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.centers.store');
        Route::get('/centers/{id}', [ExamApiController::class, 'showCenter'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.centers.show');
        Route::put('/centers/{id}', [ExamApiController::class, 'updateCenter'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.centers.update');
        Route::delete('/centers/{id}', [ExamApiController::class, 'destroyCenter'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.centers.destroy');
        Route::post('/centers/{id}/rooms', [ExamApiController::class, 'storeRoom'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.centers.rooms.store');
        Route::delete('/centers/{centerId}/rooms/{roomId}', [ExamApiController::class, 'destroyRoom'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.centers.rooms.destroy');

        // Examinations Directory & Lifecycle
        Route::get('/', [ExamApiController::class, 'index'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.index');
        Route::post('/', [ExamApiController::class, 'store'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.store');
        Route::get('/{identifier}', [ExamApiController::class, 'show'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.show');
        Route::put('/{identifier}', [ExamApiController::class, 'update'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.update');
        Route::delete('/{identifier}', [ExamApiController::class, 'destroy'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.destroy');

        // Seating Arrangement, Hall Allocation, Attendance & Results
        Route::get('/{identifier}/seating-plan', [ExamApiController::class, 'seatingPlan'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.seating-plan');
        Route::post('/{identifier}/allocate-seats', [ExamApiController::class, 'allocateSeats'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.allocate-seats');
        Route::get('/{identifier}/candidates', [ExamApiController::class, 'candidates'])
            ->middleware('api.scope:read:examinations')
            ->name('api.v1.examinations.candidates');
        Route::post('/{identifier}/attendance', [ExamApiController::class, 'markAttendance'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.attendance');
        Route::post('/{identifier}/results', [ExamApiController::class, 'recordResults'])
            ->middleware('api.scope:write:examinations')
            ->name('api.v1.examinations.results');
    });

    // 10. Optional Subjects Management (Administrative)
    Route::prefix('optional-subjects')->group(function () {
        Route::get('/', [OptionalSubjectApiController::class, 'index'])
            ->middleware('api.scope:read:optional_subjects,read:students')
            ->name('api.v1.optional-subjects.index');
        Route::get('/categories', [OptionalSubjectApiController::class, 'categories'])
            ->middleware('api.scope:read:optional_subjects,read:students')
            ->name('api.v1.optional-subjects.categories');
        Route::get('/allocations', [OptionalSubjectApiController::class, 'allocations'])
            ->middleware('api.scope:read:optional_subjects,read:students')
            ->name('api.v1.optional-subjects.allocations');
        Route::post('/quick-update', [OptionalSubjectApiController::class, 'quickUpdate'])
            ->middleware('api.scope:write:optional_subjects')
            ->name('api.v1.optional-subjects.quick-update');
        Route::get('/requests', [OptionalSubjectApiController::class, 'requests'])
            ->middleware('api.scope:read:optional_subjects,read:students')
            ->name('api.v1.optional-subjects.requests');
        Route::post('/requests/{id}/review', [OptionalSubjectApiController::class, 'reviewRequest'])
            ->middleware('api.scope:write:optional_subjects')
            ->name('api.v1.optional-subjects.requests.review');
    });

    // 11. Student ID Cards Management (Administrative)
    Route::prefix('student-id-cards')->group(function () {
        Route::get('/settings', [StudentIdCardApiController::class, 'settings'])
            ->middleware('api.scope:read:id_cards,read:students')
            ->name('api.v1.student-id-cards.settings');
        Route::get('/{identifier}/payload', [StudentIdCardApiController::class, 'payload'])
            ->middleware('api.scope:read:id_cards,read:students')
            ->name('api.v1.student-id-cards.payload');
        Route::get('/{identifier}/download', [StudentIdCardApiController::class, 'download'])
            ->middleware('api.scope:download:id_cards,read:id_cards')
            ->name('api.v1.student-id-cards.download');
    });

    // 12. Library Management System (Administrative, Catalog, Physical Accession, Circulation & Fines)
    Route::prefix('library')->group(function () {
        // Executive Dashboard & Telemetry
        Route::get('/dashboard', [LibraryApiController::class, 'dashboard'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.dashboard');
        Route::get('/statistics', [LibraryApiController::class, 'statistics'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.statistics');

        // Book Catalog Management
        Route::get('/books', [LibraryApiController::class, 'indexBooks'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.books.index');
        Route::post('/books', [LibraryApiController::class, 'storeBook'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.books.store');
        Route::get('/books/{identifier}', [LibraryApiController::class, 'showBook'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.books.show');
        Route::put('/books/{identifier}', [LibraryApiController::class, 'updateBook'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.books.update');
        Route::delete('/books/{identifier}', [LibraryApiController::class, 'destroyBook'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.books.destroy');
        Route::get('/books/{identifier}/copies', [LibraryApiController::class, 'copies'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.books.copies');
        Route::post('/books/{identifier}/copies', [LibraryApiController::class, 'storeCopy'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.books.copies.store');

        // Physical Copies & Barcode / RFID Inventory
        Route::get('/copies/{identifier}', [LibraryApiController::class, 'showCopy'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.copies.show');
        Route::post('/copies/{identifier}/status', [LibraryApiController::class, 'updateCopyStatus'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.copies.status');
        Route::delete('/copies/{identifier}', [LibraryApiController::class, 'destroyCopy'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.copies.destroy');

        // Circulation (Loans, Returns & Renewals)
        Route::get('/issues', [LibraryApiController::class, 'indexIssues'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.issues.index');
        Route::post('/issues', [LibraryApiController::class, 'issueBook'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.issues.store');
        Route::post('/issues/{id}/return', [LibraryApiController::class, 'returnBook'])
            ->whereNumber('id')
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.issues.return');
        Route::post('/issues/{id}/renew', [LibraryApiController::class, 'renewBook'])
            ->whereNumber('id')
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.issues.renew');
        Route::post('/issues/{id}/request-renewal', [LibraryApiController::class, 'requestLoanRenewal'])
            ->whereNumber('id')
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.issues.request-renewal');
        Route::post('/issues/{id}/review-renewal', [LibraryApiController::class, 'reviewLoanRenewal'])
            ->whereNumber('id')
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.issues.review-renewal');

        // In-House Reading Room Facility (Day Use)
        Route::post('/in-house/issue', [LibraryApiController::class, 'issueInHouse'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.in-house.issue');
        Route::post('/in-house/return', [LibraryApiController::class, 'returnInHouse'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.in-house.return');

        // Reservations & Holds Queue
        Route::get('/reservations', [LibraryApiController::class, 'indexReservations'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.reservations.index');
        Route::post('/reservations', [LibraryApiController::class, 'storeReservation'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.reservations.store');
        Route::post('/reservations/{id}/cancel', [LibraryApiController::class, 'cancelReservation'])
            ->whereNumber('id')
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.reservations.cancel');

        // Overdue Fines & Waivers
        Route::get('/fines', [LibraryApiController::class, 'indexFines'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.fines.index');
        Route::post('/fines/{id}/pay', [LibraryApiController::class, 'payFine'])
            ->whereNumber('id')
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.fines.pay');
        Route::post('/fines/{id}/waive', [LibraryApiController::class, 'waiveFine'])
            ->whereNumber('id')
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.fines.waive');

        // Library Members & Security Deposit Clearance
        Route::get('/members', [LibraryApiController::class, 'indexMembers'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.members.index');
        Route::get('/members/{identifier}', [LibraryApiController::class, 'showMember'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.members.show');
        Route::get('/clearance/check/{identifier}', [LibraryApiController::class, 'checkClearance'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.clearance.check');
        Route::post('/clearance/issue', [LibraryApiController::class, 'issueClearance'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.clearance.issue');

        // Metadata Taxonomy
        Route::get('/categories', [LibraryApiController::class, 'categories'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.categories.index');
        Route::post('/categories', [LibraryApiController::class, 'storeCategory'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.categories.store');
        Route::get('/authors', [LibraryApiController::class, 'authors'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.authors.index');
        Route::post('/authors', [LibraryApiController::class, 'storeAuthor'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.authors.store');
        Route::get('/publishers', [LibraryApiController::class, 'publishers'])
            ->middleware('api.scope:read:library')
            ->name('api.v1.library.publishers.index');
        Route::post('/publishers', [LibraryApiController::class, 'storePublisher'])
            ->middleware('api.scope:write:library')
            ->name('api.v1.library.publishers.store');
    });

    // 13. Comprehensive Fee & Payment Collection System Module (Administrative, Invoicing & Financial Operations)
    Route::prefix('payments')->group(function () {
        // Executive Telemetry & Deep Analytics
        Route::get('/dashboard', [PaymentApiController::class, 'dashboard'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.dashboard');
        Route::get('/statistics', [PaymentApiController::class, 'statistics'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.statistics');

        // Fee Types Catalog (CRUD)
        Route::get('/fee-types', [PaymentApiController::class, 'feeTypes'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.fee-types.index');
        Route::post('/fee-types', [PaymentApiController::class, 'storeFeeType'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.fee-types.store');
        Route::get('/fee-types/{id}', [PaymentApiController::class, 'showFeeType'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.fee-types.show');
        Route::put('/fee-types/{id}', [PaymentApiController::class, 'updateFeeType'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.fee-types.update');
        Route::delete('/fee-types/{id}', [PaymentApiController::class, 'destroyFeeType'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.fee-types.destroy');

        // Fee Schedules & Batch Invoicing (CRUD & Lifecycle)
        Route::get('/schedules', [PaymentApiController::class, 'schedules'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.schedules.index');
        Route::post('/schedules', [PaymentApiController::class, 'storeSchedule'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.schedules.store');
        Route::get('/schedules/{id}', [PaymentApiController::class, 'showSchedule'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.schedules.show');
        Route::post('/schedules/{id}/reminders', [PaymentApiController::class, 'sendScheduleReminders'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.schedules.reminders');
        Route::post('/schedules/{id}/revert', [PaymentApiController::class, 'revertSchedule'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.schedules.revert');
        Route::delete('/schedules/{id}', [PaymentApiController::class, 'destroySchedule'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.schedules.destroy');

        // Student Fee Invoices (CRUD & Lifecycle)
        Route::get('/invoices', [PaymentApiController::class, 'invoices'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.invoices.index');
        Route::post('/invoices', [PaymentApiController::class, 'storeInvoice'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.invoices.store');
        Route::get('/invoices/{identifier}', [PaymentApiController::class, 'showInvoice'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.invoices.show');
        Route::put('/invoices/{identifier}', [PaymentApiController::class, 'updateInvoice'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.invoices.update');
        Route::post('/invoices/{identifier}/cancel', [PaymentApiController::class, 'cancelInvoice'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.invoices.cancel');
        Route::delete('/invoices/{identifier}', [PaymentApiController::class, 'destroyInvoice'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.invoices.destroy');
        Route::post('/invoices/{identifier}/remind', [PaymentApiController::class, 'sendInvoiceReminder'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.invoices.remind');

        // Payment Transactions & Counter Collection
        Route::get('/transactions', [PaymentApiController::class, 'transactions'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.transactions.index');
        Route::post('/transactions/collect', [PaymentApiController::class, 'collectPayment'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.transactions.collect');
        Route::post('/transactions/offline', [PaymentApiController::class, 'recordOfflinePayment'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.transactions.offline');
        Route::post('/transactions/{id}/verify', [PaymentApiController::class, 'verifyOfflinePayment'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.transactions.verify');
        Route::get('/transactions/{identifier}', [PaymentApiController::class, 'showTransaction'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.transactions.show');
        Route::get('/transactions/{identifier}/receipt', [PaymentApiController::class, 'downloadReceipt'])
            ->middleware('api.scope:read:payments,download:receipts')
            ->name('api.v1.payments.transactions.receipt');

        // Online Payment Gateway Checkout Flow
        Route::post('/checkout/order', [PaymentApiController::class, 'createCheckoutOrder'])
            ->middleware('api.scope:read:payments,write:payments')
            ->name('api.v1.payments.checkout.order');
        Route::post('/checkout/verify', [PaymentApiController::class, 'verifyCheckoutPayment'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.checkout.verify');

        // CCAvenue Omnichannel Gateway API Endpoints
        Route::post('/ccavenue/initiate', [PaymentApiController::class, 'initiateCcavenueCheckout'])
            ->middleware('api.scope:read:payments,write:payments')
            ->name('api.v1.payments.ccavenue.initiate');
        Route::post('/ccavenue/sync-status', [PaymentApiController::class, 'syncCcavenueStatus'])
            ->middleware('api.scope:read:payments,write:payments')
            ->name('api.v1.payments.ccavenue.sync');
        Route::get('/gateways/ccavenue/config', [PaymentApiController::class, 'getCcavenueConfig'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.gateways.ccavenue.config');
        Route::post('/gateways/ccavenue/test-encryption', [PaymentApiController::class, 'testCcavenueEncryption'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.gateways.ccavenue.test-encryption');

        // Programmatic Refund & Admin Reconciliation API
        Route::post('/transactions/{identifier}/refund', [PaymentApiController::class, 'refundTransaction'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.transactions.refund');
        Route::post('/transactions/{identifier}/reconcile', [PaymentApiController::class, 'reconcileTransaction'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.transactions.reconcile');

        // Student Self-Service Feeds
        Route::get('/my-invoices', [PaymentApiController::class, 'myInvoices'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.my-invoices');
        Route::get('/my-transactions', [PaymentApiController::class, 'myTransactions'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.my-transactions');
        Route::get('/my-ledger', [PaymentApiController::class, 'myLedger'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.my-ledger');
        Route::post('/my-offline-payment', [PaymentApiController::class, 'myOfflinePayment'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.my-offline-payment');

        // Financial Ledgers & Clearance Verification API
        Route::get('/ledgers', [PaymentApiController::class, 'ledgers'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.ledgers.index');
        Route::get('/students/{studentId}/ledger', [PaymentApiController::class, 'studentLedger'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.students.ledger');
        Route::get('/students/{studentId}/clearance', [PaymentApiController::class, 'studentClearance'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.students.clearance');

        // Library Security Deposits Management
        Route::get('/library-deposits', [PaymentApiController::class, 'libraryDeposits'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.library-deposits.index');
        Route::post('/library-deposits', [PaymentApiController::class, 'storeLibraryDeposit'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.library-deposits.store');
        Route::post('/library-deposits/{id}/request-refund', [PaymentApiController::class, 'requestDepositRefund'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.library-deposits.request-refund');
        Route::post('/library-deposits/{id}/approve-refund', [PaymentApiController::class, 'approveDepositRefund'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.library-deposits.approve-refund');
        Route::post('/library-deposits/{id}/reject-refund', [PaymentApiController::class, 'rejectDepositRefund'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.library-deposits.reject-refund');
        Route::post('/library-deposits/{id}/disburse', [PaymentApiController::class, 'disburseDepositRefund'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.library-deposits.disburse');

        // Multi-Platform Export & Bulk Reconciliation
        Route::get('/export', [PaymentApiController::class, 'exportTransactions'])
            ->middleware('api.scope:read:payments')
            ->name('api.v1.payments.export');
        Route::post('/reconciliation/import', [PaymentApiController::class, 'importReconciliation'])
            ->middleware('api.scope:write:payments')
            ->name('api.v1.payments.reconciliation.import');
    });

    // Top-Level 'fees' Alias to ensure /api/v1/fees/* parity
    Route::prefix('fees')->group(function () {
        Route::get('/dashboard', [PaymentApiController::class, 'dashboard'])->middleware('api.scope:read:payments');
        Route::get('/statistics', [PaymentApiController::class, 'statistics'])->middleware('api.scope:read:payments');
        Route::get('/types', [PaymentApiController::class, 'feeTypes'])->middleware('api.scope:read:payments');
        Route::post('/types', [PaymentApiController::class, 'storeFeeType'])->middleware('api.scope:write:payments');
        Route::get('/types/{id}', [PaymentApiController::class, 'showFeeType'])->middleware('api.scope:read:payments');
        Route::put('/types/{id}', [PaymentApiController::class, 'updateFeeType'])->middleware('api.scope:write:payments');
        Route::delete('/types/{id}', [PaymentApiController::class, 'destroyFeeType'])->middleware('api.scope:write:payments');
        Route::get('/schedules', [PaymentApiController::class, 'schedules'])->middleware('api.scope:read:payments');
        Route::post('/schedules', [PaymentApiController::class, 'storeSchedule'])->middleware('api.scope:write:payments');
        Route::get('/schedules/{id}', [PaymentApiController::class, 'showSchedule'])->middleware('api.scope:read:payments');
        Route::get('/invoices', [PaymentApiController::class, 'invoices'])->middleware('api.scope:read:payments');
        Route::post('/invoices', [PaymentApiController::class, 'storeInvoice'])->middleware('api.scope:write:payments');
        Route::get('/invoices/{identifier}', [PaymentApiController::class, 'showInvoice'])->middleware('api.scope:read:payments');
        Route::get('/transactions', [PaymentApiController::class, 'transactions'])->middleware('api.scope:read:payments');
        Route::post('/collect', [PaymentApiController::class, 'collectPayment'])->middleware('api.scope:write:payments');
        Route::get('/students/{studentId}/clearance', [PaymentApiController::class, 'studentClearance'])->middleware('api.scope:read:payments');
    });

    // 10. Attendance Management & Biometric Gate Ingestion System
    Route::prefix('attendance')->group(function () {
        // Executive Telemetry & Real-Time Analytics
        Route::get('/dashboard', [AttendanceApiController::class, 'dashboard'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.dashboard');
        Route::get('/statistics', [AttendanceApiController::class, 'statistics'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.statistics');
        Route::get('/defaulters', [AttendanceApiController::class, 'defaulters'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.defaulters');
        Route::post('/defaulters/notify', [AttendanceApiController::class, 'notifyDefaulters'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.defaulters.notify');

        // Mobile & Self-Service Clock-In / Clock-Out
        Route::post('/clock-in', [AttendanceApiController::class, 'clockIn'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.clock-in');
        Route::post('/clock-out', [AttendanceApiController::class, 'clockOut'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.clock-out');
        Route::get('/my-attendance', [AttendanceApiController::class, 'myAttendance'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.my-attendance');

        // Hardware Punch Ingestion (Turnstiles, Kiosks, RFID Scanners & Biometrics)
        Route::post('/punch', [AttendanceApiController::class, 'punch'])
            ->middleware('api.scope:write:biometric,write:attendance')
            ->name('api.v1.attendance.punch');
        Route::post('/biometric/batch-punch', [AttendanceApiController::class, 'batchPunch'])
            ->middleware('api.scope:write:biometric,write:attendance')
            ->name('api.v1.attendance.biometric.batch-punch');
        Route::post('/batch-punch', [AttendanceApiController::class, 'batchPunch'])
            ->middleware('api.scope:write:biometric,write:attendance')
            ->name('api.v1.attendance.batch-punch');
        Route::post('/biometric/sync', [AttendanceApiController::class, 'syncPunches'])
            ->middleware('api.scope:write:attendance,write:biometric')
            ->name('api.v1.attendance.biometric.sync');

        // User Monthly Attendance Matrix
        Route::get('/matrix', [AttendanceApiController::class, 'index'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.matrix.all');
        Route::get('/users/{userId}/matrix', [AttendanceApiController::class, 'userMatrix'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.user-matrix');

        // Export & Import Spreadsheets
        Route::get('/export/csv', [AttendanceApiController::class, 'exportCsv'])
            ->middleware('api.scope:read:attendance,download:attendance')
            ->name('api.v1.attendance.export.csv');
        Route::get('/export/pdf', [AttendanceApiController::class, 'exportPdf'])
            ->middleware('api.scope:read:attendance,download:attendance')
            ->name('api.v1.attendance.export.pdf');
        Route::post('/import', [AttendanceApiController::class, 'import'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.import');
        Route::get('/sample-csv', [AttendanceApiController::class, 'sampleCsv'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.sample-csv');

        // Biometric Devices Management (CRUD)
        Route::get('/devices', [AttendanceApiController::class, 'devices'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.devices.index');
        Route::post('/devices', [AttendanceApiController::class, 'storeDevice'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.devices.store');
        Route::get('/devices/{id}', [AttendanceApiController::class, 'showDevice'])
            ->whereNumber('id')
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.devices.show');
        Route::put('/devices/{id}', [AttendanceApiController::class, 'updateDevice'])
            ->whereNumber('id')
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.devices.update');
        Route::delete('/devices/{id}', [AttendanceApiController::class, 'destroyDevice'])
            ->whereNumber('id')
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.devices.destroy');
        Route::post('/devices/{id}/regenerate-token', [AttendanceApiController::class, 'regenerateDeviceToken'])
            ->whereNumber('id')
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.devices.regenerate-token');

        // Working Calendars & Shifts (CRUD)
        Route::get('/calendars', [AttendanceApiController::class, 'calendars'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.calendars.index');
        Route::post('/calendars', [AttendanceApiController::class, 'storeCalendar'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.calendars.store');
        Route::get('/calendars/{id}', [AttendanceApiController::class, 'showCalendar'])
            ->whereNumber('id')
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.calendars.show');
        Route::put('/calendars/{id}', [AttendanceApiController::class, 'updateCalendar'])
            ->whereNumber('id')
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.calendars.update');
        Route::delete('/calendars/{id}', [AttendanceApiController::class, 'destroyCalendar'])
            ->whereNumber('id')
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.calendars.destroy');

        // Attendance Holidays & Academic Off-Days (CRUD)
        Route::get('/holidays', [AttendanceApiController::class, 'holidays'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.holidays.index');
        Route::post('/holidays', [AttendanceApiController::class, 'storeHoliday'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.holidays.store');
        Route::get('/holidays/{id}', [AttendanceApiController::class, 'showHoliday'])
            ->whereNumber('id')
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.holidays.show');
        Route::put('/holidays/{id}', [AttendanceApiController::class, 'updateHoliday'])
            ->whereNumber('id')
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.holidays.update');
        Route::delete('/holidays/{id}', [AttendanceApiController::class, 'destroyHoliday'])
            ->whereNumber('id')
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.holidays.destroy');

        // Master Attendance Register (CRUD & Bulk Marking)
        Route::get('/register', [AttendanceApiController::class, 'index'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.register');
        Route::get('/', [AttendanceApiController::class, 'index'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.index');
        Route::post('/mark', [AttendanceApiController::class, 'mark'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.mark');
        Route::post('/bulk-mark', [AttendanceApiController::class, 'bulkMark'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.bulk-mark');
        Route::get('/{identifier}', [AttendanceApiController::class, 'show'])
            ->middleware('api.scope:read:attendance')
            ->name('api.v1.attendance.show');
        Route::put('/{identifier}', [AttendanceApiController::class, 'update'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.update');
        Route::delete('/{identifier}', [AttendanceApiController::class, 'destroy'])
            ->middleware('api.scope:write:attendance')
            ->name('api.v1.attendance.destroy');
    });

    // 11. Leave Management System
    Route::prefix('leaves')->group(function () {
        // Executive Telemetry & Analytics
        Route::get('/dashboard', [LeaveApiController::class, 'dashboard'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.dashboard');
        Route::get('/statistics', [LeaveApiController::class, 'statistics'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.statistics');

        // Self-Service Portal Feeds
        Route::get('/my-balances', [LeaveApiController::class, 'myBalances'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.my-balances');
        Route::get('/my-applications', [LeaveApiController::class, 'myApplications'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.my-applications');
        Route::post('/apply', [LeaveApiController::class, 'apply'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.apply');
        Route::post('/my-applications/{identifier}/cancel', [LeaveApiController::class, 'cancelMyApplication'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.my-applications.cancel');

        // Dynamic Leave Categories (CRUD)
        Route::get('/categories', [LeaveApiController::class, 'categories'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.categories.index');
        Route::post('/categories', [LeaveApiController::class, 'storeCategory'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.categories.store');
        Route::get('/categories/{id}', [LeaveApiController::class, 'showCategory'])
            ->whereNumber('id')
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.categories.show');
        Route::put('/categories/{id}', [LeaveApiController::class, 'updateCategory'])
            ->whereNumber('id')
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.categories.update');
        Route::delete('/categories/{id}', [LeaveApiController::class, 'destroyCategory'])
            ->whereNumber('id')
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.categories.destroy');

        // Leave Allocations & Balances
        Route::get('/allocations', [LeaveApiController::class, 'allocations'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.allocations.index');
        Route::post('/allocations', [LeaveApiController::class, 'storeAllocation'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.allocations.store');
        Route::get('/allocations/{id}', [LeaveApiController::class, 'showAllocation'])
            ->whereNumber('id')
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.allocations.show');
        Route::put('/allocations/{id}', [LeaveApiController::class, 'updateAllocation'])
            ->whereNumber('id')
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.allocations.update');
        Route::get('/users/{userId}/balances', [LeaveApiController::class, 'userBalances'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.user-balances');
        Route::get('/balances/{userId}', [LeaveApiController::class, 'userBalances'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.balances');

        // Leave Applications Management & Workflow State Machine
        Route::get('/applications', [LeaveApiController::class, 'applications'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.applications.index');
        Route::post('/applications', [LeaveApiController::class, 'storeApplication'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.applications.store');
        Route::get('/applications/{identifier}', [LeaveApiController::class, 'showApplication'])
            ->middleware('api.scope:read:leaves')
            ->name('api.v1.leaves.applications.show');
        Route::put('/applications/{identifier}', [LeaveApiController::class, 'updateApplication'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.applications.update');
        Route::post('/applications/{identifier}/approve', [LeaveApiController::class, 'approve'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.applications.approve');
        Route::post('/applications/{identifier}/reject', [LeaveApiController::class, 'reject'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.applications.reject');
        Route::post('/applications/{identifier}/cancel', [LeaveApiController::class, 'cancel'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.applications.cancel');
        Route::delete('/applications/{identifier}', [LeaveApiController::class, 'destroyApplication'])
            ->middleware('api.scope:write:leaves')
            ->name('api.v1.leaves.applications.destroy');
    });

    // 13. Comprehensive Users & Access Management System (RBAC - 33 Endpoints)
    Route::prefix('users')->group(function () {
        // Executive Telemetry & Granular Statistics
        Route::get('/dashboard', [UserApiController::class, 'dashboard'])
            ->middleware('api.scope:read:users')
            ->name('api.v1.users.dashboard');
        Route::get('/statistics', [UserApiController::class, 'statistics'])
            ->middleware('api.scope:read:users')
            ->name('api.v1.users.statistics');

        // Bulk Operations & Data Exchange
        Route::get('/export', [UserApiController::class, 'export'])
            ->middleware('api.scope:read:users')
            ->name('api.v1.users.export');
        Route::post('/import', [UserApiController::class, 'import'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.import');
        Route::post('/bulk-status', [UserApiController::class, 'bulkStatus'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.bulk-status');

        // User Registry & CRUD
        Route::get('/', [UserApiController::class, 'index'])
            ->middleware('api.scope:read:users')
            ->name('api.v1.users.index');
        Route::post('/', [UserApiController::class, 'store'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.store');
        Route::get('/{identifier}', [UserApiController::class, 'show'])
            ->middleware('api.scope:read:users')
            ->name('api.v1.users.show');
        Route::put('/{identifier}', [UserApiController::class, 'update'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.update');
        Route::delete('/{identifier}', [UserApiController::class, 'destroy'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.destroy');
        Route::post('/{identifier}/restore', [UserApiController::class, 'restore'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.restore');
        Route::delete('/{identifier}/force-delete', [UserApiController::class, 'forceDelete'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.force-delete');

        // Security Operations, Status & Credentials
        Route::post('/{identifier}/status', [UserApiController::class, 'updateStatus'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.status');
        Route::post('/{identifier}/unlock', [UserApiController::class, 'unlock'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.unlock');
        Route::post('/{identifier}/reset-password', [UserApiController::class, 'resetPassword'])
            ->middleware('api.scope:write:users')
            ->name('api.v1.users.reset-password');
        Route::post('/{identifier}/sync-roles', [UserApiController::class, 'syncRoles'])
            ->middleware('api.scope:manage:roles,write:users')
            ->name('api.v1.users.sync-roles');
        Route::get('/{identifier}/audit-logs', [UserApiController::class, 'userAuditLogs'])
            ->middleware('api.scope:read:users')
            ->name('api.v1.users.audit-logs');
    });

    // Roles Management System
    Route::prefix('roles')->group(function () {
        Route::get('/', [UserApiController::class, 'rolesIndex'])
            ->middleware('api.scope:read:users,manage:roles')
            ->name('api.v1.roles.index');
        Route::post('/', [UserApiController::class, 'rolesStore'])
            ->middleware('api.scope:manage:roles')
            ->name('api.v1.roles.store');
        Route::get('/{id}', [UserApiController::class, 'rolesShow'])
            ->middleware('api.scope:read:users,manage:roles')
            ->name('api.v1.roles.show');
        Route::put('/{id}', [UserApiController::class, 'rolesUpdate'])
            ->middleware('api.scope:manage:roles')
            ->name('api.v1.roles.update');
        Route::delete('/{id}', [UserApiController::class, 'rolesDestroy'])
            ->middleware('api.scope:manage:roles')
            ->name('api.v1.roles.destroy');
        Route::post('/{id}/clone', [UserApiController::class, 'rolesClone'])
            ->middleware('api.scope:manage:roles')
            ->name('api.v1.roles.clone');
        Route::post('/{id}/sync-permissions', [UserApiController::class, 'rolesSyncPermissions'])
            ->middleware('api.scope:manage:roles')
            ->name('api.v1.roles.sync-permissions');
    });

    // Permissions Management System
    Route::prefix('permissions')->group(function () {
        Route::get('/', [UserApiController::class, 'permissionsIndex'])
            ->middleware('api.scope:read:users,manage:permissions')
            ->name('api.v1.permissions.index');
        Route::get('/modules', [UserApiController::class, 'permissionsModules'])
            ->middleware('api.scope:read:users,manage:permissions')
            ->name('api.v1.permissions.modules');
        Route::post('/', [UserApiController::class, 'permissionsStore'])
            ->middleware('api.scope:manage:permissions')
            ->name('api.v1.permissions.store');
        Route::put('/{id}', [UserApiController::class, 'permissionsUpdate'])
            ->middleware('api.scope:manage:permissions')
            ->name('api.v1.permissions.update');
        Route::delete('/{id}', [UserApiController::class, 'permissionsDestroy'])
            ->middleware('api.scope:manage:permissions')
            ->name('api.v1.permissions.destroy');
    });

    // Current User Self-Service (Me)
    Route::prefix('user')->group(function () {
        Route::get('/me', [UserApiController::class, 'me'])
            ->name('api.v1.user.me');
        Route::get('/profile', [UserApiController::class, 'me'])
            ->name('api.v1.user.profile');
        Route::put('/profile', [UserApiController::class, 'updateProfile'])
            ->name('api.v1.user.profile.update');
        Route::post('/change-password', [UserApiController::class, 'changePassword'])
            ->name('api.v1.user.change-password');
    });

    // System-Wide Security Audit Logs
    Route::get('/security/audit-logs', [UserApiController::class, 'securityAuditLogs'])
        ->middleware('api.scope:read:users')
        ->name('api.v1.security.audit-logs');
});

// Secure Token-Based Binary File Download (Directly streamable by browser/mobile apps)
Route::get('v1/external/study-materials/download/{token}', [ExternalStudyMaterialApiController::class, 'downloadFile'])
    ->name('api.v1.external.study-materials.download');

Route::get('v1/study-materials/download/{token}', [StudyMaterialApiController::class, 'downloadFileWithToken'])
    ->name('api.v1.study-materials.token-download');

// Streamable Hostel Form Download (Personalized PDF)
Route::get('v1/hostel/form/download', [HostelApiController::class, 'downloadForm'])
    ->middleware(['auth.api_key:optional'])
    ->name('api.v1.hostel.form.download');

// Streamable Official Library Card PDF Download
Route::get('v1/library/members/{identifier}/card/download', [LibraryApiController::class, 'downloadCard'])
    ->middleware(['auth.api_key:optional'])
    ->name('api.v1.library.members.card.download');

// Streamable Official Fee Receipt PDF Download
Route::get('v1/payments/receipts/{receiptNo}/download', [PaymentApiController::class, 'downloadReceipt'])
    ->middleware(['auth.api_key:optional'])
    ->name('api.v1.payments.receipt.download');

Route::get('v1/fees/receipts/{receiptNo}/download', [PaymentApiController::class, 'downloadReceipt'])
    ->middleware(['auth.api_key:optional'])
    ->name('api.v1.fees.receipt.download');

// Resources with Optional API Key Authentication (Public catalog with authenticated rate-limit & scopes)
Route::prefix('v1')->middleware(['auth.api_key:optional'])->group(function () {
    // CCAvenue Omnichannel Callback & Server Webhook (External Gateway Response / Mobile WebView)
    Route::post('/payments/ccavenue/callback', [PaymentApiController::class, 'handleCcavenueCallback'])
        ->name('api.v1.payments.ccavenue.callback');

    // 0. Public Bootstrap & Client Configuration (Mobile, Web, Desktop)
    Route::get('/settings/public', [SystemSettingsApiController::class, 'publicBootstrap'])
        ->name('api.v1.settings.public');
    Route::get('/settings/bootstrap', [SystemSettingsApiController::class, 'publicBootstrap'])
        ->name('api.v1.settings.bootstrap');
    Route::get('/settings/bootstrap/{platform}', [SystemSettingsApiController::class, 'platformBootstrap'])
        ->where('platform', '^[a-zA-Z0-9_\-]+$')
        ->name('api.v1.settings.bootstrap.platform');
    Route::post('/settings/version-check', [SystemSettingsApiController::class, 'versionCheck'])
        ->name('api.v1.settings.version-check');
    Route::get('/settings/features', [SystemSettingsApiController::class, 'features'])
        ->name('api.v1.settings.features');
    // 4. Study Materials
    Route::prefix('study-materials')->middleware('api.scope:read:materials')->group(function () {
        Route::get('/', [StudyMaterialApiController::class, 'index'])->name('api.v1.study-materials.index');
        Route::get('/types', [StudyMaterialApiController::class, 'types'])->name('api.v1.study-materials.types');
        Route::get('/statistics', [StudyMaterialApiController::class, 'statistics'])->name('api.v1.study-materials.statistics');
        Route::get('/my-materials', [StudyMaterialApiController::class, 'myMaterials'])->name('api.v1.study-materials.my-materials');
        Route::get('/courses/{course}', [StudyMaterialApiController::class, 'byCourse'])->name('api.v1.study-materials.course');
        Route::get('/batches/{batch}', [StudyMaterialApiController::class, 'byBatch'])->name('api.v1.study-materials.batch');
        Route::get('/{identifier}', [StudyMaterialApiController::class, 'show'])->name('api.v1.study-materials.show');
        Route::get('/{identifier}/download-zip', [StudyMaterialApiController::class, 'downloadZip'])->name('api.v1.study-materials.download-zip.public');
    });

    // 5. Alumni Directory
    Route::prefix('alumni')->middleware('api.scope:read:alumni')->group(function () {
        Route::get('/', [AlumniApiController::class, 'index'])->name('api.v1.alumni.index');
        Route::get('/categories', [AlumniApiController::class, 'categories'])->name('api.v1.alumni.categories');
        Route::get('/featured', [AlumniApiController::class, 'featured'])->name('api.v1.alumni.featured');
        Route::get('/statistics', [AlumniApiController::class, 'statistics'])->name('api.v1.alumni.statistics');
        Route::get('/{identifier}', [AlumniApiController::class, 'show'])->name('api.v1.alumni.show');
    });

    // 6. Resident Personal Portal & Policies (Mobile & Web App Integrations)
    Route::prefix('hostel')->group(function () {
        Route::get('/policies', [HostelApiController::class, 'policies'])->name('api.v1.hostel.policies');
        Route::get('/catalog', [HostelApiController::class, 'catalog'])->name('api.v1.hostel.catalog');
        Route::get('/rooms/available', [HostelApiController::class, 'availableRooms'])->name('api.v1.hostel.rooms.available.public');
        Route::get('/my-accommodation', [HostelApiController::class, 'myAccommodation'])->name('api.v1.hostel.my-accommodation');
        Route::get('/my-accommodation/roommates', [HostelApiController::class, 'myRoommates'])->name('api.v1.hostel.my-accommodation.roommates');
        Route::post('/my-accommodation/extension-request', [HostelApiController::class, 'submitExtensionRequest'])->name('api.v1.hostel.my-accommodation.extension-request');
        Route::post('/my-accommodation/upload-form', [HostelApiController::class, 'uploadSoftCopy'])->name('api.v1.hostel.my-accommodation.upload-form');
        Route::get('/my-accommodation/extension-requests', [HostelApiController::class, 'myExtensionRequests'])->name('api.v1.hostel.my-accommodation.extension-requests');
        Route::post('/verify-resident', [HostelApiController::class, 'verifyResident'])->name('api.v1.hostel.verify-resident.public');
    });

    // 7. Examination Portal & Admit Card Self-Service
    Route::prefix('examinations')->group(function () {
        Route::get('/my-exams', [ExamApiController::class, 'myExams'])->name('api.v1.examinations.my-exams');
        Route::get('/{identifier}/admit-card', [ExamApiController::class, 'admitCard'])->name('api.v1.examinations.admit-card');
    });

    // 8. Student Tests & Examinations Performance Management System
    Route::prefix('student-tests')->group(function () {
        Route::get('/dashboard', [StudentTestApiController::class, 'dashboard'])->name('api.v1.student-tests.dashboard');
        Route::get('/', [StudentTestApiController::class, 'index'])->name('api.v1.student-tests.index');
        Route::post('/', [StudentTestApiController::class, 'store'])->name('api.v1.student-tests.store');
        Route::get('/rankers', [StudentTestApiController::class, 'rankers'])->name('api.v1.student-tests.rankers');
        Route::get('/my-tests', [StudentTestApiController::class, 'myTests'])->name('api.v1.student-tests.my-tests');
        Route::get('/participation-stats', [StudentTestApiController::class, 'participationStats'])->name('api.v1.student-tests.participation-stats');
        Route::get('/results/{resultId}/marksheet', [StudentTestApiController::class, 'marksheet'])->name('api.v1.student-tests.marksheet');
        Route::get('/{identifier}', [StudentTestApiController::class, 'show'])->name('api.v1.student-tests.show');
        Route::get('/{identifier}/results', [StudentTestApiController::class, 'results'])->name('api.v1.student-tests.results');
        Route::post('/{identifier}/results', [StudentTestApiController::class, 'recordScores'])->name('api.v1.student-tests.record-scores');
        Route::post('/{identifier}/import-scores', [StudentTestApiController::class, 'importScores'])->name('api.v1.student-tests.import-scores');
        Route::get('/{identifier}/leaderboard', [StudentTestApiController::class, 'leaderboard'])->name('api.v1.student-tests.leaderboard');
        Route::get('/{identifier}/section-comparison', [StudentTestApiController::class, 'sectionComparison'])->name('api.v1.student-tests.section-comparison');
    });

    // 9. Academic Course Catalog (Public / Mobile App Prospect Intake)
    Route::prefix('academics')->group(function () {
        Route::get('/catalog', [AcademicApiController::class, 'publicCatalog'])->name('api.v1.academics.catalog');
        Route::get('/catalog/{identifier}', [AcademicApiController::class, 'publicCourseDetail'])->name('api.v1.academics.catalog.show');
    });
    Route::get('/catalog/courses', [AcademicApiController::class, 'publicCatalog'])->name('api.v1.catalog.courses');
    Route::get('/catalog/courses/{identifier}', [AcademicApiController::class, 'publicCourseDetail'])->name('api.v1.catalog.courses.show');

    // 10. Optional Subject Catalog (Public / Student)
    Route::get('/catalog/optional-subjects', [OptionalSubjectApiController::class, 'index'])
        ->name('api.v1.catalog.optional-subjects');

    // 10. Student Self-Service: Profile, Enrollments, Optional Subjects & Digital ID Card
    Route::prefix('student')->group(function () {
        Route::get('/my-profile', [StudentApiController::class, 'myProfile'])
            ->name('api.v1.student.my-profile');
        Route::get('/my-enrollments', [StudentApiController::class, 'myEnrollments'])
            ->name('api.v1.student.my-enrollments');
        Route::post('/profile-correction-request', [StudentApiController::class, 'submitCorrectionRequest'])
            ->name('api.v1.student.profile-correction-request');
        Route::get('/optional-subject', [OptionalSubjectApiController::class, 'myOptionalSubject'])
            ->name('api.v1.student.optional-subject');
        Route::post('/optional-subject/request', [OptionalSubjectApiController::class, 'submitRequest'])
            ->name('api.v1.student.optional-subject.request');
        Route::get('/id-card/settings', [StudentIdCardApiController::class, 'settings'])
            ->name('api.v1.student.id-card.settings');
        Route::get('/id-card/payload', [StudentIdCardApiController::class, 'payload'])
            ->name('api.v1.student.id-card.payload');
        Route::get('/id-card/download', [StudentIdCardApiController::class, 'download'])
            ->name('api.v1.student.id-card.download');
        Route::get('/information-sheet', [StudentInformationSheetApiController::class, 'mySheet'])
            ->name('api.v1.student.information-sheet');
        Route::get('/information-sheet/download', [StudentInformationSheetApiController::class, 'mySheetDownload'])
            ->name('api.v1.student.information-sheet.download');
        Route::get('/my-invoices', [PaymentApiController::class, 'myInvoices'])
            ->name('api.v1.student.my-invoices');
        Route::get('/my-transactions', [PaymentApiController::class, 'myTransactions'])
            ->name('api.v1.student.my-transactions');
        Route::get('/my-ledger', [PaymentApiController::class, 'myLedger'])
            ->name('api.v1.student.my-ledger');
        Route::post('/my-offline-payment', [PaymentApiController::class, 'myOfflinePayment'])
            ->name('api.v1.student.my-offline-payment');
        Route::get('/my-attendance', [AttendanceApiController::class, 'myAttendance'])
            ->name('api.v1.student.my-attendance');
        Route::post('/clock-in', [AttendanceApiController::class, 'clockIn'])
            ->name('api.v1.student.clock-in');
        Route::post('/clock-out', [AttendanceApiController::class, 'clockOut'])
            ->name('api.v1.student.clock-out');
        Route::get('/my-leaves', [LeaveApiController::class, 'myApplications'])
            ->name('api.v1.student.my-leaves');
        Route::get('/leave-balances', [LeaveApiController::class, 'myBalances'])
            ->name('api.v1.student.leave-balances');
        Route::post('/apply-leave', [LeaveApiController::class, 'apply'])
            ->name('api.v1.student.apply-leave');

        // Student Class Routine, Lectures, Syllabi & Attendance Vault
        Route::get('/academic-calendar', [AcademicCalendarApiController::class, 'studentCalendar'])
            ->name('api.v1.student.academic-calendar');
        Route::get('/classes', [ClassScheduleApiController::class, 'studentClasses'])
            ->name('api.v1.student.classes');
        Route::get('/routine', [ClassScheduleApiController::class, 'studentClasses'])
            ->name('api.v1.student.routine');
        Route::get('/classes/{id}', [ClassScheduleApiController::class, 'studentClassDetail'])
            ->whereNumber('id')
            ->name('api.v1.student.classes.detail');
        Route::get('/course-plans', [ClassScheduleApiController::class, 'studentCoursePlans'])
            ->name('api.v1.student.course-plans');
        Route::get('/routine/download', [ClassScheduleApiController::class, 'studentRoutineDownload'])
            ->name('api.v1.student.routine.download');
    });

    // Faculty Self-Service Routine, Profile, Workload & Recordings
    Route::prefix('faculty')->group(function () {
        Route::get('/my-schedule', [ClassScheduleApiController::class, 'mySchedule'])
            ->name('api.v1.faculty.my-schedule');
        Route::get('/my-profile', [ClassScheduleApiController::class, 'myProfile'])
            ->name('api.v1.faculty.my-profile');
        Route::get('/workload', [ClassScheduleApiController::class, 'myWorkload'])
            ->name('api.v1.faculty.workload');
        Route::post('/classes/{id}/recording', [ClassScheduleApiController::class, 'facultyRecording'])
            ->whereNumber('id')
            ->name('api.v1.faculty.classes.recording');
        Route::post('/classes/{id}/complete', [ClassScheduleApiController::class, 'facultyComplete'])
            ->whereNumber('id')
            ->name('api.v1.faculty.classes.complete');
        Route::post('/classes/{id}/reschedule', [ClassScheduleApiController::class, 'facultyReschedule'])
            ->whereNumber('id')
            ->name('api.v1.faculty.classes.reschedule');
    });

    // 11. Student ID Cards Inspection & Verification
    Route::prefix('student-id-cards')->group(function () {
        Route::get('/{identifier}/payload', [StudentIdCardApiController::class, 'payload'])
            ->name('api.v1.student-id-cards.payload');
        Route::get('/{identifier}/download', [StudentIdCardApiController::class, 'download'])
            ->name('api.v1.student-id-cards.download');
    });

    // 12. Library Public Catalog & Student Self-Service Portal
    Route::prefix('library')->group(function () {
        Route::get('/catalog', [LibraryApiController::class, 'indexBooks'])->name('api.v1.library.catalog');
        Route::get('/catalog/{identifier}', [LibraryApiController::class, 'showBook'])->name('api.v1.library.catalog.show');
        Route::get('/my-account', [LibraryApiController::class, 'myAccount'])->name('api.v1.library.my-account');
        Route::get('/my-loans', [LibraryApiController::class, 'myLoans'])->name('api.v1.library.my-loans');
        Route::get('/my-history', [LibraryApiController::class, 'myHistory'])->name('api.v1.library.my-history');
        Route::get('/my-reservations', [LibraryApiController::class, 'myReservations'])->name('api.v1.library.my-reservations');
        Route::post('/my-reservations', [LibraryApiController::class, 'myReserveBook'])->name('api.v1.library.my-reservations.store');
        Route::post('/my-loans/{id}/request-renewal', [LibraryApiController::class, 'requestLoanRenewal'])
            ->whereNumber('id')
            ->name('api.v1.library.my-loans.request-renewal');
        Route::get('/my-card', [LibraryApiController::class, 'myCard'])->name('api.v1.library.my-card');
    });

    // 13. Live Chat, Realtime WebRTC Calling & Online Meetings (Multi-Platform API)
    Route::prefix('chat')->group(function () {
        // System & Diagnostics
        Route::get('/status', [ChatApiController::class, 'status'])
            ->middleware('api.scope:read:chat,ping')
            ->name('api.v1.chat.status');
        Route::get('/calls/ice-servers', [ChatApiController::class, 'iceServers'])
            ->middleware('api.scope:read:chat,call:chat,meeting:chat')
            ->name('api.v1.chat.ice-servers');

        // Conversations Management
        Route::get('/conversations', [ChatApiController::class, 'indexConversations'])
            ->middleware('api.scope:read:chat')
            ->name('api.v1.chat.conversations.index');
        Route::post('/conversations/direct', [ChatApiController::class, 'startDirect'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.direct');
        Route::post('/conversations/group', [ChatApiController::class, 'createGroup'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.group');
        Route::post('/conversations/channel', [ChatApiController::class, 'createChannel'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.channel');
        Route::get('/conversations/{id}', [ChatApiController::class, 'showConversation'])
            ->middleware('api.scope:read:chat')
            ->name('api.v1.chat.conversations.show');
        Route::put('/conversations/{id}', [ChatApiController::class, 'updateConversation'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.update');
        Route::delete('/conversations/{id}', [ChatApiController::class, 'archiveConversation'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.archive');
        Route::post('/conversations/{id}/read', [ChatApiController::class, 'markAsRead'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.read');
        Route::post('/conversations/{id}/typing', [ChatApiController::class, 'typing'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.typing');
        Route::post('/conversations/{id}/participants', [ChatApiController::class, 'addParticipant'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.participants.add');
        Route::delete('/conversations/{id}/participants/{userId}', [ChatApiController::class, 'removeParticipant'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.participants.remove');
        Route::put('/conversations/{id}/participants/{userId}/role', [ChatApiController::class, 'updateParticipantRole'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.conversations.participants.role');

        // Messages & Attachments
        Route::get('/conversations/{id}/messages', [ChatApiController::class, 'getMessages'])
            ->middleware('api.scope:read:chat')
            ->name('api.v1.chat.messages.index');
        Route::post('/conversations/{id}/messages', [ChatApiController::class, 'sendMessage'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.messages.send');
        Route::put('/messages/{messageId}', [ChatApiController::class, 'editMessage'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.messages.edit');
        Route::delete('/messages/{messageId}', [ChatApiController::class, 'deleteMessage'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.messages.delete');
        Route::post('/messages/{messageId}/pin', [ChatApiController::class, 'pinMessage'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.messages.pin');
        Route::delete('/messages/{messageId}/pin', [ChatApiController::class, 'unpinMessage'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.messages.unpin');
        Route::post('/messages/{messageId}/reaction', [ChatApiController::class, 'reactMessage'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.messages.reaction');
        Route::post('/broadcast', [ChatApiController::class, 'broadcast'])
            ->middleware('api.scope:write:chat')
            ->name('api.v1.chat.broadcast');

        // WebRTC 1-on-1 & Group Calling
        Route::post('/calls/initiate', [ChatApiController::class, 'initiateCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.initiate');
        Route::post('/calls/group-initiate', [ChatApiController::class, 'initiateGroupCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.group-initiate');
        Route::post('/calls/{callUuid}/signal', [ChatApiController::class, 'signalCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.signal');
        Route::post('/calls/{callUuid}/accept', [ChatApiController::class, 'acceptCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.accept');
        Route::post('/calls/{callUuid}/reject', [ChatApiController::class, 'rejectCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.reject');
        Route::post('/calls/{callUuid}/join', [ChatApiController::class, 'joinGroupCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.join');
        Route::post('/calls/{callUuid}/leave', [ChatApiController::class, 'leaveCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.leave');
        Route::post('/calls/{callUuid}/end', [ChatApiController::class, 'endCall'])
            ->middleware('api.scope:call:chat')
            ->name('api.v1.chat.calls.end');
        Route::get('/calls/{callUuid}/status', [ChatApiController::class, 'callStatus'])
            ->middleware('api.scope:read:chat,call:chat')
            ->name('api.v1.chat.calls.status');
        Route::get('/calls/history', [ChatApiController::class, 'callHistory'])
            ->middleware('api.scope:read:chat,call:chat')
            ->name('api.v1.chat.calls.history');

        // Dedicated Online Meetings & Video Conferencing
        Route::get('/meetings', [ChatApiController::class, 'indexMeetings'])
            ->middleware('api.scope:read:chat,meeting:chat')
            ->name('api.v1.chat.meetings.index');
        Route::post('/meetings', [ChatApiController::class, 'createMeeting'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.create');
        Route::get('/meetings/{uuid}', [ChatApiController::class, 'showMeeting'])
            ->middleware('api.scope:read:chat,meeting:chat')
            ->name('api.v1.chat.meetings.show');
        Route::put('/meetings/{uuid}', [ChatApiController::class, 'updateMeeting'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.update');
        Route::delete('/meetings/{uuid}', [ChatApiController::class, 'cancelMeeting'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.cancel');
        Route::post('/meetings/{uuid}/join', [ChatApiController::class, 'joinMeeting'])
            ->middleware('api.scope:read:chat,meeting:chat')
            ->name('api.v1.chat.meetings.join');
        Route::post('/meetings/{uuid}/signal', [ChatApiController::class, 'signalMeeting'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.signal');
        Route::get('/meetings/{uuid}/sync', [ChatApiController::class, 'syncMeeting'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.sync');
        Route::post('/meetings/{uuid}/participants/{participantId}/admit', [ChatApiController::class, 'admitParticipant'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.admit');
        Route::post('/meetings/{uuid}/participants/{participantId}/deny', [ChatApiController::class, 'denyParticipant'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.deny');
        Route::put('/meetings/{uuid}/participants/{participantId}/role', [ChatApiController::class, 'setMeetingParticipantRole'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.participant-role');
        Route::post('/meetings/{uuid}/restrictions', [ChatApiController::class, 'updateMeetingRestrictions'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.restrictions');
        Route::post('/meetings/{uuid}/reaction', [ChatApiController::class, 'recordMeetingReaction'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.reaction');
        Route::post('/meetings/{uuid}/invite', [ChatApiController::class, 'sendMeetingInvitations'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.invite');
        Route::post('/meetings/{uuid}/end', [ChatApiController::class, 'endMeeting'])
            ->middleware('api.scope:meeting:chat')
            ->name('api.v1.chat.meetings.end');
    });

    // 17. Application System Settings Management Module
    Route::prefix('settings')->middleware(['auth.api_key'])->group(function () {
        // Telemetry & Groups
        Route::get('/groups', [SystemSettingsApiController::class, 'groups'])
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.groups');
        Route::get('/statistics', [SystemSettingsApiController::class, 'statistics'])
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.statistics');
        Route::get('/groups/{group}', [SystemSettingsApiController::class, 'byGroup'])
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.group');

        // Query & Single Setting
        Route::get('/', [SystemSettingsApiController::class, 'index'])
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.index');
        Route::get('/item/{key}', [SystemSettingsApiController::class, 'show'])
            ->where('key', '.*')
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.show.item');

        // Write Operations (Create, Update, Batch, Soft Delete, Restore)
        Route::post('/', [SystemSettingsApiController::class, 'store'])
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.store');
        Route::put('/batch', [SystemSettingsApiController::class, 'batchUpdate'])
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.batch-put');
        Route::post('/batch', [SystemSettingsApiController::class, 'batchUpdate'])
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.batch');
        Route::post('/assets', [SystemSettingsApiController::class, 'uploadAsset'])
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.assets');
        Route::put('/{key}', [SystemSettingsApiController::class, 'update'])
            ->where('key', '^[a-zA-Z0-9_\-\.]+$')
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.update');
        Route::delete('/{key}', [SystemSettingsApiController::class, 'destroy'])
            ->where('key', '^[a-zA-Z0-9_\-\.]+$')
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.destroy');
        Route::post('/{key}/restore', [SystemSettingsApiController::class, 'restore'])
            ->where('key', '^[a-zA-Z0-9_\-\.]+$')
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.restore');

        // Management Operations (Force Delete, Cache, Reset, Export/Import, Diagnostics)
        Route::delete('/{key}/force', [SystemSettingsApiController::class, 'forceDelete'])
            ->where('key', '^[a-zA-Z0-9_\-\.]+$')
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.force-delete');
        Route::post('/cache/flush', [SystemSettingsApiController::class, 'flushCache'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.cache.flush');
        Route::post('/reset', [SystemSettingsApiController::class, 'reset'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.reset');
        Route::get('/export/bundle', [SystemSettingsApiController::class, 'export'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.export');
        Route::post('/import/bundle', [SystemSettingsApiController::class, 'import'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.import');
        Route::post('/test-email', [SystemSettingsApiController::class, 'testEmail'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.test-email');
        Route::post('/test-sms', [SystemSettingsApiController::class, 'testSms'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.test-sms');
        Route::post('/maintenance', [SystemSettingsApiController::class, 'toggleMaintenance'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.maintenance');

        // Storage & Cloud (S3) APIs
        Route::get('/storage/overview', [SystemSettingsApiController::class, 'storageOverview'])
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.storage-overview');
        Route::post('/test-storage', [SystemSettingsApiController::class, 'testStorage'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.test-storage');
        Route::get('/storage/files', [SystemSettingsApiController::class, 'storageFiles'])
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.storage-files');
        Route::delete('/storage/files', [SystemSettingsApiController::class, 'deleteStorageFile'])
            ->middleware('api.scope:manage:settings')
            ->name('api.v1.settings.storage-delete-file');
        Route::post('/storage/signed-upload-url', [SystemSettingsApiController::class, 'signedUploadUrl'])
            ->middleware('api.scope:write:settings')
            ->name('api.v1.settings.storage-signed-upload');

        // Fallback for single key show: GET /settings/{key}
        Route::get('/{key}', [SystemSettingsApiController::class, 'show'])
            ->where('key', '^[a-zA-Z0-9_\-\.]+$')
            ->middleware('api.scope:read:settings')
            ->name('api.v1.settings.show');
    });

    // 17.5 Bot Defense & CAPTCHA System (Diagnostics, Pruning & Administrative Telemetry)
    Route::prefix('captcha')->group(function () {
        Route::get('/diagnostics', [CaptchaApiController::class, 'diagnostics'])
            ->middleware('api.scope:read:captcha,manage:captcha')
            ->name('api.v1.captcha.diagnostics');
        Route::post('/logs/prune', [CaptchaApiController::class, 'pruneLogs'])
            ->middleware('api.scope:manage:captcha')
            ->name('api.v1.captcha.logs.prune');
    });

    // 18. Support Tickets & Helpdesk System Module (Comprehensive Multi-Platform & Enterprise API)
    Route::prefix('tickets')->middleware(['auth.api_key'])->group(function () {
        // Analytics & SLA Telemetry
        Route::get('/dashboard', [TicketApiController::class, 'dashboard'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.dashboard');
        Route::get('/statistics', [TicketApiController::class, 'statistics'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.statistics');
        Route::get('/export', [TicketApiController::class, 'export'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.export');
        Route::post('/check-sla', [TicketApiController::class, 'checkSla'])
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.check-sla');

        // Categories CRUD
        Route::get('/categories', [TicketApiController::class, 'categories'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.categories');
        Route::post('/categories', [TicketApiController::class, 'storeCategory'])
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.categories.store');
        Route::get('/categories/{id}', [TicketApiController::class, 'showCategory'])
            ->whereNumber('id')
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.categories.show');
        Route::put('/categories/{id}', [TicketApiController::class, 'updateCategory'])
            ->whereNumber('id')
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.categories.update');
        Route::delete('/categories/{id}', [TicketApiController::class, 'destroyCategory'])
            ->whereNumber('id')
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.categories.destroy');

        // Canned Responses CRUD
        Route::get('/canned-responses', [TicketApiController::class, 'cannedResponses'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.canned-responses');
        Route::post('/canned-responses', [TicketApiController::class, 'storeCannedResponse'])
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.canned-responses.store');
        Route::get('/canned-responses/{id}', [TicketApiController::class, 'showCannedResponse'])
            ->whereNumber('id')
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.canned-responses.show');
        Route::put('/canned-responses/{id}', [TicketApiController::class, 'updateCannedResponse'])
            ->whereNumber('id')
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.canned-responses.update');
        Route::delete('/canned-responses/{id}', [TicketApiController::class, 'destroyCannedResponse'])
            ->whereNumber('id')
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.canned-responses.destroy');

        // User Self-Service & Bulk Operations
        Route::get('/my-tickets', [TicketApiController::class, 'myTickets'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.my-tickets');
        Route::post('/bulk-action', [TicketApiController::class, 'bulkAction'])
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.bulk-action');

        // Attachments Download
        Route::get('/attachments/{attachmentId}/download', [TicketApiController::class, 'downloadAttachment'])
            ->whereNumber('attachmentId')
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.attachments.download');

        // Master Tickets Catalog & Creation
        Route::get('/', [TicketApiController::class, 'index'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.index');
        Route::post('/', [TicketApiController::class, 'store'])
            ->middleware('api.scope:write:tickets')
            ->name('api.v1.tickets.store');

        // Specific Ticket Operations (by id, uuid, or ticket_number)
        Route::get('/{identifier}', [TicketApiController::class, 'show'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.show');
        Route::put('/{identifier}', [TicketApiController::class, 'update'])
            ->middleware('api.scope:write:tickets')
            ->name('api.v1.tickets.update');
        Route::delete('/{identifier}', [TicketApiController::class, 'destroy'])
            ->middleware('api.scope:write:tickets')
            ->name('api.v1.tickets.destroy');
        Route::post('/{identifier}/restore', [TicketApiController::class, 'restore'])
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.restore');

        // Ticket Workflow Transitions & Assignment
        Route::put('/{identifier}/status', [TicketApiController::class, 'updateStatus'])
            ->middleware('api.scope:write:tickets')
            ->name('api.v1.tickets.status');
        Route::put('/{identifier}/priority', [TicketApiController::class, 'updatePriority'])
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.priority');
        Route::put('/{identifier}/assign', [TicketApiController::class, 'assign'])
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.assign');

        // Conversation Messages & Attachments
        Route::get('/{identifier}/messages', [TicketApiController::class, 'messages'])
            ->middleware('api.scope:read:tickets')
            ->name('api.v1.tickets.messages');
        Route::post('/{identifier}/messages', [TicketApiController::class, 'storeMessage'])
            ->middleware('api.scope:write:tickets')
            ->name('api.v1.tickets.messages.store');
        Route::delete('/{identifier}/messages/{messageId}', [TicketApiController::class, 'deleteMessage'])
            ->whereNumber('messageId')
            ->middleware('api.scope:manage:tickets')
            ->name('api.v1.tickets.messages.destroy');
        Route::post('/{identifier}/rate', [TicketApiController::class, 'rate'])
            ->middleware('api.scope:write:tickets')
            ->name('api.v1.tickets.rate');
        Route::post('/{identifier}/attachments', [TicketApiController::class, 'uploadAttachments'])
            ->middleware('api.scope:write:tickets')
            ->name('api.v1.tickets.attachments.upload');
    });

    // 19. Communications & Marketing Module (Comprehensive Multi-Platform & Enterprise API)
    Route::prefix('communications')->middleware(['auth.api_key'])->group(function () {
        // Analytics & Telemetry
        Route::get('/dashboard', [CommunicationApiController::class, 'dashboard'])
            ->middleware('api.scope:read:communications')
            ->name('api.v1.communications.dashboard');
        Route::get('/statistics', [CommunicationApiController::class, 'statistics'])
            ->middleware('api.scope:read:communications')
            ->name('api.v1.communications.statistics');
        Route::get('/export', [CommunicationApiController::class, 'export'])
            ->middleware('api.scope:read:communications')
            ->name('api.v1.communications.export');

        // Direct Composing & Quick Dispatch
        Route::post('/send-email', [CommunicationApiController::class, 'sendEmail'])
            ->middleware('api.scope:write:communications')
            ->name('api.v1.communications.send-email');
        Route::post('/send-sms', [CommunicationApiController::class, 'sendSms'])
            ->middleware('api.scope:write:communications')
            ->name('api.v1.communications.send-sms');
        Route::post('/send-bulk', [CommunicationApiController::class, 'sendBulk'])
            ->middleware('api.scope:manage:communications')
            ->name('api.v1.communications.send-bulk');

        // Delivery Logs Management
        Route::prefix('logs')->group(function () {
            Route::get('/', [CommunicationApiController::class, 'logs'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.logs.index');
            Route::post('/bulk-resend', [CommunicationApiController::class, 'bulkResendLogs'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.logs.bulk-resend');
            Route::post('/purge', [CommunicationApiController::class, 'purgeLogs'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.logs.purge');
            Route::get('/{id}', [CommunicationApiController::class, 'showLog'])
                ->whereNumber('id')
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.logs.show');
            Route::post('/{id}/resend', [CommunicationApiController::class, 'resendLog'])
                ->whereNumber('id')
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.logs.resend');
            Route::delete('/{id}', [CommunicationApiController::class, 'destroyLog'])
                ->whereNumber('id')
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.logs.destroy');
            Route::post('/{id}/restore', [CommunicationApiController::class, 'restoreLog'])
                ->whereNumber('id')
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.logs.restore');
        });

        // Campaigns Management
        Route::prefix('campaigns')->group(function () {
            Route::get('/', [CommunicationApiController::class, 'campaigns'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.campaigns.index');
            Route::post('/', [CommunicationApiController::class, 'storeCampaign'])
                ->middleware('api.scope:write:communications')
                ->name('api.v1.communications.campaigns.store');
            Route::get('/{id}', [CommunicationApiController::class, 'showCampaign'])
                ->whereNumber('id')
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.campaigns.show');
            Route::put('/{id}', [CommunicationApiController::class, 'updateCampaign'])
                ->whereNumber('id')
                ->middleware('api.scope:write:communications')
                ->name('api.v1.communications.campaigns.update');
            Route::delete('/{id}', [CommunicationApiController::class, 'destroyCampaign'])
                ->whereNumber('id')
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.campaigns.destroy');
            Route::post('/{id}/restore', [CommunicationApiController::class, 'restoreCampaign'])
                ->whereNumber('id')
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.campaigns.restore');
            Route::post('/{id}/dispatch', [CommunicationApiController::class, 'dispatchCampaign'])
                ->whereNumber('id')
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.campaigns.dispatch');
            Route::post('/{id}/cancel', [CommunicationApiController::class, 'cancelCampaign'])
                ->whereNumber('id')
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.campaigns.cancel');
            Route::get('/{id}/logs', [CommunicationApiController::class, 'campaignLogs'])
                ->whereNumber('id')
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.campaigns.logs');
        });

        // Marketing Subscribers CRM
        Route::prefix('subscribers')->group(function () {
            Route::get('/', [CommunicationApiController::class, 'subscribers'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.subscribers.index');
            Route::post('/', [CommunicationApiController::class, 'storeSubscriber'])
                ->middleware('api.scope:write:communications')
                ->name('api.v1.communications.subscribers.store');
            Route::get('/export', [CommunicationApiController::class, 'exportSubscribers'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.subscribers.export');
            Route::post('/import', [CommunicationApiController::class, 'importSubscribers'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.subscribers.import');
            Route::post('/bulk-action', [CommunicationApiController::class, 'bulkSubscriberAction'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.subscribers.bulk-action');
            Route::get('/{identifier}', [CommunicationApiController::class, 'showSubscriber'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.subscribers.show');
            Route::put('/{identifier}', [CommunicationApiController::class, 'updateSubscriber'])
                ->middleware('api.scope:write:communications')
                ->name('api.v1.communications.subscribers.update');
            Route::delete('/{identifier}', [CommunicationApiController::class, 'destroySubscriber'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.subscribers.destroy');
            Route::post('/{identifier}/restore', [CommunicationApiController::class, 'restoreSubscriber'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.subscribers.restore');
            Route::post('/{identifier}/unsubscribe', [CommunicationApiController::class, 'unsubscribeSubscriber'])
                ->middleware('api.scope:write:communications')
                ->name('api.v1.communications.subscribers.unsubscribe');
        });

        // Email Templates Management
        Route::prefix('templates/email')->group(function () {
            Route::get('/', [CommunicationApiController::class, 'emailTemplates'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.templates.email.index');
            Route::post('/', [CommunicationApiController::class, 'storeEmailTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.email.store');
            Route::get('/{identifier}', [CommunicationApiController::class, 'showEmailTemplate'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.templates.email.show');
            Route::put('/{identifier}', [CommunicationApiController::class, 'updateEmailTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.email.update');
            Route::delete('/{identifier}', [CommunicationApiController::class, 'destroyEmailTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.email.destroy');
            Route::post('/{identifier}/restore', [CommunicationApiController::class, 'restoreEmailTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.email.restore');
            Route::post('/{identifier}/preview', [CommunicationApiController::class, 'previewEmailTemplate'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.templates.email.preview');
        });

        // SMS Templates Management
        Route::prefix('templates/sms')->group(function () {
            Route::get('/', [CommunicationApiController::class, 'smsTemplates'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.templates.sms.index');
            Route::post('/', [CommunicationApiController::class, 'storeSmsTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.sms.store');
            Route::get('/{identifier}', [CommunicationApiController::class, 'showSmsTemplate'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.templates.sms.show');
            Route::put('/{identifier}', [CommunicationApiController::class, 'updateSmsTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.sms.update');
            Route::delete('/{identifier}', [CommunicationApiController::class, 'destroySmsTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.sms.destroy');
            Route::post('/{identifier}/restore', [CommunicationApiController::class, 'restoreSmsTemplate'])
                ->middleware('api.scope:manage:communications')
                ->name('api.v1.communications.templates.sms.restore');
            Route::post('/{identifier}/preview', [CommunicationApiController::class, 'previewSmsTemplate'])
                ->middleware('api.scope:read:communications')
                ->name('api.v1.communications.templates.sms.preview');
        });
    });

    // 21. Global Search & Viewport Preferences
    Route::prefix('search')->group(function () {
        Route::get('/global', [SearchApiController::class, 'search'])
            ->middleware('api.scope:read:search')
            ->name('api.v1.search.global');

        Route::get('/modules', [SearchApiController::class, 'modules'])
            ->middleware('api.scope:read:search')
            ->name('api.v1.search.modules');

        Route::get('/preferences', [SearchApiController::class, 'preferences'])
            ->middleware('api.scope:read:search')
            ->name('api.v1.search.preferences');

        Route::post('/preferences', [SearchApiController::class, 'updatePreferences'])
            ->middleware('api.scope:manage:search')
            ->name('api.v1.search.preferences.update');
    });

    // 22. UPSC CSE Mock Interview Management API
    Route::prefix('mock-interviews')->middleware(['auth.api_key'])->group(function () {
        Route::get('/dashboard', [MockInterviewApiController::class, 'dashboard'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.dashboard');

        Route::get('/sessions', [MockInterviewApiController::class, 'sessions'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.index');
        Route::post('/sessions', [MockInterviewApiController::class, 'storeSession'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.store');
        Route::get('/sessions/{id}', [MockInterviewApiController::class, 'showSession'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.show');
        Route::put('/sessions/{id}', [MockInterviewApiController::class, 'updateSession'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.update');
        Route::delete('/sessions/{id}', [MockInterviewApiController::class, 'destroySession'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.destroy');

        Route::post('/sessions/{id}/members', [MockInterviewApiController::class, 'assignMember'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.members.assign');
        Route::delete('/sessions/{id}/members/{memberId}', [MockInterviewApiController::class, 'removeMember'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.members.remove');

        Route::post('/sessions/{id}/candidates', [MockInterviewApiController::class, 'assignCandidate'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.candidates.assign');
        Route::get('/candidates/{candidateId}', [MockInterviewApiController::class, 'showCandidate'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.candidates.show');
        Route::put('/candidates/{candidateId}', [MockInterviewApiController::class, 'updateCandidate'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.candidates.update');
        Route::delete('/candidates/{candidateId}', [MockInterviewApiController::class, 'removeCandidate'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.candidates.destroy');

        Route::post('/candidates/{candidateId}/evaluate', [MockInterviewApiController::class, 'evaluateCandidate'])
            ->middleware('api.scope:write:mock_interviews')
            ->name('api.v1.mock-interviews.candidates.evaluate');

        Route::put('/candidates/{candidateId}/recording', [MockInterviewApiController::class, 'updateRecording'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.candidates.recording');
        Route::post('/candidates/{candidateId}/dispatch-recording', [MockInterviewApiController::class, 'dispatchRecording'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.candidates.dispatch-recording');

        Route::post('/sessions/{id}/auto-slots', [MockInterviewApiController::class, 'autoSlots'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.auto-slots');
        Route::post('/sessions/{id}/invite', [MockInterviewApiController::class, 'dispatchInvitations'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.invite');

        Route::get('/sessions/{id}/templates', [MockInterviewApiController::class, 'getTemplates'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.templates.show');
        Route::put('/sessions/{id}/templates', [MockInterviewApiController::class, 'updateTemplates'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.sessions.templates.update');

        Route::get('/board-members', [MockInterviewApiController::class, 'boardMembers'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.board-members.index');
        Route::post('/board-members', [MockInterviewApiController::class, 'storeBoardMember'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.board-members.store');
        Route::get('/board-members/{id}', [MockInterviewApiController::class, 'showBoardMember'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.board-members.show');
        Route::put('/board-members/{id}', [MockInterviewApiController::class, 'updateBoardMember'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.board-members.update');
        Route::delete('/board-members/{id}', [MockInterviewApiController::class, 'destroyBoardMember'])
            ->middleware('api.scope:manage:mock_interviews')
            ->name('api.v1.mock-interviews.board-members.destroy');

        Route::get('/reports', [MockInterviewApiController::class, 'reports'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.reports.index');
        Route::get('/analytics', [MockInterviewApiController::class, 'analytics'])
            ->middleware('api.scope:read:mock_interviews')
            ->name('api.v1.mock-interviews.analytics');
    });

    // Student Mock Interviews portal endpoint
    Route::get('/student/mock-interviews', [MockInterviewApiController::class, 'studentSessions'])
        ->middleware(['auth.api_key', 'api.scope:read:mock_interviews'])
        ->name('api.v1.student.mock-interviews');
});
