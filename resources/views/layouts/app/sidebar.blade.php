@php
    $user = auth()->user();
    $openTicketsCount = $user ? $user->openTicketsCount() : 0;
    $pendingProfileRequestsCount = \App\Models\ProfileUpdateRequest::where('status', 'pending')->count();
    $pendingWithdrawalsCount = \App\Models\CourseWithdrawalRequest::where('status', 'pending')->count();
    $pendingOfflinePaymentsCount = ($user && ($user->can('payments.verify') || $user->hasRole('Super Administrator')))
        ? \App\Models\PaymentTransaction::where('is_offline', true)->where('status', 'under_review')->count()
        : 0;
    $pendingLibraryRefundsCount = ($user && ($user->can('library_deposit.manage') || $user->hasRole('Super Administrator')))
        ? \App\Models\LibraryDeposit::where('status', 'refund_requested')->count()
        : 0;
    $pendingLeaveApplicationsCount = ($user && ($user->can('leaves.manage') || $user->hasRole('Super Administrator')))
        ? \App\Models\LeaveApplication::where('status', 'pending')->count()
        : 0;
    $pendingHostelRequestsCount = ($user && ($user->can('hostel.requests.manage') || $user->hasRole('Super Administrator')))
        ? \App\Models\HostelExtensionRequest::where('status', 'pending')->count()
        : 0;
    $pendingOptionalSubjectRequestsCount = ($user && ($user->can('optional_subjects.requests') || $user->hasRole('Super Administrator') || $user->hasRole('Administrator')))
        ? \App\Models\OptionalSubjectChangeRequest::where('status', 'pending')->count()
        : 0;

    $isStudent = $user && $user->isStudent();
    $hasHostelHistory = $user && $user->hostelAllocations()->exists();

    $mainNavItems = [
        ['icon' => 'layout-dashboard', 'label' => __('Dashboard'), 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard') || request()->routeIs('student.dashboard')],
    ];

    if ($isStudent) {
        $mainNavItems[] = ['icon' => 'graduation-cap', 'label' => __('Academics & Enrolment'), 'href' => route('student.academics.index'), 'active' => request()->routeIs('student.academics.*') || request()->routeIs('student.enrollments.*')];
        $mainNavItems[] = ['icon' => 'calendar-days', 'label' => __('Academic Calendar'), 'href' => route('student.academic-calendar.index'), 'active' => request()->routeIs('student.academic-calendar.*'), 'permission' => 'academic_calendar.view'];
        $mainNavItems[] = ['icon' => 'calendar', 'label' => __('Class Schedule & Routine'), 'href' => route('student.routine.index'), 'active' => request()->routeIs('student.routine.*')];
        $mainNavItems[] = ['icon' => 'award', 'label' => __('UPSC Mock Interviews'), 'href' => route('student.mock-interviews.index'), 'active' => request()->routeIs('student.mock-interviews.*')];
        $mainNavItems[] = ['icon' => 'clipboard-check', 'label' => __('My Tests & Results'), 'href' => route('student.tests.index'), 'active' => request()->routeIs('student.tests.index')];
        $mainNavItems[] = ['icon' => 'trophy', 'label' => __('Rankers & Leaderboard'), 'href' => route('student.tests.leaderboard'), 'active' => request()->routeIs('student.tests.leaderboard*')];
        $mainNavItems[] = ['icon' => 'folder-open', 'label' => __('Study Materials'), 'href' => route('student.study-materials.index'), 'active' => request()->routeIs('student.study-materials.*'), 'permission' => 'study_materials.view'];
        $mainNavItems[] = ['icon' => 'calendar-check', 'label' => __('My Attendance'), 'href' => route('student.attendance.index'), 'active' => request()->routeIs('student.attendance.*')];
        $mainNavItems[] = ['icon' => 'calendar-days', 'label' => __('My Leaves'), 'href' => route('student.leaves.index'), 'active' => request()->routeIs('student.leaves.*')];
        $mainNavItems[] = ['icon' => 'receipt', 'label' => __('Fees & Invoices'), 'href' => route('student.payments.index'), 'active' => request()->routeIs('student.payments.*')];
        $mainNavItems[] = ['icon' => 'book-open', 'label' => __('Library Security Deposit'), 'href' => route('student.library-deposit.index'), 'active' => request()->routeIs('student.library-deposit.*')];
        $mainNavItems[] = ['icon' => 'book', 'label' => __('Library & Catalog'), 'href' => route('student.library.index'), 'active' => request()->routeIs('student.library.index')];
        $mainNavItems[] = ['icon' => 'id-card', 'label' => __('Digital Library Card'), 'href' => route('student.library.card'), 'active' => request()->routeIs('student.library.card')];
        $mainNavItems[] = ['icon' => 'id-card', 'label' => __('Student ID Card'), 'href' => route('student.id-card.index'), 'active' => request()->routeIs('student.id-card.*')];
        if ($hasHostelHistory) {
            $mainNavItems[] = ['icon' => 'bed', 'label' => __('My Hostel'), 'href' => route('student.hostel.index'), 'active' => request()->routeIs('student.hostel.*')];
        }
        $mainNavItems[] = ['icon' => 'activity', 'label' => __('My Activity'), 'href' => route('admin.user-activity.index'), 'active' => request()->routeIs('admin.user-activity.*'), 'permission' => 'activity.view'];
    } elseif ($user) {
        $mainNavItems[] = ['icon' => 'calendar-check', 'label' => __('My Attendance'), 'href' => route('portal.my-attendance.index'), 'active' => request()->routeIs('portal.my-attendance.*')];
        $mainNavItems[] = ['icon' => 'calendar-days', 'label' => __('My Leaves'), 'href' => route('portal.my-leaves.index'), 'active' => request()->routeIs('portal.my-leaves.*')];
        if ($hasHostelHistory || $user->can('hostel.view') || $user->hasRole('Super Administrator')) {
            $mainNavItems[] = ['icon' => 'bed', 'label' => __('My Hostel'), 'href' => route('portal.my-hostel.index'), 'active' => request()->routeIs('portal.my-hostel.*')];
        }
        $mainNavItems[] = ['icon' => 'activity', 'label' => __('My Activity'), 'href' => route('admin.user-activity.index'), 'active' => request()->routeIs('admin.user-activity.*'), 'permission' => 'activity.view'];
    }

    $chatEnabled = (bool) \App\Models\Setting::get('chat.enabled', true);
    $meetingsEnabled = (bool) \App\Models\Setting::get('meeting.enabled', \App\Models\Setting::get('chat.meetings_enabled', true));

    $hasChatAccess = $chatEnabled && $user && ($user->can('chat.access') || $user->hasRole('Super Administrator'));
    $hasMeetingsAccess = $meetingsEnabled && $user && ($user->can('meetings.view') || $user->hasRole('Super Administrator'));
    $unreadChatCount = ($hasChatAccess && $user) ? $user->unreadChatMessagesCount() : 0;

    if ($hasChatAccess) {
        $mainNavItems[] = ['icon' => 'message-square', 'label' => __('Live Chat'), 'href' => route('admin.chat.index'), 'active' => request()->routeIs('admin.chat.*') || request()->routeIs('chat.*'), 'badge' => $unreadChatCount > 0 ? ($unreadChatCount > 99 ? '99+' : (string) $unreadChatCount) : null];
    }
    if ($hasMeetingsAccess) {
        $mainNavItems[] = ['icon' => 'video', 'label' => __('Online Meetings'), 'href' => route('meetings.index'), 'active' => request()->routeIs('meetings.*')];
    }

    $rawNavSections = [
        [
            'title' => __('MAIN'),
            'items' => $mainNavItems,
        ],
        [
            'title' => __('STUDENTS'),
            'items' => [
                ['icon' => 'users', 'label' => __('Students'), 'href' => route('admin.students.index'), 'active' => request()->routeIs('admin.students.index'), 'permission' => 'students.view'],
                ['icon' => 'file-text', 'label' => __('Information Sheets'), 'href' => route('admin.students.information-sheet'), 'active' => request()->routeIs('admin.students.information-sheet*'), 'permission' => 'students.view'],
                ['icon' => 'id-card', 'label' => __('Student ID Cards'), 'href' => route('admin.student-id-cards.index'), 'active' => request()->routeIs('admin.student-id-cards.*'), 'permission' => 'student_id_cards.view'],
                ['icon' => 'book-open', 'label' => __('Optional Subjects'), 'href' => route('admin.optional-subjects.index'), 'active' => request()->routeIs('admin.optional-subjects.*'), 'permission' => 'optional_subjects.view', 'badge' => $pendingOptionalSubjectRequestsCount > 0 ? (string) $pendingOptionalSubjectRequestsCount : null],
                ['icon' => 'file-text', 'label' => __('Admissions'), 'href' => route('admin.admissions.index'), 'active' => request()->routeIs('admin.admissions.*'), 'permission' => 'admissions.view'],
                ['icon' => 'book-open', 'label' => __('Enrollments'), 'href' => route('admin.enrollments.index'), 'active' => request()->routeIs('admin.enrollments.*'), 'permission' => 'students.view'],
                ['icon' => 'user-minus', 'label' => __('Course Withdrawals'), 'href' => route('admin.course-withdrawals.index'), 'active' => request()->routeIs('admin.course-withdrawals.*'), 'permission' => 'withdrawals.view', 'badge' => $pendingWithdrawalsCount > 0 ? (string) $pendingWithdrawalsCount : null],
                ['icon' => 'edit', 'label' => __('Profile Requests'), 'href' => route('admin.profile-requests.index'), 'active' => request()->routeIs('admin.profile-requests.*'), 'permission' => 'students.view', 'badge' => $pendingProfileRequestsCount > 0 ? (string) $pendingProfileRequestsCount : null],
            ],
        ],
        [
            'title' => __('ALUMNI'),
            'items' => [
                ['icon' => 'award', 'label' => __('Alumni Directory'), 'href' => route('admin.alumni.index'), 'active' => request()->routeIs('admin.alumni.index'), 'permission' => 'alumni.view'],
                ['icon' => 'bar-chart-2', 'label' => __('Alumni Analytics'), 'href' => route('admin.alumni.statistics'), 'active' => request()->routeIs('admin.alumni.statistics*'), 'permission' => 'alumni.view'],
            ],
        ],
        [
            'title' => __('ACADEMICS'),
            'items' => [
                ['icon' => 'graduation-cap', 'label' => __('Courses'), 'href' => route('admin.courses.index'), 'active' => request()->routeIs('admin.courses.*'), 'permission' => 'courses.manage'],
                ['icon' => 'layers', 'label' => __('Batches'), 'href' => route('admin.batches.index'), 'active' => request()->routeIs('admin.batches.*'), 'permission' => 'batches.manage'],
                ['icon' => 'columns', 'label' => __('Sections'), 'href' => route('admin.sections.index'), 'active' => request()->routeIs('admin.sections.*'), 'permission' => 'sections.manage'],
                ['icon' => 'calendar-days', 'label' => __('Academic Calendar'), 'href' => route('admin.academic-calendar.index'), 'active' => request()->routeIs('admin.academic-calendar.*'), 'permission' => 'academic_calendar.view', 'admin_only' => true],
                ['icon' => 'calendar', 'label' => __('Class Routine & Schedule'), 'href' => route('admin.classes.index'), 'active' => request()->routeIs('admin.classes.index'), 'permission' => 'classes.view'],
                ['icon' => 'book-open', 'label' => __('Course Plans'), 'href' => route('admin.classes.plans'), 'active' => request()->routeIs('admin.classes.plans*'), 'permission' => 'classes.plans'],
                ['icon' => 'building-2', 'label' => __('Classrooms Master'), 'href' => route('admin.classrooms.index'), 'active' => request()->routeIs('admin.classrooms.*'), 'permission' => 'classrooms.view'],
                ['icon' => 'users', 'label' => __('Faculty Master'), 'href' => route('admin.faculties.index'), 'active' => request()->routeIs('admin.faculties.*'), 'permission' => 'faculty.view'],
                ['icon' => 'landmark', 'label' => __('Partner Institutes'), 'href' => route('admin.partner-institutes.index'), 'active' => request()->routeIs('admin.partner-institutes.*'), 'permission' => 'partner_institutes.view'],
                ['icon' => 'clipboard-check', 'label' => __('Tests & Examinations'), 'href' => route('admin.tests.index'), 'active' => request()->routeIs('admin.tests.index') || request()->routeIs('admin.tests.results'), 'permission' => 'tests.manage'],
                ['icon' => 'bar-chart-3', 'label' => __('Test Performance Reports'), 'href' => route('admin.tests.reports'), 'active' => request()->routeIs('admin.tests.reports*'), 'permission' => 'tests.manage'],
                ['icon' => 'trophy', 'label' => __('Leaderboard & Rankers'), 'href' => route('admin.tests.leaderboard'), 'active' => request()->routeIs('admin.tests.leaderboard*'), 'permission' => 'tests.manage'],
                ['icon' => 'folder-open', 'label' => __('Study Materials'), 'href' => route('admin.study-materials.index'), 'active' => request()->routeIs('admin.study-materials.index'), 'permission' => 'study_materials.view', 'admin_only' => true],
                ['icon' => 'bar-chart-2', 'label' => __('Materials Analytics'), 'href' => route('admin.study-materials.statistics'), 'active' => request()->routeIs('admin.study-materials.statistics*'), 'permission' => 'study_materials.view', 'admin_only' => true],
            ],
        ],
        [
            'title' => __('MOCK INTERVIEWS'),
            'items' => [
                ['icon' => 'award', 'label' => __('UPSC Mock Interviews'), 'href' => route('admin.mock-interviews.index'), 'active' => request()->routeIs('admin.mock-interviews.*'), 'permission' => 'mock_interviews.view'],
            ],
        ],
        [
            'title' => __('ATTENDANCE & SHIFTS'),
            'items' => [
                ['icon' => 'gauge', 'label' => __('Attendance Dashboard'), 'href' => route('admin.attendance.index'), 'active' => request()->routeIs('admin.attendance.index'), 'permission' => 'attendance.view', 'admin_only' => true],
                ['icon' => 'check-square', 'label' => __('Mark Attendance'), 'href' => route('admin.attendance.mark'), 'active' => request()->routeIs('admin.attendance.mark*'), 'permission' => 'attendance.manage'],
                ['icon' => 'bar-chart-2', 'label' => __('Reports & Matrix'), 'href' => route('admin.attendance.reports'), 'active' => request()->routeIs('admin.attendance.reports*'), 'permission' => 'attendance.export'],
                ['icon' => 'cpu', 'label' => __('Biometric Devices'), 'href' => route('admin.attendance.biometric'), 'active' => request()->routeIs('admin.attendance.biometric*'), 'permission' => 'attendance.biometric'],
                ['icon' => 'calendar', 'label' => __('Calendars & Holidays'), 'href' => route('admin.attendance.calendar'), 'active' => request()->routeIs('admin.attendance.calendar*'), 'permission' => 'attendance.settings'],
                ['icon' => 'settings', 'label' => __('Attendance Settings'), 'href' => route('admin.settings.attendance'), 'active' => request()->routeIs('admin.settings.attendance*'), 'permission' => 'attendance.settings'],
            ],
        ],
        [
            'title' => __('LEAVE MANAGEMENT'),
            'items' => [
                ['icon' => 'inbox', 'label' => __('Leave Applications'), 'href' => route('admin.leaves.index'), 'active' => request()->routeIs('admin.leaves.index*'), 'permission' => 'leaves.manage', 'badge' => $pendingLeaveApplicationsCount > 0 ? (string) $pendingLeaveApplicationsCount : null],
                ['icon' => 'folder-tree', 'label' => __('Leave Categories'), 'href' => route('admin.leaves.categories'), 'active' => request()->routeIs('admin.leaves.categories*'), 'permission' => 'leaves.categories.manage'],
            ],
        ],
        [
            'title' => __('FEES & PAYMENTS'),
            'items' => [
                ['icon' => 'receipt', 'label' => __('Fee Invoices & Dues'), 'href' => route('admin.payments.fees'), 'active' => request()->routeIs('admin.payments.fees*'), 'permission' => 'payments.view'],
                ['icon' => 'calendar-days', 'label' => __('Billing Schedules'), 'href' => route('admin.payments.schedules'), 'active' => request()->routeIs('admin.payments.schedules*'), 'permission' => 'payments.create'],
                ['icon' => 'check-check', 'label' => __('Offline Verifications'), 'href' => route('admin.payments.verifications'), 'active' => request()->routeIs('admin.payments.verifications*'), 'permission' => 'payments.verify', 'badge' => $pendingOfflinePaymentsCount > 0 ? (string) $pendingOfflinePaymentsCount : null],
                ['icon' => 'book', 'label' => __('Library Security'), 'href' => route('admin.payments.library'), 'active' => request()->routeIs('admin.payments.library*'), 'permission' => 'library_deposit.manage', 'badge' => $pendingLibraryRefundsCount > 0 ? (string) $pendingLibraryRefundsCount : null],
                ['icon' => 'banknote', 'label' => __('Ledger & Transactions'), 'href' => route('admin.payments.transactions'), 'active' => request()->routeIs('admin.payments.transactions*'), 'permission' => 'payments.view'],
                ['icon' => 'book-marked', 'label' => __('Student Financial Ledgers'), 'href' => route('admin.payments.ledger'), 'active' => request()->routeIs('admin.payments.ledger*'), 'permission' => 'payments.view'],
                ['icon' => 'pie-chart', 'label' => __('Collection Reports'), 'href' => route('admin.payments.reports'), 'active' => request()->routeIs('admin.payments.reports*'), 'permission' => 'payments.export'],
            ],
        ],
        [
            'title' => __('LIBRARY MANAGEMENT'),
            'items' => [
                ['icon' => 'book', 'label' => __('Books Catalog'), 'href' => route('admin.library.books'), 'active' => request()->routeIs('admin.library.books*'), 'permission' => 'library.books.manage'],
                ['icon' => 'barcode', 'label' => __('Accession Register'), 'href' => route('admin.library.accession'), 'active' => request()->routeIs('admin.library.accession*'), 'permission' => 'library.accession.manage'],
                ['icon' => 'repeat', 'label' => __('Circulation Desk'), 'href' => route('admin.library.circulation'), 'active' => request()->routeIs('admin.library.circulation*'), 'permission' => 'library.issue.manage'],
                ['icon' => 'bookmark-check', 'label' => __('Holds & Reservations'), 'href' => route('admin.library.reservations'), 'active' => request()->routeIs('admin.library.reservations*'), 'permission' => 'library.reservations.manage'],
                ['icon' => 'id-card', 'label' => __('Borrower Members'), 'href' => route('admin.library.members'), 'active' => request()->routeIs('admin.library.members*'), 'permission' => 'library.members.manage'],
                ['icon' => 'coins', 'label' => __('Overdue & Fines'), 'href' => route('admin.library.fines'), 'active' => request()->routeIs('admin.library.fines*'), 'permission' => 'library.fines.manage'],
                ['icon' => 'bar-chart-3', 'label' => __('Circulation Reports'), 'href' => route('admin.library.reports'), 'active' => request()->routeIs('admin.library.reports*'), 'permission' => 'library.reports.view'],
                ['icon' => 'folder-tree', 'label' => __('Catalog Taxonomy'), 'href' => route('admin.library.masters'), 'active' => request()->routeIs('admin.library.masters*'), 'permission' => 'library.categories.manage'],
            ],
        ],
        [
            'title' => __('HOSTEL MANAGEMENT'),
            'items' => [
                ['icon' => 'gauge', 'label' => __('Hostel Dashboard'), 'href' => route('admin.hostel.dashboard'), 'active' => request()->routeIs('admin.hostel.dashboard*'), 'permission' => 'hostel.view', 'admin_only' => true],
                ['icon' => 'layout-grid', 'label' => __('Visual Room Board'), 'href' => route('admin.hostel.rooms.board'), 'active' => request()->routeIs('admin.hostel.rooms.board*'), 'permission' => 'hostel.view', 'admin_only' => true],
                ['icon' => 'building-2', 'label' => __('Buildings & Floors'), 'href' => route('admin.hostel.buildings'), 'active' => request()->routeIs('admin.hostel.buildings*'), 'permission' => 'hostel.buildings.manage', 'admin_only' => true],
                ['icon' => 'door-closed', 'label' => __('Rooms & Beds'), 'href' => route('admin.hostel.rooms.index'), 'active' => request()->routeIs('admin.hostel.rooms.index*'), 'permission' => 'hostel.rooms.manage', 'admin_only' => true],
                ['icon' => 'users', 'label' => __('Room Allocations'), 'href' => route('admin.hostel.allocations'), 'active' => request()->routeIs('admin.hostel.allocations*'), 'permission' => 'hostel.allocations.view', 'admin_only' => true],
                ['icon' => 'calendar-plus', 'label' => __('Extension Requests'), 'href' => route('admin.hostel.requests'), 'active' => request()->routeIs('admin.hostel.requests*'), 'permission' => 'hostel.requests.manage', 'badge' => $pendingHostelRequestsCount > 0 ? (string) $pendingHostelRequestsCount : null, 'admin_only' => true],
                ['icon' => 'bar-chart-2', 'label' => __('Hostel Reports'), 'href' => route('admin.hostel.reports'), 'active' => request()->routeIs('admin.hostel.reports*'), 'permission' => 'hostel.reports.view', 'admin_only' => true],
                ['icon' => 'settings', 'label' => __('Hostel Settings'), 'href' => route('admin.settings.hostel'), 'active' => request()->routeIs('admin.settings.hostel*'), 'permission' => 'hostel.settings.manage', 'admin_only' => true],
            ],
        ],
        [
            'title' => __('USER & ACCESS MANAGEMENT'),
            'items' => [
                ['icon' => 'user-cog', 'label' => __('Users'), 'href' => route('admin.users.index'), 'active' => request()->routeIs('admin.users.*'), 'permission' => 'users.view'],
                ['icon' => 'shield', 'label' => __('Roles'), 'href' => route('admin.roles.index'), 'active' => request()->routeIs('admin.roles.*'), 'permission' => 'roles.view'],
                ['icon' => 'key', 'label' => __('Permissions'), 'href' => route('admin.permissions.index'), 'active' => request()->routeIs('admin.permissions.*'), 'permission' => 'permissions.manage'],
                ['icon' => 'activity', 'label' => __('User Activity Logs'), 'href' => route('admin.user-activity.index'), 'active' => request()->routeIs('admin.user-activity.*'), 'permission' => 'activity.view', 'admin_only' => true],
            ],
        ],
        [
            'title' => __('COMMUNICATIONS'),
            'items' => [
                ['icon' => 'user-plus', 'label' => __('Subscribers & Leads'), 'href' => route('admin.subscribers.index'), 'active' => request()->routeIs('admin.subscribers.*'), 'permission' => 'subscribers.view'],
                ['icon' => 'send', 'label' => __('Chat Broadcast Hub'), 'href' => route('admin.chat.broadcast'), 'active' => request()->routeIs('admin.chat.broadcast*'), 'permission' => 'chat.broadcast'],
                ['icon' => 'activity', 'label' => __('Delivery Logs'), 'href' => route('admin.communications.logs'), 'active' => request()->routeIs('admin.communications.logs*'), 'permission' => 'communications.view'],
                ['icon' => 'send', 'label' => __('Send & Drafts'), 'href' => route('admin.communications.compose'), 'active' => request()->routeIs('admin.communications.compose*'), 'permission' => 'communications.send'],
                ['icon' => 'smartphone', 'label' => __('SMS Templates'), 'href' => route('admin.sms-templates.index'), 'active' => request()->routeIs('admin.sms-templates.*'), 'permission' => 'templates.manage'],
                ['icon' => 'mail', 'label' => __('Email Templates'), 'href' => route('admin.email-templates.index'), 'active' => request()->routeIs('admin.email-templates.*'), 'permission' => 'templates.manage'],
            ],
        ],
        [
            'title' => __('DOCUMENT MANAGEMENT'),
            'items' => [
                ['icon' => 'folder-archive', 'label' => __('Soft Copies Documents'), 'href' => route('admin.documents.index'), 'active' => request()->routeIs('admin.documents.*'), 'permission' => 'documents.view'],
            ],
        ],
        [
            'title' => __('CONTENT & CMS'),
            'items' => [
                ['icon' => 'file-text', 'label' => __('Pages Management'), 'href' => route('admin.pages.index'), 'active' => request()->routeIs('admin.pages.*'), 'permission' => 'pages.manage'],
            ],
        ],
        [
            'title' => __('SUPPORT & HELPDESK'),
            'items' => [
                ['icon' => 'life-buoy', 'label' => __('Support Tickets'), 'href' => ($user?->can('tickets.view') ? route('admin.tickets.index') : route('tickets.index')), 'active' => request()->routeIs('admin.tickets.*') || request()->routeIs('tickets.*'), 'badge' => $openTicketsCount > 0 ? (string) $openTicketsCount : null],
                ['icon' => 'tag', 'label' => __('Categories & SLAs'), 'href' => route('admin.tickets.categories'), 'active' => request()->routeIs('admin.tickets.categories*'), 'permission' => 'tickets.categories'],
                ['icon' => 'message-square-quote', 'label' => __('Canned Macros'), 'href' => route('admin.tickets.canned-responses'), 'active' => request()->routeIs('admin.tickets.canned-responses*'), 'permission' => 'tickets.canned_responses'],
                ['icon' => 'mail', 'label' => __('Contact Inquiries'), 'href' => route('admin.contacts.index'), 'active' => request()->routeIs('admin.contacts.*'), 'permission' => 'contacts.manage'],
            ],
        ],
        [
            'title' => __('REPORTS'),
            'items' => [
                ['icon' => 'bar-chart-3', 'label' => __('All reports'), 'href' => route('admin.reports.index'), 'active' => request()->routeIs('admin.reports.index'), 'permission' => 'reports.view'],
                ['icon' => 'bookmark', 'label' => __('Saved reports'), 'href' => route('admin.reports.saved'), 'active' => request()->routeIs('admin.reports.saved'), 'permission' => 'reports.view'],
            ],
        ],
        [
            'title' => __('API & INTEGRATIONS'),
            'items' => [
                ['icon' => 'key', 'label' => __('API Keys'), 'href' => route('admin.api-keys.index'), 'active' => request()->routeIs('admin.api-keys.index*'), 'permission' => 'api_keys.view'],
                ['icon' => 'activity', 'label' => __('Request Telemetry'), 'href' => route('admin.api-keys.logs'), 'active' => request()->routeIs('admin.api-keys.logs*'), 'permission' => 'api_keys.logs'],
                ['icon' => 'terminal', 'label' => __('Developer Console & Docs'), 'href' => route('admin.api-keys.docs'), 'active' => request()->routeIs('admin.api-keys.docs*'), 'permission' => 'api_keys.view'],
            ],
        ],
        [
            'title' => __('SYSTEM'),
            'items' => [
                ['icon' => 'database', 'label' => __('Database & Backups'), 'href' => route('admin.backups.index'), 'active' => request()->routeIs('admin.backups.*') || request()->routeIs('admin.settings.backup'), 'permission' => 'settings.backup'],
                ['icon' => 'clock', 'label' => __('Cron Jobs'), 'href' => route('admin.cron-jobs.index'), 'active' => request()->routeIs('admin.cron-jobs.*'), 'permission' => 'settings.cron'],
                ['icon' => 'user-circle', 'label' => __('Profile'), 'href' => route('admin.profile.show'), 'active' => request()->routeIs('admin.profile.*'), 'admin_only' => true],
                ['icon' => 'shield-check', 'label' => __('Audit Logs'), 'href' => route('admin.audit-logs.index'), 'active' => request()->routeIs('admin.audit-logs.*'), 'permission' => 'audit.view'],
                [
                    'icon' => 'settings',
                    'label' => __('Settings'),
                    'admin_only' => true,
                    'children' => [
                        ['icon' => 'user', 'label' => __('Profile settings'), 'href' => route('profile.edit'), 'active' => request()->routeIs('profile.edit')],
                        ['icon' => 'lock', 'label' => __('Security'), 'href' => route('security.edit'), 'active' => request()->routeIs('security.edit')],
                        ['icon' => 'palette', 'label' => __('Appearance'), 'href' => route('appearance.edit'), 'active' => request()->routeIs('appearance.edit'), 'permission' => 'settings.appearance'],
                        ['icon' => 'users', 'label' => __('Teams'), 'href' => route('teams.index'), 'active' => request()->routeIs('teams.*'), 'permission' => 'settings.general', 'admin_only' => true],
                        ['icon' => 'message-square', 'label' => __('Live Chat & Calls'), 'href' => route('admin.settings.chat'), 'active' => request()->routeIs('admin.settings.chat'), 'permission' => 'settings.general'],
                        ['icon' => 'video', 'label' => __('Online Meetings'), 'href' => route('admin.settings.meetings'), 'active' => request()->routeIs('admin.settings.meetings'), 'permission' => 'settings.general'],
                        ['icon' => 'book', 'label' => __('Library Policy'), 'href' => route('admin.settings.library'), 'active' => request()->routeIs('admin.settings.library'), 'permission' => 'library.settings.manage'],
                        ['icon' => 'bed', 'label' => __('Hostel Policies'), 'href' => route('admin.settings.hostel'), 'active' => request()->routeIs('admin.settings.hostel*'), 'permission' => 'hostel.settings.manage'],
                        ['icon' => 'calendar-check', 'label' => __('Attendance & Shifts'), 'href' => route('admin.settings.attendance'), 'active' => request()->routeIs('admin.settings.attendance*'), 'permission' => 'attendance.settings'],
                        ['icon' => 'id-card', 'label' => __('Student ID Card Settings'), 'href' => route('admin.settings.student-id-card'), 'active' => request()->routeIs('admin.settings.student-id-card*'), 'permission' => 'settings.general'],
                        ['icon' => 'link', 'label' => __('Admission OTR Settings'), 'href' => route('admin.settings.otr'), 'active' => request()->routeIs('admin.settings.otr*'), 'permission' => 'settings.general'],
                        ['icon' => 'cpu', 'label' => __('System settings'), 'href' => route('admin.settings.index'), 'active' => request()->routeIs('admin.settings.index', 'admin.settings.general', 'admin.settings.seo', 'admin.settings.appearance', 'admin.settings.email', 'admin.settings.localization', 'admin.settings.payment', 'admin.settings.sms', 'admin.settings.notification', 'admin.settings.system', 'admin.settings.backup', 'admin.backups.index', 'admin.settings.library', 'admin.settings.hostel*', 'admin.settings.attendance*', 'admin.settings.student-id-card*', 'admin.settings.otr*'), 'permission' => 'settings.general'],
                    ],
                ],
            ],
        ],
    ];

    $isStudentUser = $user && $user->isStudent();

    $navSections = collect($rawNavSections)->map(function ($section) use ($user, $isStudentUser, $chatEnabled) {
        $filteredItems = collect($section['items'])->map(function ($item) use ($user, $isStudentUser, $chatEnabled) {
            // Hide administrative items from pure student portal accounts
            if ($isStudentUser && ! empty($item['admin_only'])) {
                return null;
            }

            if (! $chatEnabled && isset($item['href']) && $item['href'] === route('admin.chat.broadcast')) {
                return null;
            }

            if ($isStudentUser && isset($item['href']) && (
                $item['href'] === route('admin.study-materials.index') ||
                $item['href'] === route('admin.study-materials.statistics') ||
                $item['href'] === route('admin.attendance.index') ||
                $item['href'] === route('admin.academic-calendar.index') ||
                $item['href'] === route('admin.hostel.dashboard') ||
                $item['href'] === route('admin.hostel.rooms.board') ||
                $item['href'] === route('admin.profile.show')
            )) {
                return null;
            }

            if (isset($item['permission'])) {
                $hasPerm = false;
                if ($user) {
                    if ($user->hasRole('Super Administrator')) {
                        $hasPerm = true;
                    } elseif (is_array($item['permission'])) {
                        $hasPerm = $user->canAny($item['permission']);
                    } elseif ($item['permission'] === 'academic_calendar.view') {
                        $hasPerm = $user->can('academic_calendar.view') || $user->canAny([
                            'academic_calendar.create',
                            'academic_calendar.edit',
                            'academic_calendar.revise',
                            'academic_calendar.delete',
                            'academic_calendar.export',
                            'academic_calendar.import',
                            'academic_calendar.download',
                        ]);
                    } else {
                        $hasPerm = $user->can($item['permission']);
                    }
                }

                if (! $hasPerm) {
                    return null;
                }
            }

            if (isset($item['children'])) {
                $filteredChildren = collect($item['children'])->filter(function ($child) use ($user, $isStudentUser) {
                    if ($isStudentUser && ! empty($child['admin_only'])) {
                        return false;
                    }
                    if (! empty($child['admin_only']) && ! $user?->isAdmin()) {
                        return false;
                    }
                    return ! isset($child['permission']) || $user?->can($child['permission']) || $user?->hasRole('Super Administrator');
                })->values()->all();

                if (empty($filteredChildren)) {
                    return null;
                }

                $item['children'] = $filteredChildren;
            }

            return $item;
        })->filter()->values()->all();

        $section['items'] = $filteredItems;

        return $section;
    })->filter(fn ($section) => count($section['items']) > 0)->values()->all();

    $appName = \App\Models\Setting::appName();
@endphp

{{-- Desktop sidebar: fixed, collapsible --}}
<aside
    class="hidden lg:flex fixed left-0 top-0 z-40 h-screen flex-col border-r border-sidebar-border bg-sidebar transition-all duration-300"
    :class="collapsed ? 'w-16' : 'w-60'"
>
    {{-- Expanded mode --}}
    <div class="flex flex-col h-full min-h-0" x-show="! collapsed">
        <div class="flex h-14 items-center justify-between px-4 border-b border-sidebar-border shrink-0">
            <x-app-logo :href="route('dashboard')"/>
            <button
                type="button"
                x-on:click="toggleSidebar()"
                class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-sidebar-accent transition-colors cursor-pointer"
                aria-label="{{ __('Toggle sidebar') }}"
            >
                <x-icon name="panel-left-close" class="h-4 w-4 text-muted-foreground"/>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto py-3 px-2">
            @foreach ($navSections as $section)
                <div class="mb-2">
                    <h3 class="px-2 mb-1 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/60">
                        {{ $section['title'] }}
                    </h3>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            @if (isset($item['children']))
                                <li>
                                    <button
                                        type="button"
                                        x-on:click="openSubmenu = openSubmenu === '{{ $item['label'] }}' ? null : '{{ $item['label'] }}'"
                                        class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground hover:bg-sidebar-accent transition-colors cursor-pointer"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0 text-muted-foreground"/>
                                        <span class="flex-1 text-left truncate">{{ $item['label'] }}</span>
                                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-muted-foreground transition-transform" x-bind:class="openSubmenu === '{{ $item['label'] }}' ? '' : '-rotate-90'"/>
                                    </button>
                                    <div x-show="openSubmenu === '{{ $item['label'] }}'" x-collapse x-cloak>
                                        <ul class="ml-5 mt-0.5 space-y-0.5 border-l border-sidebar-border pl-2">
                                            @foreach ($item['children'] as $child)
                                                <li>
                                                    <a
                                                        href="{{ $child['href'] }}"
                                                        wire:navigate
                                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($child['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                                    >
                                                        <x-icon :name="$child['icon']" class="h-3.5 w-3.5 shrink-0"/>
                                                        <span class="truncate">{{ $child['label'] }}</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </li>
                            @else
                                <li>
                                    <a
                                        href="{{ $item['href'] }}"
                                        wire:navigate
                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($item['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0"/>
                                        <span class="truncate flex-1">{{ $item['label'] }}</span>
                                        @if (!empty($item['badge']))
                                            <span class="inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-bold text-primary-foreground shadow-xs animate-pulse">
                                                {{ $item['badge'] }}
                                            </span>
                                        @endif
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-sidebar-border px-3 py-3 shrink-0">
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                <span>{{ __('All systems online') }}</span>
            </div>
            <p class="mt-1 text-[10px] text-muted-foreground/60">v1.0.0</p>
        </div>
    </div>

    {{-- Collapsed mode: icons only with indicator dots --}}
    <div class="flex flex-col h-full min-h-0" x-show="collapsed" x-cloak>
        <div class="flex h-14 items-center justify-center border-b border-sidebar-border shrink-0">
            <button
                type="button"
                x-on:click="toggleSidebar()"
                class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-sidebar-accent transition-colors cursor-pointer"
                aria-label="{{ __('Expand sidebar') }}"
            >
                <x-icon name="panel-left" class="h-4 w-4 text-muted-foreground"/>
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto py-3 px-2">
            @foreach ($navSections as $section)
                <div class="mb-2">
                    <div class="my-2 border-t border-sidebar-border"></div>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            <li>
                                <a
                                    href="{{ $item['href'] ?? ($item['children'][0]['href'] ?? route('dashboard')) }}"
                                    wire:navigate
                                    title="{{ $item['label'] }}"
                                    class="relative flex items-center justify-center rounded-md px-2 py-1.5 text-sm transition-colors {{ ($item['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                >
                                    <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0"/>
                                    @if (!empty($item['badge']))
                                        <span class="absolute top-1 right-1 flex h-2.5 w-2.5 rounded-full bg-primary ring-2 ring-sidebar"></span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
        <div class="border-t border-sidebar-border px-3 py-3 flex justify-center shrink-0">
            <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
        </div>
    </div>
</aside>

{{-- Mobile sidebar: drawer overlay --}}
<div
    class="lg:hidden fixed inset-0 z-40 bg-black/50 backdrop-blur-sm transition-opacity"
    x-show="mobileOpen"
    x-transition.opacity
    x-cloak
    x-on:click="mobileOpen = false"
    aria-hidden="true"
></div>

<aside
    class="lg:hidden fixed inset-y-0 left-0 z-50 w-72 bg-sidebar border-r border-sidebar-border transition-transform duration-300 ease-in-out"
    :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
    aria-label="{{ __('Main navigation') }}"
>
    <div class="flex flex-col h-full">
        <div class="flex h-14 items-center justify-between px-4 border-b border-sidebar-border shrink-0">
            <x-app-logo :href="route('dashboard')"/>
            <button
                type="button"
                x-on:click="mobileOpen = false"
                class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-sidebar-accent transition-colors cursor-pointer"
                aria-label="{{ __('Close menu') }}"
            >
                <x-icon name="x" class="h-4 w-4 text-muted-foreground"/>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto py-3 px-2">
            @foreach ($navSections as $section)
                <div class="mb-2">
                    <h3 class="px-2 mb-1 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/60">
                        {{ $section['title'] }}
                    </h3>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            @if (isset($item['children']))
                                <li>
                                    <button
                                        type="button"
                                        x-on:click="openSubmenu = openSubmenu === '{{ $item['label'] }}' ? null : '{{ $item['label'] }}'"
                                        class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground hover:bg-sidebar-accent transition-colors cursor-pointer"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0 text-muted-foreground"/>
                                        <span class="flex-1 text-left truncate">{{ $item['label'] }}</span>
                                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-muted-foreground transition-transform" x-bind:class="openSubmenu === '{{ $item['label'] }}' ? '' : '-rotate-90'"/>
                                    </button>
                                    <div x-show="openSubmenu === '{{ $item['label'] }}'" x-collapse x-cloak>
                                        <ul class="ml-5 mt-0.5 space-y-0.5 border-l border-sidebar-border pl-2">
                                            @foreach ($item['children'] as $child)
                                                <li>
                                                    <a
                                                        href="{{ $child['href'] }}"
                                                        wire:navigate
                                                        x-on:click="mobileOpen = false"
                                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($child['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                                    >
                                                        <x-icon :name="$child['icon']" class="h-3.5 w-3.5 shrink-0"/>
                                                        <span class="truncate">{{ $child['label'] }}</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </li>
                            @else
                                <li>
                                    <a
                                        href="{{ $item['href'] }}"
                                        wire:navigate
                                        x-on:click="mobileOpen = false"
                                        class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors {{ ($item['active'] ?? false) ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent' }}"
                                    >
                                        <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0"/>
                                        <span class="truncate flex-1">{{ $item['label'] }}</span>
                                        @if (!empty($item['badge']))
                                            <span class="inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-bold text-primary-foreground shadow-xs animate-pulse">
                                                {{ $item['badge'] }}
                                            </span>
                                        @endif
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-sidebar-border px-3 py-3 shrink-0">
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                <span>{{ __('All systems online') }}</span>
            </div>
            <p class="mt-1 text-[10px] text-muted-foreground/60">v1.0.0</p>
        </div>
    </div>
</aside>
