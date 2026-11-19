<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RbacService
{
    /**
     * Standard System Role Definitions.
     *
     * @return array<string, array{description: string, color: string, is_system: bool}>
     */
    public static function defaultRoles(): array
    {
        return [
            'Super Administrator' => [
                'description' => 'Unrestricted root-level access across all system modules, configurations, and audit trails.',
                'color' => 'violet',
                'is_system' => true,
            ],
            'Administrator' => [
                'description' => 'Full administrative access to manage users, roles, academics, students, and settings.',
                'color' => 'emerald',
                'is_system' => true,
            ],
            'Admissions Officer' => [
                'description' => 'Manage student admission inquiries, applications, selection lists, and candidate verification.',
                'color' => 'sky',
                'is_system' => false,
            ],
            'Faculty' => [
                'description' => 'Manage batches, attendance, course study materials, assignments, and examination test scores.',
                'color' => 'blue',
                'is_system' => false,
            ],
            'Accountant' => [
                'description' => 'Manage fee collections, financial receipts, installment schedules, and refund transactions.',
                'color' => 'amber',
                'is_system' => false,
            ],
            'Staff' => [
                'description' => 'Front-desk operations, general student record search, and general communications.',
                'color' => 'zinc',
                'is_system' => false,
            ],
            'Librarian' => [
                'description' => 'Manage library catalog, book accession registers, issue/return/renewal circulation, reservations, fines, and member cards.',
                'color' => 'teal',
                'is_system' => true,
            ],
            'Student' => [
                'description' => 'Student portal access for timetable, attendance, test scores, notifications, and profile details.',
                'color' => 'indigo',
                'is_system' => false,
            ],
        ];
    }

    /**
     * Standard System Grouped Permissions.
     *
     * @return array<string, array<string, string>> Module => [Permission Name => Description]
     */
    public static function defaultPermissions(): array
    {
        return [
            'Users' => [
                'users.view' => 'View users listing and profile cards',
                'users.create' => 'Create new system users and send invitations',
                'users.edit' => 'Update user details, identity information, and designations',
                'users.delete' => 'Soft-delete and purge system user accounts',
                'users.restore' => 'Restore soft-deleted user accounts',
                'users.lock' => 'Lock and unlock user login accounts',
                'users.export' => 'Export users to Excel, CSV, and PDF',
                'users.import' => 'Import batch users from Excel and CSV',
                'users.impersonate' => 'Login into any student/user dashboard anonymously',
                'activity.view' => 'View user activity log and security history',
            ],
            'Roles & Access' => [
                'roles.view' => 'View roles and permission matrices',
                'roles.create' => 'Create new custom roles',
                'roles.edit' => 'Modify role definitions and assigned permissions',
                'roles.delete' => 'Delete custom non-system roles',
                'roles.assign' => 'Assign and remove roles from users',
                'permissions.manage' => 'Create and customize system permission keys',
            ],
            'Students' => [
                'students.view' => 'View student master list and profile dossiers',
                'students.create' => 'Register and enroll new students',
                'students.edit' => 'Update student academic and personal information',
                'students.delete' => 'Archive and delete student profiles',
                'students.export' => 'Export student data and reports',
                'students.import' => 'Bulk import students from Excel and CSV',
                'enrollments.view' => 'View student course enrollments and history',
                'enrollments.manage' => 'Create, update, and change student course enrollments',
                'enrollments.delete' => 'Cancel or delete course enrollments',
                'enrollments.export' => 'Export course enrollments to Excel, CSV, and PDF',
                'enrollments.import' => 'Bulk import course enrollments from Excel and CSV',
                'enrollments.discontinue' => 'Directly cancel or discontinue student course enrollments',
                'enrollments.reinstate' => 'Revert discontinuation or withdrawal and reinstate students to active enrollment',
                'withdrawals.view' => 'View student course withdrawal and discontinuation requests',
                'withdrawals.review' => 'Approve or reject student course withdrawal and discontinuation requests',
                'students.discipline' => 'Impose disciplinary sanctions, suspensions, or lifetime bans on students',
                'students.lift_ban' => 'Revoke or lift student suspensions and bans',
                'profile_requests.view' => 'View student profile update correction requests',
                'profile_requests.review' => 'Approve or reject student profile update correction requests',
            ],
            'Admissions' => [
                'admissions.view' => 'View candidate applications and registrations',
                'admissions.review' => 'Review, verify, and approve admission applications',
                'admissions.manage_tests' => 'Schedule entrance tests and publish selection lists',
                'admissions.export' => 'Export admission candidates and merit lists',
                'admissions.otr_sync' => 'Connect to Admission OTR portal, fetch admitted candidates, and run imports into MIS',
                'admissions.otr_configure' => 'Configure Admission OTR API keys, endpoints, and automatic synchronization settings',
            ],
            'Academics' => [
                'courses.view' => 'View academic courses and curriculum',
                'courses.manage' => 'Create and configure academic courses, fees, and rules',
                'courses.delete' => 'Delete academic courses',
                'courses.export' => 'Export academic courses to Excel, CSV, and PDF',
                'courses.import' => 'Bulk import academic courses from Excel and CSV',
                'batches.view' => 'View academic batches and batch calendar',
                'batches.manage' => 'Create, update, and manage academic batches',
                'batches.delete' => 'Delete academic batches',
                'batches.export' => 'Export academic batches to Excel, CSV, and PDF',
                'batches.import' => 'Bulk import academic batches from Excel and CSV',
                'sections.manage' => 'Manage course sections and classroom allocation',
                'sections.import' => 'Bulk import classroom sections from Excel and CSV',
                'tests.manage' => 'Create academic assessments and record test scores',
            ],
            'Academic Calendar' => [
                'academic_calendar.view' => 'View institutional academic calendars, monthly planners, and version history',
                'academic_calendar.create' => 'Create new academic year calendars and add monthly activities',
                'academic_calendar.edit' => 'Modify academic calendar details and activities',
                'academic_calendar.revise' => 'Upload revised calendar documents and manage version control',
                'academic_calendar.delete' => 'Delete or archive academic calendars, versions, and activities',
                'academic_calendar.export' => 'Export academic calendar reports and schedules to PDF, Excel, and CSV',
                'academic_calendar.import' => 'Bulk import monthly activities from spreadsheets',
                'academic_calendar.download' => 'Download version-controlled academic calendar documents',
            ],
            'Class Routine & Faculty' => [
                'classes.view' => 'View classes, lecture timetable, and routine schedules',
                'classes.create' => 'Schedule regular and optional classes, batch recurrences',
                'classes.edit' => 'Modify class schedules, timings, faculty assignments, and rooms',
                'classes.delete' => 'Cancel and delete class schedules',
                'classes.recordings' => 'Upload class recordings and manage recording links',
                'classes.plans' => 'Create, publish, and revise comprehensive course plans',
                'classes.remind' => 'Send manual and automated reminder notification alerts to students',
                'classes.reports' => 'Generate and export faculty workload, syllabus coverage, and room reports',
                'classes.export' => 'Export class schedules and routine to Excel, CSV, and PDF',
                'classes.import' => 'Bulk import class routines from Excel and CSV',
                'classrooms.view' => 'View classrooms master list, capacity, and facilities',
                'classrooms.manage' => 'Create, edit, import, and configure classrooms',
                'classrooms.delete' => 'Delete or archive classrooms',
                'faculty.view' => 'View faculty master list, profiles, and subject assignments',
                'faculty.manage' => 'Create, edit, and assign faculty and quick-create teachers',
                'faculty.delete' => 'Delete faculty profiles and teaching history',
                'faculty.import' => 'Bulk import faculty profiles from Excel and CSV',
                'partner_institutes.view' => 'View partner coaching institutes list and collaboration details',
                'partner_institutes.manage' => 'Create, edit, and configure partner coaching institutes and MoUs',
                'partner_institutes.delete' => 'Delete partner coaching institutes',
                'partner_institutes.export' => 'Export partner coaching institutes directory to Excel, CSV, and PDF',
                'partner_institutes.import' => 'Bulk import partner coaching institutes from spreadsheet',
            ],
            'Optional Subjects' => [
                'optional_subjects.view' => 'View optional subjects catalog, student allocations, and reports',
                'optional_subjects.manage' => 'Create, edit, delete optional subjects and quick update student allocations',
                'optional_subjects.requests' => 'Review, approve, and reject optional subject change requests',
                'optional_subjects.export' => 'Export optional subjects rosters, allocations, and reports',
                'optional_subjects.import' => 'Import student optional subject assignments from spreadsheet',
            ],
            'Mock Interviews' => [
                'mock_interviews.view' => 'View mock interview sessions, board members, and candidate schedules',
                'mock_interviews.create' => 'Create new mock interview sessions and drives',
                'mock_interviews.edit' => 'Modify mock interview sessions, timings, venue, and instructions',
                'mock_interviews.delete' => 'Cancel or soft-delete mock interview sessions',
                'mock_interviews.members' => 'Manage board members pool, assign chairperson and panel members to sessions',
                'mock_interviews.students' => 'Assign students to sessions, reorder sequence, and configure interview slots',
                'mock_interviews.invite' => 'Draft with live preview and dispatch invitations via Email, SMS, WhatsApp, and Telegram',
                'mock_interviews.recordings' => 'Upload video recordings, attach recording URLs, and dispatch recording download links',
                'mock_interviews.export' => 'Export session candidate rosters, scorecards, and schedules to Excel, CSV, and PDF',
                'mock_interviews.import' => 'Bulk import mock interview candidates from Excel and CSV',
                'mock_interviews.scores' => 'Record panel evaluation scores, UPSC marks, and board feedback',
            ],
            'Student ID Cards' => [
                'student_id_cards.view' => 'View student ID cards listing and card preview',
                'student_id_cards.download' => 'Download official printable Student ID Card PDF',
                'student_id_cards.settings' => 'Configure student ID card system preferences and download access',
            ],
            'Tests & Examinations' => [
                'tests.view' => 'View examinations listing, merit lists, and rankers',
                'tests.create' => 'Schedule and create mock tests and examinations',
                'tests.edit' => 'Update test schedules, parameters, and model answers',
                'tests.delete' => 'Delete test records and question sets',
                'tests.results.manage' => 'Enter, evaluate, and import student test scores',
                'tests.reports.view' => 'View test-wise and student-wise performance analytics',
                'tests.export' => 'Export test results, merit lists, and marksheets',
                'tests.student.view' => 'Student portal access to view tests, marksheets, and leaderboard',
            ],
            'Attendance & Schedules' => [
                'attendance.view' => 'View attendance dashboards, daily logs, and register matrices',
                'attendance.manage' => 'Record and manage single and bulk attendance for staff and students',
                'attendance.delete' => 'Delete attendance entries',
                'attendance.export' => 'Export attendance logs and registers to Excel, CSV, and PDF',
                'attendance.import' => 'Import bulk attendance logs from Excel and CSV',
                'attendance.settings' => 'Configure attendance shifts, working calendars, and holiday schedules',
                'attendance.notify' => 'Dispatch manual and automated presence/absence alerts and reminders',
                'attendance.biometric' => 'Manage biometric devices, API tokens, and sync raw punch logs',
            ],
            'Leave Management' => [
                'leaves.view' => 'View leave applications and leave allocation balances',
                'leaves.apply' => 'Submit leave applications',
                'leaves.manage' => 'Approve, reject, or cancel leave applications',
                'leaves.categories.manage' => 'Configure dynamic leave categories, annual quotas, and policies',
                'leaves.export' => 'Export leave history and allocation registers',
            ],
            'Communications' => [
                'communications.view' => 'View SMS and Email delivery audit logs',
                'communications.send' => 'Compose and dispatch broadcast messages and campaigns',
                'communications.export' => 'Export message delivery audit logs to Excel and CSV',
                'templates.manage' => 'Create and update SMS and Email message templates',
            ],
            'Audit & Security' => [
                'audit.view' => 'Inspect comprehensive system audit logs and diff records',
                'audit.export' => 'Export audit logs to CSV and compliance reports',
                'audit.prune' => 'Purge historical audit log entries beyond retention window',
            ],
            'Reports & BI' => [
                'reports.view' => 'View institutional analytics and performance reports',
                'reports.export' => 'Export high-resolution analytical reports to PDF/Excel',
            ],
            'CMS & Content' => [
                'pages.manage' => 'Create, edit, and publish dynamic CMS pages and policies',
                'contacts.manage' => 'Manage contact inquiries, departments, and support tickets',
            ],
            'Document Management' => [
                'documents.view' => 'View institutional soft copy documents, files repository, and version history',
                'documents.create' => 'Upload new soft copy documents and files',
                'documents.edit' => 'Update document metadata, classification, and notes',
                'documents.revise' => 'Upload revised document versions with change notes',
                'documents.delete' => 'Soft-delete and archive soft copy documents and versions',
                'documents.restore' => 'Restore soft-deleted documents and versions',
                'documents.download' => 'Download document files across all versions',
                'documents.export' => 'Export documents register to Excel, CSV, and PDF',
                'documents.import' => 'Bulk import document records from spreadsheets',
            ],
            'Support & Helpdesk' => [
                'tickets.view' => 'View support tickets list and conversations',
                'tickets.create' => 'Open new support tickets on behalf of users',
                'tickets.reply' => 'Post public responses to support tickets',
                'tickets.internal_notes' => 'Post internal staff-only notes on tickets',
                'tickets.assign' => 'Assign and reassign ticket owners and agents',
                'tickets.manage' => 'Update ticket statuses, priorities, SLAs, and delete tickets',
                'tickets.categories' => 'Manage support ticket categories and SLA rules',
                'tickets.canned_responses' => 'Manage canned responses and response macros',
            ],
            'Marketing & Subscribers' => [
                'subscribers.view' => 'View newsletter & WhatsApp subscribers list and metrics',
                'subscribers.manage' => 'Add, edit, change status, and delete subscribers',
                'subscribers.export' => 'Export subscribers list to CSV and Excel',
                'subscribers.import' => 'Import subscribers in bulk from CSV files',
            ],
            'Live Chat & Calls' => [
                'chat.access' => 'Access live chat portal and send messages',
                'chat.create_group' => 'Create and administer group conversations',
                'chat.create_channel' => 'Create and publish broadcast channels',
                'chat.broadcast' => 'Dispatch bulk personalized broadcast messages to users',
                'chat.voice_call' => 'Initiate and receive WebRTC voice audio calls',
                'chat.video_call' => 'Initiate and receive WebRTC video calls',
                'chat.pin_message' => 'Pin and unpin messages in conversations',
                'chat.delete_for_everyone' => 'Delete messages for everyone in group or channel',
                'chat.manage_settings' => 'Configure live chat and WebRTC calling parameters',
            ],
            'Online Meetings' => [
                'meetings.view' => 'View online meetings list and upcoming sessions',
                'meetings.create' => 'Create instant and schedule online meetings',
                'meetings.manage' => 'Edit, update, and cancel scheduled online meetings',
                'meetings.co_host' => 'Act as co-host, admit waiting room attendees, and manage restrictions',
                'meetings.manage_settings' => 'Configure global online meeting duration and feature policies',
            ],
            'Fees & Payments' => [
                'payments.view' => 'View payment transactions, fee schedules, and student invoices',
                'payments.create' => 'Record offline payments, collect fees, and initiate billing schedules',
                'payments.verify' => 'Verify and approve offline payments (NEFT/IMPS/UPI/Cash)',
                'payments.reconcile' => 'Reconcile gateway payments, sync transaction status, and manually resolve payment disputes',
                'payments.refund' => 'Process fee and security deposit refund requests',
                'payments.export' => 'Export fee collections, ledger, and transaction reports',
                'payments.import' => 'Import offline payment records in bulk',
                'payments.reminders' => 'Send payment reminders via SMS, WhatsApp, and Email',
                'payments.gateways' => 'Configure online and offline payment gateways',
                'payments.delete' => 'Delete or revert fee schedules and student billing records',
                'library_deposit.manage' => 'Manage library security deposits and withdrawal processing',
            ],
            'Library Management' => [
                'library.view' => 'Browse library catalog, search books, and view book details',
                'library.books.manage' => 'Create, update, and manage book catalog records and image galleries',
                'library.books.delete' => 'Delete catalog books and copies',
                'library.accession.manage' => 'Acquisition and accession registers, unique accession numbering, and barcode printing',
                'library.authors.manage' => 'Create, update, and manage book authors',
                'library.publishers.manage' => 'Create, update, and manage book publishers',
                'library.categories.manage' => 'Create, update, and manage book categories and classification codes',
                'library.members.manage' => 'Register and manage library members, membership cards, and security deposit links',
                'library.issue.manage' => 'Issue, return, and renew books, with security deposit validation and bypass authorization',
                'library.reservations.manage' => 'Review, fulfill, and cancel book reservation requests',
                'library.fines.manage' => 'View, assess, collect, and waive overdue library fines',
                'library.reminders.send' => 'Dispatch automated and on-demand return reminders via In-App, Email, SMS, WhatsApp, Telegram',
                'library.reports.view' => 'Inspect library circulation, accession register, defaulters, and inventory reports',
                'library.export' => 'Export library catalog, accessioning, and circulation data to Excel, CSV, and PDF',
                'library.import' => 'Import books and accession copies in bulk from Excel and CSV',
                'library.settings.manage' => 'Configure institutional library loan durations, fines, and borrowing policies',
                'library.reserve.create' => 'Request and reserve books from student and member portals',
            ],
            'Alumni Management' => [
                'alumni.view' => 'View alumni directory, profiles, and achievement dossiers',
                'alumni.create' => 'Create new alumni records manually or convert enrolled students',
                'alumni.edit' => 'Update alumni profile, employment, posting, and service details',
                'alumni.delete' => 'Soft-delete or remove alumni records',
                'alumni.restore' => 'Restore soft-deleted alumni records',
                'alumni.export' => 'Export alumni directory to Excel, CSV, and PDF',
                'alumni.import' => 'Bulk import alumni records from Excel and CSV',
                'alumni.featured' => 'Feature, verify, and highlight alumni in directory showcase',
                'alumni.categories' => 'Manage dynamic alumni service categories, badges, and classifications',
            ],
            'Study Materials' => [
                'study_materials.view' => 'View course and batch study materials and resources',
                'study_materials.create' => 'Upload and publish new study materials, notes, and lectures',
                'study_materials.edit' => 'Edit study material details, files, and batch associations',
                'study_materials.delete' => 'Soft-delete or remove study materials',
                'study_materials.restore' => 'Restore soft-deleted study materials',
                'study_materials.share' => 'Share study materials with students via Email, SMS, WhatsApp, Telegram, or public link',
                'study_materials.export' => 'Export study materials list to Excel, CSV, and PDF',
                'study_materials.import' => 'Bulk import study materials from Excel and CSV',
                'study_materials.download' => 'Download or access study material files and contents',
            ],
            'Hostel Management' => [
                'hostel.view' => 'View hostel dashboard, buildings, floors, rooms, and bed statuses',
                'hostel.buildings.manage' => 'Create, edit, and configure hostel buildings and floors',
                'hostel.rooms.manage' => 'Create, edit, and configure rooms, sharing beds, gender restrictions, and maintenance',
                'hostel.allocations.manage' => 'Allocate, check-in, extend, and vacate student and staff room accommodations',
                'hostel.allocations.view' => 'View hostel allocations, room histories, and resident details',
                'hostel.requests.manage' => 'Approve or reject resident accommodation extension requests with reason',
                'hostel.forms.manage' => 'Track and verify physical hard-copy and uploaded soft-copy hostel admission forms',
                'hostel.reports.view' => 'View and export hostel occupancy, gender analytics, and resident reports',
                'hostel.settings.manage' => 'Configure hostel settings including extension request enablement and softcopy requirements',
            ],
            'API & Integrations' => [
                'api_keys.view' => 'View API keys, usage statistics, and integration telemetry',
                'api_keys.create' => 'Generate new API keys for external applications and services',
                'api_keys.edit' => 'Modify API key scopes, IP whitelists, and rate limits',
                'api_keys.revoke' => 'Revoke or suspend active API keys immediately',
                'api_keys.delete' => 'Delete or purge API key records and telemetry logs',
                'api_keys.rotate' => 'Regenerate and roll API key secrets',
                'api_keys.logs' => 'Inspect incoming API request logs and error traces',
            ],
            'System Settings' => [
                'settings.general' => 'Configure institution identity, logos, and general settings',
                'settings.appearance' => 'Configure themes, logos, branding, and color palettes',
                'settings.localization' => 'Configure default language, timezones, and date formats',
                'settings.security' => 'Configure authentication rules and lockout security',
                'settings.captcha' => 'Configure Google reCAPTCHA, custom CAPTCHA service, security rules, and bot defense logs',
                'settings.email' => 'Configure SMTP, email delivery, and testing',
                'settings.sms' => 'Configure SMS gateways (2Factor, Fast2SMS, MSG91)',
                'settings.whatsapp' => 'Configure WhatsApp business gateway and notifications',
                'settings.telegram' => 'Configure Telegram bot integration and alerts',
                'settings.payment' => 'Configure payment gateways (Razorpay, PhonePe)',
                'settings.storage' => 'Configure Amazon S3, MinIO, Cloudflare R2, and cloud object storage disks and policies',
                'settings.backup' => 'Manage, trigger on-demand backups, and download database/media archives',
                'settings.cron' => 'Manage automated scheduled system tasks and cron runners',
                'settings.chat' => 'Configure live chat, WebRTC, and meeting server parameters',
            ],
            'Global Search' => [
                'global_search.access' => 'Access and execute global search across system modules and permissible records',
            ],
        ];
    }

    /**
     * Seed or sync default system roles and permissions in database.
     */
    public static function seedDefaults(): void
    {
        DB::transaction(function () {
            // Seed permissions
            foreach (static::defaultPermissions() as $module => $permissions) {
                foreach ($permissions as $name => $description) {
                    Permission::updateOrCreate(
                        ['name' => $name, 'guard_name' => 'web'],
                        ['module' => $module, 'description' => $description]
                    );
                }
            }

            // Seed roles
            foreach (static::defaultRoles() as $roleName => $meta) {
                $role = Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => 'web'],
                    [
                        'description' => $meta['description'],
                        'color' => $meta['color'],
                        'is_system' => $meta['is_system'],
                    ]
                );

                // Assign default permissions to Super Administrator & Administrator
                if ($roleName === 'Super Administrator') {
                    $role->syncPermissions(Permission::where('guard_name', 'web')->get());
                } elseif ($roleName === 'Administrator') {
                    $role->syncPermissions(Permission::where('guard_name', 'web')->where('name', 'not like', 'audit.prune')->get());
                } elseif ($roleName === 'Admissions Officer') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'students.create', 'admissions.view', 'admissions.review', 'admissions.manage_tests', 'admissions.otr_sync', 'admissions.otr_configure', 'communications.view',
                        'withdrawals.view', 'withdrawals.review', 'enrollments.discontinue', 'enrollments.reinstate', 'students.discipline',
                        'chat.access', 'chat.create_group', 'chat.create_channel', 'chat.voice_call', 'chat.video_call',
                        'meetings.view', 'meetings.create', 'meetings.manage', 'meetings.co_host',
                        'alumni.view', 'alumni.create', 'alumni.edit', 'alumni.export', 'alumni.categories',
                        'optional_subjects.view', 'optional_subjects.manage', 'optional_subjects.requests', 'optional_subjects.export', 'optional_subjects.import',
                        'student_id_cards.view', 'student_id_cards.download',
                        'classes.view', 'classes.export', 'classrooms.view', 'faculty.view',
                        'academic_calendar.view', 'academic_calendar.download', 'academic_calendar.export',
                        'documents.view', 'documents.download', 'activity.view',
                    ])->get());
                } elseif ($roleName === 'Faculty') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'courses.manage', 'batches.manage', 'sections.manage', 'tests.manage',
                        'tests.view', 'tests.create', 'tests.edit', 'tests.results.manage', 'tests.reports.view', 'tests.export',
                        'attendance.view', 'attendance.manage', 'attendance.notify', 'leaves.view', 'leaves.apply',
                        'chat.access', 'chat.create_group', 'chat.create_channel', 'chat.voice_call', 'chat.video_call',
                        'meetings.view', 'meetings.create', 'meetings.manage', 'meetings.co_host',
                        'alumni.view',
                        'study_materials.view', 'study_materials.create', 'study_materials.edit', 'study_materials.delete', 'study_materials.share', 'study_materials.export', 'study_materials.download',
                        'optional_subjects.view', 'optional_subjects.export',
                        'classes.view', 'classes.create', 'classes.edit', 'classes.recordings', 'classes.plans', 'classes.remind', 'classes.reports', 'classes.export',
                        'classrooms.view', 'faculty.view', 'partner_institutes.view',
                        'mock_interviews.view', 'mock_interviews.scores', 'mock_interviews.recordings',
                        'academic_calendar.view', 'academic_calendar.download', 'academic_calendar.export',
                        'documents.view', 'documents.create', 'documents.revise', 'documents.download', 'documents.export',
                        'activity.view',
                    ])->get());
                } elseif ($roleName === 'Accountant') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'reports.view', 'reports.export',
                        'payments.view', 'payments.create', 'payments.verify', 'payments.reconcile', 'payments.refund',
                        'payments.export', 'payments.import', 'payments.reminders', 'payments.delete', 'library_deposit.manage',
                        'chat.access', 'chat.voice_call', 'chat.video_call', 'meetings.view',
                        'academic_calendar.view', 'academic_calendar.download',
                        'documents.view', 'documents.download', 'activity.view',
                    ])->get());
                } elseif ($roleName === 'Staff') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'admissions.view', 'contacts.manage', 'tickets.view', 'tickets.reply', 'tickets.internal_notes',
                        'attendance.view', 'leaves.view', 'leaves.apply',
                        'chat.access', 'chat.create_group', 'chat.create_channel', 'chat.voice_call', 'chat.video_call',
                        'meetings.view', 'meetings.create',
                        'alumni.view', 'alumni.create',
                        'study_materials.view', 'study_materials.download',
                        'hostel.view', 'hostel.allocations.view', 'hostel.allocations.manage', 'hostel.requests.manage', 'hostel.forms.manage', 'hostel.reports.view',
                        'optional_subjects.view', 'optional_subjects.requests',
                        'student_id_cards.view', 'student_id_cards.download',
                        'classes.view', 'classes.export', 'faculty.view', 'partner_institutes.view',
                        'mock_interviews.view', 'mock_interviews.students', 'mock_interviews.invite', 'mock_interviews.recordings',
                        'academic_calendar.view', 'academic_calendar.download', 'academic_calendar.export',
                        'documents.view', 'documents.create', 'documents.revise', 'documents.download',
                        'activity.view',
                    ])->get());
                } elseif ($roleName === 'Librarian') {
                    $role->syncPermissions(Permission::where('guard_name', 'web')->where(function ($q) {
                        $q->where('name', 'like', 'library.%')
                            ->orWhereIn('name', [
                                'library_deposit.manage', 'students.view', 'reports.view', 'reports.export',
                                'chat.access', 'chat.voice_call', 'chat.video_call', 'meetings.view',
                                'attendance.view', 'leaves.view', 'leaves.apply',
                                'academic_calendar.view', 'academic_calendar.download',
                                'documents.view', 'documents.create', 'documents.revise', 'documents.download',
                                'activity.view',
                            ]);
                    })->get());
                } elseif ($roleName === 'Student') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'chat.access', 'chat.voice_call', 'chat.video_call', 'meetings.view',
                        'library.view', 'library.reserve.create',
                        'attendance.view', 'leaves.view', 'leaves.apply',
                        'study_materials.view', 'study_materials.download',
                        'tests.student.view',
                        'academic_calendar.view', 'academic_calendar.download',
                        'documents.view', 'documents.download',
                        'activity.view',
                    ])->get());
                }
            }
        });
    }

    /**
     * Create a new role with DB transaction and audit logging.
     *
     * @param  array{name: string, description?: string|null, color?: string, permissions?: array<string>}  $data
     */
    public static function createRole(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'description' => $data['description'] ?? null,
                'color' => $data['color'] ?? 'emerald',
                'is_system' => false,
            ]);

            if (! empty($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            AuditLogService::log(
                event: 'role_created',
                description: "Created new role '{$role->name}' with ".(count($data['permissions'] ?? [])).' permissions.',
                auditable: $role,
                newValues: ['name' => $role->name, 'permissions' => $data['permissions'] ?? []]
            );

            return $role;
        });
    }

    /**
     * Update an existing role.
     *
     * @param  array{name?: string, description?: string|null, color?: string, permissions?: array<string>}  $data
     */
    public static function updateRole(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $oldValues = [
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'permissions' => $role->permissions->pluck('name')->all(),
            ];

            // Protect system role names
            $updatePayload = [];
            if (! $role->is_system && isset($data['name'])) {
                $updatePayload['name'] = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $updatePayload['description'] = $data['description'];
            }
            if (isset($data['color'])) {
                $updatePayload['color'] = $data['color'];
            }

            if (! empty($updatePayload)) {
                $role->update($updatePayload);
            }

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            $newValues = [
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'permissions' => $role->permissions()->pluck('name')->all(),
            ];

            AuditLogService::log(
                event: 'role_updated',
                description: "Updated role '{$role->name}'.",
                auditable: $role,
                oldValues: $oldValues,
                newValues: $newValues
            );

            return $role;
        });
    }

    /**
     * Clone an existing role into a new custom role with same permissions.
     */
    public static function cloneRole(Role $sourceRole, string $newName, ?string $newDescription = null): Role
    {
        return DB::transaction(function () use ($sourceRole, $newName, $newDescription) {
            $newRole = Role::create([
                'name' => $newName,
                'guard_name' => 'web',
                'description' => $newDescription ?: "Cloned from {$sourceRole->name}",
                'color' => $sourceRole->color,
                'is_system' => false,
            ]);

            $permissions = $sourceRole->permissions->pluck('name')->all();
            $newRole->syncPermissions($permissions);

            AuditLogService::log(
                event: 'role_cloned',
                description: "Cloned role '{$sourceRole->name}' into new role '{$newRole->name}' with ".count($permissions).' permissions.',
                auditable: $newRole,
                newValues: ['name' => $newRole->name, 'source_role' => $sourceRole->name, 'permissions' => $permissions]
            );

            return $newRole;
        });
    }

    /**
     * Delete a custom role safely.
     */
    public static function deleteRole(Role $role): bool
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'System default roles cannot be deleted to prevent access lockout.',
            ]);
        }

        return DB::transaction(function () use ($role) {
            $userCount = $role->users()->count();
            if ($userCount > 0) {
                throw ValidationException::withMessages([
                    'role' => "Cannot delete role '{$role->name}' because {$userCount} users are currently assigned to it. Reassign these users first.",
                ]);
            }

            AuditLogService::log(
                event: 'role_deleted',
                description: "Deleted role '{$role->name}'.",
                auditable: $role,
                oldValues: ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()]
            );

            return (bool) $role->delete();
        });
    }

    /**
     * Assign roles to a user with audit tracking.
     *
     * @param  array<string>  $roleNames
     */
    public static function assignRolesToUser(User $user, array $roleNames, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $roleNames, $actor) {
            $oldRoles = $user->roles->pluck('name')->all();

            $user->syncRoles($roleNames);

            AuditLogService::log(
                event: 'user_roles_synced',
                description: "Updated roles for user '{$user->name}' to: ".implode(', ', $roleNames),
                auditable: $user,
                userId: $actor?->id,
                oldValues: ['roles' => $oldRoles],
                newValues: ['roles' => $roleNames]
            );
        });
    }

    /**
     * Create a new granular permission.
     */
    public static function createPermission(string $name, string $module = 'General', ?string $description = null): Permission
    {
        return DB::transaction(function () use ($name, $module, $description) {
            $perm = Permission::create([
                'name' => $name,
                'guard_name' => 'web',
                'module' => $module,
                'description' => $description,
            ]);

            AuditLogService::log(
                event: 'permission_created',
                description: "Created permission key '{$perm->name}' under module '{$perm->module}'.",
                auditable: $perm,
                newValues: ['name' => $perm->name, 'module' => $perm->module, 'description' => $perm->description]
            );

            return $perm;
        });
    }

    /**
     * Update an existing permission.
     */
    public static function updatePermission(Permission $permission, string $name, string $module, ?string $description = null): Permission
    {
        return DB::transaction(function () use ($permission, $name, $module, $description) {
            $oldValues = [
                'name' => $permission->name,
                'module' => $permission->module,
                'description' => $permission->description,
            ];

            $permission->update([
                'name' => $name,
                'module' => $module,
                'description' => $description,
            ]);

            AuditLogService::log(
                event: 'permission_updated',
                description: "Updated permission '{$permission->name}'.",
                auditable: $permission,
                oldValues: $oldValues,
                newValues: ['name' => $permission->name, 'module' => $permission->module, 'description' => $permission->description]
            );

            return $permission;
        });
    }

    /**
     * Delete a permission.
     */
    public static function deletePermission(Permission $permission): bool
    {
        return DB::transaction(function () use ($permission) {
            AuditLogService::log(
                event: 'permission_deleted',
                description: "Deleted permission '{$permission->name}' from module '{$permission->module}'.",
                auditable: $permission,
                oldValues: ['name' => $permission->name, 'module' => $permission->module]
            );

            return (bool) $permission->delete();
        });
    }

    /**
     * Synchronize roles for a user.
     */
    public static function syncUserRoles(User $user, string|array $roles): void
    {
        $roles = is_array($roles) ? $roles : [$roles];
        $user->syncRoles($roles);
    }

    /**
     * Soft delete a user with audit tracking.
     */
    public static function softDeleteUser(User $user, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($user, $actor) {
            $actorId = $actor?->id ?? auth()->id();
            if ($actorId && empty($user->deleted_by)) {
                $user->deleted_by = $actorId;
                $user->saveQuietly();
            }

            $deleted = (bool) $user->delete();

            if ($deleted) {
                AuditLogService::log(
                    event: 'user_soft_deleted',
                    description: "Soft deleted user '{$user->name}' (ID: {$user->id}, Email: {$user->email})",
                    auditable: $user,
                    userId: $actorId
                );
            }

            return $deleted;
        });
    }

    /**
     * Restore a soft-deleted user with audit tracking.
     */
    public static function restoreUser(User $user, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($user, $actor) {
            $actorId = $actor?->id ?? auth()->id();
            $user->deleted_by = null;
            $user->saveQuietly();

            $restored = (bool) $user->restore();

            if ($restored) {
                AuditLogService::log(
                    event: 'user_restored',
                    description: "Restored user '{$user->name}' (ID: {$user->id}, Email: {$user->email})",
                    auditable: $user,
                    userId: $actorId
                );
            }

            return $restored;
        });
    }

    /**
     * Permanently purge a user with audit tracking.
     */
    public static function forceDeleteUser(User $user, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($user, $actor) {
            $actorId = $actor?->id ?? auth()->id();
            $userName = $user->name;
            $userId = $user->id;
            $userEmail = $user->email;

            AuditLogService::log(
                event: 'user_force_deleted',
                description: "Permanently purged user '{$userName}' (ID: {$userId}, Email: {$userEmail})",
                auditable: null,
                userId: $actorId
            );

            return (bool) $user->forceDelete();
        });
    }
}
