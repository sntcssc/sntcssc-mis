<?php

use App\Models\Alumni;
use App\Models\AlumniCourseBatch;
use App\Services\Alumni\AlumniService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Alumni Analytics & Placement Insights')] class extends Component {
    #[Computed]
    public function stats(): array
    {
        return AlumniService::getStatistics();
    }

    #[Computed]
    public function topCourses()
    {
        return AlumniCourseBatch::select('course_name', DB::raw('count(*) as total'))
            ->groupBy('course_name')
            ->orderByDesc('total')
            ->take(6)
            ->get();
    }

    #[Computed]
    public function topBatches()
    {
        return AlumniCourseBatch::select('batch_name', DB::raw('count(*) as total'))
            ->groupBy('batch_name')
            ->orderByDesc('total')
            ->take(6)
            ->get();
    }

    #[Computed]
    public function recentOfficers()
    {
        return Alumni::query()
            ->latest('id')
            ->take(5)
            ->get();
    }
};
?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="p-2 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400">
                    <x-icon name="bar-chart-2" class="w-6 h-6" />
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">
                        {{ __('Alumni Analytics & Institutional Insights') }}
                    </h1>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        {{ __('Real-time metrics on officer placements, cadre allocations, service sectors, and batch performance.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a
                href="{{ route('admin.alumni.index') }}"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-card hover:bg-muted text-foreground transition-colors"
            >
                <x-icon name="arrow-left" class="w-3.5 h-3.5" />
                {{ __('Back to Directory') }}
            </a>
        </div>
    </div>

    <!-- Big KPI Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-5 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Registered Alumni') }}</span>
                <span class="p-2 rounded-lg bg-violet-50 dark:bg-violet-950/50 text-violet-600 dark:text-violet-400">
                    <x-icon name="users" class="w-5 h-5" />
                </span>
            </div>
            <div class="text-3xl font-bold text-foreground mt-2">{{ $this->stats['total_alumni'] }}</div>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Alumni records in database') }}</p>
        </div>

        <div class="p-5 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Civil Services Placements') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="medal" class="w-5 h-5" />
                </span>
            </div>
            <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ $this->stats['civil_servants_count'] }}</div>
            <p class="text-xs text-muted-foreground mt-1">{{ __('UPSC CSE & WBCS / WBPS officers') }}</p>
        </div>

        <div class="p-5 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Featured Spotlight') }}</span>
                <span class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="star" class="w-5 h-5" />
                </span>
            </div>
            <div class="text-3xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ $this->stats['featured_alumni'] }}</div>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Highlighted in showcase') }}</p>
        </div>

        <div class="p-5 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wider">{{ __('Academic Batches') }}</span>
                <span class="p-2 rounded-lg bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400">
                    <x-icon name="layers" class="w-5 h-5" />
                </span>
            </div>
            <div class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ $this->stats['unique_batches_count'] }}</div>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Represented across batches') }}</p>
        </div>
    </div>

    <!-- Analytics Charts & Distribution Grids -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Service Sector Distribution -->
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="briefcase" class="w-4 h-4 text-violet-600" />
                    <h3 class="font-bold text-sm text-foreground">{{ __('Service Category Breakdown') }}</h3>
                </div>
                <span class="text-xs text-muted-foreground">{{ count($this->stats['by_service_type']) }} {{ __('Categories') }}</span>
            </div>

            <div class="space-y-3">
                @php $total = max($this->stats['total_alumni'], 1); @endphp
                @forelse($this->stats['by_service_type'] as $type => $count)
                    @php $pct = round(($count / $total) * 100); @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-medium text-foreground truncate max-w-[280px]">{{ $type }}</span>
                            <span class="font-bold text-violet-600 dark:text-violet-400">{{ $count }} ({{ $pct }}%)</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-muted overflow-hidden">
                            <div class="h-full rounded-full bg-violet-600 dark:bg-violet-500 transition-all duration-500" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-muted-foreground italic py-4 text-center">{{ __('No service data available yet.') }}</p>
                @endforelse
            </div>
        </div>

        <!-- Year-Wise Qualifying Trend -->
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="calendar" class="w-4 h-4 text-emerald-600" />
                    <h3 class="font-bold text-sm text-foreground">{{ __('Year-Wise Qualifying Progression') }}</h3>
                </div>
                <span class="text-xs text-muted-foreground">{{ __('Examination Years') }}</span>
            </div>

            <div class="space-y-3">
                @forelse($this->stats['by_year'] as $year => $count)
                    @php $pct = round(($count / $total) * 100); @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-semibold text-foreground">{{ $year }}</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $count }} {{ __('Officers') }}</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-muted overflow-hidden">
                            <div class="h-full rounded-full bg-emerald-500 transition-all duration-500" style="width: {{ min(100, $pct * 2.5) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-muted-foreground italic py-4 text-center">{{ __('No year trend recorded yet.') }}</p>
                @endforelse
            </div>
        </div>

        <!-- Posting States Distribution -->
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="map-pin" class="w-4 h-4 text-blue-600" />
                    <h3 class="font-bold text-sm text-foreground">{{ __('Geographic Posting Footprint') }}</h3>
                </div>
                <span class="text-xs text-muted-foreground">{{ count($this->stats['by_state']) }} {{ __('States / Territories') }}</span>
            </div>

            <div class="grid grid-cols-2 gap-3">
                @forelse($this->stats['by_state'] as $state => $count)
                    <div class="p-3 rounded-lg bg-muted/30 border border-border flex items-center justify-between">
                        <span class="text-xs font-medium text-foreground truncate">{{ $state }}</span>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                            {{ $count }}
                        </span>
                    </div>
                @empty
                    <p class="col-span-2 text-xs text-muted-foreground italic py-4 text-center">{{ __('No posting state data yet.') }}</p>
                @endforelse
            </div>
        </div>

        <!-- Top Courses & Batches Performance -->
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="graduation-cap" class="w-4 h-4 text-amber-600" />
                    <h3 class="font-bold text-sm text-foreground">{{ __('Top Qualifying Courses at SNTCSSC') }}</h3>
                </div>
                <span class="text-xs text-muted-foreground">{{ __('Officer count') }}</span>
            </div>

            <div class="space-y-2.5">
                @forelse($this->topCourses as $course)
                    <div class="flex items-center justify-between p-2.5 rounded-lg bg-muted/20 border border-border text-xs">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            <span class="font-medium text-foreground">{{ $course->course_name }}</span>
                        </div>
                        <span class="font-bold text-foreground">{{ $course->total }} {{ __('Officers') }}</span>
                    </div>
                @empty
                    <p class="text-xs text-muted-foreground italic py-4 text-center">{{ __('No course records found.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
