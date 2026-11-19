<?php

namespace App\Services;

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\Batch;
use App\Models\Book;
use App\Models\CaptchaLog;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\Document;
use App\Models\Faculty;
use App\Models\MockInterviewSession;
use App\Models\PartnerInstitute;
use App\Models\Setting;
use App\Models\StudyMaterial;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

class GlobalSearchService
{
    /**
     * Safely generate route URL with graceful fallback.
     */
    public static function safeRoute(string $name, array $parameters = [], ?string $fallback = null): string
    {
        try {
            if (Route::has($name)) {
                return route($name, $parameters);
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $fallback ?? (Route::has('admin.students.index') ? route('admin.students.index') : url('/'));
    }

    /**
     * Complete master catalog of all admin panel navigation modules and pages.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function navigationCatalog(): array
    {
        return [
            // MAIN
            [
                'id' => 'nav_dashboard',
                'title' => 'Dashboard',
                'category' => 'Main',
                'description' => 'System overview, key performance indicators, live activity metrics, and dashboard charts',
                'keywords' => ['dashboard', 'home', 'overview', 'analytics', 'kpi', 'metrics', 'stats'],
                'url' => static::safeRoute('dashboard', ['current_team' => auth()->user()?->currentTeam?->slug ?? 'default'], url('/dashboard')),
                'icon' => 'layout-dashboard',
                'permission' => null,
                'admin_only' => false,
            ],
            [
                'id' => 'nav_chat',
                'title' => 'Live Chat & Messaging',
                'category' => 'Communications',
                'description' => 'Direct user messaging, group channels, voice & video calling, and audio conferencing',
                'keywords' => ['chat', 'message', 'direct message', 'video call', 'audio call', 'webrtc', 'group'],
                'url' => route('admin.chat.index'),
                'icon' => 'message-square',
                'permission' => 'chat.access',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_meetings',
                'title' => 'Online Meetings',
                'category' => 'Communications',
                'description' => 'Live interactive virtual classrooms, webinars, screen sharing, and scheduled video meetings',
                'keywords' => ['meetings', 'video call', 'webinar', 'virtual classroom', 'conference', 'zoom', 'screen share'],
                'url' => route('meetings.index'),
                'icon' => 'video',
                'permission' => 'meetings.view',
                'admin_only' => false,
            ],

            // STUDENTS & ADMISSIONS
            [
                'id' => 'nav_students',
                'title' => 'Students Directory',
                'category' => 'Students',
                'description' => 'Student master database, comprehensive academic dossiers, identity cards, and verification',
                'keywords' => ['students', 'directory', 'aspirants', 'roll number', 'admission number', 'dossier', 'candidate'],
                'url' => route('admin.students.index'),
                'icon' => 'users',
                'permission' => 'students.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_students_create',
                'title' => 'Register New Student',
                'category' => 'Students',
                'description' => 'Enroll and register a new candidate into courses and generate lifetime student identification',
                'keywords' => ['register student', 'new student', 'add candidate', 'create student', 'admission form'],
                'url' => static::safeRoute('admin.students.create', [], route('admin.students.index')),
                'icon' => 'user-plus',
                'permission' => 'students.create',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_student_id_cards',
                'title' => 'Student ID Cards',
                'category' => 'Students',
                'description' => 'Digital identity badges, RFID sync, QR verification, and printable student identity card PDFs',
                'keywords' => ['id cards', 'student id', 'identity card', 'barcode', 'qr code', 'rfid', 'printable id'],
                'url' => route('admin.student-id-cards.index'),
                'icon' => 'id-card',
                'permission' => 'student_id_cards.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_optional_subjects',
                'title' => 'Optional Subjects',
                'category' => 'Students',
                'description' => 'UPSC optional subjects allocation, faculty mentors, and student elective change requests',
                'keywords' => ['optional subject', 'upsc optional', 'elective', 'subject change', 'syllabus'],
                'url' => route('admin.optional-subjects.index'),
                'icon' => 'book-open',
                'permission' => 'optional_subjects.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_admissions',
                'title' => 'Admissions & Applications',
                'category' => 'Students',
                'description' => 'Entrance exam application forms, merit lists, candidate verification, and intake allotments',
                'keywords' => ['admissions', 'applications', 'intake', 'entrance exam', 'selection list', 'candidate verification'],
                'url' => route('admin.admissions.index'),
                'icon' => 'file-text',
                'permission' => 'admissions.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_enrollments',
                'title' => 'Course Enrollments',
                'category' => 'Students',
                'description' => 'Batch registrations, student academic active enrollments, course switches, and history',
                'keywords' => ['enrollments', 'batch registration', 'course enrollment', 'active students'],
                'url' => route('admin.enrollments.index'),
                'icon' => 'book-open',
                'permission' => 'students.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_course_withdrawals',
                'title' => 'Course Withdrawals & Disciplinary Actions',
                'category' => 'Students',
                'description' => 'Student discontinuation applications, disciplinary sanctions, suspensions, and reinstatement',
                'keywords' => ['withdrawals', 'discontinuation', 'disciplinary', 'suspension', 'sanctions', 'reinstate'],
                'url' => route('admin.course-withdrawals.index'),
                'icon' => 'user-minus',
                'permission' => 'withdrawals.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_profile_requests',
                'title' => 'Profile Update Requests',
                'category' => 'Students',
                'description' => 'Approve or reject student identity and address change requests with audit verification',
                'keywords' => ['profile requests', 'profile change', 'kyc', 'address change', 'student verification'],
                'url' => route('admin.profile-requests.index'),
                'icon' => 'edit',
                'permission' => 'students.view',
                'admin_only' => true,
            ],

            // ALUMNI
            [
                'id' => 'nav_alumni',
                'title' => 'Alumni Directory',
                'category' => 'Alumni',
                'description' => 'Civil services achievers, IAS/IPS/IFS officers directory, postings, and success stories',
                'keywords' => ['alumni', 'ias', 'ips', 'ifs', 'wbc', 'rankers', 'postings', 'officers', 'achievements'],
                'url' => route('admin.alumni.index'),
                'icon' => 'award',
                'permission' => 'alumni.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_alumni_statistics',
                'title' => 'Alumni Analytics & Placement',
                'category' => 'Alumni',
                'description' => 'Cadre distributions, service allocations, yearly selections, and alumni telemetry',
                'keywords' => ['alumni stats', 'cadre allocation', 'service category', 'placement statistics'],
                'url' => route('admin.alumni.statistics'),
                'icon' => 'bar-chart-2',
                'permission' => 'alumni.view',
                'admin_only' => true,
            ],

            // ACADEMICS
            [
                'id' => 'nav_courses',
                'title' => 'Courses Management',
                'category' => 'Academics',
                'description' => 'Civil service academic foundation courses, prelims crash programs, fees, and curricula',
                'keywords' => ['courses', 'civil services', 'foundation', 'curriculum', 'syllabus', 'programs'],
                'url' => route('admin.courses.index'),
                'icon' => 'graduation-cap',
                'permission' => 'courses.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_batches',
                'title' => 'Batches & Cohorts',
                'category' => 'Academics',
                'description' => 'Academic batches, classroom capacities, academic sessions, and schedule timings',
                'keywords' => ['batches', 'cohorts', 'sessions', 'classroom', 'intake capacity', 'timings'],
                'url' => route('admin.batches.index'),
                'icon' => 'layers',
                'permission' => 'batches.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_sections',
                'title' => 'Course Sections',
                'category' => 'Academics',
                'description' => 'Classroom section subdivisions, roll ranges, and student section allocations',
                'keywords' => ['sections', 'divisions', 'classrooms', 'subsections'],
                'url' => route('admin.sections.index'),
                'icon' => 'columns',
                'permission' => 'sections.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_academic_calendar',
                'title' => 'Academic Calendar & Planning',
                'category' => 'Academics',
                'description' => 'Month-wise institutional planning, official activity roadmap, and version-controlled documents',
                'keywords' => ['academic calendar', 'calendar', 'activities', 'planner', 'revisions', 'annual schedule', 'events', 'holidays'],
                'url' => route('admin.academic-calendar.index'),
                'icon' => 'calendar-days',
                'permission' => 'academic_calendar.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_student_academic_calendar',
                'title' => 'Academic Calendar',
                'category' => 'Student Portal',
                'description' => 'Institutional academic calendar, month-wise milestones, and downloadable revision documents',
                'keywords' => ['academic calendar', 'calendar', 'activities', 'holidays', 'schedule', 'download calendar'],
                'url' => route('student.academic-calendar.index'),
                'icon' => 'calendar-days',
                'permission' => 'academic_calendar.view',
                'student_only' => true,
            ],
            [
                'id' => 'nav_classes',
                'title' => 'Class Routine & Schedule',
                'category' => 'Academics',
                'description' => 'Dynamic lecture scheduling, recurring routines, online meeting integration, and class recordings',
                'keywords' => ['classes', 'routine', 'lecture schedule', 'timetable', 'zoom', 'google meet', 'recordings', 'reminder alert'],
                'url' => route('admin.classes.index'),
                'icon' => 'calendar',
                'permission' => 'classes.view',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_mock_interviews',
                'title' => 'UPSC Mock Interviews',
                'category' => 'Mock Interviews',
                'description' => '5-member board sessions, personality test timetable, invitations, and recording vault',
                'keywords' => ['mock', 'interview', 'personality test', 'upsc', 'board', 'recording', 'chairman'],
                'url' => route('admin.mock-interviews.index'),
                'icon' => 'award',
                'permission' => 'mock_interviews.view',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_course_plans',
                'title' => 'Course Plans & Syllabus Maps',
                'category' => 'Academics',
                'description' => 'Annual, monthly, and weekly course plans with downloadable soft copy documents for students',
                'keywords' => ['course plans', 'syllabus map', 'curriculum', 'annual plan', 'soft copy', 'pedagogical plan'],
                'url' => route('admin.classes.plans'),
                'icon' => 'book-open',
                'permission' => 'classes.plans',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_classrooms',
                'title' => 'Classrooms Master',
                'category' => 'Academics',
                'description' => 'Smart lecture halls, building floors, seating capacity, equipment facilities, and course bindings',
                'keywords' => ['classrooms', 'lecture hall', 'building', 'floor', 'smart room', 'projector', 'hall allocation'],
                'url' => route('admin.classrooms.index'),
                'icon' => 'building-2',
                'permission' => 'classrooms.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_faculties',
                'title' => 'Faculty Master Directory',
                'category' => 'Academics',
                'description' => 'Distinguished faculty dossiers, teaching departments, CVs, partner institute links, and class telemetry',
                'keywords' => ['faculty', 'teachers', 'mentors', 'professors', 'guest faculty', 'educators', 'dossier', 'resume'],
                'url' => route('admin.faculties.index'),
                'icon' => 'users',
                'permission' => 'faculty.view',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_partner_institutes',
                'title' => 'Partner Institutes for Coaching',
                'category' => 'Academics',
                'description' => 'Affiliated coaching institutes, MoU validities, liaison officials, and collaborative mentorships',
                'keywords' => ['partner institutes', 'coaching partners', 'mou', 'affiliation', 'academy', 'liaison'],
                'url' => route('admin.partner-institutes.index'),
                'icon' => 'landmark',
                'permission' => 'partner_institutes.view',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_tests',
                'title' => 'Tests & Examinations',
                'category' => 'Academics',
                'description' => 'Mock tests, prelims test series, subjective mains evaluations, and candidate score entry',
                'keywords' => ['tests', 'examinations', 'mock tests', 'prelims series', 'mains marks', 'evaluations', 'scores'],
                'url' => route('admin.tests.index'),
                'icon' => 'clipboard-check',
                'permission' => 'tests.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_test_reports',
                'title' => 'Test Performance Reports',
                'category' => 'Academics',
                'description' => 'Student marksheet dossiers, marks distribution, score trends, and batch analytics',
                'keywords' => ['test reports', 'marksheets', 'performance', 'score analysis', 'rank list'],
                'url' => route('admin.tests.reports'),
                'icon' => 'bar-chart-3',
                'permission' => 'tests.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_leaderboard',
                'title' => 'Leaderboard & Rankers',
                'category' => 'Academics',
                'description' => 'Competitive mock test rankings, percentile leaderboards, and star student performers',
                'keywords' => ['leaderboard', 'rankers', 'hall of fame', 'top scorers', 'percentile', 'merit'],
                'url' => route('admin.tests.leaderboard'),
                'icon' => 'trophy',
                'permission' => 'tests.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_study_materials',
                'title' => 'Study Materials Repository',
                'category' => 'Academics',
                'description' => 'Curated lecture notes, reference PDFs, question banks, class recordings, and handouts',
                'keywords' => ['study materials', 'lecture notes', 'handouts', 'syllabus pdf', 'e-books', 'question papers'],
                'url' => route('admin.study-materials.index'),
                'icon' => 'folder-open',
                'permission' => 'study_materials.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_materials_analytics',
                'title' => 'Materials Telemetry & Analytics',
                'category' => 'Academics',
                'description' => 'Student downloads telemetry, file access logs, subject engagement, and storage metrics',
                'keywords' => ['materials analytics', 'downloads', 'reading activity', 'storage metrics'],
                'url' => route('admin.study-materials.statistics'),
                'icon' => 'bar-chart-2',
                'permission' => 'study_materials.view',
                'admin_only' => true,
            ],

            // ATTENDANCE & SHIFTS
            [
                'id' => 'nav_attendance',
                'title' => 'Attendance Dashboard',
                'category' => 'Attendance & Shifts',
                'description' => 'Daily attendance telemetry, present/absent trends, biometrics status, and shift metrics',
                'keywords' => ['attendance dashboard', 'present rate', 'daily attendance', 'punches', 'shift summary'],
                'url' => route('admin.attendance.index'),
                'icon' => 'gauge',
                'permission' => 'attendance.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_attendance_mark',
                'title' => 'Mark Daily Attendance',
                'category' => 'Attendance & Shifts',
                'description' => 'Manual classroom attendance roll call, single/bulk punch entry, and status overrides',
                'keywords' => ['mark attendance', 'manual attendance', 'roll call', 'punch entry', 'present absent'],
                'url' => route('admin.attendance.mark'),
                'icon' => 'check-square',
                'permission' => 'attendance.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_attendance_reports',
                'title' => 'Attendance Reports & Matrix',
                'category' => 'Attendance & Shifts',
                'description' => 'Monthly attendance muster roll, percentage compliance, absentee registers, and exports',
                'keywords' => ['attendance reports', 'muster roll', 'attendance matrix', 'absentee list', 'excel export'],
                'url' => route('admin.attendance.reports'),
                'icon' => 'bar-chart-2',
                'permission' => 'attendance.export',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_attendance_biometric',
                'title' => 'Biometric Devices & Turnstiles',
                'category' => 'Attendance & Shifts',
                'description' => 'Hardware fingerprint/face recognition machines, turnstile gates, and heartbeat telemetry',
                'keywords' => ['biometric devices', 'turnstiles', 'fingerprint scanner', 'face recognition', 'rfid machines'],
                'url' => route('admin.attendance.biometric'),
                'icon' => 'cpu',
                'permission' => 'attendance.biometric',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_attendance_calendar',
                'title' => 'Calendars & Academic Holidays',
                'category' => 'Attendance & Shifts',
                'description' => 'Institutional holiday calendar, weekly off schedules, and working day configurations',
                'keywords' => ['calendar', 'holidays', 'academic calendar', 'working days', 'gazetted holidays'],
                'url' => route('admin.attendance.calendar'),
                'icon' => 'calendar',
                'permission' => 'attendance.settings',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_attendance',
                'title' => 'Attendance Settings & Rules',
                'category' => 'Attendance & Shifts',
                'description' => 'Shift timings, late arrival grace minutes, half-day deduction rules, and automated alerts',
                'keywords' => ['attendance settings', 'grace period', 'half day rules', 'shift timings', 'late punch'],
                'url' => route('admin.settings.attendance'),
                'icon' => 'settings',
                'permission' => 'attendance.settings',
                'admin_only' => true,
            ],

            // LEAVE MANAGEMENT
            [
                'id' => 'nav_leaves',
                'title' => 'Leave Applications',
                'category' => 'Leave Management',
                'description' => 'Review, approve, or reject student and staff leave applications with medical attachments',
                'keywords' => ['leave applications', 'apply leave', 'casual leave', 'medical leave', 'sick leave', 'approval'],
                'url' => route('admin.leaves.index'),
                'icon' => 'inbox',
                'permission' => 'leaves.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_leave_categories',
                'title' => 'Leave Categories & Quotas',
                'category' => 'Leave Management',
                'description' => 'Annual leave balances, casual/duty/medical categories, and institutional allocation rules',
                'keywords' => ['leave categories', 'leave quotas', 'leave balance', 'leave types'],
                'url' => route('admin.leaves.categories'),
                'icon' => 'folder-tree',
                'permission' => 'leaves.categories.manage',
                'admin_only' => true,
            ],

            // FEES & PAYMENTS
            [
                'id' => 'nav_fees',
                'title' => 'Fee Invoices & Dues',
                'category' => 'Fees & Payments',
                'description' => 'Student tuition fee invoices, outstanding balance tracking, due dates, and demand notices',
                'keywords' => ['fees', 'invoices', 'dues', 'fee collection', 'tuition fees', 'payment status'],
                'url' => route('admin.payments.fees'),
                'icon' => 'receipt',
                'permission' => 'payments.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_billing_schedules',
                'title' => 'Billing Schedules & Installments',
                'category' => 'Fees & Payments',
                'description' => 'Automated installment plans, scheduled fee generation, and payment recurring milestones',
                'keywords' => ['billing schedules', 'installments', 'fee schedules', 'recurring billing', 'fee plans'],
                'url' => route('admin.payments.schedules'),
                'icon' => 'calendar-days',
                'permission' => 'payments.create',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_offline_payments',
                'title' => 'Offline Payment Verifications',
                'category' => 'Fees & Payments',
                'description' => 'Audit and verify bank transfers (NEFT/RTGS/IMPS), UPI transaction IDs, and cash receipts',
                'keywords' => ['offline payments', 'bank transfer', 'neft', 'imps', 'upi verification', 'cash receipt'],
                'url' => route('admin.payments.verifications'),
                'icon' => 'check-check',
                'permission' => 'payments.verify',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_security',
                'title' => 'Library Security Deposit Management',
                'category' => 'Fees & Payments',
                'description' => 'Refundable caution deposits, clearance certificates, and refund transaction workflows',
                'keywords' => ['library security', 'caution deposit', 'refund', 'clearance', 'library caution money'],
                'url' => route('admin.payments.library'),
                'icon' => 'book',
                'permission' => 'library_deposit.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_transactions',
                'title' => 'Ledger & Transactions',
                'category' => 'Fees & Payments',
                'description' => 'Real-time payment transaction log, gateway references, and tax invoice generation',
                'keywords' => ['transactions', 'payments ledger', 'receipt numbers', 'gateway payments', 'tax invoice'],
                'url' => route('admin.payments.transactions'),
                'icon' => 'banknote',
                'permission' => 'payments.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_student_ledger',
                'title' => 'Student Financial Ledgers',
                'category' => 'Fees & Payments',
                'description' => 'Comprehensive candidate financial account statements, credits, debits, and balance sheets',
                'keywords' => ['student ledger', 'account statement', 'financial history', 'debit credit'],
                'url' => route('admin.payments.ledger'),
                'icon' => 'book-marked',
                'permission' => 'payments.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_collection_reports',
                'title' => 'Collection Reports & Reconciliation',
                'category' => 'Fees & Payments',
                'description' => 'Daily, monthly, and annual financial collections, GST tax reconciliations, and audit reports',
                'keywords' => ['collection reports', 'reconciliation', 'revenue reports', 'financial audit', 'gst reports'],
                'url' => route('admin.payments.reports'),
                'icon' => 'pie-chart',
                'permission' => 'payments.export',
                'admin_only' => true,
            ],

            // LIBRARY MANAGEMENT
            [
                'id' => 'nav_library_books',
                'title' => 'Books Catalog & Inventory',
                'category' => 'Library Management',
                'description' => 'Manage library book master titles, ISBN catalog, authors, publishers, and shelf locations',
                'keywords' => ['library books', 'catalog', 'isbn', 'authors', 'publishers', 'shelf location', 'book master'],
                'url' => route('admin.library.books'),
                'icon' => 'book',
                'permission' => 'library.books.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_accession',
                'title' => 'Accession Register & Barcodes',
                'category' => 'Library Management',
                'description' => 'Unique book accession serial numbering, barcode generation, and physical copy tracking',
                'keywords' => ['accession register', 'barcodes', 'accession number', 'book copies', 'physical copies'],
                'url' => route('admin.library.accession'),
                'icon' => 'barcode',
                'permission' => 'library.accession.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_circulation',
                'title' => 'Circulation Desk (Issue / Return)',
                'category' => 'Library Management',
                'description' => 'Issue books to students/staff, process book returns, renewals, and overdue calculations',
                'keywords' => ['circulation desk', 'issue book', 'return book', 'renew book', 'book loan'],
                'url' => route('admin.library.circulation'),
                'icon' => 'repeat',
                'permission' => 'library.issue.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_reservations',
                'title' => 'Holds & Reservations',
                'category' => 'Library Management',
                'description' => 'Fulfill and manage book reservations, hold queues, and student borrowing requests',
                'keywords' => ['book holds', 'reservations', 'book request', 'queued loans'],
                'url' => route('admin.library.reservations'),
                'icon' => 'bookmark-check',
                'permission' => 'library.reservations.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_members',
                'title' => 'Borrower Members & Cards',
                'category' => 'Library Management',
                'description' => 'Library membership cards, loan limits, caution deposit linkage, and borrower statuses',
                'keywords' => ['library members', 'borrower card', 'patron', 'borrowing limit'],
                'url' => route('admin.library.members'),
                'icon' => 'id-card',
                'permission' => 'library.members.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_fines',
                'title' => 'Overdue & Late Return Fines',
                'category' => 'Library Management',
                'description' => 'Assess overdue penalties, waive fines, collect late fees, and manage fine receipts',
                'keywords' => ['library fines', 'overdue fines', 'late fees', 'fine waiver', 'defaulters'],
                'url' => route('admin.library.fines'),
                'icon' => 'coins',
                'permission' => 'library.fines.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_reports',
                'title' => 'Circulation & Defaulter Reports',
                'category' => 'Library Management',
                'description' => 'Book issue/return histories, long-overdue defaulters list, and accession register prints',
                'keywords' => ['circulation reports', 'defaulter report', 'book logs', 'inventory report'],
                'url' => route('admin.library.reports'),
                'icon' => 'bar-chart-3',
                'permission' => 'library.reports.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_library_masters',
                'title' => 'Catalog Taxonomy & Categories',
                'category' => 'Library Management',
                'description' => 'Dewey Decimal Classification (DDC), book subjects, authors directory, and publishers',
                'keywords' => ['taxonomy', 'ddc', 'dewey decimal', 'book categories', 'subjects', 'publishers'],
                'url' => route('admin.library.masters'),
                'icon' => 'folder-tree',
                'permission' => 'library.categories.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_library',
                'title' => 'Library Policies & Settings',
                'category' => 'Library Management',
                'description' => 'Loan durations, max books per student, daily overdue fine rates, and deposit thresholds',
                'keywords' => ['library settings', 'loan duration', 'fine rate', 'library rules', 'borrowing policy'],
                'url' => route('admin.settings.library'),
                'icon' => 'settings',
                'permission' => 'library.settings.manage',
                'admin_only' => true,
            ],

            // DOCUMENT MANAGEMENT & REPOSITORY
            [
                'id' => 'nav_documents',
                'title' => 'Soft Copies Documents & Repository',
                'category' => 'Document Management',
                'description' => 'Multi-format institutional soft copies document management system (PDF, Word, Excel, PPT, Images, Zip) with version history and archival downloads',
                'keywords' => ['documents', 'document', 'soft copies', 'soft copy', 'repository', 'version control', 'revisions', 'pdf', 'word', 'excel', 'archive', 'file', 'files', 'upload'],
                'url' => route('admin.documents.index'),
                'icon' => 'folder-archive',
                'permission' => 'documents.view',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_documents_upload',
                'title' => 'Upload Soft Copy Document',
                'category' => 'Document Management',
                'description' => 'Register and upload new official soft copy document with version control and access classification',
                'keywords' => ['upload document', 'new document', 'add document', 'upload file', 'document version'],
                'url' => route('admin.documents.index'),
                'icon' => 'upload',
                'permission' => 'documents.create',
                'admin_only' => false,
            ],

            // HOSTEL MANAGEMENT
            [
                'id' => 'nav_hostel',
                'title' => 'Hostel Dashboard & Telemetry',
                'category' => 'Hostel Management',
                'description' => 'Total bed capacity, occupied rooms, vacant beds, gender segregation, and resident metrics',
                'keywords' => ['hostel', 'hostel dashboard', 'bed occupancy', 'residents', 'dormitory'],
                'url' => route('admin.hostel.dashboard'),
                'icon' => 'gauge',
                'permission' => 'hostel.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_hostel_board',
                'title' => 'Visual Room Board',
                'category' => 'Hostel Management',
                'description' => 'Interactive visual map of hostel buildings, rooms, floor occupancy, and bed cards',
                'keywords' => ['visual room board', 'room grid', 'bed map', 'interactive rooms'],
                'url' => route('admin.hostel.rooms.board'),
                'icon' => 'layout-grid',
                'permission' => 'hostel.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_hostel_buildings',
                'title' => 'Buildings & Floors',
                'category' => 'Hostel Management',
                'description' => 'Hostel blocks, residential wings, wardens, caretakers, and gender designations',
                'keywords' => ['hostel buildings', 'blocks', 'floors', 'wings', 'wardens'],
                'url' => route('admin.hostel.buildings'),
                'icon' => 'building-2',
                'permission' => 'hostel.buildings.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_hostel_rooms',
                'title' => 'Rooms & Beds Master',
                'category' => 'Hostel Management',
                'description' => 'Room inventory, bed numbering, sharing capacities (single, double, triple), and fees',
                'keywords' => ['hostel rooms', 'beds', 'room capacity', 'sharing bed', 'room inventory'],
                'url' => route('admin.hostel.rooms.index'),
                'icon' => 'door-closed',
                'permission' => 'hostel.rooms.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_hostel_allocations',
                'title' => 'Room Allocations & Check-in',
                'category' => 'Hostel Management',
                'description' => 'Allocate hostel beds, handle resident check-in/check-out dates, and generate admission slips',
                'keywords' => ['room allocations', 'check in', 'check out', 'resident assignment', 'hostel slip'],
                'url' => route('admin.hostel.allocations'),
                'icon' => 'users',
                'permission' => 'hostel.allocations.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_hostel_requests',
                'title' => 'Accommodation Extension Requests',
                'category' => 'Hostel Management',
                'description' => 'Review and process resident stay extension requests before academic term completions',
                'keywords' => ['extension requests', 'stay extension', 'hostel extension', 'overstay'],
                'url' => route('admin.hostel.requests'),
                'icon' => 'calendar-plus',
                'permission' => 'hostel.requests.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_hostel_reports',
                'title' => 'Hostel Occupancy & Resident Reports',
                'category' => 'Hostel Management',
                'description' => 'Hostel vacancy registers, gender-wise allocation statistics, and resident roll reports',
                'keywords' => ['hostel reports', 'occupancy reports', 'vacancy register', 'resident directory'],
                'url' => route('admin.hostel.reports'),
                'icon' => 'bar-chart-2',
                'permission' => 'hostel.reports.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_hostel',
                'title' => 'Hostel Policies & Configuration',
                'category' => 'Hostel Management',
                'description' => 'Curfew hours, stay extension eligibility, soft-copy admission rules, and security guidelines',
                'keywords' => ['hostel settings', 'curfew rules', 'hostel policy', 'extension rules'],
                'url' => route('admin.settings.hostel'),
                'icon' => 'settings',
                'permission' => 'hostel.settings.manage',
                'admin_only' => true,
            ],

            // USER & ACCESS MANAGEMENT
            [
                'id' => 'nav_users',
                'title' => 'Users & Staff Directory',
                'category' => 'User & Access Management',
                'description' => 'Manage system users, administrators, faculty, staff accounts, password resets, and lockouts',
                'keywords' => ['users', 'staff', 'administrators', 'employees', 'faculty accounts', 'lock user'],
                'url' => route('admin.users.index'),
                'icon' => 'user-cog',
                'permission' => 'users.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_roles',
                'title' => 'Roles & Permissions Matrix',
                'category' => 'User & Access Management',
                'description' => 'Role-Based Access Control (RBAC), custom roles, and permission assignments',
                'keywords' => ['roles', 'rbac', 'access control', 'role permissions', 'super admin', 'role matrix'],
                'url' => route('admin.roles.index'),
                'icon' => 'shield',
                'permission' => 'roles.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_permissions',
                'title' => 'System Permissions Catalog',
                'category' => 'User & Access Management',
                'description' => 'Inspect and configure granular system security permission keys and descriptions',
                'keywords' => ['permissions', 'security keys', 'guard', 'capabilities'],
                'url' => route('admin.permissions.index'),
                'icon' => 'key',
                'permission' => 'permissions.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_user_activity',
                'title' => 'User Activity & Login Sessions',
                'category' => 'User & Access Management',
                'description' => 'Active browser sessions, login IP addresses, failed login attempts, and device telemetry',
                'keywords' => ['user activity', 'login history', 'active sessions', 'failed logins', 'ip telemetry'],
                'url' => route('admin.user-activity.index'),
                'icon' => 'activity',
                'permission' => 'users.view',
                'admin_only' => true,
            ],

            // COMMUNICATIONS & MARKETING
            [
                'id' => 'nav_subscribers',
                'title' => 'Subscribers & Marketing Leads',
                'category' => 'Communications',
                'description' => 'Newsletter subscribers, WhatsApp community contacts, tag segments, and lead capture',
                'keywords' => ['subscribers', 'leads', 'newsletter', 'whatsapp contacts', 'crm', 'audience'],
                'url' => route('admin.subscribers.index'),
                'icon' => 'user-plus',
                'permission' => 'subscribers.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_chat_broadcast',
                'title' => 'Chat Broadcast Hub',
                'category' => 'Communications',
                'description' => 'Broadcast instant personalized notifications and announcements to students and cohorts',
                'keywords' => ['broadcast', 'chat broadcast', 'cohort messaging', 'announcement hub'],
                'url' => route('admin.chat.broadcast'),
                'icon' => 'send',
                'permission' => 'chat.broadcast',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_communications_logs',
                'title' => 'Delivery Logs & Telemetry',
                'category' => 'Communications',
                'description' => 'Real-time SMS and Email transmission logs, delivery receipts, DLT status, and error tracing',
                'keywords' => ['delivery logs', 'email logs', 'sms logs', 'dlt status', 'dispatch history'],
                'url' => route('admin.communications.logs'),
                'icon' => 'activity',
                'permission' => 'communications.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_communications_compose',
                'title' => 'Send Email & SMS Broadcasts',
                'category' => 'Communications',
                'description' => 'Compose individual or bulk transactional emails and DLT registered SMS alerts',
                'keywords' => ['send email', 'send sms', 'compose', 'dispatch message', 'bulk campaign'],
                'url' => route('admin.communications.compose'),
                'icon' => 'send',
                'permission' => 'communications.send',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_sms_templates',
                'title' => 'SMS Message Templates',
                'category' => 'Communications',
                'description' => 'Manage TRAI DLT approved SMS templates, registered sender IDs, and dynamic variables',
                'keywords' => ['sms templates', 'dlt template', 'sender id', 'sms drafts', 'variables'],
                'url' => route('admin.sms-templates.index'),
                'icon' => 'smartphone',
                'permission' => 'templates.manage',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_email_templates',
                'title' => 'Email Message Templates',
                'category' => 'Communications',
                'description' => 'Branded HTML email templates, responsive layout themes, subject lines, and placeholders',
                'keywords' => ['email templates', 'html templates', 'email drafts', 'email styling'],
                'url' => route('admin.email-templates.index'),
                'icon' => 'mail',
                'permission' => 'templates.manage',
                'admin_only' => true,
            ],

            // CONTENT & CMS
            [
                'id' => 'nav_pages',
                'title' => 'Pages Management (CMS)',
                'category' => 'Content & CMS',
                'description' => 'Manage public landing pages, institutional policy documents, terms, and multilingual content',
                'keywords' => ['pages', 'cms', 'website pages', 'content management', 'terms', 'privacy policy'],
                'url' => route('admin.pages.index'),
                'icon' => 'file-text',
                'permission' => 'pages.manage',
                'admin_only' => true,
            ],

            // SUPPORT & HELPDESK
            [
                'id' => 'nav_tickets',
                'title' => 'Support Tickets & Helpdesk',
                'category' => 'Support & Helpdesk',
                'description' => 'Student helpdesk queries, ticket assignment, reply threads, and priority resolution queues',
                'keywords' => ['support tickets', 'helpdesk', 'inquiries', 'support inbox', 'issue resolution'],
                'url' => route('admin.tickets.index'),
                'icon' => 'life-buoy',
                'permission' => 'tickets.view',
                'admin_only' => false,
            ],
            [
                'id' => 'nav_ticket_categories',
                'title' => 'Ticket Categories & SLAs',
                'category' => 'Support & Helpdesk',
                'description' => 'Department categories, Service Level Agreements (SLAs), escalation hours, and badges',
                'keywords' => ['ticket categories', 'sla', 'escalation', 'departments', 'response time'],
                'url' => route('admin.tickets.categories'),
                'icon' => 'tag',
                'permission' => 'tickets.categories',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_ticket_macros',
                'title' => 'Canned Responses & Macros',
                'category' => 'Support & Helpdesk',
                'description' => 'Pre-written quick response templates and shortcuts for fast support ticket triage',
                'keywords' => ['canned responses', 'macros', 'quick replies', 'support templates'],
                'url' => route('admin.tickets.canned-responses'),
                'icon' => 'message-square-quote',
                'permission' => 'tickets.canned_responses',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_contacts',
                'title' => 'Contact Inquiries',
                'category' => 'Support & Helpdesk',
                'description' => 'Public landing page contact form inquiries, prospect follow-ups, and email messages',
                'keywords' => ['contact inquiries', 'contact form', 'messages', 'prospect leads'],
                'url' => route('admin.contacts.index'),
                'icon' => 'mail',
                'permission' => 'contacts.manage',
                'admin_only' => true,
            ],

            // REPORTS
            [
                'id' => 'nav_reports',
                'title' => 'All Institutional Reports',
                'category' => 'Reports & Telemetry',
                'description' => 'Comprehensive academic, financial, attendance, and administrative reports suite',
                'keywords' => ['reports', 'all reports', 'audit reports', 'institutional telemetry'],
                'url' => route('admin.reports.index'),
                'icon' => 'bar-chart-3',
                'permission' => 'reports.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_saved_reports',
                'title' => 'Saved Reports & Queries',
                'category' => 'Reports & Telemetry',
                'description' => 'Access and run pre-saved custom report filter configurations and automated exports',
                'keywords' => ['saved reports', 'custom queries', 'filter presets'],
                'url' => route('admin.reports.saved'),
                'icon' => 'bookmark',
                'permission' => 'reports.view',
                'admin_only' => true,
            ],

            // API & INTEGRATIONS
            [
                'id' => 'nav_api_keys',
                'title' => 'API Keys Management',
                'category' => 'API & Integrations',
                'description' => 'Generate and manage enterprise REST API credentials, scopes, IP whitelists, and rate limits',
                'keywords' => ['api keys', 'tokens', 'rest api', 'credentials', 'external apps', 'webhooks'],
                'url' => route('admin.api-keys.index'),
                'icon' => 'key',
                'permission' => 'api_keys.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_api_logs',
                'title' => 'API Request Telemetry & Logs',
                'category' => 'API & Integrations',
                'description' => 'Live inspection of incoming API requests, HTTP status codes, latencies, and payload traces',
                'keywords' => ['api logs', 'telemetry', 'request latency', 'http status', 'api monitoring'],
                'url' => route('admin.api-keys.logs'),
                'icon' => 'activity',
                'permission' => 'api_keys.logs',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_api_docs',
                'title' => 'Developer Console & Interactive Docs',
                'category' => 'API & Integrations',
                'description' => 'In-admin testable API sandbox, cross-platform code generators, and full OpenAPI documentation',
                'keywords' => ['developer console', 'api docs', 'sandbox', 'rest documentation', 'curl', 'flutter', 'react native'],
                'url' => route('admin.api-keys.docs'),
                'icon' => 'terminal',
                'permission' => 'api_keys.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_captcha_api_docs',
                'title' => 'CAPTCHA & Bot Defense API Integration Docs',
                'category' => 'API & Integrations',
                'description' => 'Cross-platform REST API specifications, Flutter, React Native, and Desktop integration guides',
                'keywords' => ['captcha api', 'bot defense api', 'recaptcha api', 'mobile captcha', 'developer docs', 'specs', 'audio api', 'bot defense'],
                'url' => route('admin.api-keys.docs'),
                'icon' => 'file-code',
                'permission' => 'api_keys.view',
                'admin_only' => true,
            ],

            // SYSTEM & SETTINGS
            [
                'id' => 'nav_settings_captcha',
                'title' => 'reCAPTCHA & Bot Defense Settings',
                'category' => 'System & Settings',
                'description' => 'Configure Google reCAPTCHA, custom anti-OCR SVG challenges, math problems, audio readouts, and bot defense rules',
                'keywords' => ['captcha', 'recaptcha', 'google recaptcha', 'bot defense', 'custom captcha', 'security verification', 'svg captcha', 'audio captcha', 'math captcha', 'bot mitigation', 'waf'],
                'url' => route('admin.settings.captcha'),
                'icon' => 'shield-check',
                'permission' => 'settings.captcha',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_captcha_logs',
                'title' => 'CAPTCHA Verification Logs & Telemetry',
                'category' => 'System & Settings',
                'description' => 'Inspect bot mitigation logs, IP telemetry, reCAPTCHA v3 confidence scores, and failed attempt audits',
                'keywords' => ['captcha logs', 'bot logs', 'verification logs', 'recaptcha telemetry', 'security logs', 'blocked bots', 'bot telemetry'],
                'url' => route('admin.settings.captcha', ['tab' => 'logs']),
                'icon' => 'activity',
                'permission' => 'settings.captcha',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_backups',
                'title' => 'Database & Media Backups',
                'category' => 'System & Settings',
                'description' => 'Trigger immediate database snapshots, download SQL archives, and manage retention cycles',
                'keywords' => ['backups', 'database backup', 'dump', 'restore', 'snapshots', 'zip'],
                'url' => route('admin.backups.index'),
                'icon' => 'database',
                'permission' => 'settings.backup',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_cron_jobs',
                'title' => 'Cron Jobs & Scheduled Tasks',
                'category' => 'System & Settings',
                'description' => 'Monitor background task schedules, automated artisan commands, queues, and execution logs',
                'keywords' => ['cron jobs', 'task scheduler', 'background workers', 'artisan schedule', 'queues'],
                'url' => route('admin.cron-jobs.index'),
                'icon' => 'clock',
                'permission' => 'settings.cron',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_audit_logs',
                'title' => 'Audit Trails & System Logs',
                'category' => 'System & Settings',
                'description' => 'Immutable audit trails of model creations, modifications, deletions, and user actions',
                'keywords' => ['audit logs', 'audit trail', 'system logs', 'activity log', 'history'],
                'url' => route('admin.audit-logs.index'),
                'icon' => 'shield-check',
                'permission' => 'audit.view',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_general',
                'title' => 'General System Settings',
                'category' => 'System & Settings',
                'description' => 'Institution title, legal name, logos, favicons, official addresses, and helpline numbers',
                'keywords' => ['general settings', 'site name', 'institution info', 'contact email', 'branding'],
                'url' => route('admin.settings.general'),
                'icon' => 'settings',
                'permission' => 'settings.general',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_appearance',
                'title' => 'Appearance & Theme Settings',
                'category' => 'System & Settings',
                'description' => 'Theme color palettes, dark mode, typography, brand assets, and default fullscreen mode',
                'keywords' => ['appearance', 'theme settings', 'color palette', 'dark mode', 'fullscreen default', 'font'],
                'url' => route('admin.settings.appearance'),
                'icon' => 'palette',
                'permission' => 'settings.appearance',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_localization',
                'title' => 'Localization & Language Settings',
                'category' => 'System & Settings',
                'description' => 'Default system language, fallback locale, timezone (Asia/Kolkata), and date/time formatting',
                'keywords' => ['localization', 'language settings', 'timezone', 'date format', 'english', 'bengali', 'hindi'],
                'url' => route('admin.settings.localization'),
                'icon' => 'globe',
                'permission' => 'settings.localization',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_email',
                'title' => 'Email & SMTP Gateway Settings',
                'category' => 'System & Settings',
                'description' => 'Configure SMTP mailer, port, TLS/SSL encryption, sender credentials, and send test emails',
                'keywords' => ['email settings', 'smtp', 'mailer', 'sendgrid', 'mailgun', 'smtp test'],
                'url' => route('admin.settings.email'),
                'icon' => 'mail',
                'permission' => 'settings.email',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_sms',
                'title' => 'SMS Gateway Settings',
                'category' => 'System & Settings',
                'description' => 'Configure Fast2SMS, MSG91, 2Factor SMS APIs, DLT registration headers, and balance checks',
                'keywords' => ['sms settings', 'fast2sms', 'msg91', '2factor', 'dlt headers', 'gateway api'],
                'url' => route('admin.settings.sms'),
                'icon' => 'smartphone',
                'permission' => 'settings.sms',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_notification',
                'title' => 'Notification Channels Settings',
                'category' => 'System & Settings',
                'description' => 'Multi-channel notification routing: Email, In-App, SMS, WhatsApp, Telegram alerts',
                'keywords' => ['notification settings', 'channels', 'telegram bot', 'whatsapp gateway', 'alerts'],
                'url' => route('admin.settings.notification'),
                'icon' => 'bell',
                'permission' => 'settings.general',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_payment',
                'title' => 'Payment Gateway Settings',
                'category' => 'System & Settings',
                'description' => 'Razorpay and PhonePe API credentials, key secrets, webhook endpoints, and currencies',
                'keywords' => ['payment settings', 'razorpay', 'phonepe', 'payment gateways', 'webhooks'],
                'url' => route('admin.settings.payment'),
                'icon' => 'credit-card',
                'permission' => 'settings.payment',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_chat',
                'title' => 'Live Chat & WebRTC Settings',
                'category' => 'System & Settings',
                'description' => 'Reverb WebSocket servers, STUN/TURN ICE servers, upload limits, and call retention',
                'keywords' => ['chat settings', 'webrtc settings', 'turn server', 'stun', 'reverb', 'call timeout'],
                'url' => route('admin.settings.chat'),
                'icon' => 'message-square',
                'permission' => 'settings.general',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_meetings',
                'title' => 'Online Meetings Settings',
                'category' => 'System & Settings',
                'description' => 'Max meeting duration, default waiting room policy, recording storage, and screen share controls',
                'keywords' => ['meetings settings', 'meeting policy', 'waiting room', 'recording storage'],
                'url' => route('admin.settings.meetings'),
                'icon' => 'video',
                'permission' => 'settings.general',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_student_id_card',
                'title' => 'Student ID Card Settings',
                'category' => 'System & Settings',
                'description' => 'Configure student printable ID card dimensions, barcode symbology, issuer signatures, and logos',
                'keywords' => ['id card settings', 'barcode settings', 'id card template', 'signature upload'],
                'url' => route('admin.settings.student-id-card'),
                'icon' => 'id-card',
                'permission' => 'settings.general',
                'admin_only' => true,
            ],
            [
                'id' => 'nav_settings_system',
                'title' => 'System Maintenance & Optimization',
                'category' => 'System & Settings',
                'description' => 'Flush cache, route optimize, view clear, server hardware environment, and PHP runtime metrics',
                'keywords' => ['system maintenance', 'clear cache', 'optimize', 'php info', 'server health'],
                'url' => route('admin.settings.system'),
                'icon' => 'cpu',
                'permission' => 'settings.general',
                'admin_only' => true,
            ],

            // USER PROFILE & PREFERENCES
            [
                'id' => 'nav_profile_show',
                'title' => 'My Profile & Preferences',
                'category' => 'User Profile',
                'description' => 'View personal user dossier, avatar photo, designations, and account credentials',
                'keywords' => ['my profile', 'profile details', 'avatar', 'user dossier'],
                'url' => route('admin.profile.show'),
                'icon' => 'user',
                'permission' => null,
                'admin_only' => false,
            ],
            [
                'id' => 'nav_profile_edit',
                'title' => 'Edit Profile Details',
                'category' => 'User Profile',
                'description' => 'Update personal name, contact email address, and personal account details',
                'keywords' => ['edit profile', 'change email', 'update name'],
                'url' => route('profile.edit'),
                'icon' => 'user-check',
                'permission' => null,
                'admin_only' => false,
            ],
            [
                'id' => 'nav_security_edit',
                'title' => 'Account Security & Passkeys',
                'category' => 'User Profile',
                'description' => 'Update password, configure Two-Factor Authentication (2FA), FIDO2/WebAuthn passkeys',
                'keywords' => ['security', 'password', '2fa', 'two-factor', 'passkeys', 'webauthn', 'authenticator'],
                'url' => route('security.edit'),
                'icon' => 'lock',
                'permission' => null,
                'admin_only' => false,
            ],
            [
                'id' => 'nav_appearance_edit',
                'title' => 'Personal Appearance & Viewport',
                'category' => 'User Profile',
                'description' => 'Personal theme preference (Light / Dark / System) and default fullscreen viewport preference',
                'keywords' => ['personal appearance', 'theme', 'dark mode', 'light mode', 'fullscreen mode'],
                'url' => route('appearance.edit'),
                'icon' => 'palette',
                'permission' => null,
                'admin_only' => false,
            ],
            [
                'id' => 'nav_teams',
                'title' => 'Teams Management',
                'category' => 'User Profile',
                'description' => 'Manage organization teams, invite collaborative members, and switch active workspaces',
                'keywords' => ['teams', 'workspaces', 'team members', 'organization'],
                'url' => route('teams.index'),
                'icon' => 'users',
                'permission' => 'settings.general',
                'admin_only' => true,
            ],
        ];
    }

    /**
     * Master list of interactive quick actions.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function quickActionsCatalog(): array
    {
        return [
            [
                'id' => 'action_toggle_fullscreen',
                'title' => 'Toggle Full Screen Mode',
                'category' => 'Quick Actions',
                'description' => 'Switch between standard browser view and expanded full screen (F11)',
                'keywords' => ['fullscreen', 'expand', 'maximize', 'window', 'f11'],
                'action' => 'toggle-fullscreen',
                'icon' => 'maximize-2',
                'badge' => 'F11 / Instant',
                'permission' => null,
            ],
            [
                'id' => 'action_cycle_theme',
                'title' => 'Toggle Dark / Light Theme',
                'category' => 'Quick Actions',
                'description' => 'Instantly toggle between light, dark, and system operating theme mode',
                'keywords' => ['theme', 'dark', 'light', 'night mode', 'color'],
                'action' => 'cycle-theme',
                'icon' => 'palette',
                'badge' => 'Instant',
                'permission' => null,
            ],
            [
                'id' => 'action_create_student',
                'title' => 'New Student Registration Form',
                'category' => 'Quick Actions',
                'description' => 'Directly open the new student registration workflow',
                'keywords' => ['register student', 'new student', 'add aspirant', 'enrollment'],
                'url' => static::safeRoute('admin.students.create', [], route('admin.students.index')),
                'icon' => 'user-plus',
                'badge' => 'Action',
                'permission' => 'students.create',
            ],
            [
                'id' => 'action_schedule_class',
                'title' => 'Schedule Class / Lecture Routine',
                'category' => 'Quick Actions',
                'description' => 'Open the routine scheduler to create new lecture sessions',
                'keywords' => ['schedule class', 'new routine', 'add lecture', 'schedule timetable'],
                'url' => route('admin.classes.index'),
                'icon' => 'calendar-plus',
                'badge' => 'Action',
                'permission' => 'classes.manage',
            ],
            [
                'id' => 'action_create_course_plan',
                'title' => 'Publish New Course Plan',
                'category' => 'Quick Actions',
                'description' => 'Map and publish an annual or weekly course roadmap',
                'keywords' => ['create course plan', 'add course plan', 'syllabus map'],
                'url' => route('admin.classes.plans'),
                'icon' => 'book-plus',
                'badge' => 'Action',
                'permission' => 'classes.plans',
            ],
            [
                'id' => 'action_add_partner_institute',
                'title' => 'Add Partner Coaching Institute',
                'category' => 'Quick Actions',
                'description' => 'Register a partner institute MoU and collaborative program',
                'keywords' => ['add institute', 'new partner institute', 'create institute'],
                'url' => route('admin.partner-institutes.index'),
                'icon' => 'landmark',
                'badge' => 'Action',
                'permission' => 'partner_institutes.manage',
            ],
            [
                'id' => 'action_mark_attendance',
                'title' => 'Mark Daily Classroom Attendance',
                'category' => 'Quick Actions',
                'description' => 'Directly open today’s classroom roll call matrix',
                'keywords' => ['mark attendance', 'punch attendance', 'roll call'],
                'url' => route('admin.attendance.mark'),
                'icon' => 'check-square',
                'badge' => 'Action',
                'permission' => 'attendance.manage',
            ],
            [
                'id' => 'action_circulation_desk',
                'title' => 'Issue / Return Library Book',
                'category' => 'Quick Actions',
                'description' => 'Open the front-desk book issue, return, and renewal scanner',
                'keywords' => ['issue book', 'return book', 'circulation desk', 'library scan'],
                'url' => route('admin.library.circulation'),
                'icon' => 'repeat',
                'badge' => 'Action',
                'permission' => 'library.issue.manage',
            ],
            [
                'id' => 'action_upload_document',
                'title' => 'Upload Soft Copy Document',
                'category' => 'Document Management',
                'description' => 'Upload and archive institutional document file with revision control',
                'keywords' => ['upload document', 'upload', 'document', 'soft copy', 'pdf', 'word', 'file', 'archive'],
                'url' => route('admin.documents.index'),
                'icon' => 'upload',
                'badge' => 'Documents',
                'permission' => 'documents.create',
            ],
            [
                'id' => 'action_compose_message',
                'title' => 'Compose Announcement (Email / SMS)',
                'category' => 'Quick Actions',
                'description' => 'Draft and dispatch announcements to students or cohorts',
                'keywords' => ['compose message', 'send email', 'send sms', 'announcement'],
                'url' => route('admin.communications.compose'),
                'icon' => 'send',
                'badge' => 'Action',
                'permission' => 'communications.send',
            ],
            [
                'id' => 'action_create_ticket',
                'title' => 'Submit New Support Ticket',
                'category' => 'Quick Actions',
                'description' => 'Raise a priority inquiry or student support request ticket',
                'keywords' => ['create ticket', 'raise issue', 'helpdesk ticket'],
                'url' => route('admin.tickets.index'),
                'icon' => 'life-buoy',
                'badge' => 'Action',
                'permission' => 'tickets.view',
            ],
            [
                'id' => 'action_database_backup',
                'title' => 'Create Database Backup Archive',
                'category' => 'Quick Actions',
                'description' => 'Trigger an immediate on-demand database and media backup',
                'keywords' => ['database backup', 'dump', 'create backup', 'snapshot'],
                'url' => route('admin.backups.index'),
                'icon' => 'database',
                'badge' => 'Action',
                'permission' => 'settings.backup',
            ],
            [
                'id' => 'action_test_captcha',
                'title' => 'Test CAPTCHA & Run Diagnostics',
                'category' => 'Quick Actions',
                'description' => 'Launch interactive simulator, test Google connection and challenge verification',
                'keywords' => ['test captcha', 'captcha simulator', 'recaptcha test', 'diagnostics', 'bot test'],
                'url' => route('admin.settings.captcha', ['tab' => 'tester']),
                'icon' => 'shield-check',
                'badge' => 'Simulator',
                'permission' => 'settings.captcha',
            ],
            [
                'id' => 'action_configure_bot_defense',
                'title' => 'Configure Bot Defense Providers',
                'category' => 'Quick Actions',
                'description' => 'Switch between Google reCAPTCHA and Custom SVG Captcha service',
                'keywords' => ['bot defense', 'configure captcha', 'switch captcha', 'google recaptcha'],
                'url' => route('admin.settings.captcha'),
                'icon' => 'shield-alert',
                'badge' => 'Settings',
                'permission' => 'settings.captcha',
            ],
        ];
    }

    /**
     * Check if a given user has permission to access a specific navigation item or action.
     */
    public static function isAccessible(array $item, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // Feature flags check
        if (($item['id'] ?? '') === 'nav_chat' || (isset($item['url']) && str_contains($item['url'], '/chat'))) {
            if (! (bool) Setting::get('chat.enabled', true)) {
                return false;
            }
        }

        if (($item['id'] ?? '') === 'nav_meetings' || (isset($item['url']) && str_contains($item['url'], '/meetings'))) {
            if (! (bool) Setting::get('meeting.enabled', Setting::get('chat.meetings_enabled', true))) {
                return false;
            }
        }

        // Super Administrator has unrestricted access across all items
        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        // Teams management is restricted strictly to administrators
        if (isset($item['id']) && $item['id'] === 'nav_teams' && ! $user->isAdmin()) {
            return false;
        }

        // Student portal account restrictions
        if ($user->isStudent() && ! empty($item['admin_only'])) {
            return false;
        }

        // Permission check
        if (! empty($item['permission'])) {
            return $user->can($item['permission']);
        }

        return true;
    }

    /**
     * Retrieve all accessible navigation items for a given user.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function getAccessibleNavigation(?User $user): Collection
    {
        return collect(static::navigationCatalog())
            ->filter(fn ($item) => static::isAccessible($item, $user))
            ->values();
    }

    /**
     * Retrieve all accessible quick actions for a given user.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function getAccessibleQuickActions(?User $user): Collection
    {
        return collect(static::quickActionsCatalog())
            ->filter(fn ($item) => static::isAccessible($item, $user))
            ->values();
    }

    /**
     * Perform unified global search across navigation, actions, and permissible data records.
     *
     * @return array<string, mixed>
     */
    public static function search(string $rawQuery, ?User $user, int $limit = 20): array
    {
        $query = trim($rawQuery);
        $normalizedQuery = strtolower($query);

        $results = [
            'query' => $query,
            'total' => 0,
            'categories' => [],
            'items' => [],
        ];

        if (! $user) {
            return $results;
        }

        $items = collect();

        // 1. If query is empty, return popular suggested navigation and quick actions
        if ($normalizedQuery === '') {
            $suggestedNav = static::getAccessibleNavigation($user)->take(8);
            $suggestedActions = static::getAccessibleQuickActions($user)->take(4);

            $combined = $suggestedActions->merge($suggestedNav);
            $results['total'] = $combined->count();
            $results['items'] = $combined->all();
            $results['categories'] = $combined->pluck('category')->unique()->values()->all();

            return $results;
        }

        // 2. Search Navigation Catalog
        $navMatches = static::getAccessibleNavigation($user)->filter(function ($item) use ($normalizedQuery) {
            $titleMatch = str_contains(strtolower($item['title']), $normalizedQuery);
            $categoryMatch = str_contains(strtolower($item['category']), $normalizedQuery);
            $descMatch = str_contains(strtolower($item['description'] ?? ''), $normalizedQuery);

            $keywordMatch = false;
            foreach ($item['keywords'] ?? [] as $kw) {
                if (str_contains(strtolower($kw), $normalizedQuery)) {
                    $keywordMatch = true;
                    break;
                }
            }

            return $titleMatch || $categoryMatch || $descMatch || $keywordMatch;
        })->map(function ($item) use ($normalizedQuery) {
            $score = 10;
            if (strtolower($item['title']) === $normalizedQuery) {
                $score = 100;
            } elseif (str_starts_with(strtolower($item['title']), $normalizedQuery)) {
                $score = 80;
            } elseif (str_contains(strtolower($item['title']), $normalizedQuery)) {
                $score = 60;
            }

            $item['score'] = $score;
            $item['type'] = 'navigation';

            return $item;
        });

        $items = $items->merge($navMatches);

        // 3. Search Quick Actions
        $actionMatches = static::getAccessibleQuickActions($user)->filter(function ($item) use ($normalizedQuery) {
            $titleMatch = str_contains(strtolower($item['title']), $normalizedQuery);
            $descMatch = str_contains(strtolower($item['description'] ?? ''), $normalizedQuery);

            $keywordMatch = false;
            foreach ($item['keywords'] ?? [] as $kw) {
                if (str_contains(strtolower($kw), $normalizedQuery)) {
                    $keywordMatch = true;
                    break;
                }
            }

            return $titleMatch || $descMatch || $keywordMatch;
        })->map(function ($item) use ($normalizedQuery) {
            $score = 20;
            if (strtolower($item['title']) === $normalizedQuery) {
                $score = 95;
            } elseif (str_starts_with(strtolower($item['title']), $normalizedQuery)) {
                $score = 75;
            }

            $item['score'] = $score;
            $item['type'] = 'action';

            return $item;
        });

        $items = $items->merge($actionMatches);

        // 4. Live Entity Search (only if query is at least 2 characters)
        if (mb_strlen($query) >= 2) {
            // A. Students Search (Permission: students.view)
            if ($user->hasRole('Super Administrator') || $user->can('students.view')) {
                try {
                    $students = User::role('Student')
                        ->where(function ($q) use ($query) {
                            $q->where('name', 'like', "%{$query}%")
                                ->orWhere('email', 'like', "%{$query}%")
                                ->orWhere('phone', 'like', "%{$query}%")
                                ->orWhere('student_id', 'like', "%{$query}%")
                                ->orWhere('urn', 'like', "%{$query}%");
                        })
                        ->limit(5)
                        ->get();

                    foreach ($students as $student) {
                        $items->push([
                            'id' => "student_{$student->id}",
                            'type' => 'student',
                            'title' => $student->name,
                            'subtitle' => "ID: {$student->student_id} • {$student->email}",
                            'category' => 'Students',
                            'description' => "Student profile of {$student->name} (Phone: {$student->phone})",
                            'url' => route('admin.students.index', ['search' => $student->student_id ?: $student->email]),
                            'icon' => 'user',
                            'badge' => 'Student',
                            'score' => str_starts_with(strtolower($student->name), $normalizedQuery) ? 50 : 35,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search student query failed: '.$e->getMessage());
                }
            }

            // B. Courses Search (Permission: courses.manage)
            if ($user->hasRole('Super Administrator') || $user->can('courses.manage')) {
                try {
                    $courses = Course::where('name', 'like', "%{$query}%")
                        ->orWhere('code', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($courses as $course) {
                        $items->push([
                            'id' => "course_{$course->id}",
                            'type' => 'course',
                            'title' => $course->name,
                            'subtitle' => "Code: {$course->code} • ₹".number_format((float) ($course->fee_amount ?? 0), 2),
                            'category' => 'Academics',
                            'description' => "Course: {$course->name} ({$course->code})",
                            'url' => route('admin.courses.index', ['search' => $course->code]),
                            'icon' => 'graduation-cap',
                            'badge' => 'Course',
                            'score' => 40,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search courses query failed: '.$e->getMessage());
                }
            }

            // C. Batches Search (Permission: batches.manage)
            if ($user->hasRole('Super Administrator') || $user->can('batches.manage')) {
                try {
                    $batches = Batch::where('name', 'like', "%{$query}%")
                        ->orWhere('code', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($batches as $batch) {
                        $items->push([
                            'id' => "batch_{$batch->id}",
                            'type' => 'batch',
                            'title' => $batch->name,
                            'subtitle' => "Batch Code: {$batch->code}",
                            'category' => 'Academics',
                            'description' => "Batch cohort {$batch->name}",
                            'url' => route('admin.batches.index', ['search' => $batch->name]),
                            'icon' => 'layers',
                            'badge' => 'Batch',
                            'score' => 38,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search batches query failed: '.$e->getMessage());
                }
            }

            // Academic Calendar & Activity Search (Permission: academic_calendar.view)
            if ($user->hasRole('Super Administrator') || $user->can('academic_calendar.view')) {
                try {
                    $calendars = AcademicCalendar::where('title', 'like', "%{$query}%")
                        ->orWhere('academic_year', 'like', "%{$query}%")
                        ->orWhere('calendar_code', 'like', "%{$query}%")
                        ->limit(3)
                        ->get();

                    foreach ($calendars as $cal) {
                        $calUrl = $user->isStudent()
                            ? route('student.academic-calendar.index')
                            : route('admin.academic-calendar.index', ['year' => $cal->academic_year]);

                        $items->push([
                            'id' => "academic_calendar_{$cal->id}",
                            'type' => 'academic_calendar',
                            'title' => $cal->title,
                            'subtitle' => "Session {$cal->academic_year} • {$cal->calendar_code}",
                            'category' => 'Academics',
                            'description' => "Academic Calendar for session {$cal->academic_year}",
                            'url' => $calUrl,
                            'icon' => 'calendar-days',
                            'badge' => 'Calendar',
                            'score' => 42,
                        ]);
                    }

                    $activities = AcademicCalendarActivity::where('title', 'like', "%{$query}%")
                        ->orWhere('activity_code', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($activities as $act) {
                        $actUrl = $user->isStudent()
                            ? route('student.academic-calendar.index', ['q' => $act->activity_code])
                            : route('admin.academic-calendar.index', ['q' => $act->activity_code]);

                        $items->push([
                            'id' => "calendar_act_{$act->id}",
                            'type' => 'academic_activity',
                            'title' => $act->title,
                            'subtitle' => "{$act->activity_code} • {$act->formatted_date_range} • {$act->getCategoryLabel()}",
                            'category' => 'Academics',
                            'description' => $act->description ?? "Institutional activity {$act->title}",
                            'url' => $actUrl,
                            'icon' => 'calendar-check',
                            'badge' => 'Event',
                            'score' => 38,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search academic calendar query failed: '.$e->getMessage());
                }
            }

            // D. Books Catalog Search (Permission: library.books.manage or library.view)
            if ($user->hasRole('Super Administrator') || $user->can('library.books.manage') || $user->can('library.view')) {
                try {
                    $books = Book::where('title', 'like', "%{$query}%")
                        ->orWhere('isbn', 'like', "%{$query}%")
                        ->orWhere('author_name', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($books as $book) {
                        $items->push([
                            'id' => "book_{$book->id}",
                            'type' => 'book',
                            'title' => $book->title,
                            'subtitle' => "ISBN: {$book->isbn} • Author: {$book->author_name}",
                            'category' => 'Library Management',
                            'description' => "Library book: {$book->title}",
                            'url' => route('admin.library.books', ['search' => $book->isbn ?: $book->title]),
                            'icon' => 'book',
                            'badge' => 'Book',
                            'score' => 36,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search books query failed: '.$e->getMessage());
                }
            }

            // E. Users / Staff Search (Permission: users.view)
            if ($user->hasRole('Super Administrator') || $user->can('users.view')) {
                try {
                    $staffUsers = User::withoutRole('Student')
                        ->where(function ($q) use ($query) {
                            $q->where('name', 'like', "%{$query}%")
                                ->orWhere('email', 'like', "%{$query}%")
                                ->orWhere('employee_id', 'like', "%{$query}%");
                        })
                        ->limit(4)
                        ->get();

                    foreach ($staffUsers as $staff) {
                        $items->push([
                            'id' => "user_{$staff->id}",
                            'type' => 'user',
                            'title' => $staff->name,
                            'subtitle' => "{$staff->designation} • {$staff->email}",
                            'category' => 'User & Access Management',
                            'description' => "Staff member {$staff->name}",
                            'url' => route('admin.users.index', ['search' => $staff->email]),
                            'icon' => 'user-cog',
                            'badge' => 'Staff',
                            'score' => 34,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search users query failed: '.$e->getMessage());
                }
            }

            // F. Support Tickets Search (Permission: tickets.view)
            if ($user->hasRole('Super Administrator') || $user->can('tickets.view')) {
                try {
                    $tickets = Ticket::where('ticket_number', 'like', "%{$query}%")
                        ->orWhere('subject', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($tickets as $ticket) {
                        $items->push([
                            'id' => "ticket_{$ticket->id}",
                            'type' => 'ticket',
                            'title' => "#{$ticket->ticket_number}: {$ticket->subject}",
                            'subtitle' => "Status: {$ticket->status} • Priority: {$ticket->priority}",
                            'category' => 'Support & Helpdesk',
                            'description' => "Ticket {$ticket->ticket_number}",
                            'url' => route('admin.tickets.index', ['search' => $ticket->ticket_number]),
                            'icon' => 'life-buoy',
                            'badge' => 'Ticket',
                            'score' => 32,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search tickets query failed: '.$e->getMessage());
                }
            }

            // G. Study Materials Search (Permission: study_materials.view)
            if ($user->hasRole('Super Administrator') || $user->can('study_materials.view')) {
                try {
                    $materials = StudyMaterial::where('title', 'like', "%{$query}%")
                        ->orWhere('topic', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($materials as $material) {
                        $items->push([
                            'id' => "material_{$material->id}",
                            'type' => 'study_material',
                            'title' => $material->title,
                            'subtitle' => "Topic: {$material->topic} • {$material->material_type}",
                            'category' => 'Academics',
                            'description' => "Study material {$material->title}",
                            'url' => route('admin.study-materials.index', ['search' => $material->title]),
                            'icon' => 'folder-open',
                            'badge' => 'Material',
                            'score' => 30,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search study materials query failed: '.$e->getMessage());
                }
            }

            // H. Class Routine & Lecture Schedules Search (Permission: classes.view)
            if ($user->hasRole('Super Administrator') || $user->can('classes.view')) {
                try {
                    $schedules = ClassSchedule::with(['course', 'batch'])
                        ->where('subject', 'like', "%{$query}%")
                        ->orWhere('topics', 'like', "%{$query}%")
                        ->orWhere('lecture_number', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($schedules as $sched) {
                        $items->push([
                            'id' => "class_sched_{$sched->id}",
                            'type' => 'class_schedule',
                            'title' => "Class: {$sched->subject}".($sched->lecture_number ? " ({$sched->lecture_number})" : ''),
                            'subtitle' => "{$sched->class_date->format('d M Y')} • {$sched->course?->name} ({$sched->delivery_mode})",
                            'category' => 'Academics',
                            'description' => "Class {$sched->subject} on {$sched->class_date->format('d M Y')}",
                            'url' => route('admin.classes.index', ['q' => $sched->subject]),
                            'icon' => 'calendar',
                            'badge' => 'Routine',
                            'score' => 32,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search class schedules query failed: '.$e->getMessage());
                }
            }

            // H2. UPSC Mock Interview Sessions Search (Permission: mock_interviews.view)
            if ($user->hasRole('Super Administrator') || $user->can('mock_interviews.view')) {
                try {
                    $mockSessions = MockInterviewSession::where('title', 'like', "%{$query}%")
                        ->orWhere('session_code', 'like', "%{$query}%")
                        ->orWhere('venue_address', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($mockSessions as $ms) {
                        $items->push([
                            'id' => "mock_session_{$ms->id}",
                            'type' => 'mock_interview',
                            'title' => "{$ms->session_code}: {$ms->title}",
                            'subtitle' => "{$ms->formatted_date} • {$ms->formatted_time_range}",
                            'category' => 'Mock Interviews',
                            'description' => "Venue: {$ms->venue_address}",
                            'url' => route('admin.mock-interviews.index', ['q' => $ms->session_code]),
                            'icon' => 'award',
                            'badge' => 'Mock Interview',
                            'score' => 33,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search mock interview query failed: '.$e->getMessage());
                }
            }

            // I. Course Plans Search (Permission: classes.plans)
            if ($user->hasRole('Super Administrator') || $user->can('classes.plans')) {
                try {
                    $plans = CoursePlan::where('title', 'like', "%{$query}%")
                        ->orWhere('plan_code', 'like', "%{$query}%")
                        ->orWhere('topics_covered', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($plans as $plan) {
                        $items->push([
                            'id' => "course_plan_{$plan->id}",
                            'type' => 'course_plan',
                            'title' => "Course Plan: {$plan->title}",
                            'subtitle' => "Code: {$plan->plan_code} • Type: ".ucfirst($plan->plan_type)." ({$plan->status})",
                            'category' => 'Academics',
                            'description' => "Course plan {$plan->title}",
                            'url' => route('admin.classes.plans', ['q' => $plan->title]),
                            'icon' => 'book-open',
                            'badge' => 'Course Plan',
                            'score' => 31,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search course plans query failed: '.$e->getMessage());
                }
            }

            // J. Classrooms Master Search (Permission: classrooms.view)
            if ($user->hasRole('Super Administrator') || $user->can('classrooms.view')) {
                try {
                    $classrooms = Classroom::where('name', 'like', "%{$query}%")
                        ->orWhere('room_code', 'like', "%{$query}%")
                        ->orWhere('building', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($classrooms as $room) {
                        $items->push([
                            'id' => "classroom_{$room->id}",
                            'type' => 'classroom',
                            'title' => "Classroom: {$room->name}",
                            'subtitle' => "Code: {$room->room_code} • {$room->building}, {$room->floor} (Cap: {$room->capacity})",
                            'category' => 'Academics',
                            'description' => "Classroom {$room->name}",
                            'url' => route('admin.classrooms.index', ['search' => $room->name]),
                            'icon' => 'building-2',
                            'badge' => 'Hall',
                            'score' => 30,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search classrooms query failed: '.$e->getMessage());
                }
            }

            // K. Faculty Master Search (Permission: faculty.view)
            if ($user->hasRole('Super Administrator') || $user->can('faculty.view')) {
                try {
                    $faculties = Faculty::where('name', 'like', "%{$query}%")
                        ->orWhere('faculty_code', 'like', "%{$query}%")
                        ->orWhere('department', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($faculties as $fac) {
                        $items->push([
                            'id' => "faculty_{$fac->id}",
                            'type' => 'faculty',
                            'title' => "Faculty: {$fac->name}",
                            'subtitle' => "{$fac->department} • {$fac->designation} ({$fac->faculty_type_label})",
                            'category' => 'Academics',
                            'description' => "Faculty member {$fac->name}",
                            'url' => route('admin.faculties.index', ['q' => $fac->name]),
                            'icon' => 'users',
                            'badge' => 'Faculty',
                            'score' => 33,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search faculties query failed: '.$e->getMessage());
                }
            }

            // L. Partner Institutes Search (Permission: partner_institutes.view)
            if ($user->hasRole('Super Administrator') || $user->can('partner_institutes.view')) {
                try {
                    $institutes = PartnerInstitute::where('name', 'like', "%{$query}%")
                        ->orWhere('code', 'like', "%{$query}%")
                        ->orWhere('official_name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->limit(4)
                        ->get();

                    foreach ($institutes as $inst) {
                        $items->push([
                            'id' => "partner_institute_{$inst->id}",
                            'type' => 'partner_institute',
                            'title' => "Partner Institute: {$inst->name}",
                            'subtitle' => "Code: {$inst->code} • Status: {$inst->status_label} • Official: {$inst->official_name}",
                            'category' => 'Academics',
                            'description' => "Partner coaching institute {$inst->name}",
                            'url' => route('admin.partner-institutes.index', ['q' => $inst->name]),
                            'icon' => 'landmark',
                            'badge' => 'Partner',
                            'score' => 31,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search partner institutes query failed: '.$e->getMessage());
                }
            }

            // M. Soft Copies Documents Search (Permission: documents.view)
            if ($user->hasRole('Super Administrator') || $user->can('documents.view')) {
                try {
                    $documents = Document::with(['category', 'currentVersion'])
                        ->where(function ($q) use ($query) {
                            $q->where('title', 'like', "%{$query}%")
                                ->orWhere('document_number', 'like', "%{$query}%")
                                ->orWhere('purpose', 'like', "%{$query}%")
                                ->orWhere('tags', 'like', "%{$query}%")
                                ->orWhere('description', 'like', "%{$query}%")
                                ->orWhereHas('versions', function ($vq) use ($query) {
                                    $vq->where('original_file_name', 'like', "%{$query}%")
                                        ->orWhere('version_number', 'like', "%{$query}%");
                                });
                        })
                        ->limit(5)
                        ->get();

                    foreach ($documents as $doc) {
                        $curVer = $doc->currentVersion;
                        $verText = $curVer ? "v{$curVer->version_number}" : 'No file';
                        $catText = $doc->category?->name ?? 'General';
                        $items->push([
                            'id' => "document_{$doc->id}",
                            'type' => 'document',
                            'title' => $doc->title,
                            'subtitle' => "{$doc->document_number} • {$catText} • {$verText} ({$doc->total_versions_count} rev)",
                            'category' => 'Document Management',
                            'description' => $doc->purpose ?? $doc->description ?? "Soft copy document {$doc->document_number}",
                            'url' => route('admin.documents.index', ['q' => $doc->document_number]),
                            'icon' => 'folder-archive',
                            'badge' => $curVer ? strtoupper($curVer->file_type) : 'DOC',
                            'score' => 45,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search documents query failed: '.$e->getMessage());
                }
            }

            // N. Bot Defense & CAPTCHA Verification Logs Search (Permission: settings.captcha)
            if ($user->hasRole('Super Administrator') || $user->can('settings.captcha')) {
                try {
                    $logs = CaptchaLog::where(function ($q) use ($query) {
                        $q->where('ip_address', 'like', "%{$query}%")
                            ->orWhere('session_id', 'like', "%{$query}%")
                            ->orWhere('action', 'like', "%{$query}%")
                            ->orWhere('error_code', 'like', "%{$query}%")
                            ->orWhere('driver', 'like', "%{$query}%")
                            ->orWhere('status', 'like', "%{$query}%");
                    })
                        ->latest()
                        ->limit(5)
                        ->get();

                    foreach ($logs as $log) {
                        $actionName = $log->formatted_action;
                        $timeAgo = $log->created_at?->diffForHumans() ?? '';
                        $items->push([
                            'id' => "captcha_log_{$log->id}",
                            'type' => 'captcha_log',
                            'title' => "CAPTCHA Log #{$log->id}: {$actionName} (".strtoupper($log->status).')',
                            'subtitle' => "IP: {$log->ip_address} • Driver: {$log->driver} • {$timeAgo}",
                            'category' => 'Bot Defense & CAPTCHA',
                            'description' => "Verification log for {$log->action} via {$log->driver} (Status: {$log->status}".($log->score !== null ? ", Score: {$log->score}" : '').($log->error_code ? ", Error: {$log->error_code}" : '').')',
                            'url' => route('admin.settings.captcha', ['search' => $log->ip_address]),
                            'icon' => $log->status === CaptchaLog::STATUS_PASSED ? 'shield-check' : 'shield-alert',
                            'badge' => ucfirst($log->status),
                            'score' => 45,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Global search captcha logs query failed: '.$e->getMessage());
                }
            }
        }

        // Sort results by relevance score descending
        $sortedItems = $items->sortByDesc('score')->take($limit)->values();

        $results['total'] = $sortedItems->count();
        $results['items'] = $sortedItems->all();
        $results['categories'] = $sortedItems->pluck('category')->unique()->values()->all();

        return $results;
    }

    /**
     * Get viewport preferences (including fullscreen mode) for the current user and system.
     *
     * @return array<string, mixed>
     */
    public static function getViewportPreferences(?User $user): array
    {
        $systemDefault = (string) Setting::get('appearance.default_fullscreen', 'normal');
        $userPref = $user ? $user->getPreference('default_fullscreen') : null;

        return [
            'effective_fullscreen' => $userPref ?? $systemDefault,
            'user_default_fullscreen' => $userPref,
            'system_default_fullscreen' => $systemDefault,
            'search_history_enabled' => (bool) ($user ? $user->getPreference('search_history_enabled', true) : true),
            'quick_actions_enabled' => (bool) ($user ? $user->getPreference('quick_actions_enabled', true) : true),
        ];
    }

    /**
     * Update viewport & search preferences for user and optionally system-wide.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function updateViewportPreferences(User $user, array $payload, bool $applySystemWide = false): array
    {
        return DB::transaction(function () use ($user, $payload, $applySystemWide) {
            $oldPreferences = $user->preferences ?? [];

            if (isset($payload['default_fullscreen'])) {
                $user->setPreference('default_fullscreen', $payload['default_fullscreen']);
            }

            if (isset($payload['search_history_enabled'])) {
                $user->setPreference('search_history_enabled', (bool) $payload['search_history_enabled']);
            }

            if (isset($payload['quick_actions_enabled'])) {
                $user->setPreference('quick_actions_enabled', (bool) $payload['quick_actions_enabled']);
            }

            // System-wide update if requested and user has authorization
            if ($applySystemWide && isset($payload['default_fullscreen']) && ($user->hasRole('Super Administrator') || $user->can('settings.appearance'))) {
                Setting::set('appearance.default_fullscreen', $payload['default_fullscreen'], $user->id);
                Setting::flushCache();
            }

            AuditLogService::log(
                event: 'user_preferences_updated',
                description: "Updated viewport and search preferences for user {$user->name} ({$user->email})",
                newValues: $user->preferences,
                oldValues: $oldPreferences,
                userId: $user->id
            );

            return static::getViewportPreferences($user);
        });
    }
}
