@php
    $user = auth()->user();
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));

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

    $courseMix = [
        ['name' => 'Composite Course', 'value' => 184, 'color' => '#10b981'],
        ['name' => 'Prelims Crash Course', 'value' => 126, 'color' => '#06b6d4'],
        ['name' => 'Mains Guidance', 'value' => 98, 'color' => '#f59e0b'],
        ['name' => 'Interview Training', 'value' => 64, 'color' => '#8b5cf6'],
        ['name' => 'Mock Interview', 'value' => 41, 'color' => '#f43f5e'],
        ['name' => 'Test Series', 'value' => 28, 'color' => '#ec4899'],
        ['name' => 'Study Material', 'value' => 16, 'color' => '#f97316'],
        ['name' => 'Others', 'value' => 9, 'color' => '#14b8a6'],
    ];

    $topCourses = [
        ['rank' => 1, 'name' => 'Composite Course (Full Year)', 'enrolled' => 184, 'batch' => 'B-2026-A', 'revenue' => 2760000],
        ['rank' => 2, 'name' => 'Prelims Crash Course', 'enrolled' => 126, 'batch' => 'PCC-Jun', 'revenue' => 945000],
        ['rank' => 3, 'name' => 'Mains Guidance Programme', 'enrolled' => 98, 'batch' => 'MGP-01', 'revenue' => 1176000],
        ['rank' => 4, 'name' => 'Interview Training Programme', 'enrolled' => 64, 'batch' => 'ITP-01', 'revenue' => 640000],
        ['rank' => 5, 'name' => 'Mock Interview Programme', 'enrolled' => 41, 'batch' => 'MIP-02', 'revenue' => 328000],
        ['rank' => 6, 'name' => 'Weekend Test Series', 'enrolled' => 28, 'batch' => 'WTS-01', 'revenue' => 84000],
    ];

    $lowFillBatches = [
        ['name' => 'MGP-02 (Mains Guidance)', 'seats' => 6, 'capacity' => 30],
        ['name' => 'MIP-03 (Mock Interview)', 'seats' => 0, 'capacity' => 24],
        ['name' => 'WTS-02 (Weekend Test Series)', 'seats' => 4, 'capacity' => 40],
        ['name' => 'ITP-02 (Interview Training)', 'seats' => 11, 'capacity' => 20],
    ];

    $activities = [
        ['user' => 'Priya Sharma', 'action' => 'enrolled in', 'amount' => 'Composite Course', 'time' => '1 hour ago'],
        ['user' => 'Rahul Verma', 'action' => 'submitted application', 'amount' => '#1248', 'time' => '2 hours ago'],
        ['user' => 'Admin', 'action' => 'created batch', 'amount' => 'PCC-Jul-2026', 'time' => '2 hours ago'],
        ['user' => 'Ankit Singh', 'action' => 'paid installment of', 'amount' => '₹15,000', 'time' => '3 hours ago'],
        ['user' => 'Sneha Patel', 'action' => 'completed admission test with', 'amount' => '82/100', 'time' => '4 hours ago'],
        ['user' => 'Admin', 'action' => 'moved 4 applications to', 'amount' => 'List A', 'time' => '5 hours ago'],
    ];

    $recentLogins = [
        ['initials' => 'A', 'name' => 'Admin', 'status' => 'Online', 'detail' => 'Current session', 'ip' => '192.168.1.10'],
        ['initials' => 'RP', 'name' => 'Rakesh Prasad', 'status' => 'Online', 'detail' => 'Admissions desk', 'ip' => '192.168.1.24'],
        ['initials' => 'SM', 'name' => 'Sunita Mishra', 'status' => 'Offline', 'detail' => '2 hours ago', 'ip' => '192.168.1.31'],
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

<x-layouts::app :title="__('Dashboard')">
    <livewire:pages::teams.pending-invitations-modal/>

    {{-- Greeting --}}
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
        <div>
            <div class="flex items-center gap-2 flex-wrap">
                <h1 class="text-lg sm:text-xl font-semibold">{{ $greeting }}, {{ $user->name }}</h1>
                <span class="flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-500">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    {{ __('Live') }}
                </span>
            </div>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ now()->isoFormat('dddd, MMM D') }} · {{ __('2 staff online') }}
            </p>
        </div>
        <button type="button" class="text-xs text-muted-foreground hover:text-foreground transition-colors w-fit cursor-pointer">
            {{ __('Today') }} ↓
        </button>
    </div>

    {{-- Hero card --}}
    <div class="rounded-xl border border-border bg-card p-4 sm:p-5 lg:p-6">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                {{ __("Today's fee collections") }}
            </span>
            <span class="text-[10px] sm:text-xs text-muted-foreground hidden sm:block">+12.4% {{ __('vs last week average') }}</span>
        </div>
        <div class="flex flex-col gap-4">
            <div>
                <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold tracking-tight">₹1,83,871</h2>
                <div class="flex flex-wrap gap-x-3 sm:gap-x-4 gap-y-1 mt-2 text-xs sm:text-sm text-muted-foreground">
                    <span><strong class="text-foreground">11</strong> {{ __('payments') }}</span>
                    <span><strong class="text-foreground">₹16,715</strong> {{ __('avg collection') }}</span>
                    <span><strong class="text-foreground">29</strong> {{ __('pending installments') }}</span>
                </div>
            </div>
            <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1">
                <a href="{{ route('admin.admissions.index') }}" wire:navigate class="flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-3 py-2 hover:bg-secondary transition-colors shrink-0 min-w-[120px]">
                    <x-icon name="file-text" class="h-4 w-4 text-muted-foreground"/>
                    <div class="text-left">
                        <p class="text-[10px] sm:text-xs text-muted-foreground">{{ __('Applications') }}</p>
                        <p class="text-xs sm:text-sm font-semibold">+18% 24</p>
                    </div>
                </a>
                <a href="{{ route('admin.enrollments.index') }}" wire:navigate class="flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-3 py-2 hover:bg-secondary transition-colors shrink-0 min-w-[120px]">
                    <x-icon name="users" class="h-4 w-4 text-muted-foreground"/>
                    <div class="text-left">
                        <p class="text-[10px] sm:text-xs text-muted-foreground">{{ __('New enrollments') }}</p>
                        <p class="text-xs sm:text-sm font-semibold">+9% 13</p>
                    </div>
                </a>
                <a href="{{ route('admin.tests.index') }}" wire:navigate class="flex items-center gap-2 rounded-lg border border-border bg-secondary/50 px-3 py-2 hover:bg-secondary transition-colors shrink-0 min-w-[120px]">
                    <x-icon name="rotate-ccw" class="h-4 w-4 text-muted-foreground"/>
                    <div class="text-left">
                        <p class="text-[10px] sm:text-xs text-muted-foreground">{{ __('Refunds') }}</p>
                        <p class="text-xs sm:text-sm font-semibold">−0% ₹0</p>
                    </div>
                </a>
            </div>
        </div>
    </div>

    {{-- Operations + mini cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
        <div class="sm:col-span-2 lg:col-span-2 rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold">{{ __('Operations') }}</h3>
                    <span class="hidden sm:flex items-center gap-1 text-[10px] text-muted-foreground">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        {{ __('Real-time · auto-refresh 30s') }}
                    </span>
                </div>
            </div>
            <div class="grid grid-cols-1 xs:grid-cols-3 gap-2 sm:gap-3">
                <a href="{{ route('admin.admissions.index') }}" wire:navigate class="flex items-center gap-3 rounded-lg border border-amber-500/20 bg-amber-500/5 px-3 sm:px-4 py-2.5 sm:py-3 hover:bg-amber-500/10 transition-colors group">
                    <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg bg-amber-500/15 text-amber-500 font-bold text-base sm:text-lg shrink-0">7</span>
                    <div class="text-left">
                        <p class="text-xs sm:text-sm font-semibold text-foreground">{{ __('Pending admission reviews') }}</p>
                        <p class="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{{ __('Oldest waiting 3 days') }}</p>
                    </div>
                    <x-icon name="arrow-up-right" class="ml-auto h-4 w-4 text-muted-foreground group-hover:text-amber-500 transition-colors hidden sm:block"/>
                </a>
                <a href="#" class="flex items-center gap-3 rounded-lg border border-rose-500/20 bg-rose-500/5 px-3 sm:px-4 py-2.5 sm:py-3 hover:bg-rose-500/10 transition-colors group">
                    <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg bg-rose-500/15 text-rose-500 font-bold text-base sm:text-lg shrink-0">12</span>
                    <div class="text-left">
                        <p class="text-xs sm:text-sm font-semibold text-foreground">{{ __('Overdue fee installments') }}</p>
                        <p class="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{{ __('₹2.4L pending this month') }}</p>
                    </div>
                    <x-icon name="arrow-up-right" class="ml-auto h-4 w-4 text-muted-foreground group-hover:text-rose-500 transition-colors hidden sm:block"/>
                </a>
                <a href="{{ route('admin.batches.index') }}" wire:navigate class="flex items-center gap-3 rounded-lg border border-cyan-500/20 bg-cyan-500/5 px-3 sm:px-4 py-2.5 sm:py-3 hover:bg-cyan-500/10 transition-colors group">
                    <span class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg bg-cyan-500/15 text-cyan-500 font-bold text-base sm:text-lg shrink-0">3</span>
                    <div class="text-left">
                        <p class="text-xs sm:text-sm font-semibold text-foreground">{{ __('Batches starting this week') }}</p>
                        <p class="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{{ __('2 need more enrollments') }}</p>
                    </div>
                    <x-icon name="arrow-up-right" class="ml-auto h-4 w-4 text-muted-foreground group-hover:text-cyan-500 transition-colors hidden sm:block"/>
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <span class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('This week') }}</span>
            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight mt-1">₹9,42,500</h2>
            <p class="text-xs text-emerald-500 font-medium mt-1">+8.2% {{ __('vs last week') }}</p>
            <div class="mt-4 space-y-2.5">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ __('Enrollments') }}</span>
                    <span class="font-semibold">78</span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ __('Applications') }}</span>
                    <span class="font-semibold">142</span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ __('Active batches') }}</span>
                    <span class="font-semibold">16</span>
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
                    <p class="text-xs text-muted-foreground mt-0.5">{{ __('Applications vs admissions') }}</p>
                </div>
                <div class="flex gap-1 overflow-x-auto">
                    @foreach ([__('Today'), __('7d'), __('14d'), __('30d')] as $period)
                        <button type="button" class="h-7 px-2.5 text-xs shrink-0 rounded-md font-medium transition-colors cursor-pointer {{ $loop->index === 2 ? 'bg-secondary text-foreground' : 'text-muted-foreground hover:text-foreground' }}">
                            {{ $period }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-4 sm:grid sm:grid-cols-3 mb-4 overflow-x-auto pb-1 -mx-1 px-1">
                <div class="min-w-[120px]">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Applications') }}</p>
                    <p class="text-base sm:text-lg font-bold mt-0.5">486</p>
                    <p class="text-xs text-emerald-500 font-medium">+18.4%</p>
                </div>
                <div class="min-w-[120px]">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Admissions') }}</p>
                    <p class="text-base sm:text-lg font-bold mt-0.5">341</p>
                    <p class="text-xs text-emerald-500 font-medium">+12.9%</p>
                </div>
                <div class="min-w-[120px]">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Conversion') }}</p>
                    <p class="text-base sm:text-lg font-bold mt-0.5">70.2%</p>
                    <p class="text-xs text-emerald-500 font-medium">+3.1%</p>
                </div>
            </div>

            <x-chart.bar :data="$performanceData" :currentLabel="__('Admissions')" :previousLabel="__('Applications')"/>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Course mix') }}</h3>
                <span class="text-xs text-muted-foreground">14d</span>
            </div>

            <x-chart.donut :slices="$courseMix" :centerLabel="__('Enrollments')" :prefix="''"/>
        </div>
    </div>

    {{-- Enrollment insights --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3 sm:gap-4">
        <div class="xl:col-span-2 rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Enrollment insights') }}</h3>
                <a href="{{ route('admin.courses.index') }}" wire:navigate class="text-xs text-emerald-500 hover:underline font-medium">
                    {{ __('View all courses') }} →
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2">{{ __('Top courses') }}</p>
                    <div class="space-y-1.5">
                        @foreach ($topCourses as $course)
                            <div class="flex items-center gap-3 bg-secondary/30 rounded-lg px-3 py-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-500/15 text-emerald-500 text-xs font-bold shrink-0">{{ $course['rank'] }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs sm:text-sm font-medium truncate">{{ $course['name'] }}</p>
                                    <p class="text-[10px] sm:text-xs text-muted-foreground">{{ $course['enrolled'] }} {{ __('enrolled') }} · {{ $course['batch'] }}</p>
                                </div>
                                <span class="text-xs font-semibold shrink-0">₹{{ number_format($course['revenue'] / 100000, 1) }}L</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2">{{ __('Batches needing attention') }}</p>
                    <div class="space-y-1.5">
                        @foreach ($lowFillBatches as $batch)
                            <div class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 border {{ $batch['seats'] === 0 ? 'border-rose-500/20 bg-rose-500/5' : 'border-amber-500/20 bg-amber-500/5' }}">
                                <div class="min-w-0">
                                    <p class="text-xs sm:text-sm font-medium truncate">{{ $batch['name'] }}</p>
                                    <p class="text-[10px] sm:text-xs text-muted-foreground">{{ $batch['capacity'] }} {{ __('seats total') }}</p>
                                </div>
                                <span class="text-xs font-semibold shrink-0 {{ $batch['seats'] === 0 ? 'text-rose-500' : 'text-amber-500' }}">
                                    {{ $batch['seats'] }}/{{ $batch['capacity'] }}
                                </span>
                            </div>
                        @endforeach
                        <a href="{{ route('admin.batches.index') }}" wire:navigate class="block text-center text-xs text-emerald-500 hover:underline font-medium pt-1">
                            {{ __('Manage batches') }} →
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Activity') }}</h3>
                <span class="text-xs text-muted-foreground">{{ __('Today') }}</span>
            </div>
            <div class="space-y-3">
                @foreach ($activities as $activity)
                    <div class="flex items-start gap-3">
                        <span class="mt-1.5 h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>
                        <div class="min-w-0">
                            <p class="text-xs sm:text-sm">
                                <span class="font-medium">{{ $activity['user'] }}</span>
                                <span class="text-muted-foreground"> {{ $activity['action'] }} </span>
                                <span class="font-medium">{{ $activity['amount'] }}</span>
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
                :title="__('Admissions activity by hour & day')"
                :subtitle="__('Last 7 days · darker = busier')"
            />
        </div>

        <div class="rounded-xl border border-border bg-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold">{{ __('Staff sessions') }}</h3>
                <a href="{{ route('admin.users.index') }}" wire:navigate class="text-xs text-emerald-500 hover:underline font-medium">{{ __('View all') }} →</a>
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
