<?php

use App\Models\Batch;
use App\Models\Book;
use App\Models\ContactDepartment;
use App\Models\Course;
use App\Models\CronJob;
use App\Models\EmailTemplate;
use App\Models\ExaminationCenter;
use App\Models\FeeType;
use App\Models\HostelBuilding;
use App\Models\Language;
use App\Models\LeaveCategory;
use App\Models\OptionalSubject;
use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StudentTest;
use App\Models\StudyMaterial;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('database seeder executes successfully and populates all domain datasets', function () {
    $this->seed(DatabaseSeeder::class);

    // Verify core settings and languages
    expect(Setting::count())->toBeGreaterThan(0)
        ->and(Language::count())->toBeGreaterThan(0)
        ->and(Page::count())->toBeGreaterThan(0)
        ->and(EmailTemplate::count())->toBeGreaterThan(0);

    // Verify contacts, fee types, and cron jobs
    expect(ContactDepartment::count())->toBeGreaterThan(0)
        ->and(CronJob::count())->toBeGreaterThan(0)
        ->and(FeeType::count())->toBeGreaterThan(0);

    // Verify RBAC and super admin
    expect(Role::where('name', 'Super Administrator')->exists())->toBeTrue()
        ->and(User::where('email', 'admin@sntcssc.in')->exists())->toBeTrue()
        ->and(User::where('email', 'test@example.com')->exists())->toBeTrue();

    // Verify Academic and Optional Subjects
    expect(Batch::count())->toBeGreaterThan(0)
        ->and(Course::count())->toBeGreaterThan(0)
        ->and(OptionalSubject::count())->toBeGreaterThan(0);

    // Verify Hostel, Leave & Attendance, and Examination
    expect(HostelBuilding::count())->toBeGreaterThan(0)
        ->and(LeaveCategory::count())->toBeGreaterThan(0)
        ->and(ExaminationCenter::count())->toBeGreaterThan(0);

    // Verify Student Tests and library records
    expect(StudentTest::count())->toBeGreaterThan(0)
        ->and(Book::count())->toBeGreaterThan(0);

    // Verify Study Materials and Ticket System
    expect(StudyMaterial::count())->toBeGreaterThan(0)
        ->and(TicketCategory::count())->toBeGreaterThan(0);
});

test('database seeder can run idempotently without duplicating or throwing errors', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'admin@sntcssc.in')->count())->toBe(1)
        ->and(User::where('email', 'test@example.com')->count())->toBe(1);
});
