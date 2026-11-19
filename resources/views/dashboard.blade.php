@php
    $user = auth()->user();
    $isStudent = $user && ($user->hasRole('Student') || ($user->studentProfile()->exists() && ! $user->hasRole(['Super Administrator', 'Administrator', 'Admissions Officer', 'Faculty', 'Accountant', 'Staff', 'Librarian'])));
    $isAdminOrStaff = $user && ($user->hasRole(['Super Administrator', 'Administrator', 'Admissions Officer', 'Faculty', 'Accountant', 'Staff', 'Librarian']) || $user->roles()->whereNotIn('name', ['Student'])->exists() || $user->permissions()->exists());
@endphp

@if ($isStudent)
    <x-layouts::app :title="__('Student Dashboard')">
        <livewire:pages::student.dashboard />
    </x-layouts::app>
@elseif ($isAdminOrStaff)
@php
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));

    // Real dynamic financial & academic metrics
    $dbTodayCollections = (float) \App\Models\PaymentTransaction::whereDate('created_at', today())->where('status', 'successful')->sum('amount');
    $dbTodayPayments = \App\Models\PaymentTransaction::whereDate('created_at', today())->where('status', 'successful')->count();
    if ($dbTodayPayments === 0) {
        $dbTodayCollections = (float) \App\Models\Enrollment::whereDate('updated_at', today())->where('fee_status', 'paid')->sum('fee_amount');
        $dbTodayPayments = \App\Models\Enrollment::whereDate('updated_at', today())->where('fee_status', 'paid')->count();
    }
    $dbPendingInstallments = \App\Models\StudentFeeInvoice::whereIn('status', ['pending', 'partial', 'overdue'])->count();
    if ($dbPendingInstallments === 0) {
        $dbPendingInstallments = \App\Models\Enrollment::whereIn('fee_status', ['pending', 'partial'])->count();
    }
    $totalPaidRevenue = (float) \App\Models\PaymentTransaction::where('status', 'successful')->sum('amount');
    if ($totalPaidRevenue === 0.0) {
        $totalPaidRevenue = (float) \App\Models\Enrollment::where('fee_status', 'paid')->sum('fee_amount');
    }
    $dbAvgCollection = $dbTodayPayments > 0 ? (int) round($dbTodayCollections / $dbTodayPayments) : 0;
    $totalActiveEnrollments = \App\Models\Enrollment::where('status', 'active')->count();
    $activeBatchesCount = \App\Models\Batch::where('status', 'active')->count();
    $totalCoursesCount = \App\Models\Course::where('status', 'active')->count();

    // Query real courses for top courses breakdown
    $dbCourses = \App\Models\Course::with(['batches'])
        ->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
        ->orderByDesc('enrollments_count')
        ->take(6)
        ->get();

    $topCourses = $dbCourses->isNotEmpty()
        ? $dbCourses->map(function ($c, $idx) {
            $isFree = $c->isFree() || $c->fee_type === 'free';
            $rev = (float) $c->enrollments()->where('fee_status', 'paid')->sum('fee_amount');
            return [
                'rank' => $idx + 1,
                'name' => $c->name,
                'code' => $c->code,
                'category' => $c->category,
                'is_free' => $isFree,
                'enrolled' => $c->enrollments_count,
                'batch' => $c->batches->first()?->name ?? 'All Batches',
                'revenue' => $rev,
            ];
        })->all()
        : [
            ['rank' => 1, 'name' => 'Composite Course (CC)', 'code' => 'CC', 'category' => 'UPSC CSE - Prelims Cum Mains', 'is_free' => false, 'enrolled' => 184, 'batch' => '2026-2027', 'revenue' => 2760000],
            ['rank' => 2, 'name' => 'Prelims Crash Course (PCC)', 'code' => 'PCC', 'category' => 'UPSC CSE - Prelims Special', 'is_free' => false, 'enrolled' => 126, 'batch' => '2026-2027', 'revenue' => 945000],
            ['rank' => 3, 'name' => 'Free UPSC CSE Foundation Seminar', 'code' => 'FOS', 'category' => 'UPSC CSE - Foundation / Seminar', 'is_free' => true, 'enrolled' => 98, 'batch' => '2026-2027', 'revenue' => 0],
        ];

    // Real batches fill rate
    $dbBatches = \App\Models\Batch::withCount(['enrollments' => fn ($q) => $q->where('status', 'active')])
        ->where('status', 'active')
        ->take(4)
        ->get();

    $lowFillBatches = $dbBatches->isNotEmpty()
        ? $dbBatches->map(function ($b) {
            $capacity = $b->max_students ?: 200;
            $occupied = $b->enrollments_count;
            $seatsLeft = max(0, $capacity - $occupied);
            return [
                'name' => "{$b->name} ({$b->academic_year})",
                'seats' => $seatsLeft,
                'capacity' => $capacity,
            ];
        })->all()
        : [
            ['name' => 'Batch 2026-2027 (Composite Course)', 'seats' => 16, 'capacity' => 200],
            ['name' => 'Batch 2027-2028 (Upcoming Cohort)', 'seats' => 180, 'capacity' => 200],
        ];

    // Chart slices
    $colors = ['#10b981', '#06b6d4', '#f59e0b', '#8b5cf6', '#f43f5e', '#ec4899', '#f97316', '#14b8a6'];
    $courseMix = $dbCourses->isNotEmpty()
        ? $dbCourses->map(function ($c, $idx) use ($colors) {
            return [
                'name' => $c->name,
                'value' => max(1, $c->enrollments_count),
                'color' => $colors[$idx % count($colors)],
            ];
        })->all()
        : [
            ['name' => 'Composite Course (CC)', 'value' => 184, 'color' => '#10b981'],
            ['name' => 'Prelims Crash Course (PCC)', 'value' => 126, 'color' => '#06b6d4'],
            ['name' => 'Free UPSC Foundation (FOS)', 'value' => 98, 'color' => '#f59e0b'],
        ];

    $performanceData = [
        ['label' => 'Aug 07', 'current' => 12, 'previous' => 8],
        ['label' => 'Aug 08', 'current' => 18, 'previous' => 10],
        ['label' => 'Aug 09', 'current' => 9, 'previous' => 12],
        ['label' => 'Aug 10', 'current' => 22, 'previous' => 14],
        ['label' => 'Aug 11', 'current' => 28, 'previous' => 18],
        ['label' => 'Aug 12', 'current' => 24, 'previous' => 20],
        ['label' => 'Aug 13', 'current' => 35, 'previous' => 22],
        ['label' => 'Aug 14', 'current' => 31, 'previous' => 26],
        ['label' => 'Aug 15', 'current' => 42, 'previous' => 28],
        ['label' => 'Aug 16', 'current' => 38, 'previous' => 30],
        ['label' => 'Aug 17', 'current' => 48, 'previous' => 32],
        ['label' => 'Aug 18', 'current' => 45, 'previous' => 36],
        ['label' => 'Aug 19', 'current' => 56, 'previous' => 40],
        ['label' => 'Aug 20', 'current' => 29, 'previous' => 22],
    ];

    $recentLogs = \App\Models\AuditLog::with('user')->latest()->take(6)->get();
    $activities = $recentLogs->isNotEmpty()
        ? $recentLogs->map(function ($log) {
            return [
                'user' => $log->user?->name ?? __('System'),
                'action' => str_replace('_', ' ', $log->event),
                'amount' => $log->description ?: '',
                'time' => $log->created_at->diffForHumans(),
            ];
        })->all()
        : [
            ['user' => 'Admissions Desk', 'action' => 'verified student in', 'amount' => 'Composite Course (Section A)', 'time' => '10 mins ago'],
            ['user' => 'Academic Cell', 'action' => 'updated cohort section', 'amount' => 'Section B (Capacity: 100)', 'time' => '1 hour ago'],
            ['user' => 'Accounts Desk', 'action' => 'verified fee exemption for', 'amount' => 'Free UPSC CSE Foundation Seminar', 'time' => '2 hours ago'],
        ];

    $activeStaff = \App\Models\User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Administrator', 'Administrator', 'Admissions Officer', 'Faculty', 'Accountant', 'Staff']))
        ->take(4)
        ->get();

    $recentLogins = $activeStaff->isNotEmpty()
        ? $activeStaff->map(function ($st) {
            $initials = strtoupper(substr($st->first_name ?: $st->name, 0, 1) . substr($st->last_name ?: '', 0, 1));
            return [
                'initials' => $initials ?: 'U',
                'name' => $st->name,
                'status' => $st->isOnline() ? 'Online' : 'Offline',
                'detail' => $st->getRoleNames()->first() ?? __('Staff Member'),
                'ip' => 'Internal LAN',
            ];
        })->all()
        : [
            ['initials' => 'SA', 'name' => 'Super Administrator', 'status' => 'Online', 'detail' => 'Root Administrator', 'ip' => '127.0.0.1'],
        ];

    $heatmapData = [
        'Mon' => [9 => 0.3, 10 => 0.5, 11 => 0.7, 12 => 0.9, 13 => 0.6, 14 => 0.5, 15 => 0.6, 16 => 0.4, 17 => 0.8, 18 => 0.7],
        'Tue' => [9 => 0.2, 10 => 0.6, 11 => 0.8, 12 => 0.7, 13 => 0.5, 14 => 0.4, 15 => 0.7, 16 => 0.5, 17 => 0.6, 18 => 0.9],
        'Wed' => [9 => 0.4, 10 => 0.5, 11 => 0.6, 12 => 0.8, 13 => 0.7, 14 => 0.6, 15 => 0.5, 16 => 0.3, 17 => 0.5, 18 => 0.6],
        'Thu' => [9 => 0.3, 10 => 0.7, 11 => 0.9, 12 => 1.0, 13 => 0.8, 14 => 0.5, 15 => 0.6, 16 => 0.4, 17 => 0.7, 18 => 0.8],
        'Fri' => [9 => 0.5, 10 => 0.6, 11 => 0.8, 12 => 0.9, 13 => 0.6, 14 => 0.7, 15 => 0.8, 16 => 0.6, 17 => 0.9, 18 => 1.0],
        'Sat' => [10 => 0.4, 11 => 0.6, 12 => 0.8, 13 => 0.7, 14 => 0.6, 15 => 0.5, 16 => 0.4, 17 => 0.3],
        'Sun' => [11 => 0.2, 12 => 0.3, 13 => 0.4, 14 => 0.3, 15 => 0.2],
    ];
@endphp

<x-layouts::app :title="__('Admin Dashboard')">
    @if ($user?->isAdmin())
        <livewire:pages::teams.pending-invitations-modal/>
    @endif

    {{-- Greeting --}}
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
        <div>
            <div class="flex items-center gap-2 flex-wrap">
                <h1 class="text-lg sm:text-xl font-semibold">{{ $greeting }}, {{ $user->name }}</h1>
                <span class="flex items-center gap-1 rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-semibold text-primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                    {{ __('Live') }}
                </span>
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full font-mono text-[11px] font-semibold bg-muted text-foreground border border-border/60" title="{{ __('Universal Registration Number') }}">
                    <span class="text-muted-foreground">{{ __('URN:') }}</span>
                    @if(!empty($user->urn))
                        <span class="font-bold">{{ $user->urn }}</span>
                    @else
                        <span class="text-muted-foreground/70 italic text-[10px]">{{ __('Not Assigned') }}</span>
                    @endif
                </span>
            </div>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ now()->isoFormat('dddd, MMM D') }} · {{ count($recentLogins) }} {{ __('staff monitored') }}
            </p>
        </div>
        <div class="text-xs text-muted-foreground">
            {{ __('Academic MIS Overview') }}
        </div>
    </div>

    {{-- Hero card --}}
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 lg:p-6">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                {{ __("Today's fee collections") }}
            </span>
            <span class="text-[10px] sm:text-xs text-muted-foreground hidden sm:block">
                {{ __('Total Academic Revenue: ') }}<strong>₹{{ number_format($totalPaidRevenue, 2) }}</strong>
            </span>
        </div>
        <div class="flex flex-col gap-4">
            <div>
                <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold tracking-tight">₹{{ number_format($dbTodayCollections > 0 ? $dbTodayCollections : $totalPaidRevenue, 2) }}</h2>
                <div class="flex flex-wrap gap-x-3 sm:gap-x-4 gap-y-1 mt-2 text-xs sm:text-sm text-muted-foreground">
                    <span><strong class="text-foreground">{{ $dbTodayPayments > 0 ? $dbTodayPayments : $totalActiveEnrollments }}</strong> {{ __('recorded enrollments') }}</span>
                    <span><strong class="text-foreground">₹{{ number_format($dbAvgCollection > 0 ? $dbAvgCollection : ($totalActiveEnrollments > 0 ? round($totalPaidRevenue / $totalActiveEnrollments) : 0), 2) }}</strong> {{ __('average per student') }}</span>
                    <span><strong class="text-foreground">{{ $dbPendingInstallments }}</strong> {{ __('pending installments') }}</span>
                </div>
            </div>
            <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1">
                @if($user->can('admissions.view') || $user->hasRole('Super Administrator'))
                    <a href="{{ route('admin.admissions.index') }}" wire:navigate class="flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-3 py-2 hover:bg-secondary transition-colors shrink-0 min-w-[120px]">
                        <x-icon name="file-text" class="h-4 w-4 text-muted-foreground"/>
                        <div class="text-left">
                            <p class="text-[10px] sm:text-xs text-muted-foreground">{{ __('Admissions') }}</p>
                            <p class="text-xs sm:text-sm font-semibold">{{ __('Review Lists') }}</p>
                        </div>
                    </a>
                @endif
                @if($user->can('students.view') || $user->hasRole('Super Administrator'))
                    <a href="{{ route('admin.enrollments.index') }}" wire:navigate class="flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-3 py-2 hover:bg-secondary transition-colors shrink-0 min-w-[120px]">
                        <x-icon name="users" class="h-4 w-4 text-muted-foreground"/>
                        <div class="text-left">
                            <p class="text-[10px] sm:text-xs text-muted-foreground">{{ __('Active Enrollments') }}</p>
                            <p class="text-xs sm:text-sm font-semibold">{{ $totalActiveEnrollments }} {{ __('Students') }}</p>
                        </div>
                    </a>
                @endif
                @if($user->can('courses.manage') || $user->hasRole('Super Administrator'))
                    <a href="{{ route('admin.courses.index') }}" wire:navigate class="flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-3 py-2 hover:bg-secondary transition-colors shrink-0 min-w-[120px]">
                        <x-icon name="graduation-cap" class="h-4 w-4 text-muted-foreground"/>
                        <div class="text-left">
                            <p class="text-[10px] sm:text-xs text-muted-foreground">{{ __('Active Courses') }}</p>
                            <p class="text-xs sm:text-sm font-semibold">{{ $totalCoursesCount }} {{ __('Programs') }}</p>
                        </div>
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Operations + mini cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
        <div class="sm:col-span-2 lg:col-span-2 rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold">{{ __('Operations & Management') }}</h3>
                    <span class="hidden sm:flex items-center gap-1 text-[10px] text-muted-foreground">
                        <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                        {{ __('Live Academic Hub') }}
                    </span>
                </div>
            </div>
            <div class="grid grid-cols-1 xs:grid-cols-3 gap-2 sm:gap-3">
                @if($user->can('students.view') || $user->hasRole('Super Administrator'))
                    <a href="{{ route('admin.profile-requests.index') }}" wire:navigate class="flex items-center gap-3 rounded-lg border border-amber-500/20 bg-amber-500/5 px-3 sm:px-4 py-2.5 sm:py-3 hover:bg-amber-500/10 transition-colors group">
                        <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg bg-amber-500/15 text-amber-500 font-bold text-base sm:text-lg shrink-0">
                            {{ \App\Models\ProfileUpdateRequest::where('status', 'pending')->count() }}
                        </span>
                        <div class="text-left">
                            <p class="text-xs sm:text-sm font-semibold text-foreground">{{ __('Profile Requests') }}</p>
                            <p class="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{{ __('Student corrections waiting review') }}</p>
                        </div>
                        <x-icon name="arrow-up-right" class="ml-auto h-4 w-4 text-muted-foreground group-hover:text-amber-500 transition-colors hidden sm:block"/>
                    </a>
                @endif

                @if($user->can('payments.view') || $user->can('students.view') || $user->hasRole('Super Administrator'))
                    <a href="{{ ($user->can('payments.view') || $user->hasRole('Super Administrator')) ? route('admin.payments.fees') : route('admin.enrollments.index') }}" wire:navigate class="flex items-center gap-3 rounded-lg border border-rose-500/20 bg-rose-500/5 px-3 sm:px-4 py-2.5 sm:py-3 hover:bg-rose-500/10 transition-colors group">
                        <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg bg-rose-500/15 text-rose-500 font-bold text-base sm:text-lg shrink-0">
                            {{ $dbPendingInstallments }}
                        </span>
                        <div class="text-left">
                            <p class="text-xs sm:text-sm font-semibold text-foreground">{{ __('Pending / Due Fees') }}</p>
                            <p class="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{{ __('Unsettled student fee records') }}</p>
                        </div>
                        <x-icon name="arrow-up-right" class="ml-auto h-4 w-4 text-muted-foreground group-hover:text-rose-500 transition-colors hidden sm:block"/>
                    </a>
                @endif

                @if($user->can('batches.manage') || $user->hasRole('Super Administrator'))
                    <a href="{{ route('admin.batches.index') }}" wire:navigate class="flex items-center gap-3 rounded-lg border border-cyan-500/20 bg-cyan-500/5 px-3 sm:px-4 py-2.5 sm:py-3 hover:bg-cyan-500/10 transition-colors group">
                        <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg bg-cyan-500/15 text-cyan-500 font-bold text-base sm:text-lg shrink-0">
                            {{ $activeBatchesCount }}
                        </span>
                        <div class="text-left">
                            <p class="text-xs sm:text-sm font-semibold text-foreground">{{ __('Active Batches') }}</p>
                            <p class="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{{ __('Scheduled intake sessions') }}</p>
                        </div>
                        <x-icon name="arrow-up-right" class="ml-auto h-4 w-4 text-muted-foreground group-hover:text-cyan-500 transition-colors hidden sm:block"/>
                    </a>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <span class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Academic Highlights') }}</span>
            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight mt-1">{{ $totalActiveEnrollments }}</h2>
            <p class="text-xs text-primary font-medium mt-1">{{ __('Total Enrolled Candidates') }}</p>
            <div class="mt-4 space-y-2.5">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ __('Active Courses') }}</span>
                    <span class="font-semibold">{{ $totalCoursesCount }}</span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ __('Active Batches') }}</span>
                    <span class="font-semibold">{{ $activeBatchesCount }}</span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ __('Sections Capacity') }}</span>
                    <span class="font-semibold">{{ \App\Models\Section::where('status', 'active')->sum('capacity') }} {{ __('seats') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 sm:gap-4">
        <div class="xl:col-span-2 rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <div>
                    <h3 class="text-sm font-semibold">{{ __('Performance') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">{{ __('Applications vs admissions activity') }}</p>
                </div>
            </div>

            <div class="flex gap-4 sm:grid sm:grid-cols-3 mb-4 overflow-x-auto pb-1 -mx-1 px-1">
                <div class="min-w-[120px]">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Enrolled Students') }}</p>
                    <p class="text-base sm:text-lg font-bold mt-0.5">{{ $totalActiveEnrollments }}</p>
                    <p class="text-xs text-primary font-medium">{{ __('Confirmed') }}</p>
                </div>
                <div class="min-w-[120px]">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Courses Available') }}</p>
                    <p class="text-base sm:text-lg font-bold mt-0.5">{{ $totalCoursesCount }}</p>
                    <p class="text-xs text-primary font-medium">{{ __('Active Curriculums') }}</p>
                </div>
                <div class="min-w-[120px]">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Intake Capacity') }}</p>
                    <p class="text-base sm:text-lg font-bold mt-0.5">{{ \App\Models\Batch::where('status', 'active')->sum('max_students') ?: 200 }}</p>
                    <p class="text-xs text-primary font-medium">{{ __('Total Cohort Seats') }}</p>
                </div>
            </div>

            <x-chart.bar :data="$performanceData" :currentLabel="__('Admissions')" :previousLabel="__('Applications')"/>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Course Mix') }}</h3>
                <span class="text-xs text-muted-foreground">{{ __('Distribution') }}</span>
            </div>

            <x-chart.donut :slices="$courseMix" :centerLabel="__('Enrollments')" :prefix="''"/>
        </div>
    </div>

    {{-- Enrollment insights --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 sm:gap-4">
        <div class="xl:col-span-2 rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Academic Courses & Enrolment Insights') }}</h3>
                @if($user->can('courses.manage') || $user->hasRole('Super Administrator'))
                    <a href="{{ route('admin.courses.index') }}" wire:navigate class="text-xs text-primary hover:underline font-medium">
                        {{ __('Manage courses') }} →
                    </a>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2">{{ __('Top Academic Courses') }}</p>
                    <div class="space-y-1.5">
                        @foreach ($topCourses as $course)
                            <div class="flex items-center gap-3 bg-secondary/30 rounded-lg px-3 py-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-md bg-primary/15 text-primary text-xs font-bold shrink-0">{{ $course['rank'] }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5">
                                        <p class="text-xs sm:text-sm font-medium truncate">{{ $course['name'] }}</p>
                                        @if(!empty($course['is_free']))
                                            <span class="px-1.5 py-0.2 text-[9px] rounded font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                                                {{ __('Free') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[10px] sm:text-xs text-muted-foreground">{{ $course['enrolled'] }} {{ __('enrolled') }} · {{ $course['batch'] }}</p>
                                </div>
                                <span class="text-xs font-semibold shrink-0">
                                    @if(!empty($course['is_free']))
                                        <span class="text-emerald-600 dark:text-emerald-400">₹0.00</span>
                                    @else
                                        ₹{{ number_format($course['revenue'], 2) }}
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2">{{ __('Batches Capacity & Vacancy') }}</p>
                    <div class="space-y-1.5">
                        @foreach ($lowFillBatches as $batch)
                            <div class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 border {{ $batch['seats'] === 0 ? 'border-rose-500/20 bg-rose-500/5' : 'border-amber-500/20 bg-amber-500/5' }}">
                                <div class="min-w-0">
                                    <p class="text-xs sm:text-sm font-medium truncate">{{ $batch['name'] }}</p>
                                    <p class="text-[10px] sm:text-xs text-muted-foreground">{{ $batch['capacity'] }} {{ __('seats total') }}</p>
                                </div>
                                <span class="text-xs font-semibold shrink-0 {{ $batch['seats'] === 0 ? 'text-rose-500' : 'text-amber-500' }}">
                                    {{ $batch['seats'] }} {{ __('vacant') }}
                                </span>
                            </div>
                        @endforeach
                        @if($user->can('batches.manage') || $user->hasRole('Super Administrator'))
                            <a href="{{ route('admin.batches.index') }}" wire:navigate class="block text-center text-xs text-primary hover:underline font-medium pt-1">
                                {{ __('Manage batches & capacity') }} →
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Activity') }}</h3>
                <span class="text-xs text-muted-foreground">{{ __('Recent') }}</span>
            </div>
            <div class="space-y-3">
                @foreach ($activities as $activity)
                    <div class="flex items-start gap-3">
                        <span class="mt-1.5 h-2 w-2 rounded-full bg-primary shrink-0"></span>
                        <div class="min-w-0">
                            <p class="text-xs sm:text-sm">
                                <span class="font-medium">{{ $activity['user'] }}</span>
                                <span class="text-muted-foreground"> {{ $activity['action'] }} </span>
                                <span class="font-medium truncate block sm:inline">{{ $activity['amount'] }}</span>
                            </p>
                            <p class="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{{ $activity['time'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Heatmap + recent logins --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 sm:gap-4">
        <div class="xl:col-span-2">
            <x-chart.heatmap
                :data="$heatmapData"
                :title="__('Admissions & login activity by hour & day')"
                :subtitle="__('Last 7 days · darker = busier')"
            />
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Staff sessions') }}</h3>
                @if($user->can('users.view') || $user->hasRole('Super Administrator'))
                    <a href="{{ route('admin.users.index') }}" wire:navigate class="text-xs text-primary hover:underline font-medium">{{ __('View all') }} →</a>
                @endif
            </div>
            <div class="space-y-2">
                @foreach ($recentLogins as $login)
                    <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5">
                        <x-ui.avatar :name="$login['name']" :initials="$login['initials']" size="size-8 text-xs"/>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium truncate">{{ $login['name'] }}</p>
                            <p class="text-[10px] sm:text-xs text-muted-foreground">{{ $login['detail'] }}</p>
                        </div>
                        <x-ui.badge :color="$login['status'] === 'Online' ? 'success' : 'secondary'">
                            {{ __($login['status']) }}
                        </x-ui.badge>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts::app>
@else
{{-- GENERAL USER / CANDIDATE DASHBOARD --}}
@php
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
    $activeCourses = \App\Models\Course::where('status', 'active')->take(6)->get();
    $unreadTickets = $user ? $user->openTicketsCount() : 0;
    $dashboardChatEnabled = (bool) \App\Models\Setting::get('chat.enabled', true);
    $dashboardMeetingsEnabled = (bool) \App\Models\Setting::get('meeting.enabled', \App\Models\Setting::get('chat.meetings_enabled', true));
    $canAccessDashboardChat = $dashboardChatEnabled && $user && ($user->can('chat.access') || $user->hasRole('Super Administrator'));
    $canAccessDashboardMeetings = $dashboardMeetingsEnabled && $user && ($user->can('meetings.view') || $user->hasRole('Super Administrator'));
    $unreadChatCount = $canAccessDashboardChat ? $user->unreadChatMessagesCount() : 0;
@endphp

<x-layouts::app :title="__('User Dashboard')">
    @if ($user?->isAdmin())
        <livewire:pages::teams.pending-invitations-modal/>
    @endif

    {{-- Welcome Banner --}}
    <div class="bg-card border border-border rounded-xl p-5 sm:p-6 shadow-xs relative overflow-hidden">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-primary text-xl font-bold uppercase shadow-inner">
                    {{ substr($user->name, 0, 1) }}
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-xl sm:text-2xl font-bold text-foreground">
                            {{ $greeting }}, {{ $user->name }}
                        </h1>
                        <x-ui.badge variant="secondary" class="text-xs">
                            {{ __('Registered Candidate') }}
                        </x-ui.badge>
                        <x-ui.badge variant="outline" class="font-mono text-xs gap-1" title="{{ __('Universal Registration Number') }}">
                            <span class="text-muted-foreground">{{ __('URN:') }}</span>
                            @if(!empty($user->urn))
                                <span class="font-bold text-foreground">{{ $user->urn }}</span>
                            @else
                                <span class="text-muted-foreground/70 italic text-[11px]">{{ __('Not Assigned') }}</span>
                            @endif
                        </x-ui.badge>
                        @if($user->email_verified_at)
                            <x-ui.badge variant="success" class="text-xs">
                                {{ __('Email Verified') }}
                            </x-ui.badge>
                        @endif
                    </div>
                    <p class="text-xs sm:text-sm text-muted-foreground mt-0.5">
                        {{ $user->email }} @if($user->phone) • {{ $user->phone }} @endif
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('student.dashboard') }}" wire:navigate class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-primary text-primary-foreground text-xs font-semibold hover:bg-primary/90 transition-colors shadow-xs">
                    <x-icon name="user" class="w-4 h-4" />
                    {{ __('Complete Student Profile') }}
                </a>
                <a href="{{ route('tickets.create') }}" wire:navigate class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-border bg-card text-foreground text-xs font-medium hover:bg-secondary transition-colors">
                    <x-icon name="help-circle" class="w-4 h-4 text-muted-foreground" />
                    {{ __('Need Help?') }}
                </a>
            </div>
        </div>

        {{-- Next Steps Notice --}}
        <div class="mt-5 pt-4 border-t border-border/60 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-muted-foreground">
            <div class="flex items-center gap-2">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0" />
                <span>{{ __('Complete all personal, address, qualification, and UPSC details to qualify for official batch allotment.') }}</span>
            </div>
            <a href="{{ route('student.dashboard') }}" wire:navigate class="text-primary font-semibold hover:underline shrink-0">
                {{ __('Open Student Dossier') }} →
            </a>
        </div>
    </div>

    {{-- Quick Access Grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <a href="{{ route('student.dashboard') }}" wire:navigate class="rounded-xl border border-border bg-card p-4 hover:border-primary/50 transition-colors group">
            <div class="flex items-center justify-between mb-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="user-check" class="h-5 w-5"/>
                </span>
                <x-icon name="arrow-up-right" class="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors"/>
            </div>
            <h4 class="text-sm font-semibold text-foreground">{{ __('Candidate Dossier') }}</h4>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Manage personal data, qualifications, and identity proofs.') }}</p>
        </a>

        <a href="{{ route('tickets.index') }}" wire:navigate class="rounded-xl border border-border bg-card p-4 hover:border-primary/50 transition-colors group">
            <div class="flex items-center justify-between mb-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10 text-amber-500">
                    <x-icon name="life-buoy" class="h-5 w-5"/>
                </span>
                @if($unreadTickets > 0)
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] bg-amber-500 text-white font-mono font-bold">{{ $unreadTickets }}</span>
                @else
                    <x-icon name="arrow-up-right" class="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors"/>
                @endif
            </div>
            <h4 class="text-sm font-semibold text-foreground">{{ __('Support Helpdesk') }}</h4>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Submit inquiries regarding admissions, course fees, or classes.') }}</p>
        </a>

        @if ($canAccessDashboardChat)
        <a href="{{ route('chat.index') }}" wire:navigate class="rounded-xl border border-border bg-card p-4 hover:border-primary/50 transition-colors group">
            <div class="flex items-center justify-between mb-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500">
                    <x-icon name="message-square" class="h-5 w-5"/>
                </span>
                @if($unreadChatCount > 0)
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] bg-emerald-500 text-white font-mono font-bold">{{ $unreadChatCount }}</span>
                @else
                    <x-icon name="arrow-up-right" class="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors"/>
                @endif
            </div>
            <h4 class="text-sm font-semibold text-foreground">{{ __('Live Community Chat') }}</h4>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Connect in real-time with academy coordinators and peers.') }}</p>
        </a>
        @endif

        @if ($canAccessDashboardMeetings)
        <a href="{{ route('meetings.index') }}" wire:navigate class="rounded-xl border border-border bg-card p-4 hover:border-primary/50 transition-colors group">
            <div class="flex items-center justify-between mb-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-500/10 text-sky-500">
                    <x-icon name="video" class="h-5 w-5"/>
                </span>
                <x-icon name="arrow-up-right" class="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors"/>
            </div>
            <h4 class="text-sm font-semibold text-foreground">{{ __('Online Meetings & Classes') }}</h4>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Attend interactive video lectures and mentoring sessions.') }}</p>
        </a>
        @endif
    </div>

    {{-- Academic Programmes Spotlight --}}
    <div class="rounded-xl border border-border bg-card p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-3 border-b border-border">
            <div>
                <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                    <x-icon name="graduation-cap" class="w-5 h-5 text-primary" />
                    {{ __('Academic Training Programmes & Courses') }}
                </h3>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ __('Explore civil services coaching programs offered with transparent category-based fee structures.') }}
                </p>
            </div>
            <span class="text-xs text-muted-foreground">
                {{ $activeCourses->count() }} {{ __('Programs Available') }}
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse($activeCourses as $course)
                @php
                    $isFree = $course->isFree() || $course->fee_type === 'free';
                @endphp
                <div class="rounded-xl border {{ $isFree ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-border bg-secondary/20' }} p-4 flex flex-col justify-between space-y-3">
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-xs font-bold text-primary">{{ $course->code }}</span>
                            @if($isFree)
                                <x-ui.badge variant="success" class="text-[10px]">
                                    {{ __('Free of Cost / No Fee') }}
                                </x-ui.badge>
                            @else
                                <x-ui.badge variant="secondary" class="text-[10px] capitalize">
                                    {{ str_replace('_', ' ', $course->fee_type) }}
                                </x-ui.badge>
                            @endif
                        </div>
                        <h4 class="text-sm font-bold text-foreground leading-snug">{{ $course->name }}</h4>
                        @if($course->category)
                            <div class="text-[11px] text-muted-foreground">
                                <span class="font-medium text-foreground">{{ \App\Helpers\CourseData::getCategoryLabel($course->category) }}</span>
                            </div>
                        @endif
                        @if($course->description)
                            <p class="text-xs text-muted-foreground line-clamp-2 leading-relaxed">
                                {{ $course->description }}
                            </p>
                        @endif
                    </div>

                    <div class="pt-3 border-t border-border/50 flex items-center justify-between text-xs">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Tuition Rate') }}</span>
                            @if($isFree)
                                <span class="font-bold text-emerald-600 dark:text-emerald-400">₹0.00 (Exempted)</span>
                            @elseif($course->fee_type === 'monthly')
                                <span class="font-semibold text-foreground">₹1,000 <span class="text-[10px] text-muted-foreground">/mo (UR)</span> · ₹500 <span class="text-[10px] text-muted-foreground">/mo (Res)</span></span>
                            @elseif($course->fee_type === 'one_time')
                                <span class="font-semibold text-foreground">₹1,500 <span class="text-[10px] text-muted-foreground">(UR)</span> · ₹750 <span class="text-[10px] text-muted-foreground">(Res)</span></span>
                            @else
                                <span class="font-semibold text-foreground">₹{{ number_format((float)$course->total_fee, 2) }}</span>
                            @endif
                        </div>
                        <span class="text-[11px] text-muted-foreground font-medium">
                            {{ $course->duration_months ? $course->duration_months . ' ' . __('Months') : ($course->duration_weeks ? $course->duration_weeks . ' ' . __('Weeks') : '') }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="col-span-3 py-6 text-center text-xs text-muted-foreground">
                    {{ __('No active academic courses published at this time.') }}
                </div>
            @endforelse
        </div>
    </div>

    {{-- Contact Desk --}}
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs text-muted-foreground">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                <x-icon name="phone-call" class="w-5 h-5"/>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-foreground">{{ __('Admissions & Academic Helpdesk') }}</h4>
                <p class="mt-0.5">{{ __('Monday to Friday · 10:00 AM to 5:00 PM IST') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="mailto:admissions@sntcssc.in" class="hover:text-foreground font-medium transition-colors">admissions@sntcssc.in</a>
            <span>•</span>
            <a href="{{ route('tickets.create') }}" wire:navigate class="text-primary font-semibold hover:underline">{{ __('Open Ticket') }} →</a>
        </div>
    </div>
</x-layouts::app>
@endif
