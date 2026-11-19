<?php

use App\Models\Course;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialFile;
use App\Models\StudyMaterialShare;
use App\Services\StudyMaterial\StudyMaterialService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Study Materials Analytics')] class extends Component {
    public function mount(): void
    {
        $user = auth()->user();
        if ($user && $user->hasRole('Student') && ! $user->hasRole(['Super Administrator', 'Administrator', 'Faculty', 'Staff'])) {
            abort(403);
        }

        abort_unless($user && ($user->can('study_materials.view') || $user->hasRole('Super Administrator')), 403);
    }

    #[Computed]
    public function stats(): array
    {
        $service = app(StudyMaterialService::class);
        return $service->getStatistics();
    }

    #[Computed]
    public function topDownloaded()
    {
        return StudyMaterial::with(['course', 'batches', 'files'])
            ->published()
            ->orderByDesc('download_count')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function recentAccessLogs()
    {
        return StudyMaterialAccessLog::with(['studyMaterial.course', 'file', 'user'])
            ->latest('id')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function channelSharesBreakdown(): array
    {
        $shares = StudyMaterialShare::select('channel', DB::raw('count(*) as total'), DB::raw("sum(case when status = 'sent' then 1 else 0 end) as successful"))
            ->groupBy('channel')
            ->get();

        $result = [];
        foreach ($shares as $s) {
            $rate = $s->total > 0 ? round(($s->successful / $s->total) * 100, 1) : 0;
            $result[$s->channel] = [
                'total' => $s->total,
                'successful' => (int) $s->successful,
                'rate' => $rate,
            ];
        }

        return $result;
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.study-materials.index') }}" wire:navigate class="text-muted-foreground hover:text-foreground">
                    <x-icon name="arrow-left" class="w-5 h-5" />
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Study Materials & Resources Analytics') }}</h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Real-time institutional KPI telemetry, course resource coverage, download analytics, and notification channel performance.') }}
            </p>
        </div>

        <div>
            <a href="{{ route('admin.study-materials.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all">
                <x-icon name="folder" class="w-4 h-4" />
                <span>{{ __('Study Materials Directory') }}</span>
            </a>
        </div>
    </div>

    <!-- Top KPI Highlights -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-card rounded-xl border border-border p-5 shadow-sm">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Total Academic Resources') }}</span>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-extrabold text-foreground">{{ number_format($this->stats['total_materials']) }}</span>
                <span class="text-xs text-emerald-600 font-semibold">{{ $this->stats['published_materials'] }} {{ __('published') }}</span>
            </div>
            <div class="w-full bg-muted rounded-full h-1.5 mt-3 overflow-hidden">
                @php $publishedPct = $this->stats['total_materials'] > 0 ? ($this->stats['published_materials'] / $this->stats['total_materials']) * 100 : 0; @endphp
                <div class="bg-emerald-500 h-1.5 rounded-full" style="width: {{ $publishedPct }}%"></div>
            </div>
        </div>

        <div class="bg-card rounded-xl border border-border p-5 shadow-sm">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Total Storage Consumed') }}</span>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-extrabold text-foreground">{{ $this->stats['formatted_storage'] }}</span>
                <span class="text-xs text-muted-foreground font-mono">({{ $this->stats['total_files'] }} files)</span>
            </div>
            <p class="text-xs text-muted-foreground mt-3">
                {{ __('Managed via secure FileUploadService') }}
            </p>
        </div>

        <div class="bg-card rounded-xl border border-border p-5 shadow-sm">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Student Engagement') }}</span>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-extrabold text-foreground">{{ number_format($this->stats['total_downloads']) }}</span>
                <span class="text-xs text-blue-600 font-semibold">{{ number_format($this->stats['total_views']) }} views</span>
            </div>
            <p class="text-xs text-muted-foreground mt-3">
                {{ __('Downloads & online lecture inspections') }}
            </p>
        </div>

        <div class="bg-card rounded-xl border border-border p-5 shadow-sm">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Multi-Channel Shares') }}</span>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-extrabold text-foreground">{{ number_format($this->stats['total_shares']) }}</span>
                <span class="text-xs text-amber-600 font-semibold">{{ __('broadcasted') }}</span>
            </div>
            <p class="text-xs text-muted-foreground mt-3">
                {{ __('Across WhatsApp, Email, SMS & Telegram') }}
            </p>
        </div>
    </div>

    <!-- Charts & Distributions Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 1. Resource Type Breakdown -->
        <div class="bg-card rounded-xl border border-border p-6 shadow-sm">
            <h3 class="text-base font-bold text-foreground mb-1 flex items-center gap-2">
                <x-icon name="pie-chart" class="w-4 h-4 text-primary" />
                <span>{{ __('Material Type Distribution') }}</span>
            </h3>
            <p class="text-xs text-muted-foreground mb-4">
                {{ __('Breakdown of published study resources across instructional formats.') }}
            </p>

            <div class="space-y-3">
                @php
                    $allTypes = StudyMaterial::materialTypes();
                    $typeStats = $this->stats['type_distribution'];
                    $totalMat = max($this->stats['total_materials'], 1);
                @endphp
                @foreach($allTypes as $typeKey => $typeLabel)
                    @php
                        $cnt = $typeStats[$typeKey] ?? 0;
                        $pct = round(($cnt / $totalMat) * 100, 1);
                    @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-medium text-foreground">{{ $typeLabel }}</span>
                            <span class="text-muted-foreground">{{ $cnt }} ({{ $pct }}%)</span>
                        </div>
                        <div class="w-full bg-muted rounded-full h-2 overflow-hidden">
                            <div class="bg-primary h-2 rounded-full transition-all" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- 2. Communication Channel Delivery Performance -->
        <div class="bg-card rounded-xl border border-border p-6 shadow-sm">
            <h3 class="text-base font-bold text-foreground mb-1 flex items-center gap-2">
                <x-icon name="share-2" class="w-4 h-4 text-primary" />
                <span>{{ __('Share & Notification Delivery Rates') }}</span>
            </h3>
            <p class="text-xs text-muted-foreground mb-4">
                {{ __('Delivery success rates of study material notifications to students.') }}
            </p>

            <div class="grid grid-cols-2 gap-3 mb-6">
                @php
                    $channelMap = [
                        'whatsapp' => ['label' => 'WhatsApp', 'color' => 'emerald', 'icon' => 'message-circle'],
                        'email' => ['label' => 'Email', 'color' => 'blue', 'icon' => 'mail'],
                        'sms' => ['label' => 'SMS', 'color' => 'amber', 'icon' => 'smartphone'],
                        'telegram' => ['label' => 'Telegram', 'color' => 'sky', 'icon' => 'send'],
                    ];
                    $bd = $this->channelSharesBreakdown;
                @endphp

                @foreach($channelMap as $cKey => $cMeta)
                    @php $data = $bd[$cKey] ?? ['total' => 0, 'successful' => 0, 'rate' => 100]; @endphp
                    <div class="p-3.5 rounded-xl border border-border bg-muted/20">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-foreground flex items-center gap-1.5">
                                <x-icon name="{{ $cMeta['icon'] }}" class="w-4 h-4 text-{{ $cMeta['color'] }}-500" />
                                <span>{{ $cMeta['label'] }}</span>
                            </span>
                            <span class="text-xs font-bold text-{{ $cMeta['color'] }}-600">{{ $data['rate'] }}%</span>
                        </div>
                        <div class="mt-2 text-xs text-muted-foreground">
                            <span class="font-bold text-foreground">{{ $data['successful'] }}</span> / {{ $data['total'] }} {{ __('sent') }}
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Course Distribution Footprint -->
            <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">{{ __('Top Academic Courses by Study Material:') }}</h4>
            <div class="space-y-2">
                @forelse($this->stats['course_distribution'] as $crs)
                    <div class="flex items-center justify-between text-xs p-2 rounded-lg bg-card border border-border">
                        <div class="truncate">
                            <span class="font-semibold text-foreground">{{ $crs->name }}</span>
                            <span class="text-[10px] text-muted-foreground font-mono ml-1">({{ $crs->code }})</span>
                        </div>
                        <span class="font-bold text-primary">{{ $crs->study_materials_count }} {{ __('materials') }}</span>
                    </div>
                @empty
                    <div class="text-xs text-muted-foreground italic">{{ __('No course materials uploaded yet.') }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Top Downloaded Study Materials Table -->
    <div class="bg-card rounded-xl border border-border p-6 shadow-sm">
        <h3 class="text-base font-bold text-foreground mb-1 flex items-center gap-2">
            <x-icon name="trending-up" class="w-4 h-4 text-primary" />
            <span>{{ __('Most Popular Study Materials (Top Downloads)') }}</span>
        </h3>
        <p class="text-xs text-muted-foreground mb-4">
            {{ __('Top downloaded lecture notes, mock tests, and syllabus documents by civil service aspirants.') }}
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase text-[10px]">
                    <tr>
                        <th class="py-2.5 px-3">#</th>
                        <th class="py-2.5 px-3">{{ __('Material Title') }}</th>
                        <th class="py-2.5 px-3">{{ __('Course & Batches') }}</th>
                        <th class="py-2.5 px-3">{{ __('Type') }}</th>
                        <th class="py-2.5 px-3 text-center">{{ __('Downloads') }}</th>
                        <th class="py-2.5 px-3 text-center">{{ __('Views') }}</th>
                        <th class="py-2.5 px-3 text-right">{{ __('Files') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($this->topDownloaded as $idx => $top)
                        <tr class="hover:bg-muted/20">
                            <td class="py-2.5 px-3 font-bold text-muted-foreground">{{ $idx + 1 }}</td>
                            <td class="py-2.5 px-3">
                                <span class="font-bold text-foreground">{{ $top->title }}</span>
                                @if($top->subject)
                                    <span class="block text-[10px] text-muted-foreground">{{ $top->subject }}</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-muted-foreground">
                                {{ $top->course?->name }} ({{ $top->batches_summary }})
                            </td>
                            <td class="py-2.5 px-3">
                                <span class="px-2 py-0.5 rounded text-[10px] bg-muted font-medium">
                                    {{ $top->material_type_label }}
                                </span>
                            </td>
                            <td class="py-2.5 px-3 text-center font-bold text-emerald-600">
                                {{ number_format($top->download_count) }}
                            </td>
                            <td class="py-2.5 px-3 text-center font-semibold text-muted-foreground">
                                {{ number_format($top->view_count) }}
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-muted-foreground">
                                {{ $top->files->count() }} ({{ $top->formatted_total_size }})
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-4 text-center text-muted-foreground italic">
                                {{ __('No download activities recorded yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Access & Download Activity Timeline -->
    <div class="bg-card rounded-xl border border-border p-6 shadow-sm">
        <h3 class="text-base font-bold text-foreground mb-1 flex items-center gap-2">
            <x-icon name="activity" class="w-4 h-4 text-primary" />
            <span>{{ __('Recent Student Download & Access Audit Stream') }}</span>
        </h3>
        <p class="text-xs text-muted-foreground mb-4">
            {{ __('Audited student access events with IP addresses and timestamps.') }}
        </p>

        <div class="space-y-2">
            @forelse($this->recentAccessLogs as $log)
                <div class="flex items-center justify-between p-3 rounded-lg border border-border bg-card text-xs">
                    <div class="flex items-center gap-2.5 truncate">
                        <div class="w-7 h-7 rounded-lg {{ $log->action === 'download' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-blue-500/10 text-blue-600' }} flex items-center justify-center shrink-0">
                            <x-icon name="{{ $log->action === 'download' ? 'download' : 'eye' }}" class="w-3.5 h-3.5" />
                        </div>
                        <div class="truncate">
                            <span class="font-semibold text-foreground">{{ $log->user?->name ?? __('Guest Student') }}</span>
                            <span class="text-muted-foreground">
                                {{ $log->action === 'download' ? __('downloaded') : __('viewed') }}
                                <strong class="text-foreground font-medium">{{ $log->studyMaterial?->title }}</strong>
                                @if($log->file)
                                    ({{ $log->file->file_name }})
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 text-muted-foreground text-[11px] shrink-0 font-mono">
                        <span>{{ $log->ip_address }}</span>
                        <span>&bull;</span>
                        <span>{{ $log->created_at?->diffForHumans() }}</span>
                    </div>
                </div>
            @empty
                <div class="text-xs text-muted-foreground italic text-center py-4">
                    {{ __('No recent access logs found.') }}
                </div>
            @endforelse
        </div>
    </div>
</div>
